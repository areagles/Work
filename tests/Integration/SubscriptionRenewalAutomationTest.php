<?php
declare(strict_types=1);

namespace SmartSystem\Tests\Integration;

use SmartSystem\Tests\Support\DatabaseTestCase;

final class SubscriptionRenewalAutomationTest extends DatabaseTestCase
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

    public function testSubscriptionRegistrySaveRenewActivationAndExtensionRules(): void
    {
        app_initialize_license_management($this->conn);

        $created = app_license_registry_save($this->conn, [
            'client_name' => 'Subscription Customer',
            'client_email' => 'billing@example.test',
            'plan_type' => 'subscription',
            'status' => 'active',
            'subscription_ends_at' => '',
            'grace_days' => 5,
            'max_installations' => 2,
            'max_users' => 25,
        ]);
        $this->assertTrue((bool)($created['ok'] ?? false));
        $licenseId = (int)($created['id'] ?? 0);
        $this->assertGreaterThan(0, $licenseId);

        $savedRow = app_license_registry_get($this->conn, $licenseId);
        $this->assertSame('subscription', (string)($savedRow['plan_type'] ?? ''));
        $this->assertSame('active', (string)($savedRow['status'] ?? ''));
        $this->assertSame(5, (int)($savedRow['grace_days'] ?? 0));
        $this->assertSame(2, (int)($savedRow['max_installations'] ?? 0));
        $this->assertSame(25, (int)($savedRow['max_users'] ?? 0));
        $this->assertNotSame('', trim((string)($savedRow['subscription_ends_at'] ?? '')));

        $effective = app_license_registry_effective_state($savedRow);
        $this->assertSame('active', (string)($effective['status'] ?? ''));
        $this->assertSame('subscription', (string)($effective['plan'] ?? ''));

        $expiredDate = date('Y-m-d H:i:s', time() - (10 * 86400));
        $updated = app_license_registry_save($this->conn, [
            'id' => $licenseId,
            'license_key' => (string)($savedRow['license_key'] ?? ''),
            'api_token' => (string)($savedRow['api_token'] ?? ''),
            'client_name' => 'Subscription Customer',
            'client_email' => 'billing@example.test',
            'plan_type' => 'subscription',
            'status' => 'expired',
            'subscription_ends_at' => $expiredDate,
            'grace_days' => 2,
            'max_installations' => 2,
            'max_users' => 25,
        ]);
        $this->assertTrue((bool)($updated['ok'] ?? false));

        $beforeActivation = app_license_registry_get($this->conn, $licenseId);
        $beforeState = app_license_registry_effective_state($beforeActivation);
        $this->assertSame('expired', (string)($beforeState['status'] ?? ''));

        $reactivated = app_license_registry_set_status($this->conn, $licenseId, 'active');
        $this->assertTrue((bool)($reactivated['ok'] ?? false));
        $this->assertTrue((bool)($reactivated['auto_extended'] ?? false));
        $this->assertSame('subscription', (string)($reactivated['auto_extended_target'] ?? ''));
        $this->assertNotSame('', trim((string)($reactivated['auto_extended_date'] ?? '')));

        $afterActivation = app_license_registry_get($this->conn, $licenseId);
        $afterState = app_license_registry_effective_state($afterActivation);
        $this->assertSame('active', (string)($afterActivation['status'] ?? ''));
        $this->assertSame('subscription', (string)($afterActivation['plan_type'] ?? ''));
        $this->assertSame('active', (string)($afterState['status'] ?? ''));
        $this->assertSame(
            (string)($reactivated['auto_extended_date'] ?? ''),
            (string)($afterActivation['subscription_ends_at'] ?? '')
        );

        $trial = app_license_registry_save($this->conn, [
            'client_name' => 'Trial To Subscription',
            'plan_type' => 'trial',
            'status' => 'active',
            'trial_ends_at' => date('Y-m-d H:i:s', time() + 86400),
        ]);
        $this->assertTrue((bool)($trial['ok'] ?? false));
        $trialId = (int)($trial['id'] ?? 0);
        $this->assertGreaterThan(0, $trialId);

        $extended = app_license_registry_extend_days($this->conn, $trialId, 30, 'subscription');
        $this->assertTrue((bool)($extended['ok'] ?? false));
        $this->assertSame('subscription', (string)($extended['target'] ?? ''));
        $this->assertNotSame('', trim((string)($extended['new_date'] ?? '')));

        $extendedRow = app_license_registry_get($this->conn, $trialId);
        $extendedState = app_license_registry_effective_state($extendedRow);
        $this->assertSame('subscription', (string)($extendedRow['plan_type'] ?? ''));
        $this->assertSame('active', (string)($extendedRow['status'] ?? ''));
        $this->assertSame((string)($extended['new_date'] ?? ''), (string)($extendedRow['subscription_ends_at'] ?? ''));
        $this->assertSame('active', (string)($extendedState['status'] ?? ''));
        $this->assertSame('subscription', (string)($extendedState['plan'] ?? ''));
    }
}
