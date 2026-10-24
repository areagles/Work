<?php

if (!function_exists('app_initialize_support_center')) {
    function app_initialize_support_center(mysqli $conn): void
    {
        static $booted = false;
        if ($booted) {
            return;
        }
        $booted = true;
        app_ensure_support_center_schema($conn);
    }
}

if (!function_exists('app_ensure_support_center_schema')) {
    function app_ensure_support_center_schema(mysqli $conn): bool
    {
        static $ok = null;
        if ($ok !== null) {
            return $ok;
        }
        try {
            $conn->query("
                CREATE TABLE IF NOT EXISTS app_support_tickets (
                    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    installation_id VARCHAR(80) NOT NULL DEFAULT '',
                    requester_user_id INT UNSIGNED DEFAULT NULL,
                    requester_name VARCHAR(190) NOT NULL DEFAULT '',
                    requester_email VARCHAR(190) NOT NULL DEFAULT '',
                    requester_phone VARCHAR(80) NOT NULL DEFAULT '',
                    subject VARCHAR(220) NOT NULL DEFAULT '',
                    priority ENUM('low','normal','high','urgent') NOT NULL DEFAULT 'normal',
                    status ENUM('open','pending','answered','closed') NOT NULL DEFAULT 'open',
                    remote_ticket_id BIGINT UNSIGNED DEFAULT NULL,
                    remote_client_ticket_id VARCHAR(120) DEFAULT NULL,
                    remote_license_key VARCHAR(180) DEFAULT NULL,
                    remote_client_domain VARCHAR(190) DEFAULT NULL,
                    remote_client_app_url VARCHAR(255) DEFAULT NULL,
                    remote_sync_error VARCHAR(255) NOT NULL DEFAULT '',
                    remote_last_sync_at DATETIME DEFAULT NULL,
                    last_message_at DATETIME NOT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX idx_support_tickets_status (status),
                    INDEX idx_support_tickets_last_message (last_message_at),
                    INDEX idx_support_tickets_requester (requester_user_id),
                    INDEX idx_support_tickets_remote_ticket_id (remote_ticket_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");

            $conn->query("
                CREATE TABLE IF NOT EXISTS app_support_ticket_messages (
                    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    ticket_id BIGINT UNSIGNED NOT NULL,
                    sender_user_id INT UNSIGNED DEFAULT NULL,
                    sender_role ENUM('client','support') NOT NULL DEFAULT 'client',
                    message TEXT DEFAULT NULL,
                    image_path VARCHAR(255) DEFAULT NULL,
                    image_name VARCHAR(190) NOT NULL DEFAULT '',
                    remote_message_id BIGINT UNSIGNED DEFAULT NULL,
                    is_read_by_client TINYINT(1) NOT NULL DEFAULT 0,
                    is_read_by_admin TINYINT(1) NOT NULL DEFAULT 0,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_support_messages_ticket (ticket_id),
                    INDEX idx_support_messages_remote_message (remote_message_id),
                    INDEX idx_support_messages_admin_read (is_read_by_admin),
                    INDEX idx_support_messages_client_read (is_read_by_client)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");

            $conn->query("
                CREATE TABLE IF NOT EXISTS app_support_notifications (
                    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    user_id INT UNSIGNED NOT NULL,
                    ticket_id BIGINT UNSIGNED DEFAULT NULL,
                    notif_type VARCHAR(50) NOT NULL DEFAULT '',
                    title VARCHAR(190) NOT NULL DEFAULT '',
                    message VARCHAR(255) NOT NULL DEFAULT '',
                    is_read TINYINT(1) NOT NULL DEFAULT 0,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_support_notifications_user (user_id),
                    INDEX idx_support_notifications_read (is_read),
                    INDEX idx_support_notifications_ticket (ticket_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");

            if (!app_table_has_column($conn, 'app_support_tickets', 'remote_ticket_id')) {
                $conn->query("ALTER TABLE app_support_tickets ADD COLUMN remote_ticket_id BIGINT UNSIGNED DEFAULT NULL");
            }
            if (!app_table_has_column($conn, 'app_support_tickets', 'remote_client_ticket_id')) {
                $conn->query("ALTER TABLE app_support_tickets ADD COLUMN remote_client_ticket_id VARCHAR(120) DEFAULT NULL");
            }
            if (!app_table_has_column($conn, 'app_support_tickets', 'remote_license_key')) {
                $conn->query("ALTER TABLE app_support_tickets ADD COLUMN remote_license_key VARCHAR(180) DEFAULT NULL");
            }
            if (!app_table_has_column($conn, 'app_support_tickets', 'remote_client_domain')) {
                $conn->query("ALTER TABLE app_support_tickets ADD COLUMN remote_client_domain VARCHAR(190) DEFAULT NULL");
            }
            if (!app_table_has_column($conn, 'app_support_tickets', 'remote_client_app_url')) {
                $conn->query("ALTER TABLE app_support_tickets ADD COLUMN remote_client_app_url VARCHAR(255) DEFAULT NULL");
            }
            if (!app_table_has_column($conn, 'app_support_tickets', 'remote_sync_error')) {
                $conn->query("ALTER TABLE app_support_tickets ADD COLUMN remote_sync_error VARCHAR(255) NOT NULL DEFAULT ''");
            }
            if (!app_table_has_column($conn, 'app_support_tickets', 'remote_last_sync_at')) {
                $conn->query("ALTER TABLE app_support_tickets ADD COLUMN remote_last_sync_at DATETIME DEFAULT NULL");
            }
            if (!app_table_has_column($conn, 'app_support_ticket_messages', 'remote_message_id')) {
                $conn->query("ALTER TABLE app_support_ticket_messages ADD COLUMN remote_message_id BIGINT UNSIGNED DEFAULT NULL");
            }
            if (!app_table_has_column($conn, 'app_support_ticket_messages', 'image_path')) {
                $conn->query("ALTER TABLE app_support_ticket_messages ADD COLUMN image_path VARCHAR(255) DEFAULT NULL");
            }
            if (!app_table_has_column($conn, 'app_support_ticket_messages', 'image_name')) {
                $conn->query("ALTER TABLE app_support_ticket_messages ADD COLUMN image_name VARCHAR(190) NOT NULL DEFAULT ''");
            }

            $ok = true;
        } catch (Throwable $e) {
            error_log('app_ensure_support_center_schema failed: ' . $e->getMessage());
            $ok = false;
        }
        return $ok;
    }
}

if (!function_exists('app_support_is_admin')) {
    function app_support_is_admin(): bool
    {
        app_start_session();
        $roleAdmin = strtolower((string)($_SESSION['role'] ?? '')) === 'admin';
        $super = function_exists('app_is_super_user') ? app_is_super_user() : false;
        return $roleAdmin || $super;
    }
}

if (!function_exists('app_support_admin_user_ids')) {
    function app_support_admin_user_ids(mysqli $conn): array
    {
        app_initialize_support_center($conn);
        $ids = [];
        try {
            $map = app_users_schema_map($conn);
            $idColumn = app_users_resolved_column($map, 'id');
            if ($idColumn === '') {
                return [];
            }
            $selectSql = app_users_select_alias_sql($map, ['id', 'role']);
            $res = $conn->query("SELECT {$selectSql} FROM users ORDER BY `{$idColumn}` ASC");
            if ($res) {
                while ($row = $res->fetch_assoc()) {
                    if (strtolower(trim((string)($row['role'] ?? 'employee'))) !== 'admin') {
                        continue;
                    }
                    $id = (int)($row['id'] ?? 0);
                    if ($id > 0) {
                        $ids[] = $id;
                    }
                }
            }
        } catch (Throwable $e) {
            return [];
        }
        return array_values(array_unique($ids));
    }
}

if (!function_exists('app_support_notify_users')) {
    function app_support_notify_users(mysqli $conn, array $userIds, int $ticketId, string $type, string $title, string $message, int $excludeUserId = 0): int
    {
        app_initialize_support_center($conn);
        $title = mb_substr(trim($title), 0, 190);
        $message = mb_substr(trim($message), 0, 255);
        $type = mb_substr(trim($type), 0, 50);
        if ($title === '' || $message === '' || $type === '') {
            return 0;
        }

        $done = 0;
        $stmt = $conn->prepare("
            INSERT INTO app_support_notifications (user_id, ticket_id, notif_type, title, message, is_read)
            VALUES (?, ?, ?, ?, ?, 0)
        ");
        if (!$stmt) {
            return 0;
        }
        foreach (array_values(array_unique($userIds)) as $uidRaw) {
            $uid = (int)$uidRaw;
            if ($uid <= 0 || ($excludeUserId > 0 && $uid === $excludeUserId)) {
                continue;
            }
            $stmt->bind_param('iisss', $uid, $ticketId, $type, $title, $message);
            if ($stmt->execute()) {
                $done++;
            }
        }
        $stmt->close();
        return $done;
    }
}

if (!function_exists('app_support_ticket_set_remote_state')) {
    function app_support_ticket_set_remote_state(
        mysqli $conn,
        int $ticketId,
        int $remoteTicketId,
        string $syncError,
        string $remoteLicenseKey = '',
        string $remoteDomain = '',
        string $remoteAppUrl = ''
    ): void {
        app_initialize_support_center($conn);
        if ($ticketId <= 0) {
            return;
        }
        $now = date('Y-m-d H:i:s');
        $syncError = mb_substr(trim($syncError), 0, 255);
        $licenseKey = $remoteLicenseKey !== '' ? mb_substr($remoteLicenseKey, 0, 180) : null;
        $remoteDomain = $remoteDomain !== '' ? mb_substr($remoteDomain, 0, 190) : null;
        $remoteAppUrl = $remoteAppUrl !== '' ? mb_substr($remoteAppUrl, 0, 255) : null;
        $remoteClientTicketId = (string)$ticketId;

        $stmt = $conn->prepare("
            UPDATE app_support_tickets
            SET remote_ticket_id = ?,
                remote_sync_error = ?,
                remote_last_sync_at = ?,
                remote_client_ticket_id = ?,
                remote_license_key = COALESCE(?, remote_license_key),
                remote_client_domain = COALESCE(?, remote_client_domain),
                remote_client_app_url = COALESCE(?, remote_client_app_url)
            WHERE id = ?
        ");
        $stmt->bind_param(
            'issssssi',
            $remoteTicketId,
            $syncError,
            $now,
            $remoteClientTicketId,
            $licenseKey,
            $remoteDomain,
            $remoteAppUrl,
            $ticketId
        );
        $stmt->execute();
        $stmt->close();
    }
}

if (!function_exists('app_support_attachment_public_url')) {
    function app_support_attachment_public_url(string $imagePath, string $baseUrl = ''): string
    {
        $imagePath = trim(str_replace('\\', '/', $imagePath));
        if ($imagePath === '') {
            return '';
        }
        if (preg_match('#^https?://#i', $imagePath) || strpos($imagePath, '//') === 0) {
            return $imagePath;
        }
        $baseUrl = rtrim(trim($baseUrl), '/');
        if ($baseUrl === '') {
            $baseUrl = rtrim(app_base_url(), '/');
        }
        return $baseUrl . '/' . ltrim($imagePath, '/');
    }
}

if (!function_exists('app_support_attachment_safe_name')) {
    function app_support_attachment_safe_name(string $imageName, string $imagePath = ''): string
    {
        $imageName = mb_substr(trim($imageName), 0, 190);
        if ($imageName !== '') {
            return $imageName;
        }
        $source = trim($imagePath);
        if ($source === '') {
            return '';
        }
        $pathOnly = (string)parse_url($source, PHP_URL_PATH);
        $basename = basename($pathOnly !== '' ? $pathOnly : $source);
        return mb_substr(trim($basename), 0, 190);
    }
}

if (!function_exists('app_support_attachment_is_local_path')) {
    function app_support_attachment_is_local_path(string $imagePath): bool
    {
        $imagePath = trim(str_replace('\\', '/', $imagePath));
        if ($imagePath === '' || preg_match('#^https?://#i', $imagePath) || strpos($imagePath, '//') === 0) {
            return false;
        }
        if (strpos($imagePath, '..') !== false) {
            return false;
        }
        $normalized = ltrim($imagePath, '/');
        return strpos($normalized, 'uploads/') === 0;
    }
}

if (!function_exists('app_support_attachment_delete_local')) {
    function app_support_attachment_delete_local(string $imagePath): void
    {
        if (!app_support_attachment_is_local_path($imagePath)) {
            return;
        }
        $target = ltrim(str_replace('\\', '/', trim($imagePath)), '/');
        if ($target === '') {
            return;
        }
        if (@is_file($target)) {
            @unlink($target);
        }
    }
}

if (!function_exists('app_support_remote_context')) {
    function app_support_remote_context(mysqli $conn): array
    {
        $row = app_license_row($conn);
        $remoteUrl = trim((string)($row['remote_url'] ?? ''));
        $remoteToken = trim((string)($row['remote_token'] ?? ''));
        if (app_license_url_looks_placeholder($remoteUrl)) {
            $remoteUrl = '';
        }
        if (app_license_token_looks_placeholder($remoteToken)) {
            $remoteToken = '';
        }
        if (app_license_url_is_local_or_private($remoteUrl)) {
            $remoteUrl = '';
        }
        if ($remoteUrl === '' || $remoteToken === '') {
            $guessed = app_license_guess_remote_from_cloud_sync($conn);
            if ($remoteUrl === '') {
                $remoteUrl = trim((string)($guessed['remote_url'] ?? ''));
            }
            if ($remoteToken === '') {
                $remoteToken = trim((string)($guessed['remote_token'] ?? ''));
            }
        }
        $licenseKey = strtoupper(trim((string)($row['license_key'] ?? '')));
        $domain = app_license_normalize_domain((string)parse_url(app_base_url(), PHP_URL_HOST));
        $appUrl = app_base_url();
        $installationId = trim((string)($row['installation_id'] ?? ''));
        $fingerprint = trim((string)($row['fingerprint'] ?? ''));

        if ($remoteUrl === '') {
            return ['ok' => false, 'error' => 'remote_not_configured'];
        }
        if ($licenseKey === '') {
            return ['ok' => false, 'error' => 'license_key_missing'];
        }
        if ($domain === '') {
            return ['ok' => false, 'error' => 'domain_missing'];
        }
        if ($installationId === '' || $fingerprint === '') {
            return ['ok' => false, 'error' => 'installation_missing'];
        }

        return [
            'ok' => true,
            'error' => '',
            'remote_url' => $remoteUrl,
            'remote_token' => $remoteToken,
            'license_key' => $licenseKey,
            'domain' => $domain,
            'app_url' => $appUrl,
            'installation_id' => $installationId,
            'fingerprint' => $fingerprint,
        ];
    }
}

if (!function_exists('app_support_remote_candidate_urls')) {
    function app_support_remote_candidate_urls(string $remoteUrl): array
    {
        $remoteUrl = trim($remoteUrl);
        if ($remoteUrl === '' || app_license_url_looks_placeholder($remoteUrl)) {
            return [];
        }

        $candidates = [];
        $parts = parse_url($remoteUrl);
        if (!is_array($parts)) {
            return [$remoteUrl];
        }

        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        $host = (string)($parts['host'] ?? '');
        if ($scheme === '' || $host === '') {
            return [$remoteUrl];
        }

        $base = $scheme . '://' . $host;
        if (isset($parts['port']) && (int)$parts['port'] > 0) {
            $base .= ':' . (int)$parts['port'];
        }
        $path = (string)($parts['path'] ?? '');
        $query = (string)($parts['query'] ?? '');

        if (preg_match('#/api/license/check/?$#i', $path)) {
            $candidates[] = rtrim($remoteUrl, '/') . '/index.php';
            $candidates[] = $remoteUrl;
            $candidates[] = $base . '/license_api.php';
            if ($scheme === 'http') {
                $candidates[] = 'https://' . $host . '/license_api.php';
            }
            if ($scheme === 'http') {
                $candidates[] = 'https://' . $host . '/api/license/check/index.php';
                $candidates[] = 'https://' . $host . '/license_api.php';
            }
        } elseif (preg_match('#/license_api\.php/?$#i', $path)) {
            $candidates[] = $remoteUrl;
            $candidates[] = $base . '/api/license/check/';
            $candidates[] = $base . '/api/license/check';
            $candidates[] = $base . '/api/license/check/index.php';
            if ($scheme === 'http') {
                $candidates[] = 'https://' . $host . '/api/license/check/';
                $candidates[] = 'https://' . $host . '/api/license/check';
                $candidates[] = 'https://' . $host . '/api/license/check/index.php';
            }
        } else {
            $candidates[] = $base . '/license_api.php';
            if ($scheme === 'http') {
                $candidates[] = 'https://' . $host . '/license_api.php';
            }
            $candidates[] = $remoteUrl;
            $candidates[] = $base . '/api/license/check/';
            $candidates[] = $base . '/api/license/check';
            if ($scheme === 'http') {
                $candidates[] = 'https://' . $host . '/api/license/check/';
                $candidates[] = 'https://' . $host . '/api/license/check';
            }
        }

        if ($scheme === 'http') {
            $httpsUrl = 'https://' . $host;
            if (isset($parts['port']) && (int)$parts['port'] > 0 && (int)$parts['port'] !== 80) {
                $httpsUrl .= ':' . (int)$parts['port'];
            }
            $httpsUrl .= $path;
            if ($query !== '') {
                $httpsUrl .= '?' . $query;
            }
            $candidates[] = $httpsUrl;
        }

        return array_values(array_unique(array_filter($candidates, static function ($v) {
            return is_string($v) && trim($v) !== '';
        })));
    }
}

if (!function_exists('app_support_remote_post')) {
    function app_support_remote_post(mysqli $conn, array $context, array $payload): array
    {
        $remoteUrl = (string)($context['remote_url'] ?? '');
        if ($remoteUrl === '') {
            return ['ok' => false, 'error' => 'remote_not_configured', 'body' => []];
        }

        $timeout = (int)($context['timeout'] ?? 7);
        if ($timeout < 2) {
            $timeout = 2;
        } elseif ($timeout > 20) {
            $timeout = 20;
        }
        $maxUrls = (int)($context['max_urls'] ?? 6);
        if ($maxUrls < 1) {
            $maxUrls = 1;
        } elseif ($maxUrls > 16) {
            $maxUrls = 16;
        }
        $maxTokens = (int)($context['max_tokens'] ?? 3);
        if ($maxTokens < 1) {
            $maxTokens = 1;
        } elseif ($maxTokens > 12) {
            $maxTokens = 12;
        }
        $maxAttempts = (int)($context['max_attempts'] ?? 6);
        if ($maxAttempts < 1) {
            $maxAttempts = 1;
        } elseif ($maxAttempts > 40) {
            $maxAttempts = 40;
        }
        $normalizeToken = static function (string $value): string {
            $clean = trim($value);
            if ($clean === '') {
                return '';
            }
            $clean = preg_replace('/[^A-Za-z0-9._:-]/', '', $clean);
            return is_string($clean) ? trim($clean) : '';
        };

        $tokenCandidates = [];
        $primaryToken = $normalizeToken((string)($context['remote_token'] ?? ''));
        if ($primaryToken !== '') {
            $tokenCandidates[] = $primaryToken;
        }
        $ownerApiToken = $normalizeToken((string)app_env('APP_LICENSE_API_TOKEN', ''));
        if ($ownerApiToken !== '' && !in_array($ownerApiToken, $tokenCandidates, true)) {
            $tokenCandidates[] = $ownerApiToken;
        }
        $ownerRemoteToken = $normalizeToken((string)app_env('APP_LICENSE_REMOTE_TOKEN', ''));
        if ($ownerRemoteToken !== '' && !in_array($ownerRemoteToken, $tokenCandidates, true)) {
            $tokenCandidates[] = $ownerRemoteToken;
        }
        $extraTokens = $context['extra_tokens'] ?? [];
        if (is_array($extraTokens)) {
            foreach ($extraTokens as $extraToken) {
                $extraToken = $normalizeToken((string)$extraToken);
                if ($extraToken !== '' && !in_array($extraToken, $tokenCandidates, true)) {
                    $tokenCandidates[] = $extraToken;
                }
            }
        }
        if (empty($tokenCandidates)) {
            $tokenCandidates[] = '';
        }
        $tokenCandidates = array_values(array_slice($tokenCandidates, 0, $maxTokens));

        $urls = [];
        $seedUrls = [$remoteUrl];
        $extraUrls = $context['extra_urls'] ?? [];
        if (is_array($extraUrls)) {
            foreach ($extraUrls as $u) {
                $u = trim((string)$u);
                if ($u !== '') {
                    $seedUrls[] = $u;
                }
            }
        }
        foreach ($seedUrls as $seedUrl) {
            $candidateSet = app_support_remote_candidate_urls($seedUrl);
            if (empty($candidateSet)) {
                $candidateSet = [trim((string)$seedUrl)];
            }
            foreach ($candidateSet as $candidateUrl) {
                $candidateUrl = trim((string)$candidateUrl);
                if ($candidateUrl !== '') {
                    $urls[] = $candidateUrl;
                }
            }
        }
        $urls = array_values(array_unique($urls));
        if (empty($urls)) {
            $urls = [$remoteUrl];
        }
        $urls = array_values(array_slice($urls, 0, $maxUrls));

        $lastErr = 'remote_error';
        $bestErr = 'remote_error';
        $bestErrScore = 0;
        $lastBody = [];
        $bestBody = [];
        $attempts = 0;
        $stop = false;
        $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($payloadJson)) {
            $payloadJson = '';
        }
        $extractRemoteErr = static function (array $http): string {
            $code = (int)($http['http_code'] ?? 0);
            $err = trim((string)($http['error'] ?? ''));
            $rawBody = (string)($http['body'] ?? '');
            if ($err === '') {
                if ($rawBody !== '') {
                    $decodedErr = json_decode($rawBody, true);
                    if (is_array($decodedErr)) {
                        $bodyErr = trim((string)($decodedErr['error'] ?? ''));
                        if ($bodyErr !== '') {
                            $err = $bodyErr;
                        }
                    } elseif ($code >= 400) {
                        $snippet = trim((string)preg_replace('/\s+/', ' ', strip_tags($rawBody)));
                        if ($snippet !== '') {
                            $snippet = mb_substr($snippet, 0, 160);
                            $err = 'http_' . $code . ':' . $snippet;
                        }
                    }
                }
            }
            if ($err === '') {
                $err = $code > 0 ? ('http_' . $code) : 'remote_error';
            }
            return $err;
        };
        $shouldTryFormFallback = static function (array $http): bool {
            $code = (int)($http['http_code'] ?? 0);
            $err = strtolower(trim((string)($http['error'] ?? '')));
            $body = strtolower((string)($http['body'] ?? ''));
            if (in_array($code, [400, 405, 406, 415, 431], true)) {
                return true;
            }
            if (strpos($err, 'bad request') !== false || strpos($err, 'http_400') !== false) {
                return true;
            }
            if (strpos($body, 'bad request') !== false || strpos($body, 'nginx') !== false || strpos($body, 'modsecurity') !== false) {
                return true;
            }
            return false;
        };
        $scoreErr = static function (string $err): int {
            $e = strtolower(trim($err));
            if ($e === '') {
                return 0;
            }
            if (in_array($e, ['unauthorized', 'bad_signature', 'timestamp_expired', 'replay_detected'], true)) {
                return 100;
            }
            if (in_array($e, ['license_mismatch', 'installation_mismatch', 'fingerprint_mismatch', 'domain_mismatch'], true)) {
                return 95;
            }
            if (in_array($e, ['missing_required_fields', 'invalid_timestamp', 'invalid_nonce'], true)) {
                return 90;
            }
            if (strpos($e, 'users_schema_incompatible') !== false || strpos($e, 'create_failed') !== false || strpos($e, 'update_failed') !== false) {
                return 85;
            }
            if (preg_match('/^http_(401|403|409|422)\b/i', $e)) {
                return 80;
            }
            if (preg_match('/^http_(500|502|503|504)\b/i', $e)) {
                return 70;
            }
            if ($e === 'invalid_json') {
                return 60;
            }
            if (preg_match('/^http_(301|302|307|308|400|404|405|406|415|431)\b/i', $e)) {
                return 50;
            }
            return 10;
        };
        $rememberErr = static function (string $err, array $body = []) use (&$bestErr, &$bestErrScore, &$bestBody, $scoreErr): void {
            $score = $scoreErr($err);
            if ($score > $bestErrScore) {
                $bestErrScore = $score;
                $bestErr = $err;
                $bestBody = $body;
            }
        };
        foreach ($urls as $url) {
            foreach ($tokenCandidates as $token) {
                $attempts++;
                $headers = [];
                if ($token !== '') {
                    $headers[] = 'Authorization: Bearer ' . $token;
                }
                $http = app_license_http_post_json($url, $payload, $headers, $timeout);
                if (empty($http['ok'])) {
                    $err = $extractRemoteErr($http);
                    if ($payloadJson !== '' && $shouldTryFormFallback($http)) {
                        $formFields = [
                            'payload_json' => $payloadJson,
                            'payload_b64' => base64_encode($payloadJson),
                            'event' => (string)($payload['event'] ?? ''),
                            'license_key' => (string)($payload['license_key'] ?? ''),
                            'installation_id' => (string)($payload['installation_id'] ?? ''),
                            'fingerprint' => (string)($payload['fingerprint'] ?? ''),
                            'domain' => (string)($payload['domain'] ?? ''),
                            'app_url' => (string)($payload['app_url'] ?? ''),
                        ];
                        if ($token !== '') {
                            $formFields['_auth'] = $token;
                        }
                        $httpForm = app_license_http_post_form($url, $formFields, $headers, $timeout);
                        if (!empty($httpForm['ok'])) {
                            $decodedForm = json_decode((string)($httpForm['body'] ?? ''), true);
                            if (is_array($decodedForm)) {
                                if (isset($decodedForm['ok']) && !$decodedForm['ok']) {
                                    $lastErr = (string)($decodedForm['error'] ?? 'remote_rejected');
                                    $lastBody = $decodedForm;
                                    continue;
                                }
                                return ['ok' => true, 'error' => '', 'body' => $decodedForm];
                            }
                            $err = 'invalid_json';
                        } else {
                            $errForm = $extractRemoteErr($httpForm);
                            if ($errForm !== '') {
                                $err = $errForm;
                            }
                        }
                    }
                    $lastErr = $err;
                    $rememberErr($err, $lastBody);
                    continue;
                }

                $decoded = json_decode((string)($http['body'] ?? ''), true);
                if (!is_array($decoded)) {
                    $lastErr = 'invalid_json';
                    $rememberErr($lastErr, $lastBody);
                    continue;
                }
                if (isset($decoded['ok']) && !$decoded['ok']) {
                    $lastErr = (string)($decoded['error'] ?? 'remote_rejected');
                    $lastBody = $decoded;
                    $rememberErr($lastErr, $lastBody);
                    continue;
                }

                return ['ok' => true, 'error' => '', 'body' => $decoded];
            }
            if ($attempts >= $maxAttempts) {
                $stop = true;
            }
            if ($stop) {
                break;
            }
        }
        if (!$stop && $attempts >= $maxAttempts) {
            $stop = true;
        }

        if ($bestErrScore > 0) {
            return ['ok' => false, 'error' => $bestErr, 'body' => $bestBody];
        }
        return ['ok' => false, 'error' => $lastErr, 'body' => $lastBody];
    }
}

if (!function_exists('app_license_client_confirm_link_code')) {
    function app_license_client_confirm_link_code(mysqli $conn, string $code): array
    {
        if (app_license_edition() !== 'client') {
            return ['ok' => false, 'error' => 'not_client_edition'];
        }
        $code = preg_replace('/[^0-9]/', '', trim($code));
        $code = is_string($code) ? $code : '';
        if (strlen($code) !== 6) {
            return ['ok' => false, 'error' => 'invalid_code'];
        }

        $ctx = app_support_remote_context($conn);
        if (empty($ctx['ok'])) {
            return ['ok' => false, 'error' => (string)($ctx['error'] ?? 'context_failed')];
        }

        $payload = [
            'event' => 'license_link_confirm',
            'code' => $code,
            'license_key' => (string)$ctx['license_key'],
            'installation_id' => (string)$ctx['installation_id'],
            'fingerprint' => (string)$ctx['fingerprint'],
            'domain' => (string)$ctx['domain'],
            'app_url' => (string)$ctx['app_url'],
            'support_report' => app_support_client_collect_system_report($conn, 400),
        ];

        $remote = app_support_remote_post($conn, $ctx, $payload);
        if (empty($remote['ok'])) {
            return ['ok' => false, 'error' => (string)($remote['error'] ?? 'remote_failed')];
        }
        $body = (isset($remote['body']) && is_array($remote['body'])) ? $remote['body'] : [];
        if (isset($body['ok']) && !$body['ok']) {
            return ['ok' => false, 'error' => (string)($body['error'] ?? 'remote_rejected'), 'body' => $body];
        }
        $licenseNode = (isset($body['license']) && is_array($body['license'])) ? $body['license'] : [];

        $row = app_license_row($conn);
        $envRemote = app_license_env_remote();
        $envKey = app_license_env_key();
        $remoteLocked = app_license_remote_lock_mode();

        $assignedKey = strtoupper(trim((string)($licenseNode['assigned_license_key'] ?? $body['assigned_license_key'] ?? '')));
        $assignedToken = trim((string)($licenseNode['assigned_api_token'] ?? $body['assigned_api_token'] ?? ''));
        $assignedRemoteUrl = trim((string)($licenseNode['assigned_remote_url'] ?? $body['assigned_remote_url'] ?? ''));

        $targetKey = strtoupper(trim((string)($row['license_key'] ?? '')));
        if ($assignedKey !== '' && ($envKey === '' || !$remoteLocked)) {
            $targetKey = mb_substr($assignedKey, 0, 180);
        }
        $targetRemoteToken = trim((string)($row['remote_token'] ?? ''));
        if ($assignedToken !== '' && ($envRemote['token'] === '' || !$remoteLocked)) {
            $targetRemoteToken = mb_substr($assignedToken, 0, 190);
        }
        $targetRemoteUrl = trim((string)($row['remote_url'] ?? ''));
        if ($assignedRemoteUrl !== '' && ($envRemote['url'] === '' || !$remoteLocked)) {
            $scheme = strtolower((string)parse_url($assignedRemoteUrl, PHP_URL_SCHEME));
            if (filter_var($assignedRemoteUrl, FILTER_VALIDATE_URL) && in_array($scheme, ['http', 'https'], true)) {
                $targetRemoteUrl = mb_substr($assignedRemoteUrl, 0, 255);
            }
        }

        $status = strtolower(trim((string)($licenseNode['status'] ?? $row['license_status'] ?? 'active')));
        if (!in_array($status, ['active', 'suspended', 'expired'], true)) {
            $status = (string)($row['license_status'] ?? 'active');
        }
        $plan = strtolower(trim((string)($licenseNode['plan'] ?? $row['plan_type'] ?? 'trial')));
        if (!in_array($plan, ['trial', 'subscription', 'lifetime'], true)) {
            $plan = (string)($row['plan_type'] ?? 'trial');
        }
        $ownerName = mb_substr(trim((string)($licenseNode['owner_name'] ?? $row['owner_name'] ?? '')), 0, 190);
        $trialEnds = trim((string)($licenseNode['trial_ends_at'] ?? $row['trial_ends_at'] ?? ''));
        $subscriptionEnds = trim((string)($licenseNode['subscription_ends_at'] ?? $row['subscription_ends_at'] ?? ''));
        $graceDays = max(0, min(60, (int)($licenseNode['grace_days'] ?? $row['grace_days'] ?? 3)));

        $stmt = $conn->prepare("
            UPDATE app_license_state
            SET license_key = ?, remote_url = ?, remote_token = ?,
                owner_name = ?, plan_type = ?, license_status = ?,
                trial_ends_at = ?, subscription_ends_at = ?, grace_days = ?,
                last_error = ''
            WHERE id = 1
        ");
        $stmt->bind_param(
            'ssssssssi',
            $targetKey,
            $targetRemoteUrl,
            $targetRemoteToken,
            $ownerName,
            $plan,
            $status,
            $trialEnds,
            $subscriptionEnds,
            $graceDays
        );
        $stmt->execute();
        $stmt->close();

        $sync = app_license_sync_remote($conn, true);
        $autoBind = app_cloud_sync_auto_bind_from_license($conn, [
            'remote_url' => $targetRemoteUrl,
            'remote_token' => $targetRemoteToken,
        ]);
        $cloudSync = app_cloud_sync_run($conn, true);

        return [
            'ok' => true,
            'error' => '',
            'license_key' => $targetKey,
            'remote_url' => $targetRemoteUrl,
            'remote_token' => $targetRemoteToken,
            'license' => $licenseNode,
            'sync' => $sync,
            'auto_bind' => $autoBind,
            'cloud_sync' => $cloudSync,
        ];
    }
}

if (!function_exists('app_license_client_bootstrap_from_owner')) {
    function app_license_client_bootstrap_from_owner(
        mysqli $conn,
        string $ownerApiUrl,
        string $ownerApiToken,
        string $preferredLicenseKey = '',
        array $options = []
    ): array {
        if (app_license_edition() !== 'client') {
            return ['ok' => false, 'error' => 'not_client_edition'];
        }
        app_initialize_license_data($conn);

        $ownerApiUrl = trim($ownerApiUrl);
        $ownerApiToken = trim($ownerApiToken);
        $preferredLicenseKey = strtoupper(trim($preferredLicenseKey));
        if ($ownerApiUrl === '') {
            return ['ok' => false, 'error' => 'remote_url_missing'];
        }
        if ($ownerApiToken === '') {
            return ['ok' => false, 'error' => 'remote_token_missing'];
        }
        $scheme = strtolower((string)parse_url($ownerApiUrl, PHP_URL_SCHEME));
        if (!filter_var($ownerApiUrl, FILTER_VALIDATE_URL) || !in_array($scheme, ['http', 'https'], true)) {
            return ['ok' => false, 'error' => 'remote_url_invalid'];
        }

        $ownerApiUrl = mb_substr($ownerApiUrl, 0, 255);
        $ownerApiToken = mb_substr($ownerApiToken, 0, 190);
        $linkedSystem = trim((string)($options['linked_system'] ?? ''));
        $row = app_license_row($conn);
        $licenseKey = $preferredLicenseKey !== '' ? $preferredLicenseKey : strtoupper(trim((string)($row['license_key'] ?? '')));
        $licenseKey = mb_substr($licenseKey, 0, 180);

        $payload = [
            'event' => 'license_auto_bootstrap',
            'license_key' => $licenseKey,
            'preferred_license_key' => $licenseKey,
            'installation_id' => (string)($row['installation_id'] ?? ''),
            'fingerprint' => (string)($row['fingerprint'] ?? ''),
            'domain' => app_license_normalize_domain((string)parse_url(app_base_url(), PHP_URL_HOST)),
            'app_url' => app_base_url(),
            'support_report' => app_support_client_collect_system_report($conn, 400),
        ];

        $remote = app_support_remote_post($conn, [
            'remote_url' => $ownerApiUrl,
            'remote_token' => $ownerApiToken,
            'timeout' => 8,
            'max_urls' => 8,
            'max_tokens' => 2,
            'max_attempts' => 8,
        ], $payload);
        if (empty($remote['ok'])) {
            return ['ok' => false, 'error' => (string)($remote['error'] ?? 'bootstrap_failed'), 'bootstrap' => $remote];
        }

        $body = (isset($remote['body']) && is_array($remote['body'])) ? $remote['body'] : [];
        $licenseNode = (isset($body['license']) && is_array($body['license'])) ? $body['license'] : [];
        $assignedKey = strtoupper(trim((string)($licenseNode['assigned_license_key'] ?? '')));
        $assignedToken = trim((string)($licenseNode['assigned_api_token'] ?? ''));
        $assignedRemoteUrl = trim((string)($licenseNode['assigned_remote_url'] ?? $ownerApiUrl));
        if ($assignedKey === '' || $assignedToken === '' || $assignedRemoteUrl === '') {
            return ['ok' => false, 'error' => 'bootstrap_payload_incomplete', 'body' => $body];
        }

        $status = strtolower(trim((string)($licenseNode['status'] ?? 'active')));
        if (!in_array($status, ['active', 'suspended', 'expired'], true)) {
            $status = 'active';
        }
        $plan = strtolower(trim((string)($licenseNode['plan'] ?? 'trial')));
        if (!in_array($plan, ['trial', 'subscription', 'lifetime'], true)) {
            $plan = 'trial';
        }
        $ownerName = mb_substr(trim((string)($licenseNode['owner_name'] ?? '')), 0, 190);
        $trialEnds = trim((string)($licenseNode['trial_ends_at'] ?? ''));
        $subscriptionEnds = trim((string)($licenseNode['subscription_ends_at'] ?? ''));
        $graceDays = max(0, min(60, (int)($licenseNode['grace_days'] ?? 3)));

        $stmt = $conn->prepare("
            UPDATE app_license_state
            SET remote_url = ?, remote_token = ?, license_key = ?,
                owner_name = ?, plan_type = ?, license_status = ?,
                trial_ends_at = ?, subscription_ends_at = ?, grace_days = ?, last_error = ''
            WHERE id = 1
        ");
        $stmt->bind_param(
            'ssssssssi',
            $assignedRemoteUrl,
            $assignedToken,
            $assignedKey,
            $ownerName,
            $plan,
            $status,
            $trialEnds,
            $subscriptionEnds,
            $graceDays
        );
        $stmt->execute();
        $stmt->close();

        $sync = app_license_sync_remote($conn, true);
        $finalRow = app_license_row($conn);
        $persist = app_license_client_persist_runtime_env($finalRow);
        if ($linkedSystem !== '') {
            app_setting_set($conn, 'desktop_node_linked_system', $linkedSystem);
            app_env_file_upsert(['APP_DESKTOP_LINKED_SYSTEM' => $linkedSystem], __DIR__ . '/.app_env');
        }

        return [
            'ok' => true,
            'error' => '',
            'bootstrap' => $body,
            'sync' => $sync,
            'env' => app_license_client_runtime_env_updates($finalRow),
            'persist_env' => $persist,
            'license_key' => (string)($finalRow['license_key'] ?? ''),
            'remote_url' => (string)($finalRow['remote_url'] ?? ''),
            'remote_token' => (string)($finalRow['remote_token'] ?? ''),
            'linked_system' => $linkedSystem,
        ];
    }
}
