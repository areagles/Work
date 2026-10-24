<?php
// profile.php - Profile management (secure + schema-tolerant)

require 'auth.php';
require 'config.php';
app_start_session();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    app_require_csrf();
}

function profile_get_columns(mysqli $conn): array
{
    static $cols = null;
    if ($cols !== null) {
        return $cols;
    }

    $cols = [];
    try {
        $res = $conn->query('SHOW COLUMNS FROM users');
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $cols[(string)$row['Field']] = true;
            }
        }
    } catch (Throwable $e) {
        $cols = [];
    }

    return $cols;
}

function profile_has_column(mysqli $conn, string $column): bool
{
    $cols = profile_get_columns($conn);
    return isset($cols[$column]);
}

function profile_bind_params(mysqli_stmt $stmt, string $types, array $values): void
{
    if ($types === '') {
        return;
    }

    $bind = [];
    $bind[] = &$types;
    foreach ($values as $key => $value) {
        $values[$key] = $value;
        $bind[] = &$values[$key];
    }
    call_user_func_array([$stmt, 'bind_param'], $bind);
}

function profile_fetch_user(mysqli $conn, int $userId): ?array
{
    $stmt = $conn->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();

    return $row ?: null;
}

function profile_avatar_url(array $user): string
{
    $profilePic = trim((string)($user['profile_pic'] ?? ''));
    if ($profilePic !== '') {
        return $profilePic;
    }

    $avatar = trim((string)($user['avatar'] ?? ''));
    if ($avatar !== '') {
        if (strpos($avatar, '/') !== false) {
            return $avatar;
        }
        return 'uploads/users/' . $avatar;
    }

    $name = trim((string)($user['full_name'] ?? $user['username'] ?? 'User'));
    return 'https://ui-avatars.com/api/?name=' . rawurlencode($name) . '&background=2f2f2f&color=fff';
}

function profile_cleanup_old_avatars(array $oldUser, string $newAvatarPath): void
{
    $targets = [];

    $profilePic = trim((string)($oldUser['profile_pic'] ?? ''));
    if ($profilePic !== '' && basename($profilePic) !== 'default.png' && $profilePic !== $newAvatarPath) {
        $targets[$profilePic] = ['path' => $profilePic, 'base' => 'uploads/avatars'];
    }

    $avatar = trim((string)($oldUser['avatar'] ?? ''));
    if ($avatar !== '' && basename($avatar) !== 'default.png') {
        $candidate = (strpos($avatar, '/') !== false) ? $avatar : ('uploads/users/' . $avatar);
        if ($candidate !== $newAvatarPath) {
            $allowed = (strpos($candidate, 'uploads/avatars/') === 0) ? 'uploads/avatars' : 'uploads/users';
            $targets[$candidate] = ['path' => $candidate, 'base' => $allowed];
        }
    }

    foreach ($targets as $item) {
        app_safe_unlink($item['path'], $item['base']);
    }
}

$userId = (int)($_SESSION['user_id'] ?? 0);
if ($userId <= 0) {
    app_safe_redirect('login.php');
}

$user = profile_fetch_user($conn, $userId);
if (!$user) {
    session_unset();
    session_destroy();
    app_safe_redirect('login.php');
}

$hasEmail = profile_has_column($conn, 'email');
$hasPhone = profile_has_column($conn, 'phone');
$hasProfilePic = profile_has_column($conn, 'profile_pic');
$hasAvatar = profile_has_column($conn, 'avatar');
$hasCreatedAt = profile_has_column($conn, 'created_at');

$notice = '';
$noticeType = 'success';

