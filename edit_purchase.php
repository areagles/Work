<?php
// edit_purchase.php - تعديل فاتورة مشتريات (Royal Version & Logic Fix)
ob_start();
require 'auth.php'; 
require 'config.php'; 
require_once 'inventory_engine.php';
app_handle_lang_switch($conn);
require 'header.php';

$canInvoiceUpdate = app_user_can('invoices.update');
if (!$canInvoiceUpdate) {
    http_response_code(403);
    echo "<div class='container' style='margin-top:30px;'><div style='background:#1d0f0f;border:1px solid #5b2020;border-radius:12px;padding:20px;color:#ffb3b3;'>" . app_h(app_tr('⛔ لا تملك صلاحية تعديل المشتريات.', '⛔ You do not have permission to update purchases.')) . "</div></div>";
    require 'footer.php';
    exit;
}

// 1. التحقق من وجود الفاتورة
if(!isset($_GET['id'])) header("Location: invoices.php?tab=purchases");
$id = intval($_GET['id']);

$stmtInv = $conn->prepare("SELECT * FROM purchase_invoices WHERE id = ? LIMIT 1");
$stmtInv->bind_param('i', $id);
$stmtInv->execute();
$inv = $stmtInv->get_result()->fetch_assoc();
$stmtInv->close();
if(!$inv) die("<div class='container' style='margin-top:50px; color:red; text-align:center;'>عفواً، الفاتورة غير موجودة.</div>");
if ((string)($inv['status'] ?? '') === 'cancelled') {
    echo "<div class='container' style='margin-top:30px;'><div style='background:#1d0f0f;border:1px solid #5b2020;border-radius:12px;padding:20px;color:#ffb3b3;'>" . app_h(app_tr('⛔ لا يمكن تعديل فاتورة شراء ملغاة.', '⛔ Cancelled purchase invoices cannot be edited.')) . "</div></div>";
    require 'footer.php';
    exit;
}

$items = json_decode($inv['items_json'] ?? '[]', true);
$warehouseRows = [];
$warehouses = $conn->query("SELECT id, name FROM warehouses WHERE is_active = 1 ORDER BY name ASC");
if ($warehouses) {
    while ($wh = $warehouses->fetch_assoc()) {
        $warehouseRows[] = $wh;
    }
}
$supplierName = 'مورد محذوف';
$stmtSupplierName = $conn->prepare("SELECT name FROM suppliers WHERE id = ? LIMIT 1");
$supplierIdForView = (int)($inv['supplier_id'] ?? 0);
if ($stmtSupplierName && $supplierIdForView > 0) {
    $stmtSupplierName->bind_param('i', $supplierIdForView);
    $stmtSupplierName->execute();
    $supplierNameRow = $stmtSupplierName->get_result()->fetch_assoc();
    $supplierName = (string)($supplierNameRow['name'] ?? $supplierName);
    $stmtSupplierName->close();
}
$supplierDisplayName = trim((string)($inv['supplier_display_name'] ?? ''));
$effectiveSupplierName = $supplierDisplayName !== '' ? $supplierDisplayName : $supplierName;
$isTaxPurchase = trim((string)($inv['eta_uuid'] ?? '')) !== '' || trim((string)($inv['eta_status'] ?? '')) !== '';
$supplierDisplayName = trim((string)($inv['supplier_display_name'] ?? ''));
$effectiveSupplierName = $supplierDisplayName !== '' ? $supplierDisplayName : $supplierName;
$isTaxPurchase = trim((string)($inv['eta_uuid'] ?? '')) !== '' || trim((string)($inv['eta_status'] ?? '')) !== '';

$inventoryPosted = inventory_purchase_invoice_is_posted($conn, $id);
$msg = '';
$flashSuccess = $_SESSION['purchase_flash_success'] ?? '';
unset($_SESSION['purchase_flash_success']);

