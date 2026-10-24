<?php
// logout.php
require_once __DIR__ . '/modules/security/env_runtime.php';
require_once __DIR__ . '/modules/security/core_runtime.php';
app_start_session();

$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params['path'] ?? '/',
        $params['domain'] ?? '',
        (bool)($params['secure'] ?? false),
        (bool)($params['httponly'] ?? true)
    );
}
session_destroy();
app_safe_redirect('login.php', 'login.php');
?>
