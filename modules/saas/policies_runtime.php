<?php

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
            'profile_key' => $profileKey,
            'updated' => $updated,
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
