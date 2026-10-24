<?php
// view_order.php - (Royal Print Order View - Invoice Style)
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
$id = intval($_GET['id'] ?? 0);
$token = trim((string)($_GET['token'] ?? ''));

// التحقق من التوكن
$stmtJob = $conn->prepare("SELECT * FROM job_orders WHERE id = ? LIMIT 1");
$stmtJob->bind_param("i", $id);
$stmtJob->execute();
$res = $stmtJob->get_result();
if(!$res || $res->num_rows==0) die("أمر الشغل غير موجود");
$job = $res->fetch_assoc();
$stmtJob->close();

if (!hash_equals((string)($job['access_token'] ?? ''), $token)) {
    die("<div style='height:100vh; display:flex; align-items:center; justify-content:center; background:#000; color:#d4af37; font-family:sans-serif;'>
            <div style='text-align:center;'>
                <h1 style='font-size:2rem; margin:0;'>تنبيه</h1>
                <h2 style='color:#fff;'>رابط غير صالح</h2>
            </div>
         </div>");
}

$currentUrl = ((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? '') . ($_SERVER['REQUEST_URI'] ?? '');
$qrPayload = app_brand_qr_payload($brandProfile, [
    'Document' => 'Work Order View',
    'Reference' => (string)($job['job_number'] ?? ('#' . (int)$id)),
    'Operation' => (string)($job['job_title'] ?? ''),
    'Link' => $currentUrl,
]);
$qrUrl = ($outputShowQr && $qrPayload !== '') ? app_brand_qr_url($qrPayload, 140) : '';

// 2. استخراج البيانات الفنية (نفس منطق النظام)
$raw_text = $job['job_details'] ?? '';
function get_spec($pattern, $text, $default = '-') {
    if(empty($text)) return $default;
    preg_match($pattern, $text, $matches);
    return isset($matches[1]) ? trim($matches[1]) : $default;
}

$specs = [
    'p_size'     => get_spec('/مقاس الورق:.*?([\d\.]+\s*x\s*[\d\.]+)/u', $raw_text, 'غير محدد'),
    'c_size'     => get_spec('/مقاس القص:.*?([\d\.]+\s*x\s*[\d\.]+)/u', $raw_text, 'غير محدد'),
    'machine'    => get_spec('/الماكينة: (.*?)(?:\||$)/u', $raw_text, 'غير محدد'),
    'print_face' => get_spec('/الوجه: (.*?)(?:\||$)/u', $raw_text, 'غير محدد'),
    'colors'     => get_spec('/الألوان: (.*?)(?:\||$)/u', $raw_text, 'غير محدد'),
    'zinc'       => get_spec('/الزنكات: ([\d\.]+)/u', $raw_text, '0'),
    'finish'     => get_spec('/التكميلي: (.*?)(?:\||$)/u', $raw_text, 'غير محدد'),
];

function parse_job_detail_rows(string $rawText): array {
    $rows = [];
    $lines = preg_split('/\R+/u', $rawText);
    foreach ((array)$lines as $line) {
        $line = trim((string)$line);
        if ($line === '' || strpos($line, '---') === 0) {
            continue;
        }
        if (preg_match('/^([^:：]+)\s*[:：]\s*(.+)$/u', $line, $m)) {
            $rows[] = [
                'label' => trim((string)$m[1]),
                'value' => trim((string)$m[2]),
            ];
        } else {
            $rows[] = [
                'label' => 'تفصيل',
                'value' => $line,
            ];
        }
    }
    return $rows;
}

function detail_icon_for_label(string $label): string {
    $labelKey = function_exists('mb_strtolower') ? mb_strtolower($label, 'UTF-8') : strtolower($label);
    if (strpos($labelKey, 'ورق') !== false || strpos($labelKey, 'خامة') !== false) return 'fa-boxes-stacked';
    if (strpos($labelKey, 'مقاس') !== false || strpos($labelKey, 'قص') !== false) return 'fa-ruler-combined';
    if (strpos($labelKey, 'لون') !== false) return 'fa-palette';
    if (strpos($labelKey, 'طباعة') !== false || strpos($labelKey, 'ماكينة') !== false) return 'fa-print';
    if (strpos($labelKey, 'زنك') !== false || strpos($labelKey, 'سلندر') !== false) return 'fa-layer-group';
    if (strpos($labelKey, 'تشطيب') !== false || strpos($labelKey, 'بعد الطباعة') !== false) return 'fa-wand-magic-sparkles';
    if (strpos($labelKey, 'منصة') !== false || strpos($labelKey, 'محتوى') !== false) return 'fa-hashtag';
    if (strpos($labelKey, 'موقع') !== false || strpos($labelKey, 'دومين') !== false) return 'fa-globe';
    return 'fa-circle-dot';
}

$jobTypeMap = [
    'print' => 'طباعة',
    'carton' => 'كرتون',
    'plastic' => 'بلاستيك',
    'social' => 'تسويق إلكتروني',
    'web' => 'مواقع وبرمجة',
    'design_only' => 'تصميم فقط',
];
$jobType = (string)($job['job_type'] ?? '');
$jobTypeLabel = $jobTypeMap[$jobType] ?? $jobType;
$detailRows = parse_job_detail_rows((string)$raw_text);

$summaryRows = [];
if ($jobType === 'print') {
    $summaryRows = [
        ['label' => 'مقاس الورق', 'value' => (string)$specs['p_size'], 'icon' => 'fa-scroll'],
        ['label' => 'مقاس القص', 'value' => (string)$specs['c_size'], 'icon' => 'fa-scissors'],
        ['label' => 'الماكينة', 'value' => (string)$specs['machine'], 'icon' => 'fa-print'],
        ['label' => 'الألوان', 'value' => (string)$specs['colors'], 'icon' => 'fa-palette'],
        ['label' => 'الوجه', 'value' => (string)$specs['print_face'], 'icon' => 'fa-file-lines'],
        ['label' => 'الزنكات', 'value' => (string)$specs['zinc'], 'icon' => 'fa-layer-group'],
    ];
} else {
    foreach (array_slice($detailRows, 0, 6) as $row) {
        $summaryRows[] = [
            'label' => (string)$row['label'],
            'value' => (string)$row['value'],
            'icon' => detail_icon_for_label((string)$row['label']),
        ];
    }
}

// جلب بيانات العميل
$stmtClient = $conn->prepare("SELECT * FROM clients WHERE id = ? LIMIT 1");
$clientId = (int)($job['client_id'] ?? 0);
$stmtClient->bind_param("i", $clientId);
$stmtClient->execute();
$client = $stmtClient->get_result()->fetch_assoc();
$stmtClient->close();
?>
<!DOCTYPE html>
<html dir="rtl" lang="ar">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>أمر تشغيل #<?php echo $id; ?> - <?php echo app_h($appName); ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        /* --- نفس تصميم الفاتورة الملكي (Invoice Style) --- */
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

        /* الهيدر */
        .header { 
            display: flex; justify-content: space-between; align-items: center; 
            border-bottom: 1px solid var(--border); padding-bottom: 20px; margin-bottom: 30px; 
        }
        .header-side { flex: 1; text-align: center; }
        .header-side.right { text-align: right; }
        .header-side.left { text-align: left; }

        .invoice-title { font-size: 2rem; font-weight: 900; color: var(--gold); margin: 0; line-height: 1; }
        .invoice-id { font-size: 1rem; color: #fff; letter-spacing: 2px; margin-top: 5px; opacity: 0.8; font-family: monospace; }
        .logo-img { width: 90px; display: block; margin: 0 auto; }
        .date-item { font-size: 0.85rem; color: var(--text-sub); }
        .date-item strong { color: #fff; }

        /* بيانات العميل */
        .client-section { margin-bottom: 30px; background: rgba(255,255,255,0.03); padding: 15px; border-radius: 10px; border-right: 3px solid var(--gold); }
        .section-label { font-size: 0.8rem; color: var(--gold); text-transform: uppercase; font-weight: bold; }
        .client-name { font-size: 1.3rem; font-weight: 700; margin: 5px 0; color: #fff; }
        .client-details { font-size: 0.9rem; color: var(--text-sub); }

        /* جدول المواصفات (بديل جدول المنتجات) */
        .specs-grid {
            display: grid; grid-template-columns: repeat(2, 1fr); gap: 15px; margin-bottom: 30px;
        }
        .spec-item {
            background: #0a0a0a; border: 1px solid var(--border); padding: 15px; border-radius: 8px;
            display: flex; align-items: center; justify-content: space-between;
        }
        .spec-label { color: var(--text-sub); font-size: 0.9rem; display: flex; align-items: center; gap: 8px; }
        .spec-value { color: #fff; font-weight: bold; font-size: 1.1rem; }
        .spec-icon { color: var(--gold); }

        .full-specs {
            margin-top: 18px;
            border: 1px solid #2f2f2f;
            border-radius: 10px;
            overflow: hidden;
            background: #0b0b0b;
        }
        .full-specs-title {
            margin: 0;
            padding: 12px 14px;
            border-bottom: 1px solid #2f2f2f;
            color: var(--gold);
            font-size: 0.95rem;
        }
        .full-specs table { width: 100%; border-collapse: collapse; }
        .full-specs td {
            padding: 11px 14px;
            border-bottom: 1px dashed #242424;
            vertical-align: top;
            font-size: 0.9rem;
        }
        .full-specs tr:last-child td { border-bottom: none; }
        .full-specs td:first-child {
            width: 34%;
            color: #d1d1d1;
            font-weight: 700;
        }
        .full-specs td:last-child { color: #efefef; }

        /* الفوتر */
        .footer { margin-top: 40px; padding-top: 20px; border-top: 1px solid var(--border); font-size: 0.8rem; color: var(--text-sub); }
        .footer-meta { display:flex; justify-content:space-between; align-items:flex-end; gap:14px; flex-wrap:wrap; }
        .footer-lines { flex:1; min-width:220px; text-align:right; }
        .footer-lines p { margin:0 0 4px; }
        .footer-lines p:last-child { margin-bottom:0; }
        .footer-qr img { width:90px; height:90px; border:1px solid #323232; border-radius:8px; background:#fff; padding:4px; }

        /* الأزرار (تظهر فقط للشاشة) */
        .actions-bar {
            position: fixed; bottom: 20px; left: 50%; transform: translateX(-50%);
            background: rgba(18, 18, 18, 0.95); backdrop-filter: blur(10px);
            padding: 10px 20px; border-radius: 50px; width: 90%; max-width: 400px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.8); display: flex; gap: 10px; justify-content: center;
            border: 1px solid var(--gold); z-index: 1000;
        }
        .btn { border: none; padding: 10px 20px; border-radius: 30px; font-weight: bold; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 8px; font-size: 0.9rem; font-family: 'Cairo'; color: #000; transition: 0.3s; flex: 1; text-decoration: none; white-space: nowrap; background: var(--gold); }
        .btn:hover { transform: translateY(-3px); }

        /* الطباعة */
        @media print {
            body { background: #fff; color: #000; padding: 0; }
            .container { box-shadow: none; border: none; margin: 0; max-width: 100%; width: 100%; background: #fff; color: #000; border-radius: 0; }
            .container::before { display: none; }
            .header { border-bottom: 2px solid #000; flex-direction: row; }
            .header-side { text-align: inherit !important; }
            .header-side.right { text-align: right !important; }
            .invoice-title, .section-label { color: #000; text-shadow: none; }
            .invoice-id, .date-item, .client-name, .client-details { color: #000; }
            .logo-img { width: 75px; }
            .client-section { background: #fff; border: 1px solid #ddd; border-right: 4px solid #000; }
            .spec-item { background: #fff; border: 1px solid #ddd; color: #000; }
            .spec-label, .spec-value { color: #000; }
            .spec-icon { color: #000; }
            .footer { color: #000; border-top: 1px solid #ccc; }
            .footer-qr img { border-color:#aaa; }
            .full-specs { background: #fff; border-color: #ddd; }
            .full-specs-title { color: #000; border-bottom-color: #ddd; }
            .full-specs td { border-bottom-color: #eee; }
            .full-specs td:last-child, .full-specs td:first-child { color: #000; }
            .actions-bar { display: none !important; }
        }
    </style>
</head>
<body>

<div class="container">
    <div class="invoice-box">
        
        <div class="header">
            <div class="header-side right">
                <h1 class="invoice-title">أمر تشغيل</h1>
                <div class="invoice-id">ORDER #<?php echo str_pad($id, 4, '0', STR_PAD_LEFT); ?></div>
            </div>
            <div class="header-side center">
                <img src="<?php echo app_h($appLogo); ?>" alt="<?php echo app_h($appName); ?>" class="logo-img">
            </div>
            <div class="header-side left">
                <div class="date-item"><strong>التاريخ:</strong> <?php echo date('Y-m-d', strtotime($job['created_at'])); ?></div>
                <?php if (!empty($job['pricing_source_ref'])): ?>
                    <div class="date-item"><strong>ملف التسعير:</strong> <?php echo app_h((string)$job['pricing_source_ref']); ?></div>
                <?php endif; ?>
            </div>
        </div>

        <div class="client-section">
            <div class="section-label">بيانات العملية:</div>
            <h2 class="client-name"><?php echo app_h((string)$job['job_name']); ?></h2>
            <p class="client-details">
                العميل: <?php echo app_h((string)($client['name'] ?? '')); ?>
                | النوع: <strong><?php echo app_h((string)$jobTypeLabel); ?></strong>
                | الكمية: <strong><?php echo app_h((string)$job['quantity']); ?></strong>
            </p>
        </div>

        <div class="specs-grid">
            <?php foreach ($summaryRows as $summary): ?>
                <div class="spec-item">
                    <div class="spec-label"><i class="fa-solid <?php echo app_h((string)$summary['icon']); ?> spec-icon"></i> <?php echo app_h((string)$summary['label']); ?></div>
                    <div class="spec-value"><?php echo app_h((string)$summary['value']); ?></div>
                </div>
            <?php endforeach; ?>
        </div>

        <?php if($jobType === 'print' && !empty($specs['finish']) && $specs['finish'] !== 'غير محدد'): ?>
        <div style="margin-top:20px; font-size:0.9rem; color:var(--text-sub); border-top:1px dashed #333; padding-top:10px;">
            <strong style="color:var(--gold);">التشطيب التكميلي:</strong> <?php echo app_h((string)$specs['finish']); ?>
        </div>
        <?php endif; ?>

        <?php if (!empty($detailRows)): ?>
        <div class="full-specs">
            <h3 class="full-specs-title">الفنيات الكاملة للعملية</h3>
            <table>
                <tbody>
                    <?php foreach ($detailRows as $row): ?>
                        <tr>
                            <td><?php echo app_h((string)$row['label']); ?></td>
                            <td><?php echo app_h((string)$row['value']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <?php if(!empty($job['notes'])): ?>
        <div style="margin-top:10px; font-size:0.9rem; color:var(--text-sub);">
            <strong style="color:var(--gold);">ملاحظات:</strong> <?php echo nl2br(app_h((string)$job['notes'])); ?>
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

<div class="actions-bar">
    <button onclick="window.print()" class="btn">
        <i class="fa-solid fa-print"></i> طباعة / حفظ PDF
    </button>
</div>

</body>
</html>
