<?php
require_once __DIR__ . '/auth.php';

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (!app_verify_csrf($_POST['_csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'invalid_csrf'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function ai_models_provider(string $provider): string
{
    $provider = strtolower(trim($provider));
    return in_array($provider, ['ollama', 'openai', 'gemini', 'openai_compatible'], true) ? $provider : 'ollama';
}

function ai_models_default_base(string $provider): string
{
    if ($provider === 'ollama') {
        return 'http://127.0.0.1:11434/v1';
    }
    if ($provider === 'gemini') {
        return 'https://generativelanguage.googleapis.com/v1beta/openai';
    }
    return 'https://api.openai.com/v1';
}

function ai_models_request(string $url, array $headers = []): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 45,
    ]);
    $raw = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($raw === false || $err !== '') {
        return ['ok' => false, 'error' => $err !== '' ? $err : 'request_failed', 'code' => $code];
    }
    $payload = json_decode($raw, true);
    if (!is_array($payload)) {
        return ['ok' => false, 'error' => 'invalid_json', 'code' => $code];
    }
    if ($code < 200 || $code >= 300) {
        return ['ok' => false, 'error' => trim((string)($payload['error']['message'] ?? $payload['error'] ?? 'provider_error')), 'code' => $code];
    }
    return ['ok' => true, 'payload' => $payload, 'code' => $code];
}

function ai_models_is_chat_usable(string $id): bool
{
    $id = strtolower(trim($id));
    if ($id === '') {
        return false;
    }
    foreach (['embedding', 'moderation', 'rerank', 'tts', 'speech', 'transcribe'] as $blocked) {
        if (strpos($id, $blocked) !== false) {
            return false;
        }
    }
    return true;
}

$provider = ai_models_provider((string)($_POST['provider'] ?? 'ollama'));
$baseUrl = rtrim(trim((string)($_POST['base_url'] ?? ai_models_default_base($provider))), '/');
$apiKey = trim((string)($_POST['api_key'] ?? ''));

if ($provider === 'ollama') {
    $root = preg_replace('#/v1$#', '', $baseUrl);
    $tags = ai_models_request($root . '/api/tags');
    if (!$tags['ok']) {
        http_response_code(502);
        echo json_encode(['ok' => false, 'error' => (string)$tags['error'], 'message' => 'تعذر قراءة الموديلات من Ollama.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
    $ps = ai_models_request($root . '/api/ps');
    $loaded = [];
    if (!empty($ps['ok'])) {
        foreach ((array)($ps['payload']['models'] ?? []) as $row) {
            $loaded[(string)($row['name'] ?? '')] = true;
        }
    }
    $models = [];
    foreach ((array)($tags['payload']['models'] ?? []) as $row) {
        $id = (string)($row['name'] ?? '');
        if ($id === '') {
            continue;
        }
        $models[] = [
            'id' => $id,
            'name' => $id,
            'status' => isset($loaded[$id]) ? 'loaded' : 'available',
            'usable' => ai_models_is_chat_usable($id),
        ];
    }
    echo json_encode(['ok' => true, 'provider' => $provider, 'models' => $models], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($apiKey === '') {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'missing_api_key', 'message' => 'أدخل API Key أولاً.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$list = ai_models_request($baseUrl . '/models', [
    'Authorization: Bearer ' . $apiKey,
    'Content-Type: application/json',
]);
if (!$list['ok']) {
    http_response_code(502);
    echo json_encode(['ok' => false, 'error' => (string)$list['error'], 'message' => 'تعذر قراءة الموديلات من المزود.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$models = [];
foreach ((array)($list['payload']['data'] ?? []) as $row) {
    $id = (string)($row['id'] ?? '');
    if ($id === '') {
        continue;
    }
    $models[] = [
        'id' => $id,
        'name' => $id,
        'status' => (string)($row['owned_by'] ?? 'available'),
        'usable' => ai_models_is_chat_usable($id),
    ];
}

echo json_encode(['ok' => true, 'provider' => $provider, 'models' => $models], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
exit;
