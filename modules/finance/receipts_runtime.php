<?php

if (!function_exists('isOpeningBalanceReceiptRow')) {
    function isOpeningBalanceReceiptRow(?array $row): bool {
        if (!$row) {
            return false;
        }
        $desc = trim((string)($row['description'] ?? ''));
        if ($desc !== '' && (
            mb_strpos($desc, 'تسوية رصيد أول المدة') === 0 ||
            mb_stripos($desc, 'opening balance') !== false
        )) {
            return true;
        }

        $category = strtolower(trim((string)($row['category'] ?? '')));
        return in_array($category, ['opening_balance', 'client_opening', 'supplier_opening'], true);
    }
}

if (!function_exists('financeReceiptIsOpeningBalance')) {
    function financeReceiptIsOpeningBalance(mysqli $conn, ?array $row, ?array $targets = null): bool
    {
        if (isOpeningBalanceReceiptRow($row)) {
            return true;
        }

        if ($targets === null) {
            $receiptId = (int)($row['id'] ?? 0);
            if ($receiptId > 0) {
                $targets = financeReceiptAllocationTargets($conn, $receiptId);
            }
        }

        $hasOpeningTargets = !empty($targets['client_opening']) || !empty($targets['supplier_opening']);
        if (!$hasOpeningTargets) {
            return false;
        }

        $hasNonOpeningTargets = !empty($targets['sales_invoice'])
            || !empty($targets['purchase_invoice'])
            || !empty($targets['payroll'])
            || !empty($targets['loan_advance']);

        return !$hasNonOpeningTargets;
    }
}

if (!function_exists('financeOpeningBalanceNormalizeMode')) {
    function financeOpeningBalanceNormalizeMode(string $mode): string
    {
        $mode = strtolower(trim($mode));
        return in_array($mode, ['positive', 'negative', 'both', 'none'], true) ? $mode : 'positive';
    }
}

if (!function_exists('financeAdjustOpeningBalanceValue')) {
    function financeAdjustOpeningBalanceValue(float $currentOpening, float $amount, string $mode, bool $reverse = false): float
    {
        $amount = round($amount, 2);
        if ($amount <= 0.00001) {
            return round($currentOpening, 2);
        }

        $mode = financeOpeningBalanceNormalizeMode($mode);
        if ($mode === 'none') {
            return round($currentOpening, 2);
        }

        if ($mode === 'positive') {
            $newValue = $currentOpening + ($reverse ? $amount : (-1 * $amount));
        } elseif ($mode === 'negative') {
            $newValue = $currentOpening + ($reverse ? (-1 * $amount) : $amount);
        } else {
            $sign = 1;
            if ($currentOpening < -0.00001) {
                $sign = -1;
            } elseif ($currentOpening > 0.00001) {
                $sign = 1;
            }
            $delta = $sign * $amount;
            $newValue = $currentOpening + ($reverse ? $delta : (-1 * $delta));
        }

        if (abs($newValue) < 0.00001) {
            $newValue = 0.0;
        }

        return round($newValue, 2);
    }
}

if (!function_exists('financeSyncOpeningBalanceReceiptChange')) {
    function financeSyncOpeningBalanceReceiptChange(mysqli $conn, array $receiptRow, float $newAmount, ?string $newDate = null): array
    {
        $receiptId = (int)($receiptRow['id'] ?? 0);
        $type = strtolower(trim((string)($receiptRow['type'] ?? '')));
        $oldAmount = round((float)($receiptRow['amount'] ?? 0), 2);
        $invoiceId = (int)($receiptRow['invoice_id'] ?? 0);
        $clientId = (int)($receiptRow['client_id'] ?? 0);
        $supplierId = (int)($receiptRow['supplier_id'] ?? 0);
        $description = (string)($receiptRow['description'] ?? '');
        $newAmount = round((float)$newAmount, 2);

        if ($receiptId <= 0 || $oldAmount < 0 || $newAmount < 0) {
            return ['ok' => false, 'message' => app_tr('بيانات سند الافتتاح غير صالحة.', 'Invalid opening balance receipt payload.')];
        }

        if ($type === 'in' && $clientId > 0) {
            $mode = financeOpeningBalanceNormalizeMode((string)app_setting_get($conn, 'opening_balance_deduction_sign', 'positive'));
            $clientStmt = $conn->prepare("SELECT opening_balance FROM clients WHERE id = ? LIMIT 1");
            $clientStmt->bind_param('i', $clientId);
            $clientStmt->execute();
            $client = $clientStmt->get_result()->fetch_assoc();
            $clientStmt->close();
            if (!$client) {
                return ['ok' => false, 'message' => app_tr('العميل المرتبط بسند الافتتاح غير موجود.', 'The client linked to this opening balance receipt was not found.')];
            }

            $openingCurrent = (float)($client['opening_balance'] ?? 0);
            $openingRestored = financeAdjustOpeningBalanceValue($openingCurrent, $oldAmount, $mode, true);
            $openingFinal = financeAdjustOpeningBalanceValue($openingRestored, $newAmount, $mode, false);

            $updateClientStmt = $conn->prepare("UPDATE clients SET opening_balance = ? WHERE id = ?");
            $updateClientStmt->bind_param('di', $openingFinal, $clientId);
            $updateClientStmt->execute();
            $updateClientStmt->close();

            $dateValue = $newDate ?: (string)($receiptRow['trans_date'] ?? date('Y-m-d'));
            if (!preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $dateValue)) {
                $dateValue = date('Y-m-d');
            }
            $category = (string)($receiptRow['category'] ?? 'general');
            $updateReceiptStmt = $conn->prepare("UPDATE financial_receipts SET amount = ?, trans_date = ?, description = ?, category = ?, client_id = ?, invoice_id = ?, supplier_id = NULL, employee_id = NULL, payroll_id = NULL WHERE id = ?");
            $updateReceiptStmt->bind_param('dsssiii', $newAmount, $dateValue, $description, $category, $clientId, $invoiceId, $receiptId);
            $updateReceiptStmt->execute();
            $updateReceiptStmt->close();

            if ($invoiceId > 0) {
                recalculateSalesInvoice($conn, $invoiceId);
            }

            return ['ok' => true, 'kind' => 'client_opening', 'invoice_id' => $invoiceId, 'client_id' => $clientId];
        }

        if ($type === 'out' && $supplierId > 0) {
            $mode = financeOpeningBalanceNormalizeMode((string)app_setting_get($conn, 'supplier_opening_balance_deduction_sign', app_setting_get($conn, 'opening_balance_deduction_sign', 'positive')));
            $supplierStmt = $conn->prepare("SELECT opening_balance FROM suppliers WHERE id = ? LIMIT 1");
            $supplierStmt->bind_param('i', $supplierId);
            $supplierStmt->execute();
            $supplier = $supplierStmt->get_result()->fetch_assoc();
            $supplierStmt->close();
            if (!$supplier) {
                return ['ok' => false, 'message' => app_tr('المورد المرتبط بسند الافتتاح غير موجود.', 'The supplier linked to this opening balance receipt was not found.')];
            }

            $openingCurrent = (float)($supplier['opening_balance'] ?? 0);
            $openingRestored = financeAdjustOpeningBalanceValue($openingCurrent, $oldAmount, $mode, true);
            $openingFinal = financeAdjustOpeningBalanceValue($openingRestored, $newAmount, $mode, false);

            $updateSupplierStmt = $conn->prepare("UPDATE suppliers SET opening_balance = ? WHERE id = ?");
            $updateSupplierStmt->bind_param('di', $openingFinal, $supplierId);
            $updateSupplierStmt->execute();
            $updateSupplierStmt->close();

            $dateValue = $newDate ?: (string)($receiptRow['trans_date'] ?? date('Y-m-d'));
            if (!preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $dateValue)) {
                $dateValue = date('Y-m-d');
            }
            $category = (string)($receiptRow['category'] ?? 'supplier');
            $updateReceiptStmt = $conn->prepare("UPDATE financial_receipts SET amount = ?, trans_date = ?, description = ?, category = ?, supplier_id = ?, invoice_id = ?, client_id = NULL, employee_id = NULL, payroll_id = NULL WHERE id = ?");
            $updateReceiptStmt->bind_param('dsssiii', $newAmount, $dateValue, $description, $category, $supplierId, $invoiceId, $receiptId);
            $updateReceiptStmt->execute();
            $updateReceiptStmt->close();

            if ($invoiceId > 0) {
                recalculatePurchaseInvoice($conn, $invoiceId);
            }

            return ['ok' => true, 'kind' => 'supplier_opening', 'invoice_id' => $invoiceId, 'supplier_id' => $supplierId];
        }

        return ['ok' => false, 'message' => app_tr('نوع سند الافتتاح غير مدعوم للتعديل.', 'This opening balance receipt type is not supported for editing.')];
    }
}

