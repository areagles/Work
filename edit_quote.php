<?php
// edit_quote.php - (Royal Edition V2.0 - Fixed Design & Decimal Support)
ob_start();
require 'auth.php'; 
require 'config.php'; 
require 'header.php';
require_once __DIR__ . '/quote_growth_runtime.php';

if(!isset($_GET['id'])) { header("Location: quotes.php"); exit; }
$id = intval($_GET['id']);

app_ensure_quotes_schema($conn);
app_quote_growth_ensure_schema($conn);
$quoteTaxCatalog = app_tax_catalog($conn, true, 'quotes');
$quoteCurrencyOptions = app_quote_currency_options($conn);
$defaultQuoteCurrency = app_quote_default_currency($conn);
$quoteTaxLaws = app_tax_law_catalog($conn, true);
if (empty($quoteTaxLaws)) {
    $quoteTaxLaws = app_tax_default_laws();
}

// جلب البيانات
$quote = $conn->query("SELECT * FROM quotes WHERE id=$id")->fetch_assoc();
$items = $conn->query("SELECT * FROM quote_items WHERE quote_id=$id");
$quoteTaxLines = app_tax_decode_lines((string)($quote['taxes_json'] ?? '[]'));
$currentQuoteCurrency = app_quote_normalize_currency_code($conn, (string)($quote['currency_code'] ?? $defaultQuoteCurrency));
$currentQuoteRateToBase = max(0.00000001, (float)($quote['exchange_rate_to_base'] ?? ($quoteCurrencyOptions[$currentQuoteCurrency]['rate_to_base'] ?? 1)));
$selectedTaxKeys = [];
foreach ($quoteTaxLines as $taxLine) {
    $taxKey = strtolower(trim((string)($taxLine['key'] ?? '')));
    if ($taxKey !== '' && !in_array($taxKey, $selectedTaxKeys, true)) {
        $selectedTaxKeys[] = $taxKey;
    }
}

