<?php
// print_purchase.php - (Royal Print Engine V5.0 - Unified Design)
require 'auth.php'; 
require 'config.php';

$appName = app_setting_get($conn, 'app_name', 'Arab Eagles');
$appLogo = app_brand_logo_path($conn, 'assets/img/Logo.png');
$brandProfile = function_exists('app_brand_profile') ? app_brand_profile($conn) : [];
$outputShowFooter = !empty($brandProfile['show_footer']);

if(!isset($_GET['id'])) die("<div style='text-align:center; padding:50px; background:#0a0a0a; color:#fff; height:100vh; font-family:sans-serif;'><h2><i class='fa-solid fa-triangle-exclamation' style='color:#d4af37;'></i> رقم الفاتورة مفقود!</h2></div>");
$id = intval($_GET['id']);

// جلب البيانات
$sql = "SELECT p.*, COALESCE(NULLIF(p.supplier_display_name, ''), s.name) as sup_name, s.name as supplier_master_name, s.phone as sup_phone, s.address as sup_address 
        FROM purchase_invoices p 
        LEFT JOIN suppliers s ON p.supplier_id = s.id 
        WHERE p.id = $id";
$res = $conn->query($sql);

if($res->num_rows == 0) die("<div style='text-align:center; padding:50px; font-family:sans-serif; background:#0a0a0a; color:#d4af37; height:100vh;'><h2><i class='fa-solid fa-file-circle-xmark'></i> الفاتورة غير موجودة.</h2><br><a href='invoices.php?tab=purchases' style='color:#000; background:#d4af37; text-decoration:none; padding:10px 20px; border-radius:5px; font-weight:bold;'>العودة للسجل</a></div>");

$inv = $res->fetch_assoc();
$inv['purchase_number'] = $inv['purchase_number'] ?? '';
if ((string)$inv['purchase_number'] === '') {
    $inv['purchase_number'] = app_assign_document_number($conn, 'purchase_invoices', (int)$id, 'purchase_number', 'purchase', (string)($inv['inv_date'] ?? date('Y-m-d')));
}
$purchaseRef = trim((string)($inv['purchase_number'] ?? ''));
if ($purchaseRef === '') {
    $purchaseRef = '#' . str_pad((string)$inv['id'], 5, '0', STR_PAD_LEFT);
}

