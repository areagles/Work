<?php
require 'config.php';
require 'auth.php';

$isEnglish = app_current_lang($conn) === 'en';
$canPricingView = app_user_can('pricing.view') || app_is_super_user() || ((string)($_SESSION['role'] ?? '') === 'admin');
if (!$canPricingView) {
    die($isEnglish ? 'Access denied.' : 'غير مصرح.');
}

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    die($isEnglish ? 'Invalid pricing file.' : 'ملف تسعير غير صالح.');
}

$stmt = $conn->prepare("
    SELECT pr.*, c.name AS client_name, c.phone AS client_phone
    FROM app_pricing_records pr
    LEFT JOIN clients c ON c.id = pr.client_id
    WHERE pr.id = ?
    LIMIT 1
");
$stmt->bind_param('i', $id);
$stmt->execute();
$record = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$record) {
    die($isEnglish ? 'Pricing file not found.' : 'ملف التسعير غير موجود.');
}

$snapshot = json_decode((string)($record['snapshot_json'] ?? ''), true);
$calc = is_array($snapshot['calc'] ?? null) ? $snapshot['calc'] : [];
$appName = app_setting_get($conn, 'app_name', 'Arab Eagles');
$appLogo = app_brand_logo_path($conn, 'assets/img/Logo.png');
$brandProfile = app_brand_profile($conn);
$outputShowFooter = !empty($brandProfile['show_footer']);
$outputShowQr = !empty($brandProfile['show_qr']);
$footerLines = app_brand_output_lines($brandProfile, 'footer', true);

$pricingRef = (string)($record['pricing_ref'] ?: ('PRC-' . str_pad((string)$record['id'], 5, '0', STR_PAD_LEFT)));
$totalAmount = number_format((float)($record['total_amount'] ?? 0), 2, '.', '');

