<?php
require 'auth.php';
require 'config.php';

app_ensure_payroll_schema($conn);

if (!app_user_can_any(['payroll.view', 'finance.view'])) {
    http_response_code(403);
    exit('⛔ غير مصرح لك بطباعة تقرير الرواتب.');
}

$appName = app_setting_get($conn, 'app_name', 'Arab Eagles');
$search = trim((string)($_GET['q'] ?? ''));
$employeeFilter = (int)($_GET['employee_id'] ?? 0);
$statusFilter = trim((string)($_GET['status'] ?? ''));
$monthFilter = trim((string)($_GET['month_year'] ?? ''));

$where = [];
if ($search !== '') {
    $safeSearch = $conn->real_escape_string($search);
    $where[] = "(u.full_name LIKE '%{$safeSearch}%' OR p.employee_name_snapshot LIKE '%{$safeSearch}%' OR p.month_year LIKE '%{$safeSearch}%')";
}
if ($employeeFilter > 0) {
    $where[] = "p.employee_id = {$employeeFilter}";
}
if ($statusFilter !== '' && in_array($statusFilter, ['pending', 'partially_paid', 'paid'], true)) {
    $safeStatus = $conn->real_escape_string($statusFilter);
    $where[] = "p.status = '{$safeStatus}'";
}
if ($monthFilter !== '') {
    $safeMonth = $conn->real_escape_string($monthFilter);
    $where[] = "p.month_year = '{$safeMonth}'";
}
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$rows = [];
$summary = [
    'gross' => 0.0,
    'bonus' => 0.0,
    'other_deductions' => 0.0,
    'loan_deductions' => 0.0,
    'net' => 0.0,
    'paid' => 0.0,
    'remaining' => 0.0,
];

$sql = "SELECT p.*, u.full_name
        FROM payroll_sheets p
        LEFT JOIN users u ON p.employee_id = u.id
        {$whereSql}
        ORDER BY p.month_year DESC, p.id DESC";
