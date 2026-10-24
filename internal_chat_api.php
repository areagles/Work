<?php
require 'config.php';
app_start_session();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

function ic_json(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function ic_datetime_text(string $value): string
{
    $ts = strtotime($value);
    if ($ts === false) {
        return '';
    }
    return date('Y-m-d H:i', $ts);
}

function ic_contact_preview(string $text, string $kind): string
{
    $text = trim($text);
    if ($text !== '') {
        return mb_substr($text, 0, 110);
    }

    $kind = strtolower(trim($kind));
    if ($kind === 'image') {
        return app_tr('📷 صورة', '📷 Image');
    }
    if ($kind === 'audio') {
        return app_tr('🎤 رسالة صوتية', '🎤 Voice message');
    }
    if ($kind === 'file') {
        return app_tr('📎 ملف مرفق', '📎 File attached');
    }
    return app_tr('لا توجد رسائل بعد.', 'No messages yet.');
}

function ic_attachment_url(string $path): string
{
    $path = trim(str_replace('\\\\', '/', $path));
    if ($path === '') {
        return '';
    }
    if (preg_match('#^https?://#i', $path) || strpos($path, '//') === 0) {
        return $path;
    }
    return '/' . ltrim($path, '/');
}

function ic_avatar_url(array $user): string
{
    $profile = trim((string)($user['profile_pic'] ?? ''));
    if ($profile !== '') {
        return $profile;
    }

    $name = trim((string)($user['full_name'] ?? $user['username'] ?? 'User'));
    if ($name === '') {
        $name = 'User';
    }
    return 'https://ui-avatars.com/api/?name=' . rawurlencode($name) . '&background=1f1f1f&color=fff';
}

function ic_normalize_filename(string $filename): string
{
    $name = trim($filename);
    if ($name === '') {
        return '';
    }
    $name = basename($name);
    $name = preg_replace('/[^\p{L}\p{N}\s._-]/u', '', $name);
    $name = preg_replace('/\s+/', ' ', (string)$name);
    $name = trim((string)$name);
    if ($name === '') {
        return '';
    }
    return mb_substr($name, 0, 180);
}

function ic_guess_attachment_kind(string $filename): string
{
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    $imageExt = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'];
    $audioExt = ['webm', 'ogg', 'oga', 'mp3', 'wav', 'm4a', 'aac', 'opus'];
    $fileExt = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'csv', 'zip', 'rar'];

    if (in_array($ext, $imageExt, true)) {
        return 'image';
    }
    if (in_array($ext, $audioExt, true)) {
        return 'audio';
    }
    if (in_array($ext, $fileExt, true)) {
        return 'file';
    }
    return 'none';
}

function ic_chat_boot(mysqli $conn): bool
{
    static $ok = null;
    if ($ok !== null) {
        return $ok;
    }

    try {
        app_ensure_internal_chat_schema($conn);
        $ok = true;
    } catch (Throwable $e) {
        error_log('ic_chat_boot failed: ' . $e->getMessage());
        $ok = false;
    }

    return $ok;
}

function ic_job_status_text(string $status): string
{
    $status = strtolower(trim($status));
    if (in_array($status, ['completed', 'delivered', 'done', 'closed', 'archived'], true)) {
        return app_tr('مكتملة', 'Completed');
    }
    if (in_array($status, ['cancelled', 'canceled', 'rejected'], true)) {
        return app_tr('ملغاة', 'Cancelled');
    }
    if (in_array($status, ['pending', 'new'], true)) {
        return app_tr('جديدة', 'New');
    }
    if (in_array($status, ['processing', 'in_progress', 'production'], true)) {
        return app_tr('قيد التنفيذ', 'In progress');
    }
    if ($status === '') {
        return app_tr('غير محددة', 'Unspecified');
    }
    return $status;
}

function ic_job_stage_text(mysqli $conn, string $stage, string $jobType = ''): string
{
    $stage = trim($stage);
    if ($stage === '') {
        return app_tr('غير محددة', 'Unspecified');
    }
    $jobType = trim($jobType);
    if ($jobType !== '' && function_exists('app_operation_workflow')) {
        $workflow = app_operation_workflow($conn, $jobType, []);
        if (isset($workflow[$stage]['label'])) {
            return (string)$workflow[$stage]['label'];
        }
    }
    $labels = [
        'pending' => app_tr('جديد', 'New'),
        'briefing' => app_tr('التجهيز', 'Briefing'),
        'design' => app_tr('التصميم', 'Design'),
        'design_review' => app_tr('مراجعة التصميم', 'Design review'),
        'client_rev' => app_tr('مراجعة العميل', 'Client review'),
        'materials' => app_tr('الخامات', 'Materials'),
        'pre_press' => 'CTP',
        'printing' => app_tr('الطباعة', 'Printing'),
        'finishing' => app_tr('التشطيب', 'Finishing'),
        'delivery' => app_tr('التسليم', 'Delivery'),
        'accounting' => app_tr('الحسابات', 'Accounting'),
        'completed' => app_tr('أرشيف', 'Archived'),
        'cancelled' => app_tr('ملغاة', 'Cancelled'),
    ];
    return $labels[$stage] ?? $stage;
}

