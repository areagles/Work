<?php
// config.php
// Central configuration and DB bootstrap.

if (defined('APP_CONFIG_BOOTSTRAPPED')) {
    return;
}
define('APP_CONFIG_BOOTSTRAPPED', true);

// Guard against old PHP versions that cannot parse project files.
// Keep this block syntax-compatible with old runtimes.
if (version_compare(PHP_VERSION, '7.1.0', '<')) {
    if (!headers_sent()) {
        header('Content-Type: text/html; charset=UTF-8');
        http_response_code(500);
    }
    echo '⚠️ هذا النظام يحتاج PHP 7.1 أو أحدث. النسخة الحالية: ' . htmlspecialchars(PHP_VERSION, ENT_QUOTES, 'UTF-8');
    exit;
}

// Optional runtime debugging (set APP_DEBUG=1 in .app_env).
$appDebugRaw = getenv('APP_DEBUG');
if ($appDebugRaw === false && is_file(__DIR__ . '/.app_env')) {
    // Fast lightweight parse for APP_DEBUG only (before requiring security.php).
    $lines = @file(__DIR__ . '/.app_env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (is_array($lines)) {
        foreach ($lines as $line) {
            $line = trim((string)$line);
            if ($line === '' || strpos($line, '#') === 0) {
                continue;
            }
            if (strpos($line, 'APP_DEBUG=') === 0) {
                $appDebugRaw = trim(substr($line, 10));
                $appDebugRaw = trim($appDebugRaw, " \t\n\r\0\x0B\"'");
                break;
            }
        }
    }
}
if ((string)$appDebugRaw === '1') {
    @ini_set('display_errors', '1');
    @ini_set('display_startup_errors', '1');
    error_reporting(E_ALL);
}

require_once __DIR__ . '/security.php';
require_once __DIR__ . '/saas.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$autoSystemUrl = (app_is_https() ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');

if (!defined('APP_LEGACY_INVOICE_SECRET')) {
    // Backward compatibility for previously shared invoice links.
    define('APP_LEGACY_INVOICE_SECRET', trim((string)app_env('APP_LEGACY_INVOICE_SECRET', '')));
}

if (!function_exists('app_first_env')) {
    function app_first_env(array $keys, string $default = ''): string
    {
        foreach ($keys as $key) {
            $value = trim((string)app_env((string)$key, ''));
            if ($value !== '') {
                return $value;
            }
        }
        return $default;
    }
}

$servername = app_first_env(['DB_HOST', 'MYSQL_HOST', 'DB_SERVER', 'DBHOST'], 'localhost');
$username = app_first_env(['DB_USER', 'MYSQL_USER', 'DB_USERNAME', 'DATABASE_USER'], '');
$password = app_first_env(['DB_PASS', 'MYSQL_PASSWORD', 'MYSQL_PASS', 'DB_PASSWORD', 'DATABASE_PASSWORD'], '');
$dbname = app_first_env(['DB_NAME', 'MYSQL_DATABASE', 'MYSQL_DB', 'DB_DATABASE', 'DATABASE_NAME'], '');
$dbPort = (int)app_first_env(['DB_PORT', 'MYSQL_PORT', 'DATABASE_PORT'], '3306');
$dbSocket = app_first_env(['DB_SOCKET', 'MYSQL_SOCKET'], '');

if (($username === '' || $dbname === '') && app_env('DATABASE_URL', '') !== '') {
    $dbUrl = trim((string)app_env('DATABASE_URL', ''));
    $parts = parse_url($dbUrl);
    if (is_array($parts)) {
        if ($servername === 'localhost' && !empty($parts['host'])) {
            $servername = (string)$parts['host'];
        }
        if ($dbPort <= 0 && !empty($parts['port'])) {
            $dbPort = (int)$parts['port'];
        }
        if ($username === '' && isset($parts['user'])) {
            $username = (string)$parts['user'];
        }
        if ($password === '' && isset($parts['pass'])) {
            $password = (string)$parts['pass'];
        }
        if ($dbname === '' && !empty($parts['path'])) {
            $dbname = ltrim((string)$parts['path'], '/');
        }
    }
}

$saasBootstrap = app_saas_bootstrap_runtime([
    'host' => $servername,
    'user' => $username,
    'pass' => $password,
    'name' => $dbname,
    'port' => $dbPort,
    'socket' => $dbSocket,
], $autoSystemUrl);

if (!empty($saasBootstrap['resolved']) && is_array($saasBootstrap['db'] ?? null)) {
    $servername = (string)($saasBootstrap['db']['host'] ?? $servername);
    $username = (string)($saasBootstrap['db']['user'] ?? $username);
    $password = (string)($saasBootstrap['db']['pass'] ?? $password);
    $dbname = (string)($saasBootstrap['db']['name'] ?? $dbname);
    $dbPort = (int)($saasBootstrap['db']['port'] ?? $dbPort);
    $dbSocket = (string)($saasBootstrap['db']['socket'] ?? $dbSocket);
}

if (!defined('SYSTEM_URL')) {
    $systemUrlFromSaas = trim((string)($saasBootstrap['system_url'] ?? ''));
    if ($systemUrlFromSaas === '') {
        $systemUrlFromSaas = rtrim((string)app_env('SYSTEM_URL', $autoSystemUrl), '/');
    }
    define('SYSTEM_URL', rtrim($systemUrlFromSaas, '/'));
}

