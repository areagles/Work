<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "forbidden\n";
    exit;
}

require_once __DIR__ . '/../config.php';

$sync = app_license_sync_remote($conn, true);
$status = app_license_status($conn, false);
$cloudSync = app_cloud_sync_run($conn, false);

$line = sprintf(
    "[%s] license_sync_ok=%s license_sync_reason=%s allowed=%s plan=%s status=%s check_at=%s error=%s cloud_sync_ok=%s cloud_sync_reason=%s",
    date('Y-m-d H:i:s'),
    !empty($sync['ok']) ? '1' : '0',
    (string)($sync['reason'] ?? ''),
    !empty($status['allowed']) ? '1' : '0',
    (string)($status['plan'] ?? ''),
    (string)($status['status'] ?? ''),
    (string)($status['last_check_at'] ?? ''),
    (string)($status['last_error'] ?? ''),
    !empty($cloudSync['ok']) ? '1' : (!empty($cloudSync['skipped']) ? 'skip' : '0'),
    (string)($cloudSync['reason'] ?? '')
);

echo $line . PHP_EOL;
