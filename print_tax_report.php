<?php
require 'auth.php';
require 'config.php';
app_handle_lang_switch($conn);
$isEnglish = app_current_lang($conn) === 'en';

if (!app_user_can('finance.reports.view')) {
    http_response_code(403);
    exit('⛔ ' . ($isEnglish ? 'You are not authorized to print tax reports.' : 'غير مصرح لك بطباعة التقارير الضريبية.'));
}

$appName = app_setting_get($conn, 'app_name', 'Arab Eagles');
$dateFrom = (string)($_GET['date_from'] ?? date('Y-m-01'));
$dateTo = (string)($_GET['date_to'] ?? date('Y-m-t'));
$lawFilter = strtolower(trim((string)($_GET['law_key'] ?? 'all')));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) { $dateFrom = date('Y-m-01'); }
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) { $dateTo = date('Y-m-t'); }
if ($dateFrom > $dateTo) { $tmp = $dateFrom; $dateFrom = $dateTo; $dateTo = $tmp; }

$taxDataset = app_tax_report_dataset($conn, $dateFrom, $dateTo, $lawFilter);
$lawCatalog = (array)($taxDataset['law_catalog'] ?? []);
$activeLaws = (array)($taxDataset['active_laws'] ?? []);
$lawSummary = (array)($taxDataset['law_summary'] ?? []);
$lawBracketSummary = (array)($taxDataset['law_bracket_summary'] ?? []);
$salesInvoiceCount = (int)($taxDataset['sales_invoice_count'] ?? 0);
$outputVatTotal = (float)($taxDataset['output_vat_total'] ?? 0);
$purchaseVatTotal = (float)($taxDataset['purchase_vat_total'] ?? 0);
$netVatDue = (float)($taxDataset['net_vat_due'] ?? 0);
$quoteTaxTotal = (float)($taxDataset['quote_tax_total'] ?? 0);
$purchaseCount = (int)($taxDataset['purchase_count'] ?? 0);
$quoteCount = (int)($taxDataset['quote_count'] ?? 0);
$salesGrandTotal = (float)($taxDataset['sales_grand_total'] ?? 0);
$declarationTitle = (string)($taxDataset['declaration_title'] ?? ($isEnglish ? 'Draft Tax Return' : 'مسودة إقرار ضريبي'));
$declarationBody = (string)($taxDataset['declaration_body'] ?? '');
$selectedLawLabel = $isEnglish ? 'All Laws' : 'كل القوانين';
foreach ($lawCatalog as $lawRow) {
    if ((string)($lawRow['key'] ?? '') === $lawFilter) {
        $selectedLawLabel = (string)($lawRow['name'] ?? $selectedLawLabel);
        break;
    }
}
?>
<!DOCTYPE html>
<html dir="<?php echo $isEnglish ? 'ltr' : 'rtl'; ?>" lang="<?php echo $isEnglish ? 'en' : 'ar'; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo app_h($isEnglish ? 'Print Tax Report' : 'طباعة التقرير الضريبي'); ?> | <?php echo app_h($appName); ?></title>
    
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;800&display=swap" rel="stylesheet">

    <style>
        /* ========================================================= */
        /* تصميم الشاشة (الوضع الداكن - Screen Mode)                 */
        /* ========================================================= */
        :root {
            --ae-gold: #d4af37;
            --ae-gold-light: #f1c40f;
            --ae-dark: #0d0d0d;
            --ae-card: #151515;
            --ae-border: #2a2a2a;
            --ae-text-main: #f5f5f5;
            --ae-text-muted: #a0a0a0;
        }

        body { 
            font-family: 'Cairo', Tahoma, Arial, sans-serif; 
            background: var(--ae-dark); 
            color: var(--ae-text-main); 
            padding: 30px; 
            line-height: 1.6;
            margin: 0;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
        }

        /* الأزرار (أثناء المعاينة) */
        .no-print { 
            display: flex; 
            justify-content: center; 
            gap: 15px; 
            margin-bottom: 30px; 
            padding-bottom: 20px;
            border-bottom: 1px dashed var(--ae-border);
        }
        .btn { 
            display: inline-flex; 
            align-items: center; 
            gap: 8px; 
            padding: 10px 24px; 
            border-radius: 8px; 
            font-family: 'Cairo', sans-serif;
            font-weight: 600; 
            text-decoration: none; 
            cursor: pointer; 
            transition: all 0.3s ease; 
            font-size: 1rem;
            border: 1px solid transparent;
        }
        .btn-print {
            background: linear-gradient(90deg, var(--ae-gold), #b8860b);
            color: #000;
        }
        .btn-print:hover {
            box-shadow: 0 0 15px rgba(212, 175, 55, 0.4);
            transform: translateY(-2px);
        }
        .btn-back {
            background: var(--ae-card);
            color: var(--ae-text-main);
            border-color: var(--ae-border);
        }
        .btn-back:hover {
            border-color: var(--ae-gold);
            color: var(--ae-gold);
        }

        /* الترويسة */
        .head { text-align: center; margin-bottom: 30px; }
        .head h1 { color: var(--ae-gold); margin: 0 0 5px 0; font-size: 2rem; font-weight: 800; }
        .head .app-name { font-size: 1.2rem; color: var(--ae-text-muted); font-weight: 600; letter-spacing: 1px; }

        /* بيانات التقرير */
        .meta { 
            display: flex; 
            flex-wrap: wrap; 
            justify-content: space-between; 
            gap: 15px;
            margin: 0 auto 30px; 
            background: var(--ae-card); 
            border: 1px solid var(--ae-gold); 
            border-radius: 10px; 
            padding: 15px 20px; 
        }
        .meta div { font-size: 1rem; font-weight: 600; }
        .meta div span { color: var(--ae-gold); margin-inline-end: 5px; }

        /* البطاقات الإحصائية */
        .cards { 
            display: grid; 
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); 
            gap: 15px; 
            margin-bottom: 30px; 
        }
        .card { 
            background: var(--ae-card); 
            border: 1px solid var(--ae-border); 
            border-radius: 10px; 
            padding: 15px; 
            text-align: center;
            transition: transform 0.3s ease, border-color 0.3s ease;
        }
        .card:hover {
            border-color: var(--ae-gold);
            transform: translateY(-3px);
        }
        .card .label { font-size: 0.9rem; color: var(--ae-text-muted); margin-bottom: 8px; font-weight: 600; }
        .card .value { font-size: 1.4rem; font-weight: 800; color: var(--ae-gold); }

        /* الجداول */
        .table-responsive { width: 100%; overflow-x: auto; margin-bottom: 30px; border-radius: 8px; border: 1px solid var(--ae-border); }
        table { width: 100%; border-collapse: collapse; background: var(--ae-card); }
        th, td { 
            padding: 12px 15px; 
            text-align: <?php echo $isEnglish ? 'left' : 'right'; ?>; 
            font-size: 0.95rem; 
            border-bottom: 1px solid var(--ae-border);
        }
        th { 
            background: #1a1a1a; 
            color: var(--ae-gold); 
            font-weight: 800; 
            text-transform: uppercase; 
            letter-spacing: 0.5px; 
            border-bottom: 2px solid var(--ae-gold);
        }
        tr:last-child td { border-bottom: none; }
        tbody tr:hover { background: rgba(212, 175, 55, 0.05); }

        /* الإقرار الضريبي */
        .declaration { 
            background: rgba(212, 175, 55, 0.05); 
            border: 1px dashed var(--ae-gold); 
            border-radius: 10px; 
            padding: 20px; 
            line-height: 1.8; 
            margin-top: 10px; 
            font-size: 0.95rem;
        }
        .declaration strong { color: var(--ae-gold); font-size: 1.1rem; display: block; margin-bottom: 10px; }

        /* ========================================================= */
        /* تصميم الطباعة (الوضع الفاتح للحفاظ على الحبر ومراعاة القطع) */
        /* ========================================================= */
        @media print {
            body { 
                background: #fff !important; 
                color: #000 !important; 
                padding: 0 !important; 
                font-size: 12pt !important;
            }
            .container { max-width: 100% !important; }
            
            /* إخفاء الأزرار نهائياً في الطباعة */
            .no-print { display: none !important; }

            .head h1 { color: #000 !important; font-size: 18pt !important; border-bottom: 2px solid #000; padding-bottom: 10px; display: inline-block;}
            .head .app-name { color: #555 !important; }

            .meta { 
                background: transparent !important; 
                border: 1px solid #000 !important; 
                color: #000 !important;
                border-radius: 0 !important;
            }
            .meta div span { color: #000 !important; font-weight: bold; }

            .cards { display: flex !important; flex-wrap: wrap !important; gap: 10px !important; }
            .card { 
                background: transparent !important; 
                border: 1px solid #000 !important; 
                flex: 1 1 calc(33.333% - 10px);
                border-radius: 0 !important;
                padding: 10px !important;
            }
            .card .label { color: #333 !important; font-size: 10pt !important; }
            .card .value { color: #000 !important; font-size: 14pt !important; }

            .table-responsive { border: none !important; overflow: visible !important; margin-bottom: 20px !important; }
            table { background: transparent !important; border-collapse: collapse !important; }
            th, td { 
                border: 1px solid #000 !important; 
                padding: 8px !important; 
                color: #000 !important; 
            }
            th { 
                background: #f0f0f0 !important; 
                -webkit-print-color-adjust: exact; 
                print-color-adjust: exact; 
                border-bottom: 2px solid #000 !important;
            }
            
            .declaration { 
                background: transparent !important; 
                border: 1px dashed #000 !important; 
                color: #000 !important; 
                border-radius: 0 !important;
            }
            .declaration strong { color: #000 !important; }

            /* 🌟 إعدادات منع قطع البيانات بين الصفحات 🌟 */
            table, tr, td, th, .card, .declaration, .meta {
                page-break-inside: avoid !important;
                break-inside: avoid !important;
            }

            /* 🌟 تكرار عناوين الجدول في كل صفحة 🌟 */
            thead {
                display: table-header-group !important;
            }
            
            tfoot {
                display: table-footer-group !important;
            }
            
            /* مساحات إضافية لتفادي التصاق الجداول عند القطع */
            table {
                page-break-after: auto !important;
                margin-bottom: 20px !important;
            }
        }

        
        /* Codex responsive print safety 2026-05-15 */
        @media (max-width: 680px) {
            .items-table tbody td[data-label],
            .inv-table tbody td[data-label],
            .royal-table tbody td[data-label],
            table.mobile-stack tbody td[data-label] {
                display: grid !important;
                grid-template-columns: minmax(92px, 36%) minmax(0, 1fr) !important;
                gap: 10px !important;
                align-items: start !important;
                padding: 12px !important;
                text-align: right !important;
                min-height: 0 !important;
                white-space: normal !important;
                overflow-wrap: anywhere !important;
                word-break: normal !important;
            }
            .items-table tbody td[data-label]::before,
            .inv-table tbody td[data-label]::before,
            .royal-table tbody td[data-label]::before,
            table.mobile-stack tbody td[data-label]::before {
                content: attr(data-label) !important;
                position: static !important;
                width: auto !important;
                min-width: 0 !important;
                max-width: none !important;
                color: var(--gold-soft, var(--gold, #d4af37)) !important;
                font-size: .78rem !important;
                font-weight: 800 !important;
                line-height: 1.5 !important;
                white-space: normal !important;
                overflow-wrap: anywhere !important;
            }
            .items-table td.desc,
            .inv-table td.desc,
            .royal-table td.desc {
                width: 100% !important;
                line-height: 1.7 !important;
            }
            .price-cell,
            .amount-cell,
            .money-cell {
                direction: ltr !important;
                unicode-bidi: plaintext !important;
            }
        }
        
        @media print {
            .table-responsive,
            .table-wrapper,
            .items-panel,
            .table-section,
            .royal-table-card,
            .table-container {
                overflow: visible !important;
                break-inside: auto !important;
                page-break-inside: auto !important;
            }
            table,
            .items-table,
            .inv-table,
            .royal-table,
            .print-master-table {
                table-layout: fixed !important;
                width: 100% !important;
                overflow: visible !important;
                border-collapse: collapse !important;
            }
            thead { display: table-header-group !important; }
            tfoot { display: table-footer-group !important; }
            tr,
            tbody tr,
            thead tr,
            tfoot tr,
            td,
            th {
                break-inside: avoid !important;
                page-break-inside: avoid !important;
            }
            td,
            th {
                overflow-wrap: anywhere !important;
                word-break: normal !important;
                vertical-align: top !important;
            }
            td::before,
            th::before {
                display: none !important;
            }
        }
        </style>
</head>
<body>
    <div class="container">
        
        <div class="no-print">
            <button class="btn btn-print" onclick="window.print()">
                <i class="fa-solid fa-print"></i> <?php echo app_h($isEnglish ? 'Print Report' : 'طباعة التقرير الرسمي'); ?>
            </button>
            <a class="btn btn-back" href="tax_reports.php?<?php echo http_build_query($_GET); ?>">
                <i class="fa-solid fa-arrow-<?php echo $isEnglish ? 'left' : 'right'; ?>"></i> <?php echo app_h($isEnglish ? 'Back to Filter' : 'العودة للفلترة'); ?>
            </a>
        </div>

        <div class="head">
            <h1><i class="fa-solid fa-file-invoice-dollar"></i> <?php echo app_h($isEnglish ? 'Tax Report & Settlement' : 'التقرير الضريبي والمقاصة'); ?></h1>
            <div class="app-name"><?php echo app_h($appName); ?></div>
        </div>

        <div class="meta">
            <div><span><i class="fa-solid fa-calendar-days"></i> <?php echo app_h($isEnglish ? 'Period:' : 'الفترة الزمنية:'); ?></span> <?php echo $isEnglish ? app_h($dateFrom . ' to ' . $dateTo) : 'من ' . app_h($dateFrom) . ' إلى ' . app_h($dateTo); ?></div>
            <div><span><i class="fa-solid fa-gavel"></i> <?php echo app_h($isEnglish ? 'Law:' : 'القانون المطبق:'); ?></span> <?php echo app_h($selectedLawLabel); ?></div>
            <div><span><i class="fa-solid fa-receipt"></i> <?php echo app_h($isEnglish ? 'Tax sales invoices:' : 'إجمالي فواتير المبيعات الضريبية:'); ?></span> <?php echo number_format($salesInvoiceCount); ?></div>
        </div>

        <div class="cards">
            <div class="card">
                <div class="label"><?php echo app_h($isEnglish ? 'Active Laws' : 'القوانين المفعلة'); ?></div>
                <div class="value"><?php echo number_format(count($activeLaws)); ?></div>
            </div>
            <div class="card">
                <div class="label"><?php echo app_h($isEnglish ? 'Output Tax' : 'ضريبة المخرجات'); ?></div>
                <div class="value"><?php echo number_format($outputVatTotal, 2); ?></div>
            </div>
            <div class="card">
                <div class="label"><?php echo app_h($isEnglish ? 'Input Tax' : 'ضريبة المدخلات'); ?></div>
                <div class="value"><?php echo number_format($purchaseVatTotal, 2); ?></div>
            </div>
            <div class="card">
                <div class="label"><?php echo app_h($isEnglish ? 'Net Settlement' : 'صافي المقاصة المستحقة'); ?></div>
                <div class="value" style="color: <?php echo $netVatDue >= 0 ? 'var(--ae-gold)' : '#e74c3c'; ?>;"><?php echo number_format($netVatDue, 2); ?></div>
            </div>
            <div class="card">
                <div class="label"><?php echo app_h($isEnglish ? 'Quotation Taxes' : 'ضرائب عروض الأسعار'); ?></div>
                <div class="value"><?php echo number_format($quoteTaxTotal, 2); ?></div>
            </div>
            <div class="card">
                <div class="label"><?php echo app_h($isEnglish ? 'Tax Sales Total' : 'إجمالي المبيعات الضريبية'); ?></div>
                <div class="value"><?php echo number_format($salesGrandTotal, 2); ?></div>
            </div>
        </div>

        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th><?php echo app_h($isEnglish ? 'Active Law' : 'القانون المفعّل'); ?></th>
                        <th><?php echo app_h($isEnglish ? 'Key' : 'المعرف (Key)'); ?></th>
                        <th><?php echo app_h($isEnglish ? 'Category' : 'الفئة'); ?></th>
                        <th><?php echo app_h($isEnglish ? 'Frequency' : 'الدورية'); ?></th>
                        <th><?php echo app_h($isEnglish ? 'Settlement' : 'طريقة التسوية'); ?></th>
                        <th><?php echo app_h($isEnglish ? 'Brackets' : 'الشرائح'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($activeLaws): ?>
                        <?php foreach ($activeLaws as $lawRow): ?>
                            <tr>
                                <td style="font-weight:600;"><?php echo app_h((string)($lawRow['name'] ?? '')); ?></td>
                                <td style="color:var(--ae-text-muted);"><?php echo app_h((string)($lawRow['key'] ?? '')); ?></td>
                                <td><?php echo app_h((string)($lawRow['category'] ?? '')); ?></td>
                                <td><?php echo app_h((string)($lawRow['frequency'] ?? '')); ?></td>
                                <td><?php echo app_h((string)($lawRow['settlement_mode'] ?? '')); ?></td>
                                <td><?php echo number_format(count((array)($lawRow['brackets'] ?? []))); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="6" style="text-align:center; color:var(--ae-text-muted);"><?php echo app_h($isEnglish ? 'No active tax laws are configured.' : 'لا توجد قوانين ضريبية مفعلة في النظام حالياً.'); ?></td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th><?php echo app_h($isEnglish ? 'Law' : 'القانون'); ?></th>
                        <th><?php echo app_h($isEnglish ? 'Invoice Count' : 'عدد الفواتير'); ?></th>
                        <th><?php echo app_h($isEnglish ? 'Net Base' : 'صافي الأساس'); ?></th>
                        <th><?php echo app_h($isEnglish ? 'Tax / Estimated Due' : 'الضريبة / المستحق التقديري'); ?></th>
                        <th><?php echo app_h($isEnglish ? 'Report Basis' : 'وعاء التقرير الإجمالي'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($lawSummary): ?>
                        <?php foreach ($lawSummary as $row): ?>
                            <tr>
                                <td style="font-weight:600; color:var(--ae-gold-light);"><?php echo app_h((string)$row['name']); ?></td>
                                <td><?php echo number_format((float)$row['invoice_count']); ?></td>
                                <td><?php echo number_format((float)$row['net_base'], 2); ?></td>
                                <td style="font-weight:bold;"><?php echo number_format((float)($row['report_tax_total'] ?? $row['tax_total']), 2); ?></td>
                                <td><?php echo number_format((float)$row['grand_total'], 2); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="5" style="text-align:center; color:var(--ae-text-muted);"><?php echo app_h($isEnglish ? 'No data found for the selected period.' : 'لا توجد بيانات مسجلة ضمن الفترة المحددة.'); ?></td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th><?php echo app_h($isEnglish ? 'Law' : 'القانون'); ?></th>
                        <th><?php echo app_h($isEnglish ? 'Applied Basis' : 'الوعاء المعتمد'); ?></th>
                        <th><?php echo app_h($isEnglish ? 'Bracket' : 'اسم الشريحة'); ?></th>
                        <th><?php echo app_h($isEnglish ? 'From' : 'من'); ?></th>
                        <th><?php echo app_h($isEnglish ? 'To' : 'إلى'); ?></th>
                        <th><?php echo app_h($isEnglish ? 'Rate' : 'النسبة'); ?></th>
                        <th><?php echo app_h($isEnglish ? 'Slice Amount' : 'الوعاء داخل الشريحة'); ?></th>
                        <th><?php echo app_h($isEnglish ? 'Estimated Tax' : 'الضريبة التقديرية'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($lawBracketSummary): ?>
                        <?php foreach ($lawBracketSummary as $lawKey => $lawRow): ?>
                            <?php if (!empty($lawRow['rows'])): ?>
                                <?php foreach ((array)$lawRow['rows'] as $bracketRow): ?>
                                    <tr>
                                        <td style="font-weight:600;"><?php echo app_h((string)($lawRow['law_name'] ?? $lawKey)); ?></td>
                                        <td style="color:var(--ae-text-muted);"><?php echo app_h((string)($lawRow['basis_label'] ?? ($isEnglish ? 'Basis' : 'الوعاء'))); ?>: <span style="color:#fff;"><?php echo number_format((float)($lawRow['taxable_amount'] ?? 0), 2); ?></span></td>
                                        <td><?php echo app_h((string)($bracketRow['label'] ?? '')); ?></td>
                                        <td><?php echo number_format((float)($bracketRow['from'] ?? 0), 2); ?></td>
                                        <td><?php echo $bracketRow['to'] === null ? app_h($isEnglish ? 'Open' : 'مفتوحة (بلا حد)') : number_format((float)$bracketRow['to'], 2); ?></td>
                                        <td style="color:var(--ae-gold);"><?php echo number_format((float)($bracketRow['rate'] ?? 0), 4); ?>%</td>
                                        <td><?php echo number_format((float)($bracketRow['slice_amount'] ?? 0), 2); ?></td>
                                        <td style="font-weight:bold;"><?php echo number_format((float)($bracketRow['estimated_due'] ?? 0), 2); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td style="font-weight:600;"><?php echo app_h((string)($lawRow['law_name'] ?? $lawKey)); ?></td>
                                    <td><?php echo app_h((string)($lawRow['basis_label'] ?? ($isEnglish ? 'Basis' : 'الوعاء'))); ?>: <?php echo number_format((float)($lawRow['taxable_amount'] ?? 0), 2); ?></td>
                                    <td colspan="6" style="text-align:center; color:var(--ae-text-muted);"><?php echo app_h($isEnglish ? 'No brackets are defined for this law in Master Data.' : 'لا توجد شرائح معرفة لهذا القانون في البيانات الأساسية (Master Data).'); ?></td>
                                </tr>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="8" style="text-align:center; color:var(--ae-text-muted);"><?php echo app_h($isEnglish ? 'No matching bracket data was found.' : 'لا توجد بيانات شرائح مطابقة لهذه الفترة.'); ?></td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="declaration">
            <strong><i class="fa-solid fa-file-signature"></i> <?php echo app_h($declarationTitle); ?></strong>
            <p style="margin: 5px 0 15px 0;"><?php echo app_h($declarationBody); ?></p>
            <div style="display:flex; gap:30px; flex-wrap:wrap; border-top:1px solid rgba(212, 175, 55, 0.2); padding-top:10px;">
                <div><i class="fa-solid fa-file-invoice" style="color:var(--ae-text-muted);"></i> <?php echo app_h($isEnglish ? 'Purchase invoices used in settlement:' : 'فواتير المشتريات المستخدمة في المقاصة:'); ?> <strong><?php echo number_format($purchaseCount); ?></strong></div>
                <div><i class="fa-solid fa-file-contract" style="color:var(--ae-text-muted);"></i> <?php echo app_h($isEnglish ? 'Tax quotations during the period:' : 'عروض الأسعار الضريبية المصدرة خلال الفترة:'); ?> <strong><?php echo number_format($quoteCount); ?></strong></div>
            </div>
        </div>

    </div>
</body>
</html>