<?php
declare(strict_types=1);

namespace SmartSystem\Tests\Support;

final class TestConfig
{
    public static function env(string $key, string $default = ''): string
    {
        $value = getenv($key);
        if ($value !== false && $value !== '') {
            return (string)$value;
        }

        $file = dirname(__DIR__, 2) . '/.app_env.testing';
        if (is_file($file) && is_readable($file)) {
            $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if (is_array($lines)) {
                foreach ($lines as $line) {
                    $line = trim((string)$line);
                    if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                        continue;
                    }
                    [$name, $raw] = explode('=', $line, 2);
                    if (trim($name) !== $key) {
                        continue;
                    }
                    return trim((string)$raw, " \t\n\r\0\x0B\"'");
                }
            }
        }

        return $default;
    }

    public static function databaseConfig(): array
    {
        return [
            'host' => self::env('TEST_DB_HOST', '127.0.0.1'),
            'port' => (int)self::env('TEST_DB_PORT', '3306'),
            'socket' => self::env('TEST_DB_SOCKET', ''),
            'name' => self::env('TEST_DB_NAME', ''),
            'user' => self::env('TEST_DB_USER', ''),
            'pass' => self::env('TEST_DB_PASS', ''),
        ];
    }

    public static function hasDatabaseConfig(): bool
    {
        $cfg = self::databaseConfig();
        return $cfg['name'] !== '' && $cfg['user'] !== '';
    }
}
