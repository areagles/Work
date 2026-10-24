<?php

if (!function_exists('finance_client_normalization_allowed')) {
    function finance_client_normalization_allowed(): bool
    {
        return false;
    }
}

if (!function_exists('finance_allocate_incoming_receipt_for_client')) {
    function finance_allocate_incoming_receipt_for_client(mysqli $conn, int $receiptId, int $clientId, float $amount, int $directInvoiceId = 0, string $notePrefix = 'FIFO'): array
    {
        financeEnsureAllocationSchema($conn);
        $receiptId = (int)$receiptId;
        $clientId = (int)$clientId;
        $directInvoiceId = (int)$directInvoiceId;
        $remaining = round((float)$amount, 2);
        $targets = ['sales_invoice' => []];

        if ($receiptId <= 0 || $clientId <= 0 || $remaining <= 0.00001) {
            return ['remaining' => max(0.0, $remaining), 'targets' => $targets];
        }

        $openingState = financeClientOpeningBreakdown($conn, $clientId, $receiptId);
        $openingRemaining = (float)($openingState['opening_outstanding'] ?? 0);
        if ($openingRemaining > 0.00001 && $remaining > 0.00001) {
            $openingPay = min($remaining, $openingRemaining);
            financeInsertReceiptAllocation($conn, $receiptId, 'client_opening', $clientId, $openingPay, trim($notePrefix . ' opening balance'));
            $remaining -= $openingPay;
        }

        if ($directInvoiceId > 0 && $remaining > 0.00001) {
            $invoiceRes = $conn->query("SELECT remaining_amount, status FROM invoices WHERE id = {$directInvoiceId} LIMIT 1");
            $invoiceRow = $invoiceRes ? $invoiceRes->fetch_assoc() : null;
            $pay = ($invoiceRow && (string)($invoiceRow['status'] ?? '') !== 'cancelled') ? min($remaining, max(0.0, (float)($invoiceRow['remaining_amount'] ?? 0))) : 0.0;
            if ($pay > 0.00001) {
                financeInsertReceiptAllocation($conn, $receiptId, 'sales_invoice', $directInvoiceId, $pay, trim($notePrefix . ' direct'));
                $targets['sales_invoice'][$directInvoiceId] = $directInvoiceId;
                $remaining -= $pay;
            }
        }

        if ($remaining > 0.00001) {
            $sql = "SELECT id, remaining_amount FROM invoices WHERE client_id = {$clientId} AND status NOT IN ('paid','cancelled')";
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
                    financeInsertReceiptAllocation($conn, $receiptId, 'sales_invoice', $invoiceId, $pay, trim($notePrefix . ' fifo'));
                    $targets['sales_invoice'][$invoiceId] = $invoiceId;
                    $remaining -= $pay;
                }
            }
        }

        return [
            'remaining' => max(0.0, round($remaining, 2)),
            'targets' => $targets,
        ];
    }
}

