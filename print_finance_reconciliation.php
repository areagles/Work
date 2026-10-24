<?php
require 'auth.php';
require 'config.php';
require_once 'receipts_runtime.php';
app_handle_lang_switch($conn);

$isEnglish = app_current_lang($conn) === 'en';

if (!app_user_can('finance.reports.view')) { 
    http_response_code(403); 
    exit($isEnglish ? 'Unauthorized.' : 'غير مصرح.'); 
}

financeEnsureLedgerSchema($conn);
financeEnsureAllocationSchema($conn);

$appName = app_setting_get($conn, 'app_name', 'Arab Eagles');
$dateFrom = (string)($_GET['date_from'] ?? date('Y-m-01'));
$dateTo = (string)($_GET['date_to'] ?? date('Y-m-t'));
$mismatchesOnly = isset($_GET['mismatches_only']) && (string)$_GET['mismatches_only'] === '1';

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) $dateFrom = date('Y-m-01');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) $dateTo = date('Y-m-t');

$receiptIn = 0.0; 
$receiptOut = 0.0; 
$ledgerCashIn = 0.0; 
$ledgerCashOut = 0.0;

if ($stmt = $conn->prepare("SELECT IFNULL(SUM(CASE WHEN type='in' THEN amount ELSE 0 END),0), IFNULL(SUM(CASE WHEN type='out' THEN amount ELSE 0 END),0) FROM financial_receipts WHERE DATE(trans_date) BETWEEN ? AND ?")) { 
    $stmt->bind_param('ss', $dateFrom, $dateTo); 
    $stmt->execute(); 
    $stmt->bind_result($receiptIn, $receiptOut); 
    $stmt->fetch(); 
    $stmt->close(); 
}

if ($stmt = $conn->prepare("SELECT IFNULL(SUM(CASE WHEN entry_type IN ('receipt_cash_in','employee_loan_repayment_cash') THEN amount ELSE 0 END),0), IFNULL(SUM(CASE WHEN entry_type IN ('receipt_cash_out','salary_payment','employee_loan_issued') THEN amount ELSE 0 END),0) FROM finance_ledger_entries WHERE DATE(trans_date) BETWEEN ? AND ?")) { 
    $stmt->bind_param('ss', $dateFrom, $dateTo); 
    $stmt->execute(); 
    $stmt->bind_result($ledgerCashIn, $ledgerCashOut); 
    $stmt->fetch(); 
    $stmt->close(); 
}

$buildReason = static function (float $receiptTotal, float $ledgerTotal) use ($isEnglish): string { 
    $hasReceipt = abs($receiptTotal) > 0.00001; 
    $hasLedger = abs($ledgerTotal) > 0.00001; 
    
    if ($hasReceipt && !$hasLedger) return $isEnglish ? 'Cash movement exists without matching ledger posting.' : 'توجد حركة نقدية بدون قيد مطابق في الدفتر.'; 
    if (!$hasReceipt && $hasLedger) return $isEnglish ? 'Ledger posting exists without matching cash movement.' : 'يوجد قيد في الدفتر بدون حركة نقدية مقابلة.'; 
    if (round($receiptTotal, 2) !== round($ledgerTotal, 2)) return $isEnglish ? 'Partial mismatch. Review allocation or posted amount.' : 'يوجد فرق جزئي. راجع التخصيص أو المبلغ المرحل.'; 
    
    return $isEnglish ? 'Matched.' : 'مطابق.'; 
};

$mismatchFilter = static function (array $rows) use ($mismatchesOnly): array { 
    return !$mismatchesOnly ? $rows : array_filter($rows, static fn(array $row): bool => abs(round((float)$row['receipt_total'] - (float)$row['ledger_total'], 2)) > 0.009); 
};

$documentRows = [];
if ($res = $conn->query("SELECT 'sales_invoice' AS row_type, IFNULL(i.invoice_number, CONCAT('#', i.id)) AS ref, IFNULL(c.name,'') AS label, IFNULL(SUM(r.amount),0) AS receipt_total, 0 AS ledger_total FROM financial_receipts r INNER JOIN invoices i ON i.id = r.invoice_id AND r.type='in' LEFT JOIN clients c ON c.id = i.client_id WHERE DATE(r.trans_date) BETWEEN '" . $conn->real_escape_string($dateFrom) . "' AND '" . $conn->real_escape_string($dateTo) . "' GROUP BY i.id, ref, label UNION ALL SELECT 'purchase_invoice' AS row_type, IFNULL(p.purchase_number, CONCAT('#', p.id)) AS ref, IFNULL(s.name, IFNULL(p.supplier_display_name,'')) AS label, IFNULL(SUM(r.amount),0) AS receipt_total, 0 AS ledger_total FROM financial_receipts r INNER JOIN purchase_invoices p ON p.id = r.invoice_id AND r.type='out' LEFT JOIN suppliers s ON s.id = p.supplier_id WHERE DATE(r.trans_date) BETWEEN '" . $conn->real_escape_string($dateFrom) . "' AND '" . $conn->real_escape_string($dateTo) . "' GROUP BY p.id, ref, label")) { 
    while ($row = $res->fetch_assoc()) { 
        $key = (string)$row['row_type'] . ':' . (string)$row['ref']; 
        $documentRows[$key] = ['type' => (string)$row['row_type'], 'ref' => (string)$row['ref'], 'label' => (string)$row['label'], 'receipt_total' => (float)$row['receipt_total'], 'ledger_total' => 0.0]; 
    } 
}

