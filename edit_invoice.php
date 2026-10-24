<?php
// edit_invoice.php - (النسخة الإمبراطورية: تحديث حقول التاريخ)
ob_start();
error_reporting(E_ALL);

require 'auth.php'; 
require 'config.php'; 
require_once 'finance_engine.php';
require_once __DIR__ . '/modules/tax/eta_einvoice_runtime.php';
app_handle_lang_switch($conn);
require 'header.php';
$etaWorkRuntime = app_is_work_runtime();
$etaItemCatalog = $etaWorkRuntime ? app_eta_einvoice_item_catalog($conn) : [];

$canInvoiceCreate = app_user_can('invoices.create');
$canInvoiceUpdate = app_user_can('invoices.update');
$isEditRequest = isset($_GET['id']) && ((int)$_GET['id'] > 0);
if (($isEditRequest && !$canInvoiceUpdate) || (!$isEditRequest && !$canInvoiceCreate)) {
    http_response_code(403);
    echo "<div class='container' style='margin-top:30px;'><div style='background:#1d0f0f;border:1px solid #5b2020;border-radius:12px;padding:20px;color:#ffb3b3;'>" . app_h(app_tr('⛔ لا تملك صلاحية إنشاء/تعديل الفواتير.', '⛔ You do not have permission to create/update invoices.')) . "</div></div>";
    require 'footer.php';
    exit;
}

/* ==================================================
   1. معالجة الحفظ (POST)
   ================================================== */
$inv = ['client_id'=>'', 'job_id'=>0, 'inv_date'=>date('Y-m-d'), 'due_date'=>date('Y-m-d'), 'notes'=>'', 'items_json'=>'[]', 'tax'=>0, 'discount'=>0, 'invoice_kind' => 'standard', 'tax_law_key' => app_setting_get($conn, 'tax_default_sales_law', 'vat_2016'), 'taxes_json' => '[]'];
$edit_mode = false;
$salesTaxCatalog = app_tax_catalog($conn, true, 'sales');
$taxLawCatalog = app_tax_law_catalog($conn, true);

if(isset($_GET['id'])){
    $id = intval($_GET['id']);
    $res = $conn->query("SELECT * FROM invoices WHERE id=$id");
    if($res->num_rows > 0) { 
        $inv = $res->fetch_assoc(); 
        $edit_mode = true; 
    }
}