if (!function_exists('financeEnsureAllocationSchema')) {
    function financeEnsureAllocationSchema(mysqli $conn): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;

        try {
            $conn->query("
                CREATE TABLE IF NOT EXISTS financial_receipt_allocations (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    receipt_id INT NOT NULL,
                    allocation_type VARCHAR(40) NOT NULL,
                    target_id INT DEFAULT NULL,
                    amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                    notes VARCHAR(255) DEFAULT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    KEY idx_receipt_alloc_receipt (receipt_id),
                    KEY idx_receipt_alloc_target (allocation_type, target_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        } catch (Throwable $e) {
            error_log('finance allocation schema bootstrap failed: ' . $e->getMessage());
        }

        try {
            if (function_exists('app_table_has_column') && !app_table_has_column($conn, 'financial_receipts', 'tax_law_key')) {
                $conn->query("ALTER TABLE financial_receipts ADD COLUMN tax_law_key VARCHAR(60) DEFAULT NULL AFTER category");
                if (function_exists('app_table_has_column_reset')) {
                    app_table_has_column_reset('financial_receipts', 'tax_law_key');
                }
            }
        } catch (Throwable $e) {
            error_log('finance tax law schema bootstrap failed: ' . $e->getMessage());
        }
    }
}

if (!function_exists('financeReceiptAllocationTargets')) {
    function financeReceiptAllocationTargets(mysqli $conn, int $receiptId): array
    {
        financeEnsureAllocationSchema($conn);
        $targets = [
            'sales_invoice' => [],
            'purchase_invoice' => [],
            'payroll' => [],
            'client_opening' => [],
            'supplier_opening' => [],
            'loan_advance' => [],
        ];
        if ($receiptId <= 0) {
            return $targets;
        }

        $stmt = $conn->prepare("SELECT allocation_type, target_id FROM financial_receipt_allocations WHERE receipt_id = ?");
        $stmt->bind_param('i', $receiptId);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $type = strtolower(trim((string)($row['allocation_type'] ?? '')));
            $targetId = (int)($row['target_id'] ?? 0);
            if ($targetId <= 0 || !isset($targets[$type])) {
                continue;
            }
            $targets[$type][$targetId] = $targetId;
        }
        $stmt->close();

        foreach ($targets as $type => $ids) {
            $targets[$type] = array_values($ids);
        }

        return $targets;
    }
}

if (!function_exists('financeLegacyDirectSalesReceiptStats')) {
    function financeLegacyDirectSalesReceiptStats(mysqli $conn, int $clientId, ?string $asOfDate = null): array
    {
        financeEnsureAllocationSchema($conn);
        $clientId = (int)$clientId;
        if ($clientId <= 0) {
            return ['per_receipt' => [], 'allocated_total' => 0.0, 'credit_total' => 0.0];
        }

        $hasAsOf = $asOfDate !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $asOfDate);
        if ($hasAsOf) {
            $invoiceStmt = $conn->prepare("
                SELECT i.id, i.total_amount, IFNULL(a.alloc_total, 0) AS explicit_alloc_total
                FROM invoices i
                LEFT JOIN (
                    SELECT a.target_id, IFNULL(SUM(a.amount), 0) AS alloc_total
                    FROM financial_receipt_allocations a
                    INNER JOIN financial_receipts r ON r.id = a.receipt_id
                    WHERE a.allocation_type = 'sales_invoice'
                      AND r.type = 'in'
                      AND r.trans_date < ?
                    GROUP BY a.target_id
                ) a ON a.target_id = i.id
                WHERE i.client_id = ?
                  AND COALESCE(i.status, '') <> 'cancelled'
                  AND DATE(COALESCE(i.inv_date, i.created_at)) < ?
            ");
            $invoiceStmt->bind_param('sis', $asOfDate, $clientId, $asOfDate);
        } else {
            $invoiceStmt = $conn->prepare("
                SELECT i.id, i.total_amount, IFNULL(a.alloc_total, 0) AS explicit_alloc_total
                FROM invoices i
                LEFT JOIN (
                    SELECT a.target_id, IFNULL(SUM(a.amount), 0) AS alloc_total
                    FROM financial_receipt_allocations a
                    INNER JOIN financial_receipts r ON r.id = a.receipt_id
                    WHERE a.allocation_type = 'sales_invoice'
                      AND r.type = 'in'
                    GROUP BY a.target_id
                ) a ON a.target_id = i.id
                WHERE i.client_id = ?
                  AND COALESCE(i.status, '') <> 'cancelled'
            ");
            $invoiceStmt->bind_param('i', $clientId);
        }
        $invoiceStmt->execute();
        $invoiceRes = $invoiceStmt->get_result();
        $invoiceCaps = [];
        while ($invoice = $invoiceRes->fetch_assoc()) {
            $invoiceId = (int)($invoice['id'] ?? 0);
            if ($invoiceId <= 0) {
                continue;
            }
            $cap = round(max(0.0, (float)($invoice['total_amount'] ?? 0) - (float)($invoice['explicit_alloc_total'] ?? 0)), 2);
            $invoiceCaps[$invoiceId] = $cap;
        }
        $invoiceStmt->close();

        if ($hasAsOf) {
            $receiptStmt = $conn->prepare("
                SELECT r.id, r.invoice_id, r.amount
                FROM financial_receipts r
                LEFT JOIN (
                    SELECT receipt_id, COUNT(*) AS allocation_count
                    FROM financial_receipt_allocations
                    GROUP BY receipt_id
                ) ac ON ac.receipt_id = r.id
                WHERE r.client_id = ?
                  AND r.type = 'in'
                  AND IFNULL(r.invoice_id, 0) > 0
                  AND IFNULL(ac.allocation_count, 0) = 0
                  AND r.trans_date < ?
                ORDER BY r.trans_date ASC, r.id ASC
            ");
            $receiptStmt->bind_param('is', $clientId, $asOfDate);
        } else {
            $receiptStmt = $conn->prepare("
                SELECT r.id, r.invoice_id, r.amount
                FROM financial_receipts r
                LEFT JOIN (
                    SELECT receipt_id, COUNT(*) AS allocation_count
                    FROM financial_receipt_allocations
                    GROUP BY receipt_id
                ) ac ON ac.receipt_id = r.id
                WHERE r.client_id = ?
                  AND r.type = 'in'
                  AND IFNULL(r.invoice_id, 0) > 0
                  AND IFNULL(ac.allocation_count, 0) = 0
                ORDER BY r.trans_date ASC, r.id ASC
            ");
            $receiptStmt->bind_param('i', $clientId);
        }
        $receiptStmt->execute();
        $receiptRes = $receiptStmt->get_result();

        $perReceipt = [];
        $perInvoiceAllocated = [];
        $allocatedTotal = 0.0;
        $creditTotal = 0.0;
        while ($receipt = $receiptRes->fetch_assoc()) {
            $receiptId = (int)($receipt['id'] ?? 0);
            $invoiceId = (int)($receipt['invoice_id'] ?? 0);
            $amount = round((float)($receipt['amount'] ?? 0), 2);
            if ($receiptId <= 0 || $invoiceId <= 0 || $amount <= 0.00001) {
                continue;
            }

            $remainingCap = round((float)($invoiceCaps[$invoiceId] ?? 0), 2);
            $allocated = min($amount, max(0.0, $remainingCap));
            $credit = max(0.0, round($amount - $allocated, 2));
            $invoiceCaps[$invoiceId] = max(0.0, round($remainingCap - $allocated, 2));

            $perReceipt[$receiptId] = [
                'invoice_id' => $invoiceId,
                'allocated_amount' => round($allocated, 2),
                'credit_amount' => round($credit, 2),
            ];
            $perInvoiceAllocated[$invoiceId] = round((float)($perInvoiceAllocated[$invoiceId] ?? 0) + $allocated, 2);
            $allocatedTotal += $allocated;
            $creditTotal += $credit;
        }
        $receiptStmt->close();

        return [
            'per_receipt' => $perReceipt,
            'per_invoice_allocated' => $perInvoiceAllocated,
            'allocated_total' => round($allocatedTotal, 2),
            'credit_total' => round($creditTotal, 2),
        ];
    }
}

if (!function_exists('financeLegacyDirectPurchaseReceiptStats')) {
    function financeLegacyDirectPurchaseReceiptStats(mysqli $conn, int $supplierId, ?string $asOfDate = null): array
    {
        financeEnsureAllocationSchema($conn);
        $supplierId = (int)$supplierId;
        if ($supplierId <= 0) {
            return ['per_receipt' => [], 'allocated_total' => 0.0, 'credit_total' => 0.0];
        }

        $hasAsOf = $asOfDate !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $asOfDate);
        if ($hasAsOf) {
            $invoiceStmt = $conn->prepare("
                SELECT i.id, i.total_amount, IFNULL(a.alloc_total, 0) AS explicit_alloc_total
                FROM purchase_invoices i
                LEFT JOIN (
                    SELECT a.target_id, IFNULL(SUM(a.amount), 0) AS alloc_total
                    FROM financial_receipt_allocations a
                    INNER JOIN financial_receipts r ON r.id = a.receipt_id
                    WHERE a.allocation_type = 'purchase_invoice'
                      AND r.type = 'out'
                      AND r.trans_date < ?
                    GROUP BY a.target_id
                ) a ON a.target_id = i.id
                WHERE i.supplier_id = ?
                  AND COALESCE(i.status, '') <> 'cancelled'
                  AND DATE(COALESCE(i.inv_date, i.created_at)) < ?
            ");
            $invoiceStmt->bind_param('sis', $asOfDate, $supplierId, $asOfDate);
        } else {
            $invoiceStmt = $conn->prepare("
                SELECT i.id, i.total_amount, IFNULL(a.alloc_total, 0) AS explicit_alloc_total
                FROM purchase_invoices i
                LEFT JOIN (
                    SELECT a.target_id, IFNULL(SUM(a.amount), 0) AS alloc_total
                    FROM financial_receipt_allocations a
                    INNER JOIN financial_receipts r ON r.id = a.receipt_id
                    WHERE a.allocation_type = 'purchase_invoice'
                      AND r.type = 'out'
                    GROUP BY a.target_id
                ) a ON a.target_id = i.id
                WHERE i.supplier_id = ?
                  AND COALESCE(i.status, '') <> 'cancelled'
            ");
            $invoiceStmt->bind_param('i', $supplierId);
        }
        $invoiceStmt->execute();
        $invoiceRes = $invoiceStmt->get_result();
        $invoiceCaps = [];
        while ($invoice = $invoiceRes->fetch_assoc()) {
            $invoiceId = (int)($invoice['id'] ?? 0);
            if ($invoiceId <= 0) {
                continue;
            }
            $cap = round(max(0.0, (float)($invoice['total_amount'] ?? 0) - (float)($invoice['explicit_alloc_total'] ?? 0)), 2);
            $invoiceCaps[$invoiceId] = $cap;
        }
        $invoiceStmt->close();

        if ($hasAsOf) {
            $receiptStmt = $conn->prepare("
                SELECT r.id, r.invoice_id, r.amount
                FROM financial_receipts r
                LEFT JOIN (
                    SELECT receipt_id, COUNT(*) AS allocation_count
                    FROM financial_receipt_allocations
                    GROUP BY receipt_id
                ) ac ON ac.receipt_id = r.id
                WHERE r.supplier_id = ?
                  AND r.type = 'out'
                  AND IFNULL(r.invoice_id, 0) > 0
                  AND IFNULL(ac.allocation_count, 0) = 0
                  AND r.trans_date < ?
                ORDER BY r.trans_date ASC, r.id ASC
            ");
            $receiptStmt->bind_param('is', $supplierId, $asOfDate);
        } else {
            $receiptStmt = $conn->prepare("
                SELECT r.id, r.invoice_id, r.amount
                FROM financial_receipts r
                LEFT JOIN (
                    SELECT receipt_id, COUNT(*) AS allocation_count
                    FROM financial_receipt_allocations
                    GROUP BY receipt_id
                ) ac ON ac.receipt_id = r.id
                WHERE r.supplier_id = ?
                  AND r.type = 'out'
                  AND IFNULL(r.invoice_id, 0) > 0
                  AND IFNULL(ac.allocation_count, 0) = 0
                ORDER BY r.trans_date ASC, r.id ASC
            ");
            $receiptStmt->bind_param('i', $supplierId);
        }
        $receiptStmt->execute();
        $receiptRes = $receiptStmt->get_result();

        $perReceipt = [];
        $perInvoiceAllocated = [];
        $allocatedTotal = 0.0;
        $creditTotal = 0.0;
        while ($receipt = $receiptRes->fetch_assoc()) {
            $receiptId = (int)($receipt['id'] ?? 0);
            $invoiceId = (int)($receipt['invoice_id'] ?? 0);
            $amount = round((float)($receipt['amount'] ?? 0), 2);
            if ($receiptId <= 0 || $invoiceId <= 0 || $amount <= 0.00001) {
                continue;
            }

            $remainingCap = round((float)($invoiceCaps[$invoiceId] ?? 0), 2);
            $allocated = min($amount, max(0.0, $remainingCap));
            $credit = max(0.0, round($amount - $allocated, 2));
            $invoiceCaps[$invoiceId] = max(0.0, round($remainingCap - $allocated, 2));

            $perReceipt[$receiptId] = [
                'invoice_id' => $invoiceId,
                'allocated_amount' => round($allocated, 2),
                'credit_amount' => round($credit, 2),
            ];
            $perInvoiceAllocated[$invoiceId] = round((float)($perInvoiceAllocated[$invoiceId] ?? 0) + $allocated, 2);
            $allocatedTotal += $allocated;
            $creditTotal += $credit;
        }
        $receiptStmt->close();

        return [
            'per_receipt' => $perReceipt,
            'per_invoice_allocated' => $perInvoiceAllocated,
            'allocated_total' => round($allocatedTotal, 2),
            'credit_total' => round($creditTotal, 2),
        ];
    }
}

if (!function_exists('financeDeleteReceiptAllocations')) {
    function financeDeleteReceiptAllocations(mysqli $conn, int $receiptId): void
    {
        financeEnsureAllocationSchema($conn);
        if ($receiptId <= 0) {
            return;
        }
        $stmt = $conn->prepare("DELETE FROM financial_receipt_allocations WHERE receipt_id = ?");
        $stmt->bind_param('i', $receiptId);
        $stmt->execute();
        $stmt->close();
    }
}

if (!function_exists('financeInsertReceiptAllocation')) {
    function financeInsertReceiptAllocation(mysqli $conn, int $receiptId, string $allocationType, int $targetId, float $amount, string $notes = ''): void
    {
        financeEnsureAllocationSchema($conn);
        $amount = round($amount, 2);
        if ($receiptId <= 0 || $targetId <= 0 || $amount <= 0.00001) {
            return;
        }
        $allocationType = strtolower(trim($allocationType));
        $notes = mb_substr(trim($notes), 0, 255);
        $stmt = $conn->prepare("INSERT INTO financial_receipt_allocations (receipt_id, allocation_type, target_id, amount, notes) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param('isids', $receiptId, $allocationType, $targetId, $amount, $notes);
        $stmt->execute();
        $stmt->close();
    }
}

if (!function_exists('financeAllocatedAmountByType')) {
    function financeAllocatedAmountByType(mysqli $conn, string $allocationType, int $targetId): float
    {
        financeEnsureAllocationSchema($conn);
        if ($targetId <= 0) {
            return 0.0;
        }
        $allocationType = strtolower(trim($allocationType));
        $stmt = $conn->prepare("SELECT IFNULL(SUM(amount), 0) FROM financial_receipt_allocations WHERE allocation_type = ? AND target_id = ?");
        $stmt->bind_param('si', $allocationType, $targetId);
        $stmt->execute();
        $sum = (float)($stmt->get_result()->fetch_row()[0] ?? 0);
        $stmt->close();
        return $sum;
    }
}

if (!function_exists('financeLegacyDirectAllocatedAmount')) {
    function financeLegacyDirectAllocatedAmount(mysqli $conn, string $allocationType, int $targetId): float
    {
        financeEnsureAllocationSchema($conn);
        $allocationType = strtolower(trim($allocationType));
        $targetId = (int)$targetId;
        if ($targetId <= 0) {
            return 0.0;
        }

        if ($allocationType === 'sales_invoice') {
            $stmt = $conn->prepare("SELECT client_id, status FROM invoices WHERE id = ? LIMIT 1");
            $stmt->bind_param('i', $targetId);
            $stmt->execute();
            $clientId = (int)($stmt->get_result()->fetch_row()[0] ?? 0);
            $stmt->close();
            if ($clientId <= 0) {
                return 0.0;
            }
            $stats = financeLegacyDirectSalesReceiptStats($conn, $clientId);
            return round((float)($stats['per_invoice_allocated'][$targetId] ?? 0), 2);
        } elseif ($allocationType === 'purchase_invoice') {
            $stmt = $conn->prepare("SELECT supplier_id FROM purchase_invoices WHERE id = ? LIMIT 1");
            $stmt->bind_param('i', $targetId);
            $stmt->execute();
            $supplierId = (int)($stmt->get_result()->fetch_row()[0] ?? 0);
            $stmt->close();
            if ($supplierId <= 0) {
                return 0.0;
            }
            $stats = financeLegacyDirectPurchaseReceiptStats($conn, $supplierId);
            return round((float)($stats['per_invoice_allocated'][$targetId] ?? 0), 2);
        } elseif ($allocationType === 'payroll') {
            $sql = "
                SELECT IFNULL(SUM(r.amount), 0)
                FROM financial_receipts r
                LEFT JOIN (
                    SELECT receipt_id, COUNT(*) AS allocation_count
                    FROM financial_receipt_allocations
                    GROUP BY receipt_id
                ) a ON a.receipt_id = r.id
                WHERE r.type = 'out'
                  AND LOWER(TRIM(IFNULL(r.category, ''))) = 'salary'
                  AND r.payroll_id = ?
                  AND IFNULL(a.allocation_count, 0) = 0
            ";
        } else {
            return 0.0;
        }

        $stmt = $conn->prepare($sql);
        $stmt->bind_param('i', $targetId);
        $stmt->execute();
        $sum = (float)($stmt->get_result()->fetch_row()[0] ?? 0);
        $stmt->close();
        return round($sum, 2);
    }
}

if (!function_exists('financeMigrateLegacyDirectReceiptBindings')) {
    function financeMigrateLegacyDirectReceiptBindings(mysqli $conn, int $limit = 0): array
    {
        financeEnsureAllocationSchema($conn);
        $limitSql = $limit > 0 ? (' LIMIT ' . max(1, (int)$limit)) : '';
        $rows = [];
        $sql = "
            SELECT
                r.id,
                r.type,
                r.category,
                r.amount,
                r.invoice_id,
                r.payroll_id
            FROM financial_receipts r
            LEFT JOIN (
                SELECT receipt_id, COUNT(*) AS allocation_count
                FROM financial_receipt_allocations
                GROUP BY receipt_id
            ) a ON a.receipt_id = r.id
            WHERE (
                    (r.type = 'in' AND IFNULL(r.invoice_id, 0) > 0)
                 OR (r.type = 'out' AND IFNULL(r.invoice_id, 0) > 0)
                 OR (r.type = 'out' AND LOWER(TRIM(IFNULL(r.category, ''))) = 'salary' AND IFNULL(r.payroll_id, 0) > 0)
              )
            ORDER BY r.id ASC
            {$limitSql}
        ";
        $res = $conn->query($sql);
        while ($res && ($row = $res->fetch_assoc())) {
            $rows[] = $row;
        }

        $created = 0;
        $targets = [
            'sales_invoice' => [],
            'purchase_invoice' => [],
            'payroll' => [],
        ];

        foreach ($rows as $row) {
            $receiptId = (int)($row['id'] ?? 0);
            $amount = round((float)($row['amount'] ?? 0), 2);
            $type = strtolower(trim((string)($row['type'] ?? '')));
            $category = strtolower(trim((string)($row['category'] ?? '')));
            $invoiceId = (int)($row['invoice_id'] ?? 0);
            $payrollId = (int)($row['payroll_id'] ?? 0);

            if ($receiptId <= 0 || $amount <= 0.00001) {
                continue;
            }

            $existingSummary = financeReceiptAllocationSummary($conn, [
                'id' => $receiptId,
                'amount' => $amount,
                'invoice_id' => $invoiceId,
                'payroll_id' => $payrollId,
                'type' => $type,
                'category' => $category,
            ]);
            $remainder = max(0.0, round($amount - (float)($existingSummary['allocated_amount'] ?? 0), 2));
            if ($remainder <= 0.00001) {
                continue;
            }

            if ($type === 'in' && $invoiceId > 0) {
                financeInsertReceiptAllocation($conn, $receiptId, 'sales_invoice', $invoiceId, $remainder, 'Legacy direct invoice binding remainder');
                $targets['sales_invoice'][$invoiceId] = $invoiceId;
                $created++;
                continue;
            }

            if ($type === 'out' && $invoiceId > 0) {
                financeInsertReceiptAllocation($conn, $receiptId, 'purchase_invoice', $invoiceId, $remainder, 'Legacy direct purchase binding remainder');
                $targets['purchase_invoice'][$invoiceId] = $invoiceId;
                $created++;
                continue;
            }

            if ($type === 'out' && $category === 'salary' && $payrollId > 0) {
                financeInsertReceiptAllocation($conn, $receiptId, 'payroll', $payrollId, $remainder, 'Legacy direct payroll binding remainder');
                $targets['payroll'][$payrollId] = $payrollId;
                $created++;
            }
        }

        foreach (array_values($targets['sales_invoice']) as $invoiceId) {
            recalculateSalesInvoice($conn, $invoiceId);
        }
        foreach (array_values($targets['purchase_invoice']) as $invoiceId) {
            recalculatePurchaseInvoice($conn, $invoiceId);
        }
        foreach (array_values($targets['payroll']) as $payrollId) {
            recalculatePayroll($conn, $payrollId);
        }

        return [
            'ok' => true,
            'processed' => count($rows),
            'created' => $created,
            'sales_invoice_count' => count($targets['sales_invoice']),
            'purchase_invoice_count' => count($targets['purchase_invoice']),
            'payroll_count' => count($targets['payroll']),
        ];
    }
}

if (!function_exists('financeRepairDirectBindingPrecedence')) {
    function financeRepairDirectBindingPrecedence(mysqli $conn, string $scope = 'sales', int $limit = 0): array
    {
        financeEnsureAllocationSchema($conn);
        $scope = strtolower(trim($scope));
        if (!in_array($scope, ['sales', 'purchases'], true)) {
            return ['ok' => false, 'message' => 'invalid_scope'];
        }

        $type = $scope === 'sales' ? 'in' : 'out';
        $invoiceTable = $scope === 'sales' ? 'invoices' : 'purchase_invoices';
        $entityColumn = $scope === 'sales' ? 'client_id' : 'supplier_id';
        $allocationType = $scope === 'sales' ? 'sales_invoice' : 'purchase_invoice';
        $recalcFn = $scope === 'sales' ? 'recalculateSalesInvoice' : 'recalculatePurchaseInvoice';
        $limitSql = $limit > 0 ? (' LIMIT ' . max(1, $limit)) : '';

        $invoiceIds = [];
        $sql = "
            SELECT DISTINCT r.invoice_id
            FROM financial_receipts r
            LEFT JOIN (
                SELECT receipt_id, COUNT(*) AS allocation_count
                FROM financial_receipt_allocations
                GROUP BY receipt_id
            ) ac ON ac.receipt_id = r.id
            WHERE r.type = '{$type}'
              AND IFNULL(r.invoice_id, 0) > 0
              AND IFNULL(ac.allocation_count, 0) = 0
            ORDER BY r.invoice_id ASC
            {$limitSql}
        ";
        $res = $conn->query($sql);
        while ($res && ($row = $res->fetch_assoc())) {
            $invoiceIds[] = (int)($row['invoice_id'] ?? 0);
        }

        $processed = 0;
        $updatedAllocations = 0;
        $createdAllocations = 0;

        foreach ($invoiceIds as $invoiceId) {
            if ($invoiceId <= 0) {
                continue;
            }

            $stmtInv = $conn->prepare("SELECT id, total_amount FROM {$invoiceTable} WHERE id = ? LIMIT 1");
            $stmtInv->bind_param('i', $invoiceId);
            $stmtInv->execute();
            $invoice = $stmtInv->get_result()->fetch_assoc();
            $stmtInv->close();
            if (!$invoice) {
                continue;
            }

            $invoiceTotal = round((float)($invoice['total_amount'] ?? 0), 2);

            $stmtDirect = $conn->prepare("
                SELECT r.id, r.amount, r.trans_date
                FROM financial_receipts r
                LEFT JOIN (
                    SELECT receipt_id, COUNT(*) AS allocation_count
                    FROM financial_receipt_allocations
                    GROUP BY receipt_id
                ) ac ON ac.receipt_id = r.id
                WHERE r.type = ?
                  AND r.invoice_id = ?
                  AND IFNULL(ac.allocation_count, 0) = 0
                ORDER BY r.trans_date ASC, r.id ASC
            ");
            $stmtDirect->bind_param('si', $type, $invoiceId);
            $stmtDirect->execute();
            $directRows = [];
            $directRes = $stmtDirect->get_result();
            while ($row = $directRes->fetch_assoc()) {
                $directRows[] = $row;
            }
            $stmtDirect->close();
            if (empty($directRows)) {
                continue;
            }

            $stmtExplicit = $conn->prepare("
                SELECT a.id, a.receipt_id, a.amount, a.notes, IFNULL(r.invoice_id, 0) AS receipt_invoice_id
                FROM financial_receipt_allocations a
                INNER JOIN financial_receipts r ON r.id = a.receipt_id
                WHERE a.allocation_type = ?
                  AND a.target_id = ?
                ORDER BY a.id ASC
            ");
            $stmtExplicit->bind_param('si', $allocationType, $invoiceId);
            $stmtExplicit->execute();
            $explicitRows = [];
            $explicitRes = $stmtExplicit->get_result();
            while ($row = $explicitRes->fetch_assoc()) {
                $explicitRows[] = $row;
            }
            $stmtExplicit->close();

            $lockedAmount = 0.0;
            $reclaimableRows = [];
            foreach ($explicitRows as $row) {
                $notes = strtolower(trim((string)($row['notes'] ?? '')));
                $receiptInvoiceId = (int)($row['receipt_invoice_id'] ?? 0);
                $isReclaimable = (
                    $receiptInvoiceId <= 0
                    || strpos($notes, 'fifo') !== false
                    || strpos($notes, 'normalization') !== false
                );
                if ($isReclaimable) {
                    $reclaimableRows[] = $row;
                } else {
                    $lockedAmount += round((float)($row['amount'] ?? 0), 2);
                }
            }

            $remaining = max(0.0, round($invoiceTotal - $lockedAmount, 2));
            $directPlan = [];
            foreach ($directRows as $row) {
                $receiptId = (int)($row['id'] ?? 0);
                $amount = round((float)($row['amount'] ?? 0), 2);
                $pay = min($amount, $remaining);
                $directPlan[$receiptId] = round($pay, 2);
                $remaining = max(0.0, round($remaining - $pay, 2));
            }

            foreach ($reclaimableRows as $row) {
                $allocationId = (int)($row['id'] ?? 0);
                $currentAmount = round((float)($row['amount'] ?? 0), 2);
                $keep = min($currentAmount, $remaining);
                $remaining = max(0.0, round($remaining - $keep, 2));

                if ($keep <= 0.00001) {
                    $conn->query("DELETE FROM financial_receipt_allocations WHERE id = {$allocationId}");
                    $updatedAllocations++;
                } elseif (abs($keep - $currentAmount) > 0.00001) {
                    $stmtUpd = $conn->prepare("UPDATE financial_receipt_allocations SET amount = ? WHERE id = ?");
                    $stmtUpd->bind_param('di', $keep, $allocationId);
                    $stmtUpd->execute();
                    $stmtUpd->close();
                    $updatedAllocations++;
                }
            }

            foreach ($directPlan as $receiptId => $pay) {
                if ($pay <= 0.00001) {
                    continue;
                }
                $stmtExisting = $conn->prepare("
                    SELECT id, amount
                    FROM financial_receipt_allocations
                    WHERE receipt_id = ? AND allocation_type = ? AND target_id = ?
                    LIMIT 1
                ");
                $stmtExisting->bind_param('isi', $receiptId, $allocationType, $invoiceId);
                $stmtExisting->execute();
                $existing = $stmtExisting->get_result()->fetch_assoc();
                $stmtExisting->close();

                if ($existing) {
                    $allocationId = (int)($existing['id'] ?? 0);
                    $currentAmount = round((float)($existing['amount'] ?? 0), 2);
                    if (abs($currentAmount - $pay) > 0.00001) {
                        $stmtUpd = $conn->prepare("UPDATE financial_receipt_allocations SET amount = ?, notes = ? WHERE id = ?");
                        $note = 'Direct binding precedence repair';
                        $stmtUpd->bind_param('dsi', $pay, $note, $allocationId);
                        $stmtUpd->execute();
                        $stmtUpd->close();
                        $updatedAllocations++;
                    }
                } else {
                    financeInsertReceiptAllocation($conn, $receiptId, $allocationType, $invoiceId, $pay, 'Direct binding precedence repair');
                    $createdAllocations++;
                }
            }

            if (function_exists($recalcFn)) {
                $recalcFn($conn, $invoiceId);
            }
            $processed++;
        }

        return [
            'ok' => true,
            'processed' => $processed,
            'updated_allocations' => $updatedAllocations,
            'created_allocations' => $createdAllocations,
        ];
    }
}

if (!function_exists('financeMigrateLegacyOpeningBalanceReceipts')) {
    function financeMigrateLegacyOpeningBalanceReceipts(mysqli $conn, int $limit = 0): array
    {
        financeEnsureAllocationSchema($conn);
        $limitSql = $limit > 0 ? (' LIMIT ' . max(1, (int)$limit)) : '';
        $rows = [];
        $sql = "
            SELECT
                r.id,
                r.type,
                r.amount,
                r.client_id,
                r.supplier_id,
                r.invoice_id,
                r.description
            FROM financial_receipts r
            LEFT JOIN (
                SELECT receipt_id, COUNT(*) AS allocation_count
                FROM financial_receipt_allocations
                GROUP BY receipt_id
            ) a ON a.receipt_id = r.id
            WHERE IFNULL(a.allocation_count, 0) = 0
              AND (
                    (r.type = 'in' AND IFNULL(r.client_id, 0) > 0 AND IFNULL(r.invoice_id, 0) > 0 AND TRIM(IFNULL(r.description, '')) LIKE 'تسوية رصيد أول المدة%')
                 OR (r.type = 'out' AND IFNULL(r.supplier_id, 0) > 0 AND IFNULL(r.invoice_id, 0) > 0 AND TRIM(IFNULL(r.description, '')) LIKE 'تسوية رصيد أول المدة%')
              )
            ORDER BY r.id ASC
            {$limitSql}
        ";
        $res = $conn->query($sql);
        while ($res && ($row = $res->fetch_assoc())) {
            $rows[] = $row;
        }

        $created = 0;
        $salesInvoices = [];
        $purchaseInvoices = [];

        foreach ($rows as $row) {
            $receiptId = (int)($row['id'] ?? 0);
            $amount = round((float)($row['amount'] ?? 0), 2);
            $type = strtolower(trim((string)($row['type'] ?? '')));
            $invoiceId = (int)($row['invoice_id'] ?? 0);
            $clientId = (int)($row['client_id'] ?? 0);
            $supplierId = (int)($row['supplier_id'] ?? 0);

            if ($receiptId <= 0 || $invoiceId <= 0 || $amount <= 0.00001) {
                continue;
            }

            if ($type === 'in' && $clientId > 0) {
                financeInsertReceiptAllocation($conn, $receiptId, 'client_opening', $clientId, $amount, 'Migrated legacy opening settlement');
                financeInsertReceiptAllocation($conn, $receiptId, 'sales_invoice', $invoiceId, $amount, 'Migrated legacy opening settlement');
                $salesInvoices[$invoiceId] = $invoiceId;
                $created += 2;
                continue;
            }

            if ($type === 'out' && $supplierId > 0) {
                financeInsertReceiptAllocation($conn, $receiptId, 'supplier_opening', $supplierId, $amount, 'Migrated legacy opening settlement');
                financeInsertReceiptAllocation($conn, $receiptId, 'purchase_invoice', $invoiceId, $amount, 'Migrated legacy opening settlement');
                $purchaseInvoices[$invoiceId] = $invoiceId;
                $created += 2;
            }
        }

        foreach (array_values($salesInvoices) as $invoiceId) {
            recalculateSalesInvoice($conn, $invoiceId);
        }
        foreach (array_values($purchaseInvoices) as $invoiceId) {
            recalculatePurchaseInvoice($conn, $invoiceId);
        }

        return [
            'ok' => true,
            'processed' => count($rows),
            'created' => $created,
            'sales_invoice_count' => count($salesInvoices),
            'purchase_invoice_count' => count($purchaseInvoices),
        ];
    }
}

if (!function_exists('financeReceiptAllocationSummary')) {
    function financeReceiptAllocationSummary(mysqli $conn, array $receiptRow): array
    {
        financeEnsureAllocationSchema($conn);
        $receiptId = (int)($receiptRow['id'] ?? 0);
        $receiptAmount = (float)($receiptRow['amount'] ?? 0);
        $summary = [
            'count' => 0,
            'allocated_amount' => 0.0,
            'unallocated_amount' => 0.0,
            'lines' => [],
        ];
        if ($receiptId <= 0) {
            return $summary;
        }

        $stmt = $conn->prepare("SELECT allocation_type, target_id, amount FROM financial_receipt_allocations WHERE receipt_id = ? ORDER BY id ASC");
        $stmt->bind_param('i', $receiptId);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $allocationType = strtolower(trim((string)($row['allocation_type'] ?? '')));
            $targetId = (int)($row['target_id'] ?? 0);
            $amount = (float)($row['amount'] ?? 0);
            if ($amount <= 0.00001) {
                continue;
            }
            $label = '#' . $targetId;
            if ($allocationType === 'sales_invoice') {
                $label = app_tr('فاتورة مبيعات', 'Sales invoice') . ' #' . $targetId;
            } elseif ($allocationType === 'purchase_invoice') {
                $label = app_tr('فاتورة مشتريات', 'Purchase invoice') . ' #' . $targetId;
            } elseif ($allocationType === 'payroll') {
                $label = app_tr('مسير راتب', 'Payroll sheet') . ' #' . $targetId;
            } elseif ($allocationType === 'client_opening') {
                $label = app_tr('رصيد افتتاحي عميل', 'Client opening balance');
            } elseif ($allocationType === 'loan_advance') {
                $label = strtolower(trim((string)($receiptRow['category'] ?? ''))) === 'salary'
                    ? app_tr('دفعة راتب مقدمة', 'Salary prepayment')
                    : app_tr('سلفة موظف', 'Employee advance');
            }
            $summary['lines'][] = [
                'label' => $label,
                'amount' => $amount,
                'allocation_type' => $allocationType,
                'target_id' => $targetId,
            ];
            $summary['allocated_amount'] += $amount;
        }
        $stmt->close();

        $legacyInvoiceId = (int)($receiptRow['invoice_id'] ?? 0);
        $legacyPayrollId = (int)($receiptRow['payroll_id'] ?? 0);
        $type = strtolower(trim((string)($receiptRow['type'] ?? '')));
        $category = strtolower(trim((string)($receiptRow['category'] ?? '')));
        $legacyRemainder = max(0.0, round($receiptAmount - $summary['allocated_amount'], 2));

        if ($legacyRemainder > 0.00001 && $legacyInvoiceId > 0) {
            $allocationType = $type === 'in' ? 'sales_invoice' : 'purchase_invoice';
            $legacyStats = $type === 'in'
                ? financeLegacyDirectSalesReceiptStats($conn, (int)($receiptRow['client_id'] ?? 0))
                : financeLegacyDirectPurchaseReceiptStats($conn, (int)($receiptRow['supplier_id'] ?? 0));
            $receiptLegacy = (array)($legacyStats['per_receipt'][$receiptId] ?? []);
            $legacyAllocated = round((float)($receiptLegacy['allocated_amount'] ?? 0), 2);
            $legacyCredit = round((float)($receiptLegacy['credit_amount'] ?? 0), 2);
            $label = $allocationType === 'sales_invoice'
                ? app_tr('فاتورة مبيعات', 'Sales invoice') . ' #' . $legacyInvoiceId
                : app_tr('فاتورة مشتريات', 'Purchase invoice') . ' #' . $legacyInvoiceId;
            if ($legacyAllocated > 0.00001) {
                $summary['lines'][] = [
                    'label' => $label,
                    'amount' => $legacyAllocated,
                    'allocation_type' => $allocationType,
                    'target_id' => $legacyInvoiceId,
                ];
                $summary['allocated_amount'] += $legacyAllocated;
            }
            if ($legacyCredit > 0.00001) {
                $summary['lines'][] = [
                    'label' => app_tr('رصيد غير مخصص لهذا السند', 'Unallocated credit on this receipt'),
                    'amount' => $legacyCredit,
                    'allocation_type' => 'legacy_receipt_credit',
                    'target_id' => $receiptId,
                ];
            }
        } elseif ($legacyRemainder > 0.00001 && $legacyPayrollId > 0 && $type === 'out' && $category === 'salary') {
            $summary['lines'][] = [
                'label' => app_tr('مسير راتب', 'Payroll sheet') . ' #' . $legacyPayrollId,
                'amount' => $legacyRemainder,
                'allocation_type' => 'payroll',
                'target_id' => $legacyPayrollId,
            ];
            $summary['allocated_amount'] += $legacyRemainder;
        }

        $summary['count'] = count($summary['lines']);
        $summary['allocated_amount'] = round($summary['allocated_amount'], 2);
        $summary['unallocated_amount'] = max(0.0, round($receiptAmount - $summary['allocated_amount'], 2));
        return $summary;
    }
}

if (!function_exists('financeCounterpartyBalanceSummary')) {
    function financeCounterpartyBalanceSummary(mysqli $conn, array $receiptRow): array
    {
        financeEnsureAllocationSchema($conn);
        $type = strtolower(trim((string)($receiptRow['type'] ?? '')));
        $category = strtolower(trim((string)($receiptRow['category'] ?? '')));
        $clientId = (int)($receiptRow['client_id'] ?? 0);
        $supplierId = (int)($receiptRow['supplier_id'] ?? 0);
        $employeeId = (int)($receiptRow['employee_id'] ?? 0);

        $summary = [
            'label' => app_tr('متوازن', 'Balanced'),
            'amount' => 0.0,
            'kind' => 'balanced',
        ];

        if ($type === 'in' && $clientId > 0) {
            $snapshot = financeClientBalanceSnapshot($conn, $clientId);
            $balance = round((float)($snapshot['net_balance'] ?? 0), 2);
            if ($balance > 0.00001) {
                return ['label' => app_tr('رصيد مستحق على العميل', 'Customer due balance'), 'amount' => $balance, 'kind' => 'due'];
            }
            if ($balance < -0.00001) {
                return ['label' => app_tr('رصيد دائن للعميل', 'Customer credit balance'), 'amount' => abs($balance), 'kind' => 'credit'];
            }
            return $summary;
        }

        if ($type === 'out' && $supplierId > 0) {
            $snapshot = financeSupplierBalanceSnapshot($conn, $supplierId);
            $balance = round((float)($snapshot['net_balance'] ?? 0), 2);

            if ($balance > 0.00001) {
                return ['label' => app_tr('مستحق للمورد', 'Supplier due balance'), 'amount' => $balance, 'kind' => 'due'];
            }
            if ($balance < -0.00001) {
                return ['label' => app_tr('دفعة مقدمة / رصيد متبق', 'Advance / remaining credit'), 'amount' => abs($balance), 'kind' => 'credit'];
            }
        }

        if ($employeeId > 0) {
            $payrollDue = 0.0;
            $outstandingLoan = 0.0;

            $payrollStmt = $conn->prepare("
                SELECT IFNULL(SUM(CASE WHEN remaining_amount > 0 THEN remaining_amount ELSE 0 END), 0)
                FROM payroll_sheets
                WHERE employee_id = ? AND COALESCE(NULLIF(status,''), 'pending') != 'paid'
            ");
            $payrollStmt->bind_param('i', $employeeId);
            $payrollStmt->execute();
            $payrollDue = (float)($payrollStmt->get_result()->fetch_row()[0] ?? 0);
            $payrollStmt->close();

            if (function_exists('app_payroll_employee_outstanding_loan')) {
                $outstandingLoan = (float)app_payroll_employee_outstanding_loan($conn, $employeeId);
            } else {
                $issuedStmt = $conn->prepare("SELECT IFNULL(SUM(amount),0) FROM financial_receipts WHERE employee_id = ? AND type = 'out' AND category = 'loan'");
                $issuedStmt->bind_param('i', $employeeId);
                $issuedStmt->execute();
                $loansIssued = (float)($issuedStmt->get_result()->fetch_row()[0] ?? 0);
                $issuedStmt->close();

                $repaidCashStmt = $conn->prepare("SELECT IFNULL(SUM(amount),0) FROM financial_receipts WHERE employee_id = ? AND type = 'in' AND category IN ('loan','loan_repayment')");
                $repaidCashStmt->bind_param('i', $employeeId);
                $repaidCashStmt->execute();
                $repaidCash = (float)($repaidCashStmt->get_result()->fetch_row()[0] ?? 0);
                $repaidCashStmt->close();

                $repaidPayrollStmt = $conn->prepare("SELECT IFNULL(SUM(loan_deduction),0) FROM payroll_sheets WHERE employee_id = ?");
                $repaidPayrollStmt->bind_param('i', $employeeId);
                $repaidPayrollStmt->execute();
                $repaidPayroll = (float)($repaidPayrollStmt->get_result()->fetch_row()[0] ?? 0);
                $repaidPayrollStmt->close();

                $outstandingLoan = max(0.0, round($loansIssued - $repaidCash - $repaidPayroll, 2));
            }

            if ($category === 'salary' && $payrollDue > 0.00001) {
                return ['label' => app_tr('رواتب مستحقة للموظف', 'Due payroll balance'), 'amount' => $payrollDue, 'kind' => 'due'];
            }
            if ($outstandingLoan > 0.00001) {
                return ['label' => app_tr('رصيد سلف قائم على الموظف', 'Outstanding employee advance'), 'amount' => $outstandingLoan, 'kind' => 'due'];
            }
            if ($payrollDue > 0.00001) {
                return ['label' => app_tr('رواتب مستحقة للموظف', 'Due payroll balance'), 'amount' => $payrollDue, 'kind' => 'due'];
            }
        }

        return $summary;
    }
}

if (!function_exists('financeClientOpeningBreakdown')) {
    function financeClientOpeningBreakdown(mysqli $conn, int $clientId, int $excludeReceiptId = 0): array
    {
        financeEnsureAllocationSchema($conn);
        $clientId = (int)$clientId;
        $excludeReceiptId = max(0, (int)$excludeReceiptId);
        if ($clientId <= 0) {
            return [
                'opening_balance' => 0.0,
                'opening_outstanding' => 0.0,
                'opening_credit' => 0.0,
                'legacy_general_paid' => 0.0,
                'opening_applied' => 0.0,
            ];
        }

        $clientStmt = $conn->prepare("SELECT opening_balance FROM clients WHERE id = ? LIMIT 1");
        $clientStmt->bind_param('i', $clientId);
        $clientStmt->execute();
        $client = $clientStmt->get_result()->fetch_assoc();
        $clientStmt->close();

        $openingBalance = round((float)($client['opening_balance'] ?? 0), 2);
        $openingOutstanding = 0.0;
        $openingCredit = 0.0;
        $legacyOpeningPaid = 0.0;
        $openingApplied = 0.0;

        if ($openingBalance > 0.00001) {
            if ($excludeReceiptId > 0) {
                $legacyOpeningStmt = $conn->prepare("
                    SELECT IFNULL(SUM(r.amount), 0)
                    FROM financial_receipts r
                    LEFT JOIN (
                        SELECT receipt_id, COUNT(*) AS allocation_count
                        FROM financial_receipt_allocations
                        GROUP BY receipt_id
                    ) a ON a.receipt_id = r.id
                    WHERE r.client_id = ?
                      AND r.type = 'in'
                      AND LOWER(TRIM(IFNULL(r.category, ''))) IN ('opening_balance', 'client_opening')
                      AND (
                            TRIM(IFNULL(r.description, '')) LIKE 'تسوية رصيد أول المدة%'
                            OR LOWER(TRIM(IFNULL(r.description, ''))) LIKE '%opening balance%'
                          )
                      AND IFNULL(a.allocation_count, 0) = 0
                      AND r.id != ?
                ");
                $legacyOpeningStmt->bind_param('ii', $clientId, $excludeReceiptId);
            } else {
                $legacyOpeningStmt = $conn->prepare("
                    SELECT IFNULL(SUM(r.amount), 0)
                    FROM financial_receipts r
                    LEFT JOIN (
                        SELECT receipt_id, COUNT(*) AS allocation_count
                        FROM financial_receipt_allocations
                        GROUP BY receipt_id
                    ) a ON a.receipt_id = r.id
                    WHERE r.client_id = ?
                      AND r.type = 'in'
                      AND LOWER(TRIM(IFNULL(r.category, ''))) IN ('opening_balance', 'client_opening')
                      AND (
                            TRIM(IFNULL(r.description, '')) LIKE 'تسوية رصيد أول المدة%'
                            OR LOWER(TRIM(IFNULL(r.description, ''))) LIKE '%opening balance%'
                          )
                      AND IFNULL(a.allocation_count, 0) = 0
                ");
                $legacyOpeningStmt->bind_param('i', $clientId);
            }
            $legacyOpeningStmt->execute();
            $legacyOpeningPaid = (float)($legacyOpeningStmt->get_result()->fetch_row()[0] ?? 0);
            $legacyOpeningStmt->close();

            if ($excludeReceiptId > 0) {
                $openingAppliedStmt = $conn->prepare("
                    SELECT IFNULL(SUM(a.amount),0)
                    FROM financial_receipt_allocations a
                    INNER JOIN financial_receipts r ON r.id = a.receipt_id
                    WHERE r.client_id = ?
                      AND r.type = 'in'
                      AND a.allocation_type = 'client_opening'
                      AND r.id != ?
                ");
                $openingAppliedStmt->bind_param('ii', $clientId, $excludeReceiptId);
            } else {
                $openingAppliedStmt = $conn->prepare("
                    SELECT IFNULL(SUM(a.amount),0)
                    FROM financial_receipt_allocations a
                    INNER JOIN financial_receipts r ON r.id = a.receipt_id
                    WHERE r.client_id = ?
                      AND r.type = 'in'
                      AND a.allocation_type = 'client_opening'
                ");
                $openingAppliedStmt->bind_param('i', $clientId);
            }
            $openingAppliedStmt->execute();
            $openingApplied = (float)($openingAppliedStmt->get_result()->fetch_row()[0] ?? 0);
            $openingAppliedStmt->close();

            $openingOutstanding = max(0.0, round($openingBalance - $legacyOpeningPaid - $openingApplied, 2));
        } elseif ($openingBalance < -0.00001) {
            $openingCredit = abs($openingBalance);
        }

        return [
            'opening_balance' => $openingBalance,
            'opening_outstanding' => round($openingOutstanding, 2),
            'opening_credit' => round($openingCredit, 2),
            'legacy_general_paid' => round($legacyOpeningPaid, 2),
            'opening_applied' => round($openingApplied, 2),
        ];
    }
}

if (!function_exists('financeClientBalanceSnapshot')) {
    function financeClientSettlementSummary(mysqli $conn, int $clientId): array
    {
        financeEnsureAllocationSchema($conn);
        $clientId = (int)$clientId;
        if ($clientId <= 0) {
            return [
                'raw_receipts' => 0.0,
                'opening_settled' => 0.0,
                'invoice_settled' => 0.0,
                'settled_total' => 0.0,
                'unallocated_credit' => 0.0,
            ];
        }

        $rawStmt = $conn->prepare("
            SELECT IFNULL(SUM(amount), 0)
            FROM financial_receipts
            WHERE client_id = ? AND type = 'in'
        ");
        $rawStmt->bind_param('i', $clientId);
        $rawStmt->execute();
        $rawReceipts = (float)($rawStmt->get_result()->fetch_row()[0] ?? 0);
        $rawStmt->close();

        $openingBreakdown = financeClientOpeningBreakdown($conn, $clientId);
        $openingSettled = round(
            (float)($openingBreakdown['legacy_general_paid'] ?? 0)
            + (float)($openingBreakdown['opening_applied'] ?? 0),
            2
        );

        $invoiceSettledStmt = $conn->prepare("
            SELECT IFNULL(SUM(paid_amount), 0)
            FROM invoices
            WHERE client_id = ?
              AND COALESCE(status, '') <> 'cancelled'
        ");
        $invoiceSettledStmt->bind_param('i', $clientId);
        $invoiceSettledStmt->execute();
        $invoiceSettled = round((float)($invoiceSettledStmt->get_result()->fetch_row()[0] ?? 0), 2);
        $invoiceSettledStmt->close();
        $snapshot = financeClientBalanceSnapshot($conn, $clientId);

        return [
            'raw_receipts' => round($rawReceipts, 2),
            'opening_settled' => $openingSettled,
            'invoice_settled' => $invoiceSettled,
            'settled_total' => round($openingSettled + $invoiceSettled, 2),
            'unallocated_credit' => round((float)($snapshot['receipt_credit'] ?? 0), 2),
        ];
    }
}

if (!function_exists('financeClientBalanceSnapshot')) {
    function financeClientBalanceSnapshotAsOf(mysqli $conn, int $clientId, string $asOfDate): array
    {
        financeEnsureAllocationSchema($conn);
        $clientId = (int)$clientId;
        $asOfDate = trim($asOfDate);
        if ($clientId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $asOfDate)) {
            return [
                'opening_outstanding' => 0.0,
                'opening_credit' => 0.0,
                'invoice_due' => 0.0,
                'receipt_credit' => 0.0,
                'refund_total' => 0.0,
                'net_balance' => 0.0,
                'kind' => 'balanced',
            ];
        }

        $stmtClient = $conn->prepare("SELECT opening_balance FROM clients WHERE id = ? LIMIT 1");
        $stmtClient->bind_param('i', $clientId);
        $stmtClient->execute();
        $client = $stmtClient->get_result()->fetch_assoc() ?: [];
        $stmtClient->close();
        $openingBalance = round((float)($client['opening_balance'] ?? 0), 2);

        $openingCredit = $openingBalance < -0.00001 ? abs($openingBalance) : 0.0;
        $openingOutstanding = max(0.0, $openingBalance);

        if ($openingOutstanding > 0.00001) {
            $stmtLegacyOpening = $conn->prepare("
                SELECT IFNULL(SUM(r.amount), 0)
                FROM financial_receipts r
                LEFT JOIN (
                    SELECT receipt_id, COUNT(*) AS allocation_count
                    FROM financial_receipt_allocations
                    GROUP BY receipt_id
                ) ac ON ac.receipt_id = r.id
                WHERE r.client_id = ?
                  AND r.type = 'in'
                  AND r.trans_date < ?
                  AND LOWER(TRIM(IFNULL(r.category, ''))) IN ('opening_balance', 'client_opening')
                  AND (
                        TRIM(IFNULL(r.description, '')) LIKE 'تسوية رصيد أول المدة%'
                        OR LOWER(TRIM(IFNULL(r.description, ''))) LIKE '%opening balance%'
                      )
                  AND IFNULL(ac.allocation_count, 0) = 0
            ");
            $stmtLegacyOpening->bind_param('is', $clientId, $asOfDate);
            $stmtLegacyOpening->execute();
            $legacyOpeningPaid = (float)($stmtLegacyOpening->get_result()->fetch_row()[0] ?? 0);
            $stmtLegacyOpening->close();

            $stmtOpeningAlloc = $conn->prepare("
                SELECT IFNULL(SUM(a.amount),0)
                FROM financial_receipt_allocations a
                INNER JOIN financial_receipts r ON r.id = a.receipt_id
                WHERE r.client_id = ?
                  AND r.type = 'in'
                  AND r.trans_date < ?
                  AND a.allocation_type = 'client_opening'
            ");
            $stmtOpeningAlloc->bind_param('is', $clientId, $asOfDate);
            $stmtOpeningAlloc->execute();
            $openingApplied = (float)($stmtOpeningAlloc->get_result()->fetch_row()[0] ?? 0);
            $stmtOpeningAlloc->close();

            $openingOutstanding = max(0.0, round($openingOutstanding - $legacyOpeningPaid - $openingApplied, 2));
        }

        $legacyDirectStats = financeLegacyDirectSalesReceiptStats($conn, $clientId, $asOfDate);
        $legacyInvoiceAllocated = (array)($legacyDirectStats['per_invoice_allocated'] ?? []);
        $stmtInvoiceDue = $conn->prepare("
            SELECT i.id, i.total_amount, IFNULL(ap.alloc_total, 0) AS explicit_alloc_total
            FROM invoices i
            LEFT JOIN (
                SELECT a.target_id, IFNULL(SUM(a.amount), 0) AS alloc_total
                FROM financial_receipt_allocations a
                INNER JOIN financial_receipts r ON r.id = a.receipt_id
                WHERE a.allocation_type = 'sales_invoice'
                  AND r.type = 'in'
                  AND r.trans_date < ?
                GROUP BY a.target_id
            ) ap ON ap.target_id = i.id
            WHERE i.client_id = ?
              AND DATE(COALESCE(i.inv_date, i.created_at)) < ?
              AND COALESCE(i.status, '') <> 'cancelled'
        ");
        $stmtInvoiceDue->bind_param('sis', $asOfDate, $clientId, $asOfDate);
        $stmtInvoiceDue->execute();
        $invoiceRes = $stmtInvoiceDue->get_result();
        $invoiceDue = 0.0;
        while ($invoiceRow = $invoiceRes->fetch_assoc()) {
            $invoiceId = (int)($invoiceRow['id'] ?? 0);
            $invoiceTotal = round((float)($invoiceRow['total_amount'] ?? 0), 2);
            $explicitAllocated = round((float)($invoiceRow['explicit_alloc_total'] ?? 0), 2);
            $legacyAllocated = round((float)($legacyInvoiceAllocated[$invoiceId] ?? 0), 2);
            $invoiceDue += max(0.0, round($invoiceTotal - $explicitAllocated - $legacyAllocated, 2));
        }
        $stmtInvoiceDue->close();

        $legacyDirectStats = financeLegacyDirectSalesReceiptStats($conn, $clientId, $asOfDate);

        $stmtReceiptCredit = $conn->prepare("
            SELECT IFNULL(SUM(GREATEST(r.amount - IFNULL(ap.alloc_total, 0), 0)), 0)
            FROM financial_receipts r
            LEFT JOIN (
                SELECT receipt_id, IFNULL(SUM(amount), 0) AS alloc_total
                FROM financial_receipt_allocations
                GROUP BY receipt_id
            ) ap ON ap.receipt_id = r.id
            WHERE r.client_id = ?
              AND r.type = 'in'
              AND r.trans_date < ?
              AND LOWER(TRIM(IFNULL(r.category, ''))) NOT IN ('opening_balance', 'client_opening')
              AND NOT (
                    IFNULL(r.invoice_id, 0) > 0
                    AND IFNULL(ap.alloc_total, 0) <= 0.00001
                  )
        ");
        $stmtReceiptCredit->bind_param('is', $clientId, $asOfDate);
        $stmtReceiptCredit->execute();
        $receiptCredit = (float)($stmtReceiptCredit->get_result()->fetch_row()[0] ?? 0);
        $stmtReceiptCredit->close();
        $receiptCredit += (float)($legacyDirectStats['credit_total'] ?? 0);

        $stmtRefunds = $conn->prepare("
            SELECT IFNULL(SUM(amount), 0)
            FROM financial_receipts
            WHERE client_id = ?
              AND type = 'out'
              AND trans_date < ?
              AND LOWER(TRIM(IFNULL(category, ''))) = 'refund'
        ");
        $stmtRefunds->bind_param('is', $clientId, $asOfDate);
        $stmtRefunds->execute();
        $refundTotal = (float)($stmtRefunds->get_result()->fetch_row()[0] ?? 0);
        $stmtRefunds->close();

        $netBalance = round($openingOutstanding + $invoiceDue + $refundTotal - ($receiptCredit + $openingCredit), 2);
        $kind = 'balanced';
        if ($netBalance > 0.00001) {
            $kind = 'due';
        } elseif ($netBalance < -0.00001) {
            $kind = 'credit';
        }

        return [
            'opening_outstanding' => round($openingOutstanding, 2),
            'opening_credit' => round($openingCredit, 2),
            'invoice_due' => round($invoiceDue, 2),
            'receipt_credit' => round($receiptCredit, 2),
            'refund_total' => round($refundTotal, 2),
            'net_balance' => $netBalance,
            'kind' => $kind,
        ];
    }
}

if (!function_exists('financeClientBalanceSnapshot')) {
    function financeClientBalanceSnapshot(mysqli $conn, int $clientId): array
    {
        financeEnsureAllocationSchema($conn);
        $clientId = (int)$clientId;
        if ($clientId <= 0) {
            return [
                'opening_outstanding' => 0.0,
                'invoice_due' => 0.0,
                'receipt_credit' => 0.0,
                'refund_total' => 0.0,
                'net_balance' => 0.0,
                'kind' => 'balanced',
            ];
        }

        $openingBreakdown = financeClientOpeningBreakdown($conn, $clientId);
        $openingOutstanding = (float)($openingBreakdown['opening_outstanding'] ?? 0);
        $openingCredit = (float)($openingBreakdown['opening_credit'] ?? 0);

        $invoiceDueStmt = $conn->prepare("
            SELECT IFNULL(SUM(remaining_amount), 0)
            FROM invoices
            WHERE client_id = ?
              AND COALESCE(status, '') <> 'cancelled'
              AND IFNULL(remaining_amount, 0) > 0.00001
        ");
        $invoiceDueStmt->bind_param('i', $clientId);
        $invoiceDueStmt->execute();
        $invoiceDue = (float)($invoiceDueStmt->get_result()->fetch_row()[0] ?? 0);
        $invoiceDueStmt->close();

        $legacyDirectStats = financeLegacyDirectSalesReceiptStats($conn, $clientId);

        $receiptCreditStmt = $conn->prepare("
            SELECT IFNULL(SUM(
                ROUND(
                    r.amount - CASE
                        WHEN IFNULL(a.allocated_amount, 0) > 0 THEN IFNULL(a.allocated_amount, 0)
                        ELSE 0
                    END,
                    2
                )
            ), 0)
            FROM financial_receipts r
            LEFT JOIN (
                SELECT receipt_id, IFNULL(SUM(amount), 0) AS allocated_amount
                FROM financial_receipt_allocations
                GROUP BY receipt_id
            ) a ON a.receipt_id = r.id
            WHERE r.client_id = ?
              AND r.type = 'in'
              AND LOWER(TRIM(IFNULL(r.category, ''))) NOT IN ('opening_balance', 'client_opening')
              AND NOT (
                    IFNULL(r.invoice_id, 0) > 0
                    AND IFNULL(a.allocated_amount, 0) <= 0.00001
                  )
              AND ROUND(
                    r.amount - CASE
                        WHEN IFNULL(a.allocated_amount, 0) > 0 THEN IFNULL(a.allocated_amount, 0)
                        ELSE 0
                    END,
                    2
                  ) > 0.00001
        ");
        $receiptCreditStmt->bind_param('i', $clientId);
        $receiptCreditStmt->execute();
        $receiptCredit = (float)($receiptCreditStmt->get_result()->fetch_row()[0] ?? 0);
        $receiptCreditStmt->close();
        $receiptCredit += (float)($legacyDirectStats['credit_total'] ?? 0);

        $refundStmt = $conn->prepare("
            SELECT IFNULL(SUM(amount), 0)
            FROM financial_receipts
            WHERE client_id = ?
              AND type = 'out'
              AND LOWER(TRIM(IFNULL(category, ''))) = 'refund'
        ");
        $refundStmt->bind_param('i', $clientId);
        $refundStmt->execute();
        $refundTotal = (float)($refundStmt->get_result()->fetch_row()[0] ?? 0);
        $refundStmt->close();

        $netBalance = round($openingOutstanding + $invoiceDue + $refundTotal - ($receiptCredit + $openingCredit), 2);
        $kind = 'balanced';
        if ($netBalance > 0.00001) {
            $kind = 'due';
        } elseif ($netBalance < -0.00001) {
            $kind = 'credit';
        }

        return [
            'opening_outstanding' => $openingOutstanding,
            'opening_credit' => round($openingCredit, 2),
            'invoice_due' => round($invoiceDue, 2),
            'receipt_credit' => round($receiptCredit, 2),
            'refund_total' => round($refundTotal, 2),
            'net_balance' => $netBalance,
            'kind' => $kind,
        ];
    }
}

if (!function_exists('financeSupplierBalanceSnapshot')) {
    function financeSupplierSettlementSummary(mysqli $conn, int $supplierId): array
    {
        financeEnsureAllocationSchema($conn);
        $supplierId = (int)$supplierId;
        if ($supplierId <= 0) {
            return [
                'raw_payments' => 0.0,
                'opening_settled' => 0.0,
                'invoice_settled' => 0.0,
                'settled_total' => 0.0,
                'advance_credit' => 0.0,
            ];
        }

        $rawStmt = $conn->prepare("
            SELECT IFNULL(SUM(amount), 0)
            FROM financial_receipts
            WHERE supplier_id = ? AND type = 'out'
        ");
        $rawStmt->bind_param('i', $supplierId);
        $rawStmt->execute();
        $rawPayments = (float)($rawStmt->get_result()->fetch_row()[0] ?? 0);
        $rawStmt->close();

        $openingLegacyStmt = $conn->prepare("
            SELECT IFNULL(SUM(r.amount), 0)
            FROM financial_receipts r
            LEFT JOIN (
                SELECT receipt_id, COUNT(*) AS allocation_count
                FROM financial_receipt_allocations
                GROUP BY receipt_id
            ) ac ON ac.receipt_id = r.id
            WHERE r.supplier_id = ?
              AND r.type = 'out'
              AND LOWER(TRIM(IFNULL(r.category, ''))) IN ('opening_balance', 'supplier_opening')
              AND (
                    TRIM(IFNULL(r.description, '')) LIKE 'تسوية رصيد أول المدة%'
                    OR LOWER(TRIM(IFNULL(r.description, ''))) LIKE '%opening balance%'
                  )
              AND IFNULL(ac.allocation_count, 0) = 0
        ");
        $openingLegacyStmt->bind_param('i', $supplierId);
        $openingLegacyStmt->execute();
        $openingLegacy = (float)($openingLegacyStmt->get_result()->fetch_row()[0] ?? 0);
        $openingLegacyStmt->close();

        $openingAllocStmt = $conn->prepare("
            SELECT IFNULL(SUM(a.amount), 0)
            FROM financial_receipt_allocations a
            INNER JOIN financial_receipts r ON r.id = a.receipt_id
            WHERE r.supplier_id = ?
              AND r.type = 'out'
              AND a.allocation_type = 'supplier_opening'
        ");
        $openingAllocStmt->bind_param('i', $supplierId);
        $openingAllocStmt->execute();
        $openingAllocated = (float)($openingAllocStmt->get_result()->fetch_row()[0] ?? 0);
        $openingAllocStmt->close();

        $snapshot = financeSupplierBalanceSnapshot($conn, $supplierId);
        $openingSettled = round($openingLegacy + $openingAllocated, 2);
        $invoiceSettledStmt = $conn->prepare("
            SELECT IFNULL(SUM(paid_amount), 0)
            FROM purchase_invoices
            WHERE supplier_id = ?
              AND COALESCE(status, '') <> 'cancelled'
        ");
        $invoiceSettledStmt->bind_param('i', $supplierId);
        $invoiceSettledStmt->execute();
        $invoiceSettled = round((float)($invoiceSettledStmt->get_result()->fetch_row()[0] ?? 0), 2);
        $invoiceSettledStmt->close();

        return [
            'raw_payments' => round($rawPayments, 2),
            'opening_settled' => $openingSettled,
            'invoice_settled' => $invoiceSettled,
            'settled_total' => round($openingSettled + $invoiceSettled, 2),
            'advance_credit' => round((float)($snapshot['payment_credit'] ?? 0), 2),
        ];
    }
}

if (!function_exists('financeSupplierBalanceSnapshot')) {
    function financeSupplierBalanceSnapshotAsOf(mysqli $conn, int $supplierId, string $asOfDate): array
    {
        financeEnsureAllocationSchema($conn);
        $supplierId = (int)$supplierId;
        $asOfDate = trim($asOfDate);
        if ($supplierId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $asOfDate)) {
            return [
                'opening_outstanding' => 0.0,
                'opening_credit' => 0.0,
                'invoice_due' => 0.0,
                'payment_credit' => 0.0,
                'net_balance' => 0.0,
                'kind' => 'balanced',
            ];
        }

        $stmtSupplier = $conn->prepare("SELECT opening_balance FROM suppliers WHERE id = ? LIMIT 1");
        $stmtSupplier->bind_param('i', $supplierId);
        $stmtSupplier->execute();
        $supplier = $stmtSupplier->get_result()->fetch_assoc() ?: [];
        $stmtSupplier->close();
        $openingBalance = round((float)($supplier['opening_balance'] ?? 0), 2);

        $openingCredit = $openingBalance < -0.00001 ? abs($openingBalance) : 0.0;
        $openingOutstanding = max(0.0, $openingBalance);

        if ($openingOutstanding > 0.00001) {
            $stmtLegacyOpening = $conn->prepare("
                SELECT IFNULL(SUM(r.amount), 0)
                FROM financial_receipts r
                LEFT JOIN (
                    SELECT receipt_id, COUNT(*) AS allocation_count
                    FROM financial_receipt_allocations
                    GROUP BY receipt_id
                ) ac ON ac.receipt_id = r.id
                WHERE r.supplier_id = ?
                  AND r.type = 'out'
                  AND r.trans_date < ?
                  AND LOWER(TRIM(IFNULL(r.category, ''))) IN ('opening_balance', 'supplier_opening')
                  AND (
                        TRIM(IFNULL(r.description, '')) LIKE 'تسوية رصيد أول المدة%'
                        OR LOWER(TRIM(IFNULL(r.description, ''))) LIKE '%opening balance%'
                      )
                  AND IFNULL(ac.allocation_count, 0) = 0
            ");
            $stmtLegacyOpening->bind_param('is', $supplierId, $asOfDate);
            $stmtLegacyOpening->execute();
            $legacyOpeningPaid = (float)($stmtLegacyOpening->get_result()->fetch_row()[0] ?? 0);
            $stmtLegacyOpening->close();

            $stmtOpeningAlloc = $conn->prepare("
                SELECT IFNULL(SUM(a.amount),0)
                FROM financial_receipt_allocations a
                INNER JOIN financial_receipts r ON r.id = a.receipt_id
                WHERE r.supplier_id = ?
                  AND r.type = 'out'
                  AND r.trans_date < ?
                  AND a.allocation_type = 'supplier_opening'
            ");
            $stmtOpeningAlloc->bind_param('is', $supplierId, $asOfDate);
            $stmtOpeningAlloc->execute();
            $openingApplied = (float)($stmtOpeningAlloc->get_result()->fetch_row()[0] ?? 0);
            $stmtOpeningAlloc->close();

            $openingOutstanding = max(0.0, round($openingOutstanding - $legacyOpeningPaid - $openingApplied, 2));
        }

        $legacyDirectStats = financeLegacyDirectPurchaseReceiptStats($conn, $supplierId, $asOfDate);
        $legacyInvoiceAllocated = (array)($legacyDirectStats['per_invoice_allocated'] ?? []);
        $stmtInvoiceDue = $conn->prepare("
            SELECT i.id, i.total_amount, IFNULL(ap.alloc_total, 0) AS explicit_alloc_total
            FROM purchase_invoices i
            LEFT JOIN (
                SELECT a.target_id, IFNULL(SUM(a.amount), 0) AS alloc_total
                FROM financial_receipt_allocations a
                INNER JOIN financial_receipts r ON r.id = a.receipt_id
                WHERE a.allocation_type = 'purchase_invoice'
                  AND r.type = 'out'
                  AND r.trans_date < ?
                GROUP BY a.target_id
            ) ap ON ap.target_id = i.id
            WHERE i.supplier_id = ?
              AND DATE(COALESCE(i.inv_date, i.created_at)) < ?
              AND COALESCE(i.status, '') <> 'cancelled'
        ");
        $stmtInvoiceDue->bind_param('sis', $asOfDate, $supplierId, $asOfDate);
        $stmtInvoiceDue->execute();
        $invoiceRes = $stmtInvoiceDue->get_result();
        $invoiceDue = 0.0;
        while ($invoiceRow = $invoiceRes->fetch_assoc()) {
            $invoiceId = (int)($invoiceRow['id'] ?? 0);
            $invoiceTotal = round((float)($invoiceRow['total_amount'] ?? 0), 2);
            $explicitAllocated = round((float)($invoiceRow['explicit_alloc_total'] ?? 0), 2);
            $legacyAllocated = round((float)($legacyInvoiceAllocated[$invoiceId] ?? 0), 2);
            $invoiceDue += max(0.0, round($invoiceTotal - $explicitAllocated - $legacyAllocated, 2));
        }
        $stmtInvoiceDue->close();

        $legacyDirectStats = financeLegacyDirectPurchaseReceiptStats($conn, $supplierId, $asOfDate);

        $stmtPaymentCredit = $conn->prepare("
            SELECT IFNULL(SUM(GREATEST(r.amount - IFNULL(ap.alloc_total, 0), 0)), 0)
            FROM financial_receipts r
            LEFT JOIN (
                SELECT receipt_id, IFNULL(SUM(amount), 0) AS alloc_total
                FROM financial_receipt_allocations
                GROUP BY receipt_id
            ) ap ON ap.receipt_id = r.id
            WHERE r.supplier_id = ?
              AND r.type = 'out'
              AND r.trans_date < ?
              AND LOWER(TRIM(IFNULL(r.category, ''))) NOT IN ('opening_balance', 'supplier_opening')
              AND IFNULL(r.invoice_id, 0) = 0
        ");
        $stmtPaymentCredit->bind_param('is', $supplierId, $asOfDate);
        $stmtPaymentCredit->execute();
        $paymentCredit = (float)($stmtPaymentCredit->get_result()->fetch_row()[0] ?? 0);
        $stmtPaymentCredit->close();
        $paymentCredit += (float)($legacyDirectStats['credit_total'] ?? 0);

        $netBalance = round($openingOutstanding + $invoiceDue - ($openingCredit + $paymentCredit), 2);
        $kind = 'balanced';
        if ($netBalance > 0.00001) {
            $kind = 'due';
        } elseif ($netBalance < -0.00001) {
            $kind = 'credit';
        }

        return [
            'opening_outstanding' => round($openingOutstanding, 2),
            'opening_credit' => round($openingCredit, 2),
            'invoice_due' => round($invoiceDue, 2),
            'payment_credit' => round($paymentCredit, 2),
            'net_balance' => $netBalance,
            'kind' => $kind,
        ];
    }
}

if (!function_exists('financeSupplierBalanceSnapshot')) {
    function financeSupplierBalanceSnapshot(mysqli $conn, int $supplierId): array
    {
        financeEnsureAllocationSchema($conn);
        $supplierId = (int)$supplierId;
        if ($supplierId <= 0) {
            return [
                'opening_outstanding' => 0.0,
                'opening_credit' => 0.0,
                'invoice_due' => 0.0,
                'payment_credit' => 0.0,
                'net_balance' => 0.0,
                'kind' => 'balanced',
            ];
        }

        $stmt = $conn->prepare("SELECT opening_balance FROM suppliers WHERE id = ? LIMIT 1");
        $stmt->bind_param('i', $supplierId);
        $stmt->execute();
        $supplier = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();

        $openingBalance = round((float)($supplier['opening_balance'] ?? 0), 2);
        $openingOutstanding = $openingBalance > 0.00001 ? $openingBalance : 0.0;
        $openingCredit = $openingBalance < -0.00001 ? abs($openingBalance) : 0.0;

        $invoiceDueStmt = $conn->prepare("
            SELECT IFNULL(SUM(remaining_amount), 0)
            FROM purchase_invoices
            WHERE supplier_id = ?
              AND COALESCE(status, '') <> 'cancelled'
              AND IFNULL(remaining_amount, 0) > 0.00001
        ");
        $invoiceDueStmt->bind_param('i', $supplierId);
        $invoiceDueStmt->execute();
        $invoiceDue = (float)($invoiceDueStmt->get_result()->fetch_row()[0] ?? 0);
        $invoiceDueStmt->close();

        $legacyDirectStats = financeLegacyDirectPurchaseReceiptStats($conn, $supplierId);

        $paymentCreditStmt = $conn->prepare("
            SELECT IFNULL(SUM(
                ROUND(
                    r.amount - CASE
                        WHEN IFNULL(a.allocated_amount, 0) > 0 THEN IFNULL(a.allocated_amount, 0)
                        ELSE 0
                    END,
                    2
                )
            ), 0)
            FROM financial_receipts r
            LEFT JOIN (
                SELECT receipt_id, IFNULL(SUM(amount), 0) AS allocated_amount
                FROM financial_receipt_allocations
                GROUP BY receipt_id
            ) a ON a.receipt_id = r.id
            WHERE r.supplier_id = ?
              AND r.type = 'out'
              AND LOWER(TRIM(IFNULL(r.category, ''))) NOT IN ('opening_balance', 'supplier_opening')
              AND IFNULL(r.invoice_id, 0) = 0
              AND ROUND(
                    r.amount - CASE
                        WHEN IFNULL(a.allocated_amount, 0) > 0 THEN IFNULL(a.allocated_amount, 0)
                        ELSE 0
                    END,
                    2
                  ) > 0.00001
        ");
        $paymentCreditStmt->bind_param('i', $supplierId);
        $paymentCreditStmt->execute();
        $paymentCredit = (float)($paymentCreditStmt->get_result()->fetch_row()[0] ?? 0);
        $paymentCreditStmt->close();
        $paymentCredit += (float)($legacyDirectStats['credit_total'] ?? 0);

        $netBalance = round($openingOutstanding + $invoiceDue - ($openingCredit + $paymentCredit), 2);
        $kind = 'balanced';
        if ($netBalance > 0.00001) {
            $kind = 'due';
        } elseif ($netBalance < -0.00001) {
            $kind = 'credit';
        }

        return [
            'opening_outstanding' => round($openingOutstanding, 2),
            'opening_credit' => round($openingCredit, 2),
            'invoice_due' => round($invoiceDue, 2),
            'payment_credit' => round($paymentCredit, 2),
            'net_balance' => $netBalance,
            'kind' => $kind,
        ];
    }
}

if (!function_exists('financeValidateBindings')) {
    function financeValidateBindings(mysqli $conn, string $type, string $cat, $cid, $iid, $sid, $eid, $pid, string $taxLawKey = ''): string
    {
        $clientId = ($cid === "NULL") ? 0 : (int)$cid;
        $invoiceId = ($iid === "NULL") ? 0 : (int)$iid;
        $supplierId = ($sid === "NULL") ? 0 : (int)$sid;
        $employeeId = ($eid === "NULL") ? 0 : (int)$eid;
        $payrollId = ($pid === "NULL") ? 0 : (int)$pid;

        if ($type === 'in' && $invoiceId > 0) {
            if ($clientId <= 0) {
                return app_tr('يجب اختيار العميل عند ربط سند قبض بفاتورة مبيعات.', 'A client is required when linking a receipt to a sales invoice.');
            }
            $stmt = $conn->prepare("SELECT client_id, status FROM invoices WHERE id = ? LIMIT 1");
            $stmt->bind_param('i', $invoiceId);
            $stmt->execute();
            $inv = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$inv) {
                return app_tr('فاتورة المبيعات المحددة غير موجودة.', 'The selected sales invoice was not found.');
            }
            if ((string)($inv['status'] ?? '') === 'cancelled') {
                return app_tr('فاتورة المبيعات ملغاة ولا يمكن السداد عليها.', 'The selected sales invoice is cancelled and cannot receive payments.');
            }
            if ((int)$inv['client_id'] !== $clientId) {
                return app_tr('فاتورة المبيعات لا تتبع العميل المحدد.', 'The selected sales invoice does not belong to the selected client.');
            }
        }

        if ($type === 'out' && $invoiceId > 0) {
            if ($supplierId <= 0) {
                return app_tr('يجب اختيار المورد عند ربط سند صرف بفاتورة مشتريات.', 'A supplier is required when linking a payment to a purchase invoice.');
            }
            $stmt = $conn->prepare("SELECT supplier_id, status FROM purchase_invoices WHERE id = ? LIMIT 1");
            $stmt->bind_param('i', $invoiceId);
            $stmt->execute();
            $inv = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$inv) {
                return app_tr('فاتورة المشتريات المحددة غير موجودة.', 'The selected purchase invoice was not found.');
            }
            if ((string)($inv['status'] ?? '') === 'cancelled') {
                return app_tr('فاتورة المشتريات ملغاة ولا يمكن السداد عليها.', 'The selected purchase invoice is cancelled and cannot receive payments.');
            }
            if ((int)$inv['supplier_id'] !== $supplierId) {
                return app_tr('فاتورة المشتريات لا تتبع المورد المحدد.', 'The selected purchase invoice does not belong to the selected supplier.');
            }
        }

        if ($payrollId > 0) {
            if ($type !== 'out') {
                return app_tr('ربط المسير متاح فقط لسندات الصرف.', 'Payroll binding is available only for outgoing transactions.');
            }
            if ($cat !== 'salary') {
                return app_tr('ربط المسير متاح فقط عند تسجيل راتب شهري.', 'Payroll binding is available only for salary transactions.');
            }
            if ($employeeId <= 0) {
                return app_tr('يجب اختيار الموظف عند ربط سند صرف بمسير راتب.', 'An employee is required when linking a payment to payroll.');
            }
            $stmt = $conn->prepare("SELECT employee_id FROM payroll_sheets WHERE id = ? LIMIT 1");
            $stmt->bind_param('i', $payrollId);
            $stmt->execute();
            $sheet = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$sheet) {
                return app_tr('مسير الرواتب المحدد غير موجود.', 'The selected payroll sheet was not found.');
            }
            if ((int)$sheet['employee_id'] !== $employeeId) {
                return app_tr('مسير الرواتب لا يتبع الموظف المحدد.', 'The selected payroll sheet does not belong to the selected employee.');
            }
        }

        if ($cat === 'supplier' && $supplierId <= 0) {
            return app_tr('فئة المورد تتطلب تحديد المورد.', 'Supplier transactions require a selected supplier.');
        }
        if (($cat === 'salary' || $cat === 'loan') && $employeeId <= 0) {
            return app_tr('فئة الرواتب/السلف تتطلب تحديد الموظف.', 'Salary/loan transactions require a selected employee.');
        }
        if ($cat === 'tax') {
            if ($type !== 'out') {
                return app_tr('سداد الضرائب يُسجل كسند صرف فقط.', 'Tax settlement can be recorded only as an outgoing transaction.');
            }
            if ($taxLawKey === '') {
                return app_tr('يجب اختيار نوع/قانون الضريبة عند تسجيل سداد ضريبي.', 'A tax law/type must be selected when recording a tax settlement.');
            }
            if (!function_exists('app_tax_find_law') || !app_tax_find_law($conn, $taxLawKey)) {
                return app_tr('نوع الضريبة المحدد غير موجود.', 'The selected tax law/type was not found.');
            }
        }

        return '';
    }
}

if (!function_exists('recalculateSalesInvoice')) {
    function recalculateSalesInvoice(mysqli $conn, $invoice_id): void {
        if (!$invoice_id || $invoice_id === 'NULL') return;
        $invoice_id = (int)$invoice_id;
        financeEnsureAllocationSchema($conn);
        $inv = $conn->query("SELECT total_amount, status FROM invoices WHERE id = {$invoice_id}")->fetch_assoc();
        if (!$inv) return;
        if ((string)($inv['status'] ?? '') === 'cancelled') return;

        $paidLegacy = financeLegacyDirectAllocatedAmount($conn, 'sales_invoice', $invoice_id);
        $paidAllocated = financeAllocatedAmountByType($conn, 'sales_invoice', $invoice_id);
        $paidRaw = $paidLegacy + $paidAllocated;
        $invoiceTotal = (float)$inv['total_amount'];
        $paid = min($invoiceTotal, $paidRaw);
        $remaining = round($invoiceTotal - $paid, 2);
        $status = ($remaining <= 0) ? 'paid' : (($paidRaw > 0) ? 'partially_paid' : 'unpaid');
        if ($remaining < 0) $remaining = 0;
        $conn->query("UPDATE invoices SET paid_amount = {$paid}, remaining_amount = {$remaining}, status = '{$status}' WHERE id = {$invoice_id}");
    }
}

if (!function_exists('finance_sync_sales_invoice_status')) {
    function finance_sync_sales_invoice_status(mysqli $conn, int $invoiceId): void
    {
        if ($invoiceId <= 0) {
            return;
        }
        financeEnsureAllocationSchema($conn);

        $stmt = $conn->prepare("SELECT total_amount, due_date, status FROM invoices WHERE id = ? LIMIT 1");
        $stmt->bind_param('i', $invoiceId);
        $stmt->execute();
        $invoice = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$invoice) {
            return;
        }
        if ((string)($invoice['status'] ?? '') === 'cancelled') {
            return;
        }

        $paidRaw = financeLegacyDirectAllocatedAmount($conn, 'sales_invoice', $invoiceId)
            + financeAllocatedAmountByType($conn, 'sales_invoice', $invoiceId);
        // فواتير المبيعات تعتمد فقط على سندات القبض الموجهة للعميل.
        // لا نضيف سندات الصرف هنا لأن invoice_id قد يتطابق رقميًا مع فاتورة مشتريات
        // أو مصروف آخر غير تابع لوعاء فاتورة المبيعات نفسها.
        $finalTotal = (float)$invoice['total_amount'];
        $paid = min($finalTotal, $paidRaw);
        $remaining = round($finalTotal - $paid, 2);
        $today = date('Y-m-d');

        if ($remaining <= 0) {
            $status = 'paid';
            $remaining = 0;
        } elseif ($paidRaw > 0) {
            $status = 'partially_paid';
        } elseif ($today <= (string)$invoice['due_date']) {
            $status = 'deferred';
        } else {
            $status = 'overdue';
        }

        $stmtUpd = $conn->prepare("UPDATE invoices SET paid_amount = ?, remaining_amount = ?, status = ? WHERE id = ?");
        $stmtUpd->bind_param('ddsi', $paid, $remaining, $status, $invoiceId);
        $stmtUpd->execute();
        $stmtUpd->close();
    }
}

if (!function_exists('finance_validate_sales_invoice_job_client')) {
    function finance_validate_sales_invoice_job_client(mysqli $conn, int $clientId, int $jobId): string
    {
        if ($jobId <= 0) {
            return '';
        }
        if ($clientId <= 0) {
            return app_tr('يجب اختيار العميل قبل ربط الفاتورة بأمر تشغيل.', 'A client must be selected before linking the invoice to a work order.');
        }

        $stmt = $conn->prepare("SELECT client_id, status FROM job_orders WHERE id = ? LIMIT 1");
        $stmt->bind_param('i', $jobId);
        $stmt->execute();
        $job = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$job) {
            return app_tr('أمر التشغيل المحدد غير موجود.', 'The selected work order was not found.');
        }
        if ((int)($job['client_id'] ?? 0) !== $clientId) {
            return app_tr('أمر التشغيل لا يتبع العميل المحدد.', 'The selected work order does not belong to the selected client.');
        }

        return '';
    }
}

if (!function_exists('recalculatePurchaseInvoice')) {
    function recalculatePurchaseInvoice(mysqli $conn, $invoice_id): void {
        if (!$invoice_id || $invoice_id === 'NULL') return;
        $invoice_id = (int)$invoice_id;
        financeEnsureAllocationSchema($conn);
        $inv = $conn->query("SELECT total_amount, status FROM purchase_invoices WHERE id = {$invoice_id}")->fetch_assoc();
        if (!$inv) return;
        if ((string)($inv['status'] ?? '') === 'cancelled') return;

        $paidLegacy = financeLegacyDirectAllocatedAmount($conn, 'purchase_invoice', $invoice_id);
        $paidAllocated = financeAllocatedAmountByType($conn, 'purchase_invoice', $invoice_id);
        $paidRaw = $paidLegacy + $paidAllocated;
        $invoiceTotal = (float)$inv['total_amount'];
        $paid = min($invoiceTotal, $paidRaw);
        $remaining = round($invoiceTotal - $paid, 2);
        $status = 'unpaid';
        if ($remaining <= 0) {
            $status = 'paid';
            $remaining = 0;
        } elseif ($paidRaw > 0) {
            $status = 'partially_paid';
        }
        $conn->query("UPDATE purchase_invoices SET paid_amount = {$paid}, remaining_amount = {$remaining}, status = '{$status}' WHERE id = {$invoice_id}");
    }
}

if (!function_exists('recalculatePayroll')) {
    function recalculatePayroll(mysqli $conn, $payroll_id): void {
        if (!$payroll_id || $payroll_id === 'NULL') return;
        $payroll_id = (int)$payroll_id;
        app_payroll_sync_sheet($conn, $payroll_id);
    }
}

if (!function_exists('financeCreateReceiptMaster')) {
    function financeCreateReceiptMaster(
        mysqli $conn,
        string $type,
        string $category,
        float $amount,
        string $description,
        string $date,
        ?int $clientId,
        ?int $supplierId,
        ?int $employeeId,
        ?int $payrollId,
        string $createdBy
    ): int {
        $typeSql = "'" . $conn->real_escape_string($type) . "'";
        $categorySql = "'" . $conn->real_escape_string($category) . "'";
        $amountSql = "'" . round($amount, 2) . "'";
        $descriptionSql = "'" . $conn->real_escape_string($description) . "'";
        $dateSql = "'" . $conn->real_escape_string($date) . "'";
        $createdBySql = "'" . $conn->real_escape_string($createdBy) . "'";
        $clientSql = $clientId && $clientId > 0 ? (string)$clientId : 'NULL';
        $supplierSql = $supplierId && $supplierId > 0 ? (string)$supplierId : 'NULL';
        $employeeSql = $employeeId && $employeeId > 0 ? (string)$employeeId : 'NULL';
        $payrollSql = $payrollId && $payrollId > 0 ? (string)$payrollId : 'NULL';

        $sql = "
            INSERT INTO financial_receipts
            (type, category, amount, description, trans_date, client_id, invoice_id, supplier_id, employee_id, payroll_id, created_by)
            VALUES ({$typeSql}, {$categorySql}, {$amountSql}, {$descriptionSql}, {$dateSql}, {$clientSql}, NULL, {$supplierSql}, {$employeeSql}, {$payrollSql}, {$createdBySql})
        ";
        if (!$conn->query($sql)) {
            throw new RuntimeException('create receipt master failed: ' . $conn->error);
        }
        return (int)$conn->insert_id;
    }
}

if (!function_exists('autoAllocatePayment')) {
    function autoAllocatePayment(mysqli $conn, int $client_id, float $amount, string $date, string $desc, string $user): int {
        financeEnsureAllocationSchema($conn);
        $rem = $amount;
        $receiptId = financeCreateReceiptMaster($conn, 'in', 'general', $amount, $desc, $date, $client_id, null, null, null, $user);

        $c_data = $conn->query("SELECT opening_balance FROM clients WHERE id = {$client_id}")->fetch_assoc();
        $opening_bal = $c_data ? (float)$c_data['opening_balance'] : 0.0;
        if ($opening_bal > 0.00001) {
            $legacyGeneralStmt = $conn->prepare("
                SELECT IFNULL(SUM(r.amount), 0)
                FROM financial_receipts r
                LEFT JOIN financial_receipt_allocations a ON a.receipt_id = r.id
                WHERE r.client_id = ? AND r.type = 'in' AND r.invoice_id IS NULL AND a.id IS NULL
            ");
            $legacyGeneralStmt->bind_param('i', $client_id);
            $legacyGeneralStmt->execute();
            $legacyGeneralPaid = (float)($legacyGeneralStmt->get_result()->fetch_row()[0] ?? 0);
            $legacyGeneralStmt->close();

            $openingAppliedStmt = $conn->prepare("
                SELECT IFNULL(SUM(a.amount),0)
                FROM financial_receipt_allocations a
                INNER JOIN financial_receipts r ON r.id = a.receipt_id
                WHERE r.client_id = ? AND r.type = 'in' AND a.allocation_type = 'client_opening'
            ");
            $openingAppliedStmt->bind_param('i', $client_id);
            $openingAppliedStmt->execute();
            $openingAlreadyApplied = (float)($openingAppliedStmt->get_result()->fetch_row()[0] ?? 0);
            $openingAppliedStmt->close();

            $openingRemaining = max(0.0, round($opening_bal - $legacyGeneralPaid - $openingAlreadyApplied, 2));
            if ($openingRemaining > 0.00001 && $rem > 0.00001) {
                $openingPay = min($rem, $openingRemaining);
                financeInsertReceiptAllocation($conn, $receiptId, 'client_opening', $client_id, $openingPay, 'Opening balance');
                $rem -= $openingPay;
            }
        }

        if ($rem > 0) {
            $invs = $conn->query("SELECT id, remaining_amount FROM invoices WHERE client_id = {$client_id} AND status NOT IN ('paid','cancelled') ORDER BY inv_date ASC, id ASC");
            if ($invs) {
                while ($inv = $invs->fetch_assoc()) {
                    if ($rem <= 0) break;
                    $pay = ($rem >= (float)$inv['remaining_amount']) ? (float)$inv['remaining_amount'] : $rem;
                    financeInsertReceiptAllocation($conn, $receiptId, 'sales_invoice', (int)$inv['id'], $pay, 'FIFO');
                    recalculateSalesInvoice($conn, $inv['id']);
                    $rem -= $pay;
                }
            }
        }

        return $receiptId;
    }
}

if (!function_exists('autoAllocateSupplierPayment')) {
    function autoAllocateSupplierPayment(mysqli $conn, int $supplier_id, float $amount, string $date, string $desc, string $user): int {
        financeEnsureAllocationSchema($conn);
        $rem = $amount;
        $receiptId = financeCreateReceiptMaster($conn, 'out', 'supplier', $amount, $desc, $date, null, $supplier_id, null, null, $user);
        $invs = $conn->query("SELECT id, remaining_amount FROM purchase_invoices WHERE supplier_id = {$supplier_id} AND status NOT IN ('paid','cancelled') ORDER BY inv_date ASC, id ASC");
        if ($invs) {
            while ($inv = $invs->fetch_assoc()) {
                if ($rem <= 0) break;
                $pay = ($rem >= (float)$inv['remaining_amount']) ? (float)$inv['remaining_amount'] : $rem;
                financeInsertReceiptAllocation($conn, $receiptId, 'purchase_invoice', (int)$inv['id'], $pay, 'FIFO');
                recalculatePurchaseInvoice($conn, $inv['id']);
                $rem -= $pay;
            }
        }
        return $receiptId;
    }
}

if (!function_exists('autoAllocatePayrollPayment')) {
    function autoAllocatePayrollPayment(mysqli $conn, int $employee_id, float $amount, string $date, string $desc, string $user): int {
        financeEnsureAllocationSchema($conn);
        $rem = $amount;
        $receiptId = financeCreateReceiptMaster($conn, 'out', 'salary', $amount, $desc, $date, null, null, $employee_id, null, $user);
        $sheets = $conn->query("SELECT id, remaining_amount FROM payroll_sheets WHERE employee_id = {$employee_id} AND status != 'paid' ORDER BY month_year ASC, id ASC");
        if ($sheets) {
            while ($sheet = $sheets->fetch_assoc()) {
                if ($rem <= 0) break;
                $pay = ($rem >= (float)$sheet['remaining_amount']) ? (float)$sheet['remaining_amount'] : $rem;
                financeInsertReceiptAllocation($conn, $receiptId, 'payroll', (int)$sheet['id'], $pay, 'FIFO');
                recalculatePayroll($conn, $sheet['id']);
                $rem -= $pay;
            }
        }
        return $receiptId;
    }
}