function ic_user_info(mysqli $conn, int $userId): ?array
{
    if ($userId <= 0) {
        return null;
    }

    $stmt = $conn->prepare("SELECT id, full_name, username, role, COALESCE(profile_pic, '') AS profile_pic FROM users WHERE id = ? LIMIT 1");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();

    if (!$row) {
        return null;
    }

    $name = trim((string)($row['full_name'] ?? ''));
    if ($name === '') {
        $name = trim((string)($row['username'] ?? ''));
    }
    if ($name === '') {
        $name = app_tr('مستخدم', 'User') . ' #' . (int)$row['id'];
    }

    return [
        'id' => (int)$row['id'],
        'full_name' => $name,
        'role' => trim((string)($row['role'] ?? 'employee')),
        'avatar_url' => ic_avatar_url($row),
    ];
}

function ic_unread_direct_total(mysqli $conn, int $userId): int
{
    if ($userId <= 0) {
        return 0;
    }

    $stmt = $conn->prepare("SELECT COUNT(*) AS c FROM app_internal_chat_messages WHERE receiver_user_id = ? AND receiver_user_id <> 0 AND is_read = 0");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $count = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
    $stmt->close();
    return $count;
}

function ic_group_last_read_id(mysqli $conn, int $userId): int
{
    if ($userId <= 0) {
        return 0;
    }
    $stmt = $conn->prepare("SELECT last_read_message_id FROM app_internal_chat_group_reads WHERE user_id = ? LIMIT 1");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();
    return (int)($row['last_read_message_id'] ?? 0);
}

function ic_group_set_last_read_id(mysqli $conn, int $userId, int $messageId): void
{
    if ($userId <= 0 || $messageId < 0) {
        return;
    }

    $stmt = $conn->prepare("\n        INSERT INTO app_internal_chat_group_reads (user_id, last_read_message_id)\n        VALUES (?, ?)\n        ON DUPLICATE KEY UPDATE\n            last_read_message_id = GREATEST(last_read_message_id, VALUES(last_read_message_id)),\n            updated_at = CURRENT_TIMESTAMP\n    ");
    $stmt->bind_param('ii', $userId, $messageId);
    $stmt->execute();
    $stmt->close();
}

function ic_group_latest_message_id(mysqli $conn): int
{
    $res = $conn->query("SELECT IFNULL(MAX(id),0) AS mx FROM app_internal_chat_messages WHERE receiver_user_id = 0");
    $row = $res ? ($res->fetch_assoc() ?: []) : [];
    return (int)($row['mx'] ?? 0);
}

function ic_group_unread_count(mysqli $conn, int $userId): int
{
    if ($userId <= 0) {
        return 0;
    }

    $lastRead = ic_group_last_read_id($conn, $userId);
    $stmt = $conn->prepare("\n        SELECT COUNT(*) AS c\n        FROM app_internal_chat_messages\n        WHERE receiver_user_id = 0\n          AND id > ?\n          AND sender_user_id <> ?\n    ");
    $stmt->bind_param('ii', $lastRead, $userId);
    $stmt->execute();
    $count = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
    $stmt->close();
    return $count;
}

function ic_group_summary(mysqli $conn, int $userId): array
{
    $name = app_tr('الجروب العام', 'General Group');
    $stmt = $conn->prepare("\n        SELECT m.*, COALESCE(u.full_name, u.username, '') AS sender_name\n        FROM app_internal_chat_messages m\n        LEFT JOIN users u ON u.id = m.sender_user_id\n        WHERE m.receiver_user_id = 0\n        ORDER BY m.id DESC\n        LIMIT 1\n    ");
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();

    $preview = ic_contact_preview((string)($row['message_text'] ?? ''), (string)($row['attachment_kind'] ?? 'none'));

    return [
        'id' => 0,
        'name' => $name,
        'last_message_id' => (int)($row['id'] ?? 0),
        'last_message_preview' => $preview,
        'last_message_at' => ic_datetime_text((string)($row['created_at'] ?? '')),
        'unread_count' => ic_group_unread_count($conn, $userId),
    ];
}

