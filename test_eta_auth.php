<?php
require __DIR__ . '/config.php';
app_start_session();
if (!in_array(app_runtime_host(), ['localhost', '127.0.0.1'], true) && strtolower((string)($_SESSION['role'] ?? '')) !== 'admin') {
    http_response_code(404);
    exit;
}
var_export(app_eta_einvoice_test_connection($conn));