if($_SERVER['REQUEST_METHOD'] == 'POST'){
    if (($edit_mode && !$canInvoiceUpdate) || (!$edit_mode && !$canInvoiceCreate)) {
        http_response_code(403);
        die(app_h(app_tr('غير مصرح بتنفيذ هذه العملية.', 'Not authorized to perform this action.')));
    }
    if (!app_verify_csrf($_POST['_csrf_token'] ?? '')) {
        http_response_code(403);
        die(app_h(app_tr('انتهت صلاحية الجلسة. قم بتحديث الصفحة ثم حاول مرة أخرى.', 'Session expired. Refresh and try again.')));
    }
    $client = (int)($_POST['client_id'] ?? 0);
    $jobId = (int)($_POST['job_id'] ?? 0);
    $date = trim((string)($_POST['inv_date'] ?? ''));
    $due = trim((string)($_POST['due_date'] ?? ''));
    $notes = trim((string)($_POST['notes'] ?? ''));

    if ($client <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $due)) {
        http_response_code(422);
        die(app_h(app_tr('بيانات الفاتورة غير مكتملة أو غير صالحة.', 'Invoice input data is invalid.')));
    }
    $jobBindingValidation = finance_validate_sales_invoice_job_client($conn, $client, $jobId);
    if ($jobBindingValidation !== '') {
        http_response_code(422);
        die(app_h($jobBindingValidation));
    }
    
    $items = [];
    $sub_total = 0;
    if(isset($_POST['item_desc'])){
        for($i=0; $i<count($_POST['item_desc']); $i++){
            $qty = floatval($_POST['item_qty'][$i]);
            $price = floatval($_POST['item_price'][$i]);
            $total = $qty * $price;
            $unitRaw = trim((string)($_POST['item_unit'][$i] ?? ''));
            $unitOther = trim((string)($_POST['item_unit_other'][$i] ?? ''));
            $unit = ($unitRaw === 'other') ? $unitOther : $unitRaw;
            if ($unit !== '') {
                $unit = mb_substr($unit, 0, 50);
            }
            $itemCode = trim((string)($_POST['item_code'][$i] ?? ''));
            $etaUnitType = strtoupper(trim((string)($_POST['item_eta_unit_type'][$i] ?? '')));
            if ($etaUnitType === '') {
                $etaUnitType = 'EA';
            }
            $sub_total += $total;
            $items[] = [
                'desc' => $_POST['item_desc'][$i],
                'qty' => $qty,
                'unit' => $unit,
                'item_code' => mb_substr($itemCode, 0, 120),
                'unit_type' => mb_substr($etaUnitType, 0, 20),
                'price' => $price,
                'total' => $total
            ];
        }
    }
    
    $json = json_encode($items, JSON_UNESCAPED_UNICODE);
    $discount = floatval($_POST['discount']);
    $invoiceKind = (string)($_POST['invoice_kind'] ?? 'standard');
    $taxLawKey = strtolower(trim((string)($_POST['tax_law_key'] ?? app_setting_get($conn, 'tax_default_sales_law', 'vat_2016'))));
    if (!preg_match('/^[a-z0-9_]{2,60}$/', $taxLawKey)) {
        $taxLawKey = app_setting_get($conn, 'tax_default_sales_law', 'vat_2016');
    }
    $selectedTaxes = isset($_POST['tax_keys']) && is_array($_POST['tax_keys']) ? array_map('strval', $_POST['tax_keys']) : [];
    $taxCalc = app_tax_calculate_document($salesTaxCatalog, $invoiceKind, $sub_total, $discount, $selectedTaxes);
    $tax = (float)($taxCalc['tax_total'] ?? 0);
    $grand_total = (float)($taxCalc['grand_total'] ?? (($sub_total - $discount) + $tax));
    $taxesJson = json_encode(($taxCalc['lines'] ?? []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($taxesJson)) {
        $taxesJson = '[]';
    }

    if($edit_mode){
        try {
            if ($jobId > 0) {
                $stmtUpd = $conn->prepare("UPDATE invoices SET client_id = ?, job_id = ?, inv_date = ?, due_date = ?, invoice_kind = ?, tax_law_key = ?, sub_total = ?, tax = ?, tax_total = ?, discount = ?, total_amount = ?, items_json = ?, taxes_json = ?, notes = ? WHERE id = ?");
                $invoiceId = (int)$inv['id'];
                $stmtUpd->bind_param('iissssdddddsssi', $client, $jobId, $date, $due, $invoiceKind, $taxLawKey, $sub_total, $tax, $tax, $discount, $grand_total, $json, $taxesJson, $notes, $invoiceId);
            } else {
                $stmtUpd = $conn->prepare("UPDATE invoices SET client_id = ?, job_id = NULL, inv_date = ?, due_date = ?, invoice_kind = ?, tax_law_key = ?, sub_total = ?, tax = ?, tax_total = ?, discount = ?, total_amount = ?, items_json = ?, taxes_json = ?, notes = ? WHERE id = ?");
                $invoiceId = (int)$inv['id'];
                $stmtUpd->bind_param('issssdddddsssi', $client, $date, $due, $invoiceKind, $taxLawKey, $sub_total, $tax, $tax, $discount, $grand_total, $json, $taxesJson, $notes, $invoiceId);
            }
            $stmtUpd->execute();
            $stmtUpd->close();
            $target_id = (int)$inv['id'];
        } catch (Throwable $e) {
            error_log('edit_invoice update failed: ' . $e->getMessage());
            http_response_code(500);
            die(app_h(app_tr('تعذر تحديث الفاتورة حالياً.', 'Failed to update invoice right now.')));
        }
        $creator = (string)($_SESSION['name'] ?? 'System');
        if (function_exists('app_apply_client_receipt_credit_to_invoice')) {
            app_apply_client_receipt_credit_to_invoice($conn, (int)$target_id, (int)$client, $date, $creator);
        }
    } else {
        try {
            if ($jobId > 0) {
                $stmtIns = $conn->prepare("INSERT INTO invoices (client_id, job_id, inv_date, due_date, invoice_kind, tax_law_key, sub_total, tax, tax_total, discount, total_amount, items_json, taxes_json, notes, paid_amount, remaining_amount, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, 'deferred')");
                $stmtIns->bind_param('iissssdddddsssd', $client, $jobId, $date, $due, $invoiceKind, $taxLawKey, $sub_total, $tax, $tax, $discount, $grand_total, $json, $taxesJson, $notes, $grand_total);
            } else {
                $stmtIns = $conn->prepare("INSERT INTO invoices (client_id, job_id, inv_date, due_date, invoice_kind, tax_law_key, sub_total, tax, tax_total, discount, total_amount, items_json, taxes_json, notes, paid_amount, remaining_amount, status) VALUES (?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, 'deferred')");
                $stmtIns->bind_param('issssdddddsssd', $client, $date, $due, $invoiceKind, $taxLawKey, $sub_total, $tax, $tax, $discount, $grand_total, $json, $taxesJson, $notes, $grand_total);
            }
            $stmtIns->execute();
            $target_id = (int)$stmtIns->insert_id;
            $stmtIns->close();
        } catch (Throwable $e) {
            error_log('edit_invoice insert failed: ' . $e->getMessage());
            http_response_code(500);
            die(app_h(app_tr('تعذر إنشاء الفاتورة حالياً.', 'Failed to create invoice right now.')));
        }
        app_assign_document_number($conn, 'invoices', (int)$target_id, 'invoice_number', 'invoice', $date);
        $creator = (string)($_SESSION['name'] ?? 'System');
        app_apply_client_opening_balance_to_invoice($conn, (int)$target_id, (int)$client, $date, $creator);
        if (function_exists('app_apply_client_receipt_credit_to_invoice')) {
            app_apply_client_receipt_credit_to_invoice($conn, (int)$target_id, (int)$client, $date, $creator);
        }
    }
    
    finance_sync_sales_invoice_status($conn, $target_id);
    $etaRedirectExtra = '';
    if ($etaWorkRuntime && $invoiceKind === 'tax') {
        $etaAutoResult = app_eta_einvoice_queue_or_submit_saved_invoice($conn, (int)$target_id, (int)($_SESSION['user_id'] ?? 0));
        if (!empty($etaAutoResult['ok']) && empty($etaAutoResult['skipped'])) {
            $etaRedirectExtra = '&eta_save=' . urlencode((string)($etaAutoResult['mode'] ?? 'queued'));
            if (!empty($etaAutoResult['deferred'])) {
                $etaRedirectExtra .= '&eta_notice=' . urlencode((string)($etaAutoResult['notice'] ?? 'submit_deferred_until_signing_configured'));
            }
        } elseif (!empty($etaAutoResult['error'])) {
            $etaRedirectExtra = '&eta_error=' . urlencode((string)$etaAutoResult['error']);
        }
    }
    header("Location: print_invoice.php?id=$target_id" . $etaRedirectExtra); exit;
}

