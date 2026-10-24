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


if (!function_exists('financeLifecycleNormalizeStatus')) {
    function financeLifecycleNormalizeStatus(?string $status): string
    {
        $status = strtolower(trim((string)$status));
        return $status !== '' ? $status : 'posted';
    }
}

if (!function_exists('financeLifecycleIsVoidedStatus')) {
    function financeLifecycleIsVoidedStatus(?string $status, bool $isVoid = false): bool
    {
        if ($isVoid) {
            return true;
        }
        return in_array(financeLifecycleNormalizeStatus($status), ['void', 'voided', 'cancelled', 'canceled'], true);
    }
}

if (!function_exists('financeEvaluateVoidPolicy')) {
    function financeEvaluateVoidPolicy(array $context): array
    {
        $entityAr = (string)($context['entity_ar'] ?? app_tr('المستند', 'Document'));
        $entityEn = (string)($context['entity_en'] ?? 'Document');
        $operation = strtolower(trim((string)($context['operation'] ?? 'void')));
        $status = (string)($context['status'] ?? 'posted');
        $isVoid = !empty($context['is_void']);
        if (financeLifecycleIsVoidedStatus($status, $isVoid)) {
            return [
                'ok' => false,
                'code' => 'already_voided',
                'message' => app_tr($entityAr . ' ملغي بالفعل.', $entityEn . ' is already voided.'),
            ];
        }

        $etaLocked = !empty($context['eta_locked']);
        if ($etaLocked && !empty($context['block_on_eta'])) {
            return [
                'ok' => false,
                'code' => 'eta_locked',
                'message' => app_tr('المستند مرتبط بالتكامل الضريبي ولا يمكن إلغاؤه بهذه العملية.', 'The document is linked to tax integration and cannot be voided by this operation.'),
            ];
        }

        $directReceiptCount = (int)($context['direct_receipt_count'] ?? 0);
        if ($directReceiptCount > 0 && !empty($context['block_on_direct_receipts'])) {
            return [
                'ok' => false,
                'code' => 'direct_receipts_linked',
                'message' => app_tr('لا يمكن إلغاء المستند بعد ربط حركات نقدية مباشرة به.', 'Cannot void the document after direct cash movements were linked to it.'),
            ];
        }

        $allocationCount = (int)($context['allocation_count'] ?? 0);
        if ($allocationCount > 0 && !empty($context['block_on_allocations'])) {
            return [
                'ok' => false,
                'code' => 'allocations_linked',
                'message' => app_tr('لا يمكن إلغاء المستند بعد ربط تخصيصات مالية به.', 'Cannot void the document after financial allocations were linked to it.'),
            ];
        }

        $linkedReturnCount = (int)($context['linked_return_count'] ?? 0);
        if ($linkedReturnCount > 0 && !empty($context['block_on_linked_returns'])) {
            return [
                'ok' => false,
                'code' => 'linked_returns_exist',
                'message' => app_tr('لا يمكن تنفيذ العملية لوجود مردودات مرتبطة بالمستند.', 'Cannot complete this operation because linked returns exist for the document.'),
            ];
        }

        $stockLocked = !empty($context['stock_locked']);
        if ($stockLocked && !empty($context['block_on_stock'])) {
            return [
                'ok' => false,
                'code' => 'stock_locked',
                'message' => app_tr('لا يمكن تنفيذ العملية بسبب حركات مخزون مرتبطة بالمستند.', 'Cannot complete this operation because inventory movements are linked to the document.'),
            ];
        }

        return [
            'ok' => true,
            'operation' => $operation,
        ];
    }
}

if (!function_exists('financeRegisterVoidAudit')) {
    function financeRegisterVoidAudit(mysqli $conn, string $eventKey, string $entityType, int $entityId, string $mode, string $actor = '', array $details = []): void
    {
        if (!function_exists('app_audit_log_add')) {
            return;
        }
        $payload = [
            'entity_type' => $entityType,
            'entity_key' => (string)$entityId,
            'details' => array_merge([
                'mode' => $mode,
                'actor' => $actor,
                'policy' => 'central_void_policy',
            ], $details),
        ];
        app_audit_log_add($conn, $eventKey, $payload);
    }
}

if (!function_exists('financePostingRule')) {
    function financePostingRule(string $ruleName, array $context = []): ?array
    {
        $ruleName = strtolower(trim($ruleName));
        switch ($ruleName) {
            case 'sales_invoice_issued':
                return [
                    'event_key' => 'issued',
                    'entry_type' => 'sales_invoice_issued',
                    'source_type' => 'sales_invoice',
                    'counterparty_type' => 'client',
                    'counterparty_id' => (int)($context['client_id'] ?? 0),
                    'reference' => (string)($context['ref'] ?? ''),
                    'trans_date' => (string)($context['doc_date'] ?? date('Y-m-d')),
                    'amount' => (float)($context['total_amount'] ?? 0),
                    'notes' => app_tr('إصدار فاتورة مبيعات', 'Issued sales invoice'),
                ];
            case 'purchase_invoice_issued':
                return [
                    'event_key' => 'issued',
                    'entry_type' => 'purchase_invoice_issued',
                    'source_type' => 'purchase_invoice',
                    'counterparty_type' => 'supplier',
                    'counterparty_id' => (int)($context['supplier_id'] ?? 0),
                    'reference' => (string)($context['ref'] ?? ''),
                    'trans_date' => (string)($context['doc_date'] ?? date('Y-m-d')),
                    'amount' => (float)($context['total_amount'] ?? 0),
                    'notes' => app_tr('إصدار فاتورة شراء', 'Issued purchase invoice'),
                ];
            case 'sales_invoice_void':
                return [
                    'event_key' => 'void',
                    'entry_type' => 'sales_invoice_void',
                    'source_type' => 'sales_invoice',
                    'counterparty_type' => 'client',
                    'counterparty_id' => (int)($context['counterparty_id'] ?? 0),
                    'reference' => (string)($context['ref'] ?? ''),
                    'trans_date' => (string)($context['doc_date'] ?? date('Y-m-d')),
                    'amount' => (float)($context['total_amount'] ?? 0),
                    'notes' => app_tr('إلغاء فاتورة مبيعات', 'Voided sales invoice'),
                ];
            case 'purchase_invoice_void':
                return [
                    'event_key' => 'void',
                    'entry_type' => 'purchase_invoice_void',
                    'source_type' => 'purchase_invoice',
                    'counterparty_type' => 'supplier',
                    'counterparty_id' => (int)($context['counterparty_id'] ?? 0),
                    'reference' => (string)($context['ref'] ?? ''),
                    'trans_date' => (string)($context['doc_date'] ?? date('Y-m-d')),
                    'amount' => (float)($context['total_amount'] ?? 0),
                    'notes' => app_tr('إلغاء فاتورة شراء', 'Voided purchase invoice'),
                ];
            case 'financial_receipt_posted':
                return [
                    'event_key' => 'posted',
                    'entry_type' => (string)($context['entry_type'] ?? 'receipt_cash_in'),
                    'source_type' => 'financial_receipt',
                    'counterparty_type' => ($context['counterparty_type'] ?? null),
                    'counterparty_id' => isset($context['counterparty_id']) ? (int)$context['counterparty_id'] : null,
                    'reference' => (string)($context['reference'] ?? ''),
                    'trans_date' => (string)($context['trans_date'] ?? date('Y-m-d')),
                    'amount' => (float)($context['amount'] ?? 0),
                    'notes' => (string)($context['notes'] ?? ''),
                ];
            case 'financial_receipt_opening_balance':
                return [
                    'event_key' => 'opening_balance',
                    'entry_type' => (string)($context['entry_type'] ?? 'opening_balance_receipt'),
                    'source_type' => 'financial_receipt',
                    'counterparty_type' => ($context['counterparty_type'] ?? null),
                    'counterparty_id' => isset($context['counterparty_id']) ? (int)$context['counterparty_id'] : null,
                    'reference' => (string)($context['reference'] ?? ''),
                    'trans_date' => (string)($context['trans_date'] ?? date('Y-m-d')),
                    'amount' => (float)($context['amount'] ?? 0),
                    'notes' => (string)($context['notes'] ?? ''),
                ];
            case 'tax_settlement_posted':
                return [
                    'event_key' => 'posted',
                    'entry_type' => 'tax_settlement_cash',
                    'source_type' => 'financial_receipt',
                    'counterparty_type' => null,
                    'counterparty_id' => null,
                    'reference' => (string)($context['reference'] ?? ''),
                    'trans_date' => (string)($context['trans_date'] ?? date('Y-m-d')),
                    'amount' => (float)($context['amount'] ?? 0),
                    'notes' => (string)($context['notes'] ?? app_tr('سداد ضريبي', 'Tax settlement')),
                ];
            case 'manual_settlement_posted':
                return [
                    'event_key' => 'posted',
                    'entry_type' => 'manual_settlement_cash',
                    'source_type' => 'financial_receipt',
                    'counterparty_type' => ($context['counterparty_type'] ?? null),
                    'counterparty_id' => isset($context['counterparty_id']) ? (int)$context['counterparty_id'] : null,
                    'reference' => (string)($context['reference'] ?? ''),
                    'trans_date' => (string)($context['trans_date'] ?? date('Y-m-d')),
                    'amount' => (float)($context['amount'] ?? 0),
                    'notes' => (string)($context['notes'] ?? app_tr('تسوية يدوية', 'Manual settlement')),
                ];
            case 'financial_receipt_allocation_posted':
                $allocationType = strtolower(trim((string)($context['allocation_type'] ?? 'general')));
                $entryType = 'allocation_' . ($allocationType !== '' ? $allocationType : 'general');
                $eventKey = (string)($context['event_key'] ?? 'allocation');
                return [
                    'event_key' => $eventKey,
                    'entry_type' => $entryType,
                    'source_type' => 'financial_receipt_allocation',
                    'counterparty_type' => ($context['counterparty_type'] ?? null),
                    'counterparty_id' => isset($context['counterparty_id']) ? (int)$context['counterparty_id'] : null,
                    'reference' => (string)($context['reference'] ?? ''),
                    'trans_date' => (string)($context['trans_date'] ?? date('Y-m-d')),
                    'amount' => (float)($context['amount'] ?? 0),
                    'notes' => (string)($context['notes'] ?? ''),
                ];
            case 'sales_return_issued':
                return [
                    'event_key' => 'issued',
                    'entry_type' => 'sales_return_credit',
                    'source_type' => 'sales_return',
                    'counterparty_type' => 'client',
                    'counterparty_id' => (int)($context['client_id'] ?? 0),
                    'reference' => (string)($context['ref'] ?? ''),
                    'trans_date' => (string)($context['doc_date'] ?? date('Y-m-d')),
                    'amount' => (float)($context['total_amount'] ?? 0),
                    'notes' => (string)($context['notes'] ?? ''),
                ];
            case 'purchase_return_issued':
                return [
                    'event_key' => 'issued',
                    'entry_type' => 'purchase_return_credit',
                    'source_type' => 'purchase_return',
                    'counterparty_type' => 'supplier',
                    'counterparty_id' => (int)($context['supplier_id'] ?? 0),
                    'reference' => (string)($context['ref'] ?? ''),
                    'trans_date' => (string)($context['doc_date'] ?? date('Y-m-d')),
                    'amount' => (float)($context['total_amount'] ?? 0),
                    'notes' => (string)($context['notes'] ?? ''),
                ];
            case 'sales_return_refund':
                return [
                    'event_key' => 'refund_posted',
                    'entry_type' => 'client_refund_cash',
                    'source_type' => 'sales_return_refund',
                    'counterparty_type' => 'client',
                    'counterparty_id' => (int)($context['client_id'] ?? 0),
                    'reference' => (string)($context['ref'] ?? ''),
                    'trans_date' => (string)($context['doc_date'] ?? date('Y-m-d')),
                    'amount' => (float)($context['refund_amount'] ?? 0),
                    'notes' => (string)($context['notes'] ?? ''),
                ];
            case 'purchase_return_refund':
                return [
                    'event_key' => 'refund_posted',
                    'entry_type' => 'supplier_refund_cash',
                    'source_type' => 'purchase_return_refund',
                    'counterparty_type' => 'supplier',
                    'counterparty_id' => (int)($context['supplier_id'] ?? 0),
                    'reference' => (string)($context['ref'] ?? ''),
                    'trans_date' => (string)($context['doc_date'] ?? date('Y-m-d')),
                    'amount' => (float)($context['refund_amount'] ?? 0),
                    'notes' => (string)($context['notes'] ?? ''),
                ];
            case 'sales_return_void':
                return [
                    'event_key' => 'void',
                    'entry_type' => 'sales_return_void',
                    'source_type' => 'sales_return',
                    'counterparty_type' => null,
                    'counterparty_id' => null,
                    'reference' => (string)($context['ref'] ?? ''),
                    'trans_date' => (string)($context['doc_date'] ?? date('Y-m-d')),
                    'amount' => (float)($context['total_amount'] ?? 0),
                    'notes' => app_tr('إلغاء مردود مبيعات', 'Voided sales return'),
                ];
            case 'purchase_return_void':
                return [
                    'event_key' => 'void',
                    'entry_type' => 'purchase_return_void',
                    'source_type' => 'purchase_return',
                    'counterparty_type' => null,
                    'counterparty_id' => null,
                    'reference' => (string)($context['ref'] ?? ''),
                    'trans_date' => (string)($context['doc_date'] ?? date('Y-m-d')),
                    'amount' => (float)($context['total_amount'] ?? 0),
                    'notes' => app_tr('إلغاء مردود شراء', 'Voided purchase return'),
                ];
        }
        return null;
    }
}

