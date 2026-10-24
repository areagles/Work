<?php
// Force client instance into activation-required state.
// Usage: php scripts/force_client_activation.php [--force]

declare(strict_types=1);

require_once __DIR__ . '/../config.php';

$force = in_array('--force', $argv ?? [], true);
$edition = app_license_edition();
$remoteOnly = app_license_remote_only_mode();

if (!$force && $edition !== 'client') {
    fwrite(STDERR, "Abort: APP_LICENSE_EDITION is '{$edition}', not 'client'. Use --force to override.\n");
    exit(1);
}

if (!app_ensure_license_schema($conn)) {
    fwrite(STDERR, "Abort: unable to ensure license schema.\n");
    exit(1);
}

$row = app_license_row($conn);
if (empty($row)) {
    fwrite(STDERR, "Abort: no license row available.\n");
    exit(1);
}

$now = date('Y-m-d H:i:s');
$metadata = [
    'forced_activation_required' => true,
    'forced_at' => $now,
    'edition' => $edition,
    'remote_only' => $remoteOnly,
];
$metaJson = json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if (!is_string($metaJson)) {
    $metaJson = '{"forced_activation_required":true}';
}

$stmt = $conn->prepare(
    "UPDATE app_license_state
     SET license_key = '',
         plan_type = 'trial',
         license_status = 'active',
         trial_ends_at = NULL,
         subscription_ends_at = NULL,
         last_check_at = NULL,
         last_success_at = NULL,
         last_error = 'activation_required',
         metadata_json = ?
     WHERE id = 1"
);
$stmt->bind_param('s', $metaJson);
$stmt->execute();
$stmt->close();

$after = app_license_status($conn, false);

echo "OK: client activation reset completed.\n";
echo "edition=" . ($after['edition'] ?? $edition) . "\n";
echo "remote_only=" . ((isset($after['remote_only']) && $after['remote_only']) ? '1' : '0') . "\n";
echo "allowed=" . (!empty($after['allowed']) ? '1' : '0') . "\n";
echo "reason=" . ($after['reason'] ?? 'unknown') . "\n";
echo "installation_id=" . ($after['installation_id'] ?? '') . "\n";