$res = $conn->query($sql);
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $loanDeduct = (float)($row['loan_deduction'] ?? 0);
        $otherDeduct = max(0, (float)($row['deductions'] ?? 0) - $loanDeduct);
        $row['employee_label'] = app_payroll_employee_label($row);
        $row['loan_deduction_value'] = $loanDeduct;
        $row['other_deduction_value'] = $otherDeduct;
        $rows[] = $row;

        $summary['gross'] += (float)($row['basic_salary'] ?? 0);
        $summary['bonus'] += (float)($row['bonus'] ?? 0);
        $summary['other_deductions'] += $otherDeduct;
        $summary['loan_deductions'] += $loanDeduct;
        $summary['net'] += (float)($row['net_salary'] ?? 0);
        $summary['paid'] += (float)($row['paid_amount'] ?? 0);
        $summary['remaining'] += (float)($row['remaining_amount'] ?? 0);
    }
}
?>
<!DOCTYPE html>
<html dir="rtl" lang="ar">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>تقرير مسيرات الرواتب | <?php echo app_h($appName); ?></title>
    
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;800;900&display=swap" rel="stylesheet">
    
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
            --ae-blue: #3498db;
        }

        body {
            font-family: 'Cairo', sans-serif;
            background: var(--ae-dark);
            color: var(--ae-text);
            padding: 30px 15px 120px 15px;
            line-height: 1.6;
            margin: 0;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
        }

        /* الترويسة */
        .head { text-align: center; margin-bottom: 30px; }
        .head h1 { color: var(--ae-gold); margin: 0 0 5px 0; font-size: 2.2rem; font-weight: 900; }
        .head .app-name { font-size: 1.1rem; color: var(--ae-text-muted); font-weight: 600; letter-spacing: 1px; }

        /* شريط المعايير (Filters Meta) */
        .meta {
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            gap: 15px;
            background: var(--ae-card);
            border: 1px solid var(--ae-gold);
            border-radius: 12px;
            padding: 15px 20px;
            margin-bottom: 30px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.3);
        }
        .meta div { font-size: 0.95rem; font-weight: 600; color: #fff; }
        .meta div span { color: var(--ae-gold); margin-inline-end: 5px; font-weight: 800; }

        /* البطاقات الإحصائية */
        .cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 15px;
            margin-bottom: 35px;
        }
        .card {
            background: var(--ae-card);
            border: 1px solid var(--ae-border);
            border-radius: 12px;
            padding: 20px;
            text-align: center;
            transition: transform 0.3s ease, border-color 0.3s ease;
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
        }
        .card:hover { border-color: var(--ae-gold); transform: translateY(-3px); }
        .card .label { font-size: 0.95rem; color: var(--ae-text-muted); margin-bottom: 10px; font-weight: 700; display: flex; align-items: center; justify-content: center; gap: 8px; }
        .card .value { font-size: 1.8rem; font-weight: 900; color: #fff; font-family: monospace; }
        
        .card.card-gross .value { color: var(--ae-text); }
        .card.card-net .value { color: var(--ae-gold); }
        .card.card-paid .value { color: var(--ae-green); }
        .card.card-remaining .value { color: var(--ae-red); }

        /* الجداول */
        .table-wrapper {
            background: var(--ae-card);
            border: 1px solid var(--ae-border);
            border-radius: 12px;
            overflow-x: auto;
            box-shadow: 0 10px 30px rgba(0,0,0,0.3);
        }
        table { width: 100%; border-collapse: collapse; }
        th, td { 
            padding: 15px 18px; 
            text-align: center; 
            font-size: 0.95rem; 
            border-bottom: 1px solid var(--ae-border);
        }
        th { 
            background: #111; 
            color: var(--ae-gold); 
            font-weight: 800; 
            border-bottom: 2px solid var(--ae-gold); 
            white-space: nowrap;
        }
        td { color: #ddd; vertical-align: middle; }
        td.text-right { text-align: right; }
        tbody tr:last-child td { border-bottom: none; }
        tbody tr:hover { background: #1a1a1a; }
        
        .emp-name { font-weight: 700; color: #fff; font-size: 1rem; white-space: nowrap; }
        .num-val { font-family: monospace; font-weight: 600; font-size: 1.05rem; }
        .muted-row { color: var(--ae-text-muted) !important; padding: 40px !important; }

        /* الألوان داخل الجدول */
        .val-basic { color: #fff; }
        .val-bonus { color: var(--ae-blue); }
        .val-deduct { color: var(--ae-gold-light); }
        .val-other { color: var(--ae-red); }
        .val-net { color: var(--ae-gold); font-weight: 800; }
        .val-paid { color: var(--ae-green); font-weight: 800; }
        .val-rem { color: var(--ae-red); font-weight: 800; }
        
        .status-badge {
            padding: 4px 10px;
            border-radius: 4px;
            font-size: 0.8rem;
            font-weight: 800;
        }
        .status-paid { background: rgba(46, 204, 113, 0.15); color: var(--ae-green); border: 1px solid var(--ae-green); }
        .status-partially_paid { background: rgba(241, 196, 15, 0.15); color: var(--ae-gold-light); border: 1px solid var(--ae-gold-light); }
        .status-pending { background: rgba(231, 76, 60, 0.15); color: var(--ae-red); border: 1px solid var(--ae-red); }

        /* شريط الإجراءات العائم (Action Bar) */
        .action-bar {
            position: fixed;
            bottom: 25px;
            left: 50%;
            transform: translateX(-50%);
            background: rgba(15, 15, 15, 0.95);
            border: 1px solid var(--ae-gold);
            border-radius: 50px;
            padding: 10px 15px;
            display: flex;
            gap: 15px;
            z-index: 999;
            backdrop-filter: blur(10px);
            box-shadow: 0 10px 30px rgba(0,0,0,0.7);
        }
        .ab-btn {
            border: none;
            border-radius: 50px;
            padding: 12px 25px;
            font-family: 'Cairo', sans-serif;
            font-weight: 800;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: 0.95rem;
            transition: all 0.3s ease;
            white-space: nowrap;
        }
        .ab-btn.print { background: var(--ae-gold); color: #000; }
        .ab-btn.print:hover { background: var(--ae-gold-light); transform: translateY(-2px); }
        .ab-btn.back { background: #222; color: #fff; border: 1px solid #444; }
        .ab-btn.back:hover { border-color: var(--ae-gold); color: var(--ae-gold); }

        /* ========================================================= */
        /* إعدادات الطباعة (Print Mode) - أبيض وأسود رسمي لحفظ الحبر */
        /* ========================================================= */
        @media print {
            @page { size: A4 landscape; margin: 15mm; }
            body { 
                background: #fff !important; 
                color: #000 !important; 
                padding: 0 !important; 
                font-size: 11pt !important; 
            }
            .container { max-width: 100% !important; margin: 0 !important; }
            .action-bar { display: none !important; }

            .head h1 { color: #000 !important; font-size: 20pt !important; border-bottom: 2px solid #000; padding-bottom: 10px; display: inline-block; margin-bottom: 5px; }
            .head .app-name { color: #555 !important; }

            .meta { background: transparent !important; border: 1px solid #000 !important; border-radius: 0 !important; box-shadow: none !important; }
            .meta div { color: #000 !important; }
            .meta div span { color: #000 !important; }

            .cards { display: flex !important; flex-wrap: wrap !important; gap: 10px !important; margin-bottom: 20px !important; }
            .card { 
                background: transparent !important; 
                border: 1px solid #000 !important; 
                flex: 1 1 calc(25% - 10px); 
                border-radius: 0 !important; 
                padding: 10px !important; 
                box-shadow: none !important;
            }
            .card .label { color: #333 !important; font-size: 10pt !important; }
            .card .value { color: #000 !important; font-size: 14pt !important; }

            .table-wrapper { background: transparent !important; border: none !important; box-shadow: none !important; overflow: visible !important; }
            table { border-collapse: collapse !important; border: 1px solid #000 !important; width: 100% !important; }
            th, td { 
                border: 1px solid #000 !important; 
                padding: 8px !important; 
                color: #000 !important; 
            }
            th { 
                background: #f0f0f0 !important; 
                -webkit-print-color-adjust: exact; 
                print-color-adjust: exact; 
                border-bottom: 2px solid #000 !important; 
            }
            .emp-name, .val-basic, .val-bonus, .val-deduct, .val-other, .val-net, .val-paid, .val-rem { color: #000 !important; }
            .status-badge { background: transparent !important; border: none !important; color: #000 !important; padding: 0 !important; }

            /* 🌟 إعدادات منع قطع البيانات بين الصفحات (Pagination Control) 🌟 */
            tr, .card, .meta {
                page-break-inside: avoid !important;
                break-inside: avoid !important;
            }
            thead { display: table-header-group !important; }
            tfoot { display: table-footer-group !important; }
        }

        /* التوافقية مع الجوال (Responsive) */
        @media (max-width: 768px) {
            .cards { grid-template-columns: repeat(2, 1fr); }
            .action-bar { width: 95%; justify-content: center; flex-wrap: wrap; border-radius: 20px; padding: 15px; }
            .ab-btn { flex: 1; justify-content: center; }
            th, td { padding: 10px; font-size: 0.85rem; }
            .emp-name { font-size: 0.95rem; }
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
        
        <div class="head">
            <h1><i class="fa-solid fa-file-invoice-dollar"></i> تقرير مسيرات الرواتب</h1>
            <div class="app-name"><?php echo app_h($appName); ?></div>
        </div>

        <div class="meta">
            <div><span><i class="fa-solid fa-magnifying-glass"></i> البحث:</span> <?php echo app_h($search !== '' ? $search : 'الكل'); ?></div>
            <div><span><i class="fa-solid fa-filter"></i> الحالة:</span> <?php echo app_h($statusFilter !== '' ? $statusFilter : 'الكل'); ?></div>
            <div><span><i class="fa-regular fa-calendar-days"></i> الشهر:</span> <?php echo app_h($monthFilter !== '' ? $monthFilter : 'كل الشهور'); ?></div>
            <div><span><i class="fa-solid fa-users"></i> عدد المسيرات:</span> <?php echo count($rows); ?></div>
        </div>

        <div class="cards">
            <div class="card card-gross">
                <div class="label"><i class="fa-solid fa-sack-dollar"></i> إجمالي الأساسي</div>
                <div class="value"><?php echo number_format($summary['gross'], 2); ?></div>
            </div>
            <div class="card card-net">
                <div class="label"><i class="fa-solid fa-hand-holding-dollar"></i> إجمالي الصافي المستحق</div>
                <div class="value"><?php echo number_format($summary['net'], 2); ?></div>
            </div>
            <div class="card card-paid">
                <div class="label"><i class="fa-solid fa-money-bill-wave"></i> إجمالي المدفوع الفعلي</div>
                <div class="value"><?php echo number_format($summary['paid'], 2); ?></div>
            </div>
            <div class="card card-remaining">
                <div class="label"><i class="fa-solid fa-scale-unbalanced"></i> إجمالي المتبقي (المديونية)</div>
                <div class="value"><?php echo number_format($summary['remaining'], 2); ?></div>
            </div>
        </div>

        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>الشهر</th>
                        <th class="text-right">الموظف</th>
                        <th>الأساسي</th>
                        <th>الإضافي</th>
                        <th>خصم السلف</th>
                        <th>خصومات أخرى</th>
                        <th>الصافي</th>
                        <th>المدفوع</th>
                        <th>المتبقي</th>
                        <th>الحالة</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($rows): ?>
                        <?php foreach ($rows as $row): 
                            // تحويل حالة الراتب إلى نص وكلاس مفهوم
                            $statusClass = 'status-pending';
                            $statusText = 'معلق';
                            if($row['status'] === 'paid') { $statusClass = 'status-paid'; $statusText = 'تم الصرف'; }
                            elseif($row['status'] === 'partially_paid') { $statusClass = 'status-partially_paid'; $statusText = 'صرف جزئي'; }
                        ?>
                            <tr>
                                <td style="direction:ltr; font-weight:bold;"><?php echo app_h((string)$row['month_year']); ?></td>
                                <td class="text-right emp-name"><i class="fa-regular fa-circle-user" style="color:var(--ae-gold); margin-left:5px;"></i> <?php echo app_h((string)$row['employee_label']); ?></td>
                                <td class="num-val val-basic"><?php echo number_format((float)$row['basic_salary'], 2); ?></td>
                                <td class="num-val val-bonus"><?php echo number_format((float)$row['bonus'], 2); ?></td>
                                <td class="num-val val-deduct"><?php echo number_format((float)$row['loan_deduction_value'], 2); ?></td>
                                <td class="num-val val-other"><?php echo number_format((float)$row['other_deduction_value'], 2); ?></td>
                                <td class="num-val val-net"><?php echo number_format((float)$row['net_salary'], 2); ?></td>
                                <td class="num-val val-paid"><?php echo number_format((float)$row['paid_amount'], 2); ?></td>
                                <td class="num-val val-rem"><?php echo number_format((float)$row['remaining_amount'], 2); ?></td>
                                <td><span class="status-badge <?php echo $statusClass; ?>"><?php echo $statusText; ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="10" class="muted-row text-center">لا توجد بيانات مطابقة لمعايير البحث الحالية.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

    </div>

    <div class="action-bar">
        <button class="ab-btn print" onclick="window.print()">
            <i class="fa-solid fa-print"></i> طباعة / حفظ PDF
        </button>
        <a class="ab-btn back" href="payroll.php?<?php echo http_build_query($_GET); ?>">
            <i class="fa-solid fa-arrow-right"></i> الرجوع للبحث
        </a>
    </div>

</body>
</html>