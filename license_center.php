<?php
require 'auth.php';
require 'config.php';
app_start_session();
app_handle_lang_switch($conn);

if (!function_exists('lc_datetime_text')) {
    function lc_datetime_text(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '-';
        }
        $ts = strtotime($value);
        if ($ts === false) {
            return $value;
        }
        return date('Y-m-d H:i', $ts);
    }
}

if (!function_exists('lc_status_text')) {
    function lc_status_text(string $status): string
    {
        $status = strtolower(trim($status));
        if ($status === 'suspended') {
            return app_tr('موقوف', 'Suspended');
        }
        if ($status === 'expired') {
            return app_tr('منتهي', 'Expired');
        }
        return app_tr('نشط', 'Active');
    }
}

if (!function_exists('lc_plan_text')) {
    function lc_plan_text(string $plan): string
    {
        $plan = strtolower(trim($plan));
        if ($plan === 'subscription') {
            return app_tr('اشتراك', 'Subscription');
        }
        if ($plan === 'lifetime') {
            return app_tr('دائم', 'Lifetime');
        }
        return app_tr('تجريبي', 'Trial');
    }
}

if (!function_exists('lc_ticket_status_label')) {
    function lc_ticket_status_label(string $status): string
    {
        $status = strtolower(trim($status));
        if ($status === 'pending') {
            return app_tr('قيد المتابعة', 'Pending');
        }
        if ($status === 'answered') {
            return app_tr('تم الرد', 'Answered');
        }
        if ($status === 'closed') {
            return app_tr('مغلقة', 'Closed');
        }
        return app_tr('مفتوحة', 'Open');
    }
}

if (!function_exists('lc_sync_error_text')) {
    function lc_sync_error_text(string $code): string
    {
        $code = strtolower(trim($code));
        $isClientEdition = app_license_edition() === 'client';
        if ($code === '') {
            return '';
        }
        if ($isClientEdition) {
            if (in_array($code, ['owner_api_not_updated', 'remote_not_configured', 'license_key_missing', 'license_not_found'], true)) {
                return app_tr('تعذر التحقق من خدمة الدعم المركزية حالياً. يرجى التواصل مع خدمة العملاء.', 'Could not verify the central support service right now. Please contact support.');
            }
            if (in_array($code, ['http_401', 'http_403', 'http_404', 'remote_ticket_missing', 'remote_rejected'], true)) {
                return app_tr('حدث خلل مؤقت في الربط مع النظام المركزي. يرجى المحاولة لاحقاً.', 'Temporary issue while connecting to the central system. Please try again later.');
            }
            return app_tr('تعذر مزامنة التذكرة حالياً. يرجى إعادة المحاولة لاحقاً.', 'Could not sync the ticket right now. Please retry later.');
        }
        if ($code === 'owner_api_not_updated') {
            return app_tr('نظام المالك لم يُحدّث بعد لاستقبال تذاكر الدعم. حدّث ملفات النظام المركزي.', 'Owner system is not updated yet to receive support tickets. Update central files.');
        }
        if ($code === 'remote_not_configured') {
            return app_tr('رابط التحقق الخارجي غير مضبوط على نسخة العميل.', 'Remote endpoint is not configured on client site.');
        }
        if ($code === 'license_key_missing' || $code === 'license_not_found') {
            return app_tr('مفتاح الترخيص غير صحيح أو غير مسجل في النظام المركزي.', 'License key is invalid or not registered in owner system.');
        }
        if ($code === 'http_401') {
            return app_tr('فشل المصادقة مع النظام المركزي (رمز التوكن غير صحيح).', 'Authentication failed with owner system (invalid token).');
        }
        if ($code === 'http_404') {
            return app_tr('مسار API غير موجود في النظام المركزي.', 'Owner API endpoint was not found.');
        }
        if ($code === 'remote_ticket_missing' || $code === 'remote_rejected') {
            return app_tr('النظام المركزي لم يُرجع رقم تذكرة. راجع تحديثات API.', 'Owner API did not return ticket id. Review API update.');
        }
        return app_tr('فشل مزامنة التذكرة مع النظام المركزي: ', 'Ticket sync failed with owner system: ') . $code;
    }
}

if (!function_exists('lc_ticket_image_upload')) {
    function lc_ticket_image_upload(string $field): array
    {
        if (!isset($_FILES[$field]) || !is_array($_FILES[$field])) {
            return ['ok' => true, 'path' => '', 'name' => '', 'error' => ''];
        }
        $file = $_FILES[$field];
        $err = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($err === UPLOAD_ERR_NO_FILE) {
            return ['ok' => true, 'path' => '', 'name' => '', 'error' => ''];
        }

        $stored = app_store_uploaded_file($file, [
            'dir' => 'uploads/support_tickets',
            'prefix' => 'ticket_',
            'max_size' => 8 * 1024 * 1024,
            'allowed_extensions' => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'],
            'allowed_mimes' => ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/bmp'],
        ]);
        if (empty($stored['ok'])) {
            return [
                'ok' => false,
                'path' => '',
                'name' => '',
                'error' => app_tr('تعذر رفع الصورة: ', 'Image upload failed: ') . (string)($stored['error'] ?? 'upload_failed'),
            ];
        }

        $name = mb_substr(trim((string)($file['name'] ?? '')), 0, 190);
        return ['ok' => true, 'path' => (string)($stored['path'] ?? ''), 'name' => $name, 'error' => ''];
    }
}

if (!function_exists('lc_ticket_image_url')) {
    function lc_ticket_image_url(string $path): string
    {
        $path = trim(str_replace('\\', '/', $path));
        if ($path === '') {
            return '';
        }
        if (preg_match('#^https?://#i', $path) || strpos($path, '//') === 0) {
            return $path;
        }
        return '/' . ltrim($path, '/');
    }
}

if (!function_exists('lc_support_sender_name')) {
    function lc_support_sender_name(mysqli $conn): string
    {
        $appName = trim((string)app_setting_get($conn, 'app_name', 'Arab Eagles'));
        if ($appName === '') {
            $appName = 'Arab Eagles';
        }
        if (app_license_edition() === 'client') {
            return 'خدمة عملاء ' . $appName;
        }
        return app_tr('خدمة العملاء', 'Support');
    }
}

if (!function_exists('lc_ticket_source_text')) {
    function lc_ticket_source_text(array $ticket): string
    {
        $source = trim((string)($ticket['remote_client_domain'] ?? ''));
        if ($source === '') {
            $appUrl = trim((string)($ticket['remote_client_app_url'] ?? ''));
            if ($appUrl !== '') {
                $source = app_license_normalize_domain((string)parse_url($appUrl, PHP_URL_HOST));
            }
        }
        if ($source === '') {
            $source = trim((string)($ticket['installation_id'] ?? ''));
        }
        if ($source === '') {
            $source = app_tr('غير محدد', 'Unknown');
        }
        return $source;
    }
}

if (!function_exists('lc_ticket_requester_text')) {
    function lc_ticket_requester_text(array $ticket): string
    {
        $name = trim((string)($ticket['requester_name'] ?? ''));
        if ($name !== '') {
            return $name;
        }
        $email = trim((string)($ticket['requester_email'] ?? ''));
        if ($email !== '') {
            return $email;
        }
        return app_tr('عميل', 'Client');
    }
}

$currentUserId = (int)($_SESSION['user_id'] ?? 0);
$currentRole = strtolower((string)($_SESSION['role'] ?? 'employee'));
$isAdmin = ($currentRole === 'admin');
$isSupportAgent = $isAdmin && (app_license_edition() === 'owner' || app_is_super_user());
$isOwnerOpsCenter = $isSupportAgent && app_license_edition() === 'owner';
$canViewAllTickets = $isAdmin;

