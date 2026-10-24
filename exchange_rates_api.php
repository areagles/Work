<?php

require 'auth.php';
require 'config.php';
require_once __DIR__ . '/modules/finance/fx_widget_runtime.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'unauthorized'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$forceRefresh = isset($_GET['refresh']) && $_GET['refresh'] === '1';
$payload = app_fx_widget_payload($conn, $forceRefresh);

echo json_encode([
    'ok' => true,
    'data' => $payload,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