$invoiceTaxes = app_tax_decode_lines((string)($inv['taxes_json'] ?? '[]'));
$selectedTaxKeys = [];
foreach ($invoiceTaxes as $taxLine) {
    $taxKey = strtolower(trim((string)($taxLine['key'] ?? '')));
    if ($taxKey !== '' && !in_array($taxKey, $selectedTaxKeys, true)) {
        $selectedTaxKeys[] = $taxKey;
    }
}
if (empty($taxLawCatalog)) {
    $taxLawCatalog = app_tax_default_laws();
}

$itemsDataPayload = json_decode((string)($inv['items_json'] ?? '[]'), true);
if (!is_array($itemsDataPayload)) {
    $itemsDataPayload = [];
}

$jobsList = $conn->query("SELECT id, client_id, job_number, job_name, status FROM job_orders ORDER BY id DESC LIMIT 500");
?>

<style>
    :root {
        --royal-gold: #d4af37;
        --royal-gold-hover: #b8860b;
        --dark-bg: #121212;
        --card-bg: #1e1e1e;
        --input-bg: #2c2c2c;
        --border-color: #3a3a3a;
        --text-main: #e0e0e0;
        --text-muted: #a0a0a0;
        --danger: #ff4d4d;
        --info: #17a2b8;
    }

    body { background-color: var(--dark-bg); color: var(--text-main); font-family: 'Tajawal', sans-serif; }
    
    /* Layout */
    .container { max-width: 1200px; margin: 30px auto; padding: 0 15px; }
    .royal-card { background: var(--card-bg); border: 1px solid var(--border-color); border-radius: 12px; padding: 25px; box-shadow: 0 10px 30px rgba(0,0,0,0.3); }

    /* Header */
    .page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; padding-bottom: 15px; border-bottom: 1px solid var(--border-color); }
    .page-title { color: var(--royal-gold); font-size: 1.8rem; font-weight: 700; margin: 0; }

    /* Grid & Inputs */
    .invoice-meta-grid { 
        display: grid; 
        grid-template-columns: 2fr 1.3fr 1fr 1fr; 
        gap: 22px; 
        align-items: end;
    }
    
    .form-group label { display: block; color: var(--royal-gold); margin-bottom: 8px; font-weight: 600; font-size: 0.9rem; }
    
    .form-control { 
        width: 100%; 
        background: var(--input-bg); 
        border: 1px solid var(--border-color); 
        color: #fff; 
        padding: 12px; 
        border-radius: 6px; 
        transition: 0.3s; 
    }
    .form-control:focus { border-color: var(--royal-gold); outline: none; }

    /* Date Picker Specific Style */
    input[type="date"] {
        cursor: pointer; /* يجعل المؤشر يد عند المرور */
        position: relative;
    }
    /* محاولة توحيد شكل الرزنامة للمتصفحات المختلفة */
    input[type="date"]::-webkit-calendar-picker-indicator {
        background: transparent;
        bottom: 0;
        color: transparent;
        cursor: pointer;
        height: auto;
        left: 0;
        position: absolute;
        right: 0;
        top: 0;
        width: auto;
    }

    /* Table */
    .items-table { width: 100%; border-collapse: separate; border-spacing: 0 5px; }
    .items-table th { text-align: right; color: #888; padding: 10px; border-bottom: 1px solid #444; }
    .items-table td { background: #252525; padding: 5px 10px; border-top: 1px solid #333; border-bottom: 1px solid #333; }
    .items-table td:first-child { border-radius: 0 6px 6px 0; border-right: 1px solid #333; }
    .items-table td:last-child { border-radius: 6px 0 0 6px; border-left: 1px solid #333; text-align: center; }

    .table-input { width: 100%; background: transparent; border: none; color: #fff; padding: 8px; text-align: center; font-size: 1rem; }
    .table-input:focus { background: #333; border-radius: 4px; outline: none; }
    .qty-unit { display: flex; align-items: center; gap: 6px; }
    .qty-unit .qty-input { min-width: 90px; }
    .qty-unit .unit-select { min-width: 110px; }
    .qty-unit .unit-other { min-width: 120px; }

    /* Buttons */
    .btn-royal { background: linear-gradient(45deg, var(--royal-gold), #b8860b); border: none; padding: 10px 25px; color: #000; font-weight: bold; border-radius: 6px; cursor: pointer; }
    .btn-add-row { width: 100%; padding: 12px; background: rgba(255, 255, 255, 0.05); border: 2px dashed var(--border-color); color: var(--text-muted); border-radius: 8px; cursor: pointer; transition: 0.3s; margin-top: 10px; }
    .btn-add-row:hover { border-color: var(--royal-gold); color: var(--royal-gold); }

    /* Action Buttons */
    .actions-cell { display: flex; gap: 5px; justify-content: center; }
    .btn-icon { width: 32px; height: 32px; border: none; border-radius: 4px; cursor: pointer; display: flex; align-items: center; justify-content: center; transition: 0.2s; }
    .btn-del { background: rgba(255, 77, 77, 0.1); color: var(--danger); }
    .btn-del:hover { background: var(--danger); color: #fff; }
    .btn-dup { background: rgba(23, 162, 184, 0.1); color: var(--info); }
    .btn-dup:hover { background: var(--info); color: #fff; }

    /* Totals */
    .totals-section { background: #000; padding: 20px; border-radius: 8px; border: 1px solid #333; }
    .total-row { display: flex; justify-content: space-between; margin-bottom: 10px; color: #ccc; align-items: center; }
    .grand-total { border-top: 1px solid #333; margin-top: 10px; padding-top: 10px; font-size: 1.3rem; color: var(--royal-gold); font-weight: bold; }
    .tax-controls { margin: 14px 0; padding: 14px; background: #111; border: 1px solid #2e2e2e; border-radius: 8px; }
    .tax-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; }
    .tax-checklist { margin-top: 12px; display: grid; gap: 8px; }
    .tax-option { display: flex; justify-content: space-between; gap: 10px; padding: 8px 10px; border: 1px solid #2f2f2f; border-radius: 6px; background: #151515; color: #ddd; }
    .tax-option.disabled { opacity: 0.45; }
    .tax-option input { accent-color: var(--royal-gold); }
    .tax-breakdown { margin-top: 12px; border-top: 1px dashed #333; padding-top: 12px; }
    .tax-breakdown-row { display:flex; justify-content:space-between; gap:10px; color:#cfcfcf; font-size:0.94rem; margin-bottom:8px; }
    .tax-breakdown-row.subtract { color:#ff9f9f; }
    .tax-hint { color: var(--text-muted); font-size: 0.82rem; margin-top: 8px; }
    @media (max-width: 768px) {
        .tax-grid { grid-template-columns: 1fr; }
    }
</style>

<div class="container" dir="rtl">
    <form method="POST" id="invoiceForm">
        <?php echo app_csrf_input(); ?>
        
        <div class="page-header">
            <h2 class="page-title"><i class="fa-solid fa-file-invoice-dollar"></i> <?php echo $edit_mode ? 'تعديل الفاتورة #'.$inv['id'] : 'إنشاء فاتورة جديدة'; ?></h2>
            <button type="submit" class="btn-royal big-save-btn"><i class="fa-solid fa-floppy-disk"></i> حفظ وطباعة</button>
        </div>

        <div class="royal-card invoice-body">
            
            <div class="invoice-meta-grid">
                <div class="form-group">
                    <label><i class="fa-solid fa-user-tie"></i> العميل</label>
                    <select name="client_id" id="client_id" required class="form-control">
                        <option value="">-- اختر العميل --</option>
                        <?php 
                        $c_list = $conn->query("SELECT id, name FROM clients ORDER BY name ASC");
                        while($r=$c_list->fetch_assoc()){
                            $sel = ($r['id'] == $inv['client_id']) ? 'selected' : '';
                            echo "<option value='{$r['id']}' $sel>{$r['name']}</option>"; 
                        }
                        ?>
                    </select>
                </div>

                <div class="form-group">
                    <label><i class="fa-solid fa-briefcase"></i> ربط بأمر تشغيل</label>
                    <select name="job_id" id="job_id" class="form-control">
                        <option value="">-- بدون ربط --</option>
                        <?php if($jobsList): while($jobRow = $jobsList->fetch_assoc()): ?>
                            <option value="<?php echo (int)$jobRow['id']; ?>" data-client-id="<?php echo (int)($jobRow['client_id'] ?? 0); ?>" <?php echo ((int)($inv['job_id'] ?? 0) === (int)$jobRow['id']) ? 'selected' : ''; ?>>
                                <?php
                                $jobNo = trim((string)($jobRow['job_number'] ?? ''));
                                $label = ($jobNo !== '' ? $jobNo : ('JOB#' . (int)$jobRow['id'])) . ' - ' . (string)($jobRow['job_name'] ?? '');
                                echo app_h($label);
                                ?>
                            </option>
                        <?php endwhile; endif; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>تاريخ الفاتورة</label>
                    <input type="date" name="inv_date" value="<?php echo $inv['inv_date']; ?>" class="form-control" onclick="try{this.showPicker()}catch(e){}">
                </div>
                
                <div class="form-group">
                    <label>تاريخ الاستحقاق</label>
                    <input type="date" name="due_date" value="<?php echo $inv['due_date']; ?>" class="form-control" onclick="try{this.showPicker()}catch(e){}">
                </div>
            </div>

            <hr style="border-color:#333; margin: 30px 0;">

            <table class="items-table">
                <thead>
                    <tr>
                        <th width="28%">البيان</th>
                        <th width="16%" style="text-align:center;">الكمية / الوحدة</th>
                        <?php if ($etaWorkRuntime): ?>
                        <th width="16%" style="text-align:center;">ETA Code</th>
                        <th width="12%" style="text-align:center;">ETA Unit</th>
                        <?php endif; ?>
                        <th width="12%" style="text-align:center;">السعر</th>
                        <th width="12%" style="text-align:center;">الإجمالي</th>
                        <th width="8%">إجراءات</th>
                    </tr>
                </thead>
                <tbody id="items_container"></tbody>
            </table>
            
            <button type="button" onclick="addItem()" class="btn-add-row">+ إضافة بند جديد</button>

            <div class="invoice-footer" style="display:flex; flex-wrap:wrap; gap:30px; margin-top:30px;">
                <div style="flex:2; min-width:300px;">
                    <label style="color:var(--royal-gold);">ملاحظات</label>
                    <textarea name="notes" class="form-control" style="height:120px; margin-top:5px;"><?php echo $inv['notes']; ?></textarea>
                </div>
                <div class="totals-section" style="flex:1; min-width:250px;">
                    <div class="total-row"><span>المجموع</span> <span id="sub_total">0.00</span></div>
                    <div class="total-row">
                        <span>خصم</span> 
                        <input type="number" name="discount" id="discount" value="<?php echo $inv['discount']; ?>" oninput="calcTotals()" style="width:80px; background:#222; border:1px solid #444; color:#fff; text-align:center; border-radius:4px;">
                    </div>
                    <div class="tax-controls">
                        <div class="tax-grid">
                            <div>
                                <label style="color:var(--royal-gold); margin-bottom:6px;">نوع المستند</label>
                                <select name="invoice_kind" id="invoice_kind" class="form-control" onchange="calcTotals()">
                                    <option value="standard" <?php echo ((string)($inv['invoice_kind'] ?? 'standard') === 'standard') ? 'selected' : ''; ?>>فاتورة عادية</option>
                                    <option value="tax" <?php echo ((string)($inv['invoice_kind'] ?? '') === 'tax') ? 'selected' : ''; ?>>فاتورة ضريبية</option>
                                </select>
                            </div>
                            <div>
                                <label style="color:var(--royal-gold); margin-bottom:6px;">القانون الضريبي</label>
                                <select name="tax_law_key" id="tax_law_key" class="form-control">
                                    <?php foreach ($taxLawCatalog as $lawRow): ?>
                                        <option value="<?php echo app_h((string)$lawRow['key']); ?>" <?php echo ((string)($inv['tax_law_key'] ?? '') === (string)$lawRow['key']) ? 'selected' : ''; ?>>
                                            <?php echo app_h((string)$lawRow['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="tax-checklist" id="tax_checklist">
                            <?php foreach ($salesTaxCatalog as $taxType): ?>
                                <?php $taxKey = (string)($taxType['key'] ?? ''); ?>
                                <label class="tax-option<?php echo app_tax_is_tax_invoice((string)($inv['invoice_kind'] ?? 'standard')) ? '' : ' disabled'; ?>">
                                    <span>
                                        <input type="checkbox" class="js-tax-key" name="tax_keys[]" value="<?php echo app_h($taxKey); ?>" onchange="calcTotals()" <?php echo in_array($taxKey, $selectedTaxKeys, true) ? 'checked' : ''; ?>>
                                        <?php echo app_h((string)($taxType['name'] ?? $taxKey)); ?>
                                    </span>
                                    <span><?php echo number_format((float)($taxType['rate'] ?? 0), 2); ?>%</span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                        <input type="hidden" name="tax_amount" id="tax_amount" value="<?php echo (float)($inv['tax'] ?? 0); ?>">
                        <div class="tax-breakdown" id="tax_breakdown"></div>
                        <div class="tax-hint">لا يتم احتساب أي ضريبة إلا عند اختيار "فاتورة ضريبية".</div>
                    </div>
                    <div class="total-row grand-total">
                        <span>الإجمالي النهائي</span> <span id="grand_total">0.00</span>
                    </div>
                </div>
            </div>
        </div>
    </form>
</div>

<script>
let itemsData = <?php echo json_encode(array_values($itemsDataPayload), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
const clientSelect = document.getElementById('client_id');
const jobSelect = document.getElementById('job_id');
const invoiceKindEl = document.getElementById('invoice_kind');
const taxChecklistEl = document.getElementById('tax_checklist');
const taxBreakdownEl = document.getElementById('tax_breakdown');
const taxAmountEl = document.getElementById('tax_amount');
const availableTaxes = <?php echo json_encode(array_values($salesTaxCatalog), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
const etaItemCatalog = <?php echo json_encode(array_values($etaItemCatalog), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;

function buildEtaCodeOptions(selectedCode = '') {
    const selected = String(selectedCode || '').trim();
    let html = `<option value="">اختر من أكواد ETA المتاحة</option>`;
    etaItemCatalog.forEach(function (row) {
        const code = String(row.eta || '').trim();
        if (!code) return;
        const local = String(row.local || '').trim();
        const type = String(row.code_type || 'EGS').trim().toUpperCase();
        const label = `${local} - ${code} [${type}]`;
        html += `<option value="${code.replace(/"/g, '&quot;')}" ${selected===code?'selected':''}>${label}</option>`;
    });
    return html;
}

function syncEtaCodeChooser(selectEl) {
    const tr = selectEl.closest('tr');
    if (!tr) return;
    const codeInput = tr.querySelector('[name="item_code[]"]');
    if (codeInput) {
        codeInput.value = selectEl.value || '';
    }
}

function filterJobsByClient() {
    if (!clientSelect || !jobSelect) return;
    const selectedClientId = String(clientSelect.value || '');
    let currentStillVisible = false;
    Array.from(jobSelect.options).forEach((option, index) => {
        if (index === 0) {
            option.hidden = false;
            return;
        }
        const optionClientId = String(option.dataset.clientId || '');
        const visible = selectedClientId === '' || optionClientId === selectedClientId;
        option.hidden = !visible;
        if (visible && option.value === jobSelect.value) {
            currentStillVisible = true;
        }
    });
    if (!currentStillVisible && jobSelect.value !== '') {
        jobSelect.value = '';
    }
}

if (clientSelect && jobSelect) {
    clientSelect.addEventListener('change', filterJobsByClient);
    filterJobsByClient();
}

function addItem(desc='', qty=1, price=0, unit='', itemCode='', etaUnitType='EA') {
    let tbody = document.getElementById('items_container');
    let tr = document.createElement('tr');
    const unitOptions = [
        'كيلو','طن','علبة','كرتونة','كيس','ليبل','قطعة','متر','رول','باكيت','بالة','زجاجة','لوح','صندوق','عبوة'
    ];
    const unitIsKnown = unitOptions.includes(unit);
    const unitSelectValue = unitIsKnown ? unit : (unit ? 'other' : '');
    const unitOtherValue = unitIsKnown ? '' : (unit || '');
    const unitOptionHtml = unitOptions.map(u => `<option value="${u}" ${unitSelectValue===u?'selected':''}>${u}</option>`).join('');
    tr.innerHTML = `
        <td><input type="text" name="item_desc[]" value="${desc}" placeholder="وصف الصنف" class="table-input" style="text-align:right;"></td>
        <td>
            <div class="qty-unit">
                <input type="number" name="item_qty[]" value="${qty}" oninput="calcRow(this)" step="0.01" class="table-input qty-input">
                <select name="item_unit[]" class="table-input unit-select" onchange="toggleUnitOther(this)">
                    <option value="">وحدة</option>
                    ${unitOptionHtml}
                    <option value="other" ${unitSelectValue==='other'?'selected':''}>أخرى</option>
                </select>
                <input type="text" name="item_unit_other[]" value="${unitOtherValue}" class="table-input unit-other" placeholder="أخرى" ${unitSelectValue==='other'?'':'style=\"display:none;\"'}>
            </div>
        </td>
        ${<?php echo $etaWorkRuntime ? 'true' : 'false'; ?> ? `
        <td>
            <div style="display:grid;gap:6px;">
                <select class="table-input js-eta-code-picker" onchange="syncEtaCodeChooser(this)">
                    ${buildEtaCodeOptions(itemCode)}
                </select>
                <input type="text" name="item_code[]" value="${itemCode}" placeholder="EGS / GS1 / SKU" class="table-input">
            </div>
        </td>
        <td>
            <select name="item_eta_unit_type[]" class="table-input">
                <option value="EA" ${etaUnitType==='EA'?'selected':''}>EA</option>
                <option value="KG" ${etaUnitType==='KG'?'selected':''}>KG</option>
                <option value="LTR" ${etaUnitType==='LTR'?'selected':''}>LTR</option>
                <option value="MTR" ${etaUnitType==='MTR'?'selected':''}>MTR</option>
                <option value="BOX" ${etaUnitType==='BOX'?'selected':''}>BOX</option>
                <option value="PK" ${etaUnitType==='PK'?'selected':''}>PK</option>
            </select>
        </td>` : ``}
        <td><input type="number" name="item_price[]" value="${price}" oninput="calcRow(this)" step="0.01" class="table-input"></td>
        <td><input type="text" class="table-input row-total" readonly value="${(qty*price).toFixed(2)}" style="color:var(--royal-gold);"></td>
        <td>
            <div class="actions-cell">
                <button type="button" onclick="duplicateRow(this)" title="تكرار الصف" class="btn-icon btn-dup"><i class="fa-solid fa-copy"></i></button>
                <button type="button" onclick="removeRow(this)" title="حذف الصف" class="btn-icon btn-del"><i class="fa-solid fa-trash"></i></button>
            </div>
        </td>
    `;
    tbody.appendChild(tr);
    calcTotals();
}

function duplicateRow(btn) {
    let tr = btn.closest('tr');
    let desc = tr.querySelector('[name="item_desc[]"]').value;
    let qty = tr.querySelector('[name="item_qty[]"]').value;
    let price = tr.querySelector('[name="item_price[]"]').value;
    let itemCodeEl = tr.querySelector('[name="item_code[]"]');
    let etaUnitTypeEl = tr.querySelector('[name="item_eta_unit_type[]"]');
    let itemCode = itemCodeEl ? itemCodeEl.value : '';
    let etaUnitType = etaUnitTypeEl ? etaUnitTypeEl.value : 'EA';
    let unitSelect = tr.querySelector('[name="item_unit[]"]');
    let unitOther = tr.querySelector('[name="item_unit_other[]"]');
    let unit = unitSelect ? unitSelect.value : '';
    if (unit === 'other' && unitOther) unit = unitOther.value || '';
    addItem(desc, qty, price, unit, itemCode, etaUnitType);
}

function removeRow(btn) {
    if(confirm('هل تريد حذف هذا البند؟')) {
        btn.closest('tr').remove();
        calcTotals();
    }
}

function calcRow(el) {
    let tr = el.closest('tr');
    let qty = parseFloat(tr.querySelector('[name="item_qty[]"]').value) || 0;
    let price = parseFloat(tr.querySelector('[name="item_price[]"]').value) || 0;
    tr.querySelector('.row-total').value = (qty * price).toFixed(2);
    calcTotals();
}

function toggleUnitOther(selectEl) {
    const tr = selectEl.closest('tr');
    if (!tr) return;
    const otherInput = tr.querySelector('.unit-other');
    if (!otherInput) return;
    if (selectEl.value === 'other') {
        otherInput.style.display = '';
        otherInput.focus();
    } else {
        otherInput.style.display = 'none';
        otherInput.value = '';
    }
}

function calcTotals() {
    let sub = 0;
    document.querySelectorAll('.row-total').forEach(e => sub += parseFloat(e.value) || 0);
    let disc = parseFloat(document.getElementById('discount').value) || 0;
    let taxable = Math.max(0, sub - disc);
    let tax = 0;
    const isTaxInvoice = invoiceKindEl && invoiceKindEl.value === 'tax';
    const breakdown = [];
    const selectedKeys = Array.from(document.querySelectorAll('.js-tax-key:checked')).map(el => String(el.value || '').trim().toLowerCase());

    document.querySelectorAll('.tax-option').forEach(el => {
        el.classList.toggle('disabled', !isTaxInvoice);
    });
    document.querySelectorAll('.js-tax-key').forEach(el => {
        el.disabled = !isTaxInvoice;
    });
    document.querySelectorAll('.js-eta-code-picker').forEach(el => {
        el.disabled = !isTaxInvoice;
    });

    if (isTaxInvoice) {
        availableTaxes.forEach(taxType => {
            const key = String(taxType.key || '').trim().toLowerCase();
            if (!selectedKeys.includes(key)) {
                return;
            }
            const baseType = String(taxType.base || 'net_after_discount');
            const baseAmount = baseType === 'subtotal' ? sub : taxable;
            const rate = parseFloat(taxType.rate || 0);
            const amount = (baseAmount * rate) / 100;
            const mode = String(taxType.mode || 'add');
            const signedAmount = mode === 'subtract' ? (-1 * amount) : amount;
            tax += signedAmount;
            breakdown.push({
                name: String(taxType.name || key),
                rate: rate,
                mode: mode,
                amount: amount,
                signedAmount: signedAmount
            });
        });
    }

    const finalTotal = Math.max(0, taxable + tax);
    document.getElementById('sub_total').innerText = sub.toFixed(2);
    taxAmountEl.value = tax.toFixed(2);
    document.getElementById('grand_total').innerText = finalTotal.toFixed(2);

    if (taxBreakdownEl) {
        if (!isTaxInvoice) {
            taxBreakdownEl.innerHTML = '<div class="tax-breakdown-row"><span>إجمالي الضرائب</span><span>0.00</span></div>';
            return;
        }
        let html = '';
        breakdown.forEach(line => {
            const sign = line.mode === 'subtract' ? '-' : '+';
            html += `<div class="tax-breakdown-row ${line.mode === 'subtract' ? 'subtract' : ''}"><span>${line.name} (${line.rate.toFixed(2)}%)</span><span>${sign}${line.amount.toFixed(2)}</span></div>`;
        });
        html += `<div class="tax-breakdown-row" style="font-weight:700; color:var(--royal-gold); margin-bottom:0;"><span>إجمالي الضرائب</span><span>${tax.toFixed(2)}</span></div>`;
        taxBreakdownEl.innerHTML = html;
    }
}

window.onload = () => {
    if(itemsData.length > 0) { itemsData.forEach(i => addItem(i.desc, i.qty, i.price, i.unit || '', i.item_code || i.sku || '', i.unit_type || 'EA')); } else { addItem(); }
    calcTotals();
};
</script>
<?php include 'footer.php'; ob_end_flush(); ?>
