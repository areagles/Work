<?php
ob_start(); // Start output buffering at the very beginning

// adjust_stock.php - (Royal Phantom V1.1 - Header Fix)

error_reporting(E_ALL);

require 'auth.php';
require 'config.php';
require_once 'inventory_engine.php';
app_handle_lang_switch($conn);

$my_id = $_SESSION['user_id'] ?? 0;
$canAdjustStock = app_user_can('inventory.stock.adjust');
$message = '';

$item_id = isset($_GET['item_id']) ? intval($_GET['item_id']) : 0;
if ($item_id < 0) {
    header("Location: inventory.php");
    exit;
}

// Handle form submission (POST request) before any output is sent
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$canAdjustStock) {
        $message = "<div class='alert alert-danger'>صلاحيات الوصول غير كافية.</div>";
    } else {
        if (!app_verify_csrf($_POST['_csrf_token'] ?? '')) {
            $message = "<div class='alert alert-danger'>انتهت صلاحية الجلسة، أعد تحميل الصفحة ثم حاول مرة أخرى.</div>";
        } else {
        $form_item_id = $item_id > 0 ? $item_id : intval($_POST['item_id'] ?? 0);
        $warehouse_id = intval($_POST['warehouse_id'] ?? 0);
        $from_warehouse_id = intval($_POST['from_warehouse_id'] ?? 0);
        $to_warehouse_id = intval($_POST['to_warehouse_id'] ?? 0);
        $transaction_type = $_POST['transaction_type'] ?? 'in';
        $quantity = floatval($_POST['quantity']);
        $manual_unit_cost = max(0.0, (float)($_POST['manual_unit_cost'] ?? 0));
        $notes = trim((string)($_POST['notes'] ?? ''));

        if ($quantity > 0) {

            $conn->begin_transaction();
            try {
                if ($transaction_type === 'transfer') {
                    inventory_transfer_between_warehouses(
                        $conn,
                        $form_item_id,
                        $from_warehouse_id,
                        $to_warehouse_id,
                        (int)$my_id,
                        $quantity,
                        $notes
                    );
                } else {
                    inventory_manual_adjustment(
                        $conn,
                        $form_item_id,
                        $warehouse_id,
                        (int)$my_id,
                        $transaction_type,
                        $quantity,
                        $notes,
                        $manual_unit_cost
                    );
                }

                $conn->commit();
                app_safe_redirect("inventory.php?msg=stock_adjusted");

            } catch (Throwable $exception) {
                $conn->rollback();
                error_log('adjust_stock error: ' . $exception->getMessage());
                if ($exception->getMessage() === 'insufficient_stock') {
                    $message = "<div class='alert alert-danger'>لا يمكن تنفيذ الحركة لأن الرصيد المتاح غير كافٍ.</div>";
                } elseif ($exception instanceof InvalidArgumentException) {
                    $message = "<div class='alert alert-danger'>بيانات الحركة غير صالحة. راجع الحقول المطلوبة.</div>";
                } else {
                    $message = "<div class='alert alert-danger'>تعذر تنفيذ الحركة حالياً. راجع البيانات وحاول مرة أخرى.</div>";
                }
            }
        } else {
            $message = "<div class='alert alert-warning'>الرجاء إدخال كمية صحيحة.</div>";
        }
        }
    }
}

// Now that all logic with potential redirects is done, we can start outputting HTML.
require 'header.php';
echo '<link rel="stylesheet" href="assets/css/inventory-theme.css?v=20260311-1">';

// Check for access permissions to view the page
if (!$canAdjustStock) {
    echo "<div class='container'><h1><i class='fa-solid fa-lock'></i> صلاحيات الوصول غير كافية</h1></div>";
    require 'footer.php';
    exit;
}

// Fetch item data and current stock levels (optional item in transfer mode)
$item = null;
if ($item_id > 0) {
    $item_stmt = $conn->prepare("SELECT * FROM inventory_items WHERE id = ?");
    $item_stmt->bind_param('i', $item_id);
    $item_stmt->execute();
    $item_result = $item_stmt->get_result();
    if ($item_result->num_rows === 1) {
        $item = $item_result->fetch_assoc();
    } else {
        echo "<div class='container'><h1>خطأ</h1><p>المنتج غير موجود.</p></div>";
        require 'footer.php';
        exit;
    }
    $item_stmt->close();
}

