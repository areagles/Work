<?php
// print_salary.php - طباعة بيان الراتب
require 'auth.php'; 
require 'config.php';

$appName = app_setting_get($conn, 'app_name', 'Arab Eagles');

$id = intval($_GET['id']);
$sql = "SELECT p.*, u.full_name, u.role FROM payroll_sheets p LEFT JOIN users u ON p.employee_id = u.id WHERE p.id=$id";
$res = $conn->query($sql);
if(!$res || $res->num_rows==0) die("بيان الراتب غير موجود");
$row = $res->fetch_assoc();
$employeeName = app_payroll_employee_label($row);
$loanDeduct = max(0, (float)($row['loan_deduction'] ?? 0));
$otherDeduct = max(0, (float)$row['deductions'] - $loanDeduct);
$row['payroll_number'] = $row['payroll_number'] ?? '';
if ((string)$row['payroll_number'] === '') {
    $row['payroll_number'] = app_assign_document_number($conn, 'payroll_sheets', (int)$id, 'payroll_number', 'payroll', date('Y-m-d'));
}
?>
<!DOCTYPE html>
<html dir="rtl" lang="ar">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>بيان راتب - <?php echo app_h($employeeName); ?> | <?php echo app_h($appName); ?></title>
    
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
            --ae-green: #2ecc71;
            --ae-red: #e74c3c;
        }
        
        body { 
            font-family: 'Cairo', sans-serif; 
            background: var(--ae-dark); 
            color: var(--ae-text); 
            padding: 40px 20px; 
            margin: 0; 
            line-height: 1.6;
            display: flex;
            flex-direction: column;
            align-items: center;
        }
        
        .doc { 
            width: 100%;
            max-width: 800px;
            background: var(--ae-card); 
            padding: 40px 50px; 
            box-shadow: 0 15px 40px rgba(0,0,0,0.6); 
            border: 1px solid var(--ae-border);
            border-radius: 12px;
            box-sizing: border-box;
            position: relative;
            overflow: hidden;
        }

        .doc::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 4px;
            background: linear-gradient(90deg, var(--ae-gold), var(--ae-gold-light), var(--ae-gold));
        }

        /* أزرار التحكم */
        .no-print { 
            position: fixed; 
            top: 20px; 
            right: 20px;
            z-index: 999; 
            display: flex; 
            gap: 12px; 
            background: rgba(10, 10, 10, 0.8);
            padding: 10px;
            border-radius: 10px;
            backdrop-filter: blur(5px);
            border: 1px solid var(--ae-border);
        }
        .no-print button { 
            padding: 10px 25px; 
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
            background: var(--ae-gold); 
            color: #000;
        }
        .no-print button:hover { background: var(--ae-gold-light); transform: translateY(-2px); }

        /* الترويسة */
        .header { 
            display: flex; 
            justify-content: space-between; 
            align-items: center; 
            border-bottom: 1px dashed var(--ae-border); 
            padding-bottom: 25px; 
            margin-bottom: 30px; 
        }
        .brand h1 { margin: 0; color: var(--ae-gold); font-size: 1.8rem; font-weight: 800; letter-spacing: 0.5px; }
        .brand p { margin: 5px 0 0; color: var(--ae-text-muted); font-size: 0.95rem; }
        
        .title { text-align: left; }
        .title h2 { margin: 0; font-size: 1.6rem; color: #fff; font-weight: 800; text-transform: uppercase; }
        .title .ref { margin-top: 5px; font-weight: 700; color: var(--ae-gold); font-size: 1.1rem; font-family: monospace; letter-spacing: 1px; }

        /* بيانات الموظف */
        .info-grid { 
            display: grid; 
            grid-template-columns: 1fr 1fr; 
            gap: 20px; 
            margin-bottom: 35px; 
            background: #111;
            padding: 20px;
            border-radius: 8px;
            border: 1px solid var(--ae-border);
        }
        .info-item { display: flex; flex-direction: column; gap: 4px; }
        .info-item .label { color: var(--ae-text-muted); font-size: 0.85rem; font-weight: 600; }
        .info-item .val { color: #fff; font-size: 1.05rem; font-weight: 700; }

        /* تفاصيل الراتب */
        .salary-section { margin-bottom: 30px; }
        .salary-section h3 { color: var(--ae-gold); font-size: 1.1rem; border-bottom: 1px solid #333; padding-bottom: 8px; margin-bottom: 15px; }
        
        .s-row { 
            display: flex; 
            justify-content: space-between; 
            padding: 10px 15px; 
            margin-bottom: 8px; 
            background: #111; 
            border-radius: 6px; 
            border: 1px solid var(--ae-border);
            font-size: 0.95rem;
        }
        .s-row span:last-child { font-weight: 700; font-family: monospace; font-size: 1.05rem; }
        
        .s-row.basic { border-left: 3px solid #555; }
        .s-row.addition { border-left: 3px solid var(--ae-green); }
        .s-row.deduction { border-left: 3px solid var(--ae-red); }
        .s-row.deduction-loan { border-left: 3px solid var(--ae-gold-light); }

        .s-row .txt-green { color: var(--ae-green); }
        .s-row .txt-red { color: var(--ae-red); }
        .s-row .txt-gold { color: var(--ae-gold-light); }

        /* الصافي المالي */
        .net-area { 
            background: linear-gradient(135deg, #1a1500, #111); 
            padding: 25px; 
            text-align: center; 
            border: 1px solid var(--ae-gold); 
            border-radius: 8px; 
            margin-bottom: 30px;
            box-shadow: 0 0 20px rgba(212, 175, 55, 0.05);
        }
        .net-area .label { color: var(--ae-gold); font-size: 1rem; font-weight: 600; display: block; margin-bottom: 5px; }
        .net-area .amount { color: #fff; font-size: 2.5rem; font-weight: 800; }
        .net-area .currency { color: var(--ae-gold); font-size: 1.2rem; }

        /* حالة الدفع */
        .status-area {
            display: flex;
            justify-content: space-around;
            background: #111;
            padding: 15px;
            border-radius: 8px;
            border: 1px solid var(--ae-border);
            margin-bottom: 40px;
        }
        .status-item { text-align: center; }
        .status-item .s-label { display: block; color: var(--ae-text-muted); font-size: 0.85rem; margin-bottom: 5px; }
        .status-item .s-val { color: #fff; font-weight: 700; font-size: 1.1rem; }
        .status-paid { color: var(--ae-green) !important; }
        .status-pending { color: var(--ae-gold-light) !important; }

        /* التوقيعات */
        .signatures { 
            display: flex; 
            justify-content: space-between; 
            margin-top: 50px; 
            padding-top: 20px;
            border-top: 1px dashed #333;
        }
        .sig-box { 
            text-align: center; 
            width: 200px; 
        }
        .sig-line { 
            border-bottom: 1px solid #555; 
            height: 40px; 
            margin-bottom: 10px; 
        }
        .sig-title { 
            color: var(--ae-text-muted); 
            font-size: 0.9rem; 
            font-weight: 600; 
        }

        /* ========================================================= */
        /* إعدادات الطباعة (Print Mode) - أبيض وأسود رسمي لحفظ الحبر */
        /* ========================================================= */
        @media print { 
            body { 
                background: #fff !important; 
                padding: 0 !important; 
                color: #000 !important; 
                display: block;
            } 
            .doc { 
                box-shadow: none !important; 
                padding: 20px 0 !important; 
                border: none !important; 
                background: #fff !important; 
                max-width: 100% !important;
            } 
            .doc::before { display: none !important; }
            .no-print { display: none !important; } 

            .header { border-bottom: 2px solid #000 !important; margin-bottom: 20px !important; }
            .brand h1 { color: #000 !important; }
            .brand p { color: #555 !important; }
            .title h2 { color: #000 !important; }
            .title .ref { color: #000 !important; }

            .info-grid { background: transparent !important; border: 1px solid #000 !important; padding: 15px !important; margin-bottom: 20px !important; }
            .info-item .label { color: #555 !important; }
            .info-item .val { color: #000 !important; }

            .salary-section h3 { color: #000 !important; border-bottom: 1px solid #000 !important; }
            .s-row { background: transparent !important; border: 1px solid #ccc !important; color: #000 !important; padding: 6px 10px !important; }
            
            .s-row.basic { border-left: 3px solid #000 !important; }
            .s-row.addition { border-left: 3px solid #000 !important; }
            .s-row.deduction { border-left: 3px solid #000 !important; }
            .s-row.deduction-loan { border-left: 3px solid #000 !important; }

            .s-row .txt-green, .s-row .txt-red, .s-row .txt-gold { color: #000 !important; }

            .net-area { 
                background: transparent !important; 
                border: 2px solid #000 !important; 
                padding: 15px !important; 
                box-shadow: none !important;
                margin-bottom: 20px !important;
            }
            .net-area .label { color: #000 !important; }
            .net-area .amount { color: #000 !important; font-size: 2rem !important; }
            .net-area .currency { color: #000 !important; }

            .status-area { background: transparent !important; border: 1px solid #000 !important; margin-bottom: 30px !important; padding: 10px !important; }
            .status-item .s-label { color: #555 !important; }
            .status-item .s-val, .status-paid, .status-pending { color: #000 !important; }

            .signatures { border-top: 1px dashed #000 !important; margin-top: 30px !important; }
            .sig-line { border-bottom: 1px solid #000 !important; }
            .sig-title { color: #000 !important; }

            /* 🌟 إعدادات منع قطع البيانات بين الصفحات (Pagination Control) 🌟 */
            .doc, .info-grid, .salary-section, .net-area, .status-area, .signatures {
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

    <div class="no-print">
        <button onclick="window.print()"><i class="fa-solid fa-print"></i> طباعة / حفظ PDF</button>
    </div>

    <div class="doc">
        <div class="header">
            <div class="brand">
                <h1><?php echo app_h($appName); ?></h1>
                <p>إدارة الموارد البشرية والرواتب</p>
            </div>
            <div class="title">
                <h2>بيان صرف راتب</h2>
                <div class="ref"><?php echo app_h((string)$row['payroll_number']); ?></div>
            </div>
        </div>
        
        <div class="info-grid">
            <div class="info-item">
                <span class="label">اسم الموظف</span>
                <span class="val"><?php echo app_h($employeeName); ?></span>
            </div>
            <div class="info-item">
                <span class="label">المسمى الوظيفي</span>
                <span class="val"><?php echo app_h($row['role']); ?></span>
            </div>
            <div class="info-item" style="grid-column: 1 / -1;">
                <span class="label">عن شهر</span>
                <span class="val" dir="ltr"><?php echo app_h($row['month_year']); ?></span>
            </div>
        </div>
        
        <div class="salary-section">
            <h3><i class="fa-solid fa-money-check-dollar"></i> الاستحقاقات والاستقطاعات</h3>
            
            <div class="s-row basic">
                <span>الراتب الأساسي</span> 
                <span><?php echo number_format($row['basic_salary'], 2); ?></span>
            </div>
            
            <div class="s-row addition">
                <span class="txt-green"><i class="fa-solid fa-plus"></i> إضافي / مكافآت</span> 
                <span class="txt-green"><?php echo number_format($row['bonus'], 2); ?></span>
            </div>
            
            <div class="s-row deduction-loan">
                <span class="txt-gold"><i class="fa-solid fa-minus"></i> خصم سلف</span> 
                <span class="txt-gold"><?php echo number_format($loanDeduct, 2); ?></span>
            </div>
            
            <div class="s-row deduction">
                <span class="txt-red"><i class="fa-solid fa-minus"></i> خصومات أخرى</span> 
                <span class="txt-red"><?php echo number_format($otherDeduct, 2); ?></span>
            </div>
        </div>
        
        <div class="net-area">
            <span class="label">صافي الراتب المستحق (Net Pay)</span>
            <span class="amount"><?php echo number_format($row['net_salary'], 2); ?></span>
            <span class="currency">EGP</span>
        </div>

        <div class="status-area">
            <div class="status-item">
                <span class="s-label">حالة الصرف</span>
                <span class="s-val <?php echo $row['status'] == 'paid' ? 'status-paid' : 'status-pending'; ?>">
                    <?php echo $row['status'] == 'paid' ? '<i class="fa-solid fa-circle-check"></i> تم الصرف' : '<i class="fa-solid fa-circle-exclamation"></i> معلق / جزئي'; ?>
                </span>
            </div>
            <div class="status-item">
                <span class="s-label">المدفوع فعلياً</span>
                <span class="s-val" style="font-family: monospace;"><?php echo number_format($row['paid_amount'], 2); ?> EGP</span>
            </div>
        </div>
        
        <div class="signatures">
            <div class="sig-box">
                <div class="sig-line"></div>
                <div class="sig-title">توقيع المحاسب / الإدارة</div>
            </div>
            <div class="sig-box">
                <div class="sig-line"></div>
                <div class="sig-title">توقيع الموظف المستلم</div>
            </div>
        </div>
    </div>

</body>
</html>