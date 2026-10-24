<?php
// Dynamic web app manifest based on system settings.
require_once 'config.php';

header('Content-Type: application/manifest+json; charset=utf-8');

$appName = app_setting_get($conn, 'app_name', 'Arab Eagles ERP');
$shortName = mb_substr($appName, 0, 12);
$themeColor = app_normalize_hex_color(app_setting_get($conn, 'theme_color', '#d4af37'));

$manifest = [
    'name' => $appName,
    'short_name' => $shortName !== '' ? $shortName : 'ArabEagles',
    'description' => 'نظام إدارة العمليات المتكامل',
    'start_url' => 'dashboard.php',
    'scope' => '.',
    'display' => 'standalone',
    'background_color' => '#0a0a0a',
    'theme_color' => $themeColor,
    'orientation' => 'portrait-primary',
    'dir' => 'rtl',
    'lang' => 'ar',
    'icons' => [
        [
            'src' => 'assets/img/icon-192x192.png',
            'sizes' => '192x192',
            'type' => 'image/png',
            'purpose' => 'any maskable',
        ],
        [
            'src' => 'assets/img/icon-512x512.png',
            'sizes' => '512x512',
            'type' => 'image/png',
            'purpose' => 'any maskable',
        ],
    ],
];

echo json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
