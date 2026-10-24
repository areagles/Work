<?php
require_once __DIR__ . '/config.php';

if (!function_exists('saas_automation_output')) {
    function saas_automation_output(int $status, array $payload): void
    {
        if (PHP_SAPI !== 'cli' && !headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=UTF-8');
        }
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        exit;
    }
}

$saasAutomationRate = function_exists('app_rate_limit_check')
    ? app_rate_limit_check(
        'saas_automation',
        function_exists('app_rate_limit_client_key') ? app_rate_limit_client_key('saas_automation') : ((string)($_SERVER['REMOTE_ADDR'] ?? 'automation')),
        20,
        600
    )
    : ['allowed' => true, 'limit' => 0, 'remaining' => 0, 'retry_after' => 0];
if (!$saasAutomationRate['allowed']) {
    if (PHP_SAPI !== 'cli' && !headers_sent()) {
        header('Retry-After: ' . (int)$saasAutomationRate['retry_after']);
        header('X-RateLimit-Limit: ' . (int)$saasAutomationRate['limit']);
        header('X-RateLimit-Remaining: ' . (int)$saasAutomationRate['remaining']);
    }
    saas_automation_output(429, [
        'ok' => false,
        'error' => 'rate_limited',
        'retry_after' => (int)$saasAutomationRate['retry_after'],
    ]);
}
if (PHP_SAPI !== 'cli' && !headers_sent()) {
    header('X-RateLimit-Limit: ' . (int)$saasAutomationRate['limit']);
    header('X-RateLimit-Remaining: ' . (int)$saasAutomationRate['remaining']);
}

if (!function_exists('saas_automation_cli_params')) {
    function saas_automation_cli_params(array $argv): array
    {
        $params = [];
        foreach (array_slice($argv, 1) as $arg) {
            $arg = trim((string)$arg);
            if ($arg === '') {
                continue;
            }
            if (strpos($arg, '=') !== false) {
                [$k, $v] = explode('=', $arg, 2);
                $params[strtolower(trim((string)$k))] = trim((string)$v);
            } else {
                $params[strtolower($arg)] = '1';
            }
        }
        return $params;
    }
}

if (!function_exists('saas_automation_request_token')) {
    function saas_automation_request_token(array $cliParams): string
    {
        if (!empty($cliParams['token'])) {
            return trim((string)$cliParams['token']);
        }
        $queryToken = trim((string)($_GET['token'] ?? $_POST['token'] ?? $_POST['_auth'] ?? ''));
        if ($queryToken !== '') {
            return $queryToken;
        }
        $authHeader = trim((string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? ''));
        if (stripos($authHeader, 'Bearer ') === 0) {
            return trim(substr($authHeader, 7));
        }
        return '';
    }
}

$cliParams = PHP_SAPI === 'cli' ? saas_automation_cli_params($argv ?? []) : [];
$expectedToken = trim((string)app_env('APP_SAAS_AUTOMATION_TOKEN', app_env('APP_LICENSE_API_TOKEN', '')));
$providedToken = saas_automation_request_token($cliParams);

if ($expectedToken === '') {
    saas_automation_output(503, [
        'ok' => false,
        'error' => 'automation_token_not_configured',
    ]);
}
if ($providedToken === '' || !hash_equals($expectedToken, $providedToken)) {
    saas_automation_output(401, [
        'ok' => false,
        'error' => 'invalid_token',
    ]);
}
if (!function_exists('saas_automation_is_work_runtime')) {
    function saas_automation_is_work_runtime(): bool
    {
        $host = strtolower(trim((string)($_SERVER['HTTP_HOST'] ?? '')));
        if ($host === 'work.areagles.com') {
            return true;
        }
        $dirName = strtolower(trim((string)basename(__DIR__)));
        return $dirName === 'work';
    }
}
if (saas_automation_is_work_runtime() && !in_array(strtolower(trim((string)app_env('APP_ALLOW_WORK_AUTOMATION', '0'))), ['1', 'true', 'yes', 'on'], true)) {
    saas_automation_output(202, [
        'ok' => true,
        'skipped' => true,
        'reason' => 'work_runtime_automation_disabled',
        'profile' => app_runtime_profile(),
    ]);
}
if (!app_saas_mode_enabled() || (!app_is_owner_hub() && !app_is_saas_gateway())) {
    saas_automation_output(403, [
        'ok' => false,
        'error' => 'runtime_not_supported',
        'profile' => app_runtime_profile(),
    ]);
}
if (app_current_tenant_id() > 0) {
    saas_automation_output(423, [
        'ok' => false,
        'error' => 'tenant_runtime_detected',
        'tenant_id' => app_current_tenant_id(),
    ]);
}

$action = strtolower(trim((string)($cliParams['action'] ?? $_GET['action'] ?? $_POST['action'] ?? 'run')));
if ($action === '') {
    $action = 'run';
}
$shouldRecalculate = !in_array(strtolower(trim((string)($cliParams['recalculate'] ?? $_GET['recalculate'] ?? $_POST['recalculate'] ?? '1'))), ['0', 'false', 'no', 'off'], true);
$cleanupLogs = !in_array(strtolower(trim((string)($cliParams['cleanup_logs'] ?? $_GET['cleanup_logs'] ?? $_POST['cleanup_logs'] ?? '1'))), ['0', 'false', 'no', 'off'], true);
$cleanupKeepLatest = max(100, (int)($cliParams['cleanup_keep_latest'] ?? $_GET['cleanup_keep_latest'] ?? $_POST['cleanup_keep_latest'] ?? 1000));
$cleanupOlderThanDays = max(1, (int)($cliParams['cleanup_older_than_days'] ?? $_GET['cleanup_older_than_days'] ?? $_POST['cleanup_older_than_days'] ?? 90));
$actor = PHP_SAPI === 'cli' ? 'CLI Scheduler' : 'Web Scheduler';

