<?php
// login.php - (Royal Premium Login V3.1 - Secure & Brute-Force Protected)
require __DIR__ . '/config.php';
app_start_session();
app_handle_lang_switch($conn);

$redirectMode = strtolower(trim((string)($_GET['mode'] ?? '')));
$isSaasGateway = app_is_saas_gateway();
$runtimeTenantId = app_current_tenant_id();
$isGatewayLanding = $isSaasGateway && app_saas_mode_enabled() && $runtimeTenantId <= 0;
$showSaasTenantLookup = $isGatewayLanding;
$sessionTenantId = (int)($_SESSION['tenant_id'] ?? 0);
if (app_saas_mode_enabled() && $runtimeTenantId > 0) {
    if ($sessionTenantId > 0 && $sessionTenantId !== $runtimeTenantId) {
        session_unset();
        session_destroy();
        app_start_session();
    }
    $_SESSION['tenant_id'] = $runtimeTenantId;
    $_SESSION['tenant_slug'] = app_current_tenant_slug();
    $sessionTenantId = $runtimeTenantId;
}
$clientSessionId = (int)($_SESSION['portal_client_id'] ?? $_SESSION['client_id'] ?? 0);
if ($isGatewayLanding && (isset($_SESSION['user_id']) || $clientSessionId > 0 || $sessionTenantId > 0)) {
    session_unset();
    session_destroy();
    app_start_session();
    $clientSessionId = 0;
    $sessionTenantId = 0;
}
if (!$isGatewayLanding && isset($_SESSION['user_id']) && (int)$_SESSION['user_id'] > 0 && $redirectMode !== 'client') {
    app_safe_redirect('dashboard.php');
}
if (!$isGatewayLanding && $clientSessionId > 0 && $redirectMode !== 'admin') {
    app_safe_redirect('client_portal/dashboard.html');
}

$appLang = app_current_lang($conn);
$appDir = app_lang_dir($appLang);
$isEnglish = $appLang === 'en';
$appName = app_setting_get($conn, 'app_name', 'Arab Eagles');
$appLogo = app_brand_logo_path($conn, 'assets/img/Logo.png');
$appNameSafe = app_h($appName !== '' ? $appName : 'Arab Eagles');
$loginUiTheme = app_ui_theme($conn, 0);
$loginUiVars = app_ui_theme_css_vars($loginUiTheme);
$loginGold = app_h($loginUiVars['--ae-gold'] ?? '#d4af37');
$loginGoldDark = app_h($loginUiVars['--ae-gold-deep'] ?? '#b8860b');
$loginTeal = app_h($loginUiVars['--ae-accent-soft'] ?? '#1db7a4');
$loginBg = app_h($loginUiVars['--bg'] ?? '#04070a');
$loginBgAlt = app_h($loginUiVars['--bg-alt'] ?? '#0b1218');
$loginPanel = app_h($loginUiVars['--card-bg'] ?? '#101a23');
$loginPanelStrong = app_h($loginUiVars['--card-strong'] ?? '#131313');
$loginLine = app_h($loginUiVars['--border'] ?? 'rgba(212, 175, 55, 0.26)');
$loginLineSoft = "color-mix(in srgb, {$loginLine} 72%, transparent)";
$loginText = app_h($loginUiVars['--text'] ?? '#f3f6f9');
$loginMuted = app_h($loginUiVars['--muted'] ?? '#a4b0ba');
$langArUrl = app_lang_switch_url('ar');
$langEnUrl = app_lang_switch_url('en');
$licenseEdition = app_license_edition();
$allowClientPortalLogin = !$isSaasGateway && is_file(__DIR__ . '/client_portal/dashboard.html');
$licenseSnapshot = app_license_status($conn, false);
$licenseBlocked = (
    $licenseEdition === 'client'
    && isset($licenseSnapshot['allowed'])
    && empty($licenseSnapshot['allowed'])
);

// --- Brute-Force Protection (unified login) ---
$requestedLoginMode = strtolower(trim((string)($_POST['login_mode'] ?? $_GET['mode'] ?? 'admin')));
$loginMode = ($allowClientPortalLogin && $requestedLoginMode === 'client') ? 'client' : 'admin';
$isClientMode = ($loginMode === 'client');
$attemptsKey = 'login_attempts';
$attemptTimeKey = 'last_attempt_time';
$adminRateState = app_rate_limit_check('admin_login_page', app_rate_limit_client_key($loginMode), 20, 300);
app_rate_limit_emit_headers($adminRateState);

$max_attempts = 5;
$lockout_time = 300; // 5 minutes in seconds
$lockoutRemaining = 0;

if (!$adminRateState['allowed']) {
    $lockoutRemaining = max(0, (int)($adminRateState['retry_after'] ?? 0));
    $error = app_tr(
        'تم تقييد محاولات تسجيل الدخول مؤقتاً. يرجى الانتظار قليلاً ثم إعادة المحاولة.',
        'Login attempts are temporarily rate limited. Please wait a moment and try again.'
    );
} elseif (isset($_SESSION[$attemptsKey]) && (int)$_SESSION[$attemptsKey] >= $max_attempts) {
    $elapsed = time() - (int)($_SESSION[$attemptTimeKey] ?? 0);
    if ($elapsed < $lockout_time) {
        $lockoutRemaining = max(0, $lockout_time - $elapsed);
        $error = app_tr(
            'تم حظر محاولات تسجيل الدخول مؤقتاً. يرجى المحاولة مرة أخرى بعد 5 دقائق.',
            'Too many login attempts. Please try again in 5 minutes.'
        );
    } else {
        // Reset attempts after lockout period
        unset($_SESSION[$attemptsKey], $_SESSION[$attemptTimeKey]);
    }
}
// --- End Protection ---

