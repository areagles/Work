<?php
// modules/plastic.php - (Royal Plastic Master V35.0 - Fix Cylinders & Feedback)

// 0. تفعيل الأخطاء
error_reporting(E_ALL);
@ini_set('upload_max_filesize', '2048M');
@ini_set('post_max_size', '2048M');
@ini_set('max_execution_time', '3600');
@ini_set('max_input_time', '3600');
@ini_set('memory_limit', '2048M');

function plastic_is_ajax_request(): bool {
    $requestedWith = strtolower(trim((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')));
    if ($requestedWith === 'xmlhttprequest') {
        return true;
    }
    if (trim((string)($_POST['__async_form'] ?? '')) === '1') {
        return true;
    }
    $accept = strtolower(trim((string)($_SERVER['HTTP_ACCEPT'] ?? '')));
    return strpos($accept, 'application/json') !== false;
}

function plastic_finish_request(int $jobId, array $payload = []): void {
    if (plastic_is_ajax_request()) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
    safe_redirect($jobId);
}

if (!isset($conn, $job) || !is_array($job) || !isset($job['id'])) {
    http_response_code(403);
    exit('Forbidden');
}

// 1. إصلاح الجداول تلقائياً
app_ensure_job_assets_schema($conn);

// 2. دالة التوجيه
function safe_redirect($id) {
    if (!headers_sent()) {
        header('Location: job_details.php?id=' . (int)$id);
        exit;
    }
    echo "<script>window.location.href = 'job_details.php?id=$id';</script>";
    exit;
}

// دالة الواتساب
function get_wa_link($phone, $text) {
    $phone = preg_replace('/[^0-9]/', '', $phone);
    if (strlen($phone) == 11 && substr($phone, 0, 2) == '01') { $phone = '2' . $phone; }
    elseif (strlen($phone) == 10 && substr($phone, 0, 2) == '05') { $phone = '966' . substr($phone, 1); }
    if (strlen($phone) < 10) return false;
    return "https://wa.me/$phone?text=" . urlencode($text);
}

function plastic_count_job_files_by_stage(mysqli $conn, int $jobId, string $stage): int {
    $safeStage = $conn->real_escape_string($stage);
    $row = $conn->query("SELECT COUNT(*) AS cnt FROM job_files WHERE job_id={$jobId} AND stage='{$safeStage}'")->fetch_assoc();
    return (int)($row['cnt'] ?? 0);
}

function plastic_proofs_count(mysqli $conn, int $jobId): int {
    $row = $conn->query("SELECT COUNT(*) AS cnt FROM job_proofs WHERE job_id={$jobId}")->fetch_assoc();
    return (int)($row['cnt'] ?? 0);
}

function plastic_stage_descriptions(mysqli $conn, int $jobId, string $stage): string {
    $safeStage = $conn->real_escape_string($stage);
    $parts = [];
    $res = $conn->query("SELECT description FROM job_files WHERE job_id={$jobId} AND stage='{$safeStage}' ORDER BY id ASC");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $desc = trim((string)($row['description'] ?? ''));
            if ($desc !== '') {
                $parts[] = $desc;
            }
        }
    }
    return implode(' | ', $parts);
}

function plastic_sync_rollup(mysqli $conn, int $jobId, int $userId, string $userName): void {
    $briefingSummary = app_stage_data_get($conn, $jobId, 'briefing', 'briefing_summary', '');
    $proofsCount = (string)plastic_proofs_count($conn, $jobId);
    $cylindersSummary = plastic_stage_descriptions($conn, $jobId, 'cylinders');
    $cylindersCount = (string)plastic_count_job_files_by_stage($conn, $jobId, 'cylinders');
    $productionSummary = app_stage_data_get($conn, $jobId, 'printing', 'production_summary', '');

    foreach (['briefing', 'design', 'client_rev', 'cylinders', 'extrusion', 'printing', 'cutting', 'delivery', 'accounting', 'completed'] as $stageKey) {
        app_stage_data_set($conn, $jobId, $stageKey, 'briefing_summary', $briefingSummary, $userId, $userName);
    }
    foreach (['design', 'client_rev', 'cylinders', 'extrusion', 'printing', 'cutting', 'delivery', 'accounting', 'completed'] as $stageKey) {
        app_stage_data_set($conn, $jobId, $stageKey, 'proofs_count', $proofsCount, $userId, $userName);
    }
    foreach (['cylinders', 'extrusion', 'printing', 'cutting', 'delivery', 'accounting', 'completed'] as $stageKey) {
        app_stage_data_set($conn, $jobId, $stageKey, 'cylinders_summary', $cylindersSummary, $userId, $userName);
        app_stage_data_set($conn, $jobId, $stageKey, 'cylinders_count', $cylindersCount, $userId, $userName);
    }
    foreach (['printing', 'cutting', 'delivery', 'accounting', 'completed'] as $stageKey) {
        app_stage_data_set($conn, $jobId, $stageKey, 'production_summary', $productionSummary, $userId, $userName);
    }
}

// رابط الموقع الأساسي
$protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http");
$base_url = "$protocol://" . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']);
$base_url = str_replace('/modules', '', $base_url); 

// التأكد من وجود Access Token
app_job_access_token($conn, $job);

// 3. استخراج البيانات الفنية
$raw_text = $job['job_details'] ?? '';
function get_spec($pattern, $text, $default = '-') {
    if(empty($text)) return $default;
    preg_match($pattern, $text, $matches);
    return isset($matches[1]) ? trim($matches[1]) : $default;
}

$specs = [
    'material'  => get_spec('/(?:نوع الخامة|الخامة):\s*(.*)/u', $raw_text),
    'micron'    => get_spec('/(?:السمك|المايكرون):\s*(\d+)/u', $raw_text),
    'width'     => get_spec('/(?:عرض الفيلم|العرض):\s*(\d+)/u', $raw_text),
    'treat'     => get_spec('/المعالجة:\s*(.*)/u', $raw_text),
    'cyl_count' => get_spec('/السلندرات:\s*(\d+)/u', $raw_text),
    'cyl_stat'  => get_spec('/السلندرات:.*?\((.*?)\)/u', $raw_text),
    'cut_len'   => get_spec('/(?:طول القص|القص):\s*(\d+)/u', $raw_text),
    'colors'    => get_spec('/الألوان:\s*(.*)/u', $raw_text, 'غير محدد'),
];

$is_financial = app_user_can_any(['finance.view', 'invoices.view']);
$can_force_stage = app_user_can('jobs.manage_all');

$fallbackWorkflowLabels = [
    'briefing'    => '1. التجهيز',
    'design'      => '2. التصميم',
    'client_rev'  => '3. المراجعة',
    'cylinders'   => '4. السلندرات',
    'extrusion'   => '5. السحب',
    'printing'    => '6. الطباعة',
    'cutting'     => '7. القص',
    'delivery'    => '8. التسليم',
    'accounting'  => '9. الحسابات',
    'completed'   => '10. الأرشيف',
];
$workflow = app_operation_workflow($conn, 'plastic', $fallbackWorkflowLabels);
$allowed_stage_keys = array_keys($workflow);
$first_stage = (string)array_key_first($workflow);
if ($first_stage === '') {
    $first_stage = 'briefing';
}
$curr = app_workflow_current_stage($workflow, (string)($job['current_stage'] ?? ''), $first_stage);

// 4. معالجة الطلبات (POST)
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (!app_verify_csrf($_POST['_csrf_token'] ?? '')) {
        http_response_code(403);
        exit('Invalid CSRF token');
    }

    $user_name = $_SESSION['name'] ?? 'Officer';

    // === أدوات التحكم ===
    if (isset($_POST['add_internal_comment'])) {
        if(!empty($_POST['comment_text'])) {
            $c_text = $conn->real_escape_string($_POST['comment_text']);
            $timestamp = date('Y-m-d H:i');
            $new_note = "\n[💬 $user_name ($timestamp)]: $c_text";
            $conn->query("UPDATE job_orders SET notes = CONCAT(IFNULL(notes, ''), '$new_note') WHERE id={$job['id']}");
        }
        plastic_finish_request((int)$job['id'], [
            'ok' => true,
            'message' => 'تم حذف العنصر بنجاح.',
            'redirect' => 'job_details.php?id=' . (int)$job['id'],
        ]);
    }

    if (isset($_POST['force_stage_change']) && $can_force_stage) {
        $target_stage = trim((string)($_POST['target_stage'] ?? ''));
        if (in_array($target_stage, $allowed_stage_keys, true)) {
            app_update_job_stage($conn, (int)$job['id'], $target_stage);
        }
        safe_redirect($job['id']);
    }

    if (isset($_POST['delete_item'])) {
        $itemType = trim((string)($_POST['type'] ?? ''));
        $tbl = ($itemType === 'proof') ? 'job_proofs' : 'job_files';
        $id = intval($_POST['item_id']);
        $q = $conn->query("SELECT file_path FROM $tbl WHERE id=$id AND job_id={$job['id']} LIMIT 1");
        if ($r = $q->fetch_assoc()) { 
            app_safe_unlink((string)($r['file_path'] ?? ''), __DIR__ . '/../uploads');
        }
        $conn->query("DELETE FROM $tbl WHERE id=$id AND job_id={$job['id']}");
        plastic_sync_rollup($conn, (int)$job['id'], (int)($_SESSION['user_id'] ?? 0), (string)$user_name);
        safe_redirect($job['id']);
    }

    if (isset($_POST['save_prod_note'])) {
        $note = $conn->real_escape_string($_POST['prod_note']);
        $conn->query("UPDATE job_orders SET notes = CONCAT(IFNULL(notes, ''), '\n[🏭 إنتاج]: $note') WHERE id={$job['id']}");
        app_stage_data_set($conn, (int)$job['id'], 'printing', 'production_summary', (string)($_POST['prod_note'] ?? ''), (int)($_SESSION['user_id'] ?? 0), (string)$user_name);
        plastic_sync_rollup($conn, (int)$job['id'], (int)($_SESSION['user_id'] ?? 0), (string)$user_name);
        safe_redirect($job['id']);
    }

    // مراحل العمل
    if (isset($_POST['save_brief'])) {
        app_stage_data_set($conn, (int)$job['id'], 'briefing', 'briefing_summary', (string)($_POST['notes'] ?? ''), (int)($_SESSION['user_id'] ?? 0), (string)$user_name);
        if (!empty($_POST['notes'])) {
            $note = $conn->real_escape_string($_POST['notes']);
            $conn->query("UPDATE job_orders SET notes = CONCAT(IFNULL(notes, ''), '\n[📝 تجهيز]: $note') WHERE id={$job['id']}");
        }
        plastic_sync_rollup($conn, (int)$job['id'], (int)($_SESSION['user_id'] ?? 0), (string)$user_name);
        app_update_job_stage($conn, (int)$job['id'], 'design');
        safe_redirect($job['id']);
    }

    if (isset($_POST['upload_proof'])) {
        if (!empty($_FILES['proof_file']['name'])) {
            $desc = $conn->real_escape_string($_POST['proof_desc'] ?? 'بروفة');
            $stored = app_store_uploaded_file($_FILES['proof_file'], [
                'dir' => 'uploads/proofs',
                'prefix' => 'proof_',
                'max_size' => 2048 * 1024 * 1024,
            ]);
            if (!empty($stored['ok'])) {
                $target = (string)$stored['path'];
                $conn->query("INSERT INTO job_proofs (job_id, file_path, description, status) VALUES ({$job['id']}, '$target', '$desc', 'pending')");
            }
        }
        plastic_sync_rollup($conn, (int)$job['id'], (int)($_SESSION['user_id'] ?? 0), (string)$user_name);
        safe_redirect($job['id']);
    }
    if (isset($_POST['send_to_review'])) {
        $proofsCountRes = $conn->query("SELECT COUNT(*) AS cnt FROM job_proofs WHERE job_id={$job['id']}");
        $proofsCount = (int)(($proofsCountRes ? ($proofsCountRes->fetch_assoc()['cnt'] ?? 0) : 0));
        if ($proofsCount <= 0) {
            safe_redirect($job['id']);
        }
        plastic_sync_rollup($conn, (int)$job['id'], (int)($_SESSION['user_id'] ?? 0), (string)$user_name);
        app_update_job_stage($conn, (int)$job['id'], 'client_rev');
        safe_redirect($job['id']);
    }

    if (isset($_POST['finalize_review'])) {
        $proofsCountRes = $conn->query("SELECT COUNT(*) AS cnt FROM job_proofs WHERE job_id={$job['id']}");
        $proofsCount = (int)(($proofsCountRes ? ($proofsCountRes->fetch_assoc()['cnt'] ?? 0) : 0));
        if ($proofsCount <= 0) {
            safe_redirect($job['id']);
        }
        plastic_sync_rollup($conn, (int)$job['id'], (int)($_SESSION['user_id'] ?? 0), (string)$user_name);
        app_update_job_stage($conn, (int)$job['id'], 'cylinders');
        safe_redirect($job['id']);
    }

    // --- إصلاح وتعديل منطق السلندرات ---
    if (isset($_POST['save_cylinders'])) {
        $cyl_descs = $_POST['cyl_desc'] ?? [];
        $cyl_supps = $_POST['cyl_supplier'] ?? [];
        
        if (isset($_FILES['cyl_file']) && !empty($_FILES['cyl_file']['name'][0])) {
            foreach ($_FILES['cyl_file']['name'] as $i => $name) {
                if ($_FILES['cyl_file']['error'][$i] == 0) {
                    $stored = app_store_uploaded_file([
                        'name' => $_FILES['cyl_file']['name'][$i] ?? '',
                        'type' => $_FILES['cyl_file']['type'][$i] ?? '',
                        'tmp_name' => $_FILES['cyl_file']['tmp_name'][$i] ?? '',
                        'error' => $_FILES['cyl_file']['error'][$i] ?? UPLOAD_ERR_NO_FILE,
                        'size' => $_FILES['cyl_file']['size'][$i] ?? 0,
                    ], [
                        'dir' => 'uploads/cylinders',
                        'prefix' => 'cyl_' . $i . '_',
                        'max_size' => 2048 * 1024 * 1024,
                    ]);
                    if (!empty($stored['ok'])) {
                        $target = (string)$stored['path'];
                        $desc = $conn->real_escape_string($cyl_descs[$i] ?? 'ملف سلندر');
                        $supp = $conn->real_escape_string($cyl_supps[$i] ?? '');
                        $conn->query("INSERT INTO job_files (job_id, file_path, stage, description, uploaded_by) VALUES ({$job['id']}, '$target', 'cylinders', '$desc', '$supp')");
                    }
                }
            }
        }
        plastic_sync_rollup($conn, (int)$job['id'], (int)($_SESSION['user_id'] ?? 0), (string)$user_name);
        safe_redirect($job['id']); // حفظ وبقاء في الصفحة
    }

    if (isset($_POST['finish_cylinders'])) {
        $cylindersCountRes = $conn->query("SELECT COUNT(*) AS cnt FROM job_files WHERE job_id={$job['id']} AND stage='cylinders'");
        $cylindersCount = (int)(($cylindersCountRes ? ($cylindersCountRes->fetch_assoc()['cnt'] ?? 0) : 0));
        if ($cylindersCount <= 0) {
            safe_redirect($job['id']);
        }
        plastic_sync_rollup($conn, (int)$job['id'], (int)($_SESSION['user_id'] ?? 0), (string)$user_name);
        app_update_job_stage($conn, (int)$job['id'], 'extrusion');
        safe_redirect($job['id']);
    }
    // ------------------------------------

    if (isset($_POST['finish_extrusion'])) {
        app_update_job_stage($conn, (int)$job['id'], 'printing');
        safe_redirect($job['id']);
    }

    if (isset($_POST['finish_printing'])) {
        if(!empty($_POST['colors_update'])) {
            $colors = $conn->real_escape_string($_POST['colors_update']);
            $newDetails = $conn->real_escape_string($raw_text . "\nالألوان: $colors");
            $conn->query("UPDATE job_orders SET job_details = '$newDetails' WHERE id={$job['id']}");
        }
        app_stage_data_set($conn, (int)$job['id'], 'printing', 'production_summary', 'الألوان: ' . (string)($_POST['colors_update'] ?? ''), (int)($_SESSION['user_id'] ?? 0), (string)$user_name);
        plastic_sync_rollup($conn, (int)$job['id'], (int)($_SESSION['user_id'] ?? 0), (string)$user_name);
        app_update_job_stage($conn, (int)$job['id'], 'cutting');
        safe_redirect($job['id']);
    }

    if (isset($_POST['finish_cutting'])) {
        app_update_job_stage($conn, (int)$job['id'], 'delivery');
        safe_redirect($job['id']);
    }

    if (isset($_POST['finish_delivery'])) {
        $check_inv = $conn->query("SELECT id FROM invoices WHERE job_id={$job['id']}");
        if($check_inv->num_rows == 0) {
            $client_id = $job['client_id']; $price = $job['price'] ?? 0;
            if ($conn->query("INSERT INTO invoices (client_id, job_id, total_amount, remaining_amount, inv_date, status) VALUES ($client_id, {$job['id']}, $price, $price, NOW(), 'unpaid')")) {
                app_assign_document_number($conn, 'invoices', (int)$conn->insert_id, 'invoice_number', 'invoice', date('Y-m-d'));
                $createdBy = (string)($_SESSION['name'] ?? 'System');
                app_apply_client_opening_balance_to_invoice($conn, (int)$conn->insert_id, (int)$client_id, date('Y-m-d'), $createdBy);
                if (function_exists('app_apply_client_receipt_credit_to_invoice')) {
                    app_apply_client_receipt_credit_to_invoice($conn, (int)$conn->insert_id, (int)$client_id, date('Y-m-d'), $createdBy);
                }
            }
        }
        app_update_job_stage($conn, (int)$job['id'], 'accounting');
        safe_redirect($job['id']);
    }

    if (isset($_POST['archive_job'])) { app_update_job_stage($conn, (int)$job['id'], 'completed', 'completed'); safe_redirect($job['id']); }
    if (isset($_POST['reopen_job'])) {
        app_update_job_stage($conn, (int)$job['id'], $first_stage, 'processing');
        safe_redirect($job['id']);
    }

    if (isset($_POST['return_stage'])) {
        $prev = trim((string)($_POST['prev_target'] ?? ''));
        if (!in_array($prev, $allowed_stage_keys, true)) {
            safe_redirect($job['id']);
        }
        $reason = $conn->real_escape_string($_POST['return_reason']);
        $note = "\n[⚠️ تراجع]: $reason";
        app_update_job_stage_with_note($conn, (int)$job['id'], $prev, $note);
        safe_redirect($job['id']);
    }
}

