<?php
require 'config.php';
app_start_session();
app_handle_lang_switch($conn);

$host = strtolower(trim((string)($_SERVER['HTTP_HOST'] ?? '')));
$isLocalHost = (
    $host === ''
    || strpos($host, 'localhost') === 0
    || strpos($host, '127.0.0.1') === 0
    || strpos($host, '[::1]') === 0
);
if (!$isLocalHost && !app_env_flag('APP_ALLOW_REMOTE_LINK_SETUP', false)) {
    http_response_code(403);
    echo 'Access denied';
    exit;
}

if (!function_exists('dls_env_parse')) {
    function dls_env_parse(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }
        $out = [];
        $lines = @file($path, FILE_IGNORE_NEW_LINES);
        if (!is_array($lines)) {
            return [];
        }
        foreach ($lines as $line) {
            $line = trim((string)$line);
            if ($line === '' || strpos($line, '#') === 0) {
                continue;
            }
            $idx = strpos($line, '=');
            if ($idx === false || $idx <= 0) {
                continue;
            }
            $key = trim(substr($line, 0, $idx));
            $val = trim(substr($line, $idx + 1));
            if ($key !== '') {
                $out[$key] = $val;
            }
        }
        return $out;
    }
}

if (!function_exists('dls_env_set_values')) {
    function dls_env_set_values(string $path, array $values): bool
    {
        $existing = [];
        if (is_file($path)) {
            $existing = @file($path, FILE_IGNORE_NEW_LINES);
            if (!is_array($existing)) {
                $existing = [];
            }
        }

        $keys = array_keys($values);
        $done = array_fill_keys($keys, false);
        $out = [];

        foreach ($existing as $line) {
            $raw = (string)$line;
            $trimmed = trim($raw);
            $matched = false;
            foreach ($values as $key => $value) {
                if (strpos($trimmed, $key . '=') === 0) {
                    $out[] = $key . '=' . $value;
                    $done[$key] = true;
                    $matched = true;
                    break;
                }
            }
            if (!$matched) {
                $out[] = $raw;
            }
        }

        foreach ($values as $key => $value) {
            if (empty($done[$key])) {
                $out[] = $key . '=' . $value;
            }
        }

        $payload = implode(PHP_EOL, $out);
        if ($payload !== '' && substr($payload, -1) !== "\n") {
            $payload .= PHP_EOL;
        }

        return @file_put_contents($path, $payload, LOCK_EX) !== false;
    }
}

if (!function_exists('dls_safe_value')) {
    function dls_safe_value(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, 190, 'UTF-8');
        }
        return substr($value, 0, 190);
    }
}

if (!function_exists('dls_parse_activation_blob')) {
    function dls_parse_activation_blob(string $blob): array
    {
        $out = [
            'APP_LICENSE_EDITION' => '',
            'APP_LICENSE_REMOTE_URL' => '',
            'APP_LICENSE_REMOTE_TOKEN' => '',
            'APP_LICENSE_KEY' => '',
            'APP_DESKTOP_LINKED_SYSTEM' => '',
            'APP_CLOUD_SYNC_REMOTE_URL' => '',
            'APP_CLOUD_SYNC_REMOTE_TOKEN' => '',
            'APP_SUPER_USER_USERNAME' => '',
            'APP_SUPER_USER_EMAIL' => '',
            'APP_SUPER_USER_ID' => '',
        ];
        $blob = trim(str_replace(["\r\n", "\r"], "\n", $blob));
        if ($blob === '') {
            return $out;
        }

        $decodeAutoPackage = static function (string $encoded) use (&$out): void {
            $encoded = trim($encoded);
            if ($encoded === '') {
                return;
            }
            if (stripos($encoded, 'AEAUTO://') === 0) {
                $encoded = substr($encoded, 9);
            }
            $json = base64_decode($encoded, true);
            if (!is_string($json) || $json === '') {
                return;
            }
            $payload = json_decode($json, true);
            if (!is_array($payload)) {
                return;
            }
            $out['APP_LICENSE_EDITION'] = trim((string)($payload['edition'] ?? $out['APP_LICENSE_EDITION']));
            $out['APP_LICENSE_REMOTE_URL'] = trim((string)($payload['owner_api_url'] ?? $out['APP_LICENSE_REMOTE_URL']));
            $out['APP_LICENSE_REMOTE_TOKEN'] = trim((string)($payload['owner_api_token'] ?? $out['APP_LICENSE_REMOTE_TOKEN']));
            $out['APP_LICENSE_KEY'] = trim((string)($payload['license_key'] ?? $out['APP_LICENSE_KEY']));
            $out['APP_CLOUD_SYNC_REMOTE_URL'] = trim((string)($payload['cloud_sync_url'] ?? $out['APP_CLOUD_SYNC_REMOTE_URL']));
            $out['APP_CLOUD_SYNC_REMOTE_TOKEN'] = trim((string)($payload['cloud_sync_token'] ?? $out['APP_CLOUD_SYNC_REMOTE_TOKEN']));
            $out['APP_DESKTOP_LINKED_SYSTEM'] = trim((string)($payload['linked_system'] ?? $out['APP_DESKTOP_LINKED_SYSTEM']));
        };

        if (stripos($blob, 'AEAUTO://') === 0 && strpos($blob, "\n") === false) {
            $decodeAutoPackage($blob);
            return $out;
        }

        $lines = explode("\n", $blob);
        foreach ($lines as $lineRaw) {
            $line = trim((string)$lineRaw);
            if ($line === '' || strpos($line, '#') === 0) {
                continue;
            }
            if (stripos($line, 'AEAUTO://') === 0) {
                $decodeAutoPackage($line);
                continue;
            }
            $eq = strpos($line, '=');
            if ($eq === false || $eq <= 0) {
                continue;
            }
            $k = trim(substr($line, 0, $eq));
            $v = trim(substr($line, $eq + 1));
            if ($k === '') {
                continue;
            }
            if ($k === 'APP_AUTO_LINK_PACKAGE') {
                $decodeAutoPackage($v);
                continue;
            }
            if (!array_key_exists($k, $out)) {
                continue;
            }
            $out[$k] = trim($v, " \t\n\r\0\x0B\"'");
        }
        return $out;
    }
}