$error = $error ?? '';
if ($licenseBlocked) {
    $error = app_tr(
        'النظام غير مفعل حالياً. أكمل الربط والتفعيل من نظام المالك ثم أعد المحاولة.',
        'System license is currently inactive. Complete owner-side linking/activation, then try again.'
    );
}
$loginTitle = $isEnglish ? 'Login' : 'تسجيل الدخول';
$txtLoginSubtitle = $isEnglish ? 'Smart Management System' : 'نظام إدارة ذكي';
$txtUsernamePlaceholder = $isEnglish ? 'Username / Phone / Email' : 'اسم المستخدم / الهاتف / البريد';
$txtClientIdentityPlaceholder = $isEnglish ? 'Phone number or email' : 'رقم الهاتف أو البريد الإلكتروني';
$txtPasswordPlaceholder = $isEnglish ? 'Password' : 'كلمة المرور';
$txtUsernameLabel = $isEnglish ? 'Login identity' : 'بيانات الدخول';
$txtClientIdentityLabel = $isEnglish ? 'Phone / Email' : 'الهاتف / البريد';
$txtPasswordLabel = $isEnglish ? 'Password' : 'كلمة المرور';
$txtEnterSystem = $isEnglish ? 'Sign In' : 'دخول للنظام';
$txtEnterClientPortal = $isEnglish ? 'Open Client Portal' : 'دخول بوابة العميل';
$txtLocked = $isEnglish ? 'Temporarily locked' : 'محظور مؤقتاً';
$txtForgotPassword = $isEnglish ? 'Forgot password?' : 'نسيت كلمة المرور؟';
$txtClientRegister = $isEnglish ? 'Create client account' : 'تسجيل عميل جديد';
$txtClientHelp = $isEnglish ? 'Client support' : 'دعم العملاء';
$txtAdminTab = $isEnglish ? 'Admin' : 'الإداري';
$txtClientTab = $isEnglish ? 'Client' : 'العميل';
$txtShowPassword = $isEnglish ? 'Show password' : 'إظهار كلمة المرور';
$txtHidePassword = $isEnglish ? 'Hide password' : 'إخفاء كلمة المرور';
$txtCapsLockOn = $isEnglish ? 'Caps Lock is on.' : 'زر Caps Lock مفعل.';
$txtRemembered = $isEnglish ? 'Last login identity remembered on this device.' : 'آخر بيانات دخول محفوظة على هذا الجهاز.';
$txtAttemptsLeft = $isEnglish ? 'Attempts remaining: %d' : 'المحاولات المتبقية: %d';
$txtLockoutRemaining = $isEnglish ? 'Try again in %d:%02d' : 'أعد المحاولة بعد %d:%02d';
$txtSecureFooter = $isEnglish ? 'Secured by Royal Tech' : 'محمي بواسطة Royal Tech';
$txtWelcomeHeadline = $isEnglish ? 'A sharper way to run your operations.' : 'طريقة أذكى لإدارة عملياتك.';
$txtWelcomeBody = $isEnglish
    ? 'Track jobs, finance, inventory, and client delivery from one secure workspace.'
    : 'تابع أوامر الشغل، المالية، المخزون، وتسليمات العملاء من مساحة عمل واحدة آمنة.';
$txtFeatureOne = $isEnglish ? 'Live visibility on jobs and stages' : 'رؤية مباشرة لحالة العمليات والمراحل';
$txtFeatureTwo = $isEnglish ? 'Connected finance and invoice controls' : 'ربط محكم بين المالية والفواتير';
$txtFeatureThree = $isEnglish ? 'Protected access with activity accountability' : 'دخول آمن مع تتبع مسؤولية التنفيذ';
$txtLanguage = $isEnglish ? 'Language' : 'اللغة';
$txtPanelHint = $isEnglish ? 'Sign in with your account credentials.' : 'سجّل الدخول ببيانات حسابك.';
$txtPanelHintClient = $isEnglish ? 'Sign in with your client account.' : 'سجّل الدخول بحساب العميل.';
$txtNeedHelp = $isEnglish ? 'Need help? Contact system administrator.' : 'تحتاج مساعدة؟ تواصل مع مسؤول النظام.';
$txtTenantLookupTitle = $isEnglish ? 'Tenant access' : 'دخول المستأجر';
$txtTenantLookupHint = $isEnglish ? 'Enter the subscription code, tenant slug, or client domain to open the correct system.' : 'أدخل كود الاشتراك أو slug أو دومين العميل للانتقال إلى نظامه الصحيح.';
$txtTenantLookupLabel = $isEnglish ? 'Tenant code / domain' : 'كود المستأجر / الدومين';
$txtTenantLookupPlaceholder = $isEnglish ? 'sys or sys.areagles.com' : 'مثال: sys أو sys.areagles.com';
$txtTenantLookupSubmit = $isEnglish ? 'Open tenant system' : 'فتح نظام المستأجر';
if ($isGatewayLanding) {
    $loginTitle = $isEnglish ? 'SaaS Login' : 'دخول عملاء SaaS';
    $txtPanelHint = $isEnglish ? 'Enter the tenant code or domain to open the correct subscriber system.' : 'أدخل كود المستأجر أو الدومين للانتقال إلى نظام المشترك الصحيح.';
    $txtNeedHelp = $isEnglish ? 'If the tenant code is unavailable, contact the SaaS administrator.' : 'إذا لم تكن بيانات المستأجر متاحة، تواصل مع مسؤول منصة SaaS.';
}
$identityValue = trim((string)($_POST['login_identity'] ?? ''));
$panelHintCurrent = $txtPanelHint;
$identityLabelCurrent = $txtUsernameLabel;
$identityPlaceholderCurrent = $txtUsernamePlaceholder;
$submitLabelCurrent = $txtEnterSystem;
$forgotHref = 'forgot_password.php';
$forgotTargetAttr = '';

