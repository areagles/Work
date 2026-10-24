<?php

if (!function_exists('app_eta_callback_resolve_root')) {
    function app_eta_callback_resolve_root(): string
    {
        $base = dirname(__DIR__);
        $candidates = [
            $base . '/work',
            $base,
            dirname($base) . '/work',
        ];
        foreach ($candidates as $candidate) {
            if (is_file($candidate . '/config.php') && is_dir($candidate . '/modules')) {
                return $candidate;
            }
        }
        return $base;
    }
}

$appRoot = app_eta_callback_resolve_root();
require_once $appRoot . '/config.php';
require_once $appRoot . '/modules/tax/eta_einvoice_runtime.php';

if (!function_exists('app_eta_callback_json_response')) {
    function app_eta_callback_json_response(int $status, array $payload): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

if (!function_exists('app_eta_callback_apply_cors_headers')) {
    function app_eta_callback_apply_cors_headers(): void
    {
        $origin = trim((string)($_SERVER['HTTP_ORIGIN'] ?? ''));
        $allowedOrigins = [
            'https://profile.eta.gov.eg',
            'https://id.eta.gov.eg',
        ];
        if ($origin !== '' && in_array($origin, $allowedOrigins, true)) {
            header('Access-Control-Allow-Origin: ' . $origin);
            header('Vary: Origin');
        } else {
            header('Access-Control-Allow-Origin: *');
        }
        header('Access-Control-Allow-Methods: GET, HEAD, OPTIONS, PUT, POST');
        header('Access-Control-Allow-Headers: Authorization, Content-Type, Accept-Language, Accept');
        header('Access-Control-Max-Age: 86400');
    }
}

if (!function_exists('app_eta_callback_read_json')) {
    function app_eta_callback_read_json(): array
    {
        $raw = app_eta_callback_request_body();
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }
}

if (!function_exists('app_eta_callback_request_path')) {
    function app_eta_callback_request_path(): string
    {
        $uri = (string)($_SERVER['REQUEST_URI'] ?? '');
        $path = trim((string)parse_url($uri, PHP_URL_PATH), '/');
        $parts = explode('/', $path);
        if (!empty($parts) && $parts[0] === 'eta') {
            array_shift($parts);
        }
        return strtolower(trim(implode('/', $parts), '/'));
    }
}

if (!function_exists('app_eta_callback_request_body')) {
    function app_eta_callback_request_body(): string
    {
        $body = file_get_contents('php://input');
        return is_string($body) ? $body : '';
    }
}

if (!function_exists('app_eta_callback_log_inbound')) {
    function app_eta_callback_log_inbound(mysqli $conn, string $eventType, array $payload = [], string $responseCode = '200'): void
    {
        if (function_exists('app_eta_einvoice_log_sync_event')) {
            app_eta_einvoice_log_sync_event(
                $conn,
                null,
                null,
                $eventType,
                '',
                '',
                $responseCode,
                $payload
            );
        }
    }
}

$settings = app_eta_einvoice_settings($conn);
$endpoint = app_eta_callback_request_path();
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$authHeader = trim((string)($_SERVER['HTTP_AUTHORIZATION'] ?? ''));
$expectedApiKey = trim((string)($settings['callback_api_key'] ?? ''));
$isProbeMethod = in_array($method, ['GET', 'HEAD', 'OPTIONS'], true);

app_eta_callback_apply_cors_headers();

if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($endpoint !== '' && !$isProbeMethod && $expectedApiKey !== '') {
    $expectedHeader = 'ApiKey ' . $expectedApiKey;
    if (!hash_equals($expectedHeader, $authHeader)) {
        app_eta_callback_json_response(401, ['ok' => false, 'error' => 'unauthorized_callback']);
    }
}

if ($endpoint === '') {
    app_eta_callback_json_response(200, [
        'ok' => true,
        'service' => 'eta_callback',
        'available' => ['ping', 'notifications'],
    ]);
}

if ($endpoint === 'ping') {
    $configuredRin = trim((string)($settings['issuer_rin'] ?? ''));
    if ($isProbeMethod) {
        app_eta_callback_log_inbound($conn, 'eta_callback_ping_probe', [
            'method' => $method,
            'auth_present' => $authHeader !== '' ? 1 : 0,
            'user_agent' => (string)($_SERVER['HTTP_USER_AGENT'] ?? ''),
        ]);
        if ($method === 'HEAD') {
            http_response_code(200);
            exit;
        }
        app_eta_callback_json_response(200, [
            'ok' => true,
            'service' => 'eta_ping',
            'rin' => $configuredRin,
        ]);
    }
    if ($method !== 'PUT') {
        app_eta_callback_json_response(405, ['ok' => false, 'error' => 'method_not_allowed']);
    }
    if ($configuredRin === '') {
        app_eta_callback_json_response(503, ['ok' => false, 'error' => 'issuer_rin_missing']);
    }
    $payload = app_eta_callback_read_json();
    $requestRin = preg_replace('/\D+/', '', (string)($payload['rin'] ?? ''));
    if ($requestRin === '') {
        app_eta_callback_json_response(400, ['ok' => false, 'error' => 'rin_missing']);
    }
    if (!hash_equals($configuredRin, $requestRin)) {
        app_eta_callback_json_response(400, ['ok' => false, 'error' => 'rin_mismatch']);
    }
    app_eta_callback_log_inbound($conn, 'eta_callback_ping', [
        'method' => $method,
        'auth_present' => $authHeader !== '' ? 1 : 0,
        'request_rin' => $requestRin,
        'user_agent' => (string)($_SERVER['HTTP_USER_AGENT'] ?? ''),
    ]);
    app_eta_callback_json_response(200, ['rin' => $requestRin]);
}

if (in_array($endpoint, ['notifications', 'notifications/documents', 'notifications/documentpackages'], true)) {
    if ($isProbeMethod) {
        app_eta_callback_log_inbound($conn, 'eta_callback_notification_probe', [
            'endpoint' => $endpoint,
            'method' => $method,
            'auth_present' => $authHeader !== '' ? 1 : 0,
            'user_agent' => (string)($_SERVER['HTTP_USER_AGENT'] ?? ''),
        ]);
        if ($method === 'HEAD') {
            http_response_code(200);
            exit;
        }
        app_eta_callback_json_response(200, [
            'ok' => true,
            'service' => 'eta_notifications',
            'endpoint' => $endpoint,
        ]);
    }
    if ($method !== 'PUT') {
        app_eta_callback_json_response(405, ['ok' => false, 'error' => 'method_not_allowed']);
    }
    $raw = app_eta_callback_request_body();
    $decoded = json_decode($raw, true);
    $payload = is_array($decoded)
        ? $decoded
        : ['raw' => $raw];
    app_eta_callback_log_inbound($conn, 'eta_callback_notification', [
        'endpoint' => $endpoint,
        'method' => $method,
        'payload' => $payload,
        'auth_present' => $authHeader !== '' ? 1 : 0,
    ]);
    app_eta_callback_json_response(200, ['ok' => true, 'received' => true]);
}

app_eta_callback_json_response(404, ['ok' => false, 'error' => 'unknown_endpoint']);
