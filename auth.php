<?php
// auth.php - session/auth guard
require_once __DIR__ . '/config.php';
app_start_session();

// Allow public client-portal entry points even if this guard is auto-prepended by hosting config.
$requestPath = strtolower((string)parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH));
if ($requestPath !== '') {
    $publicPortalPaths = [
        '/client_portal/login.php',
        '/client_portal/login.html',
        '/client_portal/register.php',
        '/client_portal/register.html',
        '/client_portal/index.php',
    ];
    if (in_array($requestPath, $publicPortalPaths, true)) {
        return;
    }
}

if (app_is_saas_gateway() && app_current_tenant_id() <= 0) {
    session_unset();
    session_destroy();
    app_start_session();
    app_safe_redirect('login.php');
}

if (!isset($_SESSION['user_id'])) {
    app_safe_redirect('login.php');
}

if (app_saas_mode_enabled()) {
    $sessionTenantId = (int)($_SESSION['tenant_id'] ?? 0);
    $runtimeTenantId = app_current_tenant_id();
    if ($runtimeTenantId > 0 && $sessionTenantId !== $runtimeTenantId) {
        session_unset();
        session_destroy();
        app_start_session();
        app_safe_redirect('login.php');
    }
}

$sessionUserId = (int)($_SESSION['user_id'] ?? 0);
if ($sessionUserId > 0) {
    $userGuardFields = 'id';
    if (app_table_has_column($conn, 'users', 'is_active')) {
        $userGuardFields .= ', is_active';
    }
    if (app_table_has_column($conn, 'users', 'archived_at')) {
        $userGuardFields .= ', archived_at';
    }
    $stmtAuthUser = $conn->prepare("SELECT {$userGuardFields} FROM users WHERE id = ? LIMIT 1");
    $stmtAuthUser->bind_param('i', $sessionUserId);
    $stmtAuthUser->execute();
    $authUser = $stmtAuthUser->get_result()->fetch_assoc();
    $stmtAuthUser->close();
    if (!$authUser || !app_user_is_active_record($authUser)) {
        session_unset();
        session_destroy();
        app_start_session();
        app_safe_redirect('login.php?disabled=1');
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!app_verify_csrf($_POST['_csrf_token'] ?? '')) {
        http_response_code(403);
        die('Invalid CSRF token');
    }
}

$currentPage = basename((string)($_SERVER['PHP_SELF'] ?? ''));
$licenseBypassPages = ['license_center.php', 'cloud_bridge.php', 'system_status.php', 'logout.php'];
if (!in_array($currentPage, $licenseBypassPages, true)) {
    $license = app_license_status($conn, true);
    if (empty($license['allowed'])) {
        $role = strtolower((string)($_SESSION['role'] ?? ''));
        if ($role === 'admin') {
            app_safe_redirect('license_center.php?locked=1');
        }

        http_response_code(423);
        $isEnglish = app_current_lang($conn) === 'en';
        $title = $isEnglish ? 'License is inactive' : 'الترخيص غير مفعل';
        $text = $isEnglish
            ? 'System access is temporarily paused. Contact the administrator to reactivate your license.'
            : 'تم إيقاف الوصول للنظام مؤقتاً. برجاء الرجوع لمدير النظام لإعادة تفعيل الترخيص.';
        $logoutLabel = $isEnglish ? 'Logout' : 'تسجيل خروج';
        echo '<!doctype html><html lang="' . ($isEnglish ? 'en' : 'ar') . '" dir="' . ($isEnglish ? 'ltr' : 'rtl') . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'
            . app_h($title)
            . '</title><style>body{margin:0;background:#090909;color:#f2f2f2;font-family:Tahoma,Arial,sans-serif;display:flex;min-height:100vh;align-items:center;justify-content:center;padding:24px}.card{max-width:560px;width:100%;background:#141414;border:1px solid #2d2d2d;border-radius:16px;padding:24px;box-shadow:0 14px 36px rgba(0,0,0,.32)}h1{margin:0 0 10px;font-size:1.5rem;color:#d4af37}p{margin:0 0 20px;color:#ccc;line-height:1.7}a{display:inline-block;text-decoration:none;background:#d4af37;color:#111;padding:10px 18px;border-radius:10px;font-weight:700}</style></head><body><div class="card"><h1>'
            . app_h($title)
            . '</h1><p>'
            . app_h($text)
            . '</p><a href="logout.php">'
            . app_h($logoutLabel)
            . '</a></div></body></html>';
        exit;
    }
}
