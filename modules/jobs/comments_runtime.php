<?php

declare(strict_types=1);

require_once __DIR__ . '/event_log_runtime.php';

if (!function_exists('app_ensure_job_comments_schema')) {
    function app_ensure_job_comments_schema(mysqli $conn): void
    {
        static $ready = false;
        if ($ready) {
            return;
        }

        $conn->query("
            CREATE TABLE IF NOT EXISTS job_comments (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                job_id INT NOT NULL,
                user_id INT DEFAULT NULL,
                user_name VARCHAR(190) NOT NULL DEFAULT '',
                body TEXT NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_job_comments_job (job_id),
                KEY idx_job_comments_user (user_id),
                KEY idx_job_comments_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $ready = true;
    }
}

if (!function_exists('app_job_comments_schema_flags')) {
    function app_job_comments_schema_flags(mysqli $conn): array
    {
        static $flags = null;
        if (is_array($flags)) {
            return $flags;
        }

        $hasColumn = static function (string $table, string $column) use ($conn): bool {
            if (function_exists('app_table_has_column')) {
                return app_table_has_column($conn, $table, $column);
            }
            $safeTable = $conn->real_escape_string($table);
            $safeColumn = $conn->real_escape_string($column);
            $res = $conn->query("SHOW COLUMNS FROM `{$safeTable}` LIKE '{$safeColumn}'");
            return (bool)($res && $res->num_rows > 0);
        };

        $flags = [
            'has_user_id' => $hasColumn('job_comments', 'user_id'),
            'has_body' => $hasColumn('job_comments', 'body'),
            'has_comment' => $hasColumn('job_comments', 'comment'),
        ];

        return $flags;
    }
}

if (!function_exists('job_comment_add')) {
    function job_comment_add(mysqli $conn, int $jobId, int $userId, string $body, ?string $userName = null): bool
    {
        $body = trim($body);
        if ($jobId <= 0 || $body === '') {
            return false;
        }

        app_ensure_job_comments_schema($conn);
        $schema = app_job_comments_schema_flags($conn);
        $resolvedUserId = $userId > 0 ? $userId : null;
        $resolvedUserName = trim((string)($userName ?? $_SESSION['name'] ?? $_SESSION['full_name'] ?? 'User'));
        if ($resolvedUserName === '') {
            $resolvedUserName = 'User';
        }

        if (!empty($schema['has_user_id']) && !empty($schema['has_body'])) {
            $stmt = $conn->prepare("
                INSERT INTO job_comments (job_id, user_id, user_name, body)
                VALUES (?, ?, ?, ?)
            ");
            if (!$stmt) {
                return false;
            }
            $stmt->bind_param('iiss', $jobId, $resolvedUserId, $resolvedUserName, $body);
        } else {
            $commentColumn = !empty($schema['has_comment']) ? 'comment' : 'body';
            $stmt = $conn->prepare("
                INSERT INTO job_comments (job_id, user_name, {$commentColumn})
                VALUES (?, ?, ?)
            ");
            if (!$stmt) {
                return false;
            }
            $stmt->bind_param('iss', $jobId, $resolvedUserName, $body);
        }
        if (!$stmt) {
            return false;
        }
        $ok = $stmt->execute();
        $stmt->close();

        if ($ok && function_exists('job_event_log_add')) {
            job_event_log_add(
                $conn,
                $jobId,
                'comment_added',
                'تعليق جديد على العملية',
                mb_substr($body, 0, 240),
                ['comment_excerpt' => mb_substr($body, 0, 160)],
                '',
                $userId,
                $resolvedUserName
            );
        }

        return $ok;
    }
}

if (!function_exists('job_comment_rows')) {
    function job_comment_rows(mysqli $conn, int $jobId, int $limit = 80): array
    {
        if ($jobId <= 0) {
            return [];
        }
        app_ensure_job_comments_schema($conn);
        $schema = app_job_comments_schema_flags($conn);

        $limit = max(1, min(200, $limit));
        $rows = [];
        $bodyExpr = !empty($schema['has_body']) ? 'body' : (!empty($schema['has_comment']) ? 'comment AS body' : "'' AS body");
        $userIdExpr = !empty($schema['has_user_id']) ? 'user_id' : 'NULL AS user_id';
        $stmt = $conn->prepare("
            SELECT id, job_id, {$userIdExpr}, user_name, {$bodyExpr}, created_at
            FROM job_comments
            WHERE job_id = ?
            ORDER BY id DESC
            LIMIT ?
        ");
        if (!$stmt) {
            return $rows;
        }
        $stmt->bind_param('ii', $jobId, $limit);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($res && ($row = $res->fetch_assoc())) {
            $rows[] = $row;
        }
        $stmt->close();
        return $rows;
    }
}
