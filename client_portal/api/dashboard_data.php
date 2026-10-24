<?php
// client_portal/api/dashboard_data.php
ob_start();
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/db_connect.php';

$clientId = api_require_login();
api_rate_limit_or_fail('client_portal_dashboard_data', 120, 300, api_rate_limit_scope('dashboard_data'));

try {
    try {
        $clientStmt = $pdo->prepare('SELECT name, phone, avatar_url FROM clients WHERE id = ? LIMIT 1');
        $clientStmt->execute([$clientId]);
        $client = $clientStmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        // توافق مع قواعد بيانات لا تحتوي على عمود avatar_url
        $clientStmt = $pdo->prepare('SELECT name, phone FROM clients WHERE id = ? LIMIT 1');
        $clientStmt->execute([$clientId]);
        $client = $clientStmt->fetch(PDO::FETCH_ASSOC);
        if (is_array($client) && !isset($client['avatar_url'])) {
            $client['avatar_url'] = '';
        }
    }

    if (!$client) {
        api_json(['status' => 'error', 'message' => 'Client not found'], 404);
    }

    $snapshot = api_client_financial_snapshot($conn, $clientId);
    $balance = (float)($snapshot['net_balance'] ?? 0);

    $activeStmt = $pdo->prepare(
        'SELECT COUNT(*) FROM job_orders
         WHERE client_id = ?
         AND status NOT IN ("completed", "cancelled", "closed", "delivered")'
    );
    $activeStmt->execute([$clientId]);
    $activeOrders = (int) $activeStmt->fetchColumn();

    $quotesStmt = $pdo->prepare('SELECT COUNT(*) FROM quotes WHERE client_id = ? AND status = "pending"');
    $quotesStmt->execute([$clientId]);
    $pendingQuotes = (int) $quotesStmt->fetchColumn();

    $invStmt = $pdo->prepare('SELECT COUNT(*) FROM invoices WHERE client_id = ? AND (total_amount - paid_amount) > 1');
    $invStmt->execute([$clientId]);
    $dueInvoices = (int) $invStmt->fetchColumn();

    $reviewStmt = $pdo->prepare(
        'SELECT job_name, access_token
         FROM job_orders
         WHERE client_id = ?
         AND current_stage IN ("idea_review", "content_review", "design_review", "client_rev")
         ORDER BY id DESC
         LIMIT 1'
    );
    $reviewStmt->execute([$clientId]);
    $reviewRow = $reviewStmt->fetch(PDO::FETCH_ASSOC);

    $pendingReview = null;
    if ($reviewRow) {
        $baseUrl = rtrim((string)(defined('SYSTEM_URL') ? SYSTEM_URL : app_base_url()), '/');
        $pendingReview = [
            'job_name' => api_clean_text($reviewRow['job_name'] ?? '', 120),
            'url' => $baseUrl . '/client_review.php?token=' . rawurlencode((string)($reviewRow['access_token'] ?? ''))
        ];
    }

    $recentStmt = $pdo->prepare(
        'SELECT id, job_name, current_stage AS status, notes, created_at
         FROM job_orders
         WHERE client_id = ?
         ORDER BY id DESC
         LIMIT 5'
    );
    $recentStmt->execute([$clientId]);
    $recentOrders = $recentStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($recentOrders as &$order) {
        $order['id'] = (int) ($order['id'] ?? 0);
        $order['job_name'] = api_clean_text($order['job_name'] ?? '', 160);
        $order['status'] = api_clean_text($order['status'] ?? '', 60);
        $order['created_at'] = api_clean_text($order['created_at'] ?? '', 40);

        $order['rejection_reason'] = '';
        if (($order['status'] ?? '') === 'cancelled' && !empty($order['notes'])) {
            if (preg_match('/\[سبب الرفض: (.*?)\]/u', (string) $order['notes'], $matches)) {
                $order['rejection_reason'] = api_clean_text($matches[1] ?? '', 180);
            }
        }
        unset($order['notes']);
    }

    $quotesTxt = [
        'شراكتكم معنا وسام نعتز به.. دمت شريكاً للنجاح ✨',
        'خطوة بخطوة نصنع التميز معاً.. 🦅',
        'كل تفصيلة في طلبكم تنفذ بشغف..',
    ];

    $insights = [];
    if ($dueInvoices > 0) {
        $insights[] = 'هناك فواتير غير مسددة، يفضّل مراجعة صفحة المالية.';
    }
    if ($pendingQuotes > 0) {
        $insights[] = 'توجد عروض أسعار بانتظار قرارك.';
    }
    if ($activeOrders === 0) {
        $insights[] = 'لا توجد مشاريع جارية حالياً، ابدأ طلباً جديداً عندما تكون جاهزاً.';
    }

    api_json([
        'status' => 'success',
        'data' => [
            'client_id' => $clientId,
            'name' => api_clean_text($client['name'] ?? '', 120),
            'phone' => api_clean_text($client['phone'] ?? '', 40),
            'avatar_url' => api_clean_text($client['avatar_url'] ?? '', 255),
            'balance' => $balance,
            'opening_outstanding' => (float)($snapshot['opening_outstanding'] ?? 0),
            'opening_credit' => (float)($snapshot['opening_credit'] ?? 0),
            'invoice_due' => (float)($snapshot['invoice_due'] ?? 0),
            'payment_credit' => (float)($snapshot['payment_credit'] ?? 0),
            'quote' => $quotesTxt[array_rand($quotesTxt)],
            'active_orders' => $activeOrders,
            'pending_quotes' => $pendingQuotes,
            'invoices_count' => $dueInvoices,
            'pending_review' => $pendingReview,
            'recent_orders' => $recentOrders,
            'insights' => $insights,
        ]
    ]);
} catch (PDOException $e) {
    error_log('Dashboard Data Error: ' . $e->getMessage());
    api_json(['status' => 'error', 'message' => 'تعذر تحميل بيانات لوحة التحكم'], 500);
}
