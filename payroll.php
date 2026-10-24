<?php
// payroll.php - أرشيف الرواتب
ob_start();
require 'auth.php'; require 'config.php'; require 'header.php';

app_ensure_payroll_schema($conn);

$canPayrollView = app_user_can_any(['payroll.view', 'finance.view']);
if (!$canPayrollView) {
    echo "<div class='container' style='margin-top:30px;'><div style='background:#1d0f0f;border:1px solid #5b2020;border-radius:12px;padding:20px;color:#ffb3b3;'>" . app_h(app_tr('⛔ لا تملك صلاحية عرض تقارير ومسيرات الرواتب.', '⛔ You do not have permission to view payroll reports.')) . "</div></div>";
    require 'footer.php';
    ob_end_flush();
    return;
}

$search = trim((string)($_GET['q'] ?? ''));
$employeeFilter = (int)($_GET['employee_id'] ?? 0);
$statusFilter = trim((string)($_GET['status'] ?? ''));
$monthFilter = trim((string)($_GET['month_year'] ?? ''));

$employeeOptions = [];
try {
    $empRes = $conn->query("SELECT id, full_name FROM users WHERE role = 'employee' OR role = 'staff' OR role = 'admin' ORDER BY full_name ASC");
    if ($empRes) {
        while ($emp = $empRes->fetch_assoc()) {
            $employeeOptions[] = [
                'id' => (int)($emp['id'] ?? 0),
                'name' => (string)($emp['full_name'] ?? ''),
            ];
        }
    }
} catch (Throwable $e) {
}

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

