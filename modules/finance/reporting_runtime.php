<?php

if (!function_exists('finance_dashboard_stats')) {
    function finance_dashboard_stats(mysqli $conn): array
    {
        $total_in = (float)($conn->query("SELECT IFNULL(SUM(amount),0) FROM financial_receipts WHERE type='in' AND description NOT LIKE 'تسوية رصيد أول المدة%'")->fetch_row()[0] ?? 0);
        $total_out = (float)($conn->query("SELECT IFNULL(SUM(amount),0) FROM financial_receipts WHERE type='out' AND description NOT LIKE 'تسوية رصيد أول المدة%'")->fetch_row()[0] ?? 0);
        $net = $total_in - $total_out;

        $monthStart = date('Y-m-01');
        $monthly_in = (float)($conn->query("SELECT IFNULL(SUM(amount),0) FROM financial_receipts WHERE type='in' AND description NOT LIKE 'تسوية رصيد أول المدة%' AND trans_date >= '{$monthStart}'")->fetch_row()[0] ?? 0);
        $monthly_out = (float)($conn->query("SELECT IFNULL(SUM(amount),0) FROM financial_receipts WHERE type='out' AND description NOT LIKE 'تسوية رصيد أول المدة%' AND trans_date >= '{$monthStart}'")->fetch_row()[0] ?? 0);
        $monthly_net = $monthly_in - $monthly_out;

        $avg_daily_out_90 = (float)($conn->query("SELECT IFNULL(SUM(amount),0)/90 FROM financial_receipts WHERE type='out' AND description NOT LIKE 'تسوية رصيد أول المدة%' AND trans_date >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)")->fetch_row()[0] ?? 0);
        $cash_runway_days = $avg_daily_out_90 > 0 ? (int)floor(max(0, $net) / $avg_daily_out_90) : null;

        $payroll_due = (float)($conn->query("SELECT IFNULL(SUM(remaining_amount),0) FROM payroll_sheets WHERE status != 'paid'")->fetch_row()[0] ?? 0);
        $purchases_due = (float)($conn->query("SELECT IFNULL(SUM(remaining_amount),0) FROM purchase_invoices WHERE status NOT IN ('paid','cancelled')")->fetch_row()[0] ?? 0);
        $receivables_due = (float)($conn->query("SELECT IFNULL(SUM(remaining_amount),0) FROM invoices WHERE status NOT IN ('paid','cancelled')")->fetch_row()[0] ?? 0);

        $finance_signal = 'مستقر';
        if ($monthly_net < 0 || ($cash_runway_days !== null && $cash_runway_days < 30)) {
            $finance_signal = 'تحت الضغط';
        }
        if ($monthly_net < 0 && ($cash_runway_days !== null && $cash_runway_days < 14)) {
            $finance_signal = 'خطر نقدي';
        }

        return [
            'total_in' => $total_in,
            'total_out' => $total_out,
            'net' => $net,
            'month_start' => $monthStart,
            'monthly_in' => $monthly_in,
            'monthly_out' => $monthly_out,
            'monthly_net' => $monthly_net,
            'avg_daily_out_90' => $avg_daily_out_90,
            'cash_runway_days' => $cash_runway_days,
            'payroll_due' => $payroll_due,
            'purchases_due' => $purchases_due,
            'receivables_due' => $receivables_due,
            'finance_signal' => $finance_signal,
        ];
    }
}

if (!function_exists('finance_reference_payloads')) {
    function finance_reference_payloads(mysqli $conn): array
    {
        $payrolls = [];
        $qp = $conn->query("SELECT id, employee_id, month_year, remaining_amount FROM payroll_sheets WHERE status != 'paid'");
        if ($qp) {
            while ($p = $qp->fetch_assoc()) {
                $payrolls[] = $p;
            }
        }

        $salesInvoices = [];
        $qSales = $conn->query("SELECT id, client_id, remaining_amount FROM invoices WHERE status NOT IN ('paid','cancelled')");
        if ($qSales) {
            while ($row = $qSales->fetch_assoc()) {
                $salesInvoices[] = $row;
            }
        }

        $purchaseInvoices = [];
        $qPurchases = $conn->query("SELECT id, supplier_id, remaining_amount FROM purchase_invoices WHERE status NOT IN ('paid','cancelled')");
        if ($qPurchases) {
            while ($row = $qPurchases->fetch_assoc()) {
                $purchaseInvoices[] = $row;
            }
        }

        return [
            'payrolls' => $payrolls,
            'sales_invoices' => $salesInvoices,
            'purchase_invoices' => $purchaseInvoices,
            'tax_laws' => function_exists('app_tax_law_catalog') ? app_tax_law_catalog($conn, true) : [],
        ];
    }
}

