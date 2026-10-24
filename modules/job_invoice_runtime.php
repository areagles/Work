<?php

if (!function_exists('job_sales_invoice_has_eta_binding')) {
    function job_sales_invoice_has_eta_binding(array $invoiceRow): bool
    {
        foreach (['eta_uuid', 'eta_status', 'eta_submission_id', 'eta_long_id'] as $key) {
            if (trim((string)($invoiceRow[$key] ?? '')) !== '') {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('job_sales_invoice_item_desc')) {
    function job_sales_invoice_item_desc(array $job): string
    {
        $desc = trim((string)($job['job_name'] ?? $job['title'] ?? ''));
        if ($desc !== '') {
            return $desc;
        }
        $jobType = trim((string)($job['job_type'] ?? $job['activity_type'] ?? ''));
        $jobNumber = trim((string)($job['job_number'] ?? $job['order_number'] ?? ''));
        if ($jobType !== '' && $jobNumber !== '') {
            return $jobType . ' - ' . $jobNumber;
        }
        if ($jobNumber !== '') {
            return 'عملية رقم ' . $jobNumber;
        }
        return 'أمر شغل';
    }
}

if (!function_exists('job_sales_invoice_qty')) {
    function job_sales_invoice_qty(array $job): float
    {
        $qty = (float)($job['quantity'] ?? $job['qty'] ?? 0);
        return $qty > 0 ? $qty : 1.0;
    }
}

if (!function_exists('job_sales_invoice_price')) {
    function job_sales_invoice_price(array $job): float
    {
        $price = (float)($job['price'] ?? 0);
        return $price > 0 ? round($price, 2) : 0.0;
    }
}

if (!function_exists('job_sales_invoice_items_payload')) {
    function job_sales_invoice_items_payload(array $job): array
    {
        $qty = job_sales_invoice_qty($job);
        $total = job_sales_invoice_price($job);
        $unitPrice = $qty > 0 ? round($total / $qty, 2) : $total;
        return [[
            'desc' => job_sales_invoice_item_desc($job),
            'qty' => $qty,
            'price' => $unitPrice,
            'total' => $total,
        ]];
    }
}

if (!function_exists('job_sales_invoice_existing_row')) {
    function job_sales_invoice_existing_row(mysqli $conn, int $jobId): ?array
    {
        if ($jobId <= 0) {
            return null;
        }
        $etaColumns = [];
        foreach (['eta_uuid', 'eta_status', 'eta_submission_id', 'eta_long_id'] as $column) {
            $hasColumn = function_exists('app_table_has_column')
                ? app_table_has_column($conn, 'invoices', $column)
                : true;
            $etaColumns[] = $hasColumn
                ? "IFNULL({$column}, '') AS {$column}"
                : "'' AS {$column}";
        }
        $stmt = $conn->prepare("
            SELECT id, items_json, sub_total, total_amount,
                   " . implode(",\n                   ", $etaColumns) . "
            FROM invoices
            WHERE job_id = ?
            ORDER BY id ASC
            LIMIT 1
        ");
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('i', $jobId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        return $row;
    }
}

if (!function_exists('job_sales_invoice_sync_existing_items')) {
    function job_sales_invoice_sync_existing_items(mysqli $conn, int $invoiceId, array $job): void
    {
        if ($invoiceId <= 0) {
            return;
        }
        $existing = job_sales_invoice_existing_row($conn, (int)($job['id'] ?? 0));
        if (!$existing || (int)($existing['id'] ?? 0) !== $invoiceId) {
            return;
        }
        if (job_sales_invoice_has_eta_binding($existing)) {
            return;
        }
        $existingItems = json_decode((string)($existing['items_json'] ?? '[]'), true);
        if (is_array($existingItems) && !empty($existingItems) && trim((string)($existingItems[0]['desc'] ?? '')) !== '') {
            return;
        }
        $itemsJson = json_encode(job_sales_invoice_items_payload($job), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($itemsJson === false) {
            return;
        }
        $subTotal = (float)($existing['sub_total'] ?? 0);
        $safeSubTotal = $subTotal > 0 ? $subTotal : job_sales_invoice_price($job);
        $stmt = $conn->prepare("
            UPDATE invoices
            SET items_json = ?, sub_total = CASE WHEN IFNULL(sub_total, 0) <= 0.00001 THEN ? ELSE sub_total END
            WHERE id = ?
            LIMIT 1
        ");
        if ($stmt) {
            $stmt->bind_param('sdi', $itemsJson, $safeSubTotal, $invoiceId);
            $stmt->execute();
            $stmt->close();
        }
    }
}

if (!function_exists('job_create_sales_invoice_from_job')) {
    function job_create_sales_invoice_from_job(mysqli $conn, array $job, string $createdBy = 'System'): array
    {
        $jobId = (int)($job['id'] ?? 0);
        $clientId = (int)($job['client_id'] ?? 0);
        if ($jobId <= 0 || $clientId <= 0) {
            return ['ok' => false, 'message' => 'invalid_job_or_client'];
        }

        $existing = job_sales_invoice_existing_row($conn, $jobId);
        if ($existing) {
            $invoiceId = (int)($existing['id'] ?? 0);
            if ($invoiceId > 0) {
                job_sales_invoice_sync_existing_items($conn, $invoiceId, $job);
                return ['ok' => true, 'created' => false, 'invoice_id' => $invoiceId];
            }
        }

        $total = job_sales_invoice_price($job);
        $itemsJson = json_encode(job_sales_invoice_items_payload($job), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($itemsJson === false) {
            return ['ok' => false, 'message' => 'items_json_failed'];
        }

        $status = 'unpaid';
        $notes = 'فاتورة مولدة تلقائياً من العملية رقم ' . trim((string)($job['job_number'] ?? $job['order_number'] ?? ('#' . $jobId)));
        $stmt = $conn->prepare("
            INSERT INTO invoices (
                client_id, job_id, items_json, notes, sub_total, total_amount, remaining_amount, inv_date, status
            ) VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), ?)
        ");
        if (!$stmt) {
            return ['ok' => false, 'message' => 'prepare_failed'];
        }
        $stmt->bind_param('iissddds', $clientId, $jobId, $itemsJson, $notes, $total, $total, $total, $status);
        $ok = $stmt->execute();
        $invoiceId = (int)$conn->insert_id;
        $stmt->close();
        if (!$ok || $invoiceId <= 0) {
            return ['ok' => false, 'message' => 'insert_failed'];
        }

        app_assign_document_number($conn, 'invoices', $invoiceId, 'invoice_number', 'invoice', date('Y-m-d'));
        app_apply_client_opening_balance_to_invoice($conn, $invoiceId, $clientId, date('Y-m-d'), $createdBy);
        if (function_exists('app_apply_client_receipt_credit_to_invoice')) {
            app_apply_client_receipt_credit_to_invoice($conn, $invoiceId, $clientId, date('Y-m-d'), $createdBy);
        }

        return ['ok' => true, 'created' => true, 'invoice_id' => $invoiceId];
    }
}

if (!function_exists('job_append_accounting_note')) {
    function job_append_accounting_note(mysqli $conn, int $jobId, string $message): void
    {
        if ($jobId <= 0 || trim($message) === '') {
            return;
        }
        $timestamp = date('Y-m-d H:i');
        $note = "\n[الحسابات {$timestamp}]: " . trim($message);
        $stmt = $conn->prepare("UPDATE job_orders SET notes = CONCAT(IFNULL(notes, ''), ?) WHERE id = ?");
        if ($stmt) {
            $stmt->bind_param('si', $note, $jobId);
            $stmt->execute();
            $stmt->close();
        }
    }
}