$summary = [
    'total' => 0,
    'paid' => 0,
    'pending' => 0,
    'partially_paid' => 0,
    'gross' => 0.0,
    'bonus' => 0.0,
    'deductions' => 0.0,
    'loan_deductions' => 0.0,
    'net' => 0.0,
    'paid_amount' => 0.0,
    'remaining' => 0.0,
    'loans_issued' => 0.0,
    'loans_repaid_cash' => 0.0,
    'loans_repaid_payroll' => 0.0,
    'loan_outstanding' => 0.0,
];
try {
    $summaryRes = $conn->query("
        SELECT
            COUNT(*) AS total_count,
            SUM(CASE WHEN status = 'paid' THEN 1 ELSE 0 END) AS paid_count,
            SUM(CASE WHEN status = 'partially_paid' THEN 1 ELSE 0 END) AS partially_paid_count,
            SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending_count,
            SUM(basic_salary) AS gross_total,
            SUM(bonus) AS bonus_total,
            SUM(deductions) AS deductions_total,
            SUM(loan_deduction) AS loan_deductions_total,
            SUM(net_salary) AS net_total,
            SUM(paid_amount) AS paid_total,
            SUM(remaining_amount) AS remaining_total
        FROM payroll_sheets p
        LEFT JOIN users u ON p.employee_id = u.id
        {$whereSql}
    ");
    if ($summaryRes) {
        $summaryRow = $summaryRes->fetch_assoc() ?: [];
        $summary['total'] = (int)($summaryRow['total_count'] ?? 0);
        $summary['paid'] = (int)($summaryRow['paid_count'] ?? 0);
        $summary['partially_paid'] = (int)($summaryRow['partially_paid_count'] ?? 0);
        $summary['pending'] = (int)($summaryRow['pending_count'] ?? 0);
        $summary['gross'] = (float)($summaryRow['gross_total'] ?? 0);
        $summary['bonus'] = (float)($summaryRow['bonus_total'] ?? 0);
        $summary['deductions'] = (float)($summaryRow['deductions_total'] ?? 0);
        $summary['loan_deductions'] = (float)($summaryRow['loan_deductions_total'] ?? 0);
        $summary['net'] = (float)($summaryRow['net_total'] ?? 0);
        $summary['paid_amount'] = (float)($summaryRow['paid_total'] ?? 0);
        $summary['remaining'] = (float)($summaryRow['remaining_total'] ?? 0);
    }
} catch (Throwable $e) {
}

try {
    $loanWhere = [];
    if ($employeeFilter > 0) {
        $loanWhere[] = "employee_id = {$employeeFilter}";
    }
    $loanWhereSql = $loanWhere ? (' AND ' . implode(' AND ', $loanWhere)) : '';

    $summary['loans_issued'] = (float)($conn->query("SELECT IFNULL(SUM(amount),0) FROM financial_receipts WHERE type = 'out' AND category = 'loan'{$loanWhereSql}")->fetch_row()[0] ?? 0);
    $summary['loans_repaid_cash'] = (float)($conn->query("SELECT IFNULL(SUM(amount),0) FROM financial_receipts WHERE type = 'in' AND category IN ('loan','loan_repayment'){$loanWhereSql}")->fetch_row()[0] ?? 0);
    $summary['loans_repaid_payroll'] = (float)($conn->query("SELECT IFNULL(SUM(loan_deduction),0) FROM payroll_sheets" . ($employeeFilter > 0 ? " WHERE employee_id = {$employeeFilter}" : ''))->fetch_row()[0] ?? 0);
    $summary['loan_outstanding'] = max(0, $summary['loans_issued'] - $summary['loans_repaid_cash'] - $summary['loans_repaid_payroll']);
} catch (Throwable $e) {
}

$payrollRows = [];
try {
    $sql = "SELECT p.*, u.full_name
            FROM payroll_sheets p
            LEFT JOIN users u ON p.employee_id = u.id
            {$whereSql}
            ORDER BY
                CASE p.status WHEN 'pending' THEN 1 WHEN 'partially_paid' THEN 2 ELSE 3 END,
                p.month_year DESC,
                p.id DESC";
    $res = $conn->query($sql);
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $employeeId = (int)($row['employee_id'] ?? 0);
            $row['outstanding_loan_now'] = $employeeId > 0 ? app_payroll_employee_outstanding_loan($conn, $employeeId) : 0.0;
            $payrollRows[] = $row;
        }
    }
} catch (Throwable $e) {
}
?>
<style>
    .payroll-shell { display: grid; gap: 18px; }
    .payroll-hero {
        display: grid;
        grid-template-columns: minmax(0, 1.15fr) minmax(320px, 0.85fr);
        gap: 18px;
        align-items: stretch;
    }
    .payroll-card {
        position: relative;
        overflow: hidden;
        background:
            linear-gradient(180deg, rgba(255,255,255,0.06), rgba(255,255,255,0.02)),
            radial-gradient(circle at top left, rgba(212,175,55,0.12), transparent 34%),
            rgba(18,18,18,0.88);
        border: 1px solid rgba(212,175,55,0.16);
        border-radius: 22px;
        padding: 22px;
        box-shadow: 0 18px 38px rgba(0,0,0,0.24);
        backdrop-filter: blur(14px);
    }
    .payroll-card::after {
        content: "";
        position: absolute;
        inset-inline-end: -56px;
        inset-block-start: -56px;
        width: 160px;
        height: 160px;
        border-radius: 50%;
        background: radial-gradient(circle, rgba(212,175,55,0.1), transparent 70%);
        pointer-events: none;
    }
    .payroll-eyebrow {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 6px 12px;
        border-radius: 999px;
        background: rgba(212,175,55,0.08);
        border: 1px solid rgba(212,175,55,0.24);
        color: #f0d684;
        font-size: .76rem;
        font-weight: 700;
        margin-bottom: 14px;
    }
    .payroll-title { margin: 0; color: #f7f1dc; font-size: 1.8rem; line-height: 1.3; }
    .payroll-subtitle { margin: 10px 0 0; color: #a8abb1; line-height: 1.8; max-width: 780px; }
    .payroll-actions { display: flex; align-items: center; justify-content: flex-start; gap: 10px; margin-top: 18px; flex-wrap: wrap; }
    .payroll-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-height: 46px;
        padding: 0 18px;
        border-radius: 14px;
        text-decoration: none;
        font-weight: 700;
        font-family: 'Cairo', sans-serif;
        border: 1px solid rgba(212,175,55,0.2);
        background: linear-gradient(140deg, var(--gold-primary), #9c7726);
        color: #16120a;
        box-shadow: 0 10px 20px rgba(212,175,55,0.18);
    }
    .payroll-btn.secondary {
        background: rgba(255,255,255,0.04);
        color: #e7e7e7;
        border-color: rgba(255,255,255,0.12);
        box-shadow: none;
    }
    .payroll-stats {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 12px;
    }
    .payroll-filters {
        display: grid;
        grid-template-columns: 1.4fr repeat(3, minmax(0, 1fr)) auto auto;
        gap: 12px;
        align-items: end;
    }
    .payroll-filter {
        display: flex;
        flex-direction: column;
        gap: 8px;
    }
    .payroll-filter label {
        color: #d7dbc3;
        font-size: .82rem;
        font-weight: 700;
    }
    .payroll-filter input,
    .payroll-filter select {
        width: 100%;
        min-height: 46px;
        border-radius: 14px;
        border: 1px solid rgba(212,175,55,0.18);
        background: #11161f;
        color: #f3f3f3;
        padding: 0 14px;
        font-family: 'Cairo', sans-serif;
    }
    .payroll-stat {
        border-radius: 18px;
        border: 1px solid rgba(255,255,255,0.08);
        background: rgba(255,255,255,0.035);
        padding: 18px;
        min-height: 112px;
    }
    .payroll-stat-label { color: #9ca0a8; font-size: .78rem; margin-bottom: 10px; }
    .payroll-stat-value { color: #f7f1dc; font-size: 1.7rem; font-weight: 800; line-height: 1; }
    .payroll-stat-note { color: #868b91; font-size: .73rem; margin-top: 10px; }
    .payroll-stat.paid .payroll-stat-value { color: #8be8b0; }
    .payroll-stat.pending .payroll-stat-value { color: #f0cd84; }
    .payroll-list-head {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 14px;
        margin-bottom: 18px;
    }
    .payroll-list-title { margin: 0; color: #f0d684; font-size: 1.05rem; }
    .payroll-list-subtitle { margin: 8px 0 0; color: #9ca0a8; font-size: .82rem; line-height: 1.7; }
    .payroll-chip {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 999px;
        padding: 7px 12px;
        background: rgba(212,175,55,0.08);
        border: 1px solid rgba(212,175,55,0.22);
        color: #f0d684;
        font-size: .76rem;
        font-weight: 700;
    }
    .payroll-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
        gap: 14px;
    }
    .payroll-report-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
        gap: 12px;
    }
    .payroll-entry {
        border-radius: 20px;
        border: 1px solid rgba(255,255,255,0.08);
        background:
            linear-gradient(180deg, rgba(255,255,255,0.05), rgba(255,255,255,0.02)),
            rgba(15,15,15,0.72);
        padding: 18px;
        backdrop-filter: blur(12px);
    }
    .payroll-entry-head {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 12px;
        margin-bottom: 14px;
    }
    .payroll-month {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 84px;
        height: 34px;
        padding: 0 12px;
        border-radius: 999px;
        background: rgba(255,255,255,0.08);
        color: #f3e2a3;
        font-size: .8rem;
        font-weight: 700;
        direction: ltr;
    }
    .payroll-name { margin: 0; color: #f7f1dc; font-size: 1rem; line-height: 1.5; }
    .payroll-status {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        padding: 6px 12px;
        border-radius: 999px;
        font-size: .76rem;
        font-weight: 700;
        border: 1px solid transparent;
    }
    .payroll-status.paid { color: #8be8b0; background: rgba(46,204,113,.12); border-color: rgba(46,204,113,.32); }
    .payroll-status.pending { color: #f0cd84; background: rgba(241,196,15,.12); border-color: rgba(241,196,15,.28); }
    .payroll-status.partial { color: #8ac7ff; background: rgba(52,152,219,.12); border-color: rgba(52,152,219,.28); }
    .payroll-metrics {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 10px;
        margin-bottom: 14px;
    }
    .payroll-metric {
        border-radius: 14px;
        border: 1px solid rgba(255,255,255,0.06);
        background: rgba(255,255,255,0.03);
        padding: 12px;
    }
    .payroll-metric-label { color: #9ca0a8; font-size: .72rem; margin-bottom: 6px; }
    .payroll-metric-value { color: #f0f0f0; font-size: .88rem; font-weight: 700; }
    .payroll-metric-value.net { color: #f0d684; }
    .payroll-metric-value.add { color: #8be8b0; }
    .payroll-metric-value.warn { color: #f0cd84; }
    .payroll-metric-value.deduct { color: #f1a4a0; }
    .payroll-entry-footer {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        padding-top: 14px;
        border-top: 1px solid rgba(255,255,255,0.08);
        flex-wrap: wrap;
    }
    .payroll-note { color: #8d939b; font-size: .75rem; line-height: 1.6; }
    .payroll-empty {
        border-radius: 20px;
        padding: 36px 18px;
        border: 1px dashed rgba(255,255,255,0.12);
        background: rgba(255,255,255,0.025);
        color: #9ca0a8;
        text-align: center;
    }
    @media (max-width: 1024px) {
        .payroll-hero { grid-template-columns: 1fr; }
    }
    @media (max-width: 768px) {
        .payroll-stats,
        .payroll-metrics,
        .payroll-filters,
        .payroll-report-grid { grid-template-columns: 1fr; }
        .payroll-entry-footer,
        .payroll-list-head { flex-direction: column; align-items: stretch; }
        .payroll-btn { width: 100%; }
    }
</style>
<div class="container">
    <div class="payroll-shell">
        <section class="payroll-hero">
            <div class="payroll-card">
                <div class="payroll-eyebrow">إدارة الرواتب</div>
                <h2 class="payroll-title">سجل الرواتب الشهرية</h2>
                <p class="payroll-subtitle">عرض موحد لمسيرات الرواتب مع صافي الاستحقاق والخصومات وحالة الصرف ورصيد السلف الحالي، مع تقرير تشغيلي واضح لمتابعة الخصم التلقائي والصرف.</p>
                <div class="payroll-actions">
                    <a href="add_payroll.php" class="payroll-btn">إعداد راتب جديد</a>
                    <a href="finance.php?def_type=out&def_cat=salary" class="payroll-btn secondary">فتح شاشة الصرف</a>
                    <?php if (app_is_super_user() || ((string)($_SESSION['role'] ?? '') === 'admin')): ?>
                        <a href="users.php" class="payroll-btn secondary">إدارة الموظفين</a>
                    <?php endif; ?>
                    <a href="salaries.php" class="payroll-btn secondary">الجدول الكلاسيكي</a>
                    <a href="payroll_loans_report.php?<?php echo http_build_query($_GET); ?>" class="payroll-btn secondary">تقرير السلف</a>
                    <a href="print_payroll_report.php?<?php echo http_build_query($_GET); ?>" target="_blank" class="payroll-btn secondary">طباعة تقرير الرواتب</a>
                </div>
            </div>
            <div class="payroll-stats">
                <div class="payroll-card payroll-stat">
                    <div class="payroll-stat-label">إجمالي المسيرات</div>
                    <div class="payroll-stat-value"><?php echo (int)$summary['total']; ?></div>
                    <div class="payroll-stat-note">عدد السجلات المحفوظة في الأرشيف الشهري</div>
                </div>
                <div class="payroll-card payroll-stat paid">
                    <div class="payroll-stat-label">المسيرات المصروفة</div>
                    <div class="payroll-stat-value"><?php echo (int)$summary['paid']; ?></div>
                    <div class="payroll-stat-note">تم استكمال صرفها بالكامل</div>
                </div>
                <div class="payroll-card payroll-stat">
                    <div class="payroll-stat-label">المسيرات الجزئية</div>
                    <div class="payroll-stat-value"><?php echo (int)$summary['partially_paid']; ?></div>
                    <div class="payroll-stat-note">صُرف منها جزء وما زال عليها رصيد</div>
                </div>
                <div class="payroll-card payroll-stat pending">
                    <div class="payroll-stat-label">المسيرات المعلقة</div>
                    <div class="payroll-stat-value"><?php echo (int)$summary['pending']; ?></div>
                    <div class="payroll-stat-note">تحتاج إلى متابعة أو صرف</div>
                </div>
                <div class="payroll-card payroll-stat">
                    <div class="payroll-stat-label">المتبقي للصرف</div>
                    <div class="payroll-stat-value"><?php echo number_format($summary['remaining'], 2); ?></div>
                    <div class="payroll-stat-note">القيمة المتبقية من الرواتب الحالية</div>
                </div>
            </div>
        </section>

        <section class="payroll-card">
            <div class="payroll-list-head">
                <div>
                    <h3 class="payroll-list-title">فلاتر التقرير</h3>
                    <p class="payroll-list-subtitle">صفِّ الأرشيف حسب الموظف أو الحالة أو الشهر مع بقاء حسابات الرواتب والسلف مرتبطة بنفس التصفية.</p>
                </div>
            </div>
            <form method="GET" class="payroll-filters">
                <div class="payroll-filter">
                    <label for="q">بحث</label>
                    <input type="text" id="q" name="q" value="<?php echo app_h($search); ?>" placeholder="اسم الموظف أو الشهر">
                </div>
                <div class="payroll-filter">
                    <label for="employee_id">الموظف</label>
                    <select id="employee_id" name="employee_id">
                        <option value="">كل الموظفين</option>
                        <?php foreach ($employeeOptions as $emp): ?>
                            <option value="<?php echo (int)$emp['id']; ?>" <?php echo $employeeFilter === (int)$emp['id'] ? 'selected' : ''; ?>><?php echo app_h($emp['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="payroll-filter">
                    <label for="status">الحالة</label>
                    <select id="status" name="status">
                        <option value="">كل الحالات</option>
                        <option value="pending" <?php echo $statusFilter === 'pending' ? 'selected' : ''; ?>>معلق</option>
                        <option value="partially_paid" <?php echo $statusFilter === 'partially_paid' ? 'selected' : ''; ?>>جزئي</option>
                        <option value="paid" <?php echo $statusFilter === 'paid' ? 'selected' : ''; ?>>مدفوع</option>
                    </select>
                </div>
                <div class="payroll-filter">
                    <label for="month_year">الشهر</label>
                    <input type="month" id="month_year" name="month_year" value="<?php echo app_h($monthFilter); ?>">
                </div>
                <button class="payroll-btn" type="submit">تطبيق</button>
                <a href="payroll.php" class="payroll-btn secondary">إعادة ضبط</a>
            </form>
        </section>

        <section class="payroll-card">
            <div class="payroll-list-head">
                <div>
                    <h3 class="payroll-list-title">تقرير الرواتب والسلف</h3>
                    <p class="payroll-list-subtitle">ملخص تنفيذي للصافي، المدفوع، المتبقي، وإجمالي السلف والخصم التلقائي داخل المسيرات الحالية.</p>
                </div>
                <div class="payroll-chip">رصيد السلف القائم: <?php echo number_format($summary['loan_outstanding'], 2); ?></div>
            </div>
            <div class="payroll-report-grid">
                <div class="payroll-metric"><div class="payroll-metric-label">إجمالي الأساسي</div><div class="payroll-metric-value"><?php echo number_format($summary['gross'], 2); ?></div></div>
                <div class="payroll-metric"><div class="payroll-metric-label">إجمالي الإضافي</div><div class="payroll-metric-value add"><?php echo number_format($summary['bonus'], 2); ?></div></div>
                <div class="payroll-metric"><div class="payroll-metric-label">خصومات أخرى</div><div class="payroll-metric-value deduct"><?php echo number_format(max(0, $summary['deductions'] - $summary['loan_deductions']), 2); ?></div></div>
                <div class="payroll-metric"><div class="payroll-metric-label">خصم السلف من المسيرات</div><div class="payroll-metric-value warn"><?php echo number_format($summary['loan_deductions'], 2); ?></div></div>
                <div class="payroll-metric"><div class="payroll-metric-label">إجمالي الصافي</div><div class="payroll-metric-value net"><?php echo number_format($summary['net'], 2); ?></div></div>
                <div class="payroll-metric"><div class="payroll-metric-label">المدفوع فعليًا</div><div class="payroll-metric-value add"><?php echo number_format($summary['paid_amount'], 2); ?></div></div>
                <div class="payroll-metric"><div class="payroll-metric-label">المتبقي للصرف</div><div class="payroll-metric-value"><?php echo number_format($summary['remaining'], 2); ?></div></div>
                <div class="payroll-metric"><div class="payroll-metric-label">إجمالي السلف المصروفة</div><div class="payroll-metric-value"><?php echo number_format($summary['loans_issued'], 2); ?></div></div>
                <div class="payroll-metric"><div class="payroll-metric-label">سداد نقدي للسلف</div><div class="payroll-metric-value add"><?php echo number_format($summary['loans_repaid_cash'], 2); ?></div></div>
                <div class="payroll-metric"><div class="payroll-metric-label">سداد سلف من الرواتب</div><div class="payroll-metric-value warn"><?php echo number_format($summary['loans_repaid_payroll'], 2); ?></div></div>
            </div>
        </section>

        <section class="payroll-card">
            <div class="payroll-list-head">
                <div>
                    <h3 class="payroll-list-title">الأرشيف التشغيلي</h3>
                    <p class="payroll-list-subtitle">كل مسير يظهر كبطاقة مستقلة مع حالة الصرف وخصم السلف والرصيد الحالي للسلف، لتسهيل المراجعة دون ازدحام الجدول التقليدي.</p>
                </div>
                <div class="payroll-chip">عدد النتائج: <?php echo count($payrollRows); ?></div>
            </div>

            <div class="payroll-grid">
                <?php
                if ($payrollRows):
                    foreach ($payrollRows as $row):
                        $loanDeduct = (float)($row['loan_deduction'] ?? 0);
                        $otherDeduct = max(0, (float)$row['deductions'] - $loanDeduct);
                        $remaining = (float)($row['remaining_amount'] ?? 0);
                        $status = (string)($row['status'] ?? 'pending');
                        $statusLabel = $status === 'paid' ? 'تم الصرف' : ($status === 'partially_paid' ? 'صرف جزئي' : 'بانتظار الصرف');
                        $statusClass = $status === 'paid' ? 'paid' : ($status === 'partially_paid' ? 'partial' : 'pending');
                ?>
                <article class="payroll-entry">
                    <div class="payroll-entry-head">
                        <div>
                            <h3 class="payroll-name"><?php echo app_h(app_payroll_employee_label($row)); ?></h3>
                        </div>
                        <div class="payroll-month"><?php echo app_h((string)$row['month_year']); ?></div>
                    </div>

                    <div class="user-card-tags" style="margin-bottom:14px;">
                        <span class="payroll-status <?php echo $statusClass; ?>">
                            <?php echo $statusLabel; ?>
                        </span>
                        <?php if ($loanDeduct > 0): ?>
                            <span class="payroll-chip">خصم تلقائي/سلف: <?php echo number_format($loanDeduct, 2); ?></span>
                        <?php endif; ?>
                    </div>

                    <div class="payroll-metrics">
                        <div class="payroll-metric">
                            <div class="payroll-metric-label">الأساسي</div>
                            <div class="payroll-metric-value"><?php echo number_format((float)$row['basic_salary'], 2); ?></div>
                        </div>
                        <div class="payroll-metric">
                            <div class="payroll-metric-label">الإضافي</div>
                            <div class="payroll-metric-value add"><?php echo number_format((float)$row['bonus'], 2); ?></div>
                        </div>
                        <div class="payroll-metric">
                            <div class="payroll-metric-label">خصم السلف</div>
                            <div class="payroll-metric-value warn"><?php echo number_format($loanDeduct, 2); ?></div>
                        </div>
                        <div class="payroll-metric">
                            <div class="payroll-metric-label">خصومات أخرى</div>
                            <div class="payroll-metric-value deduct"><?php echo number_format($otherDeduct, 2); ?></div>
                        </div>
                        <div class="payroll-metric">
                            <div class="payroll-metric-label">الصافي</div>
                            <div class="payroll-metric-value net"><?php echo number_format((float)$row['net_salary'], 2); ?></div>
                        </div>
                        <div class="payroll-metric">
                            <div class="payroll-metric-label">المتبقي</div>
                            <div class="payroll-metric-value"><?php echo number_format($remaining, 2); ?></div>
                        </div>
                        <div class="payroll-metric">
                            <div class="payroll-metric-label">المدفوع</div>
                            <div class="payroll-metric-value add"><?php echo number_format((float)($row['paid_amount'] ?? 0), 2); ?></div>
                        </div>
                        <div class="payroll-metric">
                            <div class="payroll-metric-label">رصيد السلف الحالي</div>
                            <div class="payroll-metric-value warn"><?php echo number_format((float)($row['outstanding_loan_now'] ?? 0), 2); ?></div>
                        </div>
                    </div>

                    <div class="payroll-entry-footer">
                        <div class="payroll-note">
                            <?php
                            if ($status === 'paid') {
                                echo 'تم إغلاق هذا المسير ماليًا بالكامل.';
                            } elseif ($status === 'partially_paid') {
                                echo 'تم صرف جزء من المسير، وما زال عليه رصيد مفتوح يحتاج متابعة.';
                            } else {
                                echo 'المسير ما زال مفتوحًا ويحتاج إلى صرف الرصيد المتبقي.';
                            }
                            ?>
                        </div>
                        <div>
                            <?php if ($status !== 'paid'): ?>
                                <?php $salaryDesc = rawurlencode('دفعة راتب شهر ' . (string)$row['month_year'] . ' للموظف ' . app_payroll_employee_label($row)); ?>
                                <a href="finance.php?def_type=out&def_cat=salary&emp_id=<?php echo (int)$row['employee_id']; ?>&payroll_id=<?php echo (int)$row['id']; ?>&amount=<?php echo (float)$remaining; ?>&desc=<?php echo $salaryDesc; ?>" class="payroll-btn">اصرف الآن</a>
                            <?php endif; ?>
                            <a href="edit_payroll.php?id=<?php echo (int)$row['id']; ?>" class="payroll-btn secondary">تعديل</a>
                            <a href="print_salary.php?id=<?php echo (int)$row['id']; ?>" target="_blank" class="payroll-btn secondary">طباعة</a>
                        </div>
                    </div>
                </article>
                <?php endforeach; else: ?>
                <div class="payroll-empty">لا توجد بيانات رواتب حاليًا.</div>
                <?php endif; ?>
            </div>
        </section>
    </div>
</div>
<?php include 'footer.php'; ob_end_flush(); ?>
