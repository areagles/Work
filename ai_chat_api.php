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

if (!function_exists('ai_chat_setting')) {
    function ai_chat_setting(mysqli $conn, string $key, string $default = ''): string
    {
        $dbValue = trim((string)app_setting_get($conn, $key, ''));
        if ($dbValue !== '') {
            return $dbValue;
        }
        $envMap = [
            'ai_provider' => 'AI_PROVIDER',
            'ai_enabled' => 'AI_ENABLED',
            'ai_api_key' => 'AI_API_KEY',
            'ai_model' => 'AI_MODEL',
            'ai_base_url' => 'AI_BASE_URL',
            'ai_openai_api_key' => 'OPENAI_API_KEY',
            'ai_openai_model' => 'OPENAI_MODEL',
            'ai_openai_base_url' => 'OPENAI_BASE_URL',
            'ai_openai_enabled' => 'OPENAI_ENABLED',
        ];
        if (isset($envMap[$key])) {
            return trim((string)app_env($envMap[$key], $default));
        }
        return $default;
    }
}

if (!function_exists('ai_chat_provider')) {
    function ai_chat_provider(mysqli $conn): string
    {
        $provider = strtolower(trim(ai_chat_setting($conn, 'ai_provider', 'ollama')));
        return in_array($provider, ['ollama', 'openai', 'gemini', 'openai_compatible'], true) ? $provider : 'ollama';
    }
}

if (!function_exists('ai_chat_api_key')) {
    function ai_chat_api_key(mysqli $conn): string
    {
        $provider = ai_chat_provider($conn);
        $generic = trim(ai_chat_setting($conn, 'ai_api_key', ''));
        if ($generic !== '') {
            return $generic;
        }
        if ($provider === 'ollama') {
            return 'ollama';
        }
        return trim(ai_chat_setting($conn, 'ai_openai_api_key', ''));
    }
}

if (!function_exists('ai_chat_base_url')) {
    function ai_chat_base_url(mysqli $conn): string
    {
        $provider = ai_chat_provider($conn);
        $generic = trim(ai_chat_setting($conn, 'ai_base_url', ''));
        if ($generic !== '') {
            return rtrim($generic, '/');
        }
        if ($provider === 'ollama') {
            return 'http://127.0.0.1:11434/v1';
        }
        if ($provider === 'gemini') {
            return 'https://generativelanguage.googleapis.com/v1beta/openai';
        }
        return 'https://api.openai.com/v1';
    }
}

if (!function_exists('ai_chat_model')) {
    function ai_chat_model(mysqli $conn): string
    {
        $provider = ai_chat_provider($conn);
        $generic = trim(ai_chat_setting($conn, 'ai_model', ''));
        if ($generic !== '') {
            return $generic;
        }
        if ($provider === 'ollama') {
            return 'llama3.1:8b';
        }
        if ($provider === 'gemini') {
            return 'gemini-3-flash-preview';
        }
        return 'gpt-5.4-mini';
    }
}

if (!function_exists('ai_chat_enabled')) {
    function ai_chat_enabled(mysqli $conn): bool
    {
        $provider = ai_chat_provider($conn);
        $enabledRaw = strtolower(trim(ai_chat_setting($conn, 'ai_enabled', ai_chat_setting($conn, 'ai_openai_enabled', '1'))));
        $enabled = !in_array($enabledRaw, ['0', 'false', 'off', 'no'], true);
        if (!$enabled) {
            return false;
        }
        if ($provider === 'ollama') {
            return trim(ai_chat_base_url($conn)) !== '';
        }
        return trim(ai_chat_api_key($conn)) !== '';
    }
}

if (!function_exists('ai_chat_history_key')) {
    function ai_chat_history_key(): string
    {
        $tenantId = (int)($_SESSION['tenant_id'] ?? 0);
        $userId = (int)($_SESSION['user_id'] ?? 0);
        return 'ai_chat_history_' . $tenantId . '_' . $userId;
    }
}

if (!function_exists('ai_chat_history_get')) {
    function ai_chat_history_get(): array
    {
        app_start_session();
        $history = $_SESSION[ai_chat_history_key()] ?? [];
        return is_array($history) ? $history : [];
    }
}

if (!function_exists('ai_chat_history_set')) {
    function ai_chat_history_set(array $history): void
    {
        app_start_session();
        $_SESSION[ai_chat_history_key()] = array_values($history);
    }
}

if (!function_exists('ai_chat_trim_history')) {
    function ai_chat_trim_history(array $history, int $maxMessages = 12): array
    {
        if (count($history) <= $maxMessages) {
            return $history;
        }
        return array_slice($history, -1 * $maxMessages);
    }
}

