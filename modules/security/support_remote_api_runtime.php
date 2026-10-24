<?php

if (!function_exists('app_support_owner_pull_client_snapshot')) {
    function app_support_owner_pull_client_snapshot(
        mysqli $conn,
        int $licenseId,
        string $remoteUrl,
        string $installationId,
        string $fingerprint,
        string $domain = '',
        string $appUrl = ''
    ): array {
        app_initialize_license_management($conn);
        if (app_license_edition() !== 'owner' || !app_is_super_user()) {
            return ['ok' => false, 'error' => 'super_user_required'];
        }
        $license = app_license_registry_get($conn, $licenseId);
        if (empty($license)) {
            return ['ok' => false, 'error' => 'license_not_found'];
        }

        $licenseKey = strtoupper(trim((string)($license['license_key'] ?? '')));
        $token = trim((string)($license['api_token'] ?? ''));
        $previousToken = trim((string)($license['previous_api_token'] ?? ''));
        if ($token === '') {
            $token = trim((string)app_env('APP_LICENSE_API_TOKEN', app_env('APP_LICENSE_REMOTE_TOKEN', '')));
        }
        $remoteUrl = trim($remoteUrl);
        $appUrl = trim($appUrl);
        $domain = app_license_normalize_domain($domain);

        if (app_license_url_looks_placeholder($remoteUrl)) {
            $remoteUrl = '';
        }
        if (app_license_url_is_local_or_private($remoteUrl)) {
            $remoteUrl = '';
        }
        if ($remoteUrl === '' && $appUrl !== '' && !app_license_url_looks_placeholder($appUrl)) {
            if (!app_license_url_is_local_or_private($appUrl)) {
                $remoteUrl = $appUrl;
            }
        }
        if ($remoteUrl === '' && $domain !== '' && !app_license_is_placeholder_remote_host($domain)) {
            $remoteUrl = 'https://' . $domain;
        }

        $installationId = mb_substr(trim($installationId), 0, 80);
        $fingerprint = mb_substr(trim($fingerprint), 0, 80);
        if ($installationId === '' || $fingerprint === '') {
            return ['ok' => false, 'error' => 'installation_missing'];
        }

        if ($remoteUrl === '') {
            $stmtRep = $conn->prepare("
                SELECT app_url, domain
                FROM app_support_client_reports
                WHERE license_id = ?
                  AND installation_id = ?
                  AND fingerprint = ?
                ORDER BY last_report_at DESC, id DESC
                LIMIT 25
            ");
            if ($stmtRep) {
                $stmtRep->bind_param('iss', $licenseId, $installationId, $fingerprint);
                $stmtRep->execute();
                $resRep = $stmtRep->get_result();
                while ($resRep && ($rowRep = $resRep->fetch_assoc())) {
                    $u = trim((string)($rowRep['app_url'] ?? ''));
                    $d = app_license_normalize_domain((string)($rowRep['domain'] ?? ''));
                    if ($u !== '' && !app_license_url_looks_placeholder($u) && !app_license_url_is_local_or_private($u)) {
                        $remoteUrl = $u;
                        break;
                    }
                    if ($d !== '' && !app_license_is_placeholder_remote_host($d) && !app_license_host_is_local_or_private($d)) {
                        $remoteUrl = 'https://' . $d;
                        break;
                    }
                }
                $stmtRep->close();
            }
        }

        if ($remoteUrl === '') {
            $stmtRun = $conn->prepare("
                SELECT app_url, domain
                FROM app_license_runtime_log
                WHERE license_id = ?
                  AND installation_id = ?
                  AND fingerprint = ?
                ORDER BY checked_at DESC, id DESC
                LIMIT 25
            ");
            if ($stmtRun) {
                $stmtRun->bind_param('iss', $licenseId, $installationId, $fingerprint);
                $stmtRun->execute();
                $resRun = $stmtRun->get_result();
                while ($resRun && ($rowRun = $resRun->fetch_assoc())) {
                    $u = trim((string)($rowRun['app_url'] ?? ''));
                    $d = app_license_normalize_domain((string)($rowRun['domain'] ?? ''));
                    if ($u !== '' && !app_license_url_looks_placeholder($u) && !app_license_url_is_local_or_private($u)) {
                        $remoteUrl = $u;
                        break;
                    }
                    if ($d !== '' && !app_license_is_placeholder_remote_host($d) && !app_license_host_is_local_or_private($d)) {
                        $remoteUrl = 'https://' . $d;
                        break;
                    }
                }
                $stmtRun->close();
            }
        }

        if ($remoteUrl === '' || app_license_url_looks_placeholder($remoteUrl)) {
            // Fallback: if owner cannot reach client URL directly, use latest cached report pushed from client.
            $stmtCached = $conn->prepare("
                SELECT users_count, admins_count, app_name
                FROM app_support_client_reports
                WHERE license_id = ?
                  AND installation_id = ?
                  AND fingerprint = ?
                ORDER BY last_report_at DESC, id DESC
                LIMIT 1
            ");
            if ($stmtCached) {
                $stmtCached->bind_param('iss', $licenseId, $installationId, $fingerprint);
                $stmtCached->execute();
                $cached = $stmtCached->get_result()->fetch_assoc() ?: [];
                $stmtCached->close();
                if (!empty($cached)) {
                    return [
                        'ok' => true,
                        'error' => '',
                        'cached' => true,
                        'users_count' => (int)($cached['users_count'] ?? 0),
                        'admins_count' => (int)($cached['admins_count'] ?? 0),
                        'app_name' => (string)($cached['app_name'] ?? ''),
                    ];
                }
            }
            return ['ok' => false, 'error' => 'client_url_missing'];
        }
        if (app_license_url_is_local_or_private($remoteUrl)) {
            // Same fallback for local/private URL deployments.
            $stmtCached = $conn->prepare("
                SELECT users_count, admins_count, app_name
                FROM app_support_client_reports
                WHERE license_id = ?
                  AND installation_id = ?
                  AND fingerprint = ?
                ORDER BY last_report_at DESC, id DESC
                LIMIT 1
            ");
            if ($stmtCached) {
                $stmtCached->bind_param('iss', $licenseId, $installationId, $fingerprint);
                $stmtCached->execute();
                $cached = $stmtCached->get_result()->fetch_assoc() ?: [];
                $stmtCached->close();
                if (!empty($cached)) {
                    return [
                        'ok' => true,
                        'error' => '',
                        'cached' => true,
                        'users_count' => (int)($cached['users_count'] ?? 0),
                        'admins_count' => (int)($cached['admins_count'] ?? 0),
                        'app_name' => (string)($cached['app_name'] ?? ''),
                    ];
                }
            }
            return ['ok' => false, 'error' => 'client_private_url_unreachable_from_owner'];
        }

        if ($domain === '' || app_license_is_placeholder_remote_host($domain)) {
            $domain = app_license_normalize_domain((string)parse_url($remoteUrl, PHP_URL_HOST));
        }
        if ($appUrl === '' || app_license_url_looks_placeholder($appUrl)) {
            $appUrl = $remoteUrl;
        }

        $ctx = [
            'remote_url' => $remoteUrl,
            'remote_token' => $token,
            'extra_tokens' => [$previousToken],
            'timeout' => 7,
            'max_urls' => 4,
            'max_tokens' => 3,
            'max_attempts' => 6,
        ];
        $payload = [
            'event' => 'support_system_snapshot',
            'license_key' => $licenseKey,
            'installation_id' => $installationId,
            'fingerprint' => $fingerprint,
            'domain' => $domain,
            'app_url' => $appUrl,
        ];
        $remote = app_support_remote_post($conn, $ctx, $payload);
        if (empty($remote['ok'])) {
            return ['ok' => false, 'error' => (string)($remote['error'] ?? 'remote_failed')];
        }
        $body = (isset($remote['body']) && is_array($remote['body'])) ? $remote['body'] : [];
        if (isset($body['ok']) && !$body['ok']) {
            return ['ok' => false, 'error' => (string)($body['error'] ?? 'remote_rejected'), 'body' => $body];
        }
        $report = (isset($body['report']) && is_array($body['report'])) ? $body['report'] : [];
        if (empty($report)) {
            return ['ok' => false, 'error' => 'report_missing'];
        }

        $stored = app_support_owner_store_client_report(
            $conn,
            $licenseId,
            $licenseKey,
            $domain,
            $appUrl,
            $installationId,
            $fingerprint,
            $report
        );
        if (!$stored) {
            return ['ok' => false, 'error' => 'store_report_failed'];
        }

        return [
            'ok' => true,
            'error' => '',
            'users_count' => (int)($report['users_count'] ?? 0),
            'admins_count' => (int)($report['admins_count'] ?? 0),
            'app_name' => (string)($report['app_name'] ?? ''),
        ];
    }
}

if (!function_exists('app_license_owner_push_credentials_to_clients')) {
    function app_license_owner_push_credentials_to_clients(mysqli $conn, int $licenseId, array $options = []): array
    {
        app_initialize_license_management($conn);
        if (app_license_edition() !== 'owner' || !app_is_super_user()) {
            return ['ok' => false, 'error' => 'super_user_required', 'pushed' => 0, 'failed' => 0];
        }
        // Keep push available for immediate propagation unless explicitly disabled in .app_env.
        $forcePush = !empty($options['force']);
        if (!$forcePush && app_env_flag('APP_LICENSE_PUSH_DISABLED', false)) {
            return ['ok' => true, 'error' => 'pull_only_mode', 'pushed' => 0, 'failed' => 0];
        }
        $license = app_license_registry_get($conn, $licenseId);
        if (empty($license)) {
            return ['ok' => false, 'error' => 'license_not_found', 'pushed' => 0, 'failed' => 0];
        }
        $licenseKey = strtoupper(trim((string)($license['license_key'] ?? '')));
        $apiToken = trim((string)($license['api_token'] ?? ''));
        $previousToken = trim((string)($license['previous_api_token'] ?? ''));
        $ownerApiUrl = rtrim((string)app_base_url(), '/') . '/license_api.php';
        $timeout = max(2, min(20, (int)($options['timeout'] ?? 5)));
        $maxUrlCandidates = max(1, min(16, (int)($options['max_urls'] ?? 4)));
        $maxTokenCandidates = max(1, min(12, (int)($options['max_tokens'] ?? 3)));
        $maxAttempts = max(1, min(20, (int)($options['max_attempts'] ?? 5)));
        $maxTargets = (int)($options['max_targets'] ?? 8);
        if ($maxTargets > 0) {
            $maxTargets = max(1, min(200, $maxTargets));
        }
        $scanLimit = $maxTargets > 0 ? max(12, min(400, $maxTargets * 4)) : 60;

        $targets = [];
        $stmtReports = $conn->prepare("
            SELECT app_url, domain, installation_id, fingerprint
            FROM app_support_client_reports
            WHERE license_id = ?
            ORDER BY last_report_at DESC
            LIMIT {$scanLimit}
        ");
        $stmtReports->bind_param('i', $licenseId);
        $stmtReports->execute();
        $resReports = $stmtReports->get_result();
        while ($resReports && ($row = $resReports->fetch_assoc())) {
            $remoteUrl = trim((string)($row['app_url'] ?? ''));
            $domain = app_license_normalize_domain((string)($row['domain'] ?? ''));
            if ($remoteUrl === '' && $domain !== '') {
                $remoteUrl = 'https://' . $domain;
            }
            $installationId = mb_substr(trim((string)($row['installation_id'] ?? '')), 0, 80);
            $fingerprint = mb_substr(trim((string)($row['fingerprint'] ?? '')), 0, 80);
            if ($remoteUrl === '' || $installationId === '' || $fingerprint === '') {
                continue;
            }
            $key = strtolower($remoteUrl . '|' . $installationId . '|' . $fingerprint);
            $targets[$key] = [
                'remote_url' => $remoteUrl,
                'domain' => $domain,
                'installation_id' => $installationId,
                'fingerprint' => $fingerprint,
            ];
        }
        $stmtReports->close();

        $stmtRuntime = $conn->prepare("
            SELECT app_url, domain, installation_id, fingerprint
            FROM app_license_runtime_log
            WHERE license_id = ?
            ORDER BY checked_at DESC, id DESC
            LIMIT {$scanLimit}
        ");
        $stmtRuntime->bind_param('i', $licenseId);
        $stmtRuntime->execute();
        $resRuntime = $stmtRuntime->get_result();
        while ($resRuntime && ($row = $resRuntime->fetch_assoc())) {
            $remoteUrl = trim((string)($row['app_url'] ?? ''));
            $domain = app_license_normalize_domain((string)($row['domain'] ?? ''));
            if ($remoteUrl === '' && $domain !== '') {
                $remoteUrl = 'https://' . $domain;
            }
            $installationId = mb_substr(trim((string)($row['installation_id'] ?? '')), 0, 80);
            $fingerprint = mb_substr(trim((string)($row['fingerprint'] ?? '')), 0, 80);
            if ($remoteUrl === '' || $installationId === '' || $fingerprint === '') {
                continue;
            }
            $key = strtolower($remoteUrl . '|' . $installationId . '|' . $fingerprint);
            if (!isset($targets[$key])) {
                $targets[$key] = [
                    'remote_url' => $remoteUrl,
                    'domain' => $domain,
                    'installation_id' => $installationId,
                    'fingerprint' => $fingerprint,
                ];
            }
        }
        $stmtRuntime->close();

        if (empty($targets)) {
            return ['ok' => true, 'error' => '', 'pushed' => 0, 'failed' => 0];
        }
        if ($maxTargets > 0 && count($targets) > $maxTargets) {
            $targets = array_slice($targets, 0, $maxTargets, true);
        }

        $pushed = 0;
        $failed = 0;
        $lastError = '';
        foreach ($targets as $target) {
            $payload = [
                'event' => 'owner_credentials_push',
                'license_key' => $licenseKey,
                'installation_id' => (string)$target['installation_id'],
                'fingerprint' => (string)$target['fingerprint'],
                'domain' => (string)$target['domain'],
                'app_url' => (string)$target['remote_url'],
                'credentials' => [
                    'license_key' => $licenseKey,
                    'remote_url' => $ownerApiUrl,
                    'remote_token' => $apiToken,
                ],
            ];
            $ctx = [
                'remote_url' => (string)$target['remote_url'],
                'remote_token' => $apiToken,
                'extra_tokens' => [$previousToken],
                'timeout' => $timeout,
                'max_urls' => $maxUrlCandidates,
                'max_tokens' => $maxTokenCandidates,
                'max_attempts' => $maxAttempts,
            ];
            $remote = app_support_remote_post($conn, $ctx, $payload);
            if (!empty($remote['ok'])) {
                $pushed++;
            } else {
                $failed++;
                $lastError = (string)($remote['error'] ?? 'remote_failed');
            }
        }

        return [
            'ok' => true,
            'error' => $lastError,
            'pushed' => $pushed,
            'failed' => $failed,
        ];
    }
}

if (!function_exists('app_support_remote_push_ticket_create')) {
    function app_support_remote_push_ticket_create(
        mysqli $conn,
        int $ticketId,
        array $payload,
        string $initialMessage,
        string $imagePath = '',
        string $imageName = ''
    ): array
    {
        if (app_license_edition() !== 'client') {
            return ['ok' => false, 'error' => 'not_client_edition'];
        }

        $ctx = app_support_remote_context($conn);
        if (empty($ctx['ok'])) {
            return ['ok' => false, 'error' => (string)($ctx['error'] ?? 'context_failed')];
        }

        $subject = mb_substr(trim((string)($payload['subject'] ?? 'Support Request')), 0, 220);
        if ($subject === '') {
            $subject = 'Support Request';
        }
        $priority = strtolower(trim((string)($payload['priority'] ?? 'normal')));
        if (!in_array($priority, ['low', 'normal', 'high', 'urgent'], true)) {
            $priority = 'normal';
        }

        $body = [
            'event' => 'support_ticket_create',
            'license_key' => (string)$ctx['license_key'],
            'installation_id' => (string)$ctx['installation_id'],
            'fingerprint' => (string)$ctx['fingerprint'],
            'domain' => (string)$ctx['domain'],
            'app_url' => (string)$ctx['app_url'],
            'ticket' => [
                'client_ticket_id' => (string)$ticketId,
                'subject' => $subject,
                'priority' => $priority,
                'status' => 'open',
                'requester_name' => (string)($payload['requester_name'] ?? ''),
                'requester_email' => (string)($payload['requester_email'] ?? ''),
                'requester_phone' => (string)($payload['requester_phone'] ?? ''),
                'message' => $initialMessage,
                'image_url' => app_support_attachment_public_url($imagePath, (string)$ctx['app_url']),
                'image_name' => app_support_attachment_safe_name($imageName, $imagePath),
            ],
        ];

        $remote = app_support_remote_post($conn, $ctx, $body);
        if (empty($remote['ok'])) {
            return ['ok' => false, 'error' => (string)($remote['error'] ?? 'remote_failed')];
        }

        $remoteTicketId = (int)($remote['body']['ticket_id'] ?? 0);
        if ($remoteTicketId <= 0) {
            if (isset($remote['body']['license']) && is_array($remote['body']['license'])) {
                return ['ok' => false, 'error' => 'owner_api_not_updated'];
            }
            return ['ok' => false, 'error' => 'remote_ticket_missing'];
        }

        return [
            'ok' => true,
            'error' => '',
            'remote_ticket_id' => $remoteTicketId,
            'license_key' => (string)$ctx['license_key'],
            'domain' => (string)$ctx['domain'],
            'app_url' => (string)$ctx['app_url'],
        ];
    }
}

if (!function_exists('app_support_remote_push_ticket_reply')) {
    function app_support_remote_push_ticket_reply(
        mysqli $conn,
        array $ticket,
        string $message,
        string $status = '',
        string $imagePath = '',
        string $imageName = ''
    ): array
    {
        if (app_license_edition() !== 'client') {
            return ['ok' => false, 'error' => 'not_client_edition'];
        }

        $ticketId = (int)($ticket['id'] ?? 0);
        if ($ticketId <= 0) {
            return ['ok' => false, 'error' => 'ticket_not_found'];
        }

        $ctx = app_support_remote_context($conn);
        if (empty($ctx['ok'])) {
            return ['ok' => false, 'error' => (string)($ctx['error'] ?? 'context_failed')];
        }

        $remoteTicketId = (int)($ticket['remote_ticket_id'] ?? 0);
        $body = [
            'event' => 'support_ticket_reply',
            'license_key' => (string)$ctx['license_key'],
            'installation_id' => (string)$ctx['installation_id'],
            'fingerprint' => (string)$ctx['fingerprint'],
            'domain' => (string)$ctx['domain'],
            'app_url' => (string)$ctx['app_url'],
            'ticket' => [
                'remote_ticket_id' => $remoteTicketId > 0 ? $remoteTicketId : null,
                'client_ticket_id' => (string)$ticketId,
                'status' => $status,
                'message' => $message,
                'image_url' => app_support_attachment_public_url($imagePath, (string)$ctx['app_url']),
                'image_name' => app_support_attachment_safe_name($imageName, $imagePath),
            ],
        ];

        $remote = app_support_remote_post($conn, $ctx, $body);
        if (empty($remote['ok'])) {
            return ['ok' => false, 'error' => (string)($remote['error'] ?? 'remote_failed')];
        }

        $returnedTicketId = (int)($remote['body']['ticket_id'] ?? 0);
        if ($returnedTicketId <= 0) {
            if (isset($remote['body']['license']) && is_array($remote['body']['license'])) {
                return ['ok' => false, 'error' => 'owner_api_not_updated'];
            }
            return ['ok' => false, 'error' => 'remote_ticket_missing'];
        }

        return [
            'ok' => true,
            'error' => '',
            'remote_ticket_id' => $returnedTicketId,
            'license_key' => (string)$ctx['license_key'],
            'domain' => (string)$ctx['domain'],
            'app_url' => (string)$ctx['app_url'],
        ];
    }
}

if (!function_exists('app_support_remote_context_for_ticket')) {
    function app_support_remote_context_for_ticket(mysqli $conn, array $ticket): array
    {
        if (app_license_edition() === 'client') {
            return app_support_remote_context($conn);
        }

        $remoteUrl = trim((string)($ticket['remote_client_app_url'] ?? ''));
        $remoteDomain = app_license_normalize_domain((string)($ticket['remote_client_domain'] ?? ''));
        if ($remoteUrl === '' && $remoteDomain !== '') {
            $remoteUrl = 'https://' . $remoteDomain;
        }
        $licenseKey = strtoupper(trim((string)($ticket['remote_license_key'] ?? '')));
        if ($remoteUrl === '' || $licenseKey === '') {
            return ['ok' => false, 'error' => 'remote_not_configured'];
        }

        $domainFromUrl = app_license_normalize_domain((string)parse_url($remoteUrl, PHP_URL_HOST));
        if ($remoteDomain === '') {
            $remoteDomain = $domainFromUrl;
        }
        if ($remoteDomain === '') {
            return ['ok' => false, 'error' => 'domain_missing'];
        }

        $remoteToken = '';
        if ($licenseKey !== '') {
            $ownerLicense = app_license_registry_by_key($conn, $licenseKey);
            $remoteToken = trim((string)($ownerLicense['api_token'] ?? ''));
        }
        if ($remoteToken === '') {
            $remoteToken = trim((string)app_env('APP_LICENSE_API_TOKEN', app_env('APP_LICENSE_REMOTE_TOKEN', '')));
        }
        $selfLicense = app_license_row($conn);

        return [
            'ok' => true,
            'error' => '',
            'remote_url' => $remoteUrl,
            'remote_token' => $remoteToken,
            'license_key' => $licenseKey,
            'domain' => $remoteDomain,
            'app_url' => app_base_url(),
            'installation_id' => trim((string)($selfLicense['installation_id'] ?? '')),
            'fingerprint' => trim((string)($selfLicense['fingerprint'] ?? '')),
        ];
    }
}

if (!function_exists('app_support_remote_push_ticket_delete')) {
    function app_support_remote_push_ticket_delete(mysqli $conn, array $ticket): array
    {
        $ticketId = (int)($ticket['id'] ?? 0);
        if ($ticketId <= 0) {
            return ['ok' => false, 'error' => 'ticket_not_found'];
        }

        $ctx = app_support_remote_context_for_ticket($conn, $ticket);
        if (empty($ctx['ok'])) {
            return ['ok' => false, 'error' => (string)($ctx['error'] ?? 'context_failed')];
        }

        $clientTicketId = trim((string)($ticket['remote_client_ticket_id'] ?? ''));
        $remoteTicketId = 0;
        if (app_license_edition() === 'client') {
            $remoteTicketId = (int)($ticket['remote_ticket_id'] ?? 0);
            if ($clientTicketId === '') {
                $clientTicketId = (string)$ticketId;
            }
        } else {
            if ($clientTicketId === '') {
                $clientTicketId = (string)$ticketId;
            }
            if (preg_match('/^[0-9]+$/', $clientTicketId)) {
                $remoteTicketId = (int)$clientTicketId;
            } else {
                $remoteTicketId = (int)($ticket['remote_ticket_id'] ?? 0);
            }
        }

        $body = [
            'event' => 'support_ticket_delete',
            'license_key' => (string)$ctx['license_key'],
            'installation_id' => (string)($ctx['installation_id'] ?? ''),
            'fingerprint' => (string)($ctx['fingerprint'] ?? ''),
            'domain' => (string)$ctx['domain'],
            'app_url' => (string)$ctx['app_url'],
            'ticket' => [
                'remote_ticket_id' => $remoteTicketId > 0 ? $remoteTicketId : null,
                'client_ticket_id' => $clientTicketId,
            ],
        ];

        $remote = app_support_remote_post($conn, $ctx, $body);
        if (empty($remote['ok'])) {
            return ['ok' => false, 'error' => (string)($remote['error'] ?? 'remote_failed')];
        }

        return ['ok' => true, 'error' => '', 'deleted' => !empty($remote['body']['deleted'])];
    }
}

if (!function_exists('app_support_ticket_max_remote_message_id')) {
    function app_support_ticket_max_remote_message_id(mysqli $conn, int $ticketId): int
    {
        app_initialize_support_center($conn);
        if ($ticketId <= 0) {
            return 0;
        }
        $stmt = $conn->prepare("
            SELECT MAX(remote_message_id) AS mx
            FROM app_support_ticket_messages
            WHERE ticket_id = ? AND remote_message_id IS NOT NULL
        ");
        $stmt->bind_param('i', $ticketId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: [];
        $stmt->close();
        return max(0, (int)($row['mx'] ?? 0));
    }
}

if (!function_exists('app_support_ticket_find_by_remote_refs')) {
    function app_support_ticket_find_by_remote_refs(mysqli $conn, string $licenseKey, int $remoteTicketId, string $clientTicketId): array
    {
        app_initialize_support_center($conn);
        if ($remoteTicketId > 0) {
            $stmtById = $conn->prepare("SELECT * FROM app_support_tickets WHERE id = ? LIMIT 1");
            $stmtById->bind_param('i', $remoteTicketId);
            $stmtById->execute();
            $rowById = $stmtById->get_result()->fetch_assoc() ?: [];
            $stmtById->close();
            if (!empty($rowById)) {
                return $rowById;
            }
        }
        if ($licenseKey !== '' && $clientTicketId !== '') {
            $stmt = $conn->prepare("
                SELECT *
                FROM app_support_tickets
                WHERE remote_license_key = ? AND remote_client_ticket_id = ?
                ORDER BY id DESC
                LIMIT 1
            ");
            $stmt->bind_param('ss', $licenseKey, $clientTicketId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc() ?: [];
            $stmt->close();
            return $row;
        }
        return [];
    }
}

if (!function_exists('app_support_remote_pull_ticket_updates')) {
    function app_support_remote_pull_ticket_updates(mysqli $conn, int $ticketId): array
    {
        if (app_license_edition() !== 'client') {
            return ['ok' => false, 'error' => 'not_client_edition', 'new_messages' => 0];
        }
        $ticket = app_support_ticket_get($conn, $ticketId);
        if (!$ticket) {
            return ['ok' => false, 'error' => 'ticket_not_found', 'new_messages' => 0];
        }

        $ctx = app_support_remote_context($conn);
        if (empty($ctx['ok'])) {
            app_support_ticket_set_remote_state($conn, $ticketId, (int)($ticket['remote_ticket_id'] ?? 0), (string)($ctx['error'] ?? 'context_failed'));
            return ['ok' => false, 'error' => (string)($ctx['error'] ?? 'context_failed'), 'new_messages' => 0];
        }

        $remoteTicketId = (int)($ticket['remote_ticket_id'] ?? 0);
        $clientTicketId = trim((string)($ticket['remote_client_ticket_id'] ?? ''));
        if ($clientTicketId === '') {
            $clientTicketId = (string)$ticketId;
        }
        $sinceRemoteMessageId = app_support_ticket_max_remote_message_id($conn, $ticketId);

        $payload = [
            'event' => 'support_ticket_pull',
            'license_key' => (string)$ctx['license_key'],
            'installation_id' => (string)$ctx['installation_id'],
            'fingerprint' => (string)$ctx['fingerprint'],
            'domain' => (string)$ctx['domain'],
            'app_url' => (string)$ctx['app_url'],
            'ticket' => [
                'remote_ticket_id' => $remoteTicketId > 0 ? $remoteTicketId : null,
                'client_ticket_id' => $clientTicketId,
                'since_remote_message_id' => $sinceRemoteMessageId,
            ],
        ];

        $remote = app_support_remote_post($conn, $ctx, $payload);
        if (empty($remote['ok'])) {
            $remoteErr = (string)($remote['error'] ?? 'remote_pull_failed');
            if ($remoteErr === 'ticket_not_found') {
                $deletedLocal = app_support_ticket_delete_local($conn, $ticketId);
                if (!empty($deletedLocal['ok'])) {
                    return ['ok' => true, 'error' => '', 'new_messages' => 0, 'deleted' => true];
                }
            }
            app_support_ticket_set_remote_state(
                $conn,
                $ticketId,
                $remoteTicketId,
                $remoteErr
            );
            return ['ok' => false, 'error' => $remoteErr, 'new_messages' => 0];
        }

        $body = (isset($remote['body']) && is_array($remote['body'])) ? $remote['body'] : [];
        $remoteTicketIdResp = (int)($body['ticket_id'] ?? 0);
        if ($remoteTicketIdResp > 0 && $remoteTicketId <= 0) {
            $remoteTicketId = $remoteTicketIdResp;
        }
        $ticketStatus = strtolower(trim((string)($body['ticket_status'] ?? $ticket['status'] ?? 'open')));
        if (!in_array($ticketStatus, ['open', 'pending', 'answered', 'closed'], true)) {
            $ticketStatus = strtolower(trim((string)($ticket['status'] ?? 'open')));
        }
        $messages = (isset($body['messages']) && is_array($body['messages'])) ? $body['messages'] : [];

        $newMessages = 0;
        $ownerName = trim((string)($body['owner_name'] ?? app_tr('خدمة العملاء', 'Support')));
        if ($ownerName === '') {
            $ownerName = app_tr('خدمة العملاء', 'Support');
        }

        foreach ($messages as $msgNode) {
            if (!is_array($msgNode)) {
                continue;
            }
            $remoteMsgId = max(0, (int)($msgNode['remote_message_id'] ?? $msgNode['id'] ?? 0));
            $text = trim((string)($msgNode['message'] ?? ''));
            $imageUrl = trim((string)($msgNode['image_url'] ?? $msgNode['image_path'] ?? ''));
            $imageName = app_support_attachment_safe_name((string)($msgNode['image_name'] ?? ''), $imageUrl);
            if ($remoteMsgId <= 0 || ($text === '' && $imageUrl === '')) {
                continue;
            }
            $stmtCheck = $conn->prepare("
                SELECT id
                FROM app_support_ticket_messages
                WHERE ticket_id = ? AND remote_message_id = ?
                LIMIT 1
            ");
            $stmtCheck->bind_param('ii', $ticketId, $remoteMsgId);
            $stmtCheck->execute();
            $exists = $stmtCheck->get_result()->fetch_assoc() ?: [];
            $stmtCheck->close();
            if (!empty($exists)) {
                continue;
            }

            $stmtIn = $conn->prepare("
                INSERT INTO app_support_ticket_messages (
                    ticket_id, sender_user_id, sender_role, message, image_path, image_name, remote_message_id, is_read_by_client, is_read_by_admin
                ) VALUES (?, NULL, 'support', ?, ?, ?, ?, 0, 1)
            ");
            $stmtIn->bind_param('isssi', $ticketId, $text, $imageUrl, $imageName, $remoteMsgId);
            $stmtIn->execute();
            $stmtIn->close();
            $newMessages++;
        }

        $now = date('Y-m-d H:i:s');
        $stmtTicket = $conn->prepare("
            UPDATE app_support_tickets
            SET status = ?, last_message_at = ?, remote_ticket_id = ?, remote_sync_error = '', remote_last_sync_at = ?,
                remote_license_key = ?, remote_client_domain = ?, remote_client_app_url = ?,
                remote_client_ticket_id = ?
            WHERE id = ?
        ");
        $licenseKey = (string)($ctx['license_key'] ?? '');
        $domain = (string)($ctx['domain'] ?? '');
        $appUrl = (string)($ctx['app_url'] ?? '');
        $clientTicketKey = (string)$clientTicketId;
        $stmtTicket->bind_param('ssisssssi', $ticketStatus, $now, $remoteTicketId, $now, $licenseKey, $domain, $appUrl, $clientTicketKey, $ticketId);
        $stmtTicket->execute();
        $stmtTicket->close();

        if ($newMessages > 0) {
            $ownerId = (int)($ticket['requester_user_id'] ?? 0);
            if ($ownerId > 0) {
                app_support_notify_users(
                    $conn,
                    [$ownerId],
                    $ticketId,
                    'ticket_reply',
                    app_tr('رد جديد من خدمة العملاء', 'New support reply'),
                    $ownerName . ': ' . app_tr('تم استلام رد جديد على تذكرتك.', 'You received a new reply on your ticket.')
                );
            }
        }

        return ['ok' => true, 'error' => '', 'new_messages' => $newMessages];
    }
}

if (!function_exists('app_support_api_issue_password_reset_link')) {
    function app_support_api_issue_password_reset_link(mysqli $conn, array $payload): array
    {
        app_initialize_support_center($conn);

        $licenseKey = strtoupper(trim((string)($payload['license_key'] ?? '')));
        $domain = app_license_normalize_domain((string)($payload['domain'] ?? ''));
        $installationId = trim((string)($payload['installation_id'] ?? ''));
        $fingerprint = trim((string)($payload['fingerprint'] ?? ''));
        $userNode = (isset($payload['user']) && is_array($payload['user'])) ? $payload['user'] : [];
        $remoteUserId = (int)($userNode['remote_user_id'] ?? 0);
        $username = trim((string)($userNode['username'] ?? ''));
        $email = trim((string)($userNode['email'] ?? ''));
        $phone = trim((string)($userNode['phone'] ?? ''));
        $ttlMinutes = max(10, min(180, (int)($payload['ttl_minutes'] ?? 30)));

        if ($licenseKey === '' || $installationId === '' || $fingerprint === '') {
            return ['http_code' => 422, 'body' => ['ok' => false, 'error' => 'missing_required_fields']];
        }
        if ($remoteUserId <= 0 && $username === '' && $email === '' && $phone === '') {
            return ['http_code' => 422, 'body' => ['ok' => false, 'error' => 'user_identity_required']];
        }

        $row = app_license_row($conn);
        $localLicenseKey = strtoupper(trim((string)($row['license_key'] ?? '')));
        if ($localLicenseKey !== '' && $licenseKey !== $localLicenseKey) {
            return ['http_code' => 403, 'body' => ['ok' => false, 'error' => 'license_mismatch']];
        }
        $localInstallationId = trim((string)($row['installation_id'] ?? ''));
        if ($localInstallationId !== '' && $installationId !== '' && $installationId !== $localInstallationId) {
            return ['http_code' => 403, 'body' => ['ok' => false, 'error' => 'installation_mismatch']];
        }
        $localFingerprint = trim((string)($row['fingerprint'] ?? ''));
        if ($localFingerprint !== '' && $fingerprint !== '' && $fingerprint !== $localFingerprint) {
            return ['http_code' => 403, 'body' => ['ok' => false, 'error' => 'fingerprint_mismatch']];
        }

        if ($domain !== '') {
            $localDomain = app_license_normalize_domain((string)parse_url(app_base_url(), PHP_URL_HOST));
            if ($localDomain !== '' && $domain !== $localDomain) {
                return ['http_code' => 403, 'body' => ['ok' => false, 'error' => 'domain_mismatch']];
            }
        }

        $foundUser = [];
        if ($remoteUserId > 0) {
            $stmt = $conn->prepare("SELECT id, username, full_name, email, phone FROM users WHERE id = ? LIMIT 1");
            $stmt->bind_param('i', $remoteUserId);
            $stmt->execute();
            $foundUser = $stmt->get_result()->fetch_assoc() ?: [];
            $stmt->close();
        }
        if (empty($foundUser) && $email !== '') {
            $stmt = $conn->prepare("SELECT id, username, full_name, email, phone FROM users WHERE LOWER(email) = LOWER(?) LIMIT 1");
            $stmt->bind_param('s', $email);
            $stmt->execute();
            $foundUser = $stmt->get_result()->fetch_assoc() ?: [];
            $stmt->close();
        }
        if (empty($foundUser) && $username !== '') {
            $stmt = $conn->prepare("SELECT id, username, full_name, email, phone FROM users WHERE LOWER(username) = LOWER(?) LIMIT 1");
            $stmt->bind_param('s', $username);
            $stmt->execute();
            $foundUser = $stmt->get_result()->fetch_assoc() ?: [];
            $stmt->close();
        }
        if (empty($foundUser) && $phone !== '') {
            $stmt = $conn->prepare("SELECT id, username, full_name, email, phone FROM users WHERE phone = ? LIMIT 1");
            $stmt->bind_param('s', $phone);
            $stmt->execute();
            $foundUser = $stmt->get_result()->fetch_assoc() ?: [];
            $stmt->close();
        }
        if (empty($foundUser)) {
            return ['http_code' => 404, 'body' => ['ok' => false, 'error' => 'user_not_found']];
        }

        $issued = app_password_reset_issue_for_user(
            $conn,
            (int)($foundUser['id'] ?? 0),
            $ttlMinutes,
            mb_substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45)
        );
        if (empty($issued['ok'])) {
            return ['http_code' => 500, 'body' => ['ok' => false, 'error' => (string)($issued['error'] ?? 'issue_failed')]];
        }

        return [
            'http_code' => 200,
            'body' => [
                'ok' => true,
                'reset_link' => (string)($issued['reset_link'] ?? ''),
                'expires_at' => (string)($issued['expires_at'] ?? ''),
                'ttl_minutes' => (int)($issued['ttl_minutes'] ?? $ttlMinutes),
                'user' => (array)($issued['user'] ?? []),
            ],
        ];
    }
}

if (!function_exists('app_support_api_validate_client_target')) {
    function app_support_api_validate_client_target(mysqli $conn, array $payload): array
    {
        $licenseKey = strtoupper(trim((string)($payload['license_key'] ?? '')));
        $domain = app_license_normalize_domain((string)($payload['domain'] ?? ''));
        $installationId = trim((string)($payload['installation_id'] ?? ''));
        $fingerprint = trim((string)($payload['fingerprint'] ?? ''));

        $row = app_license_row($conn);
        $localLicenseKey = strtoupper(trim((string)($row['license_key'] ?? '')));
        if ($licenseKey === '') {
            $licenseKey = $localLicenseKey;
        }
        if ($licenseKey === '') {
            return ['ok' => false, 'http_code' => 422, 'error' => 'missing_required_fields'];
        }
        if ($localLicenseKey !== '' && $licenseKey !== $localLicenseKey) {
            return ['ok' => false, 'http_code' => 403, 'error' => 'license_mismatch'];
        }

        $localInstallationId = trim((string)($row['installation_id'] ?? ''));
        if ($installationId === '' && $localInstallationId !== '') {
            $installationId = $localInstallationId;
        }
        $localFingerprint = trim((string)($row['fingerprint'] ?? ''));
        if ($fingerprint === '' && $localFingerprint !== '') {
            $fingerprint = $localFingerprint;
        }
        if ($installationId === '' || $fingerprint === '') {
            return ['ok' => false, 'http_code' => 422, 'error' => 'missing_required_fields'];
        }

        if ($domain !== '') {
            $localDomain = app_license_normalize_domain((string)parse_url(app_base_url(), PHP_URL_HOST));
            if ($localDomain !== '' && $domain !== $localDomain) {
                $domain = $localDomain;
            }
        }

        return ['ok' => true, 'http_code' => 200, 'error' => ''];
    }
}

if (!function_exists('app_stmt_bind_dynamic_params')) {
    function app_stmt_bind_dynamic_params(mysqli_stmt $stmt, string $types, array &$values): bool
    {
        if ($types === '' || empty($values)) {
            return true;
        }
        $args = [];
        $args[] = &$types;
        foreach ($values as $idx => &$value) {
            $args[] = &$value;
            unset($idx);
        }
        return (bool)call_user_func_array([$stmt, 'bind_param'], $args);
    }
}

if (!function_exists('app_users_normalize_role_value')) {
    function app_users_normalize_role_value(string $roleRaw, $isAdminValue = null): string
    {
        $role = strtolower(trim($roleRaw));
        if ($role === '') {
            $role = 'employee';
        }
        if ($role === 'user') {
            $role = 'employee';
        }
        if ($isAdminValue !== null) {
            $flag = (string)$isAdminValue;
            if ($flag === '1' || strtolower($flag) === 'yes' || strtolower($flag) === 'true') {
                return 'admin';
            }
        }
        return $role === 'admin' ? 'admin' : 'employee';
    }
}

if (!function_exists('app_users_schema_map')) {
    function app_users_schema_map(mysqli $conn): array
    {
        static $cache = null;
        static $cacheVersion = null;
        app_ensure_users_core_schema($conn);
        $version = (string)($GLOBALS['app_users_schema_map_reset'] ?? '0');
        if (is_array($cache) && $cacheVersion === $version) {
            return $cache;
        }

        $pick = static function (mysqli $conn, array $candidates): string {
            foreach ($candidates as $candidate) {
                if (app_table_has_column($conn, 'users', $candidate)) {
                    return $candidate;
                }
            }
            return '';
        };

        $cache = [
            'id' => $pick($conn, ['id', 'user_id']),
            'username' => $pick($conn, ['username', 'user_name', 'login', 'name']),
            'password' => $pick($conn, ['password', 'password_hash', 'pass']),
            'full_name' => $pick($conn, ['full_name', 'name', 'display_name']),
            'role' => $pick($conn, ['role', 'user_type', 'type', 'account_type']),
            'email' => $pick($conn, ['email', 'mail']),
            'phone' => $pick($conn, ['phone', 'mobile', 'phone_number', 'mobile_number']),
            'avatar' => $pick($conn, ['avatar', 'profile_pic']),
            'profile_pic' => $pick($conn, ['profile_pic', 'avatar']),
            'allow_caps' => $pick($conn, ['allow_caps']),
            'deny_caps' => $pick($conn, ['deny_caps']),
            'is_admin' => $pick($conn, ['is_admin']),
        ];
        $cacheVersion = $version;

        return $cache;
    }
}

if (!function_exists('app_users_schema_map_reset')) {
    function app_users_schema_map_reset(): void
    {
        $GLOBALS['app_users_schema_map_reset'] = microtime(true);
    }
}

if (!function_exists('app_users_resolved_column')) {
    function app_users_resolved_column(array $schemaOrMap, string $alias): string
    {
        if (isset($schemaOrMap['map']) && is_array($schemaOrMap['map'])) {
            $schemaOrMap = $schemaOrMap['map'];
        }
        return trim((string)($schemaOrMap[$alias] ?? ''));
    }
}

if (!function_exists('app_users_select_alias_sql')) {
    function app_users_select_alias_sql(array $map, array $aliases = []): string
    {
        $need = !empty($aliases) ? $aliases : ['id', 'username', 'full_name', 'role', 'email', 'phone', 'profile_pic', 'allow_caps', 'deny_caps'];
        $fields = [];
        foreach ($need as $alias) {
            $column = (string)($map[$alias] ?? '');
            if ($column !== '') {
                if ($alias === 'role' && ($map['role'] ?? '') === '' && ($map['is_admin'] ?? '') !== '') {
                    $fields[] = "CASE WHEN `{$map['is_admin']}` = 1 THEN 'admin' ELSE 'employee' END AS role";
                } else {
                    $fields[] = "`{$column}` AS {$alias}";
                }
                continue;
            }
            if ($alias === 'role' && ($map['is_admin'] ?? '') !== '') {
                $fields[] = "CASE WHEN `{$map['is_admin']}` = 1 THEN 'admin' ELSE 'employee' END AS role";
            } elseif ($alias === 'id') {
                $fields[] = '0 AS id';
            } elseif ($alias === 'role') {
                $fields[] = "'employee' AS role";
            } else {
                $fields[] = "'' AS {$alias}";
            }
        }
        return implode(', ', $fields);
    }
}

if (!function_exists('app_support_users_schema')) {
    function app_support_users_schema(mysqli $conn): array
    {
        static $cache = null;
        app_ensure_users_core_schema($conn);
        if (is_array($cache)) {
            return $cache;
        }
        $map = app_users_schema_map($conn);
        $cache = [
            'id' => ($map['id'] ?? '') !== '',
            'username' => ($map['username'] ?? '') !== '',
            'password' => ($map['password'] ?? '') !== '',
            'full_name' => ($map['full_name'] ?? '') !== '',
            'role' => (($map['role'] ?? '') !== '' || ($map['is_admin'] ?? '') !== ''),
            'email' => ($map['email'] ?? '') !== '',
            'phone' => ($map['phone'] ?? '') !== '',
            'map' => $map,
        ];
        return $cache;
    }
}

if (!function_exists('app_support_users_select_fields')) {
    function app_support_users_select_fields(array $schema): string
    {
        $map = (isset($schema['map']) && is_array($schema['map'])) ? $schema['map'] : [];
        if (!empty($map)) {
            return app_users_select_alias_sql($map, ['id', 'username', 'full_name', 'role', 'email', 'phone']);
        }
        $fields = [];
        $fields[] = !empty($schema['id']) ? 'id' : '0 AS id';
        $fields[] = !empty($schema['username']) ? 'username' : "'' AS username";
        $fields[] = !empty($schema['full_name']) ? 'full_name' : "'' AS full_name";
        $fields[] = !empty($schema['role']) ? 'role' : "'employee' AS role";
        $fields[] = !empty($schema['email']) ? 'email' : "'' AS email";
        $fields[] = !empty($schema['phone']) ? 'phone' : "'' AS phone";
        return implode(', ', $fields);
    }
}

if (!function_exists('app_support_users_non_admin_role')) {
    function app_support_users_non_admin_role(mysqli $conn, string $roleColumn = ''): string
    {
        $roleColumn = trim($roleColumn);
        if ($roleColumn === '') {
            return 'employee';
        }

        // Prefer values already used by the client schema to avoid enum/constraint failures.
        $samples = [];
        try {
            $res = $conn->query("SELECT DISTINCT LOWER(TRIM(`{$roleColumn}`)) AS v FROM users WHERE `{$roleColumn}` IS NOT NULL AND TRIM(`{$roleColumn}`) <> '' LIMIT 30");
            while ($res && ($row = $res->fetch_assoc())) {
                $v = strtolower(trim((string)($row['v'] ?? '')));
                if ($v !== '') {
                    $samples[$v] = true;
                }
            }
        } catch (Throwable $e) {
            // Best-effort only.
        }

        foreach (['employee', 'user', 'staff', 'member'] as $candidate) {
            if (isset($samples[$candidate])) {
                return $candidate;
            }
        }

        if (preg_match('/(^|_)(user_?type|type|account_?type)($|_)/i', $roleColumn)) {
            return 'user';
        }

        return 'employee';
    }
}

if (!function_exists('app_support_users_resolve_role')) {
    function app_support_users_resolve_role(mysqli $conn, string $roleColumn, string $requestedRole): string
    {
        $requestedRole = strtolower(trim($requestedRole));
        if ($requestedRole !== 'admin') {
            return app_support_users_non_admin_role($conn, $roleColumn);
        }

        $roleColumn = trim($roleColumn);
        if ($roleColumn === '') {
            return 'admin';
        }

        $roleType = '';
        try {
            $res = $conn->query("SHOW COLUMNS FROM users LIKE '" . $conn->real_escape_string($roleColumn) . "'");
            $row = $res ? ($res->fetch_assoc() ?: []) : [];
            $roleType = strtolower(trim((string)($row['Type'] ?? '')));
        } catch (Throwable $e) {
            $roleType = '';
        }

        if ($roleType !== '' && strpos($roleType, 'enum(') === 0) {
            preg_match_all("/'([^']*)'/", $roleType, $m);
            $allowed = array_map(static function ($v) {
                return strtolower(trim((string)$v));
            }, (array)($m[1] ?? []));
            if (in_array('admin', $allowed, true)) {
                return 'admin';
            }
            $fallback = app_support_users_non_admin_role($conn, $roleColumn);
            if (in_array(strtolower($fallback), $allowed, true)) {
                return $fallback;
            }
            if (!empty($allowed)) {
                return (string)$allowed[0];
            }
            return $fallback;
        }

        return 'admin';
    }
}

if (!function_exists('app_support_users_required_insert_defaults')) {
    function app_support_users_required_insert_defaults(mysqli $conn, array $alreadyUsedColumns = []): array
    {
        $used = [];
        foreach ($alreadyUsedColumns as $c) {
            $c = trim((string)$c);
            if ($c !== '') {
                $used[$c] = true;
            }
        }

        $defaults = [];
        try {
            $res = $conn->query("SHOW COLUMNS FROM users");
            while ($res && ($row = $res->fetch_assoc())) {
                $column = trim((string)($row['Field'] ?? ''));
                if ($column === '' || isset($used[$column])) {
                    continue;
                }
                $nullable = strtoupper(trim((string)($row['Null'] ?? 'YES'))) === 'YES';
                $default = $row['Default'] ?? null;
                $extra = strtolower(trim((string)($row['Extra'] ?? '')));
                if ($nullable || $default !== null || strpos($extra, 'auto_increment') !== false) {
                    continue;
                }

                $typeRaw = strtolower(trim((string)($row['Type'] ?? '')));
                $bindType = 's';
                $value = '';

                if (preg_match('/^(tinyint|smallint|mediumint|int|bigint)\b/', $typeRaw)) {
                    $bindType = 'i';
                    $value = 0;
                } elseif (preg_match('/^(decimal|float|double|real)\b/', $typeRaw)) {
                    $bindType = 'd';
                    $value = 0.0;
                } elseif (strpos($typeRaw, 'enum(') === 0) {
                    preg_match_all("/'([^']*)'/", $typeRaw, $m);
                    $value = (string)(($m[1][0] ?? '') !== '' ? $m[1][0] : '');
                } elseif (strpos($typeRaw, 'date') === 0) {
                    $value = date('Y-m-d');
                } elseif (strpos($typeRaw, 'datetime') === 0 || strpos($typeRaw, 'timestamp') === 0) {
                    $value = date('Y-m-d H:i:s');
                } else {
                    $value = '';
                }

                $defaults[] = [
                    'column' => $column,
                    'bind' => $bindType,
                    'value' => $value,
                ];
            }
        } catch (Throwable $e) {
            return [];
        }

        return $defaults;
    }
}

if (!function_exists('app_support_api_find_user_for_remote_action')) {
    function app_support_api_find_user_for_remote_action(mysqli $conn, array $userNode): array
    {
        $schema = app_support_users_schema($conn);
        if (empty($schema['id']) || empty($schema['username'])) {
            return [];
        }
        $map = (isset($schema['map']) && is_array($schema['map'])) ? $schema['map'] : [];
        $idColumn = app_users_resolved_column($map, 'id');
        $usernameColumn = app_users_resolved_column($map, 'username');
        $emailColumn = app_users_resolved_column($map, 'email');
        $phoneColumn = app_users_resolved_column($map, 'phone');
        if ($idColumn === '' || $usernameColumn === '') {
            return [];
        }
        $selectFields = app_support_users_select_fields($schema);

        $remoteUserId = (int)($userNode['remote_user_id'] ?? $userNode['id'] ?? 0);
        $username = trim((string)($userNode['username'] ?? ''));
        $email = trim((string)($userNode['email'] ?? ''));
        $phone = trim((string)($userNode['phone'] ?? ''));

        if ($remoteUserId > 0) {
            $stmt = $conn->prepare("SELECT {$selectFields} FROM users WHERE `{$idColumn}` = ? LIMIT 1");
            $stmt->bind_param('i', $remoteUserId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc() ?: [];
            $stmt->close();
            if (!empty($row)) {
                return $row;
            }
        }
        if ($username !== '') {
            $stmt = $conn->prepare("SELECT {$selectFields} FROM users WHERE LOWER(`{$usernameColumn}`) = LOWER(?) LIMIT 1");
            $stmt->bind_param('s', $username);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc() ?: [];
            $stmt->close();
            if (!empty($row)) {
                return $row;
            }
        }
        if ($email !== '' && $emailColumn !== '') {
            $stmt = $conn->prepare("SELECT {$selectFields} FROM users WHERE LOWER(`{$emailColumn}`) = LOWER(?) LIMIT 1");
            $stmt->bind_param('s', $email);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc() ?: [];
            $stmt->close();
            if (!empty($row)) {
                return $row;
            }
        }
        if ($phone !== '' && $phoneColumn !== '') {
            $stmt = $conn->prepare("SELECT {$selectFields} FROM users WHERE `{$phoneColumn}` = ? LIMIT 1");
            $stmt->bind_param('s', $phone);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc() ?: [];
            $stmt->close();
            if (!empty($row)) {
                return $row;
            }
        }
        return [];
    }
}

if (!function_exists('app_support_api_user_create')) {
    function app_support_api_user_create(mysqli $conn, array $payload): array
    {
        $check = app_support_api_validate_client_target($conn, $payload);
        if (empty($check['ok'])) {
            return ['http_code' => (int)($check['http_code'] ?? 403), 'body' => ['ok' => false, 'error' => (string)($check['error'] ?? 'access_denied')]];
        }

        $schema = app_support_users_schema($conn);
        if (empty($schema['username']) || empty($schema['password'])) {
            return ['http_code' => 500, 'body' => ['ok' => false, 'error' => 'users_schema_incompatible']];
        }
        $map = (isset($schema['map']) && is_array($schema['map'])) ? $schema['map'] : [];
        $usernameColumn = app_users_resolved_column($map, 'username');
        $passwordColumn = app_users_resolved_column($map, 'password');
        $fullNameColumn = app_users_resolved_column($map, 'full_name');
        $roleColumn = app_users_resolved_column($map, 'role');
        $emailColumn = app_users_resolved_column($map, 'email');
        $phoneColumn = app_users_resolved_column($map, 'phone');
        $isAdminColumn = app_users_resolved_column($map, 'is_admin');
        if ($usernameColumn === '' || $passwordColumn === '') {
            return ['http_code' => 500, 'body' => ['ok' => false, 'error' => 'users_schema_incompatible']];
        }

        $userNode = (isset($payload['user']) && is_array($payload['user'])) ? $payload['user'] : [];
        $username = strtolower(trim((string)($userNode['username'] ?? '')));
        $password = (string)($userNode['password'] ?? '');
        if ($password === '') {
            $passwordB64 = trim((string)($userNode['password_b64'] ?? ''));
            if ($passwordB64 !== '') {
                $decodedPassword = base64_decode($passwordB64, true);
                if (is_string($decodedPassword)) {
                    $password = $decodedPassword;
                }
            }
        }
        $fullName = mb_substr(trim((string)($userNode['full_name'] ?? '')), 0, 190);
        $requestedRole = strtolower(trim((string)($userNode['role'] ?? 'user')));
        $email = mb_substr(trim((string)($userNode['email'] ?? '')), 0, 190);
        $phone = mb_substr(trim((string)($userNode['phone'] ?? '')), 0, 80);

        if ($username === '' || !preg_match('/^[a-z0-9._-]{3,120}$/i', $username)) {
            return ['http_code' => 422, 'body' => ['ok' => false, 'error' => 'username_invalid']];
        }
        if (strlen($password) < 4) {
            return ['http_code' => 422, 'body' => ['ok' => false, 'error' => 'password_too_short']];
        }
        $role = app_support_users_resolve_role($conn, $roleColumn, $requestedRole);
        if ($fullName === '') {
            $fullName = $username;
        }

        $exists = app_support_api_find_user_for_remote_action($conn, ['username' => $username]);
        if (!empty($exists)) {
            return ['http_code' => 409, 'body' => ['ok' => false, 'error' => 'username_exists']];
        }

        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        $insertColumns = [];
        $insertTypes = '';
        $insertValues = [];
        $usedColumns = [];
        $pushInsert = static function (string $column, string $type, $value) use (&$insertColumns, &$insertTypes, &$insertValues, &$usedColumns): void {
            if ($column === '' || isset($usedColumns[$column])) {
                return;
            }
            $usedColumns[$column] = true;
            $insertColumns[] = "`{$column}`";
            $insertTypes .= $type;
            $insertValues[] = $value;
        };

        $pushInsert($usernameColumn, 's', $username);
        $pushInsert($passwordColumn, 's', $passwordHash);
        $pushInsert($fullNameColumn, 's', $fullName);
        if ($roleColumn !== '') {
            $pushInsert($roleColumn, 's', $role);
        } elseif ($isAdminColumn !== '') {
            $pushInsert($isAdminColumn, 'i', $role === 'admin' ? 1 : 0);
        }
        $pushInsert($emailColumn, 's', $email);
        $pushInsert($phoneColumn, 's', $phone);
        $requiredDefaults = app_support_users_required_insert_defaults($conn, array_keys($usedColumns));
        foreach ($requiredDefaults as $defaultNode) {
            $columnName = trim((string)($defaultNode['column'] ?? ''));
            $bind = (string)($defaultNode['bind'] ?? 's');
            $value = $defaultNode['value'] ?? '';
            if ($columnName === '') {
                continue;
            }
            $pushInsert($columnName, in_array($bind, ['i', 'd', 's'], true) ? $bind : 's', $value);
        }

        if (empty($insertColumns)) {
            return ['http_code' => 500, 'body' => ['ok' => false, 'error' => 'users_schema_incompatible']];
        }

        $placeholders = implode(', ', array_fill(0, count($insertColumns), '?'));
        $columnsSql = implode(', ', $insertColumns);
        $stmt = $conn->prepare("INSERT INTO users ({$columnsSql}) VALUES ({$placeholders})");
        if (!$stmt) {
            return ['http_code' => 500, 'body' => ['ok' => false, 'error' => 'prepare_failed']];
        }
        app_stmt_bind_dynamic_params($stmt, $insertTypes, $insertValues);
        $ok = $stmt->execute();
        $newId = (int)$stmt->insert_id;
        $stmtErr = trim((string)$stmt->error);
        $stmt->close();
        if (!$ok || $newId <= 0) {
            return ['http_code' => 500, 'body' => ['ok' => false, 'error' => 'create_failed', 'detail' => $stmtErr !== '' ? $stmtErr : '']];
        }

        return [
            'http_code' => 200,
            'body' => [
                'ok' => true,
                'user' => [
                    'id' => $newId,
                    'username' => $username,
                    'full_name' => $fullName,
                    'role' => $role,
                    'email' => $email,
                    'phone' => $phone,
                ],
            ],
        ];
    }
}

if (!function_exists('app_support_api_user_update')) {
    function app_support_api_user_update(mysqli $conn, array $payload): array
    {
        $check = app_support_api_validate_client_target($conn, $payload);
        if (empty($check['ok'])) {
            return ['http_code' => (int)($check['http_code'] ?? 403), 'body' => ['ok' => false, 'error' => (string)($check['error'] ?? 'access_denied')]];
        }

        $schema = app_support_users_schema($conn);
        if (empty($schema['username']) || empty($schema['id'])) {
            return ['http_code' => 500, 'body' => ['ok' => false, 'error' => 'users_schema_incompatible']];
        }
        $map = (isset($schema['map']) && is_array($schema['map'])) ? $schema['map'] : [];
        $idColumn = app_users_resolved_column($map, 'id');
        $usernameColumn = app_users_resolved_column($map, 'username');
        $fullNameColumn = app_users_resolved_column($map, 'full_name');
        $roleColumn = app_users_resolved_column($map, 'role');
        $emailColumn = app_users_resolved_column($map, 'email');
        $phoneColumn = app_users_resolved_column($map, 'phone');
        $isAdminColumn = app_users_resolved_column($map, 'is_admin');
        if ($idColumn === '' || $usernameColumn === '') {
            return ['http_code' => 500, 'body' => ['ok' => false, 'error' => 'users_schema_incompatible']];
        }

        $userNode = (isset($payload['user']) && is_array($payload['user'])) ? $payload['user'] : [];
        $foundUser = app_support_api_find_user_for_remote_action($conn, $userNode);
        if (empty($foundUser)) {
            return ['http_code' => 404, 'body' => ['ok' => false, 'error' => 'user_not_found']];
        }

        $targetId = (int)($foundUser['id'] ?? 0);
        $username = trim((string)($userNode['username'] ?? $foundUser['username'] ?? ''));
        $fullName = trim((string)($userNode['full_name'] ?? $foundUser['full_name'] ?? ''));
        $requestedRole = strtolower(trim((string)($userNode['role'] ?? $foundUser['role'] ?? 'user')));
        $email = trim((string)($userNode['email'] ?? $foundUser['email'] ?? ''));
        $phone = trim((string)($userNode['phone'] ?? $foundUser['phone'] ?? ''));

        $username = mb_substr($username, 0, 120);
        $fullName = mb_substr($fullName, 0, 190);
        $email = mb_substr($email, 0, 190);
        $phone = mb_substr($phone, 0, 80);
        if ($username === '' || !preg_match('/^[a-z0-9._-]{3,120}$/i', $username)) {
            return ['http_code' => 422, 'body' => ['ok' => false, 'error' => 'username_invalid']];
        }
        $role = app_support_users_resolve_role($conn, $roleColumn, $requestedRole);
        if ($fullName === '') {
            $fullName = $username;
        }

        $stmtDup = $conn->prepare("SELECT `{$idColumn}` AS id FROM users WHERE LOWER(`{$usernameColumn}`) = LOWER(?) AND `{$idColumn}` <> ? LIMIT 1");
        $stmtDup->bind_param('si', $username, $targetId);
        $stmtDup->execute();
        $dupRow = $stmtDup->get_result()->fetch_assoc() ?: [];
        $stmtDup->close();
        if (!empty($dupRow)) {
            return ['http_code' => 409, 'body' => ['ok' => false, 'error' => 'username_exists']];
        }

        $setParts = [];
        $setTypes = '';
        $setValues = [];
        $usedColumns = [];
        $pushSet = static function (string $column, string $sql, string $type, $value) use (&$setParts, &$setTypes, &$setValues, &$usedColumns): void {
            if ($column === '' || isset($usedColumns[$column])) {
                return;
            }
            $usedColumns[$column] = true;
            $setParts[] = $sql;
            $setTypes .= $type;
            $setValues[] = $value;
        };

        $pushSet($usernameColumn, "`{$usernameColumn}` = ?", 's', $username);
        $pushSet($fullNameColumn, "`{$fullNameColumn}` = ?", 's', $fullName);
        if ($roleColumn !== '') {
            $pushSet($roleColumn, "`{$roleColumn}` = ?", 's', $role);
        } elseif ($isAdminColumn !== '') {
            $pushSet($isAdminColumn, "`{$isAdminColumn}` = ?", 'i', $role === 'admin' ? 1 : 0);
        }
        $pushSet($emailColumn, "`{$emailColumn}` = ?", 's', $email);
        $pushSet($phoneColumn, "`{$phoneColumn}` = ?", 's', $phone);

        if (empty($setParts)) {
            return ['http_code' => 500, 'body' => ['ok' => false, 'error' => 'users_schema_incompatible']];
        }

        $setTypes .= 'i';
        $setValues[] = $targetId;
        $stmt = $conn->prepare("UPDATE users SET " . implode(', ', $setParts) . " WHERE `{$idColumn}` = ? LIMIT 1");
        if (!$stmt) {
            return ['http_code' => 500, 'body' => ['ok' => false, 'error' => 'prepare_failed']];
        }
        app_stmt_bind_dynamic_params($stmt, $setTypes, $setValues);
        $ok = $stmt->execute();
        $stmtErr = trim((string)$stmt->error);
        $stmt->close();
        if (!$ok) {
            return ['http_code' => 500, 'body' => ['ok' => false, 'error' => 'update_failed', 'detail' => $stmtErr !== '' ? $stmtErr : '']];
        }

        return [
            'http_code' => 200,
            'body' => [
                'ok' => true,
                'user' => [
                    'id' => $targetId,
                    'username' => $username,
                    'full_name' => $fullName,
                    'role' => $role,
                    'email' => $email,
                    'phone' => $phone,
                ],
            ],
        ];
    }
}

if (!function_exists('app_support_api_user_set_password')) {
    function app_support_api_user_set_password(mysqli $conn, array $payload): array
    {
        $check = app_support_api_validate_client_target($conn, $payload);
        if (empty($check['ok'])) {
            return ['http_code' => (int)($check['http_code'] ?? 403), 'body' => ['ok' => false, 'error' => (string)($check['error'] ?? 'access_denied')]];
        }

        $schema = app_support_users_schema($conn);
        if (empty($schema['password']) || empty($schema['id'])) {
            return ['http_code' => 500, 'body' => ['ok' => false, 'error' => 'users_schema_incompatible']];
        }
        $map = (isset($schema['map']) && is_array($schema['map'])) ? $schema['map'] : [];
        $idColumn = app_users_resolved_column($map, 'id');
        $passwordColumn = app_users_resolved_column($map, 'password');
        if ($idColumn === '' || $passwordColumn === '') {
            return ['http_code' => 500, 'body' => ['ok' => false, 'error' => 'users_schema_incompatible']];
        }

        $userNode = (isset($payload['user']) && is_array($payload['user'])) ? $payload['user'] : [];
        $foundUser = app_support_api_find_user_for_remote_action($conn, $userNode);
        if (empty($foundUser)) {
            return ['http_code' => 404, 'body' => ['ok' => false, 'error' => 'user_not_found']];
        }
        $newPassword = (string)($userNode['password'] ?? '');
        if ($newPassword === '') {
            $passwordB64 = trim((string)($userNode['password_b64'] ?? ''));
            if ($passwordB64 !== '') {
                $decodedPassword = base64_decode($passwordB64, true);
                if (is_string($decodedPassword)) {
                    $newPassword = $decodedPassword;
                }
            }
        }
        if (strlen($newPassword) < 4) {
            return ['http_code' => 422, 'body' => ['ok' => false, 'error' => 'password_too_short']];
        }

        $targetId = (int)($foundUser['id'] ?? 0);
        $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
        $stmt = $conn->prepare("UPDATE users SET `{$passwordColumn}` = ? WHERE `{$idColumn}` = ? LIMIT 1");
        $stmt->bind_param('si', $passwordHash, $targetId);
        $ok = $stmt->execute();
        $stmt->close();
        if (!$ok) {
            return ['http_code' => 500, 'body' => ['ok' => false, 'error' => 'password_update_failed']];
        }

        return ['http_code' => 200, 'body' => ['ok' => true, 'user' => ['id' => $targetId, 'username' => (string)($foundUser['username'] ?? '')]]];
    }
}

if (!function_exists('app_support_api_user_delete')) {
    function app_support_api_user_delete(mysqli $conn, array $payload): array
    {
        $check = app_support_api_validate_client_target($conn, $payload);
        if (empty($check['ok'])) {
            return ['http_code' => (int)($check['http_code'] ?? 403), 'body' => ['ok' => false, 'error' => (string)($check['error'] ?? 'access_denied')]];
        }

        $userNode = (isset($payload['user']) && is_array($payload['user'])) ? $payload['user'] : [];
        $foundUser = app_support_api_find_user_for_remote_action($conn, $userNode);
        if (empty($foundUser)) {
            return ['http_code' => 404, 'body' => ['ok' => false, 'error' => 'user_not_found']];
        }

        $schema = app_support_users_schema($conn);
        $map = (isset($schema['map']) && is_array($schema['map'])) ? $schema['map'] : [];
        $idColumn = app_users_resolved_column($map, 'id');
        $roleColumn = app_users_resolved_column($map, 'role');
        $isAdminColumn = app_users_resolved_column($map, 'is_admin');
        if ($idColumn === '') {
            return ['http_code' => 500, 'body' => ['ok' => false, 'error' => 'users_schema_incompatible']];
        }

        $targetId = (int)($foundUser['id'] ?? 0);
        $targetRole = strtolower(trim((string)($foundUser['role'] ?? '')));
        if ($targetRole === 'admin') {
            if ($roleColumn !== '') {
                $adminsRow = $conn->query("SELECT COUNT(*) AS c FROM users WHERE LOWER(`{$roleColumn}`) = 'admin'")->fetch_assoc();
            } elseif ($isAdminColumn !== '') {
                $adminsRow = $conn->query("SELECT COUNT(*) AS c FROM users WHERE `{$isAdminColumn}` = 1")->fetch_assoc();
            } else {
                $adminsRow = ['c' => 0];
            }
            $adminsCount = (int)($adminsRow['c'] ?? 0);
            if ($adminsCount <= 1) {
                return ['http_code' => 409, 'body' => ['ok' => false, 'error' => 'last_admin_blocked']];
            }
        }

        $stmt = $conn->prepare("DELETE FROM users WHERE `{$idColumn}` = ? LIMIT 1");
        $stmt->bind_param('i', $targetId);
        $ok = $stmt->execute();
        $affected = (int)$stmt->affected_rows;
        $stmt->close();
        if (!$ok || $affected <= 0) {
            return ['http_code' => 500, 'body' => ['ok' => false, 'error' => 'delete_failed']];
        }

        return ['http_code' => 200, 'body' => ['ok' => true, 'deleted' => true, 'user_id' => $targetId]];
    }
}

if (!function_exists('app_support_api_ticket_create')) {
    function app_support_api_ticket_create(mysqli $conn, array $payload): array
    {
        app_initialize_support_center($conn);

        $licenseKey = strtoupper(trim((string)($payload['license_key'] ?? '')));
        $domain = app_license_normalize_domain((string)($payload['domain'] ?? ''));
        $appUrl = trim((string)($payload['app_url'] ?? ''));
        $installationId = trim((string)($payload['installation_id'] ?? ''));
        $ticketNode = (isset($payload['ticket']) && is_array($payload['ticket'])) ? $payload['ticket'] : [];
        $clientTicketId = trim((string)($ticketNode['client_ticket_id'] ?? ''));
        $subject = mb_substr(trim((string)($ticketNode['subject'] ?? 'Support Request')), 0, 220);
        $message = trim((string)($ticketNode['message'] ?? ''));
        $imageUrl = trim((string)($ticketNode['image_url'] ?? $ticketNode['image_path'] ?? ''));
        $imageName = app_support_attachment_safe_name((string)($ticketNode['image_name'] ?? ''), $imageUrl);
        $priority = strtolower(trim((string)($ticketNode['priority'] ?? 'normal')));
        $status = strtolower(trim((string)($ticketNode['status'] ?? 'open')));
        $requesterName = mb_substr(trim((string)($ticketNode['requester_name'] ?? '')), 0, 190);
        $requesterEmail = mb_substr(trim((string)($ticketNode['requester_email'] ?? '')), 0, 190);
        $requesterPhone = mb_substr(trim((string)($ticketNode['requester_phone'] ?? '')), 0, 80);

        if ($licenseKey === '' || $clientTicketId === '' || $domain === '' || ($message === '' && $imageUrl === '')) {
            return ['http_code' => 422, 'body' => ['ok' => false, 'error' => 'missing_required_fields']];
        }
        if (!in_array($priority, ['low', 'normal', 'high', 'urgent'], true)) {
            $priority = 'normal';
        }
        if (!in_array($status, ['open', 'pending', 'answered', 'closed'], true)) {
            $status = 'open';
        }

        if (app_license_edition() === 'owner') {
            $license = app_license_registry_by_key($conn, $licenseKey);
            if (!$license) {
                return ['http_code' => 404, 'body' => ['ok' => false, 'error' => 'license_not_found']];
            }
        }

        $ticketId = 0;
        $stmtFind = $conn->prepare("
            SELECT id FROM app_support_tickets
            WHERE remote_license_key = ? AND remote_client_ticket_id = ?
            ORDER BY id DESC
            LIMIT 1
        ");
        $stmtFind->bind_param('ss', $licenseKey, $clientTicketId);
        $stmtFind->execute();
        $found = $stmtFind->get_result()->fetch_assoc() ?: [];
        $stmtFind->close();
        if (!empty($found)) {
            $ticketId = (int)($found['id'] ?? 0);
        }

        $now = date('Y-m-d H:i:s');
        if ($ticketId <= 0) {
            $stmtIn = $conn->prepare("
                INSERT INTO app_support_tickets (
                    installation_id, requester_user_id, requester_name, requester_email, requester_phone,
                    subject, priority, status, remote_ticket_id, remote_client_ticket_id, remote_license_key,
                    remote_client_domain, remote_client_app_url, remote_sync_error, remote_last_sync_at, last_message_at
                ) VALUES (?, NULL, ?, ?, ?, ?, ?, ?, NULL, ?, ?, ?, ?, '', ?, ?)
            ");
            $stmtIn->bind_param(
                'sssssssssssss',
                $installationId,
                $requesterName,
                $requesterEmail,
                $requesterPhone,
                $subject,
                $priority,
                $status,
                $clientTicketId,
                $licenseKey,
                $domain,
                $appUrl,
                $now,
                $now
            );
            $stmtIn->execute();
            $ticketId = (int)$stmtIn->insert_id;
            $stmtIn->close();
        } else {
            $stmtUp = $conn->prepare("
                UPDATE app_support_tickets
                SET status = ?, last_message_at = ?, remote_sync_error = '', remote_last_sync_at = ?,
                    remote_client_domain = ?, remote_client_app_url = ?
                WHERE id = ?
            ");
            $stmtUp->bind_param('sssssi', $status, $now, $now, $domain, $appUrl, $ticketId);
            $stmtUp->execute();
            $stmtUp->close();
        }

        $stmtMsg = $conn->prepare("
            INSERT INTO app_support_ticket_messages (
                ticket_id, sender_user_id, sender_role, message, image_path, image_name, is_read_by_client, is_read_by_admin
            ) VALUES (?, NULL, 'client', ?, ?, ?, 1, 0)
        ");
        $stmtMsg->bind_param('isss', $ticketId, $message, $imageUrl, $imageName);
        $stmtMsg->execute();
        $stmtMsg->close();

        $adminIds = app_support_admin_user_ids($conn);
        app_support_notify_users($conn, $adminIds, $ticketId, 'ticket_new', 'تذكرة دعم جديدة', mb_substr($subject, 0, 255));

        return ['http_code' => 200, 'body' => ['ok' => true, 'ticket_id' => $ticketId]];
    }
}

if (!function_exists('app_support_api_ticket_reply')) {
    function app_support_api_ticket_reply(mysqli $conn, array $payload): array
    {
        app_initialize_support_center($conn);

        $licenseKey = strtoupper(trim((string)($payload['license_key'] ?? '')));
        $domain = app_license_normalize_domain((string)($payload['domain'] ?? ''));
        $appUrl = trim((string)($payload['app_url'] ?? ''));
        $ticketNode = (isset($payload['ticket']) && is_array($payload['ticket'])) ? $payload['ticket'] : [];
        $remoteTicketId = (int)($ticketNode['remote_ticket_id'] ?? 0);
        $clientTicketId = trim((string)($ticketNode['client_ticket_id'] ?? ''));
        $message = trim((string)($ticketNode['message'] ?? ''));
        $imageUrl = trim((string)($ticketNode['image_url'] ?? $ticketNode['image_path'] ?? ''));
        $imageName = app_support_attachment_safe_name((string)($ticketNode['image_name'] ?? ''), $imageUrl);
        $status = strtolower(trim((string)($ticketNode['status'] ?? 'pending')));

        if ($licenseKey === '' || $domain === '' || ($message === '' && $imageUrl === '')) {
            return ['http_code' => 422, 'body' => ['ok' => false, 'error' => 'missing_required_fields']];
        }
        if (!in_array($status, ['open', 'pending', 'answered', 'closed'], true)) {
            $status = 'pending';
        }

        if (app_license_edition() === 'owner') {
            $license = app_license_registry_by_key($conn, $licenseKey);
            if (!$license) {
                return ['http_code' => 404, 'body' => ['ok' => false, 'error' => 'license_not_found']];
            }
        }

        $ticketId = 0;
        if ($remoteTicketId > 0) {
            $stmtFindById = $conn->prepare("SELECT id FROM app_support_tickets WHERE id = ? LIMIT 1");
            $stmtFindById->bind_param('i', $remoteTicketId);
            $stmtFindById->execute();
            $foundById = $stmtFindById->get_result()->fetch_assoc() ?: [];
            $stmtFindById->close();
            $ticketId = (int)($foundById['id'] ?? 0);
        }
        if ($ticketId <= 0 && $clientTicketId !== '') {
            $stmtFind = $conn->prepare("
                SELECT id FROM app_support_tickets
                WHERE remote_license_key = ? AND remote_client_ticket_id = ?
                ORDER BY id DESC
                LIMIT 1
            ");
            $stmtFind->bind_param('ss', $licenseKey, $clientTicketId);
            $stmtFind->execute();
            $found = $stmtFind->get_result()->fetch_assoc() ?: [];
            $stmtFind->close();
            $ticketId = (int)($found['id'] ?? 0);
        }
        if ($ticketId <= 0) {
            return ['http_code' => 404, 'body' => ['ok' => false, 'error' => 'ticket_not_found']];
        }

        $now = date('Y-m-d H:i:s');
        $stmtMsg = $conn->prepare("
            INSERT INTO app_support_ticket_messages (
                ticket_id, sender_user_id, sender_role, message, image_path, image_name, is_read_by_client, is_read_by_admin
            ) VALUES (?, NULL, 'client', ?, ?, ?, 1, 0)
        ");
        $stmtMsg->bind_param('isss', $ticketId, $message, $imageUrl, $imageName);
        $stmtMsg->execute();
        $stmtMsg->close();

        $stmtUp = $conn->prepare("
            UPDATE app_support_tickets
            SET status = ?, last_message_at = ?, remote_sync_error = '', remote_last_sync_at = ?,
                remote_client_domain = ?, remote_client_app_url = ?, remote_license_key = COALESCE(remote_license_key, ?)
            WHERE id = ?
        ");
        $stmtUp->bind_param('ssssssi', $status, $now, $now, $domain, $appUrl, $licenseKey, $ticketId);
        $stmtUp->execute();
        $stmtUp->close();

        $replyPreview = $message !== '' ? $message : app_tr('تم إرفاق صورة.', 'Image attached.');
        $adminIds = app_support_admin_user_ids($conn);
        app_support_notify_users($conn, $adminIds, $ticketId, 'ticket_reply', 'رد جديد من العميل', mb_substr($replyPreview, 0, 255));

        return ['http_code' => 200, 'body' => ['ok' => true, 'ticket_id' => $ticketId]];
    }
}

if (!function_exists('app_support_api_ticket_pull')) {
    function app_support_api_ticket_pull(mysqli $conn, array $payload): array
    {
        app_initialize_support_center($conn);

        $licenseKey = strtoupper(trim((string)($payload['license_key'] ?? '')));
        $domain = app_license_normalize_domain((string)($payload['domain'] ?? ''));
        $ticketNode = (isset($payload['ticket']) && is_array($payload['ticket'])) ? $payload['ticket'] : [];
        $remoteTicketId = (int)($ticketNode['remote_ticket_id'] ?? 0);
        $clientTicketId = trim((string)($ticketNode['client_ticket_id'] ?? ''));
        $sinceRemoteMessageId = max(0, (int)($ticketNode['since_remote_message_id'] ?? 0));

        if ($licenseKey === '' || $domain === '' || ($remoteTicketId <= 0 && $clientTicketId === '')) {
            return ['http_code' => 422, 'body' => ['ok' => false, 'error' => 'missing_required_fields']];
        }

        if (app_license_edition() === 'owner') {
            $license = app_license_registry_by_key($conn, $licenseKey);
            if (!$license) {
                return ['http_code' => 404, 'body' => ['ok' => false, 'error' => 'license_not_found']];
            }
        }

        $ticket = app_support_ticket_find_by_remote_refs($conn, $licenseKey, $remoteTicketId, $clientTicketId);
        if (!$ticket) {
            return ['http_code' => 404, 'body' => ['ok' => false, 'error' => 'ticket_not_found']];
        }
        $ticketId = (int)($ticket['id'] ?? 0);
        if ($ticketId <= 0) {
            return ['http_code' => 404, 'body' => ['ok' => false, 'error' => 'ticket_not_found']];
        }

        $stmt = $conn->prepare("
            SELECT id, message, image_path, image_name, created_at, sender_user_id
            FROM app_support_ticket_messages
            WHERE ticket_id = ? AND sender_role = 'support' AND id > ?
            ORDER BY id ASC
            LIMIT 200
        ");
        $stmt->bind_param('ii', $ticketId, $sinceRemoteMessageId);
        $stmt->execute();
        $res = $stmt->get_result();
        $messages = [];
        while ($res && ($row = $res->fetch_assoc())) {
            $msgImagePath = trim((string)($row['image_path'] ?? ''));
            $messages[] = [
                'remote_message_id' => (int)($row['id'] ?? 0),
                'message' => (string)($row['message'] ?? ''),
                'image_url' => app_support_attachment_public_url($msgImagePath, app_base_url()),
                'image_name' => app_support_attachment_safe_name((string)($row['image_name'] ?? ''), $msgImagePath),
                'created_at' => (string)($row['created_at'] ?? ''),
                'sender_user_id' => (int)($row['sender_user_id'] ?? 0),
            ];
        }
        $stmt->close();

        return [
            'http_code' => 200,
            'body' => [
                'ok' => true,
                'ticket_id' => $ticketId,
                'ticket_status' => (string)($ticket['status'] ?? 'open'),
                'owner_name' => app_setting_get($conn, 'app_name', 'Support'),
                'messages' => $messages,
            ],
        ];
    }
}

if (!function_exists('app_support_api_ticket_delete')) {
    function app_support_api_ticket_delete(mysqli $conn, array $payload): array
    {
        app_initialize_support_center($conn);

        $licenseKey = strtoupper(trim((string)($payload['license_key'] ?? '')));
        $domain = app_license_normalize_domain((string)($payload['domain'] ?? ''));
        $ticketNode = (isset($payload['ticket']) && is_array($payload['ticket'])) ? $payload['ticket'] : [];
        $remoteTicketId = (int)($ticketNode['remote_ticket_id'] ?? 0);
        $clientTicketId = trim((string)($ticketNode['client_ticket_id'] ?? ''));

        if ($licenseKey === '' || ($remoteTicketId <= 0 && $clientTicketId === '')) {
            return ['http_code' => 422, 'body' => ['ok' => false, 'error' => 'missing_required_fields']];
        }

        if (app_license_edition() === 'owner') {
            $license = app_license_registry_by_key($conn, $licenseKey);
            if (!$license) {
                return ['http_code' => 404, 'body' => ['ok' => false, 'error' => 'license_not_found']];
            }
        } else {
            $localLicense = app_license_row($conn);
            $localLicenseKey = strtoupper(trim((string)($localLicense['license_key'] ?? '')));
            if ($localLicenseKey !== '' && $licenseKey !== $localLicenseKey) {
                return ['http_code' => 403, 'body' => ['ok' => false, 'error' => 'license_mismatch']];
            }
            if ($domain !== '') {
                $localDomain = app_license_normalize_domain((string)parse_url(app_base_url(), PHP_URL_HOST));
                if ($localDomain !== '' && $localDomain !== $domain) {
                    return ['http_code' => 403, 'body' => ['ok' => false, 'error' => 'domain_mismatch']];
                }
            }
        }

        $ticket = app_support_ticket_find_by_remote_refs($conn, $licenseKey, $remoteTicketId, $clientTicketId);
        if (!$ticket) {
            return ['http_code' => 200, 'body' => ['ok' => true, 'deleted' => false]];
        }

        $ticketId = (int)($ticket['id'] ?? 0);
        if ($ticketId <= 0) {
            return ['http_code' => 200, 'body' => ['ok' => true, 'deleted' => false]];
        }

        $deleted = app_support_ticket_delete_local($conn, $ticketId);
        if (empty($deleted['ok'])) {
            return ['http_code' => 500, 'body' => ['ok' => false, 'error' => (string)($deleted['error'] ?? 'ticket_delete_failed')]];
        }

        return ['http_code' => 200, 'body' => ['ok' => true, 'deleted' => true, 'ticket_id' => $ticketId]];
    }
}
