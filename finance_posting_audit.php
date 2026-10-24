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
        . app_h($isEnglish ? 'You are not authorized to access posting audit.' : 'غير مصرح لك بالدخول إلى تدقيق الترحيل المالي.')
        . "</div></div>";
    require 'footer.php';
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && in_array((string)($_POST['action'] ?? ''), ['repair_issue', 'bulk_repair_section'], true)) {
    if (!app_user_can('finance.transactions.update')) {
        $_SESSION['flash_error'] = app_tr('لا تملك صلاحية تنفيذ معالجة الترحيل.', 'You do not have permission to run posting remediation.');
    } elseif (!app_verify_csrf($_POST['_token'] ?? '')) {
        $_SESSION['flash_error'] = app_tr('رمز التحقق غير صالح. حدّث الصفحة وحاول مجددًا.', 'Invalid verification token. Refresh the page and try again.');
    } else {
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'repair_issue') {
            $repair = function_exists('financeRepairPostingAuditIssue')
                ? financeRepairPostingAuditIssue(
                    $conn,
                    (string)($_POST['section_key'] ?? ''),
                    (int)($_POST['source_id'] ?? 0),
                    (int)($_POST['ledger_id'] ?? 0),
                    (string)($_SESSION['name'] ?? 'System')
                )
                : ['ok' => false, 'message' => app_tr('محرك المعالجة غير متاح حاليًا.', 'Remediation engine is currently unavailable.')];
            if (!empty($repair['ok'])) {
                $_SESSION['flash_success'] = app_tr('تم تنفيذ المعالجة وإعادة فحص هذا المسار ممكن الآن.', 'Remediation was executed. You can now re-run this audit path.');
            } else {
                $_SESSION['flash_error'] = (string)($repair['message'] ?? app_tr('تعذرت معالجة المشكلة المحددة.', 'Could not repair the selected issue.'));
            }
        } else {
            $bulk = function_exists('financeRepairPostingAuditSectionBulk')
                ? financeRepairPostingAuditSectionBulk(
                    $conn,
                    (string)($_POST['section_key'] ?? ''),
                    (string)($_POST['date_from'] ?? date('Y-m-01')),
                    (string)($_POST['date_to'] ?? date('Y-m-t')),
                    (string)($_SESSION['name'] ?? 'System'),
                    200
                )
                : ['ok' => false, 'message' => app_tr('محرك المعالجة الجماعية غير متاح حاليًا.', 'Bulk remediation engine is currently unavailable.')];
            if (!empty($bulk['ok'])) {
                $_SESSION['flash_success'] = app_tr(
                    'تم تنفيذ المعالجة الجماعية الآمنة. تمت معالجة ' . (int)($bulk['repaired_count'] ?? 0) . ' صف، وفشل ' . (int)($bulk['failed_count'] ?? 0) . '، والمتبقي ' . (int)($bulk['remaining_count'] ?? 0) . '.',
                    'Safe bulk remediation completed. Repaired ' . (int)($bulk['repaired_count'] ?? 0) . ', failed ' . (int)($bulk['failed_count'] ?? 0) . ', remaining ' . (int)($bulk['remaining_count'] ?? 0) . '.'
                );
                $_SESSION['flash_posting_run_id'] = (int)($bulk['run_id'] ?? 0);
            } else {
                $_SESSION['flash_error'] = (string)($bulk['message'] ?? app_tr('تعذرت المعالجة الجماعية المحددة.', 'Could not run the requested bulk remediation.'));
            }
        }
    }
    $redirectQuery = [
        'date_from' => (string)($_POST['date_from'] ?? date('Y-m-01')),
        'date_to' => (string)($_POST['date_to'] ?? date('Y-m-t')),
        'issues_only' => (string)($_POST['issues_only'] ?? '1'),
        'section_key' => (string)($_POST['filter_section_key'] ?? ''),
    ];
    header('Location: finance_posting_audit.php?' . http_build_query($redirectQuery));
    exit;
}

financeEnsureLedgerSchema($conn);
financeEnsureAllocationSchema($conn);
if (function_exists('financeEnsureSalesReturnSchema')) {
    financeEnsureSalesReturnSchema($conn);
}
if (function_exists('financeEnsurePurchaseReturnFinanceSchema')) {
    financeEnsurePurchaseReturnFinanceSchema($conn);
}
require 'header.php';

$dateFrom = (string)($_GET['date_from'] ?? date('Y-m-01'));
$dateTo = (string)($_GET['date_to'] ?? date('Y-m-t'));
$issuesOnly = !isset($_GET['issues_only']) || (string)$_GET['issues_only'] === '1';
$sectionFilter = strtolower(trim((string)($_GET['section_key'] ?? '')));
$csrfToken = app_csrf_token();
$flashSuccess = (string)($_SESSION['flash_success'] ?? '');
$flashError = (string)($_SESSION['flash_error'] ?? '');
$flashPostingRunId = (int)($_SESSION['flash_posting_run_id'] ?? 0);
unset($_SESSION['flash_success'], $_SESSION['flash_error'], $_SESSION['flash_posting_run_id']);
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) $dateFrom = date('Y-m-01');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) $dateTo = date('Y-m-t');
if ($dateFrom > $dateTo) { $tmp = $dateFrom; $dateFrom = $dateTo; $dateTo = $tmp; }

$sections = [];
$summary = ['ok' => 0, 'issues' => 0];
$bulkSafeSections = ['sales_invoice_issue','purchase_invoice_issue','receipt_posting','sales_invoice_void','purchase_invoice_void','sales_return_issue','purchase_return_issue','sales_return_void','purchase_return_void'];