// 5. خريطة المراحل
$curr = app_workflow_current_stage($workflow, (string)($job['current_stage'] ?? ''), $first_stage);
$prev_stage_key = $workflow[$curr]['prev'] ?? null;
$next_stage_key = $workflow[$curr]['next'] ?? null;

$suppliers_options = "";
$s_res = $conn->query("SELECT name, phone FROM suppliers ORDER BY name ASC");
if($s_res) while($r = $s_res->fetch_assoc()) $suppliers_options .= "<option value='{$r['phone']}'>{$r['name']}</option>";

// جلب جميع الملفات والأصول مرة واحدة
$all_files = [];
$job_files_by_stage = [
    'cylinders' => [],
];
$jf = $conn->query("SELECT *, 'file' as origin FROM job_files WHERE job_id={$job['id']} ORDER BY id DESC");
while($row = $jf->fetch_assoc()) {
    $all_files[] = $row;
    $stageKey = (string)($row['stage'] ?? '');
    if (array_key_exists($stageKey, $job_files_by_stage)) {
        $job_files_by_stage[$stageKey][] = $row;
    }
}
$job_proofs = [];
$approved_proof = null;
$proof_status_counts = [];
$jp = $conn->query("SELECT *, 'proof' as origin, 'proof' as file_type FROM job_proofs WHERE job_id={$job['id']} ORDER BY id DESC");
while($row = $jp->fetch_assoc()) {
    $all_files[] = $row;
    $job_proofs[] = $row;
    $statusKey = (string)($row['status'] ?? 'pending');
    $proof_status_counts[$statusKey] = (int)($proof_status_counts[$statusKey] ?? 0) + 1;
    if ($approved_proof === null && $statusKey === 'approved') {
        $approved_proof = $row;
    }
}
?>

