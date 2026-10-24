<?php

if (!function_exists('app_desktop_node_allowed_statuses')) {
    function app_desktop_node_allowed_statuses(): array
    {
        return ['pending', 'active', 'suspended', 'disabled', 'revoked'];
    }
}

if (!function_exists('app_desktop_node_sanitize_status')) {
    function app_desktop_node_sanitize_status(string $status): string
    {
        $status = strtolower(trim($status));
        return in_array($status, app_desktop_node_allowed_statuses(), true) ? $status : 'pending';
    }
}

if (!function_exists('app_ensure_desktop_nodes_schema')) {
    function app_ensure_desktop_nodes_schema(mysqli $conn): bool
    {
        static $ok = null;
        if ($ok !== null) {
            return $ok;
        }

        try {
            $conn->query("
                CREATE TABLE IF NOT EXISTS app_desktop_nodes (
                    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    installation_code VARCHAR(32) NOT NULL,
                    node_name VARCHAR(190) NOT NULL DEFAULT '',
                    license_key VARCHAR(180) NOT NULL DEFAULT '',
                    edition VARCHAR(32) NOT NULL DEFAULT 'client',
                    linked_system VARCHAR(64) NOT NULL DEFAULT '',
                    status ENUM('pending','active','suspended','disabled','revoked') NOT NULL DEFAULT 'pending',
                    platform VARCHAR(40) NOT NULL DEFAULT '',
                    app_version VARCHAR(40) NOT NULL DEFAULT '',
                    db_label VARCHAR(120) NOT NULL DEFAULT '',
                    force_read_only TINYINT(1) NOT NULL DEFAULT 0,
                    force_password_reset TINYINT(1) NOT NULL DEFAULT 0,
                    remote_token_hash VARCHAR(128) NOT NULL DEFAULT '',
                    last_heartbeat_at DATETIME NULL,
                    last_sync_at DATETIME NULL,
                    last_ip VARCHAR(80) NOT NULL DEFAULT '',
                    metadata_json LONGTEXT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    UNIQUE KEY uniq_desktop_installation (installation_code),
                    KEY idx_desktop_status (status),
                    KEY idx_desktop_heartbeat (last_heartbeat_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            if (!app_table_has_column($conn, 'app_desktop_nodes', 'license_key')) {
                $conn->query("ALTER TABLE app_desktop_nodes ADD COLUMN license_key VARCHAR(180) NOT NULL DEFAULT '' AFTER node_name");
                $conn->query("ALTER TABLE app_desktop_nodes ADD KEY idx_desktop_license_key (license_key)");
            }
            $ok = true;
        } catch (Throwable $e) {
            error_log('app_ensure_desktop_nodes_schema failed: ' . $e->getMessage());
            $ok = false;
        }

        return $ok;
    }
}

if (!function_exists('app_desktop_node_token_hash')) {
    function app_desktop_node_token_hash(string $token): string
    {
        $token = trim($token);
        return $token === '' ? '' : hash('sha256', $token);
    }
}

if (!function_exists('app_desktop_node_register_or_update')) {
    function app_desktop_node_register_or_update(mysqli $conn, array $payload): array
    {
        if (!app_ensure_desktop_nodes_schema($conn)) {
            return ['ok' => false, 'error' => 'desktop_nodes_schema_unavailable'];
        }

        $installationCode = app_cloud_sync_sanitize_installation_code((string)($payload['installation_code'] ?? ''));
        if ($installationCode === '') {
            return ['ok' => false, 'error' => 'installation_code_required'];
        }

        $nodeName = trim((string)($payload['node_name'] ?? ''));
        $licenseKey = strtoupper(trim((string)($payload['license_key'] ?? '')));
        $edition = strtolower(trim((string)($payload['edition'] ?? 'client')));
        if (!in_array($edition, ['client', 'owner'], true)) {
            $edition = 'client';
        }
        $linkedSystem = trim((string)($payload['linked_system'] ?? ''));
        $platform = trim((string)($payload['platform'] ?? ''));
        $appVersion = trim((string)($payload['app_version'] ?? ''));
        $dbLabel = trim((string)($payload['db_label'] ?? ''));
        $remoteTokenHash = app_desktop_node_token_hash((string)($payload['remote_token'] ?? ''));
        $lastIp = trim((string)($payload['last_ip'] ?? ($_SERVER['REMOTE_ADDR'] ?? '')));
        $lastSyncAt = trim((string)($payload['last_sync_at'] ?? ''));
        $heartbeatAt = date('Y-m-d H:i:s');
        $metadata = $payload['metadata'] ?? [];
        $metadataJson = json_encode(is_array($metadata) ? $metadata : [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($metadataJson)) {
            $metadataJson = '{}';
        }

        $stmt = $conn->prepare("
            INSERT INTO app_desktop_nodes (
                installation_code, node_name, license_key, edition, linked_system, status, platform, app_version, db_label,
                force_read_only, force_password_reset, remote_token_hash, last_heartbeat_at, last_sync_at, last_ip, metadata_json
            ) VALUES (?, ?, ?, ?, ?, 'pending', ?, ?, ?, 0, 0, ?, ?, NULLIF(?, ''), ?, ?)
            ON DUPLICATE KEY UPDATE
                node_name = VALUES(node_name),
                license_key = CASE WHEN VALUES(license_key) <> '' THEN VALUES(license_key) ELSE license_key END,
                edition = VALUES(edition),
                linked_system = VALUES(linked_system),
                platform = VALUES(platform),
                app_version = VALUES(app_version),
                db_label = VALUES(db_label),
                remote_token_hash = CASE WHEN VALUES(remote_token_hash) <> '' THEN VALUES(remote_token_hash) ELSE remote_token_hash END,
                last_heartbeat_at = VALUES(last_heartbeat_at),
                last_sync_at = CASE WHEN VALUES(last_sync_at) IS NOT NULL THEN VALUES(last_sync_at) ELSE last_sync_at END,
                last_ip = VALUES(last_ip),
                metadata_json = VALUES(metadata_json)
        ");
        $stmt->bind_param(
            'ssssssssssss',
            $installationCode,
            $nodeName,
            $licenseKey,
            $edition,
            $linkedSystem,
            $platform,
            $appVersion,
            $dbLabel,
            $remoteTokenHash,
            $heartbeatAt,
            $lastSyncAt,
            $lastIp,
            $metadataJson
        );
        $stmt->execute();
        $stmt->close();

        return ['ok' => true, 'policy' => app_desktop_node_policy_for_installation($conn, $installationCode)];
    }
}

if (!function_exists('app_desktop_node_policy_for_installation')) {
    function app_desktop_node_policy_for_installation(mysqli $conn, string $installationCode): array
    {
        if (!app_ensure_desktop_nodes_schema($conn)) {
            return ['ok' => false, 'error' => 'desktop_nodes_schema_unavailable'];
        }
        $installationCode = app_cloud_sync_sanitize_installation_code($installationCode);
        if ($installationCode === '') {
            return ['ok' => false, 'error' => 'installation_code_required'];
        }

        $stmt = $conn->prepare("
            SELECT installation_code, node_name, edition, linked_system, status, platform, app_version, db_label,
                   license_key, force_read_only, force_password_reset, last_heartbeat_at, last_sync_at, updated_at
            FROM app_desktop_nodes
            WHERE installation_code = ?
            LIMIT 1
        ");
        $stmt->bind_param('s', $installationCode);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) {
            return ['ok' => false, 'error' => 'desktop_node_not_found'];
        }

        return [
            'ok' => true,
            'installation_code' => (string)$row['installation_code'],
            'node_name' => (string)($row['node_name'] ?? ''),
            'license_key' => (string)($row['license_key'] ?? ''),
            'edition' => (string)($row['edition'] ?? 'client'),
            'linked_system' => (string)($row['linked_system'] ?? ''),
            'status' => app_desktop_node_sanitize_status((string)($row['status'] ?? 'pending')),
            'platform' => (string)($row['platform'] ?? ''),
            'app_version' => (string)($row['app_version'] ?? ''),
            'db_label' => (string)($row['db_label'] ?? ''),
            'force_read_only' => (int)($row['force_read_only'] ?? 0),
            'force_password_reset' => (int)($row['force_password_reset'] ?? 0),
            'last_heartbeat_at' => (string)($row['last_heartbeat_at'] ?? ''),
            'last_sync_at' => (string)($row['last_sync_at'] ?? ''),
            'updated_at' => (string)($row['updated_at'] ?? ''),
        ];
    }
}

if (!function_exists('app_desktop_nodes_set_status_by_license')) {
    function app_desktop_nodes_set_status_by_license(
        mysqli $conn,
        string $licenseKey,
        string $status,
        int $forceReadOnly = 0,
        int $forcePasswordReset = 0
    ): array {
        if (!app_ensure_desktop_nodes_schema($conn)) {
            return ['ok' => false, 'error' => 'desktop_nodes_schema_unavailable', 'affected' => 0];
        }
        $licenseKey = strtoupper(trim($licenseKey));
        if ($licenseKey === '') {
            return ['ok' => false, 'error' => 'license_key_required', 'affected' => 0];
        }
        $status = app_desktop_node_sanitize_status($status);
        $forceReadOnly = $forceReadOnly ? 1 : 0;
        $forcePasswordReset = $forcePasswordReset ? 1 : 0;

        $stmt = $conn->prepare("
            UPDATE app_desktop_nodes
            SET status = ?, force_read_only = ?, force_password_reset = ?
            WHERE license_key = ?
        ");
        $stmt->bind_param('siis', $status, $forceReadOnly, $forcePasswordReset, $licenseKey);
        $stmt->execute();
        $affected = max(0, (int)$stmt->affected_rows);
        $stmt->close();

        return ['ok' => true, 'affected' => $affected, 'status' => $status];
    }
}

if (!function_exists('app_desktop_node_set_status')) {
    function app_desktop_node_set_status(
        mysqli $conn,
        string $installationCode,
        string $status,
        int $forceReadOnly = 0,
        int $forcePasswordReset = 0
    ): array {
        if (!app_ensure_desktop_nodes_schema($conn)) {
            return ['ok' => false, 'error' => 'desktop_nodes_schema_unavailable'];
        }
        $installationCode = app_cloud_sync_sanitize_installation_code($installationCode);
        if ($installationCode === '') {
            return ['ok' => false, 'error' => 'installation_code_required'];
        }
        $status = app_desktop_node_sanitize_status($status);
        $forceReadOnly = $forceReadOnly ? 1 : 0;
        $forcePasswordReset = $forcePasswordReset ? 1 : 0;

        $stmt = $conn->prepare("
            UPDATE app_desktop_nodes
            SET status = ?, force_read_only = ?, force_password_reset = ?
            WHERE installation_code = ?
            LIMIT 1
        ");
        $stmt->bind_param('siis', $status, $forceReadOnly, $forcePasswordReset, $installationCode);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();

        if ($affected < 0) {
            return ['ok' => false, 'error' => 'desktop_node_update_failed'];
        }

        return app_desktop_node_policy_for_installation($conn, $installationCode);
    }
}

if (!function_exists('app_desktop_nodes_list')) {
    function app_desktop_nodes_list(mysqli $conn, int $limit = 200): array
    {
        if (!app_ensure_desktop_nodes_schema($conn)) {
            return [];
        }
        $limit = max(1, min(500, $limit));
        $rows = [];
        $res = $conn->query("
            SELECT installation_code, node_name, edition, linked_system, status, platform, app_version, db_label,
                   license_key, force_read_only, force_password_reset, last_heartbeat_at, last_sync_at, updated_at, created_at
            FROM app_desktop_nodes
            ORDER BY updated_at DESC
            LIMIT {$limit}
        ");
        if (!$res) {
            return [];
        }
        while ($row = $res->fetch_assoc()) {
            $rows[] = $row;
        }
        return $rows;
    }
}

if (!function_exists('app_desktop_node_local_installation_code')) {
    function app_desktop_node_local_installation_code(mysqli $conn): string
    {
        $fromSetting = app_cloud_sync_sanitize_installation_code((string)app_setting_get($conn, 'desktop_node_installation_code', ''));
        if ($fromSetting !== '') {
            return $fromSetting;
        }
        $fromEnv = app_cloud_sync_sanitize_installation_code((string)app_env('APP_DESKTOP_INSTALLATION_CODE', ''));
        if ($fromEnv !== '') {
            return $fromEnv;
        }
        if (function_exists('app_cloud_sync_default_installation_code')) {
            return app_cloud_sync_default_installation_code($conn);
        }
        return '';
    }
}

if (!function_exists('app_desktop_node_is_local_runtime')) {
    function app_desktop_node_is_local_runtime(mysqli $conn): bool
    {
        if (trim((string)app_env('APP_DESKTOP_ADMIN_USERNAME', '')) !== '') {
            return true;
        }
        if (app_desktop_node_local_installation_code($conn) !== '') {
            return true;
        }
        return false;
    }
}

if (!function_exists('app_desktop_node_linked_system')) {
    function app_desktop_node_linked_system(mysqli $conn): string
    {
        $linked = trim((string)app_setting_get($conn, 'desktop_node_linked_system', ''));
        if ($linked !== '') {
            return $linked;
        }
        $linked = trim((string)app_env('APP_DESKTOP_LINKED_SYSTEM', ''));
        if ($linked !== '') {
            return $linked;
        }
        return 'work';
    }
}

if (!function_exists('app_desktop_node_remote_endpoint')) {
    function app_desktop_node_remote_endpoint(mysqli $conn): array
    {
        $url = trim((string)(app_license_row($conn)['remote_url'] ?? app_env('APP_LICENSE_REMOTE_URL', '')));
        $token = trim((string)(app_license_row($conn)['remote_token'] ?? app_env('APP_LICENSE_REMOTE_TOKEN', '')));
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return ['ok' => false, 'error' => 'remote_url_invalid'];
        }
        if ($token === '' || strtoupper($token) === 'CHANGE_ME') {
            return ['ok' => false, 'error' => 'remote_token_missing'];
        }
        return ['ok' => true, 'url' => $url, 'token' => $token];
    }
}

if (!function_exists('app_desktop_node_remote_call')) {
    function app_desktop_node_remote_call(string $url, string $token, array $payload): array
    {
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($body) || $body === '') {
            return ['ok' => false, 'error' => 'encode_failed'];
        }
        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
            'Authorization: Bearer ' . trim($token),
        ];
        $status = 0;

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_TIMEOUT => 15,
            ]);
            $raw = curl_exec($ch);
            $errno = curl_errno($ch);
            $error = curl_error($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);
            if ($errno !== 0) {
                return ['ok' => false, 'error' => $error !== '' ? $error : 'curl_failed', 'http_code' => $status];
            }
        } else {
            $ctx = stream_context_create([
                'http' => [
                    'method' => 'POST',
                    'header' => implode("\r\n", $headers),
                    'content' => $body,
                    'timeout' => 15,
                    'ignore_errors' => true,
                ],
            ]);
            $raw = @file_get_contents($url, false, $ctx);
            $responseHeaders = function_exists('http_get_last_response_headers')
                ? (array)http_get_last_response_headers()
                : [];
            if (!empty($responseHeaders[0]) && preg_match('/\s(\d{3})\s/', (string)$responseHeaders[0], $m)) {
                $status = (int)$m[1];
            }
        }

        $data = json_decode((string)$raw, true);
        if (!is_array($data)) {
            $data = ['raw' => (string)$raw];
        }
        $data['http_code'] = $status;
        $data['ok'] = !empty($data['ok']) || ($status >= 200 && $status < 300);
        return $data;
    }
}

if (!function_exists('app_desktop_node_apply_local_policy')) {
    function app_desktop_node_apply_local_policy(mysqli $conn, array $policy): array
    {
        $status = app_desktop_node_sanitize_status((string)($policy['status'] ?? 'pending'));
        $readOnly = !empty($policy['force_read_only']) ? 1 : 0;
        $forcePasswordReset = !empty($policy['force_password_reset']) ? 1 : 0;

        app_setting_set($conn, 'desktop_node_remote_status', $status);
        app_setting_set($conn, 'desktop_node_force_read_only', (string)$readOnly);
        app_setting_set($conn, 'desktop_node_force_password_reset', (string)$forcePasswordReset);
        app_setting_set($conn, 'desktop_node_policy_synced_at', date('Y-m-d H:i:s'));

        if ($forcePasswordReset && app_table_has_column($conn, 'users', 'must_change_password')) {
            $conn->query("UPDATE users SET must_change_password = 1 WHERE role = 'admin'");
        }

        return [
            'ok' => true,
            'status' => $status,
            'force_read_only' => $readOnly,
            'force_password_reset' => $forcePasswordReset,
        ];
    }
}

if (!function_exists('app_desktop_node_sync_policy')) {
    function app_desktop_node_sync_policy(mysqli $conn, bool $force = false): array
    {
        if (!app_desktop_node_is_local_runtime($conn)) {
            return ['ok' => false, 'error' => 'not_desktop_runtime'];
        }

        $installationCode = app_desktop_node_local_installation_code($conn);
        if ($installationCode === '') {
            return ['ok' => false, 'error' => 'installation_code_missing'];
        }

        $lastCheckedAt = trim((string)app_setting_get($conn, 'desktop_node_last_policy_check_at', ''));
        if (!$force && $lastCheckedAt !== '') {
            $lastTs = strtotime($lastCheckedAt);
            if ($lastTs !== false && (time() - $lastTs) < 60) {
                return [
                    'ok' => true,
                    'cached' => true,
                    'status' => trim((string)app_setting_get($conn, 'desktop_node_remote_status', 'pending')),
                    'force_read_only' => (int)app_setting_get($conn, 'desktop_node_force_read_only', '0'),
                    'force_password_reset' => (int)app_setting_get($conn, 'desktop_node_force_password_reset', '0'),
                ];
            }
        }

        $remote = app_desktop_node_remote_endpoint($conn);
        if (empty($remote['ok'])) {
            app_setting_set($conn, 'desktop_node_last_policy_error', (string)($remote['error'] ?? 'remote_not_configured'));
            return $remote;
        }

        $payload = [
            'event' => 'desktop_node_heartbeat',
            'installation_code' => $installationCode,
            'node_name' => trim((string)app_env('APP_DESKTOP_NODE_NAME', php_uname('n'))),
            'license_key' => strtoupper(trim((string)(app_license_row($conn)['license_key'] ?? ''))),
            'edition' => app_license_edition(),
            'linked_system' => app_desktop_node_linked_system($conn),
            'platform' => PHP_OS_FAMILY,
            'app_version' => (string)app_setting_get($conn, 'update_current_version', 'desktop-local'),
            'db_label' => trim((string)app_setting_get($conn, 'cloud_sync_local_db_label', 'Desktop Local DB')),
            'last_sync_at' => trim((string)app_setting_get($conn, 'cloud_sync_last_success_at', '')),
            'metadata' => [
                'local_url' => rtrim((string)app_base_url(), '/'),
                'read_only_local' => (int)app_setting_get($conn, 'desktop_node_force_read_only', '0'),
            ],
        ];
        $result = app_desktop_node_remote_call((string)$remote['url'], (string)$remote['token'], $payload);
        app_setting_set($conn, 'desktop_node_last_policy_check_at', date('Y-m-d H:i:s'));

        if (empty($result['ok'])) {
            app_setting_set($conn, 'desktop_node_last_policy_error', (string)($result['error'] ?? 'heartbeat_failed'));
            return $result;
        }

        $policy = isset($result['policy']) && is_array($result['policy']) ? $result['policy'] : $result;
        $applied = app_desktop_node_apply_local_policy($conn, $policy);
        app_setting_set($conn, 'desktop_node_last_policy_error', '');
        return $applied + ['remote' => $result];
    }
}
