<?php

if (!function_exists('app_saas_tenant_system_folder')) {
    function app_saas_tenant_system_folder(array $tenant): string
    {
        $folder = trim((string)($tenant['system_folder'] ?? ''));
        if ($folder !== '') {
            return app_saas_normalize_system_folder($folder, (string)($tenant['tenant_slug'] ?? 'tenant'));
        }
        return app_saas_normalize_system_folder((string)($tenant['tenant_slug'] ?? 'tenant'));
    }
}

if (!function_exists('app_saas_tenant_runtime_path')) {
    function app_saas_tenant_runtime_path(array $tenant): string
    {
        return rtrim(app_saas_gateway_public_root(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . app_saas_tenant_system_folder($tenant);
    }
}

if (!function_exists('app_saas_build_tenant_app_url')) {
    function app_saas_build_tenant_app_url(array $tenant): string
    {
        $baseUrl = app_saas_gateway_base_url();
        $folder = app_saas_tenant_system_folder($tenant);
        if ($baseUrl === '' || $folder === '') {
            return '';
        }
        return rtrim($baseUrl, '/') . '/' . rawurlencode($folder);
    }
}

if (!function_exists('app_saas_ensure_tenant_runtime_folder')) {
    function app_saas_ensure_tenant_runtime_folder(array $tenant): array
    {
        $folder = app_saas_tenant_system_folder($tenant);
        $tenantSlug = trim((string)($tenant['tenant_slug'] ?? ''));
        if ($folder === '' || $tenantSlug === '') {
            throw new RuntimeException('لا يمكن تجهيز مجلد تشغيل المستأجر بدون اسم نظام وSlug صالحين.');
        }

        $runtimePath = app_saas_tenant_runtime_path($tenant);
        if (!is_dir($runtimePath) && !@mkdir($runtimePath, 0755, true) && !is_dir($runtimePath)) {
            throw new RuntimeException('تعذر إنشاء مجلد تشغيل المستأجر: ' . $runtimePath);
        }

        $rewriteBase = '/' . trim($folder, '/') . '/';
        $htaccess = "RewriteEngine On\n"
            . "RewriteBase " . $rewriteBase . "\n"
            . "RewriteCond %{REQUEST_FILENAME} !-f\n"
            . "RewriteCond %{REQUEST_FILENAME} !-d\n"
            . "RewriteRule ^(.*)$ ../$1 [L,QSA]\n";
        @file_put_contents($runtimePath . DIRECTORY_SEPARATOR . '.htaccess', $htaccess);

        $indexPath = $runtimePath . DIRECTORY_SEPARATOR . 'index.php';
        if (!is_file($indexPath)) {
            $bootstrap = "<?php\n"
                . '$tenantFolder = ' . var_export($folder, true) . ";\n"
                . '$requestPath = parse_url((string)($_SERVER[\'REQUEST_URI\'] ?? \'\'), PHP_URL_PATH);' . "\n"
                . '$requestPath = is_string($requestPath) ? $requestPath : \'\';' . "\n"
                . '$segments = array_values(array_filter(explode(\'/\', $requestPath), static function ($segment): bool { return trim((string)$segment) !== \'\'; }));' . "\n"
                . 'if (!empty($segments) && trim((string)($segments[0] ?? \'\')) === $tenantFolder) {' . "\n"
                . '    array_shift($segments);' . "\n"
                . '}' . "\n"
                . '$target = dirname(__DIR__) . \'/\' . implode(\'/\', $segments);' . "\n"
                . 'if (is_dir($target)) { $target = rtrim($target, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . \'index.php\'; }' . "\n"
                . 'if ($target !== \'\' && is_file($target)) { require $target; exit; }' . "\n"
                . 'require dirname(__DIR__) . \'/index.php\';' . "\n";
            @file_put_contents($indexPath, $bootstrap);
        }

        $tenantFile = [
            'tenant_slug' => $tenantSlug,
            'tenant_name' => (string)($tenant['tenant_name'] ?? ''),
            'system_folder' => $folder,
            'app_url' => (string)($tenant['app_url'] ?? ''),
            'generated_at' => date('c'),
        ];
        @file_put_contents(
            $runtimePath . DIRECTORY_SEPARATOR . 'tenant.json',
            (string)json_encode($tenantFile, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
        );

        return [
            'folder' => $folder,
            'path' => $runtimePath,
            'app_url' => (string)($tenant['app_url'] ?? app_saas_build_tenant_app_url($tenant)),
        ];
    }
}

if (!function_exists('app_saas_find_tenant_by_slug')) {
    function app_saas_find_tenant_by_slug(mysqli $conn, string $slug): ?array
    {
        $slug = strtolower(trim($slug));
        if ($slug === '') {
            return null;
        }

        $stmt = $conn->prepare("
            SELECT t.*, d.domain AS matched_domain, d.is_primary AS matched_domain_primary
            FROM saas_tenants t
            LEFT JOIN saas_tenant_domains d ON d.tenant_id = t.id AND d.is_primary = 1
            WHERE LOWER(t.tenant_slug) = LOWER(?)
            LIMIT 1
        ");
        $stmt->bind_param('s', $slug);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return is_array($row) ? $row : null;
    }
}

if (!function_exists('app_saas_find_tenant_by_system_folder')) {
    function app_saas_find_tenant_by_system_folder(mysqli $conn, string $folder): ?array
    {
        $folder = app_saas_normalize_system_folder($folder);
        if ($folder === '') {
            return null;
        }

        $stmt = $conn->prepare("
            SELECT t.*, d.domain AS matched_domain, d.is_primary AS matched_domain_primary
            FROM saas_tenants t
            LEFT JOIN saas_tenant_domains d ON d.tenant_id = t.id AND d.is_primary = 1
            WHERE LOWER(COALESCE(t.system_folder, '')) = LOWER(?)
            LIMIT 1
        ");
        $stmt->bind_param('s', $folder);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return is_array($row) ? $row : null;
    }
}

if (!function_exists('app_saas_find_tenant_by_host')) {
    function app_saas_find_tenant_by_host(mysqli $conn, string $host): ?array
    {
        $host = app_saas_normalize_host($host);
        if ($host === '') {
            return null;
        }

        $stmt = $conn->prepare("
            SELECT t.*, d.domain AS matched_domain, d.is_primary AS matched_domain_primary
            FROM saas_tenant_domains d
            INNER JOIN saas_tenants t ON t.id = d.tenant_id
            WHERE LOWER(d.domain) = LOWER(?)
            ORDER BY d.is_primary DESC, d.id ASC
            LIMIT 1
        ");
        $stmt->bind_param('s', $host);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (is_array($row)) {
            return $row;
        }

        $parts = explode('.', $host);
        if (count($parts) >= 3) {
            $slug = (string)($parts[0] ?? '');
            return app_saas_find_tenant_by_slug($conn, $slug);
        }

        return null;
    }
}

if (!function_exists('app_saas_runtime_set_current_tenant')) {
    function app_saas_runtime_set_current_tenant(?array $tenant): void
    {
        $GLOBALS['app_current_tenant'] = $tenant;
    }
}

if (!function_exists('app_current_tenant')) {
    function app_current_tenant(): ?array
    {
        $tenant = $GLOBALS['app_current_tenant'] ?? null;
        return is_array($tenant) ? $tenant : null;
    }
}

if (!function_exists('app_current_tenant_id')) {
    function app_current_tenant_id(): int
    {
        $tenant = app_current_tenant();
        return (int)($tenant['id'] ?? 0);
    }
}

if (!function_exists('app_current_tenant_slug')) {
    function app_current_tenant_slug(): string
    {
        $tenant = app_current_tenant();
        return trim((string)($tenant['tenant_slug'] ?? ''));
    }
}

if (!function_exists('app_saas_bootstrap_runtime')) {
    function app_saas_bootstrap_runtime(array $defaultDbConfig, string $autoSystemUrl): array
    {
        $result = [
            'enabled' => false,
            'resolved' => false,
            'error' => '',
            'tenant' => null,
            'db' => $defaultDbConfig,
            'system_url' => $autoSystemUrl,
        ];

        if (!app_saas_mode_enabled()) {
            return $result;
        }

        $result['enabled'] = true;
        $controlConfig = app_saas_control_db_config($defaultDbConfig);
        $controlConn = app_saas_open_control_connection($controlConfig);
        app_saas_ensure_control_plane_schema($controlConn);

        $host = app_saas_normalize_host((string)($_SERVER['HTTP_HOST'] ?? parse_url($autoSystemUrl, PHP_URL_HOST) ?? ''));
        $forcedSlug = trim((string)app_env('APP_TENANT_SLUG', ''));
        if ($forcedSlug === '' && isset($_GET['tenant'])) {
            $forcedSlug = strtolower(trim((string)$_GET['tenant']));
        }
        $pathFolder = '';
        $gatewayHost = app_saas_gateway_host();
        if ($forcedSlug === '' && $gatewayHost !== '' && $host === $gatewayHost) {
            $requestPath = trim((string)parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH));
            $segments = array_values(array_filter(explode('/', $requestPath), static function ($segment): bool {
                return trim((string)$segment) !== '';
            }));
            if (!empty($segments)) {
                $firstSegment = trim((string)($segments[0] ?? ''));
                if ($firstSegment !== '' && strpos($firstSegment, '.') === false) {
                    $pathFolder = app_saas_normalize_system_folder($firstSegment);
                }
            }
        }
        if ($forcedSlug === '' && app_is_saas_gateway() && $gatewayHost !== '' && $host === $gatewayHost) {
            if ($pathFolder !== '') {
                $tenant = app_saas_find_tenant_by_system_folder($controlConn, $pathFolder);
                if (is_array($tenant)) {
                    $forcedSlug = trim((string)($tenant['tenant_slug'] ?? ''));
                }
            }
        }
        if ($forcedSlug === '' && app_is_saas_gateway() && $gatewayHost !== '' && $host === $gatewayHost) {
            $controlConn->close();
            $result['system_url'] = rtrim((string)app_env('SYSTEM_URL', $autoSystemUrl), '/');
            return $result;
        }
        $tenant = $forcedSlug !== ''
            ? app_saas_find_tenant_by_slug($controlConn, $forcedSlug)
            : ($pathFolder !== ''
                ? (app_saas_find_tenant_by_system_folder($controlConn, $pathFolder) ?? app_saas_find_tenant_by_host($controlConn, $host))
                : app_saas_find_tenant_by_host($controlConn, $host));

        if (!is_array($tenant)) {
            $controlConn->close();
            $result['error'] = 'tenant_not_found';
            return $result;
        }

        $tenantPassword = trim((string)($tenant['db_password_plain'] ?? ''));
        if ($tenantPassword === '' && trim((string)($tenant['db_password_enc'] ?? '')) !== '') {
            $tenantPassword = app_saas_decrypt_secret((string)$tenant['db_password_enc']);
        }

        $result['resolved'] = true;
        $result['tenant'] = $tenant;
        $result['db'] = [
            'host' => trim((string)($tenant['db_host'] ?? 'localhost')),
            'user' => trim((string)($tenant['db_user'] ?? '')),
            'pass' => $tenantPassword,
            'name' => trim((string)($tenant['db_name'] ?? '')),
            'port' => max(1, (int)($tenant['db_port'] ?? 3306)),
            'socket' => trim((string)($tenant['db_socket'] ?? '')),
        ];
        $tenantAppUrl = trim((string)($tenant['app_url'] ?? ''));
        if ($tenantAppUrl !== '') {
            $result['system_url'] = rtrim($tenantAppUrl, '/');
        } elseif ($host !== '') {
            $result['system_url'] = (app_is_https() ? 'https' : 'http') . '://' . $host;
        }

        app_saas_runtime_set_current_tenant($tenant);
        $controlConn->close();

        if (!defined('APP_TENANT_ID')) {
            define('APP_TENANT_ID', (int)($tenant['id'] ?? 0));
        }
        if (!defined('APP_TENANT_SLUG')) {
            define('APP_TENANT_SLUG', trim((string)($tenant['tenant_slug'] ?? '')));
        }
        if (!defined('APP_TENANT_NAME')) {
            define('APP_TENANT_NAME', trim((string)($tenant['tenant_name'] ?? '')));
        }
        if (!defined('APP_TENANT_STATUS')) {
            define('APP_TENANT_STATUS', trim((string)($tenant['status'] ?? '')));
        }

        return $result;
    }
}

if (!function_exists('app_saas_tenant_login_url')) {
    function app_saas_tenant_login_url(array $tenant, string $fallbackBaseUrl = ''): string
    {
        $tenantSlug = trim((string)($tenant['tenant_slug'] ?? ''));
        $tenantUrl = rtrim(trim((string)($tenant['app_url'] ?? '')), '/');
        $systemFolder = app_saas_tenant_system_folder($tenant);
        $gatewayHost = app_saas_gateway_host();
        $tenantUrlHost = app_saas_normalize_host((string)parse_url($tenantUrl, PHP_URL_HOST));
        $fallbackBaseUrl = rtrim(trim($fallbackBaseUrl), '/');
        $gatewayBaseUrl = $fallbackBaseUrl !== ''
            ? $fallbackBaseUrl
            : rtrim((string)app_env('SYSTEM_URL', ''), '/');

        if ($tenantUrl !== '' && $tenantUrlHost !== '' && $tenantUrlHost !== $gatewayHost) {
            return $tenantUrl . '/login.php';
        }

        if ($gatewayBaseUrl !== '' && $systemFolder !== '') {
            return $gatewayBaseUrl . '/' . rawurlencode($systemFolder) . '/login.php';
        }

        if ($gatewayBaseUrl === '' && $gatewayHost !== '') {
            $gatewayBaseUrl = (app_is_https() ? 'https' : 'http') . '://' . $gatewayHost;
        }
        if ($gatewayBaseUrl === '') {
            $gatewayBaseUrl = '/login.php';
        }

        $url = $gatewayBaseUrl . '/login.php';
        if ($tenantSlug !== '') {
            $url .= '?tenant=' . rawurlencode($tenantSlug);
        }
        return $url;
    }
}

if (!function_exists('app_saas_base_schema_queries')) {
    function app_saas_base_schema_queries(): array
    {
        return [
            "CREATE TABLE IF NOT EXISTS users (
                id INT AUTO_INCREMENT PRIMARY KEY,
                username VARCHAR(80) NOT NULL UNIQUE,
                password VARCHAR(255) NOT NULL,
                full_name VARCHAR(120) NOT NULL,
                role VARCHAR(40) NOT NULL DEFAULT 'employee',
                phone VARCHAR(40) DEFAULT NULL,
                email VARCHAR(120) DEFAULT NULL,
                avatar VARCHAR(255) DEFAULT NULL,
                profile_pic VARCHAR(255) DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS password_reset_tokens (
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS clients (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(255) NOT NULL,
                phone VARCHAR(50) DEFAULT NULL,
                email VARCHAR(150) DEFAULT NULL,
                password_hash VARCHAR(255) DEFAULT NULL,
                address TEXT DEFAULT NULL,
                google_map TEXT DEFAULT NULL,
                opening_balance DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                notes TEXT DEFAULT NULL,
                access_token VARCHAR(100) DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS suppliers (
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS employees (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(150) NOT NULL,
                job_title VARCHAR(120) DEFAULT NULL,
                initial_balance DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS warehouses (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(255) NOT NULL,
                location VARCHAR(255) DEFAULT NULL,
                manager_id INT DEFAULT NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS inventory_items (
                id INT AUTO_INCREMENT PRIMARY KEY,
                item_code VARCHAR(100) NOT NULL UNIQUE,
                name VARCHAR(255) NOT NULL,
                description TEXT DEFAULT NULL,
                category VARCHAR(120) DEFAULT 'Uncategorized',
                unit VARCHAR(50) NOT NULL,
                low_stock_threshold DECIMAL(12,2) NOT NULL DEFAULT 10.00,
                supplier_id INT DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS inventory_stock (
                id INT AUTO_INCREMENT PRIMARY KEY,
                item_id INT NOT NULL,
                warehouse_id INT NOT NULL,
                quantity DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_inventory_item_warehouse (item_id, warehouse_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS inventory_transactions (
                id INT AUTO_INCREMENT PRIMARY KEY,
                item_id INT NOT NULL,
                warehouse_id INT NOT NULL,
                user_id INT NOT NULL DEFAULT 0,
                transaction_type ENUM('in','out','transfer','adjustment','initial') NOT NULL,
                quantity DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                related_order_id INT DEFAULT NULL,
                notes TEXT DEFAULT NULL,
                transaction_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                KEY idx_inventory_trans_item (item_id),
                KEY idx_inventory_trans_wh (warehouse_id),
                KEY idx_inventory_trans_date (transaction_date)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS inventory_audit_sessions (
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS inventory_audit_lines (
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS products (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(255) NOT NULL,
                sku VARCHAR(120) DEFAULT NULL,
                description TEXT DEFAULT NULL,
                category VARCHAR(120) DEFAULT NULL,
                sale_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                purchase_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                low_stock_threshold INT NOT NULL DEFAULT 10,
                image_path VARCHAR(255) DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_products_sku (sku)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS product_stock (
                id INT AUTO_INCREMENT PRIMARY KEY,
                product_id INT NOT NULL,
                warehouse_id INT NOT NULL,
                quantity DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                UNIQUE KEY uq_product_warehouse (product_id, warehouse_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS stock_movements (
                id INT AUTO_INCREMENT PRIMARY KEY,
                product_id INT NOT NULL,
                warehouse_id INT NOT NULL,
                quantity_change DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                type VARCHAR(40) NOT NULL DEFAULT 'adjustment',
                notes TEXT DEFAULT NULL,
                user_id INT DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                KEY idx_stock_movements_date (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS job_orders (
                id INT AUTO_INCREMENT PRIMARY KEY,
                job_number VARCHAR(40) DEFAULT NULL,
                client_id INT DEFAULT NULL,
                job_name VARCHAR(255) NOT NULL,
                job_type VARCHAR(60) NOT NULL DEFAULT 'print',
                design_status VARCHAR(40) NOT NULL DEFAULT 'ready',
                status VARCHAR(40) NOT NULL DEFAULT 'pending',
                start_date DATETIME DEFAULT CURRENT_TIMESTAMP,
                delivery_date DATE DEFAULT NULL,
                current_stage VARCHAR(60) NOT NULL DEFAULT 'briefing',
                quantity DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                paid DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                notes LONGTEXT DEFAULT NULL,
                added_by VARCHAR(120) DEFAULT NULL,
                job_details LONGTEXT DEFAULT NULL,
                created_by_user_id INT DEFAULT NULL,
                access_token VARCHAR(80) DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_job_orders_client (client_id),
                KEY idx_job_orders_stage (current_stage),
                KEY idx_job_orders_delivery (delivery_date),
                KEY idx_job_orders_type (job_type)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS job_files (
                id INT AUTO_INCREMENT PRIMARY KEY,
                job_id INT NOT NULL,
                file_path VARCHAR(255) DEFAULT NULL,
                file_type VARCHAR(60) DEFAULT NULL,
                stage VARCHAR(80) DEFAULT NULL,
                description TEXT DEFAULT NULL,
                uploaded_by VARCHAR(120) DEFAULT NULL,
                uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                KEY idx_job_files_job (job_id),
                KEY idx_job_files_stage (stage)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS job_proofs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                job_id INT NOT NULL,
                file_path VARCHAR(255) DEFAULT NULL,
                description TEXT DEFAULT NULL,
                status VARCHAR(60) NOT NULL DEFAULT 'pending',
                item_index INT NOT NULL DEFAULT 0,
                client_comment TEXT DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                KEY idx_job_proofs_job (job_id),
                KEY idx_job_proofs_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS proof_comments (
                id INT AUTO_INCREMENT PRIMARY KEY,
                proof_id INT NOT NULL,
                job_id INT DEFAULT NULL,
                comment_text TEXT NOT NULL,
                comment_by VARCHAR(120) DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                KEY idx_proof_comments_proof (proof_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS job_assignments (
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS job_materials (
                id INT AUTO_INCREMENT PRIMARY KEY,
                job_id INT NOT NULL,
                product_id INT NOT NULL,
                warehouse_id INT NOT NULL,
                quantity_used DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                KEY idx_job_materials_job (job_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS social_posts (
                id INT AUTO_INCREMENT PRIMARY KEY,
                job_id INT NOT NULL,
                post_index INT NOT NULL,
                idea_text TEXT DEFAULT NULL,
                idea_status VARCHAR(50) DEFAULT 'pending',
                idea_feedback TEXT DEFAULT NULL,
                content_text TEXT DEFAULT NULL,
                design_path TEXT DEFAULT NULL,
                status VARCHAR(50) DEFAULT 'pending',
                client_feedback TEXT DEFAULT NULL,
                platform VARCHAR(50) DEFAULT NULL,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_social_job_post (job_id, post_index)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS quotes (
                id INT AUTO_INCREMENT PRIMARY KEY,
                quote_number VARCHAR(40) DEFAULT NULL,
                client_id INT NOT NULL,
                created_at DATE NOT NULL,
                valid_until DATE NOT NULL,
                quote_kind VARCHAR(20) NOT NULL DEFAULT 'standard',
                tax_law_key VARCHAR(60) DEFAULT NULL,
                total_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                tax_total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
                notes TEXT DEFAULT NULL,
                access_token VARCHAR(100) DEFAULT NULL,
                items_json LONGTEXT DEFAULT NULL,
                taxes_json LONGTEXT DEFAULT NULL,
                converted_invoice_id INT DEFAULT NULL,
                converted_at DATETIME DEFAULT NULL,
                KEY idx_quotes_client (client_id),
                KEY idx_quotes_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS quote_items (
                id INT AUTO_INCREMENT PRIMARY KEY,
                quote_id INT NOT NULL,
                item_name VARCHAR(255) NOT NULL,
                quantity DECIMAL(12,2) NOT NULL DEFAULT 1.00,
                price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                KEY idx_quote_items_quote (quote_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS invoices (
                id INT AUTO_INCREMENT PRIMARY KEY,
                invoice_number VARCHAR(40) DEFAULT NULL,
                client_id INT NOT NULL,
                job_id INT DEFAULT NULL,
                source_quote_id INT DEFAULT NULL,
                inv_date DATE NOT NULL,
                due_date DATE DEFAULT NULL,
                invoice_kind VARCHAR(20) NOT NULL DEFAULT 'standard',
                tax_law_key VARCHAR(60) DEFAULT NULL,
                sub_total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                tax DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                tax_total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                discount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                total_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                paid_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                remaining_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                status VARCHAR(40) NOT NULL DEFAULT 'unpaid',
                items_json LONGTEXT DEFAULT NULL,
                taxes_json LONGTEXT DEFAULT NULL,
                notes TEXT DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                KEY idx_invoices_client (client_id),
                KEY idx_invoices_job (job_id),
                KEY idx_invoices_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS purchase_invoices (
                id INT AUTO_INCREMENT PRIMARY KEY,
                purchase_number VARCHAR(40) DEFAULT NULL,
                supplier_id INT NOT NULL,
                inv_date DATE NOT NULL,
                due_date DATE DEFAULT NULL,
                sub_total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                tax DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                discount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                total_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                paid_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                remaining_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                status VARCHAR(40) NOT NULL DEFAULT 'unpaid',
                items_json LONGTEXT DEFAULT NULL,
                notes TEXT DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                KEY idx_purchase_invoices_supplier (supplier_id),
                KEY idx_purchase_invoices_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS financial_receipts (
                id INT AUTO_INCREMENT PRIMARY KEY,
                receipt_number VARCHAR(40) DEFAULT NULL,
                type ENUM('in','out') NOT NULL,
                category VARCHAR(50) NOT NULL DEFAULT 'general',
                amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                description TEXT DEFAULT NULL,
                trans_date DATE NOT NULL,
                client_id INT DEFAULT NULL,
                invoice_id INT DEFAULT NULL,
                supplier_id INT DEFAULT NULL,
                employee_id INT DEFAULT NULL,
                payroll_id INT DEFAULT NULL,
                user_id INT DEFAULT NULL,
                created_by VARCHAR(100) DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                KEY idx_receipts_date (trans_date),
                KEY idx_receipts_type (type),
                KEY idx_receipts_invoice (invoice_id),
                KEY idx_receipts_payroll (payroll_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS financial_receipt_allocations (
                id INT AUTO_INCREMENT PRIMARY KEY,
                receipt_id INT NOT NULL,
                allocation_type VARCHAR(40) NOT NULL,
                target_id INT DEFAULT NULL,
                amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                notes VARCHAR(255) DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                KEY idx_receipt_alloc_receipt (receipt_id),
                KEY idx_receipt_alloc_target (allocation_type, target_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS payroll_sheets (
                id INT AUTO_INCREMENT PRIMARY KEY,
                payroll_number VARCHAR(40) DEFAULT NULL,
                employee_id INT NOT NULL,
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS system_settings (
                setting_key VARCHAR(80) PRIMARY KEY,
                setting_value TEXT NOT NULL,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        ];
    }
}

if (!function_exists('app_saas_create_initial_admin')) {
    function app_saas_create_initial_admin(mysqli $conn, string $username, string $password, string $fullName, string $email = ''): void
    {
        $username = strtolower(trim($username));
        $fullName = trim($fullName) !== '' ? trim($fullName) : 'System Admin';
        $email = trim($email);
        if ($username === '' || $password === '') {
            throw new RuntimeException('بيانات المدير الأول غير مكتملة.');
        }

        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $conn->prepare("INSERT INTO users (username, password, full_name, role, email) VALUES (?, ?, ?, 'admin', ?) ON DUPLICATE KEY UPDATE password = VALUES(password), full_name = VALUES(full_name), role = 'admin', email = VALUES(email)");
        $stmt->bind_param('ssss', $username, $passwordHash, $fullName, $email);
        $stmt->execute();
        $stmt->close();
    }
}

if (!function_exists('app_saas_random_password')) {
    function app_saas_random_password(int $length = 18): string
    {
        $length = max(12, min(40, $length));
        return substr(bin2hex(random_bytes((int)ceil($length / 2))), 0, $length);
    }
}

if (!function_exists('app_saas_reset_tenant_admin_access')) {
    function app_saas_reset_tenant_admin_access(array $tenant, string $username = 'admin', string $password = '', string $fullName = '', string $email = ''): array
    {
        $tenantId = (int)($tenant['id'] ?? 0);
        if ($tenantId <= 0) {
            throw new RuntimeException('المستأجر غير محدد لاسترجاع الدخول.');
        }

        $username = strtolower(trim($username));
        if ($username === '') {
            $username = 'admin';
        }
        if ($password === '') {
            $password = app_saas_random_password(16);
        }
        $fullName = trim($fullName) !== '' ? trim($fullName) : trim((string)($tenant['tenant_name'] ?? 'System Admin'));
        $email = trim($email) !== '' ? trim($email) : trim((string)($tenant['billing_email'] ?? ''));

        $tenantConn = app_saas_open_tenant_connection($tenant);
        try {
            app_saas_prepare_tenant_database($tenantConn);
            app_saas_create_initial_admin($tenantConn, $username, $password, $fullName, $email);
        } finally {
            $tenantConn->close();
        }

        return [
            'ok' => true,
            'username' => $username,
            'password' => $password,
            'login_url' => app_saas_tenant_login_url($tenant),
        ];
    }
}

if (!function_exists('app_saas_prepare_tenant_database')) {
    function app_saas_prepare_tenant_database(mysqli $conn): void
    {
        foreach (app_saas_base_schema_queries() as $query) {
            $conn->query($query);
        }

        app_initialize_system_settings($conn);
        app_ensure_pricing_records_schema($conn);
        app_ensure_quotes_schema($conn);
        app_ensure_suppliers_schema($conn);
        app_ensure_payroll_schema($conn);
        app_ensure_financial_review_schema($conn);
        app_ensure_job_workflow_schema($conn);
        app_ensure_internal_chat_schema($conn);
        app_ensure_job_assets_schema($conn);
        app_ensure_social_schema($conn);
        app_ensure_purchase_returns_schema($conn);
        app_initialize_access_control($conn);
        app_initialize_customization_data($conn);
    }
}

if (!function_exists('app_saas_open_tenant_connection')) {
    function app_saas_open_tenant_connection(array $tenant): mysqli
    {
        $dbName = trim((string)($tenant['db_name'] ?? ''));
        $dbUser = trim((string)($tenant['db_user'] ?? ''));
        $dbHost = trim((string)($tenant['db_host'] ?? 'localhost'));
        $dbPort = max(1, (int)($tenant['db_port'] ?? 3306));
        $dbSocket = trim((string)($tenant['db_socket'] ?? ''));
        $dbPassword = trim((string)($tenant['db_password_plain'] ?? ''));
        if ($dbPassword === '' && trim((string)($tenant['db_password_enc'] ?? '')) !== '') {
            $dbPassword = app_saas_decrypt_secret((string)$tenant['db_password_enc']);
        }
        if ($dbName === '' || $dbUser === '') {
            throw new RuntimeException('بيانات قاعدة بيانات المستأجر غير مكتملة.');
        }

        $conn = ($dbSocket !== '')
            ? new mysqli($dbHost, $dbUser, $dbPassword, $dbName, $dbPort, $dbSocket)
            : new mysqli($dbHost, $dbUser, $dbPassword, $dbName, $dbPort);
        $conn->set_charset('utf8mb4');
        return $conn;
    }
}

if (!function_exists('app_saas_tenant_health')) {
    function app_saas_tenant_health(array $tenant): array
    {
        $issues = [];
        $severity = 'ok';

        $runtimePath = app_saas_tenant_runtime_path($tenant);
        $runtimeOk = is_dir($runtimePath);
        if (!$runtimeOk) {
            $issues[] = 'مجلد التشغيل غير موجود';
            $severity = 'critical';
        }

        $dbOk = false;
        $dbError = '';
        try {
            $conn = app_saas_open_tenant_connection($tenant);
            $conn->query('SELECT 1');
            $conn->close();
            $dbOk = true;
        } catch (Throwable $e) {
            $dbError = trim((string)$e->getMessage());
            $issues[] = 'تعذر الاتصال بقاعدة البيانات';
            $severity = 'critical';
        }

        $tenantStatus = strtolower(trim((string)($tenant['status'] ?? '')));
        $subscriptionStatus = strtolower(trim((string)($tenant['subscription_status'] ?? '')));
        $subscribedUntil = trim((string)($tenant['subscribed_until'] ?? ''));
        if ($tenantStatus === 'active' && $subscriptionStatus === '') {
            $issues[] = 'مستأجر نشط بدون اشتراك حالي';
            if ($severity !== 'critical') {
                $severity = 'warning';
            }
        }
        if (in_array($subscriptionStatus, ['past_due', 'suspended'], true)) {
            $issues[] = $subscriptionStatus === 'past_due' ? 'الاشتراك متأخر' : 'الاشتراك موقوف';
            if ($severity !== 'critical') {
                $severity = 'warning';
            }
        }
        if ($subscribedUntil !== '' && strtotime($subscribedUntil) !== false && strtotime($subscribedUntil) < time()) {
            $issues[] = 'تاريخ الاشتراك الحالي منتهٍ';
            if ($severity !== 'critical') {
                $severity = 'warning';
            }
        }

        return [
            'severity' => $severity,
            'db_ok' => $dbOk,
            'db_error' => $dbError,
            'runtime_ok' => $runtimeOk,
            'runtime_path' => $runtimePath,
            'issues' => $issues,
        ];
    }
}

if (!function_exists('app_saas_backup_storage_dir')) {
    function app_saas_backup_storage_dir(): string
    {
        $base = rtrim(__DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..', DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'tenant_backups';
        if (!is_dir($base)) {
            @mkdir($base, 0755, true);
        }
        return $base;
    }
}

if (!function_exists('app_saas_export_storage_dir')) {
    function app_saas_export_storage_dir(): string
    {
        $base = rtrim(__DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..', DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'tenant_exports';
        if (!is_dir($base)) {
            @mkdir($base, 0755, true);
        }
        return $base;
    }
}

if (!function_exists('app_saas_sql_literal')) {
    function app_saas_sql_literal(mysqli $conn, $value): string
    {
        if ($value === null) {
            return 'NULL';
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_int($value) || is_float($value)) {
            return (string)$value;
        }
        return "'" . $conn->real_escape_string((string)$value) . "'";
    }
}

if (!function_exists('app_saas_export_tenant_sql')) {
    function app_saas_export_tenant_sql(mysqli $tenantConn): string
    {
        $sql = "-- Arab Eagles SaaS tenant backup\n";
        $sql .= "-- Generated at: " . date('c') . "\n\n";

        $tables = [];
        $tablesRes = $tenantConn->query('SHOW TABLES');
        while ($row = $tablesRes->fetch_row()) {
            $tables[] = (string)($row[0] ?? '');
        }

        foreach ($tables as $table) {
            if ($table === '') {
                continue;
            }
            $tableEsc = '`' . str_replace('`', '``', $table) . '`';
            $createRes = $tenantConn->query("SHOW CREATE TABLE {$tableEsc}");
            $createRow = $createRes ? $createRes->fetch_assoc() : null;
            $createSql = (string)($createRow['Create Table'] ?? '');
            if ($createSql !== '') {
                $sql .= "DROP TABLE IF EXISTS {$tableEsc};\n";
                $sql .= $createSql . ";\n\n";
            }

            $rowsRes = $tenantConn->query("SELECT * FROM {$tableEsc}");
            if (!$rowsRes) {
                continue;
            }
            while ($row = $rowsRes->fetch_assoc()) {
                $columns = [];
                $values = [];
                foreach ($row as $column => $value) {
                    $columns[] = '`' . str_replace('`', '``', (string)$column) . '`';
                    $values[] = app_saas_sql_literal($tenantConn, $value);
                }
                $sql .= "INSERT INTO {$tableEsc} (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $values) . ");\n";
            }
            $sql .= "\n";
        }

        return $sql;
    }
}

if (!function_exists('app_saas_backup_tenant')) {
    function app_saas_backup_tenant(array $tenant): array
    {
        $tenantId = (int)($tenant['id'] ?? 0);
        if ($tenantId <= 0) {
            throw new RuntimeException('المستأجر غير محدد للنسخ الاحتياطي.');
        }

        $tenantConn = app_saas_open_tenant_connection($tenant);
        $sqlDump = app_saas_export_tenant_sql($tenantConn);
        $tenantConn->close();

        $slug = trim((string)($tenant['tenant_slug'] ?? 'tenant'));
        $timestamp = date('Ymd_His');
        $baseName = 'tenant_' . $slug . '_' . $timestamp;
        $storageDir = app_saas_backup_storage_dir();
        $runtimePath = app_saas_tenant_runtime_path($tenant);
        $manifest = [
            'tenant_id' => $tenantId,
            'tenant_slug' => $slug,
            'tenant_name' => (string)($tenant['tenant_name'] ?? ''),
            'system_name' => (string)($tenant['system_name'] ?? ''),
            'system_folder' => (string)($tenant['system_folder'] ?? ''),
            'app_url' => (string)($tenant['app_url'] ?? ''),
            'db_name' => (string)($tenant['db_name'] ?? ''),
            'db_host' => (string)($tenant['db_host'] ?? ''),
            'runtime_path' => $runtimePath,
            'generated_at' => date('c'),
        ];
        $manifestJson = json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

        $finalPath = $storageDir . DIRECTORY_SEPARATOR . $baseName . '.zip';
        $publicUrl = 'uploads/tenant_backups/' . $baseName . '.zip';

        if (class_exists('ZipArchive')) {
            $zip = new ZipArchive();
            if ($zip->open($finalPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('تعذر إنشاء ملف النسخة الاحتياطية.');
            }
            $zip->addFromString('manifest.json', (string)$manifestJson);
            $zip->addFromString('database.sql', $sqlDump);
            if (is_dir($runtimePath)) {
                $runtimeMarker = $runtimePath . DIRECTORY_SEPARATOR . 'tenant.json';
                if (is_file($runtimeMarker)) {
                    $zip->addFile($runtimeMarker, 'runtime/tenant.json');
                }
                $runtimeHtaccess = $runtimePath . DIRECTORY_SEPARATOR . '.htaccess';
                if (is_file($runtimeHtaccess)) {
                    $zip->addFile($runtimeHtaccess, 'runtime/.htaccess');
                }
                $runtimeIndex = $runtimePath . DIRECTORY_SEPARATOR . 'index.php';
                if (is_file($runtimeIndex)) {
                    $zip->addFile($runtimeIndex, 'runtime/index.php');
                }
            }
            $zip->close();
        } else {
            $fallbackDir = $storageDir . DIRECTORY_SEPARATOR . $baseName;
            if (!is_dir($fallbackDir)) {
                @mkdir($fallbackDir, 0755, true);
            }
            file_put_contents($fallbackDir . DIRECTORY_SEPARATOR . 'manifest.json', (string)$manifestJson);
            file_put_contents($fallbackDir . DIRECTORY_SEPARATOR . 'database.sql', $sqlDump);
            $finalPath = $fallbackDir;
            $publicUrl = 'uploads/tenant_backups/' . $baseName . '/';
        }

        return [
            'ok' => true,
            'path' => $finalPath,
            'url' => $publicUrl,
            'filename' => basename($finalPath),
        ];
    }
}

if (!function_exists('app_saas_zip_add_path')) {
    function app_saas_zip_add_path(ZipArchive $zip, string $sourcePath, string $archiveBase): void
    {
        if (is_file($sourcePath)) {
            $zip->addFile($sourcePath, trim($archiveBase, '/'));
            return;
        }
        if (!is_dir($sourcePath)) {
            return;
        }
        $items = @scandir($sourcePath);
        if (!is_array($items)) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $fullPath = $sourcePath . DIRECTORY_SEPARATOR . $item;
            $archivePath = trim($archiveBase, '/') . '/' . $item;
            if (is_dir($fullPath)) {
                $zip->addEmptyDir($archivePath);
                app_saas_zip_add_path($zip, $fullPath, $archivePath);
            } elseif (is_file($fullPath)) {
                $zip->addFile($fullPath, $archivePath);
            }
        }
    }
}

if (!function_exists('app_saas_export_tenant_package')) {
    function app_saas_export_tenant_package(array $tenant): array
    {
        $tenantId = (int)($tenant['id'] ?? 0);
        if ($tenantId <= 0) {
            throw new RuntimeException('المستأجر غير محدد للتصدير.');
        }

        $backup = app_saas_backup_tenant($tenant);
        $slug = trim((string)($tenant['tenant_slug'] ?? 'tenant'));
        $timestamp = date('Ymd_His');
        $baseName = 'tenant_export_' . $slug . '_' . $timestamp;
        $storageDir = app_saas_export_storage_dir();
        $finalPath = $storageDir . DIRECTORY_SEPARATOR . $baseName . '.zip';
        $publicUrl = 'uploads/tenant_exports/' . $baseName . '.zip';

        $manifest = [
            'tenant_id' => $tenantId,
            'tenant_slug' => $slug,
            'tenant_name' => (string)($tenant['tenant_name'] ?? ''),
            'system_name' => (string)($tenant['system_name'] ?? ''),
            'system_folder' => (string)($tenant['system_folder'] ?? ''),
            'app_url' => (string)($tenant['app_url'] ?? ''),
            'db_name' => (string)($tenant['db_name'] ?? ''),
            'db_host' => (string)($tenant['db_host'] ?? ''),
            'exported_at' => date('c'),
            'backup_filename' => (string)($backup['filename'] ?? ''),
            'backup_url' => (string)($backup['url'] ?? ''),
        ];
        $manifestJson = json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

        if (class_exists('ZipArchive')) {
            $zip = new ZipArchive();
            if ($zip->open($finalPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('تعذر إنشاء حزمة التصدير.');
            }
            $zip->addFromString('tenant_export_manifest.json', (string)$manifestJson);
            $backupPath = (string)($backup['path'] ?? '');
            if ($backupPath !== '') {
                app_saas_zip_add_path($zip, $backupPath, 'payload');
            }
            $zip->close();
        } else {
            $fallbackDir = $storageDir . DIRECTORY_SEPARATOR . $baseName;
            if (!is_dir($fallbackDir)) {
                @mkdir($fallbackDir, 0755, true);
            }
            file_put_contents($fallbackDir . DIRECTORY_SEPARATOR . 'tenant_export_manifest.json', (string)$manifestJson);
            $backupPath = (string)($backup['path'] ?? '');
            if ($backupPath !== '') {
                if (is_file($backupPath)) {
                    @copy($backupPath, $fallbackDir . DIRECTORY_SEPARATOR . basename($backupPath));
                } elseif (is_dir($backupPath)) {
                    @mkdir($fallbackDir . DIRECTORY_SEPARATOR . 'payload', 0755, true);
                    foreach ((array)scandir($backupPath) as $item) {
                        if ($item === '.' || $item === '..') {
                            continue;
                        }
                        $source = $backupPath . DIRECTORY_SEPARATOR . $item;
                        $target = $fallbackDir . DIRECTORY_SEPARATOR . 'payload' . DIRECTORY_SEPARATOR . $item;
                        if (is_file($source)) {
                            @copy($source, $target);
                        }
                    }
                }
            }
            $finalPath = $fallbackDir;
            $publicUrl = 'uploads/tenant_exports/' . $baseName . '/';
        }

        return [
            'ok' => true,
            'path' => $finalPath,
            'url' => $publicUrl,
            'filename' => basename($finalPath),
            'backup_filename' => (string)($backup['filename'] ?? ''),
        ];
    }
}

if (!function_exists('app_saas_clone_tenant_blueprint')) {
    function app_saas_clone_tenant_blueprint(mysqli $controlConn, array $sourceTenant, array $overrides = []): array
    {
        $sourceTenantId = (int)($sourceTenant['id'] ?? 0);
        if ($sourceTenantId <= 0) {
            throw new RuntimeException('المستأجر المصدر غير صالح للاستنساخ.');
        }

        $slug = trim((string)($overrides['tenant_slug'] ?? ''));
        $tenantName = trim((string)($overrides['tenant_name'] ?? ''));
        $systemName = trim((string)($overrides['system_name'] ?? ''));
        $systemFolder = app_saas_normalize_system_folder((string)($overrides['system_folder'] ?? $systemName), $slug !== '' ? $slug : 'tenant');
        $legalName = trim((string)($overrides['legal_name'] ?? (string)($sourceTenant['legal_name'] ?? '')));
        $planCode = trim((string)($overrides['plan_code'] ?? (string)($sourceTenant['plan_code'] ?? 'basic')));
        $provisionProfile = trim((string)($overrides['provision_profile'] ?? (string)($sourceTenant['provision_profile'] ?? 'standard')));
        $policyPack = trim((string)($overrides['policy_pack'] ?? (string)($sourceTenant['policy_pack'] ?? 'standard')));
        $billingEmail = trim((string)($overrides['billing_email'] ?? (string)($sourceTenant['billing_email'] ?? '')));
        $appUrl = rtrim(trim((string)($overrides['app_url'] ?? '')), '/');
        $dbHost = trim((string)($overrides['db_host'] ?? (string)($sourceTenant['db_host'] ?? 'localhost')));
        $dbPort = max(1, (int)($overrides['db_port'] ?? (int)($sourceTenant['db_port'] ?? 3306)));
        $dbName = trim((string)($overrides['db_name'] ?? ''));
        $dbUser = trim((string)($overrides['db_user'] ?? ''));
        $dbPass = (string)($overrides['db_password'] ?? '');
        $dbSocket = trim((string)($overrides['db_socket'] ?? (string)($sourceTenant['db_socket'] ?? '')));
        $timezone = trim((string)($overrides['timezone'] ?? (string)($sourceTenant['timezone'] ?? 'Africa/Cairo')));
        $locale = trim((string)($overrides['locale'] ?? (string)($sourceTenant['locale'] ?? 'ar')));
        $usersLimit = max(0, (int)($overrides['users_limit'] ?? (int)($sourceTenant['users_limit'] ?? 0)));
        $storageLimit = max(0, (int)($overrides['storage_limit_mb'] ?? (int)($sourceTenant['storage_limit_mb'] ?? 0)));
        $opsKeepLatest = max(1, (int)($overrides['ops_keep_latest'] ?? (int)($sourceTenant['ops_keep_latest'] ?? 500)));
        $opsKeepDays = max(1, (int)($overrides['ops_keep_days'] ?? (int)($sourceTenant['ops_keep_days'] ?? 30)));
        $copyPolicyOverrides = !empty($overrides['copy_policy_overrides']);
        $policyOverridesJson = $copyPolicyOverrides ? trim((string)($sourceTenant['policy_overrides_json'] ?? '')) : '';
        $policyExceptionPreset = $copyPolicyOverrides ? trim((string)($sourceTenant['policy_exception_preset'] ?? '')) : '';
        $notes = trim((string)($overrides['notes'] ?? (string)($sourceTenant['notes'] ?? '')));

        if ($slug === '' || $tenantName === '' || $systemName === '' || $dbName === '' || $dbUser === '') {
            throw new RuntimeException('بيانات الاستنساخ الأساسية غير مكتملة.');
        }

        $stmtSlug = $controlConn->prepare("SELECT id FROM saas_tenants WHERE LOWER(tenant_slug) = LOWER(?) LIMIT 1");
        $stmtSlug->bind_param('s', $slug);
        $stmtSlug->execute();
        $slugTaken = $stmtSlug->get_result()->fetch_assoc();
        $stmtSlug->close();
        if ($slugTaken) {
            throw new RuntimeException('Slug المستأجر الجديد مستخدم بالفعل.');
        }

        $stmtFolder = $controlConn->prepare("SELECT id FROM saas_tenants WHERE LOWER(COALESCE(system_folder, '')) = LOWER(?) LIMIT 1");
        $stmtFolder->bind_param('s', $systemFolder);
        $stmtFolder->execute();
        $folderTaken = $stmtFolder->get_result()->fetch_assoc();
        $stmtFolder->close();
        if ($folderTaken) {
            throw new RuntimeException('مجلد النظام الجديد مستخدم بالفعل.');
        }

        if ($appUrl === '') {
            $appUrl = rtrim(app_saas_gateway_base_url(), '/') . '/' . rawurlencode($systemFolder);
        }

        $dbPasswordEnc = app_saas_encrypt_secret($dbPass);
        $dbPasswordPlain = $dbPasswordEnc === '' ? $dbPass : '';
        $cloneNotes = trim($notes . "\n\nCloned from tenant: " . (string)($sourceTenant['tenant_slug'] ?? 'unknown') . ' at ' . date('c'));

        $stmt = $controlConn->prepare("
            INSERT INTO saas_tenants
            (
                tenant_slug, tenant_name, system_name, system_folder, legal_name, status, plan_code, provision_profile, policy_pack, billing_email, app_url,
                db_host, db_port, db_name, db_user, db_password_plain, db_password_enc, db_socket,
                timezone, locale, trial_ends_at, subscribed_until, users_limit, storage_limit_mb, ops_keep_latest, ops_keep_days, current_subscription_id,
                policy_exception_preset, policy_overrides_json, notes, activated_at, archived_at
            )
            VALUES (?, ?, ?, ?, ?, 'provisioning', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, NULL, ?, ?, ?, ?, NULL, ?, ?, ?, NULL, NULL)
        ");
        $stmt->bind_param(
            'sssssssssssisssssssiiiisss',
            $slug,
            $tenantName,
            $systemName,
            $systemFolder,
            $legalName,
            $planCode,
            $provisionProfile,
            $policyPack,
            $billingEmail,
            $appUrl,
            $dbHost,
            $dbPort,
            $dbName,
            $dbUser,
            $dbPasswordPlain,
            $dbPasswordEnc,
            $dbSocket,
            $timezone,
            $locale,
            $usersLimit,
            $storageLimit,
            $opsKeepLatest,
            $opsKeepDays,
            $policyExceptionPreset,
            $policyOverridesJson,
            $cloneNotes
        );
        $stmt->execute();
        $tenantId = (int)$stmt->insert_id;
        $stmt->close();

        $runtime = app_saas_ensure_tenant_runtime_folder([
            'id' => $tenantId,
            'tenant_slug' => $slug,
            'tenant_name' => $tenantName,
            'system_folder' => $systemFolder,
            'app_url' => $appUrl,
        ]);

        return [
            'tenant_id' => $tenantId,
            'tenant_slug' => $slug,
            'tenant_name' => $tenantName,
            'system_folder' => $systemFolder,
            'app_url' => $appUrl,
            'runtime_folder' => (string)($runtime['folder'] ?? $systemFolder),
            'copied_policy_overrides' => $copyPolicyOverrides ? app_saas_tenant_policy_overrides($sourceTenant) : [],
            'policy_exception_preset' => $policyExceptionPreset,
        ];
    }
}

if (!function_exists('app_saas_seedable_clone_presets')) {
    function app_saas_seedable_clone_presets(): array
    {
        return [
            'clients' => ['label' => 'العملاء', 'tables' => ['clients']],
            'suppliers' => ['label' => 'الموردين', 'tables' => ['suppliers']],
            'warehouses_inventory' => ['label' => 'المخازن والأصناف والأرصدة', 'tables' => ['warehouses', 'inventory_items', 'inventory_stock']],
            'products' => ['label' => 'المنتجات', 'tables' => ['products']],
            'employees' => ['label' => 'الموظفين', 'tables' => ['employees']],
        ];
    }
}

if (!function_exists('app_saas_table_exists')) {
    function app_saas_table_exists(mysqli $conn, string $table): bool
    {
        $table = trim($table);
        if ($table === '') {
            return false;
        }
        $stmt = $conn->prepare('SHOW TABLES LIKE ?');
        $stmt->bind_param('s', $table);
        $stmt->execute();
        $result = $stmt->get_result();
        $exists = $result instanceof mysqli_result && $result->num_rows > 0;
        if ($result instanceof mysqli_result) {
            $result->close();
        }
        $stmt->close();
        return $exists;
    }
}

if (!function_exists('app_saas_copy_table_rows')) {
    function app_saas_copy_table_rows(mysqli $sourceConn, mysqli $targetConn, string $table): int
    {
        if (!app_saas_table_exists($sourceConn, $table) || !app_saas_table_exists($targetConn, $table)) {
            return 0;
        }

        $safeTable = '`' . str_replace('`', '``', $table) . '`';
        $targetConn->query("DELETE FROM {$safeTable}");

        $result = $sourceConn->query("SELECT * FROM {$safeTable}");
        if (!$result instanceof mysqli_result) {
            throw new RuntimeException('تعذر قراءة جدول ' . $table . ' من المستأجر المصدر.');
        }

        $fields = $result->fetch_fields();
        $columns = [];
        foreach ($fields as $field) {
            $columns[] = (string)$field->name;
        }

        if (empty($columns)) {
            $result->close();
            return 0;
        }

        $columnSql = implode(', ', array_map(static function (string $column): string {
            return '`' . str_replace('`', '``', $column) . '`';
        }, $columns));
        $placeholderSql = implode(', ', array_fill(0, count($columns), '?'));
        $stmtInsert = $targetConn->prepare("INSERT INTO {$safeTable} ({$columnSql}) VALUES ({$placeholderSql})");

        $copied = 0;
        while ($row = $result->fetch_assoc()) {
            $types = '';
            $bindValues = [];
            foreach ($columns as $column) {
                $value = $row[$column] ?? null;
                if (is_int($value)) {
                    $types .= 'i';
                } elseif (is_float($value)) {
                    $types .= 'd';
                } else {
                    $types .= 's';
                }
                $bindValues[] = $value;
            }
            $refs = [];
            foreach ($bindValues as $index => $value) {
                $refs[$index] = &$bindValues[$index];
            }
            array_unshift($refs, $types);
            call_user_func_array([$stmtInsert, 'bind_param'], $refs);
            $stmtInsert->execute();
            $copied++;
        }

        $stmtInsert->close();
        $result->close();
        return $copied;
    }
}

if (!function_exists('app_saas_clone_tenant_seed_data')) {
    function app_saas_clone_tenant_seed_data(array $sourceTenant, array $targetTenant, array $selectedPresets = []): array
    {
        $presets = app_saas_seedable_clone_presets();
        $selected = [];
        foreach ($selectedPresets as $presetKey) {
            $presetKey = trim((string)$presetKey);
            if ($presetKey !== '' && isset($presets[$presetKey])) {
                $selected[$presetKey] = $presets[$presetKey];
            }
        }
        if (empty($selected)) {
            return ['ok' => true, 'presets' => [], 'tables' => [], 'rows_copied' => 0];
        }

        $tableOrder = ['users'];
        foreach ($selected as $preset) {
            foreach ((array)($preset['tables'] ?? []) as $table) {
                if (!in_array($table, $tableOrder, true)) {
                    $tableOrder[] = $table;
                }
            }
        }
        if (empty($tableOrder)) {
            throw new RuntimeException('لا توجد جداول صالحة للنسخ.');
        }

        $sourceConn = app_saas_open_tenant_connection($sourceTenant);
        $targetConn = app_saas_open_tenant_connection($targetTenant);

        try {
            $targetConn->begin_transaction();
            $targetConn->query('SET FOREIGN_KEY_CHECKS=0');

            $copiedTables = [];
            foreach ($tableOrder as $table) {
                $copiedTables[$table] = app_saas_copy_table_rows($sourceConn, $targetConn, $table);
            }

            $targetConn->query('SET FOREIGN_KEY_CHECKS=1');
            $targetConn->commit();

            return [
                'ok' => true,
                'presets' => array_keys($selected),
                'tables' => $copiedTables,
                'rows_copied' => (int)array_sum($copiedTables),
            ];
        } catch (Throwable $e) {
            try {
                $targetConn->query('SET FOREIGN_KEY_CHECKS=1');
                $targetConn->rollback();
            } catch (Throwable $rollbackError) {
            }
            throw new RuntimeException('تعذر نسخ البيانات التأسيسية: ' . $e->getMessage(), 0, $e);
        } finally {
            $sourceConn->close();
            $targetConn->close();
        }
    }
}

if (!function_exists('app_saas_clone_post_review')) {
    function app_saas_clone_post_review(array $tenant): array
    {
        $review = [
            'ok' => true,
            'health' => ['severity' => 'ok', 'runtime_ok' => false, 'db_ok' => false, 'issues' => []],
            'counts' => [],
            'summary' => '',
        ];

        $health = app_saas_tenant_health($tenant);
        $review['health'] = $health;
        $review['ok'] = (($health['severity'] ?? 'critical') !== 'critical');

        $countTables = ['users', 'clients', 'suppliers', 'warehouses', 'inventory_items', 'inventory_stock', 'products', 'employees'];
        try {
            $tenantConn = app_saas_open_tenant_connection($tenant);
            foreach ($countTables as $table) {
                if (!app_saas_table_exists($tenantConn, $table)) {
                    $review['counts'][$table] = null;
                    continue;
                }
                $safeTable = '`' . str_replace('`', '``', $table) . '`';
                $result = $tenantConn->query("SELECT COUNT(*) AS total FROM {$safeTable}");
                $row = $result instanceof mysqli_result ? $result->fetch_assoc() : null;
                if ($result instanceof mysqli_result) {
                    $result->close();
                }
                $review['counts'][$table] = (int)($row['total'] ?? 0);
            }
            $tenantConn->close();
        } catch (Throwable $e) {
            $review['ok'] = false;
            $review['health']['severity'] = 'critical';
            $review['health']['issues'][] = 'تعذر مراجعة قاعدة النسخة بعد الاستنساخ';
            $review['summary'] = 'DB review failed';
            return $review;
        }

        $summaryParts = [];
        $summaryParts[] = !empty($health['db_ok']) ? 'DB OK' : 'DB FAIL';
        $summaryParts[] = !empty($health['runtime_ok']) ? 'Runtime OK' : 'Runtime FAIL';
        $summaryParts[] = 'users:' . (int)($review['counts']['users'] ?? 0);
        $summaryParts[] = 'clients:' . (int)($review['counts']['clients'] ?? 0);
        $summaryParts[] = 'suppliers:' . (int)($review['counts']['suppliers'] ?? 0);
        $summaryParts[] = 'items:' . (int)($review['counts']['inventory_items'] ?? 0);
        $summaryParts[] = 'products:' . (int)($review['counts']['products'] ?? 0);
        $review['summary'] = implode(' | ', $summaryParts);

        return $review;
    }
}

if (!function_exists('app_saas_tenant_table_counts_snapshot')) {
    function app_saas_tenant_table_counts_snapshot(array $tenant, array $tables = []): array
    {
        $tables = !empty($tables) ? array_values(array_unique(array_map('strval', $tables))) : ['users', 'clients', 'suppliers', 'warehouses', 'inventory_items', 'inventory_stock', 'products', 'employees'];
        $snapshot = [];
        $tenantConn = app_saas_open_tenant_connection($tenant);
        try {
            foreach ($tables as $table) {
                if (!app_saas_table_exists($tenantConn, $table)) {
                    $snapshot[$table] = null;
                    continue;
                }
                $safeTable = '`' . str_replace('`', '``', $table) . '`';
                $result = $tenantConn->query("SELECT COUNT(*) AS total FROM {$safeTable}");
                $row = $result instanceof mysqli_result ? $result->fetch_assoc() : null;
                if ($result instanceof mysqli_result) {
                    $result->close();
                }
                $snapshot[$table] = (int)($row['total'] ?? 0);
            }
        } finally {
            $tenantConn->close();
        }

        return $snapshot;
    }
}

if (!function_exists('app_saas_clone_comparison_snapshot')) {
    function app_saas_clone_comparison_snapshot(array $sourceTenant, array $targetTenant, array $selectedPresets = []): array
    {
        $presets = app_saas_seedable_clone_presets();
        $selected = [];
        foreach ($selectedPresets as $presetKey) {
            $presetKey = trim((string)$presetKey);
            if ($presetKey !== '' && isset($presets[$presetKey])) {
                $selected[$presetKey] = $presetKey;
            }
        }

        $tables = ['users'];
        foreach (array_keys($selected) as $presetKey) {
            foreach ((array)($presets[$presetKey]['tables'] ?? []) as $table) {
                if (!in_array($table, $tables, true)) {
                    $tables[] = $table;
                }
            }
        }
        if (empty($tables)) {
            $tables = ['users'];
        }

        $sourceCounts = app_saas_tenant_table_counts_snapshot($sourceTenant, $tables);
        $targetCounts = app_saas_tenant_table_counts_snapshot($targetTenant, $tables);
        $deltaCounts = [];
        foreach ($tables as $table) {
            $sourceValue = $sourceCounts[$table] ?? null;
            $targetValue = $targetCounts[$table] ?? null;
            if ($sourceValue === null || $targetValue === null) {
                $deltaCounts[$table] = null;
                continue;
            }
            $deltaCounts[$table] = (int)$targetValue - (int)$sourceValue;
        }

        $summaryParts = [];
        foreach (['users', 'clients', 'suppliers', 'inventory_items', 'products'] as $table) {
            if (!array_key_exists($table, $targetCounts)) {
                continue;
            }
            $summaryParts[] = $table . ':' . (int)($sourceCounts[$table] ?? 0) . '->' . (int)($targetCounts[$table] ?? 0);
        }

        return [
            'tables' => $tables,
            'source_counts' => $sourceCounts,
            'target_counts' => $targetCounts,
            'delta_counts' => $deltaCounts,
            'summary' => implode(' | ', $summaryParts),
        ];
    }
}

if (!function_exists('app_saas_list_tenant_backups')) {
    function app_saas_list_tenant_backups(array $tenant): array
    {
        $storageDir = app_saas_backup_storage_dir();
        if (!is_dir($storageDir)) {
            return [];
        }

        $slug = trim((string)($tenant['tenant_slug'] ?? ''));
        if ($slug === '') {
            return [];
        }

        $prefix = 'tenant_' . $slug . '_';
        $entries = @scandir($storageDir);
        if (!is_array($entries)) {
            return [];
        }

        $backups = [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            if (strpos($entry, $prefix) !== 0) {
                continue;
            }
            $fullPath = $storageDir . DIRECTORY_SEPARATOR . $entry;
            if (!is_file($fullPath) && !is_dir($fullPath)) {
                continue;
            }
            $backups[] = [
                'filename' => $entry,
                'path' => $fullPath,
                'url' => 'uploads/tenant_backups/' . $entry,
                'modified_at' => @date('Y-m-d H:i:s', (int)@filemtime($fullPath)),
                'size' => is_file($fullPath) ? (int)@filesize($fullPath) : 0,
                'is_dir' => is_dir($fullPath),
            ];
        }

        usort($backups, static function (array $a, array $b): int {
            return strcmp((string)($b['modified_at'] ?? ''), (string)($a['modified_at'] ?? ''));
        });

        return $backups;
    }
}

if (!function_exists('app_saas_find_tenant_backup')) {
    function app_saas_find_tenant_backup(array $tenant, string $filename): ?array
    {
        $filename = basename(trim($filename));
        if ($filename === '') {
            return null;
        }
        foreach (app_saas_list_tenant_backups($tenant) as $backup) {
            if ((string)($backup['filename'] ?? '') === $filename) {
                return $backup;
            }
        }
        return null;
    }
}

if (!function_exists('app_saas_list_tenant_exports')) {
    function app_saas_list_tenant_exports(array $tenant): array
    {
        $storageDir = app_saas_export_storage_dir();
        if (!is_dir($storageDir)) {
            return [];
        }

        $slug = trim((string)($tenant['tenant_slug'] ?? ''));
        if ($slug === '') {
            return [];
        }

        $prefix = 'tenant_export_' . $slug . '_';
        $entries = @scandir($storageDir);
        if (!is_array($entries)) {
            return [];
        }

        $exports = [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            if (strpos($entry, $prefix) !== 0) {
                continue;
            }
            $fullPath = $storageDir . DIRECTORY_SEPARATOR . $entry;
            if (!is_file($fullPath) && !is_dir($fullPath)) {
                continue;
            }
            $exports[] = [
                'filename' => $entry,
                'path' => $fullPath,
                'url' => 'uploads/tenant_exports/' . $entry,
                'modified_at' => @date('Y-m-d H:i:s', (int)@filemtime($fullPath)),
                'size' => is_file($fullPath) ? (int)@filesize($fullPath) : 0,
                'is_dir' => is_dir($fullPath),
            ];
        }

        usort($exports, static function (array $a, array $b): int {
            return strcmp((string)($b['modified_at'] ?? ''), (string)($a['modified_at'] ?? ''));
        });

        return $exports;
    }
}

if (!function_exists('app_saas_read_tenant_backup')) {
    function app_saas_read_tenant_backup(array $backup): array
    {
        $path = trim((string)($backup['path'] ?? ''));
        if ($path === '') {
            throw new RuntimeException('ملف النسخة الاحتياطية غير صالح.');
        }

        $manifestJson = '';
        $databaseSql = '';
        $runtimeFiles = [];

        if (!empty($backup['is_dir']) || is_dir($path)) {
            $manifestPath = $path . DIRECTORY_SEPARATOR . 'manifest.json';
            $databasePath = $path . DIRECTORY_SEPARATOR . 'database.sql';
            if (is_file($manifestPath)) {
                $manifestJson = (string)file_get_contents($manifestPath);
            }
            if (!is_file($databasePath)) {
                throw new RuntimeException('ملف database.sql غير موجود داخل النسخة الاحتياطية.');
            }
            $databaseSql = (string)file_get_contents($databasePath);
            foreach (['tenant.json', '.htaccess', 'index.php'] as $runtimeName) {
                $runtimePath = $path . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . $runtimeName;
                if (is_file($runtimePath)) {
                    $runtimeFiles[$runtimeName] = (string)file_get_contents($runtimePath);
                }
            }
        } else {
            if (!class_exists('ZipArchive')) {
                throw new RuntimeException('الاستعادة من ملف ZIP تحتاج امتداد ZipArchive على الخادم.');
            }
            $zip = new ZipArchive();
            if ($zip->open($path) !== true) {
                throw new RuntimeException('تعذر فتح ملف النسخة الاحتياطية.');
            }
            $manifestIndex = $zip->locateName('manifest.json', ZipArchive::FL_NOCASE | ZipArchive::FL_NODIR);
            if ($manifestIndex !== false) {
                $manifestJson = (string)$zip->getFromIndex($manifestIndex);
            }
            $databaseIndex = $zip->locateName('database.sql', ZipArchive::FL_NOCASE | ZipArchive::FL_NODIR);
            if ($databaseIndex === false) {
                $zip->close();
                throw new RuntimeException('ملف database.sql غير موجود داخل النسخة الاحتياطية.');
            }
            $databaseSql = (string)$zip->getFromIndex($databaseIndex);
            foreach (['tenant.json', '.htaccess', 'index.php'] as $runtimeName) {
                $runtimeIndex = $zip->locateName('runtime/' . $runtimeName, ZipArchive::FL_NOCASE);
                if ($runtimeIndex !== false) {
                    $runtimeFiles[$runtimeName] = (string)$zip->getFromIndex($runtimeIndex);
                }
            }
            $zip->close();
        }

        $manifest = [];
        if ($manifestJson !== '') {
            $decoded = json_decode($manifestJson, true);
            if (is_array($decoded)) {
                $manifest = $decoded;
            }
        }
        if (trim($databaseSql) === '') {
            throw new RuntimeException('النسخة الاحتياطية لا تحتوي بيانات SQL صالحة للاستعادة.');
        }

        return [
            'manifest' => $manifest,
            'database_sql' => $databaseSql,
            'runtime_files' => $runtimeFiles,
        ];
    }
}

if (!function_exists('app_saas_split_sql_statements')) {
    function app_saas_split_sql_statements(string $sql): array
    {
        $statements = [];
        $buffer = '';
        $length = strlen($sql);
        $inSingle = false;
        $inDouble = false;
        $inBacktick = false;
        $escape = false;

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];
            $next = $i + 1 < $length ? $sql[$i + 1] : '';

            if (!$inSingle && !$inDouble && !$inBacktick) {
                if ($char === '-' && $next === '-') {
                    while ($i < $length && $sql[$i] !== "\n") {
                        $i++;
                    }
                    continue;
                }
                if ($char === '#') {
                    while ($i < $length && $sql[$i] !== "\n") {
                        $i++;
                    }
                    continue;
                }
                if ($char === '/' && $next === '*') {
                    $i += 2;
                    while ($i < $length - 1 && !($sql[$i] === '*' && $sql[$i + 1] === '/')) {
                        $i++;
                    }
                    $i++;
                    continue;
                }
            }

            $buffer .= $char;

            if ($escape) {
                $escape = false;
                continue;
            }
            if (($inSingle || $inDouble) && $char === '\\') {
                $escape = true;
                continue;
            }
            if ($char === "'" && !$inDouble && !$inBacktick) {
                $inSingle = !$inSingle;
                continue;
            }
            if ($char === '"' && !$inSingle && !$inBacktick) {
                $inDouble = !$inDouble;
                continue;
            }
            if ($char === '`' && !$inSingle && !$inDouble) {
                $inBacktick = !$inBacktick;
                continue;
            }
            if ($char === ';' && !$inSingle && !$inDouble && !$inBacktick) {
                $statement = trim($buffer);
                if ($statement !== '') {
                    $statements[] = $statement;
                }
                $buffer = '';
            }
        }

        $tail = trim($buffer);
        if ($tail !== '') {
            $statements[] = $tail;
        }

        return $statements;
    }
}

if (!function_exists('app_saas_drop_all_tables')) {
    function app_saas_drop_all_tables(mysqli $tenantConn): void
    {
        $tables = [];
        $res = $tenantConn->query('SHOW TABLES');
        while ($row = $res ? $res->fetch_row() : null) {
            $tables[] = (string)($row[0] ?? '');
        }
        if ($res instanceof mysqli_result) {
            $res->close();
        }
        if (empty($tables)) {
            return;
        }
        $tenantConn->query('SET FOREIGN_KEY_CHECKS=0');
        foreach ($tables as $table) {
            if ($table === '') {
                continue;
            }
            $tableEsc = '`' . str_replace('`', '``', $table) . '`';
            $tenantConn->query("DROP TABLE IF EXISTS {$tableEsc}");
        }
        $tenantConn->query('SET FOREIGN_KEY_CHECKS=1');
    }
}

if (!function_exists('app_saas_import_tenant_sql')) {
    function app_saas_import_tenant_sql(mysqli $tenantConn, string $sql): void
    {
        $statements = app_saas_split_sql_statements($sql);
        if (empty($statements)) {
            throw new RuntimeException('لا توجد أوامر SQL قابلة للاستيراد داخل النسخة الاحتياطية.');
        }

        $tenantConn->query('SET FOREIGN_KEY_CHECKS=0');
        foreach ($statements as $statement) {
            if (!$tenantConn->query($statement)) {
                $tenantConn->query('SET FOREIGN_KEY_CHECKS=1');
                throw new RuntimeException('فشل استيراد قاعدة المستأجر: ' . $tenantConn->error);
            }
        }
        $tenantConn->query('SET FOREIGN_KEY_CHECKS=1');
    }
}

if (!function_exists('app_saas_restore_runtime_files')) {
    function app_saas_restore_runtime_files(array $tenant, array $runtimeFiles): void
    {
        $runtime = app_saas_ensure_tenant_runtime_folder($tenant);
        $runtimePath = trim((string)($runtime['path'] ?? ''));
        if ($runtimePath === '') {
            return;
        }
        foreach ($runtimeFiles as $name => $contents) {
            $name = basename((string)$name);
            if ($name === '') {
                continue;
            }
            file_put_contents($runtimePath . DIRECTORY_SEPARATOR . $name, (string)$contents);
        }
    }
}

if (!function_exists('app_saas_restore_tenant')) {
    function app_saas_restore_tenant(array $tenant, array $backup): array
    {
        $tenantId = (int)($tenant['id'] ?? 0);
        if ($tenantId <= 0) {
            throw new RuntimeException('المستأجر غير محدد للاستعادة.');
        }

        $safetyBackup = app_saas_backup_tenant($tenant);
        $payload = app_saas_read_tenant_backup($backup);
        $tenantConn = app_saas_open_tenant_connection($tenant);
        try {
            app_saas_drop_all_tables($tenantConn);
            app_saas_import_tenant_sql($tenantConn, (string)($payload['database_sql'] ?? ''));
        } finally {
            $tenantConn->close();
        }

        app_saas_restore_runtime_files($tenant, (array)($payload['runtime_files'] ?? []));

        return [
            'ok' => true,
            'restored_from' => (string)($backup['filename'] ?? ''),
            'safety_backup' => (string)($safetyBackup['filename'] ?? ''),
            'manifest' => (array)($payload['manifest'] ?? []),
        ];
    }
}

if (!function_exists('app_saas_provision_tenant')) {
    function app_saas_provision_tenant(mysqli $controlConn, array $tenant, array $admin = []): array
    {
        $dbName = trim((string)($tenant['db_name'] ?? ''));
        $dbUser = trim((string)($tenant['db_user'] ?? ''));
        $dbHost = trim((string)($tenant['db_host'] ?? 'localhost'));
        $dbPort = max(1, (int)($tenant['db_port'] ?? 3306));
        $dbSocket = trim((string)($tenant['db_socket'] ?? ''));
        $dbPassword = trim((string)($tenant['db_password_plain'] ?? ''));
        if ($dbPassword === '' && trim((string)($tenant['db_password_enc'] ?? '')) !== '') {
            $dbPassword = app_saas_decrypt_secret((string)$tenant['db_password_enc']);
        }

        $tenantConn = null;
        $tenantConnected = false;
        try {
            $tenantConn = app_saas_open_tenant_connection($tenant);
            $tenantConnected = true;
        } catch (Throwable $existingDbError) {
            $tenantConnected = false;
        }

        if (!$tenantConnected) {
            $dbIdentifier = '`' . str_replace('`', '``', $dbName) . '`';
            $controlConn->query("CREATE DATABASE IF NOT EXISTS {$dbIdentifier} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

            $userHost = trim((string)app_env('APP_SAAS_DB_USER_HOST', '%'));
            if ($dbPassword !== '') {
                $userEsc = $controlConn->real_escape_string($dbUser);
                $hostEsc = $controlConn->real_escape_string($userHost);
                $passEsc = $controlConn->real_escape_string($dbPassword);
                $controlConn->query("CREATE USER IF NOT EXISTS '{$userEsc}'@'{$hostEsc}' IDENTIFIED BY '{$passEsc}'");
                $controlConn->query("ALTER USER '{$userEsc}'@'{$hostEsc}' IDENTIFIED BY '{$passEsc}'");
                $controlConn->query("GRANT ALL PRIVILEGES ON {$dbIdentifier}.* TO '{$userEsc}'@'{$hostEsc}'");
                $controlConn->query("FLUSH PRIVILEGES");
            }

            $tenantConn = ($dbSocket !== '')
                ? new mysqli($dbHost, $dbUser, $dbPassword, $dbName, $dbPort, $dbSocket)
                : new mysqli($dbHost, $dbUser, $dbPassword, $dbName, $dbPort);
            $tenantConn->set_charset('utf8mb4');
        }

        app_saas_prepare_tenant_database($tenantConn);
        $runtimeFolder = app_saas_ensure_tenant_runtime_folder($tenant);

        $adminUsername = strtolower(trim((string)($admin['username'] ?? 'admin')));
        $adminFullName = trim((string)($admin['full_name'] ?? ($tenant['tenant_name'] ?? 'System Admin')));
        $adminEmail = trim((string)($admin['email'] ?? ($tenant['billing_email'] ?? '')));
        $adminPassword = trim((string)($admin['password'] ?? ''));
        if ($adminPassword === '') {
            $adminPassword = app_saas_random_password();
        }
        app_saas_create_initial_admin($tenantConn, $adminUsername, $adminPassword, $adminFullName, $adminEmail);
        $tenantConn->close();

        return [
            'ok' => true,
            'admin_username' => $adminUsername,
            'admin_password' => $adminPassword,
            'db_name' => $dbName,
            'db_user' => $dbUser,
            'runtime_folder' => (string)($runtimeFolder['folder'] ?? ''),
            'runtime_path' => (string)($runtimeFolder['path'] ?? ''),
            'app_url' => (string)($runtimeFolder['app_url'] ?? ''),
        ];
    }
}

if (!function_exists('app_saas_drop_tenant_database')) {
    function app_saas_drop_tenant_database(mysqli $controlConn, array $tenant): void
    {
        $dbName = trim((string)($tenant['db_name'] ?? ''));
        if ($dbName === '') {
            throw new RuntimeException('اسم قاعدة بيانات المستأجر غير متوفر.');
        }

        $dbIdentifier = '`' . str_replace('`', '``', $dbName) . '`';
        $controlConn->query("DROP DATABASE IF EXISTS {$dbIdentifier}");
    }
}