// Fetch warehouses
$warehouses_result = $conn->query("SELECT id, name FROM warehouses WHERE is_active = TRUE ORDER BY name ASC");
$warehouses_result2 = $conn->query("SELECT id, name FROM warehouses WHERE is_active = TRUE ORDER BY name ASC");

// Fetch items list for transfer/manual selection
$items_result = $conn->query("SELECT id, item_code, name FROM inventory_items ORDER BY name ASC");

// Fetch current stock for this item across all warehouses
$current_stock_result = null;
if ($item_id > 0) {
    $stock_stmt = $conn->prepare("SELECT s.quantity, w.name as warehouse_name FROM inventory_stock s JOIN warehouses w ON s.warehouse_id = w.id WHERE s.item_id = ?");
    $stock_stmt->bind_param('i', $item_id);
    $stock_stmt->execute();
    $current_stock_result = $stock_stmt->get_result();
    $stock_stmt->close();
}

?>

<style>
    :root {
        --ae-gold: #d4af37;
        --ae-gold-soft: rgba(212, 175, 55, 0.12);
        --ae-border: rgba(212, 175, 55, 0.18);
        --ae-card: #141414;
        --ae-card-strong: #101010;
        --ae-input: #090909;
        --ae-text: #f3f3f3;
        --ae-muted: #a9a9a9;
        --ae-green: #2ecc71;
        --ae-red: #f87171;
        --ae-blue: #4ea3ff;
    }
    body { background-color: #050505; color: var(--ae-text); }
    .inv-move-shell { max-width: 1180px; margin: 0 auto; padding: 24px 16px 36px; }
    .inv-move-hero {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 18px;
        flex-wrap: wrap;
        margin-bottom: 22px;
    }
    .inv-move-title h1 {
        margin: 0;
        color: var(--ae-text);
        font-size: clamp(1.8rem, 2.8vw, 2.5rem);
        line-height: 1.12;
    }
    .inv-move-title p {
        margin: 8px 0 0;
        color: var(--ae-muted);
        font-size: 1rem;
        max-width: 760px;
        line-height: 1.7;
    }
    .inv-move-back {
        display: inline-flex;
        align-items: center;
        gap: 10px;
        min-height: 48px;
        padding: 0 18px;
        border-radius: 14px;
        border: 1px solid var(--ae-border);
        background: #111;
        color: #f0f0f0;
        text-decoration: none;
        font-weight: 700;
    }
    .inv-move-grid {
        display: grid;
        grid-template-columns: minmax(300px, 0.72fr) minmax(0, 1.28fr);
        gap: 22px;
        align-items: start;
    }
    .inv-info-card,
    .form-container {
        background: linear-gradient(180deg, #161616, #101010);
        border: 1px solid var(--ae-border);
        border-radius: 22px;
        box-shadow: 0 18px 40px rgba(0, 0, 0, 0.30);
    }
    .inv-info-card { padding: 22px; }
    .inv-info-card h3 {
        margin: 0 0 6px;
        color: var(--ae-text);
        font-size: 1.35rem;
    }
    .inv-info-card p {
        margin: 0;
        color: var(--ae-muted);
        line-height: 1.7;
    }
    .stock-list {
        display: grid;
        gap: 10px;
        margin-top: 18px;
    }
    .stock-pill {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 16px;
        padding: 14px 16px;
        border-radius: 16px;
        background: var(--ae-card-strong);
        border: 1px solid rgba(255,255,255,0.06);
    }
    .stock-pill span { color: var(--ae-muted); font-weight: 600; }
    .stock-pill strong { color: var(--ae-text); font-size: 1.05rem; }
    .movement-cards {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 12px;
        margin-top: 18px;
    }
    .movement-card {
        padding: 16px;
        border-radius: 18px;
        border: 1px solid rgba(255,255,255,0.06);
        background: var(--ae-card-strong);
    }
    .movement-card h4 {
        margin: 0 0 6px;
        color: var(--ae-text);
        font-size: 1rem;
    }
    .movement-card p {
        color: var(--ae-muted);
        font-size: 0.92rem;
        line-height: 1.55;
    }
    .movement-card.in { box-shadow: inset 0 0 0 1px rgba(46, 204, 113, 0.18); }
    .movement-card.out { box-shadow: inset 0 0 0 1px rgba(248, 113, 113, 0.18); }
    .movement-card.transfer { box-shadow: inset 0 0 0 1px rgba(78, 163, 255, 0.18); }
    .form-container { padding: 24px; }
    .form-title {
        margin: 0 0 18px;
        font-size: 1.28rem;
        color: var(--ae-text);
    }
    .form-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 18px;
    }
    .form-group { margin: 0; display: flex; flex-direction: column; gap: 8px; }
    .form-group.full { grid-column: 1 / -1; }
    .form-group label { display: block; color: #ddd; font-weight: 700; line-height: 1.45; }
    .form-control {
        background: var(--ae-input);
        color: var(--ae-text);
        width: 100%;
        min-height: 52px;
        padding: 14px 16px;
        border-radius: 16px;
        border: 1px solid #2f2f2f;
        font-size: 1rem;
        transition: .18s ease;
    }
    select.form-control {
        height: 52px;
        padding-block: 0;
        appearance: none;
        -webkit-appearance: none;
        background-image:
            linear-gradient(45deg, transparent 50%, #888 50%),
            linear-gradient(135deg, #888 50%, transparent 50%);
        background-position:
            calc(100% - 22px) calc(50% - 3px),
            calc(100% - 16px) calc(50% - 3px);
        background-size: 6px 6px, 6px 6px;
        background-repeat: no-repeat;
    }
    textarea.form-control {
        min-height: 140px;
        resize: vertical;
        line-height: 1.6;
    }
    .form-control:focus {
        border-color: var(--ae-gold);
        outline: none;
        box-shadow: 0 0 0 4px var(--ae-gold-soft);
    }
    .group-panel {
        grid-column: 1 / -1;
        padding: 18px;
        border-radius: 18px;
        border: 1px dashed rgba(255,255,255,0.1);
        background: rgba(255,255,255,0.02);
    }
    .group-panel h4 {
        margin: 0 0 12px;
        color: var(--ae-text);
        font-size: 1rem;
    }
    .group-panel-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 16px;
    }
    .btn-submit {
        background: linear-gradient(135deg, #e5c44f, #c99a17);
        color: #111;
        min-height: 52px;
        padding: 0 28px;
        border: none;
        border-radius: 16px;
        font-weight: 800;
        font-size: 1rem;
        cursor: pointer;
        box-shadow: 0 14px 28px rgba(201, 154, 23, 0.22);
    }
    .form-actions {
        display: flex;
        justify-content: flex-end;
        margin-top: 22px;
    }
    .alert { padding: 15px 16px; border-radius: 14px; margin-bottom: 18px; border: 1px solid; }
    .alert-danger { background: rgba(127, 29, 29, 0.22); color: #ffd7d7; border-color: rgba(248, 113, 113, 0.45); }
    .alert-warning { background: rgba(120, 88, 0, 0.18); color: #ffeaa6; border-color: rgba(241, 196, 15, 0.35); }
    @media (max-width: 980px) {
        .inv-move-grid { grid-template-columns: 1fr; }
        .movement-cards,
        .form-grid,
        .group-panel-grid { grid-template-columns: 1fr; }
    }
</style>

<div class="container inv-page inv-move-shell">
    <div class="inv-move-hero">
        <div class="inv-move-title">
            <h1><i class="fa-solid fa-right-left"></i> <?php echo app_h(app_tr('حركة المخزون', 'Stock Movement')); ?></h1>
            <p><?php echo app_h(app_tr('سجّل إدخالاً أو صرفًا أو تحويلًا بين المخازن من شاشة واحدة، مع عرض الرصيد الحالي للصنف قبل تنفيذ الحركة.', 'Record inbound, outbound, or warehouse transfer movements from one screen, with the current item balance shown before applying the movement.')); ?></p>
        </div>
        <a class="inv-move-back" href="inventory.php"><i class="fa-solid fa-arrow-left"></i> <?php echo app_h(app_tr('العودة إلى المخزون', 'Back to Inventory')); ?></a>
    </div>

    <div class="inv-move-grid">
        <aside class="inv-info-card">
            <h3><?php echo $item ? htmlspecialchars($item['name']) : app_h(app_tr('حركة وتحويل المخزون', 'Stock Movement & Transfer')); ?></h3>
            <p><?php echo app_h(app_tr('راجع الرصيد الحالي أولاً، ثم اختر نوع الحركة المناسب. في التحويل سيتم الخصم من مخزن المصدر والإضافة إلى مخزن الوجهة.', 'Review the current balance first, then choose the required movement type. In transfers, quantity is deducted from the source warehouse and added to the destination warehouse.')); ?></p>

            <?php if($item && $current_stock_result && $current_stock_result->num_rows > 0): ?>
                <div class="stock-list">
                    <?php while($st = $current_stock_result->fetch_assoc()): ?>
                        <div class="stock-pill">
                            <span><?php echo htmlspecialchars($st['warehouse_name']); ?></span>
                            <strong><?php echo number_format((float)$st['quantity'], 2); ?></strong>
                        </div>
                    <?php endwhile; ?>
                </div>
            <?php elseif($item): ?>
                <div class="stock-list">
                    <div class="stock-pill">
                        <span><?php echo app_h(app_tr('حالة الرصيد', 'Stock Status')); ?></span>
                        <strong><?php echo app_h(app_tr('لا يوجد رصيد', 'No Balance')); ?></strong>
                    </div>
                </div>
            <?php endif; ?>

            <div class="movement-cards">
                <div class="movement-card in">
                    <h4><?php echo app_h(app_tr('إدخال', 'Inbound')); ?></h4>
                    <p><?php echo app_h(app_tr('لإضافة رصيد جديد إلى مخزن محدد.', 'Use it to add new quantity into a selected warehouse.')); ?></p>
                </div>
                <div class="movement-card out">
                    <h4><?php echo app_h(app_tr('صرف', 'Outbound')); ?></h4>
                    <p><?php echo app_h(app_tr('لخصم كمية من المخزون مع منع الصرف أكبر من المتاح.', 'Use it to deduct stock while blocking quantities greater than available.')); ?></p>
                </div>
                <div class="movement-card transfer">
                    <h4><?php echo app_h(app_tr('تحويل', 'Transfer')); ?></h4>
                    <p><?php echo app_h(app_tr('لنقل الكمية بين مخزنين مع تسجيل أثر الحركة بالكامل.', 'Move quantity between two warehouses with a full movement trail.')); ?></p>
                </div>
            </div>
        </aside>

        <div class="form-container">
            <h3 class="form-title"><?php echo app_h(app_tr('بيانات الحركة', 'Movement Details')); ?></h3>
            <?php echo $message; ?>
            <form method="POST">
            <?php echo app_csrf_input(); ?>
            <div class="form-grid">
                <?php if ($item_id > 0): ?>
                    <input type="hidden" name="item_id" value="<?php echo $item_id; ?>">
                <?php else: ?>
                    <div class="form-group full">
                        <label for="item_id"><?php echo app_h(app_tr('الصنف', 'Item')); ?></label>
                        <select id="item_id" name="item_id" class="form-control" required>
                            <option value=""><?php echo app_h(app_tr('-- اختر الصنف --', '-- Select item --')); ?></option>
                            <?php if($items_result) while($it = $items_result->fetch_assoc()): ?>
                                <option value="<?php echo (int)$it['id']; ?>"><?php echo htmlspecialchars($it['item_code'] . ' - ' . $it['name']); ?></option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                <?php endif; ?>

                <div class="form-group">
                    <label for="transaction_type"><?php echo app_h(app_tr('نوع الحركة', 'Movement Type')); ?></label>
                    <select id="transaction_type" name="transaction_type" class="form-control" onchange="toggleTransferFields()">
                        <option value="in"><?php echo app_h(app_tr('إدخال / إضافة (+)', 'Inbound / Add (+)')); ?></option>
                        <option value="out"><?php echo app_h(app_tr('إخراج / سحب (-)', 'Outbound / Deduct (-)')); ?></option>
                        <option value="transfer"><?php echo app_h(app_tr('تحويل بين مخزنين', 'Transfer between warehouses')); ?></option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="quantity"><?php echo app_h(app_tr('الكمية', 'Quantity')); ?></label>
                    <input type="number" id="quantity" name="quantity" class="form-control" step="0.01" required>
                </div>

                <div class="form-group" id="manualCostGroup">
                    <label for="manual_unit_cost"><?php echo app_h(app_tr('تكلفة الوحدة عند الإدخال', 'Inbound unit cost')); ?></label>
                    <input type="number" id="manual_unit_cost" name="manual_unit_cost" class="form-control" step="0.0001" min="0" placeholder="0.0000">
                    <small style="color:#9ca3af;display:block;margin-top:6px;"><?php echo app_h(app_tr('تستخدم لتحديث متوسط تكلفة الخامة عند الإدخال اليدوي.', 'Used to update the item average cost for manual inbound stock.')); ?></small>
                </div>

                <div class="form-group full" id="singleWarehouseGroup">
                    <label for="warehouse_id"><?php echo app_h(app_tr('المخزن', 'Warehouse')); ?></label>
                    <select id="warehouse_id" name="warehouse_id" class="form-control" required>
                        <option value=""><?php echo app_h(app_tr('-- اختر المخزن --', '-- Select warehouse --')); ?></option>
                        <?php while($wh = $warehouses_result->fetch_assoc()): ?>
                            <option value="<?php echo $wh['id']; ?>"><?php echo htmlspecialchars($wh['name']); ?></option>
                        <?php endwhile; ?>
                    </select>
                </div>

                <div id="transferGroups" class="group-panel" style="display:none;">
                    <h4><?php echo app_h(app_tr('بيانات التحويل', 'Transfer Details')); ?></h4>
                    <div class="group-panel-grid">
                        <div class="form-group">
                            <label for="from_warehouse_id"><?php echo app_h(app_tr('من مخزن', 'From Warehouse')); ?></label>
                            <select id="from_warehouse_id" name="from_warehouse_id" class="form-control">
                                <option value=""><?php echo app_h(app_tr('-- اختر مخزن المصدر --', '-- Select source warehouse --')); ?></option>
                                <?php if($warehouses_result2) while($wh2 = $warehouses_result2->fetch_assoc()): ?>
                                    <option value="<?php echo (int)$wh2['id']; ?>"><?php echo htmlspecialchars($wh2['name']); ?></option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="to_warehouse_id"><?php echo app_h(app_tr('إلى مخزن', 'To Warehouse')); ?></label>
                            <select id="to_warehouse_id" name="to_warehouse_id" class="form-control">
                                <option value=""><?php echo app_h(app_tr('-- اختر مخزن الوجهة --', '-- Select destination warehouse --')); ?></option>
                                <?php
                                $warehouses_result3 = $conn->query("SELECT id, name FROM warehouses WHERE is_active = TRUE ORDER BY name ASC");
                                if($warehouses_result3) while($wh3 = $warehouses_result3->fetch_assoc()): ?>
                                    <option value="<?php echo (int)$wh3['id']; ?>"><?php echo htmlspecialchars($wh3['name']); ?></option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="form-group full">
                    <label for="notes"><?php echo app_h(app_tr('ملاحظات', 'Notes')); ?></label>
                    <textarea id="notes" name="notes" class="form-control" placeholder="<?php echo app_h(app_tr('اكتب سبب الحركة أو أي ملاحظة تشغيلية مهمة.', 'Add the movement reason or any operational note.')); ?>"></textarea>
                </div>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn-submit"><?php echo app_h(app_tr('تنفيذ الحركة', 'Apply Movement')); ?></button>
            </div>
        </form>
        </div>
    </div>
</div>

<script>
function toggleTransferFields() {
    const type = document.getElementById('transaction_type').value;
    const single = document.getElementById('singleWarehouseGroup');
    const transfer = document.getElementById('transferGroups');
    const warehouse = document.getElementById('warehouse_id');
    const fromWh = document.getElementById('from_warehouse_id');
    const toWh = document.getElementById('to_warehouse_id');
    const manualCost = document.getElementById('manualCostGroup');

    if (type === 'transfer') {
        single.style.display = 'none';
        transfer.style.display = 'block';
        warehouse.required = false;
        if (fromWh) fromWh.required = true;
        if (toWh) toWh.required = true;
        if (manualCost) manualCost.style.display = 'none';
    } else {
        single.style.display = 'block';
        transfer.style.display = 'none';
        warehouse.required = true;
        if (fromWh) fromWh.required = false;
        if (toWh) toWh.required = false;
        if (manualCost) manualCost.style.display = type === 'in' ? 'block' : 'none';
    }
}
document.addEventListener('DOMContentLoaded', toggleTransferFields);
</script>

<?php 
require 'footer.php'; 
ob_end_flush(); // Send final output to browser
?>
