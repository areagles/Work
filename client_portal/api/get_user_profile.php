<?php
// portal/api/get_user_profile.php
ob_start();
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/db_connect.php';

$clientId = api_require_login();
api_rate_limit_or_fail('client_portal_get_user_profile', 60, 300, api_rate_limit_scope('get_user_profile'));

try {
    try {
        $stmt = $pdo->prepare('SELECT id, name, email, phone, avatar_url FROM clients WHERE id = ? LIMIT 1');
        $stmt->execute([$clientId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        // توافق مع قواعد بيانات لا تحتوي على عمود avatar_url
        $stmt = $pdo->prepare('SELECT id, name, email, phone FROM clients WHERE id = ? LIMIT 1');
        $stmt->execute([$clientId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if (is_array($user) && !isset($user['avatar_url'])) {
            $user['avatar_url'] = '';
        }
    }

    if (!$user) {
        api_json(['status' => 'error', 'message' => 'لم يتم العثور على العميل'], 404);
    }

    $safe = [];
    foreach ($user as $key => $value) {
        $safe[$key] = is_string($value) ? htmlspecialchars($value, ENT_QUOTES, 'UTF-8') : $value;
    }

    api_json(['status' => 'success', 'user' => $safe]);
} catch (PDOException $e) {
    error_log('Get User Profile Error: ' . $e->getMessage());
    api_json(['status' => 'error', 'message' => 'تعذر جلب بيانات الملف الشخصي'], 500);
}
