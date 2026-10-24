<?php

if (!function_exists('saas_subscription_recalculate')) {
    function saas_subscription_recalculate(array $subscription): array
    {
        $status = strtolower(trim((string)($subscription['status'] ?? 'trial')));
        if (!in_array($status, ['trial', 'active', 'past_due', 'suspended', 'cancelled'], true)) {
            $status = 'trial';
        }

        $billingCycle = saas_normalize_subscription_cycle((string)($subscription['billing_cycle'] ?? 'monthly'));
        $cyclesCount = max(1, (int)($subscription['cycles_count'] ?? 1));
        $trialDays = max(1, (int)($subscription['trial_days'] ?? 14));
        $graceDays = max(0, (int)($subscription['grace_days'] ?? 7));
        $startsAt = saas_dt_db((string)($subscription['starts_at'] ?? ''));
        if ($startsAt === null) {
            $startsAt = date('Y-m-d H:i:s');
        }

        $recalculated = [
            'billing_cycle' => $billingCycle,
            'status' => $status,
            'starts_at' => $startsAt,
            'cycles_count' => $cyclesCount,
            'trial_days' => $trialDays,
            'grace_days' => $graceDays,
            'renews_at' => null,
            'ends_at' => null,
        ];

        if ($status === 'trial') {
            $recalculated['billing_cycle'] = 'manual';
            $recalculated['ends_at'] = saas_add_interval($startsAt, 'manual', $trialDays);
            return $recalculated;
        }

        $recalculated['renews_at'] = saas_add_interval($startsAt, $billingCycle, $cyclesCount);
        $recalculated['ends_at'] = $recalculated['renews_at'];

        if (in_array($status, ['active', 'past_due'], true) && $recalculated['ends_at'] !== null) {
            $recalculated['status'] = (strtotime($recalculated['ends_at']) !== false && strtotime($recalculated['ends_at']) < time())
                ? 'past_due'
                : 'active';
        }

        return $recalculated;
    }
}

