<?php
// print.php - (Royal Ops Room V38.4 - Token Auto-Fix)

// 1. إعدادات السيرفر القصوى (للملفات الكبيرة)
@ini_set('upload_max_filesize', '2048M'); // 2GB
@ini_set('post_max_size', '2048M');
@ini_set('max_execution_time', '3600'); // ساعة كاملة
@ini_set('max_input_time', '3600'); // مهم جداً للرفع البطيء
@ini_set('memory_limit', '2048M');

function print_is_ajax_request(): bool {
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

function print_finish_request(int $jobId, array $payload = []): void {
    if (print_is_ajax_request()) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
    header('Location: job_details.php?id=' . $jobId);
    exit;
}

// ============================================================
// 🌟 معالجة الرفع السريع (AJAX) - للملفات العامة
// ============================================================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['ajax_action']) && $_POST['ajax_action'] == 'universal_upload') {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    require_once __DIR__ . '/../auth.php';

    $response = ['status' => 'error', 'msg' => 'حدث خطأ غير معروف'];

    try {
        if (empty($_SESSION['user_id'])) {
            http_response_code(403);
            throw new Exception('Unauthorized');
        }
        if (!app_verify_csrf($_POST['_csrf_token'] ?? '')) {
            http_response_code(403);
            throw new Exception('Invalid CSRF token');
        }
        if (empty($_FILES['ajax_files']['name'][0])) throw new Exception("لم يتم استلام ملفات");

        $job_id = intval($_POST['job_id']);
        $stage = trim((string)($_POST['stage'] ?? ''));
        $uploader = $_SESSION['name'] ?? 'System';
        $desc_base = $_POST['file_desc'] ?? 'ملف';

        $folder_map = [
            'briefing' => 'uploads/briefs',
            'design' => 'uploads/proofs',
            'materials' => 'uploads/materials',
            'pre_press_supplies' => 'uploads/source',
            'pre_press' => 'uploads/production',
            'printing' => 'uploads/production'
        ];
        if (!isset($folder_map[$stage])) {
            throw new Exception('مرحلة رفع غير صالحة');
        }

        $target_dir = $folder_map[$stage];
        if (!file_exists($target_dir)) @mkdir($target_dir, 0777, true);

        $success_count = 0;
        $errors = [];

        foreach ($_FILES['ajax_files']['name'] as $i => $name) {
            if ($_FILES['ajax_files']['error'][$i] === 0) {
                if (app_is_blocked_upload_extension($name)) {
                    $errors[] = "نوع ملف غير مسموح: $name";
                    continue;
                }
                $file_desc = (count($_FILES['ajax_files']['name']) > 1) ? "$desc_base (" . ($i+1) . ")" : $desc_base;
                $file_desc_sql = $conn->real_escape_string($file_desc);
                $uploader_sql = $conn->real_escape_string((string)$uploader);

                $stored = app_store_uploaded_file([
                    'name' => $_FILES['ajax_files']['name'][$i] ?? '',
                    'type' => $_FILES['ajax_files']['type'][$i] ?? '',
                    'tmp_name' => $_FILES['ajax_files']['tmp_name'][$i] ?? '',
                    'error' => $_FILES['ajax_files']['error'][$i] ?? UPLOAD_ERR_NO_FILE,
                    'size' => $_FILES['ajax_files']['size'][$i] ?? 0,
                ], [
                    'dir' => $target_dir,
                    'prefix' => (string)$job_id . '_',
                    'max_size' => 2048 * 1024 * 1024,
                ]);

                if (!empty($stored['ok'])) {
                    $target_file = (string)$stored['path'];
                    if ($stage == 'design') {
                        $conn->query("INSERT INTO job_proofs (job_id, file_path, description, status, client_comment) VALUES ($job_id, '$target_file', '$file_desc_sql', 'pending', NULL)");
                    } else {
                        $conn->query("INSERT INTO job_files (job_id, file_path, stage, description, uploaded_by) VALUES ($job_id, '$target_file', '$stage', '$file_desc_sql', '$uploader_sql')");
                    }
                    $success_count++;
                } else {
                    $errors[] = "فشل نقل: $name";
                }
            } else {
                $errors[] = "خطأ سيرفر رقم: " . $_FILES['ajax_files']['error'][$i];
            }
        }

        if ($success_count > 0) {
            $msg = "تم رفع $success_count ملف بنجاح";
            if (!empty($errors)) {
                $msg .= ' | أخطاء: ' . implode(' | ', $errors);
            }
            $response = ['status' => 'success', 'msg' => $msg];
        }
        else $response = ['status' => 'error', 'msg' => implode(" | ", $errors)];

    } catch (Exception $e) {
        $response = ['status' => 'error', 'msg' => $e->getMessage()];
    }
    echo json_encode($response);
    exit;
}

// ============================================================
// الصفحة الرئيسية
// ============================================================
$print_module_initial_buffer_level = ob_get_level();
ob_start();
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/modules/job_invoice_runtime.php';
$app_module_embedded = !empty($app_module_embedded);

$can_force_stage = app_user_can('jobs.manage_all');
$can_design_stage = app_user_can_any(['jobs.manage_all', 'jobs.edit_assigned']);
$is_financial = app_user_can_any(['finance.view', 'invoices.view']);

$fallbackWorkflowLabels = [
    'briefing'   => '1. التجهيز',
    'design'     => '2. التصميم',
    'client_rev' => '3. المراجعة',
    'materials'  => '4. الخامات',
    'pre_press'  => '5. التجهيز (CTP)',
    'printing'   => '6. الطباعة',
    'finishing'  => '7. التشطيب',
    'delivery'   => '8. التسليم',
    'accounting' => '9. الحسابات',
    'completed'  => '10. الأرشيف',
];
$workflow = app_operation_workflow($conn, 'print', $fallbackWorkflowLabels);
$first_stage = (string)array_key_first($workflow);
if ($first_stage === '') {
    $first_stage = 'briefing';
}

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$job_query = $conn->query("SELECT j.*, c.name as client_name, c.phone as client_phone FROM job_orders j JOIN clients c ON j.client_id = c.id WHERE j.id = $id");
if ($job_query->num_rows == 0) die("أمر الشغل غير موجود");
$job = $job_query->fetch_assoc();
$curr = app_workflow_current_stage($workflow, (string)($job['current_stage'] ?? ''), $first_stage);
$prev_stage = $workflow[$curr]['prev'] ?? null;

// دوال المساعدة
function get_wa_link($phone, $text) {
    $phone = preg_replace('/[^0-9]/', '', $phone);
    if (strlen($phone) == 11 && substr($phone, 0, 2) == '01') $phone = '2' . $phone;
    elseif (strlen($phone) == 10 && substr($phone, 0, 2) == '05') $phone = '966' . substr($phone, 1);
    return "https://wa.me/$phone?text=" . urlencode($text);
}

function print_count_job_files_by_stage(mysqli $conn, int $jobId, string $stage): int {
    $safeStage = $conn->real_escape_string($stage);
    $row = $conn->query("SELECT COUNT(*) AS cnt FROM job_files WHERE job_id={$jobId} AND stage='{$safeStage}'")->fetch_assoc();
    return (int)($row['cnt'] ?? 0);
}

function print_proofs_count(mysqli $conn, int $jobId): int {
    $row = $conn->query("SELECT COUNT(*) AS cnt FROM job_proofs WHERE job_id={$jobId}")->fetch_assoc();
    return (int)($row['cnt'] ?? 0);
}