function ic_contacts(mysqli $conn, int $userId, int $limit = 60): array
{
    if ($userId <= 0) {
        return [];
    }

    $limit = max(1, min(200, $limit));

    $sql = "
        SELECT
            u.id,
            COALESCE(u.full_name, u.username, '') AS full_name,
            COALESCE(u.username, '') AS username,
            COALESCE(u.role, 'employee') AS role,
            COALESCE(u.profile_pic, '') AS profile_pic,
            (
                SELECT m.id
                FROM app_internal_chat_messages m
                WHERE m.receiver_user_id <> 0
                  AND ((m.sender_user_id = ? AND m.receiver_user_id = u.id)
                   OR  (m.sender_user_id = u.id AND m.receiver_user_id = ?))
                ORDER BY m.id DESC
                LIMIT 1
            ) AS last_message_id,
            (
                SELECT COALESCE(m.message_text, '')
                FROM app_internal_chat_messages m
                WHERE m.receiver_user_id <> 0
                  AND ((m.sender_user_id = ? AND m.receiver_user_id = u.id)
                   OR  (m.sender_user_id = u.id AND m.receiver_user_id = ?))
                ORDER BY m.id DESC
                LIMIT 1
            ) AS last_message_text,
            (
                SELECT COALESCE(m.attachment_kind, 'none')
                FROM app_internal_chat_messages m
                WHERE m.receiver_user_id <> 0
                  AND ((m.sender_user_id = ? AND m.receiver_user_id = u.id)
                   OR  (m.sender_user_id = u.id AND m.receiver_user_id = ?))
                ORDER BY m.id DESC
                LIMIT 1
            ) AS last_attachment_kind,
            (
                SELECT m.created_at
                FROM app_internal_chat_messages m
                WHERE m.receiver_user_id <> 0
                  AND ((m.sender_user_id = ? AND m.receiver_user_id = u.id)
                   OR  (m.sender_user_id = u.id AND m.receiver_user_id = ?))
                ORDER BY m.id DESC
                LIMIT 1
            ) AS last_message_at,
            (
                SELECT COUNT(*)
                FROM app_internal_chat_messages m
                WHERE m.receiver_user_id = ?
                  AND m.receiver_user_id <> 0
                  AND m.sender_user_id = u.id
                  AND m.is_read = 0
            ) AS unread_count
        FROM users u
        WHERE u.id <> ?
        ORDER BY
            CASE WHEN last_message_at IS NULL THEN 1 ELSE 0 END,
            last_message_at DESC,
            u.full_name ASC,
            u.id ASC
        LIMIT ?
    ";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param(
        'iiiiiiiiiii',
        $userId,
        $userId,
        $userId,
        $userId,
        $userId,
        $userId,
        $userId,
        $userId,
        $userId,
        $userId,
        $limit
    );
    $stmt->execute();
    $res = $stmt->get_result();

    $rows = [];
    while ($res && ($row = $res->fetch_assoc())) {
        $fullName = trim((string)($row['full_name'] ?? ''));
        if ($fullName === '') {
            $fullName = trim((string)($row['username'] ?? ''));
        }
        if ($fullName === '') {
            $fullName = app_tr('مستخدم', 'User') . ' #' . (int)($row['id'] ?? 0);
        }

        $lastKind = strtolower(trim((string)($row['last_attachment_kind'] ?? 'none')));
        if (!in_array($lastKind, ['none', 'image', 'audio', 'file'], true)) {
            $lastKind = 'none';
        }

        $rows[] = [
            'id' => (int)($row['id'] ?? 0),
            'full_name' => $fullName,
            'role' => trim((string)($row['role'] ?? 'employee')),
            'avatar_url' => ic_avatar_url($row),
            'last_message_id' => (int)($row['last_message_id'] ?? 0),
            'last_message_preview' => ic_contact_preview((string)($row['last_message_text'] ?? ''), $lastKind),
            'last_message_at' => ic_datetime_text((string)($row['last_message_at'] ?? '')),
            'unread_count' => (int)($row['unread_count'] ?? 0),
        ];
    }
    $stmt->close();

    return $rows;
}

function ic_format_message_row(array $row, int $viewerId): array
{
    $senderId = (int)($row['sender_user_id'] ?? 0);
    $receiverId = (int)($row['receiver_user_id'] ?? 0);
    $text = trim((string)($row['message_text'] ?? ''));
    $kind = strtolower(trim((string)($row['attachment_kind'] ?? 'none')));
    if (!in_array($kind, ['none', 'image', 'audio', 'file'], true)) {
        $kind = 'none';
    }
    $attachmentPath = trim((string)($row['attachment_path'] ?? ''));
    $attachmentName = trim((string)($row['attachment_name'] ?? ''));
    if ($attachmentName === '' && $attachmentPath !== '') {
        $attachmentName = basename((string)parse_url($attachmentPath, PHP_URL_PATH));
    }

    return [
        'id' => (int)($row['id'] ?? 0),
        'sender_user_id' => $senderId,
        'receiver_user_id' => $receiverId,
        'sender_name' => trim((string)($row['sender_name'] ?? '')),
        'is_me' => ($senderId === $viewerId),
        'is_group' => ($receiverId === 0),
        'text' => $text,
        'attachment_kind' => $kind,
        'attachment_name' => $attachmentName,
        'attachment_url' => ic_attachment_url($attachmentPath),
        'created_at_text' => ic_datetime_text((string)($row['created_at'] ?? '')),
    ];
}

