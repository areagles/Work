<?php

if (!function_exists('app_eta_einvoice_signing_modes')) {
    function app_eta_einvoice_signing_modes(): array
    {
        return [
            [
                'key' => 'usb_bridge',
                'name' => 'USB Token Bridge',
                'name_en' => 'USB Token Bridge',
                'requires_hardware' => 1,
                'stores_private_key' => 0,
                'recommended' => 0,
                'notes' => 'التوقيع يتم عبر جهاز أو خدمة محلية متصل بها USB token، والمفتاح الخاص لا يغادر التوكن.',
            ],
            [
                'key' => 'signing_server',
                'name' => 'Dedicated Signing Server',
                'name_en' => 'Dedicated Signing Server',
                'requires_hardware' => 1,
                'stores_private_key' => 0,
                'recommended' => 1,
                'notes' => 'الخيار العملي إذا كان المطلوب التوقيع من داخل النظام دون توصيل الفلاشة في كل محطة عمل.',
            ],
            [
                'key' => 'hsm_cloud',
                'name' => 'HSM / Cloud Seal',
                'name_en' => 'HSM / Cloud Seal',
                'requires_hardware' => 0,
                'stores_private_key' => 0,
                'recommended' => 1,
                'notes' => 'يتطلب مزودًا معتمدًا وسياسة امتثال متوافقة مع ETA وITIDA.',
            ],
        ];
    }
}

if (!function_exists('app_eta_einvoice_default_settings')) {
    function app_eta_einvoice_default_settings(): array
    {
        return [
            'enabled' => 0,
            'environment' => 'preprod',
            'base_url' => 'https://sdk.invoicing.eta.gov.eg',
            'token_url' => 'https://id.preprod.eta.gov.eg/connect/token',
            'client_id' => '',
            'client_secret' => '',
            'client_id_masked' => '',
            'issuer_rin' => '',
            'branch_code' => '',
            'activity_code' => '',
            'default_document_type' => 'i',
            'signing_mode' => 'signing_server',
            'signing_base_url' => '',
            'signing_api_key' => '',
            'callback_api_key' => '',
            'submission_mode' => 'manual_review',
            'unit_catalog' => json_encode([
                ['local' => 'قطعة', 'eta' => 'EA'],
                ['local' => 'كيلو', 'eta' => 'KG'],
                ['local' => 'متر', 'eta' => 'MTR'],
                ['local' => 'علبة', 'eta' => 'BOX'],
                ['local' => 'باكيت', 'eta' => 'PK'],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'item_catalog' => '[]',
            'auto_pull_status' => 1,
            'auto_pull_documents' => 0,
            'last_sync_at' => '',
            'last_submit_at' => '',
            'last_purchase_pull_at' => '',
            'last_purchase_new_count' => '0',
        ];
    }
}

if (!function_exists('app_eta_einvoice_normalize_settings')) {
    function app_eta_einvoice_normalize_settings(array $raw): array
    {
        $defaults = app_eta_einvoice_default_settings();
        $settings = array_merge($defaults, $raw);

        $environment = strtolower(trim((string)($settings['environment'] ?? 'preprod')));
        if (!in_array($environment, ['preprod', 'prod'], true)) {
            $environment = 'preprod';
        }

        $signingMode = strtolower(trim((string)($settings['signing_mode'] ?? 'signing_server')));
        $allowedSigningModes = array_column(app_eta_einvoice_signing_modes(), 'key');
        if (!in_array($signingMode, $allowedSigningModes, true)) {
            $signingMode = 'signing_server';
        }

        $baseUrl = trim((string)($settings['base_url'] ?? ''));
        if ($baseUrl === '') {
            $baseUrl = $environment === 'prod'
                ? 'https://api.invoicing.eta.gov.eg'
                : 'https://sdk.invoicing.eta.gov.eg';
        }
        $baseHost = strtolower((string)parse_url($baseUrl, PHP_URL_HOST));
        if ($environment === 'prod' && $baseHost !== 'api.invoicing.eta.gov.eg') {
            $baseUrl = 'https://api.invoicing.eta.gov.eg';
        }
        if ($environment === 'preprod' && $baseHost !== 'sdk.invoicing.eta.gov.eg') {
            $baseUrl = 'https://sdk.invoicing.eta.gov.eg';
        }
        $tokenUrl = trim((string)($settings['token_url'] ?? ''));
        if ($tokenUrl === '') {
            $tokenUrl = $environment === 'prod'
                ? 'https://id.eta.gov.eg/connect/token'
                : 'https://id.preprod.eta.gov.eg/connect/token';
        }
        $tokenHost = strtolower((string)parse_url($tokenUrl, PHP_URL_HOST));
        if ($environment === 'prod' && $tokenHost !== 'id.eta.gov.eg') {
            $tokenUrl = 'https://id.eta.gov.eg/connect/token';
        }
        if ($environment === 'preprod' && $tokenHost !== 'id.preprod.eta.gov.eg') {
            $tokenUrl = 'https://id.preprod.eta.gov.eg/connect/token';
        }

        $submissionMode = strtolower(trim((string)($settings['submission_mode'] ?? 'manual_review')));
        if (!in_array($submissionMode, ['manual_review', 'queue', 'auto_submit'], true)) {
            $submissionMode = 'manual_review';
        }

        return [
            'enabled' => !empty($settings['enabled']) ? 1 : 0,
            'environment' => $environment,
            'base_url' => rtrim($baseUrl, '/'),
            'token_url' => $tokenUrl,
            'client_id' => trim((string)($settings['client_id'] ?? '')),
            'client_secret' => trim((string)($settings['client_secret'] ?? '')),
            'client_id_masked' => trim((string)($settings['client_id_masked'] ?? '')),
            'issuer_rin' => preg_replace('/\D+/', '', (string)($settings['issuer_rin'] ?? '')),
            'branch_code' => trim((string)($settings['branch_code'] ?? '')),
            'activity_code' => trim((string)($settings['activity_code'] ?? '')),
            'default_document_type' => strtolower(trim((string)($settings['default_document_type'] ?? 'i'))),
            'signing_mode' => $signingMode,
            'signing_base_url' => rtrim(trim((string)($settings['signing_base_url'] ?? '')), '/'),
            'signing_api_key' => trim((string)($settings['signing_api_key'] ?? '')),
            'callback_api_key' => trim((string)($settings['callback_api_key'] ?? '')),
            'submission_mode' => $submissionMode,
            'unit_catalog' => trim((string)($settings['unit_catalog'] ?? '[]')),
            'item_catalog' => trim((string)($settings['item_catalog'] ?? '[]')),
            'auto_pull_status' => !empty($settings['auto_pull_status']) ? 1 : 0,
            'auto_pull_documents' => !empty($settings['auto_pull_documents']) ? 1 : 0,
            'last_sync_at' => trim((string)($settings['last_sync_at'] ?? '')),
            'last_submit_at' => trim((string)($settings['last_submit_at'] ?? '')),
            'last_purchase_pull_at' => trim((string)($settings['last_purchase_pull_at'] ?? '')),
            'last_purchase_new_count' => (string)max(0, (int)($settings['last_purchase_new_count'] ?? 0)),
        ];
    }
}

if (!function_exists('app_eta_einvoice_status_map')) {
    function app_eta_einvoice_status_map(): array
    {
        return [
            'submitted' => ['label' => 'مرسلة', 'label_en' => 'Submitted'],
            'valid' => ['label' => 'مقبولة', 'label_en' => 'Valid'],
            'invalid' => ['label' => 'مرفوضة', 'label_en' => 'Invalid'],
            'cancelled' => ['label' => 'ملغاة', 'label_en' => 'Cancelled'],
            'rejected' => ['label' => 'مرفوضة', 'label_en' => 'Rejected'],
            'unknown' => ['label' => 'غير معروف', 'label_en' => 'Unknown'],
        ];
    }
}

if (!function_exists('app_eta_einvoice_is_financially_inactive_status')) {
    function app_eta_einvoice_is_financially_inactive_status(string $status): bool
    {
        $status = strtolower(trim($status));
        return in_array($status, ['cancelled', 'canceled', 'invalid', 'rejected'], true);
    }
}

if (!function_exists('app_eta_einvoice_release_sales_invoice_financial_links')) {
    function app_eta_einvoice_release_sales_invoice_financial_links(mysqli $conn, int $invoiceId): void
    {
        if ($invoiceId <= 0) {
            return;
        }
        if (function_exists('app_table_exists') && app_table_exists($conn, 'financial_receipt_allocations')) {
            $stmtDeleteAlloc = $conn->prepare("DELETE FROM financial_receipt_allocations WHERE allocation_type = 'sales_invoice' AND target_id = ?");
            if ($stmtDeleteAlloc) {
                $stmtDeleteAlloc->bind_param('i', $invoiceId);
                $stmtDeleteAlloc->execute();
                $stmtDeleteAlloc->close();
            }
        }
        $stmtClearDirect = $conn->prepare("
            UPDATE financial_receipts
            SET invoice_id = NULL
            WHERE invoice_id = ?
              AND type = 'in'
              AND client_id IS NOT NULL
        ");
        if ($stmtClearDirect) {
            $stmtClearDirect->bind_param('i', $invoiceId);
            $stmtClearDirect->execute();
            $stmtClearDirect->close();
        }
    }
}

if (!function_exists('app_eta_einvoice_release_purchase_invoice_financial_links')) {
    function app_eta_einvoice_release_purchase_invoice_financial_links(mysqli $conn, int $purchaseInvoiceId): void
    {
        if ($purchaseInvoiceId <= 0) {
            return;
        }
        if (function_exists('app_table_exists') && app_table_exists($conn, 'financial_receipt_allocations')) {
            $stmtDeleteAlloc = $conn->prepare("DELETE FROM financial_receipt_allocations WHERE allocation_type = 'purchase_invoice' AND target_id = ?");
            if ($stmtDeleteAlloc) {
                $stmtDeleteAlloc->bind_param('i', $purchaseInvoiceId);
                $stmtDeleteAlloc->execute();
                $stmtDeleteAlloc->close();
            }
        }
        $stmtClearDirect = $conn->prepare("
            UPDATE financial_receipts
            SET invoice_id = NULL
            WHERE invoice_id = ?
              AND type = 'out'
              AND supplier_id IS NOT NULL
        ");
        if ($stmtClearDirect) {
            $stmtClearDirect->bind_param('i', $purchaseInvoiceId);
            $stmtClearDirect->execute();
            $stmtClearDirect->close();
        }
    }
}

if (!function_exists('app_eta_einvoice_apply_sales_financial_state')) {
    function app_eta_einvoice_apply_sales_financial_state(mysqli $conn, int $invoiceId, string $etaStatus): void
    {
        if ($invoiceId <= 0) {
            return;
        }
        $etaStatus = strtolower(trim($etaStatus));
        if (app_eta_einvoice_is_financially_inactive_status($etaStatus)) {
            app_eta_einvoice_release_sales_invoice_financial_links($conn, $invoiceId);
            $stmtCancel = $conn->prepare("UPDATE invoices SET paid_amount = 0, remaining_amount = 0, status = 'cancelled' WHERE id = ? LIMIT 1");
            if ($stmtCancel) {
                $stmtCancel->bind_param('i', $invoiceId);
                $stmtCancel->execute();
                $stmtCancel->close();
            }
            return;
        }

        $stmt = $conn->prepare("SELECT total_amount, due_date FROM invoices WHERE id = ? LIMIT 1");
        if (!$stmt) {
            return;
        }
        $stmt->bind_param('i', $invoiceId);
        $stmt->execute();
        $invoice = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$invoice) {
            return;
        }

        $paidRaw = 0.0;
        if (function_exists('financeLegacyDirectAllocatedAmount')) {
            $paidRaw += (float)financeLegacyDirectAllocatedAmount($conn, 'sales_invoice', $invoiceId);
        }
        if (function_exists('financeAllocatedAmountByType')) {
            $paidRaw += (float)financeAllocatedAmountByType($conn, 'sales_invoice', $invoiceId);
        }

        $finalTotal = (float)($invoice['total_amount'] ?? 0);
        $paid = min($finalTotal, $paidRaw);
        $remaining = round($finalTotal - $paid, 2);
        $today = date('Y-m-d');

        if ($remaining <= 0) {
            $status = 'paid';
            $remaining = 0;
        } elseif ($paidRaw > 0) {
            $status = 'partially_paid';
        } elseif ($today <= (string)($invoice['due_date'] ?? '')) {
            $status = 'deferred';
        } else {
            $status = 'overdue';
        }

        $stmtUpd = $conn->prepare("UPDATE invoices SET paid_amount = ?, remaining_amount = ?, status = ? WHERE id = ? LIMIT 1");
        if ($stmtUpd) {
            $stmtUpd->bind_param('ddsi', $paid, $remaining, $status, $invoiceId);
            $stmtUpd->execute();
            $stmtUpd->close();
        }
    }
}

if (!function_exists('app_eta_einvoice_apply_purchase_financial_state')) {
    function app_eta_einvoice_apply_purchase_financial_state(mysqli $conn, int $purchaseInvoiceId, string $etaStatus): void
    {
        if ($purchaseInvoiceId <= 0) {
            return;
        }
        $etaStatus = strtolower(trim($etaStatus));
        if (app_eta_einvoice_is_financially_inactive_status($etaStatus)) {
            app_eta_einvoice_release_purchase_invoice_financial_links($conn, $purchaseInvoiceId);
            $stmtCancel = $conn->prepare("UPDATE purchase_invoices SET paid_amount = 0, remaining_amount = 0, status = 'cancelled' WHERE id = ? LIMIT 1");
            if ($stmtCancel) {
                $stmtCancel->bind_param('i', $purchaseInvoiceId);
                $stmtCancel->execute();
                $stmtCancel->close();
            }
            return;
        }

        $stmt = $conn->prepare("SELECT total_amount FROM purchase_invoices WHERE id = ? LIMIT 1");
        if (!$stmt) {
            return;
        }
        $stmt->bind_param('i', $purchaseInvoiceId);
        $stmt->execute();
        $invoice = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$invoice) {
            return;
        }

        $paidRaw = 0.0;
        if (function_exists('financeLegacyDirectAllocatedAmount')) {
            $paidRaw += (float)financeLegacyDirectAllocatedAmount($conn, 'purchase_invoice', $purchaseInvoiceId);
        }
        if (function_exists('financeAllocatedAmountByType')) {
            $paidRaw += (float)financeAllocatedAmountByType($conn, 'purchase_invoice', $purchaseInvoiceId);
        }
        $finalTotal = (float)($invoice['total_amount'] ?? 0);
        $paid = min($finalTotal, $paidRaw);
        $remaining = round($finalTotal - $paid, 2);
        $status = 'unpaid';
        if ($remaining <= 0) {
            $status = 'paid';
            $remaining = 0;
        } elseif ($paidRaw > 0) {
            $status = 'partially_paid';
        }

        $stmtUpd = $conn->prepare("UPDATE purchase_invoices SET paid_amount = ?, remaining_amount = ?, status = ? WHERE id = ? LIMIT 1");
        if ($stmtUpd) {
            $stmtUpd->bind_param('ddsi', $paid, $remaining, $status, $purchaseInvoiceId);
            $stmtUpd->execute();
            $stmtUpd->close();
        }
    }
}

if (!function_exists('app_eta_einvoice_runtime_readiness')) {
    function app_eta_einvoice_runtime_readiness(mysqli $conn): array
    {
        $settings = app_eta_einvoice_settings($conn);
        $checks = [];
        $checks[] = [
            'key' => 'php_curl',
            'label' => 'PHP cURL',
            'ok' => function_exists('curl_init'),
            'details' => function_exists('curl_init') ? 'available' : 'missing curl extension',
        ];
        $checks[] = [
            'key' => 'eta_enabled',
            'label' => 'ETA enabled',
            'ok' => !empty($settings['enabled']),
            'details' => !empty($settings['enabled']) ? 'enabled' : 'disabled',
        ];
        $checks[] = [
            'key' => 'base_url',
            'label' => 'Base URL',
            'ok' => trim((string)$settings['base_url']) !== '',
            'details' => trim((string)$settings['base_url']),
        ];
        $checks[] = [
            'key' => 'token_url',
            'label' => 'Token URL',
            'ok' => trim((string)$settings['token_url']) !== '',
            'details' => trim((string)$settings['token_url']),
        ];
        $checks[] = [
            'key' => 'client_credentials',
            'label' => 'Client credentials',
            'ok' => trim((string)$settings['client_id']) !== '' && trim((string)$settings['client_secret']) !== '',
            'details' => trim((string)$settings['client_id']) !== '' ? 'client_id set' : 'client_id missing',
        ];
        $checks[] = [
            'key' => 'issuer_profile',
            'label' => 'Issuer profile',
            'ok' => trim((string)$settings['issuer_rin']) !== '' && trim((string)$settings['branch_code']) !== '' && trim((string)$settings['activity_code']) !== '',
            'details' => 'RIN / branch / activity',
        ];
        $requiresSigning = in_array((string)$settings['signing_mode'], ['usb_bridge', 'signing_server', 'hsm_cloud'], true);
        $checks[] = [
            'key' => 'signing_endpoint',
            'label' => 'Signing endpoint',
            'ok' => true,
            'details' => !$requiresSigning
                ? 'not required for current mode'
                : (trim((string)$settings['signing_base_url']) !== '' ? trim((string)$settings['signing_base_url']) : 'optional until submit'),
        ];

        $ok = true;
        foreach ($checks as $check) {
            if (empty($check['ok'])) {
                $ok = false;
                break;
            }
        }

        return [
            'ok' => $ok,
            'settings' => $settings,
            'checks' => $checks,
        ];
    }
}

if (!function_exists('app_eta_einvoice_signing_is_configured')) {
    function app_eta_einvoice_signing_is_configured(array $settings): bool
    {
        $mode = trim((string)($settings['signing_mode'] ?? ''));
        if (!in_array($mode, ['usb_bridge', 'signing_server', 'hsm_cloud'], true)) {
            return true;
        }
        return trim((string)($settings['signing_base_url'] ?? '')) !== '';
    }
}

if (!function_exists('app_eta_einvoice_build_invoice_payload')) {
    function app_eta_einvoice_build_invoice_payload(array $invoice, array $issuer, array $receiver, array $items, array $taxTotals = []): array
    {
        $issueDate = trim((string)($invoice['issue_date'] ?? ''));
        $currency = strtoupper(trim((string)($invoice['currency'] ?? 'EGP')));
        if ($currency === '') {
            $currency = 'EGP';
        }

        $payloadItems = [];
        foreach ($items as $row) {
            if (!is_array($row)) {
                continue;
            }
            $payloadItems[] = [
                'description' => trim((string)($row['description'] ?? '')),
                'itemType' => trim((string)($row['item_type'] ?? 'EGS')),
                'itemCode' => trim((string)($row['item_code'] ?? '')),
                'unitType' => trim((string)($row['unit_type'] ?? 'EA')),
                'quantity' => round((float)($row['quantity'] ?? 0), 5),
                'unitValue' => [
                    'currencySold' => $currency,
                    'amountEGP' => round((float)($row['unit_price'] ?? 0), 5),
                ],
                'salesTotal' => round((float)($row['sales_total'] ?? 0), 5),
                'netTotal' => round((float)($row['net_total'] ?? 0), 5),
                'total' => round((float)($row['total'] ?? 0), 5),
                'taxableItems' => array_values((array)($row['taxable_items'] ?? [])),
            ];
        }

        return [
            'issuer' => $issuer,
            'receiver' => $receiver,
            'documentType' => trim((string)($invoice['document_type'] ?? 'I')),
            'documentTypeVersion' => trim((string)($invoice['document_type_version'] ?? '1.0')),
            'dateTimeIssued' => $issueDate,
            'taxpayerActivityCode' => trim((string)($invoice['activity_code'] ?? ($issuer['activityCode'] ?? ''))),
            'internalID' => trim((string)($invoice['internal_id'] ?? '')),
            'purchaseOrderReference' => trim((string)($invoice['purchase_order_reference'] ?? '')),
            'salesOrderReference' => trim((string)($invoice['sales_order_reference'] ?? '')),
            'payment' => [
                'bankName' => trim((string)($invoice['bank_name'] ?? '')),
                'bankAddress' => trim((string)($invoice['bank_address'] ?? '')),
                'bankAccountNo' => trim((string)($invoice['bank_account_no'] ?? '')),
                'bankAccountIBAN' => trim((string)($invoice['bank_account_iban'] ?? '')),
                'swiftCode' => trim((string)($invoice['swift_code'] ?? '')),
                'terms' => trim((string)($invoice['payment_terms'] ?? '')),
            ],
            'delivery' => [
                'approach' => trim((string)($invoice['delivery_approach'] ?? '')),
                'packaging' => trim((string)($invoice['delivery_packaging'] ?? '')),
                'dateValidity' => trim((string)($invoice['delivery_validity'] ?? '')),
                'exportPort' => trim((string)($invoice['export_port'] ?? '')),
                'countryOfOrigin' => trim((string)($invoice['country_of_origin'] ?? 'EG')),
                'grossWeight' => round((float)($invoice['gross_weight'] ?? 0), 5),
                'netWeight' => round((float)($invoice['net_weight'] ?? 0), 5),
                'terms' => trim((string)($invoice['delivery_terms'] ?? '')),
            ],
            'invoiceLines' => $payloadItems,
            'totalSalesAmount' => round((float)($invoice['subtotal'] ?? 0), 5),
            'totalDiscountAmount' => round((float)($invoice['discount_total'] ?? 0), 5),
            'netAmount' => round((float)($invoice['net_amount'] ?? 0), 5),
            'taxTotals' => array_values($taxTotals),
            'totalAmount' => round((float)($invoice['total_amount'] ?? 0), 5),
            'extraDiscountAmount' => round((float)($invoice['extra_discount_amount'] ?? 0), 5),
            'totalItemsDiscountAmount' => round((float)($invoice['items_discount_total'] ?? 0), 5),
        ];
    }
}

if (!function_exists('app_eta_einvoice_required_master_data')) {
    function app_eta_einvoice_required_master_data(): array
    {
        return [
            'issuer_rin',
            'branch_code',
            'activity_code',
            'customer_tax_registration',
            'eta_item_code_or_gpc',
            'unit_type',
            'document_type',
            'tax_types_mapping',
        ];
    }
}

if (!function_exists('app_eta_einvoice_parse_catalog_text')) {
    function app_eta_einvoice_parse_catalog_text(string $raw, string $leftKey = 'local', string $rightKey = 'eta'): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $rows = [];
            foreach ($decoded as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $left = trim((string)($row[$leftKey] ?? ''));
                $right = trim((string)($row[$rightKey] ?? ''));
                if ($left === '' || $right === '') {
                    continue;
                }
                $rows[] = [$leftKey => $left, $rightKey => $right];
            }
            return $rows;
        }

        $rows = [];
        foreach (preg_split('/\r\n|\r|\n/', $raw) ?: [] as $line) {
            $line = trim((string)$line);
            if ($line === '') {
                continue;
            }
            $parts = array_map('trim', explode('|', $line));
            $left = $parts[0] ?? '';
            $right = $parts[1] ?? '';
            if ($left === '' || $right === '') {
                continue;
            }
            $rows[] = [$leftKey => $left, $rightKey => $right];
        }
        return $rows;
    }
}

