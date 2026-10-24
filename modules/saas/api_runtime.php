<?php

if (!function_exists('saas_api_json')) {
    function saas_api_json(int $statusCode, array $payload): void
    {
        http_response_code($statusCode);
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

if (!function_exists('saas_api_resolve_token')) {
    function saas_api_resolve_token(): string
    {
        $authHeader = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? '');
        if (stripos($authHeader, 'Bearer ') === 0) {
            return trim(substr($authHeader, 7));
        }
        $redirectAuth = (string)($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        if (stripos($redirectAuth, 'Bearer ') === 0) {
            return trim(substr($redirectAuth, 7));
        }
        $headerToken = trim((string)($_SERVER['HTTP_X_API_TOKEN'] ?? ''));
        if ($headerToken !== '') {
            return $headerToken;
        }
        return '';
    }
}

if (!function_exists('saas_api_expected_token')) {
    function saas_api_expected_token(): string
    {
        $candidate = trim((string)app_env('APP_SAAS_API_TOKEN', ''));
        if ($candidate !== '') {
            return $candidate;
        }
        return trim((string)app_env('APP_LICENSE_API_TOKEN', ''));
    }
}

if (!function_exists('saas_api_request_body')) {
    function saas_api_request_body(): array
    {
        static $payload = null;
        if (is_array($payload)) {
            return $payload;
        }
        $payload = [];
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            if (!empty($_POST)) {
                $payload = $_POST;
            } else {
                $raw = file_get_contents('php://input');
                if (is_string($raw) && trim($raw) !== '') {
                    $decoded = json_decode($raw, true);
                    if (is_array($decoded)) {
                        $payload = $decoded;
                    }
                }
            }
        }
        return is_array($payload) ? $payload : [];
    }
}

if (!function_exists('saas_api_request_value')) {
    function saas_api_request_value(string $key, $default = null)
    {
        $body = saas_api_request_body();
        if (array_key_exists($key, $body)) {
            return $body[$key];
        }
        if (array_key_exists($key, $_POST)) {
            return $_POST[$key];
        }
        if (array_key_exists($key, $_GET)) {
            return $_GET[$key];
        }
        return $default;
    }
}

if (!function_exists('saas_api_pick_tenant')) {
    function saas_api_pick_tenant(mysqli $controlConn, string $tenantSlug = '', int $tenantId = 0): ?array
    {
        if ($tenantId > 0) {
            $stmt = $controlConn->prepare("SELECT * FROM saas_tenants WHERE id = ? LIMIT 1");
            $stmt->bind_param('i', $tenantId);
        } else {
            $tenantSlug = trim($tenantSlug);
            if ($tenantSlug === '') {
                return null;
            }
            $stmt = $controlConn->prepare("SELECT * FROM saas_tenants WHERE LOWER(tenant_slug) = LOWER(?) LIMIT 1");
            $stmt->bind_param('s', $tenantSlug);
        }
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        if (!is_array($row)) {
            return null;
        }
        return $row;
    }
}

if (!function_exists('saas_api_sanitize_tenant')) {
    function saas_api_sanitize_tenant(array $tenant): array
    {
        if (function_exists('saas_sanitize_tenant_snapshot')) {
            return saas_sanitize_tenant_snapshot($tenant);
        }
        unset(
            $tenant['db_host'],
            $tenant['db_port'],
            $tenant['db_socket'],
            $tenant['db_user'],
            $tenant['db_name'],
            $tenant['db_password_plain'],
            $tenant['db_password_enc'],
            $tenant['app_url'],
            $tenant['billing_portal_token'],
            $tenant['billing_portal_url']
        );
        return $tenant;
    }
}

if (!function_exists('saas_api_sanitize_health')) {
    function saas_api_sanitize_health(array $health): array
    {
        return [
            'severity' => (string)($health['severity'] ?? 'unknown'),
            'db_ok' => !empty($health['db_ok']),
            'runtime_ok' => !empty($health['runtime_ok']),
            'issues' => array_values(array_map('strval', (array)($health['issues'] ?? []))),
        ];
    }
}

if (!function_exists('saas_api_find_invoice')) {
    function saas_api_find_invoice(mysqli $controlConn, int $invoiceId = 0, string $invoiceToken = ''): ?array
    {
        if ($invoiceId > 0) {
            return function_exists('saas_fetch_invoice_snapshot') ? saas_fetch_invoice_snapshot($controlConn, $invoiceId) : null;
        }
        $invoiceToken = trim($invoiceToken);
        if ($invoiceToken !== '' && function_exists('saas_find_subscription_invoice_by_token')) {
            return saas_find_subscription_invoice_by_token($controlConn, $invoiceToken);
        }
        return null;
    }
}

if (!function_exists('saas_api_bootstrap')) {
    function saas_api_bootstrap(): array
    {
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('X-Content-Type-Options: nosniff');

        if (!in_array(($_SERVER['REQUEST_METHOD'] ?? 'GET'), ['GET', 'POST'], true)) {
            http_response_code(405);
            header('Allow: GET, POST');
            echo json_encode(['ok' => false, 'error' => 'method_not_allowed'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }

        $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $rate = function_exists('app_rate_limit_check')
            ? app_rate_limit_check(
                $method === 'POST' ? 'saas_api_post' : 'saas_api_get',
                function_exists('app_rate_limit_client_key') ? app_rate_limit_client_key('saas_api') : ((string)($_SERVER['REMOTE_ADDR'] ?? 'api')),
                $method === 'POST' ? 40 : 180,
                300
            )
            : ['allowed' => true, 'limit' => 0, 'remaining' => 0, 'retry_after' => 0];
        if (!$rate['allowed']) {
            header('Retry-After: ' . (int)$rate['retry_after']);
            saas_api_json(429, ['ok' => false, 'error' => 'rate_limited', 'retry_after' => (int)$rate['retry_after']]);
        }
        header('X-RateLimit-Limit: ' . (int)$rate['limit']);
        header('X-RateLimit-Remaining: ' . (int)$rate['remaining']);

        $providedToken = saas_api_resolve_token();
        $expectedToken = saas_api_expected_token();
        if ($expectedToken === '' || !hash_equals($expectedToken, $providedToken)) {
            saas_api_json(401, ['ok' => false, 'error' => 'unauthorized']);
        }

        $controlDbConfig = app_saas_control_db_config([
            'host' => app_env('DB_HOST', 'localhost'),
            'user' => app_env('DB_USER', ''),
            'pass' => app_env('DB_PASS', ''),
            'name' => app_env('DB_NAME', ''),
            'port' => (int)app_env('DB_PORT', '3306'),
            'socket' => app_env('DB_SOCKET', ''),
        ]);
        $controlConn = app_saas_open_control_connection($controlDbConfig);
        app_saas_ensure_control_plane_schema($controlConn);
        return [$controlConn, $method];
    }
}

if (!function_exists('saas_api_handle_post')) {
    function saas_api_handle_post(mysqli $controlConn, string $tenantSlug, int $tenantId): void
    {
        $action = strtolower(trim((string)saas_api_request_value('action', '')));
        if ($action === '') {
            saas_api_json(400, ['ok' => false, 'error' => 'missing_action']);
        }

        if (in_array($action, ['recalculate_subscriptions', 'create_subscription_invoice'], true)) {
            $tenant = saas_api_pick_tenant($controlConn, $tenantSlug, $tenantId);
            if (!$tenant) {
                saas_api_json(404, ['ok' => false, 'error' => 'tenant_not_found']);
            }
            $tenant = saas_api_sanitize_tenant($tenant);

            if ($action === 'recalculate_subscriptions') {
                $updated = function_exists('saas_recalculate_tenant_subscriptions')
                    ? saas_recalculate_tenant_subscriptions($controlConn, (int)$tenant['id'])
                    : 0;
                $tenantFresh = saas_api_pick_tenant($controlConn, (string)$tenant['tenant_slug'], (int)$tenant['id']);
                saas_api_json(200, [
                    'ok' => true,
                    'action' => 'recalculate_subscriptions',
                    'tenant' => saas_api_sanitize_tenant($tenantFresh ?: $tenant),
                    'updated_subscriptions' => $updated,
                ]);
            }

            $subscriptionId = max(0, (int)saas_api_request_value('subscription_id', 0));
            $subscription = null;
            if ($subscriptionId > 0) {
                $subscription = function_exists('saas_fetch_subscription_snapshot')
                    ? saas_fetch_subscription_snapshot($controlConn, $subscriptionId)
                    : null;
                if (!is_array($subscription) || (int)($subscription['tenant_id'] ?? 0) !== (int)$tenant['id']) {
                    saas_api_json(404, ['ok' => false, 'error' => 'subscription_not_found']);
                }
            } else {
                $stmt = $controlConn->prepare("
                    SELECT * FROM saas_subscriptions
                    WHERE tenant_id = ? AND status IN ('active', 'past_due')
                    ORDER BY CASE WHEN status = 'active' THEN 1 WHEN status = 'past_due' THEN 2 ELSE 9 END, id DESC
                    LIMIT 1
                ");
                $stmt->bind_param('i', $tenant['id']);
                $stmt->execute();
                $subscription = $stmt->get_result()->fetch_assoc() ?: null;
                $stmt->close();
                if (!is_array($subscription)) {
                    saas_api_json(404, ['ok' => false, 'error' => 'subscription_not_found']);
                }
            }

            $created = function_exists('saas_generate_subscription_invoice')
                ? saas_generate_subscription_invoice($controlConn, $subscription, 'API')
                : ['ok' => false, 'reason' => 'helper_missing'];
            $invoice = !empty($created['invoice_id']) && function_exists('saas_fetch_invoice_snapshot')
                ? saas_fetch_invoice_snapshot($controlConn, (int)$created['invoice_id'])
                : null;

            saas_api_json(!empty($created['ok']) ? 200 : 422, [
                'ok' => !empty($created['ok']),
                'action' => 'create_subscription_invoice',
                'tenant' => $tenant,
                'subscription' => $subscription,
                'result' => $created,
                'invoice' => $invoice,
            ]);
        }

        if (in_array($action, ['mark_invoice_paid', 'reopen_invoice'], true)) {
            $invoiceId = max(0, (int)saas_api_request_value('invoice_id', 0));
            $invoiceToken = trim((string)saas_api_request_value('invoice_token', ''));
            $invoice = saas_api_find_invoice($controlConn, $invoiceId, $invoiceToken);
            if (!is_array($invoice)) {
                saas_api_json(404, ['ok' => false, 'error' => 'invoice_not_found']);
            }

            $tenant = saas_api_pick_tenant($controlConn, (string)($invoice['tenant_slug'] ?? ''), (int)($invoice['tenant_id'] ?? 0));
            $tenant = is_array($tenant) ? saas_api_sanitize_tenant($tenant) : null;

            if ($action === 'mark_invoice_paid') {
                $paidAt = trim((string)saas_api_request_value('paid_at', ''));
                $paymentRef = trim((string)saas_api_request_value('payment_ref', ''));
                $paymentMethod = trim((string)saas_api_request_value('payment_method', 'manual'));
                $paymentNotes = trim((string)saas_api_request_value('payment_notes', ''));
                $marked = function_exists('saas_mark_subscription_invoice_paid')
                    ? saas_mark_subscription_invoice_paid(
                        $controlConn,
                        (int)$invoice['id'],
                        (int)$invoice['tenant_id'],
                        $paidAt !== '' ? $paidAt : date('Y-m-d H:i:s'),
                        $paymentRef,
                        $paymentMethod,
                        $paymentNotes
                    )
                    : false;
                $freshInvoice = function_exists('saas_fetch_invoice_snapshot') ? saas_fetch_invoice_snapshot($controlConn, (int)$invoice['id']) : $invoice;
                saas_api_json($marked ? 200 : 422, [
                    'ok' => $marked,
                    'action' => 'mark_invoice_paid',
                    'tenant' => $tenant,
                    'invoice' => $freshInvoice,
                ]);
            }

            $reopened = function_exists('saas_reopen_subscription_invoice')
                ? saas_reopen_subscription_invoice($controlConn, (int)$invoice['id'], (int)$invoice['tenant_id'])
                : false;
            $freshInvoice = function_exists('saas_fetch_invoice_snapshot') ? saas_fetch_invoice_snapshot($controlConn, (int)$invoice['id']) : $invoice;
            saas_api_json($reopened ? 200 : 422, [
                'ok' => $reopened,
                'action' => 'reopen_invoice',
                'tenant' => $tenant,
                'invoice' => $freshInvoice,
            ]);
        }

        saas_api_json(400, ['ok' => false, 'error' => 'unsupported_action']);
    }
}

if (!function_exists('saas_api_handle_get')) {
    function saas_api_handle_get(mysqli $controlConn, string $resource, string $tenantSlug, int $tenantId, int $limit, string $statusFilter, string $search, bool $includeHealth): void
    {
        if ($resource === 'meta') {
            saas_api_json(200, [
                'ok' => true,
                'resource' => 'meta',
                'service' => 'saas_api',
                'version' => function_exists('app_system_version') ? app_system_version() : 'unknown',
                'time' => date('c'),
                'actions' => [
                    'get' => ['meta', 'tenants', 'tenant', 'subscriptions', 'invoices', 'payments'],
                    'post' => ['recalculate_subscriptions', 'create_subscription_invoice', 'mark_invoice_paid', 'reopen_invoice'],
                ],
            ]);
        }

        if ($resource === 'tenant') {
            $tenant = saas_api_pick_tenant($controlConn, $tenantSlug, $tenantId);
            if (!$tenant) {
                saas_api_json(404, ['ok' => false, 'error' => 'tenant_not_found']);
            }
            $tenantRaw = $tenant;
            $tenant = saas_api_sanitize_tenant($tenant);
            if ($includeHealth && function_exists('app_saas_tenant_health_check')) {
                $tenant['health'] = saas_api_sanitize_health(app_saas_tenant_health_check($controlConn, $tenantRaw));
            }
            saas_api_json(200, ['ok' => true, 'resource' => 'tenant', 'tenant' => $tenant]);
        }

        $map = [
            'tenants' => ['table' => 'saas_tenants', 'tenantScoped' => false],
            'subscriptions' => ['table' => 'saas_subscriptions', 'tenantScoped' => true],
            'invoices' => ['table' => 'saas_subscription_invoices', 'tenantScoped' => true],
            'payments' => ['table' => 'saas_subscription_invoice_payments', 'tenantScoped' => true],
        ];
        if (!isset($map[$resource])) {
            saas_api_json(404, ['ok' => false, 'error' => 'unknown_resource']);
        }

        $sql = "SELECT * FROM " . $map[$resource]['table'];
        $conditions = [];
        $types = '';
        $params = [];

        if (!empty($map[$resource]['tenantScoped'])) {
            if ($tenantId > 0) {
                $conditions[] = "tenant_id = ?";
                $types .= 'i';
                $params[] = $tenantId;
            } elseif ($tenantSlug !== '') {
                $tenant = saas_api_pick_tenant($controlConn, $tenantSlug, 0);
                if (!$tenant) {
                    saas_api_json(404, ['ok' => false, 'error' => 'tenant_not_found']);
                }
                $conditions[] = "tenant_id = ?";
                $types .= 'i';
                $params[] = (int)$tenant['id'];
            }
        } else {
            if ($statusFilter !== '') {
                $conditions[] = "LOWER(status) = ?";
                $types .= 's';
                $params[] = $statusFilter;
            }
            if ($search !== '') {
                $conditions[] = "(LOWER(tenant_name) LIKE ? OR LOWER(tenant_slug) LIKE ? OR LOWER(legal_name) LIKE ?)";
                $searchLike = '%' . $search . '%';
                $types .= 'sss';
                $params[] = $searchLike;
                $params[] = $searchLike;
                $params[] = $searchLike;
            }
        }
        if (!empty($map[$resource]['tenantScoped']) && $statusFilter !== '') {
            $conditions[] = "LOWER(status) = ?";
            $types .= 's';
            $params[] = $statusFilter;
        }
        if (!empty($conditions)) {
            $sql .= ' WHERE ' . implode(' AND ', $conditions);
        }
        $sql .= ' ORDER BY id DESC LIMIT ' . $limit;
        $stmt = $controlConn->prepare($sql);
        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            if ($resource === 'tenants') {
                $rowRaw = $row;
                if (function_exists('saas_tenant_billing_portal_url')) {
                    $row['billing_portal_url'] = saas_tenant_billing_portal_url($row);
                    $rowRaw = $row;
                }
                $row = saas_api_sanitize_tenant($row);
                if ($includeHealth && function_exists('app_saas_tenant_health_check')) {
                    $row['health'] = saas_api_sanitize_health(app_saas_tenant_health_check($controlConn, $rowRaw));
                }
            } elseif ($resource === 'invoices' && function_exists('saas_issue_subscription_invoice_access')) {
                $row = saas_issue_subscription_invoice_access($controlConn, $row, $controlConn);
            }
            $rows[] = $row;
        }
        $stmt->close();
        saas_api_json(200, ['ok' => true, 'resource' => $resource, 'count' => count($rows), 'items' => $rows]);
    }
}

if (!function_exists('saas_api_handle_request')) {
    function saas_api_handle_request(mysqli $controlConn, string $method): void
    {
        $resource = strtolower(trim((string)saas_api_request_value('resource', 'meta')));
        $tenantId = max(0, (int)saas_api_request_value('tenant_id', 0));
        $tenantSlug = trim((string)saas_api_request_value('tenant_slug', ''));
        $limit = max(1, min(100, (int)saas_api_request_value('limit', 25)));
        $statusFilter = strtolower(trim((string)saas_api_request_value('status', '')));
        $search = strtolower(trim((string)saas_api_request_value('search', '')));
        $includeHealth = !empty(saas_api_request_value('include_health', ''));

        if ($method === 'POST') {
            saas_api_handle_post($controlConn, $tenantSlug, $tenantId);
        }
        saas_api_handle_get($controlConn, $resource, $tenantSlug, $tenantId, $limit, $statusFilter, $search, $includeHealth);
    }
}