function ic_fetch_direct_messages(mysqli $conn, int $userId, int $peerId, int $sinceId = 0, int $limit = 120): array
{
    if ($userId <= 0 || $peerId <= 0 || $userId === $peerId) {
        return [];
    }

    $limit = max(1, min(300, $limit));
    $sinceId = max(0, $sinceId);

    if ($sinceId > 0) {
        $stmt = $conn->prepare("\n            SELECT m.*, COALESCE(u.full_name, u.username, '') AS sender_name\n            FROM app_internal_chat_messages m\n            LEFT JOIN users u ON u.id = m.sender_user_id\n            WHERE m.receiver_user_id <> 0\n              AND ((m.sender_user_id = ? AND m.receiver_user_id = ?) OR (m.sender_user_id = ? AND m.receiver_user_id = ?))\n              AND m.id > ?\n            ORDER BY m.id ASC\n            LIMIT ?\n        ");
        $stmt->bind_param('iiiiii', $userId, $peerId, $peerId, $userId, $sinceId, $limit);
        $stmt->execute();
        $res = $stmt->get_result();
        $rows = [];
        while ($res && ($row = $res->fetch_assoc())) {
            $rows[] = ic_format_message_row($row, $userId);
        }
        $stmt->close();
        return $rows;
    }

    $stmt = $conn->prepare("\n        SELECT m.*, COALESCE(u.full_name, u.username, '') AS sender_name\n        FROM app_internal_chat_messages m\n        LEFT JOIN users u ON u.id = m.sender_user_id\n        WHERE m.receiver_user_id <> 0\n          AND ((m.sender_user_id = ? AND m.receiver_user_id = ?) OR (m.sender_user_id = ? AND m.receiver_user_id = ?))\n        ORDER BY m.id DESC\n        LIMIT ?\n    ");
    $stmt->bind_param('iiiii', $userId, $peerId, $peerId, $userId, $limit);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = [];
    while ($res && ($row = $res->fetch_assoc())) {
        $rows[] = ic_format_message_row($row, $userId);
    }
    $stmt->close();

    return array_reverse($rows);
}

function ic_mark_direct_read(mysqli $conn, int $userId, int $peerId): void
{
    if ($userId <= 0 || $peerId <= 0 || $userId === $peerId) {
        return;
    }

    $stmt = $conn->prepare("\n        UPDATE app_internal_chat_messages\n        SET is_read = 1\n        WHERE receiver_user_id = ?\n          AND receiver_user_id <> 0\n          AND sender_user_id = ?\n          AND is_read = 0\n    ");
    $stmt->bind_param('ii', $userId, $peerId);
    $stmt->execute();
    $stmt->close();
}

function ic_fetch_group_messages(mysqli $conn, int $userId, int $sinceId = 0, int $limit = 120): array
{
    if ($userId <= 0) {
        return [];
    }

    $limit = max(1, min(300, $limit));
    $sinceId = max(0, $sinceId);

    if ($sinceId > 0) {
        $stmt = $conn->prepare("\n            SELECT m.*, COALESCE(u.full_name, u.username, '') AS sender_name\n            FROM app_internal_chat_messages m\n            LEFT JOIN users u ON u.id = m.sender_user_id\n            WHERE m.receiver_user_id = 0\n              AND m.id > ?\n            ORDER BY m.id ASC\n            LIMIT ?\n        ");
        $stmt->bind_param('ii', $sinceId, $limit);
        $stmt->execute();
        $res = $stmt->get_result();
        $rows = [];
        while ($res && ($row = $res->fetch_assoc())) {
            $rows[] = ic_format_message_row($row, $userId);
        }
        $stmt->close();
        return $rows;
    }

    $stmt = $conn->prepare("\n        SELECT m.*, COALESCE(u.full_name, u.username, '') AS sender_name\n        FROM app_internal_chat_messages m\n        LEFT JOIN users u ON u.id = m.sender_user_id\n        WHERE m.receiver_user_id = 0\n        ORDER BY m.id DESC\n        LIMIT ?\n    ");
    $stmt->bind_param('i', $limit);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = [];
    while ($res && ($row = $res->fetch_assoc())) {
        $rows[] = ic_format_message_row($row, $userId);
    }
    $stmt->close();

    return array_reverse($rows);
}