$noticeType = '';
$noticeText = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    app_require_csrf();
    $action = strtolower(trim((string)($_POST['action'] ?? '')));

    if ($action === 'open_ticket') {
        if ($isOwnerOpsCenter) {
            $noticeType = 'error';
            $noticeText = app_tr('نظام المالك لا يفتح تذاكر لنفسه من هذه الشاشة. هذه الواجهة مخصصة لاستقبال وإدارة التذاكر الواردة.', 'Owner system does not open tickets for itself from this screen. This view is reserved for receiving and managing incoming tickets.');
        } else {
        $upload = lc_ticket_image_upload('ticket_image');
        if (empty($upload['ok'])) {
            $noticeType = 'error';
            $noticeText = (string)($upload['error'] ?? app_tr('تعذر رفع الصورة.', 'Could not upload image.'));
        } else {
            $payload = [
                'subject' => trim((string)($_POST['ticket_subject'] ?? '')),
                'message' => trim((string)($_POST['ticket_message'] ?? '')),
                'priority' => trim((string)($_POST['ticket_priority'] ?? 'normal')),
                'requester_name' => trim((string)($_SESSION['name'] ?? '')),
                'requester_email' => trim((string)($_POST['ticket_email'] ?? ($_SESSION['email'] ?? ''))),
                'requester_phone' => trim((string)($_POST['ticket_phone'] ?? '')),
                'image_path' => (string)($upload['path'] ?? ''),
                'image_name' => (string)($upload['name'] ?? ''),
            ];
            $created = app_support_ticket_create($conn, $currentUserId, $isSupportAgent, $payload);
            if (!empty($created['ok'])) {
                $ticketId = (int)($created['ticket_id'] ?? 0);
                app_audit_log_add($conn, 'support.ticket_created', [
                    'entity_type' => 'support_ticket',
                    'entity_key' => (string)$ticketId,
                    'details' => [
                        'priority' => (string)($payload['priority'] ?? 'normal'),
                        'has_image' => !empty($payload['image_path']),
                        'support_agent' => $isSupportAgent ? 1 : 0,
                    ],
                ]);
                $syncCode = trim((string)($created['sync_error'] ?? ''));
                $target = 'license_center.php?ticket=' . $ticketId . '&msg=ticket_created';
                if ($syncCode !== '') {
                    $target .= '&sync=' . rawurlencode($syncCode);
                }
                app_safe_redirect($target);
            }
            $noticeType = 'error';
            $createError = strtolower(trim((string)($created['error'] ?? 'unknown')));
            if ($createError === 'message_or_image_required') {
                $noticeText = app_tr('أضف رسالة أو أرفق صورة واحدة على الأقل.', 'Please add a message or attach at least one image.');
            } else {
                $noticeText = app_tr('تعذر فتح التذكرة.', 'Could not open ticket.');
            }
        }
        }
    } elseif ($action === 'reply_ticket') {
        $ticketId = (int)($_POST['ticket_id'] ?? 0);
        $message = trim((string)($_POST['reply_message'] ?? ''));
        $replyStatus = trim((string)($_POST['reply_status'] ?? ''));
        $upload = lc_ticket_image_upload('reply_image');
        if (empty($upload['ok'])) {
            $noticeType = 'error';
            $noticeText = (string)($upload['error'] ?? app_tr('تعذر رفع الصورة.', 'Could not upload image.'));
        } else {
            $reply = app_support_ticket_reply(
                $conn,
                $ticketId,
                $currentUserId,
                $isSupportAgent,
                $message,
                $replyStatus,
                (string)($upload['path'] ?? ''),
                (string)($upload['name'] ?? '')
            );
            if (!empty($reply['ok'])) {
                app_audit_log_add($conn, 'support.ticket_replied', [
                    'entity_type' => 'support_ticket',
                    'entity_key' => (string)$ticketId,
                    'details' => [
                        'status' => $replyStatus,
                        'has_image' => !empty($upload['path']),
                        'support_agent' => $isSupportAgent ? 1 : 0,
                    ],
                ]);
                $syncCode = trim((string)($reply['sync_error'] ?? ''));
                $target = 'license_center.php?ticket=' . $ticketId . '&msg=ticket_replied';
                if ($syncCode !== '') {
                    $target .= '&sync=' . rawurlencode($syncCode);
                }
                app_safe_redirect($target);
            }
            $noticeType = 'error';
            $replyError = strtolower(trim((string)($reply['error'] ?? 'unknown')));
            if ($replyError === 'message_or_image_required') {
                $noticeText = app_tr('أضف رسالة أو أرفق صورة واحدة على الأقل.', 'Please add a message or attach at least one image.');
            } else {
                $noticeText = app_tr('تعذر إرسال الرد.', 'Could not send reply.');
            }
        }
    } elseif ($action === 'update_ticket_status') {
        $ticketId = (int)($_POST['ticket_id'] ?? 0);
        $status = trim((string)($_POST['ticket_status'] ?? 'open'));
        $updated = app_support_ticket_set_status($conn, $ticketId, $currentUserId, $isSupportAgent, $status);
        if (!empty($updated['ok'])) {
            app_audit_log_add($conn, 'support.ticket_status_updated', [
                'entity_type' => 'support_ticket',
                'entity_key' => (string)$ticketId,
                'details' => [
                    'status' => $status,
                    'support_agent' => $isSupportAgent ? 1 : 0,
                ],
            ]);
            app_safe_redirect('license_center.php?ticket=' . $ticketId . '&msg=ticket_status_updated');
        }
        $noticeType = 'error';
        $noticeText = app_tr('تعذر تحديث حالة التذكرة.', 'Could not update ticket status.');
    } elseif ($action === 'delete_ticket') {
        $ticketId = (int)($_POST['ticket_id'] ?? 0);
        $deleted = app_support_ticket_delete($conn, $ticketId, $currentUserId, $isSupportAgent);
        if (!empty($deleted['ok'])) {
            app_audit_log_add($conn, 'support.ticket_deleted', [
                'entity_type' => 'support_ticket',
                'entity_key' => (string)$ticketId,
                'details' => [
                    'support_agent' => $isSupportAgent ? 1 : 0,
                ],
            ]);
            $syncCode = trim((string)($deleted['sync_error'] ?? ''));
            $target = 'license_center.php?msg=ticket_deleted';
            if ($syncCode !== '') {
                $target .= '&sync=' . rawurlencode($syncCode);
            }
            app_safe_redirect($target);
        }
        $noticeType = 'error';
        $noticeText = app_tr('تعذر حذف التذكرة.', 'Could not delete ticket.');
    }
}

$msg = strtolower(trim((string)($_GET['msg'] ?? '')));
$syncCode = strtolower(trim((string)($_GET['sync'] ?? '')));
if ($noticeText === '') {
    if ($msg === 'ticket_created') {
        $noticeType = 'success';
        $noticeText = app_tr('تم فتح تذكرة الدعم بنجاح.', 'Support ticket opened successfully.');
    } elseif ($msg === 'ticket_replied') {
        $noticeType = 'success';
        $noticeText = app_tr('تم إرسال الرد بنجاح.', 'Reply sent successfully.');
    } elseif ($msg === 'ticket_status_updated') {
        $noticeType = 'success';
        $noticeText = app_tr('تم تحديث حالة التذكرة.', 'Ticket status updated successfully.');
    } elseif ($msg === 'ticket_deleted') {
        $noticeType = 'success';
        $noticeText = app_tr('تم حذف التذكرة بالكامل.', 'Ticket conversation deleted.');
    }
}
if ($syncCode !== '') {
    $syncText = lc_sync_error_text($syncCode);
    if ($syncText !== '') {
        $noticeType = 'error';
        $noticeText = $syncText;
    }
}

$license = app_license_status($conn, true);
$plan = (string)($license['plan'] ?? 'trial');
$status = (string)($license['status'] ?? 'active');
$isBlocked = empty($license['allowed']);
$statusClass = $isBlocked ? 'is-bad' : 'is-good';
$statusText = $isBlocked ? app_tr('غير نشط', 'Inactive') : app_tr('نشط', 'Active');
$expiryText = trim((string)($license['expires_at'] ?? '')) !== ''
    ? lc_datetime_text((string)$license['expires_at'])
    : app_tr('غير محدد', 'Not set');
$daysLeft = isset($license['days_left']) && $license['days_left'] !== null
    ? (string)(int)$license['days_left']
    : '-';

$supportEmail = trim(app_setting_get($conn, 'support_email', (string)app_env('APP_LICENSE_ALERT_EMAIL', '')));
$supportPhone = trim(app_setting_get($conn, 'support_phone', ''));
$supportWhatsapp = trim(app_setting_get($conn, 'support_whatsapp', ''));
if ($supportWhatsapp !== '' && stripos($supportWhatsapp, 'http://') !== 0 && stripos($supportWhatsapp, 'https://') !== 0) {
    $digits = preg_replace('/[^0-9]/', '', $supportWhatsapp);
    if ($digits !== '') {
        $supportWhatsapp = 'https://wa.me/' . $digits;
    }
}

$tickets = app_support_tickets_for_user($conn, $currentUserId, $canViewAllTickets, 80);
$selectedTicketId = (int)($_GET['ticket'] ?? 0);
if ($selectedTicketId <= 0 && !empty($tickets)) {
    $selectedTicketId = (int)($tickets[0]['id'] ?? 0);
}

$selectedTicket = [];
foreach ($tickets as $row) {
    if ((int)($row['id'] ?? 0) === $selectedTicketId) {
        $selectedTicket = $row;
        break;
    }
}
if (empty($selectedTicket) && $selectedTicketId > 0) {
    $ticketRow = app_support_ticket_get($conn, $selectedTicketId);
    if ($ticketRow && app_support_user_can_access_ticket($ticketRow, $currentUserId, $canViewAllTickets)) {
        $selectedTicket = $ticketRow;
    } else {
        $selectedTicketId = 0;
    }
}

if (app_license_edition() === 'client' && !empty($tickets)) {
    $syncTargets = [];
    if ($selectedTicketId > 0) {
        $syncTargets[] = $selectedTicketId;
    }
    foreach ($tickets as $row) {
        if (count($syncTargets) >= 8) {
            break;
        }
        $st = strtolower(trim((string)($row['status'] ?? 'open')));
        if (!in_array($st, ['open', 'pending', 'answered'], true)) {
            continue;
        }
        $tid = (int)($row['id'] ?? 0);
        if ($tid > 0) {
            $syncTargets[] = $tid;
        }
    }
    $syncTargets = array_values(array_unique($syncTargets));
    foreach ($syncTargets as $syncTicketId) {
        $pull = app_support_remote_pull_ticket_updates($conn, (int)$syncTicketId);
        if (!empty($pull['error']) && $noticeText === '' && (int)$syncTicketId === $selectedTicketId) {
            $syncText = lc_sync_error_text((string)$pull['error']);
            if ($syncText !== '') {
                $noticeType = 'error';
                $noticeText = $syncText;
            }
        }
    }

    // Reload after sync to reflect incoming replies/status.
    $tickets = app_support_tickets_for_user($conn, $currentUserId, $canViewAllTickets, 80);
    if ($selectedTicketId <= 0 && !empty($tickets)) {
        $selectedTicketId = (int)($tickets[0]['id'] ?? 0);
    }
    $selectedTicket = [];
    foreach ($tickets as $row) {
        if ((int)($row['id'] ?? 0) === $selectedTicketId) {
            $selectedTicket = $row;
            break;
        }
    }
}

$messages = [];
if (!empty($selectedTicket) && $selectedTicketId > 0) {
    app_support_ticket_mark_read($conn, $selectedTicketId, $isSupportAgent);
    app_support_notifications_mark_ticket_read($conn, $currentUserId, $selectedTicketId);
    $messages = app_support_ticket_messages($conn, $selectedTicketId, 300);
}

