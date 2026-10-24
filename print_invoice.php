<?php
// print_invoice.php - (Royal Print Engine V5.0 - PDF Bulletproof & Direct QR)

ob_start();
error_reporting(E_ALL);

require 'auth.php'; 
require 'config.php';
require_once __DIR__ . '/modules/finance/fx_widget_runtime.php';

$appName = app_setting_get($conn, 'app_name', 'Arab Eagles');
$appLogo = app_brand_logo_path($conn, 'assets/img/Logo.png');
$brandProfile = app_brand_profile($conn);
$outputTheme = app_brand_output_theme($conn);
$outputShowHeader = !empty($brandProfile['show_header']);
$outputShowFooter = !empty($brandProfile['show_footer']);
$outputShowLogo = !empty($brandProfile['show_logo']);
$outputShowQr = !empty($brandProfile['show_qr']);
$headerLines = app_brand_output_lines($brandProfile, 'header', true);
$footerLines = app_brand_output_lines($brandProfile, 'footer', true);
$legacyFooterLine1 = trim(app_setting_get($conn, 'brand_footer_line1', $appName));
$legacyFooterLine2 = trim(app_setting_get($conn, 'brand_footer_line2', ''));
$legacyFooterLine3 = trim(app_setting_get($conn, 'brand_footer_line3', (string)parse_url(SYSTEM_URL, PHP_URL_HOST)));
if (empty($footerLines)) {
    $footerLines = array_values(array_filter([$legacyFooterLine1, $legacyFooterLine2, $legacyFooterLine3], static function ($line): bool {
        return trim((string)$line) !== '';
    }));
}

if(!isset($_GET['id'])) die("<div style='text-align:center; padding:50px; background:#0a0a0a; color:#fff; height:100vh; font-family:sans-serif;'><h2><i class='fa-solid fa-triangle-exclamation' style='color:#d4af37;'></i> رقم الفاتورة مفقود!</h2></div>");

$id = intval($_GET['id']);
$stmtInv = $conn->prepare("SELECT i.*, c.name as client_name, c.phone as client_phone, c.address as client_address FROM invoices i JOIN clients c ON i.client_id=c.id WHERE i.id = ?");
$stmtInv->bind_param('i', $id);
$stmtInv->execute();
$res = $stmtInv->get_result();

if(!$res || $res->num_rows == 0) die("<div style='text-align:center; padding:50px; font-family:sans-serif; background:#0a0a0a; color:#d4af37; height:100vh;'><h2><i class='fa-solid fa-file-circle-xmark'></i> الفاتورة غير موجودة.</h2><br><a href='invoices.php' style='color:#000; background:#d4af37; text-decoration:none; padding:10px 20px; border-radius:5px; font-weight:bold;'>العودة للسجل</a></div>");

$inv = $res->fetch_assoc();
$stmtInv->close();

$itemsRaw = $inv['items_json'];
$items = [];

if($itemsRaw) {
    $decoded = json_decode($itemsRaw, true);
    if(is_string($decoded)) $decoded = json_decode($decoded, true);
    if(is_array($decoded)) $items = $decoded;
}
$invoiceTaxLines = app_tax_decode_lines((string)($inv['taxes_json'] ?? '[]'));

$sub_total = isset($inv['sub_total']) ? floatval($inv['sub_total']) : 0;
$tax = isset($inv['tax']) ? floatval($inv['tax']) : 0;
$discount = isset($inv['discount']) ? floatval($inv['discount']) : 0;
$total = floatval($inv['total_amount']);
$paid = floatval($inv['paid_amount']);
$remaining = isset($inv['remaining_amount']) ? floatval($inv['remaining_amount']) : ($total - $paid);
if ($remaining < 0) {
    $remaining = 0;
}
$invoiceCurrencyCode = strtoupper(trim((string)($inv['currency_code'] ?? 'EGP')));
if ($invoiceCurrencyCode === '') {
    $invoiceCurrencyCode = 'EGP';
}
$invoiceMoney = static function (float $value) use ($invoiceCurrencyCode): string {
    return app_quote_format_money($value, $invoiceCurrencyCode);
};

if ($sub_total == 0 && $total > 0) {
    $sub_total = $total - $tax + $discount;
}

$statusRaw = strtolower(trim((string)($inv['status'] ?? 'unpaid')));
$stamp_text = "غير مدفوع";
$stamp_class = "st-rejected";
if ($statusRaw === 'paid' || $remaining <= 0.00001) {
    $stamp_text = "خالص الدفع";
    $stamp_class = "st-approved";
} elseif (in_array($statusRaw, ['partially_paid', 'partial'], true) || $paid > 0) {
    $stamp_text = "دفعة جزئية";
    $stamp_class = "st-pending";
}

$token = app_public_token('invoice_view', $id);
$basePath = rtrim(str_replace('\\', '/', dirname($_SERVER['PHP_SELF'])), '/');
if ($basePath === '.' || $basePath === '/') { $basePath = ''; }
$public_link = app_base_url() . $basePath . "/view_invoice.php?id=$id&type=sales&token=$token";
$inv['invoice_number'] = $inv['invoice_number'] ?? '';
if ((string)$inv['invoice_number'] === '') {
    $inv['invoice_number'] = app_assign_document_number($conn, 'invoices', (int)$id, 'invoice_number', 'invoice', (string)($inv['inv_date'] ?? date('Y-m-d')));
}
$invoiceRef = trim((string)($inv['invoice_number'] ?? ''));
if ($invoiceRef === '') {
    $invoiceRef = '#' . str_pad((string)$id, 4, '0', STR_PAD_LEFT);
}
$wa_msg = "السيد/ة {$inv['client_name']}\nرابط الفاتورة الصادرة من {$appName}:\n$public_link";

// 🌟 التعديل هنا: جعل الـ QR يعتمد على الرابط المباشر فقط لكي يفتح عند المسح بالكاميرا 🌟
$qrPayload = $public_link;
// استخدام خدمة خارجية لضمان عمل الرابط بشكل مثالي ومباشر كـ URL
$qrUrl = ($outputShowQr && $qrPayload !== '') ? "https://api.qrserver.com/v1/create-qr-code/?size=150x150&margin=0&data=" . urlencode($qrPayload) : '';

