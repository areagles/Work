<?php
// view_invoice.php - (Royal View V3.0 - Unified Design & Direct QR)
ob_start();
require 'config.php'; 

$appName = app_setting_get($conn, 'app_name', 'Arab Eagles');
$appLogo = app_brand_logo_path($conn, 'assets/img/Logo.png');
$brandProfile = app_brand_profile($conn);
$outputShowFooter = !empty($brandProfile['show_footer']);
$outputShowQr = !empty($brandProfile['show_qr']);
$footerLines = app_brand_output_lines($brandProfile, 'footer', true);
$legacyFooterLine1 = trim(app_setting_get($conn, 'brand_footer_line1', $appName));
$legacyFooterLine2 = trim(app_setting_get($conn, 'brand_footer_line2', ''));
$legacyFooterLine3 = trim(app_setting_get($conn, 'brand_footer_line3', (string)parse_url(SYSTEM_URL, PHP_URL_HOST)));
if (empty($footerLines)) {
    $footerLines = array_values(array_filter([$legacyFooterLine1, $legacyFooterLine2, $legacyFooterLine3], static function ($line): bool {
        return trim((string)$line) !== '';
    }));
}

// 1. الأمان والتحقق
$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$token = $_GET['token'] ?? '';
$legacyToken = APP_LEGACY_INVOICE_SECRET !== '' ? md5($id . APP_LEGACY_INVOICE_SECRET) : null;