$saasTenantStatus = trim((string)($saasBootstrap['tenant']['status'] ?? ''));
$allowUnresolvedSaasRuntime = app_is_owner_hub() || app_is_saas_gateway();
if (!empty($saasBootstrap['enabled']) && empty($saasBootstrap['resolved']) && !$allowUnresolvedSaasRuntime) {
    if (!headers_sent()) {
        http_response_code(404);
        header('Content-Type: text/html; charset=UTF-8');
    }
    echo '<!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>المستأجر غير موجود</title><style>body{margin:0;background:#090909;color:#f2f2f2;font-family:Cairo,Tahoma,Arial,sans-serif;display:flex;min-height:100vh;align-items:center;justify-content:center;padding:24px}.card{max-width:620px;width:100%;background:#141414;border:1px solid #2d2d2d;border-radius:16px;padding:24px;box-shadow:0 14px 36px rgba(0,0,0,.32)}h1{margin:0 0 10px;font-size:1.5rem;color:#d4af37}p{margin:0;color:#ccc;line-height:1.8}</style></head><body><div class="card"><h1>تعذر تحديد مساحة العمل</h1><p>هذا الدومين غير مرتبط بأي مستأجر داخل منصة SaaS الحالية.</p></div></body></html>';
    exit;
}
if (!empty($saasBootstrap['resolved']) && $saasTenantStatus !== '' && $saasTenantStatus !== 'active') {
    if (!headers_sent()) {
        http_response_code(423);
        header('Content-Type: text/html; charset=UTF-8');
    }
    echo '<!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>المستأجر موقوف</title><style>body{margin:0;background:#090909;color:#f2f2f2;font-family:Cairo,Tahoma,Arial,sans-serif;display:flex;min-height:100vh;align-items:center;justify-content:center;padding:24px}.card{max-width:620px;width:100%;background:#141414;border:1px solid #2d2d2d;border-radius:16px;padding:24px;box-shadow:0 14px 36px rgba(0,0,0,.32)}h1{margin:0 0 10px;font-size:1.5rem;color:#d4af37}p{margin:0;color:#ccc;line-height:1.8}</style></head><body><div class="card"><h1>الوصول إلى المستأجر متوقف</h1><p>حالة هذا المستأجر الحالية: ' . app_h($saasTenantStatus) . '.</p></div></body></html>';
    exit;
}
$debugDb = app_env('APP_DEBUG_DB', '0') === '1';
$isInstallMode = (basename((string)($_SERVER['PHP_SELF'] ?? '')) === 'install.php') || app_env('APP_INSTALL_MODE', '0') === '1';
$currentScriptName = basename((string)($_SERVER['PHP_SELF'] ?? ''));
$isLightBootstrapRoute = in_array($currentScriptName, [
    'login.php',
    'logout.php',
    'forgot_password.php',
    'manifest.php',
], true);
$installLockPath = __DIR__ . '/.installed_lock';
$legacyInstallLockPath = __DIR__ . '/installed_lock.txt';
$isInstalled = is_file($installLockPath) || is_file($legacyInstallLockPath);
$installerPath = __DIR__ . '/install.php';
$installerAvailable = is_file($installerPath);

if ($username === '' || $dbname === '') {
    error_log('Database configuration is missing: DB_USER/DB_NAME (or MYSQL_*/DB_DATABASE/DB_USERNAME/DATABASE_URL)');
    if (!$isInstallMode && !$isInstalled && $installerAvailable && PHP_SAPI !== 'cli') {
        header('Location: install.php');
        exit;
    }
    die('⚠️ نأسف، النظام في وضع الصيانة حالياً (إعدادات قاعدة البيانات غير مكتملة).');
}

try {
    $conn = ($dbSocket !== '')
        ? new mysqli($servername, $username, $password, $dbname, $dbPort, $dbSocket)
        : new mysqli($servername, $username, $password, $dbname, $dbPort);
    $conn->set_charset('utf8mb4');
    app_initialize_system_settings($conn);
    app_ensure_users_core_schema($conn);
    if (!$isLightBootstrapRoute) {
        app_ensure_pricing_records_schema($conn);
        app_ensure_quotes_schema($conn);
        app_ensure_suppliers_schema($conn);
        app_ensure_payroll_schema($conn);
        app_ensure_financial_review_schema($conn);
        app_ensure_job_workflow_schema($conn);
        if (function_exists('app_ensure_job_stage_data_schema')) {
            app_ensure_job_stage_data_schema($conn);
        }
        app_ensure_internal_chat_schema($conn);
        app_ensure_job_assets_schema($conn);
        app_ensure_social_schema($conn);
        app_ensure_purchase_returns_schema($conn);
        if (function_exists('app_ensure_sales_returns_schema')) {
            app_ensure_sales_returns_schema($conn);
        }
        app_initialize_access_control($conn);
        app_initialize_customization_data($conn);
    }
    $appTimezone = app_setting_get($conn, 'timezone', 'Africa/Cairo');
    if (!@date_default_timezone_set($appTimezone)) {
        date_default_timezone_set('Africa/Cairo');
    }
} catch (mysqli_sql_exception $e) {
    error_log('Connection failed: ' . $e->getMessage());
    if (!$isInstallMode && !$isInstalled && $installerAvailable && PHP_SAPI !== 'cli') {
        header('Location: install.php?db_error=1');
        exit;
    }
    if ($debugDb) {
        die('⚠️ نأسف، النظام في وضع الصيانة حالياً (خطأ اتصال): ' . app_h($e->getMessage()));
    }
    die('⚠️ نأسف، النظام في وضع الصيانة حالياً (خطأ اتصال).');
}