function ic_store_attachment(array $file): array
{
    $allowedExt = [
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp',
        'webm', 'ogg', 'oga', 'mp3', 'wav', 'm4a', 'aac', 'opus',
        'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'csv', 'zip', 'rar',
    ];

    $stored = app_store_uploaded_file($file, [
        'dir' => 'uploads/internal_chat',
        'prefix' => 'chat_',
        'max_size' => 15 * 1024 * 1024,
        'allowed_extensions' => $allowedExt,
    ]);

    if (empty($stored['ok'])) {
        return [
            'ok' => false,
            'error' => (string)($stored['error'] ?? 'upload_failed'),
            'path' => '',
            'name' => '',
            'kind' => 'none',
        ];
    }

    $path = trim((string)($stored['path'] ?? ''));
    $originalName = ic_normalize_filename((string)($file['name'] ?? ''));
    if ($originalName === '') {
        $originalName = basename($path);
    }

    $kind = ic_guess_attachment_kind($originalName);
    if ($kind === 'none') {
        app_safe_unlink($path, 'uploads/internal_chat');
        return [
            'ok' => false,
            'error' => app_tr('نوع الملف غير مدعوم.', 'Unsupported file type.'),
            'path' => '',
            'name' => '',
            'kind' => 'none',
        ];
    }

    return [
        'ok' => true,
        'error' => '',
        'path' => $path,
        'name' => $originalName,
        'kind' => $kind,
    ];
}

function ic_insert_message_raw(
    mysqli $conn,
    int $senderUserId,
    int $receiverUserId,
    string $messageText,
    string $attachmentPath,
    string $attachmentName,
    string $attachmentKind
): array {
    $senderUserId = (int)$senderUserId;
    $receiverUserId = (int)$receiverUserId;
    $messageText = trim($messageText);
    $attachmentPath = trim($attachmentPath);
    $attachmentName = trim($attachmentName);
    $attachmentKind = strtolower(trim($attachmentKind));

    if ($senderUserId <= 0 || $receiverUserId < 0) {
        return ['ok' => false, 'error' => 'invalid_input'];
    }
    if ($receiverUserId > 0 && $senderUserId === $receiverUserId) {
        return ['ok' => false, 'error' => 'invalid_peer'];
    }
    if (!in_array($attachmentKind, ['none', 'image', 'audio', 'file'], true)) {
        $attachmentKind = 'none';
    }
    if ($messageText === '' && $attachmentPath === '') {
        return ['ok' => false, 'error' => 'empty_message'];
    }

    $messageText = mb_substr($messageText, 0, 4000);

    $stmt = $conn->prepare("\n        INSERT INTO app_internal_chat_messages\n            (sender_user_id, receiver_user_id, message_text, attachment_path, attachment_name, attachment_kind, is_read)\n        VALUES (?, ?, ?, ?, ?, ?, 0)\n    ");
    $stmt->bind_param('iissss', $senderUserId, $receiverUserId, $messageText, $attachmentPath, $attachmentName, $attachmentKind);
    $ok = $stmt->execute();
    $insertId = (int)$stmt->insert_id;
    $stmt->close();

    if (!$ok || $insertId <= 0) {
        return ['ok' => false, 'error' => 'insert_failed'];
    }

    $stmtRead = $conn->prepare("\n        SELECT m.*, COALESCE(u.full_name, u.username, '') AS sender_name\n        FROM app_internal_chat_messages m\n        LEFT JOIN users u ON u.id = m.sender_user_id\n        WHERE m.id = ?\n        LIMIT 1\n    ");
    $stmtRead->bind_param('i', $insertId);
    $stmtRead->execute();
    $row = $stmtRead->get_result()->fetch_assoc() ?: [];
    $stmtRead->close();

    return [
        'ok' => true,
        'error' => '',
        'message' => ic_format_message_row($row, $senderUserId),
    ];
}

function ic_insert_direct_message(
    mysqli $conn,
    int $senderUserId,
    int $receiverUserId,
    string $messageText,
    string $attachmentPath,
    string $attachmentName,
    string $attachmentKind
): array {
    if ($receiverUserId <= 0) {
        return ['ok' => false, 'error' => 'invalid_peer'];
    }
    return ic_insert_message_raw($conn, $senderUserId, $receiverUserId, $messageText, $attachmentPath, $attachmentName, $attachmentKind);
}

function ic_insert_group_message(
    mysqli $conn,
    int $senderUserId,
    string $messageText,
    string $attachmentPath,
    string $attachmentName,
    string $attachmentKind
): array {
    $res = ic_insert_message_raw($conn, $senderUserId, 0, $messageText, $attachmentPath, $attachmentName, $attachmentKind);
    if (!empty($res['ok'])) {
        $messageId = (int)($res['message']['id'] ?? 0);
        if ($messageId > 0) {
            ic_group_set_last_read_id($conn, $senderUserId, $messageId);
        }
    }
    return $res;
}

