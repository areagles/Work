<?php
ob_start();
require 'auth.php';
require 'config.php';
app_start_session();
app_handle_lang_switch($conn);

if (!app_is_super_user() || app_license_edition() !== 'owner' || app_is_saas_gateway()) {
    http_response_code(403);
    die('ليس لديك صلاحية الوصول إلى مركز SaaS.');
}

$isProductionOwnerRuntime = function_exists('app_saas_is_production_owner_runtime') && app_saas_is_production_owner_runtime();

if (!function_exists('saas_flash_set')) {
    function saas_flash_set(string $type, string $message): void
    {
        $_SESSION['saas_center_flash'] = [
            'type' => $type,
            'message' => $message,
        ];
    }
}

if (!function_exists('saas_flash_get')) {
    function saas_flash_get(): array
    {
        $flash = $_SESSION['saas_center_flash'] ?? [];
        unset($_SESSION['saas_center_flash']);
        return is_array($flash) ? $flash : [];
    }
}

if (!function_exists('saas_slugify')) {
    function saas_slugify(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/', '-', $value);
        $value = trim((string)$value, '-');
        return substr($value, 0, 120);
    }
}

if (!function_exists('saas_operation_matches_filters')) {
    function saas_operation_matches_filters(array $row, string $search, string $actionCode, int $tenantId, string $actorName): bool
    {
        if ($actionCode !== '' && strtolower(trim((string)($row['action_code'] ?? ''))) !== $actionCode) {
            return false;
        }
        if ($tenantId > 0 && (int)($row['tenant_id'] ?? 0) !== $tenantId) {
            return false;
        }
        if ($actorName !== '') {
            $rowActor = strtolower(trim((string)($row['actor_name'] ?? '')));
            if ($rowActor !== $actorName) {
                return false;
            }
        }
        if ($search !== '') {
            $haystack = strtolower(implode(' ', [
                (string)($row['action_code'] ?? ''),
                (string)($row['action_label'] ?? ''),
                (string)($row['tenant_slug'] ?? ''),
                (string)($row['tenant_name'] ?? ''),
                (string)($row['actor_name'] ?? ''),
                (string)($row['context_json'] ?? ''),
            ]));
            if (strpos($haystack, $search) === false) {
                return false;
            }
        }
        return true;
    }
}

if (!function_exists('saas_operation_context_preview')) {
    function saas_operation_context_preview(string $contextJson): string
    {
        $contextJson = trim($contextJson);
        if ($contextJson === '') {
            return '';
        }
        $decoded = json_decode($contextJson, true);
        if (!is_array($decoded)) {
            return $contextJson;
        }
        $parts = [];
        foreach ($decoded as $key => $value) {
            if (is_array($value)) {
                $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            } elseif (is_bool($value)) {
                $value = $value ? 'yes' : 'no';
            }
            $parts[] = $key . ': ' . (string)$value;
            if (count($parts) >= 4) {
                break;
            }
        }
        return implode(' | ', $parts);
    }
}

