<?php
// client_portal/api/identity.php
ob_start();
require __DIR__ . '/bootstrap.php';
api_rate_limit_or_fail('client_portal_identity', 60, 300, 'client_portal_identity');

try {
    if (!isset($conn) || !($conn instanceof mysqli)) {
        api_json(['status' => 'error', 'message' => 'db_unavailable'], 500);
    }

    $appName = trim(app_setting_get($conn, 'app_name', 'Arab Eagles'));
    if ($appName === '') {
        $appName = 'Arab Eagles';
    }

    $themeColor = app_normalize_hex_color(app_setting_get($conn, 'theme_color', '#d4af37'));
    $logoPath = app_brand_logo_path($conn, 'assets/img/Logo.png');
    $logoPath = str_replace('\\', '/', trim((string)$logoPath));
    $isRemoteLogo = (bool)preg_match('#^https?://#i', $logoPath);
    $logoUrl = $isRemoteLogo ? $logoPath : ('/' . ltrim($logoPath, '/'));

    $supportEmail = trim(app_setting_get($conn, 'support_email', (string)app_env('APP_LICENSE_ALERT_EMAIL', '')));
    $supportWhatsapp = trim(app_setting_get($conn, 'support_whatsapp', ''));
    if ($supportWhatsapp !== '' && stripos($supportWhatsapp, 'http://') !== 0 && stripos($supportWhatsapp, 'https://') !== 0) {
        $digits = preg_replace('/[^0-9]/', '', $supportWhatsapp);
        if ($digits !== '') {
            $supportWhatsapp = 'https://wa.me/' . $digits;
        } else {
            $supportWhatsapp = '';
        }
    }

    $scriptName = (string)($_SERVER['SCRIPT_NAME'] ?? '/client_portal/api/identity.php');
    $portalBase = rtrim(dirname($scriptName, 2), '/') . '/';
    if ($portalBase === '//') {
        $portalBase = '/client_portal/';
    }

    api_json([
        'status' => 'success',
        'data' => [
            'app_name' => $appName,
            'theme_color' => $themeColor,
            'logo_url' => $logoUrl,
            'support_email' => $supportEmail,
            'support_whatsapp_url' => $supportWhatsapp,
            'portal_base' => $portalBase,
            'login_url' => $portalBase . 'login.html',
            'register_url' => $portalBase . 'register.html',
            'csrf_token' => api_csrf_token(),
        ],
    ]);
} catch (Throwable $e) {
    error_log('portal identity api failed: ' . $e->getMessage());
    api_json(['status' => 'error', 'message' => 'identity_failed'], 500);
}
