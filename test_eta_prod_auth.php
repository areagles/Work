<?php
require __DIR__ . '/config.php';
app_start_session();
if (!in_array(app_runtime_host(), ['localhost', '127.0.0.1'], true) && strtolower((string)($_SESSION['role'] ?? '')) !== 'admin') {
    http_response_code(404);
    exit;
}
$settings = app_eta_einvoice_settings($conn);
$res = app_eta_einvoice_request_access_token($conn, true);
var_export([
  'environment' => $settings['environment'],
  'base_url' => $settings['base_url'],
  'token_url' => $settings['token_url'],
  'result' => $res,
]);
