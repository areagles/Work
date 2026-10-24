<?php
// client_portal/api/logout.php
require __DIR__ . '/bootstrap.php';

api_require_post_csrf();
api_start_session();
api_rate_limit_or_fail('client_portal_logout', 20, 300, api_rate_limit_scope('logout'));
$clientId = (int)($_SESSION[APP_CLIENT_SESSION_ID_KEY] ?? $_SESSION['client_id'] ?? 0);
$clientName = (string)($_SESSION[APP_CLIENT_SESSION_NAME_KEY] ?? $_SESSION['client_name'] ?? 'client');
if (isset($GLOBALS['conn']) && $GLOBALS['conn'] instanceof mysqli && $clientId > 0) {
    app_audit_log_add($GLOBALS['conn'], 'auth.client_api_logout', [
        'user_id' => $clientId,
        'actor_type' => 'client',
        'actor_name' => $clientName,
        'entity_type' => 'client',
        'entity_key' => (string)$clientId,
        'details' => ['mode' => 'client_api'],
    ]);
}
api_clear_client_session();

// If no admin session exists in same cookie, destroy it entirely.
$hasAdminSession = isset($_SESSION['user_id']) && (int)$_SESSION['user_id'] > 0;
if (!$hasAdminSession) {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'] ?? '', (bool)$params['secure'], (bool)$params['httponly']);
    }
    session_destroy();
}

api_json([
    'status' => 'success',
    'message' => 'تم تسجيل الخروج بنجاح',
    'redirect' => '../login.html?logged_out=1',
]);
