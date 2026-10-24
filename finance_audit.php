<?php
ob_start();
require 'auth.php';
require 'config.php';
require_once 'finance_engine.php';
app_handle_lang_switch($conn);

if (!app_user_can('finance.reports.view')) {
    http_response_code(403);
    require 'header.php';
    echo "<div class='container' style='margin-top:30px;'><div style='background:#1d0f0f;border:1px solid #5b2020;border-radius:12px;padding:20px;color:#ffb3b3;'>" . app_h(app_tr('غير مصرح لك بعرض تدقيق الدورة المالية.', 'You are not authorized to view finance audit.')) . "</div></div>";
    require 'footer.php';
    exit;
}

$dateFrom = (string)($_GET['date_from'] ?? date('Y-m-01'));
$dateTo = (string)($_GET['date_to'] ?? date('Y-m-t'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
    $dateFrom = date('Y-m-01');
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
    $dateTo = date('Y-m-t');
}
if ($dateFrom > $dateTo) {
    [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
}

$findings = [];
$addFinding = static function (string $severity, string $area, string $reference, string $message, float $difference = 0.0) use (&$findings): void {
    $findings[] = [
        'severity' => $severity,
        'area' => $area,
        'reference' => $reference,
        'message' => $message,
        'difference' => round($difference, 2),
    ];
};
$fetchAll = static function (mysqli_result|false $res): array {
    $rows = [];
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $rows[] = $row;
        }
    }
    return $rows;
};

