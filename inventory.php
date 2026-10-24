<?php
ob_start();
// inventory.php - (Royal Phantom V2.1 - Show All Items Fix)

error_reporting(E_ALL);

require 'auth.php';
require 'config.php';
app_handle_lang_switch($conn);

$canInventoryView = app_user_can('inventory.view');
$canInventoryCreate = app_user_can('inventory.items.create');
$canInventoryUpdate = app_user_can('inventory.items.update');
$canInventoryDelete = app_user_can('inventory.items.delete');
$canStockAdjust = app_user_can('inventory.stock.adjust');

if (!($canInventoryView || $canInventoryCreate || $canInventoryUpdate || $canInventoryDelete || $canStockAdjust)) {
    require 'header.php';
    echo "<div class='container'><h1><i class='fa-solid fa-lock'></i> صلاحيات الوصول غير كافية</h1></div>";
    require 'footer.php';
    exit;
}
$csrfToken = app_csrf_token();

// Delete inventory item (with related stock/movement records)
if (isset($_GET['del'])) {
    $delete_id = (int)($_GET['del'] ?? 0);
    $token = (string)($_GET['_token'] ?? '');

    if (!$canInventoryDelete) {
        header('Location: inventory.php?err=delete_denied');
        exit;
    }
    if ($delete_id <= 0 || !app_verify_csrf($token)) {
        header('Location: inventory.php?err=invalid_token');
        exit;
    }

    try {
        $conn->begin_transaction();

        $stmtStock = $conn->prepare("DELETE FROM inventory_stock WHERE item_id = ?");
        $stmtStock->bind_param('i', $delete_id);
        $stmtStock->execute();
        $stmtStock->close();

        // Keep deletion possible even when table constraints use RESTRICT.
        $stmtTrans = $conn->prepare("DELETE FROM inventory_transactions WHERE item_id = ?");
        $stmtTrans->bind_param('i', $delete_id);
        $stmtTrans->execute();
        $stmtTrans->close();

        $stmtItem = $conn->prepare("DELETE FROM inventory_items WHERE id = ?");
        $stmtItem->bind_param('i', $delete_id);
        $stmtItem->execute();
        $affected = $stmtItem->affected_rows;
        $stmtItem->close();

        $conn->commit();
        if ($affected > 0) {
            header('Location: inventory.php?msg=item_deleted');
        } else {
            header('Location: inventory.php?err=not_found');
        }
        exit;
    } catch (mysqli_sql_exception $e) {
        $conn->rollback();
        error_log('Delete inventory item failed: ' . $e->getMessage());
        header('Location: inventory.php?err=delete_failed');
        exit;
    }
}

require 'header.php';
echo '<link rel="stylesheet" href="assets/css/inventory-theme.css?v=20260311-1">';

// --- Search & Filter Logic ---
$search_query = trim($_GET['q'] ?? '');
$warehouse_filter = isset($_GET['warehouse']) ? intval($_GET['warehouse']) : 0;

$sql = "SELECT 
            i.id, i.item_code, i.name, i.category, i.unit, i.low_stock_threshold,
            COALESCE(SUM(s.quantity), 0) as total_quantity,
            GROUP_CONCAT(DISTINCT w.name SEPARATOR ', ') as warehouse_names,
            (COALESCE(SUM(s.quantity), 0) <= i.low_stock_threshold AND i.low_stock_threshold > 0) as is_low_stock
        FROM 
            inventory_items i
        LEFT JOIN 
            inventory_stock s ON i.id = s.item_id
        LEFT JOIN 
            warehouses w ON s.warehouse_id = w.id
        ";

$where_conditions = [];
$params = [];
$types = '';

