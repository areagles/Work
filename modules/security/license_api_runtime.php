<?php

if (!function_exists('app_license_api_resolve_shared_secret')) {
    function app_license_client_adopt_remote_token_if_valid(mysqli $conn, array $payload, string $bearerToken): bool
    {
        if (app_license_edition() !== 'client') {
            return false;
        }

        $bearer = trim($bearerToken);
        $len = strlen($bearer);
        if ($len < 16 || $len > 200) {
            return false;
        }

        $event = strtolower(trim((string)($payload['event'] ?? '')));
        if ($event === '' || (strpos($event, 'support_') !== 0 && $event !== 'owner_credentials_push')) {
            return false;
        }

        $row = app_license_row($conn);
        $licenseKey = strtoupper(trim((string)($payload['license_key'] ?? '')));
        $installationId = trim((string)($payload['installation_id'] ?? ''));
        $fingerprint = trim((string)($payload['fingerprint'] ?? ''));
        $localLicenseKey = strtoupper(trim((string)($row['license_key'] ?? '')));
        $localInstallationId = trim((string)($row['installation_id'] ?? ''));
        $localFingerprint = trim((string)($row['fingerprint'] ?? ''));
        if ($localLicenseKey !== '' && $licenseKey !== '' && $localLicenseKey !== $licenseKey) {
            return false;
        }

        $validationLicenseKey = $localLicenseKey !== '' ? $localLicenseKey : $licenseKey;
        $validationInstallationId = $localInstallationId !== '' ? $localInstallationId : $installationId;
        $validationFingerprint = $localFingerprint !== '' ? $localFingerprint : $fingerprint;
        if ($validationLicenseKey === '' || $validationInstallationId === '' || $validationFingerprint === '') {
            return false;
        }

        $remoteUrl = trim((string)($row['remote_url'] ?? ''));
        if ($remoteUrl === '' || app_license_url_looks_placeholder($remoteUrl) || app_license_url_is_local_or_private($remoteUrl)) {
            return false;
        }
        $localHost = app_license_normalize_domain((string)parse_url(app_base_url(), PHP_URL_HOST));
        $remoteHost = app_license_normalize_domain((string)parse_url($remoteUrl, PHP_URL_HOST));
        if ($remoteHost === '' || ($localHost !== '' && $localHost === $remoteHost)) {
            return false;
        }

        $syncPayload = [
            'event' => 'license_sync_v2',
            'license_key' => $validationLicenseKey,
            'installation_id' => $validationInstallationId,
            'fingerprint' => $validationFingerprint,
            'domain' => (string)parse_url(app_base_url(), PHP_URL_HOST),
            'app_url' => app_base_url(),
            'ts' => time(),
            'nonce' => substr(bin2hex(random_bytes(10)), 0, 20),
            'request_id' => strtoupper(substr(bin2hex(random_bytes(8)), 0, 16)),
        ];
        $syncPayload['signature'] = app_license_signature_create($syncPayload, $bearer);
        if (trim((string)$syncPayload['signature']) === '') {
            return false;
        }

        $headers = ['Authorization: Bearer ' . $bearer];
        $candidateUrls = app_support_remote_candidate_urls($remoteUrl);
        if (empty($candidateUrls)) {
            $candidateUrls = [$remoteUrl];
        }
        $candidateUrls = array_slice($candidateUrls, 0, 4);

        $validated = false;
        $assignedToken = '';
        $assignedRemoteUrl = '';
        foreach ($candidateUrls as $candidateUrl) {
            $http = app_license_http_post_json((string)$candidateUrl, $syncPayload, $headers, 8);
            if (empty($http['ok'])) {
                continue;
            }
            $decoded = json_decode((string)($http['body'] ?? ''), true);
            if (!is_array($decoded) || (isset($decoded['ok']) && !$decoded['ok'])) {
                continue;
            }
            $node = (isset($decoded['license']) && is_array($decoded['license'])) ? $decoded['license'] : $decoded;
            $assignedToken = trim((string)($node['assigned_api_token'] ?? $decoded['assigned_api_token'] ?? ''));
            $assignedRemoteUrl = trim((string)($node['assigned_remote_url'] ?? $decoded['assigned_remote_url'] ?? ''));
            $validated = true;
            break;
        }
        if (!$validated) {
            return false;
        }

        $envRemote = app_license_env_remote();
        $remoteLocked = app_license_remote_lock_mode();
        $assignedLicenseKey = strtoupper(trim((string)($node['assigned_license_key'] ?? $decoded['assigned_license_key'] ?? '')));
        $newToken = $assignedToken !== '' ? $assignedToken : $bearer;
        $newUrl = $remoteUrl;
        if ($assignedRemoteUrl !== '' && ($envRemote['url'] === '' || !$remoteLocked)) {
            $scheme = strtolower((string)parse_url($assignedRemoteUrl, PHP_URL_SCHEME));
            if (filter_var($assignedRemoteUrl, FILTER_VALIDATE_URL) && in_array($scheme, ['http', 'https'], true)) {
                $newUrl = mb_substr($assignedRemoteUrl, 0, 255);
            }
        }
        $targetLicenseKey = $localLicenseKey;
        if ($assignedLicenseKey !== '') {
            $targetLicenseKey = mb_substr($assignedLicenseKey, 0, 180);
        } elseif ($targetLicenseKey === '') {
            $targetLicenseKey = mb_substr($validationLicenseKey, 0, 180);
        }
        if (($newToken !== '' && ($envRemote['token'] === '' || !$remoteLocked)) || $targetLicenseKey !== '') {
            $newToken = mb_substr($newToken, 0, 190);
            $stmt = $conn->prepare("UPDATE app_license_state SET license_key = ?, remote_url = ?, remote_token = ?, last_error = '' WHERE id = 1");
            $stmt->bind_param('sss', $targetLicenseKey, $newUrl, $newToken);
            $stmt->execute();
            $stmt->close();
        }

        return true;
    }
}

