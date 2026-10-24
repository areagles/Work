<?php
// client_portal/api/bootstrap.php
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../modules/finance/receipts_runtime.php';

if (!defined('APP_CLIENT_SESSION_ID_KEY')) {
    define('APP_CLIENT_SESSION_ID_KEY', 'portal_client_id');
}
if (!defined('APP_CLIENT_SESSION_NAME_KEY')) {
    define('APP_CLIENT_SESSION_NAME_KEY', 'portal_client_name');
}
if (!defined('APP_CLIENT_SESSION_PHONE_KEY')) {
    define('APP_CLIENT_SESSION_PHONE_KEY', 'portal_client_phone');
}
if (!defined('APP_CLIENT_SESSION_EMAIL_KEY')) {
    define('APP_CLIENT_SESSION_EMAIL_KEY', 'portal_client_email');
}
if (!defined('APP_CLIENT_CSRF_TOKEN_KEY')) {
    define('APP_CLIENT_CSRF_TOKEN_KEY', 'portal_csrf_token');
}
if (!defined('APP_CLIENT_SESSION_FINGERPRINT_KEY')) {
    define('APP_CLIENT_SESSION_FINGERPRINT_KEY', 'portal_client_fingerprint');
}
if (!defined('APP_CLIENT_SESSION_LOGIN_AT_KEY')) {
    define('APP_CLIENT_SESSION_LOGIN_AT_KEY', 'portal_client_login_at');
}
if (!defined('APP_CLIENT_SESSION_LAST_ACTIVITY_KEY')) {
    define('APP_CLIENT_SESSION_LAST_ACTIVITY_KEY', 'portal_client_last_activity');
}
if (!defined('APP_CLIENT_SESSION_IDLE_TIMEOUT')) {
    define('APP_CLIENT_SESSION_IDLE_TIMEOUT', 4 * 60 * 60);
}

