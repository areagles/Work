<?php
// portal/api/register.php
ob_start();
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/db_connect.php';

api_require_post_csrf();
api_rate_limit_or_fail('client_portal_register', 10, 900, 'client_portal_register');

$data = api_read_json();
$name = api_clean_text($data['name'] ?? '', 120);
$phone = api_clean_phone($data['phone'] ?? '');
$emailRaw = api_clean_text($data['email'] ?? '', 120);
$address = api_clean_text($data['address'] ?? '', 255);
$passwordRaw = (string) ($data['password'] ?? '');

if ($name === '' || $phone === '' || $passwordRaw === '') {
    api_json(['status' => 'error', 'message' => 'يرجى ملء كافة البيانات المطلوبة'], 422);
}

if (strlen($passwordRaw) < 8) {
    api_json(['status' => 'error', 'message' => 'كلمة المرور يجب أن تكون 8 أحرف على الأقل'], 422);
}

$email = '';
if ($emailRaw !== '') {
    $validated = filter_var($emailRaw, FILTER_VALIDATE_EMAIL);
    if (!$validated) {
        api_json(['status' => 'error', 'message' => 'صيغة البريد الإلكتروني غير صحيحة'], 422);
    }
    $email = $validated;
}

$passwordHash = password_hash($passwordRaw, PASSWORD_DEFAULT);
$accessToken = bin2hex(random_bytes(16));

try {
    $checkSql = 'SELECT id FROM clients WHERE phone = ?';
    $params = [$phone];

    if ($email !== '') {
        $checkSql .= ' OR email = ?';
        $params[] = $email;
    }

    $checkStmt = $pdo->prepare($checkSql);
    $checkStmt->execute($params);

    if ($checkStmt->fetch()) {
        api_json(['status' => 'error', 'message' => 'رقم الهاتف أو البريد مسجل بالفعل'], 409);
    }

    $insert = $pdo->prepare(
        'INSERT INTO clients (name, phone, email, password_hash, address, access_token, created_at, opening_balance)
         VALUES (?, ?, ?, ?, ?, ?, NOW(), 0.00)'
    );

    $insert->execute([$name, $phone, $email, $passwordHash, $address, $accessToken]);
    api_json(['status' => 'success', 'message' => 'تم إنشاء الحساب بنجاح']);
} catch (PDOException $e) {
    error_log('Register Error: ' . $e->getMessage());
    api_json(['status' => 'error', 'message' => 'تعذر إنشاء الحساب حالياً'], 500);
}