function print_stage_descriptions(mysqli $conn, int $jobId, string $stage): string {
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

function print_sync_rollup(mysqli $conn, int $jobId, int $userId, string $userName): void {
    $briefingSummary = app_stage_data_get($conn, $jobId, 'briefing', 'briefing_summary', '');
    $materialsSummary = print_stage_descriptions($conn, $jobId, 'materials');
    $prepressFilesCount = (string)(print_count_job_files_by_stage($conn, $jobId, 'pre_press') + print_count_job_files_by_stage($conn, $jobId, 'pre_press_supplies'));
    $proofsCount = (string)print_proofs_count($conn, $jobId);
    $materialsCount = (string)print_count_job_files_by_stage($conn, $jobId, 'materials');
    $printSpecsSummary = app_stage_data_get($conn, $jobId, 'printing', 'print_specs_summary', '');
    $finishingSummary = app_stage_data_get($conn, $jobId, 'finishing', 'production_summary', '');

    foreach (['briefing', 'design', 'client_rev', 'materials', 'pre_press', 'printing', 'finishing', 'delivery', 'accounting', 'completed'] as $stageKey) {
        app_stage_data_set($conn, $jobId, $stageKey, 'briefing_summary', $briefingSummary, $userId, $userName);
    }
    foreach (['design', 'client_rev', 'materials', 'pre_press', 'printing', 'finishing', 'delivery', 'accounting', 'completed'] as $stageKey) {
        app_stage_data_set($conn, $jobId, $stageKey, 'proofs_count', $proofsCount, $userId, $userName);
    }
    foreach (['materials', 'pre_press', 'printing', 'finishing', 'delivery', 'accounting', 'completed'] as $stageKey) {
        app_stage_data_set($conn, $jobId, $stageKey, 'materials_summary', $materialsSummary, $userId, $userName);
        app_stage_data_set($conn, $jobId, $stageKey, 'materials_count', $materialsCount, $userId, $userName);
    }
    foreach (['pre_press', 'printing', 'finishing', 'delivery', 'accounting', 'completed'] as $stageKey) {
        app_stage_data_set($conn, $jobId, $stageKey, 'prepress_files_count', $prepressFilesCount, $userId, $userName);
    }
    foreach (['printing', 'finishing', 'delivery', 'accounting', 'completed'] as $stageKey) {
        app_stage_data_set($conn, $jobId, $stageKey, 'print_specs_summary', $printSpecsSummary, $userId, $userName);
    }
    foreach (['finishing', 'delivery', 'accounting', 'completed'] as $stageKey) {
        app_stage_data_set($conn, $jobId, $stageKey, 'production_summary', $finishingSummary, $userId, $userName);
    }
}

// استخراج المواصفات
$raw_text = $job['job_details'] ?? '';
function get_spec($pattern, $text, $default = '') {
    preg_match($pattern, $text, $matches);
    return isset($matches[1]) ? trim($matches[1]) : $default;
}
$specs = [
    'p_len' => get_spec('/مقاس الورق:.*?([\d\.]+)\s*x/u', $raw_text, '0'),
    'p_wid' => get_spec('/مقاس الورق:.*?[\d\.]+\s*x\s*([\d\.]+)/u', $raw_text, '0'),
    'c_len' => get_spec('/مقاس القص:.*?([\d\.]+)\s*x/u', $raw_text, '0'),
    'c_wid' => get_spec('/مقاس القص:.*?[\d\.]+\s*x\s*([\d\.]+)/u', $raw_text, '0'),
    'machine' => get_spec('/الماكينة: (.*?)(?:\||$)/u', $raw_text, ''),
    'print_face' => get_spec('/الوجه: (.*?)(?:\||$)/u', $raw_text, ''),
    'colors' => get_spec('/الألوان: (.*?)(?:\||$)/u', $raw_text, ''),
    'zinc' => get_spec('/الزنكات: ([\d\.]+)/u', $raw_text, '0'),
];

$history_notes = [];
preg_match_all('/\[(.*?)\]:\s*(.*?)(?=\n\[|$)/s', $job['notes'] ?? '', $matches, PREG_SET_ORDER);
$history_notes = $matches;

// 🛠️ معالجة الطلبات (POST)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && !isset($_POST['ajax_action'])) {
    if (!app_verify_csrf($_POST['_csrf_token'] ?? '')) {
        http_response_code(403);
        die('Invalid CSRF token');
    }
    
    function safe_redirect($id) {
        if (!headers_sent()) {
            header('Location: job_details.php?id=' . (int)$id);
            exit;
        }
        echo "<script>window.location.href = 'job_details.php?id=" . (int)$id . "';</script>";
        exit;
    }

    // 1. التحكم الجبري
    if (isset($_POST['admin_change_stage']) && $can_force_stage) {
        $new_stage = trim((string)($_POST['target_stage'] ?? ''));
        if (array_key_exists($new_stage, $workflow)) {
            app_update_job_stage($conn, (int)$id, $new_stage);
        }
        print_finish_request($id, [
            'ok' => true,
            'message' => 'تم تحديث المرحلة بنجاح.',
            'redirect' => 'job_details.php?id=' . $id,
        ]);
    }

    // 2. حذف الملفات
    if (isset($_POST['delete_item'])) {
        $itemType = trim((string)($_POST['type'] ?? ''));
        $tbl = ($itemType === 'proof') ? 'job_proofs' : 'job_files';
        $item_id = intval($_POST['item_id']);
        $f = $conn->query("SELECT file_path FROM $tbl WHERE id=$item_id AND job_id=$id LIMIT 1")->fetch_assoc();
        if ($f && !empty($f['file_path'])) {
            $abs_path = __DIR__ . '/' . $f['file_path'];
            if (!app_safe_unlink($abs_path, __DIR__ . '/../uploads')) {
                app_safe_unlink((string)$f['file_path'], __DIR__ . '/../uploads');
            }
        }
        $conn->query("DELETE FROM $tbl WHERE id=$item_id AND job_id=$id");
        print_sync_rollup($conn, (int)$id, (int)($_SESSION['user_id'] ?? 0), (string)($_SESSION['name'] ?? 'System'));
        print_finish_request($id, [
            'ok' => true,
            'message' => 'تم حذف العنصر بنجاح.',
            'redirect' => 'job_details.php?id=' . $id,
        ]);
    }

    // 3. التجهيز
    if (isset($_POST['save_brief_notes'])) {
        $note = $conn->real_escape_string($_POST['notes']);
        app_stage_data_set($conn, (int)$id, 'briefing', 'briefing_summary', (string)($_POST['notes'] ?? ''), (int)($_SESSION['user_id'] ?? 0), (string)($_SESSION['name'] ?? 'System'));
        print_sync_rollup($conn, (int)$id, (int)($_SESSION['user_id'] ?? 0), (string)($_SESSION['name'] ?? 'System'));
        app_update_job_stage_with_note($conn, (int)$id, 'design', "\n[تجهيز]: $note");
        safe_redirect($id);
    }

    // 4. التصميم والمراجعة
    if (isset($_POST['send_to_review'])) {
        $proofsCountRes = $conn->query("SELECT COUNT(*) AS cnt FROM job_proofs WHERE job_id=$id");
        $proofsCount = (int)(($proofsCountRes ? ($proofsCountRes->fetch_assoc()['cnt'] ?? 0) : 0));
        if ($proofsCount <= 0) {
            safe_redirect($id);
        }
        print_sync_rollup($conn, (int)$id, (int)($_SESSION['user_id'] ?? 0), (string)($_SESSION['name'] ?? 'System'));
        app_update_job_stage($conn, (int)$id, 'client_rev');
        safe_redirect($id);
    }
    if (isset($_POST['finalize_review'])) {
        app_update_job_stage($conn, (int)$id, 'materials');
        safe_redirect($id);
    }
    if (isset($_POST['return_stage'])) {
        $prev = trim((string)($_POST['prev_target'] ?? ''));
        if (!array_key_exists($prev, $workflow)) {
            safe_redirect($id);
        }
        $reason = $conn->real_escape_string($_POST['return_reason']);
        $note = "\n[تراجع]: $reason";
        app_update_job_stage_with_note($conn, (int)$id, $prev, $note);
        safe_redirect($id);
    }

    // 5. الخامات
    if (isset($_POST['save_materials']) || isset($_POST['finish_materials'])) {
        $items = $_POST['item_text'] ?? [];
        $suppliers = $_POST['supplier_phone'] ?? [];
        if (!file_exists('uploads/materials')) @mkdir('uploads/materials', 0777, true);
        if (is_array($items)) {
            foreach ($items as $i => $text) {
                if (!empty($text)) {
                    $file_link = '';
                    if (!empty($_FILES['item_file']['name'][$i])) {
                        $stored = app_store_uploaded_file([
                            'name' => $_FILES['item_file']['name'][$i] ?? '',
                            'type' => $_FILES['item_file']['type'][$i] ?? '',
                            'tmp_name' => $_FILES['item_file']['tmp_name'][$i] ?? '',
                            'error' => $_FILES['item_file']['error'][$i] ?? UPLOAD_ERR_NO_FILE,
                            'size' => $_FILES['item_file']['size'][$i] ?? 0,
                        ], [
                            'dir' => 'uploads/materials',
                            'prefix' => 'mat_' . $i . '_',
                            'max_size' => 2048 * 1024 * 1024,
                        ]);
                        if (!empty($stored['ok'])) { $file_link = (string)$stored['path']; }
                    }
                    $desc = $conn->real_escape_string($text);
                    $supp_phone = $conn->real_escape_string($suppliers[$i] ?? '');
                    $conn->query("INSERT INTO job_files (job_id, file_path, stage, description, uploaded_by) VALUES ($id, '$file_link', 'materials', '$desc', '$supp_phone')");
                }
            }
        }
        if (isset($_POST['finish_materials'])) {
            $materialsCountRes = $conn->query("SELECT COUNT(*) AS cnt FROM job_files WHERE job_id=$id AND stage='materials'");
            $materialsCount = (int)(($materialsCountRes ? ($materialsCountRes->fetch_assoc()['cnt'] ?? 0) : 0));
            if ($materialsCount <= 0) {
                safe_redirect($id);
            }
            app_update_job_stage($conn, (int)$id, 'pre_press');
        }
        print_sync_rollup($conn, (int)$id, (int)($_SESSION['user_id'] ?? 0), (string)($_SESSION['name'] ?? 'System'));
        safe_redirect($id);
    }

    // 6. الزنكات (CTP) - منطق الرفع المتعدد
    if (isset($_POST['save_ctp_orders'])) {
        $items_text = $_POST['ctp_item'] ?? [];
        $items_supp = $_POST['ctp_supplier'] ?? [];
        if (!empty($_FILES['ctp_file']['name'])) {
            foreach ($_FILES['ctp_file']['name'] as $i => $name) {
                if ($_FILES['ctp_file']['error'][$i] === 0) {
                    $stored = app_store_uploaded_file([
                        'name' => $_FILES['ctp_file']['name'][$i] ?? '',
                        'type' => $_FILES['ctp_file']['type'][$i] ?? '',
                        'tmp_name' => $_FILES['ctp_file']['tmp_name'][$i] ?? '',
                        'error' => $_FILES['ctp_file']['error'][$i] ?? UPLOAD_ERR_NO_FILE,
                        'size' => $_FILES['ctp_file']['size'][$i] ?? 0,
                    ], [
                        'dir' => 'uploads/source',
                        'prefix' => 'ctp_' . $i . '_',
                        'max_size' => 2048 * 1024 * 1024,
                    ]);
                    if (!empty($stored['ok'])) {
                        $target = (string)$stored['path'];
                        $desc_idx = isset($items_text[$i]) ? $i : 0; 
                        $text = $items_text[$desc_idx] ?? 'ملف زنكات';
                        $supp = $items_supp[$desc_idx] ?? '';
                        $desc = $conn->real_escape_string($text);
                        $supp_phone = $conn->real_escape_string($supp);
                        $conn->query("INSERT INTO job_files (job_id, file_path, stage, description, uploaded_by) VALUES ($id, '$target', 'pre_press_supplies', '$desc', '$supp_phone')");
                    }
                }
            }
        } elseif (!empty($items_text[0])) {
             $desc = $conn->real_escape_string($items_text[0]);
             $supp_phone = $conn->real_escape_string($items_supp[0] ?? '');
             $conn->query("INSERT INTO job_files (job_id, file_path, stage, description, uploaded_by) VALUES ($id, '', 'pre_press_supplies', '$desc', '$supp_phone')");
        }
        print_sync_rollup($conn, (int)$id, (int)($_SESSION['user_id'] ?? 0), (string)($_SESSION['name'] ?? 'System'));
        safe_redirect($id);
    }

    // 7. ملفات الطباعة النهائية (تم تعديلها لتكون مثل CTP)
    if (isset($_POST['save_prepress_files'])) {
        $items_text = $_POST['prep_item'] ?? [];
        $items_supp = $_POST['prep_supplier'] ?? [];
        if (!empty($_FILES['prep_file']['name'])) {
            foreach ($_FILES['prep_file']['name'] as $i => $name) {
                if ($_FILES['prep_file']['error'][$i] === 0) {
                    $stored = app_store_uploaded_file([
                        'name' => $_FILES['prep_file']['name'][$i] ?? '',
                        'type' => $_FILES['prep_file']['type'][$i] ?? '',
                        'tmp_name' => $_FILES['prep_file']['tmp_name'][$i] ?? '',
                        'error' => $_FILES['prep_file']['error'][$i] ?? UPLOAD_ERR_NO_FILE,
                        'size' => $_FILES['prep_file']['size'][$i] ?? 0,
                    ], [
                        'dir' => 'uploads/production',
                        'prefix' => 'final_' . $i . '_',
                        'max_size' => 2048 * 1024 * 1024,
                    ]);
                    if (!empty($stored['ok'])) {
                        $target = (string)$stored['path'];
                        $desc_idx = isset($items_text[$i]) ? $i : 0; 
                        $text = $items_text[$desc_idx] ?? 'ملف طباعة نهائي';
                        $supp = $items_supp[$desc_idx] ?? ''; // يمكن اختيار مطبعة خارجية هنا
                        $desc = $conn->real_escape_string($text);
                        $supp_phone = $conn->real_escape_string($supp);
                        // stage = pre_press للملفات النهائية
                        $conn->query("INSERT INTO job_files (job_id, file_path, stage, description, uploaded_by) VALUES ($id, '$target', 'pre_press', '$desc', '$supp_phone')");
                    }
                }
            }
        }
        print_sync_rollup($conn, (int)$id, (int)($_SESSION['user_id'] ?? 0), (string)($_SESSION['name'] ?? 'System'));
        safe_redirect($id);
    }

    // 8. الطباعة
    if (isset($_POST['action']) && $_POST['action'] == 'start_printing') {
        app_update_job_stage($conn, (int)$id, 'printing');
        safe_redirect($id);
    }
    if (isset($_POST['save_print_specs'])) {
        $newSpecsLine = "بيانات الطباعة التنفيذية: مقاس الورق {$_POST['p_len']}x{$_POST['p_wid']} | مقاس القص {$_POST['c_len']}x{$_POST['c_wid']} | الماكينة {$_POST['machine']} | الألوان {$_POST['colors']} | الوجه {$_POST['print_face']} | الزنكات {$_POST['zinc']}";
        $existingDetails = (string)($job['job_details'] ?? '');
        $cleanDetails = preg_replace('/\R?بيانات الطباعة التنفيذية:.*$/um', '', $existingDetails);
        $cleanDetails = trim((string)$cleanDetails);
        $mergedDetails = $cleanDetails === '' ? $newSpecsLine : ($cleanDetails . "\n" . $newSpecsLine);
        $safe_log = $conn->real_escape_string($mergedDetails);
        $conn->query("UPDATE job_orders SET job_details = '$safe_log' WHERE id=$id");
        app_stage_data_set($conn, (int)$id, 'printing', 'print_specs_summary', $newSpecsLine, (int)($_SESSION['user_id'] ?? 0), (string)($_SESSION['name'] ?? 'System'));
        print_sync_rollup($conn, (int)$id, (int)($_SESSION['user_id'] ?? 0), (string)($_SESSION['name'] ?? 'System'));
        app_update_job_stage($conn, (int)$id, 'finishing');
        if(!empty($_POST['print_notes'])) {
            $p_note = $conn->real_escape_string($_POST['print_notes']);
            $conn->query("UPDATE job_orders SET notes = CONCAT(IFNULL(notes, ''), '\n[🖨️ فني الطباعة]: $p_note') WHERE id=$id");
        }
        safe_redirect($id);
    }

    // 9. التسليم وإنهاء
    if (isset($_POST['finish_stage'])) { 
        $n = $conn->real_escape_string($_POST['finish_notes']);
        if(!empty($n)) $conn->query("UPDATE job_orders SET notes = CONCAT(IFNULL(notes, ''), '\n[تشطيب]: $n') WHERE id=$id");
        app_stage_data_set($conn, (int)$id, 'finishing', 'production_summary', (string)($_POST['finish_notes'] ?? ''), (int)($_SESSION['user_id'] ?? 0), (string)($_SESSION['name'] ?? 'System'));
        print_sync_rollup($conn, (int)$id, (int)($_SESSION['user_id'] ?? 0), (string)($_SESSION['name'] ?? 'System'));
        app_update_job_stage($conn, (int)$id, 'delivery'); 
        safe_redirect($id); 
    }
    if (isset($_POST['finish_delivery']) || isset($_POST['finish_delivery_no_invoice'])) {
        if (isset($_POST['finish_delivery'])) {
            job_create_sales_invoice_from_job($conn, $job, (string)($_SESSION['name'] ?? 'System'));
        } else {
            job_append_accounting_note($conn, (int)$id, 'تم تحويل العملية للحسابات بدون إنشاء فاتورة تلقائية. سيتم الاعتماد على فاتورة موجودة أو مستوردة لاحقاً.');
        }
        app_update_job_stage($conn, (int)$id, 'accounting');
        safe_redirect($id);
    }
    if (isset($_POST['archive_job'])) {
        $archiveStage = isset($workflow['completed']) ? 'completed' : (string)array_key_last($workflow);
        if ($archiveStage === '') {
            $archiveStage = 'completed';
        }
        $archiveStageDb = $conn->real_escape_string($archiveStage);
        app_update_job_stage($conn, (int)$id, $archiveStage, 'completed');
        safe_redirect($id);
    }
    if (isset($_POST['reopen_job'])) {
        $reopenStage = isset($workflow['accounting'])
            ? 'accounting'
            : (string)($workflow['completed']['prev'] ?? $first_stage);
        app_update_job_stage($conn, (int)$id, $reopenStage, 'processing');
        safe_redirect($id);
    }
    if(isset($_POST['save_prepress_zinc'])) {
        $zincs = floatval($_POST['zinc_count']);
        $safe_log = $conn->real_escape_string($raw_text . "\nالزنكات: $zincs |");
        $conn->query("UPDATE job_orders SET job_details = '$safe_log' WHERE id=$id");
        safe_redirect($id);
    }
}

