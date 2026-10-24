<?php
// Central licensing API endpoint.
require_once __DIR__ . '/config.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('X-Content-Type-Options: nosniff');
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$raw = file_get_contents('php://input');
$payload = json_decode((string)$raw, true);
if (!is_array($payload)) {
    $payload = [];
}
if (empty($payload)) {
    $formData = [];
    if (!empty($_POST) && is_array($_POST)) {
        $formData = $_POST;
    } elseif (is_string($raw) && $raw !== '' && strpos($raw, '=') !== false) {
        $parsedRaw = [];
        parse_str($raw, $parsedRaw);
        if (is_array($parsedRaw)) {
            $formData = $parsedRaw;
        }
    }
    if (!empty($formData)) {
        $candidatePayload = [];
        $payloadJson = trim((string)($formData['payload_json'] ?? ''));
        if ($payloadJson !== '') {
            $decoded = json_decode($payloadJson, true);
            if (is_array($decoded)) {
                $candidatePayload = $decoded;
            }
        }
        if (empty($candidatePayload)) {
            $payloadB64 = trim((string)($formData['payload_b64'] ?? ''));
            if ($payloadB64 !== '') {
                $decodedRaw = base64_decode($payloadB64, true);
                if (is_string($decodedRaw) && $decodedRaw !== '') {
                    $decoded = json_decode($decodedRaw, true);
                    if (is_array($decoded)) {
                        $candidatePayload = $decoded;
                    }
                }
            }
        }
        if (empty($candidatePayload)) {
            $payloadField = trim((string)($formData['payload'] ?? ''));
            if ($payloadField !== '') {
                $decoded = json_decode($payloadField, true);
                if (is_array($decoded)) {
                    $candidatePayload = $decoded;
                }
            }
        }
        if (empty($candidatePayload)) {
            $candidatePayload = $formData;
        }
        if (is_array($candidatePayload)) {
            $payload = $candidatePayload;
        }
    }
}

$authHeader = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? '');
$bearer = '';
if (stripos($authHeader, 'Bearer ') === 0) {
    $bearer = trim(substr($authHeader, 7));
}
if ($bearer === '') {
    $redirectAuth = (string)($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if (stripos($redirectAuth, 'Bearer ') === 0) {
        $bearer = trim(substr($redirectAuth, 7));
    }
}
if ($bearer === '') {
    $xAuth = trim((string)($_SERVER['HTTP_X_AUTHORIZATION'] ?? ''));
    if ($xAuth !== '') {
        $bearer = $xAuth;
    }
}
if ($bearer === '' && isset($payload['_auth'])) {
    $bearer = trim((string)$payload['_auth']);
}

$result = app_license_api_check($conn, $payload, $bearer);
$httpCode = (int)($result['http_code'] ?? 500);
$body = (isset($result['body']) && is_array($result['body'])) ? $result['body'] : ['ok' => false, 'error' => 'internal_error'];

http_response_code($httpCode);
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
