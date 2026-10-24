<?php
require 'auth.php';
require 'config.php';
app_start_session();

$userId = (int)($_SESSION['user_id'] ?? 0);
if ($userId <= 0) {
    app_safe_redirect('login.php');
}

$back = trim((string)($_POST['back'] ?? 'dashboard.php'));
if ($back === '' || strpos($back, '://') !== false || strpos($back, "\n") !== false || strpos($back, "\r") !== false) {
    $back = 'dashboard.php';
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = strtolower(trim((string)($_POST['action'] ?? '')));
    if ($action === 'mark_all') {
        app_support_notifications_mark_all_read($conn, $userId);
    } elseif ($action === 'mark_one') {
        $notificationId = (int)($_POST['notification_id'] ?? 0);
        if ($notificationId > 0) {
            app_support_notification_mark_read($conn, $userId, $notificationId);
        }
    }
}

app_safe_redirect($back);
