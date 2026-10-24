<?php
// portal/api/get_quotes.php
ob_start();
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/db_connect.php';

$clientId = api_require_login();
api_rate_limit_or_fail('client_portal_get_quotes', 120, 300, api_rate_limit_scope('get_quotes'));

try {
    $stmt = $pdo->prepare(
        'SELECT id, created_at, valid_until, total_amount, status, access_token
         FROM quotes
         WHERE client_id = ?
         ORDER BY id DESC'
    );
    $stmt->execute([$clientId]);
    $quotes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($quotes as &$quote) {
        $quote['id'] = (int) $quote['id'];
        $quote['total_amount'] = (float) ($quote['total_amount'] ?? 0);
        $quote['status'] = api_clean_text($quote['status'] ?? '', 40);
        $quote['valid_until'] = api_clean_text($quote['valid_until'] ?? '', 40);
        $quote['created_at'] = api_clean_text($quote['created_at'] ?? '', 40);
        $quote['access_token'] = api_clean_text($quote['access_token'] ?? '', 120);
    }

    api_json(['status' => 'success', 'data' => $quotes]);
} catch (PDOException $e) {
    error_log('Get Quotes Error: ' . $e->getMessage());
    api_json(['status' => 'error', 'message' => 'تعذر تحميل عروض الأسعار'], 500);
}
