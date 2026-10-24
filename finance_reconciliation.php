<?php
ob_start();
require 'auth.php';
require 'config.php';
require_once 'receipts_runtime.php';
app_handle_lang_switch($conn);
$isEnglish = app_current_lang($conn) === 'en';

if (!app_user_can('finance.reports.view')) {
    http_response_code(403);
    require 'header.php';
    echo "<div class='container' style='margin-top:30px;'><div style='background:#1d0f0f;border:1px solid #5b2020;border-radius:12px;padding:20px;color:#ffb3b3;'>"
        . app_h($isEnglish ? 'You are not authorized to access reconciliation.' : 'غير مصرح لك بالدخول إلى المطابقة المالية.')
        . "</div></div>";
    require 'footer.php';
    exit;
}

financeEnsureLedgerSchema($conn);
financeEnsureAllocationSchema($conn);
require 'header.php';

$dateFrom = (string)($_GET['date_from'] ?? date('Y-m-01'));
$dateTo = (string)($_GET['date_to'] ?? date('Y-m-t'));
$mismatchesOnly = isset($_GET['mismatches_only']) && (string)$_GET['mismatches_only'] === '1';
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) $dateFrom = date('Y-m-01');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) $dateTo = date('Y-m-t');

$receiptIn = 0.0; $receiptOut = 0.0; $ledgerCashIn = 0.0; $ledgerCashOut = 0.0;
if ($stmt = $conn->prepare("SELECT IFNULL(SUM(CASE WHEN type='in' THEN amount ELSE 0 END),0), IFNULL(SUM(CASE WHEN type='out' THEN amount ELSE 0 END),0) FROM financial_receipts WHERE DATE(trans_date) BETWEEN ? AND ?")) {
    $stmt->bind_param('ss', $dateFrom, $dateTo); $stmt->execute(); $stmt->bind_result($receiptIn, $receiptOut); $stmt->fetch(); $stmt->close();
}
if ($stmt = $conn->prepare("SELECT IFNULL(SUM(CASE WHEN entry_type IN ('receipt_cash_in','employee_loan_repayment_cash') THEN amount ELSE 0 END),0), IFNULL(SUM(CASE WHEN entry_type IN ('receipt_cash_out','salary_payment','employee_loan_issued') THEN amount ELSE 0 END),0) FROM finance_ledger_entries WHERE DATE(trans_date) BETWEEN ? AND ?")) {
    $stmt->bind_param('ss', $dateFrom, $dateTo); $stmt->execute(); $stmt->bind_result($ledgerCashIn, $ledgerCashOut); $stmt->fetch(); $stmt->close();
}

$sourceBreakdown = [];
if ($res = $conn->query("SELECT source_type, COUNT(*) AS c, IFNULL(SUM(amount),0) AS total_amount FROM finance_ledger_entries WHERE DATE(trans_date) BETWEEN '" . $conn->real_escape_string($dateFrom) . "' AND '" . $conn->real_escape_string($dateTo) . "' GROUP BY source_type ORDER BY total_amount DESC, c DESC")) {
    while ($row = $res->fetch_assoc()) $sourceBreakdown[] = $row;
}

$buildReason = static function (float $receiptTotal, float $ledgerTotal) use ($isEnglish): string {
    $hasReceipt = abs($receiptTotal) > 0.00001;
    $hasLedger = abs($ledgerTotal) > 0.00001;
    if ($hasReceipt && !$hasLedger) return $isEnglish ? 'Cash movement exists without matching ledger posting.' : 'توجد حركة نقدية بدون قيد مطابق في الدفتر.';
    if (!$hasReceipt && $hasLedger) return $isEnglish ? 'Ledger posting exists without matching cash movement.' : 'يوجد قيد في الدفتر بدون حركة نقدية مقابلة.';
    if (round($receiptTotal, 2) !== round($ledgerTotal, 2)) return $isEnglish ? 'Partial mismatch. Review allocation or posted amount.' : 'يوجد فرق جزئي. راجع التخصيص أو المبلغ المرحل.';
    return $isEnglish ? 'Matched.' : 'مطابق.';
};
$applyMismatchOnly = static function (array $rows) use ($mismatchesOnly): array {
    if (!$mismatchesOnly) return $rows;
    return array_filter($rows, static fn(array $row): bool => abs(round((float)$row['receipt_total'] - (float)$row['ledger_total'], 2)) > 0.009);
};

