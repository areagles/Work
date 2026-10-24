<?php
require_once __DIR__ . '/config.php';
app_start_session();
if (!in_array(app_runtime_host(), ['localhost', '127.0.0.1'], true) && strtolower((string)($_SESSION['role'] ?? '')) !== 'admin') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/modules/saas/webhook_test_receiver_runtime.php';

saas_webhook_test_receiver_handle();