if (!function_exists('finance_reallocate_receipt_fifo')) {
    function finance_reallocate_receipt_fifo(mysqli $conn, int $receiptId, string $sessionUser = ''): array
    {
        financeEnsureAllocationSchema($conn);
        if ($receiptId <= 0) {
            return ['ok' => false, 'message' => app_tr('معرّف السند غير صالح.', 'Invalid receipt id.')];
        }

        $receiptRes = $conn->query("SELECT * FROM financial_receipts WHERE id = {$receiptId} LIMIT 1");
        $receipt = $receiptRes ? $receiptRes->fetch_assoc() : null;
        if (!$receipt) {
            return ['ok' => false, 'message' => app_tr('السند غير موجود.', 'Receipt not found.')];
        }

        $oldTargets = financeReceiptAllocationTargets($conn, $receiptId);
        $newTargets = [
            'sales_invoice' => [],
            'purchase_invoice' => [],
            'payroll' => [],
        ];

        $type = strtolower(trim((string)($receipt['type'] ?? '')));
        $category = strtolower(trim((string)($receipt['category'] ?? '')));
        $clientId = (int)($receipt['client_id'] ?? 0);
        $supplierId = (int)($receipt['supplier_id'] ?? 0);
        $employeeId = (int)($receipt['employee_id'] ?? 0);
        $invoiceId = (int)($receipt['invoice_id'] ?? 0);
        $payrollId = (int)($receipt['payroll_id'] ?? 0);
        $amount = (float)($receipt['amount'] ?? 0);

        if ($amount <= 0.00001) {
            return ['ok' => false, 'message' => app_tr('لا يمكن إعادة توزيع سند بقيمة صفرية.', 'Cannot reallocate a zero-value receipt.')];
        }

        $supported = (
            ($type === 'in' && $clientId > 0)
            || ($type === 'out' && $category === 'supplier' && $supplierId > 0)
            || ($type === 'out' && $category === 'salary' && $employeeId > 0)
        );
        if (!$supported) {
            return ['ok' => false, 'message' => app_tr('هذا النوع من السندات لا يدعم التسوية التلقائية حاليًا.', 'This receipt type does not currently support automatic reallocation.')];
        }

        try {
            $conn->begin_transaction();
            financeDeleteReceiptAllocations($conn, $receiptId);

            foreach ($oldTargets['sales_invoice'] as $id) {
                recalculateSalesInvoice($conn, $id);
            }
            foreach ($oldTargets['purchase_invoice'] as $id) {
                recalculatePurchaseInvoice($conn, $id);
            }
            foreach ($oldTargets['payroll'] as $id) {
                recalculatePayroll($conn, $id);
            }
            if ($invoiceId > 0) {
                $type === 'in' ? recalculateSalesInvoice($conn, $invoiceId) : recalculatePurchaseInvoice($conn, $invoiceId);
            }
            if ($payrollId > 0) {
                recalculatePayroll($conn, $payrollId);
            }

            if ($type === 'in' && $clientId > 0) {
                $allocation = finance_allocate_incoming_receipt_for_client(
                    $conn,
                    $receiptId,
                    $clientId,
                    $amount,
                    $invoiceId,
                    $invoiceId > 0 ? 'Manual invoice binding' : 'FIFO'
                );
                $newTargets['sales_invoice'] = $allocation['targets']['sales_invoice'] ?? [];
            } elseif ($type === 'out' && $category === 'supplier' && $supplierId > 0) {
                if ($invoiceId > 0) {
                    $rem = $amount;
                    $invoiceRes = $conn->query("SELECT remaining_amount FROM purchase_invoices WHERE id = {$invoiceId} LIMIT 1");
                    $invoiceRow = $invoiceRes ? $invoiceRes->fetch_assoc() : null;
                    $pay = $invoiceRow ? min($rem, max(0.0, (float)($invoiceRow['remaining_amount'] ?? 0))) : 0.0;
                    if ($pay > 0.00001) {
                        financeInsertReceiptAllocation($conn, $receiptId, 'purchase_invoice', $invoiceId, $pay, 'Manual purchase binding');
                        $newTargets['purchase_invoice'][$invoiceId] = $invoiceId;
                        $rem -= $pay;
                    }
                    if ($rem > 0.00001) {
                        $invoices = $conn->query("SELECT id, remaining_amount FROM purchase_invoices WHERE supplier_id = {$supplierId} AND id != {$invoiceId} AND status NOT IN ('paid','cancelled') ORDER BY inv_date ASC, id ASC");
                        if ($invoices) {
                            while ($inv = $invoices->fetch_assoc()) {
                                if ($rem <= 0.00001) {
                                    break;
                                }
                                $fifoPay = min($rem, max(0.0, (float)($inv['remaining_amount'] ?? 0)));
                                if ($fifoPay <= 0.00001) {
                                    continue;
                                }
                                $invId = (int)($inv['id'] ?? 0);
                                financeInsertReceiptAllocation($conn, $receiptId, 'purchase_invoice', $invId, $fifoPay, 'FIFO remainder');
                                $newTargets['purchase_invoice'][$invId] = $invId;
                                $rem -= $fifoPay;
                            }
                        }
                    }
                } else {
                    $rem = $amount;
                    $invoices = $conn->query("SELECT id, remaining_amount FROM purchase_invoices WHERE supplier_id = {$supplierId} AND status NOT IN ('paid','cancelled') ORDER BY inv_date ASC, id ASC");
                    if ($invoices) {
                        while ($inv = $invoices->fetch_assoc()) {
                            if ($rem <= 0.00001) {
                                break;
                            }
                            $pay = min($rem, max(0.0, (float)($inv['remaining_amount'] ?? 0)));
                            if ($pay <= 0.00001) {
                                continue;
                            }
                            $invId = (int)($inv['id'] ?? 0);
                            financeInsertReceiptAllocation($conn, $receiptId, 'purchase_invoice', $invId, $pay, 'FIFO');
                            $newTargets['purchase_invoice'][$invId] = $invId;
                            $rem -= $pay;
                        }
                    }
                }
            } elseif ($type === 'out' && $category === 'salary' && $employeeId > 0) {
                if ($payrollId > 0) {
                    $rem = $amount;
                    $sheetRes = $conn->query("SELECT remaining_amount FROM payroll_sheets WHERE id = {$payrollId} LIMIT 1");
                    $sheetRow = $sheetRes ? $sheetRes->fetch_assoc() : null;
                    $pay = $sheetRow ? min($rem, max(0.0, (float)($sheetRow['remaining_amount'] ?? 0))) : 0.0;
                    if ($pay > 0.00001) {
                        financeInsertReceiptAllocation($conn, $receiptId, 'payroll', $payrollId, $pay, 'Manual payroll binding');
                        $newTargets['payroll'][$payrollId] = $payrollId;
                        $rem -= $pay;
                    }
                    if ($rem > 0.00001) {
                        $sheets = $conn->query("SELECT id, remaining_amount FROM payroll_sheets WHERE employee_id = {$employeeId} AND id != {$payrollId} AND status != 'paid' ORDER BY month_year ASC, id ASC");
                        if ($sheets) {
                            while ($sheet = $sheets->fetch_assoc()) {
                                if ($rem <= 0.00001) {
                                    break;
                                }
                                $fifoPay = min($rem, max(0.0, (float)($sheet['remaining_amount'] ?? 0)));
                                if ($fifoPay <= 0.00001) {
                                    continue;
                                }
                                $sheetId = (int)($sheet['id'] ?? 0);
                                financeInsertReceiptAllocation($conn, $receiptId, 'payroll', $sheetId, $fifoPay, 'FIFO remainder');
                                $newTargets['payroll'][$sheetId] = $sheetId;
                                $rem -= $fifoPay;
                            }
                        }
                    }
                } else {
                    $rem = $amount;
                    $sheets = $conn->query("SELECT id, remaining_amount FROM payroll_sheets WHERE employee_id = {$employeeId} AND status != 'paid' ORDER BY month_year ASC, id ASC");
                    if ($sheets) {
                        while ($sheet = $sheets->fetch_assoc()) {
                            if ($rem <= 0.00001) {
                                break;
                            }
                            $pay = min($rem, max(0.0, (float)($sheet['remaining_amount'] ?? 0)));
                            if ($pay <= 0.00001) {
                                continue;
                            }
                            $sheetId = (int)($sheet['id'] ?? 0);
                            financeInsertReceiptAllocation($conn, $receiptId, 'payroll', $sheetId, $pay, 'FIFO');
                            $newTargets['payroll'][$sheetId] = $sheetId;
                            $rem -= $pay;
                        }
                    }
                }
            }

            foreach ($oldTargets['sales_invoice'] as $id) {
                recalculateSalesInvoice($conn, $id);
            }
            foreach ($oldTargets['purchase_invoice'] as $id) {
                recalculatePurchaseInvoice($conn, $id);
            }
            foreach ($oldTargets['payroll'] as $id) {
                recalculatePayroll($conn, $id);
            }
            foreach (array_values($newTargets['sales_invoice']) as $id) {
                recalculateSalesInvoice($conn, $id);
            }
            foreach (array_values($newTargets['purchase_invoice']) as $id) {
                recalculatePurchaseInvoice($conn, $id);
            }
            foreach (array_values($newTargets['payroll']) as $id) {
                recalculatePayroll($conn, $id);
            }
            if ($invoiceId > 0) {
                $type === 'in' ? recalculateSalesInvoice($conn, $invoiceId) : recalculatePurchaseInvoice($conn, $invoiceId);
            }
            if ($payrollId > 0) {
                recalculatePayroll($conn, $payrollId);
            }

            $conn->commit();
            app_audit_log_add($conn, 'finance.transaction_reallocated_fifo', [
                'entity_type' => 'financial_receipt',
                'entity_key' => (string)$receiptId,
                'details' => [
                    'type' => $type,
                    'category' => $category,
                    'amount' => $amount,
                    'actor' => $sessionUser,
                ],
            ]);
            return ['ok' => true, 'message' => app_tr('تمت إعادة توزيع السند بنجاح.', 'Receipt reallocation completed successfully.')];
        } catch (Throwable $e) {
            $conn->rollback();
            return ['ok' => false, 'message' => app_tr('تعذر إعادة توزيع السند.', 'Could not reallocate the receipt.') . ' ' . $e->getMessage()];
        }
    }
}