if (!function_exists('saas_refresh_current_subscription')) {
    function saas_refresh_current_subscription(mysqli $controlConn, int $tenantId): void
    {
        if ($tenantId <= 0) {
            return;
        }
        $nextSubscriptionId = null;
        $stmtNext = $controlConn->prepare("
            SELECT id
            FROM saas_subscriptions
            WHERE tenant_id = ?
            ORDER BY
                CASE
                    WHEN status = 'active' THEN 1
                    WHEN status = 'trial' THEN 2
                    WHEN status = 'past_due' THEN 3
                    WHEN status = 'suspended' THEN 4
                    ELSE 5
                END,
                id DESC
            LIMIT 1
        ");
        $stmtNext->bind_param('i', $tenantId);
        $stmtNext->execute();
        $nextRow = $stmtNext->get_result()->fetch_assoc();
        $stmtNext->close();
        if ($nextRow) {
            $nextSubscriptionId = (int)($nextRow['id'] ?? 0);
        }

        if ($nextSubscriptionId > 0) {
            $stmtUpdate = $controlConn->prepare("UPDATE saas_tenants SET current_subscription_id = ? WHERE id = ? LIMIT 1");
            $stmtUpdate->bind_param('ii', $nextSubscriptionId, $tenantId);
        } else {
            $stmtUpdate = $controlConn->prepare("UPDATE saas_tenants SET current_subscription_id = NULL WHERE id = ? LIMIT 1");
            $stmtUpdate->bind_param('i', $tenantId);
        }
        $stmtUpdate->execute();
        $stmtUpdate->close();
    }
}

if (!function_exists('saas_sync_tenant_subscription_snapshot')) {
    function saas_sync_tenant_subscription_snapshot(mysqli $controlConn, int $tenantId): void
    {
        if ($tenantId <= 0) {
            return;
        }

        $stmt = $controlConn->prepare("
            SELECT t.current_subscription_id, s.status, s.plan_code, s.ends_at
            FROM saas_tenants t
            LEFT JOIN saas_subscriptions s ON s.id = t.current_subscription_id
            WHERE t.id = ?
            LIMIT 1
        ");
        $stmt->bind_param('i', $tenantId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $subscribedUntil = null;
        $trialEndsAt = null;
        $planCode = null;
        if ($row && (int)($row['current_subscription_id'] ?? 0) > 0) {
            $planCode = trim((string)($row['plan_code'] ?? ''));
            $endsAt = saas_dt_db((string)($row['ends_at'] ?? ''));
            $status = strtolower(trim((string)($row['status'] ?? '')));
            if ($status === 'trial') {
                $trialEndsAt = $endsAt;
                $subscribedUntil = $endsAt;
            } elseif ($status !== 'cancelled') {
                $subscribedUntil = $endsAt;
            }
        }

        if ($planCode !== null && $planCode !== '') {
            $stmtUpdate = $controlConn->prepare("
                UPDATE saas_tenants
                SET plan_code = ?, subscribed_until = ?, trial_ends_at = ?
                WHERE id = ?
                LIMIT 1
            ");
            $stmtUpdate->bind_param('sssi', $planCode, $subscribedUntil, $trialEndsAt, $tenantId);
        } else {
            $stmtUpdate = $controlConn->prepare("
                UPDATE saas_tenants
                SET subscribed_until = ?, trial_ends_at = ?
                WHERE id = ?
                LIMIT 1
            ");
            $stmtUpdate->bind_param('ssi', $subscribedUntil, $trialEndsAt, $tenantId);
        }
        $stmtUpdate->execute();
        $stmtUpdate->close();
    }
}

if (!function_exists('saas_recalculate_tenant_subscriptions')) {
    function saas_recalculate_tenant_subscriptions(mysqli $controlConn, int $tenantId): int
    {
        if ($tenantId <= 0) {
            return 0;
        }

        $count = 0;
        $stmtSelect = $controlConn->prepare("SELECT * FROM saas_subscriptions WHERE tenant_id = ? ORDER BY id ASC");
        $stmtSelect->bind_param('i', $tenantId);
        $stmtSelect->execute();
        $result = $stmtSelect->get_result();
        $stmtUpdate = $controlConn->prepare("
            UPDATE saas_subscriptions
            SET billing_cycle = ?, status = ?, starts_at = ?, cycles_count = ?, trial_days = ?, grace_days = ?, renews_at = ?, ends_at = ?
            WHERE id = ? AND tenant_id = ?
            LIMIT 1
        ");

        while ($row = $result->fetch_assoc()) {
            $recalculated = saas_subscription_recalculate($row);
            $subscriptionId = (int)($row['id'] ?? 0);
            $stmtUpdate->bind_param(
                'sssiiissii',
                $recalculated['billing_cycle'],
                $recalculated['status'],
                $recalculated['starts_at'],
                $recalculated['cycles_count'],
                $recalculated['trial_days'],
                $recalculated['grace_days'],
                $recalculated['renews_at'],
                $recalculated['ends_at'],
                $subscriptionId,
                $tenantId
            );
            $stmtUpdate->execute();
            $count++;
        }

        $stmtUpdate->close();
        $stmtSelect->close();
        saas_refresh_current_subscription($controlConn, $tenantId);
        saas_sync_tenant_subscription_snapshot($controlConn, $tenantId);
        return $count;
    }
}

if (!function_exists('saas_subscription_invoice_number')) {
    function saas_subscription_invoice_number(int $invoiceId): string
    {
        return 'SINV-' . date('Ymd') . '-' . str_pad((string)max(1, $invoiceId), 5, '0', STR_PAD_LEFT);
    }
}

if (!function_exists('saas_generate_subscription_invoice')) {
    function saas_generate_subscription_invoice(mysqli $controlConn, array $subscription, string $createdBy = 'System'): array
    {
        $subscriptionId = (int)($subscription['id'] ?? 0);
        $tenantId = (int)($subscription['tenant_id'] ?? 0);
        if ($subscriptionId <= 0 || $tenantId <= 0) {
            return ['ok' => false, 'reason' => 'invalid_subscription', 'invoice_id' => 0, 'already_exists' => false];
        }

        $recalculated = saas_subscription_recalculate($subscription);
        $status = strtolower(trim((string)($recalculated['status'] ?? 'trial')));
        if ($status === 'trial') {
            return ['ok' => false, 'reason' => 'trial_subscription', 'invoice_id' => 0, 'already_exists' => false];
        }

        $periodStart = saas_dt_db((string)($recalculated['starts_at'] ?? ''));
        $periodEnd = saas_dt_db((string)($recalculated['ends_at'] ?? ''));
        if ($periodStart === null || $periodEnd === null) {
            return ['ok' => false, 'reason' => 'missing_period', 'invoice_id' => 0, 'already_exists' => false];
        }

        $stmtExisting = $controlConn->prepare("
            SELECT id
            FROM saas_subscription_invoices
            WHERE subscription_id = ? AND period_start = ? AND period_end = ?
            LIMIT 1
        ");
        $stmtExisting->bind_param('iss', $subscriptionId, $periodStart, $periodEnd);
        $stmtExisting->execute();
        $existing = $stmtExisting->get_result()->fetch_assoc();
        $stmtExisting->close();
        if ($existing) {
            return ['ok' => true, 'reason' => '', 'invoice_id' => (int)$existing['id'], 'already_exists' => true];
        }

        $invoiceDate = date('Y-m-d H:i:s');
        $amount = round((float)($subscription['amount'] ?? 0), 2);
        $currencyCode = strtoupper(trim((string)($subscription['currency_code'] ?? 'EGP')));
        if ($currencyCode === '') {
            $currencyCode = 'EGP';
        }
        $notes = 'فاتورة اشتراك SaaS للدورة من ' . $periodStart . ' إلى ' . $periodEnd;

        $stmtInsert = $controlConn->prepare("
            INSERT INTO saas_subscription_invoices
                (tenant_id, subscription_id, invoice_number, status, amount, currency_code, invoice_date, due_date, period_start, period_end, notes)
            VALUES
                (?, ?, '', 'issued', ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmtInsert->bind_param('iidssssss', $tenantId, $subscriptionId, $amount, $currencyCode, $invoiceDate, $periodEnd, $periodStart, $periodEnd, $notes);
        $stmtInsert->execute();
        $invoiceId = (int)$stmtInsert->insert_id;
        $stmtInsert->close();

        if ($invoiceId > 0) {
            $invoiceNumber = saas_subscription_invoice_number($invoiceId);
            $stmtNumber = $controlConn->prepare("UPDATE saas_subscription_invoices SET invoice_number = ? WHERE id = ? LIMIT 1");
            $stmtNumber->bind_param('si', $invoiceNumber, $invoiceId);
            $stmtNumber->execute();
            $stmtNumber->close();

            $stmtInvoice = $controlConn->prepare("SELECT * FROM saas_subscription_invoices WHERE id = ? LIMIT 1");
            $stmtInvoice->bind_param('i', $invoiceId);
            $stmtInvoice->execute();
            $invoiceRow = $stmtInvoice->get_result()->fetch_assoc();
            $stmtInvoice->close();
            if (is_array($invoiceRow)) {
                $issuedInvoice = saas_issue_subscription_invoice_access(
                    $controlConn,
                    $invoiceRow,
                    isset($GLOBALS['conn']) && $GLOBALS['conn'] instanceof mysqli ? $GLOBALS['conn'] : null
                );
                if (!empty($issuedInvoice['access_token']) && function_exists('saas_find_subscription_invoice_by_token')) {
                    $fullInvoice = saas_find_subscription_invoice_by_token($controlConn, (string)$issuedInvoice['access_token']);
                    if (is_array($fullInvoice)) {
                        saas_send_billing_notifications($controlConn, $fullInvoice, 'invoice_issued');
                        saas_dispatch_outbound_webhook($controlConn, 'subscription.invoice_issued', [
                            'invoice' => $fullInvoice,
                            'subscription' => saas_fetch_subscription_snapshot($controlConn, (int)($fullInvoice['subscription_id'] ?? 0)),
                        ], (int)($fullInvoice['tenant_id'] ?? 0), 'إصدار فاتورة اشتراك');
                    }
                }
            }
        }

        return ['ok' => $invoiceId > 0, 'reason' => '', 'invoice_id' => $invoiceId, 'already_exists' => false, 'created_by' => $createdBy];
    }
}

if (!function_exists('saas_mark_subscription_invoice_paid')) {
    function saas_mark_subscription_invoice_paid(mysqli $controlConn, int $invoiceId, int $tenantId, ?string $paidAt, string $paymentRef = '', string $paymentMethod = 'manual', string $paymentNotes = ''): bool
    {
        if ($invoiceId <= 0 || $tenantId <= 0) {
            return false;
        }
        $paidAt = saas_dt_db((string)$paidAt) ?: date('Y-m-d H:i:s');
        $paymentRef = trim($paymentRef);
        $paymentMethod = saas_normalize_payment_method($paymentMethod);
        $paymentNotes = trim($paymentNotes);

        $stmtInvoice = $controlConn->prepare("
            SELECT subscription_id, amount, currency_code
            FROM saas_subscription_invoices
            WHERE id = ? AND tenant_id = ? AND status = 'issued'
            LIMIT 1
        ");
        $stmtInvoice->bind_param('ii', $invoiceId, $tenantId);
        $stmtInvoice->execute();
        $invoiceRow = $stmtInvoice->get_result()->fetch_assoc();
        $stmtInvoice->close();
        if (!$invoiceRow) {
            return false;
        }

        $stmt = $controlConn->prepare("
            UPDATE saas_subscription_invoices
            SET status = 'paid', paid_at = ?, payment_ref = ?, gateway_status = 'paid'
            WHERE id = ? AND tenant_id = ? AND status <> 'cancelled'
            LIMIT 1
        ");
        $stmt->bind_param('ssii', $paidAt, $paymentRef, $invoiceId, $tenantId);
        $stmt->execute();
        $affected = $stmt->affected_rows > 0;
        $stmt->close();
        if (!$affected) {
            return false;
        }

        $amount = round((float)($invoiceRow['amount'] ?? 0), 2);
        $currencyCode = strtoupper(trim((string)($invoiceRow['currency_code'] ?? 'EGP')));
        $subscriptionId = (int)($invoiceRow['subscription_id'] ?? 0);
        $notes = $paymentNotes !== '' ? $paymentNotes : 'Subscription invoice payment';
        $stmtPayment = $controlConn->prepare("
            INSERT INTO saas_subscription_invoice_payments
                (tenant_id, invoice_id, subscription_id, amount, currency_code, payment_method, payment_ref, paid_at, status, notes)
            VALUES
                (?, ?, ?, ?, ?, ?, ?, ?, 'posted', ?)
        ");
        $stmtPayment->bind_param('iiidsssss', $tenantId, $invoiceId, $subscriptionId, $amount, $currencyCode, $paymentMethod, $paymentRef, $paidAt, $notes);
        $stmtPayment->execute();
        $paymentId = (int)$stmtPayment->insert_id;
        $stmtPayment->close();

        $invoiceSnapshot = saas_fetch_invoice_snapshot($controlConn, $invoiceId);
        saas_dispatch_outbound_webhook($controlConn, 'subscription.invoice_paid', [
            'invoice' => $invoiceSnapshot,
            'payment' => [
                'id' => $paymentId,
                'invoice_id' => $invoiceId,
                'subscription_id' => $subscriptionId,
                'tenant_id' => $tenantId,
                'amount' => $amount,
                'currency_code' => $currencyCode,
                'payment_method' => $paymentMethod,
                'payment_ref' => $paymentRef,
                'payment_notes' => $paymentNotes,
                'paid_at' => $paidAt,
                'status' => 'posted',
            ],
            'subscription' => saas_fetch_subscription_snapshot($controlConn, $subscriptionId),
        ], $tenantId, 'تأكيد سداد فاتورة اشتراك');

        return $affected;
    }
}

if (!function_exists('saas_fetch_subscription_snapshot')) {
    function saas_fetch_subscription_snapshot(mysqli $controlConn, int $subscriptionId): ?array
    {
        if ($subscriptionId <= 0) {
            return null;
        }
        $stmt = $controlConn->prepare("SELECT * FROM saas_subscriptions WHERE id = ? LIMIT 1");
        $stmt->bind_param('i', $subscriptionId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        return is_array($row) ? $row : null;
    }
}

if (!function_exists('saas_fetch_invoice_snapshot')) {
    function saas_fetch_invoice_snapshot(mysqli $controlConn, int $invoiceId): ?array
    {
        if ($invoiceId <= 0) {
            return null;
        }
        $stmt = $controlConn->prepare("
            SELECT i.*, t.tenant_name, t.tenant_slug, t.billing_email
            FROM saas_subscription_invoices i
            INNER JOIN saas_tenants t ON t.id = i.tenant_id
            WHERE i.id = ?
            LIMIT 1
        ");
        $stmt->bind_param('i', $invoiceId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        if (!is_array($row)) {
            return null;
        }
        return function_exists('saas_issue_subscription_invoice_access')
            ? saas_issue_subscription_invoice_access($controlConn, $row, isset($GLOBALS['conn']) && $GLOBALS['conn'] instanceof mysqli ? $GLOBALS['conn'] : null)
            : $row;
    }
}

if (!function_exists('saas_subscription_invoice_access_token')) {
    function saas_subscription_invoice_access_token(int $invoiceId, string $existingToken = ''): string
    {
        $existingToken = trim($existingToken);
        if ($existingToken !== '') {
            return $existingToken;
        }
        $length = 48;
        try {
            return substr(bin2hex(random_bytes((int)ceil($length / 2))), 0, $length);
        } catch (Throwable $e) {
            return 'sinv_' . $invoiceId . '_' . substr(sha1((string)$invoiceId . '|' . microtime(true)), 0, 32);
        }
    }
}

if (!function_exists('saas_tenant_billing_portal_token')) {
    function saas_tenant_billing_portal_token(int $tenantId, string $existingToken = ''): string
    {
        $existingToken = trim($existingToken);
        if ($existingToken !== '') {
            return $existingToken;
        }
        $length = 56;
        try {
            return substr(bin2hex(random_bytes((int)ceil($length / 2))), 0, $length);
        } catch (Throwable $e) {
            return 'tenant_' . $tenantId . '_' . substr(sha1((string)$tenantId . '|' . microtime(true)), 0, 40);
        }
    }
}

if (!function_exists('saas_tenant_billing_portal_url')) {
    function saas_tenant_billing_portal_url(array $tenantRow): string
    {
        $token = trim((string)($tenantRow['billing_portal_token'] ?? ''));
        if ($token === '') {
            return '';
        }
        $baseUrl = app_saas_gateway_base_url();
        if ($baseUrl === '') {
            return '';
        }
        return rtrim($baseUrl, '/') . '/saas_billing_portal.php?portal=' . rawurlencode($token);
    }
}

if (!function_exists('saas_subscription_invoice_public_url')) {
    function saas_subscription_invoice_public_url(array $invoiceRow): string
    {
        $token = trim((string)($invoiceRow['access_token'] ?? ''));
        if ($token === '') {
            return '';
        }
        $baseUrl = app_saas_gateway_base_url();
        if ($baseUrl === '') {
            return '';
        }
        return rtrim($baseUrl, '/') . '/saas_billing_portal.php?token=' . rawurlencode($token);
    }
}

if (!function_exists('saas_issue_subscription_invoice_access')) {
    function saas_issue_subscription_invoice_access(mysqli $controlConn, array $invoiceRow, ?mysqli $settingsConn = null): array
    {
        $invoiceId = (int)($invoiceRow['id'] ?? 0);
        if ($invoiceId <= 0) {
            return $invoiceRow;
        }

        $gatewaySettings = saas_payment_gateway_settings($settingsConn ?: $controlConn);
        $token = saas_subscription_invoice_access_token($invoiceId, (string)($invoiceRow['access_token'] ?? ''));
        $provider = strtolower(trim((string)($invoiceRow['gateway_provider'] ?? '')));
        if ($provider === '') {
            $provider = strtolower(trim((string)($gatewaySettings['provider'] ?? 'manual')));
        }
        if ($provider === '') {
            $provider = 'manual';
        }

        $status = strtolower(trim((string)($invoiceRow['gateway_status'] ?? '')));
        if ($status === '') {
            $status = strtolower(trim((string)($invoiceRow['status'] ?? 'issued'))) === 'paid'
                ? 'paid'
                : (($gatewaySettings['enabled'] ?? false) ? 'ready' : 'manual');
        }

        $invoiceRow['access_token'] = $token;
        $invoiceRow['gateway_provider'] = $provider;
        $invoiceRow['gateway_status'] = $status;
        $invoiceRow['gateway_public_url'] = saas_subscription_invoice_public_url($invoiceRow);

        $tenantId = (int)($invoiceRow['tenant_id'] ?? 0);
        $tenantRow = ['id' => $tenantId];
        if ($tenantId > 0) {
            $stmtTenant = $controlConn->prepare("SELECT id, tenant_slug, tenant_name, billing_email FROM saas_tenants WHERE id = ? LIMIT 1");
            $stmtTenant->bind_param('i', $tenantId);
            $stmtTenant->execute();
            $tenantResult = $stmtTenant->get_result()->fetch_assoc();
            $stmtTenant->close();
            if (is_array($tenantResult)) {
                $tenantRow = $tenantResult;
            }
        }

        $adapterCheckout = saas_gateway_adapter_build_checkout($invoiceRow, $tenantRow, $gatewaySettings);
        $invoiceRow['gateway_provider'] = (string)($adapterCheckout['provider'] ?? $invoiceRow['gateway_provider']);
        $invoiceRow['gateway_status'] = (string)($adapterCheckout['status'] ?? $invoiceRow['gateway_status']);
        if (trim((string)($adapterCheckout['url'] ?? '')) !== '') {
            $invoiceRow['gateway_public_url'] = (string)$adapterCheckout['url'];
        }
        $invoiceRow['gateway_adapter'] = $adapterCheckout;

        $stmtUpdate = $controlConn->prepare("
            UPDATE saas_subscription_invoices
            SET access_token = ?, gateway_provider = ?, gateway_status = ?, gateway_public_url = ?
            WHERE id = ?
            LIMIT 1
        ");
        $stmtUpdate->bind_param('ssssi', $invoiceRow['access_token'], $invoiceRow['gateway_provider'], $invoiceRow['gateway_status'], $invoiceRow['gateway_public_url'], $invoiceId);
        $stmtUpdate->execute();
        $stmtUpdate->close();

        return $invoiceRow;
    }
}

if (!function_exists('saas_issue_tenant_billing_portal_access')) {
    function saas_issue_tenant_billing_portal_access(mysqli $controlConn, array $tenantRow): array
    {
        $tenantId = (int)($tenantRow['id'] ?? 0);
        if ($tenantId <= 0) {
            return $tenantRow;
        }

        $token = saas_tenant_billing_portal_token($tenantId, (string)($tenantRow['billing_portal_token'] ?? ''));
        $tenantRow['billing_portal_token'] = $token;
        $tenantRow['billing_portal_url'] = saas_tenant_billing_portal_url($tenantRow);

        $stmt = $controlConn->prepare("UPDATE saas_tenants SET billing_portal_token = ? WHERE id = ? LIMIT 1");
        $stmt->bind_param('si', $tenantRow['billing_portal_token'], $tenantId);
        $stmt->execute();
        $stmt->close();

        return $tenantRow;
    }
}

if (!function_exists('saas_find_subscription_invoice_by_token')) {
    function saas_find_subscription_invoice_by_token(mysqli $controlConn, string $token): ?array
    {
        $token = trim($token);
        if ($token === '') {
            return null;
        }
        $stmt = $controlConn->prepare("
            SELECT i.*, t.tenant_slug, t.tenant_name, t.billing_email, t.app_url, s.plan_code, s.billing_cycle
            FROM saas_subscription_invoices i
            INNER JOIN saas_tenants t ON t.id = i.tenant_id
            LEFT JOIN saas_subscriptions s ON s.id = i.subscription_id
            WHERE i.access_token = ?
            LIMIT 1
        ");
        $stmt->bind_param('s', $token);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return is_array($row) ? $row : null;
    }
}

if (!function_exists('saas_find_tenant_by_portal_token')) {
    function saas_find_tenant_by_portal_token(mysqli $controlConn, string $token): ?array
    {
        $token = trim($token);
        if ($token === '') {
            return null;
        }
        $stmt = $controlConn->prepare("SELECT * FROM saas_tenants WHERE billing_portal_token = ? LIMIT 1");
        $stmt->bind_param('s', $token);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!is_array($row)) {
            return null;
        }
        return saas_issue_tenant_billing_portal_access($controlConn, $row);
    }
}

if (!function_exists('saas_normalize_payment_method')) {
    function saas_normalize_payment_method(string $paymentMethod): string
    {
        $paymentMethod = strtolower(trim($paymentMethod));
        if ($paymentMethod === '') {
            return 'manual';
        }
        $catalog = saas_payment_method_catalog();
        if (isset($catalog[$paymentMethod])) {
            return $paymentMethod;
        }
        $paymentMethod = preg_replace('/[^a-z0-9_\-]+/', '_', $paymentMethod);
        $paymentMethod = trim((string)$paymentMethod, '_-');
        return $paymentMethod !== '' ? substr($paymentMethod, 0, 60) : 'manual';
    }
}

if (!function_exists('saas_payment_method_label')) {
    function saas_payment_method_label(string $paymentMethod, bool $isEnglish = false): string
    {
        $paymentMethod = saas_normalize_payment_method($paymentMethod);
        $catalog = saas_payment_method_catalog();
        if (isset($catalog[$paymentMethod])) {
            return (string)($isEnglish ? ($catalog[$paymentMethod]['label_en'] ?? $paymentMethod) : ($catalog[$paymentMethod]['label_ar'] ?? $paymentMethod));
        }
        return ucwords(str_replace(['_', '-'], ' ', $paymentMethod));
    }
}

if (!function_exists('saas_reopen_subscription_invoice')) {
    function saas_reopen_subscription_invoice(mysqli $controlConn, int $invoiceId, int $tenantId): bool
    {
        if ($invoiceId <= 0 || $tenantId <= 0) {
            return false;
        }
        $stmt = $controlConn->prepare("
            UPDATE saas_subscription_invoices
            SET status = 'issued', paid_at = NULL, payment_ref = '', gateway_status = 'ready'
            WHERE id = ? AND tenant_id = ? AND status = 'paid'
            LIMIT 1
        ");
        $stmt->bind_param('ii', $invoiceId, $tenantId);
        $stmt->execute();
        $affected = $stmt->affected_rows > 0;
        $stmt->close();
        if ($affected) {
            $stmtReverse = $controlConn->prepare("
                UPDATE saas_subscription_invoice_payments
                SET status = 'reversed'
                WHERE invoice_id = ? AND tenant_id = ? AND status = 'posted'
            ");
            $stmtReverse->bind_param('ii', $invoiceId, $tenantId);
            $stmtReverse->execute();
            $stmtReverse->close();
        }
        return $affected;
    }
}

if (!function_exists('saas_paymob_extract_payload')) {
    function saas_paymob_extract_payload(): array
    {
        $payload = [];
        if (!empty($_GET) && is_array($_GET)) {
            $payload = array_merge($payload, $_GET);
        }
        if (!empty($_POST) && is_array($_POST)) {
            $payload = array_merge($payload, $_POST);
        }
        $raw = isset($GLOBALS['saas_paymob_raw_body']) ? (string)$GLOBALS['saas_paymob_raw_body'] : (string)file_get_contents('php://input');
        if ($raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $payload = array_merge($payload, $decoded);
            }
        }
        return $payload;
    }
}

if (!function_exists('saas_paymob_payload_value')) {
    function saas_paymob_payload_value(array $payload, array $keys)
    {
        foreach ($keys as $key) {
            $cursor = $payload;
            $segments = explode('.', (string)$key);
            $found = true;
            foreach ($segments as $segment) {
                if (is_array($cursor) && array_key_exists($segment, $cursor)) {
                    $cursor = $cursor[$segment];
                    continue;
                }
                $found = false;
                break;
            }
            if ($found) {
                return $cursor;
            }
        }
        return null;
    }
}

if (!function_exists('saas_paymob_callback_candidates')) {
    function saas_paymob_callback_candidates(array $payload): array
    {
        $keys = ['token', 'merchant_order_id', 'merchant_reference', 'order_id', 'order.id', 'obj.order.id', 'obj.order.merchant_order_id', 'obj.id', 'source_data.sub_type', 'invoice_number', 'reference'];
        $values = [];
        foreach ($keys as $key) {
            $value = saas_paymob_payload_value($payload, [$key]);
            if (is_scalar($value)) {
                $value = trim((string)$value);
                if ($value !== '') {
                    $values[] = $value;
                }
            }
        }
        return array_values(array_unique($values));
    }
}

if (!function_exists('saas_paymob_callback_success')) {
    function saas_paymob_callback_success(array $payload): bool
    {
        $candidates = [
            saas_paymob_payload_value($payload, ['success', 'obj.success']),
            saas_paymob_payload_value($payload, ['is_auth', 'obj.is_auth']),
            saas_paymob_payload_value($payload, ['is_capture', 'obj.is_capture']),
        ];
        foreach ($candidates as $candidate) {
            if (is_bool($candidate)) {
                return $candidate;
            }
            if (is_numeric($candidate)) {
                return (int)$candidate === 1;
            }
            $text = strtolower(trim((string)$candidate));
            if (in_array($text, ['true', 'paid', 'success', 'successful', '1'], true)) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('saas_paymob_signature_candidates')) {
    function saas_paymob_signature_candidates(array $payload): array
    {
        $candidates = [];
        foreach (['hmac', 'signature', 'obj.hmac'] as $key) {
            $value = saas_paymob_payload_value($payload, [$key]);
            if (is_scalar($value)) {
                $value = trim((string)$value);
                if ($value !== '') {
                    $candidates[] = $value;
                }
            }
        }
        foreach (['HTTP_X_PAYMOB_SIGNATURE', 'HTTP_X_SIGNATURE', 'HTTP_X_HMAC'] as $serverKey) {
            $value = trim((string)($_SERVER[$serverKey] ?? ''));
            if ($value !== '') {
                $candidates[] = $value;
            }
        }
        return array_values(array_unique($candidates));
    }
}

if (!function_exists('saas_paymob_payload_flat_pairs')) {
    function saas_paymob_payload_flat_pairs(array $payload, string $prefix = ''): array
    {
        $pairs = [];
        foreach ($payload as $key => $value) {
            $fullKey = $prefix === '' ? (string)$key : ($prefix . '.' . (string)$key);
            if (is_array($value)) {
                $pairs = array_merge($pairs, saas_paymob_payload_flat_pairs($value, $fullKey));
            } elseif (is_scalar($value) || $value === null) {
                $pairs[$fullKey] = (string)$value;
            }
        }
        ksort($pairs);
        return $pairs;
    }
}

if (!function_exists('saas_paymob_verify_signature')) {
    function saas_paymob_verify_signature(array $gatewaySettings, array $payload, string $rawBody = ''): array
    {
        $secret = trim((string)($gatewaySettings['hmac_secret'] ?? $gatewaySettings['webhook_secret'] ?? ''));
        if ($secret === '') {
            return ['required' => false, 'verified' => false, 'reason' => 'secret_not_configured'];
        }
        $provided = saas_paymob_signature_candidates($payload);
        if (empty($provided)) {
            return ['required' => true, 'verified' => false, 'reason' => 'signature_missing'];
        }
        $checks = [];
        if ($rawBody !== '') {
            $checks[] = hash_hmac('sha512', $rawBody, $secret);
            $checks[] = hash_hmac('sha256', $rawBody, $secret);
        }
        $flatPairs = saas_paymob_payload_flat_pairs($payload);
        if (!empty($flatPairs)) {
            $query = http_build_query($flatPairs, '', '&', PHP_QUERY_RFC3986);
            $checks[] = hash_hmac('sha512', $query, $secret);
            $checks[] = hash_hmac('sha256', $query, $secret);
            $joined = implode('', array_values($flatPairs));
            if ($joined !== '') {
                $checks[] = hash_hmac('sha512', $joined, $secret);
                $checks[] = hash_hmac('sha256', $joined, $secret);
            }
        }
        $checks = array_values(array_unique(array_filter($checks)));
        foreach ($provided as $signature) {
            foreach ($checks as $candidate) {
                if (hash_equals(strtolower($candidate), strtolower(trim((string)$signature)))) {
                    return ['required' => true, 'verified' => true, 'reason' => 'matched'];
                }
            }
        }
        return ['required' => true, 'verified' => false, 'reason' => 'signature_mismatch', 'provided' => $provided];
    }
}

if (!function_exists('saas_find_subscription_invoice_by_reference')) {
    function saas_find_subscription_invoice_by_reference(mysqli $controlConn, array $references): ?array
    {
        foreach ($references as $reference) {
            $reference = trim((string)$reference);
            if ($reference === '') {
                continue;
            }
            $stmt = $controlConn->prepare("
                SELECT i.*, t.tenant_slug, t.tenant_name, t.billing_email, t.app_url, s.plan_code, s.billing_cycle
                FROM saas_subscription_invoices i
                INNER JOIN saas_tenants t ON t.id = i.tenant_id
                LEFT JOIN saas_subscriptions s ON s.id = i.subscription_id
                WHERE i.access_token = ? OR i.invoice_number = ? OR CAST(i.id AS CHAR(32)) = ?
                LIMIT 1
            ");
            $stmt->bind_param('sss', $reference, $reference, $reference);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (is_array($row)) {
                return $row;
            }
        }
        return null;
    }
}
