<?php
require 'config.php';
require_once __DIR__ . '/modules/saas/paymob_callback_runtime.php';

app_start_session();

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

$callbackState = saas_paymob_callback_prepare($controlConn);
if (!empty($callbackState['redirect_url']) && !headers_sent()) {
    header('Location: ' . (string)$callbackState['redirect_url']);
    exit;
}

http_response_code((int)($callbackState['http_code'] ?? 200));
header('Content-Type: application/json; charset=UTF-8');
echo json_encode((array)($callbackState['result'] ?? []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
