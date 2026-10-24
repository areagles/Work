<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../../config.php';
app_start_session();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'unauthorized'], JSON_UNESCAPED_UNICODE);
    exit;
}

$csrf = (string)($_POST['_csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
if (!app_verify_csrf($csrf)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'invalid_csrf'], JSON_UNESCAPED_UNICODE);
    exit;
}

$reason = trim((string)($_POST['reason'] ?? 'heartbeat'));
$force = in_array($reason, ['online_reconnect', 'manual'], true);
$settings = app_cloud_sync_settings($conn);
if ((int)($settings['auto_online'] ?? 1) !== 1 && $reason !== 'manual') {
    echo json_encode([
        'ok' => false,
        'skipped' => true,
        'reason' => 'auto_online_disabled',
        'applied_rules' => 0,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
$sync = app_cloud_sync_run($conn, $force);

echo json_encode([
    'ok' => !empty($sync['ok']),
    'skipped' => !empty($sync['skipped']),
    'reason' => (string)($sync['reason'] ?? ''),
    'applied_rules' => (int)($sync['applied_rules'] ?? 0),
], JSON_UNESCAPED_UNICODE);
