<?php

if (!function_exists('app_env_root_dir')) {
    function app_env_root_dir(): string
    {
        return dirname(__DIR__, 2);
    }
}

if (!function_exists('app_env_file_values')) {
    function app_env_file_values(): array
    {
        static $loaded = false;
        static $values = [];
        if ($loaded) {
            return $values;
        }
        $loaded = true;

        $files = [
            app_env_root_dir() . '/.env',
            app_env_root_dir() . '/.app_env',
        ];
        foreach ($files as $filePath) {
            if (!is_file($filePath) || !is_readable($filePath)) {
                continue;
            }
            $lines = @file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if (!is_array($lines)) {
                continue;
            }
            foreach ($lines as $line) {
                $line = trim((string)$line);
                if ($line === '' || strpos($line, '#') === 0) {
                    continue;
                }
                $eqPos = strpos($line, '=');
                if ($eqPos === false || $eqPos < 1) {
                    continue;
                }
                $name = trim(substr($line, 0, $eqPos));
                $rawValue = trim(substr($line, $eqPos + 1));
                if ($name === '' || !preg_match('/^[A-Z0-9_]+$/i', $name)) {
                    continue;
                }
                if (
                    strlen($rawValue) >= 2
                    && (
                        ($rawValue[0] === '"' && substr($rawValue, -1) === '"')
                        || ($rawValue[0] === "'" && substr($rawValue, -1) === "'")
                    )
                ) {
                    $rawValue = substr($rawValue, 1, -1);
                }
                $value = str_replace(['\\n', '\\r', '\\t'], ["\n", "\r", "\t"], $rawValue);
                $values[$name] = $value;
                if (getenv($name) === false || getenv($name) === '') {
                    @putenv($name . '=' . $value);
                    $_ENV[$name] = $value;
                }
            }
        }

        return $values;
    }
}

if (!function_exists('app_env')) {
    function app_env(string $key, ?string $default = null): ?string
    {
        $fileValues = app_env_file_values();
        if (isset($fileValues[$key]) && $fileValues[$key] !== '') {
            return (string)$fileValues[$key];
        }

        $value = getenv($key);
        if ($value !== false && $value !== '') {
            return $value;
        }

        if (isset($_ENV[$key]) && $_ENV[$key] !== '') {
            return (string)$_ENV[$key];
        }

        return $default;
    }
}

if (!function_exists('app_env_flag')) {
    function app_env_flag(string $key, bool $default = false): bool
    {
        $fallback = $default ? '1' : '0';
        $raw = trim((string)app_env($key, $fallback));
        if ($raw === '') {
            return $default;
        }
        $raw = strtolower($raw);
        return in_array($raw, ['1', 'true', 'yes', 'on'], true);
    }
}

if (!function_exists('app_env_encode_scalar')) {
    function app_env_encode_scalar($value): string
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if ($value === null) {
            return '';
        }
        $raw = (string)$value;
        $raw = str_replace(["\r", "\n", "\t"], ['\\r', '\\n', '\\t'], $raw);
        if ($raw === '') {
            return '';
        }
        if (preg_match('/[\s#"\'=]/', $raw)) {
            $escaped = str_replace(['\\', '"'], ['\\\\', '\\"'], $raw);
            return '"' . $escaped . '"';
        }
        return $raw;
    }
}

if (!function_exists('app_env_file_upsert')) {
    function app_env_file_upsert(array $updates, string $filePath = ''): array
    {
        $targetPath = $filePath !== '' ? $filePath : (app_env_root_dir() . '/.app_env');
        $clean = [];
        foreach ($updates as $k => $v) {
            $key = strtoupper(trim((string)$k));
            if ($key === '' || !preg_match('/^[A-Z0-9_]+$/', $key)) {
                continue;
            }
            $clean[$key] = (string)$v;
        }
        if (empty($clean)) {
            return ['ok' => false, 'error' => 'empty_updates', 'path' => $targetPath];
        }

        $existingLines = [];
        if (is_file($targetPath)) {
            if (!is_readable($targetPath) || !is_writable($targetPath)) {
                return ['ok' => false, 'error' => 'file_not_accessible', 'path' => $targetPath];
            }
            $loaded = @file($targetPath, FILE_IGNORE_NEW_LINES);
            if (is_array($loaded)) {
                $existingLines = $loaded;
            }
        } else {
            $dir = dirname($targetPath);
            if (!is_dir($dir) || !is_writable($dir)) {
                return ['ok' => false, 'error' => 'directory_not_writable', 'path' => $targetPath];
            }
        }

        $used = [];
        $nextLines = [];
        foreach ($existingLines as $line) {
            $lineRaw = (string)$line;
            $trimmed = trim($lineRaw);
            if ($trimmed === '' || strpos($trimmed, '#') === 0 || strpos($trimmed, '=') === false) {
                $nextLines[] = $lineRaw;
                continue;
            }
            $eqPos = strpos($lineRaw, '=');
            $name = strtoupper(trim(substr($lineRaw, 0, (int)$eqPos)));
            if ($name !== '' && isset($clean[$name])) {
                $nextLines[] = $name . '=' . app_env_encode_scalar($clean[$name]);
                $used[$name] = true;
            } else {
                $nextLines[] = $lineRaw;
            }
        }

        if (empty($nextLines)) {
            $nextLines[] = '# Managed by application runtime';
        }
        foreach ($clean as $name => $value) {
            if (!isset($used[$name])) {
                $nextLines[] = $name . '=' . app_env_encode_scalar($value);
            }
        }

        $content = implode("\n", $nextLines) . "\n";
        $written = @file_put_contents($targetPath, $content, LOCK_EX);
        if ($written === false) {
            return ['ok' => false, 'error' => 'write_failed', 'path' => $targetPath];
        }
        @chmod($targetPath, 0600);

        foreach ($clean as $name => $value) {
            @putenv($name . '=' . $value);
            $_ENV[$name] = $value;
        }

        return ['ok' => true, 'error' => '', 'path' => $targetPath];
    }
}

if (!function_exists('app_version')) {
    function app_version(?mysqli $conn = null): string
    {
        static $cached = null;
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $version = trim((string)app_env('APP_VERSION', ''));
        if ($version !== '') {
            $cached = $version;
            return $cached;
        }

        if ($conn instanceof mysqli && function_exists('app_setting_get')) {
            try {
                $version = trim((string)app_setting_get($conn, 'update_current_version', ''));
            } catch (Throwable $e) {
                $version = '';
            }
            if ($version !== '') {
                $cached = $version;
                return $cached;
            }
        }

        $cached = '2026.03.29';
        return $cached;
    }
}