function ic_job_latest_ts(mysqli $conn): int
{
    if (!app_table_has_column($conn, 'job_orders', 'updated_at')) {
        return time();
    }

    $visibility = app_job_visibility_clause($conn, 'j');
    $sql = "SELECT IFNULL(UNIX_TIMESTAMP(MAX(j.updated_at)), 0) AS ts FROM job_orders j WHERE ($visibility)";
    $res = $conn->query($sql);
    $row = $res ? ($res->fetch_assoc() ?: []) : [];
    $ts = (int)($row['ts'] ?? 0);
    return $ts > 0 ? $ts : time();
}

function ic_job_updates_since(mysqli $conn, int $sinceTs, int $limit = 20): array
{
    if ($sinceTs <= 0 || !app_table_has_column($conn, 'job_orders', 'updated_at')) {
        return [];
    }

    $visibility = app_job_visibility_clause($conn, 'j');
    $limit = max(1, min(100, $limit));

    $sql = "\n        SELECT\n            j.id,\n            j.job_name,\n            COALESCE(j.job_type, '') AS job_type,\n            COALESCE(j.status, '') AS status,\n            COALESCE(j.current_stage, '') AS current_stage,\n            UNIX_TIMESTAMP(j.updated_at) AS updated_ts,\n            j.updated_at\n        FROM job_orders j\n        WHERE ($visibility)\n          AND j.updated_at > FROM_UNIXTIME(?)\n        ORDER BY j.updated_at ASC\n        LIMIT ?\n    ";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ii', $sinceTs, $limit);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = [];
    while ($res && ($row = $res->fetch_assoc())) {
        $rows[] = [
            'job_id' => (int)($row['id'] ?? 0),
            'job_name' => trim((string)($row['job_name'] ?? '')),
            'status' => trim((string)($row['status'] ?? '')),
            'stage' => trim((string)($row['current_stage'] ?? '')),
            'status_text' => ic_job_status_text((string)($row['status'] ?? '')),
            'stage_text' => ic_job_stage_text($conn, (string)($row['current_stage'] ?? ''), (string)($row['job_type'] ?? '')),
            'updated_ts' => (int)($row['updated_ts'] ?? 0),
            'updated_at_text' => ic_datetime_text((string)($row['updated_at'] ?? '')),
        ];
    }
    $stmt->close();

    return $rows;
}

function ic_state_payload(mysqli $conn, int $userId, int $jobSinceTs = 0): array
{
    $contacts = ic_contacts($conn, $userId, 100);
    $group = ic_group_summary($conn, $userId);
    $unreadDirect = ic_unread_direct_total($conn, $userId);
    $unreadTotal = $unreadDirect + (int)($group['unread_count'] ?? 0);

    $latestJobTs = ic_job_latest_ts($conn);
    $jobUpdates = ic_job_updates_since($conn, $jobSinceTs, 25);
    foreach ($jobUpdates as $ev) {
        $latestJobTs = max($latestJobTs, (int)($ev['updated_ts'] ?? 0));
    }

    return [
        'contacts' => $contacts,
        'group' => $group,
        'unread_total' => $unreadTotal,
        'latest_job_ts' => $latestJobTs,
        'job_updates' => $jobUpdates,
    ];
}

$currentUserId = (int)($_SESSION['user_id'] ?? 0);
if ($currentUserId <= 0) {
    ic_json([
        'ok' => false,
        'error' => 'unauthorized',
        'message' => app_tr('يجب تسجيل الدخول أولاً.', 'Login required.'),
    ], 401);
}

if (!ic_chat_boot($conn)) {
    ic_json([
        'ok' => false,
        'error' => 'chat_init_failed',
        'message' => app_tr('تعذر تهيئة نظام الشات.', 'Could not initialize chat system.'),
    ], 500);
}

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$action = strtolower(trim((string)($_REQUEST['action'] ?? 'state')));

if ($action === 'state' && $method === 'GET') {
    $jobSinceTs = max(0, (int)($_GET['since_job_ts'] ?? 0));
    $state = ic_state_payload($conn, $currentUserId, $jobSinceTs);

    ic_json([
        'ok' => true,
        'action' => 'state',
        'current_user_id' => $currentUserId,
        'contacts' => $state['contacts'],
        'group' => $state['group'],
        'unread_total' => $state['unread_total'],
        'latest_job_ts' => $state['latest_job_ts'],
        'job_updates' => $state['job_updates'],
        'server_time' => date('c'),
    ]);
}

