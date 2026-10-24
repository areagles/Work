<?php

if (!function_exists('app_cloud_sync_allowed_modes')) {
    function app_cloud_sync_allowed_modes(): array
    {
        return ['off', 'push', 'pull', 'bidirectional'];
    }
}

if (!function_exists('app_cloud_sync_allowed_numbering_policies')) {
    function app_cloud_sync_allowed_numbering_policies(): array
    {
        return ['local', 'namespace', 'remote'];
    }
}

if (!function_exists('app_cloud_sync_sanitize_mode')) {
    function app_cloud_sync_sanitize_mode(string $mode): string
    {
        $mode = strtolower(trim($mode));
        return in_array($mode, app_cloud_sync_allowed_modes(), true) ? $mode : 'off';
    }
}

if (!function_exists('app_cloud_sync_sanitize_numbering_policy')) {
    function app_cloud_sync_sanitize_numbering_policy(string $policy): string
    {
        $policy = strtolower(trim($policy));
        return in_array($policy, app_cloud_sync_allowed_numbering_policies(), true) ? $policy : 'namespace';
    }
}

if (!function_exists('app_cloud_sync_sanitize_installation_code')) {
    function app_cloud_sync_sanitize_installation_code(string $code): string
    {
        $code = strtoupper(trim($code));
        $code = preg_replace('/[^A-Z0-9]/', '', $code);
        if ($code === null) {
            $code = '';
        }
        if (strlen($code) > 18) {
            $code = substr($code, 0, 18);
        }
        return $code;
    }
}

if (!function_exists('app_cloud_sync_bool_flag')) {
    function app_cloud_sync_bool_flag($value, int $default = 0): int
    {
        $raw = strtolower(trim((string)$value));
        if ($raw === '') {
            return $default ? 1 : 0;
        }
        return in_array($raw, ['1', 'true', 'yes', 'on'], true) ? 1 : 0;
    }
}

if (!function_exists('app_cloud_sync_default_installation_code')) {
    function app_cloud_sync_default_installation_code(mysqli $conn): string
    {
        $fromEnv = app_cloud_sync_sanitize_installation_code((string)app_env('APP_CLOUD_SYNC_INSTALLATION_CODE', ''));
        if ($fromEnv !== '') {
            return $fromEnv;
        }

        $licenseRow = app_license_row($conn);
        $installationId = app_cloud_sync_sanitize_installation_code((string)($licenseRow['installation_id'] ?? ''));
        if ($installationId !== '') {
            return substr($installationId, 0, 10);
        }

        $host = (string)parse_url(app_base_url(), PHP_URL_HOST);
        $fallback = app_cloud_sync_sanitize_installation_code($host);
        if ($fallback !== '') {
            return substr($fallback, 0, 10);
        }
        return substr(strtoupper(hash('crc32b', app_base_url())), 0, 8);
    }
}

