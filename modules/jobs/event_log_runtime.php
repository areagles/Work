<?php

declare(strict_types=1);

require_once __DIR__ . '/notification_runtime.php';

if (!function_exists('app_ensure_job_event_log_schema')) {
    function app_ensure_job_event_log_schema(mysqli $conn): void
    {
        static $ready = false;
        if ($ready) {
            return;
        }

        $conn->query("
            CREATE TABLE IF NOT EXISTS job_event_log (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                job_id INT NOT NULL,
                event_type VARCHAR(80) NOT NULL,
                stage_key VARCHAR(80) DEFAULT NULL,
                actor_user_id INT DEFAULT NULL,
                actor_name VARCHAR(190) NOT NULL DEFAULT '',
                title VARCHAR(255) NOT NULL,
                details TEXT DEFAULT NULL,
                payload_json LONGTEXT DEFAULT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_job_event_log_job (job_id),
                KEY idx_job_event_log_type (event_type),
                KEY idx_job_event_log_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $ready = true;
    }
}

if (!function_exists('job_event_log_add')) {
    function job_event_log_add(
        mysqli $conn,
        int $jobId,
        string $eventType,
        string $title,
        string $details = '',
        array $payload = [],
        string $stageKey = '',
        ?int $actorUserId = null,
        ?string $actorName = null
    ): bool {
        if ($jobId <= 0 || trim($eventType) === '' || trim($title) === '') {
            return false;
        }

        app_ensure_job_event_log_schema($conn);

        $payloadJson = !empty($payload) ? json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
        $stageValue = trim($stageKey) !== '' ? trim($stageKey) : null;
        $detailsValue = trim($details) !== '' ? trim($details) : null;
        $payloadValue = $payloadJson !== false ? $payloadJson : null;
        $actorUserValue = ($actorUserId !== null && $actorUserId > 0) ? $actorUserId : null;
        $resolvedActorName = $actorName !== null ? trim($actorName) : trim((string)($_SESSION['name'] ?? $_SESSION['full_name'] ?? 'System'));
        if ($resolvedActorName === '') {
            $resolvedActorName = 'System';
        }

        $stmt = $conn->prepare("
            INSERT INTO job_event_log (job_id, event_type, stage_key, actor_user_id, actor_name, title, details, payload_json)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        if (!$stmt) {
            return false;
        }
        $eventType = trim($eventType);
        $title = trim($title);
        $stmt->bind_param('ississss', $jobId, $eventType, $stageValue, $actorUserValue, $resolvedActorName, $title, $detailsValue, $payloadValue);
        $ok = $stmt->execute();
        $stmt->close();
        if ($ok && function_exists('job_event_dispatch_notification')) {
            job_event_dispatch_notification(
                $conn,
                $jobId,
                $eventType,
                $title,
                $detailsValue !== null ? $detailsValue : $title,
                (int)($actorUserValue ?? 0)
            );
        }
        return $ok;
    }
}

if (!function_exists('job_event_log_rows')) {
    function job_event_log_rows(mysqli $conn, int $jobId, int $limit = 40): array
    {
        if ($jobId <= 0) {
            return [];
        }
        app_ensure_job_event_log_schema($conn);
        $rows = [];
        $stmt = $conn->prepare("
            SELECT id, event_type, stage_key, actor_user_id, actor_name, title, details, payload_json, created_at
            FROM job_event_log
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
            $payload = [];
            if (!empty($row['payload_json'])) {
                $decoded = json_decode((string)$row['payload_json'], true);
                if (is_array($decoded)) {
                    $payload = $decoded;
                }
            }
            $row['payload'] = $payload;
            $rows[] = $row;
        }
        $stmt->close();
        return $rows;
    }
}

if (!function_exists('job_event_type_label')) {
    function job_event_type_label(string $eventType, bool $isEnglish = false): string
    {
        $eventType = strtolower(trim($eventType));
        $labels = [
            'job_created' => ['إنشاء العملية', 'Job created'],
            'job_updated' => ['تعديل بيانات العملية', 'Job updated'],
            'job_materials_rebuilt' => ['إعادة بناء خامات العملية', 'Job materials rebuilt'],
            'assignment_added' => ['إسناد عضو', 'Assignment added'],
            'assignment_removed' => ['إلغاء إسناد عضو', 'Assignment removed'],
            'stage_changed' => ['تغيير المرحلة', 'Stage changed'],
            'material_issued' => ['صرف خامة', 'Material issued'],
            'material_reversed' => ['عكس صرف خامة', 'Material reversed'],
            'service_cost_added' => ['إضافة تكلفة خدمة', 'Service cost added'],
            'service_cost_updated' => ['تعديل تكلفة خدمة', 'Service cost updated'],
            'service_cost_deleted' => ['حذف تكلفة خدمة', 'Service cost deleted'],
            'attachment_uploaded' => ['رفع مرفق', 'Attachment uploaded'],
            'attachment_deleted' => ['حذف مرفق', 'Attachment deleted'],
            'comment_added' => ['تعليق داخلي', 'Internal comment'],
        ];
        if (!isset($labels[$eventType])) {
            return $eventType !== '' ? $eventType : ($isEnglish ? 'Event' : 'حدث');
        }
        return $isEnglish ? $labels[$eventType][1] : $labels[$eventType][0];
    }
}

if (!function_exists('job_event_type_class')) {
    function job_event_type_class(string $eventType): string
    {
        $eventType = strtolower(trim($eventType));
        if (in_array($eventType, ['job_created', 'assignment_added', 'service_cost_added', 'attachment_uploaded', 'comment_added'], true)) {
            return 'positive';
        }
        if (in_array($eventType, ['assignment_removed', 'service_cost_deleted', 'attachment_deleted', 'material_reversed'], true)) {
            return 'warning';
        }
        if (in_array($eventType, ['stage_changed', 'job_updated', 'job_materials_rebuilt', 'service_cost_updated', 'material_issued'], true)) {
            return 'info';
        }
        return 'neutral';
    }
}