$documentDiffs = [];
if ($res = $conn->query("SELECT CASE WHEN type='in' THEN 'sales_invoice' ELSE 'purchase_invoice' END AS document_type, invoice_id AS document_id, IFNULL(SUM(amount),0) AS receipt_total FROM financial_receipts WHERE invoice_id IS NOT NULL AND invoice_id > 0 AND DATE(trans_date) BETWEEN '" . $conn->real_escape_string($dateFrom) . "' AND '" . $conn->real_escape_string($dateTo) . "' GROUP BY CASE WHEN type='in' THEN 'sales_invoice' ELSE 'purchase_invoice' END, invoice_id")) {
    while ($row = $res->fetch_assoc()) {
        $key = $row['document_type'] . ':' . (int)$row['document_id'];
        $documentDiffs[$key] = ['document_type' => (string)$row['document_type'], 'document_id' => (int)$row['document_id'], 'receipt_total' => (float)$row['receipt_total'], 'ledger_total' => 0.0, 'document_ref' => '', 'counterparty_name' => '', 'drill_url' => '', 'reason' => ''];
    }
}
if ($res = $conn->query("SELECT CASE WHEN source_type='sales_invoice' THEN 'sales_invoice' WHEN source_type='purchase_invoice' THEN 'purchase_invoice' WHEN source_type='financial_receipt' AND IFNULL(JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.invoice_id')), '') != '' AND counterparty_type='client' THEN 'sales_invoice' WHEN source_type='financial_receipt' AND IFNULL(JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.invoice_id')), '') != '' AND counterparty_type='supplier' THEN 'purchase_invoice' ELSE source_type END AS document_type, CASE WHEN source_type IN ('sales_invoice','purchase_invoice') THEN source_id WHEN source_type='financial_receipt' THEN CAST(IFNULL(JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.invoice_id')), '0') AS UNSIGNED) ELSE source_id END AS document_id, IFNULL(SUM(amount),0) AS ledger_total FROM finance_ledger_entries WHERE DATE(trans_date) BETWEEN '" . $conn->real_escape_string($dateFrom) . "' AND '" . $conn->real_escape_string($dateTo) . "' AND (source_type IN ('sales_invoice','purchase_invoice') OR (source_type='financial_receipt' AND CAST(IFNULL(JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.invoice_id')), '0') AS UNSIGNED) > 0)) GROUP BY document_type, document_id")) {
    while ($row = $res->fetch_assoc()) {
        $docType = (string)($row['document_type'] ?? ''); $docId = (int)($row['document_id'] ?? 0); if ($docType === '' || $docId <= 0) continue;
        $key = $docType . ':' . $docId;
        if (!isset($documentDiffs[$key])) $documentDiffs[$key] = ['document_type' => $docType, 'document_id' => $docId, 'receipt_total' => 0.0, 'ledger_total' => 0.0, 'document_ref' => '', 'counterparty_name' => '', 'drill_url' => '', 'reason' => ''];
        $documentDiffs[$key]['ledger_total'] += (float)$row['ledger_total'];
    }
}

$counterpartyDiffs = [];
if ($res = $conn->query("SELECT CASE WHEN client_id IS NOT NULL AND client_id > 0 THEN 'client' WHEN supplier_id IS NOT NULL AND supplier_id > 0 THEN 'supplier' WHEN employee_id IS NOT NULL AND employee_id > 0 THEN 'employee' ELSE 'other' END AS counterparty_type, CASE WHEN client_id IS NOT NULL AND client_id > 0 THEN client_id WHEN supplier_id IS NOT NULL AND supplier_id > 0 THEN supplier_id WHEN employee_id IS NOT NULL AND employee_id > 0 THEN employee_id ELSE 0 END AS counterparty_id, IFNULL(SUM(amount),0) AS receipt_total FROM financial_receipts WHERE DATE(trans_date) BETWEEN '" . $conn->real_escape_string($dateFrom) . "' AND '" . $conn->real_escape_string($dateTo) . "' GROUP BY counterparty_type, counterparty_id")) {
    while ($row = $res->fetch_assoc()) {
        $key = $row['counterparty_type'] . ':' . (int)$row['counterparty_id'];
        $counterpartyDiffs[$key] = ['counterparty_type' => (string)$row['counterparty_type'], 'counterparty_id' => (int)$row['counterparty_id'], 'receipt_total' => (float)$row['receipt_total'], 'ledger_total' => 0.0, 'counterparty_name' => '', 'drill_url' => '', 'reason' => ''];
    }
}
if ($res = $conn->query("SELECT counterparty_type, counterparty_id, IFNULL(SUM(amount),0) AS ledger_total FROM finance_ledger_entries WHERE DATE(trans_date) BETWEEN '" . $conn->real_escape_string($dateFrom) . "' AND '" . $conn->real_escape_string($dateTo) . "' GROUP BY counterparty_type, counterparty_id")) {
    while ($row = $res->fetch_assoc()) {
        $key = (string)$row['counterparty_type'] . ':' . (int)$row['counterparty_id'];
        if (!isset($counterpartyDiffs[$key])) $counterpartyDiffs[$key] = ['counterparty_type' => (string)$row['counterparty_type'], 'counterparty_id' => (int)$row['counterparty_id'], 'receipt_total' => 0.0, 'ledger_total' => 0.0, 'counterparty_name' => '', 'drill_url' => '', 'reason' => ''];
        $counterpartyDiffs[$key]['ledger_total'] += (float)$row['ledger_total'];
    }
}

$payrollDiffs = [];
if ($res = $conn->query("SELECT a.target_id AS payroll_id, IFNULL(SUM(a.amount),0) AS receipt_total FROM financial_receipt_allocations a INNER JOIN financial_receipts r ON r.id = a.receipt_id WHERE a.allocation_type = 'payroll' AND DATE(r.trans_date) BETWEEN '" . $conn->real_escape_string($dateFrom) . "' AND '" . $conn->real_escape_string($dateTo) . "' GROUP BY a.target_id")) {
    while ($row = $res->fetch_assoc()) {
        $payrollId = (int)($row['payroll_id'] ?? 0); if ($payrollId <= 0) continue;
        $payrollDiffs[$payrollId] = ['payroll_id' => $payrollId, 'receipt_total' => (float)$row['receipt_total'], 'ledger_total' => 0.0, 'payroll_number' => '', 'month_year' => '', 'employee_name' => '', 'status' => '', 'drill_url' => '', 'reason' => ''];
    }
}
if ($res = $conn->query("SELECT CAST(IFNULL(JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.target_id')), '0') AS UNSIGNED) AS payroll_id, IFNULL(SUM(amount),0) AS ledger_total FROM finance_ledger_entries WHERE source_type = 'financial_receipt_allocation' AND entry_type = 'allocation_payroll' AND DATE(trans_date) BETWEEN '" . $conn->real_escape_string($dateFrom) . "' AND '" . $conn->real_escape_string($dateTo) . "' GROUP BY CAST(IFNULL(JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.target_id')), '0') AS UNSIGNED)")) {
    while ($row = $res->fetch_assoc()) {
        $payrollId = (int)($row['payroll_id'] ?? 0); if ($payrollId <= 0) continue;
        if (!isset($payrollDiffs[$payrollId])) $payrollDiffs[$payrollId] = ['payroll_id' => $payrollId, 'receipt_total' => 0.0, 'ledger_total' => 0.0, 'payroll_number' => '', 'month_year' => '', 'employee_name' => '', 'status' => '', 'drill_url' => '', 'reason' => ''];
        $payrollDiffs[$payrollId]['ledger_total'] += (float)$row['ledger_total'];
    }
}