if ($action === 'thread' && $method === 'GET') {
    $peerId = (int)($_GET['peer_id'] ?? 0);
    $sinceId = max(0, (int)($_GET['since'] ?? 0));
    $jobSinceTs = max(0, (int)($_GET['since_job_ts'] ?? 0));

    if ($peerId <= 0 || $peerId === $currentUserId) {
        ic_json(['ok' => false, 'error' => 'invalid_peer'], 422);
    }

    $peer = ic_user_info($conn, $peerId);
    if (!$peer) {
        ic_json(['ok' => false, 'error' => 'peer_not_found'], 404);
    }

    ic_mark_direct_read($conn, $currentUserId, $peerId);
    $messages = ic_fetch_direct_messages($conn, $currentUserId, $peerId, $sinceId, 140);

    $lastMessageId = $sinceId;
    foreach ($messages as $msg) {
        $lastMessageId = max($lastMessageId, (int)($msg['id'] ?? 0));
    }

    $state = ic_state_payload($conn, $currentUserId, $jobSinceTs);

    ic_json([
        'ok' => true,
        'action' => 'thread',
        'peer' => $peer,
        'messages' => $messages,
        'last_message_id' => $lastMessageId,
        'contacts' => $state['contacts'],
        'group' => $state['group'],
        'unread_total' => $state['unread_total'],
        'latest_job_ts' => $state['latest_job_ts'],
        'job_updates' => $state['job_updates'],
        'server_time' => date('c'),
    ]);
}

if ($action === 'group_thread' && $method === 'GET') {
    $sinceId = max(0, (int)($_GET['since'] ?? 0));
    $jobSinceTs = max(0, (int)($_GET['since_job_ts'] ?? 0));

    $messages = ic_fetch_group_messages($conn, $currentUserId, $sinceId, 160);
    $lastMessageId = $sinceId;
    foreach ($messages as $msg) {
        $lastMessageId = max($lastMessageId, (int)($msg['id'] ?? 0));
    }

    if ($lastMessageId > 0) {
        ic_group_set_last_read_id($conn, $currentUserId, $lastMessageId);
    } else {
        $latestGroup = ic_group_latest_message_id($conn);
        if ($latestGroup > 0) {
            ic_group_set_last_read_id($conn, $currentUserId, $latestGroup);
            $lastMessageId = $latestGroup;
        }
    }

    $state = ic_state_payload($conn, $currentUserId, $jobSinceTs);

    ic_json([
        'ok' => true,
        'action' => 'group_thread',
        'group' => $state['group'],
        'messages' => $messages,
        'last_message_id' => $lastMessageId,
        'contacts' => $state['contacts'],
        'unread_total' => $state['unread_total'],
        'latest_job_ts' => $state['latest_job_ts'],
        'job_updates' => $state['job_updates'],
        'server_time' => date('c'),
    ]);
}

if ($action === 'poll' && $method === 'GET') {
    $mode = strtolower(trim((string)($_GET['mode'] ?? 'direct')));
    if (!in_array($mode, ['direct', 'group'], true)) {
        $mode = 'direct';
    }

    $peerId = (int)($_GET['peer_id'] ?? 0);
    $sinceId = max(0, (int)($_GET['since'] ?? 0));
    $sinceGroup = max(0, (int)($_GET['since_group'] ?? 0));
    $jobSinceTs = max(0, (int)($_GET['since_job_ts'] ?? 0));

    $newMessages = [];
    $newGroupMessages = [];
    $lastMessageId = $sinceId;
    $lastGroupId = $sinceGroup;
    $peer = null;

    if ($mode === 'group') {
        $newGroupMessages = ic_fetch_group_messages($conn, $currentUserId, $sinceGroup, 120);
        foreach ($newGroupMessages as $msg) {
            $lastGroupId = max($lastGroupId, (int)($msg['id'] ?? 0));
        }
        if ($lastGroupId > 0) {
            ic_group_set_last_read_id($conn, $currentUserId, $lastGroupId);
        }
    } elseif ($peerId > 0 && $peerId !== $currentUserId) {
        $peer = ic_user_info($conn, $peerId);
        if ($peer) {
            ic_mark_direct_read($conn, $currentUserId, $peerId);
            $newMessages = ic_fetch_direct_messages($conn, $currentUserId, $peerId, $sinceId, 120);
            foreach ($newMessages as $msg) {
                $lastMessageId = max($lastMessageId, (int)($msg['id'] ?? 0));
            }
        }
    }

    $state = ic_state_payload($conn, $currentUserId, $jobSinceTs);

    ic_json([
        'ok' => true,
        'action' => 'poll',
        'mode' => $mode,
        'peer' => $peer,
        'new_messages' => $newMessages,
        'new_group_messages' => $newGroupMessages,
        'last_message_id' => $lastMessageId,
        'last_group_id' => $lastGroupId,
        'contacts' => $state['contacts'],
        'group' => $state['group'],
        'unread_total' => $state['unread_total'],
        'latest_job_ts' => $state['latest_job_ts'],
        'job_updates' => $state['job_updates'],
        'server_time' => date('c'),
    ]);
}

