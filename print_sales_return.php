<?php
require 'auth.php';
require 'config.php';

$appName = app_setting_get($conn, 'app_name', 'Arab Eagles');
$appLogo = app_brand_logo_path($conn, 'assets/img/Logo.png');

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    die('رقم مردود المبيعات غير صحيح');
}

$stmt = $conn->prepare("
    SELECT r.*, i.invoice_number, c.name AS client_name, c.phone AS client_phone, c.address AS client_address
    FROM invoice_returns r
    JOIN invoices i ON i.id = r.invoice_id
    JOIN clients c ON c.id = i.client_id
    WHERE r.id = ?
    LIMIT 1
");
$stmt->bind_param('i', $id);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row) {
    die('مردود المبيعات غير موجود');
}

$itemStmt = $conn->prepare("
    SELECT ri.*, ii.description
    FROM invoice_return_items ri
    LEFT JOIN invoice_items ii ON ii.id = ri.invoice_item_id
    WHERE ri.invoice_return_id = ?
    ORDER BY ri.line_number ASC, ri.id ASC
");
$itemStmt->bind_param('i', $id);
$itemStmt->execute();
$itemRes = $itemStmt->get_result();
$items = [];
while ($it = $itemRes->fetch_assoc()) {
    $items[] = $it;
}
$itemStmt->close();

$ref = trim((string)($row['return_number'] ?? ''));
if ($ref === '') {
    $ref = 'SRTN-' . str_pad((string)$row['id'], 5, '0', STR_PAD_LEFT);
}
$invoiceRef = trim((string)($row['invoice_number'] ?? ''));
if ($invoiceRef === '') {
    $invoiceRef = '#' . (int)$row['invoice_id'];
}
?>
<!DOCTYPE html>
<html dir="rtl" lang="ar">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>مردود مبيعات <?php echo app_h($ref); ?> | <?php echo app_h($appName); ?></title>
    
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800&display=swap" rel="stylesheet">
    
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
        }
        
        body { 
            font-family: 'Cairo', sans-serif; 
            background: var(--ae-dark); 
            color: var(--ae-text); 
            padding: 40px 20px; 
            margin: 0; 
            line-height: 1.6;
        }
        
        .doc { 
            max-width: 210mm; 
            min-height: 297mm; 
            margin: 0 auto; 
            background: var(--ae-card); 
            padding: 50px; 
            box-shadow: 0 15px 40px rgba(0,0,0,0.6); 
            border: 1px solid var(--ae-border);
            border-radius: 8px;
            box-sizing: border-box;
        }

        /* أزرار التحكم */
        .no-print { 
            position: fixed; 
            top: 20px; 
            right: 20px; /* تغيير ليتناسب مع RTL */
            left: auto;
            z-index: 999; 
            display: flex; 
            gap: 12px; 
            background: rgba(10, 10, 10, 0.8);
            padding: 10px;
            border-radius: 10px;
            backdrop-filter: blur(5px);
            border: 1px solid var(--ae-border);
        }
        .no-print a, .no-print button { 
            padding: 10px 20px; 
            border-radius: 6px; 
            text-decoration: none; 
            border: none; 
            cursor: pointer; 
            font-weight: 700; 
            font-family: 'Cairo', sans-serif;
            font-size: 0.95rem;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
        }
        .no-print button { background: var(--ae-gold); color: #000; }
        .no-print button:hover { background: var(--ae-gold-light); transform: translateY(-2px); }
        .no-print a { background: #222; color: #fff; border: 1px solid #444; }
        .no-print a:hover { border-color: var(--ae-gold); color: var(--ae-gold); }

        /* الترويسة */
        .header { 
            display: flex; 
            justify-content: space-between; 
            align-items: flex-start; 
            border-bottom: 2px solid var(--ae-gold); 
            padding-bottom: 25px; 
            margin-bottom: 35px; 
        }
        .brand { display: flex; flex-direction: column; gap: 5px; }
        .logo { width: auto; height: 50px; object-fit: contain; margin-bottom: 10px; }
        .brand h1 { margin: 0; color: var(--ae-gold); font-size: 1.8rem; font-weight: 800; letter-spacing: 0.5px; }
        .brand p { margin: 0; color: var(--ae-text-muted); font-size: 0.95rem; font-weight: 600; text-transform: uppercase; }
        
        .title { text-align: left; }
        .title h2 { margin: 0 0 5px 0; font-size: 2.2rem; color: #fff; font-weight: 800; }
        .title .ref { font-weight: 700; color: var(--ae-gold); font-size: 1.2rem; font-family: monospace; letter-spacing: 1px; }

        /* البيانات الأساسية */
        .info-grid { 
            display: grid; 
            grid-template-columns: 1fr 1fr; 
            gap: 25px; 
            margin-bottom: 35px; 
        }
        .info-box {
            background: #111;
            padding: 20px;
            border-radius: 8px;
            border: 1px solid var(--ae-border);
        }
        .info-box h3 { 
            margin: 0 0 15px 0; 
            color: var(--ae-gold); 
            font-size: 1.1rem; 
            border-bottom: 1px dashed #333; 
            padding-bottom: 8px; 
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .info-box div { margin-bottom: 8px; font-size: 0.95rem; color: #ddd; display: flex; justify-content: space-between; }
        .info-box div strong { color: var(--ae-text-muted); }

        /* الجداول */
        table { 
            width: 100%; 
            border-collapse: collapse; 
            margin-bottom: 30px; 
            background: #111;
            border-radius: 8px;
            overflow: hidden;
            border: 1px solid var(--ae-border);
        }
        th { 
            background: #1a1a1a; 
            color: var(--ae-gold); 
            padding: 15px 12px; 
            border-bottom: 2px solid var(--ae-gold); 
            font-weight: 800;
            font-size: 0.95rem;
        }
        td { 
            padding: 12px; 
            border-bottom: 1px solid #222; 
            text-align: center; 
            color: #eee;
            font-size: 0.95rem;
        }
        tbody tr:last-child td { border-bottom: none; }
        tbody tr:hover { background: #151515; }

        /* الإجماليات */
        .totals-wrapper { display: flex; justify-content: flex-end; }
        .totals { 
            width: 350px; 
            background: #111; 
            border: 1px solid var(--ae-border); 
            padding: 20px; 
            border-radius: 8px; 
        }
        .totals .row { 
            display: flex; 
            justify-content: space-between; 
            margin-bottom: 12px; 
            color: #ddd;
            font-size: 0.95rem;
        }
        .totals .row span:last-child { font-family: monospace; font-size: 1.05rem; }
        .totals .grand { 
            border-top: 1px dashed #444; 
            padding-top: 15px; 
            margin-top: 10px; 
            font-weight: 800; 
            font-size: 1.2rem; 
            color: var(--ae-gold);
        }
        .totals .grand span:last-child { font-size: 1.3rem; }

        /* الملاحظات والتذييل */
        .notes-box {
            margin-top: 35px; 
            background: #111; 
            padding: 20px; 
            border-right: 4px solid var(--ae-gold);
            border-radius: 4px;
            font-size: 0.9rem;
            color: #ccc;
        }
        .notes-box strong { color: var(--ae-gold); display: block; margin-bottom: 5px; }
        
        .footer { 
            margin-top: 50px; 
            border-top: 1px solid #333; 
            padding-top: 20px; 
            text-align: center; 
            color: #666; 
            font-size: 0.85rem; 
        }

        /* ========================================================= */
        /* إعدادات الطباعة (Print Mode) - أبيض وأسود رسمي لحفظ الحبر */
        /* ========================================================= */
        @media print { 
            body { background: #fff !important; padding: 0 !important; color: #000 !important; } 
            .doc { 
                box-shadow: none !important; 
                padding: 0 !important; 
                border: none !important; 
                background: #fff !important; 
                max-width: 100% !important;
            } 
            .no-print { display: none !important; } 

            .header { border-bottom: 2px solid #000 !important; margin-bottom: 20px !important; }
            .brand h1 { color: #000 !important; }
            .brand p { color: #555 !important; }
            .title h2 { color: #000 !important; }
            .title .ref { color: #000 !important; }

            .info-box { background: transparent !important; border: 1px solid #000 !important; }
            .info-box h3 { color: #000 !important; border-bottom: 1px solid #000 !important; }
            .info-box div, .info-box div strong { color: #000 !important; }

            table { background: transparent !important; border: 1px solid #000 !important; }
            th { 
                background: #f0f0f0 !important; 
                color: #000 !important; 
                border-bottom: 2px solid #000 !important; 
                border-left: 1px solid #000 !important;
                -webkit-print-color-adjust: exact; 
                print-color-adjust: exact; 
            }
            td { 
                color: #000 !important; 
                border: 1px solid #000 !important; 
            }

            .totals { background: transparent !important; border: 1px solid #000 !important; }
            .totals .row { color: #000 !important; }
            .totals .grand { border-top: 2px solid #000 !important; color: #000 !important; }

            .notes-box { background: transparent !important; border: 1px solid #000 !important; border-right: 4px solid #000 !important; color: #000 !important; }
            .notes-box strong { color: #000 !important; }

            /* 🌟 إعدادات منع قطع البيانات بين الصفحات (Pagination Control) 🌟 */
            .info-grid, .totals-wrapper, .notes-box, tr {
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

<div class="no-print">
    <button onclick="window.print()"><i class="fa-solid fa-print"></i> طباعة / حفظ PDF</button>
    <a href="sales_returns.php"><i class="fa-solid fa-arrow-right"></i> رجوع للقائمة</a>
</div>

<div class="doc">
    <div class="header">
        <div class="brand">
            <img src="<?php echo app_h($appLogo); ?>" alt="logo" class="logo" onerror="this.style.display='none'">
            <h1><?php echo app_h($appName); ?></h1>
            <p>مستند رسمي</p>
        </div>
        <div class="title">
            <h2>مردود مبيعات</h2>
            <div class="ref"><?php echo app_h($ref); ?></div>
        </div>
    </div>

    <div class="info-grid">
        <div class="info-box">
            <h3><i class="fa-solid fa-user-tie"></i> بيانات العميل</h3>
            <div><strong>الاسم:</strong> <span><?php echo app_h((string)$row['client_name']); ?></span></div>
            <div><strong>الهاتف:</strong> <span style="direction:ltr;"><?php echo app_h((string)($row['client_phone'] ?? '-')); ?></span></div>
            <div><strong>العنوان:</strong> <span><?php echo app_h((string)($row['client_address'] ?? '-')); ?></span></div>
        </div>
        <div class="info-box">
            <h3><i class="fa-solid fa-file-invoice"></i> تفاصيل المستند</h3>
            <div><strong>رقم فاتورة المبيعات:</strong> <span><?php echo app_h($invoiceRef); ?></span></div>
            <div><strong>تاريخ المردود:</strong> <span><?php echo app_h((string)$row['return_date']); ?></span></div>
            <div><strong>المبلغ المردود للعميل:</strong> <span><?php echo number_format((float)($row['refund_amount'] ?? 0), 2); ?></span></div>
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
                    <td style="text-align:right; font-weight:600;"><?php echo app_h((string)($item['description'] ?? '')); ?></td>
                    <td><?php echo app_h((string)($item['quantity'] ?? 0)); ?></td>
                    <td><?php echo number_format((float)($item['unit_price'] ?? 0), 2); ?></td>
                    <td><?php echo number_format((float)($item['line_total'] ?? 0), 2); ?></td>
                </tr>
            <?php endforeach; else: ?>
                <tr><td colspan="5" style="padding:30px; color:#666;">لا توجد أصناف مسجلة في هذا المردود.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>

    <div class="totals-wrapper">
        <div class="totals">
            <div class="row"><span>المجموع الفرعي</span><span><?php echo number_format((float)$row['subtotal_amount'], 2); ?></span></div>
            <div class="row"><span>قيمة الضريبة</span><span><?php echo number_format((float)$row['tax_amount'], 2); ?></span></div>
            <div class="row"><span>قيمة الخصم المسترد</span><span><?php echo number_format((float)$row['discount_amount'], 2); ?></span></div>
            <div class="row"><span>رد المبلغ نقداً/تحويل</span><span><?php echo number_format((float)($row['refund_amount'] ?? 0), 2); ?></span></div>
            
            <div class="row grand">
                <span>الرصيد الدائن المتبقي</span>
                <span><?php echo number_format(max(0, (float)$row['total_amount'] - (float)($row['refund_amount'] ?? 0)), 2); ?></span>
            </div>
        </div>
    </div>

    <?php if (trim((string)($row['notes'] ?? '')) !== ''): ?>
        <div class="notes-box">
            <strong><i class="fa-solid fa-pen-to-square"></i> ملاحظات:</strong>
            <?php echo nl2br(app_h((string)$row['notes'])); ?>
        </div>
    <?php endif; ?>

    <div class="footer">
        تم إنشاء هذا المستند إلكترونياً من خلال نظام <?php echo app_h($appName); ?>.<br>
        وثيقة غير قابلة للتعديل.
    </div>
</div>

</body>
</html>