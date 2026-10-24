<?php
// delivery_receipt.php - (Royal Delivery Engine V2.0 - Multi-Page Print Safe)

ob_start();
error_reporting(E_ALL);

require 'auth.php'; 
require 'config.php';

$appName = app_setting_get($conn, 'app_name', 'Arab Eagles');
$appLogo = app_brand_logo_path($conn, 'assets/img/Logo.png');
$brandProfile = app_brand_profile($conn);
$outputShowHeader = !empty($brandProfile['show_header']);
$outputShowFooter = !empty($brandProfile['show_footer']);
$outputShowLogo = !empty($brandProfile['show_logo']);
$outputShowQr = !empty($brandProfile['show_qr']);
$headerLines = app_brand_output_lines($brandProfile, 'header', true);
$footerLines = app_brand_output_lines($brandProfile, 'footer', true);

if(!isset($_GET['id'])) die("<div style='text-align:center; padding:50px; background:#050505; color:white; height:100vh;'><h2><i class='fa-solid fa-triangle-exclamation'></i> رقم السجل مفقود!</h2></div>");

$id = intval($_GET['id']);
$stmtInv = $conn->prepare("SELECT i.*, c.name as client_name, c.phone as client_phone, c.address as client_address, j.job_number, j.job_name, j.status AS job_status, j.access_token AS job_access_token FROM invoices i JOIN clients c ON i.client_id=c.id LEFT JOIN job_orders j ON j.id = i.job_id AND j.client_id = i.client_id WHERE i.id = ?");
$stmtInv->bind_param('i', $id);
$stmtInv->execute();
$res = $stmtInv->get_result();

if(!$res || $res->num_rows == 0) die("<div style='text-align:center; padding:50px; font-family:sans-serif; background:#050505; color:#d4af37; height:100vh;'><h2><i class='fa-solid fa-file-circle-xmark'></i> السجل غير موجود.</h2><br><a href='invoices.php' style='color:#fff; text-decoration:none; border:1px solid #d4af37; padding:10px 20px; border-radius:5px;'>العودة</a></div>");

$inv = $res->fetch_assoc();
$stmtInv->close();
$inv['invoice_number'] = $inv['invoice_number'] ?? '';
if ((string)$inv['invoice_number'] === '') {
    $inv['invoice_number'] = app_assign_document_number($conn, 'invoices', (int)$id, 'invoice_number', 'invoice', (string)($inv['inv_date'] ?? date('Y-m-d')));
}
$deliveryRef = trim((string)($inv['invoice_number'] ?? ''));
if ($deliveryRef === '') {
    $deliveryRef = 'D-REC #' . str_pad((string)$id, 4, '0', STR_PAD_LEFT);
} else {
    $deliveryRef = 'D-REC ' . $deliveryRef;
}
$itemsRaw = $inv['items_json'];
$items = [];

