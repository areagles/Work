<?php
require 'auth.php';
require 'config.php';

$userId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;
$params = [];
if ($userId > 0) {
    $params['employee_id'] = $userId;
}
if (!empty($_GET['from'])) {
    $params['from'] = (string)$_GET['from'];
}
if (!empty($_GET['to'])) {
    $params['to'] = (string)$_GET['to'];
}

$target = 'statement_employee.php';
if (!empty($params)) {
    $target .= '?' . http_build_query($params);
}

app_safe_redirect($target, 'statement_employee.php');