<style>
    :root { --pl-gold: #f1c40f; --pl-bg: #121212; --pl-card: #1e1e1e; --pl-green: #2ecc71; --pl-red: #e74c3c; --pl-blue: #3498db; }
    .split-layout { display: flex; gap: 20px; align-items: flex-start; }
    .sidebar { width: 300px; flex-shrink: 0; background: #151515; border: 1px solid #333; border-radius: 12px; padding: 20px; position: sticky; top: calc(var(--nav-total-height, 70px) + 20px); max-height: calc(100vh - var(--nav-total-height, 70px) - 40px); overflow-y: auto; }
    .main-content { flex: 1; min-width: 0; }
    @media (max-width: 900px) { 
        .split-layout { flex-direction: column; } 
        .sidebar { width: 100%; order: 2; position: static; max-height: none; } 
        .main-content { width: 100%; order: 1; margin-bottom: 20px; }
    }
    .info-block { margin-bottom: 20px; border-bottom: 1px dashed #333; padding-bottom: 15px; }
    .info-label { color: var(--pl-gold); font-size: 0.85rem; font-weight: bold; margin-bottom: 5px; display: block; }
    .info-value { color: #ddd; font-size: 0.95rem; white-space: pre-wrap; line-height: 1.6; background: #0a0a0a; padding: 10px; border-radius: 6px; border: 1px solid #222; }
    .timeline { position: relative; padding-right: 20px; border-right: 2px solid #333; }
    .timeline-item { position: relative; margin-bottom: 20px; }
    .timeline-item::before { content: ''; position: absolute; right: -26px; top: 5px; width: 10px; height: 10px; background: #555; border-radius: 50%; border: 2px solid #151515; transition: 0.3s; }
    .timeline-item.active::before { background: var(--pl-gold); box-shadow: 0 0 10px var(--pl-gold); }
    .timeline-item.active .t-title { color: var(--pl-gold); font-weight: bold; }
    .t-title { color: #888; font-size: 0.9rem; }
    .comments-box { background: #000; padding: 10px; border-radius: 6px; max-height: 200px; overflow-y: auto; font-size: 0.85rem; border: 1px solid #333; margin-bottom: 10px; }
    .comment-input { width: 100%; background: #222; border: 1px solid #444; padding: 8px; color: #fff; border-radius: 4px; margin-bottom: 5px; }
    .admin-controls { display: flex; gap: 5px; margin-top: 10px; background: #222; padding: 5px; border-radius: 5px; }
    .stage-header { display: flex; gap: 10px; overflow-x: auto; padding-bottom: 10px; margin-bottom: 20px; border-bottom: 1px solid #333; }
    .step-badge { background: #333; color: #777; padding: 5px 15px; border-radius: 20px; white-space: nowrap; font-size: 0.8rem; }
    .step-badge.active { background: var(--pl-gold); color: #000; font-weight: bold; }
    .main-card { background: var(--pl-card); padding: 25px; border-radius: 12px; border: 1px solid #333; margin-bottom: 20px; }
    .card-title { color: var(--pl-gold); margin: 0 0 15px 0; border-bottom: 1px dashed #444; padding-bottom: 10px; font-size: 1.2rem; }
    .btn { width: 100%; padding: 12px; border: none; border-radius: 5px; cursor: pointer; color: #fff; font-weight: bold; margin-top: 10px; transition: 0.2s; }
    .btn:hover { opacity: 0.9; }
    .btn-gold { background: linear-gradient(45deg, var(--pl-gold), #d4ac0d); color: #000; }
    .btn-green { background: var(--pl-green); }
    .btn-red { background: var(--pl-red); }
    .btn-gray { background: #444; }
    .btn-sm { padding: 5px 10px; font-size: 0.8rem; width: auto; margin-top: 0; }
    .p-input { background: #000; border: 1px solid #444; color: #fff; padding: 8px; width: 100%; border-radius: 4px; }
    .file-item { display: flex; align-items: center; gap: 10px; background: #0a0a0a; padding: 8px; margin-bottom: 5px; border-radius: 6px; border: 1px solid #333; transition: 0.2s; }
    .file-item:hover { border-color: var(--pl-gold); }
    .file-icon { font-size: 1.2rem; color: #777; }
    .file-link { flex: 1; color: #fff; text-decoration: none; font-size: 0.9rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .file-tag { font-size: 0.7rem; background: #333; padding: 2px 6px; border-radius: 4px; color: #aaa; }
    .delete-btn { background: none; border: none; color: var(--pl-red); cursor: pointer; padding: 0 5px; font-size: 1.1rem; transition: 0.2s; }
    .delete-btn:hover { transform: scale(1.1); }
    .modal-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.9); z-index: 9999; justify-content: center; align-items: center; }
    .modal-box { background: #1a1a1a; padding: 30px; width: min(450px, calc(100vw - 24px)); max-width: 450px; border: 2px solid var(--pl-red); border-radius: 10px; text-align: center; }
    
    .proof-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 15px; margin-top: 20px; }
    .proof-card { background: #000; border-radius: 8px; overflow: hidden; position: relative; }
    .proof-img { width: 100%; height: 120px; object-fit: cover; display: block; }
    .proof-status-icon { position: absolute; top: 5px; right: 5px; background: rgba(0,0,0,0.7); padding: 2px 6px; border-radius: 4px; font-size: 0.8rem; }
    .proof-feedback { padding: 8px; font-size: 0.8rem; color: #fff; background: #222; border-top: 1px solid #333; min-height: 40px; }
    .feedback-info { background: rgba(52, 152, 219, 0.1); border-left: 3px solid #3498db; padding: 5px; margin-top: 5px; color: #3498db; }
    @media (max-width: 560px) {
        .split-layout {
            display: flex;
            flex-direction: column;
            gap: 12px;
            padding-inline: 0;
            width: 100%;
            overflow-x: clip;
        }
        .main-content,
        .sidebar {
            width: 100%;
            max-width: 100%;
            min-width: 0;
            margin-inline: 0;
        }
        .main-content { order: 1; margin-bottom: 0; }
        .sidebar { order: 2; position: static; max-height: none; }
        .main-card,
        .sidebar { padding: 0; overflow: hidden; }
        .sidebar.mobile-collapsed > *:not(.sidebar-mobile-head) { display: none !important; }
        .sidebar-mobile-head {
            width: 100%;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            padding: 12px 14px;
            background: #141414;
            color: #f1d77f;
            border: 1px solid #333;
            border-radius: 10px;
            font: inherit;
            font-weight: 800;
            cursor: pointer;
        }
        .sidebar-mobile-head::after {
            content: "⌄";
            font-size: 1rem;
            transition: transform .2s ease;
        }
        .sidebar-mobile-head.open::after { transform: rotate(180deg); }
        .main-card { padding: 12px; }
        .stage-header {
            position: static;
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 8px;
            background: transparent;
            margin-inline: 0;
            padding: 0 0 8px;
            border-bottom: none;
            width: 100%;
            max-width: 100%;
            overflow: visible;
        }
        .proof-grid { grid-template-columns: repeat(auto-fill, minmax(100px, 1fr)); }
        input[type="file"][name="cyl_file[]"] { width: 100% !important; }
        .stage-header { margin-bottom: 14px; }
        .step-badge {
            min-width: 0;
            text-align: center;
            padding: 8px 10px;
            font-size: 0.72rem;
            line-height: 1.35;
            white-space: normal;
        }
        .step-badge.active { grid-column: 1 / -1; }
        .workflow-sidebar-block { display: none; }
        .file-item { flex-direction: column; align-items: flex-start; }
        .file-item form,
        .file-item a { width: 100%; }
        .card-title { font-size: 1.02rem; }
        .main-card form div[style*="display:flex"] { flex-direction: column; align-items: stretch !important; }
        .main-card input[type="file"],
        .main-card input[type="text"],
        .main-card textarea,
        .main-card button,
        .main-card select { width: 100% !important; max-width: 100%; }
        .main-card input[type="text"],
        .main-card textarea,
        .main-card select { padding: 10px; font-size: 0.9rem; }
    }
</style>
<script>
document.addEventListener('DOMContentLoaded', function () {
    if (!window.matchMedia('(max-width: 560px)').matches) return;
    document.querySelectorAll('.sidebar').forEach(function (sidebar) {
        if (sidebar.querySelector('.sidebar-mobile-head')) return;
        const titleEl = sidebar.querySelector('h3');
        const title = titleEl ? titleEl.textContent.trim() : 'ملف العملية';
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'sidebar-mobile-head';
        btn.textContent = title;
        btn.addEventListener('click', function () {
            sidebar.classList.toggle('mobile-collapsed');
            btn.classList.toggle('open');
        });
        sidebar.insertBefore(btn, sidebar.firstChild);
        sidebar.classList.add('mobile-collapsed');
    });
});
</script>

<div class="container split-layout">
    
    <div class="sidebar">
        <h3 style="color:#fff; border-bottom:2px solid var(--pl-gold); padding-bottom:10px; margin-top:0;">📂 ملف العملية</h3>
        
        <div class="info-block">
            <span class="info-label">👤 بيانات العميل والعملية:</span>
            <div class="info-value">
                <div style="margin-bottom:5px; color:var(--pl-gold);"><?php echo $job['client_name']; ?></div>
                <div style="font-size:1.1rem; font-weight:bold;"><?php echo $job['job_name']; ?></div>
            </div>
        </div>

        <div class="info-block">
            <span class="info-label">📝 تفاصيل/ملاحظات العميل:</span>
            <div class="info-value" style="font-size:0.85rem; color:#bbb;">
                <?php echo nl2br($job['job_details'] ?? 'لا توجد تفاصيل إضافية'); ?>
            </div>
        </div>

        <div class="info-block">
            <span class="info-label">📊 مواصفات فنية:</span>
            <div class="info-value" style="font-size:0.85rem;">
                <strong>الخامة:</strong> <?php echo $specs['material']; ?><br>
                <strong>السمك:</strong> <?php echo $specs['micron']; ?> ميكرون<br>
                <strong>العرض:</strong> <?php echo $specs['width']; ?> سم<br>
                <strong>السلندرات:</strong> <?php echo $specs['cyl_count']; ?>
            </div>
        </div>

        <div class="info-block">
            <span class="info-label">💬 المناقشات الداخلية:</span>
            <div class="comments-box">
                <?php echo nl2br($job['notes'] ?? 'لا توجد ملاحظات'); ?>
            </div>
            <form method="POST">
                <input type="text" name="comment_text" class="comment-input" placeholder="اكتب ملاحظة..." required>
                <button type="submit" name="add_internal_comment" class="btn btn-gray btn-sm" style="width:100%;">إرسال تعليق</button>
            </form>
        </div>

        <div class="info-block" style="border:none;">
            <span class="info-label">📎 الأرشيف والمرفقات (<?php echo count($all_files); ?>):</span>
            <?php if(!empty($all_files)): ?>
                <?php foreach($all_files as $f): 
                    $ext = pathinfo($f['file_path'], PATHINFO_EXTENSION);
                    $icon = in_array(strtolower($ext), ['jpg','png','jpeg','webp']) ? '🖼️' : '📄';
                    $type = ($f['origin'] == 'proof') ? 'proof' : 'file';
                    $f_desc = !empty($f['description']) ? $f['description'] : basename($f['file_path']);
                ?>
                <div class="file-item">
                    <span class="file-icon"><?php echo $icon; ?></span>
                    <a href="<?php echo $f['file_path']; ?>" target="_blank" class="file-link" title="<?php echo $f_desc; ?>"><?php echo $f_desc; ?></a>
                    <span class="file-tag"><?php echo ($type == 'proof') ? 'بروفة' : $f['stage']; ?></span>
                    <form method="POST" style="margin:0;">
                        <input type="hidden" name="type" value="<?php echo $type; ?>">
                        <input type="hidden" name="item_id" value="<?php echo $f['id']; ?>">
                        <input type="hidden" name="__async_form" value="1"><button name="delete_item" class="delete-btn" onclick="return confirm('حذف الملف نهائياً؟')">×</button>
                    </form>
                </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div style="color:#666; font-size:0.9rem; text-align:center;">لا توجد مرفقات حالياً</div>
            <?php endif; ?>
        </div>
        
        <div class="info-block workflow-sidebar-block">
            <span class="info-label">📋 مسار العمل:</span>
            <div class="timeline">
                <?php foreach($workflow as $k => $v): $active = ($k == $curr) ? 'active' : ''; ?>
                <div class="timeline-item <?php echo $active; ?>"><span class="t-title"><?php echo $v['label']; ?></span></div>
                <?php endforeach; ?>
            </div>
        </div>

        <?php if($can_force_stage): ?>
            <div class="info-block" style="border-top:1px dashed #333; padding-top:15px;">
                <span class="info-label">⚙️ تحكم إداري (تجاوز):</span>
                <div class="admin-controls">
                    <?php if($prev_stage_key): ?>
                    <form method="POST" style="flex:1;"><input type="hidden" name="target_stage" value="<?php echo $prev_stage_key; ?>"><button name="force_stage_change" class="btn btn-red btn-sm" style="width:100%;">« تراجع جبري</button></form>
                    <?php endif; ?>
                    <?php if($next_stage_key): ?>
                    <form method="POST" style="flex:1;"><input type="hidden" name="target_stage" value="<?php echo $next_stage_key; ?>"><button name="force_stage_change" class="btn btn-gold btn-sm" style="width:100%;">تمرير جبري »</button></form>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <div class="main-content">
        
        <div class="stage-header">
            <?php foreach($workflow as $key => $data): ?>
                <div class="step-badge <?php echo ($key == $curr) ? 'active' : ''; ?>"><?php echo $data['label']; ?></div>
            <?php endforeach; ?>
        </div>

        <div class="main-card" style="border-top:3px solid var(--pl-gold);">
            <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap;">
                <h3 class="card-title" style="margin:0;">🛍️ <?php echo $job['job_name']; ?> (#<?php echo $job['id']; ?>)</h3>
                <button onclick="printOrder()" class="btn btn-gray" style="width:auto; padding:5px 15px; font-size:0.8rem;">📄 طباعة أمر الشغل</button>
            </div>
            
            <div style="margin-top:15px;">
                <label style="color:#aaa;">💬 ملاحظات الإنتاج العامة:</label>
                <form method="POST" style="margin-top:5px; display:flex; gap:10px;">
                    <input type="text" name="prod_note" class="p-input" placeholder="اكتب ملاحظة..." required>
                    <button type="submit" name="save_prod_note" class="btn btn-gray" style="width:auto; margin:0;">حفظ</button>
                </form>
            </div>
        </div>

        <?php if($curr == 'briefing'): ?>
        <div class="main-card">
            <h3 class="card-title">📝 التجهيز</h3>
            <form method="POST">
                <textarea name="notes" rows="3" class="p-input" placeholder="تعليمات خاصة..."></textarea>
                <button name="save_brief" class="btn btn-gold">حفظ وبدء التصميم ➡️</button>
            </form>
        </div>
        <?php endif; ?>

        <?php if($curr == 'design'): ?>
        <div class="main-card">
            <h3 class="card-title">🎨 التصميم</h3>
            <form method="POST" enctype="multipart/form-data" style="margin-bottom:20px;">
                <div style="display:flex; gap:10px;">
                    <input type="text" name="proof_desc" placeholder="اسم التصميم" class="p-input">
                    <input type="file" name="proof_file" style="color:#aaa;">
                </div>
                <button name="upload_proof" class="btn btn-gray">📤 رفع بروفة</button>
            </form>
            <div style="display:grid; grid-template-columns: repeat(auto-fill, minmax(100px, 1fr)); gap:10px;">
                <?php foreach($job_proofs as $p): ?>
                    <div style="background:#000; padding:5px; text-align:center;">
                        <a href="<?php echo $p['file_path']; ?>" target="_blank"><img src="<?php echo $p['file_path']; ?>" style="width:100%; height:60px; object-fit:contain;"></a>
                        <div style="font-size:0.7rem; color:#888;"><?php echo $p['description']; ?></div>
                        <form method="POST" onsubmit="return confirm('حذف؟');"><input type="hidden" name="__async_form" value="1"><input type="hidden" name="type" value="proof"><input type="hidden" name="item_id" value="<?php echo $p['id']; ?>"><button name="delete_item" style="color:red; background:none; border:none; cursor:pointer;">×</button></form>
                    </div>
                <?php endforeach; ?>
            </div>
            <form method="POST"><button name="send_to_review" class="btn btn-gold">إرسال للمراجعة ➡️</button></form>
        </div>
        <?php endif; ?>

        <?php if($curr == 'client_rev'): ?>
        <div class="main-card">
            <h3 class="card-title">⏳ مراجعة العميل</h3>
            <?php 
            $link = app_client_review_link($conn, $job);
            $wa_link = get_wa_link($job['client_phone'], "رابط المراجعة:\n$link");
            $wa_attr = $wa_link ? "href='$wa_link' target='_blank'" : "href='#' onclick=\"alert('رقم خطأ');\"";
            
            $st_arr = $proof_status_counts;
            ?>
            
            <div style="text-align:center; padding:10px; background:#111; margin-bottom:15px; border-radius:5px;">
                <a <?php echo $wa_attr; ?> class="btn btn-green" style="display:inline-block; width:auto;">📱 إرسال الرابط للعميل واتساب</a>
            </div>

            <div class="proof-grid">
                <?php 
                foreach($job_proofs as $p):
                    $border_color = '#444';
                    $status_icon = '⏳';
                    if($p['status'] == 'approved') { $border_color = 'var(--pl-green)'; $status_icon = '✅'; }
                    if($p['status'] == 'rejected') { $border_color = 'var(--pl-red)'; $status_icon = '❌'; }
                ?>
                <div class="proof-card" style="border: 2px solid <?php echo $border_color; ?>;">
                    <span class="proof-status-icon"><?php echo $status_icon; ?></span>
                    <a href="<?php echo $p['file_path']; ?>" target="_blank">
                        <img src="<?php echo $p['file_path']; ?>" class="proof-img">
                    </a>
                    <div class="proof-feedback">
                        <?php if($p['status'] == 'rejected'): ?>
                            <strong style="color:var(--pl-red);">رفض:</strong>
                        <?php elseif($p['status'] == 'approved'): ?>
                            <strong style="color:var(--pl-green);">موافقة</strong>
                        <?php else: ?>
                            <strong style="color:#888;">انتظار...</strong>
                        <?php endif; ?>
                        
                        <?php if(!empty($p['client_comment'])): ?>
                            <div class="<?php echo ($p['status'] == 'rejected') ? '' : 'feedback-info'; ?>" style="margin-top:4px; font-style:italic;">
                                💬 <?php echo htmlspecialchars($p['client_comment']); ?>
                            </div>
                        <?php else: ?>
                            <div style="margin-top:4px; color:#666;">-</div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            
            <?php if(($st_arr['rejected']??0) > 0): ?>
                 <form method="POST" style="margin-top:20px;"><input type="hidden" name="prev_target" value="design"><input type="hidden" name="return_reason" value="رفض العميل"><button name="return_stage" class="btn btn-red">↩️ يوجد رفض - عودة للتصميم</button></form>
            <?php else: ?>
                 <form method="POST" style="margin-top:20px;"><button name="finalize_review" class="btn btn-gold">اعتماد (للسلندرات) ➡️</button></form>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if($curr == 'cylinders'): ?>
        <div class="main-card">
            <h3 class="card-title">⚙️ السلندرات والتجهيزات</h3>
            
            <form method="POST" enctype="multipart/form-data">
                <div id="cyl-area">
                    <div style="display:flex; gap:5px; margin-bottom:5px;">
                        <input type="text" name="cyl_desc[]" placeholder="وصف (سلندر وجه/خلف)" class="p-input" style="flex:2;">
                        <select name="cyl_supplier[]" class="p-input" style="flex:1;">
                            <option value="">اختر مورد...</option>
                            <?php echo $suppliers_options; ?>
                        </select>
                        <input type="file" name="cyl_file[]" style="width:80px;">
                    </div>
                </div>
                <button type="button" onclick="addCyl()" class="btn btn-gray" style="width:auto;">+ ملف آخر</button>
                <button type="submit" name="save_cylinders" class="btn btn-gray" style="margin-top:10px;">💾 حفظ ورفع الملفات</button>
            </form>
            <script>function addCyl(){ let d=document.createElement('div'); d.innerHTML=document.querySelector('#cyl-area > div').innerHTML; document.getElementById('cyl-area').appendChild(d); }</script>
            
            <div style="margin-top:20px; border-top:1px dashed #333; padding-top:10px;">
                <h4 style="color:var(--pl-gold); margin:0 0 10px 0;">📂 ملفات السلندرات المحفوظة:</h4>
                <?php 
                if(!empty($job_files_by_stage['cylinders'])):
                    foreach($job_files_by_stage['cylinders'] as $cf):
                        $supp_phone = preg_replace('/[^0-9]/', '', $cf['uploaded_by']); 
                        $file_link_full = $base_url . '/' . $cf['file_path'];
                        $wa_msg = "مرحباً، مرفق ملف السلندر: \n" . $file_link_full . "\n" . "الوصف: " . $cf['description'];
                        $wa_url = get_wa_link($supp_phone, $wa_msg);
                ?>
                <div style="background:#0a0a0a; padding:10px; margin-bottom:5px; border-radius:5px; display:flex; justify-content:space-between; align-items:center;">
                    <div>
                        <a href="<?php echo $cf['file_path']; ?>" target="_blank" style="color:#fff; text-decoration:none;">📄 <?php echo $cf['description']; ?></a>
                        <div style="font-size:0.75rem; color:#666;">مورد: <?php echo $cf['uploaded_by'] ?: 'غير محدد'; ?></div>
                    </div>
                    <?php if($wa_url): ?>
                        <a href="<?php echo $wa_url; ?>" target="_blank" class="btn btn-green btn-sm" style="text-decoration:none;">📱 إرسال واتساب</a>
                    <?php else: ?>
                        <span style="font-size:0.7rem; color:#555;">(لا يوجد رقم)</span>
                    <?php endif; ?>
                </div>
                <?php endforeach; else: echo '<p style="color:#666;">لم يتم حفظ ملفات بعد.</p>'; endif; ?>
            </div>

            <div style="display:flex; gap:10px; margin-top:20px;">
                <form method="POST" style="flex:1;"><button name="finish_cylinders" class="btn btn-gold">✅ السلندرات جاهزة (للسحب)</button></form>
            </div>
            
            <form method="POST" style="margin-top:10px;"><input type="hidden" name="prev_target" value="client_rev"><textarea name="return_reason" placeholder="سبب التراجع..." style="width:100%; background:#222; border:1px solid #444; color:#fff; display:none;" id="ret_reason_cyl"></textarea><button type="button" onclick="document.getElementById('ret_reason_cyl').style.display='block'; this.type='submit'; this.name='return_stage'; this.innerHTML='تأكيد التراجع'; this.className='btn btn-red';" class="btn btn-gray">↩️ تراجع للمصادقة</button></form>
        </div>
        <?php endif; ?>

        <?php if($curr == 'extrusion'): ?>
        <div class="main-card">
            <h3 class="card-title">🏭 السحب (Extrusion)</h3>
            <p style="color:#aaa;">المواصفات: <?php echo "{$specs['material']} - {$specs['micron']} ميكرون - {$specs['width']} سم"; ?></p>
            <form method="POST"><button name="finish_extrusion" class="btn btn-gold">✅ تم السحب (للطباعة)</button></form>
            <form method="POST" style="margin-top:10px;"><input type="hidden" name="prev_target" value="cylinders"><textarea name="return_reason" placeholder="السبب..." style="width:100%; background:#222; border:1px solid #444; color:#fff; display:none;" id="ret_reason_ext"></textarea><button type="button" onclick="document.getElementById('ret_reason_ext').style.display='block'; this.type='submit'; this.name='return_stage'; this.innerHTML='تأكيد التراجع'; this.className='btn btn-red';" class="btn btn-gray">↩️ تراجع للسلندرات</button></form>
        </div>
        <?php endif; ?>

        <?php if($curr == 'printing'): ?>
        <div class="main-card">
            <h3 class="card-title">🖨️ الطباعة (Flexo/Roto)</h3>
            
            <?php 
            $app_proof = $approved_proof;
            if($app_proof): ?>
                <div style="text-align:center; margin-bottom:15px; border:1px solid var(--pl-gold); padding:10px;">
                    <p style="color:var(--pl-gold); margin:0 0 5px 0;">🎨 التصميم المعتمد:</p>
                    <a href="<?php echo $app_proof['file_path']; ?>" target="_blank"><img src="<?php echo $app_proof['file_path']; ?>" style="max-width:100%; height:200px; object-fit:contain;"></a>
                </div>
            <?php endif; ?>

            <label style="color:#aaa;">الألوان:</label>
            <input type="text" name="colors_update" value="<?php echo $specs['colors']; ?>" class="p-input" style="margin-bottom:10px;">
            
            <form method="POST"><button name="finish_printing" class="btn btn-gold">✅ تمت الطباعة (للقص)</button></form>
            <form method="POST" style="margin-top:10px;"><input type="hidden" name="prev_target" value="extrusion"><textarea name="return_reason" placeholder="السبب..." style="width:100%; background:#222; border:1px solid #444; color:#fff; display:none;" id="ret_reason_prt"></textarea><button type="button" onclick="document.getElementById('ret_reason_prt').style.display='block'; this.type='submit'; this.name='return_stage'; this.innerHTML='تأكيد التراجع'; this.className='btn btn-red';" class="btn btn-gray">↩️ تراجع للسحب</button></form>
        </div>
        <?php endif; ?>

        <?php if($curr == 'cutting'): ?>
        <div class="main-card">
            <h3 class="card-title">✂️ القص واللحام</h3>
            <p style="color:#aaa;">طول القص المطلوب: <strong><?php echo $specs['cut_len']; ?> سم</strong></p>
            <form method="POST"><button name="finish_cutting" class="btn btn-gold">✅ تم القص والتعبئة (للتسليم)</button></form>
            <form method="POST" style="margin-top:10px;"><input type="hidden" name="prev_target" value="printing"><textarea name="return_reason" placeholder="السبب..." style="width:100%; background:#222; border:1px solid #444; color:#fff; display:none;" id="ret_reason_cut"></textarea><button type="button" onclick="document.getElementById('ret_reason_cut').style.display='block'; this.type='submit'; this.name='return_stage'; this.innerHTML='تأكيد التراجع'; this.className='btn btn-red';" class="btn btn-gray">↩️ تراجع للطباعة</button></form>
        </div>
        <?php endif; ?>

        <?php if($curr == 'delivery'): ?>
        <div class="main-card">
            <h3 class="card-title">🚚 التسليم</h3>
            
            <div style="background:#111; padding:20px; border-right:4px solid var(--pl-green); border-radius:5px; margin-bottom:20px;">
                <div style="display:flex; justify-content:space-between; align-items:center;">
                    <div>
                        <h4 style="margin:0 0 10px 0; color:#fff; font-size:1.2rem;">👤 <?php echo $job['client_name']; ?></h4>
                        <p style="margin:5px 0; color:#ccc;">📞 <?php echo $job['client_phone']; ?></p>
                        <p style="margin:5px 0; color:#ccc;">📦 الكمية: <strong><?php echo $job['quantity']; ?></strong></p>
                    </div>
                    <div style="font-size:3rem; color:var(--pl-gold);"><i class="fa-solid fa-box-open"></i></div>
                </div>
                <div style="margin-top:15px; display:flex; gap:10px;">
                    <a href="tel:<?php echo $job['client_phone']; ?>" class="btn btn-gray" style="flex:1; text-align:center; text-decoration:none;">📞 اتصال</a>
                    <a href="<?php echo get_wa_link($job['client_phone'], "مرحباً، طلبيتك #{$job['id']} جاهزة للاستلام."); ?>" target="_blank" class="btn btn-green" style="flex:1; text-align:center; text-decoration:none;">📱 واتساب</a>
                </div>
            </div>

            <form method="POST" onsubmit="return confirm('إغلاق نهائي؟');"><button name="finish_delivery" class="btn btn-gold">تسليم وإغلاق 🏁</button></form>
        </div>
        <?php endif; ?>

        <?php if(in_array($curr, ['accounting', 'completed'])): ?>
        <div class="main-card" style="text-align:center;">
            <h2 style="color:var(--pl-green);">✅ العملية مكتملة</h2>
            <?php if($is_financial): ?>
                <a href="invoices.php?tab=sales" class="btn btn-gray" style="display:inline-block; width:auto;">الملف المالي</a>
                <?php if($curr == 'accounting'): ?><form method="POST"><button name="archive_job" class="btn btn-gold" style="width:auto; margin-top:10px;">أرشفة نهائية</button></form><?php endif; ?>
            <?php endif; ?>
            <?php if($curr == 'completed'): ?><form method="POST" onsubmit="return confirm('تأكيد؟');" style="margin-top:20px;"><button name="reopen_job" class="btn btn-red" style="width:auto;">🔄 إعادة فتح</button></form><?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if($prev_stage_key && !in_array($curr, ['completed'])): ?>
        <div style="text-align:right; margin-top:20px;">
            <button onclick="document.getElementById('backModal').style.display='flex'" class="btn btn-red" style="width:auto; padding:8px 20px; font-size:0.8rem;">↩️ تراجع</button>
        </div>
        <?php endif; ?>

    </div>
</div>

<div id="backModal" class="modal-overlay">
    <div class="modal-box">
        <h3 style="color:var(--pl-red);">⚠️ تراجع للمرحلة السابقة</h3>
        <form method="POST">
            <input type="hidden" name="prev_target" value="<?php echo $prev_stage_key; ?>">
            <textarea name="return_reason" required placeholder="سبب التراجع..." style="width:100%; height:80px; background:#000; color:#fff; border:1px solid #555;"></textarea>
            <div style="display:flex; gap:10px; margin-top:10px;">
                <button name="return_stage" class="btn btn-red">تأكيد</button>
                <button type="button" onclick="document.getElementById('backModal').style.display='none'" class="btn btn-gray">إلغاء</button>
            </div>
        </form>
    </div>
</div>

<script>
const moduleCsrfToken = <?php echo json_encode(app_csrf_token(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('form[method="POST"], form[method="post"]').forEach(function (form) {
        if (form.querySelector('input[name="_csrf_token"]')) {
            return;
        }
        var input = document.createElement('input');
        input.type = 'hidden';
        input.name = '_csrf_token';
        input.value = moduleCsrfToken;
        form.appendChild(input);
    });
});
function printOrder() {
    var win = window.open('', '', 'width=800,height=600');
    var fullNotes = <?php echo json_encode(nl2br($job['notes'] ?? '')); ?>;
    var fullSpecs = <?php echo json_encode(nl2br($job['job_details'] ?? '')); ?>;
    win.document.write('<html dir="rtl"><body style="font-family:sans-serif; padding:20px;">');
    win.document.write('<h2 style="text-align:center; border-bottom:2px solid #000;">أمر تشغيل بلاستيك</h2>');
    win.document.write('<h3>العميل: <?php echo $job['client_name']; ?> | العملية: <?php echo $job['job_name']; ?></h3>');
    win.document.write('<table border="1" width="100%" cellpadding="10" style="border-collapse:collapse; margin-top:20px;">');
    win.document.write('<tr><td><strong>الخامة:</strong> <?php echo $specs['material']; ?></td><td><strong>السمك:</strong> <?php echo $specs['micron']; ?> ميكرون</td></tr>');
    win.document.write('<tr><td><strong>العرض:</strong> <?php echo $specs['width']; ?> سم</td><td><strong>القص:</strong> <?php echo $specs['cut_len']; ?> سم</td></tr>');
    win.document.write('<tr><td><strong>المعالجة:</strong> <?php echo $specs['treat']; ?></td><td><strong>السلندرات:</strong> <?php echo $specs['cyl_count']; ?></td></tr>');
    win.document.write('<tr><td colspan="2"><strong>الألوان:</strong> <?php echo $specs['colors']; ?></td></tr>');
    win.document.write('</table>');
    win.document.write('<div style="margin-top:12px; border:1px solid #000; padding:10px;"><strong>🔧 الفنيات الكاملة:</strong><br>' + fullSpecs + '</div>');
    win.document.write('<div style="margin-top:20px; border:1px dashed #000; padding:10px;"><strong>📜 ملاحظات:</strong><br>' + fullNotes + '</div>');
    win.document.write('<script>window.print();<\/script></body></html>');
    win.document.close();
}
</script>
