<?php
// update_api.php
// Central update distribution API (owner system)

require_once __DIR__ . '/config.php';

if (!function_exists('update_api_json')) {
    function update_api_json(int $code, array $payload): void
    {
        http_response_code($code);
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('X-Content-Type-Options: nosniff');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

if (!function_exists('update_api_token_from_request')) {
    function update_api_token_from_request(array $payload = []): string
    {
        $headerToken = trim((string)($_SERVER['HTTP_X_UPDATE_TOKEN'] ?? ''));
        if ($headerToken !== '') {
            return $headerToken;
        }

        $authHeader = trim((string)($_SERVER['HTTP_AUTHORIZATION'] ?? ''));
        if (stripos($authHeader, 'Bearer ') === 0) {
            $bearer = trim(substr($authHeader, 7));
            if ($bearer !== '') {
                return $bearer;
            }
        }

        $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if ($method !== 'GET') {
            $payloadToken = trim((string)($payload['token'] ?? ''));
            if ($payloadToken !== '') {
                return $payloadToken;
            }
            $postToken = trim((string)($_POST['token'] ?? ''));
            if ($postToken !== '') {
                return $postToken;
            }
        }
        return '';
    }
}

if (!function_exists('update_api_require_token')) {
    function update_api_require_token(mysqli $conn, array $payload = []): string
    {
        $expected = trim((string)app_update_api_token($conn));
        if ($expected === '') {
            update_api_json(503, ['ok' => false, 'error' => 'api_token_not_configured']);
        }
        $provided = update_api_token_from_request($payload);
        if ($provided === '' || !hash_equals($expected, $provided)) {
            update_api_json(401, ['ok' => false, 'error' => 'access_denied']);
        }
        return $expected;
    }
}

app_initialize_system_settings($conn);
if (!app_ensure_update_center_schema($conn)) {
    update_api_json(500, ['ok' => false, 'error' => 'schema_not_ready']);
}

if (app_license_edition() !== 'owner') {
    update_api_json(403, ['ok' => false, 'error' => 'owner_only']);
}

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$action = strtolower(trim((string)($_GET['action'] ?? $_POST['action'] ?? '')));

if ($method === 'GET' && $action === 'download') {
    update_api_require_token($conn, []);

    $packageId = (int)($_GET['id'] ?? 0);
    if ($packageId <= 0) {
        update_api_json(400, ['ok' => false, 'error' => 'invalid_package_id']);
    }

    $stmt = $conn->prepare('SELECT * FROM app_update_packages WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $packageId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) {
        update_api_json(404, ['ok' => false, 'error' => 'package_not_found']);
    }

    $path = app_update_package_local_path((array)$row);
    if ($path === '' || !is_file($path)) {
        update_api_json(404, ['ok' => false, 'error' => 'package_file_missing']);
    }

    $filename = trim((string)($row['package_name'] ?? 'update_package.zip'));
    if ($filename === '') {
        $filename = 'update_package.zip';
    }
    if (strtolower((string)pathinfo($filename, PATHINFO_EXTENSION)) !== 'zip') {
        $filename .= '.zip';
    }

    header('Content-Type: application/zip');
    header('Content-Length: ' . (string)filesize($path));
    header('Content-Disposition: attachment; filename="' . rawurlencode($filename) . '"; filename*=UTF-8\'\'' . rawurlencode($filename));
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('X-Content-Type-Options: nosniff');
    readfile($path);
    exit;
}

if ($method !== 'POST') {
    header('Allow: POST, GET');
    update_api_json(405, ['ok' => false, 'error' => 'method_not_allowed']);
}

$raw = file_get_contents('php://input');
$payload = json_decode((string)$raw, true);
if (!is_array($payload)) {
    $payload = [];
}

if ($action === '') {
    $action = strtolower(trim((string)($payload['action'] ?? 'latest')));
}
if ($action === '') {
    $action = 'latest';
}

update_api_require_token($conn, $payload);

if ($action === 'ping') {
    update_api_json(200, [
        'ok' => true,
        'edition' => app_license_edition(),
        'server_time' => date('Y-m-d H:i:s'),
    ]);
}

if ($action === 'latest') {
    $edition = app_update_sanitize_target_edition((string)($payload['edition'] ?? 'any'));
    $currentVersion = app_update_sanitize_version_tag((string)($payload['current_version'] ?? ''));

    $package = app_update_latest_package($conn, $edition);
    if (!$package) {
        update_api_json(200, [
            'ok' => true,
            'has_update' => false,
            'edition' => $edition,
            'reason' => 'no_active_package',
        ]);
    }

    $versionTag = app_update_sanitize_version_tag((string)($package['version_tag'] ?? ''));
    if ($currentVersion !== '' && $versionTag !== '' && hash_equals($currentVersion, $versionTag)) {
        update_api_json(200, [
            'ok' => true,
            'has_update' => false,
            'edition' => $edition,
            'reason' => 'already_latest',
            'version_tag' => $versionTag,
        ]);
    }

    $packageId = (int)($package['id'] ?? 0);
    update_api_json(200, [
        'ok' => true,
        'has_update' => true,
        'edition' => $edition,
        'package' => [
            'id' => $packageId,
            'version_tag' => $versionTag,
            'target_edition' => (string)($package['target_edition'] ?? 'any'),
            'release_notes' => (string)($package['release_notes'] ?? ''),
            'sha256' => (string)($package['file_hash'] ?? ''),
            'file_size' => (int)($package['file_size'] ?? 0),
            'created_at' => (string)($package['created_at'] ?? ''),
            'source_mode' => (string)($package['source_mode'] ?? 'local_upload'),
        ],
        'download_url' => app_update_download_url($packageId),
    ]);
}

update_api_json(400, ['ok' => false, 'error' => 'unknown_action']);