if ($isClientMode) {
    $panelHintCurrent = $txtPanelHintClient;
    $identityLabelCurrent = $txtClientIdentityLabel;
    $identityPlaceholderCurrent = $txtClientIdentityPlaceholder;
    $submitLabelCurrent = $txtEnterClientPortal;
    $forgotHref = 'https://wa.me/201000571057';
    $forgotTargetAttr = ' target="_blank" rel="noopener"';
}

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    (!$licenseBlocked)
    && !empty($adminRateState['allowed'])
    && (!isset($_SESSION[$attemptsKey]) || (int)$_SESSION[$attemptsKey] < $max_attempts)
) {
    if (!app_verify_csrf($_POST['_csrf_token'] ?? '')) {
        $reloadTarget = 'login.php';
        if ($allowClientPortalLogin && in_array($redirectMode, ['admin', 'client'], true)) {
            $reloadTarget .= '?mode=' . rawurlencode($redirectMode);
        }
        app_safe_redirect($reloadTarget);
    }

    $user_input = trim((string)($_POST['login_identity'] ?? ''));
    $pass_input = (string)($_POST['password'] ?? '');
    $tenantAccessCode = trim((string)($_POST['tenant_access_code'] ?? ''));
    $postAction = trim((string)($_POST['action'] ?? ''));

    if ($error === '' && $postAction === 'tenant_redirect' && $showSaasTenantLookup) {
        if ($tenantAccessCode === '') {
            $error = app_tr('أدخل كود المستأجر أو الدومين أولاً.', 'Enter the tenant code or domain first.');
        } else {
            try {
                $controlDbConfig = app_saas_control_db_config([
                    'host' => app_env('DB_HOST', 'localhost'),
                    'user' => app_env('DB_USER', ''),
                    'pass' => app_env('DB_PASS', ''),
                    'name' => app_env('DB_NAME', ''),
                    'port' => (int)app_env('DB_PORT', '3306'),
                    'socket' => app_env('DB_SOCKET', ''),
                ]);
                $controlConn = app_saas_open_control_connection($controlDbConfig);
                app_saas_ensure_control_plane_schema($controlConn);
                $tenantRow = app_saas_find_tenant_by_host($controlConn, $tenantAccessCode);
                if (!$tenantRow) {
                    $tenantRow = app_saas_find_tenant_by_slug($controlConn, $tenantAccessCode);
                }
                $controlConn->close();
                if (!$tenantRow) {
                    throw new RuntimeException(app_tr('المستأجر غير موجود أو لا يملك رابط تشغيل صالحًا.', 'Tenant was not found or does not have a valid runtime URL.'));
                }
                $tenantLoginUrl = function_exists('app_saas_tenant_login_url')
                    ? app_saas_tenant_login_url($tenantRow, rtrim((string)app_env('SYSTEM_URL', ''), '/'))
                    : '';
                if ($tenantLoginUrl === '') {
                    throw new RuntimeException(app_tr('المستأجر غير موجود أو لا يملك رابط تشغيل صالحًا.', 'Tenant was not found or does not have a valid runtime URL.'));
                }
                app_safe_redirect($tenantLoginUrl);
            } catch (Throwable $e) {
                $error = $e->getMessage();
            }
        }
    }

    if ($error === '' && !$isGatewayLanding && $user_input !== '' && $pass_input !== '') {
        // 1) Try internal system users when admin mode is selected.
        if (!$isClientMode) {
            $selectFields = "id, username, password, full_name, role";
            if (app_table_has_column($conn, 'users', 'allow_caps')) {
                $selectFields .= ", allow_caps";
            }
            if (app_table_has_column($conn, 'users', 'deny_caps')) {
                $selectFields .= ", deny_caps";
            }
            if (app_table_has_column($conn, 'users', 'email')) {
                $selectFields .= ", email";
            }
            if (app_table_has_column($conn, 'users', 'must_change_password')) {
                $selectFields .= ", must_change_password";
            }
            if (app_table_has_column($conn, 'users', 'is_active')) {
                $selectFields .= ", is_active";
            }
            if (app_table_has_column($conn, 'users', 'archived_at')) {
                $selectFields .= ", archived_at";
            }
            $stmt = $conn->prepare("SELECT $selectFields FROM users WHERE username = ? LIMIT 1");
            $stmt->bind_param("s", $user_input);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result->num_rows > 0) {
                $user = $result->fetch_assoc();
                if (password_verify($pass_input, (string)$user['password'])) {
                    if (!app_user_is_active_record($user)) {
                        $error = app_tr('تم إيقاف هذا الحساب أو أرشفته.', 'This account is inactive or archived.');
                        $stmt->close();
                        goto login_failed_done;
                    }
                    unset($_SESSION[$attemptsKey], $_SESSION[$attemptTimeKey]);
                    session_regenerate_id(true);

                    unset(
                        $_SESSION['portal_client_id'],
                        $_SESSION['portal_client_name'],
                        $_SESSION['portal_client_phone'],
                        $_SESSION['portal_client_email'],
                        $_SESSION['client_id'],
                        $_SESSION['client_name'],
                        $_SESSION['client_phone'],
                        $_SESSION['client_email']
                    );

                    $_SESSION['user_id'] = (int)$user['id'];
                    $_SESSION['username'] = (string)$user['username'];
                    $_SESSION['name'] = (string)$user['full_name'];
                    $_SESSION['role'] = (string)$user['role'];
                    $_SESSION['email'] = (string)($user['email'] ?? '');
                    $_SESSION['tenant_id'] = app_current_tenant_id();
                    $_SESSION['tenant_slug'] = app_current_tenant_slug();
                    $_SESSION['must_change_password'] = (int)($user['must_change_password'] ?? 0);
                    app_set_session_permission_caps($user['allow_caps'] ?? '', $user['deny_caps'] ?? '');
                    if (function_exists('app_desktop_node_sync_policy') && app_desktop_node_is_local_runtime($conn)) {
                        $desktopPolicy = app_desktop_node_sync_policy($conn, true);
                        $desktopStatus = app_desktop_node_sanitize_status((string)($desktopPolicy['status'] ?? app_setting_get($conn, 'desktop_node_remote_status', 'pending')));
                        if (in_array($desktopStatus, ['suspended', 'disabled', 'revoked'], true)) {
                            session_unset();
                            session_destroy();
                            app_start_session();
                            $error = app_tr('نسخة سطح المكتب هذه موقوفة من النظام الأساسي.', 'This desktop edition has been disabled from the primary system.');
                            $stmt->close();
                            goto login_failed_done;
                        }
                        if (!empty($desktopPolicy['force_password_reset'])) {
                            $_SESSION['must_change_password'] = 1;
                        }
                    }
                    app_audit_log_add($conn, 'auth.admin_login_success', [
                        'user_id' => (int)$user['id'],
                        'actor_type' => 'user',
                        'actor_name' => (string)$user['full_name'],
                        'entity_type' => 'auth',
                        'entity_key' => (string)$user['username'],
                        'details' => ['mode' => 'admin'],
                    ]);

                    if ((int)($_SESSION['must_change_password'] ?? 0) === 1) {
                        app_safe_redirect('desktop_force_password.php');
                    }
                    app_safe_redirect('dashboard.php');
                }
            }
            $stmt->close();
        }

        // 2) Try portal account only in client mode.
        if ($allowClientPortalLogin && $isClientMode) {
            $stmtClient = $conn->prepare("SELECT id, name, phone, email, password_hash FROM clients WHERE phone = ? OR email = ? LIMIT 1");
            $stmtClient->bind_param("ss", $user_input, $user_input);
            $stmtClient->execute();
            $clientResult = $stmtClient->get_result();

            if ($clientResult->num_rows > 0) {
                $client = $clientResult->fetch_assoc();
                if (!empty($client['password_hash']) && password_verify($pass_input, (string)$client['password_hash'])) {
                    unset($_SESSION[$attemptsKey], $_SESSION[$attemptTimeKey]);
                    session_regenerate_id(true);

                    unset($_SESSION['user_id'], $_SESSION['username'], $_SESSION['name'], $_SESSION['role'], $_SESSION['email']);
                    $_SESSION['portal_client_id'] = (int)$client['id'];
                    $_SESSION['portal_client_name'] = (string)$client['name'];
                    $_SESSION['portal_client_phone'] = (string)($client['phone'] ?? '');
                    $_SESSION['portal_client_email'] = (string)($client['email'] ?? '');
                    $_SESSION['tenant_id'] = app_current_tenant_id();
                    $_SESSION['tenant_slug'] = app_current_tenant_slug();
                    // legacy portal compatibility
                    $_SESSION['client_id'] = (int)$client['id'];
                    $_SESSION['client_name'] = (string)$client['name'];
                    $_SESSION['client_phone'] = (string)($client['phone'] ?? '');
                    $_SESSION['client_email'] = (string)($client['email'] ?? '');
                    app_audit_log_add($conn, 'auth.client_login_success', [
                        'user_id' => (int)$client['id'],
                        'actor_type' => 'client',
                        'actor_name' => (string)$client['name'],
                        'entity_type' => 'client',
                        'entity_key' => (string)($client['phone'] ?? $client['email'] ?? $client['id']),
                        'details' => ['mode' => 'portal'],
                    ]);

                    app_safe_redirect('client_portal/dashboard.html');
                }
            }
            $stmtClient->close();
        }

        login_failed_done:
        if ($error === '') {
            $_SESSION[$attemptsKey] = (int)($_SESSION[$attemptsKey] ?? 0) + 1;
            $_SESSION[$attemptTimeKey] = time();
            app_audit_log_add($conn, 'auth.login_failed', [
                'actor_type' => $isClientMode ? 'client' : 'user',
                'actor_name' => 'anonymous',
                'entity_type' => 'auth',
                'entity_key' => $user_input,
                'details' => ['mode' => $isClientMode ? 'client' : 'admin'],
            ]);
            $remaining_attempts = max(0, $max_attempts - (int)$_SESSION[$attemptsKey]);
            $error = sprintf(
                app_tr('بيانات الدخول غير صحيحة. تبقى لديك %d محاولات.', 'Invalid credentials. %d attempts remaining.'),
                $remaining_attempts
            );
        }
    } elseif ($error === '') {
        $error = app_tr('يرجى إدخال بيانات الدخول كاملة.', 'Please enter all login fields.');
    }
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && $licenseBlocked && $error === '') {
    $error = app_tr(
        'تم إيقاف تسجيل الدخول حتى اكتمال تفعيل الترخيص.',
        'Login is blocked until license activation is completed.'
    );
}
$isLocked = isset($_SESSION[$attemptsKey]) && (int)$_SESSION[$attemptsKey] >= $max_attempts;
$attemptsLeft = max(0, $max_attempts - (int)($_SESSION[$attemptsKey] ?? 0));
?>
<!DOCTYPE html>
<html dir="<?php echo app_h($appDir); ?>" lang="<?php echo app_h($appLang); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title><?php echo app_h($loginTitle); ?> | <?php echo $appNameSafe; ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800;900&family=Sora:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/brand.css">
    <style>
        :root {
            --gold: <?php echo $loginGold; ?>;
            --gold-dark: <?php echo $loginGoldDark; ?>;
            --teal: <?php echo $loginTeal; ?>;
            --ink-0: <?php echo $loginBg; ?>;
            --ink-1: <?php echo $loginBgAlt; ?>;
            --ink-2: <?php echo $loginPanel; ?>;
            --line: <?php echo $loginLine; ?>;
            --line-soft: <?php echo $loginLineSoft; ?>;
            --text-main: <?php echo $loginText; ?>;
            --text-soft: <?php echo $loginMuted; ?>;
            --danger: #ff8c87;
        }

        * {
            box-sizing: border-box;
            -webkit-tap-highlight-color: transparent;
        }

        html, body {
            margin: 0;
            min-height: 100%;
        }

        body {
            font-family: <?php echo $isEnglish ? "'Sora', 'Cairo', sans-serif" : "'Cairo', 'Sora', sans-serif"; ?>;
            color: var(--text-main);
            background:
                radial-gradient(44% 52% at 12% 14%, rgba(29, 183, 164, 0.22), transparent 68%),
                radial-gradient(38% 50% at 88% 86%, rgba(212, 175, 55, 0.2), transparent 70%),
                linear-gradient(135deg, var(--ink-0), var(--ink-1) 42%, var(--ink-2) 100%);
            overflow-x: hidden;
        }

        .login-shell {
            position: relative;
            min-height: 100vh;
            width: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: clamp(14px, 3.2vw, 36px);
            overflow: hidden;
        }

        .login-shell::before {
            content: '';
            position: absolute; width: 100%; height: 100%;
            background-image:
                linear-gradient(rgba(255,255,255,0.03) 1px, transparent 1px),
                linear-gradient(90deg, rgba(255,255,255,0.03) 1px, transparent 1px);
            background-size: 56px 56px;
            opacity: 0.12;
            pointer-events: none;
        }
        .login-shell::after {
            content: '';
            position: absolute;
            inset: -40%;
            background: linear-gradient(115deg, transparent 35%, rgba(255,255,255,0.08) 48%, transparent 61%);
            transform: translateX(-32%) rotate(8deg);
            animation: ae-login-sweep 9s ease-in-out infinite;
            pointer-events: none;
        }

        .bg-orb {
            position: absolute;
            width: 380px;
            height: 380px;
            border-radius: 50%;
            filter: blur(14px);
            opacity: 0.28;
            pointer-events: none;
            z-index: 0;
        }
        .bg-orb.orb-a {
            inset-inline-start: -110px;
            top: -80px;
            background: radial-gradient(circle, rgba(29,183,164,0.52), rgba(29,183,164,0.08) 70%);
        }
        .bg-orb.orb-b {
            inset-inline-end: -130px;
            bottom: -120px;
            background: radial-gradient(circle, rgba(212,175,55,0.54), rgba(212,175,55,0.08) 74%);
        }

        .login-layout {
            position: relative;
            z-index: 1;
            width: 100%;
            max-width: 1120px;
            display: grid;
            grid-template-columns: minmax(0, 1.08fr) minmax(0, 0.92fr);
            border: 1px solid var(--line);
            border-radius: 30px;
            background:
                linear-gradient(135deg, rgba(14,18,23,0.94), rgba(9,13,17,0.92));
            box-shadow:
                0 36px 58px rgba(0, 0, 0, 0.5),
                0 0 0 1px rgba(255, 255, 255, 0.02) inset;
            overflow: hidden;
            backdrop-filter: blur(12px);
            animation: ae-login-rise 0.7s ease both;
        }

        .login-aside {
            position: relative;
            padding: clamp(26px, 3.1vw, 44px);
            border-inline-end: 1px solid var(--line-soft);
            background:
                linear-gradient(175deg, rgba(19, 26, 34, 0.9), rgba(8, 12, 16, 0.88));
            display: flex;
            flex-direction: column;
            gap: 26px;
        }
        .login-aside::before {
            content: '';
            position: absolute;
            inset: 0;
            background:
                linear-gradient(120deg, rgba(29,183,164,0.08), transparent 42%),
                linear-gradient(300deg, rgba(212,175,55,0.08), transparent 58%);
            pointer-events: none;
        }
        .login-aside::after {
            content: '';
            position: absolute;
            inset-inline-start: 0;
            inset-block-start: 0;
            width: 3px;
            height: 100%;
            background: linear-gradient(180deg, transparent, var(--gold), var(--teal), transparent);
            opacity: 0.7;
            animation: ae-login-scan 4.8s ease-in-out infinite;
        }

        .brand-row {
            position: relative;
            display: flex;
            align-items: center;
            gap: 14px;
            z-index: 1;
        }
        .brand-row::after {
            content: '';
            width: 9px;
            height: 9px;
            border-radius: 50%;
            background: #67e08f;
            box-shadow: 0 0 0 0 rgba(103,224,143,.48);
            animation: ae-live-pulse 1.9s ease-out infinite;
            margin-inline-start: auto;
        }
        .brand-image {
            width: 64px;
            height: 64px;
            object-fit: cover;
            border-radius: 16px;
            border: 1px solid rgba(212,175,55,0.42);
            box-shadow: 0 12px 26px rgba(212,175,55,0.22);
            background: #121212;
        }
        .brand-title {
            margin: 0;
            font-size: clamp(1.35rem, 2.1vw, 2rem);
            font-weight: 800;
            color: #f6eac2;
            letter-spacing: 0.2px;
            line-height: 1.1;
            text-shadow: 0 0 12px rgba(212,175,55,0.2);
        }
        .brand-subtitle {
            margin: 0;
            color: var(--text-soft);
            font-size: 0.84rem;
            font-weight: 600;
        }

        .aside-copy {
            position: relative;
            z-index: 1;
            margin-top: 4px;
        }
        .aside-copy h2 {
            margin: 0 0 10px;
            font-size: clamp(1.28rem, 2.2vw, 2.1rem);
            line-height: 1.25;
            color: #ffffff;
            font-weight: 800;
        }
        .aside-copy p {
            margin: 0;
            font-size: 0.95rem;
            line-height: 1.8;
            color: #b8c5d0;
        }

        .feature-list {
            margin: 0;
            padding: 0;
            list-style: none;
            display: inline-flex;
            flex-direction: column;
            gap: 10px;
            width: 100%;
            margin-top: 16px;
        }
        .feature-list li {
            display: flex;
            align-items: center;
            gap: 10px;
            border: 1px solid rgba(212,175,55,0.16);
            background: rgba(255,255,255,0.02);
            border-radius: 13px;
            padding: 10px 12px;
            color: #dce5ec;
            font-size: 0.88rem;
            font-weight: 600;
        }
        .feature-list i {
            width: 26px;
            height: 26px;
            border-radius: 8px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: var(--gold);
            border: 1px solid rgba(212,175,55,0.3);
            background: rgba(212,175,55,0.08);
            flex-shrink: 0;
        }

        .lang-area {
            margin-top: auto;
            position: relative;
            z-index: 1;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            border: 1px solid rgba(212,175,55,0.24);
            background: rgba(255,255,255,0.02);
            border-radius: 14px;
            padding: 9px 12px;
        }
        .lang-label {
            font-size: 0.8rem;
            font-weight: 700;
            color: #dbe2e9;
        }
        .lang-links {
            display: inline-flex;
            gap: 6px;
        }
        .lang-links a {
            color: #d3dbe3;
            font-size: 0.8rem;
            font-weight: 700;
            border: 1px solid #2f3f4d;
            border-radius: 9px;
            padding: 5px 10px;
            text-decoration: none;
            transition: 0.2s;
        }
        .lang-links a.active {
            border-color: rgba(212,175,55,0.56);
            background: rgba(212,175,55,0.18);
            color: #f8e9b9;
        }
        .lang-links a:hover {
            border-color: rgba(212,175,55,0.5);
            color: #f9edc6;
        }

        .login-panel {
            padding: clamp(26px, 3.1vw, 44px);
            display: flex;
            flex-direction: column;
        }
        .panel-head {
            margin-bottom: 18px;
        }
        .panel-head h3 {
            margin: 0;
            font-size: clamp(1.28rem, 2.1vw, 1.85rem);
            font-weight: 800;
            color: #ffffff;
            line-height: 1.25;
        }
        .panel-head p {
            margin: 8px 0 0;
            color: #9fb0be;
            font-size: 0.9rem;
            line-height: 1.6;
        }
        .mode-switch {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 8px;
            margin-bottom: 14px;
        }
        .mode-tab {
            border: 1px solid #2f3f4d;
            background: #0c1319;
            color: #d3dce4;
            border-radius: 11px;
            padding: 10px 12px;
            font-family: inherit;
            font-weight: 700;
            cursor: pointer;
            transition: 0.2s;
        }
        .mode-tab.active {
            border-color: rgba(212, 175, 55, 0.64);
            background: rgba(212, 175, 55, 0.2);
            color: #fbe9b5;
        }

        .error-msg {
            border: 1px solid rgba(255, 110, 103, 0.52);
            background: rgba(191, 54, 47, 0.2);
            color: #ffd3d1;
            border-radius: 12px;
            padding: 11px 12px;
            margin-bottom: 14px;
            font-size: 0.88rem;
            line-height: 1.55;
        }
        .attempts-badge {
            color: #f5d985;
            font-size: 0.8rem;
            background: rgba(212, 175, 55, 0.09);
            border: 1px solid rgba(212, 175, 55, 0.4);
            border-radius: 999px;
            padding: 5px 11px;
            margin: 0 0 14px;
            width: fit-content;
        }
        .tenant-access-box {
            border: 1px solid rgba(212,175,55,0.18);
            background: rgba(255,255,255,0.02);
            border-radius: 16px;
            padding: 14px;
            margin-top: 16px;
        }
        .tenant-access-box h4 {
            margin: 0 0 6px;
            color: #f0d684;
            font-size: 0.96rem;
        }
        .tenant-access-box p {
            margin: 0 0 12px;
            color: #9fb0be;
            font-size: 0.84rem;
            line-height: 1.8;
        }

        .login-form {
            display: flex;
            flex-direction: column;
            gap: 14px;
        }
        .input-group {
            margin: 0;
            position: relative;
        }
        .form-label {
            display: block;
            color: #d9e1e8;
            font-size: 0.82rem;
            margin-bottom: 8px;
            text-align: start;
            font-weight: 700;
            letter-spacing: 0.2px;
        }
        .input-wrap {
            position: relative;
        }
        .input-wrap::after {
            content: '';
            position: absolute;
            inset-inline: 14px;
            inset-block-end: 0;
            height: 1px;
            background: linear-gradient(90deg, transparent, rgba(212,175,55,.72), transparent);
            transform: scaleX(0);
            transition: transform .24s ease;
            pointer-events: none;
        }
        .input-wrap:focus-within::after {
            transform: scaleX(1);
        }
        .input-icon {
            position: absolute;
            top: 50%;
            transform: translateY(-50%);
            inset-inline-start: 13px;
            color: #7d8a94;
            font-size: 0.92rem;
            pointer-events: none;
        }

        .input-control {
            width: 100%;
            padding: 14px 14px;
            padding-inline-start: 40px;
            background: rgba(2, 5, 8, 0.7);
            border: 1px solid #2a3945;
            border-radius: 12px;
            color: #f4f6f8;
            font: inherit;
            font-size: 0.96rem;
            transition: 0.22s;
            text-align: start;
        }
        .input-control:focus {
            border-color: rgba(212, 175, 55, 0.72);
            outline: none;
            background: rgba(1, 4, 8, 0.88);
            box-shadow: 0 0 0 3px rgba(212, 175, 55, 0.14);
        }

        .password-toggle {
            position: absolute;
            top: 50%;
            transform: translateY(-50%);
            inset-inline-end: 7px;
            border: 1px solid #2f3f4a;
            background: #0d141a;
            color: #d7dde3;
            width: 34px;
            height: 34px;
            border-radius: 10px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            transition: 0.18s;
        }
        .password-toggle:hover {
            border-color: rgba(212, 175, 55, 0.62);
            color: #ffe4a0;
            background: rgba(212,175,55,0.12);
        }
        .input-wrap.has-toggle .input-control {
            padding-inline-end: 46px;
        }

        .assist-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            margin-top: 2px;
            margin-bottom: 2px;
            flex-wrap: wrap;
        }
        .assist-text {
            color: #9fceb7;
            font-size: 0.75rem;
            text-align: start;
            display: none;
            line-height: 1.5;
        }

        .forgot-row {
            text-align: start;
            margin-top: -2px;
            margin-bottom: 2px;
        }
        .forgot-link {
            color: #f0cd6b;
            font-size: 0.83rem;
            font-weight: 700;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .forgot-link:hover {
            color: #ffe29a;
            text-decoration: underline;
        }

        .btn-royal {
            margin-top: 4px;
            background: linear-gradient(130deg, var(--gold), var(--gold-dark) 56%, var(--teal));
            color: #060606;
            font-weight: 800;
            border: none;
            padding: 14px;
            width: 100%;
            border-radius: 12px;
            cursor: pointer;
            font-size: 1rem;
            font-family: inherit;
            transition: 0.22s;
            box-shadow: 0 16px 30px rgba(6, 8, 10, 0.4);
            letter-spacing: 0.3px;
        }
        .btn-royal:hover {
            transform: translateY(-1px);
            box-shadow: 0 20px 34px rgba(212, 175, 55, 0.24);
            filter: brightness(1.05);
        }
        .btn-royal.is-submitting {
            position: relative;
            overflow: hidden;
            pointer-events: none;
        }
        .btn-royal.is-submitting::after {
            content: '';
            position: absolute;
            inset: 0;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,.34), transparent);
            transform: translateX(-100%);
            animation: ae-button-load 1.1s linear infinite;
        }
        .btn-royal:disabled {
            cursor: not-allowed;
            opacity: 0.58;
            box-shadow: none;
            filter: grayscale(0.12);
            transform: none;
        }

        .help-note {
            margin: 12px 0 0;
            color: #8898a5;
            font-size: 0.78rem;
            line-height: 1.6;
        }
        .secure-footer {
            margin-top: 18px;
            color: #6d7a86;
            font-size: 0.72rem;
            text-align: start;
        }

        @keyframes ae-login-rise {
            from { opacity: 0; transform: translateY(14px) scale(.985); }
            to { opacity: 1; transform: translateY(0) scale(1); }
        }
        @keyframes ae-login-sweep {
            0%, 44% { transform: translateX(-34%) rotate(8deg); opacity: 0; }
            56% { opacity: .75; }
            100% { transform: translateX(34%) rotate(8deg); opacity: 0; }
        }
        @keyframes ae-login-scan {
            0%, 100% { opacity: .32; transform: translateY(-18%); }
            50% { opacity: .85; transform: translateY(18%); }
        }
        @keyframes ae-live-pulse {
            0% { box-shadow: 0 0 0 0 rgba(103,224,143,.48); }
            70% { box-shadow: 0 0 0 12px rgba(103,224,143,0); }
            100% { box-shadow: 0 0 0 0 rgba(103,224,143,0); }
        }
        @keyframes ae-button-load {
            to { transform: translateX(100%); }
        }

        @media (prefers-reduced-motion: reduce) {
            *, *::before, *::after {
                animation-duration: .001ms !important;
                animation-iteration-count: 1 !important;
                scroll-behavior: auto !important;
            }
        }

        @media (max-width: 980px) {
            .login-layout {
                grid-template-columns: 1fr;
                max-width: 740px;
            }
            .login-aside {
                border-inline-end: none;
                border-bottom: 1px solid var(--line-soft);
                gap: 18px;
            }
            .lang-area {
                margin-top: 4px;
            }
        }

        @media (max-width: 560px) {
            .login-shell {
                padding: 10px;
            }
            .login-layout {
                border-radius: 20px;
            }
            .login-aside,
            .login-panel {
                padding: 18px 14px;
            }
            .brand-image {
                width: 54px;
                height: 54px;
                border-radius: 14px;
            }
            .feature-list li {
                padding: 9px 10px;
            }
            .lang-area {
                flex-direction: column;
                align-items: flex-start;
            }
        }

    </style>