if (!function_exists('finance_form_options')) {
    function finance_form_options(mysqli $conn): array
    {
        $clients = [];
        $cl = $conn->query("SELECT id, name FROM clients ORDER BY name ASC");
        if ($cl) {
            while ($row = $cl->fetch_assoc()) {
                $clients[] = $row;
            }
        }

        $suppliers = [];
        $sups = $conn->query("SELECT id, name FROM suppliers ORDER BY name ASC");
        if ($sups) {
            while ($row = $sups->fetch_assoc()) {
                $suppliers[] = $row;
            }
        }

        $employees = [];
        $emps = $conn->query("SELECT id, full_name AS name FROM users WHERE is_active = 1 AND archived_at IS NULL ORDER BY full_name ASC");
        if ($emps) {
            while ($row = $emps->fetch_assoc()) {
                $employees[] = $row;
            }
        }

        return [
            'clients' => $clients,
            'suppliers' => $suppliers,
            'employees' => $employees,
            'tax_laws' => function_exists('app_tax_law_catalog') ? app_tax_law_catalog($conn, true) : [],
        ];
    }
}

if (!function_exists('finance_journal_entries')) {
    function finance_journal_entries(mysqli $conn, int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));
        $rows = [];
        $sql = "SELECT t.*, c.name AS cname, s.name AS sname, u.full_name AS ename
                FROM financial_receipts t
                LEFT JOIN clients c ON t.client_id = c.id
                LEFT JOIN suppliers s ON t.supplier_id = s.id
                LEFT JOIN users u ON t.employee_id = u.id
                ORDER BY t.trans_date DESC, t.id DESC
                LIMIT {$limit}";
        $hist = $conn->query($sql);
        if ($hist) {
            while ($row = $hist->fetch_assoc()) {
                $rows[] = $row;
            }
        }
        return $rows;
    }
}