if($itemsRaw) {
    $decoded = json_decode($itemsRaw, true);
    if(is_string($decoded)) $decoded = json_decode($decoded, true);
    if(is_array($decoded)) $items = $decoded;
}
$qrPayload = app_brand_qr_payload($brandProfile, [
    'Document' => 'Delivery Receipt',
    'Reference' => $deliveryRef,
    'Client' => (string)($inv['client_name'] ?? ''),
    'Date' => date('Y-m-d', strtotime((string)($inv['inv_date'] ?? date('Y-m-d')))),
]);
$qrUrl = ($outputShowQr && $qrPayload !== '') ? app_brand_qr_url($qrPayload, 140) : '';
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>سند تسليم <?php echo app_h($deliveryRef); ?> | <?php echo app_h($appName); ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        :root { 
            --bg-body: #050505; 
            --card-bg: #121212; 
            --gold: #d4af37; 
            --accent: #e67e22; 
            --text-main: #ffffff; 
            --text-sub: #aaaaaa; 
            --border: #333;
        }

        body { background-color: var(--bg-body); color: var(--text-main); font-family: 'Cairo', sans-serif; margin: 0; padding: 15px; }
        
        .container { 
            max-width: 850px; margin: 20px auto 100px auto; 
            background: var(--card-bg); border-radius: 15px; 
            box-shadow: 0 0 50px rgba(0,0,0,0.8); position: relative; 
            border: 1px solid var(--border); overflow: hidden;
        }
        
        .container::before {
            content: ''; position: absolute; top: 0; left: 0; right: 0; height: 4px;
            background: linear-gradient(90deg, var(--accent), #f39c12, var(--accent));
        }

        .receipt-box { padding: 30px 40px; position: relative; z-index: 1; }

        /* =========================================
           الجدول الرئيسي الحامي للطباعة (Master Table)
           ========================================= */
        .print-master-table { width: 100%; border-collapse: collapse; border: none; }
        .print-master-table > thead { display: table-header-group; }
        .print-master-table > tfoot { display: table-footer-group; }
        .print-master-table > tbody > tr > td { padding: 0; border: none; }

        /* الهيدر */
        .header-content { display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid var(--border); padding-bottom: 15px; margin-bottom: 25px; }
        .header-side { flex: 1; text-align: center; }
        .header-side.right { text-align: right; }
        .header-side.left { text-align: left; }
        .receipt-title { font-size: 1.6rem; font-weight: 900; color: var(--accent); margin: 0; line-height: 1.2; letter-spacing: 1px; }
        .receipt-id { font-size: 1rem; color: #fff; letter-spacing: 2px; margin-top: 5px; opacity: 0.8; font-family: monospace; }
        .brand-name-fallback { font-size: 1rem; font-weight: 800; color: #fff; text-align: center; }
        .logo-img { width: 60px; max-width: 100%; display: block; margin: 0 auto; filter: drop-shadow(0 0 10px rgba(230,126,34,0.1)); }
        .date-item { font-size: 0.9rem; color: var(--text-sub); margin-bottom: 4px; }
        .date-item strong { color: #fff; display: inline-block; font-weight: 800; }
        .header-info-list { margin-bottom: 18px; padding: 9px 12px; border: 1px dashed #2d2d2d; border-radius: 10px; background: rgba(255,255,255,0.02); font-size: 0.8rem; color: #c8c8c8; line-height: 1.7; }
        .header-info-list div { margin-bottom: 3px; }
        .header-info-list div:last-child { margin-bottom: 0; }

        /* بيانات العميل */
        .client-section { margin-bottom: 25px; background: rgba(255,255,255,0.02); padding: 15px 20px; border-radius: 8px; border-right: 3px solid var(--accent); }
        .section-label { font-size: 0.8rem; color: var(--accent); text-transform: uppercase; margin-bottom: 5px; font-weight: bold; }
        .client-name { font-size: 1.3rem; font-weight: 800; margin: 0; color: #fff; }
        .client-details { font-size: 0.85rem; color: var(--text-sub); margin: 8px 0 0 0; display: flex; gap: 15px; flex-wrap: wrap; }
        .job-link-box { margin-bottom: 20px; background: rgba(230,126,34,0.08); border: 1px solid rgba(230,126,34,0.2); border-radius: 10px; padding: 12px 16px; display:flex; justify-content:space-between; align-items:center; gap:16px; flex-wrap:wrap; }
        .job-link-title { color: var(--accent); font-size: 0.8rem; font-weight: 700; margin-bottom: 5px; }
        .job-link-main { color: #fff; font-size: 0.98rem; font-weight: 700; }
        .job-link-sub { color: var(--text-sub); font-size: 0.84rem; }

        /* جدول الكميات */
        .items-table { width: 100%; border-collapse: collapse; margin-bottom: 25px; }
        .items-table th { background: rgba(230, 126, 34, 0.1); color: var(--accent); padding: 10px; text-align: center; font-size: 0.9rem; border-bottom: 1px solid var(--accent); font-weight: 800; }
        .items-table td { padding: 12px 10px; border-bottom: 1px solid var(--border); text-align: center; font-size: 1rem; color: #eee; vertical-align: middle; page-break-inside: avoid; }
        .items-table td.desc { text-align: right; width: 70%; color: #fff; font-weight: bold; }
        .items-table td.qty { width: 20%; font-family: sans-serif; font-weight: 900; color: var(--gold); font-size: 1.2rem; }
        .items-table tr:nth-child(even) { background: rgba(255,255,255,0.01); }

        /* الشروط وبنود التسليم */
        .terms-box { border: 1px dashed var(--border); padding: 15px 20px; border-radius: 8px; font-size: 0.8rem; color: var(--text-sub); line-height: 1.6; background: rgba(0,0,0,0.2); margin-bottom: 25px; page-break-inside: avoid; }
        .terms-box strong { color: var(--accent); display: block; margin-bottom: 5px; font-size: 0.9rem; }
        .terms-box ul { margin: 0; padding-right: 20px; }

        /* منطقة التوقيعات (مصغرة ومنسقة للطباعة) */
        .signatures-table { width: 100%; margin-top: 20px; border-collapse: collapse; page-break-inside: avoid; }
        .sig-td { width: 45%; border: 1px solid var(--border); border-radius: 8px; padding: 15px; background: #0a0a0a; vertical-align: top; }
        .sig-spacer { width: 10%; }
        .sig-title { font-weight: bold; color: var(--accent); border-bottom: 1px dashed #333; padding-bottom: 8px; margin-bottom: 12px; text-align: center; font-size: 0.9rem; }
        .sig-line { font-size: 0.8rem; color: #ccc; margin-bottom: 15px; line-height: 1.5; }
        .dots { color: #555; letter-spacing: 2px; }

        /* الفوتر */
        .footer-content { padding-top: 15px; font-size: 0.75rem; color: var(--text-sub); line-height: 1.6; }
        .footer-meta { display: flex; align-items: flex-end; justify-content: space-between; gap: 16px; flex-wrap: wrap; }
        .footer-lines { flex: 1; min-width: 240px; text-align: right; }
        .footer-lines p { margin: 0 0 4px; }
        .footer-lines p:last-child { margin-bottom: 0; }
        .qr-box { min-width: 104px; text-align: center; }
        .qr-box img { width: 92px; height: 92px; display: inline-block; background: #fff; border-radius: 10px; border: 1px solid #2f2f2f; padding: 4px; }
        .qr-box small { display: block; margin-top: 4px; color: #8d8d8d; }
        
        .actions-bar { position: fixed; bottom: 20px; left: 50%; transform: translateX(-50%); background: rgba(18, 18, 18, 0.95); backdrop-filter: blur(10px); padding: 10px 30px; border-radius: 50px; width: 90%; max-width: 500px; box-shadow: 0 10px 40px rgba(0,0,0,0.8); display: flex; gap: 10px; justify-content: center; border: 1px solid var(--accent); z-index: 1000; }
        .btn { border: none; padding: 10px 20px; border-radius: 30px; font-weight: bold; cursor: pointer; text-decoration: none; display: flex; align-items: center; justify-content: center; gap: 8px; font-size: 0.9rem; font-family: 'Cairo'; color: #fff; transition: 0.3s; flex: 1; white-space: nowrap; }
        .btn-print { background: var(--accent); color: #000; }
        .btn-back { background: #333; color: #fff; }

        /* =========================================
           🖨️ وضع الطباعة (Strict PDF & Print Rules)
           ========================================= */
        @media print {
            @page { size: A4; margin: 15mm; }
            body { background: #fff; color: #000; padding: 0; margin: 0; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .container { box-shadow: none; border: none; margin: 0; max-width: 100%; width: 100%; background: #fff; color: #000; border-radius: 0; }
            .container::before { display: none; }
            .receipt-box { padding: 0; }
            
            /* ألوان الطباعة الأبيض والأسود */
            .header-content { border-bottom: 2px solid #000; margin-bottom: 15px; padding-bottom: 10px; }
            .receipt-title { color: #000; font-size: 1.5rem; }
            .receipt-id, .date-item, .date-item strong, .brand-name-fallback { color: #333; }
            .logo-img { width: 48px; filter: none; }
            .header-info-list { border-color: #bbb; color: #000; background: #fff; }
            
            .client-section { background: #fff; border: 1px solid #ddd; border-right: 4px solid #000; padding: 10px 15px; margin-bottom: 15px; }
            .section-label, .client-name, .client-details { color: #000; }
            
            .items-table { border-color: #000; margin-bottom: 15px; }
            .items-table th { background: #f0f0f0 !important; color: #000 !important; border-bottom: 2px solid #000; padding: 8px; }
            .items-table td, .items-table td.desc, .items-table td.qty { color: #000 !important; border-bottom: 1px solid #ccc; padding: 8px; }
            
            .terms-box { border: 1px solid #ccc; background: #fff; color: #000; padding: 10px 15px; margin-bottom: 15px; }
            .terms-box strong { color: #000; }
            
            .sig-td { background: #fff; border-color: #000; padding: 10px; }
            .sig-title { color: #000; border-bottom-color: #000; padding-bottom: 5px; margin-bottom: 8px; }
            .sig-line { color: #000; margin-bottom: 10px; }
            .dots { color: #000; }

            .footer-content { color: #000; border-top: 1px solid #000; padding-top: 10px; }
            .qr-box img { border-color: #aaa; }
            .qr-box small { color: #333; }
            .actions-bar { display: none !important; }
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
    <div class="receipt-box">
        
        <table class="print-master-table">
            
            <thead>
                <tr>
                    <td>
                        <div class="header-content">
                            <div class="header-side right">
                                <h1 class="receipt-title">سند تسليم بضاعة</h1>
                                <div class="receipt-id"><?php echo app_h($deliveryRef); ?></div>
                            </div>
                            <div class="header-side center">
                                <?php if ($outputShowLogo): ?>
                                    <img src="<?php echo app_h($appLogo); ?>" alt="Logo" class="logo-img" onerror="this.style.display='none'">
                                <?php else: ?>
                                    <div class="brand-name-fallback"><?php echo app_h((string)($brandProfile['org_name'] ?? $appName)); ?></div>
                                <?php endif; ?>
                            </div>
                            <div class="header-side left">
                                <div class="date-item"><strong>تاريخ الفاتورة:</strong> <?php echo date('Y-m-d', strtotime($inv['inv_date'])); ?></div>
                                <div class="date-item"><strong>تاريخ التسليم:</strong> <?php echo date('Y-m-d', strtotime($inv['inv_date'])); ?></div>
                            </div>
                        </div>
                        <?php if ($outputShowHeader && !empty($headerLines)): ?>
                            <div class="header-info-list">
                                <?php foreach ($headerLines as $line): ?>
                                    <div><?php echo app_h($line); ?></div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </td>
                </tr>
            </thead>

            <tbody>
                <tr>
                    <td>
                        <div class="client-section">
                            <div class="section-label">الجهة المستلمة:</div>
                            <h2 class="client-name"><?php echo htmlspecialchars($inv['client_name']); ?></h2>
                            <div class="client-details">
                                <?php if(!empty($inv['client_phone'])): ?><span><i class="fa-solid fa-phone"></i> <?php echo htmlspecialchars($inv['client_phone']); ?></span><?php endif; ?>
                                <?php if(!empty($inv['client_address'])): ?><span><i class='fa-solid fa-location-dot'></i> <?php echo htmlspecialchars($inv['client_address']); ?></span><?php endif; ?>
                            </div>
                        </div>

                        <?php if (!empty($inv['job_id']) && (!empty($inv['job_number']) || !empty($inv['job_name']))): ?>
                        <div class="job-link-box">
                            <div>
                                <div class="job-link-title">أمر التشغيل المرتبط</div>
                                <div class="job-link-main"><?php echo app_h(trim((string)($inv['job_number'] ?: ('JOB#' . (int)$inv['job_id'])) . ' - ' . (string)($inv['job_name'] ?? ''))); ?></div>
                                <div class="job-link-sub">الحالة: <?php echo app_h((string)($inv['job_status'] ?? '-')); ?></div>
                            </div>
                        </div>
                        <?php endif; ?>

                        <table class="items-table">
                            <thead>
                                <tr>
                                    <th width="10%">م</th>
                                    <th width="70%">البيان / تفاصيل المنتج</th>
                                    <th width="20%">الكمية المسلمة / الوحدة</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                if(!empty($items)): 
                                    $count = 1;
                                    foreach($items as $item): 
                                        // المحقق الذكي
                                        $itemName = 'صنف غير محدد';
                                        foreach($item as $key => $val) {
                                            $kl = strtolower($key);
                                            if(!in_array($kl, ['qty','quantity','count','price','cost','unit_price','total','subtotal','tax','id','unit']) && is_string($val) && !is_numeric($val) && trim($val) !== '') {
                                                $itemName = $val; break;
                                            }
                                        }

                                        $qty = isset($item['qty']) ? floatval($item['qty']) : (isset($item['quantity']) ? floatval($item['quantity']) : 1);
                                        $unitVal = trim((string)($item['unit'] ?? ''));
                                        $qtyLabel = $qty;
                                        if ($unitVal !== '') {
                                            $qtyLabel .= ' ' . htmlspecialchars($unitVal);
                                        }
                                ?>
                                <tr>
                                    <td><?php echo $count++; ?></td>
                                    <td class="desc"><?php echo nl2br(htmlspecialchars($itemName)); ?></td>
                                    <td class="qty"><?php echo $qtyLabel; ?></td>
                                </tr>
                                <?php endforeach; else: ?>
                                <tr>
                                    <td colspan="3" style="text-align:center; padding:30px; color:#888;">لم يتم إدراج أصناف تفصيلية.</td>
                                </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>

                        <div class="terms-box">
                            <strong><i class="fa-solid fa-handshake"></i> إقرار الاستلام:</strong>
                            <ul>
                                <li>أقر أنا الموقع أدناه بأنني استلمت البضاعة/المنتجات الموضحة أعلاه بحالة ممتازة ومطابقة للمواصفات والكميات المطلوبة.</li>
                                <li>تتحمل الجهة المستلمة المسؤولية الكاملة عن حفظ وتخزين المنتجات بعد توقيع هذا السند.</li>
                                <li>لا يقبل إرجاع أو استبدال المنتجات بعد خروجها من المخزن إلا في حالة وجود عيب صناعة واضح، خلال 14 يوماً.</li>
                            </ul>
                        </div>

                        <table class="signatures-table">
                            <tr>
                                <td class="sig-td">
                                    <div class="sig-title">المستلم (العميل / المفوض)</div>
                                    <div class="sig-line">الاسم: <span class="dots">...................................................</span></div>
                                    <div class="sig-line">التوقيع: <span class="dots">...................................................</span></div>
                                </td>
                                <td class="sig-spacer"></td>
                                <td class="sig-td">
                                    <div class="sig-title">المسلم (المندوب / المخزن)</div>
                                    <div class="sig-line">الاسم: <span class="dots">...................................................</span></div>
                                    <div class="sig-line">التوقيع: <span class="dots">...................................................</span></div>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>
            </tbody>

            <?php if ($outputShowFooter): ?>
            <tfoot>
                <tr>
                    <td>
                        <div class="footer-content">
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
                                    <div class="qr-box">
                                        <img src="<?php echo app_h($qrUrl); ?>" alt="QR">
                                        <small>QR</small>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </td>
                </tr>
            </tfoot>
            <?php endif; ?>

        </table>

    </div>
</div>

<div class="actions-bar">
    <a href="invoices.php?tab=sales" class="btn btn-back"><i class="fa-solid fa-arrow-right"></i> عودة للسجل</a>
    <button onclick="window.print()" class="btn btn-print"><i class="fa-solid fa-print"></i> طباعة السند (PDF)</button>
</div>

</body>
</html>
