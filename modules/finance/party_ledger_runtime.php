<?php

if (!function_exists('financeLedgerNormalizeDate')) {
    function financeLedgerNormalizeDate(?string $value, string $fallback): string
    {
        $value = trim((string)$value);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : $fallback;
    }
}

if (!function_exists('financeClientLedgerDateRange')) {
    function financeClientLedgerDateRange(mysqli $conn, int $clientId, ?string $dateFrom, ?string $dateTo): array
    {
        $dateTo = financeLedgerNormalizeDate($dateTo, date('Y-m-d'));
        $dateFrom = trim((string)$dateFrom);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
            $stmt = $conn->prepare("
                SELECT MIN(t_date) AS first_date
                FROM (
                    SELECT DATE(COALESCE(NULLIF(inv_date, ''), created_at)) AS t_date
                    FROM invoices
                    WHERE client_id = ?
                      AND COALESCE(status, '') <> 'cancelled'
                    UNION ALL
                    SELECT trans_date AS t_date
                    FROM financial_receipts
                    WHERE client_id = ?
                      AND type = 'in'
                ) d
                WHERE t_date IS NOT NULL
            ");
            $stmt->bind_param('ii', $clientId, $clientId);
            $stmt->execute();
            $firstDate = (string)($stmt->get_result()->fetch_assoc()['first_date'] ?? '');
            $stmt->close();
            $dateFrom = preg_match('/^\d{4}-\d{2}-\d{2}$/', $firstDate) ? $firstDate : date('Y-01-01');
        }

        if ($dateFrom > $dateTo) {
            [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
        }

        return [$dateFrom, $dateTo];
    }
}

if (!function_exists('financeSupplierLedgerDateRange')) {
    function financeSupplierLedgerDateRange(mysqli $conn, int $supplierId, ?string $dateFrom, ?string $dateTo): array
    {
        $dateTo = financeLedgerNormalizeDate($dateTo, date('Y-m-d'));
        $dateFrom = trim((string)$dateFrom);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
            $stmt = $conn->prepare("
                SELECT MIN(t_date) AS first_date
                FROM (
                    SELECT DATE(COALESCE(NULLIF(inv_date, ''), created_at)) AS t_date
                    FROM purchase_invoices
                    WHERE supplier_id = ?
                      AND COALESCE(status, '') <> 'cancelled'
                    UNION ALL
                    SELECT trans_date AS t_date
                    FROM financial_receipts
                    WHERE supplier_id = ?
                      AND type = 'out'
                ) d
                WHERE t_date IS NOT NULL
            ");
            $stmt->bind_param('ii', $supplierId, $supplierId);
            $stmt->execute();
            $firstDate = (string)($stmt->get_result()->fetch_assoc()['first_date'] ?? '');
            $stmt->close();
            $dateFrom = preg_match('/^\d{4}-\d{2}-\d{2}$/', $firstDate) ? $firstDate : date('Y-01-01');
        }

        if ($dateFrom > $dateTo) {
            [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
        }

        return [$dateFrom, $dateTo];
    }
}

if (!function_exists('financeClientLedgerBuild')) {
    function financeClientLedgerBuild(mysqli $conn, int $clientId, string $dateFrom, string $dateTo): array
    {
        $openingExclusion = "description NOT LIKE 'تسوية رصيد أول المدة%'";
        $snapshotAsOf = function_exists('financeClientBalanceSnapshotAsOf')
            ? financeClientBalanceSnapshotAsOf($conn, $clientId, $dateFrom)
            : null;
        $openingBalance = $snapshotAsOf ? round((float)($snapshotAsOf['net_balance'] ?? 0), 2) : 0.0;

        $clientId = (int)$clientId;
        $fromEsc = $conn->real_escape_string($dateFrom);
        $toEsc = $conn->real_escape_string($dateTo);

        $parts = [
            "
                SELECT
                    COALESCE(NULLIF(inv_date, ''), DATE(created_at)) AS t_date,
                    'invoice' AS type,
                    id AS ref_id,
                    total_amount AS debit,
                    0 AS credit,
                    'فاتورة مبيعات' AS description
                FROM invoices
                WHERE client_id = {$clientId}
                  AND COALESCE(status, '') <> 'cancelled'
                  AND DATE(COALESCE(NULLIF(inv_date, ''), created_at)) BETWEEN '{$fromEsc}' AND '{$toEsc}'
            ",
            "
                SELECT
                    trans_date AS t_date,
                    'receipt' AS type,
                    id AS ref_id,
                    0 AS debit,
                    amount AS credit,
                    description
                FROM financial_receipts
                WHERE client_id = {$clientId}
                  AND type = 'in'
                  AND trans_date BETWEEN '{$fromEsc}' AND '{$toEsc}'
                  AND {$openingExclusion}
            ",
            "
                SELECT
                    trans_date AS t_date,
                    'refund' AS type,
                    id AS ref_id,
                    amount AS debit,
                    0 AS credit,
                    description
                FROM financial_receipts
                WHERE client_id = {$clientId}
                  AND type = 'out'
                  AND LOWER(TRIM(IFNULL(category, ''))) = 'refund'
                  AND trans_date BETWEEN '{$fromEsc}' AND '{$toEsc}'
            ",
        ];

        if (app_table_exists($conn, 'sales_invoice_returns')) {
            $parts[] = "
                SELECT
                    return_date AS t_date,
                    'sales_return' AS type,
                    id AS ref_id,
                    0 AS debit,
                    total_amount AS credit,
                    CONCAT('مردود مبيعات ', IFNULL(return_number, '')) AS description
                FROM sales_invoice_returns
                WHERE client_id = {$clientId}
                  AND return_date BETWEEN '{$fromEsc}' AND '{$toEsc}'
            ";
        }

        $res = $conn->query("
            SELECT * FROM (
                " . implode("\nUNION ALL\n", $parts) . "
            ) AS ledger
            ORDER BY t_date ASC, ref_id ASC
        ");

        $rows = [];
        $sumDebit = 0.0;
        $sumCredit = 0.0;
        $runningBalance = $openingBalance;
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $runningBalance += ((float)$row['debit'] - (float)$row['credit']);
                $sumDebit += (float)$row['debit'];
                $sumCredit += (float)$row['credit'];
                $row['running_balance'] = round($runningBalance, 2);
                $rows[] = $row;
            }
        }

        return [
            'opening_balance' => $openingBalance,
            'rows' => $rows,
            'sum_debit' => round($sumDebit, 2),
            'sum_credit' => round($sumCredit, 2),
            'closing_balance' => round($runningBalance, 2),
        ];
    }
}

if (!function_exists('financeSupplierLedgerBuild')) {
    function financeSupplierLedgerBuild(mysqli $conn, int $supplierId, string $dateFrom, string $dateTo): array
    {
        $openingExclusion = "description NOT LIKE 'تسوية رصيد أول المدة%'";
        $snapshotAsOf = function_exists('financeSupplierBalanceSnapshotAsOf')
            ? financeSupplierBalanceSnapshotAsOf($conn, $supplierId, $dateFrom)
            : null;
        $openingBalance = $snapshotAsOf ? round((float)($snapshotAsOf['net_balance'] ?? 0), 2) : 0.0;

        $supplierId = (int)$supplierId;
        $fromEsc = $conn->real_escape_string($dateFrom);
        $toEsc = $conn->real_escape_string($dateTo);

        $parts = [
            "
                SELECT
                    COALESCE(NULLIF(inv_date, ''), DATE(created_at)) AS t_date,
                    'purchase' AS type,
                    id AS ref_id,
                    0 AS debit,
                    total_amount AS credit,
                    'فاتورة مشتريات' AS description
                FROM purchase_invoices
                WHERE supplier_id = {$supplierId}
                  AND COALESCE(status, '') <> 'cancelled'
                  AND DATE(COALESCE(NULLIF(inv_date, ''), created_at)) BETWEEN '{$fromEsc}' AND '{$toEsc}'
            ",
            "
                SELECT
                    trans_date AS t_date,
                    'payment' AS type,
                    id AS ref_id,
                    amount AS debit,
                    0 AS credit,
                    description
                FROM financial_receipts
                WHERE supplier_id = {$supplierId}
                  AND type = 'out'
                  AND trans_date BETWEEN '{$fromEsc}' AND '{$toEsc}'
                  AND {$openingExclusion}
            ",
        ];

        if (app_table_exists($conn, 'purchase_invoice_returns')) {
            $parts[] = "
                SELECT
                    return_date AS t_date,
                    'purchase_return' AS type,
                    id AS ref_id,
                    total_amount AS debit,
                    0 AS credit,
                    CONCAT('مردود شراء ', IFNULL(return_number, '')) AS description
                FROM purchase_invoice_returns
                WHERE supplier_id = {$supplierId}
                  AND return_date BETWEEN '{$fromEsc}' AND '{$toEsc}'
            ";
        }

        $res = $conn->query("
            SELECT * FROM (
                " . implode("\nUNION ALL\n", $parts) . "
            ) AS ledger
            ORDER BY t_date ASC, ref_id ASC
        ");

        $rows = [];
        $sumDebit = 0.0;
        $sumCredit = 0.0;
        $runningBalance = $openingBalance;
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $runningBalance += ((float)$row['credit'] - (float)$row['debit']);
                $sumDebit += (float)$row['debit'];
                $sumCredit += (float)$row['credit'];
                $row['running_balance'] = round($runningBalance, 2);
                $rows[] = $row;
            }
        }

        return [
            'opening_balance' => $openingBalance,
            'rows' => $rows,
            'sum_debit' => round($sumDebit, 2),
            'sum_credit' => round($sumCredit, 2),
            'closing_balance' => round($runningBalance, 2),
        ];
    }
}

