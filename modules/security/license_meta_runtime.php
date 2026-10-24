<?php

if (!function_exists('app_license_edition')) {
    function app_license_edition(): string
    {
        $edition = strtolower(trim((string)app_env('APP_LICENSE_EDITION', 'client')));
        return in_array($edition, ['owner', 'client'], true) ? $edition : 'client';
    }
}

if (!function_exists('app_runtime_profile')) {
    function app_runtime_profile(): string
    {
        $profile = strtolower(trim((string)app_env('APP_RUNTIME_PROFILE', '')));
        if (in_array($profile, ['owner_hub', 'client_full', 'saas_gateway'], true)) {
            return $profile;
        }
        return app_license_edition() === 'owner' ? 'owner_hub' : 'client_full';
    }
}

if (!function_exists('app_is_owner_hub')) {
    function app_is_owner_hub(): bool
    {
        return app_runtime_profile() === 'owner_hub';
    }
}

if (!function_exists('app_is_client_full')) {
    function app_is_client_full(): bool
    {
        return app_runtime_profile() === 'client_full';
    }
}

if (!function_exists('app_is_saas_gateway')) {
    function app_is_saas_gateway(): bool
    {
        return app_runtime_profile() === 'saas_gateway';
    }
}

if (!function_exists('app_runtime_profile_catalog')) {
    function app_runtime_profile_catalog(): array
    {
        return [
            'owner_hub' => [
                'label_ar' => 'مركز الإدارة والتفعيل',
                'label_en' => 'Owner Hub',
                'family' => 'owner',
            ],
            'client_full' => [
                'label_ar' => 'نظام عميل خاص',
                'label_en' => 'Private Client',
                'family' => 'private',
            ],
            'saas_gateway' => [
                'label_ar' => 'بوابة SaaS',
                'label_en' => 'SaaS Gateway',
                'family' => 'saas',
            ],
        ];
    }
}

if (!function_exists('app_runtime_profile_label')) {
    function app_runtime_profile_label(?string $profile = null): string
    {
        $profile = strtolower(trim((string)($profile ?? app_runtime_profile())));
        $catalog = app_runtime_profile_catalog();
        $row = $catalog[$profile] ?? [];
        if (!$row) {
            return $profile !== '' ? $profile : app_tr('غير معروف', 'Unknown');
        }
        return app_tr((string)($row['label_ar'] ?? $profile), (string)($row['label_en'] ?? $profile));
    }
}

if (!function_exists('app_license_plan_catalog')) {
    function app_license_plan_catalog(): array
    {
        return [
            'trial' => ['label_ar' => 'تجريبي', 'label_en' => 'Trial'],
            'subscription' => ['label_ar' => 'اشتراك', 'label_en' => 'Subscription'],
            'lifetime' => ['label_ar' => 'بيع نهائي', 'label_en' => 'Lifetime Sale'],
        ];
    }
}

if (!function_exists('app_license_plan_label')) {
    function app_license_plan_label(string $plan): string
    {
        $plan = strtolower(trim($plan));
        $catalog = app_license_plan_catalog();
        $row = $catalog[$plan] ?? [];
        if (!$row) {
            return $plan !== '' ? $plan : app_tr('غير محدد', 'Not set');
        }
        return app_tr((string)($row['label_ar'] ?? $plan), (string)($row['label_en'] ?? $plan));
    }
}

if (!function_exists('app_status_label_catalog')) {
    function app_status_label_catalog(): array
    {
        return [
            'active' => ['label_ar' => 'نشط', 'label_en' => 'Active'],
            'suspended' => ['label_ar' => 'موقوف', 'label_en' => 'Suspended'],
            'expired' => ['label_ar' => 'منتهي', 'label_en' => 'Expired'],
            'trial' => ['label_ar' => 'تجريبي', 'label_en' => 'Trial'],
            'past_due' => ['label_ar' => 'متأخر', 'label_en' => 'Past due'],
            'cancelled' => ['label_ar' => 'ملغي', 'label_en' => 'Cancelled'],
            'provisioning' => ['label_ar' => 'قيد التهيئة', 'label_en' => 'Provisioning'],
            'archived' => ['label_ar' => 'مؤرشف', 'label_en' => 'Archived'],
        ];
    }
}

if (!function_exists('app_status_label')) {
    function app_status_label(string $status): string
    {
        $status = strtolower(trim($status));
        $catalog = app_status_label_catalog();
        $row = $catalog[$status] ?? [];
        if (!$row) {
            return $status !== '' ? $status : app_tr('غير محدد', 'Not set');
        }
        return app_tr((string)($row['label_ar'] ?? $status), (string)($row['label_en'] ?? $status));
    }
}

if (!function_exists('app_billing_cycle_catalog')) {
    function app_billing_cycle_catalog(): array
    {
        return [
            'monthly' => ['label_ar' => 'شهري', 'label_en' => 'Monthly'],
            'quarterly' => ['label_ar' => 'ربع سنوي', 'label_en' => 'Quarterly'],
            'yearly' => ['label_ar' => 'سنوي', 'label_en' => 'Yearly'],
            'manual' => ['label_ar' => 'يدوي', 'label_en' => 'Manual'],
        ];
    }
}

if (!function_exists('app_billing_cycle_label')) {
    function app_billing_cycle_label(string $cycle): string
    {
        $cycle = strtolower(trim($cycle));
        $catalog = app_billing_cycle_catalog();
        $row = $catalog[$cycle] ?? [];
        if (!$row) {
            return $cycle !== '' ? $cycle : app_tr('غير محدد', 'Not set');
        }
        return app_tr((string)($row['label_ar'] ?? $cycle), (string)($row['label_en'] ?? $cycle));
    }
}

if (!function_exists('app_license_default_client_status')) {
    function app_license_default_client_status(): string
    {
        $raw = strtolower(trim((string)app_env('APP_LICENSE_DEFAULT_STATUS', 'suspended')));
        if (!in_array($raw, ['active', 'suspended', 'expired'], true)) {
            return 'suspended';
        }
        return $raw;
    }
}

if (!function_exists('app_license_owner_remote_sync_enabled')) {
    function app_license_owner_remote_sync_enabled(): bool
    {
        return app_env_flag('APP_LICENSE_OWNER_REMOTE_SYNC', false);
    }
}
