<?php

if (!function_exists('saas_webhook_test_receiver_headers')) {
    function saas_webhook_test_receiver_headers(): array
    {
        $headers = [];
        if (function_exists('getallheaders')) {
            $headers = getallheaders();
        } else {
            foreach ($_SERVER as $key => $value) {
                if (strpos($key, 'HTTP_') === 0) {
                    $name = str_replace('_', '-', substr($key, 5));
                    $headers[$name] = (string)$value;
                }
            }
        }
        return $headers;
    }
}

if (!function_exists('saas_webhook_test_receiver_payload')) {
    function saas_webhook_test_receiver_payload(string $rawBody): array
    {
        if (!empty($_POST)) {
            return $_POST;
        }
        if (trim($rawBody) !== '') {
            $decoded = json_decode($rawBody, true);
            if (is_array($decoded)) {
                return $decoded;
            }
            return ['raw' => $rawBody];
        }
        return $_GET;
    }
}

if (!function_exists('saas_webhook_test_receiver_handle')) {
    function saas_webhook_test_receiver_handle(): void
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

        $receiverRate = function_exists('app_rate_limit_check')
            ? app_rate_limit_check(
                'saas_webhook_test_receiver',
                function_exists('app_rate_limit_client_key') ? app_rate_limit_client_key('saas_webhook_test_receiver') : ((string)($_SERVER['REMOTE_ADDR'] ?? 'receiver')),
                60,
                600
            )
            : ['allowed' => true, 'limit' => 0, 'remaining' => 0, 'retry_after' => 0];
        if (!$receiverRate['allowed']) {
            header('Retry-After: ' . (int)$receiverRate['retry_after']);
            header('X-RateLimit-Limit: ' . (int)$receiverRate['limit']);
            header('X-RateLimit-Remaining: ' . (int)$receiverRate['remaining']);
            http_response_code(429);
            echo json_encode(['ok' => false, 'error' => 'rate_limited', 'retry_after' => (int)$receiverRate['retry_after']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }
        header('X-RateLimit-Limit: ' . (int)$receiverRate['limit']);
        header('X-RateLimit-Remaining: ' . (int)$receiverRate['remaining']);

        $headers = saas_webhook_test_receiver_headers();
        $rawBody = (string)file_get_contents('php://input');
        if (strlen($rawBody) > 262144) {
            http_response_code(413);
            echo json_encode(['ok' => false, 'error' => 'payload_too_large'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }
        $payload = saas_webhook_test_receiver_payload($rawBody);

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
            $settingsConn = isset($GLOBALS['conn']) && $GLOBALS['conn'] instanceof mysqli ? $GLOBALS['conn'] : $controlConn;
            $gatewaySettings = function_exists('saas_payment_gateway_settings') ? saas_payment_gateway_settings($settingsConn) : [];

            $requiredToken = trim((string)($gatewaySettings['outbound_webhooks_token'] ?? ''));
            $requiredSecret = trim((string)($gatewaySettings['outbound_webhooks_secret'] ?? ''));
            $providedAuthHeader = trim((string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? ''));
            $providedHeaderToken = trim((string)($_SERVER['HTTP_X_API_TOKEN'] ?? ''));
            $providedToken = '';
            if (stripos($providedAuthHeader, 'Bearer ') === 0) {
                $providedToken = trim(substr($providedAuthHeader, 7));
            } elseif ($providedHeaderToken !== '') {
                $providedToken = $providedHeaderToken;
            }

            $signatureHeader = trim((string)($_SERVER['HTTP_X_ARABEAGLES_WEBHOOK_SIGNATURE'] ?? ''));
            $signatureVerified = ($requiredSecret === '');
            if ($requiredSecret !== '' && strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST') {
                $expectedSignature = 'sha256=' . hash_hmac('sha256', $rawBody, $requiredSecret);
                $signatureVerified = $signatureHeader !== '' && hash_equals($expectedSignature, $signatureHeader);
            }

            $tokenVerified = ($requiredToken === '') || ($providedToken !== '' && hash_equals($requiredToken, $providedToken));
            if (!$tokenVerified) {
                if (function_exists('app_saas_log_operation')) {
                    app_saas_log_operation($controlConn, 'integration.webhook_test_rejected', 'رفض Webhook تجريبي', 0, [
                        'reason' => 'invalid_token',
                        'request_method' => (string)($_SERVER['REQUEST_METHOD'] ?? 'POST'),
                    ], 'Webhook Test Receiver');
                }
                $controlConn->close();
                http_response_code(401);
                echo json_encode([
                    'ok' => false,
                    'error' => 'invalid_token',
                    'verification' => ['token_verified' => false, 'signature_verified' => $signatureVerified],
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                exit;
            }

            if (!$signatureVerified) {
                if (function_exists('app_saas_log_operation')) {
                    app_saas_log_operation($controlConn, 'integration.webhook_test_rejected', 'رفض Webhook تجريبي', 0, [
                        'reason' => 'invalid_signature',
                        'request_method' => (string)($_SERVER['REQUEST_METHOD'] ?? 'POST'),
                    ], 'Webhook Test Receiver');
                }
                $controlConn->close();
                http_response_code(403);
                echo json_encode([
                    'ok' => false,
                    'error' => 'invalid_signature',
                    'verification' => ['token_verified' => $tokenVerified, 'signature_verified' => false],
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                exit;
            }

            $inboxId = function_exists('app_saas_store_webhook_test_inbox')
                ? app_saas_store_webhook_test_inbox($controlConn, $headers, $payload, $rawBody)
                : 0;
            if (function_exists('app_saas_log_operation')) {
                app_saas_log_operation($controlConn, 'integration.webhook_test_received', 'استقبال Webhook تجريبي', 0, [
                    'inbox_id' => $inboxId,
                    'request_method' => (string)($_SERVER['REQUEST_METHOD'] ?? 'POST'),
                    'query_string' => (string)($_SERVER['QUERY_STRING'] ?? ''),
                    'token_verified' => $tokenVerified ? 1 : 0,
                    'signature_verified' => $signatureVerified ? 1 : 0,
                ], 'Webhook Test Receiver');
            }
            $controlConn->close();

            echo json_encode([
                'ok' => true,
                'receiver' => 'saas_webhook_test_receiver',
                'stored' => $inboxId > 0,
                'inbox_id' => $inboxId,
                'received_at' => date('c'),
                'verification' => [
                    'token_required' => $requiredToken !== '',
                    'token_verified' => $tokenVerified,
                    'signature_required' => $requiredSecret !== '' && strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST',
                    'signature_verified' => $signatureVerified,
                ],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode([
                'ok' => false,
                'error' => $e->getMessage(),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }
    }
}
