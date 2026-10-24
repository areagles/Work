<?php
declare(strict_types=1);

namespace SmartSystem\Tests\Integration;

use SmartSystem\Tests\Support\DatabaseTestCase;

final class DatabaseConnectionTest extends DatabaseTestCase
{
    public function testCanQueryConfiguredTestingDatabase(): void
    {
        $result = $this->conn->query('SELECT 1 AS ok');
        $row = $result ? $result->fetch_assoc() : null;

        $this->assertIsArray($row);
        $this->assertSame('1', (string)($row['ok'] ?? '0'));
    }
}
