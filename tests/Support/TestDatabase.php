<?php
declare(strict_types=1);

namespace SmartSystem\Tests\Support;

use mysqli;
use mysqli_sql_exception;
use RuntimeException;

final class TestDatabase
{
    public static function connect(): mysqli
    {
        $cfg = TestConfig::databaseConfig();
        if ($cfg['name'] === '' || $cfg['user'] === '') {
            throw new RuntimeException('Testing database is not configured.');
        }

        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

        try {
            $conn = $cfg['socket'] !== ''
                ? new mysqli($cfg['host'], $cfg['user'], $cfg['pass'], $cfg['name'], $cfg['port'], $cfg['socket'])
                : new mysqli($cfg['host'], $cfg['user'], $cfg['pass'], $cfg['name'], $cfg['port']);
        } catch (mysqli_sql_exception $e) {
            throw new RuntimeException('Failed connecting to testing database: ' . $e->getMessage(), 0, $e);
        }

        $conn->set_charset('utf8mb4');
        return $conn;
    }
}