if (!function_exists('dls_is_placeholder')) {
    function dls_is_placeholder(string $value, array $placeholders): bool
    {
        $v = strtoupper(trim($value));
        if ($v === '') {
            return true;
        }
        foreach ($placeholders as $p) {
            if ($v === strtoupper(trim((string)$p))) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('dls_remote_call')) {
    function dls_remote_call(string $url, string $token, array $payload): array
    {
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($body) || $body === '') {
            return ['ok' => false, 'error' => 'encode_failed'];
        }
        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
            'Authorization: Bearer ' . trim($token),
        ];

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_TIMEOUT => 20,
            ]);
            $raw = curl_exec($ch);
            $errno = curl_errno($ch);
            $error = curl_error($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);
            if ($errno !== 0) {
                return ['ok' => false, 'error' => $error !== '' ? $error : 'curl_failed', 'http_code' => $status];
            }
        } else {
            $context = stream_context_create([
                'http' => [
                    'method' => 'POST',
                    'header' => implode("\r\n", $headers),
                    'content' => $body,
                    'timeout' => 20,
                    'ignore_errors' => true,
                ],
            ]);
            $raw = @file_get_contents($url, false, $context);
            $status = 0;
            $responseHeaders = function_exists('http_get_last_response_headers')
                ? (array)http_get_last_response_headers()
                : [];
            if (!empty($responseHeaders[0]) && preg_match('/\s(\d{3})\s/', (string)$responseHeaders[0], $m)) {
                $status = (int)$m[1];
            }
        }

        $data = json_decode((string)$raw, true);
        if (!is_array($data)) {
            $data = ['raw' => (string)$raw];
        }
        $data['http_code'] = $status ?? 0;
        $data['ok'] = !empty($data['ok']) || (($status ?? 0) >= 200 && ($status ?? 0) < 300);
        return $data;
    }
}

