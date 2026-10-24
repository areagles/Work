<?php
declare(strict_types=1);

namespace SmartSystem\Tests\Support;

use mysqli;
use PHPUnit\Framework\TestCase;

abstract class DatabaseTestCase extends TestCase
{
    protected ?mysqli $conn = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (!TestConfig::hasDatabaseConfig()) {
            $this->markTestSkipped('Testing database is not configured. Use .app_env.testing or TEST_DB_* env vars.');
        }

        $this->conn = TestDatabase::connect();
        $this->conn->begin_transaction();
    }

    protected function tearDown(): void
    {
        if ($this->conn instanceof mysqli) {
            try {
                $this->conn->rollback();
            } catch (\Throwable $e) {
            }
            $this->conn->close();
        }

        $this->conn = null;
        parent::tearDown();
    }
}
