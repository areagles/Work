<?php
// Upsert a client license directly in owner database.
// Usage example:
// php scripts/upsert_client_license.php --key=AE-CLI-XXXX --client="Client Name" --plan=subscription --status=active --subscription-ends="2026-12-31 23:59:59" --domains="client.com,*.client.com" --strict=1 --max-installations=1

declare(strict_types=1);

require_once __DIR__ . '/../config.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must run from CLI.\n");
    exit(1);
}

if (app_license_edition() !== 'owner') {
    fwrite(STDERR, "Abort: APP_LICENSE_EDITION must be owner on this instance.\n");
    exit(1);
}

if (!app_ensure_license_management_schema($conn)) {
    fwrite(STDERR, "Abort: failed to initialize license management schema.\n");
    exit(1);
}

$opts = getopt('', [
    'key:',
    'client:',
    'email::',
    'phone::',
    'plan::',
    'status::',
    'trial-ends::',
    'subscription-ends::',
    'grace-days::',
    'domains::',
    'strict::',
    'max-installations::',
    'notes::',
    'help::',
]);

if (isset($opts['help']) || !isset($opts['key']) || !isset($opts['client'])) {
    echo "Usage:\n";
    echo "  php scripts/upsert_client_license.php --key=AE-CLI-XXXX --client=\"Client Name\" [--plan=subscription|trial|lifetime] [--status=active|suspended|expired] [--subscription-ends=\"YYYY-mm-dd HH:ii:ss\"] [--domains=\"client.com,*.client.com\"] [--strict=1] [--max-installations=1]\n";
    exit(isset($opts['help']) ? 0 : 1);
}

$licenseKey = strtoupper(trim((string)$opts['key']));
$clientName = trim((string)$opts['client']);
$clientEmail = trim((string)($opts['email'] ?? ''));
$clientPhone = trim((string)($opts['phone'] ?? ''));
$plan = strtolower(trim((string)($opts['plan'] ?? 'subscription')));
$status = strtolower(trim((string)($opts['status'] ?? 'active')));
$trialEnds = trim((string)($opts['trial-ends'] ?? ''));
$subscriptionEnds = trim((string)($opts['subscription-ends'] ?? date('Y-m-d H:i:s', strtotime('+30 days'))));
$graceDays = max(0, min(60, (int)($opts['grace-days'] ?? 3)));
$domainsRaw = trim((string)($opts['domains'] ?? ''));
$strict = ((int)($opts['strict'] ?? 1)) === 1 ? 1 : 0;
$maxInstallations = max(1, min(20, (int)($opts['max-installations'] ?? 1)));
$notes = trim((string)($opts['notes'] ?? ''));

if ($licenseKey === '' || $clientName === '') {
    fwrite(STDERR, "Abort: --key and --client are required.\n");
    exit(1);
}
if (!in_array($plan, ['trial', 'subscription', 'lifetime'], true)) {
    fwrite(STDERR, "Abort: invalid --plan.\n");
    exit(1);
}
if (!in_array($status, ['active', 'suspended', 'expired'], true)) {
    fwrite(STDERR, "Abort: invalid --status.\n");
    exit(1);
}

if ($plan === 'trial') {
    if ($trialEnds === '') {
        $trialEnds = date('Y-m-d H:i:s', strtotime('+14 days'));
    }
    if (strtotime($trialEnds) === false) {
        fwrite(STDERR, "Abort: invalid --trial-ends datetime.\n");
        exit(1);
    }
    $subscriptionEnds = '';
} elseif ($plan === 'subscription') {
    if ($subscriptionEnds === '' || strtotime($subscriptionEnds) === false) {
        fwrite(STDERR, "Abort: invalid --subscription-ends datetime.\n");
        exit(1);
    }
    $trialEnds = '';
} else {
    $trialEnds = '';
    $subscriptionEnds = '';
}

$domains = app_license_decode_domains($domainsRaw);
$domainsJson = app_license_encode_domains($domains);
$lockReason = $status === 'suspended' ? 'Suspended by owner script' : '';

$stmt = $conn->prepare("
    INSERT INTO app_license_registry (
        license_key, client_name, client_email, client_phone,
        plan_type, status, trial_ends_at, subscription_ends_at, grace_days,
        allowed_domains, strict_installation, max_installations, lock_reason, notes
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE
        client_name = VALUES(client_name),
        client_email = VALUES(client_email),
        client_phone = VALUES(client_phone),
        plan_type = VALUES(plan_type),
        status = VALUES(status),
        trial_ends_at = VALUES(trial_ends_at),
        subscription_ends_at = VALUES(subscription_ends_at),
        grace_days = VALUES(grace_days),
        allowed_domains = VALUES(allowed_domains),
        strict_installation = VALUES(strict_installation),
        max_installations = VALUES(max_installations),
        lock_reason = VALUES(lock_reason),
        notes = VALUES(notes)
");
$stmt->bind_param(
    'ssssssssisiiss',
    $licenseKey,
    $clientName,
    $clientEmail,
    $clientPhone,
    $plan,
    $status,
    $trialEnds,
    $subscriptionEnds,
    $graceDays,
    $domainsJson,
    $strict,
    $maxInstallations,
    $lockReason,
    $notes
);
$ok = $stmt->execute();
$stmt->close();

if (!$ok) {
    fwrite(STDERR, "Failed to upsert license.\n");
    exit(1);
}

echo "OK: license upserted.\n";
echo "key={$licenseKey}\n";
echo "client={$clientName}\n";
echo "plan={$plan}\n";
echo "status={$status}\n";
echo "subscription_ends_at={$subscriptionEnds}\n";
echo "allowed_domains=" . implode(',', $domains) . "\n";
