<?php
// portal/api/get_invoices.php
ob_start();
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/db_connect.php';

$clientId = api_require_login();
api_rate_limit_or_fail('client_portal_get_invoices', 120, 300, api_rate_limit_scope('get_invoices'));

try {
    $snapshot = api_client_financial_snapshot($conn, $clientId);
    $totalBilled = (float) ($snapshot['total_sales'] ?? 0);
    $totalPaid = (float) ($snapshot['total_paid'] ?? 0);

    $summary = [
        'total_billed' => $totalBilled,
        'total_paid' => $totalPaid,
        'total_due' => (float)($snapshot['net_balance'] ?? 0),
        'opening_outstanding' => (float)($snapshot['opening_outstanding'] ?? 0),
        'opening_credit' => (float)($snapshot['opening_credit'] ?? 0),
        'invoice_due' => (float)($snapshot['invoice_due'] ?? 0),
        'payment_credit' => (float)($snapshot['payment_credit'] ?? 0),
    ];

    $stmt = $pdo->prepare(
        'SELECT
            i.id,
            COALESCE(i.total_amount, 0) AS total_amount,
            COALESCE(i.paid_amount, 0) AS paid_amount,
            COALESCE(i.remaining_amount, 0) AS remaining_amount,
            COALESCE(i.status, "") AS status,
            COALESCE(DATE_FORMAT(i.due_date, "%Y-%m-%d"), "") AS due_date,
            DATE_FORMAT(i.created_at, "%Y-%m-%d") AS date,
            COALESCE(j.job_name, "فاتورة عامة") AS job_name,
            COALESCE(i.items_json, "[]") AS items_json
         FROM invoices i
         LEFT JOIN job_orders j ON i.job_id = j.id
         WHERE i.client_id = ?
         ORDER BY i.id DESC'
    );
    $stmt->execute([$clientId]);
    $invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($invoices as &$invoice) {
        $invoice['id'] = (int) $invoice['id'];
        $invoice['total_amount'] = (float) ($invoice['total_amount'] ?? 0);
        $invoice['paid_amount'] = (float) ($invoice['paid_amount'] ?? 0);
        $invoice['remaining_amount'] = round((float)($invoice['remaining_amount'] ?? 0), 2);
        $invoice['date'] = api_clean_text($invoice['date'] ?? '', 20);
        $invoice['due_date'] = api_clean_text($invoice['due_date'] ?? '', 20);
        $invoice['job_name'] = api_clean_text($invoice['job_name'] ?? '', 180);
        $invoice['status'] = api_clean_text($invoice['status'] ?? '', 40);
        $invoice['items_json'] = $invoice['items_json'] ?? '[]';
    }

    api_json(['status' => 'success', 'summary' => $summary, 'data' => $invoices]);
} catch (PDOException $e) {
    error_log('Get Invoices Error: ' . $e->getMessage());
    api_json(['status' => 'error', 'message' => 'تعذر تحميل البيانات المالية'], 500);
}
