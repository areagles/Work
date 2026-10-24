<?php

if (!function_exists('app_saas_log_operation')) {
    function app_saas_log_operation(mysqli $controlConn, string $actionCode, string $actionLabel, int $tenantId = 0, array $context = [], string $actorName = ''): void
    {
        $actionCode = substr(trim($actionCode), 0, 80);
        $actionLabel = mb_substr(trim($actionLabel), 0, 190);
        if ($actionCode === '') {
            return;
        }
        if ($actorName === '') {
            $actorName = trim((string)($_SESSION['name'] ?? $_SESSION['username'] ?? 'System'));
        }
        $actorName = mb_substr($actorName !== '' ? $actorName : 'System', 0, 190);
        $tenantId = max(0, $tenantId);
        $contextJson = !empty($context)
            ? json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : null;

        if ($tenantId > 0) {
            $stmt = $controlConn->prepare("
                INSERT INTO saas_operation_log (tenant_id, action_code, action_label, actor_name, context_json)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->bind_param('issss', $tenantId, $actionCode, $actionLabel, $actorName, $contextJson);
        } else {
            $stmt = $controlConn->prepare("
                INSERT INTO saas_operation_log (tenant_id, action_code, action_label, actor_name, context_json)
                VALUES (NULL, ?, ?, ?, ?)
            ");
            $stmt->bind_param('ssss', $actionCode, $actionLabel, $actorName, $contextJson);
        }
        $stmt->execute();
        $stmt->close();
    }
}

if (!function_exists('app_saas_recent_operations')) {
    function app_saas_recent_operations(mysqli $controlConn, int $limit = 40): array
    {
        $limit = max(1, min(200, $limit));
        $rows = [];
        $sql = "
            SELECT l.*, t.tenant_slug, t.tenant_name
            FROM saas_operation_log l
            LEFT JOIN saas_tenants t ON t.id = l.tenant_id
            ORDER BY l.id DESC
            LIMIT {$limit}
        ";
        $res = $controlConn->query($sql);
        while ($res instanceof mysqli_result && ($row = $res->fetch_assoc())) {
            $rows[] = $row;
        }
        if ($res instanceof mysqli_result) {
            $res->close();
        }
        return $rows;
    }
}

if (!function_exists('app_saas_recent_webhook_deliveries')) {
    function app_saas_recent_webhook_deliveries(mysqli $controlConn, int $limit = 40): array
    {
        $limit = max(1, min(300, $limit));
        $rows = [];
        $sql = "
            SELECT d.*, t.tenant_slug, t.tenant_name
            FROM saas_webhook_deliveries d
            LEFT JOIN saas_tenants t ON t.id = d.tenant_id
            ORDER BY d.id DESC
            LIMIT ?
        ";
        $stmt = $controlConn->prepare($sql);
        $stmt->bind_param('i', $limit);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $rows[] = $row;
        }
        $stmt->close();
        return $rows;
    }
}

if (!function_exists('app_saas_webhook_test_receiver_url')) {
    function app_saas_webhook_test_receiver_url(): string
    {
        return rtrim(app_base_url(), '/') . '/saas_webhook_test_receiver.php';
    }
}

if (!function_exists('app_saas_store_webhook_test_inbox')) {
    function app_saas_store_webhook_test_inbox(mysqli $controlConn, array $headers, array $payload, string $rawBody = ''): int
    {
        $sourceIp = mb_substr(trim((string)($_SERVER['REMOTE_ADDR'] ?? '')), 0, 80);
        $requestMethod = mb_substr(strtoupper(trim((string)($_SERVER['REQUEST_METHOD'] ?? 'POST'))), 0, 12);
        $queryString = mb_substr(trim((string)($_SERVER['QUERY_STRING'] ?? '')), 0, 255);
        $headersJson = json_encode($headers, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $rawBody = (string)$rawBody;

        $stmt = $controlConn->prepare("
            INSERT INTO saas_webhook_test_inbox
                (source_ip, request_method, query_string, headers_json, payload_json, raw_body)
            VALUES
                (?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param('ssssss', $sourceIp, $requestMethod, $queryString, $headersJson, $payloadJson, $rawBody);
        $stmt->execute();
        $id = (int)$stmt->insert_id;
        $stmt->close();
        return $id;
    }
}

if (!function_exists('app_saas_recent_webhook_test_inbox')) {
    function app_saas_recent_webhook_test_inbox(mysqli $controlConn, int $limit = 30): array
    {
        $limit = max(1, min(200, $limit));
        $rows = [];
        $stmt = $controlConn->prepare("
            SELECT *
            FROM saas_webhook_test_inbox
            ORDER BY id DESC
            LIMIT ?
        ");
        $stmt->bind_param('i', $limit);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $rows[] = $row;
        }
        $stmt->close();
        return $rows;
    }
}

if (!function_exists('saas_webhook_retry_delay_seconds')) {
    function saas_webhook_retry_delay_seconds(int $attemptCount): int
    {
        $schedule = [
            1 => 300,
            2 => 900,
            3 => 3600,
            4 => 21600,
            5 => 86400,
        ];
        return $schedule[$attemptCount] ?? 86400;
    }
}

if (!function_exists('saas_webhook_due_retry_at')) {
    function saas_webhook_due_retry_at(int $attemptCount): string
    {
        return date('Y-m-d H:i:s', time() + saas_webhook_retry_delay_seconds($attemptCount));
    }
}

if (!function_exists('app_saas_cleanup_operation_log')) {
    function app_saas_cleanup_operation_log(mysqli $controlConn, int $keepLatest = 1000, int $olderThanDays = 90): array
    {
        $keepLatest = max(100, min(50000, $keepLatest));
        $olderThanDays = max(1, min(3650, $olderThanDays));
        $deleted = 0;

        $cutoffDate = date('Y-m-d H:i:s', strtotime('-' . $olderThanDays . ' days'));
        $keepThresholdId = 0;
        $offset = max(0, $keepLatest - 1);
        $thresholdSql = "
            SELECT id
            FROM saas_operation_log
            ORDER BY id DESC
            LIMIT {$offset}, 1
        ";
        $thresholdRes = $controlConn->query($thresholdSql);
        if ($thresholdRes && ($thresholdRow = $thresholdRes->fetch_assoc())) {
            $keepThresholdId = (int)($thresholdRow['id'] ?? 0);
        }
        if ($thresholdRes instanceof mysqli_result) {
            $thresholdRes->close();
        }

        if ($keepThresholdId > 0) {
            $stmt = $controlConn->prepare("
                DELETE FROM saas_operation_log
                WHERE id < ?
                  AND created_at < ?
            ");
            $stmt->bind_param('is', $keepThresholdId, $cutoffDate);
        } else {
            $stmt = $controlConn->prepare("
                DELETE FROM saas_operation_log
                WHERE created_at < ?
            ");
            $stmt->bind_param('s', $cutoffDate);
        }
        $stmt->execute();
        $deleted = (int)$stmt->affected_rows;
        $stmt->close();

        return [
            'deleted' => $deleted,
            'keep_latest' => $keepLatest,
            'older_than_days' => $olderThanDays,
            'cutoff_date' => $cutoffDate,
        ];
    }
}

if (!function_exists('app_saas_cleanup_operation_log_for_tenant')) {
    function app_saas_cleanup_operation_log_for_tenant(mysqli $controlConn, int $tenantId, int $keepLatest = 1000, int $olderThanDays = 90): array
    {
        $tenantId = max(0, $tenantId);
        if ($tenantId <= 0) {
            return [
                'tenant_id' => 0,
                'deleted' => 0,
                'keep_latest' => $keepLatest,
                'older_than_days' => $olderThanDays,
            ];
        }

        $keepLatest = max(100, min(50000, $keepLatest));
        $olderThanDays = max(1, min(3650, $olderThanDays));
        $deleted = 0;
        $cutoffDate = date('Y-m-d H:i:s', strtotime('-' . $olderThanDays . ' days'));
        $keepThresholdId = 0;
        $offset = max(0, $keepLatest - 1);

        $stmtThreshold = $controlConn->prepare("
            SELECT id
            FROM saas_operation_log
            WHERE tenant_id = ?
            ORDER BY id DESC
            LIMIT {$offset}, 1
        ");
        $stmtThreshold->bind_param('i', $tenantId);
        $stmtThreshold->execute();
        $thresholdRow = $stmtThreshold->get_result()->fetch_assoc();
        $stmtThreshold->close();
        if ($thresholdRow) {
            $keepThresholdId = (int)($thresholdRow['id'] ?? 0);
        }

        if ($keepThresholdId > 0) {
            $stmt = $controlConn->prepare("
                DELETE FROM saas_operation_log
                WHERE tenant_id = ?
                  AND id < ?
                  AND created_at < ?
            ");
            $stmt->bind_param('iis', $tenantId, $keepThresholdId, $cutoffDate);
        } else {
            $stmt = $controlConn->prepare("
                DELETE FROM saas_operation_log
                WHERE tenant_id = ?
                  AND created_at < ?
            ");
            $stmt->bind_param('is', $tenantId, $cutoffDate);
        }
        $stmt->execute();
        $deleted = (int)$stmt->affected_rows;
        $stmt->close();

        return [
            'tenant_id' => $tenantId,
            'deleted' => $deleted,
            'keep_latest' => $keepLatest,
            'older_than_days' => $olderThanDays,
            'cutoff_date' => $cutoffDate,
        ];
    }
}

if (!function_exists('app_saas_cleanup_operation_log_with_policies')) {
    function app_saas_cleanup_operation_log_with_policies(mysqli $controlConn, int $defaultKeepLatest = 1000, int $defaultOlderThanDays = 90): array
    {
        $defaultKeepLatest = max(100, min(50000, $defaultKeepLatest));
        $defaultOlderThanDays = max(1, min(3650, $defaultOlderThanDays));
        $tenantRuns = 0;
        $tenantDeleted = 0;

        $res = $controlConn->query("SELECT id, ops_keep_latest, ops_keep_days FROM saas_tenants ORDER BY id ASC");
        while ($res instanceof mysqli_result && ($row = $res->fetch_assoc())) {
            $tenantId = (int)($row['id'] ?? 0);
            if ($tenantId <= 0) {
                continue;
            }
            $cleanup = app_saas_cleanup_operation_log_for_tenant(
                $controlConn,
                $tenantId,
                max(100, (int)($row['ops_keep_latest'] ?? $defaultKeepLatest)),
                max(1, (int)($row['ops_keep_days'] ?? $defaultOlderThanDays))
            );
            $tenantRuns++;
            $tenantDeleted += (int)($cleanup['deleted'] ?? 0);
        }
        if ($res instanceof mysqli_result) {
            $res->close();
        }

        $globalCleanup = app_saas_cleanup_operation_log($controlConn, $defaultKeepLatest, $defaultOlderThanDays);

        return [
            'deleted' => $tenantDeleted + (int)($globalCleanup['deleted'] ?? 0),
            'tenant_deleted' => $tenantDeleted,
            'tenant_runs' => $tenantRuns,
            'global_deleted' => (int)($globalCleanup['deleted'] ?? 0),
            'keep_latest' => $defaultKeepLatest,
            'older_than_days' => $defaultOlderThanDays,
        ];
    }
}
