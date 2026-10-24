<?php

if (!function_exists('app_rate_limit_client_key')) {
    function app_rate_limit_client_key(string $scope = ''): string
    {
        $parts = [];
        $ip = trim((string)($_SERVER['REMOTE_ADDR'] ?? 'cli'));
        $parts[] = $ip !== '' ? $ip : 'unknown';
        if ($scope !== '') {
            $parts[] = $scope;
        }
        $auth = trim((string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? $_SERVER['HTTP_X_API_TOKEN'] ?? ''));
        if ($auth !== '') {
            $parts[] = substr(sha1($auth), 0, 16);
        }
        return implode('|', $parts);
    }
}

if (!function_exists('app_rate_limit_state_dir')) {
    function app_rate_limit_state_dir(): string
    {
        $dir = __DIR__ . '/../../uploads/.rate_limits';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        return $dir;
    }
}

if (!function_exists('app_rate_limit_check')) {
    function app_rate_limit_check(string $bucket, string $clientKey, int $limit, int $windowSeconds): array
    {
        $limit = max(1, $limit);
        $windowSeconds = max(1, $windowSeconds);
        $bucket = preg_replace('/[^a-z0-9_\-\.]/i', '_', strtolower(trim($bucket)));
        $clientKey = sha1(trim($clientKey) !== '' ? $clientKey : 'anonymous');
        $file = rtrim(app_rate_limit_state_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $bucket . '_' . $clientKey . '.json';
        $now = time();
        $state = ['hits' => []];

        $fp = @fopen($file, 'c+');
        if ($fp === false) {
            return ['allowed' => true, 'limit' => $limit, 'remaining' => $limit - 1, 'retry_after' => 0];
        }

        try {
            if (!flock($fp, LOCK_EX)) {
                fclose($fp);
                return ['allowed' => true, 'limit' => $limit, 'remaining' => $limit - 1, 'retry_after' => 0];
            }
            rewind($fp);
            $raw = stream_get_contents($fp);
            if (is_string($raw) && trim($raw) !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded) && isset($decoded['hits']) && is_array($decoded['hits'])) {
                    $state = $decoded;
                }
            }

            $hits = [];
            foreach ((array)$state['hits'] as $ts) {
                $ts = (int)$ts;
                if ($ts > 0 && ($now - $ts) < $windowSeconds) {
                    $hits[] = $ts;
                }
            }

            $allowed = count($hits) < $limit;
            $retryAfter = 0;
            if ($allowed) {
                $hits[] = $now;
            } elseif (!empty($hits)) {
                $retryAfter = max(1, $windowSeconds - ($now - min($hits)));
            }

            $state['hits'] = $hits;
            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, (string)json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            fflush($fp);
            flock($fp, LOCK_UN);
            fclose($fp);

            return [
                'allowed' => $allowed,
                'limit' => $limit,
                'remaining' => max(0, $limit - count($hits)),
                'retry_after' => $retryAfter,
            ];
        } catch (Throwable $e) {
            @flock($fp, LOCK_UN);
            @fclose($fp);
            return ['allowed' => true, 'limit' => $limit, 'remaining' => $limit - 1, 'retry_after' => 0];
        }
    }
}

if (!function_exists('app_rate_limit_emit_headers')) {
    function app_rate_limit_emit_headers(array $state): void
    {
        if (headers_sent()) {
            return;
        }
        header('X-RateLimit-Limit: ' . (int)($state['limit'] ?? 0));
        header('X-RateLimit-Remaining: ' . max(0, (int)($state['remaining'] ?? 0)));
        $retryAfter = (int)($state['retry_after'] ?? 0);
        if ($retryAfter > 0) {
            header('Retry-After: ' . $retryAfter);
        }
    }
}