$collectRows = static function(mysqli $conn, string $sql, string $types, array $params, callable $map): array {
    $rows = [];
    if ($stmt = $conn->prepare($sql)) {
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $rows[] = $map($row);
        }
        $stmt->close();
    }
    return $rows;
};

$normalizeIssue = static function(array $row): array {
    $sourceExists = (int)($row['source_exists'] ?? 0) > 0;
    $ledgerExists = (int)($row['ledger_exists'] ?? 0) > 0;
    $status = $sourceExists && $ledgerExists ? 'ok' : 'issue';
    if ($sourceExists && !$ledgerExists) {
        $reason = (string)($row['issue_reason'] ?? app_tr('المستند موجود لكن قيد الترحيل المتوقع مفقود.', 'Source document exists but the expected posting is missing.'));
    } elseif (!$sourceExists && $ledgerExists) {
        $reason = (string)($row['issue_reason'] ?? app_tr('يوجد قيد مرحل بلا مستند تشغيلي مطابق.', 'A posted ledger entry exists without a matching source document.'));
    } else {
        $reason = (string)($row['ok_reason'] ?? app_tr('مطابق', 'Matched'));
    }
    $row['status'] = $status;
    $row['reason'] = $reason;
    return $row;
};

$sections['sales_invoice_issue'] = [
    'title' => $isEnglish ? 'Sales invoice issue posting' : 'ترحيل إصدار فواتير المبيعات',
    'rows' => $collectRows(
        $conn,
        "SELECT CONCAT('sales:', i.id) AS audit_key, i.id AS source_id, IFNULL(i.invoice_number, CONCAT('#', i.id)) AS ref_label, COALESCE(i.inv_date, CURDATE()) AS doc_date, IFNULL(c.name, CONCAT('Client #', i.client_id)) AS party_name, IFNULL(i.total_amount,0) AS amount, 1 AS source_exists, CASE WHEN EXISTS (SELECT 1 FROM finance_ledger_entries l WHERE l.source_type='sales_invoice' AND l.source_id=i.id AND l.entry_type='sales_invoice_issued') THEN 1 ELSE 0 END AS ledger_exists FROM invoices i LEFT JOIN clients c ON c.id=i.client_id WHERE DATE(COALESCE(i.inv_date, CURDATE())) BETWEEN ? AND ? AND COALESCE(i.status,'') <> 'cancelled'",
        'ss',
        [$dateFrom, $dateTo],
        function(array $row) use ($normalizeIssue): array {
            $row['drill_url'] = 'edit_invoice.php?id=' . (int)$row['source_id'];
            $row['kind_label'] = app_tr('فاتورة مبيعات', 'Sales invoice');
            return $normalizeIssue($row);
        }
    ),
];

$sections['purchase_invoice_issue'] = [
    'title' => $isEnglish ? 'Purchase invoice issue posting' : 'ترحيل إصدار فواتير المشتريات',
    'rows' => $collectRows(
        $conn,
        "SELECT CONCAT('purchase:', p.id) AS audit_key, p.id AS source_id, IFNULL(p.purchase_number, CONCAT('#', p.id)) AS ref_label, COALESCE(p.inv_date, CURDATE()) AS doc_date, IFNULL(s.name, CONCAT('Supplier #', p.supplier_id)) AS party_name, IFNULL(p.total_amount,0) AS amount, 1 AS source_exists, CASE WHEN EXISTS (SELECT 1 FROM finance_ledger_entries l WHERE l.source_type='purchase_invoice' AND l.source_id=p.id AND l.entry_type='purchase_invoice_issued') THEN 1 ELSE 0 END AS ledger_exists FROM purchase_invoices p LEFT JOIN suppliers s ON s.id=p.supplier_id WHERE DATE(COALESCE(p.inv_date, CURDATE())) BETWEEN ? AND ? AND COALESCE(p.status,'') <> 'cancelled'",
        'ss',
        [$dateFrom, $dateTo],
        function(array $row) use ($normalizeIssue): array {
            $row['drill_url'] = 'print_purchase.php?id=' . (int)$row['source_id'];
            $row['kind_label'] = app_tr('فاتورة شراء', 'Purchase invoice');
            return $normalizeIssue($row);
        }
    ),
];

$sections['receipt_posting'] = [
    'title' => $isEnglish ? 'Cash receipt/payment posting' : 'ترحيل السندات النقدية',
    'rows' => $collectRows(
        $conn,
        "SELECT CONCAT('receipt:', r.id) AS audit_key, r.id AS source_id, CONCAT('#', r.id) AS ref_label, COALESCE(r.trans_date, CURDATE()) AS doc_date, CASE WHEN IFNULL(r.client_id,0) > 0 THEN IFNULL(c.name, CONCAT('Client #', r.client_id)) WHEN IFNULL(r.supplier_id,0) > 0 THEN IFNULL(s.name, CONCAT('Supplier #', r.supplier_id)) WHEN IFNULL(r.employee_id,0) > 0 THEN IFNULL(u.full_name, CONCAT('Employee #', r.employee_id)) ELSE IFNULL(r.description, '') END AS party_name, IFNULL(r.amount,0) AS amount, 1 AS source_exists, CASE WHEN EXISTS (SELECT 1 FROM finance_ledger_entries l WHERE l.source_type='financial_receipt' AND l.source_id=r.id AND l.entry_type IN ('receipt_cash_in','receipt_cash_out','tax_settlement_cash','manual_settlement_cash','opening_balance_receipt','opening_balance_payment')) THEN 1 ELSE 0 END AS ledger_exists FROM financial_receipts r LEFT JOIN clients c ON c.id=r.client_id LEFT JOIN suppliers s ON s.id=r.supplier_id LEFT JOIN users u ON u.id=r.employee_id WHERE DATE(COALESCE(r.trans_date, CURDATE())) BETWEEN ? AND ?",
        'ss',
        [$dateFrom, $dateTo],
        function(array $row) use ($normalizeIssue): array {
            $row['drill_url'] = 'finance.php';
            $row['kind_label'] = app_tr('سند مالي', 'Financial receipt');
            return $normalizeIssue($row);
        }
    ),
];

