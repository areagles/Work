<?php
ob_start();
require 'auth.php';
require 'config.php';
app_handle_lang_switch($conn);
$isEnglish = app_current_lang($conn) === 'en';

if (!app_user_can('finance.reports.view')) {
    http_response_code(403);
    require 'header.php';
    echo "<div class='container' style='margin-top:30px;'><div style='background:#1d0f0f;border:1px solid #5b2020;border-radius:12px;padding:20px;color:#ffb3b3;'>⛔ " . app_h($isEnglish ? 'You are not authorized to access tax reports.' : 'غير مصرح لك بالدخول إلى التقارير الضريبية.') . "</div></div>";
    require 'footer.php';
    exit;
}

require 'header.php';

$dateFrom = (string)($_GET['date_from'] ?? date('Y-m-01'));
$dateTo = (string)($_GET['date_to'] ?? date('Y-m-t'));
$lawFilter = strtolower(trim((string)($_GET['law_key'] ?? 'all')));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
    $dateFrom = date('Y-m-01');
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
    $dateTo = date('Y-m-t');
}
if ($dateFrom > $dateTo) {
    $tmp = $dateFrom;
    $dateFrom = $dateTo;
    $dateTo = $tmp;
}

$taxDataset = app_tax_report_dataset($conn, $dateFrom, $dateTo, $lawFilter);
$lawCatalog = (array)($taxDataset['law_catalog'] ?? []);
$activeLaws = (array)($taxDataset['active_laws'] ?? []);
$lawFilter = (string)($taxDataset['law_filter'] ?? $lawFilter);
$salesSummary = (array)($taxDataset['sales_summary'] ?? []);
$lawSummary = (array)($taxDataset['law_summary'] ?? []);
$lawBracketSummary = (array)($taxDataset['law_bracket_summary'] ?? []);
$salesInvoiceCount = (int)($taxDataset['sales_invoice_count'] ?? 0);
$salesNetBase = (float)($taxDataset['sales_net_base'] ?? 0);
$salesTaxTotal = (float)($taxDataset['sales_tax_total'] ?? 0);
$salesGrandTotal = (float)($taxDataset['sales_grand_total'] ?? 0);
$outputVatTotal = (float)($taxDataset['output_vat_total'] ?? 0);
$purchaseVatTotal = (float)($taxDataset['purchase_vat_total'] ?? 0);
$purchaseCount = (int)($taxDataset['purchase_count'] ?? 0);
$quoteTaxTotal = (float)($taxDataset['quote_tax_total'] ?? 0);
$quoteCount = (int)($taxDataset['quote_count'] ?? 0);
$netVatDue = (float)($taxDataset['net_vat_due'] ?? 0);
$declarationTitle = (string)($taxDataset['declaration_title'] ?? ($isEnglish ? 'Draft Tax Return' : 'مسودة إقرار ضريبي'));
$declarationBody = (string)($taxDataset['declaration_body'] ?? ($isEnglish ? 'This section shows an operational draft based on recorded system data for the selected period and should be reviewed before official filing.' : 'يعرض هذا القسم مسودة تشغيلية مبنية على البيانات المسجلة بالنظام خلال الفترة المحددة، ويجب مراجعتها محاسبياً قبل التقديم الرسمي.'));
?>

