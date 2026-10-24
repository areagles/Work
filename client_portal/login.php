<?php
require_once __DIR__ . '/../config.php';

$target = __DIR__ . '/login.html';
if (is_file($target)) {
    header('Content-Type: text/html; charset=UTF-8');
    readfile($target);
    exit;
}

app_safe_redirect('login.html');
