<?php
// Desktop first-run bootstrap.
// Creates required schema + default admin automatically (idempotent).

require_once __DIR__ . '/../security.php';

if (!function_exists('desktop_bootstrap_log')) {
    function desktop_bootstrap_log(string $message): void
    {
        $text = trim($message);
        if ($text === '') {
            return;
        }
        echo '[desktop-bootstrap] ' . $text . PHP_EOL;
    }
}

if (!function_exists('desktop_bootstrap_env_flag')) {
    function desktop_bootstrap_env_flag(string $key, bool $default = false): bool
    {
        $raw = trim((string)app_env($key, $default ? '1' : '0'));
        if ($raw === '') {
            return $default;
        }
        return in_array(strtolower($raw), ['1', 'true', 'yes', 'on'], true);
    }
}

if (!function_exists('desktop_bootstrap_db_connect')) {
    function desktop_bootstrap_db_connect(): mysqli
    {
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

        $host = trim((string)app_env('DB_HOST', 'localhost'));
        $port = (int)app_env('DB_PORT', '3306');
        $socket = trim((string)app_env('DB_SOCKET', ''));
        $user = trim((string)app_env('DB_USER', ''));
        $pass = (string)app_env('DB_PASS', '');
        $name = trim((string)app_env('DB_NAME', ''));

        if ($user === '' || $name === '') {
            throw new RuntimeException('DB_USER / DB_NAME are required for desktop bootstrap.');
        }

        $conn = ($socket !== '')
            ? new mysqli($host, $user, $pass, $name, max(1, $port), $socket)
            : new mysqli($host, $user, $pass, $name, max(1, $port));
        $conn->set_charset('utf8mb4');
        return $conn;
    }
}

if (!function_exists('desktop_bootstrap_table_exists')) {
    function desktop_bootstrap_table_exists(mysqli $conn, string $table): bool
    {
        $table = trim($table);
        if ($table === '' || !preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
            return false;
        }
        $stmt = $conn->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1');
        $stmt->bind_param('s', $table);
        $stmt->execute();
        $exists = (bool)$stmt->get_result()->fetch_row();
        $stmt->close();
        return $exists;
    }
}

if (!function_exists('desktop_bootstrap_count_rows')) {
    function desktop_bootstrap_count_rows(mysqli $conn, string $table): int
    {
        if (!desktop_bootstrap_table_exists($conn, $table)) {
            return 0;
        }
        $sql = 'SELECT COUNT(*) AS c FROM `' . $table . '`';
        $res = $conn->query($sql);
        if (!$res) {
            return 0;
        }
        $row = $res->fetch_assoc();
        return (int)($row['c'] ?? 0);
    }
}

if (!function_exists('desktop_bootstrap_require_installer')) {
    function desktop_bootstrap_require_installer(): void
    {
        if (function_exists('installer_execute_schema')) {
            return;
        }

        $previousMethod = $_SERVER['REQUEST_METHOD'] ?? null;
        $_SERVER['REQUEST_METHOD'] = 'GET';

        ob_start();
        require __DIR__ . '/../install.php';
        ob_end_clean();

        if ($previousMethod === null) {
            unset($_SERVER['REQUEST_METHOD']);
        } else {
            $_SERVER['REQUEST_METHOD'] = $previousMethod;
        }

        if (!function_exists('installer_execute_schema')) {
            throw new RuntimeException('installer_execute_schema is unavailable.');
        }
    }
}

