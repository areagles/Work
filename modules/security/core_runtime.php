<?php

if (!function_exists('app_is_https')) {
    function app_is_https(): bool
    {
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            return true;
        }
        if (!empty($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443) {
            return true;
        }
        return false;
    }
}

if (!function_exists('app_runtime_host')) {
    function app_runtime_host(): string
    {
        return strtolower(trim((string)($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '')));
    }
}

if (!function_exists('app_is_work_runtime')) {
    function app_is_work_runtime(): bool
    {
        $host = app_runtime_host();
        if ($host === '') {
            return false;
        }
        return $host === 'work.areagles.com';
    }
}

if (!function_exists('app_start_session')) {
    function app_start_session(): void
    {
        if (session_status() !== PHP_SESSION_NONE) {
            return;
        }

        $host = $_SERVER['HTTP_HOST'] ?? '';
        $sessionName = trim((string)app_env('APP_SESSION_NAME', ''));
        if ($sessionName === '') {
            $hostKey = strtolower(trim((string)$host));
            if ($hostKey === '') {
                $hostKey = 'app';
            }
            $hostKey = preg_replace('/[^a-z0-9]+/i', '_', $hostKey);
            $sessionName = 'AESESS_' . substr(trim((string)$hostKey, '_'), 0, 24);
        }
        if ($sessionName !== '') {
            session_name($sessionName);
        }

        $cookieDomain = trim((string)app_env('APP_SESSION_DOMAIN', ''));
        $cookieParams = [
            'lifetime' => 0,
            'path' => '/',
            'secure' => app_is_https(),
            'httponly' => true,
            'samesite' => 'Lax',
        ];
        if ($cookieDomain !== '') {
            $cookieParams['domain'] = $cookieDomain;
        }

        if (PHP_VERSION_ID >= 70300) {
            session_set_cookie_params($cookieParams);
        } else {
            session_set_cookie_params(
                $cookieParams['lifetime'],
                $cookieParams['path'] . '; samesite=' . $cookieParams['samesite'],
                $cookieDomain,
                $cookieParams['secure'],
                $cookieParams['httponly']
            );
        }

        @ini_set('session.use_strict_mode', '1');
        @ini_set('session.use_only_cookies', '1');
        @ini_set('session.use_trans_sid', '0');
        @ini_set('session.cookie_httponly', '1');
        @ini_set('session.cookie_secure', app_is_https() ? '1' : '0');
        session_start();
    }
}