if ($action === 'send' && $method === 'POST') {
    if (!app_verify_csrf((string)($_POST['_csrf_token'] ?? ''))) {
        ic_json([
            'ok' => false,
            'error' => 'invalid_csrf',
            'message' => app_tr('انتهت الجلسة. حدث الصفحة ثم حاول مرة أخرى.', 'Session expired. Refresh and try again.'),
        ], 403);
    }

    $peerId = (int)($_POST['peer_id'] ?? 0);
    $messageText = trim((string)($_POST['message'] ?? ''));

    if ($peerId <= 0 || $peerId === $currentUserId) {
        ic_json(['ok' => false, 'error' => 'invalid_peer'], 422);
    }

    $peer = ic_user_info($conn, $peerId);
    if (!$peer) {
        ic_json(['ok' => false, 'error' => 'peer_not_found'], 404);
    }

    $attachmentPath = '';
    $attachmentName = '';
    $attachmentKind = 'none';

    if (isset($_FILES['attachment']) && is_array($_FILES['attachment']) && (int)($_FILES['attachment']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        $upload = ic_store_attachment($_FILES['attachment']);
        if (empty($upload['ok'])) {
            ic_json([
                'ok' => false,
                'error' => 'upload_failed',
                'message' => app_tr('تعذر رفع الملف: ', 'Could not upload file: ') . (string)($upload['error'] ?? 'upload_failed'),
            ], 422);
        }
        $attachmentPath = (string)($upload['path'] ?? '');
        $attachmentName = (string)($upload['name'] ?? '');
        $attachmentKind = (string)($upload['kind'] ?? 'none');
    }

    $inserted = ic_insert_direct_message(
        $conn,
        $currentUserId,
        $peerId,
        $messageText,
        $attachmentPath,
        $attachmentName,
        $attachmentKind
    );

    if (empty($inserted['ok'])) {
        ic_json([
            'ok' => false,
            'error' => (string)($inserted['error'] ?? 'send_failed'),
            'message' => app_tr('تعذر إرسال الرسالة.', 'Could not send message.'),
        ], 422);
    }

    $state = ic_state_payload($conn, $currentUserId, 0);

    ic_json([
        'ok' => true,
        'action' => 'send',
        'message' => $inserted['message'],
        'contacts' => $state['contacts'],
        'group' => $state['group'],
        'unread_total' => $state['unread_total'],
        'latest_job_ts' => $state['latest_job_ts'],
        'job_updates' => [],
        'server_time' => date('c'),
    ]);
}

if ($action === 'send_group' && $method === 'POST') {
    if (!app_verify_csrf((string)($_POST['_csrf_token'] ?? ''))) {
        ic_json([
            'ok' => false,
            'error' => 'invalid_csrf',
            'message' => app_tr('انتهت الجلسة. حدث الصفحة ثم حاول مرة أخرى.', 'Session expired. Refresh and try again.'),
        ], 403);
    }

    $messageText = trim((string)($_POST['message'] ?? ''));

    $attachmentPath = '';
    $attachmentName = '';
    $attachmentKind = 'none';

    if (isset($_FILES['attachment']) && is_array($_FILES['attachment']) && (int)($_FILES['attachment']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        $upload = ic_store_attachment($_FILES['attachment']);
        if (empty($upload['ok'])) {
            ic_json([
                'ok' => false,
                'error' => 'upload_failed',
                'message' => app_tr('تعذر رفع الملف: ', 'Could not upload file: ') . (string)($upload['error'] ?? 'upload_failed'),
            ], 422);
        }
        $attachmentPath = (string)($upload['path'] ?? '');
        $attachmentName = (string)($upload['name'] ?? '');
        $attachmentKind = (string)($upload['kind'] ?? 'none');
    }

    $inserted = ic_insert_group_message(
        $conn,
        $currentUserId,
        $messageText,
        $attachmentPath,
        $attachmentName,
        $attachmentKind
    );

    if (empty($inserted['ok'])) {
        ic_json([
            'ok' => false,
            'error' => (string)($inserted['error'] ?? 'send_failed'),
            'message' => app_tr('تعذر إرسال الرسالة.', 'Could not send message.'),
        ], 422);
    }

    $state = ic_state_payload($conn, $currentUserId, 0);

    ic_json([
        'ok' => true,
        'action' => 'send_group',
        'message' => $inserted['message'],
        'contacts' => $state['contacts'],
        'group' => $state['group'],
        'unread_total' => $state['unread_total'],
        'latest_job_ts' => $state['latest_job_ts'],
        'job_updates' => [],
        'server_time' => date('c'),
    ]);
}

ic_json([
    'ok' => false,
    'error' => 'unknown_action',
    'message' => app_tr('إجراء غير مدعوم.', 'Unsupported action.'),
], 400);