if (!function_exists('finance_normalize_client_receipts_fifo')) {
    function finance_normalize_client_receipts_fifo(mysqli $conn, int $clientId, string $sessionUser = ''): array
    {
        return [
            'ok' => false,
            'message' => app_tr(
                'إعادة بناء تسويات العملاء جماعيًا أُوقفت نهائيًا حفاظًا على سلامة القيود التاريخية. استخدم تسوية السند الفردي فقط.',
                'Bulk client receipt normalization has been permanently disabled to preserve historical accounting intent. Use individual receipt reallocation only.'
            ),
        ];
    }
}

if (!function_exists('finance_delete_transaction')) {
    function finance_delete_transaction(mysqli $conn, int $id): array
    {
        financeEnsureAllocationSchema($conn);
        if ($id <= 0) {
            return ['ok' => false, 'message' => app_tr('معرّف الحركة غير صالح.', 'Invalid transaction id.')];
        }

        $old_res = $conn->query("SELECT id, invoice_id, payroll_id, client_id, supplier_id, category, trans_date, amount, type, description FROM financial_receipts WHERE id = {$id}");
        if (!$old_res || $old_res->num_rows === 0) {
            return ['ok' => false, 'message' => app_tr('الحركة المالية غير موجودة.', 'Financial transaction not found.')];
        }

        $old = $old_res->fetch_assoc();
        $allocationTargets = financeReceiptAllocationTargets($conn, $id);
        if (financeReceiptIsOpeningBalance($conn, $old, $allocationTargets)) {
            $openingSync = financeSyncOpeningBalanceReceiptChange($conn, array_merge($old, ['id' => $id]), 0.0);
            if (empty($openingSync['ok'])) {
                return ['ok' => false, 'message' => (string)($openingSync['message'] ?? app_tr('تعذر حذف سند رصيد أول المدة.', 'Could not delete the opening balance receipt.'))];
            }
            financeDeleteReceiptAllocations($conn, $id);
            if (!$conn->query("DELETE FROM financial_receipts WHERE id = {$id}")) {
                return ['ok' => false, 'message' => app_tr('فشل حذف سند رصيد أول المدة.', 'Failed to delete the opening balance receipt.') . ' ' . $conn->error];
            }
            app_audit_log_add($conn, 'finance.opening_balance_receipt_deleted', [
                'entity_type' => 'financial_receipt',
                'entity_key' => (string)$id,
                'details' => [
                    'type' => (string)($old['type'] ?? ''),
                    'description' => (string)($old['description'] ?? ''),
                ],
            ]);
            if (!empty($old['invoice_id'])) {
                if (($old['type'] ?? '') === 'in') {
                    recalculateSalesInvoice($conn, $old['invoice_id']);
                } else {
                    recalculatePurchaseInvoice($conn, $old['invoice_id']);
                }
            }
            return ['ok' => true, 'redirect' => 'finance.php?msg=deleted'];
        }

        financeDeleteReceiptAllocations($conn, $id);
        if (!$conn->query("DELETE FROM financial_receipts WHERE id = {$id}")) {
            return ['ok' => false, 'message' => app_tr('فشل حذف الحركة المالية.', 'Failed to delete financial transaction.') . ' ' . $conn->error];
        }
        app_audit_log_add($conn, 'finance.transaction_deleted', [
            'entity_type' => 'financial_receipt',
            'entity_key' => (string)$id,
            'details' => [
                'type' => (string)($old['type'] ?? ''),
                'description' => (string)($old['description'] ?? ''),
            ],
        ]);

        if (!empty($old['invoice_id'])) {
            if (($old['type'] ?? '') === 'in') {
                recalculateSalesInvoice($conn, $old['invoice_id']);
            } else {
                recalculatePurchaseInvoice($conn, $old['invoice_id']);
            }
        }
        if (!empty($old['payroll_id'])) {
            recalculatePayroll($conn, $old['payroll_id']);
        }
        foreach ($allocationTargets['sales_invoice'] as $invoiceId) {
            recalculateSalesInvoice($conn, $invoiceId);
        }
        foreach ($allocationTargets['purchase_invoice'] as $invoiceId) {
            recalculatePurchaseInvoice($conn, $invoiceId);
        }
        foreach ($allocationTargets['payroll'] as $payrollId) {
            recalculatePayroll($conn, $payrollId);
        }

        return ['ok' => true, 'redirect' => 'finance.php?msg=deleted'];
    }
}

