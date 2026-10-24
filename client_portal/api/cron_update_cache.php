<?php
// client_portal/api/cron_update_cache.php
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/db_connect.php';

$isCli = PHP_SAPI === 'cli';
api_rate_limit_or_fail('client_portal_cron_update_cache', 20, 600, 'client_portal_cron_update_cache');
$requestSecret = trim((string)($_GET['secret_key'] ?? $_POST['secret_key'] ?? ''));
$expectedSecret = trim((string)app_env('APP_PORTAL_CACHE_CRON_SECRET', ''));
if (!$isCli) {
    if ($expectedSecret === '' || $requestSecret === '' || !hash_equals($expectedSecret, $requestSecret)) {
        api_json(['status' => 'error', 'message' => 'Not Found'], 404);
    }
}

try {
    $sql = "UPDATE system_settings 
            SET setting_value = UNIX_TIMESTAMP() 
            WHERE setting_key = 'last_cache_clear'";
            
    $stmt = $pdo->prepare($sql);
    $stmt->execute();

    if (isset($GLOBALS['conn']) && $GLOBALS['conn'] instanceof mysqli) {
        app_audit_log_add($GLOBALS['conn'], 'portal.cache_timestamp_updated', [
            'actor_type' => $isCli ? 'system' : 'api',
            'actor_name' => $isCli ? 'cli_cron' : 'portal_cache_cron',
            'entity_type' => 'portal_cache',
            'entity_key' => 'last_cache_clear',
            'details' => [
                'mode' => $isCli ? 'cli' : 'http',
            ],
        ]);
    }

    if ($isCli) {
        echo "Cache Timestamp Updated Successfully at " . date('Y-m-d H:i:s');
        exit;
    }
    api_json([
        'status' => 'success',
        'message' => 'cache_timestamp_updated',
        'updated_at' => date('c'),
    ]);

} catch (Exception $e) {
    error_log("Cron Error: " . $e->getMessage());
    if ($isCli) {
        fwrite(STDERR, "Cron Error\n");
        exit(1);
    }
    api_json(['status' => 'error', 'message' => 'cache_update_failed'], 500);
}
