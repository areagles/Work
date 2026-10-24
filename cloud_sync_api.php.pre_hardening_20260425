<?php
// Cloud sync API endpoint.
require_once __DIR__ . '/config.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$raw = file_get_contents('php://input');
$payload = json_decode((string)$raw, true);
if (!is_array($payload)) {
    $payload = [];
}

$authHeader = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? '');
$bearer = '';
if (stripos($authHeader, 'Bearer ') === 0) {
    $bearer = trim(substr($authHeader, 7));
}

$result = app_cloud_sync_api_exchange($conn, $payload, $bearer);
$httpCode = (int)($result['http_code'] ?? 500);
$body = (isset($result['body']) && is_array($result['body'])) ? $result['body'] : ['ok' => false, 'error' => 'internal_error'];

http_response_code($httpCode);
header('Content-Type: application/json; charset=UTF-8');
echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

