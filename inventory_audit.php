<?php
ob_start();
require 'auth.php';
require 'config.php';
require_once 'inventory_engine.php';
app_handle_lang_switch($conn);

if (!function_exists('inventory_audit_error_text')) {
    function inventory_audit_error_text(string $code): string
    {
        $map = [
            'invalid_audit_warehouse' => 'يرجى اختيار مخزن صالح لبدء الجرد.',
            'invalid_audit_date' => 'تاريخ الجرد غير صالح.',
            'warehouse_not_found' => 'المخزن المحدد غير موجود.',
            'audit_session_not_found' => 'جلسة الجرد غير موجودة.',
            'audit_session_locked' => 'لا يمكن تعديل جلسة جرد تم اعتمادها.',
            'audit_session_already_applied' => 'تم اعتماد هذه الجلسة من قبل.',
            'audit_session_has_no_counts' => 'لا يمكن اعتماد الجرد قبل تسجيل أي كميات فعلية.',
            'audit_line_not_found' => 'تعذر العثور على سطر الجرد المطلوب.',
            'audit_warehouse_missing' => 'لا يمكن اعتماد الجلسة لعدم وجود مخزن مرتبط بها.',
            'invalid_audit_count' => 'الكمية الفعلية لا يمكن أن تكون أقل من صفر.',
        ];
        $code = trim($code);
        return $map[$code] ?? $code;
    }
}

$canInventoryAudit = app_user_can('inventory.view');
if (!$canInventoryAudit) {
    http_response_code(403);
    require 'header.php';
    echo "<div class='container' style='margin-top:30px;'><div style='background:#1d0f0f;border:1px solid #5b2020;border-radius:12px;padding:20px;color:#ffb3b3;'>غير مصرح لك باستخدام نظام الجرد.</div></div>";
    require 'footer.php';
    exit;
}

$currentUserId = (int)($_SESSION['user_id'] ?? 0);
$message = '';
$messageType = 'ok';
$sessionId = (int)($_GET['session_id'] ?? $_POST['session_id'] ?? 0);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!app_verify_csrf($_POST['_csrf_token'] ?? '')) {
        $message = 'انتهت صلاحية الجلسة. حدّث الصفحة ثم حاول مرة أخرى.';
        $messageType = 'err';
    } else {
        $action = (string)($_POST['action'] ?? '');
        try {
            if ($action === 'create_session') {
                $warehouseId = (int)($_POST['warehouse_id'] ?? 0);
                $auditDate = trim((string)($_POST['audit_date'] ?? date('Y-m-d')));
                $title = trim((string)($_POST['title'] ?? ''));
                $notes = trim((string)($_POST['notes'] ?? ''));
                $conn->begin_transaction();
                $sessionId = inventory_create_audit_session($conn, $warehouseId, $currentUserId, $auditDate, $title, $notes);
                $conn->commit();
                app_safe_redirect('inventory_audit.php?session_id=' . $sessionId . '&msg=created');
            } elseif ($action === 'save_counts') {
                $sessionId = (int)($_POST['session_id'] ?? 0);
                $itemIds = (array)($_POST['item_id'] ?? []);
                $countedQtys = (array)($_POST['counted_qty'] ?? []);
                $lineNotes = (array)($_POST['line_notes'] ?? []);
                $conn->begin_transaction();
                foreach ($itemIds as $index => $itemIdRaw) {
                    $itemId = (int)$itemIdRaw;
                    if ($itemId <= 0) {
                        continue;
                    }
                    $rawCount = trim((string)($countedQtys[$index] ?? ''));
                    if ($rawCount === '') {
                        continue;
                    }
                    $countedQty = (float)$rawCount;
                    $note = trim((string)($lineNotes[$index] ?? ''));
                    inventory_update_audit_count($conn, $sessionId, $itemId, $countedQty, $currentUserId, $note);
                }
                $conn->commit();
                app_safe_redirect('inventory_audit.php?session_id=' . $sessionId . '&msg=saved');
            } elseif ($action === 'apply_session') {
                $sessionId = (int)($_POST['session_id'] ?? 0);
                $conn->begin_transaction();
                inventory_apply_audit_session($conn, $sessionId, $currentUserId);
                $conn->commit();
                app_safe_redirect('inventory_audit.php?session_id=' . $sessionId . '&msg=applied');
            }
        } catch (Throwable $e) {
            try {
                $conn->rollback();
            } catch (Throwable $rollbackError) {
            }
            $messageType = 'err';
            $message = 'تعذر تنفيذ العملية: ' . inventory_audit_error_text((string)$e->getMessage());
        }
    }
}