// قائمة الموردين + تحميل الأصول مرة واحدة لتجنب تكرار الاستعلامات
$suppliers_options = "";
$s_res = $conn->query("SELECT name, phone FROM suppliers ORDER BY name ASC");
if($s_res) {
    while($r = $s_res->fetch_assoc()) {
        $suppliers_options .= "<option value='{$r['phone']}'>{$r['name']}</option>";
    }
}
$all_files = [];
$job_files_by_stage = [
    'briefing' => [],
    'materials' => [],
    'pre_press_supplies' => [],
    'pre_press' => [],
];
$files_res = $conn->query("SELECT * FROM job_files WHERE job_id=$id ORDER BY id DESC");
if ($files_res) {
    while ($fileRow = $files_res->fetch_assoc()) {
        $all_files[] = $fileRow;
        $stageKey = (string)($fileRow['stage'] ?? '');
        if (array_key_exists($stageKey, $job_files_by_stage)) {
            $job_files_by_stage[$stageKey][] = $fileRow;
        }
    }
}
$job_proofs = [];
$proofs_res = $conn->query("SELECT * FROM job_proofs WHERE job_id=$id ORDER BY id DESC");
if ($proofs_res) {
    while ($proofRow = $proofs_res->fetch_assoc()) {
        $job_proofs[] = $proofRow;
    }
}
$moduleLang = app_current_lang();
$moduleDir = app_lang_dir($moduleLang);
$moduleI18n = [];
if ($moduleLang === 'en') {
    $moduleI18n = [
        'إدارة العملية' => 'Job Management',
        'ملف العملية' => 'Job File',
        'تعليق داخلي' => 'Internal Comment',
        'المرحلة الحالية' => 'Current Stage',
        'تغيير المرحلة' => 'Change Stage',
        'أرشفة' => 'Archive',
        'إعادة فتح' => 'Reopen',
        'التجهيز والبيانات' => 'Briefing & Data',
        'التصميم' => 'Design',
        'انتظار العميل' => 'Waiting for Client',
        'إدارة الخامات' => 'Materials Management',
        'التجهيز (CTP)' => 'Pre-Press (CTP)',
        'الطباعة' => 'Printing',
        'التشطيب' => 'Finishing',
        'التسليم' => 'Delivery',
        'العملية مكتملة' => 'Job Completed',
        'أرشفة نهائية' => 'Final Archive',
        'تأكيد' => 'Confirm',
        'إلغاء' => 'Cancel',
        'مشاركة ملف' => 'Share File',
        'اختر المورد:' => 'Choose Supplier:',
        'إرسال' => 'Send',
        'عودة' => 'Back',
    ];
}
?>

