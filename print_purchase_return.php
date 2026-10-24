<?php
require 'auth.php';
require 'config.php';

$appName = app_setting_get($conn, 'app_name', 'Arab Eagles');
$appLogo = app_brand_logo_path($conn, 'assets/img/Logo.png');

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    die('رقم مردود الشراء غير صحيح');
}

$stmt = $conn->prepare("
    SELECT r.*, s.name AS supplier_name, s.phone AS supplier_phone, s.address AS supplier_address,
           w.name AS warehouse_name, p.purchase_number
    FROM purchase_invoice_returns r
    JOIN suppliers s ON s.id = r.supplier_id
    LEFT JOIN warehouses w ON w.id = r.warehouse_id
    LEFT JOIN purchase_invoices p ON p.id = r.purchase_invoice_id
    WHERE r.id = ?
    LIMIT 1
");
$stmt->bind_param('i', $id);
$stmt->execute();
$res = $stmt->get_result();
$row = $res->fetch_assoc();
$stmt->close();

if (!$row) {
    die('مردود الشراء غير موجود');
}

$items = json_decode((string)($row['items_json'] ?? '[]'), true);
if (!is_array($items)) {
    $items = [];
}
$ref = trim((string)($row['return_number'] ?? ''));
if ($ref === '') {
    $ref = 'PRTN-' . str_pad((string)$row['id'], 5, '0', STR_PAD_LEFT);
}
$purchaseRef = trim((string)($row['purchase_number'] ?? ''));
if ($purchaseRef === '') {
    $purchaseRef = '#' . (int)$row['purchase_invoice_id'];
}
?>
<!DOCTYPE html>
<html dir="rtl" lang="ar">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>مردود شراء <?php echo app_h($ref); ?> | <?php echo app_h($appName); ?></title>
    
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800;900&display=swap" rel="stylesheet">
    
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
            --ae-red: #e74c3c;
            --ae-green: #2ecc71;
        }
        
        body { 
            font-family: 'Cairo', sans-serif; 
            background: var(--ae-dark); 
            color: var(--ae-text); 
            padding: 40px 20px 120px 20px; 
            margin: 0; 
            line-height: 1.6;
        }
        
        .doc { 
            max-width: 900px; 
            margin: 0 auto; 
            background: var(--ae-card); 
            padding: 40px 50px; 
            box-shadow: 0 15px 40px rgba(0,0,0,0.6); 
            border: 1px solid var(--ae-border);
            border-radius: 16px;
            box-sizing: border-box;
            position: relative;
            overflow: hidden;
        }
        
        .doc::before { 
            content: ''; 
            position: absolute; 
            top: 0; left: 0; right: 0; 
            height: 5px; 
            background: linear-gradient(90deg, var(--ae-gold), #b8860b, var(--ae-gold)); 
            z-index: 10;
        }

        /* شريط الإجراءات العائم (Action Bar) */
        .action-bar { 
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

        /* الترويسة */
        .header { 
            display: flex; 
            justify-content: space-between; 
            align-items: flex-start; 
            border-bottom: 1px dashed var(--ae-border); 
            padding-bottom: 25px; 
            margin-bottom: 35px; 
        }
        .brand { display: flex; flex-direction: column; gap: 5px; }
        .logo { width: auto; height: 65px; object-fit: contain; margin-bottom: 10px; border-radius: 8px; }
        .brand h1 { margin: 0; color: var(--ae-gold); font-size: 1.6rem; font-weight: 900; letter-spacing: 0.5px; }
        .brand p { margin: 0; color: var(--ae-text-muted); font-size: 0.9rem; font-weight: 600; text-transform: uppercase; }
        
        .title { text-align: left; }
        .title h2 { margin: 0 0 5px 0; font-size: 2rem; color: #fff; font-weight: 900; }
        .title .ref { font-weight: 800; color: var(--ae-gold); font-size: 1.2rem; font-family: monospace; letter-spacing: 1px; }

        /* البيانات الأساسية */
        .info-grid { 
            display: grid; 
            grid-template-columns: 1fr 1fr; 
            gap: 25px; 
            margin-bottom: 35px; 
        }
        .info-box {
            background: #0a0a0a;
            padding: 20px;
            border-radius: 12px;
            border: 1px solid var(--ae-border);
            border-right: 3px solid var(--ae-gold);
        }
        .info-box h3 { 
            margin: 0 0 15px 0; 
            color: var(--ae-gold); 
            font-size: 1.05rem; 
            border-bottom: 1px solid #222; 
            padding-bottom: 8px; 
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .info-box div { margin-bottom: 8px; font-size: 0.95rem; color: #ddd; display: flex; justify-content: space-between; }
        .info-box div strong { color: var(--ae-text-muted); font-weight: 600; }

        /* الجداول */
        table { 
            width: 100%; 
            border-collapse: collapse; 
            margin-bottom: 30px; 
            background: #0a0a0a;
            border-radius: 10px;
            overflow: hidden;
            border: 1px solid var(--ae-border);
        }
        th { 
            background: #1a1a1a; 
            color: var(--ae-gold); 
            padding: 15px; 
            border-bottom: 2px solid var(--ae-gold); 
            font-weight: 900;
            font-size: 0.95rem;
            text-align: center;
        }
        td { 
            padding: 15px; 
            border-bottom: 1px solid #222; 
            text-align: center; 
            color: #eee;
            font-size: 0.95rem;
            vertical-align: middle;
        }
        tbody tr:last-child td { border-bottom: none; }
        tbody tr:hover { background: #111; }
        .td-desc { text-align: right; width: 45%; font-weight: bold; color: #fff; }
        .td-num { font-family: monospace; font-size: 1.05rem; }

        /* الإجماليات */
        .totals-wrapper { display: flex; justify-content: flex-end; margin-bottom: 30px; }
        .totals { 
            width: 50%; 
            background: #0a0a0a; 
            border: 1px solid var(--ae-border); 
            padding: 20px; 
            border-radius: 12px; 
        }
        .totals .row { 
            display: flex; 
            justify-content: space-between; 
            margin-bottom: 12px; 
            color: #ccc;
            font-size: 0.95rem;
        }
        .totals .row span:last-child { font-family: monospace; font-size: 1.05rem; font-weight: bold; color: #fff; }
        .totals .grand { 
            border-top: 1px dashed #444; 
            padding-top: 15px; 
            margin-top: 10px; 
            font-weight: 900; 
            font-size: 1.2rem; 
            color: var(--ae-gold);
        }
        .totals .grand span:last-child { font-size: 1.4rem; }

        /* الملاحظات والتذييل */
        .notes-box {
            margin-top: 20px; 
            background: #0a0a0a; 
            padding: 20px; 
            border-right: 4px solid var(--ae-gold);
            border-radius: 10px;
            font-size: 0.95rem;
            color: #ddd;
            line-height: 1.8;
            border: 1px solid var(--ae-border);
            border-right-width: 4px;
        }
        .notes-box strong { color: var(--ae-gold); display: block; margin-bottom: 8px; font-size: 1.05rem; }
        
        .footer { 
            margin-top: 50px; 
            border-top: 1px dashed var(--ae-border); 
            padding-top: 20px; 
            text-align: center; 
            color: var(--ae-text-muted); 
            font-size: 0.85rem; 
            line-height: 1.8;
        }

        /* التوافقية مع الهواتف */
        @media (max-width: 768px) {
            .doc { padding: 25px 15px; }
            .info-grid { grid-template-columns: 1fr; }
            .header { flex-direction: column; align-items: center; text-align: center; gap: 15px; }
            .title { text-align: center; }
            .totals { width: 100%; }
            .actions-bar { width: 95%; flex-direction: column; border-radius: 20px; padding: 15px; }
        }

        /* ========================================================= */
        /* إعدادات الطباعة (Print Mode) - أبيض وأسود رسمي لحفظ الحبر */
        /* ========================================================= */
        @media print { 
            @page { size: A4; margin: 15mm; }
            body { background: #fff !important; padding: 0 !important; color: #000 !important; } 
            .doc { 
                box-shadow: none !important; 
                padding: 0 !important; 
                border: none !important; 
                background: #fff !important; 
                max-width: 100% !important;
            } 
            .doc::before { display: none !important; }
            .action-bar { display: none !important; } 

            .header { border-bottom: 2px solid #000 !important; margin-bottom: 20px !important; }
            .brand h1, .title h2, .title .ref { color: #000 !important; }
            .brand p { color: #555 !important; }
            .logo { filter: grayscale(100%); }

            .info-box { background: transparent !important; border: 1px solid #000 !important; border-right: 4px solid #000 !important; }
            .info-box h3 { color: #000 !important; border-bottom: 1px solid #ccc !important; }
            .info-box div, .info-box div strong { color: #000 !important; }

            table { background: transparent !important; border: 1px solid #000 !important; }
            th { 
                background: #f0f0f0 !important; 
                color: #000 !important; 
                border-bottom: 2px solid #000 !important; 
                -webkit-print-color-adjust: exact; 
                print-color-adjust: exact; 
            }
            td { color: #000 !important; border-bottom: 1px solid #ccc !important; }
            .td-desc { color: #000 !important; }

            .totals { background: transparent !important; border: 1px solid #000 !important; }
            .totals .row { color: #000 !important; }
            .totals .row span:last-child { color: #000 !important; }
            .totals .grand { border-top: 2px solid #000 !important; color: #000 !important; }

            .notes-box { background: transparent !important; border: 1px solid #000 !important; border-right: 4px solid #000 !important; color: #000 !important; }
            .notes-box strong { color: #000 !important; }

            .footer { border-top: 1px solid #000 !important; color: #555 !important; }

            /* 🌟 إعدادات منع قطع البيانات بين الصفحات (Pagination Control) 🌟 */
            .info-grid, .totals-wrapper, .notes-box, tr, .footer {
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

<div class="doc">
    <div class="header">
        <div class="brand">
            <img src="<?php echo app_h($appLogo); ?>" alt="logo" class="logo" onerror="this.style.display='none'">
            <h1><?php echo app_h($appName); ?></h1>
            <p>مستند مردود مبيعات موردين</p>
        </div>
        <div class="title">
            <h2>مردود شراء</h2>
            <div class="ref"><?php echo app_h($ref); ?></div>
        </div>
    </div>

    <div class="info-grid">
        <div class="info-box">
            <h3><i class="fa-solid fa-truck-field"></i> بيانات المورد</h3>
            <div><strong>الاسم:</strong> <span><?php echo app_h((string)$row['supplier_name']); ?></span></div>
            <div><strong>الهاتف:</strong> <span style="direction:ltr;"><?php echo app_h((string)($row['supplier_phone'] ?? '-')); ?></span></div>
            <div><strong>العنوان:</strong> <span><?php echo app_h((string)($row['supplier_address'] ?? '-')); ?></span></div>
        </div>
        <div class="info-box">
            <h3><i class="fa-solid fa-file-invoice"></i> تفاصيل المستند</h3>
            <div><strong>رقم فاتورة الشراء:</strong> <span><?php echo app_h($purchaseRef); ?></span></div>
            <div><strong>تاريخ المردود:</strong> <span><?php echo app_h((string)$row['return_date']); ?></span></div>
            <div><strong>مخزن الاستلام:</strong> <span><?php echo app_h((string)($row['warehouse_name'] ?? '-')); ?></span></div>
        </div>
    </div>

    <table>
        <thead>
            <tr>
                <th style="width: 5%;">#</th>
                <th style="text-align:right; width: 45%;">البيان / الصنف المردود</th>
                <th style="width: 15%;">الكمية</th>
                <th style="width: 15%;">سعر الوحدة</th>
                <th style="width: 20%;">الإجمالي</th>
            </tr>
        </thead>
        <tbody>
            <?php if ($items): foreach ($items as $idx => $item): ?>
                <tr>
                    <td><?php echo $idx + 1; ?></td>
                    <td class="td-desc"><?php echo app_h((string)($item['desc'] ?? '')); ?></td>
                    <td class="td-num"><?php echo app_h((string)($item['qty'] ?? 0)); ?></td>
                    <td class="td-num"><?php echo number_format((float)($item['price'] ?? 0), 2); ?></td>
                    <td class="td-num" style="color: var(--ae-gold); font-weight:bold;"><?php echo number_format((float)($item['total'] ?? 0), 2); ?></td>
                </tr>
            <?php endforeach; else: ?>
                <tr><td colspan="5" style="padding:30px; color:#666;">لا توجد أصناف مسجلة في هذا المردود.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>

    <?php $refundAmount = round((float)($row['refund_amount'] ?? 0), 2); ?>
    <div class="totals-wrapper">
        <div class="totals">
            <div class="row"><span>المجموع الفرعي</span><span><?php echo number_format((float)$row['subtotal_amount'], 2); ?></span></div>
            <div class="row"><span>قيمة الضريبة</span><span><?php echo number_format((float)$row['tax_amount'], 2); ?></span></div>
            <div class="row"><span>قيمة الخصم</span><span style="color:var(--ae-red);">- <?php echo number_format((float)$row['discount_amount'], 2); ?></span></div>
            <div class="row"><span>رد المبلغ من المورد</span><span style="color:var(--ae-green);"><?php echo number_format($refundAmount, 2); ?></span></div>
            
            <div class="row grand">
                <span>رصيد المورد الدائن</span>
                <span><?php echo number_format(max(0, (float)$row['total_amount'] - $refundAmount), 2); ?></span>
            </div>
            
            <div class="row" style="margin-top:10px; border-top:1px dashed #333; padding-top:10px;">
                <span>المبلغ الإجمالي للمردود</span>
                <span><?php echo number_format((float)$row['total_amount'], 2); ?></span>
            </div>
        </div>
    </div>

    <?php if (trim((string)($row['notes'] ?? '')) !== ''): ?>
        <div class="notes-box">
            <strong><i class="fa-solid fa-pen-to-square"></i> ملاحظات وسبب الارتجاع:</strong>
            <?php echo nl2br(app_h((string)$row['notes'])); ?>
        </div>
    <?php endif; ?>

    <div class="footer">
        تم إنشاء هذا المستند إلكترونياً واعتماده من خلال نظام <strong><?php echo app_h($appName); ?></strong>.<br>
        وثيقة مالية غير قابلة للتعديل.
    </div>
</div>

<div class="action-bar no-print">
    <a class="btn btn-back" href="purchase_returns.php">
        <i class="fa-solid fa-arrow-right"></i> الرجوع للسجل
    </a>
    <button class="btn btn-print" onclick="window.print()">
        <i class="fa-solid fa-print"></i> طباعة / حفظ PDF
    </button>
</div>

</body>
</html>