// 2. معالجة الحفظ
if($_SERVER['REQUEST_METHOD'] == 'POST'){
    if (!$canInvoiceUpdate) {
        http_response_code(403);
        die(app_h(app_tr('غير مصرح بتنفيذ هذه العملية.', 'Not authorized to perform this action.')));
    }
    if (!app_verify_csrf($_POST['_csrf_token'] ?? '')) {
        http_response_code(403);
        die(app_h(app_tr('انتهت صلاحية الجلسة. قم بتحديث الصفحة ثم حاول مرة أخرى.', 'Session expired. Refresh and try again.')));
    }
    $inv_date = trim((string)($_POST['inv_date'] ?? ''));
    $warehouse_id = (int)($_POST['warehouse_id'] ?? 0);
    $supplier_display_name = trim((string)($_POST['supplier_display_name'] ?? ''));
    $notes = trim((string)($_POST['notes'] ?? ''));
    if ($isTaxPurchase) {
        $supplier_display_name = (string)($inv['supplier_display_name'] ?? '');
    }
    
    // أ) تجميع البنود وحساب الإجمالي الجديد
    $sub_total = 0;
    $new_items = [];
    
    if(isset($_POST['item_name'])){
        for($i=0; $i<count($_POST['item_name']); $i++){
            $itemId = (int)($_POST['item_id'][$i] ?? 0);
            $itemCode = trim((string)($_POST['item_code'][$i] ?? ''));
            $unitType = trim((string)($_POST['unit_type'][$i] ?? ''));
            $desc = $_POST['item_name'][$i];
            $qty = floatval($_POST['qty'][$i]);
            $price = floatval($_POST['price'][$i]);
            
            if(!empty($desc) && $qty > 0){
                $total = $qty * $price;
                $sub_total += $total;
                $line = ['item_id'=>$itemId, 'desc'=>$desc, 'qty'=>$qty, 'price'=>$price, 'total'=>$total];
                if ($itemCode !== '') {
                    $line['item_code'] = $itemCode;
                }
                if ($unitType !== '') {
                    $line['unit_type'] = $unitType;
                }
                $new_items[] = $line;
            }
        }
    }

    $oldItemsNormalized = [];
    foreach (($items ?: []) as $oldItem) {
        $oldItemsNormalized[] = [
            'item_id' => (int)($oldItem['item_id'] ?? 0),
            'desc' => (string)($oldItem['desc'] ?? ''),
            'qty' => round((float)($oldItem['qty'] ?? 0), 4),
            'price' => round((float)($oldItem['price'] ?? 0), 4),
        ];
    }
    $newItemsNormalized = [];
    foreach ($new_items as $newItem) {
        $newItemsNormalized[] = [
            'item_id' => (int)($newItem['item_id'] ?? 0),
            'desc' => (string)($newItem['desc'] ?? ''),
            'qty' => round((float)($newItem['qty'] ?? 0), 4),
            'price' => round((float)($newItem['price'] ?? 0), 4),
        ];
    }
    if ($inventoryPosted) {
        $warehouseChanged = ((int)($inv['warehouse_id'] ?? 0) !== $warehouse_id);
        $itemsChanged = json_encode($oldItemsNormalized, JSON_UNESCAPED_UNICODE) !== json_encode($newItemsNormalized, JSON_UNESCAPED_UNICODE);
        if ($warehouseChanged || $itemsChanged) {
            $msg = app_tr(
                '❌ لا يمكن تعديل بنود الأصناف أو المخزن بعد ترحيل الفاتورة إلى المخزون. أنشئ فاتورة تصحيح أو فاتورة جديدة بدلًا من ذلك.',
                '❌ You cannot edit item lines or warehouse after the invoice has been posted to inventory. Create an adjustment or a new invoice instead.'
            );
        }
    }
    
    // ب) حساب الصوافي
    $tax = floatval($_POST['tax']);
    $discount = floatval($_POST['discount']);
    $new_grand_total = ($sub_total + $tax) - $discount;
    if($new_grand_total < 0) $new_grand_total = 0;

    $json = json_encode($new_items, JSON_UNESCAPED_UNICODE);

    // ج) المنطق المحاسبي (تحديث المتبقي والحالة)
    // 1. حساب الفرق لتحديث رصيد المورد
    $diff = $new_grand_total - $inv['total_amount'];
    
    // 2. حساب المتبقي الجديد بناءً على ما تم دفعه سابقاً
    $paid_amount = $inv['paid_amount']; // المدفوع لا يتغير من هذه الصفحة
    $new_remaining = $new_grand_total - $paid_amount;
    
    // 3. تحديد الحالة الجديدة تلقائياً
    $new_status = 'unpaid';
    if($new_remaining <= 0) {
        $new_remaining = 0;
        $new_status = 'paid';
    } elseif($paid_amount > 0) {
        $new_status = 'partially_paid';
    }

    // د) تنفيذ التحديث
    if ($msg === '' && $inv_date === '') {
        $msg = app_tr('❌ تاريخ الفاتورة مطلوب.', '❌ Invoice date is required.');
    }
    if ($msg === '' && empty($new_items)) {
        $msg = app_tr('❌ أضف بند مشتريات واحدًا على الأقل.', '❌ Add at least one purchase line.');
    }
    if ($msg === '' && $warehouse_id <= 0) {
        $msg = app_tr('❌ اختر المخزن المستلم.', '❌ Select the receiving warehouse.');
    }

    if($msg === ''){
        $conn->begin_transaction();
        try {
            $postedNow = false;
            $stmtUpdate = $conn->prepare("UPDATE purchase_invoices SET inv_date = ?, warehouse_id = ?, supplier_display_name = ?, tax = ?, discount = ?, total_amount = ?, remaining_amount = ?, status = ?, items_json = ?, notes = ? WHERE id = ?");
            if (!$stmtUpdate) {
                throw new RuntimeException($conn->error);
            }
            $stmtUpdate->bind_param(
                'sisddddsssi',
                $inv_date,
                $warehouse_id,
                $supplier_display_name,
                $tax,
                $discount,
                $new_grand_total,
                $new_remaining,
                $new_status,
                $json,
                $notes,
                $id
            );
            if (!$stmtUpdate->execute()) {
                throw new RuntimeException($stmtUpdate->error ?: $conn->error);
            }
            $stmtUpdate->close();

            if (!$inventoryPosted) {
                inventory_post_existing_purchase_invoice($conn, $id, (int)($_SESSION['user_id'] ?? 0));
                $postedNow = true;
            } elseif (abs($diff) > 0.000001) {
                $supplier_id = (int)$inv['supplier_id'];
                $stmtSupplierBalance = $conn->prepare("UPDATE suppliers SET current_balance = current_balance + ? WHERE id = ?");
                if (!$stmtSupplierBalance) {
                    throw new RuntimeException($conn->error);
                }
                $stmtSupplierBalance->bind_param('di', $diff, $supplier_id);
                if (!$stmtSupplierBalance->execute()) {
                    throw new RuntimeException($stmtSupplierBalance->error ?: $conn->error);
                }
                $stmtSupplierBalance->close();
            }

            $conn->commit();
            $_SESSION['purchase_flash_success'] = $postedNow
                ? app_tr(
                    '✅ تم حفظ الفاتورة وترحيلها للمخزون وتصحيح رصيد المورد بنجاح.',
                    '✅ Purchase invoice saved, posted to inventory, and supplier balance corrected successfully.'
                )
                : app_tr(
                    '✅ تم تحديث الفاتورة وإعادة احتساب المديونية بنجاح.',
                    '✅ Purchase invoice updated and balances recalculated successfully.'
                );
            app_safe_redirect('invoices.php?tab=purchases');
        } catch (Throwable $e) {
            $conn->rollback();
            $reason = (string)$e->getMessage();
            if ($reason === 'purchase_invoice_items_unmapped') {
                $msg = app_tr(
                    '❌ تعذر ترحيل أصناف فاتورة ETA إلى المخزون لأن البنود غير مربوطة بأصناف المخزون بعد. راجع أكواد الأصناف أو أسماء البنود ثم أعد الحفظ.',
                    '❌ Failed to post ETA purchase items to inventory because the lines are not mapped to stock items yet. Review item codes or names, then save again.'
                );
            } else {
                $msg = app_tr('❌ تعذر حفظ التعديلات:', '❌ Failed to save changes:') . ' ' . $reason;
            }
        }
    }
}
?>