<?php if (!$app_module_embedded): ?>
<!DOCTYPE html>
<html lang="<?php echo app_h($moduleLang); ?>" dir="<?php echo app_h($moduleDir); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo app_h(app_tr('إدارة العملية', 'Job Management')); ?> - <?php echo htmlspecialchars($job['job_name']); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;800&display=swap" rel="stylesheet">
<?php endif; ?>
    <style>
        /* ========================================================= */
        /* المتغيرات العامة والتنسيقات الأساسية */
        /* ========================================================= */
        :root { 
            --ae-gold: #d4af37; 
            --ae-gold-light: #f1c40f; 
            --ae-dark: #121212; /* Charcoal Base */
            --ae-card: #1a1a1a;
            --ae-border: #2a2a2a;
            --ae-border-focus: #444444;
            --ae-green: #2ecc71; 
            --ae-red: #e74c3c; 
            --ae-text-main: #f5f5f5;
            --ae-text-muted: #a0a0a0;
            --ae-shadow: 0 4px 15px rgba(0, 0, 0, 0.4);
        }
        
        body { 
            background-color: #0d0d0d; 
            color: var(--ae-text-main); 
            font-family: 'Cairo', sans-serif; 
            margin: 0; 
            padding: 20px; 
            line-height: 1.6;
        }

        /* تخصيص شريط التمرير */
        ::-webkit-scrollbar { width: 8px; height: 8px; }
        ::-webkit-scrollbar-track { background: var(--ae-dark); }
        ::-webkit-scrollbar-thumb { background: #333; border-radius: 4px; }
        ::-webkit-scrollbar-thumb:hover { background: var(--ae-gold); }

        /* ========================================================= */
        /* الهيكل (Layout) */
        /* ========================================================= */
        .container.split-layout { 
            display: flex; 
            gap: 24px; 
            align-items: flex-start; 
            flex-wrap: wrap; 
            max-width: 1400px;
            margin: 0 auto;
        }
        .sidebar { 
            width: 320px; 
            flex-shrink: 0; 
            background: var(--ae-card); 
            border: 1px solid var(--ae-border); 
            border-radius: 12px; 
            padding: 24px; 
            box-shadow: var(--ae-shadow);
        }
        .main-content { 
            flex: 1; 
            min-width: 0; 
        }

        @media (max-width: 900px) { 
            .sidebar { width: 100%; margin-bottom: 20px; box-sizing: border-box; order: 2; } 
            .main-content { width: 100%; order: 1; } 
        }

        /* ========================================================= */
        /* الأزرار (Buttons) والتأثيرات */
        /* ========================================================= */
        @property --angle { syntax: '<angle>'; initial-value: 0deg; inherits: false; }
        @keyframes rotateOrbit { to { --angle: 360deg; } }
        @keyframes liquidGold { 0% { background-position: 0% 50%; } 100% { background-position: 100% 50%; } }

        .btn-liquid { 
            position: relative; 
            border: none; 
            background: transparent; 
            padding: 0; 
            cursor: pointer; 
            outline: none; 
            border-radius: 8px; 
            z-index: 1; 
            width: 100%; 
            display: block; 
            margin-top: 15px; 
            text-decoration: none; 
            transition: transform 0.2s ease;
        }
        .btn-liquid:active { transform: scale(0.98); }
        .btn-liquid::before { 
            content: ''; position: absolute; z-index: -2; top: -2px; left: -2px; right: -2px; bottom: -2px; 
            border-radius: 10px; background: var(--ae-dark); 
            background-image: conic-gradient(from var(--angle), transparent 0%, transparent 70%, var(--ae-gold) 85%, var(--ae-gold-light) 95%, transparent 100%); 
            animation: rotateOrbit 4s linear infinite; 
        }
        .btn-content { 
            display: flex; align-items: center; justify-content: center; gap: 8px; 
            background: linear-gradient(90deg, var(--ae-gold), #b8860b, var(--ae-gold-light), var(--ae-gold)); 
            background-size: 300% 100%; padding: 14px 20px; border-radius: 8px; 
            color: #111; font-weight: 800; font-family: 'Cairo'; font-size: 1rem;
            animation: liquidGold 4s linear infinite; 
        }
        .btn-liquid:hover::before { filter: drop-shadow(0 0 12px rgba(212, 175, 55, 0.4)); animation-duration: 2s; }
        .btn-liquid:disabled { opacity: 0.6; cursor: not-allowed; }

        .btn-simple { 
            padding: 10px 18px; border: none; border-radius: 8px; cursor: pointer; color: #fff; 
            font-weight: 600; font-family: 'Cairo'; display: inline-flex; align-items: center; justify-content: center; gap: 6px;
            text-decoration: none; font-size: 0.9rem; transition: all 0.3s ease; 
        }
        .btn-simple:hover { transform: translateY(-2px); box-shadow: 0 4px 10px rgba(0,0,0,0.3); opacity: 0.9; }
        .btn-green { background: var(--ae-green); } 
        .btn-red { background: var(--ae-red); } 
        .btn-gray { background: #333; border: 1px solid #444; }

        /* ========================================================= */
        /* النصوص والمعلومات (Typography & Info Blocks) */
        /* ========================================================= */
        .info-block { margin-bottom: 18px; border-bottom: 1px dashed var(--ae-border); padding-bottom: 15px; }
        .info-block:last-child { border-bottom: none; margin-bottom: 0; padding-bottom: 0; }
        .info-label { color: var(--ae-gold); font-size: 0.85rem; font-weight: 600; display: block; margin-bottom: 6px; text-transform: uppercase; letter-spacing: 0.5px; }
        .info-value { background: #111; padding: 10px 14px; border-radius: 8px; border: 1px solid var(--ae-border); font-size: 0.95rem; word-wrap: break-word; color: #ddd; }
        
        /* ========================================================= */
        /* حقول الإدخال (Inputs & Forms) */
        /* ========================================================= */
        .p-input { 
            background: #111; border: 1px solid var(--ae-border); color: #fff; padding: 12px 14px; 
            width: 100%; border-radius: 8px; box-sizing: border-box; font-family: 'Cairo'; 
            margin-bottom: 10px; font-size: 0.95rem; transition: all 0.3s ease; 
        }
        .p-input:focus {
            border-color: var(--ae-gold);
            box-shadow: 0 0 8px rgba(212, 175, 55, 0.15);
            outline: none;
            background: #151515;
        }
        .p-input::placeholder { color: #666; }

        /* ========================================================= */
        /* البطاقات والمراحل (Cards & Stages) */
        /* ========================================================= */
        .main-card { 
            background: var(--ae-card); padding: 24px; border-radius: 12px; 
            border: 1px solid var(--ae-border); margin-bottom: 24px; box-shadow: var(--ae-shadow); 
        }
        .card-title { color: var(--ae-gold); border-bottom: 1px solid var(--ae-border); padding-bottom: 14px; font-size: 1.3rem; margin-top: 0; font-weight: 800; display: flex; align-items: center; gap: 10px; }
        
        .stage-header { 
            display: flex; gap: 8px; overflow-x: auto; padding-bottom: 15px; margin-bottom: 24px; 
            border-bottom: 1px solid var(--ae-border); scrollbar-width: thin;
        }
        .step-badge { 
            background: #151515; color: var(--ae-text-muted); padding: 8px 18px; 
            border-radius: 20px; white-space: nowrap; font-size: 0.85rem; border: 1px solid #222;
            transition: all 0.3s ease; font-weight: 600;
        }
        .step-badge.active { 
            background: rgba(212, 175, 55, 0.1); color: var(--ae-gold); font-weight: 800; 
            border-color: var(--ae-gold); box-shadow: 0 0 10px rgba(212, 175, 55, 0.1); 
        }

        /* ========================================================= */
        /* إدارة الملفات والمرفقات (Files & Gallery) */
        /* ========================================================= */
        .file-item { 
            display: flex; align-items: center; gap: 8px; background: #111; padding: 10px 14px; 
            margin-bottom: 8px; border-radius: 8px; border: 1px solid var(--ae-border); 
            transition: all 0.2s ease; 
        }
        .file-item:hover { border-color: var(--ae-gold); background: #151515; }
        .file-link { color: var(--ae-text-main); flex: 1; text-decoration: none; overflow: hidden; white-space: nowrap; text-overflow: ellipsis; font-size: 0.9rem; display: flex; align-items: center; gap: 8px; }
        .file-link i { color: var(--ae-gold); font-size: 1.1rem; }
        
        .action-btn { 
            width: 32px; height: 32px; border-radius: 6px; display: flex; align-items: center; 
            justify-content: center; cursor: pointer; border: none; transition: all 0.2s ease; font-size: 0.9rem; 
        }
        .btn-del { background: rgba(231, 76, 60, 0.1); color: var(--ae-red); }
        .btn-del:hover { background: var(--ae-red); color: #fff; transform: scale(1.05); }
        .btn-share { background: rgba(37, 211, 102, 0.1); color: #25D366; }
        .btn-share:hover { background: #25D366; color: #fff; transform: scale(1.05); }
        
        .btn-wa { 
            background: #25D366; font-size: 0.85rem; padding: 6px 12px; border-radius: 20px; 
            color: #fff; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; 
            margin-top: 5px; font-weight: 600; transition: all 0.2s ease; border: none; cursor: pointer;
        }
        .btn-wa:hover { opacity: 0.9; transform: translateY(-1px); }

        .upload-zone { 
            border: 1.5px dashed #444; padding: 25px; border-radius: 12px; background: #111; 
            text-align: center; margin-top: 20px; transition: border-color 0.3s ease; 
        }
        .upload-zone:hover { border-color: var(--ae-gold); }
        
        .progress-wrapper { width: 100%; background: #222; height: 12px; border-radius: 6px; margin-top: 15px; overflow: hidden; display: none; }
        .progress-fill { height: 100%; background: var(--ae-green); width: 0%; transition: width 0.3s ease; box-shadow: 0 0 10px rgba(46, 204, 113, 0.5); }
        
        .gallery-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 20px; margin-top: 20px; }
        .gallery-item { background: #111; border: 1px solid var(--ae-border); border-radius: 10px; overflow: hidden; position: relative; transition: all 0.3s ease; }
        .gallery-item:hover { border-color: #555; transform: translateY(-3px); box-shadow: var(--ae-shadow); }
        .gallery-item.rejected-item { border: 1px solid rgba(231, 76, 60, 0.5); }
        .gallery-item.approved-item { border: 1px solid rgba(46, 204, 113, 0.5); }
        .g-thumb { width: 100%; height: 140px; object-fit: cover; display: block; background: #0a0a0a; transition: opacity 0.3s; }
        .gallery-item a:hover .g-thumb { opacity: 0.8; }
        
        .client-feedback-box { background: rgba(231, 76, 60, 0.05); border: 1px solid rgba(231, 76, 60, 0.3); padding: 12px; margin-top: 10px; color: #ffcccc; font-size: 0.85rem; line-height: 1.5; border-radius: 8px; }
        .client-approval-box { background: rgba(46, 204, 113, 0.05); border: 1px solid rgba(46, 204, 113, 0.3); padding: 8px; margin-top: 10px; color: var(--ae-green); font-size: 0.85rem; text-align: center; border-radius: 8px; font-weight: 600; }

        /* ========================================================= */
        /* النوافذ المنبثقة والتحكم الإداري (Modals & Admin) */
        /* ========================================================= */
        .admin-controls { background: #1a1010; border: 1px solid rgba(231, 76, 60, 0.3); padding: 15px; border-radius: 10px; margin-bottom: 20px; }
        .admin-badge { background: var(--ae-red); color: white; padding: 4px 10px; border-radius: 4px; font-size: 0.75rem; font-weight: bold; margin-bottom: 10px; display: inline-block; letter-spacing: 0.5px; }

        .modal-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.85); z-index: 9999; justify-content: center; align-items: center; backdrop-filter: blur(4px); }
        .modal-box { background: var(--ae-card); padding: 30px; width: min(450px, calc(100vw - 40px)); border: 1px solid var(--ae-gold); border-radius: 12px; text-align: center; position: relative; box-shadow: 0 15px 40px rgba(0,0,0,0.6); }
        
        .loading-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.9); z-index: 99999; display: none; align-items: center; justify-content: center; flex-direction: column; color: #fff; backdrop-filter: blur(5px); }
        
        /* ========================================================= */
        /* التوافقية مع الهواتف (Responsive Mobile) */
        /* ========================================================= */
        @media (max-width: 560px) {
            body { padding: 15px 15px 90px; }
            .container.split-layout { gap: 15px; }
            .sidebar { padding: 0; background: transparent; border: none; box-shadow: none; }
            .sidebar.mobile-collapsed > *:not(.sidebar-mobile-head) { display: none !important; }
            
            .sidebar-mobile-head {
                width: 100%; display: flex; align-items: center; justify-content: space-between; gap: 10px;
                padding: 16px; background: var(--ae-card); color: var(--ae-gold); border: 1px solid var(--ae-border);
                border-radius: 10px; font-weight: 800; cursor: pointer; font-size: 1.1rem;
            }
            .sidebar-mobile-head::after { content: "⌄"; font-size: 1.2rem; transition: transform .3s ease; }
            .sidebar-mobile-head.open::after { transform: rotate(180deg); }
            .sidebar-mobile-head.open { border-bottom-left-radius: 0; border-bottom-right-radius: 0; border-bottom: none; }
            
            .main-card { padding: 16px; }
            .stage-header {
                display: grid; grid-template-columns: repeat(2, 1fr); gap: 10px; padding: 0 0 15px; border-bottom: none;
            }
            .step-badge { text-align: center; padding: 10px; font-size: 0.8rem; white-space: normal; }
            .step-badge.active { grid-column: 1 / -1; }
            .gallery-grid { grid-template-columns: repeat(auto-fill, minmax(130px, 1fr)); gap: 15px; }
            .file-item { flex-direction: column; align-items: flex-start; gap: 12px; }
            .file-item form, .file-item a, .file-item > div { width: 100%; }
            .main-card form div[style*="display:flex"] { flex-direction: column; align-items: stretch !important; gap: 10px; }
        }
    </style>

    <script>
    document.addEventListener('DOMContentLoaded', function () {
        if (!window.matchMedia('(max-width: 560px)').matches) return;
        document.querySelectorAll('.sidebar').forEach(function (sidebar) {
            if (sidebar.querySelector('.sidebar-mobile-head')) return;
            const titleEl = sidebar.querySelector('h3');
            const title = titleEl ? titleEl.textContent.trim() : 'ملف العملية';
            if(titleEl) titleEl.style.display = 'none'; // إخفاء العنوان الأصلي
            
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'sidebar-mobile-head';
            btn.textContent = title;
            btn.addEventListener('click', function () {
                sidebar.classList.toggle('mobile-collapsed');
                btn.classList.toggle('open');
                // تجميل الخلفية عند الفتح
                if(btn.classList.contains('open')){
                    sidebar.style.background = 'var(--ae-card)';
                    sidebar.style.border = '1px solid var(--ae-border)';
                    sidebar.style.borderTop = 'none';
                    sidebar.style.borderTopLeftRadius = '0';
                    sidebar.style.borderTopRightRadius = '0';
                    sidebar.style.padding = '15px';
                } else {
                    sidebar.style.background = 'transparent';
                    sidebar.style.border = 'none';
                    sidebar.style.padding = '0';
                }
            });
            sidebar.insertBefore(btn, sidebar.firstChild);
            sidebar.classList.add('mobile-collapsed');
        });
    });
    </script>

    <script>
    function uploadFilesUniversal(stage, inputId, progressId, textId, btnId) {
        const fileInput = document.getElementById(inputId);
        const files = fileInput.files;
        const descInput = document.getElementById(inputId + '_desc');
        
        if (files.length === 0) return alert("اختر ملفات أولاً");

        const formData = new FormData();
        for (let i = 0; i < files.length; i++) formData.append("ajax_files[]", files[i]);
        formData.append("job_id", "<?php echo $id; ?>");
        formData.append("stage", stage);
        formData.append("ajax_action", "universal_upload");
        if (typeof csrfToken === "string" && csrfToken !== "") {
            formData.append("_csrf_token", csrfToken);
        }
        if(descInput) formData.append("file_desc", descInput.value);

        const xhr = new XMLHttpRequest();
        xhr.timeout = 0; // 🛑 إلغاء التايم أوت للملفات الكبيرة
        
        const btn = document.getElementById(btnId);
        const pWrap = document.getElementById(progressId);
        const pFill = pWrap.querySelector('.progress-fill');
        const pText = document.getElementById(textId);
        const originalContent = btn.innerHTML;

        btn.disabled = true;
        btn.querySelector('.btn-content').innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> جاري الرفع...';
        pWrap.style.display = 'block';
        pText.style.display = 'block';
        pFill.style.width = "0%";

        xhr.upload.addEventListener("progress", function(evt) {
            if (evt.lengthComputable) {
                const percent = Math.round((evt.loaded / evt.total) * 100);
                pFill.style.width = percent + "%";
                pText.innerHTML = percent + "%";
            }
        });

        xhr.onload = function() {
            if (xhr.status === 200) {
                try {
                    const res = JSON.parse(xhr.responseText);
                    if (res.status === 'success') {
                        pText.innerHTML = res.msg;
                        setTimeout(() => window.location.reload(), 1000);
                    } else {
                        alert(res.msg);
                        resetBtn(btn, originalContent);
                    }
                } catch (e) {
                    console.error(xhr.responseText);
                    alert("خطأ في السيرفر");
                    resetBtn(btn, originalContent);
                }
            } else {
                alert("فشل الاتصال");
                resetBtn(btn, originalContent);
            }
        };
        xhr.onerror = function() { alert("خطأ شبكة"); resetBtn(btn, originalContent); };
        xhr.open("POST", window.location.href);
        xhr.send(formData);
    }

    function resetBtn(btn, content) {
        setTimeout(() => { btn.disabled = false; btn.innerHTML = content; }, 1000);
    }

    function showLoading() {
        document.getElementById('fullPageLoader').style.display = 'flex';
    }

    // دالة المشاركة
    let currentFileUrl = '';
    function openShareModal(fileUrl, fileName) {
        currentFileUrl = fileUrl;
        document.getElementById('shareFileName').innerText = fileName;
        document.getElementById('shareModal').style.display = 'flex';
    }
    function sendWhatsApp() {
        const phone = document.getElementById('shareSupplier').value || document.getElementById('manualPhone').value;
        if(!phone) return alert('برجاء اختيار مورد أو كتابة رقم صحيح');
        const msg = "مرفق ملف خاص بعملية: <?php echo htmlspecialchars($job['job_name']); ?>\n" + currentFileUrl;
        let cleanPhone = phone.replace(/[^0-9]/g, '');
        if (cleanPhone.length == 11 && cleanPhone.startsWith('01')) cleanPhone = '2' + cleanPhone;
        const url = `https://wa.me/${cleanPhone}?text=${encodeURIComponent(msg)}`;
        window.open(url, '_blank');
        document.getElementById('shareModal').style.display = 'none';
    }
    </script>
</head>
<body>

<div id="fullPageLoader" class="loading-overlay">
    <i class="fa-solid fa-circle-notch fa-spin fa-3x" style="color:var(--ae-gold); margin-bottom:20px;"></i>
    <h3 style="margin:0; color:#fff; font-weight:600;">جاري رفع الملفات الكبيرة... يرجى الانتظار</h3>
</div>

<div class="container split-layout">
    
    <div class="sidebar">
        <?php if($can_force_stage): ?>
        <div class="admin-controls">
            <span class="admin-badge"><i class="fa-solid fa-shield-halved"></i> تحكم إداري</span>
            <form method="POST" onsubmit="return confirm('تأكيد تغيير المرحلة جبرياً؟');">
                <input type="hidden" name="admin_change_stage" value="1">
                <select name="target_stage" class="p-input" style="padding:8px; font-size:0.85rem; height:auto; margin-bottom:12px;">
                    <?php foreach($workflow as $key => $val): ?>
                        <option value="<?php echo htmlspecialchars($key); ?>" <?php if($key == $curr) echo 'selected'; ?>><?php echo htmlspecialchars($val['label']); ?></option>
                    <?php endforeach; ?>
                </select>
                <button class="btn-simple btn-red" style="width:100%; font-size:0.85rem;">تحديث المرحلة <i class="fa-solid fa-bolt"></i></button>
            </form>
        </div>
        <?php endif; ?>

        <h3 style="color:#fff; border-bottom:2px solid var(--ae-gold); padding-bottom:12px; margin-top:0;"><i class="fa-solid fa-folder-open" style="color:var(--ae-gold);"></i> ملف العملية</h3>
        
        <div class="info-block">
            <span class="info-label"><i class="fa-solid fa-user"></i> العميل</span>
            <div class="info-value" style="color:var(--ae-gold); font-weight:bold; font-size:1.05rem;"><?php echo htmlspecialchars($job['client_name']); ?></div>
            <div class="info-value" style="margin-top:6px; font-size:0.85rem; color:var(--ae-text-muted);"><i class="fa-solid fa-phone"></i> <?php echo htmlspecialchars($job['client_phone']); ?></div>
        </div>
        
        <div class="info-block">
            <span class="info-label"><i class="fa-solid fa-tag"></i> اسم العملية</span>
            <div class="info-value" style="font-weight:600;"><?php echo htmlspecialchars($job['job_name']); ?></div>
        </div>
        
        <div class="info-block">
            <span class="info-label"><i class="fa-solid fa-list-check"></i> المواصفات الأساسية</span>
            <div class="info-value" style="font-size:0.9rem; line-height:1.7;"><?php echo nl2br(htmlspecialchars($job['job_details'] ?? '-')); ?></div>
        </div>

        <?php if(!empty($history_notes)): ?>
        <div class="info-block">
            <span class="info-label"><i class="fa-solid fa-clock-rotate-left"></i> سجل الملاحظات والبيانات</span>
            <?php foreach($history_notes as $note): ?>
                <div class="info-value" style="margin-bottom:8px; font-size:0.85rem;">
                    <span style="color:var(--ae-gold); font-weight:bold; display:block; margin-bottom:4px;"><?php echo htmlspecialchars($note[1]); ?>:</span>
                    <?php echo nl2br(htmlspecialchars($note[2])); ?>
                </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <div class="info-block" style="border:none;">
            <span class="info-label"><i class="fa-solid fa-paperclip"></i> الملفات المرفوعة (الكل)</span>
            <?php if(!empty($all_files)): ?>
                <?php foreach($all_files as $f): 
                    $file_url = (isset($_SERVER['HTTPS'])?"https":"http")."://".$_SERVER['HTTP_HOST']. dirname($_SERVER['PHP_SELF']) . "/" .$f['file_path'];
                    $file_name = basename($f['file_path']);
                ?>
                <div class="file-item">
                    <a href="<?php echo htmlspecialchars($file_url); ?>" target="_blank" class="file-link" title="<?php echo htmlspecialchars($file_name); ?>">
                        <i class="fa-solid fa-file-arrow-down"></i> <?php echo htmlspecialchars(mb_strimwidth($file_name, 0, 15, "...")); ?>
                    </a>
                    <button class="action-btn btn-share" onclick="openShareModal('<?php echo htmlspecialchars(addslashes($file_url)); ?>', '<?php echo htmlspecialchars(addslashes($file_name)); ?>')" title="إرسال واتساب"><i class="fa-brands fa-whatsapp"></i></button>
                    <form method="POST" style="margin:0;">
                        <input type="hidden" name="__async_form" value="1">
                        <input type="hidden" name="delete_item" value="1">
                        <input type="hidden" name="type" value="file">
                        <input type="hidden" name="item_id" value="<?php echo (int)$f['id']; ?>">
                        <button class="action-btn btn-del" onclick="return confirm('تأكيد حذف الملف نهائياً؟')" title="حذف"><i class="fa-solid fa-trash-can"></i></button>
                    </form>
                </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div style='color:var(--ae-text-muted); font-size:0.9rem; padding:10px; background:#111; border-radius:8px; text-align:center;'>لا يوجد ملفات مرفوعة</div>
            <?php endif; ?>
        </div>
    </div>

    <div class="main-content">
        
        <div class="stage-header">
            <?php foreach($workflow as $key => $data): ?>
                <div class="step-badge <?php echo ($key == $curr) ? 'active' : ''; ?>"><?php echo htmlspecialchars($data['label']); ?></div>
            <?php endforeach; ?>
        </div>

        <?php if($curr == 'briefing'): ?>
        <div class="main-card">
            <h3 class="card-title"><i class="fa-solid fa-pen-ruler"></i> التجهيز والبيانات</h3>
            <form method="POST">
                <textarea name="notes" rows="4" class="p-input" placeholder="أضف ملاحظات فنية، تفاصيل للمصمم، أو متطلبات خاصة..."></textarea>
                <button name="save_brief_notes" class="btn-liquid"><span class="btn-content"><i class="fa-solid fa-wand-magic-sparkles"></i> حفظ وبدء التصميم</span></button>
            </form>
            
            <div class="upload-zone">
                <h4 style="margin-top:0; color:#fff; font-weight:600;"><i class="fa-solid fa-cloud-arrow-up" style="color:var(--ae-gold);"></i> رفع ملفات مساعدة</h4>
                <p style="color:var(--ae-text-muted); font-size:0.85rem; margin-bottom:15px;">المرفقات ستظهر في القائمة الجانبية فور رفعها لمشاركتها بسهولة</p>
                <input type="text" id="brief_desc" class="p-input" placeholder="وصف المرفق (اختياري)">
                <input type="file" id="brief_files" multiple class="p-input" style="padding:8px;">
                <div id="prog_brief" class="progress-wrapper"><div class="progress-fill"></div></div>
                <div id="txt_brief" class="progress-text" style="display:none; text-align:center; margin-top:5px; font-size:0.85rem; color:var(--ae-gold);">0%</div>
                <button type="button" id="btn_brief" class="btn-simple btn-gray" style="width:100%; margin-top:10px; padding:12px;" onclick="uploadFilesUniversal('briefing', 'brief_files', 'prog_brief', 'txt_brief', 'btn_brief')">
                    <i class="fa-solid fa-upload"></i> بدء الرفع
                </button>
            </div>
            
             <?php if(!empty($job_files_by_stage['briefing'])): ?>
             <h4 style="color:var(--ae-gold); margin:25px 0 10px 0; border-top:1px solid var(--ae-border); padding-top:15px;"><i class="fa-solid fa-share-nodes"></i> ملفات التجهيز السريعة</h4>
                <?php foreach($job_files_by_stage['briefing'] as $b): 
                    $b_url = (isset($_SERVER['HTTPS'])?"https":"http")."://".$_SERVER['HTTP_HOST']. dirname($_SERVER['PHP_SELF']) . "/" .$b['file_path'];
                ?>
                <div class="file-item" style="justify-content:space-between;">
                    <span style="font-weight:600; color:#ccc;"><i class="fa-solid fa-file-lines" style="color:#555; margin-right:5px;"></i> <?php echo htmlspecialchars($b['description']); ?></span>
                    <button class="btn-wa" onclick="openShareModal('<?php echo htmlspecialchars(addslashes($b_url)); ?>', '<?php echo htmlspecialchars(addslashes($b['description'])); ?>')"><i class="fa-brands fa-whatsapp"></i> مشاركة</button>
                </div>
                <?php endforeach; ?>
             <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if($curr == 'design' || $can_design_stage): ?>
        <div class="main-card">
            <h3 class="card-title"><i class="fa-solid fa-palette"></i> التصميم وإدارة البروفات</h3>
            <div class="upload-zone">
                <h4 style="margin-top:0; color:#fff; font-weight:600;">رفع بروفات للعميل</h4>
                <input type="text" id="proof_desc" class="p-input" placeholder="اسم البروفة (مثال: تصميم الوجه الأول)">
                <input type="file" id="proof_file" multiple class="p-input" style="padding:8px;">
                <div id="prog_proof" class="progress-wrapper"><div class="progress-fill"></div></div>
                <div id="txt_proof" class="progress-text" style="display:none; text-align:center; margin-top:5px; font-size:0.85rem; color:var(--ae-gold);">0%</div>
                <button type="button" id="btn_proof" class="btn-simple btn-gray" style="width:100%; margin-top:10px; padding:12px;" onclick="uploadFilesUniversal('design', 'proof_file', 'prog_proof', 'txt_proof', 'btn_proof')">
                    <i class="fa-solid fa-cloud-arrow-up"></i> رفع البروفة
                </button>
            </div>
            
            <div class="gallery-grid">
                <?php foreach($job_proofs as $p): 
                    $url = (isset($_SERVER['HTTPS'])?"https":"http")."://".$_SERVER['HTTP_HOST']. dirname($_SERVER['PHP_SELF']) . "/" .$p['file_path'];
                    $is_rejected = ($p['status'] == 'rejected' || $p['status'] == 'pending_revision');
                    $is_approved = ($p['status'] == 'approved');
                ?>
                <div class="gallery-item <?php echo $is_rejected ? 'rejected-item' : ($is_approved ? 'approved-item' : ''); ?>">
                    <a href="<?php echo htmlspecialchars($url); ?>" target="_blank">
                        <?php if(preg_match('/\.(jpg|jpeg|png|gif|webp)$/i', $p['file_path'])): ?>
                            <img src="<?php echo htmlspecialchars($url); ?>" class="g-thumb" alt="بروفة">
                        <?php else: ?>
                            <div class="g-thumb" style="display:flex;align-items:center;justify-content:center;color:#444; background:#151515;"><i class="fa-solid fa-file-pdf fa-3x"></i></div>
                        <?php endif; ?>
                    </a>
                    <div style="padding:15px; font-size:0.9rem;">
                        <div style="font-weight:800; margin-bottom:10px; color:#fff; text-align:center;"><?php echo htmlspecialchars($p['description']); ?></div>
                        
                        <?php if(!empty($p['client_comment']) && $is_rejected): ?>
                            <div class="client-feedback-box">
                                <i class="fa-solid fa-triangle-exclamation"></i> <strong>مطلوب تعديل:</strong><br>
                                <?php echo htmlspecialchars($p['client_comment']); ?>
                            </div>
                        <?php endif; ?>

                        <?php if($is_approved): ?>
                            <div class="client-approval-box">
                                <i class="fa-solid fa-circle-check"></i> معتمد
                            </div>
                        <?php endif; ?>

                        <div style="display:flex; justify-content:center; gap:10px; margin-top:15px; border-top:1px solid #222; padding-top:10px;">
                            <button class="action-btn btn-share" onclick="openShareModal('<?php echo htmlspecialchars(addslashes($url)); ?>', 'بروفة')" title="مشاركة"><i class="fa-brands fa-whatsapp"></i></button>
                            <form method="POST" style="margin:0;">
                                <input type="hidden" name="__async_form" value="1">
                                <input type="hidden" name="delete_item" value="1">
                                <input type="hidden" name="type" value="proof">
                                <input type="hidden" name="item_id" value="<?php echo (int)$p['id']; ?>">
                                <button class="action-btn btn-del" onclick="return confirm('تأكيد حذف البروفة؟')"><i class="fa-solid fa-trash-can"></i></button>
                            </form>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            
            <?php if($curr == 'design'): ?>
            <form method="POST" style="margin-top:30px;">
                <button name="send_to_review" class="btn-liquid"><span class="btn-content">إرسال للمراجعة والاعتماد <i class="fa-solid fa-paper-plane"></i></span></button>
            </form>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if($curr == 'client_rev'): ?>
        <div class="main-card">
            <h3 class="card-title" style="color:var(--ae-gold-light);"><i class="fa-solid fa-hourglass-half"></i> في انتظار مراجعة العميل</h3>
            <?php 
                $link = app_client_review_link($conn, $job);
                $wa = get_wa_link($job['client_phone'], "رابط مراجعة واعتماد التصميم الخاص بكم:\n$link");
            ?>
            <div style="text-align:center; background:#111; padding:25px; border-radius:10px; border:1px solid var(--ae-border);">
                <p style="color:var(--ae-text-muted); margin-top:0; font-size:0.9rem;">رابط الاعتماد المخصص للعميل:</p>
                <input type="text" value="<?php echo htmlspecialchars($link); ?>" readonly class="p-input" style="direction:ltr; text-align:center; font-family:monospace; color:var(--ae-gold); font-weight:bold;">
                <div style="margin-top:20px; display:flex; justify-content:center; gap:15px; flex-wrap:wrap;">
                    <a href="<?php echo htmlspecialchars($wa); ?>" target="_blank" class="btn-simple btn-green"><i class="fa-brands fa-whatsapp"></i> إرسال عبر واتساب</a>
                    <a href="<?php echo htmlspecialchars($link); ?>" target="_blank" class="btn-simple btn-gray"><i class="fa-solid fa-eye"></i> معاينة الرابط</a>
                </div>
            </div>
            
            <div style="display:flex; gap:15px; margin-top:30px; flex-wrap:wrap;">
                <form method="POST" style="flex:1; min-width:200px;">
                    <input type="hidden" name="prev_target" value="design">
                    <input type="hidden" name="return_reason" value="تعديل داخلي">
                    <button name="return_stage" class="btn-simple btn-red" style="width:100%; padding:12px;"><i class="fa-solid fa-arrow-rotate-left"></i> سحب للتصميم (تعديل)</button>
                </form>
                <form method="POST" style="flex:1; min-width:200px;">
                    <button name="finalize_review" class="btn-simple btn-gray" style="width:100%; padding:12px; border-color:var(--ae-gold); color:var(--ae-gold);"><i class="fa-solid fa-forward-step"></i> تجاوز الاعتماد (موافقة يدوية)</button>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <?php if($curr == 'materials'): ?>
        <div class="main-card">
            <h3 class="card-title"><i class="fa-solid fa-boxes-stacked"></i> إدارة الخامات</h3>
            
            <?php if(!empty($job_files_by_stage['materials'])): ?>
            <div style="margin-bottom:25px; border:1px solid var(--ae-border); padding:20px; border-radius:10px; background:#111;">
                <h4 style="margin:0 0 15px 0; color:var(--ae-text-muted); font-weight:600;"><i class="fa-solid fa-clipboard-list"></i> طلبات توريد سابقة:</h4>
                <?php foreach($job_files_by_stage['materials'] as $m): 
                    $sup_ph = preg_replace('/[^0-9]/', '', $m['uploaded_by']);
                    $msg_text = "مطلوب خامات لعملية {$job['job_name']}:\n" . $m['description'];
                    if (!empty($m['file_path'])) {
                        $file_full_url = (isset($_SERVER['HTTPS']) ? "https" : "http") . "://" . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']) . "/" . $m['file_path'];
                        $msg_text .= "\n📎 رابط الملف: " . $file_full_url;
                    }
                    $wa_sup = (!empty($sup_ph)) ? get_wa_link($sup_ph, $msg_text) : '#';
                ?>
                <div style="display:flex; justify-content:space-between; margin-bottom:8px; background:#1a1a1a; padding:12px; border-radius:8px; align-items:center; border:1px solid #222;">
                    <span style="font-weight:600; color:#ddd;"><i class="fa-solid fa-box" style="color:var(--ae-gold); margin-right:8px;"></i> <?php echo htmlspecialchars($m['description']); ?></span>
                    <div style="display:flex; align-items:center; gap:10px;">
                        <?php if(!empty($sup_ph)): ?>
                            <a href="<?php echo htmlspecialchars($wa_sup); ?>" target="_blank" class="btn-wa" style="margin:0;"><i class="fa-brands fa-whatsapp"></i> إرسال</a>
                        <?php endif; ?>
                        <form method="POST" style="margin:0;">
                            <input type="hidden" name="__async_form" value="1"><input type="hidden" name="delete_item" value="1">
                            <input type="hidden" name="type" value="file"><input type="hidden" name="item_id" value="<?php echo (int)$m['id']; ?>">
                            <button class="action-btn btn-del" onclick="return confirm('حذف؟')"><i class="fa-solid fa-xmark"></i></button>
                        </form>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <form method="POST" enctype="multipart/form-data">
                <div id="mat-items-container">
                    <div style="display:flex; gap:10px; margin-bottom:10px; flex-wrap:wrap; background:#151515; padding:15px; border-radius:8px; border:1px solid #222;">
                        <input type="text" name="item_text[]" placeholder="توصيف الصنف (ورق، أحبار...)" class="p-input" style="flex:2; margin-bottom:0;">
                        <select name="supplier_phone[]" class="p-input" style="flex:1; margin-bottom:0;">
                            <option value="">-- اختر مورد --</option>
                            <?php echo $suppliers_options; ?>
                        </select>
                        <input type="file" name="item_file[]" class="p-input" style="flex:1; margin-bottom:0; padding:8px;" multiple>
                    </div>
                </div>
                <button type="button" onclick="addMatItem()" class="btn-simple btn-gray" style="margin-top:10px;"><i class="fa-solid fa-plus"></i> بند إضافي</button>
                
                <div style="display:flex; gap:15px; margin-top:30px;">
                    <button name="save_materials" class="btn-simple btn-gray" style="flex:1; padding:12px;"><i class="fa-solid fa-floppy-disk"></i> حفظ البيانات فقط</button>
                    <button name="finish_materials" class="btn-liquid" style="flex:2; margin-top:0;"><span class="btn-content">الانتهاء من الخامات <i class="fa-solid fa-arrow-left"></i></span></button>
                </div>
            </form>
        </div>
        <?php endif; ?>

        <?php if($curr == 'pre_press'): ?>
        <div class="main-card">
            <h3 class="card-title"><i class="fa-solid fa-gears"></i> التجهيز والفرز (CTP)</h3>
            
            <?php if(!empty($job_files_by_stage['pre_press_supplies'])): ?>
            <div style="margin-bottom:25px; border:1px solid rgba(212, 175, 55, 0.3); padding:20px; border-radius:10px; background:rgba(212, 175, 55, 0.02);">
                <h4 style="margin:0 0 15px 0; color:var(--ae-gold);"><i class="fa-solid fa-layer-group"></i> طلبات الزنكات الحالية:</h4>
                <?php foreach($job_files_by_stage['pre_press_supplies'] as $c): 
                    $s_ph = preg_replace('/[^0-9]/', '', $c['uploaded_by']);
                    $w_msg = "طلب فرز/زنكات لعملية {$job['job_name']}:\n" . $c['description'];
                    if (!empty($c['file_path'])) {
                         $file_full_url = (isset($_SERVER['HTTPS']) ? "https" : "http") . "://" . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']) . "/" . $c['file_path'];
                         $w_msg .= "\n📎 رابط الملف: " . $file_full_url;
                    }
                    $w_lnk = (!empty($s_ph)) ? get_wa_link($s_ph, $w_msg) : '#';
                ?>
                <div style="background:#111; padding:12px; margin-bottom:8px; border-radius:8px; display:flex; justify-content:space-between; align-items:center; border:1px solid #333;">
                    <span style="font-weight:600; color:#ddd;"><?php echo htmlspecialchars($c['description']); ?></span>
                    <div style="display:flex; align-items:center; gap:10px;">
                        <?php if(!empty($s_ph)): ?><a href="<?php echo htmlspecialchars($w_lnk); ?>" target="_blank" class="btn-wa" style="margin:0;"><i class="fa-brands fa-whatsapp"></i></a><?php endif; ?>
                        <form method="POST" style="margin:0;">
                            <input type="hidden" name="__async_form" value="1"><input type="hidden" name="delete_item" value="1">
                            <input type="hidden" name="type" value="file"><input type="hidden" name="item_id" value="<?php echo (int)$c['id']; ?>">
                            <button class="action-btn btn-del"><i class="fa-solid fa-xmark"></i></button>
                        </form>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <form method="POST" enctype="multipart/form-data" onsubmit="showLoading()" style="border-bottom:1px dashed var(--ae-border); padding-bottom:25px; margin-bottom:25px;">
                <h4 style="margin-top:0; color:#fff; font-size:1rem; margin-bottom:15px;">إضافة طلب زنكات جديد</h4>
                <div style="display:flex; gap:10px; flex-wrap:wrap; background:#151515; padding:15px; border-radius:8px;">
                    <input type="text" name="ctp_item[]" class="p-input" placeholder="توصيف (مثال: 4 زنكات مقاس ربع)" style="flex:2; margin-bottom:0;">
                    <select name="ctp_supplier[]" class="p-input" style="flex:1; margin-bottom:0;">
                        <option value="">-- مكتب الفرز --</option>
                        <?php echo $suppliers_options; ?>
                    </select>
                    <input type="file" name="ctp_file[]" class="p-input" style="flex:1; margin-bottom:0; padding:8px;" multiple>
                    <button name="save_ctp_orders" class="btn-simple btn-gray"><i class="fa-solid fa-plus"></i> إضافة</button>
                </div>
            </form>

            <h4 style="color:#fff; font-size:1.1rem; margin-bottom:15px;"><i class="fa-solid fa-file-pdf" style="color:var(--ae-red);"></i> ملفات الطباعة النهائية (PDF)</h4>
            <?php if(!empty($job_files_by_stage['pre_press'])): ?>
            <div style="margin-bottom:20px; border:1px solid #333; padding:15px; border-radius:10px; background:#111;">
                <?php foreach($job_files_by_stage['pre_press'] as $pf): 
                    $s_ph = preg_replace('/[^0-9]/', '', $pf['uploaded_by']);
                    $w_msg = "ملف طباعة نهائي للعملية {$job['job_name']}:\n" . $pf['description'];
                    if (!empty($pf['file_path'])) {
                         $file_full_url = (isset($_SERVER['HTTPS']) ? "https" : "http") . "://" . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']) . "/" . $pf['file_path'];
                         $w_msg .= "\n📎 تحميل للطباعة: " . $file_full_url;
                    }
                    $w_lnk = (!empty($s_ph)) ? get_wa_link($s_ph, $w_msg) : '#';
                ?>
                <div style="background:#1a1a1a; padding:12px; margin-bottom:8px; border-radius:8px; display:flex; justify-content:space-between; align-items:center; border:1px solid #222;">
                    <a href="<?php echo htmlspecialchars($file_full_url ?? '#'); ?>" target="_blank" style="color:#fff; text-decoration:none; font-weight:600; display:flex; align-items:center; gap:8px;">
                        <i class="fa-solid fa-file-pdf" style="color:var(--ae-red); font-size:1.2rem;"></i> <?php echo htmlspecialchars($pf['description']); ?>
                    </a>
                    <div style="display:flex; align-items:center; gap:10px;">
                        <?php if(!empty($s_ph)): ?><a href="<?php echo htmlspecialchars($w_lnk); ?>" target="_blank" class="btn-wa" style="margin:0;"><i class="fa-solid fa-print"></i> للمطبعة</a><?php endif; ?>
                        <form method="POST" style="margin:0;">
                            <input type="hidden" name="__async_form" value="1"><input type="hidden" name="delete_item" value="1">
                            <input type="hidden" name="type" value="file"><input type="hidden" name="item_id" value="<?php echo (int)$pf['id']; ?>">
                            <button class="action-btn btn-del"><i class="fa-solid fa-trash-can"></i></button>
                        </form>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <form method="POST" enctype="multipart/form-data" onsubmit="showLoading()">
                <div style="display:flex; gap:10px; flex-wrap:wrap; background:#151515; padding:15px; border-radius:8px; border:1px solid #222;">
                    <input type="text" name="prep_item[]" class="p-input" placeholder="وصف الملف (مثال: وجه 1 / غلاف)" style="flex:2; margin-bottom:0;">
                    <select name="prep_supplier[]" class="p-input" style="flex:1; margin-bottom:0;">
                        <option value="">-- مطبعة خارجية (اختياري) --</option>
                        <?php echo $suppliers_options; ?>
                    </select>
                    <input type="file" name="prep_file[]" class="p-input" style="flex:1; margin-bottom:0; padding:8px;" multiple>
                    <button name="save_prepress_files" class="btn-simple btn-gray"><i class="fa-solid fa-cloud-arrow-up"></i> رفع</button>
                </div>
            </form>

            <form method="POST" style="margin-top:30px;">
                <input type="hidden" name="action" value="start_printing">
                <button class="btn-liquid"><span class="btn-content">اعتماد وبدء الطباعة الفعيلة <i class="fa-solid fa-print"></i></span></button>
            </form>
        </div>
        <?php endif; ?>

        <?php if($curr == 'printing'): ?>
        <div class="main-card">
            <h3 class="card-title"><i class="fa-solid fa-print"></i> بيانات الطباعة الفنية</h3>
            <?php 
            $token = app_job_access_token($conn, $job);
            $order_link = (isset($_SERVER['HTTPS'])?"https":"http")."://".$_SERVER['HTTP_HOST'].str_replace('/modules','',dirname($_SERVER['PHP_SELF']))."/view_order.php?id=$id&token={$token}";
            ?>
            <div style="margin-bottom:25px; display:flex; justify-content:flex-end;">
                <a href="https://wa.me/?text=<?php echo urlencode("أمر تشغيل مطبعة لعملية ({$job['job_name']}):\n$order_link"); ?>" target="_blank" class="btn-simple btn-green" style="padding:10px 20px;">
                    <i class="fa-brands fa-whatsapp" style="font-size:1.2rem;"></i> إرسال أمر الشغل الفني (رابط)
                </a>
            </div>
            
            <form method="POST">
                <div style="background:#111; padding:20px; border-radius:10px; border:1px solid #333; margin-bottom:20px;">
                    <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap:15px;">
                        
                        <div style="display:flex; flex-direction:column;">
                            <label style="color:var(--ae-text-muted); font-size:0.85rem; margin-bottom:5px;">مقاس الورق (طول x عرض):</label>
                            <div style="display:flex; gap:10px; align-items:center;">
                                <input type="number" step="any" name="p_len" value="<?php echo htmlspecialchars($specs['p_len']); ?>" class="p-input" style="margin:0; text-align:center;">
                                <span style="color:#666;">X</span>
                                <input type="number" step="any" name="p_wid" value="<?php echo htmlspecialchars($specs['p_wid']); ?>" class="p-input" style="margin:0; text-align:center;">
                            </div>
                        </div>

                        <div style="display:flex; flex-direction:column;">
                            <label style="color:var(--ae-text-muted); font-size:0.85rem; margin-bottom:5px;">مقاس القص (طول x عرض):</label>
                            <div style="display:flex; gap:10px; align-items:center;">
                                <input type="number" step="any" name="c_len" value="<?php echo htmlspecialchars($specs['c_len']); ?>" class="p-input" style="margin:0; text-align:center;">
                                <span style="color:#666;">X</span>
                                <input type="number" step="any" name="c_wid" value="<?php echo htmlspecialchars($specs['c_wid']); ?>" class="p-input" style="margin:0; text-align:center;">
                            </div>
                        </div>

                        <div style="display:flex; flex-direction:column;">
                            <label style="color:var(--ae-text-muted); font-size:0.85rem; margin-bottom:5px;">الماكينة المستهدفة:</label>
                            <input type="text" name="machine" value="<?php echo htmlspecialchars($specs['machine']); ?>" class="p-input" style="margin:0;">
                        </div>

                        <div style="display:flex; flex-direction:column;">
                            <label style="color:var(--ae-text-muted); font-size:0.85rem; margin-bottom:5px;">عدد الألوان:</label>
                            <input type="text" name="colors" value="<?php echo htmlspecialchars($specs['colors']); ?>" class="p-input" style="margin:0;">
                        </div>

                        <div style="display:flex; flex-direction:column;">
                            <label style="color:var(--ae-text-muted); font-size:0.85rem; margin-bottom:5px;">عدد الزنكات المسحوبة:</label>
                            <input type="number" name="zinc" value="<?php echo htmlspecialchars($specs['zinc']); ?>" class="p-input" style="margin:0;">
                        </div>

                        <div style="display:flex; flex-direction:column;">
                            <label style="color:var(--ae-text-muted); font-size:0.85rem; margin-bottom:5px;">طريقة الوجه (طباعة):</label>
                            <select name="print_face" class="p-input" style="margin:0;">
                                <option value="<?php echo htmlspecialchars($specs['print_face']); ?>"><?php echo htmlspecialchars($specs['print_face'])?:'-- تحديد --'; ?></option>
                                <option>وجه واحد</option>
                                <option>وجهين</option>
                            </select>
                        </div>
                    </div>
                </div>

                <label style="color:var(--ae-text-muted); font-size:0.85rem; margin-bottom:5px; display:block;">تقرير أو ملاحظات فني الطباعة:</label>
                <textarea name="print_notes" class="p-input" rows="3" placeholder="أضف أي ملاحظات تمت أثناء التشغيل..."></textarea>
                
                <button name="save_print_specs" class="btn-liquid"><span class="btn-content">تأكيد الطباعة والانتقال للتشطيب <i class="fa-solid fa-scissors"></i></span></button>
            </form>
        </div>
        <?php endif; ?>

        <?php if($curr == 'finishing'): ?>
        <div class="main-card">
            <h3 class="card-title"><i class="fa-solid fa-scissors"></i> مرحلة التشطيب والتكميلي</h3>
            <div style="background:#111; padding:15px; border-radius:8px; border-left:4px solid var(--ae-gold); margin-bottom:20px;">
                <span style="color:var(--ae-text-muted); font-size:0.85rem;">المواصفات المطلوبة:</span>
                <p style="color:#fff; font-weight:600; margin:5px 0 0 0;"><?php echo htmlspecialchars(get_spec('/التكميلي: (.*?)(?:\||$)/u', $raw_text, 'غير محدد')); ?></p>
            </div>
            
            <form method="POST">
                <label style="color:var(--ae-text-muted); font-size:0.85rem; margin-bottom:5px; display:block;">ملاحظات الجودة والتسليم للعميل:</label>
                <textarea name="finish_notes" class="p-input" rows="4" placeholder="تم التقطيع والتغليف بجودة عالية..."></textarea>
                <button name="finish_stage" class="btn-liquid"><span class="btn-content">اعتماد التشطيب وجاهزية التسليم <i class="fa-solid fa-truck-fast"></i></span></button>
            </form>
        </div>
        <?php endif; ?>

        <?php if($curr == 'delivery'): ?>
        <div class="main-card">
            <h3 class="card-title" style="color:var(--ae-green);"><i class="fa-solid fa-truck-fast"></i> التسليم للعميل</h3>
            
            <div style="background:#111; padding:20px; border-radius:10px; margin-bottom:25px; border:1px solid #222;">
                <h4 style="margin:0 0 15px 0; color:var(--ae-gold);"><i class="fa-solid fa-address-book"></i> بيانات التواصل السريع</h4>
                <div style="display:flex; flex-direction:column; gap:10px; margin-bottom:20px;">
                    <div style="font-size:1.1rem; color:#fff;"><strong>👤 العميل:</strong> <?php echo htmlspecialchars($job['client_name']); ?></div>
                    <div style="font-size:1.1rem; color:#fff;"><strong>📞 رقم الهاتف:</strong> <span style="letter-spacing:1px;"><?php echo htmlspecialchars($job['client_phone']); ?></span></div>
                </div>
                
                <div style="display:flex; gap:12px; flex-wrap:wrap;">
                    <a href="tel:<?php echo htmlspecialchars($job['client_phone']); ?>" class="btn-simple btn-gray" style="flex:1; text-align:center;"><i class="fa-solid fa-phone"></i> اتصال مباشر</a>
                    <a href="<?php echo htmlspecialchars(get_wa_link($job['client_phone'], "السيد/ة {$job['client_name']}، نود إعلامكم بأن طلبكم ({$job['job_name']}) جاهز الآن للاستلام.")); ?>" target="_blank" class="btn-simple btn-green" style="flex:1; text-align:center;"><i class="fa-brands fa-whatsapp"></i> إبلاغ بالجاهزية</a>
                    <a href="<?php echo htmlspecialchars(get_wa_link($job['client_phone'], "يرجى التكرم بإرسال موقعكم (Location) لتنسيق توصيل الطلب.")); ?>" target="_blank" class="btn-simple btn-gray" style="flex:1; text-align:center;"><i class="fa-solid fa-location-dot"></i> طلب موقع (Location)</a>
                </div>
            </div>

            <div style="display:grid; gap:10px;">
                <form method="POST" onsubmit="return confirm('هل أنت متأكد من تسليم العمل وإنشاء فاتورة العملية؟');">
                    <button name="finish_delivery" class="btn-liquid"><span class="btn-content">تأكيد التسليم وإنشاء فاتورة <i class="fa-solid fa-file-invoice-dollar"></i></span></button>
                </form>
                <form method="POST" onsubmit="return confirm('سيتم تحويل العملية للحسابات بدون إنشاء فاتورة تلقائية. متابعة؟');">
                    <button name="finish_delivery_no_invoice" class="btn-simple btn-gray" style="width:100%;"><i class="fa-solid fa-ban"></i> تحويل للحسابات بدون فاتورة</button>
                </form>
                <div style="color:var(--ae-text-muted); font-size:0.88rem;">استخدم الخيار الثاني إذا كانت الفاتورة صادرة بالفعل على ETA أو ستُستورد لاحقاً للنظام.</div>
            </div>
        </div>
        <?php endif; ?>

        <?php if(in_array($curr, ['accounting', 'completed'])): ?>
        <div class="main-card" style="text-align:center; padding:50px 20px;">
            <i class="fa-solid fa-circle-check fa-4x" style="color:var(--ae-green); margin-bottom:20px;"></i>
            <h2 style="color:var(--ae-green); margin-top:0;">تم إنجاز العملية بنجاح</h2>
            <p style="color:var(--ae-text-muted); margin-bottom:30px;">العملية الآن في عهدة القسم المالي أو الأرشيف</p>
            
            <div style="display:flex; justify-content:center; gap:15px; flex-wrap:wrap;">
                <?php if($is_financial): ?>
                    <a href="invoices.php?tab=sales" class="btn-simple btn-gray" style="padding:12px 25px;"><i class="fa-solid fa-calculator"></i> استعراض الملف المالي</a>
                    <?php if($curr == 'accounting'): ?>
                        <form method="POST" style="margin:0;">
                            <button name="archive_job" class="btn-simple btn-red" style="padding:12px 25px;"><i class="fa-solid fa-box-archive"></i> إرسال للأرشيف النهائي</button>
                        </form>
                    <?php endif; ?>
                <?php endif; ?>
                
                <?php if($curr == 'completed'): ?>
                    <form method="POST" style="margin:0;" onsubmit="return confirm('تأكيد إعادة فتح العملية للتعديل؟');">
                        <button name="reopen_job" class="btn-simple btn-gray" style="padding:12px 25px; border-color:var(--ae-gold); color:var(--ae-gold);"><i class="fa-solid fa-lock-open"></i> إعادة فتح العملية</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <?php if($prev_stage && !in_array($curr, ['completed'])): ?>
        <div style="text-align:left; margin-top:30px; border-top:1px solid #222; padding-top:20px;">
            <button onclick="document.getElementById('backModal').style.display='flex'" class="btn-simple btn-gray" style="font-size:0.85rem; color:var(--ae-text-muted);">
                <i class="fa-solid fa-clock-rotate-left"></i> تراجع للمرحلة السابقة
            </button>
        </div>
        <?php endif; ?>

    </div>
</div>

<div id="backModal" class="modal-overlay">
    <div class="modal-box">
        <h3 style="color:var(--ae-red); margin-top:0;"><i class="fa-solid fa-triangle-exclamation"></i> تراجع بخطوة للخلف</h3>
        <p style="color:var(--ae-text-muted); font-size:0.9rem; margin-bottom:20px;">سيتم إعادة العملية للمرحلة السابقة. يرجى كتابة السبب للتوثيق.</p>
        <form method="POST">
            <input type="hidden" name="prev_target" value="<?php echo htmlspecialchars($prev_stage ?? ''); ?>">
            <textarea name="return_reason" required placeholder="سبب التراجع الإداري أو الفني..." class="p-input" style="height:100px; resize:none;"></textarea>
            <div style="display:flex; gap:10px; margin-top:20px;">
                <button name="return_stage" class="btn-simple btn-red" style="flex:1;">تأكيد التراجع</button>
                <button type="button" onclick="document.getElementById('backModal').style.display='none'" class="btn-simple btn-gray" style="flex:1;">إلغاء</button>
            </div>
        </form>
    </div>
</div>

<div id="shareModal" class="modal-overlay">
    <div class="modal-box" style="border-color:#25D366; background:#111;">
        <h3 style="color:#25D366; margin-top:0;"><i class="fa-brands fa-whatsapp fa-lg"></i> مشاركة سريعة</h3>
        <p id="shareFileName" style="color:#ccc; font-size:0.9rem; margin-bottom:20px; font-weight:600; word-break:break-all;"></p>
        
        <label style="display:block; text-align:right; margin-bottom:8px; color:var(--ae-text-muted); font-size:0.85rem;">اختر المورد من القائمة:</label>
        <select id="shareSupplier" class="p-input">
            <option value="">-- القائمة المسجلة --</option>
            <?php echo $suppliers_options; ?>
        </select>
        
        <div style="margin:15px 0; display:flex; align-items:center; color:#555;">
            <hr style="flex:1; border-color:#333;">
            <span style="padding:0 10px; font-size:0.8rem;">أو إدخال يدوي</span>
            <hr style="flex:1; border-color:#333;">
        </div>
        
        <input type="text" id="manualPhone" class="p-input" placeholder="مثال: 01xxxxxxxxx" onchange="document.getElementById('shareSupplier').value = ''">
        
        <div style="display:flex; gap:10px; margin-top:25px;">
            <button onclick="sendWhatsApp()" class="btn-simple" style="flex:1; background:#25D366; color:#000; font-weight:800;">إرسال الآن</button>
            <button onclick="document.getElementById('shareModal').style.display='none'" class="btn-simple btn-gray" style="flex:1;">إلغاء</button>
        </div>
    </div>
</div>

<script>
// سكربت الترجمة والأمان المضمن
const moduleI18n = <?php echo json_encode($moduleI18n, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
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
if (Object.keys(moduleI18n).length > 0) {
    const translateString = (text) => {
        let out = text;
        for (const [ar, en] of Object.entries(moduleI18n)) {
            out = out.split(ar).join(en);
        }
        return out;
    };
    const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT, {
        acceptNode(node) {
            if (!node || !node.nodeValue || !/[ء-ي]/.test(node.nodeValue)) return NodeFilter.FILTER_REJECT;
            const tag = node.parentElement ? node.parentElement.tagName : "";
            if (tag === "SCRIPT" || tag === "STYLE") return NodeFilter.FILTER_REJECT;
            return NodeFilter.FILTER_ACCEPT;
        }
    });
    const nodes = [];
    while (walker.nextNode()) nodes.push(walker.currentNode);
    nodes.forEach((n) => { n.nodeValue = translateString(n.nodeValue); });
    document.querySelectorAll("input,textarea,button,a,label,option").forEach((el) => {
        ["placeholder", "title", "aria-label", "value"].forEach((attr) => {
            const val = el.getAttribute(attr);
            if (val && /[ء-ي]/.test(val)) el.setAttribute(attr, translateString(val));
        });
    });
}
</script>

<?php if (!$app_module_embedded): ?>
</body>
</html>
<?php endif; ?>
<?php
if (ob_get_level() > $print_module_initial_buffer_level) {
    $print_module_output = ob_get_clean();
    echo $print_module_output;
}
?>