if (!function_exists('app_eta_einvoice_normalize_unit_catalog_rows')) {
    function app_eta_einvoice_normalize_unit_catalog_rows(array $rows): array
    {
        $normalized = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $local = trim((string)($row['local'] ?? ''));
            $eta = strtoupper(trim((string)($row['eta'] ?? '')));
            if ($local === '' || $eta === '') {
                continue;
            }
            $normalized[] = ['local' => $local, 'eta' => $eta];
        }
        return array_values($normalized);
    }
}

if (!function_exists('app_eta_einvoice_normalize_item_catalog_rows')) {
    function app_eta_einvoice_normalize_item_catalog_rows(array $rows): array
    {
        $normalized = [];
        $seen = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $local = trim((string)($row['local'] ?? ''));
            $eta = trim((string)($row['eta'] ?? $row['item_code'] ?? ''));
            $codeType = strtoupper(trim((string)($row['code_type'] ?? $row['item_type'] ?? 'EGS')));
            $source = strtolower(trim((string)($row['source'] ?? 'manual')));
            $active = isset($row['active']) ? (int)!empty($row['active']) : 1;
            if (!in_array($codeType, ['EGS', 'GS1'], true)) {
                $codeType = 'EGS';
            }
            if (!in_array($source, ['manual', 'eta_sync'], true)) {
                $source = 'manual';
            }
            if ($local === '' || $eta === '') {
                continue;
            }
            $key = mb_strtolower($local) . '|' . mb_strtolower($eta) . '|' . $codeType;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $normalized[] = [
                'local' => $local,
                'eta' => $eta,
                'code_type' => $codeType,
                'source' => $source,
                'active' => $active,
            ];
        }
        return array_values($normalized);
    }
}

if (!function_exists('app_eta_einvoice_unit_catalog')) {
    function app_eta_einvoice_unit_catalog(mysqli $conn): array
    {
        $settings = app_eta_einvoice_settings($conn);
        return app_eta_einvoice_normalize_unit_catalog_rows(
            app_eta_einvoice_parse_catalog_text((string)($settings['unit_catalog'] ?? '[]'), 'local', 'eta')
        );
    }
}

if (!function_exists('app_eta_einvoice_item_catalog')) {
    function app_eta_einvoice_item_catalog(mysqli $conn): array
    {
        $settings = app_eta_einvoice_settings($conn);
        $rows = app_eta_einvoice_decode_json_list((string)($settings['item_catalog'] ?? '[]'));
        if (empty($rows)) {
            $rows = app_eta_einvoice_parse_catalog_text((string)($settings['item_catalog'] ?? '[]'), 'local', 'eta');
        }
        return app_eta_einvoice_normalize_item_catalog_rows($rows);
    }
}

if (!function_exists('app_eta_einvoice_guess_unit_type')) {
    function app_eta_einvoice_guess_unit_type(mysqli $conn, string $localUnit): string
    {
        $localUnit = trim($localUnit);
        if ($localUnit === '') {
            return 'EA';
        }
        foreach (app_eta_einvoice_unit_catalog($conn) as $row) {
            if (trim((string)$row['local']) === $localUnit) {
                return trim((string)$row['eta']) !== '' ? trim((string)$row['eta']) : 'EA';
            }
        }
        return 'EA';
    }
}

if (!function_exists('app_eta_einvoice_guess_item_code')) {
    function app_eta_einvoice_guess_item_code(mysqli $conn, string $localDescription): string
    {
        $mapping = app_eta_einvoice_guess_item_mapping($conn, $localDescription);
        return (string)($mapping['eta'] ?? '');
    }
}

if (!function_exists('app_eta_einvoice_guess_item_mapping')) {
    function app_eta_einvoice_guess_item_mapping(mysqli $conn, string $localDescription): array
    {
        $needle = trim(mb_strtolower($localDescription));
        if ($needle === '') {
            return [];
        }
        foreach (app_eta_einvoice_item_catalog($conn) as $row) {
            $local = trim(mb_strtolower((string)($row['local'] ?? '')));
            if ($local !== '' && $local === $needle) {
                return $row;
            }
        }
        return [];
    }
}

if (!function_exists('app_eta_einvoice_settings')) {
    function app_eta_einvoice_settings(mysqli $conn): array
    {
        $defaults = app_eta_einvoice_default_settings();
        $raw = [];
        foreach ($defaults as $key => $value) {
            $raw[$key] = app_setting_get($conn, 'eta_einvoice_' . $key, (string)$value);
        }
        return app_eta_einvoice_normalize_settings($raw);
    }
}

if (!function_exists('app_eta_einvoice_http_request')) {
    function app_eta_einvoice_http_request(string $method, string $url, array $headers = [], ?array $payload = null, int $connectTimeout = 15, int $timeout = 60): array
    {
        $method = strtoupper(trim($method));
        $ch = curl_init($url);
        $httpHeaders = $headers;
        if ($payload !== null) {
            $httpHeaders[] = 'Content-Type: application/json';
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $httpHeaders,
            CURLOPT_CONNECTTIMEOUT => $connectTimeout,
            CURLOPT_TIMEOUT => $timeout,
        ]);
        if ($payload !== null) {
            $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $json !== false ? $json : '{}');
        }
        $raw = curl_exec($ch);
        $err = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false || $err !== '') {
            return ['ok' => false, 'code' => $code, 'error' => $err !== '' ? $err : 'request_failed', 'body' => ''];
        }

        $decoded = json_decode((string)$raw, true);
        return [
            'ok' => $code >= 200 && $code < 300,
            'code' => $code,
            'error' => $code >= 200 && $code < 300 ? '' : trim((string)($decoded['error']['message'] ?? $decoded['error'] ?? 'request_failed')),
            'body' => (string)$raw,
            'json' => is_array($decoded) ? $decoded : null,
        ];
    }
}

if (!function_exists('app_eta_einvoice_http_form_request')) {
    function app_eta_einvoice_http_form_request(string $method, string $url, array $headers = [], array $payload = [], int $connectTimeout = 15, int $timeout = 60): array
    {
        $method = strtoupper(trim($method));
        $ch = curl_init($url);
        $httpHeaders = array_merge($headers, ['Content-Type: application/x-www-form-urlencoded']);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $httpHeaders,
            CURLOPT_CONNECTTIMEOUT => $connectTimeout,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_POSTFIELDS => http_build_query($payload),
        ]);
        $raw = curl_exec($ch);
        $err = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false || $err !== '') {
            return ['ok' => false, 'code' => $code, 'error' => $err !== '' ? $err : 'request_failed', 'body' => ''];
        }

        $decoded = json_decode((string)$raw, true);
        return [
            'ok' => $code >= 200 && $code < 300,
            'code' => $code,
            'error' => $code >= 200 && $code < 300 ? '' : trim((string)($decoded['error']['message'] ?? $decoded['error'] ?? 'request_failed')),
            'body' => (string)$raw,
            'json' => is_array($decoded) ? $decoded : null,
        ];
    }
}

if (!function_exists('app_eta_einvoice_access_token_cache')) {
    function app_eta_einvoice_access_token_cache(mysqli $conn): array
    {
        return [
            'token' => trim((string)app_setting_get($conn, 'eta_einvoice_access_token', '')),
            'expires_at' => trim((string)app_setting_get($conn, 'eta_einvoice_access_token_expires_at', '')),
        ];
    }
}

if (!function_exists('app_eta_einvoice_request_access_token')) {
    function app_eta_einvoice_request_access_token(mysqli $conn, bool $forceRefresh = false): array
    {
        $settings = app_eta_einvoice_settings($conn);
        if ($settings['client_id'] === '' || $settings['client_secret'] === '') {
            return ['ok' => false, 'error' => 'missing_client_credentials'];
        }

        $cached = app_eta_einvoice_access_token_cache($conn);
        $nowTs = time();
        $expiresTs = $cached['expires_at'] !== '' ? strtotime($cached['expires_at']) : false;
        if (!$forceRefresh && $cached['token'] !== '' && $expiresTs !== false && $expiresTs > ($nowTs + 120)) {
            return [
                'ok' => true,
                'token' => $cached['token'],
                'expires_at' => date('c', $expiresTs),
                'cached' => true,
            ];
        }

        $basicAuth = base64_encode($settings['client_id'] . ':' . $settings['client_secret']);
        $response = app_eta_einvoice_http_form_request('POST', (string)$settings['token_url'], [
            'Accept: application/json',
            'Authorization: Basic ' . $basicAuth,
        ], [
            'grant_type' => 'client_credentials',
        ]);

        if (!$response['ok']) {
            return [
                'ok' => false,
                'error' => $response['error'] !== '' ? $response['error'] : 'eta_auth_failed',
                'code' => $response['code'],
                'body' => $response['body'],
            ];
        }

        $token = trim((string)($response['json']['access_token'] ?? ''));
        $expiresIn = max(60, (int)($response['json']['expires_in'] ?? 3600));
        if ($token === '') {
            return ['ok' => false, 'error' => 'missing_access_token'];
        }

        $expiresAt = date('Y-m-d H:i:s', $nowTs + $expiresIn);
        app_setting_set($conn, 'eta_einvoice_access_token', $token);
        app_setting_set($conn, 'eta_einvoice_access_token_expires_at', $expiresAt);

        return [
            'ok' => true,
            'token' => $token,
            'expires_at' => $expiresAt,
            'cached' => false,
        ];
    }
}