$lastMessageId = 0;
foreach ($messages as $msgRow) {
    $lastMessageId = max($lastMessageId, (int)($msgRow['id'] ?? 0));
}

$ticketOps = [
    'all' => count($tickets),
    'open' => 0,
    'pending' => 0,
    'answered' => 0,
    'closed' => 0,
    'urgent' => 0,
    'unread' => 0,
    'sync_errors' => 0,
];
foreach ($tickets as $ticketRow) {
    $tkStatus = strtolower(trim((string)($ticketRow['status'] ?? 'open')));
    if (isset($ticketOps[$tkStatus])) {
        $ticketOps[$tkStatus]++;
    }
    $tkPriority = strtolower(trim((string)($ticketRow['priority'] ?? 'normal')));
    if (in_array($tkPriority, ['urgent', 'high'], true)) {
        $ticketOps['urgent']++;
    }
    $ticketOps['unread'] += $isSupportAgent
        ? (int)($ticketRow['unread_for_admin'] ?? 0)
        : (int)($ticketRow['unread_for_client'] ?? 0);
    if (trim((string)($ticketRow['remote_sync_error'] ?? '')) !== '') {
        $ticketOps['sync_errors']++;
    }
}
$selectedPriority = strtolower(trim((string)($selectedTicket['priority'] ?? 'normal')));
$opsHeroTitle = $isOwnerOpsCenter
    ? app_tr('غرفة عمليات الدعم المركزي', 'Central Support Operations Room')
    : app_tr('مركز الترخيص والدعم', 'License & Support Center');
$opsHeroSubtitle = $isOwnerOpsCenter
    ? app_tr('استقبال التذاكر الواردة، مراقبة المسارات النشطة، والتحكم الفوري في الردود والحالات من نقطة المالك المركزية.', 'Receive incoming tickets, monitor active lanes, and control replies and statuses instantly from the owner control point.')
    : app_tr('نافذة متابعة حالة الترخيص وطلبات الدعم الخاصة بالنظام الحالي.', 'A single place to track license state and support requests for this system.');