$sections['sales_return_issue'] = [
    'title' => $isEnglish ? 'Sales return posting' : 'ترحيل مردودات المبيعات',
    'rows' => $collectRows(
        $conn,
        "SELECT CONCAT('sales_return:', r.id) AS audit_key, r.id AS source_id, IFNULL(r.return_number, CONCAT('#', r.id)) AS ref_label, COALESCE(r.return_date, CURDATE()) AS doc_date, IFNULL(c.name, CONCAT('Client #', i.client_id)) AS party_name, IFNULL(r.total_amount,0) AS amount, 1 AS source_exists, CASE WHEN EXISTS (SELECT 1 FROM finance_ledger_entries l WHERE l.source_type='sales_return' AND l.source_id=r.id AND l.entry_type='sales_return_credit') THEN 1 ELSE 0 END AS ledger_exists FROM invoice_returns r LEFT JOIN invoices i ON i.id=r.invoice_id LEFT JOIN clients c ON c.id=i.client_id WHERE DATE(COALESCE(r.return_date, CURDATE())) BETWEEN ? AND ? AND IFNULL(r.is_void,0)=0 AND LOWER(TRIM(IFNULL(r.status,'posted'))) NOT IN ('void','cancelled')",
        'ss',
        [$dateFrom, $dateTo],
        function(array $row) use ($normalizeIssue): array {
            $row['drill_url'] = 'sales_returns.php';
            $row['kind_label'] = app_tr('مردود مبيعات', 'Sales return');
            return $normalizeIssue($row);
        }
    ),
];

$sections['purchase_return_issue'] = [
    'title' => $isEnglish ? 'Purchase return posting' : 'ترحيل مردودات المشتريات',
    'rows' => $collectRows(
        $conn,
        "SELECT CONCAT('purchase_return:', r.id) AS audit_key, r.id AS source_id, IFNULL(r.return_number, CONCAT('#', r.id)) AS ref_label, COALESCE(r.return_date, CURDATE()) AS doc_date, IFNULL(s.name, CONCAT('Supplier #', p.supplier_id)) AS party_name, IFNULL(r.total_amount,0) AS amount, 1 AS source_exists, CASE WHEN EXISTS (SELECT 1 FROM finance_ledger_entries l WHERE l.source_type='purchase_return' AND l.source_id=r.id AND l.entry_type='purchase_return_credit') THEN 1 ELSE 0 END AS ledger_exists FROM purchase_invoice_returns r LEFT JOIN purchase_invoices p ON p.id=r.purchase_invoice_id LEFT JOIN suppliers s ON s.id=p.supplier_id WHERE DATE(COALESCE(r.return_date, CURDATE())) BETWEEN ? AND ? AND LOWER(TRIM(IFNULL(r.status,'posted'))) NOT IN ('void','cancelled')",
        'ss',
        [$dateFrom, $dateTo],
        function(array $row) use ($normalizeIssue): array {
            $row['drill_url'] = 'purchase_returns.php';
            $row['kind_label'] = app_tr('مردود مشتريات', 'Purchase return');
            return $normalizeIssue($row);
        }
    ),
];

$sections['sales_invoice_void'] = [
    'title' => $isEnglish ? 'Sales invoice void posting' : 'ترحيل إلغاء فواتير المبيعات',
    'rows' => $collectRows(
        $conn,
        "SELECT CONCAT('sales_void:', i.id) AS audit_key, i.id AS source_id, IFNULL(i.invoice_number, CONCAT('#', i.id)) AS ref_label, COALESCE(i.inv_date, CURDATE()) AS doc_date, IFNULL(c.name, CONCAT('Client #', i.client_id)) AS party_name, IFNULL(i.total_amount,0) AS amount, 1 AS source_exists, CASE WHEN EXISTS (SELECT 1 FROM finance_ledger_entries l WHERE l.source_type='sales_invoice' AND l.source_id=i.id AND l.entry_type='sales_invoice_void') THEN 1 ELSE 0 END AS ledger_exists FROM invoices i LEFT JOIN clients c ON c.id=i.client_id WHERE DATE(COALESCE(i.inv_date, CURDATE())) BETWEEN ? AND ? AND LOWER(TRIM(COALESCE(i.status,''))) = 'cancelled'",
        'ss',
        [$dateFrom, $dateTo],
        function(array $row) use ($normalizeIssue): array {
            $row['drill_url'] = 'invoices.php?tab=sales';
            $row['kind_label'] = app_tr('إلغاء فاتورة مبيعات', 'Sales invoice void');
            return $normalizeIssue($row);
        }
    ),
];