$ok = (string)($_GET['ok'] ?? '');
if ($ok === 'saved') {
    $notice = 'تم حفظ بيانات الملف الشخصي بنجاح.';
    $noticeType = 'success';
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && (string)($_POST['action'] ?? '') === 'save_profile') {
        $fullName = trim((string)($_POST['full_name'] ?? ''));
        $email = trim((string)($_POST['email'] ?? ''));
        $phone = trim((string)($_POST['phone'] ?? ''));
        $themeChoice = trim((string)($_POST['ui_theme_preset'] ?? 'system'));

        $currentPassword = (string)($_POST['current_password'] ?? '');
        $newPassword = (string)($_POST['new_password'] ?? '');
        $confirmPassword = (string)($_POST['confirm_password'] ?? '');

        if ($fullName === '') {
            $notice = 'الاسم الكامل مطلوب.';
            $noticeType = 'error';
        }

        if ($notice === '' && $hasEmail && $email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $notice = 'صيغة البريد الإلكتروني غير صحيحة.';
            $noticeType = 'error';
        }

        $changePassword = ($newPassword !== '' || $confirmPassword !== '');
        if ($notice === '' && $changePassword) {
            if (strlen($newPassword) < 6) {
                $notice = 'كلمة المرور الجديدة يجب أن تكون 6 أحرف على الأقل.';
                $noticeType = 'error';
            } elseif ($newPassword !== $confirmPassword) {
                $notice = 'تأكيد كلمة المرور غير مطابق.';
                $noticeType = 'error';
            } else {
                $savedHash = (string)($user['password'] ?? '');
                if ($savedHash !== '' && !password_verify($currentPassword, $savedHash)) {
                    $notice = 'كلمة المرور الحالية غير صحيحة.';
                    $noticeType = 'error';
                }
            }
        }

        $newAvatarPath = null;
        if ($notice === '' && isset($_FILES['avatar']) && (int)($_FILES['avatar']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $upload = app_store_uploaded_file($_FILES['avatar'], [
                'dir' => 'uploads/avatars',
                'prefix' => 'profile_' . $userId . '_',
                'max_size' => 5 * 1024 * 1024,
                'allowed_extensions' => ['jpg', 'jpeg', 'png', 'gif', 'webp'],
                'allowed_mimes' => ['image/jpeg', 'image/png', 'image/gif', 'image/webp'],
            ]);

            if (!$upload['ok']) {
                $notice = 'فشل رفع الصورة: ' . (string)$upload['error'];
                $noticeType = 'error';
            } else {
                $newAvatarPath = (string)$upload['path'];
            }
        }

        if ($notice === '') {
            $set = ['full_name = ?'];
            $types = 's';
            $values = [$fullName];

            if ($hasEmail) {
                $set[] = 'email = ?';
                $types .= 's';
                $values[] = $email;
            }
            if ($hasPhone) {
                $set[] = 'phone = ?';
                $types .= 's';
                $values[] = $phone;
            }

            if ($newAvatarPath !== null) {
                if ($hasProfilePic) {
                    $set[] = 'profile_pic = ?';
                    $types .= 's';
                    $values[] = $newAvatarPath;
                }
                if ($hasAvatar) {
                    $avatarColValue = $hasProfilePic ? basename($newAvatarPath) : $newAvatarPath;
                    $set[] = 'avatar = ?';
                    $types .= 's';
                    $values[] = $avatarColValue;
                }
            }

            if ($changePassword) {
                $set[] = 'password = ?';
                $types .= 's';
                $values[] = password_hash($newPassword, PASSWORD_DEFAULT);
            }

            $sql = 'UPDATE users SET ' . implode(', ', $set) . ' WHERE id = ?';
            $types .= 'i';
            $values[] = $userId;

            $stmt = $conn->prepare($sql);
            profile_bind_params($stmt, $types, $values);
            $stmt->execute();
            $stmt->close();

            if ($newAvatarPath !== null) {
                profile_cleanup_old_avatars($user, $newAvatarPath);
            }

            $validThemes = app_ui_theme_presets();
            if ($themeChoice === '' || $themeChoice === 'system') {
                app_setting_set($conn, app_ui_theme_user_setting_key($userId), '');
            } elseif (isset($validThemes[$themeChoice])) {
                app_setting_set($conn, app_ui_theme_user_setting_key($userId), $themeChoice);
            }

            $_SESSION['name'] = $fullName;
            header('Location: profile.php?ok=saved');
            exit;
        }

        if ($newAvatarPath !== null && is_file($newAvatarPath)) {
            app_safe_unlink($newAvatarPath, 'uploads/avatars');
        }
    }
} catch (Throwable $e) {
    $notice = 'تعذر حفظ البيانات حالياً. راجع المدخلات ثم أعد المحاولة.';
    $noticeType = 'error';
}

