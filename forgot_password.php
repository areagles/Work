<?php
// forgot_password.php
require 'config.php';
app_start_session();
app_handle_lang_switch($conn);

if (isset($_SESSION['user_id'])) {
    app_safe_redirect('dashboard.php');
}

$appLang = app_current_lang($conn);
$appDir = app_lang_dir($appLang);
$isEnglish = $appLang === 'en';
$appName = app_setting_get($conn, 'app_name', 'Arab Eagles');
$appLogo = app_brand_logo_path($conn, 'assets/img/Logo.png');
$appNameSafe = app_h($appName !== '' ? $appName : 'Arab Eagles');

$langArUrl = app_lang_switch_url('ar');
$langEnUrl = app_lang_switch_url('en');

$title = $isEnglish ? 'Forgot Password' : 'نسيت كلمة المرور';
$subtitle = $isEnglish ? 'Reset access via your registered email' : 'استرجع الدخول من خلال بريدك الإلكتروني المسجل';
$emailLabel = $isEnglish ? 'Registered Email' : 'البريد الإلكتروني المسجل';
$emailPlaceholder = $isEnglish ? 'name@example.com' : 'name@example.com';
$sendButton = $isEnglish ? 'Send Reset Link' : 'إرسال رابط إعادة التعيين';
$backLogin = $isEnglish ? 'Back to Login' : 'العودة لتسجيل الدخول';
$genericNotice = $isEnglish
    ? 'If this email is registered, a reset link has been sent.'
    : 'إذا كان البريد مسجلاً، تم إرسال رابط إعادة تعيين كلمة المرور.';
$csrfError = $isEnglish
    ? 'Session expired. Refresh and try again.'
    : 'انتهت صلاحية الجلسة. حدّث الصفحة ثم حاول مرة أخرى.';
$invalidEmailError = $isEnglish
    ? 'Please enter a valid email address.'
    : 'يرجى إدخال بريد إلكتروني صحيح.';
$rateLimitedError = $isEnglish
    ? 'Please wait a little before requesting another reset link.'
    : 'يرجى الانتظار قليلاً قبل طلب رابط جديد.';
$debugMailFailed = $isEnglish
    ? 'Email sending failed on server (debug mode).'
    : 'فشل إرسال البريد من السيرفر (وضع التصحيح).';
$systemUrlRequiredError = $isEnglish
    ? 'Password reset is not configured correctly on this server. Contact the administrator.'
    : 'إعادة تعيين كلمة المرور غير مهيأة بشكل صحيح على هذا النظام. تواصل مع مسؤول النظام.';