<style>
    :root { --gold: #d4af37; --bg-dark: #0f0f0f; --card-bg: #1a1a1a; }
    body { background-color: var(--bg-dark); color: #fff; font-family: 'Cairo'; }

    .royal-card { background: var(--card-bg); padding: 30px; border-radius: 15px; border: 1px solid #333; border-top: 4px solid var(--gold); margin-top: 30px; box-shadow: 0 10px 30px rgba(0,0,0,0.5); }
    
    input, textarea, select { width: 100%; background: #050505; border: 1px solid #444; color: #fff; padding: 12px; border-radius: 6px; box-sizing: border-box; }
    input:focus, textarea:focus { border-color: var(--gold); outline: none; }
    label { color: var(--gold); margin-bottom: 5px; display: block; font-weight: bold; font-size: 0.9rem; }

    table { width: 100%; border-collapse: collapse; margin-top: 20px; }
    th { text-align: right; color: #aaa; padding: 10px; border-bottom: 1px solid #333; font-size: 0.9rem; }
    td { padding: 5px; border-bottom: 1px solid #222; }
    
    .row-total { font-weight: bold; color: var(--gold); border: none; background: transparent; text-align: left; }
    
    .btn-action { cursor: pointer; padding: 5px 10px; border-radius: 5px; font-weight: bold; border: none; }
    .btn-add { background: #333; color: #fff; width: auto; margin-top: 10px; }
    .btn-save { background: linear-gradient(45deg, var(--gold), #b8860b); color: #000; width: 100%; padding: 15px; margin-top: 20px; font-size: 1.1rem; }
    .btn-del { color: #e74c3c; background: transparent; font-size: 1.2rem; }

    .totals-area { background: #222; padding: 20px; border-radius: 10px; margin-top: 20px; border: 1px solid #333; width: 300px; margin-right: auto; }
    .totals-row { display: flex; justify-content: space-between; margin-bottom: 10px; align-items: center; }
</style>

<div class="container">
    <div class="royal-card">
        <div style="display:flex; justify-content:space-between; border-bottom:1px solid #333; padding-bottom:15px; margin-bottom:20px;">
            <h2 style="color:var(--gold); margin:0;">✏️ تعديل فاتورة مشتريات #<?php echo $id; ?></h2>
            <a href="invoices.php?tab=purchases" style="color:#aaa; text-decoration:none;"><i class="fa-solid fa-arrow-right"></i> رجوع</a>
        </div>

        <?php if ($flashSuccess !== ''): ?>
            <div style="margin-bottom:18px; background:#102416; border:1px solid #2c7a45; color:#d6ffe1; border-radius:12px; padding:14px 16px;">
                <?php echo app_h($flashSuccess); ?>
            </div>
        <?php endif; ?>
        <?php if ($msg !== ''): ?>
            <div style="margin-bottom:18px; background:#2b1212; border:1px solid #7a2c2c; color:#ffd9d9; border-radius:12px; padding:14px 16px;">
                <?php echo app_h($msg); ?>
            </div>
        <?php endif; ?>
        <?php if (!$inventoryPosted): ?>
            <div style="margin-bottom:18px; background:#1b2230; border:1px solid #40506f; color:#dbe7ff; border-radius:12px; padding:14px 16px;">
                <?php echo app_h(app_tr('ℹ️ هذه الفاتورة لم تُرحَّل للمخزون بعد. عند الحفظ سيتم ترحيلها وتصحيح رصيد المورد تلقائيًا.', 'ℹ️ This invoice has not been posted to inventory yet. Saving will post it and correct the supplier balance automatically.')); ?>
            </div>
        <?php endif; ?>
        <div style="margin-bottom:18px; background:#171717; border:1px solid #333; border-radius:12px; padding:14px 16px; color:#ddd;">
            <strong style="color:var(--gold);"><?php echo app_h(app_tr('اسم المورد المعروض على الفاتورة', 'Displayed supplier name on invoice')); ?>:</strong>
            <?php echo app_h($effectiveSupplierName); ?>
            <span style="margin-inline-start:10px; display:inline-flex; align-items:center; gap:6px; padding:4px 10px; border-radius:999px; background:<?php echo $isTaxPurchase ? 'rgba(231, 76, 60, 0.12)' : 'rgba(46, 204, 113, 0.12)'; ?>; border:1px solid <?php echo $isTaxPurchase ? 'rgba(231, 76, 60, 0.3)' : 'rgba(46, 204, 113, 0.3)'; ?>; color:<?php echo $isTaxPurchase ? '#ffb3b3' : '#b9ffd0'; ?>; font-size:.78rem; font-weight:700;">
                <i class="fa-solid <?php echo $isTaxPurchase ? 'fa-file-shield' : 'fa-file-circle-check'; ?>"></i>
                <?php echo app_h($isTaxPurchase ? app_tr('ضريبية / ETA', 'Tax / ETA') : app_tr('غير ضريبية', 'Non-tax')); ?>
            </span>
        </div>

        <form method="POST" id="invoiceForm">
            <?php echo app_csrf_input(); ?>
            <div style="display:grid; grid-template-columns: 1fr 1fr; gap:20px;">
                <div>
                    <label>تاريخ الفاتورة</label>
                    <input type="date" name="inv_date" value="<?php echo $inv['inv_date']; ?>" required>
                </div>
                <div>
                    <label>المورد (للقراءة فقط)</label>
                    <input type="text" value="<?php echo app_h($supplierName); ?>" readonly style="opacity:0.6; cursor:not-allowed;">
                </div>
                <div>
                    <label><?php echo app_h(app_tr('اسم المورد على الفاتورة', 'Supplier name on invoice')); ?></label>
                    <input type="text" name="supplier_display_name" value="<?php echo app_h((string)($inv['supplier_display_name'] ?? '')); ?>" <?php echo $isTaxPurchase ? 'readonly style="opacity:0.6; cursor:not-allowed;"' : ''; ?> placeholder="<?php echo app_h(app_tr('اختياري - يطبع بدل اسم المورد الأساسي', 'Optional - printed instead of supplier master name')); ?>">
                </div>
                <div>
                    <label>المخزن المستلم</label>
                    <select name="warehouse_id" required>
                        <option value="">-- اختر المخزن --</option>
                        <?php foreach ($warehouseRows as $wh): ?>
                            <option value="<?php echo (int)$wh['id']; ?>" <?php echo ((int)($inv['warehouse_id'] ?? 0) === (int)$wh['id']) ? 'selected' : ''; ?>>
                                <?php echo app_h($wh['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div style="margin:14px 0 6px; color:<?php echo $isTaxPurchase ? '#f4df9a' : '#999'; ?>; font-size:.82rem;">
                <?php echo app_h($isTaxPurchase
                    ? app_tr('هذه الفاتورة ضريبية/ETA، لذلك اسم المورد عليها للقراءة فقط.', 'This is an ETA/tax invoice, so the supplier name is read-only here.')
                    : app_tr('يمكنك تعديل اسم المورد المعروض لهذه الفاتورة فقط دون تعديل سجل المورد الرئيسي.', 'You can change the displayed supplier name for this invoice only without modifying the supplier master record.')); ?>
            </div>
            <?php if ($isTaxPurchase): ?>
                <div style="margin:14px 0 6px; background:#2a2212; border:1px solid #6e5a22; border-radius:12px; padding:12px 14px; color:#f4df9a;">
                    <?php echo app_h(app_tr('هذه فاتورة ضريبية/ETA، لذلك اسم المورد عليها غير قابل للتعديل من شاشة الفاتورة.', 'This is an ETA/tax purchase invoice, so the supplier name cannot be edited from this screen.')); ?>
                </div>
            <?php else: ?>
                <div style="margin:14px 0 6px; color:#999; font-size:.82rem;">
                    <?php echo app_h(app_tr('هذا الحقل يغيّر اسم المورد في هذه الفاتورة فقط دون تعديل سجل المورد الرئيسي.', 'This field changes the supplier name for this invoice only without modifying the supplier master record.')); ?>
                </div>
            <?php endif; ?>

            <table id="items_table">
                <thead>
                    <tr>
                        <th width="40%">الصنف / البيان</th>
                        <th width="15%">الكمية</th>
                        <th width="20%">السعر</th>
                        <th width="20%">الإجمالي</th>
                        <th width="5%"></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if($items): foreach($items as $item): ?>
                    <tr>
                        <td><input type="hidden" name="item_id[]" value="<?php echo (int)($item['item_id'] ?? 0); ?>"><input type="hidden" name="item_code[]" value="<?php echo app_h((string)($item['item_code'] ?? '')); ?>"><input type="hidden" name="unit_type[]" value="<?php echo app_h((string)($item['unit_type'] ?? '')); ?>"><input type="text" name="item_name[]" value="<?php echo $item['desc']; ?>" required placeholder="اسم الصنف"></td>
                        <td><input type="number" name="qty[]" value="<?php echo $item['qty']; ?>" step="0.01" oninput="calc(this)" placeholder="0"></td>
                        <td><input type="number" name="price[]" value="<?php echo $item['price']; ?>" step="0.01" oninput="calc(this)" placeholder="0.00"></td>
                        <td><input type="text" readonly class="row-total" value="<?php echo $item['total']; ?>"></td>
                        <td style="text-align:center;"><button type="button" class="btn-action btn-del" onclick="removeRow(this)">✕</button></td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
            
            <button type="button" class="btn-action btn-add" onclick="addRow()">+ إضافة بند جديد</button>

            <div class="totals-area">
                <div class="totals-row">
                    <span>الضريبة / رسوم:</span>
                    <input type="number" name="tax" value="<?php echo $inv['tax'] ?? 0; ?>" oninput="calcAll()" style="width:100px; padding:5px;">
                </div>
                <div class="totals-row">
                    <span>خصم:</span>
                    <input type="number" name="discount" value="<?php echo $inv['discount'] ?? 0; ?>" oninput="calcAll()" style="width:100px; padding:5px;">
                </div>
                <div style="border-top:1px solid #444; margin:10px 0;"></div>
                <div class="totals-row" style="font-size:1.2rem; font-weight:bold; color:var(--gold);">
                    <span>الإجمالي النهائي:</span>
                    <span id="grand_total"><?php echo number_format($inv['total_amount'], 2); ?></span>
                </div>
                <div style="text-align:center; font-size:0.8rem; color:#aaa; margin-top:5px;">
                    (مدفوع سابقاً: <?php echo number_format($inv['paid_amount'], 2); ?>)
                </div>
            </div>

            <div style="margin-top:20px;">
                <label>ملاحظات</label>
                <textarea name="notes" rows="2" placeholder="أي تفاصيل إضافية..."><?php echo $inv['notes']; ?></textarea>
            </div>

            <button type="submit" class="btn-action btn-save">
                <i class="fa-solid fa-save"></i> حفظ التعديلات وتحديث الحسابات
            </button>
        </form>
    </div>
</div>

<script>
// إضافة صف جديد
function addRow(){
    let tbody = document.querySelector('#items_table tbody');
    let tr = document.createElement('tr');
    tr.innerHTML = `
        <td><input type="hidden" name="item_id[]" value="0"><input type="hidden" name="item_code[]" value=""><input type="hidden" name="unit_type[]" value=""><input type="text" name="item_name[]" required placeholder="اسم الصنف"></td>
        <td><input type="number" name="qty[]" value="1" step="0.01" oninput="calc(this)"></td>
        <td><input type="number" name="price[]" value="0" step="0.01" oninput="calc(this)"></td>
        <td><input type="text" readonly class="row-total" value="0.00"></td>
        <td style="text-align:center;"><button type="button" class="btn-action btn-del" onclick="removeRow(this)">✕</button></td>
    `;
    tbody.appendChild(tr);
}

// حذف صف
function removeRow(btn){
    btn.closest('tr').remove();
    calcAll();
}

// حساب صف واحد
function calc(input){
    let tr = input.closest('tr');
    let q = parseFloat(tr.querySelector('input[name="qty[]"]').value) || 0;
    let p = parseFloat(tr.querySelector('input[name="price[]"]').value) || 0;
    tr.querySelector('.row-total').value = (q * p).toFixed(2);
    calcAll();
}

// حساب الإجمالي الكلي
function calcAll(){
    let sub = 0;
    document.querySelectorAll('.row-total').forEach(el => {
        sub += parseFloat(el.value) || 0;
    });

    let tax = parseFloat(document.querySelector('input[name="tax"]').value) || 0;
    let disc = parseFloat(document.querySelector('input[name="discount"]').value) || 0;

    let total = (sub + tax) - disc;
    if(total < 0) total = 0;

    document.getElementById('grand_total').innerText = total.toFixed(2);
}
</script>

<?php include 'footer.php'; ob_end_flush(); ?>
