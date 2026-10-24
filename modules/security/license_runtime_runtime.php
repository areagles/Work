<?php

if (!function_exists('app_license_status')) {
    function app_license_status(mysqli $conn, bool $autoSync = true): array
    {
        app_initialize_license_data($conn);
        if (function_exists('app_is_saas_gateway') && function_exists('app_current_tenant_id')
            && app_is_saas_gateway() && app_current_tenant_id() > 0) {
            $tenant = function_exists('app_current_tenant') ? (app_current_tenant() ?? []) : [];
            $tenantSubscribedUntil = trim((string)($tenant['subscribed_until'] ?? ''));
            $tenantTrialEndsAt = trim((string)($tenant['trial_ends_at'] ?? ''));
            return [
                'allowed' => true,
                'reason' => 'saas_tenant_runtime',
                'edition' => app_license_edition(),
                'remote_only' => false,
                'plan' => $tenantTrialEndsAt !== '' ? 'trial' : 'subscription',
                'status' => 'active',
                'days_left' => null,
                'expires_at' => $tenantSubscribedUntil,
                'owner_name' => (string)($tenant['tenant_name'] ?? ''),
                'installation_id' => '',
                'fingerprint' => '',
                'license_key' => '',
                'remote_url' => '',
                'last_check_at' => '',
                'last_success_at' => '',
                'last_error' => '',
                'grace_days' => 0,
                'trial_ends_at' => $tenantTrialEndsAt,
                'subscription_ends_at' => $tenantSubscribedUntil,
            ];
        }
        if ($autoSync) {
            app_license_sync_remote($conn, false);
        }
        $row = app_license_row($conn);
        $now = time();
        $edition = app_license_edition();
        $remoteOnly = app_license_remote_only_mode();
        $plan = strtolower(trim((string)($row['plan_type'] ?? 'trial')));
        $status = strtolower(trim((string)($row['license_status'] ?? 'active')));
        $graceDays = max(0, (int)($row['grace_days'] ?? 0));
        $licenseKey = trim((string)($row['license_key'] ?? ''));
        $remoteUrl = trim((string)($row['remote_url'] ?? ''));
        $allowed = true;
        $reason = 'ok';
        $expiresAt = '';
        $daysLeft = null;

        $strictClient = ($edition === 'client' && $remoteOnly && app_license_client_strict_enforcement());
        $ownerLabUnlock = app_license_owner_lab_unlock();

        if ($autoSync && $strictClient) {
            $lastCheck = trim((string)($row['last_check_at'] ?? ''));
            $lastCheckTs = $lastCheck !== '' ? strtotime($lastCheck) : false;
            $lastSuccess = trim((string)($row['last_success_at'] ?? ''));
            $lastSuccessTs = $lastSuccess !== '' ? strtotime($lastSuccess) : false;
            $maxStaleSeconds = app_license_client_max_stale_seconds();
            $needForce = app_license_local_state_is_blocked($row, $now);
            if ($lastSuccessTs === false || ($now - $lastSuccessTs) > $maxStaleSeconds) {
                $needForce = true;
            }
            if ($lastCheckTs === false || ($now - $lastCheckTs) >= 20) {
                $needForce = true;
            }
            if ($needForce) {
                app_license_sync_remote($conn, true);
                $row = app_license_row($conn);
                $plan = strtolower(trim((string)($row['plan_type'] ?? 'trial')));
                $status = strtolower(trim((string)($row['license_status'] ?? 'active')));
                $graceDays = max(0, (int)($row['grace_days'] ?? 0));
                $licenseKey = trim((string)($row['license_key'] ?? ''));
                $remoteUrl = trim((string)($row['remote_url'] ?? ''));
            }
        }

        // Client edition strict mode: no runtime access unless centrally verified.
        if ($strictClient) {
            if ($licenseKey === '') {
                $allowed = false;
                $reason = 'activation_required';
            } elseif ($remoteUrl === '') {
                $allowed = false;
                $reason = 'remote_not_configured';
            } else {
                $lastSuccess = trim((string)($row['last_success_at'] ?? ''));
                if ($lastSuccess === '') {
                    app_license_sync_remote($conn, true);
                    $row = app_license_row($conn);
                    $plan = strtolower(trim((string)($row['plan_type'] ?? 'trial')));
                    $status = strtolower(trim((string)($row['license_status'] ?? 'active')));
                    $graceDays = max(0, (int)($row['grace_days'] ?? 0));
                    $licenseKey = trim((string)($row['license_key'] ?? ''));
                    $remoteUrl = trim((string)($row['remote_url'] ?? ''));
                    $lastSuccess = trim((string)($row['last_success_at'] ?? ''));
                }
                if ($lastSuccess === '') {
                    $allowed = false;
                    $reason = 'verification_required';
                } else {
                    $lastSuccessTs = strtotime($lastSuccess);
                    if ($lastSuccessTs === false) {
                        $allowed = false;
                        $reason = 'verification_required';
                    } else {
                        $maxStaleSeconds = app_license_client_max_stale_seconds();
                        if (($now - $lastSuccessTs) > $maxStaleSeconds) {
                            app_license_sync_remote($conn, true);
                            $row = app_license_row($conn);
                            $plan = strtolower(trim((string)($row['plan_type'] ?? 'trial')));
                            $status = strtolower(trim((string)($row['license_status'] ?? 'active')));
                            $graceDays = max(0, (int)($row['grace_days'] ?? 0));
                            $licenseKey = trim((string)($row['license_key'] ?? ''));
                            $remoteUrl = trim((string)($row['remote_url'] ?? ''));
                            $lastSuccess = trim((string)($row['last_success_at'] ?? ''));
                            $lastSuccessTs = $lastSuccess !== '' ? strtotime($lastSuccess) : false;
                            if ($lastSuccessTs === false || ($now - (int)$lastSuccessTs) > $maxStaleSeconds) {
                                $allowed = false;
                                $reason = 'stale_verification';
                            }
                        }
                    }
                }
            }
        }

        if ($allowed) {
            if ($status === 'suspended') {
                if (!$ownerLabUnlock) {
                    $allowed = false;
                    $reason = 'suspended';
                } else {
                    $reason = 'owner_lab_unlock';
                }
            } elseif ($plan === 'trial') {
                $expiresAt = trim((string)($row['trial_ends_at'] ?? ''));
                $expTs = $expiresAt !== '' ? strtotime($expiresAt) : false;
                if ($expTs === false || $now > $expTs) {
                    if (!$ownerLabUnlock) {
                        $allowed = false;
                        $reason = 'trial_expired';
                    } else {
                        $reason = 'owner_lab_unlock';
                    }
                } else {
                    $daysLeft = (int)max(0, floor(($expTs - $now) / 86400));
                }
            } elseif ($plan === 'subscription') {
                $baseExpiry = trim((string)($row['subscription_ends_at'] ?? ''));
                $expTs = $baseExpiry !== '' ? strtotime($baseExpiry) : false;
                if ($expTs === false) {
                    if (!$ownerLabUnlock) {
                        $allowed = false;
                        $reason = 'subscription_missing';
                    } else {
                        $reason = 'owner_lab_unlock';
                    }
                } else {
                    $expiresAt = date('Y-m-d H:i:s', $expTs + ($graceDays * 86400));
                    $withGraceTs = strtotime($expiresAt);
                    if ($withGraceTs === false || $now > $withGraceTs) {
                        if (!$ownerLabUnlock) {
                            $allowed = false;
                            $reason = 'subscription_expired';
                        } else {
                            $reason = 'owner_lab_unlock';
                        }
                    } else {
                        $daysLeft = (int)max(0, floor(($withGraceTs - $now) / 86400));
                    }
                }
            } elseif ($plan === 'lifetime') {
                if ($status === 'expired' && !$ownerLabUnlock) {
                    $allowed = false;
                    $reason = 'lifetime_disabled';
                } elseif ($status === 'suspended' && !$ownerLabUnlock) {
                    $allowed = false;
                    $reason = 'suspended';
                } else {
                    $allowed = true;
                    $reason = $ownerLabUnlock ? 'owner_lab_unlock' : 'ok';
                }
                $daysLeft = null;
            } else {
                $allowed = false;
                $reason = 'invalid_plan';
            }
        }

        return [
            'allowed' => $allowed,
            'reason' => $reason,
            'edition' => $edition,
            'remote_only' => $remoteOnly,
            'plan' => $plan,
            'status' => $status,
            'days_left' => $daysLeft,
            'expires_at' => $expiresAt,
            'owner_name' => (string)($row['owner_name'] ?? ''),
            'installation_id' => (string)($row['installation_id'] ?? ''),
            'fingerprint' => (string)($row['fingerprint'] ?? ''),
            'license_key' => (string)($row['license_key'] ?? ''),
            'remote_url' => (string)($row['remote_url'] ?? ''),
            'last_check_at' => (string)($row['last_check_at'] ?? ''),
            'last_success_at' => (string)($row['last_success_at'] ?? ''),
            'last_error' => (string)($row['last_error'] ?? ''),
            'grace_days' => $graceDays,
            'trial_ends_at' => (string)($row['trial_ends_at'] ?? ''),
            'subscription_ends_at' => (string)($row['subscription_ends_at'] ?? ''),
        ];
    }
}