$sections['purchase_invoice_void'] = [
    'title' => $isEnglish ? 'Purchase invoice void posting' : 'ترحيل إلغاء فواتير المشتريات',
    'rows' => $collectRows(
        $conn,
        "SELECT CONCAT('purchase_void:', p.id) AS audit_key, p.id AS source_id, IFNULL(p.purchase_number, CONCAT('#', p.id)) AS ref_label, COALESCE(p.inv_date, CURDATE()) AS doc_date, IFNULL(s.name, CONCAT('Supplier #', p.supplier_id)) AS party_name, IFNULL(p.total_amount,0) AS amount, 1 AS source_exists, CASE WHEN EXISTS (SELECT 1 FROM finance_ledger_entries l WHERE l.source_type='purchase_invoice' AND l.source_id=p.id AND l.entry_type='purchase_invoice_void') THEN 1 ELSE 0 END AS ledger_exists FROM purchase_invoices p LEFT JOIN suppliers s ON s.id=p.supplier_id WHERE DATE(COALESCE(p.inv_date, CURDATE())) BETWEEN ? AND ? AND LOWER(TRIM(COALESCE(p.status,''))) = 'cancelled'",
        'ss',
        [$dateFrom, $dateTo],
        function(array $row) use ($normalizeIssue): array {
            $row['drill_url'] = 'invoices.php?tab=purchases';
            $row['kind_label'] = app_tr('إلغاء فاتورة شراء', 'Purchase invoice void');
            return $normalizeIssue($row);
        }
    ),
];

$sections['sales_return_refund'] = [
    'title' => $isEnglish ? 'Sales return refund posting' : 'ترحيل رد مبالغ مردودات المبيعات',
    'rows' => $collectRows(
        $conn,
        "SELECT CONCAT('sales_return_refund:', r.id) AS audit_key, r.id AS source_id, IFNULL(r.return_number, CONCAT('#', r.id)) AS ref_label, COALESCE(fr.trans_date, r.return_date, CURDATE()) AS doc_date, IFNULL(c.name, CONCAT('Client #', i.client_id)) AS party_name, IFNULL(r.refund_amount,0) AS amount, 1 AS source_exists, CASE WHEN EXISTS (SELECT 1 FROM finance_ledger_entries l WHERE l.source_type='sales_return_refund' AND l.source_id=r.id AND l.entry_type='client_refund_cash') THEN 1 ELSE 0 END AS ledger_exists FROM invoice_returns r LEFT JOIN invoices i ON i.id=r.invoice_id LEFT JOIN clients c ON c.id=i.client_id LEFT JOIN financial_receipts fr ON fr.id = r.refund_receipt_id WHERE DATE(COALESCE(fr.trans_date, r.return_date, CURDATE())) BETWEEN ? AND ? AND IFNULL(r.refund_amount,0) > 0.009",
        'ss',
        [$dateFrom, $dateTo],
        function(array $row) use ($normalizeIssue): array {
            $row['drill_url'] = 'sales_returns.php';
            $row['kind_label'] = app_tr('رد مبلغ مردود مبيعات', 'Sales return refund');
            return $normalizeIssue($row);
        }
    ),
];

$sections['purchase_return_refund'] = [
    'title' => $isEnglish ? 'Purchase return refund posting' : 'ترحيل رد مبالغ مردودات المشتريات',
    'rows' => $collectRows(
        $conn,
        "SELECT CONCAT('purchase_return_refund:', r.id) AS audit_key, r.id AS source_id, IFNULL(r.return_number, CONCAT('#', r.id)) AS ref_label, COALESCE(fr.trans_date, r.return_date, CURDATE()) AS doc_date, IFNULL(s.name, CONCAT('Supplier #', p.supplier_id)) AS party_name, IFNULL(r.refund_amount,0) AS amount, 1 AS source_exists, CASE WHEN EXISTS (SELECT 1 FROM finance_ledger_entries l WHERE l.source_type='purchase_return_refund' AND l.source_id=r.id AND l.entry_type='supplier_refund_cash') THEN 1 ELSE 0 END AS ledger_exists FROM purchase_invoice_returns r LEFT JOIN purchase_invoices p ON p.id=r.purchase_invoice_id LEFT JOIN suppliers s ON s.id=p.supplier_id LEFT JOIN financial_receipts fr ON fr.id = r.refund_receipt_id WHERE DATE(COALESCE(fr.trans_date, r.return_date, CURDATE())) BETWEEN ? AND ? AND IFNULL(r.refund_amount,0) > 0.009",
        'ss',
        [$dateFrom, $dateTo],
        function(array $row) use ($normalizeIssue): array {
            $row['drill_url'] = 'purchase_returns.php';
            $row['kind_label'] = app_tr('رد مبلغ مردود مشتريات', 'Purchase return refund');
            return $normalizeIssue($row);
        }
    ),
];

$sections['sales_return_void'] = [
    'title' => $isEnglish ? 'Sales return void posting' : 'ترحيل إلغاء مردودات المبيعات',
    'rows' => $collectRows(
        $conn,
        "SELECT CONCAT('sales_return_void:', r.id) AS audit_key, r.id AS source_id, IFNULL(r.return_number, CONCAT('#', r.id)) AS ref_label, COALESCE(r.return_date, CURDATE()) AS doc_date, IFNULL(c.name, CONCAT('Client #', i.client_id)) AS party_name, IFNULL(r.total_amount,0) AS amount, 1 AS source_exists, CASE WHEN EXISTS (SELECT 1 FROM finance_ledger_entries l WHERE l.source_type='sales_return' AND l.source_id=r.id AND l.entry_type='sales_return_void') THEN 1 ELSE 0 END AS ledger_exists FROM invoice_returns r LEFT JOIN invoices i ON i.id=r.invoice_id LEFT JOIN clients c ON c.id=i.client_id WHERE DATE(COALESCE(r.return_date, CURDATE())) BETWEEN ? AND ? AND (IFNULL(r.is_void,0)=1 OR LOWER(TRIM(IFNULL(r.status,'posted'))) IN ('void','cancelled'))",
        'ss',
        [$dateFrom, $dateTo],
        function(array $row) use ($normalizeIssue): array {
            $row['drill_url'] = 'sales_returns.php';
            $row['kind_label'] = app_tr('إلغاء مردود مبيعات', 'Sales return void');
            return $normalizeIssue($row);
        }
    ),
];