$loanAdvanceDiffs = [];
if ($res = $conn->query("SELECT a.target_id AS employee_id, IFNULL(SUM(a.amount),0) AS receipt_total FROM financial_receipt_allocations a INNER JOIN financial_receipts r ON r.id = a.receipt_id WHERE a.allocation_type = 'loan_advance' AND DATE(r.trans_date) BETWEEN '" . $conn->real_escape_string($dateFrom) . "' AND '" . $conn->real_escape_string($dateTo) . "' GROUP BY a.target_id")) {
    while ($row = $res->fetch_assoc()) {
        $employeeId = (int)($row['employee_id'] ?? 0); if ($employeeId <= 0) continue;
        $loanAdvanceDiffs[$employeeId] = ['employee_id' => $employeeId, 'receipt_total' => (float)$row['receipt_total'], 'ledger_total' => 0.0, 'employee_name' => '', 'drill_url' => '', 'reason' => ''];
    }
}
if ($res = $conn->query("SELECT CAST(IFNULL(JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.target_id')), '0') AS UNSIGNED) AS employee_id, IFNULL(SUM(amount),0) AS ledger_total FROM finance_ledger_entries WHERE source_type = 'financial_receipt_allocation' AND entry_type = 'allocation_loan_advance' AND DATE(trans_date) BETWEEN '" . $conn->real_escape_string($dateFrom) . "' AND '" . $conn->real_escape_string($dateTo) . "' GROUP BY CAST(IFNULL(JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.target_id')), '0') AS UNSIGNED)")) {
    while ($row = $res->fetch_assoc()) {
        $employeeId = (int)($row['employee_id'] ?? 0); if ($employeeId <= 0) continue;
        if (!isset($loanAdvanceDiffs[$employeeId])) $loanAdvanceDiffs[$employeeId] = ['employee_id' => $employeeId, 'receipt_total' => 0.0, 'ledger_total' => 0.0, 'employee_name' => '', 'drill_url' => '', 'reason' => ''];
        $loanAdvanceDiffs[$employeeId]['ledger_total'] += (float)$row['ledger_total'];
    }
}

$taxDiffs = [];
if (app_table_has_column($conn, 'financial_receipts', 'tax_law_key')) {
    if ($res = $conn->query("SELECT COALESCE(NULLIF(TRIM(tax_law_key), ''), '__unassigned__') AS tax_law_key, IFNULL(SUM(amount),0) AS receipt_total FROM financial_receipts WHERE type='out' AND category='tax' AND DATE(trans_date) BETWEEN '" . $conn->real_escape_string($dateFrom) . "' AND '" . $conn->real_escape_string($dateTo) . "' GROUP BY COALESCE(NULLIF(TRIM(tax_law_key), ''), '__unassigned__')")) {
        while ($row = $res->fetch_assoc()) {
            $law = (string)($row['tax_law_key'] ?? '__unassigned__');
            $taxDiffs[$law] = ['tax_law_key' => $law, 'receipt_total' => (float)$row['receipt_total'], 'ledger_total' => 0.0, 'reason' => ''];
        }
    }
    if ($res = $conn->query("SELECT COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.tax_law_key')), ''), '__unassigned__') AS tax_law_key, IFNULL(SUM(amount),0) AS ledger_total FROM finance_ledger_entries WHERE source_type='financial_receipt' AND DATE(trans_date) BETWEEN '" . $conn->real_escape_string($dateFrom) . "' AND '" . $conn->real_escape_string($dateTo) . "' AND JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.category')) = 'tax' GROUP BY COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.tax_law_key')), ''), '__unassigned__')")) {
        while ($row = $res->fetch_assoc()) {
            $law = (string)($row['tax_law_key'] ?? '__unassigned__');
            if (!isset($taxDiffs[$law])) $taxDiffs[$law] = ['tax_law_key' => $law, 'receipt_total' => 0.0, 'ledger_total' => 0.0, 'reason' => ''];
            $taxDiffs[$law]['ledger_total'] += (float)$row['ledger_total'];
        }
    }
}

$manualDiffs = [];
if ($res = $conn->query("SELECT COALESCE(NULLIF(TRIM(category), ''), 'general') AS category_key, IFNULL(SUM(amount),0) AS receipt_total FROM financial_receipts WHERE DATE(trans_date) BETWEEN '" . $conn->real_escape_string($dateFrom) . "' AND '" . $conn->real_escape_string($dateTo) . "' AND COALESCE(NULLIF(TRIM(category), ''), 'general') IN ('general','manual') GROUP BY COALESCE(NULLIF(TRIM(category), ''), 'general')")) {
    while ($row = $res->fetch_assoc()) {
        $cat = (string)($row['category_key'] ?? 'general');
        $manualDiffs[$cat] = ['category_key' => $cat, 'receipt_total' => (float)$row['receipt_total'], 'ledger_total' => 0.0, 'reason' => ''];
    }
}
if ($res = $conn->query("SELECT COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.category')), ''), 'general') AS category_key, IFNULL(SUM(amount),0) AS ledger_total FROM finance_ledger_entries WHERE source_type='financial_receipt' AND DATE(trans_date) BETWEEN '" . $conn->real_escape_string($dateFrom) . "' AND '" . $conn->real_escape_string($dateTo) . "' AND COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.category')), ''), 'general') IN ('general','manual') GROUP BY COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.category')), ''), 'general')")) {
    while ($row = $res->fetch_assoc()) {
        $cat = (string)($row['category_key'] ?? 'general');
        if (!isset($manualDiffs[$cat])) $manualDiffs[$cat] = ['category_key' => $cat, 'receipt_total' => 0.0, 'ledger_total' => 0.0, 'reason' => ''];
        $manualDiffs[$cat]['ledger_total'] += (float)$row['ledger_total'];
    }
}

