<?php
ob_start();
// warehouses.php - (Royal Phantom V2.2 - Final Stability Fix)

error_reporting(E_ALL);

require 'auth.php';
require 'config.php';
app_handle_lang_switch($conn);

$canWarehousesView = app_user_can('inventory.warehouses.view');
$canWarehousesCreate = app_user_can('inventory.warehouses.create');
$canWarehousesUpdate = app_user_can('inventory.warehouses.update');
$canWarehousesDelete = app_user_can('inventory.warehouses.delete');
$canWarehousesToggle = app_user_can('inventory.warehouses.toggle');
$canInventoryView = app_user_can('inventory.view');
$canStockAdjust = app_user_can('inventory.stock.adjust');

if (!$canWarehousesView) {
    require 'header.php';
    echo "<div class='container'><h1>صلاحيات الوصول غير كافية</h1><p>هذه الصفحة متاحة للمديرين ومسؤولي الإنتاج فقط.</p></div>";
    require 'footer.php';
    exit;
}
$csrfToken = app_csrf_token();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_warehouse'])) {
    app_require_csrf();
    $toggleId = (int)($_POST['warehouse_id'] ?? 0);
    if (!$canWarehousesToggle) {
        header('Location: warehouses.php?err=denied');
        exit;
    }
    if ($toggleId <= 0) {
        header('Location: warehouses.php?err=invalid_token');
        exit;
    }
    try {
        $stmt = $conn->prepare("UPDATE warehouses SET is_active = IF(is_active = 1, 0, 1) WHERE id = ?");
        $stmt->bind_param('i', $toggleId);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();
        if ($affected > 0) {
            header('Location: warehouses.php?msg=status_updated');
        } else {
            header('Location: warehouses.php?err=not_found');
        }
    } catch (mysqli_sql_exception $e) {
        error_log('Warehouse toggle failed: ' . $e->getMessage());
        header('Location: warehouses.php?err=update_failed');
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_warehouse'])) {
    app_require_csrf();
    $deleteId = (int)($_POST['warehouse_id'] ?? 0);
    if (!$canWarehousesDelete) {
        header('Location: warehouses.php?err=denied');
        exit;
    }
    if ($deleteId <= 0) {
        header('Location: warehouses.php?err=invalid_token');
        exit;
    }
    try {
        $conn->begin_transaction();

        $stmtStock = $conn->prepare("SELECT IFNULL(SUM(quantity),0) AS qty_sum FROM inventory_stock WHERE warehouse_id = ?");
        $stmtStock->bind_param('i', $deleteId);
        $stmtStock->execute();
        $stockRow = $stmtStock->get_result()->fetch_assoc();
        $stmtStock->close();
        $stockQty = (float)($stockRow['qty_sum'] ?? 0);

        if (abs($stockQty) > 0.000001) {
            $conn->rollback();
            header('Location: warehouses.php?err=warehouse_has_stock');
            exit;
        }

        $stmtDelStock = $conn->prepare("DELETE FROM inventory_stock WHERE warehouse_id = ?");
        $stmtDelStock->bind_param('i', $deleteId);
        $stmtDelStock->execute();
        $stmtDelStock->close();

        $stmtDelTrans = $conn->prepare("DELETE FROM inventory_transactions WHERE warehouse_id = ?");
        $stmtDelTrans->bind_param('i', $deleteId);
        $stmtDelTrans->execute();
        $stmtDelTrans->close();

        $stmtDelWh = $conn->prepare("DELETE FROM warehouses WHERE id = ?");
        $stmtDelWh->bind_param('i', $deleteId);
        $stmtDelWh->execute();
        $affected = $stmtDelWh->affected_rows;
        $stmtDelWh->close();

        $conn->commit();
        if ($affected > 0) {
            header('Location: warehouses.php?msg=deleted');
        } else {
            header('Location: warehouses.php?err=not_found');
        }
    } catch (mysqli_sql_exception $e) {
        $conn->rollback();
        error_log('Warehouse delete failed: ' . $e->getMessage());
        header('Location: warehouses.php?err=delete_failed');
    }
    exit;
}

require 'header.php';
echo '<link rel="stylesheet" href="assets/css/inventory-theme.css?v=20260311-1">';

// قائمة المخازن مع اسم المسؤول
$sql = "SELECT w.*, u.full_name as manager_name
        FROM warehouses w
        LEFT JOIN users u ON w.manager_id = u.id
        ORDER BY w.name ASC";

