<?php
// reset_password.php
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

$title = $isEnglish ? 'Reset Password' : 'إعادة تعيين كلمة المرور';
$subtitle = $isEnglish ? 'Enter a new secure password' : 'أدخل كلمة مرور جديدة وآمنة';
$newPassLabel = $isEnglish ? 'New Password' : 'كلمة المرور الجديدة';
$confirmPassLabel = $isEnglish ? 'Confirm New Password' : 'تأكيد كلمة المرور الجديدة';
$newPassPlaceholder = $isEnglish ? 'At least 8 characters' : '8 أحرف على الأقل';
$submitText = $isEnglish ? 'Update Password' : 'تحديث كلمة المرور';
$backLogin = $isEnglish ? 'Back to Login' : 'العودة لتسجيل الدخول';
$forgotAgain = $isEnglish ? 'Request another reset link' : 'طلب رابط استعادة جديد';
$showText = $isEnglish ? 'Show password' : 'إظهار كلمة المرور';
$hideText = $isEnglish ? 'Hide password' : 'إخفاء كلمة المرور';

$invalidLinkMsg = $isEnglish
    ? 'This reset link is invalid or expired.'
    : 'رابط إعادة التعيين غير صالح أو انتهت صلاحيته.';
$csrfError = $isEnglish
    ? 'Session expired. Refresh and try again.'
    : 'انتهت صلاحية الجلسة. حدّث الصفحة ثم حاول مرة أخرى.';
$passwordMismatch = $isEnglish
    ? 'Password confirmation does not match.'
    : 'تأكيد كلمة المرور غير متطابق.';
$passwordShort = $isEnglish
    ? 'Password must be at least 8 characters.'
    : 'كلمة المرور يجب أن تكون 8 أحرف على الأقل.';
$successMsg = $isEnglish
    ? 'Password updated successfully. You can now sign in.'
    : 'تم تحديث كلمة المرور بنجاح. يمكنك الآن تسجيل الدخول.';

$selector = trim((string)($_GET['selector'] ?? $_POST['selector'] ?? ''));
$token = trim((string)($_GET['token'] ?? $_POST['token'] ?? ''));

$error = '';
$notice = '';
$success = false;

$selectorOk = (bool)preg_match('/^[a-f0-9]{16}$/i', $selector);
$tokenOk = (bool)preg_match('/^[a-f0-9]{64}$/i', $token);

$canUseLink = false;
$tokenRow = null;