$salesInvoiceIds = []; $purchaseInvoiceIds = []; $clientIds = []; $supplierIds = []; $employeeIds = []; $payrollIds = [];
foreach ($documentDiffs as $row) {
    if ($row['document_type'] === 'sales_invoice' && (int)$row['document_id'] > 0) $salesInvoiceIds[(int)$row['document_id']] = (int)$row['document_id'];
    elseif ($row['document_type'] === 'purchase_invoice' && (int)$row['document_id'] > 0) $purchaseInvoiceIds[(int)$row['document_id']] = (int)$row['document_id'];
}
foreach ($counterpartyDiffs as $row) {
    if ($row['counterparty_type'] === 'client' && (int)$row['counterparty_id'] > 0) $clientIds[(int)$row['counterparty_id']] = (int)$row['counterparty_id'];
    elseif ($row['counterparty_type'] === 'supplier' && (int)$row['counterparty_id'] > 0) $supplierIds[(int)$row['counterparty_id']] = (int)$row['counterparty_id'];
    elseif ($row['counterparty_type'] === 'employee' && (int)$row['counterparty_id'] > 0) $employeeIds[(int)$row['counterparty_id']] = (int)$row['counterparty_id'];
}
foreach ($payrollDiffs as $row) if ((int)$row['payroll_id'] > 0) $payrollIds[(int)$row['payroll_id']] = (int)$row['payroll_id'];
foreach ($loanAdvanceDiffs as $row) if ((int)$row['employee_id'] > 0) $employeeIds[(int)$row['employee_id']] = (int)$row['employee_id'];

$salesInvoiceMap = []; if ($salesInvoiceIds) { $sql = "SELECT i.id, IFNULL(i.invoice_number, CONCAT('#', i.id)) AS ref, IFNULL(c.name,'') AS counterparty_name FROM invoices i LEFT JOIN clients c ON c.id = i.client_id WHERE i.id IN (" . implode(',', array_map('intval', array_values($salesInvoiceIds))) . ")"; if ($res = $conn->query($sql)) while ($row = $res->fetch_assoc()) $salesInvoiceMap[(int)$row['id']] = ['ref' => (string)($row['ref'] ?? ''), 'counterparty_name' => (string)($row['counterparty_name'] ?? '')]; }
$purchaseInvoiceMap = []; if ($purchaseInvoiceIds) { $sql = "SELECT p.id, IFNULL(p.purchase_number, CONCAT('#', p.id)) AS ref, IFNULL(s.name, IFNULL(p.supplier_display_name,'')) AS counterparty_name FROM purchase_invoices p LEFT JOIN suppliers s ON s.id = p.supplier_id WHERE p.id IN (" . implode(',', array_map('intval', array_values($purchaseInvoiceIds))) . ")"; if ($res = $conn->query($sql)) while ($row = $res->fetch_assoc()) $purchaseInvoiceMap[(int)$row['id']] = ['ref' => (string)($row['ref'] ?? ''), 'counterparty_name' => (string)($row['counterparty_name'] ?? '')]; }
$clientMap = []; if ($clientIds) { $sql = "SELECT id, IFNULL(name, CONCAT('Client #', id)) AS display_name FROM clients WHERE id IN (" . implode(',', array_map('intval', array_values($clientIds))) . ")"; if ($res = $conn->query($sql)) while ($row = $res->fetch_assoc()) $clientMap[(int)$row['id']] = (string)($row['display_name'] ?? ''); }
$supplierMap = []; if ($supplierIds) { $sql = "SELECT id, IFNULL(name, CONCAT('Supplier #', id)) AS display_name FROM suppliers WHERE id IN (" . implode(',', array_map('intval', array_values($supplierIds))) . ")"; if ($res = $conn->query($sql)) while ($row = $res->fetch_assoc()) $supplierMap[(int)$row['id']] = (string)($row['display_name'] ?? ''); }
$employeeMap = []; if ($employeeIds) { $sql = "SELECT id, IFNULL(full_name, CONCAT('Employee #', id)) AS display_name FROM users WHERE id IN (" . implode(',', array_map('intval', array_values($employeeIds))) . ")"; $res = $conn->query($sql); if ($res) while ($row = $res->fetch_assoc()) $employeeMap[(int)$row['id']] = (string)($row['display_name'] ?? ''); }
$payrollMap = []; if ($payrollIds) { $sql = "SELECT p.id, IFNULL(p.payroll_number, CONCAT('#', p.id)) AS payroll_number, IFNULL(p.month_year,'') AS month_year, IFNULL(p.status,'') AS status, IFNULL(p.employee_name_snapshot, IFNULL(u.full_name,'')) AS employee_name, p.employee_id FROM payroll_sheets p LEFT JOIN users u ON u.id = p.employee_id WHERE p.id IN (" . implode(',', array_map('intval', array_values($payrollIds))) . ")"; if ($res = $conn->query($sql)) while ($row = $res->fetch_assoc()) $payrollMap[(int)$row['id']] = ['payroll_number' => (string)($row['payroll_number'] ?? ''), 'month_year' => (string)($row['month_year'] ?? ''), 'status' => (string)($row['status'] ?? ''), 'employee_name' => (string)($row['employee_name'] ?? ''), 'employee_id' => (int)($row['employee_id'] ?? 0)]; }