if ($res = $conn->query("SELECT source_type, reference, IFNULL(SUM(amount),0) AS ledger_total FROM finance_ledger_entries WHERE DATE(trans_date) BETWEEN '" . $conn->real_escape_string($dateFrom) . "' AND '" . $conn->real_escape_string($dateTo) . "' AND source_type IN ('sales_invoice','purchase_invoice') GROUP BY source_type, reference")) { 
    while ($row = $res->fetch_assoc()) { 
        $key = (string)$row['source_type'] . ':' . (string)$row['reference']; 
        if (!isset($documentRows[$key])) $documentRows[$key] = ['type' => (string)$row['source_type'], 'ref' => (string)$row['reference'], 'label' => '', 'receipt_total' => 0.0, 'ledger_total' => 0.0]; 
        $documentRows[$key]['ledger_total'] += (float)$row['ledger_total']; 
    } 
}
foreach ($documentRows as $k => $row) $documentRows[$k]['reason'] = $buildReason((float)$row['receipt_total'], (float)$row['ledger_total']);
$documentRows = $mismatchFilter($documentRows);

$counterpartyRows = [];
if ($res = $conn->query("SELECT 'client' AS row_type, c.name AS label, IFNULL(SUM(r.amount),0) AS receipt_total, 0 AS ledger_total FROM financial_receipts r INNER JOIN clients c ON c.id = r.client_id WHERE DATE(r.trans_date) BETWEEN '" . $conn->real_escape_string($dateFrom) . "' AND '" . $conn->real_escape_string($dateTo) . "' GROUP BY c.id, c.name UNION ALL SELECT 'supplier' AS row_type, s.name AS label, IFNULL(SUM(r.amount),0) AS receipt_total, 0 AS ledger_total FROM financial_receipts r INNER JOIN suppliers s ON s.id = r.supplier_id WHERE DATE(r.trans_date) BETWEEN '" . $conn->real_escape_string($dateFrom) . "' AND '" . $conn->real_escape_string($dateTo) . "' GROUP BY s.id, s.name UNION ALL SELECT 'employee' AS row_type, IFNULL(u.full_name, CONCAT('#', r.employee_id)) AS label, IFNULL(SUM(r.amount),0) AS receipt_total, 0 AS ledger_total FROM financial_receipts r LEFT JOIN users u ON u.id = r.employee_id WHERE r.employee_id IS NOT NULL AND r.employee_id > 0 AND DATE(r.trans_date) BETWEEN '" . $conn->real_escape_string($dateFrom) . "' AND '" . $conn->real_escape_string($dateTo) . "' GROUP BY r.employee_id, label")) { 
    while ($row = $res->fetch_assoc()) { 
        $key = (string)$row['row_type'] . ':' . (string)$row['label']; 
        $counterpartyRows[$key] = ['type' => (string)$row['row_type'], 'label' => (string)$row['label'], 'receipt_total' => (float)$row['receipt_total'], 'ledger_total' => 0.0]; 
    } 
}

if ($res = $conn->query("SELECT counterparty_type AS row_type, counterparty_id, IFNULL(SUM(amount),0) AS ledger_total FROM finance_ledger_entries WHERE DATE(trans_date) BETWEEN '" . $conn->real_escape_string($dateFrom) . "' AND '" . $conn->real_escape_string($dateTo) . "' AND counterparty_type IN ('client','supplier','employee') GROUP BY counterparty_type, counterparty_id")) { 
    while ($row = $res->fetch_assoc()) { 
        $key = (string)$row['row_type'] . ':#' . (int)($row['counterparty_id'] ?? 0); 
        if (!isset($counterpartyRows[$key])) $counterpartyRows[$key] = ['type' => (string)$row['row_type'], 'label' => '#' . (int)($row['counterparty_id'] ?? 0), 'receipt_total' => 0.0, 'ledger_total' => 0.0]; 
        $counterpartyRows[$key]['ledger_total'] += (float)$row['ledger_total']; 
    } 
}
foreach ($counterpartyRows as $k => $row) $counterpartyRows[$k]['reason'] = $buildReason((float)$row['receipt_total'], (float)$row['ledger_total']);
$counterpartyRows = $mismatchFilter($counterpartyRows);