if (!function_exists('app_license_save_manual')) {
    function app_license_save_manual(mysqli $conn, array $payload): array
    {
        app_initialize_license_data($conn);
        if (!app_is_super_user()) {
            return ['ok' => false, 'error' => 'super_user_required'];
        }
        if (app_license_remote_only_mode()) {
            return ['ok' => false, 'error' => 'remote_only_mode'];
        }

        $plan = strtolower(trim((string)($payload['plan_type'] ?? 'trial')));
        $status = strtolower(trim((string)($payload['license_status'] ?? 'active')));
        $licenseKey = trim((string)($payload['license_key'] ?? ''));
        $owner = trim((string)($payload['owner_name'] ?? ''));
        $graceDays = max(0, min(60, (int)($payload['grace_days'] ?? 3)));
        $trialDays = max(1, min(365, (int)($payload['trial_days'] ?? 14)));
        $trialEndsAt = trim((string)($payload['trial_ends_at'] ?? ''));
        $subscriptionEnds = trim((string)($payload['subscription_ends_at'] ?? ''));

        if (!in_array($plan, ['trial', 'subscription', 'lifetime'], true)) {
            return ['ok' => false, 'error' => 'plan_type_invalid'];
        }
        if (!in_array($status, ['active', 'expired', 'suspended'], true)) {
            $status = 'active';
        }

        $now = date('Y-m-d H:i:s');
        if ($plan === 'trial') {
            if ($trialEndsAt === '') {
                $trialEndsAt = date('Y-m-d H:i:s', time() + ($trialDays * 86400));
            } elseif (strtotime($trialEndsAt) === false) {
                return ['ok' => false, 'error' => 'trial_date_invalid'];
            }
            $subscriptionEnds = '';
        } elseif ($plan === 'subscription') {
            if ($subscriptionEnds === '' || strtotime($subscriptionEnds) === false) {
                return ['ok' => false, 'error' => 'subscription_date_invalid'];
            }
            $trialEndsAt = '';
        } else {
            $trialEndsAt = '';
            $subscriptionEnds = '';
        }

        $stmt = $conn->prepare("
            UPDATE app_license_state
            SET license_key = ?, owner_name = ?, plan_type = ?, license_status = ?, grace_days = ?,
                trial_started_at = CASE WHEN ? = 'trial' THEN COALESCE(trial_started_at, ?) ELSE trial_started_at END,
                trial_ends_at = ?, subscription_ends_at = ?, last_error = ''
            WHERE id = 1
        ");
        $stmt->bind_param('ssssissss', $licenseKey, $owner, $plan, $status, $graceDays, $plan, $now, $trialEndsAt, $subscriptionEnds);
        $stmt->execute();
        $stmt->close();
        return ['ok' => true, 'error' => ''];
    }
}

if (!function_exists('app_license_save_remote_settings')) {
    function app_license_save_remote_settings(mysqli $conn, string $remoteUrl, string $remoteToken): array
    {
        app_initialize_license_data($conn);
        $isAdmin = strtolower((string)($_SESSION['role'] ?? '')) === 'admin';
        $allowClientAdmin = app_license_edition() === 'client' && $isAdmin;
        if (!app_is_super_user() && !$allowClientAdmin) {
            return ['ok' => false, 'error' => 'super_user_required'];
        }

        $envRemote = app_license_env_remote();
        $envLocked = app_license_remote_lock_mode();
        if ($envLocked && $envRemote['url'] !== '') {
            return ['ok' => false, 'error' => 'remote_locked_by_env'];
        }

        $remoteUrl = trim($remoteUrl);
        $remoteToken = trim($remoteToken);
        if ($remoteUrl !== '') {
            $scheme = strtolower((string)parse_url($remoteUrl, PHP_URL_SCHEME));
            if (!filter_var($remoteUrl, FILTER_VALIDATE_URL) || !in_array($scheme, ['http', 'https'], true)) {
                return ['ok' => false, 'error' => 'remote_url_invalid'];
            }
        }
        $stmt = $conn->prepare("UPDATE app_license_state SET remote_url = ?, remote_token = ? WHERE id = 1");
        $stmt->bind_param('ss', $remoteUrl, $remoteToken);
        $stmt->execute();
        $stmt->close();
        return ['ok' => true, 'error' => ''];
    }
}

if (!function_exists('app_initialize_license_management')) {
    function app_initialize_license_management(mysqli $conn): void
    {
        static $booted = false;
        if ($booted) {
            return;
        }
        $booted = true;
        if (app_license_edition() !== 'owner') {
            return;
        }
        app_ensure_license_management_schema($conn);
    }
}

if (!function_exists('app_ensure_license_management_schema')) {
    function app_ensure_license_management_schema(mysqli $conn): bool
    {
        static $ok = null;
        if ($ok !== null) {
            return $ok;
        }
        try {
            $conn->query("
                CREATE TABLE IF NOT EXISTS app_license_registry (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    license_key VARCHAR(180) NOT NULL UNIQUE,
                    client_name VARCHAR(190) NOT NULL DEFAULT '',
                    client_email VARCHAR(190) NOT NULL DEFAULT '',
                    client_phone VARCHAR(80) NOT NULL DEFAULT '',
                    plan_type ENUM('trial','subscription','lifetime') NOT NULL DEFAULT 'trial',
                    status ENUM('active','expired','suspended') NOT NULL DEFAULT 'active',
                    trial_ends_at DATETIME DEFAULT NULL,
                    subscription_ends_at DATETIME DEFAULT NULL,
                    grace_days INT NOT NULL DEFAULT 3,
                    allowed_domains TEXT DEFAULT NULL,
                    strict_installation TINYINT(1) NOT NULL DEFAULT 0,
                    max_installations INT NOT NULL DEFAULT 1,
                    max_users INT NOT NULL DEFAULT 0,
                    api_token VARCHAR(190) NOT NULL DEFAULT '',
                    previous_api_token VARCHAR(190) NOT NULL DEFAULT '',
                    previous_api_token_expires_at DATETIME DEFAULT NULL,
                    lock_reason VARCHAR(255) NOT NULL DEFAULT '',
                    notes TEXT DEFAULT NULL,
                    metadata_json TEXT DEFAULT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX idx_license_registry_status (status),
                    INDEX idx_license_registry_plan (plan_type),
                    INDEX idx_license_registry_api_token (api_token)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");

            $conn->query("
                CREATE TABLE IF NOT EXISTS app_license_installations (
                    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    license_id INT UNSIGNED NOT NULL,
                    installation_id VARCHAR(80) NOT NULL DEFAULT '',
                    fingerprint VARCHAR(80) NOT NULL DEFAULT '',
                    domain VARCHAR(190) NOT NULL DEFAULT '',
                    app_url VARCHAR(255) NOT NULL DEFAULT '',
                    first_seen_at DATETIME NOT NULL,
                    last_seen_at DATETIME NOT NULL,
                    last_ip VARCHAR(64) NOT NULL DEFAULT '',
                    metadata_json TEXT DEFAULT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    UNIQUE KEY uq_license_installation (license_id, installation_id, fingerprint),
                    INDEX idx_license_installations_license (license_id),
                    INDEX idx_license_installations_seen (last_seen_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");

            $conn->query("
                CREATE TABLE IF NOT EXISTS app_license_runtime_log (
                    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    license_id INT UNSIGNED DEFAULT NULL,
                    license_key VARCHAR(180) NOT NULL DEFAULT '',
                    domain VARCHAR(190) NOT NULL DEFAULT '',
                    app_url VARCHAR(255) NOT NULL DEFAULT '',
                    installation_id VARCHAR(80) NOT NULL DEFAULT '',
                    fingerprint VARCHAR(80) NOT NULL DEFAULT '',
                    status VARCHAR(30) NOT NULL DEFAULT '',
                    plan_type VARCHAR(30) NOT NULL DEFAULT '',
                    checked_at DATETIME NOT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_runtime_license (license_id),
                    INDEX idx_runtime_checked (checked_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");

            $conn->query("
                CREATE TABLE IF NOT EXISTS app_license_runtime_bindings (
                    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    license_id INT UNSIGNED NOT NULL,
                    installation_id VARCHAR(80) NOT NULL DEFAULT '',
                    fingerprint VARCHAR(80) NOT NULL DEFAULT '',
                    incoming_license_key VARCHAR(180) NOT NULL DEFAULT '',
                    domain VARCHAR(190) NOT NULL DEFAULT '',
                    notes VARCHAR(255) NOT NULL DEFAULT '',
                    is_active TINYINT(1) NOT NULL DEFAULT 1,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    UNIQUE KEY uq_runtime_binding_install (installation_id, fingerprint),
                    INDEX idx_runtime_binding_license (license_id),
                    INDEX idx_runtime_binding_active (is_active)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");

            $conn->query("
                CREATE TABLE IF NOT EXISTS app_license_blocked_clients (
                    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    domain VARCHAR(190) NOT NULL DEFAULT '',
                    app_url VARCHAR(255) NOT NULL DEFAULT '',
                    installation_id VARCHAR(80) NOT NULL DEFAULT '',
                    fingerprint VARCHAR(80) NOT NULL DEFAULT '',
                    license_key VARCHAR(180) NOT NULL DEFAULT '',
                    reason VARCHAR(255) NOT NULL DEFAULT '',
                    notes TEXT DEFAULT NULL,
                    blocked_by_user_id INT UNSIGNED DEFAULT NULL,
                    released_at DATETIME DEFAULT NULL,
                    is_active TINYINT(1) NOT NULL DEFAULT 1,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX idx_blocked_domain (domain),
                    INDEX idx_blocked_install (installation_id, fingerprint),
                    INDEX idx_blocked_license (license_key),
                    INDEX idx_blocked_active (is_active)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");

            $conn->query("
                CREATE TABLE IF NOT EXISTS app_license_link_codes (
                    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    license_id INT UNSIGNED NOT NULL,
                    runtime_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                    installation_id VARCHAR(80) NOT NULL DEFAULT '',
                    fingerprint VARCHAR(80) NOT NULL DEFAULT '',
                    domain VARCHAR(190) NOT NULL DEFAULT '',
                    code_hash VARCHAR(64) NOT NULL DEFAULT '',
                    code_hint VARCHAR(24) NOT NULL DEFAULT '',
                    auto_activate TINYINT(1) NOT NULL DEFAULT 1,
                    sent_channel VARCHAR(40) NOT NULL DEFAULT '',
                    sent_target VARCHAR(190) NOT NULL DEFAULT '',
                    expires_at DATETIME NOT NULL,
                    used_at DATETIME DEFAULT NULL,
                    used_domain VARCHAR(190) NOT NULL DEFAULT '',
                    metadata_json TEXT DEFAULT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_link_codes_license (license_id),
                    INDEX idx_link_codes_install (installation_id, fingerprint),
                    INDEX idx_link_codes_code_hash (code_hash),
                    INDEX idx_link_codes_expires (expires_at),
                    INDEX idx_link_codes_used (used_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");

            $conn->query("
                CREATE TABLE IF NOT EXISTS app_license_alerts (
                    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    license_id INT UNSIGNED DEFAULT NULL,
                    alert_type VARCHAR(60) NOT NULL DEFAULT '',
                    severity ENUM('info','warning','critical') NOT NULL DEFAULT 'info',
                    title VARCHAR(190) NOT NULL DEFAULT '',
                    message TEXT DEFAULT NULL,
                    meta_json TEXT DEFAULT NULL,
                    is_read TINYINT(1) NOT NULL DEFAULT 0,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_alerts_read (is_read),
                    INDEX idx_alerts_created (created_at),
                    INDEX idx_alerts_license (license_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");

            $conn->query("
                CREATE TABLE IF NOT EXISTS app_support_client_reports (
                    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    license_id INT UNSIGNED DEFAULT NULL,
                    license_key VARCHAR(180) NOT NULL DEFAULT '',
                    client_name VARCHAR(190) NOT NULL DEFAULT '',
                    domain VARCHAR(190) NOT NULL DEFAULT '',
                    app_url VARCHAR(255) NOT NULL DEFAULT '',
                    installation_id VARCHAR(80) NOT NULL DEFAULT '',
                    fingerprint VARCHAR(80) NOT NULL DEFAULT '',
                    app_name VARCHAR(190) NOT NULL DEFAULT '',
                    users_count INT NOT NULL DEFAULT 0,
                    admins_count INT NOT NULL DEFAULT 0,
                    generated_at DATETIME DEFAULT NULL,
                    payload_json MEDIUMTEXT DEFAULT NULL,
                    last_report_at DATETIME NOT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    UNIQUE KEY uq_support_client_report_install (license_key, installation_id, fingerprint),
                    INDEX idx_support_client_report_license (license_id),
                    INDEX idx_support_client_report_last (last_report_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");

            $conn->query("
                CREATE TABLE IF NOT EXISTS app_support_client_report_users (
                    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    report_id BIGINT UNSIGNED NOT NULL,
                    remote_user_id INT DEFAULT NULL,
                    username VARCHAR(120) NOT NULL DEFAULT '',
                    full_name VARCHAR(190) NOT NULL DEFAULT '',
                    role VARCHAR(80) NOT NULL DEFAULT '',
                    email VARCHAR(190) NOT NULL DEFAULT '',
                    phone VARCHAR(80) NOT NULL DEFAULT '',
                    raw_json TEXT DEFAULT NULL,
                    updated_at DATETIME NOT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_support_report_users_report (report_id),
                    INDEX idx_support_report_users_email (email),
                    INDEX idx_support_report_users_phone (phone),
                    INDEX idx_support_report_users_remote (report_id, remote_user_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");

            if (!app_table_has_column($conn, 'app_license_registry', 'max_users')) {
                $conn->query("ALTER TABLE app_license_registry ADD COLUMN max_users INT NOT NULL DEFAULT 0");
            }
            if (!app_table_has_column($conn, 'app_license_registry', 'api_token')) {
                $conn->query("ALTER TABLE app_license_registry ADD COLUMN api_token VARCHAR(190) NOT NULL DEFAULT ''");
            }
            if (!app_table_has_column($conn, 'app_license_registry', 'previous_api_token')) {
                $conn->query("ALTER TABLE app_license_registry ADD COLUMN previous_api_token VARCHAR(190) NOT NULL DEFAULT ''");
            }
            if (!app_table_has_column($conn, 'app_license_registry', 'previous_api_token_expires_at')) {
                $conn->query("ALTER TABLE app_license_registry ADD COLUMN previous_api_token_expires_at DATETIME DEFAULT NULL");
            }
            if (!app_table_has_column($conn, 'app_license_runtime_log', 'app_url')) {
                $conn->query("ALTER TABLE app_license_runtime_log ADD COLUMN app_url VARCHAR(255) NOT NULL DEFAULT ''");
            }
            if (!app_table_has_column($conn, 'app_license_runtime_bindings', 'incoming_license_key')) {
                $conn->query("ALTER TABLE app_license_runtime_bindings ADD COLUMN incoming_license_key VARCHAR(180) NOT NULL DEFAULT ''");
            }
            if (!app_table_has_column($conn, 'app_license_runtime_bindings', 'domain')) {
                $conn->query("ALTER TABLE app_license_runtime_bindings ADD COLUMN domain VARCHAR(190) NOT NULL DEFAULT ''");
            }
            if (!app_table_has_column($conn, 'app_license_runtime_bindings', 'notes')) {
                $conn->query("ALTER TABLE app_license_runtime_bindings ADD COLUMN notes VARCHAR(255) NOT NULL DEFAULT ''");
            }
            if (!app_table_has_column($conn, 'app_license_runtime_bindings', 'is_active')) {
                $conn->query("ALTER TABLE app_license_runtime_bindings ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1");
            }

            $ok = true;
        } catch (Throwable $e) {
            error_log('app_ensure_license_management_schema failed: ' . $e->getMessage());
            $ok = false;
        }
        return $ok;
    }
}

if (!function_exists('app_license_normalize_domain')) {
    function app_license_normalize_domain(string $domain): string
    {
        $domain = strtolower(trim($domain));
        if ($domain === '') {
            return '';
        }
        if (strpos($domain, '://') !== false) {
            $parsed = parse_url($domain, PHP_URL_HOST);
            if (is_string($parsed) && $parsed !== '') {
                $domain = $parsed;
            }
        }
        $domain = preg_replace('/:\d+$/', '', $domain);
        return trim((string)$domain, " \t\n\r\0\x0B.");
    }
}

if (!function_exists('app_license_decode_domains')) {
    function app_license_decode_domains($raw): array
    {
        $items = [];
        if (is_array($raw)) {
            $items = $raw;
        } else {
            $text = trim((string)$raw);
            if ($text !== '' && ($text[0] === '[' || $text[0] === '{')) {
                $decoded = json_decode($text, true);
                if (is_array($decoded)) {
                    $items = $decoded;
                }
            }
            if (empty($items) && $text !== '') {
                $items = preg_split('/[\n\r,;]+/', $text) ?: [];
            }
        }
        $domains = [];
        foreach ($items as $item) {
            $d = app_license_normalize_domain((string)$item);
            if ($d !== '') {
                $domains[] = $d;
            }
        }
        return array_values(array_unique($domains));
    }
}

if (!function_exists('app_license_encode_domains')) {
    function app_license_encode_domains(array $domains): string
    {
        $json = json_encode(array_values(array_unique($domains)), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return is_string($json) ? $json : '[]';
    }
}

if (!function_exists('app_license_domain_allowed')) {
    function app_license_domain_allowed(array $allowedDomains, string $domain): bool
    {
        $domain = app_license_normalize_domain($domain);
        if (empty($allowedDomains) || $domain === '') {
            return empty($allowedDomains);
        }
        foreach ($allowedDomains as $ruleRaw) {
            $rule = app_license_normalize_domain((string)$ruleRaw);
            if ($rule === '') {
                continue;
            }
            if ($rule === $domain) {
                return true;
            }
            if (strpos($rule, '*.') === 0) {
                $suffix = substr($rule, 1); // ".example.com"
                if ($suffix !== '' && substr($domain, -strlen($suffix)) === $suffix) {
                    return true;
                }
            }
        }
        return false;
    }
}

if (!function_exists('app_license_make_key')) {
    function app_license_make_key(): string
    {
        return 'AE-' . strtoupper(substr(bin2hex(random_bytes(8)), 0, 16));
    }
}

if (!function_exists('app_license_make_api_token')) {
    function app_license_make_api_token(): string
    {
        return 'AEAPI-' . strtoupper(substr(bin2hex(random_bytes(24)), 0, 40));
    }
}

if (!function_exists('app_license_make_link_code')) {
    function app_license_make_link_code(): string
    {
        return str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }
}

if (!function_exists('app_license_link_code_hash')) {
    function app_license_link_code_hash(string $code): string
    {
        $clean = preg_replace('/[^0-9]/', '', trim($code));
        $clean = is_string($clean) ? $clean : '';
        return hash('sha256', 'AE_LINK_CODE|' . $clean);
    }
}