if (!function_exists('financeLedgerEntryAddLine')) {
    function financeLedgerEntryAddLine(array &$entry, string $account, float $debit, float $credit): void
    {
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
    }
}

if (!function_exists('financeLedgerFinalizeEntry')) {
    function financeLedgerFinalizeEntry(array $entry): ?array
    {
        $debit = 0.0;
        $credit = 0.0;
        foreach ((array)($entry['lines'] ?? []) as $line) {
            $debit += (float)($line['debit'] ?? 0);
            $credit += (float)($line['credit'] ?? 0);
        }

        if (empty($entry['lines'])) {
            return null;
        }

        $entry['debit_total'] = round($debit, 2);
        $entry['credit_total'] = round($credit, 2);
        $entry['balanced'] = abs($entry['debit_total'] - $entry['credit_total']) <= 0.01;
        return $entry;
    }
}

if (!function_exists('financeJournalEntriesBuild')) {
    function financeJournalEntriesBuild(mysqli $conn, string $dateFrom, string $dateTo): array
    {
        $entries = [];
        $dateFrom = financeLedgerNormalizeDate($dateFrom, date('Y-m-01'));
        $dateTo = financeLedgerNormalizeDate($dateTo, date('Y-m-t'));
        if ($dateFrom > $dateTo) {
            [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
        }

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
            $entry = [
                'date' => (string)$row['inv_date'],
                'source' => app_tr('فاتورة مبيعات', 'Sales Invoice'),
                'ref' => (string)($row['invoice_number'] ?: ('INV-' . $row['id'])),
                'party' => (string)($row['client_name'] ?? ''),
                'memo' => app_tr('إثبات فاتورة مبيعات', 'Record sales invoice'),
                'lines' => [],
            ];
            financeLedgerEntryAddLine($entry, app_tr('ذمم العملاء', 'Accounts Receivable'), $total, 0);
            financeLedgerEntryAddLine($entry, app_tr('إيرادات المبيعات', 'Sales Revenue'), 0, $netBase);
            financeLedgerEntryAddLine($entry, app_tr('ضريبة قيمة مضافة مخرجات', 'Output VAT'), 0, $tax);
            $final = financeLedgerFinalizeEntry($entry);
            if ($final) {
                $entries[] = $final;
            }
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
            $entry = [
                'date' => (string)$row['inv_date'],
                'source' => app_tr('فاتورة مشتريات', 'Purchase Invoice'),
                'ref' => (string)($row['purchase_number'] ?: ('PUR-' . $row['id'])),
                'party' => (string)($row['supplier_name'] ?? ''),
                'memo' => app_tr('إثبات فاتورة مشتريات/مخزون', 'Record purchase/inventory invoice'),
                'lines' => [],
            ];
            financeLedgerEntryAddLine($entry, app_tr('مشتريات / مخزون', 'Purchases / Inventory'), $netBase, 0);
            financeLedgerEntryAddLine($entry, app_tr('ضريبة قيمة مضافة مدخلات', 'Input VAT'), $tax, 0);
            financeLedgerEntryAddLine($entry, app_tr('ذمم الموردين', 'Accounts Payable'), 0, $total);
            $final = financeLedgerFinalizeEntry($entry);
            if ($final) {
                $entries[] = $final;
            }
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
            $entry = [
                'date' => (string)$row['trans_date'],
                'source' => $type === 'in' ? app_tr('سند قبض', 'Receipt') : app_tr('سند صرف', 'Payment'),
                'ref' => 'FR-' . (int)$row['id'],
                'party' => (string)($row['client_name'] ?? $row['supplier_name'] ?? $row['employee_name'] ?? ''),
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
                financeLedgerEntryAddLine($entry, app_tr('الخزينة / النقدية', 'Cash / Treasury'), $amount, 0);
                financeLedgerEntryAddLine($entry, $creditAccount, 0, $amount);
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
                financeLedgerEntryAddLine($entry, $debitAccount, $amount, 0);
                financeLedgerEntryAddLine($entry, app_tr('الخزينة / النقدية', 'Cash / Treasury'), 0, $amount);
            }
            $final = financeLedgerFinalizeEntry($entry);
            if ($final) {
                $entries[] = $final;
            }
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
                financeLedgerEntryAddLine($entry, app_tr('مردودات المبيعات', 'Sales Returns'), $subtotal, 0);
                financeLedgerEntryAddLine($entry, app_tr('ضريبة قيمة مضافة مخرجات', 'Output VAT'), $tax, 0);
                financeLedgerEntryAddLine($entry, app_tr('ذمم العملاء', 'Accounts Receivable'), 0, $total);
                $final = financeLedgerFinalizeEntry($entry);
                if ($final) {
                    $entries[] = $final;
                }
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
                financeLedgerEntryAddLine($entry, app_tr('ذمم الموردين', 'Accounts Payable'), $total, 0);
                financeLedgerEntryAddLine($entry, app_tr('مشتريات / مخزون', 'Purchases / Inventory'), 0, $subtotal);
                financeLedgerEntryAddLine($entry, app_tr('ضريبة قيمة مضافة مدخلات', 'Input VAT'), 0, $tax);
                $final = financeLedgerFinalizeEntry($entry);
                if ($final) {
                    $entries[] = $final;
                }
            }
            $stmtPurchaseReturns->close();
        }

        usort($entries, static function (array $a, array $b): int {
            return strcmp(($a['date'] ?? '') . ($a['ref'] ?? ''), ($b['date'] ?? '') . ($b['ref'] ?? ''));
        });

        $totalDebit = 0.0;
        $totalCredit = 0.0;
        $unbalancedCount = 0;
        foreach ($entries as $entry) {
            $totalDebit += (float)($entry['debit_total'] ?? 0);
            $totalCredit += (float)($entry['credit_total'] ?? 0);
            if (empty($entry['balanced'])) {
                $unbalancedCount++;
            }
        }

        return [
            'entries' => $entries,
            'total_debit' => round($totalDebit, 2),
            'total_credit' => round($totalCredit, 2),
            'unbalanced_count' => $unbalancedCount,
        ];
    }
}

