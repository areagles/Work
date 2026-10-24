<?php
require 'auth.php';
require 'config.php';

app_ensure_payroll_schema($conn);

if (!app_user_can_any(['payroll.view', 'finance.view'])) {
    http_response_code(403);
    exit('⛔ غير مصرح لك بطباعة تقرير السلف.');
}

$appName = app_setting_get($conn, 'app_name', 'Arab Eagles');
$search = trim((string)($_GET['q'] ?? ''));
$employeeFilter = (int)($_GET['employee_id'] ?? 0);

$where = [];
if ($search !== '') {
    $safeSearch = $conn->real_escape_string($search);
    $where[] = "u.full_name LIKE '%{$safeSearch}%'";
}
if ($employeeFilter > 0) {
    $where[] = "u.id = {$employeeFilter}";
}
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$rows = [];
$summary = [
    'issued' => 0.0,
    'cash' => 0.0,
    'payroll' => 0.0,
    'outstanding' => 0.0,
    'salary_due' => 0.0,
    'payroll_net' => 0.0,
    'payroll_paid' => 0.0,
    'salary_cash' => 0.0,
];
$sql = "
    SELECT
        u.id,
        u.full_name,
        (SELECT IFNULL(SUM(amount),0) FROM financial_receipts WHERE employee_id = u.id AND type = 'out' AND category = 'loan') AS loans_issued,
        (SELECT IFNULL(SUM(amount),0) FROM financial_receipts WHERE employee_id = u.id AND type = 'in' AND category IN ('loan','loan_repayment')) AS repaid_cash,
        (SELECT IFNULL(SUM(amount),0) FROM financial_receipts WHERE employee_id = u.id AND type = 'out' AND category = 'salary') AS salary_cash_paid,
        (SELECT IFNULL(SUM(loan_deduction),0) FROM payroll_sheets WHERE employee_id = u.id) AS repaid_payroll,
        (SELECT IFNULL(SUM(net_salary),0) FROM payroll_sheets WHERE employee_id = u.id) AS payroll_net_total,
        (SELECT IFNULL(SUM(paid_amount),0) FROM payroll_sheets WHERE employee_id = u.id) AS payroll_paid_total,
        (SELECT IFNULL(SUM(CASE WHEN remaining_amount > 0 THEN remaining_amount ELSE 0 END),0) FROM payroll_sheets WHERE employee_id = u.id AND status != 'paid') AS salary_due,
        (SELECT COUNT(*) FROM payroll_sheets WHERE employee_id = u.id) AS payroll_count,
        (SELECT MAX(month_year) FROM payroll_sheets WHERE employee_id = u.id) AS last_payroll_month
    FROM users u
    {$whereSql}
    ORDER BY u.full_name ASC
