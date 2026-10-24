<?php
// Seed initial subscriptions in owner edition.
// Usage:
//   php scripts/seed_license_registry.php
//   php scripts/seed_license_registry.php --force

declare(strict_types=1);

require_once __DIR__ . '/../config.php';

if (PHP_SAPI !== 'cli') {
    echo "This script must run in CLI.\n";
    exit(1);
}

$force = in_array('--force', $argv ?? [], true);

if (app_license_edition() !== 'owner') {
    echo "Abort: APP_LICENSE_EDITION must be 'owner'.\n";
    exit(1);
}

if (!app_ensure_license_management_schema($conn)) {
    echo "Abort: unable to initialize license management schema.\n";
    exit(1);
}

$existing = (int)($conn->query("SELECT COUNT(*) AS c FROM app_license_registry")->fetch_assoc()['c'] ?? 0);
if ($existing > 0 && !$force) {
    echo "Skipped: app_license_registry already has data ({$existing} rows). Use --force to add seed anyway.\n";
    exit(0);
}

if ($force) {
    // Keep existing production data safe unless operator explicitly forces a reset.
    $conn->query("DELETE FROM app_license_runtime_log");
    $conn->query("DELETE FROM app_license_alerts");
    $conn->query("DELETE FROM app_license_installations");
    $conn->query("DELETE FROM app_license_registry");
}

$rows = [
    [
        'license_key' => 'AE-TRIAL-0001',
        'client_name' => 'Demo Trial Client',
        'client_email' => 'trial@example.com',
        'client_phone' => '',
        'plan_type' => 'trial',
        'status' => 'active',
        'trial_ends_at' => date('Y-m-d H:i:s', strtotime('+14 days')),
        'subscription_ends_at' => '',
        'grace_days' => 3,
        'allowed_domains' => app_license_encode_domains(['trial-client.local']),
        'strict_installation' => 0,
        'max_installations' => 1,
        'lock_reason' => '',
        'notes' => 'Initial trial seed',
    ],
    [
        'license_key' => 'AE-SUB-0001',
        'client_name' => 'Demo Paid Client',
        'client_email' => 'paid@example.com',
        'client_phone' => '',
        'plan_type' => 'subscription',
        'status' => 'active',
        'trial_ends_at' => '',
        'subscription_ends_at' => date('Y-m-d H:i:s', strtotime('+30 days')),
        'grace_days' => 3,
        'allowed_domains' => app_license_encode_domains(['paid-client.local']),
        'strict_installation' => 1,
        'max_installations' => 1,
        'lock_reason' => '',
        'notes' => 'Initial subscription seed',
    ],
];

$stmt = $conn->prepare("
    INSERT INTO app_license_registry (
        license_key, client_name, client_email, client_phone,
        plan_type, status, trial_ends_at, subscription_ends_at, grace_days,
        allowed_domains, strict_installation, max_installations, lock_reason, notes
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
");

$inserted = 0;
foreach ($rows as $r) {
    $stmt->bind_param(
        'ssssssssisiiss',
        $r['license_key'],
        $r['client_name'],
        $r['client_email'],
        $r['client_phone'],
        $r['plan_type'],
        $r['status'],
        $r['trial_ends_at'],
        $r['subscription_ends_at'],
        $r['grace_days'],
        $r['allowed_domains'],
        $r['strict_installation'],
        $r['max_installations'],
        $r['lock_reason'],
        $r['notes']
    );
    if ($stmt->execute()) {
        $inserted++;
    }
}
$stmt->close();

echo "Seed completed: {$inserted} subscription rows inserted.\n";