if (!function_exists('desktop_bootstrap_ensure_unique_client_identity')) {
    function desktop_bootstrap_ensure_unique_client_identity(mysqli $conn): void
    {
        if (strtolower(app_license_edition()) !== 'client') {
            return;
        }
        if (!desktop_bootstrap_env_flag('APP_DESKTOP_ENFORCE_AUTO_KEY', true)) {
            return;
        }
        app_initialize_license_data($conn);

        $row = app_license_row($conn);
        if (empty($row)) {
            return;
        }
        $installationId = trim((string)($row['installation_id'] ?? ''));
        $fingerprint = trim((string)($row['fingerprint'] ?? ''));
        $envKey = trim((string)app_env('APP_LICENSE_KEY', ''));
        $metadataRaw = (string)($row['metadata_json'] ?? '');
        $metadata = json_decode($metadataRaw, true);
        if (!is_array($metadata)) {
            $metadata = [];
        }

        if ($installationId === '') {
            $installationId = substr(bin2hex(random_bytes(16)), 0, 32);
            $stmt = $conn->prepare("UPDATE app_license_state SET installation_id = ? WHERE id = 1");
            $stmt->bind_param('s', $installationId);
            $stmt->execute();
            $stmt->close();
        }
        if ($fingerprint === '') {
            $fingerprint = app_license_installation_fingerprint($conn);
            $stmt = $conn->prepare("UPDATE app_license_state SET fingerprint = ? WHERE id = 1");
            $stmt->bind_param('s', $fingerprint);
            $stmt->execute();
            $stmt->close();
        }

        $forceReseed = desktop_bootstrap_env_flag('APP_DESKTOP_FORCE_RESEED_KEY', true);
        $alreadySeeded = !empty($metadata['desktop_identity_seeded']);
        if ($envKey === '' && desktop_bootstrap_env_flag('APP_LICENSE_AUTO_KEY', true)) {
            if ($forceReseed && !$alreadySeeded) {
                $desiredKey = app_license_generate_auto_key($installationId, $fingerprint);
                $currentKey = strtoupper(trim((string)($row['license_key'] ?? '')));
                if ($desiredKey !== '' && $currentKey !== $desiredKey) {
                    $stmt = $conn->prepare("UPDATE app_license_state SET license_key = ? WHERE id = 1");
                    $stmt->bind_param('s', $desiredKey);
                    $stmt->execute();
                    $stmt->close();
                    desktop_bootstrap_log('client license key reseeded for unique desktop identity.');
                }
            }
        }

        $forceSyncApiPerInstall = desktop_bootstrap_env_flag('APP_DESKTOP_FORCE_NEW_SYNC_TOKEN', true);
        $syncTokenSeeded = !empty($metadata['desktop_sync_api_seeded']);
        if ($forceSyncApiPerInstall && !$syncTokenSeeded) {
            $syncApiToken = substr(hash('sha256', random_bytes(32) . '|' . $installationId . '|' . $fingerprint), 0, 64);
            $installCode = strtoupper(substr(hash('sha256', $installationId . '|' . $fingerprint), 0, 10));
            app_setting_set($conn, 'cloud_sync_api_token', $syncApiToken);
            app_setting_set($conn, 'cloud_sync_installation_code', $installCode);
            desktop_bootstrap_log('cloud sync incoming API token generated for this installation.');
        }

        $metadata['desktop_identity_seeded'] = 1;
        $metadata['desktop_sync_api_seeded'] = 1;
        $metadata['desktop_seeded_at'] = date('c');
        $metaJson = json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($metaJson)) {
            $metaJson = '{}';
        }
        $stmtMeta = $conn->prepare("UPDATE app_license_state SET metadata_json = ? WHERE id = 1");
        $stmtMeta->bind_param('s', $metaJson);
        $stmtMeta->execute();
        $stmtMeta->close();
    }
}

if (!function_exists('desktop_bootstrap_ensure_admin')) {
    function desktop_bootstrap_ensure_admin(mysqli $conn): void
    {
        $adminUsername = trim((string)app_env('APP_DESKTOP_ADMIN_USERNAME', 'admin'));
        $adminPassword = (string)app_env('APP_DESKTOP_ADMIN_PASSWORD', 'admin');
        $adminFullName = trim((string)app_env('APP_DESKTOP_ADMIN_FULL_NAME', 'Desktop Admin'));

        if ($adminUsername === '') {
            $adminUsername = 'admin';
        }
        if ($adminPassword === '') {
            $adminPassword = 'admin';
        }
        if ($adminFullName === '') {
            $adminFullName = 'Desktop Admin';
        }

        $stmtCheck = $conn->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
        $stmtCheck->bind_param('s', $adminUsername);
        $stmtCheck->execute();
        $row = $stmtCheck->get_result()->fetch_assoc();
        $stmtCheck->close();

        $passHash = password_hash($adminPassword, PASSWORD_DEFAULT);
        $role = 'admin';

        if ($row) {
            $id = (int)$row['id'];
            $stmtUpdate = $conn->prepare('UPDATE users SET password = ?, full_name = ?, role = ? WHERE id = ?');
            $stmtUpdate->bind_param('sssi', $passHash, $adminFullName, $role, $id);
            $stmtUpdate->execute();
            $stmtUpdate->close();
            desktop_bootstrap_log('admin user updated: ' . $adminUsername);
            return;
        }

        $stmtInsert = $conn->prepare('INSERT INTO users (username, password, full_name, role) VALUES (?, ?, ?, ?)');
        $stmtInsert->bind_param('ssss', $adminUsername, $passHash, $adminFullName, $role);
        $stmtInsert->execute();
        $stmtInsert->close();

        desktop_bootstrap_log('admin user created: ' . $adminUsername);
    }
}

