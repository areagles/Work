<?php
ob_start();
require 'auth.php';
require 'config.php';
require_once 'finance_engine.php';
app_handle_lang_switch($conn);
$isEnglish = app_current_lang($conn) === 'en';

if (!app_user_can('finance.reports.view')) {
    http_response_code(403);
    require 'header.php';
    echo "<div class='container' style='margin-top:30px;'><div style='background:#1d0f0f;border:1px solid #5b2020;border-radius:12px;padding:20px;color:#ffb3b3;'>" . app_h(app_tr('غير مصرح لك بعرض دفتر الأستاذ.', 'You are not authorized to view the ledger.')) . "</div></div>";
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
    $tmp = $dateFrom;
    $dateFrom = $dateTo;
    $dateTo = $tmp;
}

$ledgerEntries = [];
$addLine = static function (array &$entry, string $account, float $debit, float $credit): void {
    $debit = round(max(0.0, $debit), 2);
    $credit = round(max(0.0, $credit), 2);
    if ($debit <= 0.00001 && $credit <= 0.00001) {
        return;
    }
    $entry['lines'][] = [
        'account' => $account,
        'debit' => $debit,
        'credit' => $credit,
    ];
};
$pushEntry = static function (array $entry) use (&$ledgerEntries): void {
    $debit = 0.0;
    $credit = 0.0;
    foreach ((array)($entry['lines'] ?? []) as $line) {
        $debit += (float)($line['debit'] ?? 0);
        $credit += (float)($line['credit'] ?? 0);
    }
    $entry['debit_total'] = round($debit, 2);
    $entry['credit_total'] = round($credit, 2);
    $entry['balanced'] = abs($entry['debit_total'] - $entry['credit_total']) <= 0.01;
    if (!empty($entry['lines'])) {
        $ledgerEntries[] = $entry;
    }
};