require 'header.php';

$warehouses = [];
$whRes = $conn->query("SELECT id, name FROM warehouses WHERE is_active = 1 ORDER BY name ASC");
if ($whRes) {
    while ($row = $whRes->fetch_assoc()) {
        $warehouses[] = $row;
    }
}

$sessions = [];
$sessionRes = $conn->query("
    SELECT s.*, w.name AS warehouse_name, u.full_name AS created_by_name
    FROM inventory_audit_sessions s
    JOIN warehouses w ON w.id = s.warehouse_id
    LEFT JOIN users u ON u.id = s.created_by_user_id
    ORDER BY s.id DESC
    LIMIT 40
");
if ($sessionRes) {
    while ($row = $sessionRes->fetch_assoc()) {
        $sessions[] = $row;
    }
}

$selectedSession = null;
$selectedLines = [];
if ($sessionId > 0) {
    $stmtSession = $conn->prepare("
        SELECT s.*, w.name AS warehouse_name, u.full_name AS created_by_name
        FROM inventory_audit_sessions s
        JOIN warehouses w ON w.id = s.warehouse_id
        LEFT JOIN users u ON u.id = s.created_by_user_id
        WHERE s.id = ?
        LIMIT 1
    ");
    if ($stmtSession) {
        $stmtSession->bind_param('i', $sessionId);
        $stmtSession->execute();
        $selectedSession = $stmtSession->get_result()->fetch_assoc();
        $stmtSession->close();
    }

    if ($selectedSession) {
        $stmtLines = $conn->prepare("
            SELECT l.*, i.item_code, i.name AS item_name, i.category, i.unit
            FROM inventory_audit_lines l
            JOIN inventory_items i ON i.id = l.item_id
            WHERE l.session_id = ?
            ORDER BY i.name ASC
        ");
        if ($stmtLines) {
            $stmtLines->bind_param('i', $sessionId);
            $stmtLines->execute();
            $resLines = $stmtLines->get_result();
            while ($row = $resLines->fetch_assoc()) {
                $selectedLines[] = $row;
            }
            $stmtLines->close();
        }
    }
}

$stats = [
    'total_items' => count($selectedLines),
    'counted_items' => 0,
    'matched_items' => 0,
    'variance_items' => 0,
    'variance_total' => 0.0,
];
foreach ($selectedLines as $line) {
    if ($line['counted_qty'] !== null) {
        $stats['counted_items']++;
        if (abs((float)($line['variance_qty'] ?? 0)) <= 0.00001) {
            $stats['matched_items']++;
        }
    }
    $variance = (float)($line['variance_qty'] ?? 0);
    if (abs($variance) > 0.00001) {
        $stats['variance_items']++;
        $stats['variance_total'] += $variance;
    }
}
?>
<style>
body { background:#080808; color:#f1f1f1; }
.audit-shell { max-width: 1400px; margin: 0 auto; padding: 24px 16px 36px; }
.audit-grid { display:grid; grid-template-columns: 360px minmax(0,1fr); gap:18px; align-items:start; }
.audit-card { background:#141414; border:1px solid #2a2a2a; border-radius:18px; padding:18px; box-shadow:0 18px 34px rgba(0,0,0,.22); }
.audit-title { margin:0 0 12px; color:#d4af37; }
.audit-form { display:grid; gap:12px; }
.audit-input, .audit-select, .audit-textarea { width:100%; background:#0b0b0b; border:1px solid #363636; color:#fff; border-radius:12px; padding:12px; }
.audit-textarea { min-height:92px; resize:vertical; }
.audit-btn { display:inline-flex; align-items:center; justify-content:center; gap:8px; border:none; border-radius:12px; padding:12px 16px; cursor:pointer; font-weight:800; }
.audit-btn.gold { background:linear-gradient(135deg,#e7c45c,#c8920f); color:#121212; }
.audit-btn.green { background:#1f7a45; color:#fff; }
.audit-btn.dark { background:#1a1a1a; color:#e6e6e6; border:1px solid #343434; text-decoration:none; }
.audit-list { display:grid; gap:10px; margin-top:12px; }
.audit-session { display:block; padding:14px; border-radius:14px; background:#0f0f0f; border:1px solid #232323; color:#e6e6e6; text-decoration:none; }
.audit-session.active { border-color:#d4af37; box-shadow:0 0 0 1px rgba(212,175,55,.28); }
.audit-session small { display:block; color:#979797; margin-top:6px; }
.audit-stats { display:grid; grid-template-columns: repeat(auto-fit, minmax(180px,1fr)); gap:12px; margin-bottom:16px; }
.audit-stat { background:#101010; border:1px solid #232323; border-radius:14px; padding:14px; }
.audit-stat .n { font-size:1.55rem; font-weight:800; color:#d4af37; }
.audit-table-wrap { overflow:auto; }
.audit-table { width:100%; border-collapse:collapse; min-width:980px; }
.audit-table th, .audit-table td { padding:12px; border-bottom:1px solid #232323; text-align:right; }
.audit-table th { color:#d4af37; background:#101010; position:sticky; top:0; }
.audit-badge { display:inline-flex; align-items:center; padding:6px 10px; border-radius:999px; font-size:.82rem; font-weight:700; }
.audit-badge.draft { background:rgba(52,152,219,.14); color:#a6daff; }
.audit-badge.applied { background:rgba(46,204,113,.14); color:#9ef0bc; }
.audit-row-bad { background:rgba(231,76,60,.08); }
.audit-row-good { background:rgba(46,204,113,.05); }
.audit-actions { display:flex; gap:10px; flex-wrap:wrap; margin-bottom:16px; }
.audit-note { color:#9b9b9b; font-size:.92rem; }
.alert-box { border-radius:12px; padding:14px 16px; margin-bottom:14px; }
.alert-box.ok { background:rgba(46,204,113,.14); border:1px solid rgba(46,204,113,.35); color:#b6ffd0; }
.alert-box.err { background:rgba(231,76,60,.14); border:1px solid rgba(231,76,60,.35); color:#ffd2cc; }
@media (max-width: 1080px) {
    .audit-grid { grid-template-columns: 1fr; }
    .audit-stats { grid-template-columns: repeat(2, minmax(0,1fr)); }
}
</style>

<div class="audit-shell">
    <?php if (isset($_GET['msg']) && $_GET['msg'] === 'created'): ?>
        <div class="alert-box ok">تم إنشاء جلسة الجرد بنجاح.</div>
    <?php elseif (isset($_GET['msg']) && $_GET['msg'] === 'saved'): ?>
        <div class="alert-box ok">تم حفظ كميات الجرد.</div>
    <?php elseif (isset($_GET['msg']) && $_GET['msg'] === 'applied'): ?>
        <div class="alert-box ok">تم اعتماد الجرد وترحيل فروقات المخزون.</div>
    <?php elseif ($message !== ''): ?>
        <div class="alert-box <?php echo $messageType === 'err' ? 'err' : 'ok'; ?>"><?php echo app_h($message); ?></div>
    <?php endif; ?>

    <div style="display:flex; justify-content:space-between; gap:12px; align-items:center; margin-bottom:18px; flex-wrap:wrap;">
        <div>
            <h1 style="margin:0; color:#d4af37;"><i class="fa-solid fa-clipboard-check"></i> جرد المخازن</h1>
            <div class="audit-note">جلسات جرد فعلية قابلة للطباعة واعتماد الفروقات مباشرة على المخزون.</div>
        </div>
        <div class="audit-actions">
            <a class="audit-btn dark" href="inventory.php"><i class="fa-solid fa-boxes-stacked"></i> المخزون</a>
            <a class="audit-btn dark" href="warehouses.php"><i class="fa-solid fa-warehouse"></i> المخازن</a>
            <?php if ($selectedSession): ?>
                <a class="audit-btn dark" href="inventory_audit_print.php?id=<?php echo (int)$selectedSession['id']; ?>" target="_blank"><i class="fa-solid fa-print"></i> طباعة الجرد</a>
            <?php endif; ?>
        </div>
    </div>

    <div class="audit-grid">
        <div class="audit-card">
            <h3 class="audit-title">جلسة جرد جديدة</h3>
            <form class="audit-form" method="post">
                <?php echo app_csrf_input(); ?>
                <input type="hidden" name="action" value="create_session">
                <select class="audit-select" name="warehouse_id" required>
                    <option value="">اختر المخزن</option>
                    <?php foreach ($warehouses as $warehouse): ?>
                        <option value="<?php echo (int)$warehouse['id']; ?>"><?php echo app_h((string)$warehouse['name']); ?></option>
                    <?php endforeach; ?>
                </select>
                <input class="audit-input" type="date" name="audit_date" value="<?php echo app_h(date('Y-m-d')); ?>" required>
                <input class="audit-input" type="text" name="title" placeholder="مثال: جرد نهاية الشهر">
                <textarea class="audit-textarea" name="notes" placeholder="ملاحظات عامة على الجرد"></textarea>
                <button class="audit-btn gold" type="submit"><i class="fa-solid fa-plus"></i> إنشاء الجلسة</button>
            </form>

            <h3 class="audit-title" style="margin-top:20px;">آخر الجلسات</h3>
            <div class="audit-list">
                <?php foreach ($sessions as $session): ?>
                    <a class="audit-session <?php echo ((int)$session['id'] === (int)$sessionId) ? 'active' : ''; ?>" href="inventory_audit.php?session_id=<?php echo (int)$session['id']; ?>">
                        <strong><?php echo app_h((string)($session['title'] ?? 'جرد')); ?></strong>
                        <small><?php echo app_h((string)($session['warehouse_name'] ?? '')); ?> | <?php echo app_h((string)($session['audit_date'] ?? '')); ?></small>
                        <small><?php echo app_h((string)($session['created_by_name'] ?? 'System')); ?></small>
                    </a>
                <?php endforeach; ?>
                <?php if (empty($sessions)): ?>
                    <div class="audit-note">لا توجد جلسات جرد حتى الآن.</div>
                <?php endif; ?>
            </div>
        </div>

        <div class="audit-card">
            <?php if ($selectedSession): ?>
                <div style="display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap; margin-bottom:14px;">
                    <div>
                        <h3 class="audit-title" style="margin-bottom:6px;"><?php echo app_h((string)($selectedSession['title'] ?? 'جلسة جرد')); ?></h3>
                        <div class="audit-note"><?php echo app_h((string)($selectedSession['warehouse_name'] ?? '')); ?> | <?php echo app_h((string)($selectedSession['audit_date'] ?? '')); ?></div>
                    </div>
                    <span class="audit-badge <?php echo app_h((string)($selectedSession['status'] ?? 'draft')); ?>">
                        <?php echo ((string)($selectedSession['status'] ?? '') === 'applied') ? 'مُعتمد' : 'مسودة'; ?>
                    </span>
                </div>

                <div class="audit-stats">
                    <div class="audit-stat"><div>عدد الأصناف</div><div class="n"><?php echo number_format($stats['total_items']); ?></div></div>
                    <div class="audit-stat"><div>تم عده</div><div class="n"><?php echo number_format($stats['counted_items']); ?></div></div>
                    <div class="audit-stat"><div>مطابق للنظام</div><div class="n"><?php echo number_format($stats['matched_items']); ?></div></div>
                    <div class="audit-stat"><div>أصناف بها فرق</div><div class="n"><?php echo number_format($stats['variance_items']); ?></div></div>
                    <div class="audit-stat"><div>إجمالي فرق الكميات</div><div class="n"><?php echo number_format($stats['variance_total'], 2); ?></div></div>
                </div>

                <div class="audit-actions">
                    <?php if ((string)($selectedSession['status'] ?? '') === 'draft'): ?>
                        <form method="post">
                            <?php echo app_csrf_input(); ?>
                            <input type="hidden" name="action" value="apply_session">
                            <input type="hidden" name="session_id" value="<?php echo (int)$selectedSession['id']; ?>">
                            <button class="audit-btn green" type="submit" onclick="return confirm('سيتم ترحيل فروقات الجرد إلى المخزون مباشرة. هل تريد الاستمرار؟');"><i class="fa-solid fa-check-double"></i> اعتماد الجرد</button>
                        </form>
                    <?php endif; ?>
                </div>

                <form method="post">
                    <?php echo app_csrf_input(); ?>
                    <input type="hidden" name="action" value="save_counts">
                    <input type="hidden" name="session_id" value="<?php echo (int)$selectedSession['id']; ?>">
                    <div class="audit-table-wrap">
                        <table class="audit-table">
                            <thead>
                                <tr>
                                    <th>الكود</th>
                                    <th>الصنف</th>
                                    <th>الفئة</th>
                                    <th>الوحدة</th>
                                    <th>رصيد النظام</th>
                                    <th>العد الفعلي</th>
                                    <th>الفرق</th>
                                    <th>ملاحظات</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($selectedLines as $line): ?>
                                    <?php $variance = (float)($line['variance_qty'] ?? 0); ?>
                                    <tr class="<?php echo abs($variance) > 0.00001 ? 'audit-row-bad' : (($line['counted_qty'] !== null) ? 'audit-row-good' : ''); ?>">
                                        <td>
                                            <input type="hidden" name="item_id[]" value="<?php echo (int)$line['item_id']; ?>">
                                            <?php echo app_h((string)($line['item_code'] ?? '')); ?>
                                        </td>
                                        <td><?php echo app_h((string)($line['item_name'] ?? '')); ?></td>
                                        <td><?php echo app_h((string)($line['category'] ?? '-')); ?></td>
                                        <td><?php echo app_h((string)($line['unit'] ?? '-')); ?></td>
                                        <td><?php echo number_format((float)($line['system_qty'] ?? 0), 2); ?></td>
                                        <td>
                                            <input class="audit-input" style="padding:10px;" type="number" step="0.01" min="0" name="counted_qty[]" value="<?php echo ($line['counted_qty'] !== null) ? app_h((string)$line['counted_qty']) : ''; ?>" <?php echo ((string)($selectedSession['status'] ?? '') === 'applied') ? 'readonly' : ''; ?>>
                                        </td>
                                        <td><?php echo ($line['counted_qty'] !== null) ? number_format($variance, 2) : '-'; ?></td>
                                        <td><input class="audit-input" style="padding:10px;" type="text" name="line_notes[]" value="<?php echo app_h((string)($line['notes'] ?? '')); ?>" <?php echo ((string)($selectedSession['status'] ?? '') === 'applied') ? 'readonly' : ''; ?>></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php if ((string)($selectedSession['status'] ?? '') === 'draft'): ?>
                        <div style="margin-top:16px;">
                            <button class="audit-btn gold" type="submit"><i class="fa-solid fa-floppy-disk"></i> حفظ نتائج الجرد</button>
                        </div>
                    <?php endif; ?>
                </form>
            <?php else: ?>
                <div class="audit-note">اختر جلسة جرد من القائمة أو أنشئ جلسة جديدة للبدء.</div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require 'footer.php'; ob_end_flush(); ?>