foreach ($fetchAll($conn->query("
    SELECT id, invoice_number, sub_total, discount, tax, tax_total, total_amount, paid_amount, remaining_amount, status
    FROM invoices
    WHERE DATE(COALESCE(NULLIF(inv_date, ''), created_at)) BETWEEN '" . $conn->real_escape_string($dateFrom) . "' AND '" . $conn->real_escape_string($dateTo) . "'
")) as $row) {
    $tax = (float)($row['tax_total'] ?? 0);
    if (abs($tax) <= 0.00001) {
        $tax = (float)($row['tax'] ?? 0);
    }
    $expectedTotal = round(max(0.0, (float)$row['sub_total'] - (float)$row['discount']) + $tax, 2);
    $actualTotal = round((float)$row['total_amount'], 2);
    $ref = (string)($row['invoice_number'] ?: ('INV-' . $row['id']));
    if (strtolower((string)$row['status']) === 'cancelled') {
        if (abs((float)$row['paid_amount']) > 0.01 || abs((float)$row['remaining_amount']) > 0.01) {
            $addFinding('high', app_tr('فواتير المبيعات', 'Sales invoices'), $ref, app_tr('فاتورة ملغاة ما زالت تحمل مدفوعًا أو متبقيًا.', 'Cancelled invoice still carries paid or remaining balance.'), max(abs((float)$row['paid_amount']), abs((float)$row['remaining_amount'])));
        }
        continue;
    }
    if (abs($expectedTotal - $actualTotal) > 0.01) {
        $addFinding('high', app_tr('فواتير المبيعات', 'Sales invoices'), $ref, app_tr('إجمالي الفاتورة لا يساوي الأساس ناقص الخصم زائد الضريبة.', 'Invoice total does not match base minus discount plus tax.'), $actualTotal - $expectedTotal);
    }
    $paidRemain = round((float)$row['paid_amount'] + (float)$row['remaining_amount'], 2);
    if (abs($paidRemain - $actualTotal) > 0.01) {
        $addFinding('high', app_tr('أرصدة العملاء', 'Customer balances'), $ref, app_tr('المدفوع زائد المتبقي لا يساوي إجمالي الفاتورة.', 'Paid plus remaining does not equal invoice total.'), $paidRemain - $actualTotal);
    }
}

foreach ($fetchAll($conn->query("
    SELECT id, purchase_number, sub_total, discount, tax, total_amount, paid_amount, remaining_amount, status
    FROM purchase_invoices
    WHERE DATE(COALESCE(NULLIF(inv_date, ''), created_at)) BETWEEN '" . $conn->real_escape_string($dateFrom) . "' AND '" . $conn->real_escape_string($dateTo) . "'
")) as $row) {
    $expectedTotal = round(max(0.0, (float)$row['sub_total'] - (float)$row['discount']) + (float)$row['tax'], 2);
    $actualTotal = round((float)$row['total_amount'], 2);
    $ref = (string)($row['purchase_number'] ?: ('PUR-' . $row['id']));
    if (strtolower((string)$row['status']) === 'cancelled') {
        if (abs((float)$row['paid_amount']) > 0.01 || abs((float)$row['remaining_amount']) > 0.01) {
            $addFinding('high', app_tr('فواتير المشتريات', 'Purchase invoices'), $ref, app_tr('فاتورة شراء ملغاة ما زالت تحمل مدفوعًا أو متبقيًا.', 'Cancelled purchase invoice still carries paid or remaining balance.'), max(abs((float)$row['paid_amount']), abs((float)$row['remaining_amount'])));
        }
        continue;
    }
    if (abs($expectedTotal - $actualTotal) > 0.01) {
        $addFinding('high', app_tr('فواتير المشتريات', 'Purchase invoices'), $ref, app_tr('إجمالي فاتورة الشراء لا يساوي الأساس ناقص الخصم زائد الضريبة.', 'Purchase total does not match base minus discount plus tax.'), $actualTotal - $expectedTotal);
    }
    $paidRemain = round((float)$row['paid_amount'] + (float)$row['remaining_amount'], 2);
    if (abs($paidRemain - $actualTotal) > 0.01) {
        $addFinding('high', app_tr('أرصدة الموردين', 'Supplier balances'), $ref, app_tr('المدفوع زائد المتبقي لا يساوي إجمالي فاتورة الشراء.', 'Paid plus remaining does not equal purchase total.'), $paidRemain - $actualTotal);
    }
}

if (app_table_exists($conn, 'financial_receipt_allocations')) {
    foreach ($fetchAll($conn->query("
        SELECT r.id, r.amount, IFNULL(SUM(a.amount), 0) AS allocated
        FROM financial_receipts r
        JOIN financial_receipt_allocations a ON a.receipt_id = r.id
        WHERE DATE(r.trans_date) BETWEEN '" . $conn->real_escape_string($dateFrom) . "' AND '" . $conn->real_escape_string($dateTo) . "'
        GROUP BY r.id, r.amount
        HAVING allocated - r.amount > 0.01
    ")) as $row) {
        $addFinding('critical', app_tr('التسويات', 'Allocations'), 'FR-' . (int)$row['id'], app_tr('إجمالي التسويات أكبر من مبلغ السند.', 'Allocated amount exceeds receipt amount.'), (float)$row['allocated'] - (float)$row['amount']);
    }

    foreach ($fetchAll($conn->query("
        SELECT a.id, a.allocation_type, a.target_id, a.amount
        FROM financial_receipt_allocations a
        LEFT JOIN invoices i ON a.allocation_type = 'sales_invoice' AND i.id = a.target_id
        LEFT JOIN purchase_invoices p ON a.allocation_type = 'purchase_invoice' AND p.id = a.target_id
        WHERE (a.allocation_type = 'sales_invoice' AND (i.id IS NULL OR COALESCE(i.status, '') = 'cancelled'))
           OR (a.allocation_type = 'purchase_invoice' AND (p.id IS NULL OR COALESCE(p.status, '') = 'cancelled'))
    ")) as $row) {
        $addFinding('critical', app_tr('التسويات', 'Allocations'), 'ALLOC-' . (int)$row['id'], app_tr('تسوية مرتبطة بمستند مفقود أو ملغى.', 'Allocation points to a missing or cancelled document.'), (float)$row['amount']);
    }
}

if (app_table_exists($conn, 'sales_invoice_returns')) {
    foreach ($fetchAll($conn->query("
        SELECT id, return_number, subtotal_amount, tax_amount, total_amount
        FROM sales_invoice_returns
        WHERE DATE(return_date) BETWEEN '" . $conn->real_escape_string($dateFrom) . "' AND '" . $conn->real_escape_string($dateTo) . "'
    ")) as $row) {
        $expected = round((float)$row['subtotal_amount'] + (float)$row['tax_amount'], 2);
        $actual = round((float)$row['total_amount'], 2);
        if (abs($expected - $actual) > 0.01) {
            $addFinding('high', app_tr('مردودات المبيعات', 'Sales returns'), (string)($row['return_number'] ?: ('SRTN-' . $row['id'])), app_tr('إجمالي مردود المبيعات لا يساوي الأساس زائد الضريبة.', 'Sales return total does not match subtotal plus tax.'), $actual - $expected);
        }
    }
}

if (app_table_exists($conn, 'purchase_invoice_returns')) {
    foreach ($fetchAll($conn->query("
        SELECT id, return_number, subtotal_amount, tax_amount, total_amount
        FROM purchase_invoice_returns
        WHERE DATE(return_date) BETWEEN '" . $conn->real_escape_string($dateFrom) . "' AND '" . $conn->real_escape_string($dateTo) . "'
    ")) as $row) {
        $expected = round((float)$row['subtotal_amount'] + (float)$row['tax_amount'], 2);
        $actual = round((float)$row['total_amount'], 2);
        if (abs($expected - $actual) > 0.01) {
            $addFinding('high', app_tr('مردودات الشراء', 'Purchase returns'), (string)($row['return_number'] ?: ('PRTN-' . $row['id'])), app_tr('إجمالي مردود الشراء لا يساوي الأساس زائد الضريبة.', 'Purchase return total does not match subtotal plus tax.'), $actual - $expected);
        }
    }
}

$severityOrder = ['critical' => 0, 'high' => 1, 'medium' => 2, 'low' => 3];
usort($findings, static function (array $a, array $b) use ($severityOrder): int {
    return ($severityOrder[$a['severity']] ?? 9) <=> ($severityOrder[$b['severity']] ?? 9);
});

$counts = ['critical' => 0, 'high' => 0, 'medium' => 0, 'low' => 0];
foreach ($findings as $finding) {
    $counts[$finding['severity']] = ($counts[$finding['severity']] ?? 0) + 1;
}

require 'header.php';
?>
<style>
    .audit-shell { max-width:1500px; margin:0 auto; padding:22px; }
    .audit-head { display:flex; justify-content:space-between; gap:14px; align-items:center; flex-wrap:wrap; margin:18px 0; }
    .audit-head h2 { color:var(--gold); margin:0; }
    .audit-panel { background:#141414; border:1px solid #2b2b2b; border-radius:18px; padding:18px; margin-bottom:18px; }
    .audit-filters { display:grid; grid-template-columns:repeat(auto-fit,minmax(220px,1fr)); gap:12px; align-items:end; }
    .audit-input { width:100%; background:#090909; border:1px solid #3a3a3a; color:#fff; padding:12px; border-radius:10px; }
    .audit-btn { background:linear-gradient(45deg,var(--gold),#b8860b); color:#000; border:0; padding:12px 18px; border-radius:10px; font-weight:900; text-decoration:none; display:inline-flex; gap:8px; align-items:center; justify-content:center; }
    .audit-cards { display:grid; grid-template-columns:repeat(auto-fit,minmax(190px,1fr)); gap:12px; }
    .audit-card { background:#0f0f0f; border:1px solid #2b2b2b; border-radius:14px; padding:16px; }
    .audit-card .label { color:#aaa; }
    .audit-card .num { font-size:1.7rem; font-weight:900; margin-top:6px; }
    .sev-critical { color:#ff4d4d; }
    .sev-high { color:#ffb14d; }
    .sev-medium { color:#ffe66d; }
    .sev-low { color:#84e69f; }
    .audit-table { width:100%; border-collapse:collapse; }
    .audit-table th, .audit-table td { padding:12px 14px; border-bottom:1px solid #282828; text-align:right; vertical-align:top; }
    .audit-table th { color:var(--gold); background:#181818; }
    .audit-empty { padding:34px; text-align:center; color:#84e69f; font-weight:900; }
    .amount { direction:ltr; font-weight:800; }
</style>

<div class="audit-shell">
    <div class="audit-head">
        <h2><i class="fa-solid fa-shield-halved"></i> <?php echo app_h(app_tr('تدقيق الدورة المالية', 'Finance Cycle Audit')); ?></h2>
        <div style="display:flex; gap:10px; flex-wrap:wrap;">
            <a class="audit-btn" href="finance_ledger.php?date_from=<?php echo urlencode($dateFrom); ?>&date_to=<?php echo urlencode($dateTo); ?>"><i class="fa-solid fa-book"></i> <?php echo app_h(app_tr('دفتر القيود', 'Ledger')); ?></a>
            <a class="audit-btn" href="finance_reports.php"><i class="fa-solid fa-arrow-right"></i> <?php echo app_h(app_tr('رجوع للتقارير', 'Back to reports')); ?></a>
        </div>
    </div>

    <div class="audit-panel">
        <form method="get" class="audit-filters">
            <div>
                <label><?php echo app_h(app_tr('من تاريخ', 'Date from')); ?></label>
                <input class="audit-input" type="date" name="date_from" value="<?php echo app_h($dateFrom); ?>">
            </div>
            <div>
                <label><?php echo app_h(app_tr('إلى تاريخ', 'Date to')); ?></label>
                <input class="audit-input" type="date" name="date_to" value="<?php echo app_h($dateTo); ?>">
            </div>
            <button class="audit-btn" type="submit"><i class="fa-solid fa-magnifying-glass-chart"></i> <?php echo app_h(app_tr('فحص', 'Audit')); ?></button>
        </form>
    </div>

    <div class="audit-cards">
        <div class="audit-card"><div class="label"><?php echo app_h(app_tr('إجمالي الملاحظات', 'Total findings')); ?></div><div class="num"><?php echo number_format(count($findings)); ?></div></div>
        <div class="audit-card"><div class="label"><?php echo app_h(app_tr('حرجة', 'Critical')); ?></div><div class="num sev-critical"><?php echo number_format($counts['critical']); ?></div></div>
        <div class="audit-card"><div class="label"><?php echo app_h(app_tr('عالية', 'High')); ?></div><div class="num sev-high"><?php echo number_format($counts['high']); ?></div></div>
        <div class="audit-card"><div class="label"><?php echo app_h(app_tr('متوسطة/منخفضة', 'Medium/Low')); ?></div><div class="num"><?php echo number_format($counts['medium'] + $counts['low']); ?></div></div>
    </div>

    <div class="audit-panel">
        <?php if (empty($findings)): ?>
            <div class="audit-empty"><?php echo app_h(app_tr('لا توجد فروق ظاهرة في الفترة المحددة.', 'No visible finance discrepancies in the selected period.')); ?></div>
        <?php else: ?>
            <table class="audit-table">
                <thead>
                    <tr>
                        <th><?php echo app_h(app_tr('الخطورة', 'Severity')); ?></th>
                        <th><?php echo app_h(app_tr('القسم', 'Area')); ?></th>
                        <th><?php echo app_h(app_tr('المرجع', 'Reference')); ?></th>
                        <th><?php echo app_h(app_tr('الملاحظة', 'Finding')); ?></th>
                        <th><?php echo app_h(app_tr('الفرق', 'Difference')); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($findings as $finding): ?>
                        <tr>
                            <td class="sev-<?php echo app_h((string)$finding['severity']); ?>"><?php echo app_h((string)$finding['severity']); ?></td>
                            <td><?php echo app_h((string)$finding['area']); ?></td>
                            <td><?php echo app_h((string)$finding['reference']); ?></td>
                            <td><?php echo app_h((string)$finding['message']); ?></td>
                            <td class="amount"><?php echo number_format((float)$finding['difference'], 2); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<?php require 'footer.php'; ob_end_flush(); ?>
