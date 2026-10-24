<?php
// Example central license endpoint.
// Deploy on your central server as: /api/license/check
// Adjust storage and validation rules to your production database.

declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');

function respond(int $code, array $payload): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function body_json(): array
{
    $raw = file_get_contents('php://input');
    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function bearer_token(): string
{
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!is_string($header) || stripos($header, 'Bearer ') !== 0) {
        return '';
    }
    return trim(substr($header, 7));
}

$apiToken = getenv('LICENSE_API_TOKEN') ?: 'CHANGE_ME';
$auth = bearer_token();
if ($apiToken !== '' && !hash_equals($apiToken, $auth)) {
    respond(401, ['ok' => false, 'error' => 'unauthorized']);
}

$in = body_json();
$licenseKey = trim((string)($in['license_key'] ?? ''));
$installationId = trim((string)($in['installation_id'] ?? ''));
$fingerprint = trim((string)($in['fingerprint'] ?? ''));
$domain = strtolower(trim((string)($in['domain'] ?? '')));

if ($licenseKey === '' || $installationId === '' || $fingerprint === '' || $domain === '') {
    respond(422, ['ok' => false, 'error' => 'missing_required_fields']);
}

// Example static store. Replace with your database query.
$licenses = [
    'CLIENT-LICENSE-KEY' => [
        'status' => 'active',
        'plan' => 'subscription',
        'owner_name' => 'Client Name',
        'subscription_ends_at' => '2026-12-31 23:59:59',
        'trial_ends_at' => null,
        'grace_days' => 3,
        'allowed_domains' => ['client-domain.com'],
        'installation_id' => null, // optional strict lock
        'fingerprint' => null, // optional strict lock
    ],
];

if (!isset($licenses[$licenseKey])) {
    // Return suspended to avoid key enumeration.
    respond(200, [
        'license' => [
            'status' => 'suspended',
            'plan' => 'subscription',
            'owner_name' => '',
            'subscription_ends_at' => date('Y-m-d H:i:s'),
            'trial_ends_at' => null,
            'grace_days' => 0,
        ],
    ]);
}

$row = $licenses[$licenseKey];
$allowedDomains = array_map('strtolower', (array)($row['allowed_domains'] ?? []));
if (!empty($allowedDomains) && !in_array($domain, $allowedDomains, true)) {
    respond(200, [
        'license' => [
            'status' => 'suspended',
            'plan' => (string)($row['plan'] ?? 'subscription'),
            'owner_name' => (string)($row['owner_name'] ?? ''),
            'subscription_ends_at' => (string)($row['subscription_ends_at'] ?? date('Y-m-d H:i:s')),
            'trial_ends_at' => $row['trial_ends_at'] ?? null,
            'grace_days' => 0,
        ],
    ]);
}

$lockInstall = trim((string)($row['installation_id'] ?? ''));
if ($lockInstall !== '' && !hash_equals($lockInstall, $installationId)) {
    respond(200, [
        'license' => [
            'status' => 'suspended',
            'plan' => (string)($row['plan'] ?? 'subscription'),
            'owner_name' => (string)($row['owner_name'] ?? ''),
            'subscription_ends_at' => (string)($row['subscription_ends_at'] ?? date('Y-m-d H:i:s')),
            'trial_ends_at' => $row['trial_ends_at'] ?? null,
            'grace_days' => 0,
        ],
    ]);
}

$lockFingerprint = trim((string)($row['fingerprint'] ?? ''));
if ($lockFingerprint !== '' && !hash_equals($lockFingerprint, $fingerprint)) {
    respond(200, [
        'license' => [
            'status' => 'suspended',
            'plan' => (string)($row['plan'] ?? 'subscription'),
            'owner_name' => (string)($row['owner_name'] ?? ''),
            'subscription_ends_at' => (string)($row['subscription_ends_at'] ?? date('Y-m-d H:i:s')),
            'trial_ends_at' => $row['trial_ends_at'] ?? null,
            'grace_days' => 0,
        ],
    ]);
}

respond(200, [
    'license' => [
        'status' => (string)($row['status'] ?? 'active'),
        'plan' => (string)($row['plan'] ?? 'subscription'),
        'owner_name' => (string)($row['owner_name'] ?? ''),
        'trial_ends_at' => $row['trial_ends_at'] ?? null,
        'subscription_ends_at' => $row['subscription_ends_at'] ?? null,
        'grace_days' => (int)($row['grace_days'] ?? 3),
    ],
]);