try {
    $controlConfig = app_saas_control_db_config([
        'host' => app_env('DB_HOST', 'localhost'),
        'user' => app_env('DB_USER', ''),
        'pass' => app_env('DB_PASS', ''),
        'name' => app_env('DB_NAME', ''),
        'port' => (int)app_env('DB_PORT', '3306'),
        'socket' => app_env('DB_SOCKET', ''),
    ]);
    $controlConn = app_saas_open_control_connection($controlConfig);
    app_saas_ensure_control_plane_schema($controlConn);

    $result = [
        'ok' => true,
        'action' => $action,
        'profile' => app_runtime_profile(),
        'ran_at' => date('c'),
        'policy_sync' => ['tenants' => 0, 'tenant_updates' => 0, 'subscription_updates' => 0],
        'recalculated_subscriptions' => 0,
        'invoice_generation' => ['created' => 0, 'existing' => 0, 'skipped' => 0],
        'overdue_policy' => ['updated' => 0, 'suspended' => 0, 'past_due' => 0],
        'operation_cleanup' => ['deleted' => 0, 'keep_latest' => $cleanupKeepLatest, 'older_than_days' => $cleanupOlderThanDays],
        'webhook_retries' => ['selected' => 0, 'sent' => 0, 'failed' => 0],
    ];

    $tenantIds = saas_collect_tenant_ids($controlConn);

    foreach ($tenantIds as $tenantId) {
        $sync = function_exists('app_saas_sync_policy_pack_runtime_to_tenant')
            ? app_saas_sync_policy_pack_runtime_to_tenant($controlConn, $tenantId)
            : ['tenant_updated' => 0, 'subscriptions_updated' => 0];
        $result['policy_sync']['tenants']++;
        $result['policy_sync']['tenant_updates'] += (int)($sync['tenant_updated'] ?? 0);
        $result['policy_sync']['subscription_updates'] += (int)($sync['subscriptions_updated'] ?? 0);
    }

    if ($shouldRecalculate) {
        foreach ($tenantIds as $tenantId) {
            $result['recalculated_subscriptions'] += saas_recalculate_tenant_subscriptions($controlConn, $tenantId);
        }
    }

    if (in_array($action, ['run', 'generate', 'generate_invoices'], true)) {
        $result['invoice_generation'] = saas_generate_due_subscription_invoices($controlConn, $actor);
        if ($action === 'run') {
            $result['overdue_policy'] = saas_apply_overdue_policy_all($controlConn);
        }
    } elseif (in_array($action, ['overdue', 'apply_overdue'], true)) {
        $result['overdue_policy'] = saas_apply_overdue_policy_all($controlConn);
    } else {
        throw new RuntimeException('unsupported_action');
    }

    if ($cleanupLogs) {
        $result['operation_cleanup'] = function_exists('app_saas_cleanup_operation_log_with_policies')
            ? app_saas_cleanup_operation_log_with_policies($controlConn, $cleanupKeepLatest, $cleanupOlderThanDays)
            : app_saas_cleanup_operation_log($controlConn, $cleanupKeepLatest, $cleanupOlderThanDays);
    }

    if (function_exists('saas_retry_due_webhook_deliveries')) {
        $result['webhook_retries'] = saas_retry_due_webhook_deliveries($controlConn, 25);
    }

    app_saas_log_operation($controlConn, 'automation.' . $action, 'تشغيل آلي SaaS', 0, [
        'action' => $action,
        'policy_sync' => $result['policy_sync'] ?? [],
        'recalculated_subscriptions' => (int)($result['recalculated_subscriptions'] ?? 0),
        'invoice_generation' => $result['invoice_generation'] ?? [],
        'overdue_policy' => $result['overdue_policy'] ?? [],
        'operation_cleanup' => $result['operation_cleanup'] ?? [],
        'webhook_retries' => $result['webhook_retries'] ?? [],
        'runtime_profile' => app_runtime_profile(),
        'channel' => PHP_SAPI === 'cli' ? 'cli' : 'web',
    ], $actor);
    if (function_exists('saas_dispatch_outbound_webhook')) {
        saas_dispatch_outbound_webhook($controlConn, 'automation.run', [
            'action' => $action,
            'result' => $result,
            'channel' => PHP_SAPI === 'cli' ? 'cli' : 'web',
            'actor' => $actor,
        ], 0, 'تشغيل آلي SaaS');
    }

    $controlConn->close();
    saas_automation_output(200, $result);
} catch (Throwable $e) {
    if (isset($controlConn) && $controlConn instanceof mysqli) {
        try {
            app_saas_log_operation($controlConn, 'automation.failed', 'فشل تشغيل آلي SaaS', 0, [
                'action' => $action,
                'error' => $e->getMessage(),
                'runtime_profile' => app_runtime_profile(),
                'channel' => PHP_SAPI === 'cli' ? 'cli' : 'web',
            ], $actor ?? 'Scheduler');
            if (function_exists('saas_dispatch_outbound_webhook')) {
                saas_dispatch_outbound_webhook($controlConn, 'automation.failed', [
                    'action' => $action,
                    'error' => $e->getMessage(),
                    'channel' => PHP_SAPI === 'cli' ? 'cli' : 'web',
                    'actor' => $actor ?? 'Scheduler',
                ], 0, 'فشل تشغيل آلي SaaS');
            }
        } catch (Throwable $loggingError) {
        }
        try {
            $controlConn->close();
        } catch (Throwable $closeError) {
        }
    }
    saas_automation_output(500, [
        'ok' => false,
        'error' => $e->getMessage(),
        'profile' => app_runtime_profile(),
    ]);
}