if (!function_exists('app_eta_einvoice_decode_json_list')) {
    function app_eta_einvoice_decode_json_list($raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }
        $decoded = json_decode((string)$raw, true);
        return is_array($decoded) ? $decoded : [];
    }
}

if (!function_exists('app_eta_einvoice_invoice_context')) {
    function app_eta_einvoice_invoice_context(mysqli $conn, int $invoiceId): array
    {
        if ($invoiceId <= 0) {
            return ['ok' => false, 'error' => 'invalid_invoice_id'];
        }

        $sql = "
            SELECT
                i.*,
                c.name AS client_name,
                c.phone AS client_phone,
                c.email AS client_email,
                c.address AS client_address,
                c.tax_number AS client_tax_number,
                c.tax_id AS client_tax_id,
                c.national_id AS client_national_id,
                c.country_code AS client_country_code,
                c.eta_receiver_type AS client_receiver_type
            FROM invoices i
            LEFT JOIN clients c ON c.id = i.client_id
            WHERE i.id = ?
            LIMIT 1
        ";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return ['ok' => false, 'error' => 'prepare_failed'];
        }
        $stmt->bind_param('i', $invoiceId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) {
            return ['ok' => false, 'error' => 'invoice_not_found'];
        }

        $items = app_eta_einvoice_decode_json_list((string)($row['items_json'] ?? '[]'));
        $taxLines = app_eta_einvoice_decode_json_list((string)($row['taxes_json'] ?? '[]'));

        return [
            'ok' => true,
            'invoice' => $row,
            'items' => $items,
            'tax_lines' => $taxLines,
        ];
    }
}

if (!function_exists('app_eta_einvoice_issuer_profile')) {
    function app_eta_einvoice_issuer_profile(mysqli $conn, array $settings): array
    {
        return [
            'address' => [
                'branchID' => trim((string)$settings['branch_code']),
                'country' => 'EG',
                'governate' => '',
                'regionCity' => '',
                'street' => trim((string)app_setting_get($conn, 'org_address', '')),
                'buildingNumber' => '',
                'postalCode' => '',
                'floor' => '',
                'room' => '',
                'landmark' => '',
                'additionalInformation' => '',
            ],
            'type' => 'B',
            'id' => trim((string)$settings['issuer_rin']),
            'name' => trim((string)app_setting_get($conn, 'org_legal_name', app_setting_get($conn, 'org_name', 'Arab Eagles'))),
            'tradeName' => trim((string)app_setting_get($conn, 'org_name', 'Arab Eagles')),
            'branchCode' => trim((string)$settings['branch_code']),
            'activityCode' => trim((string)$settings['activity_code']),
        ];
    }
}

if (!function_exists('app_eta_einvoice_receiver_profile')) {
    function app_eta_einvoice_receiver_profile(array $invoiceRow): array
    {
        $name = trim((string)($invoiceRow['client_name'] ?? 'عميل'));
        $receiverType = strtoupper(trim((string)($invoiceRow['client_receiver_type'] ?? 'B')));
        if (!in_array($receiverType, ['B', 'P', 'F'], true)) {
            $receiverType = 'B';
        }
        $receiverId = trim((string)($invoiceRow['client_tax_number'] ?? $invoiceRow['client_tax_id'] ?? ''));
        if ($receiverType === 'P' && trim((string)($invoiceRow['client_national_id'] ?? '')) !== '') {
            $receiverId = trim((string)$invoiceRow['client_national_id']);
        }
        return [
            'type' => $receiverType,
            'id' => $receiverId,
            'name' => $name !== '' ? $name : 'عميل',
            'mobileNumber' => trim((string)($invoiceRow['client_phone'] ?? '')),
            'email' => trim((string)($invoiceRow['client_email'] ?? '')),
            'address' => [
                'country' => trim((string)($invoiceRow['client_country_code'] ?? 'EG')) !== '' ? trim((string)$invoiceRow['client_country_code']) : 'EG',
                'governate' => '',
                'regionCity' => '',
                'street' => trim((string)($invoiceRow['client_address'] ?? '')),
                'buildingNumber' => '',
                'postalCode' => '',
                'floor' => '',
                'room' => '',
                'landmark' => '',
                'additionalInformation' => '',
            ],
        ];
    }
}

if (!function_exists('app_eta_einvoice_item_row_from_local')) {
    function app_eta_einvoice_item_row_from_local(mysqli $conn, array $row): array
    {
        $description = trim((string)($row['name'] ?? $row['description'] ?? $row['desc'] ?? $row['title'] ?? ''));
        $qty = round((float)($row['qty'] ?? $row['quantity'] ?? 1), 5);
        $unitPrice = round((float)($row['price'] ?? $row['unit_price'] ?? $row['rate'] ?? 0), 5);
        $salesTotal = round((float)($row['sub_total'] ?? $row['subtotal'] ?? ($qty * $unitPrice)), 5);
        $lineTotal = round((float)($row['total'] ?? $row['line_total'] ?? $salesTotal), 5);
        $itemCode = trim((string)($row['item_code'] ?? $row['sku'] ?? ''));
        $itemType = trim((string)($row['item_type'] ?? ''));
        if ($itemCode === '') {
            $mapping = app_eta_einvoice_guess_item_mapping($conn, $description);
            $itemCode = trim((string)($mapping['eta'] ?? ''));
            if ($itemType === '' && !empty($mapping['code_type'])) {
                $itemType = trim((string)$mapping['code_type']);
            }
        }
        $unitType = trim((string)($row['unit_type'] ?? ''));
        if ($unitType === '') {
            $unitType = app_eta_einvoice_guess_unit_type($conn, trim((string)($row['unit'] ?? '')));
        }
        $taxableItems = [];
        foreach (app_eta_einvoice_decode_json_list($row['taxable_items'] ?? []) as $tax) {
            if (!is_array($tax)) {
                continue;
            }
            $taxableItems[] = [
                'taxType' => trim((string)($tax['taxType'] ?? $tax['tax_type'] ?? 'T1')),
                'subType' => trim((string)($tax['subType'] ?? $tax['sub_type'] ?? 'V001')),
                'rate' => round((float)($tax['rate'] ?? 0), 5),
                'amount' => round((float)($tax['amount'] ?? 0), 5),
            ];
        }

        return [
            'description' => $description,
            'item_type' => $itemType !== '' ? $itemType : 'EGS',
            'item_code' => $itemCode,
            'unit_type' => $unitType !== '' ? $unitType : 'EA',
            'quantity' => $qty,
            'unit_price' => $unitPrice,
            'sales_total' => $salesTotal,
            'net_total' => round((float)($row['net_total'] ?? $salesTotal), 5),
            'total' => $lineTotal,
            'taxable_items' => $taxableItems,
        ];
    }
}

if (!function_exists('app_eta_einvoice_tax_totals_from_local')) {
    function app_eta_einvoice_tax_totals_from_local(array $taxLines): array
    {
        $grouped = [];
        foreach ($taxLines as $tax) {
            if (!is_array($tax)) {
                continue;
            }
            $taxType = trim((string)($tax['eta_tax_type'] ?? $tax['taxType'] ?? $tax['tax_type'] ?? ''));
            if ($taxType === '') {
                $category = strtolower(trim((string)($tax['category'] ?? '')));
                $taxType = $category === 'vat' ? 'T1' : 'T4';
            }
            $amount = (float)($tax['amount'] ?? $tax['tax_amount'] ?? 0);
            if (!isset($grouped[$taxType])) {
                $grouped[$taxType] = 0.0;
            }
            $grouped[$taxType] += $amount;
        }

        $totals = [];
        foreach ($grouped as $taxType => $amount) {
            $totals[] = [
                'taxType' => $taxType,
                'amount' => round((float)$amount, 5),
            ];
        }
        return $totals;
    }
}

if (!function_exists('app_eta_einvoice_prepare_invoice_payload')) {
    function app_eta_einvoice_prepare_invoice_payload(mysqli $conn, int $invoiceId): array
    {
        $settings = app_eta_einvoice_settings($conn);
        $context = app_eta_einvoice_invoice_context($conn, $invoiceId);
        if (empty($context['ok'])) {
            return $context;
        }

        $invoice = (array)$context['invoice'];
        $localItems = (array)$context['items'];
        $taxLines = (array)$context['tax_lines'];
        $errors = [];

        if ((int)($invoice['client_id'] ?? 0) <= 0) {
            $errors[] = 'فاتورة المبيعات يجب أن تكون مرتبطة بعميل.';
        }
        $receiverProfile = app_eta_einvoice_receiver_profile($invoice);
        if (trim((string)($receiverProfile['id'] ?? '')) === '') {
            $errors[] = 'العميل لا يحتوي معرف ETA صالحًا (رقم ضريبي/Tax ID أو رقم قومي حسب نوع المستقبل).';
        }
        if (trim((string)($invoice['invoice_number'] ?? '')) === '') {
            $errors[] = 'رقم الفاتورة المحلي غير موجود.';
        }
        if (empty($localItems)) {
            $errors[] = 'الفاتورة لا تحتوي بنودًا.';
        }
        if ($settings['issuer_rin'] === '' || $settings['branch_code'] === '' || $settings['activity_code'] === '') {
            $errors[] = 'إعدادات ETA الأساسية غير مكتملة: issuer RIN / branch code / activity code.';
        }

        $payloadItems = [];
        foreach ($localItems as $row) {
            $mapped = app_eta_einvoice_item_row_from_local($conn, (array)$row);
            if ($mapped['description'] === '') {
                $errors[] = 'يوجد بند فاتورة بدون وصف.';
            }
            if ($mapped['item_code'] === '') {
                $errors[] = 'يوجد بند فاتورة بدون ETA item code / SKU mapped.';
            }
            $payloadItems[] = $mapped;
        }

        if (!empty($errors)) {
            return ['ok' => false, 'error' => 'eta_payload_validation_failed', 'messages' => array_values(array_unique($errors))];
        }

        $invoiceDate = trim((string)($invoice['inv_date'] ?? date('Y-m-d')));
        if (strlen($invoiceDate) === 10) {
            $invoiceDate .= 'T12:00:00Z';
        }

        $payload = app_eta_einvoice_build_invoice_payload([
            'issue_date' => $invoiceDate,
            'currency' => 'EGP',
            'document_type' => strtoupper(trim((string)($settings['default_document_type'] ?: 'i'))),
            'document_type_version' => '1.0',
            'activity_code' => $settings['activity_code'],
            'internal_id' => trim((string)($invoice['invoice_number'] ?? ('INV-' . $invoiceId))),
            'subtotal' => (float)($invoice['sub_total'] ?? 0),
            'discount_total' => (float)($invoice['discount'] ?? 0),
            'net_amount' => (float)(($invoice['sub_total'] ?? 0) - ($invoice['discount'] ?? 0)),
            'total_amount' => (float)($invoice['total_amount'] ?? 0),
            'extra_discount_amount' => 0,
            'items_discount_total' => (float)($invoice['discount'] ?? 0),
        ], app_eta_einvoice_issuer_profile($conn, $settings), $receiverProfile, $payloadItems, app_eta_einvoice_tax_totals_from_local($taxLines));

        return [
            'ok' => true,
            'invoice' => $invoice,
            'items' => $payloadItems,
            'payload' => $payload,
            'payload_hash' => hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
        ];
    }
}

