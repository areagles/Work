<?php
ob_start();
require 'auth.php';
require 'config.php';
require_once 'inventory_engine.php';
app_handle_lang_switch($conn);

$canView = app_user_can('inventory.view');
$canAdjust = app_user_can('inventory.stock.adjust');
if (!$canView) {
    http_response_code(403);
    require 'header.php';
    echo "<div class='container' style='margin-top:30px;'><div style='background:#1d0f0f;border:1px solid #5b2020;border-radius:12px;padding:20px;color:#ffb3b3;'>غير مصرح لك بعرض تدقيق التكلفة.</div></div>";
    require 'footer.php';
    exit;
}

$message = '';
$messageType = 'ok';

$conn->query("
    CREATE TABLE IF NOT EXISTS inventory_cost_revaluations (
        id INT AUTO_INCREMENT PRIMARY KEY,
        item_id INT NOT NULL,
        old_avg_cost DECIMAL(12,4) NOT NULL DEFAULT 0.0000,
        new_avg_cost DECIMAL(12,4) NOT NULL DEFAULT 0.0000,
        notes TEXT NULL,
        created_by_user_id INT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_inventory_cost_reval_item (item_id),
        KEY idx_inventory_cost_reval_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!$canAdjust) {
        $message = 'صلاحيات الوصول غير كافية.';
        $messageType = 'err';
    } elseif (!app_verify_csrf($_POST['_csrf_token'] ?? '')) {
        $message = 'انتهت صلاحية الجلسة. أعد تحميل الصفحة ثم حاول مرة أخرى.';
        $messageType = 'err';
    } else {
        $action = (string)($_POST['action'] ?? '');
        try {
            if ($action === 'revalue_item') {
                $itemId = (int)($_POST['item_id'] ?? 0);
                $newAvgCost = (float)($_POST['new_avg_cost'] ?? 0);
                if ($itemId <= 0 || $newAvgCost < 0) {
                    throw new InvalidArgumentException('invalid_revaluation');
                }
                $notes = trim((string)($_POST['notes'] ?? ''));
                $stmtOld = $conn->prepare("SELECT IFNULL(avg_unit_cost, 0) AS avg_unit_cost FROM inventory_items WHERE id = ? LIMIT 1");
                if (!$stmtOld) {
                    throw new RuntimeException($conn->error);
                }
                $stmtOld->bind_param('i', $itemId);
                $stmtOld->execute();
                $oldRow = $stmtOld->get_result()->fetch_assoc();
                $stmtOld->close();
                if (!$oldRow) {
                    throw new RuntimeException('item_not_found');
                }
                $oldAvgCost = (float)($oldRow['avg_unit_cost'] ?? 0);
                $conn->begin_transaction();
                $stmt = $conn->prepare("UPDATE inventory_items SET avg_unit_cost = ? WHERE id = ?");
                if (!$stmt) {
                    throw new RuntimeException($conn->error);
                }
                $stmt->bind_param('di', $newAvgCost, $itemId);
                $stmt->execute();
                $stmt->close();
                $currentUserId = (int)($_SESSION['user_id'] ?? 0);
                $stmtLog = $conn->prepare("
                    INSERT INTO inventory_cost_revaluations (item_id, old_avg_cost, new_avg_cost, notes, created_by_user_id)
                    VALUES (?, ?, ?, ?, ?)
                ");
                if (!$stmtLog) {
                    throw new RuntimeException($conn->error);
                }
                $stmtLog->bind_param('iddsi', $itemId, $oldAvgCost, $newAvgCost, $notes, $currentUserId);
                $stmtLog->execute();
                $stmtLog->close();
                $conn->commit();
                app_safe_redirect('inventory_cost_audit.php?msg=revalued');
            } elseif ($action === 'recompute_zero_items') {
                $res = $conn->query("SELECT id FROM inventory_items WHERE IFNULL(avg_unit_cost, 0) <= 0.000001 ORDER BY id ASC");
                if ($res) {
                    while ($row = $res->fetch_assoc()) {
                        app_inventory_item_avg_cost($conn, (int)$row['id']);
                    }
                }
                app_safe_redirect('inventory_cost_audit.php?msg=recomputed');
            }
        } catch (Throwable $e) {
            try {
                if ($conn->errno === 0) {
                    $conn->rollback();
                }
            } catch (Throwable $rollbackError) {
            }
            $message = 'تعذر تنفيذ العملية: ' . $e->getMessage();
            $messageType = 'err';
        }
    }
}

require 'header.php';

if (($_GET['msg'] ?? '') === 'revalued') {
    $message = 'تم تحديث متوسط تكلفة الصنف.';
}
if (($_GET['msg'] ?? '') === 'recomputed') {
    $message = 'تمت محاولة إعادة احتساب الأصناف ذات متوسط التكلفة الصفري من حركات الإدخال.';
}

$items = [];
$itemsRes = $conn->query("
    SELECT
        i.id,
        i.item_code,
        i.name,
        IFNULL(i.avg_unit_cost, 0) AS avg_unit_cost,
        IFNULL(SUM(s.quantity), 0) AS qty_on_hand
    FROM inventory_items i
    LEFT JOIN inventory_stock s ON s.item_id = i.id
    GROUP BY i.id, i.item_code, i.name, i.avg_unit_cost
    ORDER BY i.name ASC
");
if ($itemsRes) {
    while ($row = $itemsRes->fetch_assoc()) {
        $items[] = $row;
    }
}

$exceptions = [];
$exceptionRes = $conn->query("
    SELECT
        t.id,
        t.transaction_date,
        t.transaction_type,
        t.quantity,
        IFNULL(t.unit_cost, 0) AS unit_cost,
        IFNULL(t.total_cost, 0) AS total_cost,
        IFNULL(t.reference_type, '') AS reference_type,
        i.item_code,
        i.name AS item_name,
        IFNULL(i.avg_unit_cost, 0) AS avg_unit_cost,
        w.name AS warehouse_name
    FROM inventory_transactions t
    JOIN inventory_items i ON i.id = t.item_id
    LEFT JOIN warehouses w ON w.id = t.warehouse_id
    WHERE ABS(IFNULL(t.quantity, 0)) > 0.000001
      AND IFNULL(t.total_cost, 0) <= 0.000001
      AND IFNULL(i.avg_unit_cost, 0) > 0.000001
      AND t.transaction_type IN ('out', 'transfer', 'adjustment')
    ORDER BY t.id DESC
    LIMIT 200
");
if ($exceptionRes) {
    while ($row = $exceptionRes->fetch_assoc()) {
        $exceptions[] = $row;
    }
}

$zeroItems = [];
$zeroRes = $conn->query("
    SELECT i.id, i.item_code, i.name, IFNULL(SUM(s.quantity), 0) AS qty_on_hand
    FROM inventory_items i
    LEFT JOIN inventory_stock s ON s.item_id = i.id
    WHERE IFNULL(i.avg_unit_cost, 0) <= 0.000001
    GROUP BY i.id, i.item_code, i.name
    HAVING ABS(qty_on_hand) > 0.000001
    ORDER BY i.name ASC
    LIMIT 200
");
if ($zeroRes) {
    while ($row = $zeroRes->fetch_assoc()) {
        $zeroItems[] = $row;
    }
}

$revaluations = [];
$revalRes = $conn->query("
    SELECT r.*, i.item_code, i.name AS item_name, u.full_name AS user_name
    FROM inventory_cost_revaluations r
    JOIN inventory_items i ON i.id = r.item_id
    LEFT JOIN users u ON u.id = r.created_by_user_id
    ORDER BY r.id DESC
    LIMIT 50
");
if ($revalRes) {
    while ($row = $revalRes->fetch_assoc()) {
        $revaluations[] = $row;
    }
}
?>

<style>
.cost-wrap{max-width:1180px;margin:28px auto;padding:0 18px;color:#e5e7eb}
.cost-hero{background:linear-gradient(135deg,#111827,#253019);border:1px solid rgba(212,175,55,.28);border-radius:22px;padding:24px;box-shadow:0 18px 45px rgba(0,0,0,.25)}
.cost-hero h1{margin:0 0 8px;color:#f9d36a;font-size:1.7rem}
.cost-hero p{margin:0;color:#cbd5e1;line-height:1.8}
.cost-grid{display:grid;grid-template-columns:1fr 1fr;gap:18px;margin-top:18px}
.cost-card{background:#111827;border:1px solid #263244;border-radius:18px;padding:18px}
.cost-card h2{margin:0 0 12px;color:#f3f4f6;font-size:1.15rem}
.cost-table{width:100%;border-collapse:collapse;margin-top:12px;background:#0b1120;border-radius:14px;overflow:hidden}
.cost-table th,.cost-table td{padding:10px;border-bottom:1px solid #1f2937;text-align:right;font-size:.92rem}
.cost-table th{background:#172033;color:#f9d36a}
.cost-table td{color:#d1d5db}
.cost-input,.cost-select{width:100%;background:#0b1120;border:1px solid #334155;color:#fff;border-radius:10px;padding:10px}
.cost-btn{border:0;border-radius:12px;padding:10px 14px;background:#d4af37;color:#111;font-weight:800;cursor:pointer}
.cost-btn.secondary{background:#334155;color:#fff}
.cost-alert{margin-top:16px;border-radius:14px;padding:12px 14px}
.cost-alert.ok{background:#10281c;border:1px solid #2f855a;color:#b7f7cf}
.cost-alert.err{background:#2a1111;border:1px solid #7f1d1d;color:#fecaca}
@media(max-width:900px){.cost-grid{grid-template-columns:1fr}.cost-table{display:block;overflow:auto}}
</style>

<div class="cost-wrap" dir="<?php echo app_h($_SESSION['lang'] === 'en' ? 'ltr' : 'rtl'); ?>">
    <div class="cost-hero">
        <h1><i class="fa-solid fa-scale-balanced"></i> <?php echo app_h(app_tr('تدقيق وتقييم تكلفة الخامات', 'Raw Material Cost Audit & Revaluation')); ?></h1>
        <p><?php echo app_h(app_tr('راقب الحركات التي خرجت بتكلفة صفرية، وعدّل متوسط تكلفة الخامة عند الحاجة قبل احتساب ربحية العمليات.', 'Monitor zero-cost movements and adjust item average cost before operation profitability is calculated.')); ?></p>
    </div>

    <?php if ($message !== ''): ?>
        <div class="cost-alert <?php echo $messageType === 'err' ? 'err' : 'ok'; ?>"><?php echo app_h($message); ?></div>
    <?php endif; ?>

    <div class="cost-grid">
        <div class="cost-card">
            <h2><?php echo app_h(app_tr('إعادة تقييم متوسط تكلفة صنف', 'Revalue Item Average Cost')); ?></h2>
            <form method="post">
                <input type="hidden" name="_csrf_token" value="<?php echo app_h(app_csrf_token()); ?>">
                <input type="hidden" name="action" value="revalue_item">
                <label><?php echo app_h(app_tr('الصنف', 'Item')); ?></label>
                <select class="cost-select" name="item_id" required>
                    <option value=""><?php echo app_h(app_tr('اختر الصنف', 'Select item')); ?></option>
                    <?php foreach ($items as $itemRow): ?>
                        <option value="<?php echo (int)$itemRow['id']; ?>"><?php echo app_h((string)$itemRow['item_code'] . ' - ' . (string)$itemRow['name'] . ' | Avg: ' . number_format((float)$itemRow['avg_unit_cost'], 4) . ' | Qty: ' . number_format((float)$itemRow['qty_on_hand'], 2)); ?></option>
                    <?php endforeach; ?>
                </select>
                <label style="display:block;margin-top:12px;"><?php echo app_h(app_tr('متوسط التكلفة الجديد', 'New average cost')); ?></label>
                <input class="cost-input" type="number" min="0" step="0.0001" name="new_avg_cost" required>
                <label style="display:block;margin-top:12px;"><?php echo app_h(app_tr('سبب إعادة التقييم', 'Revaluation reason')); ?></label>
                <textarea class="cost-input" name="notes" rows="3" placeholder="<?php echo app_h(app_tr('مثال: تصحيح فاتورة مورد / تكلفة شحن / مراجعة جرد', 'Example: supplier invoice correction / freight cost / inventory review')); ?>"></textarea>
                <button class="cost-btn" type="submit" style="margin-top:14px;"><?php echo app_h(app_tr('حفظ التقييم', 'Save Revaluation')); ?></button>
            </form>
        </div>

        <div class="cost-card">
            <h2><?php echo app_h(app_tr('أصناف برصيد وبدون متوسط تكلفة', 'Stocked Items Without Average Cost')); ?></h2>
            <?php if ($canAdjust): ?>
                <form method="post" style="margin-bottom:12px;">
                    <input type="hidden" name="_csrf_token" value="<?php echo app_h(app_csrf_token()); ?>">
                    <input type="hidden" name="action" value="recompute_zero_items">
                    <button class="cost-btn secondary" type="submit"><?php echo app_h(app_tr('إعادة احتساب من حركات الإدخال', 'Recompute from inbound transactions')); ?></button>
                </form>
            <?php endif; ?>
            <table class="cost-table">
                <thead><tr><th><?php echo app_h(app_tr('الصنف', 'Item')); ?></th><th><?php echo app_h(app_tr('الرصيد', 'Qty')); ?></th></tr></thead>
                <tbody>
                <?php if (empty($zeroItems)): ?>
                    <tr><td colspan="2"><?php echo app_h(app_tr('لا توجد أصناف ظاهرة بهذا الشرط.', 'No items matched this condition.')); ?></td></tr>
                <?php else: foreach ($zeroItems as $row): ?>
                    <tr><td><?php echo app_h((string)$row['item_code'] . ' - ' . (string)$row['name']); ?></td><td><?php echo number_format((float)$row['qty_on_hand'], 2); ?></td></tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="cost-card" style="margin-top:18px;">
        <h2><?php echo app_h(app_tr('حركات بتكلفة صفرية رغم وجود متوسط تكلفة', 'Zero-Cost Movements With Known Average Cost')); ?></h2>
        <table class="cost-table">
            <thead><tr><th>#</th><th><?php echo app_h(app_tr('التاريخ', 'Date')); ?></th><th><?php echo app_h(app_tr('الصنف', 'Item')); ?></th><th><?php echo app_h(app_tr('المخزن', 'Warehouse')); ?></th><th><?php echo app_h(app_tr('النوع', 'Type')); ?></th><th><?php echo app_h(app_tr('الكمية', 'Qty')); ?></th><th><?php echo app_h(app_tr('متوسط الصنف', 'Item Avg')); ?></th></tr></thead>
            <tbody>
            <?php if (empty($exceptions)): ?>
                <tr><td colspan="7"><?php echo app_h(app_tr('لا توجد استثناءات تكلفة حالياً.', 'No costing exceptions currently.')); ?></td></tr>
            <?php else: foreach ($exceptions as $row): ?>
                <tr>
                    <td><?php echo (int)$row['id']; ?></td>
                    <td><?php echo app_h((string)$row['transaction_date']); ?></td>
                    <td><?php echo app_h((string)$row['item_code'] . ' - ' . (string)$row['item_name']); ?></td>
                    <td><?php echo app_h((string)$row['warehouse_name']); ?></td>
                    <td><?php echo app_h((string)$row['transaction_type'] . ' / ' . (string)$row['reference_type']); ?></td>
                    <td><?php echo number_format((float)$row['quantity'], 2); ?></td>
                    <td><?php echo number_format((float)$row['avg_unit_cost'], 4); ?></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>

    <div class="cost-card" style="margin-top:18px;">
        <h2><?php echo app_h(app_tr('آخر تغييرات متوسط التكلفة', 'Latest Average Cost Changes')); ?></h2>
        <table class="cost-table">
            <thead><tr><th>#</th><th><?php echo app_h(app_tr('التاريخ', 'Date')); ?></th><th><?php echo app_h(app_tr('الصنف', 'Item')); ?></th><th><?php echo app_h(app_tr('قبل', 'Old')); ?></th><th><?php echo app_h(app_tr('بعد', 'New')); ?></th><th><?php echo app_h(app_tr('المستخدم', 'User')); ?></th><th><?php echo app_h(app_tr('السبب', 'Reason')); ?></th></tr></thead>
            <tbody>
            <?php if (empty($revaluations)): ?>
                <tr><td colspan="7"><?php echo app_h(app_tr('لا توجد تغييرات مسجلة بعد.', 'No revaluations logged yet.')); ?></td></tr>
            <?php else: foreach ($revaluations as $row): ?>
                <tr>
                    <td><?php echo (int)$row['id']; ?></td>
                    <td><?php echo app_h((string)$row['created_at']); ?></td>
                    <td><?php echo app_h((string)$row['item_code'] . ' - ' . (string)$row['item_name']); ?></td>
                    <td><?php echo number_format((float)$row['old_avg_cost'], 4); ?></td>
                    <td><?php echo number_format((float)$row['new_avg_cost'], 4); ?></td>
                    <td><?php echo app_h((string)($row['user_name'] ?? '')); ?></td>
                    <td><?php echo app_h((string)($row['notes'] ?? '')); ?></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php
require 'footer.php';
ob_end_flush();
?>