if (!function_exists('ai_chat_system_prompt')) {
    function ai_chat_system_prompt(mysqli $conn): string
    {
        $isEnglish = function_exists('app_current_lang') && app_current_lang($conn) === 'en';
        $appName = trim((string)app_setting_get($conn, 'app_name', 'Arab Eagles'));
        if ($isEnglish) {
            return 'You are the internal AI assistant inside ' . $appName . '. Answer briefly, practically, and in the same language as the user. Focus on operations, ideas, design direction, planning, execution help, and concise next steps. Do not claim access to data you do not have.';
        }
        return 'أنت مساعد AI داخلي داخل نظام ' . $appName . '. أجب باختصار وبشكل عملي وبنفس لغة المستخدم. ركز على العمليات، والأفكار، واتجاهات التصميم، والخطط، والمساعدة التنفيذية، والخطوات التالية الواضحة. لا تدع امتلاك بيانات غير متاحة لك.';
    }
}

if (!function_exists('ai_chat_request_chat_completions')) {
    function ai_chat_request_chat_completions(mysqli $conn, array $history, string $message): array
    {
        $provider = ai_chat_provider($conn);
        $apiKey = ai_chat_api_key($conn);
        if ($apiKey === '' && $provider !== 'ollama') {
            return ['ok' => false, 'error' => 'missing_api_key'];
        }

        $messages = [[
            'role' => 'system',
            'content' => ai_chat_system_prompt($conn),
        ]];
        foreach ($history as $item) {
            $role = trim((string)($item['role'] ?? ''));
            $text = trim((string)($item['text'] ?? ''));
            if ($text === '' || !in_array($role, ['user', 'assistant'], true)) {
                continue;
            }
            $messages[] = [
                'role' => $role,
                'content' => $text,
            ];
        }
        $messages[] = [
            'role' => 'user',
            'content' => $message,
        ];

        $headers = ['Content-Type: application/json'];
        if ($apiKey !== '') {
            $headers[] = 'Authorization: Bearer ' . $apiKey;
        }

        $body = [
            'model' => ai_chat_model($conn),
            'messages' => $messages,
            'stream' => false,
            'max_tokens' => 700,
        ];

        $ch = curl_init(ai_chat_base_url($conn) . '/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 90,
        ]);
        $raw = curl_exec($ch);
        $err = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false || $err !== '') {
            return ['ok' => false, 'error' => $err !== '' ? $err : 'request_failed'];
        }
        $payload = json_decode($raw, true);
        if (!is_array($payload)) {
            return ['ok' => false, 'error' => 'invalid_json'];
        }
        if ($code < 200 || $code >= 300) {
            return ['ok' => false, 'error' => trim((string)($payload['error']['message'] ?? ($provider . '_error')))];
        }
        $text = trim((string)($payload['choices'][0]['message']['content'] ?? ''));
        if ($text === '') {
            return ['ok' => false, 'error' => 'empty_response'];
        }
        return ['ok' => true, 'text' => $text, 'model' => (string)$body['model']];
    }
}

$action = trim((string)($_POST['action'] ?? 'message'));
if ($action === 'reset') {
    ai_chat_history_set([]);
    echo json_encode(['ok' => true, 'message' => 'تم تصفير المحادثة.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (!ai_chat_enabled($conn)) {
    $provider = ai_chat_provider($conn);
    http_response_code(503);
    echo json_encode([
        'ok' => false,
        'error' => 'ai_not_configured',
        'message' => $provider === 'ollama'
            ? 'Ollama غير مفعّل بعد. شغّل Ollama واضبط AI Base URL والموديل أولاً.'
            : 'مزود AI غير مفعّل. أضف API Key أو فعّل مزودًا آخر أولاً.',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$message = trim((string)($_POST['message'] ?? ''));
if ($message === '') {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'empty_message'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$history = ai_chat_trim_history(ai_chat_history_get(), 12);
$provider = ai_chat_provider($conn);
$result = ai_chat_request_chat_completions($conn, $history, $message);
if (empty($result['ok'])) {
    http_response_code(502);
    echo json_encode([
        'ok' => false,
        'error' => (string)($result['error'] ?? 'ai_failed'),
        'message' => 'تعذر الحصول على رد من مزود AI.',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$history[] = ['role' => 'user', 'text' => $message];
$history[] = ['role' => 'assistant', 'text' => (string)$result['text']];
ai_chat_history_set(ai_chat_trim_history($history, 12));

echo json_encode([
    'ok' => true,
    'message' => 'تم استلام الرد.',
    'reply' => (string)$result['text'],
    'model' => (string)($result['model'] ?? ''),
    'provider' => $provider,
    'history' => ai_chat_trim_history($history, 12),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
exit;
