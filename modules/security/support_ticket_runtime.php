<?php

if (!function_exists('app_support_ticket_get')) {
    function app_support_ticket_get(mysqli $conn, int $ticketId): array
    {
        app_initialize_support_center($conn);
        if ($ticketId <= 0) {
            return [];
        }
        $stmt = $conn->prepare("SELECT * FROM app_support_tickets WHERE id = ? LIMIT 1");
        $stmt->bind_param('i', $ticketId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: [];
        $stmt->close();
        return $row;
    }
}

if (!function_exists('app_support_user_can_access_ticket')) {
    function app_support_user_can_access_ticket(array $ticket, int $userId, bool $isAdmin): bool
    {
        if ($isAdmin) {
            return true;
        }
        $ownerId = (int)($ticket['requester_user_id'] ?? 0);
        return $ownerId > 0 && $ownerId === $userId;
    }
}

if (!function_exists('app_support_ticket_create')) {
    function app_support_ticket_create(mysqli $conn, int $userId, bool $isAdmin, array $payload): array
    {
        app_initialize_support_center($conn);
        if ($userId <= 0) {
            return ['ok' => false, 'error' => 'invalid_user'];
        }

        $subject = mb_substr(trim((string)($payload['subject'] ?? '')), 0, 220);
        $message = trim((string)($payload['message'] ?? ''));
        $imagePath = trim((string)($payload['image_path'] ?? ''));
        $imageName = app_support_attachment_safe_name((string)($payload['image_name'] ?? ''), $imagePath);
        $priority = strtolower(trim((string)($payload['priority'] ?? 'normal')));
        $requesterName = mb_substr(trim((string)($payload['requester_name'] ?? ($_SESSION['name'] ?? ''))), 0, 190);
        $requesterEmail = mb_substr(trim((string)($payload['requester_email'] ?? ($_SESSION['email'] ?? ''))), 0, 190);
        $requesterPhone = mb_substr(trim((string)($payload['requester_phone'] ?? '')), 0, 80);

        if (!in_array($priority, ['low', 'normal', 'high', 'urgent'], true)) {
            $priority = 'normal';
        }
        if ($subject === '') {
            $subject = 'Support Request';
        }
        if ($message === '' && $imagePath === '') {
            return ['ok' => false, 'error' => 'message_or_image_required'];
        }

        $license = app_license_row($conn);
        $installationId = mb_substr(trim((string)($license['installation_id'] ?? '')), 0, 80);
        $status = 'open';
        $now = date('Y-m-d H:i:s');

        $stmt = $conn->prepare("
            INSERT INTO app_support_tickets (
                installation_id, requester_user_id, requester_name, requester_email, requester_phone,
                subject, priority, status, last_message_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param(
            'sisssssss',
            $installationId,
            $userId,
            $requesterName,
            $requesterEmail,
            $requesterPhone,
            $subject,
            $priority,
            $status,
            $now
        );
        $ok = $stmt->execute();
        $ticketId = (int)$stmt->insert_id;
        $stmt->close();
        if (!$ok || $ticketId <= 0) {
            return ['ok' => false, 'error' => 'ticket_insert_failed'];
        }

        $syncError = '';

        $senderRole = $isAdmin ? 'support' : 'client';
        $isReadByClient = $senderRole === 'client' ? 1 : 0;
        $isReadByAdmin = $senderRole === 'support' ? 1 : 0;
        $stmtMsg = $conn->prepare("
            INSERT INTO app_support_ticket_messages (
                ticket_id, sender_user_id, sender_role, message, image_path, image_name, is_read_by_client, is_read_by_admin
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmtMsg->bind_param('iissssii', $ticketId, $userId, $senderRole, $message, $imagePath, $imageName, $isReadByClient, $isReadByAdmin);
        $stmtMsg->execute();
        $stmtMsg->close();

        if (!$isAdmin) {
            $adminIds = app_support_admin_user_ids($conn);
            app_support_notify_users(
                $conn,
                $adminIds,
                $ticketId,
                'ticket_new',
                'تذكرة دعم جديدة',
                mb_substr($subject, 0, 255),
                $userId
            );

            if (app_license_edition() === 'client') {
                $remoteSync = app_support_remote_push_ticket_create($conn, $ticketId, $payload, $message, $imagePath, $imageName);
                if (!empty($remoteSync['ok'])) {
                    app_support_ticket_set_remote_state(
                        $conn,
                        $ticketId,
                        (int)($remoteSync['remote_ticket_id'] ?? 0),
                        '',
                        (string)($remoteSync['license_key'] ?? ''),
                        (string)($remoteSync['domain'] ?? ''),
                        (string)($remoteSync['app_url'] ?? '')
                    );
                } else {
                    $syncError = (string)($remoteSync['error'] ?? 'remote_sync_failed');
                    app_support_ticket_set_remote_state(
                        $conn,
                        $ticketId,
                        0,
                        $syncError
                    );
                }
            }
        }

        return ['ok' => true, 'error' => '', 'ticket_id' => $ticketId, 'sync_error' => $syncError];
    }
}

if (!function_exists('app_support_ticket_reply')) {
    function app_support_ticket_reply(
        mysqli $conn,
        int $ticketId,
        int $userId,
        bool $isAdmin,
        string $message,
        string $status = '',
        string $imagePath = '',
        string $imageName = ''
    ): array
    {
        app_initialize_support_center($conn);
        if ($ticketId <= 0 || $userId <= 0) {
            return ['ok' => false, 'error' => 'invalid_input'];
        }
        $ticket = app_support_ticket_get($conn, $ticketId);
        if (!$ticket) {
            return ['ok' => false, 'error' => 'ticket_not_found'];
        }
        $canManageAll = $isAdmin || (app_license_edition() === 'client' && app_support_is_admin());
        if (!app_support_user_can_access_ticket($ticket, $userId, $canManageAll)) {
            return ['ok' => false, 'error' => 'access_denied'];
        }

        $message = trim($message);
        $imagePath = trim($imagePath);
        $imageName = app_support_attachment_safe_name($imageName, $imagePath);
        if ($message === '' && $imagePath === '') {
            return ['ok' => false, 'error' => 'message_or_image_required'];
        }
        $replyPreview = $message !== '' ? $message : app_tr('تم إرفاق صورة.', 'Image attached.');

        $senderRole = $isAdmin ? 'support' : 'client';
        $isReadByClient = $senderRole === 'client' ? 1 : 0;
        $isReadByAdmin = $senderRole === 'support' ? 1 : 0;

        $stmtMsg = $conn->prepare("
            INSERT INTO app_support_ticket_messages (
                ticket_id, sender_user_id, sender_role, message, image_path, image_name, is_read_by_client, is_read_by_admin
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmtMsg->bind_param('iissssii', $ticketId, $userId, $senderRole, $message, $imagePath, $imageName, $isReadByClient, $isReadByAdmin);
        $ok = $stmtMsg->execute();
        $stmtMsg->close();
        if (!$ok) {
            return ['ok' => false, 'error' => 'reply_insert_failed'];
        }

        $newStatus = trim(strtolower($status));
        if (!in_array($newStatus, ['open', 'pending', 'answered', 'closed'], true)) {
            $newStatus = $isAdmin ? 'answered' : 'pending';
        }
        $now = date('Y-m-d H:i:s');
        $stmtT = $conn->prepare("UPDATE app_support_tickets SET status = ?, last_message_at = ? WHERE id = ?");
        $stmtT->bind_param('ssi', $newStatus, $now, $ticketId);
        $stmtT->execute();
        $stmtT->close();

        $syncError = '';

        if ($isAdmin) {
            $ownerUserId = (int)($ticket['requester_user_id'] ?? 0);
            if ($ownerUserId > 0) {
                app_support_notify_users(
                    $conn,
                    [$ownerUserId],
                    $ticketId,
                    'ticket_reply',
                    'رد جديد من خدمة العملاء',
                    mb_substr($replyPreview, 0, 255),
                    $userId
                );
            }
        } else {
            $adminIds = app_support_admin_user_ids($conn);
            app_support_notify_users(
                $conn,
                $adminIds,
                $ticketId,
                'ticket_reply',
                'رد جديد من العميل',
                mb_substr($replyPreview, 0, 255),
                $userId
            );

            if (app_license_edition() === 'client') {
                $remoteSync = app_support_remote_push_ticket_reply($conn, $ticket, $message, $newStatus, $imagePath, $imageName);
                if (!empty($remoteSync['ok'])) {
                    app_support_ticket_set_remote_state(
                        $conn,
                        $ticketId,
                        (int)($remoteSync['remote_ticket_id'] ?? 0),
                        '',
                        (string)($remoteSync['license_key'] ?? ''),
                        (string)($remoteSync['domain'] ?? ''),
                        (string)($remoteSync['app_url'] ?? '')
                    );
                } else {
                    $syncError = (string)($remoteSync['error'] ?? 'remote_sync_failed');
                    app_support_ticket_set_remote_state(
                        $conn,
                        $ticketId,
                        (int)($ticket['remote_ticket_id'] ?? 0),
                        $syncError
                    );
                }
            }
        }

        return ['ok' => true, 'error' => '', 'ticket_id' => $ticketId, 'sync_error' => $syncError];
    }
}

if (!function_exists('app_support_ticket_set_status')) {
    function app_support_ticket_set_status(mysqli $conn, int $ticketId, int $userId, bool $isAdmin, string $status): array
    {
        app_initialize_support_center($conn);
        if (!$isAdmin) {
            return ['ok' => false, 'error' => 'admin_required'];
        }
        if ($ticketId <= 0 || $userId <= 0) {
            return ['ok' => false, 'error' => 'invalid_input'];
        }
        $status = strtolower(trim($status));
        if (!in_array($status, ['open', 'pending', 'answered', 'closed'], true)) {
            return ['ok' => false, 'error' => 'status_invalid'];
        }
        $ticket = app_support_ticket_get($conn, $ticketId);
        if (!$ticket) {
            return ['ok' => false, 'error' => 'ticket_not_found'];
        }

        $stmt = $conn->prepare("UPDATE app_support_tickets SET status = ? WHERE id = ?");
        $stmt->bind_param('si', $status, $ticketId);
        $ok = $stmt->execute();
        $stmt->close();
        if (!$ok) {
            return ['ok' => false, 'error' => 'status_update_failed'];
        }

        $ownerUserId = (int)($ticket['requester_user_id'] ?? 0);
        if ($ownerUserId > 0) {
            app_support_notify_users(
                $conn,
                [$ownerUserId],
                $ticketId,
                'ticket_status',
                'تم تحديث حالة التذكرة',
                'الحالة الجديدة: ' . $status,
                $userId
            );
        }

        return ['ok' => true, 'error' => '', 'ticket_id' => $ticketId, 'status' => $status];
    }
}

if (!function_exists('app_support_ticket_delete_local')) {
    function app_support_ticket_delete_local(mysqli $conn, int $ticketId): array
    {
        app_initialize_support_center($conn);
        if ($ticketId <= 0) {
            return ['ok' => false, 'error' => 'invalid_ticket'];
        }

        $imagePaths = [];
        try {
            $stmtImages = $conn->prepare("SELECT image_path FROM app_support_ticket_messages WHERE ticket_id = ?");
            $stmtImages->bind_param('i', $ticketId);
            $stmtImages->execute();
            $resImages = $stmtImages->get_result();
            while ($resImages && ($row = $resImages->fetch_assoc())) {
                $path = trim((string)($row['image_path'] ?? ''));
                if ($path !== '') {
                    $imagePaths[] = $path;
                }
            }
            $stmtImages->close();

            $conn->begin_transaction();

            $stmtNotif = $conn->prepare("DELETE FROM app_support_notifications WHERE ticket_id = ?");
            $stmtNotif->bind_param('i', $ticketId);
            $stmtNotif->execute();
            $stmtNotif->close();

            $stmtMsg = $conn->prepare("DELETE FROM app_support_ticket_messages WHERE ticket_id = ?");
            $stmtMsg->bind_param('i', $ticketId);
            $stmtMsg->execute();
            $stmtMsg->close();

            $stmtTicket = $conn->prepare("DELETE FROM app_support_tickets WHERE id = ? LIMIT 1");
            $stmtTicket->bind_param('i', $ticketId);
            $stmtTicket->execute();
            $affected = (int)$stmtTicket->affected_rows;
            $stmtTicket->close();

            $conn->commit();

            if ($affected <= 0) {
                return ['ok' => false, 'error' => 'ticket_not_found'];
            }

            foreach (array_values(array_unique($imagePaths)) as $imgPath) {
                app_support_attachment_delete_local((string)$imgPath);
            }

            return ['ok' => true, 'error' => ''];
        } catch (Throwable $e) {
            @$conn->rollback();
            return ['ok' => false, 'error' => 'ticket_delete_failed'];
        }
    }
}

if (!function_exists('app_support_ticket_delete')) {
    function app_support_ticket_delete(mysqli $conn, int $ticketId, int $userId, bool $isAdmin): array
    {
        app_initialize_support_center($conn);
        if ($ticketId <= 0 || $userId <= 0) {
            return ['ok' => false, 'error' => 'invalid_input'];
        }

        $ticket = app_support_ticket_get($conn, $ticketId);
        if (!$ticket) {
            return ['ok' => false, 'error' => 'ticket_not_found'];
        }
        $canManageAll = $isAdmin || (app_license_edition() === 'client' && app_support_is_admin());
        if (!app_support_user_can_access_ticket($ticket, $userId, $canManageAll)) {
            return ['ok' => false, 'error' => 'access_denied'];
        }

        $syncError = '';
        $shouldSyncRemote = false;
        if (app_license_edition() === 'client') {
            $shouldSyncRemote = true;
        } elseif ($isAdmin) {
            $hasRemoteRef = trim((string)($ticket['remote_license_key'] ?? '')) !== ''
                && (trim((string)($ticket['remote_client_app_url'] ?? '')) !== ''
                    || trim((string)($ticket['remote_client_domain'] ?? '')) !== '');
            $shouldSyncRemote = $hasRemoteRef;
        }

        if ($shouldSyncRemote) {
            $remoteSync = app_support_remote_push_ticket_delete($conn, $ticket);
            if (empty($remoteSync['ok'])) {
                $syncError = (string)($remoteSync['error'] ?? 'remote_sync_failed');
            }
        }

        $deleted = app_support_ticket_delete_local($conn, $ticketId);
        if (empty($deleted['ok'])) {
            return ['ok' => false, 'error' => (string)($deleted['error'] ?? 'ticket_delete_failed')];
        }

        return ['ok' => true, 'error' => '', 'ticket_id' => $ticketId, 'sync_error' => $syncError];
    }
}

if (!function_exists('app_support_tickets_for_user')) {
    function app_support_tickets_for_user(mysqli $conn, int $userId, bool $isAdmin, int $limit = 50): array
    {
        app_initialize_support_center($conn);
        $limit = max(1, min(300, $limit));
        $rows = [];

        if ($isAdmin) {
            $sql = "
                SELECT t.*,
                    (SELECT COUNT(*) FROM app_support_ticket_messages m WHERE m.ticket_id = t.id AND m.is_read_by_admin = 0) AS unread_for_admin,
                    (SELECT COUNT(*) FROM app_support_ticket_messages m WHERE m.ticket_id = t.id AND m.is_read_by_client = 0) AS unread_for_client
                FROM app_support_tickets t
                ORDER BY t.last_message_at DESC, t.id DESC
                LIMIT ?
            ";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('i', $limit);
        } else {
            $sql = "
                SELECT t.*,
                    (SELECT COUNT(*) FROM app_support_ticket_messages m WHERE m.ticket_id = t.id AND m.is_read_by_admin = 0) AS unread_for_admin,
                    (SELECT COUNT(*) FROM app_support_ticket_messages m WHERE m.ticket_id = t.id AND m.is_read_by_client = 0) AS unread_for_client
                FROM app_support_tickets t
                WHERE t.requester_user_id = ?
                ORDER BY t.last_message_at DESC, t.id DESC
                LIMIT ?
            ";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('ii', $userId, $limit);
        }
        $stmt->execute();
        $res = $stmt->get_result();
        while ($res && ($row = $res->fetch_assoc())) {
            $rows[] = $row;
        }
        $stmt->close();
        return $rows;
    }
}

if (!function_exists('app_support_ticket_messages')) {
    function app_support_ticket_messages(mysqli $conn, int $ticketId, int $limit = 250): array
    {
        app_initialize_support_center($conn);
        if ($ticketId <= 0) {
            return [];
        }
        $limit = max(1, min(500, $limit));
        $stmt = $conn->prepare("
            SELECT m.*, COALESCE(u.full_name, '') AS sender_name
            FROM app_support_ticket_messages m
            LEFT JOIN users u ON u.id = m.sender_user_id
            WHERE m.ticket_id = ?
            ORDER BY m.id ASC
            LIMIT ?
        ");
        $stmt->bind_param('ii', $ticketId, $limit);
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

if (!function_exists('app_support_ticket_mark_read')) {
    function app_support_ticket_mark_read(mysqli $conn, int $ticketId, bool $forAdmin): bool
    {
        app_initialize_support_center($conn);
        if ($ticketId <= 0) {
            return false;
        }
        if ($forAdmin) {
            $stmt = $conn->prepare("UPDATE app_support_ticket_messages SET is_read_by_admin = 1 WHERE ticket_id = ? AND is_read_by_admin = 0");
        } else {
            $stmt = $conn->prepare("UPDATE app_support_ticket_messages SET is_read_by_client = 1 WHERE ticket_id = ? AND is_read_by_client = 0");
        }
        $stmt->bind_param('i', $ticketId);
        $ok = $stmt->execute();
        $stmt->close();
        return (bool)$ok;
    }
}

if (!function_exists('app_support_notifications_unread_count')) {
    function app_support_notifications_unread_count(mysqli $conn, int $userId): int
    {
        app_initialize_support_center($conn);
        if ($userId <= 0) {
            return 0;
        }
        $stmt = $conn->prepare("SELECT COUNT(*) AS c FROM app_support_notifications WHERE user_id = ? AND is_read = 0");
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: [];
        $stmt->close();
        return (int)($row['c'] ?? 0);
    }
}

if (!function_exists('app_support_notifications_recent')) {
    function app_support_notifications_recent(mysqli $conn, int $userId, int $limit = 12): array
    {
        app_initialize_support_center($conn);
        if ($userId <= 0) {
            return [];
        }
        $limit = max(1, min(100, $limit));
        $stmt = $conn->prepare("
            SELECT id, user_id, ticket_id, notif_type, title, message, is_read, created_at
            FROM app_support_notifications
            WHERE user_id = ?
            ORDER BY id DESC
            LIMIT ?
        ");
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

if (!function_exists('app_support_notification_mark_read')) {
    function app_support_notification_mark_read(mysqli $conn, int $userId, int $notificationId): bool
    {
        app_initialize_support_center($conn);
        if ($userId <= 0 || $notificationId <= 0) {
            return false;
        }
        $stmt = $conn->prepare("UPDATE app_support_notifications SET is_read = 1 WHERE id = ? AND user_id = ?");
        $stmt->bind_param('ii', $notificationId, $userId);
        $ok = $stmt->execute();
        $stmt->close();
        return (bool)$ok;
    }
}

if (!function_exists('app_support_notifications_mark_all_read')) {
    function app_support_notifications_mark_all_read(mysqli $conn, int $userId): bool
    {
        app_initialize_support_center($conn);
        if ($userId <= 0) {
            return false;
        }
        $stmt = $conn->prepare("UPDATE app_support_notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0");
        $stmt->bind_param('i', $userId);
        $ok = $stmt->execute();
        $stmt->close();
        return (bool)$ok;
    }
}

if (!function_exists('app_support_notifications_mark_ticket_read')) {
    function app_support_notifications_mark_ticket_read(mysqli $conn, int $userId, int $ticketId): bool
    {
        app_initialize_support_center($conn);
        if ($userId <= 0 || $ticketId <= 0) {
            return false;
        }
        $stmt = $conn->prepare("
            UPDATE app_support_notifications
            SET is_read = 1
            WHERE user_id = ? AND ticket_id = ? AND is_read = 0
        ");
        $stmt->bind_param('ii', $userId, $ticketId);
        $ok = $stmt->execute();
        $stmt->close();
        return (bool)$ok;
    }
}

if (!function_exists('app_support_admin_attention_tickets')) {
    function app_support_admin_attention_tickets(mysqli $conn, int $limit = 8): array
    {
        app_initialize_support_center($conn);
        $limit = max(1, min(100, $limit));
        $readColumn = app_license_edition() === 'owner' ? 'is_read_by_admin' : 'is_read_by_client';
        $orderAlias = app_license_edition() === 'owner' ? 'unread_for_admin' : 'unread_for_client';
        $stmt = $conn->prepare("
            SELECT t.*,
                (SELECT COUNT(*) FROM app_support_ticket_messages m WHERE m.ticket_id = t.id AND m.is_read_by_admin = 0) AS unread_for_admin,
                (SELECT COUNT(*) FROM app_support_ticket_messages m WHERE m.ticket_id = t.id AND m.is_read_by_client = 0) AS unread_for_client
            FROM app_support_tickets t
            WHERE EXISTS (
                SELECT 1 FROM app_support_ticket_messages m2
                WHERE m2.ticket_id = t.id
                  AND m2.{$readColumn} = 0
            )
            ORDER BY {$orderAlias} DESC, t.last_message_at DESC, t.id DESC
            LIMIT ?
        ");
        $stmt->bind_param('i', $limit);
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

if (!function_exists('app_support_admin_unread_messages_count')) {
    function app_support_admin_unread_messages_count(mysqli $conn): int
    {
        app_initialize_support_center($conn);
        $readColumn = app_license_edition() === 'owner' ? 'is_read_by_admin' : 'is_read_by_client';
        $row = $conn->query("
            SELECT COUNT(*) AS c
            FROM app_support_ticket_messages
            WHERE {$readColumn} = 0
        ")->fetch_assoc();
        return (int)($row['c'] ?? 0);
    }
}
