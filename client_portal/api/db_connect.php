<?php
// client_portal/api/db_connect.php
error_reporting(0);
ini_set('display_errors', '0');

if (!function_exists('api_first_env')) {
    function api_first_env(array $keys, string $default = ''): string
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

$dbHost = api_first_env(['DB_HOST', 'MYSQL_HOST', 'DB_SERVER', 'DBHOST'], 'localhost');
$dbName = api_first_env(['DB_NAME', 'MYSQL_DATABASE', 'MYSQL_DB', 'DB_DATABASE', 'DATABASE_NAME'], '');
$dbUser = api_first_env(['DB_USER', 'MYSQL_USER', 'DB_USERNAME', 'DATABASE_USER'], '');
$dbPass = api_first_env(['DB_PASS', 'MYSQL_PASSWORD', 'MYSQL_PASS', 'DB_PASSWORD', 'DATABASE_PASSWORD'], '');
$dbPort = (int)api_first_env(['DB_PORT', 'MYSQL_PORT', 'DATABASE_PORT'], '3306');
$dbSocket = api_first_env(['DB_SOCKET', 'MYSQL_SOCKET'], '');

$dsn = $dbSocket !== ''
    ? "mysql:unix_socket={$dbSocket};dbname={$dbName};charset=utf8mb4"
    : "mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset=utf8mb4";

$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
];

try {
    $pdo = new PDO($dsn, $dbUser, $dbPass, $options);
} catch (Throwable $e) {
    error_log('client_portal db_connect PDO error: ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['status' => 'error', 'message' => 'فشل اتصال قاعدة البيانات'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
