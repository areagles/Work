<?php
// view_quote.php - النسخة الماسية (متوافق 100% مع الموبايل)
ob_start();
require 'config.php'; 
require_once __DIR__ . '/modules/finance/fx_widget_runtime.php';
require_once __DIR__ . '/quote_growth_runtime.php';
app_start_session();
app_ensure_quotes_schema($conn);
app_quote_growth_ensure_schema($conn);
app_ensure_taxation_schema($conn);

$appName = app_setting_get($conn, 'app_name', 'Arab Eagles');
$appLogo = app_brand_logo_path($conn, 'assets/img/Logo.png');
$brandProfile = app_brand_profile($conn);
$outputTheme = app_brand_output_theme($conn);
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

// 1. تحديد طريقة الوصول
$quote = null;
$access_mode = '';
$stmt = null;

if (isset($_GET['token'])) {
    $token = trim((string)$_GET['token']);
    $stmt = $conn->prepare("
        SELECT q.*, c.name as client_name, c.phone as client_phone, c.address as client_addr
        FROM quotes q
        JOIN clients c ON q.client_id = c.id
        WHERE q.access_token = ?
        LIMIT 1
    ");
    $stmt->bind_param('s', $token);
    $access_mode = 'token';
} elseif (isset($_GET['id'])) {
    require_once 'auth.php'; 
    $id = intval($_GET['id']);
    $stmt = $conn->prepare("
        SELECT q.*, c.name as client_name, c.phone as client_phone, c.address as client_addr
        FROM quotes q
        JOIN clients c ON q.client_id = c.id
        WHERE q.id = ?
        LIMIT 1
    ");
    $stmt->bind_param('i', $id);
    $access_mode = 'id';
} else {
    die("<div style='text-align:center; padding:50px; color:white; background:#000;'>رابط غير صالح.</div>");
}

$stmt->execute();
$res = $stmt->get_result();
if (!$res || $res->num_rows == 0) die("<div style='text-align:center; padding:50px; color:white; background:#000;'>العرض غير موجود.</div>");
$quote = $res->fetch_assoc();
$stmt->close();
$quote_id = $quote['id'];
$linkedInvoiceId = (int)($quote['converted_invoice_id'] ?? 0);
$linkedInvoiceToken = $linkedInvoiceId > 0 ? app_public_token('invoice_view', $linkedInvoiceId) : '';
$conversionNotice = '';
$conversionError = '';
$flowNotice = '';
$flowNoticeTone = 'info';
$status_value = strtolower((string)($quote['status'] ?? 'pending'));
$quoteReviewClosed = trim((string)($quote['quote_review_closed_at'] ?? '')) !== '';
$quoteReviewClosedReason = trim((string)($quote['quote_review_closed_reason'] ?? ''));
$internalApprovalStatus = (string)($quote['internal_approval_status'] ?? 'not_required');
$quoteRejectionReason = trim((string)($quote['quote_rejection_reason'] ?? ''));
if (!in_array($status_value, ['pending', 'approved', 'rejected'], true)) {
    $status_value = 'pending';
}
$quote_access_token = (string)($quote['access_token'] ?? '');
$client_phone_digits = preg_replace('/\D+/', '', (string)($quote['client_phone'] ?? ''));
$whatsapp_phone = ltrim($client_phone_digits, '0');
if ($whatsapp_phone !== '' && strpos($whatsapp_phone, '20') !== 0) {
    $whatsapp_phone = '20' . $whatsapp_phone;
}

$buildQuoteRedirect = static function (array $params = []) use ($access_mode, $quote_access_token, $quote_id): string {
    $base = ($access_mode === 'token')
        ? ('?token=' . rawurlencode($quote_access_token))
        : ('?id=' . intval($quote_id));
    if (empty($params)) {
        return $base;
    }

    return $base . '&' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
};

$backHref = $access_mode === 'id' ? 'quotes.php' : 'javascript:history.back()';
$backLabel = $access_mode === 'id' ? 'رجوع للعروض' : 'رجوع';

// 2. معالجة القرار
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    if (!app_verify_csrf($_POST['_csrf_token'] ?? '')) {
        http_response_code(419);
        die("<div style='text-align:center; padding:50px; color:white; background:#000;'>انتهت صلاحية الجلسة. أعد تحميل الصفحة.</div>");
    }

    $action = (string)($_POST['action'] ?? '');
    if (in_array($action, ['approve', 'reject', 'convert_to_invoice'], true)) {
        if ($access_mode !== 'id' && $quoteReviewClosed && in_array($action, ['approve', 'reject'], true)) {
            app_safe_redirect($buildQuoteRedirect(['decision' => 'review_closed']), 'view_quote.php');
        }
        if ($access_mode !== 'id' && $action === 'approve' && $internalApprovalStatus === 'pending') {
            app_safe_redirect($buildQuoteRedirect(['decision' => 'internal_pending']), 'view_quote.php');
        }
        $client_note = trim((string)($_POST['client_note'] ?? ''));
        $client_note = mb_substr($client_note, 0, 2000);
        $rejectReason = '';
        if ($action === 'reject') {
            $allowedReasons = app_quote_rejection_reasons();
            $rejectReason = trim((string)($_POST['quote_rejection_reason'] ?? ''));
            if (!in_array($rejectReason, $allowedReasons, true)) {
                $rejectReason = $rejectReason !== '' ? mb_substr($rejectReason, 0, 190) : 'سبب آخر';
            }
        }
        if (in_array($action, ['approve', 'reject'], true)) {
            $status = ($action === 'approve') ? 'approved' : 'rejected';

            $update_stmt = $conn->prepare("UPDATE quotes SET status = ?, client_comment = ?, quote_rejection_reason = ? WHERE id = ?");
            $update_stmt->bind_param('sssi', $status, $client_note, $rejectReason, $quote_id);
            $update_stmt->execute();
            $update_stmt->close();
            app_quote_history_add($conn, $quote_id, $status === 'approved' ? 'client_approved' : 'client_rejected', $status === 'approved' ? 'وافق العميل على العرض' : 'رفض العميل العرض', ['client_note' => $client_note, 'reason' => $rejectReason]);
        }

        if ($access_mode === 'id' && ($action === 'approve' || $action === 'convert_to_invoice')) {
            $actorName = trim((string)($_SESSION['name'] ?? $_SESSION['username'] ?? 'Quote Approval'));
            $conversion = app_quote_convert_to_invoice($conn, (int)$quote_id, $actorName);
            if (empty($conversion['ok'])) {
                app_safe_redirect($buildQuoteRedirect([
                    'decision' => $action === 'approve' ? 'approved' : 'converted',
                    'conversion_error' => (string)($conversion['error'] ?? 'conversion_failed'),
                ]), 'view_quote.php');
            } else {
                $invoiceId = (int)($conversion['invoice_id'] ?? 0);
                app_safe_redirect($buildQuoteRedirect([
                    'decision' => $action === 'approve' ? 'approved' : 'converted',
                    'converted_invoice_id' => $invoiceId,
                    'already_converted' => !empty($conversion['already_converted']) ? '1' : '0',
                ]), 'view_quote.php');
            }
        }

        app_safe_redirect($buildQuoteRedirect([
            'decision' => $action === 'reject' ? 'rejected' : 'updated',
        ]), 'view_quote.php');
    }
}

if (isset($_GET['converted_invoice_id'])) {
    $linkedInvoiceId = max(0, (int)$_GET['converted_invoice_id']);
    if ($linkedInvoiceId > 0) {
        $linkedInvoiceToken = app_public_token('invoice_view', $linkedInvoiceId);
        $conversionNotice = 'تم إنشاء فاتورة تشغيلية من هذا العرض بنجاح.';
    }
}
if (isset($_GET['conversion_error'])) {
    $conversionError = trim((string)$_GET['conversion_error']);
}
if (isset($_GET['decision'])) {
    $decision = strtolower(trim((string)$_GET['decision']));
    $alreadyConverted = trim((string)($_GET['already_converted'] ?? '0')) === '1';
    if ($decision === 'approved') {
        $flowNoticeTone = $conversionError === '' ? 'success' : 'warning';
        if ($access_mode === 'token') {
            $flowNotice = 'تم اعتماد العرض بنجاح وإرسال الموافقة للإدارة لاستكمال التنفيذ الداخلي.';
        } elseif ($conversionError === '') {
            $flowNotice = $alreadyConverted
                ? 'تم اعتماد العرض بنجاح. الفاتورة التشغيلية كانت منشأة بالفعل وتم ربطك بها.'
                : 'تم اعتماد العرض بنجاح وإنشاء الفاتورة التشغيلية تلقائيًا.';
        } else {
            $flowNotice = 'تم اعتماد العرض بنجاح، لكن تعذر إنشاء الفاتورة التشغيلية تلقائيًا الآن. ستتم المتابعة من الإدارة.';
        }
    } elseif ($decision === 'rejected') {
        $flowNoticeTone = 'danger';
        $flowNotice = 'تم تسجيل اعتذارك/رفضك للعرض وإرسال ملاحظتك للإدارة.';
    } elseif ($decision === 'review_closed') {
        $flowNotice = 'تم إغلاق مراجعة العميل لهذا العرض. لا يمكن تسجيل موافقة أو رفض من رابط العميل حالياً.';
        $flowNoticeTone = 'warning';
    } elseif ($decision === 'internal_pending') {
        $flowNotice = 'العرض بانتظار اعتماد داخلي قبل تسجيل موافقة العميل. ستتم مراجعة الإدارة أولاً.';
        $flowNoticeTone = 'warning';
    } elseif ($decision === 'converted') {
        $flowNoticeTone = $conversionError === '' ? 'success' : 'warning';
        $flowNotice = $conversionError === ''
            ? ($alreadyConverted
                ? 'الفاتورة التشغيلية موجودة بالفعل لهذا العرض.'
                : 'تم إنشاء الفاتورة التشغيلية لهذا العرض بنجاح.')
            : 'تعذر إنشاء الفاتورة التشغيلية حاليًا. حاول مرة أخرى بعد لحظات.';
    }
}

$items = $conn->query("SELECT * FROM quote_items WHERE quote_id = $quote_id");
$quoteTaxLines = app_tax_decode_lines((string)($quote['taxes_json'] ?? '[]'));
$quoteItems = [];
if ($items instanceof mysqli_result) {
    while ($itemRow = $items->fetch_assoc()) {
        $quoteItems[] = $itemRow;
    }
    $items->free();
}

$quoteRef = trim((string)($quote['quote_number'] ?? ''));
if ($quoteRef === '') {
    $quoteRef = 'QUO-' . str_pad((string)$quote_id, 4, '0', STR_PAD_LEFT);
}
$sourceRef = trim((string)($quote['pricing_source_ref'] ?? ''));
$quoteKind = trim((string)($quote['quote_kind'] ?? 'standard'));
$quoteKindLabel = 'عرض سعر تشغيلي';
if ($quoteKind === 'tax') {
    $quoteKindLabel = 'عرض سعر ضريبي';
} elseif ($quoteKind === 'request') {
    $quoteKindLabel = 'طلب تسعير';
}
$issuedAtLabel = trim((string)($quote['created_at'] ?? ''));
$validUntilLabel = trim((string)($quote['valid_until'] ?? ''));
$notesText = trim((string)($quote['notes'] ?? ''));
$clientCommentText = trim((string)($quote['client_comment'] ?? ''));
$quoteAttachments = app_quote_attachments($conn, (int)$quote_id);
$clientAddress = trim((string)($quote['client_addr'] ?? ''));
$clientPhone = trim((string)($quote['client_phone'] ?? ''));
$quoteCurrency = app_quote_currency_from_quote($conn, $quote);
$quoteCurrencyCode = (string)($quoteCurrency['code'] ?? 'EGP');
$quoteBaseCurrency = (string)($quoteCurrency['base_currency'] ?? app_quote_default_currency($conn));
$quoteExchangeRate = (float)($quoteCurrency['rate_to_base'] ?? 1);
$quoteBaseTotal = (float)($quote['base_total_amount'] ?? 0);
$quoteMoney = static function (float $value) use ($quoteCurrencyCode): string {
    return app_quote_format_money($value, $quoteCurrencyCode);
};
$itemsCount = count($quoteItems);
$subtotalAmount = 0.0;
foreach ($quoteItems as $itemRow) {
    $subtotalAmount += (float)($itemRow['total'] ?? 0);
}
$taxAddAmount = 0.0;
$taxSubtractAmount = 0.0;
foreach ($quoteTaxLines as $taxLine) {
    $amount = (float)($taxLine['amount'] ?? 0);
    if (((string)($taxLine['mode'] ?? 'add')) === 'subtract') {
        $taxSubtractAmount += $amount;
    } else {
        $taxAddAmount += $amount;
    }
}
$statusMap = [
    'pending' => ['label' => 'بانتظار الاعتماد', 'stamp' => 'PENDING', 'desc' => 'العرض ما زال مفتوحًا وبانتظار قرار العميل.'],
    'approved' => ['label' => 'تمت الموافقة', 'stamp' => 'APPROVED', 'desc' => 'تم اعتماد العرض ويمكن متابعة التنفيذ والفوترة.'],
    'rejected' => ['label' => 'مرفوض / معتذر', 'stamp' => 'REJECTED', 'desc' => 'تم تسجيل الرفض أو الاعتذار مع الملاحظات إن وجدت.'],
];
$statusUi = $statusMap[$status_value] ?? $statusMap['pending'];

$public_dir = rtrim(dirname($_SERVER['PHP_SELF']), '/\\');
$public_link = app_base_url() . $public_dir . "/view_quote.php?token=" . rawurlencode($quote_access_token);
$wa_msg = "مرحباً أ/" . (string)($quote['client_name'] ?? '') . "،\nمرفق عرض السعر من " . (string)$appName . ":\n" . $public_link;
$qrPayload = app_brand_qr_payload($brandProfile, [
    'Document' => 'Quotation View',
    'Quote ID' => (string)($quote['quote_number'] ?? $quote_id),
    'Client' => (string)($quote['client_name'] ?? ''),
    'Total' => app_quote_format_money((float)($quote['total_amount'] ?? 0), $quoteCurrencyCode),
    'Link' => $public_link,
]);
$qrUrl = ($outputShowQr && $qrPayload !== '') ? app_brand_qr_url($qrPayload, 140) : '';
$quoteHistoryRows = [];
if ($access_mode === 'id') {
    try {
        $histStmt = $conn->prepare("SELECT event_label, details_json, user_name, created_at FROM quote_history WHERE quote_id = ? ORDER BY id DESC LIMIT 12");
        if ($histStmt) {
            $histStmt->bind_param('i', $quote_id);
            $histStmt->execute();
            $histRes = $histStmt->get_result();
            while ($histRes && ($histRow = $histRes->fetch_assoc())) {
                $quoteHistoryRows[] = $histRow;
            }
            $histStmt->close();
        }
    } catch (Throwable $e) {
        $quoteHistoryRows = [];
    }
}
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>عرض سعر #<?php echo $quote_id; ?> | <?php echo app_h($appName); ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800&display=swap" rel="stylesheet">
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
            --border-strong: rgba(255,255,255,0.16);
            --surface: rgba(255,255,255,0.04);
            --surface-strong: rgba(255,255,255,0.07);
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
        .quote-top-actions { display: flex; justify-content: flex-start; margin-bottom: 18px; }
        .quote-top-back {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 11px 18px;
            border-radius: 999px;
            text-decoration: none;
            color: var(--gold);
            background: rgba(255,255,255,0.03);
            border: 1px solid var(--border);
            font-weight: 800;
            transition: transform .2s ease, background .2s ease, border-color .2s ease;
        }
        .quote-top-back:hover {
            transform: translateY(-2px);
            background: var(--surface);
            border-color: rgba(212,175,55,0.30);
        }

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

        .quote-hero {
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
            gap: 22px;
        }
        .hero-logo-wrap {
            width: 98px;
            height: 98px;
            border-radius: 26px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(145deg, rgba(212,175,55,0.16), rgba(255,255,255,0.02));
            border: 1px solid rgba(212,175,55,0.24);
            box-shadow: inset 0 0 0 1px rgba(255,255,255,0.04);
            flex-shrink: 0;
        }
        .logo-img {
            max-width: 74px;
            max-height: 74px;
            display: block;
            object-fit: contain;
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
        .hero-ref {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }
        .hero-ref-label {
            color: var(--text-soft);
            font-size: .78rem;
            font-weight: 700;
            letter-spacing: .05em;
            text-transform: uppercase;
        }
        .hero-ref-value {
            color: #fff;
            font-size: 1.5rem;
            font-weight: 900;
            letter-spacing: .04em;
        }
        .hero-ref-meta {
            color: var(--text-sub);
            font-size: .9rem;
            line-height: 1.7;
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
        .client-card .client-name {
            margin: 0;
            color: #fff;
            font-size: 1.5rem;
            line-height: 1.4;
            font-weight: 900;
        }
        .client-card .client-subtitle {
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
            padding: 18px 18px;
            background: linear-gradient(135deg, rgba(212,175,55,0.18), rgba(212,175,55,0.08));
            border: 1px solid rgba(212,175,55,0.24);
        }
        .summary-row.final strong {
            color: var(--gold-soft);
            font-size: 1.35rem;
        }
        .currency-reveal {
            margin-top: 12px; padding: 10px 12px; border-radius: 14px;
            background: rgba(212,175,55,.09); border: 1px solid rgba(212,175,55,.22);
            color: var(--gold-soft); font-weight: 800; font-size: .84rem; line-height: 1.7;
        }
        .summary-hint {
            color: var(--text-soft);
            font-size: .82rem;
            line-height: 1.8;
        }

        .items-panel,
        .terms-box,
        .client-comment-box,
        .attachments-box,
        .flow-box {
            border: 1px solid var(--border);
            border-radius: 22px;
            background: linear-gradient(180deg, rgba(255,255,255,0.025), rgba(255,255,255,0.012));
        }
        .items-panel { padding: 22px 22px 10px; margin-bottom: 22px; }
        .attachments-box { padding: 22px 24px; margin-bottom: 22px; }
        .attachment-list { display:grid; gap:10px; }
        .attachment-link { display:flex; align-items:center; justify-content:space-between; gap:12px; padding:12px 14px; border-radius:14px; border:1px solid var(--border); color:#f1eadb; text-decoration:none; background:rgba(255,255,255,.025); }
        .attachment-link i { color:var(--gold); }
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
            width: 48%;
            color: #fff;
            line-height: 1.9;
            font-weight: 700;
        }
        .item-description-text {
            display: block;
            margin-top: 8px;
            color: var(--text-sub);
            font-size: .88rem;
            line-height: 1.8;
            font-weight: 600;
            white-space: pre-line;
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
            margin: 18px 0 6px;
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

        .terms-box,
        .client-comment-box,
        .flow-box {
            padding: 22px 24px;
            margin-top: 18px;
            line-height: 1.95;
        }
        .terms-title,
        .client-comment-title {
            display: flex;
            align-items: center;
            gap: 10px;
            margin: 0 0 14px;
            color: var(--gold-soft);
            font-size: 1rem;
            font-weight: 800;
        }
        .terms-content,
        .client-comment-content {
            color: #f0eadf;
            font-size: .95rem;
            white-space: pre-line;
        }
        .terms-empty {
            color: var(--text-soft);
            font-style: italic;
        }

        .flow-box {
            text-align: center;
            font-weight: 700;
        }

        .footer {
            margin-top: 28px;
            padding-top: 20px;
            border-top: 1px dashed rgba(255,255,255,0.16);
            color: var(--text-sub);
            font-size: .85rem;
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
        .footer-lines p:last-child { margin-bottom: 0; }
        .footer-qr img {
            width: 96px;
            height: 96px;
            border-radius: 14px;
            border: 1px solid var(--line);
            background: #fff;
            padding: 6px;
        }
        .footer a { color: var(--gold); text-decoration: none; }

        .history-box { margin-top: 18px; padding: 18px; border-radius: 18px; background: rgba(255,255,255,.035); border: 1px solid rgba(255,255,255,.08); }
        .history-list { display:grid; gap:10px; margin-top:12px; }
        .history-item { display:flex; justify-content:space-between; gap:12px; padding:10px 12px; border-radius:12px; background:rgba(0,0,0,.18); color:#ddd; font-size:.88rem; }
        .history-item small { color:#888; white-space:nowrap; }
        .decision-area {
            position: relative;
            overflow: hidden;
            margin-top: 28px;
            padding: 28px;
            text-align: center;
            border-radius: 22px;
            border: 1px solid rgba(255,255,255,0.10);
            background: linear-gradient(135deg, rgba(255,255,255,0.04), rgba(0,0,0,0.16));
            page-break-inside: avoid;
        }
        .decision-title {
            margin-bottom: 18px;
            color: #fff;
            font-size: 1.18rem;
            font-weight: 900;
        }
        .decision-btns {
            display: flex;
            justify-content: center;
            gap: 16px;
            margin-top: 18px;
            position: relative;
            z-index: 1;
        }
        .btn-decision {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            padding: 15px 34px;
            border: none;
            border-radius: 999px;
            cursor: pointer;
            color: #fff;
            font-size: .98rem;
            font-weight: 900;
            transition: transform .2s ease, box-shadow .2s ease, opacity .2s ease;
        }
        .btn-decision:hover { transform: translateY(-2px); }
        .btn-accept { background: linear-gradient(135deg, #29a85f, #44c173); box-shadow: 0 10px 22px rgba(68,193,115,0.24); }
        .btn-reject { background: rgba(223,90,87,0.08); border: 1px solid rgba(223,90,87,0.38); color: #ffc3c0; }
        .btn-reject:hover { background: rgba(223,90,87,0.18); }

        .client-note-wrapper { max-width: 640px; margin: 0 auto; }
        .client-note {
            width: 100%;
            min-height: 116px;
            padding: 16px 18px;
            resize: vertical;
            border-radius: 16px;
            border: 1px solid rgba(255,255,255,0.10);
            background: rgba(0,0,0,0.18);
            color: #fff;
            font: inherit;
            outline: none;
            transition: border-color .2s ease, box-shadow .2s ease;
        }
        .client-note:focus {
            border-color: rgba(212,175,55,0.42);
            box-shadow: 0 0 0 4px rgba(212,175,55,0.08);
        }

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
            .quote-hero,
            .intro-grid,
            .meta-grid {
                grid-template-columns: 1fr;
            }
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
            .panel-card,
            .items-panel,
            .terms-box,
            .client-comment-box,
            .flow-box,
            .decision-area { padding: 18px 16px; }
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
                display: grid;
                grid-template-columns: minmax(92px, 36%) minmax(0, 1fr);
                gap: 10px;
                align-items: start;
                padding: 12px;
                text-align: right;
                border-bottom: 1px solid rgba(255,255,255,0.06);
                min-height: 0;
                overflow-wrap: anywhere;
                word-break: normal;
            }
            .items-table tbody td:last-child { border-bottom: 0; }
            .items-table tbody td::before {
                content: attr(data-label);
                position: static;
                width: auto;
                min-width: 0;
                color: var(--gold-soft);
                font-size: .78rem;
                font-weight: 800;
                line-height: 1.5;
                white-space: normal;
                overflow-wrap: anywhere;
            }
            .items-table td.desc { width: 100%; line-height: 1.7; }
            .items-table .price-cell { direction: ltr; unicode-bidi: plaintext; }
            .decision-btns,
            .actions-bar { flex-direction: column; }
            .actions-bar {
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
            :root {
                --print-border: #d9d4c8;
                --print-soft: #666052;
            }
            body {
                background: #fff;
                color: #111;
                padding: 0;
                margin: 0;
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
            .quote-top-actions,
            .actions-bar,
            .decision-area { display: none !important; }
            .hero-brand,
            .hero-meta-stack,
            .panel-card,
            .items-panel,
            .terms-box,
            .client-comment-box,
            .flow-box {
                background: #fff !important;
                border-color: var(--print-border) !important;
                color: #111 !important;
                box-shadow: none !important;
            }
            .hero-title,
            .caption-title,
            .client-card .client-name,
            .meta-value,
            .summary-row strong,
            .terms-content,
            .client-comment-content,
            .footer-lines p,
            .tax-line span:last-child { color: #111 !important; }
            .hero-description,
            .hero-subtitle,
            .hero-ref-meta,
            .meta-label,
            .meta-note,
            .caption-sub,
            .summary-row,
            .summary-hint,
            .client-card .client-subtitle,
            .terms-empty,
            .footer,
            .tax-line,
            .flow-box { color: var(--print-soft) !important; }
            .status-chip.pending,
            .status-chip.approved,
            .status-chip.rejected,
            .panel-chip,
            .client-pill,
            .summary-row,
            .summary-row.final,
            .tax-line {
                background: #fff !important;
                border-color: var(--print-border) !important;
            }
            .items-panel {
                break-inside: auto !important;
                page-break-inside: auto !important;
                overflow: visible !important;
            }
            .items-table {
                display: table !important;
                width: 100% !important;
                table-layout: fixed !important;
                background: #fff !important;
                border-collapse: collapse !important;
                break-before: avoid-page !important;
                page-break-before: avoid !important;
                overflow: visible !important;
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
            .items-table thead { display: table-header-group !important; }
            .items-table tbody { display: table-row-group !important; }
            .items-table tr {
                display: table-row !important;
                break-inside: avoid !important;
                page-break-inside: avoid !important;
            }
            .items-table tbody tr,
            .items-table tbody td,
            .items-table thead th {
                break-inside: avoid !important;
                page-break-inside: avoid !important;
            }
            .items-table thead th {
                background: #f2efe8 !important;
                color: #111 !important;
                border-bottom: 1px solid #c7c0b2 !important;
            }
            .items-table tbody td {
                display: table-cell !important;
                padding: 10px 8px !important;
                color: #111 !important;
                border-bottom: 1px solid #dfd8ca !important;
                background: #fff !important;
                vertical-align: top !important;
                overflow-wrap: anywhere !important;
                word-break: normal !important;
            }
            .items-table td.desc {
                width: 48% !important;
                line-height: 1.55 !important;
            }
            .items-table tbody td::before { display: none !important; }
            .items-table tbody tr:nth-child(even) td { background: #fbfaf7 !important; }
            .item-index {
                background: #f2efe8 !important;
                color: #111 !important;
            }
            .footer {
                border-top-color: #d7d1c5 !important;
                page-break-inside: avoid;
            }
            .footer-qr img { border-color: #cfc7b8 !important; }
            .status-stamp {
                opacity: 0.06 !important;
                color: #000 !important;
                border-color: #000 !important;
            }
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

        /* Codex quotation print polish 2026-05-21 */
        @page {
            size: A4;
            margin: 10mm 15mm 12mm 10mm;
        }
        @media print {
            * {
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }
            html,
            body {
                width: auto !important;
                max-width: none !important;
                min-height: 297mm;
                background: #fff !important;
                color: #111 !important;
                font-size: 10pt !important;
                line-height: 1.45 !important;
            }
            body {
                padding: 0 !important;
                margin: 0 !important;
            }
            .container {
                width: 100% !important;
                max-width: none !important;
                margin: 0 !important;
                border: 0 !important;
                border-radius: 0 !important;
                box-shadow: none !important;
                background: #fff !important;
                overflow: visible !important;
            }
            .container::before,
            .container::after,
            .status-stamp,
            .quote-top-actions,
            .actions-bar,
            .decision-area,
            .summary-hint {
                display: none !important;
            }
            .invoice-box {
                padding: 0 !important;
                width: 100% !important;
                max-width: 100% !important;
                overflow: visible !important;
            }
            .quote-hero {
                display: grid !important;
                grid-template-columns: 1.35fr .9fr !important;
                gap: 6mm !important;
                margin: 0 0 5mm !important;
                break-inside: avoid !important;
                page-break-inside: avoid !important;
            }
            .hero-brand,
            .hero-meta-stack,
            .meta-card,
            .panel-card,
            .items-panel,
            .terms-box,
            .client-comment-box,
            .attachments-box,
            .history-box,
            .flow-box {
                border: 1px solid #cfc8ba !important;
                border-radius: 0 !important;
                background: #fff !important;
                box-shadow: none !important;
            }
            .hero-brand,
            .hero-meta-stack {
                min-height: 0 !important;
                padding: 8mm !important;
            }
            .hero-brand {
                gap: 5mm !important;
            }
            .hero-logo-wrap {
                width: 22mm !important;
                height: 22mm !important;
                border-radius: 0 !important;
                box-shadow: none !important;
            }
            .logo-img {
                max-width: 18mm !important;
                max-height: 18mm !important;
            }
            .hero-kicker {
                margin-bottom: 2mm !important;
                font-size: 8.5pt !important;
            }
            .hero-title {
                font-size: 22pt !important;
                line-height: 1.1 !important;
                margin: 0 !important;
                color: #111 !important;
            }
            .hero-subtitle,
            .hero-description,
            .hero-ref-meta {
                margin-top: 2mm !important;
                font-size: 9pt !important;
                line-height: 1.5 !important;
                color: #5f5a50 !important;
            }
            .status-chip {
                padding: 5px 10px !important;
                border-radius: 0 !important;
                font-size: 8.5pt !important;
                background: #fff !important;
            }
            .hero-ref-label {
                font-size: 7.5pt !important;
                letter-spacing: 0 !important;
            }
            .hero-ref-value {
                font-size: 15pt !important;
                letter-spacing: 0 !important;
            }
            .meta-grid {
                grid-template-columns: repeat(4, 1fr) !important;
                gap: 3mm !important;
                margin: 0 0 5mm !important;
                break-inside: avoid !important;
                page-break-inside: avoid !important;
            }
            .meta-card {
                min-height: 0 !important;
                padding: 4mm !important;
            }
            .meta-label,
            .meta-note {
                margin: 0 0 1.5mm !important;
                font-size: 8pt !important;
                color: #625d54 !important;
            }
            .meta-value {
                font-size: 10pt !important;
                line-height: 1.35 !important;
            }
            .intro-grid {
                display: grid !important;
                grid-template-columns: 1.1fr .9fr !important;
                gap: 4mm !important;
                margin: 0 0 5mm !important;
                break-inside: avoid !important;
                page-break-inside: avoid !important;
            }
            .panel-card {
                padding: 5mm !important;
            }
            .panel-title,
            .terms-title,
            .client-comment-title {
                margin: 0 0 3mm !important;
                font-size: 11pt !important;
                color: #111 !important;
            }
            .client-card .client-name {
                font-size: 14pt !important;
            }
            .client-card .client-subtitle,
            .client-pill,
            .summary-row,
            .tax-line,
            .terms-content,
            .client-comment-content {
                font-size: 9pt !important;
                line-height: 1.55 !important;
            }
            .client-pills {
                gap: 2mm !important;
                margin-top: 3mm !important;
            }
            .client-pill,
            .summary-row,
            .summary-row.final,
            .currency-reveal {
                border-radius: 0 !important;
                padding: 2.5mm 3mm !important;
                background: #fff !important;
                border: 1px solid #ddd5c8 !important;
            }
            .summary-stack {
                gap: 2mm !important;
            }
            .summary-row.final strong {
                font-size: 12pt !important;
                color: #111 !important;
            }
            .items-panel {
                padding: 0 !important;
                margin: 0 0 4mm !important;
                border: 0 !important;
                break-inside: auto !important;
                page-break-inside: auto !important;
            }
            .items-table {
                display: block !important;
                width: 100% !important;
                table-layout: auto !important;
                border-collapse: separate !important;
                border-spacing: 0 !important;
                border: 0 !important;
                margin: 0 !important;
                background: #fff !important;
                overflow: visible !important;
            }
            .items-table-caption {
                display: block !important;
                padding: 0 0 2mm !important;
                break-inside: avoid !important;
                page-break-inside: avoid !important;
            }
            .caption-copy {
                display: flex !important;
                justify-content: space-between !important;
                align-items: flex-end !important;
                gap: 4mm !important;
            }
            .caption-title {
                font-size: 12pt !important;
                color: #111 !important;
            }
            .caption-sub {
                display: none !important;
            }
            .panel-chip {
                border-radius: 0 !important;
                padding: 2mm 3mm !important;
                background: #fff !important;
                color: #111 !important;
                border: 1px solid #cfc8ba !important;
                font-size: 8.5pt !important;
            }
            .table-intro-row {
                display: none !important;
            }
            .items-table thead {
                display: none !important;
            }
            .items-table tbody {
                display: block !important;
            }
            .items-table tr {
                display: grid !important;
                grid-template-columns: 12mm minmax(0, 1fr) 30mm 30mm 32mm !important;
                border: 1px solid #bfb7a8 !important;
                border-bottom: 0 !important;
                background: #fff !important;
                break-inside: avoid-page !important;
                page-break-inside: avoid !important;
                margin: 0 !important;
                width: 100% !important;
            }
            .items-table tbody tr:last-child {
                border-bottom: 1px solid #bfb7a8 !important;
            }
            .items-table tbody tr:nth-child(even) {
                background: #fbfaf7 !important;
            }
            .items-table td {
                display: block !important;
                border: 0 !important;
                border-left: 1px solid #cfc8ba !important;
                padding: 2.3mm 2mm !important;
                font-size: 8.7pt !important;
                line-height: 1.45 !important;
                color: #111 !important;
                background: transparent !important;
                vertical-align: top !important;
                overflow-wrap: anywhere !important;
                word-break: normal !important;
                min-width: 0 !important;
                min-height: 100% !important;
            }
            .items-table td:last-child {
                border-left: 0 !important;
            }
            .items-table td::before {
                content: attr(data-label);
                display: block !important;
                margin-bottom: 1.2mm !important;
                color: #6b6256 !important;
                font-size: 7.3pt !important;
                font-weight: 800 !important;
                line-height: 1.2 !important;
            }
            .items-table td.desc {
                width: auto !important;
                font-weight: 700 !important;
            }
            .item-description-text {
                margin-top: 1.5mm !important;
                color: #3f3a32 !important;
                font-size: 8.3pt !important;
                line-height: 1.45 !important;
            }
            .item-index {
                width: auto !important;
                height: auto !important;
                min-width: 0 !important;
                border-radius: 0 !important;
                background: transparent !important;
                color: #111 !important;
            }
            .price-cell {
                direction: ltr !important;
                unicode-bidi: plaintext !important;
                white-space: nowrap !important;
                text-align: center !important;
                color: #111 !important;
            }
            .terms-box,
            .client-comment-box,
            .attachments-box,
            .history-box,
            .flow-box,
            .footer {
                padding: 4mm !important;
                margin: 0 0 4mm !important;
                border-radius: 0 !important;
                break-inside: avoid-page !important;
                page-break-inside: avoid !important;
            }
            .terms-content {
                columns: 1 !important;
                column-gap: 0 !important;
                color: #111 !important;
                font-size: 9pt !important;
            }
            .footer {
                border-top: 1px solid #cfc8ba !important;
                margin-top: 4mm !important;
            }
            .footer-content {
                gap: 4mm !important;
            }
            a {
                color: inherit !important;
                text-decoration: none !important;
            }
        }
        .quote-print-document {
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
            .quote-print-document {
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
            .quote-print-document,
            .quote-print-document * {
                box-sizing: border-box !important;
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }
            .quote-print-sheet {
                width: 100% !important;
                max-width: 100% !important;
                color: #111 !important;
                background: #fff !important;
            }
            .qp-header {
                display: grid;
                grid-template-columns: 23mm minmax(0, 1fr) 38mm;
                gap: 5mm;
                align-items: center;
                padding-bottom: 4mm;
                border-bottom: 2px solid #111;
                break-inside: avoid;
                page-break-inside: avoid;
            }
            .qp-logo {
                width: 20mm;
                height: 20mm;
                object-fit: contain;
                border: 1px solid #d4cbbb;
                padding: 2mm;
            }
            .qp-title h1 {
                margin: 0;
                font-size: 19pt;
                line-height: 1.15;
                color: #111;
            }
            .qp-title p {
                margin: 1mm 0 0;
                color: #444;
                font-size: 9pt;
            }
            .qp-status {
                text-align: center;
                border: 1px solid #bdb4a5;
                padding: 2.5mm;
                font-weight: 800;
                color: #111;
            }
            .qp-grid {
                display: grid;
                grid-template-columns: repeat(4, 1fr);
                gap: 2.5mm;
                margin: 4mm 0;
                break-inside: avoid;
                page-break-inside: avoid;
            }
            .qp-box {
                border: 1px solid #cfc7b8;
                padding: 2.5mm;
                min-height: 14mm;
            }
            .qp-label {
                display: block;
                color: #5d554b;
                font-size: 7pt;
                font-weight: 800;
                margin-bottom: 1.5mm;
            }
            .qp-value {
                display: block;
                color: #111;
                font-weight: 900;
                font-size: 9.6pt;
                line-height: 1.35;
                overflow-wrap: anywhere;
            }
            .qp-two {
                display: grid;
                grid-template-columns: 1.1fr .9fr;
                gap: 3mm;
                margin-bottom: 4mm;
                break-inside: avoid;
                page-break-inside: avoid;
            }
            .qp-panel {
                border: 1px solid #cfc7b8;
                padding: 3mm;
            }
            .qp-panel h2,
            .qp-section-title {
                margin: 0 0 3mm;
                color: #111;
                font-size: 11pt;
                line-height: 1.25;
            }
            .qp-line {
                display: flex;
                justify-content: space-between;
                gap: 5mm;
                border-bottom: 1px solid #e2dacc;
                padding: 1.25mm 0;
            }
            .qp-line:last-child {
                border-bottom: 0;
            }
            .qp-money {
                direction: ltr;
                unicode-bidi: plaintext;
                white-space: nowrap;
                color: #111;
                font-weight: 900;
            }
            .qp-items {
                margin-top: 3mm;
            }
            .qp-page-break {
                break-before: page;
                page-break-before: always;
                height: 0;
                overflow: hidden;
            }
            .qp-item {
                border: 1px solid #bfb6a7;
                margin: 0 0 2mm;
                break-inside: avoid-page;
                page-break-inside: avoid;
                background: #fff;
            }
            .qp-item-head,
            .qp-item-body {
                display: grid;
                grid-template-columns: 10mm minmax(0, 1fr) 27mm 27mm 30mm;
            }
            .qp-item-head {
                background: #f2eee6;
                border-bottom: 1px solid #bfb6a7;
                font-weight: 900;
            }
            .qp-cell {
                padding: 1.55mm 1.7mm;
                border-left: 1px solid #cfc7b8;
                min-width: 0;
                color: #111;
                overflow-wrap: anywhere;
            }
            .qp-cell:last-child {
                border-left: 0;
            }
            .qp-item-name {
                font-weight: 900;
                color: #111;
            }
            .qp-item-desc {
                margin-top: 1mm;
                color: #333;
                font-size: 7.7pt;
                line-height: 1.32;
                white-space: pre-line;
            }
            .qp-tax-lines {
                margin-top: 3mm;
                border: 1px solid #cfc7b8;
                padding: 2.5mm 3mm;
                break-inside: avoid;
                page-break-inside: avoid;
            }
            .qp-terms {
                border: 1px solid #cfc7b8;
                padding: 3mm;
                margin-top: 4mm;
                color: #111;
            }
            .qp-terms-content {
                white-space: pre-line;
                color: #111;
                font-size: 7.6pt;
                line-height: 1.36;
            }
            .qp-footer {
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
            .qp-footer p {
                margin: 0 0 1mm;
                color: #111;
                font-size: 7.8pt;
            }
            .qp-qr {
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
        <div class="quote-top-actions">
            <a href="<?php echo app_h($backHref); ?>" class="quote-top-back">
                <i class="fa-solid fa-arrow-right"></i> <?php echo app_h($backLabel); ?>
            </a>
        </div>
        
        <div class="status-stamp st-<?php echo app_h($status_value); ?>">
            <?php echo app_h($statusUi['stamp']); ?>
        </div>

        <section class="quote-hero">
            <div class="hero-brand">
                <div class="hero-logo-wrap">
                    <img src="<?php echo app_h($appLogo); ?>" alt="<?php echo app_h($appName); ?>" class="logo-img">
                </div>
                <div class="hero-copy">
                    <div class="hero-kicker"><i class="fa-solid fa-file-signature"></i> <?php echo app_h($quoteKindLabel); ?></div>
                    <h1 class="hero-title">عرض سعر</h1>
                    <div class="hero-subtitle"><?php echo app_h($appName); ?></div>
                    <div class="hero-description"><?php echo app_h($statusUi['desc']); ?></div>
                </div>
            </div>
            <div class="hero-meta-stack">
                <div class="status-chip <?php echo app_h($status_value); ?>">
                    <i class="fa-solid fa-circle-check"></i> <?php echo app_h($statusUi['label']); ?>
                </div>
                <div class="hero-ref">
                    <span class="hero-ref-label">Quotation Reference</span>
                    <span class="hero-ref-value"><?php echo app_h($quoteRef); ?></span>
                    <div class="hero-ref-meta">
                        <?php if ($sourceRef !== ''): ?>
                            <div>مرجع التسعير: <strong><?php echo app_h($sourceRef); ?></strong></div>
                        <?php endif; ?>
                        <?php if ($linkedInvoiceId > 0): ?>
                            <div>مرتبطة بفاتورة تشغيلية رقم <strong>#<?php echo (int)$linkedInvoiceId; ?></strong></div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </section>

        <section class="meta-grid">
            <div class="meta-card">
                <span class="meta-label">رقم العرض</span>
                <span class="meta-value"><?php echo app_h($quoteRef); ?></span>
                <span class="meta-note">معرف داخلي: #<?php echo (int)$quote_id; ?></span>
            </div>
            <div class="meta-card">
                <span class="meta-label">تاريخ الإصدار</span>
                <span class="meta-value"><?php echo app_h($issuedAtLabel !== '' ? $issuedAtLabel : '-'); ?></span>
                <span class="meta-note">تم إنشاء العرض من داخل النظام مباشرة</span>
            </div>
            <div class="meta-card">
                <span class="meta-label">صالح حتى</span>
                <span class="meta-value"><?php echo app_h($validUntilLabel !== '' ? $validUntilLabel : '-'); ?></span>
                <span class="meta-note">يرجى مراجعة الأسعار بعد انتهاء المدة</span>
            </div>
            <div class="meta-card">
                <span class="meta-label">عدد البنود</span>
                <span class="meta-value"><?php echo number_format($itemsCount); ?> بند</span>
                <span class="meta-note">الإجمالي النهائي: <?php echo app_h($quoteMoney((float)$quote['total_amount'])); ?></span>
            </div>
        </section>

        <section class="intro-grid">
            <div class="panel-card client-card">
                <h2 class="panel-title"><i class="fa-solid fa-user-tie"></i> بيانات العميل</h2>
                <h3 class="client-name"><?php echo app_h((string)$quote['client_name']); ?></h3>
                <p class="client-subtitle">نقدم هذا العرض للعميل المذكور مع التفاصيل المالية والتنفيذية المرتبطة بكل بند بصورة واضحة وقابلة للطباعة المباشرة.</p>
                <div class="client-pills">
                    <?php if ($clientPhone !== ''): ?>
                    <span class="client-pill"><i class="fa-solid fa-phone"></i> <?php echo app_h($clientPhone); ?></span>
                    <?php endif; ?>
                    <?php if ($clientAddress !== ''): ?>
                    <span class="client-pill"><i class="fa-solid fa-location-dot"></i> <?php echo app_h($clientAddress); ?></span>
                    <?php endif; ?>
                    <span class="client-pill"><i class="fa-solid fa-calendar-check"></i> الصلاحية حتى <?php echo app_h($validUntilLabel !== '' ? $validUntilLabel : '-'); ?></span>
                </div>
            </div>

            <aside class="panel-card summary-card">
                <h2 class="panel-title"><i class="fa-solid fa-scale-balanced"></i> الملخص المالي</h2>
                <div class="summary-stack">
                    <div class="summary-row">
                        <span>إجمالي البنود</span>
                        <strong><?php echo app_h($quoteMoney($subtotalAmount)); ?></strong>
                    </div>
                    <?php if ($taxAddAmount > 0): ?>
                    <div class="summary-row">
                        <span>إضافات ضريبية ورسوم</span>
                        <strong>+<?php echo app_h($quoteMoney($taxAddAmount)); ?></strong>
                    </div>
                    <?php endif; ?>
                    <?php if ($taxSubtractAmount > 0): ?>
                    <div class="summary-row">
                        <span>خصومات أو استقطاعات</span>
                        <strong>-<?php echo app_h($quoteMoney($taxSubtractAmount)); ?></strong>
                    </div>
                    <?php endif; ?>
                    <div class="summary-row final">
                        <span>الإجمالي النهائي</span>
                        <strong><?php echo app_h($quoteMoney((float)$quote['total_amount'])); ?></strong>
                    </div>
                </div>
                <?php if ($quoteCurrencyCode !== $quoteBaseCurrency && $quoteExchangeRate > 0): ?>
                <div class="currency-reveal">عملة العرض: <?php echo app_h($quoteCurrencyCode); ?> · سعر التحويل المحفوظ: 1 <?php echo app_h($quoteCurrencyCode); ?> = <?php echo number_format($quoteExchangeRate, 4); ?> <?php echo app_h($quoteBaseCurrency); ?><?php if ($quoteBaseTotal > 0): ?> · المكافئ: <?php echo app_h(app_quote_format_money($quoteBaseTotal, $quoteBaseCurrency)); ?><?php endif; ?></div>
                <?php endif; ?>
                <div class="summary-hint">الأسعار الموضحة أدناه هي القيم النهائية المعتمدة داخل العرض، وتظهر في نسخة الطباعة وملف الـPDF بنفس التنسيق.</div>
            </aside>
        </section>

        <section class="items-panel">
            <table class="items-table">
                <caption class="items-table-caption">
                    <span class="caption-copy">
                        <span class="caption-copy-main">
                            <span class="caption-title">بنود العرض التفصيلية</span>
                            <span class="caption-sub">تفاصيل كل بند مع الكمية وسعر الوحدة والإجمالي، بصياغة أوضح للشاشة والطباعة.</span>
                        </span>
                        <span class="panel-chip"><i class="fa-solid fa-layer-group"></i> <?php echo number_format($itemsCount); ?> بند</span>
                    </span>
                </caption>
                <thead>
                    <tr class="table-intro-row">
                        <th colspan="5" class="table-intro-cell">
                            <div class="table-intro-copy">
                                <div class="table-intro-main">
                                    <div class="table-intro-title">بنود العرض التفصيلية</div>
                                    <div class="table-intro-sub">تفاصيل كل بند مع الكمية وسعر الوحدة والإجمالي، بصياغة أوضح للشاشة والطباعة.</div>
                                </div>
                                <div class="panel-chip"><i class="fa-solid fa-layer-group"></i> <?php echo number_format($itemsCount); ?> بند</div>
                            </div>
                        </th>
                    </tr>
                    <tr>
                        <th width="7%">#</th>
                        <th width="48%">البيان / الوصف</th>
                        <th width="15%">الكمية</th>
                        <th width="15%">سعر الوحدة</th>
                        <th width="15%">الإجمالي</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($quoteItems)): ?>
                    <?php foreach ($quoteItems as $index => $item): ?>
                    <?php
                        $unitVal = trim((string)($item['unit'] ?? ''));
                        $qtyLabel = number_format((float)$item['quantity'], 2);
                        if ($unitVal !== '') {
                            $qtyLabel .= ' ' . $unitVal;
                        }
                    ?>
                    <tr>
                        <td data-label="الرقم"><span class="item-index"><?php echo (int)($index + 1); ?></span></td>
                        <?php $itemDescription = trim((string)($item['description'] ?? '')); ?>
                        <td data-label="البيان / الوصف" class="desc">
                            <?php echo nl2br(app_h((string)$item['item_name'])); ?>
                            <?php if ($itemDescription !== ''): ?>
                                <span class="item-description-text"><?php echo nl2br(app_h($itemDescription)); ?></span>
                            <?php endif; ?>
                        </td>
                        <td data-label="الكمية"><?php echo app_h($qtyLabel); ?></td>
                        <td data-label="سعر الوحدة" class="price-cell"><?php echo app_h($quoteMoney((float)$item['price'])); ?></td>
                        <td data-label="الإجمالي" class="price-cell"><?php echo app_h($quoteMoney((float)$item['total'])); ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php else: ?>
                    <tr>
                        <td colspan="5" class="empty-items">لا توجد بنود مسجلة داخل عرض السعر حتى الآن.</td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>

            <?php if (!empty($quoteTaxLines)): ?>
            <div class="tax-lines">
                <?php foreach ($quoteTaxLines as $taxLine): ?>
                <?php
                    $taxMode = (string)($taxLine['mode'] ?? 'add');
                    $taxName = (string)($taxLine['name'] ?? 'ضريبة');
                    $taxAmount = (float)($taxLine['amount'] ?? 0);
                ?>
                <div class="tax-line <?php echo $taxMode === 'subtract' ? 'subtract' : 'add'; ?>">
                    <span><?php echo app_h($taxName); ?></span>
                    <span><?php echo ($taxMode === 'subtract' ? '-' : '+') . app_h($quoteMoney($taxAmount)); ?></span>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </section>

        <section class="terms-box">
            <h2 class="terms-title"><i class="fa-solid fa-gavel"></i> الشروط والأحكام والملاحظات</h2>
            <div class="terms-content<?php echo $notesText === '' ? ' terms-empty' : ''; ?>">
                <?php echo $notesText !== '' ? nl2br(app_h($notesText)) : 'لا توجد ملاحظات إضافية مسجلة على هذا العرض.'; ?>
            </div>
        </section>

        <?php if ($clientCommentText !== '' && $status_value !== 'pending'): ?>
        <section class="client-comment-box">
            <h2 class="client-comment-title"><i class="fa-solid fa-comments"></i> ملاحظة العميل</h2>
            <?php if($quoteRejectionReason !== ''): ?><div style="margin-bottom:10px;color:#ffc1bd;font-weight:800;">سبب الرفض: <?php echo app_h($quoteRejectionReason); ?></div><?php endif; ?>
            <div class="client-comment-content"><?php echo nl2br(app_h($clientCommentText)); ?></div>
        </section>
        <?php endif; ?>

        <?php if (!empty($quoteAttachments)): ?>
        <section class="attachments-box">
            <h2 class="terms-title"><i class="fa-solid fa-paperclip"></i> مرفقات العرض</h2>
            <div class="attachment-list">
                <?php foreach ($quoteAttachments as $att): ?>
                    <a class="attachment-link" href="<?php echo app_h((string)$att['file_path']); ?>" target="_blank" rel="noopener">
                        <span><i class="fa-solid fa-file"></i> <?php echo app_h((string)$att['file_name']); ?></span>
                        <small><?php echo number_format(((int)($att['file_size'] ?? 0)) / 1024, 1); ?> KB</small>
                    </a>
                <?php endforeach; ?>
            </div>
        </section>
        <?php endif; ?>


        <?php if ($access_mode === 'id' && !empty($quoteHistoryRows)): ?>
        <section class="history-box">
            <h2 class="terms-title"><i class="fa-solid fa-clock-rotate-left"></i> سجل تغييرات العرض</h2>
            <div class="history-list">
                <?php foreach ($quoteHistoryRows as $histRow): ?>
                    <div class="history-item">
                        <span><?php echo app_h((string)($histRow['event_label'] ?? 'تحديث')); ?><?php if(trim((string)($histRow['user_name'] ?? '')) !== ''): ?> · <?php echo app_h((string)$histRow['user_name']); ?><?php endif; ?></span>
                        <small><?php echo app_h((string)($histRow['created_at'] ?? '')); ?></small>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
        <?php endif; ?>

        <?php if ($flowNotice !== ''): ?>
        <?php
            $flowBg = 'rgba(52,152,219,0.08)';
            $flowBorder = 'rgba(52,152,219,0.35)';
            $flowColor = '#bfe6ff';
            if ($flowNoticeTone === 'success') {
                $flowBg = 'rgba(46,204,113,0.08)';
                $flowBorder = 'rgba(46,204,113,0.35)';
                $flowColor = '#bdf3cf';
            } elseif ($flowNoticeTone === 'warning') {
                $flowBg = 'rgba(241,196,15,0.08)';
                $flowBorder = 'rgba(241,196,15,0.35)';
                $flowColor = '#ffe9a6';
            } elseif ($flowNoticeTone === 'danger') {
                $flowBg = 'rgba(231,76,60,0.08)';
                $flowBorder = 'rgba(231,76,60,0.35)';
                $flowColor = '#ffb7b7';
            }
        ?>
        <div class="flow-box" style="background:<?php echo $flowBg; ?>; border-color:<?php echo $flowBorder; ?>; color:<?php echo $flowColor; ?>;">
            <i class="fa-solid fa-circle-info"></i> <?php echo app_h($flowNotice); ?>
        </div>
        <?php endif; ?>
        <?php if ($conversionNotice !== ''): ?>
        <div class="flow-box" style="background:rgba(46,204,113,0.08); border-color:rgba(46,204,113,0.35); color:#bdf3cf;">
            <i class="fa-solid fa-file-invoice-dollar"></i> <?php echo app_h($conversionNotice); ?>
        </div>
        <?php endif; ?>
        <?php if ($conversionError !== ''): ?>
        <div class="flow-box" style="background:rgba(231,76,60,0.08); border-color:rgba(231,76,60,0.35); color:#ffb7b7;">
            <i class="fa-solid fa-triangle-exclamation"></i> <?php echo app_h('تعذر تحويل العرض إلى فاتورة حالياً: ' . $conversionError); ?>
        </div>
        <?php endif; ?>

        <?php if($status_value == 'pending' && $quoteReviewClosed): ?>
        <div class="decision-area" style="border-color:rgba(230,126,34,.35); background:rgba(230,126,34,.08);">
            <div class="decision-title">مراجعة العميل لهذا العرض مغلقة حالياً</div>
            <div style="color:#f8d7a5; line-height:1.8;">لن تظهر أزرار الموافقة أو الرفض للعميل حتى يتم فتح المراجعة مرة أخرى من إدارة العروض.<?php if($quoteReviewClosedReason !== ''): ?> السبب: <?php echo app_h($quoteReviewClosedReason); ?><?php endif; ?></div>
        </div>
        <?php elseif($status_value == 'pending'): ?>
        <div class="decision-area">
            <div class="decision-title">يرجى اتخاذ قرار بشأن هذا العرض</div>
            <form method="POST" action="">
                <?php echo app_csrf_input(); ?>
                <?php if($internalApprovalStatus === 'pending'): ?>
                    <div style="margin-bottom:12px;color:#ffe9a6;line-height:1.8;">هذا العرض تحت اعتماد داخلي قبل التنفيذ. يمكن للعميل إرسال ملاحظته، وستراجع الإدارة الاعتماد النهائي.</div>
                <?php endif; ?>
                <div class="client-note-wrapper">
                    <textarea name="client_note" class="client-note" placeholder="هل لديك أي ملاحظات أو استفسارات؟ اكتبها هنا..."></textarea>
                </div>
                <div class="client-note-wrapper" style="margin-top:12px;">
                    <select name="quote_rejection_reason" class="client-note" style="min-height:52px;">
                        <option value="">سبب الرفض عند الاعتذار</option>
                        <?php foreach (app_quote_rejection_reasons() as $reason): ?>
                            <option value="<?php echo app_h($reason); ?>"><?php echo app_h($reason); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="decision-btns">
                    <button type="submit" name="action" value="approve" class="btn-decision btn-accept" onclick="return confirm('هل أنت متأكد من الموافقة؟')">
                        <i class="fa-solid fa-check-circle"></i> موافقة واعتماد
                    </button>
                    <button type="submit" name="action" value="reject" class="btn-decision btn-reject" onclick="return confirm('هل أنت متأكد من الرفض؟')">
                        <i class="fa-solid fa-times-circle"></i> اعتذار / رفض
                    </button>
                </div>
            </form>
        </div>
        <?php else: ?>
            <div style="text-align:center; margin-top:40px; padding:20px; background:rgba(255,255,255,0.05); border-radius:10px; border:1px solid var(--border);">
                <div style="font-size:1.1rem; margin-bottom:10px;">حالة العرض الحالية:</div>
                <?php if($status_value == 'approved'): ?>
                    <div style="color:#2ecc71; font-size:1.5rem; font-weight:bold;"><i class="fa-solid fa-check-circle"></i> تمت الموافقة من قبل العميل</div>
                    <?php if($access_mode === 'id' && $linkedInvoiceId > 0): ?>
                        <div style="margin-top:14px;">
                            <a href="print_invoice.php?id=<?php echo (int)$linkedInvoiceId; ?>&token=<?php echo app_h($linkedInvoiceToken); ?>" target="_blank" style="display:inline-flex;align-items:center;gap:8px;padding:12px 18px;border-radius:999px;background:rgba(212,175,55,0.12);border:1px solid rgba(212,175,55,0.4);color:var(--gold);text-decoration:none;font-weight:800;">
                                <i class="fa-solid fa-file-invoice"></i> فتح الفاتورة التشغيلية
                            </a>
                        </div>
                    <?php elseif($access_mode === 'id'): ?>
                        <form method="POST" action="" style="margin-top:14px;">
                            <?php echo app_csrf_input(); ?>
                            <button type="submit" name="action" value="convert_to_invoice" class="btn-decision btn-accept" style="margin:0 auto;">
                                <i class="fa-solid fa-file-circle-plus"></i> إنشاء فاتورة تشغيلية
                            </button>
                        </form>
                    <?php endif; ?>
                <?php elseif($status_value == 'rejected'): ?>
                    <div style="color:#e74c3c; font-size:1.5rem; font-weight:bold;"><i class="fa-solid fa-times-circle"></i> تم رفض العرض</div>
                <?php else: ?>
                    <div style="color:#f1c40f; font-size:1.5rem; font-weight:bold;"><i class="fa-solid fa-hourglass-half"></i> العرض قيد المراجعة</div>
                <?php endif; ?>
                
                <?php if(!empty($quote['client_comment'])): ?>
                    <div style="margin-top:15px; color:#aaa; font-style:italic;">"<?php echo app_h((string)$quote['client_comment']); ?>"</div>
                <?php endif; ?>
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
                        <p><?php echo app_h((string)$brandProfile['org_footer_note']); ?></p>
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

<div class="quote-print-document" aria-hidden="true">
    <div class="quote-print-sheet">
        <header class="qp-header">
            <img class="qp-logo" src="<?php echo app_h($appLogo); ?>" alt="<?php echo app_h($appName); ?>">
            <div class="qp-title">
                <h1>عرض سعر</h1>
                <p><?php echo app_h($appName); ?> · <?php echo app_h($quoteKindLabel); ?></p>
            </div>
            <div class="qp-status"><?php echo app_h($statusUi['label']); ?></div>
        </header>

        <section class="qp-grid">
            <div class="qp-box">
                <span class="qp-label">رقم العرض</span>
                <span class="qp-value"><?php echo app_h($quoteRef); ?></span>
            </div>
            <div class="qp-box">
                <span class="qp-label">تاريخ الإصدار</span>
                <span class="qp-value"><?php echo app_h($issuedAtLabel !== '' ? $issuedAtLabel : '-'); ?></span>
            </div>
            <div class="qp-box">
                <span class="qp-label">صالح حتى</span>
                <span class="qp-value"><?php echo app_h($validUntilLabel !== '' ? $validUntilLabel : '-'); ?></span>
            </div>
            <div class="qp-box">
                <span class="qp-label">إجمالي العرض</span>
                <span class="qp-value qp-money"><?php echo app_h($quoteMoney((float)$quote['total_amount'])); ?></span>
            </div>
        </section>

        <section class="qp-two">
            <div class="qp-panel">
                <h2>بيانات العميل</h2>
                <div class="qp-line">
                    <span>العميل</span>
                    <strong><?php echo app_h((string)$quote['client_name']); ?></strong>
                </div>
                <?php if ($clientPhone !== ''): ?>
                <div class="qp-line">
                    <span>الهاتف</span>
                    <strong><?php echo app_h($clientPhone); ?></strong>
                </div>
                <?php endif; ?>
                <?php if ($clientAddress !== ''): ?>
                <div class="qp-line">
                    <span>العنوان</span>
                    <strong><?php echo app_h($clientAddress); ?></strong>
                </div>
                <?php endif; ?>
            </div>
            <div class="qp-panel">
                <h2>ملخص العرض</h2>
                <div class="qp-line">
                    <span>عدد البنود</span>
                    <strong><?php echo number_format($itemsCount); ?></strong>
                </div>
                <div class="qp-line">
                    <span>الإجمالي قبل الضرائب</span>
                    <strong class="qp-money"><?php echo app_h($quoteMoney($subtotalAmount)); ?></strong>
                </div>
                <?php if ($taxAddAmount > 0): ?>
                <div class="qp-line">
                    <span>ضرائب مضافة</span>
                    <strong class="qp-money">+<?php echo app_h($quoteMoney($taxAddAmount)); ?></strong>
                </div>
                <?php endif; ?>
                <?php if ($taxSubtractAmount > 0): ?>
                <div class="qp-line">
                    <span>خصومات / استقطاعات</span>
                    <strong class="qp-money">-<?php echo app_h($quoteMoney($taxSubtractAmount)); ?></strong>
                </div>
                <?php endif; ?>
                <div class="qp-line">
                    <span>الإجمالي النهائي</span>
                    <strong class="qp-money"><?php echo app_h($quoteMoney((float)$quote['total_amount'])); ?></strong>
                </div>
                <?php if ($quoteCurrencyCode !== $quoteBaseCurrency && $quoteExchangeRate > 0): ?>
                <div class="qp-line">
                    <span>سعر التحويل</span>
                    <strong>1 <?php echo app_h($quoteCurrencyCode); ?> = <?php echo number_format($quoteExchangeRate, 4); ?> <?php echo app_h($quoteBaseCurrency); ?></strong>
                </div>
                <?php endif; ?>
            </div>
        </section>

        <section class="qp-items">
            <h2 class="qp-section-title">بنود العرض التفصيلية</h2>
            <?php if (!empty($quoteItems)): ?>
            <?php
                $qpPrintBudget = 13;
                $qpPrintNextBudget = 46;
                $qpPrintUsed = 0;
            ?>
            <?php foreach ($quoteItems as $index => $item): ?>
            <?php
                $unitVal = trim((string)($item['unit'] ?? ''));
                $qtyLabel = number_format((float)$item['quantity'], 2);
                if ($unitVal !== '') {
                    $qtyLabel .= ' ' . $unitVal;
                }
                $itemDescription = trim((string)($item['description'] ?? ''));
                $qpLineCount = 0;
                $qpRawLines = preg_split('/\R/u', trim((string)($item['item_name'] ?? '') . "\n" . $itemDescription));
                if (!is_array($qpRawLines) || empty($qpRawLines)) {
                    $qpRawLines = [''];
                }
                foreach ($qpRawLines as $qpRawLine) {
                    $qpRawLine = trim((string)$qpRawLine);
                    $qpLineLength = function_exists('mb_strlen') ? mb_strlen($qpRawLine, 'UTF-8') : strlen($qpRawLine);
                    $qpLineCount += max(1, (int)ceil($qpLineLength / 58));
                }
                $qpItemWeight = 3 + $qpLineCount;
            ?>
            <?php if ($qpPrintUsed > 0 && ($qpPrintUsed + $qpItemWeight) > $qpPrintBudget): ?>
            <div class="qp-page-break"></div>
            <?php
                $qpPrintUsed = 0;
                $qpPrintBudget = $qpPrintNextBudget;
            ?>
            <?php endif; ?>
            <?php $qpPrintUsed += $qpItemWeight; ?>
            <article class="qp-item">
                <div class="qp-item-head">
                    <div class="qp-cell">#</div>
                    <div class="qp-cell">البيان / الوصف</div>
                    <div class="qp-cell">الكمية</div>
                    <div class="qp-cell">سعر الوحدة</div>
                    <div class="qp-cell">الإجمالي</div>
                </div>
                <div class="qp-item-body">
                    <div class="qp-cell"><?php echo (int)($index + 1); ?></div>
                    <div class="qp-cell">
                        <div class="qp-item-name"><?php echo nl2br(app_h((string)$item['item_name'])); ?></div>
                        <?php if ($itemDescription !== ''): ?>
                        <div class="qp-item-desc"><?php echo nl2br(app_h($itemDescription)); ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="qp-cell"><?php echo app_h($qtyLabel); ?></div>
                    <div class="qp-cell qp-money"><?php echo app_h($quoteMoney((float)$item['price'])); ?></div>
                    <div class="qp-cell qp-money"><?php echo app_h($quoteMoney((float)$item['total'])); ?></div>
                </div>
            </article>
            <?php endforeach; ?>
            <?php else: ?>
            <div class="qp-panel">لا توجد بنود مسجلة داخل عرض السعر حتى الآن.</div>
            <?php endif; ?>
        </section>

        <?php if (!empty($quoteTaxLines)): ?>
        <section class="qp-tax-lines">
            <?php foreach ($quoteTaxLines as $taxLine): ?>
            <?php
                $taxMode = (string)($taxLine['mode'] ?? 'add');
                $taxName = (string)($taxLine['name'] ?? 'ضريبة');
                $taxAmount = (float)($taxLine['amount'] ?? 0);
            ?>
            <div class="qp-line">
                <span><?php echo app_h($taxName); ?></span>
                <strong class="qp-money"><?php echo ($taxMode === 'subtract' ? '-' : '+') . app_h($quoteMoney($taxAmount)); ?></strong>
            </div>
            <?php endforeach; ?>
        </section>
        <?php endif; ?>

        <section class="qp-terms">
            <h2 class="qp-section-title">الشروط والأحكام والملاحظات</h2>
            <div class="qp-terms-content"><?php echo $notesText !== '' ? nl2br(app_h($notesText)) : 'لا توجد ملاحظات إضافية مسجلة على هذا العرض.'; ?></div>
        </section>

        <?php if ($outputShowFooter): ?>
        <footer class="qp-footer">
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
                <img class="qp-qr" src="<?php echo app_h($qrUrl); ?>" alt="QR">
            <?php endif; ?>
        </footer>
        <?php endif; ?>
    </div>
</div>

<div class="actions-bar">
    <a href="<?php echo app_h($backHref); ?>" class="btn btn-back">
        <i class="fa-solid fa-arrow-right"></i> <?php echo app_h($backLabel); ?>
    </a>
    <?php if($access_mode == 'id'): ?>
    <a href="https://wa.me/<?php echo app_h($whatsapp_phone); ?>?text=<?php echo rawurlencode($wa_msg); ?>" target="_blank" class="btn btn-wa">
        <i class="fa-brands fa-whatsapp"></i> إرسال للعميل
    </a>
    <?php if($status_value === 'approved' && $linkedInvoiceId > 0): ?>
    <a href="print_invoice.php?id=<?php echo (int)$linkedInvoiceId; ?>&token=<?php echo app_h($linkedInvoiceToken); ?>" target="_blank" class="btn btn-back" style="background:#1f3a2a; color:#bdf3cf;">
        <i class="fa-solid fa-file-invoice"></i> الفاتورة
    </a>
    <?php endif; ?>
    <?php endif; ?>
    <button onclick="window.print()" class="btn btn-print">
        <i class="fa-solid fa-print"></i> طباعة / PDF
    </button>
</div>

</body>
</html>
