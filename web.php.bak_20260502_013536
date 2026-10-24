<?php
// modules/web.php - (Royal Web Manager V44.0 - Mobile & Full Control)

// 0. إعدادات النظام
error_reporting(E_ALL);
@ini_set('upload_max_filesize', '2048M');
@ini_set('post_max_size', '2048M');
@ini_set('max_execution_time', '3600');
@ini_set('max_input_time', '3600');
@ini_set('memory_limit', '2048M');

if (!isset($conn, $job) || !is_array($job) || !isset($job['id'])) {
    http_response_code(403);
    exit('Forbidden');
}

if (function_exists('app_ensure_job_stage_data_schema')) {
    app_ensure_job_stage_data_schema($conn);
}

// 1. دالة التوجيه
function safe_redirect($id) {
    if (!headers_sent()) {
        header('Location: job_details.php?id=' . (int)$id);
        exit;
    }
    echo "<script>window.location.href = 'job_details.php?id=" . (int)$id . "';</script>";
    exit;
}
function web_is_ajax_request(): bool {
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
function web_finish_request(int $jobId, array $payload = []): void {
    if (web_is_ajax_request()) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
    safe_redirect($jobId);
}

// 2. استخراج البيانات الفنية للعرض
$raw_text = $job['job_details'] ?? '';
function get_spec($pattern, $text) {
    preg_match($pattern, $text, $matches);
    return isset($matches[1]) ? trim($matches[1]) : '-';
}
function web_extract_latest_note($notes, $label) {
    $pattern = '/\[' . preg_quote($label, '/') . '\]:\s*(.*?)(?=\n\[|$)/su';
    if (!preg_match_all($pattern, (string)$notes, $matches) || empty($matches[1])) {
        return '';
    }
    return trim((string)end($matches[1]));
}
function web_stage_value(mysqli $conn, array $job, string $stageKey, string $fieldKey, string $fallbackLabel = '', string $default = ''): string {
    $value = app_stage_data_get($conn, (int)($job['id'] ?? 0), $stageKey, $fieldKey, '');
    if ($value !== '') {
        return $value;
    }
    if ($fallbackLabel !== '') {
        $legacy = web_extract_latest_note($job['notes'] ?? '', $fallbackLabel);
        if ($legacy !== '') {
            return $legacy;
        }
    }
    return $default;
}

function web_count_job_files_by_stage(mysqli $conn, int $jobId, string $stage): int {
    if ($jobId <= 0 || $stage === '') {
        return 0;
    }
    $safeStage = $conn->real_escape_string($stage);
    $row = $conn->query("SELECT COUNT(*) AS cnt FROM job_files WHERE job_id={$jobId} AND stage='{$safeStage}'")->fetch_assoc();
    return (int)($row['cnt'] ?? 0);
}

function web_proofs_count(mysqli $conn, int $jobId): int {
    if ($jobId <= 0) {
        return 0;
    }
    $row = $conn->query("SELECT COUNT(*) AS cnt FROM job_proofs WHERE job_id={$jobId}")->fetch_assoc();
    return (int)($row['cnt'] ?? 0);
}

function web_sync_rollup(mysqli $conn, array $job, int $userId, string $userName): void {
    $jobId = (int)($job['id'] ?? 0);
    if ($jobId <= 0) {
        return;
    }

    $requirements = app_stage_data_get($conn, $jobId, 'briefing', 'requirements', '');
    $devUrl = app_stage_data_get($conn, $jobId, 'development', 'dev_url', '');
    $devNotes = app_stage_data_get($conn, $jobId, 'development', 'dev_notes', '');
    $testingReport = app_stage_data_get($conn, $jobId, 'testing', 'testing_report', '');
    $briefingFiles = (string)web_count_job_files_by_stage($conn, $jobId, 'briefing');
    $uiFiles = (string)web_proofs_count($conn, $jobId);

    foreach (['briefing', 'ui_design', 'client_rev', 'development', 'testing', 'launch', 'accounting', 'completed'] as $stageKey) {
        app_stage_data_set($conn, $jobId, $stageKey, 'requirements', $requirements, $userId, $userName);
        app_stage_data_set($conn, $jobId, $stageKey, 'briefing_files_count', $briefingFiles, $userId, $userName);
    }
    foreach (['ui_design', 'client_rev', 'development', 'testing', 'launch', 'accounting', 'completed'] as $stageKey) {
        app_stage_data_set($conn, $jobId, $stageKey, 'ui_files_count', $uiFiles, $userId, $userName);
    }
    foreach (['development', 'testing', 'launch', 'accounting', 'completed'] as $stageKey) {
        app_stage_data_set($conn, $jobId, $stageKey, 'dev_url', $devUrl, $userId, $userName);
        app_stage_data_set($conn, $jobId, $stageKey, 'dev_notes', $devNotes, $userId, $userName);
    }
    foreach (['testing', 'launch', 'accounting', 'completed'] as $stageKey) {
        app_stage_data_set($conn, $jobId, $stageKey, 'testing_report', $testingReport, $userId, $userName);
    }
}

$specs = [
    'type'    => get_spec('/(?:نوع الموقع|المشروع):\s*(.*)/u', $raw_text),
    'domain'  => get_spec('/(?:الدومين|النطاق):\s*(.*)/u', $raw_text),
    'hosting' => get_spec('/(?:الاستضافة|Hosting):\s*(.*)/u', $raw_text),
    'theme'   => get_spec('/(?:الثيم|Theme):\s*(.*)/u', $raw_text),
];
$current_requirements = web_stage_value($conn, $job, 'briefing', 'requirements', 'متطلبات المشروع');
$current_dev_url = web_stage_value($conn, $job, 'development', 'dev_url', 'رابط المعاينة');
$current_dev_notes = web_stage_value($conn, $job, 'development', 'dev_notes', 'ملاحظات التطوير');
$current_testing_report = web_stage_value($conn, $job, 'testing', 'testing_report', 'تقرير الاختبار');

$fallbackWorkflowLabels = [
    'briefing'    => '1. التحليل',
    'ui_design'   => '2. تصميم UI',
    'client_rev'  => '3. مراجعة UI',
    'development' => '4. البرمجة',
    'testing'     => '5. الاختبار',
    'launch'      => '6. الإطلاق',
    'accounting'  => '7. الحسابات',
    'completed'   => '8. الأرشيف',
];
$workflow = app_operation_workflow($conn, 'web', $fallbackWorkflowLabels);
$allowed_stage_keys = array_keys($workflow);
$first_stage = (string)array_key_first($workflow);
if ($first_stage === '') {
    $first_stage = 'briefing';
}
$curr = app_workflow_current_stage($workflow, (string)($job['current_stage'] ?? ''), $first_stage);
$can_force_stage = app_user_can('jobs.manage_all');

// 3. معالجة الطلبات (Controller Logic)
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (!app_verify_csrf($_POST['_csrf_token'] ?? '')) {
        http_response_code(403);
        exit('Invalid CSRF token');
    }

    $user_name = $_SESSION['name'] ?? 'Developer';

    // === أدوات التحكم الجديدة (التعليقات، الحذف، التمرير الجبري) ===

    // 1. إضافة تعليق داخلي
    if (isset($_POST['add_internal_comment'])) {
        if(!empty($_POST['comment_text'])) {
            $c_text = $conn->real_escape_string($_POST['comment_text']);
            $timestamp = date('Y-m-d H:i');
            $new_note = "\n[تعليق $user_name ($timestamp)]: $c_text";
            $conn->query("UPDATE job_orders SET notes = CONCAT(IFNULL(notes, ''), '$new_note') WHERE id={$job['id']}");
        }
        safe_redirect($job['id']);
    }

    // 2. التحكم الجبري بالمراحل (Force Stage)
    if (isset($_POST['force_stage_change']) && $can_force_stage) {
        $target_stage = trim((string)($_POST['target_stage'] ?? ''));
        if (in_array($target_stage, $allowed_stage_keys, true)) {
            app_update_job_stage($conn, (int)$job['id'], $target_stage);
        }
        safe_redirect($job['id']);
    }

    // 3. حذف الملفات (شامل: من الاستضافة وقاعدة البيانات)
    if (isset($_POST['delete_item'])) {
        $itemType = trim((string)($_POST['type'] ?? ''));
        $tbl = ($itemType === 'proof') ? 'job_proofs' : 'job_files';
        $id = intval($_POST['item_id']);
        
        // جلب المسار لحذفه من السيرفر
        $q = $conn->query("SELECT file_path FROM $tbl WHERE id=$id");
        if ($r = $q->fetch_assoc()) { 
            app_safe_unlink((string)($r['file_path'] ?? ''), __DIR__ . '/uploads');
        }
        
        // حذف من قاعدة البيانات
        $conn->query("DELETE FROM $tbl WHERE id=$id");
        web_sync_rollup($conn, $job, (int)($_SESSION['user_id'] ?? 0), (string)$user_name);
        safe_redirect($job['id']);
    }
    // === نهاية الأدوات الجديدة ===

    // A. مرحلة التحليل (Briefing)
    if (isset($_POST['save_brief']) || isset($_POST['upload_brief_files'])) {
        $briefUploadedCount = 0;
        $briefErrors = [];
        $reqs = $conn->real_escape_string(trim((string)($_POST['requirements'] ?? '')));
        if ($reqs !== '') {
            $conn->query("UPDATE job_orders SET notes = CONCAT(IFNULL(notes, ''), '\n[متطلبات المشروع]: $reqs') WHERE id={$job['id']}");
            app_stage_data_set($conn, (int)$job['id'], 'briefing', 'requirements', (string)($_POST['requirements'] ?? ''), (int)($_SESSION['user_id'] ?? 0), (string)$user_name);
        }
        
        if (!empty($_FILES['doc_files']['name'][0])) {
            foreach ($_FILES['doc_files']['name'] as $i => $name) {
                if ($_FILES['doc_files']['error'][$i] == 0) {
                    $stored = app_store_uploaded_file([
                        'name' => $_FILES['doc_files']['name'][$i] ?? '',
                        'type' => $_FILES['doc_files']['type'][$i] ?? '',
                        'tmp_name' => $_FILES['doc_files']['tmp_name'][$i] ?? '',
                        'error' => $_FILES['doc_files']['error'][$i] ?? UPLOAD_ERR_NO_FILE,
                        'size' => $_FILES['doc_files']['size'][$i] ?? 0,
                    ], [
                        'dir' => 'uploads/briefs',
                        'prefix' => 'web_',
                        'max_size' => 2048 * 1024 * 1024,
                    ]);
                    if (!empty($stored['ok'])) {
                        $target = (string)$stored['path'];
                        $conn->query("INSERT INTO job_files (job_id, file_path, stage, description, uploaded_by) VALUES ({$job['id']}, '$target', 'briefing', 'وثائق المشروع', '$user_name')");
                        $briefUploadedCount++;
                    } else {
                        $briefErrors[] = (string)($stored['error'] ?? 'Upload failed.');
                    }
                } elseif (($_FILES['doc_files']['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                    $briefErrors[] = app_upload_error_message((int)($_FILES['doc_files']['error'][$i] ?? UPLOAD_ERR_NO_FILE));
                }
            }
        }
        if (isset($_POST['upload_brief_files'])) {
            web_sync_rollup($conn, $job, (int)($_SESSION['user_id'] ?? 0), (string)$user_name);
            web_finish_request((int)$job['id'], [
                'ok' => ($briefUploadedCount > 0 || empty($briefErrors)),
                'message' => $briefUploadedCount > 0 ? 'تم رفع الملفات بنجاح.' : 'تم حفظ المتطلبات بنجاح.',
                'error' => ($briefUploadedCount <= 0 && !empty($briefErrors)) ? trim(implode(' | ', array_filter($briefErrors))) : '',
                'reload' => true,
                'redirect' => 'job_details.php?id=' . (int)$job['id'],
            ]);
        }
        web_sync_rollup($conn, $job, (int)($_SESSION['user_id'] ?? 0), (string)$user_name);
        app_update_job_stage($conn, (int)$job['id'], 'ui_design');
        web_finish_request((int)$job['id'], [
            'ok' => true,
            'message' => 'تم حفظ المتطلبات والانتقال إلى تصميم الواجهة.',
            'reload' => true,
            'redirect' => 'job_details.php?id=' . (int)$job['id'],
        ]);
    }

    // B. تصميم الواجهة (UI/UX)
    if (isset($_POST['action_ui'])) {
        $uiUploadedCount = 0;
        $uiErrors = [];
        // 1. رفع الملفات أولاً (إذا وجدت)
        if (!empty($_FILES['ui_files']['name'][0])) {
            foreach ($_FILES['ui_files']['name'] as $i => $name) {
                if ($_FILES['ui_files']['error'][$i] == 0) {
                    $stored = app_store_uploaded_file([
                        'name' => $_FILES['ui_files']['name'][$i] ?? '',
                        'type' => $_FILES['ui_files']['type'][$i] ?? '',
                        'tmp_name' => $_FILES['ui_files']['tmp_name'][$i] ?? '',
                        'error' => $_FILES['ui_files']['error'][$i] ?? UPLOAD_ERR_NO_FILE,
                        'size' => $_FILES['ui_files']['size'][$i] ?? 0,
                    ], [
                        'dir' => 'uploads/proofs',
                        'prefix' => 'ui_',
                        'max_size' => 2048 * 1024 * 1024,
                    ]);
                    if (!empty($stored['ok'])) {
                        $target = (string)$stored['path'];
                        $conn->query("INSERT INTO job_proofs (job_id, file_path, description, status) VALUES ({$job['id']}, '$target', 'تصميم واجهة', 'pending')");
                        $uiUploadedCount++;
                    } else {
                        $uiErrors[] = (string)($stored['error'] ?? ('فشل رفع الملف: ' . $name));
                    }
                } elseif (($_FILES['ui_files']['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                    $uiErrors[] = app_upload_error_message((int)($_FILES['ui_files']['error'][$i] ?? UPLOAD_ERR_NO_FILE));
                }
            }
        }

        // 2. التحقق من الزر المضغوط
        if ($_POST['action_ui'] === 'send_review') {
            $proofsCountRes = $conn->query("SELECT COUNT(*) AS cnt FROM job_proofs WHERE job_id={$job['id']}");
            $proofsCount = (int)(($proofsCountRes ? ($proofsCountRes->fetch_assoc()['cnt'] ?? 0) : 0));
            if (($proofsCount + $uiUploadedCount) <= 0) {
                web_finish_request((int)$job['id'], [
                    'ok' => false,
                    'error' => 'لا يمكن الإرسال للمراجعة بدون رفع تصميم واجهة واحد على الأقل.',
                    'reload' => false,
                    'redirect' => 'job_details.php?id=' . (int)$job['id'],
                ]);
            }
            // الانتقال للمرحلة التالية
            app_update_job_stage($conn, (int)$job['id'], 'client_rev');
        } elseif ($uiUploadedCount <= 0 && !empty($uiErrors)) {
            web_finish_request((int)$job['id'], [
                'ok' => false,
                'error' => trim(implode(' | ', array_filter($uiErrors))),
                'reload' => false,
                'redirect' => 'job_details.php?id=' . (int)$job['id'],
            ]);
        }
        web_sync_rollup($conn, $job, (int)($_SESSION['user_id'] ?? 0), (string)$user_name);
        web_finish_request((int)$job['id'], [
            'ok' => true,
            'message' => ($_POST['action_ui'] === 'send_review') ? 'تم رفع الملفات وإرسالها للمراجعة.' : 'تم رفع الملفات بنجاح.',
            'reload' => true,
            'redirect' => 'job_details.php?id=' . (int)$job['id'],
        ]);
    }

    // C. مراجعة العميل
    if (isset($_POST['approve_ui'])) {
        app_update_job_stage($conn, (int)$job['id'], 'development');
        safe_redirect($job['id']);
    }

    // D. البرمجة (Development)
    if (isset($_POST['save_dev_progress'])) {
        $dev_url = $conn->real_escape_string(trim((string)($_POST['dev_url'] ?? '')));
        $dev_notes = $conn->real_escape_string(trim((string)($_POST['dev_notes'] ?? '')));
        if(!empty($dev_url)) {
            $conn->query("UPDATE job_orders SET notes = CONCAT(IFNULL(notes, ''), '\n[رابط المعاينة]: $dev_url') WHERE id={$job['id']}");
        }
        if(!empty($dev_notes)) {
            $conn->query("UPDATE job_orders SET notes = CONCAT(IFNULL(notes, ''), '\n[ملاحظات التطوير]: $dev_notes') WHERE id={$job['id']}");
        }
        app_stage_data_set($conn, (int)$job['id'], 'development', 'dev_url', (string)($_POST['dev_url'] ?? ''), (int)($_SESSION['user_id'] ?? 0), (string)$user_name);
        app_stage_data_set($conn, (int)$job['id'], 'development', 'dev_notes', (string)($_POST['dev_notes'] ?? ''), (int)($_SESSION['user_id'] ?? 0), (string)$user_name);
        
        if($_POST['save_dev_progress'] === 'finish') {
             app_update_job_stage($conn, (int)$job['id'], 'testing');
        }
        web_sync_rollup($conn, $job, (int)($_SESSION['user_id'] ?? 0), (string)$user_name);
        web_finish_request((int)$job['id'], [
            'ok' => true,
            'message' => ($_POST['save_dev_progress'] === 'finish') ? 'تم حفظ التقدم والانتقال للاختبار.' : 'تم حفظ تقدم التطوير بنجاح.',
            'reload' => ($_POST['save_dev_progress'] === 'finish'),
            'redirect' => 'job_details.php?id=' . (int)$job['id'],
        ]);
    }

    // E. الاختبار (Testing)
    if (isset($_POST['finish_testing'])) {
        $test_checks = $_POST['test_checks'] ?? [];
        $test_notes = trim((string)($_POST['test_notes'] ?? ''));
        $report_parts = [];
        if (is_array($test_checks) && !empty($test_checks)) {
            $report_parts[] = 'بنود الاختبار: ' . implode('، ', array_map(function ($item) {
                return trim((string)$item);
            }, $test_checks));
        }
        if ($test_notes !== '') {
            $report_parts[] = 'ملاحظات: ' . $test_notes;
        }
        if (!empty($report_parts)) {
            $safe_report = $conn->real_escape_string(implode(' | ', $report_parts));
            $conn->query("UPDATE job_orders SET notes = CONCAT(IFNULL(notes, ''), '\n[تقرير الاختبار]: $safe_report') WHERE id={$job['id']}");
            app_stage_data_set($conn, (int)$job['id'], 'testing', 'testing_report', implode(' | ', $report_parts), (int)($_SESSION['user_id'] ?? 0), (string)$user_name);
        }
        web_sync_rollup($conn, $job, (int)($_SESSION['user_id'] ?? 0), (string)$user_name);
        app_update_job_stage($conn, (int)$job['id'], 'launch');
        safe_redirect($job['id']);
    }

    // F. الإطلاق
    if (isset($_POST['finish_launch'])) {
        $chk = $conn->query("SELECT id FROM invoices WHERE job_id={$job['id']}");
        if($chk->num_rows == 0) {
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

    // G. الأرشيف وإعادة الفتح
    if (isset($_POST['archive_job'])) { 
        app_update_job_stage($conn, (int)$job['id'], 'completed', 'completed'); 
        safe_redirect($job['id']); 
    }
    if (isset($_POST['reopen_job'])) { 
        app_update_job_stage($conn, (int)$job['id'], $first_stage, 'processing'); 
        safe_redirect($job['id']); 
    }

    // خدمات عامة (تراجع)
    if (isset($_POST['return_stage'])) {
        $prev = trim((string)($_POST['prev_target'] ?? ''));
        if (!in_array($prev, $allowed_stage_keys, true)) {
            safe_redirect($job['id']);
        }
        $reason = $conn->real_escape_string($_POST['return_reason']);
        $note = "\n[تراجع]: $reason";
        app_update_job_stage_with_note($conn, (int)$job['id'], $prev, $note);
        safe_redirect($job['id']);
    }
}

// 4. خريطة المراحل
$curr = app_workflow_current_stage($workflow, (string)($job['current_stage'] ?? ''), $first_stage);
$prev_stage = $workflow[$curr]['prev'] ?? null;
$next_stage = $workflow[$curr]['next'] ?? null;
$curr_label = $workflow[$curr]['label'] ?? '';
$prev_label = ($prev_stage && isset($workflow[$prev_stage])) ? $workflow[$prev_stage]['label'] : 'لا يوجد';
$next_label = ($next_stage && isset($workflow[$next_stage])) ? $workflow[$next_stage]['label'] : 'لا يوجد';

// رابط العميل
$client_link = app_client_review_link($conn, $job);

// جلب جميع الملفات للشريط الجانبي
$all_files = [];
$jf = $conn->query("SELECT *, 'file' as origin FROM job_files WHERE job_id={$job['id']} ORDER BY id DESC");
if ($jf) {
    while ($row = $jf->fetch_assoc()) {
        $all_files[] = $row;
    }
}
$jp = $conn->query("SELECT *, 'proof' as origin, 'proof' as stage FROM job_proofs WHERE job_id={$job['id']} ORDER BY id DESC");
if ($jp) {
    while ($row = $jp->fetch_assoc()) {
        $all_files[] = $row;
    }
}
?>

<style>
    :root { --w-gold: #f39c12; --w-bg: #121212; --w-card: #1e1e1e; --w-blue: #3498db; --w-green: #2ecc71; --w-red: #e74c3c; }
    
    /* Responsive Split Layout */
    .split-layout { display: flex; gap: 20px; align-items: flex-start; }
    .sidebar { width: 320px; flex-shrink: 0; background: #151515; border: 1px solid #333; border-radius: 12px; padding: 20px; position: sticky; top: calc(var(--nav-total-height, 70px) + 20px); max-height: calc(100vh - var(--nav-total-height, 70px) - 40px); overflow-y: auto; }
    .main-content { flex: 1; min-width: 0; }
    
    /* Mobile Logic */
    @media (max-width: 900px) { 
        .split-layout { flex-direction: column; } 
        .sidebar { width: 100%; order: 2; position: static; max-height: none; } 
        .main-content { width: 100%; order: 1; margin-bottom: 20px; }
    }

    /* Sidebar Items */
    .info-block { margin-bottom: 20px; border-bottom: 1px dashed #333; padding-bottom: 15px; }
    .info-label { color: var(--w-gold); font-size: 0.85rem; font-weight: bold; margin-bottom: 5px; display: block; }
    .info-value { color: #ddd; font-size: 0.95rem; white-space: pre-wrap; line-height: 1.6; background: #0a0a0a; padding: 10px; border-radius: 6px; border: 1px solid #222; }

    /* File List in Sidebar */
    .file-item { display: flex; align-items: center; gap: 10px; background: #0a0a0a; padding: 8px; margin-bottom: 5px; border-radius: 6px; border: 1px solid #333; transition: 0.2s; }
    .file-item:hover { border-color: var(--w-gold); }
    .file-icon { font-size: 1.2rem; color: #777; }
    .file-link { flex: 1; color: #fff; text-decoration: none; font-size: 0.9rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .file-tag { font-size: 0.7rem; background: #333; padding: 2px 6px; border-radius: 4px; color: #aaa; }
    
    /* Delete Button */
    .delete-btn { background: none; border: none; color: var(--w-red); cursor: pointer; padding: 0 5px; font-size: 1.1rem; transition: 0.2s; }
    .delete-btn:hover { transform: scale(1.1); }

    /* Internal Comments */
    .comments-box { background: #000; padding: 10px; border-radius: 6px; max-height: 200px; overflow-y: auto; font-size: 0.85rem; border: 1px solid #333; margin-bottom: 10px; }
    .comment-input { width: 100%; background: #222; border: 1px solid #444; padding: 8px; color: #fff; border-radius: 4px; margin-bottom: 5px; }

    /* Admin Controls */
    .admin-controls { display: flex; gap: 5px; margin-top: 10px; background: #222; padding: 5px; border-radius: 5px; }

    /* General UI */
    .stage-header { display: flex; gap: 10px; overflow-x: auto; padding-bottom: 10px; margin-bottom: 20px; border-bottom: 1px solid #333; }
    .stage-summary { display:grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap:12px; margin-bottom:20px; }
    .stage-summary-item { background:#151515; border:1px solid #333; border-radius:10px; padding:14px; }
    .stage-summary-label { display:block; color:#8f8f8f; font-size:0.8rem; margin-bottom:6px; }
    .stage-summary-value { color:#f2f2f2; font-size:1rem; font-weight:700; }
    .step-badge { background: #333; color: #777; padding: 5px 15px; border-radius: 20px; white-space: nowrap; font-size: 0.8rem; }
    .step-badge.active { background: var(--w-gold); color: #000; font-weight: bold; }
    
    .main-card { background: var(--w-card); padding: 25px; border-radius: 12px; border: 1px solid #333; margin-bottom: 20px; }
    .card-title { color: var(--w-gold); margin: 0 0 15px 0; border-bottom: 1px dashed #444; padding-bottom: 10px; font-size: 1.2rem; }
    
    .btn { width: 100%; padding: 12px; border: none; border-radius: 5px; cursor: pointer; color: #fff; font-weight: bold; margin-top: 10px; }
    .btn-gold { background: var(--w-gold); color: #000; }
    .btn-blue { background: var(--w-blue); }
    .btn-green { background: var(--w-green); }
    .btn-red { background: var(--w-red); }
    .btn-gray { background: #444; }
    .btn-sm { padding: 5px 10px; font-size: 0.8rem; width: auto; margin-top: 0; }
    
    .tech-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 15px; margin-bottom: 20px; }
    .tech-item { background: #151515; padding: 15px; border-radius: 8px; text-align: center; border: 1px solid #333; }
    .tech-label { display: block; font-size: 0.75rem; color: #888; margin-bottom: 5px; }
    .tech-val { display: block; font-size: 1rem; color: #fff; font-weight: bold; }
    
    textarea, input[type="text"] { width: 100%; background: #151515; border: 1px solid #444; color: #fff; padding: 12px; border-radius: 6px; box-sizing: border-box; }
    
    .checklist-item { display: flex; align-items: center; gap: 10px; background: #000; padding: 10px; margin-bottom: 5px; border-radius: 5px; }
    input[type="checkbox"] { width: 20px; height: 20px; accent-color: var(--w-green); }
    
    .modal-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.8); align-items: center; justify-content: center; z-index: 1000; }
    .modal-box { background: #222; padding: 20px; width: min(350px, calc(100vw - 24px)); max-width: 350px; border-radius: 10px; border: 1px solid #555; text-align: center; }
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
        .sidebar,
        .stage-output-panel { padding: 12px; }
        .sidebar {
            padding: 0;
            overflow: hidden;
        }
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
        .step-badge {
            min-width: 0;
            text-align: center;
            padding: 8px 10px;
            white-space: normal;
            line-height: 1.35;
        }
        .step-badge.active { grid-column: 1 / -1; }
        .stage-summary { display: none; }
        .stage-summary-item {
            min-width: 0;
            padding: 10px 12px;
        }
        .stage-summary-label { font-size: 0.68rem; }
        .stage-summary-value { font-size: 0.88rem; }
        .tech-grid { grid-template-columns: 1fr; }
        .checklist-item { align-items: flex-start; }
        .stage-header { gap: 6px; margin-bottom: 14px; }
        .step-badge { padding: 6px 10px; font-size: 0.72rem; }
        .file-item { flex-direction: column; align-items: flex-start; }
        .file-item form,
        .file-item .file-link,
        .file-item .file-tag { width: 100%; }
        .card-title { font-size: 1.02rem; margin-bottom: 12px; }
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
        const title = titleEl ? titleEl.textContent.trim() : 'ملف المشروع';
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
        <h3 style="color:#fff; border-bottom:2px solid var(--w-gold); padding-bottom:10px; margin-top:0;">ملف المشروع</h3>
        
        <div class="info-block">
            <span class="info-label">البيانات الأساسية:</span>
            <div class="info-value">
                <strong>المشروع:</strong> <?php echo $job['job_name']; ?><br>
                <strong>النوع:</strong> <?php echo $specs['type']; ?><br>
                <strong>الثيم:</strong> <?php echo $specs['theme']; ?>
            </div>
        </div>

        <div class="info-block">
            <span class="info-label">المناقشات الداخلية:</span>
            <div class="comments-box">
                <?php echo nl2br($job['notes'] ?? 'لا توجد ملاحظات'); ?>
            </div>
            <form method="POST">
                <input type="text" name="comment_text" class="comment-input" placeholder="اكتب ملاحظة..." required>
                <button type="submit" name="add_internal_comment" class="btn btn-gray btn-sm" style="width:100%;">إرسال تعليق</button>
            </form>
        </div>

        <div class="info-block" style="border:none;">
            <span class="info-label">الأرشيف والمرفقات:</span>
            <?php if(!empty($all_files)): ?>
                <?php foreach($all_files as $f): 
                    $ext = pathinfo($f['file_path'], PATHINFO_EXTENSION);
                    $icon = in_array(strtolower($ext), ['jpg','png','jpeg','webp']) ? 'IMG' : 'FILE';
                ?>
                <div class="file-item">
                    <span class="file-icon"><?php echo $icon; ?></span>
                    <a href="<?php echo $f['file_path']; ?>" target="_blank" class="file-link"><?php echo $f['description'] ?: basename($f['file_path']); ?></a>
                    <span class="file-tag"><?php echo $f['stage']; ?></span>
                    <form method="POST" style="margin:0;">
                        <input type="hidden" name="type" value="file">
                        <input type="hidden" name="item_id" value="<?php echo $f['id']; ?>">
                        <input type="hidden" name="__async_form" value="1"><button name="delete_item" class="delete-btn" onclick="return confirm('حذف الملف نهائياً من السيرفر؟')">×</button>
                    </form>
                </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div style="color:#666; font-size:0.9rem; text-align:center;">لا توجد مرفقات</div>
            <?php endif; ?>
        </div>

        <?php if($can_force_stage): ?>
            <div class="info-block" style="border-top:1px dashed #333; padding-top:15px;">
                <span class="info-label">تحكم إداري:</span>
                <div class="admin-controls">
                    <?php if($prev_stage): ?>
                    <form method="POST" style="flex:1;"><input type="hidden" name="target_stage" value="<?php echo $prev_stage; ?>"><button name="force_stage_change" class="btn btn-red btn-sm" style="width:100%;">« تراجع جبري</button></form>
                    <?php endif; ?>
                    <?php if($next_stage): ?>
                    <form method="POST" style="flex:1;"><input type="hidden" name="target_stage" value="<?php echo $next_stage; ?>"><button name="force_stage_change" class="btn btn-gold btn-sm" style="width:100%;">تمرير جبري »</button></form>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <div class="main-content">
        
        <div class="stage-header">
            <?php foreach($workflow as $key => $stageData): ?>
                <div class="step-badge <?php echo ($key == $curr) ? 'active' : ''; ?>"><?php echo $stageData['label']; ?></div>
            <?php endforeach; ?>
        </div>
        <div class="stage-summary">
            <div class="stage-summary-item">
                <span class="stage-summary-label">المرحلة الحالية</span>
                <span class="stage-summary-value"><?php echo app_h($curr_label); ?></span>
            </div>
            <div class="stage-summary-item">
                <span class="stage-summary-label">المرحلة السابقة</span>
                <span class="stage-summary-value"><?php echo app_h($prev_label); ?></span>
            </div>
            <div class="stage-summary-item">
                <span class="stage-summary-label">المرحلة التالية</span>
                <span class="stage-summary-value"><?php echo app_h($next_label); ?></span>
            </div>
        </div>

        <div class="main-card" style="border-top: 3px solid var(--w-gold);">
            <h3 class="card-title"><?php echo $job['job_name']; ?></h3>
            <div class="tech-grid">
                <div class="tech-item"><span class="tech-label">نوع المشروع</span><span class="tech-val"><?php echo $specs['type']; ?></span></div>
                <div class="tech-item"><span class="tech-label">النطاق (Domain)</span><a href="https://<?php echo $specs['domain']; ?>" target="_blank" class="tech-val" style="color:var(--w-blue); text-decoration:none;"><?php echo $specs['domain']; ?></a></div>
                <div class="tech-item"><span class="tech-label">الاستضافة</span><span class="tech-val"><?php echo $specs['hosting']; ?></span></div>
            </div>
        </div>

        <?php if($curr == 'briefing'): ?>
        <div class="main-card">
            <h3 class="card-title">تحليل المتطلبات (Requirements)</h3>
            <div class="app-ai-panel" data-job-id="<?php echo (int)$job['id']; ?>" data-csrf="<?php echo app_h(app_csrf_token()); ?>" data-context="web_requirements_plan" data-item-count="1" data-target-selector="textarea[name='requirements']" data-apply-mode="fill-single" style="margin-bottom:15px;">
                <div class="app-ai-head">
                    <div class="app-ai-title">مساعد AI للخطة</div>
                    <div class="app-ai-note">مسودة سريعة للمتطلبات والصفحات والمكونات</div>
                </div>
                <textarea class="app-ai-seed" placeholder="أدخل وصف المشروع أو المجال أو أي ملاحظات أولية..."><?php echo app_h($current_requirements); ?></textarea>
                <div class="app-ai-actions">
                    <button type="button" class="app-ai-btn app-ai-btn-primary app-ai-generate">توليد خطة المتطلبات</button>
                </div>
                <div class="app-ai-status"></div>
                <div class="app-ai-results"></div>
            </div>
            <form method="POST" enctype="multipart/form-data" class="op-async-form" data-upload-progress="1">
                <input type="hidden" name="__async_form" value="1">
                <label style="color:#aaa;">المتطلبات التقنية والمميزات:</label>
                <textarea name="requirements" rows="6" placeholder="اكتب قائمة المميزات، الصفحات المطلوبة، اللغات، طرق الدفع..."><?php echo app_h($current_requirements); ?></textarea>
                
                <div style="margin-top:15px;">
                    <label style="color:#aaa;">ملفات التخطيط (Sitemap / Wireframes):</label>
                    <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap; margin-top:5px;">
                        <input type="file" name="doc_files[]" multiple style="color:#fff; flex:1 1 320px; display:block;">
                        <button type="submit" name="upload_brief_files" class="btn btn-gray" style="width:auto; white-space:nowrap;">رفع الملفات</button>
                    </div>
                </div>
                
                <button type="submit" name="save_brief" class="btn btn-gold">حفظ وبدء التصميم (UI)</button>
                <div class="op-form-status" style="display:none; margin-top:10px; font-size:0.9rem;"></div>
                <div class="op-form-progress" style="display:none; margin-top:10px;"><div style="height:8px; background:#222; border-radius:999px; overflow:hidden;"><div class="op-form-progress-bar" style="width:0%; height:8px; background:linear-gradient(90deg,#c79c2f,#f4d269);"></div></div><div class="op-form-progress-text" style="margin-top:6px; color:#aaa; font-size:0.85rem;">جاري الرفع...</div></div>
            </form>
        </div>
        <?php endif; ?>

        <?php if($curr == 'ui_design'): ?>
        <div class="main-card">
            <h3 class="card-title">تصميم الواجهة</h3>
            <form method="POST" enctype="multipart/form-data" class="op-async-form" data-upload-progress="1">
                <input type="hidden" name="__async_form" value="1">
                <label style="color:#aaa;">رفع شاشات التصميم (XD/Figma/Images):</label>
                <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap; margin-top:10px; margin-bottom:10px;">
                    <input type="file" name="ui_files[]" multiple style="color:#fff; flex:1 1 320px; display:block;">
                    <button type="submit" name="action_ui" value="upload" class="btn btn-gray" style="width:auto; white-space:nowrap;">رفع الملفات</button>
                </div>
                
                <div style="display:flex; gap:10px;">
                    <button type="submit" name="action_ui" value="send_review" class="btn btn-gold">رفع وإرسال للمراجعة</button>
                </div>
                <div class="op-form-status" style="display:none; margin-top:10px; font-size:0.9rem;"></div>
                <div class="op-form-progress" style="display:none; margin-top:10px;"><div style="height:8px; background:#222; border-radius:999px; overflow:hidden;"><div class="op-form-progress-bar" style="width:0%; height:8px; background:linear-gradient(90deg,#c79c2f,#f4d269);"></div></div><div class="op-form-progress-text" style="margin-top:6px; color:#aaa; font-size:0.85rem;">جاري الرفع...</div></div>
            </form>
            
            <h4 style="color:#fff; margin-top:20px;">البروفات الحالية:</h4>
            <div style="display:flex; gap:10px; flex-wrap:wrap; margin-top:10px;">
                <?php 
                $proofs = $conn->query("SELECT * FROM job_proofs WHERE job_id={$job['id']}");
                while($p = $proofs->fetch_assoc()): ?>
                    <div style="text-align:center;">
                        <a href="<?php echo $p['file_path']; ?>" target="_blank">
                            <img src="<?php echo $p['file_path']; ?>" style="width:100px; height:100px; object-fit:cover; border:1px solid #444; border-radius:5px;">
                        </a>
                        <form method="POST" onsubmit="return confirm('حذف التصميم؟');" style="margin-top:5px;">
                            <input type="hidden" name="type" value="proof">
                            <input type="hidden" name="item_id" value="<?php echo $p['id']; ?>">
                            <input type="hidden" name="__async_form" value="1"><button name="delete_item" style="background:none; border:none; color:var(--w-red); cursor:pointer;">حذف</button>
                        </form>
                    </div>
                <?php endwhile; ?>
            </div>
        </div>
        <?php endif; ?>

        <?php if($curr == 'client_rev'): ?>
        <div class="main-card" style="text-align:center;">
            <h3 class="card-title">انتظار مراجعة العميل</h3>
            <p style="color:#aaa;">العميل يراجع التصميمات حالياً.</p>
            <div style="background:#000; padding:10px; border-radius:5px; margin-bottom:15px;">
                <input type="text" value="<?php echo $client_link; ?>" readonly style="width:100%; background:#000; color:var(--w-green); text-align:center; border:none;">
            </div>
            <a href="https://wa.me/<?php echo $job['client_phone']; ?>?text=<?php echo urlencode("يرجى مراجعة تصميم الموقع:\n$client_link"); ?>" target="_blank" class="btn btn-green" style="display:inline-block; width:auto; text-decoration:none;">إرسال واتساب</a>
            
            <form method="POST" style="margin-top:20px;">
                <button name="approve_ui" class="btn btn-gold">تم الاعتماد (تخطي يدوي للبرمجة)</button>
            </form>
        </div>
        <?php endif; ?>

        <?php if($curr == 'development'): ?>
        <div class="main-card">
            <h3 class="card-title">مرحلة التطوير</h3>
            <div class="app-ai-panel" data-job-id="<?php echo (int)$job['id']; ?>" data-csrf="<?php echo app_h(app_csrf_token()); ?>" data-context="web_development_plan" data-item-count="1" data-target-selector="textarea[name='dev_notes']" data-apply-mode="fill-single" style="margin-bottom:15px;">
                <div class="app-ai-head">
                    <div class="app-ai-title">مساعد AI لخطة التطوير</div>
                    <div class="app-ai-note">يقترح تقسيم التنفيذ والاختبار والتسليم</div>
                </div>
                <textarea class="app-ai-seed" placeholder="أدخل ما تم أو ما يجب تنفيذه في مرحلة التطوير..."><?php echo app_h($current_dev_notes); ?></textarea>
                <div class="app-ai-actions">
                    <button type="button" class="app-ai-btn app-ai-btn-primary app-ai-generate">توليد خطة تطوير</button>
                </div>
                <div class="app-ai-status"></div>
                <div class="app-ai-results"></div>
            </div>
            <form method="POST" class="op-async-form" data-upload-progress="0">
                <input type="hidden" name="__async_form" value="1">
                <label style="color:#aaa;">رابط بيئة التطوير (Staging URL):</label>
                <input type="text" name="dev_url" placeholder="http://dev.yoursite.com" style="direction:ltr;" value="<?php echo app_h($current_dev_url); ?>">
                
                <label style="color:#aaa; margin-top:10px; display:block;">ملاحظات / بيانات دخول لوحة التحكم:</label>
                <textarea name="dev_notes" rows="3" placeholder="Admin URL / Username / Password..."><?php echo app_h($current_dev_notes); ?></textarea>
                
                <div style="display:flex; gap:10px; margin-top:15px;">
                    <button type="submit" name="save_dev_progress" value="save" class="btn btn-gray">حفظ التقدم</button>
                    <button type="submit" name="save_dev_progress" value="finish" class="btn btn-gold">انتهاء التكويد (للاختبار)</button>
                </div>
                <div class="op-form-status" style="display:none; margin-top:10px; font-size:0.9rem;"></div>
            </form>
        </div>
        <?php endif; ?>

        <?php if($curr == 'testing'): ?>
        <div class="main-card">
            <h3 class="card-title">اختبار الجودة</h3>
            <form method="POST">
                <div style="background:#111; padding:15px; border-radius:8px; margin-bottom:15px;">
                    <label class="checklist-item"><input type="checkbox" name="test_checks[]" value="التوافق مع الجوال"> <span>التوافق مع الجوال</span></label>
                    <label class="checklist-item"><input type="checkbox" name="test_checks[]" value="سرعة التحميل"> <span>سرعة التحميل</span></label>
                    <label class="checklist-item"><input type="checkbox" name="test_checks[]" value="عمل النماذج"> <span>عمل النماذج</span></label>
                </div>
                <textarea name="test_notes" rows="3" placeholder="ملاحظات الاختبار النهائية"><?php echo app_h($current_testing_report); ?></textarea>
                <button name="finish_testing" class="btn btn-gold">الموقع جاهز للإطلاق</button>
            </form>
        </div>
        <?php endif; ?>

        <?php if($curr == 'launch'): ?>
        <div class="main-card">
            <h3 class="card-title">الإطلاق الرسمي</h3>
            <p style="color:#aaa;">تأكد من ربط الدومين الأساسي ونقل الملفات للسيرفر الحي.</p>
            
            <div style="text-align:center; margin:20px 0;">
                <a href="https://<?php echo $specs['domain']; ?>" target="_blank" class="btn btn-blue" style="text-decoration:none; display:inline-block; width:auto; padding:15px 40px;">زيارة الموقع الحي</a>
            </div>
            
            <form method="POST">
                <button name="finish_launch" class="btn btn-gold">تأكيد التسليم والتحويل للحسابات</button>
            </form>
        </div>
        <?php endif; ?>

        <?php if(in_array($curr, ['accounting', 'completed'])): ?>
        <div class="main-card" style="text-align:center;">
            <h2 style="color:var(--w-green);">تم تسليم المشروع بنجاح</h2>
            <?php if($curr == 'accounting'): ?>
                <a href="invoices.php?tab=sales" class="btn btn-gray" style="display:inline-block; width:auto;">الملف المالي</a>
                <form method="POST" style="margin-top:15px;">
                    <button name="archive_job" class="btn btn-gold" style="width:auto;">أرشفة نهائية</button>
                </form>
            <?php else: ?>
                <p style="color:#aaa;">المشروع في الأرشيف.</p>
                <form method="POST" onsubmit="return confirm('تأكيد إعادة الفتح؟');" style="margin-top:20px;">
                    <button name="reopen_job" class="btn btn-red" style="width:auto;">إعادة فتح المشروع</button>
                </form>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if($prev_stage && !in_array($curr, ['completed'])): ?>
        <div style="text-align:right; margin-top:20px;">
            <button onclick="document.getElementById('returnModal').style.display='flex'" class="btn btn-red" style="width:auto; padding:8px 20px; font-size:0.8rem;">تراجع للمرحلة السابقة</button>
        </div>
        <?php endif; ?>

    </div>

</div>
<script>
    (function() {
        const forms = document.querySelectorAll('.op-async-form');
        if (!forms.length) return;
        forms.forEach(function(form) {
            form.addEventListener('submit', function(evt) {
                evt.preventDefault();
                const submitter = evt.submitter || document.activeElement;
                const data = new FormData(form);
                const statusBox = form.querySelector('.op-form-status');
                const progressWrap = form.querySelector('.op-form-progress');
                const progressBar = form.querySelector('.op-form-progress-bar');
                const progressText = form.querySelector('.op-form-progress-text');
                const trackProgress = form.getAttribute('data-upload-progress') === '1';
                if (submitter && submitter.name) {
                    data.set(submitter.name, submitter.value || '1');
                }
                if (statusBox) {
                    statusBox.style.display = 'block';
                    statusBox.style.color = '#aaa';
                    statusBox.textContent = 'جاري التنفيذ...';
                }
                if (progressWrap) progressWrap.style.display = trackProgress ? 'block' : 'none';
                if (progressBar) progressBar.style.width = '0%';
                if (submitter) submitter.disabled = true;
                const xhr = new XMLHttpRequest();
                xhr.open('POST', form.getAttribute('action') || window.location.href, true);
                xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
                xhr.upload.addEventListener('progress', function(e) {
                    if (!trackProgress || !e.lengthComputable) return;
                    const percent = Math.max(0, Math.min(100, Math.round((e.loaded / e.total) * 100)));
                    if (progressBar) progressBar.style.width = percent + '%';
                    if (progressText) progressText.textContent = 'جاري الرفع... ' + percent + '%';
                });
                xhr.onreadystatechange = function() {
                    if (xhr.readyState !== 4) return;
                    if (submitter) submitter.disabled = false;
                    let payload = null;
                    try { payload = JSON.parse(xhr.responseText || '{}'); } catch (err) { payload = null; }
                    if (xhr.status >= 200 && xhr.status < 300 && payload && payload.ok) {
                        if (statusBox) {
                            statusBox.style.color = '#9fd6a8';
                            statusBox.textContent = payload.message || 'تم التنفيذ بنجاح.';
                        }
                        if (progressBar) progressBar.style.width = '100%';
                        if (payload.reload) {
                            window.setTimeout(function() {
                                window.location.href = payload.redirect || window.location.href;
                            }, 400);
                        }
                        return;
                    }
                    if (statusBox) {
                        statusBox.style.color = '#d98c8c';
                        statusBox.textContent = (payload && payload.error) ? payload.error : 'تعذر تنفيذ العملية. أعد المحاولة.';
                    }
                    if (progressWrap) progressWrap.style.display = 'none';
                };
                xhr.send(data);
            });
        });
    })();
</script>

<div id="returnModal" class="modal-overlay">
    <div class="modal-box">
        <h3 style="color:var(--w-red); margin-top:0;">تأكيد التراجع</h3>
        <form method="POST">
            <input type="hidden" name="prev_target" value="<?php echo $prev_stage; ?>">
            <textarea name="return_reason" required placeholder="سبب التراجع..." style="width:100%; height:80px; background:#000; color:#fff; border:1px solid #555; margin-bottom:10px;"></textarea>
            <div style="display:flex; gap:10px;">
                <button name="return_stage" class="btn btn-red" style="flex:1;">تأكيد</button>
                <button type="button" onclick="document.getElementById('returnModal').style.display='none'" class="btn btn-gray" style="flex:1;">إلغاء</button>
            </div>
        </form>
    </div>
</div>
