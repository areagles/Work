<?php

if (!function_exists('app_license_registry_effective_state')) {
    function app_license_registry_effective_state(array $row): array
    {
        $plan = strtolower(trim((string)($row['plan_type'] ?? 'trial')));
        $status = strtolower(trim((string)($row['status'] ?? 'active')));
        $trialEnds = trim((string)($row['trial_ends_at'] ?? ''));
        $subscriptionEnds = trim((string)($row['subscription_ends_at'] ?? ''));
        $graceDays = max(0, min(60, (int)($row['grace_days'] ?? 3)));
        $now = time();

        if ($status === 'suspended') {
            return [
                'status' => 'suspended',
                'plan' => $plan,
                'trial_ends_at' => $trialEnds,
                'subscription_ends_at' => $subscriptionEnds,
                'grace_days' => $graceDays,
            ];
        }
        if ($status === 'expired') {
            return [
                'status' => 'expired',
                'plan' => $plan,
                'trial_ends_at' => $trialEnds,
                'subscription_ends_at' => $subscriptionEnds,
                'grace_days' => $graceDays,
            ];
        }

        if ($plan === 'trial') {
            if ($trialEnds === '' && !empty($row['created_at'])) {
                $trialEnds = date('Y-m-d H:i:s', strtotime((string)$row['created_at'] . ' +14 days'));
            }
            $trialTs = $trialEnds !== '' ? strtotime($trialEnds) : false;
            if ($trialTs === false || $now > $trialTs) {
                $status = 'expired';
            } else {
                $status = 'active';
            }
        } elseif ($plan === 'subscription') {
            $subTs = $subscriptionEnds !== '' ? strtotime($subscriptionEnds) : false;
            if ($subTs === false) {
                $status = 'expired';
            } else {
                $status = ($now > ($subTs + ($graceDays * 86400))) ? 'expired' : 'active';
            }
        } elseif ($plan === 'lifetime') {
            $status = 'active';
        } else {
            $status = 'expired';
        }

        return [
            'status' => $status,
            'plan' => $plan,
            'trial_ends_at' => $trialEnds,
            'subscription_ends_at' => $subscriptionEnds,
            'grace_days' => $graceDays,
        ];
    }
}

if (!function_exists('app_license_registry_get')) {
    function app_license_registry_get(mysqli $conn, int $id): array
    {
        app_initialize_license_management($conn);
        if ($id <= 0) {
            return [];
        }
        $stmt = $conn->prepare("SELECT * FROM app_license_registry WHERE id = ? LIMIT 1");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: [];
        $stmt->close();
        return is_array($row) ? $row : [];
    }
}

if (!function_exists('app_license_registry_by_key')) {
    function app_license_registry_by_key(mysqli $conn, string $licenseKey): array
    {
        app_initialize_license_management($conn);
        $licenseKey = trim($licenseKey);
        if ($licenseKey === '') {
            return [];
        }
        $stmt = $conn->prepare("SELECT * FROM app_license_registry WHERE license_key = ? LIMIT 1");
        $stmt->bind_param('s', $licenseKey);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: [];
        $stmt->close();
        return is_array($row) ? $row : [];
    }
}