if (!function_exists('api_json')) {
    function api_json(array $data, int $statusCode = 200): void
    {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=UTF-8');
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            header('Pragma: no-cache');
        }
        http_response_code($statusCode);
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

if (!function_exists('api_rate_limit_or_fail')) {
    function api_rate_limit_or_fail(string $bucket, int $limit, int $windowSeconds, string $scope = ''): void
    {
        $state = app_rate_limit_check($bucket, app_rate_limit_client_key($scope), $limit, $windowSeconds);
        app_rate_limit_emit_headers($state);
        if (!empty($state['allowed'])) {
            return;
        }
        api_json(
            [
                'status' => 'error',
                'message' => 'Too Many Requests',
                'code' => 'rate_limited',
                'retry_after' => (int)($state['retry_after'] ?? 0),
            ],
            429
        );
    }
}

if (!function_exists('api_rate_limit_scope')) {
    function api_rate_limit_scope(string $suffix = ''): string
    {
        $clientId = (int)($_SESSION[APP_CLIENT_SESSION_ID_KEY] ?? $_SESSION['client_id'] ?? 0);
        $scope = $clientId > 0 ? ('client:' . $clientId) : 'guest';
        $suffix = trim($suffix);
        if ($suffix !== '') {
            $scope .= '|' . $suffix;
        }
        return $scope;
    }
}

if (!function_exists('api_start_session')) {
    function api_start_session(): void
    {
        app_start_session();
        if (empty($_SESSION[APP_CLIENT_CSRF_TOKEN_KEY]) || !is_string($_SESSION[APP_CLIENT_CSRF_TOKEN_KEY])) {
            $_SESSION[APP_CLIENT_CSRF_TOKEN_KEY] = bin2hex(random_bytes(32));
        }
    }
}

if (!function_exists('api_client_fingerprint')) {
    function api_client_fingerprint(): string
    {
        $ip = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
        $ua = trim((string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
        return hash('sha256', $ip . '|' . $ua);
    }
}

if (!function_exists('api_invalidate_client_session')) {
    function api_invalidate_client_session(string $reason = 'invalidated'): void
    {
        $clientId = (int)($_SESSION[APP_CLIENT_SESSION_ID_KEY] ?? $_SESSION['client_id'] ?? 0);
        $clientName = (string)($_SESSION[APP_CLIENT_SESSION_NAME_KEY] ?? $_SESSION['client_name'] ?? 'client');
        api_clear_client_session();
        if (isset($GLOBALS['conn']) && $GLOBALS['conn'] instanceof mysqli && $clientId > 0) {
            app_audit_log_add($GLOBALS['conn'], 'auth.client_api_session_invalidated', [
                'user_id' => $clientId,
                'actor_type' => 'client',
                'actor_name' => $clientName,
                'entity_type' => 'client',
                'entity_key' => (string)$clientId,
                'details' => ['reason' => $reason],
            ]);
        }
    }
}

if (!function_exists('api_csrf_token')) {
    function api_csrf_token(): string
    {
        api_start_session();
        return (string)($_SESSION[APP_CLIENT_CSRF_TOKEN_KEY] ?? '');
    }
}

if (!function_exists('api_current_client_id')) {
    function api_current_client_id(): int
    {
        api_start_session();
        $id = (int)($_SESSION[APP_CLIENT_SESSION_ID_KEY] ?? 0);
        if ($id > 0) {
            $expectedFingerprint = (string)($_SESSION[APP_CLIENT_SESSION_FINGERPRINT_KEY] ?? '');
            $currentFingerprint = api_client_fingerprint();
            if ($expectedFingerprint !== '' && !hash_equals($expectedFingerprint, $currentFingerprint)) {
                api_invalidate_client_session('fingerprint_mismatch');
                return 0;
            }

            $lastActivity = (int)($_SESSION[APP_CLIENT_SESSION_LAST_ACTIVITY_KEY] ?? 0);
            if ($lastActivity > 0 && (time() - $lastActivity) > APP_CLIENT_SESSION_IDLE_TIMEOUT) {
                api_invalidate_client_session('idle_timeout');
                return 0;
            }

            $_SESSION[APP_CLIENT_SESSION_LAST_ACTIVITY_KEY] = time();
            return $id;
        }
        // Backward compatibility for older portal session key.
        $legacyId = (int)($_SESSION['client_id'] ?? 0);
        if ($legacyId > 0) {
            $_SESSION[APP_CLIENT_SESSION_ID_KEY] = $legacyId;
            $_SESSION[APP_CLIENT_SESSION_NAME_KEY] = (string)($_SESSION['client_name'] ?? '');
            $_SESSION[APP_CLIENT_SESSION_PHONE_KEY] = (string)($_SESSION['client_phone'] ?? '');
            $_SESSION[APP_CLIENT_SESSION_EMAIL_KEY] = (string)($_SESSION['client_email'] ?? '');
            $_SESSION[APP_CLIENT_SESSION_FINGERPRINT_KEY] = api_client_fingerprint();
            $_SESSION[APP_CLIENT_SESSION_LOGIN_AT_KEY] = time();
            $_SESSION[APP_CLIENT_SESSION_LAST_ACTIVITY_KEY] = time();
        }
        return $legacyId;
    }
}

if (!function_exists('api_set_client_session')) {
    function api_set_client_session(array $client): void
    {
        api_start_session();
        $_SESSION[APP_CLIENT_SESSION_ID_KEY] = (int)($client['id'] ?? 0);
        $_SESSION[APP_CLIENT_SESSION_NAME_KEY] = (string)($client['name'] ?? '');
        $_SESSION[APP_CLIENT_SESSION_PHONE_KEY] = (string)($client['phone'] ?? '');
        $_SESSION[APP_CLIENT_SESSION_EMAIL_KEY] = (string)($client['email'] ?? '');
        $_SESSION[APP_CLIENT_SESSION_FINGERPRINT_KEY] = api_client_fingerprint();
        $_SESSION[APP_CLIENT_SESSION_LOGIN_AT_KEY] = time();
        $_SESSION[APP_CLIENT_SESSION_LAST_ACTIVITY_KEY] = time();

        // Backward compatibility for legacy portal pages/endpoints.
        $_SESSION['client_id'] = (int)($_SESSION[APP_CLIENT_SESSION_ID_KEY] ?? 0);
        $_SESSION['client_name'] = (string)($_SESSION[APP_CLIENT_SESSION_NAME_KEY] ?? '');
        $_SESSION['client_phone'] = (string)($_SESSION[APP_CLIENT_SESSION_PHONE_KEY] ?? '');
        $_SESSION['client_email'] = (string)($_SESSION[APP_CLIENT_SESSION_EMAIL_KEY] ?? '');
    }
}

if (!function_exists('api_clear_client_session')) {
    function api_clear_client_session(): void
    {
        api_start_session();
        unset(
            $_SESSION[APP_CLIENT_SESSION_ID_KEY],
            $_SESSION[APP_CLIENT_SESSION_NAME_KEY],
            $_SESSION[APP_CLIENT_SESSION_PHONE_KEY],
            $_SESSION[APP_CLIENT_SESSION_EMAIL_KEY],
            $_SESSION[APP_CLIENT_SESSION_FINGERPRINT_KEY],
            $_SESSION[APP_CLIENT_SESSION_LOGIN_AT_KEY],
            $_SESSION[APP_CLIENT_SESSION_LAST_ACTIVITY_KEY],
            $_SESSION['client_id'],
            $_SESSION['client_name'],
            $_SESSION['client_phone'],
            $_SESSION['client_email']
        );
    }
}

if (!function_exists('api_require_login')) {
    function api_require_login(): int
    {
        $clientId = api_current_client_id();
        if ($clientId <= 0) {
            api_json(['status' => 'error', 'message' => 'Unauthorized'], 401);
        }
        if (isset($GLOBALS['conn']) && $GLOBALS['conn'] instanceof mysqli) {
            $stmt = $GLOBALS['conn']->prepare('SELECT id, name, phone, email FROM clients WHERE id = ? LIMIT 1');
            if ($stmt) {
                $stmt->bind_param('i', $clientId);
                $stmt->execute();
                $res = $stmt->get_result();
                $row = $res ? $res->fetch_assoc() : null;
                $stmt->close();
                if (!$row) {
                    api_invalidate_client_session('client_missing');
                    api_json(['status' => 'error', 'message' => 'Unauthorized'], 401);
                }
                $_SESSION[APP_CLIENT_SESSION_NAME_KEY] = (string)($row['name'] ?? '');
                $_SESSION[APP_CLIENT_SESSION_PHONE_KEY] = (string)($row['phone'] ?? '');
                $_SESSION[APP_CLIENT_SESSION_EMAIL_KEY] = (string)($row['email'] ?? '');
                $_SESSION['client_name'] = (string)($_SESSION[APP_CLIENT_SESSION_NAME_KEY] ?? '');
                $_SESSION['client_phone'] = (string)($_SESSION[APP_CLIENT_SESSION_PHONE_KEY] ?? '');
                $_SESSION['client_email'] = (string)($_SESSION[APP_CLIENT_SESSION_EMAIL_KEY] ?? '');
            }
        }
        return $clientId;
    }
}

if (!function_exists('api_read_json')) {
    function api_read_json(): array
    {
        $raw = file_get_contents('php://input');
        if (!$raw) {
            return [];
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }
}

if (!function_exists('api_clean_text')) {
    function api_clean_text($value, int $maxLen = 255): string
    {
        $text = trim((string) ($value ?? ''));
        $text = strip_tags($text);
        $text = preg_replace('/[\x00-\x1F\x7F]/u', '', $text);
        if ($text === null) {
            $text = '';
        }
        $length = function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
        if ($length > $maxLen) {
            return function_exists('mb_substr') ? mb_substr($text, 0, $maxLen, 'UTF-8') : substr($text, 0, $maxLen);
        }
        return $text;
    }
}

if (!function_exists('api_client_financial_snapshot')) {
    function api_client_financial_snapshot(mysqli $conn, int $clientId): array
    {
        if ($clientId <= 0) {
            return [
                'opening_outstanding' => 0.0,
                'opening_credit' => 0.0,
                'invoice_due' => 0.0,
                'payment_credit' => 0.0,
                'net_balance' => 0.0,
                'total_sales' => 0.0,
                'total_paid' => 0.0,
            ];
        }

        if (function_exists('financeClientBalanceSnapshot')) {
            $snapshot = financeClientBalanceSnapshot($conn, $clientId);
            $settlement = function_exists('financeClientSettlementSummary')
                ? financeClientSettlementSummary($conn, $clientId)
                : ['settled_total' => 0.0];

            $stmtSales = $conn->prepare("
                SELECT IFNULL(SUM(total_amount), 0)
                FROM invoices
                WHERE client_id = ?
                  AND COALESCE(status, '') <> 'cancelled'
            ");
            $stmtSales->bind_param('i', $clientId);
            $stmtSales->execute();
            $totalSales = (float)($stmtSales->get_result()->fetch_row()[0] ?? 0);
            $stmtSales->close();

            return [
                'opening_outstanding' => round((float)($snapshot['opening_outstanding'] ?? 0), 2),
                'opening_credit' => round((float)($snapshot['opening_credit'] ?? 0), 2),
                'invoice_due' => round((float)($snapshot['invoice_due'] ?? 0), 2),
                'payment_credit' => round((float)($snapshot['receipt_credit'] ?? 0), 2),
                'net_balance' => round((float)($snapshot['net_balance'] ?? 0), 2),
                'total_sales' => round($totalSales, 2),
                'total_paid' => round((float)($settlement['settled_total'] ?? 0), 2),
            ];
        }

        $sql = "
            SELECT
                IFNULL(inv.total_sales, 0) AS total_sales,
                IFNULL(pay.total_paid, 0) AS total_paid,
                ROUND(
                    CASE
                        WHEN c.opening_balance > 0 THEN
                            GREATEST(
                                c.opening_balance
                                - IFNULL(opening_legacy.legacy_opening_paid, 0)
                                - IFNULL(opening_alloc.opening_applied, 0),
                                0
                            )
                        ELSE 0
                    END,
                    2
                ) AS opening_outstanding,
                ROUND(
                    CASE
                        WHEN c.opening_balance < 0 THEN ABS(c.opening_balance)
                        ELSE 0
                    END,
                    2
                ) AS opening_credit,
                ROUND(IFNULL(inv.invoice_due, 0), 2) AS invoice_due,
                ROUND(IFNULL(rc.receipt_credit, 0), 2) AS payment_credit,
                ROUND(
                    (
                        CASE
                            WHEN c.opening_balance > 0 THEN
                                GREATEST(
                                    c.opening_balance
                                    - IFNULL(opening_legacy.legacy_opening_paid, 0)
                                    - IFNULL(opening_alloc.opening_applied, 0),
                                    0
                                )
                            ELSE 0
                        END
                    )
                    + IFNULL(inv.invoice_due, 0)
                    - (
                        CASE
                            WHEN c.opening_balance < 0 THEN ABS(c.opening_balance)
                            ELSE 0
                        END
                        + IFNULL(rc.receipt_credit, 0)
                    ),
                    2
                ) AS net_balance
            FROM clients c
            LEFT JOIN (
                SELECT
                    client_id,
                    IFNULL(SUM(total_amount), 0) AS total_sales,
                    IFNULL(SUM(CASE WHEN IFNULL(remaining_amount, 0) > 0.00001 THEN remaining_amount ELSE 0 END), 0) AS invoice_due
                FROM invoices
                WHERE client_id = ?
                GROUP BY client_id
            ) inv ON inv.client_id = c.id
            LEFT JOIN (
                SELECT client_id, IFNULL(SUM(amount), 0) AS total_paid
                FROM financial_receipts
                WHERE type = 'in' AND client_id = ?
                GROUP BY client_id
            ) pay ON pay.client_id = c.id
            LEFT JOIN (
                SELECT r.client_id, IFNULL(SUM(a.amount), 0) AS opening_applied
                FROM financial_receipt_allocations a
                INNER JOIN financial_receipts r ON r.id = a.receipt_id
                WHERE r.type = 'in' AND a.allocation_type = 'client_opening' AND r.client_id = ?
                GROUP BY r.client_id
            ) opening_alloc ON opening_alloc.client_id = c.id
            LEFT JOIN (
                SELECT r.client_id, IFNULL(SUM(r.amount), 0) AS legacy_opening_paid
                FROM financial_receipts r
                LEFT JOIN (
                    SELECT receipt_id, COUNT(*) AS allocation_count
                    FROM financial_receipt_allocations
                    GROUP BY receipt_id
                ) ac ON ac.receipt_id = r.id
                WHERE r.type = 'in'
                  AND r.client_id = ?
                  AND LOWER(TRIM(IFNULL(r.category, ''))) IN ('opening_balance', 'client_opening')
                  AND (
                        TRIM(IFNULL(r.description, '')) LIKE 'تسوية رصيد أول المدة%'
                        OR LOWER(TRIM(IFNULL(r.description, ''))) LIKE '%opening balance%'
                      )
                  AND IFNULL(ac.allocation_count, 0) = 0
                GROUP BY r.client_id
            ) opening_legacy ON opening_legacy.client_id = c.id
            LEFT JOIN (
                SELECT
                    r.client_id,
                    IFNULL(SUM(
                        ROUND(
                            r.amount - CASE
                                WHEN IFNULL(r.invoice_id, 0) > 0 THEN r.amount
                                WHEN IFNULL(a.allocated_amount, 0) > 0 THEN IFNULL(a.allocated_amount, 0)
                                ELSE 0
                            END,
                            2
                        )
                    ), 0) AS receipt_credit
                FROM financial_receipts r
                LEFT JOIN (
                    SELECT receipt_id, IFNULL(SUM(amount), 0) AS allocated_amount
                    FROM financial_receipt_allocations
                    GROUP BY receipt_id
                ) a ON a.receipt_id = r.id
                WHERE r.type = 'in'
                  AND r.client_id = ?
                  AND LOWER(TRIM(IFNULL(r.category, ''))) NOT IN ('opening_balance', 'client_opening')
                  AND ROUND(
                        r.amount - CASE
                            WHEN IFNULL(r.invoice_id, 0) > 0 THEN r.amount
                            WHEN IFNULL(a.allocated_amount, 0) > 0 THEN IFNULL(a.allocated_amount, 0)
                            ELSE 0
                        END,
                        2
                      ) > 0.00001
                GROUP BY r.client_id
            ) rc ON rc.client_id = c.id
            WHERE c.id = ?
            LIMIT 1
        ";

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return [
                'opening_outstanding' => 0.0,
                'opening_credit' => 0.0,
                'invoice_due' => 0.0,
                'payment_credit' => 0.0,
                'net_balance' => 0.0,
                'total_sales' => 0.0,
                'total_paid' => 0.0,
            ];
        }
        $stmt->bind_param('iiiiii', $clientId, $clientId, $clientId, $clientId, $clientId, $clientId);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();

        $snapshot = [
            'opening_outstanding' => (float)($row['opening_outstanding'] ?? 0),
            'opening_credit' => (float)($row['opening_credit'] ?? 0),
            'invoice_due' => (float)($row['invoice_due'] ?? 0),
            'payment_credit' => (float)($row['payment_credit'] ?? 0),
            'net_balance' => (float)($row['net_balance'] ?? 0),
            'total_sales' => (float)($row['total_sales'] ?? 0),
            'total_paid' => (float)($row['total_paid'] ?? 0),
        ];

        foreach ($snapshot as $key => $value) {
            $snapshot[$key] = round((float)$value, 2);
        }

        return $snapshot;
    }
}

if (!function_exists('api_clean_phone')) {
    function api_clean_phone($value): string
    {
        return preg_replace('/[^0-9+]/', '', (string) ($value ?? '')) ?: '';
    }
}

if (!function_exists('api_require_post')) {
    function api_require_post(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            api_json(['status' => 'error', 'message' => 'Method Not Allowed'], 405);
        }
    }
}

if (!function_exists('api_require_csrf')) {
    function api_require_csrf(): void
    {
        api_start_session();
        $expected = (string)($_SESSION[APP_CLIENT_CSRF_TOKEN_KEY] ?? '');
        if ($expected === '') {
            api_json(['status' => 'error', 'message' => 'Invalid CSRF token', 'code' => 'csrf_missing'], 419);
        }

        $headerToken = '';
        if (isset($_SERVER['HTTP_X_CSRF_TOKEN'])) {
            $headerToken = trim((string)$_SERVER['HTTP_X_CSRF_TOKEN']);
        }
        $postToken = trim((string)($_POST['_csrf'] ?? ''));
        $provided = $headerToken !== '' ? $headerToken : $postToken;

        if ($provided === '' || !hash_equals($expected, $provided)) {
            api_json(['status' => 'error', 'message' => 'Invalid CSRF token', 'code' => 'csrf_invalid'], 419);
        }
    }
}

if (!function_exists('api_require_post_csrf')) {
    function api_require_post_csrf(): void
    {
        api_require_post();
        api_require_csrf();
    }
}

if (!function_exists('api_require_active_license')) {
    function api_require_active_license(mysqli $conn): void
    {
        if (app_license_edition() !== 'client') {
            return;
        }
        $license = app_license_status($conn, true);
        if (!empty($license['allowed'])) {
            return;
        }
        api_json(
            [
                'status' => 'error',
                'message' => app_tr('الترخيص غير مفعل حالياً.', 'License is inactive.'),
                'code' => 'license_inactive',
                'reason' => (string)($license['reason'] ?? 'locked'),
            ],
            423
        );
    }
}

if (!defined('APP_CLIENT_PORTAL_SKIP_LICENSE_GUARD')) {
    api_require_active_license($conn);
}