// Apply warehouse filter logic correctly
if ($warehouse_filter > 0) {
    // If a specific warehouse is selected, we only care about stock in that warehouse.
    // We need to re-structure the query slightly for this case.
    $sql = "SELECT 
                i.id, i.item_code, i.name, i.category, i.unit, i.low_stock_threshold,
                COALESCE(s.quantity, 0) as total_quantity,
                w.name as warehouse_names
            FROM 
                inventory_items i
            LEFT JOIN 
                inventory_stock s ON i.id = s.item_id AND s.warehouse_id = ?
            LEFT JOIN 
                warehouses w ON s.warehouse_id = w.id
            ";
    $params[] = $warehouse_filter;
    $types .= 'i';
    // Add a condition to make sure the warehouse is active
    $where_conditions[] = "(w.id = ? OR w.id IS NULL)";
    $params[] = $warehouse_filter; // Add it again for the WHERE clause
    $types .= 'i';

} else {
     // If showing all warehouses, only join with stock from active warehouses.
     $where_conditions[] = "(w.is_active = 1 OR w.id IS NULL)";
}


if (!empty($search_query)) {
    $where_conditions[] = "(i.item_code LIKE ? OR i.name LIKE ?)";
    $search_like = "%{$search_query}%";
    $params = array_merge($params, [$search_like, $search_like]);
    $types .= 'ss';
}

if (!empty($where_conditions)) {
    $sql .= " WHERE " . implode(' AND ', $where_conditions);
}

if ($warehouse_filter > 0) {
    $sql .= " ORDER BY (COALESCE(s.quantity, 0) <= i.low_stock_threshold AND i.low_stock_threshold > 0) DESC, i.name ASC";
} else {
    $sql .= " GROUP BY i.id, i.item_code, i.name, i.category, i.unit, i.low_stock_threshold";
    $sql .= " ORDER BY (COALESCE(SUM(s.quantity), 0) <= i.low_stock_threshold AND i.low_stock_threshold > 0) DESC, i.name ASC";
}


$stmt = $conn->prepare($sql);
if ($stmt) {
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $items_result = $stmt->get_result();
} else {
    // Log error for debugging
    error_log("SQL Prepare Failed in inventory.php: " . $conn->error);
    $items_result = false;
}

// Fetch active warehouses for the filter dropdown
$warehouses_result = $conn->query("SELECT id, name FROM warehouses WHERE is_active = 1 ORDER BY name ASC");

$items = [];
if ($items_result) {
    while ($row = $items_result->fetch_assoc()) {
        $items[] = $row;
    }
}

$statsItems = count($items);
$statsLow = 0;
$statsZero = 0;
$statsQty = 0.0;
foreach ($items as $sItem) {
    $qty = (float)($sItem['total_quantity'] ?? 0);
    $min = (float)($sItem['low_stock_threshold'] ?? 0);
    $statsQty += $qty;
    if ($qty <= 0.000001) {
        $statsZero++;
    }
    if ($min > 0 && $qty <= $min) {
        $statsLow++;
    }
}

?>