if (!function_exists('app_license_api_resolve_shared_secret')) {
    function app_license_api_resolve_shared_secret(mysqli $conn, array $payload, string $bearerToken): string
    {
        $bearer = trim($bearerToken);
        if ($bearer === '') {
            return '';
        }

        $candidates = [];
        $globalToken = trim((string)app_env('APP_LICENSE_API_TOKEN', app_env('APP_LICENSE_REMOTE_TOKEN', '')));
        if ($globalToken !== '') {
            $candidates[] = $globalToken;
        }

        $localState = app_license_row($conn);
        $localRemoteToken = trim((string)($localState['remote_token'] ?? ''));
        if ($localRemoteToken !== '') {
            $candidates[] = $localRemoteToken;
        }

        $licenseKey = strtoupper(trim((string)($payload['license_key'] ?? '')));
        if ($licenseKey !== '' && app_license_edition() === 'owner') {
            $stmt = $conn->prepare("
                SELECT api_token, previous_api_token, previous_api_token_expires_at
                FROM app_license_registry
                WHERE license_key = ?
                LIMIT 1
            ");
            $stmt->bind_param('s', $licenseKey);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc() ?: [];
            $stmt->close();

            $apiToken = trim((string)($row['api_token'] ?? ''));
            if ($apiToken !== '') {
                $candidates[] = $apiToken;
            }
            $prevToken = trim((string)($row['previous_api_token'] ?? ''));
            $prevUntil = trim((string)($row['previous_api_token_expires_at'] ?? ''));
            if ($prevToken !== '') {
                $prevTs = $prevUntil !== '' ? strtotime($prevUntil) : false;
                if ($prevTs === false || $prevTs >= time()) {
                    $candidates[] = $prevToken;
                }
            }
        }
        if (app_license_edition() === 'owner') {
            $stmtAny = $conn->prepare("
                SELECT api_token, previous_api_token, previous_api_token_expires_at
                FROM app_license_registry
                WHERE api_token = ? OR previous_api_token = ?
                LIMIT 2
            ");
            $stmtAny->bind_param('ss', $bearer, $bearer);
            $stmtAny->execute();
            $resAny = $stmtAny->get_result();
            while ($resAny && ($rowAny = $resAny->fetch_assoc())) {
                $apiAny = trim((string)($rowAny['api_token'] ?? ''));
                if ($apiAny !== '') {
                    $candidates[] = $apiAny;
                }
                $prevAny = trim((string)($rowAny['previous_api_token'] ?? ''));
                $prevUntilAny = trim((string)($rowAny['previous_api_token_expires_at'] ?? ''));
                if ($prevAny !== '') {
                    $prevTsAny = $prevUntilAny !== '' ? strtotime($prevUntilAny) : false;
                    if ($prevTsAny === false || $prevTsAny >= time()) {
                        $candidates[] = $prevAny;
                    }
                }
            }
            $stmtAny->close();
        }

        foreach ($candidates as $candidate) {
            $candidate = trim((string)$candidate);
            if ($candidate !== '' && hash_equals($candidate, $bearer)) {
                return $candidate;
            }
        }
        if (app_license_edition() === 'client' && app_license_client_adopt_remote_token_if_valid($conn, $payload, $bearer)) {
            return $bearer;
        }
        return '';
    }
}

if (!function_exists('app_license_api_validate_signed_request')) {
    function app_license_api_validate_signed_request(mysqli $conn, array $payload, string $secret, string $licenseKey, string $installationId): array
    {
        $ts = (int)($payload['ts'] ?? 0);
        if ($ts <= 0) {
            return ['ok' => false, 'error' => 'invalid_timestamp', 'http_code' => 422];
        }
        if (abs(time() - $ts) > 300) {
            return ['ok' => false, 'error' => 'timestamp_expired', 'http_code' => 401];
        }

        $nonce = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($payload['nonce'] ?? ''));
        $nonce = is_string($nonce) ? trim($nonce) : '';
        if ($nonce === '' || strlen($nonce) < 8 || strlen($nonce) > 80) {
            return ['ok' => false, 'error' => 'invalid_nonce', 'http_code' => 422];
        }

        $signature = trim((string)($payload['signature'] ?? ''));
        if (!app_license_signature_verify($payload, $secret, $signature)) {
            return ['ok' => false, 'error' => 'bad_signature', 'http_code' => 401];
        }

        if (app_license_edition() === 'owner') {
            $registered = app_license_nonce_register($conn, $licenseKey, $installationId, $nonce);
            if (!$registered) {
                return ['ok' => false, 'error' => 'replay_detected', 'http_code' => 409];
            }
        }

        return ['ok' => true, 'error' => '', 'http_code' => 200];
    }
}

if (!function_exists('app_license_api_bearer_allowed')) {
    function app_license_api_bearer_allowed(mysqli $conn, array $payload, string $bearerToken): bool
    {
        return app_license_api_resolve_shared_secret($conn, $payload, $bearerToken) !== '';
    }
}

if (!function_exists('app_license_api_log_unauthorized_attempt')) {
    function app_license_api_log_unauthorized_attempt(mysqli $conn, array $payload, string $bearerToken = ''): void
    {
        if (app_license_edition() !== 'owner') {
            return;
        }
        app_initialize_license_management($conn);

        $licenseKey = strtoupper(trim((string)($payload['license_key'] ?? '')));
        $installationId = mb_substr(trim((string)($payload['installation_id'] ?? '')), 0, 80);
        $fingerprint = mb_substr(trim((string)($payload['fingerprint'] ?? '')), 0, 80);
        $domain = app_license_normalize_domain((string)($payload['domain'] ?? ''));
        $appUrl = mb_substr(trim((string)($payload['app_url'] ?? '')), 0, 255);
        $event = strtolower(trim((string)($payload['event'] ?? 'check')));
        if ($domain === '' && $appUrl !== '') {
            $domain = app_license_normalize_domain((string)parse_url($appUrl, PHP_URL_HOST));
        }

        if ($licenseKey === '' && $installationId === '' && $fingerprint === '' && $domain === '' && $appUrl === '') {
            return;
        }

        $licenseId = 0;
        $plan = 'unknown';
        if ($licenseKey !== '') {
            $license = app_license_registry_by_key($conn, $licenseKey);
            if (!empty($license)) {
                $licenseId = (int)($license['id'] ?? 0);
                $plan = strtolower(trim((string)($license['plan_type'] ?? 'unknown')));
            }
        }
        if ($plan === '') {
            $plan = 'unknown';
        }

        $stmtDup = $conn->prepare("
            SELECT id
            FROM app_license_runtime_log
            WHERE license_key = ?
              AND installation_id = ?
              AND fingerprint = ?
              AND domain = ?
              AND status = 'unauthorized'
              AND checked_at >= (NOW() - INTERVAL 2 MINUTE)
            LIMIT 1
        ");
        $stmtDup->bind_param('ssss', $licenseKey, $installationId, $fingerprint, $domain);
        $stmtDup->execute();
        $dup = $stmtDup->get_result()->fetch_assoc();
        $stmtDup->close();
        if ($dup) {
            return;
        }

        app_license_runtime_log_add(
            $conn,
            $licenseId,
            $licenseKey,
            $domain,
            $installationId,
            $fingerprint,
            'unauthorized',
            $plan,
            $appUrl
        );
        $tokenHash = $bearerToken !== '' ? substr(hash('sha256', $bearerToken), 0, 12) : 'none';
        app_license_alert_add(
            $conn,
            $licenseId,
            'unauthorized_api_token',
            'warning',
            'Unauthorized API token attempt',
            'A runtime attempted to access license API with an unauthorized token.',
            [
                'license_key' => $licenseKey,
                'installation_id' => $installationId,
                'fingerprint' => $fingerprint,
                'domain' => $domain,
                'app_url' => $appUrl,
                'event' => $event,
                'token_hash' => $tokenHash,
            ]
        );
    }
}

if (!function_exists('app_license_api_sync_v2')) {
    function app_license_api_sync_v2(mysqli $conn, array $payload, string $bearerToken = ''): array
    {
        app_initialize_license_management($conn);
        if (app_license_edition() !== 'owner') {
            return ['http_code' => 404, 'body' => ['ok' => false, 'error' => 'not_available']];
        }

        $licenseKey = strtoupper(trim((string)($payload['license_key'] ?? '')));
        $installationId = trim((string)($payload['installation_id'] ?? ''));
        $fingerprint = trim((string)($payload['fingerprint'] ?? ''));
        $domain = app_license_normalize_domain((string)($payload['domain'] ?? ''));
        $appUrl = trim((string)($payload['app_url'] ?? ''));
        $reportNode = (isset($payload['support_report']) && is_array($payload['support_report'])) ? $payload['support_report'] : [];
        $requestedLicenseKey = $licenseKey;
        $assignedLicenseKey = '';

        if ($licenseKey === '' || $installationId === '' || $fingerprint === '' || $domain === '') {
            return ['http_code' => 422, 'body' => ['ok' => false, 'error' => 'missing_required_fields']];
        }

        $blocked = app_license_blocked_runtime_match($conn, $domain, $appUrl, $installationId, $fingerprint, $licenseKey);
        if (!empty($blocked)) {
            app_license_runtime_log_add(
                $conn,
                0,
                $licenseKey,
                $domain,
                $installationId,
                $fingerprint,
                'blocked',
                'blocked',
                $appUrl
            );
            return [
                'http_code' => 200,
                'body' => [
                    'ok' => true,
                    'license' => [
                        'status' => 'suspended',
                        'plan' => 'subscription',
                        'owner_name' => '',
                        'trial_ends_at' => null,
                        'subscription_ends_at' => date('Y-m-d H:i:s'),
                        'grace_days' => 0,
                        'assigned_license_key' => null,
                        'assigned_api_token' => null,
                        'assigned_remote_url' => rtrim((string)app_base_url(), '/') . '/license_api.php',
                    ],
                    'error' => 'blocked_client',
                ],
            ];
        }

        $sharedSecret = app_license_api_resolve_shared_secret($conn, $payload, $bearerToken);
        if ($sharedSecret === '') {
            app_license_api_log_unauthorized_attempt($conn, $payload, $bearerToken);
            return ['http_code' => 401, 'body' => ['ok' => false, 'error' => 'unauthorized']];
        }
        $guard = app_license_api_validate_signed_request($conn, $payload, $sharedSecret, $licenseKey, $installationId);
        if (empty($guard['ok'])) {
            app_license_api_log_unauthorized_attempt($conn, $payload, $bearerToken);
            return [
                'http_code' => (int)($guard['http_code'] ?? 401),
                'body' => ['ok' => false, 'error' => (string)($guard['error'] ?? 'request_rejected')],
            ];
        }

        $license = app_license_registry_by_key($conn, $licenseKey);
        $binding = app_license_runtime_binding_find($conn, $installationId, $fingerprint);
        if (!empty($binding)) {
            $mappedLicenseId = (int)($binding['license_id'] ?? 0);
            if ($mappedLicenseId > 0) {
                $mappedLicense = app_license_registry_get($conn, $mappedLicenseId);
                if (!empty($mappedLicense)) {
                    $mappedLicenseKey = strtoupper(trim((string)($mappedLicense['license_key'] ?? '')));
                    if ((int)($license['id'] ?? 0) !== $mappedLicenseId) {
                        $license = $mappedLicense;
                    }
                    if ($mappedLicenseKey !== '') {
                        $assignedLicenseKey = $mappedLicenseKey;
                        $licenseKey = $mappedLicenseKey;
                    }
                }
            }
        }

        if (!$license) {
            app_license_runtime_log_add(
                $conn,
                0,
                $licenseKey,
                $domain,
                $installationId,
                $fingerprint,
                'suspended',
                'unknown',
                $appUrl
            );
            app_license_alert_add(
                $conn,
                0,
                'unknown_license_key',
                'warning',
                'Unknown license key check',
                'A runtime attempted to validate with an unknown license key.',
                ['license_key' => $licenseKey, 'requested_license_key' => $requestedLicenseKey, 'domain' => $domain]
            );
            return [
                'http_code' => 200,
                'body' => [
                    'ok' => true,
                    'license' => [
                        'status' => 'suspended',
                        'plan' => 'subscription',
                        'owner_name' => '',
                        'trial_ends_at' => null,
                        'subscription_ends_at' => date('Y-m-d H:i:s'),
                        'grace_days' => 0,
                        'assigned_license_key' => null,
                        'assigned_api_token' => null,
                        'assigned_remote_url' => rtrim((string)app_base_url(), '/') . '/license_api.php',
                    ],
                ],
            ];
        }

        $licenseId = (int)$license['id'];
        $assignedApiToken = trim((string)($license['api_token'] ?? ''));
        $assignedRemoteUrl = rtrim((string)app_base_url(), '/') . '/license_api.php';
        $allowedDomains = app_license_decode_domains((string)($license['allowed_domains'] ?? ''));
        if (!empty($allowedDomains) && !app_license_domain_allowed($allowedDomains, $domain)) {
            app_license_runtime_log_add(
                $conn,
                $licenseId,
                $licenseKey,
                $domain,
                $installationId,
                $fingerprint,
                'suspended',
                (string)($license['plan_type'] ?? 'subscription'),
                $appUrl
            );
            return [
                'http_code' => 200,
                'body' => [
                    'ok' => true,
                    'license' => [
                        'status' => 'suspended',
                        'plan' => (string)($license['plan_type'] ?? 'subscription'),
                        'owner_name' => (string)($license['client_name'] ?? ''),
                        'trial_ends_at' => $license['trial_ends_at'] ?? null,
                        'subscription_ends_at' => $license['subscription_ends_at'] ?? null,
                        'grace_days' => (int)($license['grace_days'] ?? 0),
                        'assigned_license_key' => $assignedLicenseKey !== '' ? $assignedLicenseKey : null,
                        'assigned_api_token' => $assignedApiToken !== '' ? $assignedApiToken : null,
                        'assigned_remote_url' => $assignedRemoteUrl,
                    ],
                ],
            ];
        }

        $maxUsers = max(0, (int)($license['max_users'] ?? 0));
        if ($maxUsers > 0) {
            $reportedUsersCount = max(0, (int)($reportNode['users_count'] ?? 0));
            if ($reportedUsersCount > $maxUsers) {
                app_license_runtime_log_add(
                    $conn,
                    $licenseId,
                    $licenseKey,
                    $domain,
                    $installationId,
                    $fingerprint,
                    'suspended',
                    (string)($license['plan_type'] ?? 'subscription'),
                    $appUrl
                );
                return [
                    'http_code' => 200,
                    'body' => [
                        'ok' => true,
                        'license' => [
                            'status' => 'suspended',
                            'plan' => (string)($license['plan_type'] ?? 'subscription'),
                            'owner_name' => (string)($license['client_name'] ?? ''),
                            'trial_ends_at' => $license['trial_ends_at'] ?? null,
                            'subscription_ends_at' => $license['subscription_ends_at'] ?? null,
                            'grace_days' => (int)($license['grace_days'] ?? 0),
                            'assigned_license_key' => $assignedLicenseKey !== '' ? $assignedLicenseKey : null,
                            'assigned_api_token' => $assignedApiToken !== '' ? $assignedApiToken : null,
                            'assigned_remote_url' => $assignedRemoteUrl,
                        ],
                    ],
                ];
            }
        }

        $strictInstall = (int)($license['strict_installation'] ?? 0) === 1;
        $maxInstallations = max(1, (int)($license['max_installations'] ?? 1));
        if ($strictInstall) {
            $stmtExists = $conn->prepare("
                SELECT id
                FROM app_license_installations
                WHERE license_id = ? AND installation_id = ? AND fingerprint = ?
                LIMIT 1
            ");
            $stmtExists->bind_param('iss', $licenseId, $installationId, $fingerprint);
            $stmtExists->execute();
            $existingInstall = $stmtExists->get_result()->fetch_assoc();
            $stmtExists->close();

            if (!$existingInstall) {
                $count = app_license_installation_count($conn, $licenseId);
                if ($count >= $maxInstallations) {
                    app_license_runtime_log_add(
                        $conn,
                        $licenseId,
                        $licenseKey,
                        $domain,
                        $installationId,
                        $fingerprint,
                        'suspended',
                        (string)($license['plan_type'] ?? 'subscription'),
                        $appUrl
                    );
                    return [
                        'http_code' => 200,
                        'body' => [
                            'ok' => true,
                            'license' => [
                                'status' => 'suspended',
                                'plan' => (string)($license['plan_type'] ?? 'subscription'),
                                'owner_name' => (string)($license['client_name'] ?? ''),
                                'trial_ends_at' => $license['trial_ends_at'] ?? null,
                                'subscription_ends_at' => $license['subscription_ends_at'] ?? null,
                                'grace_days' => (int)($license['grace_days'] ?? 0),
                                'assigned_license_key' => $assignedLicenseKey !== '' ? $assignedLicenseKey : null,
                                'assigned_api_token' => $assignedApiToken !== '' ? $assignedApiToken : null,
                                'assigned_remote_url' => $assignedRemoteUrl,
                            ],
                        ],
                    ];
                }
            }
        }

        $touch = app_license_installation_touch(
            $conn,
            $licenseId,
            $installationId,
            $fingerprint,
            $domain,
            $appUrl,
            ['payload_meta' => ['event' => (string)($payload['event'] ?? 'license_sync_v2'), 'request_id' => (string)($payload['request_id'] ?? '')]]
        );
        if (!empty($touch['is_new'])) {
            app_license_alert_add(
                $conn,
                $licenseId,
                'new_installation',
                'warning',
                'New installation detected',
                'A new installation started with this license.',
                ['license_key' => $licenseKey, 'domain' => $domain, 'installation_id' => $installationId]
            );
        }

        if (!empty($reportNode)) {
            app_support_owner_store_client_report(
                $conn,
                $licenseId,
                $licenseKey,
                $domain,
                $appUrl,
                $installationId,
                $fingerprint,
                $reportNode
            );
        }

        $state = app_license_registry_effective_state($license);
        app_license_runtime_log_add(
            $conn,
            $licenseId,
            $licenseKey,
            $domain,
            $installationId,
            $fingerprint,
            (string)$state['status'],
            (string)$state['plan'],
            $appUrl
        );

        return [
            'http_code' => 200,
            'body' => [
                'ok' => true,
                'license' => [
                    'status' => (string)$state['status'],
                    'plan' => (string)$state['plan'],
                    'owner_name' => (string)($license['client_name'] ?? ''),
                    'trial_ends_at' => $state['trial_ends_at'] !== '' ? (string)$state['trial_ends_at'] : null,
                    'subscription_ends_at' => $state['subscription_ends_at'] !== '' ? (string)$state['subscription_ends_at'] : null,
                    'grace_days' => (int)$state['grace_days'],
                    'assigned_license_key' => $assignedLicenseKey !== '' ? $assignedLicenseKey : (string)($license['license_key'] ?? ''),
                    'assigned_api_token' => $assignedApiToken !== '' ? $assignedApiToken : null,
                    'assigned_remote_url' => $assignedRemoteUrl,
                ],
                'server' => [
                    'mode' => 'pull_only',
                    'recommended_sync_seconds' => 20,
                    'timestamp' => date('c'),
                ],
            ],
        ];
    }
}