$envDir = __DIR__ . '/desktop_runtime';
if (!is_dir($envDir)) {
    $fallbackDir = __DIR__ . '/runtime_template/desktop_runtime';
    if (is_dir($fallbackDir)) {
        $envDir = $fallbackDir;
    }
}
$envFile = $envDir . '/.env';
$envExampleFile = $envDir . '/.env.example';
if (!is_file($envFile) && is_file($envExampleFile)) {
    @copy($envExampleFile, $envFile);
}
$envVals = dls_env_parse($envFile);
$requestedEdition = strtolower(trim((string)($_GET['edition'] ?? '')));
$currentEdition = strtolower(trim((string)($envVals['APP_LICENSE_EDITION'] ?? app_license_edition())));
if (!in_array($currentEdition, ['owner', 'client'], true)) {
    $currentEdition = 'client';
}
if (in_array($requestedEdition, ['owner', 'client'], true) && (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST')) {
    $currentEdition = $requestedEdition;
}
$currentSuperUserId = max(0, (int)($envVals['APP_SUPER_USER_ID'] ?? app_env('APP_SUPER_USER_ID', '0')));
$currentSuperUserUsername = trim((string)($envVals['APP_SUPER_USER_USERNAME'] ?? app_env('APP_SUPER_USER_USERNAME', '')));
$currentSuperUserEmail = trim((string)($envVals['APP_SUPER_USER_EMAIL'] ?? app_env('APP_SUPER_USER_EMAIL', '')));

app_initialize_license_data($conn);
$licenseRow = app_license_row($conn);

$dbRemoteUrl = trim((string)($licenseRow['remote_url'] ?? ''));
$dbRemoteToken = trim((string)($licenseRow['remote_token'] ?? ''));
$dbLicenseKey = trim((string)($licenseRow['license_key'] ?? ''));

$currentRemoteUrl = trim((string)($envVals['APP_LICENSE_REMOTE_URL'] ?? $dbRemoteUrl));
$currentRemoteToken = trim((string)($envVals['APP_LICENSE_REMOTE_TOKEN'] ?? $dbRemoteToken));
$currentLicenseKey = trim((string)($envVals['APP_LICENSE_KEY'] ?? $dbLicenseKey));
$currentLinkedSystem = trim((string)($envVals['APP_DESKTOP_LINKED_SYSTEM'] ?? app_setting_get($conn, 'desktop_node_linked_system', (string)app_env('APP_DESKTOP_LINKED_SYSTEM', 'work'))));

$isConfigured = false;
if ($currentEdition === 'owner') {
    $isConfigured = ($currentSuperUserId > 0 || $currentSuperUserUsername !== '' || $currentSuperUserEmail !== '');
} else {
    $isConfigured = (
        !dls_is_placeholder($currentRemoteUrl, ['', 'HTTPS://OWNER.EXAMPLE.COM/LICENSE_API.PHP'])
        && !dls_is_placeholder($currentRemoteToken, ['', 'CHANGE_ME', 'PUT_CLIENT_API_TOKEN_HERE'])
        && !dls_is_placeholder($currentLicenseKey, ['', 'SET_CLIENT_LICENSE_KEY', 'SET_CLIENT_LICENSE_KEY_HERE', 'PUT_CLIENT_LICENSE_KEY_HERE'])
    );
}

$noticeType = '';
$noticeText = '';
$activationBlob = '';
$manualRemoteUrl = $currentRemoteUrl;
$manualRemoteToken = '';
$manualLicenseKey = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!app_verify_csrf($_POST['_csrf_token'] ?? '')) {
        $noticeType = 'err';
        $noticeText = app_tr('انتهت صلاحية الجلسة. حدّث الصفحة ثم أعد المحاولة.', 'Session expired. Refresh the page and try again.');
    } else {
        $targetEdition = strtolower(trim((string)($_POST['edition'] ?? $currentEdition)));
        $activationBlob = trim((string)($_POST['activation_blob'] ?? ''));
        if (!in_array($targetEdition, ['owner', 'client'], true)) {
            $targetEdition = $currentEdition;
        }
        $parsedBlob = dls_parse_activation_blob($activationBlob);
        if (!empty($parsedBlob['APP_LICENSE_EDITION'])) {
            $ed = strtolower(trim((string)$parsedBlob['APP_LICENSE_EDITION']));
            if (in_array($ed, ['owner', 'client'], true)) {
                $targetEdition = $ed;
            }
        }

        $manualRemoteUrl = trim((string)($_POST['remote_url'] ?? ''));
        $manualRemoteToken = trim((string)($_POST['remote_token'] ?? ''));
        $manualLicenseKey = strtoupper(trim((string)($_POST['license_key'] ?? '')));

        $remoteUrl = $manualRemoteUrl;
        if ($remoteUrl === '' && !empty($parsedBlob['APP_LICENSE_REMOTE_URL'])) {
            $remoteUrl = trim((string)$parsedBlob['APP_LICENSE_REMOTE_URL']);
        }
        if ($remoteUrl === '' && $currentRemoteUrl !== '') {
            $remoteUrl = $currentRemoteUrl;
        }
        $remoteToken = $manualRemoteToken;
        if ($remoteToken === '' && !empty($parsedBlob['APP_LICENSE_REMOTE_TOKEN'])) {
            $remoteToken = trim((string)$parsedBlob['APP_LICENSE_REMOTE_TOKEN']);
        }
        if ($remoteToken === '' && $currentRemoteToken !== '') {
            $remoteToken = $currentRemoteToken;
        }
        $licenseKey = $manualLicenseKey;
        if ($licenseKey === '' && !empty($parsedBlob['APP_LICENSE_KEY'])) {
            $licenseKey = strtoupper(trim((string)$parsedBlob['APP_LICENSE_KEY']));
        }
        if ($licenseKey === '' && $currentLicenseKey !== '') {
            $licenseKey = $currentLicenseKey;
        }

        $superUserId = max(0, (int)($_POST['super_user_id'] ?? 0));
        if ($superUserId <= 0 && !empty($parsedBlob['APP_SUPER_USER_ID'])) {
            $superUserId = max(0, (int)$parsedBlob['APP_SUPER_USER_ID']);
        }
        $superUserUsername = trim((string)($_POST['super_user_username'] ?? ''));
        if ($superUserUsername === '' && !empty($parsedBlob['APP_SUPER_USER_USERNAME'])) {
            $superUserUsername = trim((string)$parsedBlob['APP_SUPER_USER_USERNAME']);
        }
        $superUserEmail = trim((string)($_POST['super_user_email'] ?? ''));
        if ($superUserEmail === '' && !empty($parsedBlob['APP_SUPER_USER_EMAIL'])) {
            $superUserEmail = trim((string)$parsedBlob['APP_SUPER_USER_EMAIL']);
        }
        $linkedSystem = trim((string)($_POST['linked_system'] ?? ''));
        if ($linkedSystem === '' && !empty($parsedBlob['APP_DESKTOP_LINKED_SYSTEM'])) {
            $linkedSystem = trim((string)$parsedBlob['APP_DESKTOP_LINKED_SYSTEM']);
        }
        if ($linkedSystem === '' && $currentLinkedSystem !== '') {
            $linkedSystem = $currentLinkedSystem;
        }

        if ($targetEdition === 'client' && ($remoteUrl === '' || !filter_var($remoteUrl, FILTER_VALIDATE_URL))) {
            $noticeType = 'err';
            $noticeText = app_tr('أدخل رابط API صالح.', 'Enter a valid API URL.');
        } elseif ($targetEdition === 'client' && ($remoteToken === '' || strlen($remoteToken) < 12)) {
            $noticeType = 'err';
            $noticeText = app_tr('أدخل Token صحيح (12 حرفاً على الأقل).', 'Enter a valid token (at least 12 characters).');
        } elseif ($targetEdition === 'client' && ($licenseKey === '' || strlen($licenseKey) < 8)) {
            $noticeType = 'err';
            $noticeText = app_tr('أدخل مفتاح الترخيص الصحيح.', 'Enter a valid license key.');
        } elseif ($targetEdition === 'owner' && $superUserId <= 0 && $superUserUsername === '' && $superUserEmail === '') {
            $noticeType = 'err';
            $noticeText = app_tr('في نسخة المالك أدخل هوية Super User واحدة على الأقل (ID أو Username أو Email).', 'For owner edition, provide at least one Super User identifier (ID, username, or email).');
        } elseif ($remoteUrl !== '' && !filter_var($remoteUrl, FILTER_VALIDATE_URL)) {
            $noticeType = 'err';
            $noticeText = app_tr('رابط API غير صالح.', 'Invalid API URL.');
        } else {
            $remoteUrl = dls_safe_value($remoteUrl);
            $remoteToken = dls_safe_value($remoteToken);
            $licenseKey = dls_safe_value($licenseKey);
            $superUserUsername = dls_safe_value($superUserUsername);
            $superUserEmail = dls_safe_value($superUserEmail);
            $linkedSystem = preg_replace('/[^a-z0-9_-]/i', '', strtolower($linkedSystem));
            if ($linkedSystem === '') {
                $linkedSystem = 'work';
            }

            if ($targetEdition === 'owner') {
                // Owner desktop is locally managed; credentials can stay empty unless explicitly set.
                if ($remoteToken === 'CHANGE_ME') {
                    $remoteToken = '';
                }
                if (dls_is_placeholder($licenseKey, ['SET_CLIENT_LICENSE_KEY', 'SET_CLIENT_LICENSE_KEY_HERE', 'PUT_CLIENT_LICENSE_KEY_HERE'])) {
                    $licenseKey = '';
                }
            }

            $stmt = $conn->prepare('UPDATE app_license_state SET remote_url = ?, remote_token = ?, license_key = ? WHERE id = 1');
            $stmt->bind_param('sss', $remoteUrl, $remoteToken, $licenseKey);
            $stmt->execute();
            $stmt->close();

            app_setting_set($conn, 'app_license_edition', $targetEdition);

            $cloudSyncUrl = trim((string)$parsedBlob['APP_CLOUD_SYNC_REMOTE_URL']);
            $cloudSyncToken = trim((string)$parsedBlob['APP_CLOUD_SYNC_REMOTE_TOKEN']);

            $envUpdates = [
                'APP_LICENSE_EDITION' => $targetEdition,
                'APP_LICENSE_REMOTE_URL' => $remoteUrl,
                'APP_LICENSE_REMOTE_TOKEN' => $remoteToken,
                'APP_LICENSE_KEY' => $licenseKey,
                'APP_LICENSE_REMOTE_ONLY' => $targetEdition === 'owner' ? '0' : '1',
                'APP_LICENSE_REMOTE_LOCK' => $targetEdition === 'owner' ? '0' : '1',
                'APP_DESKTOP_LINKED_SYSTEM' => $linkedSystem,
            ];
            if ($cloudSyncUrl !== '') {
                $envUpdates['APP_CLOUD_SYNC_REMOTE_URL'] = dls_safe_value($cloudSyncUrl);
            }
            if ($cloudSyncToken !== '') {
                $envUpdates['APP_CLOUD_SYNC_REMOTE_TOKEN'] = dls_safe_value($cloudSyncToken);
            }
            if ($targetEdition === 'owner') {
                $envUpdates['APP_SUPER_USER_ID'] = $superUserId > 0 ? (string)$superUserId : '';
                $envUpdates['APP_SUPER_USER_USERNAME'] = $superUserUsername;
                $envUpdates['APP_SUPER_USER_EMAIL'] = $superUserEmail;
                app_setting_set($conn, 'super_user_id', $superUserId > 0 ? (string)$superUserId : '0');
                app_setting_set($conn, 'super_user_username', strtolower($superUserUsername));
                app_setting_set($conn, 'super_user_email', strtolower($superUserEmail));

                // Ensure owner desktop can enter immediately as owner edition.
                $ownerName = app_setting_get($conn, 'app_name', 'Arab Eagles');
                $stmtOwner = $conn->prepare("
                    UPDATE app_license_state
                    SET plan_type = 'lifetime',
                        license_status = 'active',
                        trial_started_at = NULL,
                        trial_ends_at = NULL,
                        subscription_ends_at = NULL,
                        owner_name = ?,
                        last_error = ''
                    WHERE id = 1
                ");
                $stmtOwner->bind_param('s', $ownerName);
                $stmtOwner->execute();
                $stmtOwner->close();
            }
            dls_env_set_values($envFile, $envUpdates);
            app_setting_set($conn, 'desktop_node_linked_system', $linkedSystem);

            $sync = app_license_sync_remote($conn, true);
            $desktopNodeRegister = ['ok' => false, 'reason' => 'skipped'];
            if (
                $targetEdition === 'client'
                && $remoteUrl !== ''
                && $remoteToken !== ''
                && filter_var($remoteUrl, FILTER_VALIDATE_URL)
            ) {
                $installationCode = function_exists('app_cloud_sync_default_installation_code')
                    ? app_cloud_sync_default_installation_code($conn)
                    : strtoupper(substr(hash('sha256', (string)app_base_url()), 0, 10));
                $nodeName = trim((string)app_env('APP_DESKTOP_NODE_NAME', ''));
                if ($nodeName === '') {
                    $nodeName = php_uname('n');
                }
                $desktopNodeRegister = dls_remote_call($remoteUrl, $remoteToken, [
                    'event' => 'desktop_node_register',
                    'installation_code' => $installationCode,
                    'node_name' => $nodeName,
                    'edition' => $targetEdition,
                    'linked_system' => $linkedSystem,
                    'platform' => PHP_OS_FAMILY,
                    'app_version' => 'desktop-bootstrap-1',
                    'db_label' => trim((string)app_setting_get($conn, 'cloud_sync_local_db_label', 'Desktop Local DB')),
                    'metadata' => [
                        'local_url' => rtrim((string)app_base_url(), '/'),
                        'license_key_suffix' => $licenseKey !== '' ? substr($licenseKey, -6) : '',
                    ],
                ]);
                app_setting_set($conn, 'desktop_node_installation_code', $installationCode);
                app_setting_set($conn, 'desktop_node_remote_status', trim((string)($desktopNodeRegister['policy']['status'] ?? 'pending')));
                app_setting_set($conn, 'desktop_node_force_read_only', (string)(int)($desktopNodeRegister['policy']['force_read_only'] ?? 0));
                app_setting_set($conn, 'desktop_node_force_password_reset', (string)(int)($desktopNodeRegister['policy']['force_password_reset'] ?? 0));
            }
            $isConfigured = $targetEdition === 'owner'
                ? ($superUserId > 0 || $superUserUsername !== '' || $superUserEmail !== '')
                : true;

            app_audit_log_add($conn, 'license.desktop_link_saved', [
                'entity_type' => 'license_setup',
                'entity_key' => 'desktop_link',
                'details' => [
                    'edition' => $targetEdition,
                    'configured' => $isConfigured ? 1 : 0,
                    'remote_url' => $remoteUrl !== '' ? $remoteUrl : null,
                    'license_key_suffix' => $licenseKey !== '' ? substr($licenseKey, -6) : '',
                    'sync_ok' => !empty($sync['ok']) ? 1 : 0,
                    'sync_error' => (string)($sync['error'] ?? ''),
                    'desktop_node_register_ok' => !empty($desktopNodeRegister['ok']) ? 1 : 0,
                ],
            ]);

            if ($targetEdition === 'owner') {
                $noticeType = 'ok';
                $noticeText = app_tr('تم حفظ وضع نسخة المالك بنجاح. يمكنك الدخول مباشرة للنظام الإداري.', 'Owner edition mode saved successfully. You can now login directly to admin system.');
            } elseif (!empty($sync['ok'])) {
                $noticeType = 'ok';
                $noticeText = app_tr('تم حفظ الربط والتحقق بنجاح. يمكنك المتابعة إلى تسجيل الدخول.', 'Link settings saved and verified successfully. You can continue to login.');
            } else {
                $noticeType = 'ok';
                $noticeText = app_tr('تم حفظ الربط. تعذر التحقق اللحظي الآن وسيتم الفحص تلقائياً لاحقاً.', 'Link settings saved. Live verification failed now and will retry automatically later.');
            }

            if ((string)($_POST['save_and_login'] ?? '') === '1') {
                header('Location: login.php?desktop_linked=1');
                exit;
            }

            $currentEdition = $targetEdition;
            $currentRemoteUrl = $remoteUrl;
            $currentRemoteToken = $remoteToken;
            $currentLicenseKey = $licenseKey;
            $currentLinkedSystem = $linkedSystem;
            $manualRemoteUrl = $currentRemoteUrl;
            $manualRemoteToken = '';
            $manualLicenseKey = '';
            $currentSuperUserId = $superUserId;
            $currentSuperUserUsername = $superUserUsername;
            $currentSuperUserEmail = $superUserEmail;
        }
    }
}