foreach ($documentDiffs as $key => $row) {
    if ($row['document_type'] === 'sales_invoice' && isset($salesInvoiceMap[(int)$row['document_id']])) { $documentDiffs[$key]['document_ref'] = (string)$salesInvoiceMap[(int)$row['document_id']]['ref']; $documentDiffs[$key]['counterparty_name'] = (string)$salesInvoiceMap[(int)$row['document_id']]['counterparty_name']; $documentDiffs[$key]['drill_url'] = 'edit_invoice.php?id=' . (int)$row['document_id']; }
    elseif ($row['document_type'] === 'purchase_invoice' && isset($purchaseInvoiceMap[(int)$row['document_id']])) { $documentDiffs[$key]['document_ref'] = (string)$purchaseInvoiceMap[(int)$row['document_id']]['ref']; $documentDiffs[$key]['counterparty_name'] = (string)$purchaseInvoiceMap[(int)$row['document_id']]['counterparty_name']; $documentDiffs[$key]['drill_url'] = 'print_purchase.php?id=' . (int)$row['document_id']; }
    else $documentDiffs[$key]['document_ref'] = '#' . (int)$row['document_id'];
    $documentDiffs[$key]['reason'] = $buildReason((float)$row['receipt_total'], (float)$row['ledger_total']);
}
foreach ($counterpartyDiffs as $key => $row) {
    if ($row['counterparty_type'] === 'client') { $counterpartyDiffs[$key]['counterparty_name'] = (string)($clientMap[(int)$row['counterparty_id']] ?? ('Client #' . (int)$row['counterparty_id'])); $counterpartyDiffs[$key]['drill_url'] = 'clients.php'; }
    elseif ($row['counterparty_type'] === 'supplier') { $counterpartyDiffs[$key]['counterparty_name'] = (string)($supplierMap[(int)$row['counterparty_id']] ?? ('Supplier #' . (int)$row['counterparty_id'])); $counterpartyDiffs[$key]['drill_url'] = 'suppliers.php'; }
    elseif ($row['counterparty_type'] === 'employee') { $counterpartyDiffs[$key]['counterparty_name'] = (string)($employeeMap[(int)$row['counterparty_id']] ?? ('Employee #' . (int)$row['counterparty_id'])); $counterpartyDiffs[$key]['drill_url'] = 'statement_employee.php?employee_id=' . (int)$row['counterparty_id'] . '&from=' . rawurlencode($dateFrom) . '&to=' . rawurlencode($dateTo); }
    else $counterpartyDiffs[$key]['counterparty_name'] = $isEnglish ? 'General / Unknown' : 'عام / غير محدد';
    $counterpartyDiffs[$key]['reason'] = $buildReason((float)$row['receipt_total'], (float)$row['ledger_total']);
}
foreach ($payrollDiffs as $key => $row) {
    $payrollDiffs[$key]['employee_name'] = (string)($payrollMap[(int)$key]['employee_name'] ?? '');
    $payrollDiffs[$key]['payroll_number'] = (string)($payrollMap[(int)$key]['payroll_number'] ?? ('#' . (int)$key));
    $payrollDiffs[$key]['month_year'] = (string)($payrollMap[(int)$key]['month_year'] ?? '');
    $payrollDiffs[$key]['status'] = (string)($payrollMap[(int)$key]['status'] ?? '');
    $payrollEmployeeId = (int)($payrollMap[(int)$key]['employee_id'] ?? 0);
    $payrollDiffs[$key]['drill_url'] = 'payroll.php?employee_id=' . $payrollEmployeeId . '&month_year=' . rawurlencode((string)$payrollDiffs[$key]['month_year']);
    $payrollDiffs[$key]['reason'] = $buildReason((float)$row['receipt_total'], (float)$row['ledger_total']);
}
foreach ($loanAdvanceDiffs as $key => $row) {
    $loanAdvanceDiffs[$key]['employee_name'] = (string)($employeeMap[(int)$key] ?? ('Employee #' . (int)$key));
    $loanAdvanceDiffs[$key]['drill_url'] = 'statement_employee.php?employee_id=' . (int)$key . '&from=' . rawurlencode($dateFrom) . '&to=' . rawurlencode($dateTo);
    $loanAdvanceDiffs[$key]['reason'] = $buildReason((float)$row['receipt_total'], (float)$row['ledger_total']);
}
foreach ($taxDiffs as $key => $row) $taxDiffs[$key]['reason'] = $buildReason((float)$row['receipt_total'], (float)$row['ledger_total']);
foreach ($manualDiffs as $key => $row) $manualDiffs[$key]['reason'] = $buildReason((float)$row['receipt_total'], (float)$row['ledger_total']);