$sections['purchase_return_void'] = [
    'title' => $isEnglish ? 'Purchase return void posting' : 'ترحيل إلغاء مردودات المشتريات',
    'rows' => $collectRows(
        $conn,
        "SELECT CONCAT('purchase_return_void:', r.id) AS audit_key, r.id AS source_id, IFNULL(r.return_number, CONCAT('#', r.id)) AS ref_label, COALESCE(r.return_date, CURDATE()) AS doc_date, IFNULL(s.name, CONCAT('Supplier #', p.supplier_id)) AS party_name, IFNULL(r.total_amount,0) AS amount, 1 AS source_exists, CASE WHEN EXISTS (SELECT 1 FROM finance_ledger_entries l WHERE l.source_type='purchase_return' AND l.source_id=r.id AND l.entry_type='purchase_return_void') THEN 1 ELSE 0 END AS ledger_exists FROM purchase_invoice_returns r LEFT JOIN purchase_invoices p ON p.id=r.purchase_invoice_id LEFT JOIN suppliers s ON s.id=p.supplier_id WHERE DATE(COALESCE(r.return_date, CURDATE())) BETWEEN ? AND ? AND LOWER(TRIM(IFNULL(r.status,'posted'))) IN ('void','cancelled')",
        'ss',
        [$dateFrom, $dateTo],
        function(array $row) use ($normalizeIssue): array {
            $row['drill_url'] = 'purchase_returns.php';
            $row['kind_label'] = app_tr('إلغاء مردود مشتريات', 'Purchase return void');
            return $normalizeIssue($row);
        }
    ),
];

$sections['orphan_ledger'] = [
    'title' => $isEnglish ? 'Orphan ledger entries' : 'قيود دفتر يتيمة',
    'rows' => $collectRows(
        $conn,
        "SELECT CONCAT(IFNULL(l.source_type,''), ':', l.source_id, ':', l.id) AS audit_key, l.id AS ledger_id, l.source_id, IFNULL(NULLIF(l.reference,''), CONCAT('#', l.source_id)) AS ref_label, COALESCE(l.trans_date, CURDATE()) AS doc_date, CASE WHEN l.counterparty_type='client' THEN IFNULL(c.name, CONCAT('Client #', l.counterparty_id)) WHEN l.counterparty_type='supplier' THEN IFNULL(s.name, CONCAT('Supplier #', l.counterparty_id)) WHEN l.counterparty_type='employee' THEN IFNULL(u.full_name, CONCAT('Employee #', l.counterparty_id)) ELSE IFNULL(l.source_type, '') END AS party_name, IFNULL(l.amount,0) AS amount, 0 AS source_exists, 1 AS ledger_exists, IFNULL(l.source_type,'') AS source_type, IFNULL(l.entry_type,'') AS entry_type FROM finance_ledger_entries l LEFT JOIN clients c ON c.id = l.counterparty_id AND l.counterparty_type='client' LEFT JOIN suppliers s ON s.id = l.counterparty_id AND l.counterparty_type='supplier' LEFT JOIN users u ON u.id = l.counterparty_id AND l.counterparty_type='employee' WHERE DATE(COALESCE(l.trans_date, CURDATE())) BETWEEN ? AND ? AND ((l.source_type='sales_invoice' AND NOT EXISTS (SELECT 1 FROM invoices i WHERE i.id=l.source_id)) OR (l.source_type='purchase_invoice' AND NOT EXISTS (SELECT 1 FROM purchase_invoices p WHERE p.id=l.source_id)) OR (l.source_type='financial_receipt' AND NOT EXISTS (SELECT 1 FROM financial_receipts r WHERE r.id=l.source_id)) OR (l.source_type='financial_receipt_allocation' AND NOT EXISTS (SELECT 1 FROM financial_receipts ra WHERE ra.id=l.source_id)) OR (l.source_type='sales_return' AND NOT EXISTS (SELECT 1 FROM invoice_returns sr WHERE sr.id=l.source_id)) OR (l.source_type='purchase_return' AND NOT EXISTS (SELECT 1 FROM purchase_invoice_returns pr WHERE pr.id=l.source_id)) OR (l.source_type='sales_return_refund' AND NOT EXISTS (SELECT 1 FROM invoice_returns srr WHERE srr.id=l.source_id)) OR (l.source_type='purchase_return_refund' AND NOT EXISTS (SELECT 1 FROM purchase_invoice_returns prr WHERE prr.id=l.source_id)) OR (l.source_type NOT IN ('sales_invoice','purchase_invoice','financial_receipt','financial_receipt_allocation','sales_return','purchase_return','sales_return_refund','purchase_return_refund')))",
        'ss',
        [$dateFrom, $dateTo],
        function(array $row) use ($normalizeIssue, $isEnglish): array {
            $sourceType = (string)($row['source_type'] ?? '');
            $row['kind_label'] = match ($sourceType) {
                'sales_invoice' => app_tr('قيد يتيم لفاتورة مبيعات', 'Orphan sales invoice entry'),
                'purchase_invoice' => app_tr('قيد يتيم لفاتورة شراء', 'Orphan purchase invoice entry'),
                'financial_receipt' => app_tr('قيد يتيم لسند مالي', 'Orphan financial receipt entry'),
                'financial_receipt_allocation' => app_tr('قيد يتيم لتخصيص مالي', 'Orphan allocation entry'),
                'sales_return' => app_tr('قيد يتيم لمردود مبيعات', 'Orphan sales return entry'),
                'purchase_return' => app_tr('قيد يتيم لمردود مشتريات', 'Orphan purchase return entry'),
                'sales_return_refund' => app_tr('قيد يتيم لرد مبلغ عميل', 'Orphan client refund entry'),
                'purchase_return_refund' => app_tr('قيد يتيم لرد مبلغ مورد', 'Orphan supplier refund entry'),
                default => app_tr('قيد يتيم بمصدر غير معروف', 'Orphan entry with unknown source'),
            };
            $row['drill_url'] = match ($sourceType) {
                'sales_invoice' => 'invoices.php?tab=sales',
                'purchase_invoice' => 'invoices.php?tab=purchases',
                'financial_receipt', 'financial_receipt_allocation' => 'finance.php',
                'sales_return', 'sales_return_refund' => 'sales_returns.php',
                'purchase_return', 'purchase_return_refund' => 'purchase_returns.php',
                default => 'finance_ledger.php',
            };
            $row['issue_reason'] = in_array($sourceType, ['sales_invoice','purchase_invoice','financial_receipt','financial_receipt_allocation','sales_return','purchase_return','sales_return_refund','purchase_return_refund'], true)
                ? app_tr('يوجد قيد دفتر ولكن المصدر التشغيلي المرتبط به مفقود أو محذوف.', 'Ledger entry exists but the linked operational source is missing or deleted.')
                : app_tr('يوجد قيد دفتر بنوع مصدر غير مدعوم في طبقة التدقيق الحالية.', 'Ledger entry exists with a source type not supported by the current audit layer.');
            return $normalizeIssue($row);
        }
    ),
];

