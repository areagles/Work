<?php
// client_portal/api/get_order_details.php
ob_start();
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/db_connect.php';

function client_portal_order_status_payload(string $status, string $stage, string $type): array
{
    $status = strtolower(trim($status));
    $stage = strtolower(trim($stage));
    $type = strtolower(trim($type));

    $closedStatuses = ['completed', 'delivered', 'done', 'closed', 'shipped', 'archived'];
    $cancelledStatuses = ['canceled', 'cancelled', 'rejected'];

    if (in_array($status, $closedStatuses, true) || in_array($stage, $closedStatuses, true)) {
        return ['✅ تم التسليم', true];
    }

    if (in_array($status, $cancelledStatuses, true) || in_array($stage, $cancelledStatuses, true)) {
        return ['❌ ملغاة', true];
    }

    if (in_array($status, ['pending', 'new'], true)) {
        return ['⏳ قيد المراجعة', false];
    }
    if ($status === 'design' || strpos($stage, 'design') !== false) {
        return ['🎨 مرحلة التصميم', false];
    }
    if (in_array($status, ['proof_sent', 'waiting_approval'], true) || strpos($stage, 'review') !== false) {
        return ['✋ بانتظار موافقتك', false];
    }
    if ($status === 'approved') {
        return ['✅ تمت الموافقة', false];
    }
    if (in_array($status, ['processing', 'in_progress', 'production'], true)) {
        if ($type === 'print') {
            return ['🖨️ جاري الطباعة', false];
        }
        if ($type === 'web') {
            return ['💻 جاري البرمجة', false];
        }
        return ['⚙️ جاري التنفيذ', false];
    }

    return ['⏳ قيد المتابعة', false];
}

$clientId = api_require_login();
api_rate_limit_or_fail('client_portal_get_order_details', 120, 300, api_rate_limit_scope('get_order_details'));
$orderId = (int)($_GET['id'] ?? 0);
if ($orderId <= 0) {
    api_json(['status' => 'error', 'message' => 'لم يتم تحديد رقم الطلب'], 400);
}

try {
    $stmt = $pdo->prepare(
        'SELECT id, job_name, job_type, status, current_stage, quantity, price, notes, job_details, created_at, delivery_date
         FROM job_orders
         WHERE id = ? AND client_id = ?
         LIMIT 1'
    );
    $stmt->execute([$orderId, $clientId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$order) {
        api_json(['status' => 'error', 'message' => 'الطلب غير موجود أو لا تملكه'], 404);
    }

    $filesStmt = $pdo->prepare(
        'SELECT id, file_path, file_type, stage, description, uploaded_by, uploaded_at
         FROM job_files
         WHERE job_id = ?
         ORDER BY id ASC'
    );
    $filesStmt->execute([$orderId]);
    $files = $filesStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $proofsStmt = $pdo->prepare(
        'SELECT id, file_path, description, status, item_index, client_comment, created_at
         FROM job_proofs
         WHERE job_id = ?
         ORDER BY id ASC'
    );
    $proofsStmt->execute([$orderId]);
    $proofs = $proofsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $jobType = trim((string)($order['job_type'] ?? ''));
    $workflow = [];
    if ($jobType !== '' && function_exists('app_operation_workflow')) {
        $fallback = [
            'briefing' => 'التجهيز',
            'design' => 'التصميم',
            'client_rev' => 'مراجعة العميل',
            'delivery' => 'التسليم',
            'accounting' => 'الحسابات',
            'completed' => 'الأرشيف',
        ];
        $flow = app_operation_workflow($conn, $jobType, $fallback);
        foreach ($flow as $stageKey => $stageData) {
            $workflow[] = [
                'stage_key' => (string)$stageKey,
                'label' => (string)($stageData['label'] ?? $stageKey),
                'is_current' => ((string)$stageKey === (string)($order['current_stage'] ?? '')),
            ];
        }
    }

    foreach ($files as &$file) {
        $path = ltrim((string)($file['file_path'] ?? ''), '/');
        $file['id'] = (int)($file['id'] ?? 0);
        $file['url'] = $path !== '' ? rtrim((string)(defined('SYSTEM_URL') ? SYSTEM_URL : app_base_url()), '/') . '/' . $path : '';
    }
    unset($file);

    foreach ($proofs as &$proof) {
        $path = ltrim((string)($proof['file_path'] ?? ''), '/');
        $proof['id'] = (int)($proof['id'] ?? 0);
        $proof['item_index'] = (int)($proof['item_index'] ?? 0);
        $proof['url'] = $path !== '' ? rtrim((string)(defined('SYSTEM_URL') ? SYSTEM_URL : app_base_url()), '/') . '/' . $path : '';
    }
    unset($proof);

    [$statusText, $isClosed] = client_portal_order_status_payload(
        (string)($order['status'] ?? ''),
        (string)($order['current_stage'] ?? ''),
        (string)($order['job_type'] ?? '')
    );
    $order['status_text'] = $statusText;
    $order['is_closed'] = $isClosed;

    api_json([
        'status' => 'success',
        'data' => [
            'order' => $order,
            'workflow' => $workflow,
            'files' => $files,
            'proofs' => $proofs,
        ],
    ]);
} catch (Throwable $e) {
    error_log('client_portal get_order_details error: ' . $e->getMessage());
    api_json(['status' => 'error', 'message' => 'حدث خطأ أثناء تحميل تفاصيل الطلب'], 500);
}
