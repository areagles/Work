<?php
declare(strict_types=1);

namespace SmartSystem\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SmartSystem\Tests\Support\TestConfig;

final class EnvironmentBootstrapTest extends TestCase
{
    public function testVersionHelperExists(): void
    {
        $this->assertTrue(function_exists('app_version'));
        $this->assertSame('2026.03.29', app_version());
    }

    public function testTestingEnvironmentDefaultsArePresent(): void
    {
        $this->assertSame('testing', (string)getenv('APP_ENV'));
        $this->assertSame('1', (string)getenv('APP_TESTING'));
    }

    public function testDatabaseConfigCanBeDetected(): void
    {
        $this->assertIsBool(TestConfig::hasDatabaseConfig());
    }
}
