<?php
// saas.php
// Minimal multi-tenant SaaS control-plane bootstrap.

require_once __DIR__ . '/modules/saas/integration_adapters.php';
require_once __DIR__ . '/modules/saas/subscriptions_invoices_runtime.php';
require_once __DIR__ . '/modules/saas/gateway_notifications_runtime.php';
require_once __DIR__ . '/modules/saas/policies_runtime.php';
require_once __DIR__ . '/modules/saas/tenant_lifecycle_runtime.php';
require_once __DIR__ . '/modules/saas/ops_runtime.php';

if (!function_exists('app_saas_mode_enabled')) {
    function app_saas_mode_enabled(): bool
    {
        return app_env_flag('APP_SAAS_MODE', false);
    }
}

if (!function_exists('app_saas_normalize_host')) {
    function app_saas_normalize_host(string $host): string
    {
        $host = strtolower(trim($host));
        if ($host === '') {
            return '';
        }
        $host = preg_replace('/:\d+$/', '', $host);
        $host = trim((string)$host, '.');
        return $host;
    }
}

if (!function_exists('app_saas_secret_key')) {
    function app_saas_secret_key(): string
    {
        return trim((string)app_env('APP_SAAS_SECRET_KEY', ''));
    }
}

if (!function_exists('app_saas_gateway_host')) {
    function app_saas_gateway_host(): string
    {
        $gatewayHost = app_saas_normalize_host((string)app_env('APP_SAAS_GATEWAY_HOST', ''));
        if ($gatewayHost !== '') {
            return $gatewayHost;
        }
        $currentHost = app_saas_normalize_host((string)parse_url((string)app_env('SYSTEM_URL', ''), PHP_URL_HOST));
        if ($currentHost !== '') {
            $parts = explode('.', $currentHost);
            if (count($parts) >= 3 && strtolower((string)($parts[0] ?? '')) !== 'sys') {
                return 'sys.' . implode('.', array_slice($parts, 1));
            }
        }
        return $currentHost;
    }
}

if (!function_exists('app_saas_normalize_system_folder')) {
    function app_saas_normalize_system_folder(string $value, string $fallback = 'tenant'): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/', '-', $value);
        $value = trim((string)$value, '-');
        if ($value === '') {
            $value = strtolower(trim($fallback));
            $value = preg_replace('/[^a-z0-9]+/', '-', $value);
            $value = trim((string)$value, '-');
        }
        if ($value === '') {
            $value = 'tenant';
        }
        return substr($value, 0, 120);
    }
}

if (!function_exists('app_saas_gateway_base_url')) {
    function app_saas_gateway_base_url(): string
    {
        $baseUrl = rtrim(trim((string)app_env('APP_SAAS_GATEWAY_BASE_URL', '')), '/');
        if ($baseUrl !== '') {
            return $baseUrl;
        }
        $gatewayHost = app_saas_gateway_host();
        if ($gatewayHost !== '') {
            $scheme = strtolower(trim((string)parse_url((string)app_env('SYSTEM_URL', ''), PHP_URL_SCHEME)));
            if ($scheme !== 'http' && $scheme !== 'https') {
                $scheme = app_is_https() ? 'https' : 'https';
            }
            return $scheme . '://' . $gatewayHost;
        }
        $systemUrl = rtrim(trim((string)app_env('SYSTEM_URL', '')), '/');
        if ($systemUrl !== '') {
            return $systemUrl;
        }
        return '';
    }
}

if (!function_exists('app_saas_gateway_public_root')) {
    function app_saas_gateway_public_root(): string
    {
        $configured = rtrim(trim((string)app_env('APP_SAAS_GATEWAY_PUBLIC_ROOT', '')), DIRECTORY_SEPARATOR);
        if ($configured !== '') {
            return $configured;
        }

        $currentRoot = rtrim(__DIR__, DIRECTORY_SEPARATOR);
        $publicHtmlRoot = dirname($currentRoot);
        $gatewayHost = app_saas_gateway_host();
        if ($gatewayHost === '') {
            return $currentRoot;
        }

        $parts = explode('.', $gatewayHost);
        if (count($parts) >= 3) {
            $subdomainFolder = trim((string)$parts[0]);
            if ($subdomainFolder !== '') {
                return $publicHtmlRoot . DIRECTORY_SEPARATOR . $subdomainFolder;
            }
        }

        return $publicHtmlRoot;
    }
}

if (!function_exists('app_saas_control_plane_host')) {
    function app_saas_control_plane_host(): string
    {
        $systemUrl = trim((string)app_env('SYSTEM_URL', (defined('SYSTEM_URL') ? (string)SYSTEM_URL : '')));
        return app_saas_normalize_host((string)parse_url($systemUrl, PHP_URL_HOST));
    }
}

if (!function_exists('app_saas_is_production_owner_runtime')) {
    function app_saas_is_production_owner_runtime(): bool
    {
        if (!app_is_owner_hub()) {
            return false;
        }
        if (app_env_flag('APP_ALLOW_PRODUCTION_TENANT_DESTRUCTIVE', false)) {
            return false;
        }
        $host = app_saas_control_plane_host();
        return $host === 'work.areagles.com';
    }
}

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
            . "RewriteRule ^$ index.php [L]\n"
            . "RewriteCond %{REQUEST_FILENAME} !-f\n"
            . "RewriteCond %{REQUEST_FILENAME} !-d\n"
            . "RewriteRule ^(.*)$ ../$1?tenant=" . rawurlencode($tenantSlug) . " [QSA,L]\n";
        $indexPhp = "<?php\nheader('Location: login.php');\nexit;\n";
        $markerJson = json_encode([
            'tenant_id' => (int)($tenant['id'] ?? 0),
            'tenant_slug' => $tenantSlug,
            'system_folder' => $folder,
            'app_url' => app_saas_build_tenant_app_url($tenant),
            'generated_at' => date('c'),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

        file_put_contents($runtimePath . DIRECTORY_SEPARATOR . '.htaccess', $htaccess);
        file_put_contents($runtimePath . DIRECTORY_SEPARATOR . 'index.php', $indexPhp);
        file_put_contents($runtimePath . DIRECTORY_SEPARATOR . 'tenant.json', (string)$markerJson);

        return [
            'folder' => $folder,
            'path' => $runtimePath,
            'app_url' => app_saas_build_tenant_app_url($tenant),
        ];
    }
}

if (!function_exists('app_saas_decrypt_secret')) {
    function app_saas_decrypt_secret(string $ciphertext): string
    {
        $ciphertext = trim($ciphertext);
        if ($ciphertext === '') {
            return '';
        }

        $key = app_saas_secret_key();
        if ($key === '') {
            return '';
        }

        $raw = base64_decode($ciphertext, true);
        if (!is_string($raw) || strlen($raw) <= 16) {
            return '';
        }

        $iv = substr($raw, 0, 16);
        $payload = substr($raw, 16);
        $plain = openssl_decrypt($payload, 'AES-256-CBC', hash('sha256', $key, true), OPENSSL_RAW_DATA, $iv);
        return is_string($plain) ? $plain : '';
    }
}

if (!function_exists('app_saas_encrypt_secret')) {
    function app_saas_encrypt_secret(string $plain): string
    {
        $plain = (string)$plain;
        if ($plain === '') {
            return '';
        }

        $key = app_saas_secret_key();
        if ($key === '') {
            return '';
        }

        try {
            $iv = random_bytes(16);
        } catch (Throwable $e) {
            return '';
        }

        $cipher = openssl_encrypt($plain, 'AES-256-CBC', hash('sha256', $key, true), OPENSSL_RAW_DATA, $iv);
        if (!is_string($cipher) || $cipher === '') {
            return '';
        }

        return base64_encode($iv . $cipher);
    }
}

if (!function_exists('app_saas_control_db_config')) {
    function app_saas_control_db_config(array $fallback): array
    {
        $port = (int)app_env('APP_SAAS_CONTROL_DB_PORT', (string)($fallback['port'] ?? 3306));
        if ($port <= 0) {
            $port = (int)($fallback['port'] ?? 3306);
        }

        return [
            'host' => trim((string)app_env('APP_SAAS_CONTROL_DB_HOST', (string)($fallback['host'] ?? 'localhost'))),
            'user' => trim((string)app_env('APP_SAAS_CONTROL_DB_USER', (string)($fallback['user'] ?? ''))),
            'pass' => (string)app_env('APP_SAAS_CONTROL_DB_PASS', (string)($fallback['pass'] ?? '')),
            'name' => trim((string)app_env('APP_SAAS_CONTROL_DB_NAME', (string)($fallback['name'] ?? ''))),
            'port' => $port,
            'socket' => trim((string)app_env('APP_SAAS_CONTROL_DB_SOCKET', (string)($fallback['socket'] ?? ''))),
        ];
    }
}

if (!function_exists('app_saas_open_control_connection')) {
    function app_saas_open_control_connection(array $config): mysqli
    {
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        $socket = trim((string)($config['socket'] ?? ''));
        $host = trim((string)($config['host'] ?? 'localhost'));
        $user = trim((string)($config['user'] ?? ''));
        $pass = (string)($config['pass'] ?? '');
        $name = trim((string)($config['name'] ?? ''));
        $port = (int)($config['port'] ?? 3306);

        $conn = ($socket !== '')
            ? new mysqli($host, $user, $pass, $name, $port, $socket)
            : new mysqli($host, $user, $pass, $name, $port);
        $conn->set_charset('utf8mb4');
        return $conn;
    }
}