if (isset($_GET['live_updates'])) {
    if (ob_get_length()) {
        ob_clean();
    }
    header('Content-Type: application/json; charset=utf-8');

    $ticketId = (int)($_GET['ticket'] ?? $_GET['ticket_id'] ?? 0);
    $sinceId = max(0, (int)($_GET['since'] ?? $_GET['since_id'] ?? 0));

    if ($ticketId <= 0) {
        echo json_encode(['ok' => false, 'error' => 'ticket_required'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    if (app_license_edition() === 'client') {
        app_support_remote_pull_ticket_updates($conn, $ticketId);
    }

    $ticket = app_support_ticket_get($conn, $ticketId);
    if (!$ticket) {
        echo json_encode(['ok' => true, 'deleted' => true], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
    if (!app_support_user_can_access_ticket($ticket, $currentUserId, $canViewAllTickets)) {
        echo json_encode(['ok' => false, 'access_denied' => true], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    app_support_ticket_mark_read($conn, $ticketId, $isSupportAgent);
    app_support_notifications_mark_ticket_read($conn, $currentUserId, $ticketId);

    $rows = app_support_ticket_messages($conn, $ticketId, 400);
    $newMessages = [];
    $latestId = $sinceId;
    $supportLabel = lc_support_sender_name($conn);
    $requesterLabel = lc_ticket_requester_text($ticket);
    foreach ($rows as $row) {
        $msgId = (int)($row['id'] ?? 0);
        if ($msgId <= $sinceId) {
            continue;
        }
        $latestId = max($latestId, $msgId);
        $senderRole = strtolower(trim((string)($row['sender_role'] ?? 'client')));
        $senderName = trim((string)($row['sender_name'] ?? ''));
        if ($senderRole === 'support' && app_license_edition() === 'client') {
            $senderName = $supportLabel;
        } elseif ($senderName === '') {
            $senderName = $senderRole === 'support'
                ? $supportLabel
                : $requesterLabel;
        }
        $imagePath = trim((string)($row['image_path'] ?? ''));
        $imageName = trim((string)($row['image_name'] ?? ''));
        if ($imageName === '' && $imagePath !== '') {
            $imageName = basename((string)parse_url($imagePath, PHP_URL_PATH));
        }

        $newMessages[] = [
            'id' => $msgId,
            'sender_role' => $senderRole,
            'sender_name' => $senderName,
            'message' => (string)($row['message'] ?? ''),
            'image_url' => lc_ticket_image_url($imagePath),
            'image_name' => $imageName,
            'created_at_text' => lc_datetime_text((string)($row['created_at'] ?? '')),
        ];
    }

    $status = strtolower(trim((string)($ticket['status'] ?? 'open')));
    if (!in_array($status, ['open', 'pending', 'answered', 'closed'], true)) {
        $status = 'open';
    }

    echo json_encode([
        'ok' => true,
        'ticket_id' => $ticketId,
        'last_message_id' => $latestId,
        'ticket_status' => $status,
        'ticket_status_label' => lc_ticket_status_label($status),
        'last_message_at_text' => lc_datetime_text((string)($ticket['last_message_at'] ?? '')),
        'ticket_requester_name' => $requesterLabel,
        'ticket_source' => lc_ticket_source_text($ticket),
        'new_messages' => $newMessages,
        'new_messages_count' => count($newMessages),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

require 'header.php';
?>
<style>
    .lc-wrap {
        max-width: 1560px;
        margin: 26px auto 42px;
        padding: 0 14px;
    }
    .lc-grid {
        display: grid;
        grid-template-columns: 340px minmax(0, 1fr);
        gap: 16px;
    }
    .lc-card {
        background: rgba(17, 17, 19, 0.96);
        border: 1px solid rgba(212, 175, 55, 0.28);
        border-radius: 16px;
        padding: 16px;
    }
    .lc-card h3 {
        margin: 0 0 12px;
        color: #d4af37;
        font-size: 1.12rem;
    }
    .lc-owner-strip{
        display:flex;
        align-items:flex-start;
        justify-content:space-between;
        gap:16px;
        margin-bottom:16px;
        padding:16px 18px;
        border-radius:18px;
        border:1px solid rgba(212,175,55,.24);
        background:linear-gradient(180deg,rgba(16,19,26,.96),rgba(11,14,21,.96));
    }
    .lc-owner-strip h1{
        margin:0 0 6px;
        color:#f5f7fb;
        font-size:1.55rem;
        line-height:1.15;
    }
    .lc-owner-strip p{
        margin:0;
        max-width:760px;
        color:#9fabc0;
        font-size:.92rem;
        line-height:1.7;
    }
    .lc-owner-strip-stats{
        display:flex;
        flex-wrap:wrap;
        gap:10px;
        justify-content:flex-end;
    }
    .lc-owner-pill{
        display:inline-flex;
        align-items:center;
        gap:8px;
        padding:10px 14px;
        border-radius:999px;
        border:1px solid #30384a;
        background:#10141d;
        color:#edf2fb;
        font-size:.84rem;
        font-weight:800;
        white-space:nowrap;
    }
    .lc-owner-pill strong{
        color:#d4af37;
        font-weight:900;
    }
    .lc-hero{
        position:relative;
        overflow:hidden;
        margin-bottom:16px;
        border-radius:20px;
        border:1px solid rgba(212,175,55,.26);
        background:
            radial-gradient(circle at 0% 0%, rgba(212,175,55,.18), transparent 28%),
            radial-gradient(circle at 100% 20%, rgba(86,190,255,.12), transparent 22%),
            linear-gradient(155deg,#111319,#090c13 58%,#10141d);
        box-shadow:0 18px 40px rgba(0,0,0,.32);
        padding:20px;
    }
    .lc-hero-grid{
        display:grid;
        grid-template-columns:minmax(0,1.35fr) minmax(320px,.65fr);
        gap:16px;
        align-items:stretch;
    }
    .lc-hero-kicker{
        display:inline-flex;
        align-items:center;
        gap:8px;
        padding:8px 12px;
        border-radius:999px;
        border:1px solid rgba(212,175,55,.28);
        background:rgba(212,175,55,.08);
        color:#f0d77c;
        font-size:.78rem;
        font-weight:900;
        letter-spacing:.08em;
    }
    .lc-hero h1{
        margin:14px 0 10px;
        color:#f5f7fb;
        font-size:clamp(1.7rem,2.4vw,2.4rem);
        line-height:1.08;
    }
    .lc-hero p{
        margin:0;
        max-width:780px;
        color:#9fabc0;
        line-height:1.8;
        font-size:.98rem;
    }
    .lc-hero-chips{display:flex;flex-wrap:wrap;gap:10px;margin-top:16px}
    .lc-signal{
        display:inline-flex;
        align-items:center;
        gap:8px;
        padding:9px 13px;
        border-radius:999px;
        border:1px solid #31384a;
        background:rgba(13,18,28,.88);
        color:#dfe8f9;
        font-size:.84rem;
        font-weight:800;
    }
    .lc-signal.ok{border-color:rgba(46,178,93,.45);color:#98efb6}
    .lc-signal.bad{border-color:rgba(193,70,70,.45);color:#ffb9b9}
    .lc-command{
        display:grid;
        grid-template-columns:repeat(2,minmax(0,1fr));
        gap:12px;
    }
    .lc-command-card{
        border:1px solid rgba(255,255,255,.08);
        border-radius:16px;
        background:linear-gradient(165deg,rgba(18,23,35,.92),rgba(10,13,21,.96));
        padding:14px;
        min-height:110px;
        box-shadow:inset 0 1px 0 rgba(255,255,255,.03);
    }
    .lc-command-card .k{display:block;color:#8f9ab0;font-size:.76rem;margin-bottom:10px}
    .lc-command-card .v{display:block;color:#f5f7fb;font-size:1.6rem;font-weight:900;margin-bottom:6px}
    .lc-command-card .s{display:block;color:#b6c0d4;font-size:.82rem;line-height:1.6}
    .lc-ops-grid{
        display:grid;
        grid-template-columns:repeat(4,minmax(0,1fr));
        gap:12px;
        margin:0 0 16px;
    }
    .lc-ops-tile{
        padding:16px;
        border-radius:16px;
        border:1px solid rgba(212,175,55,.18);
        background:linear-gradient(160deg,#10131a,#0b0e15);
        box-shadow:0 14px 28px rgba(0,0,0,.24);
    }
    .lc-ops-tile .k{display:block;color:#909cb2;font-size:.78rem;margin-bottom:8px}
    .lc-ops-tile .v{display:block;color:#f5f7fb;font-size:1.65rem;font-weight:900}
    .lc-ops-tile .s{display:block;color:#aeb9cd;font-size:.8rem;margin-top:6px}
    .lc-board-card{padding:14px}
    .lc-grid.owner-ops{grid-template-columns:1fr}
    .lc-grid.owner-ops .lc-board{
        grid-template-columns:360px minmax(0,1fr);
        min-height:680px;
        gap:14px;
    }
    .lc-grid.owner-ops .lc-ticket-list,
    .lc-grid.owner-ops .lc-thread{
        background:linear-gradient(180deg,#0f131d,#0a0e15);
        border-color:#283145;
    }
    .lc-grid.owner-ops .lc-ticket-list{
        max-height:none;
        min-height:680px;
    }
    .lc-grid.owner-ops .lc-ticket-item{
        background:linear-gradient(180deg,#131824,#0f141d);
        border-color:#283042;
    }
    .lc-grid.owner-ops .lc-ticket-item.active{
        background:linear-gradient(180deg,rgba(212,175,55,.16),rgba(212,175,55,.06));
        box-shadow:0 0 0 1px rgba(212,175,55,.12), 0 12px 24px rgba(0,0,0,.22);
    }
    .lc-grid.owner-ops .lc-thread{
        padding:18px;
        min-height:680px;
    }
    .lc-grid.owner-ops .lc-messages{
        max-height:none;
        min-height:440px;
    }
    .lc-board-top{
        display:flex;
        align-items:center;
        justify-content:space-between;
        gap:12px;
        margin-bottom:12px;
    }
    .lc-board-top h3{
        margin:0;
    }
    .lc-board-top .lc-contact-chips{
        margin:0;
    }
    .lc-notice {
        margin-bottom: 12px;
        border-radius: 12px;
        padding: 10px 12px;
        border: 1px solid;
        font-weight: 700;
    }
    .lc-notice.ok {
        background: rgba(46, 178, 93, 0.15);
        border-color: rgba(46, 178, 93, 0.4);
        color: #93f5bf;
    }
    .lc-notice.err {
        background: rgba(193, 70, 70, 0.15);
        border-color: rgba(193, 70, 70, 0.42);
        color: #ffb5b5;
    }

    .lc-status-pill {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 7px 12px;
        border-radius: 999px;
        border: 1px solid transparent;
        font-weight: 800;
        font-size: 0.92rem;
        margin-bottom: 12px;
    }
    .lc-status-pill.is-good {
        background: rgba(46, 178, 93, 0.18);
        color: #7ef0ad;
        border-color: rgba(46, 178, 93, 0.34);
    }
    .lc-status-pill.is-bad {
        background: rgba(193, 70, 70, 0.18);
        color: #ffaeae;
        border-color: rgba(193, 70, 70, 0.35);
    }
    .lc-status-row {
        display: grid;
        grid-template-columns: 115px 1fr;
        gap: 8px;
        align-items: center;
        margin-bottom: 10px;
    }
    .lc-status-label {
        color: #a2a2a2;
        font-size: 0.86rem;
    }
    .lc-status-value {
        color: #ececec;
        font-size: 0.93rem;
        font-weight: 700;
    }

    .lc-contact-chips {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        margin-bottom: 12px;
    }
    .lc-chip {
        border: 1px solid #3b3b3f;
        border-radius: 999px;
        padding: 7px 10px;
        font-size: 0.82rem;
        color: #ddd;
        text-decoration: none;
        background: rgba(255,255,255,0.02);
    }
    .lc-chip:hover {
        border-color: rgba(212,175,55,0.55);
        color: #fff;
        background: rgba(212,175,55,0.08);
    }
    .lc-form-grid {
        display: grid;
        grid-template-columns: 1fr 170px 220px 220px;
        gap: 10px;
    }
    .lc-field {
        display: flex;
        flex-direction: column;
        gap: 6px;
    }
    .lc-field label {
        color: #afafaf;
        font-size: 0.84rem;
    }
    .lc-field input,
    .lc-field select,
    .lc-field textarea {
        min-height: 40px;
        border-radius: 10px;
        border: 1px solid #383838;
        background: #0c0d10;
        color: #f3f3f3;
        padding: 9px 11px;
        font-family: inherit;
    }
    .lc-field input[type="file"] {
        min-height: 44px;
        padding: 8px 10px;
    }
    .lc-field textarea {
        min-height: 84px;
        resize: vertical;
    }
    .lc-btn {
        border: 1px solid transparent;
        border-radius: 10px;
        padding: 9px 14px;
        font-weight: 800;
        cursor: pointer;
    }
    .lc-btn.gold {
        background: #d4af37;
        color: #101010;
    }
    .lc-btn.dark {
        background: #1f1f24;
        border-color: #404048;
        color: #eee;
    }
    .lc-btn.danger {
        background: rgba(184, 64, 64, 0.2);
        border-color: rgba(184, 64, 64, 0.5);
        color: #ffc0c0;
    }

    .lc-divider {
        height: 1px;
        background: rgba(255,255,255,0.08);
        margin: 14px 0;
    }

    .lc-board {
        display: grid;
        grid-template-columns: 280px minmax(0, 1fr);
        gap: 12px;
        min-height: 410px;
    }
    .lc-ticket-list {
        border: 1px solid #313136;
        background: #111216;
        border-radius: 12px;
        padding: 8px;
        overflow: auto;
        max-height: 560px;
    }
    .lc-ticket-item {
        display: block;
        border: 1px solid #2c2c31;
        border-radius: 10px;
        padding: 9px;
        margin-bottom: 8px;
        text-decoration: none;
        color: #ececec;
        background: #15161b;
    }
    .lc-ticket-item.active {
        border-color: rgba(212,175,55,0.54);
        background: rgba(212,175,55,0.12);
    }
    .lc-ticket-subject {
        font-size: 0.86rem;
        font-weight: 800;
        line-height: 1.35;
        margin-bottom: 5px;
    }
    .lc-ticket-origin {
        display: flex;
        flex-direction: column;
        gap: 3px;
        margin-bottom: 6px;
        font-size: 0.74rem;
        color: #b9b9b9;
    }
    .lc-ticket-origin b {
        color: #e3e3e3;
        font-weight: 700;
    }
    .lc-ticket-meta {
        display: flex;
        flex-wrap: wrap;
        gap: 6px;
        align-items: center;
        font-size: 0.75rem;
        color: #b0b0b0;
    }
    .lc-badge {
        display: inline-flex;
        align-items: center;
        border-radius: 999px;
        border: 1px solid #3c3c44;
        padding: 3px 8px;
        font-size: 0.72rem;
        font-weight: 800;
    }
    .lc-badge.open { color: #ffd580; border-color: rgba(212,175,55,.45); }
    .lc-badge.pending { color: #b8d3ff; border-color: rgba(112,153,255,.42); }
    .lc-badge.answered { color: #95f5bf; border-color: rgba(72,194,117,.44); }
    .lc-badge.closed { color: #d1d1d1; border-color: rgba(165,165,165,.42); }
    .lc-badge.error { color: #ffb4b4; border-color: rgba(201,84,84,.5); background: rgba(201,84,84,.12); }
    .lc-sync-line {
        margin-top: 6px;
        font-size: 0.73rem;
        color: #ffb0b0;
        line-height: 1.45;
        word-break: break-word;
    }
    .lc-badge.unread {
        color: #6ef0a0;
        border-color: rgba(66,194,113,.5);
        background: rgba(66,194,113,.14);
    }

    .lc-thread {
        border: 1px solid #313136;
        background: #111216;
        border-radius: 12px;
        padding: 12px;
        display: flex;
        flex-direction: column;
        min-height: 410px;
    }
    .lc-thread-head {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
        margin-bottom: 10px;
    }
    .lc-thread-title {
        color: #ececec;
        font-size: 1rem;
        font-weight: 800;
    }
    .lc-thread-origin {
        display: flex;
        flex-wrap: wrap;
        gap: 8px 14px;
        margin-top: 6px;
        color: #c4c4c4;
        font-size: 0.8rem;
    }
    .lc-thread-origin b {
        color: #f0f0f0;
    }
    .lc-status-form {
        display: inline-flex;
        align-items: center;
        gap: 8px;
    }
    .lc-status-form select {
        min-height: 36px;
        border-radius: 9px;
        border: 1px solid #3d3d46;
        background: #0e0f13;
        color: #f2f2f2;
        padding: 0 10px;
    }

    .lc-messages {
        flex: 1;
        overflow: auto;
        border: 1px solid #2d2d32;
        border-radius: 10px;
        background: #0f1014;
        padding: 10px;
        margin-bottom: 10px;
        max-height: 360px;
    }
    .lc-msg-row {
        display: flex;
        margin-bottom: 10px;
    }
    .lc-msg-row.client { justify-content: flex-start; }
    .lc-msg-row.support { justify-content: flex-end; }
    .lc-msg {
        max-width: min(620px, 100%);
        border-radius: 12px;
        border: 1px solid #34343b;
        background: #16171c;
        padding: 8px 10px;
    }
    .lc-msg-row.support .lc-msg {
        border-color: rgba(212,175,55,.52);
        background: rgba(212,175,55,.11);
    }
    .lc-msg-head {
        display: flex;
        align-items: center;
        gap: 8px;
        font-size: 0.74rem;
        color: #b8b8b8;
        margin-bottom: 5px;
    }
    .lc-msg-text {
        color: #efefef;
        font-size: 0.88rem;
        line-height: 1.55;
        white-space: pre-wrap;
        word-break: break-word;
    }
    .lc-msg-image {
        margin-top: 8px;
    }
    .lc-msg-image a {
        display: inline-block;
        border: 1px solid rgba(212,175,55,.35);
        border-radius: 10px;
        overflow: hidden;
        max-width: min(360px, 100%);
    }
    .lc-msg-image img {
        display: block;
        width: 100%;
        height: auto;
        max-height: 320px;
        object-fit: contain;
        background: #090a0d;
    }
    .lc-msg-image-name {
        margin-top: 6px;
        font-size: 0.74rem;
        color: #bdbdbd;
    }

    .lc-reply-form textarea {
        width: 100%;
        min-height: 90px;
        border-radius: 10px;
        border: 1px solid #3a3a40;
        background: #0b0c10;
        color: #f2f2f2;
        padding: 10px;
        font-family: inherit;
        resize: vertical;
        margin-bottom: 8px;
    }
    .lc-reply-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        align-items: center;
        justify-content: space-between;
    }
    .lc-reply-actions select {
        min-height: 38px;
        border-radius: 9px;
        border: 1px solid #3d3d46;
        background: #0e0f13;
        color: #f2f2f2;
        padding: 0 10px;
    }

    .lc-empty {
        border: 1px dashed #3a3a42;
        border-radius: 11px;
        color: #a6a6a6;
        padding: 12px;
        text-align: center;
    }

    @media (max-width: 1100px) {
        .lc-hero-grid {
            grid-template-columns:1fr;
        }
        .lc-command,.lc-ops-grid {
            grid-template-columns:repeat(2,minmax(0,1fr));
        }
        .lc-owner-strip {
            flex-direction:column;
        }
        .lc-owner-strip-stats {
            justify-content:flex-start;
        }
        .lc-grid {
            grid-template-columns: 1fr;
        }
        .lc-form-grid {
            grid-template-columns: 1fr 1fr;
        }
        .lc-board {
            grid-template-columns: 1fr;
        }
        .lc-ticket-list {
            max-height: 280px;
        }
    }
    @media (max-width: 700px) {
        .lc-command,.lc-ops-grid {
            grid-template-columns:1fr;
        }
        .lc-wrap {
            margin-top: 16px;
            padding: 0 10px;
        }
        .lc-owner-strip{
            padding:14px;
        }
        .lc-owner-strip h1{
            font-size:1.3rem;
        }
        .lc-card {
            padding: 12px;
            border-radius: 14px;
        }
        .lc-card h3 {
            font-size: 1rem;
        }
        .lc-form-grid {
            grid-template-columns: 1fr;
        }
        .lc-status-row {
            grid-template-columns: 1fr;
        }
        .lc-board {
            min-height: 0;
            gap: 10px;
        }
        .lc-ticket-list {
            max-height: 250px;
            padding: 7px;
        }
        .lc-ticket-item {
            padding: 8px;
        }
        .lc-ticket-subject {
            font-size: 0.9rem;
        }
        .lc-thread {
            min-height: 0;
            padding: 10px;
        }
        .lc-thread-head {
            align-items: stretch;
        }
        .lc-status-form {
            width: 100%;
            justify-content: space-between;
        }
        .lc-status-form select,
        .lc-status-form .lc-btn {
            min-height: 36px;
        }
        .lc-messages {
            max-height: 300px;
            padding: 8px;
        }
        .lc-msg-row.client,
        .lc-msg-row.support {
            justify-content: flex-start;
        }
        .lc-msg {
            width: 100%;
            max-width: 100%;
        }
        .lc-msg-text {
            font-size: 0.92rem;
            line-height: 1.65;
        }
        .lc-reply-form textarea {
            min-height: 110px;
            font-size: 0.93rem;
        }
        .lc-reply-actions {
            flex-direction: column;
            align-items: stretch;
            gap: 8px;
        }
        .lc-reply-actions > div,
        .lc-reply-actions select,
        .lc-reply-actions .lc-btn {
            width: 100%;
        }
    }
</style>

<div class="container lc-wrap">
    <?php if ($noticeText !== ''): ?>
        <div class="lc-notice <?php echo $noticeType === 'success' ? 'ok' : 'err'; ?>"><?php echo app_h($noticeText); ?></div>
    <?php endif; ?>

    <?php if ($isOwnerOpsCenter): ?>
    <section class="lc-owner-strip">
        <div>
            <h1><?php echo app_h(app_tr('غرفة عمليات الدعم', 'Support Operations Room')); ?></h1>
            <p><?php echo app_h(app_tr('قائمة واضحة للتذاكر من اليسار، ومحادثة كاملة من اليمين، مع مؤشرات تشغيل مختصرة فقط.', 'A clear ticket list on the left, a full conversation on the right, and only compact operating indicators.')); ?></p>
        </div>
        <div class="lc-owner-strip-stats">
            <span class="lc-owner-pill"><i class="fa-solid fa-inbox"></i> <?php echo app_h(app_tr('مفتوحة', 'Open')); ?> <strong><?php echo (int)$ticketOps['open']; ?></strong></span>
            <span class="lc-owner-pill"><i class="fa-solid fa-hourglass-half"></i> <?php echo app_h(app_tr('قيد المتابعة', 'Pending')); ?> <strong><?php echo (int)$ticketOps['pending']; ?></strong></span>
            <span class="lc-owner-pill"><i class="fa-solid fa-envelope-open-text"></i> <?php echo app_h(app_tr('تم الرد', 'Answered')); ?> <strong><?php echo (int)$ticketOps['answered']; ?></strong></span>
            <span class="lc-owner-pill"><i class="fa-solid fa-circle-check"></i> <?php echo app_h(app_tr('مغلقة', 'Closed')); ?> <strong><?php echo (int)$ticketOps['closed']; ?></strong></span>
            <?php if ($ticketOps['sync_errors'] > 0): ?>
                <span class="lc-owner-pill"><i class="fa-solid fa-wave-square"></i> <?php echo app_h(app_tr('أخطاء مزامنة', 'Sync Errors')); ?> <strong><?php echo (int)$ticketOps['sync_errors']; ?></strong></span>
            <?php endif; ?>
        </div>
    </section>
    <?php else: ?>
    <section class="lc-hero">
        <div class="lc-hero-grid">
            <div>
                <div class="lc-hero-kicker"><i class="fa-solid fa-satellite-dish"></i> <?php echo app_h(app_tr('وضع المتابعة الحالي', 'Current Tracking Mode')); ?></div>
                <h1><?php echo app_h($opsHeroTitle); ?></h1>
                <p><?php echo app_h($opsHeroSubtitle); ?></p>
                <div class="lc-hero-chips">
                    <span class="lc-signal <?php echo $isBlocked ? 'bad' : 'ok'; ?>"><i class="fa-solid fa-shield-halved"></i> <?php echo app_h($statusText); ?></span>
                    <span class="lc-signal"><i class="fa-solid fa-cubes"></i> <?php echo app_h(lc_plan_text($plan)); ?></span>
                    <span class="lc-signal <?php echo $ticketOps['sync_errors'] > 0 ? 'bad' : 'ok'; ?>"><i class="fa-solid fa-wave-square"></i> <?php echo app_h(app_tr('أخطاء المزامنة', 'Sync Errors')); ?>: <?php echo (int)$ticketOps['sync_errors']; ?></span>
                    <span class="lc-signal <?php echo $ticketOps['unread'] > 0 ? 'bad' : ''; ?>"><i class="fa-solid fa-bell"></i> <?php echo app_h(app_tr('غير مقروء', 'Unread')); ?>: <?php echo (int)$ticketOps['unread']; ?></span>
                </div>
            </div>
            <div class="lc-command">
                <div class="lc-command-card">
                    <span class="k"><?php echo app_h(app_tr('المسار النشط', 'Active Route')); ?></span>
                    <span class="v"><?php echo app_h($isOwnerOpsCenter ? app_tr('OWNER', 'OWNER') : app_tr('CLIENT', 'CLIENT')); ?></span>
                    <span class="s"><?php echo app_h($isOwnerOpsCenter ? app_tr('استقبال وتوزيع ومراقبة التذاكر الواردة من الأنظمة المتصلة.', 'Receive, route, and monitor incoming tickets from connected systems.') : app_tr('إرسال الطلبات وتتبع الردود الواردة من مركز المالك.', 'Send requests and track inbound replies from the owner center.')); ?></span>
                </div>
                <div class="lc-command-card">
                    <span class="k"><?php echo app_h(app_tr('التذكرة النشطة', 'Focused Ticket')); ?></span>
                    <span class="v"><?php echo $selectedTicketId > 0 ? '#' . (int)$selectedTicketId : '--'; ?></span>
                    <span class="s"><?php echo app_h($selectedTicketId > 0 ? lc_ticket_status_label((string)($selectedTicket['status'] ?? 'open')) : app_tr('لا توجد تذكرة محددة حالياً.', 'No active ticket selected right now.')); ?></span>
                </div>
                <div class="lc-command-card">
                    <span class="k"><?php echo app_h(app_tr('الجهة الأخيرة', 'Last Source')); ?></span>
                    <span class="v" style="font-size:1rem;"><?php echo app_h(!empty($selectedTicket) ? lc_ticket_source_text($selectedTicket) : app_tr('لا يوجد', 'None')); ?></span>
                    <span class="s"><?php echo app_h($isOwnerOpsCenter ? app_tr('المجال أو هوية النظام القادم منه الطلب الجاري.', 'Domain or installation identity of the current inbound request.') : app_tr('المسار الحالي الذي يتلقى الردود الخاصة بهذه النسخة.', 'Current source route that serves this installation.')); ?></span>
                </div>
                <div class="lc-command-card">
                    <span class="k"><?php echo app_h(app_tr('أولوية التذكرة', 'Ticket Priority')); ?></span>
                    <span class="v" style="font-size:1rem;"><?php echo app_h($selectedTicketId > 0 ? strtoupper($selectedPriority) : '--'); ?></span>
                    <span class="s"><?php echo app_h($ticketOps['urgent'] > 0 ? app_tr('يوجد ضغط وارد على الصف العاجل ويحتاج متابعة فورية.', 'Urgent queue contains inbound pressure and needs immediate follow-up.') : app_tr('لا توجد تذاكر عاجلة حالياً.', 'No urgent tickets at the moment.')); ?></span>
                </div>
            </div>
        </div>
    </section>
    <?php endif; ?>

    <div class="lc-grid <?php echo $isOwnerOpsCenter ? 'owner-ops' : ''; ?>">
        <?php if (!$isOwnerOpsCenter): ?>
        <section class="lc-card">
            <h3><?php echo app_h(app_tr('حالة الترخيص الحالية', 'Current License Status')); ?></h3>
            <div class="lc-status-pill <?php echo $statusClass; ?>"><?php echo app_h($statusText); ?></div>

            <div class="lc-status-row">
                <div class="lc-status-label"><?php echo app_h(app_tr('الخطة', 'Plan')); ?></div>
                <div class="lc-status-value"><?php echo app_h(lc_plan_text($plan)); ?></div>
            </div>
            <div class="lc-status-row">
                <div class="lc-status-label"><?php echo app_h(app_tr('الحالة', 'Status')); ?></div>
                <div class="lc-status-value"><?php echo app_h(lc_status_text($status)); ?></div>
            </div>
            <div class="lc-status-row">
                <div class="lc-status-label"><?php echo app_h(app_tr('تاريخ الانتهاء', 'Expires At')); ?></div>
                <div class="lc-status-value"><?php echo app_h($expiryText); ?></div>
            </div>
            <div class="lc-status-row">
                <div class="lc-status-label"><?php echo app_h(app_tr('أيام متبقية', 'Days Left')); ?></div>
                <div class="lc-status-value"><?php echo app_h($daysLeft); ?></div>
            </div>
        </section>

        <section class="lc-card">
            <h3><?php echo app_h(app_tr('التواصل مع خدمة العملاء', 'Customer Support')); ?></h3>

            <div class="lc-contact-chips">
                <?php if ($supportEmail !== ''): ?>
                    <a class="lc-chip" href="mailto:<?php echo app_h($supportEmail); ?>"><i class="fa-solid fa-envelope"></i> <?php echo app_h($supportEmail); ?></a>
                <?php endif; ?>
                <?php if ($supportPhone !== ''): ?>
                    <a class="lc-chip" href="tel:<?php echo app_h($supportPhone); ?>"><i class="fa-solid fa-phone"></i> <?php echo app_h($supportPhone); ?></a>
                <?php endif; ?>
                <?php if ($supportWhatsapp !== ''): ?>
                    <a class="lc-chip" href="<?php echo app_h($supportWhatsapp); ?>" target="_blank" rel="noopener"><i class="fa-brands fa-whatsapp"></i> <?php echo app_h(app_tr('واتساب الدعم', 'Support WhatsApp')); ?></a>
                <?php endif; ?>
                <?php if ($supportEmail === '' && $supportPhone === '' && $supportWhatsapp === ''): ?>
                    <span class="lc-chip"><?php echo app_h(app_tr('أرسل تذكرة مباشرة وسنرد عليك عبر الجرس.', 'Open a ticket directly and we will reply via the bell notifications.')); ?></span>
                <?php endif; ?>
            </div>

            <form method="post" enctype="multipart/form-data">
                <?php echo app_csrf_field(); ?>
                <input type="hidden" name="action" value="open_ticket">
                <div class="lc-form-grid">
                    <div class="lc-field">
                        <label><?php echo app_h(app_tr('عنوان التذكرة', 'Ticket Subject')); ?></label>
                        <input type="text" name="ticket_subject" maxlength="220" placeholder="<?php echo app_h(app_tr('مثال: مشكلة في التفعيل', 'Example: Activation issue')); ?>" required>
                    </div>
                    <div class="lc-field">
                        <label><?php echo app_h(app_tr('الأولوية', 'Priority')); ?></label>
                        <select name="ticket_priority">
                            <option value="normal"><?php echo app_h(app_tr('متوسطة', 'Normal')); ?></option>
                            <option value="high"><?php echo app_h(app_tr('عالية', 'High')); ?></option>
                            <option value="urgent"><?php echo app_h(app_tr('عاجلة', 'Urgent')); ?></option>
                            <option value="low"><?php echo app_h(app_tr('منخفضة', 'Low')); ?></option>
                        </select>
                    </div>
                    <div class="lc-field">
                        <label><?php echo app_h(app_tr('البريد للتواصل', 'Contact Email')); ?></label>
                        <input type="email" name="ticket_email" value="<?php echo app_h((string)($_SESSION['email'] ?? '')); ?>" maxlength="190">
                    </div>
                    <div class="lc-field">
                        <label><?php echo app_h(app_tr('الهاتف للتواصل', 'Contact Phone')); ?></label>
                        <input type="text" name="ticket_phone" value="" maxlength="80">
                    </div>
                </div>
                <div class="lc-field" style="margin-top:10px;">
                    <label><?php echo app_h(app_tr('تفاصيل المشكلة (اختياري مع الصورة)', 'Issue details (optional if image is attached)')); ?></label>
                    <textarea name="ticket_message" placeholder="<?php echo app_h(app_tr('اكتب وصفًا واضحًا للمشكلة أو الطلب...', 'Describe the issue or request clearly...')); ?>"></textarea>
                </div>
                <div class="lc-field" style="margin-top:10px;">
                    <label><?php echo app_h(app_tr('إرفاق صورة فقط (اختياري)', 'Attach image only (optional)')); ?></label>
                    <input type="file" name="ticket_image" accept="image/*">
                </div>
                <div style="display:flex;justify-content:flex-end;margin-top:8px;">
                    <button type="submit" class="lc-btn gold"><i class="fa-solid fa-life-ring"></i> <?php echo app_h(app_tr('فتح تذكرة', 'Open Ticket')); ?></button>
                </div>
            </form>

            <div class="lc-divider"></div>
        <?php else: ?>
        <section class="lc-card lc-board-card">
            <div class="lc-board-top">
                <h3><?php echo app_h(app_tr('التذاكر الواردة', 'Inbound Tickets')); ?></h3>
                <div class="lc-contact-chips">
                    <span class="lc-chip"><i class="fa-solid fa-layer-group"></i> <?php echo app_h(app_tr('الإجمالي', 'Total')); ?>: <?php echo (int)$ticketOps['all']; ?></span>
                    <span class="lc-chip"><i class="fa-solid fa-bell"></i> <?php echo app_h(app_tr('غير مقروء', 'Unread')); ?>: <?php echo (int)$ticketOps['unread']; ?></span>
                </div>
            </div>
            <div class="lc-status-pill <?php echo $ticketOps['sync_errors'] > 0 ? 'is-bad' : 'is-good'; ?>"><?php echo app_h($ticketOps['sync_errors'] > 0 ? app_tr('يوجد انحراف مزامنة يحتاج متابعة', 'Sync drift detected and needs attention') : app_tr('المسار يعمل بصورة مستقرة', 'Flow is operating normally')); ?></div>
            <div class="lc-contact-chips" style="margin-bottom:14px;">
                <span class="lc-chip"><i class="fa-solid fa-inbox"></i> <?php echo app_h(app_tr('مفتوحة', 'Open')); ?>: <?php echo (int)$ticketOps['open']; ?></span>
                <span class="lc-chip"><i class="fa-solid fa-hourglass-half"></i> <?php echo app_h(app_tr('قيد المتابعة', 'Pending')); ?>: <?php echo (int)$ticketOps['pending']; ?></span>
                <span class="lc-chip"><i class="fa-solid fa-envelope-open-text"></i> <?php echo app_h(app_tr('تم الرد', 'Answered')); ?>: <?php echo (int)$ticketOps['answered']; ?></span>
                <span class="lc-chip"><i class="fa-solid fa-circle-check"></i> <?php echo app_h(app_tr('مغلقة', 'Closed')); ?>: <?php echo (int)$ticketOps['closed']; ?></span>
                <?php if ($ticketOps['sync_errors'] > 0): ?>
                    <span class="lc-chip"><i class="fa-solid fa-wave-square"></i> <?php echo app_h(app_tr('أخطاء مزامنة', 'Sync Errors')); ?>: <?php echo (int)$ticketOps['sync_errors']; ?></span>
                <?php endif; ?>
            </div>
        <?php endif; ?>

            <div class="lc-board">
                <aside class="lc-ticket-list">
                    <?php if (empty($tickets)): ?>
                        <div class="lc-empty"><?php echo app_h(app_tr('لا توجد تذاكر حالياً.', 'No tickets yet.')); ?></div>
                    <?php else: ?>
                        <?php foreach ($tickets as $tk): ?>
                            <?php
                                $tkId = (int)($tk['id'] ?? 0);
                                $tkStatus = strtolower(trim((string)($tk['status'] ?? 'open')));
                                $tkUnread = $isSupportAgent ? (int)($tk['unread_for_admin'] ?? 0) : (int)($tk['unread_for_client'] ?? 0);
                                $tkSyncError = trim((string)($tk['remote_sync_error'] ?? ''));
                                $tkSyncText = lc_sync_error_text($tkSyncError);
                                $tkRequester = lc_ticket_requester_text($tk);
                                $tkSource = lc_ticket_source_text($tk);
                                $isActiveTk = ($tkId === $selectedTicketId);
                            ?>
                            <a href="license_center.php?ticket=<?php echo $tkId; ?>" class="lc-ticket-item <?php echo $isActiveTk ? 'active' : ''; ?>" data-ticket-id="<?php echo $tkId; ?>">
                                <div class="lc-ticket-subject"><?php echo app_h((string)($tk['subject'] ?? app_tr('بدون عنوان', 'No subject'))); ?></div>
                                <div class="lc-ticket-origin">
                                    <span><?php echo app_h(app_tr('المستخدم:', 'User:')); ?> <b data-role="ticket-requester"><?php echo app_h($tkRequester); ?></b></span>
                                    <span><?php echo app_h(app_tr('الموقع:', 'Source:')); ?> <b data-role="ticket-source"><?php echo app_h($tkSource); ?></b></span>
                                </div>
                                <div class="lc-ticket-meta">
                                    <span class="lc-badge <?php echo app_h($tkStatus); ?>" data-role="ticket-status"><?php echo app_h(lc_ticket_status_label($tkStatus)); ?></span>
                                    <span data-role="ticket-time"><?php echo app_h(lc_datetime_text((string)($tk['last_message_at'] ?? ''))); ?></span>
                                    <?php if ($tkUnread > 0): ?>
                                        <span class="lc-badge unread"><?php echo $tkUnread; ?> <?php echo app_h(app_tr('غير مقروء', 'unread')); ?></span>
                                    <?php endif; ?>
                                    <?php if ($tkSyncError !== ''): ?>
                                        <span class="lc-badge error" title="<?php echo app_h($tkSyncError); ?>"><?php echo app_h(app_tr('خطأ مزامنة', 'Sync error')); ?></span>
                                    <?php endif; ?>
                                </div>
                                <?php if ($tkSyncText !== ''): ?>
                                    <div class="lc-sync-line"><?php echo app_h($tkSyncText); ?></div>
                                <?php endif; ?>
                            </a>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </aside>

                <div class="lc-thread">
                    <?php if (empty($selectedTicket)): ?>
                        <div class="lc-empty"><?php echo app_h(app_tr('اختر تذكرة من القائمة لعرض المحادثة.', 'Select a ticket from the list to view conversation.')); ?></div>
                    <?php else: ?>
                        <?php
                            $selectedStatus = strtolower(trim((string)($selectedTicket['status'] ?? 'open')));
                            $selectedSubject = (string)($selectedTicket['subject'] ?? app_tr('بدون عنوان', 'No subject'));
                            $selectedSyncError = trim((string)($selectedTicket['remote_sync_error'] ?? ''));
                            $selectedSyncText = lc_sync_error_text($selectedSyncError);
                            $selectedRequester = lc_ticket_requester_text($selectedTicket);
                            $selectedSource = lc_ticket_source_text($selectedTicket);
                            $supportSenderLabel = lc_support_sender_name($conn);
                        ?>
                        <div class="lc-thread-head">
                            <div>
                                <div class="lc-thread-title">#<?php echo (int)$selectedTicket['id']; ?> - <?php echo app_h($selectedSubject); ?></div>
                                <div class="lc-thread-origin">
                                    <span><?php echo app_h(app_tr('المستخدم:', 'User:')); ?> <b id="lcSelectedRequester"><?php echo app_h($selectedRequester); ?></b></span>
                                    <span><?php echo app_h(app_tr('الموقع:', 'Source:')); ?> <b id="lcSelectedSource"><?php echo app_h($selectedSource); ?></b></span>
                                </div>
                                <div class="lc-ticket-meta" style="margin-top:4px;">
                                    <span id="lcSelectedStatusBadge" class="lc-badge <?php echo app_h($selectedStatus); ?>" data-status="<?php echo app_h($selectedStatus); ?>"><?php echo app_h(lc_ticket_status_label($selectedStatus)); ?></span>
                                    <span><?php echo app_h(app_tr('آخر تحديث:', 'Last update:')); ?> <span id="lcSelectedLastUpdate"><?php echo app_h(lc_datetime_text((string)($selectedTicket['last_message_at'] ?? ''))); ?></span></span>
                                </div>
                            </div>

                            <?php if ($isSupportAgent): ?>
                                <form method="post" class="lc-status-form">
                                    <?php echo app_csrf_field(); ?>
                                    <input type="hidden" name="action" value="update_ticket_status">
                                    <input type="hidden" name="ticket_id" value="<?php echo (int)$selectedTicket['id']; ?>">
                                    <select name="ticket_status">
                                        <option value="open" <?php echo $selectedStatus === 'open' ? 'selected' : ''; ?>><?php echo app_h(app_tr('مفتوحة', 'Open')); ?></option>
                                        <option value="pending" <?php echo $selectedStatus === 'pending' ? 'selected' : ''; ?>><?php echo app_h(app_tr('قيد المتابعة', 'Pending')); ?></option>
                                        <option value="answered" <?php echo $selectedStatus === 'answered' ? 'selected' : ''; ?>><?php echo app_h(app_tr('تم الرد', 'Answered')); ?></option>
                                        <option value="closed" <?php echo $selectedStatus === 'closed' ? 'selected' : ''; ?>><?php echo app_h(app_tr('مغلقة', 'Closed')); ?></option>
                                    </select>
                                    <button type="submit" class="lc-btn dark"><?php echo app_h(app_tr('حفظ الحالة', 'Save Status')); ?></button>
                                </form>
                            <?php endif; ?>
                            <form method="post" class="lc-status-form" onsubmit="return confirm('<?php echo app_h(app_tr('سيتم حذف المحادثة بالكامل لديك ولدى الطرف الآخر. هل تريد المتابعة؟', 'This will delete the conversation on both sides. Continue?')); ?>');">
                                <?php echo app_csrf_field(); ?>
                                <input type="hidden" name="action" value="delete_ticket">
                                <input type="hidden" name="ticket_id" value="<?php echo (int)$selectedTicket['id']; ?>">
                                <button type="submit" class="lc-btn danger"><i class="fa-solid fa-trash"></i> <?php echo app_h(app_tr('حذف المحادثة', 'Delete Conversation')); ?></button>
                            </form>
                        </div>

                        <?php if ($selectedSyncText !== ''): ?>
                            <div class="lc-notice err" style="margin:0 0 10px;">
                                <?php echo app_h($selectedSyncText); ?>
                            </div>
                        <?php endif; ?>

                        <div class="lc-messages" id="lcMessagesBox" data-last-id="<?php echo (int)$lastMessageId; ?>">
                            <?php if (empty($messages)): ?>
                                <div class="lc-empty"><?php echo app_h(app_tr('لا توجد رسائل داخل هذه التذكرة بعد.', 'No messages in this ticket yet.')); ?></div>
                            <?php else: ?>
                                <?php foreach ($messages as $msgRow): ?>
                                    <?php
                                        $senderRole = strtolower(trim((string)($msgRow['sender_role'] ?? 'client')));
                                        $senderName = trim((string)($msgRow['sender_name'] ?? ''));
                                        $messageText = trim((string)($msgRow['message'] ?? ''));
                                        $imagePath = trim((string)($msgRow['image_path'] ?? ''));
                                        $imageUrl = lc_ticket_image_url($imagePath);
                                        $imageName = trim((string)($msgRow['image_name'] ?? ''));
                                        if ($imageName === '' && $imagePath !== '') {
                                            $imageName = basename((string)parse_url($imagePath, PHP_URL_PATH));
                                        }
                                        if ($senderRole === 'support' && app_license_edition() === 'client') {
                                            $senderName = $supportSenderLabel;
                                        } elseif ($senderName === '') {
                                            $senderName = $senderRole === 'support'
                                                ? $supportSenderLabel
                                                : $selectedRequester;
                                        }
                                    ?>
                                    <div class="lc-msg-row <?php echo app_h($senderRole); ?>" data-msg-id="<?php echo (int)($msgRow['id'] ?? 0); ?>">
                                        <div class="lc-msg">
                                            <div class="lc-msg-head">
                                                <strong><?php echo app_h($senderName); ?></strong>
                                                <span>•</span>
                                                <span><?php echo app_h(lc_datetime_text((string)($msgRow['created_at'] ?? ''))); ?></span>
                                            </div>
                                            <?php if ($messageText !== ''): ?>
                                                <div class="lc-msg-text"><?php echo nl2br(app_h($messageText)); ?></div>
                                            <?php endif; ?>
                                            <?php if ($imageUrl !== ''): ?>
                                                <div class="lc-msg-image">
                                                    <a href="<?php echo app_h($imageUrl); ?>" target="_blank" rel="noopener">
                                                        <img src="<?php echo app_h($imageUrl); ?>" alt="<?php echo app_h($imageName !== '' ? $imageName : app_tr('مرفق صورة', 'Image attachment')); ?>" loading="lazy">
                                                    </a>
                                                    <?php if ($imageName !== ''): ?>
                                                        <div class="lc-msg-image-name"><?php echo app_h($imageName); ?></div>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>

                        <form method="post" class="lc-reply-form" enctype="multipart/form-data">
                            <?php echo app_csrf_field(); ?>
                            <input type="hidden" name="action" value="reply_ticket">
                            <input type="hidden" name="ticket_id" value="<?php echo (int)$selectedTicket['id']; ?>">
                            <textarea name="reply_message" placeholder="<?php echo app_h(app_tr('اكتب ردك هنا (اختياري مع الصورة)...', 'Write your reply (optional with image)...')); ?>"></textarea>
                            <div class="lc-field" style="margin-bottom:8px;">
                                <label><?php echo app_h(app_tr('إرفاق صورة فقط (اختياري)', 'Attach image only (optional)')); ?></label>
                                <input type="file" name="reply_image" accept="image/*">
                            </div>
                            <div class="lc-reply-actions">
                                <div>
                                    <?php if ($isSupportAgent): ?>
                                        <select name="reply_status">
                                            <option value="answered"><?php echo app_h(app_tr('تعيين الحالة: تم الرد', 'Set status: Answered')); ?></option>
                                            <option value="pending"><?php echo app_h(app_tr('تعيين الحالة: قيد المتابعة', 'Set status: Pending')); ?></option>
                                            <option value="open"><?php echo app_h(app_tr('تعيين الحالة: مفتوحة', 'Set status: Open')); ?></option>
                                            <option value="closed"><?php echo app_h(app_tr('تعيين الحالة: مغلقة', 'Set status: Closed')); ?></option>
                                        </select>
                                    <?php endif; ?>
                                </div>
                                <button type="submit" class="lc-btn gold"><i class="fa-solid fa-paper-plane"></i> <?php echo app_h(app_tr('إرسال الرد', 'Send Reply')); ?></button>
                            </div>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </section>
    </div>
</div>

<script>
(() => {
    const selectedTicketId = <?php echo (int)$selectedTicketId; ?>;
    if (!selectedTicketId) return;

    const messagesBox = document.getElementById('lcMessagesBox');
    if (!messagesBox) return;

    const isMobileLite = window.matchMedia('(max-width: 900px)').matches;
    const pollMs = isMobileLite ? 5500 : 2500;
    const lastUpdatePrefix = <?php echo json_encode(app_tr('آخر تحديث:', 'Last update:'), JSON_UNESCAPED_UNICODE); ?>;
    const forceSupportLabel = <?php echo app_license_edition() === 'client' ? 'true' : 'false'; ?>;
    const selectedRequesterNode = document.getElementById('lcSelectedRequester');
    const selectedSourceNode = document.getElementById('lcSelectedSource');
    const fallbackClient = (selectedRequesterNode && selectedRequesterNode.textContent.trim()) || <?php echo json_encode(app_tr('العميل', 'Client'), JSON_UNESCAPED_UNICODE); ?>;
    const fallbackSupport = <?php echo json_encode(lc_support_sender_name($conn), JSON_UNESCAPED_UNICODE); ?>;
    const fallbackImageAlt = <?php echo json_encode(app_tr('مرفق صورة', 'Image attachment'), JSON_UNESCAPED_UNICODE); ?>;

    let lastMessageId = Number(messagesBox.dataset.lastId || 0);
    let inFlight = false;

    const selectedStatusBadge = document.getElementById('lcSelectedStatusBadge');
    const selectedLastUpdate = document.getElementById('lcSelectedLastUpdate');
    const activeTicketItem = document.querySelector('.lc-ticket-item.active');
    const activeRequesterNode = activeTicketItem ? activeTicketItem.querySelector('[data-role="ticket-requester"]') : null;
    const activeSourceNode = activeTicketItem ? activeTicketItem.querySelector('[data-role="ticket-source"]') : null;

    function escapeHtml(value) {
        return String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function textToHtml(value) {
        return escapeHtml(value).replace(/\n/g, '<br>');
    }

    function updateStatus(status, label, lastUpdateText) {
        if (selectedStatusBadge) {
            selectedStatusBadge.classList.remove('open', 'pending', 'answered', 'closed');
            if (status) {
                selectedStatusBadge.classList.add(status);
                selectedStatusBadge.dataset.status = status;
            }
            if (label) {
                selectedStatusBadge.textContent = label;
            }
        }
        if (selectedLastUpdate && lastUpdateText) {
            selectedLastUpdate.textContent = lastUpdateText;
        }
        if (activeTicketItem) {
            const badge = activeTicketItem.querySelector('[data-role=\"ticket-status\"]');
            const time = activeTicketItem.querySelector('[data-role=\"ticket-time\"]');
            if (badge) {
                badge.classList.remove('open', 'pending', 'answered', 'closed');
                if (status) badge.classList.add(status);
                if (label) badge.textContent = label;
            }
            if (time && lastUpdateText) {
                time.textContent = lastUpdateText;
            }
        }
    }

    function buildMessageRow(msg) {
        const row = document.createElement('div');
        const role = (msg.sender_role || 'client').toLowerCase();
        row.className = 'lc-msg-row ' + (role === 'support' ? 'support' : 'client');
        row.dataset.msgId = String(msg.id || '');

        const card = document.createElement('div');
        card.className = 'lc-msg';

        let senderName = String(msg.sender_name || '').trim();
        if (role === 'support' && forceSupportLabel) {
            senderName = fallbackSupport;
        } else if (!senderName) {
            senderName = role === 'support' ? fallbackSupport : fallbackClient;
        }
        const createdAt = msg.created_at_text || '';
        const head = document.createElement('div');
        head.className = 'lc-msg-head';
        head.innerHTML = '<strong>' + escapeHtml(senderName) + '</strong><span>•</span><span>' + escapeHtml(createdAt) + '</span>';
        card.appendChild(head);

        const messageText = (msg.message || '').trim();
        if (messageText !== '') {
            const text = document.createElement('div');
            text.className = 'lc-msg-text';
            text.innerHTML = textToHtml(messageText);
            card.appendChild(text);
        }

        const imageUrl = (msg.image_url || '').trim();
        if (imageUrl !== '') {
            const imageWrap = document.createElement('div');
            imageWrap.className = 'lc-msg-image';
            const imageName = (msg.image_name || '').trim();
            const imageAlt = imageName !== '' ? imageName : fallbackImageAlt;
            imageWrap.innerHTML = '' +
                '<a href=\"' + escapeHtml(imageUrl) + '\" target=\"_blank\" rel=\"noopener\">' +
                '  <img src=\"' + escapeHtml(imageUrl) + '\" alt=\"' + escapeHtml(imageAlt) + '\" loading=\"lazy\">' +
                '</a>' +
                (imageName !== '' ? '<div class=\"lc-msg-image-name\">' + escapeHtml(imageName) + '</div>' : '');
            card.appendChild(imageWrap);
        }

        row.appendChild(card);
        return row;
    }

    function appendMessages(newMessages) {
        if (!Array.isArray(newMessages) || newMessages.length === 0) return;

        const wasNearBottom = (messagesBox.scrollHeight - messagesBox.scrollTop - messagesBox.clientHeight) < 140;
        const emptyPlaceholder = messagesBox.querySelector('.lc-empty');
        if (emptyPlaceholder) {
            emptyPlaceholder.remove();
        }

        for (const msg of newMessages) {
            const mid = Number(msg.id || 0);
            if (mid > 0 && messagesBox.querySelector('[data-msg-id=\"' + mid + '\"]')) {
                continue;
            }
            messagesBox.appendChild(buildMessageRow(msg));
            if (mid > lastMessageId) {
                lastMessageId = mid;
            }
        }

        messagesBox.dataset.lastId = String(lastMessageId);
        if (wasNearBottom) {
            messagesBox.scrollTop = messagesBox.scrollHeight;
        }
    }

    function pollLiveUpdates() {
        if (inFlight || document.visibilityState === 'hidden') return;
        inFlight = true;
        const url = 'license_center.php?live_updates=1&ticket=' + encodeURIComponent(selectedTicketId) + '&since=' + encodeURIComponent(lastMessageId) + '&_=' + Date.now();
        fetch(url, { credentials: 'same-origin' })
            .then((r) => r.json())
            .then((data) => {
                if (!data || data.ok !== true) {
                    if (data && data.access_denied) {
                        window.location.href = 'license_center.php';
                    }
                    return;
                }
                if (data.deleted) {
                    window.location.href = 'license_center.php?msg=ticket_deleted';
                    return;
                }
                appendMessages(data.new_messages || []);
                updateStatus(data.ticket_status || '', data.ticket_status_label || '', data.last_message_at_text || '');
                if (data.ticket_requester_name && selectedRequesterNode) {
                    selectedRequesterNode.textContent = data.ticket_requester_name;
                }
                if (data.ticket_requester_name && activeRequesterNode) {
                    activeRequesterNode.textContent = data.ticket_requester_name;
                }
                if (data.ticket_source && selectedSourceNode) {
                    selectedSourceNode.textContent = data.ticket_source;
                }
                if (data.ticket_source && activeSourceNode) {
                    activeSourceNode.textContent = data.ticket_source;
                }
                if (typeof data.last_message_id !== 'undefined') {
                    const responseLastId = Number(data.last_message_id || 0);
                    if (responseLastId > lastMessageId) {
                        lastMessageId = responseLastId;
                        messagesBox.dataset.lastId = String(lastMessageId);
                    }
                }
            })
            .catch(() => {})
            .finally(() => { inFlight = false; });
    }

    pollLiveUpdates();
    window.setInterval(pollLiveUpdates, pollMs);
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') {
            pollLiveUpdates();
        }
    });
})();
</script>

<?php require 'footer.php'; ?>