if (!function_exists('finance_save_transaction')) {
    function finance_save_transaction(mysqli $conn, array $post, string $sessionUser): array
    {
        financeEnsureAllocationSchema($conn);
        $allowedTypes = ['in', 'out'];
        $allowedCats = ['general', 'supplier', 'salary', 'loan', 'tax', 'refund'];

        $type = in_array(($post['type'] ?? ''), $allowedTypes, true) ? $post['type'] : 'in';
        $cat = in_array(($post['category'] ?? ''), $allowedCats, true) ? $post['category'] : 'general';
        $amt = (float)($post['amount'] ?? 0);
        if ($amt <= 0) {
            return ['ok' => false, 'message' => app_tr('المبلغ يجب أن يكون أكبر من صفر.', 'Amount must be greater than zero.')];
        }

        $date = (string)($post['date'] ?? date('Y-m-d'));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $date = date('Y-m-d');
        }

        $descRaw = (string)($post['desc'] ?? '');
        $desc = $conn->real_escape_string($descRaw);
        $taxLawKeyRaw = trim((string)($post['tax_law_key'] ?? ''));
        $taxLawKey = $taxLawKeyRaw !== '' ? $conn->real_escape_string($taxLawKeyRaw) : '';

        $cid = !empty($post['client_id']) ? (int)$post['client_id'] : "NULL";
        $iid = !empty($post['invoice_id']) ? (int)$post['invoice_id'] : "NULL";
        $sid = !empty($post['supplier_id']) ? (int)$post['supplier_id'] : "NULL";
        $eid = !empty($post['employee_id']) ? (int)$post['employee_id'] : "NULL";
        $pid = !empty($post['payroll_id']) ? (int)$post['payroll_id'] : "NULL";

        $validation = financeValidateBindings($conn, $type, $cat, $cid, $iid, $sid, $eid, $pid, $taxLawKeyRaw);
        if ($validation !== '') {
            return ['ok' => false, 'message' => $validation];
        }

        $user = $conn->real_escape_string(mb_substr($sessionUser !== '' ? $sessionUser : 'Admin', 0, 100));

        $isUpdate = isset($post['trans_id']) && (string)$post['trans_id'] !== '';
        if ($isUpdate) {
            $tid = (int)$post['trans_id'];
            $old_data = $conn->query("SELECT id, invoice_id, payroll_id, client_id, supplier_id, category, tax_law_key, trans_date, amount, type, description FROM financial_receipts WHERE id = {$tid}")->fetch_assoc();
            if (!$old_data) {
                return ['ok' => false, 'message' => app_tr('الحركة المالية غير موجودة.', 'Financial transaction not found.')];
            }
            $oldAllocationTargets = financeReceiptAllocationTargets($conn, $tid);
            if (financeReceiptIsOpeningBalance($conn, $old_data, $oldAllocationTargets)) {
                try {
                    $conn->begin_transaction();
                    $openingSync = financeSyncOpeningBalanceReceiptChange($conn, $old_data, $amt, $date);
                    if (empty($openingSync['ok'])) {
                        $conn->rollback();
                        return ['ok' => false, 'message' => (string)($openingSync['message'] ?? app_tr('تعذر تعديل سند رصيد أول المدة.', 'Could not update the opening balance receipt.'))];
                    }
                    $conn->commit();
                    app_audit_log_add($conn, 'finance.opening_balance_receipt_updated', [
                        'entity_type' => 'financial_receipt',
                        'entity_key' => (string)$tid,
                        'details' => [
                            'old_amount' => (float)($old_data['amount'] ?? 0),
                            'new_amount' => $amt,
                            'date' => $date,
                        ],
                    ]);
                    if (!empty($old_data['invoice_id'])) {
                        if (($old_data['type'] ?? '') === 'in') {
                            recalculateSalesInvoice($conn, (int)$old_data['invoice_id']);
                        } else {
                            recalculatePurchaseInvoice($conn, (int)$old_data['invoice_id']);
                        }
                    }
                    return ['ok' => true, 'redirect' => 'finance.php?msg=updated'];
                } catch (Throwable $e) {
                    $conn->rollback();
                    return ['ok' => false, 'message' => app_tr('تعذر تعديل سند رصيد أول المدة.', 'Could not update the opening balance receipt.') . ' ' . $e->getMessage()];
                }
            }

            $taxLawSql = $taxLawKey !== '' ? ("'{$taxLawKey}'") : "NULL";
            $sql = "UPDATE financial_receipts SET type='{$type}', category='{$cat}', tax_law_key={$taxLawSql}, amount='{$amt}', description='{$desc}', trans_date='{$date}', client_id={$cid}, invoice_id={$iid}, supplier_id={$sid}, employee_id={$eid}, payroll_id={$pid} WHERE id={$tid}";
            if (!$conn->query($sql)) {
                return ['ok' => false, 'message' => app_tr('فشل تحديث الحركة المالية.', 'Failed to update financial transaction.') . ' ' . $conn->error];
            }
            financeDeleteReceiptAllocations($conn, $tid);
            $newAllocationTargets = [
                'sales_invoice' => [],
                'purchase_invoice' => [],
                'payroll' => [],
            ];

            if ($type === 'in') {
                $allocation = finance_allocate_incoming_receipt_for_client(
                    $conn,
                    $tid,
                    $cid !== "NULL" ? (int)$cid : 0,
                    $amt,
                    $iid !== "NULL" ? (int)$iid : 0,
                    $iid !== "NULL" ? 'Manual invoice binding' : 'FIFO'
                );
                $newAllocationTargets['sales_invoice'] = $allocation['targets']['sales_invoice'] ?? [];
            }
            if ($type === 'out' && $iid !== "NULL") {
                financeInsertReceiptAllocation($conn, $tid, 'purchase_invoice', (int)$iid, $amt, 'Manual purchase binding');
                $newAllocationTargets['purchase_invoice'][(int)$iid] = (int)$iid;
            }
            if ($type === 'out' && $cat === 'salary' && $pid !== "NULL") {
                financeInsertReceiptAllocation($conn, $tid, 'payroll', (int)$pid, $amt, 'Manual payroll binding');
                $newAllocationTargets['payroll'][(int)$pid] = (int)$pid;
            }
            if ($type === 'out' && $cat === 'supplier' && $sid !== "NULL" && $iid === "NULL") {
                $rem = $amt;
                $invs = $conn->query("SELECT id, remaining_amount FROM purchase_invoices WHERE supplier_id = " . (int)$sid . " AND status NOT IN ('paid','cancelled') ORDER BY inv_date ASC, id ASC");
                if ($invs) {
                    while ($inv = $invs->fetch_assoc()) {
                        if ($rem <= 0) break;
                        $pay = ($rem >= (float)$inv['remaining_amount']) ? (float)$inv['remaining_amount'] : $rem;
                        financeInsertReceiptAllocation($conn, $tid, 'purchase_invoice', (int)$inv['id'], $pay, 'FIFO');
                        $newAllocationTargets['purchase_invoice'][(int)$inv['id']] = (int)$inv['id'];
                        $rem -= $pay;
                    }
                }
            }
            if ($type === 'out' && $cat === 'salary' && $eid !== "NULL" && $pid === "NULL") {
                $rem = $amt;
                $sheets = $conn->query("SELECT id, remaining_amount FROM payroll_sheets WHERE employee_id = " . (int)$eid . " AND status != 'paid' ORDER BY month_year ASC, id ASC");
                if ($sheets) {
                    while ($sheet = $sheets->fetch_assoc()) {
                        if ($rem <= 0) break;
                        $pay = ($rem >= (float)$sheet['remaining_amount']) ? (float)$sheet['remaining_amount'] : $rem;
                        financeInsertReceiptAllocation($conn, $tid, 'payroll', (int)$sheet['id'], $pay, 'FIFO');
                        $newAllocationTargets['payroll'][(int)$sheet['id']] = (int)$sheet['id'];
                        $rem -= $pay;
                    }
                }
            }

            if (!empty($old_data['invoice_id'])) {
                (($old_data['type'] ?? '') === 'in') ? recalculateSalesInvoice($conn, $old_data['invoice_id']) : recalculatePurchaseInvoice($conn, $old_data['invoice_id']);
            }
            if (!empty($old_data['payroll_id'])) {
                recalculatePayroll($conn, $old_data['payroll_id']);
            }
            foreach ($oldAllocationTargets['sales_invoice'] as $invoiceId) {
                recalculateSalesInvoice($conn, $invoiceId);
            }
            foreach ($oldAllocationTargets['purchase_invoice'] as $invoiceId) {
                recalculatePurchaseInvoice($conn, $invoiceId);
            }
            foreach ($oldAllocationTargets['payroll'] as $payrollId) {
                recalculatePayroll($conn, $payrollId);
            }
            foreach (array_values($newAllocationTargets['sales_invoice']) as $invoiceId) {
                recalculateSalesInvoice($conn, $invoiceId);
            }
            foreach (array_values($newAllocationTargets['purchase_invoice']) as $invoiceId) {
                recalculatePurchaseInvoice($conn, $invoiceId);
            }
            foreach (array_values($newAllocationTargets['payroll']) as $payrollId) {
                recalculatePayroll($conn, $payrollId);
            }
            if ($iid !== "NULL") {
                ($type === 'in') ? recalculateSalesInvoice($conn, $iid) : recalculatePurchaseInvoice($conn, $iid);
            }
            if ($pid !== "NULL") {
                recalculatePayroll($conn, $pid);
            }
            app_audit_log_add($conn, 'finance.transaction_updated', [
                'entity_type' => 'financial_receipt',
                'entity_key' => (string)$tid,
                    'details' => [
                        'type' => $type,
                        'category' => $cat,
                        'tax_law_key' => $taxLawKeyRaw,
                        'amount' => $amt,
                        'date' => $date,
                    ],
            ]);

            return ['ok' => true, 'redirect' => 'finance.php?msg=updated'];
        }

        if ($type === 'in' && $cid !== "NULL" && $iid === "NULL") {
            $lastId = autoAllocatePayment($conn, (int)$cid, $amt, $date, $desc, $user);
            return ['ok' => true, 'redirect' => 'finance.php?msg=auto&lid=' . $lastId, 'last_id' => $lastId];
        }
        if ($type === 'out' && $cat === 'supplier' && $sid !== "NULL" && $iid === "NULL") {
            $lastId = autoAllocateSupplierPayment($conn, (int)$sid, $amt, $date, $desc, $user);
            return ['ok' => true, 'redirect' => 'finance.php?msg=auto_sup&lid=' . $lastId, 'last_id' => $lastId];
        }
        if ($type === 'out' && $cat === 'salary' && $eid !== "NULL" && $pid === "NULL") {
            $lastId = autoAllocatePayrollPayment($conn, (int)$eid, $amt, $date, $desc, $user);
            return ['ok' => true, 'redirect' => 'finance.php?msg=auto_emp&lid=' . $lastId, 'last_id' => $lastId];
        }

        $taxLawSql = $taxLawKey !== '' ? ("'{$taxLawKey}'") : "NULL";
        $sql = "INSERT INTO financial_receipts (type, category, tax_law_key, amount, description, trans_date, client_id, invoice_id, supplier_id, employee_id, payroll_id, created_by) VALUES ('{$type}', '{$cat}', {$taxLawSql}, '{$amt}', '{$desc}', '{$date}', {$cid}, {$iid}, {$sid}, {$eid}, {$pid}, '{$user}')";
        if (!$conn->query($sql)) {
            return ['ok' => false, 'message' => app_tr('خطأ في الحفظ:', 'Save failed:') . ' ' . $conn->error];
        }

        $last_id = (int)$conn->insert_id;
        if ($type === 'in' && $cid !== "NULL") {
            finance_allocate_incoming_receipt_for_client(
                $conn,
                $last_id,
                (int)$cid,
                $amt,
                $iid !== "NULL" ? (int)$iid : 0,
                $iid !== "NULL" ? 'Manual invoice binding' : 'FIFO'
            );
        }
        if ($type === 'out' && $iid !== "NULL") {
            financeInsertReceiptAllocation($conn, $last_id, 'purchase_invoice', (int)$iid, $amt, 'Manual purchase binding');
        }
        if ($type === 'out' && $cat === 'salary' && $pid !== "NULL") {
            financeInsertReceiptAllocation($conn, $last_id, 'payroll', (int)$pid, $amt, 'Manual payroll binding');
        }
        if ($iid !== "NULL") {
            ($type === 'in') ? recalculateSalesInvoice($conn, $iid) : recalculatePurchaseInvoice($conn, $iid);
        }
        if ($pid !== "NULL") {
            recalculatePayroll($conn, $pid);
        }
        app_audit_log_add($conn, 'finance.transaction_created', [
            'entity_type' => 'financial_receipt',
            'entity_key' => (string)$last_id,
            'details' => [
                'type' => $type,
                'category' => $cat,
                'tax_law_key' => $taxLawKeyRaw,
                'amount' => $amt,
                'date' => $date,
            ],
        ]);

        return ['ok' => true, 'redirect' => 'finance.php?msg=saved&lid=' . $last_id, 'last_id' => $last_id];
    }
}