if (!function_exists('app_saas_ensure_control_plane_schema')) {
    function app_saas_ensure_control_plane_schema(mysqli $conn): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;

        $conn->query("
            CREATE TABLE IF NOT EXISTS saas_tenants (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                tenant_slug VARCHAR(120) NOT NULL,
                tenant_name VARCHAR(190) NOT NULL,
                system_name VARCHAR(190) NOT NULL DEFAULT '',
                system_folder VARCHAR(120) DEFAULT NULL,
                legal_name VARCHAR(190) NOT NULL DEFAULT '',
                status ENUM('provisioning','active','suspended','archived') NOT NULL DEFAULT 'provisioning',
                plan_code VARCHAR(80) NOT NULL DEFAULT 'basic',
                provision_profile VARCHAR(80) NOT NULL DEFAULT 'standard',
                policy_pack VARCHAR(80) NOT NULL DEFAULT 'standard',
                billing_email VARCHAR(190) NOT NULL DEFAULT '',
                billing_portal_token VARCHAR(96) NOT NULL DEFAULT '',
                app_url VARCHAR(255) NOT NULL DEFAULT '',
                db_host VARCHAR(190) NOT NULL DEFAULT 'localhost',
                db_port INT UNSIGNED NOT NULL DEFAULT 3306,
                db_name VARCHAR(190) NOT NULL DEFAULT '',
                db_user VARCHAR(190) NOT NULL DEFAULT '',
                db_password_plain TEXT DEFAULT NULL,
                db_password_enc LONGTEXT DEFAULT NULL,
                db_socket VARCHAR(255) NOT NULL DEFAULT '',
                timezone VARCHAR(80) NOT NULL DEFAULT 'Africa/Cairo',
                locale VARCHAR(20) NOT NULL DEFAULT 'ar',
                trial_ends_at DATETIME DEFAULT NULL,
                subscribed_until DATETIME DEFAULT NULL,
                users_limit INT UNSIGNED NOT NULL DEFAULT 0,
                storage_limit_mb INT UNSIGNED NOT NULL DEFAULT 0,
                ops_keep_latest INT UNSIGNED NOT NULL DEFAULT 500,
                ops_keep_days INT UNSIGNED NOT NULL DEFAULT 30,
                policy_exception_preset VARCHAR(120) DEFAULT NULL,
                policy_overrides_json LONGTEXT DEFAULT NULL,
                notes TEXT DEFAULT NULL,
                activated_at DATETIME DEFAULT NULL,
                archived_at DATETIME DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_tenant_slug (tenant_slug),
                UNIQUE KEY uniq_billing_portal_token (billing_portal_token),
                KEY idx_tenant_status (status),
                KEY idx_plan_code (plan_code)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        if (function_exists('app_table_has_column') && !app_table_has_column($conn, 'saas_tenants', 'billing_portal_token')) {
            $conn->query("ALTER TABLE saas_tenants ADD COLUMN billing_portal_token VARCHAR(96) NOT NULL DEFAULT '' AFTER billing_email");
            app_table_has_column_reset('saas_tenants', 'billing_portal_token');
            $conn->query("ALTER TABLE saas_tenants ADD UNIQUE KEY uniq_billing_portal_token (billing_portal_token)");
        }
        if (function_exists('app_table_has_column') && !app_table_has_column($conn, 'saas_tenants', 'current_subscription_id')) {
            $conn->query("ALTER TABLE saas_tenants ADD COLUMN current_subscription_id INT UNSIGNED DEFAULT NULL AFTER storage_limit_mb");
            app_table_has_column_reset('saas_tenants', 'current_subscription_id');
        }
        if (function_exists('app_table_has_column') && !app_table_has_column($conn, 'saas_tenants', 'system_name')) {
            $conn->query("ALTER TABLE saas_tenants ADD COLUMN system_name VARCHAR(190) NOT NULL DEFAULT '' AFTER tenant_name");
            app_table_has_column_reset('saas_tenants', 'system_name');
        }
        if (function_exists('app_table_has_column') && !app_table_has_column($conn, 'saas_tenants', 'system_folder')) {
            $conn->query("ALTER TABLE saas_tenants ADD COLUMN system_folder VARCHAR(120) DEFAULT NULL AFTER system_name");
            app_table_has_column_reset('saas_tenants', 'system_folder');
        }
        if (function_exists('app_table_has_column') && !app_table_has_column($conn, 'saas_tenants', 'provision_profile')) {
            $conn->query("ALTER TABLE saas_tenants ADD COLUMN provision_profile VARCHAR(80) NOT NULL DEFAULT 'standard' AFTER plan_code");
            app_table_has_column_reset('saas_tenants', 'provision_profile');
        }
        if (function_exists('app_table_has_column') && !app_table_has_column($conn, 'saas_tenants', 'policy_pack')) {
            $conn->query("ALTER TABLE saas_tenants ADD COLUMN policy_pack VARCHAR(80) NOT NULL DEFAULT 'standard' AFTER provision_profile");
            app_table_has_column_reset('saas_tenants', 'policy_pack');
        }
        if (function_exists('app_table_has_column') && !app_table_has_column($conn, 'saas_tenants', 'ops_keep_latest')) {
            $conn->query("ALTER TABLE saas_tenants ADD COLUMN ops_keep_latest INT UNSIGNED NOT NULL DEFAULT 500 AFTER storage_limit_mb");
            app_table_has_column_reset('saas_tenants', 'ops_keep_latest');
        }
        if (function_exists('app_table_has_column') && !app_table_has_column($conn, 'saas_tenants', 'ops_keep_days')) {
            $conn->query("ALTER TABLE saas_tenants ADD COLUMN ops_keep_days INT UNSIGNED NOT NULL DEFAULT 30 AFTER ops_keep_latest");
            app_table_has_column_reset('saas_tenants', 'ops_keep_days');
        }
        if (function_exists('app_table_has_column') && !app_table_has_column($conn, 'saas_tenants', 'policy_exception_preset')) {
            $conn->query("ALTER TABLE saas_tenants ADD COLUMN policy_exception_preset VARCHAR(120) DEFAULT NULL AFTER ops_keep_days");
            app_table_has_column_reset('saas_tenants', 'policy_exception_preset');
        }
        if (function_exists('app_table_has_column') && !app_table_has_column($conn, 'saas_tenants', 'policy_overrides_json')) {
            $conn->query("ALTER TABLE saas_tenants ADD COLUMN policy_overrides_json LONGTEXT DEFAULT NULL AFTER policy_exception_preset");
            app_table_has_column_reset('saas_tenants', 'policy_overrides_json');
        }

        $conn->query("
            CREATE TABLE IF NOT EXISTS saas_tenant_domains (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                tenant_id INT UNSIGNED NOT NULL,
                domain VARCHAR(255) NOT NULL,
                is_primary TINYINT(1) NOT NULL DEFAULT 0,
                verified_at DATETIME DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_domain (domain),
                KEY idx_tenant_primary (tenant_id, is_primary),
                CONSTRAINT fk_saas_domain_tenant FOREIGN KEY (tenant_id) REFERENCES saas_tenants(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $conn->query("
            CREATE TABLE IF NOT EXISTS saas_subscriptions (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                tenant_id INT UNSIGNED NOT NULL,
                billing_cycle ENUM('monthly','quarterly','yearly','manual') NOT NULL DEFAULT 'monthly',
                status ENUM('trial','active','past_due','suspended','cancelled') NOT NULL DEFAULT 'trial',
                plan_code VARCHAR(80) NOT NULL DEFAULT 'basic',
                amount DECIMAL(18,2) NOT NULL DEFAULT 0,
                currency_code VARCHAR(12) NOT NULL DEFAULT 'EGP',
                starts_at DATETIME DEFAULT NULL,
                cycles_count INT UNSIGNED NOT NULL DEFAULT 1,
                trial_days INT UNSIGNED NOT NULL DEFAULT 14,
                grace_days INT UNSIGNED NOT NULL DEFAULT 7,
                renews_at DATETIME DEFAULT NULL,
                ends_at DATETIME DEFAULT NULL,
                external_ref VARCHAR(190) NOT NULL DEFAULT '',
                notes TEXT DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_subscription_tenant (tenant_id),
                KEY idx_subscription_status (status),
                CONSTRAINT fk_saas_subscription_tenant FOREIGN KEY (tenant_id) REFERENCES saas_tenants(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        if (function_exists('app_table_has_column') && !app_table_has_column($conn, 'saas_subscriptions', 'cycles_count')) {
            $conn->query("ALTER TABLE saas_subscriptions ADD COLUMN cycles_count INT UNSIGNED NOT NULL DEFAULT 1 AFTER starts_at");
            app_table_has_column_reset('saas_subscriptions', 'cycles_count');
        }
        if (function_exists('app_table_has_column') && !app_table_has_column($conn, 'saas_subscriptions', 'trial_days')) {
            $conn->query("ALTER TABLE saas_subscriptions ADD COLUMN trial_days INT UNSIGNED NOT NULL DEFAULT 14 AFTER cycles_count");
            app_table_has_column_reset('saas_subscriptions', 'trial_days');
        }
        if (function_exists('app_table_has_column') && !app_table_has_column($conn, 'saas_subscriptions', 'grace_days')) {
            $conn->query("ALTER TABLE saas_subscriptions ADD COLUMN grace_days INT UNSIGNED NOT NULL DEFAULT 7 AFTER trial_days");
            app_table_has_column_reset('saas_subscriptions', 'grace_days');
        }

        $conn->query("
            CREATE TABLE IF NOT EXISTS saas_subscription_invoices (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                tenant_id INT UNSIGNED NOT NULL,
                subscription_id INT UNSIGNED NOT NULL,
                invoice_number VARCHAR(40) NOT NULL DEFAULT '',
                status ENUM('draft','issued','paid','cancelled') NOT NULL DEFAULT 'issued',
                amount DECIMAL(18,2) NOT NULL DEFAULT 0,
                currency_code VARCHAR(12) NOT NULL DEFAULT 'EGP',
                invoice_date DATETIME DEFAULT NULL,
                due_date DATETIME DEFAULT NULL,
                period_start DATETIME DEFAULT NULL,
                period_end DATETIME DEFAULT NULL,
                paid_at DATETIME DEFAULT NULL,
                payment_ref VARCHAR(190) NOT NULL DEFAULT '',
                access_token VARCHAR(96) NOT NULL DEFAULT '',
                gateway_provider VARCHAR(80) NOT NULL DEFAULT 'manual',
                gateway_status VARCHAR(80) NOT NULL DEFAULT 'pending',
                gateway_public_url VARCHAR(255) NOT NULL DEFAULT '',
                notes TEXT DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_saas_invoice_period (subscription_id, period_start, period_end),
                UNIQUE KEY uniq_saas_invoice_access_token (access_token),
                KEY idx_saas_invoice_tenant (tenant_id),
                KEY idx_saas_invoice_status (status),
                CONSTRAINT fk_saas_invoice_tenant FOREIGN KEY (tenant_id) REFERENCES saas_tenants(id) ON DELETE CASCADE,
                CONSTRAINT fk_saas_invoice_subscription FOREIGN KEY (subscription_id) REFERENCES saas_subscriptions(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        if (function_exists('app_table_has_column') && !app_table_has_column($conn, 'saas_subscription_invoices', 'paid_at')) {
            $conn->query("ALTER TABLE saas_subscription_invoices ADD COLUMN paid_at DATETIME DEFAULT NULL AFTER period_end");
            app_table_has_column_reset('saas_subscription_invoices', 'paid_at');
        }
        if (function_exists('app_table_has_column') && !app_table_has_column($conn, 'saas_subscription_invoices', 'payment_ref')) {
            $conn->query("ALTER TABLE saas_subscription_invoices ADD COLUMN payment_ref VARCHAR(190) NOT NULL DEFAULT '' AFTER paid_at");
            app_table_has_column_reset('saas_subscription_invoices', 'payment_ref');
        }
        if (function_exists('app_table_has_column') && !app_table_has_column($conn, 'saas_subscription_invoices', 'access_token')) {
            $conn->query("ALTER TABLE saas_subscription_invoices ADD COLUMN access_token VARCHAR(96) NOT NULL DEFAULT '' AFTER payment_ref");
            app_table_has_column_reset('saas_subscription_invoices', 'access_token');
        }
        if (function_exists('app_table_has_column') && !app_table_has_column($conn, 'saas_subscription_invoices', 'gateway_provider')) {
            $conn->query("ALTER TABLE saas_subscription_invoices ADD COLUMN gateway_provider VARCHAR(80) NOT NULL DEFAULT 'manual' AFTER access_token");
            app_table_has_column_reset('saas_subscription_invoices', 'gateway_provider');
        }
        if (function_exists('app_table_has_column') && !app_table_has_column($conn, 'saas_subscription_invoices', 'gateway_status')) {
            $conn->query("ALTER TABLE saas_subscription_invoices ADD COLUMN gateway_status VARCHAR(80) NOT NULL DEFAULT 'pending' AFTER gateway_provider");
            app_table_has_column_reset('saas_subscription_invoices', 'gateway_status');
        }
        if (function_exists('app_table_has_column') && !app_table_has_column($conn, 'saas_subscription_invoices', 'gateway_public_url')) {
            $conn->query("ALTER TABLE saas_subscription_invoices ADD COLUMN gateway_public_url VARCHAR(255) NOT NULL DEFAULT '' AFTER gateway_status");
            app_table_has_column_reset('saas_subscription_invoices', 'gateway_public_url');
        }

        $conn->query("
            CREATE TABLE IF NOT EXISTS saas_subscription_invoice_payments (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                tenant_id INT UNSIGNED NOT NULL,
                invoice_id INT UNSIGNED NOT NULL,
                subscription_id INT UNSIGNED NOT NULL,
                amount DECIMAL(18,2) NOT NULL DEFAULT 0,
                currency_code VARCHAR(12) NOT NULL DEFAULT 'EGP',
                payment_method VARCHAR(60) NOT NULL DEFAULT 'manual',
                payment_ref VARCHAR(190) NOT NULL DEFAULT '',
                paid_at DATETIME DEFAULT NULL,
                status ENUM('posted','reversed') NOT NULL DEFAULT 'posted',
                notes TEXT DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_saas_invoice_payment_invoice (invoice_id),
                KEY idx_saas_invoice_payment_tenant (tenant_id),
                KEY idx_saas_invoice_payment_status (status),
                CONSTRAINT fk_saas_invoice_payment_invoice FOREIGN KEY (invoice_id) REFERENCES saas_subscription_invoices(id) ON DELETE CASCADE,
                CONSTRAINT fk_saas_invoice_payment_tenant FOREIGN KEY (tenant_id) REFERENCES saas_tenants(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $conn->query("
            CREATE TABLE IF NOT EXISTS saas_operation_log (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                tenant_id INT UNSIGNED DEFAULT NULL,
                action_code VARCHAR(80) NOT NULL,
                action_label VARCHAR(190) NOT NULL DEFAULT '',
                actor_name VARCHAR(190) NOT NULL DEFAULT '',
                context_json LONGTEXT DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                KEY idx_saas_operation_tenant (tenant_id),
                KEY idx_saas_operation_action (action_code),
                KEY idx_saas_operation_created (created_at),
                CONSTRAINT fk_saas_operation_tenant FOREIGN KEY (tenant_id) REFERENCES saas_tenants(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $conn->query("
            CREATE TABLE IF NOT EXISTS saas_webhook_deliveries (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                tenant_id INT UNSIGNED DEFAULT NULL,
                event_code VARCHAR(120) NOT NULL,
                event_label VARCHAR(190) NOT NULL DEFAULT '',
                target_url VARCHAR(255) NOT NULL DEFAULT '',
                request_headers_json LONGTEXT DEFAULT NULL,
                payload_json LONGTEXT DEFAULT NULL,
                status ENUM('pending','sent','failed') NOT NULL DEFAULT 'pending',
                attempt_count INT UNSIGNED NOT NULL DEFAULT 0,
                max_attempts INT UNSIGNED NOT NULL DEFAULT 5,
                http_code INT NOT NULL DEFAULT 0,
                response_body LONGTEXT DEFAULT NULL,
                last_error VARCHAR(255) NOT NULL DEFAULT '',
                last_attempt_at DATETIME DEFAULT NULL,
                next_retry_at DATETIME DEFAULT NULL,
                delivered_at DATETIME DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_saas_webhook_tenant (tenant_id),
                KEY idx_saas_webhook_event (event_code),
                KEY idx_saas_webhook_status_retry (status, next_retry_at),
                KEY idx_saas_webhook_created (created_at),
                CONSTRAINT fk_saas_webhook_tenant FOREIGN KEY (tenant_id) REFERENCES saas_tenants(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $conn->query("
            CREATE TABLE IF NOT EXISTS saas_webhook_test_inbox (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                source_ip VARCHAR(80) NOT NULL DEFAULT '',
                request_method VARCHAR(12) NOT NULL DEFAULT 'POST',
                query_string VARCHAR(255) NOT NULL DEFAULT '',
                headers_json LONGTEXT DEFAULT NULL,
                payload_json LONGTEXT DEFAULT NULL,
                raw_body LONGTEXT DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                KEY idx_saas_webhook_test_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $conn->query("
            CREATE TABLE IF NOT EXISTS saas_provision_profiles (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                profile_key VARCHAR(80) NOT NULL,
                label VARCHAR(120) NOT NULL,
                plan_code VARCHAR(80) NOT NULL DEFAULT 'basic',
                timezone VARCHAR(80) NOT NULL DEFAULT 'Africa/Cairo',
                locale VARCHAR(20) NOT NULL DEFAULT 'ar',
                users_limit INT UNSIGNED NOT NULL DEFAULT 0,
                storage_limit_mb INT UNSIGNED NOT NULL DEFAULT 0,
                is_system TINYINT(1) NOT NULL DEFAULT 0,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                sort_order INT NOT NULL DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_profile_key (profile_key),
                KEY idx_profile_active_sort (is_active, sort_order, id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $conn->query("
            CREATE TABLE IF NOT EXISTS saas_policy_packs (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                pack_key VARCHAR(80) NOT NULL,
                label VARCHAR(120) NOT NULL,
                tenant_status VARCHAR(40) NOT NULL DEFAULT 'active',
                timezone VARCHAR(80) NOT NULL DEFAULT 'Africa/Cairo',
                locale VARCHAR(20) NOT NULL DEFAULT 'ar',
                trial_days INT UNSIGNED NOT NULL DEFAULT 14,
                grace_days INT UNSIGNED NOT NULL DEFAULT 7,
                ops_keep_latest INT UNSIGNED NOT NULL DEFAULT 500,
                ops_keep_days INT UNSIGNED NOT NULL DEFAULT 30,
                is_system TINYINT(1) NOT NULL DEFAULT 0,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                sort_order INT NOT NULL DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_pack_key (pack_key),
                KEY idx_pack_active_sort (is_active, sort_order, id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $conn->query("
            CREATE TABLE IF NOT EXISTS saas_policy_exception_presets (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                preset_key VARCHAR(80) NOT NULL,
                label VARCHAR(120) NOT NULL,
                tenant_status VARCHAR(40) DEFAULT NULL,
                timezone VARCHAR(80) DEFAULT NULL,
                locale VARCHAR(20) DEFAULT NULL,
                trial_days INT UNSIGNED DEFAULT NULL,
                grace_days INT UNSIGNED DEFAULT NULL,
                ops_keep_latest INT UNSIGNED DEFAULT NULL,
                ops_keep_days INT UNSIGNED DEFAULT NULL,
                is_system TINYINT(1) NOT NULL DEFAULT 0,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                sort_order INT NOT NULL DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_exception_preset_key (preset_key),
                KEY idx_exception_preset_active_sort (is_active, sort_order, id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $defaultProfiles = [
            ['light', 'Light', 'basic', 'Africa/Cairo', 'ar', 3, 512, 1, 10],
            ['standard', 'Standard', 'standard', 'Africa/Cairo', 'ar', 10, 2048, 1, 20],
            ['inventory', 'Inventory', 'inventory', 'Africa/Cairo', 'ar', 15, 4096, 1, 30],
            ['full', 'Full', 'full', 'Africa/Cairo', 'ar', 30, 8192, 1, 40],
        ];
        $stmtProfile = $conn->prepare("
            INSERT INTO saas_provision_profiles
            (profile_key, label, plan_code, timezone, locale, users_limit, storage_limit_mb, is_system, sort_order)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                label = VALUES(label),
                plan_code = VALUES(plan_code),
                timezone = VALUES(timezone),
                locale = VALUES(locale),
                users_limit = VALUES(users_limit),
                storage_limit_mb = VALUES(storage_limit_mb),
                is_system = VALUES(is_system),
                sort_order = VALUES(sort_order)
        ");
        foreach ($defaultProfiles as $profileRow) {
            [$profileKey, $label, $planCode, $timezone, $locale, $usersLimit, $storageLimit, $isSystem, $sortOrder] = $profileRow;
            $stmtProfile->bind_param('sssssiiii', $profileKey, $label, $planCode, $timezone, $locale, $usersLimit, $storageLimit, $isSystem, $sortOrder);
            $stmtProfile->execute();
        }
        $stmtProfile->close();

        $defaultPolicyPacks = [
            ['light', 'Light', 'active', 'Africa/Cairo', 'ar', 7, 3, 200, 14, 1, 10],
            ['standard', 'Standard', 'active', 'Africa/Cairo', 'ar', 14, 7, 500, 30, 1, 20],
            ['strict', 'Strict', 'active', 'Africa/Cairo', 'ar', 7, 2, 300, 14, 1, 30],
            ['enterprise', 'Enterprise', 'active', 'Africa/Cairo', 'en', 21, 10, 2000, 90, 1, 40],
        ];
        $stmtPack = $conn->prepare("
            INSERT INTO saas_policy_packs
            (pack_key, label, tenant_status, timezone, locale, trial_days, grace_days, ops_keep_latest, ops_keep_days, is_system, sort_order)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                label = VALUES(label),
                tenant_status = VALUES(tenant_status),
                timezone = VALUES(timezone),
                locale = VALUES(locale),
                trial_days = VALUES(trial_days),
                grace_days = VALUES(grace_days),
                ops_keep_latest = VALUES(ops_keep_latest),
                ops_keep_days = VALUES(ops_keep_days),
                is_system = VALUES(is_system),
                sort_order = VALUES(sort_order)
        ");
        foreach ($defaultPolicyPacks as $packRow) {
            [$packKey, $label, $tenantStatus, $timezone, $locale, $trialDays, $graceDays, $opsKeepLatest, $opsKeepDays, $isSystem, $sortOrder] = $packRow;
            $stmtPack->bind_param('sssssiiiiii', $packKey, $label, $tenantStatus, $timezone, $locale, $trialDays, $graceDays, $opsKeepLatest, $opsKeepDays, $isSystem, $sortOrder);
            $stmtPack->execute();
        }
        $stmtPack->close();

        $defaultExceptionPresets = [
            ['trial_plus_7', 'تمديد تجربة 7 أيام', null, null, null, 21, null, null, null, 1, 10],
            ['grace_plus_14', 'سماح 14 يوم', null, null, null, null, 14, null, null, 1, 20],
            ['retention_long', 'احتفاظ طويل بالسجل', null, null, null, null, null, 2000, 180, 1, 30],
            ['english_utc', 'تشغيل إنجليزي', null, 'UTC', 'en', null, null, null, null, 1, 40],
        ];
        $stmtExceptionPreset = $conn->prepare("
            INSERT INTO saas_policy_exception_presets
            (preset_key, label, tenant_status, timezone, locale, trial_days, grace_days, ops_keep_latest, ops_keep_days, is_system, sort_order)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                label = VALUES(label),
                tenant_status = VALUES(tenant_status),
                timezone = VALUES(timezone),
                locale = VALUES(locale),
                trial_days = VALUES(trial_days),
                grace_days = VALUES(grace_days),
                ops_keep_latest = VALUES(ops_keep_latest),
                ops_keep_days = VALUES(ops_keep_days),
                is_system = VALUES(is_system),
                sort_order = VALUES(sort_order)
        ");
        foreach ($defaultExceptionPresets as $presetRow) {
            [$presetKey, $label, $tenantStatus, $timezone, $locale, $trialDays, $graceDays, $opsKeepLatest, $opsKeepDays, $isSystem, $sortOrder] = $presetRow;
            $stmtExceptionPreset->bind_param('sssssiiiiii', $presetKey, $label, $tenantStatus, $timezone, $locale, $trialDays, $graceDays, $opsKeepLatest, $opsKeepDays, $isSystem, $sortOrder);
            $stmtExceptionPreset->execute();
        }
        $stmtExceptionPreset->close();
    }
}

if (!function_exists('app_saas_list_provision_profiles')) {
    function app_saas_list_provision_profiles(mysqli $controlConn, bool $activeOnly = false): array
    {
        $sql = "SELECT * FROM saas_provision_profiles";
        if ($activeOnly) {
            $sql .= " WHERE is_active = 1";
        }
        $sql .= " ORDER BY sort_order ASC, id ASC";
        $result = $controlConn->query($sql);
        $rows = [];
        if ($result instanceof mysqli_result) {
            while ($row = $result->fetch_assoc()) {
                $rows[] = $row;
            }
            $result->close();
        }
        return $rows;
    }
}

if (!function_exists('app_saas_upsert_provision_profile')) {
    function app_saas_upsert_provision_profile(mysqli $controlConn, array $data): int
    {
        $profileKey = strtolower(trim((string)($data['profile_key'] ?? '')));
        $label = trim((string)($data['label'] ?? ''));
        $planCode = trim((string)($data['plan_code'] ?? 'basic'));
        $timezone = trim((string)($data['timezone'] ?? 'Africa/Cairo'));
        $locale = trim((string)($data['locale'] ?? 'ar'));
        $usersLimit = max(0, (int)($data['users_limit'] ?? 0));
        $storageLimit = max(0, (int)($data['storage_limit_mb'] ?? 0));
        $sortOrder = (int)($data['sort_order'] ?? 0);
        $isActive = !empty($data['is_active']) ? 1 : 0;

        if ($profileKey === '' || $label === '') {
            throw new RuntimeException(app_tr('بيانات بروفايل التهيئة غير مكتملة.', 'Provision profile data is incomplete.'));
        }

        $stmt = $controlConn->prepare("
            INSERT INTO saas_provision_profiles
            (profile_key, label, plan_code, timezone, locale, users_limit, storage_limit_mb, is_active, sort_order)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                label = VALUES(label),
                plan_code = VALUES(plan_code),
                timezone = VALUES(timezone),
                locale = VALUES(locale),
                users_limit = VALUES(users_limit),
                storage_limit_mb = VALUES(storage_limit_mb),
                is_active = VALUES(is_active),
                sort_order = VALUES(sort_order)
        ");
        $stmt->bind_param('sssssiiii', $profileKey, $label, $planCode, $timezone, $locale, $usersLimit, $storageLimit, $isActive, $sortOrder);
        $stmt->execute();
        $id = (int)$stmt->insert_id;
        $stmt->close();
        return $id;
    }
}

if (!function_exists('app_saas_delete_provision_profile')) {
    function app_saas_delete_provision_profile(mysqli $controlConn, string $profileKey): void
    {
        $profileKey = strtolower(trim($profileKey));
        if ($profileKey === '') {
            throw new RuntimeException(app_tr('بروفايل التهيئة غير صالح.', 'Provision profile is invalid.'));
        }
        $stmtCheck = $controlConn->prepare("SELECT is_system FROM saas_provision_profiles WHERE profile_key = ? LIMIT 1");
        $stmtCheck->bind_param('s', $profileKey);
        $stmtCheck->execute();
        $row = $stmtCheck->get_result()->fetch_assoc();
        $stmtCheck->close();
        if (!$row) {
            throw new RuntimeException(app_tr('بروفايل التهيئة غير موجود.', 'Provision profile was not found.'));
        }
        if ((int)($row['is_system'] ?? 0) === 1) {
            throw new RuntimeException(app_tr('لا يمكن حذف بروفايل تهيئة افتراضي من النظام.', 'The default provision profile cannot be deleted.'));
        }
        $stmtDelete = $controlConn->prepare("DELETE FROM saas_provision_profiles WHERE profile_key = ? LIMIT 1");
        $stmtDelete->bind_param('s', $profileKey);
        $stmtDelete->execute();
        $stmtDelete->close();
    }
}

if (!function_exists('app_saas_find_provision_profile')) {
    function app_saas_find_provision_profile(mysqli $controlConn, string $profileKey): ?array
    {
        $profileKey = strtolower(trim($profileKey));
        if ($profileKey === '') {
            return null;
        }
        $stmt = $controlConn->prepare("SELECT * FROM saas_provision_profiles WHERE profile_key = ? LIMIT 1");
        $stmt->bind_param('s', $profileKey);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }
}

if (!function_exists('app_saas_list_policy_packs')) {
    function app_saas_list_policy_packs(mysqli $controlConn, bool $activeOnly = false): array
    {
        $sql = "SELECT * FROM saas_policy_packs";
        if ($activeOnly) {
            $sql .= " WHERE is_active = 1";
        }
        $sql .= " ORDER BY sort_order ASC, id ASC";
        $result = $controlConn->query($sql);
        $rows = [];
        if ($result instanceof mysqli_result) {
            while ($row = $result->fetch_assoc()) {
                $rows[] = $row;
            }
            $result->close();
        }
        return $rows;
    }
}

if (!function_exists('app_saas_upsert_policy_pack')) {
    function app_saas_upsert_policy_pack(mysqli $controlConn, array $data): int
    {
        $packKey = strtolower(trim((string)($data['pack_key'] ?? '')));
        if ($packKey === '') {
            throw new RuntimeException('Pack key مطلوب.');
        }
        $label = trim((string)($data['label'] ?? $packKey));
        $tenantStatus = strtolower(trim((string)($data['tenant_status'] ?? 'active')));
        if (!in_array($tenantStatus, ['provisioning', 'active', 'suspended', 'archived'], true)) {
            $tenantStatus = 'active';
        }
        $timezone = trim((string)($data['timezone'] ?? 'Africa/Cairo'));
        $locale = trim((string)($data['locale'] ?? 'ar'));
        $trialDays = max(1, (int)($data['trial_days'] ?? 14));
        $graceDays = max(0, (int)($data['grace_days'] ?? 7));
        $opsKeepLatest = max(1, (int)($data['ops_keep_latest'] ?? 500));
        $opsKeepDays = max(1, (int)($data['ops_keep_days'] ?? 30));
        $isActive = !empty($data['is_active']) ? 1 : 0;
        $sortOrder = (int)($data['sort_order'] ?? 0);

        $stmt = $controlConn->prepare("
            INSERT INTO saas_policy_packs
            (pack_key, label, tenant_status, timezone, locale, trial_days, grace_days, ops_keep_latest, ops_keep_days, is_active, sort_order)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                label = VALUES(label),
                tenant_status = VALUES(tenant_status),
                timezone = VALUES(timezone),
                locale = VALUES(locale),
                trial_days = VALUES(trial_days),
                grace_days = VALUES(grace_days),
                ops_keep_latest = VALUES(ops_keep_latest),
                ops_keep_days = VALUES(ops_keep_days),
                is_active = VALUES(is_active),
                sort_order = VALUES(sort_order)
        ");
        $stmt->bind_param('sssssiiiiii', $packKey, $label, $tenantStatus, $timezone, $locale, $trialDays, $graceDays, $opsKeepLatest, $opsKeepDays, $isActive, $sortOrder);
        $stmt->execute();
        $id = (int)$stmt->insert_id;
        $stmt->close();
        return $id;
    }
}

if (!function_exists('app_saas_delete_policy_pack')) {
    function app_saas_delete_policy_pack(mysqli $controlConn, string $packKey): void
    {
        $packKey = strtolower(trim($packKey));
        if ($packKey === '') {
            throw new RuntimeException('Pack key مطلوب.');
        }
        $stmtCheck = $controlConn->prepare("SELECT is_system FROM saas_policy_packs WHERE pack_key = ? LIMIT 1");
        $stmtCheck->bind_param('s', $packKey);
        $stmtCheck->execute();
        $row = $stmtCheck->get_result()->fetch_assoc();
        $stmtCheck->close();
        if (!$row) {
            throw new RuntimeException(app_tr('حزمة السياسات غير موجودة.', 'Policy pack was not found.'));
        }
        if ((int)($row['is_system'] ?? 0) === 1) {
            throw new RuntimeException(app_tr('لا يمكن حذف حزمة سياسات نظامية.', 'A system policy pack cannot be deleted.'));
        }
        $stmtDelete = $controlConn->prepare("DELETE FROM saas_policy_packs WHERE pack_key = ? LIMIT 1");
        $stmtDelete->bind_param('s', $packKey);
        $stmtDelete->execute();
        $stmtDelete->close();
    }
}

if (!function_exists('app_saas_find_policy_pack')) {
    function app_saas_find_policy_pack(mysqli $controlConn, string $packKey): ?array
    {
        $packKey = strtolower(trim($packKey));
        if ($packKey === '') {
            return null;
        }
        $stmt = $controlConn->prepare("SELECT * FROM saas_policy_packs WHERE pack_key = ? LIMIT 1");
        $stmt->bind_param('s', $packKey);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }
}

if (!function_exists('app_saas_list_policy_exception_presets')) {
    function app_saas_list_policy_exception_presets(mysqli $controlConn, bool $activeOnly = false): array
    {
        $sql = "SELECT * FROM saas_policy_exception_presets";
        if ($activeOnly) {
            $sql .= " WHERE is_active = 1";
        }
        $sql .= " ORDER BY sort_order ASC, id ASC";
        $result = $controlConn->query($sql);
        $rows = [];
        if ($result instanceof mysqli_result) {
            while ($row = $result->fetch_assoc()) {
                $rows[] = $row;
            }
            $result->close();
        }
        return $rows;
    }
}

if (!function_exists('app_saas_find_policy_exception_preset')) {
    function app_saas_find_policy_exception_preset(mysqli $controlConn, string $presetKey): ?array
    {
        $presetKey = strtolower(trim($presetKey));
        if ($presetKey === '') {
            return null;
        }
        $stmt = $controlConn->prepare("SELECT * FROM saas_policy_exception_presets WHERE preset_key = ? LIMIT 1");
        $stmt->bind_param('s', $presetKey);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }
}

if (!function_exists('app_saas_upsert_policy_exception_preset')) {
    function app_saas_upsert_policy_exception_preset(mysqli $controlConn, array $data): int
    {
        $presetKey = strtolower(trim((string)($data['preset_key'] ?? '')));
        if ($presetKey === '') {
            throw new RuntimeException('Preset key مطلوب.');
        }
        $label = trim((string)($data['label'] ?? $presetKey));
        $normalized = app_saas_normalize_policy_overrides($data);
        $tenantStatus = $normalized['tenant_status'] ?? null;
        $timezone = $normalized['timezone'] ?? null;
        $locale = $normalized['locale'] ?? null;
        $trialDays = $normalized['trial_days'] ?? null;
        $graceDays = $normalized['grace_days'] ?? null;
        $opsKeepLatest = $normalized['ops_keep_latest'] ?? null;
        $opsKeepDays = $normalized['ops_keep_days'] ?? null;
        $isActive = !empty($data['is_active']) ? 1 : 0;
        $sortOrder = (int)($data['sort_order'] ?? 0);

        $stmt = $controlConn->prepare("
            INSERT INTO saas_policy_exception_presets
            (preset_key, label, tenant_status, timezone, locale, trial_days, grace_days, ops_keep_latest, ops_keep_days, is_active, sort_order)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                label = VALUES(label),
                tenant_status = VALUES(tenant_status),
                timezone = VALUES(timezone),
                locale = VALUES(locale),
                trial_days = VALUES(trial_days),
                grace_days = VALUES(grace_days),
                ops_keep_latest = VALUES(ops_keep_latest),
                ops_keep_days = VALUES(ops_keep_days),
                is_active = VALUES(is_active),
                sort_order = VALUES(sort_order)
        ");
        $stmt->bind_param('sssssiiiiii', $presetKey, $label, $tenantStatus, $timezone, $locale, $trialDays, $graceDays, $opsKeepLatest, $opsKeepDays, $isActive, $sortOrder);
        $stmt->execute();
        $id = (int)$stmt->insert_id;
        $stmt->close();
        return $id;
    }
}

if (!function_exists('app_saas_delete_policy_exception_preset')) {
    function app_saas_delete_policy_exception_preset(mysqli $controlConn, string $presetKey): void
    {
        $presetKey = strtolower(trim($presetKey));
        if ($presetKey === '') {
            throw new RuntimeException('Preset key مطلوب.');
        }
        $stmtCheck = $controlConn->prepare("SELECT is_system FROM saas_policy_exception_presets WHERE preset_key = ? LIMIT 1");
        $stmtCheck->bind_param('s', $presetKey);
        $stmtCheck->execute();
        $row = $stmtCheck->get_result()->fetch_assoc();
        $stmtCheck->close();
        if (!$row) {
            throw new RuntimeException(app_tr('قالب الاستثناء غير موجود.', 'Exception preset was not found.'));
        }
        if ((int)($row['is_system'] ?? 0) === 1) {
            throw new RuntimeException(app_tr('لا يمكن حذف قالب استثناء نظامي.', 'A system exception preset cannot be deleted.'));
        }
        $stmtDelete = $controlConn->prepare("DELETE FROM saas_policy_exception_presets WHERE preset_key = ? LIMIT 1");
        $stmtDelete->bind_param('s', $presetKey);
        $stmtDelete->execute();
        $stmtDelete->close();
    }
}

if (!function_exists('app_saas_tenant_policy_overrides')) {
    function app_saas_tenant_policy_overrides(array $tenant): array
    {
        $raw = trim((string)($tenant['policy_overrides_json'] ?? ''));
        if ($raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }
}

if (!function_exists('app_saas_normalize_policy_overrides')) {
    function app_saas_normalize_policy_overrides(array $input): array
    {
        $normalized = [];
        $allowedStatuses = ['provisioning', 'active', 'suspended', 'archived'];

        $tenantStatus = strtolower(trim((string)($input['tenant_status'] ?? '')));
        if ($tenantStatus !== '' && in_array($tenantStatus, $allowedStatuses, true)) {
            $normalized['tenant_status'] = $tenantStatus;
        }

        $timezone = trim((string)($input['timezone'] ?? ''));
        if ($timezone !== '') {
            $normalized['timezone'] = $timezone;
        }

        $locale = strtolower(trim((string)($input['locale'] ?? '')));
        if (in_array($locale, ['ar', 'en'], true)) {
            $normalized['locale'] = $locale;
        }

        foreach ([
            'trial_days' => [1, 3650],
            'grace_days' => [0, 3650],
            'ops_keep_latest' => [100, 50000],
            'ops_keep_days' => [1, 3650],
        ] as $key => [$min, $max]) {
            if (!array_key_exists($key, $input) || trim((string)$input[$key]) === '') {
                continue;
            }
            $normalized[$key] = max($min, min($max, (int)$input[$key]));
        }

        return $normalized;
    }
}

if (!function_exists('app_saas_policy_override_labels')) {
    function app_saas_policy_override_labels(): array
    {
        return [
            'tenant_status' => 'الحالة',
            'timezone' => 'المنطقة الزمنية',
            'locale' => 'اللغة',
            'trial_days' => 'أيام التجربة',
            'grace_days' => 'أيام السماح',
            'ops_keep_latest' => 'الاحتفاظ بآخر السجلات',
            'ops_keep_days' => 'حذف السجلات الأقدم',
        ];
    }
}

if (!function_exists('app_saas_policy_override_summary')) {
    function app_saas_policy_override_summary(array $overrides): string
    {
        if (empty($overrides)) {
            return '';
        }
        $labels = app_saas_policy_override_labels();
        $parts = [];
        foreach ($overrides as $key => $value) {
            $label = (string)($labels[$key] ?? $key);
            $parts[] = $label . ': ' . (is_scalar($value) ? (string)$value : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
        return implode(' | ', $parts);
    }
}

if (!function_exists('app_saas_save_tenant_policy_overrides')) {
    function app_saas_save_tenant_policy_overrides(mysqli $controlConn, int $tenantId, array $overrides): array
    {
        $tenantId = max(0, $tenantId);
        if ($tenantId <= 0) {
            throw new RuntimeException('المستأجر غير صالح لحفظ استثناءات السياسة.');
        }
        $normalized = app_saas_normalize_policy_overrides($overrides);
        $json = !empty($normalized)
            ? json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : null;
        $stmt = $controlConn->prepare("UPDATE saas_tenants SET policy_overrides_json = ?, policy_exception_preset = NULL WHERE id = ? LIMIT 1");
        $stmt->bind_param('si', $json, $tenantId);
        $stmt->execute();
        $stmt->close();
        return $normalized;
    }
}

if (!function_exists('app_saas_clear_tenant_policy_overrides')) {
    function app_saas_clear_tenant_policy_overrides(mysqli $controlConn, int $tenantId): void
    {
        $tenantId = max(0, $tenantId);
        if ($tenantId <= 0) {
            throw new RuntimeException('المستأجر غير صالح لمسح استثناءات السياسة.');
        }
        $stmt = $controlConn->prepare("UPDATE saas_tenants SET policy_overrides_json = NULL, policy_exception_preset = NULL WHERE id = ? LIMIT 1");
        $stmt->bind_param('i', $tenantId);
        $stmt->execute();
        $stmt->close();
    }
}

if (!function_exists('app_saas_apply_policy_exception_preset_to_tenant')) {
    function app_saas_apply_policy_exception_preset_to_tenant(mysqli $controlConn, int $tenantId, string $presetKey): array
    {
        $tenantId = max(0, $tenantId);
        if ($tenantId <= 0) {
            throw new RuntimeException(app_tr('المستأجر غير صالح لتطبيق قالب الاستثناء.', 'The tenant is not valid for applying the exception preset.'));
        }
        $preset = app_saas_find_policy_exception_preset($controlConn, $presetKey);
        if (!$preset || empty($preset['is_active'])) {
            throw new RuntimeException(app_tr('قالب الاستثناء غير موجود أو غير نشط.', 'The exception preset does not exist or is inactive.'));
        }

        $overrides = app_saas_normalize_policy_overrides($preset);
        $saved = app_saas_save_tenant_policy_overrides($controlConn, $tenantId, $overrides);
        $normalizedPresetKey = strtolower(trim((string)($preset['preset_key'] ?? $presetKey)));

        $stmtPreset = $controlConn->prepare("UPDATE saas_tenants SET policy_exception_preset = ? WHERE id = ? LIMIT 1");
        $stmtPreset->bind_param('si', $normalizedPresetKey, $tenantId);
        $stmtPreset->execute();
        $stmtPreset->close();

        $stmtTenant = $controlConn->prepare("SELECT policy_pack FROM saas_tenants WHERE id = ? LIMIT 1");
        $stmtTenant->bind_param('i', $tenantId);
        $stmtTenant->execute();
        $tenantRow = $stmtTenant->get_result()->fetch_assoc();
        $stmtTenant->close();
        $packKey = trim((string)($tenantRow['policy_pack'] ?? 'standard'));
        if ($packKey !== '') {
            app_saas_apply_policy_pack_to_tenant($controlConn, $tenantId, $packKey);
        }

        return [
            'tenant_id' => $tenantId,
            'preset_key' => $normalizedPresetKey,
            'policy_pack' => $packKey,
            'overrides' => $saved,
        ];
    }
}

if (!function_exists('app_saas_policy_exception_preset_diff')) {
    function app_saas_policy_exception_preset_diff(array $tenant, array $preset): array
    {
        $current = app_saas_tenant_policy_overrides($tenant);
        $target = app_saas_normalize_policy_overrides($preset);
        $labels = app_saas_policy_override_labels();
        $changes = [];
        foreach ($labels as $field => $label) {
            $currentValue = $current[$field] ?? null;
            $targetValue = $target[$field] ?? null;
            if ((string)$currentValue === (string)$targetValue) {
                continue;
            }
            $changes[$field] = [
                'label' => $label,
                'current' => $currentValue,
                'target' => $targetValue,
            ];
        }
        return [
            'preset_key' => (string)($preset['preset_key'] ?? ''),
            'changes' => $changes,
            'changed_count' => count($changes),
            'is_same' => empty($changes),
        ];
    }
}

if (!function_exists('app_saas_bulk_reapply_policy_exception_preset')) {
    function app_saas_bulk_reapply_policy_exception_preset(mysqli $controlConn, string $presetKey): array
    {
        $preset = app_saas_find_policy_exception_preset($controlConn, $presetKey);
        if (!$preset || empty($preset['is_active'])) {
            throw new RuntimeException(app_tr('قالب الاستثناء غير موجود أو غير نشط.', 'The exception preset does not exist or is inactive.'));
        }
        $normalizedPresetKey = (string)($preset['preset_key'] ?? $presetKey);
        $stmtTenants = $controlConn->prepare("SELECT id FROM saas_tenants WHERE policy_exception_preset = ?");
        $stmtTenants->bind_param('s', $normalizedPresetKey);
        $stmtTenants->execute();
        $result = $stmtTenants->get_result();
        $tenantIds = [];
        while ($row = $result->fetch_assoc()) {
            $tenantIds[] = (int)($row['id'] ?? 0);
        }
        $stmtTenants->close();

        $updated = 0;
        foreach ($tenantIds as $tenantId) {
            if ($tenantId <= 0) {
                continue;
            }
            app_saas_apply_policy_exception_preset_to_tenant($controlConn, $tenantId, $normalizedPresetKey);
            $updated++;
        }

        return [
            'preset_key' => $normalizedPresetKey,
            'updated' => $updated,
        ];
    }
}

if (!function_exists('app_saas_resolve_policy_pack_target')) {
    function app_saas_resolve_policy_pack_target(array $tenant, ?array $subscription, array $pack): array
    {
        $overrides = app_saas_tenant_policy_overrides($tenant);

        return [
            'tenant_status' => (string)($overrides['tenant_status'] ?? $pack['tenant_status'] ?? 'active'),
            'timezone' => (string)($overrides['timezone'] ?? $pack['timezone'] ?? 'Africa/Cairo'),
            'locale' => (string)($overrides['locale'] ?? $pack['locale'] ?? 'ar'),
            'pack_key' => (string)($pack['pack_key'] ?? 'standard'),
            'trial_days' => (int)($overrides['trial_days'] ?? $pack['trial_days'] ?? ($subscription['trial_days'] ?? 14)),
            'grace_days' => (int)($overrides['grace_days'] ?? $pack['grace_days'] ?? ($subscription['grace_days'] ?? 7)),
            'ops_keep_latest' => (int)($overrides['ops_keep_latest'] ?? $pack['ops_keep_latest'] ?? 500),
            'ops_keep_days' => (int)($overrides['ops_keep_days'] ?? $pack['ops_keep_days'] ?? 30),
            'overrides' => $overrides,
        ];
    }
}

if (!function_exists('app_saas_policy_pack_diff')) {
    function app_saas_policy_pack_diff(array $tenant, ?array $subscription, array $pack): array
    {
        $fieldLabels = [
            'status' => 'حالة المستأجر',
            'timezone' => 'المنطقة الزمنية',
            'locale' => 'اللغة',
            'policy_pack' => app_tr('حزمة السياسات', 'Policy Pack'),
            'trial_days' => 'أيام التجربة',
            'grace_days' => 'أيام السماح',
            'ops_keep_latest' => 'حد السجلات المحفوظة',
            'ops_keep_days' => 'عمر السجلات بالأيام',
        ];

        $current = [
            'status' => trim((string)($tenant['status'] ?? 'provisioning')),
            'timezone' => trim((string)($tenant['timezone'] ?? 'Africa/Cairo')),
            'locale' => trim((string)($tenant['locale'] ?? 'ar')),
            'policy_pack' => trim((string)($tenant['policy_pack'] ?? 'standard')),
            'trial_days' => (int)($subscription['trial_days'] ?? 14),
            'grace_days' => (int)($subscription['grace_days'] ?? 7),
            'ops_keep_latest' => (int)($tenant['ops_keep_latest'] ?? 500),
            'ops_keep_days' => (int)($tenant['ops_keep_days'] ?? 30),
        ];
        $effective = function_exists('app_saas_resolve_policy_pack_target')
            ? app_saas_resolve_policy_pack_target($tenant, $subscription, $pack)
            : [];
        $target = [
            'status' => trim((string)($effective['tenant_status'] ?? $pack['tenant_status'] ?? 'active')),
            'timezone' => trim((string)($effective['timezone'] ?? $pack['timezone'] ?? 'Africa/Cairo')),
            'locale' => trim((string)($effective['locale'] ?? $pack['locale'] ?? 'ar')),
            'policy_pack' => trim((string)($effective['pack_key'] ?? $pack['pack_key'] ?? 'standard')),
            'trial_days' => (int)($effective['trial_days'] ?? $pack['trial_days'] ?? 14),
            'grace_days' => (int)($effective['grace_days'] ?? $pack['grace_days'] ?? 7),
            'ops_keep_latest' => (int)($effective['ops_keep_latest'] ?? $pack['ops_keep_latest'] ?? 500),
            'ops_keep_days' => (int)($effective['ops_keep_days'] ?? $pack['ops_keep_days'] ?? 30),
        ];

        $changes = [];
        foreach ($fieldLabels as $field => $label) {
            if ((string)$current[$field] === (string)$target[$field]) {
                continue;
            }
            $changes[$field] = [
                'label' => $label,
                'current' => $current[$field],
                'target' => $target[$field],
            ];
        }

        return [
            'pack_key' => (string)($pack['pack_key'] ?? ''),
            'overrides' => (array)($effective['overrides'] ?? []),
            'changes' => $changes,
            'changed_fields' => array_keys($changes),
            'changed_count' => count($changes),
            'is_same' => empty($changes),
        ];
    }
}

if (!function_exists('app_saas_apply_policy_pack_to_tenant')) {
    function app_saas_apply_policy_pack_to_tenant(mysqli $controlConn, int $tenantId, string $packKey): array
    {
        $tenantId = max(0, $tenantId);
        if ($tenantId <= 0) {
            throw new RuntimeException(app_tr('المستأجر غير صالح لتطبيق حزمة السياسات.', 'The tenant is not valid for applying the policy pack.'));
        }
        $pack = app_saas_find_policy_pack($controlConn, $packKey);
        if (!$pack || empty($pack['is_active'])) {
            throw new RuntimeException(app_tr('حزمة السياسات غير موجودة أو غير نشطة.', 'The policy pack does not exist or is inactive.'));
        }

        $stmtTenantCurrent = $controlConn->prepare("SELECT * FROM saas_tenants WHERE id = ? LIMIT 1");
        $stmtTenantCurrent->bind_param('i', $tenantId);
        $stmtTenantCurrent->execute();
        $tenantRow = $stmtTenantCurrent->get_result()->fetch_assoc();
        $stmtTenantCurrent->close();
        $effective = function_exists('app_saas_resolve_policy_pack_target')
            ? app_saas_resolve_policy_pack_target((array)$tenantRow, null, $pack)
            : [];
        $tenantStatus = (string)($effective['tenant_status'] ?? $pack['tenant_status'] ?? 'active');
        $timezone = (string)($effective['timezone'] ?? $pack['timezone'] ?? 'Africa/Cairo');
        $locale = (string)($effective['locale'] ?? $pack['locale'] ?? 'ar');
        $trialDays = (int)($effective['trial_days'] ?? $pack['trial_days'] ?? 14);
        $graceDays = (int)($effective['grace_days'] ?? $pack['grace_days'] ?? 7);
        $opsKeepLatest = (int)($effective['ops_keep_latest'] ?? $pack['ops_keep_latest'] ?? 500);
        $opsKeepDays = (int)($effective['ops_keep_days'] ?? $pack['ops_keep_days'] ?? 30);
        $normalizedPackKey = (string)($pack['pack_key'] ?? $packKey);

        $stmtTenant = $controlConn->prepare("
            UPDATE saas_tenants
            SET status = ?, policy_pack = ?, timezone = ?, locale = ?, ops_keep_latest = ?, ops_keep_days = ?
            WHERE id = ?
            LIMIT 1
        ");
        $stmtTenant->bind_param('ssssiii', $tenantStatus, $normalizedPackKey, $timezone, $locale, $opsKeepLatest, $opsKeepDays, $tenantId);
        $stmtTenant->execute();
        $stmtTenant->close();

        $stmtSubs = $controlConn->prepare("
            UPDATE saas_subscriptions
            SET trial_days = ?, grace_days = ?
            WHERE tenant_id = ? AND status <> 'cancelled'
        ");
        $stmtSubs->bind_param('iii', $trialDays, $graceDays, $tenantId);
        $stmtSubs->execute();
        $subscriptionsUpdated = $stmtSubs->affected_rows;
        $stmtSubs->close();

        saas_recalculate_tenant_subscriptions($controlConn, $tenantId);
        saas_apply_overdue_policy_for_tenant($controlConn, $tenantId);

        return [
            'tenant_id' => $tenantId,
            'pack_key' => $normalizedPackKey,
            'tenant_status' => $tenantStatus,
            'timezone' => $timezone,
            'locale' => $locale,
            'trial_days' => $trialDays,
            'grace_days' => $graceDays,
            'ops_keep_latest' => $opsKeepLatest,
            'ops_keep_days' => $opsKeepDays,
            'policy_overrides' => (array)($effective['overrides'] ?? []),
            'subscriptions_updated' => max(0, (int)$subscriptionsUpdated),
        ];
    }
}

if (!function_exists('app_saas_bulk_reapply_policy_pack')) {
    function app_saas_bulk_reapply_policy_pack(mysqli $controlConn, string $packKey): array
    {
        $pack = app_saas_find_policy_pack($controlConn, $packKey);
        if (!$pack || empty($pack['is_active'])) {
            throw new RuntimeException(app_tr('حزمة السياسات غير موجودة أو غير نشطة.', 'The policy pack does not exist or is inactive.'));
        }
        $normalizedPackKey = (string)($pack['pack_key'] ?? $packKey);
        $stmtTenants = $controlConn->prepare("SELECT id FROM saas_tenants WHERE policy_pack = ?");
        $stmtTenants->bind_param('s', $normalizedPackKey);
        $stmtTenants->execute();
        $result = $stmtTenants->get_result();
        $tenantIds = [];
        while ($row = $result->fetch_assoc()) {
            $tenantIds[] = (int)($row['id'] ?? 0);
        }
        $stmtTenants->close();

        $updated = 0;
        foreach ($tenantIds as $tenantId) {
            if ($tenantId <= 0) {
                continue;
            }
            app_saas_apply_policy_pack_to_tenant($controlConn, $tenantId, $normalizedPackKey);
            $updated++;
        }

        return [
            'pack_key' => $normalizedPackKey,
            'updated' => $updated,
        ];
    }
}

if (!function_exists('app_saas_apply_provision_profile_to_tenant')) {
    function app_saas_apply_provision_profile_to_tenant(mysqli $controlConn, int $tenantId, string $profileKey): array
    {
        $tenantId = max(0, $tenantId);
        if ($tenantId <= 0) {
            throw new RuntimeException(app_tr('المستأجر غير صالح لتطبيق بروفايل التهيئة.', 'The tenant is not valid for applying the provision profile.'));
        }
        $profile = app_saas_find_provision_profile($controlConn, $profileKey);
        if (!$profile || empty($profile['is_active'])) {
            throw new RuntimeException(app_tr('بروفايل التهيئة غير موجود أو غير نشط.', 'The provision profile does not exist or is inactive.'));
        }

        $stmt = $controlConn->prepare("
            UPDATE saas_tenants
            SET
                plan_code = ?,
                provision_profile = ?,
                timezone = ?,
                locale = ?,
                users_limit = ?,
                storage_limit_mb = ?
            WHERE id = ?
            LIMIT 1
        ");
        $planCode = (string)($profile['plan_code'] ?? 'basic');
        $profileKey = (string)($profile['profile_key'] ?? $profileKey);
        $timezone = (string)($profile['timezone'] ?? 'Africa/Cairo');
        $locale = (string)($profile['locale'] ?? 'ar');
        $usersLimit = (int)($profile['users_limit'] ?? 0);
        $storageLimit = (int)($profile['storage_limit_mb'] ?? 0);
        $stmt->bind_param('ssssiii', $planCode, $profileKey, $timezone, $locale, $usersLimit, $storageLimit, $tenantId);
        $stmt->execute();
        $stmt->close();

        return [
            'tenant_id' => $tenantId,
            'profile_key' => $profileKey,
            'plan_code' => $planCode,
            'timezone' => $timezone,
            'locale' => $locale,
            'users_limit' => $usersLimit,
            'storage_limit_mb' => $storageLimit,
        ];
    }
}

if (!function_exists('app_saas_provision_profile_diff')) {
    function app_saas_provision_profile_diff(array $tenant, array $profile): array
    {
        $fieldLabels = [
            'plan_code' => 'الخطة',
            'timezone' => 'المنطقة الزمنية',
            'locale' => 'اللغة',
            'users_limit' => 'حد المستخدمين',
            'storage_limit_mb' => 'حد التخزين',
            'provision_profile' => app_tr('بروفايل التهيئة', 'Provision Profile'),
        ];

        $current = [
            'plan_code' => trim((string)($tenant['plan_code'] ?? 'basic')),
            'timezone' => trim((string)($tenant['timezone'] ?? 'Africa/Cairo')),
            'locale' => trim((string)($tenant['locale'] ?? 'ar')),
            'users_limit' => (int)($tenant['users_limit'] ?? 0),
            'storage_limit_mb' => (int)($tenant['storage_limit_mb'] ?? 0),
            'provision_profile' => trim((string)($tenant['provision_profile'] ?? 'standard')),
        ];

        $target = [
            'plan_code' => trim((string)($profile['plan_code'] ?? 'basic')),
            'timezone' => trim((string)($profile['timezone'] ?? 'Africa/Cairo')),
            'locale' => trim((string)($profile['locale'] ?? 'ar')),
            'users_limit' => (int)($profile['users_limit'] ?? 0),
            'storage_limit_mb' => (int)($profile['storage_limit_mb'] ?? 0),
            'provision_profile' => trim((string)($profile['profile_key'] ?? ($profile['provision_profile'] ?? 'standard'))),
        ];

        $changes = [];
        foreach ($fieldLabels as $field => $label) {
            if ((string)$current[$field] === (string)$target[$field]) {
                continue;
            }
            $changes[$field] = [
                'label' => $label,
                'current' => $current[$field],
                'target' => $target[$field],
            ];
        }

        return [
            'profile_key' => (string)($profile['profile_key'] ?? ''),
            'changes' => $changes,
            'changed_fields' => array_keys($changes),
            'changed_count' => count($changes),
            'is_same' => empty($changes),
        ];
    }
}

if (!function_exists('app_saas_bulk_reapply_provision_profile')) {
    function app_saas_bulk_reapply_provision_profile(mysqli $controlConn, string $profileKey): array
    {
        $profile = app_saas_find_provision_profile($controlConn, $profileKey);
        if (!$profile || empty($profile['is_active'])) {
            throw new RuntimeException(app_tr('بروفايل التهيئة غير موجود أو غير نشط.', 'The provision profile does not exist or is inactive.'));
        }

        $stmtTenants = $controlConn->prepare("SELECT id FROM saas_tenants WHERE provision_profile = ?");
        $stmtTenants->bind_param('s', $profileKey);
        $stmtTenants->execute();
        $result = $stmtTenants->get_result();
        $tenantIds = [];
        while ($row = $result->fetch_assoc()) {
            $tenantIds[] = (int)($row['id'] ?? 0);
        }
        $stmtTenants->close();

        $updated = 0;
        foreach ($tenantIds as $tenantId) {
            if ($tenantId <= 0) {
                continue;
            }
            app_saas_apply_provision_profile_to_tenant($controlConn, $tenantId, $profileKey);
            $updated++;
        }

        return [
            'profile_key' => (string)($profile['profile_key'] ?? $profileKey),
            'updated' => $updated,
        ];
    }
}

if (!function_exists('app_saas_log_operation')) {
    function app_saas_log_operation(mysqli $controlConn, string $actionCode, string $actionLabel, int $tenantId = 0, array $context = [], string $actorName = ''): void
    {
        $actionCode = substr(trim($actionCode), 0, 80);
        $actionLabel = mb_substr(trim($actionLabel), 0, 190);
        if ($actionCode === '') {
            return;
        }
        if ($actorName === '') {
            $actorName = trim((string)($_SESSION['name'] ?? $_SESSION['username'] ?? 'System'));
        }
        $actorName = mb_substr($actorName !== '' ? $actorName : 'System', 0, 190);
        $tenantId = max(0, $tenantId);
        $contextJson = !empty($context)
            ? json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : null;

        if ($tenantId > 0) {
            $stmt = $controlConn->prepare("
                INSERT INTO saas_operation_log (tenant_id, action_code, action_label, actor_name, context_json)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->bind_param('issss', $tenantId, $actionCode, $actionLabel, $actorName, $contextJson);
        } else {
            $stmt = $controlConn->prepare("
                INSERT INTO saas_operation_log (tenant_id, action_code, action_label, actor_name, context_json)
                VALUES (NULL, ?, ?, ?, ?)
            ");
            $stmt->bind_param('ssss', $actionCode, $actionLabel, $actorName, $contextJson);
        }
        $stmt->execute();
        $stmt->close();
    }
}

if (!function_exists('app_saas_recent_operations')) {
    function app_saas_recent_operations(mysqli $controlConn, int $limit = 40): array
    {
        $limit = max(1, min(200, $limit));
        $rows = [];
        $sql = "
            SELECT l.*, t.tenant_slug, t.tenant_name
            FROM saas_operation_log l
            LEFT JOIN saas_tenants t ON t.id = l.tenant_id
            ORDER BY l.id DESC
            LIMIT {$limit}
        ";
        $res = $controlConn->query($sql);
        while ($row = $res->fetch_assoc()) {
            $rows[] = $row;
        }
        return $rows;
    }
}

if (!function_exists('app_saas_recent_webhook_deliveries')) {
    function app_saas_recent_webhook_deliveries(mysqli $controlConn, int $limit = 40): array
    {
        $limit = max(1, min(300, $limit));
        $rows = [];
        $sql = "
            SELECT d.*,
                   t.tenant_slug,
                   t.tenant_name
            FROM saas_webhook_deliveries d
            LEFT JOIN saas_tenants t ON t.id = d.tenant_id
            ORDER BY d.id DESC
            LIMIT ?
        ";
        $stmt = $controlConn->prepare($sql);
        $stmt->bind_param('i', $limit);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $rows[] = $row;
        }
        $stmt->close();
        return $rows;
    }
}

if (!function_exists('app_saas_webhook_test_receiver_url')) {
    function app_saas_webhook_test_receiver_url(): string
    {
        return rtrim(app_base_url(), '/') . '/saas_webhook_test_receiver.php';
    }
}

if (!function_exists('app_saas_store_webhook_test_inbox')) {
    function app_saas_store_webhook_test_inbox(mysqli $controlConn, array $headers, array $payload, string $rawBody = ''): int
    {
        $sourceIp = mb_substr(trim((string)($_SERVER['REMOTE_ADDR'] ?? '')), 0, 80);
        $requestMethod = mb_substr(strtoupper(trim((string)($_SERVER['REQUEST_METHOD'] ?? 'POST'))), 0, 12);
        $queryString = mb_substr(trim((string)($_SERVER['QUERY_STRING'] ?? '')), 0, 255);
        $headersJson = json_encode($headers, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $rawBody = (string)$rawBody;

        $stmt = $controlConn->prepare("
            INSERT INTO saas_webhook_test_inbox
                (source_ip, request_method, query_string, headers_json, payload_json, raw_body)
            VALUES
                (?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param('ssssss', $sourceIp, $requestMethod, $queryString, $headersJson, $payloadJson, $rawBody);
        $stmt->execute();
        $id = (int)$stmt->insert_id;
        $stmt->close();
        return $id;
    }
}

if (!function_exists('app_saas_recent_webhook_test_inbox')) {
    function app_saas_recent_webhook_test_inbox(mysqli $controlConn, int $limit = 30): array
    {
        $limit = max(1, min(200, $limit));
        $rows = [];
        $stmt = $controlConn->prepare("
            SELECT *
            FROM saas_webhook_test_inbox
            ORDER BY id DESC
            LIMIT ?
        ");
        $stmt->bind_param('i', $limit);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $rows[] = $row;
        }
        $stmt->close();
        return $rows;
    }
}

if (!function_exists('saas_webhook_retry_delay_seconds')) {
    function saas_webhook_retry_delay_seconds(int $attemptCount): int
    {
        $schedule = [
            1 => 300,
            2 => 900,
            3 => 3600,
            4 => 21600,
            5 => 86400,
        ];
        return $schedule[$attemptCount] ?? 86400;
    }
}

if (!function_exists('saas_webhook_due_retry_at')) {
    function saas_webhook_due_retry_at(int $attemptCount): string
    {
        return date('Y-m-d H:i:s', time() + saas_webhook_retry_delay_seconds($attemptCount));
    }
}

if (!function_exists('app_saas_cleanup_operation_log')) {
    function app_saas_cleanup_operation_log(mysqli $controlConn, int $keepLatest = 1000, int $olderThanDays = 90): array
    {
        $keepLatest = max(100, min(50000, $keepLatest));
        $olderThanDays = max(1, min(3650, $olderThanDays));
        $deleted = 0;

        $cutoffDate = date('Y-m-d H:i:s', strtotime('-' . $olderThanDays . ' days'));
        $keepThresholdId = 0;
        $offset = max(0, $keepLatest - 1);
        $thresholdSql = "
            SELECT id
            FROM saas_operation_log
            ORDER BY id DESC
            LIMIT {$offset}, 1
        ";
        $thresholdRes = $controlConn->query($thresholdSql);
        if ($thresholdRes && ($thresholdRow = $thresholdRes->fetch_assoc())) {
            $keepThresholdId = (int)($thresholdRow['id'] ?? 0);
        }

        if ($keepThresholdId > 0) {
            $stmt = $controlConn->prepare("
                DELETE FROM saas_operation_log
                WHERE id < ?
                  AND created_at < ?
            ");
            $stmt->bind_param('is', $keepThresholdId, $cutoffDate);
        } else {
            $stmt = $controlConn->prepare("
                DELETE FROM saas_operation_log
                WHERE created_at < ?
            ");
            $stmt->bind_param('s', $cutoffDate);
        }
        $stmt->execute();
        $deleted = (int)$stmt->affected_rows;
        $stmt->close();

        return [
            'deleted' => $deleted,
            'keep_latest' => $keepLatest,
            'older_than_days' => $olderThanDays,
            'cutoff_date' => $cutoffDate,
        ];
    }
}

if (!function_exists('app_saas_cleanup_operation_log_for_tenant')) {
    function app_saas_cleanup_operation_log_for_tenant(mysqli $controlConn, int $tenantId, int $keepLatest = 1000, int $olderThanDays = 90): array
    {
        $tenantId = max(0, $tenantId);
        if ($tenantId <= 0) {
            return [
                'tenant_id' => 0,
                'deleted' => 0,
                'keep_latest' => $keepLatest,
                'older_than_days' => $olderThanDays,
            ];
        }

        $keepLatest = max(100, min(50000, $keepLatest));
        $olderThanDays = max(1, min(3650, $olderThanDays));
        $deleted = 0;
        $cutoffDate = date('Y-m-d H:i:s', strtotime('-' . $olderThanDays . ' days'));
        $keepThresholdId = 0;
        $offset = max(0, $keepLatest - 1);

        $stmtThreshold = $controlConn->prepare("
            SELECT id
            FROM saas_operation_log
            WHERE tenant_id = ?
            ORDER BY id DESC
            LIMIT {$offset}, 1
        ");
        $stmtThreshold->bind_param('i', $tenantId);
        $stmtThreshold->execute();
        $thresholdRow = $stmtThreshold->get_result()->fetch_assoc();
        $stmtThreshold->close();
        if ($thresholdRow) {
            $keepThresholdId = (int)($thresholdRow['id'] ?? 0);
        }

        if ($keepThresholdId > 0) {
            $stmt = $controlConn->prepare("
                DELETE FROM saas_operation_log
                WHERE tenant_id = ?
                  AND id < ?
                  AND created_at < ?
            ");
            $stmt->bind_param('iis', $tenantId, $keepThresholdId, $cutoffDate);
        } else {
            $stmt = $controlConn->prepare("
                DELETE FROM saas_operation_log
                WHERE tenant_id = ?
                  AND created_at < ?
            ");
            $stmt->bind_param('is', $tenantId, $cutoffDate);
        }
        $stmt->execute();
        $deleted = (int)$stmt->affected_rows;
        $stmt->close();

        return [
            'tenant_id' => $tenantId,
            'deleted' => $deleted,
            'keep_latest' => $keepLatest,
            'older_than_days' => $olderThanDays,
            'cutoff_date' => $cutoffDate,
        ];
    }
}

if (!function_exists('app_saas_sync_policy_pack_runtime_to_tenant')) {
    function app_saas_sync_policy_pack_runtime_to_tenant(mysqli $controlConn, int $tenantId): array
    {
        $tenantId = max(0, $tenantId);
        if ($tenantId <= 0) {
            return [
                'tenant_id' => 0,
                'pack_key' => '',
                'tenant_updated' => 0,
                'subscriptions_updated' => 0,
            ];
        }

        $stmt = $controlConn->prepare("
            SELECT t.*, p.pack_key, p.timezone AS pack_timezone, p.locale AS pack_locale,
                   p.trial_days AS pack_trial_days, p.grace_days AS pack_grace_days,
                   p.ops_keep_latest AS pack_ops_keep_latest, p.ops_keep_days AS pack_ops_keep_days
            FROM saas_tenants t
            LEFT JOIN saas_policy_packs p ON p.pack_key = t.policy_pack AND p.is_active = 1
            WHERE t.id = ?
            LIMIT 1
        ");
        $stmt->bind_param('i', $tenantId);
        $stmt->execute();
        $tenantRow = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$tenantRow || trim((string)($tenantRow['pack_key'] ?? '')) === '') {
            return [
                'tenant_id' => $tenantId,
                'pack_key' => '',
                'tenant_updated' => 0,
                'subscriptions_updated' => 0,
            ];
        }

        $packKey = trim((string)($tenantRow['pack_key'] ?? ''));
        $effective = function_exists('app_saas_resolve_policy_pack_target')
            ? app_saas_resolve_policy_pack_target($tenantRow, null, [
                'pack_key' => $packKey,
                'tenant_status' => (string)($tenantRow['tenant_status'] ?? 'active'),
                'timezone' => (string)($tenantRow['pack_timezone'] ?? $tenantRow['timezone'] ?? 'Africa/Cairo'),
                'locale' => (string)($tenantRow['pack_locale'] ?? $tenantRow['locale'] ?? 'ar'),
                'trial_days' => (int)($tenantRow['pack_trial_days'] ?? 14),
                'grace_days' => (int)($tenantRow['pack_grace_days'] ?? 7),
                'ops_keep_latest' => (int)($tenantRow['pack_ops_keep_latest'] ?? 500),
                'ops_keep_days' => (int)($tenantRow['pack_ops_keep_days'] ?? 30),
            ])
            : [];
        $timezone = trim((string)($effective['timezone'] ?? $tenantRow['pack_timezone'] ?? $tenantRow['timezone'] ?? 'Africa/Cairo'));
        $locale = trim((string)($effective['locale'] ?? $tenantRow['pack_locale'] ?? $tenantRow['locale'] ?? 'ar'));
        $opsKeepLatest = max(100, (int)($effective['ops_keep_latest'] ?? $tenantRow['pack_ops_keep_latest'] ?? $tenantRow['ops_keep_latest'] ?? 500));
        $opsKeepDays = max(1, (int)($effective['ops_keep_days'] ?? $tenantRow['pack_ops_keep_days'] ?? $tenantRow['ops_keep_days'] ?? 30));
        $trialDays = max(1, (int)($effective['trial_days'] ?? $tenantRow['pack_trial_days'] ?? 14));
        $graceDays = max(0, (int)($effective['grace_days'] ?? $tenantRow['pack_grace_days'] ?? 7));

        $stmtTenant = $controlConn->prepare("
            UPDATE saas_tenants
            SET timezone = ?, locale = ?, ops_keep_latest = ?, ops_keep_days = ?
            WHERE id = ?
              AND (
                timezone <> ?
                OR locale <> ?
                OR ops_keep_latest <> ?
                OR ops_keep_days <> ?
              )
            LIMIT 1
        ");
        $stmtTenant->bind_param('ssiiissii', $timezone, $locale, $opsKeepLatest, $opsKeepDays, $tenantId, $timezone, $locale, $opsKeepLatest, $opsKeepDays);
        $stmtTenant->execute();
        $tenantUpdated = (int)$stmtTenant->affected_rows;
        $stmtTenant->close();

        $stmtSubs = $controlConn->prepare("
            UPDATE saas_subscriptions
            SET trial_days = ?, grace_days = ?
            WHERE tenant_id = ?
              AND status <> 'cancelled'
              AND (trial_days <> ? OR grace_days <> ?)
        ");
        $stmtSubs->bind_param('iiiii', $trialDays, $graceDays, $tenantId, $trialDays, $graceDays);
        $stmtSubs->execute();
        $subscriptionsUpdated = (int)$stmtSubs->affected_rows;
        $stmtSubs->close();

        return [
            'tenant_id' => $tenantId,
            'pack_key' => $packKey,
            'tenant_updated' => $tenantUpdated,
            'subscriptions_updated' => $subscriptionsUpdated,
            'ops_keep_latest' => $opsKeepLatest,
            'ops_keep_days' => $opsKeepDays,
            'trial_days' => $trialDays,
            'grace_days' => $graceDays,
            'policy_overrides' => (array)($effective['overrides'] ?? []),
        ];
    }
}

if (!function_exists('app_saas_cleanup_operation_log_with_policies')) {
    function app_saas_cleanup_operation_log_with_policies(mysqli $controlConn, int $defaultKeepLatest = 1000, int $defaultOlderThanDays = 90): array
    {
        $defaultKeepLatest = max(100, min(50000, $defaultKeepLatest));
        $defaultOlderThanDays = max(1, min(3650, $defaultOlderThanDays));
        $tenantRuns = 0;
        $tenantDeleted = 0;

        $res = $controlConn->query("SELECT id, ops_keep_latest, ops_keep_days FROM saas_tenants ORDER BY id ASC");
        while ($row = $res->fetch_assoc()) {
            $tenantId = (int)($row['id'] ?? 0);
            if ($tenantId <= 0) {
                continue;
            }
            $cleanup = app_saas_cleanup_operation_log_for_tenant(
                $controlConn,
                $tenantId,
                max(100, (int)($row['ops_keep_latest'] ?? $defaultKeepLatest)),
                max(1, (int)($row['ops_keep_days'] ?? $defaultOlderThanDays))
            );
            $tenantRuns++;
            $tenantDeleted += (int)($cleanup['deleted'] ?? 0);
        }
        if ($res instanceof mysqli_result) {
            $res->close();
        }

        $globalCleanup = app_saas_cleanup_operation_log($controlConn, $defaultKeepLatest, $defaultOlderThanDays);

        return [
            'deleted' => $tenantDeleted + (int)($globalCleanup['deleted'] ?? 0),
            'tenant_deleted' => $tenantDeleted,
            'tenant_runs' => $tenantRuns,
            'global_deleted' => (int)($globalCleanup['deleted'] ?? 0),
            'keep_latest' => $defaultKeepLatest,
            'older_than_days' => $defaultOlderThanDays,
        ];
    }
}

if (!function_exists('saas_dt_db')) {
    function saas_dt_db(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        $value = str_replace('T', ' ', $value);
        if (preg_match('/^\d{4}-\d{2}-\d{2}\s\d{2}:\d{2}$/', $value)) {
            $value .= ':00';
        }
        return strtotime($value) === false ? null : $value;
    }
}

if (!function_exists('saas_cycle_interval_spec')) {
    function saas_cycle_interval_spec(string $billingCycle, int $cyclesCount): string
    {
        $cyclesCount = max(1, $cyclesCount);
        switch ($billingCycle) {
            case 'yearly':
                return 'P' . $cyclesCount . 'Y';
            case 'quarterly':
                return 'P' . ($cyclesCount * 3) . 'M';
            case 'manual':
                return 'P' . $cyclesCount . 'D';
            case 'monthly':
            default:
                return 'P' . $cyclesCount . 'M';
        }
    }
}

if (!function_exists('saas_normalize_subscription_cycle')) {
    function saas_normalize_subscription_cycle(string $billingCycle): string
    {
        $billingCycle = strtolower(trim($billingCycle));
        if (!in_array($billingCycle, ['monthly', 'quarterly', 'yearly', 'manual'], true)) {
            $billingCycle = 'monthly';
        }
        return $billingCycle;
    }
}

if (!function_exists('saas_add_interval')) {
    function saas_add_interval(?string $startAt, string $billingCycle, int $cyclesCount): ?string
    {
        $startAt = trim((string)$startAt);
        if ($startAt === '') {
            return null;
        }
        try {
            $dt = new DateTime($startAt);
            $dt->add(new DateInterval(saas_cycle_interval_spec($billingCycle, $cyclesCount)));
            return $dt->format('Y-m-d H:i:s');
        } catch (Throwable $e) {
            return null;
        }
    }
}

if (!function_exists('saas_subscription_recalculate')) {
    function saas_subscription_recalculate(array $subscription): array
    {
        $status = strtolower(trim((string)($subscription['status'] ?? 'trial')));
        if (!in_array($status, ['trial', 'active', 'past_due', 'suspended', 'cancelled'], true)) {
            $status = 'trial';
        }

        $billingCycle = saas_normalize_subscription_cycle((string)($subscription['billing_cycle'] ?? 'monthly'));
        $cyclesCount = max(1, (int)($subscription['cycles_count'] ?? 1));
        $trialDays = max(1, (int)($subscription['trial_days'] ?? 14));
        $graceDays = max(0, (int)($subscription['grace_days'] ?? 7));
        $startsAt = saas_dt_db((string)($subscription['starts_at'] ?? ''));
        if ($startsAt === null) {
            $startsAt = date('Y-m-d H:i:s');
        }

        $recalculated = [
            'billing_cycle' => $billingCycle,
            'status' => $status,
            'starts_at' => $startsAt,
            'cycles_count' => $cyclesCount,
            'trial_days' => $trialDays,
            'grace_days' => $graceDays,
            'renews_at' => null,
            'ends_at' => null,
        ];

        if ($status === 'trial') {
            $recalculated['billing_cycle'] = 'manual';
            $recalculated['ends_at'] = saas_add_interval($startsAt, 'manual', $trialDays);
            return $recalculated;
        }

        $recalculated['renews_at'] = saas_add_interval($startsAt, $billingCycle, $cyclesCount);
        $recalculated['ends_at'] = $recalculated['renews_at'];

        if (in_array($status, ['active', 'past_due'], true) && $recalculated['ends_at'] !== null) {
            $recalculated['status'] = (strtotime($recalculated['ends_at']) !== false && strtotime($recalculated['ends_at']) < time())
                ? 'past_due'
                : 'active';
        }

        return $recalculated;
    }
}

if (!function_exists('saas_refresh_current_subscription')) {
    function saas_refresh_current_subscription(mysqli $controlConn, int $tenantId): void
    {
        if ($tenantId <= 0) {
            return;
        }
        $nextSubscriptionId = null;
        $stmtNext = $controlConn->prepare("
            SELECT id
            FROM saas_subscriptions
            WHERE tenant_id = ?
            ORDER BY
                CASE
                    WHEN status = 'active' THEN 1
                    WHEN status = 'trial' THEN 2
                    WHEN status = 'past_due' THEN 3
                    WHEN status = 'suspended' THEN 4
                    ELSE 5
                END,
                id DESC
            LIMIT 1
        ");
        $stmtNext->bind_param('i', $tenantId);
        $stmtNext->execute();
        $nextRow = $stmtNext->get_result()->fetch_assoc();
        $stmtNext->close();
        if ($nextRow) {
            $nextSubscriptionId = (int)($nextRow['id'] ?? 0);
        }

        if ($nextSubscriptionId > 0) {
            $stmtUpdate = $controlConn->prepare("UPDATE saas_tenants SET current_subscription_id = ? WHERE id = ? LIMIT 1");
            $stmtUpdate->bind_param('ii', $nextSubscriptionId, $tenantId);
        } else {
            $stmtUpdate = $controlConn->prepare("UPDATE saas_tenants SET current_subscription_id = NULL WHERE id = ? LIMIT 1");
            $stmtUpdate->bind_param('i', $tenantId);
        }
        $stmtUpdate->execute();
        $stmtUpdate->close();
    }
}

if (!function_exists('saas_sync_tenant_subscription_snapshot')) {
    function saas_sync_tenant_subscription_snapshot(mysqli $controlConn, int $tenantId): void
    {
        if ($tenantId <= 0) {
            return;
        }

        $stmt = $controlConn->prepare("
            SELECT t.current_subscription_id, s.status, s.plan_code, s.ends_at
            FROM saas_tenants t
            LEFT JOIN saas_subscriptions s ON s.id = t.current_subscription_id
            WHERE t.id = ?
            LIMIT 1
        ");
        $stmt->bind_param('i', $tenantId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $subscribedUntil = null;
        $trialEndsAt = null;
        $planCode = null;
        if ($row && (int)($row['current_subscription_id'] ?? 0) > 0) {
            $planCode = trim((string)($row['plan_code'] ?? ''));
            $endsAt = saas_dt_db((string)($row['ends_at'] ?? ''));
            $status = strtolower(trim((string)($row['status'] ?? '')));
            if ($status === 'trial') {
                $trialEndsAt = $endsAt;
                $subscribedUntil = $endsAt;
            } elseif ($status !== 'cancelled') {
                $subscribedUntil = $endsAt;
            }
        }

        if ($planCode !== null && $planCode !== '') {
            $stmtUpdate = $controlConn->prepare("
                UPDATE saas_tenants
                SET plan_code = ?, subscribed_until = ?, trial_ends_at = ?
                WHERE id = ?
                LIMIT 1
            ");
            $stmtUpdate->bind_param('sssi', $planCode, $subscribedUntil, $trialEndsAt, $tenantId);
        } else {
            $stmtUpdate = $controlConn->prepare("
                UPDATE saas_tenants
                SET subscribed_until = ?, trial_ends_at = ?
                WHERE id = ?
                LIMIT 1
            ");
            $stmtUpdate->bind_param('ssi', $subscribedUntil, $trialEndsAt, $tenantId);
        }
        $stmtUpdate->execute();
        $stmtUpdate->close();
    }
}

if (!function_exists('saas_recalculate_tenant_subscriptions')) {
    function saas_recalculate_tenant_subscriptions(mysqli $controlConn, int $tenantId): int
    {
        if ($tenantId <= 0) {
            return 0;
        }

        $count = 0;
        $stmtSelect = $controlConn->prepare("SELECT * FROM saas_subscriptions WHERE tenant_id = ? ORDER BY id ASC");
        $stmtSelect->bind_param('i', $tenantId);
        $stmtSelect->execute();
        $result = $stmtSelect->get_result();
        $stmtUpdate = $controlConn->prepare("
            UPDATE saas_subscriptions
            SET billing_cycle = ?, status = ?, starts_at = ?, cycles_count = ?, trial_days = ?, grace_days = ?, renews_at = ?, ends_at = ?
            WHERE id = ? AND tenant_id = ?
            LIMIT 1
        ");

        while ($row = $result->fetch_assoc()) {
            $recalculated = saas_subscription_recalculate($row);
            $subscriptionId = (int)($row['id'] ?? 0);
            $stmtUpdate->bind_param(
                'sssiiissii',
                $recalculated['billing_cycle'],
                $recalculated['status'],
                $recalculated['starts_at'],
                $recalculated['cycles_count'],
                $recalculated['trial_days'],
                $recalculated['grace_days'],
                $recalculated['renews_at'],
                $recalculated['ends_at'],
                $subscriptionId,
                $tenantId
            );
            $stmtUpdate->execute();
            $count++;
        }

        $stmtUpdate->close();
        $stmtSelect->close();

        saas_refresh_current_subscription($controlConn, $tenantId);
        saas_sync_tenant_subscription_snapshot($controlConn, $tenantId);

        return $count;
    }
}

if (!function_exists('saas_subscription_invoice_number')) {
    function saas_subscription_invoice_number(int $invoiceId): string
    {
        return 'SINV-' . date('Ymd') . '-' . str_pad((string)max(1, $invoiceId), 5, '0', STR_PAD_LEFT);
    }
}

if (!function_exists('saas_generate_subscription_invoice')) {
    function saas_generate_subscription_invoice(mysqli $controlConn, array $subscription, string $createdBy = 'System'): array
    {
        $subscriptionId = (int)($subscription['id'] ?? 0);
        $tenantId = (int)($subscription['tenant_id'] ?? 0);
        if ($subscriptionId <= 0 || $tenantId <= 0) {
            return ['ok' => false, 'reason' => 'invalid_subscription', 'invoice_id' => 0, 'already_exists' => false];
        }

        $recalculated = saas_subscription_recalculate($subscription);
        $status = strtolower(trim((string)($recalculated['status'] ?? 'trial')));
        if ($status === 'trial') {
            return ['ok' => false, 'reason' => 'trial_subscription', 'invoice_id' => 0, 'already_exists' => false];
        }

        $periodStart = saas_dt_db((string)($recalculated['starts_at'] ?? ''));
        $periodEnd = saas_dt_db((string)($recalculated['ends_at'] ?? ''));
        if ($periodStart === null || $periodEnd === null) {
            return ['ok' => false, 'reason' => 'missing_period', 'invoice_id' => 0, 'already_exists' => false];
        }

        $stmtExisting = $controlConn->prepare("
            SELECT id
            FROM saas_subscription_invoices
            WHERE subscription_id = ? AND period_start = ? AND period_end = ?
            LIMIT 1
        ");
        $stmtExisting->bind_param('iss', $subscriptionId, $periodStart, $periodEnd);
        $stmtExisting->execute();
        $existing = $stmtExisting->get_result()->fetch_assoc();
        $stmtExisting->close();
        if ($existing) {
            return ['ok' => true, 'reason' => '', 'invoice_id' => (int)$existing['id'], 'already_exists' => true];
        }

        $invoiceDate = date('Y-m-d H:i:s');
        $amount = round((float)($subscription['amount'] ?? 0), 2);
        $currencyCode = strtoupper(trim((string)($subscription['currency_code'] ?? 'EGP')));
        if ($currencyCode === '') {
            $currencyCode = 'EGP';
        }
        $notes = 'فاتورة اشتراك SaaS للدورة من ' . $periodStart . ' إلى ' . $periodEnd;

        $stmtInsert = $controlConn->prepare("
            INSERT INTO saas_subscription_invoices
                (tenant_id, subscription_id, invoice_number, status, amount, currency_code, invoice_date, due_date, period_start, period_end, notes)
            VALUES
                (?, ?, '', 'issued', ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmtInsert->bind_param(
            'iidssssss',
            $tenantId,
            $subscriptionId,
            $amount,
            $currencyCode,
            $invoiceDate,
            $periodEnd,
            $periodStart,
            $periodEnd,
            $notes
        );
        $stmtInsert->execute();
        $invoiceId = (int)$stmtInsert->insert_id;
        $stmtInsert->close();

        if ($invoiceId > 0) {
            $invoiceNumber = saas_subscription_invoice_number($invoiceId);
            $stmtNumber = $controlConn->prepare("UPDATE saas_subscription_invoices SET invoice_number = ? WHERE id = ? LIMIT 1");
            $stmtNumber->bind_param('si', $invoiceNumber, $invoiceId);
            $stmtNumber->execute();
            $stmtNumber->close();

            $stmtInvoice = $controlConn->prepare("SELECT * FROM saas_subscription_invoices WHERE id = ? LIMIT 1");
            $stmtInvoice->bind_param('i', $invoiceId);
            $stmtInvoice->execute();
            $invoiceRow = $stmtInvoice->get_result()->fetch_assoc();
            $stmtInvoice->close();
            if (is_array($invoiceRow)) {
                $issuedInvoice = saas_issue_subscription_invoice_access(
                    $controlConn,
                    $invoiceRow,
                    isset($GLOBALS['conn']) && $GLOBALS['conn'] instanceof mysqli ? $GLOBALS['conn'] : null
                );
                if (!empty($issuedInvoice['access_token']) && function_exists('saas_find_subscription_invoice_by_token')) {
                    $fullInvoice = saas_find_subscription_invoice_by_token($controlConn, (string)$issuedInvoice['access_token']);
                    if (is_array($fullInvoice)) {
                        saas_send_billing_notifications($controlConn, $fullInvoice, 'invoice_issued');
                        saas_dispatch_outbound_webhook($controlConn, 'subscription.invoice_issued', [
                            'invoice' => $fullInvoice,
                            'subscription' => saas_fetch_subscription_snapshot($controlConn, (int)($fullInvoice['subscription_id'] ?? 0)),
                        ], (int)($fullInvoice['tenant_id'] ?? 0), 'إصدار فاتورة اشتراك');
                    }
                }
            }
        }

        return ['ok' => $invoiceId > 0, 'reason' => '', 'invoice_id' => $invoiceId, 'already_exists' => false, 'created_by' => $createdBy];
    }
}

if (!function_exists('saas_mark_subscription_invoice_paid')) {
    function saas_mark_subscription_invoice_paid(mysqli $controlConn, int $invoiceId, int $tenantId, ?string $paidAt, string $paymentRef = '', string $paymentMethod = 'manual', string $paymentNotes = ''): bool
    {
        if ($invoiceId <= 0 || $tenantId <= 0) {
            return false;
        }
        $paidAt = saas_dt_db((string)$paidAt) ?: date('Y-m-d H:i:s');
        $paymentRef = trim($paymentRef);
        $paymentMethod = saas_normalize_payment_method($paymentMethod);
        $paymentNotes = trim($paymentNotes);
        $stmtInvoice = $controlConn->prepare("
            SELECT subscription_id, amount, currency_code
            FROM saas_subscription_invoices
            WHERE id = ? AND tenant_id = ? AND status = 'issued'
            LIMIT 1
        ");
        $stmtInvoice->bind_param('ii', $invoiceId, $tenantId);
        $stmtInvoice->execute();
        $invoiceRow = $stmtInvoice->get_result()->fetch_assoc();
        $stmtInvoice->close();
        if (!$invoiceRow) {
            return false;
        }

        $stmt = $controlConn->prepare("
            UPDATE saas_subscription_invoices
            SET status = 'paid', paid_at = ?, payment_ref = ?, gateway_status = 'paid'
            WHERE id = ? AND tenant_id = ? AND status <> 'cancelled'
            LIMIT 1
        ");
        $stmt->bind_param('ssii', $paidAt, $paymentRef, $invoiceId, $tenantId);
        $stmt->execute();
        $affected = $stmt->affected_rows > 0;
        $stmt->close();
        if (!$affected) {
            return false;
        }

        $amount = round((float)($invoiceRow['amount'] ?? 0), 2);
        $currencyCode = strtoupper(trim((string)($invoiceRow['currency_code'] ?? 'EGP')));
        $subscriptionId = (int)($invoiceRow['subscription_id'] ?? 0);
        $notes = $paymentNotes !== '' ? $paymentNotes : 'Subscription invoice payment';
        $stmtPayment = $controlConn->prepare("
            INSERT INTO saas_subscription_invoice_payments
                (tenant_id, invoice_id, subscription_id, amount, currency_code, payment_method, payment_ref, paid_at, status, notes)
            VALUES
                (?, ?, ?, ?, ?, ?, ?, ?, 'posted', ?)
        ");
        $stmtPayment->bind_param(
            'iiidsssss',
            $tenantId,
            $invoiceId,
            $subscriptionId,
            $amount,
            $currencyCode,
            $paymentMethod,
            $paymentRef,
            $paidAt,
            $notes
        );
        $stmtPayment->execute();
        $paymentId = (int)$stmtPayment->insert_id;
        $stmtPayment->close();
        $invoiceSnapshot = saas_fetch_invoice_snapshot($controlConn, $invoiceId);
        saas_dispatch_outbound_webhook($controlConn, 'subscription.invoice_paid', [
            'invoice' => $invoiceSnapshot,
            'payment' => [
                'id' => $paymentId,
                'invoice_id' => $invoiceId,
                'subscription_id' => $subscriptionId,
                'tenant_id' => $tenantId,
                'amount' => $amount,
                'currency_code' => $currencyCode,
                'payment_method' => $paymentMethod,
                'payment_ref' => $paymentRef,
                'payment_notes' => $paymentNotes,
                'paid_at' => $paidAt,
                'status' => 'posted',
            ],
            'subscription' => saas_fetch_subscription_snapshot($controlConn, $subscriptionId),
        ], $tenantId, 'تأكيد سداد فاتورة اشتراك');
        return $affected;
    }
}

if (!function_exists('saas_payment_method_catalog')) {
    function saas_payment_method_catalog(): array
    {
        $catalog = [
            'bank_transfer' => ['label_ar' => 'تحويل بنكي', 'label_en' => 'Bank transfer'],
            'instapay' => ['label_ar' => 'إنستاباي', 'label_en' => 'InstaPay'],
            'wallet' => ['label_ar' => 'محفظة إلكترونية', 'label_en' => 'Mobile wallet'],
            'cash' => ['label_ar' => 'نقدي', 'label_en' => 'Cash'],
            'card' => ['label_ar' => 'بطاقة', 'label_en' => 'Card'],
            'check' => ['label_ar' => 'شيك', 'label_en' => 'Check'],
            'gateway' => ['label_ar' => 'بوابة دفع', 'label_en' => 'Payment gateway'],
            'manual' => ['label_ar' => 'يدوي', 'label_en' => 'Manual'],
        ];

        if (function_exists('app_setting_get') && isset($GLOBALS['conn']) && $GLOBALS['conn'] instanceof mysqli) {
            $raw = trim((string)app_setting_get($GLOBALS['conn'], 'saas_payment_methods_catalog', ''));
            if ($raw !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    foreach ($decoded as $key => $row) {
                        $code = strtolower(trim((string)$key));
                        if ($code === '') {
                            continue;
                        }
                        $catalog[$code] = [
                            'label_ar' => trim((string)($row['label_ar'] ?? $row['ar'] ?? $code)),
                            'label_en' => trim((string)($row['label_en'] ?? $row['en'] ?? $code)),
                        ];
                    }
                }
            }
        }

        return $catalog;
    }
}

if (!function_exists('saas_payment_gateway_settings')) {
    function saas_payment_gateway_settings(?mysqli $settingsConn = null): array
    {
        if ($settingsConn instanceof mysqli && function_exists('app_payment_gateway_settings')) {
            $shared = app_payment_gateway_settings($settingsConn);
            return [
                'enabled' => !empty($shared['enabled']),
                'provider' => (string)($shared['provider'] ?? 'manual'),
                'checkout_url' => (string)($shared['checkout_url'] ?? ''),
                'provider_label_ar' => (string)($shared['provider_label_ar'] ?? 'بوابة الدفع'),
                'provider_label_en' => (string)($shared['provider_label_en'] ?? 'Payment gateway'),
                'instructions_ar' => (string)($shared['instructions_ar'] ?? ''),
                'instructions_en' => (string)($shared['instructions_en'] ?? ''),
                'support_email' => (string)($shared['support_email'] ?? ''),
                'support_whatsapp' => (string)($shared['support_whatsapp'] ?? ''),
                'api_base_url' => (string)($shared['api_base_url'] ?? ''),
                'api_version' => (string)($shared['api_version'] ?? ''),
                'public_key' => (string)($shared['public_key'] ?? ''),
                'secret_key' => (string)($shared['secret_key'] ?? ''),
                'merchant_id' => (string)($shared['merchant_id'] ?? ''),
                'integration_id' => (string)($shared['integration_id'] ?? ''),
                'iframe_id' => (string)($shared['iframe_id'] ?? ''),
                'hmac_secret' => (string)($shared['hmac_secret'] ?? ''),
                'webhook_secret' => (string)($shared['webhook_secret'] ?? ''),
                'callback_url' => (string)($shared['callback_url'] ?? ''),
                'webhook_url' => (string)($shared['webhook_url'] ?? ''),
                'paymob_integration_name' => (string)($shared['paymob_integration_name'] ?? ''),
                'paymob_processed_callback_url' => (string)($shared['paymob_processed_callback_url'] ?? ($shared['callback_url'] ?? '')),
                'paymob_response_callback_url' => (string)($shared['paymob_response_callback_url'] ?? ($shared['webhook_url'] ?? '')),
                'email_notifications_enabled' => !empty($shared['email_notifications_enabled']),
                'whatsapp_notifications_enabled' => !empty($shared['whatsapp_notifications_enabled']),
                'whatsapp_mode' => (string)($shared['whatsapp_mode'] ?? 'link'),
                'whatsapp_access_token' => (string)($shared['whatsapp_access_token'] ?? ''),
                'whatsapp_phone_number_id' => (string)($shared['whatsapp_phone_number_id'] ?? ''),
                'outbound_webhooks_enabled' => !empty($shared['outbound_webhooks_enabled']),
                'outbound_webhooks_url' => (string)($shared['outbound_webhooks_url'] ?? ''),
                'outbound_webhooks_token' => (string)($shared['outbound_webhooks_token'] ?? ''),
                'outbound_webhooks_secret' => (string)($shared['outbound_webhooks_secret'] ?? ''),
                'outbound_webhooks_events' => (string)($shared['outbound_webhooks_events'] ?? ''),
            ];
        }

        $provider = 'manual';
        $enabled = false;
        $checkoutUrl = '';
        $providerLabelAr = 'بوابة الدفع';
        $providerLabelEn = 'Payment gateway';
        $instructionsAr = 'استخدم رابط السداد أو بيانات التحويل الظاهرة، ثم أرسل مرجع السداد إلى فريق المتابعة.';
        $instructionsEn = 'Use the payment link or transfer details shown here, then send the payment reference to the support team.';
        $supportEmail = '';
        $supportWhatsapp = '';
        $apiBaseUrl = '';
        $apiVersion = '';
        $publicKey = '';
        $secretKey = '';
        $merchantId = '';
        $integrationId = '';
        $iframeId = '';
        $hmacSecret = '';
        $webhookSecret = '';
        $callbackUrl = '';
        $webhookUrl = '';
        $paymobIntegrationName = '';
        $paymobProcessedCallbackUrl = '';
        $paymobResponseCallbackUrl = '';
        $emailNotificationsEnabled = true;
        $whatsappNotificationsEnabled = false;
        $whatsappMode = 'link';
        $whatsappAccessToken = '';
        $whatsappPhoneNumberId = '';
        $outboundWebhooksEnabled = false;
        $outboundWebhooksUrl = '';
        $outboundWebhooksToken = '';
        $outboundWebhooksSecret = '';
        $outboundWebhooksEvents = '';

        if ($settingsConn instanceof mysqli && function_exists('app_setting_get')) {
            $enabled = app_setting_get($settingsConn, 'payment_gateway_enabled', app_setting_get($settingsConn, 'saas_gateway_enabled', '0')) === '1';
            $provider = strtolower(trim((string)app_setting_get($settingsConn, 'payment_gateway_provider', app_setting_get($settingsConn, 'saas_gateway_provider', 'manual'))));
            $checkoutUrl = trim((string)app_setting_get($settingsConn, 'payment_gateway_checkout_url', app_setting_get($settingsConn, 'saas_gateway_checkout_url', '')));
            $providerLabelAr = trim((string)app_setting_get($settingsConn, 'payment_gateway_provider_label_ar', app_setting_get($settingsConn, 'saas_gateway_provider_label_ar', $providerLabelAr)));
            $providerLabelEn = trim((string)app_setting_get($settingsConn, 'payment_gateway_provider_label_en', app_setting_get($settingsConn, 'saas_gateway_provider_label_en', $providerLabelEn)));
            $instructionsAr = trim((string)app_setting_get($settingsConn, 'payment_gateway_instructions_ar', app_setting_get($settingsConn, 'saas_gateway_instructions_ar', $instructionsAr)));
            $instructionsEn = trim((string)app_setting_get($settingsConn, 'payment_gateway_instructions_en', app_setting_get($settingsConn, 'saas_gateway_instructions_en', $instructionsEn)));
            $supportEmail = trim((string)app_setting_get($settingsConn, 'payment_gateway_support_email', app_setting_get($settingsConn, 'saas_gateway_support_email', app_setting_get($settingsConn, 'support_email', ''))));
            $supportWhatsapp = trim((string)app_setting_get($settingsConn, 'payment_gateway_support_whatsapp', app_setting_get($settingsConn, 'saas_gateway_support_whatsapp', app_setting_get($settingsConn, 'support_whatsapp', ''))));
            $apiBaseUrl = trim((string)app_setting_get($settingsConn, 'payment_gateway_api_base_url', ''));
            $apiVersion = trim((string)app_setting_get($settingsConn, 'payment_gateway_api_version', ''));
            $publicKey = trim((string)app_setting_get($settingsConn, 'payment_gateway_public_key', ''));
            $secretKey = trim((string)app_setting_get($settingsConn, 'payment_gateway_secret_key', ''));
            $merchantId = trim((string)app_setting_get($settingsConn, 'payment_gateway_merchant_id', ''));
            $integrationId = trim((string)app_setting_get($settingsConn, 'payment_gateway_integration_id', ''));
            $iframeId = trim((string)app_setting_get($settingsConn, 'payment_gateway_iframe_id', ''));
            $hmacSecret = trim((string)app_setting_get($settingsConn, 'payment_gateway_hmac_secret', ''));
            $webhookSecret = trim((string)app_setting_get($settingsConn, 'payment_gateway_webhook_secret', ''));
            $callbackUrl = trim((string)app_setting_get($settingsConn, 'payment_gateway_callback_url', ''));
            $webhookUrl = trim((string)app_setting_get($settingsConn, 'payment_gateway_webhook_url', ''));
            $paymobIntegrationName = trim((string)app_setting_get($settingsConn, 'payment_gateway_paymob_integration_name', ''));
            $paymobProcessedCallbackUrl = trim((string)app_setting_get($settingsConn, 'payment_gateway_paymob_processed_callback_url', $callbackUrl));
            $paymobResponseCallbackUrl = trim((string)app_setting_get($settingsConn, 'payment_gateway_paymob_response_callback_url', $webhookUrl));
            $emailNotificationsEnabled = app_setting_get($settingsConn, 'payment_gateway_email_notifications_enabled', '1') === '1';
            $whatsappNotificationsEnabled = app_setting_get($settingsConn, 'payment_gateway_whatsapp_notifications_enabled', '0') === '1';
            $whatsappMode = trim((string)app_setting_get($settingsConn, 'payment_gateway_whatsapp_mode', 'link'));
            $whatsappAccessToken = trim((string)app_setting_get($settingsConn, 'payment_gateway_whatsapp_access_token', ''));
            $whatsappPhoneNumberId = trim((string)app_setting_get($settingsConn, 'payment_gateway_whatsapp_phone_number_id', ''));
            $outboundWebhooksEnabled = app_setting_get($settingsConn, 'payment_gateway_outbound_webhooks_enabled', '0') === '1';
            $outboundWebhooksUrl = trim((string)app_setting_get($settingsConn, 'payment_gateway_outbound_webhooks_url', ''));
            $outboundWebhooksToken = trim((string)app_setting_get($settingsConn, 'payment_gateway_outbound_webhooks_token', ''));
            $outboundWebhooksSecret = trim((string)app_setting_get($settingsConn, 'payment_gateway_outbound_webhooks_secret', ''));
            $outboundWebhooksEvents = trim((string)app_setting_get($settingsConn, 'payment_gateway_outbound_webhooks_events', ''));
        }

        if ($provider === '') {
            $provider = 'manual';
        }

        return [
            'enabled' => $enabled,
            'provider' => $provider,
            'checkout_url' => $checkoutUrl,
            'provider_label_ar' => $providerLabelAr !== '' ? $providerLabelAr : 'بوابة الدفع',
            'provider_label_en' => $providerLabelEn !== '' ? $providerLabelEn : 'Payment gateway',
            'instructions_ar' => $instructionsAr !== '' ? $instructionsAr : 'استخدم رابط السداد أو بيانات التحويل الظاهرة، ثم أرسل مرجع السداد إلى فريق المتابعة.',
            'instructions_en' => $instructionsEn !== '' ? $instructionsEn : 'Use the payment link or transfer details shown here, then send the payment reference to the support team.',
            'support_email' => $supportEmail,
            'support_whatsapp' => $supportWhatsapp,
            'api_base_url' => $apiBaseUrl,
            'api_version' => $apiVersion,
            'public_key' => $publicKey,
            'secret_key' => $secretKey,
            'merchant_id' => $merchantId,
            'integration_id' => $integrationId,
            'iframe_id' => $iframeId,
            'hmac_secret' => $hmacSecret,
            'webhook_secret' => $webhookSecret,
            'callback_url' => $callbackUrl,
            'webhook_url' => $webhookUrl,
            'paymob_integration_name' => $paymobIntegrationName,
            'paymob_processed_callback_url' => $paymobProcessedCallbackUrl,
            'paymob_response_callback_url' => $paymobResponseCallbackUrl,
            'email_notifications_enabled' => $emailNotificationsEnabled,
            'whatsapp_notifications_enabled' => $whatsappNotificationsEnabled,
            'whatsapp_mode' => $whatsappMode !== '' ? $whatsappMode : 'link',
            'whatsapp_access_token' => $whatsappAccessToken,
            'whatsapp_phone_number_id' => $whatsappPhoneNumberId,
            'outbound_webhooks_enabled' => $outboundWebhooksEnabled,
            'outbound_webhooks_url' => $outboundWebhooksUrl,
            'outbound_webhooks_token' => $outboundWebhooksToken,
            'outbound_webhooks_secret' => $outboundWebhooksSecret,
            'outbound_webhooks_events' => $outboundWebhooksEvents,
        ];
    }
}

if (!function_exists('saas_outbound_webhook_events')) {
    function saas_outbound_webhook_events(array $gatewaySettings): array
    {
        $raw = $gatewaySettings['outbound_webhooks_events'] ?? '';
        $events = [];
        if (is_string($raw) && trim($raw) !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                foreach ($decoded as $item) {
                    $value = strtolower(trim((string)$item));
                    if ($value !== '') {
                        $events[] = $value;
                    }
                }
            } else {
                $parts = preg_split('/[\s,;\n\r]+/', trim((string)$raw)) ?: [];
                foreach ($parts as $item) {
                    $value = strtolower(trim((string)$item));
                    if ($value !== '') {
                        $events[] = $value;
                    }
                }
            }
        }
        return array_values(array_unique($events));
    }
}

if (!function_exists('saas_outbound_webhook_allowed')) {
    function saas_outbound_webhook_allowed(array $gatewaySettings, string $eventCode): bool
    {
        if (empty($gatewaySettings['outbound_webhooks_enabled'])) {
            return false;
        }
        if (trim((string)($gatewaySettings['outbound_webhooks_url'] ?? '')) === '') {
            return false;
        }
        $eventCode = strtolower(trim($eventCode));
        if ($eventCode === '') {
            return false;
        }
        $allowed = saas_outbound_webhook_events($gatewaySettings);
        if ($allowed === []) {
            return true;
        }
        return in_array($eventCode, $allowed, true);
    }
}

if (!function_exists('saas_fetch_tenant_snapshot')) {
    function saas_fetch_tenant_snapshot(mysqli $controlConn, int $tenantId): ?array
    {
        if ($tenantId <= 0) {
            return null;
        }
        $stmt = $controlConn->prepare("SELECT * FROM saas_tenants WHERE id = ? LIMIT 1");
        $stmt->bind_param('i', $tenantId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        if (!is_array($row)) {
            return null;
        }
        if (function_exists('saas_issue_tenant_billing_portal_access')) {
            $row = saas_issue_tenant_billing_portal_access($controlConn, $row);
        }
        unset($row['db_password_plain'], $row['db_password_enc']);
        return $row;
    }
}

if (!function_exists('saas_fetch_subscription_snapshot')) {
    function saas_fetch_subscription_snapshot(mysqli $controlConn, int $subscriptionId): ?array
    {
        if ($subscriptionId <= 0) {
            return null;
        }
        $stmt = $controlConn->prepare("SELECT * FROM saas_subscriptions WHERE id = ? LIMIT 1");
        $stmt->bind_param('i', $subscriptionId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        return is_array($row) ? $row : null;
    }
}

if (!function_exists('saas_fetch_invoice_snapshot')) {
    function saas_fetch_invoice_snapshot(mysqli $controlConn, int $invoiceId): ?array
    {
        if ($invoiceId <= 0) {
            return null;
        }
        $stmt = $controlConn->prepare("
            SELECT i.*, t.tenant_name, t.tenant_slug, t.billing_email
            FROM saas_subscription_invoices i
            INNER JOIN saas_tenants t ON t.id = i.tenant_id
            WHERE i.id = ?
            LIMIT 1
        ");
        $stmt->bind_param('i', $invoiceId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        if (!is_array($row)) {
            return null;
        }
        return function_exists('saas_issue_subscription_invoice_access')
            ? saas_issue_subscription_invoice_access($controlConn, $row, isset($GLOBALS['conn']) && $GLOBALS['conn'] instanceof mysqli ? $GLOBALS['conn'] : null)
            : $row;
    }
}

if (!function_exists('saas_webhook_record_attempt')) {
    function saas_webhook_record_attempt(mysqli $controlConn, int $deliveryId, array $response, int $attemptCount, int $maxAttempts): void
    {
        $ok = !empty($response['ok']);
        $httpCode = (int)($response['http_code'] ?? 0);
        $body = (string)($response['body'] ?? '');
        $error = mb_substr(trim((string)($response['error'] ?? '')), 0, 255);
        $status = $ok ? 'sent' : 'failed';
        $lastAttemptAt = date('Y-m-d H:i:s');
        $deliveredAt = $ok ? $lastAttemptAt : null;
        $nextRetryAt = (!$ok && $attemptCount < $maxAttempts) ? saas_webhook_due_retry_at($attemptCount) : null;

        $stmt = $controlConn->prepare("
            UPDATE saas_webhook_deliveries
            SET status = ?, attempt_count = ?, http_code = ?, response_body = ?, last_error = ?, last_attempt_at = ?, next_retry_at = ?, delivered_at = ?
            WHERE id = ?
            LIMIT 1
        ");
        $stmt->bind_param('siisssssi', $status, $attemptCount, $httpCode, $body, $error, $lastAttemptAt, $nextRetryAt, $deliveredAt, $deliveryId);
        $stmt->execute();
        $stmt->close();
    }
}

if (!function_exists('saas_execute_webhook_delivery')) {
    function saas_execute_webhook_delivery(mysqli $controlConn, array $delivery): array
    {
        $deliveryId = (int)($delivery['id'] ?? 0);
        if ($deliveryId <= 0) {
            return ['ok' => false, 'skipped' => true, 'reason' => 'invalid_delivery'];
        }

        $targetUrl = trim((string)($delivery['target_url'] ?? ''));
        if ($targetUrl === '') {
            return ['ok' => false, 'skipped' => true, 'reason' => 'missing_target_url'];
        }

        $payload = json_decode((string)($delivery['payload_json'] ?? ''), true);
        if (!is_array($payload)) {
            $payload = [];
        }
        $headers = json_decode((string)($delivery['request_headers_json'] ?? ''), true);
        if (!is_array($headers)) {
            $headers = [];
        }
        $headers = array_values(array_filter(array_map(static function ($line) {
            return trim((string)$line);
        }, $headers), static function ($line) {
            return $line !== '';
        }));

        $attemptCount = max(0, (int)($delivery['attempt_count'] ?? 0)) + 1;
        $maxAttempts = max(1, (int)($delivery['max_attempts'] ?? 5));
        $response = app_license_http_post_json($targetUrl, $payload, $headers, 20);
        saas_webhook_record_attempt($controlConn, $deliveryId, $response, $attemptCount, $maxAttempts);

        $tenantId = max(0, (int)($delivery['tenant_id'] ?? 0));
        app_saas_log_operation(
            $controlConn,
            !empty($response['ok']) ? 'integration.webhook_sent' : 'integration.webhook_failed',
            !empty($response['ok']) ? 'إرسال Outbound Webhook' : 'فشل Outbound Webhook',
            $tenantId,
            [
                'delivery_id' => $deliveryId,
                'event' => (string)($delivery['event_code'] ?? ''),
                'target_url' => $targetUrl,
                'attempt_count' => $attemptCount,
                'max_attempts' => $maxAttempts,
                'http_code' => (int)($response['http_code'] ?? 0),
                'ok' => !empty($response['ok']),
                'error' => (string)($response['error'] ?? ''),
                'next_retry_at' => (!$response['ok'] && $attemptCount < $maxAttempts) ? saas_webhook_due_retry_at($attemptCount) : null,
            ],
            'System'
        );

        return [
            'ok' => !empty($response['ok']),
            'skipped' => false,
            'delivery_id' => $deliveryId,
            'attempt_count' => $attemptCount,
            'max_attempts' => $maxAttempts,
            'http_code' => (int)($response['http_code'] ?? 0),
            'reason' => (string)($response['error'] ?? ''),
            'body' => (string)($response['body'] ?? ''),
        ];
    }
}

if (!function_exists('saas_retry_due_webhook_deliveries')) {
    function saas_retry_due_webhook_deliveries(mysqli $controlConn, int $limit = 20): array
    {
        $limit = max(1, min(200, $limit));
        $rows = [];
        $now = date('Y-m-d H:i:s');
        $stmt = $controlConn->prepare("
            SELECT *
            FROM saas_webhook_deliveries
            WHERE status = 'failed'
              AND attempt_count < max_attempts
              AND (next_retry_at IS NULL OR next_retry_at <= ?)
            ORDER BY COALESCE(next_retry_at, created_at) ASC, id ASC
            LIMIT ?
        ");
        $stmt->bind_param('si', $now, $limit);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $rows[] = $row;
        }
        $stmt->close();

        $summary = ['selected' => count($rows), 'sent' => 0, 'failed' => 0];
        foreach ($rows as $row) {
            $result = saas_execute_webhook_delivery($controlConn, $row);
            if (!empty($result['ok'])) {
                $summary['sent']++;
            } elseif (empty($result['skipped'])) {
                $summary['failed']++;
            }
        }
        return $summary;
    }
}

if (!function_exists('saas_retry_webhook_delivery_now')) {
    function saas_retry_webhook_delivery_now(mysqli $controlConn, int $deliveryId): array
    {
        if ($deliveryId <= 0) {
            return ['ok' => false, 'reason' => 'invalid_delivery'];
        }
        $stmt = $controlConn->prepare("SELECT * FROM saas_webhook_deliveries WHERE id = ? LIMIT 1");
        $stmt->bind_param('i', $deliveryId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        if (!is_array($row)) {
            return ['ok' => false, 'reason' => 'not_found'];
        }
        if ((int)($row['attempt_count'] ?? 0) >= (int)($row['max_attempts'] ?? 5)) {
            return ['ok' => false, 'reason' => 'max_attempts_reached'];
        }
        return saas_execute_webhook_delivery($controlConn, $row);
    }
}

if (!function_exists('saas_dispatch_outbound_webhook')) {
    function saas_dispatch_outbound_webhook(mysqli $controlConn, string $eventCode, array $payload, int $tenantId = 0, string $label = ''): array
    {
        $gatewaySettings = saas_payment_gateway_settings($controlConn);
        $eventCode = strtolower(trim($eventCode));
        if (!saas_outbound_webhook_allowed($gatewaySettings, $eventCode)) {
            return ['ok' => false, 'skipped' => true, 'reason' => 'disabled_or_not_allowed'];
        }

        $url = trim((string)($gatewaySettings['outbound_webhooks_url'] ?? ''));
        if ($url === '') {
            return ['ok' => false, 'skipped' => true, 'reason' => 'missing_url'];
        }

        $tenantId = max(0, $tenantId);
        $tenant = $tenantId > 0 ? saas_fetch_tenant_snapshot($controlConn, $tenantId) : null;
        $body = [
            'event' => $eventCode,
            'label' => $label !== '' ? $label : $eventCode,
            'sent_at' => date('c'),
            'runtime_profile' => app_runtime_profile(),
            'system_url' => rtrim(app_base_url(), '/'),
            'tenant' => $tenant,
            'data' => $payload,
        ];

        $headers = ['X-ArabEagles-Webhook-Event: ' . $eventCode];
        $token = trim((string)($gatewaySettings['outbound_webhooks_token'] ?? ''));
        if ($token !== '') {
            $headers[] = 'Authorization: Bearer ' . $token;
        }
        $secret = trim((string)($gatewaySettings['outbound_webhooks_secret'] ?? ''));
        if ($secret !== '') {
            $json = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (is_string($json)) {
                $headers[] = 'X-ArabEagles-Webhook-Signature: sha256=' . hash_hmac('sha256', $json, $secret);
            }
        }

        $payloadJson = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $headersJson = json_encode($headers, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $maxAttempts = 5;
        if ($tenantId > 0) {
            $stmt = $controlConn->prepare("
                INSERT INTO saas_webhook_deliveries
                    (tenant_id, event_code, event_label, target_url, request_headers_json, payload_json, status, max_attempts)
                VALUES
                    (?, ?, ?, ?, ?, ?, 'pending', ?)
            ");
            $stmt->bind_param('isssssi', $tenantId, $eventCode, $label, $url, $headersJson, $payloadJson, $maxAttempts);
        } else {
            $stmt = $controlConn->prepare("
                INSERT INTO saas_webhook_deliveries
                    (tenant_id, event_code, event_label, target_url, request_headers_json, payload_json, status, max_attempts)
                VALUES
                    (NULL, ?, ?, ?, ?, ?, 'pending', ?)
            ");
            $stmt->bind_param('sssssi', $eventCode, $label, $url, $headersJson, $payloadJson, $maxAttempts);
        }
        $stmt->execute();
        $deliveryId = (int)$stmt->insert_id;
        $stmt->close();

        if ($deliveryId <= 0) {
            return ['ok' => false, 'skipped' => false, 'reason' => 'insert_failed'];
        }

        return saas_execute_webhook_delivery($controlConn, [
            'id' => $deliveryId,
            'tenant_id' => $tenantId,
            'event_code' => $eventCode,
            'target_url' => $url,
            'request_headers_json' => $headersJson,
            'payload_json' => $payloadJson,
            'attempt_count' => 0,
            'max_attempts' => $maxAttempts,
        ]);
    }
}

if (!function_exists('saas_billing_notification_message')) {
    function saas_billing_notification_message(array $invoiceRow, string $eventCode, bool $isEnglish = false, array $extra = []): array
    {
        $invoiceNumber = (string)($invoiceRow['invoice_number'] ?? 'SINV');
        $tenantName = (string)($invoiceRow['tenant_name'] ?? $invoiceRow['tenant_slug'] ?? 'Tenant');
        $amount = number_format((float)($invoiceRow['amount'] ?? 0), 2);
        $currency = (string)($invoiceRow['currency_code'] ?? 'EGP');
        $portalUrl = (string)(function_exists('saas_subscription_invoice_public_url') ? saas_subscription_invoice_public_url($invoiceRow) : '');
        $tenantPortalUrl = trim((string)($extra['tenant_portal_url'] ?? ''));
        $paymentRef = trim((string)($extra['payment_ref'] ?? ''));
        $payerName = trim((string)($extra['payer_name'] ?? ''));
        $notice = trim((string)($extra['notice'] ?? ''));

        if ($eventCode === 'payment_notice') {
            $subject = $isEnglish
                ? 'Payment notice for subscription invoice ' . $invoiceNumber
                : 'إشعار سداد لفاتورة الاشتراك ' . $invoiceNumber;
            $bodyLines = $isEnglish
                ? [
                    'A payment notice was submitted from the public billing portal.',
                    'Tenant: ' . $tenantName,
                    'Invoice: ' . $invoiceNumber,
                    'Amount: ' . $currency . ' ' . $amount,
                    'Payer: ' . ($payerName !== '' ? $payerName : '-'),
                    'Reference: ' . ($paymentRef !== '' ? $paymentRef : '-'),
                    'Notes: ' . ($notice !== '' ? $notice : '-'),
                    'Portal: ' . ($portalUrl !== '' ? $portalUrl : '-'),
                    'Account portal: ' . ($tenantPortalUrl !== '' ? $tenantPortalUrl : '-'),
                ]
                : [
                    'تم إرسال إشعار سداد من بوابة الفوترة العامة.',
                    'المستأجر: ' . $tenantName,
                    'الفاتورة: ' . $invoiceNumber,
                    'القيمة: ' . $currency . ' ' . $amount,
                    'اسم الدافع: ' . ($payerName !== '' ? $payerName : '-'),
                    'المرجع: ' . ($paymentRef !== '' ? $paymentRef : '-'),
                    'الملاحظات: ' . ($notice !== '' ? $notice : '-'),
                    'رابط البوابة: ' . ($portalUrl !== '' ? $portalUrl : '-'),
                    'رابط بوابة العميل: ' . ($tenantPortalUrl !== '' ? $tenantPortalUrl : '-'),
                ];
        } else {
            $subject = $isEnglish
                ? 'Subscription invoice issued: ' . $invoiceNumber
                : 'تم إصدار فاتورة اشتراك: ' . $invoiceNumber;
            $bodyLines = $isEnglish
                ? [
                    'A new subscription invoice has been issued.',
                    'Tenant: ' . $tenantName,
                    'Invoice: ' . $invoiceNumber,
                    'Amount: ' . $currency . ' ' . $amount,
                    'Portal: ' . ($portalUrl !== '' ? $portalUrl : '-'),
                    'Account portal: ' . ($tenantPortalUrl !== '' ? $tenantPortalUrl : '-'),
                ]
                : [
                    'تم إصدار فاتورة اشتراك جديدة.',
                    'المستأجر: ' . $tenantName,
                    'الفاتورة: ' . $invoiceNumber,
                    'القيمة: ' . $currency . ' ' . $amount,
                    'رابط البوابة: ' . ($portalUrl !== '' ? $portalUrl : '-'),
                    'رابط بوابة العميل: ' . ($tenantPortalUrl !== '' ? $tenantPortalUrl : '-'),
                ];
        }

        return [
            'subject' => $subject,
            'body_ar' => implode("\n", $isEnglish ? [] : $bodyLines),
            'body_en' => implode("\n", $isEnglish ? $bodyLines : []),
            'body' => implode("\n", $bodyLines),
        ];
    }
}

if (!function_exists('saas_send_billing_notifications')) {
    function saas_send_billing_notifications(mysqli $controlConn, array $invoiceRow, string $eventCode, array $extra = []): array
    {
        $gatewaySettings = saas_payment_gateway_settings($controlConn);
        $tenantEmail = trim((string)($invoiceRow['billing_email'] ?? ''));
        $supportEmail = trim((string)($gatewaySettings['support_email'] ?? ''));
        $supportWhatsapp = trim((string)($gatewaySettings['support_whatsapp'] ?? ''));
        $tenantPortalUrl = '';
        $tenantId = (int)($invoiceRow['tenant_id'] ?? 0);
        if ($tenantId > 0) {
            $stmtTenant = $controlConn->prepare("SELECT * FROM saas_tenants WHERE id = ? LIMIT 1");
            $stmtTenant->bind_param('i', $tenantId);
            $stmtTenant->execute();
            $tenantRow = $stmtTenant->get_result()->fetch_assoc() ?: null;
            $stmtTenant->close();
            if (is_array($tenantRow) && function_exists('saas_issue_tenant_billing_portal_access')) {
                $tenantRow = saas_issue_tenant_billing_portal_access($controlConn, $tenantRow);
                $tenantPortalUrl = (string)($tenantRow['billing_portal_url'] ?? '');
            }
        }
        $extra['tenant_portal_url'] = $tenantPortalUrl;

        $messageAr = saas_billing_notification_message($invoiceRow, $eventCode, false, $extra);
        $messageEn = saas_billing_notification_message($invoiceRow, $eventCode, true, $extra);

        $emailResults = [
            'support' => false,
            'tenant' => false,
        ];
        if (!empty($gatewaySettings['email_notifications_enabled']) && function_exists('app_send_email_basic')) {
            if ($supportEmail !== '') {
                $emailResults['support'] = app_send_email_basic($supportEmail, (string)$messageAr['subject'], (string)$messageAr['body'], [
                    'from_name' => 'Arab Eagles Billing',
                ]);
            }
            if ($tenantEmail !== '') {
                $emailResults['tenant'] = app_send_email_basic($tenantEmail, (string)$messageEn['subject'], (string)$messageEn['body'], [
                    'from_name' => 'Arab Eagles Billing',
                ]);
            }
        }

        $whatsResult = ['ok' => false, 'mode' => 'disabled', 'error' => 'notifications_disabled', 'link' => ''];
        if ($supportWhatsapp !== '' && function_exists('app_payment_gateway_send_whatsapp')) {
            $whatsResult = app_payment_gateway_send_whatsapp($gatewaySettings, $supportWhatsapp, (string)$messageAr['body']);
        }

        return [
            'email' => $emailResults,
            'whatsapp' => $whatsResult,
        ];
    }
}

if (!function_exists('saas_subscription_invoice_access_token')) {
    function saas_subscription_invoice_access_token(int $invoiceId, string $existingToken = ''): string
    {
        $existingToken = trim($existingToken);
        if ($existingToken !== '') {
            return $existingToken;
        }
        $length = 48;
        try {
            return substr(bin2hex(random_bytes((int)ceil($length / 2))), 0, $length);
        } catch (Throwable $e) {
            return 'sinv_' . $invoiceId . '_' . substr(sha1((string)$invoiceId . '|' . microtime(true)), 0, 32);
        }
    }
}

if (!function_exists('saas_tenant_billing_portal_token')) {
    function saas_tenant_billing_portal_token(int $tenantId, string $existingToken = ''): string
    {
        $existingToken = trim($existingToken);
        if ($existingToken !== '') {
            return $existingToken;
        }
        $length = 56;
        try {
            return substr(bin2hex(random_bytes((int)ceil($length / 2))), 0, $length);
        } catch (Throwable $e) {
            return 'tenant_' . $tenantId . '_' . substr(sha1((string)$tenantId . '|' . microtime(true)), 0, 40);
        }
    }
}

if (!function_exists('saas_tenant_billing_portal_url')) {
    function saas_tenant_billing_portal_url(array $tenantRow): string
    {
        $token = trim((string)($tenantRow['billing_portal_token'] ?? ''));
        if ($token === '') {
            return '';
        }
        $baseUrl = app_saas_gateway_base_url();
        if ($baseUrl === '') {
            return '';
        }
        return rtrim($baseUrl, '/') . '/saas_billing_portal.php?portal=' . rawurlencode($token);
    }
}

if (!function_exists('saas_subscription_invoice_public_url')) {
    function saas_subscription_invoice_public_url(array $invoiceRow): string
    {
        $token = trim((string)($invoiceRow['access_token'] ?? ''));
        if ($token === '') {
            return '';
        }
        $baseUrl = app_saas_gateway_base_url();
        if ($baseUrl === '') {
            return '';
        }
        return rtrim($baseUrl, '/') . '/saas_billing_portal.php?token=' . rawurlencode($token);
    }
}

if (!function_exists('saas_issue_subscription_invoice_access')) {
    function saas_issue_subscription_invoice_access(mysqli $controlConn, array $invoiceRow, ?mysqli $settingsConn = null): array
    {
        $invoiceId = (int)($invoiceRow['id'] ?? 0);
        if ($invoiceId <= 0) {
            return $invoiceRow;
        }

        $gatewaySettings = saas_payment_gateway_settings($settingsConn ?: $controlConn);
        $token = saas_subscription_invoice_access_token($invoiceId, (string)($invoiceRow['access_token'] ?? ''));
        $provider = strtolower(trim((string)($invoiceRow['gateway_provider'] ?? '')));
        if ($provider === '') {
            $provider = strtolower(trim((string)($gatewaySettings['provider'] ?? 'manual')));
        }
        if ($provider === '') {
            $provider = 'manual';
        }

        $status = strtolower(trim((string)($invoiceRow['gateway_status'] ?? '')));
        if ($status === '') {
            $status = strtolower(trim((string)($invoiceRow['status'] ?? 'issued'))) === 'paid'
                ? 'paid'
                : (($gatewaySettings['enabled'] ?? false) ? 'ready' : 'manual');
        }

        $invoiceRow['access_token'] = $token;
        $invoiceRow['gateway_provider'] = $provider;
        $invoiceRow['gateway_status'] = $status;
        $invoiceRow['gateway_public_url'] = saas_subscription_invoice_public_url($invoiceRow);

        $tenantId = (int)($invoiceRow['tenant_id'] ?? 0);
        $tenantRow = ['id' => $tenantId];
        if ($tenantId > 0) {
            $stmtTenant = $controlConn->prepare("SELECT id, tenant_slug, tenant_name, billing_email FROM saas_tenants WHERE id = ? LIMIT 1");
            $stmtTenant->bind_param('i', $tenantId);
            $stmtTenant->execute();
            $tenantResult = $stmtTenant->get_result()->fetch_assoc();
            $stmtTenant->close();
            if (is_array($tenantResult)) {
                $tenantRow = $tenantResult;
            }
        }

        $adapterCheckout = saas_gateway_adapter_build_checkout($invoiceRow, $tenantRow, $gatewaySettings);
        $invoiceRow['gateway_provider'] = (string)($adapterCheckout['provider'] ?? $invoiceRow['gateway_provider']);
        $invoiceRow['gateway_status'] = (string)($adapterCheckout['status'] ?? $invoiceRow['gateway_status']);
        if (trim((string)($adapterCheckout['url'] ?? '')) !== '') {
            $invoiceRow['gateway_public_url'] = (string)$adapterCheckout['url'];
        }
        $invoiceRow['gateway_adapter'] = $adapterCheckout;

        $stmtUpdate = $controlConn->prepare("
            UPDATE saas_subscription_invoices
            SET access_token = ?, gateway_provider = ?, gateway_status = ?, gateway_public_url = ?
            WHERE id = ?
            LIMIT 1
        ");
        $stmtUpdate->bind_param(
            'ssssi',
            $invoiceRow['access_token'],
            $invoiceRow['gateway_provider'],
            $invoiceRow['gateway_status'],
            $invoiceRow['gateway_public_url'],
            $invoiceId
        );
        $stmtUpdate->execute();
        $stmtUpdate->close();

        return $invoiceRow;
    }
}

if (!function_exists('saas_issue_tenant_billing_portal_access')) {
    function saas_issue_tenant_billing_portal_access(mysqli $controlConn, array $tenantRow): array
    {
        $tenantId = (int)($tenantRow['id'] ?? 0);
        if ($tenantId <= 0) {
            return $tenantRow;
        }

        $token = saas_tenant_billing_portal_token($tenantId, (string)($tenantRow['billing_portal_token'] ?? ''));
        $tenantRow['billing_portal_token'] = $token;
        $tenantRow['billing_portal_url'] = saas_tenant_billing_portal_url($tenantRow);

        $stmt = $controlConn->prepare("UPDATE saas_tenants SET billing_portal_token = ? WHERE id = ? LIMIT 1");
        $stmt->bind_param('si', $tenantRow['billing_portal_token'], $tenantId);
        $stmt->execute();
        $stmt->close();

        return $tenantRow;
    }
}

if (!function_exists('saas_find_subscription_invoice_by_token')) {
    function saas_find_subscription_invoice_by_token(mysqli $controlConn, string $token): ?array
    {
        $token = trim($token);
        if ($token === '') {
            return null;
        }
        $stmt = $controlConn->prepare("
            SELECT i.*, t.tenant_slug, t.tenant_name, t.billing_email, t.app_url, s.plan_code, s.billing_cycle
            FROM saas_subscription_invoices i
            INNER JOIN saas_tenants t ON t.id = i.tenant_id
            LEFT JOIN saas_subscriptions s ON s.id = i.subscription_id
            WHERE i.access_token = ?
            LIMIT 1
        ");
        $stmt->bind_param('s', $token);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return is_array($row) ? $row : null;
    }
}

if (!function_exists('saas_find_tenant_by_portal_token')) {
    function saas_find_tenant_by_portal_token(mysqli $controlConn, string $token): ?array
    {
        $token = trim($token);
        if ($token === '') {
            return null;
        }
        $stmt = $controlConn->prepare("
            SELECT *
            FROM saas_tenants
            WHERE billing_portal_token = ?
            LIMIT 1
        ");
        $stmt->bind_param('s', $token);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!is_array($row)) {
            return null;
        }
        return saas_issue_tenant_billing_portal_access($controlConn, $row);
    }
}

if (!function_exists('saas_normalize_payment_method')) {
    function saas_normalize_payment_method(string $paymentMethod): string
    {
        $paymentMethod = strtolower(trim($paymentMethod));
        if ($paymentMethod === '') {
            return 'manual';
        }

        $catalog = saas_payment_method_catalog();
        if (isset($catalog[$paymentMethod])) {
            return $paymentMethod;
        }

        $paymentMethod = preg_replace('/[^a-z0-9_\-]+/', '_', $paymentMethod);
        $paymentMethod = trim((string)$paymentMethod, '_-');
        return $paymentMethod !== '' ? substr($paymentMethod, 0, 60) : 'manual';
    }
}

if (!function_exists('saas_paymob_extract_payload')) {
    function saas_paymob_extract_payload(): array
    {
        $payload = [];
        if (!empty($_GET) && is_array($_GET)) {
            $payload = array_merge($payload, $_GET);
        }
        if (!empty($_POST) && is_array($_POST)) {
            $payload = array_merge($payload, $_POST);
        }

        $raw = isset($GLOBALS['saas_paymob_raw_body']) ? (string)$GLOBALS['saas_paymob_raw_body'] : (string)file_get_contents('php://input');
        if ($raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $payload = array_merge($payload, $decoded);
            }
        }

        return $payload;
    }
}

if (!function_exists('saas_paymob_payload_value')) {
    function saas_paymob_payload_value(array $payload, array $keys)
    {
        foreach ($keys as $key) {
            $cursor = $payload;
            $segments = explode('.', (string)$key);
            $found = true;
            foreach ($segments as $segment) {
                if (is_array($cursor) && array_key_exists($segment, $cursor)) {
                    $cursor = $cursor[$segment];
                    continue;
                }
                $found = false;
                break;
            }
            if ($found) {
                return $cursor;
            }
        }
        return null;
    }
}

if (!function_exists('saas_paymob_callback_candidates')) {
    function saas_paymob_callback_candidates(array $payload): array
    {
        $keys = [
            'token',
            'merchant_order_id',
            'merchant_reference',
            'order_id',
            'order.id',
            'obj.order.id',
            'obj.order.merchant_order_id',
            'obj.id',
            'source_data.sub_type',
            'invoice_number',
            'reference',
        ];
        $values = [];
        foreach ($keys as $key) {
            $value = saas_paymob_payload_value($payload, [$key]);
            if (is_scalar($value)) {
                $value = trim((string)$value);
                if ($value !== '') {
                    $values[] = $value;
                }
            }
        }
        return array_values(array_unique($values));
    }
}

if (!function_exists('saas_paymob_callback_success')) {
    function saas_paymob_callback_success(array $payload): bool
    {
        $candidates = [
            saas_paymob_payload_value($payload, ['success', 'obj.success']),
            saas_paymob_payload_value($payload, ['is_auth', 'obj.is_auth']),
            saas_paymob_payload_value($payload, ['is_capture', 'obj.is_capture']),
        ];
        foreach ($candidates as $candidate) {
            if (is_bool($candidate)) {
                return $candidate;
            }
            if (is_numeric($candidate)) {
                return (int)$candidate === 1;
            }
            $text = strtolower(trim((string)$candidate));
            if (in_array($text, ['true', 'paid', 'success', 'successful', '1'], true)) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('saas_paymob_signature_candidates')) {
    function saas_paymob_signature_candidates(array $payload): array
    {
        $candidates = [];
        foreach (['hmac', 'signature', 'obj.hmac'] as $key) {
            $value = saas_paymob_payload_value($payload, [$key]);
            if (is_scalar($value)) {
                $value = trim((string)$value);
                if ($value !== '') {
                    $candidates[] = $value;
                }
            }
        }

        foreach (['HTTP_X_PAYMOB_SIGNATURE', 'HTTP_X_SIGNATURE', 'HTTP_X_HMAC'] as $serverKey) {
            $value = trim((string)($_SERVER[$serverKey] ?? ''));
            if ($value !== '') {
                $candidates[] = $value;
            }
        }

        return array_values(array_unique($candidates));
    }
}

if (!function_exists('saas_paymob_payload_flat_pairs')) {
    function saas_paymob_payload_flat_pairs(array $payload, string $prefix = ''): array
    {
        $pairs = [];
        foreach ($payload as $key => $value) {
            $fullKey = $prefix === '' ? (string)$key : ($prefix . '.' . (string)$key);
            if (is_array($value)) {
                $pairs = array_merge($pairs, saas_paymob_payload_flat_pairs($value, $fullKey));
            } elseif (is_scalar($value) || $value === null) {
                $pairs[$fullKey] = (string)$value;
            }
        }
        ksort($pairs);
        return $pairs;
    }
}

if (!function_exists('saas_paymob_verify_signature')) {
    function saas_paymob_verify_signature(array $gatewaySettings, array $payload, string $rawBody = ''): array
    {
        $secret = trim((string)($gatewaySettings['hmac_secret'] ?? $gatewaySettings['webhook_secret'] ?? ''));
        if ($secret === '') {
            return ['required' => false, 'verified' => false, 'reason' => 'secret_not_configured'];
        }

        $provided = saas_paymob_signature_candidates($payload);
        if (empty($provided)) {
            return ['required' => true, 'verified' => false, 'reason' => 'signature_missing'];
        }

        $checks = [];
        if ($rawBody !== '') {
            $checks[] = hash_hmac('sha512', $rawBody, $secret);
            $checks[] = hash_hmac('sha256', $rawBody, $secret);
        }

        $flatPairs = saas_paymob_payload_flat_pairs($payload);
        if (!empty($flatPairs)) {
            $query = http_build_query($flatPairs, '', '&', PHP_QUERY_RFC3986);
            $checks[] = hash_hmac('sha512', $query, $secret);
            $checks[] = hash_hmac('sha256', $query, $secret);
            $joined = implode('', array_values($flatPairs));
            if ($joined !== '') {
                $checks[] = hash_hmac('sha512', $joined, $secret);
                $checks[] = hash_hmac('sha256', $joined, $secret);
            }
        }

        $checks = array_values(array_unique(array_filter($checks)));
        foreach ($provided as $signature) {
            foreach ($checks as $candidate) {
                if (hash_equals(strtolower($candidate), strtolower(trim((string)$signature)))) {
                    return ['required' => true, 'verified' => true, 'reason' => 'matched'];
                }
            }
        }

        return ['required' => true, 'verified' => false, 'reason' => 'signature_mismatch', 'provided' => $provided];
    }
}

if (!function_exists('saas_find_subscription_invoice_by_reference')) {
    function saas_find_subscription_invoice_by_reference(mysqli $controlConn, array $references): ?array
    {
        foreach ($references as $reference) {
            $reference = trim((string)$reference);
            if ($reference === '') {
                continue;
            }

            $stmt = $controlConn->prepare("
                SELECT i.*, t.tenant_slug, t.tenant_name, t.billing_email, t.app_url, s.plan_code, s.billing_cycle
                FROM saas_subscription_invoices i
                INNER JOIN saas_tenants t ON t.id = i.tenant_id
                LEFT JOIN saas_subscriptions s ON s.id = i.subscription_id
                WHERE i.access_token = ? OR i.invoice_number = ? OR CAST(i.id AS CHAR(32)) = ?
                LIMIT 1
            ");
            $stmt->bind_param('sss', $reference, $reference, $reference);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (is_array($row)) {
                return $row;
            }
        }
        return null;
    }
}

if (!function_exists('saas_payment_method_label')) {
    function saas_payment_method_label(string $paymentMethod, bool $isEnglish = false): string
    {
        $paymentMethod = saas_normalize_payment_method($paymentMethod);
        $catalog = saas_payment_method_catalog();
        if (isset($catalog[$paymentMethod])) {
            return (string)($isEnglish ? ($catalog[$paymentMethod]['label_en'] ?? $paymentMethod) : ($catalog[$paymentMethod]['label_ar'] ?? $paymentMethod));
        }

        return ucwords(str_replace(['_', '-'], ' ', $paymentMethod));
    }
}

if (!function_exists('saas_reopen_subscription_invoice')) {
    function saas_reopen_subscription_invoice(mysqli $controlConn, int $invoiceId, int $tenantId): bool
    {
        if ($invoiceId <= 0 || $tenantId <= 0) {
            return false;
        }
        $stmt = $controlConn->prepare("
            UPDATE saas_subscription_invoices
            SET status = 'issued', paid_at = NULL, payment_ref = '', gateway_status = 'ready'
            WHERE id = ? AND tenant_id = ? AND status = 'paid'
            LIMIT 1
        ");
        $stmt->bind_param('ii', $invoiceId, $tenantId);
        $stmt->execute();
        $affected = $stmt->affected_rows > 0;
        $stmt->close();
        if ($affected) {
            $stmtReverse = $controlConn->prepare("
                UPDATE saas_subscription_invoice_payments
                SET status = 'reversed'
                WHERE invoice_id = ? AND tenant_id = ? AND status = 'posted'
            ");
            $stmtReverse->bind_param('ii', $invoiceId, $tenantId);
            $stmtReverse->execute();
            $stmtReverse->close();
        }
        return $affected;
    }
}

if (!function_exists('saas_finance_summary')) {
    function saas_finance_summary(mysqli $controlConn): array
    {
        $summary = [
            'invoices_total' => 0,
            'issued_count' => 0,
            'paid_count' => 0,
            'overdue_count' => 0,
            'issued_amount' => 0.0,
            'paid_amount' => 0.0,
            'outstanding_amount' => 0.0,
            'payments_posted' => 0.0,
            'payments_reversed' => 0.0,
        ];

        $invoiceRes = $controlConn->query("
            SELECT status, amount, due_date
            FROM saas_subscription_invoices
            ORDER BY id DESC
        ");
        while ($row = $invoiceRes->fetch_assoc()) {
            $summary['invoices_total']++;
            $status = strtolower(trim((string)($row['status'] ?? 'issued')));
            $amount = round((float)($row['amount'] ?? 0), 2);
            if ($status === 'paid') {
                $summary['paid_count']++;
                $summary['paid_amount'] += $amount;
                continue;
            }
            if ($status !== 'issued') {
                continue;
            }
            $summary['issued_count']++;
            $summary['issued_amount'] += $amount;
            $summary['outstanding_amount'] += $amount;
            $dueTs = strtotime((string)($row['due_date'] ?? ''));
            if ($dueTs !== false && $dueTs < time()) {
                $summary['overdue_count']++;
            }
        }

        $paymentRes = $controlConn->query("
            SELECT status, amount
            FROM saas_subscription_invoice_payments
            ORDER BY id DESC
        ");
        while ($row = $paymentRes->fetch_assoc()) {
            $amount = round((float)($row['amount'] ?? 0), 2);
            $status = strtolower(trim((string)($row['status'] ?? 'posted')));
            if ($status === 'reversed') {
                $summary['payments_reversed'] += $amount;
            } else {
                $summary['payments_posted'] += $amount;
            }
        }

        return $summary;
    }
}

if (!function_exists('saas_apply_overdue_policy_for_tenant')) {
    function saas_apply_overdue_policy_for_tenant(mysqli $controlConn, int $tenantId): array
    {
        if ($tenantId <= 0) {
            return ['updated' => 0, 'suspended' => 0, 'past_due' => 0];
        }

        $updated = 0;
        $suspended = 0;
        $pastDue = 0;
        $nowTs = time();

        $stmtSelect = $controlConn->prepare("SELECT * FROM saas_subscriptions WHERE tenant_id = ? AND status <> 'cancelled' ORDER BY id ASC");
        $stmtSelect->bind_param('i', $tenantId);
        $stmtSelect->execute();
        $result = $stmtSelect->get_result();

        $stmtInvoice = $controlConn->prepare("
            SELECT due_date
            FROM saas_subscription_invoices
            WHERE subscription_id = ? AND status = 'issued' AND due_date IS NOT NULL
            ORDER BY due_date ASC, id ASC
            LIMIT 1
        ");
        $stmtUpdate = $controlConn->prepare("UPDATE saas_subscriptions SET status = ? WHERE id = ? AND tenant_id = ? LIMIT 1");

        while ($row = $result->fetch_assoc()) {
            $subscriptionId = (int)($row['id'] ?? 0);
            $recalculated = saas_subscription_recalculate($row);
            $targetStatus = (string)($recalculated['status'] ?? 'trial');

            if (!in_array($targetStatus, ['trial', 'active', 'past_due', 'suspended', 'cancelled'], true)) {
                $targetStatus = 'active';
            }

            if (!in_array($targetStatus, ['trial', 'cancelled', 'suspended'], true)) {
                $stmtInvoice->bind_param('i', $subscriptionId);
                $stmtInvoice->execute();
                $invoiceRow = $stmtInvoice->get_result()->fetch_assoc();
                $dueDate = saas_dt_db((string)($invoiceRow['due_date'] ?? ''));
                if ($dueDate !== null) {
                    $dueTs = strtotime($dueDate);
                    $graceDays = max(0, (int)($row['grace_days'] ?? 7));
                    $graceTs = $dueTs === false ? false : strtotime('+' . $graceDays . ' days', $dueTs);
                    if ($dueTs !== false && $dueTs <= $nowTs) {
                        $targetStatus = 'past_due';
                        $pastDue++;
                        if ($graceTs !== false && $graceTs <= $nowTs) {
                            $targetStatus = 'suspended';
                            $suspended++;
                        }
                    }
                }
            }

            $currentStatus = strtolower(trim((string)($row['status'] ?? 'trial')));
            if ($targetStatus !== $currentStatus) {
                $stmtUpdate->bind_param('sii', $targetStatus, $subscriptionId, $tenantId);
                $stmtUpdate->execute();
                $updated++;
                saas_dispatch_outbound_webhook($controlConn, 'subscription.status_changed', [
                    'subscription_id' => $subscriptionId,
                    'tenant_id' => $tenantId,
                    'from_status' => $currentStatus,
                    'to_status' => $targetStatus,
                    'subscription' => saas_fetch_subscription_snapshot($controlConn, $subscriptionId),
                ], $tenantId, 'تغير حالة الاشتراك');
            }
        }

        $stmtUpdate->close();
        $stmtInvoice->close();
        $stmtSelect->close();

        saas_refresh_current_subscription($controlConn, $tenantId);
        saas_sync_tenant_subscription_snapshot($controlConn, $tenantId);

        $stmtTenant = $controlConn->prepare("
            SELECT t.current_subscription_id, s.status
            FROM saas_tenants t
            LEFT JOIN saas_subscriptions s ON s.id = t.current_subscription_id
            WHERE t.id = ?
            LIMIT 1
        ");
        $stmtTenant->bind_param('i', $tenantId);
        $stmtTenant->execute();
        $tenantRow = $stmtTenant->get_result()->fetch_assoc();
        $stmtTenant->close();
        $currentSubscriptionStatus = strtolower(trim((string)($tenantRow['status'] ?? '')));
        if ($currentSubscriptionStatus === 'suspended') {
            $stmtSuspend = $controlConn->prepare("UPDATE saas_tenants SET status = 'suspended' WHERE id = ? AND status <> 'archived' LIMIT 1");
            $stmtSuspend->bind_param('i', $tenantId);
            $stmtSuspend->execute();
            $stmtSuspend->close();
        }

        return ['updated' => $updated, 'suspended' => $suspended, 'past_due' => $pastDue];
    }
}

if (!function_exists('saas_collect_tenant_ids')) {
    function saas_collect_tenant_ids(mysqli $controlConn): array
    {
        $tenantIds = [];
        $tenantRes = $controlConn->query("SELECT id FROM saas_tenants ORDER BY id ASC");
        while ($tenantRow = $tenantRes->fetch_assoc()) {
            $tenantId = (int)($tenantRow['id'] ?? 0);
            if ($tenantId > 0) {
                $tenantIds[] = $tenantId;
            }
        }
        return $tenantIds;
    }
}

if (!function_exists('saas_generate_due_subscription_invoices')) {
    function saas_generate_due_subscription_invoices(mysqli $controlConn, string $createdBy = 'System', ?string $now = null): array
    {
        $now = $now !== null && trim($now) !== '' ? trim($now) : date('Y-m-d H:i:s');
        $created = 0;
        $existing = 0;
        $skipped = 0;
        $res = $controlConn->query("
            SELECT *
            FROM saas_subscriptions
            WHERE status IN ('active', 'past_due')
              AND COALESCE(ends_at, renews_at) IS NOT NULL
              AND COALESCE(ends_at, renews_at) <= '" . $controlConn->real_escape_string($now) . "'
            ORDER BY tenant_id ASC, id ASC
        ");
        while ($subRow = $res->fetch_assoc()) {
            $result = saas_generate_subscription_invoice($controlConn, $subRow, $createdBy);
            if (!empty($result['ok']) && empty($result['already_exists'])) {
                $created++;
            } elseif (!empty($result['already_exists'])) {
                $existing++;
            } else {
                $skipped++;
            }
        }
        return ['created' => $created, 'existing' => $existing, 'skipped' => $skipped];
    }
}

if (!function_exists('saas_apply_overdue_policy_all')) {
    function saas_apply_overdue_policy_all(mysqli $controlConn): array
    {
        $updated = 0;
        $suspended = 0;
        $pastDue = 0;
        foreach (saas_collect_tenant_ids($controlConn) as $tenantId) {
            $result = saas_apply_overdue_policy_for_tenant($controlConn, $tenantId);
            $updated += (int)($result['updated'] ?? 0);
            $suspended += (int)($result['suspended'] ?? 0);
            $pastDue += (int)($result['past_due'] ?? 0);
        }
        return ['updated' => $updated, 'suspended' => $suspended, 'past_due' => $pastDue];
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

if (!function_exists('app_saas_provision_tenant')) {
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
        $base = rtrim(__DIR__, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'tenant_backups';
        if (!is_dir($base)) {
            @mkdir($base, 0755, true);
        }
        return $base;
    }
}

if (!function_exists('app_saas_export_storage_dir')) {
    function app_saas_export_storage_dir(): string
    {
        $base = rtrim(__DIR__, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'tenant_exports';
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
            VALUES (?, ?, ?, ?, ?, 'provisioning', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, NULL, ?, ?, ?, ?, NULL, ?, ?, ?, NULL, NULL)
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
            'clients' => [
                'label' => 'العملاء',
                'tables' => ['clients'],
            ],
            'suppliers' => [
                'label' => 'الموردين',
                'tables' => ['suppliers'],
            ],
            'warehouses_inventory' => [
                'label' => 'المخازن والأصناف والأرصدة',
                'tables' => ['warehouses', 'inventory_items', 'inventory_stock'],
            ],
            'products' => [
                'label' => 'المنتجات',
                'tables' => ['products'],
            ],
            'employees' => [
                'label' => 'الموظفين',
                'tables' => ['employees'],
            ],
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

        $copied = 0;
        while ($row = $result->fetch_assoc()) {
            $values = [];
            foreach ($columns as $column) {
                $value = $row[$column] ?? null;
                if ($value === null) {
                    $values[] = 'NULL';
                } elseif (is_numeric($value) && !preg_match('/^0[0-9]+/', (string)$value)) {
                    $values[] = (string)$value;
                } else {
                    $values[] = "'" . $targetConn->real_escape_string((string)$value) . "'";
                }
            }
            $valuesSql = implode(', ', $values);
            if (!$targetConn->query("INSERT INTO {$safeTable} ({$columnSql}) VALUES ({$valuesSql})")) {
                throw new RuntimeException('تعذر نسخ سجل إلى جدول ' . $table . ': ' . $targetConn->error);
            }
            $copied++;
        }
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
                $selected[$presetKey] = $presetKey;
            }
        }
        if (empty($selected)) {
            throw new RuntimeException('لم يتم اختيار مجموعات بيانات صالحة للنسخ.');
        }

        $sourceTenantId = (int)($sourceTenant['id'] ?? 0);
        $targetTenantId = (int)($targetTenant['id'] ?? 0);
        if ($sourceTenantId <= 0 || $targetTenantId <= 0 || $sourceTenantId === $targetTenantId) {
            throw new RuntimeException('بيانات النسخ بين المستأجرين غير صالحة.');
        }

        $tableOrder = [];
        foreach (array_keys($selected) as $presetKey) {
            foreach ((array)($presets[$presetKey]['tables'] ?? []) as $table) {
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
            'health' => [
                'severity' => 'ok',
                'runtime_ok' => false,
                'db_ok' => false,
                'issues' => [],
            ],
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