<style>
    .inv-layout {
        display: grid;
        gap: 18px;
    }
    .inv-headline {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 10px;
    }
    .inv-title-sub {
        color: #9ba3b4;
        margin-top: 6px;
        font-size: 0.92rem;
    }
    .hero-actions { display:flex; gap:10px; flex-wrap:wrap; justify-content:flex-end; }
    .inv-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        padding: 11px 16px;
        border-radius: 12px;
        border: 1px solid transparent;
        font-weight: 800;
        text-decoration: none;
        cursor: pointer;
        line-height: 1;
        transition: transform .16s ease, box-shadow .16s ease, border-color .16s ease, opacity .16s ease;
    }
    .inv-btn:hover {
        transform: translateY(-1px);
        box-shadow: 0 10px 20px rgba(0,0,0,.28);
        opacity: .98;
    }
    .inv-btn.gold {
        background: linear-gradient(135deg, #e5c75a, #b98713);
        color: #131313;
        border-color: rgba(229, 199, 90, .45);
    }
    .inv-btn.blue {
        background: linear-gradient(135deg, #2f7cf7, #1e56c7);
        color: #f8fbff;
        border-color: rgba(47, 124, 247, .45);
    }
    .inv-btn.dark {
        background: #151923;
        color: #d8deea;
        border-color: #3a4254;
    }
    .inv-btn.ghost {
        background: transparent;
        color: #c5cede;
        border-color: #3b4251;
    }
    .inv-btn.sm {
        padding: 10px 14px;
        min-width: 96px;
    }

    .inv-kpi-grid {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 12px;
    }
    .inv-kpi {
        border: 1px solid var(--inv-border);
        border-radius: 14px;
        background: linear-gradient(180deg, rgba(14,16,22,.95), rgba(10,12,18,.9));
        padding: 14px;
    }
    .inv-kpi .k {
        color: #9ca5b6;
        font-size: 0.88rem;
        margin-bottom: 6px;
    }
    .inv-kpi .v {
        color: #f3f5f9;
        font-size: 1.6rem;
        font-weight: 900;
        line-height: 1.1;
    }
    .inv-kpi .v.gold { color: #f0d680; }
    .inv-kpi .v.ok { color: #7debb4; }
    .inv-kpi .v.bad { color: #ff9d92; }

    .inv-filters {
        display: grid;
        grid-template-columns: 1.2fr .9fr auto auto;
        gap: 10px;
        align-items: center;
    }
    .inv-filter-actions {
        display: flex;
        gap: 8px;
    }

    .inv-table-wrap {
        border: 1px solid var(--inv-border);
        border-radius: 16px;
        overflow: auto;
        background: linear-gradient(180deg, rgba(16,18,24,.95), rgba(12,14,20,.9));
    }
    .inv-table {
        width: 100%;
        min-width: 980px;
        border-collapse: collapse;
    }
    .inv-table th {
        position: sticky;
        top: 0;
        z-index: 2;
        background: rgba(8, 9, 13, 0.95);
        color: #c5cede;
        font-weight: 800;
        letter-spacing: .2px;
    }
    .inv-table th,
    .inv-table td {
        padding: 12px 14px;
        border-bottom: 1px solid rgba(255,255,255,.06);
        text-align: right;
        vertical-align: middle;
    }
    .inv-table tbody tr:hover {
        background: rgba(212,175,55,.06);
    }
    .inv-row-low {
        background: linear-gradient(90deg, rgba(231, 76, 60, 0.12), rgba(231, 76, 60, 0.03));
    }
    .inv-code {
        font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
        font-size: .86rem;
        color: #cfd7e7;
    }
    .inv-name {
        font-weight: 800;
        color: #f7f8fb;
    }
    .inv-meta {
        color: #a7b0bf;
        font-size: .84rem;
        margin-top: 2px;
    }
    .inv-qty {
        font-weight: 900;
        font-size: 1.05rem;
    }
    .inv-qty.zero { color: #8d95a5; }
    .inv-status-pill {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 4px 10px;
        border-radius: 999px;
        font-size: .82rem;
        font-weight: 800;
        border: 1px solid transparent;
    }
    .inv-status-pill.low {
        color: #ffb6ad;
        border-color: rgba(231,76,60,.45);
        background: rgba(231,76,60,.14);
    }
    .inv-status-pill.ok {
        color: #8df1bf;
        border-color: rgba(46,204,113,.45);
        background: rgba(46,204,113,.12);
    }
    .inv-status-pill.zero {
        color: #c0c7d3;
        border-color: rgba(156,165,182,.35);
        background: rgba(156,165,182,.1);
    }
    .inv-actions {
        display: flex;
        gap: 7px;
        flex-wrap: wrap;
    }
    .inv-actions a {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 34px;
        height: 34px;
        border: 1px solid rgba(255,255,255,.18);
        border-radius: 10px;
        color: #d7dce7;
        text-decoration: none;
    }
    .inv-actions a:hover {
        color: #f1d98b;
        border-color: rgba(212,175,55,.7);
    }
    .inv-actions a.act-del {
        color: #ffb4ac;
        border-color: rgba(231,76,60,.5);
    }
    .inv-actions a.act-del:hover {
        color: #ffd3cd;
        border-color: rgba(231,76,60,.9);
    }

    .inv-mobile-list { display: none; }
    .inv-mobile-card {
        border: 1px solid var(--inv-border);
        border-radius: 14px;
        background: linear-gradient(180deg, rgba(16,18,24,.95), rgba(12,14,20,.9));
        padding: 12px;
    }
    .inv-mobile-row {
        display: flex;
        justify-content: space-between;
        gap: 10px;
        padding: 5px 0;
        color: #c5cede;
        font-size: .92rem;
    }
    .inv-mobile-row .label { color: #8f98aa; }
    .inv-mobile-actions { margin-top: 8px; }

    @media (max-width: 1180px) {
        .inv-kpi-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        .inv-filters { grid-template-columns: 1fr 1fr; }
        .inv-filter-actions { grid-column: 1 / -1; }
    }
    @media (max-width: 900px) {
        .inv-headline { flex-direction: column; align-items: flex-start; }
        .hero-actions { width: 100%; display: grid; grid-template-columns: 1fr; }
        .hero-actions .btn-add { justify-content: center; }
        .inv-kpi-grid { grid-template-columns: 1fr 1fr; }
        .inv-filters { grid-template-columns: 1fr; }
        .inv-table-wrap { display: none; }
        .inv-mobile-list { display: grid; gap: 10px; }
    }
    @media (max-width: 560px) {
        .inv-kpi-grid { grid-template-columns: 1fr; }
    }
</style>

<div class="container page-shell inv-page">
    <?php if (isset($_GET['msg']) && $_GET['msg'] === 'item_deleted'): ?>
        <div class="page-alert alert-ok"><?php echo app_h(app_tr('تم حذف المنتج من المخزون بنجاح.', 'Inventory item deleted successfully.')); ?></div>
    <?php elseif (isset($_GET['msg']) && $_GET['msg'] === 'item_added'): ?>
        <div class="page-alert alert-ok"><?php echo app_h(app_tr('تم إضافة المنتج بنجاح.', 'Item added successfully.')); ?></div>
    <?php elseif (isset($_GET['msg']) && $_GET['msg'] === 'item_updated'): ?>
        <div class="page-alert alert-ok"><?php echo app_h(app_tr('تم تحديث بيانات المنتج بنجاح.', 'Item updated successfully.')); ?></div>
    <?php elseif (isset($_GET['err']) && $_GET['err'] === 'delete_denied'): ?>
        <div class="page-alert alert-bad"><?php echo app_h(app_tr('ليس لديك صلاحية حذف عناصر المخزون.', 'You do not have permission to delete inventory items.')); ?></div>
    <?php elseif (isset($_GET['err']) && $_GET['err'] === 'invalid_token'): ?>
        <div class="page-alert alert-bad"><?php echo app_h(app_tr('طلب الحذف غير صالح، أعد المحاولة.', 'Invalid delete request. Please try again.')); ?></div>
    <?php elseif (isset($_GET['err']) && $_GET['err'] === 'delete_failed'): ?>
        <div class="page-alert alert-bad"><?php echo app_h(app_tr('تعذر حذف المنتج بسبب ارتباطات أخرى أو خطأ في قاعدة البيانات.', 'Could not delete the item because of linked records or a database error.')); ?></div>
    <?php elseif (isset($_GET['err']) && $_GET['err'] === 'not_found'): ?>
        <div class="page-alert alert-bad"><?php echo app_h(app_tr('المنتج المطلوب غير موجود.', 'Requested item was not found.')); ?></div>
    <?php endif; ?>

    <div class="inv-layout">
        <div class="inv-headline">
            <div>
                <h1><i class="fa-solid fa-boxes-stacked"></i> <?php echo app_h(app_tr('إدارة المخزون', 'Inventory Management')); ?></h1>
                <div class="inv-title-sub"><?php echo app_h(app_tr('متابعة الأصناف والكميات وحالة المخزون في جميع المخازن.', 'Track items, quantities, and stock status across warehouses.')); ?></div>
            </div>
            <div class="hero-actions">
                <?php if ($canInventoryCreate): ?>
                    <a href="add_inventory_item.php" class="inv-btn gold"><i class="fa-solid fa-plus"></i> <?php echo app_h(app_tr('إضافة صنف', 'Add Item')); ?></a>
                <?php endif; ?>
                <?php if ($canStockAdjust): ?>
                    <a href="adjust_stock.php" class="inv-btn blue"><i class="fa-solid fa-right-left"></i> <?php echo app_h(app_tr('حركة/تحويل', 'Movement / Transfer')); ?></a>
                <?php endif; ?>
                <?php if ($canInventoryView): ?>
                    <a href="inventory_audit.php" class="inv-btn" style="background:#1a1a1a; color:#f4f4f4; border-color:#3a3a3a;"><i class="fa-solid fa-clipboard-check"></i> <?php echo app_h(app_tr('جرد المخازن', 'Warehouse Audit')); ?></a>
                <?php endif; ?>
            </div>
        </div>

        <div class="inv-kpi-grid">
            <div class="inv-kpi">
                <div class="k"><?php echo app_h(app_tr('إجمالي الأصناف المعروضة', 'Total Displayed Items')); ?></div>
                <div class="v gold"><?php echo number_format($statsItems); ?></div>
            </div>
            <div class="inv-kpi">
                <div class="k"><?php echo app_h(app_tr('أصناف منخفضة المخزون', 'Low Stock Items')); ?></div>
                <div class="v bad"><?php echo number_format($statsLow); ?></div>
            </div>
            <div class="inv-kpi">
                <div class="k"><?php echo app_h(app_tr('أصناف بدون رصيد', 'Out of Stock Items')); ?></div>
                <div class="v"><?php echo number_format($statsZero); ?></div>
            </div>
            <div class="inv-kpi">
                <div class="k"><?php echo app_h(app_tr('إجمالي الكمية', 'Total Quantity')); ?></div>
                <div class="v ok"><?php echo number_format($statsQty, 2); ?></div>
            </div>
        </div>

        <form method="GET" class="ph-filters inv-filters">
            <input type="text" name="q" class="ph-search" placeholder="<?php echo app_h(app_tr('بحث بالكود أو الاسم...', 'Search by code or name...')); ?>" value="<?php echo htmlspecialchars($search_query); ?>">
            <select name="warehouse" class="ph-search">
                <option value="0"><?php echo app_h(app_tr('كل المخازن', 'All Warehouses')); ?></option>
                <?php if($warehouses_result && $warehouses_result->num_rows > 0): ?>
                    <?php while($w = $warehouses_result->fetch_assoc()): ?>
                        <option value="<?php echo (int)$w['id']; ?>" <?php echo ($warehouse_filter === (int)$w['id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($w['name']); ?>
                        </option>
                    <?php endwhile; ?>
                <?php endif; ?>
            </select>
            <div class="inv-filter-actions">
                <button type="submit" class="inv-btn dark sm"><i class="fa-solid fa-search"></i> <?php echo app_h(app_tr('بحث', 'Search')); ?></button>
                <a href="inventory.php" class="inv-btn ghost sm"><?php echo app_h(app_tr('إعادة تعيين', 'Reset')); ?></a>
            </div>
        </form>

        <div class="inv-table-wrap">
            <table class="inv-table">
                <thead>
                    <tr>
                        <th><?php echo app_h(app_tr('الصنف', 'Item')); ?></th>
                        <th><?php echo app_h(app_tr('الفئة', 'Category')); ?></th>
                        <th><?php echo app_h(app_tr('الكمية', 'Quantity')); ?></th>
                        <th><?php echo app_h(app_tr('الوحدة', 'Unit')); ?></th>
                        <th><?php echo app_h(app_tr('المخازن', 'Warehouses')); ?></th>
                        <th><?php echo app_h(app_tr('الحالة', 'Status')); ?></th>
                        <th><?php echo app_h(app_tr('إجراءات', 'Actions')); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($items)): ?>
                        <?php foreach($items as $item):
                            $quantity = (float)($item['total_quantity'] ?? 0);
                            $isLow = ($item['low_stock_threshold'] > 0 && $quantity <= (float)$item['low_stock_threshold']);
                            $isZero = $quantity <= 0.000001;
                        ?>
                            <tr class="<?php echo $isLow ? 'inv-row-low' : ''; ?>">
                                <td>
                                    <div class="inv-name"><?php echo htmlspecialchars((string)$item['name']); ?></div>
                                    <div class="inv-meta"><span class="inv-code"><?php echo htmlspecialchars((string)$item['item_code']); ?></span></div>
                                </td>
                                <td><?php echo htmlspecialchars((string)($item['category'] ?? '--')); ?></td>
                                <td><span class="inv-qty <?php echo $isZero ? 'zero' : ''; ?>"><?php echo number_format($quantity, 2); ?></span></td>
                                <td><?php echo htmlspecialchars((string)$item['unit']); ?></td>
                                <td><?php echo htmlspecialchars((string)($item['warehouse_names'] ?? app_tr('لم يحدد بعد', 'Not assigned yet'))); ?></td>
                                <td>
                                    <?php if($isLow): ?>
                                        <span class="inv-status-pill low"><i class="fa-solid fa-triangle-exclamation"></i> <?php echo app_h(app_tr('مخزون منخفض', 'Low stock')); ?></span>
                                    <?php elseif(!$isZero): ?>
                                        <span class="inv-status-pill ok"><i class="fa-solid fa-circle-check"></i> <?php echo app_h(app_tr('متوفر', 'Available')); ?></span>
                                    <?php else: ?>
                                        <span class="inv-status-pill zero"><i class="fa-regular fa-circle"></i> <?php echo app_h(app_tr('لا يوجد رصيد', 'No stock')); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="inv-actions">
                                    <?php if ($canInventoryUpdate): ?>
                                        <a href="edit_inventory_item.php?id=<?php echo (int)$item['id']; ?>" title="<?php echo app_h(app_tr('تعديل بيانات المنتج', 'Edit item data')); ?>"><i class="fa-solid fa-pen"></i></a>
                                    <?php endif; ?>
                                    <?php if ($canStockAdjust): ?>
                                        <a href="adjust_stock.php?item_id=<?php echo (int)$item['id']; ?>" title="<?php echo app_h(app_tr('تسوية / حركة مخزون', 'Stock adjustment / movement')); ?>"><i class="fa-solid fa-right-left"></i></a>
                                    <?php endif; ?>
                                    <?php if ($canInventoryDelete): ?>
                                        <a class="act-del" href="inventory.php?del=<?php echo (int)$item['id']; ?>&amp;_token=<?php echo urlencode($csrfToken); ?>" title="<?php echo app_h(app_tr('حذف المنتج', 'Delete item')); ?>" onclick="return confirm('<?php echo app_h(app_tr('تحذير: سيتم حذف المنتج وكل سجلات مخزونه وحركاته. هل أنت متأكد؟', 'Warning: This item and all its stock records and movements will be deleted. Are you sure?')); ?>');"><i class="fa-solid fa-trash-can"></i></a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="7" style="text-align:center; padding:44px 16px; color:#9ea6b5;">
                                <i class="fa-solid fa-box-open fa-2x"></i><br><br>
                                <?php if(!empty($search_query)): ?>
                                    <?php echo app_h(app_tr('لا توجد نتائج مطابقة للبحث', 'No results found for')); ?> "<?php echo htmlspecialchars($search_query); ?>".
                                <?php else: ?>
                                    <?php echo app_h(app_tr('لا توجد أي منتجات معرفة في النظام حتى الآن. ابدأ بإضافة صنف جديد.', 'No products are defined in the system yet. Start by adding a new item.')); ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="inv-mobile-list">
            <?php if (!empty($items)): ?>
                <?php foreach($items as $item):
                    $quantity = (float)($item['total_quantity'] ?? 0);
                    $isLow = ($item['low_stock_threshold'] > 0 && $quantity <= (float)$item['low_stock_threshold']);
                    $isZero = $quantity <= 0.000001;
                ?>
                    <div class="inv-mobile-card <?php echo $isLow ? 'inv-row-low' : ''; ?>">
                        <div class="inv-name"><?php echo htmlspecialchars((string)$item['name']); ?></div>
                        <div class="inv-meta"><span class="inv-code"><?php echo htmlspecialchars((string)$item['item_code']); ?></span></div>
                        <div class="inv-mobile-row"><span class="label"><?php echo app_h(app_tr('الفئة', 'Category')); ?></span><span><?php echo htmlspecialchars((string)($item['category'] ?? '--')); ?></span></div>
                        <div class="inv-mobile-row"><span class="label"><?php echo app_h(app_tr('الكمية', 'Quantity')); ?></span><span class="inv-qty <?php echo $isZero ? 'zero' : ''; ?>"><?php echo number_format($quantity, 2); ?> <?php echo htmlspecialchars((string)$item['unit']); ?></span></div>
                        <div class="inv-mobile-row"><span class="label"><?php echo app_h(app_tr('المخازن', 'Warehouses')); ?></span><span><?php echo htmlspecialchars((string)($item['warehouse_names'] ?? app_tr('لم يحدد بعد', 'Not assigned yet'))); ?></span></div>
                        <div class="inv-mobile-row">
                            <span class="label"><?php echo app_h(app_tr('الحالة', 'Status')); ?></span>
                            <span>
                                <?php if($isLow): ?>
                                    <span class="inv-status-pill low"><i class="fa-solid fa-triangle-exclamation"></i> <?php echo app_h(app_tr('منخفض', 'Low')); ?></span>
                                <?php elseif(!$isZero): ?>
                                    <span class="inv-status-pill ok"><i class="fa-solid fa-circle-check"></i> <?php echo app_h(app_tr('متوفر', 'Available')); ?></span>
                                <?php else: ?>
                                    <span class="inv-status-pill zero"><i class="fa-regular fa-circle"></i> <?php echo app_h(app_tr('صفر', 'Zero')); ?></span>
                                <?php endif; ?>
                            </span>
                        </div>
                        <div class="inv-mobile-actions inv-actions">
                            <?php if ($canInventoryUpdate): ?>
                                <a href="edit_inventory_item.php?id=<?php echo (int)$item['id']; ?>" title="<?php echo app_h(app_tr('تعديل بيانات المنتج', 'Edit item data')); ?>"><i class="fa-solid fa-pen"></i></a>
                            <?php endif; ?>
                            <?php if ($canStockAdjust): ?>
                                <a href="adjust_stock.php?item_id=<?php echo (int)$item['id']; ?>" title="<?php echo app_h(app_tr('تسوية / حركة مخزون', 'Stock adjustment / movement')); ?>"><i class="fa-solid fa-right-left"></i></a>
                            <?php endif; ?>
                            <?php if ($canInventoryDelete): ?>
                                <a class="act-del" href="inventory.php?del=<?php echo (int)$item['id']; ?>&amp;_token=<?php echo urlencode($csrfToken); ?>" title="<?php echo app_h(app_tr('حذف المنتج', 'Delete item')); ?>" onclick="return confirm('<?php echo app_h(app_tr('تحذير: سيتم حذف المنتج وكل سجلات مخزونه وحركاته. هل أنت متأكد؟', 'Warning: This item and all its stock records and movements will be deleted. Are you sure?')); ?>');"><i class="fa-solid fa-trash-can"></i></a>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require 'footer.php'; ?>
<?php ob_end_flush(); ?>
