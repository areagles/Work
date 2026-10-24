<?php
// Desktop bootstrap auto-update checker.
// Runs inside container after runtime startup to pull/apply latest package from owner endpoint.

require_once __DIR__ . '/../config.php';

if (!function_exists('desktop_auto_update_log')) {
    function desktop_auto_update_log(string $message): void
    {
        $text = trim($message);
        if ($text === '') {
            return;
        }
        echo '[desktop-auto-update] ' . $text . PHP_EOL;
    }
}

if (!function_exists('desktop_auto_update_normalize_url')) {
    function desktop_auto_update_normalize_url(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }

        $url = preg_replace('/\s+/', '', $url) ?: '';
        if ($url === '') {
            return '';
        }

        $url = rtrim($url, '/');
        if (str_ends_with($url, '/license_api.php')) {
            return substr($url, 0, -strlen('/license_api.php')) . '/update_api.php';
        }
        if (str_ends_with($url, '/api/license/check')) {
            return substr($url, 0, -strlen('/api/license/check')) . '/api/update/check';
        }
        return $url;
    }
}

if (!function_exists('desktop_auto_update_remote_url')) {
    function desktop_auto_update_remote_url(mysqli $conn): string
    {
        $candidates = [
            trim((string)app_setting_get($conn, 'update_remote_url', '')),
            trim((string)app_env('APP_UPDATE_REMOTE_URL', '')),
            trim((string)app_env('APP_LICENSE_REMOTE_URL', '')),
        ];

        foreach ($candidates as $value) {
            $normalized = desktop_auto_update_normalize_url($value);
            if ($normalized !== '') {
                return $normalized;
            }
        }
        return '';
    }
}

if (!function_exists('desktop_auto_update_remote_token')) {
    function desktop_auto_update_remote_token(mysqli $conn): string
    {
        $candidates = [
            trim((string)app_setting_get($conn, 'update_remote_token', '')),
            trim((string)app_env('APP_UPDATE_REMOTE_TOKEN', '')),
            trim((string)app_env('APP_LICENSE_REMOTE_TOKEN', '')),
        ];

        foreach ($candidates as $value) {
            if ($value !== '' && strtoupper($value) !== 'CHANGE_ME') {
                return $value;
            }
        }
        return '';
    }
}

if (!function_exists('desktop_auto_update_channel')) {
    function desktop_auto_update_channel(mysqli $conn): string
    {
        $channel = trim((string)app_setting_get($conn, 'update_channel', ''));
        if ($channel === '') {
            $channel = trim((string)app_env('APP_UPDATE_CHANNEL', 'stable'));
        }
        $channel = strtolower($channel);
        return in_array($channel, ['stable', 'beta'], true) ? $channel : 'stable';
    }
}

if (!function_exists('desktop_auto_update_is_enabled')) {
    function desktop_auto_update_is_enabled(): bool
    {
        $flag = trim((string)app_env('AE_DESKTOP_AUTO_UPDATE', '1'));
        return !in_array(strtolower($flag), ['0', 'false', 'off', 'no'], true);
    }
}

try {
    if (!desktop_auto_update_is_enabled()) {
        desktop_auto_update_log('disabled by AE_DESKTOP_AUTO_UPDATE');
        exit(0);
    }

    if (!function_exists('app_ensure_update_center_schema') || !function_exists('app_update_pull_remote_package')) {
        desktop_auto_update_log('update center functions are not available in this build.');
        exit(0);
    }

    if (!app_ensure_update_center_schema($conn)) {
        desktop_auto_update_log('schema not ready; skipping.');
        exit(0);
    }

    $remoteUrl = desktop_auto_update_remote_url($conn);
    $remoteToken = desktop_auto_update_remote_token($conn);
    $channel = desktop_auto_update_channel($conn);

    if ($remoteUrl === '') {
        desktop_auto_update_log('remote URL is empty; skipping.');
        exit(0);
    }
    if ($remoteToken === '') {
        desktop_auto_update_log('remote token is empty; skipping.');
        exit(0);
    }

    if (!filter_var($remoteUrl, FILTER_VALIDATE_URL)) {
        desktop_auto_update_log('remote URL is invalid: ' . $remoteUrl);
        exit(0);
    }

    // Keep local settings synced so UI shows same source used by bootstrap.
    app_setting_set($conn, 'update_remote_url', $remoteUrl);
    app_setting_set($conn, 'update_remote_token', $remoteToken);
    app_setting_set($conn, 'update_channel', $channel);

    desktop_auto_update_log('checking latest package from ' . $remoteUrl . ' (' . $channel . ')');

    $result = app_update_pull_remote_package($conn, $remoteUrl, $remoteToken, [
        'edition' => app_license_edition(),
        'channel' => $channel,
        'performed_by' => 'desktop_auto_update',
        'force' => false,
    ]);

    if (empty($result['ok'])) {
        $error = trim((string)($result['error'] ?? 'unknown_error'));
        desktop_auto_update_log('check failed: ' . ($error !== '' ? $error : 'unknown_error'));
        exit(0);
    }

    if (empty($result['has_update'])) {
        if (!empty($result['skipped']) && (string)($result['reason'] ?? '') === 'already_latest') {
            desktop_auto_update_log('already on latest version.');
        } else {
            desktop_auto_update_log('no new package available.');
        }
        exit(0);
    }

    $versionTag = trim((string)($result['version_tag'] ?? ''));
    if ($versionTag !== '') {
        desktop_auto_update_log('update applied successfully: ' . $versionTag);
    } else {
        desktop_auto_update_log('update applied successfully.');
    }

    exit(0);
} catch (Throwable $e) {
    desktop_auto_update_log('fatal: ' . $e->getMessage());
    exit(0);
}