if (!function_exists('app_ensure_cloud_sync_schema')) {
    function app_ensure_cloud_sync_schema(mysqli $conn): bool
    {
        static $ok = null;
        if ($ok !== null) {
            return $ok;
        }

        try {
            $conn->query("
                CREATE TABLE IF NOT EXISTS app_cloud_sync_runtime_log (
                    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    direction ENUM('outgoing','incoming') NOT NULL DEFAULT 'outgoing',
                    status ENUM('success','failed','skipped') NOT NULL DEFAULT 'success',
                    installation_code VARCHAR(60) NOT NULL DEFAULT '',
                    license_key VARCHAR(180) NOT NULL DEFAULT '',
                    source_domain VARCHAR(190) NOT NULL DEFAULT '',
                    sync_mode VARCHAR(20) NOT NULL DEFAULT '',
                    numbering_policy VARCHAR(20) NOT NULL DEFAULT '',
                    payload_hash VARCHAR(80) NOT NULL DEFAULT '',
                    integrity_hash VARCHAR(80) NOT NULL DEFAULT '',
                    details TEXT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    KEY idx_cloud_sync_created (created_at),
                    KEY idx_cloud_sync_installation (installation_code),
                    KEY idx_cloud_sync_license (license_key)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            $ok = true;
        } catch (Throwable $e) {
            error_log('app_ensure_cloud_sync_schema failed: ' . $e->getMessage());
            $ok = false;
        }
        return $ok;
    }
}

if (!function_exists('app_cloud_sync_settings')) {
    function app_cloud_sync_settings(mysqli $conn): array
    {
        app_ensure_cloud_sync_schema($conn);

        $enabled = app_cloud_sync_bool_flag(
            app_setting_get($conn, 'cloud_sync_enabled', (string)app_env('APP_CLOUD_SYNC_ENABLED', '0')),
            0
        );
        $remoteUrl = trim((string)app_setting_get($conn, 'cloud_sync_remote_url', (string)app_env('APP_CLOUD_SYNC_REMOTE_URL', '')));
        $remoteToken = trim((string)app_setting_get($conn, 'cloud_sync_remote_token', (string)app_env('APP_CLOUD_SYNC_REMOTE_TOKEN', '')));
        $apiToken = trim((string)app_setting_get(
            $conn,
            'cloud_sync_api_token',
            (string)app_env('APP_CLOUD_SYNC_API_TOKEN', (string)app_env('APP_LICENSE_API_TOKEN', ''))
        ));
        $installationCode = app_cloud_sync_sanitize_installation_code((string)app_setting_get($conn, 'cloud_sync_installation_code', ''));
        if ($installationCode === '') {
            $installationCode = app_cloud_sync_default_installation_code($conn);
        }

        $mode = app_cloud_sync_sanitize_mode((string)app_setting_get($conn, 'cloud_sync_mode', (string)app_env('APP_CLOUD_SYNC_MODE', 'off')));
        $policy = app_cloud_sync_sanitize_numbering_policy((string)app_setting_get(
            $conn,
            'cloud_sync_numbering_policy',
            (string)app_env('APP_CLOUD_SYNC_NUMBERING_POLICY', 'namespace')
        ));
        $interval = (int)app_setting_get($conn, 'cloud_sync_interval_seconds', (string)app_env('APP_CLOUD_SYNC_INTERVAL_SECONDS', '120'));
        if ($interval < 15) {
            $interval = 15;
        } elseif ($interval > 3600) {
            $interval = 3600;
        }

        $autoOnline = app_cloud_sync_bool_flag(
            app_setting_get($conn, 'cloud_sync_auto_online', (string)app_env('APP_CLOUD_SYNC_AUTO_ONLINE', '1')),
            1
        );
        $verifyFinancial = app_cloud_sync_bool_flag(
            app_setting_get($conn, 'cloud_sync_verify_financial', (string)app_env('APP_CLOUD_SYNC_VERIFY_FINANCIAL', '1')),
            1
        );

        return [
            'enabled' => $enabled,
            'remote_url' => $remoteUrl,
            'remote_token' => $remoteToken,
            'api_token' => $apiToken,
            'installation_code' => $installationCode,
            'sync_mode' => $mode,
            'numbering_policy' => $policy,
            'interval_seconds' => $interval,
            'auto_online' => $autoOnline,
            'verify_financial' => $verifyFinancial,
            'local_db_label' => trim((string)app_setting_get($conn, 'cloud_sync_local_db_label', 'Desktop Local DB')),
            'remote_db_label' => trim((string)app_setting_get($conn, 'cloud_sync_remote_db_label', 'Cloud DB')),
            'last_sync_at' => trim((string)app_setting_get($conn, 'cloud_sync_last_sync_at', '')),
            'last_success_at' => trim((string)app_setting_get($conn, 'cloud_sync_last_success_at', '')),
            'last_error' => trim((string)app_setting_get($conn, 'cloud_sync_last_error', '')),
            'last_integrity_hash' => trim((string)app_setting_get($conn, 'cloud_sync_last_integrity_hash', '')),
        ];
    }
}

if (!function_exists('app_cloud_sync_save_settings')) {
    function app_cloud_sync_save_settings(mysqli $conn, array $payload): array
    {
        $enabled = app_cloud_sync_bool_flag($payload['enabled'] ?? 0, 0);
        $remoteUrl = trim((string)($payload['remote_url'] ?? ''));
        $remoteToken = trim((string)($payload['remote_token'] ?? ''));
        $apiToken = trim((string)($payload['api_token'] ?? ''));
        $installationCode = app_cloud_sync_sanitize_installation_code((string)($payload['installation_code'] ?? ''));
        $syncMode = app_cloud_sync_sanitize_mode((string)($payload['sync_mode'] ?? 'off'));
        $numberingPolicy = app_cloud_sync_sanitize_numbering_policy((string)($payload['numbering_policy'] ?? 'namespace'));
        $interval = (int)($payload['interval_seconds'] ?? 120);
        $autoOnline = app_cloud_sync_bool_flag($payload['auto_online'] ?? 0, 1);
        $verifyFinancial = app_cloud_sync_bool_flag($payload['verify_financial'] ?? 0, 1);
        $localDbLabel = trim((string)($payload['local_db_label'] ?? 'Desktop Local DB'));
        $remoteDbLabel = trim((string)($payload['remote_db_label'] ?? 'Cloud DB'));

        if ($remoteUrl !== '') {
            $scheme = strtolower((string)parse_url($remoteUrl, PHP_URL_SCHEME));
            if (!in_array($scheme, ['http', 'https'], true) || !filter_var($remoteUrl, FILTER_VALIDATE_URL)) {
                return ['ok' => false, 'error' => 'invalid_remote_url'];
            }
        }
        if ($interval < 15) {
            $interval = 15;
        } elseif ($interval > 3600) {
            $interval = 3600;
        }
        if ($installationCode === '') {
            $installationCode = app_cloud_sync_default_installation_code($conn);
        }

        if ($enabled === 1) {
            if ($remoteUrl === '') {
                return ['ok' => false, 'error' => 'remote_url_required'];
            }
            if ($remoteToken === '') {
                return ['ok' => false, 'error' => 'remote_token_required'];
            }
            if ($syncMode === 'off') {
                $syncMode = 'push';
            }
        }

        if (function_exists('mb_substr')) {
            $remoteToken = mb_substr($remoteToken, 0, 220, 'UTF-8');
            $apiToken = mb_substr($apiToken, 0, 220, 'UTF-8');
            $localDbLabel = mb_substr($localDbLabel, 0, 120, 'UTF-8');
            $remoteDbLabel = mb_substr($remoteDbLabel, 0, 120, 'UTF-8');
        } else {
            $remoteToken = substr($remoteToken, 0, 220);
            $apiToken = substr($apiToken, 0, 220);
            $localDbLabel = substr($localDbLabel, 0, 120);
            $remoteDbLabel = substr($remoteDbLabel, 0, 120);
        }

        app_setting_set($conn, 'cloud_sync_enabled', (string)$enabled);
        app_setting_set($conn, 'cloud_sync_remote_url', $remoteUrl);
        app_setting_set($conn, 'cloud_sync_remote_token', $remoteToken);
        app_setting_set($conn, 'cloud_sync_api_token', $apiToken);
        app_setting_set($conn, 'cloud_sync_installation_code', $installationCode);
        app_setting_set($conn, 'cloud_sync_mode', $syncMode);
        app_setting_set($conn, 'cloud_sync_numbering_policy', $numberingPolicy);
        app_setting_set($conn, 'cloud_sync_interval_seconds', (string)$interval);
        app_setting_set($conn, 'cloud_sync_auto_online', (string)$autoOnline);
        app_setting_set($conn, 'cloud_sync_verify_financial', (string)$verifyFinancial);
        app_setting_set($conn, 'cloud_sync_local_db_label', $localDbLabel !== '' ? $localDbLabel : 'Desktop Local DB');
        app_setting_set($conn, 'cloud_sync_remote_db_label', $remoteDbLabel !== '' ? $remoteDbLabel : 'Cloud DB');

        return ['ok' => true, 'settings' => app_cloud_sync_settings($conn)];
    }
}

if (!function_exists('app_cloud_sync_integrity_report')) {
    function app_cloud_sync_integrity_report(mysqli $conn): array
    {
        $checks = [];
        $issues = [];

        if (
            app_table_has_column($conn, 'invoices', 'total_amount')
            && app_table_has_column($conn, 'invoices', 'paid_amount')
            && app_table_has_column($conn, 'invoices', 'remaining_amount')
        ) {
            try {
                $row = $conn->query("
                    SELECT
                        IFNULL(SUM(total_amount), 0) AS total_amount,
                        IFNULL(SUM(paid_amount), 0) AS paid_amount,
                        IFNULL(SUM(remaining_amount), 0) AS remaining_amount,
                        IFNULL(SUM(
                            CASE
                                WHEN paid_amount > total_amount + 0.01
                                  OR remaining_amount < -0.01
                                  OR ABS((total_amount - paid_amount) - remaining_amount) > 0.05
                                THEN 1 ELSE 0
                            END
                        ), 0) AS anomalies
                    FROM invoices
                ")->fetch_assoc();
                $anomalies = (int)($row['anomalies'] ?? 0);
                $checks['invoices'] = [
                    'total_amount' => (float)($row['total_amount'] ?? 0),
                    'paid_amount' => (float)($row['paid_amount'] ?? 0),
                    'remaining_amount' => (float)($row['remaining_amount'] ?? 0),
                    'anomalies' => $anomalies,
                ];
                if ($anomalies > 0) {
                    $issues[] = 'invoice_balance_mismatch:' . $anomalies;
                }
            } catch (Throwable $e) {
                $issues[] = 'invoice_check_failed';
            }
        }

        if (
            app_table_has_column($conn, 'purchase_invoices', 'total_amount')
            && app_table_has_column($conn, 'purchase_invoices', 'paid_amount')
            && app_table_has_column($conn, 'purchase_invoices', 'remaining_amount')
        ) {
            try {
                $row = $conn->query("
                    SELECT
                        IFNULL(SUM(total_amount), 0) AS total_amount,
                        IFNULL(SUM(paid_amount), 0) AS paid_amount,
                        IFNULL(SUM(remaining_amount), 0) AS remaining_amount,
                        IFNULL(SUM(
                            CASE
                                WHEN paid_amount > total_amount + 0.01
                                  OR remaining_amount < -0.01
                                  OR ABS((total_amount - paid_amount) - remaining_amount) > 0.05
                                THEN 1 ELSE 0
                            END
                        ), 0) AS anomalies
                    FROM purchase_invoices
                ")->fetch_assoc();
                $anomalies = (int)($row['anomalies'] ?? 0);
                $checks['purchase_invoices'] = [
                    'total_amount' => (float)($row['total_amount'] ?? 0),
                    'paid_amount' => (float)($row['paid_amount'] ?? 0),
                    'remaining_amount' => (float)($row['remaining_amount'] ?? 0),
                    'anomalies' => $anomalies,
                ];
                if ($anomalies > 0) {
                    $issues[] = 'purchase_balance_mismatch:' . $anomalies;
                }
            } catch (Throwable $e) {
                $issues[] = 'purchase_check_failed';
            }
        }

        if (app_table_has_column($conn, 'inventory_stock', 'quantity')) {
            try {
                $row = $conn->query("
                    SELECT
                        IFNULL(SUM(CASE WHEN quantity < -0.0001 THEN 1 ELSE 0 END), 0) AS negatives,
                        IFNULL(SUM(quantity), 0) AS total_qty
                    FROM inventory_stock
                ")->fetch_assoc();
                $negatives = (int)($row['negatives'] ?? 0);
                $checks['inventory'] = [
                    'total_qty' => (float)($row['total_qty'] ?? 0),
                    'negatives' => $negatives,
                ];
                if ($negatives > 0) {
                    $issues[] = 'inventory_negative_rows:' . $negatives;
                }
            } catch (Throwable $e) {
                $issues[] = 'inventory_check_failed';
            }
        }

        if (app_table_has_column($conn, 'financial_receipts', 'amount')) {
            try {
                $row = $conn->query("
                    SELECT
                        IFNULL(SUM(CASE WHEN amount < 0 THEN 1 ELSE 0 END), 0) AS negatives,
                        IFNULL(SUM(amount), 0) AS total_amount
                    FROM financial_receipts
                ")->fetch_assoc();
                $negatives = (int)($row['negatives'] ?? 0);
                $checks['financial_receipts'] = [
                    'total_amount' => (float)($row['total_amount'] ?? 0),
                    'negatives' => $negatives,
                ];
                if ($negatives > 0) {
                    $issues[] = 'financial_negative_rows:' . $negatives;
                }
            } catch (Throwable $e) {
                $issues[] = 'financial_check_failed';
            }
        }

        $status = empty($issues) ? 'ok' : 'warning';
        $encoded = json_encode([
            'status' => $status,
            'checks' => $checks,
            'issues' => $issues,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $hash = hash('sha256', is_string($encoded) ? $encoded : '');

        return [
            'status' => $status,
            'checks' => $checks,
            'issues' => $issues,
            'hash' => $hash,
            'generated_at' => date('Y-m-d H:i:s'),
        ];
    }
}

if (!function_exists('app_cloud_sync_sequence_snapshot')) {
    function app_cloud_sync_sequence_snapshot(mysqli $conn): array
    {
        app_initialize_document_sequences($conn);
        $rows = [];
        $res = $conn->query("
            SELECT doc_type, prefix, padding, next_number, reset_policy, last_reset_key
            FROM app_document_sequences
            ORDER BY doc_type ASC
        ");
        while ($res && ($row = $res->fetch_assoc())) {
            $docType = strtolower(trim((string)($row['doc_type'] ?? '')));
            if ($docType === '') {
                continue;
            }
            $rows[$docType] = [
                'doc_type' => $docType,
                'prefix' => (string)($row['prefix'] ?? ''),
                'padding' => (int)($row['padding'] ?? 5),
                'next_number' => max(1, (int)($row['next_number'] ?? 1)),
                'reset_policy' => (string)($row['reset_policy'] ?? 'none'),
                'last_reset_key' => (string)($row['last_reset_key'] ?? ''),
            ];
        }
        return $rows;
    }
}

if (!function_exists('app_cloud_sync_usage_counters')) {
    function app_cloud_sync_usage_counters(mysqli $conn): array
    {
        $tables = ['jobs', 'invoices', 'purchase_invoices', 'clients', 'suppliers', 'users', 'inventory_items'];
        $counts = [];
        foreach ($tables as $table) {
            if (!preg_match('/^[a-z0-9_]+$/i', $table)) {
                continue;
            }
            try {
                $row = $conn->query("SELECT COUNT(*) AS c FROM `{$table}`")->fetch_assoc();
                $counts[$table] = (int)($row['c'] ?? 0);
            } catch (Throwable $e) {
                $counts[$table] = null;
            }
        }
        return $counts;
    }
}

if (!function_exists('app_cloud_sync_payload')) {
    function app_cloud_sync_payload(mysqli $conn): array
    {
        $settings = app_cloud_sync_settings($conn);
        $license = app_license_row($conn);
        if ((int)($settings['verify_financial'] ?? 1) === 1) {
            $integrity = app_cloud_sync_integrity_report($conn);
        } else {
            $integrity = [
                'status' => 'disabled',
                'checks' => [],
                'issues' => [],
                'hash' => hash('sha256', 'disabled'),
                'generated_at' => date('Y-m-d H:i:s'),
            ];
        }
        $sequences = app_cloud_sync_sequence_snapshot($conn);

        return [
            'kind' => 'desktop_sync_push',
            'sent_at' => date('c'),
            'app_url' => app_base_url(),
            'domain' => (string)parse_url(app_base_url(), PHP_URL_HOST),
            'edition' => app_license_edition(),
            'installation_code' => (string)$settings['installation_code'],
            'sync_mode' => (string)$settings['sync_mode'],
            'numbering_policy' => (string)$settings['numbering_policy'],
            'license_key' => (string)($license['license_key'] ?? ''),
            'license_status' => (string)($license['license_status'] ?? ''),
            'integrity' => $integrity,
            'sequences' => $sequences,
            'counters' => app_cloud_sync_usage_counters($conn),
        ];
    }
}

if (!function_exists('app_cloud_sync_candidate_urls')) {
    function app_cloud_sync_candidate_urls(string $remoteUrl): array
    {
        $remoteUrl = trim($remoteUrl);
        if ($remoteUrl === '') {
            return [];
        }
        $candidates = [$remoteUrl];
        $parts = parse_url($remoteUrl);
        if (!is_array($parts)) {
            return $candidates;
        }

        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        $host = (string)($parts['host'] ?? '');
        if ($scheme === '' || $host === '') {
            return $candidates;
        }
        $base = $scheme . '://' . $host;
        if (isset($parts['port']) && (int)$parts['port'] > 0) {
            $base .= ':' . (int)$parts['port'];
        }
        $path = (string)($parts['path'] ?? '');
        if (preg_match('#/api/cloud/sync/?$#i', $path)) {
            $candidates[] = rtrim($remoteUrl, '/') . '/index.php';
            $candidates[] = $base . '/cloud_sync_api.php';
        } elseif (preg_match('#/cloud_sync_api\.php/?$#i', $path)) {
            $candidates[] = $base . '/api/cloud/sync';
            $candidates[] = $base . '/api/cloud/sync/index.php';
        } else {
            $candidates[] = $base . '/cloud_sync_api.php';
            $candidates[] = $base . '/api/cloud/sync';
        }

        return array_values(array_unique(array_filter($candidates, static function ($v) {
            return is_string($v) && trim($v) !== '';
        })));
    }
}

if (!function_exists('app_cloud_sync_url_from_license_remote')) {
    function app_cloud_sync_url_from_license_remote(string $licenseRemoteUrl): string
    {
        $url = trim($licenseRemoteUrl);
        if ($url === '') {
            return '';
        }
        $parts = parse_url($url);
        if (!is_array($parts)) {
            return '';
        }
        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        $host = trim((string)($parts['host'] ?? ''));
        if ($host === '' || !in_array($scheme, ['http', 'https'], true)) {
            return '';
        }
        $base = $scheme . '://' . $host;
        if (isset($parts['port']) && (int)$parts['port'] > 0) {
            $base .= ':' . (int)$parts['port'];
        }
        return $base . '/api/cloud/sync';
    }
}

if (!function_exists('app_owner_license_api_url')) {
    function app_owner_license_api_url(): string
    {
        return rtrim((string)app_base_url(), '/') . '/license_api.php';
    }
}

if (!function_exists('app_license_activation_package_payload')) {
    function app_license_activation_package_payload(
        string $remoteUrl,
        string $remoteToken,
        string $licenseKey,
        string $edition = 'client',
        array $extra = []
    ): array {
        $edition = strtolower(trim($edition));
        if (!in_array($edition, ['owner', 'client'], true)) {
            $edition = 'client';
        }

        $remoteUrl = trim($remoteUrl);
        $remoteToken = trim($remoteToken);
        $licenseKey = strtoupper(trim($licenseKey));

        $payload = [
            'version' => 2,
            'edition' => $edition,
            'owner_api_url' => $remoteUrl,
            'owner_api_token' => $remoteToken,
            'license_key' => $licenseKey,
            'mode' => $edition === 'client' ? 'cloud_auto_link' : 'owner',
            'issued_at' => gmdate('c'),
            'cloud_sync_url' => trim((string)($extra['APP_CLOUD_SYNC_REMOTE_URL'] ?? app_cloud_sync_url_from_license_remote($remoteUrl))),
            'cloud_sync_token' => trim((string)($extra['APP_CLOUD_SYNC_REMOTE_TOKEN'] ?? $remoteToken)),
            'owner_base_url' => preg_replace('#/license_api\.php$#i', '', $remoteUrl),
        ];

        if (!empty($extra['client_name'])) {
            $payload['client_name'] = mb_substr(trim((string)$extra['client_name']), 0, 190);
        }
        if (!empty($extra['allowed_domains'])) {
            $payload['allowed_domains'] = trim((string)$extra['allowed_domains']);
        }
        if (!empty($extra['APP_DESKTOP_LINKED_SYSTEM'])) {
            $payload['linked_system'] = trim((string)$extra['APP_DESKTOP_LINKED_SYSTEM']);
        }
        return $payload;
    }
}

if (!function_exists('app_license_activation_package_encoded')) {
    function app_license_activation_package_encoded(
        string $remoteUrl,
        string $remoteToken,
        string $licenseKey,
        string $edition = 'client',
        array $extra = []
    ): string {
        $payload = app_license_activation_package_payload($remoteUrl, $remoteToken, $licenseKey, $edition, $extra);
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json) || $json === '') {
            return '';
        }
        return 'AEAUTO://' . base64_encode($json);
    }
}

if (!function_exists('app_license_activation_package_text')) {
    function app_license_activation_package_text(
        string $remoteUrl,
        string $remoteToken,
        string $licenseKey,
        string $edition = 'client',
        array $extra = []
    ): string {
        $edition = strtolower(trim($edition));
        if (!in_array($edition, ['owner', 'client'], true)) {
            $edition = 'client';
        }

        $remoteUrl = trim($remoteUrl);
        $remoteToken = trim($remoteToken);
        $licenseKey = strtoupper(trim($licenseKey));
        $cloudSyncUrl = trim((string)($extra['APP_CLOUD_SYNC_REMOTE_URL'] ?? ''));
        $cloudSyncToken = trim((string)($extra['APP_CLOUD_SYNC_REMOTE_TOKEN'] ?? ''));
        $linkedSystem = trim((string)($extra['APP_DESKTOP_LINKED_SYSTEM'] ?? ''));
        $superUsername = trim((string)($extra['APP_SUPER_USER_USERNAME'] ?? ''));
        $superEmail = trim((string)($extra['APP_SUPER_USER_EMAIL'] ?? ''));
        $superId = trim((string)($extra['APP_SUPER_USER_ID'] ?? ''));

        if ($cloudSyncUrl === '' && $edition === 'client') {
            $cloudSyncUrl = app_cloud_sync_url_from_license_remote($remoteUrl);
        }
        if ($cloudSyncToken === '' && $edition === 'client') {
            $cloudSyncToken = $remoteToken;
        }

        $autoPackage = app_license_activation_package_encoded($remoteUrl, $remoteToken, $licenseKey, $edition, $extra);

        $lines = [
            'APP_LICENSE_EDITION=' . $edition,
            'APP_LICENSE_REMOTE_URL=' . $remoteUrl,
            'APP_LICENSE_REMOTE_TOKEN=' . $remoteToken,
            'APP_LICENSE_KEY=' . $licenseKey,
            'APP_LICENSE_REMOTE_ONLY=' . ($edition === 'client' ? '1' : '0'),
            'APP_LICENSE_REMOTE_LOCK=0',
        ];

        if ($autoPackage !== '') {
            $lines[] = 'APP_AUTO_LINK_PACKAGE=' . $autoPackage;
        }

        if ($edition === 'client') {
            $lines[] = 'APP_LICENSE_SYNC_INTERVAL_SECONDS=20';
            $lines[] = 'APP_LICENSE_SYNC_ACTIVE_INTERVAL_SECONDS=60';
            $lines[] = 'APP_LICENSE_ENV_AUTOWRITE=1';
        }

        if ($cloudSyncUrl !== '') {
            $lines[] = 'APP_CLOUD_SYNC_REMOTE_URL=' . $cloudSyncUrl;
        }
        if ($cloudSyncToken !== '') {
            $lines[] = 'APP_CLOUD_SYNC_REMOTE_TOKEN=' . $cloudSyncToken;
        }
        if ($linkedSystem !== '') {
            $lines[] = 'APP_DESKTOP_LINKED_SYSTEM=' . $linkedSystem;
        }
        if ($superUsername !== '') {
            $lines[] = 'APP_SUPER_USER_USERNAME=' . $superUsername;
        }
        if ($superEmail !== '') {
            $lines[] = 'APP_SUPER_USER_EMAIL=' . $superEmail;
        }
        if ($superId !== '') {
            $lines[] = 'APP_SUPER_USER_ID=' . $superId;
        }

        return implode("\n", $lines);
    }
}

if (!function_exists('app_license_client_apply_auto_package')) {
    function app_license_client_apply_auto_package(mysqli $conn, string $package): array
    {
        if (app_license_edition() !== 'client') {
            return ['ok' => false, 'error' => 'not_client_edition'];
        }
        $package = trim($package);
        if ($package === '') {
            return ['ok' => false, 'error' => 'empty_package'];
        }
        if (stripos($package, 'AEAUTO://') === 0) {
            $package = substr($package, 9);
        }
        $json = base64_decode($package, true);
        if (!is_string($json) || $json === '') {
            return ['ok' => false, 'error' => 'invalid_package'];
        }
        $payload = json_decode($json, true);
        if (!is_array($payload)) {
            return ['ok' => false, 'error' => 'invalid_package_json'];
        }
        $ownerApiUrl = trim((string)($payload['owner_api_url'] ?? ''));
        $ownerApiToken = trim((string)($payload['owner_api_token'] ?? ''));
        $licenseKey = strtoupper(trim((string)($payload['license_key'] ?? '')));
        $linkedSystem = trim((string)($payload['linked_system'] ?? ''));
        return app_license_client_bootstrap_from_owner($conn, $ownerApiUrl, $ownerApiToken, $licenseKey, [
            'linked_system' => $linkedSystem,
        ]);
    }
}

if (!function_exists('app_license_client_self_heal_connection')) {
    function app_license_client_self_heal_connection(mysqli $conn, bool $forceSync = false): array
    {
        if (app_license_edition() !== 'client') {
            return ['ok' => false, 'error' => 'not_client_edition'];
        }
        app_initialize_license_data($conn);
        $row = app_license_row($conn);
        $remoteUrl = trim((string)($row['remote_url'] ?? app_env('APP_LICENSE_REMOTE_URL', '')));
        $remoteToken = trim((string)($row['remote_token'] ?? app_env('APP_LICENSE_REMOTE_TOKEN', '')));
        if ($remoteUrl === '' || $remoteToken === '') {
            return ['ok' => false, 'error' => 'remote_not_configured'];
        }

        $licenseKey = strtoupper(trim((string)($row['license_key'] ?? '')));
        if ($licenseKey === '' || app_license_token_looks_placeholder($remoteToken) || app_license_url_looks_placeholder($remoteUrl)) {
            return app_license_client_bootstrap_from_owner($conn, $remoteUrl, $remoteToken, $licenseKey);
        }

        $sync = app_license_sync_remote($conn, $forceSync);
        if (!empty($sync['ok']) || !empty($sync['skipped'])) {
            return ['ok' => true, 'mode' => 'sync', 'sync' => $sync];
        }

        $retry = app_license_client_bootstrap_from_owner($conn, $remoteUrl, $remoteToken, $licenseKey);
        if (!empty($retry['ok'])) {
            return $retry + ['mode' => 'bootstrap_after_sync_failure'];
        }

        return ['ok' => false, 'error' => (string)($sync['reason'] ?? $retry['error'] ?? 'unknown'), 'sync' => $sync, 'retry' => $retry];
    }
}

if (!function_exists('app_cloud_sync_is_placeholder_value')) {
    function app_cloud_sync_is_placeholder_value(string $value): bool
    {
        $v = strtolower(trim($value));
        return $v === ''
            || $v === 'change_me'
            || $v === 'put_client_api_token_here'
            || $v === 'https://owner.example.com/api/cloud/sync'
            || $v === 'https://owner.example.com/license_api.php';
    }
}

if (!function_exists('app_cloud_sync_auto_bind_from_license')) {
    function app_cloud_sync_auto_bind_from_license(mysqli $conn, array $licenseRow = []): array
    {
        if (app_license_edition() !== 'client') {
            return ['ok' => false, 'skipped' => true, 'reason' => 'not_client_edition'];
        }

        if (empty($licenseRow)) {
            $licenseRow = app_license_row($conn);
        }

        $licenseRemoteUrl = trim((string)($licenseRow['remote_url'] ?? ''));
        $licenseRemoteToken = trim((string)($licenseRow['remote_token'] ?? ''));
        $derivedCloudUrl = app_cloud_sync_url_from_license_remote($licenseRemoteUrl);

        $settings = app_cloud_sync_settings($conn);
        $currentRemoteUrl = trim((string)($settings['remote_url'] ?? ''));
        $currentRemoteToken = trim((string)($settings['remote_token'] ?? ''));
        $source = trim((string)app_setting_get($conn, 'cloud_sync_link_source', ''));
        $allowAutoOverride = ($source === '' || $source === 'license_auto');

        if (!$allowAutoOverride && !app_cloud_sync_is_placeholder_value($currentRemoteUrl) && !app_cloud_sync_is_placeholder_value($currentRemoteToken)) {
            return ['ok' => true, 'skipped' => true, 'reason' => 'manual_settings_locked'];
        }

        $targetRemoteUrl = $derivedCloudUrl;
        if (app_cloud_sync_is_placeholder_value($targetRemoteUrl)) {
            $targetRemoteUrl = $currentRemoteUrl;
        }

        $targetRemoteToken = $licenseRemoteToken;
        if (app_cloud_sync_is_placeholder_value($targetRemoteToken)) {
            $targetRemoteToken = $currentRemoteToken;
        }

        if (app_cloud_sync_is_placeholder_value($targetRemoteUrl) || app_cloud_sync_is_placeholder_value($targetRemoteToken)) {
            return ['ok' => false, 'skipped' => true, 'reason' => 'missing_remote_settings'];
        }

        $save = app_cloud_sync_save_settings($conn, [
            'enabled' => 1,
            'remote_url' => $targetRemoteUrl,
            'remote_token' => $targetRemoteToken,
            'api_token' => (string)($settings['api_token'] ?? ''),
            'installation_code' => (string)($settings['installation_code'] ?? ''),
            'sync_mode' => (string)($settings['sync_mode'] ?? 'push'),
            'numbering_policy' => (string)($settings['numbering_policy'] ?? 'namespace'),
            'interval_seconds' => (string)($settings['interval_seconds'] ?? 120),
            'auto_online' => (string)($settings['auto_online'] ?? 1),
            'verify_financial' => (string)($settings['verify_financial'] ?? 1),
            'local_db_label' => (string)($settings['local_db_label'] ?? 'Desktop Local DB'),
            'remote_db_label' => (string)($settings['remote_db_label'] ?? 'Cloud DB'),
        ]);

        if (empty($save['ok'])) {
            return ['ok' => false, 'skipped' => false, 'reason' => (string)($save['error'] ?? 'save_failed')];
        }

        app_setting_set($conn, 'cloud_sync_link_source', 'license_auto');
        return ['ok' => true, 'skipped' => false, 'settings' => $save['settings'] ?? []];
    }
}

if (!function_exists('app_cloud_sync_log_runtime')) {
    function app_cloud_sync_log_runtime(
        mysqli $conn,
        string $direction,
        string $status,
        string $installationCode,
        string $licenseKey,
        string $sourceDomain,
        string $syncMode,
        string $numberingPolicy,
        string $payloadHash,
        string $integrityHash,
        string $details = ''
    ): void {
        if (!app_ensure_cloud_sync_schema($conn)) {
            return;
        }

        $direction = in_array($direction, ['outgoing', 'incoming'], true) ? $direction : 'outgoing';
        $status = in_array($status, ['success', 'failed', 'skipped'], true) ? $status : 'success';
        if (function_exists('mb_substr')) {
            $details = mb_substr(trim($details), 0, 4000, 'UTF-8');
        } else {
            $details = substr(trim($details), 0, 4000);
        }

        try {
            $stmt = $conn->prepare("
                INSERT INTO app_cloud_sync_runtime_log (
                    direction, status, installation_code, license_key, source_domain,
                    sync_mode, numbering_policy, payload_hash, integrity_hash, details
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->bind_param(
                'ssssssssss',
                $direction,
                $status,
                $installationCode,
                $licenseKey,
                $sourceDomain,
                $syncMode,
                $numberingPolicy,
                $payloadHash,
                $integrityHash,
                $details
            );
            $stmt->execute();
            $stmt->close();
        } catch (Throwable $e) {
            error_log('app_cloud_sync_log_runtime failed: ' . $e->getMessage());
        }
    }
}

if (!function_exists('app_ensure_cloud_sync_queue_schema')) {
    function app_ensure_cloud_sync_queue_schema(mysqli $conn): bool
    {
        static $ok = null;
        if ($ok !== null) {
            return $ok;
        }
        try {
            $conn->query("
                CREATE TABLE IF NOT EXISTS app_cloud_sync_queue (
                    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    installation_code VARCHAR(60) NOT NULL DEFAULT '',
                    license_key VARCHAR(180) NOT NULL DEFAULT '',
                    source_domain VARCHAR(190) NOT NULL DEFAULT '',
                    sync_mode VARCHAR(20) NOT NULL DEFAULT '',
                    numbering_policy VARCHAR(20) NOT NULL DEFAULT '',
                    payload_json LONGTEXT NOT NULL,
                    payload_hash VARCHAR(80) NOT NULL DEFAULT '',
                    integrity_hash VARCHAR(80) NOT NULL DEFAULT '',
                    queue_status ENUM('pending','processing','sent','failed') NOT NULL DEFAULT 'pending',
                    attempts INT NOT NULL DEFAULT 0,
                    last_error VARCHAR(255) NOT NULL DEFAULT '',
                    available_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    sent_at DATETIME DEFAULT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    KEY idx_cloud_sync_queue_status (queue_status, available_at),
                    KEY idx_cloud_sync_queue_installation (installation_code),
                    KEY idx_cloud_sync_queue_hash (payload_hash)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            $ok = true;
        } catch (Throwable $e) {
            error_log('app_ensure_cloud_sync_queue_schema failed: ' . $e->getMessage());
            $ok = false;
        }
        return $ok;
    }
}

if (!function_exists('app_cloud_sync_queue_enqueue')) {
    function app_cloud_sync_queue_enqueue(
        mysqli $conn,
        array $payload,
        string $payloadHash,
        string $integrityHash,
        string $syncMode,
        string $numberingPolicy,
        string $licenseKey,
        string $domain,
        string $installationCode,
        string $reason = ''
    ): array {
        if (!app_ensure_cloud_sync_queue_schema($conn)) {
            return ['ok' => false, 'error' => 'queue_schema_unavailable'];
        }
        $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($payloadJson) || $payloadJson === '') {
            return ['ok' => false, 'error' => 'payload_encode_failed'];
        }

        try {
            $stmtFind = $conn->prepare("
                SELECT id, attempts
                FROM app_cloud_sync_queue
                WHERE payload_hash = ? AND queue_status IN ('pending','failed','processing')
                ORDER BY id DESC
                LIMIT 1
            ");
            $stmtFind->bind_param('s', $payloadHash);
            $stmtFind->execute();
            $existing = $stmtFind->get_result()->fetch_assoc();
            $stmtFind->close();

            if ($existing) {
                $id = (int)($existing['id'] ?? 0);
                $lastError = trim($reason);
                $status = 'pending';
                $stmtUpd = $conn->prepare("
                    UPDATE app_cloud_sync_queue
                    SET queue_status = ?, last_error = ?, available_at = NOW(), updated_at = NOW()
                    WHERE id = ?
                    LIMIT 1
                ");
                $stmtUpd->bind_param('ssi', $status, $lastError, $id);
                $stmtUpd->execute();
                $stmtUpd->close();
                return ['ok' => true, 'queued' => true, 'id' => $id, 'deduplicated' => true];
            }

            $status = 'pending';
            $error = trim($reason);
            $stmt = $conn->prepare("
                INSERT INTO app_cloud_sync_queue (
                    installation_code, license_key, source_domain, sync_mode, numbering_policy,
                    payload_json, payload_hash, integrity_hash, queue_status, attempts, last_error, available_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, NOW())
            ");
            $stmt->bind_param(
                'ssssssssss',
                $installationCode,
                $licenseKey,
                $domain,
                $syncMode,
                $numberingPolicy,
                $payloadJson,
                $payloadHash,
                $integrityHash,
                $status,
                $error
            );
            $stmt->execute();
            $id = (int)$stmt->insert_id;
            $stmt->close();
            return ['ok' => true, 'queued' => true, 'id' => $id];
        } catch (Throwable $e) {
            error_log('app_cloud_sync_queue_enqueue failed: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'queue_insert_failed'];
        }
    }
}

if (!function_exists('app_cloud_sync_send_payload')) {
    function app_cloud_sync_send_payload(mysqli $conn, array $settings, array $payload, bool $allowPolicyPull = true): array
    {
        $remoteUrl = trim((string)$settings['remote_url']);
        $remoteToken = trim((string)$settings['remote_token']);
        $mode = (string)$settings['sync_mode'];
        $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $payloadHash = hash('sha256', is_string($payloadJson) ? $payloadJson : '');
        $integrityHash = trim((string)($payload['integrity']['hash'] ?? ''));
        $licenseKey = strtoupper(trim((string)($payload['license_key'] ?? '')));
        $domain = (string)($payload['domain'] ?? '');
        $headers = ['Authorization: Bearer ' . $remoteToken];
        $urls = app_cloud_sync_candidate_urls($remoteUrl);
        if (empty($urls)) {
            $urls = [$remoteUrl];
        }

        $lastErr = 'remote_error';
        $responseBody = [];
        foreach ($urls as $url) {
            $http = app_license_http_post_json($url, $payload, $headers, 14);
            if (empty($http['ok'])) {
                $code = (int)($http['http_code'] ?? 0);
                $err = trim((string)($http['error'] ?? ''));
                if ($err === '') {
                    $err = $code > 0 ? ('http_' . $code) : 'remote_error';
                }
                $lastErr = $err;
                continue;
            }
            $decoded = json_decode((string)($http['body'] ?? ''), true);
            if (!is_array($decoded)) {
                $lastErr = 'invalid_json';
                continue;
            }
            if (isset($decoded['ok']) && !$decoded['ok']) {
                $lastErr = (string)($decoded['error'] ?? 'remote_rejected');
                $responseBody = $decoded;
                continue;
            }
            $responseBody = $decoded;
            $lastErr = '';
            break;
        }

        if ($lastErr !== '') {
            return [
                'ok' => false,
                'reason' => $lastErr,
                'payload_hash' => $payloadHash,
                'integrity_hash' => $integrityHash,
                'license_key' => $licenseKey,
                'domain' => $domain,
            ];
        }

        $appliedRules = 0;
        $canPull = $allowPolicyPull && (in_array($mode, ['pull', 'bidirectional'], true) || (string)$settings['numbering_policy'] === 'remote');
        if ($canPull && isset($responseBody['sequence_patch']) && is_array($responseBody['sequence_patch'])) {
            $appliedRules = app_cloud_sync_apply_sequence_patch($conn, $responseBody['sequence_patch']);
        }
        if ($allowPolicyPull && isset($responseBody['policy']) && is_array($responseBody['policy'])) {
            $serverPolicy = app_cloud_sync_sanitize_numbering_policy((string)($responseBody['policy']['numbering_policy'] ?? ''));
            $serverCode = app_cloud_sync_sanitize_installation_code((string)($responseBody['policy']['installation_code'] ?? ''));
            if ($serverPolicy !== '') {
                app_setting_set($conn, 'cloud_sync_numbering_policy', $serverPolicy);
            }
            if ($serverCode !== '') {
                app_setting_set($conn, 'cloud_sync_installation_code', $serverCode);
            }
        }

        return [
            'ok' => true,
            'response' => $responseBody,
            'applied_rules' => $appliedRules,
            'payload_hash' => $payloadHash,
            'integrity_hash' => $integrityHash,
            'license_key' => $licenseKey,
            'domain' => $domain,
        ];
    }
}

if (!function_exists('app_cloud_sync_queue_process')) {
    function app_cloud_sync_queue_process(mysqli $conn, array $settings, int $limit = 20): array
    {
        if (!app_ensure_cloud_sync_queue_schema($conn)) {
            return ['ok' => false, 'error' => 'queue_schema_unavailable'];
        }
        $limit = max(1, min(100, $limit));
        $processed = 0;
        $sent = 0;
        $failed = 0;

        $res = $conn->query("
            SELECT id, payload_json, payload_hash, integrity_hash, installation_code, license_key, source_domain, sync_mode, numbering_policy, attempts
            FROM app_cloud_sync_queue
            WHERE queue_status IN ('pending','failed') AND available_at <= NOW()
            ORDER BY id ASC
            LIMIT {$limit}
        ");
        if (!$res) {
            return ['ok' => false, 'error' => 'queue_select_failed'];
        }
        while ($row = $res->fetch_assoc()) {
            $processed++;
            $id = (int)($row['id'] ?? 0);
            $conn->query("UPDATE app_cloud_sync_queue SET queue_status = 'processing', attempts = attempts + 1 WHERE id = {$id} LIMIT 1");
            $payload = json_decode((string)($row['payload_json'] ?? ''), true);
            if (!is_array($payload)) {
                $conn->query("UPDATE app_cloud_sync_queue SET queue_status = 'failed', last_error = 'invalid_payload_json' WHERE id = {$id} LIMIT 1");
                $failed++;
                continue;
            }
            $result = app_cloud_sync_send_payload($conn, $settings, $payload, false);
            if (!empty($result['ok'])) {
                $conn->query("UPDATE app_cloud_sync_queue SET queue_status = 'sent', last_error = '', sent_at = NOW() WHERE id = {$id} LIMIT 1");
                $sent++;
            } else {
                $reason = trim((string)($result['reason'] ?? 'remote_error'));
                $delayMinutes = min(60, max(1, (int)($row['attempts'] ?? 0) + 1));
                $stmtFail = $conn->prepare("
                    UPDATE app_cloud_sync_queue
                    SET queue_status = 'failed', last_error = ?, available_at = DATE_ADD(NOW(), INTERVAL ? MINUTE)
                    WHERE id = ?
                    LIMIT 1
                ");
                $stmtFail->bind_param('sii', $reason, $delayMinutes, $id);
                $stmtFail->execute();
                $stmtFail->close();
                $failed++;
                break;
            }
        }
        return ['ok' => true, 'processed' => $processed, 'sent' => $sent, 'failed' => $failed];
    }
}

if (!function_exists('app_cloud_sync_apply_sequence_patch')) {
    function app_cloud_sync_apply_sequence_patch(mysqli $conn, $patch): int
    {
        if (!is_array($patch) || empty($patch)) {
            return 0;
        }
        app_initialize_document_sequences($conn);
        $applied = 0;

        foreach ($patch as $key => $row) {
            if (!is_array($row)) {
                if (is_array($patch) && is_string($key)) {
                    $row = ['doc_type' => $key, 'next_number' => (int)$row];
                } else {
                    continue;
                }
            }
            $docType = strtolower(trim((string)($row['doc_type'] ?? $key)));
            if (!preg_match('/^[a-z0-9_]{2,40}$/', $docType)) {
                continue;
            }
            $prefix = (string)($row['prefix'] ?? '');
            $padding = max(1, min(10, (int)($row['padding'] ?? 5)));
            $nextNumber = max(1, (int)($row['next_number'] ?? 1));
            $resetPolicy = trim((string)($row['reset_policy'] ?? 'none'));
            if (!in_array($resetPolicy, ['none', 'yearly', 'monthly'], true)) {
                $resetPolicy = 'none';
            }

            try {
                $stmtSel = $conn->prepare("
                    SELECT next_number, prefix, padding, reset_policy
                    FROM app_document_sequences
                    WHERE doc_type = ?
                    LIMIT 1
                ");
                $stmtSel->bind_param('s', $docType);
                $stmtSel->execute();
                $current = $stmtSel->get_result()->fetch_assoc();
                $stmtSel->close();

                if ($current) {
                    $currentNext = max(1, (int)($current['next_number'] ?? 1));
                    $mergedNext = max($currentNext, $nextNumber);
                    $mergedPrefix = $prefix !== '' ? $prefix : (string)($current['prefix'] ?? '');
                    $mergedPadding = max((int)($current['padding'] ?? 5), $padding);
                    $mergedReset = $resetPolicy !== 'none' ? $resetPolicy : (string)($current['reset_policy'] ?? 'none');
                    $stmtUpd = $conn->prepare("
                        UPDATE app_document_sequences
                        SET prefix = ?, padding = ?, next_number = ?, reset_policy = ?
                        WHERE doc_type = ?
                    ");
                    $stmtUpd->bind_param('siiss', $mergedPrefix, $mergedPadding, $mergedNext, $mergedReset, $docType);
                    $stmtUpd->execute();
                    $stmtUpd->close();
                } else {
                    $stmtIns = $conn->prepare("
                        INSERT INTO app_document_sequences (doc_type, prefix, padding, next_number, reset_policy, last_reset_key)
                        VALUES (?, ?, ?, ?, ?, '')
                    ");
                    $stmtIns->bind_param('ssiis', $docType, $prefix, $padding, $nextNumber, $resetPolicy);
                    $stmtIns->execute();
                    $stmtIns->close();
                }
                $applied++;
            } catch (Throwable $e) {
                error_log('app_cloud_sync_apply_sequence_patch failed for ' . $docType . ': ' . $e->getMessage());
            }
        }

        return $applied;
    }
}

if (!function_exists('app_cloud_sync_run')) {
    function app_cloud_sync_run(mysqli $conn, bool $force = false): array
    {
        $settings = app_cloud_sync_settings($conn);
        $mode = (string)$settings['sync_mode'];
        if ((int)$settings['enabled'] !== 1 || $mode === 'off') {
            app_cloud_sync_log_runtime(
                $conn,
                'outgoing',
                'skipped',
                (string)$settings['installation_code'],
                '',
                (string)parse_url(app_base_url(), PHP_URL_HOST),
                $mode,
                (string)$settings['numbering_policy'],
                '',
                '',
                'disabled_or_off'
            );
            return ['ok' => false, 'skipped' => true, 'reason' => 'disabled_or_off'];
        }

        $remoteUrl = trim((string)$settings['remote_url']);
        $remoteToken = trim((string)$settings['remote_token']);
        if ($remoteUrl === '' || $remoteToken === '') {
            app_setting_set($conn, 'cloud_sync_last_error', 'remote_not_configured');
            return ['ok' => false, 'skipped' => false, 'reason' => 'remote_not_configured'];
        }

        $lastSyncAt = trim((string)$settings['last_sync_at']);
        if (!$force && $lastSyncAt !== '') {
            $lastSyncTs = strtotime($lastSyncAt);
            if ($lastSyncTs !== false) {
                $elapsed = time() - $lastSyncTs;
                if ($elapsed >= 0 && $elapsed < (int)$settings['interval_seconds']) {
                    return ['ok' => true, 'skipped' => true, 'reason' => 'interval_guard'];
                }
            }
        }

        $payload = app_cloud_sync_payload($conn);
        $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $payloadHash = hash('sha256', is_string($payloadJson) ? $payloadJson : '');
        $integrityHash = (string)($payload['integrity']['hash'] ?? '');
        $licenseKey = strtoupper(trim((string)($payload['license_key'] ?? '')));
        $domain = (string)($payload['domain'] ?? '');
        $now = date('Y-m-d H:i:s');

        app_setting_set($conn, 'cloud_sync_last_sync_at', $now);

        $queueProcess = app_cloud_sync_queue_process($conn, $settings, 20);
        if (!empty($queueProcess['ok']) && !empty($queueProcess['sent'])) {
            app_setting_set($conn, 'cloud_sync_queue_last_flush_at', $now);
        }

        $send = app_cloud_sync_send_payload($conn, $settings, $payload, true);
        if (empty($send['ok'])) {
            if (function_exists('app_desktop_node_is_local_runtime') && app_desktop_node_is_local_runtime($conn)) {
                app_cloud_sync_queue_enqueue(
                    $conn,
                    $payload,
                    $payloadHash,
                    $integrityHash,
                    $mode,
                    (string)$settings['numbering_policy'],
                    $licenseKey,
                    $domain,
                    (string)$settings['installation_code'],
                    (string)($send['reason'] ?? 'remote_error')
                );
            }
            $lastErr = (string)($send['reason'] ?? 'remote_error');
            app_setting_set($conn, 'cloud_sync_last_error', $lastErr);
            app_cloud_sync_log_runtime(
                $conn,
                'outgoing',
                'failed',
                (string)$settings['installation_code'],
                $licenseKey,
                $domain,
                $mode,
                (string)$settings['numbering_policy'],
                $payloadHash,
                $integrityHash,
                $lastErr
            );
            return ['ok' => false, 'skipped' => false, 'reason' => $lastErr];
        }

        $responseBody = (array)($send['response'] ?? []);
        $appliedRules = (int)($send['applied_rules'] ?? 0);

        app_setting_set($conn, 'cloud_sync_last_success_at', $now);
        app_setting_set($conn, 'cloud_sync_last_error', '');
        app_setting_set($conn, 'cloud_sync_last_integrity_hash', $integrityHash);

        app_cloud_sync_log_runtime(
            $conn,
            'outgoing',
            'success',
            (string)$settings['installation_code'],
            $licenseKey,
            $domain,
            $mode,
            (string)$settings['numbering_policy'],
            $payloadHash,
            $integrityHash,
            'rules_applied=' . $appliedRules
        );

        return [
            'ok' => true,
            'skipped' => false,
            'reason' => 'synced',
            'applied_rules' => $appliedRules,
            'response' => $responseBody,
            'queue' => $queueProcess,
        ];
    }
}

if (!function_exists('app_cloud_sync_api_expected_token')) {
    function app_cloud_sync_api_expected_token(mysqli $conn): string
    {
        $settings = app_cloud_sync_settings($conn);
        $token = trim((string)$settings['api_token']);
        if ($token !== '') {
            return $token;
        }
        return trim((string)app_env('APP_CLOUD_SYNC_API_TOKEN', (string)app_env('APP_LICENSE_API_TOKEN', '')));
    }
}

if (!function_exists('app_cloud_sync_api_exchange')) {
    function app_cloud_sync_api_exchange(mysqli $conn, array $payload, string $bearerToken = ''): array
    {
        $expectedToken = app_cloud_sync_api_expected_token($conn);
        if ($expectedToken !== '' && !hash_equals($expectedToken, trim($bearerToken))) {
            return [
                'http_code' => 403,
                'body' => ['ok' => false, 'error' => 'access_denied'],
            ];
        }

        $settings = app_cloud_sync_settings($conn);
        $incomingInstall = app_cloud_sync_sanitize_installation_code((string)($payload['installation_code'] ?? ''));
        $incomingLicense = strtoupper(trim((string)($payload['license_key'] ?? '')));
        $incomingDomain = trim((string)($payload['domain'] ?? ''));
        $incomingMode = app_cloud_sync_sanitize_mode((string)($payload['sync_mode'] ?? 'off'));
        $incomingPolicy = app_cloud_sync_sanitize_numbering_policy((string)($payload['numbering_policy'] ?? 'namespace'));
        $incomingIntegrityHash = trim((string)($payload['integrity']['hash'] ?? ''));
        $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $payloadHash = hash('sha256', is_string($payloadJson) ? $payloadJson : '');

        $details = '';
        if (isset($payload['integrity']['issues']) && is_array($payload['integrity']['issues'])) {
            $details = implode(', ', array_map('strval', $payload['integrity']['issues']));
        }

        app_cloud_sync_log_runtime(
            $conn,
            'incoming',
            'success',
            $incomingInstall,
            $incomingLicense,
            $incomingDomain,
            $incomingMode,
            $incomingPolicy,
            $payloadHash,
            $incomingIntegrityHash,
            $details
        );

        $sequencePatch = app_cloud_sync_sequence_snapshot($conn);
        return [
            'http_code' => 200,
            'body' => [
                'ok' => true,
                'server_time' => date('c'),
                'policy' => [
                    'numbering_policy' => (string)$settings['numbering_policy'],
                    'installation_code' => (string)$settings['installation_code'],
                ],
                'sequence_patch' => $sequencePatch,
                'message' => 'sync_received',
            ],
        ];
    }
}

if (!function_exists('app_cloud_sync_apply_numbering_policy')) {
    function app_cloud_sync_apply_numbering_policy(mysqli $conn, string $docNumber, string $docType = ''): string
    {
        $docNumber = trim($docNumber);
        if ($docNumber === '') {
            return '';
        }

        $settings = app_cloud_sync_settings($conn);
        if ((int)$settings['enabled'] !== 1) {
            return $docNumber;
        }
        if ((string)$settings['numbering_policy'] !== 'namespace') {
            return $docNumber;
        }

        $installationCode = app_cloud_sync_sanitize_installation_code((string)$settings['installation_code']);
        if ($installationCode === '') {
            return $docNumber;
        }
        $prefix = $installationCode . '-';
        if (strpos($docNumber, $prefix) === 0) {
            return $docNumber;
        }
        return $prefix . $docNumber;
    }
}

if (!function_exists('app_ensure_license_schema')) {
    function app_ensure_license_schema(mysqli $conn): bool
    {
        static $ok = null;
        if ($ok !== null) {
            return $ok;
        }
        try {
            $conn->query("
                CREATE TABLE IF NOT EXISTS app_license_state (
                    id TINYINT UNSIGNED PRIMARY KEY,
                    installation_id VARCHAR(80) NOT NULL DEFAULT '',
                    fingerprint VARCHAR(80) NOT NULL DEFAULT '',
                    license_key VARCHAR(180) NOT NULL DEFAULT '',
                    plan_type ENUM('trial','subscription','lifetime') NOT NULL DEFAULT 'trial',
                    license_status ENUM('active','expired','suspended') NOT NULL DEFAULT 'active',
                    trial_started_at DATETIME DEFAULT NULL,
                    trial_ends_at DATETIME DEFAULT NULL,
                    subscription_ends_at DATETIME DEFAULT NULL,
                    grace_days INT NOT NULL DEFAULT 3,
                    owner_name VARCHAR(150) NOT NULL DEFAULT '',
                    remote_url VARCHAR(255) NOT NULL DEFAULT '',
                    remote_token VARCHAR(190) NOT NULL DEFAULT '',
                    last_check_at DATETIME DEFAULT NULL,
                    last_success_at DATETIME DEFAULT NULL,
                    last_error VARCHAR(255) NOT NULL DEFAULT '',
                    metadata_json TEXT DEFAULT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            if (!app_table_has_column($conn, 'app_license_state', 'installation_id')) {
                $conn->query("ALTER TABLE app_license_state ADD COLUMN installation_id VARCHAR(80) NOT NULL DEFAULT ''");
            }
            if (!app_table_has_column($conn, 'app_license_state', 'fingerprint')) {
                $conn->query("ALTER TABLE app_license_state ADD COLUMN fingerprint VARCHAR(80) NOT NULL DEFAULT ''");
            }
            if (!app_table_has_column($conn, 'app_license_state', 'license_key')) {
                $conn->query("ALTER TABLE app_license_state ADD COLUMN license_key VARCHAR(180) NOT NULL DEFAULT ''");
            }
            if (!app_table_has_column($conn, 'app_license_state', 'plan_type')) {
                $conn->query("ALTER TABLE app_license_state ADD COLUMN plan_type ENUM('trial','subscription','lifetime') NOT NULL DEFAULT 'trial'");
            }
            if (!app_table_has_column($conn, 'app_license_state', 'license_status')) {
                $conn->query("ALTER TABLE app_license_state ADD COLUMN license_status ENUM('active','expired','suspended') NOT NULL DEFAULT 'active'");
            }
            if (!app_table_has_column($conn, 'app_license_state', 'trial_started_at')) {
                $conn->query("ALTER TABLE app_license_state ADD COLUMN trial_started_at DATETIME DEFAULT NULL");
            }
            if (!app_table_has_column($conn, 'app_license_state', 'trial_ends_at')) {
                $conn->query("ALTER TABLE app_license_state ADD COLUMN trial_ends_at DATETIME DEFAULT NULL");
            }
            if (!app_table_has_column($conn, 'app_license_state', 'subscription_ends_at')) {
                $conn->query("ALTER TABLE app_license_state ADD COLUMN subscription_ends_at DATETIME DEFAULT NULL");
            }
            if (!app_table_has_column($conn, 'app_license_state', 'grace_days')) {
                $conn->query("ALTER TABLE app_license_state ADD COLUMN grace_days INT NOT NULL DEFAULT 3");
            }
            if (!app_table_has_column($conn, 'app_license_state', 'owner_name')) {
                $conn->query("ALTER TABLE app_license_state ADD COLUMN owner_name VARCHAR(150) NOT NULL DEFAULT ''");
            }
            if (!app_table_has_column($conn, 'app_license_state', 'remote_url')) {
                $conn->query("ALTER TABLE app_license_state ADD COLUMN remote_url VARCHAR(255) NOT NULL DEFAULT ''");
            }
            if (!app_table_has_column($conn, 'app_license_state', 'remote_token')) {
                $conn->query("ALTER TABLE app_license_state ADD COLUMN remote_token VARCHAR(190) NOT NULL DEFAULT ''");
            }
            if (!app_table_has_column($conn, 'app_license_state', 'last_check_at')) {
                $conn->query("ALTER TABLE app_license_state ADD COLUMN last_check_at DATETIME DEFAULT NULL");
            }
            if (!app_table_has_column($conn, 'app_license_state', 'last_success_at')) {
                $conn->query("ALTER TABLE app_license_state ADD COLUMN last_success_at DATETIME DEFAULT NULL");
            }
            if (!app_table_has_column($conn, 'app_license_state', 'last_error')) {
                $conn->query("ALTER TABLE app_license_state ADD COLUMN last_error VARCHAR(255) NOT NULL DEFAULT ''");
            }
            if (!app_table_has_column($conn, 'app_license_state', 'metadata_json')) {
                $conn->query("ALTER TABLE app_license_state ADD COLUMN metadata_json TEXT DEFAULT NULL");
            }
            $ok = true;
        } catch (Throwable $e) {
            error_log('app_ensure_license_schema failed: ' . $e->getMessage());
            $ok = false;
        }
        return $ok;
    }
}

if (!function_exists('app_license_installation_fingerprint')) {
    function app_license_installation_fingerprint(mysqli $conn): string
    {
        $host = (string)parse_url(app_base_url(), PHP_URL_HOST);
        $dbName = (string)($conn->query("SELECT DATABASE() AS dbn")->fetch_assoc()['dbn'] ?? '');
        $node = php_uname('n');
        $seed = $host . '|' . $dbName . '|' . $node . '|' . __DIR__;
        return substr(hash('sha256', $seed), 0, 64);
    }
}

if (!function_exists('app_license_remote_only_mode')) {
    function app_license_remote_only_mode(): bool
    {
        // Owner edition defaults to local control allowed; client edition defaults to remote-only.
        return app_env_flag('APP_LICENSE_REMOTE_ONLY', app_license_edition() !== 'owner');
    }
}

if (!function_exists('app_license_remote_lock_mode')) {
    function app_license_remote_lock_mode(): bool
    {
        // New pull-only architecture keeps remote settings dynamic by default.
        // Set APP_LICENSE_REMOTE_LOCK=1 only when you intentionally want hard static values from env.
        return app_env_flag('APP_LICENSE_REMOTE_LOCK', false);
    }
}

if (!function_exists('app_license_client_strict_enforcement')) {
    function app_license_client_strict_enforcement(): bool
    {
        return app_env_flag('APP_LICENSE_STRICT_ENFORCEMENT', true);
    }
}

if (!function_exists('app_license_owner_lab_unlock')) {
    function app_license_owner_lab_unlock(): bool
    {
        return app_license_edition() === 'owner' && app_env_flag('APP_LICENSE_OWNER_LAB_UNLOCK', false);
    }
}

if (!function_exists('app_license_is_placeholder_remote_host')) {
    function app_license_is_placeholder_remote_host(string $host): bool
    {
        $host = app_license_normalize_domain($host);
        if ($host === '') {
            return true;
        }
        if (in_array($host, ['example.com', 'www.example.com', 'client.example.com', 'owner.example.com'], true)) {
            return true;
        }
        return false;
    }
}

if (!function_exists('app_license_url_looks_placeholder')) {
    function app_license_url_looks_placeholder(string $url): bool
    {
        $url = trim($url);
        if ($url === '') {
            return true;
        }
        $parts = parse_url($url);
        if (!is_array($parts)) {
            return false;
        }
        $host = (string)($parts['host'] ?? '');
        if ($host === '') {
            return false;
        }
        return app_license_is_placeholder_remote_host($host);
    }
}

if (!function_exists('app_license_token_looks_placeholder')) {
    function app_license_token_looks_placeholder(string $token): bool
    {
        $v = strtoupper(trim($token));
        return in_array($v, ['', 'CHANGE_ME', 'PUT_CLIENT_API_TOKEN_HERE', 'PUT_STRONG_TOKEN_HERE'], true);
    }
}

if (!function_exists('app_license_host_is_local_or_private')) {
    function app_license_host_is_local_or_private(string $host): bool
    {
        $host = strtolower(trim($host));
        if ($host === '' || $host === 'localhost' || $host === '127.0.0.1' || $host === '::1') {
            return true;
        }
        if (substr($host, -6) === '.local' || substr($host, -4) === '.lan') {
            return true;
        }
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            if (preg_match('/^(10|127)\./', $host)) {
                return true;
            }
            if (preg_match('/^192\.168\./', $host)) {
                return true;
            }
            if (preg_match('/^172\.(1[6-9]|2[0-9]|3[0-1])\./', $host)) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('app_license_url_is_local_or_private')) {
    function app_license_url_is_local_or_private(string $url): bool
    {
        $url = trim($url);
        if ($url === '') {
            return true;
        }
        $parts = parse_url($url);
        if (!is_array($parts)) {
            return false;
        }
        $host = trim((string)($parts['host'] ?? ''));
        if ($host === '') {
            return false;
        }
        return app_license_host_is_local_or_private($host);
    }
}

if (!function_exists('app_license_guess_remote_from_cloud_sync')) {
    function app_license_guess_remote_from_cloud_sync(mysqli $conn): array
    {
        $syncUrl = trim((string)app_setting_get($conn, 'cloud_sync_remote_url', (string)app_env('APP_CLOUD_SYNC_REMOTE_URL', '')));
        $syncToken = trim((string)app_setting_get($conn, 'cloud_sync_remote_token', (string)app_env('APP_CLOUD_SYNC_REMOTE_TOKEN', '')));
        if (app_license_url_looks_placeholder($syncUrl) || app_license_url_is_local_or_private($syncUrl)) {
            $syncUrl = '';
        }
        if (app_license_token_looks_placeholder($syncToken)) {
            $syncToken = '';
        }

        $licenseUrl = '';
        if ($syncUrl !== '') {
            $parts = parse_url($syncUrl);
            if (is_array($parts)) {
                $scheme = strtolower((string)($parts['scheme'] ?? ''));
                $host = trim((string)($parts['host'] ?? ''));
                if ($host !== '' && in_array($scheme, ['http', 'https'], true) && !app_license_host_is_local_or_private($host)) {
                    $base = $scheme . '://' . $host;
                    if (isset($parts['port']) && (int)$parts['port'] > 0) {
                        $base .= ':' . (int)$parts['port'];
                    }
                    $licenseUrl = $base . '/license_api.php';
                }
            }
        }

        return [
            'remote_url' => $licenseUrl,
            'remote_token' => $syncToken,
        ];
    }
}

if (!function_exists('app_license_env_remote')) {
    function app_license_env_remote(): array
    {
        $url = trim((string)app_env('APP_LICENSE_REMOTE_URL', ''));
        $token = trim((string)app_env('APP_LICENSE_REMOTE_TOKEN', ''));
        if (app_license_url_looks_placeholder($url)) {
            $url = '';
        }
        if (app_license_token_looks_placeholder($token)) {
            $token = '';
        }
        return [
            'url' => $url,
            'token' => $token,
        ];
    }
}

if (!function_exists('app_license_env_key')) {
    function app_license_env_key(): string
    {
        $value = strtoupper(trim((string)app_env('APP_LICENSE_KEY', '')));
        if (in_array($value, ['', 'SET_CLIENT_LICENSE_KEY', 'SET_CLIENT_LICENSE_KEY_HERE', 'PUT_CLIENT_LICENSE_KEY_HERE'], true)) {
            return '';
        }
        return $value;
    }
}

if (!function_exists('app_license_generate_auto_key')) {
    function app_license_generate_auto_key(string $installationId, string $fingerprint): string
    {
        $seed = strtoupper(trim($installationId)) . '|' . strtoupper(trim($fingerprint));
        $hash = strtoupper(substr(hash('sha256', $seed), 0, 12));
        return 'AE-CLI-' . $hash;
    }
}

if (!function_exists('app_license_apply_remote_overrides')) {
    function app_license_apply_remote_overrides(array $row): array
    {
        $currentUrl = trim((string)($row['remote_url'] ?? ''));
        $currentToken = trim((string)($row['remote_token'] ?? ''));
        if (app_license_url_looks_placeholder($currentUrl)) {
            $row['remote_url'] = '';
            $currentUrl = '';
        }
        if (app_license_token_looks_placeholder($currentToken)) {
            $row['remote_token'] = '';
            $currentToken = '';
        }

        $envRemote = app_license_env_remote();
        $remoteLocked = app_license_remote_lock_mode();
        if ($envRemote['url'] !== '') {
            if ($remoteLocked || $currentUrl === '') {
                $row['remote_url'] = $envRemote['url'];
                $currentUrl = $envRemote['url'];
            }
        }
        if ($envRemote['token'] !== '') {
            if ($remoteLocked || $currentToken === '') {
                $row['remote_token'] = $envRemote['token'];
                $currentToken = $envRemote['token'];
            }
        }
        $envKey = app_license_env_key();
        if ($envKey !== '') {
            $currentKey = strtoupper(trim((string)($row['license_key'] ?? '')));
            if ($remoteLocked || $currentKey === '') {
                $row['license_key'] = $envKey;
            }
        }
        return $row;
    }
}

if (!function_exists('app_initialize_license_data')) {
    function app_initialize_license_data(mysqli $conn): void
    {
        static $booted = false;
        if ($booted) {
            return;
        }
        $booted = true;
        if (!app_ensure_license_schema($conn)) {
            return;
        }

        try {
            $edition = app_license_edition();
            $row = $conn->query("SELECT * FROM app_license_state WHERE id = 1 LIMIT 1")->fetch_assoc();
            $now = date('Y-m-d H:i:s');
            $fingerprint = app_license_installation_fingerprint($conn);
            $envRemote = app_license_env_remote();
            if (!$row) {
                $installationId = substr(bin2hex(random_bytes(16)), 0, 32);
                $isOwnerEdition = $edition === 'owner';
                if ($isOwnerEdition) {
                    $stmt = $conn->prepare("
                        INSERT INTO app_license_state (
                            id, installation_id, fingerprint, license_key, plan_type, license_status,
                            trial_started_at, trial_ends_at, subscription_ends_at, grace_days, owner_name,
                            remote_url, remote_token
                        ) VALUES (1, ?, ?, '', 'lifetime', 'active', NULL, NULL, NULL, 3, '', ?, ?)
                    ");
                    $stmt->bind_param('ssss', $installationId, $fingerprint, $envRemote['url'], $envRemote['token']);
                } else {
                    $trialEndsAt = date('Y-m-d H:i:s', time() + (14 * 86400));
                    $defaultClientStatus = app_license_default_client_status();
                    $stmt = $conn->prepare("
                        INSERT INTO app_license_state (
                            id, installation_id, fingerprint, license_key, plan_type, license_status,
                            trial_started_at, trial_ends_at, subscription_ends_at, grace_days, owner_name,
                            remote_url, remote_token
                        ) VALUES (1, ?, ?, '', 'trial', ?, ?, ?, NULL, 3, '', ?, ?)
                    ");
                    $stmt->bind_param('sssssss', $installationId, $fingerprint, $defaultClientStatus, $now, $trialEndsAt, $envRemote['url'], $envRemote['token']);
                }
                $stmt->execute();
                $stmt->close();
                return;
            }

            $installationId = trim((string)($row['installation_id'] ?? ''));
            if ($installationId === '') {
                $installationId = substr(bin2hex(random_bytes(16)), 0, 32);
                $stmt = $conn->prepare("UPDATE app_license_state SET installation_id = ? WHERE id = 1");
                $stmt->bind_param('s', $installationId);
                $stmt->execute();
                $stmt->close();
            }
            if (trim((string)($row['fingerprint'] ?? '')) === '') {
                $stmt = $conn->prepare("UPDATE app_license_state SET fingerprint = ? WHERE id = 1");
                $stmt->bind_param('s', $fingerprint);
                $stmt->execute();
                $stmt->close();
            }
            if (
                $edition === 'client'
                && trim((string)($row['license_key'] ?? '')) === ''
                && app_env_flag('APP_LICENSE_AUTO_KEY', true)
            ) {
                $autoKey = app_license_generate_auto_key($installationId, $fingerprint);
                $stmt = $conn->prepare("UPDATE app_license_state SET license_key = ? WHERE id = 1");
                $stmt->bind_param('s', $autoKey);
                $stmt->execute();
                $stmt->close();
                $row['license_key'] = $autoKey;
            }
            // Important: do NOT overwrite runtime DB link credentials from .app_env on every request.
            // Env values should seed empty DB values (or enforce when explicit lock mode is enabled).
            $remoteLocked = app_license_remote_lock_mode();
            if ($envRemote['url'] !== '' || $envRemote['token'] !== '') {
                $dbRemoteUrl = trim((string)($row['remote_url'] ?? ''));
                $dbRemoteToken = trim((string)($row['remote_token'] ?? ''));
                $targetUrl = $dbRemoteUrl;
                $targetToken = $dbRemoteToken;
                if ($remoteLocked || $dbRemoteUrl === '') {
                    $targetUrl = $envRemote['url'] !== '' ? $envRemote['url'] : $dbRemoteUrl;
                }
                if ($remoteLocked || $dbRemoteToken === '') {
                    $targetToken = $envRemote['token'] !== '' ? $envRemote['token'] : $dbRemoteToken;
                }
                if ($targetUrl !== $dbRemoteUrl || $targetToken !== $dbRemoteToken) {
                    $stmt = $conn->prepare("UPDATE app_license_state SET remote_url = ?, remote_token = ? WHERE id = 1");
                    $stmt->bind_param('ss', $targetUrl, $targetToken);
                    $stmt->execute();
                    $stmt->close();
                    $row['remote_url'] = $targetUrl;
                    $row['remote_token'] = $targetToken;
                }
            }
            $envKey = app_license_env_key();
            $dbLicenseKey = strtoupper(trim((string)($row['license_key'] ?? '')));
            if ($envKey !== '' && ($remoteLocked || $dbLicenseKey === '')) {
                if ($dbLicenseKey !== $envKey) {
                    $stmt = $conn->prepare("UPDATE app_license_state SET license_key = ? WHERE id = 1");
                    $stmt->bind_param('s', $envKey);
                    $stmt->execute();
                    $stmt->close();
                    $row['license_key'] = $envKey;
                }
            }
            if ($edition === 'owner' && (string)($row['plan_type'] ?? 'trial') === 'trial') {
                $stmt = $conn->prepare("
                    UPDATE app_license_state
                    SET plan_type = 'lifetime',
                        license_status = 'active',
                        trial_started_at = NULL,
                        trial_ends_at = NULL,
                        subscription_ends_at = NULL,
                        last_error = ''
                    WHERE id = 1
                ");
                $stmt->execute();
                $stmt->close();
            } elseif ((string)($row['plan_type'] ?? 'trial') === 'trial') {
                $trialStarted = trim((string)($row['trial_started_at'] ?? ''));
                $trialEnds = trim((string)($row['trial_ends_at'] ?? ''));
                if ($trialStarted === '') {
                    $trialStarted = $now;
                }
                if ($trialEnds === '') {
                    $trialEnds = date('Y-m-d H:i:s', strtotime($trialStarted . ' +14 days'));
                }
                $stmt = $conn->prepare("UPDATE app_license_state SET trial_started_at = ?, trial_ends_at = ? WHERE id = 1");
                $stmt->bind_param('ss', $trialStarted, $trialEnds);
                $stmt->execute();
                $stmt->close();
            }
        } catch (Throwable $e) {
            error_log('app_initialize_license_data failed: ' . $e->getMessage());
        }
    }
}

if (!function_exists('app_license_row')) {
    function app_license_row(mysqli $conn): array
    {
        app_initialize_license_data($conn);
        try {
            $row = $conn->query("SELECT * FROM app_license_state WHERE id = 1 LIMIT 1")->fetch_assoc();
            if (!is_array($row)) {
                return [];
            }
            return app_license_apply_remote_overrides($row);
        } catch (Throwable $e) {
            return [];
        }
    }
}

if (!function_exists('app_license_client_runtime_env_updates')) {
    function app_license_client_runtime_env_updates(array $row): array
    {
        $licenseKey = strtoupper(trim((string)($row['license_key'] ?? '')));
        $remoteUrl = trim((string)($row['remote_url'] ?? ''));
        $remoteToken = trim((string)($row['remote_token'] ?? ''));
        if ($licenseKey === '' || $remoteUrl === '' || $remoteToken === '') {
            return [];
        }

        $updates = [
            'APP_LICENSE_EDITION' => 'client',
            'APP_LICENSE_KEY' => $licenseKey,
            'APP_LICENSE_REMOTE_URL' => $remoteUrl,
            'APP_LICENSE_REMOTE_TOKEN' => $remoteToken,
            'APP_LICENSE_REMOTE_ONLY' => app_license_remote_only_mode() ? '1' : '0',
            'APP_LICENSE_REMOTE_LOCK' => app_license_remote_lock_mode() ? '1' : '0',
        ];

        $fastInterval = max(10, min(900, (int)app_env('APP_LICENSE_SYNC_INTERVAL_SECONDS', '20')));
        $activeInterval = max($fastInterval, min(3600, (int)app_env('APP_LICENSE_SYNC_ACTIVE_INTERVAL_SECONDS', '60')));
        $updates['APP_LICENSE_SYNC_INTERVAL_SECONDS'] = (string)$fastInterval;
        $updates['APP_LICENSE_SYNC_ACTIVE_INTERVAL_SECONDS'] = (string)$activeInterval;

        return $updates;
    }
}

if (!function_exists('app_license_client_persist_runtime_env')) {
    function app_license_client_persist_runtime_env(array $row): array
    {
        if (app_license_edition() !== 'client') {
            return ['ok' => false, 'skipped' => true, 'reason' => 'not_client'];
        }
        if (!app_env_flag('APP_LICENSE_ENV_AUTOWRITE', true)) {
            return ['ok' => false, 'skipped' => true, 'reason' => 'disabled'];
        }
        $updates = app_license_client_runtime_env_updates($row);
        if (empty($updates)) {
            return ['ok' => false, 'skipped' => true, 'reason' => 'missing_values'];
        }
        $written = app_env_file_upsert($updates, __DIR__ . '/.app_env');
        if (empty($written['ok'])) {
            return ['ok' => false, 'skipped' => false, 'reason' => (string)($written['error'] ?? 'write_failed')];
        }
        return ['ok' => true, 'skipped' => false, 'reason' => 'saved'];
    }
}

if (!function_exists('app_license_http_post_json')) {
    function app_license_http_post_json(string $url, array $payload, array $headers = [], int $timeout = 10): array
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            return ['ok' => false, 'http_code' => 0, 'body' => '', 'error' => 'json_encode_failed'];
        }

        $baseHeaders = [
            'Content-Type: application/json',
            'Accept: application/json',
            'Content-Length: ' . strlen($json),
            'User-Agent: ArabEaglesERP/1.0',
            'Expect:',
        ];
        $allHeaders = array_merge($baseHeaders, $headers);

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $json,
                CURLOPT_HTTPHEADER => $allHeaders,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 4,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_CONNECTTIMEOUT => min(5, $timeout),
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ]);
            $body = curl_exec($ch);
            $err = curl_error($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($body === false) {
                return ['ok' => false, 'http_code' => $code, 'body' => '', 'error' => $err !== '' ? $err : 'curl_failed'];
            }
            return ['ok' => $code >= 200 && $code < 300, 'http_code' => $code, 'body' => (string)$body, 'error' => ''];
        }

        $streamOptions = [
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $allHeaders),
                'content' => $json,
                'timeout' => $timeout,
                'ignore_errors' => true,
                'follow_location' => 1,
                'max_redirects' => 4,
            ],
        ];
        $ctx = stream_context_create($streamOptions);
        $stream = @fopen($url, 'rb', false, $ctx);
        if ($stream === false) {
            return ['ok' => false, 'http_code' => 0, 'body' => '', 'error' => 'http_post_failed'];
        }

        $meta = stream_get_meta_data($stream);
        $body = stream_get_contents($stream);
        fclose($stream);
        if ($body === false) {
            return ['ok' => false, 'http_code' => 0, 'body' => '', 'error' => 'http_read_failed'];
        }

        $code = 0;
        $responseHeaders = [];
        if (isset($meta['wrapper_data']) && is_array($meta['wrapper_data'])) {
            $responseHeaders = $meta['wrapper_data'];
        }
        foreach ($responseHeaders as $line) {
            if (preg_match('/\s(\d{3})\s/', (string)$line, $m)) {
                $code = (int)$m[1];
                break;
            }
        }

        return ['ok' => $code >= 200 && $code < 300, 'http_code' => $code, 'body' => (string)$body, 'error' => ''];
    }
}

if (!function_exists('app_license_http_post_form')) {
    function app_license_http_post_form(string $url, array $fields, array $headers = [], int $timeout = 10): array
    {
        $encoded = http_build_query($fields, '', '&', PHP_QUERY_RFC3986);
        $baseHeaders = [
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: application/json',
            'Content-Length: ' . strlen($encoded),
            'User-Agent: ArabEaglesERP/1.0',
            'Expect:',
        ];
        $allHeaders = array_merge($baseHeaders, $headers);

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $encoded,
                CURLOPT_HTTPHEADER => $allHeaders,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 4,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_CONNECTTIMEOUT => min(5, $timeout),
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ]);
            $body = curl_exec($ch);
            $err = curl_error($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($body === false) {
                return ['ok' => false, 'http_code' => $code, 'body' => '', 'error' => $err !== '' ? $err : 'curl_failed'];
            }
            return ['ok' => $code >= 200 && $code < 300, 'http_code' => $code, 'body' => (string)$body, 'error' => ''];
        }

        $streamOptions = [
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $allHeaders),
                'content' => $encoded,
                'timeout' => $timeout,
                'ignore_errors' => true,
                'follow_location' => 1,
                'max_redirects' => 4,
            ],
        ];
        $ctx = stream_context_create($streamOptions);
        $stream = @fopen($url, 'rb', false, $ctx);
        if ($stream === false) {
            return ['ok' => false, 'http_code' => 0, 'body' => '', 'error' => 'http_post_failed'];
        }

        $meta = stream_get_meta_data($stream);
        $body = stream_get_contents($stream);
        fclose($stream);
        if ($body === false) {
            return ['ok' => false, 'http_code' => 0, 'body' => '', 'error' => 'http_read_failed'];
        }

        $code = 0;
        $responseHeaders = [];
        if (isset($meta['wrapper_data']) && is_array($meta['wrapper_data'])) {
            $responseHeaders = $meta['wrapper_data'];
        }
        foreach ($responseHeaders as $line) {
            if (preg_match('/\s(\d{3})\s/', (string)$line, $m)) {
                $code = (int)$m[1];
                break;
            }
        }

        return ['ok' => $code >= 200 && $code < 300, 'http_code' => $code, 'body' => (string)$body, 'error' => ''];
    }
}

if (!function_exists('app_license_signature_sort_array')) {
    function app_license_signature_sort_array($value)
    {
        if (!is_array($value)) {
            return $value;
        }
        $isAssoc = array_keys($value) !== range(0, count($value) - 1);
        if ($isAssoc) {
            ksort($value);
        }
        foreach ($value as $k => $v) {
            $value[$k] = app_license_signature_sort_array($v);
        }
        return $value;
    }
}

if (!function_exists('app_license_signature_payload')) {
    function app_license_signature_payload(array $payload): string
    {
        $copy = $payload;
        unset($copy['signature']);
        $copy = app_license_signature_sort_array($copy);
        $json = json_encode($copy, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return is_string($json) ? $json : '{}';
    }
}

if (!function_exists('app_license_signature_create')) {
    function app_license_signature_create(array $payload, string $secret): string
    {
        $secret = trim($secret);
        if ($secret === '') {
            return '';
        }
        $base = app_license_signature_payload($payload);
        return hash_hmac('sha256', $base, $secret);
    }
}

if (!function_exists('app_license_signature_verify')) {
    function app_license_signature_verify(array $payload, string $secret, string $signature): bool
    {
        $signature = strtolower(trim($signature));
        if ($signature === '' || $secret === '') {
            return false;
        }
        $expected = app_license_signature_create($payload, $secret);
        if ($expected === '') {
            return false;
        }
        return hash_equals($expected, $signature);
    }
}

if (!function_exists('app_license_nonce_table_ensure')) {
    function app_license_nonce_table_ensure(mysqli $conn): void
    {
        static $ok = false;
        if ($ok) {
            return;
        }
        $conn->query("
            CREATE TABLE IF NOT EXISTS app_license_request_nonces (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                license_key VARCHAR(180) NOT NULL DEFAULT '',
                installation_id VARCHAR(80) NOT NULL DEFAULT '',
                nonce_value VARCHAR(80) NOT NULL DEFAULT '',
                created_at DATETIME NOT NULL,
                UNIQUE KEY uq_license_nonce (license_key, installation_id, nonce_value),
                INDEX idx_license_nonce_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $ok = true;
    }
}

if (!function_exists('app_license_nonce_register')) {
    function app_license_nonce_register(mysqli $conn, string $licenseKey, string $installationId, string $nonce): bool
    {
        app_license_nonce_table_ensure($conn);
        $licenseKey = strtoupper(trim($licenseKey));
        $installationId = trim($installationId);
        $nonce = trim($nonce);
        if ($licenseKey === '' || $installationId === '' || $nonce === '') {
            return false;
        }
        $now = date('Y-m-d H:i:s');
        $ok = false;
        $stmt = null;
        try {
            $stmt = $conn->prepare("
                INSERT INTO app_license_request_nonces (license_key, installation_id, nonce_value, created_at)
                VALUES (?, ?, ?, ?)
            ");
            if (!$stmt) {
                return false;
            }
            $stmt->bind_param('ssss', $licenseKey, $installationId, $nonce, $now);
            $ok = $stmt->execute();
        } catch (Throwable $e) {
            // Duplicate nonce is a normal replay signal; return false so caller can map it to replay_detected.
            $code = (int)$e->getCode();
            if ($code === 1062) {
                $ok = false;
            } else {
                $ok = false;
            }
        } finally {
            if ($stmt instanceof mysqli_stmt) {
                $stmt->close();
            }
        }

        // Opportunistic cleanup to keep table bounded.
        if (random_int(1, 100) <= 3) {
            $conn->query("DELETE FROM app_license_request_nonces WHERE created_at < (NOW() - INTERVAL 3 DAY)");
        }
        return (bool)$ok;
    }
}

if (!function_exists('app_license_sync_min_interval_seconds')) {
    function app_license_sync_min_interval_seconds(array $row): int
    {
        $defaultInterval = 12 * 3600;
        if (app_license_edition() !== 'client' || !app_license_remote_only_mode()) {
            return $defaultInterval;
        }

        $status = strtolower(trim((string)($row['license_status'] ?? 'active')));
        $lastSuccess = trim((string)($row['last_success_at'] ?? ''));
        $fastInterval = (int)app_env('APP_LICENSE_SYNC_INTERVAL_SECONDS', '20');
        $fastInterval = max(10, min(900, $fastInterval));
        $activeInterval = (int)app_env('APP_LICENSE_SYNC_ACTIVE_INTERVAL_SECONDS', (string)max(60, $fastInterval));
        $activeInterval = max($fastInterval, min(3600, $activeInterval));

        // Client edition should recover quickly from locks after owner-side updates.
        if ($status === 'suspended' || $status === 'expired' || $lastSuccess === '') {
            return $fastInterval;
        }

        return $activeInterval;
    }
}

if (!function_exists('app_license_client_max_stale_seconds')) {
    function app_license_client_max_stale_seconds(): int
    {
        $default = 900; // 15 minutes
        $raw = (int)app_env('APP_LICENSE_MAX_STALE_SECONDS', (string)$default);
        return max(30, min(86400, $raw));
    }
}

if (!function_exists('app_license_local_state_is_blocked')) {
    function app_license_local_state_is_blocked(array $row, int $now): bool
    {
        $status = strtolower(trim((string)($row['license_status'] ?? 'active')));
        if ($status === 'suspended' || $status === 'expired') {
            return true;
        }

        $plan = strtolower(trim((string)($row['plan_type'] ?? 'trial')));
        if ($plan === 'trial') {
            $trialEndsAt = trim((string)($row['trial_ends_at'] ?? ''));
            $trialTs = $trialEndsAt !== '' ? strtotime($trialEndsAt) : false;
            return ($trialTs === false || $now > $trialTs);
        }

        if ($plan === 'subscription') {
            $subscriptionEnds = trim((string)($row['subscription_ends_at'] ?? ''));
            $subscriptionTs = $subscriptionEnds !== '' ? strtotime($subscriptionEnds) : false;
            if ($subscriptionTs === false) {
                return true;
            }
            $graceDays = max(0, (int)($row['grace_days'] ?? 0));
            $withGraceTs = $subscriptionTs + ($graceDays * 86400);
            return $now > $withGraceTs;
        }

        return false;
    }
}

if (!function_exists('app_license_sync_remote')) {
    function app_license_sync_remote(mysqli $conn, bool $force = false): array
    {
        if (app_license_edition() === 'owner' && !app_license_owner_remote_sync_enabled()) {
            return ['ok' => false, 'skipped' => true, 'reason' => 'owner_remote_sync_disabled'];
        }

        $row = app_license_row($conn);
        if (empty($row)) {
            return ['ok' => false, 'skipped' => true, 'reason' => 'missing_row'];
        }
        $strictClientRemote = (
            app_license_edition() === 'client'
            && app_license_remote_only_mode()
            && app_license_client_strict_enforcement()
        );

        $remoteUrl = trim((string)($row['remote_url'] ?? ''));
        if (app_license_url_looks_placeholder($remoteUrl)) {
            $remoteUrl = '';
        }
        if (app_license_url_is_local_or_private($remoteUrl)) {
            $remoteUrl = '';
        }
        $licenseKey = trim((string)($row['license_key'] ?? ''));
        $remoteToken = trim((string)($row['remote_token'] ?? ''));
        if (app_license_token_looks_placeholder($remoteToken)) {
            $remoteToken = '';
        }

        if ($remoteUrl === '' || $remoteToken === '') {
            $guessed = app_license_guess_remote_from_cloud_sync($conn);
            $guessedUrl = trim((string)($guessed['remote_url'] ?? ''));
            $guessedToken = trim((string)($guessed['remote_token'] ?? ''));
            if ($remoteUrl === '' && $guessedUrl !== '') {
                $remoteUrl = $guessedUrl;
            }
            if ($remoteToken === '' && $guessedToken !== '') {
                $remoteToken = $guessedToken;
            }
            if ($remoteUrl !== '' || $remoteToken !== '') {
                try {
                    $stmtUpdRemote = $conn->prepare("UPDATE app_license_state SET remote_url = ?, remote_token = ? WHERE id = 1");
                    $stmtUpdRemote->bind_param('ss', $remoteUrl, $remoteToken);
                    $stmtUpdRemote->execute();
                    $stmtUpdRemote->close();
                } catch (Throwable $e) {
                    // Keep runtime values even if DB persistence fails.
                }
            }
        }

        if ($remoteUrl === '') {
            return ['ok' => false, 'skipped' => true, 'reason' => app_license_remote_only_mode() ? 'remote_not_configured' : 'remote_disabled'];
        }
        if ($licenseKey === '') {
            return ['ok' => false, 'skipped' => true, 'reason' => 'missing_license_key'];
        }

        $lastCheck = trim((string)($row['last_check_at'] ?? ''));
        $lastCheckTs = $lastCheck !== '' ? strtotime($lastCheck) : false;
        $minInterval = app_license_sync_min_interval_seconds($row);
        if (!$force && $lastCheckTs !== false && (time() - $lastCheckTs) < $minInterval) {
            return ['ok' => true, 'skipped' => true, 'reason' => 'recent_check'];
        }

        $tokenCandidates = [];
        $addToken = static function (string $token) use (&$tokenCandidates): void {
            $clean = trim($token);
            if ($clean === '' || app_license_token_looks_placeholder($clean)) {
                return;
            }
            if (!in_array($clean, $tokenCandidates, true)) {
                $tokenCandidates[] = $clean;
            }
        };
        $addToken($remoteToken);
        $addToken((string)app_env('APP_LICENSE_REMOTE_TOKEN', ''));
        $addToken((string)app_env('APP_LICENSE_API_TOKEN', ''));

        if ($strictClientRemote && empty($tokenCandidates)) {
            $now = date('Y-m-d H:i:s');
            $err = 'remote_token_missing';
            $stmt = $conn->prepare("
                UPDATE app_license_state
                SET last_check_at = ?, last_success_at = NULL, license_status = 'suspended', last_error = ?
                WHERE id = 1
            ");
            $stmt->bind_param('ss', $now, $err);
            $stmt->execute();
            $stmt->close();
            return ['ok' => false, 'skipped' => false, 'reason' => $err];
        }

        if (empty($tokenCandidates)) {
            $tokenCandidates[] = '';
        }

        $ts = time();
        $nonce = substr(bin2hex(random_bytes(12)), 0, 24);
        $basePayload = [
            'event' => 'license_sync_v2',
            'license_key' => $licenseKey,
            'installation_id' => (string)($row['installation_id'] ?? ''),
            'fingerprint' => (string)($row['fingerprint'] ?? ''),
            'domain' => (string)parse_url(app_base_url(), PHP_URL_HOST),
            'app_url' => app_base_url(),
            'ts' => $ts,
            'nonce' => $nonce,
            'request_id' => strtoupper(substr(bin2hex(random_bytes(10)), 0, 20)),
            'current_plan' => (string)($row['plan_type'] ?? 'trial'),
            'current_status' => (string)($row['license_status'] ?? 'active'),
            'client_state' => [
                'status' => (string)($row['license_status'] ?? 'active'),
                'plan' => (string)($row['plan_type'] ?? 'trial'),
                'last_success_at' => (string)($row['last_success_at'] ?? ''),
                'last_error' => (string)($row['last_error'] ?? ''),
            ],
        ];
        if (app_license_edition() === 'client') {
            $basePayload['support_report'] = app_support_client_collect_system_report($conn, 250);
        }

        $candidateUrls = app_support_remote_candidate_urls($remoteUrl);
        if (empty($candidateUrls)) {
            $candidateUrls = [$remoteUrl];
        }

        $http = ['ok' => false, 'http_code' => 0, 'body' => '', 'error' => 'remote_error'];
        $decoded = null;
        $syncOk = false;
        $usedToken = '';
        foreach ($candidateUrls as $candidateUrl) {
            foreach ($tokenCandidates as $candidateToken) {
                $headers = [];
                if ($candidateToken !== '') {
                    $headers[] = 'Authorization: Bearer ' . $candidateToken;
                }
                $payload = $basePayload;
                $payload['signature'] = app_license_signature_create($payload, $candidateToken);
                $http = app_license_http_post_json((string)$candidateUrl, $payload, $headers, 10);
                if (empty($http['ok'])) {
                    continue;
                }
                $decodedTry = json_decode((string)$http['body'], true);
                if (!is_array($decodedTry)) {
                    $http = ['ok' => false, 'http_code' => 200, 'body' => (string)($http['body'] ?? ''), 'error' => 'invalid_json'];
                    continue;
                }
                if (isset($decodedTry['ok']) && !$decodedTry['ok']) {
                    $errTry = trim((string)($decodedTry['error'] ?? 'remote_rejected'));
                    if ($errTry === '') {
                        $errTry = 'remote_rejected';
                    }
                    $http = ['ok' => false, 'http_code' => 200, 'body' => json_encode($decodedTry), 'error' => $errTry];
                    continue;
                }
                $decoded = $decodedTry;
                $syncOk = true;
                $usedToken = (string)$candidateToken;
                break 2;
            }
        }

        $now = date('Y-m-d H:i:s');
        if (!$syncOk) {
            $httpCode = (int)($http['http_code'] ?? 0);
            $errRaw = trim((string)($http['error'] ?? ''));
            if ($errRaw === '') {
                $errRaw = $httpCode > 0 ? ('http_' . $httpCode) : 'remote_error';
            }
            $err = mb_substr($errRaw, 0, 250);
            $hardFail = $strictClientRemote && (
                in_array($httpCode, [401, 403, 404, 422], true)
                || in_array($err, ['unauthorized', 'bad_signature', 'replay_detected', 'timestamp_expired', 'missing_required_fields'], true)
            );
            if ($hardFail) {
                $stmt = $conn->prepare("
                    UPDATE app_license_state
                    SET last_check_at = ?, last_success_at = NULL, license_status = 'suspended', last_error = ?
                    WHERE id = 1
                ");
                $stmt->bind_param('ss', $now, $err);
            } else {
                $stmt = $conn->prepare("UPDATE app_license_state SET last_check_at = ?, last_error = ? WHERE id = 1");
                $stmt->bind_param('ss', $now, $err);
            }
            $stmt->execute();
            $stmt->close();
            return ['ok' => false, 'skipped' => false, 'reason' => $err];
        }

        $node = (isset($decoded['license']) && is_array($decoded['license'])) ? $decoded['license'] : $decoded;
        $status = strtolower(trim((string)($node['status'] ?? $row['license_status'] ?? 'active')));
        $plan = strtolower(trim((string)($node['plan'] ?? $row['plan_type'] ?? 'trial')));
        $owner = trim((string)($node['owner_name'] ?? $row['owner_name'] ?? ''));
        $trialEnds = trim((string)($node['trial_ends_at'] ?? $row['trial_ends_at'] ?? ''));
        $subscriptionEnds = trim((string)($node['subscription_ends_at'] ?? $row['subscription_ends_at'] ?? ''));
        $graceDays = (int)($node['grace_days'] ?? $row['grace_days'] ?? 3);
        $assignedLicenseKey = strtoupper(trim((string)($node['assigned_license_key'] ?? $decoded['assigned_license_key'] ?? '')));
        $assignedApiToken = trim((string)($node['assigned_api_token'] ?? $decoded['assigned_api_token'] ?? ''));
        $assignedRemoteUrl = trim((string)($node['assigned_remote_url'] ?? $decoded['assigned_remote_url'] ?? ''));
        $envKey = app_license_env_key();
        $envRemote = app_license_env_remote();
        $remoteLocked = app_license_remote_lock_mode();
        $currentLicenseKey = strtoupper(trim((string)($row['license_key'] ?? '')));
        $effectiveLicenseKey = $currentLicenseKey;
        if ($assignedLicenseKey !== '' && ($envKey === '' || !$remoteLocked)) {
            $effectiveLicenseKey = $assignedLicenseKey;
        }
        $effectiveRemoteToken = trim((string)($row['remote_token'] ?? ''));
        if ($assignedApiToken !== '' && ($envRemote['token'] === '' || !$remoteLocked)) {
            $effectiveRemoteToken = mb_substr($assignedApiToken, 0, 190);
        }
        if ($effectiveRemoteToken === '' && $usedToken !== '' && ($envRemote['token'] === '' || !$remoteLocked)) {
            $effectiveRemoteToken = mb_substr($usedToken, 0, 190);
        }
        $effectiveRemoteUrl = trim((string)($row['remote_url'] ?? ''));
        if ($assignedRemoteUrl !== '' && ($envRemote['url'] === '' || !$remoteLocked)) {
            $scheme = strtolower((string)parse_url($assignedRemoteUrl, PHP_URL_SCHEME));
            if (filter_var($assignedRemoteUrl, FILTER_VALIDATE_URL) && in_array($scheme, ['http', 'https'], true)) {
                $effectiveRemoteUrl = mb_substr($assignedRemoteUrl, 0, 255);
            }
        }

        if (!in_array($status, ['active', 'expired', 'suspended'], true)) {
            $status = 'active';
        }
        if (!in_array($plan, ['trial', 'subscription', 'lifetime'], true)) {
            $plan = (string)($row['plan_type'] ?? 'trial');
        }
        if ($trialEnds !== '' && strtotime($trialEnds) === false) {
            $trialEnds = (string)($row['trial_ends_at'] ?? '');
        }
        if ($subscriptionEnds !== '' && strtotime($subscriptionEnds) === false) {
            $subscriptionEnds = (string)($row['subscription_ends_at'] ?? '');
        }
        $graceDays = max(0, min(60, $graceDays));

        $stmt = $conn->prepare("
            UPDATE app_license_state
            SET plan_type = ?, license_status = ?, owner_name = ?, trial_ends_at = ?, subscription_ends_at = ?,
                grace_days = ?, license_key = ?, remote_url = ?, remote_token = ?, last_check_at = ?, last_success_at = ?, last_error = ''
            WHERE id = 1
        ");
        $stmt->bind_param(
            'sssssisssss',
            $plan,
            $status,
            $owner,
            $trialEnds,
            $subscriptionEnds,
            $graceDays,
            $effectiveLicenseKey,
            $effectiveRemoteUrl,
            $effectiveRemoteToken,
            $now,
            $now
        );
        $stmt->execute();
        $stmt->close();

        if (app_license_edition() === 'client') {
            $persistEnv = app_license_client_persist_runtime_env([
                'license_key' => $effectiveLicenseKey,
                'remote_url' => $effectiveRemoteUrl,
                'remote_token' => $effectiveRemoteToken,
            ]);
            if (empty($persistEnv['ok']) && empty($persistEnv['skipped'])) {
                error_log('app_license_client_persist_runtime_env failed: ' . (string)($persistEnv['reason'] ?? 'unknown'));
            }
        }

        $autoBind = app_cloud_sync_auto_bind_from_license($conn, [
            'remote_url' => $effectiveRemoteUrl,
            'remote_token' => $effectiveRemoteToken,
        ]);
        if (!empty($autoBind['ok']) && empty($autoBind['skipped'])) {
            app_cloud_sync_run($conn, true);
        }

        return ['ok' => true, 'skipped' => false, 'reason' => 'synced'];
    }
}