";
$res = $conn->query($sql);
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $issued = (float)($row['loans_issued'] ?? 0);
        $cash = (float)($row['repaid_cash'] ?? 0);
        $salaryCash = (float)($row['salary_cash_paid'] ?? 0);
        $payroll = (float)($row['repaid_payroll'] ?? 0);
        $payrollNet = (float)($row['payroll_net_total'] ?? 0);
        $payrollPaid = (float)($row['payroll_paid_total'] ?? 0);
        $outstanding = max(0, $issued - $cash - $payroll);
        $salaryDue = (float)($row['salary_due'] ?? 0);
        if ($issued <= 0 && $cash <= 0 && $salaryCash <= 0 && $payroll <= 0 && $payrollNet <= 0 && $payrollPaid <= 0 && $salaryDue <= 0) {
            continue;
        }
        $row['outstanding'] = $outstanding;
        $rows[] = $row;
        $summary['issued'] += $issued;
        $summary['cash'] += $cash;
        $summary['salary_cash'] += $salaryCash;
        $summary['payroll'] += $payroll;
        $summary['payroll_net'] += $payrollNet;
        $summary['payroll_paid'] += $payrollPaid;
        $summary['outstanding'] += $outstanding;
        $summary['salary_due'] += $salaryDue;
    }
}
?>
<!DOCTYPE html>
<html dir="rtl" lang="ar">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>تقرير سلف الموظفين | <?php echo app_h($appName); ?></title>
    
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

        /* البطاقات الإحصائية */
        .cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
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
        
        .card.card-issued .value { color: #3498db; }
        .card.card-cash .value { color: var(--ae-green); }
        .card.card-payroll .value { color: var(--ae-gold-light); }
        .card.card-outstanding .value { color: var(--ae-red); }
        .card.card-due .value { color: #9b59b6; }

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
            text-align: right; 
            font-size: 0.95rem; 
            border-bottom: 1px solid var(--ae-border);
        }
        th { 
            background: #111; 
            color: var(--ae-gold); 
            font-weight: 800; 
            text-transform: uppercase; 
            border-bottom: 2px solid var(--ae-gold); 
            white-space: nowrap;
        }
        td { color: #ddd; vertical-align: middle; }
        tbody tr:last-child td { border-bottom: none; }
        tbody tr:hover { background: #1a1a1a; }
        
        .emp-name { font-weight: 700; color: #fff; font-size: 1.05rem; }
        .num-val { font-family: monospace; font-weight: 600; font-size: 1.05rem; }
        .text-center { text-align: center; }
        
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

            .cards { display: flex !important; flex-wrap: wrap !important; gap: 10px !important; margin-bottom: 20px !important; }
            .card { 
                background: transparent !important; 
                border: 1px solid #000 !important; 
                flex: 1 1 calc(20% - 10px); 
                border-radius: 0 !important; 
                padding: 10px !important; 
                box-shadow: none !important;
            }
            .card .label { color: #333 !important; font-size: 9pt !important; }
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
            .emp-name { color: #000 !important; }

            /* 🌟 إعدادات منع قطع البيانات بين الصفحات (Pagination Control) 🌟 */
            tr, .card {
                page-break-inside: avoid !important;
                break-inside: avoid !important;
            }
            thead { display: table-header-group !important; }
            tfoot { display: table-footer-group !important; }
        }

        /* التوافقية مع الجوال (Responsive) */
        @media (max-width: 768px) {
            .cards { grid-template-columns: repeat(2, 1fr); }
            .action-bar { width: 90%; justify-content: center; flex-wrap: wrap; border-radius: 20px; padding: 15px; }
            .ab-btn { flex: 1; justify-content: center; }
            th, td { padding: 10px; font-size: 0.85rem; }
            .emp-name { font-size: 0.95rem; }
        }
        @media (max-width: 480px) {
            .cards { grid-template-columns: 1fr; }
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
            <h1><i class="fa-solid fa-hand-holding-dollar"></i> تقرير سلف الموظفين والرواتب الشهرية</h1>
            <div class="app-name"><?php echo app_h($appName); ?></div>
        </div>

        <div class="cards">
            <div class="card">
                <div class="label"><i class="fa-solid fa-file-signature"></i> صافي الرواتب المعتمدة</div>
                <div class="value"><?php echo number_format($summary['payroll_net'], 2); ?></div>
            </div>
            <div class="card">
                <div class="label"><i class="fa-solid fa-wallet"></i> المدفوع داخل المسيرات</div>
                <div class="value"><?php echo number_format($summary['payroll_paid'], 2); ?></div>
            </div>
            <div class="card">
                <div class="label"><i class="fa-solid fa-money-check-dollar"></i> دفعات رواتب مسجلة</div>
                <div class="value"><?php echo number_format($summary['salary_cash'], 2); ?></div>
            </div>
            <div class="card card-issued">
                <div class="label"><i class="fa-solid fa-money-bill-transfer"></i> إجمالي السلف المصروفة</div>
                <div class="value"><?php echo number_format($summary['issued'], 2); ?></div>
            </div>
            <div class="card card-cash">
                <div class="label"><i class="fa-solid fa-money-bill-wave"></i> سداد نقدي</div>
                <div class="value"><?php echo number_format($summary['cash'], 2); ?></div>
            </div>
            <div class="card card-payroll">
                <div class="label"><i class="fa-solid fa-file-invoice-dollar"></i> سداد من الرواتب</div>
                <div class="value"><?php echo number_format($summary['payroll'], 2); ?></div>
            </div>
            <div class="card card-outstanding">
                <div class="label"><i class="fa-solid fa-scale-unbalanced"></i> الرصيد القائم المستحق</div>
                <div class="value"><?php echo number_format($summary['outstanding'], 2); ?></div>
            </div>
            <div class="card card-due">
                <div class="label"><i class="fa-solid fa-clock-rotate-left"></i> مستحقات رواتب مفتوحة</div>
                <div class="value"><?php echo number_format($summary['salary_due'], 2); ?></div>
            </div>
        </div>

        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>الموظف</th>
                        <th class="text-center">عدد المسيرات</th>
                        <th class="text-center">آخر شهر</th>
                        <th class="text-center">صافي الرواتب</th>
                        <th class="text-center">المدفوع بالمسيرات</th>
                        <th class="text-center">دفعات رواتب مسجلة</th>
                        <th class="text-center">السلف المصروفة</th>
                        <th class="text-center">السداد النقدي</th>
                        <th class="text-center">السداد من الرواتب</th>
                        <th class="text-center">الرصيد القائم</th>
                        <th class="text-center">مستحقات رواتب مفتوحة</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($rows): ?>
                        <?php foreach ($rows as $row): ?>
                            <tr>
                                <td class="emp-name"><i class="fa-regular fa-circle-user" style="color:var(--ae-gold); margin-left:5px;"></i> <?php echo app_h((string)$row['full_name']); ?></td>
                                <td class="text-center num-val" style="color:var(--ae-text-muted);"><?php echo number_format((float)$row['payroll_count']); ?></td>
                                <td class="text-center num-val" style="color:var(--ae-text-muted);"><?php echo app_h((string)($row['last_payroll_month'] ?? '-')); ?></td>
                                <td class="text-center num-val"><?php echo number_format((float)($row['payroll_net_total'] ?? 0), 2); ?></td>
                                <td class="text-center num-val" style="color:#16a085;"><?php echo number_format((float)($row['payroll_paid_total'] ?? 0), 2); ?></td>
                                <td class="text-center num-val" style="color:#8e44ad;"><?php echo number_format((float)($row['salary_cash_paid'] ?? 0), 2); ?></td>
                                <td class="text-center num-val" style="color:#3498db;"><?php echo number_format((float)$row['loans_issued'], 2); ?></td>
                                <td class="text-center num-val" style="color:var(--ae-green);"><?php echo number_format((float)$row['repaid_cash'], 2); ?></td>
                                <td class="text-center num-val" style="color:var(--ae-gold-light);"><?php echo number_format((float)$row['repaid_payroll'], 2); ?></td>
                                <td class="text-center num-val" style="color:var(--ae-red); font-weight:800;"><?php echo number_format((float)$row['outstanding'], 2); ?></td>
                                <td class="text-center num-val" style="color:#9b59b6;"><?php echo number_format((float)$row['salary_due'], 2); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="11" style="text-align:center; padding:40px; color:var(--ae-text-muted);">لا توجد بيانات رواتب أو سلف مطابقة لمعايير البحث الحالية.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

    </div>

    <div class="action-bar">
        <button class="ab-btn print" onclick="window.print()">
            <i class="fa-solid fa-print"></i> طباعة / حفظ PDF
        </button>
        <a class="ab-btn back" href="payroll_loans_report.php?<?php echo http_build_query($_GET); ?>">
            <i class="fa-solid fa-arrow-right"></i> الرجوع للبحث
        </a>
    </div>

</body>
</html>
