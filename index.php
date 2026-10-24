<?php
// index.php
require_once __DIR__ . '/config.php';
app_start_session();
if (isset($_SESSION['user_id'])) {
    app_safe_redirect('dashboard.php');
} elseif ((int)($_SESSION['portal_client_id'] ?? $_SESSION['client_id'] ?? 0) > 0) {
    app_safe_redirect('client_portal/dashboard.html');
} else {
    app_safe_redirect('login.php');
}
exit();
?>
