<?php

declare(strict_types=1);

if (!function_exists('app_ensure_job_notifications_schema')) {
    function app_ensure_job_notifications_schema(mysqli $conn): void
    {
        static $ready = false;
        if ($ready) {
            return;
        }

        $conn->query("
            CREATE TABLE IF NOT EXISTS app_job_notifications (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                job_id INT NOT NULL,
                notif_type VARCHAR(80) NOT NULL,
                title VARCHAR(190) NOT NULL,
                message VARCHAR(255) NOT NULL,
                is_read TINYINT(1) NOT NULL DEFAULT 0,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_app_job_notifications_user (user_id),
                KEY idx_app_job_notifications_job (job_id),
                KEY idx_app_job_notifications_read (is_read),
                KEY idx_app_job_notifications_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $ready = true;
    }
}

if (!function_exists('app_job_notification_recipient_ids')) {
    function app_job_notification_recipient_ids(mysqli $conn, int $jobId, int $excludeUserId = 0): array
    {
        app_ensure_job_notifications_schema($conn);
        if ($jobId <= 0) {
            return [];
        }

        $ids = [];

        if (function_exists('app_job_assignments')) {
            foreach (app_job_assignments($conn, $jobId) as $assignment) {
                $uid = (int)($assignment['user_id'] ?? 0);
                if ($uid > 0 && $uid !== $excludeUserId) {
                    $ids[] = $uid;
                }
            }
        }

        $stmt = $conn->prepare("SELECT created_by_user_id FROM job_orders WHERE id = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('i', $jobId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $ownerId = (int)($row['created_by_user_id'] ?? 0);
            if ($ownerId > 0 && $ownerId !== $excludeUserId) {
                $ids[] = $ownerId;
            }
        }

        return array_values(array_unique(array_filter($ids, static fn ($id) => (int)$id > 0)));
    }
}

if (!function_exists('app_job_notify_users')) {
    function app_job_notify_users(mysqli $conn, array $userIds, int $jobId, string $type, string $title, string $message, int $excludeUserId = 0): int
    {
        app_ensure_job_notifications_schema($conn);
        $title = mb_substr(trim($title), 0, 190);
        $message = mb_substr(trim($message), 0, 255);
        $type = mb_substr(trim($type), 0, 80);
        if ($jobId <= 0 || $title === '' || $message === '' || $type === '') {
            return 0;
        }

        $done = 0;
        $stmt = $conn->prepare("
            INSERT INTO app_job_notifications (user_id, job_id, notif_type, title, message, is_read)
            VALUES (?, ?, ?, ?, ?, 0)
        ");
        if (!$stmt) {
            return 0;
        }

        foreach (array_values(array_unique($userIds)) as $uidRaw) {
            $uid = (int)$uidRaw;
            if ($uid <= 0 || ($excludeUserId > 0 && $uid === $excludeUserId)) {
                continue;
            }
            $stmt->bind_param('iisss', $uid, $jobId, $type, $title, $message);
            if ($stmt->execute()) {
                $done++;
            }
        }
        $stmt->close();
        return $done;
    }
}

if (!function_exists('app_job_notifications_unread_count')) {
    function app_job_notifications_unread_count(mysqli $conn, int $userId): int
    {
        app_ensure_job_notifications_schema($conn);
        if ($userId <= 0) {
            return 0;
        }
        $stmt = $conn->prepare("SELECT COUNT(*) AS c FROM app_job_notifications WHERE user_id = ? AND is_read = 0");
        if (!$stmt) {
            return 0;
        }
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: [];
        $stmt->close();
        return (int)($row['c'] ?? 0);
    }
}

if (!function_exists('app_job_notifications_recent')) {
    function app_job_notifications_recent(mysqli $conn, int $userId, int $limit = 12): array
    {
        app_ensure_job_notifications_schema($conn);
        if ($userId <= 0) {
            return [];
        }
        $limit = max(1, min(100, $limit));
        $stmt = $conn->prepare("
            SELECT id, user_id, job_id, notif_type, title, message, is_read, created_at
            FROM app_job_notifications
            WHERE user_id = ?
            ORDER BY id DESC
            LIMIT ?
        ");
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param('ii', $userId, $limit);
        $stmt->execute();
        $rows = [];
        $res = $stmt->get_result();
        while ($res && ($row = $res->fetch_assoc())) {
            $rows[] = $row;
        }
        $stmt->close();
        return $rows;
    }
}

if (!function_exists('app_job_notification_mark_read')) {
    function app_job_notification_mark_read(mysqli $conn, int $userId, int $notificationId): bool
    {
        app_ensure_job_notifications_schema($conn);
        if ($userId <= 0 || $notificationId <= 0) {
            return false;
        }
        $stmt = $conn->prepare("UPDATE app_job_notifications SET is_read = 1 WHERE id = ? AND user_id = ?");
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('ii', $notificationId, $userId);
        $ok = $stmt->execute();
        $stmt->close();
        return (bool)$ok;
    }
}

if (!function_exists('app_job_notifications_mark_all_read')) {
    function app_job_notifications_mark_all_read(mysqli $conn, int $userId): bool
    {
        app_ensure_job_notifications_schema($conn);
        if ($userId <= 0) {
            return false;
        }
        $stmt = $conn->prepare("UPDATE app_job_notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0");
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('i', $userId);
        $ok = $stmt->execute();
        $stmt->close();
        return (bool)$ok;
    }
}

if (!function_exists('job_event_dispatch_notification')) {
    function job_event_dispatch_notification(mysqli $conn, int $jobId, string $eventType, string $title, string $message, int $actorUserId = 0): int
    {
        $notifyTypes = [
            'stage_changed',
            'material_issued',
            'material_reversed',
            'service_cost_added',
            'service_cost_updated',
            'service_cost_deleted',
            'attachment_uploaded',
            'attachment_deleted',
            'assignment_added',
            'assignment_removed',
            'job_updated',
            'comment_added',
        ];
        if ($jobId <= 0 || !in_array(trim($eventType), $notifyTypes, true)) {
            return 0;
        }
        $recipientIds = app_job_notification_recipient_ids($conn, $jobId, $actorUserId);
        if (empty($recipientIds)) {
            return 0;
        }
        return app_job_notify_users($conn, $recipientIds, $jobId, $eventType, $title, $message, $actorUserId);
    }
}