<style>
    :root { --gold:#d4af37; --bg:#0b0b0b; --panel:#141414; --border:#2d2d2d; --green:#2ecc71; --red:#e74c3c; --blue:#3498db; }
    body { background:var(--bg); color:#fff; font-family:'Cairo',sans-serif; }
    .container { max-width:1400px; margin:0 auto; padding:20px; }
    .page-head { display:flex; justify-content:space-between; align-items:center; gap:14px; flex-wrap:wrap; margin:20px 0 24px; }
    .page-head h2 { margin:0; color:var(--gold); }
    .panel { background:var(--panel); border:1px solid var(--border); border-radius:16px; padding:20px; box-shadow:0 10px 24px rgba(0,0,0,.25); }
    .filters { display:grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap:14px; margin-bottom:20px; }
    .input, .select { width:100%; background:#0d0d0d; border:1px solid #3a3a3a; color:#fff; padding:12px; border-radius:10px; }
    .btn { background:linear-gradient(45deg, var(--gold), #b8860b); color:#000; border:none; padding:12px 18px; border-radius:10px; font-weight:800; cursor:pointer; text-decoration:none; display:inline-flex; align-items:center; justify-content:center; gap:8px; }
    .cards { display:grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap:16px; margin-bottom:22px; }
    .card { background:#101010; border:1px solid var(--border); border-radius:14px; padding:18px; }
    .card .label { color:#999; font-size:.9rem; margin-bottom:8px; }
    .card .num { font-size:1.8rem; font-weight:900; }
    .law-cards { display:grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap:16px; margin-bottom:20px; }
    .law-card { background:#101010; border:1px solid #3a2c0c; border-radius:14px; padding:18px; }
    .law-card h4 { margin:0 0 10px; color:var(--gold); }
    .law-meta { color:#bbb; line-height:1.8; font-size:.95rem; }
    .table-wrap { overflow:auto; }
    table { width:100%; border-collapse:collapse; }
    th, td { padding:12px; border-bottom:1px solid #262626; text-align:right; }
    th { color:var(--gold); background:rgba(212,175,55,.06); }
    .declaration { line-height:1.9; color:#ddd; }
</style>

<div class="container">
    <div class="page-head">
        <h2><i class="fa-solid fa-receipt"></i> <?php echo app_h($isEnglish ? 'Tax Reports & Settlement' : 'التقارير الضريبية والمقاصة'); ?></h2>
        <div style="display:flex; gap:10px; flex-wrap:wrap;">
            <a href="print_tax_report.php?<?php echo http_build_query($_GET); ?>" target="_blank" class="btn"><i class="fa-solid fa-print"></i> <?php echo app_h($isEnglish ? 'Print Report' : 'طباعة التقرير'); ?></a>
            <a href="finance_reports.php" class="btn"><i class="fa-solid fa-arrow-right"></i> <?php echo app_h($isEnglish ? 'Back to Finance Reports' : 'الرجوع للتقارير المالية'); ?></a>
        </div>
    </div>

    <div class="panel" style="margin-bottom:20px;">
        <form method="get" class="filters">
            <div>
                <label style="display:block; color:#aaa; margin-bottom:8px;"><?php echo app_h($isEnglish ? 'Date From' : 'من تاريخ'); ?></label>
                <input type="date" class="input" name="date_from" value="<?php echo app_h($dateFrom); ?>">
            </div>
            <div>
                <label style="display:block; color:#aaa; margin-bottom:8px;"><?php echo app_h($isEnglish ? 'Date To' : 'إلى تاريخ'); ?></label>
                <input type="date" class="input" name="date_to" value="<?php echo app_h($dateTo); ?>">
            </div>
            <div>
                <label style="display:block; color:#aaa; margin-bottom:8px;"><?php echo app_h($isEnglish ? 'Tax Law' : 'القانون الضريبي'); ?></label>
                <select class="select" name="law_key">
                    <option value="all"><?php echo app_h($isEnglish ? 'All Laws' : 'كل القوانين'); ?></option>
                    <?php foreach ($lawCatalog as $lawRow): ?>
                        <option value="<?php echo app_h((string)$lawRow['key']); ?>" <?php echo ($lawFilter === (string)$lawRow['key']) ? 'selected' : ''; ?>>
                            <?php echo app_h((string)$lawRow['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="display:flex; align-items:flex-end;">
                <button class="btn" type="submit"><i class="fa-solid fa-filter"></i> <?php echo app_h($isEnglish ? 'Refresh Report' : 'تحديث التقرير'); ?></button>
            </div>
        </form>
    </div>

    <div class="cards">
        <div class="card"><div class="label"><?php echo app_h($isEnglish ? 'Tax Sales Invoices' : 'فواتير ضريبية مبيعات'); ?></div><div class="num"><?php echo number_format($salesInvoiceCount); ?></div></div>
        <div class="card"><div class="label"><?php echo app_h($isEnglish ? 'Active Tax Laws' : 'القوانين الضريبية المفعلة'); ?></div><div class="num"><?php echo number_format(count($activeLaws)); ?></div></div>
        <div class="card"><div class="label"><?php echo app_h($isEnglish ? 'Output Tax' : 'ضريبة المخرجات'); ?></div><div class="num" style="color:var(--gold);"><?php echo number_format($outputVatTotal, 2); ?></div></div>
        <div class="card"><div class="label"><?php echo app_h($isEnglish ? 'Input Tax From Purchases' : 'ضريبة المدخلات من المشتريات'); ?></div><div class="num" style="color:var(--blue);"><?php echo number_format($purchaseVatTotal, 2); ?></div></div>
        <div class="card"><div class="label"><?php echo app_h($isEnglish ? 'Net Tax Settlement' : 'صافي المقاصة الضريبية'); ?></div><div class="num" style="color:<?php echo $netVatDue >= 0 ? 'var(--red)' : 'var(--green)'; ?>;"><?php echo number_format($netVatDue, 2); ?></div></div>
        <div class="card"><div class="label"><?php echo app_h($isEnglish ? 'Quotation Taxes' : 'ضرائب عروض الأسعار'); ?></div><div class="num" style="color:var(--green);"><?php echo number_format($quoteTaxTotal, 2); ?></div></div>
    </div>

    <div class="panel" style="margin-bottom:20px;">
        <h3 style="margin-top:0; color:var(--gold);"><?php echo app_h($isEnglish ? 'Active Tax Laws From Master Data' : 'القوانين الضريبية المفعلة من Master Data'); ?></h3>
        <div class="law-cards">
            <?php if (!empty($activeLaws)): ?>
                <?php foreach ($activeLaws as $lawRow): ?>
                    <div class="law-card">
                        <h4><?php echo app_h((string)($lawRow['name'] ?? '')); ?></h4>
                        <div class="law-meta">
                            <?php echo app_h($isEnglish ? 'Law:' : 'القانون:'); ?> <?php echo app_h((string)($lawRow['key'] ?? '')); ?><br>
                            <?php echo app_h($isEnglish ? 'Category:' : 'الفئة:'); ?> <?php echo app_h((string)($lawRow['category'] ?? '')); ?><br>
                            <?php echo app_h($isEnglish ? 'Frequency:' : 'الدورية:'); ?> <?php echo app_h((string)($lawRow['frequency'] ?? '')); ?><br>
                            <?php echo app_h($isEnglish ? 'Settlement:' : 'طريقة التسوية:'); ?> <?php echo app_h((string)($lawRow['settlement_mode'] ?? '')); ?><br>
                            <?php echo app_h($isEnglish ? 'Defined brackets:' : 'الشرائح المعرفة:'); ?> <?php echo number_format(count((array)($lawRow['brackets'] ?? []))); ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div style="color:#999;"><?php echo app_h($isEnglish ? 'No active tax laws are defined in Master Data.' : 'لا توجد قوانين ضريبية مفعلة في البيانات الأولية.'); ?></div>
            <?php endif; ?>
        </div>
    </div>

    <div class="panel" style="margin-bottom:20px;">
        <h3 style="margin-top:0; color:var(--gold);"><?php echo app_h($isEnglish ? 'Tax Summary By Type' : 'ملخص الضرائب حسب النوع'); ?></h3>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th><?php echo app_h($isEnglish ? 'Tax' : 'الضريبة'); ?></th>
                        <th><?php echo app_h($isEnglish ? 'Category' : 'الفئة'); ?></th>
                        <th><?php echo app_h($isEnglish ? 'Rate' : 'النسبة'); ?></th>
                        <th><?php echo app_h($isEnglish ? 'Calculation Mode' : 'طريقة الحساب'); ?></th>
                        <th><?php echo app_h($isEnglish ? 'Base Total' : 'إجمالي الأساس'); ?></th>
                        <th><?php echo app_h($isEnglish ? 'Tax Total' : 'إجمالي الضريبة'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($salesSummary)): ?>
                        <?php foreach ($salesSummary as $row): ?>
                            <tr>
                                <td><?php echo app_h((string)$row['name']); ?></td>
                                <td><?php echo app_h((string)$row['category']); ?></td>
                                <td><?php echo number_format((float)$row['rate'], 2); ?>%</td>
                                <td><?php echo app_h(((string)$row['mode'] === 'subtract') ? ($isEnglish ? 'Deduct from invoice' : 'خصم من الفاتورة') : ($isEnglish ? 'Add to invoice' : 'إضافة على الفاتورة')); ?></td>
                                <td><?php echo number_format((float)$row['base_total'], 2); ?></td>
                                <td><?php echo number_format((float)$row['amount_total'], 2); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="6" style="color:#999;"><?php echo app_h($isEnglish ? 'No tax data found for the selected period.' : 'لا توجد بيانات ضريبية ضمن الفترة المحددة.'); ?></td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="panel" style="margin-bottom:20px;">
        <h3 style="margin-top:0; color:var(--gold);"><?php echo app_h($isEnglish ? 'Summary By Law' : 'ملخص حسب القانون'); ?></h3>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th><?php echo app_h($isEnglish ? 'Law' : 'القانون'); ?></th>
                        <th><?php echo app_h($isEnglish ? 'Invoice Count' : 'عدد الفواتير'); ?></th>
                        <th><?php echo app_h($isEnglish ? 'Net Base' : 'صافي الأساس'); ?></th>
                        <th><?php echo app_h($isEnglish ? 'Tax Total' : 'إجمالي الضرائب'); ?></th>
                        <th><?php echo app_h($isEnglish ? 'Document Total' : 'إجمالي المستندات'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($lawSummary)): ?>
                        <?php foreach ($lawSummary as $row): ?>
                            <tr>
                                <td><?php echo app_h((string)$row['name']); ?></td>
                                <td><?php echo number_format((float)$row['invoice_count']); ?></td>
                                <td><?php echo number_format((float)$row['net_base'], 2); ?></td>
                                <td><?php echo number_format((float)($row['report_tax_total'] ?? $row['tax_total']), 2); ?></td>
                                <td><?php echo number_format((float)$row['grand_total'], 2); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="5" style="color:#999;"><?php echo app_h($isEnglish ? 'No matching tax invoices were found.' : 'لا توجد فواتير ضريبية مطابقة للبحث.'); ?></td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="panel" style="margin-bottom:20px;">
        <h3 style="margin-top:0; color:var(--gold);"><?php echo app_h($isEnglish ? 'Taxes By Brackets & Law' : 'الضرائب حسب الشرائح والقانون'); ?></h3>
        <?php if (!empty($lawBracketSummary)): ?>
            <?php foreach ($lawBracketSummary as $lawKey => $lawRow): ?>
                <div style="margin-bottom:22px;">
                    <div style="margin-bottom:8px; color:#fff; font-weight:800;"><?php echo app_h((string)($lawRow['law_name'] ?? $lawKey)); ?></div>
                    <div style="margin-bottom:10px; color:#bbb;">
                        <?php echo app_h((string)($lawRow['basis_label'] ?? ($isEnglish ? 'Basis' : 'الوعاء'))); ?>:
                        <?php echo number_format((float)($lawRow['taxable_amount'] ?? 0), 2); ?>
                        <?php if ((string)($lawKey) === 'simplified_6_2025'): ?>
                            <br><?php echo app_h($isEnglish ? 'The simplified regime is calculated on turnover before VAT and only from documents marked as tax invoices.' : 'يتم احتساب النظام المبسط على حجم الأعمال قبل ضريبة القيمة المضافة وبالاعتماد على الفواتير الضريبية فقط.'); ?>
                        <?php endif; ?>
                    </div>
                    <?php if (!empty($lawRow['has_brackets'])): ?>
                        <div class="table-wrap">
                            <table>
                                <thead>
                                    <tr>
                                        <th><?php echo app_h($isEnglish ? 'Bracket' : 'الشريحة'); ?></th>
                                        <th><?php echo app_h($isEnglish ? 'From' : 'من'); ?></th>
                                        <th><?php echo app_h($isEnglish ? 'To' : 'إلى'); ?></th>
                                        <th><?php echo app_h($isEnglish ? 'Rate' : 'النسبة'); ?></th>
                                        <th><?php echo app_h($isEnglish ? 'Slice Amount' : 'الوعاء داخل الشريحة'); ?></th>
                                        <th><?php echo app_h($isEnglish ? 'Estimated Tax' : 'الضريبة التقديرية'); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ((array)($lawRow['rows'] ?? []) as $bracketRow): ?>
                                        <tr>
                                            <td><?php echo app_h((string)($bracketRow['label'] ?? '')); ?></td>
                                            <td><?php echo number_format((float)($bracketRow['from'] ?? 0), 2); ?></td>
                                            <td><?php echo $bracketRow['to'] === null ? app_h($isEnglish ? 'Open' : 'مفتوحة') : number_format((float)$bracketRow['to'], 2); ?></td>
                                            <td><?php echo number_format((float)($bracketRow['rate'] ?? 0), 4); ?>%</td>
                                            <td><?php echo number_format((float)($bracketRow['slice_amount'] ?? 0), 2); ?></td>
                                            <td><?php echo number_format((float)($bracketRow['estimated_due'] ?? 0), 2); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <tr>
                                        <td colspan="5" style="font-weight:800;"><?php echo app_h($isEnglish ? 'Total Estimated Tax By Brackets' : 'إجمالي الضريبة التقديرية حسب الشرائح'); ?></td>
                                        <td style="font-weight:800;"><?php echo number_format((float)($lawRow['estimated_due'] ?? 0), 2); ?></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div style="color:#999;"><?php echo app_h($isEnglish ? 'No brackets are defined for this law in Master Data yet.' : 'لا توجد شرائح معرفة لهذا القانون في Master Data حتى الآن.'); ?></div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div style="color:#999;"><?php echo app_h($isEnglish ? 'No bracket data matched the selected period and active laws.' : 'لا توجد بيانات شرائح مطابقة للفترة والقوانين المفعلة.'); ?></div>
        <?php endif; ?>
    </div>

    <div class="panel">
        <h3 style="margin-top:0; color:var(--gold);"><?php echo app_h($declarationTitle); ?></h3>
        <div class="declaration">
            <?php echo app_h($declarationBody); ?><br>
            <?php echo app_h($isEnglish ? 'Total taxable sales during the period:' : 'إجمالي المبيعات الخاضعة خلال الفترة:'); ?> <?php echo number_format($salesGrandTotal, 2); ?> <?php echo app_h($isEnglish ? '' : 'جنيه.'); ?><br>
            <?php echo app_h($isEnglish ? 'Purchase invoices used in settlement:' : 'عدد فواتير المشتريات المستخدمة في المقاصة:'); ?> <?php echo number_format($purchaseCount); ?>.<br>
            <?php echo app_h($isEnglish ? 'Tax quotations during the period:' : 'عدد عروض الأسعار الضريبية خلال الفترة:'); ?> <?php echo number_format($quoteCount); ?>.<br>
            <?php echo app_h($isEnglish ? 'This is an operational draft based on Master Data tax settings and recorded system data, and it should be reviewed before final filing.' : 'هذه المسودة تشغيلية ومبنية على الإعدادات المختارة في `master_data.php` والبيانات المسجلة في النظام، ويجب مراجعتها قبل الإقرار النهائي.'); ?>
        </div>
    </div>
</div>

<?php require 'footer.php'; ob_end_flush(); ?>