$invoiceDateLabel = trim((string)($inv['inv_date'] ?? ''));
$clientName = trim((string)($inv['client_name'] ?? 'عميل غير محدد'));
$clientPhone = trim((string)($inv['client_phone'] ?? ''));
$clientAddress = trim((string)($inv['client_address'] ?? ''));
$invoiceNotes = trim((string)($inv['notes'] ?? ''));
$itemsCount = count($items);
$taxAddAmount = 0.0;
$taxSubtractAmount = 0.0;
foreach ($invoiceTaxLines as $taxLine) {
    $amount = (float)($taxLine['amount'] ?? 0);
    if (((string)($taxLine['mode'] ?? 'add')) === 'subtract') {
        $taxSubtractAmount += $amount;
    } else {
        $taxAddAmount += $amount;
    }
}
$statusUiMap = [
    'st-approved' => ['label' => 'خالص الدفع', 'desc' => 'تم سداد الفاتورة بالكامل وأصبحت مغلقة ماليًا.'],
    'st-pending' => ['label' => 'دفعة جزئية', 'desc' => 'الفاتورة مسدد منها جزء وما زال عليها رصيد مفتوح.'],
    'st-rejected' => ['label' => 'غير مدفوع', 'desc' => 'الفاتورة ما زالت مفتوحة بالكامل ولم يتم تحصيلها بعد.'],
];
$statusUi = $statusUiMap[$stamp_class] ?? $statusUiMap['st-rejected'];
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>فاتورة <?php echo app_h($invoiceRef); ?> | <?php echo app_h($appName); ?></title>
    
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        :root {
            --bg-body: <?php echo app_h($outputTheme['bg']); ?>;
            --card-bg: <?php echo app_h($outputTheme['card']); ?>;
            --gold: <?php echo app_h($outputTheme['accent']); ?>;
            --gold-soft: <?php echo app_h($outputTheme['accent_soft']); ?>;
            --paper: <?php echo app_h($outputTheme['paper']); ?>;
            --ink: <?php echo app_h($outputTheme['ink']); ?>;
            --line: <?php echo app_h($outputTheme['line']); ?>;
            --tint: <?php echo app_h($outputTheme['tint']); ?>;
            --border: rgba(255,255,255,0.10);
            --surface: rgba(255,255,255,0.04);
            --text-main: #f7f2e8;
            --text-sub: #b6aa96;
            --text-soft: #8f8576;
            --ok: #44c173;
            --danger: #df5a57;
            --warn: #dba73a;
            --shadow: 0 28px 72px rgba(0,0,0,0.42);
        }

        * { box-sizing: border-box; }
        body {
            margin: 0;
            padding: 22px;
            min-height: 100vh;
            font-family: 'Cairo', sans-serif;
            color: var(--text-main);
            background:
                radial-gradient(circle at top right, rgba(212,175,55,0.14), transparent 24%),
                radial-gradient(circle at top left, rgba(255,255,255,0.05), transparent 18%),
                linear-gradient(180deg, color-mix(in srgb, var(--bg-body) 88%, #000 12%), var(--bg-body));
        }

        .container {
            position: relative;
            max-width: 980px;
            margin: 0 auto 110px;
            background:
                linear-gradient(180deg, rgba(255,255,255,0.02), rgba(255,255,255,0)),
                var(--card-bg);
            border: 1px solid var(--border);
            border-radius: 30px;
            box-shadow: var(--shadow);
            overflow: hidden;
        }
        .container::before {
            content: '';
            position: absolute;
            inset: 0 0 auto;
            height: 5px;
            background: linear-gradient(90deg, transparent 0%, var(--gold) 16%, #fff2be 52%, var(--gold) 84%, transparent 100%);
        }
        .container::after {
            content: '';
            position: absolute;
            inset: 24px auto auto 24px;
            width: 180px;
            height: 180px;
            background: radial-gradient(circle, rgba(212,175,55,0.14), transparent 70%);
            filter: blur(24px);
            pointer-events: none;
        }

        .invoice-box { position: relative; z-index: 1; padding: 38px 42px 42px; }
        .status-stamp {
            position: absolute;
            top: 118px;
            left: 50%;
            transform: translateX(-50%) rotate(-11deg);
            padding: 10px 34px;
            border: 4px double currentColor;
            border-radius: 18px;
            font-size: 2.5rem;
            font-weight: 900;
            letter-spacing: 9px;
            text-transform: uppercase;
            opacity: 0.08;
            pointer-events: none;
            white-space: nowrap;
            z-index: 0;
        }
        .st-pending { color: var(--warn); }
        .st-approved { color: var(--ok); opacity: 0.12; }
        .st-rejected { color: var(--danger); opacity: 0.12; }

        .doc-hero {
            display: grid;
            grid-template-columns: minmax(0, 1.45fr) minmax(220px, 0.8fr);
            gap: 22px;
            align-items: stretch;
            margin-bottom: 22px;
        }
        .hero-brand,
        .hero-meta-stack {
            border: 1px solid var(--border);
            background: linear-gradient(180deg, rgba(255,255,255,0.04), rgba(255,255,255,0.015));
            border-radius: 24px;
            padding: 22px 24px;
            min-height: 178px;
        }
        .hero-brand {
            display: flex;
            align-items: center;
            gap: 24px;
        }
        .hero-logo-wrap {
            width: 136px;
            height: 136px;
            border-radius: 28px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(145deg, rgba(212,175,55,0.18), rgba(255,255,255,0.03));
            border: 1px solid rgba(212,175,55,0.32);
            box-shadow: 0 10px 26px rgba(0,0,0,0.28), inset 0 0 0 1px rgba(255,255,255,0.03);
            flex-shrink: 0;
            padding: 10px;
        }
        .logo-img {
            max-width: 112px;
            max-height: 112px;
            width: 100%;
            height: 100%;
            display: block;
            object-fit: contain;
        }
        .hero-brand::after {
            content: '';
            align-self: stretch;
            width: 1px;
            margin: 4px 2px;
            background: linear-gradient(180deg, transparent, var(--border) 20%, var(--border) 80%, transparent);
            order: 1;
        }
        .hero-logo-wrap { order: 0; }
        .hero-copy { order: 2; }
        .brand-name-fallback {
            color: #fff;
            font-size: 1.15rem;
            font-weight: 900;
            text-align: center;
        }
        .hero-kicker {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 10px;
            color: var(--gold-soft);
            font-size: .84rem;
            font-weight: 800;
        }
        .hero-title {
            margin: 0;
            font-size: 2.45rem;
            line-height: 1;
            color: #fff;
            font-weight: 900;
        }
        .hero-subtitle {
            margin-top: 10px;
            color: var(--text-sub);
            font-size: 1.03rem;
        }
        .hero-description {
            margin-top: 12px;
            max-width: 580px;
            color: var(--text-soft);
            line-height: 1.75;
            font-size: .92rem;
        }
        .hero-meta-stack {
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            gap: 16px;
        }
        .status-chip {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            align-self: flex-start;
            padding: 10px 16px;
            border-radius: 999px;
            font-size: .88rem;
            font-weight: 800;
            border: 1px solid transparent;
        }
        .status-chip.pending { color: #ffd27e; background: rgba(219,167,58,0.12); border-color: rgba(219,167,58,0.35); }
        .status-chip.approved { color: #bbf0cb; background: rgba(68,193,115,0.12); border-color: rgba(68,193,115,0.35); }
        .status-chip.rejected { color: #ffc1bd; background: rgba(223,90,87,0.12); border-color: rgba(223,90,87,0.35); }
        .hero-ref-label {
            color: var(--text-soft);
            font-size: .78rem;
            font-weight: 700;
            letter-spacing: .05em;
            text-transform: uppercase;
        }
        .hero-ref-value {
            margin-top: 6px;
            color: #fff;
            font-size: 1.5rem;
            font-weight: 900;
            letter-spacing: .04em;
        }
        .hero-ref-meta {
            margin-top: 8px;
            color: var(--text-sub);
            font-size: .9rem;
            line-height: 1.8;
        }

        .meta-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 14px;
            margin-bottom: 22px;
        }
        .meta-card {
            padding: 16px 18px;
            border-radius: 18px;
            background: rgba(255,255,255,0.025);
            border: 1px solid rgba(255,255,255,0.08);
            min-height: 98px;
        }
        .meta-label {
            display: block;
            color: var(--text-soft);
            font-size: .8rem;
            margin-bottom: 10px;
            font-weight: 700;
        }
        .meta-value {
            display: block;
            color: #fff;
            font-size: 1.08rem;
            font-weight: 800;
            line-height: 1.6;
        }
        .meta-note {
            display: block;
            margin-top: 8px;
            color: var(--text-sub);
            font-size: .82rem;
        }

        .header-info-list,
        .panel-card,
        .items-panel,
        .terms-box,
        .footer,
        .tax-lines,
        .summary-card {
            break-inside: avoid;
            page-break-inside: avoid;
        }
        .header-info-list {
            margin-bottom: 18px;
            padding: 16px 18px;
            border-radius: 18px;
            border: 1px solid var(--border);
            background: rgba(255,255,255,0.025);
            color: var(--text-sub);
            text-align: center;
            line-height: 1.85;
        }

        .intro-grid {
            display: grid;
            grid-template-columns: minmax(0, 1.35fr) minmax(280px, .85fr);
            gap: 18px;
            margin-bottom: 24px;
        }
        .panel-card {
            background: linear-gradient(180deg, rgba(255,255,255,0.03), rgba(255,255,255,0.015));
            border: 1px solid var(--border);
            border-radius: 22px;
            padding: 22px 24px;
        }
        .panel-title {
            margin: 0 0 16px;
            display: flex;
            align-items: center;
            gap: 10px;
            color: var(--gold-soft);
            font-size: 1rem;
            font-weight: 800;
        }
        .client-name {
            margin: 0;
            color: #fff;
            font-size: 1.5rem;
            line-height: 1.4;
            font-weight: 900;
        }
        .client-subtitle {
            margin: 8px 0 0;
            color: var(--text-sub);
            line-height: 1.8;
            font-size: .95rem;
        }
        .client-pills {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 16px;
        }
        .client-pill {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            max-width: 100%;
            padding: 10px 14px;
            border-radius: 999px;
            background: rgba(255,255,255,0.04);
            border: 1px solid rgba(255,255,255,0.08);
            color: #f1eadb;
            font-size: .88rem;
            font-weight: 700;
        }
        .client-pill i { color: var(--gold); }

        .summary-card {
            display: flex;
            flex-direction: column;
            gap: 14px;
        }
        .summary-stack {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        .summary-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 12px 14px;
            border-radius: 16px;
            background: rgba(255,255,255,0.03);
            color: var(--text-sub);
        }
        .summary-row strong {
            color: #fff;
            font-size: 1rem;
            font-weight: 900;
        }
        .summary-row.final {
            padding: 18px;
            background: linear-gradient(135deg, rgba(212,175,55,0.18), rgba(212,175,55,0.08));
            border: 1px solid rgba(212,175,55,0.24);
        }
        .summary-row.final strong {
            color: var(--gold-soft);
            font-size: 1.35rem;
        }
        .summary-hint {
            color: var(--text-soft);
            font-size: .82rem;
            line-height: 1.8;
        }

        .items-panel,
        .terms-box {
            border: 1px solid var(--border);
            border-radius: 22px;
            background: linear-gradient(180deg, rgba(255,255,255,0.025), rgba(255,255,255,0.012));
        }
        .items-panel { padding: 22px 22px 10px; margin-bottom: 22px; }
        .panel-head {
            display: flex;
            align-items: flex-end;
            justify-content: space-between;
            gap: 16px;
            margin-bottom: 18px;
        }
        .panel-head-copy h2 {
            margin: 0;
            color: #fff;
            font-size: 1.35rem;
            font-weight: 900;
        }
        .panel-head-copy p {
            margin: 7px 0 0;
            color: var(--text-soft);
            font-size: .9rem;
        }
        .panel-chip {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 14px;
            border-radius: 999px;
            background: rgba(212,175,55,0.10);
            color: var(--gold-soft);
            border: 1px solid rgba(212,175,55,0.24);
            font-size: .84rem;
            font-weight: 800;
            white-space: nowrap;
        }
        .items-table-caption {
            caption-side: top;
            padding: 0 0 16px;
            text-align: initial;
        }
        .caption-copy {
            display: flex;
            align-items: flex-end;
            justify-content: space-between;
            gap: 16px;
        }
        .caption-copy-main {
            display: flex;
            flex-direction: column;
            gap: 7px;
        }
        .caption-title {
            color: #fff;
            font-size: 1.35rem;
            font-weight: 900;
            line-height: 1.3;
        }
        .caption-sub {
            color: var(--text-soft);
            font-size: .9rem;
            line-height: 1.8;
        }
        .table-intro-row { display: none; }
        .table-intro-cell {
            padding: 0 0 12px !important;
            background: transparent !important;
            border-bottom: 0 !important;
        }
        .table-intro-copy {
            display: flex;
            align-items: flex-end;
            justify-content: space-between;
            gap: 16px;
            text-align: initial;
        }
        .table-intro-main {
            display: flex;
            flex-direction: column;
            gap: 7px;
        }
        .table-intro-title {
            color: #111;
            font-size: 1.2rem;
            font-weight: 900;
            line-height: 1.3;
        }
        .table-intro-sub {
            color: #666052;
            font-size: .88rem;
            line-height: 1.7;
        }

        .items-table {
            width: 100%;
            border-collapse: collapse;
            overflow: hidden;
            border-radius: 18px;
            background: rgba(0,0,0,0.12);
        }
        .items-table thead th {
            padding: 14px 12px;
            background: linear-gradient(180deg, rgba(212,175,55,0.12), rgba(212,175,55,0.06));
            color: var(--gold-soft);
            font-size: .88rem;
            font-weight: 800;
            text-align: center;
            border-bottom: 1px solid rgba(255,255,255,0.08);
        }
        .items-table tbody tr {
            break-inside: avoid;
            page-break-inside: avoid;
        }
        .items-table tbody td {
            padding: 16px 12px;
            border-bottom: 1px solid rgba(255,255,255,0.07);
            color: #f2ede3;
            text-align: center;
            vertical-align: top;
            font-size: .93rem;
        }
        .items-table tbody tr:last-child td { border-bottom: 0; }
        .items-table tbody tr:nth-child(even) td { background: rgba(255,255,255,0.015); }
        .items-table td.desc {
            text-align: right;
            width: 45%;
            color: #fff;
            line-height: 1.9;
            font-weight: 700;
        }
        .item-index {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 34px;
            height: 34px;
            border-radius: 50%;
            background: rgba(212,175,55,0.12);
            color: var(--gold-soft);
            font-weight: 900;
        }
        .price-cell { white-space: nowrap; font-feature-settings: "tnum"; }
        .empty-items {
            padding: 26px 12px;
            text-align: center;
            color: var(--text-sub);
            font-weight: 700;
        }

        .tax-lines {
            display: flex;
            flex-direction: column;
            gap: 10px;
            margin: 18px 0 20px;
        }
        .tax-line {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 12px 14px;
            border-radius: 16px;
            background: rgba(255,255,255,0.025);
            border: 1px solid rgba(255,255,255,0.07);
            color: var(--text-sub);
        }
        .tax-line span:last-child {
            color: #fff;
            font-weight: 900;
            white-space: nowrap;
        }
        .tax-line.add span:last-child { color: var(--gold-soft); }
        .tax-line.subtract span:last-child { color: #ffb7b7; }

        .terms-box {
            padding: 22px 24px;
            margin-top: 18px;
            line-height: 1.95;
            color: #f0eadf;
            font-size: .95rem;
            white-space: pre-line;
        }
        .terms-title {
            display: flex;
            align-items: center;
            gap: 10px;
            margin: 0 0 14px;
            color: var(--gold-soft);
            font-size: 1rem;
            font-weight: 800;
        }

        .footer {
            margin-top: 28px;
            padding-top: 20px;
            border-top: 1px dashed rgba(255,255,255,0.16);
            color: var(--text-sub);
            font-size: .85rem;
            line-height: 1.8;
        }
        .footer-meta {
            display: flex;
            align-items: flex-end;
            justify-content: space-between;
            gap: 16px;
            flex-wrap: wrap;
        }
        .footer-lines { flex: 1; min-width: 240px; }
        .footer-lines p { margin: 0 0 5px; }
        .footer-lines p:first-child { color: var(--gold-soft); font-size: 1rem; font-weight: 900; }
        .qr-box { min-width: 110px; text-align: center; }
        .qr-box img {
            width: 96px;
            height: 96px;
            border-radius: 14px;
            border: 1px solid var(--line);
            background: #fff;
            padding: 6px;
        }
        .qr-box small { display: block; margin-top: 6px; color: var(--text-soft); font-weight: 700; }

        .actions-bar {
            position: fixed;
            left: 50%;
            bottom: 18px;
            transform: translateX(-50%);
            z-index: 1000;
            display: flex;
            justify-content: center;
            gap: 10px;
            width: min(92vw, 760px);
            padding: 12px;
            border-radius: 26px;
            border: 1px solid rgba(212,175,55,0.24);
            background: rgba(16,16,16,0.94);
            box-shadow: 0 18px 44px rgba(0,0,0,0.48);
            backdrop-filter: blur(14px);
        }
        .btn {
            flex: 1;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            min-height: 48px;
            padding: 12px 16px;
            border-radius: 16px;
            border: none;
            text-decoration: none;
            color: #fff;
            font: inherit;
            font-size: .92rem;
            font-weight: 800;
            cursor: pointer;
            transition: transform .2s ease, opacity .2s ease, background .2s ease;
            white-space: nowrap;
        }
        .btn:hover { transform: translateY(-2px); }
        .btn-print { background: linear-gradient(135deg, var(--gold), #f0cf7a); color: #1a1408; }
        .btn-wa { background: linear-gradient(135deg, #1f9e57, #2ecc71); }
        .btn-back { background: rgba(255,255,255,0.08); }

        @media (max-width: 900px) {
            .invoice-box { padding: 28px 24px 32px; }
            .doc-hero,
            .intro-grid,
            .meta-grid { grid-template-columns: 1fr; }
            .hero-brand { flex-direction: column; align-items: flex-start; }
            .hero-logo-wrap { width: 82px; height: 82px; }
            .hero-title { font-size: 2.1rem; }
            .status-stamp { top: 142px; font-size: 2rem; }
        }

        @media (max-width: 680px) {
            body { padding: 10px; }
            .container { margin-bottom: 150px; border-radius: 22px; }
            .invoice-box { padding: 20px 16px 24px; }
            .hero-brand,
            .hero-meta-stack,
            .header-info-list,
            .panel-card,
            .items-panel,
            .terms-box { padding: 18px 16px; }
            .hero-title { font-size: 1.82rem; }
            .hero-description { font-size: .86rem; }
            .meta-card { min-height: auto; }
            .panel-head { flex-direction: column; align-items: flex-start; }
            .caption-copy { flex-direction: column; align-items: flex-start; }
            .items-table,
            .items-table thead,
            .items-table tbody,
            .items-table tr,
            .items-table td { display: block; width: 100%; }
            .items-table thead { display: none; }
            .items-table tbody tr {
                margin-bottom: 12px;
                border: 1px solid rgba(255,255,255,0.08);
                border-radius: 18px;
                overflow: hidden;
                background: rgba(255,255,255,0.02);
            }
            .items-table tbody td {
                position: relative;
                padding: 12px 12px 12px 44%;
                text-align: right;
                border-bottom: 1px solid rgba(255,255,255,0.06);
                min-height: 50px;
            }
            .items-table tbody td:last-child { border-bottom: 0; }
            .items-table tbody td::before {
                content: attr(data-label);
                position: absolute;
                right: 12px;
                top: 12px;
                width: 34%;
                color: var(--gold-soft);
                font-size: .78rem;
                font-weight: 800;
                line-height: 1.5;
            }
            .items-table td.desc { width: 100%; }
            .actions-bar {
                flex-direction: column;
                width: calc(100vw - 16px);
                bottom: 8px;
                padding: 10px;
                border-radius: 20px;
            }
            .btn { width: 100%; }
            .status-stamp {
                top: 166px;
                font-size: 1.32rem;
                padding: 8px 16px;
                letter-spacing: 5px;
            }
        }

        @media print {
            @page { size: A4; margin: 10mm; }
            :root {
                --print-border: #d9d4c8;
                --print-soft: #666052;
            }
            body {
                background: #fff !important;
                color: #111 !important;
                padding: 0 !important;
                margin: 0 !important;
            }
            .container {
                max-width: 100%;
                width: 100%;
                margin: 0;
                border: 0;
                border-radius: 0;
                box-shadow: none;
                background: #fff;
            }
            .container::after { display: none; }
            .invoice-box { padding: 22px 24px 0; }
            .actions-bar { display: none !important; }

            .doc-hero,
            .meta-grid,
            .intro-grid,
            .items-panel,
            .tax-lines,
            .terms-box,
            .footer,
            .header-info-list,
            .panel-card,
            .summary-card,
            .summary-stack,
            .summary-row,
            .tax-line,
            .client-pills {
                break-inside: avoid-page !important;
                page-break-inside: avoid !important;
            }

            .hero-brand,
            .hero-meta-stack,
            .header-info-list,
            .panel-card,
            .items-panel,
            .terms-box,
            .footer {
                background: #fff !important;
                border-color: var(--print-border) !important;
                color: #111 !important;
                box-shadow: none !important;
            }
            .hero-title,
            .caption-title,
            .client-name,
            .meta-value,
            .summary-row strong,
            .terms-box,
            .footer-lines p,
            .tax-line span:last-child,
            .brand-name-fallback { color: #111 !important; }
            .hero-description,
            .hero-subtitle,
            .hero-ref-meta,
            .meta-label,
            .meta-note,
            .caption-sub,
            .summary-row,
            .summary-hint,
            .client-subtitle,
            .footer,
            .tax-line,
            .header-info-list,
            .qr-box small { color: var(--print-soft) !important; }
            .status-chip,
            .panel-chip,
            .client-pill,
            .summary-row,
            .summary-row.final,
            .tax-line,
            .item-index {
                background: #fff !important;
                border-color: var(--print-border) !important;
                color: #111 !important;
            }
            .status-stamp {
                opacity: 0.06 !important;
                color: #000 !important;
                border-color: #000 !important;
            }
            .items-table {
                display: table !important;
                width: 100%;
                background: #fff !important;
                border: 1px solid var(--print-border) !important;
                break-before: avoid-page !important;
                page-break-before: avoid !important;
                page-break-inside: auto !important;
            }
            .items-table-caption {
                display: none !important;
            }
            .table-intro-row {
                display: table-row !important;
            }
            .table-intro-cell,
            .table-intro-copy,
            .table-intro-main {
                break-inside: avoid-page !important;
                page-break-inside: avoid !important;
            }
            .table-intro-copy {
                align-items: flex-end !important;
            }
            .table-intro-row + tr th {
                background: #f2efe8 !important;
                color: #111 !important;
                border-bottom: 1px solid #c7c0b2 !important;
            }
            .items-table thead {
                display: table-header-group !important;
            }
            .items-table tbody {
                display: table-row-group !important;
            }
            .items-table tr {
                display: table-row !important;
                page-break-inside: avoid !important;
                break-inside: avoid-page !important;
            }
            .items-table th {
                background: #f2efe8 !important;
                color: #111 !important;
                border-bottom: 1px solid #c7c0b2 !important;
            }
            .items-table td {
                display: table-cell !important;
                padding: 12px 10px !important;
                color: #111 !important;
                border-bottom: 1px solid #dfd8ca !important;
                background: #fff !important;
            }
            .items-table td::before { display: none !important; }
            .items-table tbody tr:nth-child(even) td { background: #fbfaf7 !important; }
            .qr-box img { border-color: #cfc7b8 !important; }
            a { color: inherit !important; text-decoration: none !important; }
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
        .invoice-print-document {
            display: none;
        }
        @page {
            size: A4;
            margin: 10mm 12mm 10mm 12mm;
        }
        @media print {
            body > .container,
            body > .actions-bar {
                display: none !important;
            }
            .invoice-print-document {
                display: block !important;
            }
            html,
            body {
                width: auto !important;
                max-width: none !important;
                margin: 0 !important;
                padding: 0 !important;
                overflow: visible !important;
                background: #fff !important;
                color: #111 !important;
                direction: rtl !important;
                font-family: 'Cairo', Arial, sans-serif !important;
                font-size: 8.8pt !important;
                line-height: 1.35 !important;
            }
            .invoice-print-document,
            .invoice-print-document * {
                box-sizing: border-box !important;
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }
            .ip-sheet {
                width: 100% !important;
                max-width: 100% !important;
                color: #111 !important;
                background: #fff !important;
            }
            .ip-header {
                display: grid;
                grid-template-columns: 32mm minmax(0, 1fr) 34mm;
                gap: 6mm;
                align-items: center;
                padding-bottom: 5mm;
                margin-bottom: 2mm;
                border-bottom: 2.5px solid #111;
                break-inside: avoid;
                page-break-inside: avoid;
            }
            .ip-logo {
                width: 30mm;
                height: 30mm;
                object-fit: contain;
                border: 1px solid #d4cbbb;
                border-radius: 2mm;
                padding: 2.5mm;
                background: #fdfbf6;
            }
            .ip-title {
                border-right: 1px solid #d9d1c1;
                padding-right: 5mm;
            }
            .ip-title h1 {
                margin: 0;
                font-size: 20pt;
                line-height: 1.15;
                color: #111;
                letter-spacing: .2pt;
            }
            .ip-title p {
                margin: 1.5mm 0 0;
                color: #444;
                font-size: 8.8pt;
                font-weight: 700;
            }
            .ip-status {
                text-align: center;
                border: 1.5px solid #bdb4a5;
                border-radius: 2mm;
                padding: 3mm 2mm;
                font-weight: 900;
                font-size: 9pt;
                color: #111;
                background: #f7f3ea;
            }
            .ip-grid {
                display: grid;
                grid-template-columns: repeat(4, 1fr);
                gap: 2.5mm;
                margin: 4mm 0;
                break-inside: avoid;
                page-break-inside: avoid;
            }
            .ip-box {
                border: 1px solid #cfc7b8;
                padding: 2.5mm;
                min-height: 14mm;
            }
            .ip-label {
                display: block;
                color: #5d554b;
                font-size: 7pt;
                font-weight: 800;
                margin-bottom: 1.5mm;
            }
            .ip-value {
                display: block;
                color: #111;
                font-weight: 900;
                font-size: 9.6pt;
                line-height: 1.35;
                overflow-wrap: anywhere;
            }
            .ip-two {
                display: grid;
                grid-template-columns: 1.1fr .9fr;
                gap: 3mm;
                margin-bottom: 4mm;
                break-inside: avoid;
                page-break-inside: avoid;
            }
            .ip-panel {
                border: 1px solid #cfc7b8;
                padding: 3mm;
            }
            .ip-panel h2,
            .ip-section-title {
                margin: 0 0 3mm;
                color: #111;
                font-size: 11pt;
                line-height: 1.25;
            }
            .ip-line {
                display: flex;
                justify-content: space-between;
                gap: 5mm;
                border-bottom: 1px solid #e2dacc;
                padding: 1.25mm 0;
            }
            .ip-line:last-child {
                border-bottom: 0;
            }
            .ip-money {
                direction: ltr;
                unicode-bidi: plaintext;
                white-space: nowrap;
                color: #111;
                font-weight: 900;
            }
            .ip-items {
                margin-top: 3mm;
            }
            .ip-page-break {
                break-before: page;
                page-break-before: always;
                height: 0;
                overflow: hidden;
            }
            .ip-item {
                border: 1px solid #bfb6a7;
                margin: 0 0 2mm;
                break-inside: avoid-page;
                page-break-inside: avoid;
                background: #fff;
            }
            .ip-item-head,
            .ip-item-body {
                display: grid;
                grid-template-columns: 10mm minmax(0, 1fr) 27mm 27mm 30mm;
            }
            .ip-item-head {
                background: #f2eee6;
                border-bottom: 1px solid #bfb6a7;
                font-weight: 900;
            }
            .ip-cell {
                padding: 1.55mm 1.7mm;
                border-left: 1px solid #cfc7b8;
                min-width: 0;
                color: #111;
                overflow-wrap: anywhere;
            }
            .ip-cell:last-child {
                border-left: 0;
            }
            .ip-item-name {
                font-weight: 900;
                color: #111;
                white-space: pre-line;
            }
            .ip-tax-lines {
                margin-top: 3mm;
                border: 1px solid #cfc7b8;
                padding: 2.5mm 3mm;
                break-inside: avoid;
                page-break-inside: avoid;
            }
            .ip-notes {
                border: 1px solid #cfc7b8;
                padding: 3mm;
                margin-top: 4mm;
                color: #111;
                break-inside: avoid-page;
                page-break-inside: avoid;
            }
            .ip-notes-content {
                white-space: pre-line;
                color: #111;
                font-size: 7.8pt;
                line-height: 1.45;
            }
            .ip-footer {
                display: grid;
                grid-template-columns: minmax(0, 1fr) 28mm;
                gap: 5mm;
                align-items: end;
                border-top: 1px solid #cfc7b8;
                margin-top: 4mm;
                padding-top: 3mm;
                break-inside: avoid;
                page-break-inside: avoid;
            }
            .ip-footer p {
                margin: 0 0 1mm;
                color: #111;
                font-size: 7.8pt;
            }
            .ip-qr {
                width: 22mm;
                height: 22mm;
                object-fit: contain;
                justify-self: end;
            }
        }
        </style>
</head>
<body>

<div class="container">
    <div class="invoice-box">
        
        <div class="status-stamp <?php echo $stamp_class; ?>"><?php echo $stamp_text; ?></div>

        <section class="doc-hero">
            <div class="hero-brand">
                <div class="hero-logo-wrap">
                    <?php if ($outputShowLogo): ?>
                        <img src="<?php echo app_h($appLogo); ?>" alt="Logo" class="logo-img" onerror="this.style.display='none'">
                    <?php else: ?>
                        <div class="brand-name-fallback"><?php echo app_h((string)($brandProfile['org_name'] ?? $appName)); ?></div>
                    <?php endif; ?>
                </div>
                <div class="hero-copy">
                    <div class="hero-kicker"><i class="fa-solid fa-file-invoice-dollar"></i> فاتورة مبيعات</div>
                    <h1 class="hero-title">فاتورة</h1>
                    <div class="hero-subtitle"><?php echo app_h($appName); ?></div>
                    <div class="hero-description"><?php echo app_h($statusUi['desc']); ?></div>
                </div>
            </div>
            <div class="hero-meta-stack">
                <div class="status-chip <?php echo $stamp_class === 'st-approved' ? 'approved' : ($stamp_class === 'st-pending' ? 'pending' : 'rejected'); ?>">
                    <i class="fa-solid fa-wallet"></i> <?php echo app_h($statusUi['label']); ?>
                </div>
                <div class="hero-ref">
                    <span class="hero-ref-label">Invoice Reference</span>
                    <div class="hero-ref-value"><?php echo app_h($invoiceRef); ?></div>
                    <div class="hero-ref-meta">
                        <div>المعرف الداخلي: <strong>#<?php echo (int)$id; ?></strong></div>
                        <div>الرابط العام متاح للمشاركة والطباعة والتحقق.</div>
                    </div>
                </div>
            </div>
        </section>

        <section class="meta-grid">
            <div class="meta-card">
                <span class="meta-label">رقم الفاتورة</span>
                <span class="meta-value"><?php echo app_h($invoiceRef); ?></span>
                <span class="meta-note">مستند مالي جاهز للطباعة والمشاركة</span>
            </div>
            <div class="meta-card">
                <span class="meta-label">تاريخ الإصدار</span>
                <span class="meta-value"><?php echo app_h($invoiceDateLabel !== '' ? date('Y-m-d', strtotime($invoiceDateLabel)) : '-'); ?></span>
                <span class="meta-note">تاريخ اعتماد المستند داخل النظام</span>
            </div>
            <div class="meta-card">
                <span class="meta-label">المدفوع حتى الآن</span>
                <span class="meta-value"><?php echo app_h($invoiceMoney($paid)); ?></span>
                <span class="meta-note">التحصيل المسجل على هذه الفاتورة</span>
            </div>
            <div class="meta-card">
                <span class="meta-label">المتبقي</span>
                <span class="meta-value"><?php echo app_h($invoiceMoney($remaining)); ?></span>
                <span class="meta-note">الرصيد المفتوح المطلوب تحصيله</span>
            </div>
        </section>

        <?php if ($outputShowHeader && !empty($headerLines)): ?>
            <div class="header-info-list">
                <?php foreach ($headerLines as $line): ?>
                    <div><?php echo app_h($line); ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <section class="intro-grid">
            <div class="panel-card">
                <h2 class="panel-title"><i class="fa-solid fa-user-tie"></i> بيانات العميل</h2>
                <h3 class="client-name"><?php echo app_h($clientName); ?></h3>
                <p class="client-subtitle">هذه الفاتورة تعرض البنود والضرائب والتحصيلات بشكل منظم للطباعة والـPDF والمراجعة المباشرة مع العميل.</p>
                <div class="client-pills">
                    <?php if ($clientPhone !== ''): ?>
                    <span class="client-pill"><i class="fa-solid fa-phone"></i> <?php echo app_h($clientPhone); ?></span>
                    <?php endif; ?>
                    <?php if ($clientAddress !== ''): ?>
                    <span class="client-pill"><i class="fa-solid fa-location-dot"></i> <?php echo app_h($clientAddress); ?></span>
                    <?php endif; ?>
                    <span class="client-pill"><i class="fa-solid fa-file-circle-check"></i> <?php echo app_h($statusUi['label']); ?></span>
                </div>
            </div>

            <aside class="panel-card summary-card">
                <h2 class="panel-title"><i class="fa-solid fa-scale-balanced"></i> الملخص المالي</h2>
                <div class="summary-stack">
                    <div class="summary-row">
                        <span>إجمالي البنود</span>
                        <strong><?php echo app_h($invoiceMoney($sub_total)); ?></strong>
                    </div>
                    <?php if ($discount > 0): ?>
                    <div class="summary-row">
                        <span>خصم مستقطع</span>
                        <strong>-<?php echo app_h($invoiceMoney($discount)); ?></strong>
                    </div>
                    <?php endif; ?>
                    <?php if ($taxAddAmount > 0): ?>
                    <div class="summary-row">
                        <span>إضافات ضريبية ورسوم</span>
                        <strong>+<?php echo app_h($invoiceMoney($taxAddAmount)); ?></strong>
                    </div>
                    <?php endif; ?>
                    <?php if ($taxSubtractAmount > 0): ?>
                    <div class="summary-row">
                        <span>استقطاعات وضريبة خصم</span>
                        <strong>-<?php echo app_h($invoiceMoney($taxSubtractAmount)); ?></strong>
                    </div>
                    <?php endif; ?>
                    <div class="summary-row">
                        <span>المدفوع حتى الآن</span>
                        <strong><?php echo app_h($invoiceMoney($paid)); ?></strong>
                    </div>
                    <div class="summary-row">
                        <span>المتبقي</span>
                        <strong><?php echo app_h($invoiceMoney($remaining)); ?></strong>
                    </div>
                    <div class="summary-row final">
                        <span>الإجمالي النهائي</span>
                        <strong><?php echo app_h($invoiceMoney($total)); ?></strong>
                    </div>
                </div>
                <div class="summary-hint">تم الحفاظ على نفس منطق المدفوع والمتبقي والضرائب، مع إعادة تنظيم العرض فقط لتحسين المخرج النهائي.</div>
            </aside>
        </section>

        <section class="items-panel">
            <table class="items-table">
                <caption class="items-table-caption">
                    <span class="caption-copy">
                        <span class="caption-copy-main">
                            <span class="caption-title">بنود الفاتورة التفصيلية</span>
                            <span class="caption-sub">كل بند يظهر بشكل أوضح على الشاشة والطباعة، مع منع تقطيع الصفوف والمجاميع بشكل يضعف المستند.</span>
                        </span>
                        <span class="panel-chip"><i class="fa-solid fa-layer-group"></i> <?php echo number_format($itemsCount); ?> بند</span>
                    </span>
                </caption>
                <thead>
                    <tr class="table-intro-row">
                        <th colspan="5" class="table-intro-cell">
                            <div class="table-intro-copy">
                                <div class="table-intro-main">
                                    <div class="table-intro-title">بنود الفاتورة التفصيلية</div>
                                    <div class="table-intro-sub">كل بند يظهر بشكل أوضح على الشاشة والطباعة، مع منع تقطيع الصفوف والمجاميع بشكل يضعف المستند.</div>
                                </div>
                                <div class="panel-chip"><i class="fa-solid fa-layer-group"></i> <?php echo number_format($itemsCount); ?> بند</div>
                            </div>
                        </th>
                    </tr>
                    <tr>
                        <th width="7%">#</th>
                        <th width="43%">البيان / تفاصيل الخدمة</th>
                        <th width="15%">الكمية</th>
                        <th width="15%">سعر الوحدة</th>
                        <th width="20%">الإجمالي</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($items)): ?>
                    <?php foreach ($items as $index => $item): ?>
                    <?php
                        $itemName = 'صنف غير محدد';
                        foreach ($item as $key => $val) {
                            $kl = strtolower((string)$key);
                            if (!in_array($kl, ['qty','quantity','count','price','cost','unit_price','total','subtotal','tax','id','unit'], true) && is_string($val) && !is_numeric($val) && trim($val) !== '') {
                                $itemName = $val;
                                break;
                            }
                        }
                        $qty = isset($item['qty']) ? (float)$item['qty'] : (isset($item['quantity']) ? (float)$item['quantity'] : 1.0);
                        $unitVal = trim((string)($item['unit'] ?? ''));
                        $qtyLabel = number_format($qty, 2);
                        if ($unitVal !== '') {
                            $qtyLabel .= ' ' . $unitVal;
                        }
                        $price = isset($item['price']) ? (float)$item['price'] : (isset($item['unit_price']) ? (float)$item['unit_price'] : 0.0);
                        $itemTotal = isset($item['total']) ? (float)$item['total'] : ($qty * $price);
                    ?>
                    <tr>
                        <td data-label="الرقم"><span class="item-index"><?php echo (int)($index + 1); ?></span></td>
                        <td data-label="البيان / تفاصيل الخدمة" class="desc"><?php echo nl2br(app_h($itemName)); ?></td>
                        <td data-label="الكمية"><?php echo app_h($qtyLabel); ?></td>
                        <td data-label="سعر الوحدة" class="price-cell"><?php echo number_format($price, 2); ?></td>
                        <td data-label="الإجمالي" class="price-cell"><?php echo number_format($itemTotal, 2); ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php else: ?>
                    <tr>
                        <td colspan="5" class="empty-items">لم يتم إدراج أصناف تفصيلية في هذه الفاتورة.</td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>

            <?php if (!empty($invoiceTaxLines)): ?>
            <div class="tax-lines">
                <?php foreach ($invoiceTaxLines as $taxLine): ?>
                <?php
                    $taxName = (string)($taxLine['name'] ?? 'ضريبة');
                    $taxAmount = (float)($taxLine['amount'] ?? 0);
                    $taxMode = (string)($taxLine['mode'] ?? 'add');
                ?>
                <div class="tax-line <?php echo $taxMode === 'subtract' ? 'subtract' : 'add'; ?>">
                    <span><?php echo app_h($taxName); ?></span>
                    <span><?php echo ($taxMode === 'subtract' ? '-' : '+') . app_h($invoiceMoney($taxAmount)); ?></span>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </section>

        <?php if($invoiceNotes !== ''): ?>
        <div class="terms-box">
            <h2 class="terms-title"><i class="fa-solid fa-file-pen"></i> الشروط وملاحظات الفاتورة</h2>
            <?php echo nl2br(app_h($invoiceNotes)); ?>
        </div>
        <?php endif; ?>

        <?php if ($outputShowFooter): ?>
        <div class="footer">
            <div class="footer-meta">
                <div class="footer-lines">
                    <p><?php echo app_h((string)($brandProfile['org_name'] ?? $appName)); ?></p>
                    <?php foreach ($footerLines as $line): ?>
                        <p><?php echo app_h($line); ?></p>
                    <?php endforeach; ?>
                    <?php if (trim((string)($brandProfile['org_footer_note'] ?? '')) !== ''): ?>
                        <p style="margin-top:8px; font-style:italic;"><?php echo app_h((string)$brandProfile['org_footer_note']); ?></p>
                    <?php endif; ?>
                </div>
                <?php if ($qrUrl !== ''): ?>
                    <div class="qr-box">
                        <img src="<?php echo app_h($qrUrl); ?>" alt="QR Code Verification">
                        <small>مسح للدفع/التحقق</small>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<div class="invoice-print-document" aria-hidden="true">
    <div class="ip-sheet">
        <header class="ip-header">
            <?php if ($outputShowLogo): ?>
                <img class="ip-logo" src="<?php echo app_h($appLogo); ?>" alt="<?php echo app_h($appName); ?>">
            <?php else: ?>
                <div class="ip-logo" style="display:flex;align-items:center;justify-content:center;font-weight:900;font-size:7pt;"><?php echo app_h((string)($brandProfile['org_name'] ?? $appName)); ?></div>
            <?php endif; ?>
            <div class="ip-title">
                <h1>فاتورة مبيعات</h1>
                <p><?php echo app_h($appName); ?> · <?php echo app_h($invoiceRef); ?></p>
            </div>
            <div class="ip-status"><?php echo app_h($statusUi['label']); ?></div>
        </header>

        <section class="ip-grid">
            <div class="ip-box">
                <span class="ip-label">رقم الفاتورة</span>
                <span class="ip-value"><?php echo app_h($invoiceRef); ?></span>
            </div>
            <div class="ip-box">
                <span class="ip-label">تاريخ الإصدار</span>
                <span class="ip-value"><?php echo app_h($invoiceDateLabel !== '' ? date('Y-m-d', strtotime($invoiceDateLabel)) : '-'); ?></span>
            </div>
            <div class="ip-box">
                <span class="ip-label">المدفوع</span>
                <span class="ip-value ip-money"><?php echo app_h($invoiceMoney($paid)); ?></span>
            </div>
            <div class="ip-box">
                <span class="ip-label">المتبقي</span>
                <span class="ip-value ip-money"><?php echo app_h($invoiceMoney($remaining)); ?></span>
            </div>
        </section>

        <?php if ($outputShowHeader && !empty($headerLines)): ?>
        <section class="ip-panel" style="margin-bottom:4mm;">
            <?php foreach ($headerLines as $line): ?>
                <div><?php echo app_h($line); ?></div>
            <?php endforeach; ?>
        </section>
        <?php endif; ?>

        <section class="ip-two">
            <div class="ip-panel">
                <h2>بيانات العميل</h2>
                <div class="ip-line">
                    <span>العميل</span>
                    <strong><?php echo app_h($clientName); ?></strong>
                </div>
                <?php if ($clientPhone !== ''): ?>
                <div class="ip-line">
                    <span>الهاتف</span>
                    <strong><?php echo app_h($clientPhone); ?></strong>
                </div>
                <?php endif; ?>
                <?php if ($clientAddress !== ''): ?>
                <div class="ip-line">
                    <span>العنوان</span>
                    <strong><?php echo app_h($clientAddress); ?></strong>
                </div>
                <?php endif; ?>
            </div>
            <div class="ip-panel">
                <h2>الملخص المالي</h2>
                <div class="ip-line">
                    <span>إجمالي البنود</span>
                    <strong class="ip-money"><?php echo app_h($invoiceMoney($sub_total)); ?></strong>
                </div>
                <?php if ($discount > 0): ?>
                <div class="ip-line">
                    <span>خصم مستقطع</span>
                    <strong class="ip-money">-<?php echo app_h($invoiceMoney($discount)); ?></strong>
                </div>
                <?php endif; ?>
                <?php if ($taxAddAmount > 0): ?>
                <div class="ip-line">
                    <span>إضافات ضريبية ورسوم</span>
                    <strong class="ip-money">+<?php echo app_h($invoiceMoney($taxAddAmount)); ?></strong>
                </div>
                <?php endif; ?>
                <?php if ($taxSubtractAmount > 0): ?>
                <div class="ip-line">
                    <span>استقطاعات وضريبة خصم</span>
                    <strong class="ip-money">-<?php echo app_h($invoiceMoney($taxSubtractAmount)); ?></strong>
                </div>
                <?php endif; ?>
                <div class="ip-line">
                    <span>الإجمالي النهائي</span>
                    <strong class="ip-money"><?php echo app_h($invoiceMoney($total)); ?></strong>
                </div>
            </div>
        </section>

        <section class="ip-items">
            <h2 class="ip-section-title">بنود الفاتورة التفصيلية</h2>
            <?php if (!empty($items)): ?>
            <?php
                $ipPrintBudget = 15;
                $ipPrintNextBudget = 48;
                $ipPrintUsed = 0;
            ?>
            <?php foreach ($items as $index => $item): ?>
            <?php
                $itemName = 'صنف غير محدد';
                foreach ($item as $key => $val) {
                    $kl = strtolower((string)$key);
                    if (!in_array($kl, ['qty','quantity','count','price','cost','unit_price','total','subtotal','tax','id','unit'], true) && is_string($val) && !is_numeric($val) && trim($val) !== '') {
                        $itemName = $val;
                        break;
                    }
                }
                $qty = isset($item['qty']) ? (float)$item['qty'] : (isset($item['quantity']) ? (float)$item['quantity'] : 1.0);
                $unitVal = trim((string)($item['unit'] ?? ''));
                $qtyLabel = number_format($qty, 2);
                if ($unitVal !== '') {
                    $qtyLabel .= ' ' . $unitVal;
                }
                $price = isset($item['price']) ? (float)$item['price'] : (isset($item['unit_price']) ? (float)$item['unit_price'] : 0.0);
                $itemTotal = isset($item['total']) ? (float)$item['total'] : ($qty * $price);
                $ipLineCount = 0;
                $ipRawLines = preg_split('/\R/u', trim((string)$itemName));
                if (!is_array($ipRawLines) || empty($ipRawLines)) {
                    $ipRawLines = [''];
                }
                foreach ($ipRawLines as $ipRawLine) {
                    $ipRawLine = trim((string)$ipRawLine);
                    $ipLineLength = function_exists('mb_strlen') ? mb_strlen($ipRawLine, 'UTF-8') : strlen($ipRawLine);
                    $ipLineCount += max(1, (int)ceil($ipLineLength / 58));
                }
                $ipItemWeight = 3 + $ipLineCount;
            ?>
            <?php if ($ipPrintUsed > 0 && ($ipPrintUsed + $ipItemWeight) > $ipPrintBudget): ?>
            <div class="ip-page-break"></div>
            <?php
                $ipPrintUsed = 0;
                $ipPrintBudget = $ipPrintNextBudget;
            ?>
            <?php endif; ?>
            <?php $ipPrintUsed += $ipItemWeight; ?>
            <article class="ip-item">
                <div class="ip-item-head">
                    <div class="ip-cell">#</div>
                    <div class="ip-cell">البيان / تفاصيل الخدمة</div>
                    <div class="ip-cell">الكمية</div>
                    <div class="ip-cell">سعر الوحدة</div>
                    <div class="ip-cell">الإجمالي</div>
                </div>
                <div class="ip-item-body">
                    <div class="ip-cell"><?php echo (int)($index + 1); ?></div>
                    <div class="ip-cell"><div class="ip-item-name"><?php echo nl2br(app_h($itemName)); ?></div></div>
                    <div class="ip-cell"><?php echo app_h($qtyLabel); ?></div>
                    <div class="ip-cell ip-money"><?php echo app_h($invoiceMoney($price)); ?></div>
                    <div class="ip-cell ip-money"><?php echo app_h($invoiceMoney($itemTotal)); ?></div>
                </div>
            </article>
            <?php endforeach; ?>
            <?php else: ?>
            <div class="ip-panel">لم يتم إدراج أصناف تفصيلية في هذه الفاتورة.</div>
            <?php endif; ?>
        </section>

        <?php if (!empty($invoiceTaxLines)): ?>
        <section class="ip-tax-lines">
            <?php foreach ($invoiceTaxLines as $taxLine): ?>
            <?php
                $taxName = (string)($taxLine['name'] ?? 'ضريبة');
                $taxAmount = (float)($taxLine['amount'] ?? 0);
                $taxMode = (string)($taxLine['mode'] ?? 'add');
            ?>
            <div class="ip-line">
                <span><?php echo app_h($taxName); ?></span>
                <strong class="ip-money"><?php echo ($taxMode === 'subtract' ? '-' : '+') . app_h($invoiceMoney($taxAmount)); ?></strong>
            </div>
            <?php endforeach; ?>
        </section>
        <?php endif; ?>

        <?php if($invoiceNotes !== ''): ?>
        <section class="ip-notes">
            <h2 class="ip-section-title">الشروط وملاحظات الفاتورة</h2>
            <div class="ip-notes-content"><?php echo nl2br(app_h($invoiceNotes)); ?></div>
        </section>
        <?php endif; ?>

        <?php if ($outputShowFooter): ?>
        <footer class="ip-footer">
            <div>
                <p style="font-weight:900;"><?php echo app_h((string)($brandProfile['org_name'] ?? $appName)); ?></p>
                <?php foreach ($footerLines as $line): ?>
                    <p><?php echo app_h($line); ?></p>
                <?php endforeach; ?>
                <?php if (trim((string)($brandProfile['org_footer_note'] ?? '')) !== ''): ?>
                    <p><?php echo app_h((string)$brandProfile['org_footer_note']); ?></p>
                <?php endif; ?>
            </div>
            <?php if ($qrUrl !== ''): ?>
                <img class="ip-qr" src="<?php echo app_h($qrUrl); ?>" alt="QR">
            <?php endif; ?>
        </footer>
        <?php endif; ?>
    </div>
</div>

<div class="actions-bar">
    <a href="invoices.php?tab=sales" class="btn btn-back"><i class="fa-solid fa-arrow-right"></i> الرجوع للنظام</a>
    <a href="https://wa.me/20<?php echo ltrim($inv['client_phone'], '0'); ?>?text=<?php echo urlencode($wa_msg); ?>" target="_blank" class="btn btn-wa"><i class="fa-brands fa-whatsapp"></i> إرسال واتساب</a>
    <button onclick="window.print()" class="btn btn-print"><i class="fa-solid fa-print"></i> طباعة / حفظ PDF</button>
</div>

</body>
</html>
<?php ob_end_flush(); ?>