$payrollRows = [];
if ($res = $conn->query("SELECT IFNULL(p.payroll_number, CONCAT('#', p.id)) AS payroll_number, IFNULL(p.month_year,'') AS month_year, IFNULL(p.employee_name_snapshot, IFNULL(u.full_name,'')) AS employee_name, IFNULL(p.status,'') AS status, IFNULL(SUM(a.amount),0) AS receipt_total, 0 AS ledger_total FROM financial_receipt_allocations a INNER JOIN financial_receipts r ON r.id = a.receipt_id INNER JOIN payroll_sheets p ON p.id = a.target_id LEFT JOIN users u ON u.id = p.employee_id WHERE a.allocation_type='payroll' AND DATE(r.trans_date) BETWEEN '" . $conn->real_escape_string($dateFrom) . "' AND '" . $conn->real_escape_string($dateTo) . "' GROUP BY p.id, payroll_number, month_year, employee_name, status")) { 
    while ($row = $res->fetch_assoc()) { 
        $key = (string)$row['payroll_number']; 
        $payrollRows[$key] = ['payroll_number' => (string)$row['payroll_number'], 'month_year' => (string)$row['month_year'], 'employee_name' => (string)$row['employee_name'], 'status' => (string)$row['status'], 'receipt_total' => (float)$row['receipt_total'], 'ledger_total' => 0.0]; 
    } 
}

if ($res = $conn->query("SELECT CAST(IFNULL(JSON_UNQUOTE(JSON_EXTRACT(l.payload_json, '$.target_id')), '0') AS UNSIGNED) AS payroll_id, IFNULL(SUM(l.amount),0) AS ledger_total FROM finance_ledger_entries l WHERE l.source_type='financial_receipt_allocation' AND l.entry_type='allocation_payroll' AND DATE(l.trans_date) BETWEEN '" . $conn->real_escape_string($dateFrom) . "' AND '" . $conn->real_escape_string($dateTo) . "' GROUP BY payroll_id")) { 
    while ($row = $res->fetch_assoc()) { 
        $pid = (int)($row['payroll_id'] ?? 0); 
        $sheet = $conn->query("SELECT IFNULL(payroll_number, CONCAT('#', id)) AS payroll_number, IFNULL(month_year,'') AS month_year, IFNULL(employee_name_snapshot,'') AS employee_name, IFNULL(status,'') AS status FROM payroll_sheets WHERE id={$pid} LIMIT 1")->fetch_assoc(); 
        $key = (string)($sheet['payroll_number'] ?? ('#'.$pid)); 
        if (!isset($payrollRows[$key])) $payrollRows[$key] = ['payroll_number' => $key, 'month_year' => (string)($sheet['month_year'] ?? ''), 'employee_name' => (string)($sheet['employee_name'] ?? ''), 'status' => (string)($sheet['status'] ?? ''), 'receipt_total' => 0.0, 'ledger_total' => 0.0]; 
        $payrollRows[$key]['ledger_total'] += (float)$row['ledger_total']; 
    } 
}
foreach ($payrollRows as $k => $row) $payrollRows[$k]['reason'] = $buildReason((float)$row['receipt_total'], (float)$row['ledger_total']);
$payrollRows = $mismatchFilter($payrollRows);

$loanRows = [];
if ($res = $conn->query("SELECT IFNULL(u.full_name, CONCAT('#', a.target_id)) AS employee_name, IFNULL(SUM(a.amount),0) AS receipt_total, 0 AS ledger_total FROM financial_receipt_allocations a INNER JOIN financial_receipts r ON r.id = a.receipt_id LEFT JOIN users u ON u.id = a.target_id WHERE a.allocation_type='loan_advance' AND DATE(r.trans_date) BETWEEN '" . $conn->real_escape_string($dateFrom) . "' AND '" . $conn->real_escape_string($dateTo) . "' GROUP BY a.target_id, employee_name")) { 
    while ($row = $res->fetch_assoc()) { 
        $key = (string)$row['employee_name']; 
        $loanRows[$key] = ['employee_name' => (string)$row['employee_name'],'receipt_total' => (float)$row['receipt_total'],'ledger_total' => 0.0]; 
    } 
}