if (!$selectorOk || !$tokenOk || !app_ensure_password_reset_schema($conn)) {
    $error = $invalidLinkMsg;
} else {
    $stmt = $conn->prepare("
        SELECT id, user_id, token_hash, expires_at, used_at
        FROM password_reset_tokens
        WHERE selector = ?
        LIMIT 1
    ");
    $stmt->bind_param('s', $selector);
    $stmt->execute();
    $res = $stmt->get_result();
    $tokenRow = $res ? $res->fetch_assoc() : null;
    $stmt->close();

    if ($tokenRow) {
        $isExpired = strtotime((string)$tokenRow['expires_at']) < time();
        $isUsed = !empty($tokenRow['used_at']);
        $hashMatch = hash_equals((string)$tokenRow['token_hash'], hash('sha256', $token));
        $canUseLink = (!$isExpired && !$isUsed && $hashMatch);
    }

    if (!$canUseLink) {
        $error = $invalidLinkMsg;
    }
}

if ($canUseLink && (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST')) {
    if (!app_verify_csrf($_POST['_csrf_token'] ?? '')) {
        $error = $csrfError;
    } else {
        $newPassword = (string)($_POST['new_password'] ?? '');
        $confirmPassword = (string)($_POST['confirm_password'] ?? '');

        if (strlen($newPassword) < 8) {
            $error = $passwordShort;
        } elseif ($newPassword !== $confirmPassword) {
            $error = $passwordMismatch;
        } else {
            $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
            $userId = (int)($tokenRow['user_id'] ?? 0);

            if ($userId <= 0 || !$passwordHash) {
                $error = $invalidLinkMsg;
            } else {
                try {
                    $conn->begin_transaction();

                    $stmtUser = $conn->prepare("UPDATE users SET password = ? WHERE id = ? LIMIT 1");
                    $stmtUser->bind_param('si', $passwordHash, $userId);
                    $stmtUser->execute();
                    $affected = (int)$stmtUser->affected_rows;
                    $stmtUser->close();

                    $stmtUsed = $conn->prepare("UPDATE password_reset_tokens SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL");
                    $stmtUsed->bind_param('i', $userId);
                    $stmtUsed->execute();
                    $stmtUsed->close();

                    $conn->commit();

                    if ($affected >= 0) {
                        $success = true;
                        $notice = $successMsg;
                        $canUseLink = false;
                    } else {
                        $error = $invalidLinkMsg;
                    }
                } catch (Throwable $e) {
                    $conn->rollback();
                    error_log('password reset update failed: ' . $e->getMessage());
                    $error = $invalidLinkMsg;
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
            width: min(470px, 92vw);
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
        .toggle {
            position: absolute;
            top: 50%;
            transform: translateY(-50%);
            inset-inline-end: 8px;
            width: 30px;
            height: 30px;
            border: 1px solid #333;
            background: #131313;
            color: #d7d7d7;
            border-radius: 8px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
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
            padding-inline-end: 44px;
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
        .links {
            margin-top: 14px;
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }
        .link {
            color: #d4af37;
            text-decoration: none;
            font-size: 0.86rem;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            gap: 7px;
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

        <?php if ($canUseLink && !$success): ?>
            <form method="post">
                <?php echo app_csrf_input(); ?>
                <input type="hidden" name="selector" value="<?php echo app_h($selector); ?>">
                <input type="hidden" name="token" value="<?php echo app_h($token); ?>">

                <div class="field">
                    <label class="label" for="newPassInput"><?php echo app_h($newPassLabel); ?></label>
                    <div class="input-wrap">
                        <i class="fa-solid fa-lock icon"></i>
                        <input id="newPassInput" class="input" type="password" name="new_password" placeholder="<?php echo app_h($newPassPlaceholder); ?>" required autocomplete="new-password">
                        <button type="button" class="toggle js-toggle-pass" data-target="newPassInput" aria-label="<?php echo app_h($showText); ?>" title="<?php echo app_h($showText); ?>">
                            <i class="fa-regular fa-eye"></i>
                        </button>
                    </div>
                </div>

                <div class="field">
                    <label class="label" for="confirmPassInput"><?php echo app_h($confirmPassLabel); ?></label>
                    <div class="input-wrap">
                        <i class="fa-solid fa-shield-halved icon"></i>
                        <input id="confirmPassInput" class="input" type="password" name="confirm_password" placeholder="<?php echo app_h($newPassPlaceholder); ?>" required autocomplete="new-password">
                        <button type="button" class="toggle js-toggle-pass" data-target="confirmPassInput" aria-label="<?php echo app_h($showText); ?>" title="<?php echo app_h($showText); ?>">
                            <i class="fa-regular fa-eye"></i>
                        </button>
                    </div>
                </div>

                <button class="btn" type="submit"><?php echo app_h($submitText); ?></button>
            </form>
        <?php endif; ?>

        <div class="links">
            <a href="login.php" class="link"><i class="fa-solid fa-arrow-left"></i> <?php echo app_h($backLogin); ?></a>
            <a href="forgot_password.php" class="link"><i class="fa-solid fa-rotate"></i> <?php echo app_h($forgotAgain); ?></a>
        </div>
    </div>

    <script>
        (function () {
            const showText = <?php echo json_encode($showText, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
            const hideText = <?php echo json_encode($hideText, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;

            document.querySelectorAll('.js-toggle-pass').forEach((btn) => {
                btn.addEventListener('click', () => {
                    const targetId = btn.getAttribute('data-target');
                    const input = targetId ? document.getElementById(targetId) : null;
                    if (!input) return;
                    const isHidden = input.type === 'password';
                    input.type = isHidden ? 'text' : 'password';
                    btn.setAttribute('aria-label', isHidden ? hideText : showText);
                    btn.setAttribute('title', isHidden ? hideText : showText);
                    const icon = btn.querySelector('i');
                    if (icon) {
                        icon.className = isHidden ? 'fa-regular fa-eye-slash' : 'fa-regular fa-eye';
                    }
                });
            });
        })();
    </script>
</body>
</html>