if ($id <= 0 || !app_verify_public_token('invoice_view', $id, $token, $legacyToken)) {
    die("<div style='height:100vh; display:flex; align-items:center; justify-content:center; background:#000; color:#d4af37; font-family:sans-serif;'>
            <div style='text-align:center;'>
                <h1 style='font-size:2rem; margin:0;'>تنبيه</h1>
                <h2 style='color:#fff;'>رابط غير صالح</h2>
            </div>
         </div>");
}

// 2. جلب البيانات
$stmt = $conn->prepare("SELECT i.*, c.name as client_name, c.phone as client_phone, c.address as client_address FROM invoices i JOIN clients c ON i.client_id=c.id WHERE i.id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$res = $stmt->get_result();
if (!$res || $res->num_rows === 0) {
    $stmt->close();
    die("الفاتورة غير موجودة");
}
$inv = $res->fetch_assoc();
$stmt->close();

$items = json_decode((string)($inv['items_json'] ?? ''), true);
if (!is_array($items)) {
    $items = [];
}
$invoiceTaxLines = app_tax_decode_lines((string)($inv['taxes_json'] ?? '[]'));

$statusRaw = (string)($inv['status'] ?? 'unpaid');
$stampClass = 'st-unpaid';
$stampText = 'UNPAID';
if ($statusRaw === 'paid') {
    $stampClass = 'st-paid';
    $stampText = 'PAID';
} elseif (in_array($statusRaw, ['partially_paid', 'partial'], true)) {
    $stampClass = 'st-partially';
    $stampText = 'PARTIAL';
}

$paidAmount = (float)($inv['paid_amount'] ?? 0);
$remainingAmount = (float)($inv['remaining_amount'] ?? ((float)($inv['total_amount'] ?? 0) - $paidAmount));
if ($remainingAmount < 0) {
    $remainingAmount = 0;
}
$invoiceRef = trim((string)($inv['invoice_number'] ?? ''));
if ($invoiceRef === '') {
    $invoiceRef = '#' . str_pad((string)$id, 4, '0', STR_PAD_LEFT);
}
$currentUrl = ((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? '') . ($_SERVER['REQUEST_URI'] ?? '');

// 🌟 التعديل هنا: جعل الـ QR يعتمد على الرابط المباشر فقط لكي يفتح عند المسح بالكاميرا 🌟
$qrPayload = $currentUrl;
// استخدام خدمة خارجية لضمان عمل الرابط بشكل مثالي ومباشر كـ URL
$qrUrl = ($outputShowQr && $qrPayload !== '') ? "https://api.qrserver.com/v1/create-qr-code/?size=150x150&margin=0&data=" . urlencode($qrPayload) : '';

$paymentMethods = app_payment_methods_config($conn);
$paymentRequestDefaultPercent = (float)app_setting_get($conn, 'payment_request_default_percent', '30');
$paymentRequestDefaultPercent = max(0.0, min(100.0, $paymentRequestDefaultPercent));
$paymentRequestDefaultNote = trim(app_setting_get($conn, 'payment_request_default_note', 'عربون'));
if ($paymentRequestDefaultNote === '') {
    $paymentRequestDefaultNote = 'عربون';
}
$suggestedDepositAmount = round((((float)$inv['total_amount']) * $paymentRequestDefaultPercent) / 100, 2);
if ($suggestedDepositAmount > $remainingAmount && $remainingAmount > 0) {
    $suggestedDepositAmount = $remainingAmount;
}
if ($suggestedDepositAmount <= 0 && $remainingAmount > 0) {
    $suggestedDepositAmount = $remainingAmount;
}
$paymentMethodsJson = json_encode($paymentMethods, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($paymentMethodsJson === false) {
    $paymentMethodsJson = '[]';
}
$backHref = 'javascript:history.back()';
$backLabel = 'رجوع';
$primaryPaymentLinkMethod = null;
foreach ($paymentMethods as $method) {
    $methodType = (string)($method['type'] ?? '');
    $methodValue = trim((string)($method['value'] ?? ''));
    if ($methodType === 'url' && $methodValue !== '') {
        $primaryPaymentLinkMethod = $method;
        break;
    }
}

// 3. معالجة الردود
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    if (!app_verify_csrf($_POST['_csrf_token'] ?? '')) {
        echo "<script>alert('انتهت صلاحية الجلسة، برجاء إعادة تحميل الصفحة.');</script>";
    } elseif ($_POST['action'] == 'approve') {
        $note = "\n[العميل]: تمت الموافقة عبر الرابط الإلكتروني.";
        $stmt_up = $conn->prepare("UPDATE invoices SET notes = CONCAT(IFNULL(notes, ''), ?) WHERE id = ?");
        $stmt_up->bind_param("si", $note, $id);
        $stmt_up->execute();
        $stmt_up->close();
        echo "<script>alert('شكراً لك! تم تأكيد الاستلام.'); window.location.reload();</script>";
    } elseif ($_POST['action'] == 'reject') {
        $reason = trim((string)($_POST['reason'] ?? ''));
        $reason = mb_substr($reason, 0, 1000);
        $note = "\n[ملاحظة عميل]: $reason";
        $stmt_up = $conn->prepare("UPDATE invoices SET notes = CONCAT(IFNULL(notes, ''), ?) WHERE id = ?");
        $stmt_up->bind_param("si", $note, $id);
        $stmt_up->execute();
        $stmt_up->close();
        echo "<script>alert('تم إرسال الملاحظات للإدارة.'); window.location.reload();</script>";
    }
}
?>
<!DOCTYPE html>
<html dir="rtl" lang="ar">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>فاتورة <?php echo app_h($invoiceRef); ?> - <?php echo app_h($appName); ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        /* --- المتغيرات الملكية --- */
        :root { 
            --bg-body: #050505; --card-bg: #121212; --gold: #d4af37; 
            --text-main: #ffffff; --text-sub: #aaaaaa; --border: #333;
        }

        body { background-color: var(--bg-body); color: var(--text-main); font-family: 'Cairo', sans-serif; margin: 0; padding: 15px; padding-bottom: 80px; }
        
        .container { 
            max-width: 800px; margin: 0 auto; 
            background: var(--card-bg); 
            border-radius: 15px; 
            box-shadow: 0 0 50px rgba(0,0,0,0.8);
            position: relative; 
            border: 1px solid var(--border);
            overflow: hidden;
        }
        
        .container::before {
            content: ''; position: absolute; top: 0; left: 0; right: 0; height: 4px;
            background: linear-gradient(90deg, var(--gold), #b8860b, var(--gold));
        }

        .invoice-box { padding: 40px; }
        .invoice-top-actions { display:flex; justify-content:flex-start; margin-bottom:18px; }
        .invoice-top-back {
            display:inline-flex; align-items:center; gap:8px; padding:10px 16px; border-radius:999px;
            text-decoration:none; background:rgba(255,255,255,0.04); border:1px solid rgba(212,175,55,0.35);
            color:var(--gold); font-weight:700; transition:0.3s;
        }
        .invoice-top-back:hover { transform:translateY(-2px); background:rgba(212,175,55,0.10); }

        /* --- الهيدر المركزي --- */
        .header { 
            display: flex; justify-content: space-between; align-items: center; 
            border-bottom: 1px solid var(--border); padding-bottom: 20px; margin-bottom: 30px; 
        }
        .header-side { flex: 1; text-align: center; }
        .header-side.right { text-align: right; display: flex; flex-direction: column; gap: 6px; }
        .header-side.left { text-align: left; }

        .invoice-title { font-size: 1.9rem; font-weight: 900; color: var(--gold); margin: 0; line-height: 1.15; }
        .invoice-id { font-size: 0.85rem; color: var(--text-sub); letter-spacing: 0.4px; }
        .logo-img { width: 180px; display: block; margin: 0 auto; }
        .date-item { font-size: 0.85rem; color: var(--text-sub); }
        .date-item strong, .invoice-id strong { color: #fff; }

        /* بيانات العميل */
        .client-section { margin-bottom: 30px; background: rgba(255,255,255,0.03); padding: 15px; border-radius: 10px; border-right: 3px solid var(--gold); }
        .section-label { font-size: 0.8rem; color: var(--gold); text-transform: uppercase; font-weight: bold; }
        .client-name { font-size: 1.3rem; font-weight: 700; margin: 5px 0; color: #fff; }
        .client-details { font-size: 0.9rem; color: var(--text-sub); }
        
        /* الجدول */
        .items-table { width: 100%; border-collapse: separate; border-spacing: 0; margin-bottom: 30px; }
        .items-table th { background: rgba(212, 175, 55, 0.1); color: var(--gold); padding: 12px; text-align: center; font-size: 0.9rem; border-bottom: 1px solid var(--gold); }
        .items-table td { padding: 12px; border-bottom: 1px solid var(--border); text-align: center; font-size: 0.95rem; color: #eee; }
        .items-table td.desc { text-align: right; width: 50%; color: #fff; }
        
        /* الإجماليات */
        .totals-area { 
            background: #0a0a0a; border: 1px solid var(--border); 
            border-radius: 10px; padding: 20px; margin-bottom: 30px;
        }
        .total-row { display: flex; justify-content: space-between; align-items: baseline; flex-wrap: wrap; row-gap: 4px; margin-bottom: 8px; font-size: 0.95rem; color: #ccc; }
        .final-total { 
            display: flex; justify-content: space-between; align-items: baseline; flex-wrap: wrap; row-gap: 4px;
            border-top: 1px dashed #444; padding-top: 10px; margin-top: 10px; 
            font-size: 1.2rem; font-weight: 800; color: var(--gold); 
        }
        .total-row > span:last-child,
        .final-total > span:last-child { white-space: nowrap; }

        /* وسائل الدفع */
        .payment-section {
            margin-bottom: 30px;
            background: #0b0b0b;
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 16px;
        }
        .payment-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
            gap: 10px;
            margin-top: 12px;
        }
        .payment-card {
            border: 1px solid #2e2e2e;
            border-radius: 10px;
            background: rgba(255,255,255,0.02);
            padding: 12px;
        }
        .payment-label {
            color: #fff;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 8px;
        }
        .payment-value {
            color: #d5d5d5;
            word-break: break-word;
            font-size: 0.88rem;
            margin-bottom: 10px;
            direction: ltr;
            text-align: left;
        }
        .payment-empty {
            color: #a3a3a3;
            font-size: 0.9rem;
            margin-top: 10px;
        }

        /* الفوتر */
        .footer { margin-top: 40px; padding-top: 20px; border-top: 1px solid var(--border); font-size: 0.8rem; color: var(--text-sub); }
        .footer-meta { display:flex; justify-content:space-between; align-items:flex-end; gap:14px; flex-wrap:wrap; }
        .footer-lines { flex:1; min-width:220px; text-align:right; }
        .footer-lines p { margin:0 0 4px; }
        .footer-lines p:last-child { margin-bottom:0; }
        .footer-qr img { width:90px; height:90px; border:1px solid #323232; border-radius:8px; background:#fff; padding:4px; }

        /* ختم الحالة */
        .status-stamp { 
            position: absolute; top: 150px; left: 50%; transform: translateX(-50%) rotate(-10deg);
            padding: 10px 40px; border: 4px double; font-weight: 900; text-transform: uppercase; 
            opacity: 0.15; font-size: 3rem; letter-spacing: 10px; pointer-events: none;
        }
        .st-paid { color: #2ecc71; border-color: #2ecc71; opacity: 0.3; }
        .st-unpaid { color: #c0392b; border-color: #c0392b; }
        .st-partially { color: #f39c12; border-color: #f39c12; }

        /* Actions Bar */
        .actions-bar {
            position: fixed; bottom: 20px; left: 50%; transform: translateX(-50%);
            background: rgba(18, 18, 18, 0.95); backdrop-filter: blur(10px);
            padding: 10px 20px; border-radius: 50px; width: 90%; max-width: 750px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.8); display: flex; gap: 10px; justify-content: center;
            border: 1px solid var(--gold); z-index: 1000; flex-wrap: wrap;
        }
        .btn { border: none; padding: 10px 20px; border-radius: 30px; font-weight: bold; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 8px; font-size: 0.9rem; font-family: 'Cairo'; color: #fff; transition: 0.3s; flex: 1; text-decoration: none; white-space: nowrap; }
        .btn:hover { transform: translateY(-3px); }
        
        .btn-pay-primary { background: linear-gradient(45deg, #d4af37, #9e7b16); color: #111; box-shadow: 0 0 18px rgba(212, 175, 55, 0.35); }
        .btn-pay-request { background: #34495e; }
        .btn-copy { background: #1f6feb; }
        .btn-neutral { background: #333; }
        .btn-approve { background: #27ae60; }
        .btn-reject { background: #c0392b; }

        /* Modal */
        .modal { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.9); z-index: 2000; justify-content: center; align-items: center; }
        .modal-content { background: #1a1a1a; border: 1px solid var(--gold); padding: 25px; border-radius: 15px; width: 90%; max-width: 400px; text-align: center; }
        .modal-content h3 { color: var(--gold); margin-top: 0; }
        .modal-field { text-align: right; margin: 10px 0; }
        .modal-label { font-size: 0.86rem; color: #cfcfcf; margin-bottom: 6px; display: block; }
        .modal-input, .modal-select {
            width: 100%;
            padding: 10px;
            border: 1px solid #444;
            border-radius: 8px;
            background: #050505;
            color: #fff;
            font-family: 'Cairo', sans-serif;
            outline: none;
        }
        .modal-input:focus, .modal-select:focus { border-color: var(--gold); }
        .method-meta {
            display: none;
            border: 1px dashed #444;
            border-radius: 8px;
            padding: 10px;
            margin: 10px 0;
            text-align: right;
        }
        .method-meta-value {
            direction: ltr;
            text-align: left;
            color: #ddd;
            font-size: 0.86rem;
            margin-bottom: 10px;
            word-break: break-all;
        }
        textarea { width: 100%; height: 100px; padding: 10px; margin: 15px 0; border: 1px solid #444; border-radius: 8px; background: #050505; color: #fff; font-family: 'Cairo'; outline: none; }
        textarea:focus { border-color: var(--gold); }

        /* --- الموبايل --- */
        @media (max-width: 768px) {
            .invoice-box { padding: 20px 15px; }
            .invoice-top-actions { margin-bottom:14px; }
            .header { flex-direction: column; text-align: center; gap: 15px; }
            .header-side { width: 100%; text-align: center !important; }
            .header-side.center { order: -1; }
            .logo-img { width: 60px; }
            
            .items-table { display: block; overflow-x: auto; white-space: nowrap; }
            .actions-bar { flex-wrap: wrap; border-radius: 20px; padding: 15px; }
            .btn { font-size: 0.8rem; padding: 12px; }
        }

        /* --- الطباعة --- */
        @media print {
            @page { size: A4; margin: 10mm; }
            body { background: #fff !important; color: #000 !important; padding: 0 !important; margin: 0 !important; }
            .container { box-shadow: none !important; border: none !important; margin: 0 !important; max-width: 100% !important; width: 100% !important; background: #fff !important; color: #000 !important; border-radius: 0 !important; }
            .container::before { display: none !important; }
            
            .header {
                border-bottom: 2.5px solid #000 !important;
                flex-direction: row !important;
                align-items: center !important;
                gap: 16px !important;
                padding-bottom: 16px !important;
                margin-bottom: 22px !important;
            }
            .header-side { text-align: inherit !important; }
            .header-side.right { text-align: right !important; }
            .header-side.center {
                border-right: 1px solid #ccc !important;
                border-left: 1px solid #ccc !important;
                padding: 0 16px !important;
            }
            .invoice-title, .section-label { color: #000 !important; text-shadow: none !important; }
            .invoice-title { font-size: 1.7rem !important; }
            .invoice-id, .date-item, .client-name, .client-details { color: #000 !important; }
            .invoice-id { opacity: 0.75 !important; }
            .logo-img { width: 165px !important; height: auto !important; max-height: 165px !important; object-fit: contain !important; }
            
            .client-section { background: #fff !important; border: 1px solid #ddd !important; border-right: 4px solid #000 !important; }
            .items-table th { background: #f0f0f0 !important; color: #000 !important; border-bottom: 2px solid #000 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .items-table td { color: #000 !important; border-bottom: 1px solid #ddd !important; }
            
            .totals-area { background: #fff !important; border: 1px solid #000 !important; color: #000 !important; }
            .total-row, .final-total { color: #000 !important; }
            
            .payment-section { background: #fff !important; border: 1px solid #000 !important; }
            .payment-label, .payment-value, .payment-empty { color: #000 !important; }
            
            .footer { color: #000 !important; border-top: 1px solid #ccc !important; }
            .footer-qr img { border-color:#aaa !important; }
            
            /* إخفاء الأزرار عند الطباعة */
            .actions-bar, .modal, .btn-pay-primary, .btn-copy, .invoice-top-actions { display: none !important; }
            .status-stamp { opacity: 0.1 !important; color: #000 !important; border-color: #000 !important; }
            
            /* منع انقسام الجداول بين الصفحات */
            .items-table, .totals-area, .payment-section, .footer, tr {
                page-break-inside: avoid !important;
                break-inside: avoid !important;
            }
            thead { display: table-header-group !important; }
            tfoot { display: table-footer-group !important; }
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
        <div class="invoice-box">
            <div class="invoice-top-actions no-print">
                <a href="<?php echo app_h($backHref); ?>" class="invoice-top-back">
                    <i class="fa-solid fa-arrow-right"></i> <?php echo app_h($backLabel); ?>
                </a>
            </div>
        
        <div class="status-stamp <?php echo $stampClass; ?>"><?php echo $stampText; ?></div>

        <div class="header">
            <div class="header-side right">
                <div class="date-item"><strong>التاريخ:</strong> <?php echo $inv['inv_date']; ?></div>
                <div class="invoice-id"><strong>رقم الفاتورة:</strong> <?php echo app_h($invoiceRef); ?></div>
            </div>
            <div class="header-side center">
                <h1 class="invoice-title">فاتورة مبيعات</h1>
            </div>
            <div class="header-side left">
                <img src="<?php echo app_h($appLogo); ?>" alt="<?php echo app_h($appName); ?>" class="logo-img">
            </div>
        </div>

        <div class="client-section">
            <div class="section-label">فاتورة إلى:</div>
            <h2 class="client-name"><?php echo app_h($inv['client_name']); ?></h2>
            <p class="client-details">
                <i class="fa-solid fa-phone"></i> <?php echo app_h($inv['client_phone']); ?> 
                <?php if(!empty($inv['client_address'])) echo " | " . app_h($inv['client_address']); ?>
            </p>
        </div>

        <table class="items-table">
            <thead>
                <tr>
                    <th width="5%">#</th>
                    <th width="50%">البيان</th>
                    <th width="15%">الكمية / الوحدة</th>
                    <th width="15%">السعر</th>
                    <th width="15%">الإجمالي</th>
                </tr>
            </thead>
            <tbody>
                <?php $count=1; foreach($items as $item): ?>
                <tr>
                    <td><?php echo $count++; ?></td>
                    <td class="desc"><?php echo app_h($item['desc'] ?? ''); ?></td>
                    <?php
                        $unitVal = trim((string)($item['unit'] ?? ''));
                        $qtyLabel = number_format((float)($item['qty'] ?? 0), 2);
                        if ($unitVal !== '') {
                            $qtyLabel .= ' ' . app_h($unitVal);
                        }
                    ?>
                    <td><?php echo $qtyLabel; ?></td>
                    <td><?php echo number_format((float)($item['price'] ?? 0), 2); ?></td>
                    <td><?php echo number_format((float)($item['total'] ?? 0), 2); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <div class="totals-area">
            <div class="total-row">
                <span>المجموع الفرعي</span>
                <span><?php echo number_format((float)$inv['sub_total'], 2); ?></span>
            </div>
            <?php if($inv['discount'] > 0): ?>
            <div class="total-row" style="color:#c0392b;">
                <span>خصم</span>
                <span>-<?php echo number_format((float)$inv['discount'], 2); ?></span>
            </div>
            <?php endif; ?>
            <?php foreach ($invoiceTaxLines as $taxLine): ?>
            <?php
                $taxName = (string)($taxLine['name'] ?? 'ضريبة');
                $taxAmount = (float)($taxLine['amount'] ?? 0);
                $taxMode = (string)($taxLine['mode'] ?? 'add');
                $taxColor = ($taxMode === 'subtract') ? '#ff9f9f' : 'var(--gold)';
                $taxPrefix = ($taxMode === 'subtract') ? '-' : '+';
            ?>
            <div class="total-row" style="color:<?php echo $taxColor; ?>;">
                <span><?php echo app_h($taxName); ?></span>
                <span><?php echo $taxPrefix . number_format($taxAmount, 2); ?></span>
            </div>
            <?php endforeach; ?>
            <div class="final-total">
                <span>الإجمالي النهائي</span>
                <span><?php echo number_format((float)$inv['total_amount'], 2); ?> <small>EGP</small></span>
            </div>
            <div class="total-row" style="margin-top:12px; color:#2ecc71; font-weight:700;">
                <span>المسدّد</span>
                <span><?php echo number_format($paidAmount, 2); ?> <small>EGP</small></span>
            </div>
            <div class="total-row" style="color:#e74c3c; font-weight:800;">
                <span>المتبقي بعد التسوية</span>
                <span><?php echo number_format($remainingAmount, 2); ?> <small>EGP</small></span>
            </div>
        </div>

        <div class="payment-section">
            <div class="section-label">وسائل الدفع المتاحة:</div>
            <?php if (!empty($paymentMethods)): ?>
            <div class="payment-grid">
                <?php foreach ($paymentMethods as $method): ?>
                    <?php
                    $methodLabel = (string)($method['label'] ?? 'Payment');
                    $methodIcon = (string)($method['icon'] ?? 'fa-money-bill-wave');
                    $methodType = (string)($method['type'] ?? 'text');
                    $methodValue = (string)($method['value'] ?? '');
                    ?>
                    <div class="payment-card">
                        <div class="payment-label">
                            <i class="fa-solid <?php echo app_h($methodIcon); ?>"></i>
                            <span><?php echo app_h($methodLabel); ?></span>
                        </div>
                        <div class="payment-value"><?php echo app_h($methodValue); ?></div>
                        <?php if ($methodType === 'url'): ?>
                            <a class="btn btn-pay-primary no-print" style="padding:8px 12px; font-size:0.8rem;" href="<?php echo app_h($methodValue); ?>" target="_blank" rel="noopener">
                                <i class="fa-solid fa-up-right-from-square"></i> فتح الرابط
                            </a>
                        <?php else: ?>
                            <button type="button" class="btn btn-copy js-copy-payment-value no-print" style="padding:8px 12px; font-size:0.8rem;" data-copy-value="<?php echo app_h($methodValue); ?>">
                                <i class="fa-solid fa-copy"></i> نسخ الرقم
                            </button>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <div class="payment-empty">لا توجد وسائل دفع مفعلة حالياً.</div>
            <?php endif; ?>
        </div>

        <?php if(!empty($inv['notes'])): ?>
        <div style="margin-top:20px; font-size:0.9rem; color:var(--text-sub); border-top:1px dashed #333; padding-top:10px;">
            <strong style="color:var(--gold);">ملاحظات:</strong> <?php echo nl2br(app_h($inv['notes'])); ?>
        </div>
        <?php endif; ?>

        <?php if ($outputShowFooter): ?>
        <div class="footer">
            <div class="footer-meta">
                <div class="footer-lines">
                    <p style="font-weight:bold;"><?php echo app_h((string)($brandProfile['org_name'] ?? $appName)); ?></p>
                    <?php foreach ($footerLines as $line): ?>
                        <p><?php echo app_h($line); ?></p>
                    <?php endforeach; ?>
                    <?php if (trim((string)($brandProfile['org_footer_note'] ?? '')) !== ''): ?>
                        <p style="margin-top:8px; font-style:italic;"><?php echo app_h((string)$brandProfile['org_footer_note']); ?></p>
                    <?php endif; ?>
                </div>
                <?php if ($qrUrl !== ''): ?>
                <div class="footer-qr">
                    <img src="<?php echo app_h($qrUrl); ?>" alt="QR">
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

    </div>
</div>

<div class="actions-bar no-print">
    <?php if ($primaryPaymentLinkMethod !== null): ?>
    <a href="<?php echo app_h((string)($primaryPaymentLinkMethod['value'] ?? '')); ?>" target="_blank" rel="noopener noreferrer" referrerpolicy="no-referrer" class="btn btn-pay-primary">
        <i class="fa-solid <?php echo app_h((string)($primaryPaymentLinkMethod['icon'] ?? 'fa-credit-card')); ?>"></i>
        دفع <?php echo app_h((string)($primaryPaymentLinkMethod['label'] ?? 'Online')); ?>
    </a>
    <?php endif; ?>
    <?php if (!empty($paymentMethods)): ?>
    <button type="button" class="btn btn-pay-request" onclick="openPaymentRequestModal()">
        <i class="fa-solid fa-money-check-dollar"></i> طلب دفع
    </button>
    <?php endif; ?>
    
    <button type="button" class="btn btn-neutral" onclick="window.print()">
        <i class="fa-solid fa-print"></i> طباعة / حفظ PDF
    </button>
    
    <form method="POST" style="display:contents;">
        <?php echo app_csrf_input(); ?>
        <button type="submit" name="action" value="approve" class="btn btn-approve" onclick="return confirm('تأكيد استلام الفاتورة؟')">
            <i class="fa-solid fa-check-circle"></i> موافقة
        </button>
    </form>
    
    <button type="button" class="btn btn-reject" onclick="document.getElementById('rejModal').style.display='flex'">
        <i class="fa-solid fa-triangle-exclamation"></i> ملاحظة
    </button>
</div>

<?php if (!empty($paymentMethods)): ?>
<div id="payReqModal" class="modal">
    <div class="modal-content">
        <h3><i class="fa-solid fa-money-check-dollar"></i> طلب دفع منفصل</h3>
        <div class="modal-field">
            <label class="modal-label" for="payReqMethod">وسيلة الدفع</label>
            <select id="payReqMethod" class="modal-select">
                <?php foreach ($paymentMethods as $method): ?>
                    <option value="<?php echo app_h((string)($method['key'] ?? '')); ?>">
                        <?php echo app_h((string)($method['label'] ?? 'Payment')); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="modal-field">
            <label class="modal-label" for="payReqAmount">المبلغ المطلوب (EGP)</label>
            <input id="payReqAmount" class="modal-input" type="number" min="0" step="0.01" value="<?php echo app_h(number_format((float)$suggestedDepositAmount, 2, '.', '')); ?>">
        </div>
        <div class="modal-field">
            <label class="modal-label" for="payReqNote">وصف الطلب</label>
            <input id="payReqNote" class="modal-input" value="<?php echo app_h($paymentRequestDefaultNote); ?>" placeholder="عربون">
        </div>
        <div id="payMethodMeta" class="method-meta">
            <div id="payMethodValue" class="method-meta-value"></div>
            <div style="display:flex; gap:10px;">
                <button type="button" id="copyMethodValueBtn" class="btn btn-copy" style="flex:1;">
                    <i class="fa-solid fa-copy"></i> نسخ وسيلة الدفع
                </button>
                <a id="openMethodLinkBtn" class="btn btn-pay-primary" style="flex:1; display:none;" target="_blank" rel="noopener">
                    <i class="fa-solid fa-up-right-from-square"></i> فتح الرابط
                </a>
            </div>
        </div>
        <div class="modal-field">
            <label class="modal-label" for="payReqMessage">نص الطلب</label>
            <textarea id="payReqMessage" readonly></textarea>
        </div>
        <div style="display:flex; gap:10px; flex-wrap:wrap;">
            <button type="button" class="btn btn-copy" style="flex:1;" onclick="copyPaymentMessage()">
                <i class="fa-solid fa-copy"></i> نسخ الرسالة
            </button>
            <button type="button" class="btn btn-pay-primary" style="flex:1;" onclick="sendPaymentWhatsapp()">
                <i class="fa-brands fa-whatsapp"></i> إرسال واتساب
            </button>
            <button type="button" class="btn btn-neutral" style="flex:1;" onclick="closePaymentRequestModal()">
                إغلاق
            </button>
        </div>
    </div>
</div>
<?php endif; ?>

<div id="rejModal" class="modal">
    <div class="modal-content">
        <h3><i class="fa-solid fa-pen-to-square"></i> إضافة ملاحظة</h3>
        <form method="POST">
            <?php echo app_csrf_input(); ?>
            <input type="hidden" name="action" value="reject">
            <textarea name="reason" required placeholder="هل هناك أي خطأ في الفاتورة؟ اكتبه هنا..."></textarea>
            <div style="display:flex; gap:10px;">
                <button class="btn btn-reject" style="flex:1;">إرسال</button>
                <button type="button" class="btn btn-neutral" style="flex:1;" onclick="document.getElementById('rejModal').style.display='none'">إلغاء</button>
            </div>
        </form>
    </div>
</div>

<script>
(function () {
    function copyTextToClipboard(text) {
        const value = String(text || '').trim();
        if (value === '') {
            return Promise.reject(new Error('empty'));
        }
        if (navigator.clipboard && navigator.clipboard.writeText) {
            return navigator.clipboard.writeText(value);
        }
        return new Promise(function (resolve, reject) {
            const input = document.createElement('textarea');
            input.value = value;
            input.style.position = 'fixed';
            input.style.opacity = '0';
            document.body.appendChild(input);
            input.focus();
            input.select();
            try {
                const ok = document.execCommand('copy');
                document.body.removeChild(input);
                if (ok) {
                    resolve();
                } else {
                    reject(new Error('failed'));
                }
            } catch (err) {
                document.body.removeChild(input);
                reject(err);
            }
        });
    }

    document.querySelectorAll('.js-copy-payment-value').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const value = btn.getAttribute('data-copy-value') || '';
            copyTextToClipboard(value).then(function () {
                alert('تم نسخ وسيلة الدفع.');
            }).catch(function () {
                alert('تعذر نسخ وسيلة الدفع.');
            });
        });
    });

    const paymentMethods = <?php echo $paymentMethodsJson; ?>;
    if (!Array.isArray(paymentMethods) || paymentMethods.length === 0) {
        return;
    }

    const invoiceRef = <?php echo json_encode($invoiceRef, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
    const clientName = <?php echo json_encode((string)($inv['client_name'] ?? ''), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
    const clientPhone = <?php echo json_encode((string)($inv['client_phone'] ?? ''), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
    const invoiceTotal = Number(<?php echo json_encode((float)$inv['total_amount']); ?>);
    const invoiceRemaining = Number(<?php echo json_encode((float)$remainingAmount); ?>);

    const payReqModal = document.getElementById('payReqModal');
    const payReqMethod = document.getElementById('payReqMethod');
    const payReqAmount = document.getElementById('payReqAmount');
    const payReqNote = document.getElementById('payReqNote');
    const payMethodMeta = document.getElementById('payMethodMeta');
    const payMethodValue = document.getElementById('payMethodValue');
    const copyMethodValueBtn = document.getElementById('copyMethodValueBtn');
    const openMethodLinkBtn = document.getElementById('openMethodLinkBtn');
    const payReqMessage = document.getElementById('payReqMessage');
    const rejModal = document.getElementById('rejModal');

    function formatAmount(value) {
        return Number(value || 0).toFixed(2);
    }

    function normalizeWhatsappPhone(raw) {
        let digits = String(raw || '').replace(/\D/g, '');
        if (!digits) {
            return '';
        }
        if (digits.indexOf('00') === 0) {
            digits = digits.slice(2);
        }
        if (digits.length === 11 && digits[0] === '0') {
            return '2' + digits.slice(1);
        }
        if (digits.length === 12 && digits.indexOf('20') === 0) {
            return digits;
        }
        return digits;
    }

    function getMethodByKey(key) {
        return paymentMethods.find(function (method) { return method.key === key; }) || paymentMethods[0];
    }

    function currentAmountValue() {
        const value = parseFloat(payReqAmount.value);
        if (!Number.isFinite(value) || value < 0) {
            return 0;
        }
        return value;
    }

    function buildRequestText() {
        const method = getMethodByKey(payReqMethod.value);
        const amount = currentAmountValue();
        const note = String(payReqNote.value || 'عربون').trim() || 'عربون';
        const lines = [];
        lines.push('السيد/ة ' + clientName + '،');
        lines.push('يرجى سداد ' + note + ' بقيمة ' + formatAmount(amount) + ' EGP للفاتورة ' + invoiceRef + '.');
        lines.push('إجمالي الفاتورة: ' + formatAmount(invoiceTotal) + ' EGP.');
        lines.push('المتبقي الحالي: ' + formatAmount(invoiceRemaining) + ' EGP.');
        lines.push('وسيلة الدفع: ' + method.label + '.');
        if (method.type === 'url') {
            lines.push('رابط الدفع: ' + method.value);
        } else {
            lines.push('بيانات الدفع: ' + method.value);
        }
        lines.push('شكراً لكم.');
        return lines.join('\n');
    }

    function refreshMethodMeta() {
        const method = getMethodByKey(payReqMethod.value);
        if (!method) {
            payMethodMeta.style.display = 'none';
            return;
        }
        payMethodMeta.style.display = 'block';
        payMethodValue.textContent = method.value || '';
        openMethodLinkBtn.style.display = method.type === 'url' ? 'flex' : 'none';
        if (method.type === 'url') {
            openMethodLinkBtn.href = method.value || '#';
        } else {
            openMethodLinkBtn.removeAttribute('href');
        }
    }

    function refreshPaymentMessage() {
        payReqMessage.value = buildRequestText();
    }

    window.openPaymentRequestModal = function () {
        if (!payReqModal) {
            return;
        }
        payReqModal.style.display = 'flex';
        refreshMethodMeta();
        refreshPaymentMessage();
    };

    window.closePaymentRequestModal = function () {
        if (!payReqModal) {
            return;
        }
        payReqModal.style.display = 'none';
    };

    window.copyPaymentMessage = function () {
        copyTextToClipboard(payReqMessage.value).then(function () {
            alert('تم نسخ رسالة طلب الدفع.');
        }).catch(function () {
            alert('تعذر نسخ الرسالة.');
        });
    };

    window.sendPaymentWhatsapp = function () {
        const message = String(payReqMessage.value || '').trim();
        const phone = normalizeWhatsappPhone(clientPhone);
        if (!phone) {
            alert('لا يوجد رقم هاتف صالح للعميل لإرسال واتساب.');
            return;
        }
        const waUrl = 'https://wa.me/' + phone + '?text=' + encodeURIComponent(message);
        window.open(waUrl, '_blank');
    };

    if (copyMethodValueBtn) {
        copyMethodValueBtn.addEventListener('click', function () {
            const method = getMethodByKey(payReqMethod.value);
            copyTextToClipboard(method ? method.value : '').then(function () {
                alert('تم نسخ وسيلة الدفع.');
            }).catch(function () {
                alert('تعذر نسخ وسيلة الدفع.');
            });
        });
    }

    if (payReqMethod) {
        payReqMethod.addEventListener('change', function () {
            refreshMethodMeta();
            refreshPaymentMessage();
        });
    }
    if (payReqAmount) {
        payReqAmount.addEventListener('input', refreshPaymentMessage);
        payReqAmount.addEventListener('change', refreshPaymentMessage);
    }
    if (payReqNote) {
        payReqNote.addEventListener('input', refreshPaymentMessage);
        payReqNote.addEventListener('change', refreshPaymentMessage);
    }

    window.addEventListener('click', function (event) {
        if (payReqModal && event.target === payReqModal) {
            closePaymentRequestModal();
        }
        if (rejModal && event.target === rejModal) {
            rejModal.style.display = 'none';
        }
    });

    refreshMethodMeta();
    refreshPaymentMessage();
})();
</script>

</body>
</html>