$documentDiffs = $applyMismatchOnly($documentDiffs); $counterpartyDiffs = $applyMismatchOnly($counterpartyDiffs); $payrollDiffs = $applyMismatchOnly($payrollDiffs); $loanAdvanceDiffs = $applyMismatchOnly($loanAdvanceDiffs); $taxDiffs = $applyMismatchOnly($taxDiffs); $manualDiffs = $applyMismatchOnly($manualDiffs);
uasort($documentDiffs, static fn($a,$b)=> abs(round((float)$b['receipt_total']-(float)$b['ledger_total'],2)) <=> abs(round((float)$a['receipt_total']-(float)$a['ledger_total'],2)));
uasort($counterpartyDiffs, static fn($a,$b)=> abs(round((float)$b['receipt_total']-(float)$b['ledger_total'],2)) <=> abs(round((float)$a['receipt_total']-(float)$a['ledger_total'],2)));
uasort($payrollDiffs, static fn($a,$b)=> abs(round((float)$b['receipt_total']-(float)$b['ledger_total'],2)) <=> abs(round((float)$a['receipt_total']-(float)$a['ledger_total'],2)));
uasort($loanAdvanceDiffs, static fn($a,$b)=> abs(round((float)$b['receipt_total']-(float)$b['ledger_total'],2)) <=> abs(round((float)$a['receipt_total']-(float)$a['ledger_total'],2)));
uasort($taxDiffs, static fn($a,$b)=> abs(round((float)$b['receipt_total']-(float)$b['ledger_total'],2)) <=> abs(round((float)$a['receipt_total']-(float)$a['ledger_total'],2)));
uasort($manualDiffs, static fn($a,$b)=> abs(round((float)$b['receipt_total']-(float)$b['ledger_total'],2)) <=> abs(round((float)$a['receipt_total']-(float)$a['ledger_total'],2)));

$printUrl = 'print_finance_reconciliation.php?' . http_build_query(['date_from' => $dateFrom, 'date_to' => $dateTo, 'mismatches_only' => $mismatchesOnly ? '1' : '0']);
$formatDocType = static function (string $type) use ($isEnglish): string { return ['sales_invoice' => $isEnglish ? 'Sales invoice' : 'فاتورة مبيعات', 'purchase_invoice' => $isEnglish ? 'Purchase invoice' : 'فاتورة شراء'][$type] ?? $type; };
$formatCounterpartyType = static function (string $type) use ($isEnglish): string { return ['client' => $isEnglish ? 'Client' : 'عميل', 'supplier' => $isEnglish ? 'Supplier' : 'مورد', 'employee' => $isEnglish ? 'Employee' : 'موظف', 'other' => $isEnglish ? 'Other' : 'أخرى'][$type] ?? $type; };
$formatTaxLaw = static function (string $key) use ($isEnglish): string { return $key === '__unassigned__' ? ($isEnglish ? 'Unassigned tax law' : 'قانون ضريبي غير محدد') : $key; };
$formatManualKey = static function (string $key) use ($isEnglish): string { return $key === 'general' ? ($isEnglish ? 'General manual entries' : 'قيود/حركات عامة') : ($isEnglish ? 'Manual entries' : 'حركات يدوية'); };
?>
<div class="container" style="margin-top:30px;">
    <div style="display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap; margin-bottom:20px;">
        <h2 style="margin:0; color:var(--gold);"><?php echo app_h($isEnglish ? 'Ledger Reconciliation' : 'مطابقة القيود'); ?></h2>
        <div style="display:flex; gap:10px; flex-wrap:wrap;">
            <a href="finance_ledger.php" style="background:#0f3d2e; color:#fff; padding:10px 18px; text-decoration:none; border-radius:10px; font-weight:700;"><?php echo app_h($isEnglish ? 'Open ledger' : 'فتح دفتر الحركة'); ?></a>
            <a href="<?php echo app_h($printUrl); ?>" target="_blank" style="background:#b98713; color:#141414; padding:10px 18px; text-decoration:none; border-radius:10px; font-weight:800;"><?php echo app_h($isEnglish ? 'Print / PDF report' : 'طباعة / PDF'); ?></a>
            <a href="finance_reports.php" style="background:#1f2937; color:#fff; padding:10px 18px; text-decoration:none; border-radius:10px; font-weight:700;"><?php echo app_h($isEnglish ? 'Back to finance reports' : 'الرجوع للتقارير المالية'); ?></a>
        </div>
    </div>
    <div style="background:#151515; border:1px solid #333; border-radius:16px; padding:18px; margin-bottom:18px;">
        <form method="GET" style="display:grid; grid-template-columns:repeat(auto-fit,minmax(220px,1fr)); gap:12px;">
            <div><label style="display:block; margin-bottom:8px; color:#aaa;"><?php echo app_h($isEnglish ? 'Date from' : 'من تاريخ'); ?></label><input type="date" name="date_from" value="<?php echo app_h($dateFrom); ?>" style="width:100%; background:#090909; border:1px solid #444; color:#fff; padding:12px; border-radius:8px;"></div>
            <div><label style="display:block; margin-bottom:8px; color:#aaa;"><?php echo app_h($isEnglish ? 'Date to' : 'إلى تاريخ'); ?></label><input type="date" name="date_to" value="<?php echo app_h($dateTo); ?>" style="width:100%; background:#090909; border:1px solid #444; color:#fff; padding:12px; border-radius:8px;"></div>
            <div style="display:flex; align-items:flex-end;"><label style="display:flex; align-items:center; gap:10px; background:#090909; border:1px solid #444; color:#fff; padding:12px 14px; border-radius:8px; width:100%; box-sizing:border-box;"><input type="checkbox" name="mismatches_only" value="1" <?php echo $mismatchesOnly ? 'checked' : ''; ?>><span><?php echo app_h($isEnglish ? 'Mismatches only' : 'الفروقات فقط'); ?></span></label></div>
            <div style="display:flex; align-items:flex-end;"><button type="submit" style="width:100%; background:linear-gradient(135deg,#e5c75a,#b98713); color:#141414; border:none; padding:12px; border-radius:8px; font-weight:800;"><?php echo app_h($isEnglish ? 'Refresh' : 'تحديث'); ?></button></div>
        </form>
    </div>
    <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(220px,1fr)); gap:16px; margin-bottom:18px;">
        <div style="background:#101010; border:1px solid #333; border-radius:14px; padding:18px;"><div style="color:#999; margin-bottom:8px;"><?php echo app_h($isEnglish ? 'Cash receipts' : 'إجمالي القبض النقدي'); ?></div><div style="font-size:1.8rem; font-weight:900; color:#22c55e;"><?php echo number_format((float)$receiptIn, 2); ?></div></div>
        <div style="background:#101010; border:1px solid #333; border-radius:14px; padding:18px;"><div style="color:#999; margin-bottom:8px;"><?php echo app_h($isEnglish ? 'Ledger cash-in' : 'القبض في الدفتر'); ?></div><div style="font-size:1.8rem; font-weight:900; color:#60a5fa;"><?php echo number_format((float)$ledgerCashIn, 2); ?></div></div>
        <div style="background:#101010; border:1px solid #333; border-radius:14px; padding:18px;"><div style="color:#999; margin-bottom:8px;"><?php echo app_h($isEnglish ? 'Cash payments' : 'إجمالي الصرف النقدي'); ?></div><div style="font-size:1.8rem; font-weight:900; color:#f87171;"><?php echo number_format((float)$receiptOut, 2); ?></div></div>
        <div style="background:#101010; border:1px solid #333; border-radius:14px; padding:18px;"><div style="color:#999; margin-bottom:8px;"><?php echo app_h($isEnglish ? 'Ledger cash-out' : 'الصرف في الدفتر'); ?></div><div style="font-size:1.8rem; font-weight:900; color:#fbbf24;"><?php echo number_format((float)$ledgerCashOut, 2); ?></div></div>
    </div>