if ($res = $conn->query("SELECT CAST(IFNULL(JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.target_id')), '0') AS UNSIGNED) AS employee_id, IFNULL(SUM(amount),0) AS ledger_total FROM finance_ledger_entries WHERE source_type='financial_receipt_allocation' AND entry_type='allocation_loan_advance' AND DATE(trans_date) BETWEEN '" . $conn->real_escape_string($dateFrom) . "' AND '" . $conn->real_escape_string($dateTo) . "' GROUP BY employee_id")) { 
    while ($row = $res->fetch_assoc()) { 
        $employeeId = (int)($row['employee_id'] ?? 0); 
        $name = (string)($conn->query("SELECT IFNULL(full_name, CONCAT('#', id)) FROM users WHERE id={$employeeId} LIMIT 1")->fetch_row()[0] ?? ('#'.$employeeId)); 
        if (!isset($loanRows[$name])) $loanRows[$name] = ['employee_name' => $name,'receipt_total' => 0.0,'ledger_total' => 0.0]; 
        $loanRows[$name]['ledger_total'] += (float)$row['ledger_total']; 
    } 
}
foreach ($loanRows as $k => $row) $loanRows[$k]['reason'] = $buildReason((float)$row['receipt_total'], (float)$row['ledger_total']);
$loanRows = $mismatchFilter($loanRows);

$taxRows = [];
if (app_table_has_column($conn, 'financial_receipts', 'tax_law_key')) {
    if ($res = $conn->query("SELECT COALESCE(NULLIF(TRIM(tax_law_key), ''), '__unassigned__') AS tax_law_key, IFNULL(SUM(amount),0) AS receipt_total, 0 AS ledger_total FROM financial_receipts WHERE type='out' AND category='tax' AND DATE(trans_date) BETWEEN '" . $conn->real_escape_string($dateFrom) . "' AND '" . $conn->real_escape_string($dateTo) . "' GROUP BY COALESCE(NULLIF(TRIM(tax_law_key), ''), '__unassigned__')")) {
        while ($row = $res->fetch_assoc()) $taxRows[(string)$row['tax_law_key']] = ['tax_law_key' => (string)$row['tax_law_key'],'receipt_total' => (float)$row['receipt_total'],'ledger_total' => 0.0];
    }
    if ($res = $conn->query("SELECT COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.tax_law_key')), ''), '__unassigned__') AS tax_law_key, IFNULL(SUM(amount),0) AS ledger_total FROM finance_ledger_entries WHERE source_type='financial_receipt' AND DATE(trans_date) BETWEEN '" . $conn->real_escape_string($dateFrom) . "' AND '" . $conn->real_escape_string($dateTo) . "' AND JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.category'))='tax' GROUP BY COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.tax_law_key')), ''), '__unassigned__')")) { 
        while ($row = $res->fetch_assoc()) { 
            $law = (string)$row['tax_law_key']; 
            if (!isset($taxRows[$law])) $taxRows[$law] = ['tax_law_key' => $law,'receipt_total' => 0.0,'ledger_total' => 0.0]; 
            $taxRows[$law]['ledger_total'] += (float)$row['ledger_total']; 
        } 
    }
}
foreach ($taxRows as $k => $row) $taxRows[$k]['reason'] = $buildReason((float)$row['receipt_total'], (float)$row['ledger_total']);
$taxRows = $mismatchFilter($taxRows);

$manualRows = [];
if ($res = $conn->query("SELECT COALESCE(NULLIF(TRIM(category), ''), 'general') AS category_key, IFNULL(SUM(amount),0) AS receipt_total, 0 AS ledger_total FROM financial_receipts WHERE DATE(trans_date) BETWEEN '" . $conn->real_escape_string($dateFrom) . "' AND '" . $conn->real_escape_string($dateTo) . "' AND COALESCE(NULLIF(TRIM(category), ''), 'general') IN ('general','manual') GROUP BY COALESCE(NULLIF(TRIM(category), ''), 'general')")) {
    while ($row = $res->fetch_assoc()) $manualRows[(string)$row['category_key']] = ['category_key' => (string)$row['category_key'],'receipt_total' => (float)$row['receipt_total'],'ledger_total' => 0.0];
}
if ($res = $conn->query("SELECT COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.category')), ''), 'general') AS category_key, IFNULL(SUM(amount),0) AS ledger_total FROM finance_ledger_entries WHERE source_type='financial_receipt' AND DATE(trans_date) BETWEEN '" . $conn->real_escape_string($dateFrom) . "' AND '" . $conn->real_escape_string($dateTo) . "' AND COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.category')), ''), 'general') IN ('general','manual') GROUP BY COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.category')), ''), 'general')")) { 
    while ($row = $res->fetch_assoc()) { 
        $cat = (string)$row['category_key']; 
        if (!isset($manualRows[$cat])) $manualRows[$cat] = ['category_key' => $cat,'receipt_total' => 0.0,'ledger_total' => 0.0]; 
        $manualRows[$cat]['ledger_total'] += (float)$row['ledger_total']; 
    } 
}
foreach ($manualRows as $k => $row) $manualRows[$k]['reason'] = $buildReason((float)$row['receipt_total'], (float)$row['ledger_total']);
$manualRows = $mismatchFilter($manualRows);

