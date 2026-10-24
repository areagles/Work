<?php
// portal/api/get_orders.php
ob_start();
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/db_connect.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$clientId = api_require_login();
api_rate_limit_or_fail('client_portal_get_orders', 120, 300, api_rate_limit_scope('get_orders'));

function map_order_status(string $status, string $stage, string $type): array
{
    $status = strtolower(trim($status));
    $stage = strtolower(trim($stage));
    $isClosed = false;
    $label = 'قيد المراجعة';

    $closedStatuses = ['completed', 'delivered', 'done', 'closed', 'shipped', 'archived'];
    $cancelledStatuses = ['canceled', 'cancelled', 'rejected'];
    if (in_array($status, $closedStatuses, true) || in_array($stage, $closedStatuses, true)) {
        return ['✅ تم التسليم', true];
    }

    if (in_array($status, $cancelledStatuses, true) || in_array($stage, $cancelledStatuses, true)) {
        return ['❌ ملغاة', true];
    }

    if (in_array($status, ['pending', 'new'], true)) {
        $label = '⏳ قيد المراجعة';
    } elseif ($status === 'design' || strpos($stage, 'design') !== false) {
        $label = '🎨 مرحلة التصميم';
    } elseif (in_array($status, ['proof_sent', 'waiting_approval'], true) || strpos($stage, 'review') !== false) {
        $label = '✋ بانتظار موافقتك';
    } elseif ($status === 'approved') {
        $label = '✅ تمت الموافقة';
    } elseif (in_array($status, ['processing', 'in_progress', 'production'], true)) {
        if ($type === 'print') {
            $label = '🖨️ جاري الطباعة';
        } elseif ($type === 'web') {
            $label = '💻 جاري البرمجة';
        } else {
            $label = '⚙️ جاري التنفيذ';
        }
    }

    return [$label, $isClosed];
}

try {
    $stmt = $pdo->prepare(
        'SELECT id, job_name, quantity, price, status, current_stage, job_type, created_at
         FROM job_orders
         WHERE client_id = ?
         ORDER BY id DESC'
    );
    $stmt->execute([$clientId]);
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($orders as &$order) {
        [$statusText, $isClosed] = map_order_status(
            (string) ($order['status'] ?? ''),
            (string) ($order['current_stage'] ?? ''),
            (string) ($order['job_type'] ?? '')
        );

        $order['id'] = (int) $order['id'];
        $order['job_name'] = api_clean_text($order['job_name'] ?? '', 180);
        $order['quantity'] = (float) ($order['quantity'] ?? 0);
        $order['price'] = (float) ($order['price'] ?? 0);
        $order['status'] = (string)($order['status'] ?? '');
        $order['current_stage'] = (string)($order['current_stage'] ?? '');
        $order['status_text'] = $statusText;
        $order['is_closed'] = $isClosed;
        $order['price_formatted'] = $order['price'] > 0 ? number_format($order['price'], 2) . ' ج.م' : '---';
        $order['date_formatted'] = date('Y/m/d', strtotime((string) ($order['created_at'] ?? 'now')));
    }

    api_json(['status' => 'success', 'data' => $orders]);
} catch (PDOException $e) {
    error_log('Get Orders Error: ' . $e->getMessage());
    api_json(['status' => 'error', 'message' => 'تعذر تحميل الطلبات'], 500);
}
