<?php
require_once __DIR__ . '/../config.php';
app_start_session();

$clientId = (int)($_SESSION['portal_client_id'] ?? $_SESSION['client_id'] ?? 0);
if ($clientId > 0) {
    app_safe_redirect('dashboard.html');
}
app_safe_redirect('login.html');

