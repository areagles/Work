<?php
require_once __DIR__ . '/auth.php';

$query = $_GET;
$query['tab'] = 'purchases';
app_safe_redirect('invoices.php' . (!empty($query) ? ('?' . http_build_query($query)) : ''));