$items = json_decode($inv['items_json'] ?? '[]', true);
$isTaxPurchase = trim((string)($inv['eta_uuid'] ?? '')) !== '' || trim((string)($inv['eta_status'] ?? '')) !== '';
$hasCustomSupplierName = trim((string)($inv['supplier_display_name'] ?? '')) !== '' && trim((string)($inv['supplier_display_name'] ?? '')) !== trim((string)($inv['supplier_master_name'] ?? ''));
?>
<!DOCTYPE html>
<html dir="rtl" lang="ar">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>فاتورة مشتريات <?php echo app_h($purchaseRef); ?> | <?php echo app_h($appName); ?></title>
    
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
            --ae-card: #151515; 
            --ae-border: #2a2a2a; 
            --ae-text: #f5f5f5; 
            --ae-text-muted: #a0a0a0;
            --ae-green: #2ecc71;
            --ae-red: #e74c3c;
        }
        
        body { 
            background-color: var(--ae-dark); 
            color: var(--ae-text); 
            font-family: 'Cairo', sans-serif; 
            margin: 0; 
            padding: 30px 15px 120px 15px; 
        }
        
        .container { 
            max-width: 900px; 
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
            position: relative; 
            z-index: 2; 
        }
        .header-side { flex: 1; }
        .header-side.right { text-align: right; }
        .header-side.center { text-align: center; }
        .header-side.left { text-align: left; }

        .invoice-title { font-size: 1.8rem; font-weight: 900; color: var(--ae-gold); margin: 0; line-height: 1.2; }
        .invoice-id { font-size: 1.2rem; color: #fff; letter-spacing: 2px; margin-top: 5px; font-family: monospace; font-weight: bold; }
        .brand-name-fallback { font-size: 1.3rem; font-weight: 900; color: var(--ae-gold); }
        .logo-img { width: 80px; max-width: 100%; display: block; margin: 0 auto; border-radius: 8px; }
        
        .date-item { font-size: 0.9rem; color: var(--ae-text-muted); margin-bottom: 6px; }
        .date-item strong { color: #fff; display: inline-block; font-weight: 700; margin-left: 5px; }

        /* شارة الضريبة */
        .doc-kind-badge { 
            display: inline-flex; align-items: center; gap: 8px; margin-top: 10px; 
            padding: 6px 15px; border-radius: 20px; font-size: 0.85rem; font-weight: 800; 
        }
        .doc-kind-badge.non-tax { background: rgba(46, 204, 113, 0.15); color: var(--ae-green); border: 1px solid var(--ae-green); }
        .doc-kind-badge.tax { background: rgba(231, 76, 60, 0.15); color: var(--ae-red); border: 1px solid var(--ae-red); }

        /* البيانات الأساسية */
        .info-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 30px;
        }
        .client-section { 
            background: linear-gradient(180deg, #111, #0a0a0a); 
            padding: 20px; 
            border-radius: 12px; 
            border-right: 4px solid var(--ae-gold); 
            position: relative; 
            z-index: 2; 
            border: 1px solid var(--ae-border);
            border-right-width: 4px;
        }
        .section-label { font-size: 0.9rem; color: var(--ae-gold); margin-bottom: 12px; font-weight: 800; border-bottom: 1px dashed #333; padding-bottom: 8px; }
        .info-row { display: flex; justify-content: space-between; margin-bottom: 8px; font-size: 0.95rem; color: #ddd; }
        .info-row span:first-child { font-weight: bold; color: var(--ae-text-muted); }

        /* جدول الأصناف */
        .items-table { 
            width: 100%; 
            border-collapse: collapse; 
            margin-bottom: 35px; 
            position: relative; 
            z-index: 2; 
            background: #111;
            border-radius: 10px;
            overflow: hidden;
            border: 1px solid var(--ae-border);
        }
        .items-table th { 
            background: #1a1a1a; 
            color: var(--ae-gold); 
            padding: 15px; 
            text-align: center; 
            font-size: 0.95rem; 
            border-bottom: 2px solid var(--ae-gold); 
            font-weight: 800; 
        }
        .items-table td { 
            padding: 15px; 
            border-bottom: 1px solid #222; 
            text-align: center; 
            font-size: 0.95rem; 
            color: #eee; 
            vertical-align: middle; 
        }
        .items-table td.desc { text-align: right; width: 45%; color: #fff; font-weight: 700; }
        .items-table tr:last-child td { border-bottom: none; }
        .items-table tr:hover { background: #151515; }

        /* الإجماليات */
        .totals-wrapper { width: 100%; display: table; position: relative; z-index: 2; margin-bottom: 40px; }
        .totals-table { 
            width: 50%; 
            float: left; 
            border-collapse: collapse; 
            background: #111; 
            border: 1px solid var(--ae-border); 
            border-radius: 12px; 
            overflow: hidden; 
        }
        .totals-table td { padding: 12px 20px; border-bottom: 1px dashed #222; font-size: 0.95rem; }
        .totals-table td:first-child { color: var(--ae-text-muted); text-align: right; font-weight: bold; }
        .totals-table td:last-child { color: #fff; text-align: left; font-family: monospace; font-weight: bold; font-size: 1.05rem; }
        .totals-table tr:last-child td { border-bottom: none; }
        
        .totals-table tr.grand { background: linear-gradient(135deg, var(--ae-gold), #b8860b); }
        .totals-table tr.grand td { color: #000; font-size: 1.3rem; font-weight: 900; border-top: 1px solid #000; }
        .clear-fix { clear: both; }

        /* الملاحظات والتذييل */
        .terms-box { 
            border: 1px solid var(--ae-border); 
            padding: 20px; 
            border-radius: 10px; 
            font-size: 0.9rem; 
            color: #ccc; 
            line-height: 1.8; 
            background: #111; 
            border-right: 4px solid var(--ae-gold);
        }
        .terms-box strong { color: var(--ae-gold); display: block; margin-bottom: 8px; font-size: 1rem; }
        
        .footer { 
            margin-top: 50px; 
            padding-top: 25px; 
            border-top: 1px dashed var(--ae-border); 
            text-align: center;
            font-size: 0.85rem; 
            color: var(--ae-text-muted); 
            line-height: 1.8; 
            position: relative; 
            z-index: 2; 
        }
        
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
            .invoice-title, .section-label, .date-item strong, .invoice-id, .date-item, .brand-name-fallback { color: #000 !important; text-shadow: none !important; }
            .logo-img { width: 70px !important; filter: none !important; }
            
            .client-section { background: transparent !important; border: 1px solid #000 !important; padding: 15px !important; border-right-width: 4px !important; border-right-color: #000 !important; }
            .info-row { color: #000 !important; }
            .info-row span:first-child { color: #333 !important; }
            
            .items-table { border: 1px solid #000 !important; background: transparent !important; }
            .items-table th { background: #f0f0f0 !important; color: #000 !important; border-bottom: 2px solid #000 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .items-table td { color: #000 !important; border-bottom: 1px solid #000 !important; }
            .items-table td.desc { color: #000 !important; }
            
            .totals-table { border-color: #000 !important; background: transparent !important; width: 50% !important; float: left !important; }
            .totals-table td { color: #000 !important; border-bottom-color: #000 !important; }
            .totals-table td:first-child { color: #333 !important; }
            .totals-table tr.grand { background: #f0f0f0 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .totals-table tr.grand td { color: #000 !important; border-top: 2px solid #000 !important; }
            
            .terms-box { border: 1px solid #000 !important; background: transparent !important; color: #000 !important; border-right-width: 4px !important; border-right-color: #000 !important;}
            .terms-box strong { color: #000 !important; }

            .footer { color: #000 !important; border-top: 1px solid #000 !important; margin-top: 30px !important; padding-top: 15px !important; }
            .doc-kind-badge { border-color: #000 !important; color: #000 !important; background: transparent !important; }
            
            .actions-bar { display: none !important; }

            /* 🌟 إعدادات منع قطع البيانات بين الصفحات (Pagination Control) 🌟 */
            .items-table, .totals-wrapper, .terms-box, .client-section, .info-grid, tr {
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
            .info-grid { grid-template-columns: 1fr; gap: 15px; }
            .totals-table { width: 100%; float: none; }
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
                <h1 class="invoice-title">فاتورة مشتريات</h1>
                <div class="invoice-id"><?php echo app_h($purchaseRef); ?></div>
                <div class="doc-kind-badge <?php echo $isTaxPurchase ? 'tax' : 'non-tax'; ?>">
                    <i class="fa-solid <?php echo $isTaxPurchase ? 'fa-file-shield' : 'fa-file-circle-check'; ?>"></i>
                    <?php echo app_h($isTaxPurchase ? 'فاتورة ضريبية / ETA' : 'فاتورة غير ضريبية'); ?>
                </div>
            </div>
            <div class="header-side center">
                <?php if ($appLogo): ?>
                    <img src="<?php echo app_h($appLogo); ?>" alt="Logo" class="logo-img" onerror="this.style.display='none'">
                <?php else: ?>
                    <div class="brand-name-fallback"><?php echo app_h((string)($brandProfile['org_name'] ?? $appName)); ?></div>
                <?php endif; ?>
            </div>
            <div class="header-side left">
                <div class="date-item"><strong>تاريخ الإصدار:</strong> <?php echo date('Y-m-d', strtotime($inv['inv_date'])); ?></div>
                <div class="date-item"><strong>تاريخ الاستحقاق:</strong> <?php echo date('Y-m-d', strtotime($inv['due_date'])); ?></div>
            </div>
        </div>

        <div class="info-grid">
            <div class="client-section">
                <div class="section-label"><i class="fa-solid fa-user-tie"></i> بيانات المورد</div>
                <h2 class="client-name" style="margin-bottom: 10px;"><?php echo app_h($inv['sup_name']); ?></h2>
                <?php if($hasCustomSupplierName): ?>
                    <div class="info-row"><span>الاسم الأساسي:</span> <span><?php echo app_h($inv['supplier_master_name']); ?></span></div>
                <?php endif; ?>
                <div class="info-row"><span>الهاتف:</span> <span style="direction:ltr;"><?php echo app_h($inv['sup_phone'] ?: '-'); ?></span></div>
                <div class="info-row"><span>العنوان:</span> <span><?php echo app_h($inv['sup_address'] ?: '-'); ?></span></div>
            </div>

            <div class="client-section" style="border-right-color: #555;">
                <div class="section-label" style="color:#ccc;"><i class="fa-solid fa-file-invoice"></i> معلومات الفاتورة</div>
                <div class="info-row"><span>رقم الفاتورة:</span> <span><?php echo app_h($purchaseRef); ?></span></div>
                <div class="info-row"><span>تاريخ السداد:</span> <span><?php echo app_h($inv['due_date']); ?></span></div>
                <div class="info-row"><span>أنشئت بواسطة:</span> <span>النظام (Admin)</span></div>
            </div>
        </div>

        <table class="items-table">
            <thead>
                <tr>
                    <th width="5%">#</th>
                    <th width="50%">البيان / الصنف المورد</th>
                    <th width="15%">الكمية</th>
                    <th width="15%">السعر</th>
                    <th width="15%">الإجمالي</th>
                </tr>
            </thead>
            <tbody>
                <?php if(!empty($items)): foreach($items as $k => $item): ?>
                <tr>
                    <td><?php echo $k+1; ?></td>
                    <td class="desc"><?php echo app_h($item['desc'] ?? ''); ?></td>
                    <td style="font-family: monospace; font-size:1.05rem;"><?php echo app_h((string)($item['qty'] ?? 0)); ?></td>
                    <td style="font-family: monospace; font-size:1.05rem;"><?php echo number_format((float)($item['price'] ?? 0), 2); ?></td>
                    <td style="font-family: monospace; font-size:1.05rem; font-weight: bold; color: var(--ae-gold);"><?php echo number_format((float)($item['total'] ?? 0), 2); ?></td>
                </tr>
                <?php endforeach; else: ?>
                <tr>
                    <td colspan="5" style="text-align:center; padding:30px; color:#888;">لا توجد أصناف مسجلة في هذه الفاتورة.</td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>

        <div class="totals-wrapper">
            <table class="totals-table">
                <tr>
                    <td>المجموع الفرعي:</td>
                    <td><?php echo number_format((float)($inv['sub_total'] ?? 0), 2); ?></td>
                </tr>

                <?php if((float)($inv['tax'] ?? 0) > 0): ?>
                <tr>
                    <td>(+) قيمة الضريبة:</td>
                    <td style="color: var(--ae-gold);"><?php echo number_format((float)$inv['tax'], 2); ?></td>
                </tr>
                <?php endif; ?>

                <?php if((float)($inv['discount'] ?? 0) > 0): ?>
                <tr>
                    <td>(-) قيمة الخصم:</td>
                    <td style="color: var(--ae-red);">- <?php echo number_format((float)$inv['discount'], 2); ?></td>
                </tr>
                <?php endif; ?>

                <tr class="grand">
                    <td>الإجمالي النهائي:</td>
                    <td><?php echo number_format((float)($inv['total_amount'] ?? 0), 2); ?> <small style="font-size:0.8rem; font-weight:bold;">EGP</small></td>
                </tr>
                
                <tr>
                    <td>المسدد للمورد:</td>
                    <td style="color: var(--ae-green);"><?php echo number_format((float)($inv['paid_amount'] ?? 0), 2); ?></td>
                </tr>
                <tr>
                    <td>المتبقي (دائن):</td>
                    <td style="color: var(--ae-red);"><?php echo number_format((float)($inv['remaining_amount'] ?? 0), 2); ?></td>
                </tr>
            </table>
            <div class="clear-fix"></div>
        </div>

        <?php if(!empty(trim((string)($inv['notes'] ?? '')))): ?>
        <div class="terms-box">
            <strong><i class="fa-solid fa-note-sticky"></i> ملاحظات الإدارة:</strong>
            <?php echo nl2br(app_h((string)$inv['notes'])); ?>
        </div>
        <?php endif; ?>

        <div class="footer">
            <p>تم إصدار هذه الفاتورة إلكترونياً واعتمادها من خلال نظام <strong><?php echo app_h($appName); ?></strong> للإدارة المالية.</p>
            <p>تاريخ ووقت الطباعة: <?php echo date('Y-m-d H:i:s'); ?></p>
            <?php if ($outputShowFooter && !empty($brandProfile['org_footer_note'])): ?>
                <p style="margin-top:5px; font-style:italic;"><?php echo app_h((string)$brandProfile['org_footer_note']); ?></p>
            <?php endif; ?>
        </div>

    </div>
</div>

<div class="actions-bar no-print">
    <a href="invoices.php?tab=purchases" class="btn btn-back">
        <i class="fa-solid fa-arrow-right"></i> الرجوع للسجل
    </a>
    <button onclick="window.print()" class="btn btn-print">
        <i class="fa-solid fa-print"></i> طباعة / حفظ PDF
    </button>
</div>

</body>
</html>