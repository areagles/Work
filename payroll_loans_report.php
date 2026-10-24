<?php
ob_start();
require 'auth.php';
require 'config.php';
require 'header.php';

app_ensure_payroll_schema($conn);

if (!app_user_can_any(['payroll.view', 'finance.view'])) {
    http_response_code(403);
    echo "<div class='container' style='margin-top:30px;'><div style='background:#1d0f0f;border:1px solid #5b2020;border-radius:12px;padding:20px;color:#ffb3b3;'>⛔ غير مصرح لك بالدخول إلى تقرير سلف الموظفين.</div></div>";
    require 'footer.php';
    exit;
}

$search = trim((string)($_GET['q'] ?? ''));
$employeeFilter = (int)($_GET['employee_id'] ?? 0);

$employeeOptions = [];
$employeeRes = $conn->query("SELECT id, full_name FROM users ORDER BY full_name ASC");
if ($employeeRes) {
    while ($emp = $employeeRes->fetch_assoc()) {
        $employeeOptions[] = ['id' => (int)$emp['id'], 'name' => (string)$emp['full_name']];
    }
}

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
<style>
    .loan-shell { display:grid; gap:18px; }
    .loan-card { background:#141414; border:1px solid #2a2a2a; border-radius:18px; padding:20px; }
    .loan-head { display:flex; justify-content:space-between; align-items:flex-start; gap:12px; flex-wrap:wrap; margin-bottom:18px; }
    .loan-title { margin:0; color:#d4af37; }
    .loan-subtitle { margin:8px 0 0; color:#9aa0a8; }
    .loan-filters { display:grid; grid-template-columns:1.2fr 1fr auto auto; gap:12px; align-items:end; }
    .loan-filters label { display:block; color:#ccc; margin-bottom:6px; font-size:.85rem; }
    .loan-filters input, .loan-filters select { width:100%; min-height:44px; border-radius:12px; border:1px solid #333; background:#0e0e0e; color:#fff; padding:0 12px; }
    .loan-btn { display:inline-flex; align-items:center; justify-content:center; min-height:44px; padding:0 16px; border-radius:12px; text-decoration:none; border:1px solid rgba(212,175,55,.2); background:linear-gradient(140deg,#d4af37,#9c7726); color:#16120a; font-weight:800; }
    .loan-btn.secondary { background:rgba(255,255,255,.04); color:#eee; border-color:rgba(255,255,255,.14); }
    .loan-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(180px,1fr)); gap:12px; margin-top:14px; }
    .loan-kpi { border:1px solid #2b2b2b; border-radius:14px; background:#101010; padding:16px; }
    .loan-kpi .label { color:#999; font-size:.78rem; margin-bottom:8px; }
    .loan-kpi .value { color:#fff; font-weight:900; font-size:1.25rem; }
    .loan-table { overflow:auto; }
    table { width:100%; border-collapse:collapse; }
    th, td { padding:12px; border-bottom:1px solid #262626; text-align:right; }
    th { color:#d4af37; background:rgba(212,175,55,.06); }
    @media (max-width: 768px) { .loan-filters, .loan-grid { grid-template-columns:1fr; } }
</style>
<div class="container">
    <div class="loan-shell">
        <section class="loan-card">
            <div class="loan-head">
                <div>
                    <h2 class="loan-title">تقرير سلف الموظفين والرواتب الشهرية</h2>
                    <p class="loan-subtitle">قراءة موحدة للسلف، خصمها من المسيرات، سجل الرواتب الشهرية، والمدفوع الفعلي لكل موظف.</p>
                </div>
                <div style="display:flex; gap:10px; flex-wrap:wrap;">
                    <a href="payroll.php" class="loan-btn secondary">الرجوع للرواتب</a>
                    <a href="print_payroll_loans_report.php?<?php echo http_build_query($_GET); ?>" target="_blank" class="loan-btn">طباعة التقرير</a>
                </div>
            </div>
            <form method="GET" class="loan-filters">
                <div>
                    <label for="q">بحث</label>
                    <input type="text" id="q" name="q" value="<?php echo app_h($search); ?>" placeholder="اسم الموظف">
                </div>
                <div>
                    <label for="employee_id">الموظف</label>
                    <select id="employee_id" name="employee_id">
                        <option value="">كل الموظفين</option>
                        <?php foreach ($employeeOptions as $emp): ?>
                            <option value="<?php echo (int)$emp['id']; ?>" <?php echo $employeeFilter === (int)$emp['id'] ? 'selected' : ''; ?>><?php echo app_h($emp['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button class="loan-btn" type="submit">تطبيق</button>
                <a href="payroll_loans_report.php" class="loan-btn secondary">إعادة ضبط</a>
            </form>
            <div class="loan-grid">
                <div class="loan-kpi"><div class="label">صافي الرواتب المعتمدة</div><div class="value"><?php echo number_format($summary['payroll_net'], 2); ?></div></div>
                <div class="loan-kpi"><div class="label">المدفوع داخل المسيرات</div><div class="value"><?php echo number_format($summary['payroll_paid'], 2); ?></div></div>
                <div class="loan-kpi"><div class="label">دفعات رواتب مسجلة</div><div class="value"><?php echo number_format($summary['salary_cash'], 2); ?></div></div>
                <div class="loan-kpi"><div class="label">إجمالي السلف المصروفة</div><div class="value"><?php echo number_format($summary['issued'], 2); ?></div></div>
                <div class="loan-kpi"><div class="label">سداد نقدي</div><div class="value"><?php echo number_format($summary['cash'], 2); ?></div></div>
                <div class="loan-kpi"><div class="label">خصم من الرواتب</div><div class="value"><?php echo number_format($summary['payroll'], 2); ?></div></div>
                <div class="loan-kpi"><div class="label">الرصيد القائم</div><div class="value"><?php echo number_format($summary['outstanding'], 2); ?></div></div>
                <div class="loan-kpi"><div class="label">مستحقات رواتب مفتوحة</div><div class="value"><?php echo number_format($summary['salary_due'], 2); ?></div></div>
            </div>
        </section>
        <section class="loan-card">
            <div class="loan-table">
                <table>
                    <thead>
                        <tr>
                            <th>الموظف</th>
                            <th>عدد المسيرات</th>
                            <th>آخر شهر</th>
                            <th>صافي الرواتب</th>
                            <th>المدفوع بالمسيرات</th>
                            <th>دفعات رواتب مسجلة</th>
                            <th>السلف المصروفة</th>
                            <th>السداد النقدي</th>
                            <th>السداد من الرواتب</th>
                            <th>الرصيد القائم</th>
                            <th>مستحقات رواتب مفتوحة</th>
                            <th>الإجراءات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($rows): ?>
                            <?php foreach ($rows as $row): ?>
                                <tr>
                                    <td><?php echo app_h((string)$row['full_name']); ?></td>
                                    <td><?php echo number_format((float)$row['payroll_count']); ?></td>
                                    <td><?php echo app_h((string)($row['last_payroll_month'] ?? '-')); ?></td>
                                    <td><?php echo number_format((float)($row['payroll_net_total'] ?? 0), 2); ?></td>
                                    <td><?php echo number_format((float)($row['payroll_paid_total'] ?? 0), 2); ?></td>
                                    <td><?php echo number_format((float)($row['salary_cash_paid'] ?? 0), 2); ?></td>
                                    <td><?php echo number_format((float)$row['loans_issued'], 2); ?></td>
                                    <td><?php echo number_format((float)$row['repaid_cash'], 2); ?></td>
                                    <td><?php echo number_format((float)$row['repaid_payroll'], 2); ?></td>
                                    <td><?php echo number_format((float)$row['outstanding'], 2); ?></td>
                                    <td><?php echo number_format((float)$row['salary_due'], 2); ?></td>
                                    <td>
                                        <a class="loan-btn secondary" href="statement_employee.php?employee_id=<?php echo (int)$row['id']; ?>">كشف الحساب</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="12" style="color:#999;">لا توجد بيانات رواتب أو سلف مطابقة للمرشحات الحالية.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</div>
<?php require 'footer.php'; ob_end_flush(); ?>