if (!function_exists('app_h')) {
    function app_h($value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('app_ensure_audit_log_schema')) {
    function app_ensure_audit_log_schema(mysqli $conn): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        @$conn->query("
            CREATE TABLE IF NOT EXISTS app_audit_log (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id INT DEFAULT NULL,
                actor_type VARCHAR(40) NOT NULL DEFAULT 'system',
                actor_name VARCHAR(190) NOT NULL DEFAULT '',
                action_key VARCHAR(120) NOT NULL DEFAULT '',
                entity_type VARCHAR(80) NOT NULL DEFAULT '',
                entity_key VARCHAR(190) NOT NULL DEFAULT '',
                tenant_id INT DEFAULT NULL,
                tenant_slug VARCHAR(190) DEFAULT NULL,
                ip_address VARCHAR(64) NOT NULL DEFAULT '',
                request_path VARCHAR(255) NOT NULL DEFAULT '',
                details_json LONGTEXT DEFAULT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_audit_action (action_key),
                KEY idx_audit_entity (entity_type, entity_key),
                KEY idx_audit_user (user_id),
                KEY idx_audit_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }
}

if (!function_exists('app_audit_log_add')) {
    function app_audit_log_add(mysqli $conn, string $actionKey, array $context = []): void
    {
        try {
            app_ensure_audit_log_schema($conn);
            $userId = isset($context['user_id']) ? (int)$context['user_id'] : (int)($_SESSION['user_id'] ?? $_SESSION['portal_client_id'] ?? $_SESSION['client_id'] ?? 0);
            $actorType = trim((string)($context['actor_type'] ?? (($userId > 0 && isset($_SESSION['user_id'])) ? 'user' : (($userId > 0) ? 'client' : 'system'))));
            $actorName = mb_substr(trim((string)($context['actor_name'] ?? ($_SESSION['name'] ?? $_SESSION['username'] ?? $_SESSION['portal_client_name'] ?? $_SESSION['client_name'] ?? 'system'))), 0, 190);
            $entityType = mb_substr(trim((string)($context['entity_type'] ?? '')), 0, 80);
            $entityKey = mb_substr(trim((string)($context['entity_key'] ?? '')), 0, 190);
            $tenantId = isset($context['tenant_id']) ? (int)$context['tenant_id'] : (int)($_SESSION['tenant_id'] ?? (function_exists('app_current_tenant_id') ? app_current_tenant_id() : 0));
            $tenantSlug = mb_substr(trim((string)($context['tenant_slug'] ?? ($_SESSION['tenant_slug'] ?? (function_exists('app_current_tenant_slug') ? app_current_tenant_slug() : '')))), 0, 190);
            $ipAddress = mb_substr(trim((string)($context['ip_address'] ?? ($_SERVER['REMOTE_ADDR'] ?? ''))), 0, 64);
            $requestPath = mb_substr(trim((string)($context['request_path'] ?? ($_SERVER['REQUEST_URI'] ?? $_SERVER['PHP_SELF'] ?? ''))), 0, 255);
            $details = $context['details'] ?? [];
            if (!is_array($details)) {
                $details = ['value' => $details];
            }
            $detailsJson = json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $actionKey = mb_substr(trim($actionKey), 0, 120);
            if ($actionKey === '') {
                return;
            }
            $stmt = $conn->prepare("
                INSERT INTO app_audit_log
                    (user_id, actor_type, actor_name, action_key, entity_type, entity_key, tenant_id, tenant_slug, ip_address, request_path, details_json)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            if (!$stmt) {
                return;
            }
            $userIdOrNull = $userId > 0 ? $userId : null;
            $tenantIdOrNull = $tenantId > 0 ? $tenantId : null;
            $stmt->bind_param(
                'isssssissss',
                $userIdOrNull,
                $actorType,
                $actorName,
                $actionKey,
                $entityType,
                $entityKey,
                $tenantIdOrNull,
                $tenantSlug,
                $ipAddress,
                $requestPath,
                $detailsJson
            );
            $stmt->execute();
            $stmt->close();
        } catch (Throwable $e) {
            error_log('app_audit_log_add failed: ' . $e->getMessage());
        }
    }
}

if (!function_exists('app_supported_languages')) {
    function app_supported_languages(): array
    {
        $langs = [
            'ar' => 'العربية',
            'en' => 'English',
        ];

        $translations = app_translations();
        foreach (array_keys($translations) as $code) {
            if (!isset($langs[$code])) {
                $label = $translations[$code]['lang.name'] ?? ucfirst($code);
                $langs[$code] = $label;
            }
        }

        return $langs;
    }
}

if (!function_exists('app_normalize_lang')) {
    function app_normalize_lang(string $lang): string
    {
        $lang = strtolower(trim($lang));
        $supported = app_supported_languages();
        return isset($supported[$lang]) ? $lang : 'ar';
    }
}

if (!function_exists('app_current_lang')) {
    function app_current_lang(?mysqli $conn = null): string
    {
        app_start_session();

        $sessionRaw = trim((string)($_SESSION['app_lang'] ?? ''));
        if ($sessionRaw !== '') {
            $sessionLang = app_normalize_lang($sessionRaw);
            $_SESSION['app_lang'] = $sessionLang;
            return $sessionLang;
        }

        if ($conn !== null && function_exists('app_setting_get')) {
            $dbLang = app_normalize_lang(app_setting_get($conn, 'app_lang', 'ar'));
            $_SESSION['app_lang'] = $dbLang;
            return $dbLang;
        }

        $envLang = app_normalize_lang((string)app_env('APP_LANG_DEFAULT', 'ar'));
        $_SESSION['app_lang'] = $envLang;
        return $envLang;
    }
}

if (!function_exists('app_lang_dir')) {
    function app_lang_dir(?string $lang = null): string
    {
        $lang = app_normalize_lang((string)($lang ?? app_current_lang()));
        return $lang === 'ar' ? 'rtl' : 'ltr';
    }
}

if (!function_exists('app_set_lang')) {
    function app_set_lang(string $lang, ?mysqli $conn = null, bool $persistDefault = false): string
    {
        app_start_session();
        $lang = app_normalize_lang($lang);
        $_SESSION['app_lang'] = $lang;

        if ($persistDefault && $conn !== null && function_exists('app_setting_set')) {
            app_setting_set($conn, 'app_lang', $lang);
        }

        return $lang;
    }
}

if (!function_exists('app_lang_switch_url')) {
    function app_lang_switch_url(string $lang): string
    {
        $lang = app_normalize_lang($lang);
        $uri = (string)($_SERVER['REQUEST_URI'] ?? basename((string)($_SERVER['PHP_SELF'] ?? 'dashboard.php')));
        if ($uri === '') {
            $uri = 'dashboard.php';
        }
        $parts = parse_url($uri);
        $path = (string)($parts['path'] ?? 'dashboard.php');
        parse_str((string)($parts['query'] ?? ''), $query);
        $query['set_lang'] = $lang;
        $qs = http_build_query($query);
        return $path . ($qs !== '' ? ('?' . $qs) : '');
    }
}

if (!function_exists('app_handle_lang_switch')) {
    function app_handle_lang_switch(?mysqli $conn = null): void
    {
        if (!isset($_GET['set_lang'])) {
            return;
        }
        $requested = app_normalize_lang((string)$_GET['set_lang']);
        app_set_lang($requested, $conn, false);

        $uri = (string)($_SERVER['REQUEST_URI'] ?? '');
        $parts = parse_url($uri);
        $path = (string)($parts['path'] ?? basename((string)($_SERVER['PHP_SELF'] ?? 'dashboard.php')));
        parse_str((string)($parts['query'] ?? ''), $query);
        unset($query['set_lang']);
        $qs = http_build_query($query);
        $target = $path . ($qs !== '' ? ('?' . $qs) : '');
        app_safe_redirect($target, 'dashboard.php');
    }
}

if (!function_exists('app_safe_redirect')) {
    function app_safe_redirect(string $path, string $fallback = 'dashboard.php'): void
    {
        $path = trim($path);
        if ($path === '' || preg_match('/^https?:\\/\\//i', $path)) {
            $path = $fallback;
        }
        if (strpos($path, "\n") !== false || strpos($path, "\r") !== false) {
            $path = $fallback;
        }
        if (
            function_exists('app_is_saas_gateway')
            && function_exists('app_current_tenant_id')
            && function_exists('app_current_tenant_slug')
            && app_is_saas_gateway()
            && app_current_tenant_id() > 0
            && !preg_match('/^https?:\\/\\//i', $path)
        ) {
            $parts = parse_url($path);
            $redirectPath = (string)($parts['path'] ?? $path);
            $query = [];
            parse_str((string)($parts['query'] ?? ''), $query);
            if (empty($query['tenant'])) {
                $tenantSlug = trim((string)app_current_tenant_slug());
                if ($tenantSlug !== '') {
                    $query['tenant'] = $tenantSlug;
                }
            }
            $path = $redirectPath;
            $qs = http_build_query($query);
            if ($qs !== '') {
                $path .= '?' . $qs;
            }
            if (!empty($parts['fragment'])) {
                $path .= '#' . $parts['fragment'];
            }
        }
        header('Location: ' . $path);
        exit;
    }
}

if (!function_exists('app_csrf_token')) {
    function app_csrf_token(): string
    {
        app_start_session();
        if (empty($_SESSION['_csrf_token'])) {
            $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['_csrf_token'];
    }
}

if (!function_exists('app_csrf_input')) {
    function app_csrf_input(): string
    {
        return '<input type="hidden" name="_csrf_token" value="' . app_h(app_csrf_token()) . '">';
    }
}

if (!function_exists('app_csrf_field')) {
    function app_csrf_field(): string
    {
        return app_csrf_input();
    }
}

if (!function_exists('app_verify_csrf')) {
    function app_verify_csrf(?string $token): bool
    {
        app_start_session();
        if (!is_string($token) || $token === '' || empty($_SESSION['_csrf_token'])) {
            return false;
        }
        return hash_equals($_SESSION['_csrf_token'], $token);
    }
}

if (!function_exists('app_require_csrf')) {
    function app_require_csrf(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            return;
        }
        if (!app_verify_csrf($_POST['_csrf_token'] ?? '')) {
            http_response_code(419);
            die('Invalid CSRF token');
        }
    }
}