if (!function_exists('app_license_registry_list')) {
    function app_license_registry_list(mysqli $conn, int $limit = 300): array
    {
        app_initialize_license_management($conn);
        $limit = max(1, min(1000, $limit));
        $stmt = $conn->prepare("
            SELECT r.*,
                   (SELECT COUNT(*) FROM app_license_installations i WHERE i.license_id = r.id) AS installations_count,
                   (SELECT MAX(last_seen_at) FROM app_license_installations i WHERE i.license_id = r.id) AS last_seen_at,
                   (
                       SELECT sr.users_count
                       FROM app_support_client_reports sr
                       WHERE sr.license_key = r.license_key
                       ORDER BY sr.last_report_at DESC, sr.id DESC
                       LIMIT 1
                   ) AS latest_users_count
            FROM app_license_registry r
            ORDER BY r.updated_at DESC, r.id DESC
            LIMIT ?
        ");
        $stmt->bind_param('i', $limit);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
        $stmt->close();
        return is_array($rows) ? $rows : [];
    }
}

if (!function_exists('app_license_registry_save')) {
    function app_license_registry_save(mysqli $conn, array $payload): array
    {
        app_initialize_license_management($conn);
        if (!app_is_super_user() || app_license_edition() !== 'owner') {
            return ['ok' => false, 'error' => 'super_user_required'];
        }

        $id = max(0, (int)($payload['id'] ?? 0));
        $licenseKey = strtoupper(trim((string)($payload['license_key'] ?? '')));
        if ($licenseKey === '') {
            $licenseKey = app_license_make_key();
        }
        $clientName = trim((string)($payload['client_name'] ?? ''));
        $clientEmail = trim((string)($payload['client_email'] ?? ''));
        $clientPhone = trim((string)($payload['client_phone'] ?? ''));
        $plan = strtolower(trim((string)($payload['plan_type'] ?? 'trial')));
        $status = strtolower(trim((string)($payload['status'] ?? 'active')));
        $trialEnds = trim((string)($payload['trial_ends_at'] ?? ''));
        $subscriptionEnds = trim((string)($payload['subscription_ends_at'] ?? ''));
        $graceDays = max(0, min(60, (int)($payload['grace_days'] ?? 3)));
        $strictInstall = !empty($payload['strict_installation']) ? 1 : 0;
        $maxInstallations = max(1, min(20, (int)($payload['max_installations'] ?? 1)));
        $maxUsers = max(0, min(10000, (int)($payload['max_users'] ?? 0)));
        $apiToken = trim((string)($payload['api_token'] ?? ''));
        if ($apiToken === '') {
            $apiToken = app_license_make_api_token();
        }
        if (function_exists('mb_substr')) {
            $apiToken = (string)mb_substr($apiToken, 0, 190, 'UTF-8');
        } else {
            $apiToken = substr($apiToken, 0, 190);
        }
        $lockReason = trim((string)($payload['lock_reason'] ?? ''));
        $notes = trim((string)($payload['notes'] ?? ''));
        $domains = app_license_decode_domains($payload['allowed_domains'] ?? '');
        $domainsJson = app_license_encode_domains($domains);

        if (!in_array($plan, ['trial', 'subscription', 'lifetime'], true)) {
            return ['ok' => false, 'error' => 'invalid_plan'];
        }
        if (!in_array($status, ['active', 'expired', 'suspended'], true)) {
            $status = 'active';
        }

        if ($plan === 'trial') {
            if ($trialEnds !== '' && strtotime($trialEnds) === false) {
                return ['ok' => false, 'error' => 'invalid_trial_ends_at'];
            }
            if ($trialEnds === '') {
                $trialEnds = date('Y-m-d H:i:s', strtotime('+14 days'));
            }
            $subscriptionEnds = '';
        } elseif ($plan === 'subscription') {
            if ($subscriptionEnds === '') {
                $subscriptionEnds = date('Y-m-d H:i:s', strtotime('+30 days'));
            } elseif (strtotime($subscriptionEnds) === false) {
                return ['ok' => false, 'error' => 'invalid_subscription_ends_at'];
            }
            $trialEnds = '';
        } else {
            $trialEnds = '';
            $subscriptionEnds = '';
        }

        if ($id > 0) {
            $stmt = $conn->prepare("
                UPDATE app_license_registry
                SET license_key = ?, client_name = ?, client_email = ?, client_phone = ?,
                    plan_type = ?, status = ?, trial_ends_at = ?, subscription_ends_at = ?, grace_days = ?,
                    allowed_domains = ?, strict_installation = ?, max_installations = ?, max_users = ?, api_token = ?, lock_reason = ?, notes = ?
                WHERE id = ?
            ");
            $stmt->bind_param(
                'ssssssssisiiisssi',
                $licenseKey,
                $clientName,
                $clientEmail,
                $clientPhone,
                $plan,
                $status,
                $trialEnds,
                $subscriptionEnds,
                $graceDays,
                $domainsJson,
                $strictInstall,
                $maxInstallations,
                $maxUsers,
                $apiToken,
                $lockReason,
                $notes,
                $id
            );
            $ok = $stmt->execute();
            $stmt->close();
            return ['ok' => (bool)$ok, 'error' => $ok ? '' : 'db_update_failed', 'id' => $id, 'license_key' => $licenseKey, 'api_token' => $apiToken];
        }

        $stmt = $conn->prepare("
            INSERT INTO app_license_registry (
                license_key, client_name, client_email, client_phone,
                plan_type, status, trial_ends_at, subscription_ends_at, grace_days,
                allowed_domains, strict_installation, max_installations, max_users, api_token, lock_reason, notes
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param(
            'ssssssssisiiisss',
            $licenseKey,
            $clientName,
            $clientEmail,
            $clientPhone,
            $plan,
            $status,
            $trialEnds,
            $subscriptionEnds,
            $graceDays,
            $domainsJson,
            $strictInstall,
            $maxInstallations,
            $maxUsers,
            $apiToken,
            $lockReason,
            $notes
        );
        $ok = $stmt->execute();
        $newId = (int)$stmt->insert_id;
        $stmt->close();
        return ['ok' => (bool)$ok, 'error' => $ok ? '' : 'db_insert_failed', 'id' => $newId, 'license_key' => $licenseKey, 'api_token' => $apiToken];
    }
}

if (!function_exists('app_license_registry_create_auto_bootstrap')) {
    function app_license_registry_create_auto_bootstrap(
        mysqli $conn,
        string $domain = '',
        string $appUrl = '',
        string $preferredLicenseKey = '',
        string $notes = ''
    ): array {
        app_initialize_license_management($conn);
        if (app_license_edition() !== 'owner') {
            return ['ok' => false, 'error' => 'not_owner_edition'];
        }

        $domain = app_license_normalize_domain($domain);
        $appUrl = mb_substr(trim($appUrl), 0, 255);
        $preferredLicenseKey = strtoupper(trim($preferredLicenseKey));
        $notes = trim($notes);

        $clientName = $domain !== '' ? $domain : app_tr('عميل جديد', 'New Client');
        $licenseKey = $preferredLicenseKey !== '' ? $preferredLicenseKey : app_license_make_key();
        $apiToken = app_license_make_api_token();
        $trialEnds = date('Y-m-d H:i:s', strtotime('+14 days'));
        $domainsJson = $domain !== '' ? app_license_encode_domains([$domain]) : '[]';
        $notesText = $notes !== '' ? $notes : ('Auto-created from runtime bootstrap' . ($appUrl !== '' ? (' [' . $appUrl . ']') : ''));

        $stmt = $conn->prepare("
            INSERT INTO app_license_registry (
                license_key, client_name, client_email, client_phone,
                plan_type, status, trial_ends_at, subscription_ends_at, grace_days,
                allowed_domains, strict_installation, max_installations, max_users, api_token, lock_reason, notes
            ) VALUES (?, ?, '', '', 'trial', 'active', ?, '', 3, ?, 1, 1, 0, ?, '', ?)
        ");
        $stmt->bind_param(
            'sssss',
            $licenseKey,
            $clientName,
            $trialEnds,
            $domainsJson,
            $apiToken,
            $notesText
        );
        $ok = $stmt->execute();
        $newId = (int)$stmt->insert_id;
        $stmt->close();
        if (!$ok || $newId <= 0) {
            return ['ok' => false, 'error' => 'db_insert_failed'];
        }

        $row = app_license_registry_get($conn, $newId);
        if (empty($row)) {
            return ['ok' => false, 'error' => 'license_created_but_not_loaded'];
        }
        return ['ok' => true, 'error' => '', 'row' => $row];
    }
}

if (!function_exists('app_license_runtime_find_auto_bootstrap_license')) {
    function app_license_runtime_find_auto_bootstrap_license(mysqli $conn, string $preferredLicenseKey = '', string $domain = ''): array
    {
        app_initialize_license_management($conn);
        $preferredLicenseKey = strtoupper(trim($preferredLicenseKey));
        $domain = app_license_normalize_domain($domain);

        if ($preferredLicenseKey !== '') {
            $byKey = app_license_registry_by_key($conn, $preferredLicenseKey);
            if (!empty($byKey)) {
                return $byKey;
            }
        }

        if ($domain === '') {
            return [];
        }

        $stmt = $conn->prepare("
            SELECT *
            FROM app_license_registry
            ORDER BY id DESC
            LIMIT 800
        ");
        $stmt->execute();
        $res = $stmt->get_result();
        while ($res && ($row = $res->fetch_assoc())) {
            $allowed = app_license_decode_domains((string)($row['allowed_domains'] ?? ''));
            if (!empty($allowed) && app_license_domain_allowed($allowed, $domain)) {
                $stmt->close();
                return $row;
            }
        }
        $stmt->close();

        return [];
    }
}

if (!function_exists('app_license_registry_rotate_api_token')) {
    function app_license_registry_rotate_api_token(mysqli $conn, int $licenseId): array
    {
        app_initialize_license_management($conn);
        if (!app_is_super_user() || app_license_edition() !== 'owner') {
            return ['ok' => false, 'error' => 'super_user_required'];
        }
        if ($licenseId <= 0) {
            return ['ok' => false, 'error' => 'invalid_license_id'];
        }

        $row = app_license_registry_get($conn, $licenseId);
        if (empty($row)) {
            return ['ok' => false, 'error' => 'not_found'];
        }

        $apiToken = app_license_make_api_token();
        $previousApiToken = trim((string)($row['api_token'] ?? ''));
        $fallbackUntil = date('Y-m-d H:i:s', time() + (14 * 86400));
        $stmt = $conn->prepare("
            UPDATE app_license_registry
            SET api_token = ?, previous_api_token = ?, previous_api_token_expires_at = ?
            WHERE id = ?
        ");
        $stmt->bind_param('sssi', $apiToken, $previousApiToken, $fallbackUntil, $licenseId);
        $ok = $stmt->execute();
        $stmt->close();
        return [
            'ok' => (bool)$ok,
            'error' => $ok ? '' : 'db_update_failed',
            'api_token' => $apiToken,
            'previous_api_token' => $previousApiToken,
            'previous_api_token_expires_at' => $fallbackUntil,
            'license_key' => (string)($row['license_key'] ?? ''),
        ];
    }
}

if (!function_exists('app_license_registry_set_lock')) {
    function app_license_registry_set_lock(mysqli $conn, int $licenseId, bool $lock, string $reason = ''): array
    {
        app_initialize_license_management($conn);
        if (!app_is_super_user() || app_license_edition() !== 'owner') {
            return ['ok' => false, 'error' => 'super_user_required'];
        }
        if ($licenseId <= 0) {
            return ['ok' => false, 'error' => 'invalid_license_id'];
        }
        $status = $lock ? 'suspended' : 'active';
        $reason = $lock ? trim($reason) : '';
        $stmt = $conn->prepare("UPDATE app_license_registry SET status = ?, lock_reason = ? WHERE id = ?");
        $stmt->bind_param('ssi', $status, $reason, $licenseId);
        $ok = $stmt->execute();
        $stmt->close();
        if ($ok && $lock) {
            app_license_alert_add($conn, $licenseId, 'manual_lock', 'critical', 'Client Locked', 'A client license was locked manually.');
        }
        return ['ok' => (bool)$ok, 'error' => $ok ? '' : 'db_update_failed'];
    }
}

if (!function_exists('app_license_registry_set_status')) {
    function app_license_registry_set_status(mysqli $conn, int $licenseId, string $status, string $reason = ''): array
    {
        app_initialize_license_management($conn);
        if (!app_is_super_user() || app_license_edition() !== 'owner') {
            return ['ok' => false, 'error' => 'super_user_required'];
        }
        if ($licenseId <= 0) {
            return ['ok' => false, 'error' => 'invalid_license_id'];
        }
        $status = strtolower(trim($status));
        if (!in_array($status, ['active', 'suspended', 'expired'], true)) {
            return ['ok' => false, 'error' => 'invalid_status'];
        }
        $lockReason = $status === 'suspended' ? trim($reason) : '';
        $autoExtended = false;
        $autoExtendedTarget = '';
        $autoExtendedDate = '';

        if ($status === 'active') {
            $row = app_license_registry_get($conn, $licenseId);
            if (!empty($row)) {
                $plan = strtolower(trim((string)($row['plan_type'] ?? 'trial')));
                if ($plan === 'trial') {
                    $trialEnds = trim((string)($row['trial_ends_at'] ?? ''));
                    $trialTs = $trialEnds !== '' ? strtotime($trialEnds) : false;
                    $needsExtend = ($trialTs === false || time() > $trialTs);
                    if ($needsExtend) {
                        $autoExtendedTarget = 'trial';
                        $autoExtendedDate = date('Y-m-d H:i:s', strtotime('+14 days'));
                        $stmt = $conn->prepare("
                            UPDATE app_license_registry
                            SET status = 'active', lock_reason = '', trial_ends_at = ?, subscription_ends_at = NULL
                            WHERE id = ?
                        ");
                        $stmt->bind_param('si', $autoExtendedDate, $licenseId);
                        $ok = $stmt->execute();
                        $stmt->close();
                        if (!$ok) {
                            return ['ok' => false, 'error' => 'db_update_failed'];
                        }
                        $autoExtended = true;
                    }
                } elseif ($plan === 'subscription') {
                    $subscriptionEnds = trim((string)($row['subscription_ends_at'] ?? ''));
                    $subTs = $subscriptionEnds !== '' ? strtotime($subscriptionEnds) : false;
                    $graceDays = max(0, min(60, (int)($row['grace_days'] ?? 3)));
                    $needsExtend = ($subTs === false || time() > ($subTs + ($graceDays * 86400)));
                    if ($needsExtend) {
                        $autoExtendedTarget = 'subscription';
                        $autoExtendedDate = date('Y-m-d H:i:s', strtotime('+30 days'));
                        $stmt = $conn->prepare("
                            UPDATE app_license_registry
                            SET status = 'active', lock_reason = '', subscription_ends_at = ?, trial_ends_at = NULL
                            WHERE id = ?
                        ");
                        $stmt->bind_param('si', $autoExtendedDate, $licenseId);
                        $ok = $stmt->execute();
                        $stmt->close();
                        if (!$ok) {
                            return ['ok' => false, 'error' => 'db_update_failed'];
                        }
                        $autoExtended = true;
                    }
                }
            }
        }

        if (!$autoExtended) {
            $stmt = $conn->prepare("UPDATE app_license_registry SET status = ?, lock_reason = ? WHERE id = ?");
            $stmt->bind_param('ssi', $status, $lockReason, $licenseId);
            $ok = $stmt->execute();
            $stmt->close();
        } else {
            $ok = true;
        }
        if (!$ok) {
            return ['ok' => false, 'error' => 'db_update_failed'];
        }
        $licenseRowAfter = app_license_registry_get($conn, $licenseId);
        $licenseKeyAfter = strtoupper(trim((string)($licenseRowAfter['license_key'] ?? '')));
        if ($licenseKeyAfter !== '' && function_exists('app_desktop_nodes_set_status_by_license')) {
            if ($status === 'active') {
                app_desktop_nodes_set_status_by_license($conn, $licenseKeyAfter, 'active', 0, 0);
            } elseif (in_array($status, ['suspended', 'expired'], true)) {
                app_desktop_nodes_set_status_by_license($conn, $licenseKeyAfter, 'suspended', 1, 0);
            }
        }
        if ($status === 'suspended') {
            app_license_alert_add($conn, $licenseId, 'manual_pause', 'warning', 'Client Paused', 'A client license was paused manually.');
        } elseif ($status === 'active') {
            app_license_alert_add($conn, $licenseId, 'manual_activate', 'info', 'Client Activated', 'A client license was activated manually.');
        }
        return [
            'ok' => true,
            'error' => '',
            'auto_extended' => $autoExtended,
            'auto_extended_target' => $autoExtendedTarget,
            'auto_extended_date' => $autoExtendedDate,
        ];
    }
}

if (!function_exists('app_license_registry_delete')) {
    function app_license_registry_delete(mysqli $conn, int $licenseId): array
    {
        app_initialize_license_management($conn);
        if (!app_is_super_user() || app_license_edition() !== 'owner') {
            return ['ok' => false, 'error' => 'super_user_required'];
        }
        if ($licenseId <= 0) {
            return ['ok' => false, 'error' => 'invalid_license_id'];
        }
        $licenseRow = app_license_registry_get($conn, $licenseId);
        if (empty($licenseRow)) {
            return ['ok' => false, 'error' => 'not_found'];
        }
        $licenseKey = strtoupper(trim((string)($licenseRow['license_key'] ?? '')));

        // Soft-stop clients before hard deletion to reduce grace exposure on connected systems.
        try {
            app_license_registry_set_status($conn, $licenseId, 'suspended', 'License deleted by owner');
            app_license_owner_push_credentials_to_clients($conn, $licenseId, [
                'max_targets' => 8,
                'timeout' => 4,
                'max_urls' => 4,
                'max_tokens' => 3,
            ]);
        } catch (Throwable $e) {
            // Continue hard delete regardless of remote reachability.
        }

        $conn->begin_transaction();
        try {
            $stmt1 = $conn->prepare("DELETE FROM app_license_installations WHERE license_id = ?");
            $stmt1->bind_param('i', $licenseId);
            $stmt1->execute();
            $stmt1->close();

            $stmt2 = $conn->prepare("DELETE FROM app_license_runtime_log WHERE license_id = ?");
            $stmt2->bind_param('i', $licenseId);
            $stmt2->execute();
            $stmt2->close();

            $stmt3 = $conn->prepare("DELETE FROM app_license_alerts WHERE license_id = ?");
            $stmt3->bind_param('i', $licenseId);
            $stmt3->execute();
            $stmt3->close();

            $stmt4 = $conn->prepare("DELETE FROM app_license_runtime_bindings WHERE license_id = ?");
            $stmt4->bind_param('i', $licenseId);
            $stmt4->execute();
            $stmt4->close();

            if ($licenseKey !== '') {
                $stmt5 = $conn->prepare("DELETE FROM app_support_client_report_users WHERE report_id IN (SELECT id FROM app_support_client_reports WHERE license_key = ?)");
                $stmt5->bind_param('s', $licenseKey);
                $stmt5->execute();
                $stmt5->close();

                $stmt6 = $conn->prepare("DELETE FROM app_support_client_reports WHERE license_key = ?");
                $stmt6->bind_param('s', $licenseKey);
                $stmt6->execute();
                $stmt6->close();
            } else {
                $stmt5 = $conn->prepare("DELETE FROM app_support_client_report_users WHERE report_id IN (SELECT id FROM app_support_client_reports WHERE license_id = ?)");
                $stmt5->bind_param('i', $licenseId);
                $stmt5->execute();
                $stmt5->close();

                $stmt6 = $conn->prepare("DELETE FROM app_support_client_reports WHERE license_id = ?");
                $stmt6->bind_param('i', $licenseId);
                $stmt6->execute();
                $stmt6->close();
            }

            $stmt7 = $conn->prepare("DELETE FROM app_license_link_codes WHERE license_id = ?");
            $stmt7->bind_param('i', $licenseId);
            $stmt7->execute();
            $stmt7->close();

            $stmt8 = $conn->prepare("DELETE FROM app_license_registry WHERE id = ?");
            $stmt8->bind_param('i', $licenseId);
            $stmt8->execute();
            $affected = (int)$stmt8->affected_rows;
            $stmt8->close();

            $conn->commit();
            return ['ok' => $affected > 0, 'error' => $affected > 0 ? '' : 'not_found'];
        } catch (Throwable $e) {
            $conn->rollback();
            return ['ok' => false, 'error' => 'delete_failed'];
        }
    }
}

if (!function_exists('app_license_registry_delete_all')) {
    function app_license_registry_delete_all(mysqli $conn): array
    {
        app_initialize_license_management($conn);
        if (!app_is_super_user() || app_license_edition() !== 'owner') {
            return ['ok' => false, 'error' => 'super_user_required'];
        }
        $conn->begin_transaction();
        try {
            $countRow = $conn->query("SELECT COUNT(*) AS c FROM app_license_registry")->fetch_assoc();
            $count = (int)($countRow['c'] ?? 0);
            $conn->query("DELETE FROM app_license_installations");
            $conn->query("DELETE FROM app_license_runtime_log");
            $conn->query("DELETE FROM app_license_runtime_bindings");
            $conn->query("DELETE FROM app_license_link_codes");
            $conn->query("DELETE FROM app_license_alerts");
            $conn->query("DELETE FROM app_support_client_report_users");
            $conn->query("DELETE FROM app_support_client_reports");
            $conn->query("DELETE FROM app_license_registry");
            $conn->commit();
            return ['ok' => true, 'error' => '', 'deleted' => $count];
        } catch (Throwable $e) {
            $conn->rollback();
            return ['ok' => false, 'error' => 'delete_all_failed', 'deleted' => 0];
        }
    }
}

if (!function_exists('app_license_registry_extend_days')) {
    function app_license_registry_extend_days(mysqli $conn, int $licenseId, int $days, string $target = 'auto'): array
    {
        app_initialize_license_management($conn);
        if (!app_is_super_user() || app_license_edition() !== 'owner') {
            return ['ok' => false, 'error' => 'super_user_required'];
        }
        if ($licenseId <= 0) {
            return ['ok' => false, 'error' => 'invalid_license_id'];
        }
        $days = (int)$days;
        if ($days <= 0 || $days > 3650) {
            return ['ok' => false, 'error' => 'invalid_days'];
        }

        $row = app_license_registry_get($conn, $licenseId);
        if (empty($row)) {
            return ['ok' => false, 'error' => 'not_found'];
        }

        $plan = strtolower(trim((string)($row['plan_type'] ?? 'trial')));
        $target = strtolower(trim($target));
        if (!in_array($target, ['auto', 'trial', 'subscription'], true)) {
            $target = 'auto';
        }
        if ($target === 'auto') {
            $target = $plan === 'subscription' ? 'subscription' : 'trial';
        }

        $column = $target === 'subscription' ? 'subscription_ends_at' : 'trial_ends_at';
        if ($plan === 'lifetime' && $target === 'trial') {
            return ['ok' => false, 'error' => 'lifetime_no_trial'];
        }

        $raw = trim((string)($row[$column] ?? ''));
        $baseTs = $raw !== '' ? strtotime($raw) : false;
        if ($baseTs === false || $baseTs < time()) {
            $baseTs = time();
        }
        $newTs = $baseTs + ($days * 86400);
        $newDate = date('Y-m-d H:i:s', $newTs);

        if ($target === 'subscription' && $plan !== 'subscription') {
            $stmt = $conn->prepare("
                UPDATE app_license_registry
                SET plan_type = 'subscription',
                    status = 'active',
                    trial_ends_at = NULL,
                    subscription_ends_at = ?,
                    lock_reason = ''
                WHERE id = ?
            ");
            $stmt->bind_param('si', $newDate, $licenseId);
        } else {
            $sql = "UPDATE app_license_registry SET status = 'active', lock_reason = '', {$column} = ? WHERE id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('si', $newDate, $licenseId);
        }
        $ok = $stmt->execute();
        $stmt->close();
        if (!$ok) {
            return ['ok' => false, 'error' => 'db_update_failed'];
        }
        return ['ok' => true, 'error' => '', 'new_date' => $newDate, 'target' => $target];
    }
}

if (!function_exists('app_license_alert_add')) {
    function app_license_alert_add(mysqli $conn, int $licenseId, string $type, string $severity, string $title, string $message, array $meta = []): void
    {
        app_initialize_license_management($conn);
        $type = trim($type);
        $severity = in_array($severity, ['info', 'warning', 'critical'], true) ? $severity : 'info';
        $titleRaw = trim($title);
        $title = function_exists('mb_substr') ? mb_substr($titleRaw, 0, 190, 'UTF-8') : substr($titleRaw, 0, 190);
        $message = trim($message);
        $metaJson = json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($metaJson)) {
            $metaJson = '{}';
        }
        $stmt = $conn->prepare("
            INSERT INTO app_license_alerts (license_id, alert_type, severity, title, message, meta_json, is_read)
            VALUES (?, ?, ?, ?, ?, ?, 0)
        ");
        $stmt->bind_param('isssss', $licenseId, $type, $severity, $title, $message, $metaJson);
        $stmt->execute();
        $stmt->close();

        $to = trim((string)app_env('APP_LICENSE_ALERT_EMAIL', ''));
        if ($to !== '') {
            $subject = '[License Alert] ' . $title;
            $body = $message . "\n\nType: " . $type . "\nSeverity: " . $severity . "\nTime: " . date('Y-m-d H:i:s');
            @app_send_email_basic($to, $subject, nl2br(app_h($body)));
        }

        $text = app_license_alert_compose_text($type, $severity, $title, $message, $meta);
        app_license_alert_send_telegram($text);
        app_license_alert_send_whatsapp($text);
        app_license_alert_send_webhook($type, $severity, $title, $message, $meta);
    }
}

if (!function_exists('app_license_alert_compose_text')) {
    function app_license_alert_compose_text(string $type, string $severity, string $title, string $message, array $meta = []): string
    {
        $lines = [
            '[License Alert] ' . trim($title),
            'Severity: ' . trim($severity),
            'Type: ' . trim($type),
            'Time: ' . date('Y-m-d H:i:s'),
        ];
        $msg = trim($message);
        if ($msg !== '') {
            $lines[] = 'Message: ' . $msg;
        }
        if (!empty($meta)) {
            $metaJson = json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (is_string($metaJson) && $metaJson !== '') {
                $lines[] = 'Meta: ' . $metaJson;
            }
        }
        return implode("\n", $lines);
    }
}

if (!function_exists('app_license_alert_send_telegram')) {
    function app_license_alert_send_telegram(string $text): void
    {
        $botToken = trim((string)app_env('APP_LICENSE_ALERT_TELEGRAM_BOT_TOKEN', ''));
        $chatId = trim((string)app_env('APP_LICENSE_ALERT_TELEGRAM_CHAT_ID', ''));
        if ($botToken === '' || $chatId === '') {
            return;
        }
        $url = 'https://api.telegram.org/bot' . rawurlencode($botToken) . '/sendMessage';
        $payload = [
            'chat_id' => $chatId,
            'text' => $text,
            'disable_web_page_preview' => true,
        ];
        @app_license_http_post_json($url, $payload, [], 10);
    }
}

if (!function_exists('app_license_alert_send_whatsapp')) {
    function app_license_alert_send_whatsapp(string $text): void
    {
        $token = trim((string)app_env('APP_LICENSE_ALERT_WHATSAPP_TOKEN', ''));
        $phoneNumberId = trim((string)app_env('APP_LICENSE_ALERT_WHATSAPP_PHONE_NUMBER_ID', ''));
        $to = trim((string)app_env('APP_LICENSE_ALERT_WHATSAPP_TO', ''));
        if ($token === '' || $phoneNumberId === '' || $to === '') {
            return;
        }
        $url = 'https://graph.facebook.com/v20.0/' . rawurlencode($phoneNumberId) . '/messages';
        $headers = ['Authorization: Bearer ' . $token];
        $payload = [
            'messaging_product' => 'whatsapp',
            'to' => $to,
            'type' => 'text',
            'text' => ['body' => $text],
        ];
        @app_license_http_post_json($url, $payload, $headers, 10);
    }
}

if (!function_exists('app_license_alert_send_webhook')) {
    function app_license_alert_send_webhook(string $type, string $severity, string $title, string $message, array $meta = []): void
    {
        $url = trim((string)app_env('APP_LICENSE_ALERT_WEBHOOK_URL', ''));
        if ($url === '') {
            return;
        }
        $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
        if (!in_array($scheme, ['http', 'https'], true)) {
            return;
        }
        $token = trim((string)app_env('APP_LICENSE_ALERT_WEBHOOK_TOKEN', ''));
        $headers = [];
        if ($token !== '') {
            $headers[] = 'Authorization: Bearer ' . $token;
        }
        $payload = [
            'event' => 'license_alert',
            'type' => $type,
            'severity' => $severity,
            'title' => $title,
            'message' => $message,
            'meta' => $meta,
            'timestamp' => date('c'),
        ];
        @app_license_http_post_json($url, $payload, $headers, 10);
    }
}

if (!function_exists('app_license_alert_recent')) {
    function app_license_alert_recent(mysqli $conn, int $limit = 100): array
    {
        app_initialize_license_management($conn);
        $limit = max(1, min(500, $limit));
        $stmt = $conn->prepare("
            SELECT a.*, r.client_name, r.license_key
            FROM app_license_alerts a
            LEFT JOIN app_license_registry r ON r.id = a.license_id
            ORDER BY a.id DESC
            LIMIT ?
        ");
        $stmt->bind_param('i', $limit);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
        $stmt->close();
        return is_array($rows) ? $rows : [];
    }
}

if (!function_exists('app_license_alert_unread_count')) {
    function app_license_alert_unread_count(mysqli $conn): int
    {
        app_initialize_license_management($conn);
        $row = $conn->query("SELECT COUNT(*) AS c FROM app_license_alerts WHERE is_read = 0")->fetch_assoc();
        return (int)($row['c'] ?? 0);
    }
}

if (!function_exists('app_license_alert_mark_read')) {
    function app_license_alert_mark_read(mysqli $conn, int $alertId): bool
    {
        app_initialize_license_management($conn);
        if ($alertId <= 0) {
            return false;
        }
        $stmt = $conn->prepare("UPDATE app_license_alerts SET is_read = 1 WHERE id = ?");
        $stmt->bind_param('i', $alertId);
        $ok = $stmt->execute();
        $stmt->close();
        return (bool)$ok;
    }
}

if (!function_exists('app_license_alert_mark_all_read')) {
    function app_license_alert_mark_all_read(mysqli $conn): bool
    {
        app_initialize_license_management($conn);
        return (bool)$conn->query("UPDATE app_license_alerts SET is_read = 1 WHERE is_read = 0");
    }
}

if (!function_exists('app_license_alert_delete')) {
    function app_license_alert_delete(mysqli $conn, int $alertId): bool
    {
        app_initialize_license_management($conn);
        if (!app_is_super_user() || app_license_edition() !== 'owner' || $alertId <= 0) {
            return false;
        }
        $stmt = $conn->prepare("DELETE FROM app_license_alerts WHERE id = ?");
        $stmt->bind_param('i', $alertId);
        $ok = $stmt->execute();
        $stmt->close();
        return (bool)$ok;
    }
}

if (!function_exists('app_license_alert_delete_all')) {
    function app_license_alert_delete_all(mysqli $conn, bool $onlyRead = false): int
    {
        app_initialize_license_management($conn);
        if (!app_is_super_user() || app_license_edition() !== 'owner') {
            return 0;
        }
        if ($onlyRead) {
            $conn->query("DELETE FROM app_license_alerts WHERE is_read = 1");
        } else {
            $conn->query("DELETE FROM app_license_alerts");
        }
        return (int)$conn->affected_rows;
    }
}

if (!function_exists('app_license_runtime_log_add')) {
    function app_license_runtime_log_add(
        mysqli $conn,
        int $licenseId,
        string $licenseKey,
        string $domain,
        string $installationId,
        string $fingerprint,
        string $status,
        string $plan,
        string $appUrl = ''
    ): void
    {
        app_initialize_license_management($conn);
        $checkedAt = date('Y-m-d H:i:s');
        $stmt = $conn->prepare("
            INSERT INTO app_license_runtime_log
            (license_id, license_key, domain, app_url, installation_id, fingerprint, status, plan_type, checked_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $appUrl = mb_substr(trim($appUrl), 0, 255);
        $stmt->bind_param('issssssss', $licenseId, $licenseKey, $domain, $appUrl, $installationId, $fingerprint, $status, $plan, $checkedAt);
        $stmt->execute();
        $stmt->close();
    }
}

if (!function_exists('app_license_runtime_binding_find')) {
    function app_license_runtime_binding_find(mysqli $conn, string $installationId, string $fingerprint): array
    {
        app_initialize_license_management($conn);
        $installationId = trim($installationId);
        $fingerprint = trim($fingerprint);
        if ($installationId === '' || $fingerprint === '') {
            return [];
        }
        $stmt = $conn->prepare("
            SELECT b.*, r.license_key AS target_license_key, r.client_name AS target_client_name
            FROM app_license_runtime_bindings b
            LEFT JOIN app_license_registry r ON r.id = b.license_id
            WHERE b.installation_id = ? AND b.fingerprint = ? AND b.is_active = 1
            LIMIT 1
        ");
        $stmt->bind_param('ss', $installationId, $fingerprint);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: [];
        $stmt->close();
        return is_array($row) ? $row : [];
    }
}

if (!function_exists('app_license_runtime_bind_identity_internal')) {
    function app_license_runtime_bind_identity_internal(
        mysqli $conn,
        int $licenseId,
        string $installationId,
        string $fingerprint,
        string $incomingLicenseKey = '',
        string $domain = '',
        string $notes = '',
        string $appUrl = '',
        bool $activateAfterLink = false,
        bool $allowRebind = false
    ): array {
        app_initialize_license_management($conn);
        if ($licenseId <= 0) {
            return ['ok' => false, 'error' => 'invalid_license_id'];
        }
        $licenseRow = app_license_registry_get($conn, $licenseId);
        if (empty($licenseRow)) {
            return ['ok' => false, 'error' => 'license_not_found'];
        }

        $installationId = mb_substr(trim($installationId), 0, 80);
        $fingerprint = mb_substr(trim($fingerprint), 0, 80);
        if ($installationId === '' || $fingerprint === '') {
            return ['ok' => false, 'error' => 'runtime_identity_missing'];
        }
        $incomingLicenseKey = strtoupper(mb_substr(trim($incomingLicenseKey), 0, 180));
        $domain = app_license_normalize_domain($domain);
        $notes = mb_substr(trim($notes), 0, 255);
        $appUrl = mb_substr(trim($appUrl), 0, 255);

        $checkStmt = $conn->prepare("
            SELECT id
            FROM app_license_runtime_bindings
            WHERE installation_id = ? AND fingerprint = ?
            LIMIT 1
        ");
        $checkStmt->bind_param('ss', $installationId, $fingerprint);
        $checkStmt->execute();
        $existing = $checkStmt->get_result()->fetch_assoc() ?: [];
        $checkStmt->close();

        if (!empty($existing)) {
            $bindingId = (int)($existing['id'] ?? 0);
            $existingLicenseId = 0;
            $stmtBinding = $conn->prepare("
                SELECT license_id
                FROM app_license_runtime_bindings
                WHERE id = ?
                LIMIT 1
            ");
            $stmtBinding->bind_param('i', $bindingId);
            $stmtBinding->execute();
            $existingBinding = $stmtBinding->get_result()->fetch_assoc() ?: [];
            $stmtBinding->close();
            $existingLicenseId = (int)($existingBinding['license_id'] ?? 0);
            if ($existingLicenseId > 0 && $existingLicenseId !== $licenseId && !$allowRebind) {
                return [
                    'ok' => false,
                    'error' => 'runtime_already_bound_to_other_license',
                    'bound_license_id' => $existingLicenseId,
                ];
            }
            $upStmt = $conn->prepare("
                UPDATE app_license_runtime_bindings
                SET license_id = ?, incoming_license_key = ?, domain = ?, notes = ?, is_active = 1
                WHERE id = ?
            ");
            $upStmt->bind_param('isssi', $licenseId, $incomingLicenseKey, $domain, $notes, $bindingId);
            $ok = $upStmt->execute();
            $upStmt->close();
            if (!$ok) {
                return ['ok' => false, 'error' => 'binding_update_failed'];
            }
        } else {
            $inStmt = $conn->prepare("
                INSERT INTO app_license_runtime_bindings
                (license_id, installation_id, fingerprint, incoming_license_key, domain, notes, is_active)
                VALUES (?, ?, ?, ?, ?, ?, 1)
            ");
            $inStmt->bind_param('isssss', $licenseId, $installationId, $fingerprint, $incomingLicenseKey, $domain, $notes);
            $ok = $inStmt->execute();
            $inStmt->close();
            if (!$ok) {
                return ['ok' => false, 'error' => 'binding_insert_failed'];
            }
        }

        if ($appUrl !== '') {
            $upRuntimeStmt = $conn->prepare("
                UPDATE app_license_runtime_log
                SET license_id = ?, app_url = ?
                WHERE installation_id = ? AND fingerprint = ?
            ");
            $upRuntimeStmt->bind_param('isss', $licenseId, $appUrl, $installationId, $fingerprint);
        } else {
            $upRuntimeStmt = $conn->prepare("
                UPDATE app_license_runtime_log
                SET license_id = ?
                WHERE installation_id = ? AND fingerprint = ?
            ");
            $upRuntimeStmt->bind_param('iss', $licenseId, $installationId, $fingerprint);
        }
        $upRuntimeStmt->execute();
        $upRuntimeStmt->close();

        app_license_installation_touch(
            $conn,
            $licenseId,
            $installationId,
            $fingerprint,
            $domain,
            $appUrl,
            [
                'source' => 'runtime_bind_identity',
                'incoming_license_key' => $incomingLicenseKey,
                'notes' => $notes,
            ]
        );

        if ($activateAfterLink) {
            $stmtActivate = $conn->prepare("
                UPDATE app_license_registry
                SET status = 'active', lock_reason = ''
                WHERE id = ?
            ");
            $stmtActivate->bind_param('i', $licenseId);
            $stmtActivate->execute();
            $stmtActivate->close();
        }

        app_license_alert_add(
            $conn,
            $licenseId,
            'runtime_linked',
            'info',
            'Runtime linked to subscription',
            'A runtime installation was linked to an existing subscription.',
            [
                'installation_id' => $installationId,
                'fingerprint' => $fingerprint,
                'domain' => $domain,
                'app_url' => $appUrl,
                'incoming_license_key' => $incomingLicenseKey,
                'linked_license_key' => (string)($licenseRow['license_key'] ?? ''),
            ]
        );

        return [
            'ok' => true,
            'error' => '',
            'installation_id' => $installationId,
            'fingerprint' => $fingerprint,
            'incoming_license_key' => $incomingLicenseKey,
            'linked_license_key' => (string)($licenseRow['license_key'] ?? ''),
            'client_name' => (string)($licenseRow['client_name'] ?? ''),
        ];
    }
}

if (!function_exists('app_license_runtime_bind_from_log')) {
    function app_license_runtime_bind_from_log(mysqli $conn, int $runtimeId, int $licenseId, string $notes = '', bool $activateAfterLink = false): array
    {
        app_initialize_license_management($conn);
        if (!app_is_super_user() || app_license_edition() !== 'owner') {
            return ['ok' => false, 'error' => 'super_user_required'];
        }
        if ($runtimeId <= 0 || $licenseId <= 0) {
            return ['ok' => false, 'error' => 'invalid_ids'];
        }

        $runtimeStmt = $conn->prepare("SELECT * FROM app_license_runtime_log WHERE id = ? LIMIT 1");
        $runtimeStmt->bind_param('i', $runtimeId);
        $runtimeStmt->execute();
        $runtimeRow = $runtimeStmt->get_result()->fetch_assoc() ?: [];
        $runtimeStmt->close();
        if (empty($runtimeRow)) {
            return ['ok' => false, 'error' => 'runtime_not_found'];
        }
        $bind = app_license_runtime_bind_identity_internal(
            $conn,
            $licenseId,
            (string)($runtimeRow['installation_id'] ?? ''),
            (string)($runtimeRow['fingerprint'] ?? ''),
            (string)($runtimeRow['license_key'] ?? ''),
            (string)($runtimeRow['domain'] ?? ''),
            $notes !== '' ? $notes : ('manual_runtime_link#' . $runtimeId),
            (string)($runtimeRow['app_url'] ?? ''),
            $activateAfterLink,
            true
        );
        if (empty($bind['ok'])) {
            return $bind;
        }
        return $bind;
    }
}

if (!function_exists('app_license_link_code_issue')) {
    function app_license_link_code_issue(
        mysqli $conn,
        int $runtimeId,
        int $licenseId,
        string $channel = 'generate',
        bool $autoActivate = true
    ): array {
        app_initialize_license_management($conn);
        if (!app_is_super_user() || app_license_edition() !== 'owner') {
            return ['ok' => false, 'error' => 'super_user_required'];
        }
        if ($runtimeId <= 0 || $licenseId <= 0) {
            return ['ok' => false, 'error' => 'invalid_ids'];
        }

        $runtimeStmt = $conn->prepare("SELECT * FROM app_license_runtime_log WHERE id = ? LIMIT 1");
        $runtimeStmt->bind_param('i', $runtimeId);
        $runtimeStmt->execute();
        $runtime = $runtimeStmt->get_result()->fetch_assoc() ?: [];
        $runtimeStmt->close();
        if (empty($runtime)) {
            return ['ok' => false, 'error' => 'runtime_not_found'];
        }
        $license = app_license_registry_get($conn, $licenseId);
        if (empty($license)) {
            return ['ok' => false, 'error' => 'license_not_found'];
        }

        $installationId = mb_substr(trim((string)($runtime['installation_id'] ?? '')), 0, 80);
        $fingerprint = mb_substr(trim((string)($runtime['fingerprint'] ?? '')), 0, 80);
        $domain = app_license_normalize_domain((string)($runtime['domain'] ?? ''));
        if ($installationId === '' || $fingerprint === '') {
            return ['ok' => false, 'error' => 'runtime_identity_missing'];
        }

        $code = app_license_make_link_code();
        $codeHash = app_license_link_code_hash($code);
        $expiresAt = date('Y-m-d H:i:s', time() + (20 * 60));
        $channel = mb_substr(trim($channel), 0, 40);
        $target = '';
        if ($channel === 'email_auto') {
            $target = mb_substr(trim((string)($license['client_email'] ?? '')), 0, 190);
        } elseif ($channel === 'whatsapp_open') {
            $target = mb_substr(trim((string)($license['client_phone'] ?? '')), 0, 190);
        }

        // Keep a single active code per installation to avoid operator confusion.
        $stmtDisable = $conn->prepare("
            UPDATE app_license_link_codes
            SET used_at = NOW(), used_domain = 'replaced'
            WHERE installation_id = ? AND fingerprint = ? AND used_at IS NULL
        ");
        $stmtDisable->bind_param('ss', $installationId, $fingerprint);
        $stmtDisable->execute();
        $stmtDisable->close();

        $stmtIn = $conn->prepare("
            INSERT INTO app_license_link_codes (
                license_id, runtime_id, installation_id, fingerprint, domain,
                code_hash, code_hint, auto_activate, sent_channel, sent_target,
                expires_at, metadata_json
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $codeHint = '******';
        $autoActivateInt = $autoActivate ? 1 : 0;
        $metadataJson = json_encode([
            'runtime_id' => $runtimeId,
            'issued_by_user' => (int)($_SESSION['user_id'] ?? 0),
            'incoming_license_key' => (string)($runtime['license_key'] ?? ''),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($metadataJson)) {
            $metadataJson = '{}';
        }
        $stmtIn->bind_param(
            'iisssssissss',
            $licenseId,
            $runtimeId,
            $installationId,
            $fingerprint,
            $domain,
            $codeHash,
            $codeHint,
            $autoActivateInt,
            $channel,
            $target,
            $expiresAt,
            $metadataJson
        );
        $ok = $stmtIn->execute();
        $linkCodeId = (int)$stmtIn->insert_id;
        $stmtIn->close();
        if (!$ok || $linkCodeId <= 0) {
            return ['ok' => false, 'error' => 'code_insert_failed'];
        }

        $clientName = trim((string)($license['client_name'] ?? ''));
        $appName = app_setting_get($conn, 'app_name', 'Arab Eagles');
        $message = app_tr(
            "رمز تأكيد ربط النظام: {$code}\nالعميل: {$clientName}\nينتهي الرمز: {$expiresAt}\nاستخدمه من صفحة ربط النظام داخل الإعدادات.",
            "System link confirmation code: {$code}\nClient: {$clientName}\nExpires: {$expiresAt}\nUse it in system link page inside settings."
        );
        $subject = app_tr('رمز تأكيد ربط النظام', 'System link confirmation code');

        $mailto = '';
        $clientEmail = trim((string)($license['client_email'] ?? ''));
        if (filter_var($clientEmail, FILTER_VALIDATE_EMAIL)) {
            $mailto = 'mailto:' . rawurlencode($clientEmail)
                . '?subject=' . rawurlencode($subject)
                . '&body=' . rawurlencode($message);
        }
        $waPhone = preg_replace('/[^0-9]+/', '', (string)($license['client_phone'] ?? ''));
        $waPhone = is_string($waPhone) ? $waPhone : '';
        $whatsapp = $waPhone !== ''
            ? ('https://wa.me/' . $waPhone . '?text=' . rawurlencode($message))
            : '';

        $emailSent = false;
        if ($channel === 'email_auto' && filter_var($clientEmail, FILTER_VALIDATE_EMAIL)) {
            $emailSent = app_send_email_basic($clientEmail, $subject, $message, ['from_name' => $appName]);
        }

        app_license_alert_add(
            $conn,
            $licenseId,
            'link_code_issued',
            'info',
            'Link confirmation code issued',
            'A six-digit link confirmation code was generated for client binding.',
            [
                'runtime_id' => $runtimeId,
                'installation_id' => $installationId,
                'fingerprint' => $fingerprint,
                'expires_at' => $expiresAt,
                'channel' => $channel,
            ]
        );

        return [
            'ok' => true,
            'error' => '',
            'link_code_id' => $linkCodeId,
            'runtime_id' => $runtimeId,
            'code' => $code,
            'expires_at' => $expiresAt,
            'license_id' => $licenseId,
            'license_key' => (string)($license['license_key'] ?? ''),
            'client_name' => $clientName,
            'message' => $message,
            'mailto' => $mailto,
            'whatsapp' => $whatsapp,
            'email_sent' => $emailSent,
            'auto_activate' => $autoActivateInt,
        ];
    }
}

if (!function_exists('app_license_link_code_consume')) {
    function app_license_link_code_consume(
        mysqli $conn,
        string $code,
        string $installationId,
        string $fingerprint,
        string $domain,
        string $appUrl = '',
        string $incomingLicenseKey = '',
        array $supportReport = []
    ): array {
        app_initialize_license_management($conn);
        if (app_license_edition() !== 'owner') {
            return ['ok' => false, 'error' => 'not_owner_edition'];
        }

        $code = preg_replace('/[^0-9]/', '', trim($code));
        $code = is_string($code) ? $code : '';
        if (strlen($code) !== 6) {
            return ['ok' => false, 'error' => 'invalid_code'];
        }
        $installationId = mb_substr(trim($installationId), 0, 80);
        $fingerprint = mb_substr(trim($fingerprint), 0, 80);
        $domain = app_license_normalize_domain($domain);
        $appUrl = mb_substr(trim($appUrl), 0, 255);
        $incomingLicenseKey = strtoupper(mb_substr(trim($incomingLicenseKey), 0, 180));
        if ($installationId === '' || $fingerprint === '') {
            return ['ok' => false, 'error' => 'installation_missing'];
        }

        $hash = app_license_link_code_hash($code);
        $now = date('Y-m-d H:i:s');
        $stmt = $conn->prepare("
            SELECT *
            FROM app_license_link_codes
            WHERE code_hash = ?
              AND installation_id = ?
              AND fingerprint = ?
              AND used_at IS NULL
              AND expires_at >= ?
            ORDER BY id DESC
            LIMIT 1
        ");
        $stmt->bind_param('ssss', $hash, $installationId, $fingerprint, $now);
        $stmt->execute();
        $codeRow = $stmt->get_result()->fetch_assoc() ?: [];
        $stmt->close();
        if (empty($codeRow)) {
            return ['ok' => false, 'error' => 'code_invalid_or_expired'];
        }

        $licenseId = (int)($codeRow['license_id'] ?? 0);
        if ($licenseId <= 0) {
            return ['ok' => false, 'error' => 'license_not_found'];
        }
        $autoActivate = (int)($codeRow['auto_activate'] ?? 0) === 1;

        $bind = app_license_runtime_bind_identity_internal(
            $conn,
            $licenseId,
            $installationId,
            $fingerprint,
            $incomingLicenseKey,
            $domain,
            'confirmed_link_code#' . (int)($codeRow['id'] ?? 0),
            $appUrl,
            $autoActivate
        );
        if (empty($bind['ok'])) {
            return $bind;
        }

        $usedDomain = $domain !== '' ? $domain : app_license_normalize_domain((string)parse_url($appUrl, PHP_URL_HOST));
        $stmtUse = $conn->prepare("
            UPDATE app_license_link_codes
            SET used_at = NOW(), used_domain = ?
            WHERE id = ?
        ");
        $codeId = (int)($codeRow['id'] ?? 0);
        $stmtUse->bind_param('si', $usedDomain, $codeId);
        $stmtUse->execute();
        $stmtUse->close();

        $license = app_license_registry_get($conn, $licenseId);
        $state = app_license_registry_effective_state($license);
        $assignedApiToken = trim((string)($license['api_token'] ?? ''));
        $primaryApiUrl = rtrim((string)app_base_url(), '/') . '/license_api.php';
        $altApiUrl = rtrim((string)app_base_url(), '/') . '/api/license/check/';

        if (!empty($supportReport)) {
            app_support_owner_store_client_report(
                $conn,
                $licenseId,
                (string)($license['license_key'] ?? ''),
                $usedDomain,
                $appUrl,
                $installationId,
                $fingerprint,
                $supportReport
            );
        }

        return [
            'ok' => true,
            'error' => '',
            'license_id' => $licenseId,
            'license_key' => (string)($license['license_key'] ?? ''),
            'api_token' => $assignedApiToken,
            'remote_url' => $primaryApiUrl,
            'remote_alt_url' => $altApiUrl,
            'status' => (string)($state['status'] ?? 'active'),
            'plan' => (string)($state['plan'] ?? 'trial'),
            'trial_ends_at' => (string)($state['trial_ends_at'] ?? ''),
            'subscription_ends_at' => (string)($state['subscription_ends_at'] ?? ''),
            'grace_days' => (int)($state['grace_days'] ?? 0),
            'owner_name' => (string)($license['client_name'] ?? ''),
            'auto_activated' => $autoActivate ? 1 : 0,
        ];
    }
}

if (!function_exists('app_support_api_system_snapshot')) {
    function app_support_api_system_snapshot(mysqli $conn, array $payload): array
    {
        if (app_license_edition() !== 'client') {
            return ['http_code' => 404, 'body' => ['ok' => false, 'error' => 'not_available']];
        }
        $check = app_support_api_validate_client_target($conn, $payload);
        if (empty($check['ok'])) {
            return [
                'http_code' => (int)($check['http_code'] ?? 403),
                'body' => ['ok' => false, 'error' => (string)($check['error'] ?? 'access_denied')],
            ];
        }

        $report = app_support_client_collect_system_report($conn, 600);
        $licenseRow = app_license_row($conn);
        $report['edition'] = app_license_edition();
        $report['installation_id'] = (string)($licenseRow['installation_id'] ?? '');
        $report['fingerprint'] = (string)($licenseRow['fingerprint'] ?? '');
        $report['license_key'] = (string)($licenseRow['license_key'] ?? '');
        $report['app_url'] = app_base_url();
        $report['domain'] = app_license_normalize_domain((string)parse_url(app_base_url(), PHP_URL_HOST));

        return [
            'http_code' => 200,
            'body' => [
                'ok' => true,
                'report' => $report,
            ],
        ];
    }
}

if (!function_exists('app_support_api_owner_credentials_push')) {
    function app_support_api_owner_credentials_push(mysqli $conn, array $payload): array
    {
        if (app_license_edition() !== 'client') {
            return ['http_code' => 404, 'body' => ['ok' => false, 'error' => 'not_available']];
        }
        app_initialize_license_data($conn);

        $installationId = trim((string)($payload['installation_id'] ?? ''));
        $fingerprint = trim((string)($payload['fingerprint'] ?? ''));
        $domain = app_license_normalize_domain((string)($payload['domain'] ?? ''));
        if ($installationId === '' || $fingerprint === '') {
            return ['http_code' => 422, 'body' => ['ok' => false, 'error' => 'missing_required_fields']];
        }

        $row = app_license_row($conn);
        $localInstallationId = trim((string)($row['installation_id'] ?? ''));
        $localFingerprint = trim((string)($row['fingerprint'] ?? ''));
        if ($localInstallationId !== '' && $installationId !== $localInstallationId) {
            return ['http_code' => 403, 'body' => ['ok' => false, 'error' => 'installation_mismatch']];
        }
        if ($localFingerprint !== '' && $fingerprint !== $localFingerprint) {
            return ['http_code' => 403, 'body' => ['ok' => false, 'error' => 'fingerprint_mismatch']];
        }
        if ($domain !== '') {
            $localDomain = app_license_normalize_domain((string)parse_url(app_base_url(), PHP_URL_HOST));
            if ($localDomain !== '' && $localDomain !== $domain) {
                return ['http_code' => 403, 'body' => ['ok' => false, 'error' => 'domain_mismatch']];
            }
        }

        $credentials = (isset($payload['credentials']) && is_array($payload['credentials'])) ? $payload['credentials'] : [];
        $incomingKey = strtoupper(trim((string)($credentials['license_key'] ?? $payload['license_key'] ?? '')));
        $incomingRemoteUrl = trim((string)($credentials['remote_url'] ?? ''));
        $incomingRemoteToken = trim((string)($credentials['remote_token'] ?? ''));

        if ($incomingRemoteUrl !== '') {
            $scheme = strtolower((string)parse_url($incomingRemoteUrl, PHP_URL_SCHEME));
            if (!filter_var($incomingRemoteUrl, FILTER_VALIDATE_URL) || !in_array($scheme, ['http', 'https'], true)) {
                return ['http_code' => 422, 'body' => ['ok' => false, 'error' => 'invalid_remote_url']];
            }
            $incomingRemoteUrl = mb_substr($incomingRemoteUrl, 0, 255);
        }
        if ($incomingRemoteToken !== '') {
            $incomingRemoteToken = mb_substr($incomingRemoteToken, 0, 190);
        }
        if ($incomingKey !== '') {
            $incomingKey = mb_substr($incomingKey, 0, 180);
        }

        $envRemote = app_license_env_remote();
        $envKey = app_license_env_key();
        $remoteLocked = app_license_remote_lock_mode();

        $targetRemoteUrl = trim((string)($row['remote_url'] ?? ''));
        $targetRemoteToken = trim((string)($row['remote_token'] ?? ''));
        $targetKey = strtoupper(trim((string)($row['license_key'] ?? '')));

        if (($envRemote['url'] === '' || !$remoteLocked) && $incomingRemoteUrl !== '') {
            $targetRemoteUrl = $incomingRemoteUrl;
        }
        if (($envRemote['token'] === '' || !$remoteLocked) && $incomingRemoteToken !== '') {
            $targetRemoteToken = $incomingRemoteToken;
        }
        if (($envKey === '' || !$remoteLocked) && $incomingKey !== '') {
            $targetKey = $incomingKey;
        }

        $stmt = $conn->prepare("
            UPDATE app_license_state
            SET remote_url = ?, remote_token = ?, license_key = ?, last_error = ''
            WHERE id = 1
        ");
        $stmt->bind_param('sss', $targetRemoteUrl, $targetRemoteToken, $targetKey);
        $stmt->execute();
        $stmt->close();

        // Persist incoming credentials locally (desktop/cloud client) to avoid stale startup values.
        $persistPrep = app_license_client_persist_runtime_env(app_license_row($conn));

        $sync = app_license_sync_remote($conn, true);
        $cloudSync = app_cloud_sync_run($conn, true);
        $status = app_license_status($conn, false);

        return [
            'http_code' => 200,
            'body' => [
                'ok' => true,
                'applied' => true,
                'license_key' => $targetKey,
                'remote_url' => $targetRemoteUrl,
                'remote_token' => $targetRemoteToken,
                'sync' => $sync,
                'cloud_sync' => $cloudSync,
                'persist_env' => $persistPrep,
                'license' => [
                    'status' => (string)($status['status'] ?? ''),
                    'plan' => (string)($status['plan'] ?? ''),
                    'allowed' => !empty($status['allowed']) ? 1 : 0,
                    'expires_at' => (string)($status['expires_at'] ?? ''),
                    'last_error' => (string)($status['last_error'] ?? ''),
                ],
            ],
        ];
    }
}

if (!function_exists('app_license_api_event_link_confirm')) {
    function app_license_api_event_link_confirm(mysqli $conn, array $payload): array
    {
        if (app_license_edition() !== 'owner') {
            return ['http_code' => 404, 'body' => ['ok' => false, 'error' => 'not_available']];
        }

        $code = trim((string)($payload['code'] ?? ''));
        $installationId = trim((string)($payload['installation_id'] ?? ''));
        $fingerprint = trim((string)($payload['fingerprint'] ?? ''));
        $domain = app_license_normalize_domain((string)($payload['domain'] ?? ''));
        $appUrl = trim((string)($payload['app_url'] ?? ''));
        $incomingLicenseKey = strtoupper(trim((string)($payload['license_key'] ?? '')));
        $report = (isset($payload['support_report']) && is_array($payload['support_report'])) ? $payload['support_report'] : [];

        $consume = app_license_link_code_consume(
            $conn,
            $code,
            $installationId,
            $fingerprint,
            $domain,
            $appUrl,
            $incomingLicenseKey,
            $report
        );
        if (empty($consume['ok'])) {
            $error = (string)($consume['error'] ?? 'confirm_failed');
            $http = in_array($error, ['invalid_code', 'code_invalid_or_expired', 'installation_missing'], true) ? 422 : 403;
            return ['http_code' => $http, 'body' => ['ok' => false, 'error' => $error]];
        }

        $licenseNode = [
            'status' => (string)($consume['status'] ?? 'active'),
            'plan' => (string)($consume['plan'] ?? 'trial'),
            'owner_name' => (string)($consume['owner_name'] ?? ''),
            'trial_ends_at' => (string)($consume['trial_ends_at'] ?? ''),
            'subscription_ends_at' => (string)($consume['subscription_ends_at'] ?? ''),
            'grace_days' => (int)($consume['grace_days'] ?? 0),
            'assigned_license_key' => (string)($consume['license_key'] ?? ''),
            'assigned_api_token' => (string)($consume['api_token'] ?? ''),
            'assigned_remote_url' => (string)($consume['remote_url'] ?? ''),
            'remote_alt_url' => (string)($consume['remote_alt_url'] ?? ''),
        ];

        return [
            'http_code' => 200,
            'body' => [
                'ok' => true,
                'license' => $licenseNode,
            ],
        ];
    }
}

if (!function_exists('app_license_api_event_auto_bootstrap')) {
    function app_license_api_event_auto_bootstrap(mysqli $conn, array $payload, string $bearerToken = ''): array
    {
        if (app_license_edition() !== 'owner') {
            return ['http_code' => 404, 'body' => ['ok' => false, 'error' => 'not_available']];
        }
        if (!app_license_api_bearer_allowed($conn, $payload, $bearerToken)) {
            app_license_api_log_unauthorized_attempt($conn, $payload, $bearerToken);
            return ['http_code' => 401, 'body' => ['ok' => false, 'error' => 'unauthorized']];
        }

        app_initialize_license_management($conn);

        $installationId = mb_substr(trim((string)($payload['installation_id'] ?? '')), 0, 80);
        $fingerprint = mb_substr(trim((string)($payload['fingerprint'] ?? '')), 0, 80);
        $appUrl = mb_substr(trim((string)($payload['app_url'] ?? '')), 0, 255);
        $domain = app_license_normalize_domain((string)($payload['domain'] ?? ''));
        $incomingLicenseKey = strtoupper(trim((string)($payload['license_key'] ?? '')));
        $preferredLicenseKey = strtoupper(trim((string)($payload['preferred_license_key'] ?? $incomingLicenseKey)));
        $reportNode = (isset($payload['support_report']) && is_array($payload['support_report'])) ? $payload['support_report'] : [];

        if ($installationId === '' || $fingerprint === '') {
            return ['http_code' => 422, 'body' => ['ok' => false, 'error' => 'installation_missing']];
        }
        if ($domain === '' && $appUrl !== '') {
            $domain = app_license_normalize_domain((string)parse_url($appUrl, PHP_URL_HOST));
        }

        $blocked = app_license_blocked_runtime_match($conn, $domain, $appUrl, $installationId, $fingerprint, $preferredLicenseKey);
        if (empty($blocked) && $incomingLicenseKey !== '' && $incomingLicenseKey !== $preferredLicenseKey) {
            $blocked = app_license_blocked_runtime_match($conn, $domain, $appUrl, $installationId, $fingerprint, $incomingLicenseKey);
        }
        if (!empty($blocked)) {
            app_license_runtime_log_add(
                $conn,
                0,
                $incomingLicenseKey !== '' ? $incomingLicenseKey : $preferredLicenseKey,
                $domain,
                $installationId,
                $fingerprint,
                'blocked',
                'blocked',
                $appUrl
            );
            return ['http_code' => 200, 'body' => ['ok' => false, 'error' => 'blocked_client']];
        }

        $binding = app_license_runtime_binding_find($conn, $installationId, $fingerprint);
        $license = [];
        if (!empty($binding)) {
            $mappedLicenseId = (int)($binding['license_id'] ?? 0);
            if ($mappedLicenseId > 0) {
                $license = app_license_registry_get($conn, $mappedLicenseId);
            }
        }

        if (empty($license)) {
            app_license_runtime_log_add(
                $conn,
                0,
                $incomingLicenseKey !== '' ? $incomingLicenseKey : $preferredLicenseKey,
                $domain,
                $installationId,
                $fingerprint,
                'pending_activation',
                'pending',
                $appUrl
            );

            return [
                'http_code' => 200,
                'body' => [
                    'ok' => true,
                    'license' => [
                        'status' => 'suspended',
                        'plan' => 'pending',
                        'owner_name' => '',
                        'trial_ends_at' => null,
                        'subscription_ends_at' => null,
                        'grace_days' => 0,
                        'assigned_license_key' => null,
                        'assigned_api_token' => null,
                        'assigned_remote_url' => null,
                    ],
                    'server' => [
                        'mode' => 'owner_manual_activation_required',
                        'timestamp' => date('c'),
                    ],
                    'error' => 'awaiting_owner_activation',
                ],
            ];
        }

        $licenseId = (int)($license['id'] ?? 0);

        if (!empty($reportNode)) {
            app_support_owner_store_client_report(
                $conn,
                $licenseId,
                (string)($license['license_key'] ?? ''),
                $domain,
                $appUrl,
                $installationId,
                $fingerprint,
                $reportNode
            );
        }

        $state = app_license_registry_effective_state($license);
        $assignedRemoteUrl = rtrim((string)app_base_url(), '/') . '/license_api.php';
        $assignedApiToken = trim((string)($license['api_token'] ?? ''));
        $assignedLicenseKey = strtoupper(trim((string)($license['license_key'] ?? '')));

        app_license_runtime_log_add(
            $conn,
            $licenseId,
            $assignedLicenseKey !== '' ? $assignedLicenseKey : $incomingLicenseKey,
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
                    'assigned_license_key' => $assignedLicenseKey,
                    'assigned_api_token' => $assignedApiToken !== '' ? $assignedApiToken : null,
                    'assigned_remote_url' => $assignedRemoteUrl,
                ],
                'server' => [
                    'mode' => 'owner_manual_activation',
                    'timestamp' => date('c'),
                ],
            ],
        ];
    }
}

if (!function_exists('app_license_runtime_recent')) {
    function app_license_runtime_recent(mysqli $conn, int $limit = 120): array
    {
        app_initialize_license_management($conn);
        $limit = max(1, min(500, $limit));
        $stmt = $conn->prepare("
            SELECT l.*, r.client_name,
                   b.license_id AS linked_license_id,
                   b.incoming_license_key AS linked_incoming_license_key,
                   b.notes AS linked_notes,
                   b.updated_at AS linked_updated_at
            FROM app_license_runtime_log l
            LEFT JOIN app_license_registry r ON r.id = l.license_id
            LEFT JOIN app_license_runtime_bindings b
              ON b.installation_id = l.installation_id
             AND b.fingerprint = l.fingerprint
             AND b.is_active = 1
            ORDER BY l.id DESC
            LIMIT ?
        ");
        $stmt->bind_param('i', $limit);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
        $stmt->close();
        return is_array($rows) ? $rows : [];
    }
}

if (!function_exists('app_license_runtime_delete')) {
    function app_license_runtime_delete(mysqli $conn, int $runtimeId): bool
    {
        app_initialize_license_management($conn);
        if (!app_is_super_user() || app_license_edition() !== 'owner' || $runtimeId <= 0) {
            return false;
        }
        $stmt = $conn->prepare("DELETE FROM app_license_runtime_log WHERE id = ?");
        $stmt->bind_param('i', $runtimeId);
        $ok = $stmt->execute();
        $stmt->close();
        return (bool)$ok;
    }
}

if (!function_exists('app_license_runtime_delete_all')) {
    function app_license_runtime_delete_all(mysqli $conn): int
    {
        app_initialize_license_management($conn);
        if (!app_is_super_user() || app_license_edition() !== 'owner') {
            return 0;
        }
        $conn->query("DELETE FROM app_license_runtime_log");
        return (int)$conn->affected_rows;
    }
}

if (!function_exists('app_license_blocked_runtime_match')) {
    function app_license_blocked_runtime_match(mysqli $conn, string $domain = '', string $appUrl = '', string $installationId = '', string $fingerprint = '', string $licenseKey = ''): array
    {
        app_initialize_license_management($conn);
        $domain = app_license_normalize_domain($domain);
        $licenseKey = strtoupper(trim($licenseKey));
        $installationId = trim($installationId);
        $fingerprint = trim($fingerprint);
        if ($domain === '' && $installationId === '' && $fingerprint === '' && $licenseKey === '') {
            return [];
        }
        $stmt = $conn->prepare("
            SELECT *
            FROM app_license_blocked_clients
            WHERE is_active = 1
              AND (
                    (installation_id <> '' AND installation_id = ? AND fingerprint = ?)
                 OR (domain <> '' AND domain = ?)
                 OR (license_key <> '' AND license_key = ?)
              )
            ORDER BY id DESC
            LIMIT 1
        ");
        $stmt->bind_param('ssss', $installationId, $fingerprint, $domain, $licenseKey);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: [];
        $stmt->close();
        return is_array($row) ? $row : [];
    }
}

if (!function_exists('app_license_blocked_runtime_add')) {
    function app_license_blocked_runtime_add(mysqli $conn, array $payload): array
    {
        app_initialize_license_management($conn);
        if (!app_is_super_user() || app_license_edition() !== 'owner') {
            return ['ok' => false, 'error' => 'forbidden'];
        }
        $domain = app_license_normalize_domain((string)($payload['domain'] ?? ''));
        $appUrl = trim((string)($payload['app_url'] ?? ''));
        $installationId = mb_substr(trim((string)($payload['installation_id'] ?? '')), 0, 80);
        $fingerprint = mb_substr(trim((string)($payload['fingerprint'] ?? '')), 0, 80);
        $licenseKey = strtoupper(trim((string)($payload['license_key'] ?? '')));
        $reason = trim((string)($payload['reason'] ?? 'Blocked by owner'));
        $notes = trim((string)($payload['notes'] ?? ''));
        if ($domain === '' && $installationId === '' && $fingerprint === '' && $licenseKey === '') {
            return ['ok' => false, 'error' => 'identity_missing'];
        }
        $existing = app_license_blocked_runtime_match($conn, $domain, $appUrl, $installationId, $fingerprint, $licenseKey);
        if (!empty($existing)) {
            return ['ok' => true, 'id' => (int)($existing['id'] ?? 0), 'already' => true];
        }
        $blockedBy = (int)($_SESSION['user_id'] ?? 0);
        $stmt = $conn->prepare("
            INSERT INTO app_license_blocked_clients
                (domain, app_url, installation_id, fingerprint, license_key, reason, notes, blocked_by_user_id)
            VALUES
                (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param('sssssssi', $domain, $appUrl, $installationId, $fingerprint, $licenseKey, $reason, $notes, $blockedBy);
        $ok = $stmt->execute();
        $newId = $ok ? (int)$stmt->insert_id : 0;
        $stmt->close();
        return $ok ? ['ok' => true, 'id' => $newId] : ['ok' => false, 'error' => 'insert_failed'];
    }
}

if (!function_exists('app_license_blocked_runtime_release')) {
    function app_license_blocked_runtime_release(mysqli $conn, int $blockId): bool
    {
        app_initialize_license_management($conn);
        if (!app_is_super_user() || app_license_edition() !== 'owner' || $blockId <= 0) {
            return false;
        }
        $stmt = $conn->prepare("UPDATE app_license_blocked_clients SET is_active = 0, released_at = NOW() WHERE id = ? AND is_active = 1");
        $stmt->bind_param('i', $blockId);
        $ok = $stmt->execute();
        $stmt->close();
        return (bool)$ok;
    }
}

if (!function_exists('app_license_blocked_runtime_list')) {
    function app_license_blocked_runtime_list(mysqli $conn, bool $activeOnly = true, int $limit = 200): array
    {
        app_initialize_license_management($conn);
        $limit = max(1, min(500, $limit));
        $where = $activeOnly ? 'WHERE is_active = 1' : '';
        $stmt = $conn->prepare("SELECT * FROM app_license_blocked_clients {$where} ORDER BY id DESC LIMIT ?");
        $stmt->bind_param('i', $limit);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
        $stmt->close();
        return is_array($rows) ? $rows : [];
    }
}

if (!function_exists('app_license_runtime_block_from_log')) {
    function app_license_runtime_block_from_log(mysqli $conn, int $runtimeId, string $reason = 'Blocked by owner'): array
    {
        app_initialize_license_management($conn);
        if (!app_is_super_user() || app_license_edition() !== 'owner' || $runtimeId <= 0) {
            return ['ok' => false, 'error' => 'forbidden'];
        }
        $stmt = $conn->prepare("SELECT * FROM app_license_runtime_log WHERE id = ? LIMIT 1");
        $stmt->bind_param('i', $runtimeId);
        $stmt->execute();
        $runtime = $stmt->get_result()->fetch_assoc() ?: [];
        $stmt->close();
        if (empty($runtime)) {
            return ['ok' => false, 'error' => 'runtime_missing'];
        }
        $installationId = trim((string)($runtime['installation_id'] ?? ''));
        $fingerprint = trim((string)($runtime['fingerprint'] ?? ''));
        $domain = app_license_normalize_domain((string)($runtime['domain'] ?? ''));
        $appUrl = trim((string)($runtime['app_url'] ?? ''));
        $licenseKey = strtoupper(trim((string)($runtime['license_key'] ?? '')));
        $licenseId = (int)($runtime['license_id'] ?? 0);

        $conn->begin_transaction();
        try {
            $block = app_license_blocked_runtime_add($conn, [
                'domain' => $domain,
                'app_url' => $appUrl,
                'installation_id' => $installationId,
                'fingerprint' => $fingerprint,
                'license_key' => $licenseKey,
                'reason' => $reason,
                'notes' => 'Created from runtime #' . $runtimeId,
            ]);
            if (empty($block['ok'])) {
                throw new RuntimeException((string)($block['error'] ?? 'block_create_failed'));
            }

            if ($installationId !== '' && $fingerprint !== '') {
                $stmtDelBind = $conn->prepare("DELETE FROM app_license_runtime_bindings WHERE installation_id = ? AND fingerprint = ?");
                $stmtDelBind->bind_param('ss', $installationId, $fingerprint);
                $stmtDelBind->execute();
                $stmtDelBind->close();

                $stmtDelCodes = $conn->prepare("DELETE FROM app_license_link_codes WHERE installation_id = ? AND fingerprint = ?");
                $stmtDelCodes->bind_param('ss', $installationId, $fingerprint);
                $stmtDelCodes->execute();
                $stmtDelCodes->close();

                $stmtDelLogs = $conn->prepare("DELETE FROM app_license_runtime_log WHERE installation_id = ? AND fingerprint = ?");
                $stmtDelLogs->bind_param('ss', $installationId, $fingerprint);
                $stmtDelLogs->execute();
                $stmtDelLogs->close();

                if ($licenseId > 0) {
                    $stmtDelInstall = $conn->prepare("DELETE FROM app_license_installations WHERE license_id = ? AND installation_id = ? AND fingerprint = ?");
                    $stmtDelInstall->bind_param('iss', $licenseId, $installationId, $fingerprint);
                    $stmtDelInstall->execute();
                    $stmtDelInstall->close();
                }
            } else {
                $stmtDelLogs = $conn->prepare("DELETE FROM app_license_runtime_log WHERE id = ?");
                $stmtDelLogs->bind_param('i', $runtimeId);
                $stmtDelLogs->execute();
                $stmtDelLogs->close();
            }

            if ($licenseId > 0) {
                app_license_alert_add(
                    $conn,
                    $licenseId,
                    'blocked_runtime',
                    'warning',
                    'Runtime blocked by owner',
                    'A client runtime was blocked and its linking codes were destroyed.',
                    ['domain' => $domain, 'installation_id' => $installationId, 'fingerprint' => $fingerprint, 'license_key' => $licenseKey]
                );
            }
            if ($licenseKey !== '' && function_exists('app_desktop_nodes_set_status_by_license')) {
                app_desktop_nodes_set_status_by_license($conn, $licenseKey, 'revoked', 1, 0);
            }

            $conn->commit();
            return ['ok' => true, 'block_id' => (int)($block['id'] ?? 0)];
        } catch (Throwable $e) {
            $conn->rollback();
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }
}

if (!function_exists('app_license_installation_touch')) {
    function app_license_installation_touch(mysqli $conn, int $licenseId, string $installationId, string $fingerprint, string $domain, string $appUrl, array $meta = []): array
    {
        app_initialize_license_management($conn);
        $installationId = trim($installationId);
        $fingerprint = trim($fingerprint);
        $domain = app_license_normalize_domain($domain);
        $appUrl = trim($appUrl);
        $now = date('Y-m-d H:i:s');
        $ip = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
        $metaJson = json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($metaJson)) {
            $metaJson = '{}';
        }

        $stmt = $conn->prepare("
            SELECT id
            FROM app_license_installations
            WHERE license_id = ? AND installation_id = ? AND fingerprint = ?
            LIMIT 1
        ");
        $stmt->bind_param('iss', $licenseId, $installationId, $fingerprint);
        $stmt->execute();
        $found = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($found) {
            $id = (int)$found['id'];
            $stmtUp = $conn->prepare("
                UPDATE app_license_installations
                SET domain = ?, app_url = ?, last_seen_at = ?, last_ip = ?, metadata_json = ?
                WHERE id = ?
            ");
            $stmtUp->bind_param('sssssi', $domain, $appUrl, $now, $ip, $metaJson, $id);
            $stmtUp->execute();
            $stmtUp->close();
            return ['is_new' => false, 'id' => $id];
        }

        $stmtIn = $conn->prepare("
            INSERT INTO app_license_installations
            (license_id, installation_id, fingerprint, domain, app_url, first_seen_at, last_seen_at, last_ip, metadata_json)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmtIn->bind_param('issssssss', $licenseId, $installationId, $fingerprint, $domain, $appUrl, $now, $now, $ip, $metaJson);
        $stmtIn->execute();
        $id = (int)$stmtIn->insert_id;
        $stmtIn->close();
        return ['is_new' => true, 'id' => $id];
    }
}

if (!function_exists('app_license_installation_count')) {
    function app_license_installation_count(mysqli $conn, int $licenseId): int
    {
        app_initialize_license_management($conn);
        $stmt = $conn->prepare("SELECT COUNT(*) AS c FROM app_license_installations WHERE license_id = ?");
        $stmt->bind_param('i', $licenseId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return (int)($row['c'] ?? 0);
    }
}

if (!function_exists('app_support_client_collect_system_report')) {
    function app_support_client_collect_system_report(mysqli $conn, int $limitUsers = 250): array
    {
        $limitUsers = max(1, min(600, $limitUsers));
        $users = [];
        $adminsCount = 0;
        try {
            $stmt = $conn->prepare("
                SELECT id, username, full_name, role, email, phone
                FROM users
                ORDER BY id ASC
                LIMIT ?
            ");
            $stmt->bind_param('i', $limitUsers);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($res && ($row = $res->fetch_assoc())) {
                $role = strtolower(trim((string)($row['role'] ?? '')));
                if ($role === 'admin') {
                    $adminsCount++;
                }
                $users[] = [
                    'id' => (int)($row['id'] ?? 0),
                    'username' => mb_substr(trim((string)($row['username'] ?? '')), 0, 120),
                    'full_name' => mb_substr(trim((string)($row['full_name'] ?? '')), 0, 190),
                    'role' => mb_substr($role, 0, 80),
                    'email' => mb_substr(trim((string)($row['email'] ?? '')), 0, 190),
                    'phone' => mb_substr(trim((string)($row['phone'] ?? '')), 0, 80),
                ];
            }
            $stmt->close();
        } catch (Throwable $e) {
            error_log('app_support_client_collect_system_report failed: ' . $e->getMessage());
        }

        return [
            'generated_at' => date('Y-m-d H:i:s'),
            'app_name' => mb_substr(app_setting_get($conn, 'app_name', 'Arab Eagles'), 0, 190),
            'users_count' => count($users),
            'admins_count' => $adminsCount,
            'users' => $users,
        ];
    }
}

if (!function_exists('app_support_owner_store_client_report')) {
    function app_support_owner_store_client_report(
        mysqli $conn,
        int $licenseId,
        string $licenseKey,
        string $domain,
        string $appUrl,
        string $installationId,
        string $fingerprint,
        array $report
    ): bool {
        if (app_license_edition() !== 'owner') {
            return false;
        }
        app_initialize_license_management($conn);

        $licenseKey = strtoupper(trim($licenseKey));
        $domain = app_license_normalize_domain($domain);
        $appUrl = mb_substr(trim($appUrl), 0, 255);
        $installationId = mb_substr(trim($installationId), 0, 80);
        $fingerprint = mb_substr(trim($fingerprint), 0, 80);
        if ($licenseKey === '' || $domain === '' || $installationId === '' || $fingerprint === '') {
            return false;
        }

        $generatedAt = trim((string)($report['generated_at'] ?? ''));
        if ($generatedAt === '' || strtotime($generatedAt) === false) {
            $generatedAt = date('Y-m-d H:i:s');
        }
        $now = date('Y-m-d H:i:s');
        $appName = mb_substr(trim((string)($report['app_name'] ?? '')), 0, 190);
        $usersNode = (isset($report['users']) && is_array($report['users'])) ? $report['users'] : [];
        $usersCount = max(0, min(5000, (int)($report['users_count'] ?? count($usersNode))));
        $adminsCount = max(0, min(1000, (int)($report['admins_count'] ?? 0)));

        $licenseRow = $licenseId > 0 ? app_license_registry_get($conn, $licenseId) : [];
        $clientName = mb_substr(trim((string)($licenseRow['client_name'] ?? '')), 0, 190);

        $payloadJson = json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($payloadJson)) {
            $payloadJson = '{}';
        }

        $reportId = 0;
        $stmtFind = $conn->prepare("
            SELECT id
            FROM app_support_client_reports
            WHERE license_key = ? AND installation_id = ? AND fingerprint = ?
            LIMIT 1
        ");
        $stmtFind->bind_param('sss', $licenseKey, $installationId, $fingerprint);
        $stmtFind->execute();
        $found = $stmtFind->get_result()->fetch_assoc() ?: [];
        $stmtFind->close();
        if (!empty($found)) {
            $reportId = (int)($found['id'] ?? 0);
        }

        if ($reportId > 0) {
            $stmtUp = $conn->prepare("
                UPDATE app_support_client_reports
                SET license_id = ?, client_name = ?, domain = ?, app_url = ?, app_name = ?,
                    users_count = ?, admins_count = ?, generated_at = ?, payload_json = ?, last_report_at = ?
                WHERE id = ?
            ");
            $stmtUp->bind_param(
                'issssiisssi',
                $licenseId,
                $clientName,
                $domain,
                $appUrl,
                $appName,
                $usersCount,
                $adminsCount,
                $generatedAt,
                $payloadJson,
                $now,
                $reportId
            );
            $stmtUp->execute();
            $stmtUp->close();
        } else {
            $stmtIn = $conn->prepare("
                INSERT INTO app_support_client_reports (
                    license_id, license_key, client_name, domain, app_url, installation_id, fingerprint,
                    app_name, users_count, admins_count, generated_at, payload_json, last_report_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmtIn->bind_param(
                'isssssssiisss',
                $licenseId,
                $licenseKey,
                $clientName,
                $domain,
                $appUrl,
                $installationId,
                $fingerprint,
                $appName,
                $usersCount,
                $adminsCount,
                $generatedAt,
                $payloadJson,
                $now
            );
            $stmtIn->execute();
            $reportId = (int)$stmtIn->insert_id;
            $stmtIn->close();
        }

        if ($reportId <= 0) {
            return false;
        }

        $stmtDel = $conn->prepare("DELETE FROM app_support_client_report_users WHERE report_id = ?");
        $stmtDel->bind_param('i', $reportId);
        $stmtDel->execute();
        $stmtDel->close();

        $stmtUser = $conn->prepare("
            INSERT INTO app_support_client_report_users (
                report_id, remote_user_id, username, full_name, role, email, phone, raw_json, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        if (!$stmtUser) {
            return true;
        }
        $countInserted = 0;
        foreach ($usersNode as $userNode) {
            if (!is_array($userNode)) {
                continue;
            }
            if ($countInserted >= 1000) {
                break;
            }
            $remoteUserId = (int)($userNode['id'] ?? 0);
            $username = mb_substr(trim((string)($userNode['username'] ?? '')), 0, 120);
            $fullName = mb_substr(trim((string)($userNode['full_name'] ?? '')), 0, 190);
            $role = mb_substr(trim((string)($userNode['role'] ?? '')), 0, 80);
            $email = mb_substr(trim((string)($userNode['email'] ?? '')), 0, 190);
            $phone = mb_substr(trim((string)($userNode['phone'] ?? '')), 0, 80);
            if ($username === '' && $fullName === '' && $email === '' && $phone === '' && $remoteUserId <= 0) {
                continue;
            }
            $rawJson = json_encode($userNode, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (!is_string($rawJson)) {
                $rawJson = '{}';
            }
            $stmtUser->bind_param('iisssssss', $reportId, $remoteUserId, $username, $fullName, $role, $email, $phone, $rawJson, $now);
            $stmtUser->execute();
            $countInserted++;
        }
        $stmtUser->close();
        return true;
    }
}

if (!function_exists('app_support_owner_reports_list')) {
    function app_support_owner_reports_list(mysqli $conn, int $limit = 300): array
    {
        app_initialize_license_management($conn);
        $limit = max(1, min(1000, $limit));
        $stmt = $conn->prepare("
            SELECT r.*,
                   lr.client_email AS license_email,
                   lr.client_phone AS license_phone
            FROM app_support_client_reports r
            LEFT JOIN app_license_registry lr ON lr.id = r.license_id
            ORDER BY r.last_report_at DESC, r.id DESC
            LIMIT ?
        ");
        $stmt->bind_param('i', $limit);
        $stmt->execute();
        $rows = [];
        $res = $stmt->get_result();
        while ($res && ($row = $res->fetch_assoc())) {
            $rows[] = $row;
        }
        $stmt->close();
        return $rows;
    }
}

if (!function_exists('app_support_owner_report_get')) {
    function app_support_owner_report_get(mysqli $conn, int $reportId): array
    {
        app_initialize_license_management($conn);
        if ($reportId <= 0) {
            return [];
        }
        $stmt = $conn->prepare("
            SELECT r.*,
                   lr.client_email AS license_email,
                   lr.client_phone AS license_phone,
                   (
                       SELECT rl.app_url
                       FROM app_license_runtime_log rl
                       WHERE rl.license_id = r.license_id
                       ORDER BY rl.checked_at DESC, rl.id DESC
                       LIMIT 1
                   ) AS runtime_app_url,
                   (
                       SELECT rl.domain
                       FROM app_license_runtime_log rl
                       WHERE rl.license_id = r.license_id
                       ORDER BY rl.checked_at DESC, rl.id DESC
                       LIMIT 1
                   ) AS runtime_domain,
                   (
                       SELECT rl.installation_id
                       FROM app_license_runtime_log rl
                       WHERE rl.license_id = r.license_id
                       ORDER BY rl.checked_at DESC, rl.id DESC
                       LIMIT 1
                   ) AS runtime_installation_id,
                   (
                       SELECT rl.fingerprint
                       FROM app_license_runtime_log rl
                       WHERE rl.license_id = r.license_id
                       ORDER BY rl.checked_at DESC, rl.id DESC
                       LIMIT 1
                   ) AS runtime_fingerprint,
                   (
                       SELECT ai.app_url
                       FROM app_license_installations ai
                       WHERE ai.license_id = r.license_id
                       ORDER BY ai.last_seen_at DESC, ai.id DESC
                       LIMIT 1
                   ) AS install_app_url,
                   (
                       SELECT ai.domain
                       FROM app_license_installations ai
                       WHERE ai.license_id = r.license_id
                       ORDER BY ai.last_seen_at DESC, ai.id DESC
                       LIMIT 1
                   ) AS install_domain,
                   (
                       SELECT ai.installation_id
                       FROM app_license_installations ai
                       WHERE ai.license_id = r.license_id
                       ORDER BY ai.last_seen_at DESC, ai.id DESC
                       LIMIT 1
                   ) AS install_installation_id,
                   (
                       SELECT ai.fingerprint
                       FROM app_license_installations ai
                       WHERE ai.license_id = r.license_id
                       ORDER BY ai.last_seen_at DESC, ai.id DESC
                       LIMIT 1
                   ) AS install_fingerprint
            FROM app_support_client_reports r
            LEFT JOIN app_license_registry lr ON lr.id = r.license_id
            WHERE r.id = ?
            LIMIT 1
        ");
        $stmt->bind_param('i', $reportId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: [];
        $stmt->close();
        return $row;
    }
}

if (!function_exists('app_support_owner_report_live_identity')) {
    function app_support_owner_report_live_identity(array $report): array
    {
        $installationId = trim((string)($report['runtime_installation_id'] ?? ''));
        if ($installationId === '') {
            $installationId = trim((string)($report['install_installation_id'] ?? ''));
        }
        if ($installationId === '') {
            $installationId = trim((string)($report['installation_id'] ?? ''));
        }

        $fingerprint = trim((string)($report['runtime_fingerprint'] ?? ''));
        if ($fingerprint === '') {
            $fingerprint = trim((string)($report['install_fingerprint'] ?? ''));
        }
        if ($fingerprint === '') {
            $fingerprint = trim((string)($report['fingerprint'] ?? ''));
        }

        $appUrl = trim((string)($report['runtime_app_url'] ?? ''));
        if ($appUrl === '') {
            $appUrl = trim((string)($report['install_app_url'] ?? ''));
        }
        if ($appUrl === '') {
            $appUrl = trim((string)($report['app_url'] ?? ''));
        }

        $domain = app_license_normalize_domain((string)($report['runtime_domain'] ?? ''));
        if ($domain === '') {
            $domain = app_license_normalize_domain((string)($report['install_domain'] ?? ''));
        }
        if ($domain === '') {
            $domain = app_license_normalize_domain((string)($report['domain'] ?? ''));
        }

        $remoteUrl = $appUrl;
        if ($remoteUrl === '' && $domain !== '' && !app_license_is_placeholder_remote_host($domain)) {
            $remoteUrl = 'https://' . $domain;
        }

        return [
            'installation_id' => mb_substr($installationId, 0, 80),
            'fingerprint' => mb_substr($fingerprint, 0, 80),
            'domain' => $domain,
            'app_url' => mb_substr(trim($appUrl), 0, 255),
            'remote_url' => mb_substr(trim($remoteUrl), 0, 255),
        ];
    }
}

if (!function_exists('app_support_owner_report_users')) {
    function app_support_owner_report_users(mysqli $conn, int $reportId, int $limit = 1000): array
    {
        app_initialize_license_management($conn);
        if ($reportId <= 0) {
            return [];
        }
        $limit = max(1, min(2000, $limit));
        $stmt = $conn->prepare("
            SELECT *
            FROM app_support_client_report_users
            WHERE report_id = ?
            ORDER BY id ASC
            LIMIT ?
        ");
        $stmt->bind_param('ii', $reportId, $limit);
        $stmt->execute();
        $rows = [];
        $res = $stmt->get_result();
        while ($res && ($row = $res->fetch_assoc())) {
            $rows[] = $row;
        }
        $stmt->close();
        return $rows;
    }
}

if (!function_exists('app_support_owner_report_remote_endpoints')) {
    function app_support_owner_report_remote_endpoints(array $report): array
    {
        $live = app_support_owner_report_live_identity($report);
        $appUrl = trim((string)($live['app_url'] ?? ''));
        $domainRaw = trim((string)($live['domain'] ?? ''));

        $hosts = [];
        $hostFromAppUrl = app_license_normalize_domain((string)parse_url($appUrl, PHP_URL_HOST));
        if (
            $hostFromAppUrl !== ''
            && !app_license_is_placeholder_remote_host($hostFromAppUrl)
            && !app_license_host_is_local_or_private($hostFromAppUrl)
        ) {
            $hosts[] = $hostFromAppUrl;
        }

        if ($domainRaw !== '') {
            $tokens = preg_split('/[\s,\|;]+/', $domainRaw) ?: [];
            foreach ($tokens as $token) {
                $token = trim((string)$token);
                if ($token === '') {
                    continue;
                }
                $candidateHost = '';
                if (strpos($token, '://') !== false) {
                    $candidateHost = app_license_normalize_domain((string)parse_url($token, PHP_URL_HOST));
                } elseif (strpos($token, '/') !== false) {
                    $candidateHost = app_license_normalize_domain((string)parse_url('https://' . ltrim($token, '/'), PHP_URL_HOST));
                } else {
                    $candidateHost = app_license_normalize_domain($token);
                }
                if ($candidateHost === '') {
                    continue;
                }
                if (app_license_is_placeholder_remote_host($candidateHost) || app_license_host_is_local_or_private($candidateHost)) {
                    continue;
                }
                $hosts[] = $candidateHost;
            }
        }

        $hosts = array_values(array_unique(array_filter($hosts, static function ($v) {
            return is_string($v) && trim($v) !== '';
        })));

        $extra = [];
        $primary = '';
        foreach ($hosts as $host) {
            if ($primary === '') {
                $primary = 'https://' . $host . '/license_api.php';
            }
            $extra[] = 'https://' . $host . '/license_api.php';
            $extra[] = 'https://' . $host . '/api/license/check/';
            $extra[] = 'http://' . $host . '/license_api.php';
            $extra[] = 'http://' . $host . '/api/license/check/';
        }

        if ($appUrl !== '' && !app_license_url_looks_placeholder($appUrl) && !app_license_url_is_local_or_private($appUrl)) {
            if ($primary === '') {
                $primary = $appUrl;
            }
            $extra[] = $appUrl;
        }

        $extra = array_values(array_unique(array_filter($extra, static function ($v) {
            return is_string($v) && trim($v) !== '';
        })));

        return [
            'primary' => $primary,
            'extra' => $extra,
            'hosts' => $hosts,
        ];
    }
}

if (!function_exists('app_support_owner_should_retry_after_remote_error')) {
    function app_support_owner_should_retry_after_remote_error(string $error): bool
    {
        $err = strtolower(trim($error));
        if ($err === '') {
            return false;
        }
        if (in_array($err, ['unauthorized', 'bad_signature', 'timestamp_expired', 'replay_detected', 'invalid_json'], true)) {
            return true;
        }
        if (in_array($err, ['license_mismatch', 'installation_mismatch', 'fingerprint_mismatch', 'domain_mismatch'], true)) {
            return true;
        }
        if (preg_match('/^http_(301|302|307|308|400|401|403|404|405|406|409|415|431)\b/i', $err)) {
            return true;
        }
        return false;
    }
}

if (!function_exists('app_support_owner_delete_reports_for_license')) {
    function app_support_owner_delete_reports_for_license(mysqli $conn, int $licenseId, string $licenseKey = ''): array
    {
        app_initialize_license_management($conn);
        $licenseId = max(0, $licenseId);
        $licenseKey = strtoupper(trim($licenseKey));
        if ($licenseId <= 0 && $licenseKey === '') {
            return ['ok' => false, 'error' => 'invalid_target', 'reports_deleted' => 0, 'users_deleted' => 0];
        }

        $reportIds = [];
        if ($licenseId > 0) {
            $stmt = $conn->prepare("SELECT id FROM app_support_client_reports WHERE license_id = ?");
            $stmt->bind_param('i', $licenseId);
        } else {
            $stmt = $conn->prepare("SELECT id FROM app_support_client_reports WHERE license_key = ?");
            $stmt->bind_param('s', $licenseKey);
        }
        $stmt->execute();
        $res = $stmt->get_result();
        while ($res && ($row = $res->fetch_assoc())) {
            $reportIds[] = (int)($row['id'] ?? 0);
        }
        $stmt->close();
        $reportIds = array_values(array_filter($reportIds, static function ($v) {
            return (int)$v > 0;
        }));
        if (empty($reportIds)) {
            return ['ok' => true, 'error' => '', 'reports_deleted' => 0, 'users_deleted' => 0];
        }

        $usersDeleted = 0;
        $reportsDeleted = 0;
        foreach ($reportIds as $rid) {
            $stmtUsers = $conn->prepare("DELETE FROM app_support_client_report_users WHERE report_id = ?");
            $stmtUsers->bind_param('i', $rid);
            $stmtUsers->execute();
            $usersDeleted += (int)$stmtUsers->affected_rows;
            $stmtUsers->close();

            $stmtReport = $conn->prepare("DELETE FROM app_support_client_reports WHERE id = ?");
            $stmtReport->bind_param('i', $rid);
            $stmtReport->execute();
            $reportsDeleted += (int)$stmtReport->affected_rows;
            $stmtReport->close();
        }

        return [
            'ok' => true,
            'error' => '',
            'reports_deleted' => $reportsDeleted,
            'users_deleted' => $usersDeleted,
        ];
    }
}

if (!function_exists('app_support_owner_delete_report')) {
    function app_support_owner_delete_report(mysqli $conn, int $reportId): array
    {
        app_initialize_license_management($conn);
        if (app_license_edition() !== 'owner' || !app_is_super_user()) {
            return ['ok' => false, 'error' => 'super_user_required', 'reports_deleted' => 0, 'users_deleted' => 0];
        }
        if ($reportId <= 0) {
            return ['ok' => false, 'error' => 'invalid_report_id', 'reports_deleted' => 0, 'users_deleted' => 0];
        }

        $stmtUsers = $conn->prepare("DELETE FROM app_support_client_report_users WHERE report_id = ?");
        $stmtUsers->bind_param('i', $reportId);
        $stmtUsers->execute();
        $usersDeleted = (int)$stmtUsers->affected_rows;
        $stmtUsers->close();

        $stmtReport = $conn->prepare("DELETE FROM app_support_client_reports WHERE id = ?");
        $stmtReport->bind_param('i', $reportId);
        $stmtReport->execute();
        $reportsDeleted = (int)$stmtReport->affected_rows;
        $stmtReport->close();

        return [
            'ok' => $reportsDeleted > 0,
            'error' => $reportsDeleted > 0 ? '' : 'report_not_found',
            'reports_deleted' => $reportsDeleted,
            'users_deleted' => $usersDeleted,
        ];
    }
}

if (!function_exists('app_support_owner_issue_user_reset_link')) {
    function app_support_owner_issue_user_reset_link(mysqli $conn, int $reportId, int $reportUserId): array
    {
        if (app_license_edition() !== 'owner' || !app_is_super_user()) {
            return ['ok' => false, 'error' => 'super_user_required'];
        }
        $report = app_support_owner_report_get($conn, $reportId);
        if (empty($report)) {
            return ['ok' => false, 'error' => 'report_not_found'];
        }
        $stmtUser = $conn->prepare("
            SELECT *
            FROM app_support_client_report_users
            WHERE id = ? AND report_id = ?
            LIMIT 1
        ");
        $stmtUser->bind_param('ii', $reportUserId, $reportId);
        $stmtUser->execute();
        $userRow = $stmtUser->get_result()->fetch_assoc() ?: [];
        $stmtUser->close();
        if (empty($userRow)) {
            return ['ok' => false, 'error' => 'user_not_found'];
        }

        $live = app_support_owner_report_live_identity($report);
        $remoteEndpoints = app_support_owner_report_remote_endpoints($report);
        $baseUrl = trim((string)($remoteEndpoints['primary'] ?? $live['remote_url'] ?? ''));
        if ($baseUrl === '') {
            return ['ok' => false, 'error' => 'client_url_missing'];
        }
        $licenseKey = strtoupper(trim((string)($report['license_key'] ?? '')));
        $licenseRow = [];
        $licenseId = (int)($report['license_id'] ?? 0);
        if ($licenseId > 0) {
            $licenseRow = app_license_registry_get($conn, $licenseId);
        }
        if (empty($licenseRow) && $licenseKey !== '') {
            $licenseRow = app_license_registry_by_key($conn, $licenseKey);
        }

        $apiToken = trim((string)($licenseRow['api_token'] ?? ''));
        $extraTokens = [];
        $previousToken = trim((string)($licenseRow['previous_api_token'] ?? ''));
        $previousUntil = trim((string)($licenseRow['previous_api_token_expires_at'] ?? ''));
        if ($previousToken !== '') {
            $prevTs = $previousUntil !== '' ? strtotime($previousUntil) : false;
            if ($prevTs !== false && $prevTs < time()) {
                $previousToken = '';
            }
        }
        if ($previousToken !== '' && $previousToken !== $apiToken) {
            $extraTokens[] = $previousToken;
        }
        $ownerApiToken = trim((string)app_env('APP_LICENSE_API_TOKEN', ''));
        if ($ownerApiToken !== '' && $ownerApiToken !== $apiToken && !in_array($ownerApiToken, $extraTokens, true)) {
            $extraTokens[] = $ownerApiToken;
        }
        $ownerRemoteToken = trim((string)app_env('APP_LICENSE_REMOTE_TOKEN', ''));
        if ($ownerRemoteToken !== '' && $ownerRemoteToken !== $apiToken && !in_array($ownerRemoteToken, $extraTokens, true)) {
            $extraTokens[] = $ownerRemoteToken;
        }
        if ($apiToken === '' && !empty($extraTokens)) {
            $apiToken = (string)array_shift($extraTokens);
        }

        $payload = [
            'event' => 'support_password_reset_issue',
            'license_key' => $licenseKey,
            'installation_id' => trim((string)($live['installation_id'] ?? $report['installation_id'] ?? '')),
            'fingerprint' => trim((string)($live['fingerprint'] ?? $report['fingerprint'] ?? '')),
            'domain' => trim((string)($live['domain'] ?? $report['domain'] ?? '')),
            'app_url' => trim((string)($live['app_url'] ?? $baseUrl)),
            'user' => [
                'remote_user_id' => (int)($userRow['remote_user_id'] ?? 0),
                'username' => (string)($userRow['username'] ?? ''),
                'email' => (string)($userRow['email'] ?? ''),
                'phone' => (string)($userRow['phone'] ?? ''),
            ],
        ];

        $ctx = [
            'remote_url' => $baseUrl,
            'remote_token' => $apiToken,
            'extra_tokens' => $extraTokens,
            'extra_urls' => (array)($remoteEndpoints['extra'] ?? []),
            'max_urls' => 14,
            'max_tokens' => 8,
            'max_attempts' => 12,
        ];
        $remote = app_support_remote_post($conn, $ctx, $payload);
        if (empty($remote['ok']) && app_support_owner_should_retry_after_remote_error((string)($remote['error'] ?? ''))) {
            $licenseId = (int)($report['license_id'] ?? 0);
            if (
                $licenseId > 0
                && trim((string)($payload['installation_id'] ?? '')) !== ''
                && trim((string)($payload['fingerprint'] ?? '')) !== ''
            ) {
                app_support_owner_pull_client_snapshot(
                    $conn,
                    $licenseId,
                    $baseUrl,
                    (string)$payload['installation_id'],
                    (string)$payload['fingerprint'],
                    (string)$payload['domain'],
                    (string)$payload['app_url']
                );
                $report = app_support_owner_report_get($conn, $reportId);
                if (!empty($report)) {
                    $live = app_support_owner_report_live_identity($report);
                    $remoteEndpoints = app_support_owner_report_remote_endpoints($report);
                    $baseUrl = trim((string)($remoteEndpoints['primary'] ?? $live['remote_url'] ?? $baseUrl));
                    $payload['installation_id'] = trim((string)($live['installation_id'] ?? $payload['installation_id']));
                    $payload['fingerprint'] = trim((string)($live['fingerprint'] ?? $payload['fingerprint']));
                    $payload['domain'] = trim((string)($live['domain'] ?? $payload['domain']));
                    $payload['app_url'] = trim((string)($live['app_url'] ?? $payload['app_url']));
                    $ctx['remote_url'] = $baseUrl !== '' ? $baseUrl : (string)$ctx['remote_url'];
                    $ctx['extra_urls'] = (array)($remoteEndpoints['extra'] ?? []);
                    $remote = app_support_remote_post($conn, $ctx, $payload);
                }
            }
        }
        if (empty($remote['ok'])) {
            return ['ok' => false, 'error' => (string)($remote['error'] ?? 'remote_failed')];
        }
        $body = (isset($remote['body']) && is_array($remote['body'])) ? $remote['body'] : [];
        $resetLink = trim((string)($body['reset_link'] ?? ''));
        if ($resetLink === '') {
            return ['ok' => false, 'error' => (string)($body['error'] ?? 'reset_link_missing')];
        }

        return [
            'ok' => true,
            'error' => '',
            'reset_link' => $resetLink,
            'expires_at' => (string)($body['expires_at'] ?? ''),
            'user' => [
                'full_name' => (string)($body['user']['full_name'] ?? $userRow['full_name'] ?? ''),
                'email' => (string)($body['user']['email'] ?? $userRow['email'] ?? ''),
                'phone' => (string)($body['user']['phone'] ?? $userRow['phone'] ?? ''),
                'username' => (string)($body['user']['username'] ?? $userRow['username'] ?? ''),
            ],
            'report' => $report,
        ];
    }
}

if (!function_exists('app_support_owner_remote_user_action')) {
    function app_support_owner_remote_user_action(mysqli $conn, int $reportId, string $action, array $userPayload): array
    {
        if (app_license_edition() !== 'owner' || !app_is_super_user()) {
            return ['ok' => false, 'error' => 'super_user_required'];
        }
        $report = app_support_owner_report_get($conn, $reportId);
        if (empty($report)) {
            return ['ok' => false, 'error' => 'report_not_found'];
        }

        $eventMap = [
            'create' => 'support_user_create',
            'update' => 'support_user_update',
            'set_password' => 'support_user_set_password',
            'delete' => 'support_user_delete',
        ];
        $event = (string)($eventMap[$action] ?? '');
        if ($event === '') {
            return ['ok' => false, 'error' => 'invalid_action'];
        }

        // Some nginx/WAF configurations may reject raw password patterns in JSON bodies.
        // For create/set_password actions, send password as base64 field.
        if (in_array($action, ['create', 'set_password'], true)) {
            $rawPassword = (string)($userPayload['password'] ?? '');
            if ($rawPassword !== '') {
                $userPayload['password_b64'] = base64_encode($rawPassword);
                unset($userPayload['password']);
            }
        }

        $live = app_support_owner_report_live_identity($report);
        $remoteEndpoints = app_support_owner_report_remote_endpoints($report);
        $baseUrl = trim((string)($remoteEndpoints['primary'] ?? $live['remote_url'] ?? ''));
        if ($baseUrl === '') {
            return ['ok' => false, 'error' => 'client_url_missing'];
        }
        $licenseKey = strtoupper(trim((string)($report['license_key'] ?? '')));
        $licenseRow = [];
        $licenseId = (int)($report['license_id'] ?? 0);
        if ($licenseId > 0) {
            $licenseRow = app_license_registry_get($conn, $licenseId);
        }
        if (empty($licenseRow) && $licenseKey !== '') {
            $licenseRow = app_license_registry_by_key($conn, $licenseKey);
        }

        $apiToken = trim((string)($licenseRow['api_token'] ?? ''));
        $extraTokens = [];
        $previousToken = trim((string)($licenseRow['previous_api_token'] ?? ''));
        $previousUntil = trim((string)($licenseRow['previous_api_token_expires_at'] ?? ''));
        if ($previousToken !== '') {
            $prevTs = $previousUntil !== '' ? strtotime($previousUntil) : false;
            if ($prevTs !== false && $prevTs < time()) {
                $previousToken = '';
            }
        }
        if ($previousToken !== '' && $previousToken !== $apiToken) {
            $extraTokens[] = $previousToken;
        }
        $ownerApiToken = trim((string)app_env('APP_LICENSE_API_TOKEN', ''));
        if ($ownerApiToken !== '' && $ownerApiToken !== $apiToken && !in_array($ownerApiToken, $extraTokens, true)) {
            $extraTokens[] = $ownerApiToken;
        }
        $ownerRemoteToken = trim((string)app_env('APP_LICENSE_REMOTE_TOKEN', ''));
        if ($ownerRemoteToken !== '' && $ownerRemoteToken !== $apiToken && !in_array($ownerRemoteToken, $extraTokens, true)) {
            $extraTokens[] = $ownerRemoteToken;
        }
        if ($apiToken === '' && !empty($extraTokens)) {
            $apiToken = (string)array_shift($extraTokens);
        }

        $payload = [
            'event' => $event,
            'license_key' => $licenseKey,
            'installation_id' => trim((string)($live['installation_id'] ?? $report['installation_id'] ?? '')),
            'fingerprint' => trim((string)($live['fingerprint'] ?? $report['fingerprint'] ?? '')),
            'domain' => trim((string)($live['domain'] ?? $report['domain'] ?? '')),
            'app_url' => trim((string)($live['app_url'] ?? $baseUrl)),
            'user' => $userPayload,
        ];
        $ctx = [
            'remote_url' => $baseUrl,
            'remote_token' => $apiToken,
            'extra_tokens' => $extraTokens,
            'extra_urls' => (array)($remoteEndpoints['extra'] ?? []),
            'max_urls' => 14,
            'max_tokens' => 8,
            'max_attempts' => 12,
        ];
        $remote = app_support_remote_post($conn, $ctx, $payload);
        if (empty($remote['ok']) && app_support_owner_should_retry_after_remote_error((string)($remote['error'] ?? ''))) {
            $licenseId = (int)($report['license_id'] ?? 0);
            if (
                $licenseId > 0
                && trim((string)($payload['installation_id'] ?? '')) !== ''
                && trim((string)($payload['fingerprint'] ?? '')) !== ''
            ) {
                app_support_owner_pull_client_snapshot(
                    $conn,
                    $licenseId,
                    $baseUrl,
                    (string)$payload['installation_id'],
                    (string)$payload['fingerprint'],
                    (string)$payload['domain'],
                    (string)$payload['app_url']
                );
                $report = app_support_owner_report_get($conn, $reportId);
                if (!empty($report)) {
                    $live = app_support_owner_report_live_identity($report);
                    $remoteEndpoints = app_support_owner_report_remote_endpoints($report);
                    $baseUrl = trim((string)($remoteEndpoints['primary'] ?? $live['remote_url'] ?? $baseUrl));
                    $payload['installation_id'] = trim((string)($live['installation_id'] ?? $payload['installation_id']));
                    $payload['fingerprint'] = trim((string)($live['fingerprint'] ?? $payload['fingerprint']));
                    $payload['domain'] = trim((string)($live['domain'] ?? $payload['domain']));
                    $payload['app_url'] = trim((string)($live['app_url'] ?? $payload['app_url']));
                    $ctx['remote_url'] = $baseUrl !== '' ? $baseUrl : (string)$ctx['remote_url'];
                    $ctx['extra_urls'] = (array)($remoteEndpoints['extra'] ?? []);
                    $remote = app_support_remote_post($conn, $ctx, $payload);
                }
            }
        }
        if (empty($remote['ok'])) {
            return ['ok' => false, 'error' => (string)($remote['error'] ?? 'remote_failed')];
        }
        $body = (isset($remote['body']) && is_array($remote['body'])) ? $remote['body'] : [];
        if (isset($body['ok']) && !$body['ok']) {
            return ['ok' => false, 'error' => (string)($body['error'] ?? 'remote_rejected'), 'body' => $body];
        }

        return ['ok' => true, 'error' => '', 'body' => $body, 'report' => $report];
    }
}