$sectionOptions = [];
foreach ($sections as $sectionKey => $sectionMeta) {
    $sectionOptions[(string)$sectionKey] = (string)($sectionMeta['title'] ?? (string)$sectionKey);
}
if ($sectionFilter !== '' && !isset($sections[$sectionFilter])) {
    $sectionFilter = '';
}
if ($sectionFilter !== '') {
    $sections = [$sectionFilter => $sections[$sectionFilter]];
}

$bulkPreviewCounts = [];
foreach ($bulkSafeSections as $bulkKey) {
    $bulkPreviewCounts[$bulkKey] = function_exists('financePreviewPostingAuditSectionBulk')
        ? financePreviewPostingAuditSectionBulk($conn, $bulkKey, $dateFrom, $dateTo, 200)
        : ['ok' => false, 'total_count' => 0, 'repair_count' => 0, 'limit' => 200];
}

foreach ($sections as $key => $section) {
    $rows = [];
    foreach ($section['rows'] as $row) {
        if ($row['status'] === 'issue') $summary['issues']++;
        else $summary['ok']++;
        if ($issuesOnly && $row['status'] !== 'issue') continue;
        $rows[] = $row;
    }
    usort($rows, static function(array $a, array $b): int {
        return strcmp((string)$a['doc_date'], (string)$b['doc_date']) * -1;
    });
    $sections[$key]['rows'] = $rows;
}
?>
<div class="container" style="margin-top:30px;">
    <div style="display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap; margin-bottom:20px;">
        <h2 style="margin:0; color:var(--gold);"><?php echo app_h($isEnglish ? 'Posting Audit' : 'تدقيق الترحيل المالي'); ?></h2>
        <div style="display:flex; gap:10px; flex-wrap:wrap;">
            <a href="finance_ledger.php" style="background:#123b63; color:#fff; padding:10px 18px; text-decoration:none; border-radius:10px; font-weight:700;"><?php echo app_h($isEnglish ? 'Open ledger' : 'دفتر الحركة'); ?></a>
            <a href="finance_reconciliation.php" style="background:#0f3d2e; color:#fff; padding:10px 18px; text-decoration:none; border-radius:10px; font-weight:700;"><?php echo app_h($isEnglish ? 'Reconciliation' : 'مطابقة القيود'); ?></a>
            <a href="finance_posting_audit_runs.php" style="background:#5b3a12; color:#fff; padding:10px 18px; text-decoration:none; border-radius:10px; font-weight:700;"><?php echo app_h($isEnglish ? 'Bulk run reports' : 'تقارير التشغيل الجماعي'); ?></a>
            <a href="finance_reports.php" style="background:#1f2937; color:#fff; padding:10px 18px; text-decoration:none; border-radius:10px; font-weight:700;"><?php echo app_h($isEnglish ? 'Back to finance reports' : 'الرجوع للتقارير المالية'); ?></a>
        </div>
    </div>

    <?php if ($flashSuccess !== ''): ?>
        <div style="background:#0f2c1f; border:1px solid #1f6b49; color:#bbf7d0; border-radius:14px; padding:14px 18px; margin-bottom:18px; font-weight:700;">
            <?php echo app_h($flashSuccess); ?>
        </div>
    <?php endif; ?>
    <?php if ($flashError !== ''): ?>
        <div style="background:#2b1111; border:1px solid #7f1d1d; color:#fecaca; border-radius:14px; padding:14px 18px; margin-bottom:18px; font-weight:700;">
            <?php echo app_h($flashError); ?>
        </div>
    <?php endif; ?>
    <?php if ($flashPostingRunId > 0): ?>
        <div style="margin-bottom:18px;">
            <a href="finance_posting_audit_runs.php?run_id=<?php echo (int)$flashPostingRunId; ?>" style="display:inline-flex; align-items:center; gap:8px; background:#1f2937; color:#fff; padding:10px 14px; text-decoration:none; border-radius:10px; font-weight:800;">
                <?php echo app_h($isEnglish ? 'Open bulk run report' : 'فتح تقرير التشغيل الجماعي'); ?> #<?php echo (int)$flashPostingRunId; ?>
            </a>
        </div>
    <?php endif; ?>
    <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(180px,1fr)); gap:12px; margin-bottom:18px;">
        <div style="background:#151515; border:1px solid #333; border-radius:14px; padding:18px;">
            <div style="color:#aaa; margin-bottom:8px;"><?php echo app_h($isEnglish ? 'Issues' : 'المشكلات'); ?></div>
            <div style="font-size:30px; font-weight:900; color:#f87171;"><?php echo (int)$summary['issues']; ?></div>
        </div>
        <div style="background:#151515; border:1px solid #333; border-radius:14px; padding:18px;">
            <div style="color:#aaa; margin-bottom:8px;"><?php echo app_h($isEnglish ? 'Matched checks' : 'الفحوصات المطابقة'); ?></div>
            <div style="font-size:30px; font-weight:900; color:#34d399;"><?php echo (int)$summary['ok']; ?></div>
        </div>
    </div>

    <div style="background:#151515; border:1px solid #333; border-radius:16px; padding:18px; margin-bottom:18px;">
        <form method="GET" style="display:grid; grid-template-columns:repeat(auto-fit,minmax(180px,1fr)); gap:12px; align-items:end;">
            <div>
                <label style="display:block; margin-bottom:8px; color:#aaa;"><?php echo app_h($isEnglish ? 'Date from' : 'من تاريخ'); ?></label>
                <input type="date" name="date_from" value="<?php echo app_h($dateFrom); ?>" style="width:100%; background:#090909; border:1px solid #444; color:#fff; padding:12px; border-radius:8px;">
            </div>
            <div>
                <label style="display:block; margin-bottom:8px; color:#aaa;"><?php echo app_h($isEnglish ? 'Date to' : 'إلى تاريخ'); ?></label>
                <input type="date" name="date_to" value="<?php echo app_h($dateTo); ?>" style="width:100%; background:#090909; border:1px solid #444; color:#fff; padding:12px; border-radius:8px;">
            </div>
            <div>
                <label style="display:block; margin-bottom:8px; color:#aaa;"><?php echo app_h($isEnglish ? 'Section' : 'القسم'); ?></label>
                <select name="section_key" style="width:100%; background:#090909; border:1px solid #444; color:#fff; padding:12px; border-radius:8px;">
                    <option value=""><?php echo app_h($isEnglish ? 'All sections' : 'كل الأقسام'); ?></option>
                    <?php foreach ($sectionOptions as $optionKey => $optionLabel): ?>
                        <option value="<?php echo app_h((string)$optionKey); ?>" <?php echo $sectionFilter === (string)$optionKey ? 'selected' : ''; ?>>
                            <?php echo app_h((string)$optionLabel); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="display:flex; align-items:center; gap:10px; padding-top:28px;">
                <label style="display:flex; align-items:center; gap:8px; color:#ddd; font-weight:700;">
                    <input type="checkbox" name="issues_only" value="1" <?php echo $issuesOnly ? 'checked' : ''; ?>>
                    <?php echo app_h($isEnglish ? 'Issues only' : 'المشكلات فقط'); ?>
                </label>
            </div>
            <div>
                <button type="submit" style="width:100%; background:linear-gradient(135deg,#e5c75a,#b98713); color:#141414; border:none; padding:12px; border-radius:8px; font-weight:800;"><?php echo app_h($isEnglish ? 'Run audit' : 'تشغيل التدقيق'); ?></button>
            </div>
        </form>
    </div>

    <?php foreach ($sections as $key => $section): ?>
        <div style="background:#1a1a1a; padding:20px; border-radius:14px; border:1px solid #333; overflow:auto; margin-bottom:18px;">
            <div style="display:flex; justify-content:space-between; gap:12px; align-items:center; flex-wrap:wrap; margin-bottom:14px;">
                <h3 style="margin:0; color:var(--gold);"><?php echo app_h($section['title']); ?></h3>
                <?php if (app_user_can('finance.transactions.update') && in_array($key, $bulkSafeSections, true)): ?>
                    <?php $bulkPreview = $bulkPreviewCounts[$key] ?? ['ok' => false, 'total_count' => 0, 'repair_count' => 0, 'limit' => 200]; ?>
                    <?php $bulkTotal = (int)($bulkPreview['total_count'] ?? 0); ?>
                    <?php $bulkRepair = (int)($bulkPreview['repair_count'] ?? 0); ?>
                    <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
                        <span style="background:#111827; color:#d1d5db; border:1px solid #374151; padding:9px 12px; border-radius:999px; font-weight:700;">
                            <?php if ($bulkTotal > $bulkRepair): ?>
                                <?php echo app_h(($isEnglish ? 'Preview: ' : 'معاينة: ') . number_format($bulkTotal) . ($isEnglish ? ' rows, will repair ' : ' صف، سيتم إصلاح ') . number_format($bulkRepair)); ?>
                            <?php else: ?>
                                <?php echo app_h(($isEnglish ? 'Preview: ' : 'معاينة: ') . number_format($bulkTotal)); ?>
                            <?php endif; ?>
                        </span>
                        <form method="POST" style="margin:0;">
                            <input type="hidden" name="_token" value="<?php echo app_h($csrfToken); ?>">
                            <input type="hidden" name="action" value="bulk_repair_section">
                            <input type="hidden" name="section_key" value="<?php echo app_h((string)$key); ?>">
                            <input type="hidden" name="filter_section_key" value="<?php echo app_h($sectionFilter); ?>">
                            <input type="hidden" name="date_from" value="<?php echo app_h($dateFrom); ?>">
                            <input type="hidden" name="date_to" value="<?php echo app_h($dateTo); ?>">
                            <input type="hidden" name="issues_only" value="<?php echo $issuesOnly ? '1' : '0'; ?>">
                            <button type="submit" <?php echo $bulkRepair <= 0 ? 'disabled' : ''; ?> style="background:#3b2f0f; color:#fff; border:1px solid #7c5a12; padding:9px 12px; border-radius:8px; font-weight:800; cursor:<?php echo $bulkRepair <= 0 ? 'not-allowed' : 'pointer'; ?>; opacity:<?php echo $bulkRepair <= 0 ? '0.55' : '1'; ?>;">
                                <?php echo app_h($isEnglish ? 'Bulk safe re-post' : 'إعادة ترحيل جماعية آمنة'); ?>
                            </button>
                        </form>
                    </div>
                <?php endif; ?>
            </div>
            <table style="width:100%; border-collapse:collapse; color:#fff;">
                <thead>
                    <tr style="border-bottom:2px solid #333; color:var(--gold);">
                        <th style="padding:10px; text-align:right;"><?php echo app_h($isEnglish ? 'Type' : 'النوع'); ?></th>
                        <th style="padding:10px; text-align:right;"><?php echo app_h($isEnglish ? 'Reference' : 'المرجع'); ?></th>
                        <th style="padding:10px; text-align:right;"><?php echo app_h($isEnglish ? 'Date' : 'التاريخ'); ?></th>
                        <th style="padding:10px; text-align:right;"><?php echo app_h($isEnglish ? 'Counterparty' : 'الطرف'); ?></th>
                        <th style="padding:10px; text-align:right;"><?php echo app_h($isEnglish ? 'Amount' : 'المبلغ'); ?></th>
                        <th style="padding:10px; text-align:right;"><?php echo app_h($isEnglish ? 'Status' : 'الحالة'); ?></th>
                        <th style="padding:10px; text-align:right;"><?php echo app_h($isEnglish ? 'Reason' : 'السبب'); ?></th>
                        <th style="padding:10px; text-align:right;"><?php echo app_h($isEnglish ? 'Action' : 'الإجراء'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($section['rows']): foreach ($section['rows'] as $row): ?>
                        <tr style="border-bottom:1px solid #333;">
                            <td style="padding:12px;"><?php echo app_h((string)($row['kind_label'] ?? '')); ?></td>
                            <td style="padding:12px; font-weight:800;">
                                <?php if (!empty($row['drill_url'])): ?>
                                    <a href="<?php echo app_h((string)$row['drill_url']); ?>" style="color:#93c5fd; text-decoration:none;"><?php echo app_h((string)($row['ref_label'] ?? '')); ?></a>
                                <?php else: ?>
                                    <?php echo app_h((string)($row['ref_label'] ?? '')); ?>
                                <?php endif; ?>
                            </td>
                            <td style="padding:12px;"><?php echo app_h((string)($row['doc_date'] ?? '')); ?></td>
                            <td style="padding:12px;"><?php echo app_h((string)($row['party_name'] ?? '')); ?></td>
                            <td style="padding:12px; color:#fbbf24; font-weight:800;"><?php echo number_format((float)($row['amount'] ?? 0), 2); ?></td>
                            <td style="padding:12px; font-weight:800; color:<?php echo (($row['status'] ?? '') === 'issue') ? '#f87171' : '#34d399'; ?>;"><?php echo app_h(($row['status'] ?? '') === 'issue' ? ($isEnglish ? 'Issue' : 'مشكلة') : ($isEnglish ? 'OK' : 'مطابق')); ?></td>
                            <td style="padding:12px;"><?php echo app_h((string)($row['reason'] ?? '')); ?></td>
                            <td style="padding:12px;">
                                <?php if (($row['status'] ?? '') === 'issue' && app_user_can('finance.transactions.update')): ?>
                                    <form method="POST" style="margin:0;">
                                        <input type="hidden" name="_token" value="<?php echo app_h($csrfToken); ?>">
                                        <input type="hidden" name="action" value="repair_issue">
                                        <input type="hidden" name="section_key" value="<?php echo app_h((string)$key); ?>">
                                        <input type="hidden" name="filter_section_key" value="<?php echo app_h($sectionFilter); ?>">
                                        <input type="hidden" name="source_id" value="<?php echo (int)($row['source_id'] ?? 0); ?>">
                                        <input type="hidden" name="ledger_id" value="<?php echo (int)($row['ledger_id'] ?? 0); ?>">
                                        <input type="hidden" name="date_from" value="<?php echo app_h($dateFrom); ?>">
                                        <input type="hidden" name="date_to" value="<?php echo app_h($dateTo); ?>">
                                        <input type="hidden" name="issues_only" value="<?php echo $issuesOnly ? '1' : '0'; ?>">
                                        <button type="submit" style="background:<?php echo $key === 'orphan_ledger' ? '#7f1d1d' : '#0f3d2e'; ?>; color:#fff; border:none; padding:9px 12px; border-radius:8px; font-weight:800; cursor:pointer;">
                                            <?php echo app_h($key === 'orphan_ledger' ? ($isEnglish ? 'Delete orphan' : 'حذف القيد اليتيم') : ($isEnglish ? 'Re-post' : 'إعادة الترحيل')); ?>
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <span style="color:#666;"><?php echo app_h($isEnglish ? 'No action' : 'لا يوجد إجراء'); ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; else: ?>
                        <tr><td colspan="8" style="text-align:center; padding:26px; color:#777;"><?php echo app_h($isEnglish ? 'No rows for this section.' : 'لا توجد صفوف في هذا القسم.'); ?></td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    <?php endforeach; ?>
</div>
<?php require 'footer.php'; ob_end_flush(); ?>