$stmtSales = $conn->prepare("
    SELECT i.id, i.invoice_number, i.inv_date, i.sub_total, i.discount, i.tax_total, i.tax, i.total_amount, c.name AS client_name
    FROM invoices i
    LEFT JOIN clients c ON c.id = i.client_id
    WHERE COALESCE(i.status, '') <> 'cancelled'
      AND DATE(i.inv_date) BETWEEN ? AND ?
    ORDER BY i.inv_date ASC, i.id ASC
");
$stmtSales->bind_param('ss', $dateFrom, $dateTo);
$stmtSales->execute();
$salesRes = $stmtSales->get_result();
while ($row = $salesRes->fetch_assoc()) {
    $netBase = max(0.0, (float)($row['sub_total'] ?? 0) - (float)($row['discount'] ?? 0));
    $tax = (float)($row['tax_total'] ?? ($row['tax'] ?? 0));
    $total = (float)($row['total_amount'] ?? 0);
    $ref = (string)($row['invoice_number'] ?: ('INV-' . $row['id']));
    $entry = [
        'date' => (string)$row['inv_date'],
        'source' => app_tr('فاتورة مبيعات', 'Sales Invoice'),
        'ref' => $ref,
        'party' => (string)($row['client_name'] ?? ''),
        'memo' => app_tr('إثبات فاتورة مبيعات', 'Record sales invoice'),
        'lines' => [],
    ];
    $addLine($entry, app_tr('ذمم العملاء', 'Accounts Receivable'), $total, 0);
    $addLine($entry, app_tr('إيرادات المبيعات', 'Sales Revenue'), 0, $netBase);
    $addLine($entry, app_tr('ضريبة قيمة مضافة مخرجات', 'Output VAT'), 0, $tax);
    $pushEntry($entry);
}
$stmtSales->close();

$stmtPurchases = $conn->prepare("
    SELECT p.id, p.purchase_number, p.inv_date, p.sub_total, p.discount, p.tax, p.total_amount, s.name AS supplier_name
    FROM purchase_invoices p
    LEFT JOIN suppliers s ON s.id = p.supplier_id
    WHERE COALESCE(p.status, '') <> 'cancelled'
      AND DATE(p.inv_date) BETWEEN ? AND ?
    ORDER BY p.inv_date ASC, p.id ASC
");
$stmtPurchases->bind_param('ss', $dateFrom, $dateTo);
$stmtPurchases->execute();
$purchaseRes = $stmtPurchases->get_result();
while ($row = $purchaseRes->fetch_assoc()) {
    $netBase = max(0.0, (float)($row['sub_total'] ?? 0) - (float)($row['discount'] ?? 0));
    $tax = (float)($row['tax'] ?? 0);
    $total = (float)($row['total_amount'] ?? 0);
    $ref = (string)($row['purchase_number'] ?: ('PUR-' . $row['id']));
    $entry = [
        'date' => (string)$row['inv_date'],
        'source' => app_tr('فاتورة مشتريات', 'Purchase Invoice'),
        'ref' => $ref,
        'party' => (string)($row['supplier_name'] ?? ''),
        'memo' => app_tr('إثبات فاتورة مشتريات/مخزون', 'Record purchase/inventory invoice'),
        'lines' => [],
    ];
    $addLine($entry, app_tr('مشتريات / مخزون', 'Purchases / Inventory'), $netBase, 0);
    $addLine($entry, app_tr('ضريبة قيمة مضافة مدخلات', 'Input VAT'), $tax, 0);
    $addLine($entry, app_tr('ذمم الموردين', 'Accounts Payable'), 0, $total);
    $pushEntry($entry);
}
$stmtPurchases->close();

$stmtReceipts = $conn->prepare("
    SELECT r.*, c.name AS client_name, s.name AS supplier_name, u.full_name AS employee_name
    FROM financial_receipts r
    LEFT JOIN clients c ON c.id = r.client_id
    LEFT JOIN suppliers s ON s.id = r.supplier_id
    LEFT JOIN users u ON u.id = r.employee_id
    WHERE DATE(r.trans_date) BETWEEN ? AND ?
    ORDER BY r.trans_date ASC, r.id ASC
");
$stmtReceipts->bind_param('ss', $dateFrom, $dateTo);
$stmtReceipts->execute();
$receiptRes = $stmtReceipts->get_result();
while ($row = $receiptRes->fetch_assoc()) {
    $amount = (float)($row['amount'] ?? 0);
    if ($amount <= 0.00001) {
        continue;
    }
    $type = strtolower(trim((string)($row['type'] ?? '')));
    $category = strtolower(trim((string)($row['category'] ?? '')));
    $party = (string)($row['client_name'] ?? $row['supplier_name'] ?? $row['employee_name'] ?? '');
    $entry = [
        'date' => (string)$row['trans_date'],
        'source' => $type === 'in' ? app_tr('سند قبض', 'Receipt') : app_tr('سند صرف', 'Payment'),
        'ref' => 'FR-' . (int)$row['id'],
        'party' => $party,
        'memo' => (string)($row['description'] ?? ''),
        'lines' => [],
    ];
    if ($type === 'in') {
        $creditAccount = app_tr('إيرادات / أخرى', 'Revenue / Other');
        if ((int)($row['client_id'] ?? 0) > 0) {
            $creditAccount = app_tr('ذمم العملاء', 'Accounts Receivable');
        } elseif ($category === 'loan' || $category === 'loan_repayment') {
            $creditAccount = app_tr('سلف الموظفين', 'Employee Advances');
        }
        $addLine($entry, app_tr('الخزينة / النقدية', 'Cash / Treasury'), $amount, 0);
        $addLine($entry, $creditAccount, 0, $amount);
    } else {
        $debitAccount = app_tr('مصروفات عامة', 'General Expenses');
        if ($category === 'supplier' || (int)($row['supplier_id'] ?? 0) > 0) {
            $debitAccount = app_tr('ذمم الموردين', 'Accounts Payable');
        } elseif ($category === 'salary') {
            $debitAccount = app_tr('مصروف رواتب', 'Payroll Expense');
        } elseif ($category === 'loan') {
            $debitAccount = app_tr('سلف الموظفين', 'Employee Advances');
        } elseif ($category === 'tax') {
            $debitAccount = app_tr('مصروف/تسوية ضرائب', 'Tax Settlement');
        } elseif ($category === 'refund') {
            $debitAccount = app_tr('أرصدة عملاء مردودة', 'Customer Refunds Payable');
        }
        $addLine($entry, $debitAccount, $amount, 0);
        $addLine($entry, app_tr('الخزينة / النقدية', 'Cash / Treasury'), 0, $amount);
    }
    $pushEntry($entry);
}
$stmtReceipts->close();

if (app_table_exists($conn, 'sales_invoice_returns')) {
    $stmtSalesReturns = $conn->prepare("
        SELECT r.*, c.name AS client_name, i.invoice_number
        FROM sales_invoice_returns r
        LEFT JOIN clients c ON c.id = r.client_id
        LEFT JOIN invoices i ON i.id = r.invoice_id
        WHERE DATE(r.return_date) BETWEEN ? AND ?
        ORDER BY r.return_date ASC, r.id ASC
    ");
    $stmtSalesReturns->bind_param('ss', $dateFrom, $dateTo);
    $stmtSalesReturns->execute();
    $retRes = $stmtSalesReturns->get_result();
    while ($row = $retRes->fetch_assoc()) {
        $subtotal = (float)($row['subtotal_amount'] ?? 0);
        $tax = (float)($row['tax_amount'] ?? 0);
        $total = (float)($row['total_amount'] ?? 0);
        $entry = [
            'date' => (string)$row['return_date'],
            'source' => app_tr('مردود مبيعات', 'Sales Return'),
            'ref' => (string)($row['return_number'] ?: ('SRTN-' . $row['id'])),
            'party' => (string)($row['client_name'] ?? ''),
            'memo' => app_tr('عكس جزئي لفاتورة مبيعات', 'Partial reversal of sales invoice'),
            'lines' => [],
        ];
        $addLine($entry, app_tr('مردودات المبيعات', 'Sales Returns'), $subtotal, 0);
        $addLine($entry, app_tr('ضريبة قيمة مضافة مخرجات', 'Output VAT'), $tax, 0);
        $addLine($entry, app_tr('ذمم العملاء', 'Accounts Receivable'), 0, $total);
        $pushEntry($entry);
    }
    $stmtSalesReturns->close();
}

if (app_table_exists($conn, 'purchase_invoice_returns')) {
    $stmtPurchaseReturns = $conn->prepare("
        SELECT r.*, s.name AS supplier_name, p.purchase_number
        FROM purchase_invoice_returns r
        LEFT JOIN suppliers s ON s.id = r.supplier_id
        LEFT JOIN purchase_invoices p ON p.id = r.purchase_invoice_id
        WHERE DATE(r.return_date) BETWEEN ? AND ?
        ORDER BY r.return_date ASC, r.id ASC
    ");
    $stmtPurchaseReturns->bind_param('ss', $dateFrom, $dateTo);
    $stmtPurchaseReturns->execute();
    $pretRes = $stmtPurchaseReturns->get_result();
    while ($row = $pretRes->fetch_assoc()) {
        $subtotal = (float)($row['subtotal_amount'] ?? 0);
        $tax = (float)($row['tax_amount'] ?? 0);
        $total = (float)($row['total_amount'] ?? 0);
        $entry = [
            'date' => (string)$row['return_date'],
            'source' => app_tr('مردود شراء', 'Purchase Return'),
            'ref' => (string)($row['return_number'] ?: ('PRTN-' . $row['id'])),
            'party' => (string)($row['supplier_name'] ?? ''),
            'memo' => app_tr('عكس جزئي لفاتورة مشتريات', 'Partial reversal of purchase invoice'),
            'lines' => [],
        ];
        $addLine($entry, app_tr('ذمم الموردين', 'Accounts Payable'), $total, 0);
        $addLine($entry, app_tr('مشتريات / مخزون', 'Purchases / Inventory'), 0, $subtotal);
        $addLine($entry, app_tr('ضريبة قيمة مضافة مدخلات', 'Input VAT'), 0, $tax);
        $pushEntry($entry);
    }
    $stmtPurchaseReturns->close();
}

usort($ledgerEntries, static function (array $a, array $b): int {
    return strcmp(($a['date'] ?? '') . ($a['ref'] ?? ''), ($b['date'] ?? '') . ($b['ref'] ?? ''));
});

$totalDebit = 0.0;
$totalCredit = 0.0;
$unbalancedCount = 0;
foreach ($ledgerEntries as $entry) {
    $totalDebit += (float)($entry['debit_total'] ?? 0);
    $totalCredit += (float)($entry['credit_total'] ?? 0);
    if (empty($entry['balanced'])) {
        $unbalancedCount++;
    }
}

require 'header.php';
?>
<style>
    .ledger-shell { max-width: 1500px; margin: 0 auto; padding: 22px; }
    .ledger-head { display:flex; justify-content:space-between; align-items:center; gap:14px; flex-wrap:wrap; margin:18px 0 20px; }
    .ledger-head h2 { color:var(--gold); margin:0; }
    .ledger-panel { background:#141414; border:1px solid #2b2b2b; border-radius:18px; padding:18px; margin-bottom:18px; }
    .ledger-filters { display:grid; grid-template-columns:repeat(auto-fit,minmax(220px,1fr)); gap:12px; align-items:end; }
    .ledger-input { width:100%; background:#090909; border:1px solid #3a3a3a; color:#fff; padding:12px; border-radius:10px; }
    .ledger-btn { background:linear-gradient(45deg,var(--gold),#b8860b); color:#000; border:0; padding:12px 18px; border-radius:10px; font-weight:900; text-decoration:none; display:inline-flex; gap:8px; align-items:center; justify-content:center; }
    .ledger-cards { display:grid; grid-template-columns:repeat(auto-fit,minmax(220px,1fr)); gap:12px; }
    .ledger-card { background:#0f0f0f; border:1px solid #2b2b2b; border-radius:14px; padding:16px; }
    .ledger-card .label { color:#aaa; font-size:.9rem; }
    .ledger-card .num { font-size:1.55rem; font-weight:900; margin-top:6px; }
    .entry { border:1px solid #272727; border-radius:14px; overflow:hidden; margin-bottom:14px; background:#101010; }
    .entry-head { display:grid; grid-template-columns:140px 180px 1fr 160px; gap:10px; padding:12px 14px; background:#181818; color:#ddd; align-items:center; }
    .entry-head strong { color:var(--gold); }
    .entry-lines { width:100%; border-collapse:collapse; }
    .entry-lines th, .entry-lines td { padding:10px 14px; border-top:1px solid #252525; text-align:right; }
    .entry-lines th { color:var(--gold); background:#151515; }
    .amount { font-weight:800; direction:ltr; }
    .bad { color:#ff8b8b; }
    .ok { color:#79e69a; }
    @media(max-width:800px){ .entry-head{ grid-template-columns:1fr; } .ledger-shell{ padding:14px; } }
</style>

<div class="ledger-shell">
    <div class="ledger-head">
        <h2><i class="fa-solid fa-book"></i> <?php echo app_h(app_tr('دفتر الأستاذ والقيود', 'Ledger & Journal')); ?></h2>
        <a class="ledger-btn" href="finance_reports.php"><i class="fa-solid fa-arrow-right"></i> <?php echo app_h(app_tr('رجوع للتقارير', 'Back to reports')); ?></a>
    </div>

    <div class="ledger-panel">
        <form method="get" class="ledger-filters">
            <div>
                <label><?php echo app_h(app_tr('من تاريخ', 'Date from')); ?></label>
                <input class="ledger-input" type="date" name="date_from" value="<?php echo app_h($dateFrom); ?>">
            </div>
            <div>
                <label><?php echo app_h(app_tr('إلى تاريخ', 'Date to')); ?></label>
                <input class="ledger-input" type="date" name="date_to" value="<?php echo app_h($dateTo); ?>">
            </div>
            <button class="ledger-btn" type="submit"><i class="fa-solid fa-filter"></i> <?php echo app_h(app_tr('تحديث', 'Refresh')); ?></button>
        </form>
    </div>

    <div class="ledger-cards">
        <div class="ledger-card"><div class="label"><?php echo app_h(app_tr('عدد القيود', 'Entries')); ?></div><div class="num"><?php echo number_format(count($ledgerEntries)); ?></div></div>
        <div class="ledger-card"><div class="label"><?php echo app_h(app_tr('إجمالي المدين', 'Total debit')); ?></div><div class="num"><?php echo number_format($totalDebit, 2); ?></div></div>
        <div class="ledger-card"><div class="label"><?php echo app_h(app_tr('إجمالي الدائن', 'Total credit')); ?></div><div class="num"><?php echo number_format($totalCredit, 2); ?></div></div>
        <div class="ledger-card"><div class="label"><?php echo app_h(app_tr('قيود غير متوازنة', 'Unbalanced')); ?></div><div class="num <?php echo $unbalancedCount > 0 ? 'bad' : 'ok'; ?>"><?php echo number_format($unbalancedCount); ?></div></div>
    </div>

    <div class="ledger-panel">
        <?php if (empty($ledgerEntries)): ?>
            <div style="color:#aaa; text-align:center; padding:30px;"><?php echo app_h(app_tr('لا توجد قيود في الفترة المحددة.', 'No ledger entries in the selected period.')); ?></div>
        <?php endif; ?>
        <?php foreach ($ledgerEntries as $entry): ?>
            <div class="entry">
                <div class="entry-head">
                    <div><strong><?php echo app_h((string)$entry['date']); ?></strong></div>
                    <div><?php echo app_h((string)$entry['source']); ?> | <?php echo app_h((string)$entry['ref']); ?></div>
                    <div><?php echo app_h((string)$entry['party']); ?><br><span style="color:#999;"><?php echo app_h((string)$entry['memo']); ?></span></div>
                    <div class="<?php echo !empty($entry['balanced']) ? 'ok' : 'bad'; ?>"><?php echo !empty($entry['balanced']) ? app_h(app_tr('متوازن', 'Balanced')) : app_h(app_tr('غير متوازن', 'Unbalanced')); ?></div>
                </div>
                <table class="entry-lines">
                    <thead>
                        <tr>
                            <th><?php echo app_h(app_tr('الحساب', 'Account')); ?></th>
                            <th><?php echo app_h(app_tr('مدين', 'Debit')); ?></th>
                            <th><?php echo app_h(app_tr('دائن', 'Credit')); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ((array)$entry['lines'] as $line): ?>
                            <tr>
                                <td><?php echo app_h((string)$line['account']); ?></td>
                                <td class="amount"><?php echo ((float)$line['debit'] > 0) ? number_format((float)$line['debit'], 2) : '-'; ?></td>
                                <td class="amount"><?php echo ((float)$line['credit'] > 0) ? number_format((float)$line['credit'], 2) : '-'; ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <tr>
                            <td style="font-weight:900;"><?php echo app_h(app_tr('الإجمالي', 'Total')); ?></td>
                            <td class="amount" style="font-weight:900;"><?php echo number_format((float)$entry['debit_total'], 2); ?></td>
                            <td class="amount" style="font-weight:900;"><?php echo number_format((float)$entry['credit_total'], 2); ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<?php require 'footer.php'; ob_end_flush(); ?>