if (!function_exists('saas_clone_template_presets')) {
    function saas_clone_template_presets(): array
    {
        return [
            'blank' => [
                'label' => 'نسخة فارغة',
                'provision_now' => true,
                'seed_presets' => [],
            ],
            'foundation' => [
                'label' => 'نسخة تأسيسية',
                'provision_now' => true,
                'seed_presets' => ['clients', 'suppliers', 'warehouses_inventory', 'products', 'employees'],
            ],
            'catalog' => [
                'label' => 'نسخة كتالوج',
                'provision_now' => true,
                'seed_presets' => ['products', 'warehouses_inventory'],
            ],
        ];
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

if (!function_exists('saas_refresh_primary_domain')) {
    function saas_refresh_primary_domain(mysqli $controlConn, int $tenantId): void
    {
        if ($tenantId <= 0) {
            return;
        }

        $stmtReset = $controlConn->prepare("UPDATE saas_tenant_domains SET is_primary = 0 WHERE tenant_id = ?");
        $stmtReset->bind_param('i', $tenantId);
        $stmtReset->execute();
        $stmtReset->close();

        $nextDomainId = 0;
        $stmtNext = $controlConn->prepare("
            SELECT id
            FROM saas_tenant_domains
            WHERE tenant_id = ?
            ORDER BY id ASC
            LIMIT 1
        ");
        $stmtNext->bind_param('i', $tenantId);
        $stmtNext->execute();
        $nextRow = $stmtNext->get_result()->fetch_assoc();
        $stmtNext->close();
        if ($nextRow) {
            $nextDomainId = (int)($nextRow['id'] ?? 0);
        }

        if ($nextDomainId > 0) {
            $stmtPrimary = $controlConn->prepare("UPDATE saas_tenant_domains SET is_primary = 1 WHERE id = ? LIMIT 1");
            $stmtPrimary->bind_param('i', $nextDomainId);
            $stmtPrimary->execute();
            $stmtPrimary->close();
        }
    }
}

if (!function_exists('saas_dt_db')) {
    function saas_dt_db(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        $value = str_replace('T', ' ', $value);
        if (preg_match('/^\d{4}-\d{2}-\d{2}\s\d{2}:\d{2}$/', $value)) {
            $value .= ':00';
        }
        return strtotime($value) === false ? null : $value;
    }
}

if (!function_exists('saas_cycle_interval_spec')) {
    function saas_cycle_interval_spec(string $billingCycle, int $cyclesCount): string
    {
        $cyclesCount = max(1, $cyclesCount);
        switch ($billingCycle) {
            case 'yearly':
                return 'P' . $cyclesCount . 'Y';
            case 'quarterly':
                return 'P' . ($cyclesCount * 3) . 'M';
            case 'manual':
                return 'P' . $cyclesCount . 'D';
            case 'monthly':
            default:
                return 'P' . $cyclesCount . 'M';
        }
    }
}

if (!function_exists('saas_normalize_subscription_cycle')) {
    function saas_normalize_subscription_cycle(string $billingCycle): string
    {
        $billingCycle = strtolower(trim($billingCycle));
        if (!in_array($billingCycle, ['monthly', 'quarterly', 'yearly', 'manual'], true)) {
            $billingCycle = 'monthly';
        }
        return $billingCycle;
    }
}

if (!function_exists('saas_add_interval')) {
    function saas_add_interval(?string $startAt, string $billingCycle, int $cyclesCount): ?string
    {
        $startAt = trim((string)$startAt);
        if ($startAt === '') {
            return null;
        }
        try {
            $dt = new DateTime($startAt);
            $dt->add(new DateInterval(saas_cycle_interval_spec($billingCycle, $cyclesCount)));
            return $dt->format('Y-m-d H:i:s');
        } catch (Throwable $e) {
            return null;
        }
    }
}

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

if (!function_exists('saas_apply_overdue_policy_for_tenant')) {
    function saas_apply_overdue_policy_for_tenant(mysqli $controlConn, int $tenantId): array
    {
        if ($tenantId <= 0) {
            return ['updated' => 0, 'suspended' => 0, 'past_due' => 0];
        }

        $updated = 0;
        $suspended = 0;
        $pastDue = 0;
        $nowTs = time();

        $stmtSelect = $controlConn->prepare("SELECT * FROM saas_subscriptions WHERE tenant_id = ? AND status <> 'cancelled' ORDER BY id ASC");
        $stmtSelect->bind_param('i', $tenantId);
        $stmtSelect->execute();
        $result = $stmtSelect->get_result();

        $stmtInvoice = $controlConn->prepare("
            SELECT due_date
            FROM saas_subscription_invoices
            WHERE subscription_id = ? AND status = 'issued' AND due_date IS NOT NULL
            ORDER BY due_date ASC, id ASC
            LIMIT 1
        ");
        $stmtUpdate = $controlConn->prepare("UPDATE saas_subscriptions SET status = ? WHERE id = ? AND tenant_id = ? LIMIT 1");

        while ($row = $result->fetch_assoc()) {
            $subscriptionId = (int)($row['id'] ?? 0);
            $recalculated = saas_subscription_recalculate($row);
            $targetStatus = (string)($recalculated['status'] ?? 'trial');

            if (!in_array($targetStatus, ['trial', 'active', 'past_due', 'suspended', 'cancelled'], true)) {
                $targetStatus = 'active';
            }

            if (!in_array($targetStatus, ['trial', 'cancelled', 'suspended'], true)) {
                $stmtInvoice->bind_param('i', $subscriptionId);
                $stmtInvoice->execute();
                $invoiceRow = $stmtInvoice->get_result()->fetch_assoc();
                $dueDate = saas_dt_db((string)($invoiceRow['due_date'] ?? ''));
                if ($dueDate !== null) {
                    $dueTs = strtotime($dueDate);
                    $graceDays = max(0, (int)($row['grace_days'] ?? 7));
                    $graceTs = $dueTs === false ? false : strtotime('+' . $graceDays . ' days', $dueTs);
                    if ($dueTs !== false && $dueTs <= $nowTs) {
                        $targetStatus = 'past_due';
                        $pastDue++;
                        if ($graceTs !== false && $graceTs <= $nowTs) {
                            $targetStatus = 'suspended';
                            $suspended++;
                        }
                    }
                }
            }

            $currentStatus = strtolower(trim((string)($row['status'] ?? 'trial')));
            if ($targetStatus !== $currentStatus) {
                $stmtUpdate->bind_param('sii', $targetStatus, $subscriptionId, $tenantId);
                $stmtUpdate->execute();
                $updated++;
            }
        }

        $stmtUpdate->close();
        $stmtInvoice->close();
        $stmtSelect->close();

        saas_refresh_current_subscription($controlConn, $tenantId);
        saas_sync_tenant_subscription_snapshot($controlConn, $tenantId);

        $stmtTenant = $controlConn->prepare("
            SELECT t.current_subscription_id, s.status
            FROM saas_tenants t
            LEFT JOIN saas_subscriptions s ON s.id = t.current_subscription_id
            WHERE t.id = ?
            LIMIT 1
        ");
        $stmtTenant->bind_param('i', $tenantId);
        $stmtTenant->execute();
        $tenantRow = $stmtTenant->get_result()->fetch_assoc();
        $stmtTenant->close();
        $currentSubscriptionStatus = strtolower(trim((string)($tenantRow['status'] ?? '')));
        if ($currentSubscriptionStatus === 'suspended') {
            $stmtSuspend = $controlConn->prepare("UPDATE saas_tenants SET status = 'suspended' WHERE id = ? AND status <> 'archived' LIMIT 1");
            $stmtSuspend->bind_param('i', $tenantId);
            $stmtSuspend->execute();
            $stmtSuspend->close();
        }

        return ['updated' => $updated, 'suspended' => $suspended, 'past_due' => $pastDue];
    }
}

if (!function_exists('saas_subscription_invoice_number')) {
    function saas_subscription_invoice_number(int $invoiceId): string
    {
        return 'SINV-' . date('Ymd') . '-' . str_pad((string)max(1, $invoiceId), 5, '0', STR_PAD_LEFT);
    }
}

if (!function_exists('saas_mark_subscription_invoice_paid')) {
    function saas_mark_subscription_invoice_paid(mysqli $controlConn, int $invoiceId, int $tenantId, ?string $paidAt, string $paymentRef = ''): bool
    {
        if ($invoiceId <= 0 || $tenantId <= 0) {
            return false;
        }
        $paidAt = saas_dt_db((string)$paidAt) ?: date('Y-m-d H:i:s');
        $paymentRef = trim($paymentRef);
        $stmt = $controlConn->prepare("
            UPDATE saas_subscription_invoices
            SET status = 'paid', paid_at = ?, payment_ref = ?
            WHERE id = ? AND tenant_id = ? AND status <> 'cancelled'
            LIMIT 1
        ");
        $stmt->bind_param('ssii', $paidAt, $paymentRef, $invoiceId, $tenantId);
        $stmt->execute();
        $affected = $stmt->affected_rows > 0;
        $stmt->close();
        return $affected;
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
            SET status = 'issued', paid_at = NULL, payment_ref = ''
            WHERE id = ? AND tenant_id = ? AND status = 'paid'
            LIMIT 1
        ");
        $stmt->bind_param('ii', $invoiceId, $tenantId);
        $stmt->execute();
        $affected = $stmt->affected_rows > 0;
        $stmt->close();
        return $affected;
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
            VALUES (?, ?, '', 'issued', ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmtInsert->bind_param('iidssssss', $tenantId, $subscriptionId, $amount, $currencyCode, $invoiceDate, $periodEnd, $periodStart, $periodEnd, $notes);
        $stmtInsert->execute();
        $invoiceId = (int)$stmtInsert->insert_id;
        $stmtInsert->close();
        if ($invoiceId <= 0) {
            return ['ok' => false, 'reason' => 'insert_failed', 'invoice_id' => 0, 'already_exists' => false];
        }

        $invoiceNumber = saas_subscription_invoice_number($invoiceId);
        $stmtNumber = $controlConn->prepare("UPDATE saas_subscription_invoices SET invoice_number = ? WHERE id = ? LIMIT 1");
        $stmtNumber->bind_param('si', $invoiceNumber, $invoiceId);
        $stmtNumber->execute();
        $stmtNumber->close();

        return ['ok' => true, 'reason' => '', 'invoice_id' => $invoiceId, 'invoice_number' => $invoiceNumber, 'already_exists' => false];
    }
}

$controlDbConfig = app_saas_control_db_config([
    'host' => app_env('DB_HOST', 'localhost'),
    'user' => app_env('DB_USER', ''),
    'pass' => app_env('DB_PASS', ''),
    'name' => app_env('DB_NAME', ''),
    'port' => (int)app_env('DB_PORT', '3306'),
    'socket' => app_env('DB_SOCKET', ''),
]);

$controlConn = app_saas_open_control_connection($controlDbConfig);
app_saas_ensure_control_plane_schema($controlConn);
$controlConn->query("
    UPDATE saas_tenants t
    SET t.current_subscription_id = (
        SELECT s.id
        FROM saas_subscriptions s
        WHERE s.tenant_id = t.id
        ORDER BY
            CASE
                WHEN s.status = 'active' THEN 1
                WHEN s.status = 'trial' THEN 2
                WHEN s.status = 'past_due' THEN 3
                WHEN s.status = 'suspended' THEN 4
                ELSE 5
            END,
            s.id DESC
        LIMIT 1
    )
    WHERE (t.current_subscription_id IS NULL OR t.current_subscription_id = 0)
");

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    app_require_csrf();
    $action = trim((string)($_POST['action'] ?? ''));
    $saasActorName = trim((string)($_SESSION['username'] ?? $_SESSION['full_name'] ?? 'System'));

    try {
        if ($action === 'create_tenant') {
            $slug = saas_slugify((string)($_POST['tenant_slug'] ?? ''));
            $name = trim((string)($_POST['tenant_name'] ?? ''));
            $systemName = trim((string)($_POST['system_name'] ?? ''));
            $legalName = trim((string)($_POST['legal_name'] ?? ''));
            $status = strtolower(trim((string)($_POST['status'] ?? 'provisioning')));
            $planCode = trim((string)($_POST['plan_code'] ?? 'basic'));
            $provisionProfile = trim((string)($_POST['provision_profile'] ?? 'standard'));
            $policyPack = trim((string)($_POST['policy_pack'] ?? 'standard'));
            $billingEmail = trim((string)($_POST['billing_email'] ?? ''));
            $appUrl = rtrim(trim((string)($_POST['app_url'] ?? '')), '/');
            $dbHost = trim((string)($_POST['db_host'] ?? 'localhost'));
            $dbPort = max(1, (int)($_POST['db_port'] ?? 3306));
            $dbName = trim((string)($_POST['db_name'] ?? ''));
            $dbUser = trim((string)($_POST['db_user'] ?? ''));
            $dbPass = (string)($_POST['db_password'] ?? '');
            $dbSocket = trim((string)($_POST['db_socket'] ?? ''));
            $timezone = trim((string)($_POST['timezone'] ?? 'Africa/Cairo'));
            $locale = trim((string)($_POST['locale'] ?? 'ar'));
            $trialEndsAt = saas_dt_db((string)($_POST['trial_ends_at'] ?? ''));
            $subscribedUntil = saas_dt_db((string)($_POST['subscribed_until'] ?? ''));
            $usersLimit = max(0, (int)($_POST['users_limit'] ?? 0));
            $storageLimit = max(0, (int)($_POST['storage_limit_mb'] ?? 0));
            $opsKeepLatest = max(1, (int)($_POST['ops_keep_latest'] ?? 500));
            $opsKeepDays = max(1, (int)($_POST['ops_keep_days'] ?? 30));
            $notes = trim((string)($_POST['notes'] ?? ''));
            $primaryDomain = app_saas_normalize_host((string)($_POST['primary_domain'] ?? ''));
            $gatewayHost = app_saas_gateway_host();
            $systemFolder = app_saas_normalize_system_folder((string)($_POST['system_folder'] ?? $systemName), $slug !== '' ? $slug : 'tenant');

            if ($slug === '' || $name === '' || $systemName === '' || $dbName === '' || $dbUser === '') {
                throw new RuntimeException('بيانات المستأجر الأساسية غير مكتملة.');
            }
            if ($primaryDomain !== '' && $gatewayHost !== '' && $primaryDomain === $gatewayHost) {
                throw new RuntimeException('لا يمكن استخدام دومين بوابة الـ SaaS نفسه كدومين أساسي لمستأجر.');
            }
            if (!in_array($status, ['provisioning', 'active', 'suspended', 'archived'], true)) {
                $status = 'provisioning';
            }
            $stmtFolder = $controlConn->prepare("SELECT id FROM saas_tenants WHERE LOWER(COALESCE(system_folder, '')) = LOWER(?) LIMIT 1");
            $stmtFolder->bind_param('s', $systemFolder);
            $stmtFolder->execute();
            $folderTaken = $stmtFolder->get_result()->fetch_assoc();
            $stmtFolder->close();
            if ($folderTaken) {
                throw new RuntimeException('اسم النظام أو مجلد التشغيل مستخدم بالفعل. اختر اسمًا مختلفًا.');
            }
            if ($appUrl === '' && $systemFolder !== '') {
                $appUrl = rtrim(app_saas_gateway_base_url(), '/') . '/' . rawurlencode($systemFolder);
            }

            $dbPasswordEnc = app_saas_encrypt_secret($dbPass);
            $dbPasswordPlain = $dbPasswordEnc === '' ? $dbPass : '';
            $activatedAt = $status === 'active' ? date('Y-m-d H:i:s') : null;

            $controlConn->begin_transaction();
            $stmt = $controlConn->prepare("
                INSERT INTO saas_tenants
                (
                    tenant_slug, tenant_name, system_name, system_folder, legal_name, status, plan_code, provision_profile, policy_pack, billing_email, app_url,
                    db_host, db_port, db_name, db_user, db_password_plain, db_password_enc, db_socket,
                    timezone, locale, trial_ends_at, subscribed_until, users_limit, storage_limit_mb, ops_keep_latest, ops_keep_days,
                    notes, activated_at
                )
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->bind_param(
                'ssssssssssssisssssssssiiiiss',
                $slug,
                $name,
                $systemName,
                $systemFolder,
                $legalName,
                $status,
                $planCode,
                $provisionProfile,
                $policyPack,
                $billingEmail,
                $appUrl,
                $dbHost,
                $dbPort,
                $dbName,
                $dbUser,
                $dbPasswordPlain,
                $dbPasswordEnc,
                $dbSocket,
                $timezone,
                $locale,
                $trialEndsAt,
                $subscribedUntil,
                $usersLimit,
                $storageLimit,
                $opsKeepLatest,
                $opsKeepDays,
                $notes,
                $activatedAt
            );
            $stmt->execute();
            $tenantId = (int)$stmt->insert_id;
            $stmt->close();

            if ($primaryDomain !== '') {
                $stmtDomain = $controlConn->prepare("
                    INSERT INTO saas_tenant_domains (tenant_id, domain, is_primary, verified_at)
                    VALUES (?, ?, 1, NOW())
                ");
                $stmtDomain->bind_param('is', $tenantId, $primaryDomain);
                $stmtDomain->execute();
                $stmtDomain->close();
            }

            $runtimeSetup = app_saas_ensure_tenant_runtime_folder([
                'id' => $tenantId,
                'tenant_slug' => $slug,
                'tenant_name' => $name,
                'system_folder' => $systemFolder,
                'app_url' => $appUrl,
            ]);
            if ($appUrl === '' && !empty($runtimeSetup['app_url'])) {
                $appUrl = (string)$runtimeSetup['app_url'];
                $stmtAppUrl = $controlConn->prepare("UPDATE saas_tenants SET app_url = ? WHERE id = ? LIMIT 1");
                $stmtAppUrl->bind_param('si', $appUrl, $tenantId);
                $stmtAppUrl->execute();
                $stmtAppUrl->close();
            }

            $controlConn->commit();
            app_saas_log_operation($controlConn, 'tenant.created', 'إنشاء مستأجر', $tenantId, [
                'tenant_slug' => $slug,
                'system_folder' => $systemFolder,
                'plan_code' => $planCode,
                'provision_profile' => $provisionProfile,
                'policy_pack' => $policyPack,
                'status' => $status,
                'app_url' => $appUrl,
            ], $saasActorName);
            saas_flash_set('success', 'تم إنشاء المستأجر بنجاح وتجهيز مجلد النظام: ' . $systemFolder);
            app_safe_redirect('saas_center.php');
        }

        if ($action === 'save_provision_profile') {
            $profileKey = saas_slugify((string)($_POST['profile_key'] ?? ''));
            app_saas_upsert_provision_profile($controlConn, [
                'profile_key' => $profileKey,
                'label' => trim((string)($_POST['label'] ?? '')),
                'plan_code' => trim((string)($_POST['plan_code'] ?? 'basic')),
                'timezone' => trim((string)($_POST['timezone'] ?? 'Africa/Cairo')),
                'locale' => trim((string)($_POST['locale'] ?? 'ar')),
                'users_limit' => (int)($_POST['users_limit'] ?? 0),
                'storage_limit_mb' => (int)($_POST['storage_limit_mb'] ?? 0),
                'sort_order' => (int)($_POST['sort_order'] ?? 0),
                'is_active' => !empty($_POST['is_active']) ? 1 : 0,
            ]);
            app_saas_log_operation($controlConn, 'provision_profile.saved', 'حفظ Provision Profile', 0, [
                'profile_key' => $profileKey,
                'plan_code' => trim((string)($_POST['plan_code'] ?? 'basic')),
            ], $saasActorName);
            saas_flash_set('success', app_tr('تم حفظ بروفايل التهيئة: ', 'Provision profile saved: ') . $profileKey);
            app_safe_redirect('saas_center.php');
        }

        if ($action === 'delete_provision_profile') {
            $profileKey = saas_slugify((string)($_POST['profile_key'] ?? ''));
            app_saas_delete_provision_profile($controlConn, $profileKey);
            app_saas_log_operation($controlConn, 'provision_profile.deleted', 'حذف Provision Profile', 0, [
                'profile_key' => $profileKey,
            ], $saasActorName);
            saas_flash_set('success', app_tr('تم حذف بروفايل التهيئة: ', 'Provision profile deleted: ') . $profileKey);
            app_safe_redirect('saas_center.php');
        }

        if ($action === 'apply_provision_profile') {
            $tenantId = (int)($_POST['tenant_id'] ?? 0);
            $profileKey = saas_slugify((string)($_POST['profile_key'] ?? ''));
            $apply = app_saas_apply_provision_profile_to_tenant($controlConn, $tenantId, $profileKey);
            app_saas_log_operation($controlConn, 'provision_profile.applied', 'تطبيق Provision Profile على مستأجر', $tenantId, $apply, $saasActorName);
            saas_flash_set('success', app_tr('تم تطبيق بروفايل التهيئة على المستأجر: ', 'Provision profile applied to tenant: ') . $profileKey);
            app_safe_redirect('saas_center.php');
        }

        if ($action === 'bulk_reapply_provision_profile') {
            $profileKey = saas_slugify((string)($_POST['profile_key'] ?? ''));
            $result = app_saas_bulk_reapply_provision_profile($controlConn, $profileKey);
            app_saas_log_operation($controlConn, 'provision_profile.bulk_reapplied', 'إعادة تطبيق Provision Profile جماعيًا', 0, $result, $saasActorName);
            saas_flash_set('success', app_tr('تمت إعادة تطبيق بروفايل التهيئة على ', 'Provision profile reapplied to ') . (int)($result['updated'] ?? 0) . app_tr(' مستأجر/مستأجرين.', ' tenant(s).'));
            app_safe_redirect('saas_center.php');
        }

        if ($action === 'save_policy_pack') {
            $packKey = saas_slugify((string)($_POST['pack_key'] ?? ''));
            app_saas_upsert_policy_pack($controlConn, [
                'pack_key' => $packKey,
                'label' => trim((string)($_POST['label'] ?? '')),
                'tenant_status' => trim((string)($_POST['tenant_status'] ?? 'active')),
                'timezone' => trim((string)($_POST['timezone'] ?? 'Africa/Cairo')),
                'locale' => trim((string)($_POST['locale'] ?? 'ar')),
                'trial_days' => (int)($_POST['trial_days'] ?? 14),
                'grace_days' => (int)($_POST['grace_days'] ?? 7),
                'ops_keep_latest' => (int)($_POST['ops_keep_latest'] ?? 500),
                'ops_keep_days' => (int)($_POST['ops_keep_days'] ?? 30),
                'sort_order' => (int)($_POST['sort_order'] ?? 0),
                'is_active' => !empty($_POST['is_active']) ? 1 : 0,
            ]);
            app_saas_log_operation($controlConn, 'policy_pack.saved', 'حفظ Policy Pack', 0, [
                'pack_key' => $packKey,
                'tenant_status' => trim((string)($_POST['tenant_status'] ?? 'active')),
            ], $saasActorName);
            saas_flash_set('success', app_tr('تم حفظ حزمة السياسات: ', 'Policy pack saved: ') . $packKey);
            app_safe_redirect('saas_center.php');
        }

        if ($action === 'delete_policy_pack') {
            $packKey = saas_slugify((string)($_POST['pack_key'] ?? ''));
            app_saas_delete_policy_pack($controlConn, $packKey);
            app_saas_log_operation($controlConn, 'policy_pack.deleted', 'حذف Policy Pack', 0, [
                'pack_key' => $packKey,
            ], $saasActorName);
            saas_flash_set('success', app_tr('تم حذف حزمة السياسات: ', 'Policy pack deleted: ') . $packKey);
            app_safe_redirect('saas_center.php');
        }

        if ($action === 'apply_policy_pack') {
            $tenantId = (int)($_POST['tenant_id'] ?? 0);
            $packKey = saas_slugify((string)($_POST['pack_key'] ?? ''));
            $apply = app_saas_apply_policy_pack_to_tenant($controlConn, $tenantId, $packKey);
            app_saas_log_operation($controlConn, 'policy_pack.applied', 'تطبيق Policy Pack على مستأجر', $tenantId, $apply, $saasActorName);
            saas_flash_set('success', app_tr('تم تطبيق حزمة السياسات على المستأجر: ', 'Policy pack applied to tenant: ') . $packKey);
            app_safe_redirect('saas_center.php');
        }

        if ($action === 'bulk_reapply_policy_pack') {
            $packKey = saas_slugify((string)($_POST['pack_key'] ?? ''));
            $result = app_saas_bulk_reapply_policy_pack($controlConn, $packKey);
            app_saas_log_operation($controlConn, 'policy_pack.bulk_reapplied', 'إعادة تطبيق Policy Pack جماعيًا', 0, $result, $saasActorName);
            saas_flash_set('success', app_tr('تمت إعادة تطبيق حزمة السياسات على ', 'Policy pack reapplied to ') . (int)($result['updated'] ?? 0) . app_tr(' مستأجر/مستأجرين.', ' tenant(s).'));
            app_safe_redirect('saas_center.php');
        }

        if ($action === 'save_policy_exceptions') {
            $tenantId = (int)($_POST['tenant_id'] ?? 0);
            $overrides = app_saas_save_tenant_policy_overrides($controlConn, $tenantId, [
                'tenant_status' => (string)($_POST['exception_tenant_status'] ?? ''),
                'timezone' => (string)($_POST['exception_timezone'] ?? ''),
                'locale' => (string)($_POST['exception_locale'] ?? ''),
                'trial_days' => (string)($_POST['exception_trial_days'] ?? ''),
                'grace_days' => (string)($_POST['exception_grace_days'] ?? ''),
                'ops_keep_latest' => (string)($_POST['exception_ops_keep_latest'] ?? ''),
                'ops_keep_days' => (string)($_POST['exception_ops_keep_days'] ?? ''),
            ]);
            $stmtTenant = $controlConn->prepare("SELECT policy_pack FROM saas_tenants WHERE id = ? LIMIT 1");
            $stmtTenant->bind_param('i', $tenantId);
            $stmtTenant->execute();
            $tenantRow = $stmtTenant->get_result()->fetch_assoc();
            $stmtTenant->close();
            $packKey = trim((string)($tenantRow['policy_pack'] ?? 'standard'));
            if ($packKey !== '') {
                app_saas_apply_policy_pack_to_tenant($controlConn, $tenantId, $packKey);
            }
            app_saas_log_operation($controlConn, 'policy_exception.saved', 'حفظ استثناءات Policy Pack', $tenantId, [
                'policy_pack' => $packKey,
                'overrides' => $overrides,
            ], $saasActorName);
            saas_flash_set('success', empty($overrides)
                ? app_tr('تم مسح استثناءات المستأجر والعودة إلى حزمة السياسات الأساسية.', 'Tenant exceptions were cleared and the base policy pack was restored.')
                : app_tr('تم حفظ استثناءات المستأجر على حزمة السياسات.', 'Tenant exceptions were saved on the policy pack.'));
            app_safe_redirect('saas_center.php');
        }

        if ($action === 'clear_policy_exceptions') {
            $tenantId = (int)($_POST['tenant_id'] ?? 0);
            app_saas_clear_tenant_policy_overrides($controlConn, $tenantId);
            $stmtTenant = $controlConn->prepare("SELECT policy_pack FROM saas_tenants WHERE id = ? LIMIT 1");
            $stmtTenant->bind_param('i', $tenantId);
            $stmtTenant->execute();
            $tenantRow = $stmtTenant->get_result()->fetch_assoc();
            $stmtTenant->close();
            $packKey = trim((string)($tenantRow['policy_pack'] ?? 'standard'));
            if ($packKey !== '') {
                app_saas_apply_policy_pack_to_tenant($controlConn, $tenantId, $packKey);
            }
            app_saas_log_operation($controlConn, 'policy_exception.cleared', 'مسح استثناءات Policy Pack', $tenantId, [
                'policy_pack' => $packKey,
            ], $saasActorName);
            saas_flash_set('success', app_tr('تم مسح استثناءات المستأجر والرجوع إلى إعدادات حزمة السياسات الأصلية.', 'Tenant exceptions were cleared and the original policy pack settings were restored.'));
            app_safe_redirect('saas_center.php');
        }

        if ($action === 'save_policy_exception_preset') {
            $presetKey = saas_slugify((string)($_POST['preset_key'] ?? ''));
            app_saas_upsert_policy_exception_preset($controlConn, [
                'preset_key' => $presetKey,
                'label' => trim((string)($_POST['label'] ?? '')),
                'tenant_status' => (string)($_POST['tenant_status'] ?? ''),
                'timezone' => (string)($_POST['timezone'] ?? ''),
                'locale' => (string)($_POST['locale'] ?? ''),
                'trial_days' => (string)($_POST['trial_days'] ?? ''),
                'grace_days' => (string)($_POST['grace_days'] ?? ''),
                'ops_keep_latest' => (string)($_POST['ops_keep_latest'] ?? ''),
                'ops_keep_days' => (string)($_POST['ops_keep_days'] ?? ''),
                'is_active' => !empty($_POST['is_active']) ? 1 : 0,
                'sort_order' => (int)($_POST['sort_order'] ?? 0),
            ]);
            app_saas_log_operation($controlConn, 'policy_exception_preset.saved', 'حفظ Exception Preset', 0, [
                'preset_key' => $presetKey,
            ], $saasActorName);
            saas_flash_set('success', app_tr('تم حفظ قالب الاستثناء: ', 'Exception preset saved: ') . $presetKey);
            app_safe_redirect('saas_center.php');
        }

        if ($action === 'delete_policy_exception_preset') {
            $presetKey = saas_slugify((string)($_POST['preset_key'] ?? ''));
            app_saas_delete_policy_exception_preset($controlConn, $presetKey);
            app_saas_log_operation($controlConn, 'policy_exception_preset.deleted', 'حذف Exception Preset', 0, [
                'preset_key' => $presetKey,
            ], $saasActorName);
            saas_flash_set('success', app_tr('تم حذف قالب الاستثناء: ', 'Exception preset deleted: ') . $presetKey);
            app_safe_redirect('saas_center.php');
        }

        if ($action === 'apply_policy_exception_preset') {
            $tenantId = (int)($_POST['tenant_id'] ?? 0);
            $presetKey = saas_slugify((string)($_POST['preset_key'] ?? ''));
            $apply = app_saas_apply_policy_exception_preset_to_tenant($controlConn, $tenantId, $presetKey);
            app_saas_log_operation($controlConn, 'policy_exception_preset.applied', 'تطبيق Exception Preset على مستأجر', $tenantId, $apply, $saasActorName);
            saas_flash_set('success', app_tr('تم تطبيق قالب الاستثناء على المستأجر: ', 'Exception preset applied to tenant: ') . $presetKey);
            app_safe_redirect('saas_center.php');
        }

        if ($action === 'bulk_reapply_policy_exception_preset') {
            $presetKey = saas_slugify((string)($_POST['preset_key'] ?? ''));
            $result = app_saas_bulk_reapply_policy_exception_preset($controlConn, $presetKey);
            app_saas_log_operation($controlConn, 'policy_exception_preset.bulk_reapplied', 'إعادة تطبيق Exception Preset جماعيًا', 0, $result, $saasActorName);
            saas_flash_set('success', app_tr('تمت إعادة تطبيق قالب الاستثناء على ', 'Exception preset reapplied to ') . (int)($result['updated'] ?? 0) . app_tr(' مستأجر/مستأجرين.', ' tenant(s).'));
            app_safe_redirect('saas_center.php');
        }

        if ($action === 'add_domain') {
            $tenantId = (int)($_POST['tenant_id'] ?? 0);
            $domain = app_saas_normalize_host((string)($_POST['domain'] ?? ''));
            $isPrimary = !empty($_POST['is_primary']) ? 1 : 0;
            $gatewayHost = app_saas_gateway_host();
            if ($tenantId <= 0 || $domain === '') {
                throw new RuntimeException('بيانات الدومين غير مكتملة.');
            }
            if ($gatewayHost !== '' && $domain === $gatewayHost) {
                throw new RuntimeException('لا يمكن ربط دومين بوابة الـ SaaS نفسه داخل قائمة دومينات المستأجر.');
            }

            $controlConn->begin_transaction();
            if ($isPrimary === 1) {
                $stmtReset = $controlConn->prepare("UPDATE saas_tenant_domains SET is_primary = 0 WHERE tenant_id = ?");
                $stmtReset->bind_param('i', $tenantId);
                $stmtReset->execute();
                $stmtReset->close();
            }
            $stmtDomain = $controlConn->prepare("
                INSERT INTO saas_tenant_domains (tenant_id, domain, is_primary, verified_at)
                VALUES (?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE tenant_id = VALUES(tenant_id), is_primary = VALUES(is_primary), verified_at = VALUES(verified_at)
            ");
            $stmtDomain->bind_param('isi', $tenantId, $domain, $isPrimary);
            $stmtDomain->execute();
            $stmtDomain->close();
            $controlConn->commit();
            app_saas_log_operation($controlConn, 'tenant.domain_added', 'إضافة دومين', $tenantId, [
                'domain' => $domain,
                'is_primary' => $isPrimary,
            ], $saasActorName);
            saas_flash_set('success', 'تم حفظ الدومين.');
            app_safe_redirect('saas_center.php');
        }

        if ($action === 'delete_domain') {
            $tenantId = (int)($_POST['tenant_id'] ?? 0);
            $domainId = (int)($_POST['domain_id'] ?? 0);
            if ($tenantId <= 0 || $domainId <= 0) {
                throw new RuntimeException('الدومين أو المستأجر غير محدد.');
            }

            $stmtDomain = $controlConn->prepare("SELECT id, is_primary FROM saas_tenant_domains WHERE id = ? AND tenant_id = ? LIMIT 1");
            $stmtDomain->bind_param('ii', $domainId, $tenantId);
            $stmtDomain->execute();
            $domainRow = $stmtDomain->get_result()->fetch_assoc();
            $stmtDomain->close();
            if (!$domainRow) {
                throw new RuntimeException('الدومين غير موجود داخل هذا المستأجر.');
            }

            $controlConn->begin_transaction();
            $stmtDelete = $controlConn->prepare("DELETE FROM saas_tenant_domains WHERE id = ? AND tenant_id = ? LIMIT 1");
            $stmtDelete->bind_param('ii', $domainId, $tenantId);
            $stmtDelete->execute();
            $stmtDelete->close();
            if ((int)($domainRow['is_primary'] ?? 0) === 1) {
                saas_refresh_primary_domain($controlConn, $tenantId);
            }
            $controlConn->commit();
            app_saas_log_operation($controlConn, 'tenant.domain_deleted', 'حذف دومين', $tenantId, [
                'domain_id' => $domainId,
                'was_primary' => (int)($domainRow['is_primary'] ?? 0),
            ], $saasActorName);

            saas_flash_set('success', 'تم حذف الدومين.');
            app_safe_redirect('saas_center.php');
        }

        if ($action === 'create_subscription') {
            $tenantId = (int)($_POST['tenant_id'] ?? 0);
            $billingCycle = saas_normalize_subscription_cycle((string)($_POST['billing_cycle'] ?? 'monthly'));
            $status = strtolower(trim((string)($_POST['subscription_status'] ?? 'trial')));
            $planCode = trim((string)($_POST['subscription_plan_code'] ?? 'basic'));
            $amount = (float)($_POST['amount'] ?? 0);
            $currencyCode = strtoupper(trim((string)($_POST['currency_code'] ?? 'EGP')));
            $startsAt = saas_dt_db((string)($_POST['starts_at'] ?? ''));
            $cyclesCount = max(1, (int)($_POST['cycles_count'] ?? 1));
            $trialDays = max(1, (int)($_POST['trial_days'] ?? 14));
            $graceDays = max(0, (int)($_POST['grace_days'] ?? 7));
            $renewsAt = saas_dt_db((string)($_POST['renews_at'] ?? ''));
            $endsAt = saas_dt_db((string)($_POST['ends_at'] ?? ''));
            $externalRef = trim((string)($_POST['external_ref'] ?? ''));
            $notes = trim((string)($_POST['subscription_notes'] ?? ''));
            if ($tenantId <= 0) {
                throw new RuntimeException('المستأجر غير محدد للاشتراك.');
            }
            if (!in_array($status, ['trial', 'active', 'past_due', 'suspended', 'cancelled'], true)) {
                $status = 'trial';
            }
            $recalculated = saas_subscription_recalculate([
                'billing_cycle' => $billingCycle,
                'status' => $status,
                'starts_at' => $startsAt,
                'cycles_count' => $cyclesCount,
                'trial_days' => $trialDays,
                'grace_days' => $graceDays,
            ]);
            if ($startsAt === null) {
                $startsAt = (string)$recalculated['starts_at'];
            }
            if ($status === 'trial') {
                $billingCycle = (string)$recalculated['billing_cycle'];
                if ($endsAt === null) {
                    $endsAt = $recalculated['ends_at'];
                }
                $renewsAt = null;
            } else {
                if ($renewsAt === null) {
                    $renewsAt = $recalculated['renews_at'];
                }
                if ($endsAt === null) {
                    $endsAt = $recalculated['ends_at'];
                }
            }

            $stmt = $controlConn->prepare("
                INSERT INTO saas_subscriptions
                (tenant_id, billing_cycle, status, plan_code, amount, currency_code, starts_at, cycles_count, trial_days, grace_days, renews_at, ends_at, external_ref, notes)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->bind_param('isssdssiiissss', $tenantId, $billingCycle, $status, $planCode, $amount, $currencyCode, $startsAt, $cyclesCount, $trialDays, $graceDays, $renewsAt, $endsAt, $externalRef, $notes);
            $stmt->execute();
            $subscriptionId = (int)$stmt->insert_id;
            $stmt->close();
            if ($subscriptionId > 0) {
                $stmtBind = $controlConn->prepare("UPDATE saas_tenants SET current_subscription_id = ? WHERE id = ? LIMIT 1");
                $stmtBind->bind_param('ii', $subscriptionId, $tenantId);
                $stmtBind->execute();
                $stmtBind->close();
            }
            saas_sync_tenant_subscription_snapshot($controlConn, $tenantId);
            app_saas_log_operation($controlConn, 'subscription.created', 'إنشاء اشتراك', $tenantId, [
                'subscription_id' => $subscriptionId,
                'billing_cycle' => $billingCycle,
                'status' => $status,
                'plan_code' => $planCode,
                'amount' => round($amount, 2),
                'currency_code' => $currencyCode,
            ], $saasActorName);
            saas_flash_set('success', 'تمت إضافة الاشتراك.');
            app_safe_redirect('saas_center.php');
        }

        if ($action === 'recalculate_subscription') {
            $tenantId = (int)($_POST['tenant_id'] ?? 0);
            $subscriptionId = (int)($_POST['subscription_id'] ?? 0);
            if ($tenantId <= 0 || $subscriptionId <= 0) {
                throw new RuntimeException('الاشتراك أو المستأجر غير محدد.');
            }

            $stmtSub = $controlConn->prepare("SELECT * FROM saas_subscriptions WHERE id = ? AND tenant_id = ? LIMIT 1");
            $stmtSub->bind_param('ii', $subscriptionId, $tenantId);
            $stmtSub->execute();
            $subRow = $stmtSub->get_result()->fetch_assoc();
            $stmtSub->close();
            if (!$subRow) {
                throw new RuntimeException('الاشتراك غير موجود داخل هذا المستأجر.');
            }

            $recalculated = saas_subscription_recalculate($subRow);
            $stmtUpdate = $controlConn->prepare("
                UPDATE saas_subscriptions
                SET billing_cycle = ?, status = ?, starts_at = ?, cycles_count = ?, trial_days = ?, grace_days = ?, renews_at = ?, ends_at = ?
                WHERE id = ? AND tenant_id = ?
                LIMIT 1
            ");
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
            $stmtUpdate->close();

            saas_refresh_current_subscription($controlConn, $tenantId);
            saas_sync_tenant_subscription_snapshot($controlConn, $tenantId);
            app_saas_log_operation($controlConn, 'subscription.recalculated', 'إعادة احتساب اشتراك', $tenantId, [
                'subscription_id' => $subscriptionId,
                'status' => (string)$recalculated['status'],
                'renews_at' => (string)($recalculated['renews_at'] ?? ''),
                'ends_at' => (string)($recalculated['ends_at'] ?? ''),
            ], $saasActorName);
            $message = 'تمت إعادة احتساب دورة الاشتراك.';
            if ((string)$recalculated['status'] === 'trial') {
                $message .= ' نهاية التجربة: ' . ((string)($recalculated['ends_at'] ?? '-'));
            } else {
                $message .= ' التجديد التالي: ' . ((string)($recalculated['renews_at'] ?? '-'));
            }
            saas_flash_set('success', $message);
            app_safe_redirect('saas_center.php');
        }

        if ($action === 'apply_overdue_policy_tenant') {
            $tenantId = (int)($_POST['tenant_id'] ?? 0);
            if ($tenantId <= 0) {
                throw new RuntimeException('المستأجر غير محدد.');
            }
            $result = saas_apply_overdue_policy_for_tenant($controlConn, $tenantId);
            app_saas_log_operation($controlConn, 'subscription.overdue_tenant', 'مراجعة تأخير مستأجر', $tenantId, [
                'updated' => (int)($result['updated'] ?? 0),
                'past_due' => (int)($result['past_due'] ?? 0),
                'suspended' => (int)($result['suspended'] ?? 0),
            ], $saasActorName);
            saas_flash_set('success', 'تمت مراجعة التأخير لهذا المستأجر. تحديثات: ' . (int)($result['updated'] ?? 0));
            app_safe_redirect('saas_center.php');
        }

        if ($action === 'apply_overdue_policy_all') {
            $tenantIds = [];
            $tenantRes = $controlConn->query("SELECT id FROM saas_tenants ORDER BY id ASC");
            while ($tenantRow = $tenantRes->fetch_assoc()) {
                $tenantIds[] = (int)($tenantRow['id'] ?? 0);
            }
            $updated = 0;
            $suspended = 0;
            $pastDue = 0;
            foreach ($tenantIds as $tenantId) {
                if ($tenantId > 0) {
                    $result = saas_apply_overdue_policy_for_tenant($controlConn, $tenantId);
                    $updated += (int)($result['updated'] ?? 0);
                    $suspended += (int)($result['suspended'] ?? 0);
                    $pastDue += (int)($result['past_due'] ?? 0);
                }
            }
            app_saas_log_operation($controlConn, 'subscription.overdue_all', 'تطبيق سياسة التأخير', 0, [
                'updated' => $updated,
                'past_due' => $pastDue,
                'suspended' => $suspended,
                'tenant_count' => count($tenantIds),
            ], $saasActorName);
            saas_flash_set('success', 'تم تطبيق سياسة التأخير. تحديثات: ' . $updated . ' | متأخر: ' . $pastDue . ' | موقوف: ' . $suspended);
            app_safe_redirect('saas_center.php');
        }

        if ($action === 'recalculate_tenant_subscriptions') {
            $tenantId = (int)($_POST['tenant_id'] ?? 0);
            if ($tenantId <= 0) {
                throw new RuntimeException('المستأجر غير محدد.');
            }
            $count = saas_recalculate_tenant_subscriptions($controlConn, $tenantId);
            app_saas_log_operation($controlConn, 'subscription.recalculate_tenant_all', 'إعادة احتساب اشتراكات مستأجر', $tenantId, [
                'count' => $count,
            ], $saasActorName);
            saas_flash_set('success', 'تمت إعادة احتساب ' . $count . ' اشتراك/اشتراكات وربطها بالمستأجر.');
            app_safe_redirect('saas_center.php');
        }

        if ($action === 'recalculate_all_subscriptions') {
            $tenantIds = [];
            $tenantRes = $controlConn->query("SELECT id FROM saas_tenants ORDER BY id ASC");
            while ($tenantRow = $tenantRes->fetch_assoc()) {
                $tenantIds[] = (int)($tenantRow['id'] ?? 0);
            }
            $affected = 0;
            foreach ($tenantIds as $tenantId) {
                if ($tenantId > 0) {
                    $affected += saas_recalculate_tenant_subscriptions($controlConn, $tenantId);
                }
            }
            app_saas_log_operation($controlConn, 'subscription.recalculate_all', 'إعادة احتساب كل الاشتراكات', 0, [
                'affected' => $affected,
                'tenant_count' => count($tenantIds),
            ], $saasActorName);
            saas_flash_set('success', 'تمت إعادة احتساب كل اشتراكات SaaS. إجمالي الاشتراكات المعاد ضبطها: ' . $affected);
            app_safe_redirect('saas_center.php');
        }

        if ($action === 'generate_subscription_invoice') {
            $tenantId = (int)($_POST['tenant_id'] ?? 0);
            $subscriptionId = (int)($_POST['subscription_id'] ?? 0);
            if ($tenantId <= 0 || $subscriptionId <= 0) {
                throw new RuntimeException('الاشتراك أو المستأجر غير محدد.');
            }
            $stmtSub = $controlConn->prepare("SELECT * FROM saas_subscriptions WHERE id = ? AND tenant_id = ? LIMIT 1");
            $stmtSub->bind_param('ii', $subscriptionId, $tenantId);
            $stmtSub->execute();
            $subRow = $stmtSub->get_result()->fetch_assoc();
            $stmtSub->close();
            if (!$subRow) {
                throw new RuntimeException('الاشتراك غير موجود داخل هذا المستأجر.');
            }

            $result = saas_generate_subscription_invoice($controlConn, $subRow, (string)($_SESSION['username'] ?? 'System'));
            if (!$result['ok']) {
                if (($result['reason'] ?? '') === 'trial_subscription') {
                    throw new RuntimeException('لا يتم إنشاء فواتير اشتراك للاشتراكات التجريبية.');
                }
                throw new RuntimeException('تعذر إنشاء فاتورة الاشتراك.');
            }

            $message = !empty($result['already_exists'])
                ? 'فاتورة الاشتراك موجودة بالفعل لنفس الدورة.'
                : 'تم إنشاء فاتورة الاشتراك بنجاح.';
            app_saas_log_operation($controlConn, 'subscription.invoice_generated', 'إنشاء فاتورة اشتراك', $tenantId, [
                'subscription_id' => $subscriptionId,
                'invoice_id' => (int)($result['invoice_id'] ?? 0),
                'already_exists' => !empty($result['already_exists']),
            ], $saasActorName);
            saas_flash_set('success', $message);
            app_safe_redirect('saas_center.php');
        }

        if ($action === 'generate_due_subscription_invoices') {
            $now = date('Y-m-d H:i:s');
            $created = 0;
            $existing = 0;
            $res = $controlConn->query("
                SELECT *
                FROM saas_subscriptions
                WHERE status IN ('active', 'past_due')
                  AND COALESCE(ends_at, renews_at) IS NOT NULL
                  AND COALESCE(ends_at, renews_at) <= '" . $controlConn->real_escape_string($now) . "'
                ORDER BY tenant_id ASC, id ASC
            ");
            while ($subRow = $res->fetch_assoc()) {
                $result = saas_generate_subscription_invoice($controlConn, $subRow, (string)($_SESSION['username'] ?? 'System'));
                if (!empty($result['ok']) && empty($result['already_exists'])) {
                    $created++;
                } elseif (!empty($result['already_exists'])) {
                    $existing++;
                }
            }
            app_saas_log_operation($controlConn, 'subscription.invoice_generate_due', 'إنشاء فواتير الاشتراكات المستحقة', 0, [
                'created' => $created,
                'existing' => $existing,
            ], $saasActorName);
            saas_flash_set('success', 'تم إنشاء ' . $created . ' فاتورة اشتراك مستحقة، والموجود مسبقًا: ' . $existing . '.');
            app_safe_redirect('saas_center.php');
        }

        if ($action === 'mark_subscription_invoice_paid') {
            $tenantId = (int)($_POST['tenant_id'] ?? 0);
            $invoiceId = (int)($_POST['invoice_id'] ?? 0);
            $paidAt = (string)($_POST['paid_at'] ?? '');
            $paymentRef = (string)($_POST['payment_ref'] ?? '');
            $paymentMethod = (string)($_POST['payment_method'] ?? 'manual');
            $paymentNotes = (string)($_POST['payment_notes'] ?? '');
            if (!saas_mark_subscription_invoice_paid($controlConn, $invoiceId, $tenantId, $paidAt, $paymentRef, $paymentMethod, $paymentNotes)) {
                throw new RuntimeException('تعذر تسجيل سداد فاتورة الاشتراك.');
            }
            saas_apply_overdue_policy_for_tenant($controlConn, $tenantId);
            app_saas_log_operation($controlConn, 'subscription.invoice_paid', 'تسجيل سداد فاتورة اشتراك', $tenantId, [
                'invoice_id' => $invoiceId,
                'payment_method' => $paymentMethod,
                'payment_ref' => $paymentRef,
            ], $saasActorName);
            saas_flash_set('success', 'تم تسجيل سداد فاتورة الاشتراك.');
            app_safe_redirect('saas_center.php');
        }

        if ($action === 'reopen_subscription_invoice') {
            $tenantId = (int)($_POST['tenant_id'] ?? 0);
            $invoiceId = (int)($_POST['invoice_id'] ?? 0);
            if (!saas_reopen_subscription_invoice($controlConn, $invoiceId, $tenantId)) {
                throw new RuntimeException('تعذر إعادة فاتورة الاشتراك إلى حالة مستحقة.');
            }
            saas_apply_overdue_policy_for_tenant($controlConn, $tenantId);
            app_saas_log_operation($controlConn, 'subscription.invoice_reopened', 'إعادة فتح فاتورة اشتراك', $tenantId, [
                'invoice_id' => $invoiceId,
            ], $saasActorName);
            saas_flash_set('success', 'تمت إعادة فاتورة الاشتراك إلى حالة مستحقة.');
            app_safe_redirect('saas_center.php');
        }

        if ($action === 'set_current_subscription') {
            $tenantId = (int)($_POST['tenant_id'] ?? 0);
            $subscriptionId = (int)($_POST['subscription_id'] ?? 0);
            if ($tenantId <= 0 || $subscriptionId <= 0) {
                throw new RuntimeException('الاشتراك أو المستأجر غير محدد.');
            }
            $stmtSub = $controlConn->prepare("SELECT id FROM saas_subscriptions WHERE id = ? AND tenant_id = ? LIMIT 1");
            $stmtSub->bind_param('ii', $subscriptionId, $tenantId);
            $stmtSub->execute();
            $subRow = $stmtSub->get_result()->fetch_assoc();
            $stmtSub->close();
            if (!$subRow) {
                throw new RuntimeException('الاشتراك غير موجود داخل هذا المستأجر.');
            }
            $stmtCurrent = $controlConn->prepare("UPDATE saas_tenants SET current_subscription_id = ? WHERE id = ? LIMIT 1");
            $stmtCurrent->bind_param('ii', $subscriptionId, $tenantId);
            $stmtCurrent->execute();
            $stmtCurrent->close();
            saas_sync_tenant_subscription_snapshot($controlConn, $tenantId);
            app_saas_log_operation($controlConn, 'subscription.set_current', 'تعيين الاشتراك الحالي', $tenantId, [
                'subscription_id' => $subscriptionId,
            ], $saasActorName);
            saas_flash_set('success', 'تم تعيين الاشتراك الحالي.');
            app_safe_redirect('saas_center.php');
        }

        if ($action === 'delete_subscription') {
            $tenantId = (int)($_POST['tenant_id'] ?? 0);
            $subscriptionId = (int)($_POST['subscription_id'] ?? 0);
            if ($tenantId <= 0 || $subscriptionId <= 0) {
                throw new RuntimeException('الاشتراك أو المستأجر غير محدد.');
            }
            $stmtSub = $controlConn->prepare("SELECT id FROM saas_subscriptions WHERE id = ? AND tenant_id = ? LIMIT 1");
            $stmtSub->bind_param('ii', $subscriptionId, $tenantId);
            $stmtSub->execute();
            $subRow = $stmtSub->get_result()->fetch_assoc();
            $stmtSub->close();
            if (!$subRow) {
                throw new RuntimeException('الاشتراك غير موجود داخل هذا المستأجر.');
            }

            $controlConn->begin_transaction();
            $stmtDelete = $controlConn->prepare("DELETE FROM saas_subscriptions WHERE id = ? AND tenant_id = ? LIMIT 1");
            $stmtDelete->bind_param('ii', $subscriptionId, $tenantId);
            $stmtDelete->execute();
            $stmtDelete->close();
            saas_refresh_current_subscription($controlConn, $tenantId);
            saas_sync_tenant_subscription_snapshot($controlConn, $tenantId);
            $controlConn->commit();
            app_saas_log_operation($controlConn, 'subscription.deleted', 'حذف اشتراك', $tenantId, [
                'subscription_id' => $subscriptionId,
            ], $saasActorName);

            saas_flash_set('success', 'تم حذف الاشتراك.');
            app_safe_redirect('saas_center.php');
        }

        if ($action === 'set_tenant_status') {
            $tenantId = (int)($_POST['tenant_id'] ?? 0);
            $status = strtolower(trim((string)($_POST['status'] ?? '')));
            if ($tenantId <= 0 || !in_array($status, ['provisioning', 'active', 'suspended', 'archived'], true)) {
                throw new RuntimeException('تعذر تحديث حالة المستأجر.');
            }
            $activatedAt = $status === 'active' ? date('Y-m-d H:i:s') : null;
            $archivedAt = $status === 'archived' ? date('Y-m-d H:i:s') : null;
            $stmt = $controlConn->prepare("UPDATE saas_tenants SET status = ?, activated_at = COALESCE(?, activated_at), archived_at = ? WHERE id = ? LIMIT 1");
            $stmt->bind_param('sssi', $status, $activatedAt, $archivedAt, $tenantId);
            $stmt->execute();
            $stmt->close();
            app_saas_log_operation($controlConn, 'tenant.status_changed', 'تحديث حالة مستأجر', $tenantId, [
                'status' => $status,
            ], $saasActorName);
            saas_flash_set('success', 'تم تحديث حالة المستأجر.');
            app_safe_redirect('saas_center.php');
        }

        if ($action === 'provision_tenant') {
            $tenantId = (int)($_POST['tenant_id'] ?? 0);
            if ($tenantId <= 0) {
                throw new RuntimeException('المستأجر غير محدد للتهيئة.');
            }
            $stmt = $controlConn->prepare("SELECT * FROM saas_tenants WHERE id = ? LIMIT 1");
            $stmt->bind_param('i', $tenantId);
            $stmt->execute();
            $tenantRow = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$tenantRow) {
                throw new RuntimeException('المستأجر غير موجود.');
            }

            $provision = app_saas_provision_tenant($controlConn, $tenantRow, [
                'username' => trim((string)($_POST['admin_username'] ?? 'admin')),
                'full_name' => trim((string)($_POST['admin_full_name'] ?? (string)($tenantRow['tenant_name'] ?? 'System Admin'))),
                'email' => trim((string)($_POST['admin_email'] ?? (string)($tenantRow['billing_email'] ?? ''))),
                'password' => trim((string)($_POST['admin_password'] ?? '')),
            ]);

            $stmtStatus = $controlConn->prepare("UPDATE saas_tenants SET status = 'active', activated_at = COALESCE(activated_at, NOW()) WHERE id = ? LIMIT 1");
            $stmtStatus->bind_param('i', $tenantId);
            $stmtStatus->execute();
            $stmtStatus->close();
            if (trim((string)($tenantRow['app_url'] ?? '')) === '' && trim((string)($provision['app_url'] ?? '')) !== '') {
                $provisionAppUrl = trim((string)$provision['app_url']);
                $stmtAppUrl = $controlConn->prepare("UPDATE saas_tenants SET app_url = ? WHERE id = ? LIMIT 1");
                $stmtAppUrl->bind_param('si', $provisionAppUrl, $tenantId);
                $stmtAppUrl->execute();
                $stmtAppUrl->close();
            }

            app_saas_log_operation($controlConn, 'tenant.provisioned', 'تهيئة مستأجر', $tenantId, [
                'runtime_folder' => (string)($provision['runtime_folder'] ?? ''),
                'app_url' => (string)($provision['app_url'] ?? ''),
                'admin_username' => (string)($provision['admin_username'] ?? 'admin'),
            ], $saasActorName);
            saas_flash_set(
                'success',
                'تمت تهيئة قاعدة المستأجر. مجلد النظام: ' . (($provision['runtime_folder'] ?? '') !== '' ? (string)$provision['runtime_folder'] : '-') . ' | بيانات المدير الأول: ' . ($provision['admin_username'] ?? 'admin') . ' / ' . ($provision['admin_password'] ?? '')
            );
            app_safe_redirect('saas_center.php');
        }

        if ($action === 'delete_tenant') {
            $tenantId = (int)($_POST['tenant_id'] ?? 0);
            $deleteDatabase = !empty($_POST['delete_database']);
            if ($tenantId <= 0) {
                throw new RuntimeException('المستأجر غير محدد.');
            }
            if ($isProductionOwnerRuntime && $deleteDatabase) {
                throw new RuntimeException('حذف قواعد بيانات المستأجرين محظور من بيئة work الإنتاجية. استخدم بيئة اختبار أو فعّل السماح الصريح من الإعدادات البيئية فقط عند الطوارئ.');
            }

            $stmtTenant = $controlConn->prepare("SELECT * FROM saas_tenants WHERE id = ? LIMIT 1");
            $stmtTenant->bind_param('i', $tenantId);
            $stmtTenant->execute();
            $tenantRow = $stmtTenant->get_result()->fetch_assoc();
            $stmtTenant->close();
            if (!$tenantRow) {
                throw new RuntimeException('المستأجر غير موجود.');
            }

            $controlConn->begin_transaction();
            $stmtDelete = $controlConn->prepare("DELETE FROM saas_tenants WHERE id = ? LIMIT 1");
            $stmtDelete->bind_param('i', $tenantId);
            $stmtDelete->execute();
            $stmtDelete->close();

            if ($deleteDatabase) {
                app_saas_drop_tenant_database($controlConn, $tenantRow);
            }

            $controlConn->commit();
            app_saas_log_operation($controlConn, 'tenant.deleted', 'حذف مستأجر', $tenantId, [
                'delete_database' => $deleteDatabase ? 1 : 0,
                'tenant_slug' => (string)($tenantRow['tenant_slug'] ?? ''),
            ], $saasActorName);
            saas_flash_set('success', $deleteDatabase ? 'تم حذف المستأجر وقاعدة بياناته.' : 'تم حذف المستأجر من مركز SaaS.');
            app_safe_redirect('saas_center.php');
        }

        if ($action === 'backup_tenant') {
            $tenantId = (int)($_POST['tenant_id'] ?? 0);
            if ($tenantId <= 0) {
                throw new RuntimeException('المستأجر غير محدد للنسخ الاحتياطي.');
            }
            $stmtTenant = $controlConn->prepare("SELECT * FROM saas_tenants WHERE id = ? LIMIT 1");
            $stmtTenant->bind_param('i', $tenantId);
            $stmtTenant->execute();
            $tenantRow = $stmtTenant->get_result()->fetch_assoc();
            $stmtTenant->close();
            if (!$tenantRow) {
                throw new RuntimeException('المستأجر غير موجود.');
            }

            $backup = app_saas_backup_tenant($tenantRow);
            $backupUrl = trim((string)($backup['url'] ?? ''));
            $message = 'تم إنشاء نسخة احتياطية للمستأجر بنجاح: ' . (string)($backup['filename'] ?? 'backup');
            if ($backupUrl !== '') {
                $message .= ' | ' . $backupUrl;
            }
            app_saas_log_operation($controlConn, 'tenant.backup_created', 'إنشاء نسخة احتياطية', $tenantId, [
                'filename' => (string)($backup['filename'] ?? ''),
                'url' => $backupUrl,
            ], $saasActorName);
            saas_flash_set('success', $message);
            app_safe_redirect('saas_center.php');
        }

        if ($action === 'export_tenant_package') {
            $tenantId = (int)($_POST['tenant_id'] ?? 0);
            if ($tenantId <= 0) {
                throw new RuntimeException('المستأجر غير محدد للتصدير.');
            }
            $stmtTenant = $controlConn->prepare("SELECT * FROM saas_tenants WHERE id = ? LIMIT 1");
            $stmtTenant->bind_param('i', $tenantId);
            $stmtTenant->execute();
            $tenantRow = $stmtTenant->get_result()->fetch_assoc();
            $stmtTenant->close();
            if (!$tenantRow) {
                throw new RuntimeException('المستأجر غير موجود.');
            }

            $export = app_saas_export_tenant_package($tenantRow);
            app_saas_log_operation($controlConn, 'tenant.exported', 'تصدير حزمة مستأجر', $tenantId, [
                'filename' => (string)($export['filename'] ?? ''),
                'url' => (string)($export['url'] ?? ''),
                'backup_filename' => (string)($export['backup_filename'] ?? ''),
            ], $saasActorName);
            saas_flash_set('success', 'تم إنشاء حزمة تصدير للمستأجر: ' . (string)($export['filename'] ?? 'package') . ' | ' . (string)($export['url'] ?? ''));
            app_safe_redirect('saas_center.php');
        }

        if ($action === 'clone_tenant_blueprint') {
            if ($isProductionOwnerRuntime) {
                throw new RuntimeException('الاستنساخ محظور من بيئة work الإنتاجية افتراضيًا. استخدم sys أو plast للتجارب والتهيئة.');
            }
            $sourceTenantId = (int)($_POST['source_tenant_id'] ?? 0);
            $cloneSeedPresets = array_values(array_unique(array_map('strval', (array)($_POST['clone_seed_presets'] ?? []))));
            if ($sourceTenantId <= 0) {
                throw new RuntimeException('المستأجر المصدر غير محدد للاستنساخ.');
            }
            if (!empty($cloneSeedPresets) && empty($_POST['clone_provision_now'])) {
                throw new RuntimeException('نسخ البيانات التأسيسية يتطلب تفعيل "تهيئة النسخة مباشرة بعد الإنشاء" أولًا.');
            }
            $stmtTenant = $controlConn->prepare("SELECT * FROM saas_tenants WHERE id = ? LIMIT 1");
            $stmtTenant->bind_param('i', $sourceTenantId);
            $stmtTenant->execute();
            $sourceTenant = $stmtTenant->get_result()->fetch_assoc();
            $stmtTenant->close();
            if (!$sourceTenant) {
                throw new RuntimeException('المستأجر المصدر غير موجود.');
            }

            $clone = app_saas_clone_tenant_blueprint($controlConn, $sourceTenant, [
                'tenant_slug' => saas_slugify((string)($_POST['clone_tenant_slug'] ?? '')),
                'tenant_name' => trim((string)($_POST['clone_tenant_name'] ?? '')),
                'system_name' => trim((string)($_POST['clone_system_name'] ?? '')),
                'system_folder' => trim((string)($_POST['clone_system_folder'] ?? '')),
                'legal_name' => trim((string)($_POST['clone_legal_name'] ?? '')),
                'plan_code' => trim((string)($_POST['clone_plan_code'] ?? '')),
                'provision_profile' => trim((string)($_POST['clone_provision_profile'] ?? (string)($sourceTenant['provision_profile'] ?? 'standard'))),
                'policy_pack' => trim((string)($_POST['clone_policy_pack'] ?? (string)($sourceTenant['policy_pack'] ?? 'standard'))),
                'billing_email' => trim((string)($_POST['clone_billing_email'] ?? '')),
                'app_url' => trim((string)($_POST['clone_app_url'] ?? '')),
                'db_host' => trim((string)($_POST['clone_db_host'] ?? 'localhost')),
                'db_port' => (int)($_POST['clone_db_port'] ?? 3306),
                'db_name' => trim((string)($_POST['clone_db_name'] ?? '')),
                'db_user' => trim((string)($_POST['clone_db_user'] ?? '')),
                'db_password' => (string)($_POST['clone_db_password'] ?? ''),
                'db_socket' => trim((string)($_POST['clone_db_socket'] ?? '')),
                'timezone' => trim((string)($_POST['clone_timezone'] ?? 'Africa/Cairo')),
                'locale' => trim((string)($_POST['clone_locale'] ?? 'ar')),
                'users_limit' => (int)($_POST['clone_users_limit'] ?? 0),
                'storage_limit_mb' => (int)($_POST['clone_storage_limit_mb'] ?? 0),
                'ops_keep_latest' => (int)($_POST['clone_ops_keep_latest'] ?? (int)($sourceTenant['ops_keep_latest'] ?? 500)),
                'ops_keep_days' => (int)($_POST['clone_ops_keep_days'] ?? (int)($sourceTenant['ops_keep_days'] ?? 30)),
                'copy_policy_overrides' => !empty($_POST['clone_copy_policy_overrides']) ? 1 : 0,
                'notes' => trim((string)($_POST['clone_notes'] ?? '')),
            ]);
            $clonedTenantId = (int)($clone['tenant_id'] ?? 0);
            $provisionNow = !empty($_POST['clone_provision_now']);
            $provisionMessage = '';
            $seedMessage = '';
            $reviewMessage = '';
            $comparisonMessage = '';
            if ($provisionNow && $clonedTenantId > 0) {
                $stmtCloned = $controlConn->prepare("SELECT * FROM saas_tenants WHERE id = ? LIMIT 1");
                $stmtCloned->bind_param('i', $clonedTenantId);
                $stmtCloned->execute();
                $clonedTenant = $stmtCloned->get_result()->fetch_assoc();
                $stmtCloned->close();
                if ($clonedTenant) {
                    $provision = app_saas_provision_tenant($controlConn, $clonedTenant, [
                        'username' => trim((string)($_POST['clone_admin_username'] ?? 'admin')),
                        'full_name' => trim((string)($_POST['clone_admin_full_name'] ?? (string)($clonedTenant['tenant_name'] ?? 'System Admin'))),
                        'email' => trim((string)($_POST['clone_admin_email'] ?? (string)($clonedTenant['billing_email'] ?? ''))),
                        'password' => trim((string)($_POST['clone_admin_password'] ?? '')),
                    ]);
                    $stmtStatus = $controlConn->prepare("UPDATE saas_tenants SET status = 'active', activated_at = COALESCE(activated_at, NOW()) WHERE id = ? LIMIT 1");
                    $stmtStatus->bind_param('i', $clonedTenantId);
                    $stmtStatus->execute();
                    $stmtStatus->close();
                    app_saas_log_operation($controlConn, 'tenant.clone_provisioned', 'تهيئة نسخة مستنسخة', $clonedTenantId, [
                        'admin_username' => (string)($provision['admin_username'] ?? 'admin'),
                        'runtime_folder' => (string)($provision['runtime_folder'] ?? ''),
                    ], $saasActorName);
                    $provisionMessage = ' | تمت التهيئة مباشرة: ' . (string)($provision['admin_username'] ?? 'admin') . ' / ' . (string)($provision['admin_password'] ?? '');

                    if (!empty($cloneSeedPresets)) {
                        $seed = app_saas_clone_tenant_seed_data($sourceTenant, $clonedTenant, $cloneSeedPresets);
                        app_saas_log_operation($controlConn, 'tenant.clone_seeded_data', 'نسخ بيانات تأسيسية لنسخة مستنسخة', $clonedTenantId, [
                            'source_tenant_id' => $sourceTenantId,
                            'presets' => (array)($seed['presets'] ?? []),
                            'tables' => (array)($seed['tables'] ?? []),
                            'rows_copied' => (int)($seed['rows_copied'] ?? 0),
                        ], $saasActorName);
                        $seedMessage = ' | تم نسخ بيانات تأسيسية بعدد سجلات: ' . (int)($seed['rows_copied'] ?? 0);
                    }

                    $stmtReviewTenant = $controlConn->prepare("SELECT * FROM saas_tenants WHERE id = ? LIMIT 1");
                    $stmtReviewTenant->bind_param('i', $clonedTenantId);
                    $stmtReviewTenant->execute();
                    $reviewTenant = $stmtReviewTenant->get_result()->fetch_assoc();
                    $stmtReviewTenant->close();
                    if ($reviewTenant) {
                        $review = app_saas_clone_post_review($reviewTenant);
                        app_saas_log_operation($controlConn, 'tenant.clone_reviewed', 'مراجعة جاهزية نسخة مستنسخة', $clonedTenantId, [
                            'health' => (array)($review['health'] ?? []),
                            'counts' => (array)($review['counts'] ?? []),
                            'summary' => (string)($review['summary'] ?? ''),
                        ], $saasActorName);
                        $reviewMessage = ' | مراجعة النسخة: ' . (string)($review['summary'] ?? '');

                        $comparison = app_saas_clone_comparison_snapshot($sourceTenant, $reviewTenant, $cloneSeedPresets);
                        app_saas_log_operation($controlConn, 'tenant.clone_compared', 'مقارنة المصدر بالنسخة المستنسخة', $clonedTenantId, [
                            'source_tenant_id' => $sourceTenantId,
                            'source_tenant_slug' => (string)($sourceTenant['tenant_slug'] ?? ''),
                            'tables' => (array)($comparison['tables'] ?? []),
                            'source_counts' => (array)($comparison['source_counts'] ?? []),
                            'target_counts' => (array)($comparison['target_counts'] ?? []),
                            'delta_counts' => (array)($comparison['delta_counts'] ?? []),
                            'summary' => (string)($comparison['summary'] ?? ''),
                        ], $saasActorName);
                        $comparisonMessage = ' | مقارنة المصدر: ' . (string)($comparison['summary'] ?? '');
                    }
                }
            }
            app_saas_log_operation($controlConn, 'tenant.cloned_blueprint', 'استنساخ مستأجر كمسودة', (int)($clone['tenant_id'] ?? 0), [
                'source_tenant_id' => $sourceTenantId,
                'source_tenant_slug' => (string)($sourceTenant['tenant_slug'] ?? ''),
                'runtime_folder' => (string)($clone['runtime_folder'] ?? ''),
                'provisioned_now' => $provisionNow ? 1 : 0,
                'provision_profile' => trim((string)($_POST['clone_provision_profile'] ?? (string)($sourceTenant['provision_profile'] ?? 'standard'))),
                'policy_pack' => trim((string)($_POST['clone_policy_pack'] ?? (string)($sourceTenant['policy_pack'] ?? 'standard'))),
                'seed_presets' => $cloneSeedPresets,
                'copied_policy_overrides' => (array)($clone['copied_policy_overrides'] ?? []),
            ], $saasActorName);
            $policyOverridesMessage = !empty($clone['copied_policy_overrides']) ? ' | تم توريث استثناءات السياسة' : '';
            saas_flash_set('success', 'تم إنشاء نسخة مسودة من المستأجر: ' . (string)($clone['tenant_name'] ?? '') . ' | ' . (string)($clone['app_url'] ?? '') . $policyOverridesMessage . $provisionMessage . $seedMessage . $reviewMessage . $comparisonMessage);
            app_safe_redirect('saas_center.php');
        }

        if ($action === 'cleanup_operation_log') {
            $keepLatest = max(100, (int)($_POST['keep_latest_logs'] ?? 1000));
            $olderThanDays = max(1, (int)($_POST['older_than_days'] ?? 90));
            $cleanup = app_saas_cleanup_operation_log($controlConn, $keepLatest, $olderThanDays);
            app_saas_log_operation($controlConn, 'operations.cleaned', 'تنظيف سجل العمليات', 0, [
                'deleted' => (int)($cleanup['deleted'] ?? 0),
                'keep_latest' => (int)($cleanup['keep_latest'] ?? $keepLatest),
                'older_than_days' => (int)($cleanup['older_than_days'] ?? $olderThanDays),
            ], $saasActorName);
            saas_flash_set('success', 'تم تنظيف سجل العمليات. المحذوف: ' . (int)($cleanup['deleted'] ?? 0) . ' | الاحتفاظ بآخر: ' . (int)($cleanup['keep_latest'] ?? $keepLatest) . ' | الأقدم من: ' . (int)($cleanup['older_than_days'] ?? $olderThanDays) . ' يوم.');
            app_safe_redirect('saas_center.php');
        }

        if ($action === 'retry_webhook_delivery') {
            $deliveryId = (int)($_POST['delivery_id'] ?? 0);
            $retry = function_exists('saas_retry_webhook_delivery_now')
                ? saas_retry_webhook_delivery_now($controlConn, $deliveryId)
                : ['ok' => false, 'reason' => 'helper_missing'];
            if (empty($retry['ok'])) {
                throw new RuntimeException('تعذر إعادة محاولة الـ Webhook: ' . (string)($retry['reason'] ?? 'retry_failed'));
            }
            saas_flash_set('success', 'تمت إعادة محاولة الـ Webhook بنجاح. HTTP: ' . (int)($retry['http_code'] ?? 0));
            app_safe_redirect('saas_center.php?webhook_open=1#webhooks-card');
        }

        if ($action === 'restore_tenant') {
            $tenantId = (int)($_POST['tenant_id'] ?? 0);
            $backupFilename = basename(trim((string)($_POST['backup_filename'] ?? '')));
            if ($tenantId <= 0) {
                throw new RuntimeException('المستأجر غير محدد للاستعادة.');
            }
            if ($isProductionOwnerRuntime) {
                throw new RuntimeException('استعادة المستأجرين محظورة من بيئة work الإنتاجية افتراضيًا لحماية البيانات الحقيقية.');
            }
            if ($backupFilename === '') {
                throw new RuntimeException('اختر نسخة احتياطية صالحة قبل بدء الاستعادة.');
            }

            $stmtTenant = $controlConn->prepare("SELECT * FROM saas_tenants WHERE id = ? LIMIT 1");
            $stmtTenant->bind_param('i', $tenantId);
            $stmtTenant->execute();
            $tenantRow = $stmtTenant->get_result()->fetch_assoc();
            $stmtTenant->close();
            if (!$tenantRow) {
                throw new RuntimeException('المستأجر غير موجود.');
            }

            $backup = app_saas_find_tenant_backup($tenantRow, $backupFilename);
            if (!$backup) {
                throw new RuntimeException('النسخة الاحتياطية المختارة غير موجودة لهذا المستأجر.');
            }

            $restore = app_saas_restore_tenant($tenantRow, $backup);
            $message = 'تمت استعادة المستأجر من النسخة: ' . (string)($restore['restored_from'] ?? $backupFilename);
            if (trim((string)($restore['safety_backup'] ?? '')) !== '') {
                $message .= ' | نسخة أمان قبل الاستعادة: ' . (string)$restore['safety_backup'];
            }
            app_saas_log_operation($controlConn, 'tenant.restored', 'استعادة مستأجر', $tenantId, [
                'restored_from' => (string)($restore['restored_from'] ?? $backupFilename),
                'safety_backup' => (string)($restore['safety_backup'] ?? ''),
            ], $saasActorName);
            saas_flash_set('success', $message);
            app_safe_redirect('saas_center.php');
        }

        if ($action === 'recover_tenant_access') {
            $tenantId = (int)($_POST['tenant_id'] ?? 0);
            if ($tenantId <= 0) {
                throw new RuntimeException('المستأجر غير محدد لاسترجاع الدخول.');
            }

            $stmtTenant = $controlConn->prepare("SELECT * FROM saas_tenants WHERE id = ? LIMIT 1");
            $stmtTenant->bind_param('i', $tenantId);
            $stmtTenant->execute();
            $tenantRow = $stmtTenant->get_result()->fetch_assoc();
            $stmtTenant->close();
            if (!$tenantRow) {
                throw new RuntimeException('المستأجر غير موجود.');
            }

            $recover = app_saas_reset_tenant_admin_access(
                $tenantRow,
                trim((string)($_POST['recover_username'] ?? 'admin')),
                trim((string)($_POST['recover_password'] ?? '')),
                trim((string)($_POST['recover_full_name'] ?? (string)($tenantRow['tenant_name'] ?? 'System Admin'))),
                trim((string)($_POST['recover_email'] ?? (string)($tenantRow['billing_email'] ?? '')))
            );
            saas_flash_set(
                'success',
                'تم استرجاع دخول المستأجر: '
                . (string)($recover['username'] ?? 'admin')
                . ' / '
                . (string)($recover['password'] ?? '')
                . ' | '
                . (string)($recover['login_url'] ?? '')
            );
            app_saas_log_operation($controlConn, 'tenant.access_recovered', 'استرجاع دخول مستأجر', $tenantId, [
                'username' => (string)($recover['username'] ?? 'admin'),
                'login_url' => (string)($recover['login_url'] ?? ''),
            ], $saasActorName);
            app_safe_redirect('saas_center.php');
        }
    } catch (Throwable $e) {
        try {
            $controlConn->rollback();
        } catch (Throwable $rollbackError) {
        }
        saas_flash_set('error', 'تعذر تنفيذ العملية: ' . $e->getMessage());
        app_safe_redirect('saas_center.php');
    }
}

$flash = saas_flash_get();
$cloneSeedPresetsCatalog = function_exists('app_saas_seedable_clone_presets') ? app_saas_seedable_clone_presets() : [];
$cloneTemplatePresets = function_exists('saas_clone_template_presets') ? saas_clone_template_presets() : [];
$allProvisionProfiles = function_exists('app_saas_list_provision_profiles') ? app_saas_list_provision_profiles($controlConn, false) : [];
$tenantProvisionProfiles = function_exists('app_saas_list_provision_profiles') ? app_saas_list_provision_profiles($controlConn, true) : [];
$allPolicyPacks = function_exists('app_saas_list_policy_packs') ? app_saas_list_policy_packs($controlConn, false) : [];
$tenantPolicyPacks = function_exists('app_saas_list_policy_packs') ? app_saas_list_policy_packs($controlConn, true) : [];
$allPolicyExceptionPresets = function_exists('app_saas_list_policy_exception_presets') ? app_saas_list_policy_exception_presets($controlConn, false) : [];
$tenantPolicyExceptionPresets = function_exists('app_saas_list_policy_exception_presets') ? app_saas_list_policy_exception_presets($controlConn, true) : [];
$provisionProfileMap = [];
foreach ($allProvisionProfiles as $profileRow) {
    $profileKey = strtolower(trim((string)($profileRow['profile_key'] ?? '')));
    if ($profileKey !== '') {
        $provisionProfileMap[$profileKey] = $profileRow;
    }
}
$policyPackMap = [];
foreach ($allPolicyPacks as $packRow) {
    $packKey = strtolower(trim((string)($packRow['pack_key'] ?? '')));
    if ($packKey !== '') {
        $policyPackMap[$packKey] = $packRow;
    }
}
$policyExceptionPresetMap = [];
foreach ($allPolicyExceptionPresets as $presetRow) {
    $presetKey = strtolower(trim((string)($presetRow['preset_key'] ?? '')));
    if ($presetKey !== '') {
        $policyExceptionPresetMap[$presetKey] = $presetRow;
    }
}

$tenantRows = [];
$tenantIds = [];
$tenantsRes = $controlConn->query("
    SELECT
        t.*,
        (SELECT COUNT(*) FROM saas_tenant_domains d WHERE d.tenant_id = t.id) AS domains_count,
        (SELECT d.domain FROM saas_tenant_domains d WHERE d.tenant_id = t.id ORDER BY d.is_primary DESC, d.id ASC LIMIT 1) AS primary_domain,
        (SELECT s.status FROM saas_subscriptions s WHERE s.id = t.current_subscription_id LIMIT 1) AS subscription_status,
        (SELECT s.plan_code FROM saas_subscriptions s WHERE s.id = t.current_subscription_id LIMIT 1) AS subscription_plan,
        (SELECT s.renews_at FROM saas_subscriptions s WHERE s.id = t.current_subscription_id LIMIT 1) AS subscription_renews_at
    FROM saas_tenants t
    ORDER BY t.id DESC
");
while ($row = $tenantsRes->fetch_assoc()) {
    $tenantRows[] = $row;
    $tenantIds[] = (int)$row['id'];
}

if (!function_exists('saas_tenant_priority_score')) {
    function saas_tenant_priority_score(array $tenant, array $health, int $domainsCount, int $subscriptionsCount): int
    {
        $score = 0;
        $status = strtolower(trim((string)($tenant['status'] ?? '')));
        $subscriptionStatus = strtolower(trim((string)($tenant['subscription_status'] ?? '')));
        $severity = strtolower(trim((string)($health['severity'] ?? 'ok')));

        if ($severity === 'critical') {
            $score += 1000;
        } elseif ($severity === 'warning') {
            $score += 600;
        }
        if ($subscriptionStatus === 'past_due') {
            $score += 500;
        } elseif ($subscriptionStatus === 'suspended') {
            $score += 450;
        } elseif ($subscriptionStatus === 'trial') {
            $score += 220;
        } elseif ($subscriptionStatus === 'none' || $subscriptionStatus === '') {
            $score += 180;
        }
        if ($status === 'provisioning') {
            $score += 350;
        } elseif ($status === 'suspended') {
            $score += 300;
        } elseif ($status === 'archived') {
            $score -= 200;
        }
        if ($domainsCount <= 0) {
            $score += 80;
        }
        if ($subscriptionsCount <= 0) {
            $score += 70;
        }

        return $score;
    }
}

$profileUsageSummary = [];
foreach ($tenantRows as $tenantRow) {
    $profileKey = strtolower(trim((string)($tenantRow['provision_profile'] ?? 'standard')));
    if ($profileKey === '') {
        $profileKey = 'standard';
    }
    if (!isset($profileUsageSummary[$profileKey])) {
        $profileUsageSummary[$profileKey] = [
            'assigned' => 0,
            'drifted' => 0,
            'samples' => [],
        ];
    }
    $profileUsageSummary[$profileKey]['assigned']++;
    if (isset($provisionProfileMap[$profileKey]) && function_exists('app_saas_provision_profile_diff')) {
        $profileDiff = app_saas_provision_profile_diff($tenantRow, $provisionProfileMap[$profileKey]);
        if (empty($profileDiff['is_same'])) {
            $profileUsageSummary[$profileKey]['drifted']++;
            if (count($profileUsageSummary[$profileKey]['samples']) < 3) {
                $profileUsageSummary[$profileKey]['samples'][] = (string)($tenantRow['tenant_name'] ?: $tenantRow['tenant_slug'] ?: ('#' . (int)($tenantRow['id'] ?? 0)));
            }
        }
    }
}
$policyPackUsageSummary = [];
$policyExceptionPresetUsageSummary = [];

$domainsByTenant = [];
$subscriptionsByTenant = [];
$subscriptionInvoicesByTenant = [];
$subscriptionPaymentByInvoice = [];
$backupsByTenant = [];
$exportsByTenant = [];
if (!empty($tenantIds)) {
    $idsSql = implode(',', array_map('intval', $tenantIds));
    $domainRes = $controlConn->query("SELECT * FROM saas_tenant_domains WHERE tenant_id IN ($idsSql) ORDER BY is_primary DESC, domain ASC");
    while ($row = $domainRes->fetch_assoc()) {
        $domainsByTenant[(int)$row['tenant_id']][] = $row;
    }
    $subscriptionRes = $controlConn->query("SELECT * FROM saas_subscriptions WHERE tenant_id IN ($idsSql) ORDER BY id DESC");
    while ($row = $subscriptionRes->fetch_assoc()) {
        $subscriptionsByTenant[(int)$row['tenant_id']][] = $row;
    }
    $subscriptionInvoiceRes = $controlConn->query("SELECT * FROM saas_subscription_invoices WHERE tenant_id IN ($idsSql) ORDER BY id DESC");
    while ($row = $subscriptionInvoiceRes->fetch_assoc()) {
        if (function_exists('saas_issue_subscription_invoice_access')) {
            $row = saas_issue_subscription_invoice_access($controlConn, $row, $conn);
        }
        $subscriptionInvoicesByTenant[(int)$row['tenant_id']][] = $row;
    }
    $subscriptionPaymentRes = $controlConn->query("
        SELECT *
        FROM saas_subscription_invoice_payments
        WHERE tenant_id IN ($idsSql)
        ORDER BY id DESC
    ");
    while ($row = $subscriptionPaymentRes->fetch_assoc()) {
        $invoiceId = (int)($row['invoice_id'] ?? 0);
        if ($invoiceId > 0 && !isset($subscriptionPaymentByInvoice[$invoiceId])) {
            $subscriptionPaymentByInvoice[$invoiceId] = $row;
        }
    }
}

foreach ($tenantRows as $tenantRow) {
    $packKey = strtolower(trim((string)($tenantRow['policy_pack'] ?? 'standard')));
    if ($packKey === '') {
        $packKey = 'standard';
    }
    if (!isset($policyPackUsageSummary[$packKey])) {
        $policyPackUsageSummary[$packKey] = [
            'assigned' => 0,
            'drifted' => 0,
            'samples' => [],
        ];
    }
    $policyPackUsageSummary[$packKey]['assigned']++;
    $currentSub = $subscriptionsByTenant[(int)($tenantRow['id'] ?? 0)][0] ?? null;
    if (isset($policyPackMap[$packKey]) && function_exists('app_saas_policy_pack_diff')) {
        $packDiff = app_saas_policy_pack_diff($tenantRow, is_array($currentSub) ? $currentSub : null, $policyPackMap[$packKey]);
        if (empty($packDiff['is_same'])) {
            $policyPackUsageSummary[$packKey]['drifted']++;
            if (count($policyPackUsageSummary[$packKey]['samples']) < 3) {
                $policyPackUsageSummary[$packKey]['samples'][] = (string)($tenantRow['tenant_name'] ?: $tenantRow['tenant_slug'] ?: ('#' . (int)($tenantRow['id'] ?? 0)));
            }
        }
    }

    $exceptionPresetKey = strtolower(trim((string)($tenantRow['policy_exception_preset'] ?? '')));
    if ($exceptionPresetKey !== '') {
        if (!isset($policyExceptionPresetUsageSummary[$exceptionPresetKey])) {
            $policyExceptionPresetUsageSummary[$exceptionPresetKey] = [
                'assigned' => 0,
                'drifted' => 0,
                'samples' => [],
            ];
        }
        $policyExceptionPresetUsageSummary[$exceptionPresetKey]['assigned']++;
        if (isset($policyExceptionPresetMap[$exceptionPresetKey]) && function_exists('app_saas_policy_exception_preset_diff')) {
            $presetDiff = app_saas_policy_exception_preset_diff($tenantRow, $policyExceptionPresetMap[$exceptionPresetKey]);
            if (empty($presetDiff['is_same'])) {
                $policyExceptionPresetUsageSummary[$exceptionPresetKey]['drifted']++;
                if (count($policyExceptionPresetUsageSummary[$exceptionPresetKey]['samples']) < 3) {
                    $policyExceptionPresetUsageSummary[$exceptionPresetKey]['samples'][] = (string)($tenantRow['tenant_name'] ?: $tenantRow['tenant_slug'] ?: ('#' . (int)($tenantRow['id'] ?? 0)));
                }
            }
        }
    }
}

foreach ($tenantRows as $row) {
    $tenantId = (int)($row['id'] ?? 0);
    if ($tenantId > 0) {
        $backupsByTenant[$tenantId] = app_saas_list_tenant_backups($row);
        $exportsByTenant[$tenantId] = app_saas_list_tenant_exports($row);
    }
}

$opsSearch = strtolower(trim((string)($_GET['ops_search'] ?? '')));
$opsActionFilter = strtolower(trim((string)($_GET['ops_action'] ?? '')));
$opsTenantFilter = max(0, (int)($_GET['ops_tenant_id'] ?? 0));
$opsActorFilter = strtolower(trim((string)($_GET['ops_actor'] ?? '')));
$opsKeepLatest = max(100, (int)($_GET['ops_keep_latest'] ?? 1000));
$opsOlderThanDays = max(1, (int)($_GET['ops_older_than_days'] ?? 90));
$opsDrawerOpen = !empty($_GET['ops_open']) || $opsSearch !== '' || $opsActionFilter !== '' || $opsTenantFilter > 0 || $opsActorFilter !== '' || !empty($_GET['export_ops']);
$allRecentSaasOps = $opsDrawerOpen ? app_saas_recent_operations($controlConn, 150) : [];
$webhookStatusFilter = strtolower(trim((string)($_GET['webhook_status'] ?? '')));
$webhookEventFilter = strtolower(trim((string)($_GET['webhook_event'] ?? '')));
$webhookTenantFilter = max(0, (int)($_GET['webhook_tenant_id'] ?? 0));
$webhookDrawerOpen = !empty($_GET['webhook_open']) || $webhookStatusFilter !== '' || $webhookEventFilter !== '' || $webhookTenantFilter > 0;
$allWebhookDeliveries = $webhookDrawerOpen && function_exists('app_saas_recent_webhook_deliveries')
    ? app_saas_recent_webhook_deliveries($controlConn, 150)
    : [];
$webhookTestDrawerOpen = !empty($_GET['webhook_test_open']);
$recentWebhookTestInbox = $webhookTestDrawerOpen && function_exists('app_saas_recent_webhook_test_inbox')
    ? app_saas_recent_webhook_test_inbox($controlConn, 50)
    : [];
$webhookTestReceiverUrl = function_exists('app_saas_webhook_test_receiver_url')
    ? app_saas_webhook_test_receiver_url()
    : rtrim(app_base_url(), '/') . '/saas_webhook_test_receiver.php';
$webhookEventOptions = [];
$recentWebhookDeliveries = [];
$opsActionOptions = [];
$opsActorOptions = [];
$recentSaasOps = [];
$latestCloneReviewByTenant = [];
$latestCloneComparisonByTenant = [];
foreach ($allRecentSaasOps as $opRow) {
    $actionCode = strtolower(trim((string)($opRow['action_code'] ?? '')));
    $actorName = trim((string)($opRow['actor_name'] ?? ''));
    if ($actionCode !== '') {
        $opsActionOptions[$actionCode] = (string)($opRow['action_label'] ?? $actionCode);
    }
    if ($actorName !== '') {
        $opsActorOptions[strtolower($actorName)] = $actorName;
    }
    if ($actionCode === 'tenant.clone_reviewed') {
        $tenantId = (int)($opRow['tenant_id'] ?? 0);
        if ($tenantId > 0 && !isset($latestCloneReviewByTenant[$tenantId])) {
            $latestCloneReviewByTenant[$tenantId] = $opRow;
        }
    }
    if ($actionCode === 'tenant.clone_compared') {
        $tenantId = (int)($opRow['tenant_id'] ?? 0);
        if ($tenantId > 0 && !isset($latestCloneComparisonByTenant[$tenantId])) {
            $latestCloneComparisonByTenant[$tenantId] = $opRow;
        }
    }
    if (saas_operation_matches_filters($opRow, $opsSearch, $opsActionFilter, $opsTenantFilter, $opsActorFilter)) {
        $recentSaasOps[] = $opRow;
    }
}
foreach ($allWebhookDeliveries as $deliveryRow) {
    $eventCode = strtolower(trim((string)($deliveryRow['event_code'] ?? '')));
    $statusCode = strtolower(trim((string)($deliveryRow['status'] ?? '')));
    if ($eventCode !== '') {
        $webhookEventOptions[$eventCode] = $eventCode;
    }
    if ($webhookStatusFilter !== '' && $statusCode !== $webhookStatusFilter) {
        continue;
    }
    if ($webhookEventFilter !== '' && $eventCode !== $webhookEventFilter) {
        continue;
    }
    if ($webhookTenantFilter > 0 && (int)($deliveryRow['tenant_id'] ?? 0) !== $webhookTenantFilter) {
        continue;
    }
    $recentWebhookDeliveries[] = $deliveryRow;
}
if (!empty($_GET['export_ops'])) {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="saas-operations-' . date('Ymd-His') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['created_at', 'action_code', 'action_label', 'tenant_id', 'tenant_slug', 'tenant_name', 'actor_name', 'context']);
    foreach ($recentSaasOps as $opRow) {
        fputcsv($out, [
            (string)($opRow['created_at'] ?? ''),
            (string)($opRow['action_code'] ?? ''),
            (string)($opRow['action_label'] ?? ''),
            (int)($opRow['tenant_id'] ?? 0),
            (string)($opRow['tenant_slug'] ?? ''),
            (string)($opRow['tenant_name'] ?? ''),
            (string)($opRow['actor_name'] ?? ''),
            (string)($opRow['context_json'] ?? ''),
        ]);
    }
    fclose($out);
    exit;
}

$stats = [
    'total' => count($tenantRows),
    'active' => 0,
    'suspended' => 0,
    'domains' => 0,
    'trial_subscriptions' => 0,
    'past_due_subscriptions' => 0,
    'active_subscriptions' => 0,
    'subscriptions_total' => 0,
    'health_ok' => 0,
    'health_warning' => 0,
    'health_critical' => 0,
];
$tenantHealthById = [];
foreach ($tenantRows as $row) {
    $stats['domains'] += (int)($row['domains_count'] ?? 0);
    $status = strtolower(trim((string)($row['status'] ?? '')));
    if ($status === 'active') {
        $stats['active']++;
    } elseif ($status === 'suspended') {
        $stats['suspended']++;
    }
    $tenantId = (int)($row['id'] ?? 0);
    if ($tenantId > 0) {
        $health = app_saas_tenant_health($row);
        $tenantHealthById[$tenantId] = $health;
        if (($health['severity'] ?? 'ok') === 'critical') {
            $stats['health_critical']++;
        } elseif (($health['severity'] ?? 'ok') === 'warning') {
            $stats['health_warning']++;
        } else {
            $stats['health_ok']++;
        }
    }
}
foreach ($subscriptionsByTenant as $tenantSubscriptions) {
    foreach ($tenantSubscriptions as $subRow) {
        $stats['subscriptions_total']++;
        $subStatus = strtolower(trim((string)($subRow['status'] ?? '')));
        if ($subStatus === 'active') {
            $stats['active_subscriptions']++;
        } elseif ($subStatus === 'trial') {
            $stats['trial_subscriptions']++;
        } elseif ($subStatus === 'past_due') {
            $stats['past_due_subscriptions']++;
        }
    }
}

foreach ($tenantRows as &$tenantRowRef) {
    $tenantId = (int)($tenantRowRef['id'] ?? 0);
    if ($tenantId > 0 && function_exists('saas_issue_tenant_billing_portal_access')) {
        $tenantRowRef = saas_issue_tenant_billing_portal_access($controlConn, $tenantRowRef);
    }
    $health = $tenantHealthById[$tenantId] ?? ['severity' => 'ok', 'issues' => []];
    $tenantRowRef['_priority_score'] = saas_tenant_priority_score(
        $tenantRowRef,
        $health,
        count((array)($domainsByTenant[$tenantId] ?? [])),
        count((array)($subscriptionsByTenant[$tenantId] ?? []))
    );
}
unset($tenantRowRef);

usort($tenantRows, static function (array $a, array $b): int {
    $scoreA = (int)($a['_priority_score'] ?? 0);
    $scoreB = (int)($b['_priority_score'] ?? 0);
    if ($scoreA !== $scoreB) {
        return $scoreB <=> $scoreA;
    }
    return ((int)($b['id'] ?? 0)) <=> ((int)($a['id'] ?? 0));
});

$invoiceStats = [
    'total' => 0,
    'issued_count' => 0,
    'paid_count' => 0,
    'overdue_count' => 0,
    'issued_amount' => 0.0,
    'paid_amount' => 0.0,
    'outstanding_amount' => 0.0,
];
$invoiceAging = [
    'current' => ['label' => 'غير مستحقة بعد', 'count' => 0, 'amount' => 0.0],
    '1_7' => ['label' => 'متأخرة 1-7 أيام', 'count' => 0, 'amount' => 0.0],
    '8_30' => ['label' => 'متأخرة 8-30 يوم', 'count' => 0, 'amount' => 0.0],
    '31_60' => ['label' => 'متأخرة 31-60 يوم', 'count' => 0, 'amount' => 0.0],
    '61_plus' => ['label' => 'متأخرة +60 يوم', 'count' => 0, 'amount' => 0.0],
];
foreach ($subscriptionInvoicesByTenant as $tenantInvoices) {
    foreach ($tenantInvoices as $invoiceRow) {
        $invoiceStats['total']++;
        $invoiceStatus = strtolower(trim((string)($invoiceRow['status'] ?? 'issued')));
        $invoiceAmount = round((float)($invoiceRow['amount'] ?? 0), 2);
        if ($invoiceStatus === 'paid') {
            $invoiceStats['paid_count']++;
            $invoiceStats['paid_amount'] += $invoiceAmount;
            continue;
        }
        if ($invoiceStatus !== 'issued') {
            continue;
        }

        $invoiceStats['issued_count']++;
        $invoiceStats['issued_amount'] += $invoiceAmount;
        $invoiceStats['outstanding_amount'] += $invoiceAmount;

        $dueDate = saas_dt_db((string)($invoiceRow['due_date'] ?? ''));
        $daysOverdue = null;
        if ($dueDate !== null) {
            $dueTs = strtotime($dueDate);
            if ($dueTs !== false) {
                $daysOverdue = (int)floor((time() - $dueTs) / 86400);
            }
        }

        if ($daysOverdue === null || $daysOverdue < 1) {
            $invoiceAging['current']['count']++;
            $invoiceAging['current']['amount'] += $invoiceAmount;
            continue;
        }

        $invoiceStats['overdue_count']++;
        if ($daysOverdue <= 7) {
            $bucketKey = '1_7';
        } elseif ($daysOverdue <= 30) {
            $bucketKey = '8_30';
        } elseif ($daysOverdue <= 60) {
            $bucketKey = '31_60';
        } else {
            $bucketKey = '61_plus';
        }
        $invoiceAging[$bucketKey]['count']++;
        $invoiceAging[$bucketKey]['amount'] += $invoiceAmount;
    }
}

$billingCycles = app_billing_cycle_catalog();
$isEnglish = app_current_lang($conn) === 'en';
$paymentMethodCatalog = function_exists('saas_payment_method_catalog') ? saas_payment_method_catalog() : [
    'bank_transfer' => ['label_ar' => 'تحويل بنكي', 'label_en' => 'Bank transfer'],
    'instapay' => ['label_ar' => 'إنستاباي', 'label_en' => 'InstaPay'],
    'wallet' => ['label_ar' => 'محفظة إلكترونية', 'label_en' => 'Mobile wallet'],
    'cash' => ['label_ar' => 'نقدي', 'label_en' => 'Cash'],
    'card' => ['label_ar' => 'بطاقة', 'label_en' => 'Card'],
    'check' => ['label_ar' => 'شيك', 'label_en' => 'Check'],
    'gateway' => ['label_ar' => 'بوابة دفع', 'label_en' => 'Payment gateway'],
    'manual' => ['label_ar' => 'يدوي', 'label_en' => 'Manual'],
];

include 'header.php';
?>
<style>
    .saas-shell{display:grid;gap:18px}
    .saas-hero,.saas-card{background:linear-gradient(180deg,rgba(255,255,255,.05),rgba(255,255,255,.02)),rgba(18,18,18,.82);border:1px solid rgba(255,255,255,.08);border-radius:24px;box-shadow:0 16px 34px rgba(0,0,0,.22)}
    .saas-hero{padding:28px}
    .saas-title{margin:0;color:#f5e5a8;font-size:2rem}
    .saas-sub{margin:10px 0 0;color:#bfc3cb;line-height:1.9;max-width:820px}
    .saas-metrics{display:grid;grid-template-columns:repeat(9,minmax(0,1fr));gap:14px;margin-top:18px}
    .saas-stages{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:12px;margin-top:18px}
    .saas-stage{padding:16px;border-radius:18px;background:rgba(255,255,255,.035);border:1px solid rgba(255,255,255,.08)}
    .saas-stage .step{display:inline-flex;align-items:center;justify-content:center;width:30px;height:30px;border-radius:999px;background:rgba(212,175,55,.16);color:#f0d684;font-weight:800;margin-bottom:10px}
    .saas-stage .name{display:block;color:#fff;font-weight:800;margin-bottom:6px}
    .saas-stage .desc{display:block;color:#9ba1a9;line-height:1.7;font-size:.84rem}
    .saas-metric{padding:18px;border-radius:18px;background:rgba(255,255,255,.035);border:1px solid rgba(255,255,255,.08)}
    .saas-metric .label{font-size:.78rem;color:#9ba1a9}
    .saas-metric .value{margin-top:8px;font-size:1.6rem;color:#fff;font-weight:800}
    .saas-grid{display:grid;grid-template-columns:minmax(360px,430px) minmax(0,1fr);gap:18px;align-items:start}
    .saas-card{padding:22px}
    .saas-card h2{margin:0 0 12px;color:#f0d684;font-size:1.3rem}
    .saas-card p{margin:0 0 16px;color:#9ba1a9;line-height:1.8}
    .saas-form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}
    .saas-form-grid .full{grid-column:1/-1}
    .saas-card label{display:block;margin-bottom:8px;color:#bfc3cb;font-size:.9rem}
    .saas-card input,.saas-card select,.saas-card textarea{width:100%;box-sizing:border-box;border:1px solid rgba(255,255,255,.1);background:rgba(8,8,8,.84);color:#fff;border-radius:14px;padding:13px 14px;font-family:'Cairo',sans-serif}
    .saas-card textarea{min-height:84px;resize:vertical}
    .saas-btn{border:0;border-radius:14px;padding:12px 16px;font-family:'Cairo',sans-serif;font-weight:800;cursor:pointer}
    .saas-btn.primary{background:linear-gradient(135deg,#d4af37,#b8860b);color:#111}
    .saas-btn.dark{background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.1);color:#fff}
    .saas-flash{padding:14px 16px;border-radius:16px}
    .saas-flash.success{background:rgba(46, 204, 113, .12);border:1px solid rgba(46, 204, 113, .28);color:#a8edc0}
    .saas-flash.error{background:rgba(231, 76, 60, .12);border:1px solid rgba(231, 76, 60, .28);color:#ffb8af}
    .tenant-list{display:grid;gap:16px}
    .tenant-card{padding:22px;border-radius:22px;border:1px solid rgba(212,175,55,.14);background:linear-gradient(180deg,rgba(255,255,255,.045),rgba(255,255,255,.018)),rgba(10,10,10,.75)}
    .tenant-card.is-hidden{display:none}
    .tenant-head{display:flex;justify-content:space-between;gap:14px;align-items:flex-start;margin-bottom:14px}
    .tenant-title{margin:0;color:#fff;font-size:1.28rem}
    .tenant-meta{margin-top:8px;color:#9ca1ab;line-height:1.8}
    .badge{display:inline-flex;align-items:center;justify-content:center;padding:7px 12px;border-radius:999px;font-size:.78rem;font-weight:800}
    .badge.active{background:rgba(46,204,113,.14);border:1px solid rgba(46,204,113,.3);color:#9cebba}
    .badge.suspended{background:rgba(231,76,60,.14);border:1px solid rgba(231,76,60,.3);color:#ffb8af}
    .badge.provisioning{background:rgba(52,152,219,.14);border:1px solid rgba(52,152,219,.3);color:#a8d7ff}
    .badge.archived{background:rgba(149,165,166,.14);border:1px solid rgba(149,165,166,.3);color:#d6dcdd}
    .badge.trial{background:rgba(241,196,15,.14);border:1px solid rgba(241,196,15,.3);color:#ffe083}
    .badge.past_due{background:rgba(230,126,34,.14);border:1px solid rgba(230,126,34,.3);color:#ffc48c}
    .badge.ok{background:rgba(46,204,113,.14);border:1px solid rgba(46,204,113,.3);color:#9cebba}
    .badge.warning{background:rgba(241,196,15,.14);border:1px solid rgba(241,196,15,.3);color:#ffe083}
    .badge.critical{background:rgba(231,76,60,.14);border:1px solid rgba(231,76,60,.3);color:#ffb8af}
    .badge.paid{background:rgba(46,204,113,.14);border:1px solid rgba(46,204,113,.3);color:#9cebba}
    .badge.issued{background:rgba(52,152,219,.14);border:1px solid rgba(52,152,219,.3);color:#a8d7ff}
    .badge.draft{background:rgba(149,165,166,.14);border:1px solid rgba(149,165,166,.3);color:#d6dcdd}
    .badge.cancelled{background:rgba(127,140,141,.14);border:1px solid rgba(127,140,141,.3);color:#d0d6d7}
    .tenant-data{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin:16px 0}
    .tenant-data .item{padding:12px 14px;border-radius:16px;background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.06)}
    .tenant-data .k{display:block;font-size:.75rem;color:#8f96a1;margin-bottom:6px}
    .tenant-data .v{display:block;color:#fff;font-weight:700;word-break:break-word}
    .tenant-summary{display:flex;gap:8px;flex-wrap:wrap;margin:6px 0 14px}
    .tenant-kpi-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin:0 0 14px}
    .tenant-kpi{padding:12px 14px;border-radius:16px;background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.06)}
    .tenant-kpi .k{display:block;font-size:.74rem;color:#8f96a1;margin-bottom:6px}
    .tenant-kpi .v{display:block;color:#fff;font-weight:800}
    .tenant-chip{display:inline-flex;align-items:center;gap:6px;padding:7px 11px;border-radius:999px;background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.08);color:#dce2eb;font-size:.78rem;font-weight:700}
    .tenant-actions,.tenant-subgrid{display:grid;gap:12px}
    .tenant-actions{grid-template-columns:repeat(4,minmax(0,1fr));margin-bottom:12px}
    .tenant-subgrid{grid-template-columns:1.15fr 1.4fr 1fr}
    .mini-form{padding:16px;border-radius:18px;background:rgba(255,255,255,.025);border:1px solid rgba(255,255,255,.06)}
    .mini-form h3{margin:0 0 10px;color:#f0d684;font-size:1rem}
    .mini-form .stack{display:grid;gap:10px}
    .mini-list{display:grid;gap:8px}
    .mini-row{padding:10px 12px;border-radius:14px;background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.05);color:#d4d8dd}
    .mini-row small{display:block;color:#939aa5;margin-top:4px}
    .preset-row{display:flex;flex-wrap:wrap;gap:8px}
    .preset-row .saas-btn{padding:9px 12px}
    .mini-row.invoice-row{display:grid;gap:4px}
    .invoice-payment-form{display:grid;grid-template-columns:1fr 1fr 150px auto;gap:8px;margin-top:8px}
    .invoice-payment-form input,.invoice-payment-form select,.invoice-payment-form textarea{width:100%;box-sizing:border-box;border:1px solid rgba(255,255,255,.1);background:rgba(8,8,8,.84);color:#fff;border-radius:12px;padding:10px 12px;font-family:'Cairo',sans-serif}
    .invoice-payment-form textarea{grid-column:1/-1;min-height:68px;resize:vertical}
    .invoice-payment-form button{white-space:nowrap}
    .invoice-link-row{display:flex;gap:8px;flex-wrap:wrap;margin-top:8px}
    .invoice-link-row .saas-btn{padding:9px 12px;border-radius:12px}
    .gateway-card-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}
    .gateway-card-grid .full{grid-column:1/-1}
    .gateway-note{padding:12px 14px;border-radius:16px;background:rgba(255,255,255,.03);border:1px dashed rgba(255,255,255,.08);color:#9ca4af;line-height:1.8}
    .mini-row.domain-row{display:flex;align-items:flex-start;justify-content:space-between;gap:10px}
    .mini-row.domain-row form{margin:0}
    .subscription-manager{display:grid;gap:14px}
    .subscription-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px}
    .subscription-card{padding:14px;border-radius:18px;background:linear-gradient(180deg,rgba(255,255,255,.05),rgba(255,255,255,.02)),rgba(12,12,12,.78);border:1px solid rgba(212,175,55,.12)}
    .subscription-card.is-current{border-color:rgba(212,175,55,.42);box-shadow:0 0 0 1px rgba(212,175,55,.18) inset}
    .subscription-top{display:flex;justify-content:space-between;gap:10px;align-items:flex-start;margin-bottom:10px}
    .subscription-title{margin:0;color:#fff;font-size:1rem;font-weight:800}
    .subscription-current{display:inline-flex;align-items:center;gap:6px;padding:6px 10px;border-radius:999px;background:rgba(212,175,55,.12);color:#f0d684;font-size:.74rem;font-weight:800}
    .subscription-meta{display:grid;gap:8px;margin-bottom:12px}
    .subscription-meta .line{display:flex;justify-content:space-between;gap:10px;color:#d4d8dd;font-size:.88rem}
    .subscription-meta .line span:last-child{text-align:left;color:#fff;font-weight:700}
    .subscription-actions{display:grid;grid-template-columns:1fr 1fr;gap:8px}
    .subscription-actions .full-span{grid-column:1/-1}
    .saas-btn.warn{background:rgba(231,76,60,.12);border:1px solid rgba(231,76,60,.28);color:#ffb8af}
    .saas-btn.danger{background:linear-gradient(135deg,rgba(130,21,21,.9),rgba(92,12,12,.95));border:1px solid rgba(255,94,94,.22);color:#fff}
    .inline-note{padding:10px 12px;border-radius:14px;background:rgba(255,255,255,.03);border:1px dashed rgba(255,255,255,.08);color:#9ca4af;line-height:1.8;font-size:.88rem}
    .quick-subscription-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}
    .quick-subscription-grid .full{grid-column:1/-1}
    .saas-filter-row{display:grid;grid-template-columns:1fr 180px 180px;gap:10px;margin-bottom:14px}
    .saas-filter-row input,.saas-filter-row select{width:100%;box-sizing:border-box;border:1px solid rgba(255,255,255,.1);background:rgba(8,8,8,.84);color:#fff;border-radius:14px;padding:13px 14px;font-family:'Cairo',sans-serif}
    .saas-toolbar{display:flex;gap:10px;flex-wrap:wrap;margin-top:14px}
    .saas-smart-toolbar{display:flex;gap:10px;flex-wrap:wrap;margin-top:18px}
    .saas-smart-toolbar .saas-btn.is-active{background:linear-gradient(135deg,#d4af37,#b8860b);color:#111}
    .saas-admin-section.is-hidden{display:none}
    .policy-smart-toolbar{display:flex;gap:10px;flex-wrap:wrap;margin:0 0 16px}
    .policy-smart-toolbar .saas-btn.is-active{background:linear-gradient(135deg,#d4af37,#b8860b);color:#111}
    .policy-admin-section.is-hidden{display:none}
    .policy-manager-card.is-collapsed .policy-manager-body{display:none}
    .policy-toggle-btn{min-width:120px}
    .policy-summary{display:grid;gap:8px;margin-bottom:10px}
    .policy-summary small{color:#98a0ab;line-height:1.7}
    .tenant-toggle-btn{min-width:120px}
    .tenant-card.is-collapsed .tenant-data,
    .tenant-card.is-collapsed .tenant-actions,
    .tenant-card.is-collapsed .tenant-subgrid{display:none}
    .saas-report-grid{display:grid;grid-template-columns:minmax(0,1.15fr) minmax(0,.85fr);gap:18px}
    .saas-report-grid h2{margin-bottom:10px}
    .saas-report-grid p{margin-bottom:14px}
    .saas-invoice-metrics{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;margin-top:0}
    .saas-invoice-metrics .saas-metric{min-height:118px;display:flex;flex-direction:column;justify-content:center}
    .saas-invoice-metrics .saas-metric .label{line-height:1.8}
    .saas-aging-list{display:grid;gap:10px}
    .saas-aging-row{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:12px 14px;border-radius:16px;background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.06)}
    .saas-aging-row .meta{display:grid;gap:4px}
    .saas-aging-row .meta strong{color:#fff;font-size:.95rem}
    .saas-aging-row .meta small{color:#949ca8}
    .saas-aging-row .figures{text-align:left}
    .saas-aging-row .figures strong{display:block;color:#fff}
    .saas-aging-row .figures small{display:block;color:#9ba1a9;margin-top:4px}
    .saas-ops-list{display:grid;gap:10px}
    .saas-op-row{padding:12px 14px;border-radius:16px;background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.06);display:grid;gap:6px}
    .saas-op-row .top{display:flex;align-items:center;justify-content:space-between;gap:12px}
    .saas-op-row strong{color:#fff}
    .saas-op-row small,.saas-op-row .meta{color:#98a0ab;line-height:1.7}
    .saas-op-filters{display:grid;grid-template-columns:1.4fr 1fr 1fr 1fr auto;gap:10px;margin-bottom:14px}
    .saas-op-filters input,.saas-op-filters select{width:100%;box-sizing:border-box;border:1px solid rgba(255,255,255,.1);background:rgba(8,8,8,.84);color:#fff;border-radius:14px;padding:13px 14px;font-family:'Cairo',sans-serif}
    .ops-drawer.is-collapsed .ops-drawer-body{display:none}
    .ops-drawer-head{display:flex;justify-content:space-between;gap:12px;align-items:flex-start;margin-bottom:10px}
    .smart-admin-grid{display:grid;grid-template-columns:1.2fr .8fr;gap:12px;margin:0 0 14px}
    .smart-stage-nav{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px}
    .smart-stage-nav .saas-btn.is-active{background:linear-gradient(135deg,#d4af37,#b8860b);color:#111}
    .smart-stage-panel,.smart-tech-panel{display:none}
    .smart-stage-panel.is-active,.smart-tech-panel.is-active{display:block}
    .smart-stage-panel > .mini-form,.smart-tech-panel > .mini-form{margin-bottom:12px}
    .smart-stage-panel > .mini-form:last-child,.smart-tech-panel > .mini-form:last-child{margin-bottom:0}
    .smart-tech-head{display:grid;gap:10px;margin-bottom:12px}
    .smart-tech-head select{width:100%;box-sizing:border-box;border:1px solid rgba(255,255,255,.1);background:rgba(8,8,8,.84);color:#fff;border-radius:14px;padding:13px 14px;font-family:'Cairo',sans-serif}
    .smart-inline-actions{display:grid;gap:10px}
    .smart-inline-actions form,.smart-inline-actions a{margin:0}
    .smart-inline-actions a.saas-btn{text-decoration:none;display:inline-flex;align-items:center;justify-content:center}
    .tenant-card.has-smart-console .tenant-actions,
    .tenant-card.has-smart-console .tenant-subgrid{display:none}
    .smart-create-shell{display:grid;gap:12px}
    .smart-create-nav{display:flex;gap:8px;flex-wrap:wrap}
    .smart-create-nav .saas-btn.is-active{background:linear-gradient(135deg,#d4af37,#b8860b);color:#111}
    .smart-create-panel{display:none}
    .smart-create-panel.is-active{display:block}
    .smart-create-panel .saas-form-grid{margin-top:12px}
    .tenant-create-form.is-smartified > .saas-form-grid{display:none}
    @media (max-width: 1180px){.saas-grid{grid-template-columns:1fr}.tenant-data,.tenant-kpi-grid,.saas-stages,.saas-metrics{grid-template-columns:repeat(2,minmax(0,1fr))}}
    @media (max-width: 1180px){.saas-report-grid{grid-template-columns:1fr}}
    @media (max-width: 760px){.saas-metrics,.saas-form-grid,.tenant-data,.tenant-actions,.tenant-subgrid,.saas-stages,.quick-subscription-grid,.subscription-actions,.saas-filter-row,.invoice-payment-form,.saas-op-filters,.smart-admin-grid,.gateway-card-grid{grid-template-columns:1fr}.saas-invoice-metrics{grid-template-columns:repeat(2,minmax(0,1fr))}.tenant-head,.subscription-top,.saas-aging-row,.saas-op-row .top{flex-direction:column}.saas-aging-row .figures{text-align:right;width:100%}}
    @media (max-width: 560px){.saas-invoice-metrics{grid-template-columns:1fr}}
</style>

<div class="container">
    <div class="saas-shell">
        <section class="saas-hero">
            <h1 class="saas-title">مركز SaaS</h1>
            <p class="saas-sub">إدارة المستأجرين، ربط الدومينات، ومتابعة الاشتراكات من طبقة مركزية واحدة. هذا المسار يعمل فوق نواة `tenant-per-database` الجديدة.</p>
            <div class="saas-metrics">
                <div class="saas-metric"><div class="label">إجمالي المستأجرين</div><div class="value"><?php echo (int)$stats['total']; ?></div></div>
                <div class="saas-metric"><div class="label">المستأجرون النشطون</div><div class="value"><?php echo (int)$stats['active']; ?></div></div>
                <div class="saas-metric"><div class="label">المستأجرون الموقوفون</div><div class="value"><?php echo (int)$stats['suspended']; ?></div></div>
                <div class="saas-metric"><div class="label">الدومينات المرتبطة</div><div class="value"><?php echo (int)$stats['domains']; ?></div></div>
                <div class="saas-metric"><div class="label">الاشتراكات النشطة</div><div class="value"><?php echo (int)$stats['active_subscriptions']; ?></div></div>
                <div class="saas-metric"><div class="label">التجريبية / المتأخرة</div><div class="value"><?php echo (int)$stats['trial_subscriptions']; ?> / <?php echo (int)$stats['past_due_subscriptions']; ?></div></div>
                <div class="saas-metric"><div class="label">الصحة السليمة</div><div class="value"><?php echo (int)$stats['health_ok']; ?></div></div>
                <div class="saas-metric"><div class="label">إنذارات الصحة</div><div class="value"><?php echo (int)$stats['health_warning']; ?></div></div>
                <div class="saas-metric"><div class="label">مشكلات حرجة</div><div class="value"><?php echo (int)$stats['health_critical']; ?></div></div>
            </div>
            <div class="saas-stages">
                <div class="saas-stage"><span class="step">1</span><span class="name">إنشاء المستأجر</span><span class="desc">سجل اسم العميل وبيانات قاعدة التشغيل والرابط العام.</span></div>
                <div class="saas-stage"><span class="step">2</span><span class="name">ربط الدومين</span><span class="desc">حدد الدومين الأساسي الذي سيدخل منه العميل.</span></div>
                <div class="saas-stage"><span class="step">3</span><span class="name">إنشاء الاشتراك</span><span class="desc">اختر الباقة والدورة والمدة، وسيتم حساب التواريخ تلقائيًا.</span></div>
                <div class="saas-stage"><span class="step">4</span><span class="name">تهيئة التشغيل</span><span class="desc">جهز قاعدة العميل والجداول الأساسية وأنشئ المدير الأول.</span></div>
                <div class="saas-stage"><span class="step">5</span><span class="name">تسليم الدخول</span><span class="desc">سلّم الدومين وبيانات المدير الأول وابدأ تشغيل العميل.</span></div>
            </div>
            <div class="saas-toolbar">
                <form method="post" onsubmit="return confirm('سيتم إعادة احتساب كل اشتراكات المستأجرين وربطها بالحالات الحالية. هل تريد المتابعة؟');">
                    <?php echo app_csrf_input(); ?>
                    <input type="hidden" name="action" value="recalculate_all_subscriptions">
                    <button type="submit" class="saas-btn dark">إعادة احتساب كل الاشتراكات</button>
                </form>
                <form method="post" onsubmit="return confirm('سيتم إنشاء فواتير للاشتراكات المستحقة فقط مع منع التكرار لنفس الدورة. هل تريد المتابعة؟');">
                    <?php echo app_csrf_input(); ?>
                    <input type="hidden" name="action" value="generate_due_subscription_invoices">
                    <button type="submit" class="saas-btn primary">إنشاء فواتير الاشتراكات المستحقة</button>
                </form>
                <form method="post" onsubmit="return confirm('سيتم مراجعة كل فواتير الاشتراك غير المسددة وتحديث المتأخر والموقوف تلقائياً. هل تريد المتابعة؟');">
                    <?php echo app_csrf_input(); ?>
                    <input type="hidden" name="action" value="apply_overdue_policy_all">
                    <button type="submit" class="saas-btn dark">تطبيق سياسة التأخير</button>
                </form>
            </div>
            <div class="saas-smart-toolbar">
                <button type="button" class="saas-btn dark is-active" data-admin-filter="all">الكل</button>
                <button type="button" class="saas-btn dark" data-admin-filter="operations">التشغيل</button>
                <button type="button" class="saas-btn dark" data-admin-filter="policies">السياسات</button>
                <button type="button" class="saas-btn dark" data-admin-filter="create">الإنشاء</button>
                <button type="button" class="saas-btn dark" data-admin-filter="tenants">المستأجرون</button>
            </div>
        </section>

        <?php if (!empty($flash['message'])): ?>
            <div class="saas-flash <?php echo ($flash['type'] ?? '') === 'success' ? 'success' : 'error'; ?>">
                <?php echo app_h((string)$flash['message']); ?>
            </div>
        <?php endif; ?>

        <?php if ($isProductionOwnerRuntime): ?>
            <div class="saas-flash error">
                هذه النسخة تعمل كبيئة إنتاجية على `work`. الاستعادة وحذف قواعد البيانات محميان افتراضيًا هنا، بينما تبقى الأدوات التشغيلية غير المدمرة متاحة.
            </div>
        <?php endif; ?>

        <section class="saas-report-grid">
            <div class="saas-card">
                <h2>تقارير فواتير الاشتراك</h2>
                <p>ملخص تشغيلي سريع للفواتير الصادرة والمسددة والمتأخرة داخل مركز الـ SaaS، مع الاعتماد على نفس بيانات دورة الاشتراك الحالية.</p>
                <div class="saas-invoice-metrics">
                    <div class="saas-metric"><div class="label">إجمالي الفواتير</div><div class="value"><?php echo (int)$invoiceStats['total']; ?></div></div>
                    <div class="saas-metric"><div class="label">فواتير مفتوحة</div><div class="value"><?php echo (int)$invoiceStats['issued_count']; ?></div></div>
                    <div class="saas-metric"><div class="label">فواتير مسددة</div><div class="value"><?php echo (int)$invoiceStats['paid_count']; ?></div></div>
                    <div class="saas-metric"><div class="label">فواتير متأخرة</div><div class="value"><?php echo (int)$invoiceStats['overdue_count']; ?></div></div>
                    <div class="saas-metric"><div class="label">إجمالي قائم</div><div class="value"><?php echo number_format((float)$invoiceStats['outstanding_amount'], 2); ?></div></div>
                    <div class="saas-metric"><div class="label">إجمالي محصل</div><div class="value"><?php echo number_format((float)$invoiceStats['paid_amount'], 2); ?></div></div>
                </div>
            </div>

            <div class="saas-card">
                <h2>تقادم الفواتير</h2>
                <p>توزيع الفواتير غير المسددة حسب عمر التأخير لمتابعة التحصيل والإنذارات سريعًا.</p>
                <div class="saas-aging-list">
                    <?php foreach ($invoiceAging as $agingRow): ?>
                        <div class="saas-aging-row">
                            <div class="meta">
                                <strong><?php echo app_h((string)$agingRow['label']); ?></strong>
                                <small><?php echo (int)$agingRow['count']; ?> فاتورة</small>
                            </div>
                            <div class="figures">
                                <strong><?php echo number_format((float)$agingRow['amount'], 2); ?></strong>
                                <small>إجمالي القيمة</small>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

        </section>

        <section class="saas-card saas-admin-section ops-drawer <?php echo $opsDrawerOpen ? '' : 'is-collapsed'; ?>" data-admin-section="operations" id="ops-card">
            <div class="ops-drawer-head">
                <div>
                    <h2>آخر العمليات</h2>
                    <p>سجل العمليات مطوي افتراضيًا، ولا يجلب البيانات إلا عند الفتح أو التصفية أو التصدير.</p>
                </div>
                <?php if ($opsDrawerOpen): ?>
                    <a class="saas-btn dark" href="saas_center.php#ops-card">إخفاء السجل</a>
                <?php else: ?>
                    <a class="saas-btn dark" href="saas_center.php?ops_open=1#ops-card">إظهار السجل</a>
                <?php endif; ?>
            </div>
            <div class="ops-drawer-body">
                <form method="get" class="saas-op-filters">
                    <input type="hidden" name="ops_open" value="1">
                    <input type="text" name="ops_search" value="<?php echo app_h($opsSearch); ?>" placeholder="بحث في العملية أو المستأجر أو السياق">
                    <select name="ops_action">
                        <option value="">كل العمليات</option>
                        <?php foreach ($opsActionOptions as $actionCode => $actionLabel): ?>
                            <option value="<?php echo app_h($actionCode); ?>" <?php echo $opsActionFilter === $actionCode ? 'selected' : ''; ?>>
                                <?php echo app_h($actionLabel); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <select name="ops_tenant_id">
                        <option value="0">كل المستأجرين</option>
                        <?php foreach ($tenantRows as $tenant): ?>
                            <option value="<?php echo (int)($tenant['id'] ?? 0); ?>" <?php echo $opsTenantFilter === (int)($tenant['id'] ?? 0) ? 'selected' : ''; ?>>
                                <?php echo app_h((string)($tenant['tenant_name'] ?? $tenant['tenant_slug'] ?? 'Tenant')); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <select name="ops_actor">
                        <option value="">كل المنفذين</option>
                        <?php foreach ($opsActorOptions as $actorKey => $actorLabel): ?>
                            <option value="<?php echo app_h($actorKey); ?>" <?php echo $opsActorFilter === $actorKey ? 'selected' : ''; ?>>
                                <?php echo app_h($actorLabel); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div style="display:flex;gap:10px;flex-wrap:wrap">
                        <button type="submit" class="saas-btn dark">تصفية</button>
                        <a class="saas-btn primary" href="saas_center.php?<?php echo app_h(http_build_query([
                            'ops_open' => 1,
                            'ops_search' => $opsSearch,
                            'ops_action' => $opsActionFilter,
                            'ops_tenant_id' => $opsTenantFilter,
                            'ops_actor' => $opsActorFilter,
                            'ops_keep_latest' => $opsKeepLatest,
                            'ops_older_than_days' => $opsOlderThanDays,
                            'export_ops' => 1,
                        ])); ?>">CSV</a>
                    </div>
                </form>
                <form method="post" class="saas-op-filters" onsubmit="return confirm('سيتم حذف السجلات الأقدم من المدة المحددة مع الاحتفاظ بآخر عدد مطلوب من العمليات. هل تريد المتابعة؟');">
                    <?php echo app_csrf_input(); ?>
                    <input type="hidden" name="action" value="cleanup_operation_log">
                    <input type="text" value="تنظيف سجل العمليات" readonly>
                    <input type="number" name="keep_latest_logs" min="100" value="<?php echo (int)$opsKeepLatest; ?>" placeholder="الاحتفاظ بآخر N سجل">
                    <input type="number" name="older_than_days" min="1" value="<?php echo (int)$opsOlderThanDays; ?>" placeholder="الأقدم من N يوم">
                    <div class="inline-note" style="min-height:auto;margin:0;">يُحذف فقط ما هو أقدم من المدة المحددة وخارج آخر عدد محفوظ.</div>
                    <div style="display:flex;gap:10px;flex-wrap:wrap">
                        <button type="submit" class="saas-btn dark">تنظيف السجل</button>
                    </div>
                </form>
                <div class="saas-ops-list">
                    <?php if (empty($recentSaasOps)): ?>
                        <div class="mini-row">لا توجد عمليات مطابقة للفلاتر الحالية.</div>
                    <?php else: ?>
                        <?php foreach ($recentSaasOps as $opRow): ?>
                            <?php
                                $tenantLabel = trim((string)($opRow['tenant_name'] ?? ''));
                                if ($tenantLabel === '') {
                                    $tenantLabel = trim((string)($opRow['tenant_slug'] ?? ''));
                                }
                                if ($tenantLabel === '') {
                                    $tenantLabel = 'النظام المركزي';
                                }
                            ?>
                            <div class="saas-op-row">
                                <div class="top">
                                    <strong><?php echo app_h((string)($opRow['action_label'] ?? 'عملية')); ?></strong>
                                    <span class="badge <?php echo (int)($opRow['tenant_id'] ?? 0) > 0 ? 'active' : 'provisioning'; ?>">
                                        <?php echo app_h($tenantLabel); ?>
                                    </span>
                                </div>
                                <div class="meta">
                                    <?php echo app_h((string)($opRow['actor_name'] ?? 'System')); ?>
                                    <?php if (trim((string)($opRow['created_at'] ?? '')) !== ''): ?>
                                        | <?php echo app_h((string)$opRow['created_at']); ?>
                                    <?php endif; ?>
                                </div>
                                <?php if (trim((string)($opRow['context_json'] ?? '')) !== ''): ?>
                                    <small><?php echo app_h(saas_operation_context_preview((string)$opRow['context_json'])); ?></small>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </section>

        <section class="saas-card saas-admin-section ops-drawer <?php echo $webhookDrawerOpen ? '' : 'is-collapsed'; ?>" data-admin-section="operations" id="webhooks-card">
            <div class="ops-drawer-head">
                <div>
                    <h2>سجل Webhooks</h2>
                    <p>سجل مستقل لمحاولات التسليم، ويظهر الفاشل والمؤجل مع إمكانية إعادة المحاولة يدويًا.</p>
                </div>
                <?php if ($webhookDrawerOpen): ?>
                    <a class="saas-btn dark" href="saas_center.php#webhooks-card">إخفاء السجل</a>
                <?php else: ?>
                    <a class="saas-btn dark" href="saas_center.php?webhook_open=1#webhooks-card">إظهار السجل</a>
                <?php endif; ?>
            </div>
            <div class="ops-drawer-body">
                <form method="get" class="saas-op-filters">
                    <input type="hidden" name="webhook_open" value="1">
                    <select name="webhook_status">
                        <option value="">كل الحالات</option>
                        <?php foreach (['pending' => 'Pending', 'sent' => 'Sent', 'failed' => 'Failed'] as $statusKey => $statusLabel): ?>
                            <option value="<?php echo app_h($statusKey); ?>" <?php echo $webhookStatusFilter === $statusKey ? 'selected' : ''; ?>>
                                <?php echo app_h($statusLabel); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <select name="webhook_event">
                        <option value="">كل الأحداث</option>
                        <?php foreach ($webhookEventOptions as $eventCode => $eventLabel): ?>
                            <option value="<?php echo app_h($eventCode); ?>" <?php echo $webhookEventFilter === $eventCode ? 'selected' : ''; ?>>
                                <?php echo app_h($eventLabel); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <select name="webhook_tenant_id">
                        <option value="0">كل المستأجرين</option>
                        <?php foreach ($tenantRows as $tenant): ?>
                            <option value="<?php echo (int)($tenant['id'] ?? 0); ?>" <?php echo $webhookTenantFilter === (int)($tenant['id'] ?? 0) ? 'selected' : ''; ?>>
                                <?php echo app_h((string)($tenant['tenant_name'] ?? $tenant['tenant_slug'] ?? 'Tenant')); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div style="display:flex;gap:10px;flex-wrap:wrap">
                        <button type="submit" class="saas-btn dark">تصفية</button>
                    </div>
                </form>
                <div class="saas-ops-list">
                    <?php if (empty($recentWebhookDeliveries)): ?>
                        <div class="mini-row">لا توجد محاولات Webhook مطابقة للفلاتر الحالية.</div>
                    <?php else: ?>
                        <?php foreach ($recentWebhookDeliveries as $deliveryRow): ?>
                            <?php
                                $tenantLabel = trim((string)($deliveryRow['tenant_name'] ?? ''));
                                if ($tenantLabel === '') {
                                    $tenantLabel = trim((string)($deliveryRow['tenant_slug'] ?? ''));
                                }
                                if ($tenantLabel === '') {
                                    $tenantLabel = 'النظام المركزي';
                                }
                                $deliveryStatus = strtolower(trim((string)($deliveryRow['status'] ?? 'pending')));
                                $canRetryDelivery = $deliveryStatus === 'failed'
                                    && (int)($deliveryRow['attempt_count'] ?? 0) < (int)($deliveryRow['max_attempts'] ?? 5);
                            ?>
                            <div class="saas-op-row">
                                <div class="top">
                                    <strong><?php echo app_h((string)($deliveryRow['event_code'] ?? 'webhook')); ?></strong>
                                    <span class="badge <?php echo $deliveryStatus === 'sent' ? 'active' : ($deliveryStatus === 'failed' ? 'suspended' : 'provisioning'); ?>">
                                        <?php echo app_h(strtoupper($deliveryStatus)); ?>
                                    </span>
                                </div>
                                <div class="meta">
                                    <?php echo app_h($tenantLabel); ?>
                                    <?php if (trim((string)($deliveryRow['created_at'] ?? '')) !== ''): ?>
                                        | <?php echo app_h((string)$deliveryRow['created_at']); ?>
                                    <?php endif; ?>
                                </div>
                                <small>
                                    المحاولات: <?php echo (int)($deliveryRow['attempt_count'] ?? 0); ?> / <?php echo (int)($deliveryRow['max_attempts'] ?? 5); ?>
                                    | HTTP: <?php echo (int)($deliveryRow['http_code'] ?? 0); ?>
                                    <?php if (trim((string)($deliveryRow['next_retry_at'] ?? '')) !== ''): ?>
                                        | retry: <?php echo app_h((string)$deliveryRow['next_retry_at']); ?>
                                    <?php endif; ?>
                                </small>
                                <?php if (trim((string)($deliveryRow['last_error'] ?? '')) !== ''): ?>
                                    <small><?php echo app_h((string)($deliveryRow['last_error'] ?? '')); ?></small>
                                <?php endif; ?>
                                <?php if ($canRetryDelivery): ?>
                                    <form method="post" style="margin-top:10px;display:flex;gap:10px;flex-wrap:wrap;">
                                        <?php echo app_csrf_input(); ?>
                                        <input type="hidden" name="action" value="retry_webhook_delivery">
                                        <input type="hidden" name="delivery_id" value="<?php echo (int)($deliveryRow['id'] ?? 0); ?>">
                                        <button type="submit" class="saas-btn dark">إعادة المحاولة الآن</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </section>

        <section class="saas-card saas-admin-section ops-drawer <?php echo $webhookTestDrawerOpen ? '' : 'is-collapsed'; ?>" data-admin-section="operations" id="webhook-test-card">
            <div class="ops-drawer-head">
                <div>
                    <h2>Webhook Receiver Test Tool</h2>
                    <p>Endpoint تجريبي عام لحفظ أي webhook وارد داخل النظام، مناسب لاختبار التكاملات أو ربط الـ outbound webhooks على نفس المنصة.</p>
                </div>
                <?php if ($webhookTestDrawerOpen): ?>
                    <a class="saas-btn dark" href="saas_center.php#webhook-test-card">إخفاء الأداة</a>
                <?php else: ?>
                    <a class="saas-btn dark" href="saas_center.php?webhook_test_open=1#webhook-test-card">إظهار الأداة</a>
                <?php endif; ?>
            </div>
            <div class="ops-drawer-body">
                <div class="saas-op-row">
                    <div class="top">
                        <strong>Test Receiver URL</strong>
                        <span class="badge active">READY</span>
                    </div>
                    <small><?php echo app_h($webhookTestReceiverUrl); ?></small>
                </div>
                <div class="saas-ops-list">
                    <?php if (empty($recentWebhookTestInbox)): ?>
                        <div class="mini-row">لا توجد رسائل اختبار واردة حاليًا. افتح الأداة أو وجّه أي webhook إلى الرابط أعلاه لتظهر هنا.</div>
                    <?php else: ?>
                        <?php foreach ($recentWebhookTestInbox as $inboxRow): ?>
                            <?php
                                $payloadPreview = '';
                                if (trim((string)($inboxRow['payload_json'] ?? '')) !== '') {
                                    $payloadPreview = saas_operation_context_preview((string)$inboxRow['payload_json']);
                                } elseif (trim((string)($inboxRow['raw_body'] ?? '')) !== '') {
                                    $payloadPreview = mb_substr(trim((string)$inboxRow['raw_body']), 0, 220);
                                }
                            ?>
                            <div class="saas-op-row">
                                <div class="top">
                                    <strong><?php echo app_h((string)($inboxRow['request_method'] ?? 'POST')); ?></strong>
                                    <span class="badge provisioning"><?php echo app_h((string)($inboxRow['source_ip'] ?? '')); ?></span>
                                </div>
                                <div class="meta">
                                    #<?php echo (int)($inboxRow['id'] ?? 0); ?>
                                    <?php if (trim((string)($inboxRow['created_at'] ?? '')) !== ''): ?>
                                        | <?php echo app_h((string)$inboxRow['created_at']); ?>
                                    <?php endif; ?>
                                    <?php if (trim((string)($inboxRow['query_string'] ?? '')) !== ''): ?>
                                        | ?<?php echo app_h((string)$inboxRow['query_string']); ?>
                                    <?php endif; ?>
                                </div>
                                <?php if ($payloadPreview !== ''): ?>
                                    <small><?php echo app_h($payloadPreview); ?></small>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </section>

        <div class="saas-grid">
            <section class="saas-card saas-admin-section" data-admin-section="policies">
                <div class="policy-smart-toolbar">
                    <button type="button" class="saas-btn dark is-active" data-policy-filter="all">كل السياسات</button>
                    <button type="button" class="saas-btn dark" data-policy-filter="profiles">البروفايلات</button>
                    <button type="button" class="saas-btn dark" data-policy-filter="packs">الحزم</button>
                    <button type="button" class="saas-btn dark" data-policy-filter="exceptions">الاستثناءات</button>
                </div>
            </section>

            <section class="saas-card saas-admin-section policy-admin-section" data-admin-section="policies" data-policy-section="profiles">
                <h2><?php echo app_h(app_tr('بروفايلات التهيئة', 'Provision Profiles')); ?></h2>
                <p><?php echo app_h(app_tr('إدارة البروفايلات الجاهزة التي تُستخدم في إنشاء واستنساخ المستأجرين، مع إبقاء الافتراضيات الأساسية متاحة دائمًا.', 'Manage the ready-made profiles used in tenant creation and cloning while keeping the core defaults always available.')); ?></p>
                <div class="subscription-grid">
                    <?php foreach ($allProvisionProfiles as $profileRow): ?>
                        <?php
                            $profileKey = (string)($profileRow['profile_key'] ?? '');
                            $isSystemProfile = (int)($profileRow['is_system'] ?? 0) === 1;
                        ?>
                        <div class="subscription-card policy-manager-card is-collapsed">
                            <div class="subscription-top">
                                <h3 class="subscription-title"><?php echo app_h((string)($profileRow['label'] ?? $profileKey)); ?></h3>
                                <span class="badge <?php echo !empty($profileRow['is_active']) ? 'active' : 'archived'; ?>">
                                    <?php echo app_h(!empty($profileRow['is_active']) ? app_tr('نشط', 'Active') : app_tr('غير نشط', 'Inactive')); ?>
                                </span>
                            </div>
                            <div class="subscription-meta">
                                <div class="item"><span class="k"><?php echo app_h(app_tr('المفتاح', 'Key')); ?></span><span class="v"><?php echo app_h($profileKey); ?></span></div>
                                <div class="item"><span class="k"><?php echo app_h(app_tr('الخطة', 'Plan')); ?></span><span class="v"><?php echo app_h((string)($profileRow['plan_code'] ?? 'basic')); ?></span></div>
                                <div class="item"><span class="k"><?php echo app_h(app_tr('المنطقة الزمنية / اللغة', 'Timezone / Locale')); ?></span><span class="v"><?php echo app_h((string)($profileRow['timezone'] ?? 'Africa/Cairo')); ?> / <?php echo app_h((string)($profileRow['locale'] ?? 'ar')); ?></span></div>
                                <div class="item"><span class="k"><?php echo app_h(app_tr('المستخدمون / التخزين', 'Users / Storage')); ?></span><span class="v"><?php echo (int)($profileRow['users_limit'] ?? 0); ?> / <?php echo (int)($profileRow['storage_limit_mb'] ?? 0); ?> MB</span></div>
                            </div>
                            <div class="policy-summary">
                                <div class="mini-row">
                                    معاينة جماعية:
                                    <small>
                                        مرتبطون: <?php echo (int)($profileUsageSummary[$profileKey]['assigned'] ?? 0); ?>
                                        | منحرفون: <?php echo (int)($profileUsageSummary[$profileKey]['drifted'] ?? 0); ?>
                                    </small>
                                </div>
                                <?php if (!empty($profileUsageSummary[$profileKey]['samples'] ?? [])): ?>
                                    <small>أمثلة انحراف: <?php echo app_h(implode('، ', (array)$profileUsageSummary[$profileKey]['samples'])); ?></small>
                                <?php endif; ?>
                            </div>
                            <button type="button" class="saas-btn dark policy-toggle-btn" data-policy-card-toggle="1">إظهار التحرير</button>
                            <div class="policy-manager-body">
                                <form method="post" class="stack" style="margin-top:10px;">
                                    <?php echo app_csrf_input(); ?>
                                    <input type="hidden" name="action" value="save_provision_profile">
                                    <input type="text" name="profile_key" value="<?php echo app_h($profileKey); ?>" placeholder="<?php echo app_h(app_tr('مفتاح البروفايل', 'Profile key')); ?>" <?php echo $isSystemProfile ? 'readonly' : ''; ?>>
                                    <input type="text" name="label" value="<?php echo app_h((string)($profileRow['label'] ?? '')); ?>" placeholder="<?php echo app_h(app_tr('الاسم الظاهر', 'Label')); ?>">
                                    <input type="text" name="plan_code" value="<?php echo app_h((string)($profileRow['plan_code'] ?? 'basic')); ?>" placeholder="<?php echo app_h(app_tr('كود الخطة', 'Plan code')); ?>">
                                    <input type="text" name="timezone" value="<?php echo app_h((string)($profileRow['timezone'] ?? 'Africa/Cairo')); ?>" placeholder="<?php echo app_h(app_tr('المنطقة الزمنية', 'Timezone')); ?>">
                                    <select name="locale">
                                        <option value="ar" <?php echo ((string)($profileRow['locale'] ?? 'ar')) === 'ar' ? 'selected' : ''; ?>>ar</option>
                                        <option value="en" <?php echo ((string)($profileRow['locale'] ?? 'ar')) === 'en' ? 'selected' : ''; ?>>en</option>
                                    </select>
                                    <input type="number" name="users_limit" value="<?php echo (int)($profileRow['users_limit'] ?? 0); ?>" placeholder="<?php echo app_h(app_tr('حد المستخدمين', 'Users limit')); ?>">
                                    <input type="number" name="storage_limit_mb" value="<?php echo (int)($profileRow['storage_limit_mb'] ?? 0); ?>" placeholder="<?php echo app_h(app_tr('حد التخزين MB', 'Storage limit MB')); ?>">
                                    <input type="number" name="sort_order" value="<?php echo (int)($profileRow['sort_order'] ?? 0); ?>" placeholder="<?php echo app_h(app_tr('ترتيب العرض', 'Sort order')); ?>">
                                    <label><input type="checkbox" name="is_active" value="1" <?php echo !empty($profileRow['is_active']) ? 'checked' : ''; ?>> <?php echo app_h(app_tr('نشط', 'Active')); ?></label>
                                    <div style="display:flex;gap:8px;flex-wrap:wrap">
                                        <button type="submit" class="saas-btn primary">حفظ</button>
                                    </div>
                                </form>
                                <form method="post" style="margin-top:8px;" onsubmit="return confirm('سيتم إعادة تطبيق هذا الـ Provision Profile على كل المستأجرين المرتبطين به حاليًا. هل تريد المتابعة؟');">
                                    <?php echo app_csrf_input(); ?>
                                    <input type="hidden" name="action" value="bulk_reapply_provision_profile">
                                    <input type="hidden" name="profile_key" value="<?php echo app_h($profileKey); ?>">
                                    <button type="submit" class="saas-btn dark">إعادة تطبيق جماعي</button>
                                </form>
                                <?php if (!$isSystemProfile): ?>
                                    <form method="post" style="margin-top:8px;" onsubmit="return confirm('سيتم حذف هذا الـ Provision Profile. هل تريد المتابعة؟');">
                                        <?php echo app_csrf_input(); ?>
                                        <input type="hidden" name="action" value="delete_provision_profile">
                                        <input type="hidden" name="profile_key" value="<?php echo app_h($profileKey); ?>">
                                        <button type="submit" class="saas-btn dark">حذف</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    <div class="subscription-card policy-manager-card is-collapsed">
                        <div class="subscription-top">
                            <h3 class="subscription-title"><?php echo app_h(app_tr('بروفايل تهيئة جديد', 'New Provision Profile')); ?></h3>
                            <span class="badge provisioning"><?php echo app_h(app_tr('جديد', 'New')); ?></span>
                        </div>
                        <div class="policy-summary"><small>استخدمها فقط عند الحاجة لإضافة بروفايل جديد، مع إبقاء الشاشة اليومية أخف.</small></div>
                        <button type="button" class="saas-btn dark policy-toggle-btn" data-policy-card-toggle="1">إظهار التحرير</button>
                        <div class="policy-manager-body">
                            <form method="post" class="stack" style="margin-top:10px;">
                                <?php echo app_csrf_input(); ?>
                                <input type="hidden" name="action" value="save_provision_profile">
                                <input type="text" name="profile_key" placeholder="<?php echo app_h(app_tr('مفتاح البروفايل', 'Profile key')); ?>" required>
                                <input type="text" name="label" placeholder="<?php echo app_h(app_tr('الاسم الظاهر', 'Label')); ?>" required>
                                <input type="text" name="plan_code" value="basic" placeholder="<?php echo app_h(app_tr('كود الخطة', 'Plan code')); ?>">
                                <input type="text" name="timezone" value="Africa/Cairo" placeholder="<?php echo app_h(app_tr('المنطقة الزمنية', 'Timezone')); ?>">
                                <select name="locale"><option value="ar">ar</option><option value="en">en</option></select>
                                <input type="number" name="users_limit" value="0" placeholder="<?php echo app_h(app_tr('حد المستخدمين', 'Users limit')); ?>">
                                <input type="number" name="storage_limit_mb" value="0" placeholder="<?php echo app_h(app_tr('حد التخزين MB', 'Storage limit MB')); ?>">
                                <input type="number" name="sort_order" value="100" placeholder="<?php echo app_h(app_tr('ترتيب العرض', 'Sort order')); ?>">
                                <label><input type="checkbox" name="is_active" value="1" checked> <?php echo app_h(app_tr('نشط', 'Active')); ?></label>
                                <button type="submit" class="saas-btn primary">إضافة</button>
                            </form>
                        </div>
                    </div>
                </div>
            </section>

            <section class="saas-card saas-admin-section policy-admin-section" data-admin-section="policies" data-policy-section="packs">
                <h2><?php echo app_h(app_tr('حزم سياسات المستأجرين', 'Tenant Policy Packs')); ?></h2>
                <p><?php echo app_h(app_tr('حزم سياسات تشغيلية تضبط حالة المستأجر واللغة والمنطقة الزمنية وأيام التجربة والسماح وسياسة تنظيف سجل العمليات.', 'Operational policy packs that control tenant status, locale, timezone, trial and grace periods, and operations log retention rules.')); ?></p>
                <div class="subscription-grid">
                    <?php foreach ($allPolicyPacks as $packRow): ?>
                        <?php
                            $packKey = (string)($packRow['pack_key'] ?? '');
                            $isSystemPack = (int)($packRow['is_system'] ?? 0) === 1;
                        ?>
                        <div class="subscription-card policy-manager-card is-collapsed">
                            <div class="subscription-top">
                                <h3 class="subscription-title"><?php echo app_h((string)($packRow['label'] ?? $packKey)); ?></h3>
                                <span class="badge <?php echo !empty($packRow['is_active']) ? 'active' : 'archived'; ?>">
                                    <?php echo app_h(!empty($packRow['is_active']) ? app_tr('نشط', 'Active') : app_tr('غير نشط', 'Inactive')); ?>
                                </span>
                            </div>
                            <div class="subscription-meta">
                                <div class="item"><span class="k"><?php echo app_h(app_tr('المفتاح', 'Key')); ?></span><span class="v"><?php echo app_h($packKey); ?></span></div>
                                <div class="item"><span class="k"><?php echo app_h(app_tr('الحالة / اللغة', 'Status / Locale')); ?></span><span class="v"><?php echo app_h((string)($packRow['tenant_status'] ?? 'active')); ?> / <?php echo app_h((string)($packRow['locale'] ?? 'ar')); ?></span></div>
                                <div class="item"><span class="k"><?php echo app_h(app_tr('التجربة / السماح', 'Trial / Grace')); ?></span><span class="v"><?php echo (int)($packRow['trial_days'] ?? 14); ?> / <?php echo (int)($packRow['grace_days'] ?? 7); ?></span></div>
                                <div class="item"><span class="k"><?php echo app_h(app_tr('الاحتفاظ بسجل العمليات', 'Ops Retention')); ?></span><span class="v"><?php echo (int)($packRow['ops_keep_latest'] ?? 500); ?> / <?php echo (int)($packRow['ops_keep_days'] ?? 30); ?>d</span></div>
                            </div>
                            <div class="policy-summary">
                                <div class="mini-row">
                                    معاينة جماعية:
                                    <small>
                                        مرتبطون: <?php echo (int)($policyPackUsageSummary[$packKey]['assigned'] ?? 0); ?>
                                        | منحرفون: <?php echo (int)($policyPackUsageSummary[$packKey]['drifted'] ?? 0); ?>
                                    </small>
                                </div>
                                <?php if (!empty($policyPackUsageSummary[$packKey]['samples'] ?? [])): ?>
                                    <small>أمثلة انحراف: <?php echo app_h(implode('، ', (array)$policyPackUsageSummary[$packKey]['samples'])); ?></small>
                                <?php endif; ?>
                            </div>
                            <button type="button" class="saas-btn dark policy-toggle-btn" data-policy-card-toggle="1">إظهار التحرير</button>
                            <div class="policy-manager-body">
                                <form method="post" class="stack" style="margin-top:10px;">
                                    <?php echo app_csrf_input(); ?>
                                    <input type="hidden" name="action" value="save_policy_pack">
                                    <input type="text" name="pack_key" value="<?php echo app_h($packKey); ?>" placeholder="pack key" <?php echo $isSystemPack ? 'readonly' : ''; ?>>
                                    <input type="text" name="label" value="<?php echo app_h((string)($packRow['label'] ?? '')); ?>" placeholder="label">
                                    <select name="tenant_status">
                                        <?php foreach (['provisioning', 'active', 'suspended', 'archived'] as $statusCode): ?>
                                            <option value="<?php echo app_h($statusCode); ?>" <?php echo ((string)($packRow['tenant_status'] ?? 'active')) === $statusCode ? 'selected' : ''; ?>><?php echo app_h($statusCode); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <input type="text" name="timezone" value="<?php echo app_h((string)($packRow['timezone'] ?? 'Africa/Cairo')); ?>" placeholder="timezone">
                                    <select name="locale">
                                        <option value="ar" <?php echo ((string)($packRow['locale'] ?? 'ar')) === 'ar' ? 'selected' : ''; ?>>ar</option>
                                        <option value="en" <?php echo ((string)($packRow['locale'] ?? 'ar')) === 'en' ? 'selected' : ''; ?>>en</option>
                                    </select>
                                    <input type="number" name="trial_days" value="<?php echo (int)($packRow['trial_days'] ?? 14); ?>" placeholder="trial days">
                                    <input type="number" name="grace_days" value="<?php echo (int)($packRow['grace_days'] ?? 7); ?>" placeholder="grace days">
                                    <input type="number" name="ops_keep_latest" value="<?php echo (int)($packRow['ops_keep_latest'] ?? 500); ?>" placeholder="ops keep latest">
                                    <input type="number" name="ops_keep_days" value="<?php echo (int)($packRow['ops_keep_days'] ?? 30); ?>" placeholder="ops keep days">
                                    <input type="number" name="sort_order" value="<?php echo (int)($packRow['sort_order'] ?? 0); ?>" placeholder="sort order">
                                    <label><input type="checkbox" name="is_active" value="1" <?php echo !empty($packRow['is_active']) ? 'checked' : ''; ?>> active</label>
                                    <button type="submit" class="saas-btn primary">حفظ</button>
                                </form>
                                <form method="post" style="margin-top:8px;" onsubmit="return confirm('سيتم إعادة تطبيق هذا الـ Policy Pack على كل المستأجرين المرتبطين به حاليًا. هل تريد المتابعة؟');">
                                    <?php echo app_csrf_input(); ?>
                                    <input type="hidden" name="action" value="bulk_reapply_policy_pack">
                                    <input type="hidden" name="pack_key" value="<?php echo app_h($packKey); ?>">
                                    <button type="submit" class="saas-btn dark">إعادة تطبيق جماعي</button>
                                </form>
                                <?php if (!$isSystemPack): ?>
                                    <form method="post" style="margin-top:8px;" onsubmit="return confirm('سيتم حذف هذا الـ Policy Pack. هل تريد المتابعة؟');">
                                        <?php echo app_csrf_input(); ?>
                                        <input type="hidden" name="action" value="delete_policy_pack">
                                        <input type="hidden" name="pack_key" value="<?php echo app_h($packKey); ?>">
                                        <button type="submit" class="saas-btn dark">حذف</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    <div class="subscription-card policy-manager-card is-collapsed">
                        <div class="subscription-top">
                            <h3 class="subscription-title"><?php echo app_h(app_tr('حزمة سياسات جديدة', 'New Policy Pack')); ?></h3>
                            <span class="badge provisioning"><?php echo app_h(app_tr('جديد', 'New')); ?></span>
                        </div>
                        <div class="policy-summary"><small>أنشئ حزمة جديدة فقط عند وجود سياسة تشغيل مختلفة تستحق الفصل عن الحزم الحالية.</small></div>
                        <button type="button" class="saas-btn dark policy-toggle-btn" data-policy-card-toggle="1">إظهار التحرير</button>
                        <div class="policy-manager-body">
                            <form method="post" class="stack" style="margin-top:10px;">
                                <?php echo app_csrf_input(); ?>
                                <input type="hidden" name="action" value="save_policy_pack">
                                <input type="text" name="pack_key" placeholder="<?php echo app_h(app_tr('مفتاح الحزمة', 'Pack key')); ?>" required>
                                <input type="text" name="label" placeholder="<?php echo app_h(app_tr('الاسم الظاهر', 'Label')); ?>" required>
                                <select name="tenant_status">
                                    <option value="active">active</option>
                                    <option value="provisioning">provisioning</option>
                                    <option value="suspended">suspended</option>
                                    <option value="archived">archived</option>
                                </select>
                                <input type="text" name="timezone" value="Africa/Cairo" placeholder="<?php echo app_h(app_tr('المنطقة الزمنية', 'Timezone')); ?>">
                                <select name="locale"><option value="ar">ar</option><option value="en">en</option></select>
                                <input type="number" name="trial_days" value="14" placeholder="<?php echo app_h(app_tr('أيام التجربة', 'Trial days')); ?>">
                                <input type="number" name="grace_days" value="7" placeholder="<?php echo app_h(app_tr('أيام السماح', 'Grace days')); ?>">
                                <input type="number" name="ops_keep_latest" value="500" placeholder="<?php echo app_h(app_tr('عدد السجلات المحفوظة', 'Ops keep latest')); ?>">
                                <input type="number" name="ops_keep_days" value="30" placeholder="<?php echo app_h(app_tr('عمر السجلات بالأيام', 'Ops keep days')); ?>">
                                <input type="number" name="sort_order" value="100" placeholder="<?php echo app_h(app_tr('ترتيب العرض', 'Sort order')); ?>">
                                <label><input type="checkbox" name="is_active" value="1" checked> <?php echo app_h(app_tr('نشط', 'Active')); ?></label>
                                <button type="submit" class="saas-btn primary">إضافة</button>
                            </form>
                        </div>
                    </div>
                </div>
            </section>

            <section class="saas-card saas-admin-section policy-admin-section" data-admin-section="policies" data-policy-section="exceptions">
                <h2><?php echo app_h(app_tr('قوالب استثناءات السياسات', 'Policy Exception Presets')); ?></h2>
                <p><?php echo app_h(app_tr('قوالب سريعة للاستثناءات المحلية فوق الـ Policy Pack، مثل تمديد التجربة أو زيادة مهلة السماح أو إطالة الاحتفاظ بالسجل.', 'Quick presets for local exceptions on top of the policy pack, such as extending trial time, increasing grace days, or keeping operations logs longer.')); ?></p>
                <div class="subscription-grid">
                    <?php foreach ($allPolicyExceptionPresets as $presetRow): ?>
                        <?php
                            $presetKey = (string)($presetRow['preset_key'] ?? '');
                            $isSystemPreset = (int)($presetRow['is_system'] ?? 0) === 1;
                            $presetSummary = function_exists('app_saas_policy_override_summary')
                                ? app_saas_policy_override_summary(array_filter([
                                    'tenant_status' => $presetRow['tenant_status'] ?? null,
                                    'timezone' => $presetRow['timezone'] ?? null,
                                    'locale' => $presetRow['locale'] ?? null,
                                    'trial_days' => $presetRow['trial_days'] ?? null,
                                    'grace_days' => $presetRow['grace_days'] ?? null,
                                    'ops_keep_latest' => $presetRow['ops_keep_latest'] ?? null,
                                    'ops_keep_days' => $presetRow['ops_keep_days'] ?? null,
                                ], static fn($v) => $v !== null && $v !== ''))
                                : '';
                        ?>
                        <div class="subscription-card policy-manager-card is-collapsed">
                            <div class="subscription-top">
                                <h3 class="subscription-title"><?php echo app_h((string)($presetRow['label'] ?? $presetKey)); ?></h3>
                                <span class="badge <?php echo !empty($presetRow['is_active']) ? 'active' : 'archived'; ?>">
                                    <?php echo app_h(!empty($presetRow['is_active']) ? app_tr('نشط', 'Active') : app_tr('غير نشط', 'Inactive')); ?>
                                </span>
                            </div>
                            <div class="subscription-meta">
                                <div class="item"><span class="k"><?php echo app_h(app_tr('المفتاح', 'Key')); ?></span><span class="v"><?php echo app_h($presetKey); ?></span></div>
                                <div class="item"><span class="k"><?php echo app_h(app_tr('القالب', 'Preset')); ?></span><span class="v"><?php echo app_h($presetSummary !== '' ? $presetSummary : app_tr('لا توجد قيم معرفة', 'No defined values')); ?></span></div>
                            </div>
                            <div class="policy-summary">
                                <div class="mini-row">
                                    معاينة جماعية:
                                    <small>
                                        مرتبطون: <?php echo (int)($policyExceptionPresetUsageSummary[$presetKey]['assigned'] ?? 0); ?>
                                        | منحرفون: <?php echo (int)($policyExceptionPresetUsageSummary[$presetKey]['drifted'] ?? 0); ?>
                                    </small>
                                </div>
                                <?php if (!empty($policyExceptionPresetUsageSummary[$presetKey]['samples'] ?? [])): ?>
                                    <small>أمثلة انحراف: <?php echo app_h(implode('، ', (array)$policyExceptionPresetUsageSummary[$presetKey]['samples'])); ?></small>
                                <?php endif; ?>
                            </div>
                            <button type="button" class="saas-btn dark policy-toggle-btn" data-policy-card-toggle="1">إظهار التحرير</button>
                            <div class="policy-manager-body">
                                <form method="post" class="stack" style="margin-top:10px;">
                                    <?php echo app_csrf_input(); ?>
                                    <input type="hidden" name="action" value="save_policy_exception_preset">
                                    <input type="text" name="preset_key" value="<?php echo app_h($presetKey); ?>" placeholder="preset key" <?php echo $isSystemPreset ? 'readonly' : ''; ?>>
                                    <input type="text" name="label" value="<?php echo app_h((string)($presetRow['label'] ?? '')); ?>" placeholder="label">
                                    <select name="tenant_status">
                                        <option value="" <?php echo trim((string)($presetRow['tenant_status'] ?? '')) === '' ? 'selected' : ''; ?>>بدون استثناء حالة</option>
                                        <?php foreach (['provisioning', 'active', 'suspended', 'archived'] as $statusCode): ?>
                                            <option value="<?php echo app_h($statusCode); ?>" <?php echo ((string)($presetRow['tenant_status'] ?? '')) === $statusCode ? 'selected' : ''; ?>><?php echo app_h($statusCode); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <input type="text" name="timezone" value="<?php echo app_h((string)($presetRow['timezone'] ?? '')); ?>" placeholder="timezone">
                                    <select name="locale">
                                        <option value="" <?php echo trim((string)($presetRow['locale'] ?? '')) === '' ? 'selected' : ''; ?>>بدون استثناء لغة</option>
                                        <option value="ar" <?php echo ((string)($presetRow['locale'] ?? '')) === 'ar' ? 'selected' : ''; ?>>ar</option>
                                        <option value="en" <?php echo ((string)($presetRow['locale'] ?? '')) === 'en' ? 'selected' : ''; ?>>en</option>
                                    </select>
                                    <input type="number" name="trial_days" value="<?php echo app_h((string)($presetRow['trial_days'] ?? '')); ?>" placeholder="trial days">
                                    <input type="number" name="grace_days" value="<?php echo app_h((string)($presetRow['grace_days'] ?? '')); ?>" placeholder="grace days">
                                    <input type="number" name="ops_keep_latest" value="<?php echo app_h((string)($presetRow['ops_keep_latest'] ?? '')); ?>" placeholder="ops keep latest">
                                    <input type="number" name="ops_keep_days" value="<?php echo app_h((string)($presetRow['ops_keep_days'] ?? '')); ?>" placeholder="ops keep days">
                                    <input type="number" name="sort_order" value="<?php echo (int)($presetRow['sort_order'] ?? 0); ?>" placeholder="sort order">
                                    <label><input type="checkbox" name="is_active" value="1" <?php echo !empty($presetRow['is_active']) ? 'checked' : ''; ?>> active</label>
                                    <button type="submit" class="saas-btn primary">حفظ</button>
                                </form>
                                <form method="post" style="margin-top:8px;" onsubmit="return confirm('سيتم إعادة تطبيق هذا الـ Exception Preset على كل المستأجرين المرتبطين به حاليًا. هل تريد المتابعة؟');">
                                    <?php echo app_csrf_input(); ?>
                                    <input type="hidden" name="action" value="bulk_reapply_policy_exception_preset">
                                    <input type="hidden" name="preset_key" value="<?php echo app_h($presetKey); ?>">
                                    <button type="submit" class="saas-btn dark">إعادة تطبيق جماعي</button>
                                </form>
                                <?php if (!$isSystemPreset): ?>
                                    <form method="post" style="margin-top:8px;" onsubmit="return confirm('سيتم حذف هذا الـ Exception Preset. هل تريد المتابعة؟');">
                                        <?php echo app_csrf_input(); ?>
                                        <input type="hidden" name="action" value="delete_policy_exception_preset">
                                        <input type="hidden" name="preset_key" value="<?php echo app_h($presetKey); ?>">
                                        <button type="submit" class="saas-btn dark">حذف</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    <div class="subscription-card policy-manager-card is-collapsed">
                        <div class="subscription-top">
                            <h3 class="subscription-title"><?php echo app_h(app_tr('قالب استثناء جديد', 'New Exception Preset')); ?></h3>
                            <span class="badge provisioning"><?php echo app_h(app_tr('جديد', 'New')); ?></span>
                        </div>
                        <div class="policy-summary"><small>للاستثناءات الجاهزة السريعة فقط، بدون فتح النموذج الكامل كل مرة.</small></div>
                        <button type="button" class="saas-btn dark policy-toggle-btn" data-policy-card-toggle="1">إظهار التحرير</button>
                        <div class="policy-manager-body">
                            <form method="post" class="stack" style="margin-top:10px;">
                                <?php echo app_csrf_input(); ?>
                                <input type="hidden" name="action" value="save_policy_exception_preset">
                                <input type="text" name="preset_key" placeholder="<?php echo app_h(app_tr('مفتاح القالب', 'Preset key')); ?>" required>
                                <input type="text" name="label" placeholder="<?php echo app_h(app_tr('الاسم الظاهر', 'Label')); ?>" required>
                                <select name="tenant_status">
                                    <option value="">بدون استثناء حالة</option>
                                    <option value="active">active</option>
                                    <option value="provisioning">provisioning</option>
                                    <option value="suspended">suspended</option>
                                    <option value="archived">archived</option>
                                </select>
                                <input type="text" name="timezone" placeholder="<?php echo app_h(app_tr('المنطقة الزمنية', 'Timezone')); ?>">
                                <select name="locale"><option value="">بدون استثناء لغة</option><option value="ar">ar</option><option value="en">en</option></select>
                                <input type="number" name="trial_days" placeholder="<?php echo app_h(app_tr('أيام التجربة', 'Trial days')); ?>">
                                <input type="number" name="grace_days" placeholder="grace days">
                                <input type="number" name="ops_keep_latest" placeholder="ops keep latest">
                                <input type="number" name="ops_keep_days" placeholder="ops keep days">
                                <input type="number" name="sort_order" value="100" placeholder="sort order">
                                <label><input type="checkbox" name="is_active" value="1" checked> active</label>
                                <button type="submit" class="saas-btn primary">إضافة</button>
                            </form>
                        </div>
                    </div>
                </div>
            </section>

            <section class="saas-card saas-admin-section" data-admin-section="create">
                <h2>إضافة مستأجر</h2>
                <p>ابدأ بتسجيل بيانات العميل الأساسية فقط. بعد الحفظ ستكمل الدومين والاشتراك والتهيئة من بطاقة المستأجر نفسها.</p>
                <form method="post" class="tenant-create-form">
                    <?php echo app_csrf_input(); ?>
                    <input type="hidden" name="action" value="create_tenant">
                    <div class="saas-form-grid">
                        <?php if (!empty($tenantProvisionProfiles)): ?>
                            <div class="full">
                                <div class="mini-row">
                                    Profiles جاهزة
                                    <small>اختيار profile يضبط الخطة واللغة والمنطقة الزمنية والحدود الافتراضية بسرعة.</small>
                                    <div class="preset-row" style="margin-top:8px;">
                                        <?php foreach ($tenantProvisionProfiles as $profileMeta): ?>
                                            <?php $profileKey = (string)($profileMeta['profile_key'] ?? ''); ?>
                                            <button
                                                type="button"
                                                class="saas-btn dark"
                                                data-provision-profile="<?php echo app_h((string)$profileKey); ?>"
                                                data-target-form="create"
                                                data-plan-code="<?php echo app_h((string)($profileMeta['plan_code'] ?? 'basic')); ?>"
                                                data-timezone="<?php echo app_h((string)($profileMeta['timezone'] ?? 'Africa/Cairo')); ?>"
                                                data-locale="<?php echo app_h((string)($profileMeta['locale'] ?? 'ar')); ?>"
                                                data-users-limit="<?php echo (int)($profileMeta['users_limit'] ?? 0); ?>"
                                                data-storage-limit="<?php echo (int)($profileMeta['storage_limit_mb'] ?? 0); ?>"
                                            >
                                                <?php echo app_h((string)($profileMeta['label'] ?? $profileKey)); ?>
                                            </button>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($tenantPolicyPacks)): ?>
                            <div class="full">
                                <div class="mini-row">
                                    Policy Packs
                                    <small>تضبط سياسة المستأجر والاشتراكات الافتراضية وعمر سجل العمليات بسرعة.</small>
                                    <div class="preset-row" style="margin-top:8px;">
                                        <?php foreach ($tenantPolicyPacks as $packMeta): ?>
                                            <?php $packKey = (string)($packMeta['pack_key'] ?? ''); ?>
                                            <button
                                                type="button"
                                                class="saas-btn dark"
                                                data-policy-pack="<?php echo app_h((string)$packKey); ?>"
                                                data-target-form="create"
                                                data-tenant-status="<?php echo app_h((string)($packMeta['tenant_status'] ?? 'active')); ?>"
                                                data-timezone="<?php echo app_h((string)($packMeta['timezone'] ?? 'Africa/Cairo')); ?>"
                                                data-locale="<?php echo app_h((string)($packMeta['locale'] ?? 'ar')); ?>"
                                                data-trial-days="<?php echo (int)($packMeta['trial_days'] ?? 14); ?>"
                                                data-grace-days="<?php echo (int)($packMeta['grace_days'] ?? 7); ?>"
                                                data-ops-keep-latest="<?php echo (int)($packMeta['ops_keep_latest'] ?? 500); ?>"
                                                data-ops-keep-days="<?php echo (int)($packMeta['ops_keep_days'] ?? 30); ?>"
                                            >
                                                <?php echo app_h((string)($packMeta['label'] ?? $packKey)); ?>
                                            </button>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
                        <div><label>الاسم التشغيلي</label><input type="text" name="tenant_name" required></div>
                        <div><label>Slug</label><input type="text" name="tenant_slug" placeholder="acme" required></div>
                        <div><label>اسم النظام</label><input type="text" id="saasSystemNameInput" name="system_name" placeholder="Acme ERP" required></div>
                        <div><label>مجلد النظام</label><input type="text" id="saasSystemFolderInput" name="system_folder" placeholder="acme-erp"></div>
                        <div><label>الاسم القانوني</label><input type="text" name="legal_name"></div>
                        <div><label>الباقة</label><input type="text" name="plan_code" value="basic"></div>
                        <div><label>Provision Profile</label><input type="text" name="provision_profile" value="standard"></div>
                        <div><label>Policy Pack</label><input type="text" name="policy_pack" value="standard"></div>
                        <div><label>الحالة الأولية</label><select name="status"><option value="provisioning">Provisioning</option><option value="active">Active</option><option value="suspended">Suspended</option><option value="archived">Archived</option></select></div>
                        <div><label>البريد المالي</label><input type="email" name="billing_email"></div>
                        <div class="full"><label>الرابط العام</label><input type="url" name="app_url" placeholder="https://tenant.example.com"></div>
                        <div class="full"><label>الدومين الأساسي</label><input type="text" name="primary_domain" placeholder="tenant.example.com"></div>
                        <div><label>DB Host</label><input type="text" name="db_host" value="127.0.0.1"></div>
                        <div><label>DB Port</label><input type="number" name="db_port" value="3306"></div>
                        <div><label>DB Name</label><input type="text" name="db_name" required></div>
                        <div><label>DB User</label><input type="text" name="db_user" required></div>
                        <div><label>DB Password</label><input type="text" name="db_password"></div>
                        <div><label>DB Socket</label><input type="text" name="db_socket"></div>
                        <div><label>Timezone</label><input type="text" name="timezone" value="Africa/Cairo"></div>
                        <div><label>Locale</label><select name="locale"><option value="ar">ar</option><option value="en">en</option></select></div>
                        <div><label>حد المستخدمين</label><input type="number" name="users_limit" value="0"></div>
                        <div><label>حد التخزين MB</label><input type="number" name="storage_limit_mb" value="0"></div>
                        <div><label>الاحتفاظ بآخر السجلات</label><input type="number" name="ops_keep_latest" value="500"></div>
                        <div><label>حذف السجلات الأقدم من يوم</label><input type="number" name="ops_keep_days" value="30"></div>
                        <div><label>نهاية التجربة المبدئية</label><input type="datetime-local" name="trial_ends_at"></div>
                        <div><label>حد الاشتراك الحالي</label><input type="datetime-local" name="subscribed_until"></div>
                        <div class="full"><label>ملاحظات</label><textarea name="notes"></textarea></div>
                        <div class="full"><button type="submit" class="saas-btn primary">إنشاء المستأجر</button></div>
                    </div>
                </form>
            </section>

            <section class="saas-card saas-admin-section" data-admin-section="tenants">
                <h2>المستأجرون</h2>
                <p>عرض تشغيلي مركزي للحالة الحالية، الدومينات، والاشتراكات مع إجراءات فورية.</p>
                <div class="saas-filter-row">
                    <input type="text" id="saasTenantFilterText" placeholder="بحث بالاسم / الدومين / قاعدة التشغيل">
                    <select id="saasTenantFilterStatus">
                        <option value="all">كل الحالات</option>
                        <option value="active">نشط</option>
                        <option value="suspended">موقوف</option>
                        <option value="provisioning">قيد التهيئة</option>
                        <option value="archived">مؤرشف</option>
                    </select>
                    <select id="saasTenantFilterSubscription">
                        <option value="all">كل أوضاع الاشتراك</option>
                        <option value="active">اشتراك نشط</option>
                        <option value="trial">تجريبي</option>
                        <option value="past_due">متأخر</option>
                        <option value="suspended">موقوف</option>
                        <option value="none">بدون اشتراك</option>
                    </select>
                </div>
                <div class="tenant-list">
                    <?php if (empty($tenantRows)): ?>
                        <div class="tenant-card">لا توجد مستأجرات مسجلة بعد.</div>
                    <?php endif; ?>
                    <?php foreach ($tenantRows as $tenant): ?>
                        <?php
                            $tenantId = (int)$tenant['id'];
                            $tenantStatus = strtolower(trim((string)$tenant['status']));
                            $tenantSubscriptionStatus = strtolower(trim((string)($tenant['subscription_status'] ?? 'none')));
                            $tenantHealth = $tenantHealthById[$tenantId] ?? ['severity' => 'ok', 'issues' => [], 'db_ok' => false, 'runtime_ok' => false, 'runtime_path' => ''];
                            if ($tenantSubscriptionStatus === '') {
                                $tenantSubscriptionStatus = 'none';
                            }
                            $tenantSubscriptionsCount = count($subscriptionsByTenant[$tenantId] ?? []);
                            $tenantDomainsCount = count($domainsByTenant[$tenantId] ?? []);
                            $tenantLoginUrl = function_exists('app_saas_tenant_login_url') ? app_saas_tenant_login_url($tenant) : '';
                            $tenantPolicyOverrides = function_exists('app_saas_tenant_policy_overrides') ? app_saas_tenant_policy_overrides($tenant) : [];
                            $latestCloneReview = $latestCloneReviewByTenant[$tenantId] ?? null;
                            $latestCloneReviewSummary = $latestCloneReview ? saas_operation_context_preview((string)($latestCloneReview['context_json'] ?? '')) : '';
                            $latestCloneComparison = $latestCloneComparisonByTenant[$tenantId] ?? null;
                            $latestCloneComparisonSummary = $latestCloneComparison ? saas_operation_context_preview((string)($latestCloneComparison['context_json'] ?? '')) : '';
                            $latestBackup = $backupsByTenant[$tenantId][0] ?? null;
                            $latestExport = $exportsByTenant[$tenantId][0] ?? null;
                        ?>
                        <article
                            class="tenant-card is-collapsed"
                            data-tenant-card="1"
                            data-tenant-id="<?php echo $tenantId; ?>"
                            data-status="<?php echo app_h($tenantStatus); ?>"
                            data-subscription-status="<?php echo app_h($tenantSubscriptionStatus); ?>"
                            data-search="<?php echo app_h(strtolower(trim((string)($tenant['tenant_name'] ?? '') . ' ' . (string)($tenant['tenant_slug'] ?? '') . ' ' . (string)($tenant['system_name'] ?? '') . ' ' . (string)($tenant['system_folder'] ?? '') . ' ' . (string)($tenant['primary_domain'] ?? '') . ' ' . (string)($tenant['app_url'] ?? '') . ' ' . (string)($tenant['db_name'] ?? '')))); ?>"
                        >
                            <div class="tenant-head">
                                <div>
                                    <h3 class="tenant-title"><?php echo app_h((string)$tenant['tenant_name']); ?></h3>
                                    <div class="tenant-meta">
                                        <?php echo app_h((string)$tenant['tenant_slug']); ?>
                                        <?php if (trim((string)($tenant['system_name'] ?? '')) !== ''): ?>
                                            | <?php echo app_h((string)$tenant['system_name']); ?>
                                        <?php endif; ?>
                                        <?php if (trim((string)$tenant['legal_name']) !== ''): ?>
                                            | <?php echo app_h((string)$tenant['legal_name']); ?>
                                        <?php endif; ?>
                                        <br>
                                        <?php echo app_h((string)($tenant['primary_domain'] ?? '')); ?>
                                    </div>
                                </div>
                                <div style="display:flex;gap:8px;align-items:flex-start;flex-wrap:wrap;">
                                    <button type="button" class="saas-btn dark tenant-toggle-btn" data-tenant-toggle="1">إظهار الإدارة</button>
                                    <span class="badge <?php echo app_h($tenantStatus); ?>"><?php echo app_h((string)$tenant['status']); ?></span>
                                </div>
                            </div>

                            <div class="tenant-summary">
                                <span class="tenant-chip">النوع: <?php echo app_h(app_runtime_profile_label('saas_gateway')); ?></span>
                                <span class="tenant-chip">الدومينات: <?php echo $tenantDomainsCount; ?></span>
                                <span class="tenant-chip">الاشتراكات: <?php echo $tenantSubscriptionsCount; ?></span>
                                <span class="tenant-chip">الحالي: <?php echo app_h(app_status_label($tenantSubscriptionStatus)); ?></span>
                                <span class="tenant-chip">الخطة: <?php echo app_h((string)($tenant['subscription_plan'] ?? $tenant['plan_code'] ?? '-')); ?></span>
                                <span class="tenant-chip">Profile: <?php echo app_h((string)($tenant['provision_profile'] ?? 'standard')); ?></span>
                                <span class="tenant-chip">Policy: <?php echo app_h((string)($tenant['policy_pack'] ?? 'standard')); ?></span>
                                <span class="tenant-chip">Exception Preset: <?php echo app_h((string)($tenant['policy_exception_preset'] ?? '-')); ?></span>
                                <span class="tenant-chip">استثناءات: <?php echo count($tenantPolicyOverrides); ?></span>
                                <span class="tenant-chip">الحد الحالي: <?php echo app_h((string)($tenant['subscribed_until'] ?? '-')); ?></span>
                                <span class="badge <?php echo app_h((string)($tenantHealth['severity'] ?? 'ok')); ?>">
                                    <?php echo app_h(($tenantHealth['severity'] ?? 'ok') === 'critical' ? 'حرج' : (($tenantHealth['severity'] ?? 'ok') === 'warning' ? 'إنذار' : 'سليم')); ?>
                                </span>
                            </div>

                            <div class="tenant-kpi-grid">
                                <div class="tenant-kpi">
                                    <span class="k">جاهزية التشغيل</span>
                                    <span class="v"><?php echo !empty($tenantHealth['db_ok']) && !empty($tenantHealth['runtime_ok']) ? 'جاهز' : 'يحتاج مراجعة'; ?></span>
                                </div>
                                <div class="tenant-kpi">
                                    <span class="k">حالة الاشتراك</span>
                                    <span class="v"><?php echo app_h(app_status_label($tenantSubscriptionStatus)); ?></span>
                                </div>
                                <div class="tenant-kpi">
                                    <span class="k"><?php echo app_h(app_tr('آخر نسخة احتياطية', 'Last Backup')); ?></span>
                                    <span class="v"><?php echo app_h((string)($latestBackup['modified_at'] ?? 'لا يوجد')); ?></span>
                                </div>
                                <div class="tenant-kpi">
                                    <span class="k">استهلاك الحدود</span>
                                    <span class="v"><?php echo (int)($tenant['users_limit'] ?? 0); ?> / <?php echo (int)($tenant['storage_limit_mb'] ?? 0); ?>MB</span>
                                </div>
                            </div>

                            <div class="tenant-data">
                                <div class="item"><span class="k">الرابط العام</span><span class="v"><?php echo app_h((string)$tenant['app_url']); ?></span></div>
                                <div class="item"><span class="k"><?php echo app_h(app_tr('بوابة العميل', 'Customer billing portal')); ?></span><span class="v"><?php echo app_h((string)($tenant['billing_portal_url'] ?? '-')); ?></span></div>
                                <div class="item"><span class="k">مجلد النظام</span><span class="v"><?php echo app_h((string)($tenant['system_folder'] ?? '-')); ?></span></div>
                                <div class="item"><span class="k">قاعدة التشغيل</span><span class="v"><?php echo app_h((string)$tenant['db_name']); ?></span></div>
                                <div class="item"><span class="k">المستخدمون / التخزين</span><span class="v"><?php echo (int)($tenant['users_limit'] ?? 0); ?> / <?php echo (int)($tenant['storage_limit_mb'] ?? 0); ?> MB</span></div>
                                <div class="item"><span class="k"><?php echo app_h(app_tr('بروفايل التهيئة', 'Provision Profile')); ?></span><span class="v"><?php echo app_h((string)($tenant['provision_profile'] ?? 'standard')); ?></span></div>
                                <div class="item"><span class="k"><?php echo app_h(app_tr('حزمة السياسات', 'Policy Pack')); ?></span><span class="v"><?php echo app_h((string)($tenant['policy_pack'] ?? 'standard')); ?></span></div>
                                <div class="item"><span class="k"><?php echo app_h(app_tr('قالب الاستثناء', 'Exception Preset')); ?></span><span class="v"><?php echo app_h((string)($tenant['policy_exception_preset'] ?? '-')); ?></span></div>
                                <div class="item"><span class="k">الاشتراك المرتبط</span><span class="v"><?php echo app_h((string)($tenant['subscription_plan'] ?? '-')); ?> | <?php echo app_h(app_status_label((string)($tenant['subscription_status'] ?? '-'))); ?></span></div>
                                <div class="item"><span class="k">ينتهي / يتجدد حتى</span><span class="v"><?php echo app_h((string)($tenant['subscribed_until'] ?? $tenant['subscription_renews_at'] ?? '-')); ?></span></div>
                                <div class="item"><span class="k"><?php echo app_h(app_tr('الاحتفاظ بالسجل', 'Retention')); ?></span><span class="v"><?php echo (int)($tenant['ops_keep_latest'] ?? 500); ?> / <?php echo (int)($tenant['ops_keep_days'] ?? 30); ?> <?php echo app_h(app_tr('يوم', 'days')); ?></span></div>
                            </div>

                            <div class="mini-row" style="margin-bottom:14px;">
                                صحة التشغيل:
                                DB <?php echo !empty($tenantHealth['db_ok']) ? 'OK' : 'FAIL'; ?> |
                                Runtime <?php echo !empty($tenantHealth['runtime_ok']) ? 'OK' : 'FAIL'; ?>
                                <?php if (!empty($tenantHealth['issues'])): ?>
                                    <small><?php echo app_h(implode(' | ', (array)$tenantHealth['issues'])); ?></small>
                                <?php else: ?>
                                    <small>لا توجد مشكلات تشغيلية مكتشفة حاليًا.</small>
                                <?php endif; ?>
                            </div>

                            <?php if (!empty($tenantPolicyOverrides)): ?>
                                <div class="mini-row" style="margin-bottom:14px;">
                                    استثناءات السياسة:
                                    <small>
                                        <?php
                                            echo app_h(function_exists('app_saas_policy_override_summary') ? app_saas_policy_override_summary($tenantPolicyOverrides) : json_encode($tenantPolicyOverrides, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                                        ?>
                                    </small>
                                </div>
                            <?php endif; ?>

                            <?php if ($latestCloneReview): ?>
                                <div class="mini-row" style="margin-bottom:14px;">
                                    آخر مراجعة استنساخ:
                                    <?php echo app_h((string)($latestCloneReview['created_at'] ?? '-')); ?>
                                    <small>
                                        <?php echo app_h($latestCloneReviewSummary !== '' ? $latestCloneReviewSummary : (string)($latestCloneReview['action_label'] ?? 'مراجعة جاهزية نسخة مستنسخة')); ?>
                                    </small>
                                </div>
                            <?php endif; ?>

                            <?php if ($latestCloneComparison): ?>
                                <div class="mini-row" style="margin-bottom:14px;">
                                    مقارنة المصدر بالنسخة:
                                    <?php echo app_h((string)($latestCloneComparison['created_at'] ?? '-')); ?>
                                    <small>
                                        <?php echo app_h($latestCloneComparisonSummary !== '' ? $latestCloneComparisonSummary : (string)($latestCloneComparison['action_label'] ?? 'مقارنة المصدر بالنسخة المستنسخة')); ?>
                                    </small>
                                </div>
                            <?php endif; ?>

                            <?php if ($latestCloneReview || $latestCloneComparison): ?>
                                <div class="mini-row" style="margin-bottom:14px;">
                                    إجراءات سريعة بعد الاستنساخ
                                    <small>
                                        <?php if ($tenantLoginUrl !== ''): ?>
                                            <a href="<?php echo app_h($tenantLoginUrl); ?>" target="_blank" rel="noopener">فتح النسخة</a>
                                        <?php endif; ?>
                                        <?php if (!empty($tenant['billing_portal_url'])): ?>
                                            <?php if ($tenantLoginUrl !== ''): ?> | <?php endif; ?>
                                            <a href="<?php echo app_h((string)$tenant['billing_portal_url']); ?>" target="_blank" rel="noopener"><?php echo app_h(app_tr('بوابة العميل', 'Customer Portal')); ?></a>
                                        <?php endif; ?>
                                        <?php if ($latestBackup): ?>
                                            <?php if ($tenantLoginUrl !== '' || !empty($tenant['billing_portal_url'])): ?> | <?php endif; ?>
                                            <a href="<?php echo app_h((string)($latestBackup['url'] ?? '#')); ?>" target="_blank" rel="noopener"><?php echo app_h(app_tr('آخر نسخة احتياطية', 'Last Backup')); ?></a>
                                        <?php endif; ?>
                                        <?php if ($latestExport): ?>
                                            <?php if ($tenantLoginUrl !== '' || !empty($tenant['billing_portal_url']) || $latestBackup): ?> | <?php endif; ?>
                                            <a href="<?php echo app_h((string)($latestExport['url'] ?? '#')); ?>" target="_blank" rel="noopener"><?php echo app_h(app_tr('آخر تصدير', 'Last Export')); ?></a>
                                        <?php endif; ?>
                                        <?php if ($tenantLoginUrl !== '' || !empty($tenant['billing_portal_url']) || $latestBackup || $latestExport): ?> | <?php endif; ?>
                                        <a href="#recover-tenant-<?php echo $tenantId; ?>">استرجاع الدخول</a>
                                    </small>
                                </div>
                            <?php endif; ?>

                            <div class="tenant-actions">
                                <?php if ($tenantLoginUrl !== ''): ?>
                                    <a href="<?php echo app_h($tenantLoginUrl); ?>" target="_blank" rel="noopener" class="saas-btn dark" style="display:inline-flex;align-items:center;justify-content:center;text-decoration:none;">فتح النظام</a>
                                <?php endif; ?>
                                <?php if (!empty($tenant['billing_portal_url'])): ?>
                                    <a href="<?php echo app_h((string)$tenant['billing_portal_url']); ?>" target="_blank" rel="noopener" class="saas-btn dark" style="display:inline-flex;align-items:center;justify-content:center;text-decoration:none;"><?php echo app_h(app_tr('بوابة العميل', 'Customer Portal')); ?></a>
                                <?php endif; ?>
                                <?php if (!empty($tenantProvisionProfiles)): ?>
                                    <?php
                                        $currentProfileKey = strtolower(trim((string)($tenant['provision_profile'] ?? 'standard')));
                                        $currentProfileMeta = $provisionProfileMap[$currentProfileKey] ?? null;
                                        $initialProfileDiff = ($currentProfileMeta && function_exists('app_saas_provision_profile_diff'))
                                            ? app_saas_provision_profile_diff($tenant, $currentProfileMeta)
                                            : ['is_same' => true, 'changes' => []];
                                        $initialProfileDiffText = app_tr('لا توجد تغييرات. المستأجر مطابق للبروفايل الحالي.', 'No changes. The tenant already matches the current profile.');
                                        if (!empty($initialProfileDiff['changes'])) {
                                            $initialParts = [];
                                            foreach ((array)$initialProfileDiff['changes'] as $changeMeta) {
                                                $initialParts[] = (string)($changeMeta['label'] ?? '') . ': '
                                                    . (string)($changeMeta['current'] ?? '-') . ' -> '
                                                    . (string)($changeMeta['target'] ?? '-');
                                            }
                                            if (!empty($initialParts)) {
                                                $initialProfileDiffText = implode(' | ', $initialParts);
                                            }
                                        }
                                    ?>
                                    <form method="post">
                                        <?php echo app_csrf_input(); ?>
                                        <input type="hidden" name="action" value="apply_provision_profile">
                                        <input type="hidden" name="tenant_id" value="<?php echo $tenantId; ?>">
                                        <select name="profile_key" data-profile-select="1">
                                            <?php foreach ($tenantProvisionProfiles as $profileMeta): ?>
                                                <?php $profileKey = (string)($profileMeta['profile_key'] ?? ''); ?>
                                                <option value="<?php echo app_h($profileKey); ?>" <?php echo ((string)($tenant['provision_profile'] ?? 'standard')) === $profileKey ? 'selected' : ''; ?>>
                                                    <?php echo app_h((string)($profileMeta['label'] ?? $profileKey)); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <div
                                            class="mini-row"
                                            data-profile-diff-preview="1"
                                            data-current-plan="<?php echo app_h((string)($tenant['plan_code'] ?? 'basic')); ?>"
                                            data-current-timezone="<?php echo app_h((string)($tenant['timezone'] ?? 'Africa/Cairo')); ?>"
                                            data-current-locale="<?php echo app_h((string)($tenant['locale'] ?? 'ar')); ?>"
                                            data-current-users="<?php echo (int)($tenant['users_limit'] ?? 0); ?>"
                                            data-current-storage="<?php echo (int)($tenant['storage_limit_mb'] ?? 0); ?>"
                                            data-current-profile="<?php echo app_h((string)($tenant['provision_profile'] ?? 'standard')); ?>"
                                        >
                                            <small><?php echo app_h($initialProfileDiffText); ?></small>
                                        </div>
                                        <button type="submit" class="saas-btn dark"><?php echo app_h(app_tr('تطبيق البروفايل', 'Apply Profile')); ?></button>
                                    </form>
                                <?php endif; ?>
                                <?php if (!empty($tenantPolicyPacks)): ?>
                                    <?php
                                        $currentPackKey = strtolower(trim((string)($tenant['policy_pack'] ?? 'standard')));
                                        $currentPackMeta = $policyPackMap[$currentPackKey] ?? null;
                                        $currentPolicySubscription = $subscriptionsByTenant[$tenantId][0] ?? null;
                                        $initialPolicyDiff = ($currentPackMeta && function_exists('app_saas_policy_pack_diff'))
                                            ? app_saas_policy_pack_diff($tenant, is_array($currentPolicySubscription) ? $currentPolicySubscription : null, $currentPackMeta)
                                            : ['is_same' => true, 'changes' => []];
                                        $initialPolicyDiffText = app_tr('لا توجد تغييرات. المستأجر مطابق لحزمة السياسات الحالية.', 'No changes. The tenant already matches the current policy pack.');
                                        if (!empty($initialPolicyDiff['changes'])) {
                                            $initialPolicyParts = [];
                                            foreach ((array)$initialPolicyDiff['changes'] as $changeMeta) {
                                                $initialPolicyParts[] = (string)($changeMeta['label'] ?? '') . ': '
                                                    . (string)($changeMeta['current'] ?? '-') . ' -> '
                                                    . (string)($changeMeta['target'] ?? '-');
                                            }
                                            if (!empty($initialPolicyParts)) {
                                                $initialPolicyDiffText = implode(' | ', $initialPolicyParts);
                                            }
                                        }
                                        $effectivePolicySummary = '';
                                        if ($currentPackMeta && function_exists('app_saas_resolve_policy_pack_target')) {
                                            $effectivePolicyTarget = app_saas_resolve_policy_pack_target($tenant, is_array($currentPolicySubscription) ? $currentPolicySubscription : null, $currentPackMeta);
                                            $effectivePolicySummary = 'فعليًا: '
                                                . 'status=' . (string)($effectivePolicyTarget['tenant_status'] ?? '-')
                                                . ' | locale=' . (string)($effectivePolicyTarget['locale'] ?? '-')
                                                . ' | timezone=' . (string)($effectivePolicyTarget['timezone'] ?? '-')
                                                . ' | trial=' . (int)($effectivePolicyTarget['trial_days'] ?? 0)
                                                . ' | grace=' . (int)($effectivePolicyTarget['grace_days'] ?? 0)
                                                . ' | ops=' . (int)($effectivePolicyTarget['ops_keep_latest'] ?? 0)
                                                . '/' . (int)($effectivePolicyTarget['ops_keep_days'] ?? 0);
                                        }
                                    ?>
                                    <form method="post">
                                        <?php echo app_csrf_input(); ?>
                                        <input type="hidden" name="action" value="apply_policy_pack">
                                        <input type="hidden" name="tenant_id" value="<?php echo $tenantId; ?>">
                                        <select name="pack_key" data-policy-select="1">
                                            <?php foreach ($tenantPolicyPacks as $packMeta): ?>
                                                <?php $packKey = (string)($packMeta['pack_key'] ?? ''); ?>
                                                <option value="<?php echo app_h($packKey); ?>" <?php echo ((string)($tenant['policy_pack'] ?? 'standard')) === $packKey ? 'selected' : ''; ?>>
                                                    <?php echo app_h((string)($packMeta['label'] ?? $packKey)); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <div
                                            class="mini-row"
                                            data-policy-diff-preview="1"
                                            data-current-status="<?php echo app_h((string)($tenant['status'] ?? 'provisioning')); ?>"
                                            data-current-timezone="<?php echo app_h((string)($tenant['timezone'] ?? 'Africa/Cairo')); ?>"
                                            data-current-locale="<?php echo app_h((string)($tenant['locale'] ?? 'ar')); ?>"
                                            data-current-policy-pack="<?php echo app_h((string)($tenant['policy_pack'] ?? 'standard')); ?>"
                                            data-current-trial-days="<?php echo (int)(($subscriptionsByTenant[$tenantId][0]['trial_days'] ?? 14)); ?>"
                                            data-current-grace-days="<?php echo (int)(($subscriptionsByTenant[$tenantId][0]['grace_days'] ?? 7)); ?>"
                                            data-current-ops-keep-latest="<?php echo (int)($tenant['ops_keep_latest'] ?? 500); ?>"
                                            data-current-ops-keep-days="<?php echo (int)($tenant['ops_keep_days'] ?? 30); ?>"
                                        >
                                            <small><?php echo app_h($initialPolicyDiffText); ?></small>
                                        </div>
                                        <button type="submit" class="saas-btn dark"><?php echo app_h(app_tr('تطبيق السياسة', 'Apply Policy')); ?></button>
                                    </form>
                                <?php endif; ?>
                                <?php if (!empty($tenantPolicyExceptionPresets)): ?>
                                    <form method="post">
                                        <?php echo app_csrf_input(); ?>
                                        <input type="hidden" name="action" value="apply_policy_exception_preset">
                                        <input type="hidden" name="tenant_id" value="<?php echo $tenantId; ?>">
                                        <select name="preset_key">
                                            <?php foreach ($tenantPolicyExceptionPresets as $presetMeta): ?>
                                                <?php $presetKey = (string)($presetMeta['preset_key'] ?? ''); ?>
                                                <option value="<?php echo app_h($presetKey); ?>" <?php echo ((string)($tenant['policy_exception_preset'] ?? '')) === $presetKey ? 'selected' : ''; ?>>
                                                    <?php echo app_h((string)($presetMeta['label'] ?? $presetKey)); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button type="submit" class="saas-btn dark"><?php echo app_h(app_tr('تطبيق قالب الاستثناء', 'Apply Exception Preset')); ?></button>
                                    </form>
                                <?php endif; ?>
                                <form method="post">
                                    <?php echo app_csrf_input(); ?>
                                    <input type="hidden" name="action" value="save_policy_exceptions">
                                    <input type="hidden" name="tenant_id" value="<?php echo $tenantId; ?>">
                                    <input type="text" name="exception_tenant_status" value="<?php echo app_h((string)($tenantPolicyOverrides['tenant_status'] ?? '')); ?>" placeholder="استثناء الحالة">
                                    <input type="text" name="exception_timezone" value="<?php echo app_h((string)($tenantPolicyOverrides['timezone'] ?? '')); ?>" placeholder="استثناء Timezone">
                                    <select name="exception_locale">
                                        <option value="" <?php echo !isset($tenantPolicyOverrides['locale']) ? 'selected' : ''; ?>>بدون استثناء لغة</option>
                                        <option value="ar" <?php echo (($tenantPolicyOverrides['locale'] ?? '') === 'ar') ? 'selected' : ''; ?>>ar</option>
                                        <option value="en" <?php echo (($tenantPolicyOverrides['locale'] ?? '') === 'en') ? 'selected' : ''; ?>>en</option>
                                    </select>
                                    <input type="number" name="exception_trial_days" value="<?php echo app_h((string)($tenantPolicyOverrides['trial_days'] ?? '')); ?>" placeholder="استثناء أيام التجربة">
                                    <input type="number" name="exception_grace_days" value="<?php echo app_h((string)($tenantPolicyOverrides['grace_days'] ?? '')); ?>" placeholder="استثناء أيام السماح">
                                    <input type="number" name="exception_ops_keep_latest" value="<?php echo app_h((string)($tenantPolicyOverrides['ops_keep_latest'] ?? '')); ?>" placeholder="استثناء الاحتفاظ بآخر السجلات">
                                    <input type="number" name="exception_ops_keep_days" value="<?php echo app_h((string)($tenantPolicyOverrides['ops_keep_days'] ?? '')); ?>" placeholder="استثناء حذف السجلات الأقدم">
                                    <?php if ($effectivePolicySummary !== ''): ?>
                                        <div class="mini-row"><small><?php echo app_h($effectivePolicySummary); ?></small></div>
                                    <?php endif; ?>
                                    <button type="submit" class="saas-btn dark">حفظ الاستثناءات</button>
                                </form>
                                <?php if (!empty($tenantPolicyOverrides)): ?>
                                    <form method="post" onsubmit="return confirm('سيتم حذف كل استثناءات هذا المستأجر والرجوع إلى Policy Pack الأصلي. هل تريد المتابعة؟');">
                                        <?php echo app_csrf_input(); ?>
                                        <input type="hidden" name="action" value="clear_policy_exceptions">
                                        <input type="hidden" name="tenant_id" value="<?php echo $tenantId; ?>">
                                        <button type="submit" class="saas-btn warn">مسح الاستثناءات</button>
                                    </form>
                                <?php endif; ?>
                                <form method="post" onsubmit="return confirm('سيتم إنشاء نسخة احتياطية لقاعدة هذا المستأجر وملف manifest. هل تريد المتابعة؟');">
                                    <?php echo app_csrf_input(); ?>
                                    <input type="hidden" name="action" value="backup_tenant">
                                    <input type="hidden" name="tenant_id" value="<?php echo $tenantId; ?>">
                                    <button type="submit" class="saas-btn primary">نسخة احتياطية</button>
                                </form>
                                <form method="post" onsubmit="return confirm('سيتم إنشاء حزمة تصدير كاملة لهذا المستأجر مشتملة على نسخة احتياطية وManifest جاهز للنقل. هل تريد المتابعة؟');">
                                    <?php echo app_csrf_input(); ?>
                                    <input type="hidden" name="action" value="export_tenant_package">
                                    <input type="hidden" name="tenant_id" value="<?php echo $tenantId; ?>">
                                    <button type="submit" class="saas-btn dark">حزمة تصدير</button>
                                </form>
                                <?php foreach (['active' => 'تفعيل', 'suspended' => 'إيقاف', 'provisioning' => 'Provisioning'] as $statusCode => $label): ?>
                                    <form method="post">
                                        <?php echo app_csrf_input(); ?>
                                        <input type="hidden" name="action" value="set_tenant_status">
                                        <input type="hidden" name="tenant_id" value="<?php echo $tenantId; ?>">
                                        <input type="hidden" name="status" value="<?php echo app_h($statusCode); ?>">
                                        <button type="submit" class="saas-btn dark"><?php echo app_h($label); ?></button>
                                    </form>
                                <?php endforeach; ?>
                            </div>

                            <div class="tenant-subgrid">
                                <div class="mini-form">
                                    <h3>الدومينات</h3>
                                    <div class="mini-list" style="margin-bottom:12px;">
                                        <?php foreach (($domainsByTenant[$tenantId] ?? []) as $domainRow): ?>
                                            <div class="mini-row domain-row">
                                                <div>
                                                    <?php echo app_h((string)$domainRow['domain']); ?>
                                                    <small><?php echo (int)($domainRow['is_primary'] ?? 0) === 1 ? 'Primary' : 'Secondary'; ?></small>
                                                </div>
                                                <form method="post" onsubmit="return confirm('سيتم حذف هذا الدومين من هذا المستأجر. هل تريد المتابعة؟');">
                                                    <?php echo app_csrf_input(); ?>
                                                    <input type="hidden" name="action" value="delete_domain">
                                                    <input type="hidden" name="tenant_id" value="<?php echo $tenantId; ?>">
                                                    <input type="hidden" name="domain_id" value="<?php echo (int)($domainRow['id'] ?? 0); ?>">
                                                    <button type="submit" class="saas-btn warn">حذف</button>
                                                </form>
                                            </div>
                                        <?php endforeach; ?>
                                        <?php if (empty($domainsByTenant[$tenantId])): ?><div class="mini-row">لا توجد دومينات مرتبطة.</div><?php endif; ?>
                                    </div>
                                    <form method="post" class="stack">
                                        <?php echo app_csrf_input(); ?>
                                        <input type="hidden" name="action" value="add_domain">
                                        <input type="hidden" name="tenant_id" value="<?php echo $tenantId; ?>">
                                        <input type="text" name="domain" placeholder="tenant.example.com" required>
                                        <label><input type="checkbox" name="is_primary" value="1"> تعيينه كدومين أساسي</label>
                                        <button type="submit" class="saas-btn primary">حفظ الدومين</button>
                                    </form>
                                </div>

                                <div class="mini-form">
                                    <h3>الاشتراكات</h3>
                                    <div class="subscription-manager">
                                        <div class="inline-note">الإدارة أصبحت مباشرة من الكارت نفسه: تعيين كاشتراك حالي أو حذف، مع عرض واضح للحالة والقيمة والدورة.</div>
                                        <form method="post" style="margin:0;">
                                            <?php echo app_csrf_input(); ?>
                                            <input type="hidden" name="action" value="recalculate_tenant_subscriptions">
                                            <input type="hidden" name="tenant_id" value="<?php echo $tenantId; ?>">
                                            <button type="submit" class="saas-btn dark">إعادة احتساب اشتراكات هذا المستأجر</button>
                                        </form>
                                        <form method="post" style="margin:0;">
                                            <?php echo app_csrf_input(); ?>
                                            <input type="hidden" name="action" value="apply_overdue_policy_tenant">
                                            <input type="hidden" name="tenant_id" value="<?php echo $tenantId; ?>">
                                            <button type="submit" class="saas-btn dark">مراجعة التأخير لهذا المستأجر</button>
                                        </form>
                                        <div class="subscription-grid">
                                            <?php foreach (($subscriptionsByTenant[$tenantId] ?? []) as $subRow): ?>
                                                <?php
                                                    $subId = (int)($subRow['id'] ?? 0);
                                                    $isCurrentSubscription = (int)($tenant['current_subscription_id'] ?? 0) === $subId;
                                                    $subStatus = strtolower(trim((string)($subRow['status'] ?? 'trial')));
                                                ?>
                                                <div class="subscription-card <?php echo $isCurrentSubscription ? 'is-current' : ''; ?>">
                                                    <div class="subscription-top">
                                                        <div>
                                                            <div class="subscription-title"><?php echo app_h((string)($subRow['plan_code'] ?? 'subscription')); ?></div>
                                                            <span class="badge <?php echo app_h($subStatus); ?>"><?php echo app_h(app_status_label((string)($subRow['status'] ?? '-'))); ?></span>
                                                        </div>
                                                        <?php if ($isCurrentSubscription): ?>
                                                            <div class="subscription-current">الحالي</div>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div class="subscription-meta">
                                                        <div class="line"><span>القيمة</span><span><?php echo app_h((string)($subRow['currency_code'] ?? '')); ?> <?php echo app_h((string)($subRow['amount'] ?? '0')); ?></span></div>
                                                        <div class="line"><span>الدورة</span><span><?php echo app_h(app_billing_cycle_label((string)($subRow['billing_cycle'] ?? '-'))); ?></span></div>
                                                        <div class="line"><span>البداية</span><span><?php echo app_h((string)($subRow['starts_at'] ?? '-')); ?></span></div>
                                                        <div class="line"><span>عدد الدورات</span><span><?php echo (int)($subRow['cycles_count'] ?? 1); ?></span></div>
                                                        <div class="line"><span>أيام التجربة</span><span><?php echo (int)($subRow['trial_days'] ?? 14); ?></span></div>
                                                        <div class="line"><span>مهلة السماح</span><span><?php echo (int)($subRow['grace_days'] ?? 7); ?> يوم</span></div>
                                                        <div class="line"><span>التجديد</span><span><?php echo app_h((string)($subRow['renews_at'] ?? '-')); ?></span></div>
                                                        <div class="line"><span>النهاية</span><span><?php echo app_h((string)($subRow['ends_at'] ?? '-')); ?></span></div>
                                                    </div>
                                                    <div class="subscription-actions">
                                                        <?php if (!$isCurrentSubscription): ?>
                                                            <form method="post">
                                                                <?php echo app_csrf_input(); ?>
                                                                <input type="hidden" name="action" value="set_current_subscription">
                                                                <input type="hidden" name="tenant_id" value="<?php echo $tenantId; ?>">
                                                                <input type="hidden" name="subscription_id" value="<?php echo $subId; ?>">
                                                                <button type="submit" class="saas-btn dark">تعيين كحالي</button>
                                                            </form>
                                                        <?php else: ?>
                                                            <button type="button" class="saas-btn dark" disabled>الاشتراك الحالي</button>
                                                        <?php endif; ?>
                                                        <form method="post" onsubmit="return confirm('سيتم حذف هذا الاشتراك نهائياً. هل تريد المتابعة؟');">
                                                            <?php echo app_csrf_input(); ?>
                                                            <input type="hidden" name="action" value="delete_subscription">
                                                            <input type="hidden" name="tenant_id" value="<?php echo $tenantId; ?>">
                                                            <input type="hidden" name="subscription_id" value="<?php echo $subId; ?>">
                                                            <button type="submit" class="saas-btn warn">حذف</button>
                                                        </form>
                                                        <form method="post">
                                                            <?php echo app_csrf_input(); ?>
                                                            <input type="hidden" name="action" value="generate_subscription_invoice">
                                                            <input type="hidden" name="tenant_id" value="<?php echo $tenantId; ?>">
                                                            <input type="hidden" name="subscription_id" value="<?php echo $subId; ?>">
                                                            <button type="submit" class="saas-btn dark">إنشاء فاتورة</button>
                                                        </form>
                                                        <form method="post" class="full-span">
                                                            <?php echo app_csrf_input(); ?>
                                                            <input type="hidden" name="action" value="recalculate_subscription">
                                                            <input type="hidden" name="tenant_id" value="<?php echo $tenantId; ?>">
                                                            <input type="hidden" name="subscription_id" value="<?php echo $subId; ?>">
                                                            <button type="submit" class="saas-btn primary">إعادة احتساب الدورة</button>
                                                        </form>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                            <?php if (empty($subscriptionsByTenant[$tenantId])): ?>
                                                <div class="mini-row">لا توجد اشتراكات لهذا المستأجر حتى الآن.</div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <form method="post" class="stack" style="margin-top:14px;">
                                        <?php echo app_csrf_input(); ?>
                                        <input type="hidden" name="action" value="create_subscription">
                                        <input type="hidden" name="tenant_id" value="<?php echo $tenantId; ?>">
                                        <div class="quick-subscription-grid">
                                            <div>
                                                <label>الباقة</label>
                                                <select name="subscription_plan_code">
                                                    <?php $subPlan = (string)($tenant['plan_code'] ?? 'basic'); ?>
                                                    <option value="basic" <?php echo $subPlan === 'basic' ? 'selected' : ''; ?>>basic</option>
                                                    <option value="growth" <?php echo $subPlan === 'growth' ? 'selected' : ''; ?>>growth</option>
                                                    <option value="pro" <?php echo $subPlan === 'pro' ? 'selected' : ''; ?>>pro</option>
                                                    <option value="enterprise" <?php echo $subPlan === 'enterprise' ? 'selected' : ''; ?>>enterprise</option>
                                                    <option value="<?php echo app_h($subPlan); ?>" <?php echo !in_array($subPlan, ['basic','growth','pro','enterprise'], true) ? 'selected' : ''; ?>><?php echo app_h($subPlan); ?></option>
                                                </select>
                                            </div>
                                            <div>
                                                <label>الحالة</label>
                                                <select name="subscription_status">
                                                    <option value="trial">تجريبي</option>
                                                    <option value="active">نشط</option>
                                                    <option value="past_due">متأخر</option>
                                                    <option value="suspended">موقوف</option>
                                                    <option value="cancelled">ملغي</option>
                                                </select>
                                            </div>
                                            <div>
                                                <label>الدورة</label>
                                                <select name="billing_cycle">
                                                    <?php foreach ($billingCycles as $cycleCode => $cycleMeta): ?>
                                                        <option value="<?php echo app_h($cycleCode); ?>"><?php echo app_h(app_billing_cycle_label($cycleCode)); ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div><label>القيمة</label><input type="number" step="0.01" name="amount" placeholder="0.00"></div>
                                            <div><label>العملة</label><input type="text" name="currency_code" value="EGP"></div>
                                            <div><label>تاريخ البداية</label><input type="datetime-local" name="starts_at"></div>
                                            <div><label>عدد الدورات</label><input type="number" name="cycles_count" min="1" value="1"></div>
                                            <div><label>أيام التجربة</label><input type="number" name="trial_days" min="1" value="14"></div>
                                            <div><label>أيام السماح</label><input type="number" name="grace_days" min="0" value="7"></div>
                                            <div><label>مرجع اختياري</label><input type="text" name="external_ref" placeholder="invoice / contract"></div>
                                            <div class="full inline-note">المنطق المبسط:
                                            <small>إذا كان النوع تجريبيًا، يتم حساب النهاية تلقائيًا من عدد أيام التجربة. وإذا كان نشطًا، يتم حساب التجديد والنهاية تلقائيًا من الدورة وعدد الدورات، ثم تتحول الفاتورة غير المسددة إلى متأخرة وبعد مهلة السماح إلى إيقاف.</small>
                                            </div>
                                            <div class="full"><textarea name="subscription_notes" placeholder="ملاحظات الاشتراك"></textarea></div>
                                        </div>
                                        <button type="submit" class="saas-btn primary">حفظ الاشتراك</button>
                                    </form>
                                </div>

                                <div class="mini-form">
                                    <h3>فواتير الاشتراك</h3>
                                    <div class="mini-list">
                                        <?php foreach (array_slice(($subscriptionInvoicesByTenant[$tenantId] ?? []), 0, 6) as $invoiceRow): ?>
                                            <div class="mini-row invoice-row">
                                                <strong><?php echo app_h((string)($invoiceRow['invoice_number'] ?? 'SINV')); ?></strong>
                                                <?php $invoiceStatus = strtolower(trim((string)($invoiceRow['status'] ?? 'issued'))); ?>
                                                <?php $invoicePaymentRow = $subscriptionPaymentByInvoice[(int)($invoiceRow['id'] ?? 0)] ?? null; ?>
                                                <span>
                                                    <?php echo app_h((string)($invoiceRow['currency_code'] ?? '')); ?> <?php echo app_h((string)($invoiceRow['amount'] ?? '0')); ?>
                                                    |
                                                    <span class="badge <?php echo app_h($invoiceStatus); ?>"><?php echo app_h(app_status_label((string)($invoiceRow['status'] ?? 'issued'))); ?></span>
                                                </span>
                                                <small>الدورة: <?php echo app_h((string)($invoiceRow['period_start'] ?? '-')); ?> -> <?php echo app_h((string)($invoiceRow['period_end'] ?? '-')); ?></small>
                                                <small>الاستحقاق: <?php echo app_h((string)($invoiceRow['due_date'] ?? '-')); ?></small>
                                                <small><?php echo app_h(app_tr('البوابة', 'Gateway')); ?>: <?php echo app_h((string)($invoiceRow['gateway_provider'] ?? 'manual')); ?> | <?php echo app_h(app_tr('الحالة', 'Status')); ?>: <?php echo app_h((string)($invoiceRow['gateway_status'] ?? 'pending')); ?></small>
                                                <?php if (trim((string)($invoiceRow['gateway_public_url'] ?? '')) !== ''): ?>
                                                    <div class="invoice-link-row">
                                                        <a href="<?php echo app_h((string)$invoiceRow['gateway_public_url']); ?>" target="_blank" class="saas-btn dark"><?php echo app_h(app_tr('فتح رابط الدفع', 'Open payment link')); ?></a>
                                                        <button type="button" class="saas-btn dark" data-copy-text="<?php echo app_h((string)$invoiceRow['gateway_public_url']); ?>" onclick="copyGatewayText(this)"><?php echo app_h(app_tr('نسخ الرابط', 'Copy link')); ?></button>
                                                    </div>
                                                <?php endif; ?>
                                                <?php if (trim((string)($invoiceRow['paid_at'] ?? '')) !== ''): ?>
                                                    <small>تم السداد: <?php echo app_h((string)($invoiceRow['paid_at'] ?? '-')); ?><?php if (trim((string)($invoiceRow['payment_ref'] ?? '')) !== ''): ?> | المرجع: <?php echo app_h((string)($invoiceRow['payment_ref'] ?? '')); ?><?php endif; ?></small>
                                                    <?php if (is_array($invoicePaymentRow)): ?>
                                                        <small>الطريقة: <?php echo app_h(function_exists('saas_payment_method_label') ? saas_payment_method_label((string)($invoicePaymentRow['payment_method'] ?? 'manual'), $isEnglish) : (string)($invoicePaymentRow['payment_method'] ?? 'manual')); ?></small>
                                                        <?php if (trim((string)($invoicePaymentRow['notes'] ?? '')) !== ''): ?>
                                                            <small>ملاحظات السداد: <?php echo app_h((string)($invoicePaymentRow['notes'] ?? '')); ?></small>
                                                        <?php endif; ?>
                                                    <?php endif; ?>
                                                <?php endif; ?>
                                                <?php if ($invoiceStatus !== 'paid' && $invoiceStatus !== 'cancelled'): ?>
                                                    <form method="post" class="invoice-payment-form">
                                                        <?php echo app_csrf_input(); ?>
                                                        <input type="hidden" name="action" value="mark_subscription_invoice_paid">
                                                        <input type="hidden" name="tenant_id" value="<?php echo $tenantId; ?>">
                                                        <input type="hidden" name="invoice_id" value="<?php echo (int)($invoiceRow['id'] ?? 0); ?>">
                                                        <select name="payment_method">
                                                            <?php foreach ($paymentMethodCatalog as $methodCode => $methodRow): ?>
                                                                <option value="<?php echo app_h((string)$methodCode); ?>" <?php echo (string)$methodCode === 'manual' ? 'selected' : ''; ?>>
                                                                    <?php echo app_h((string)($isEnglish ? ($methodRow['label_en'] ?? $methodCode) : ($methodRow['label_ar'] ?? $methodCode))); ?>
                                                                </option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                        <input type="text" name="payment_ref" placeholder="مرجع السداد">
                                                        <input type="datetime-local" name="paid_at" value="<?php echo app_h(date('Y-m-d\\TH:i')); ?>">
                                                        <textarea name="payment_notes" placeholder="ملاحظات السداد"></textarea>
                                                        <button type="submit" class="saas-btn primary">تم السداد</button>
                                                    </form>
                                                <?php elseif ($invoiceStatus === 'paid'): ?>
                                                    <form method="post" style="margin-top:8px;">
                                                        <?php echo app_csrf_input(); ?>
                                                        <input type="hidden" name="action" value="reopen_subscription_invoice">
                                                        <input type="hidden" name="tenant_id" value="<?php echo $tenantId; ?>">
                                                        <input type="hidden" name="invoice_id" value="<?php echo (int)($invoiceRow['id'] ?? 0); ?>">
                                                        <button type="submit" class="saas-btn dark">إعادة إلى مستحقة</button>
                                                    </form>
                                                <?php endif; ?>
                                            </div>
                                        <?php endforeach; ?>
                                        <?php if (empty($subscriptionInvoicesByTenant[$tenantId])): ?>
                                            <div class="mini-row">لا توجد فواتير اشتراك لهذا المستأجر حتى الآن.</div>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <div class="mini-form">
                                    <h3>تهيئة التشغيل</h3>
                                    <div class="mini-row" style="margin-bottom:12px;">
                                        إنشاء قاعدة المستأجر والجداول الأساسية وإضافة المدير الأول.
                                    </div>
                                    <form method="post" class="stack">
                                        <?php echo app_csrf_input(); ?>
                                        <input type="hidden" name="action" value="provision_tenant">
                                        <input type="hidden" name="tenant_id" value="<?php echo $tenantId; ?>">
                                        <input type="text" name="admin_username" value="admin" placeholder="admin username">
                                        <input type="text" name="admin_full_name" value="<?php echo app_h((string)$tenant['tenant_name']); ?>" placeholder="admin full name">
                                        <input type="email" name="admin_email" value="<?php echo app_h((string)($tenant['billing_email'] ?? '')); ?>" placeholder="admin email">
                                        <input type="text" name="admin_password" placeholder="اتركه فارغًا لتوليد كلمة مرور">
                                        <button type="submit" class="saas-btn primary">تنفيذ التهيئة</button>
                                    </form>
                                </div>

                                <div class="mini-form" id="recover-tenant-<?php echo $tenantId; ?>">
                                    <h3>استرجاع الدخول</h3>
                                    <div class="inline-note">يعيد إنشاء أو تحديث حساب المدير داخل قاعدة المستأجر، ويصلح الوصول إذا فُقدت كلمة المرور أو تلف حساب `admin`.</div>
                                    <form method="post" class="stack" onsubmit="return confirm('سيتم تحديث بيانات دخول مدير المستأجر فورًا. هل تريد المتابعة؟');">
                                        <?php echo app_csrf_input(); ?>
                                        <input type="hidden" name="action" value="recover_tenant_access">
                                        <input type="hidden" name="tenant_id" value="<?php echo $tenantId; ?>">
                                        <input type="text" name="recover_username" value="admin" placeholder="admin username">
                                        <input type="text" name="recover_full_name" value="<?php echo app_h((string)$tenant['tenant_name']); ?>" placeholder="full name">
                                        <input type="email" name="recover_email" value="<?php echo app_h((string)($tenant['billing_email'] ?? '')); ?>" placeholder="admin email">
                                        <input type="text" name="recover_password" placeholder="اتركه فارغًا لتوليد كلمة مرور جديدة">
                                        <button type="submit" class="saas-btn primary">استرجاع الدخول</button>
                                    </form>
                                </div>

                                <div class="mini-form">
                                    <h3>استنساخ كمسودة</h3>
                                    <div class="inline-note">ينشئ مستأجرًا جديدًا من نفس القالب التشغيلي الحالي، لكن بقاعدة ومجلد نظام جديدين، بدون نسخ الدومينات أو الاشتراكات أو بيانات العميل نفسها.</div>
                                    <form method="post" class="stack clone-blueprint-form" onsubmit="return confirm('سيتم إنشاء مستأجر جديد كمسودة اعتمادًا على هذا المستأجر، مع الحاجة لاحقًا إلى التهيئة على قاعدة التشغيل الجديدة. هل تريد المتابعة؟');">
                                        <?php echo app_csrf_input(); ?>
                                        <input type="hidden" name="action" value="clone_tenant_blueprint">
                                        <input type="hidden" name="source_tenant_id" value="<?php echo $tenantId; ?>">
                                        <?php if (!empty($cloneTemplatePresets)): ?>
                                            <div class="mini-row">
                                                قوالب الاستنساخ السريعة
                                                <small>اختيار القالب يضبط التهيئة وخيارات نسخ البيانات التأسيسية تلقائيًا.</small>
                                                <div class="preset-row" style="margin-top:8px;">
                                                    <?php foreach ($cloneTemplatePresets as $presetKey => $presetMeta): ?>
                                                        <button
                                                            type="button"
                                                            class="saas-btn dark"
                                                            data-clone-preset="<?php echo app_h((string)$presetKey); ?>"
                                                            data-provision-now="<?php echo !empty($presetMeta['provision_now']) ? '1' : '0'; ?>"
                                                            data-seed-presets="<?php echo app_h(json_encode((array)($presetMeta['seed_presets'] ?? []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); ?>"
                                                        >
                                                            <?php echo app_h((string)($presetMeta['label'] ?? $presetKey)); ?>
                                                        </button>
                                                    <?php endforeach; ?>
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                        <?php if (!empty($tenantProvisionProfiles)): ?>
                                            <div class="mini-row">
                                                Provision Profiles
                                                <small>تضبط الخطة واللغة والمنطقة الزمنية والحدود الافتراضية للنسخة الجديدة.</small>
                                                <div class="preset-row" style="margin-top:8px;">
                                                    <?php foreach ($tenantProvisionProfiles as $profileMeta): ?>
                                                        <?php $profileKey = (string)($profileMeta['profile_key'] ?? ''); ?>
                                                        <button
                                                            type="button"
                                                            class="saas-btn dark"
                                                            data-provision-profile="<?php echo app_h((string)$profileKey); ?>"
                                                            data-target-form="clone"
                                                            data-plan-code="<?php echo app_h((string)($profileMeta['plan_code'] ?? 'basic')); ?>"
                                                            data-timezone="<?php echo app_h((string)($profileMeta['timezone'] ?? 'Africa/Cairo')); ?>"
                                                            data-locale="<?php echo app_h((string)($profileMeta['locale'] ?? 'ar')); ?>"
                                                            data-users-limit="<?php echo (int)($profileMeta['users_limit'] ?? 0); ?>"
                                                            data-storage-limit="<?php echo (int)($profileMeta['storage_limit_mb'] ?? 0); ?>"
                                                        >
                                                            <?php echo app_h((string)($profileMeta['label'] ?? $profileKey)); ?>
                                                        </button>
                                                    <?php endforeach; ?>
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                        <?php if (!empty($tenantPolicyPacks)): ?>
                                            <div class="mini-row">
                                                Policy Packs
                                                <small>تضبط سياسة المستأجر والاشتراكات والاحتفاظ بالسجل للنسخة الجديدة.</small>
                                                <div class="preset-row" style="margin-top:8px;">
                                                    <?php foreach ($tenantPolicyPacks as $packMeta): ?>
                                                        <?php $packKey = (string)($packMeta['pack_key'] ?? ''); ?>
                                                        <button
                                                            type="button"
                                                            class="saas-btn dark"
                                                            data-policy-pack="<?php echo app_h((string)$packKey); ?>"
                                                            data-target-form="clone"
                                                            data-tenant-status="<?php echo app_h((string)($packMeta['tenant_status'] ?? 'active')); ?>"
                                                            data-timezone="<?php echo app_h((string)($packMeta['timezone'] ?? 'Africa/Cairo')); ?>"
                                                            data-locale="<?php echo app_h((string)($packMeta['locale'] ?? 'ar')); ?>"
                                                            data-trial-days="<?php echo (int)($packMeta['trial_days'] ?? 14); ?>"
                                                            data-grace-days="<?php echo (int)($packMeta['grace_days'] ?? 7); ?>"
                                                            data-ops-keep-latest="<?php echo (int)($packMeta['ops_keep_latest'] ?? 500); ?>"
                                                            data-ops-keep-days="<?php echo (int)($packMeta['ops_keep_days'] ?? 30); ?>"
                                                        >
                                                            <?php echo app_h((string)($packMeta['label'] ?? $packKey)); ?>
                                                        </button>
                                                    <?php endforeach; ?>
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                        <input type="text" name="clone_tenant_name" value="<?php echo app_h((string)$tenant['tenant_name']); ?> Copy" placeholder="اسم المستأجر الجديد" required>
                                        <input type="text" name="clone_tenant_slug" value="<?php echo app_h((string)$tenant['tenant_slug']); ?>-copy" placeholder="slug جديد" required>
                                        <input type="text" name="clone_system_name" value="<?php echo app_h((string)($tenant['system_name'] ?: $tenant['tenant_name'])); ?> Copy" placeholder="اسم النظام الجديد" required>
                                        <input type="text" name="clone_system_folder" value="<?php echo app_h((string)($tenant['system_folder'] ?: $tenant['tenant_slug'])); ?>-copy" placeholder="مجلد النظام الجديد" required>
                                        <input type="email" name="clone_billing_email" value="<?php echo app_h((string)($tenant['billing_email'] ?? '')); ?>" placeholder="البريد المالي">
                                        <input type="text" name="clone_plan_code" value="<?php echo app_h((string)($tenant['plan_code'] ?? 'basic')); ?>" placeholder="الخطة">
                                        <input type="text" name="clone_provision_profile" value="<?php echo app_h((string)($tenant['provision_profile'] ?? 'standard')); ?>" placeholder="Provision Profile">
                                        <input type="text" name="clone_policy_pack" value="<?php echo app_h((string)($tenant['policy_pack'] ?? 'standard')); ?>" placeholder="Policy Pack">
                                        <input type="text" name="clone_db_host" value="<?php echo app_h((string)($tenant['db_host'] ?? 'localhost')); ?>" placeholder="DB Host" required>
                                        <input type="number" name="clone_db_port" value="<?php echo (int)($tenant['db_port'] ?? 3306); ?>" placeholder="DB Port" required>
                                        <input type="text" name="clone_db_name" placeholder="قاعدة التشغيل الجديدة" required>
                                        <input type="text" name="clone_db_user" placeholder="DB User الجديد" required>
                                        <input type="text" name="clone_db_password" placeholder="DB Password الجديد">
                                        <input type="text" name="clone_db_socket" value="<?php echo app_h((string)($tenant['db_socket'] ?? '')); ?>" placeholder="DB Socket">
                                        <input type="text" name="clone_timezone" value="<?php echo app_h((string)($tenant['timezone'] ?? 'Africa/Cairo')); ?>" placeholder="Timezone">
                                        <select name="clone_locale">
                                            <option value="ar" <?php echo ((string)($tenant['locale'] ?? 'ar')) === 'ar' ? 'selected' : ''; ?>>ar</option>
                                            <option value="en" <?php echo ((string)($tenant['locale'] ?? 'ar')) === 'en' ? 'selected' : ''; ?>>en</option>
                                        </select>
                                        <input type="number" name="clone_users_limit" value="<?php echo (int)($tenant['users_limit'] ?? 0); ?>" placeholder="حد المستخدمين">
                                        <input type="number" name="clone_storage_limit_mb" value="<?php echo (int)($tenant['storage_limit_mb'] ?? 0); ?>" placeholder="حد التخزين MB">
                                        <input type="number" name="clone_ops_keep_latest" value="<?php echo (int)($tenant['ops_keep_latest'] ?? 500); ?>" placeholder="الاحتفاظ بآخر السجلات">
                                        <input type="number" name="clone_ops_keep_days" value="<?php echo (int)($tenant['ops_keep_days'] ?? 30); ?>" placeholder="حذف السجلات الأقدم من يوم">
                                        <input type="url" name="clone_app_url" placeholder="اتركه فارغًا للتوليد التلقائي">
                                        <textarea name="clone_notes" placeholder="ملاحظات النسخة الجديدة"><?php echo app_h((string)($tenant['notes'] ?? '')); ?></textarea>
                                        <label><input type="checkbox" name="clone_provision_now" value="1"> تهيئة النسخة مباشرة بعد الإنشاء</label>
                                        <?php if (!empty($cloneSeedPresetsCatalog)): ?>
                                            <div class="inline-note">اختياري: بعد التهيئة الفورية يمكن نسخ بيانات تأسيسية فقط إلى النسخة الجديدة، بدون نقل الفواتير أو الاشتراكات أو الحركات التشغيلية.</div>
                                            <div class="mini-row">
                                                <?php foreach ($cloneSeedPresetsCatalog as $presetKey => $presetMeta): ?>
                                                    <label><input type="checkbox" name="clone_seed_presets[]" value="<?php echo app_h((string)$presetKey); ?>"> <?php echo app_h((string)($presetMeta['label'] ?? $presetKey)); ?></label>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endif; ?>
                                        <label><input type="checkbox" name="clone_copy_policy_overrides" value="1"> توريث استثناءات Policy Pack إلى النسخة الجديدة</label>
                                        <input type="text" name="clone_admin_username" value="admin" placeholder="admin username للنسخة الجديدة">
                                        <input type="text" name="clone_admin_full_name" value="<?php echo app_h((string)$tenant['tenant_name']); ?>" placeholder="اسم المدير الأول">
                                        <input type="email" name="clone_admin_email" value="<?php echo app_h((string)($tenant['billing_email'] ?? '')); ?>" placeholder="بريد المدير الأول">
                                        <input type="text" name="clone_admin_password" placeholder="اتركه فارغًا لتوليد كلمة مرور">
                                        <button type="submit" class="saas-btn dark" <?php echo $isProductionOwnerRuntime ? 'disabled' : ''; ?>>إنشاء نسخة مسودة</button>
                                        <?php if ($isProductionOwnerRuntime): ?>
                                            <div class="mini-row">
                                                الاستنساخ معطل على `work` لأنه إنتاجي.
                                                <small>نفذ هذه الخطوة من `sys` أو `plast` فقط.</small>
                                            </div>
                                        <?php endif; ?>
                                    </form>
                                </div>

                                <div class="mini-form">
                                    <h3>استعادة النظام</h3>
                                    <div class="inline-note">يتم إنشاء نسخة أمان تلقائية قبل الاستعادة، ثم تفريغ قاعدة المستأجر الحالية وإعادة استيراد `database.sql` من النسخة المختارة.</div>
                                    <form method="post" class="stack" onsubmit="return confirm('سيتم استبدال بيانات هذا المستأجر بالكامل بمحتوى النسخة الاحتياطية المختارة. سيُنشئ النظام نسخة أمان أولاً. هل تريد المتابعة؟');">
                                        <?php echo app_csrf_input(); ?>
                                        <input type="hidden" name="action" value="restore_tenant">
                                        <input type="hidden" name="tenant_id" value="<?php echo $tenantId; ?>">
                                        <select name="backup_filename" required>
                                            <option value="">اختر نسخة احتياطية</option>
                                            <?php foreach (($backupsByTenant[$tenantId] ?? []) as $backupRow): ?>
                                                <option value="<?php echo app_h((string)($backupRow['filename'] ?? '')); ?>">
                                                    <?php
                                                    $sizeKb = max(1, (int)ceil(((int)($backupRow['size'] ?? 0)) / 1024));
                                                    echo app_h((string)($backupRow['filename'] ?? '')) . ' | ' . app_h((string)($backupRow['modified_at'] ?? '-')) . ' | ' . $sizeKb . ' KB';
                                                    ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <?php if (!empty($backupsByTenant[$tenantId])): ?>
                                            <div class="mini-row">
                                                آخر نسخة متاحة: <?php echo app_h((string)(($backupsByTenant[$tenantId][0]['filename'] ?? '-'))); ?>
                                                <small>عدد النسخ المتاحة: <?php echo count((array)($backupsByTenant[$tenantId] ?? [])); ?></small>
                                            </div>
                                            <div class="mini-list">
                                                <?php foreach (array_slice((array)($backupsByTenant[$tenantId] ?? []), 0, 3) as $backupRow): ?>
                                                    <div class="mini-row">
                                                        <?php echo app_h((string)($backupRow['filename'] ?? '-')); ?>
                                                        <small>
                                                            <?php echo app_h((string)($backupRow['modified_at'] ?? '-')); ?>
                                                            |
                                                            <a href="<?php echo app_h((string)($backupRow['url'] ?? '#')); ?>" target="_blank" rel="noopener">تنزيل النسخة</a>
                                                        </small>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php else: ?>
                                            <div class="mini-row">
                                                لا توجد نسخ احتياطية متاحة لهذا المستأجر بعد.
                                                <small>أنشئ نسخة احتياطية أولًا حتى تتمكن من الاستعادة.</small>
                                            </div>
                                        <?php endif; ?>
                                        <?php if (!empty($exportsByTenant[$tenantId])): ?>
                                            <div class="mini-row">
                                                آخر حزمة تصدير: <?php echo app_h((string)(($exportsByTenant[$tenantId][0]['filename'] ?? '-'))); ?>
                                                <small>عدد الحزم المتاحة: <?php echo count((array)($exportsByTenant[$tenantId] ?? [])); ?></small>
                                            </div>
                                            <div class="mini-list">
                                                <?php foreach (array_slice((array)($exportsByTenant[$tenantId] ?? []), 0, 3) as $exportRow): ?>
                                                    <div class="mini-row">
                                                        <?php echo app_h((string)($exportRow['filename'] ?? '-')); ?>
                                                        <small>
                                                            <?php echo app_h((string)($exportRow['modified_at'] ?? '-')); ?>
                                                            |
                                                            <a href="<?php echo app_h((string)($exportRow['url'] ?? '#')); ?>" target="_blank" rel="noopener">تنزيل الحزمة</a>
                                                        </small>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endif; ?>
                                        <button type="submit" class="saas-btn dark" <?php echo ($isProductionOwnerRuntime || empty($backupsByTenant[$tenantId])) ? 'disabled' : ''; ?>>استعادة من النسخة المختارة</button>
                                        <?php if ($isProductionOwnerRuntime): ?>
                                            <div class="mini-row">
                                                الاستعادة معطلة هنا لأن هذه النسخة إنتاجية (`work`).
                                                <small>استخدم `sys` أو `plast` للتجارب، أو فعّل السماح الصريح بيئيًا عند الطوارئ فقط.</small>
                                            </div>
                                        <?php endif; ?>
                                    </form>
                                </div>

                                <div class="mini-form">
                                    <h3>حذف المستأجر</h3>
                                    <div class="inline-note">هذا الإجراء يحذف المستأجر نهائيًا من مركز SaaS. فعّل حذف قاعدة البيانات فقط إذا كانت هذه القاعدة مخصصة لهذا المستأجر وحده.</div>
                                    <form method="post" class="stack" onsubmit="return confirm('سيتم حذف المستأجر نهائيًا. هذا الإجراء لا يمكن التراجع عنه. هل تريد المتابعة؟');">
                                        <?php echo app_csrf_input(); ?>
                                        <input type="hidden" name="action" value="delete_tenant">
                                        <input type="hidden" name="tenant_id" value="<?php echo $tenantId; ?>">
                                        <div class="mini-row">
                                            قاعدة التشغيل الحالية: <?php echo app_h((string)$tenant['db_name']); ?>
                                            <small>حذف قاعدة البيانات سيحذف كل بيانات هذا العميل التشغيلية نهائيًا.</small>
                                        </div>
                                        <label><input type="checkbox" name="delete_database" value="1" <?php echo $isProductionOwnerRuntime ? 'disabled' : ''; ?>> حذف قاعدة بيانات المستأجر نهائيًا</label>
                                        <?php if ($isProductionOwnerRuntime): ?>
                                            <div class="mini-row">
                                                حذف قاعدة البيانات معطل على `work`.
                                                <small>يمكن حذف بطاقة المستأجر نفسها فقط عند الحاجة، لكن حذف البيانات التشغيلية محظور هنا افتراضيًا.</small>
                                            </div>
                                        <?php endif; ?>
                                        <button type="submit" class="saas-btn danger">حذف النظام المستأجر بالكامل</button>
                                    </form>
                                </div>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            </section>
        </div>
</div>
</div>
<script>
function copyGatewayText(button) {
    if (!button) return;
    const value = button.getAttribute('data-copy-text') || '';
    if (!value) return;
    if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
        navigator.clipboard.writeText(value);
        return;
    }
    const probe = document.createElement('textarea');
    probe.value = value;
    document.body.appendChild(probe);
    probe.select();
    try { document.execCommand('copy'); } catch (error) {}
    document.body.removeChild(probe);
}

const saasTenantFilterText = document.getElementById('saasTenantFilterText');
const saasTenantFilterStatus = document.getElementById('saasTenantFilterStatus');
const saasTenantFilterSubscription = document.getElementById('saasTenantFilterSubscription');
const saasSystemNameInput = document.getElementById('saasSystemNameInput');
const saasSystemFolderInput = document.getElementById('saasSystemFolderInput');
const adminFilterButtons = Array.from(document.querySelectorAll('[data-admin-filter]'));
const policyFilterButtons = Array.from(document.querySelectorAll('[data-policy-filter]'));
const STORAGE_KEYS = {
    admin: 'saas_center_admin_filter',
    policy: 'saas_center_policy_filter',
    opsOpen: 'saas_center_ops_open',
    createStep: 'saas_center_create_step',
    tenantStagePrefix: 'saas_center_tenant_stage_',
    tenantTechPrefix: 'saas_center_tenant_tech_',
};

function normalizeSystemFolder(value) {
    return String(value || '')
        .trim()
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '')
        .slice(0, 120);
}

function applySaasTenantFilter() {
    const q = (saasTenantFilterText && saasTenantFilterText.value ? saasTenantFilterText.value : '').trim().toLowerCase();
    const status = (saasTenantFilterStatus && saasTenantFilterStatus.value ? saasTenantFilterStatus.value : 'all').toLowerCase();
    const subscriptionStatus = (saasTenantFilterSubscription && saasTenantFilterSubscription.value ? saasTenantFilterSubscription.value : 'all').toLowerCase();

    document.querySelectorAll('[data-tenant-card="1"]').forEach((card) => {
        const haystack = (card.getAttribute('data-search') || '').toLowerCase();
        const cardStatus = (card.getAttribute('data-status') || '').toLowerCase();
        const cardSubscriptionStatus = (card.getAttribute('data-subscription-status') || '').toLowerCase();
        const matchesText = q === '' || haystack.indexOf(q) !== -1;
        const matchesStatus = status === 'all' || status === cardStatus;
        const matchesSubscription = subscriptionStatus === 'all' || subscriptionStatus === cardSubscriptionStatus;
        card.classList.toggle('is-hidden', !(matchesText && matchesStatus && matchesSubscription));
    });
}

if (saasTenantFilterText) saasTenantFilterText.addEventListener('input', applySaasTenantFilter);
if (saasTenantFilterStatus) saasTenantFilterStatus.addEventListener('change', applySaasTenantFilter);
if (saasTenantFilterSubscription) saasTenantFilterSubscription.addEventListener('change', applySaasTenantFilter);

function applyAdminSectionFilter(mode) {
    const normalizedMode = mode || 'all';
    try { localStorage.setItem(STORAGE_KEYS.admin, normalizedMode); } catch (error) {}
    adminFilterButtons.forEach((button) => {
        button.classList.toggle('is-active', (button.getAttribute('data-admin-filter') || 'all') === normalizedMode);
    });
    document.querySelectorAll('.saas-admin-section').forEach((section) => {
        const sectionMode = section.getAttribute('data-admin-section') || 'all';
        const visible = normalizedMode === 'all' || normalizedMode === sectionMode;
        section.classList.toggle('is-hidden', !visible);
    });
}

function applyPolicySectionFilter(mode) {
    const normalizedMode = mode || 'all';
    try { localStorage.setItem(STORAGE_KEYS.policy, normalizedMode); } catch (error) {}
    policyFilterButtons.forEach((button) => {
        button.classList.toggle('is-active', (button.getAttribute('data-policy-filter') || 'all') === normalizedMode);
    });
    document.querySelectorAll('.policy-admin-section').forEach((section) => {
        const sectionMode = section.getAttribute('data-policy-section') || 'all';
        const visible = normalizedMode === 'all' || normalizedMode === sectionMode;
        section.classList.toggle('is-hidden', !visible);
    });
}

adminFilterButtons.forEach((button) => {
    button.addEventListener('click', () => {
        applyAdminSectionFilter(button.getAttribute('data-admin-filter') || 'all');
    });
});

policyFilterButtons.forEach((button) => {
    button.addEventListener('click', () => {
        applyPolicySectionFilter(button.getAttribute('data-policy-filter') || 'all');
    });
});

document.querySelectorAll('[data-tenant-toggle="1"]').forEach((button) => {
    const card = button.closest('[data-tenant-card="1"]');
    if (!card) return;
    const syncButton = () => {
        button.textContent = card.classList.contains('is-collapsed') ? 'إظهار الإدارة' : 'إخفاء الإدارة';
    };
    syncButton();
    button.addEventListener('click', () => {
        card.classList.toggle('is-collapsed');
        syncButton();
    });
});

document.querySelectorAll('[data-policy-card-toggle="1"]').forEach((button) => {
    const card = button.closest('.policy-manager-card');
    if (!card) return;
    const syncButton = () => {
        button.textContent = card.classList.contains('is-collapsed') ? 'إظهار التحرير' : 'إخفاء التحرير';
    };
    syncButton();
    button.addEventListener('click', () => {
        card.classList.toggle('is-collapsed');
        syncButton();
    });
});

const rememberedAdminFilter = (() => { try { return localStorage.getItem(STORAGE_KEYS.admin) || 'all'; } catch (error) { return 'all'; } })();
const rememberedPolicyFilter = (() => { try { return localStorage.getItem(STORAGE_KEYS.policy) || 'all'; } catch (error) { return 'all'; } })();
applyAdminSectionFilter(rememberedAdminFilter);
applyPolicySectionFilter(rememberedPolicyFilter);

function createSmartConsoleForTenant(card) {
    if (!card || card.classList.contains('has-smart-console')) return;

    const actionsBox = card.querySelector('.tenant-actions');
    const subgridBox = card.querySelector('.tenant-subgrid');
    if (!actionsBox || !subgridBox) return;

    const findActionForm = (actionName) => actionsBox.querySelector(`form input[name="action"][value="${actionName}"]`)?.closest('form') || null;
    const subForms = Array.from(subgridBox.querySelectorAll(':scope > .mini-form'));
    const findSubForm = (title) => subForms.find((node) => {
        const h3 = node.querySelector('h3');
        return h3 && h3.textContent.trim() === title;
    }) || null;

    const smartGrid = document.createElement('div');
    smartGrid.className = 'smart-admin-grid';

    const stageCard = document.createElement('div');
    stageCard.className = 'mini-form';
    stageCard.innerHTML = '<h3>وحدة الإدارة المرحلية</h3><div class="inline-note">تنقّل بين مراحل إدارة المستأجر من بطاقة واحدة بدل فتح كل الأقسام معًا.</div><div class="smart-stage-nav"></div><div class="smart-stage-panels"></div>';
    const stageNav = stageCard.querySelector('.smart-stage-nav');
    const stagePanels = stageCard.querySelector('.smart-stage-panels');
    const tenantId = card.getAttribute('data-tenant-id') || '';

    const addStage = (key, label, nodes) => {
        const usableNodes = (nodes || []).filter(Boolean);
        if (!usableNodes.length) return;
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'saas-btn dark';
        button.textContent = label;
        button.setAttribute('data-stage-key', key);

        const panel = document.createElement('div');
        panel.className = 'smart-stage-panel';
        panel.setAttribute('data-stage-panel', key);
        usableNodes.forEach((node) => panel.appendChild(node));

        button.addEventListener('click', () => {
            stageNav.querySelectorAll('[data-stage-key]').forEach((item) => item.classList.toggle('is-active', item === button));
            stagePanels.querySelectorAll('[data-stage-panel]').forEach((item) => item.classList.toggle('is-active', item === panel));
            if (tenantId !== '') {
                try { localStorage.setItem(STORAGE_KEYS.tenantStagePrefix + tenantId, key); } catch (error) {}
            }
        });

        stageNav.appendChild(button);
        stagePanels.appendChild(panel);
    };

    const policyWrap = document.createElement('div');
    policyWrap.className = 'mini-form';
    policyWrap.innerHTML = '<h3>السياسات والإعدادات</h3><div class="inline-note">تطبيق البروفايلات والسياسات والاستثناءات من نقطة واحدة.</div><div class="smart-inline-actions"></div>';
    const policyActions = policyWrap.querySelector('.smart-inline-actions');
    [
        findActionForm('apply_provision_profile'),
        findActionForm('apply_policy_pack'),
        findActionForm('apply_policy_exception_preset'),
        findActionForm('save_policy_exceptions'),
        findActionForm('clear_policy_exceptions'),
    ].filter(Boolean).forEach((node) => policyActions.appendChild(node));

    const domainsForm = findSubForm('الدومينات');
    const subscriptionsForm = findSubForm('الاشتراكات');

    const setupWrap = document.createElement('div');
    setupWrap.className = 'mini-form';
    setupWrap.innerHTML = '<h3>التهيئة والوصول</h3><div class="inline-note">التهيئة الأولية واسترجاع مدير النظام عند الحاجة.</div>';
    [findSubForm('تهيئة التشغيل'), findSubForm('استرجاع الدخول')].filter(Boolean).forEach((node) => setupWrap.appendChild(node));

    addStage('policies', 'السياسات', [policyWrap]);
    addStage('domains', 'الدومينات', [domainsForm]);
    addStage('subscriptions', 'الاشتراكات', [subscriptionsForm]);
    addStage('setup', 'التهيئة', [setupWrap]);

    const rememberedStage = tenantId !== '' ? (() => { try { return localStorage.getItem(STORAGE_KEYS.tenantStagePrefix + tenantId) || ''; } catch (error) { return ''; } })() : '';
    const stageTarget = stageNav.querySelector(`[data-stage-key="${rememberedStage}"]`) || stageNav.querySelector('[data-stage-key]');
    if (stageTarget) {
        stageTarget.click();
    }

    const techCard = document.createElement('div');
    techCard.className = 'mini-form';
    techCard.innerHTML = '<h3>العمليات التقنية</h3><div class="inline-note">اختر العملية التقنية التي تريدها، وسيظهر كارتها فقط بدل عرض كل الأدوات الثقيلة معًا.</div><div class="smart-tech-head"><select data-tech-select="1"></select></div><div class="smart-tech-panels"></div>';
    const techSelect = techCard.querySelector('[data-tech-select="1"]');
    const techPanels = techCard.querySelector('.smart-tech-panels');

    const addTechPanel = (key, label, nodes) => {
        const usableNodes = (nodes || []).filter(Boolean);
        if (!usableNodes.length) return;
        const option = document.createElement('option');
        option.value = key;
        option.textContent = label;
        techSelect.appendChild(option);

        const panel = document.createElement('div');
        panel.className = 'smart-tech-panel';
        panel.setAttribute('data-tech-panel', key);
        usableNodes.forEach((node) => panel.appendChild(node));
        techPanels.appendChild(panel);
    };

    const accessWrap = document.createElement('div');
    accessWrap.className = 'mini-form';
    accessWrap.innerHTML = '<h3>الوصول والحالة</h3><div class="smart-inline-actions"></div>';
    const accessActions = accessWrap.querySelector('.smart-inline-actions');
    const loginLink = actionsBox.querySelector('a.saas-btn');
    if (loginLink) accessActions.appendChild(loginLink);
    Array.from(actionsBox.querySelectorAll('form')).forEach((form) => {
        const actionInput = form.querySelector('input[name="action"]');
        const actionValue = actionInput ? actionInput.value : '';
        if (actionValue === 'set_tenant_status') {
            accessActions.appendChild(form);
        }
    });

    const backupWrap = document.createElement('div');
    backupWrap.className = 'mini-form';
    backupWrap.innerHTML = '<h3>النسخ والتصدير</h3><div class="smart-inline-actions"></div>';
    const backupActions = backupWrap.querySelector('.smart-inline-actions');
    [findActionForm('backup_tenant'), findActionForm('export_tenant_package')].filter(Boolean).forEach((node) => backupActions.appendChild(node));

    addTechPanel('access', 'الوصول والحالة', [accessWrap]);
    addTechPanel('backup', 'النسخ والتصدير', [backupWrap]);
    addTechPanel('clone', 'الاستنساخ', [findSubForm('استنساخ كمسودة')]);
    addTechPanel('restore', 'الاستعادة', [findSubForm('استعادة النظام')]);
    addTechPanel('delete', 'الحذف', [findSubForm('حذف المستأجر')]);

    techSelect.addEventListener('change', () => {
        const activeKey = techSelect.value || '';
        techPanels.querySelectorAll('[data-tech-panel]').forEach((panel) => {
            panel.classList.toggle('is-active', panel.getAttribute('data-tech-panel') === activeKey);
        });
        if (tenantId !== '') {
            try { localStorage.setItem(STORAGE_KEYS.tenantTechPrefix + tenantId, activeKey); } catch (error) {}
        }
    });
    if (techSelect.options.length > 0) {
        const rememberedTech = tenantId !== '' ? (() => { try { return localStorage.getItem(STORAGE_KEYS.tenantTechPrefix + tenantId) || ''; } catch (error) { return ''; } })() : '';
        techSelect.value = rememberedTech && techSelect.querySelector(`option[value="${rememberedTech}"]`) ? rememberedTech : techSelect.options[0].value;
        techSelect.dispatchEvent(new Event('change'));
    }

    smartGrid.appendChild(stageCard);
    smartGrid.appendChild(techCard);
    card.insertBefore(smartGrid, actionsBox);
    card.classList.add('has-smart-console');
}

document.querySelectorAll('[data-tenant-card="1"]').forEach((card) => {
    createSmartConsoleForTenant(card);
});

function createSmartCreateForm() {
    const form = document.querySelector('.tenant-create-form');
    if (!form || form.classList.contains('is-smartified')) return;
    const grid = form.querySelector('.saas-form-grid');
    if (!grid) return;

    const groups = {
        basics: [],
        technical: [],
        policy: [],
    };

    const fieldNames = {
        basics: ['tenant_name', 'tenant_slug', 'system_name', 'system_folder', 'legal_name', 'billing_email', 'app_url', 'primary_domain', 'notes'],
        technical: ['db_host', 'db_port', 'db_name', 'db_user', 'db_password', 'db_socket'],
        policy: ['plan_code', 'provision_profile', 'policy_pack', 'status', 'timezone', 'locale', 'users_limit', 'storage_limit_mb', 'ops_keep_latest', 'ops_keep_days', 'trial_ends_at', 'subscribed_until'],
    };

    Array.from(grid.children).forEach((node) => {
        const input = node.querySelector('[name]');
        const fieldName = input ? String(input.getAttribute('name') || '') : '';
        let assigned = false;
        Object.entries(fieldNames).forEach(([groupKey, names]) => {
            if (!assigned && fieldName !== '' && names.includes(fieldName)) {
                groups[groupKey].push(node);
                assigned = true;
            }
        });
        if (!assigned) {
            if (node.classList.contains('full')) {
                groups.policy.push(node);
            } else {
                groups.basics.push(node);
            }
        }
    });

    const shell = document.createElement('div');
    shell.className = 'smart-create-shell';
    shell.innerHTML = '<div class="inline-note">نموذج الإنشاء أصبح مرحليًا: ابدأ بالأساسيات، ثم الاتصال والتشغيل، ثم السياسات والحدود.</div><div class="smart-create-nav"></div><div class="smart-create-panels"></div>';
    const nav = shell.querySelector('.smart-create-nav');
    const panels = shell.querySelector('.smart-create-panels');

    const createPanel = (key, label, description, nodes) => {
        const panel = document.createElement('div');
        panel.className = 'smart-create-panel';
        panel.setAttribute('data-create-panel', key);

        const note = document.createElement('div');
        note.className = 'mini-row';
        note.innerHTML = `<strong>${label}</strong><small>${description}</small>`;
        panel.appendChild(note);

        const panelGrid = document.createElement('div');
        panelGrid.className = 'saas-form-grid';
        (nodes || []).forEach((node) => panelGrid.appendChild(node));
        panel.appendChild(panelGrid);

        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'saas-btn dark';
        button.textContent = label;
        button.setAttribute('data-step-key', key);
        button.addEventListener('click', () => {
            nav.querySelectorAll('.saas-btn').forEach((item) => item.classList.toggle('is-active', item === button));
            panels.querySelectorAll('.smart-create-panel').forEach((item) => item.classList.toggle('is-active', item === panel));
            try { localStorage.setItem(STORAGE_KEYS.createStep, key); } catch (error) {}
        });

        nav.appendChild(button);
        panels.appendChild(panel);
    };

    createPanel('basics', 'البيانات الأساسية', 'معلومات العميل، النظام، والرابط الرئيسي قبل أي تفاصيل تقنية.', groups.basics);
    createPanel('technical', 'اتصال وتشغيل', 'بيانات قاعدة التشغيل ومسار الربط الفني للمستأجر الجديد.', groups.technical);
    createPanel('policy', 'السياسات والحدود', 'الخطة، البروفايل، السياسة، والحدود الافتراضية قبل الحفظ.', groups.policy);

    form.insertBefore(shell, grid);
    form.classList.add('is-smartified');

    const rememberedStep = (() => { try { return localStorage.getItem(STORAGE_KEYS.createStep) || ''; } catch (error) { return ''; } })();
    const createStepButton = nav.querySelector(`.saas-btn[data-step-key="${rememberedStep}"]`) || nav.querySelector('.saas-btn');
    if (createStepButton) createStepButton.click();
}

createSmartCreateForm();

if (saasSystemNameInput && saasSystemFolderInput) {
    let systemFolderTouched = false;
    const syncSystemFolder = () => {
        if (systemFolderTouched) return;
        saasSystemFolderInput.value = normalizeSystemFolder(saasSystemNameInput.value);
    };
    saasSystemNameInput.addEventListener('input', syncSystemFolder);
    saasSystemFolderInput.addEventListener('input', () => {
        systemFolderTouched = normalizeSystemFolder(saasSystemFolderInput.value) !== '';
        saasSystemFolderInput.value = normalizeSystemFolder(saasSystemFolderInput.value);
    });
    saasSystemFolderInput.addEventListener('blur', () => {
        saasSystemFolderInput.value = normalizeSystemFolder(saasSystemFolderInput.value);
        systemFolderTouched = saasSystemFolderInput.value !== '';
    });
    syncSystemFolder();
}

document.querySelectorAll('.clone-blueprint-form').forEach((form) => {
    const provisionCheckbox = form.querySelector('input[name="clone_provision_now"]');
    const seedCheckboxes = Array.from(form.querySelectorAll('input[name="clone_seed_presets[]"]'));
    form.querySelectorAll('[data-clone-preset]').forEach((button) => {
        button.addEventListener('click', () => {
            const provisionNow = button.getAttribute('data-provision-now') === '1';
            let seedPresets = [];
            try {
                seedPresets = JSON.parse(button.getAttribute('data-seed-presets') || '[]');
            } catch (error) {
                seedPresets = [];
            }
            if (provisionCheckbox) {
                provisionCheckbox.checked = provisionNow;
            }
            seedCheckboxes.forEach((checkbox) => {
                checkbox.checked = seedPresets.includes(checkbox.value);
            });
        });
    });
});

document.querySelectorAll('[data-provision-profile]').forEach((button) => {
    button.addEventListener('click', () => {
        const targetForm = button.getAttribute('data-target-form');
        const form = targetForm === 'create'
            ? document.querySelector('.tenant-create-form')
            : button.closest('form');
        if (!form) return;

        const planCode = button.getAttribute('data-plan-code') || '';
        const timezone = button.getAttribute('data-timezone') || 'Africa/Cairo';
        const locale = button.getAttribute('data-locale') || 'ar';
        const usersLimit = button.getAttribute('data-users-limit') || '0';
        const storageLimit = button.getAttribute('data-storage-limit') || '0';

        const planInput = form.querySelector(targetForm === 'create' ? 'input[name="plan_code"]' : 'input[name="clone_plan_code"]');
        const profileInput = form.querySelector(targetForm === 'create' ? 'input[name="provision_profile"]' : 'input[name="clone_provision_profile"]');
        const timezoneInput = form.querySelector(targetForm === 'create' ? 'input[name="timezone"]' : 'input[name="clone_timezone"]');
        const localeInput = form.querySelector(targetForm === 'create' ? 'select[name="locale"]' : 'select[name="clone_locale"]');
        const usersLimitInput = form.querySelector(targetForm === 'create' ? 'input[name="users_limit"]' : 'input[name="clone_users_limit"]');
        const storageLimitInput = form.querySelector(targetForm === 'create' ? 'input[name="storage_limit_mb"]' : 'input[name="clone_storage_limit_mb"]');

        if (planInput) planInput.value = planCode;
        if (profileInput) profileInput.value = button.getAttribute('data-provision-profile') || '';
        if (timezoneInput) timezoneInput.value = timezone;
        if (localeInput) localeInput.value = locale;
        if (usersLimitInput) usersLimitInput.value = usersLimit;
        if (storageLimitInput) storageLimitInput.value = storageLimit;
    });
});

document.querySelectorAll('[data-policy-pack]').forEach((button) => {
    button.addEventListener('click', () => {
        const targetForm = button.getAttribute('data-target-form');
        const form = targetForm === 'create'
            ? document.querySelector('.tenant-create-form')
            : button.closest('form');
        if (!form) return;

        const tenantStatus = button.getAttribute('data-tenant-status') || 'active';
        const timezone = button.getAttribute('data-timezone') || 'Africa/Cairo';
        const locale = button.getAttribute('data-locale') || 'ar';
        const trialDays = button.getAttribute('data-trial-days') || '14';
        const graceDays = button.getAttribute('data-grace-days') || '7';
        const opsKeepLatest = button.getAttribute('data-ops-keep-latest') || '500';
        const opsKeepDays = button.getAttribute('data-ops-keep-days') || '30';

        const policyInput = form.querySelector(targetForm === 'create' ? 'input[name="policy_pack"]' : 'input[name="clone_policy_pack"]');
        const statusInput = form.querySelector(targetForm === 'create' ? 'select[name="status"]' : null);
        const timezoneInput = form.querySelector(targetForm === 'create' ? 'input[name="timezone"]' : 'input[name="clone_timezone"]');
        const localeInput = form.querySelector(targetForm === 'create' ? 'select[name="locale"]' : 'select[name="clone_locale"]');
        const trialDaysInput = form.querySelector(targetForm === 'create' ? null : 'input[name="subscription_trial_days"], input[name="clone_trial_days"]');
        const graceDaysInput = form.querySelector(targetForm === 'create' ? null : 'input[name="subscription_grace_days"], input[name="clone_grace_days"]');
        const opsKeepLatestInput = form.querySelector(targetForm === 'create' ? 'input[name="ops_keep_latest"]' : 'input[name="clone_ops_keep_latest"]');
        const opsKeepDaysInput = form.querySelector(targetForm === 'create' ? 'input[name="ops_keep_days"]' : 'input[name="clone_ops_keep_days"]');

        if (policyInput) policyInput.value = button.getAttribute('data-policy-pack') || '';
        if (statusInput) statusInput.value = tenantStatus;
        if (timezoneInput) timezoneInput.value = timezone;
        if (localeInput) localeInput.value = locale;
        if (trialDaysInput) trialDaysInput.value = trialDays;
        if (graceDaysInput) graceDaysInput.value = graceDays;
        if (opsKeepLatestInput) opsKeepLatestInput.value = opsKeepLatest;
        if (opsKeepDaysInput) opsKeepDaysInput.value = opsKeepDays;
    });
});

const provisionProfilesCatalog = <?php
    $profilePreviewCatalog = [];
    foreach ($tenantProvisionProfiles as $profileMeta) {
        $profileKey = strtolower(trim((string)($profileMeta['profile_key'] ?? '')));
        if ($profileKey === '') {
            continue;
        }
        $profilePreviewCatalog[$profileKey] = [
            'label' => (string)($profileMeta['label'] ?? $profileKey),
            'plan_code' => (string)($profileMeta['plan_code'] ?? 'basic'),
            'timezone' => (string)($profileMeta['timezone'] ?? 'Africa/Cairo'),
            'locale' => (string)($profileMeta['locale'] ?? 'ar'),
            'users_limit' => (int)($profileMeta['users_limit'] ?? 0),
            'storage_limit_mb' => (int)($profileMeta['storage_limit_mb'] ?? 0),
        ];
    }
    echo json_encode($profilePreviewCatalog, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
?> || {};

const policyPacksCatalog = <?php
    $policyPreviewCatalog = [];
    foreach ($tenantPolicyPacks as $packMeta) {
        $packKey = strtolower(trim((string)($packMeta['pack_key'] ?? '')));
        if ($packKey === '') {
            continue;
        }
        $policyPreviewCatalog[$packKey] = [
            'label' => (string)($packMeta['label'] ?? $packKey),
            'tenant_status' => (string)($packMeta['tenant_status'] ?? 'active'),
            'timezone' => (string)($packMeta['timezone'] ?? 'Africa/Cairo'),
            'locale' => (string)($packMeta['locale'] ?? 'ar'),
            'trial_days' => (int)($packMeta['trial_days'] ?? 14),
            'grace_days' => (int)($packMeta['grace_days'] ?? 7),
            'ops_keep_latest' => (int)($packMeta['ops_keep_latest'] ?? 500),
            'ops_keep_days' => (int)($packMeta['ops_keep_days'] ?? 30),
        ];
    }
    echo json_encode($policyPreviewCatalog, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
?> || {};

document.querySelectorAll('form').forEach((form) => {
    const profileSelect = form.querySelector('[data-profile-select="1"]');
    const previewBox = form.querySelector('[data-profile-diff-preview="1"]');
    if (!profileSelect || !previewBox) return;

    const renderProfileDiffPreview = () => {
        const selectedKey = String(profileSelect.value || '').toLowerCase().trim();
        const selectedProfile = provisionProfilesCatalog[selectedKey];
        if (!selectedProfile) {
            previewBox.innerHTML = '<small>لم يتم العثور على بيانات الـ Profile المختار.</small>';
            return;
        }

        const currentValues = {
            plan_code: previewBox.getAttribute('data-current-plan') || '',
            timezone: previewBox.getAttribute('data-current-timezone') || '',
            locale: previewBox.getAttribute('data-current-locale') || '',
            users_limit: previewBox.getAttribute('data-current-users') || '0',
            storage_limit_mb: previewBox.getAttribute('data-current-storage') || '0',
            provision_profile: previewBox.getAttribute('data-current-profile') || '',
        };

        const labels = {
            plan_code: 'الخطة',
            timezone: 'المنطقة الزمنية',
            locale: 'اللغة',
            users_limit: 'حد المستخدمين',
            storage_limit_mb: 'حد التخزين',
            provision_profile: '<?php echo app_h(app_tr('بروفايل التهيئة', 'Provision Profile')); ?>',
        };

        const targetValues = {
            plan_code: String(selectedProfile.plan_code || ''),
            timezone: String(selectedProfile.timezone || ''),
            locale: String(selectedProfile.locale || ''),
            users_limit: String(selectedProfile.users_limit || 0),
            storage_limit_mb: String(selectedProfile.storage_limit_mb || 0),
            provision_profile: selectedKey,
        };

        const changes = [];
        Object.keys(labels).forEach((field) => {
            if (String(currentValues[field]) === String(targetValues[field])) {
                return;
            }
            changes.push(`${labels[field]}: ${currentValues[field]} -> ${targetValues[field]}`);
        });

        if (!changes.length) {
            previewBox.innerHTML = `<small><?php echo app_h(app_tr('لا توجد تغييرات. المستأجر مطابق للبروفايل المختار:', 'No changes. The tenant already matches the selected profile:')); ?> ${selectedProfile.label || selectedKey}.</small>`;
            return;
        }

        previewBox.innerHTML = `<small>${changes.join(' | ')}</small>`;
    };

    profileSelect.addEventListener('change', renderProfileDiffPreview);
    renderProfileDiffPreview();
});

document.querySelectorAll('form').forEach((form) => {
    const packSelect = form.querySelector('[data-policy-select="1"]');
    const previewBox = form.querySelector('[data-policy-diff-preview="1"]');
    if (!packSelect || !previewBox) return;

    const renderPolicyDiffPreview = () => {
        const selectedKey = String(packSelect.value || '').toLowerCase().trim();
        const selectedPack = policyPacksCatalog[selectedKey];
        if (!selectedPack) {
            previewBox.innerHTML = '<small><?php echo app_h(app_tr('لم يتم العثور على بيانات حزمة السياسات المختارة.', 'The selected policy pack data could not be found.')); ?></small>';
            return;
        }

        const currentValues = {
            status: previewBox.getAttribute('data-current-status') || '',
            timezone: previewBox.getAttribute('data-current-timezone') || '',
            locale: previewBox.getAttribute('data-current-locale') || '',
            policy_pack: previewBox.getAttribute('data-current-policy-pack') || '',
            trial_days: previewBox.getAttribute('data-current-trial-days') || '14',
            grace_days: previewBox.getAttribute('data-current-grace-days') || '7',
            ops_keep_latest: previewBox.getAttribute('data-current-ops-keep-latest') || '500',
            ops_keep_days: previewBox.getAttribute('data-current-ops-keep-days') || '30',
        };

        const labels = {
            status: 'حالة المستأجر',
            timezone: 'المنطقة الزمنية',
            locale: 'اللغة',
            policy_pack: '<?php echo app_h(app_tr('حزمة السياسات', 'Policy Pack')); ?>',
            trial_days: 'أيام التجربة',
            grace_days: 'أيام السماح',
            ops_keep_latest: 'حد السجلات المحفوظة',
            ops_keep_days: 'عمر السجلات بالأيام',
        };

        const targetValues = {
            status: String(selectedPack.tenant_status || ''),
            timezone: String(selectedPack.timezone || ''),
            locale: String(selectedPack.locale || ''),
            policy_pack: selectedKey,
            trial_days: String(selectedPack.trial_days || 14),
            grace_days: String(selectedPack.grace_days || 7),
            ops_keep_latest: String(selectedPack.ops_keep_latest || 500),
            ops_keep_days: String(selectedPack.ops_keep_days || 30),
        };

        const changes = [];
        Object.keys(labels).forEach((field) => {
            if (String(currentValues[field]) === String(targetValues[field])) {
                return;
            }
            changes.push(`${labels[field]}: ${currentValues[field]} -> ${targetValues[field]}`);
        });

        if (!changes.length) {
            previewBox.innerHTML = `<small><?php echo app_h(app_tr('لا توجد تغييرات. المستأجر مطابق لحزمة السياسات المختارة:', 'No changes. The tenant already matches the selected policy pack:')); ?> ${selectedPack.label || selectedKey}.</small>`;
            return;
        }

        previewBox.innerHTML = `<small>${changes.join(' | ')}</small>`;
    };

    packSelect.addEventListener('change', renderPolicyDiffPreview);
    renderPolicyDiffPreview();
});
</script>
<?php
$controlConn->close();
include 'footer.php';
ob_end_flush();