// معالجة التحديث
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (!app_verify_csrf($_POST['_csrf_token'] ?? '')) {
        http_response_code(403);
        die('Invalid CSRF token');
    }
    $client_id = intval($_POST['client_id']);
    $date = $_POST['date'];
    $valid = $_POST['valid_until'];
    $notes = $conn->real_escape_string($_POST['notes']);
    $expectedCloseDate = trim((string)($_POST['expected_close_date'] ?? ''));
    $depositRequiredAmount = max(0, (float)($_POST['deposit_required_amount'] ?? 0));
    if ($expectedCloseDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $expectedCloseDate)) {
        $expectedCloseDate = '';
    }
    $quoteKind = (string)($_POST['quote_kind'] ?? 'standard');
    $taxLawKey = strtolower(trim((string)($_POST['tax_law_key'] ?? app_setting_get($conn, 'tax_default_quote_law', 'vat_2016'))));
    if (!preg_match('/^[a-z0-9_]{2,60}$/', $taxLawKey)) {
        $taxLawKey = app_setting_get($conn, 'tax_default_quote_law', 'vat_2016');
    }
    $postedTaxKeys = isset($_POST['tax_keys']) && is_array($_POST['tax_keys']) ? array_map('strval', $_POST['tax_keys']) : [];
    $currencyCode = app_quote_normalize_currency_code($conn, (string)($_POST['currency_code'] ?? $currentQuoteCurrency));
    $currencyMeta = app_quote_currency_meta($conn, $currencyCode);
    $currencyLabel = (string)($currencyMeta['label_ar'] ?? $currencyCode);
    $baseCurrencyCode = (string)($currencyMeta['base_currency'] ?? app_quote_default_currency($conn));
    $exchangeRateToBase = max(0, (float)($currencyMeta['rate_to_base'] ?? 1));

    // 1. حساب الإجمالي الجديد
    $sub_total = 0;
    $itemsJsonRows = [];
    if(isset($_POST['item_name'])){
        for($i=0; $i<count($_POST['item_name']); $i++){
            $qtyVal = floatval($_POST['qty'][$i]);
            $priceVal = floatval($_POST['price'][$i]);
            $lineTotal = $qtyVal * $priceVal;
            $sub_total += $lineTotal;
            $unitRaw = trim((string)($_POST['unit'][$i] ?? ''));
            $unitOther = trim((string)($_POST['unit_other'][$i] ?? ''));
            $unitVal = ($unitRaw === 'other') ? $unitOther : $unitRaw;
            $itemsJsonRows[] = [
                'desc' => trim((string)($_POST['item_name'][$i] ?? '')),
                'description' => trim((string)($_POST['item_description'][$i] ?? '')),
                'qty' => $qtyVal,
                'unit' => $unitVal,
                'price' => $priceVal,
                'total' => $lineTotal,
            ];
        }
    }
    $taxCalc = app_tax_calculate_document($quoteTaxCatalog, $quoteKind, $sub_total, 0, $postedTaxKeys);
    $taxTotal = (float)($taxCalc['tax_total'] ?? 0);
    $grand_total = (float)($taxCalc['grand_total'] ?? $sub_total);
    $baseTotalAmount = $exchangeRateToBase > 0 ? round($grand_total * $exchangeRateToBase, 2) : $grand_total;
    $internalApprovalStatus = (string)($quote['internal_approval_status'] ?? 'not_required');
    if ($internalApprovalStatus !== 'approved') {
        $internalApprovalStatus = app_quote_internal_status_for_total($conn, $baseTotalAmount);
    }
    $fulfillmentStage = trim((string)($_POST['fulfillment_stage'] ?? ($quote['fulfillment_stage'] ?? 'client_review')));
    $allowedStages = ['client_review', 'awaiting_deposit', 'ready_to_start', 'invoiced'];
    if (!in_array($fulfillmentStage, $allowedStages, true)) {
        $fulfillmentStage = $depositRequiredAmount > 0 ? 'awaiting_deposit' : 'client_review';
    }
    $taxesJson = json_encode(($taxCalc['lines'] ?? []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($taxesJson)) {
        $taxesJson = '[]';
    }
    $itemsJson = json_encode($itemsJsonRows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($itemsJson)) {
        $itemsJson = '[]';
    }

    // 2. تحديث الجدول الرئيسي
    $safeCurrencyCode = $conn->real_escape_string($currencyCode);
    $safeCurrencyLabel = $conn->real_escape_string($currencyLabel);
    $safeBaseCurrencyCode = $conn->real_escape_string($baseCurrencyCode);
    $conn->query("UPDATE quotes SET client_id='$client_id', created_at='$date', valid_until='$valid', quote_kind='$quoteKind', tax_law_key='$taxLawKey', total_amount='$grand_total', currency_code='$safeCurrencyCode', currency_label='$safeCurrencyLabel', base_currency_code='$safeBaseCurrencyCode', exchange_rate_to_base='$exchangeRateToBase', base_total_amount='$baseTotalAmount', tax_total='$taxTotal', taxes_json='" . $conn->real_escape_string($taxesJson) . "', items_json='" . $conn->real_escape_string($itemsJson) . "', internal_approval_status='" . $conn->real_escape_string($internalApprovalStatus) . "', fulfillment_stage='" . $conn->real_escape_string($fulfillmentStage) . "', deposit_required_amount='$depositRequiredAmount', expected_close_date=" . ($expectedCloseDate !== '' ? "'" . $conn->real_escape_string($expectedCloseDate) . "'" : "NULL") . ", notes='$notes' WHERE id=$id");

    // 3. حذف البنود القديمة بالكامل
    $conn->query("DELETE FROM quote_items WHERE quote_id=$id");

    // 4. إدراج البنود الجديدة
    if(isset($_POST['item_name'])){
        for($i=0; $i<count($_POST['item_name']); $i++){
            $iname = $conn->real_escape_string($_POST['item_name'][$i]);
            $idescRaw = mb_substr(trim((string)($_POST['item_description'][$i] ?? '')), 0, 2000);
            $idesc = $conn->real_escape_string($idescRaw);
            $iqty = floatval($_POST['qty'][$i]);
            $iprice = floatval($_POST['price'][$i]);
            $unitRaw = trim((string)($_POST['unit'][$i] ?? ''));
            $unitOther = trim((string)($_POST['unit_other'][$i] ?? ''));
            $unit = ($unitRaw === 'other') ? $unitOther : $unitRaw;
            if ($unit !== '') {
                $unit = $conn->real_escape_string(mb_substr($unit, 0, 50));
            }
            $itotal = $iqty * $iprice;
            
            $conn->query("INSERT INTO quote_items (quote_id, item_name, description, quantity, unit, price, total) VALUES ($id, '$iname', '$idesc', '$iqty', '$unit', '$iprice', '$itotal')");
        }
    }

    app_quote_growth_upload_attachments($conn, $id);
    app_quote_history_add($conn, $id, 'quote_updated', 'تم تعديل عرض السعر', ['currency' => $currencyCode, 'total' => $grand_total, 'internal_approval_status' => $internalApprovalStatus]);
    header("Location: quotes.php?msg=updated"); exit;
}
?>