if (!function_exists('financeLedgerPostByRule')) {
    function financeLedgerPostByRule(mysqli $conn, string $ruleName, int $sourceId, array $context = [], array $payload = [], string $actor = ''): void
    {
        $rule = financePostingRule($ruleName, $context);
        if (!$rule) {
            return;
        }
        financeLedgerUpsert(
            $conn,
            (string)($rule['event_key'] ?? ''),
            (string)($rule['source_type'] ?? ''),
            (int)$sourceId,
            (string)($rule['entry_type'] ?? ''),
            (string)($rule['trans_date'] ?? date('Y-m-d')),
            (float)($rule['amount'] ?? 0),
            $rule['counterparty_type'] ?? null,
            isset($rule['counterparty_id']) ? (int)$rule['counterparty_id'] : null,
            (string)($rule['reference'] ?? ''),
            (string)($rule['notes'] ?? ''),
            $payload,
            $actor
        );
    }
}

if (!function_exists('finance_post_sales_invoice_issue_to_ledger')) {
    function finance_post_sales_invoice_issue_to_ledger(mysqli $conn, int $invoiceId, string $actor = ''): void
    {
        financeEnsureLedgerSchema($conn);
        $invoiceId = (int)$invoiceId;
        if ($invoiceId <= 0) {
            return;
        }

        $stmt = $conn->prepare("
            SELECT id, IFNULL(invoice_number,'') AS ref, COALESCE(inv_date, CURDATE()) AS doc_date,
                   IFNULL(total_amount,0) AS total_amount, IFNULL(sub_total,0) AS sub_total,
                   IFNULL(discount,0) AS discount_amount, IFNULL(tax_total, IFNULL(tax,0)) AS tax_amount,
                   IFNULL(client_id,0) AS client_id, IFNULL(status,'deferred') AS status
            FROM invoices
            WHERE id = ?
            LIMIT 1
        ");
        $stmt->bind_param('i', $invoiceId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        if (!$row) {
            return;
        }

        financeLedgerPostByRule(
            $conn,
            'sales_invoice_issued',
            $invoiceId,
            [
                'client_id' => (int)($row['client_id'] ?? 0),
                'ref' => (string)($row['ref'] ?? ''),
                'doc_date' => (string)($row['doc_date'] ?? date('Y-m-d')),
                'total_amount' => max(0.0, round((float)($row['total_amount'] ?? 0), 2)),
            ],
            [
                'sub_total' => (float)($row['sub_total'] ?? 0),
                'discount_amount' => (float)($row['discount_amount'] ?? 0),
                'tax_amount' => (float)($row['tax_amount'] ?? 0),
                'status' => (string)($row['status'] ?? 'deferred'),
            ],
            $actor
        );
    }
}

if (!function_exists('finance_post_purchase_invoice_issue_to_ledger')) {
    function finance_post_purchase_invoice_issue_to_ledger(mysqli $conn, int $invoiceId, string $actor = ''): void
    {
        financeEnsureLedgerSchema($conn);
        $invoiceId = (int)$invoiceId;
        if ($invoiceId <= 0) {
            return;
        }

        $stmt = $conn->prepare("
            SELECT id, IFNULL(purchase_number,'') AS ref,
                   COALESCE(inv_date, CURDATE()) AS doc_date,
                   IFNULL(total_amount,0) AS total_amount,
                   IFNULL(subtotal_amount, IFNULL(sub_total,0)) AS sub_total,
                   IFNULL(discount_amount, IFNULL(discount,0)) AS discount_amount,
                   IFNULL(tax_amount, IFNULL(tax,0)) AS tax_amount,
                   IFNULL(supplier_id,0) AS supplier_id,
                   IFNULL(status,'unpaid') AS status
            FROM purchase_invoices
            WHERE id = ?
            LIMIT 1
        ");
        $stmt->bind_param('i', $invoiceId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        if (!$row) {
            return;
        }

        financeLedgerPostByRule(
            $conn,
            'purchase_invoice_issued',
            $invoiceId,
            [
                'supplier_id' => (int)($row['supplier_id'] ?? 0),
                'ref' => (string)($row['ref'] ?? ''),
                'doc_date' => (string)($row['doc_date'] ?? date('Y-m-d')),
                'total_amount' => max(0.0, round((float)($row['total_amount'] ?? 0), 2)),
            ],
            [
                'sub_total' => (float)($row['sub_total'] ?? 0),
                'discount_amount' => (float)($row['discount_amount'] ?? 0),
                'tax_amount' => (float)($row['tax_amount'] ?? 0),
                'status' => (string)($row['status'] ?? 'unpaid'),
            ],
            $actor
        );
    }
}

if (!function_exists('finance_post_invoice_void_to_ledger')) {
    function finance_post_invoice_void_to_ledger(mysqli $conn, string $invoiceType, int $invoiceId, string $actor = ''): void
    {
        financeEnsureLedgerSchema($conn);
        $invoiceType = strtolower(trim($invoiceType));
        $invoiceId = (int)$invoiceId;
        if ($invoiceId <= 0 || !in_array($invoiceType, ['sales', 'purchase'], true)) {
            return;
        }

        if ($invoiceType === 'sales') {
            $stmt = $conn->prepare("SELECT id, IFNULL(invoice_number,'') AS ref, COALESCE(inv_date, CURDATE()) AS doc_date, IFNULL(total_amount,0) AS total_amount, IFNULL(client_id,0) AS counterparty_id FROM invoices WHERE id = ? LIMIT 1");
            $stmt->bind_param('i', $invoiceId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc() ?: null;
            $stmt->close();
            if (!$row) {
                return;
            }
            financeLedgerPostByRule(
                $conn,
                'sales_invoice_void',
                $invoiceId,
                [
                    'counterparty_id' => (int)($row['counterparty_id'] ?? 0),
                    'ref' => (string)($row['ref'] ?? ''),
                    'doc_date' => (string)($row['doc_date'] ?? date('Y-m-d')),
                    'total_amount' => max(0.0, round((float)($row['total_amount'] ?? 0), 2)),
                ],
                [],
                $actor
            );
            return;
        }

        $stmt = $conn->prepare("SELECT id, IFNULL(purchase_number,'') AS ref, COALESCE(inv_date, CURDATE()) AS doc_date, IFNULL(total_amount,0) AS total_amount, IFNULL(supplier_id,0) AS counterparty_id FROM purchase_invoices WHERE id = ? LIMIT 1");
        $stmt->bind_param('i', $invoiceId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        if (!$row) {
            return;
        }
        financeLedgerPostByRule(
            $conn,
            'purchase_invoice_void',
            $invoiceId,
            [
                'counterparty_id' => (int)($row['counterparty_id'] ?? 0),
                'ref' => (string)($row['ref'] ?? ''),
                'doc_date' => (string)($row['doc_date'] ?? date('Y-m-d')),
                'total_amount' => max(0.0, round((float)($row['total_amount'] ?? 0), 2)),
            ],
            [],
            $actor
        );
    }
}

if (!function_exists('financeLedgerDeleteById')) {
    function financeLedgerDeleteById(mysqli $conn, int $ledgerId): void
    {
        $ledgerId = (int)$ledgerId;
        if ($ledgerId <= 0) {
            return;
        }
        $stmt = $conn->prepare("DELETE FROM finance_ledger_entries WHERE id = ?");
        $stmt->bind_param('i', $ledgerId);
        $stmt->execute();
        $stmt->close();
    }
}

if (!function_exists('finance_post_sales_return_issue_to_ledger')) {
    function finance_post_sales_return_issue_to_ledger(mysqli $conn, int $salesReturnId, string $actor = ''): void
    {
        financeEnsureSalesReturnSchema($conn);
        $salesReturnId = (int)$salesReturnId;
        if ($salesReturnId <= 0) {
            return;
        }
        $stmt = $conn->prepare("
            SELECT r.id, r.invoice_id, r.return_number, r.return_date, r.total_amount, r.subtotal_amount, r.tax_amount, r.discount_amount,
                   IFNULL(i.client_id,0) AS client_id, IFNULL(r.notes,'') AS notes
            FROM invoice_returns r
            LEFT JOIN invoices i ON i.id = r.invoice_id
            WHERE r.id = ?
            LIMIT 1
        ");
        $stmt->bind_param('i', $salesReturnId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        if (!$row) {
            return;
        }
        financeLedgerPostByRule(
            $conn,
            'sales_return_issued',
            $salesReturnId,
            [
                'client_id' => (int)($row['client_id'] ?? 0),
                'ref' => (string)($row['return_number'] ?? ''),
                'doc_date' => (string)($row['return_date'] ?? date('Y-m-d')),
                'total_amount' => max(0.0, round((float)($row['total_amount'] ?? 0), 2)),
                'notes' => trim((string)($row['notes'] ?? '')),
            ],
            [
                'invoice_id' => (int)($row['invoice_id'] ?? 0),
                'subtotal_amount' => (float)($row['subtotal_amount'] ?? 0),
                'tax_amount' => (float)($row['tax_amount'] ?? 0),
                'discount_amount' => (float)($row['discount_amount'] ?? 0),
                'total_amount' => (float)($row['total_amount'] ?? 0),
            ],
            $actor
        );
    }
}

if (!function_exists('finance_post_purchase_return_issue_to_ledger')) {
    function finance_post_purchase_return_issue_to_ledger(mysqli $conn, int $purchaseReturnId, string $actor = ''): void
    {
        financeEnsurePurchaseReturnFinanceSchema($conn);
        $purchaseReturnId = (int)$purchaseReturnId;
        if ($purchaseReturnId <= 0) {
            return;
        }
        $stmt = $conn->prepare("
            SELECT r.id, r.purchase_invoice_id, r.return_number, r.return_date, r.total_amount,
                   IFNULL(r.subtotal_amount,0) AS subtotal_amount, IFNULL(r.tax_amount,0) AS tax_amount, IFNULL(r.discount_amount,0) AS discount_amount,
                   IFNULL(p.supplier_id,0) AS supplier_id, IFNULL(r.notes,'') AS notes
            FROM purchase_invoice_returns r
            LEFT JOIN purchase_invoices p ON p.id = r.purchase_invoice_id
            WHERE r.id = ?
            LIMIT 1
        ");
        $stmt->bind_param('i', $purchaseReturnId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        if (!$row) {
            return;
        }
        financeLedgerPostByRule(
            $conn,
            'purchase_return_issued',
            $purchaseReturnId,
            [
                'supplier_id' => (int)($row['supplier_id'] ?? 0),
                'ref' => (string)($row['return_number'] ?? ''),
                'doc_date' => (string)($row['return_date'] ?? date('Y-m-d')),
                'total_amount' => max(0.0, round((float)($row['total_amount'] ?? 0), 2)),
                'notes' => trim((string)($row['notes'] ?? '')),
            ],
            [
                'purchase_invoice_id' => (int)($row['purchase_invoice_id'] ?? 0),
                'subtotal_amount' => (float)($row['subtotal_amount'] ?? 0),
                'tax_amount' => (float)($row['tax_amount'] ?? 0),
                'discount_amount' => (float)($row['discount_amount'] ?? 0),
                'total_amount' => (float)($row['total_amount'] ?? 0),
            ],
            $actor
        );
    }
}

if (!function_exists('finance_post_sales_return_void_to_ledger')) {
    function finance_post_sales_return_void_to_ledger(mysqli $conn, int $salesReturnId, string $actor = ''): void
    {
        financeEnsureSalesReturnSchema($conn);
        $salesReturnId = (int)$salesReturnId;
        if ($salesReturnId <= 0) {
            return;
        }
        $stmt = $conn->prepare("
            SELECT id, return_number, return_date, total_amount, invoice_id
            FROM invoice_returns
            WHERE id = ?
            LIMIT 1
        ");
        $stmt->bind_param('i', $salesReturnId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        if (!$row) {
            return;
        }
        financeLedgerPostByRule(
            $conn,
            'sales_return_void',
            $salesReturnId,
            [
                'ref' => (string)($row['return_number'] ?? ''),
                'doc_date' => (string)($row['return_date'] ?? date('Y-m-d')),
                'total_amount' => max(0.0, round((float)($row['total_amount'] ?? 0), 2)),
            ],
            ['invoice_id' => (int)($row['invoice_id'] ?? 0)],
            $actor
        );
    }
}

if (!function_exists('finance_post_purchase_return_void_to_ledger')) {
    function finance_post_purchase_return_void_to_ledger(mysqli $conn, int $purchaseReturnId, string $actor = ''): void
    {
        financeEnsurePurchaseReturnFinanceSchema($conn);
        $purchaseReturnId = (int)$purchaseReturnId;
        if ($purchaseReturnId <= 0) {
            return;
        }
        $stmt = $conn->prepare("
            SELECT id, return_number, return_date, total_amount, purchase_invoice_id
            FROM purchase_invoice_returns
            WHERE id = ?
            LIMIT 1
        ");
        $stmt->bind_param('i', $purchaseReturnId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        if (!$row) {
            return;
        }
        financeLedgerPostByRule(
            $conn,
            'purchase_return_void',
            $purchaseReturnId,
            [
                'ref' => (string)($row['return_number'] ?? ''),
                'doc_date' => (string)($row['return_date'] ?? date('Y-m-d')),
                'total_amount' => max(0.0, round((float)($row['total_amount'] ?? 0), 2)),
            ],
            ['purchase_invoice_id' => (int)($row['purchase_invoice_id'] ?? 0)],
            $actor
        );
    }
}

if (!function_exists('finance_post_sales_return_refund_to_ledger')) {
    function finance_post_sales_return_refund_to_ledger(mysqli $conn, int $salesReturnId, string $actor = ''): void
    {
        financeEnsureSalesReturnSchema($conn);
        $salesReturnId = (int)$salesReturnId;
        if ($salesReturnId <= 0) {
            return;
        }
        $stmt = $conn->prepare("SELECT IFNULL(refund_amount,0) AS refund_amount FROM invoice_returns WHERE id = ? LIMIT 1");
        $stmt->bind_param('i', $salesReturnId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        if (!$row) {
            return;
        }
        finance_sync_sales_return_refund_receipt($conn, $salesReturnId, (float)($row['refund_amount'] ?? 0), $actor);
    }
}

if (!function_exists('finance_post_purchase_return_refund_to_ledger')) {
    function finance_post_purchase_return_refund_to_ledger(mysqli $conn, int $purchaseReturnId, string $actor = ''): void
    {
        financeEnsurePurchaseReturnFinanceSchema($conn);
        $purchaseReturnId = (int)$purchaseReturnId;
        if ($purchaseReturnId <= 0) {
            return;
        }
        $stmt = $conn->prepare("SELECT IFNULL(refund_amount,0) AS refund_amount FROM purchase_invoice_returns WHERE id = ? LIMIT 1");
        $stmt->bind_param('i', $purchaseReturnId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        if (!$row) {
            return;
        }
        finance_sync_purchase_return_refund_receipt($conn, $purchaseReturnId, (float)($row['refund_amount'] ?? 0), $actor);
    }
}

if (!function_exists('financeRepairPostingAuditIssue')) {
    function financeRepairPostingAuditIssue(mysqli $conn, string $sectionKey, int $sourceId, int $ledgerId = 0, string $actor = ''): array
    {
        financeEnsureLedgerSchema($conn);
        $sectionKey = strtolower(trim($sectionKey));
        $sourceId = (int)$sourceId;
        $ledgerId = (int)$ledgerId;
        $actor = trim($actor) !== '' ? trim($actor) : 'System';

        switch ($sectionKey) {
            case 'sales_invoice_issue':
                finance_post_sales_invoice_issue_to_ledger($conn, $sourceId, $actor);
                break;
            case 'purchase_invoice_issue':
                finance_post_purchase_invoice_issue_to_ledger($conn, $sourceId, $actor);
                break;
            case 'receipt_posting':
                financeSyncReceiptLedger($conn, $sourceId, $actor);
                break;
            case 'sales_invoice_void':
                finance_post_invoice_void_to_ledger($conn, 'sales', $sourceId, $actor);
                break;
            case 'purchase_invoice_void':
                finance_post_invoice_void_to_ledger($conn, 'purchase', $sourceId, $actor);
                break;
            case 'sales_return_issue':
                finance_post_sales_return_issue_to_ledger($conn, $sourceId, $actor);
                break;
            case 'purchase_return_issue':
                finance_post_purchase_return_issue_to_ledger($conn, $sourceId, $actor);
                break;
            case 'sales_return_refund':
                finance_post_sales_return_refund_to_ledger($conn, $sourceId, $actor);
                break;
            case 'purchase_return_refund':
                finance_post_purchase_return_refund_to_ledger($conn, $sourceId, $actor);
                break;
            case 'sales_return_void':
                finance_post_sales_return_void_to_ledger($conn, $sourceId, $actor);
                break;
            case 'purchase_return_void':
                finance_post_purchase_return_void_to_ledger($conn, $sourceId, $actor);
                break;
            case 'orphan_ledger':
                financeLedgerDeleteById($conn, $ledgerId);
                break;
            default:
                return ['ok' => false, 'message' => app_tr('نوع معالجة التدقيق غير مدعوم.', 'Unsupported audit remediation type.')];
        }

        if (function_exists('app_audit_log_add')) {
            app_audit_log_add($conn, 'finance.posting_audit_repaired', [
                'entity_type' => 'posting_audit',
                'entity_key' => $sectionKey . ':' . $sourceId . ':' . $ledgerId,
                'details' => [
                    'section_key' => $sectionKey,
                    'source_id' => $sourceId,
                    'ledger_id' => $ledgerId,
                    'actor' => $actor,
                ],
            ]);
        }
        return ['ok' => true];
    }
}

if (!function_exists('financeEnsurePostingAuditRunSchema')) {
    function financeEnsurePostingAuditRunSchema(mysqli $conn): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;

        $conn->query("
            CREATE TABLE IF NOT EXISTS finance_posting_audit_runs (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                run_type VARCHAR(40) NOT NULL DEFAULT 'bulk_repair',
                section_key VARCHAR(80) NOT NULL,
                date_from DATE NOT NULL,
                date_to DATE NOT NULL,
                preview_total_count INT NOT NULL DEFAULT 0,
                scanned_count INT NOT NULL DEFAULT 0,
                repaired_count INT NOT NULL DEFAULT 0,
                failed_count INT NOT NULL DEFAULT 0,
                remaining_count INT NOT NULL DEFAULT 0,
                repair_limit_count INT NOT NULL DEFAULT 200,
                actor_name VARCHAR(190) NOT NULL DEFAULT '',
                notes TEXT DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_finance_posting_audit_runs_created (created_at),
                KEY idx_finance_posting_audit_runs_section (section_key, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $conn->query("
            CREATE TABLE IF NOT EXISTS finance_posting_audit_run_items (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                run_id BIGINT UNSIGNED NOT NULL,
                section_key VARCHAR(80) NOT NULL,
                source_id BIGINT UNSIGNED DEFAULT NULL,
                ledger_id BIGINT UNSIGNED DEFAULT NULL,
                item_status VARCHAR(40) NOT NULL DEFAULT 'pending',
                message TEXT DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_finance_posting_audit_run_items_run (run_id, id),
                KEY idx_finance_posting_audit_run_items_status (item_status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }
}

if (!function_exists('financeCreatePostingAuditRun')) {
    function financeCreatePostingAuditRun(mysqli $conn, string $sectionKey, string $dateFrom, string $dateTo, int $previewTotalCount, int $repairLimitCount, string $actor = '', string $notes = ''): int
    {
        financeEnsurePostingAuditRunSchema($conn);
        $actor = mb_substr(trim($actor) !== '' ? trim($actor) : 'System', 0, 190);
        $stmt = $conn->prepare("
            INSERT INTO finance_posting_audit_runs (
                run_type, section_key, date_from, date_to, preview_total_count, scanned_count, repaired_count, failed_count, remaining_count, repair_limit_count, actor_name, notes
            ) VALUES ('bulk_repair', ?, ?, ?, ?, 0, 0, 0, ?, ?, ?, ?)
        ");
        $remaining = max(0, (int)$previewTotalCount);
        $stmt->bind_param(
            'sssiiiss',
            $sectionKey,
            $dateFrom,
            $dateTo,
            $previewTotalCount,
            $remaining,
            $repairLimitCount,
            $actor,
            $notes
        );
        $stmt->execute();
        $runId = (int)$stmt->insert_id;
        $stmt->close();
        return $runId;
    }
}

if (!function_exists('financeAppendPostingAuditRunItem')) {
    function financeAppendPostingAuditRunItem(mysqli $conn, int $runId, string $sectionKey, int $sourceId, int $ledgerId, string $itemStatus, string $message = ''): void
    {
        financeEnsurePostingAuditRunSchema($conn);
        $runId = (int)$runId;
        if ($runId <= 0) {
            return;
        }
        $itemStatus = trim($itemStatus) !== '' ? trim($itemStatus) : 'pending';
        $stmt = $conn->prepare("
            INSERT INTO finance_posting_audit_run_items (
                run_id, section_key, source_id, ledger_id, item_status, message
            ) VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param('isiiss', $runId, $sectionKey, $sourceId, $ledgerId, $itemStatus, $message);
        $stmt->execute();
        $stmt->close();
    }
}

if (!function_exists('financeFinalizePostingAuditRun')) {
    function financeFinalizePostingAuditRun(mysqli $conn, int $runId, int $scannedCount, int $repairedCount, int $failedCount, int $remainingCount): void
    {
        financeEnsurePostingAuditRunSchema($conn);
        $runId = (int)$runId;
        if ($runId <= 0) {
            return;
        }
        $stmt = $conn->prepare("
            UPDATE finance_posting_audit_runs
            SET scanned_count = ?, repaired_count = ?, failed_count = ?, remaining_count = ?
            WHERE id = ?
        ");
        $stmt->bind_param('iiiii', $scannedCount, $repairedCount, $failedCount, $remainingCount, $runId);
        $stmt->execute();
        $stmt->close();
    }
}

if (!function_exists('financePostingAuditRunDrillUrl')) {
    function financePostingAuditRunDrillUrl(string $sectionKey, int $sourceId, int $ledgerId = 0): string
    {
        $sectionKey = strtolower(trim($sectionKey));
        $sourceId = (int)$sourceId;
        $ledgerId = (int)$ledgerId;
        return match ($sectionKey) {
            'sales_invoice_issue', 'sales_invoice_void' => 'invoices.php?tab=sales',
            'purchase_invoice_issue', 'purchase_invoice_void' => 'invoices.php?tab=purchases',
            'receipt_posting' => 'finance.php',
            'sales_return_issue', 'sales_return_refund', 'sales_return_void' => 'sales_returns.php',
            'purchase_return_issue', 'purchase_return_refund', 'purchase_return_void' => 'purchase_returns.php',
            'orphan_ledger' => 'finance_ledger.php' . ($ledgerId > 0 ? '?source_type=' . rawurlencode('') : ''),
            default => 'finance_posting_audit.php',
        };
    }
}

if (!function_exists('financePreviewPostingAuditSectionBulk')) {
    function financePreviewPostingAuditSectionBulk(mysqli $conn, string $sectionKey, string $dateFrom, string $dateTo, int $limit = 200): array
    {
        $sectionKey = strtolower(trim($sectionKey));
        $limit = max(1, min(500, (int)$limit));
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

        $allowed = [
            'sales_invoice_issue',
            'purchase_invoice_issue',
            'receipt_posting',
            'sales_invoice_void',
            'purchase_invoice_void',
            'sales_return_issue',
            'purchase_return_issue',
            'sales_return_void',
            'purchase_return_void',
        ];
        if (!in_array($sectionKey, $allowed, true)) {
            return ['ok' => false, 'message' => app_tr('المعاينة الجماعية متاحة فقط للمسارات الآمنة منخفضة المخاطر.', 'Bulk preview is available only for low-risk safe posting paths.')];
        }

        $from = $conn->real_escape_string($dateFrom);
        $to = $conn->real_escape_string($dateTo);
        $sql = '';
        switch ($sectionKey) {
            case 'sales_invoice_issue':
                $sql = "SELECT COUNT(*) FROM invoices i WHERE DATE(COALESCE(i.inv_date, CURDATE())) BETWEEN '{$from}' AND '{$to}' AND COALESCE(i.status,'') <> 'cancelled' AND NOT EXISTS (SELECT 1 FROM finance_ledger_entries l WHERE l.source_type='sales_invoice' AND l.source_id=i.id AND l.entry_type='sales_invoice_issued')";
                break;
            case 'purchase_invoice_issue':
                $sql = "SELECT COUNT(*) FROM purchase_invoices p WHERE DATE(COALESCE(p.inv_date, CURDATE())) BETWEEN '{$from}' AND '{$to}' AND COALESCE(p.status,'') <> 'cancelled' AND NOT EXISTS (SELECT 1 FROM finance_ledger_entries l WHERE l.source_type='purchase_invoice' AND l.source_id=p.id AND l.entry_type='purchase_invoice_issued')";
                break;
            case 'receipt_posting':
                $sql = "SELECT COUNT(*) FROM financial_receipts r WHERE DATE(COALESCE(r.trans_date, CURDATE())) BETWEEN '{$from}' AND '{$to}' AND NOT EXISTS (SELECT 1 FROM finance_ledger_entries l WHERE l.source_type='financial_receipt' AND l.source_id=r.id AND l.entry_type IN ('receipt_cash_in','receipt_cash_out','tax_settlement_cash','manual_settlement_cash','opening_balance_receipt','opening_balance_payment'))";
                break;
            case 'sales_invoice_void':
                $sql = "SELECT COUNT(*) FROM invoices i WHERE DATE(COALESCE(i.inv_date, CURDATE())) BETWEEN '{$from}' AND '{$to}' AND LOWER(TRIM(COALESCE(i.status,'')))='cancelled' AND NOT EXISTS (SELECT 1 FROM finance_ledger_entries l WHERE l.source_type='sales_invoice' AND l.source_id=i.id AND l.entry_type='sales_invoice_void')";
                break;
            case 'purchase_invoice_void':
                $sql = "SELECT COUNT(*) FROM purchase_invoices p WHERE DATE(COALESCE(p.inv_date, CURDATE())) BETWEEN '{$from}' AND '{$to}' AND LOWER(TRIM(COALESCE(p.status,'')))='cancelled' AND NOT EXISTS (SELECT 1 FROM finance_ledger_entries l WHERE l.source_type='purchase_invoice' AND l.source_id=p.id AND l.entry_type='purchase_invoice_void')";
                break;
            case 'sales_return_issue':
                $sql = "SELECT COUNT(*) FROM invoice_returns r WHERE DATE(COALESCE(r.return_date, CURDATE())) BETWEEN '{$from}' AND '{$to}' AND IFNULL(r.is_void,0)=0 AND LOWER(TRIM(IFNULL(r.status,'posted'))) NOT IN ('void','cancelled') AND NOT EXISTS (SELECT 1 FROM finance_ledger_entries l WHERE l.source_type='sales_return' AND l.source_id=r.id AND l.entry_type='sales_return_credit')";
                break;
            case 'purchase_return_issue':
                $sql = "SELECT COUNT(*) FROM purchase_invoice_returns r WHERE DATE(COALESCE(r.return_date, CURDATE())) BETWEEN '{$from}' AND '{$to}' AND LOWER(TRIM(IFNULL(r.status,'posted'))) NOT IN ('void','cancelled') AND NOT EXISTS (SELECT 1 FROM finance_ledger_entries l WHERE l.source_type='purchase_return' AND l.source_id=r.id AND l.entry_type='purchase_return_credit')";
                break;
            case 'sales_return_void':
                $sql = "SELECT COUNT(*) FROM invoice_returns r WHERE DATE(COALESCE(r.return_date, CURDATE())) BETWEEN '{$from}' AND '{$to}' AND (IFNULL(r.is_void,0)=1 OR LOWER(TRIM(IFNULL(r.status,'posted'))) IN ('void','cancelled')) AND NOT EXISTS (SELECT 1 FROM finance_ledger_entries l WHERE l.source_type='sales_return' AND l.source_id=r.id AND l.entry_type='sales_return_void')";
                break;
            case 'purchase_return_void':
                $sql = "SELECT COUNT(*) FROM purchase_invoice_returns r WHERE DATE(COALESCE(r.return_date, CURDATE())) BETWEEN '{$from}' AND '{$to}' AND LOWER(TRIM(IFNULL(r.status,'posted'))) IN ('void','cancelled') AND NOT EXISTS (SELECT 1 FROM finance_ledger_entries l WHERE l.source_type='purchase_return' AND l.source_id=r.id AND l.entry_type='purchase_return_void')";
                break;
        }

        if ($sql === '') {
            return ['ok' => false, 'message' => app_tr('تعذر تجهيز معاينة المعالجة الجماعية.', 'Could not prepare bulk remediation preview.')];
        }

        $total = (int)($conn->query($sql)->fetch_row()[0] ?? 0);
        return [
            'ok' => true,
            'total_count' => max(0, $total),
            'repair_count' => min(max(0, $total), $limit),
            'limit' => $limit,
        ];
    }
}

if (!function_exists('financeRepairPostingAuditSectionBulk')) {
    function financeRepairPostingAuditSectionBulk(mysqli $conn, string $sectionKey, string $dateFrom, string $dateTo, string $actor = '', int $limit = 200): array
    {
        $sectionKey = strtolower(trim($sectionKey));
        $actor = trim($actor) !== '' ? trim($actor) : 'System';
        $limit = max(1, min(500, (int)$limit));
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

        $allowed = [
            'sales_invoice_issue',
            'purchase_invoice_issue',
            'receipt_posting',
            'sales_invoice_void',
            'purchase_invoice_void',
            'sales_return_issue',
            'purchase_return_issue',
            'sales_return_void',
            'purchase_return_void',
        ];
        if (!in_array($sectionKey, $allowed, true)) {
            return ['ok' => false, 'message' => app_tr('المعالجة الجماعية متاحة فقط للمسارات الآمنة منخفضة المخاطر.', 'Bulk remediation is available only for low-risk safe posting paths.')];
        }

        $from = $conn->real_escape_string($dateFrom);
        $to = $conn->real_escape_string($dateTo);
        $sql = '';
        switch ($sectionKey) {
            case 'sales_invoice_issue':
                $sql = "SELECT i.id AS source_id, 0 AS ledger_id FROM invoices i WHERE DATE(COALESCE(i.inv_date, CURDATE())) BETWEEN '{$from}' AND '{$to}' AND COALESCE(i.status,'') <> 'cancelled' AND NOT EXISTS (SELECT 1 FROM finance_ledger_entries l WHERE l.source_type='sales_invoice' AND l.source_id=i.id AND l.entry_type='sales_invoice_issued') ORDER BY i.id DESC LIMIT {$limit}";
                break;
            case 'purchase_invoice_issue':
                $sql = "SELECT p.id AS source_id, 0 AS ledger_id FROM purchase_invoices p WHERE DATE(COALESCE(p.inv_date, CURDATE())) BETWEEN '{$from}' AND '{$to}' AND COALESCE(p.status,'') <> 'cancelled' AND NOT EXISTS (SELECT 1 FROM finance_ledger_entries l WHERE l.source_type='purchase_invoice' AND l.source_id=p.id AND l.entry_type='purchase_invoice_issued') ORDER BY p.id DESC LIMIT {$limit}";
                break;
            case 'receipt_posting':
                $sql = "SELECT r.id AS source_id, 0 AS ledger_id FROM financial_receipts r WHERE DATE(COALESCE(r.trans_date, CURDATE())) BETWEEN '{$from}' AND '{$to}' AND NOT EXISTS (SELECT 1 FROM finance_ledger_entries l WHERE l.source_type='financial_receipt' AND l.source_id=r.id AND l.entry_type IN ('receipt_cash_in','receipt_cash_out','tax_settlement_cash','manual_settlement_cash','opening_balance_receipt','opening_balance_payment')) ORDER BY r.id DESC LIMIT {$limit}";
                break;
            case 'sales_invoice_void':
                $sql = "SELECT i.id AS source_id, 0 AS ledger_id FROM invoices i WHERE DATE(COALESCE(i.inv_date, CURDATE())) BETWEEN '{$from}' AND '{$to}' AND LOWER(TRIM(COALESCE(i.status,'')))='cancelled' AND NOT EXISTS (SELECT 1 FROM finance_ledger_entries l WHERE l.source_type='sales_invoice' AND l.source_id=i.id AND l.entry_type='sales_invoice_void') ORDER BY i.id DESC LIMIT {$limit}";
                break;
            case 'purchase_invoice_void':
                $sql = "SELECT p.id AS source_id, 0 AS ledger_id FROM purchase_invoices p WHERE DATE(COALESCE(p.inv_date, CURDATE())) BETWEEN '{$from}' AND '{$to}' AND LOWER(TRIM(COALESCE(p.status,'')))='cancelled' AND NOT EXISTS (SELECT 1 FROM finance_ledger_entries l WHERE l.source_type='purchase_invoice' AND l.source_id=p.id AND l.entry_type='purchase_invoice_void') ORDER BY p.id DESC LIMIT {$limit}";
                break;
            case 'sales_return_issue':
                $sql = "SELECT r.id AS source_id, 0 AS ledger_id FROM invoice_returns r WHERE DATE(COALESCE(r.return_date, CURDATE())) BETWEEN '{$from}' AND '{$to}' AND IFNULL(r.is_void,0)=0 AND LOWER(TRIM(IFNULL(r.status,'posted'))) NOT IN ('void','cancelled') AND NOT EXISTS (SELECT 1 FROM finance_ledger_entries l WHERE l.source_type='sales_return' AND l.source_id=r.id AND l.entry_type='sales_return_credit') ORDER BY r.id DESC LIMIT {$limit}";
                break;
            case 'purchase_return_issue':
                $sql = "SELECT r.id AS source_id, 0 AS ledger_id FROM purchase_invoice_returns r WHERE DATE(COALESCE(r.return_date, CURDATE())) BETWEEN '{$from}' AND '{$to}' AND LOWER(TRIM(IFNULL(r.status,'posted'))) NOT IN ('void','cancelled') AND NOT EXISTS (SELECT 1 FROM finance_ledger_entries l WHERE l.source_type='purchase_return' AND l.source_id=r.id AND l.entry_type='purchase_return_credit') ORDER BY r.id DESC LIMIT {$limit}";
                break;
            case 'sales_return_void':
                $sql = "SELECT r.id AS source_id, 0 AS ledger_id FROM invoice_returns r WHERE DATE(COALESCE(r.return_date, CURDATE())) BETWEEN '{$from}' AND '{$to}' AND (IFNULL(r.is_void,0)=1 OR LOWER(TRIM(IFNULL(r.status,'posted'))) IN ('void','cancelled')) AND NOT EXISTS (SELECT 1 FROM finance_ledger_entries l WHERE l.source_type='sales_return' AND l.source_id=r.id AND l.entry_type='sales_return_void') ORDER BY r.id DESC LIMIT {$limit}";
                break;
            case 'purchase_return_void':
                $sql = "SELECT r.id AS source_id, 0 AS ledger_id FROM purchase_invoice_returns r WHERE DATE(COALESCE(r.return_date, CURDATE())) BETWEEN '{$from}' AND '{$to}' AND LOWER(TRIM(IFNULL(r.status,'posted'))) IN ('void','cancelled') AND NOT EXISTS (SELECT 1 FROM finance_ledger_entries l WHERE l.source_type='purchase_return' AND l.source_id=r.id AND l.entry_type='purchase_return_void') ORDER BY r.id DESC LIMIT {$limit}";
                break;
        }
        if ($sql === '') {
            return ['ok' => false, 'message' => app_tr('تعذر تجهيز قائمة المعالجة الجماعية.', 'Could not prepare bulk remediation list.')];
        }

        $previewBefore = financePreviewPostingAuditSectionBulk($conn, $sectionKey, $dateFrom, $dateTo, $limit);
        $runId = financeCreatePostingAuditRun(
            $conn,
            $sectionKey,
            $dateFrom,
            $dateTo,
            (int)($previewBefore['total_count'] ?? 0),
            $limit,
            $actor,
            app_tr('تشغيل معالجة جماعية آمنة من شاشة تدقيق الترحيل.', 'Safe bulk remediation run from posting audit screen.')
        );

        $rows = [];
        if ($res = $conn->query($sql)) {
            while ($row = $res->fetch_assoc()) {
                $rows[] = $row;
            }
        }
        $repaired = 0;
        $failed = 0;
        foreach ($rows as $row) {
            $sourceId = (int)($row['source_id'] ?? 0);
            $ledgerId = (int)($row['ledger_id'] ?? 0);
            $result = financeRepairPostingAuditIssue($conn, $sectionKey, $sourceId, $ledgerId, $actor);
            if (!empty($result['ok'])) {
                $repaired++;
                financeAppendPostingAuditRunItem($conn, $runId, $sectionKey, $sourceId, $ledgerId, 'repaired', '');
            } else {
                $failed++;
                financeAppendPostingAuditRunItem(
                    $conn,
                    $runId,
                    $sectionKey,
                    $sourceId,
                    $ledgerId,
                    'failed',
                    (string)($result['message'] ?? app_tr('فشل غير موضح.', 'Unspecified failure.'))
                );
            }
        }

        $previewAfter = financePreviewPostingAuditSectionBulk($conn, $sectionKey, $dateFrom, $dateTo, $limit);
        $remainingCount = (int)($previewAfter['total_count'] ?? 0);
        financeFinalizePostingAuditRun($conn, $runId, count($rows), $repaired, $failed, $remainingCount);

        if (function_exists('app_audit_log_add')) {
            app_audit_log_add($conn, 'finance.posting_audit_bulk_repaired', [
                'entity_type' => 'posting_audit_bulk',
                'entity_key' => $sectionKey,
                'details' => [
                    'section_key' => $sectionKey,
                    'date_from' => $dateFrom,
                    'date_to' => $dateTo,
                    'repaired_count' => $repaired,
                    'failed_count' => $failed,
                    'remaining_count' => $remainingCount,
                    'scanned_count' => count($rows),
                    'run_id' => $runId,
                    'actor' => $actor,
                ],
            ]);
        }

        return [
            'ok' => true,
            'run_id' => $runId,
            'repaired_count' => $repaired,
            'failed_count' => $failed,
            'remaining_count' => $remainingCount,
            'scanned_count' => count($rows),
        ];
    }
}

if (!function_exists('financeSyncReceiptLedger')) {
    function financeSyncReceiptLedger(mysqli $conn, int $receiptId, string $actor = ''): void
    {
        financeEnsureLedgerSchema($conn);
        financeEnsureAllocationSchema($conn);
        $receiptId = (int)$receiptId;
        if ($receiptId <= 0) {
            return;
        }

        $stmt = $conn->prepare("
            SELECT id, type, category, amount, description, trans_date, client_id, invoice_id, supplier_id, employee_id, payroll_id
            FROM financial_receipts
            WHERE id = ?
            LIMIT 1
        ");
        $stmt->bind_param('i', $receiptId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();

        if (!$row) {
            financeLedgerDeleteBySource($conn, 'financial_receipt', $receiptId);
            financeLedgerDeleteBySource($conn, 'financial_receipt_allocation', $receiptId);
            return;
        }

        $amount = round((float)($row['amount'] ?? 0), 2);
        $transDate = (string)($row['trans_date'] ?? date('Y-m-d'));
        $category = strtolower(trim((string)($row['category'] ?? 'general')));
        $type = strtolower(trim((string)($row['type'] ?? 'in')));
        $entryType = $type === 'in' ? 'receipt_cash_in' : 'receipt_cash_out';
        if ($type === 'out' && $category === 'salary') {
            $entryType = 'salary_payment';
        } elseif ($type === 'out' && $category === 'loan') {
            $entryType = 'employee_loan_issued';
        } elseif ($type === 'in' && in_array($category, ['loan', 'loan_repayment'], true)) {
            $entryType = 'employee_loan_repayment_cash';
        }
        $counterpartyType = null;
        $counterpartyId = null;
        if ((int)($row['client_id'] ?? 0) > 0) {
            $counterpartyType = 'client';
            $counterpartyId = (int)$row['client_id'];
        } elseif ((int)($row['supplier_id'] ?? 0) > 0) {
            $counterpartyType = 'supplier';
            $counterpartyId = (int)$row['supplier_id'];
        } elseif ((int)($row['employee_id'] ?? 0) > 0) {
            $counterpartyType = 'employee';
            $counterpartyId = (int)$row['employee_id'];
        }

        $postingRule = 'financial_receipt_posted';
        if ($category === 'tax') {
            $postingRule = 'tax_settlement_posted';
        } elseif (in_array($category, ['general', 'manual'], true)) {
            $postingRule = 'manual_settlement_posted';
        }

        financeLedgerPostByRule(
            $conn,
            $postingRule,
            $receiptId,
            [
                'entry_type' => $entryType,
                'trans_date' => $transDate,
                'amount' => $amount,
                'counterparty_type' => $counterpartyType,
                'counterparty_id' => $counterpartyId,
                'reference' => '#' . $receiptId,
                'notes' => (string)($row['description'] ?? ''),
            ],
            [
                'category' => $category,
                'invoice_id' => (int)($row['invoice_id'] ?? 0),
                'payroll_id' => (int)($row['payroll_id'] ?? 0),
                'tax_law_key' => (string)($row['tax_law_key'] ?? ''),
            ],
            $actor
        );

        if (financeReceiptIsOpeningBalance($conn, $row)) {
            $openingEntryType = ((string)($row['type'] ?? 'in') === 'in') ? 'opening_balance_receipt' : 'opening_balance_payment';
            financeLedgerPostByRule(
                $conn,
                'financial_receipt_opening_balance',
                $receiptId,
                [
                    'entry_type' => $openingEntryType,
                    'trans_date' => $transDate,
                    'amount' => $amount,
                    'counterparty_type' => $counterpartyType,
                    'counterparty_id' => $counterpartyId,
                    'reference' => '#' . $receiptId,
                    'notes' => (string)($row['description'] ?? ''),
                ],
                [],
                $actor
            );
        } else {
            financeLedgerDeleteBySource($conn, 'financial_receipt', $receiptId, 'opening_balance');
        }

        financeLedgerDeleteBySource($conn, 'financial_receipt_allocation', $receiptId);
        $allocStmt = $conn->prepare("
            SELECT allocation_type, target_id, amount, notes
            FROM financial_receipt_allocations
            WHERE receipt_id = ?
            ORDER BY id ASC
        ");
        $allocStmt->bind_param('i', $receiptId);
        $allocStmt->execute();
        $allocRes = $allocStmt->get_result();
        $idx = 0;
        while ($alloc = $allocRes->fetch_assoc()) {
            $idx++;
            $allocationType = strtolower(trim((string)($alloc['allocation_type'] ?? '')));
            $targetId = (int)($alloc['target_id'] ?? 0);
            $allocAmount = round((float)($alloc['amount'] ?? 0), 2);
            if ($allocAmount <= 0.00001) {
                continue;
            }
            $eventKey = 'allocation_' . $idx;
            financeLedgerPostByRule(
                $conn,
                'financial_receipt_allocation_posted',
                $receiptId,
                [
                    'event_key' => $eventKey,
                    'allocation_type' => $allocationType,
                    'trans_date' => $transDate,
                    'amount' => $allocAmount,
                    'counterparty_type' => $counterpartyType,
                    'counterparty_id' => $counterpartyId,
                    'reference' => '#' . $receiptId,
                    'notes' => (string)($alloc['notes'] ?? ''),
                ],
                [
                    'allocation_type' => $allocationType,
                    'target_id' => $targetId,
                    'receipt_id' => $receiptId,
                ],
                $actor
            );
        }
        $allocStmt->close();
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

if (!function_exists('financeEntityDeleteLinkSummary')) {
    function financeEntityDeleteLinkSummary(mysqli $conn, string $entityType, int $entityId): array
    {
        $entityType = strtolower(trim($entityType));
        $entityId = (int)$entityId;
        if ($entityId <= 0 || !in_array($entityType, ['client', 'supplier'], true)) {
            return ['total' => 0, 'details' => []];
        }

        $definitions = [];
        if ($entityType === 'client') {
            $definitions = [
                'invoices' => ["SELECT COUNT(*) FROM invoices WHERE client_id = ?", 'فواتير المبيعات'],
                'receipts' => ["SELECT COUNT(*) FROM financial_receipts WHERE client_id = ?", 'سندات القبض'],
                'quotes' => ["SELECT COUNT(*) FROM quotes WHERE client_id = ?", 'عروض الأسعار'],
                'jobs' => ["SELECT COUNT(*) FROM job_orders WHERE client_id = ?", 'العمليات'],
            ];
        } else {
            $definitions = [
                'purchase_invoices' => ["SELECT COUNT(*) FROM purchase_invoices WHERE supplier_id = ?", 'فواتير المشتريات'],
                'payments' => ["SELECT COUNT(*) FROM financial_receipts WHERE supplier_id = ?", 'سندات الصرف'],
            ];
        }

        $details = [];
        $total = 0;
        foreach ($definitions as $key => [$sql, $label]) {
            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                continue;
            }
            $stmt->bind_param('i', $entityId);
            $stmt->execute();
            $count = (int)($stmt->get_result()->fetch_row()[0] ?? 0);
            $stmt->close();
            if ($count <= 0) {
                continue;
            }
            $details[$key] = [
                'count' => $count,
                'label' => $label,
            ];
            $total += $count;
        }

        return ['total' => $total, 'details' => $details];
    }
}

if (!function_exists('financeEntityDeleteBlockedMessage')) {
    function financeEntityDeleteBlockedMessage(string $entityType, array $summary): string
    {
        $entityType = strtolower(trim($entityType));
        $nounAr = $entityType === 'supplier' ? 'المورد' : 'العميل';
        $parts = [];
        foreach ((array)($summary['details'] ?? []) as $row) {
            $label = (string)($row['label'] ?? '');
            $count = (int)($row['count'] ?? 0);
            if ($label === '' || $count <= 0) {
                continue;
            }
            $parts[] = $label . ' (' . $count . ')';
        }
        $suffix = !empty($parts) ? ' ' . implode('، ', $parts) : '';
        return 'لا يمكن حذف ' . $nounAr . ' لأنه مرتبط ببيانات مسجلة:' . $suffix;
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

if (!function_exists('financeEnsureLedgerSchema')) {
    function financeEnsureLedgerSchema(mysqli $conn): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;

        try {
            $conn->query("
                CREATE TABLE IF NOT EXISTS finance_ledger_entries (
                    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    event_key VARCHAR(80) NOT NULL,
                    source_type VARCHAR(80) NOT NULL,
                    source_id BIGINT UNSIGNED NOT NULL,
                    entry_type VARCHAR(80) NOT NULL,
                    counterparty_type VARCHAR(40) DEFAULT NULL,
                    counterparty_id BIGINT UNSIGNED DEFAULT NULL,
                    trans_date DATE NOT NULL,
                    amount DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                    currency VARCHAR(10) NOT NULL DEFAULT 'EGP',
                    reference VARCHAR(255) DEFAULT NULL,
                    notes TEXT DEFAULT NULL,
                    payload_json LONGTEXT DEFAULT NULL,
                    created_by_name VARCHAR(190) NOT NULL DEFAULT '',
                    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    UNIQUE KEY uniq_finance_ledger_source_event (source_type, source_id, event_key),
                    KEY idx_finance_ledger_date (trans_date),
                    KEY idx_finance_ledger_counterparty (counterparty_type, counterparty_id),
                    KEY idx_finance_ledger_entry_type (entry_type)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        } catch (Throwable $e) {
            error_log('finance ledger schema bootstrap failed: ' . $e->getMessage());
        }
    }
}

if (!function_exists('financeLedgerDeleteBySource')) {
    function financeLedgerDeleteBySource(mysqli $conn, string $sourceType, int $sourceId, ?string $eventKey = null): void
    {
        financeEnsureLedgerSchema($conn);
        $sourceType = trim($sourceType);
        $sourceId = (int)$sourceId;
        if ($sourceType === '' || $sourceId <= 0) {
            return;
        }
        if ($eventKey !== null && trim($eventKey) !== '') {
            $eventKey = trim($eventKey);
            $stmt = $conn->prepare("DELETE FROM finance_ledger_entries WHERE source_type = ? AND source_id = ? AND event_key = ?");
            $stmt->bind_param('sis', $sourceType, $sourceId, $eventKey);
        } else {
            $stmt = $conn->prepare("DELETE FROM finance_ledger_entries WHERE source_type = ? AND source_id = ?");
            $stmt->bind_param('si', $sourceType, $sourceId);
        }
        $stmt->execute();
        $stmt->close();
    }
}

if (!function_exists('financeLedgerUpsert')) {
    function financeLedgerUpsert(
        mysqli $conn,
        string $eventKey,
        string $sourceType,
        int $sourceId,
        string $entryType,
        string $transDate,
        float $amount,
        ?string $counterpartyType = null,
        ?int $counterpartyId = null,
        string $reference = '',
        string $notes = '',
        array $payload = [],
        string $actor = ''
    ): void {
        financeEnsureLedgerSchema($conn);
        $eventKey = trim($eventKey);
        $sourceType = trim($sourceType);
        $entryType = trim($entryType);
        $transDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', $transDate) ? $transDate : date('Y-m-d');
        $amount = round(max(0.0, $amount), 2);
        $counterpartyType = $counterpartyType !== null ? trim($counterpartyType) : null;
        $counterpartyId = $counterpartyId !== null ? (int)$counterpartyId : null;
        $reference = trim($reference);
        $notes = trim($notes);
        $payloadJson = !empty($payload) ? json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
        $actor = mb_substr(trim($actor) !== '' ? trim($actor) : 'System', 0, 190);

        if ($eventKey === '' || $sourceType === '' || $sourceId <= 0 || $entryType === '' || $amount <= 0.00001) {
            return;
        }

        $stmt = $conn->prepare("
            INSERT INTO finance_ledger_entries (
                event_key, source_type, source_id, entry_type, counterparty_type, counterparty_id,
                trans_date, amount, currency, reference, notes, payload_json, created_by_name
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'EGP', ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                entry_type = VALUES(entry_type),
                counterparty_type = VALUES(counterparty_type),
                counterparty_id = VALUES(counterparty_id),
                trans_date = VALUES(trans_date),
                amount = VALUES(amount),
                reference = VALUES(reference),
                notes = VALUES(notes),
                payload_json = VALUES(payload_json),
                created_by_name = VALUES(created_by_name)
        ");
        $stmt->bind_param(
            'ssissisdssss',
            $eventKey,
            $sourceType,
            $sourceId,
            $entryType,
            $counterpartyType,
            $counterpartyId,
            $transDate,
            $amount,
            $reference,
            $notes,
            $payloadJson,
            $actor
        );
        $stmt->execute();
        $stmt->close();
    }
}

if (!function_exists('financeVoidSalesReturn')) {
    function financeVoidSalesReturn(mysqli $conn, int $salesReturnId, string $actor = ''): array
    {
        financeEnsureSalesReturnSchema($conn);
        $salesReturnId = (int)$salesReturnId;
        if ($salesReturnId <= 0) {
            return ['ok' => false, 'message' => app_tr('مردود المبيعات غير صالح.', 'Invalid sales return.')];
        }

        $stmt = $conn->prepare("
            SELECT id, return_number, return_date, total_amount, IFNULL(refund_amount,0) AS refund_amount, IFNULL(refund_receipt_id,0) AS refund_receipt_id,
                   IFNULL(is_void,0) AS is_void, IFNULL(status,'posted') AS status, invoice_id
            FROM invoice_returns
            WHERE id = ?
            LIMIT 1
        ");
        $stmt->bind_param('i', $salesReturnId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) {
            return ['ok' => false, 'message' => app_tr('مردود المبيعات غير موجود.', 'Sales return not found.')];
        }
        $policy = financeEvaluateVoidPolicy([
            'entity_ar' => 'مردود المبيعات',
            'entity_en' => 'Sales return',
            'operation' => 'void',
            'status' => (string)($row['status'] ?? 'posted'),
            'is_void' => (int)($row['is_void'] ?? 0) === 1,
        ]);
        if (empty($policy['ok'])) {
            return ['ok' => false, 'message' => (string)($policy['message'] ?? app_tr('تعذر إلغاء مردود المبيعات.', 'Could not void sales return.'))];
        }

        $refundAmount = round((float)($row['refund_amount'] ?? 0), 2);
        if ($refundAmount > 0.00001) {
            $sync = finance_sync_sales_return_refund_receipt($conn, $salesReturnId, 0, $actor);
            if (empty($sync['ok'])) {
                return $sync;
            }
        }

        $upd = $conn->prepare("UPDATE invoice_returns SET status = 'void', is_void = 1, refund_amount = 0, refund_receipt_id = NULL WHERE id = ?");
        $upd->bind_param('i', $salesReturnId);
        $upd->execute();
        $upd->close();

        financeLedgerDeleteBySource($conn, 'sales_return', $salesReturnId);
        financeLedgerPostByRule(
            $conn,
            'sales_return_void',
            $salesReturnId,
            [
                'ref' => (string)($row['return_number'] ?? ''),
                'doc_date' => (string)($row['return_date'] ?? date('Y-m-d')),
                'total_amount' => max(0.0, round((float)($row['total_amount'] ?? 0), 2)),
            ],
            ['invoice_id' => (int)($row['invoice_id'] ?? 0)],
            $actor
        );
        financeRegisterVoidAudit($conn, 'sales.return_voided', 'sales_return', $salesReturnId, 'void', $actor, [
            'invoice_id' => (int)($row['invoice_id'] ?? 0),
            'ref' => (string)($row['return_number'] ?? ''),
        ]);

        return ['ok' => true];
    }
}

if (!function_exists('financeVoidPurchaseReturn')) {
    function financeVoidPurchaseReturn(mysqli $conn, int $purchaseReturnId, string $actor = ''): array
    {
        financeEnsurePurchaseReturnFinanceSchema($conn);
        $purchaseReturnId = (int)$purchaseReturnId;
        if ($purchaseReturnId <= 0) {
            return ['ok' => false, 'message' => app_tr('مردود الشراء غير صالح.', 'Invalid purchase return.')];
        }

        $stmt = $conn->prepare("
            SELECT id, return_number, return_date, total_amount, IFNULL(refund_amount,0) AS refund_amount, IFNULL(refund_receipt_id,0) AS refund_receipt_id,
                   IFNULL(status,'posted') AS status, purchase_invoice_id
            FROM purchase_invoice_returns
            WHERE id = ?
            LIMIT 1
        ");
        $stmt->bind_param('i', $purchaseReturnId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) {
            return ['ok' => false, 'message' => app_tr('مردود الشراء غير موجود.', 'Purchase return not found.')];
        }
        $policy = financeEvaluateVoidPolicy([
            'entity_ar' => 'مردود الشراء',
            'entity_en' => 'Purchase return',
            'operation' => 'void',
            'status' => (string)($row['status'] ?? 'posted'),
        ]);
        if (empty($policy['ok'])) {
            return ['ok' => false, 'message' => (string)($policy['message'] ?? app_tr('تعذر إلغاء مردود الشراء.', 'Could not void purchase return.'))];
        }

        $refundAmount = round((float)($row['refund_amount'] ?? 0), 2);
        if ($refundAmount > 0.00001) {
            $sync = finance_sync_purchase_return_refund_receipt($conn, $purchaseReturnId, 0, $actor);
            if (empty($sync['ok'])) {
                return $sync;
            }
        }

        $upd = $conn->prepare("UPDATE purchase_invoice_returns SET status = 'void', refund_amount = 0, refund_receipt_id = NULL WHERE id = ?");
        $upd->bind_param('i', $purchaseReturnId);
        $upd->execute();
        $upd->close();

        financeLedgerDeleteBySource($conn, 'purchase_return', $purchaseReturnId);
        financeLedgerPostByRule(
            $conn,
            'purchase_return_void',
            $purchaseReturnId,
            [
                'ref' => (string)($row['return_number'] ?? ''),
                'doc_date' => (string)($row['return_date'] ?? date('Y-m-d')),
                'total_amount' => max(0.0, round((float)($row['total_amount'] ?? 0), 2)),
            ],
            ['purchase_invoice_id' => (int)($row['purchase_invoice_id'] ?? 0)],
            $actor
        );
        financeRegisterVoidAudit($conn, 'purchase.return_voided', 'purchase_return', $purchaseReturnId, 'void', $actor, [
            'purchase_invoice_id' => (int)($row['purchase_invoice_id'] ?? 0),
            'ref' => (string)($row['return_number'] ?? ''),
        ]);

        return ['ok' => true];
    }
}

if (!function_exists('financeEnsureSalesReturnSchema')) {
    function financeEnsureSalesReturnSchema(mysqli $conn): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;

        try {
            $conn->query("
                CREATE TABLE IF NOT EXISTS invoice_returns (
                    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    invoice_id BIGINT UNSIGNED NOT NULL,
                    return_number VARCHAR(255) NOT NULL,
                    return_date DATE NOT NULL,
                    subtotal_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                    tax_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                    discount_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                    total_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                    refund_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                    status VARCHAR(40) NOT NULL DEFAULT 'posted',
                    is_void TINYINT(1) NOT NULL DEFAULT 0,
                    notes TEXT DEFAULT NULL,
                    created_by_user_id INT DEFAULT NULL,
                    created_by_name VARCHAR(190) NOT NULL DEFAULT '',
                    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    KEY idx_invoice_returns_invoice (invoice_id),
                    KEY idx_invoice_returns_date (return_date)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            $conn->query("
                CREATE TABLE IF NOT EXISTS invoice_return_items (
                    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    invoice_return_id BIGINT UNSIGNED NOT NULL,
                    invoice_item_id BIGINT UNSIGNED NOT NULL,
                    line_number INT UNSIGNED NOT NULL DEFAULT 1,
                    quantity DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                    unit_label VARCHAR(50) NOT NULL DEFAULT 'وحدة',
                    unit_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                    line_total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    KEY idx_invoice_return_items_return (invoice_return_id),
                    KEY idx_invoice_return_items_item (invoice_item_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            if (function_exists('app_table_has_column')) {
                if (!app_table_has_column($conn, 'invoice_returns', 'tax_profile_json')) {
                    $conn->query("ALTER TABLE invoice_returns ADD COLUMN tax_profile_json LONGTEXT DEFAULT NULL AFTER tax_amount");
                }
                if (!app_table_has_column($conn, 'invoice_returns', 'refund_amount')) {
                    $conn->query("ALTER TABLE invoice_returns ADD COLUMN refund_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER total_amount");
                }
                if (!app_table_has_column($conn, 'invoice_returns', 'refund_receipt_id')) {
                    $conn->query("ALTER TABLE invoice_returns ADD COLUMN refund_receipt_id BIGINT UNSIGNED DEFAULT NULL AFTER refund_amount");
                }
                if (!app_table_has_column($conn, 'invoice_returns', 'status')) {
                    $conn->query("ALTER TABLE invoice_returns ADD COLUMN status VARCHAR(40) NOT NULL DEFAULT 'posted' AFTER refund_receipt_id");
                }
                if (!app_table_has_column($conn, 'invoice_returns', 'items_json')) {
                    $conn->query("ALTER TABLE invoice_returns ADD COLUMN items_json LONGTEXT DEFAULT NULL AFTER status");
                }
                if (!app_table_has_column($conn, 'invoice_returns', 'created_by_user_id')) {
                    $conn->query("ALTER TABLE invoice_returns ADD COLUMN created_by_user_id INT DEFAULT NULL AFTER notes");
                }
                if (!app_table_has_column($conn, 'invoice_returns', 'created_by_name')) {
                    $conn->query("ALTER TABLE invoice_returns ADD COLUMN created_by_name VARCHAR(190) NOT NULL DEFAULT '' AFTER created_by_user_id");
                }
                if (function_exists('app_table_has_column_reset')) {
                    app_table_has_column_reset('invoice_returns', 'tax_profile_json');
                    app_table_has_column_reset('invoice_returns', 'refund_amount');
                    app_table_has_column_reset('invoice_returns', 'refund_receipt_id');
                    app_table_has_column_reset('invoice_returns', 'status');
                    app_table_has_column_reset('invoice_returns', 'items_json');
                    app_table_has_column_reset('invoice_returns', 'created_by_user_id');
                    app_table_has_column_reset('invoice_returns', 'created_by_name');
                }
            }
        } catch (Throwable $e) {
            error_log('finance sales return schema bootstrap failed: ' . $e->getMessage());
        }
    }
}

if (!function_exists('financeClientSalesReturnCreditTotal')) {
    function financeClientSalesReturnCreditTotal(mysqli $conn, int $clientId, ?string $asOfDate = null): float
    {
        financeEnsureSalesReturnSchema($conn);
        $clientId = (int)$clientId;
        if ($clientId <= 0) {
            return 0.0;
        }

        $hasAsOf = $asOfDate !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $asOfDate);
        if ($hasAsOf) {
            $stmt = $conn->prepare("
                SELECT IFNULL(SUM(GREATEST(r.total_amount - IFNULL(r.refund_amount, 0), 0)), 0)
                FROM invoice_returns r
                INNER JOIN invoices i ON i.id = r.invoice_id
                WHERE i.client_id = ?
                  AND IFNULL(r.is_void, 0) = 0
                  AND LOWER(TRIM(IFNULL(r.status, 'posted'))) NOT IN ('void', 'cancelled')
                  AND r.return_date <= ?
            ");
            $stmt->bind_param('is', $clientId, $asOfDate);
        } else {
            $stmt = $conn->prepare("
                SELECT IFNULL(SUM(GREATEST(r.total_amount - IFNULL(r.refund_amount, 0), 0)), 0)
                FROM invoice_returns r
                INNER JOIN invoices i ON i.id = r.invoice_id
                WHERE i.client_id = ?
                  AND IFNULL(r.is_void, 0) = 0
                  AND LOWER(TRIM(IFNULL(r.status, 'posted'))) NOT IN ('void', 'cancelled')
            ");
            $stmt->bind_param('i', $clientId);
        }
        $stmt->execute();
        $amount = (float)($stmt->get_result()->fetch_row()[0] ?? 0);
        $stmt->close();
        return round($amount, 2);
    }
}

if (!function_exists('finance_sync_sales_return_refund_receipt')) {
    function finance_sync_sales_return_refund_receipt(mysqli $conn, int $salesReturnId, float $refundAmount, string $sessionUser = ''): array
    {
        financeEnsureAllocationSchema($conn);
        financeEnsureSalesReturnSchema($conn);
        $salesReturnId = (int)$salesReturnId;
        $refundAmount = round(max(0.0, $refundAmount), 2);
        if ($salesReturnId <= 0) {
            return ['ok' => false, 'message' => app_tr('مردود المبيعات غير صالح.', 'Invalid sales return.')];
        }

        if (function_exists('app_table_has_column') && !app_table_has_column($conn, 'invoice_returns', 'refund_receipt_id')) {
            $conn->query("ALTER TABLE invoice_returns ADD COLUMN refund_receipt_id BIGINT UNSIGNED DEFAULT NULL AFTER refund_amount");
            if (function_exists('app_table_has_column_reset')) {
                app_table_has_column_reset('invoice_returns', 'refund_receipt_id');
            }
        }

        $stmt = $conn->prepare("
            SELECT r.id, r.invoice_id, r.return_number, r.return_date, r.total_amount, IFNULL(r.refund_amount, 0) AS refund_amount,
                   IFNULL(r.refund_receipt_id, 0) AS refund_receipt_id,
                   i.client_id, i.invoice_number
            FROM invoice_returns r
            INNER JOIN invoices i ON i.id = r.invoice_id
            WHERE r.id = ?
            LIMIT 1
        ");
        $stmt->bind_param('i', $salesReturnId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) {
            return ['ok' => false, 'message' => app_tr('مردود المبيعات غير موجود.', 'Sales return not found.')];
        }

        $clientId = (int)($row['client_id'] ?? 0);
        $refundReceiptId = (int)($row['refund_receipt_id'] ?? 0);
        $returnDate = (string)($row['return_date'] ?? date('Y-m-d'));
        $maxRefund = round((float)($row['total_amount'] ?? 0), 2);
        $refundAmount = round(min($refundAmount, max(0.0, $maxRefund)), 2);
        $returnNumber = trim((string)($row['return_number'] ?? ''));
        if ($returnNumber === '') {
            $returnNumber = 'SRTN-' . str_pad((string)$salesReturnId, 5, '0', STR_PAD_LEFT);
        }
        $invoiceNumber = trim((string)($row['invoice_number'] ?? ''));
        $desc = app_tr('رد مبلغ مردود مبيعات ', 'Sales return refund ') . $returnNumber;
        if ($invoiceNumber !== '') {
            $desc .= ' / ' . $invoiceNumber;
        }
        $actor = mb_substr(trim($sessionUser) !== '' ? trim($sessionUser) : 'System', 0, 100);

        if ($refundAmount <= 0.00001) {
            if ($refundReceiptId > 0) {
                financeDeleteReceiptAllocations($conn, $refundReceiptId);
                $delStmt = $conn->prepare("DELETE FROM financial_receipts WHERE id = ?");
                $delStmt->bind_param('i', $refundReceiptId);
                $delStmt->execute();
                $delStmt->close();
                $updStmt = $conn->prepare("UPDATE invoice_returns SET refund_amount = 0, refund_receipt_id = NULL WHERE id = ?");
                $updStmt->bind_param('i', $salesReturnId);
                $updStmt->execute();
                $updStmt->close();
            }
            financeLedgerDeleteBySource($conn, 'sales_return_refund', $salesReturnId, 'refund_posted');
            return ['ok' => true, 'refund_receipt_id' => 0];
        }

        if ($refundReceiptId > 0) {
            $updReceipt = $conn->prepare("
                UPDATE financial_receipts
                SET type = 'out',
                    category = 'client_refund',
                    amount = ?,
                    description = ?,
                    trans_date = ?,
                    client_id = ?,
                    invoice_id = NULL,
                    supplier_id = NULL,
                    employee_id = NULL,
                    payroll_id = NULL,
                    created_by = ?
                WHERE id = ?
            ");
            $updReceipt->bind_param('dssisi', $refundAmount, $desc, $returnDate, $clientId, $actor, $refundReceiptId);
            $updReceipt->execute();
            $updReceipt->close();
        } else {
            $insReceipt = $conn->prepare("
                INSERT INTO financial_receipts (
                    type, category, amount, description, trans_date, client_id, invoice_id, supplier_id, employee_id, payroll_id, created_by
                ) VALUES ('out', 'client_refund', ?, ?, ?, ?, NULL, NULL, NULL, NULL, ?)
            ");
            $insReceipt->bind_param('dssis', $refundAmount, $desc, $returnDate, $clientId, $actor);
            $insReceipt->execute();
            $refundReceiptId = (int)$insReceipt->insert_id;
            $insReceipt->close();
        }

        $updReturn = $conn->prepare("UPDATE invoice_returns SET refund_amount = ?, refund_receipt_id = ? WHERE id = ?");
        $updReturn->bind_param('dii', $refundAmount, $refundReceiptId, $salesReturnId);
        $updReturn->execute();
        $updReturn->close();

        financeLedgerPostByRule(
            $conn,
            'sales_return_refund',
            $salesReturnId,
            [
                'client_id' => $clientId,
                'ref' => $returnNumber,
                'doc_date' => $returnDate,
                'refund_amount' => $refundAmount,
                'notes' => $desc,
            ],
            [
                'receipt_id' => $refundReceiptId,
                'invoice_id' => (int)($row['invoice_id'] ?? 0),
                'refund_amount' => $refundAmount,
            ],
            $actor
        );

        return ['ok' => true, 'refund_receipt_id' => $refundReceiptId];
    }
}

if (!function_exists('financeEnsurePurchaseReturnFinanceSchema')) {
    function financeEnsurePurchaseReturnFinanceSchema(mysqli $conn): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;

        if (function_exists('app_ensure_purchase_returns_schema')) {
            app_ensure_purchase_returns_schema($conn);
        }

        try {
            if (function_exists('app_table_has_column')) {
                if (!app_table_has_column($conn, 'purchase_invoice_returns', 'refund_amount')) {
                    $conn->query("ALTER TABLE purchase_invoice_returns ADD COLUMN refund_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER total_amount");
                }
                if (!app_table_has_column($conn, 'purchase_invoice_returns', 'status')) {
                    $conn->query("ALTER TABLE purchase_invoice_returns ADD COLUMN status VARCHAR(40) NOT NULL DEFAULT 'posted' AFTER refund_amount");
                }
                if (!app_table_has_column($conn, 'purchase_invoice_returns', 'refund_receipt_id')) {
                    $conn->query("ALTER TABLE purchase_invoice_returns ADD COLUMN refund_receipt_id BIGINT UNSIGNED DEFAULT NULL AFTER refund_amount");
                }
            }
        } catch (Throwable $e) {
            error_log('finance purchase return schema bootstrap failed: ' . $e->getMessage());
        }
    }
}

if (!function_exists('finance_sync_purchase_return_refund_receipt')) {
    function finance_sync_purchase_return_refund_receipt(mysqli $conn, int $purchaseReturnId, float $refundAmount, string $sessionUser = ''): array
    {
        financeEnsureAllocationSchema($conn);
        financeEnsurePurchaseReturnFinanceSchema($conn);
        $purchaseReturnId = (int)$purchaseReturnId;
        $refundAmount = round(max(0.0, $refundAmount), 2);
        if ($purchaseReturnId <= 0) {
            return ['ok' => false, 'message' => app_tr('مردود الشراء غير صالح.', 'Invalid purchase return.')];
        }

        $stmt = $conn->prepare("
            SELECT r.id, r.purchase_invoice_id, r.return_number, r.return_date, r.total_amount, IFNULL(r.refund_amount, 0) AS refund_amount,
                   IFNULL(r.refund_receipt_id, 0) AS refund_receipt_id,
                   i.supplier_id, i.purchase_number, i.tax_law_key
            FROM purchase_invoice_returns r
            INNER JOIN purchase_invoices i ON i.id = r.purchase_invoice_id
            WHERE r.id = ?
            LIMIT 1
        ");
        $stmt->bind_param('i', $purchaseReturnId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) {
            return ['ok' => false, 'message' => app_tr('مردود الشراء غير موجود.', 'Purchase return not found.')];
        }

        $supplierId = (int)($row['supplier_id'] ?? 0);
        $refundReceiptId = (int)($row['refund_receipt_id'] ?? 0);
        $returnDate = (string)($row['return_date'] ?? date('Y-m-d'));
        $maxRefund = round((float)($row['total_amount'] ?? 0), 2);
        $refundAmount = round(min($refundAmount, max(0.0, $maxRefund)), 2);
        $returnNumber = trim((string)($row['return_number'] ?? ''));
        if ($returnNumber === '') {
            $returnNumber = 'PRTN-' . str_pad((string)$purchaseReturnId, 5, '0', STR_PAD_LEFT);
        }
        $purchaseNumber = trim((string)($row['purchase_number'] ?? ''));
        $taxLawKey = trim((string)($row['tax_law_key'] ?? ''));
        $desc = app_tr('رد مبلغ مردود شراء ', 'Purchase return refund ') . $returnNumber;
        if ($purchaseNumber !== '') {
            $desc .= ' / ' . $purchaseNumber;
        }
        $actor = mb_substr(trim($sessionUser) !== '' ? trim($sessionUser) : 'System', 0, 100);

        if ($refundAmount <= 0.00001) {
            if ($refundReceiptId > 0) {
                financeDeleteReceiptAllocations($conn, $refundReceiptId);
                $delStmt = $conn->prepare("DELETE FROM financial_receipts WHERE id = ?");
                $delStmt->bind_param('i', $refundReceiptId);
                $delStmt->execute();
                $delStmt->close();
                $updStmt = $conn->prepare("UPDATE purchase_invoice_returns SET refund_amount = 0, refund_receipt_id = NULL WHERE id = ?");
                $updStmt->bind_param('i', $purchaseReturnId);
                $updStmt->execute();
                $updStmt->close();
            }
            financeLedgerDeleteBySource($conn, 'purchase_return_refund', $purchaseReturnId, 'refund_posted');
            return ['ok' => true, 'refund_receipt_id' => 0];
        }

        if ($refundReceiptId > 0) {
            $updReceipt = $conn->prepare("
                UPDATE financial_receipts
                SET type = 'in',
                    category = 'supplier_refund',
                    tax_law_key = ?,
                    amount = ?,
                    description = ?,
                    trans_date = ?,
                    supplier_id = ?,
                    invoice_id = NULL,
                    client_id = NULL,
                    employee_id = NULL,
                    payroll_id = NULL,
                    created_by = ?
                WHERE id = ?
            ");
            $updReceipt->bind_param('sdssisi', $taxLawKey, $refundAmount, $desc, $returnDate, $supplierId, $actor, $refundReceiptId);
            $updReceipt->execute();
            $updReceipt->close();
        } else {
            $insReceipt = $conn->prepare("
                INSERT INTO financial_receipts (
                    type, category, tax_law_key, amount, description, trans_date, client_id, invoice_id, supplier_id, employee_id, payroll_id, created_by
                ) VALUES ('in', 'supplier_refund', ?, ?, ?, ?, NULL, NULL, ?, NULL, NULL, ?)
            ");
            $insReceipt->bind_param('sdssis', $taxLawKey, $refundAmount, $desc, $returnDate, $supplierId, $actor);
            $insReceipt->execute();
            $refundReceiptId = (int)$insReceipt->insert_id;
            $insReceipt->close();
        }

        $updReturn = $conn->prepare("UPDATE purchase_invoice_returns SET refund_amount = ?, refund_receipt_id = ? WHERE id = ?");
        $updReturn->bind_param('dii', $refundAmount, $refundReceiptId, $purchaseReturnId);
        $updReturn->execute();
        $updReturn->close();

        financeLedgerPostByRule(
            $conn,
            'purchase_return_refund',
            $purchaseReturnId,
            [
                'supplier_id' => $supplierId,
                'ref' => $returnNumber,
                'doc_date' => $returnDate,
                'refund_amount' => $refundAmount,
                'notes' => $desc,
            ],
            [
                'receipt_id' => $refundReceiptId,
                'purchase_invoice_id' => (int)($row['purchase_invoice_id'] ?? 0),
                'refund_amount' => $refundAmount,
                'tax_law_key' => $taxLawKey,
            ],
            $actor
        );

        return ['ok' => true, 'refund_receipt_id' => $refundReceiptId];
    }
}

if (!function_exists('finance_create_sales_invoice_return')) {
    function finance_create_sales_invoice_return(
        mysqli $conn,
        int $invoiceId,
        string $returnDate,
        array $returnLines,
        int $userId,
        string $creatorName = '',
        string $notes = ''
    ): int {
        financeEnsureSalesReturnSchema($conn);
        if ($invoiceId <= 0) {
            throw new InvalidArgumentException('invalid_sales_invoice');
        }
        if ($returnDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $returnDate)) {
            $returnDate = date('Y-m-d');
        }

        $stmtInv = $conn->prepare("
            SELECT id, client_id, inv_date, due_date, sub_total, tax, discount, total_amount, paid_amount, remaining_amount, status,
                   IFNULL(eta_uuid, '') AS eta_uuid,
                   IFNULL(eta_status, '') AS eta_status,
                   IFNULL(eta_submission_id, '') AS eta_submission_id,
                   IFNULL(eta_long_id, '') AS eta_long_id
            FROM invoices
            WHERE id = ?
            LIMIT 1
        ");
        $stmtInv->bind_param('i', $invoiceId);
        $stmtInv->execute();
        $invoice = $stmtInv->get_result()->fetch_assoc();
        $stmtInv->close();
        if (!$invoice) {
            throw new RuntimeException('sales_invoice_not_found');
        }
        if ((string)($invoice['status'] ?? '') === 'cancelled') {
            throw new RuntimeException('sales_invoice_cancelled');
        }
        $hasEtaBinding = trim((string)($invoice['eta_uuid'] ?? '')) !== ''
            || trim((string)($invoice['eta_status'] ?? '')) !== ''
            || trim((string)($invoice['eta_submission_id'] ?? '')) !== ''
            || trim((string)($invoice['eta_long_id'] ?? '')) !== '';
        if ($hasEtaBinding) {
            throw new RuntimeException('sales_invoice_eta_locked');
        }
        if ((float)($invoice['paid_amount'] ?? 0) > 0.00001) {
            throw new RuntimeException('sales_invoice_paid');
        }

        $lineStmt = $conn->prepare("
            SELECT id, line_number, quantity, unit_label, unit_price, line_total
            FROM invoice_items
            WHERE invoice_id = ?
            ORDER BY line_number ASC, id ASC
        ");
        $lineStmt->bind_param('i', $invoiceId);
        $lineStmt->execute();
        $lineRes = $lineStmt->get_result();
        $itemMap = [];
        while ($row = $lineRes->fetch_assoc()) {
            $itemMap[(int)($row['id'] ?? 0)] = $row;
        }
        $lineStmt->close();
        if (empty($itemMap)) {
            throw new RuntimeException('sales_invoice_items_missing');
        }

        $normalized = [];
        $subtotal = 0.0;
        foreach ($returnLines as $line) {
            $invoiceItemId = (int)($line['invoice_item_id'] ?? 0);
            $qty = round((float)($line['qty'] ?? 0), 2);
            if ($invoiceItemId <= 0 || $qty <= 0.00001) {
                continue;
            }
            if (!isset($itemMap[$invoiceItemId])) {
                throw new RuntimeException('sales_return_invalid_item');
            }

            $src = $itemMap[$invoiceItemId];
            $sourceQty = round((float)($src['quantity'] ?? 0), 2);
            $returnedStmt = $conn->prepare("
                SELECT IFNULL(SUM(ri.quantity), 0)
                FROM invoice_return_items ri
                INNER JOIN invoice_returns r ON r.id = ri.invoice_return_id
                WHERE ri.invoice_item_id = ?
                  AND IFNULL(r.is_void, 0) = 0
                  AND LOWER(TRIM(IFNULL(r.status, 'posted'))) NOT IN ('void', 'cancelled')
            ");
            $returnedStmt->bind_param('i', $invoiceItemId);
            $returnedStmt->execute();
            $alreadyReturned = round((float)($returnedStmt->get_result()->fetch_row()[0] ?? 0), 2);
            $returnedStmt->close();

            $availableQty = round(max(0.0, $sourceQty - $alreadyReturned), 2);
            if ($qty - $availableQty > 0.00001) {
                throw new RuntimeException('sales_return_qty_exceeds_invoice');
            }

            $unitPrice = round((float)($src['unit_price'] ?? 0), 2);
            $lineTotal = round($qty * $unitPrice, 2);
            $subtotal += $lineTotal;
            $normalized[] = [
                'invoice_item_id' => $invoiceItemId,
                'line_number' => (int)($src['line_number'] ?? 1),
                'quantity' => $qty,
                'unit_label' => (string)($src['unit_label'] ?? 'وحدة'),
                'unit_price' => $unitPrice,
                'line_total' => $lineTotal,
            ];
        }

        if (empty($normalized) || $subtotal <= 0.00001) {
            throw new RuntimeException('sales_return_empty');
        }

        $invoiceSubtotal = max(0.0, round((float)($invoice['sub_total'] ?? 0), 2));
        $invoiceDiscount = max(0.0, round((float)($invoice['discount'] ?? 0), 2));
        $invoiceTax = max(0.0, round((float)($invoice['tax'] ?? 0), 2));
        $ratio = $invoiceSubtotal > 0.00001 ? min(1, round($subtotal / $invoiceSubtotal, 8)) : 0.0;
        $discountAmount = round($invoiceDiscount * $ratio, 2);
        $taxAmount = round($invoiceTax * $ratio, 2);
        $totalAmount = round(max(0.0, $subtotal - $discountAmount + $taxAmount), 2);
        if ($totalAmount <= 0.00001) {
            throw new RuntimeException('sales_return_zero_total');
        }

        $creatorName = mb_substr(trim($creatorName), 0, 190);
        if ($creatorName == '') {
            $creatorName = 'System';
        }

        $stmtIns = $conn->prepare("
            INSERT INTO invoice_returns (
                invoice_id, return_number, return_date, subtotal_amount, tax_amount, discount_amount, total_amount,
                refund_amount, status, is_void, notes, created_by_user_id, created_by_name
            ) VALUES (?, '', ?, ?, ?, ?, ?, 0, 'posted', 0, ?, ?, ?)
        ");
        $stmtIns->bind_param(
            'isddddssis',
            $invoiceId,
            $returnDate,
            $subtotal,
            $taxAmount,
            $discountAmount,
            $totalAmount,
            $notes,
            $userId,
            $creatorName
        );
        $stmtIns->execute();
        $returnId = (int)$stmtIns->insert_id;
        $stmtIns->close();
        if ($returnId <= 0) {
            throw new RuntimeException('sales_return_insert_failed');
        }

        $rowStmt = $conn->prepare("
            INSERT INTO invoice_return_items (
                invoice_return_id, invoice_item_id, line_number, quantity, unit_label, unit_price, line_total
            ) VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        foreach ($normalized as $row) {
            $invoiceItemId = (int)$row['invoice_item_id'];
            $lineNumber = (int)$row['line_number'];
            $quantity = (float)$row['quantity'];
            $unitLabel = (string)$row['unit_label'];
            $unitPrice = (float)$row['unit_price'];
            $lineTotal = (float)$row['line_total'];
            $rowStmt->bind_param('iiidsdd', $returnId, $invoiceItemId, $lineNumber, $quantity, $unitLabel, $unitPrice, $lineTotal);
            $rowStmt->execute();
        }
        $rowStmt->close();

        app_assign_document_number($conn, 'invoice_returns', $returnId, 'return_number', 'srtn', $returnDate);
        financeLedgerPostByRule(
            $conn,
            'sales_return_issued',
            $returnId,
            [
                'client_id' => (int)($invoice['client_id'] ?? 0),
                'ref' => $returnNumber,
                'doc_date' => $returnDate,
                'total_amount' => $totalAmount,
                'notes' => trim($notes),
            ],
            [
                'invoice_id' => $invoiceId,
                'subtotal_amount' => $subtotal,
                'tax_amount' => $taxAmount,
                'discount_amount' => $discountAmount,
                'total_amount' => $totalAmount,
            ],
            $creatorName
        );

        return $returnId;
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
            $stmt = $conn->prepare("SELECT client_id FROM invoices WHERE id = ? LIMIT 1");
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
                $label = app_tr('سلفة موظف', 'Employee advance');
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

        $salesReturnCredit = financeClientSalesReturnCreditTotal($conn, $clientId, $asOfDate);
        $netBalance = round($openingOutstanding + $invoiceDue - ($receiptCredit + $openingCredit + $salesReturnCredit), 2);
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
            'sales_return_credit' => round($salesReturnCredit, 2),
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

        $salesReturnCredit = financeClientSalesReturnCreditTotal($conn, $clientId);
        $netBalance = round($openingOutstanding + $invoiceDue - ($receiptCredit + $openingCredit + $salesReturnCredit), 2);
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
            'sales_return_credit' => round($salesReturnCredit, 2),
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
            $stmt = $conn->prepare("SELECT client_id FROM invoices WHERE id = ? LIMIT 1");
            $stmt->bind_param('i', $invoiceId);
            $stmt->execute();
            $inv = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$inv) {
                return app_tr('فاتورة المبيعات المحددة غير موجودة.', 'The selected sales invoice was not found.');
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
        $inv = $conn->query("SELECT total_amount FROM invoices WHERE id = {$invoice_id}")->fetch_assoc();
        if (!$inv) return;

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

        $stmt = $conn->prepare("SELECT total_amount, due_date FROM invoices WHERE id = ? LIMIT 1");
        $stmt->bind_param('i', $invoiceId);
        $stmt->execute();
        $invoice = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$invoice) {
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

if (!function_exists('financeSyncSupplierCurrentBalance')) {
    function financeSyncSupplierCurrentBalance(mysqli $conn, int $supplierId): void
    {
        $supplierId = (int)$supplierId;
        if ($supplierId <= 0) {
            return;
        }
        if (function_exists('app_table_has_column') && !app_table_has_column($conn, 'suppliers', 'current_balance')) {
            return;
        }
        $snapshot = financeSupplierBalanceSnapshot($conn, $supplierId);
        $currentBalance = round((float)($snapshot['net_balance'] ?? 0), 2);
        $stmt = $conn->prepare("UPDATE suppliers SET current_balance = ? WHERE id = ?");
        $stmt->bind_param('di', $currentBalance, $supplierId);
        $stmt->execute();
        $stmt->close();
    }
}

if (!function_exists('recalculatePurchaseInvoice')) {
    function recalculatePurchaseInvoice(mysqli $conn, $invoice_id): void {
        if (!$invoice_id || $invoice_id === 'NULL') return;
        $invoice_id = (int)$invoice_id;
        financeEnsureAllocationSchema($conn);

        $stmtInv = $conn->prepare("SELECT supplier_id, total_amount, status FROM purchase_invoices WHERE id = ? LIMIT 1");
        $stmtInv->bind_param('i', $invoice_id);
        $stmtInv->execute();
        $inv = $stmtInv->get_result()->fetch_assoc();
        $stmtInv->close();
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
        $stmtUpd = $conn->prepare("UPDATE purchase_invoices SET paid_amount = ?, remaining_amount = ?, status = ? WHERE id = ?");
        $stmtUpd->bind_param('ddsi', $paid, $remaining, $status, $invoice_id);
        $stmtUpd->execute();
        $stmtUpd->close();

        financeSyncSupplierCurrentBalance($conn, (int)($inv['supplier_id'] ?? 0));
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

if (!function_exists('finance_allocate_outgoing_receipt_for_supplier')) {
    function finance_allocate_outgoing_receipt_for_supplier(mysqli $conn, int $receiptId, int $supplierId, float $amount, int $directInvoiceId = 0, string $notePrefix = 'FIFO'): array
    {
        financeEnsureAllocationSchema($conn);
        $receiptId = (int)$receiptId;
        $supplierId = (int)$supplierId;
        $directInvoiceId = (int)$directInvoiceId;
        $remaining = round((float)$amount, 2);
        $targets = ['purchase_invoice' => []];

        if ($receiptId <= 0 || $supplierId <= 0 || $remaining <= 0.00001) {
            return ['remaining' => max(0.0, $remaining), 'targets' => $targets];
        }

        $sData = $conn->query("SELECT opening_balance FROM suppliers WHERE id = {$supplierId}")->fetch_assoc();
        $openingBal = $sData ? (float)($sData['opening_balance'] ?? 0) : 0.0;
        if ($openingBal > 0.00001) {
            $legacyGeneralStmt = $conn->prepare("
                SELECT IFNULL(SUM(r.amount), 0)
                FROM financial_receipts r
                LEFT JOIN financial_receipt_allocations a ON a.receipt_id = r.id
                WHERE r.supplier_id = ? AND r.type = 'out' AND r.invoice_id IS NULL AND a.id IS NULL AND r.id <> ?
            ");
            $legacyGeneralStmt->bind_param('ii', $supplierId, $receiptId);
            $legacyGeneralStmt->execute();
            $legacyGeneralPaid = (float)($legacyGeneralStmt->get_result()->fetch_row()[0] ?? 0);
            $legacyGeneralStmt->close();

            $openingAppliedStmt = $conn->prepare("
                SELECT IFNULL(SUM(a.amount),0)
                FROM financial_receipt_allocations a
                INNER JOIN financial_receipts r ON r.id = a.receipt_id
                WHERE r.supplier_id = ? AND r.type = 'out' AND a.allocation_type = 'supplier_opening' AND r.id <> ?
            ");
            $openingAppliedStmt->bind_param('ii', $supplierId, $receiptId);
            $openingAppliedStmt->execute();
            $openingAlreadyApplied = (float)($openingAppliedStmt->get_result()->fetch_row()[0] ?? 0);
            $openingAppliedStmt->close();

            $openingRemaining = max(0.0, round($openingBal - $legacyGeneralPaid - $openingAlreadyApplied, 2));
            if ($openingRemaining > 0.00001 && $remaining > 0.00001) {
                $openingPay = min($remaining, $openingRemaining);
                financeInsertReceiptAllocation($conn, $receiptId, 'supplier_opening', $supplierId, $openingPay, trim($notePrefix . ' opening balance'));
                $remaining -= $openingPay;
            }
        }

        if ($directInvoiceId > 0 && $remaining > 0.00001) {
            $invoiceRes = $conn->query("SELECT remaining_amount FROM purchase_invoices WHERE id = {$directInvoiceId} AND COALESCE(status, '') <> 'cancelled' LIMIT 1");
            $invoiceRow = $invoiceRes ? $invoiceRes->fetch_assoc() : null;
            $pay = $invoiceRow ? min($remaining, max(0.0, (float)($invoiceRow['remaining_amount'] ?? 0))) : 0.0;
            if ($pay > 0.00001) {
                financeInsertReceiptAllocation($conn, $receiptId, 'purchase_invoice', $directInvoiceId, $pay, trim($notePrefix . ' direct'));
                $targets['purchase_invoice'][$directInvoiceId] = $directInvoiceId;
                $remaining -= $pay;
            }
        }

        if ($remaining > 0.00001) {
            $sql = "SELECT id, remaining_amount FROM purchase_invoices WHERE supplier_id = {$supplierId} AND status NOT IN ('paid','cancelled')";
            if ($directInvoiceId > 0) {
                $sql .= " AND id != {$directInvoiceId}";
            }
            $sql .= " ORDER BY inv_date ASC, id ASC";
            $invoices = $conn->query($sql);
            if ($invoices) {
                while ($inv = $invoices->fetch_assoc()) {
                    if ($remaining <= 0.00001) {
                        break;
                    }
                    $pay = min($remaining, max(0.0, (float)($inv['remaining_amount'] ?? 0)));
                    if ($pay <= 0.00001) {
                        continue;
                    }
                    $invoiceId = (int)($inv['id'] ?? 0);
                    financeInsertReceiptAllocation($conn, $receiptId, 'purchase_invoice', $invoiceId, $pay, trim($notePrefix . ' fifo'));
                    $targets['purchase_invoice'][$invoiceId] = $invoiceId;
                    $remaining -= $pay;
                }
            }
        }

        financeSyncSupplierCurrentBalance($conn, $supplierId);

        return [
            'remaining' => max(0.0, round($remaining, 2)),
            'targets' => $targets,
        ];
    }
}

if (!function_exists('autoAllocateSupplierPayment')) {
    function autoAllocateSupplierPayment(mysqli $conn, int $supplier_id, float $amount, string $date, string $desc, string $user): int {
        financeEnsureAllocationSchema($conn);
        $receiptId = financeCreateReceiptMaster($conn, 'out', 'supplier', $amount, $desc, $date, null, $supplier_id, null, null, $user);
        $allocation = finance_allocate_outgoing_receipt_for_supplier($conn, $receiptId, $supplier_id, $amount, 0, 'FIFO');
        foreach (($allocation['targets']['purchase_invoice'] ?? []) as $invoiceId) {
            recalculatePurchaseInvoice($conn, (int)$invoiceId);
        }
        financeSyncSupplierCurrentBalance($conn, $supplier_id);
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
        if ($rem > 0) {
            financeInsertReceiptAllocation($conn, $receiptId, 'loan_advance', $employee_id, $rem, 'Advance remainder');
        }
        return $receiptId;
    }
}