$isEnglish = app_current_lang($conn) === 'en';
$appName = app_setting_get($conn, 'app_name', 'Arab Eagles');
$appLogo = app_brand_logo_path($conn, 'assets/img/Logo.png');
$langArUrl = app_lang_switch_url('ar');
$langEnUrl = app_lang_switch_url('en');
$showAdvanced = ($noticeType === 'err') || ((string)($_GET['advanced'] ?? '') === '1');
?>
<!doctype html>
<html lang="<?php echo $isEnglish ? 'en' : 'ar'; ?>" dir="<?php echo $isEnglish ? 'ltr' : 'rtl'; ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title><?php echo app_h(app_tr('ربط النظام', 'System Link Setup')); ?> | <?php echo app_h($appName); ?></title>
    <link rel="stylesheet" href="assets/css/brand.css">
    <style>
        body{margin:0;background:#080b12;color:#f2f2f2;font-family:'Cairo',Tahoma,Arial,sans-serif}
        .wrap{max-width:760px;margin:32px auto;padding:0 14px}
        .card{background:#111620;border:1px solid rgba(212,175,55,.26);border-radius:16px;padding:18px}
        .brand{display:flex;align-items:center;gap:12px;margin-bottom:14px}
        .brand img{width:58px;height:58px;border-radius:12px;object-fit:cover;border:1px solid rgba(212,175,55,.36)}
        h1{margin:0;color:#f4d98d;font-size:1.45rem}
        .sub{color:#b6c2d1;font-size:.92rem;line-height:1.7;margin:2px 0 0}
        .lang{display:flex;gap:8px;margin-top:8px}
        .lang a{padding:6px 10px;border-radius:9px;border:1px solid #3a4662;color:#d9e4f0;text-decoration:none;font-size:.8rem}
        .lang a.active{border-color:rgba(212,175,55,.6);background:rgba(212,175,55,.14);color:#ffe8a8}
        .note{margin:12px 0;padding:10px 12px;border-radius:10px;background:rgba(32,57,99,.16);border:1px solid rgba(95,141,232,.35);color:#dce8ff;line-height:1.7}
        .alert{margin:12px 0;padding:10px 12px;border-radius:10px;border:1px solid;font-weight:700}
        .alert.ok{background:rgba(58,170,104,.16);border-color:rgba(58,170,104,.46);color:#9df0c0}
        .alert.err{background:rgba(191,76,76,.16);border-color:rgba(191,76,76,.46);color:#ffc4c4}
        .f{display:flex;flex-direction:column;gap:6px;margin:10px 0}
        textarea{min-height:120px;border-radius:11px;border:1px solid rgba(212,175,55,.28);background:#0a0f17;color:#f2f2f2;padding:10px 12px;font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace}
        label{color:#c7d1dc;font-size:.9rem}
        input{min-height:42px;border-radius:11px;border:1px solid rgba(212,175,55,.28);background:#0a0f17;color:#f2f2f2;padding:10px 12px}
        .actions{display:flex;flex-wrap:wrap;gap:10px;margin-top:14px}
        .btn{border:1px solid transparent;border-radius:10px;padding:10px 14px;font-weight:800;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center}
        .btn.gold{background:#d4af37;color:#111}
        .btn.dark{background:#222938;border-color:#3f4c66;color:#f3f3f3}
        .edition-links{display:flex;flex-wrap:wrap;gap:8px;margin:12px 0 4px}
        .edition-links a{padding:8px 10px;border-radius:10px;border:1px solid #3a4662;color:#d9e4f0;text-decoration:none;font-weight:700;font-size:.82rem}
        .edition-links a.active{border-color:rgba(212,175,55,.6);background:rgba(212,175,55,.14);color:#ffe8a8}
        .advanced{margin-top:10px;border:1px dashed rgba(212,175,55,.32);border-radius:12px;background:rgba(9,12,20,.45)}
        .advanced>summary{cursor:pointer;list-style:none;padding:10px 12px;color:#f2d484;font-weight:800;display:flex;align-items:center;justify-content:space-between}
        .advanced>summary::-webkit-details-marker{display:none}
        .advanced-body{border-top:1px dashed rgba(212,175,55,.22);padding:10px 12px 4px}
    </style>
</head>
<body>
<div class="wrap">
    <div class="card">
        <div class="brand">
            <img src="<?php echo app_h($appLogo); ?>" alt="logo" onerror="this.style.display='none'">
            <div>
                <h1><?php echo app_h(app_tr('ربط نسخة سطح المكتب', 'Desktop Link Setup')); ?></h1>
                <p class="sub"><?php echo app_h(app_tr('للعميل: الصق حزمة الربط القادمة من النظام السحابي ثم اضغط زر واحد "حفظ والدخول".', 'For client: paste the link package from cloud, then click one button "Save & Login".')); ?></p>
            </div>
        </div>

        <div class="lang">
            <a href="<?php echo app_h($langArUrl); ?>" class="<?php echo $isEnglish ? '' : 'active'; ?>">العربية</a>
            <a href="<?php echo app_h($langEnUrl); ?>" class="<?php echo $isEnglish ? 'active' : ''; ?>">English</a>
        </div>

        <?php if ($noticeText !== ''): ?>
            <div class="alert <?php echo $noticeType === 'ok' ? 'ok' : 'err'; ?>"><?php echo app_h($noticeText); ?></div>
        <?php endif; ?>

        <?php if ($isConfigured): ?>
            <div class="note"><?php echo app_h(app_tr('الربط مهيأ حالياً. يمكنك تعديل القيم عند الحاجة.', 'Link is currently configured. You can update values when needed.')); ?></div>
        <?php else: ?>
            <div class="note"><?php echo app_h(app_tr('الربط غير مكتمل. الصق حزمة الربط الكاملة في الحقل التالي، وسيتم تعبئة البيانات تلقائياً.', 'Link is not configured yet. Paste the full activation package below and data will be filled automatically.')); ?></div>
        <?php endif; ?>
        <div class="edition-links">
            <a href="desktop_link_setup.php?edition=client" class="<?php echo $currentEdition === 'client' ? 'active' : ''; ?>"><?php echo app_h(app_tr('إعداد نسخة العميل', 'Client setup')); ?></a>
            <a href="desktop_link_setup.php?edition=owner" class="<?php echo $currentEdition === 'owner' ? 'active' : ''; ?>"><?php echo app_h(app_tr('تفعيل نسخة المالك', 'Owner setup')); ?></a>
        </div>

        <form method="post" novalidate>
            <?php echo app_csrf_input(); ?>
            <div class="f">
                <label><?php echo app_h(app_tr('ربط سريع (موصى به)', 'Quick link (recommended)')); ?></label>
                <textarea name="activation_blob" placeholder="<?php echo app_h(app_tr('الصق هنا رسالة التفعيل كاملة القادمة من نظام المالك (APP_LICENSE_REMOTE_URL / TOKEN / KEY).', 'Paste full activation message from owner system here (APP_LICENSE_REMOTE_URL / TOKEN / KEY).')); ?>"><?php echo app_h($activationBlob); ?></textarea>
            </div>
            <details class="advanced" <?php echo $showAdvanced ? 'open' : ''; ?>>
                <summary><?php echo app_h(app_tr('إعداد يدوي (اختياري)', 'Manual setup (optional)')); ?></summary>
                <div class="advanced-body">
                    <div class="f">
                        <label><?php echo app_h(app_tr('نوع النسخة', 'Edition type')); ?></label>
                        <select name="edition" id="editionSelect" style="min-height:42px;border-radius:11px;border:1px solid rgba(212,175,55,.28);background:#0a0f17;color:#f2f2f2;padding:10px 12px">
                            <option value="client" <?php echo $currentEdition === 'client' ? 'selected' : ''; ?>><?php echo app_h(app_tr('Client (نسخة العميل)', 'Client edition')); ?></option>
                            <option value="owner" <?php echo $currentEdition === 'owner' ? 'selected' : ''; ?>><?php echo app_h(app_tr('Owner/Admin (نسخة المالك)', 'Owner/Admin edition')); ?></option>
                        </select>
                    </div>
                    <div class="f">
                        <label><?php echo app_h(app_tr('رابط API للتحقق', 'License API URL')); ?></label>
                        <input type="url" name="remote_url" value="<?php echo app_h($manualRemoteUrl); ?>" placeholder="https://owner.example.com/license_api.php" required>
                    </div>
                    <div class="f">
                        <label><?php echo app_h(app_tr('Token التوثيق', 'Bearer token')); ?></label>
                        <input type="text" name="remote_token" value="<?php echo app_h($manualRemoteToken); ?>" placeholder="<?php echo app_h(app_tr('ألصق التوكن أو اتركه فارغاً للاحتفاظ بالقيمة الحالية', 'Paste token, or leave blank to keep current value')); ?>" autocomplete="off" required>
                    </div>
                    <div class="f">
                        <label><?php echo app_h(app_tr('مفتاح الترخيص', 'License key')); ?></label>
                        <input type="text" name="license_key" value="<?php echo app_h($manualLicenseKey); ?>" placeholder="<?php echo app_h(app_tr('AE-CLI-XXXXXXXXXXXX أو اتركه فارغاً للاحتفاظ بالقيمة الحالية', 'AE-CLI-XXXXXXXXXXXX or leave blank to keep current value')); ?>" autocomplete="off" required>
                    </div>
                    <div id="ownerFields" style="<?php echo $currentEdition === 'owner' ? '' : 'display:none;'; ?>">
                        <div class="f">
                            <label><?php echo app_h(app_tr('Super User Username (اختياري)', 'Super User Username (optional)')); ?></label>
                            <input type="text" name="super_user_username" value="<?php echo app_h($currentSuperUserUsername); ?>" placeholder="owner_super_admin" autocomplete="off">
                        </div>
                        <div class="f">
                            <label><?php echo app_h(app_tr('Super User Email (اختياري)', 'Super User Email (optional)')); ?></label>
                            <input type="email" name="super_user_email" value="<?php echo app_h($currentSuperUserEmail); ?>" placeholder="owner@example.com" autocomplete="off">
                        </div>
                        <div class="f">
                            <label><?php echo app_h(app_tr('Super User ID (اختياري)', 'Super User ID (optional)')); ?></label>
                            <input type="number" min="0" name="super_user_id" value="<?php echo (int)$currentSuperUserId; ?>">
                        </div>
                    </div>
                </div>
            </details>
            <div class="actions">
                <button type="submit" class="btn gold" name="save_and_login" value="1"><?php echo app_h(app_tr('حفظ والدخول', 'Save & Login')); ?></button>
                <button type="submit" class="btn dark"><?php echo app_h(app_tr('حفظ فقط', 'Save only')); ?></button>
                <a href="login.php" class="btn dark"><?php echo app_h(app_tr('الذهاب لتسجيل الدخول', 'Go to Login')); ?></a>
            </div>
        </form>
    </div>
</div>
<script>
(function () {
    const edition = document.getElementById('editionSelect');
    if (!edition) return;
    const ownerFields = document.getElementById('ownerFields');
    const remoteUrl = document.querySelector('input[name="remote_url"]');
    const remoteToken = document.querySelector('input[name="remote_token"]');
    const licenseKey = document.querySelector('input[name="license_key"]');

    function syncEditionUI() {
        const isOwner = (edition.value || '').toLowerCase() === 'owner';
        if (ownerFields) {
            ownerFields.style.display = isOwner ? '' : 'none';
        }
        if (remoteUrl) remoteUrl.required = !isOwner;
        if (remoteToken) remoteToken.required = !isOwner;
        if (licenseKey) licenseKey.required = !isOwner;
    }

    edition.addEventListener('change', syncEditionUI);
    syncEditionUI();
})();
</script>
</body>
</html>