<?php
$renderTable = static function (string $title, array $headers, array $rows, callable $renderer, int $colspan) use ($isEnglish) {
    echo '<div style="background:#1a1a1a; padding:20px; border-radius:14px; border:1px solid #333; margin-bottom:18px; overflow:auto;">';
    echo '<h3 style="margin-top:0; color:var(--gold);">' . app_h($title) . '</h3><table style="width:100%; border-collapse:collapse; color:#fff;"><thead><tr style="border-bottom:2px solid #333; color:var(--gold);">';
    foreach ($headers as $header) echo '<th style="padding:10px; text-align:right;">' . app_h($header) . '</th>';
    echo '</tr></thead><tbody>';
    if ($rows) foreach ($rows as $row) $renderer($row); else echo '<tr><td colspan="' . $colspan . '" style="text-align:center; padding:30px; color:#777;">' . app_h($isEnglish ? 'No rows for current filters.' : 'لا توجد صفوف للمرشحات الحالية.') . '</td></tr>';
    echo '</tbody></table></div>';
};
$renderTable($isEnglish ? 'By document' : 'حسب المستند', [$isEnglish ? 'Document type' : 'نوع المستند', $isEnglish ? 'Reference' : 'المرجع', $isEnglish ? 'Counterparty' : 'الطرف', $isEnglish ? 'Receipts' : 'الحركات النقدية', $isEnglish ? 'Ledger' : 'الدفتر', $isEnglish ? 'Difference' : 'الفرق', $isEnglish ? 'Reason' : 'السبب'], $documentDiffs, static function ($row) use ($formatDocType) { $diff = round((float)$row['receipt_total'] - (float)$row['ledger_total'], 2); echo '<tr style="border-bottom:1px solid #333;"><td style="padding:12px;">' . app_h($formatDocType((string)$row['document_type'])) . '</td><td style="padding:12px; font-weight:700;">' . (!empty($row['drill_url']) ? '<a href="' . app_h((string)$row['drill_url']) . '" style="color:#93c5fd; text-decoration:none;">' . app_h((string)$row['document_ref']) . '</a>' : app_h((string)$row['document_ref'])) . '</td><td style="padding:12px;">' . app_h((string)($row['counterparty_name'] ?: '-')) . '</td><td style="padding:12px;">' . number_format((float)$row['receipt_total'], 2) . '</td><td style="padding:12px;">' . number_format((float)$row['ledger_total'], 2) . '</td><td style="padding:12px; color:' . (abs($diff) > 0.009 ? '#f87171' : '#22c55e') . '; font-weight:800;">' . number_format($diff, 2) . '</td><td style="padding:12px; color:#d1d5db;">' . app_h((string)$row['reason']) . '</td></tr>'; }, 7);
$renderTable($isEnglish ? 'By counterparty' : 'حسب العميل/المورد', [$isEnglish ? 'Type' : 'النوع', $isEnglish ? 'Name' : 'الاسم', $isEnglish ? 'Receipts' : 'الحركات النقدية', $isEnglish ? 'Ledger' : 'الدفتر', $isEnglish ? 'Difference' : 'الفرق', $isEnglish ? 'Reason' : 'السبب'], $counterpartyDiffs, static function ($row) use ($formatCounterpartyType) { $diff = round((float)$row['receipt_total'] - (float)$row['ledger_total'], 2); echo '<tr style="border-bottom:1px solid #333;"><td style="padding:12px;">' . app_h($formatCounterpartyType((string)$row['counterparty_type'])) . '</td><td style="padding:12px; font-weight:700;">' . (!empty($row['drill_url']) ? '<a href="' . app_h((string)$row['drill_url']) . '" style="color:#93c5fd; text-decoration:none;">' . app_h((string)$row['counterparty_name']) . '</a>' : app_h((string)$row['counterparty_name'])) . '</td><td style="padding:12px;">' . number_format((float)$row['receipt_total'], 2) . '</td><td style="padding:12px;">' . number_format((float)$row['ledger_total'], 2) . '</td><td style="padding:12px; color:' . (abs($diff) > 0.009 ? '#f87171' : '#22c55e') . '; font-weight:800;">' . number_format($diff, 2) . '</td><td style="padding:12px; color:#d1d5db;">' . app_h((string)$row['reason']) . '</td></tr>'; }, 6);
$renderTable($isEnglish ? 'By payroll sheet' : 'حسب كشف الرواتب', [$isEnglish ? 'Payroll no.' : 'رقم المسير', $isEnglish ? 'Month' : 'الشهر', $isEnglish ? 'Employee' : 'الموظف', $isEnglish ? 'Status' : 'الحالة', $isEnglish ? 'Receipts' : 'التخصيصات النقدية', $isEnglish ? 'Ledger' : 'الدفتر', $isEnglish ? 'Difference' : 'الفرق', $isEnglish ? 'Reason' : 'السبب'], $payrollDiffs, static function ($row) { $diff = round((float)$row['receipt_total'] - (float)$row['ledger_total'], 2); echo '<tr style="border-bottom:1px solid #333;"><td style="padding:12px; font-weight:700;">' . (!empty($row['drill_url']) ? '<a href="' . app_h((string)$row['drill_url']) . '" style="color:#93c5fd; text-decoration:none;">' . app_h((string)$row['payroll_number']) . '</a>' : app_h((string)$row['payroll_number'])) . '</td><td style="padding:12px;">' . app_h((string)$row['month_year']) . '</td><td style="padding:12px;">' . app_h((string)($row['employee_name'] ?: '-')) . '</td><td style="padding:12px;">' . app_h((string)($row['status'] ?: '-')) . '</td><td style="padding:12px;">' . number_format((float)$row['receipt_total'], 2) . '</td><td style="padding:12px;">' . number_format((float)$row['ledger_total'], 2) . '</td><td style="padding:12px; color:' . (abs($diff) > 0.009 ? '#f87171' : '#22c55e') . '; font-weight:800;">' . number_format($diff, 2) . '</td><td style="padding:12px; color:#d1d5db;">' . app_h((string)$row['reason']) . '</td></tr>'; }, 8);
$renderTable($isEnglish ? 'By employee advance' : 'حسب سلف الموظفين', [$isEnglish ? 'Employee' : 'الموظف', $isEnglish ? 'Receipts' : 'التخصيصات النقدية', $isEnglish ? 'Ledger' : 'الدفتر', $isEnglish ? 'Difference' : 'الفرق', $isEnglish ? 'Reason' : 'السبب'], $loanAdvanceDiffs, static function ($row) { $diff = round((float)$row['receipt_total'] - (float)$row['ledger_total'], 2); echo '<tr style="border-bottom:1px solid #333;"><td style="padding:12px; font-weight:700;">' . (!empty($row['drill_url']) ? '<a href="' . app_h((string)$row['drill_url']) . '" style="color:#93c5fd; text-decoration:none;">' . app_h((string)$row['employee_name']) . '</a>' : app_h((string)$row['employee_name'])) . '</td><td style="padding:12px;">' . number_format((float)$row['receipt_total'], 2) . '</td><td style="padding:12px;">' . number_format((float)$row['ledger_total'], 2) . '</td><td style="padding:12px; color:' . (abs($diff) > 0.009 ? '#f87171' : '#22c55e') . '; font-weight:800;">' . number_format($diff, 2) . '</td><td style="padding:12px; color:#d1d5db;">' . app_h((string)$row['reason']) . '</td></tr>'; }, 5);
$renderTable($isEnglish ? 'Tax settlement reconciliation' : 'مطابقة الضرائب', [$isEnglish ? 'Tax law' : 'القانون الضريبي', $isEnglish ? 'Receipts' : 'سندات السداد', $isEnglish ? 'Ledger' : 'الدفتر', $isEnglish ? 'Difference' : 'الفرق', $isEnglish ? 'Reason' : 'السبب'], $taxDiffs, static function ($row) use ($formatTaxLaw) { $diff = round((float)$row['receipt_total'] - (float)$row['ledger_total'], 2); echo '<tr style="border-bottom:1px solid #333;"><td style="padding:12px; font-weight:700;">' . app_h($formatTaxLaw((string)$row['tax_law_key'])) . '</td><td style="padding:12px;">' . number_format((float)$row['receipt_total'], 2) . '</td><td style="padding:12px;">' . number_format((float)$row['ledger_total'], 2) . '</td><td style="padding:12px; color:' . (abs($diff) > 0.009 ? '#f87171' : '#22c55e') . '; font-weight:800;">' . number_format($diff, 2) . '</td><td style="padding:12px; color:#d1d5db;">' . app_h((string)$row['reason']) . '</td></tr>'; }, 5);
$renderTable($isEnglish ? 'Manual / general settlements' : 'التسويات والقيود اليدوية', [$isEnglish ? 'Category' : 'الفئة', $isEnglish ? 'Receipts' : 'الحركات', $isEnglish ? 'Ledger' : 'الدفتر', $isEnglish ? 'Difference' : 'الفرق', $isEnglish ? 'Reason' : 'السبب'], $manualDiffs, static function ($row) use ($formatManualKey) { $diff = round((float)$row['receipt_total'] - (float)$row['ledger_total'], 2); echo '<tr style="border-bottom:1px solid #333;"><td style="padding:12px; font-weight:700;">' . app_h($formatManualKey((string)$row['category_key'])) . '</td><td style="padding:12px;">' . number_format((float)$row['receipt_total'], 2) . '</td><td style="padding:12px;">' . number_format((float)$row['ledger_total'], 2) . '</td><td style="padding:12px; color:' . (abs($diff) > 0.009 ? '#f87171' : '#22c55e') . '; font-weight:800;">' . number_format($diff, 2) . '</td><td style="padding:12px; color:#d1d5db;">' . app_h((string)$row['reason']) . '</td></tr>'; }, 5);
?>
</div>
<?php require 'footer.php'; ob_end_flush(); ?>
