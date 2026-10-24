<?php
require __DIR__ . '/config.php';
app_start_session();
if (!in_array(app_runtime_host(), ['localhost', '127.0.0.1'], true) && strtolower((string)($_SESSION['role'] ?? '')) !== 'admin') {
    http_response_code(404);
    exit;
}
$token = app_eta_einvoice_request_access_token($conn, true);
var_export(['token_ok' => $token['ok'] ?? false, 'env' => app_eta_einvoice_settings($conn)['environment'] ?? '', 'base' => app_eta_einvoice_settings($conn)['base_url'] ?? '']);
echo PHP_EOL . "===FETCH===\n";
var_export(app_eta_einvoice_fetch_published_codes($conn, 'EGS', ['OnlyActive' => true, 'Ps' => 5, 'Pn' => 1]));
