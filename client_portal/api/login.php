<?php
// client_portal/api/login.php
ob_start();
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/db_connect.php';

api_require_post_csrf();
api_start_session();
api_rate_limit_or_fail('client_portal_login', 15, 300, 'client_portal_login');

$data = api_read_json();
$loginValueRaw = trim((string)($data['phone'] ?? $data['email'] ?? $_POST['phone'] ?? $_POST['email'] ?? ''));
$phone = api_clean_phone($loginValueRaw);
$email = strtolower(trim($loginValueRaw));
$password = (string) ($data['password'] ?? ($_POST['password'] ?? ''));

if (($phone === '' && $email === '') || $password === '') {
    api_json(['status' => 'error', 'message' => 'بيانات غير مكتملة'], 422);
}

try {
    $stmt = $pdo->prepare('
        SELECT id, name, phone, email, password_hash
        FROM clients
        WHERE phone = ?
           OR REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(phone, " ", ""), "-", ""), "+", ""), "(", ""), ")", "") = ?
           OR LOWER(email) = ?
        LIMIT 1
    ');
    $stmt->execute([$phone, $phone, $email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user || empty($user['password_hash']) || !password_verify($password, $user['password_hash'])) {
        if (isset($GLOBALS['conn']) && $GLOBALS['conn'] instanceof mysqli) {
            app_audit_log_add($GLOBALS['conn'], 'auth.client_api_login_failed', [
                'actor_type' => 'client',
                'actor_name' => 'anonymous',
                'entity_type' => 'auth',
                'entity_key' => $phone !== '' ? $phone : $email,
                'details' => ['mode' => 'client_api'],
            ]);
        }
        api_json(['status' => 'error', 'message' => 'رقم الهاتف أو كلمة المرور غير صحيحة'], 401);
    }

    session_regenerate_id(true);
    api_set_client_session($user);
    if (isset($GLOBALS['conn']) && $GLOBALS['conn'] instanceof mysqli) {
        app_audit_log_add($GLOBALS['conn'], 'auth.client_api_login_success', [
            'user_id' => (int)$user['id'],
            'actor_type' => 'client',
            'actor_name' => (string)($user['name'] ?? ''),
            'entity_type' => 'client',
            'entity_key' => (string)($user['phone'] ?? $user['email'] ?? $user['id']),
            'details' => ['mode' => 'client_api'],
        ]);
    }

    api_json([
        'status' => 'success',
        'message' => 'تم تسجيل الدخول بنجاح',
        'redirect' => '../dashboard.html',
        'csrf_token' => api_csrf_token(),
    ]);
} catch (PDOException $e) {
    error_log('Login Error: ' . $e->getMessage());
    api_json(['status' => 'error', 'message' => 'تعذر تسجيل الدخول حالياً'], 500);
}