$warehouses_result = $conn->query($sql);
$stats = $conn->query("
    SELECT
        (SELECT COUNT(*) FROM warehouses WHERE is_active = 1) as active_wh,
        (SELECT COUNT(*) FROM inventory_items) as items_count,
        (SELECT IFNULL(SUM(quantity),0) FROM inventory_stock) as total_qty
")->fetch_assoc();

$recent_movements = $conn->query("
    SELECT t.transaction_type, t.quantity, t.created_at, i.name as item_name, w.name as warehouse_name
    FROM (
        SELECT item_id, warehouse_id, transaction_type, quantity, transaction_date as created_at
        FROM inventory_transactions
        ORDER BY id DESC
        LIMIT 8
    ) t
    LEFT JOIN inventory_items i ON i.id = t.item_id
    LEFT JOIN warehouses w ON w.id = t.warehouse_id
    ORDER BY t.created_at DESC
");

?>

<style>
    :root { --ae-gold: #d4af37; --border: rgba(212, 175, 55, 0.15); }
    body { background-color: #050505; color: #eee; }
    .btn-add { background: linear-gradient(90deg, var(--ae-gold), #b8860b); color: #000; padding: 10px 25px; border-radius: 8px; font-weight: bold; text-decoration: none; display: flex; align-items: center; gap: 8px; }
    .table-container { background: #141414; border: 1px solid var(--border); border-radius: 16px; overflow: hidden; }
    .styled-table { width: 100%; border-collapse: collapse; }
    .styled-table thead tr { background-color: #0a0a0a; text-align: right; }
    .styled-table th, .styled-table td { padding: 15px 20px; }
    .styled-table tbody tr { border-bottom: 1px solid #222; }
    .styled-table tbody tr:last-of-type { border-bottom: none; }
    .status-active { color: #2ecc71; }
    .status-inactive { color: #e74c3c; }
    .j-actions { display:flex; gap:8px; }
    .j-actions a { text-decoration: none; font-size: 0.92rem; padding: 6px 10px; border: 1px solid #333; border-radius: 8px; color: #d0d0d0; display:inline-flex; align-items:center; gap:6px; }
    .j-actions a:hover { color: var(--ae-gold); border-color: var(--ae-gold); }
    .j-actions .btn-del { color:#f59f95; border-color: rgba(231, 76, 60, 0.5); }
    .j-actions .btn-del:hover { color:#ffd0ca; border-color: #e74c3c; }
    .j-actions .btn-toggle { color:#9fd9ff; border-color: rgba(31, 111, 235, 0.5); }
    .j-actions .btn-toggle:hover { color:#c9e8ff; border-color:#1f6feb; }
    .kpi-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:14px;margin-bottom:20px}
    .kpi{background:#111;border:1px solid #222;border-radius:12px;padding:16px}
    .kpi .n{font-size:1.6rem;font-weight:800;color:var(--ae-gold)}
    .actions{display:flex;gap:10px;flex-wrap:wrap;justify-content:flex-end}
    @media (max-width: 900px){
        .ph-hero{flex-direction:column;align-items:flex-start !important;gap:12px}
        .actions{width:100%}
        .actions .btn-add{flex:1;justify-content:center}
        .j-actions{flex-wrap:wrap}
    }
    .page-alert { border-radius: 10px; padding: 12px 14px; margin-bottom: 14px; border: 1px solid transparent; }
    .alert-ok { background: rgba(46, 204, 113, 0.14); color: #7ef2ad; border-color: rgba(46, 204, 113, 0.45); }
    .alert-bad { background: rgba(231, 76, 60, 0.14); color: #ffb7af; border-color: rgba(231, 76, 60, 0.45); }
</style>

<div class="container inv-page">
    <?php if (isset($_GET['msg']) && $_GET['msg'] === 'added'): ?>
        <div class="page-alert alert-ok"><?php echo app_h(app_tr('تم إضافة المخزن بنجاح.', 'Warehouse added successfully.')); ?></div>
    <?php elseif (isset($_GET['msg']) && $_GET['msg'] === 'updated'): ?>
        <div class="page-alert alert-ok"><?php echo app_h(app_tr('تم تحديث بيانات المخزن بنجاح.', 'Warehouse updated successfully.')); ?></div>
    <?php elseif (isset($_GET['msg']) && $_GET['msg'] === 'deleted'): ?>
        <div class="page-alert alert-ok"><?php echo app_h(app_tr('تم حذف المخزن بنجاح.', 'Warehouse deleted successfully.')); ?></div>
    <?php elseif (isset($_GET['msg']) && $_GET['msg'] === 'status_updated'): ?>
        <div class="page-alert alert-ok"><?php echo app_h(app_tr('تم تحديث حالة المخزن بنجاح.', 'Warehouse status updated successfully.')); ?></div>
    <?php elseif (isset($_GET['err']) && $_GET['err'] === 'warehouse_has_stock'): ?>
        <div class="page-alert alert-bad"><?php echo app_h(app_tr('لا يمكن حذف المخزن لأنه يحتوي على رصيد مخزون. قم بتصفيره أو نقله أولاً.', 'This warehouse cannot be deleted because it still has stock. Empty or transfer it first.')); ?></div>
    <?php elseif (isset($_GET['err']) && $_GET['err'] === 'denied'): ?>
        <div class="page-alert alert-bad"><?php echo app_h(app_tr('ليس لديك صلاحية تنفيذ هذا الإجراء.', 'You do not have permission to perform this action.')); ?></div>
    <?php elseif (isset($_GET['err']) && $_GET['err'] === 'invalid_token'): ?>
        <div class="page-alert alert-bad"><?php echo app_h(app_tr('طلب غير صالح، أعد المحاولة.', 'Invalid request, please try again.')); ?></div>
    <?php elseif (isset($_GET['err']) && in_array($_GET['err'], ['not_found','delete_failed','update_failed'], true)): ?>
        <div class="page-alert alert-bad"><?php echo app_h(app_tr('تعذر تنفيذ العملية على المخزن المطلوب.', 'Failed to perform the requested warehouse action.')); ?></div>
    <?php endif; ?>

    <div class="ph-hero" style="display:flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--border); padding-bottom: 20px; margin-bottom: 25px;">
        <h1 style="color:var(--ae-gold);"><i class="fa-solid fa-warehouse"></i> <?php echo app_h(app_tr('إدارة المخازن', 'Warehouses Management')); ?></h1>
        <div class="actions">
            <?php if ($canWarehousesCreate): ?>
                <a href="add_warehouse.php" class="btn-add"><i class="fa-solid fa-plus"></i> <?php echo app_h(app_tr('إضافة مخزن جديد', 'Add New Warehouse')); ?></a>
            <?php endif; ?>
            <?php if ($canInventoryView): ?>
                <a href="inventory.php" class="btn-add" style="background:#1f6feb; color:#fff;"><i class="fa-solid fa-boxes-stacked"></i> <?php echo app_h(app_tr('المخزون', 'Inventory')); ?></a>
            <?php endif; ?>
            <?php if ($canStockAdjust): ?>
                <a href="adjust_stock.php" class="btn-add" style="background:#2d7d46; color:#fff;"><i class="fa-solid fa-right-left"></i> <?php echo app_h(app_tr('حركة/تحويل', 'Movement / Transfer')); ?></a>
            <?php endif; ?>
            <?php if ($canInventoryView): ?>
                <a href="inventory_audit.php" class="btn-add" style="background:#262626; color:#fff;"><i class="fa-solid fa-clipboard-check"></i> <?php echo app_h(app_tr('الجرد والمطابقة', 'Audit & Reconciliation')); ?></a>
            <?php endif; ?>
        </div>
    </div>

    <div class="kpi-grid">
        <div class="kpi">
            <div style="color:#888"><?php echo app_h(app_tr('المخازن النشطة', 'Active warehouses')); ?></div>
            <div class="n"><?php echo number_format((int)$stats['active_wh']); ?></div>
        </div>
        <div class="kpi">
            <div style="color:#888"><?php echo app_h(app_tr('عدد الأصناف', 'Items count')); ?></div>
            <div class="n"><?php echo number_format((int)$stats['items_count']); ?></div>
        </div>
        <div class="kpi">
            <div style="color:#888"><?php echo app_h(app_tr('إجمالي وحدات المخزون', 'Total stock units')); ?></div>
            <div class="n"><?php echo number_format((float)$stats['total_qty'], 2); ?></div>
        </div>
    </div>

    <div class="table-container">
        <table class="styled-table">
            <thead>
                <tr>
                    <th><?php echo app_h(app_tr('اسم المخزن', 'Warehouse Name')); ?></th>
                    <th><?php echo app_h(app_tr('الموقع', 'Location')); ?></th>
                    <th><?php echo app_h(app_tr('المسؤول', 'Manager')); ?></th>
                    <th><?php echo app_h(app_tr('الحالة', 'Status')); ?></th>
                    <th><?php echo app_h(app_tr('إجراءات', 'Actions')); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if ($warehouses_result && $warehouses_result->num_rows > 0): ?>
                    <?php while($wh = $warehouses_result->fetch_assoc()): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($wh['name']); ?></strong></td>
                            <td><?php echo htmlspecialchars($wh['location'] ?? app_tr('غير محدد', 'Not specified')); ?></td>
                            <td><?php echo htmlspecialchars($wh['manager_name'] ?? app_tr('غير معيّن', 'Not assigned')); ?></td>
                            <td>
                                <span class="<?php echo $wh['is_active'] ? 'status-active' : 'status-inactive'; ?>">
                                    <?php echo $wh['is_active'] ? app_h(app_tr('نشط', 'Active')) : app_h(app_tr('غير نشط', 'Inactive')); ?>
                                </span>
                            </td>
                            <td class="j-actions">
                                <?php if ($canWarehousesUpdate): ?>
                                    <a href="edit_warehouse.php?id=<?php echo (int)$wh['id']; ?>" title="<?php echo app_h(app_tr('تعديل بيانات المخزن', 'Edit warehouse')); ?>"><i class="fa-solid fa-pen"></i> <?php echo app_h(app_tr('تعديل', 'Edit')); ?></a>
                                <?php endif; ?>
                                <?php if ($canWarehousesToggle): ?>
                                    <form method="post" style="display:inline;margin:0;">
                                        <?php echo app_csrf_input(); ?>
                                        <input type="hidden" name="warehouse_id" value="<?php echo (int)$wh['id']; ?>">
                                        <button class="btn-toggle" type="submit" name="toggle_warehouse" value="1" title="<?php echo app_h(app_tr('تغيير الحالة', 'Change status')); ?>">
                                            <i class="fa-solid fa-power-off"></i> <?php echo $wh['is_active'] ? app_h(app_tr('تعطيل', 'Disable')) : app_h(app_tr('تفعيل', 'Enable')); ?>
                                        </button>
                                    </form>
                                <?php endif; ?>
                                <?php if ($canWarehousesDelete): ?>
                                    <form method="post" style="display:inline;margin:0;" onsubmit="return confirm('<?php echo app_h(app_tr('تحذير: سيتم حذف المخزن وحركاته إذا كان الرصيد صفراً. هل أنت متأكد؟', 'Warning: the warehouse and its movements will be deleted if stock is zero. Are you sure?')); ?>');">
                                        <?php echo app_csrf_input(); ?>
                                        <input type="hidden" name="warehouse_id" value="<?php echo (int)$wh['id']; ?>">
                                        <button class="btn-del" type="submit" name="delete_warehouse" value="1" title="<?php echo app_h(app_tr('حذف المخزن', 'Delete warehouse')); ?>">
                                            <i class="fa-solid fa-trash-can"></i> <?php echo app_h(app_tr('حذف', 'Delete')); ?>
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="5" style="text-align: center; padding: 50px; color: #888;">
                            <i class="fa-solid fa-warehouse fa-3x"></i><br><br>
                            <?php echo app_h(app_tr('لم يتم العثور على مخازن.', 'No warehouses found.')); ?> <a href="add_warehouse.php"><?php echo app_h(app_tr('أضف المخزن الأول الآن', 'Add the first warehouse now')); ?></a>.
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="table-container" style="margin-top:20px;">
        <table class="styled-table">
            <thead>
                <tr>
                    <th><?php echo app_h(app_tr('آخر حركات المخزون', 'Latest stock movements')); ?></th>
                    <th><?php echo app_h(app_tr('الصنف', 'Item')); ?></th>
                    <th><?php echo app_h(app_tr('المخزن', 'Warehouse')); ?></th>
                    <th><?php echo app_h(app_tr('الكمية', 'Quantity')); ?></th>
                    <th><?php echo app_h(app_tr('الوقت', 'Time')); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if($recent_movements && $recent_movements->num_rows > 0): ?>
                    <?php while($m = $recent_movements->fetch_assoc()): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($m['transaction_type']); ?></td>
                            <td><?php echo htmlspecialchars($m['item_name'] ?? '-'); ?></td>
                            <td><?php echo htmlspecialchars($m['warehouse_name'] ?? '-'); ?></td>
                            <td><?php echo number_format((float)$m['quantity'], 2); ?></td>
                            <td><?php echo htmlspecialchars($m['created_at']); ?></td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr><td colspan="5" style="text-align:center; padding:20px; color:#777;"><?php echo app_h(app_tr('لا توجد حركات حتى الآن.', 'No movements yet.')); ?></td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

</div>

<?php require 'footer.php'; ?>
<?php ob_end_flush(); ?>