$user = profile_fetch_user($conn, $userId);
if (!$user) {
    session_unset();
    session_destroy();
    app_safe_redirect('login.php');
}

$avatarUrl = profile_avatar_url($user);
$avatarSrc = (strpos($avatarUrl, 'uploads/') === 0) ? ($avatarUrl . '?t=' . time()) : $avatarUrl;
$roleLabel = trim((string)($user['role'] ?? 'employee'));
$createdAt = $hasCreatedAt ? trim((string)($user['created_at'] ?? '')) : '';
$profileThemePresets = app_ui_theme_presets();
$profileSystemThemeKey = app_ui_theme_system_key($conn);
$profileUserThemeKey = app_ui_theme_user_key($conn, $userId);
$profileSelectedThemeKey = $profileUserThemeKey !== '' ? $profileUserThemeKey : 'system';

require 'header.php';
?>

<style>
    .profile-page {
        max-width: 1220px;
        margin: 0 auto;
        padding: 20px;
    }
    .profile-title {
        margin: 0 0 8px;
        color: #f6f6f6;
        font-size: 1.42rem;
        font-weight: 900;
    }
    .profile-subtitle {
        margin: 0 0 18px;
        color: #9a9a9a;
        font-size: 0.95rem;
    }
    .profile-alert {
        border-radius: 12px;
        padding: 12px 14px;
        margin-bottom: 16px;
        border: 1px solid transparent;
    }
    .profile-alert.success {
        background: rgba(46, 204, 113, 0.1);
        border-color: rgba(46, 204, 113, 0.45);
        color: #8ce9b2;
    }
    .profile-alert.error {
        background: rgba(231, 76, 60, 0.1);
        border-color: rgba(231, 76, 60, 0.45);
        color: #ffaaa1;
    }

    .profile-grid {
        display: grid;
        grid-template-columns: 320px 1fr;
        gap: 18px;
        align-items: start;
    }
    .profile-card {
        background: #141414;
        border: 1px solid #2c2c2c;
        border-radius: 14px;
        padding: 18px;
        box-shadow: 0 10px 28px rgba(0, 0, 0, 0.36);
    }
    .profile-side {
        position: sticky;
        top: 94px;
    }

    .avatar-wrap {
        width: 130px;
        height: 130px;
        margin: 0 auto 12px;
        position: relative;
    }
    .avatar-img {
        width: 100%;
        height: 100%;
        border-radius: 50%;
        object-fit: cover;
        border: 3px solid rgba(212, 175, 55, 0.72);
        background: #222;
    }
    .avatar-upload {
        display: none;
    }
    .avatar-edit-btn {
        position: absolute;
        left: 2px;
        bottom: 2px;
        width: 34px;
        height: 34px;
        border-radius: 50%;
        border: 1px solid rgba(212, 175, 55, 0.7);
        background: #111;
        color: var(--gold-primary);
        display: inline-flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
    }

    .profile-name {
        text-align: center;
        margin: 0;
        font-size: 1.08rem;
        color: #f1f1f1;
    }
    .profile-role {
        margin: 8px auto 0;
        width: max-content;
        padding: 5px 12px;
        border-radius: 999px;
        border: 1px solid rgba(212, 175, 55, 0.35);
        background: rgba(212, 175, 55, 0.08);
        color: #d7bf78;
        font-size: 0.78rem;
    }
    .profile-meta {
        margin-top: 14px;
        border-top: 1px solid #2a2a2a;
        padding-top: 12px;
        font-size: 0.88rem;
        color: #b1b1b1;
        line-height: 1.9;
    }

    .profile-section-title {
        margin: 0 0 14px;
        color: #f5f5f5;
        font-size: 1.03rem;
    }
    .profile-fields {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 12px;
    }
    .profile-field.full {
        grid-column: 1 / -1;
    }
    .profile-field label {
        display: block;
        margin-bottom: 6px;
        color: #bcbcbc;
        font-size: 0.86rem;
    }
    .profile-input {
        width: 100%;
        border: 1px solid #363636;
        border-radius: 10px;
        background: #0f0f0f;
        color: #f3f3f3;
        padding: 10px 12px;
        font-family: 'Cairo', sans-serif;
    }
    .profile-input:focus {
        outline: none;
        border-color: rgba(212, 175, 55, 0.75);
        box-shadow: 0 0 0 2px rgba(212, 175, 55, 0.12);
    }
    .profile-input.readonly {
        color: #9d9d9d;
        background: #151515;
    }
    .profile-help {
        margin-top: 6px;
        color: #8d8d8d;
        font-size: 0.8rem;
    }

    .password-box {
        margin-top: 14px;
        border: 1px dashed #3a3a3a;
        border-radius: 12px;
        padding: 12px;
        background: #101010;
    }
    .password-title {
        margin: 0 0 10px;
        color: #d7bf78;
        font-size: 0.92rem;
    }
    .profile-theme-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
        gap: 12px;
        margin-top: 12px;
    }
    .profile-theme-input { position: absolute; opacity: 0; pointer-events: none; }
    .profile-theme-card {
        display: block;
        border-radius: 16px;
        border: 1px solid rgba(255,255,255,.08);
        background: #111;
        overflow: hidden;
        cursor: pointer;
        transition: transform .18s ease, border-color .18s ease, box-shadow .18s ease;
    }
    .profile-theme-card:hover { transform: translateY(-1px); }
    .profile-theme-input:checked + .profile-theme-card {
        border-color: var(--ae-gold);
        box-shadow: 0 0 0 1px color-mix(in srgb, var(--ae-gold) 55%, transparent), 0 18px 30px rgba(0,0,0,.28);
    }
    .profile-theme-preview {
        height: 74px;
        padding: 10px;
        display: grid;
        align-content: end;
        gap: 8px;
    }
    .profile-theme-swatches { display: flex; gap: 7px; }
    .profile-theme-swatches span {
        width: 18px;
        height: 18px;
        border-radius: 50%;
        border: 1px solid rgba(255,255,255,.18);
    }
    .profile-theme-lines { display: grid; gap: 6px; }
    .profile-theme-lines i {
        display: block;
        height: 7px;
        border-radius: 999px;
        background: rgba(255,255,255,.14);
    }
    .profile-theme-lines i:first-child { width: 62%; }
    .profile-theme-lines i:last-child { width: 82%; }
    .profile-theme-body { padding: 11px 12px 14px; display: grid; gap: 4px; }
    .profile-theme-name { font-size: .91rem; font-weight: 800; color: #f4f4f4; }
    .profile-theme-key { font-size: .74rem; color: #999; }

    .profile-actions {
        margin-top: 16px;
        display: flex;
        justify-content: flex-end;
        gap: 10px;
    }
    .profile-btn {
        border: 1px solid #3a3a3a;
        border-radius: 10px;
        padding: 11px 16px;
        font-family: 'Cairo', sans-serif;
        font-weight: 700;
        cursor: pointer;
    }
    .profile-btn.primary {
        color: #000;
        background: linear-gradient(135deg, var(--gold-primary), #a37c26);
    }
    .profile-btn.secondary {
        color: #ddd;
        background: #1e1e1e;
    }

    @media (max-width: 960px) {
        .profile-grid {
            grid-template-columns: 1fr;
        }
        .profile-side {
            position: static;
        }
    }
    @media (max-width: 720px) {
        .profile-fields {
            grid-template-columns: 1fr;
        }
    }
</style>

<div class="profile-page">
    <h2 class="profile-title">الملف الشخصي</h2>
    <p class="profile-subtitle">تحديث البيانات الأساسية للحساب بصورة آمنة ومنظمة.</p>

    <?php if ($notice !== ''): ?>
        <div class="profile-alert <?php echo $noticeType === 'error' ? 'error' : 'success'; ?>"><?php echo app_h($notice); ?></div>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data" class="profile-grid" autocomplete="off">
        <input type="hidden" name="action" value="save_profile">
        <?php echo app_csrf_input(); ?>

        <aside class="profile-card profile-side">
            <div class="avatar-wrap">
                <img id="avatarPreview" class="avatar-img" src="<?php echo app_h($avatarSrc); ?>" alt="Profile avatar">
                <label class="avatar-edit-btn" for="avatarInput" title="تغيير الصورة">
                    <i class="fa-solid fa-camera"></i>
                </label>
                <input class="avatar-upload" id="avatarInput" type="file" name="avatar" accept="image/jpeg,image/png,image/gif,image/webp">
            </div>

            <h3 class="profile-name"><?php echo app_h((string)($user['full_name'] ?? '')); ?></h3>
            <div class="profile-role"><?php echo app_h($roleLabel); ?></div>

            <div class="profile-meta">
                <div><strong>اسم المستخدم:</strong> <?php echo app_h((string)($user['username'] ?? '')); ?></div>
                <?php if ($createdAt !== ''): ?>
                    <div><strong>تاريخ الإنشاء:</strong> <?php echo app_h($createdAt); ?></div>
                <?php endif; ?>
                <div style="font-size:0.8rem;color:#8f8f8f;margin-top:6px;">يفضل رفع صورة مربعة وواضحة لتظهر بشكل أفضل داخل النظام.</div>
            </div>
        </aside>

        <section class="profile-card">
            <h3 class="profile-section-title">البيانات الأساسية</h3>
            <div class="profile-fields">
                <div class="profile-field">
                    <label>الاسم الكامل</label>
                    <input class="profile-input" type="text" name="full_name" required maxlength="120" value="<?php echo app_h((string)($user['full_name'] ?? '')); ?>">
                </div>

                <div class="profile-field">
                    <label>اسم المستخدم</label>
                    <input class="profile-input readonly" type="text" value="<?php echo app_h((string)($user['username'] ?? '')); ?>" readonly>
                    <div class="profile-help">تعديل اسم المستخدم يتم من صفحة إدارة المستخدمين فقط.</div>
                </div>

                <?php if ($hasEmail): ?>
                <div class="profile-field">
                    <label>البريد الإلكتروني</label>
                    <input class="profile-input" type="email" name="email" maxlength="120" value="<?php echo app_h((string)($user['email'] ?? '')); ?>">
                </div>
                <?php else: ?>
                <div class="profile-field">
                    <label>البريد الإلكتروني</label>
                    <input class="profile-input readonly" type="text" value="غير مدعوم في هيكل قاعدة البيانات الحالي" readonly>
                </div>
                <?php endif; ?>

                <?php if ($hasPhone): ?>
                <div class="profile-field">
                    <label>الهاتف</label>
                    <input class="profile-input" type="text" name="phone" maxlength="30" value="<?php echo app_h((string)($user['phone'] ?? '')); ?>">
                </div>
                <?php endif; ?>
            </div>

            <div class="password-box">
                <h4 class="password-title">تغيير كلمة المرور (اختياري)</h4>
                <div class="profile-fields">
                    <div class="profile-field full">
                        <label>كلمة المرور الحالية</label>
                        <input class="profile-input" type="password" name="current_password" autocomplete="new-password">
                    </div>
                    <div class="profile-field">
                        <label>كلمة المرور الجديدة</label>
                        <input class="profile-input" type="password" name="new_password" minlength="6" autocomplete="new-password">
                    </div>
                    <div class="profile-field">
                        <label>تأكيد كلمة المرور الجديدة</label>
                        <input class="profile-input" type="password" name="confirm_password" minlength="6" autocomplete="new-password">
                    </div>
                </div>
                <div class="profile-help">عند ترك حقول كلمة المرور فارغة، سيتم حفظ باقي البيانات فقط دون تغيير كلمة المرور.</div>
            </div>

            <div class="password-box">
                <h4 class="password-title">ثيم العرض الشخصي</h4>
                <div class="profile-help">يمكنك اختيار ثيم خاص بك أو العودة دائمًا إلى ثيم النظام العام.</div>
                <div class="profile-theme-grid">
                    <div>
                        <input class="profile-theme-input" type="radio" id="profile_theme_system" name="ui_theme_preset" value="system" <?php echo $profileSelectedThemeKey === 'system' ? 'checked' : ''; ?>>
                        <label class="profile-theme-card" for="profile_theme_system">
                            <div class="profile-theme-preview" style="background:linear-gradient(160deg,#0b0b0b,#171717);">
                                <div class="profile-theme-swatches">
                                    <span style="background:var(--ae-gold);"></span>
                                    <span style="background:#363636;"></span>
                                    <span style="background:#f2f2f2;"></span>
                                </div>
                                <div class="profile-theme-lines">
                                    <i style="background:#f2f2f2;"></i>
                                    <i style="background:var(--ae-gold);"></i>
                                </div>
                            </div>
                            <div class="profile-theme-body">
                                <div class="profile-theme-name">ثيم النظام العام</div>
                                <div class="profile-theme-key"><?php echo app_h($profileSystemThemeKey); ?></div>
                            </div>
                        </label>
                    </div>
                    <?php foreach ($profileThemePresets as $presetKey => $preset): ?>
                        <?php
                        $accent = (string)($preset['accent'] ?? '#d4af37');
                        $accentSoft = (string)($preset['accent_soft'] ?? '#f2d47a');
                        $bg = (string)($preset['bg'] ?? '#050505');
                        $card = (string)($preset['card'] ?? '#121212');
                        $textColor = (string)($preset['text'] ?? '#f2f2f2');
                        $label = (string)($preset['label_ar'] ?? $preset['label'] ?? $presetKey);
                        ?>
                        <div>
                            <input class="profile-theme-input" type="radio" id="profile_theme_<?php echo app_h($presetKey); ?>" name="ui_theme_preset" value="<?php echo app_h($presetKey); ?>" <?php echo $profileSelectedThemeKey === $presetKey ? 'checked' : ''; ?>>
                            <label class="profile-theme-card" for="profile_theme_<?php echo app_h($presetKey); ?>">
                                <div class="profile-theme-preview" style="background:linear-gradient(160deg,<?php echo app_h($bg); ?>,<?php echo app_h($card); ?>);">
                                    <div class="profile-theme-swatches">
                                        <span style="background:<?php echo app_h($accent); ?>;"></span>
                                        <span style="background:<?php echo app_h($accentSoft); ?>;"></span>
                                        <span style="background:<?php echo app_h($card); ?>;"></span>
                                    </div>
                                    <div class="profile-theme-lines">
                                        <i style="background:<?php echo app_h($textColor); ?>;"></i>
                                        <i style="background:<?php echo app_h($accentSoft); ?>;"></i>
                                    </div>
                                </div>
                                <div class="profile-theme-body">
                                    <div class="profile-theme-name"><?php echo app_h($label); ?></div>
                                    <div class="profile-theme-key"><?php echo app_h($presetKey); ?></div>
                                </div>
                            </label>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="profile-actions">
                <a class="profile-btn secondary" href="dashboard.php">عودة</a>
                <button class="profile-btn primary" type="submit">حفظ التغييرات</button>
            </div>
        </section>
    </form>
</div>

<script>
(function () {
    var input = document.getElementById('avatarInput');
    var preview = document.getElementById('avatarPreview');
    if (!input || !preview) {
        return;
    }

    input.addEventListener('change', function () {
        if (!input.files || !input.files[0]) {
            return;
        }
        var reader = new FileReader();
        reader.onload = function (e) {
            preview.src = e.target && e.target.result ? e.target.result : preview.src;
        };
        reader.readAsDataURL(input.files[0]);
    });
})();
</script>

<?php require 'footer.php'; ?>
