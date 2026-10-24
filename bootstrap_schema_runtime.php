<?php

if (!function_exists('app_ensure_pricing_records_schema')) {
    function app_ensure_pricing_records_schema(mysqli $conn): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;

        $conn->query("
            CREATE TABLE IF NOT EXISTS app_pricing_records (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                pricing_ref VARCHAR(40) NOT NULL DEFAULT '',
                client_id INT UNSIGNED NOT NULL DEFAULT 0,
                operation_name VARCHAR(255) NOT NULL DEFAULT '',
                pricing_mode VARCHAR(32) NOT NULL DEFAULT 'general',
                qty DECIMAL(18,3) NOT NULL DEFAULT 0,
                unit_label VARCHAR(80) NOT NULL DEFAULT '',
                total_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
                notes TEXT DEFAULT NULL,
                snapshot_json LONGTEXT DEFAULT NULL,
                created_by_user_id INT UNSIGNED NOT NULL DEFAULT 0,
                created_by_name VARCHAR(190) NOT NULL DEFAULT '',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_pricing_client (client_id),
                INDEX idx_pricing_created (created_at),
                INDEX idx_pricing_mode (pricing_mode)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }
}

if (!function_exists('app_ensure_quotes_schema')) {
    function app_ensure_quotes_schema(mysqli $conn): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;

        try {
            $conn->query("
                CREATE TABLE IF NOT EXISTS quotes (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    client_id INT NOT NULL,
                    quote_number VARCHAR(40) DEFAULT NULL,
                    created_at DATE NOT NULL,
                    valid_until DATE NOT NULL,
                    total_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                    status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
                    notes TEXT DEFAULT NULL,
                    client_comment TEXT DEFAULT NULL,
                    access_token VARCHAR(100) DEFAULT NULL,
                    items_json LONGTEXT DEFAULT NULL,
                    INDEX idx_quotes_client (client_id),
                    INDEX idx_quotes_status (status),
                    INDEX idx_quotes_created (created_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");

            $conn->query("
                CREATE TABLE IF NOT EXISTS quote_history (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    quote_id INT NOT NULL,
                    event_type VARCHAR(80) NOT NULL DEFAULT '',
                    event_label VARCHAR(190) NOT NULL DEFAULT '',
                    details_json LONGTEXT DEFAULT NULL,
                    user_id INT NOT NULL DEFAULT 0,
                    user_name VARCHAR(190) NOT NULL DEFAULT '',
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_quote_history_quote (quote_id),
                    INDEX idx_quote_history_created (created_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");

            $conn->query("
                CREATE TABLE IF NOT EXISTS quote_items (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    quote_id INT NOT NULL,
                    item_name VARCHAR(255) NOT NULL,
                    description TEXT DEFAULT NULL,
                    quantity DECIMAL(10,2) NOT NULL DEFAULT 1.00,
                    unit VARCHAR(50) DEFAULT NULL,
                    price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                    total DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                    INDEX idx_quote_items_quote (quote_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        } catch (Throwable $e) {
            error_log('quotes schema bootstrap failed: ' . $e->getMessage());
            return;
        }

        $ensureQuoteColumn = static function (string $column, string $sql) use ($conn): void {
            if (app_table_has_column($conn, 'quotes', $column)) {
                return;
            }
            try {
                $conn->query($sql);
                app_table_has_column_reset('quotes', $column);
            } catch (Throwable $e) {
                error_log('quotes schema alter failed for ' . $column . ': ' . $e->getMessage());
            }
        };

        $ensureItemColumn = static function (string $column, string $sql) use ($conn): void {
            if (app_table_has_column($conn, 'quote_items', $column)) {
                return;
            }
            try {
                $conn->query($sql);
                app_table_has_column_reset('quote_items', $column);
            } catch (Throwable $e) {
                error_log('quote_items schema alter failed for ' . $column . ': ' . $e->getMessage());
            }
        };

        $ensureQuoteColumn('quote_number', "ALTER TABLE quotes ADD COLUMN quote_number VARCHAR(40) DEFAULT NULL AFTER client_id");
        $ensureQuoteColumn('created_at', "ALTER TABLE quotes ADD COLUMN created_at DATE NOT NULL DEFAULT '2000-01-01' AFTER quote_number");
        $ensureQuoteColumn('valid_until', "ALTER TABLE quotes ADD COLUMN valid_until DATE NOT NULL DEFAULT '2000-01-01' AFTER created_at");
        $ensureQuoteColumn('total_amount', "ALTER TABLE quotes ADD COLUMN total_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER valid_until");
        $ensureQuoteColumn('status', "ALTER TABLE quotes ADD COLUMN status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending' AFTER total_amount");
        $ensureQuoteColumn('notes', "ALTER TABLE quotes ADD COLUMN notes TEXT DEFAULT NULL AFTER status");
        $ensureQuoteColumn('client_comment', "ALTER TABLE quotes ADD COLUMN client_comment TEXT DEFAULT NULL AFTER notes");
        $ensureQuoteColumn('access_token', "ALTER TABLE quotes ADD COLUMN access_token VARCHAR(100) DEFAULT NULL AFTER client_comment");
        $ensureQuoteColumn('source_pricing_record_id', "ALTER TABLE quotes ADD COLUMN source_pricing_record_id INT UNSIGNED NOT NULL DEFAULT 0 AFTER access_token");
        $ensureQuoteColumn('pricing_source_ref', "ALTER TABLE quotes ADD COLUMN pricing_source_ref VARCHAR(40) NOT NULL DEFAULT '' AFTER source_pricing_record_id");
        $ensureQuoteColumn('items_json', "ALTER TABLE quotes ADD COLUMN items_json LONGTEXT DEFAULT NULL AFTER access_token");
        $ensureQuoteColumn('currency_code', "ALTER TABLE quotes ADD COLUMN currency_code VARCHAR(8) NOT NULL DEFAULT 'EGP' AFTER total_amount");
        $ensureQuoteColumn('currency_label', "ALTER TABLE quotes ADD COLUMN currency_label VARCHAR(120) NOT NULL DEFAULT 'جنيه مصري' AFTER currency_code");
        $ensureQuoteColumn('base_currency_code', "ALTER TABLE quotes ADD COLUMN base_currency_code VARCHAR(8) NOT NULL DEFAULT 'EGP' AFTER currency_label");
        $ensureQuoteColumn('exchange_rate_to_base', "ALTER TABLE quotes ADD COLUMN exchange_rate_to_base DECIMAL(18,8) NOT NULL DEFAULT 1.00000000 AFTER base_currency_code");
        $ensureQuoteColumn('base_total_amount', "ALTER TABLE quotes ADD COLUMN base_total_amount DECIMAL(14,2) NOT NULL DEFAULT 0.00 AFTER exchange_rate_to_base");
        $ensureQuoteColumn('converted_invoice_id', "ALTER TABLE quotes ADD COLUMN converted_invoice_id INT DEFAULT NULL AFTER taxes_json");
        $ensureQuoteColumn('converted_at', "ALTER TABLE quotes ADD COLUMN converted_at DATETIME DEFAULT NULL AFTER converted_invoice_id");
        $ensureQuoteColumn('quote_review_closed_at', "ALTER TABLE quotes ADD COLUMN quote_review_closed_at DATETIME DEFAULT NULL AFTER converted_at");
        $ensureQuoteColumn('quote_review_closed_by', "ALTER TABLE quotes ADD COLUMN quote_review_closed_by INT NOT NULL DEFAULT 0 AFTER quote_review_closed_at");
        $ensureQuoteColumn('quote_review_closed_reason', "ALTER TABLE quotes ADD COLUMN quote_review_closed_reason VARCHAR(255) NOT NULL DEFAULT '' AFTER quote_review_closed_by");
        $ensureItemColumn('description', "ALTER TABLE quote_items ADD COLUMN description TEXT DEFAULT NULL AFTER item_name");
        $ensureItemColumn('unit', "ALTER TABLE quote_items ADD COLUMN unit VARCHAR(50) DEFAULT NULL AFTER quantity");
        if (app_table_exists($conn, 'invoices')) {
            $ensureInvoiceColumn = static function (string $column, string $sql) use ($conn): void {
                if (app_table_has_column($conn, 'invoices', $column)) {
                    return;
                }
                try {
                    $conn->query($sql);
                    app_table_has_column_reset('invoices', $column);
                } catch (Throwable $e) {
                    error_log('invoices schema alter failed for ' . $column . ': ' . $e->getMessage());
                }
            };
            $ensureInvoiceColumn('currency_code', "ALTER TABLE invoices ADD COLUMN currency_code VARCHAR(8) NOT NULL DEFAULT 'EGP' AFTER total_amount");
            $ensureInvoiceColumn('currency_label', "ALTER TABLE invoices ADD COLUMN currency_label VARCHAR(120) NOT NULL DEFAULT 'جنيه مصري' AFTER currency_code");
            $ensureInvoiceColumn('base_currency_code', "ALTER TABLE invoices ADD COLUMN base_currency_code VARCHAR(8) NOT NULL DEFAULT 'EGP' AFTER currency_label");
            $ensureInvoiceColumn('exchange_rate_to_base', "ALTER TABLE invoices ADD COLUMN exchange_rate_to_base DECIMAL(18,8) NOT NULL DEFAULT 1.00000000 AFTER base_currency_code");
            $ensureInvoiceColumn('base_total_amount', "ALTER TABLE invoices ADD COLUMN base_total_amount DECIMAL(14,2) NOT NULL DEFAULT 0.00 AFTER exchange_rate_to_base");
        }

    }
}

if (!function_exists('app_ensure_suppliers_schema')) {
    function app_ensure_suppliers_schema(mysqli $conn): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;

        try {
            $conn->query("
                CREATE TABLE IF NOT EXISTS suppliers (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    name VARCHAR(255) NOT NULL,
                    category VARCHAR(100) DEFAULT NULL,
                    phone VARCHAR(50) DEFAULT NULL,
                    email VARCHAR(120) DEFAULT NULL,
                    address TEXT DEFAULT NULL,
                    contact_person VARCHAR(120) DEFAULT NULL,
                    opening_balance DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                    current_balance DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                    notes TEXT DEFAULT NULL,
                    access_token VARCHAR(100) DEFAULT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        } catch (Throwable $e) {
            error_log('suppliers schema bootstrap failed: ' . $e->getMessage());
            return;
        }

        $ensureColumn = static function (string $column, string $sql) use ($conn): void {
            if (app_table_has_column($conn, 'suppliers', $column)) {
                return;
            }
            try {
                $conn->query($sql);
                app_table_has_column_reset('suppliers', $column);
            } catch (Throwable $e) {
                error_log('suppliers schema alter failed for ' . $column . ': ' . $e->getMessage());
            }
        };

        $ensureColumn('category', "ALTER TABLE suppliers ADD COLUMN category VARCHAR(100) DEFAULT NULL AFTER name");
        $ensureColumn('phone', "ALTER TABLE suppliers ADD COLUMN phone VARCHAR(50) DEFAULT NULL AFTER category");
        $ensureColumn('email', "ALTER TABLE suppliers ADD COLUMN email VARCHAR(120) DEFAULT NULL AFTER phone");
        $ensureColumn('address', "ALTER TABLE suppliers ADD COLUMN address TEXT DEFAULT NULL AFTER email");
        $ensureColumn('contact_person', "ALTER TABLE suppliers ADD COLUMN contact_person VARCHAR(120) DEFAULT NULL AFTER address");
        $ensureColumn('opening_balance', "ALTER TABLE suppliers ADD COLUMN opening_balance DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER contact_person");
        $ensureColumn('current_balance', "ALTER TABLE suppliers ADD COLUMN current_balance DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER opening_balance");
        $ensureColumn('notes', "ALTER TABLE suppliers ADD COLUMN notes TEXT DEFAULT NULL AFTER current_balance");
        $ensureColumn('access_token', "ALTER TABLE suppliers ADD COLUMN access_token VARCHAR(100) DEFAULT NULL AFTER notes");
        $ensureColumn('created_at', "ALTER TABLE suppliers ADD COLUMN created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP AFTER access_token");
        $ensureColumn('tax_number', "ALTER TABLE suppliers ADD COLUMN tax_number VARCHAR(60) DEFAULT NULL AFTER email");
        $ensureColumn('tax_id', "ALTER TABLE suppliers ADD COLUMN tax_id VARCHAR(60) DEFAULT NULL AFTER tax_number");
        $ensureColumn('national_id', "ALTER TABLE suppliers ADD COLUMN national_id VARCHAR(30) DEFAULT NULL AFTER tax_id");
        $ensureColumn('country_code', "ALTER TABLE suppliers ADD COLUMN country_code VARCHAR(2) NOT NULL DEFAULT 'EG' AFTER national_id");
        $ensureColumn('eta_receiver_type', "ALTER TABLE suppliers ADD COLUMN eta_receiver_type VARCHAR(1) NOT NULL DEFAULT 'B' AFTER country_code");
    }
}

if (!function_exists('app_ensure_clients_eta_schema')) {
    function app_ensure_clients_eta_schema(mysqli $conn): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;

        $ensureColumn = static function (string $column, string $sql) use ($conn): void {
            if (app_table_has_column($conn, 'clients', $column)) {
                return;
            }
            try {
                $conn->query($sql);
                app_table_has_column_reset('clients', $column);
            } catch (Throwable $e) {
                error_log('clients eta schema alter failed for ' . $column . ': ' . $e->getMessage());
            }
        };

        $ensureColumn('tax_number', "ALTER TABLE clients ADD COLUMN tax_number VARCHAR(60) DEFAULT NULL AFTER email");
        $ensureColumn('tax_id', "ALTER TABLE clients ADD COLUMN tax_id VARCHAR(60) DEFAULT NULL AFTER tax_number");
        $ensureColumn('national_id', "ALTER TABLE clients ADD COLUMN national_id VARCHAR(30) DEFAULT NULL AFTER tax_id");
        $ensureColumn('country_code', "ALTER TABLE clients ADD COLUMN country_code VARCHAR(2) NOT NULL DEFAULT 'EG' AFTER national_id");
        $ensureColumn('eta_receiver_type', "ALTER TABLE clients ADD COLUMN eta_receiver_type VARCHAR(1) NOT NULL DEFAULT 'B' AFTER country_code");
    }
}

if (!function_exists('app_ensure_taxation_schema')) {
    function app_ensure_taxation_schema(mysqli $conn): bool
    {
        static $ok = null;
        if ($ok !== null) {
            return $ok;
        }

        try {
            app_ensure_clients_eta_schema($conn);
            $ensureInvoiceColumn = static function (string $column, string $sql) use ($conn): void {
                if (app_table_has_column($conn, 'invoices', $column)) {
                    return;
                }
                $conn->query($sql);
                app_table_has_column_reset('invoices', $column);
            };
            $ensureQuoteColumn = static function (string $column, string $sql) use ($conn): void {
                if (app_table_has_column($conn, 'quotes', $column)) {
                    return;
                }
                $conn->query($sql);
                app_table_has_column_reset('quotes', $column);
            };

            $ensureInvoiceColumn('invoice_kind', "ALTER TABLE invoices ADD COLUMN invoice_kind VARCHAR(20) NOT NULL DEFAULT 'standard' AFTER due_date");
            $ensureInvoiceColumn('tax_law_key', "ALTER TABLE invoices ADD COLUMN tax_law_key VARCHAR(60) DEFAULT NULL AFTER invoice_kind");
            $ensureInvoiceColumn('taxes_json', "ALTER TABLE invoices ADD COLUMN taxes_json LONGTEXT DEFAULT NULL AFTER items_json");
            $ensureInvoiceColumn('tax_total', "ALTER TABLE invoices ADD COLUMN tax_total DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER tax");
            $ensureInvoiceColumn('source_quote_id', "ALTER TABLE invoices ADD COLUMN source_quote_id INT DEFAULT NULL AFTER job_id");
            $ensureInvoiceColumn('eta_uuid', "ALTER TABLE invoices ADD COLUMN eta_uuid VARCHAR(120) DEFAULT NULL AFTER source_quote_id");
            $ensureInvoiceColumn('eta_status', "ALTER TABLE invoices ADD COLUMN eta_status VARCHAR(40) DEFAULT NULL AFTER eta_uuid");
            $ensureInvoiceColumn('eta_submission_id', "ALTER TABLE invoices ADD COLUMN eta_submission_id VARCHAR(120) DEFAULT NULL AFTER eta_status");
            $ensureInvoiceColumn('eta_last_sync_at', "ALTER TABLE invoices ADD COLUMN eta_last_sync_at DATETIME DEFAULT NULL AFTER eta_submission_id");
            $ensureInvoiceColumn('eta_validation_json', "ALTER TABLE invoices ADD COLUMN eta_validation_json LONGTEXT DEFAULT NULL AFTER eta_last_sync_at");
            $ensurePurchaseColumn = static function (string $column, string $sql) use ($conn): void {
                if (app_table_has_column($conn, 'purchase_invoices', $column)) {
                    return;
                }
                $conn->query($sql);
                app_table_has_column_reset('purchase_invoices', $column);
            };
            $ensurePurchaseColumn('eta_uuid', "ALTER TABLE purchase_invoices ADD COLUMN eta_uuid VARCHAR(120) DEFAULT NULL AFTER notes");
            $ensurePurchaseColumn('supplier_display_name', "ALTER TABLE purchase_invoices ADD COLUMN supplier_display_name VARCHAR(255) DEFAULT NULL AFTER supplier_id");
            $ensurePurchaseColumn('eta_status', "ALTER TABLE purchase_invoices ADD COLUMN eta_status VARCHAR(40) DEFAULT NULL AFTER eta_uuid");
            $ensurePurchaseColumn('eta_submission_id', "ALTER TABLE purchase_invoices ADD COLUMN eta_submission_id VARCHAR(120) DEFAULT NULL AFTER eta_status");
            $ensurePurchaseColumn('eta_long_id', "ALTER TABLE purchase_invoices ADD COLUMN eta_long_id VARCHAR(190) DEFAULT NULL AFTER eta_submission_id");
            $ensurePurchaseColumn('eta_last_sync_at', "ALTER TABLE purchase_invoices ADD COLUMN eta_last_sync_at DATETIME DEFAULT NULL AFTER eta_long_id");
            $ensurePurchaseColumn('eta_validation_json', "ALTER TABLE purchase_invoices ADD COLUMN eta_validation_json LONGTEXT DEFAULT NULL AFTER eta_last_sync_at");
            $purchaseStatusMeta = $conn->query("SHOW COLUMNS FROM purchase_invoices LIKE 'status'")->fetch_assoc();
            $purchaseStatusType = strtolower((string)($purchaseStatusMeta['Type'] ?? ''));
            if ($purchaseStatusType !== '' && strpos($purchaseStatusType, 'enum(') === 0 && strpos($purchaseStatusType, "'cancelled'") === false) {
                $conn->query("ALTER TABLE purchase_invoices MODIFY COLUMN status ENUM('unpaid','partially_paid','paid','cancelled') DEFAULT 'unpaid'");
                app_table_has_column_reset('purchase_invoices', 'status');
            }

            $ensureQuoteColumn('quote_kind', "ALTER TABLE quotes ADD COLUMN quote_kind VARCHAR(20) NOT NULL DEFAULT 'standard' AFTER valid_until");
            $ensureQuoteColumn('tax_law_key', "ALTER TABLE quotes ADD COLUMN tax_law_key VARCHAR(60) DEFAULT NULL AFTER quote_kind");
            $ensureQuoteColumn('taxes_json', "ALTER TABLE quotes ADD COLUMN taxes_json LONGTEXT DEFAULT NULL AFTER items_json");
            $ensureQuoteColumn('tax_total', "ALTER TABLE quotes ADD COLUMN tax_total DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER total_amount");

            if (app_setting_get($conn, 'tax_types_catalog', '') === '') {
                app_setting_set($conn, 'tax_types_catalog', json_encode(app_tax_default_types(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            }
            if (app_setting_get($conn, 'tax_law_catalog', '') === '') {
                app_setting_set($conn, 'tax_law_catalog', json_encode(app_tax_default_laws(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            }
            if (app_setting_get($conn, 'tax_default_sales_law', '') === '') {
                app_setting_set($conn, 'tax_default_sales_law', 'vat_2016');
            }
            if (app_setting_get($conn, 'tax_default_quote_law', '') === '') {
                app_setting_set($conn, 'tax_default_quote_law', 'vat_2016');
            }

            $ok = true;
        } catch (Throwable $e) {
            error_log('app_ensure_taxation_schema failed: ' . $e->getMessage());
            $ok = false;
        }

        return $ok;
    }
}

if (!function_exists('app_tax_default_types')) {
    function app_tax_default_types(): array
    {
        return [
            [
                'key' => 'vat_14',
                'name' => 'ضريبة القيمة المضافة',
                'name_en' => 'Value Added Tax',
                'category' => 'vat',
                'rate' => 14,
                'mode' => 'add',
                'base' => 'net_after_discount',
                'scopes' => ['sales', 'quotes'],
                'is_active' => 1,
            ],
            [
                'key' => 'withholding_1',
                'name' => 'خصم تحت حساب الضريبة',
                'name_en' => 'Withholding Tax',
                'category' => 'withholding',
                'rate' => 1,
                'mode' => 'subtract',
                'base' => 'net_after_discount',
                'scopes' => ['sales', 'quotes'],
                'is_active' => 0,
            ],
            [
                'key' => 'stamp_0_4',
                'name' => 'ضريبة / رسم دمغة',
                'name_en' => 'Stamp Tax',
                'category' => 'stamp',
                'rate' => 0.4,
                'mode' => 'add',
                'base' => 'net_after_discount',
                'scopes' => ['sales', 'quotes'],
                'is_active' => 0,
            ],
        ];
    }
}

if (!function_exists('app_tax_default_laws')) {
    function app_tax_default_laws(): array
    {
        return [
            [
                'key' => 'vat_2016',
                'name' => 'ضريبة القيمة المضافة',
                'name_en' => 'VAT Law',
                'category' => 'vat',
                'frequency' => 'monthly',
                'settlement_mode' => 'vat_offset',
                'is_active' => 1,
                'notes' => 'تستخدم لإقرارات القيمة المضافة والمقاصة بين المخرجات والمدخلات.',
            ],
            [
                'key' => 'income_91_2005',
                'name' => 'ضريبة الدخل',
                'name_en' => 'Income Tax Law',
                'category' => 'income',
                'frequency' => 'annual',
                'settlement_mode' => 'standalone',
                'is_active' => 1,
                'notes' => 'تعتمد على صافي الربح بعد التسويات القانونية وفق الدليل المرفق.',
            ],
            [
                'key' => 'simplified_6_2025',
                'name' => 'النظام المبسط للمشروعات الصغيرة',
                'name_en' => 'Simplified SME Tax Regime',
                'category' => 'simplified',
                'frequency' => 'annual',
                'settlement_mode' => 'turnover_based',
                'is_active' => 1,
                'notes' => 'يعتمد على حجم الأعمال السنوي مع بقاء القيمة المضافة منفصلة.',
            ],
            [
                'key' => 'procedures_206_2020',
                'name' => 'الإجراءات الضريبية الموحد',
                'name_en' => 'Unified Tax Procedures',
                'category' => 'procedural',
                'frequency' => 'informational',
                'settlement_mode' => 'informational',
                'is_active' => 1,
                'notes' => 'حاكم لدورة المستندات والإقرارات وليس وعاءً ضريبياً مستقلاً.',
            ],
        ];
    }
}

if (!function_exists('app_tax_normalize_type')) {
    function app_tax_normalize_type(array $row): ?array
    {
        $key = strtolower(trim((string)($row['key'] ?? '')));
        $name = trim((string)($row['name'] ?? ''));
        if ($key === '' || !preg_match('/^[a-z0-9_]{2,60}$/', $key) || $name === '') {
            return null;
        }
        $category = strtolower(trim((string)($row['category'] ?? 'other')));
        if (!in_array($category, ['vat', 'withholding', 'stamp', 'other'], true)) {
            $category = 'other';
        }
        $mode = strtolower(trim((string)($row['mode'] ?? 'add')));
        if (!in_array($mode, ['add', 'subtract'], true)) {
            $mode = 'add';
        }
        $base = strtolower(trim((string)($row['base'] ?? 'net_after_discount')));
        if (!in_array($base, ['subtotal', 'net_after_discount'], true)) {
            $base = 'net_after_discount';
        }
        $scopesRaw = $row['scopes'] ?? ['sales', 'quotes'];
        $scopes = [];
        foreach ((array)$scopesRaw as $scope) {
            $scope = strtolower(trim((string)$scope));
            if (in_array($scope, ['sales', 'quotes', 'purchase', 'all'], true) && !in_array($scope, $scopes, true)) {
                $scopes[] = $scope;
            }
        }
        if (empty($scopes)) {
            $scopes = ['sales', 'quotes'];
        }

        return [
            'key' => $key,
            'name' => $name,
            'name_en' => trim((string)($row['name_en'] ?? $name)),
            'category' => $category,
            'rate' => round((float)($row['rate'] ?? 0), 4),
            'mode' => $mode,
            'base' => $base,
            'scopes' => $scopes,
            'is_active' => (int)($row['is_active'] ?? 0) === 1 ? 1 : 0,
        ];
    }
}

if (!function_exists('app_tax_normalize_law')) {
    function app_tax_normalize_law(array $row): ?array
    {
        $key = strtolower(trim((string)($row['key'] ?? '')));
        $name = trim((string)($row['name'] ?? ''));
        if ($key === '' || !preg_match('/^[a-z0-9_]{2,60}$/', $key) || $name === '') {
            return null;
        }
        $category = strtolower(trim((string)($row['category'] ?? 'procedural')));
        if (!in_array($category, ['vat', 'income', 'simplified', 'stamp', 'procedural'], true)) {
            $category = 'procedural';
        }
        $frequency = strtolower(trim((string)($row['frequency'] ?? 'monthly')));
        if (!in_array($frequency, ['monthly', 'quarterly', 'annual', 'informational'], true)) {
            $frequency = 'monthly';
        }
        $settlementMode = strtolower(trim((string)($row['settlement_mode'] ?? 'informational')));
        if (!in_array($settlementMode, ['vat_offset', 'standalone', 'turnover_based', 'informational'], true)) {
            $settlementMode = 'informational';
        }

        return [
            'key' => $key,
            'name' => $name,
            'name_en' => trim((string)($row['name_en'] ?? $name)),
            'category' => $category,
            'frequency' => $frequency,
            'settlement_mode' => $settlementMode,
            'is_active' => (int)($row['is_active'] ?? 0) === 1 ? 1 : 0,
            'notes' => trim((string)($row['notes'] ?? '')),
        ];
    }
}

if (!function_exists('app_tax_catalog')) {
    function app_tax_catalog(mysqli $conn, bool $activeOnly = false, string $scope = 'all'): array
    {
        $raw = trim(app_setting_get($conn, 'tax_types_catalog', ''));
        $decoded = $raw !== '' ? json_decode($raw, true) : null;
        if (!is_array($decoded)) {
            $decoded = app_tax_default_types();
        }

        $rows = [];
        foreach ($decoded as $row) {
            if (!is_array($row)) {
                continue;
            }
            $normalized = app_tax_normalize_type($row);
            if ($normalized === null) {
                continue;
            }
            if ($activeOnly && (int)$normalized['is_active'] !== 1) {
                continue;
            }
            if ($scope !== 'all' && !in_array('all', $normalized['scopes'], true) && !in_array($scope, $normalized['scopes'], true)) {
                continue;
            }
            $rows[] = $normalized;
        }
        return $rows;
    }
}

if (!function_exists('app_tax_law_catalog')) {
    function app_tax_law_catalog(mysqli $conn, bool $activeOnly = false): array
    {
        $raw = trim(app_setting_get($conn, 'tax_law_catalog', ''));
        $decoded = $raw !== '' ? json_decode($raw, true) : null;
        if (!is_array($decoded)) {
            $decoded = app_tax_default_laws();
        }

        $rows = [];
        foreach ($decoded as $row) {
            if (!is_array($row)) {
                continue;
            }
            $normalized = app_tax_normalize_law($row);
            if ($normalized === null) {
                continue;
            }
            if ($activeOnly && (int)$normalized['is_active'] !== 1) {
                continue;
            }
            $rows[] = $normalized;
        }
        return $rows;
    }
}

if (!function_exists('app_tax_find_law')) {
    function app_tax_find_law(mysqli $conn, string $lawKey): ?array
    {
        foreach (app_tax_law_catalog($conn, false) as $law) {
            if ((string)$law['key'] === $lawKey) {
                return $law;
            }
        }
        return null;
    }
}

if (!function_exists('app_tax_is_tax_invoice')) {
    function app_tax_is_tax_invoice(string $kind): bool
    {
        return strtolower(trim($kind)) === 'tax';
    }
}

if (!function_exists('app_tax_calculate_document')) {
    function app_tax_calculate_document(array $catalog, string $kind, float $subTotal, float $discount, array $selectedKeys): array
    {
        $subTotal = round(max(0.0, $subTotal), 2);
        $discount = round(max(0.0, $discount), 2);
        $netBase = round(max(0.0, $subTotal - $discount), 2);

        if (!app_tax_is_tax_invoice($kind)) {
            return [
                'sub_total' => $subTotal,
                'discount' => $discount,
                'net_base' => $netBase,
                'tax_total' => 0.0,
                'grand_total' => $netBase,
                'lines' => [],
            ];
        }

        $catalogMap = [];
        foreach ($catalog as $taxType) {
            $catalogMap[(string)$taxType['key']] = $taxType;
        }

        $lines = [];
        $taxTotal = 0.0;
        foreach ($selectedKeys as $selectedKeyRaw) {
            $selectedKey = strtolower(trim((string)$selectedKeyRaw));
            if ($selectedKey === '' || !isset($catalogMap[$selectedKey])) {
                continue;
            }
            $taxType = $catalogMap[$selectedKey];
            if ((int)($taxType['is_active'] ?? 0) !== 1) {
                continue;
            }

            $baseAmount = ((string)($taxType['base'] ?? 'net_after_discount') === 'subtotal') ? $subTotal : $netBase;
            $rate = round((float)($taxType['rate'] ?? 0), 4);
            $amount = round(($baseAmount * $rate) / 100, 2);
            $signedAmount = ((string)($taxType['mode'] ?? 'add') === 'subtract') ? -$amount : $amount;
            $taxTotal += $signedAmount;

            $lines[] = [
                'key' => (string)$taxType['key'],
                'name' => (string)$taxType['name'],
                'name_en' => (string)($taxType['name_en'] ?? $taxType['name']),
                'category' => (string)($taxType['category'] ?? 'other'),
                'rate' => $rate,
                'mode' => (string)($taxType['mode'] ?? 'add'),
                'base' => (string)($taxType['base'] ?? 'net_after_discount'),
                'base_amount' => $baseAmount,
                'amount' => $amount,
                'signed_amount' => $signedAmount,
            ];
        }

        $taxTotal = round($taxTotal, 2);
        return [
            'sub_total' => $subTotal,
            'discount' => $discount,
            'net_base' => $netBase,
            'tax_total' => $taxTotal,
            'grand_total' => round($netBase + $taxTotal, 2),
            'lines' => $lines,
        ];
    }
}

if (!function_exists('app_tax_decode_lines')) {
    function app_tax_decode_lines($raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }
}

if (!function_exists('app_ensure_job_assets_schema')) {
    function app_ensure_job_assets_schema(mysqli $conn): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;

        $targets = [
            ['table' => 'job_files', 'column' => 'description', 'type' => 'TEXT DEFAULT NULL'],
            ['table' => 'job_proofs', 'column' => 'description', 'type' => 'TEXT DEFAULT NULL'],
            ['table' => 'job_proofs', 'column' => 'item_index', 'type' => 'INT DEFAULT 0'],
            ['table' => 'job_proofs', 'column' => 'client_comment', 'type' => 'TEXT DEFAULT NULL'],
        ];

        foreach ($targets as $target) {
            $table = (string)$target['table'];
            $column = (string)$target['column'];
            $type = (string)$target['type'];
            if (app_table_has_column($conn, $table, $column)) {
                continue;
            }
            try {
                $conn->query("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$type}");
                app_table_has_column_reset($table, $column);
            } catch (Throwable $e) {
                error_log("job assets schema alter failed for {$table}.{$column}: " . $e->getMessage());
            }
        }

        if (!app_table_has_index($conn, 'job_files', 'idx_job_files_job_id')) {
            try {
                $conn->query("ALTER TABLE `job_files` ADD INDEX `idx_job_files_job_id` (`job_id`)");
                app_table_has_index_reset('job_files', 'idx_job_files_job_id');
            } catch (Throwable $e) {
                error_log('job assets schema index failed for job_files.job_id: ' . $e->getMessage());
            }
        }
    }
}

if (!function_exists('app_ensure_social_schema')) {
    function app_ensure_social_schema(mysqli $conn): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;

        try {
            $conn->query("
                CREATE TABLE IF NOT EXISTS social_posts (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    job_id INT NOT NULL,
                    post_index INT NOT NULL,
                    idea_text TEXT,
                    idea_status VARCHAR(50) DEFAULT 'pending',
                    idea_feedback TEXT,
                    content_text TEXT,
                    design_path TEXT,
                    status VARCHAR(50) DEFAULT 'pending',
                    client_feedback TEXT,
                    platform VARCHAR(50),
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        } catch (Throwable $e) {
            error_log('social schema bootstrap failed: ' . $e->getMessage());
            return;
        }

        $ensure = static function (string $column, string $sql) use ($conn): void {
            if (app_table_has_column($conn, 'social_posts', $column)) {
                return;
            }
            try {
                $conn->query($sql);
                app_table_has_column_reset('social_posts', $column);
            } catch (Throwable $e) {
                error_log('social schema alter failed for ' . $column . ': ' . $e->getMessage());
            }
        };

        try {
            $colsRs = $conn->query("SHOW COLUMNS FROM social_posts");
            $columns = [];
            if ($colsRs) {
                while ($col = $colsRs->fetch_assoc()) {
                    $columns[(string)$col['Field']] = strtolower((string)$col['Type']);
                }
            }
            if (isset($columns['design_path']) && strpos((string)$columns['design_path'], 'text') === false) {
                $conn->query("ALTER TABLE social_posts MODIFY design_path TEXT");
            }
        } catch (Throwable $e) {
            error_log('social schema inspect failed: ' . $e->getMessage());
        }

        $ensure('idea_text', "ALTER TABLE social_posts ADD COLUMN idea_text TEXT");
        $ensure('idea_status', "ALTER TABLE social_posts ADD COLUMN idea_status VARCHAR(50) DEFAULT 'pending'");
        $ensure('idea_feedback', "ALTER TABLE social_posts ADD COLUMN idea_feedback TEXT");

        if (!app_table_has_index($conn, 'social_posts', 'idx_social_posts_job_id')) {
            try {
                $conn->query("ALTER TABLE `social_posts` ADD INDEX `idx_social_posts_job_id` (`job_id`)");
                app_table_has_index_reset('social_posts', 'idx_social_posts_job_id');
            } catch (Throwable $e) {
                error_log('social schema index failed for social_posts.job_id: ' . $e->getMessage());
            }
        }

        if (!app_table_has_index($conn, 'social_posts', 'idx_social_posts_job_post')) {
            try {
                $conn->query("ALTER TABLE `social_posts` ADD INDEX `idx_social_posts_job_post` (`job_id`, `post_index`)");
                app_table_has_index_reset('social_posts', 'idx_social_posts_job_post');
            } catch (Throwable $e) {
                error_log('social schema index failed for social_posts.job_id,post_index: ' . $e->getMessage());
            }
        }
    }
}

if (!function_exists('app_ensure_payroll_schema')) {
    function app_ensure_payroll_schema(mysqli $conn): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;

        try {
            $conn->query("
                CREATE TABLE IF NOT EXISTS payroll_sheets (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    payroll_number VARCHAR(40) DEFAULT NULL,
                    employee_id INT NOT NULL,
                    employee_name_snapshot VARCHAR(190) NOT NULL DEFAULT '',
                    month_year VARCHAR(7) NOT NULL,
                    basic_salary DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                    bonus DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                    deductions DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                    loan_deduction DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                    net_salary DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                    paid_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                    remaining_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                    status VARCHAR(40) NOT NULL DEFAULT 'pending',
                    notes TEXT DEFAULT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY uq_payroll_employee_month (employee_id, month_year)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        } catch (Throwable $e) {
            error_log('payroll schema bootstrap failed: ' . $e->getMessage());
            return;
        }

        $ensureColumn = static function (string $column, string $sql) use ($conn): void {
            if (app_table_has_column($conn, 'payroll_sheets', $column)) {
                return;
            }
            try {
                $conn->query($sql);
                app_table_has_column_reset('payroll_sheets', $column);
            } catch (Throwable $e) {
                error_log('payroll schema alter failed for ' . $column . ': ' . $e->getMessage());
            }
        };

        $ensureColumn('payroll_number', "ALTER TABLE payroll_sheets ADD COLUMN payroll_number VARCHAR(40) DEFAULT NULL AFTER id");
        $ensureColumn('employee_name_snapshot', "ALTER TABLE payroll_sheets ADD COLUMN employee_name_snapshot VARCHAR(190) NOT NULL DEFAULT '' AFTER employee_id");
        $ensureColumn('basic_salary', "ALTER TABLE payroll_sheets ADD COLUMN basic_salary DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER month_year");
        $ensureColumn('bonus', "ALTER TABLE payroll_sheets ADD COLUMN bonus DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER basic_salary");
        $ensureColumn('deductions', "ALTER TABLE payroll_sheets ADD COLUMN deductions DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER bonus");
        $ensureColumn('loan_deduction', "ALTER TABLE payroll_sheets ADD COLUMN loan_deduction DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER deductions");
        $ensureColumn('net_salary', "ALTER TABLE payroll_sheets ADD COLUMN net_salary DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER loan_deduction");
        $ensureColumn('paid_amount', "ALTER TABLE payroll_sheets ADD COLUMN paid_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER net_salary");
        $ensureColumn('remaining_amount', "ALTER TABLE payroll_sheets ADD COLUMN remaining_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER paid_amount");
        $ensureColumn('status', "ALTER TABLE payroll_sheets ADD COLUMN status VARCHAR(40) NOT NULL DEFAULT 'pending' AFTER remaining_amount");
        $ensureColumn('notes', "ALTER TABLE payroll_sheets ADD COLUMN notes TEXT DEFAULT NULL AFTER status");
        $ensureColumn('created_at', "ALTER TABLE payroll_sheets ADD COLUMN created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP AFTER notes");
        try {
            $conn->query("
                UPDATE payroll_sheets p
                LEFT JOIN users u ON u.id = p.employee_id
                SET p.employee_name_snapshot = COALESCE(NULLIF(TRIM(u.full_name), ''), p.employee_name_snapshot)
                WHERE TRIM(COALESCE(p.employee_name_snapshot, '')) = ''
            ");
        } catch (Throwable $e) {
            error_log('payroll snapshot backfill failed: ' . $e->getMessage());
        }
    }
}

if (!function_exists('app_super_user_reference')) {
    function app_super_user_reference(): array
    {
        $id = max(0, (int)app_env('APP_SUPER_USER_ID', '0'));
        $username = strtolower(trim((string)app_env('APP_SUPER_USER_USERNAME', '')));
        $email = strtolower(trim((string)app_env('APP_SUPER_USER_EMAIL', '')));

        // If env values are not fully configured, fallback to DB-managed super user identity.
        // This enables first-time bootstrap from inside the system UI.
        if (
            ($id <= 0 || $username === '' || $email === '')
            && isset($GLOBALS['conn'])
            && $GLOBALS['conn'] instanceof mysqli
            && function_exists('app_setting_get')
        ) {
            try {
                /** @var mysqli $conn */
                $conn = $GLOBALS['conn'];
                if ($id <= 0) {
                    $id = max(0, (int)app_setting_get($conn, 'super_user_id', '0'));
                }
                if ($username === '') {
                    $username = strtolower(trim((string)app_setting_get($conn, 'super_user_username', '')));
                }
                if ($email === '') {
                    $email = strtolower(trim((string)app_setting_get($conn, 'super_user_email', '')));
                }
            } catch (Throwable $e) {
                // keep env-only values on any DB-read failure
            }
        }

        return [
            'id' => $id,
            'username' => $username,
            'email' => $email,
        ];
    }
}

if (!function_exists('app_is_super_user')) {
    function app_is_super_user(): bool
    {
        app_start_session();
        if (strtolower((string)($_SESSION['role'] ?? '')) !== 'admin') {
            return false;
        }

        $ref = app_super_user_reference();
        $configured = false;

        if ((int)$ref['id'] > 0) {
            $configured = true;
            if ((int)($_SESSION['user_id'] ?? 0) === (int)$ref['id']) {
                return true;
            }
        }

        if ((string)$ref['username'] !== '') {
            $configured = true;
            $username = strtolower(trim((string)($_SESSION['username'] ?? '')));
            if ($username !== '' && hash_equals((string)$ref['username'], $username)) {
                return true;
            }
        }

        if ((string)$ref['email'] !== '') {
            $configured = true;
            $email = strtolower(trim((string)($_SESSION['email'] ?? '')));
            if ($email !== '' && hash_equals((string)$ref['email'], $email)) {
                return true;
            }
        }

        // No configured super-user identifier means licensing controls stay locked.
        if (!$configured) {
            return false;
        }

        return false;
    }
}


if (!function_exists('app_translations')) {
    function app_translations(): array
    {
        static $cache = null;
        if (is_array($cache)) {
            return $cache;
        }

        // built-in translations for Arabic/English and other defaults
        $base = [
            'en' => [
                'nav.home' => 'Home',
                'nav.jobs.new' => 'New Job',
                'nav.more' => 'More',
                'nav.data.master' => 'Master Data',
                'nav.users' => 'Users',
                'nav.profile' => 'My Profile',
                'nav.logout' => 'Logout',
                'nav.finance' => 'Finance',
                'nav.invoices' => 'Invoices',
                'nav.finance_reports' => 'Finance Reports',
                'nav.inventory' => 'Inventory',
                'nav.warehouses' => 'Warehouses',
                'nav.stock' => 'Stock Movement',
                'nav.quotes' => 'Quotations',
                'nav.clients' => 'Clients',
                'nav.suppliers' => 'Suppliers',
                'nav.customization' => 'Customization & Maintenance',
                'nav.backup' => 'Backups',
                'nav.install_app' => 'Install App',
                'nav.menu' => 'Menu',
                'common.access_denied' => 'Access denied',
                'common.current_account' => 'Current account',
                'users.title' => 'Users Management',
                'users.subtitle' => 'Create, update, delete, and control user permissions.',
                'users.form.add' => 'Add User',
                'users.form.edit' => 'Edit User',
                'users.form.full_name' => 'Full Name',
                'users.form.username' => 'Username',
                'users.form.phone' => 'Phone',
                'users.form.email' => 'Email',
                'users.form.role' => 'Role',
                'users.form.password' => 'Password',
                'users.form.password_optional' => 'Password (optional)',
                'users.form.avatar' => 'Avatar',
                'users.form.save_new' => 'Create User',
                'users.form.save_edit' => 'Save Changes',
                'users.form.cancel' => 'Cancel',
                'users.permissions.open' => 'Open Permissions (Allow)',
                'users.permissions.close' => 'Close Permissions (Deny)',
                'users.permissions.help' => 'Allowed permissions grant access even if role does not include them. Denied permissions block access even if role allows them.',
                'md.title' => 'Master Data & Customization',
                'md.section.brand' => 'Branding',
                'md.section.types' => 'Operation Types',
                'md.section.stages' => 'Operation Stages',
                'md.section.catalog' => 'Catalog (materials/services)',
                'md.section.seq' => 'Smart Numbering',
                'md.btn.save' => 'Save',
                'md.btn.add_update' => 'Add / Update',
                'md.btn.delete' => 'Delete',
                'md.btn.enable' => 'Enable',
                'md.btn.disable' => 'Disable',
                'md.help.type_key' => 'Stable internal key used in routes and process mapping (letters, numbers, underscore).',
                'md.help.stage_key' => 'Stable internal key for workflow transitions and tracking.',
                'md.help.group' => 'Category key for catalog grouping (example: material, service, feature).',
                'md.help.prefix' => 'Prefix shown before document numbers, e.g. INV-.',
                'common.lang' => 'Language',
                'common.ar' => 'Arabic',
                'common.en' => 'English',
            ],
        ];

        // load external JSON translation files
        $dir = __DIR__ . '/i18n';
        if (is_dir($dir)) {
            foreach (glob($dir . '/*.json') as $file) {
                $code = basename($file, '.json');
                $raw = file_get_contents($file);
                if ($raw === false) {
                    continue;
                }
                $json = json_decode($raw, true);
                if (is_array($json)) {
                    if (!isset($base[$code])) {
                        $base[$code] = [];
                    }
                    // merge and allow project-specific JSON to override built-in defaults
                    $base[$code] = array_replace($base[$code], $json);
                } else {
                    error_log("Invalid translation JSON file: $file");
                }
            }
        }

        $cache = $base;
        return $cache;
    }
}

if (!function_exists('app_t')) {
    function app_t(string $key, string $fallback = ''): string
    {
        $lang = app_current_lang();
        $map = app_translations();
        if (isset($map[$lang]) && is_array($map[$lang]) && array_key_exists($key, $map[$lang])) {
            return (string)$map[$lang][$key];
        }
        // log a warning when a translation is missing (helps review)
        if ($lang !== 'ar') {
            error_log("Missing translation for key '$key' in language '$lang'");
        }
        return $fallback !== '' ? $fallback : $key;
    }
}

if (!function_exists('app_lang_is')) {
    function app_lang_is(string $lang): bool
    {
        return app_current_lang() === app_normalize_lang($lang);
    }
}

if (!function_exists('app_tr')) {
    function app_tr(string $arabic, string $english): string
    {
        // simple convenience, but encourage use of `app_t` with keys
        return app_lang_is('en') ? $english : $arabic;
    }
}

if (!function_exists('app_base_url')) {
    function app_base_url(): string
    {
        $configured = app_env('SYSTEM_URL');
        if (!empty($configured)) {
            return rtrim($configured, '/');
        }
        $scheme = app_is_https() ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        return $scheme . '://' . $host;
    }
}

if (!function_exists('app_job_access_token')) {
    function app_job_access_token(mysqli $conn, array &$job): string
    {
        $jobId = (int)($job['id'] ?? 0);
        if ($jobId <= 0) {
            return '';
        }
        $token = trim((string)($job['access_token'] ?? ''));
        if ($token !== '') {
            return $token;
        }
        $token = bin2hex(random_bytes(16));
        $stmt = $conn->prepare('UPDATE job_orders SET access_token = ? WHERE id = ?');
        $stmt->bind_param('si', $token, $jobId);
        $stmt->execute();
        $stmt->close();
        $job['access_token'] = $token;
        return $token;
    }
}

if (!function_exists('app_quote_access_token')) {
    function app_quote_access_token(mysqli $conn, array &$quote): string
    {
        $quoteId = (int)($quote['id'] ?? 0);
        if ($quoteId <= 0) {
            return '';
        }
        $token = trim((string)($quote['access_token'] ?? ''));
        if ($token !== '') {
            return $token;
        }
        $token = bin2hex(random_bytes(16));
        $stmt = $conn->prepare('UPDATE quotes SET access_token = ? WHERE id = ?');
        if ($stmt) {
            $stmt->bind_param('si', $token, $quoteId);
            $stmt->execute();
            $stmt->close();
        }
        $quote['access_token'] = $token;
        return $token;
    }
}

if (!function_exists('app_quote_view_link')) {
    function app_quote_view_link(mysqli $conn, array &$quote): string
    {
        $token = app_quote_access_token($conn, $quote);
        if ($token === '') {
            return '';
        }
        return app_base_url() . '/view_quote.php?token=' . rawurlencode($token);
    }
}

if (!function_exists('app_supplier_access_token')) {
    function app_supplier_access_token(mysqli $conn, array &$supplier): string
    {
        $supplierId = (int)($supplier['id'] ?? 0);
        if ($supplierId <= 0) {
            return '';
        }
        $token = trim((string)($supplier['access_token'] ?? ''));
        if ($token !== '') {
            return $token;
        }
        $token = bin2hex(random_bytes(16));
        $stmt = $conn->prepare('UPDATE suppliers SET access_token = ? WHERE id = ?');
        if ($stmt) {
            $stmt->bind_param('si', $token, $supplierId);
            $stmt->execute();
            $stmt->close();
        }
        $supplier['access_token'] = $token;
        return $token;
    }
}

if (!function_exists('app_supplier_financial_review_link')) {
    function app_supplier_financial_review_link(mysqli $conn, array &$supplier): string
    {
        $token = app_supplier_access_token($conn, $supplier);
        if ($token === '') {
            return '';
        }
        return app_base_url() . '/financial_review.php?token=' . rawurlencode($token) . '&type=supplier';
    }
}

if (!function_exists('app_client_review_link')) {
    function app_client_review_link(mysqli $conn, array &$job): string
    {
        $stage = strtolower(trim((string)($job['current_stage'] ?? '')));
        if (!in_array($stage, ['client_rev', 'idea_review', 'content_review', 'design_review'], true)) {
            return '';
        }

        $token = app_job_access_token($conn, $job);
        if ($token === '') {
            return '';
        }
        return app_base_url() . '/client_review.php?token=' . rawurlencode($token);
    }
}

if (!function_exists('app_payroll_employee_outstanding_loan')) {
    function app_payroll_employee_outstanding_loan(mysqli $conn, int $employeeId): float
    {
        if ($employeeId <= 0) {
            return 0.0;
        }
        $employeeId = (int)$employeeId;
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
        }
        $given = (float)($conn->query("SELECT IFNULL(SUM(amount),0) FROM financial_receipts WHERE employee_id = {$employeeId} AND type = 'out' AND category = 'loan'")->fetch_row()[0] ?? 0);
        $givenAllocated = (float)($conn->query("
            SELECT IFNULL(SUM(a.amount),0)
            FROM financial_receipt_allocations a
            INNER JOIN financial_receipts r ON r.id = a.receipt_id
            WHERE r.employee_id = {$employeeId}
              AND r.type = 'out'
              AND LOWER(TRIM(IFNULL(r.category, ''))) = 'loan'
              AND a.allocation_type = 'loan_advance'
        ")->fetch_row()[0] ?? 0);
        $repaidCash = (float)($conn->query("SELECT IFNULL(SUM(amount),0) FROM financial_receipts WHERE employee_id = {$employeeId} AND type = 'in' AND category IN ('loan','loan_repayment')")->fetch_row()[0] ?? 0);
        $repaidPayroll = (float)($conn->query("SELECT IFNULL(SUM(loan_deduction),0) FROM payroll_sheets WHERE employee_id = {$employeeId}")->fetch_row()[0] ?? 0);
        $outstanding = round($given + $givenAllocated - $repaidCash - $repaidPayroll, 2);
        return $outstanding > 0 ? $outstanding : 0.0;
    }
}

if (!function_exists('app_payroll_bind_salary_receipt_credits')) {
    function app_payroll_bind_salary_receipt_credits(mysqli $conn, int $payrollId): void
    {
        $payrollId = (int)$payrollId;
        if ($payrollId <= 0) {
            return;
        }

        $stmtSheet = $conn->prepare("SELECT id, employee_id, net_salary FROM payroll_sheets WHERE id = ? LIMIT 1");
        $stmtSheet->bind_param('i', $payrollId);
        $stmtSheet->execute();
        $sheet = $stmtSheet->get_result()->fetch_assoc();
        $stmtSheet->close();
        if (!$sheet) {
            return;
        }

        $employeeId = (int)($sheet['employee_id'] ?? 0);
        $netSalary = max(0.0, round((float)($sheet['net_salary'] ?? 0), 2));
        if ($employeeId <= 0 || $netSalary <= 0.00001) {
            return;
        }

        $directStmt = $conn->prepare("
            SELECT IFNULL(SUM(amount), 0)
            FROM financial_receipts
            WHERE payroll_id = ?
              AND type = 'out'
              AND LOWER(TRIM(IFNULL(category, ''))) = 'salary'
        ");
        $directStmt->bind_param('i', $payrollId);
        $directStmt->execute();
        $directPaid = (float)($directStmt->get_result()->fetch_row()[0] ?? 0);
        $directStmt->close();

        $allocStmt = $conn->prepare("
            SELECT IFNULL(SUM(a.amount), 0)
            FROM financial_receipt_allocations a
            INNER JOIN financial_receipts r ON r.id = a.receipt_id
            WHERE a.allocation_type = 'payroll'
              AND a.target_id = ?
              AND r.type = 'out'
              AND LOWER(TRIM(IFNULL(r.category, ''))) = 'salary'
        ");
        $allocStmt->bind_param('i', $payrollId);
        $allocStmt->execute();
        $allocPaid = (float)($allocStmt->get_result()->fetch_row()[0] ?? 0);
        $allocStmt->close();

        $needed = round($netSalary - ($directPaid + $allocPaid), 2);
        if ($needed <= 0.00001) {
            return;
        }

        $legacyAllocSql = "
            SELECT a.id, a.receipt_id, a.amount
            FROM financial_receipt_allocations a
            INNER JOIN financial_receipts r ON r.id = a.receipt_id
            WHERE a.allocation_type = 'loan_advance'
              AND a.target_id = {$employeeId}
              AND r.employee_id = {$employeeId}
              AND r.type = 'out'
              AND LOWER(TRIM(IFNULL(r.category, ''))) = 'salary'
            ORDER BY r.trans_date ASC, r.id ASC, a.id ASC
        ";
        $legacyAllocRes = $conn->query($legacyAllocSql);
        if ($legacyAllocRes) {
            while (($row = $legacyAllocRes->fetch_assoc()) && $needed > 0.00001) {
                $allocationId = (int)($row['id'] ?? 0);
                $receiptId = (int)($row['receipt_id'] ?? 0);
                $amount = round((float)($row['amount'] ?? 0), 2);
                if ($allocationId <= 0 || $receiptId <= 0 || $amount <= 0.00001) {
                    continue;
                }

                $move = min($amount, $needed);
                if ($move <= 0.00001) {
                    continue;
                }

                $stmtInsert = $conn->prepare("
                    INSERT INTO financial_receipt_allocations (receipt_id, allocation_type, target_id, amount, notes)
                    VALUES (?, 'payroll', ?, ?, 'Salary prepayment rebinding')
                ");
                $stmtInsert->bind_param('iid', $receiptId, $payrollId, $move);
                $stmtInsert->execute();
                $stmtInsert->close();

                $remainingAlloc = round($amount - $move, 2);
                if ($remainingAlloc <= 0.00001) {
                    $stmtDelete = $conn->prepare("DELETE FROM financial_receipt_allocations WHERE id = ?");
                    $stmtDelete->bind_param('i', $allocationId);
                    $stmtDelete->execute();
                    $stmtDelete->close();
                } else {
                    $stmtUpdate = $conn->prepare("UPDATE financial_receipt_allocations SET amount = ? WHERE id = ?");
                    $stmtUpdate->bind_param('di', $remainingAlloc, $allocationId);
                    $stmtUpdate->execute();
                    $stmtUpdate->close();
                }

                $needed = round($needed - $move, 2);
            }
        }

        if ($needed <= 0.00001) {
            return;
        }

        $salaryCreditsSql = "
            SELECT
                r.id,
                r.amount,
                IFNULL(alloc.total_allocated, 0) AS total_allocated
            FROM financial_receipts r
            LEFT JOIN (
                SELECT receipt_id, IFNULL(SUM(amount), 0) AS total_allocated
                FROM financial_receipt_allocations
                GROUP BY receipt_id
            ) alloc ON alloc.receipt_id = r.id
            WHERE r.employee_id = {$employeeId}
              AND r.type = 'out'
              AND LOWER(TRIM(IFNULL(r.category, ''))) = 'salary'
            ORDER BY r.trans_date ASC, r.id ASC
        ";
        $salaryCreditsRes = $conn->query($salaryCreditsSql);
        if ($salaryCreditsRes) {
            while (($row = $salaryCreditsRes->fetch_assoc()) && $needed > 0.00001) {
                $receiptId = (int)($row['id'] ?? 0);
                $amount = round((float)($row['amount'] ?? 0), 2);
                $allocated = round((float)($row['total_allocated'] ?? 0), 2);
                $available = round($amount - $allocated, 2);
                if ($receiptId <= 0 || $available <= 0.00001) {
                    continue;
                }

                $move = min($available, $needed);
                if ($move <= 0.00001) {
                    continue;
                }

                $stmtInsert = $conn->prepare("
                    INSERT INTO financial_receipt_allocations (receipt_id, allocation_type, target_id, amount, notes)
                    VALUES (?, 'payroll', ?, ?, 'Salary receipt credit')
                ");
                $stmtInsert->bind_param('iid', $receiptId, $payrollId, $move);
                $stmtInsert->execute();
                $stmtInsert->close();

                $needed = round($needed - $move, 2);
            }
        }
    }
}

if (!function_exists('app_payroll_sync_sheet')) {
    function app_payroll_sync_sheet(mysqli $conn, int $payrollId): void
    {
        if ($payrollId <= 0) {
            return;
        }

        $stmt = $conn->prepare("SELECT net_salary FROM payroll_sheets WHERE id = ? LIMIT 1");
        $stmt->bind_param('i', $payrollId);
        $stmt->execute();
        $sheet = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$sheet) {
            return;
        }
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
        }

        if (function_exists('app_payroll_bind_salary_receipt_credits')) {
            app_payroll_bind_salary_receipt_credits($conn, $payrollId);
        }

        $stmtPaid = $conn->prepare("SELECT IFNULL(SUM(amount), 0) AS total_paid FROM financial_receipts WHERE payroll_id = ? AND type = 'out'");
        $stmtPaid->bind_param('i', $payrollId);
        $stmtPaid->execute();
        $paidRow = $stmtPaid->get_result()->fetch_assoc();
        $stmtPaid->close();
        $stmtAlloc = $conn->prepare("
            SELECT IFNULL(SUM(a.amount), 0) AS total_paid
            FROM financial_receipt_allocations a
            INNER JOIN financial_receipts r ON r.id = a.receipt_id
            WHERE a.allocation_type = 'payroll' AND a.target_id = ? AND r.type = 'out'
        ");
        $stmtAlloc->bind_param('i', $payrollId);
        $stmtAlloc->execute();
        $allocRow = $stmtAlloc->get_result()->fetch_assoc();
        $stmtAlloc->close();

        $net = max(0, (float)($sheet['net_salary'] ?? 0));
        $paid = max(0, (float)($paidRow['total_paid'] ?? 0) + (float)($allocRow['total_paid'] ?? 0));
        $remaining = round($net - $paid, 2);
        $status = 'pending';

        if ($remaining <= 0.00001) {
            $remaining = 0.0;
            $status = 'paid';
        } elseif ($paid > 0.00001) {
            $status = 'partially_paid';
        }

        $stmtUpdate = $conn->prepare("UPDATE payroll_sheets SET paid_amount = ?, remaining_amount = ?, status = ? WHERE id = ?");
        $stmtUpdate->bind_param('ddsi', $paid, $remaining, $status, $payrollId);
        $stmtUpdate->execute();
        $stmtUpdate->close();
    }
}

if (!function_exists('app_payroll_employee_label')) {
    function app_payroll_employee_label(array $row): string
    {
        $name = trim((string)($row['employee_name_snapshot'] ?? ''));
        if ($name !== '') {
            return $name;
        }
        $name = trim((string)($row['full_name'] ?? $row['emp_name'] ?? $row['party_name'] ?? ''));
        if ($name !== '') {
            return $name;
        }
        $employeeId = (int)($row['employee_id'] ?? 0);
        return $employeeId > 0 ? ('#' . $employeeId) : '-';
    }
}

if (!function_exists('app_user_is_archived')) {
    function app_user_is_archived(array $user): bool
    {
        return trim((string)($user['archived_at'] ?? '')) !== '';
    }
}

if (!function_exists('app_user_is_active_record')) {
    function app_user_is_active_record(array $user): bool
    {
        $isActive = !array_key_exists('is_active', $user) || (int)$user['is_active'] === 1;
        return $isActive && !app_user_is_archived($user);
    }
}

if (!function_exists('app_user_role')) {
    function app_user_role(): string
    {
        app_start_session();
        return (string)($_SESSION['role'] ?? 'guest');
    }
}

if (!function_exists('app_user_has_any_role')) {
    function app_user_has_any_role(array $roles): bool
    {
        $role = app_user_role();
        foreach ($roles as $allowed) {
            if ($role === (string)$allowed) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('app_user_id')) {
    function app_user_id(): int
    {
        app_start_session();
        return (int)($_SESSION['user_id'] ?? 0);
    }
}

if (!function_exists('app_user_identity_keys')) {
    function app_user_identity_keys(): array
    {
        app_start_session();
        $raw = [
            (string)($_SESSION['name'] ?? ''),
            (string)($_SESSION['username'] ?? ''),
            (string)($_SESSION['email'] ?? ''),
        ];
        $keys = [];
        foreach ($raw as $value) {
            $value = trim($value);
            if ($value === '') {
                continue;
            }
            $keys[] = function_exists('mb_strtolower')
                ? mb_strtolower($value, 'UTF-8')
                : strtolower($value);
        }
        return array_values(array_unique($keys));
    }
}

if (!function_exists('app_role_capabilities')) {
    function app_role_capabilities(string $role): array
    {
        static $map = [
            'admin' => ['*'],
            'manager' => [
                'dashboard.view',
                'jobs.view_all',
                'jobs.manage_all',
                'jobs.assign',
                'jobs.create',
                'pricing.view',
                'clients.view',
                'clients.create',
                'clients.update',
                'clients.balance.view',
                'payroll.view',
                'quotes.view',
                'quotes.create',
                'quotes.update',
                'quotes.manage',
                'quotes.approve',
                'quotes.reports',
                'payroll.create',
                'payroll.update',
                'finance.view',
                'finance.transactions.view',
                'finance.transactions.create',
                'finance.transactions.update',
                'finance.transactions.delete',
                'finance.reports.view',
                'invoices.view',
                'invoices.create',
                'invoices.update',
                'invoices.duplicate',
                'invoices.delete',
                'inventory.view',
                'inventory.items.create',
                'inventory.items.update',
                'inventory.items.delete',
                'inventory.stock.adjust',
                'inventory.warehouses.view',
                'inventory.warehouses.create',
                'inventory.warehouses.update',
                'inventory.warehouses.delete',
                'inventory.warehouses.toggle',
            ],
            'sales' => ['dashboard.view', 'jobs.create', 'jobs.view_assigned', 'jobs.edit_assigned', 'pricing.view', 'quotes.view', 'quotes.create', 'quotes.update', 'clients.view', 'clients.create', 'clients.update'],
            'designer' => ['dashboard.view', 'jobs.view_assigned', 'jobs.edit_assigned'],
            'production' => [
                'dashboard.view',
                'jobs.view_assigned',
                'jobs.edit_assigned',
                'inventory.view',
                'inventory.items.update',
                'inventory.stock.adjust',
                'inventory.warehouses.view',
            ],
            'purchasing' => [
                'dashboard.view',
                'jobs.view_assigned',
                'jobs.edit_assigned',
                'inventory.view',
                'inventory.items.create',
                'inventory.items.update',
                'inventory.stock.adjust',
                'inventory.warehouses.view',
            ],
            'monitor' => ['dashboard.view', 'jobs.view_assigned'],
            'accountant' => [
                'dashboard.view',
                'jobs.view_assigned',
                'pricing.view',
                'clients.view',
                'clients.create',
                'clients.update',
                'clients.balance.view',
                'quotes.view',
                'quotes.create',
                'quotes.update',
                'quotes.reports',
                'payroll.view',
                'payroll.create',
                'payroll.update',
                'finance.view',
                'finance.transactions.view',
                'finance.transactions.create',
                'finance.transactions.update',
                'finance.transactions.delete',
                'finance.reports.view',
                'invoices.view',
                'invoices.create',
                'invoices.update',
                'invoices.duplicate',
            ],
            'driver' => ['dashboard.view', 'jobs.view_assigned'],
            'worker' => ['dashboard.view', 'jobs.view_assigned'],
            'employee' => ['dashboard.view', 'jobs.view_assigned'],
            'guest' => [],
        ];
        $role = trim(strtolower($role));
        return $map[$role] ?? [];
    }
}

if (!function_exists('app_capability_catalog')) {
    function app_capability_catalog(): array
    {
        return [
            'dashboard.view' => ['group' => 'jobs', 'label' => 'فتح لوحة العمليات الرئيسية'],
            'jobs.create' => ['group' => 'jobs', 'label' => 'إنشاء أوامر التشغيل'],
            'jobs.view_all' => ['group' => 'jobs', 'label' => 'عرض كل العمليات'],
            'jobs.manage_all' => ['group' => 'jobs', 'label' => 'إدارة كل العمليات'],
            'jobs.assign' => ['group' => 'jobs', 'label' => 'إدارة أعضاء العملية'],
            'jobs.view_assigned' => ['group' => 'jobs', 'label' => 'عرض العمليات المسندة فقط'],
            'jobs.edit_assigned' => ['group' => 'jobs', 'label' => 'تعديل العمليات المسندة'],
            'pricing.view' => ['group' => 'pricing', 'label' => 'فتح شاشة تسعير الطباعة'],
            'pricing.settings' => ['group' => 'pricing', 'label' => 'إدارة إعدادات تسعير الطباعة'],
            'clients.view' => ['group' => 'sales', 'label' => 'عرض بيانات العملاء الأساسية'],
            'clients.create' => ['group' => 'sales', 'label' => 'إضافة عملاء جدد'],
            'clients.update' => ['group' => 'sales', 'label' => 'تعديل بيانات العملاء'],
            'clients.balance.view' => ['group' => 'sales', 'label' => 'عرض أرصدة العملاء'],
            'quotes.view' => ['group' => 'sales', 'label' => 'عرض عروض الأسعار'],
            'quotes.create' => ['group' => 'sales', 'label' => 'إنشاء عروض الأسعار'],
            'quotes.update' => ['group' => 'sales', 'label' => 'تعديل عروض الأسعار'],
            'quotes.manage' => ['group' => 'sales', 'label' => 'إدارة حالات ومرفقات عروض الأسعار'],
            'quotes.approve' => ['group' => 'sales', 'label' => 'اعتماد عروض الأسعار داخلياً'],
            'quotes.reports' => ['group' => 'sales', 'label' => 'تقارير أداء عروض الأسعار'],
            'payroll.view' => ['group' => 'finance', 'label' => 'عرض مسيرات الرواتب'],
            'payroll.create' => ['group' => 'finance', 'label' => 'إنشاء مسيرات الرواتب'],
            'payroll.update' => ['group' => 'finance', 'label' => 'تعديل مسيرات الرواتب'],
            'finance.view' => ['group' => 'finance', 'label' => 'الوصول للوحدة المالية'],
            'finance.transactions.view' => ['group' => 'finance', 'label' => 'عرض الحركات المالية'],
            'finance.transactions.create' => ['group' => 'finance', 'label' => 'إضافة حركة مالية'],
            'finance.transactions.update' => ['group' => 'finance', 'label' => 'تعديل حركة مالية'],
            'finance.transactions.delete' => ['group' => 'finance', 'label' => 'حذف حركة مالية'],
            'finance.reports.view' => ['group' => 'finance', 'label' => 'عرض التقارير المالية'],
            'invoices.view' => ['group' => 'finance', 'label' => 'عرض الفواتير'],
            'invoices.create' => ['group' => 'finance', 'label' => 'إنشاء فواتير/مسيرات جديدة'],
            'invoices.update' => ['group' => 'finance', 'label' => 'تعديل الفواتير/المسيرات'],
            'invoices.duplicate' => ['group' => 'finance', 'label' => 'تكرار فاتورة/مسير'],
            'invoices.delete' => ['group' => 'finance', 'label' => 'حذف فواتير/مسيرات'],
            'inventory.view' => ['group' => 'inventory', 'label' => 'عرض المخزون'],
            'inventory.items.create' => ['group' => 'inventory', 'label' => 'إضافة أصناف المخزون'],
            'inventory.items.update' => ['group' => 'inventory', 'label' => 'تعديل أصناف المخزون'],
            'inventory.items.delete' => ['group' => 'inventory', 'label' => 'حذف أصناف المخزون'],
            'inventory.stock.adjust' => ['group' => 'inventory', 'label' => 'تنفيذ حركات/تحويلات المخزون'],
            'inventory.warehouses.view' => ['group' => 'inventory', 'label' => 'عرض المخازن'],
            'inventory.warehouses.create' => ['group' => 'inventory', 'label' => 'إضافة مخزن'],
            'inventory.warehouses.update' => ['group' => 'inventory', 'label' => 'تعديل المخزن'],
            'inventory.warehouses.delete' => ['group' => 'inventory', 'label' => 'حذف المخزن'],
            'inventory.warehouses.toggle' => ['group' => 'inventory', 'label' => 'تفعيل/تعطيل المخزن'],
        ];
    }
}

if (!function_exists('app_capability_keys')) {
    function app_capability_keys(): array
    {
        return array_keys(app_capability_catalog());
    }
}

if (!function_exists('app_normalize_capability_list')) {
    function app_normalize_capability_list($raw, ?array $allowedKeys = null): array
    {
        $values = [];
        if (is_array($raw)) {
            $values = $raw;
        } elseif (is_string($raw) && $raw !== '') {
            $trimmed = trim($raw);
            if ($trimmed !== '' && ($trimmed[0] === '[' || $trimmed[0] === '{')) {
                $decoded = json_decode($trimmed, true);
                if (is_array($decoded)) {
                    $values = $decoded;
                }
            }
            if (empty($values)) {
                $values = preg_split('/[\s,;\n\r]+/', $trimmed) ?: [];
            }
        }

        $normalized = [];
        foreach ($values as $cap) {
            $cap = trim(strtolower((string)$cap));
            if ($cap === '') {
                continue;
            }
            $normalized[] = $cap;
        }

        $normalized = array_values(array_unique($normalized));
        if (is_array($allowedKeys)) {
            $allowedMap = array_fill_keys($allowedKeys, true);
            $normalized = array_values(array_filter($normalized, function (string $cap) use ($allowedMap): bool {
                return isset($allowedMap[$cap]);
            }));
        }
        return $normalized;
    }
}

if (!function_exists('app_session_permission_caps')) {
    function app_session_permission_caps(string $key): array
    {
        app_start_session();
        $allowedKeys = app_capability_keys();
        $raw = $_SESSION[$key] ?? [];
        return app_normalize_capability_list($raw, $allowedKeys);
    }
}

if (!function_exists('app_set_session_permission_caps')) {
    function app_set_session_permission_caps($allowRaw, $denyRaw): void
    {
        app_start_session();
        $allowedKeys = app_capability_keys();
        $_SESSION['allow_caps'] = app_normalize_capability_list($allowRaw, $allowedKeys);
        $_SESSION['deny_caps'] = app_normalize_capability_list($denyRaw, $allowedKeys);
    }
}

if (!function_exists('app_user_can')) {
    function app_user_can(string $capability): bool
    {
        $capability = trim(strtolower($capability));
        if ($capability === '') {
            return false;
        }

        $allowCaps = app_session_permission_caps('allow_caps');
        $denyCaps = app_session_permission_caps('deny_caps');
        if (in_array('*', $denyCaps, true) || in_array($capability, $denyCaps, true)) {
            return false;
        }
        if (in_array('*', $allowCaps, true) || in_array($capability, $allowCaps, true)) {
            return true;
        }

        $caps = app_role_capabilities(app_user_role());
        if (in_array('*', $caps, true)) {
            return true;
        }
        return in_array($capability, $caps, true);
    }
}

if (!function_exists('app_user_can_any')) {
    function app_user_can_any(array $capabilities): bool
    {
        foreach ($capabilities as $capability) {
            if (app_user_can((string)$capability)) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('app_table_has_column')) {
    function app_table_has_column(mysqli $conn, string $table, string $column): bool
    {
        if (!isset($GLOBALS['app_table_has_column_cache']) || !is_array($GLOBALS['app_table_has_column_cache'])) {
            $GLOBALS['app_table_has_column_cache'] = [];
        }
        $cache = &$GLOBALS['app_table_has_column_cache'];
        $key = strtolower($table . '|' . $column);
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }
        if (!preg_match('/^[a-z0-9_]+$/i', $table) || !preg_match('/^[a-z0-9_]+$/i', $column)) {
            $cache[$key] = false;
            return false;
        }
        try {
            $res = $conn->query("SHOW COLUMNS FROM `$table` LIKE '" . $conn->real_escape_string($column) . "'");
            $ok = ($res && $res->num_rows > 0);
        } catch (Throwable $e) {
            $ok = false;
            error_log('app_table_has_column failed for ' . $table . '.' . $column . ': ' . $e->getMessage());
        }
        $cache[$key] = $ok;
        return $ok;
    }
}

if (!function_exists('app_table_has_column_reset')) {
    function app_table_has_column_reset(string $table = '', string $column = ''): void
    {
        if (!isset($GLOBALS['app_table_has_column_cache']) || !is_array($GLOBALS['app_table_has_column_cache'])) {
            $GLOBALS['app_table_has_column_cache'] = [];
            return;
        }
        if ($table === '') {
            $GLOBALS['app_table_has_column_cache'] = [];
            return;
        }
        $table = strtolower(trim($table));
        $column = strtolower(trim($column));
        foreach (array_keys($GLOBALS['app_table_has_column_cache']) as $key) {
            if (strpos($key, $table . '|') !== 0) {
                continue;
            }
            if ($column !== '' && substr($key, strlen($table) + 1) !== $column) {
                continue;
            }
            unset($GLOBALS['app_table_has_column_cache'][$key]);
        }
    }
}

if (!function_exists('app_table_has_index')) {
    function app_table_has_index(mysqli $conn, string $table, string $indexName): bool
    {
        if (!isset($GLOBALS['app_table_has_index_cache']) || !is_array($GLOBALS['app_table_has_index_cache'])) {
            $GLOBALS['app_table_has_index_cache'] = [];
        }
        $cache = &$GLOBALS['app_table_has_index_cache'];
        $key = strtolower($table . '|' . $indexName);
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }
        if (!preg_match('/^[a-z0-9_]+$/i', $table) || !preg_match('/^[a-z0-9_]+$/i', $indexName)) {
            $cache[$key] = false;
            return false;
        }
        try {
            $res = $conn->query("SHOW INDEX FROM `$table` WHERE Key_name = '" . $conn->real_escape_string($indexName) . "'");
            $ok = ($res && $res->num_rows > 0);
        } catch (Throwable $e) {
            $ok = false;
            error_log('app_table_has_index failed for ' . $table . '.' . $indexName . ': ' . $e->getMessage());
        }
        $cache[$key] = $ok;
        return $ok;
    }
}

if (!function_exists('app_table_has_index_reset')) {
    function app_table_has_index_reset(string $table = '', string $indexName = ''): void
    {
        if (!isset($GLOBALS['app_table_has_index_cache']) || !is_array($GLOBALS['app_table_has_index_cache'])) {
            $GLOBALS['app_table_has_index_cache'] = [];
            return;
        }
        if ($table === '') {
            $GLOBALS['app_table_has_index_cache'] = [];
            return;
        }
        $table = strtolower(trim($table));
        $indexName = strtolower(trim($indexName));
        foreach (array_keys($GLOBALS['app_table_has_index_cache']) as $key) {
            if (strpos($key, $table . '|') !== 0) {
                continue;
            }
            if ($indexName !== '' && substr($key, strlen($table) + 1) !== $indexName) {
                continue;
            }
            unset($GLOBALS['app_table_has_index_cache'][$key]);
        }
    }
}

if (!function_exists('app_table_exists')) {
    function app_table_exists(mysqli $conn, string $table): bool
    {
        if (!preg_match('/^[a-z0-9_]+$/i', $table)) {
            return false;
        }
        try {
            $tableEsc = $conn->real_escape_string($table);
            $res = $conn->query("SHOW TABLES LIKE '{$tableEsc}'");
            return (bool)($res && $res->num_rows > 0);
        } catch (Throwable $e) {
            error_log('app_table_exists failed for ' . $table . ': ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('app_ensure_users_core_schema')) {
    function app_ensure_users_core_schema(mysqli $conn): void
    {
        static $booted = [];
        $bootKey = function_exists('spl_object_id') ? (string)spl_object_id($conn) : md5((string)mt_rand());
        if (!empty($booted[$bootKey])) {
            return;
        }
        $booted[$bootKey] = true;

        try {
            $conn->query("
                CREATE TABLE IF NOT EXISTS users (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    username VARCHAR(80) NOT NULL UNIQUE,
                    password VARCHAR(255) NOT NULL,
                    full_name VARCHAR(120) NOT NULL,
                    role VARCHAR(40) NOT NULL DEFAULT 'employee',
                    is_active TINYINT(1) NOT NULL DEFAULT 1,
                    archived_at DATETIME DEFAULT NULL,
                    archived_by INT DEFAULT NULL,
                    archived_reason VARCHAR(255) DEFAULT NULL,
                    phone VARCHAR(40) DEFAULT NULL,
                    email VARCHAR(120) DEFAULT NULL,
                    avatar VARCHAR(255) DEFAULT NULL,
                    profile_pic VARCHAR(255) DEFAULT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        } catch (Throwable $e) {
            error_log('users table bootstrap failed: ' . $e->getMessage());
            return;
        }

        if (!app_table_exists($conn, 'users')) {
            return;
        }

        $legacyName = app_table_has_column($conn, 'users', 'name');
        $legacyPasswordHash = app_table_has_column($conn, 'users', 'password_hash');
        $legacyUserTypeCol = '';
        foreach (['user_type', 'type', 'account_type'] as $candidate) {
            if (app_table_has_column($conn, 'users', $candidate)) {
                $legacyUserTypeCol = $candidate;
                break;
            }
        }
        $legacyIsAdmin = app_table_has_column($conn, 'users', 'is_admin');

        $ensureColumn = static function (string $column, string $sql) use ($conn): void {
            if (app_table_has_column($conn, 'users', $column)) {
                return;
            }
            try {
                $conn->query($sql);
                app_table_has_column_reset('users', $column);
            } catch (Throwable $e) {
                error_log('users schema alter failed for ' . $column . ': ' . $e->getMessage());
            }
        };

        $ensureColumn('id', "ALTER TABLE users ADD COLUMN id INT NOT NULL AUTO_INCREMENT PRIMARY KEY FIRST");
        $ensureColumn('username', "ALTER TABLE users ADD COLUMN username VARCHAR(80) DEFAULT NULL");
        $ensureColumn('password', "ALTER TABLE users ADD COLUMN password VARCHAR(255) NOT NULL DEFAULT ''");
        $ensureColumn('full_name', "ALTER TABLE users ADD COLUMN full_name VARCHAR(120) NOT NULL DEFAULT ''");
        $ensureColumn('role', "ALTER TABLE users ADD COLUMN role VARCHAR(40) NOT NULL DEFAULT 'employee'");
        $ensureColumn('is_active', "ALTER TABLE users ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER role");
        $ensureColumn('archived_at', "ALTER TABLE users ADD COLUMN archived_at DATETIME DEFAULT NULL AFTER is_active");
        $ensureColumn('archived_by', "ALTER TABLE users ADD COLUMN archived_by INT DEFAULT NULL AFTER archived_at");
        $ensureColumn('archived_reason', "ALTER TABLE users ADD COLUMN archived_reason VARCHAR(255) DEFAULT NULL AFTER archived_by");
        $ensureColumn('phone', "ALTER TABLE users ADD COLUMN phone VARCHAR(40) DEFAULT NULL");
        $ensureColumn('email', "ALTER TABLE users ADD COLUMN email VARCHAR(120) DEFAULT NULL");
        $ensureColumn('avatar', "ALTER TABLE users ADD COLUMN avatar VARCHAR(255) DEFAULT NULL");
        $ensureColumn('profile_pic', "ALTER TABLE users ADD COLUMN profile_pic VARCHAR(255) DEFAULT NULL");
        $ensureColumn('access_token', "ALTER TABLE users ADD COLUMN access_token VARCHAR(100) DEFAULT NULL");
        $ensureColumn('last_balance_confirm', "ALTER TABLE users ADD COLUMN last_balance_confirm DATETIME DEFAULT NULL");
        $ensureColumn('created_at', "ALTER TABLE users ADD COLUMN created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP");

        try {
            if (app_table_has_column($conn, 'users', 'username')) {
                if (app_table_has_column($conn, 'users', 'id')) {
                    if ($legacyName) {
                        $conn->query("
                            UPDATE users
                            SET username = CONCAT('user_', id)
                            WHERE username IS NULL OR TRIM(username) = ''
                        ");
                    } else {
                        $conn->query("
                            UPDATE users
                            SET username = CONCAT('user_', id)
                            WHERE username IS NULL OR TRIM(username) = ''
                        ");
                    }
                } else {
                    $conn->query("
                        UPDATE users
                        SET username = CONCAT('user_', FLOOR(RAND() * 1000000))
                        WHERE username IS NULL OR TRIM(username) = ''
                    ");
                }
            }

            if (app_table_has_column($conn, 'users', 'password') && $legacyPasswordHash) {
                $conn->query("
                    UPDATE users
                    SET password = password_hash
                    WHERE (password IS NULL OR TRIM(password) = '')
                      AND password_hash IS NOT NULL
                      AND TRIM(password_hash) <> ''
                ");
            }

            if (app_table_has_column($conn, 'users', 'full_name')) {
                if ($legacyName) {
                    $conn->query("
                        UPDATE users
                        SET full_name = name
                        WHERE (full_name IS NULL OR TRIM(full_name) = '')
                          AND name IS NOT NULL
                          AND TRIM(name) <> ''
                    ");
                }
                if (app_table_has_column($conn, 'users', 'username')) {
                    $conn->query("
                        UPDATE users
                        SET full_name = username
                        WHERE full_name IS NULL OR TRIM(full_name) = ''
                    ");
                }
            }

            if (app_table_has_column($conn, 'users', 'role')) {
                if ($legacyUserTypeCol !== '') {
                    $conn->query("
                        UPDATE users
                        SET role = LOWER(TRIM(`{$legacyUserTypeCol}`))
                        WHERE (role IS NULL OR TRIM(role) = '')
                          AND `{$legacyUserTypeCol}` IS NOT NULL
                          AND TRIM(`{$legacyUserTypeCol}`) <> ''
                    ");
                }
                if ($legacyIsAdmin) {
                    $conn->query("
                        UPDATE users
                        SET role = 'admin'
                        WHERE (role IS NULL OR TRIM(role) = '' OR LOWER(TRIM(role)) IN ('user', 'employee'))
                          AND is_admin = 1
                    ");
                }
                $conn->query("
                    UPDATE users
                    SET role = 'employee'
                    WHERE role IS NULL OR TRIM(role) = ''
                ");
            }
            if (app_table_has_column($conn, 'users', 'archived_at') && app_table_has_column($conn, 'users', 'is_active')) {
                $conn->query("UPDATE users SET is_active = 0 WHERE archived_at IS NOT NULL");
            }
        } catch (Throwable $e) {
            error_log('users schema backfill failed: ' . $e->getMessage());
        }

        try {
            $conn->query("ALTER TABLE users MODIFY username VARCHAR(80) NOT NULL");
        } catch (Throwable $e) {
            // keep best effort only
        }

        try {
            $hasUnique = false;
            $res = $conn->query("SHOW INDEX FROM users WHERE Column_name = 'username'");
            if ($res) {
                while ($row = $res->fetch_assoc()) {
                    if ((int)($row['Non_unique'] ?? 1) === 0) {
                        $hasUnique = true;
                        break;
                    }
                }
            }
            if (!$hasUnique) {
                $conn->query("ALTER TABLE users ADD UNIQUE KEY uq_users_username (username)");
            }
        } catch (Throwable $e) {
            error_log('users username unique index ensure failed: ' . $e->getMessage());
        }

        app_table_has_column_reset('users');
    }
}

if (!function_exists('app_ensure_financial_review_schema')) {
    function app_ensure_financial_review_schema(mysqli $conn): void
    {
        static $booted = [];
        $bootKey = function_exists('spl_object_id') ? (string)spl_object_id($conn) : md5((string)mt_rand());
        if (!empty($booted[$bootKey])) {
            return;
        }
        $booted[$bootKey] = true;

        app_ensure_users_core_schema($conn);

        $ensureEntityColumns = static function (string $table) use ($conn): void {
            if (!app_table_exists($conn, $table)) {
                return;
            }

            $ensureColumn = static function (string $column, string $sql) use ($conn, $table): void {
                if (app_table_has_column($conn, $table, $column)) {
                    return;
                }
                try {
                    $conn->query($sql);
                    app_table_has_column_reset($table, $column);
                } catch (Throwable $e) {
                    error_log($table . ' financial review schema alter failed for ' . $column . ': ' . $e->getMessage());
                }
            };

            $ensureColumn('access_token', "ALTER TABLE `{$table}` ADD COLUMN access_token VARCHAR(100) DEFAULT NULL");
            $ensureColumn('last_balance_confirm', "ALTER TABLE `{$table}` ADD COLUMN last_balance_confirm DATETIME DEFAULT NULL");
        };

        $ensureEntityColumns('clients');
        $ensureEntityColumns('suppliers');
        $ensureEntityColumns('users');
    }
}

if (!function_exists('app_ensure_job_acl_schema')) {
    function app_ensure_job_acl_schema(mysqli $conn): void
    {
        static $booted = [];
        $bootKey = function_exists('spl_object_id') ? (string)spl_object_id($conn) : md5((string)mt_rand());
        if (!empty($booted[$bootKey])) {
            return;
        }
        $booted[$bootKey] = true;

        try {
            $conn->query("
                CREATE TABLE IF NOT EXISTS job_assignments (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    job_id INT NOT NULL,
                    user_id INT NOT NULL,
                    assigned_role VARCHAR(40) NOT NULL DEFAULT 'member',
                    is_active TINYINT(1) NOT NULL DEFAULT 1,
                    assigned_by INT DEFAULT NULL,
                    assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    UNIQUE KEY uq_job_user (job_id, user_id),
                    KEY idx_user_active (user_id, is_active),
                    KEY idx_job_active (job_id, is_active)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        } catch (Throwable $e) {
            error_log('job_assignments init failed: ' . $e->getMessage());
        }

        try {
            if (!app_table_has_column($conn, 'job_orders', 'created_by_user_id')) {
                $conn->query("ALTER TABLE job_orders ADD COLUMN created_by_user_id INT DEFAULT NULL");
                $conn->query("CREATE INDEX idx_job_orders_creator ON job_orders (created_by_user_id)");
            }

            $seeded = function_exists('app_setting_get')
                ? app_setting_get($conn, 'acl_job_owner_seed_v1', '0')
                : '0';
            if ($seeded !== '1') {
                $conn->query("
                    UPDATE job_orders j
                    SET j.created_by_user_id = (
                        SELECT u.id
                        FROM users u
                        WHERE LOWER(TRIM(u.full_name)) = LOWER(TRIM(j.added_by))
                           OR LOWER(TRIM(u.username)) = LOWER(TRIM(j.added_by))
                        ORDER BY u.id ASC
                        LIMIT 1
                    )
                    WHERE j.created_by_user_id IS NULL
                      AND COALESCE(j.added_by, '') <> ''
                ");
                if (function_exists('app_setting_set')) {
                    app_setting_set($conn, 'acl_job_owner_seed_v1', '1');
                }
            }
        } catch (Throwable $e) {
            error_log('job ACL schema update failed: ' . $e->getMessage());
        }
    }
}

if (!function_exists('app_ensure_job_workflow_schema')) {
    function app_ensure_job_workflow_schema(mysqli $conn): void
    {
        static $booted = [];
        $bootKey = function_exists('spl_object_id') ? (string)spl_object_id($conn) : md5((string)mt_rand());
        if (!empty($booted[$bootKey])) {
            return;
        }
        $booted[$bootKey] = true;

        if (app_table_exists($conn, 'job_orders')) {
            $jobColumns = [
                'price' => "ALTER TABLE job_orders ADD COLUMN price DECIMAL(10,2) DEFAULT 0.00",
                'paid' => "ALTER TABLE job_orders ADD COLUMN paid DECIMAL(10,2) DEFAULT 0.00",
                'quantity' => "ALTER TABLE job_orders ADD COLUMN quantity INT(11) DEFAULT 0",
                'job_details' => "ALTER TABLE job_orders ADD COLUMN job_details TEXT",
                'access_token' => "ALTER TABLE job_orders ADD COLUMN access_token VARCHAR(100) DEFAULT NULL",
                'source_pricing_record_id' => "ALTER TABLE job_orders ADD COLUMN source_pricing_record_id INT UNSIGNED NOT NULL DEFAULT 0",
                'pricing_source_ref' => "ALTER TABLE job_orders ADD COLUMN pricing_source_ref VARCHAR(40) NOT NULL DEFAULT ''",
            ];
            foreach ($jobColumns as $column => $sql) {
                if (app_table_has_column($conn, 'job_orders', $column)) {
                    continue;
                }
                try {
                    $conn->query($sql);
                    app_table_has_column_reset('job_orders', $column);
                } catch (Throwable $e) {
                    error_log('job_orders schema alter failed for ' . $column . ': ' . $e->getMessage());
                }
            }
        }

        try {
            $conn->query("
                CREATE TABLE IF NOT EXISTS job_materials (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    job_id INT NOT NULL,
                    product_id INT NOT NULL,
                    warehouse_id INT NOT NULL,
                    quantity_used DECIMAL(10,2) NOT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    KEY idx_job_materials_job (job_id),
                    KEY idx_job_materials_product (product_id),
                    KEY idx_job_materials_warehouse (warehouse_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        } catch (Throwable $e) {
            error_log('job_materials init failed: ' . $e->getMessage());
        }
    }
}

if (!function_exists('app_ensure_job_stage_data_schema')) {
    function app_ensure_job_stage_data_schema(mysqli $conn): void
    {
        static $booted = [];
        $bootKey = function_exists('spl_object_id') ? (string)spl_object_id($conn) : md5((string)mt_rand());
        if (!empty($booted[$bootKey])) {
            return;
        }
        $booted[$bootKey] = true;

        try {
            $conn->query("
                CREATE TABLE IF NOT EXISTS job_stage_data (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    job_id INT NOT NULL,
                    stage_key VARCHAR(100) NOT NULL,
                    field_key VARCHAR(100) NOT NULL,
                    field_value LONGTEXT NULL,
                    updated_by_user_id INT DEFAULT NULL,
                    updated_by_user_name VARCHAR(255) DEFAULT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    UNIQUE KEY uniq_job_stage_field (job_id, stage_key, field_key),
                    KEY idx_job_stage_data_job (job_id),
                    KEY idx_job_stage_data_stage (stage_key),
                    KEY idx_job_stage_data_field (field_key)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        } catch (Throwable $e) {
            error_log('job_stage_data init failed: ' . $e->getMessage());
        }
    }
}

if (!function_exists('app_stage_data_get')) {
    function app_stage_data_get(mysqli $conn, int $jobId, string $stageKey, string $fieldKey, string $default = ''): string
    {
        if ($jobId <= 0 || $stageKey === '' || $fieldKey === '') {
            return $default;
        }
        app_ensure_job_stage_data_schema($conn);
        try {
            $stmt = $conn->prepare("
                SELECT field_value
                FROM job_stage_data
                WHERE job_id = ?
                  AND stage_key = ?
                  AND field_key = ?
                LIMIT 1
            ");
            $stmt->bind_param('iss', $jobId, $stageKey, $fieldKey);
            $stmt->execute();
            $value = $stmt->get_result()->fetch_column();
            $stmt->close();
            return $value === false || $value === null ? $default : (string)$value;
        } catch (Throwable $e) {
            error_log('job_stage_data get failed: ' . $e->getMessage());
            return $default;
        }
    }
}

if (!function_exists('app_stage_data_set')) {
    function app_stage_data_set(
        mysqli $conn,
        int $jobId,
        string $stageKey,
        string $fieldKey,
        string $fieldValue,
        int $userId = 0,
        string $userName = ''
    ): bool {
        if ($jobId <= 0 || $stageKey === '' || $fieldKey === '') {
            return false;
        }
        app_ensure_job_stage_data_schema($conn);
        try {
            $stmt = $conn->prepare("
                INSERT INTO job_stage_data (
                    job_id, stage_key, field_key, field_value, updated_by_user_id, updated_by_user_name
                ) VALUES (?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    field_value = VALUES(field_value),
                    updated_by_user_id = VALUES(updated_by_user_id),
                    updated_by_user_name = VALUES(updated_by_user_name),
                    updated_at = CURRENT_TIMESTAMP
            ");
            $stmt->bind_param('isssis', $jobId, $stageKey, $fieldKey, $fieldValue, $userId, $userName);
            $ok = $stmt->execute();
            $stmt->close();
            return (bool)$ok;
        } catch (Throwable $e) {
            error_log('job_stage_data set failed: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('app_initialize_access_control')) {
    if (!function_exists('app_parse_job_notes')) {
        function app_parse_job_notes(string $notes): array
        {
            $notes = trim($notes);
            if ($notes === '') {
                return [];
            }

            $entries = [];
            if (preg_match_all('/^\[(.+?)\]:\s*(.*?)(?=^\[.+?\]:|\z)/msu', $notes, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    $label = trim((string)($match[1] ?? ''));
                    $value = trim((string)($match[2] ?? ''));
                    if ($label === '' && $value === '') {
                        continue;
                    }
                    $entries[] = [
                        'label' => $label,
                        'value' => $value,
                    ];
                }
            }

            if (!empty($entries)) {
                return array_reverse($entries);
            }

            $lines = preg_split('/\R+/u', $notes) ?: [];
            foreach ($lines as $line) {
                $line = trim((string)$line);
                if ($line === '') {
                    continue;
                }
                $entries[] = [
                    'label' => 'ملاحظة',
                    'value' => $line,
                ];
            }

            return array_reverse($entries);
        }
    }

    if (!function_exists('app_stage_output_stage_lines')) {
        function app_stage_output_stage_lines(mysqli $conn, int $jobId, string $jobType, string $stageKey): array
        {
            if ($jobId <= 0 || $stageKey === '') {
                return [];
            }

            app_ensure_job_stage_data_schema($conn);

            $fieldLabels = [
                'ideas_summary' => 'ملخص الأفكار',
                'content_summary' => 'ملخص المحتوى',
                'designs_summary' => 'ملخص التصميمات',
                'reference_files_count' => 'عدد الملفات المرجعية',
                'content_files_count' => 'عدد ملفات المحتوى',
                'source_files_count' => 'عدد ملفات المصدر',
                'stage_update_summary' => 'ملخص تحديث المرحلة',
                'stage_files_count' => 'عدد ملفات المرحلة',
                'briefing_summary' => 'ملخص التجهيز',
                'briefing_files_count' => 'عدد ملفات التجهيز',
                'proofs_count' => 'عدد البروفات',
                'design_proofs_count' => 'عدد التصميمات',
                'handover_files_count' => 'عدد ملفات التسليم',
                'ui_files_count' => 'عدد ملفات الواجهة',
                'materials_summary' => 'ملخص الخامات',
                'materials_count' => 'عدد عناصر الخامات',
                'prepress_files_count' => 'عدد ملفات ما قبل الطباعة',
                'print_specs_summary' => 'ملخص مواصفات الطباعة',
                'cylinders_summary' => 'ملخص السلندرات',
                'cylinders_count' => 'عدد ملفات السلندرات',
                'production_summary' => 'ملخص الإنتاج',
                'imagination_notes' => 'ملاحظات التجهيز',
                'source_link' => 'رابط التسليم',
                'publish_date' => 'تاريخ النشر',
                'publish_channels' => 'قنوات النشر',
                'publish_links' => 'روابط النشر',
                'publish_notes' => 'ملاحظات النشر',
                'requirements' => 'المتطلبات',
                'dev_url' => 'رابط التطوير',
                'dev_notes' => 'ملاحظات التطوير',
                'testing_report' => 'تقرير الاختبار',
            ];

            $rows = [];
            try {
                $stmt = $conn->prepare("
                    SELECT field_key, field_value
                    FROM job_stage_data
                    WHERE job_id = ?
                      AND stage_key = ?
                    ORDER BY id ASC
                ");
                $stmt->bind_param('is', $jobId, $stageKey);
                $stmt->execute();
                $result = $stmt->get_result();
                while ($row = $result->fetch_assoc()) {
                    $fieldKey = trim((string)($row['field_key'] ?? ''));
                    $fieldValue = trim((string)($row['field_value'] ?? ''));
                    if ($fieldKey === '' || $fieldValue === '') {
                        continue;
                    }
                    $rows[] = [
                        'label' => $fieldLabels[$fieldKey] ?? ucwords(str_replace('_', ' ', $fieldKey)),
                        'value' => $fieldValue,
                    ];
                }
                $stmt->close();
            } catch (Throwable $e) {
                error_log('job_stage_output failed: ' . $e->getMessage());
                return [];
            }

            return $rows;
        }
    }

    function app_initialize_access_control(mysqli $conn): void
    {
        app_ensure_users_core_schema($conn);
        app_ensure_job_acl_schema($conn);
        try {
            if (!app_table_has_column($conn, 'users', 'allow_caps')) {
                $conn->query("ALTER TABLE users ADD COLUMN allow_caps TEXT NULL");
            }
            if (!app_table_has_column($conn, 'users', 'deny_caps')) {
                $conn->query("ALTER TABLE users ADD COLUMN deny_caps TEXT NULL");
            }
        } catch (Throwable $e) {
            error_log('user permission columns init failed: ' . $e->getMessage());
        }
    }
}

if (!function_exists('app_job_is_owner')) {
    function app_job_is_owner(mysqli $conn, int $jobId, int $userId): bool
    {
        if ($jobId <= 0 || $userId <= 0) {
            return false;
        }
        app_ensure_job_acl_schema($conn);
        $stmt = $conn->prepare("SELECT created_by_user_id, added_by FROM job_orders WHERE id = ? LIMIT 1");
        $stmt->bind_param('i', $jobId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) {
            return false;
        }
        if ((int)($row['created_by_user_id'] ?? 0) === $userId) {
            return true;
        }

        $addedBy = trim((string)($row['added_by'] ?? ''));
        if ($addedBy === '') {
            return false;
        }
        $addedKey = function_exists('mb_strtolower')
            ? mb_strtolower($addedBy, 'UTF-8')
            : strtolower($addedBy);
        return in_array($addedKey, app_user_identity_keys(), true);
    }
}

if (!function_exists('app_job_is_assigned')) {
    function app_job_is_assigned(mysqli $conn, int $jobId, int $userId): bool
    {
        if ($jobId <= 0 || $userId <= 0) {
            return false;
        }
        app_ensure_job_acl_schema($conn);
        $stmt = $conn->prepare("SELECT id FROM job_assignments WHERE job_id = ? AND user_id = ? AND is_active = 1 LIMIT 1");
        $stmt->bind_param('ii', $jobId, $userId);
        $stmt->execute();
        $ok = $stmt->get_result()->num_rows > 0;
        $stmt->close();
        return $ok;
    }
}

if (!function_exists('app_user_can_access_job')) {
    function app_user_can_access_job(mysqli $conn, int $jobId, bool $needEdit = false): bool
    {
        if ($jobId <= 0) {
            return false;
        }
        if (app_user_can('jobs.manage_all')) {
            return true;
        }
        if (!$needEdit && app_user_can('jobs.view_all')) {
            return true;
        }

        $userId = app_user_id();
        if ($userId <= 0) {
            return false;
        }

        $canViewAssigned = app_user_can('jobs.view_assigned') || app_user_can('jobs.edit_assigned');
        if (!$canViewAssigned) {
            return false;
        }

        $isLinked = app_job_is_owner($conn, $jobId, $userId) || app_job_is_assigned($conn, $jobId, $userId);
        if (!$isLinked) {
            return false;
        }

        if ($needEdit && !app_user_can('jobs.edit_assigned')) {
            return false;
        }

        return true;
    }
}

if (!function_exists('app_require_job_access')) {
    function app_require_job_access(mysqli $conn, int $jobId, bool $needEdit = false): void
    {
        if (app_user_can_access_job($conn, $jobId, $needEdit)) {
            return;
        }
        http_response_code(403);
        die('⛔ غير مصرح لك بالوصول لهذه العملية.');
    }
}

if (!function_exists('app_assign_user_to_job')) {
    function app_assign_user_to_job(mysqli $conn, int $jobId, int $userId, string $assignedRole = 'member', ?int $assignedBy = null): bool
    {
        if ($jobId <= 0 || $userId <= 0) {
            return false;
        }
        app_ensure_job_acl_schema($conn);
        $assignedRole = trim($assignedRole);
        if ($assignedRole === '') {
            $assignedRole = 'member';
        }
        $assignedById = (int)($assignedBy ?? app_user_id());
        $isActive = 1;
        $stmt = $conn->prepare("
            INSERT INTO job_assignments (job_id, user_id, assigned_role, is_active, assigned_by, assigned_at)
            VALUES (?, ?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE
                assigned_role = VALUES(assigned_role),
                is_active = 1,
                assigned_by = VALUES(assigned_by),
                updated_at = NOW()
        ");
        $stmt->bind_param('iisii', $jobId, $userId, $assignedRole, $isActive, $assignedById);
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }
}

if (!function_exists('app_unassign_user_from_job')) {
    function app_unassign_user_from_job(mysqli $conn, int $jobId, int $userId): bool
    {
        if ($jobId <= 0 || $userId <= 0) {
            return false;
        }
        app_ensure_job_acl_schema($conn);
        $stmt = $conn->prepare("UPDATE job_assignments SET is_active = 0, updated_at = NOW() WHERE job_id = ? AND user_id = ?");
        $stmt->bind_param('ii', $jobId, $userId);
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }
}

if (!function_exists('app_job_assignments')) {
    function app_job_assignments(mysqli $conn, int $jobId): array
    {
        if ($jobId <= 0) {
            return [];
        }
        app_ensure_job_acl_schema($conn);
        $stmt = $conn->prepare("
            SELECT ja.user_id, ja.assigned_role, ja.assigned_at, u.full_name, u.role
            FROM job_assignments ja
            JOIN users u ON u.id = ja.user_id
            WHERE ja.job_id = ? AND ja.is_active = 1
            ORDER BY u.full_name ASC
        ");
        $stmt->bind_param('i', $jobId);
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $stmt->close();
        return $rows;
    }
}

if (!function_exists('app_job_visibility_clause')) {
    function app_job_visibility_clause(mysqli $conn, string $jobAlias = 'j'): string
    {
        app_ensure_job_acl_schema($conn);
        if (app_user_can('jobs.manage_all') || app_user_can('jobs.view_all')) {
            return '1=1';
        }
        if (!(app_user_can('jobs.view_assigned') || app_user_can('jobs.edit_assigned'))) {
            return '1=0';
        }
        $userId = app_user_id();
        if ($userId <= 0) {
            return '1=0';
        }

        $parts = [];
        if (app_table_has_column($conn, 'job_orders', 'created_by_user_id')) {
            $parts[] = $jobAlias . ".created_by_user_id = " . $userId;
        }

        foreach (app_user_identity_keys() as $identity) {
            $safeIdentity = $conn->real_escape_string($identity);
            $parts[] = "LOWER(TRIM(COALESCE(" . $jobAlias . ".added_by, ''))) = '" . $safeIdentity . "'";
        }

        if (app_table_has_column($conn, 'job_assignments', 'job_id')) {
            $parts[] = "EXISTS (SELECT 1 FROM job_assignments ja WHERE ja.job_id = " . $jobAlias . ".id AND ja.user_id = " . $userId . " AND ja.is_active = 1)";
        }

        if (empty($parts)) {
            return '1=0';
        }
        return '(' . implode(' OR ', array_values(array_unique($parts))) . ')';
    }
}

if (!function_exists('app_secret_key')) {
    function app_secret_key(): string
    {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }

        $envSecret = app_env('APP_SECRET_KEY');
        if (!empty($envSecret)) {
            $cached = $envSecret;
            return $cached;
        }

        $secretCandidates = [
            __DIR__ . '/.app_secret',
            __DIR__ . '/app_secret.txt',
        ];
        foreach ($secretCandidates as $secretFile) {
            if (!is_file($secretFile)) {
                continue;
            }
            $existing = trim((string)file_get_contents($secretFile));
            if ($existing !== '') {
                $cached = $existing;
                return $cached;
            }
        }

        $generated = bin2hex(random_bytes(32));
        $secretFile = __DIR__ . '/.app_secret';
        $written = @file_put_contents($secretFile, $generated, LOCK_EX);
        if ($written === false) {
            $legacySeed = defined('APP_LEGACY_INVOICE_SECRET') ? APP_LEGACY_INVOICE_SECRET : 'legacy-seed';
            $generated = hash('sha256', $legacySeed . '|' . __DIR__);
        } else {
            @chmod($secretFile, 0600);
        }
        $cached = $generated;
        return $cached;
    }
}

if (!function_exists('app_public_token')) {
    function app_public_token(string $scope, $id): string
    {
        $payload = $scope . ':' . (string)intval($id);
        return hash_hmac('sha256', $payload, app_secret_key());
    }
}

if (!function_exists('app_verify_public_token')) {
    function app_verify_public_token(string $scope, $id, ?string $token, ?string $legacyToken = null): bool
    {
        if (!is_string($token) || $token === '') {
            return false;
        }
        $expected = app_public_token($scope, $id);
        if (hash_equals($expected, $token)) {
            return true;
        }
        if (is_string($legacyToken) && $legacyToken !== '' && hash_equals($legacyToken, $token)) {
            return true;
        }
        return false;
    }
}

if (!function_exists('app_ensure_dir')) {
    function app_ensure_dir(string $dir, int $mode = 0755): bool
    {
        if (is_dir($dir)) {
            return true;
        }
        return @mkdir($dir, $mode, true);
    }
}

if (!function_exists('app_upload_error_message')) {
    function app_upload_error_message(int $code): string
    {
        $map = [
            UPLOAD_ERR_INI_SIZE => 'The uploaded file exceeds server limits.',
            UPLOAD_ERR_FORM_SIZE => 'The uploaded file exceeds form limits.',
            UPLOAD_ERR_PARTIAL => 'The uploaded file was only partially uploaded.',
            UPLOAD_ERR_NO_FILE => 'No file was uploaded.',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary upload folder.',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write uploaded file to disk.',
            UPLOAD_ERR_EXTENSION => 'A PHP extension blocked the upload.',
        ];
        return $map[$code] ?? 'Unknown upload error.';
    }
}

if (!function_exists('app_is_blocked_upload_extension')) {
    function app_is_blocked_upload_extension(string $filename): bool
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if ($ext === '') {
            return false;
        }
        $blockedExt = ['php', 'phtml', 'phar', 'cgi', 'pl', 'py', 'sh', 'exe', 'bat', 'cmd', 'com', 'js', 'asp', 'aspx', 'jsp', 'ini', 'htaccess'];
        return in_array($ext, $blockedExt, true);
    }
}

if (!function_exists('app_upload_name_is_suspicious')) {
    function app_upload_name_is_suspicious(string $filename): bool
    {
        $name = strtolower(trim(basename($filename)));
        if ($name === '') {
            return true;
        }
        if (preg_match('/[\x00-\x1F\x7F]/', $name)) {
            return true;
        }
        if (substr_count($name, '.') >= 2 && preg_match('/\.(php|phtml|phar|pl|py|sh|cgi|asp|aspx|jsp)\./', $name)) {
            return true;
        }
        return false;
    }
}

if (!function_exists('app_valid_setting_key')) {
    function app_valid_setting_key(string $key): bool
    {
        return (bool)preg_match('/^[a-z0-9_.-]{1,80}$/i', $key);
    }
}

if (!function_exists('app_normalize_hex_color')) {
    function app_normalize_hex_color(?string $value, string $fallback = '#d4af37'): string
    {
        $value = trim((string)$value);
        if (preg_match('/^#[0-9a-f]{6}$/i', $value)) {
            return strtolower($value);
        }
        if (preg_match('/^#[0-9a-f]{3}$/i', $value)) {
            return '#' . strtolower($value[1] . $value[1] . $value[2] . $value[2] . $value[3] . $value[3]);
        }
        return strtolower($fallback);
    }
}

if (!function_exists('app_ensure_system_settings_table')) {
    function app_ensure_system_settings_table(mysqli $conn): bool
    {
        static $state = null;
        if ($state !== null) {
            return $state;
        }

        try {
            $sql = "CREATE TABLE IF NOT EXISTS system_settings (
                setting_key VARCHAR(80) PRIMARY KEY,
                setting_value TEXT NOT NULL,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
            $conn->query($sql);
            $state = true;
        } catch (Throwable $e) {
            error_log('system_settings init failed: ' . $e->getMessage());
            $state = false;
        }
        return $state;
    }
}

if (!function_exists('app_system_setting_defaults')) {
    function app_system_setting_defaults(): array
    {
        return [
            'app_name' => 'Arab Eagles',
            'app_logo_path' => 'assets/img/Logo.png',
            'app_lang' => 'ar',
            'theme_color' => '#d4af37',
            'ui_theme_preset' => 'midnight_gold',
            'output_theme_preset' => 'midnight_gold',
            'accent_mode' => 'adaptive',
            'dashboard_page_size' => '18',
            'opening_balance_deduction_sign' => 'positive',
            'supplier_opening_balance_deduction_sign' => 'positive',
            'timezone' => 'Africa/Cairo',
            'payment_method_paymob_url' => '',
            'payment_method_wallet_number' => '',
            'payment_method_instapay_url' => 'https://ipn.eg/S/eagles.bm/instapay/3MH6E0',
            'payment_gateway_enabled' => '0',
            'payment_gateway_rollout_state' => 'draft',
            'payment_gateway_provider' => 'manual',
            'payment_gateway_checkout_url' => '',
            'payment_gateway_provider_label_ar' => 'بوابة الدفع',
            'payment_gateway_provider_label_en' => 'Payment gateway',
            'payment_gateway_support_email' => '',
            'payment_gateway_support_whatsapp' => '',
            'payment_gateway_api_base_url' => '',
            'payment_gateway_api_version' => '',
            'payment_gateway_public_key' => '',
            'payment_gateway_secret_key' => '',
            'payment_gateway_merchant_id' => '',
            'payment_gateway_integration_id' => '',
            'payment_gateway_iframe_id' => '',
            'payment_gateway_hmac_secret' => '',
            'payment_gateway_webhook_secret' => '',
            'payment_gateway_callback_url' => '',
            'payment_gateway_webhook_url' => '',
            'payment_gateway_paymob_integration_name' => '',
            'payment_gateway_paymob_processed_callback_url' => '',
            'payment_gateway_paymob_response_callback_url' => '',
            'payment_gateway_email_notifications_enabled' => '1',
            'payment_gateway_whatsapp_notifications_enabled' => '0',
            'payment_gateway_whatsapp_mode' => 'link',
            'payment_gateway_whatsapp_access_token' => '',
            'payment_gateway_whatsapp_phone_number_id' => '',
            'payment_gateway_outbound_webhooks_enabled' => '0',
            'payment_gateway_outbound_webhooks_url' => '',
            'payment_gateway_outbound_webhooks_token' => '',
            'payment_gateway_outbound_webhooks_secret' => '',
            'payment_gateway_outbound_webhooks_events' => 'subscription.invoice_issued,subscription.invoice_paid,subscription.invoice_payment_notice,subscription.status_changed,automation.run,automation.failed',
            'payment_gateway_instructions_ar' => 'استخدم رابط السداد أو بيانات التحويل الظاهرة ثم أرسل مرجع السداد لفريق المتابعة.',
            'payment_gateway_instructions_en' => 'Use the payment link or transfer details shown, then send the payment reference to the billing team.',
            'payment_request_default_percent' => '30',
            'payment_request_default_note' => 'عربون',
            'org_name' => 'Arab Eagles',
            'org_legal_name' => '',
            'org_tax_number' => '',
            'org_commercial_number' => '',
            'org_phone_primary' => '',
            'org_phone_secondary' => '',
            'org_email' => '',
            'org_website' => '',
            'org_address' => '',
            'org_social_whatsapp' => '',
            'org_social_facebook' => '',
            'org_social_instagram' => '',
            'org_social_linkedin' => '',
            'org_social_x' => '',
            'org_social_youtube' => '',
            'org_footer_note' => '',
            'output_show_header' => '1',
            'output_show_footer' => '1',
            'output_show_logo' => '1',
            'output_show_qr' => '1',
            'output_header_items' => 'org_legal_name,org_tax_number,org_commercial_number',
            'output_footer_items' => 'org_phone_primary,org_phone_secondary,org_email,org_website,org_address',
        ];
    }
}

if (!function_exists('app_initialize_system_settings')) {
    function app_initialize_system_settings(mysqli $conn): void
    {
        static $booted = false;
        if ($booted) {
            return;
        }

        if (!app_ensure_system_settings_table($conn)) {
            $booted = true;
            return;
        }
        $defaults = app_system_setting_defaults();
        $stmt = $conn->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_key = setting_key");
        foreach ($defaults as $key => $value) {
            $k = (string)$key;
            $v = (string)$value;
            $stmt->bind_param('ss', $k, $v);
            $stmt->execute();
        }
        $stmt->close();
        // تنظيف مفاتيح قديمة خاصة بالمساعد الملغي.
        $conn->query("DELETE FROM system_settings WHERE setting_key IN (
            'ai_chip_1_label',
            'ai_chip_1_prompt',
            'ai_chip_2_label',
            'ai_chip_2_prompt',
            'ai_chip_3_label',
            'ai_chip_3_prompt'
        )");
        $booted = true;
    }
}

if (!function_exists('app_ensure_eta_einvoice_schema')) {
    function app_ensure_eta_einvoice_schema(mysqli $conn): bool
    {
        static $ok = null;
        if ($ok !== null) {
            return $ok;
        }

        try {
            app_initialize_system_settings($conn);
            app_ensure_taxation_schema($conn);

            $conn->query("
                CREATE TABLE IF NOT EXISTS eta_outbox (
                    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    invoice_id INT NOT NULL,
                    internal_number VARCHAR(80) NOT NULL DEFAULT '',
                    payload_json LONGTEXT NOT NULL,
                    payload_hash VARCHAR(64) NOT NULL DEFAULT '',
                    signing_mode VARCHAR(40) NOT NULL DEFAULT 'signing_server',
                    signature_json LONGTEXT DEFAULT NULL,
                    queue_status ENUM('draft','queued','signed','submitted','synced','failed') NOT NULL DEFAULT 'draft',
                    eta_uuid VARCHAR(120) DEFAULT NULL,
                    eta_submission_id VARCHAR(120) DEFAULT NULL,
                    submit_attempts INT UNSIGNED NOT NULL DEFAULT 0,
                    last_error TEXT DEFAULT NULL,
                    created_by_user_id INT DEFAULT NULL,
                    queued_at DATETIME DEFAULT NULL,
                    signed_at DATETIME DEFAULT NULL,
                    submitted_at DATETIME DEFAULT NULL,
                    synced_at DATETIME DEFAULT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    UNIQUE KEY uniq_eta_outbox_invoice (invoice_id),
                    KEY idx_eta_outbox_status (queue_status, id),
                    KEY idx_eta_outbox_uuid (eta_uuid)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");

            if (!app_table_has_column($conn, 'eta_outbox', 'signature_json')) {
                $conn->query("ALTER TABLE eta_outbox ADD COLUMN signature_json LONGTEXT DEFAULT NULL AFTER signing_mode");
            }
            if (!app_table_has_column($conn, 'eta_outbox', 'signed_at')) {
                $conn->query("ALTER TABLE eta_outbox ADD COLUMN signed_at DATETIME DEFAULT NULL AFTER queued_at");
            }

            $conn->query("
                CREATE TABLE IF NOT EXISTS eta_sync_log (
                    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    outbox_id BIGINT UNSIGNED DEFAULT NULL,
                    invoice_id INT DEFAULT NULL,
                    event_type VARCHAR(60) NOT NULL DEFAULT '',
                    status_before VARCHAR(40) NOT NULL DEFAULT '',
                    status_after VARCHAR(40) NOT NULL DEFAULT '',
                    response_code VARCHAR(40) NOT NULL DEFAULT '',
                    response_json LONGTEXT DEFAULT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    KEY idx_eta_sync_invoice (invoice_id, id),
                    KEY idx_eta_sync_outbox (outbox_id, id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");

            $conn->query("
                CREATE TABLE IF NOT EXISTS eta_document_map (
                    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    invoice_id INT NOT NULL,
                    internal_number VARCHAR(80) NOT NULL DEFAULT '',
                    eta_uuid VARCHAR(120) NOT NULL,
                    eta_submission_id VARCHAR(120) DEFAULT NULL,
                    eta_long_id VARCHAR(190) DEFAULT NULL,
                    eta_status VARCHAR(40) NOT NULL DEFAULT '',
                    document_type VARCHAR(10) NOT NULL DEFAULT 'I',
                    issued_at DATETIME DEFAULT NULL,
                    last_pulled_at DATETIME DEFAULT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    UNIQUE KEY uniq_eta_map_invoice (invoice_id),
                    UNIQUE KEY uniq_eta_map_uuid (eta_uuid),
                    KEY idx_eta_map_status (eta_status)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");

            $conn->query("
                CREATE TABLE IF NOT EXISTS eta_error_log (
                    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    invoice_id INT DEFAULT NULL,
                    outbox_id BIGINT UNSIGNED DEFAULT NULL,
                    phase VARCHAR(60) NOT NULL DEFAULT '',
                    error_code VARCHAR(60) NOT NULL DEFAULT '',
                    error_message TEXT NOT NULL,
                    context_json LONGTEXT DEFAULT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    KEY idx_eta_error_invoice (invoice_id, id),
                    KEY idx_eta_error_phase (phase, id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");

            $defaults = function_exists('app_eta_einvoice_default_settings')
                ? app_eta_einvoice_default_settings()
                : [
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
                    'submission_mode' => 'manual_review',
                    'auto_pull_status' => 1,
                    'auto_pull_documents' => 0,
                    'last_sync_at' => '',
                    'last_submit_at' => '',
                    'last_purchase_pull_at' => '',
                    'last_purchase_new_count' => '0',
                ];
            foreach ($defaults as $key => $value) {
                $settingKey = 'eta_einvoice_' . $key;
                $stmt = $conn->prepare("SELECT 1 FROM app_settings WHERE setting_key = ? LIMIT 1");
                if ($stmt) {
                    $stmt->bind_param('s', $settingKey);
                    $stmt->execute();
                    $exists = (bool)($stmt->get_result()?->fetch_row());
                    $stmt->close();
                    if ($exists) {
                        continue;
                    }
                }
                app_setting_set($conn, $settingKey, (string)$value);
            }

            $ok = true;
        } catch (Throwable $e) {
            error_log('app_ensure_eta_einvoice_schema failed: ' . $e->getMessage());
            $ok = false;
        }

        return $ok;
    }
}

if (!function_exists('app_ensure_password_reset_schema')) {
    function app_ensure_password_reset_schema(mysqli $conn): bool
    {
        static $state = null;
        if ($state !== null) {
            return $state;
        }

        try {
            $conn->query("
                CREATE TABLE IF NOT EXISTS password_reset_tokens (
                    id BIGINT AUTO_INCREMENT PRIMARY KEY,
                    user_id INT NOT NULL,
                    email VARCHAR(190) NOT NULL,
                    selector CHAR(16) NOT NULL UNIQUE,
                    token_hash CHAR(64) NOT NULL,
                    expires_at DATETIME NOT NULL,
                    used_at DATETIME DEFAULT NULL,
                    request_ip VARCHAR(45) DEFAULT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    KEY idx_password_reset_user (user_id),
                    KEY idx_password_reset_email (email),
                    KEY idx_password_reset_expiry (expires_at),
                    KEY idx_password_reset_used (used_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            $state = true;
        } catch (Throwable $e) {
            error_log('password reset schema init failed: ' . $e->getMessage());
            $state = false;
        }

        return $state;
    }
}

if (!function_exists('app_password_reset_issue_for_user')) {
    function app_password_reset_issue_for_user(mysqli $conn, int $userId, int $ttlMinutes = 30, string $requestIp = ''): array
    {
        if ($userId <= 0 || !app_ensure_password_reset_schema($conn)) {
            return ['ok' => false, 'error' => 'schema_unavailable'];
        }

        $stmtUser = $conn->prepare("SELECT id, username, full_name, email, phone FROM users WHERE id = ? LIMIT 1");
        $stmtUser->bind_param('i', $userId);
        $stmtUser->execute();
        $user = $stmtUser->get_result()->fetch_assoc() ?: [];
        $stmtUser->close();
        if (empty($user)) {
            return ['ok' => false, 'error' => 'user_not_found'];
        }

        $userEmail = trim((string)($user['email'] ?? ''));
        if (!filter_var($userEmail, FILTER_VALIDATE_EMAIL)) {
            $userEmail = 'user' . (int)$userId . '@local.invalid';
        }
        $ttlMinutes = max(10, min(180, $ttlMinutes));
        $requestIp = mb_substr(trim($requestIp), 0, 45);

        try {
            $selector = bin2hex(random_bytes(8));
            $token = bin2hex(random_bytes(32));
            $tokenHash = hash('sha256', $token);
            $expiresAt = date('Y-m-d H:i:s', time() + ($ttlMinutes * 60));

            $stmtCleanup = $conn->prepare("
                DELETE FROM password_reset_tokens
                WHERE (user_id = ? AND used_at IS NULL) OR used_at IS NOT NULL OR expires_at < NOW()
            ");
            $stmtCleanup->bind_param('i', $userId);
            $stmtCleanup->execute();
            $stmtCleanup->close();

            $stmtInsert = $conn->prepare("
                INSERT INTO password_reset_tokens (user_id, email, selector, token_hash, expires_at, request_ip)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmtInsert->bind_param('isssss', $userId, $userEmail, $selector, $tokenHash, $expiresAt, $requestIp);
            $ok = $stmtInsert->execute();
            $stmtInsert->close();
            if (!$ok) {
                return ['ok' => false, 'error' => 'insert_failed'];
            }

            $resetLink = rtrim(app_base_url(), '/') . '/reset_password.php?selector=' . urlencode($selector) . '&token=' . urlencode($token);

            return [
                'ok' => true,
                'error' => '',
                'reset_link' => $resetLink,
                'expires_at' => $expiresAt,
                'ttl_minutes' => $ttlMinutes,
                'user' => [
                    'id' => (int)($user['id'] ?? 0),
                    'username' => (string)($user['username'] ?? ''),
                    'full_name' => (string)($user['full_name'] ?? ''),
                    'email' => trim((string)($user['email'] ?? '')),
                    'phone' => trim((string)($user['phone'] ?? '')),
                ],
            ];
        } catch (Throwable $e) {
            error_log('app_password_reset_issue_for_user failed: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'token_generation_failed'];
        }
    }
}

if (!function_exists('app_send_email_basic')) {
    function app_send_email_basic(string $toEmail, string $subject, string $body, array $options = []): bool
    {
        $toEmail = trim($toEmail);
        if ($toEmail === '' || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        $systemUrl = (string)app_env('SYSTEM_URL', (defined('SYSTEM_URL') ? (string)SYSTEM_URL : ''));
        $host = (string)parse_url($systemUrl, PHP_URL_HOST);
        if ($host === '') {
            $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
        }
        $host = preg_replace('/[^a-z0-9.-]+/i', '', $host);
        if ($host === '') {
            $host = 'localhost';
        }
        $defaultFrom = 'no-reply@' . $host;

        $fromEmail = trim((string)($options['from_email'] ?? app_env('MAIL_FROM_ADDRESS', $defaultFrom)));
        if (!filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
            $fromEmail = $defaultFrom;
        }

        $fromName = trim((string)($options['from_name'] ?? app_env('MAIL_FROM_NAME', 'Arab Eagles ERP')));
        if ($fromName === '') {
            $fromName = 'Arab Eagles ERP';
        }
        $replyTo = trim((string)($options['reply_to'] ?? app_env('MAIL_REPLY_TO', $fromEmail)));
        if (!filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
            $replyTo = $fromEmail;
        }

        $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
        $headers = [
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
            'From: "' . str_replace(['"', "\r", "\n"], '', $fromName) . '" <' . $fromEmail . '>',
            'Reply-To: ' . $replyTo,
            'X-Mailer: PHP/' . PHP_VERSION,
        ];

        $returnPath = trim((string)app_env('MAIL_RETURN_PATH', ''));
        $additionalParams = '';
        if ($returnPath !== '' && filter_var($returnPath, FILTER_VALIDATE_EMAIL)) {
            $additionalParams = '-f ' . $returnPath;
        }

        $ok = $additionalParams !== ''
            ? @mail($toEmail, $encodedSubject, $body, implode("\r\n", $headers), $additionalParams)
            : @mail($toEmail, $encodedSubject, $body, implode("\r\n", $headers));

        if (!$ok) {
            error_log('mail() failed for recipient: ' . $toEmail);
        }

        return $ok;
    }
}

if (!function_exists('app_invoice_status_by_amounts')) {
    function app_invoice_status_by_amounts(float $paid, float $remaining): string
    {
        if ($remaining <= 0.00001) {
            return 'paid';
        }
        if ($paid > 0.00001) {
            return 'partially_paid';
        }
        return 'unpaid';
    }
}

if (!function_exists('app_payment_methods_config')) {
    function app_payment_gateway_settings(mysqli $conn): array
    {
        $rolloutState = strtolower(trim(app_setting_get($conn, 'payment_gateway_rollout_state', 'draft')));
        if (!in_array($rolloutState, ['draft', 'pending_contract', 'active'], true)) {
            $rolloutState = 'draft';
        }
        $provider = strtolower(trim(app_setting_get($conn, 'payment_gateway_provider', 'manual')));
        if ($provider === '') {
            $provider = 'manual';
        }
        $configuredEnabled = app_setting_get($conn, 'payment_gateway_enabled', '0') === '1';
        $isActive = $rolloutState === 'active';

        return [
            'rollout_state' => $rolloutState,
            'rollout_locked' => !$isActive,
            'configured_enabled' => $configuredEnabled,
            'enabled' => $configuredEnabled && $isActive,
            'provider' => $provider,
            'checkout_url' => trim(app_setting_get($conn, 'payment_gateway_checkout_url', '')),
            'provider_label_ar' => trim(app_setting_get($conn, 'payment_gateway_provider_label_ar', 'بوابة الدفع')),
            'provider_label_en' => trim(app_setting_get($conn, 'payment_gateway_provider_label_en', 'Payment gateway')),
            'support_email' => trim(app_setting_get($conn, 'payment_gateway_support_email', '')),
            'support_whatsapp' => trim(app_setting_get($conn, 'payment_gateway_support_whatsapp', '')),
            'api_base_url' => trim(app_setting_get($conn, 'payment_gateway_api_base_url', '')),
            'api_version' => trim(app_setting_get($conn, 'payment_gateway_api_version', '')),
            'public_key' => trim(app_setting_get($conn, 'payment_gateway_public_key', '')),
            'secret_key' => trim(app_setting_get($conn, 'payment_gateway_secret_key', '')),
            'merchant_id' => trim(app_setting_get($conn, 'payment_gateway_merchant_id', '')),
            'integration_id' => trim(app_setting_get($conn, 'payment_gateway_integration_id', '')),
            'iframe_id' => trim(app_setting_get($conn, 'payment_gateway_iframe_id', '')),
            'hmac_secret' => trim(app_setting_get($conn, 'payment_gateway_hmac_secret', '')),
            'webhook_secret' => trim(app_setting_get($conn, 'payment_gateway_webhook_secret', '')),
            'callback_url' => trim(app_setting_get($conn, 'payment_gateway_callback_url', '')),
            'webhook_url' => trim(app_setting_get($conn, 'payment_gateway_webhook_url', '')),
            'paymob_integration_name' => trim(app_setting_get($conn, 'payment_gateway_paymob_integration_name', '')),
            'paymob_processed_callback_url' => trim(app_setting_get($conn, 'payment_gateway_paymob_processed_callback_url', app_setting_get($conn, 'payment_gateway_callback_url', ''))),
            'paymob_response_callback_url' => trim(app_setting_get($conn, 'payment_gateway_paymob_response_callback_url', app_setting_get($conn, 'payment_gateway_webhook_url', ''))),
            'email_notifications_enabled' => $isActive && app_setting_get($conn, 'payment_gateway_email_notifications_enabled', '1') === '1',
            'whatsapp_notifications_enabled' => $isActive && app_setting_get($conn, 'payment_gateway_whatsapp_notifications_enabled', '0') === '1',
            'whatsapp_mode' => trim(app_setting_get($conn, 'payment_gateway_whatsapp_mode', 'link')),
            'whatsapp_access_token' => trim(app_setting_get($conn, 'payment_gateway_whatsapp_access_token', '')),
            'whatsapp_phone_number_id' => trim(app_setting_get($conn, 'payment_gateway_whatsapp_phone_number_id', '')),
            'outbound_webhooks_enabled' => $isActive && app_setting_get($conn, 'payment_gateway_outbound_webhooks_enabled', '0') === '1',
            'outbound_webhooks_url' => trim(app_setting_get($conn, 'payment_gateway_outbound_webhooks_url', '')),
            'outbound_webhooks_token' => trim(app_setting_get($conn, 'payment_gateway_outbound_webhooks_token', '')),
            'outbound_webhooks_secret' => trim(app_setting_get($conn, 'payment_gateway_outbound_webhooks_secret', '')),
            'outbound_webhooks_events' => trim(app_setting_get($conn, 'payment_gateway_outbound_webhooks_events', 'subscription.invoice_issued,subscription.invoice_paid,subscription.invoice_payment_notice,subscription.status_changed,automation.run,automation.failed')),
            'instructions_ar' => trim(app_setting_get($conn, 'payment_gateway_instructions_ar', 'استخدم رابط السداد أو بيانات التحويل الظاهرة ثم أرسل مرجع السداد لفريق المتابعة.')),
            'instructions_en' => trim(app_setting_get($conn, 'payment_gateway_instructions_en', 'Use the payment link or transfer details shown, then send the payment reference to the billing team.')),
        ];
    }
}

if (!function_exists('app_payment_gateway_whatsapp_link')) {
    function app_payment_gateway_whatsapp_link(string $phone, string $message): string
    {
        $digits = preg_replace('/[^0-9]/', '', $phone);
        if ($digits === '') {
            return '';
        }
        return 'https://wa.me/' . $digits . '?text=' . rawurlencode($message);
    }
}

if (!function_exists('app_payment_gateway_send_whatsapp')) {
    function app_payment_gateway_send_whatsapp(array $gatewaySettings, string $toPhone, string $message): array
    {
        $toPhone = trim($toPhone);
        $message = trim($message);
        if ($toPhone === '' || $message === '') {
            return ['ok' => false, 'mode' => 'none', 'error' => 'missing_values', 'link' => ''];
        }

        $link = app_payment_gateway_whatsapp_link($toPhone, $message);
        if (empty($gatewaySettings['whatsapp_notifications_enabled'])) {
            return ['ok' => false, 'mode' => 'disabled', 'error' => 'notifications_disabled', 'link' => $link];
        }

        $mode = strtolower(trim((string)($gatewaySettings['whatsapp_mode'] ?? 'link')));
        if ($mode !== 'api') {
            return ['ok' => true, 'mode' => 'link', 'error' => '', 'link' => $link];
        }

        $accessToken = trim((string)($gatewaySettings['whatsapp_access_token'] ?? ''));
        $phoneNumberId = trim((string)($gatewaySettings['whatsapp_phone_number_id'] ?? ''));
        $apiBaseUrl = trim((string)($gatewaySettings['api_base_url'] ?? ''));
        $apiVersion = trim((string)($gatewaySettings['api_version'] ?? ''));
        if ($apiBaseUrl === '') {
            $apiBaseUrl = 'https://graph.facebook.com';
        }
        if ($apiVersion === '') {
            $apiVersion = 'v20.0';
        }
        if ($accessToken === '' || $phoneNumberId === '') {
            return ['ok' => false, 'mode' => 'api', 'error' => 'missing_api_credentials', 'link' => $link];
        }

        $url = rtrim($apiBaseUrl, '/') . '/' . trim($apiVersion, '/') . '/' . rawurlencode($phoneNumberId) . '/messages';
        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => preg_replace('/[^0-9]/', '', $toPhone),
            'type' => 'text',
            'text' => ['preview_url' => false, 'body' => $message],
        ];
        $response = app_license_http_post_json($url, $payload, [
            'Authorization: Bearer ' . $accessToken,
        ], 20);

        return [
            'ok' => !empty($response['ok']),
            'mode' => 'api',
            'error' => (string)($response['error'] ?? ''),
            'link' => $link,
            'http_code' => (int)($response['http_code'] ?? 0),
            'body' => (string)($response['body'] ?? ''),
        ];
    }
}

if (!function_exists('app_payment_methods_config')) {
    function app_payment_methods_config(mysqli $conn): array
    {
        $methods = [];
        $gateway = app_payment_gateway_settings($conn);

        if (!empty($gateway['enabled'])) {
            $gatewayLabel = app_current_lang($conn) === 'en'
                ? ((string)($gateway['provider_label_en'] ?? 'Payment gateway'))
                : ((string)($gateway['provider_label_ar'] ?? 'بوابة الدفع'));
            $methods[] = [
                'key' => 'gateway',
                'label' => $gatewayLabel !== '' ? $gatewayLabel : 'Payment gateway',
                'type' => 'url',
                'value' => trim((string)($gateway['checkout_url'] ?? '')),
                'icon' => 'fa-credit-card',
                'provider' => (string)($gateway['provider'] ?? 'manual'),
            ];
        }

        $paymobUrl = trim(app_setting_get($conn, 'payment_method_paymob_url', ''));
        $paymobScheme = strtolower((string)parse_url($paymobUrl, PHP_URL_SCHEME));
        if ($paymobUrl !== '' && filter_var($paymobUrl, FILTER_VALIDATE_URL) && in_array($paymobScheme, ['http', 'https'], true)) {
            $methods[] = [
                'key' => 'paymob',
                'label' => 'Paymob',
                'type' => 'url',
                'value' => $paymobUrl,
                'icon' => 'fa-credit-card',
            ];
        }

        $walletNumber = trim(app_setting_get($conn, 'payment_method_wallet_number', ''));
        if ($walletNumber !== '') {
            $methods[] = [
                'key' => 'wallet',
                'label' => 'Wallet',
                'type' => 'text',
                'value' => $walletNumber,
                'icon' => 'fa-wallet',
            ];
        }

        $instapayUrl = trim(app_setting_get($conn, 'payment_method_instapay_url', ''));
        $instapayScheme = strtolower((string)parse_url($instapayUrl, PHP_URL_SCHEME));
        if ($instapayUrl !== '' && filter_var($instapayUrl, FILTER_VALIDATE_URL) && in_array($instapayScheme, ['http', 'https'], true)) {
            $methods[] = [
                'key' => 'instapay',
                'label' => 'InstaPay',
                'type' => 'url',
                'value' => $instapayUrl,
                'icon' => 'fa-bolt',
            ];
        }

        return $methods;
    }
}

if (!function_exists('app_apply_client_opening_balance_to_invoice')) {
    function app_apply_client_opening_balance_to_invoice(mysqli $conn, int $invoiceId, int $clientId, ?string $invoiceDate = null, string $createdBy = 'System'): array
    {
        if (function_exists('financeEnsureAllocationSchema')) {
            financeEnsureAllocationSchema($conn);
        }
        if ($invoiceId <= 0 || $clientId <= 0) {
            return ['ok' => false, 'applied' => 0.0, 'reason' => 'invalid_input'];
        }

        $mode = strtolower(trim(app_setting_get($conn, 'opening_balance_deduction_sign', 'positive')));
        if (!in_array($mode, ['positive', 'negative', 'both', 'none'], true)) {
            $mode = 'positive';
        }
        if ($mode === 'none') {
            return ['ok' => true, 'applied' => 0.0, 'reason' => 'disabled'];
        }

        $stmtInv = $conn->prepare("SELECT id, total_amount, IFNULL(paid_amount,0) AS paid_amount, IFNULL(remaining_amount,total_amount) AS remaining_amount, DATE(inv_date) AS inv_date FROM invoices WHERE id = ? AND client_id = ? LIMIT 1");
        $stmtInv->bind_param('ii', $invoiceId, $clientId);
        $stmtInv->execute();
        $inv = $stmtInv->get_result()->fetch_assoc();
        $stmtInv->close();
        if (!$inv) {
            return ['ok' => false, 'applied' => 0.0, 'reason' => 'invoice_not_found'];
        }

        $stmtClient = $conn->prepare("SELECT opening_balance FROM clients WHERE id = ? LIMIT 1");
        $stmtClient->bind_param('i', $clientId);
        $stmtClient->execute();
        $client = $stmtClient->get_result()->fetch_assoc();
        $stmtClient->close();
        if (!$client) {
            return ['ok' => false, 'applied' => 0.0, 'reason' => 'client_not_found'];
        }

        $opening = (float)$client['opening_balance'];
        $remaining = max(0.0, (float)$inv['remaining_amount']);
        if ($remaining <= 0.00001) {
            return ['ok' => true, 'applied' => 0.0, 'reason' => 'invoice_closed'];
        }

        $creditAvailable = 0.0;
        if ($mode === 'positive') {
            $creditAvailable = max(0.0, $opening);
        } elseif ($mode === 'negative') {
            $creditAvailable = max(0.0, -$opening);
        } else { // both
            $creditAvailable = abs($opening);
        }
        if ($creditAvailable <= 0.00001) {
            return ['ok' => true, 'applied' => 0.0, 'reason' => 'no_opening_credit'];
        }

        $apply = min($creditAvailable, $remaining);
        if ($apply <= 0.00001) {
            return ['ok' => true, 'applied' => 0.0, 'reason' => 'nothing_to_apply'];
        }

        $stmtExisting = $conn->prepare("SELECT IFNULL(SUM(amount),0) AS s FROM financial_receipts WHERE invoice_id = ? AND type = 'in' AND description LIKE 'تسوية رصيد أول المدة%'");
        $stmtExisting->bind_param('i', $invoiceId);
        $stmtExisting->execute();
        $existing = (float)($stmtExisting->get_result()->fetch_assoc()['s'] ?? 0);
        $stmtExisting->close();
        if ($existing > 0.00001) {
            return ['ok' => true, 'applied' => 0.0, 'reason' => 'already_applied'];
        }

        $paidOld = max(0.0, (float)$inv['paid_amount']);
        $paidNew = $paidOld + $apply;
        $remainingNew = max(0.0, $remaining - $apply);
        $statusNew = app_invoice_status_by_amounts($paidNew, $remainingNew);
        $dateValue = $invoiceDate ?: ($inv['inv_date'] ?: date('Y-m-d'));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateValue)) {
            $dateValue = date('Y-m-d');
        }

        if ($mode === 'positive') {
            $openingNew = $opening - $apply;
        } elseif ($mode === 'negative') {
            $openingNew = $opening + $apply;
        } else {
            $openingNew = ($opening >= 0) ? ($opening - $apply) : ($opening + $apply);
        }
        if (abs($openingNew) < 0.00001) {
            $openingNew = 0.0;
        }

        $safeCreatedBy = mb_substr($createdBy, 0, 100);
        if ($safeCreatedBy === '') {
            $safeCreatedBy = 'System';
        }

        try {
            $conn->begin_transaction();

            $stmtUpdInv = $conn->prepare("UPDATE invoices SET paid_amount = ?, remaining_amount = ?, status = ? WHERE id = ?");
            $stmtUpdInv->bind_param('ddsi', $paidNew, $remainingNew, $statusNew, $invoiceId);
            $stmtUpdInv->execute();
            $stmtUpdInv->close();

            $stmtUpdClient = $conn->prepare("UPDATE clients SET opening_balance = ? WHERE id = ?");
            $stmtUpdClient->bind_param('di', $openingNew, $clientId);
            $stmtUpdClient->execute();
            $stmtUpdClient->close();

            $desc = "تسوية رصيد أول المدة تلقائياً للفاتورة #{$invoiceId}";
            $category = 'general';
            $type = 'in';
            $stmtIns = $conn->prepare("INSERT INTO financial_receipts (type, category, amount, description, trans_date, client_id, invoice_id, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmtIns->bind_param('ssdssiis', $type, $category, $apply, $desc, $dateValue, $clientId, $invoiceId, $safeCreatedBy);
            $stmtIns->execute();
            $receiptId = (int)$stmtIns->insert_id;
            $stmtIns->close();
            if ($receiptId > 0 && function_exists('financeInsertReceiptAllocation')) {
                financeInsertReceiptAllocation($conn, $receiptId, 'client_opening', $clientId, $apply, 'Automatic opening balance settlement');
                financeInsertReceiptAllocation($conn, $receiptId, 'sales_invoice', $invoiceId, $apply, 'Automatic opening balance settlement');
            }

            $conn->commit();
            return ['ok' => true, 'applied' => $apply, 'reason' => 'applied'];
        } catch (Throwable $e) {
            $conn->rollback();
            error_log('opening balance apply failed: ' . $e->getMessage());
            return ['ok' => false, 'applied' => 0.0, 'reason' => 'exception'];
        }
    }
}

if (!function_exists('app_apply_client_receipt_credit_to_invoice')) {
    function app_apply_client_receipt_credit_to_invoice(mysqli $conn, int $invoiceId, int $clientId, ?string $invoiceDate = null, string $createdBy = 'System'): array
    {
        if (function_exists('financeEnsureAllocationSchema')) {
            financeEnsureAllocationSchema($conn);
        }
        if ($invoiceId <= 0 || $clientId <= 0) {
            return ['ok' => false, 'applied' => 0.0, 'reason' => 'invalid_input'];
        }

        $stmtInv = $conn->prepare("SELECT id, IFNULL(remaining_amount,total_amount) AS remaining_amount, DATE(inv_date) AS inv_date FROM invoices WHERE id = ? AND client_id = ? LIMIT 1");
        $stmtInv->bind_param('ii', $invoiceId, $clientId);
        $stmtInv->execute();
        $inv = $stmtInv->get_result()->fetch_assoc();
        $stmtInv->close();
        if (!$inv) {
            return ['ok' => false, 'applied' => 0.0, 'reason' => 'invoice_not_found'];
        }

        $remaining = max(0.0, round((float)($inv['remaining_amount'] ?? 0), 2));
        if ($remaining <= 0.00001) {
            return ['ok' => true, 'applied' => 0.0, 'reason' => 'invoice_closed'];
        }

        $dateValue = $invoiceDate ?: ($inv['inv_date'] ?: date('Y-m-d'));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$dateValue)) {
            $dateValue = date('Y-m-d');
        }
        $safeCreatedBy = mb_substr(trim($createdBy), 0, 100);
        if ($safeCreatedBy === '') {
            $safeCreatedBy = 'System';
        }

        $stmtCreditReceipts = $conn->prepare("
            SELECT
                r.id,
                r.amount,
                IFNULL(a.allocated_amount, 0) AS allocated_amount
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
            ORDER BY r.trans_date ASC, r.id ASC
        ");
        $stmtCreditReceipts->bind_param('i', $clientId);
        $stmtCreditReceipts->execute();
        $creditRes = $stmtCreditReceipts->get_result();

        $plans = [];
        $applyTotal = 0.0;
        $remainingToApply = $remaining;
        while ($creditRes && ($row = $creditRes->fetch_assoc())) {
            if ($remainingToApply <= 0.00001) {
                break;
            }
            $receiptId = (int)($row['id'] ?? 0);
            $receiptAmount = round((float)($row['amount'] ?? 0), 2);
            $allocatedAmount = round((float)($row['allocated_amount'] ?? 0), 2);
            $available = max(0.0, round($receiptAmount - $allocatedAmount, 2));
            if ($receiptId <= 0 || $available <= 0.00001) {
                continue;
            }
            $pay = min($available, $remainingToApply);
            if ($pay <= 0.00001) {
                continue;
            }
            $plans[] = ['receipt_id' => $receiptId, 'amount' => $pay];
            $applyTotal += $pay;
            $remainingToApply = max(0.0, round($remainingToApply - $pay, 2));
        }
        $stmtCreditReceipts->close();

        if ($applyTotal <= 0.00001) {
            return ['ok' => true, 'applied' => 0.0, 'reason' => 'no_receipt_credit'];
        }

        try {
            $conn->begin_transaction();
            foreach ($plans as $plan) {
                if (function_exists('financeInsertReceiptAllocation')) {
                    financeInsertReceiptAllocation(
                        $conn,
                        (int)$plan['receipt_id'],
                        'sales_invoice',
                        $invoiceId,
                        (float)$plan['amount'],
                        'Automatic receipt credit settlement'
                    );
                }
            }
            if (function_exists('recalculateSalesInvoice')) {
                recalculateSalesInvoice($conn, $invoiceId);
            }
            $conn->commit();
            return ['ok' => true, 'applied' => round($applyTotal, 2), 'reason' => 'applied'];
        } catch (Throwable $e) {
            $conn->rollback();
            error_log('receipt credit apply failed: ' . $e->getMessage());
            return ['ok' => false, 'applied' => 0.0, 'reason' => 'exception'];
        }
    }
}

if (!function_exists('app_apply_supplier_opening_balance_to_purchase_invoice')) {
    function app_apply_supplier_opening_balance_to_purchase_invoice(mysqli $conn, int $purchaseInvoiceId, int $supplierId, ?string $invoiceDate = null, string $createdBy = 'System'): array
    {
        if (function_exists('financeEnsureAllocationSchema')) {
            financeEnsureAllocationSchema($conn);
        }
        if ($purchaseInvoiceId <= 0 || $supplierId <= 0) {
            return ['ok' => false, 'applied' => 0.0, 'reason' => 'invalid_input'];
        }

        $mode = strtolower(trim(app_setting_get($conn, 'supplier_opening_balance_deduction_sign', app_setting_get($conn, 'opening_balance_deduction_sign', 'positive'))));
        if (!in_array($mode, ['positive', 'negative', 'both', 'none'], true)) {
            $mode = 'positive';
        }
        if ($mode === 'none') {
            return ['ok' => true, 'applied' => 0.0, 'reason' => 'disabled'];
        }

        $stmtInv = $conn->prepare("SELECT id, total_amount, IFNULL(paid_amount,0) AS paid_amount, IFNULL(remaining_amount,total_amount) AS remaining_amount, DATE(inv_date) AS inv_date FROM purchase_invoices WHERE id = ? AND supplier_id = ? LIMIT 1");
        $stmtInv->bind_param('ii', $purchaseInvoiceId, $supplierId);
        $stmtInv->execute();
        $inv = $stmtInv->get_result()->fetch_assoc();
        $stmtInv->close();
        if (!$inv) {
            return ['ok' => false, 'applied' => 0.0, 'reason' => 'invoice_not_found'];
        }

        $stmtSupplier = $conn->prepare("SELECT opening_balance FROM suppliers WHERE id = ? LIMIT 1");
        $stmtSupplier->bind_param('i', $supplierId);
        $stmtSupplier->execute();
        $supplier = $stmtSupplier->get_result()->fetch_assoc();
        $stmtSupplier->close();
        if (!$supplier) {
            return ['ok' => false, 'applied' => 0.0, 'reason' => 'supplier_not_found'];
        }

        $opening = (float)$supplier['opening_balance'];
        $remaining = max(0.0, (float)$inv['remaining_amount']);
        if ($remaining <= 0.00001) {
            return ['ok' => true, 'applied' => 0.0, 'reason' => 'invoice_closed'];
        }

        $creditAvailable = 0.0;
        if ($mode === 'positive') {
            $creditAvailable = max(0.0, $opening);
        } elseif ($mode === 'negative') {
            $creditAvailable = max(0.0, -$opening);
        } else { // both
            $creditAvailable = abs($opening);
        }
        if ($creditAvailable <= 0.00001) {
            return ['ok' => true, 'applied' => 0.0, 'reason' => 'no_opening_credit'];
        }

        $apply = min($creditAvailable, $remaining);
        if ($apply <= 0.00001) {
            return ['ok' => true, 'applied' => 0.0, 'reason' => 'nothing_to_apply'];
        }

        $stmtExisting = $conn->prepare("SELECT IFNULL(SUM(amount),0) AS s FROM financial_receipts WHERE invoice_id = ? AND type = 'out' AND description LIKE 'تسوية رصيد أول المدة%'");
        $stmtExisting->bind_param('i', $purchaseInvoiceId);
        $stmtExisting->execute();
        $existing = (float)($stmtExisting->get_result()->fetch_assoc()['s'] ?? 0);
        $stmtExisting->close();
        if ($existing > 0.00001) {
            return ['ok' => true, 'applied' => 0.0, 'reason' => 'already_applied'];
        }

        $paidOld = max(0.0, (float)$inv['paid_amount']);
        $paidNew = $paidOld + $apply;
        $remainingNew = max(0.0, $remaining - $apply);
        $statusNew = app_invoice_status_by_amounts($paidNew, $remainingNew);
        $dateValue = $invoiceDate ?: ($inv['inv_date'] ?: date('Y-m-d'));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateValue)) {
            $dateValue = date('Y-m-d');
        }

        if ($mode === 'positive') {
            $openingNew = $opening - $apply;
        } elseif ($mode === 'negative') {
            $openingNew = $opening + $apply;
        } else {
            $openingNew = ($opening >= 0) ? ($opening - $apply) : ($opening + $apply);
        }
        if (abs($openingNew) < 0.00001) {
            $openingNew = 0.0;
        }

        $safeCreatedBy = mb_substr($createdBy, 0, 100);
        if ($safeCreatedBy === '') {
            $safeCreatedBy = 'System';
        }

        try {
            $conn->begin_transaction();

            $stmtUpdInv = $conn->prepare("UPDATE purchase_invoices SET paid_amount = ?, remaining_amount = ?, status = ? WHERE id = ?");
            $stmtUpdInv->bind_param('ddsi', $paidNew, $remainingNew, $statusNew, $purchaseInvoiceId);
            $stmtUpdInv->execute();
            $stmtUpdInv->close();

            $stmtUpdSupplier = $conn->prepare("UPDATE suppliers SET opening_balance = ? WHERE id = ?");
            $stmtUpdSupplier->bind_param('di', $openingNew, $supplierId);
            $stmtUpdSupplier->execute();
            $stmtUpdSupplier->close();

            $desc = "تسوية رصيد أول المدة تلقائياً لفاتورة شراء #{$purchaseInvoiceId}";
            $type = 'out';
            $category = 'supplier';
            $stmtIns = $conn->prepare("INSERT INTO financial_receipts (type, category, amount, description, trans_date, supplier_id, invoice_id, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmtIns->bind_param('ssdssiis', $type, $category, $apply, $desc, $dateValue, $supplierId, $purchaseInvoiceId, $safeCreatedBy);
            $stmtIns->execute();
            $receiptId = (int)$stmtIns->insert_id;
            $stmtIns->close();
            if ($receiptId > 0 && function_exists('financeInsertReceiptAllocation')) {
                financeInsertReceiptAllocation($conn, $receiptId, 'supplier_opening', $supplierId, $apply, 'Automatic opening balance settlement');
                financeInsertReceiptAllocation($conn, $receiptId, 'purchase_invoice', $purchaseInvoiceId, $apply, 'Automatic opening balance settlement');
            }

            $conn->commit();
            return ['ok' => true, 'applied' => $apply, 'reason' => 'applied'];
        } catch (Throwable $e) {
            $conn->rollback();
            error_log('supplier opening balance apply failed: ' . $e->getMessage());
            return ['ok' => false, 'applied' => 0.0, 'reason' => 'exception'];
        }
    }
}

if (!function_exists('app_update_storage_dir')) {
    function app_update_storage_dir(): string
    {
        return __DIR__ . '/uploads/system_updates';
    }
}

if (!function_exists('app_update_ensure_storage_dirs')) {
    function app_update_ensure_storage_dirs(): array
    {
        $base = app_update_storage_dir();
        $paths = [
            'base' => $base,
            'packages' => $base . '/packages',
            'downloads' => $base . '/downloads',
            'backups' => $base . '/backups',
        ];
        $ok = true;
        foreach ($paths as $path) {
            if (!is_dir($path) && !@mkdir($path, 0775, true) && !is_dir($path)) {
                $ok = false;
            }
        }
        $paths['ok'] = $ok;
        return $paths;
    }
}

if (!function_exists('app_ensure_update_center_schema')) {
    function app_ensure_update_center_schema(mysqli $conn): bool
    {
        static $ok = null;
        if ($ok !== null) {
            return $ok;
        }

        try {
            $conn->query("
                CREATE TABLE IF NOT EXISTS app_update_packages (
                    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    package_name VARCHAR(190) NOT NULL DEFAULT '',
                    stored_path VARCHAR(255) NOT NULL DEFAULT '',
                    file_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
                    file_hash VARCHAR(64) NOT NULL DEFAULT '',
                    version_tag VARCHAR(80) NOT NULL DEFAULT '',
                    target_edition ENUM('any','owner','client') NOT NULL DEFAULT 'any',
                    release_notes TEXT NULL,
                    is_active TINYINT(1) NOT NULL DEFAULT 1,
                    uploaded_by VARCHAR(190) NOT NULL DEFAULT '',
                    source_mode ENUM('local_upload','remote_pull') NOT NULL DEFAULT 'local_upload',
                    remote_source_url VARCHAR(255) NOT NULL DEFAULT '',
                    applied_count INT UNSIGNED NOT NULL DEFAULT 0,
                    last_applied_at DATETIME NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX idx_update_target_active (target_edition, is_active, id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");

            if (!app_table_has_column($conn, 'app_update_packages', 'source_mode')) {
                $conn->query("ALTER TABLE app_update_packages ADD COLUMN source_mode ENUM('local_upload','remote_pull') NOT NULL DEFAULT 'local_upload'");
            }
            if (!app_table_has_column($conn, 'app_update_packages', 'remote_source_url')) {
                $conn->query("ALTER TABLE app_update_packages ADD COLUMN remote_source_url VARCHAR(255) NOT NULL DEFAULT ''");
            }

            app_initialize_system_settings($conn);
            $defaults = [
                'update_api_token' => '',
                'update_remote_url' => '',
                'update_remote_token' => '',
                'update_channel' => 'stable',
                'update_current_version' => '',
                'update_last_check_at' => '',
                'update_last_status' => '',
                'update_last_error' => '',
                'update_last_package_id' => '',
            ];
            foreach ($defaults as $key => $value) {
                app_setting_set($conn, $key, (string)$value);
            }

            $dirs = app_update_ensure_storage_dirs();
            $ok = !empty($dirs['ok']);
        } catch (Throwable $e) {
            error_log('update center schema failed: ' . $e->getMessage());
            $ok = false;
        }
        return $ok;
    }
}

if (!function_exists('app_update_api_token')) {
    function app_update_api_token(mysqli $conn): string
    {
        $token = trim((string)app_setting_get($conn, 'update_api_token', ''));
        if ($token !== '') {
            return $token;
        }
        $token = trim((string)app_env('APP_UPDATE_API_TOKEN', ''));
        if ($token !== '') {
            return $token;
        }
        return trim((string)app_env('APP_LICENSE_API_TOKEN', ''));
    }
}

if (!function_exists('app_update_sanitize_version_tag')) {
    function app_update_sanitize_version_tag(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        $value = preg_replace('/[^a-zA-Z0-9._\\- ]+/', '', $value) ?? '';
        return mb_substr(trim($value), 0, 80);
    }
}

if (!function_exists('app_update_sanitize_target_edition')) {
    function app_update_sanitize_target_edition(string $value): string
    {
        $value = strtolower(trim($value));
        return in_array($value, ['any', 'owner', 'client'], true) ? $value : 'any';
    }
}

if (!function_exists('app_update_should_skip_path')) {
    function app_update_should_skip_path(string $relativePath): bool
    {
        $rel = str_replace('\\', '/', ltrim(trim($relativePath), '/'));
        if ($rel === '') {
            return true;
        }
        if (strpos($rel, "\0") !== false) {
            return true;
        }
        $parts = explode('/', $rel);
        foreach ($parts as $part) {
            if ($part === '..') {
                return true;
            }
        }

        $skipExact = [
            '.app_env',
            '.env',
            'install.php',
            'database_schema.php',
        ];
        if (in_array($rel, $skipExact, true)) {
            return true;
        }
        if (strpos($rel, '.app_env.') === 0) {
            return true;
        }
        $skipPrefixes = [
            '.git/',
            '_release/',
            '_desktop_build/',
            'uploads/',
        ];
        foreach ($skipPrefixes as $prefix) {
            if (strpos($rel, $prefix) === 0) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('app_update_relpath_safe')) {
    function app_update_relpath_safe(string $entryName): string
    {
        $name = str_replace('\\', '/', trim($entryName));
        $name = ltrim($name, '/');
        if ($name === '' || strpos($name, "\0") !== false) {
            return '';
        }
        $segments = explode('/', $name);
        $safe = [];
        foreach ($segments as $seg) {
            if ($seg === '' || $seg === '.') {
                continue;
            }
            if ($seg === '..') {
                return '';
            }
            $safe[] = $seg;
        }
        return implode('/', $safe);
    }
}

if (!function_exists('app_update_apply_zip')) {
    function app_update_apply_zip(string $zipPath, string $targetRoot, string $backupDir = ''): array
    {
        if (!is_file($zipPath)) {
            return ['ok' => false, 'applied' => 0, 'skipped' => 0, 'errors' => ['zip_not_found']];
        }
        if (!class_exists('ZipArchive')) {
            return ['ok' => false, 'applied' => 0, 'skipped' => 0, 'errors' => ['zip_extension_missing']];
        }

        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            return ['ok' => false, 'applied' => 0, 'skipped' => 0, 'errors' => ['zip_open_failed']];
        }

        $targetRoot = rtrim($targetRoot, '/\\');
        $applied = 0;
        $skipped = 0;
        $errors = [];
        $backupRoot = '';
        if ($backupDir !== '') {
            $backupRoot = rtrim($backupDir, '/\\');
            if (!is_dir($backupRoot) && !@mkdir($backupRoot, 0775, true) && !is_dir($backupRoot)) {
                $backupRoot = '';
            }
        }

        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entry = (string)$zip->getNameIndex($i);
                $rel = app_update_relpath_safe($entry);
                if ($rel === '') {
                    $skipped++;
                    continue;
                }
                if (substr($entry, -1) === '/' || substr($rel, -1) === '/') {
                    continue;
                }
                if (app_update_should_skip_path($rel)) {
                    $skipped++;
                    continue;
                }

                $destPath = $targetRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
                $destDir = dirname($destPath);
                if (!is_dir($destDir) && !@mkdir($destDir, 0775, true) && !is_dir($destDir)) {
                    $errors[] = 'mkdir_failed:' . $rel;
                    continue;
                }

                if ($backupRoot !== '' && is_file($destPath)) {
                    $backupPath = $backupRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
                    $backupDirname = dirname($backupPath);
                    if (is_dir($backupDirname) || @mkdir($backupDirname, 0775, true) || is_dir($backupDirname)) {
                        @copy($destPath, $backupPath);
                    }
                }

                $stream = $zip->getStream($entry);
                if (!$stream) {
                    $errors[] = 'stream_failed:' . $rel;
                    continue;
                }

                $tmpPath = $destPath . '.tmp_' . bin2hex(random_bytes(4));
                $out = @fopen($tmpPath, 'wb');
                if (!$out) {
                    @fclose($stream);
                    $errors[] = 'write_failed:' . $rel;
                    continue;
                }

                $writeOk = true;
                while (!feof($stream)) {
                    $buf = fread($stream, 8192);
                    if ($buf === false) {
                        $writeOk = false;
                        break;
                    }
                    if ($buf === '') {
                        continue;
                    }
                    if (fwrite($out, $buf) === false) {
                        $writeOk = false;
                        break;
                    }
                }
                fclose($out);
                fclose($stream);

                if (!$writeOk) {
                    @unlink($tmpPath);
                    $errors[] = 'copy_failed:' . $rel;
                    continue;
                }

                if (!@rename($tmpPath, $destPath)) {
                    @unlink($tmpPath);
                    $errors[] = 'replace_failed:' . $rel;
                    continue;
                }
                @chmod($destPath, 0644);
                $applied++;
            }
        } catch (Throwable $e) {
            $errors[] = 'exception:' . $e->getMessage();
        } finally {
            $zip->close();
        }

        return [
            'ok' => empty($errors),
            'applied' => $applied,
            'skipped' => $skipped,
            'errors' => $errors,
            'backup_dir' => $backupRoot,
        ];
    }
}

if (!function_exists('app_update_store_package')) {
    function app_update_store_package(mysqli $conn, array $upload, array $meta = []): array
    {
        if (!app_ensure_update_center_schema($conn)) {
            return ['ok' => false, 'error' => 'schema_not_ready'];
        }

        $errorCode = (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($errorCode !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'error' => 'upload_error_' . $errorCode];
        }
        $tmp = (string)($upload['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            return ['ok' => false, 'error' => 'invalid_upload'];
        }

        $originalName = trim((string)($upload['name'] ?? 'update_package.zip'));
        $ext = strtolower((string)pathinfo($originalName, PATHINFO_EXTENSION));
        if ($ext !== 'zip') {
            return ['ok' => false, 'error' => 'zip_only'];
        }

        $size = (int)($upload['size'] ?? 0);
        if ($size <= 0 || $size > 900 * 1024 * 1024) {
            return ['ok' => false, 'error' => 'invalid_size'];
        }

        $dirs = app_update_ensure_storage_dirs();
        if (empty($dirs['ok'])) {
            return ['ok' => false, 'error' => 'storage_not_writable'];
        }

        $targetEdition = app_update_sanitize_target_edition((string)($meta['target_edition'] ?? 'any'));
        $versionTag = app_update_sanitize_version_tag((string)($meta['version_tag'] ?? ''));
        $notes = mb_substr(trim((string)($meta['release_notes'] ?? '')), 0, 4000);
        $uploadedBy = mb_substr(trim((string)($meta['uploaded_by'] ?? 'system')), 0, 190);
        if ($uploadedBy === '') {
            $uploadedBy = 'system';
        }
        $setActive = !empty($meta['set_active']);
        $sourceMode = (string)($meta['source_mode'] ?? 'local_upload');
        if (!in_array($sourceMode, ['local_upload', 'remote_pull'], true)) {
            $sourceMode = 'local_upload';
        }
        $remoteSourceUrl = mb_substr(trim((string)($meta['remote_source_url'] ?? '')), 0, 255);

        $safeBase = preg_replace('/[^a-zA-Z0-9_.-]+/', '_', $originalName) ?: 'update_package.zip';
        $storedFile = 'pkg_' . date('Ymd_His') . '_' . bin2hex(random_bytes(5)) . '_' . $safeBase;
        $storedPath = $dirs['packages'] . '/' . $storedFile;
        if (!@move_uploaded_file($tmp, $storedPath)) {
            return ['ok' => false, 'error' => 'move_failed'];
        }
        @chmod($storedPath, 0644);

        $hash = @hash_file('sha256', $storedPath);
        if ($hash === false) {
            @unlink($storedPath);
            return ['ok' => false, 'error' => 'hash_failed'];
        }

        if (!class_exists('ZipArchive')) {
            @unlink($storedPath);
            return ['ok' => false, 'error' => 'zip_extension_missing'];
        }
        $zip = new ZipArchive();
        if ($zip->open($storedPath) !== true) {
            @unlink($storedPath);
            return ['ok' => false, 'error' => 'zip_open_failed'];
        }
        $entries = (int)$zip->numFiles;
        $zip->close();
        if ($entries <= 0) {
            @unlink($storedPath);
            return ['ok' => false, 'error' => 'zip_empty'];
        }

        $relativePath = 'uploads/system_updates/packages/' . $storedFile;
        $isActive = $setActive ? 1 : 0;
        if ($setActive) {
            $conn->query("UPDATE app_update_packages SET is_active = 0");
        }
        $stmt = $conn->prepare("
            INSERT INTO app_update_packages
                (package_name, stored_path, file_size, file_hash, version_tag, target_edition, release_notes, is_active, uploaded_by, source_mode, remote_source_url)
            VALUES
                (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param(
            'ssissssisss',
            $originalName,
            $relativePath,
            $size,
            $hash,
            $versionTag,
            $targetEdition,
            $notes,
            $isActive,
            $uploadedBy,
            $sourceMode,
            $remoteSourceUrl
        );
        $ok = $stmt->execute();
        $insertId = (int)$stmt->insert_id;
        $stmt->close();
        if (!$ok) {
            @unlink($storedPath);
            return ['ok' => false, 'error' => 'db_insert_failed'];
        }

        return [
            'ok' => true,
            'id' => $insertId,
            'path' => $relativePath,
            'hash' => $hash,
            'size' => $size,
            'version_tag' => $versionTag,
            'target_edition' => $targetEdition,
            'is_active' => $isActive === 1,
        ];
    }
}

if (!function_exists('app_update_list_packages')) {
    function app_update_list_packages(mysqli $conn, int $limit = 80): array
    {
        if (!app_ensure_update_center_schema($conn)) {
            return [];
        }
        $limit = max(1, min(500, $limit));
        $sql = "SELECT * FROM app_update_packages ORDER BY id DESC LIMIT {$limit}";
        $res = $conn->query($sql);
        $rows = [];
        while ($res && ($row = $res->fetch_assoc())) {
            $rows[] = $row;
        }
        return $rows;
    }
}

if (!function_exists('app_update_activate_package')) {
    function app_update_activate_package(mysqli $conn, int $packageId): array
    {
        if (!app_ensure_update_center_schema($conn)) {
            return ['ok' => false, 'error' => 'schema_not_ready'];
        }
        if ($packageId <= 0) {
            return ['ok' => false, 'error' => 'invalid_package_id'];
        }
        $stmt = $conn->prepare("SELECT id FROM app_update_packages WHERE id = ? LIMIT 1");
        $stmt->bind_param('i', $packageId);
        $stmt->execute();
        $exists = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$exists) {
            return ['ok' => false, 'error' => 'package_not_found'];
        }

        $conn->begin_transaction();
        try {
            $conn->query("UPDATE app_update_packages SET is_active = 0");
            $stmt = $conn->prepare("UPDATE app_update_packages SET is_active = 1 WHERE id = ? LIMIT 1");
            $stmt->bind_param('i', $packageId);
            $stmt->execute();
            $stmt->close();
            $conn->commit();
            return ['ok' => true];
        } catch (Throwable $e) {
            $conn->rollback();
            return ['ok' => false, 'error' => 'db_update_failed'];
        }
    }
}

if (!function_exists('app_update_latest_package')) {
    function app_update_latest_package(mysqli $conn, string $edition = 'any'): ?array
    {
        if (!app_ensure_update_center_schema($conn)) {
            return null;
        }
        $edition = app_update_sanitize_target_edition($edition);
        $sql = "
            SELECT *
            FROM app_update_packages
            WHERE is_active = 1
              AND (target_edition = 'any' OR target_edition = ?)
            ORDER BY id DESC
            LIMIT 1
        ";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('s', $edition);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }
}

if (!function_exists('app_update_download_url')) {
    function app_update_download_url(int $packageId, string $token = ''): string
    {
        return rtrim(app_base_url(), '/') . '/update_api.php?action=download&id=' . max(0, $packageId);
    }
}

if (!function_exists('app_update_package_local_path')) {
    function app_update_package_local_path(array $row): string
    {
        $rel = trim((string)($row['stored_path'] ?? ''));
        if ($rel === '') {
            return '';
        }
        $rel = str_replace('\\', '/', $rel);
        if (strpos($rel, '..') !== false) {
            return '';
        }
        return __DIR__ . '/' . ltrim($rel, '/');
    }
}

if (!function_exists('app_update_apply_package')) {
    function app_update_apply_package(mysqli $conn, int $packageId, string $performedBy = ''): array
    {
        if (!app_ensure_update_center_schema($conn)) {
            return ['ok' => false, 'error' => 'schema_not_ready'];
        }
        if ($packageId <= 0) {
            return ['ok' => false, 'error' => 'invalid_package_id'];
        }

        $stmt = $conn->prepare("SELECT * FROM app_update_packages WHERE id = ? LIMIT 1");
        $stmt->bind_param('i', $packageId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) {
            return ['ok' => false, 'error' => 'package_not_found'];
        }

        $zipPath = app_update_package_local_path($row);
        if ($zipPath === '' || !is_file($zipPath)) {
            return ['ok' => false, 'error' => 'package_file_missing'];
        }

        $backupDir = app_update_storage_dir() . '/backups/apply_' . date('Ymd_His') . '_' . max(1, $packageId);
        $apply = app_update_apply_zip($zipPath, __DIR__, $backupDir);
        if (empty($apply['ok'])) {
            return ['ok' => false, 'error' => 'apply_failed', 'details' => $apply];
        }

        $stmt = $conn->prepare("
            UPDATE app_update_packages
            SET applied_count = applied_count + 1,
                last_applied_at = NOW(),
                updated_at = NOW()
            WHERE id = ?
            LIMIT 1
        ");
        $stmt->bind_param('i', $packageId);
        $stmt->execute();
        $stmt->close();

        $versionTag = trim((string)($row['version_tag'] ?? ''));
        if ($versionTag !== '') {
            app_setting_set($conn, 'update_current_version', $versionTag);
        }
        app_setting_set($conn, 'update_last_package_id', (string)$packageId);
        app_setting_set($conn, 'update_last_status', 'applied_local');
        app_setting_set($conn, 'update_last_check_at', date('Y-m-d H:i:s'));
        app_setting_set($conn, 'update_last_error', '');

        return [
            'ok' => true,
            'package_id' => $packageId,
            'version_tag' => $versionTag,
            'performed_by' => $performedBy,
            'details' => $apply,
        ];
    }
}

if (!function_exists('app_update_http_download')) {
    function app_update_http_download(string $url, string $destPath, array $headers = [], int $timeout = 35): array
    {
        $url = trim($url);
        if ($url === '') {
            return ['ok' => false, 'error' => 'empty_url'];
        }
        $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
        if (!in_array($scheme, ['http', 'https'], true) || !filter_var($url, FILTER_VALIDATE_URL)) {
            return ['ok' => false, 'error' => 'invalid_url'];
        }

        $dir = dirname($destPath);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return ['ok' => false, 'error' => 'dest_not_writable'];
        }

        if (function_exists('curl_init')) {
            $tmp = $destPath . '.part';
            $fh = @fopen($tmp, 'wb');
            if (!$fh) {
                return ['ok' => false, 'error' => 'open_temp_failed'];
            }
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_FILE => $fh,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 4,
                CURLOPT_CONNECTTIMEOUT => min(12, $timeout),
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_USERAGENT => 'ArabEagles-Updater/1.0',
            ]);
            $ok = curl_exec($ch);
            $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErr = (string)curl_error($ch);
            curl_close($ch);
            fclose($fh);

            if (!$ok || $httpCode < 200 || $httpCode >= 300) {
                @unlink($tmp);
                return ['ok' => false, 'error' => 'download_failed', 'http_code' => $httpCode, 'curl_error' => $curlErr];
            }
            if (!@rename($tmp, $destPath)) {
                @unlink($tmp);
                return ['ok' => false, 'error' => 'move_failed'];
            }
            @chmod($destPath, 0644);
            return ['ok' => true, 'path' => $destPath];
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => $timeout,
                'ignore_errors' => true,
                'header' => implode("\r\n", $headers),
            ],
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
            ],
        ]);
        $content = @file_get_contents($url, false, $context);
        if ($content === false) {
            return ['ok' => false, 'error' => 'download_failed'];
        }
        if (@file_put_contents($destPath, $content) === false) {
            return ['ok' => false, 'error' => 'write_failed'];
        }
        @chmod($destPath, 0644);
        return ['ok' => true, 'path' => $destPath];
    }
}

if (!function_exists('app_update_remote_latest')) {
    function app_update_remote_latest(string $remoteUrl, string $remoteToken, string $edition, string $channel = 'stable'): array
    {
        $remoteUrl = trim($remoteUrl);
        $remoteToken = trim($remoteToken);
        if ($remoteUrl === '') {
            return ['ok' => false, 'error' => 'remote_url_required'];
        }
        if ($remoteToken === '') {
            return ['ok' => false, 'error' => 'remote_token_required'];
        }

        $headers = ['X-Update-Token: ' . $remoteToken];
        $http = app_license_http_post_json($remoteUrl, [
            'action' => 'latest',
            'edition' => app_update_sanitize_target_edition($edition),
            'channel' => trim($channel) !== '' ? trim($channel) : 'stable',
        ], $headers, 22);

        if (empty($http['ok'])) {
            return ['ok' => false, 'error' => (string)($http['error'] ?? 'http_failed')];
        }
        $bodyRaw = (string)($http['body'] ?? '');
        $json = json_decode($bodyRaw, true);
        if (!is_array($json)) {
            return ['ok' => false, 'error' => 'invalid_json'];
        }
        if (empty($json['ok'])) {
            return ['ok' => false, 'error' => (string)($json['error'] ?? 'remote_error'), 'response' => $json];
        }
        return ['ok' => true, 'response' => $json];
    }
}

if (!function_exists('app_update_pull_remote_package')) {
    function app_update_pull_remote_package(mysqli $conn, string $remoteUrl, string $remoteToken, array $opts = []): array
    {
        if (!app_ensure_update_center_schema($conn)) {
            return ['ok' => false, 'error' => 'schema_not_ready'];
        }
        $edition = app_update_sanitize_target_edition((string)($opts['edition'] ?? app_license_edition()));
        $channel = trim((string)($opts['channel'] ?? 'stable'));
        $performedBy = mb_substr(trim((string)($opts['performed_by'] ?? 'system')), 0, 190);
        if ($performedBy === '') {
            $performedBy = 'system';
        }

        $latest = app_update_remote_latest($remoteUrl, $remoteToken, $edition, $channel);
        if (empty($latest['ok'])) {
            app_setting_set($conn, 'update_last_status', 'remote_check_failed');
            app_setting_set($conn, 'update_last_check_at', date('Y-m-d H:i:s'));
            app_setting_set($conn, 'update_last_error', (string)($latest['error'] ?? 'remote_check_failed'));
            return $latest;
        }

        $payload = (array)($latest['response'] ?? []);
        $hasUpdate = !empty($payload['has_update']);
        if (!$hasUpdate) {
            app_setting_set($conn, 'update_last_status', 'no_update');
            app_setting_set($conn, 'update_last_check_at', date('Y-m-d H:i:s'));
            app_setting_set($conn, 'update_last_error', '');
            return ['ok' => true, 'has_update' => false, 'response' => $payload];
        }

        $package = (array)($payload['package'] ?? []);
        $packageId = (int)($package['id'] ?? 0);
        $versionTag = app_update_sanitize_version_tag((string)($package['version_tag'] ?? ''));
        $sha = strtolower(trim((string)($package['sha256'] ?? '')));
        $downloadUrl = trim((string)($payload['download_url'] ?? ''));
        if ($downloadUrl === '') {
            return ['ok' => false, 'error' => 'download_url_missing', 'response' => $payload];
        }

        $currentVersion = trim((string)app_setting_get($conn, 'update_current_version', ''));
        if ($versionTag !== '' && $currentVersion !== '' && hash_equals($versionTag, $currentVersion) && empty($opts['force'])) {
            app_setting_set($conn, 'update_last_status', 'already_latest');
            app_setting_set($conn, 'update_last_check_at', date('Y-m-d H:i:s'));
            app_setting_set($conn, 'update_last_error', '');
            return ['ok' => true, 'has_update' => false, 'skipped' => true, 'reason' => 'already_latest', 'version' => $versionTag];
        }

        $dirs = app_update_ensure_storage_dirs();
        if (empty($dirs['ok'])) {
            return ['ok' => false, 'error' => 'storage_not_writable'];
        }

        $tmpName = 'remote_' . ($packageId > 0 ? $packageId : 'pkg') . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.zip';
        $tmpPath = $dirs['downloads'] . '/' . $tmpName;
        $download = app_update_http_download($downloadUrl, $tmpPath, ['X-Update-Token: ' . $remoteToken], 45);
        if (empty($download['ok'])) {
            app_setting_set($conn, 'update_last_status', 'download_failed');
            app_setting_set($conn, 'update_last_check_at', date('Y-m-d H:i:s'));
            app_setting_set($conn, 'update_last_error', (string)($download['error'] ?? 'download_failed'));
            return ['ok' => false, 'error' => (string)($download['error'] ?? 'download_failed')];
        }

        if ($sha !== '') {
            $localHash = strtolower((string)hash_file('sha256', $tmpPath));
            if (!hash_equals($sha, $localHash)) {
                @unlink($tmpPath);
                app_setting_set($conn, 'update_last_status', 'hash_mismatch');
                app_setting_set($conn, 'update_last_check_at', date('Y-m-d H:i:s'));
                app_setting_set($conn, 'update_last_error', 'hash_mismatch');
                return ['ok' => false, 'error' => 'hash_mismatch'];
            }
        }

        $fakeUpload = [
            'name' => 'remote_update_' . ($packageId > 0 ? $packageId : 'pkg') . '.zip',
            'tmp_name' => $tmpPath,
            'error' => UPLOAD_ERR_OK,
            'size' => (int)@filesize($tmpPath),
        ];

        // Move remote file into package repository without requiring HTTP upload source.
        $dirs = app_update_ensure_storage_dirs();
        $storedFile = 'pkg_' . date('Ymd_His') . '_' . bin2hex(random_bytes(5)) . '_remote.zip';
        $storedAbs = $dirs['packages'] . '/' . $storedFile;
        if (!@rename($tmpPath, $storedAbs)) {
            @copy($tmpPath, $storedAbs);
            @unlink($tmpPath);
        }
        if (!is_file($storedAbs)) {
            return ['ok' => false, 'error' => 'store_remote_failed'];
        }

        $storedRel = 'uploads/system_updates/packages/' . $storedFile;
        $size = (int)@filesize($storedAbs);
        $hash = (string)hash_file('sha256', $storedAbs);

        $stmt = $conn->prepare("
            INSERT INTO app_update_packages
                (package_name, stored_path, file_size, file_hash, version_tag, target_edition, release_notes, is_active, uploaded_by, source_mode, remote_source_url)
            VALUES
                (?, ?, ?, ?, ?, ?, ?, 0, ?, 'remote_pull', ?)
        ");
        $packageName = 'remote_update_' . ($packageId > 0 ? $packageId : 'latest') . '.zip';
        $targetEdition = app_update_sanitize_target_edition((string)($package['target_edition'] ?? $edition));
        $notes = mb_substr(trim((string)($package['release_notes'] ?? '')), 0, 4000);
        $stmt->bind_param('ssissssss', $packageName, $storedRel, $size, $hash, $versionTag, $targetEdition, $notes, $performedBy, $remoteUrl);
        $stmt->execute();
        $localPackageId = (int)$stmt->insert_id;
        $stmt->close();

        $apply = app_update_apply_package($conn, $localPackageId, $performedBy);
        if (empty($apply['ok'])) {
            app_setting_set($conn, 'update_last_status', 'apply_failed');
            app_setting_set($conn, 'update_last_check_at', date('Y-m-d H:i:s'));
            app_setting_set($conn, 'update_last_error', (string)($apply['error'] ?? 'apply_failed'));
            return ['ok' => false, 'error' => (string)($apply['error'] ?? 'apply_failed'), 'details' => $apply];
        }

        if ($versionTag !== '') {
            app_setting_set($conn, 'update_current_version', $versionTag);
        }
        app_setting_set($conn, 'update_last_package_id', (string)$localPackageId);
        app_setting_set($conn, 'update_last_status', 'applied_remote');
        app_setting_set($conn, 'update_last_check_at', date('Y-m-d H:i:s'));
        app_setting_set($conn, 'update_last_error', '');

        return [
            'ok' => true,
            'has_update' => true,
            'remote_package_id' => $packageId,
            'local_package_id' => $localPackageId,
            'version_tag' => $versionTag,
            'apply' => $apply,
        ];
    }
}

if (!function_exists('app_ensure_job_customization_schema')) {
    function app_ensure_job_customization_schema(mysqli $conn): bool
    {
        static $ok = null;
        if ($ok !== null) {
            return $ok;
        }

        try {
            $conn->query("
                CREATE TABLE IF NOT EXISTS app_operation_types (
                    type_key VARCHAR(50) PRIMARY KEY,
                    type_name VARCHAR(120) NOT NULL,
                    type_name_en VARCHAR(120) NOT NULL DEFAULT '',
                    icon_class VARCHAR(80) NOT NULL DEFAULT '',
                    default_stage_key VARCHAR(60) NOT NULL DEFAULT 'briefing',
                    sort_order INT NOT NULL DEFAULT 0,
                    is_active TINYINT(1) NOT NULL DEFAULT 1,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            $conn->query("
                CREATE TABLE IF NOT EXISTS app_operation_stages (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    type_key VARCHAR(50) NOT NULL,
                    stage_key VARCHAR(60) NOT NULL,
                    stage_name VARCHAR(120) NOT NULL,
                    stage_name_en VARCHAR(120) NOT NULL DEFAULT '',
                    stage_order INT NOT NULL DEFAULT 0,
                    default_stage_cost DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                    stage_actions_json TEXT NULL,
                    stage_required_ops_json TEXT NULL,
                    is_active TINYINT(1) NOT NULL DEFAULT 1,
                    is_terminal TINYINT(1) NOT NULL DEFAULT 0,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    UNIQUE KEY uq_type_stage (type_key, stage_key),
                    KEY idx_type_order (type_key, stage_order)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            $conn->query("
                CREATE TABLE IF NOT EXISTS app_operation_catalog (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    type_key VARCHAR(50) NOT NULL,
                    catalog_group VARCHAR(40) NOT NULL,
                    item_label VARCHAR(150) NOT NULL,
                    item_label_en VARCHAR(150) NOT NULL DEFAULT '',
                    sort_order INT NOT NULL DEFAULT 0,
                    default_unit_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                    is_active TINYINT(1) NOT NULL DEFAULT 1,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    UNIQUE KEY uq_type_group_label (type_key, catalog_group, item_label),
                    KEY idx_type_group_order (type_key, catalog_group, sort_order)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            if (!app_table_has_column($conn, 'app_operation_stages', 'default_stage_cost')) {
                $conn->query("ALTER TABLE app_operation_stages ADD COLUMN default_stage_cost DECIMAL(12,2) NOT NULL DEFAULT 0.00");
            }
            if (!app_table_has_column($conn, 'app_operation_stages', 'stage_actions_json')) {
                $conn->query("ALTER TABLE app_operation_stages ADD COLUMN stage_actions_json TEXT NULL");
            }
            if (!app_table_has_column($conn, 'app_operation_stages', 'stage_required_ops_json')) {
                $conn->query("ALTER TABLE app_operation_stages ADD COLUMN stage_required_ops_json TEXT NULL");
            }
            if (!app_table_has_column($conn, 'app_operation_catalog', 'default_unit_price')) {
                $conn->query("ALTER TABLE app_operation_catalog ADD COLUMN default_unit_price DECIMAL(12,2) NOT NULL DEFAULT 0.00");
            }
            if (!app_table_has_column($conn, 'app_operation_types', 'type_name_en')) {
                $conn->query("ALTER TABLE app_operation_types ADD COLUMN type_name_en VARCHAR(120) NOT NULL DEFAULT ''");
            }
            if (!app_table_has_column($conn, 'app_operation_stages', 'stage_name_en')) {
                $conn->query("ALTER TABLE app_operation_stages ADD COLUMN stage_name_en VARCHAR(120) NOT NULL DEFAULT ''");
            }
            if (!app_table_has_column($conn, 'app_operation_catalog', 'item_label_en')) {
                $conn->query("ALTER TABLE app_operation_catalog ADD COLUMN item_label_en VARCHAR(150) NOT NULL DEFAULT ''");
            }

            // Keep existing data usable immediately in EN until admin edits dedicated EN labels.
            $conn->query("UPDATE app_operation_types SET type_name_en = type_name WHERE TRIM(COALESCE(type_name_en, '')) = ''");
            $conn->query("UPDATE app_operation_stages SET stage_name_en = stage_name WHERE TRIM(COALESCE(stage_name_en, '')) = ''");
            $conn->query("UPDATE app_operation_catalog SET item_label_en = item_label WHERE TRIM(COALESCE(item_label_en, '')) = ''");
            $conn->query("UPDATE app_operation_stages SET stage_actions_json = '[]' WHERE stage_actions_json IS NULL OR TRIM(stage_actions_json) = ''");
            $conn->query("UPDATE app_operation_stages SET stage_required_ops_json = '[]' WHERE stage_required_ops_json IS NULL OR TRIM(stage_required_ops_json) = ''");
            $ok = true;
        } catch (Throwable $e) {
            error_log('app_ensure_job_customization_schema failed: ' . $e->getMessage());
            $ok = false;
        }

        return $ok;
    }
}

if (!function_exists('app_job_customization_defaults')) {
    function app_job_customization_defaults(): array
    {
        return [
            'types' => [
                ['key' => 'print', 'name' => 'الطباعة', 'name_en' => 'Printing', 'icon' => 'fa-print', 'default_stage' => 'briefing', 'sort' => 10],
                ['key' => 'carton', 'name' => 'الكرتون', 'name_en' => 'Carton', 'icon' => 'fa-box-open', 'default_stage' => 'briefing', 'sort' => 20],
                ['key' => 'plastic', 'name' => 'البلاستيك', 'name_en' => 'Plastic', 'icon' => 'fa-bag-shopping', 'default_stage' => 'briefing', 'sort' => 30],
                ['key' => 'social', 'name' => 'السوشيال', 'name_en' => 'Social Media', 'icon' => 'fa-hashtag', 'default_stage' => 'briefing', 'sort' => 40],
                ['key' => 'web', 'name' => 'المواقع', 'name_en' => 'Web Projects', 'icon' => 'fa-laptop-code', 'default_stage' => 'briefing', 'sort' => 50],
                ['key' => 'design_only', 'name' => 'التصميم فقط', 'name_en' => 'Design Only', 'icon' => 'fa-pen-nib', 'default_stage' => 'briefing', 'sort' => 60],
            ],
            'stages' => [
                'print' => [
                    ['key' => 'briefing', 'name' => '1. التجهيز', 'terminal' => 0],
                    ['key' => 'design', 'name' => '2. التصميم', 'terminal' => 0],
                    ['key' => 'client_rev', 'name' => '3. مراجعة العميل', 'terminal' => 0],
                    ['key' => 'pre_press', 'name' => '4. التجهيز (CTP)', 'terminal' => 0],
                    ['key' => 'materials', 'name' => '5. الخامات', 'terminal' => 0],
                    ['key' => 'printing', 'name' => '6. الطباعة', 'terminal' => 0],
                    ['key' => 'finishing', 'name' => '7. التشطيب', 'terminal' => 0],
                    ['key' => 'delivery', 'name' => '8. التسليم', 'terminal' => 0],
                    ['key' => 'accounting', 'name' => '9. الحسابات', 'terminal' => 0],
                    ['key' => 'completed', 'name' => '10. الأرشيف', 'terminal' => 1],
                ],
                'carton' => [
                    ['key' => 'briefing', 'name' => '1. التجهيز', 'terminal' => 0],
                    ['key' => 'design', 'name' => '2. التصميم', 'terminal' => 0],
                    ['key' => 'client_rev', 'name' => '3. مراجعة العميل', 'terminal' => 0],
                    ['key' => 'pre_press', 'name' => '4. التجهيز (CTP)', 'terminal' => 0],
                    ['key' => 'materials', 'name' => '5. الخامات', 'terminal' => 0],
                    ['key' => 'printing', 'name' => '6. الطباعة', 'terminal' => 0],
                    ['key' => 'die_cutting', 'name' => '7. التكسير', 'terminal' => 0],
                    ['key' => 'gluing', 'name' => '8. اللصق', 'terminal' => 0],
                    ['key' => 'delivery', 'name' => '9. التسليم', 'terminal' => 0],
                    ['key' => 'accounting', 'name' => '10. الحسابات', 'terminal' => 0],
                    ['key' => 'completed', 'name' => '11. الأرشيف', 'terminal' => 1],
                ],
                'plastic' => [
                    ['key' => 'briefing', 'name' => '1. التجهيز', 'terminal' => 0],
                    ['key' => 'design', 'name' => '2. التصميم', 'terminal' => 0],
                    ['key' => 'client_rev', 'name' => '3. مراجعة العميل', 'terminal' => 0],
                    ['key' => 'cylinders', 'name' => '4. السلندرات', 'terminal' => 0],
                    ['key' => 'extrusion', 'name' => '5. السحب', 'terminal' => 0],
                    ['key' => 'printing', 'name' => '6. الطباعة', 'terminal' => 0],
                    ['key' => 'finishing', 'name' => '7. التشطيب', 'terminal' => 0],
                    ['key' => 'delivery', 'name' => '8. التسليم', 'terminal' => 0],
                    ['key' => 'accounting', 'name' => '9. الحسابات', 'terminal' => 0],
                    ['key' => 'completed', 'name' => '10. الأرشيف', 'terminal' => 1],
                ],
                'social' => [
                    ['key' => 'briefing', 'name' => '1. التجهيز', 'terminal' => 0],
                    ['key' => 'idea_review', 'name' => '2. مراجعة الفكرة', 'terminal' => 0],
                    ['key' => 'content_writing', 'name' => '3. كتابة المحتوى', 'terminal' => 0],
                    ['key' => 'content_review', 'name' => '4. مراجعة المحتوى', 'terminal' => 0],
                    ['key' => 'designing', 'name' => '5. التصميم', 'terminal' => 0],
                    ['key' => 'design_review', 'name' => '6. مراجعة التصميم', 'terminal' => 0],
                    ['key' => 'publishing', 'name' => '7. النشر', 'terminal' => 0],
                    ['key' => 'accounting', 'name' => '8. الحسابات', 'terminal' => 0],
                    ['key' => 'completed', 'name' => '9. الأرشيف', 'terminal' => 1],
                ],
                'web' => [
                    ['key' => 'briefing', 'name' => '1. التحليل', 'terminal' => 0],
                    ['key' => 'ui_design', 'name' => '2. تصميم الواجهة', 'terminal' => 0],
                    ['key' => 'client_rev', 'name' => '3. مراجعة العميل', 'terminal' => 0],
                    ['key' => 'development', 'name' => '4. البرمجة', 'terminal' => 0],
                    ['key' => 'testing', 'name' => '5. الاختبار', 'terminal' => 0],
                    ['key' => 'launch', 'name' => '6. الإطلاق', 'terminal' => 0],
                    ['key' => 'accounting', 'name' => '7. الحسابات', 'terminal' => 0],
                    ['key' => 'completed', 'name' => '8. الأرشيف', 'terminal' => 1],
                ],
                'design_only' => [
                    ['key' => 'briefing', 'name' => '1. التجهيز', 'terminal' => 0],
                    ['key' => 'design', 'name' => '2. التصميم', 'terminal' => 0],
                    ['key' => 'client_rev', 'name' => '3. مراجعة العميل', 'terminal' => 0],
                    ['key' => 'handover', 'name' => '4. التسليم', 'terminal' => 0],
                    ['key' => 'accounting', 'name' => '5. الحسابات', 'terminal' => 0],
                    ['key' => 'completed', 'name' => '6. الأرشيف', 'terminal' => 1],
                ],
            ],
            'catalog' => [
                ['type' => 'print', 'group' => 'paper', 'items' => ['كوشيه', 'دوبلكس', 'برستول', 'كرافت', 'نيوز', 'ايفوري', 'NCR', 'FBB', 'ورق لاصق', 'ورق فويل']],
                ['type' => 'print', 'group' => 'material', 'items' => ['ورق', 'أحبار طباعة', 'زنكات', 'سلفان', 'ورنيش UV', 'غراء', 'شريط لاصق']],
                ['type' => 'print', 'group' => 'service', 'items' => ['بصمة', 'كفراج', 'تخريم', 'ترقيم', 'تدبيس', 'تجليد', 'UV موضعي', 'Hot Foil']],
                ['type' => 'carton', 'group' => 'material', 'items' => ['دوبلكس', 'كرافت', 'E-Flute', 'B-Flute', 'C-Flute', 'Micro Flute']],
                ['type' => 'carton', 'group' => 'service', 'items' => ['سلفان', 'بصمة', 'كفراج', 'تكسير', 'تخريم', 'تجميع']],
                ['type' => 'plastic', 'group' => 'material', 'items' => ['HDPE', 'LDPE', 'PP', 'BOPP', 'CPP']],
                ['type' => 'plastic', 'group' => 'feature', 'items' => ['فتحات تهوية', 'ثقوب تعليق', 'سحاب', 'قاع مدعم', 'طباعة داخلية']],
                ['type' => 'social', 'group' => 'platform', 'items' => ['Facebook', 'Instagram', 'TikTok', 'Snapchat', 'LinkedIn', 'X (Twitter)', 'YouTube', 'Google Ads']],
                ['type' => 'social', 'group' => 'content', 'items' => ['بوست ثابت', 'كاروسيل', 'ريلز', 'ستوري', 'فيديو إعلاني']],
                ['type' => 'web', 'group' => 'feature', 'items' => ['لوحة تحكم', 'متعدد اللغات', 'SEO', 'مدونة', 'نظام حجز', 'نظام دفع']],
                ['type' => 'design_only', 'group' => 'scope', 'items' => ['هوية بصرية', 'عبوات', 'مطبوعات', 'سوشيال ميديا', 'موشن']],
                ['type' => 'design_only', 'group' => 'deliverable', 'items' => ['PDF جاهز للطباعة', 'AI مفتوح', 'PSD مفتوح', 'PNG/JPG', 'Mockup']],
            ],
        ];
    }
}

if (!function_exists('app_initialize_job_customization')) {
    function app_initialize_job_customization(mysqli $conn): void
    {
        static $booted = false;
        if ($booted) {
            return;
        }
        if (!app_ensure_job_customization_schema($conn)) {
            $booted = true;
            return;
        }

        try {
            $defaults = app_job_customization_defaults();

            $stmtType = $conn->prepare("
                INSERT IGNORE INTO app_operation_types (type_key, type_name, type_name_en, icon_class, default_stage_key, sort_order, is_active)
                VALUES (?, ?, ?, ?, ?, ?, 1)
            ");
            foreach ($defaults['types'] as $typeRow) {
                $typeKey = (string)$typeRow['key'];
                $typeName = (string)$typeRow['name'];
                $typeNameEn = trim((string)($typeRow['name_en'] ?? ''));
                if ($typeNameEn === '') {
                    $typeNameEn = $typeName;
                }
                $iconClass = (string)$typeRow['icon'];
                $defaultStage = (string)$typeRow['default_stage'];
                $sortOrder = (int)$typeRow['sort'];
                $stmtType->bind_param('sssssi', $typeKey, $typeName, $typeNameEn, $iconClass, $defaultStage, $sortOrder);
                $stmtType->execute();
            }
            $stmtType->close();

            $stmtStage = $conn->prepare("
                INSERT IGNORE INTO app_operation_stages (
                    type_key,
                    stage_key,
                    stage_name,
                    stage_name_en,
                    stage_order,
                    stage_actions_json,
                    stage_required_ops_json,
                    is_active,
                    is_terminal
                )
                VALUES (?, ?, ?, ?, ?, '[]', '[]', 1, ?)
            ");
            foreach ($defaults['stages'] as $typeKey => $stages) {
                $order = 1;
                foreach ($stages as $stageRow) {
                    $stageKey = (string)$stageRow['key'];
                    $stageName = (string)$stageRow['name'];
                    $stageNameEn = trim((string)($stageRow['name_en'] ?? ''));
                    if ($stageNameEn === '') {
                        $stageNameEn = $stageName;
                    }
                    $terminal = (int)$stageRow['terminal'];
                    $stmtStage->bind_param('ssssii', $typeKey, $stageKey, $stageName, $stageNameEn, $order, $terminal);
                    $stmtStage->execute();
                    $order++;
                }
            }
            $stmtStage->close();

            $stmtCatalog = $conn->prepare("
                INSERT IGNORE INTO app_operation_catalog (type_key, catalog_group, item_label, item_label_en, sort_order, is_active)
                VALUES (?, ?, ?, ?, ?, 1)
            ");
            foreach ($defaults['catalog'] as $catalogDef) {
                $typeKey = (string)$catalogDef['type'];
                $group = (string)$catalogDef['group'];
                $items = is_array($catalogDef['items']) ? $catalogDef['items'] : [];
                $sort = 1;
                foreach ($items as $label) {
                    $itemLabel = trim((string)$label);
                    if ($itemLabel === '') {
                        continue;
                    }
                    $itemLabelEn = $itemLabel;
                    $stmtCatalog->bind_param('ssssi', $typeKey, $group, $itemLabel, $itemLabelEn, $sort);
                    $stmtCatalog->execute();
                    $sort++;
                }
            }
            $stmtCatalog->close();
        } catch (Throwable $e) {
            error_log('app_initialize_job_customization failed: ' . $e->getMessage());
        }

        $booted = true;
    }
}

if (!function_exists('app_operation_types')) {
    function app_operation_types(mysqli $conn, bool $activeOnly = true): array
    {
        app_initialize_job_customization($conn);
        $isEnglish = app_current_lang($conn) === 'en';
        $nameExpr = $isEnglish ? "COALESCE(NULLIF(type_name_en, ''), type_name)" : "type_name";
        $sql = "SELECT type_key,
                       type_name AS type_name_ar,
                       type_name_en,
                       {$nameExpr} AS type_name,
                       icon_class,
                       default_stage_key,
                       sort_order,
                       is_active
                FROM app_operation_types";
        if ($activeOnly) {
            $sql .= " WHERE is_active = 1";
        }
        $sql .= " ORDER BY sort_order ASC, {$nameExpr} ASC";
        $rows = [];
        $res = $conn->query($sql);
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $rows[] = $row;
            }
        }
        return $rows;
    }
}

if (!function_exists('app_operation_stages')) {
    function app_operation_stages(mysqli $conn, string $typeKey, bool $activeOnly = true): array
    {
        $typeKey = trim($typeKey);
        if ($typeKey === '') {
            return [];
        }
        app_initialize_job_customization($conn);
        $isEnglish = app_current_lang($conn) === 'en';
        $nameExpr = $isEnglish ? "COALESCE(NULLIF(stage_name_en, ''), stage_name)" : "stage_name";
        $sql = "SELECT id,
                       stage_key,
                       stage_name AS stage_name_ar,
                       stage_name_en,
                       {$nameExpr} AS stage_name,
                       stage_order,
                       stage_actions_json,
                       stage_required_ops_json,
                       is_terminal,
                       is_active
                FROM app_operation_stages
                WHERE type_key = ?";
        if ($activeOnly) {
            $sql .= " AND is_active = 1";
        }
        $sql .= " ORDER BY stage_order ASC, id ASC";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('s', $typeKey);
        $stmt->execute();
        $res = $stmt->get_result();
        $rows = [];
        while ($res && ($row = $res->fetch_assoc())) {
            $actions = [];
            $requiredOps = [];
            $actionsRaw = trim((string)($row['stage_actions_json'] ?? ''));
            if ($actionsRaw !== '') {
                $decoded = json_decode($actionsRaw, true);
                if (is_array($decoded)) {
                    foreach ($decoded as $item) {
                        $item = trim((string)$item);
                        if ($item !== '') {
                            $actions[] = $item;
                        }
                    }
                }
            }
            $requiredRaw = trim((string)($row['stage_required_ops_json'] ?? ''));
            if ($requiredRaw !== '') {
                $decoded = json_decode($requiredRaw, true);
                if (is_array($decoded)) {
                    foreach ($decoded as $item) {
                        $item = trim((string)$item);
                        if ($item !== '') {
                            $requiredOps[] = $item;
                        }
                    }
                }
            }
            $row['stage_actions'] = array_values(array_unique($actions));
            $row['stage_required_ops'] = array_values(array_unique($requiredOps));
            $rows[] = $row;
        }
        $stmt->close();
        return $rows;
    }
}

if (!function_exists('app_operation_stage_map')) {
    function app_operation_stage_map(mysqli $conn, string $typeKey, bool $activeOnly = true): array
    {
        $rows = app_operation_stages($conn, $typeKey, $activeOnly);
        $map = [];
        foreach ($rows as $row) {
            $map[(string)$row['stage_key']] = (string)$row['stage_name'];
        }
        return $map;
    }
}

if (!function_exists('app_operation_first_stage')) {
    function app_operation_first_stage(mysqli $conn, string $typeKey, string $fallback = 'briefing'): string
    {
        $stages = app_operation_stages($conn, $typeKey, true);
        if (!empty($stages)) {
            return (string)($stages[0]['stage_key'] ?? $fallback);
        }
        return $fallback;
    }
}

if (!function_exists('app_operation_workflow')) {
    function app_operation_workflow(mysqli $conn, string $typeKey, array $fallbackLabels = []): array
    {
        $rows = app_operation_stages($conn, $typeKey, true);
        $labels = [];
        $orderedKeys = [];

        foreach ($rows as $row) {
            $stageKey = strtolower(trim((string)($row['stage_key'] ?? '')));
            if ($stageKey === '') {
                continue;
            }
            if (!isset($labels[$stageKey])) {
                $orderedKeys[] = $stageKey;
            }
            $stageName = trim((string)($row['stage_name'] ?? ''));
            $labels[$stageKey] = $stageName !== '' ? $stageName : (string)($fallbackLabels[$stageKey] ?? $stageKey);
        }

        foreach ($fallbackLabels as $stageKey => $stageLabel) {
            $stageKey = strtolower(trim((string)$stageKey));
            if ($stageKey === '') {
                continue;
            }
            if (!isset($labels[$stageKey])) {
                $labels[$stageKey] = trim((string)$stageLabel) !== '' ? (string)$stageLabel : $stageKey;
                $orderedKeys[] = $stageKey;
            }
        }

        if (empty($orderedKeys)) {
            $orderedKeys[] = 'briefing';
            $labels['briefing'] = (string)($fallbackLabels['briefing'] ?? '1. التجهيز');
        }

        $workflow = [];
        $count = count($orderedKeys);
        for ($i = 0; $i < $count; $i++) {
            $key = $orderedKeys[$i];
            $workflow[$key] = [
                'label' => (string)($labels[$key] ?? $key),
                'prev' => $i > 0 ? $orderedKeys[$i - 1] : null,
                'next' => $i < ($count - 1) ? $orderedKeys[$i + 1] : null,
            ];
        }

        return $workflow;
    }
}

if (!function_exists('app_workflow_current_stage')) {
    function app_workflow_current_stage(array $workflow, string $currentStage, string $fallbackStage = 'briefing'): string
    {
        $currentStage = trim($currentStage);
        if ($currentStage !== '' && isset($workflow[$currentStage])) {
            return $currentStage;
        }
        if ($fallbackStage !== '' && isset($workflow[$fallbackStage])) {
            return $fallbackStage;
        }
        $first = array_key_first($workflow);
        return $first !== null ? (string)$first : $fallbackStage;
    }
}

if (!function_exists('app_job_stage_implied_status')) {
    function app_job_stage_implied_status(string $stageKey, string $fallback = 'processing'): string
    {
        $stageKey = strtolower(trim($stageKey));
        if ($stageKey === 'completed') {
            return 'completed';
        }
        if ($stageKey === 'cancelled') {
            return 'cancelled';
        }
        if ($stageKey === 'pending') {
            return 'pending';
        }
        return $fallback !== '' ? $fallback : 'processing';
    }
}

if (!function_exists('app_update_job_stage')) {
    function app_update_job_stage(mysqli $conn, int $jobId, string $stageKey, ?string $status = null): bool
    {
        $jobId = (int)$jobId;
        $stageKey = trim($stageKey);
        if ($jobId <= 0 || $stageKey === '') {
            return false;
        }
        $status = $status !== null ? trim($status) : app_job_stage_implied_status($stageKey, 'processing');
        if ($status === '') {
            $status = app_job_stage_implied_status($stageKey, 'processing');
        }
        $stmt = $conn->prepare("UPDATE job_orders SET current_stage = ?, status = ? WHERE id = ?");
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('ssi', $stageKey, $status, $jobId);
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }
}

if (!function_exists('app_update_job_stage_with_note')) {
    function app_update_job_stage_with_note(mysqli $conn, int $jobId, string $stageKey, string $note, ?string $status = null): bool
    {
        $jobId = (int)$jobId;
        $stageKey = trim($stageKey);
        if ($jobId <= 0 || $stageKey === '') {
            return false;
        }
        $status = $status !== null ? trim($status) : app_job_stage_implied_status($stageKey, 'processing');
        if ($status === '') {
            $status = app_job_stage_implied_status($stageKey, 'processing');
        }
        $stmt = $conn->prepare("UPDATE job_orders SET current_stage = ?, status = ?, notes = CONCAT(IFNULL(notes, ''), ?) WHERE id = ?");
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('sssi', $stageKey, $status, $note, $jobId);
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }
}

if (!function_exists('app_ensure_internal_chat_schema')) {
    function app_ensure_internal_chat_schema(mysqli $conn): void
    {
        $conn->query("
            CREATE TABLE IF NOT EXISTS app_internal_chat_messages (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                sender_user_id INT UNSIGNED NOT NULL,
                receiver_user_id INT UNSIGNED NOT NULL DEFAULT 0,
                message_text TEXT DEFAULT NULL,
                attachment_path VARCHAR(255) DEFAULT NULL,
                attachment_name VARCHAR(190) NOT NULL DEFAULT '',
                attachment_kind ENUM('none','image','audio','file') NOT NULL DEFAULT 'none',
                is_read TINYINT(1) NOT NULL DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_internal_chat_receiver (receiver_user_id, is_read, id),
                INDEX idx_internal_chat_pair_sender (sender_user_id, receiver_user_id, id),
                INDEX idx_internal_chat_pair_receiver (receiver_user_id, sender_user_id, id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $conn->query("
            CREATE TABLE IF NOT EXISTS app_internal_chat_group_reads (
                user_id INT UNSIGNED NOT NULL PRIMARY KEY,
                last_read_message_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_group_reads_last (last_read_message_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        if (!app_table_has_column($conn, 'app_internal_chat_messages', 'receiver_user_id')) {
            $conn->query("ALTER TABLE app_internal_chat_messages ADD COLUMN receiver_user_id INT UNSIGNED NOT NULL DEFAULT 0");
        }
        if (!app_table_has_column($conn, 'app_internal_chat_messages', 'attachment_name')) {
            $conn->query("ALTER TABLE app_internal_chat_messages ADD COLUMN attachment_name VARCHAR(190) NOT NULL DEFAULT ''");
        }
        if (!app_table_has_column($conn, 'app_internal_chat_messages', 'attachment_kind')) {
            $conn->query("ALTER TABLE app_internal_chat_messages ADD COLUMN attachment_kind ENUM('none','image','audio','file') NOT NULL DEFAULT 'none'");
        }
        if (!app_table_has_column($conn, 'app_internal_chat_messages', 'is_read')) {
            $conn->query("ALTER TABLE app_internal_chat_messages ADD COLUMN is_read TINYINT(1) NOT NULL DEFAULT 0");
        }
        if (!app_table_has_column($conn, 'app_internal_chat_group_reads', 'last_read_message_id')) {
            $conn->query("ALTER TABLE app_internal_chat_group_reads ADD COLUMN last_read_message_id BIGINT UNSIGNED NOT NULL DEFAULT 0");
        }
    }
}

if (!function_exists('app_operation_catalog_items')) {
    function app_operation_catalog_items(mysqli $conn, string $typeKey, string $group, bool $activeOnly = true): array
    {
        $typeKey = trim($typeKey);
        $group = trim($group);
        if ($typeKey === '' || $group === '') {
            return [];
        }
        app_initialize_job_customization($conn);
        $isEnglish = app_current_lang($conn) === 'en';
        $labelExpr = $isEnglish ? "COALESCE(NULLIF(item_label_en, ''), item_label)" : "item_label";
        $sql = "SELECT item_label AS item_label_ar,
                       item_label_en,
                       {$labelExpr} AS item_label
                FROM app_operation_catalog
                WHERE type_key = ? AND catalog_group = ?";
        if ($activeOnly) {
            $sql .= " AND is_active = 1";
        }
        $sql .= " ORDER BY sort_order ASC, {$labelExpr} ASC";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('ss', $typeKey, $group);
        $stmt->execute();
        $res = $stmt->get_result();
        $items = [];
        while ($res && ($row = $res->fetch_assoc())) {
            $label = trim((string)($row['item_label'] ?? ''));
            if ($label !== '') {
                $items[] = $label;
            }
        }
        $stmt->close();
        return array_values(array_unique($items));
    }
}

if (!function_exists('app_operation_catalog_entries')) {
    function app_operation_catalog_entries(mysqli $conn, string $typeKey, bool $activeOnly = true): array
    {
        $typeKey = trim($typeKey);
        if ($typeKey === '') {
            return [];
        }
        app_initialize_job_customization($conn);
        $isEnglish = app_current_lang($conn) === 'en';
        $labelExpr = $isEnglish ? "COALESCE(NULLIF(item_label_en, ''), item_label)" : "item_label";
        $sql = "SELECT id,
                       catalog_group,
                       item_label AS item_label_ar,
                       item_label_en,
                       {$labelExpr} AS item_label,
                       sort_order,
                       default_unit_price,
                       is_active
                FROM app_operation_catalog
                WHERE type_key = ?";
        if ($activeOnly) {
            $sql .= " AND is_active = 1";
        }
        $sql .= " ORDER BY catalog_group ASC, sort_order ASC, {$labelExpr} ASC";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('s', $typeKey);
        $stmt->execute();
        $res = $stmt->get_result();
        $rows = [];
        while ($res && ($row = $res->fetch_assoc())) {
            $rows[] = $row;
        }
        $stmt->close();
        return $rows;
    }
}

if (!function_exists('app_ensure_document_numbering_schema')) {
    function app_ensure_document_numbering_schema(mysqli $conn): bool
    {
        static $ok = null;
        if ($ok !== null) {
            return $ok;
        }

        try {
            $conn->query("
                CREATE TABLE IF NOT EXISTS app_document_sequences (
                    doc_type VARCHAR(40) PRIMARY KEY,
                    prefix VARCHAR(20) NOT NULL DEFAULT '',
                    padding TINYINT UNSIGNED NOT NULL DEFAULT 5,
                    next_number INT UNSIGNED NOT NULL DEFAULT 1,
                    reset_policy ENUM('none','yearly','monthly') NOT NULL DEFAULT 'none',
                    last_reset_key VARCHAR(10) NOT NULL DEFAULT '',
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            $ok = true;
        } catch (Throwable $e) {
            error_log('app_ensure_document_numbering_schema failed: ' . $e->getMessage());
            $ok = false;
        }

        return $ok;
    }
}

if (!function_exists('app_document_sequence_defaults')) {
    function app_document_sequence_defaults(): array
    {
        return [
            'job' => ['prefix' => 'JOB-', 'padding' => 5, 'next' => 1, 'reset' => 'yearly'],
            'invoice' => ['prefix' => 'INV-', 'padding' => 5, 'next' => 1, 'reset' => 'yearly'],
            'purchase' => ['prefix' => 'PINV-', 'padding' => 5, 'next' => 1, 'reset' => 'yearly'],
            'quote' => ['prefix' => 'Q-', 'padding' => 5, 'next' => 1, 'reset' => 'yearly'],
            'receipt' => ['prefix' => 'RCT-', 'padding' => 6, 'next' => 1, 'reset' => 'yearly'],
            'payment' => ['prefix' => 'PMT-', 'padding' => 6, 'next' => 1, 'reset' => 'yearly'],
            'payroll' => ['prefix' => 'PAY-', 'padding' => 5, 'next' => 1, 'reset' => 'yearly'],
        ];
    }
}

if (!function_exists('app_initialize_document_sequences')) {
    function app_initialize_document_sequences(mysqli $conn): void
    {
        static $booted = false;
        if ($booted) {
            return;
        }
        if (!app_ensure_document_numbering_schema($conn)) {
            $booted = true;
            return;
        }

        try {
            $defaults = app_document_sequence_defaults();
            $stmt = $conn->prepare("
                INSERT INTO app_document_sequences (doc_type, prefix, padding, next_number, reset_policy, last_reset_key)
                VALUES (?, ?, ?, ?, ?, '')
                ON DUPLICATE KEY UPDATE
                    prefix = prefix
            ");
            foreach ($defaults as $docType => $rule) {
                $prefix = (string)$rule['prefix'];
                $padding = (int)$rule['padding'];
                $next = (int)$rule['next'];
                $reset = (string)$rule['reset'];
                $stmt->bind_param('ssiis', $docType, $prefix, $padding, $next, $reset);
                $stmt->execute();
            }
            $stmt->close();
        } catch (Throwable $e) {
            error_log('app_initialize_document_sequences failed: ' . $e->getMessage());
        }

        $booted = true;
    }
}

if (!function_exists('app_document_sequence_rule')) {
    function app_document_sequence_rule(mysqli $conn, string $docType): array
    {
        $docType = strtolower(trim($docType));
        app_initialize_document_sequences($conn);
        if ($docType === '') {
            return ['prefix' => '', 'padding' => 5, 'next_number' => 1, 'reset_policy' => 'none', 'last_reset_key' => ''];
        }

        $stmt = $conn->prepare("SELECT prefix, padding, next_number, reset_policy, last_reset_key FROM app_document_sequences WHERE doc_type = ? LIMIT 1");
        $stmt->bind_param('s', $docType);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($row) {
            return $row;
        }

        $defaults = app_document_sequence_defaults();
        $fallback = $defaults[$docType] ?? ['prefix' => strtoupper($docType) . '-', 'padding' => 5, 'next' => 1, 'reset' => 'none'];
        return [
            'prefix' => (string)$fallback['prefix'],
            'padding' => (int)$fallback['padding'],
            'next_number' => (int)$fallback['next'],
            'reset_policy' => (string)$fallback['reset'],
            'last_reset_key' => '',
        ];
    }
}

if (!function_exists('app_document_next_number')) {
    function app_document_next_number(mysqli $conn, string $docType, ?string $dateValue = null): string
    {
        $docType = strtolower(trim($docType));
        if ($docType === '') {
            return '';
        }
        app_initialize_document_sequences($conn);

        $defaults = app_document_sequence_defaults();
        $fallback = $defaults[$docType] ?? ['prefix' => strtoupper($docType) . '-', 'padding' => 5, 'next' => 1, 'reset' => 'none'];

        $effectiveDate = $dateValue;
        if (!is_string($effectiveDate) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $effectiveDate)) {
            $effectiveDate = date('Y-m-d');
        }
        $currentYear = substr($effectiveDate, 0, 4);
        $currentMonth = substr($effectiveDate, 0, 7);

        try {
            $conn->begin_transaction();

            $stmt = $conn->prepare("
                SELECT prefix, padding, next_number, reset_policy, last_reset_key
                FROM app_document_sequences
                WHERE doc_type = ?
                LIMIT 1
                FOR UPDATE
            ");
            $stmt->bind_param('s', $docType);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$row) {
                $prefix = (string)$fallback['prefix'];
                $padding = (int)$fallback['padding'];
                $nextNumber = (int)$fallback['next'];
                $resetPolicy = (string)$fallback['reset'];
                $lastResetKey = '';

                $stmtIns = $conn->prepare("
                    INSERT INTO app_document_sequences (doc_type, prefix, padding, next_number, reset_policy, last_reset_key)
                    VALUES (?, ?, ?, ?, ?, '')
                ");
                $stmtIns->bind_param('ssiis', $docType, $prefix, $padding, $nextNumber, $resetPolicy);
                $stmtIns->execute();
                $stmtIns->close();
            } else {
                $prefix = (string)($row['prefix'] ?? $fallback['prefix']);
                $padding = max(1, (int)($row['padding'] ?? $fallback['padding']));
                $nextNumber = max(1, (int)($row['next_number'] ?? $fallback['next']));
                $resetPolicy = (string)($row['reset_policy'] ?? $fallback['reset']);
                $lastResetKey = (string)($row['last_reset_key'] ?? '');
            }

            $currentResetKey = '';
            if ($resetPolicy === 'yearly') {
                $currentResetKey = $currentYear;
            } elseif ($resetPolicy === 'monthly') {
                $currentResetKey = $currentMonth;
            }
            if ($currentResetKey !== '' && $currentResetKey !== $lastResetKey) {
                $nextNumber = 1;
            }

            $formatted = $prefix . str_pad((string)$nextNumber, $padding, '0', STR_PAD_LEFT);
            $newNext = $nextNumber + 1;

            $stmtUpd = $conn->prepare("
                UPDATE app_document_sequences
                SET next_number = ?, last_reset_key = ?
                WHERE doc_type = ?
            ");
            $stmtUpd->bind_param('iss', $newNext, $currentResetKey, $docType);
            $stmtUpd->execute();
            $stmtUpd->close();

            app_quote_history_add($conn, $quoteId, 'converted_to_invoice', 'تم تحويل العرض إلى فاتورة', ['invoice_id' => $invoiceId], 0, $createdBy);

            $conn->commit();
            return app_cloud_sync_apply_numbering_policy($conn, $formatted, $docType);
        } catch (Throwable $e) {
            $conn->rollback();
            error_log('app_document_next_number failed for ' . $docType . ': ' . $e->getMessage());
            $fallbackNumber = (string)$fallback['prefix'] . str_pad((string)rand(1, 99999), (int)$fallback['padding'], '0', STR_PAD_LEFT);
            return app_cloud_sync_apply_numbering_policy($conn, $fallbackNumber, $docType);
        }
    }
}

if (!function_exists('app_assign_document_number')) {
    function app_assign_document_number(mysqli $conn, string $table, int $recordId, string $column, string $docType, ?string $dateValue = null): string
    {
        if ($recordId <= 0) {
            return '';
        }
        if (!preg_match('/^[a-z0-9_]+$/i', $table) || !preg_match('/^[a-z0-9_]+$/i', $column)) {
            return '';
        }
        if (!app_table_has_column($conn, $table, $column)) {
            try {
                $conn->query("ALTER TABLE `$table` ADD COLUMN `$column` VARCHAR(40) DEFAULT NULL");
            } catch (Throwable $e) {
                error_log('app_assign_document_number column init failed: ' . $e->getMessage());
                return '';
            }
        }

        try {
            $stmt = $conn->prepare("SELECT `$column` AS doc_no FROM `$table` WHERE id = ? LIMIT 1");
            $stmt->bind_param('i', $recordId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $existing = trim((string)($row['doc_no'] ?? ''));
            if ($existing !== '') {
                return $existing;
            }

            $next = app_document_next_number($conn, $docType, $dateValue);
            if ($next === '') {
                return '';
            }

            $stmtUpd = $conn->prepare("UPDATE `$table` SET `$column` = ? WHERE id = ?");
            $stmtUpd->bind_param('si', $next, $recordId);
            $stmtUpd->execute();
            $stmtUpd->close();
            return $next;
        } catch (Throwable $e) {
            error_log('app_assign_document_number failed: ' . $e->getMessage());
            return '';
        }
    }
}

if (!function_exists('app_quote_convert_to_invoice')) {
    function app_quote_convert_to_invoice(mysqli $conn, int $quoteId, string $createdBy = 'System'): array
    {
        app_ensure_quotes_schema($conn);
        app_ensure_taxation_schema($conn);

        if ($quoteId <= 0) {
            return ['ok' => false, 'error' => 'invalid_quote_id'];
        }

        try {
            $stmt = $conn->prepare("SELECT * FROM quotes WHERE id = ? LIMIT 1");
            $stmt->bind_param('i', $quoteId);
            $stmt->execute();
            $quote = $stmt->get_result()->fetch_assoc() ?: [];
            $stmt->close();
            if (empty($quote)) {
                return ['ok' => false, 'error' => 'quote_not_found'];
            }

            $existingInvoiceId = (int)($quote['converted_invoice_id'] ?? 0);
            if ($existingInvoiceId > 0) {
                $check = $conn->prepare("SELECT id FROM invoices WHERE id = ? LIMIT 1");
                $check->bind_param('i', $existingInvoiceId);
                $check->execute();
                $existing = $check->get_result()->fetch_assoc() ?: [];
                $check->close();
                if (!empty($existing)) {
                    return ['ok' => true, 'error' => '', 'invoice_id' => $existingInvoiceId, 'already_converted' => true];
                }
            }

            $status = strtolower(trim((string)($quote['status'] ?? 'pending')));
            if ($status !== 'approved') {
                return ['ok' => false, 'error' => 'quote_not_approved'];
            }

            $clientId = (int)($quote['client_id'] ?? 0);
            if ($clientId <= 0) {
                return ['ok' => false, 'error' => 'client_required'];
            }

            $itemsJson = trim((string)($quote['items_json'] ?? ''));
            $items = [];
            if ($itemsJson !== '') {
                $decoded = json_decode($itemsJson, true);
                if (is_array($decoded)) {
                    $items = $decoded;
                }
            }
            if (empty($items)) {
                $itemsRes = $conn->query("SELECT item_name, description, quantity, unit, price, total FROM quote_items WHERE quote_id = " . (int)$quoteId . " ORDER BY id ASC");
                while ($itemsRes && ($item = $itemsRes->fetch_assoc())) {
                    $items[] = [
                        'desc' => (string)($item['item_name'] ?? ''),
                        'description' => (string)($item['description'] ?? ''),
                        'qty' => (float)($item['quantity'] ?? 0),
                        'unit' => (string)($item['unit'] ?? ''),
                        'price' => (float)($item['price'] ?? 0),
                        'total' => (float)($item['total'] ?? 0),
                    ];
                }
            }
            if (empty($items)) {
                return ['ok' => false, 'error' => 'quote_items_missing'];
            }

            $subTotal = 0.0;
            foreach ($items as $item) {
                $lineTotal = isset($item['total']) ? (float)$item['total'] : (((float)($item['qty'] ?? 0)) * ((float)($item['price'] ?? 0)));
                $subTotal += $lineTotal;
            }
            $taxTotal = (float)($quote['tax_total'] ?? 0);
            $grandTotal = (float)($quote['total_amount'] ?? 0);
            $currencyCode = strtoupper(trim((string)($quote['currency_code'] ?? 'EGP')));
            if ($currencyCode === '') { $currencyCode = 'EGP'; }
            $currencyLabel = (string)($quote['currency_label'] ?? $currencyCode);
            $baseCurrencyCode = strtoupper(trim((string)($quote['base_currency_code'] ?? 'EGP')));
            if ($baseCurrencyCode === '') { $baseCurrencyCode = 'EGP'; }
            $exchangeRateToBase = max(0, (float)($quote['exchange_rate_to_base'] ?? 1));
            $baseTotalAmount = (float)($quote['base_total_amount'] ?? 0);
            if ($baseTotalAmount <= 0) { $baseTotalAmount = $exchangeRateToBase > 0 ? round($grandTotal * $exchangeRateToBase, 2) : $grandTotal; }
            if ($grandTotal <= 0) {
                $grandTotal = $subTotal + $taxTotal;
            }

            $invoiceKind = trim((string)($quote['quote_kind'] ?? 'standard'));
            if ($invoiceKind === '') {
                $invoiceKind = 'standard';
            }
            $taxLawKey = trim((string)($quote['tax_law_key'] ?? ''));
            $taxesJson = trim((string)($quote['taxes_json'] ?? '[]'));
            if ($taxesJson === '') {
                $taxesJson = '[]';
            }
            $safeItemsJson = json_encode($items, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (!is_string($safeItemsJson)) {
                $safeItemsJson = '[]';
            }
            $quoteNumber = trim((string)($quote['quote_number'] ?? ''));
            if ($quoteNumber === '') {
                $quoteNumber = app_assign_document_number($conn, 'quotes', $quoteId, 'quote_number', 'quote', (string)($quote['created_at'] ?? date('Y-m-d')));
            }
            $notes = trim((string)($quote['notes'] ?? ''));
            $conversionNote = 'Converted from quote ' . ($quoteNumber !== '' ? $quoteNumber : ('#' . $quoteId));
            if ($notes !== '') {
                $notes .= "\n\n";
            }
            $notes .= '[' . $conversionNote . ']';

            $invoiceDate = (string)($quote['created_at'] ?? date('Y-m-d'));
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $invoiceDate)) {
                $invoiceDate = date('Y-m-d');
            }
            $dueDate = (string)($quote['valid_until'] ?? $invoiceDate);
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dueDate)) {
                $dueDate = $invoiceDate;
            }

            $conn->begin_transaction();

            $stmtIns = $conn->prepare("
                INSERT INTO invoices (
                    client_id, job_id, source_quote_id, inv_date, due_date, invoice_kind, tax_law_key,
                    sub_total, tax, tax_total, discount, total_amount, currency_code, currency_label, base_currency_code, exchange_rate_to_base, base_total_amount, items_json, taxes_json, notes,
                    paid_amount, remaining_amount, status
                ) VALUES (?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, 'deferred')
            ");
            $stmtIns->bind_param(
                'iissssddddsssddsssd',
                $clientId,
                $quoteId,
                $invoiceDate,
                $dueDate,
                $invoiceKind,
                $taxLawKey,
                $subTotal,
                $taxTotal,
                $taxTotal,
                $grandTotal,
                $currencyCode,
                $currencyLabel,
                $baseCurrencyCode,
                $exchangeRateToBase,
                $baseTotalAmount,
                $safeItemsJson,
                $taxesJson,
                $notes,
                $grandTotal
            );
            $stmtIns->execute();
            $invoiceId = (int)$stmtIns->insert_id;
            $stmtIns->close();

            if ($invoiceId <= 0) {
                $conn->rollback();
                return ['ok' => false, 'error' => 'invoice_insert_failed'];
            }

            app_assign_document_number($conn, 'invoices', $invoiceId, 'invoice_number', 'invoice', $invoiceDate);

            $convertedAt = date('Y-m-d H:i:s');
            $stmtUpd = $conn->prepare("UPDATE quotes SET converted_invoice_id = ?, converted_at = ? WHERE id = ?");
            $stmtUpd->bind_param('isi', $invoiceId, $convertedAt, $quoteId);
            $stmtUpd->execute();
            $stmtUpd->close();

            $conn->commit();

            $canSyncInvoiceFinance = app_table_exists($conn, 'financial_receipts');
            if ($canSyncInvoiceFinance && !function_exists('finance_sync_sales_invoice_status') && is_file(__DIR__ . '/finance_engine.php')) {
                require_once __DIR__ . '/finance_engine.php';
            }
            if ($canSyncInvoiceFinance && function_exists('finance_sync_sales_invoice_status')) {
                finance_sync_sales_invoice_status($conn, $invoiceId);
            }
            if (function_exists('app_apply_client_opening_balance_to_invoice')) {
                app_apply_client_opening_balance_to_invoice($conn, $invoiceId, $clientId, $invoiceDate, $createdBy);
            }

            return ['ok' => true, 'error' => '', 'invoice_id' => $invoiceId, 'already_converted' => false];
        } catch (Throwable $e) {
            try {
                $conn->rollback();
            } catch (Throwable $rollbackError) {
            }
            error_log('app_quote_convert_to_invoice failed: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'conversion_failed'];
        }
    }
}

if (!function_exists('app_ensure_operations_costing_schema')) {
    function app_ensure_operations_costing_schema(mysqli $conn): bool
    {
        static $ok = null;
        if ($ok !== null) {
            return $ok;
        }
        try {
            $conn->query("
                CREATE TABLE IF NOT EXISTS job_service_costs (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    job_id INT NOT NULL,
                    stage_key VARCHAR(60) NOT NULL DEFAULT '',
                    service_name VARCHAR(180) NOT NULL,
                    qty DECIMAL(12,2) NOT NULL DEFAULT 1.00,
                    unit_cost DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                    total_cost DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                    notes TEXT DEFAULT NULL,
                    created_by_user_id INT DEFAULT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    KEY idx_job_service_costs_job (job_id),
                    KEY idx_job_service_costs_stage (stage_key)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");

            if (!app_table_has_column($conn, 'inventory_items', 'avg_unit_cost')) {
                $conn->query("ALTER TABLE inventory_items ADD COLUMN avg_unit_cost DECIMAL(12,4) NOT NULL DEFAULT 0.0000");
            }
            if (!app_table_has_column($conn, 'inventory_transactions', 'unit_cost')) {
                $conn->query("ALTER TABLE inventory_transactions ADD COLUMN unit_cost DECIMAL(12,4) NOT NULL DEFAULT 0.0000");
            }
            if (!app_table_has_column($conn, 'inventory_transactions', 'total_cost')) {
                $conn->query("ALTER TABLE inventory_transactions ADD COLUMN total_cost DECIMAL(12,2) NOT NULL DEFAULT 0.00");
            }
            if (!app_table_has_column($conn, 'inventory_transactions', 'reference_type')) {
                $conn->query("ALTER TABLE inventory_transactions ADD COLUMN reference_type VARCHAR(40) DEFAULT NULL");
            }
            if (!app_table_has_column($conn, 'inventory_transactions', 'reference_id')) {
                $conn->query("ALTER TABLE inventory_transactions ADD COLUMN reference_id INT DEFAULT NULL");
            }
            if (!app_table_has_column($conn, 'inventory_transactions', 'stage_key')) {
                $conn->query("ALTER TABLE inventory_transactions ADD COLUMN stage_key VARCHAR(60) DEFAULT NULL");
            }
            if (!app_table_has_column($conn, 'purchase_invoices', 'warehouse_id')) {
                $conn->query("ALTER TABLE purchase_invoices ADD COLUMN warehouse_id INT DEFAULT NULL");
            }
            if (!app_table_has_column($conn, 'invoices', 'job_id')) {
                $conn->query("ALTER TABLE invoices ADD COLUMN job_id INT DEFAULT NULL");
            }

            $ok = true;
        } catch (Throwable $e) {
            error_log('app_ensure_operations_costing_schema failed: ' . $e->getMessage());
            $ok = false;
        }
        return $ok;
    }
}

if (!function_exists('app_ensure_purchase_returns_schema')) {
    function app_ensure_purchase_returns_schema(mysqli $conn): bool
    {
        static $ok = null;
        if ($ok !== null) {
            return $ok;
        }
        try {
            $conn->query("
                CREATE TABLE IF NOT EXISTS purchase_invoice_returns (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    return_number VARCHAR(40) DEFAULT NULL,
                    purchase_invoice_id INT NOT NULL,
                    supplier_id INT NOT NULL,
                    warehouse_id INT NOT NULL,
                    return_date DATE NOT NULL,
                    subtotal_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                    tax_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                    discount_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                    total_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                    items_json LONGTEXT DEFAULT NULL,
                    notes TEXT DEFAULT NULL,
                    created_by_user_id INT DEFAULT NULL,
                    created_by_name VARCHAR(190) NOT NULL DEFAULT '',
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    KEY idx_purchase_return_invoice (purchase_invoice_id),
                    KEY idx_purchase_return_supplier (supplier_id),
                    KEY idx_purchase_return_date (return_date)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            $ok = true;
        } catch (Throwable $e) {
            error_log('app_ensure_purchase_returns_schema failed: ' . $e->getMessage());
            $ok = false;
        }
        return $ok;
    }
}

if (!function_exists('app_ensure_sales_returns_schema')) {
    function app_ensure_sales_returns_schema(mysqli $conn): bool
    {
        static $ok = null;
        if ($ok !== null) {
            return $ok;
        }
        try {
            $conn->query("
                CREATE TABLE IF NOT EXISTS sales_invoice_returns (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    return_number VARCHAR(40) DEFAULT NULL,
                    invoice_id INT NOT NULL,
                    client_id INT NOT NULL,
                    return_date DATE NOT NULL,
                    subtotal_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                    tax_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                    discount_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                    total_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                    items_json LONGTEXT DEFAULT NULL,
                    notes TEXT DEFAULT NULL,
                    created_by_user_id INT DEFAULT NULL,
                    created_by_name VARCHAR(190) NOT NULL DEFAULT '',
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    KEY idx_sales_return_invoice (invoice_id),
                    KEY idx_sales_return_client (client_id),
                    KEY idx_sales_return_date (return_date)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            $ok = true;
        } catch (Throwable $e) {
            error_log('app_ensure_sales_returns_schema failed: ' . $e->getMessage());
            $ok = false;
        }
        return $ok;
    }
}

if (!function_exists('app_ensure_inventory_audit_schema')) {
    function app_ensure_inventory_audit_schema(mysqli $conn): bool
    {
        static $ok = null;
        if ($ok !== null) {
            return $ok;
        }

        try {
            $conn->query("
                CREATE TABLE IF NOT EXISTS inventory_audit_sessions (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    warehouse_id INT NOT NULL,
                    audit_date DATE NOT NULL,
                    title VARCHAR(190) NOT NULL DEFAULT '',
                    notes TEXT DEFAULT NULL,
                    status ENUM('draft','applied') NOT NULL DEFAULT 'draft',
                    created_by_user_id INT DEFAULT NULL,
                    applied_by_user_id INT DEFAULT NULL,
                    applied_at DATETIME DEFAULT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    KEY idx_inventory_audit_sessions_wh (warehouse_id),
                    KEY idx_inventory_audit_sessions_date (audit_date),
                    KEY idx_inventory_audit_sessions_status (status)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");

            $conn->query("
                CREATE TABLE IF NOT EXISTS inventory_audit_lines (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    session_id INT NOT NULL,
                    item_id INT NOT NULL,
                    system_qty DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                    counted_qty DECIMAL(12,2) DEFAULT NULL,
                    variance_qty DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                    notes VARCHAR(255) DEFAULT NULL,
                    counted_by_user_id INT DEFAULT NULL,
                    counted_at DATETIME DEFAULT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    UNIQUE KEY uq_inventory_audit_session_item (session_id, item_id),
                    KEY idx_inventory_audit_lines_session (session_id),
                    KEY idx_inventory_audit_lines_item (item_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");

            if (!app_table_has_column($conn, 'inventory_audit_sessions', 'applied_by_user_id')) {
                $conn->query("ALTER TABLE inventory_audit_sessions ADD COLUMN applied_by_user_id INT DEFAULT NULL AFTER created_by_user_id");
            }
            if (!app_table_has_column($conn, 'inventory_audit_sessions', 'applied_at')) {
                $conn->query("ALTER TABLE inventory_audit_sessions ADD COLUMN applied_at DATETIME DEFAULT NULL AFTER applied_by_user_id");
            }
            if (!app_table_has_column($conn, 'inventory_audit_lines', 'variance_qty')) {
                $conn->query("ALTER TABLE inventory_audit_lines ADD COLUMN variance_qty DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER counted_qty");
                $conn->query("UPDATE inventory_audit_lines SET variance_qty = IFNULL(counted_qty, system_qty) - system_qty");
            }
            if (!app_table_has_column($conn, 'inventory_audit_lines', 'counted_by_user_id')) {
                $conn->query("ALTER TABLE inventory_audit_lines ADD COLUMN counted_by_user_id INT DEFAULT NULL AFTER notes");
            }
            if (!app_table_has_column($conn, 'inventory_audit_lines', 'counted_at')) {
                $conn->query("ALTER TABLE inventory_audit_lines ADD COLUMN counted_at DATETIME DEFAULT NULL AFTER counted_by_user_id");
            }

            $ok = true;
        } catch (Throwable $e) {
            error_log('app_ensure_inventory_audit_schema failed: ' . $e->getMessage());
            $ok = false;
        }

        return $ok;
    }
}

if (!function_exists('app_inventory_item_avg_cost')) {
    function app_inventory_item_avg_cost(mysqli $conn, int $itemId): float
    {
        if ($itemId <= 0) {
            return 0.0;
        }
        try {
            $stmt = $conn->prepare("SELECT IFNULL(avg_unit_cost, 0) AS avg_cost FROM inventory_items WHERE id = ? LIMIT 1");
            $stmt->bind_param('i', $itemId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $storedAvg = (float)($row['avg_cost'] ?? 0);
            if ($storedAvg > 0.000001) {
                return $storedAvg;
            }

            if (
                !app_table_has_column($conn, 'inventory_transactions', 'unit_cost')
                || !app_table_has_column($conn, 'inventory_transactions', 'reference_type')
            ) {
                return 0.0;
            }

            $stmtFallback = $conn->prepare("
                SELECT
                    IFNULL(SUM(ABS(quantity) * unit_cost), 0) AS total_cost,
                    IFNULL(SUM(ABS(quantity)), 0) AS total_qty
                FROM inventory_transactions
                WHERE item_id = ?
                  AND quantity > 0
                  AND unit_cost > 0
                  AND reference_type IN ('purchase_invoice', 'opening_stock', 'manual_in')
            ");
            if (!$stmtFallback) {
                return 0.0;
            }
            $stmtFallback->bind_param('i', $itemId);
            $stmtFallback->execute();
            $fallbackRow = $stmtFallback->get_result()->fetch_assoc();
            $stmtFallback->close();

            $totalQty = (float)($fallbackRow['total_qty'] ?? 0);
            $totalCost = (float)($fallbackRow['total_cost'] ?? 0);
            if ($totalQty <= 0.000001 || $totalCost <= 0.000001) {
                return 0.0;
            }

            $computedAvg = $totalCost / $totalQty;
            $stmtUpd = $conn->prepare("UPDATE inventory_items SET avg_unit_cost = ? WHERE id = ? AND IFNULL(avg_unit_cost, 0) <= 0.000001");
            if ($stmtUpd) {
                $stmtUpd->bind_param('di', $computedAvg, $itemId);
                $stmtUpd->execute();
                $stmtUpd->close();
            }
            return $computedAvg;
        } catch (Throwable $e) {
            return 0.0;
        }
    }
}

if (!function_exists('app_inventory_apply_purchase_cost')) {
    function app_inventory_apply_purchase_cost(mysqli $conn, int $itemId, float $qtyIn, float $unitCost): void
    {
        if ($itemId <= 0 || $qtyIn <= 0) {
            return;
        }
        try {
            $stmtQty = $conn->prepare("SELECT IFNULL(SUM(quantity), 0) AS total_qty FROM inventory_stock WHERE item_id = ?");
            $stmtQty->bind_param('i', $itemId);
            $stmtQty->execute();
            $qtyRow = $stmtQty->get_result()->fetch_assoc();
            $stmtQty->close();

            $currentQty = max(0.0, (float)($qtyRow['total_qty'] ?? 0.0));
            $currentAvg = app_inventory_item_avg_cost($conn, $itemId);
            $denominator = $currentQty + $qtyIn;
            if ($denominator <= 0.00001) {
                return;
            }
            $newAvg = (($currentQty * $currentAvg) + ($qtyIn * $unitCost)) / $denominator;
            $stmtUpd = $conn->prepare("UPDATE inventory_items SET avg_unit_cost = ? WHERE id = ?");
            $stmtUpd->bind_param('di', $newAvg, $itemId);
            $stmtUpd->execute();
            $stmtUpd->close();
        } catch (Throwable $e) {
            error_log('app_inventory_apply_purchase_cost skipped: ' . $e->getMessage());
        }
    }
}

if (!function_exists('app_job_financial_summary')) {
    function app_job_financial_summary(mysqli $conn, int $jobId): array
    {
        if ($jobId <= 0) {
            return [
                'revenue' => 0.0,
                'paid_revenue' => 0.0,
                'remaining_revenue' => 0.0,
                'invoice_count' => 0,
                'material_cost' => 0.0,
                'service_cost' => 0.0,
                'total_cost' => 0.0,
                'profit' => 0.0,
                'status' => 'neutral',
            ];
        }

        $revenue = 0.0;
        $paidRevenue = 0.0;
        $remainingRevenue = 0.0;
        $invoiceCount = 0;
        if (app_table_has_column($conn, 'invoices', 'job_id')) {
            $stmtRev = $conn->prepare("SELECT COUNT(*) AS invoice_count, IFNULL(SUM(total_amount), 0) AS total_revenue, IFNULL(SUM(paid_amount), 0) AS paid_revenue, IFNULL(SUM(remaining_amount), 0) AS remaining_revenue FROM invoices WHERE job_id = ?");
            $stmtRev->bind_param('i', $jobId);
            $stmtRev->execute();
            $revRow = $stmtRev->get_result()->fetch_assoc();
            $stmtRev->close();
            $revenue = (float)($revRow['total_revenue'] ?? 0);
            $paidRevenue = (float)($revRow['paid_revenue'] ?? 0);
            $remainingRevenue = (float)($revRow['remaining_revenue'] ?? 0);
            $invoiceCount = (int)($revRow['invoice_count'] ?? 0);
        }

        $materialCost = 0.0;
        if (app_table_has_column($conn, 'inventory_transactions', 'reference_type') && app_table_has_column($conn, 'inventory_transactions', 'total_cost')) {
            $stmtMat = $conn->prepare("SELECT IFNULL(SUM(total_cost), 0) AS c FROM inventory_transactions WHERE reference_type = 'job_material' AND related_order_id = ?");
            $stmtMat->bind_param('i', $jobId);
            $stmtMat->execute();
            $matRow = $stmtMat->get_result()->fetch_assoc();
            $stmtMat->close();
            $materialCost = (float)($matRow['c'] ?? 0);
        }

        $serviceCost = 0.0;
        $stmtSvc = $conn->prepare("SELECT IFNULL(SUM(total_cost), 0) AS c FROM job_service_costs WHERE job_id = ?");
        $stmtSvc->bind_param('i', $jobId);
        $stmtSvc->execute();
        $svcRow = $stmtSvc->get_result()->fetch_assoc();
        $stmtSvc->close();
        $serviceCost = (float)($svcRow['c'] ?? 0);

        $totalCost = $materialCost + $serviceCost;
        $profit = $revenue - $totalCost;
        $status = 'neutral';
        if ($profit > 0.00001) {
            $status = 'profit';
        } elseif ($profit < -0.00001) {
            $status = 'loss';
        }

        return [
            'revenue' => $revenue,
            'paid_revenue' => $paidRevenue,
            'remaining_revenue' => $remainingRevenue,
            'invoice_count' => $invoiceCount,
            'material_cost' => $materialCost,
            'service_cost' => $serviceCost,
            'total_cost' => $totalCost,
            'profit' => $profit,
            'status' => $status,
        ];
    }
}

if (!function_exists('app_initialize_customization_data')) {
    function app_initialize_customization_data(mysqli $conn): void
    {
        app_initialize_job_customization($conn);
        app_initialize_document_sequences($conn);
        app_ensure_operations_costing_schema($conn);
        app_ensure_taxation_schema($conn);
        app_ensure_eta_einvoice_schema($conn);
        app_ensure_inventory_audit_schema($conn);
        app_ensure_cloud_sync_schema($conn);
        app_initialize_license_data($conn);
        app_initialize_license_management($conn);
        app_initialize_support_center($conn);
    }
}

if (!function_exists('app_quote_history_add')) {
    function app_quote_history_add(mysqli $conn, int $quoteId, string $eventType, string $eventLabel, array $details = [], int $userId = 0, string $userName = ''): void
    {
        if ($quoteId <= 0) {
            return;
        }
        app_ensure_quotes_schema($conn);
        $payload = json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($payload)) {
            $payload = '{}';
        }
        if ($userId <= 0) {
            $userId = (int)($_SESSION['user_id'] ?? 0);
        }
        if ($userName === '') {
            $userName = (string)($_SESSION['name'] ?? $_SESSION['username'] ?? 'system');
        }
        try {
            $stmt = $conn->prepare("INSERT INTO quote_history (quote_id, event_type, event_label, details_json, user_id, user_name, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())");
            if ($stmt) {
                $stmt->bind_param('isssis', $quoteId, $eventType, $eventLabel, $payload, $userId, $userName);
                $stmt->execute();
                $stmt->close();
            }
        } catch (Throwable $e) {
            error_log('quote history add failed: ' . $e->getMessage());
        }
    }
}