if (!function_exists('finance_credit_watch_report')) {
    function finance_credit_watch_report(mysqli $conn): array
    {
        financeEnsureAllocationSchema($conn);
        $clientCredits = [];
        $clientCreditTotal = 0.0;
        $clientCreditSql = "
            SELECT
                q.client_id,
                q.client_name,
                q.phone,
                q.net_balance
            FROM (
                SELECT
                    c.id AS client_id,
                    c.name AS client_name,
                    c.phone,
                    ROUND(
                        (
                            CASE
                                WHEN c.opening_balance > 0 THEN
                                    GREATEST(
                                        c.opening_balance
                                        - IFNULL(opening_legacy.legacy_opening_paid, 0)
                                        - IFNULL(opening_alloc.opening_applied, 0),
                                        0
                                    )
                                ELSE 0
                            END
                        )
                        + IFNULL(inv.invoice_due, 0)
                        - (
                            CASE
                                WHEN c.opening_balance < 0 THEN ABS(c.opening_balance)
                                ELSE 0
                            END
                            + IFNULL(rc.receipt_credit, 0)
                        ),
                        2
                    ) AS net_balance
                FROM clients c
                LEFT JOIN (
                    SELECT client_id, IFNULL(SUM(remaining_amount), 0) AS invoice_due
                    FROM invoices
                    WHERE IFNULL(remaining_amount, 0) > 0.00001
                    GROUP BY client_id
                ) inv ON inv.client_id = c.id
                LEFT JOIN (
                    SELECT r.client_id, IFNULL(SUM(a.amount), 0) AS opening_applied
                    FROM financial_receipt_allocations a
                    INNER JOIN financial_receipts r ON r.id = a.receipt_id
                    WHERE r.type = 'in' AND a.allocation_type = 'client_opening'
                    GROUP BY r.client_id
                ) opening_alloc ON opening_alloc.client_id = c.id
                LEFT JOIN (
                    SELECT r.client_id, IFNULL(SUM(r.amount), 0) AS legacy_opening_paid
                    FROM financial_receipts r
                    LEFT JOIN (
                        SELECT receipt_id, COUNT(*) AS allocation_count
                        FROM financial_receipt_allocations
                        GROUP BY receipt_id
                    ) ac ON ac.receipt_id = r.id
                    WHERE r.type = 'in'
                      AND LOWER(TRIM(IFNULL(r.category, ''))) IN ('opening_balance', 'client_opening')
                      AND (
                            TRIM(IFNULL(r.description, '')) LIKE 'تسوية رصيد أول المدة%'
                            OR LOWER(TRIM(IFNULL(r.description, ''))) LIKE '%opening balance%'
                          )
                      AND IFNULL(ac.allocation_count, 0) = 0
                    GROUP BY r.client_id
                ) opening_legacy ON opening_legacy.client_id = c.id
                LEFT JOIN (
                    SELECT
                        r.client_id,
                        IFNULL(SUM(
                            ROUND(
                                r.amount - CASE
                                    WHEN IFNULL(r.invoice_id, 0) > 0 THEN r.amount
                                    WHEN IFNULL(a.allocated_amount, 0) > 0 THEN IFNULL(a.allocated_amount, 0)
                                    ELSE 0
                                END,
                                2
                            )
                        ), 0) AS receipt_credit
                    FROM financial_receipts r
                    LEFT JOIN (
                        SELECT receipt_id, IFNULL(SUM(amount), 0) AS allocated_amount
                        FROM financial_receipt_allocations
                        GROUP BY receipt_id
                    ) a ON a.receipt_id = r.id
                    WHERE r.type = 'in'
                      AND LOWER(TRIM(IFNULL(r.category, ''))) NOT IN ('opening_balance', 'client_opening')
                      AND ROUND(
                            r.amount - CASE
                                WHEN IFNULL(r.invoice_id, 0) > 0 THEN r.amount
                                WHEN IFNULL(a.allocated_amount, 0) > 0 THEN IFNULL(a.allocated_amount, 0)
                                ELSE 0
                            END,
                            2
                          ) > 0.00001
                    GROUP BY r.client_id
                ) rc ON rc.client_id = c.id
            ) q
            WHERE q.net_balance < -0.00001
            ORDER BY q.client_name ASC
        ";
        $clientRes = $conn->query($clientCreditSql);
        while ($clientRes && ($row = $clientRes->fetch_assoc())) {
            $netBalance = round((float)($row['net_balance'] ?? 0), 2);
            if ($netBalance >= -0.00001) {
                continue;
            }
            $creditAmount = abs($netBalance);
            $clientCredits[] = [
                'client_id' => (int)($row['client_id'] ?? 0),
                'client_name' => (string)($row['client_name'] ?? ''),
                'phone' => (string)($row['phone'] ?? ''),
                'credit_amount' => $creditAmount,
            ];
            $clientCreditTotal += $creditAmount;
        }

        $receiptCredits = [];
        $receiptCreditTotal = 0.0;
        $receiptCreditSql = "
            SELECT
                r.id AS receipt_id,
                r.trans_date,
                r.amount,
                r.description,
                r.client_id,
                c.name AS client_name,
                ROUND(
                    r.amount - CASE
                        WHEN IFNULL(r.invoice_id, 0) > 0 THEN r.amount
                        WHEN IFNULL(a.allocated_amount, 0) > 0 THEN IFNULL(a.allocated_amount, 0)
                        ELSE 0
                    END,
                    2
                ) AS unallocated_amount
            FROM financial_receipts r
            LEFT JOIN clients c ON c.id = r.client_id
            LEFT JOIN (
                SELECT receipt_id, IFNULL(SUM(amount), 0) AS allocated_amount
                FROM financial_receipt_allocations
                GROUP BY receipt_id
            ) a ON a.receipt_id = r.id
            WHERE r.type = 'in'
              AND LOWER(TRIM(IFNULL(r.category, ''))) NOT IN ('opening_balance', 'client_opening')
              AND ROUND(
                    r.amount - CASE
                        WHEN IFNULL(r.invoice_id, 0) > 0 THEN r.amount
                        WHEN IFNULL(a.allocated_amount, 0) > 0 THEN IFNULL(a.allocated_amount, 0)
                        ELSE 0
                    END,
                    2
                  ) > 0.00001
            ORDER BY r.trans_date DESC, r.id DESC
        ";
        $receiptRes = $conn->query($receiptCreditSql);
        while ($receiptRes && ($row = $receiptRes->fetch_assoc())) {
            $unallocated = round((float)($row['unallocated_amount'] ?? 0), 2);
            if ($unallocated <= 0.00001) {
                continue;
            }
            $receiptCredits[] = [
                'receipt_id' => (int)($row['receipt_id'] ?? 0),
                'trans_date' => (string)($row['trans_date'] ?? ''),
                'amount' => (float)($row['amount'] ?? 0),
                'unallocated_amount' => $unallocated,
                'description' => (string)($row['description'] ?? ''),
                'client_id' => (int)($row['client_id'] ?? 0),
                'client_name' => (string)($row['client_name'] ?? ''),
            ];
            $receiptCreditTotal += $unallocated;
        }

        return [
            'client_credit_count' => count($clientCredits),
            'client_credit_total' => round($clientCreditTotal, 2),
            'receipt_credit_count' => count($receiptCredits),
            'receipt_credit_total' => round($receiptCreditTotal, 2),
            'client_credits' => $clientCredits,
            'receipt_credits' => $receiptCredits,
        ];
    }
}