if (!function_exists('financeReceiptAllocationAuditBuild')) {
    function financeReceiptAllocationAuditBuild(mysqli $conn, string $dateFrom, string $dateTo): array
    {
        $dateFrom = financeLedgerNormalizeDate($dateFrom, date('Y-m-01'));
        $dateTo = financeLedgerNormalizeDate($dateTo, date('Y-m-t'));
        if ($dateFrom > $dateTo) {
            [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
        }

        $rows = [];
        if (!app_table_exists($conn, 'financial_receipt_allocations')) {
            return $rows;
        }

        $stmt = $conn->prepare("
            SELECT *
            FROM financial_receipts
            WHERE trans_date BETWEEN ? AND ?
            ORDER BY trans_date ASC, id ASC
        ");
        $stmt->bind_param('ss', $dateFrom, $dateTo);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($receipt = $res->fetch_assoc()) {
            $summary = financeReceiptAllocationSummary($conn, $receipt);
            $rows[] = [
                'receipt' => $receipt,
                'summary' => $summary,
                'amount' => round((float)($receipt['amount'] ?? 0), 2),
                'allocated_amount' => round((float)($summary['allocated_amount'] ?? 0), 2),
                'unallocated_amount' => round((float)($summary['unallocated_amount'] ?? 0), 2),
            ];
        }
        $stmt->close();

        return $rows;
    }
}

if (!function_exists('financeOpeningBalanceAuditBuild')) {
    function financeOpeningBalanceAuditBuild(mysqli $conn): array
    {
        $data = [
            'clients' => [],
            'suppliers' => [],
        ];

        $clientRes = $conn->query("SELECT id, name, opening_balance FROM clients WHERE ABS(IFNULL(opening_balance, 0)) > 0.00001 ORDER BY id ASC");
        while ($clientRes && ($row = $clientRes->fetch_assoc())) {
            $breakdown = function_exists('financeClientOpeningBreakdown')
                ? financeClientOpeningBreakdown($conn, (int)$row['id'])
                : [];
            $data['clients'][] = [
                'id' => (int)$row['id'],
                'name' => (string)($row['name'] ?? ''),
                'opening_balance' => round((float)($row['opening_balance'] ?? 0), 2),
                'breakdown' => $breakdown,
            ];
        }

        $supplierRes = $conn->query("SELECT id, name, opening_balance FROM suppliers WHERE ABS(IFNULL(opening_balance, 0)) > 0.00001 ORDER BY id ASC");
        while ($supplierRes && ($row = $supplierRes->fetch_assoc())) {
            $breakdown = function_exists('financeSupplierBalanceSnapshot')
                ? financeSupplierBalanceSnapshot($conn, (int)$row['id'])
                : [];
            $data['suppliers'][] = [
                'id' => (int)$row['id'],
                'name' => (string)($row['name'] ?? ''),
                'opening_balance' => round((float)($row['opening_balance'] ?? 0), 2),
                'breakdown' => $breakdown,
            ];
        }

        return $data;
    }
}

if (!function_exists('financeTransactionEffectSnapshot')) {
    function financeTransactionEffectSnapshot(mysqli $conn, array $receiptRow): array
    {
        $receiptId = (int)($receiptRow['id'] ?? 0);
        $type = strtolower(trim((string)($receiptRow['type'] ?? '')));
        $category = strtolower(trim((string)($receiptRow['category'] ?? '')));
        $targets = function_exists('financeReceiptAllocationTargets')
            ? financeReceiptAllocationTargets($conn, $receiptId)
            : [];
        $allocationSummary = function_exists('financeReceiptAllocationSummary')
            ? financeReceiptAllocationSummary($conn, $receiptRow)
            : ['count' => 0, 'allocated_amount' => 0.0, 'unallocated_amount' => 0.0, 'lines' => []];
        $counterpartySummary = function_exists('financeCounterpartyBalanceSummary')
            ? financeCounterpartyBalanceSummary($conn, $receiptRow)
            : ['label' => app_tr('غير متاح', 'Unavailable'), 'amount' => 0.0, 'kind' => 'unknown'];
        $isOpening = function_exists('financeReceiptIsOpeningBalance')
            ? financeReceiptIsOpeningBalance($conn, $receiptRow, $targets)
            : false;

        return [
            'receipt_id' => $receiptId,
            'type' => $type,
            'category' => $category,
            'amount' => round((float)($receiptRow['amount'] ?? 0), 2),
            'targets' => $targets,
            'allocation_summary' => $allocationSummary,
            'counterparty_summary' => $counterpartySummary,
            'is_opening_balance' => $isOpening,
            'invoice_id' => (int)($receiptRow['invoice_id'] ?? 0),
            'client_id' => (int)($receiptRow['client_id'] ?? 0),
            'supplier_id' => (int)($receiptRow['supplier_id'] ?? 0),
            'employee_id' => (int)($receiptRow['employee_id'] ?? 0),
            'payroll_id' => (int)($receiptRow['payroll_id'] ?? 0),
        ];
    }
}

if (!function_exists('financeTransactionDeleteService')) {
    function financeTransactionDeleteService(mysqli $conn, int $receiptId): array
    {
        if (!function_exists('finance_delete_transaction')) {
            return ['ok' => false, 'message' => app_tr('خدمة حذف السند غير متاحة.', 'Delete transaction service is unavailable.')];
        }
        return finance_delete_transaction($conn, $receiptId);
    }
}

if (!function_exists('financeTransactionSaveService')) {
    function financeTransactionSaveService(mysqli $conn, array $payload, string $sessionUser): array
    {
        if (!function_exists('finance_save_transaction')) {
            return ['ok' => false, 'message' => app_tr('خدمة حفظ السند غير متاحة.', 'Save transaction service is unavailable.')];
        }
        return finance_save_transaction($conn, $payload, $sessionUser);
    }
}

if (!function_exists('financeTransactionReallocateService')) {
    function financeTransactionReallocateService(mysqli $conn, int $receiptId, string $sessionUser = ''): array
    {
        if (!function_exists('finance_reallocate_receipt_fifo')) {
            return ['ok' => false, 'message' => app_tr('خدمة إعادة تخصيص السند غير متاحة.', 'Receipt reallocation service is unavailable.')];
        }
        return finance_reallocate_receipt_fifo($conn, $receiptId, $sessionUser);
    }
}

if (!function_exists('financeSalesInvoiceCancelService')) {
    function financeSalesInvoiceCancelService(mysqli $conn, int $invoiceId, string $actor = 'System'): array
    {
        $invoiceId = (int)$invoiceId;
        if ($invoiceId <= 0) {
            return ['ok' => false, 'message' => 'invalid_invoice_id'];
        }

        try {
            $conn->begin_transaction();
            $etaSelectParts = [];
            foreach (['eta_uuid', 'eta_status', 'eta_submission_id', 'eta_long_id'] as $column) {
                $hasColumn = function_exists('app_table_has_column')
                    ? app_table_has_column($conn, 'invoices', $column)
                    : false;
                $etaSelectParts[] = $hasColumn
                    ? "IFNULL($column, '') AS $column"
                    : "'' AS $column";
            }
            $stmt = $conn->prepare("
                SELECT id, status,
                       " . implode(",\n                       ", $etaSelectParts) . "
                FROM invoices
                WHERE id = ?
                LIMIT 1
            ");
            if (!$stmt) {
                throw new RuntimeException($conn->error);
            }
            $stmt->bind_param('i', $invoiceId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc() ?: null;
            $stmt->close();
            if (!$row) {
                throw new RuntimeException('sales_invoice_not_found');
            }
            if ((string)($row['status'] ?? '') === 'cancelled') {
                $conn->commit();
                return ['ok' => true, 'code' => 'already_cancelled'];
            }

            $hasEtaBinding = trim((string)($row['eta_uuid'] ?? '')) !== ''
                || trim((string)($row['eta_status'] ?? '')) !== ''
                || trim((string)($row['eta_submission_id'] ?? '')) !== ''
                || trim((string)($row['eta_long_id'] ?? '')) !== '';
            if ($hasEtaBinding) {
                throw new RuntimeException('sales_eta_locked');
            }

            if (function_exists('financeEnsureAllocationSchema')) {
                financeEnsureAllocationSchema($conn);
            }
            $linkedStmt = $conn->prepare("
                SELECT
                    IFNULL(SUM(CASE WHEN invoice_id = ? THEN 1 ELSE 0 END), 0) AS direct_receipt_count,
                    IFNULL((
                        SELECT COUNT(*)
                        FROM financial_receipt_allocations
                        WHERE allocation_type = 'sales_invoice' AND target_id = ?
                    ), 0) AS allocated_receipt_count
                FROM financial_receipts
                WHERE type = 'in'
            ");
            $linkedStmt->bind_param('ii', $invoiceId, $invoiceId);
            $linkedStmt->execute();
            $linked = $linkedStmt->get_result()->fetch_assoc() ?: [];
            $linkedStmt->close();
            if (((int)($linked['direct_receipt_count'] ?? 0) > 0) || ((int)($linked['allocated_receipt_count'] ?? 0) > 0)) {
                throw new RuntimeException('sales_paid');
            }

            if (app_table_exists($conn, 'eta_outbox')) {
                if ($stmtEta = $conn->prepare("DELETE FROM eta_outbox WHERE invoice_id = ?")) {
                    $stmtEta->bind_param('i', $invoiceId);
                    $stmtEta->execute();
                    $stmtEta->close();
                }
            }

            $stmtCancel = $conn->prepare("UPDATE invoices SET paid_amount = 0, remaining_amount = 0, status = 'cancelled' WHERE id = ?");
            if (!$stmtCancel) {
                throw new RuntimeException($conn->error);
            }
            $stmtCancel->bind_param('i', $invoiceId);
            $stmtCancel->execute();
            $stmtCancel->close();

            if (function_exists('app_audit_log_add')) {
                app_audit_log_add($conn, 'sales_invoice.cancelled', [
                    'entity_type' => 'invoice',
                    'entity_key' => (string)$invoiceId,
                    'details' => [
                        'actor' => $actor,
                        'reason' => 'invoice_delete_action_soft_cancel',
                    ],
                ]);
            }
            $conn->commit();
            return ['ok' => true, 'code' => 'cancelled'];
        } catch (Throwable $e) {
            $conn->rollback();
            return ['ok' => false, 'message' => (string)$e->getMessage()];
        }
    }
}
