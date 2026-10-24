<?php
declare(strict_types=1);

namespace SmartSystem\Tests\Integration;

use SmartSystem\Tests\Support\DatabaseTestCase;

final class LicenseLifecycleTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['conn'] = $this->conn;
        app_start_session();
        $_SESSION['role'] = 'admin';
        $_SESSION['user_id'] = 1;
        $_SESSION['username'] = 'mahmoud_haidar';
        $_SESSION['email'] = 'info@areagles.com';
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['conn']);
        unset(
            $_SESSION['role'],
            $_SESSION['user_id'],
            $_SESSION['username'],
            $_SESSION['email']
        );

        parent::tearDown();
    }

    public function testLicenseLifecycleCoversTrialSubscriptionAndLifetimeRules(): void
    {
        app_ensure_license_schema($this->conn);
        app_initialize_license_data($this->conn);
        $this->assertLicenseRowExists();
        $ownerLabUnlock = app_license_owner_lab_unlock();

        $trialEndsAt = date('Y-m-d H:i:s', time() + 3 * 86400);
        $saved = app_license_save_manual($this->conn, [
            'plan_type' => 'trial',
            'license_status' => 'active',
            'trial_ends_at' => $trialEndsAt,
            'owner_name' => 'Test Trial Owner',
            'license_key' => 'TRIAL-001',
        ]);
        $this->assertTrue((bool)($saved['ok'] ?? false));

        $status = app_license_status($this->conn, false);
        $this->assertTrue((bool)$status['allowed']);
        $this->assertSame('ok', (string)$status['reason']);
        $this->assertSame('trial', (string)$status['plan']);
        $this->assertSame('active', (string)$status['status']);
        $this->assertSame('Test Trial Owner', (string)$status['owner_name']);
        $this->assertGreaterThanOrEqual(0, (int)$status['days_left']);

        $saved = app_license_save_manual($this->conn, [
            'plan_type' => 'trial',
            'license_status' => 'active',
            'trial_ends_at' => date('Y-m-d H:i:s', time() - 3600),
            'license_key' => 'TRIAL-EXPIRED',
        ]);
        $this->assertTrue((bool)($saved['ok'] ?? false));

        $status = app_license_status($this->conn, false);
        $this->assertSame($ownerLabUnlock, (bool)$status['allowed']);
        $this->assertSame($ownerLabUnlock ? 'owner_lab_unlock' : 'trial_expired', (string)$status['reason']);
        $this->assertSame('trial', (string)$status['plan']);

        $saved = app_license_save_manual($this->conn, [
            'plan_type' => 'subscription',
            'license_status' => 'active',
            'subscription_ends_at' => date('Y-m-d H:i:s', time() - 86400),
            'grace_days' => 3,
            'owner_name' => 'Subscribed Customer',
            'license_key' => 'SUB-001',
        ]);
        $this->assertTrue((bool)($saved['ok'] ?? false));

        $status = app_license_status($this->conn, false);
        $this->assertTrue((bool)$status['allowed']);
        $this->assertSame('ok', (string)$status['reason']);
        $this->assertSame('subscription', (string)$status['plan']);
        $this->assertSame(3, (int)$status['grace_days']);
        $this->assertSame('Subscribed Customer', (string)$status['owner_name']);

        $saved = app_license_save_manual($this->conn, [
            'plan_type' => 'subscription',
            'license_status' => 'active',
            'subscription_ends_at' => date('Y-m-d H:i:s', time() - (5 * 86400)),
            'grace_days' => 2,
            'license_key' => 'SUB-EXPIRED',
        ]);
        $this->assertTrue((bool)($saved['ok'] ?? false));

        $status = app_license_status($this->conn, false);
        $this->assertSame($ownerLabUnlock, (bool)$status['allowed']);
        $this->assertSame($ownerLabUnlock ? 'owner_lab_unlock' : 'subscription_expired', (string)$status['reason']);
        $this->assertSame('subscription', (string)$status['plan']);

        $saved = app_license_save_manual($this->conn, [
            'plan_type' => 'lifetime',
            'license_status' => 'active',
            'owner_name' => 'Lifetime Owner',
            'license_key' => 'LIFE-001',
        ]);
        $this->assertTrue((bool)($saved['ok'] ?? false));

        $status = app_license_status($this->conn, false);
        $this->assertTrue((bool)$status['allowed']);
        $this->assertSame($ownerLabUnlock ? 'owner_lab_unlock' : 'ok', (string)$status['reason']);
        $this->assertSame('lifetime', (string)$status['plan']);
        $this->assertNull($status['days_left']);

        $saved = app_license_save_manual($this->conn, [
            'plan_type' => 'lifetime',
            'license_status' => 'suspended',
            'license_key' => 'LIFE-SUSPENDED',
        ]);
        $this->assertTrue((bool)($saved['ok'] ?? false));

        $status = app_license_status($this->conn, false);
        $this->assertSame($ownerLabUnlock, (bool)$status['allowed']);
        $this->assertSame($ownerLabUnlock ? 'owner_lab_unlock' : 'suspended', (string)$status['reason']);
        $this->assertSame('lifetime', (string)$status['plan']);
        $this->assertSame('suspended', (string)$status['status']);
    }

    private function assertLicenseRowExists(): void
    {
        $result = $this->conn->query("SELECT COUNT(*) AS total FROM app_license_state WHERE id = 1");
        $row = $result ? $result->fetch_assoc() : null;

        $this->assertSame(1, (int)($row['total'] ?? 0));
    }
}
