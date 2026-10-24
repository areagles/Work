<?php
declare(strict_types=1);

namespace SmartSystem\Tests\Integration;

use SmartSystem\Tests\Support\DatabaseTestCase;

final class SubscriptionPaymentReconciliationTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        require_once __DIR__ . '/../../saas.php';

        $GLOBALS['conn'] = $this->conn;
        app_start_session();
        $_SESSION['role'] = 'admin';
        $_SESSION['user_id'] = 1;
        $_SESSION['username'] = 'owner_admin';
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['conn']);
        unset($_SESSION['role'], $_SESSION['user_id'], $_SESSION['username']);

        parent::tearDown();
    }

    public function testSubscriptionInvoicePaymentAndReopenAreReconciled(): void
    {
        app_saas_ensure_control_plane_schema($this->conn);
        $baselineSummary = saas_finance_summary($this->conn);

        $tenantId = $this->insertTenant();
        $subscriptionId = $this->insertSubscription($tenantId);

        $subscriptionRow = $this->conn->query("SELECT * FROM saas_subscriptions WHERE id = {$subscriptionId} LIMIT 1")->fetch_assoc();
        $this->assertIsArray($subscriptionRow);

        $generated = saas_generate_subscription_invoice($this->conn, $subscriptionRow, 'test-suite');
        $this->assertTrue((bool)($generated['ok'] ?? false));
        $this->assertFalse((bool)($generated['already_exists'] ?? true));
        $invoiceId = (int)($generated['invoice_id'] ?? 0);
        $this->assertGreaterThan(0, $invoiceId);

        $paidAt = '2026-03-29 23:55:00';
        $paymentRef = 'TXN-778899';
        $paymentMethod = 'bank_transfer';
        $paymentNotes = 'Collected against March renewal';

        $marked = saas_mark_subscription_invoice_paid(
            $this->conn,
            $invoiceId,
            $tenantId,
            $paidAt,
            $paymentRef,
            $paymentMethod,
            $paymentNotes
        );
        $this->assertTrue($marked);

        $invoiceRow = $this->conn->query("SELECT * FROM saas_subscription_invoices WHERE id = {$invoiceId} LIMIT 1")->fetch_assoc();
        $this->assertSame('paid', (string)($invoiceRow['status'] ?? ''));
        $this->assertSame($paidAt, (string)($invoiceRow['paid_at'] ?? ''));
        $this->assertSame($paymentRef, (string)($invoiceRow['payment_ref'] ?? ''));

        $paymentRow = $this->conn->query("
            SELECT *
            FROM saas_subscription_invoice_payments
            WHERE invoice_id = {$invoiceId}
            ORDER BY id DESC
            LIMIT 1
        ")->fetch_assoc();
        $this->assertIsArray($paymentRow);
        $this->assertSame('posted', (string)($paymentRow['status'] ?? ''));
        $this->assertSame($paymentMethod, (string)($paymentRow['payment_method'] ?? ''));
        $this->assertSame($paymentRef, (string)($paymentRow['payment_ref'] ?? ''));
        $this->assertSame($paymentNotes, (string)($paymentRow['notes'] ?? ''));
        $this->assertSame('EGP', (string)($paymentRow['currency_code'] ?? ''));
        $this->assertSame('2500.00', number_format((float)($paymentRow['amount'] ?? 0), 2, '.', ''));

        $summary = saas_finance_summary($this->conn);
        $this->assertSame(1, (int)($summary['paid_count'] ?? 0) - (int)($baselineSummary['paid_count'] ?? 0));
        $this->assertSame(2500.00, round((float)($summary['paid_amount'] ?? 0) - (float)($baselineSummary['paid_amount'] ?? 0), 2));
        $this->assertSame(2500.00, round((float)($summary['payments_posted'] ?? 0) - (float)($baselineSummary['payments_posted'] ?? 0), 2));
        $this->assertSame(0.0, round((float)($summary['payments_reversed'] ?? 0) - (float)($baselineSummary['payments_reversed'] ?? 0), 2));

        $reopened = saas_reopen_subscription_invoice($this->conn, $invoiceId, $tenantId);
        $this->assertTrue($reopened);

        $reopenedInvoice = $this->conn->query("SELECT * FROM saas_subscription_invoices WHERE id = {$invoiceId} LIMIT 1")->fetch_assoc();
        $this->assertSame('issued', (string)($reopenedInvoice['status'] ?? ''));
        $this->assertSame('', (string)($reopenedInvoice['payment_ref'] ?? ''));
        $this->assertNull($reopenedInvoice['paid_at']);

        $reversedPayment = $this->conn->query("
            SELECT *
            FROM saas_subscription_invoice_payments
            WHERE invoice_id = {$invoiceId}
            ORDER BY id DESC
            LIMIT 1
        ")->fetch_assoc();
        $this->assertSame('reversed', (string)($reversedPayment['status'] ?? ''));

        $reopenedSummary = saas_finance_summary($this->conn);
        $this->assertSame(0, (int)($reopenedSummary['paid_count'] ?? 0) - (int)($baselineSummary['paid_count'] ?? 0));
        $this->assertSame(0.0, round((float)($reopenedSummary['paid_amount'] ?? 0) - (float)($baselineSummary['paid_amount'] ?? 0), 2));
        $this->assertSame(0.0, round((float)($reopenedSummary['payments_posted'] ?? 0) - (float)($baselineSummary['payments_posted'] ?? 0), 2));
        $this->assertSame(2500.00, round((float)($reopenedSummary['payments_reversed'] ?? 0) - (float)($baselineSummary['payments_reversed'] ?? 0), 2));
        $this->assertSame(1, (int)($reopenedSummary['issued_count'] ?? 0) - (int)($baselineSummary['issued_count'] ?? 0));
    }

    private function insertTenant(): int
    {
        $suffix = date('YmdHis') . '_' . substr(bin2hex(random_bytes(3)), 0, 6);
        $slug = 'tenant-payment-' . strtolower($suffix);
        $tenantName = 'Tenant Payment ' . $suffix;
        $billingEmail = $slug . '@test.local';
        $appUrl = 'https://' . $slug . '.test.local';
        $dbName = 'tenant_payment_' . strtolower(substr($suffix, 0, 16));
        $dbUser = 'tenant_user_' . strtolower(substr($suffix, 0, 16));

        $stmt = $this->conn->prepare("
            INSERT INTO saas_tenants
                (tenant_slug, tenant_name, legal_name, status, plan_code, billing_email, app_url, db_name, db_user)
            VALUES
                (?, ?, ?, 'active', 'pro', ?, ?, ?, ?)
        ");
        $legalName = $tenantName . ' LLC';
        $stmt->bind_param('sssssss', $slug, $tenantName, $legalName, $billingEmail, $appUrl, $dbName, $dbUser);
        $stmt->execute();
        $tenantId = (int)$stmt->insert_id;
        $stmt->close();

        return $tenantId;
    }

    private function insertSubscription(int $tenantId): int
    {
        $startsAt = '2026-03-01 00:00:00';
        $renewsAt = '2026-04-01 00:00:00';
        $stmt = $this->conn->prepare("
            INSERT INTO saas_subscriptions
                (tenant_id, billing_cycle, status, plan_code, amount, currency_code, starts_at, cycles_count, trial_days, grace_days, renews_at, ends_at, external_ref, notes)
            VALUES
                (?, 'monthly', 'active', 'pro', 2500.00, 'EGP', ?, 1, 14, 7, ?, ?, 'SUB-2026-03', 'Automated reconciliation test')
        ");
        $stmt->bind_param('isss', $tenantId, $startsAt, $renewsAt, $renewsAt);
        $stmt->execute();
        $subscriptionId = (int)$stmt->insert_id;
        $stmt->close();

        $this->conn->query("UPDATE saas_tenants SET current_subscription_id = {$subscriptionId} WHERE id = {$tenantId} LIMIT 1");

        return $subscriptionId;
    }
}
