<?php
// add_purchase.php - (Royal Phantom V3.0 - Integrated Inventory Automation)
ob_start();
require 'auth.php'; 
require 'config.php'; 
require_once 'inventory_engine.php';
require_once 'receipts_runtime.php';
app_handle_lang_switch($conn);
require 'header.php';

$canInvoiceCreate = app_user_can('invoices.create');
if (!$canInvoiceCreate) {
    http_response_code(403);
    echo "<div class='container' style='margin-top:30px;'><div style='background:#1d0f0f;border:1px solid #5b2020;border-radius:12px;padding:20px;color:#ffb3b3;'>" . app_h(app_tr('⛔ لا تملك صلاحية تسجيل المشتريات.', '⛔ You do not have permission to create purchases.')) . "</div></div>";
    require 'footer.php';
    exit;
}

$user_id = $_SESSION['user_id'] ?? 0; // Get user ID for logging
$purchaseMessage = '';
$purchaseForm = [
    'supplier_id' => 0,
    'supplier_display_name' => '',
    'warehouse_id' => 0,
    'inv_date' => date('Y-m-d'),
    'due_date' => date('Y-m-d'),
    'tax' => 0,
    'discount' => 0,
    'notes' => '',
];
$purchaseDraftItems = [];

if (isset($_GET['clone_id']) && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $cloneId = (int)($_GET['clone_id'] ?? 0);
    if ($cloneId > 0) {
        $stmtClone = $conn->prepare("
            SELECT supplier_id, warehouse_id, inv_date, due_date, tax, discount, notes, items_json, status
            FROM purchase_invoices
            WHERE id = ?
            LIMIT 1
        ");
        if ($stmtClone) {
            $stmtClone->bind_param('i', $cloneId);
            $stmtClone->execute();
            $cloneInv = $stmtClone->get_result()->fetch_assoc();
            $stmtClone->close();
            if ($cloneInv && (string)($cloneInv['status'] ?? '') !== 'cancelled') {
                $purchaseForm['supplier_id'] = (int)($cloneInv['supplier_id'] ?? 0);
                $purchaseForm['supplier_display_name'] = (string)($cloneInv['supplier_display_name'] ?? '');
                $purchaseForm['warehouse_id'] = (int)($cloneInv['warehouse_id'] ?? 0);
                $purchaseForm['inv_date'] = date('Y-m-d');
                $purchaseForm['due_date'] = date('Y-m-d');
                $purchaseForm['tax'] = (float)($cloneInv['tax'] ?? 0);
                $purchaseForm['discount'] = (float)($cloneInv['discount'] ?? 0);
                $purchaseForm['notes'] = (string)($cloneInv['notes'] ?? '');
                $cloneItems = json_decode((string)($cloneInv['items_json'] ?? '[]'), true);
                if (is_array($cloneItems)) {
                    foreach ($cloneItems as $cloneItem) {
                        $itemId = (int)($cloneItem['item_id'] ?? 0);
                        $qty = (float)($cloneItem['qty'] ?? 0);
                        if ($itemId <= 0 || $qty <= 0) {
                            continue;
                        }
                        $purchaseDraftItems[] = [
                            'item_id' => $itemId,
                            'qty' => $qty,
                            'price' => (float)($cloneItem['price'] ?? 0),
                        ];
                    }
                }
                $purchaseMessage = "<div class='alert-box' style='background:#1b2230;border:1px solid #40506f;color:#dbe7ff;border-radius:12px;padding:14px 16px;margin-bottom:18px;'>" . app_h(app_tr('ℹ️ تم تحميل بيانات فاتورة الشراء كمسودة جديدة. لن يتم ترحيل أي كمية أو إنشاء فاتورة جديدة إلا بعد الضغط على حفظ.', 'ℹ️ Purchase invoice data loaded as a new draft. No stock or new invoice will be posted until you save.')) . "</div>";
            }
        }
    }
}

// --- Pre-fetch data for forms ---
// Fetch warehouses
$warehouses_res = $conn->query("SELECT id, name FROM warehouses WHERE is_active = 1 ORDER BY name ASC");

// Fetch inventory items for the dropdown
$items_res = $conn->query("SELECT id, name, item_code FROM inventory_items ORDER BY name ASC");
$inventory_items_options = "";
while($item = $items_res->fetch_assoc()){
    $inventory_items_options .= "<option value='{$item['id']}'>" . htmlspecialchars($item['name']) . " (" . htmlspecialchars($item['item_code']) . ")</option>";
}

// --- POST Request Handling ---
if($_SERVER['REQUEST_METHOD'] == 'POST'){
    if (!$canInvoiceCreate) {
        http_response_code(403);
        die(app_h(app_tr('غير مصرح بتنفيذ هذه العملية.', 'Not authorized to perform this action.')));
    }
    if (!app_verify_csrf($_POST['_csrf_token'] ?? '')) {
        http_response_code(403);
        die(app_h(app_tr('انتهت صلاحية الجلسة. قم بتحديث الصفحة ثم حاول مرة أخرى.', 'Session expired. Refresh and try again.')));
    }
    $purchaseForm['supplier_id'] = (int)($_POST['supplier_id'] ?? 0);
    $purchaseForm['supplier_display_name'] = trim((string)($_POST['supplier_display_name'] ?? ''));
    $purchaseForm['warehouse_id'] = (int)($_POST['warehouse_id'] ?? 0);
    $purchaseForm['inv_date'] = (string)($_POST['inv_date'] ?? date('Y-m-d'));
    $purchaseForm['due_date'] = (string)($_POST['due_date'] ?? date('Y-m-d'));
    $purchaseForm['tax'] = (float)($_POST['tax'] ?? 0);
    $purchaseForm['discount'] = (float)($_POST['discount'] ?? 0);
    $purchaseForm['notes'] = (string)($_POST['notes'] ?? '');
    $purchaseDraftItems = [];
    // 1. Get main invoice data
    $supplier_id = $purchaseForm['supplier_id'];
    $supplier_display_name = $purchaseForm['supplier_display_name'];
    $warehouse_id = $purchaseForm['warehouse_id']; // << NEW: Warehouse ID
    $inv_date = $purchaseForm['inv_date'];
    $due_date = $purchaseForm['due_date'];
    $notes = $conn->real_escape_string($purchaseForm['notes']);
    
    // 2. Validate essential data
    if($supplier_id == 0 || $warehouse_id == 0) {
        $purchaseMessage = "<div class='alert-box error'>" . app_h(app_tr('❌ الرجاء اختيار المورد والمخزن المستلم.', '❌ Please select the supplier and receiving warehouse.')) . "</div>";
    } else {
        // 3. Loop through items and calculate totals
        $items_for_db = [];
        $sub_total = 0;
        if(isset($_POST['item_id'])){
            for($i=0; $i<count($_POST['item_id']); $i++){
                $item_id = intval($_POST['item_id'][$i]);
                $qty = floatval($_POST['qty'][$i]);
                $price = floatval($_POST['price'][$i]);
                
                if($item_id > 0 && $qty > 0) {
                    $total = $qty * $price;
                    $sub_total += $total;
                    $purchaseDraftItems[] = ['item_id' => $item_id, 'qty' => $qty, 'price' => $price];
                    $item_info = null;
                    $itemInfoStmt = $conn->prepare("SELECT name, item_code FROM inventory_items WHERE id = ? LIMIT 1");
                    if ($itemInfoStmt) {
                        $itemInfoStmt->bind_param('i', $item_id);
                        $itemInfoStmt->execute();
                        $item_info = $itemInfoStmt->get_result()->fetch_assoc() ?: null;
                        $itemInfoStmt->close();
                    }
                    if (!$item_info) {
                        continue;
                    }
                    $item_name = (string)$item_info['name'] . ' (' . (string)$item_info['item_code'] . ')';

                    $items_for_db[] = ['item_id'=>$item_id, 'desc'=>$item_name, 'qty'=>$qty, 'price'=>$price, 'total'=>$total];
                }
            }
        }
        
        if(empty($items_for_db)) {
            $purchaseMessage = "<div class='alert-box error'>" . app_h(app_tr('❌ لا يمكن حفظ فاتورة فارغة أو بدون كميات.', '❌ Cannot save an empty invoice or lines without quantities.')) . "</div>";
        } else {
            $json = json_encode($items_for_db, JSON_UNESCAPED_UNICODE);
            $tax = $purchaseForm['tax'];
            $discount = $purchaseForm['discount'];
            $grand_total = ($sub_total + $tax) - $discount;
            try {
                $conn->begin_transaction();

                $stmtInvoice = $conn->prepare("INSERT INTO purchase_invoices (supplier_id, supplier_display_name, warehouse_id, inv_date, due_date, sub_total, tax, discount, total_amount, remaining_amount, status, items_json, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'unpaid', ?, ?)");
                if (!$stmtInvoice) {
                    throw new RuntimeException('prepare_purchase_invoice_failed');
                }
                $remainingAmount = $grand_total;
                $stmtInvoice->bind_param('isissdddddss', $supplier_id, $supplier_display_name, $warehouse_id, $inv_date, $due_date, $sub_total, $tax, $discount, $grand_total, $remainingAmount, $json, $notes);
                $stmtInvoice->execute();
                $purchase_invoice_id = (int)$conn->insert_id;
                $stmtInvoice->close();

                app_assign_document_number($conn, 'purchase_invoices', (int)$purchase_invoice_id, 'purchase_number', 'purchase', $inv_date);
                $creator = (string)($_SESSION['name'] ?? 'System');
                app_apply_supplier_opening_balance_to_purchase_invoice($conn, (int)$purchase_invoice_id, (int)$supplier_id, $inv_date, $creator);

                inventory_receive_purchase_invoice(
                    $conn,
                    $purchase_invoice_id,
                    $supplier_id,
                    $warehouse_id,
                    (int)$user_id,
                    $items_for_db,
                    (float)$grand_total
                );

                if (function_exists('finance_post_purchase_invoice_issue_to_ledger')) {
                    finance_post_purchase_invoice_issue_to_ledger($conn, (int)$purchase_invoice_id, (string)($_SESSION['name'] ?? 'System'));
                }

                $conn->commit();
                $_SESSION['flash_success'] = app_tr('✅ تم حفظ فاتورة الشراء وتحديث المخزون ومتوسط التكلفة تلقائياً.', '✅ Purchase invoice saved and inventory/cost average updated.');
                app_safe_redirect('invoices.php?tab=purchases');
            } catch (Throwable $e) {
                $conn->rollback();
                error_log('add_purchase failed: ' . $e->getMessage());
                $purchaseMessage = "<div class='alert-box error'>" . app_h(app_tr('خطأ أثناء حفظ الفاتورة أو تحديث المخزون. راجع البيانات ثم حاول مرة أخرى.', 'Error while saving the invoice or updating inventory. Review the data and try again.')) . "</div>";
            }
        }
    }
}
?>

<style>
    /* Same styles as before */
    :root { --gold: #d4af37; --dark-bg: #121212; --panel-bg: #1e1e1e; --border: #333; }
    .royal-card { background: var(--panel-bg); border: 1px solid var(--border); border-radius: 12px; padding: 30px; margin-top: 20px; }
    .page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; border-bottom: 1px solid var(--border); padding-bottom: 15px; }
    .page-title { color: var(--gold); margin: 0; }
    .form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 20px; margin-bottom: 30px; }
    .input-group label { display: block; color: #aaa; margin-bottom: 8px; }
    .form-control { width: 100%; padding: 12px; background: #0a0a0a; border: 1px solid #444; color: #fff; border-radius: 8px; }
    .table-container { overflow-x: auto; background: #151515; border-radius: 8px; border: 1px solid var(--border); margin-bottom: 20px; }
    table { width: 100%; border-collapse: collapse; min-width: 700px; }
    th { background: #222; color: var(--gold); padding: 15px; text-align: center; }
    td { padding: 10px; border-bottom: 1px solid #222; }
    .table-input { width: 100%; background: transparent; border: none; border-bottom: 1px solid #444; color: #fff; text-align: center; padding: 8px; }
    .text-left { text-align: right !important; }
    .totals-wrapper { display: flex; justify-content: flex-end; margin-top: 20px; }
    .totals-card { background: #151515; border: 1px solid var(--gold); border-radius: 10px; padding: 20px; width: 350px; }
    .total-row { display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; }
    .grand-total { font-size: 1.4rem; color: var(--gold); border-top: 1px solid #333; padding-top: 10px; font-weight: bold; }
    .btn-add { background: #222; color: #fff; border: 1px dashed #555; width: 100%; padding: 12px; cursor: pointer; border-radius: 8px; }
    .btn-save { background: linear-gradient(45deg, var(--gold), #b8860b); color: #000; font-weight: bold; border: none; padding: 15px 30px; border-radius: 8px; cursor: pointer; width: 100%; font-size: 1.1rem; }
    .del-btn { color: #e74c3c; cursor: pointer; }
</style>

<div class="container">
    <div class="royal-card">
        <div class="page-header">
            <h2 class="page-title"><i class="fa-solid fa-file-invoice-dollar"></i> تسجيل فاتورة مشتريات (مع تحديث المخزون)</h2>
            <a href="invoices.php?tab=purchases" style="color:#aaa; text-decoration:none;"><i class="fa-solid fa-arrow-right"></i> رجوع</a>
        </div>
        <?php echo $purchaseMessage; ?>
        
        <form method="POST">
            <?php echo app_csrf_input(); ?>
            <div class="form-grid">
                <div class="input-group">
                    <label><i class="fa-solid fa-truck-field"></i> المورد <span style="color:red">*</span></label>
                    <select name="supplier_id" class="form-control" required>
                        <option value="">-- اختر المورد --</option>
                        <?php 
                        $s_res = $conn->query("SELECT * FROM suppliers ORDER BY name ASC");
                        while($s = $s_res->fetch_assoc()) echo "<option value='{$s['id']}'" . (((int)$purchaseForm['supplier_id'] === (int)$s['id']) ? ' selected' : '') . ">" . htmlspecialchars($s['name']) . "</option>";
                        ?>
                    </select>
                </div>
                <div class="input-group">
                    <label><i class="fa-solid fa-signature"></i> اسم المورد على الفاتورة</label>
                    <input type="text" name="supplier_display_name" class="form-control" value="<?php echo app_h((string)($purchaseForm['supplier_display_name'] ?? '')); ?>" placeholder="اختياري - يطبع بدل اسم المورد الأساسي">
                </div>
                 <div class="input-group">
                    <label><i class="fa-solid fa-warehouse"></i> المخزن المستلم <span style="color:red">*</span></label>
                    <select name="warehouse_id" class="form-control" required>
                        <option value="">-- اختر المخزن --</option>
                        <?php 
                        // Reset pointer and loop through warehouses again
                        $warehouses_res->data_seek(0);
                        while($wh = $warehouses_res->fetch_assoc()) echo "<option value='{$wh['id']}'" . (((int)$purchaseForm['warehouse_id'] === (int)$wh['id']) ? ' selected' : '') . ">" . htmlspecialchars($wh['name']) . "</option>";
                        ?>
                    </select>
                </div>
                <div class="input-group">
                    <label><i class="fa-regular fa-calendar"></i> تاريخ الفاتورة</label>
                    <input type="date" name="inv_date" class="form-control" value="<?php echo app_h((string)$purchaseForm['inv_date']); ?>" required>
                </div>
                <div class="input-group">
                    <label><i class="fa-solid fa-hourglass-half"></i> تاريخ الاستحقاق</label>
                    <input type="date" name="due_date" class="form-control" value="<?php echo app_h((string)$purchaseForm['due_date']); ?>" required>
                </div>
            </div>

            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th width="40%" style="text-align:right; padding-right:20px;">الصنف من المخزون</th>
                            <th width="15%">الكمية</th>
                            <th width="15%">سعر الوحدة</th>
                            <th width="20%">الإجمالي</th>
                            <th width="10%">حذف</th>
                        </tr>
                    </thead>
                    <tbody id="items_area">
                        <!-- Initial row will be added by JS -->
                    </tbody>
                </table>
            </div>
            
            <button type="button" onclick="addRow()" class="btn-add"><i class="fa-solid fa-plus"></i> إضافة صنف جديد</button>

            <div class="totals-wrapper">
                 <div class="totals-card">
                    <div class="total-row"><span>المجموع الفرعي:</span><span id="sub_total" style="font-weight:bold;">0.00</span></div>
                    <div class="total-row"><span>(+) ضريبة / مصاريف:</span><input type="number" step="0.01" name="tax" value="<?php echo app_h((string)$purchaseForm['tax']); ?>" oninput="calcAll()" class="total-input"></div>
                    <div class="total-row"><span>(-) خصم مكتسب:</span><input type="number" step="0.01" name="discount" value="<?php echo app_h((string)$purchaseForm['discount']); ?>" oninput="calcAll()" class="total-input"></div>
                    <div class="total-row grand-total"><span>الصافي النهائي:</span><span id="grand_total">0.00 EGP</span></div>
                </div>
            </div>

            <div class="input-group" style="margin-top:20px;">
                <label><i class="fa-solid fa-note-sticky"></i> ملاحظات</label>
                <textarea name="notes" class="form-control" rows="3"><?php echo app_h((string)$purchaseForm['notes']); ?></textarea>
            </div>

            <button type="submit" class="btn-save"><i class="fa-solid fa-save"></i> حفظ وترحيل للمخزون</button>
        </form>
    </div>
</div>

<script>
const inventoryItems = `<?php echo addslashes($inventory_items_options); ?>`;
const preloadedRows = <?php echo json_encode($purchaseDraftItems, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;

function addRow(rowData = null){
    let tr = document.createElement('tr');
    tr.innerHTML = `
        <td>
            <select name="item_id[]" required class="form-control table-input text-left" style="padding-right:10px;">
                <option value="">-- اختر منتج --</option>
                ${inventoryItems}
            </select>
        </td>
        <td><input type="number" step="0.01" name="qty[]" value="1" oninput="calc(this)" class="table-input"></td>
        <td><input type="number" step="0.01" name="price[]" value="0" oninput="calc(this)" class="table-input"></td>
        <td><input type="text" readonly class="row-total table-input" value="0.00" style="color:var(--gold); font-weight:bold;"></td>
        <td style="text-align:center;"><i class="fa-solid fa-trash-can del-btn" onclick="deleteRow(this)"></i></td>
    `;
    document.getElementById('items_area').appendChild(tr);
    if (rowData && typeof rowData === 'object') {
        tr.querySelector('[name="item_id[]"]').value = String(rowData.item_id || '');
        tr.querySelector('[name="qty[]"]').value = Number(rowData.qty || 0) > 0 ? rowData.qty : 1;
        tr.querySelector('[name="price[]"]').value = Number(rowData.price || 0);
        calc(tr.querySelector('[name="price[]"]'));
    }
}

function deleteRow(btn) {
    btn.closest('tr').remove();
    calcAll();
}

function calc(el){
    let tr = el.closest('tr');
    let q = parseFloat(tr.querySelector('[name="qty[]"]').value) || 0;
    let p = parseFloat(tr.querySelector('[name="price[]"]').value) || 0;
    tr.querySelector('.row-total').value = (q * p).toFixed(2);
    calcAll();
}

function calcAll(){
    let sub = 0;
    document.querySelectorAll('.row-total').forEach(e => sub += parseFloat(e.value));
    document.getElementById('sub_total').innerText = sub.toFixed(2);
    
    let tax = parseFloat(document.querySelector('[name="tax"]').value) || 0;
    let disc = parseFloat(document.querySelector('[name="discount"]').value) || 0;
    
    let grand = (sub + tax) - disc;
    document.getElementById('grand_total').innerText = grand.toFixed(2) + ' EGP';
}

// Add one row automatically on page load
document.addEventListener('DOMContentLoaded', function() {
    if (Array.isArray(preloadedRows) && preloadedRows.length) {
        preloadedRows.forEach(addRow);
        calcAll();
        return;
    }
    addRow();
});
</script>

<?php include 'footer.php'; ?>
