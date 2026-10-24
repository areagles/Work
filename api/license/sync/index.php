<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
app_start_session();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'ok' => false,
        'error' => 'method_not_allowed',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode([
        'ok' => false,
        'error' => 'unauthorized',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$csrf = (string)($_POST['_csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
if (!app_verify_csrf($csrf)) {
    http_response_code(403);
    echo json_encode([
        'ok' => false,
        'error' => 'invalid_csrf',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$sync = app_license_sync_remote($conn, true);
$status = app_license_status($conn, false);

echo json_encode([
    'ok' => true,
    'sync' => [
        'ok' => !empty($sync['ok']),
        'skipped' => !empty($sync['skipped']),
        'reason' => (string)($sync['reason'] ?? ''),
    ],
    'allowed' => !empty($status['allowed']),
    'status' => (string)($status['status'] ?? ''),
    'plan' => (string)($status['plan'] ?? ''),
    'days_left' => isset($status['days_left']) ? (int)$status['days_left'] : null,
    'reason' => (string)($status['reason'] ?? ''),
    'last_error' => (string)($status['last_error'] ?? ''),
    'last_check_at' => (string)($status['last_check_at'] ?? ''),
], JSON_UNESCAPED_UNICODE);