</head>
<body class="brand-shell">
    <div class="login-shell">
        <div class="bg-orb orb-a"></div>
        <div class="bg-orb orb-b"></div>

        <div class="login-layout">
            <aside class="login-aside">
                <div class="brand-row">
                    <img src="<?php echo app_h($appLogo); ?>" alt="logo" class="brand-image" onerror="this.style.display='none'">
                    <div>
                        <h1 class="brand-title"><?php echo $appNameSafe; ?></h1>
                        <p class="brand-subtitle"><?php echo app_h($txtLoginSubtitle); ?></p>
                    </div>
                </div>

                <div class="aside-copy">
                    <h2><?php echo app_h($txtWelcomeHeadline); ?></h2>
                    <p><?php echo app_h($txtWelcomeBody); ?></p>

                    <ul class="feature-list">
                        <li><i class="fa-solid fa-signal"></i> <?php echo app_h($txtFeatureOne); ?></li>
                        <li><i class="fa-solid fa-file-invoice-dollar"></i> <?php echo app_h($txtFeatureTwo); ?></li>
                        <li><i class="fa-solid fa-shield-halved"></i> <?php echo app_h($txtFeatureThree); ?></li>
                    </ul>
                </div>

                <div class="lang-area">
                    <span class="lang-label"><?php echo app_h($txtLanguage); ?></span>
                    <div class="lang-links">
                        <a href="<?php echo app_h($langArUrl); ?>" class="<?php echo !$isEnglish ? 'active' : ''; ?>">العربية</a>
                        <a href="<?php echo app_h($langEnUrl); ?>" class="<?php echo $isEnglish ? 'active' : ''; ?>">English</a>
                    </div>
                </div>
            </aside>

            <main class="login-panel">
                <div class="panel-head">
                    <h3><?php echo app_h($loginTitle); ?></h3>
                    <p id="panelHintText"><?php echo app_h($panelHintCurrent); ?></p>
                </div>

                <?php if ($allowClientPortalLogin): ?>
                    <div class="mode-switch" id="loginModeSwitch">
                        <button type="button" class="mode-tab <?php echo $loginMode === 'admin' ? 'active' : ''; ?>" data-mode="admin"><?php echo app_h($txtAdminTab); ?></button>
                        <button type="button" class="mode-tab <?php echo $loginMode === 'client' ? 'active' : ''; ?>" data-mode="client"><?php echo app_h($txtClientTab); ?></button>
                    </div>
                <?php endif; ?>

                <?php if($error): ?>
                    <div class="error-msg"><?php echo app_h($error); ?></div>
                <?php endif; ?>
                <?php if($isLocked && $lockoutRemaining > 0): ?>
                    <div class="attempts-badge" data-lockout-remaining="<?php echo (int)$lockoutRemaining; ?>">
                        <?php echo app_h(sprintf($txtLockoutRemaining, (int)floor($lockoutRemaining / 60), (int)($lockoutRemaining % 60))); ?>
                    </div>
                <?php elseif(!$isLocked && $attemptsLeft < $max_attempts): ?>
                    <div class="attempts-badge">
                        <?php echo app_h(sprintf($txtAttemptsLeft, $attemptsLeft)); ?>
                    </div>
                <?php endif; ?>

                <?php if (!$isGatewayLanding): ?>
                    <form method="POST" class="login-form" novalidate>
                        <?php echo app_csrf_input(); ?>
                        <input type="hidden" name="login_mode" id="loginModeInput" value="<?php echo app_h($loginMode); ?>">
                        <div class="input-group">
                            <label class="form-label" for="usernameInput" id="identityLabelText"><?php echo app_h($identityLabelCurrent); ?></label>
                            <div class="input-wrap">
                                <i class="fa-solid fa-user input-icon"></i>
                                <input id="usernameInput" class="input-control" type="text" name="login_identity" placeholder="<?php echo app_h($identityPlaceholderCurrent); ?>" required autocomplete="username" value="<?php echo app_h($identityValue); ?>">
                            </div>
                        </div>

                        <div class="input-group">
                            <label class="form-label" for="passwordInput"><?php echo app_h($txtPasswordLabel); ?></label>
                            <div class="input-wrap has-toggle">
                                <i class="fa-solid fa-lock input-icon"></i>
                                <input id="passwordInput" class="input-control" type="password" name="password" placeholder="<?php echo app_h($txtPasswordPlaceholder); ?>" required autocomplete="current-password">
                                <button type="button" class="password-toggle" id="passwordToggle" aria-label="<?php echo app_h($txtShowPassword); ?>" title="<?php echo app_h($txtShowPassword); ?>">
                                    <i class="fa-regular fa-eye"></i>
                                </button>
                            </div>
                        </div>

                        <div class="assist-row">
                            <span class="assist-text" id="capsLockHint"><?php echo app_h($txtCapsLockOn); ?></span>
                            <span class="assist-text" id="usernameRemembered"><?php echo app_h($txtRemembered); ?></span>
                        </div>

                        <div class="forgot-row">
                            <a href="<?php echo app_h($forgotHref); ?>" class="forgot-link" id="forgotLink"<?php echo $forgotTargetAttr; ?>><i class="fa-solid fa-key"></i> <span id="forgotLinkLabel"><?php echo app_h($isClientMode ? $txtClientHelp : $txtForgotPassword); ?></span></a>
                        </div>

                        <button type="submit" class="btn-royal" id="submitBtnLabel" <?php echo $isLocked ? 'disabled' : ''; ?>>
                            <?php echo $isLocked ? app_h($txtLocked) : app_h($submitLabelCurrent); ?>
                        </button>
                    </form>
                <?php endif; ?>

                <?php if ($showSaasTenantLookup): ?>
                    <div class="tenant-access-box">
                        <h4><?php echo app_h($txtTenantLookupTitle); ?></h4>
                        <p><?php echo app_h($txtTenantLookupHint); ?></p>
                        <form method="POST" class="login-form" novalidate>
                            <?php echo app_csrf_input(); ?>
                            <input type="hidden" name="action" value="tenant_redirect">
                            <div class="input-group">
                                <label class="form-label" for="tenantAccessInput"><?php echo app_h($txtTenantLookupLabel); ?></label>
                                <div class="input-wrap">
                                    <i class="fa-solid fa-building-shield input-icon"></i>
                                    <input id="tenantAccessInput" class="input-control" type="text" name="tenant_access_code" placeholder="<?php echo app_h($txtTenantLookupPlaceholder); ?>">
                                </div>
                            </div>
                            <button type="submit" class="btn-royal"><?php echo app_h($txtTenantLookupSubmit); ?></button>
                        </form>
                    </div>
                <?php endif; ?>

                <p class="help-note"><?php echo app_h($txtNeedHelp); ?></p>
                <p class="secure-footer"><?php echo app_h($txtSecureFooter); ?> &copy; <?php echo date('Y'); ?></p>
            </main>
        </div>
    </div>

    <script>
        (function () {
            const isEnglish = <?php echo json_encode($isEnglish); ?>;
            const initialMode = <?php echo json_encode($loginMode); ?>;
            const txtShowPassword = <?php echo json_encode($txtShowPassword, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
            const txtHidePassword = <?php echo json_encode($txtHidePassword, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
            const txtPanelHintAdmin = <?php echo json_encode($txtPanelHint, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
            const txtPanelHintClient = <?php echo json_encode($txtPanelHintClient, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
            const txtIdentityAdmin = <?php echo json_encode($txtUsernameLabel, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
            const txtIdentityClient = <?php echo json_encode($txtClientIdentityLabel, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
            const txtIdentityAdminPlaceholder = <?php echo json_encode($txtUsernamePlaceholder, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
            const txtIdentityClientPlaceholder = <?php echo json_encode($txtClientIdentityPlaceholder, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
            const txtSubmitAdmin = <?php echo json_encode($txtEnterSystem, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
            const txtSubmitClient = <?php echo json_encode($txtEnterClientPortal, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
            const txtForgotAdmin = <?php echo json_encode($txtForgotPassword, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
            const txtForgotClient = <?php echo json_encode($txtClientHelp, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;

            const passwordInput = document.getElementById('passwordInput');
            const passwordToggle = document.getElementById('passwordToggle');
            const capsLockHint = document.getElementById('capsLockHint');
            const usernameInput = document.getElementById('usernameInput');
            const usernameRemembered = document.getElementById('usernameRemembered');
            const loginModeInput = document.getElementById('loginModeInput');
            const panelHintText = document.getElementById('panelHintText');
            const identityLabelText = document.getElementById('identityLabelText');
            const submitBtnLabel = document.getElementById('submitBtnLabel');
            const forgotLink = document.getElementById('forgotLink');
            const forgotLinkLabel = document.getElementById('forgotLinkLabel');
            const modeTabs = document.querySelectorAll('.mode-tab');

            let activeMode = initialMode === 'client' ? 'client' : 'admin';

            const setModeUI = function (mode) {
                activeMode = mode === 'client' ? 'client' : 'admin';
                if (loginModeInput) {
                    loginModeInput.value = activeMode;
                }
                modeTabs.forEach(function (btn) {
                    btn.classList.toggle('active', btn.dataset.mode === activeMode);
                });
                if (panelHintText) {
                    panelHintText.textContent = activeMode === 'client' ? txtPanelHintClient : txtPanelHintAdmin;
                }
                if (identityLabelText) {
                    identityLabelText.textContent = activeMode === 'client' ? txtIdentityClient : txtIdentityAdmin;
                }
                if (usernameInput) {
                    usernameInput.placeholder = activeMode === 'client' ? txtIdentityClientPlaceholder : txtIdentityAdminPlaceholder;
                }
                if (submitBtnLabel && !submitBtnLabel.disabled) {
                    submitBtnLabel.textContent = activeMode === 'client' ? txtSubmitClient : txtSubmitAdmin;
                }
                if (forgotLink) {
                    forgotLink.href = activeMode === 'client' ? 'https://wa.me/201000571057' : 'forgot_password.php';
                    if (activeMode === 'client') {
                        forgotLink.target = '_blank';
                        forgotLink.rel = 'noopener';
                    } else {
                        forgotLink.removeAttribute('target');
                        forgotLink.removeAttribute('rel');
                    }
                }
                if (forgotLinkLabel) {
                    forgotLinkLabel.textContent = activeMode === 'client' ? txtForgotClient : txtForgotAdmin;
                }
                if (usernameInput && usernameRemembered) {
                    const stored = localStorage.getItem('ae_last_identity_' + activeMode) || '';
                    if (stored && usernameInput.value.trim() === '') {
                        usernameInput.value = stored;
                        usernameRemembered.style.display = 'inline-block';
                    } else {
                        usernameRemembered.style.display = 'none';
                    }
                }
            };

            if (passwordToggle && passwordInput) {
                passwordToggle.addEventListener('click', function () {
                    const isHidden = passwordInput.type === 'password';
                    passwordInput.type = isHidden ? 'text' : 'password';
                    const icon = passwordToggle.querySelector('i');
                    if (icon) {
                        icon.className = isHidden ? 'fa-regular fa-eye-slash' : 'fa-regular fa-eye';
                    }
                    const label = isHidden ? txtHidePassword : txtShowPassword;
                    passwordToggle.setAttribute('aria-label', label);
                    passwordToggle.setAttribute('title', label);
                });

                const updateCapsLockHint = function (event) {
                    if (!capsLockHint || !event || typeof event.getModifierState !== 'function') return;
                    capsLockHint.style.display = event.getModifierState('CapsLock') ? 'inline-block' : 'none';
                };
                passwordInput.addEventListener('keydown', updateCapsLockHint);
                passwordInput.addEventListener('keyup', updateCapsLockHint);
                passwordInput.addEventListener('blur', function () {
                    if (capsLockHint) capsLockHint.style.display = 'none';
                });
            }

            modeTabs.forEach(function (btn) {
                btn.addEventListener('click', function () {
                    setModeUI(btn.dataset.mode || 'admin');
                });
            });
            setModeUI(activeMode);

            if (usernameInput) {
                const saved = localStorage.getItem('ae_last_identity_' + activeMode) || '';
                if (saved && !usernameInput.value) {
                    usernameInput.value = saved;
                    if (usernameRemembered) {
                        usernameRemembered.style.display = 'inline-block';
                    }
                }

                const parentForm = usernameInput.closest('form');
                if (parentForm) {
                    parentForm.addEventListener('submit', function () {
                        localStorage.setItem('ae_last_identity_' + activeMode, usernameInput.value || '');
                        if (submitBtnLabel && !submitBtnLabel.disabled) {
                            submitBtnLabel.classList.add('is-submitting');
                        }
                    });
                }
            }

            const lockoutBadge = document.querySelector('[data-lockout-remaining]');
            if (lockoutBadge) {
                let secondsLeft = parseInt(lockoutBadge.getAttribute('data-lockout-remaining') || '0', 10);
                const formatTimer = function (seconds) {
                    const m = Math.floor(Math.max(0, seconds) / 60);
                    const s = Math.max(0, seconds) % 60;
                    const formatted = String(m) + ':' + String(s).padStart(2, '0');
                    return isEnglish ? ('Try again in ' + formatted) : ('أعد المحاولة بعد ' + formatted);
                };

                lockoutBadge.textContent = formatTimer(secondsLeft);
                if (secondsLeft > 0) {
                    const timer = setInterval(function () {
                        secondsLeft -= 1;
                        if (secondsLeft <= 0) {
                            clearInterval(timer);
                            window.location.reload();
                            return;
                        }
                        lockoutBadge.textContent = formatTimer(secondsLeft);
                    }, 1000);
                }
            }
        })();
    </script>
</body>
</html>