if (!function_exists('app_eta_einvoice_queue_invoice')) {
    function app_eta_einvoice_queue_invoice(mysqli $conn, int $invoiceId, int $userId = 0): array
    {
        $prepared = app_eta_einvoice_prepare_invoice_payload($conn, $invoiceId);
        if (empty($prepared['ok'])) {
            return $prepared;
        }

        $invoice = (array)$prepared['invoice'];
        $settings = app_eta_einvoice_settings($conn);
        $payloadJson = json_encode($prepared['payload'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($payloadJson) || $payloadJson === '') {
            return ['ok' => false, 'error' => 'payload_encode_failed'];
        }

        $internalNumber = trim((string)($invoice['invoice_number'] ?? ('INV-' . $invoiceId)));
        $status = $settings['submission_mode'] === 'manual_review' ? 'draft' : 'queued';
        $stmt = $conn->prepare("
            INSERT INTO eta_outbox (
                invoice_id, internal_number, payload_json, payload_hash, signing_mode, queue_status,
                created_by_user_id, queued_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                internal_number = VALUES(internal_number),
                payload_json = VALUES(payload_json),
                payload_hash = VALUES(payload_hash),
                signing_mode = VALUES(signing_mode),
                queue_status = VALUES(queue_status),
                created_by_user_id = VALUES(created_by_user_id),
                queued_at = VALUES(queued_at),
                updated_at = CURRENT_TIMESTAMP
        ");
        if (!$stmt) {
            return ['ok' => false, 'error' => 'outbox_prepare_failed'];
        }

        $queuedAt = $status === 'queued' ? date('Y-m-d H:i:s') : null;
        $invoiceIdInt = $invoiceId;
        $createdBy = $userId > 0 ? $userId : null;
        $stmt->bind_param(
            'isssssis',
            $invoiceIdInt,
            $internalNumber,
            $payloadJson,
            $prepared['payload_hash'],
            $settings['signing_mode'],
            $status,
            $createdBy,
            $queuedAt
        );
        $ok = $stmt->execute();
        $outboxId = (int)$stmt->insert_id;
        $stmt->close();

        if (!$ok) {
            return ['ok' => false, 'error' => 'outbox_insert_failed'];
        }
        if ($outboxId <= 0) {
            $stmtFetch = $conn->prepare("SELECT id FROM eta_outbox WHERE invoice_id = ? LIMIT 1");
            if ($stmtFetch) {
                $stmtFetch->bind_param('i', $invoiceIdInt);
                $stmtFetch->execute();
                $fetchRow = $stmtFetch->get_result()->fetch_assoc();
                $stmtFetch->close();
                $outboxId = (int)($fetchRow['id'] ?? 0);
            }
        }

        app_audit_log_add($conn, 'eta.invoice_queued', [
            'entity_type' => 'invoice',
            'entity_key' => (string)$invoiceId,
            'details' => [
                'outbox_status' => $status,
                'signing_mode' => $settings['signing_mode'],
                'internal_number' => $internalNumber,
            ],
        ]);

        return [
            'ok' => true,
            'invoice_id' => $invoiceId,
            'outbox_id' => $outboxId,
            'queue_status' => $status,
            'payload_hash' => $prepared['payload_hash'],
        ];
    }
}

if (!function_exists('app_eta_einvoice_api_paths')) {
    function app_eta_einvoice_api_paths(): array
    {
        return [
            'token' => '/connect/token',
            'submit' => '/api/v1.0/documentsubmissions/',
            'submission_details' => '/api/v1.0/documentsubmissions/',
            'document_details' => '/api/v1.0/documents/',
            'document_raw' => '/api/v1.0/documents/',
            'search_documents' => '/api/v1.0/documents/search',
            'published_codes' => '/api/v1.0/codetypes/{codeType}/codes',
            'my_code_usage_requests' => '/api/v1.0/codetypes/requests/my',
            'create_egs_code' => '/api/v1.0/codetypes/requests/codes',
        ];
    }
}

if (!function_exists('app_eta_einvoice_build_api_url')) {
    function app_eta_einvoice_build_api_url(array $settings, string $path): string
    {
        return rtrim((string)$settings['base_url'], '/') . '/' . ltrim($path, '/');
    }
}

if (!function_exists('app_eta_einvoice_fetch_published_codes')) {
    function app_eta_einvoice_fetch_published_codes(mysqli $conn, string $codeType, array $filters = []): array
    {
        $codeType = strtoupper(trim($codeType));
        if (!in_array($codeType, ['EGS', 'GS1'], true)) {
            return ['ok' => false, 'error' => 'invalid_code_type'];
        }
        $settings = app_eta_einvoice_settings($conn);
        $token = app_eta_einvoice_request_access_token($conn);
        if (empty($token['ok'])) {
            return $token;
        }
        $path = str_replace('{codeType}', rawurlencode($codeType), app_eta_einvoice_api_paths()['published_codes']);
        $url = app_eta_einvoice_build_api_url($settings, $path);
        $query = [];
        $allowed = ['CodeLookupValue', 'ParentCodeLookupValue', 'CodeID', 'CodeName', 'CodeDescription', 'TaxpayerRIN', 'ParentCodeID', 'ParentLevelName', 'OnlyActive', 'ActiveFrom', 'ActiveTo', 'Ps', 'Pn', 'CodeTypeLevelNumber'];
        foreach ($allowed as $key) {
            if (!isset($filters[$key]) || $filters[$key] === '' || $filters[$key] === null) {
                continue;
            }
            $query[$key] = is_bool($filters[$key]) ? ($filters[$key] ? 'true' : 'false') : (string)$filters[$key];
        }
        if (!empty($query)) {
            $url .= '?' . http_build_query($query);
        }
        $response = app_eta_einvoice_http_request('GET', $url, [
            'Authorization: Bearer ' . $token['token'],
            'Accept: application/json',
        ], null, 15, 60);
        if ((int)($response['code'] ?? 0) !== 200) {
            return [
                'ok' => false,
                'status' => (int)($response['code'] ?? 0),
                'error' => 'eta_codes_fetch_failed',
                'body' => (string)($response['body'] ?? ''),
            ];
        }
        $decoded = is_array($response['json'] ?? null)
            ? (array)$response['json']
            : json_decode((string)($response['body'] ?? ''), true);
        $items = [];
        if (is_array($decoded)) {
            $items = isset($decoded['result']) && is_array($decoded['result']) ? $decoded['result'] : $decoded;
        }
        return ['ok' => true, 'items' => is_array($items) ? array_values($items) : []];
    }
}

if (!function_exists('app_eta_einvoice_sync_item_catalog_from_eta')) {
    function app_eta_einvoice_sync_item_catalog_from_eta(mysqli $conn, array $filters = []): array
    {
        $manualRows = [];
        foreach (app_eta_einvoice_item_catalog($conn) as $row) {
            if (($row['source'] ?? 'manual') === 'manual') {
                $manualRows[] = $row;
            }
        }
        $syncedRows = [];
        foreach (['EGS', 'GS1'] as $codeType) {
            $result = app_eta_einvoice_fetch_published_codes($conn, $codeType, $filters);
            if (empty($result['ok'])) {
                return $result;
            }
            foreach ((array)($result['items'] ?? []) as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $local = trim((string)($item['codeNameSecondaryLang'] ?? $item['codeNamePrimaryLang'] ?? $item['codeDescriptionSecondaryLang'] ?? $item['codeDescriptionPrimaryLang'] ?? ''));
                $eta = trim((string)($item['CodeLookupValue'] ?? $item['codeLookupValue'] ?? ''));
                if ($local === '' || $eta === '') {
                    continue;
                }
                $syncedRows[] = [
                    'local' => $local,
                    'eta' => $eta,
                    'code_type' => $codeType,
                    'source' => 'eta_sync',
                    'active' => isset($item['active']) ? (int)!empty($item['active']) : 1,
                ];
            }
        }
        $catalog = app_eta_einvoice_normalize_item_catalog_rows(array_merge($manualRows, $syncedRows));
        $encoded = json_encode($catalog, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        app_setting_set($conn, 'eta_einvoice_item_catalog', is_string($encoded) ? $encoded : '[]');
        app_setting_set($conn, 'eta_einvoice_last_sync_at', gmdate('Y-m-d H:i:s'));
        return ['ok' => true, 'count' => count($catalog), 'items' => $catalog];
    }
}

if (!function_exists('app_eta_einvoice_fetch_my_code_usage_requests')) {
    function app_eta_einvoice_fetch_my_code_usage_requests(mysqli $conn, array $filters = []): array
    {
        $settings = app_eta_einvoice_settings($conn);
        $token = app_eta_einvoice_request_access_token($conn);
        if (empty($token['ok'])) {
            return $token;
        }
        $url = app_eta_einvoice_build_api_url($settings, app_eta_einvoice_api_paths()['my_code_usage_requests']);
        $query = [];
        $allowed = ['ItemCode', 'CodeName', 'CodeDescription', 'ParentLevelName', 'ParentItemCode', 'ActiveFrom', 'ActiveTo', 'Active', 'Status', 'RequestType', 'OrderDirections', 'Pn', 'Ps'];
        foreach ($allowed as $key) {
            if (!isset($filters[$key]) || $filters[$key] === '' || $filters[$key] === null) {
                continue;
            }
            $query[$key] = is_bool($filters[$key]) ? ($filters[$key] ? 'true' : 'false') : (string)$filters[$key];
        }
        if (!empty($query)) {
            $url .= '?' . http_build_query($query);
        }
        $response = app_eta_einvoice_http_request('GET', $url, [
            'Authorization: Bearer ' . $token['token'],
            'Accept: application/json',
        ], null, 15, 60);
        if ((int)($response['code'] ?? 0) !== 200) {
            return [
                'ok' => false,
                'status' => (int)($response['code'] ?? 0),
                'error' => 'eta_my_codes_fetch_failed',
                'body' => (string)($response['body'] ?? ''),
            ];
        }
        $decoded = is_array($response['json'] ?? null)
            ? (array)$response['json']
            : json_decode((string)($response['body'] ?? ''), true);
        $items = [];
        if (is_array($decoded)) {
            $items = isset($decoded['result']) && is_array($decoded['result']) ? $decoded['result'] : $decoded;
        }
        return ['ok' => true, 'items' => is_array($items) ? array_values($items) : []];
    }
}

if (!function_exists('app_eta_einvoice_sync_my_item_catalog_from_eta')) {
    function app_eta_einvoice_sync_my_item_catalog_from_eta(mysqli $conn, array $filters = []): array
    {
        $manualRows = [];
        foreach (app_eta_einvoice_item_catalog($conn) as $row) {
            if (($row['source'] ?? 'manual') === 'manual') {
                $manualRows[] = $row;
            }
        }

        $defaults = [
            'Active' => true,
            'Status' => 'Approved',
            'OrderDirections' => 'Descending',
            'Pn' => 1,
            'Ps' => 100,
        ];
        $baseFilters = array_merge($defaults, $filters);
        $requestedCodeType = strtoupper(trim((string)($baseFilters['CodeType'] ?? '')));
        if (!in_array($requestedCodeType, ['EGS', 'GS1'], true)) {
            $requestedCodeType = '';
        }
        $allItems = [];
        $seenKeys = [];
        $page = max(1, (int)($baseFilters['Pn'] ?? 1));
        $pageSize = max(1, (int)($baseFilters['Ps'] ?? 100));
        $maxPages = 100;

        for ($i = 0; $i < $maxPages; $i++, $page++) {
            $requestFilters = array_merge($baseFilters, [
                'Pn' => $page,
                'Ps' => $pageSize,
            ]);
            $result = app_eta_einvoice_fetch_my_code_usage_requests($conn, $requestFilters);
            if (empty($result['ok']) && (int)($result['status'] ?? 0) === 429) {
                sleep(5);
                $result = app_eta_einvoice_fetch_my_code_usage_requests($conn, $requestFilters);
            }
            if (empty($result['ok'])) {
                return $result;
            }
            $pageItems = array_values((array)($result['items'] ?? []));
            if (empty($pageItems)) {
                break;
            }
            $newOnPage = 0;
            foreach ($pageItems as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $itemCode = trim((string)($item['itemCode'] ?? ''));
                $codeType = strtoupper(trim((string)($item['codeTypeName'] ?? $item['codeType'] ?? 'EGS')));
                $seenKey = $codeType . '|' . $itemCode;
                if ($itemCode === '' || isset($seenKeys[$seenKey])) {
                    continue;
                }
                $seenKeys[$seenKey] = true;
                $allItems[] = $item;
                $newOnPage++;
            }
            if ($newOnPage === 0) {
                break;
            }
            sleep(5);
        }

        $syncedRows = [];
        foreach ($allItems as $item) {
            if (!is_array($item)) {
                continue;
            }
            $local = trim((string)($item['codeNameSecondaryLang'] ?? $item['codeNamePrimaryLang'] ?? $item['descriptionSecondaryLang'] ?? $item['descriptionPrimaryLang'] ?? $item['codeName'] ?? $item['description'] ?? $item['codeDescription'] ?? ''));
            $eta = trim((string)($item['itemCode'] ?? ''));
            $codeType = strtoupper(trim((string)($item['codeTypeName'] ?? $item['codeType'] ?? 'EGS')));
            if (!in_array($codeType, ['EGS', 'GS1'], true)) {
                $codeType = 'EGS';
            }
            if ($requestedCodeType !== '' && $codeType !== $requestedCodeType) {
                continue;
            }
            if ($local === '' || $eta === '') {
                continue;
            }
            $syncedRows[] = [
                'local' => $local,
                'eta' => $eta,
                'code_type' => $codeType,
                'source' => 'eta_sync',
                'active' => isset($item['active']) ? (int)!empty($item['active']) : 1,
            ];
        }

        $catalog = app_eta_einvoice_normalize_item_catalog_rows(array_merge($manualRows, $syncedRows));
        $encoded = json_encode($catalog, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        app_setting_set($conn, 'eta_einvoice_item_catalog', is_string($encoded) ? $encoded : '[]');
        app_setting_set($conn, 'eta_einvoice_last_sync_at', gmdate('Y-m-d H:i:s'));
        return ['ok' => true, 'count' => count($catalog), 'items' => $catalog];
    }
}

if (!function_exists('app_eta_einvoice_log_sync_event')) {
    function app_eta_einvoice_log_sync_event(mysqli $conn, ?int $outboxId, ?int $invoiceId, string $eventType, string $statusBefore, string $statusAfter, string $responseCode = '', $responseJson = null): void
    {
        $responseText = '';
        if (is_array($responseJson)) {
            $encoded = json_encode($responseJson, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $responseText = is_string($encoded) ? $encoded : '';
        } elseif (is_string($responseJson)) {
            $responseText = $responseJson;
        }
        $stmt = $conn->prepare("
            INSERT INTO eta_sync_log (
                outbox_id, invoice_id, event_type, status_before, status_after, response_code, response_json
            ) VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        if (!$stmt) {
            return;
        }
        $stmt->bind_param('iisssss', $outboxId, $invoiceId, $eventType, $statusBefore, $statusAfter, $responseCode, $responseText);
        $stmt->execute();
        $stmt->close();
    }
}

if (!function_exists('app_eta_einvoice_log_error')) {
    function app_eta_einvoice_log_error(mysqli $conn, ?int $invoiceId, ?int $outboxId, string $phase, string $errorCode, string $errorMessage, $context = null): void
    {
        $contextJson = '';
        if (is_array($context)) {
            $encoded = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $contextJson = is_string($encoded) ? $encoded : '';
        } elseif (is_string($context)) {
            $contextJson = $context;
        }
        $stmt = $conn->prepare("
            INSERT INTO eta_error_log (
                invoice_id, outbox_id, phase, error_code, error_message, context_json
            ) VALUES (?, ?, ?, ?, ?, ?)
        ");
        if (!$stmt) {
            return;
        }
        $stmt->bind_param('iissss', $invoiceId, $outboxId, $phase, $errorCode, $errorMessage, $contextJson);
        $stmt->execute();
        $stmt->close();
    }
}

if (!function_exists('app_eta_einvoice_recent_logs')) {
    function app_eta_einvoice_recent_logs(mysqli $conn, int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));
        $syncRows = [];
        $errorRows = [];

        $syncSql = "
            SELECT l.*, i.invoice_number
            FROM eta_sync_log l
            LEFT JOIN invoices i ON i.id = l.invoice_id
            ORDER BY l.id DESC
            LIMIT {$limit}
        ";
        if ($res = $conn->query($syncSql)) {
            while ($row = $res->fetch_assoc()) {
                $syncRows[] = $row;
            }
        }

        $errorSql = "
            SELECT e.*, i.invoice_number
            FROM eta_error_log e
            LEFT JOIN invoices i ON i.id = e.invoice_id
            ORDER BY e.id DESC
            LIMIT {$limit}
        ";
        if ($res = $conn->query($errorSql)) {
            while ($row = $res->fetch_assoc()) {
                $errorRows[] = $row;
            }
        }

        return [
            'sync' => $syncRows,
            'errors' => $errorRows,
        ];
    }
}

if (!function_exists('app_eta_einvoice_outbox_summary')) {
    function app_eta_einvoice_outbox_summary(mysqli $conn): array
    {
        $summary = [
            'total' => 0,
            'queued' => 0,
            'signed' => 0,
            'submitted' => 0,
            'synced' => 0,
            'failed' => 0,
        ];
        $sql = "
            SELECT queue_status, COUNT(*) AS cnt
            FROM eta_outbox
            GROUP BY queue_status
        ";
        if ($res = $conn->query($sql)) {
            while ($row = $res->fetch_assoc()) {
                $status = trim((string)($row['queue_status'] ?? ''));
                $cnt = (int)($row['cnt'] ?? 0);
                $summary['total'] += $cnt;
                if (array_key_exists($status, $summary)) {
                    $summary[$status] = $cnt;
                }
            }
        }
        return $summary;
    }
}

if (!function_exists('app_eta_einvoice_test_connection')) {
    function app_eta_einvoice_test_connection(mysqli $conn): array
    {
        $readiness = app_eta_einvoice_runtime_readiness($conn);
        if (empty($readiness['ok'])) {
            return [
                'ok' => false,
                'error' => 'eta_not_ready',
                'readiness' => $readiness,
            ];
        }
        $auth = app_eta_einvoice_request_access_token($conn, true);
        if (empty($auth['ok'])) {
            return [
                'ok' => false,
                'error' => (string)($auth['error'] ?? 'eta_auth_failed'),
                'code' => (int)($auth['code'] ?? 0),
                'body' => $auth['body'] ?? '',
                'readiness' => $readiness,
            ];
        }
        return [
            'ok' => true,
            'token_cached' => !empty($auth['cached']) ? 1 : 0,
            'expires_at' => (string)($auth['expires_at'] ?? ''),
            'readiness' => $readiness,
        ];
    }
}

if (!function_exists('app_eta_einvoice_outbox_row')) {
    function app_eta_einvoice_outbox_row(mysqli $conn, int $outboxId): ?array
    {
        $stmt = $conn->prepare("SELECT * FROM eta_outbox WHERE id = ? LIMIT 1");
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('i', $outboxId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return is_array($row) ? $row : null;
    }
}

if (!function_exists('app_eta_einvoice_mark_invoice_status')) {
    function app_eta_einvoice_mark_invoice_status(mysqli $conn, int $invoiceId, array $fields): void
    {
        if ($invoiceId <= 0 || empty($fields)) {
            return;
        }
        $allowed = [
            'eta_uuid' => 's',
            'eta_status' => 's',
            'eta_submission_id' => 's',
            'eta_last_sync_at' => 's',
            'eta_validation_json' => 's',
        ];
        $sets = [];
        $types = '';
        $values = [];
        foreach ($fields as $key => $value) {
            if (!isset($allowed[$key])) {
                continue;
            }
            $sets[] = $key . ' = ?';
            $types .= $allowed[$key];
            $values[] = $value;
        }
        if (empty($sets)) {
            return;
        }
        $types .= 'i';
        $values[] = $invoiceId;
        $sql = "UPDATE invoices SET " . implode(', ', $sets) . " WHERE id = ? LIMIT 1";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return;
        }
        $stmt->bind_param($types, ...$values);
        $stmt->execute();
        $stmt->close();
    }
}

if (!function_exists('app_eta_einvoice_mark_purchase_invoice_status')) {
    function app_eta_einvoice_mark_purchase_invoice_status(mysqli $conn, int $purchaseInvoiceId, array $fields): void
    {
        if ($purchaseInvoiceId <= 0 || empty($fields)) {
            return;
        }
        $allowed = [
            'eta_uuid' => 's',
            'eta_status' => 's',
            'eta_submission_id' => 's',
            'eta_long_id' => 's',
            'eta_last_sync_at' => 's',
            'eta_validation_json' => 's',
        ];
        $sets = [];
        $types = '';
        $values = [];
        foreach ($fields as $key => $value) {
            if (!isset($allowed[$key])) {
                continue;
            }
            $sets[] = $key . ' = ?';
            $types .= $allowed[$key];
            $values[] = $value;
        }
        if (empty($sets)) {
            return;
        }
        $types .= 'i';
        $values[] = $purchaseInvoiceId;
        $sql = "UPDATE purchase_invoices SET " . implode(', ', $sets) . " WHERE id = ? LIMIT 1";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return;
        }
        $stmt->bind_param($types, ...$values);
        $stmt->execute();
        $stmt->close();
    }
}

if (!function_exists('app_eta_einvoice_upsert_document_map')) {
    function app_eta_einvoice_upsert_document_map(mysqli $conn, int $invoiceId, string $internalNumber, string $uuid, string $submissionId = '', string $status = '', string $documentType = 'I', string $issuedAt = '', string $longId = ''): void
    {
        if ($invoiceId <= 0 || $uuid === '') {
            return;
        }
        $pulledAt = date('Y-m-d H:i:s');
        $stmt = $conn->prepare("
            INSERT INTO eta_document_map (
                invoice_id, internal_number, eta_uuid, eta_submission_id, eta_long_id, eta_status, document_type, issued_at, last_pulled_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                internal_number = VALUES(internal_number),
                eta_submission_id = VALUES(eta_submission_id),
                eta_long_id = VALUES(eta_long_id),
                eta_status = VALUES(eta_status),
                document_type = VALUES(document_type),
                issued_at = VALUES(issued_at),
                last_pulled_at = VALUES(last_pulled_at),
                updated_at = CURRENT_TIMESTAMP
        ");
        if (!$stmt) {
            return;
        }
        $stmt->bind_param('issssssss', $invoiceId, $internalNumber, $uuid, $submissionId, $longId, $status, $documentType, $issuedAt, $pulledAt);
        $stmt->execute();
        $stmt->close();
    }
}

if (!function_exists('app_eta_einvoice_sign_document')) {
    function app_eta_einvoice_sign_document(mysqli $conn, array $payload, ?array $settingsOverride = null): array
    {
        $settings = $settingsOverride ?: app_eta_einvoice_settings($conn);
        $mode = trim((string)($settings['signing_mode'] ?? 'signing_server'));

        if ($mode === 'usb_bridge' || $mode === 'signing_server' || $mode === 'hsm_cloud') {
            $signingUrl = trim((string)($settings['signing_base_url'] ?? ''));
            if ($signingUrl === '') {
                return ['ok' => false, 'error' => 'missing_signing_base_url'];
            }

            $headers = ['Accept: application/json'];
            $apiKey = trim((string)($settings['signing_api_key'] ?? ''));
            if ($apiKey !== '') {
                $headers[] = 'Authorization: Bearer ' . $apiKey;
            }
            $request = [
                'mode' => $mode,
                'hash' => hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
                'document' => $payload,
            ];
            $response = app_eta_einvoice_http_request('POST', rtrim($signingUrl, '/') . '/sign', $headers, $request, 15, 90);
            if (!$response['ok']) {
                return [
                    'ok' => false,
                    'error' => $response['error'] !== '' ? $response['error'] : 'signing_failed',
                    'code' => $response['code'],
                    'body' => $response['body'],
                ];
            }

            $body = is_array($response['json']) ? $response['json'] : [];
            $signedDocument = null;
            if (isset($body['document']) && is_array($body['document'])) {
                $signedDocument = $body['document'];
            } elseif (!empty($body['documents']) && is_array($body['documents']) && isset($body['documents'][0]) && is_array($body['documents'][0])) {
                $signedDocument = $body['documents'][0];
            }

            return [
                'ok' => true,
                'mode' => $mode,
                'signed_document' => $signedDocument,
                'signature' => $body['signature'] ?? null,
                'response' => $body,
            ];
        }

        return ['ok' => false, 'error' => 'unsupported_signing_mode'];
    }
}

if (!function_exists('app_eta_einvoice_submit_outbox')) {
    function app_eta_einvoice_submit_outbox(mysqli $conn, int $outboxId): array
    {
        $settings = app_eta_einvoice_settings($conn);
        $outbox = app_eta_einvoice_outbox_row($conn, $outboxId);
        if (!$outbox) {
            return ['ok' => false, 'error' => 'outbox_not_found'];
        }
        if (!$settings['enabled']) {
            return ['ok' => false, 'error' => 'eta_disabled'];
        }
        if (!app_eta_einvoice_signing_is_configured($settings)) {
            $statusBefore = trim((string)($outbox['queue_status'] ?? 'draft'));
            $stmtQueued = $conn->prepare("UPDATE eta_outbox SET queue_status = 'queued', last_error = '', updated_at = CURRENT_TIMESTAMP WHERE id = ? LIMIT 1");
            if ($stmtQueued) {
                $stmtQueued->bind_param('i', $outboxId);
                $stmtQueued->execute();
                $stmtQueued->close();
            }
            app_eta_einvoice_log_sync_event($conn, $outboxId, (int)$outbox['invoice_id'], 'submit_deferred', $statusBefore, 'queued', '', [
                'reason' => 'missing_signing_base_url',
                'message' => 'Deferred until signing service is configured',
            ]);
            return [
                'ok' => true,
                'deferred' => true,
                'error' => 'missing_signing_base_url',
                'queue_status' => 'queued',
                'outbox_id' => $outboxId,
                'invoice_id' => (int)$outbox['invoice_id'],
            ];
        }

        $statusBefore = trim((string)($outbox['queue_status'] ?? 'draft'));
        $tokenResult = app_eta_einvoice_request_access_token($conn);
        if (empty($tokenResult['ok'])) {
            app_eta_einvoice_log_error($conn, (int)$outbox['invoice_id'], $outboxId, 'auth', (string)($tokenResult['error'] ?? 'eta_auth_failed'), 'ETA auth failed', $tokenResult);
            return $tokenResult;
        }

        $payload = json_decode((string)($outbox['payload_json'] ?? ''), true);
        if (!is_array($payload)) {
            return ['ok' => false, 'error' => 'invalid_outbox_payload'];
        }

        $signResult = app_eta_einvoice_sign_document($conn, $payload, $settings);
        if (empty($signResult['ok'])) {
            $stmtSignFail = $conn->prepare("UPDATE eta_outbox SET queue_status = 'failed', last_error = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ? LIMIT 1");
            if ($stmtSignFail) {
                $signError = mb_substr(trim((string)($signResult['error'] ?? 'signing_failed')), 0, 1000);
                $stmtSignFail->bind_param('si', $signError, $outboxId);
                $stmtSignFail->execute();
                $stmtSignFail->close();
            }
            app_eta_einvoice_log_sync_event($conn, $outboxId, (int)$outbox['invoice_id'], 'sign_failed', $statusBefore, 'failed', (string)($signResult['code'] ?? ''), $signResult['response'] ?? $signResult['body'] ?? $signResult);
            app_eta_einvoice_log_error($conn, (int)$outbox['invoice_id'], $outboxId, 'sign', (string)($signResult['error'] ?? 'signing_failed'), 'ETA signing failed', $signResult['response'] ?? $signResult['body'] ?? $signResult);
            return $signResult;
        }

        $signedPayload = is_array($signResult['signed_document'] ?? null) ? (array)$signResult['signed_document'] : $payload;
        $signatureJson = json_encode([
            'mode' => $signResult['mode'] ?? $settings['signing_mode'],
            'signature' => $signResult['signature'] ?? null,
            'response' => $signResult['response'] ?? null,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $signedAt = date('Y-m-d H:i:s');
        $stmtSigned = $conn->prepare("UPDATE eta_outbox SET queue_status = 'signed', signature_json = ?, signed_at = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ? LIMIT 1");
        if ($stmtSigned) {
            $signatureText = is_string($signatureJson) ? $signatureJson : '';
            $stmtSigned->bind_param('ssi', $signatureText, $signedAt, $outboxId);
            $stmtSigned->execute();
            $stmtSigned->close();
        }
        app_eta_einvoice_log_sync_event($conn, $outboxId, (int)$outbox['invoice_id'], 'sign', $statusBefore, 'signed', '', $signResult['response'] ?? []);
        $statusBefore = 'signed';

        $paths = app_eta_einvoice_api_paths();
        $submitPayload = ['documents' => [$signedPayload]];
        $response = app_eta_einvoice_http_request('POST', app_eta_einvoice_build_api_url($settings, $paths['submit']), [
            'Accept: application/json',
            'Authorization: Bearer ' . $tokenResult['token'],
        ], $submitPayload, 15, 90);

        if (!$response['ok']) {
            $stmtFail = $conn->prepare("UPDATE eta_outbox SET queue_status = 'failed', submit_attempts = submit_attempts + 1, last_error = ? WHERE id = ? LIMIT 1");
            if ($stmtFail) {
                $lastError = mb_substr(trim((string)($response['error'] ?? 'submit_failed')), 0, 1000);
                $stmtFail->bind_param('si', $lastError, $outboxId);
                $stmtFail->execute();
                $stmtFail->close();
            }
            app_eta_einvoice_log_sync_event($conn, $outboxId, (int)$outbox['invoice_id'], 'submit_failed', $statusBefore, 'failed', (string)$response['code'], $response['json'] ?? $response['body']);
            app_eta_einvoice_log_error($conn, (int)$outbox['invoice_id'], $outboxId, 'submit', (string)($response['error'] ?? 'submit_failed'), 'ETA submit failed', $response['json'] ?? $response['body']);
            return ['ok' => false, 'error' => $response['error'] !== '' ? $response['error'] : 'submit_failed', 'code' => $response['code']];
        }

        $body = is_array($response['json']) ? $response['json'] : [];
        $submissionId = trim((string)($body['submissionId'] ?? $body['submissionUUID'] ?? ''));
        $accepted = [];
        foreach ((array)($body['acceptedDocuments'] ?? $body['submittedDocuments'] ?? []) as $doc) {
            if (is_array($doc)) {
                $accepted[] = $doc;
            }
        }
        $doc0 = $accepted[0] ?? [];
        $uuid = trim((string)($doc0['uuid'] ?? $doc0['documentUUID'] ?? ''));
        $longId = trim((string)($doc0['longId'] ?? ''));
        $statusAfter = $uuid !== '' ? 'submitted' : 'queued';
        $validationJson = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $submittedAt = date('Y-m-d H:i:s');

        $stmtOk = $conn->prepare("
            UPDATE eta_outbox
            SET queue_status = ?, eta_uuid = ?, eta_submission_id = ?, submit_attempts = submit_attempts + 1, last_error = '', submitted_at = ?, updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
            LIMIT 1
        ");
        if ($stmtOk) {
            $stmtOk->bind_param('ssssi', $statusAfter, $uuid, $submissionId, $submittedAt, $outboxId);
            $stmtOk->execute();
            $stmtOk->close();
        }

        app_eta_einvoice_mark_invoice_status($conn, (int)$outbox['invoice_id'], [
            'eta_uuid' => $uuid,
            'eta_status' => $statusAfter,
            'eta_submission_id' => $submissionId,
            'eta_last_sync_at' => $submittedAt,
            'eta_validation_json' => is_string($validationJson) ? $validationJson : '',
        ]);
        app_eta_einvoice_apply_sales_financial_state($conn, (int)$outbox['invoice_id'], $statusAfter);
        app_eta_einvoice_upsert_document_map(
            $conn,
            (int)$outbox['invoice_id'],
            trim((string)($outbox['internal_number'] ?? '')),
            $uuid,
            $submissionId,
            $statusAfter,
            strtoupper(trim((string)($payload['documentType'] ?? 'I'))),
            trim((string)($payload['dateTimeIssued'] ?? '')),
            $longId
        );
        app_eta_einvoice_log_sync_event($conn, $outboxId, (int)$outbox['invoice_id'], 'submit', $statusBefore, $statusAfter, (string)$response['code'], $body);

        return [
            'ok' => true,
            'outbox_id' => $outboxId,
            'invoice_id' => (int)$outbox['invoice_id'],
            'submission_id' => $submissionId,
            'uuid' => $uuid,
            'queue_status' => $statusAfter,
            'response' => $body,
        ];
    }
}

if (!function_exists('app_eta_einvoice_sync_document_status')) {
    function app_eta_einvoice_sync_document_status(mysqli $conn, int $invoiceId): array
    {
        $settings = app_eta_einvoice_settings($conn);
        $tokenResult = app_eta_einvoice_request_access_token($conn);
        if (empty($tokenResult['ok'])) {
            return $tokenResult;
        }

        $stmt = $conn->prepare("
            SELECT i.id, i.invoice_number, i.eta_uuid, i.eta_status, i.eta_submission_id
            FROM invoices i
            WHERE i.id = ?
            LIMIT 1
        ");
        if (!$stmt) {
            return ['ok' => false, 'error' => 'prepare_failed'];
        }
        $stmt->bind_param('i', $invoiceId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) {
            return ['ok' => false, 'error' => 'invoice_not_found'];
        }
        $uuid = trim((string)($row['eta_uuid'] ?? ''));
        $submissionId = trim((string)($row['eta_submission_id'] ?? ''));
        if ($uuid === '' && $submissionId === '') {
            return ['ok' => false, 'error' => 'missing_eta_uuid'];
        }

        $paths = app_eta_einvoice_api_paths();
        $headers = [
            'Accept: application/json',
            'Authorization: Bearer ' . $tokenResult['token'],
        ];

        $statusBefore = trim((string)($row['eta_status'] ?? ''));
        $submissionBody = null;
        $submissionOverallStatus = '';
        $submissionDocument = null;

        if ($submissionId !== '') {
            $submissionResponse = app_eta_einvoice_http_request(
                'GET',
                app_eta_einvoice_build_api_url($settings, $paths['submission_details'] . rawurlencode($submissionId)),
                $headers,
                null,
                15,
                60
            );
            if ($submissionResponse['ok']) {
                $submissionBody = is_array($submissionResponse['json'] ?? null) ? (array)$submissionResponse['json'] : [];
                $submissionOverallStatus = strtolower(trim((string)($submissionBody['overallStatus'] ?? '')));
                foreach ((array)($submissionBody['documentSummary'] ?? []) as $summaryRow) {
                    if (!is_array($summaryRow)) {
                        continue;
                    }
                    $summaryInternalId = trim((string)($summaryRow['internalId'] ?? ''));
                    $currentInternalId = trim((string)($row['invoice_number'] ?? ''));
                    if ($uuid !== '' && trim((string)($summaryRow['uuid'] ?? '')) === $uuid) {
                        $submissionDocument = $summaryRow;
                        break;
                    }
                    if ($currentInternalId !== '' && $summaryInternalId !== '' && $summaryInternalId === $currentInternalId) {
                        $submissionDocument = $summaryRow;
                        break;
                    }
                }
                if ($uuid === '' && is_array($submissionDocument)) {
                    $uuid = trim((string)($submissionDocument['uuid'] ?? ''));
                }
            } else {
                app_eta_einvoice_log_error($conn, $invoiceId, null, 'sync_submission', (string)($submissionResponse['error'] ?? 'submission_sync_failed'), 'ETA submission status sync failed', $submissionResponse['json'] ?? $submissionResponse['body']);
            }
        }

        if ($uuid === '') {
            $normalizedStatus = $submissionOverallStatus !== '' ? $submissionOverallStatus : ($statusBefore !== '' ? $statusBefore : 'submitted');
            $lastSyncAt = date('Y-m-d H:i:s');
            $submissionJson = json_encode($submissionBody ?: ['overallStatus' => $normalizedStatus], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            app_eta_einvoice_mark_invoice_status($conn, $invoiceId, [
                'eta_status' => $normalizedStatus,
                'eta_last_sync_at' => $lastSyncAt,
                'eta_validation_json' => is_string($submissionJson) ? $submissionJson : '',
            ]);
            app_eta_einvoice_log_sync_event($conn, null, $invoiceId, 'sync_submission', $statusBefore, $normalizedStatus, '200', $submissionBody ?: ['overallStatus' => $normalizedStatus]);
            return [
                'ok' => true,
                'invoice_id' => $invoiceId,
                'uuid' => '',
                'eta_status' => $normalizedStatus,
                'response' => $submissionBody ?: ['overallStatus' => $normalizedStatus],
            ];
        }

        $response = app_eta_einvoice_http_request(
            'GET',
            app_eta_einvoice_build_api_url($settings, $paths['document_details'] . rawurlencode($uuid) . '/details'),
            $headers,
            null,
            15,
            60
        );
        if (!$response['ok']) {
            app_eta_einvoice_log_error($conn, $invoiceId, null, 'sync', (string)($response['error'] ?? 'sync_failed'), 'ETA status sync failed', $response['json'] ?? $response['body']);
            return ['ok' => false, 'error' => $response['error'] !== '' ? $response['error'] : 'sync_failed', 'code' => $response['code']];
        }

        $body = is_array($response['json']) ? $response['json'] : [];
        $rawStatus = trim((string)($body['status'] ?? $body['documentStatus'] ?? $body['document']['status'] ?? 'unknown'));
        $normalizedStatus = strtolower($rawStatus);
        $lastSyncAt = date('Y-m-d H:i:s');
        $validationJson = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        app_eta_einvoice_mark_invoice_status($conn, $invoiceId, [
            'eta_status' => $normalizedStatus !== '' ? $normalizedStatus : 'unknown',
            'eta_last_sync_at' => $lastSyncAt,
            'eta_validation_json' => is_string($validationJson) ? $validationJson : '',
        ]);
        app_eta_einvoice_apply_sales_financial_state($conn, $invoiceId, $normalizedStatus !== '' ? $normalizedStatus : 'unknown');
        app_eta_einvoice_upsert_document_map(
            $conn,
            $invoiceId,
            trim((string)($row['invoice_number'] ?? '')),
            $uuid,
            $submissionId,
            $normalizedStatus !== '' ? $normalizedStatus : 'unknown',
            trim((string)($body['documentType'] ?? $body['typeName'] ?? 'I')),
            trim((string)($body['dateTimeIssued'] ?? $body['dateTimeReceived'] ?? '')),
            trim((string)($body['longId'] ?? ''))
        );
        app_eta_einvoice_log_sync_event($conn, null, $invoiceId, 'sync', $statusBefore, $normalizedStatus !== '' ? $normalizedStatus : 'unknown', (string)$response['code'], $body);

        return [
            'ok' => true,
            'invoice_id' => $invoiceId,
            'uuid' => $uuid,
            'eta_status' => $normalizedStatus !== '' ? $normalizedStatus : 'unknown',
            'response' => $body,
        ];
    }
}

if (!function_exists('app_eta_einvoice_period_range')) {
    function app_eta_einvoice_period_range(string $dateFrom, string $dateTo): array
    {
        $dateFrom = trim($dateFrom);
        $dateTo = trim($dateTo);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
            return ['ok' => false, 'error' => 'invalid_period'];
        }
        if ($dateFrom > $dateTo) {
            return ['ok' => false, 'error' => 'invalid_period_order'];
        }
        $tz = new DateTimeZone('Africa/Cairo');
        $fromDt = new DateTime($dateFrom . ' 00:00:00', $tz);
        $toDt = new DateTime($dateTo . ' 23:59:59', $tz);
        $fromDt->setTimezone(new DateTimeZone('UTC'));
        $toDt->setTimezone(new DateTimeZone('UTC'));
        $fromUtc = $fromDt->format('Y-m-d\TH:i:s');
        $toUtc = $toDt->format('Y-m-d\TH:i:s');
        return ['ok' => true, 'from' => $dateFrom, 'to' => $dateTo, 'from_utc' => $fromUtc, 'to_utc' => $toUtc];
    }
}

if (!function_exists('app_eta_einvoice_period_chunks')) {
    function app_eta_einvoice_period_chunks(string $dateFrom, string $dateTo, int $maxDays = 31): array
    {
        $period = app_eta_einvoice_period_range($dateFrom, $dateTo);
        if (empty($period['ok'])) {
            return $period;
        }
        $maxDays = max(1, $maxDays);
        $tz = new DateTimeZone('Africa/Cairo');
        $cursor = new DateTime($period['from'] . ' 00:00:00', $tz);
        $end = new DateTime($period['to'] . ' 00:00:00', $tz);
        $chunks = [];
        while ($cursor <= $end) {
            $chunkStart = clone $cursor;
            $chunkEnd = clone $cursor;
            $chunkEnd->modify('+' . ($maxDays - 1) . ' days');
            if ($chunkEnd > $end) {
                $chunkEnd = clone $end;
            }
            $chunk = app_eta_einvoice_period_range($chunkStart->format('Y-m-d'), $chunkEnd->format('Y-m-d'));
            if (empty($chunk['ok'])) {
                return $chunk;
            }
            $chunks[] = $chunk;
            $cursor = clone $chunkEnd;
            $cursor->modify('+1 day');
        }
        return [
            'ok' => true,
            'from' => $period['from'],
            'to' => $period['to'],
            'chunks' => $chunks,
        ];
    }
}

if (!function_exists('app_eta_einvoice_queue_or_submit_saved_invoice')) {
    function app_eta_einvoice_queue_or_submit_saved_invoice(mysqli $conn, int $invoiceId, int $userId = 0): array
    {
        if (!app_is_work_runtime()) {
            return ['ok' => false, 'error' => 'eta_not_available_in_this_runtime'];
        }
        $settings = app_eta_einvoice_settings($conn);
        if (empty($settings['enabled'])) {
            return ['ok' => false, 'error' => 'eta_disabled'];
        }
        $mode = (string)($settings['submission_mode'] ?? 'manual_review');
        if (!in_array($mode, ['queue', 'auto_submit'], true)) {
            return ['ok' => true, 'mode' => 'manual_review', 'skipped' => true];
        }
        $effectiveMode = $mode;
        if ($mode === 'auto_submit' && !app_eta_einvoice_signing_is_configured($settings)) {
            $effectiveMode = 'queue';
        }

        $queued = app_eta_einvoice_queue_invoice($conn, $invoiceId, $userId);
        if (empty($queued['ok'])) {
            return $queued;
        }
        if ($effectiveMode === 'queue') {
            return [
                'ok' => true,
                'mode' => 'queue',
                'queue' => $queued,
                'deferred' => $mode === 'auto_submit',
                'notice' => $mode === 'auto_submit' ? 'submit_deferred_until_signing_configured' : '',
            ];
        }
        $outboxId = (int)($queued['outbox_id'] ?? 0);
        if ($outboxId <= 0) {
            return ['ok' => false, 'error' => 'missing_outbox_id'];
        }
        $submitted = app_eta_einvoice_submit_outbox($conn, $outboxId);
        if (empty($submitted['ok'])) {
            return $submitted;
        }
        return ['ok' => true, 'mode' => 'auto_submit', 'queue' => $queued, 'submit' => $submitted];
    }
}

if (!function_exists('app_eta_einvoice_batch_process_sales_period')) {
    function app_eta_einvoice_batch_process_sales_period(mysqli $conn, string $dateFrom, string $dateTo, string $mode, int $userId = 0): array
    {
        $period = app_eta_einvoice_period_range($dateFrom, $dateTo);
        if (empty($period['ok'])) {
            return $period;
        }
        $mode = strtolower(trim($mode));
        if (!in_array($mode, ['queue', 'submit', 'sync'], true)) {
            return ['ok' => false, 'error' => 'invalid_batch_mode'];
        }
        $settings = app_eta_einvoice_settings($conn);
        $submitDeferred = false;
        if ($mode === 'submit' && !app_eta_einvoice_signing_is_configured($settings)) {
            $mode = 'queue';
            $submitDeferred = true;
        }
        $stmt = $conn->prepare("
            SELECT id, IFNULL(invoice_number, '') AS invoice_number, IFNULL(eta_uuid, '') AS eta_uuid, IFNULL(eta_submission_id, '') AS eta_submission_id
            FROM invoices
            WHERE invoice_kind = 'tax'
              AND DATE(inv_date) BETWEEN ? AND ?
            ORDER BY inv_date ASC, id ASC
        ");
        if (!$stmt) {
            return ['ok' => false, 'error' => 'prepare_failed'];
        }
        $stmt->bind_param('ss', $period['from'], $period['to']);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $summary = [
            'ok' => true,
            'mode' => $mode,
            'requested_mode' => $submitDeferred ? 'submit' : $mode,
            'deferred_submit' => $submitDeferred,
            'from' => $period['from'],
            'to' => $period['to'],
            'matched' => count($rows),
            'processed' => 0,
            'success' => 0,
            'failed' => 0,
            'details' => [],
        ];

        foreach ($rows as $row) {
            $invoiceId = (int)($row['id'] ?? 0);
            if ($invoiceId <= 0) {
                continue;
            }
            $summary['processed']++;
            if ($mode === 'queue') {
                $result = app_eta_einvoice_queue_invoice($conn, $invoiceId, $userId);
            } elseif ($mode === 'submit') {
                $queueResult = app_eta_einvoice_queue_invoice($conn, $invoiceId, $userId);
                $result = !empty($queueResult['ok']) && (int)($queueResult['outbox_id'] ?? 0) > 0
                    ? app_eta_einvoice_submit_outbox($conn, (int)$queueResult['outbox_id'])
                    : $queueResult;
            } else {
                $result = app_eta_einvoice_sync_document_status($conn, $invoiceId);
            }
            if (!empty($result['ok'])) {
                $summary['success']++;
            } else {
                $summary['failed']++;
                $summary['details'][] = [
                    'invoice_id' => $invoiceId,
                    'invoice_number' => (string)($row['invoice_number'] ?? ''),
                    'error' => (string)($result['error'] ?? 'eta_batch_failed'),
                ];
            }
        }

        return $summary;
    }
}

if (!function_exists('app_eta_einvoice_search_documents')) {
    function app_eta_einvoice_search_documents(mysqli $conn, array $filters): array
    {
        $settings = app_eta_einvoice_settings($conn);
        $tokenResult = app_eta_einvoice_request_access_token($conn);
        if (empty($tokenResult['ok'])) {
            return $tokenResult;
        }
        $query = [];
        $allowed = [
            'submissionDateFrom', 'submissionDateTo', 'issueDateFrom', 'issueDateTo',
            'continuationToken', 'pageSize', 'direction', 'status', 'documentType',
            'receiverType', 'receiverId', 'issuerType', 'issuerId', 'uuid', 'internalID',
        ];
        foreach ($allowed as $key) {
            if (!array_key_exists($key, $filters) || $filters[$key] === '' || $filters[$key] === null) {
                continue;
            }
            $query[$key] = (string)$filters[$key];
        }
        if (!isset($query['pageSize']) || (int)$query['pageSize'] <= 0) {
            $query['pageSize'] = '100';
        }
        $url = app_eta_einvoice_build_api_url($settings, app_eta_einvoice_api_paths()['search_documents']);
        $url .= '?' . http_build_query($query);
        $response = app_eta_einvoice_http_request('GET', $url, [
            'Accept: application/json',
            'Authorization: Bearer ' . $tokenResult['token'],
        ], null, 15, 90);
        if (!$response['ok']) {
            return [
                'ok' => false,
                'error' => $response['error'] !== '' ? $response['error'] : 'eta_search_failed',
                'code' => (int)($response['code'] ?? 0),
                'body' => $response['body'] ?? '',
            ];
        }
        $json = is_array($response['json'] ?? null) ? (array)$response['json'] : [];
        return [
            'ok' => true,
            'result' => array_values((array)($json['result'] ?? [])),
            'metadata' => (array)($json['metadata'] ?? []),
            'response' => $json,
        ];
    }
}

if (!function_exists('app_eta_einvoice_get_document_details')) {
    function app_eta_einvoice_get_document_details(mysqli $conn, string $uuid): array
    {
        $uuid = trim($uuid);
        if ($uuid === '') {
            return ['ok' => false, 'error' => 'missing_uuid'];
        }
        $settings = app_eta_einvoice_settings($conn);
        $tokenResult = app_eta_einvoice_request_access_token($conn);
        if (empty($tokenResult['ok'])) {
            return $tokenResult;
        }
        $url = app_eta_einvoice_build_api_url($settings, app_eta_einvoice_api_paths()['document_details'] . rawurlencode($uuid) . '/details');
        $response = app_eta_einvoice_http_request('GET', $url, [
            'Accept: application/json',
            'Authorization: Bearer ' . $tokenResult['token'],
        ], null, 15, 90);
        if (!$response['ok']) {
            return [
                'ok' => false,
                'error' => $response['error'] !== '' ? $response['error'] : 'eta_document_details_failed',
                'code' => (int)($response['code'] ?? 0),
                'body' => $response['body'] ?? '',
            ];
        }
        $json = is_array($response['json'] ?? null) ? (array)$response['json'] : [];
        return ['ok' => true, 'document' => $json];
    }
}

if (!function_exists('app_eta_einvoice_find_or_create_supplier_from_document')) {
    function app_eta_einvoice_find_or_create_supplier_from_document(mysqli $conn, array $document): array
    {
        $issuer = (array)($document['issuer'] ?? []);
        $supplierName = trim((string)($issuer['name'] ?? $document['issuerName'] ?? ''));
        $supplierTaxId = trim((string)($issuer['id'] ?? $document['issuerId'] ?? ''));
        $supplierType = strtoupper(trim((string)($issuer['type'] ?? $document['issuerType'] ?? 'B')));
        $countryCode = trim((string)($issuer['address']['country'] ?? 'EG'));
        if ($supplierName === '') {
            $supplierName = 'ETA Supplier';
        }
        if ($supplierTaxId !== '') {
            $stmt = $conn->prepare("SELECT id FROM suppliers WHERE tax_number = ? OR tax_id = ? LIMIT 1");
            if ($stmt) {
                $stmt->bind_param('ss', $supplierTaxId, $supplierTaxId);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if ($row) {
                    return ['ok' => true, 'supplier_id' => (int)$row['id'], 'created' => false];
                }
            }
        }
        $stmtByName = $conn->prepare("SELECT id FROM suppliers WHERE name = ? LIMIT 1");
        if ($stmtByName) {
            $stmtByName->bind_param('s', $supplierName);
            $stmtByName->execute();
            $row = $stmtByName->get_result()->fetch_assoc();
            $stmtByName->close();
            if ($row) {
                return ['ok' => true, 'supplier_id' => (int)$row['id'], 'created' => false];
            }
        }

        $address = trim((string)($issuer['address']['street'] ?? ''));
        $email = trim((string)($document['issuerEmail'] ?? ''));
        $phone = trim((string)($document['issuerMobileNumber'] ?? ''));
        $receiverType = in_array($supplierType, ['B', 'F'], true) ? $supplierType : 'B';
        $stmtInsert = $conn->prepare("
            INSERT INTO suppliers (name, phone, email, address, notes, tax_number, tax_id, national_id, country_code, eta_receiver_type)
            VALUES (?, ?, ?, ?, ?, ?, ?, '', ?, ?)
        ");
        if (!$stmtInsert) {
            return ['ok' => false, 'error' => 'supplier_insert_prepare_failed'];
        }
        $notes = 'Imported automatically from ETA.';
        $taxId = $supplierTaxId;
        $stmtInsert->bind_param('sssssssss', $supplierName, $phone, $email, $address, $notes, $supplierTaxId, $taxId, $countryCode, $receiverType);
        $ok = $stmtInsert->execute();
        $supplierId = (int)$stmtInsert->insert_id;
        $stmtInsert->close();
        if (!$ok || $supplierId <= 0) {
            return ['ok' => false, 'error' => 'supplier_insert_failed'];
        }
        return ['ok' => true, 'supplier_id' => $supplierId, 'created' => true];
    }
}

if (!function_exists('app_eta_einvoice_find_inventory_item_id')) {
    function app_eta_einvoice_find_inventory_item_id(mysqli $conn, string $itemCode, string $desc): int
    {
        if (function_exists('inventory_find_catalog_item_id')) {
            return inventory_find_catalog_item_id($conn, [
                'item_id' => 0,
                'item_code' => $itemCode,
                'desc' => $desc,
            ]);
        }

        static $catalogCodeColumn = null;
        static $codeCache = [];
        static $nameCache = [];
        $normalizeKey = static function (string $value): string {
            $value = preg_replace('/\s+/', ' ', trim($value));
            if (function_exists('mb_strtolower')) {
                return mb_strtolower($value, 'UTF-8');
            }
            return strtolower($value);
        };

        if ($catalogCodeColumn === null) {
            if (function_exists('app_table_has_column') && app_table_has_column($conn, 'inventory_items', 'item_code')) {
                $catalogCodeColumn = 'item_code';
            } elseif (function_exists('app_table_has_column') && app_table_has_column($conn, 'inventory_items', 'sku')) {
                $catalogCodeColumn = 'sku';
            } else {
                $catalogCodeColumn = '';
            }
        }

        $itemCode = trim($itemCode);
        if ($itemCode !== '' && $catalogCodeColumn !== '') {
            $codeKey = $normalizeKey($itemCode);
            if (array_key_exists($codeKey, $codeCache)) {
                return (int)$codeCache[$codeKey];
            }
            $stmtByCode = $conn->prepare("SELECT id FROM inventory_items WHERE TRIM({$catalogCodeColumn}) = ? LIMIT 1");
            if ($stmtByCode) {
                $stmtByCode->bind_param('s', $itemCode);
                $stmtByCode->execute();
                $match = $stmtByCode->get_result()->fetch_assoc();
                $stmtByCode->close();
                $resolvedId = (int)($match['id'] ?? 0);
                $codeCache[$codeKey] = $resolvedId;
                if ($resolvedId > 0) {
                    return $resolvedId;
                }
            }
        }

        $desc = trim($desc);
        if ($desc !== '') {
            $nameKey = $normalizeKey($desc);
            if (array_key_exists($nameKey, $nameCache)) {
                return (int)$nameCache[$nameKey];
            }
            $stmtByName = $conn->prepare("SELECT id FROM inventory_items WHERE TRIM(name) = ? LIMIT 1");
            if ($stmtByName) {
                $stmtByName->bind_param('s', $desc);
                $stmtByName->execute();
                $match = $stmtByName->get_result()->fetch_assoc();
                $stmtByName->close();
                $resolvedId = (int)($match['id'] ?? 0);
                $nameCache[$nameKey] = $resolvedId;
                if ($resolvedId > 0) {
                    return $resolvedId;
                }
            }
        }

        return 0;
    }
}

if (!function_exists('app_eta_einvoice_purchase_items_from_document')) {
    function app_eta_einvoice_purchase_items_from_document(mysqli $conn, array $document): array
    {
        $rows = [];
        $invoiceLines = (array)($document['invoiceLines'] ?? $document['document']['invoiceLines'] ?? []);
        foreach ($invoiceLines as $line) {
            if (!is_array($line)) {
                continue;
            }
            $desc = trim((string)($line['description'] ?? ''));
            $qty = (float)($line['quantity'] ?? 0);
            $price = (float)($line['unitValue']['amountEGP'] ?? $line['unitValue']['amountSold'] ?? $line['valueDifference'] ?? 0);
            $total = (float)($line['total'] ?? $line['netTotal'] ?? ($qty * $price));
            $itemCode = trim((string)($line['itemCode'] ?? ''));
            $unitType = trim((string)($line['unitType'] ?? 'EA'));
            if ($desc === '' && $itemCode === '') {
                continue;
            }
            $resolvedItemId = app_eta_einvoice_find_inventory_item_id($conn, $itemCode, $desc);
            $rows[] = [
                'item_id' => $resolvedItemId,
                'desc' => $desc !== '' ? $desc : $itemCode,
                'qty' => $qty > 0 ? $qty : 1,
                'price' => $price,
                'total' => $total,
                'item_code' => $itemCode,
                'unit_type' => $unitType,
            ];
        }
        return $rows;
    }
}

if (!function_exists('app_eta_einvoice_sales_items_from_document')) {
    function app_eta_einvoice_sales_items_from_document(array $document): array
    {
        $rows = [];
        $invoiceLines = (array)($document['invoiceLines'] ?? $document['document']['invoiceLines'] ?? []);
        foreach ($invoiceLines as $line) {
            if (!is_array($line)) {
                continue;
            }
            $desc = trim((string)($line['description'] ?? ''));
            $qty = (float)($line['quantity'] ?? 0);
            $price = (float)($line['unitValue']['amountEGP'] ?? $line['unitValue']['amountSold'] ?? 0);
            $total = (float)($line['total'] ?? $line['netTotal'] ?? ($qty * $price));
            $itemCode = trim((string)($line['itemCode'] ?? ''));
            $unitType = trim((string)($line['unitType'] ?? 'EA'));
            if ($desc === '' && $itemCode === '') {
                continue;
            }
            $rows[] = [
                'desc' => $desc !== '' ? $desc : $itemCode,
                'qty' => $qty > 0 ? $qty : 1,
                'unit' => $unitType !== '' ? $unitType : 'EA',
                'item_code' => $itemCode,
                'unit_type' => $unitType !== '' ? $unitType : 'EA',
                'price' => $price,
                'total' => $total,
            ];
        }
        return $rows;
    }
}

if (!function_exists('app_eta_einvoice_find_or_create_client_from_document')) {
    function app_eta_einvoice_find_or_create_client_from_document(mysqli $conn, array $document): array
    {
        $receiver = (array)($document['receiver'] ?? []);
        $clientName = trim((string)($receiver['name'] ?? $document['receiverName'] ?? ''));
        $clientTaxId = trim((string)($receiver['id'] ?? $document['receiverId'] ?? ''));
        $receiverType = strtoupper(trim((string)($receiver['type'] ?? $document['receiverType'] ?? 'B')));
        $countryCode = trim((string)($receiver['address']['country'] ?? 'EG'));
        if ($clientName === '') {
            $clientName = 'ETA Client';
        }
        if ($clientTaxId !== '') {
            $stmt = $conn->prepare("SELECT id FROM clients WHERE tax_number = ? OR tax_id = ? OR national_id = ? LIMIT 1");
            if ($stmt) {
                $stmt->bind_param('sss', $clientTaxId, $clientTaxId, $clientTaxId);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if ($row) {
                    return ['ok' => true, 'client_id' => (int)$row['id'], 'created' => false];
                }
            }
        }
        $stmtByName = $conn->prepare("SELECT id FROM clients WHERE name = ? LIMIT 1");
        if ($stmtByName) {
            $stmtByName->bind_param('s', $clientName);
            $stmtByName->execute();
            $row = $stmtByName->get_result()->fetch_assoc();
            $stmtByName->close();
            if ($row) {
                return ['ok' => true, 'client_id' => (int)$row['id'], 'created' => false];
            }
        }
        $address = trim((string)($receiver['address']['street'] ?? ''));
        $email = trim((string)($document['receiverEmail'] ?? ''));
        $phone = trim((string)($document['receiverMobileNumber'] ?? ''));
        $etaReceiverType = in_array($receiverType, ['B', 'P', 'F'], true) ? $receiverType : 'B';
        $stmtInsert = $conn->prepare("
            INSERT INTO clients (name, phone, email, address, notes, tax_number, tax_id, national_id, country_code, eta_receiver_type)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        if (!$stmtInsert) {
            return ['ok' => false, 'error' => 'client_insert_prepare_failed'];
        }
        $notes = 'Imported automatically from ETA sent documents.';
        $taxNumber = $etaReceiverType === 'B' ? $clientTaxId : '';
        $taxId = $etaReceiverType === 'B' ? $clientTaxId : '';
        $nationalId = $etaReceiverType === 'P' ? $clientTaxId : '';
        $stmtInsert->bind_param('ssssssssss', $clientName, $phone, $email, $address, $notes, $taxNumber, $taxId, $nationalId, $countryCode, $etaReceiverType);
        $ok = $stmtInsert->execute();
        $clientId = (int)$stmtInsert->insert_id;
        $stmtInsert->close();
        if (!$ok || $clientId <= 0) {
            return ['ok' => false, 'error' => 'client_insert_failed'];
        }
        return ['ok' => true, 'client_id' => $clientId, 'created' => true];
    }
}

if (!function_exists('app_eta_einvoice_import_sales_document')) {
    function app_eta_einvoice_import_sales_document(mysqli $conn, array $summary, array $details): array
    {
        $uuid = trim((string)($summary['uuid'] ?? $details['uuid'] ?? ''));
        if ($uuid === '') {
            return ['ok' => false, 'error' => 'missing_uuid'];
        }
        $internalId = trim((string)($details['internalID'] ?? $summary['internalId'] ?? ''));
        $stmtExisting = $conn->prepare("SELECT id FROM invoices WHERE eta_uuid = ? OR invoice_number = ? LIMIT 1");
        if ($stmtExisting) {
            $stmtExisting->bind_param('ss', $uuid, $internalId);
            $stmtExisting->execute();
            $existing = $stmtExisting->get_result()->fetch_assoc();
            $stmtExisting->close();
            if ($existing) {
                $invoiceId = (int)$existing['id'];
                $etaStatus = strtolower(trim((string)($summary['status'] ?? $details['status'] ?? 'submitted')));
                $validationJson = json_encode(['summary' => $summary, 'details' => $details], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                app_eta_einvoice_mark_invoice_status($conn, $invoiceId, [
                    'eta_uuid' => $uuid,
                    'eta_status' => $etaStatus,
                    'eta_submission_id' => trim((string)($summary['submissionUUID'] ?? $details['submissionUUID'] ?? '')),
                    'eta_last_sync_at' => date('Y-m-d H:i:s'),
                    'eta_validation_json' => is_string($validationJson) ? $validationJson : '',
                ]);
                app_eta_einvoice_apply_sales_financial_state($conn, $invoiceId, $etaStatus);
                return ['ok' => true, 'invoice_id' => $invoiceId, 'created' => false, 'duplicate' => true];
            }
        }

        $clientResult = app_eta_einvoice_find_or_create_client_from_document($conn, $details);
        if (empty($clientResult['ok'])) {
            return $clientResult;
        }
        $clientId = (int)$clientResult['client_id'];
        $items = app_eta_einvoice_sales_items_from_document($details);
        $itemsJson = json_encode($items, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($itemsJson)) {
            $itemsJson = '[]';
        }
        $invDate = substr((string)($summary['dateTimeIssued'] ?? $details['dateTimeIssued'] ?? gmdate('Y-m-d')), 0, 10);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $invDate)) {
            $invDate = gmdate('Y-m-d');
        }
        $subTotal = (float)($details['totalSalesAmount'] ?? $summary['totalSales'] ?? 0);
        $discount = (float)($details['totalDiscountAmount'] ?? $summary['totalDiscount'] ?? 0);
        $tax = 0.0;
        foreach ((array)($details['taxTotals'] ?? []) as $taxRow) {
            if (!is_array($taxRow)) {
                continue;
            }
            $tax += (float)($taxRow['amount'] ?? 0);
        }
        $totalAmount = (float)($details['totalAmount'] ?? $summary['total'] ?? ($subTotal - $discount + $tax));
        $etaStatus = strtolower(trim((string)($summary['status'] ?? $details['status'] ?? 'submitted')));
        $submissionId = trim((string)($summary['submissionUUID'] ?? $details['submissionUUID'] ?? ''));
        $validationJson = json_encode(['summary' => $summary, 'details' => $details], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($validationJson)) {
            $validationJson = '';
        }
        $taxesJson = json_encode((array)($details['taxTotals'] ?? []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($taxesJson)) {
            $taxesJson = '[]';
        }
        $notes = 'Imported automatically from ETA sent documents.';
        $localStatus = app_eta_einvoice_is_financially_inactive_status($etaStatus) ? 'cancelled' : 'deferred';
        $paidAmount = 0.0;
        $remainingAmount = $localStatus === 'cancelled' ? 0.0 : $totalAmount;
        $stmtInsert = $conn->prepare("
            INSERT INTO invoices (
                client_id, job_id, inv_date, due_date, invoice_kind, tax_law_key, sub_total, tax, tax_total, discount, total_amount, items_json, taxes_json, notes,
                paid_amount, remaining_amount, status, eta_uuid, eta_status, eta_submission_id, eta_last_sync_at, eta_validation_json
            ) VALUES (
                ?, NULL, ?, ?, 'tax', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?
            )
        ");
        if (!$stmtInsert) {
            return ['ok' => false, 'error' => 'sales_import_prepare_failed'];
        }
        $taxLawKey = app_setting_get($conn, 'tax_default_sales_law', 'vat_2016');
        $now = date('Y-m-d H:i:s');
        $stmtInsert->bind_param(
            'isssdddddsssddssssss',
            $clientId,
            $invDate,
            $invDate,
            $taxLawKey,
            $subTotal,
            $tax,
            $tax,
            $discount,
            $totalAmount,
            $itemsJson,
            $taxesJson,
            $notes,
            $paidAmount,
            $remainingAmount,
            $localStatus,
            $uuid,
            $etaStatus,
            $submissionId,
            $now,
            $validationJson
        );
        $ok = $stmtInsert->execute();
        $invoiceId = (int)$stmtInsert->insert_id;
        $stmtInsert->close();
        if (!$ok || $invoiceId <= 0) {
            return ['ok' => false, 'error' => 'sales_import_insert_failed'];
        }
        if ($internalId !== '') {
            $stmtRef = $conn->prepare("UPDATE invoices SET invoice_number = ? WHERE id = ? LIMIT 1");
            if ($stmtRef) {
                $stmtRef->bind_param('si', $internalId, $invoiceId);
                $stmtRef->execute();
                $stmtRef->close();
            }
        } else {
            app_assign_document_number($conn, 'invoices', $invoiceId, 'invoice_number', 'invoice', $invDate);
        }
        app_eta_einvoice_apply_sales_financial_state($conn, $invoiceId, $etaStatus);
        return ['ok' => true, 'invoice_id' => $invoiceId, 'client_id' => $clientId, 'created' => true, 'duplicate' => false];
    }
}

if (!function_exists('app_eta_einvoice_pull_sales_documents_by_period')) {
    function app_eta_einvoice_pull_sales_documents_by_period(mysqli $conn, string $dateFrom, string $dateTo): array
    {
        @set_time_limit(300);
        $period = app_eta_einvoice_period_chunks($dateFrom, $dateTo, 31);
        if (empty($period['ok'])) {
            return $period;
        }
        $processed = [];
        $imported = 0;
        $duplicates = 0;
        $createdClients = 0;
        $errors = [];
        foreach ((array)($period['chunks'] ?? []) as $chunk) {
            if (!is_array($chunk)) {
                continue;
            }
            @set_time_limit(300);
            $continuationToken = '';
            $seenTokens = [];
            $pageGuard = 0;
            do {
                $pageGuard++;
                if ($pageGuard > 100) {
                    $errors[] = [
                        'range' => $chunk,
                        'error' => 'pagination_guard_limit',
                        'message' => 'ETA sales pagination exceeded safe page limit.',
                    ];
                    break;
                }
                if ($continuationToken !== '') {
                    if (isset($seenTokens[$continuationToken])) {
                        $errors[] = [
                            'range' => $chunk,
                            'error' => 'duplicate_continuation_token',
                            'token' => $continuationToken,
                            'message' => 'ETA sales pagination token repeated unexpectedly.',
                        ];
                        break;
                    }
                    $seenTokens[$continuationToken] = true;
                }
                $search = app_eta_einvoice_search_documents($conn, [
                    'issueDateFrom' => $chunk['from_utc'],
                    'issueDateTo' => $chunk['to_utc'],
                    'direction' => 'Sent',
                    'pageSize' => '100',
                    'continuationToken' => $continuationToken,
                ]);
                if (empty($search['ok'])) {
                    return $search;
                }
                foreach ((array)($search['result'] ?? []) as $summary) {
                    if (!is_array($summary)) {
                        continue;
                    }
                    $uuid = trim((string)($summary['uuid'] ?? ''));
                    if ($uuid === '' || in_array($uuid, $processed, true)) {
                        continue;
                    }
                    $summaryDocumentType = strtolower(trim((string)($summary['documentType'] ?? $summary['typeName'] ?? '')));
                    if ($summaryDocumentType !== '' && !in_array($summaryDocumentType, ['i', 'ii', 'ei'], true)) {
                        continue;
                    }
                    $detailsResult = app_eta_einvoice_get_document_details($conn, $uuid);
                    if (empty($detailsResult['ok'])) {
                        $errors[] = ['uuid' => $uuid, 'error' => (string)($detailsResult['error'] ?? 'details_failed')];
                        continue;
                    }
                    $detailsDocument = (array)($detailsResult['document'] ?? []);
                    $detailsType = strtolower(trim((string)($detailsDocument['documentType'] ?? $detailsDocument['typeName'] ?? '')));
                    if ($detailsType !== '' && !in_array($detailsType, ['i', 'ii', 'ei'], true)) {
                        continue;
                    }
                    $importResult = app_eta_einvoice_import_sales_document($conn, $summary, $detailsDocument);
                    if (empty($importResult['ok'])) {
                        $errors[] = ['uuid' => $uuid, 'error' => (string)($importResult['error'] ?? 'import_failed')];
                        continue;
                    }
                    if (!empty($importResult['duplicate'])) {
                        $duplicates++;
                    } else {
                        $imported++;
                    }
                    if (!empty($importResult['created']) && !empty($importResult['client_id'])) {
                        $createdClients++;
                    }
                    $processed[] = $uuid;
                }
                $resultRows = (array)($search['result'] ?? []);
                $nextToken = trim((string)($search['metadata']['continuationToken'] ?? ''));
                if ($nextToken === 'EndofResultSet') {
                    $nextToken = '';
                }
                if ($nextToken !== '' && empty($resultRows)) {
                    $errors[] = [
                        'range' => $chunk,
                        'error' => 'empty_page_with_token',
                        'token' => $nextToken,
                        'message' => 'ETA sales returned an empty page with a continuation token.',
                    ];
                    break;
                }
                $continuationToken = $nextToken;
                if ($continuationToken !== '') {
                    sleep(2);
                }
            } while ($continuationToken !== '');
            if (count((array)($period['chunks'] ?? [])) > 1) {
                sleep(1);
            }
        }
        return [
            'ok' => true,
            'from' => $period['from'],
            'to' => $period['to'],
            'imported' => $imported,
            'duplicates' => $duplicates,
            'created_clients' => $createdClients,
            'errors' => $errors,
        ];
    }
}

if (!function_exists('app_eta_einvoice_import_purchase_document')) {
    function app_eta_einvoice_import_purchase_document(mysqli $conn, array $summary, array $details): array
    {
        $uuid = trim((string)($summary['uuid'] ?? $details['uuid'] ?? ''));
        $status = strtolower(trim((string)($summary['status'] ?? $details['status'] ?? 'valid')));
        if ($uuid === '') {
            return ['ok' => false, 'error' => 'missing_uuid'];
        }
        $stmtExisting = $conn->prepare("SELECT id FROM purchase_invoices WHERE eta_uuid = ? LIMIT 1");
        if ($stmtExisting) {
            $stmtExisting->bind_param('s', $uuid);
            $stmtExisting->execute();
            $existing = $stmtExisting->get_result()->fetch_assoc();
            $stmtExisting->close();
            if ($existing) {
                $purchaseInvoiceId = (int)$existing['id'];
                app_eta_einvoice_mark_purchase_invoice_status($conn, $purchaseInvoiceId, [
                    'eta_uuid' => $uuid,
                    'eta_status' => $status !== '' ? $status : 'valid',
                    'eta_submission_id' => trim((string)($summary['submissionUUID'] ?? $details['submissionUUID'] ?? '')),
                    'eta_long_id' => trim((string)($summary['longId'] ?? $details['longId'] ?? '')),
                    'eta_last_sync_at' => date('Y-m-d H:i:s'),
                ]);
                app_eta_einvoice_apply_purchase_financial_state($conn, $purchaseInvoiceId, $status !== '' ? $status : 'valid');
                return ['ok' => true, 'purchase_invoice_id' => $purchaseInvoiceId, 'created' => false, 'duplicate' => true];
            }
        }

        $supplierResult = app_eta_einvoice_find_or_create_supplier_from_document($conn, $details);
        if (empty($supplierResult['ok'])) {
            return $supplierResult;
        }
        $supplierId = (int)$supplierResult['supplier_id'];
        $items = app_eta_einvoice_purchase_items_from_document($conn, $details);
        $itemsJson = json_encode($items, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($itemsJson)) {
            $itemsJson = '[]';
        }
        $invDate = substr((string)($summary['dateTimeIssued'] ?? $details['dateTimeIssued'] ?? gmdate('Y-m-d')), 0, 10);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $invDate)) {
            $invDate = gmdate('Y-m-d');
        }
        $subTotal = (float)($summary['totalSales'] ?? $details['totalSalesAmount'] ?? 0);
        $discount = (float)($summary['totalDiscount'] ?? $details['totalDiscountAmount'] ?? 0);
        $tax = 0.0;
        foreach ((array)($details['taxTotals'] ?? []) as $taxRow) {
            if (!is_array($taxRow)) {
                continue;
            }
            $tax += (float)($taxRow['amount'] ?? 0);
        }
        $totalAmount = (float)($summary['total'] ?? $details['totalAmount'] ?? ($subTotal - $discount + $tax));
        $notes = 'Imported automatically from ETA received documents.';
        $submissionId = trim((string)($summary['submissionUUID'] ?? $details['submissionUUID'] ?? ''));
        $longId = trim((string)($summary['longId'] ?? $details['longId'] ?? ''));
        $validationJson = json_encode(['summary' => $summary, 'details' => $details], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($validationJson)) {
            $validationJson = '';
        }
        $etaStatus = $status !== '' ? $status : 'valid';
        $localStatus = app_eta_einvoice_is_financially_inactive_status($etaStatus) ? 'cancelled' : 'unpaid';
        $paidAmount = 0.0;
        $remainingAmount = $localStatus === 'cancelled' ? 0.0 : $totalAmount;

        $stmtInsert = $conn->prepare("
            INSERT INTO purchase_invoices (
                supplier_id, warehouse_id, inv_date, due_date, sub_total, tax, discount, total_amount, paid_amount, remaining_amount, status, items_json, notes,
                eta_uuid, eta_status, eta_submission_id, eta_long_id, eta_last_sync_at, eta_validation_json
            ) VALUES (?, NULL, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        if (!$stmtInsert) {
            return ['ok' => false, 'error' => 'purchase_import_prepare_failed'];
        }
        $now = date('Y-m-d H:i:s');
        $stmtInsert->bind_param(
            'issdddddsssssssss',
            $supplierId,
            $invDate,
            $invDate,
            $subTotal,
            $tax,
            $discount,
            $totalAmount,
            $remainingAmount,
            $localStatus,
            $itemsJson,
            $notes,
            $uuid,
            $etaStatus,
            $submissionId,
            $longId,
            $now,
            $validationJson
        );
        $ok = $stmtInsert->execute();
        $purchaseInvoiceId = (int)$stmtInsert->insert_id;
        $stmtInsert->close();
        if (!$ok || $purchaseInvoiceId <= 0) {
            return ['ok' => false, 'error' => 'purchase_import_insert_failed'];
        }
        app_assign_document_number($conn, 'purchase_invoices', $purchaseInvoiceId, 'purchase_number', 'purchase', $invDate);
        app_eta_einvoice_apply_purchase_financial_state($conn, $purchaseInvoiceId, $etaStatus);
        return [
            'ok' => true,
            'purchase_invoice_id' => $purchaseInvoiceId,
            'supplier_id' => $supplierId,
            'created' => true,
            'duplicate' => false,
        ];
    }
}

if (!function_exists('app_eta_einvoice_pull_purchase_documents_by_period')) {
    function app_eta_einvoice_pull_purchase_documents_by_period(mysqli $conn, string $dateFrom, string $dateTo, bool $autoMode = false): array
    {
        @set_time_limit(300);
        $period = app_eta_einvoice_period_chunks($dateFrom, $dateTo, 31);
        if (empty($period['ok'])) {
            return $period;
        }
        $processed = [];
        $imported = 0;
        $duplicates = 0;
        $createdSuppliers = 0;
        $errors = [];
        foreach ((array)($period['chunks'] ?? []) as $chunk) {
            if (!is_array($chunk)) {
                continue;
            }
            @set_time_limit(300);
            $continuationToken = '';
            $seenTokens = [];
            $pageGuard = 0;
            do {
                $pageGuard++;
                if ($pageGuard > 100) {
                    $errors[] = [
                        'range' => $chunk,
                        'error' => 'pagination_guard_limit',
                        'message' => 'ETA purchase pagination exceeded safe page limit.',
                    ];
                    break;
                }
                if ($continuationToken !== '') {
                    if (isset($seenTokens[$continuationToken])) {
                        $errors[] = [
                            'range' => $chunk,
                            'error' => 'duplicate_continuation_token',
                            'token' => $continuationToken,
                            'message' => 'ETA purchase pagination token repeated unexpectedly.',
                        ];
                        break;
                    }
                    $seenTokens[$continuationToken] = true;
                }
                $search = app_eta_einvoice_search_documents($conn, [
                    'issueDateFrom' => $chunk['from_utc'],
                    'issueDateTo' => $chunk['to_utc'],
                    'direction' => 'Received',
                    'pageSize' => '100',
                    'continuationToken' => $continuationToken,
                ]);
                if (empty($search['ok'])) {
                    return $search;
                }
                foreach ((array)($search['result'] ?? []) as $summary) {
                    if (!is_array($summary)) {
                        continue;
                    }
                    $uuid = trim((string)($summary['uuid'] ?? ''));
                    if ($uuid === '' || in_array($uuid, $processed, true)) {
                        continue;
                    }
                    $summaryDocumentType = strtolower(trim((string)($summary['documentType'] ?? $summary['typeName'] ?? '')));
                    if ($summaryDocumentType !== '' && !in_array($summaryDocumentType, ['i', 'ii', 'ei'], true)) {
                        continue;
                    }
                    $detailsResult = app_eta_einvoice_get_document_details($conn, $uuid);
                    if (empty($detailsResult['ok'])) {
                        $errors[] = ['uuid' => $uuid, 'error' => (string)($detailsResult['error'] ?? 'details_failed')];
                        continue;
                    }
                    $detailsDocument = (array)($detailsResult['document'] ?? []);
                    $detailsType = strtolower(trim((string)($detailsDocument['documentType'] ?? $detailsDocument['typeName'] ?? '')));
                    if ($detailsType !== '' && !in_array($detailsType, ['i', 'ii', 'ei'], true)) {
                        continue;
                    }
                    $importResult = app_eta_einvoice_import_purchase_document($conn, $summary, $detailsDocument);
                    if (empty($importResult['ok'])) {
                        $errors[] = ['uuid' => $uuid, 'error' => (string)($importResult['error'] ?? 'import_failed')];
                        continue;
                    }
                    if (!empty($importResult['duplicate'])) {
                        $duplicates++;
                    } else {
                        $imported++;
                    }
                    if (!empty($importResult['created']) && !empty($importResult['supplier_id'])) {
                        $createdSuppliers++;
                    }
                    $processed[] = $uuid;
                }
                $resultRows = (array)($search['result'] ?? []);
                $nextToken = trim((string)($search['metadata']['continuationToken'] ?? ''));
                if ($nextToken === 'EndofResultSet') {
                    $nextToken = '';
                }
                if ($nextToken !== '' && empty($resultRows)) {
                    $errors[] = [
                        'range' => $chunk,
                        'error' => 'empty_page_with_token',
                        'token' => $nextToken,
                        'message' => 'ETA purchase returned an empty page with a continuation token.',
                    ];
                    break;
                }
                $continuationToken = $nextToken;
                if ($continuationToken !== '') {
                    sleep(2);
                }
            } while ($continuationToken !== '');
            if (count((array)($period['chunks'] ?? [])) > 1) {
                sleep(1);
            }
        }

        $now = date('Y-m-d H:i:s');
        app_setting_set($conn, 'eta_einvoice_last_purchase_pull_at', $now);
        app_setting_set($conn, 'eta_einvoice_last_purchase_new_count', (string)$imported);
        if ($imported > 0) {
            app_audit_log_add($conn, 'eta.purchase_imported', [
                'entity_type' => 'eta',
                'entity_key' => 'purchase_pull',
                'details' => [
                    'from' => $period['from'],
                    'to' => $period['to'],
                    'imported' => $imported,
                    'duplicates' => $duplicates,
                    'auto_mode' => $autoMode ? 1 : 0,
                ],
            ]);
        }

        return [
            'ok' => true,
            'from' => $period['from'],
            'to' => $period['to'],
            'imported' => $imported,
            'duplicates' => $duplicates,
            'created_suppliers' => $createdSuppliers,
            'errors' => $errors,
            'auto_mode' => $autoMode,
        ];
    }
}

if (!function_exists('app_eta_einvoice_auto_pull_purchase_documents')) {
    function app_eta_einvoice_auto_pull_purchase_documents(mysqli $conn): array
    {
        $settings = app_eta_einvoice_settings($conn);
        if (empty($settings['enabled']) || empty($settings['auto_pull_documents']) || !app_is_work_runtime()) {
            return ['ok' => true, 'skipped' => true, 'reason' => 'auto_pull_disabled'];
        }
        $lastPullAt = trim((string)app_setting_get($conn, 'eta_einvoice_last_purchase_pull_at', ''));
        if ($lastPullAt !== '') {
            $lastTs = strtotime($lastPullAt);
            if ($lastTs !== false && $lastTs > (time() - 300)) {
                return ['ok' => true, 'skipped' => true, 'reason' => 'recent_pull'];
            }
        }
        $today = date('Y-m-d');
        return app_eta_einvoice_pull_purchase_documents_by_period($conn, $today, $today, true);
    }
}