$notice = '';
$error = '';
$emailValue = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!app_verify_csrf($_POST['_csrf_token'] ?? '')) {
        $error = $csrfError;
    } else {
        $emailValue = trim((string)($_POST['email'] ?? ''));
        $isValidEmail = filter_var($emailValue, FILTER_VALIDATE_EMAIL) !== false;
        if (!$isValidEmail) {
            $error = $invalidEmailError;
        } else {
            $lastReqAt = (int)($_SESSION['password_reset_last_request_at'] ?? 0);
            if ($lastReqAt > 0 && (time() - $lastReqAt) < 25) {
                $error = $rateLimitedError;
            } else {
                $_SESSION['password_reset_last_request_at'] = time();
                $notice = $genericNotice;

                if (app_ensure_password_reset_schema($conn)) {
                    $stmt = $conn->prepare("SELECT id, full_name, email, is_active, archived_at FROM users WHERE LOWER(email) = LOWER(?) LIMIT 1");
                    $stmt->bind_param('s', $emailValue);
                    $stmt->execute();
                    $res = $stmt->get_result();
                    $user = $res ? $res->fetch_assoc() : null;
                    $stmt->close();

                    if ($user && app_user_is_active_record($user) && !empty($user['email'])) {
                        $userId = (int)$user['id'];
                        $userEmail = trim((string)$user['email']);
                        $userName = trim((string)($user['full_name'] ?? ''));
                        if ($userName === '') {
                            $userName = $isEnglish ? 'User' : 'المستخدم';
                        }

                        try {
                            $selector = bin2hex(random_bytes(8));
                            $token = bin2hex(random_bytes(32));
                            $tokenHash = hash('sha256', $token);
                            $ttlMinutes = (int)app_env('PASSWORD_RESET_TTL_MINUTES', '30');
                            $ttlMinutes = max(10, min(180, $ttlMinutes));
                            $expiresAt = date('Y-m-d H:i:s', time() + ($ttlMinutes * 60));
                            $requestIp = mb_substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);

                            $stmtCleanup = $conn->prepare("DELETE FROM password_reset_tokens WHERE (user_id = ? AND used_at IS NULL) OR used_at IS NOT NULL OR expires_at < NOW()");
                            $stmtCleanup->bind_param('i', $userId);
                            $stmtCleanup->execute();
                            $stmtCleanup->close();

                            $stmtInsert = $conn->prepare("
                                INSERT INTO password_reset_tokens (user_id, email, selector, token_hash, expires_at, request_ip)
                                VALUES (?, ?, ?, ?, ?, ?)
                            ");
                            $stmtInsert->bind_param('isssss', $userId, $userEmail, $selector, $tokenHash, $expiresAt, $requestIp);
                            $stmtInsert->execute();
                            $stmtInsert->close();

                            $baseUrl = rtrim((string)app_env('SYSTEM_URL', ''), '/');
                            if ($baseUrl === '' || !preg_match('#^https?://#i', $baseUrl)) {
                                throw new RuntimeException('missing_system_url');
                            }
                            $resetLink = $baseUrl . '/reset_password.php?selector=' . urlencode($selector) . '&token=' . urlencode($token);

                            if ($isEnglish) {
                                $subject = 'Password reset request';
                                $body = "Hello {$userName},\n\n"
                                    . "A password reset was requested for your account on {$appName}.\n"
                                    . "Open this link to set a new password:\n{$resetLink}\n\n"
                                    . "This link expires in {$ttlMinutes} minutes.\n"
                                    . "If you did not request this, please ignore this email.\n\n"
                                    . "Regards,\n{$appName}";
                            } else {
                                $subject = 'طلب إعادة تعيين كلمة المرور';
                                $body = "مرحباً {$userName}،\n\n"
                                    . "تم طلب إعادة تعيين كلمة المرور لحسابك على {$appName}.\n"
                                    . "استخدم الرابط التالي لتعيين كلمة مرور جديدة:\n{$resetLink}\n\n"
                                    . "صلاحية الرابط {$ttlMinutes} دقيقة.\n"
                                    . "إذا لم تطلب ذلك، يمكنك تجاهل هذه الرسالة.\n\n"
                                    . "تحياتنا،\n{$appName}";
                            }

                            $mailOk = app_send_email_basic($userEmail, $subject, $body, [
                                'from_name' => $appName,
                            ]);
                            if (!$mailOk && app_env('APP_DEBUG_AUTH_MAIL', '0') === '1') {
                                $error = $debugMailFailed;
                            }
                        } catch (Throwable $e) {
                            error_log('forgot password flow failed: ' . $e->getMessage());
                            if ($e->getMessage() === 'missing_system_url') {
                                $error = $systemUrlRequiredError;
                            }
                            if (app_env('APP_DEBUG_AUTH_MAIL', '0') === '1') {
                                $error = $debugMailFailed;
                            }
                        }
                    }
                } else {
                    error_log('password reset table unavailable');
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html dir="<?php echo app_h($appDir); ?>" lang="<?php echo app_h($appLang); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title><?php echo app_h($title); ?> | <?php echo $appNameSafe; ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@300;400;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/brand.css">
    <style>
        body, html {
            margin: 0;
            min-height: 100%;
            font-family: 'Cairo', sans-serif;
            display: flex;
            align-items: center;
            justify-content: center;
            background: radial-gradient(circle at 20% 10%, rgba(24,199,160,0.08), transparent 24%), radial-gradient(circle at 82% 88%, rgba(212,175,55,0.11), transparent 28%), #050505;
            color: #fff;
        }
        .wrap {
            width: min(460px, 92vw);
            background: linear-gradient(145deg, rgba(18,18,18,0.95), rgba(10,10,10,0.92));
            border: 1px solid rgba(212, 175, 55, 0.3);
            border-radius: 18px;
            box-shadow: 0 14px 30px rgba(0,0,0,0.65);
            padding: 24px 22px;
            position: relative;
        }
        .wrap::before {
            content: '';
            position: absolute;
            inset-inline: 0;
            top: 0;
            height: 3px;
            border-radius: 18px 18px 0 0;
            background: linear-gradient(90deg, #d4af37, #18c7a0, #d4af37);
        }
        .head {
            text-align: center;
            margin-bottom: 16px;
        }
        .logo {
            width: 68px;
            height: 68px;
            border-radius: 50%;
            border: 2px solid #d4af37;
            object-fit: cover;
            margin-bottom: 10px;
            box-shadow: 0 0 16px rgba(212,175,55,0.25);
        }
        .title {
            margin: 0;
            color: #d4af37;
            font-size: 1.3rem;
            font-weight: 800;
        }
        .sub {
            margin-top: 6px;
            color: #a4afad;
            font-size: 0.88rem;
        }
        .lang-links {
            margin-top: 10px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            border: 1px solid rgba(212,175,55,0.26);
            border-radius: 999px;
            padding: 4px 10px;
            background: rgba(255,255,255,0.03);
        }
        .lang-links a {
            color: #d4af37;
            text-decoration: none;
            font-size: 0.78rem;
            font-weight: 700;
        }
        .sep { color: #666; font-size: 0.72rem; }
        .field {
            margin-bottom: 14px;
        }
        .label {
            display: block;
            color: #d2d2d2;
            font-size: 0.85rem;
            margin-bottom: 6px;
            font-weight: 700;
            text-align: start;
        }
        .input-wrap {
            position: relative;
        }
        .icon {
            position: absolute;
            top: 50%;
            transform: translateY(-50%);
            inset-inline-start: 11px;
            color: #868686;
            font-size: 0.88rem;
        }
        .input {
            width: 100%;
            box-sizing: border-box;
            border-radius: 10px;
            border: 1px solid #2f3a37;
            background: rgba(0,0,0,0.56);
            color: #fff;
            padding: 13px 13px;
            padding-inline-start: 36px;
            font-family: 'Cairo', sans-serif;
            font-size: 0.95rem;
            outline: none;
        }
        .input:focus {
            border-color: #d4af37;
            box-shadow: 0 0 0 3px rgba(212,175,55,0.15);
        }
        .btn {
            width: 100%;
            border: none;
            border-radius: 10px;
            padding: 12px 14px;
            font-family: 'Cairo', sans-serif;
            font-size: 1rem;
            font-weight: 800;
            cursor: pointer;
            background: linear-gradient(135deg, #d4af37, #b8860b 55%, #18c7a0);
            color: #000;
            transition: 0.2s;
        }
        .btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 10px 20px rgba(212,175,55,0.2);
        }
        .msg {
            border-radius: 10px;
            padding: 9px 11px;
            font-size: 0.86rem;
            margin-bottom: 13px;
        }
        .msg.err {
            background: rgba(192,57,43,0.15);
            border: 1px solid rgba(231,76,60,0.7);
            color: #ffc1be;
        }
        .msg.ok {
            background: rgba(46,204,113,0.14);
            border: 1px solid rgba(46,204,113,0.55);
            color: #b8f1cd;
        }
        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            margin-top: 14px;
            color: #d4af37;
            text-decoration: none;
            font-size: 0.86rem;
            font-weight: 700;
        }
    </style>
</head>
<body class="brand-shell">
    <div class="wrap">
        <div class="head">
            <img src="<?php echo app_h($appLogo); ?>" alt="logo" class="logo" onerror="this.style.display='none'">
            <h1 class="title"><?php echo app_h($title); ?></h1>
            <div class="sub"><?php echo app_h($subtitle); ?></div>
            <div class="lang-links">
                <a href="<?php echo app_h($langArUrl); ?>">العربية</a>
                <span class="sep">|</span>
                <a href="<?php echo app_h($langEnUrl); ?>">English</a>
            </div>
        </div>

        <?php if ($error !== ''): ?>
            <div class="msg err"><i class="fa-solid fa-triangle-exclamation"></i> <?php echo app_h($error); ?></div>
        <?php elseif ($notice !== ''): ?>
            <div class="msg ok"><i class="fa-solid fa-circle-check"></i> <?php echo app_h($notice); ?></div>
        <?php endif; ?>

        <form method="post">
            <?php echo app_csrf_input(); ?>
            <div class="field">
                <label class="label" for="emailInput"><?php echo app_h($emailLabel); ?></label>
                <div class="input-wrap">
                    <i class="fa-solid fa-envelope icon"></i>
                    <input id="emailInput" class="input" type="email" name="email" placeholder="<?php echo app_h($emailPlaceholder); ?>" value="<?php echo app_h($emailValue); ?>" required autocomplete="email">
                </div>
            </div>
            <button class="btn" type="submit"><?php echo app_h($sendButton); ?></button>
        </form>

        <a href="login.php" class="back-link"><i class="fa-solid fa-arrow-left"></i> <?php echo app_h($backLogin); ?></a>
    </div>
</body>
</html>