$formatTaxLaw = static function (string $key) use ($isEnglish): string { return $key === '__unassigned__' ? ($isEnglish ? 'Unassigned tax law' : 'قانون ضريبي غير محدد') : $key; };
$formatManualKey = static function (string $key) use ($isEnglish): string { return $key === 'general' ? ($isEnglish ? 'General manual entries' : 'قيود/حركات عامة') : ($isEnglish ? 'Manual entries' : 'حركات يدوية'); };

?>
<!DOCTYPE html>
<html dir="<?php echo $isEnglish ? 'ltr' : 'rtl'; ?>" lang="<?php echo $isEnglish ? 'en' : 'ar'; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo app_h($isEnglish ? 'Ledger Reconciliation Report' : 'تقرير مطابقة القيود'); ?> | <?php echo app_h($appName); ?></title>
    
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;800&display=swap" rel="stylesheet">
    
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
            padding: 30px;
            line-height: 1.6;
            margin: 0;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
        }

        /* أزرار التحكم */
        .no-print {
            display: flex;
            justify-content: center;
            gap: 15px;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 1px dashed var(--ae-border);
        }
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 24px;
            border-radius: 8px;
            font-family: 'Cairo', sans-serif;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
            transition: all 0.3s ease;
            font-size: 1rem;
            border: 1px solid transparent;
        }
        .btn-print { background: linear-gradient(90deg, var(--ae-gold), #b8860b); color: #000; }
        .btn-print:hover { box-shadow: 0 0 15px rgba(212, 175, 55, 0.4); transform: translateY(-2px); }
        .btn-back { background: var(--ae-card); color: var(--ae-text); border-color: var(--ae-border); }
        .btn-back:hover { border-color: var(--ae-gold); color: var(--ae-gold); }

        /* الترويسة */
        .head { text-align: center; margin-bottom: 30px; }
        .head h1 { color: var(--ae-gold); margin: 0 0 5px 0; font-size: 2rem; font-weight: 800; }
        .head .app-name { font-size: 1.2rem; color: var(--ae-text-muted); font-weight: 600; letter-spacing: 1px; }

        /* البيانات الأساسية */
        .meta {
            display: flex;
            flex-wrap: wrap;
            justify-content: space-between;
            gap: 15px;
            background: var(--ae-card);
            border: 1px solid var(--ae-gold);
            border-radius: 10px;
            padding: 15px 20px;
            margin-bottom: 30px;
        }
        .meta div { font-size: 1rem; font-weight: 600; }
        .meta div span { color: var(--ae-gold); margin-inline-end: 5px; }

        /* البطاقات الإحصائية */
        .cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 30px;
        }
        .card {
            background: var(--ae-card);
            border: 1px solid var(--ae-border);
            border-radius: 10px;
            padding: 20px;
            text-align: center;
            transition: transform 0.3s ease, border-color 0.3s ease;
        }
        .card:hover { border-color: var(--ae-gold); transform: translateY(-3px); }
        .card .label { font-size: 0.95rem; color: var(--ae-text-muted); margin-bottom: 10px; font-weight: 600; }
        .card .value { font-size: 1.6rem; font-weight: 800; color: #fff; font-family: monospace; }
        .card .value.in { color: var(--ae-green); }
        .card .value.out { color: var(--ae-red); }

        /* الجداول والتنسيقات */
        .table-section { margin-bottom: 40px; background: var(--ae-card); border-radius: 12px; border: 1px solid var(--ae-border); overflow: hidden; padding: 20px; }
        .table-section h2 { margin: 0 0 15px 0; color: var(--ae-gold); font-size: 1.3rem; font-weight: 800; border-bottom: 1px dashed var(--ae-border); padding-bottom: 10px; display: flex; align-items: center; gap: 8px; }
        .table-responsive { width: 100%; overflow-x: auto; }
        
        table { width: 100%; border-collapse: collapse; }
        th, td { 
            padding: 12px 15px; 
            text-align: <?php echo $isEnglish ? 'left' : 'right'; ?>; 
            font-size: 0.95rem; 
            border-bottom: 1px solid var(--ae-border);
        }
        th { 
            background: #111; 
            color: var(--ae-gold); 
            font-weight: 800; 
            text-transform: uppercase; 
            border-bottom: 2px solid var(--ae-gold); 
        }
        tr:last-child td { border-bottom: none; }
        tbody tr:hover { background: #1a1a1a; }
        .muted-row { color: var(--ae-text-muted); text-align: center !important; padding: 30px !important; }
        .diff-val { font-weight: bold; font-family: monospace; }
        .diff-zero { color: var(--ae-green); }
        .diff-alert { color: var(--ae-red); }

        /* ========================================================= */
        /* إعدادات الطباعة (Print Mode) - أبيض وأسود رسمي لحفظ الحبر */
        /* ========================================================= */
        @media print {
            body { 
                background: #fff !important; 
                color: #000 !important; 
                padding: 0 !important; 
                font-size: 11pt !important; 
            }
            .container { max-width: 100% !important; }
            .no-print { display: none !important; }

            .head h1 { color: #000 !important; font-size: 18pt !important; border-bottom: 2px solid #000; padding-bottom: 10px; display: inline-block; }
            .head .app-name { color: #555 !important; }

            .meta { 
                background: transparent !important; 
                border: 1px solid #000 !important; 
                color: #000 !important; 
                border-radius: 0 !important; 
            }
            .meta div span { color: #000 !important; font-weight: bold; }

            .cards { display: flex !important; flex-wrap: wrap !important; gap: 10px !important; }
            .card { 
                background: transparent !important; 
                border: 1px solid #000 !important; 
                flex: 1 1 calc(25% - 10px); 
                border-radius: 0 !important; 
                padding: 10px !important; 
            }
            .card .label { color: #333 !important; font-size: 10pt !important; }
            .card .value { color: #000 !important; font-size: 14pt !important; }

            .table-section { background: transparent !important; border: none !important; padding: 0 !important; margin-bottom: 30px !important; }
            .table-section h2 { color: #000 !important; border-bottom: 2px solid #000 !important; }
            
            table { border-collapse: collapse !important; border: 1px solid #000 !important; }
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

            .diff-zero, .diff-alert { color: #000 !important; }

            /* 🌟 إعدادات منع قطع البيانات بين الصفحات (Pagination Control) 🌟 */
            .table-section, tr, .card, .meta {
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

    <div class="container">
        
        <div class="no-print">
            <button class="btn btn-print" onclick="window.print()">
                <i class="fa-solid fa-print"></i> <?php echo app_h($isEnglish ? 'Print / Save PDF' : 'طباعة / حفظ PDF'); ?>
            </button>
            <a class="btn btn-back" href="finance_reconciliation.php?<?php echo http_build_query($_GET); ?>">
                <i class="fa-solid fa-arrow-<?php echo $isEnglish ? 'left' : 'right'; ?>"></i> <?php echo app_h($isEnglish ? 'Back' : 'رجوع للفلترة'); ?>
            </a>
        </div>

        <div class="head">
            <h1><i class="fa-solid fa-scale-balanced"></i> <?php echo app_h($isEnglish ? 'Ledger Reconciliation Report' : 'تقرير مطابقة القيود'); ?></h1>
            <div class="app-name"><?php echo app_h($appName); ?></div>
        </div>

        <div class="meta">
            <div><span><i class="fa-regular fa-calendar"></i> <?php echo app_h($isEnglish ? 'Date from' : 'من تاريخ'); ?>:</span> <?php echo app_h($dateFrom); ?></div>
            <div><span><i class="fa-regular fa-calendar-check"></i> <?php echo app_h($isEnglish ? 'Date to' : 'إلى تاريخ'); ?>:</span> <?php echo app_h($dateTo); ?></div>
            <div><span><i class="fa-solid fa-filter"></i> <?php echo app_h($isEnglish ? 'Mode' : 'الوضع'); ?>:</span> <?php echo app_h($mismatchesOnly ? ($isEnglish ? 'Mismatches only' : 'عرض الفروقات وعدم المطابقة فقط') : ($isEnglish ? 'All rows' : 'عرض كافة السجلات')); ?></div>
        </div>

        <div class="cards">
            <div class="card">
                <div class="label"><i class="fa-solid fa-arrow-down-short-wide"></i> <?php echo app_h($isEnglish ? 'Cash receipts' : 'إجمالي القبض النقدي'); ?></div>
                <div class="value in"><?php echo number_format($receiptIn, 2); ?></div>
            </div>
            <div class="card">
                <div class="label"><i class="fa-solid fa-book-journal-whills"></i> <?php echo app_h($isEnglish ? 'Ledger cash-in' : 'إجمالي القبض في الدفتر'); ?></div>
                <div class="value in"><?php echo number_format($ledgerCashIn, 2); ?></div>
            </div>
            <div class="card">
                <div class="label"><i class="fa-solid fa-arrow-up-right-dots"></i> <?php echo app_h($isEnglish ? 'Cash payments' : 'إجمالي الصرف النقدي'); ?></div>
                <div class="value out"><?php echo number_format($receiptOut, 2); ?></div>
            </div>
            <div class="card">
                <div class="label"><i class="fa-solid fa-book-open"></i> <?php echo app_h($isEnglish ? 'Ledger cash-out' : 'إجمالي الصرف في الدفتر'); ?></div>
                <div class="value out"><?php echo number_format($ledgerCashOut, 2); ?></div>
            </div>
        </div>

        <?php 
        $render = static function(string $title, string $icon, array $headers, array $rows, callable $rowFn, int $colspan) use ($isEnglish) { 
            echo '<div class="table-section">';
            echo '<h2><i class="' . app_h($icon) . '"></i> ' . app_h($title) . '</h2>';
            echo '<div class="table-responsive"><table><thead><tr>'; 
            foreach($headers as $h) echo '<th>' . app_h($h) . '</th>'; 
            echo '</tr></thead><tbody>'; 
            if($rows) {
                foreach($rows as $row) $rowFn($row); 
            } else {
                echo '<tr><td colspan="' . $colspan . '" class="muted-row">' . app_h($isEnglish ? 'No records match the current filters.' : 'لا توجد سجلات مطابقة لمعايير البحث الحالية.') . '</td></tr>'; 
            }
            echo '</tbody></table></div></div>'; 
        }; 
        
        // 1. حسب المستند
        $render(
            $isEnglish ? 'By document' : 'المطابقة حسب المستند', 
            'fa-solid fa-file-invoice',
            [$isEnglish ? 'Type' : 'النوع', $isEnglish ? 'Reference' : 'المرجع', $isEnglish ? 'Counterparty' : 'الطرف المالي', $isEnglish ? 'Receipts' : 'الحركات النقدية', $isEnglish ? 'Ledger' : 'ترحيل الدفتر', $isEnglish ? 'Difference' : 'الفرق', $isEnglish ? 'Reason' : 'التحليل'], 
            $documentRows, 
            static function($row) { 
                $diff = round((float)$row['receipt_total'] - (float)$row['ledger_total'], 2); 
                $diffClass = abs($diff) > 0.009 ? 'diff-alert' : 'diff-zero';
                echo '<tr>
                        <td>'.app_h((string)$row['type']).'</td>
                        <td style="font-family:monospace; font-weight:bold;">'.app_h((string)$row['ref']).'</td>
                        <td>'.app_h((string)($row['label'] ?: '-')).'</td>
                        <td style="font-family:monospace;">'.number_format((float)$row['receipt_total'], 2).'</td>
                        <td style="font-family:monospace;">'.number_format((float)$row['ledger_total'], 2).'</td>
                        <td class="diff-val '.$diffClass.'">'.number_format($diff, 2).'</td>
                        <td>'.app_h((string)$row['reason']).'</td>
                      </tr>'; 
            }, 
            7
        );

        // 2. حسب العميل/المورد
        $render(
            $isEnglish ? 'By counterparty' : 'المطابقة حسب العميل / المورد', 
            'fa-solid fa-users-gear',
            [$isEnglish ? 'Type' : 'النوع', $isEnglish ? 'Name' : 'اسم الجهة', $isEnglish ? 'Receipts' : 'الحركات النقدية', $isEnglish ? 'Ledger' : 'ترحيل الدفتر', $isEnglish ? 'Difference' : 'الفرق', $isEnglish ? 'Reason' : 'التحليل'], 
            $counterpartyRows, 
            static function($row) { 
                $diff = round((float)$row['receipt_total'] - (float)$row['ledger_total'], 2); 
                $diffClass = abs($diff) > 0.009 ? 'diff-alert' : 'diff-zero';
                echo '<tr>
                        <td>'.app_h((string)$row['type']).'</td>
                        <td style="font-weight:bold;">'.app_h((string)$row['label']).'</td>
                        <td style="font-family:monospace;">'.number_format((float)$row['receipt_total'], 2).'</td>
                        <td style="font-family:monospace;">'.number_format((float)$row['ledger_total'], 2).'</td>
                        <td class="diff-val '.$diffClass.'">'.number_format($diff, 2).'</td>
                        <td>'.app_h((string)$row['reason']).'</td>
                      </tr>'; 
            }, 
            6
        );

        // 3. حسب كشف الرواتب
        $render(
            $isEnglish ? 'By payroll sheet' : 'المطابقة حسب مسيرات الرواتب', 
            'fa-solid fa-money-check-dollar',
            [$isEnglish ? 'Payroll no.' : 'رقم المسير', $isEnglish ? 'Month' : 'الشهر', $isEnglish ? 'Employee' : 'الموظف', $isEnglish ? 'Status' : 'الحالة', $isEnglish ? 'Receipts' : 'النقدية المصروفة', $isEnglish ? 'Ledger' : 'ترحيل الدفتر', $isEnglish ? 'Difference' : 'الفرق', $isEnglish ? 'Reason' : 'التحليل'], 
            $payrollRows, 
            static function($row) { 
                $diff = round((float)$row['receipt_total'] - (float)$row['ledger_total'], 2); 
                $diffClass = abs($diff) > 0.009 ? 'diff-alert' : 'diff-zero';
                echo '<tr>
                        <td style="font-family:monospace; font-weight:bold;">'.app_h((string)$row['payroll_number']).'</td>
                        <td>'.app_h((string)$row['month_year']).'</td>
                        <td>'.app_h((string)$row['employee_name']).'</td>
                        <td>'.app_h((string)$row['status']).'</td>
                        <td style="font-family:monospace;">'.number_format((float)$row['receipt_total'], 2).'</td>
                        <td style="font-family:monospace;">'.number_format((float)$row['ledger_total'], 2).'</td>
                        <td class="diff-val '.$diffClass.'">'.number_format($diff, 2).'</td>
                        <td>'.app_h((string)$row['reason']).'</td>
                      </tr>'; 
            }, 
            8
        );

        // 4. حسب السلف
        $render(
            $isEnglish ? 'By employee advance' : 'المطابقة حسب سلف الموظفين', 
            'fa-solid fa-hand-holding-dollar',
            [$isEnglish ? 'Employee' : 'الموظف', $isEnglish ? 'Receipts' : 'النقدية المصروفة', $isEnglish ? 'Ledger' : 'ترحيل الدفتر', $isEnglish ? 'Difference' : 'الفرق', $isEnglish ? 'Reason' : 'التحليل'], 
            $loanRows, 
            static function($row) { 
                $diff = round((float)$row['receipt_total'] - (float)$row['ledger_total'], 2); 
                $diffClass = abs($diff) > 0.009 ? 'diff-alert' : 'diff-zero';
                echo '<tr>
                        <td style="font-weight:bold;">'.app_h((string)$row['employee_name']).'</td>
                        <td style="font-family:monospace;">'.number_format((float)$row['receipt_total'], 2).'</td>
                        <td style="font-family:monospace;">'.number_format((float)$row['ledger_total'], 2).'</td>
                        <td class="diff-val '.$diffClass.'">'.number_format($diff, 2).'</td>
                        <td>'.app_h((string)$row['reason']).'</td>
                      </tr>'; 
            }, 
            5
        );

        // 5. التسويات الضريبية
        $render(
            $isEnglish ? 'Tax settlement reconciliation' : 'مطابقة التسويات والمدفوعات الضريبية', 
            'fa-solid fa-building-columns',
            [$isEnglish ? 'Tax law' : 'القانون الضريبي', $isEnglish ? 'Receipts' : 'سندات السداد', $isEnglish ? 'Ledger' : 'ترحيل الدفتر', $isEnglish ? 'Difference' : 'الفرق', $isEnglish ? 'Reason' : 'التحليل'], 
            $taxRows, 
            static function($row) use($formatTaxLaw) { 
                $diff = round((float)$row['receipt_total'] - (float)$row['ledger_total'], 2); 
                $diffClass = abs($diff) > 0.009 ? 'diff-alert' : 'diff-zero';
                echo '<tr>
                        <td style="font-weight:bold;">'.app_h($formatTaxLaw((string)$row['tax_law_key'])).'</td>
                        <td style="font-family:monospace;">'.number_format((float)$row['receipt_total'], 2).'</td>
                        <td style="font-family:monospace;">'.number_format((float)$row['ledger_total'], 2).'</td>
                        <td class="diff-val '.$diffClass.'">'.number_format($diff, 2).'</td>
                        <td>'.app_h((string)$row['reason']).'</td>
                      </tr>'; 
            }, 
            5
        );

        // 6. الحركات اليدوية
        $render(
            $isEnglish ? 'Manual / general settlements' : 'التسويات والقيود العامة اليدوية', 
            'fa-solid fa-pen-to-square',
            [$isEnglish ? 'Category' : 'الفئة', $isEnglish ? 'Receipts' : 'الحركات النقدية', $isEnglish ? 'Ledger' : 'ترحيل الدفتر', $isEnglish ? 'Difference' : 'الفرق', $isEnglish ? 'Reason' : 'التحليل'], 
            $manualRows, 
            static function($row) use($formatManualKey) { 
                $diff = round((float)$row['receipt_total'] - (float)$row['ledger_total'], 2); 
                $diffClass = abs($diff) > 0.009 ? 'diff-alert' : 'diff-zero';
                echo '<tr>
                        <td style="font-weight:bold;">'.app_h($formatManualKey((string)$row['category_key'])).'</td>
                        <td style="font-family:monospace;">'.number_format((float)$row['receipt_total'], 2).'</td>
                        <td style="font-family:monospace;">'.number_format((float)$row['ledger_total'], 2).'</td>
                        <td class="diff-val '.$diffClass.'">'.number_format($diff, 2).'</td>
                        <td>'.app_h((string)$row['reason']).'</td>
                      </tr>'; 
            }, 
            5
        ); 
        ?>

    </div>
</body>
</html>