// إنشاء QR Code حقيقي يحتوي على تفاصيل ملف التسعير
$qrPayload = app_brand_qr_payload($brandProfile, [
    'Document' => $isEnglish ? 'Pricing Record' : 'ملف تسعير',
    'Reference' => $pricingRef,
    'Client' => (string)($record['client_name'] ?? '-'),
    'Date' => date('Y-m-d', strtotime((string)$record['created_at'])),
    'Total' => $totalAmount
]);
$qrUrl = ($outputShowQr && $qrPayload !== '') ? "https://api.qrserver.com/v1/create-qr-code/?size=150x150&margin=0&data=" . urlencode($qrPayload) : '';
?>
<!DOCTYPE html>
<html lang="<?php echo $isEnglish ? 'en' : 'ar'; ?>" dir="<?php echo $isEnglish ? 'ltr' : 'rtl'; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title><?php echo app_h($isEnglish ? 'Pricing File' : 'ملف التسعير'); ?> <?php echo app_h($pricingRef); ?></title>
    
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        /* ========================================================= */
        /* تصميم الشاشة (Preview Mode) - الهوية الفحمية والذهبية     */
        /* ========================================================= */
        :root { 
            --ae-gold: #d4af37; 
            --ae-gold-light: #f1c40f; 
            --ae-dark: #0a0a0a; 
            --ae-card: #121212; 
            --ae-border: #2a2a2a; 
            --ae-text: #f5f5f5; 
            --ae-text-muted: #a0a0a0;
        }
        
        body { 
            background-color: var(--ae-dark); 
            color: var(--ae-text); 
            font-family: 'Cairo', sans-serif; 
            margin: 0; 
            padding: 30px 15px 120px 15px; 
        }
        
        .container { 
            max-width: 860px; 
            margin: 0 auto; 
            background: var(--ae-card); 
            border-radius: 16px; 
            box-shadow: 0 20px 50px rgba(0,0,0,0.6); 
            position: relative; 
            border: 1px solid var(--ae-border); 
            overflow: hidden; 
        }
        
        .container::before { 
            content: ''; 
            position: absolute; 
            top: 0; left: 0; right: 0; 
            height: 5px; 
            background: linear-gradient(90deg, var(--ae-gold), #b8860b, var(--ae-gold)); 
            z-index: 10;
        }
        
        .invoice-box { padding: 40px; position: relative; z-index: 1; }

        /* الترويسة */
        .header { 
            display: flex; 
            justify-content: space-between; 
            align-items: center; 
            border-bottom: 1px dashed var(--ae-border); 
            padding-bottom: 25px; 
            margin-bottom: 30px; 
        }
        .header-side { flex: 1; }
        .header-side.right { text-align: <?php echo $isEnglish ? 'left' : 'right'; ?>; }
        .header-side.center { text-align: center; }
        .header-side.left { text-align: <?php echo $isEnglish ? 'right' : 'left'; ?>; }

        .invoice-title { font-size: 1.8rem; font-weight: 900; color: var(--ae-gold); margin: 0; line-height: 1.2; }
        .invoice-id { font-size: 1.2rem; color: #fff; letter-spacing: 2px; margin-top: 5px; font-family: monospace; font-weight: bold; }
        .brand-name-fallback { font-size: 1.3rem; font-weight: 900; color: var(--ae-gold); }
        .logo-img { width: 80px; max-width: 100%; display: block; margin: 0 auto; border-radius: 8px; }
        
        .date-item { font-size: 0.9rem; color: var(--ae-text-muted); margin-bottom: 6px; }
        .date-item strong { color: #fff; display: inline-block; width: 60px; font-weight: 700; }

        /* البيانات الأساسية */
        .grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 15px;
            margin-bottom: 30px;
        }
        .card-info {
            background: #0a0a0a;
            border: 1px solid var(--ae-border);
            border-radius: 12px;
            padding: 15px;
            border-right: 3px solid var(--ae-gold);
        }
        .card-info small { display: block; color: var(--ae-text-muted); font-size: 0.85rem; margin-bottom: 5px; font-weight: 600; text-transform: uppercase; }
        .card-info strong { color: #fff; font-size: 1.1rem; font-weight: 800; display: block; }

        /* جدول الأصناف */
        .items-table { 
            width: 100%; 
            border-collapse: collapse; 
            margin-bottom: 30px; 
            background: #0a0a0a;
            border-radius: 10px;
            overflow: hidden;
            border: 1px solid var(--ae-border);
        }
        .items-table th { 
            background: #1a1a1a; 
            color: var(--ae-gold); 
            padding: 15px; 
            text-align: <?php echo $isEnglish ? 'left' : 'right'; ?>; 
            font-size: 0.95rem; 
            border-bottom: 2px solid var(--ae-gold); 
            font-weight: 800; 
        }
        .items-table td { 
            padding: 15px; 
            border-bottom: 1px solid #222; 
            text-align: <?php echo $isEnglish ? 'left' : 'right'; ?>; 
            font-size: 0.95rem; 
            color: #eee; 
            vertical-align: middle; 
        }
        .items-table td:last-child { font-family: monospace; font-size: 1.05rem; color: var(--ae-gold); font-weight: bold; }
        .items-table tr:last-child td { border-bottom: none; }
        .items-table tr:hover { background: #151515; }

        /* الإجمالي النهائي */
        .total-box {
            margin-top: 20px;
            padding: 20px;
            border-radius: 12px;
            background: linear-gradient(135deg, var(--ae-gold), #b8860b);
            color: #000;
            font-weight: 900;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 1.4rem;
            box-shadow: 0 5px 15px rgba(212, 175, 55, 0.2);
        }
        .total-box span:last-child { font-family: monospace; font-size: 1.6rem; }

        /* الملاحظات */
        .notes {
            margin-top: 30px;
            border: 1px dashed #444;
            border-radius: 12px;
            padding: 20px;
            color: #ddd;
            line-height: 1.8;
            background: #0a0a0a;
            font-size: 0.95rem;
        }
        .notes strong { color: var(--ae-gold); display: block; margin-bottom: 10px; font-size: 1.05rem; }

        /* التذييل */
        .footer { 
            margin-top: 40px; 
            padding-top: 25px; 
            border-top: 1px dashed var(--ae-border); 
            font-size: 0.85rem; 
            color: var(--ae-text-muted); 
            line-height: 1.8; 
        }
        .footer-meta { display: flex; align-items: flex-end; justify-content: space-between; gap: 20px; flex-wrap: wrap; }
        .footer-lines { flex: 1; min-width: 250px; text-align: <?php echo $isEnglish ? 'left' : 'right'; ?>; }
        .footer-lines p { margin: 0 0 5px; }
        .footer-lines p:first-child { color: var(--ae-gold); font-size: 1rem; font-weight: bold; }
        .qr-box { min-width: 110px; text-align: center; }
        .qr-box img { width: 100px; height: 100px; display: inline-block; background: #fff; border-radius: 10px; border: 1px solid var(--ae-gold); padding: 5px; }
        .qr-box small { display: block; margin-top: 5px; color: var(--ae-text-muted); font-weight: bold; }

        /* شريط الإجراءات العائم */
        .actions-bar { 
            position: fixed; 
            bottom: 25px; 
            left: 50%; 
            transform: translateX(-50%); 
            background: rgba(15, 15, 15, 0.95); 
            backdrop-filter: blur(10px); 
            padding: 12px 25px; 
            border-radius: 50px; 
            width: 90%; 
            max-width: 500px; 
            box-shadow: 0 10px 40px rgba(0,0,0,0.8); 
            display: flex; 
            gap: 12px; 
            justify-content: center; 
            border: 1px solid var(--ae-gold); 
            z-index: 1000; 
        }
        .btn { 
            border: none; 
            padding: 12px 20px; 
            border-radius: 30px; 
            font-weight: 800; 
            cursor: pointer; 
            text-decoration: none; 
            display: flex; 
            align-items: center; 
            justify-content: center; 
            gap: 8px; 
            font-size: 0.95rem; 
            font-family: 'Cairo', sans-serif; 
            transition: 0.3s; 
            flex: 1; 
            white-space: nowrap; 
        }
        .btn-print { background: var(--ae-gold); color: #000; }
        .btn-print:hover { background: var(--ae-gold-light); transform: translateY(-2px); }
        .btn-back { background: #222; color: #fff; border: 1px solid #444; }
        .btn-back:hover { border-color: var(--ae-gold); color: var(--ae-gold); }

        /* ========================================================= */
        /* إعدادات الطباعة (Print Mode) - أبيض وأسود رسمي لحفظ الحبر */
        /* ========================================================= */
        @media print {
            @page { size: A4; margin: 10mm; }
            body { background: #fff !important; color: #000 !important; padding: 0 !important; margin: 0 !important; }
            .container { box-shadow: none !important; border: none !important; margin: 0 !important; max-width: 100% !important; width: 100% !important; background: #fff !important; border-radius: 0 !important; }
            .container::before { display: none !important; }
            .invoice-box { padding: 0 !important; }
            
            .header { border-bottom: 2px solid #000 !important; padding-bottom: 15px !important; margin-bottom: 20px !important; }
            .invoice-title, .card-info small, .date-item strong, .invoice-id, .date-item, .brand-name-fallback, .card-info strong { color: #000 !important; text-shadow: none !important; }
            .logo-img { width: 70px !important; filter: none !important; }
            
            .card-info { background: transparent !important; border: 1px solid #000 !important; padding: 15px !important; border-right-width: 1px !important; }
            
            .items-table { border: 1px solid #000 !important; background: transparent !important; }
            .items-table th { background: #f0f0f0 !important; color: #000 !important; border-bottom: 2px solid #000 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .items-table td { color: #000 !important; border-bottom: 1px solid #000 !important; }
            
            .total-box { background: #f0f0f0 !important; color: #000 !important; border: 2px solid #000 !important; box-shadow: none !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            
            .notes { border: 1px solid #000 !important; background: transparent !important; color: #000 !important; }
            .notes strong { color: #000 !important; }

            .footer { color: #000 !important; border-top: 1px solid #000 !important; margin-top: 30px !important; padding-top: 15px !important; }
            .footer-lines p:first-child { color: #000 !important; }
            .qr-box img { border-color: #000 !important; }
            .qr-box small { color: #555 !important; }
            
            .actions-bar { display: none !important; }

            /* 🌟 إعدادات منع قطع البيانات بين الصفحات (Pagination Control) 🌟 */
            .items-table, .total-box, .notes, .footer, tr, .card-info {
                page-break-inside: avoid !important;
                break-inside: avoid !important;
            }
            thead { display: table-header-group !important; }
            tfoot { display: table-footer-group !important; }
        }

        /* استجابة للهواتف (Responsive) */
        @media (max-width: 768px) {
            .header { flex-direction: column; text-align: center; gap: 15px; }
            .header-side { text-align: center !important; }
            .grid { grid-template-columns: 1fr; }
            .actions-bar { flex-direction: column; width: 95%; border-radius: 20px; padding: 15px; }
            .items-table { font-size: 0.85rem; }
            .items-table th, .items-table td { padding: 10px 5px; }
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
        
        <div class="header">
            <div class="header-side right">
                <h1 class="invoice-title"><?php echo app_h($isEnglish ? 'Pricing File' : 'ملف التسعير'); ?></h1>
                <div class="invoice-id"><?php echo app_h($pricingRef); ?></div>
            </div>
            <div class="header-side center">
                <?php if ($appLogo): ?>
                    <img src="<?php echo app_h($appLogo); ?>" alt="<?php echo app_h($appName); ?>" class="logo-img">
                <?php else: ?>
                    <div class="brand-name-fallback"><?php echo app_h($appName); ?></div>
                <?php endif; ?>
            </div>
            <div class="header-side left">
                <div class="date-item"><strong><?php echo app_h($isEnglish ? 'Date:' : 'التاريخ:'); ?></strong> <?php echo app_h(date('Y-m-d', strtotime((string)$record['created_at']))); ?></div>
            </div>
        </div>

        <div class="grid">
            <div class="card-info">
                <small><i class="fa-solid fa-building-user"></i> <?php echo app_h($isEnglish ? 'Client' : 'العميل'); ?></small>
                <strong><?php echo app_h((string)($record['client_name'] ?? '-')); ?></strong>
            </div>
            <div class="card-info">
                <small><i class="fa-solid fa-list-check"></i> <?php echo app_h($isEnglish ? 'Operation' : 'العملية'); ?></small>
                <strong><?php echo app_h((string)($record['operation_name'] ?? '-')); ?></strong>
            </div>
            <div class="card-info">
                <small><i class="fa-solid fa-phone"></i> <?php echo app_h($isEnglish ? 'Phone' : 'الهاتف'); ?></small>
                <strong style="direction:ltr; text-align:<?php echo $isEnglish ? 'left' : 'right'; ?>;"><?php echo app_h((string)($record['client_phone'] ?? '-')); ?></strong>
            </div>
        </div>

        <table class="items-table">
            <thead>
                <tr>
                    <th><?php echo app_h($isEnglish ? 'Item / Description' : 'البند / الوصف'); ?></th>
                    <th><?php echo app_h($isEnglish ? 'Value' : 'القيمة'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $hasItems = false;
                foreach (['stage_rows', 'printing_rows', 'finishing_rows'] as $rowType):
                    foreach ((array)($calc[$rowType] ?? []) as $row): 
                        if(!empty($row['label'])):
                            $hasItems = true;
                ?>
                    <tr>
                        <td><?php echo app_h((string)$row['label']); ?></td>
                        <td><?php echo app_h(number_format((float)($row['value'] ?? 0), 2)); ?></td>
                    </tr>
                <?php 
                        endif;
                    endforeach; 
                endforeach; 
                
                if(!$hasItems): 
                ?>
                    <tr>
                        <td colspan="2" style="text-align:center; padding:30px; color:#888;">
                            <?php echo app_h($isEnglish ? 'No detailed items listed.' : 'لم يتم إدراج بنود تفصيلية.'); ?>
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>

        <div class="total-box">
            <span><i class="fa-solid fa-sack-dollar"></i> <?php echo app_h($isEnglish ? 'Final Total' : 'الإجمالي النهائي المستحق'); ?></span>
            <span><?php echo app_h(number_format((float)($record['total_amount'] ?? 0), 2)); ?></span>
        </div>

        <?php if (!empty(trim((string)$record['notes']))): ?>
            <div class="notes">
                <strong><i class="fa-solid fa-pen-to-square"></i> <?php echo app_h($isEnglish ? 'Notes & Terms' : 'الشروط والملاحظات'); ?></strong>
                <?php echo nl2br(app_h((string)$record['notes'])); ?>
            </div>
        <?php endif; ?>

        <?php if ($outputShowFooter && (!empty($footerLines) || $qrUrl !== '')): ?>
            <div class="footer">
                <div class="footer-meta">
                    <div class="footer-lines">
                        <p><?php echo app_h((string)($brandProfile['org_name'] ?? $appName)); ?></p>
                        <?php foreach ($footerLines as $line): ?>
                            <p><?php echo app_h((string)$line); ?></p>
                        <?php endforeach; ?>
                    </div>
                    <?php if ($qrUrl !== ''): ?>
                        <div class="qr-box">
                            <img src="<?php echo app_h($qrUrl); ?>" alt="QR Code Verification">
                            <small><?php echo app_h($isEnglish ? 'Scan to Verify' : 'مسح للتحقق'); ?></small>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="actions-bar no-print">
    <a class="btn btn-back" href="pricing_records.php">
        <i class="fa-solid fa-arrow-right"></i> <?php echo app_h($isEnglish ? 'Back to System' : 'الرجوع للنظام'); ?>
    </a>
    <button class="btn btn-print" onclick="window.print()">
        <i class="fa-solid fa-print"></i> <?php echo app_h($isEnglish ? 'Print / Save PDF' : 'طباعة / حفظ PDF'); ?>
    </button>
</div>

</body>
</html>