if (!function_exists('desktop_bootstrap_mark_lock')) {
    function desktop_bootstrap_mark_lock(): void
    {
        $lockFile = __DIR__ . '/../.installed_lock';
        $payload = "installed_at=" . date('c') . "\nsource=desktop_bootstrap\n";
        @file_put_contents($lockFile, $payload, LOCK_EX);
        @chmod($lockFile, 0644);
    }
}

try {
    $conn = desktop_bootstrap_db_connect();

    $usersExists = desktop_bootstrap_table_exists($conn, 'users');
    $jobsExists = desktop_bootstrap_table_exists($conn, 'job_orders');

    if (!$usersExists || !$jobsExists) {
        desktop_bootstrap_log('schema is missing. applying installer schema...');
        desktop_bootstrap_require_installer();
        installer_execute_schema($conn);
        desktop_bootstrap_log('schema created.');
    } else {
        desktop_bootstrap_log('schema exists. checking for missing modules...');
    }

    app_initialize_system_settings($conn);
    app_initialize_access_control($conn);
    app_initialize_customization_data($conn);

    // Ensure extended modules and post-upgrade schemas are present.
    if (function_exists('app_ensure_jobs_extended_schema')) {
        app_ensure_jobs_extended_schema($conn);
    }
    if (function_exists('app_ensure_operations_costing_schema')) {
        app_ensure_operations_costing_schema($conn);
    }
    if (function_exists('app_ensure_cloud_sync_schema')) {
        app_ensure_cloud_sync_schema($conn);
    }

    $forceDefaultAdmin = desktop_bootstrap_env_flag('APP_DESKTOP_FORCE_DEFAULT_ADMIN', true);
    if ($forceDefaultAdmin) {
        desktop_bootstrap_log('ensuring configured desktop admin account.');
        desktop_bootstrap_ensure_admin($conn);
    } else {
        // Fallback mode: only create admin when users table is empty or missing admin role.
        $usersCount = desktop_bootstrap_count_rows($conn, 'users');
        if ($usersCount <= 0) {
            desktop_bootstrap_log('users table is empty, creating default admin.');
            desktop_bootstrap_ensure_admin($conn);
        } else {
            $stmtAdmin = $conn->query("SELECT id FROM users WHERE role='admin' LIMIT 1");
            $hasAdmin = $stmtAdmin && $stmtAdmin->fetch_row();
            if (!$hasAdmin) {
                desktop_bootstrap_log('no admin user found, creating default admin.');
                desktop_bootstrap_ensure_admin($conn);
            }
        }
    }

    desktop_bootstrap_ensure_unique_client_identity($conn);

    if (app_license_edition() === 'client') {
        desktop_bootstrap_log('syncing activation status with owner center...');
        $sync = app_license_sync_remote($conn, true);
        if (!empty($sync['ok'])) {
            desktop_bootstrap_log('activation channel is connected.');
        } else {
            $reason = trim((string)($sync['reason'] ?? 'pending_activation'));
            desktop_bootstrap_log('activation is pending: ' . $reason);
        }

        $autoBind = app_cloud_sync_auto_bind_from_license($conn);
        if (!empty($autoBind['ok']) && empty($autoBind['skipped'])) {
            desktop_bootstrap_log('cloud sync link configured automatically.');
            $cloudSync = app_cloud_sync_run($conn, true);
            if (!empty($cloudSync['ok'])) {
                desktop_bootstrap_log('cloud sync completed successfully.');
            } elseif (!empty($cloudSync['skipped'])) {
                desktop_bootstrap_log('cloud sync is ready and will run on schedule.');
            } else {
                $reason = trim((string)($cloudSync['reason'] ?? 'pending'));
                desktop_bootstrap_log('cloud sync will retry automatically: ' . $reason);
            }
        } else {
            $reason = trim((string)($autoBind['reason'] ?? 'pending_activation'));
            desktop_bootstrap_log('cloud sync auto-link is waiting for activation data: ' . $reason);
        }
    }

    app_ensure_dir(__DIR__ . '/../uploads/job_files');
    app_ensure_dir(__DIR__ . '/../uploads/proofs');
    app_ensure_dir(__DIR__ . '/../uploads/source');
    app_ensure_dir(__DIR__ . '/../uploads/briefs');
    app_ensure_dir(__DIR__ . '/../uploads/materials');
    app_ensure_dir(__DIR__ . '/../uploads/avatars');
    app_ensure_dir(__DIR__ . '/../uploads/products');
    app_harden_upload_directory(__DIR__ . '/../uploads');

    desktop_bootstrap_mark_lock();

    desktop_bootstrap_log('bootstrap completed successfully.');
    exit(0);
} catch (Throwable $e) {
    desktop_bootstrap_log('bootstrap failed: ' . $e->getMessage());
    exit(1);
}
