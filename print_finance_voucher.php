<?php
// print_finance_voucher.php - طباعة سند قبض / صرف
ob_start();

require 'auth.php';
require 'config.php';
require_once 'finance_engine.php';
app_handle_lang_switch($conn);
financeEnsureAllocationSchema($conn);

$canFinanceVoucher = app_user_can_any([
    'finance.view',
    'finance.transactions.view',
    'finance.transactions.create',
    'finance.transactions.update',
    'finance.transactions.delete',
]);

if (!$canFinanceVoucher) {
    http_response_code(403);
    die('<div style="padding:30px;font-family:Cairo,Arial,sans-serif">غير مصرح لك بطباعة السندات المالية.</div>');
}

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    die('<div style="padding:30px;font-family:Cairo,Arial,sans-serif">رقم السند غير صالح.</div>');
}

$stmt = $conn->prepare("
    SELECT
        t.*,
        c.name AS client_name,
        c.phone AS client_phone,
        s.name AS supplier_name,
        s.phone AS supplier_phone,
        u.full_name AS employee_name,
        ps.month_year AS payroll_month
    FROM financial_receipts t
    LEFT JOIN clients c ON t.client_id = c.id
    LEFT JOIN suppliers s ON t.supplier_id = s.id
    LEFT JOIN users u ON t.employee_id = u.id
    LEFT JOIN payroll_sheets ps ON t.payroll_id = ps.id
    WHERE t.id = ?
    LIMIT 1
");
$stmt->bind_param('i', $id);
$stmt->execute();
$res = $stmt->get_result();
$voucher = $res ? $res->fetch_assoc() : null;
$stmt->close();

if (!$voucher) {
    http_response_code(404);
    die('<div style="padding:30px;font-family:Cairo,Arial,sans-serif">السند غير موجود.</div>');
}

$appName = app_setting_get($conn, 'app_name', 'Arab Eagles');
$appLogo = app_brand_logo_path($conn, 'assets/img/Logo.png');
$outputTheme = app_brand_output_theme($conn);
$brandProfile = app_brand_profile($conn);
$outputShowHeader = !empty($brandProfile['show_header']);
$outputShowFooter = !empty($brandProfile['show_footer']);
$outputShowLogo = !empty($brandProfile['show_logo']);
$outputShowQr = !empty($brandProfile['show_qr']);
$headerLines = app_brand_output_lines($brandProfile, 'header', true);
$footerLines = app_brand_output_lines($brandProfile, 'footer', true);

$type = strtolower(trim((string)($voucher['type'] ?? 'in')));
$category = strtolower(trim((string)($voucher['category'] ?? 'general')));
$isReceipt = ($type === 'in');
$voucherTitle = $isReceipt ? 'سند قبض' : 'سند صرف';
$voucherTitleEn = $isReceipt ? 'Receipt Voucher' : 'Payment Voucher';
$docType = $isReceipt ? 'receipt' : 'payment';

$voucherNumber = trim((string)($voucher['voucher_number'] ?? ''));
if ($voucherNumber === '') {
    $voucherNumber = app_assign_document_number(
        $conn,
        'financial_receipts',
        $id,
        'voucher_number',
        $docType,
        (string)($voucher['trans_date'] ?? date('Y-m-d'))
    );
}
if ($voucherNumber === '') {
    $voucherNumber = '#' . str_pad((string)$id, 6, '0', STR_PAD_LEFT);
}

$categoryMap = [
    'general' => 'عام',
    'supplier' => 'موردين',
    'salary' => 'رواتب',
    'loan' => 'سلف',
    'tax' => 'ضرائب',
];
$categoryText = $categoryMap[$category] ?? 'عام';
$taxLawKey = trim((string)($voucher['tax_law_key'] ?? ''));
$taxLawLabel = $taxLawKey !== '' && function_exists('app_tax_find_law')
    ? (string)((app_tax_find_law($conn, $taxLawKey)['name'] ?? $taxLawKey))
    : $taxLawKey;

$counterpartyLabel = $isReceipt ? 'العميل / جهة القبض' : 'الجهة المستفيدة';
$counterpartyValue = '-';
$counterpartyPhone = '';

if ($isReceipt && trim((string)($voucher['client_name'] ?? '')) !== '') {
    $counterpartyLabel = 'العميل';
    $counterpartyValue = (string)$voucher['client_name'];
    $counterpartyPhone = (string)($voucher['client_phone'] ?? '');
} elseif (!$isReceipt && trim((string)($voucher['supplier_name'] ?? '')) !== '') {
    $counterpartyLabel = 'المورد';
    $counterpartyValue = (string)$voucher['supplier_name'];
    $counterpartyPhone = (string)($voucher['supplier_phone'] ?? '');
} elseif (!$isReceipt && trim((string)($voucher['employee_name'] ?? '')) !== '') {
    $counterpartyLabel = 'الموظف';
    $counterpartyValue = (string)$voucher['employee_name'];
}

$reference = '-';
if (!empty($voucher['invoice_id'])) {
    $invoiceId = (int)$voucher['invoice_id'];
    $reference = $isReceipt ? "فاتورة مبيعات #{$invoiceId}" : "فاتورة مشتريات #{$invoiceId}";
}
if (!empty($voucher['payroll_id'])) {
    $payrollId = (int)$voucher['payroll_id'];
    $payrollMonth = trim((string)($voucher['payroll_month'] ?? ''));
    $reference = $payrollMonth !== ''
        ? "كشف راتب #{$payrollId} ({$payrollMonth})"
        : "كشف راتب #{$payrollId}";
}

$amount = (float)($voucher['amount'] ?? 0);
$amountText = number_format($amount, 2);
$createdBy = trim((string)($voucher['created_by'] ?? ''));
if ($createdBy === '') {
    $createdBy = 'System';
}
$transDate = trim((string)($voucher['trans_date'] ?? date('Y-m-d')));
$notes = trim((string)($voucher['description'] ?? ''));
$allocationSummary = financeReceiptAllocationSummary($conn, $voucher);
$balanceSummary = financeCounterpartyBalanceSummary($conn, $voucher);
$referenceValue = $reference;
if (($allocationSummary['count'] ?? 0) === 1 && !empty($allocationSummary['lines'][0]['label'])) {
    $referenceValue = (string)$allocationSummary['lines'][0]['label'];
}
if (($allocationSummary['count'] ?? 0) > 1) {
    $referenceValue = app_tr('توزيع داخلي على عدة فواتير', 'Internal allocation across multiple invoices');
}
$qrPayload = app_brand_qr_payload($brandProfile, [
    'Document' => $voucherTitleEn,
    'Reference' => $voucherNumber,
    'Date' => $transDate,
    'Amount' => number_format($amount, 2, '.', ''),
    'Category' => $categoryText,
]);
$qrUrl = ($outputShowQr && $qrPayload !== '') ? app_brand_qr_url($qrPayload, 140) : '';
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title><?php echo app_h($voucherTitle . ' ' . $voucherNumber); ?> | <?php echo app_h($appName); ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* ========================================================= */
        /* المتغيرات الأساسية (بما فيها متغيرات النظام للحفاظ على المنطق) */
        /* ========================================================= */
        :root {
            /* متغيرات النظام الأصلية (لا يتم حذفها) */
            --sys-gold: <?php echo app_h($outputTheme['accent']); ?>;
            --sys-gold-soft: <?php echo app_h($outputTheme['accent_soft']); ?>;
            --sys-ink: <?php echo app_h($outputTheme['ink']); ?>;
            --sys-paper: <?php echo app_h($outputTheme['paper']); ?>;
            
            /* متغيرات الهوية الفحمية والذهبية (Screen Theme) */
            --ae-gold: #d4af37;
            --ae-gold-light: #f1c40f;
            --ae-dark: #0a0a0a;
            --ae-card: #151515;
            --ae-border: #2a2a2a;
            --ae-text: #f5f5f5;
            --ae-text-muted: #a0a0a0;
            --ae-red: #e74c3c;
            --ae-green: #2ecc71;
        }
        
        * { box-sizing: border-box; }
        
        body {
            margin: 0;
            padding: 30px 16px 120px 16px;
            font-family: 'Cairo', sans-serif;
            background: var(--ae-dark);
            color: var(--ae-text);
        }

        /* ========================================================= */
        /* الحاوية الرئيسية للسند (Screen Mode)                      */
        /* ========================================================= */
        .sheet {
            max-width: 920px;
            margin: 0 auto;
            background: var(--ae-card);
            color: var(--ae-text);
            border-radius: 16px;
            border: 1px solid var(--ae-border);
            overflow: hidden;
            box-shadow: 0 18px 50px rgba(0,0,0,0.6);
            position: relative;
        }
        
        .sheet-top {
            height: 6px;
            background: linear-gradient(90deg, var(--ae-gold), var(--ae-gold-light), var(--ae-gold));
        }
        
        .sheet-body {
            padding: 40px;
        }

        /* الترويسة */
        .head {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 20px;
            border-bottom: 2px dashed var(--ae-border);
            padding-bottom: 25px;
            margin-bottom: 30px;
        }
        .brand {
            display: flex;
            align-items: center;
            gap: 15px;
        }
        .brand img {
            width: 60px;
            height: 60px;
            object-fit: contain;
            border-radius: 10px;
            background: transparent;
        }
        .logo-fallback {
            width: 60px;
            height: 60px;
            border-radius: 10px;
            border: 1px solid var(--ae-gold);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            font-weight: 900;
            color: var(--ae-gold);
            background: rgba(212, 175, 55, 0.1);
        }
        .brand h1 {
            margin: 0;
            font-size: 1.5rem;
            color: var(--ae-gold);
            font-weight: 900;
        }
        .brand p {
            margin: 5px 0 0;
            color: var(--ae-text-muted);
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .voucher-meta { text-align: left; }
        .voucher-meta .title { margin: 0; color: #fff; font-size: 2rem; font-weight: 900; }
        .voucher-meta .title-en { margin: 5px 0 10px; color: var(--ae-text-muted); font-size: 0.9rem; letter-spacing: 1px; text-transform: uppercase; }
        .voucher-meta .no { margin: 0; font-family: monospace; font-weight: 800; font-size: 1.2rem; color: var(--ae-gold); }

        .head-info {
            margin: 0 0 25px;
            border: 1px solid var(--ae-border);
            border-radius: 10px;
            background: #111;
            padding: 15px;
            color: var(--ae-text-muted);
            font-size: 0.9rem;
            line-height: 1.6;
            text-align: center;
        }

        /* شبكة البيانات */
        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 15px;
            margin-bottom: 25px;
        }
        .cell {
            background: #111;
            border: 1px solid var(--ae-border);
            border-radius: 10px;
            padding: 15px;
            transition: border-color 0.3s ease;
        }
        .cell:hover { border-color: var(--ae-gold); }
        .cell .label { font-size: 0.85rem; color: var(--ae-text-muted); margin-bottom: 5px; font-weight: 600; }
        .cell .value { font-weight: 800; color: #fff; font-size: 1.05rem; word-break: break-word; }

        /* مربع المبلغ */
        .amount-box {
            border: 2px dashed var(--ae-gold);
            background: linear-gradient(135deg, rgba(212, 175, 55, 0.05), #111);
            border-radius: 12px;
            padding: 25px;
            margin: 25px 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 15px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.3);
        }
        .amount-box .a-label { color: var(--ae-gold); font-weight: 800; font-size: 1.2rem; }
        .amount-box .a-value { font-size: 2.2rem; font-weight: 900; color: #fff; font-family: monospace; }

        /* الملاحظات والتوزيع */
        .notes {
            margin-top: 10px;
            border: 1px solid var(--ae-border);
            border-radius: 10px;
            padding: 15px;
            min-height: 80px;
            white-space: pre-wrap;
            line-height: 1.8;
            color: #ccc;
            background: #1a1a1a;
            font-size: 0.95rem;
        }
        .allocation-list {
            margin-top: 20px;
            border: 1px solid var(--ae-border);
            border-radius: 10px;
            background: #111;
            padding: 15px 20px;
        }
        .allocation-row {
            display: flex;
            justify-content: space-between;
            padding: 10px 0;
            border-bottom: 1px dashed #333;
            color: #ddd;
        }
        .allocation-row:last-child { border-bottom: none; }
        .allocation-row .amount { font-weight: 800; color: var(--ae-gold); font-family: monospace; font-size: 1.1rem; }

        /* التذييل والتوقيعات */
        .foot {
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            gap: 20px;
            margin-top: 40px;
            padding-top: 25px;
            border-top: 1px solid var(--ae-border);
        }
        .foot .stamp { color: var(--ae-text-muted); font-size: 0.85rem; line-height: 1.6; }
        .foot .stamp .org-name { font-weight: 800; color: var(--ae-gold); display: block; margin-top: 10px; font-size: 1rem; }
        .foot .stamp .qr-box { margin-top: 15px; }
        .foot .stamp .qr-box img { width: 100px; height: 100px; border: 1px solid var(--ae-gold); border-radius: 10px; background: #fff; padding: 5px; }
        
        .foot .sign { text-align: center; min-width: 250px; }
        .foot .sign .line {
            margin-top: 50px;
            border-top: 1px solid #555;
            padding-top: 10px;
            color: var(--ae-text-muted);
            font-size: 0.9rem;
            font-weight: 600;
        }

        /* شريط الإجراءات العائم (Action Bar) */
        .action-bar {
            position: fixed;
            bottom: 25px;
            left: 50%;
            transform: translateX(-50%);
            background: rgba(15, 15, 15, 0.9);
            border: 1px solid var(--ae-gold);
            border-radius: 50px;
            padding: 10px 15px;
            display: flex;
            gap: 15px;
            z-index: 999;
            backdrop-filter: blur(10px);
            box-shadow: 0 10px 30px rgba(0,0,0,0.7);
        }
        .ab-btn {
            border: none;
            border-radius: 50px;
            padding: 12px 25px;
            font-family: 'Cairo', sans-serif;
            font-weight: 800;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: 0.95rem;
            transition: all 0.3s ease;
        }
        .ab-btn.print { background: var(--ae-gold); color: #000; }
        .ab-btn.print:hover { background: var(--ae-gold-light); transform: translateY(-2px); }
        .ab-btn.back { background: #222; color: #fff; border: 1px solid #444; }
        .ab-btn.back:hover { border-color: var(--ae-gold); color: var(--ae-gold); }

        @media (max-width: 760px) {
            .sheet-body { padding: 25px 20px; }
            .head { flex-direction: column; align-items: flex-start; }
            .voucher-meta { text-align: right; width: 100%; }
            .grid { grid-template-columns: 1fr; }
            .amount-box { flex-direction: column; align-items: flex-start; gap: 10px; }
            .foot { flex-direction: column; align-items: stretch; gap: 40px; }
            .foot .sign { min-width: 100%; }
            .foot .stamp .qr-box { text-align: center; }
            .action-bar { width: 90%; justify-content: center; }
        }

        /* ========================================================= */
        /* إعدادات الطباعة (Print Mode) - أبيض وأسود رسمي لحفظ الحبر */
        /* ========================================================= */
        @media print {
            @page { size: A4; margin: 15mm; }
            body {
                background: #fff !important;
                padding: 0 !important;
                margin: 0 !important;
                color: #000 !important;
            }
            
            .sheet {
                box-shadow: none !important;
                border: none !important;
                border-radius: 0 !important;
                margin: 0 !important;
                max-width: 100% !important;
                background: #fff !important;
            }
            .sheet-top { display: none !important; }
            .sheet-body { padding: 0 !important; }
            
            .action-bar { display: none !important; }

            .head { border-bottom: 2px solid #000 !important; padding-bottom: 15px !important; margin-bottom: 20px !important; }
            .brand h1 { color: #000 !important; }
            .brand p { color: #555 !important; }
            .logo-fallback { background: transparent !important; color: #000 !important; border-color: #000 !important; }
            
            .voucher-meta .title { color: #000 !important; }
            .voucher-meta .title-en { color: #555 !important; }
            .voucher-meta .no { color: #000 !important; }

            .head-info { background: transparent !important; color: #000 !important; border-color: #000 !important; padding: 10px !important; }

            .cell { background: transparent !important; border: 1px solid #000 !important; border-radius: 0 !important; padding: 10px !important; }
            .cell .label { color: #333 !important; }
            .cell .value { color: #000 !important; }

            .amount-box { background: transparent !important; border: 2px dashed #000 !important; border-radius: 0 !important; padding: 15px !important; margin: 15px 0 !important; }
            .amount-box .a-label { color: #000 !important; }
            .amount-box .a-value { color: #000 !important; }

            .notes { background: transparent !important; border: 1px solid #000 !important; border-radius: 0 !important; color: #000 !important; }
            
            .allocation-list { background: transparent !important; border: 1px solid #000 !important; border-radius: 0 !important; }
            .allocation-row { border-bottom-color: #ccc !important; color: #000 !important; }
            .allocation-row .amount { color: #000 !important; }

            .foot { border-top: 1px solid #000 !important; padding-top: 15px !important; margin-top: 25px !important; }
            .foot .stamp { color: #000 !important; }
            .foot .stamp .org-name { color: #000 !important; }
            .foot .stamp .qr-box img { border-color: #000 !important; }
            .foot .sign .line { border-top-color: #000 !important; color: #000 !important; }

            /* 🌟 إعدادات منع قطع البيانات بين الصفحات (Pagination Control) 🌟 */
            .grid, .cell, .amount-box, .allocation-list, .notes, .foot {
                page-break-inside: avoid !important;
                break-inside: avoid !important;
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
    <div class="sheet">
        <div class="sheet-top"></div>
        <div class="sheet-body">
            
            <div class="head">
                <div class="brand">
                    <?php if ($outputShowLogo): ?>
                        <img src="<?php echo app_h($appLogo); ?>" alt="logo" onerror="this.style.display='none'">
                    <?php else: ?>
                        <div class="logo-fallback"><?php echo app_h(mb_substr((string)($brandProfile['org_name'] ?? $appName), 0, 2)); ?></div>
                    <?php endif; ?>
                    <div>
                        <h1><?php echo app_h((string)($brandProfile['org_name'] ?? $appName)); ?></h1>
                        <p>Financial Document</p>
                    </div>
                </div>
                <div class="voucher-meta">
                    <p class="title"><?php echo app_h($voucherTitle); ?></p>
                    <p class="title-en"><?php echo app_h($voucherTitleEn); ?></p>
                    <p class="no"><?php echo app_h($voucherNumber); ?></p>
                </div>
            </div>

            <?php if ($outputShowHeader && !empty($headerLines)): ?>
                <div class="head-info">
                    <?php foreach ($headerLines as $line): ?>
                        <div><?php echo app_h($line); ?></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <div class="grid">
                <div class="cell">
                    <div class="label">تاريخ السند</div>
                    <div class="value"><?php echo app_h($transDate); ?></div>
                </div>
                <div class="cell">
                    <div class="label">التصنيف</div>
                    <div class="value"><?php echo app_h($categoryText); ?></div>
                </div>
                <div class="cell">
                    <div class="label">نوع الضريبة</div>
                    <div class="value"><?php echo app_h($taxLawLabel !== '' ? $taxLawLabel : '-'); ?></div>
                </div>
                <div class="cell">
                    <div class="label"><?php echo app_h($counterpartyLabel); ?></div>
                    <div class="value"><?php echo app_h($counterpartyValue); ?></div>
                </div>
                <div class="cell">
                    <div class="label">الهاتف / وسيلة الاتصال</div>
                    <div class="value" style="direction: ltr; text-align: right;"><?php echo app_h($counterpartyPhone !== '' ? $counterpartyPhone : '-'); ?></div>
                </div>
                <div class="cell">
                    <div class="label">مرجع الحركة</div>
                    <div class="value"><?php echo app_h($referenceValue); ?></div>
                </div>
                <div class="cell">
                    <div class="label">تم التسجيل بواسطة</div>
                    <div class="value"><?php echo app_h($createdBy); ?></div>
                </div>
                <div class="cell">
                    <div class="label">حالة الرصيد بعد السند</div>
                    <div class="value" style="direction: ltr; text-align: right;">
                        <?php echo app_h((string)($balanceSummary['label'] ?? '-')); ?>
                        <?php echo isset($balanceSummary['amount']) ? ' - ' . number_format((float)$balanceSummary['amount'], 2) . ' ج.م' : ''; ?>
                    </div>
                </div>
                <div class="cell">
                    <div class="label">الموزع داخلياً</div>
                    <div class="value">
                        <?php echo number_format((float)($allocationSummary['allocated_amount'] ?? 0), 2); ?> ج.م
                        <?php if (($allocationSummary['unallocated_amount'] ?? 0) > 0.00001): ?> 
                            <br><span style="font-size:0.8rem; color:var(--ae-gold);"><?php echo app_h(app_tr('متبق غير موزع', 'Unallocated remainder')); ?>: <?php echo number_format((float)$allocationSummary['unallocated_amount'], 2); ?></span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="amount-box">
                <div class="a-label"><i class="fa-solid fa-money-bill-wave"></i> قيمة السند</div>
                <div class="a-value"><?php echo app_h($amountText); ?> ج.م</div>
            </div>

            <?php if (($allocationSummary['count'] ?? 0) > 0): ?>
                <div class="allocation-list">
                    <div style="color:var(--ae-gold); font-size:0.9rem; font-weight:bold; margin-bottom:10px;"><i class="fa-solid fa-list-check"></i> تفصيل التوزيع الداخلي:</div>
                    <?php foreach (($allocationSummary['lines'] ?? []) as $line): ?>
                        <div class="allocation-row">
                            <div><?php echo app_h((string)($line['label'] ?? '')); ?></div>
                            <div class="amount"><?php echo number_format((float)($line['amount'] ?? 0), 2); ?> ج.م</div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <div style="margin-top:20px;">
                <div style="color:var(--ae-gold); font-size:0.9rem; font-weight:bold; margin-bottom:5px;"><i class="fa-solid fa-pen-to-square"></i> البيان / الملاحظات:</div>
                <div class="notes"><?php echo app_h($notes !== '' ? $notes : '-'); ?></div>
            </div>

            <div class="foot">
                <div class="stamp">
                    <i class="fa-solid fa-hashtag"></i> رقم الحركة الداخلي: #<?php echo (int)$voucher['id']; ?><br>
                    <i class="fa-regular fa-clock"></i> وقت الطباعة: <?php echo app_h(date('Y-m-d H:i:s')); ?>
                    
                    <?php if ($outputShowFooter): ?>
                        <span class="org-line org-name"><?php echo app_h((string)($brandProfile['org_name'] ?? $appName)); ?></span>
                        <?php foreach ($footerLines as $line): ?>
                            <span class="org-line"><?php echo app_h($line); ?></span>
                        <?php endforeach; ?>
                        <?php if (trim((string)($brandProfile['org_footer_note'] ?? '')) !== ''): ?>
                            <span class="org-line" style="margin-top:8px; font-style:italic;"><?php echo app_h((string)$brandProfile['org_footer_note']); ?></span>
                        <?php endif; ?>
                    <?php endif; ?>
                    
                    <?php if ($qrUrl !== ''): ?>
                        <div class="qr-box">
                            <img src="<?php echo app_h($qrUrl); ?>" alt="QR Code Verification">
                        </div>
                    <?php endif; ?>
                </div>
                
                <div class="sign">
                    <div class="line">توقيع المستلم / المعتمد</div>
                </div>
            </div>
        </div>
    </div>

    <div class="action-bar">
        <button class="ab-btn print" onclick="window.print()"><i class="fa-solid fa-print"></i> طباعة / حفظ PDF</button>
        <a class="ab-btn back" href="finance.php"><i class="fa-solid fa-arrow-right"></i> رجوع للنظام</a>
    </div>

</body>
</html>
<?php ob_end_flush(); ?>