<style>
    :root { --gold: #d4af37; --dark: #050505; --panel: #151515; --border: #333; }
    
    .royal-container { max-width: 1200px; margin: 30px auto; padding: 0 20px; }
    
    .royal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; border-bottom: 2px solid var(--gold); padding-bottom: 15px; }
    .royal-header h2 { color: var(--gold); margin: 0; font-family: 'Cairo', sans-serif; font-weight: 700; }
    
    .royal-card { background: var(--panel); padding: 25px; border-radius: 15px; border: 1px solid var(--border); box-shadow: 0 10px 30px rgba(0,0,0,0.5); }
    
    .form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin-bottom: 30px; }
    
    label { color: #888; font-size: 0.9rem; margin-bottom: 8px; display: block; }
    .royal-input { width: 100%; background: #0a0a0a; border: 1px solid #444; color: #fff; padding: 12px; border-radius: 8px; font-family: 'Cairo'; outline: none; transition: 0.3s; }
    .royal-input:focus { border-color: var(--gold); box-shadow: 0 0 10px rgba(212, 175, 55, 0.2); }
    
    /* Table Styling */
    .table-responsive { overflow-x: auto; background: #0a0a0a; border-radius: 10px; border: 1px solid #333; padding: 5px; }
    .royal-table { width: 100%; border-collapse: collapse; }
    .royal-table th { color: var(--gold); text-align: right; padding: 15px; border-bottom: 1px solid #333; font-size: 0.9rem; }
    .royal-table td { padding: 10px; border-bottom: 1px solid #222; vertical-align: middle; }
    
    /* Inputs inside table */
    .table-input { width: 100%; background: transparent; border: none; border-bottom: 1px solid #333; color: #eee; padding: 8px; text-align: center; font-weight: bold; }
    .table-input:focus { border-bottom-color: var(--gold); outline: none; }
    .table-input.text-start { text-align: right; }
    .row-actions { display:flex; align-items:center; justify-content:center; gap:12px; }
    
    /* Buttons */
    .btn-royal { background: linear-gradient(45deg, var(--gold), #b8860b); color: #000; padding: 12px 30px; border: none; border-radius: 8px; font-weight: bold; cursor: pointer; display: flex; align-items: center; gap: 10px; font-size: 1rem; transition: 0.3s; }
    .btn-royal:hover { transform: translateY(-2px); box-shadow: 0 5px 15px rgba(212, 175, 55, 0.4); }
    
    .btn-add-row { background: #333; color: #fff; padding: 8px 20px; border-radius: 20px; border: 1px solid #555; cursor: pointer; font-size: 0.85rem; margin-top: 15px; display: inline-block; }
    .btn-add-row:hover { border-color: var(--gold); color: var(--gold); }

    .del-row { color: #e74c3c; cursor: pointer; font-size: 1.2rem; transition: 0.2s; }
    .del-row:hover { transform: scale(1.2); }

    .total-display { font-size: 1.5rem; color: var(--gold); font-weight: bold; text-align: left; margin-top: 20px; }
    .currency-orbit { display:grid; grid-template-columns:1fr auto; gap:10px; align-items:center; background:#0d0d0d; border:1px solid rgba(212,175,55,.3); border-radius:12px; padding:10px; }
    .currency-badge { color:var(--gold); font-weight:900; white-space:nowrap; }
    .currency-hint { grid-column:1/-1; color:#8f8f8f; font-size:.78rem; line-height:1.6; }
    .tax-panel { margin-top: 22px; padding: 16px; background: #0d0d0d; border: 1px solid #2d2d2d; border-radius: 12px; }
    .tax-grid { display:grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap:14px; }
    .tax-checks { display:grid; gap:8px; margin-top:12px; }
    .tax-option { display:flex; justify-content:space-between; gap:10px; padding:10px 12px; border:1px solid #2d2d2d; border-radius:8px; background:#141414; }
    .tax-option.disabled { opacity:.45; }
    .tax-option input { accent-color: var(--gold); }
    .tax-breakdown { margin-top:12px; border-top:1px dashed #333; padding-top:12px; }
    .tax-line { display:flex; justify-content:space-between; gap:10px; margin-bottom:8px; color:#d6d6d6; }
    .tax-line.subtract { color:#ffaaaa; }
</style>

<div class="royal-container">
    <form method="POST" enctype="multipart/form-data">
        <?php echo app_csrf_input(); ?>
        <div class="royal-header">
            <h2><i class="fa-solid fa-file-pen"></i> تعديل عرض السعر #<?php echo $id; ?></h2>
            <button type="submit" class="btn-royal"><i class="fa-solid fa-floppy-disk"></i> حفظ التعديلات</button>
        </div>

        <div class="royal-card">
            <div class="form-grid">
                <div>
                    <label>العميل</label>
                    <select name="client_id" class="royal-input" required>
                        <?php 
                        $cli = $conn->query("SELECT id, name FROM clients");
                        while($c = $cli->fetch_assoc()){
                            $sel = ($c['id'] == $quote['client_id']) ? 'selected' : '';
                            echo "<option value='{$c['id']}' $sel>{$c['name']}</option>";
                        }
                        ?>
                    </select>
                </div>
                <div>
                    <label>تاريخ العرض</label>
                    <input type="date" name="date" class="royal-input" value="<?php echo date('Y-m-d', strtotime($quote['created_at'])); ?>" required>
                </div>
                <div>
                    <label>صالح حتى</label>
                    <input type="date" name="valid_until" class="royal-input" value="<?php echo $quote['valid_until']; ?>" required>
                </div>
                <div>
                    <label>تاريخ متوقع للإغلاق</label>
                    <input type="date" name="expected_close_date" class="royal-input" value="<?php echo app_h((string)($quote['expected_close_date'] ?? '')); ?>">
                </div>
                <div>
                    <label>مرحلة المتابعة</label>
                    <select name="fulfillment_stage" class="royal-input">
                        <?php $stage = (string)($quote['fulfillment_stage'] ?? 'client_review'); ?>
                        <option value="client_review" <?php echo $stage === 'client_review' ? 'selected' : ''; ?>>مراجعة العميل</option>
                        <option value="awaiting_deposit" <?php echo $stage === 'awaiting_deposit' ? 'selected' : ''; ?>>بانتظار دفعة مقدمة</option>
                        <option value="ready_to_start" <?php echo $stage === 'ready_to_start' ? 'selected' : ''; ?>>جاهز للتنفيذ</option>
                        <option value="invoiced" <?php echo $stage === 'invoiced' ? 'selected' : ''; ?>>تمت الفوترة</option>
                    </select>
                </div>
                <div>
                    <label>دفعة مقدمة مطلوبة</label>
                    <input type="number" name="deposit_required_amount" class="royal-input" value="<?php echo app_h((string)($quote['deposit_required_amount'] ?? '0')); ?>" step="0.01" min="0">
                </div>
                <div>
                    <label>عملة العرض</label>
                    <div class="currency-orbit">
                        <select name="currency_code" id="quote_currency" class="royal-input" onchange="updateCurrencyUI(true)">
                            <?php foreach ($quoteCurrencyOptions as $currencyCode => $currencyMeta): ?>
                                <option value="<?php echo app_h($currencyCode); ?>" data-rate="<?php echo app_h((string)($currencyMeta['rate_to_base'] ?? 1)); ?>" data-base="<?php echo app_h((string)($currencyMeta['base_currency'] ?? $defaultQuoteCurrency)); ?>" <?php echo $currencyCode === $currentQuoteCurrency ? 'selected' : ''; ?>>
                                    <?php echo app_h($currencyCode . ' - ' . (string)($currencyMeta['label_ar'] ?? $currencyCode)); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <span class="currency-badge" id="currency_badge"><?php echo app_h($currentQuoteCurrency); ?></span>
                        <div class="currency-hint" id="currency_hint">يتم حفظ سعر التحويل مع العرض.</div>
                    </div>
                </div>
            </div>
            <div class="tax-panel">
                <div class="tax-grid">
                    <div>
                        <label>نوع العرض</label>
                        <select name="quote_kind" id="quote_kind" class="royal-input" onchange="calcTotal()">
                            <option value="standard" <?php echo ((string)($quote['quote_kind'] ?? 'standard') === 'standard') ? 'selected' : ''; ?>>عرض سعر عادي</option>
                            <option value="tax" <?php echo ((string)($quote['quote_kind'] ?? '') === 'tax') ? 'selected' : ''; ?>>عرض سعر ضريبي</option>
                        </select>
                    </div>
                    <div>
                        <label>القانون الضريبي</label>
                        <select name="tax_law_key" id="tax_law_key" class="royal-input">
                            <?php foreach ($quoteTaxLaws as $lawRow): ?>
                                <option value="<?php echo app_h((string)$lawRow['key']); ?>" <?php echo ((string)($quote['tax_law_key'] ?? '') === (string)$lawRow['key']) ? 'selected' : ''; ?>>
                                    <?php echo app_h((string)$lawRow['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="tax-checks" id="tax_checks">
                    <?php foreach ($quoteTaxCatalog as $taxType): ?>
                        <?php $taxKey = (string)($taxType['key'] ?? ''); ?>
                        <label class="tax-option<?php echo ((string)($quote['quote_kind'] ?? 'standard') === 'tax') ? '' : ' disabled'; ?>">
                            <span>
                                <input type="checkbox" class="js-tax-key" name="tax_keys[]" value="<?php echo app_h($taxKey); ?>" onchange="calcTotal()" <?php echo in_array($taxKey, $selectedTaxKeys, true) ? 'checked' : ''; ?>>
                                <?php echo app_h((string)$taxType['name']); ?>
                            </span>
                            <span><?php echo number_format((float)($taxType['rate'] ?? 0), 2); ?>%</span>
                        </label>
                    <?php endforeach; ?>
                </div>
                <div class="tax-breakdown" id="tax_breakdown"></div>
            </div>

            <div class="table-responsive">
                <table class="royal-table">
                    <thead>
                        <tr>
                            <th width="40%">البيان / الصنف</th>
                            <th width="20%" style="text-align:center">الكمية / الوحدة</th>
                            <th width="20%" style="text-align:center">السعر</th>
                            <th width="20%" style="text-align:center">الإجمالي</th>
                            <th width="5%"></th>
                        </tr>
                    </thead>
                    <tbody id="items_container">
                        <?php while($item = $items->fetch_assoc()): ?>
                        <?php
                            $unitVal = trim((string)($item['unit'] ?? ''));
                            $unitOptions = ['كيلو','طن','علبة','كرتونة','كيس','ليبل','قطعة','متر','رول','باكيت','بالة','زجاجة','لوح','صندوق','عبوة'];
                            $unitIsKnown = in_array($unitVal, $unitOptions, true);
                            $unitSelectValue = $unitIsKnown ? $unitVal : ($unitVal !== '' ? 'other' : '');
                            $unitOtherValue = $unitIsKnown ? '' : $unitVal;
                        ?>
                        <tr>
                            <td>
                                <input type="text" name="item_name[]" value="<?php echo htmlspecialchars($item['item_name']); ?>" required class="table-input text-start" placeholder="اسم المنتج...">
                                <textarea name="item_description[]" class="table-input text-start item-description-input" rows="2" placeholder="وصف تفصيلي اختياري للبند..." style="resize:vertical; min-height:46px;"><?php echo app_h((string)($item['description'] ?? '')); ?></textarea>
                            </td>
                            <td>
                                <div class="qty-unit">
                                    <input type="number" step="0.01" name="qty[]" value="<?php echo $item['quantity']; ?>" class="table-input qty-input" oninput="calc(this)">
                                    <select name="unit[]" class="table-input unit-select" onchange="toggleUnitOther(this)">
                                        <option value="">وحدة</option>
                                        <?php foreach ($unitOptions as $u): ?>
                                            <option value="<?php echo app_h($u); ?>" <?php echo ($unitSelectValue === $u) ? 'selected' : ''; ?>><?php echo app_h($u); ?></option>
                                        <?php endforeach; ?>
                                        <option value="other" <?php echo ($unitSelectValue === 'other') ? 'selected' : ''; ?>>أخرى</option>
                                    </select>
                                    <input type="text" name="unit_other[]" class="table-input unit-other" placeholder="أخرى" value="<?php echo app_h($unitOtherValue); ?>" <?php echo ($unitSelectValue === 'other') ? '' : 'style="display:none;"'; ?>>
                                </div>
                            </td>
                            <td><input type="number" step="0.01" name="price[]" value="<?php echo $item['price']; ?>" class="table-input" oninput="calc(this)"></td>
                            <td><input type="text" readonly class="table-input row-total" value="<?php echo $item['total']; ?>" style="color:var(--gold);"></td>
                            <td style="text-align:center;">
                                <div class="row-actions">
                                    <i class="fa-solid fa-copy" onclick="duplicateRow(this)" title="تكرار البند"></i>
                                    <i class="fa-solid fa-xmark del-row" onclick="removeRow(this)" title="حذف البند"></i>
                                </div>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>

            <button type="button" onclick="addRow()" class="btn-add-row"><i class="fa-solid fa-plus"></i> إضافة بند جديد</button>

            <div class="total-display">
                الإجمالي: <span id="grand_total"><?php echo number_format($quote['total_amount'], 2); ?></span> <span id="grand_currency"><?php echo app_h($currentQuoteCurrency); ?></span>
            </div>

            <div style="margin-top:30px;">
                <label>ملاحظات وشروط</label>
                <textarea name="notes" class="royal-input" rows="3"><?php echo $quote['notes']; ?></textarea>
            </div>

            <div style="margin-top:30px;">
                <label>إضافة مرفقات</label>
                <input type="file" name="quote_attachments[]" class="royal-input" multiple accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx,.xls,.xlsx">
            </div>
        </div>
    </form>
</div>

<script>
const quoteTaxes = <?php echo json_encode(array_values($quoteTaxCatalog), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
const quoteCurrencyMap = <?php echo json_encode($quoteCurrencyOptions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
let activeCurrencyCode = <?php echo json_encode($currentQuoteCurrency); ?>;
let activeRateToBase = <?php echo json_encode($currentQuoteRateToBase); ?>;
function quoteCurrencyMeta(code) {
    return quoteCurrencyMap[code] || {base_currency:'EGP', rate_to_base:1};
}
function convertAmountBetweenCurrencies(amount, fromRate, toRate) {
    amount = Number(amount || 0);
    fromRate = Number(fromRate || 1);
    toRate = Number(toRate || 1);
    if (fromRate <= 0) fromRate = 1;
    if (toRate <= 0) toRate = 1;
    return (amount * fromRate) / toRate;
}
function refreshQuoteRowTotals() {
    document.querySelectorAll('#items_container tr').forEach((tr) => {
        const qty = parseFloat((tr.querySelector('[name="qty[]"]') || {}).value) || 0;
        const price = parseFloat((tr.querySelector('[name="price[]"]') || {}).value) || 0;
        const totalEl = tr.querySelector('.row-total');
        if (totalEl) totalEl.value = (qty * price).toFixed(2);
    });
}
function convertQuoteInputsToCurrency(newCode) {
    const meta = quoteCurrencyMeta(newCode);
    const newRate = Number(meta.rate_to_base || 1);
    if (newCode === activeCurrencyCode || newRate <= 0) return;
    document.querySelectorAll('[name="price[]"]').forEach((input) => {
        const next = convertAmountBetweenCurrencies(parseFloat(input.value) || 0, activeRateToBase, newRate);
        input.value = next.toFixed(2);
    });
    activeCurrencyCode = newCode;
    activeRateToBase = newRate;
    refreshQuoteRowTotals();
}
function updateCurrencyUI(convertValues = false) {
    const select = document.getElementById('quote_currency');
    const code = select ? select.value : 'EGP';
    if (convertValues) {
        convertQuoteInputsToCurrency(code);
    }
    const meta = quoteCurrencyMap[code] || {};
    const base = meta.base_currency || 'EGP';
    const rate = Number(meta.rate_to_base || 1);
    const badge = document.getElementById('currency_badge');
    const suffix = document.getElementById('grand_currency');
    const hint = document.getElementById('currency_hint');
    if (badge) badge.textContent = code;
    if (suffix) suffix.textContent = code;
    if (hint) hint.textContent = code === base ? 'عملة الأساس المعتمدة في البيانات الأولية.' : ('سعر التحويل الحالي: 1 ' + code + ' = ' + rate.toFixed(4) + ' ' + base + '. سيتم حفظه مع العرض.');
    if (convertValues) {
        calcTotal();
    }
}

function addRow() {
    let tr = document.createElement('tr');
    tr.innerHTML = `
        <td>
            <input type="text" name="item_name[]" required class="table-input text-start" placeholder="اسم المنتج...">
            <textarea name="item_description[]" class="table-input text-start item-description-input" rows="2" placeholder="وصف تفصيلي اختياري للبند..." style="resize:vertical; min-height:46px;"></textarea>
        </td>
        <td>
            <div class="qty-unit">
                <input type="number" step="0.01" name="qty[]" value="1" class="table-input qty-input" oninput="calc(this)">
                <select name="unit[]" class="table-input unit-select" onchange="toggleUnitOther(this)">
                    <option value="">وحدة</option>
                    <option value="كيلو">كيلو</option>
                    <option value="طن">طن</option>
                    <option value="علبة">علبة</option>
                    <option value="كرتونة">كرتونة</option>
                    <option value="كيس">كيس</option>
                    <option value="ليبل">ليبل</option>
                    <option value="قطعة">قطعة</option>
                    <option value="متر">متر</option>
                    <option value="رول">رول</option>
                    <option value="باكيت">باكيت</option>
                    <option value="بالة">بالة</option>
                    <option value="زجاجة">زجاجة</option>
                    <option value="لوح">لوح</option>
                    <option value="صندوق">صندوق</option>
                    <option value="عبوة">عبوة</option>
                    <option value="other">أخرى</option>
                </select>
                <input type="text" name="unit_other[]" class="table-input unit-other" placeholder="أخرى" style="display:none;">
            </div>
        </td>
        <td><input type="number" step="0.01" name="price[]" value="0" class="table-input" oninput="calc(this)"></td>
        <td><input type="text" readonly class="table-input row-total" value="0.00" style="color:var(--gold);"></td>
        <td style="text-align:center;">
            <div class="row-actions">
                <i class="fa-solid fa-copy" onclick="duplicateRow(this)" title="تكرار البند"></i>
                <i class="fa-solid fa-xmark del-row" onclick="removeRow(this)" title="حذف البند"></i>
            </div>
        </td>
    `;
    document.getElementById('items_container').appendChild(tr);
}

function duplicateRow(triggerEl) {
    const tr = triggerEl.closest('tr');
    if (!tr) return;
    const clone = tr.cloneNode(true);
    const sourceSelect = tr.querySelector('.unit-select');
    const cloneSelect = clone.querySelector('.unit-select');
    if (sourceSelect && cloneSelect) {
        cloneSelect.value = sourceSelect.value;
    }
    const sourceOther = tr.querySelector('.unit-other');
    const cloneOther = clone.querySelector('.unit-other');
    if (sourceOther && cloneOther) {
        cloneOther.value = sourceOther.value;
        cloneOther.style.display = sourceSelect && sourceSelect.value === 'other' ? '' : 'none';
    }
    tr.insertAdjacentElement('afterend', clone);
    calcTotal();
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

function removeRow(btn) {
    const row = btn.closest('tr');
    if (!row) return;
    const rows = document.querySelectorAll('#items_container tr');
    if (rows.length <= 1) {
        row.querySelector('[name="item_name[]"]').value = '';
        const descInput = row.querySelector('[name="item_description[]"]');
        if (descInput) descInput.value = '';
        row.querySelector('[name="qty[]"]').value = '1';
        row.querySelector('[name="price[]"]').value = '0';
        const unitSelect = row.querySelector('.unit-select');
        if (unitSelect) unitSelect.value = '';
        const unitOther = row.querySelector('.unit-other');
        if (unitOther) {
            unitOther.value = '';
            unitOther.style.display = 'none';
        }
        row.querySelector('.row-total').value = '0.00';
        calcTotal();
        return;
    }
    row.remove();
    calcTotal();
}

function calc(el) {
    let tr = el.closest('tr');
    let q = parseFloat(tr.querySelector('[name="qty[]"]').value) || 0;
    let p = parseFloat(tr.querySelector('[name="price[]"]').value) || 0;
    let total = q * p;
    tr.querySelector('.row-total').value = total.toFixed(2);
    calcTotal();
}

function calcTotal() {
    let total = 0;
    document.querySelectorAll('.row-total').forEach(inp => {
        total += parseFloat(inp.value) || 0;
    });
    const kindEl = document.getElementById('quote_kind');
    const isTaxQuote = kindEl && kindEl.value === 'tax';
    let taxTotal = 0;
    const selectedKeys = Array.from(document.querySelectorAll('.js-tax-key:checked')).map(el => String(el.value || '').trim().toLowerCase());
    document.querySelectorAll('.tax-option').forEach(el => el.classList.toggle('disabled', !isTaxQuote));
    document.querySelectorAll('.js-tax-key').forEach(el => {
        el.disabled = !isTaxQuote;
    });
    let breakdownHtml = '';
    if (isTaxQuote) {
        quoteTaxes.forEach(taxType => {
            const key = String(taxType.key || '').trim().toLowerCase();
            if (!selectedKeys.includes(key)) {
                return;
            }
            const rate = parseFloat(taxType.rate || 0);
            const baseType = String(taxType.base || 'net_after_discount');
            const baseAmount = baseType === 'subtotal' ? total : total;
            const amount = (baseAmount * rate) / 100;
            const mode = String(taxType.mode || 'add');
            const signedAmount = mode === 'subtract' ? (-1 * amount) : amount;
            taxTotal += signedAmount;
            breakdownHtml += `<div class="tax-line ${mode === 'subtract' ? 'subtract' : ''}"><span>${String(taxType.name || key)} (${rate.toFixed(2)}%)</span><span>${mode === 'subtract' ? '-' : '+'}${amount.toFixed(2)}</span></div>`;
        });
    }
    breakdownHtml += `<div class="tax-line" style="font-weight:700; color:var(--gold); margin-bottom:0;"><span>إجمالي الضرائب</span><span>${taxTotal.toFixed(2)}</span></div>`;
    document.getElementById('tax_breakdown').innerHTML = breakdownHtml;
    document.getElementById('grand_total').innerText = Math.max(0, total + taxTotal).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    updateCurrencyUI();
}
window.onload = function () { calcTotal(); updateCurrencyUI(); };
</script>

<?php include 'footer.php'; ob_end_flush(); ?>
