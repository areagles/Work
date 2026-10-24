<?php
require_once __DIR__ . '/modules/tax/engine_runtime.php';
require_once __DIR__ . '/modules/tax/reporting_runtime.php';
require_once __DIR__ . '/modules/security/core_runtime.php';
require_once __DIR__ . '/modules/security/settings_branding_runtime.php';
require_once __DIR__ . '/modules/security/license_meta_runtime.php';
require_once __DIR__ . '/modules/security/io_runtime.php';
require_once __DIR__ . '/modules/security/rate_limit_runtime.php';
require_once __DIR__ . '/modules/security/license_runtime_runtime.php';
require_once __DIR__ . '/modules/security/license_registry_runtime.php';
require_once __DIR__ . '/modules/security/license_api_runtime.php';
require_once __DIR__ . '/modules/security/support_core_runtime.php';
require_once __DIR__ . '/modules/security/support_remote_api_runtime.php';
require_once __DIR__ . '/modules/security/support_ticket_runtime.php';
require_once __DIR__ . '/modules/security/license_cloud_sync_runtime.php';
require_once __DIR__ . '/modules/security/env_runtime.php';
require_once __DIR__ . '/modules/security/bootstrap_schema_runtime.php';
// security.php
// Shared security helpers used across the application.

if (!function_exists('app_license_api_check')) {
    function app_license_api_check(mysqli $conn, array $payload, string $bearerToken = ''): array
    {
        app_initialize_license_management($conn);
        $event = strtolower(trim((string)($payload['event'] ?? '')));
        if ($event === '' || $event === 'check' || $event === 'license_sync_v2') {
            return app_license_api_sync_v2($conn, $payload, $bearerToken);
        }

        if (!app_license_api_bearer_allowed($conn, $payload, $bearerToken)) {
            app_license_api_log_unauthorized_attempt($conn, $payload, $bearerToken);
            return ['http_code' => 401, 'body' => ['ok' => false, 'error' => 'unauthorized']];
        }

        if ($event === 'support_ticket_create') {
            return app_support_api_ticket_create($conn, $payload);
        }
        if ($event === 'support_ticket_reply') {
            return app_support_api_ticket_reply($conn, $payload);
        }
        if ($event === 'support_ticket_pull') {
            return app_support_api_ticket_pull($conn, $payload);
        }
        if ($event === 'support_ticket_delete') {
            return app_support_api_ticket_delete($conn, $payload);
        }
        if ($event === 'support_system_snapshot') {
            return app_support_api_system_snapshot($conn, $payload);
        }
        if ($event === 'owner_credentials_push') {
            return app_support_api_owner_credentials_push($conn, $payload);
        }
        if ($event === 'support_password_reset_issue') {
            return app_support_api_issue_password_reset_link($conn, $payload);
        }
        if ($event === 'support_user_create') {
            return app_support_api_user_create($conn, $payload);
        }
        if ($event === 'support_user_update') {
            return app_support_api_user_update($conn, $payload);
        }
        if ($event === 'support_user_set_password') {
            return app_support_api_user_set_password($conn, $payload);
        }
        if ($event === 'support_user_delete') {
            return app_support_api_user_delete($conn, $payload);
        }
        if ($event === 'license_link_confirm') {
            return app_license_api_event_link_confirm($conn, $payload);
        }
        if ($event === 'license_auto_bootstrap') {
            return app_license_api_event_auto_bootstrap($conn, $payload, $bearerToken);
        }

        return ['http_code' => 422, 'body' => ['ok' => false, 'error' => 'unknown_event']];
    }
}

