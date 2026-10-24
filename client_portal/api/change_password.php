<?php
// portal/api/change_password.php
ob_start();
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/db_connect.php';

api_require_post_csrf();
$clientId = api_require_login();
api_rate_limit_or_fail('client_portal_change_password', 8, 900, api_rate_limit_scope('change_password'));

$currentPass = (string) ($_POST['current_password'] ?? '');
$newPass = (string) ($_POST['new_password'] ?? '');

if ($currentPass === '' || $newPass === '') {
    api_json(['status' => 'error', 'message' => 'جميع الحقول مطلوبة'], 422);
}

if (strlen($newPass) < 8) {
    api_json(['status' => 'error', 'message' => 'كلمة المرور الجديدة يجب أن تكون 8 أحرف على الأقل'], 422);
}

if ($newPass === $currentPass) {
    api_json(['status' => 'error', 'message' => 'يرجى اختيار كلمة مرور جديدة مختلفة'], 422);
}

try {
    $stmt = $pdo->prepare('SELECT password_hash FROM clients WHERE id = ? LIMIT 1');
    $stmt->execute([$clientId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user || empty($user['password_hash'])) {
        api_json(['status' => 'error', 'message' => 'المستخدم غير موجود'], 404);
    }

    if (!password_verify($currentPass, $user['password_hash'])) {
        api_json(['status' => 'error', 'message' => 'كلمة المرور الحالية غير صحيحة'], 401);
    }

    $newHash = password_hash($newPass, PASSWORD_DEFAULT);
    $update = $pdo->prepare('UPDATE clients SET password_hash = ? WHERE id = ?');
    $update->execute([$newHash, $clientId]);

    api_json(['status' => 'success', 'message' => 'تم تغيير كلمة المرور بنجاح']);
} catch (PDOException $e) {
    error_log('Change Password Error: ' . $e->getMessage());
    api_json(['status' => 'error', 'message' => 'حدث خطأ أثناء تحديث كلمة المرور'], 500);
}
