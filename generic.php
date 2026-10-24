<?php
// modules/generic.php
// Dynamic fallback module for custom operation types.

error_reporting(E_ALL);
@ini_set('upload_max_filesize', '2048M');
@ini_set('post_max_size', '2048M');
@ini_set('max_execution_time', '3600');
@ini_set('max_input_time', '3600');
@ini_set('memory_limit', '2048M');
require_once __DIR__ . '/modules/jobs/event_log_runtime.php';

if (!isset($conn, $job) || !is_array($job) || !isset($job['id'])) {
    http_response_code(403);
    exit('Forbidden');
}

if (!function_exists('generic_module_redirect')) {
    function generic_module_redirect(int $id): void
    {
        if (!headers_sent()) {
            header('Location: job_details.php?id=' . $id);
            exit;
        }
        echo "<script>window.location.href = 'job_details.php?id={$id}';</script>";
        exit;
    }
}
if (!function_exists('generic_module_is_ajax_request')) {
    function generic_module_is_ajax_request(): bool
    {
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
}
if (!function_exists('generic_module_finish_request')) {
    function generic_module_finish_request(int $id, array $payload = []): void
    {
        if (generic_module_is_ajax_request()) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }
        generic_module_redirect($id);
    }
}
if (!function_exists('generic_module_sync_rollup')) {
    function generic_module_sync_rollup(mysqli $conn, int $jobId, array $workflow, string $currentStage, string $summary, int $userId, string $userName): void
    {
        if ($jobId <= 0 || $currentStage === '' || !isset($workflow[$currentStage])) {
            return;
        }
        $row = ['cnt' => 0];
        $stmtCount = $conn->prepare("SELECT COUNT(*) AS cnt FROM job_files WHERE job_id = ? AND stage = ?");
        if ($stmtCount) {
            $stmtCount->bind_param('is', $jobId, $currentStage);
            $stmtCount->execute();
            $row = $stmtCount->get_result()->fetch_assoc() ?: $row;
            $stmtCount->close();
        }
        $filesCount = (string)($row['cnt'] ?? 0);
        $stageKeys = array_keys($workflow);
        $startIndex = array_search($currentStage, $stageKeys, true);
        if ($startIndex === false) {
            return;
        }
        for ($i = $startIndex; $i < count($stageKeys); $i++) {
            $stageKey = (string)$stageKeys[$i];
            app_stage_data_set($conn, $jobId, $stageKey, 'stage_update_summary', $summary, $userId, $userName);
            app_stage_data_set($conn, $jobId, $stageKey, 'stage_files_count', $filesCount, $userId, $userName);
        }
    }
}
if (!function_exists('generic_module_append_job_note')) {
    function generic_module_append_job_note(mysqli $conn, int $jobId, string $note): void
    {
        if ($jobId <= 0 || $note === '') {
            return;
        }
        $stmt = $conn->prepare("UPDATE job_orders SET notes = CONCAT(IFNULL(notes, ''), ?) WHERE id = ?");
        if ($stmt) {
            $stmt->bind_param('si', $note, $jobId);
            $stmt->execute();
            $stmt->close();
        }
    }
}
if (!function_exists('generic_module_insert_job_file')) {
    function generic_module_insert_job_file(mysqli $conn, int $jobId, string $path, string $stage, string $description, string $uploadedBy): bool
    {
        if ($jobId <= 0 || $path === '') {
            return false;
        }
        $stmt = $conn->prepare("INSERT INTO job_files (job_id, file_path, stage, description, uploaded_by) VALUES (?, ?, ?, ?, ?)");
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('issss', $jobId, $path, $stage, $description, $uploadedBy);
        $ok = $stmt->execute();
        $stmt->close();
        return (bool)$ok;
    }
}
if (!function_exists('generic_module_fetch_job_files')) {
    function generic_module_fetch_job_files(mysqli $conn, int $jobId, ?string $stage = null): array
    {
        if ($jobId <= 0) {
            return [];
        }
        $rows = [];
        if ($stage === null) {
            $stmt = $conn->prepare("SELECT * FROM job_files WHERE job_id = ? ORDER BY id DESC");
            if ($stmt) {
                $stmt->bind_param('i', $jobId);
            }
        } else {
            $stmt = $conn->prepare("SELECT * FROM job_files WHERE job_id = ? AND stage = ? ORDER BY id DESC");
            if ($stmt) {
                $stmt->bind_param('is', $jobId, $stage);
            }
        }
        if (!$stmt) {
            return [];
        }
        $stmt->execute();
        $res = $stmt->get_result();
        while ($res && ($row = $res->fetch_assoc())) {
            $rows[] = $row;
        }
        $stmt->close();
        return $rows;
    }
}

$jobTypeKey = strtolower(trim((string)($job['job_type'] ?? '')));
$operationTitle = $jobTypeKey !== '' ? $jobTypeKey : 'operation';
foreach (app_operation_types($conn, false) as $typeRow) {
    if ((string)($typeRow['type_key'] ?? '') === $jobTypeKey) {
        $operationTitle = trim((string)($typeRow['type_name'] ?? '')) !== ''
            ? (string)$typeRow['type_name']
            : $operationTitle;
        break;
    }
}

$fallbackWorkflowLabels = [
    'briefing' => '1. التجهيز',
    'completed' => '2. الأرشيف',
];
$workflow = app_operation_workflow($conn, $jobTypeKey, $fallbackWorkflowLabels);
$allowedStageKeys = array_keys($workflow);
$firstStage = (string)array_key_first($workflow);
if ($firstStage === '') {
    $firstStage = 'briefing';
}
$curr = app_workflow_current_stage($workflow, (string)($job['current_stage'] ?? ''), $firstStage);
$prevStage = $workflow[$curr]['prev'] ?? null;
$nextStage = $workflow[$curr]['next'] ?? null;
$archiveStage = isset($workflow['completed']) ? 'completed' : ((string)array_key_last($workflow) ?: $curr);
$dynamicStageFields = app_operation_field_defs($conn, $jobTypeKey, $curr, true);
$dynamicStageValues = [];
foreach ($dynamicStageFields as $fieldDef) {
    $fieldKey = (string)($fieldDef['field_key'] ?? '');
    if ($fieldKey === '') {
        continue;
    }
    $dynamicStageValues[$fieldKey] = app_stage_data_get($conn, (int)$job['id'], $curr, $fieldKey, (string)($fieldDef['default_value'] ?? ''));
}

$canForceControl = app_user_can('jobs.manage_all');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!app_verify_csrf($_POST['_csrf_token'] ?? '')) {
        http_response_code(403);
        exit('Invalid CSRF token');
    }

    $userName = (string)($_SESSION['name'] ?? 'System');
    $safeCurr = $conn->real_escape_string($curr);

    if (isset($_POST['add_internal_comment'])) {
        $comment = trim((string)($_POST['comment_text'] ?? ''));
        if ($comment !== '') {
            $stamp = date('Y-m-d H:i');
            $note = "\n[تعليق {$userName} ({$stamp})]: {$comment}";
            generic_module_append_job_note($conn, (int)$job['id'], $note);
        }
        generic_module_finish_request((int)$job['id'], [
            'ok' => true,
            'message' => app_tr('تم حفظ تحديث المرحلة بنجاح.', 'Stage update saved successfully.'),
            'reload' => true,
            'redirect' => 'job_details.php?id=' . (int)$job['id'],
        ]);
    }

    if (isset($_POST['save_stage_update'])) {
        $stageNote = trim((string)($_POST['stage_note'] ?? ''));
        $postedDynamicFields = is_array($_POST['dynamic_field'] ?? null) ? $_POST['dynamic_field'] : [];
        $summaryParts = [];
        foreach ($dynamicStageFields as $fieldDef) {
            $fieldKey = (string)($fieldDef['field_key'] ?? '');
            if ($fieldKey === '') {
                continue;
            }
            $fieldValue = $postedDynamicFields[$fieldKey] ?? '';
            if (is_array($fieldValue)) {
                $fieldValue = implode(' + ', array_values(array_filter(array_map('trim', $fieldValue), static function ($value): bool {
                    return $value !== '';
                })));
            }
            $fieldValue = trim((string)$fieldValue);
            if ((int)($fieldDef['is_required'] ?? 0) === 1 && $fieldValue === '') {
                if (generic_module_is_ajax_request()) {
                    http_response_code(422);
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode([
                        'ok' => false,
                        'error' => app_tr('يوجد حقل مطلوب غير مكتمل.', 'A required field is missing.'),
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    exit;
                }
                http_response_code(422);
                exit('Required field missing');
            }
            app_stage_data_set($conn, (int)$job['id'], $curr, $fieldKey, $fieldValue, (int)($_SESSION['user_id'] ?? 0));
            if ($fieldValue !== '') {
                $label = trim((string)($fieldDef['label_ar'] ?? $fieldDef['label_en'] ?? $fieldKey));
                $summaryParts[] = $label . ': ' . $fieldValue;
            }
        }
        if ($stageNote !== '') {
            $stageLabel = (string)($workflow[$curr]['label'] ?? $curr);
            generic_module_append_job_note($conn, (int)$job['id'], "\n[تحديث {$stageLabel}]: {$stageNote}");
            $summaryParts[] = app_tr('ملاحظات', 'Notes') . ': ' . $stageNote;
        }

        if (isset($_FILES['stage_files']) && is_array($_FILES['stage_files']['name'] ?? null)) {
            $count = count($_FILES['stage_files']['name']);
            for ($i = 0; $i < $count; $i++) {
                $singleFile = [
                    'name' => $_FILES['stage_files']['name'][$i] ?? '',
                    'type' => $_FILES['stage_files']['type'][$i] ?? '',
                    'tmp_name' => $_FILES['stage_files']['tmp_name'][$i] ?? '',
                    'error' => $_FILES['stage_files']['error'][$i] ?? UPLOAD_ERR_NO_FILE,
                    'size' => $_FILES['stage_files']['size'][$i] ?? 0,
                ];
                if ((int)$singleFile['error'] === UPLOAD_ERR_NO_FILE) {
                    continue;
                }

                $stored = app_store_uploaded_file($singleFile, [
                    'dir' => 'uploads/generic',
                    'prefix' => 'job_' . (int)$job['id'] . '_',
                    'max_size' => 2048 * 1024 * 1024,
                ]);
                if (!$stored['ok']) {
                    continue;
                }

                $fileDescList = $_POST['file_desc'] ?? [];
                $desc = trim((string)($fileDescList[$i] ?? ''));
                if ($desc === '') {
                    $desc = 'ملف مرحلة ' . ($workflow[$curr]['label'] ?? $curr);
                }
                generic_module_insert_job_file($conn, (int)$job['id'], (string)$stored['path'], $curr, $desc, $userName);
                job_event_log_add(
                    $conn,
                    (int)$job['id'],
                    'attachment_uploaded',
                    'رفع مرفق على العملية',
                    'تم رفع ملف وربطه بمرحلة من مراحل العملية.',
                    [
                        'stage_key' => $curr,
                        'file_path' => (string)$stored['path'],
                        'description' => $desc,
                        'original_name' => (string)($singleFile['name'] ?? ''),
                    ],
                    $curr,
                    (int)($_SESSION['user_id'] ?? 0),
                    $userName
                );
            }
        }

        generic_module_sync_rollup(
            $conn,
            (int)$job['id'],
            $workflow,
            $curr,
            trim(implode(' | ', array_filter($summaryParts))),
            (int)($_SESSION['user_id'] ?? 0),
            $userName
        );

        generic_module_finish_request((int)$job['id'], [
            'ok' => true,
            'message' => app_tr('تم حفظ تحديث المرحلة بنجاح.', 'Stage update saved successfully.'),
            'reload' => true,
            'redirect' => 'job_details.php?id=' . (int)$job['id'],
        ]);
    }

    if (isset($_POST['delete_file'])) {
        $fileId = (int)($_POST['file_id'] ?? 0);
        if ($fileId > 0) {
            $jobIdInt = (int)$job['id'];
            $fileRow = null;
            $stmtFile = $conn->prepare("SELECT file_path, stage, description, uploaded_by FROM job_files WHERE id = ? AND job_id = ? LIMIT 1");
            if ($stmtFile) {
                $stmtFile->bind_param('ii', $fileId, $jobIdInt);
                $stmtFile->execute();
                $fileRow = $stmtFile->get_result()->fetch_assoc();
                $stmtFile->close();
            }
            $filePath = (string)($fileRow['file_path'] ?? '');
            if ($filePath !== '' && strpos($filePath, 'uploads/') === 0) {
                app_safe_unlink($filePath, __DIR__ . '/../uploads');
            }
            $stmtDel = $conn->prepare("DELETE FROM job_files WHERE id = ? AND job_id = ?");
            if ($stmtDel) {
                $stmtDel->bind_param('ii', $fileId, $jobIdInt);
                $stmtDel->execute();
                $stmtDel->close();
            }
            job_event_log_add(
                $conn,
                (int)$job['id'],
                'attachment_deleted',
                'حذف مرفق من العملية',
                'تم حذف ملف من مرفقات العملية.',
                [
                    'file_id' => $fileId,
                    'file_path' => $filePath,
                    'stage_key' => (string)($fileRow['stage'] ?? ''),
                    'description' => (string)($fileRow['description'] ?? ''),
                    'uploaded_by' => (string)($fileRow['uploaded_by'] ?? ''),
                ],
                (string)($fileRow['stage'] ?? ''),
                (int)($_SESSION['user_id'] ?? 0),
                $userName
            );
        }
        generic_module_finish_request((int)$job['id'], [
            'ok' => true,
            'message' => app_tr('تم حذف الملف بنجاح.', 'File deleted successfully.'),
            'reload' => true,
            'redirect' => 'job_details.php?id=' . (int)$job['id'],
        ]);
    }

    if (isset($_POST['force_stage_change']) && $canForceControl) {
        $targetStage = trim((string)($_POST['target_stage'] ?? ''));
        if ($targetStage !== '' && in_array($targetStage, $allowedStageKeys, true)) {
            app_update_job_stage($conn, (int)$job['id'], $targetStage);
        }
        generic_module_redirect((int)$job['id']);
    }

    if (isset($_POST['move_prev']) && $prevStage !== null) {
        app_update_job_stage($conn, (int)$job['id'], (string)$prevStage);
        generic_module_redirect((int)$job['id']);
    }

    if (isset($_POST['move_next']) && $nextStage !== null) {
        app_update_job_stage($conn, (int)$job['id'], (string)$nextStage);
        generic_module_redirect((int)$job['id']);
    }

    if (isset($_POST['return_stage'])) {
        $targetPrev = trim((string)($_POST['prev_target'] ?? ''));
        if ($targetPrev !== '' && in_array($targetPrev, $allowedStageKeys, true)) {
            $reason = trim((string)($_POST['return_reason'] ?? ''));
                if ($reason !== '') {
                    $safeReason = $conn->real_escape_string($reason);
                    $note = "\n[⚠️ تراجع]: {$safeReason}";
                    $stmt = $conn->prepare("UPDATE job_orders SET current_stage = ?, status = ?, notes = CONCAT(IFNULL(notes, ''), ?) WHERE id = ?");
                    if ($stmt) {
                        $processingStatus = app_job_stage_implied_status($targetPrev, 'processing');
                        $stmt->bind_param('sssi', $targetPrev, $processingStatus, $note, $job['id']);
                        $stmt->execute();
                        $stmt->close();
                    }
                } else {
                    app_update_job_stage($conn, (int)$job['id'], $targetPrev);
                }
            }
        generic_module_redirect((int)$job['id']);
    }

    if (isset($_POST['archive_job'])) {
        app_update_job_stage($conn, (int)$job['id'], $archiveStage, 'completed');
        generic_module_redirect((int)$job['id']);
    }

    if (isset($_POST['reopen_job'])) {
        app_update_job_stage($conn, (int)$job['id'], $firstStage, 'processing');
        generic_module_redirect((int)$job['id']);
    }
}

$allFiles = generic_module_fetch_job_files($conn, (int)$job['id']);
$currentStageFiles = generic_module_fetch_job_files($conn, (int)$job['id'], $curr);
?>

<style>
    .g-wrap { display: grid; grid-template-columns: 320px 1fr; gap: 16px; }
    .g-side, .g-main-card { background: #151515; border: 1px solid #2d2d2d; border-radius: 12px; padding: 14px; }
    .g-title { margin: 0 0 10px; color: var(--gold-primary, #d4af37); }
    .g-sub { color: #a8a8a8; font-size: 0.88rem; margin-bottom: 10px; }
    .g-pill-wrap { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 12px; }
    .g-pill { background: #2b2b2b; color: #8f8f8f; padding: 6px 12px; border-radius: 999px; font-size: 0.82rem; }
    .g-pill.active { background: var(--gold-primary, #d4af37); color: #111; font-weight: 800; }
    .g-row { margin-bottom: 10px; }
    .g-label { display: block; font-size: 0.82rem; color: #bbbbbb; margin-bottom: 5px; }
    .g-input, .g-textarea, .g-select { width: 100%; background: #0d0d0d; color: #fff; border: 1px solid #343434; border-radius: 8px; padding: 10px; font-family: 'Cairo', sans-serif; }
    .g-textarea { min-height: 92px; resize: vertical; }
    .g-btn { border: 1px solid #3f3f3f; border-radius: 8px; padding: 9px 12px; cursor: pointer; font-family: 'Cairo', sans-serif; font-weight: 700; }
    .g-btn-gold { background: linear-gradient(130deg, var(--gold-primary, #d4af37), #ad8529); color: #111; }
    .g-btn-red { background: rgba(231,76,60,0.18); color: #ffb7af; border-color: rgba(231,76,60,0.5); }
    .g-btn-gray { background: #2a2a2a; color: #d2d2d2; }
    .g-actions { display: flex; gap: 8px; flex-wrap: wrap; }
    .g-file { display: flex; align-items: center; justify-content: space-between; gap: 8px; background: #0b0b0b; border: 1px solid #2f2f2f; border-radius: 8px; padding: 8px; margin-bottom: 7px; }
    .g-file a { color: #e8e8e8; text-decoration: none; }
    .g-file-tag { font-size: 0.74rem; color: #909090; }
    .g-note { background: #0d0d0d; border: 1px dashed #373737; color: #bfbfbf; border-radius: 10px; padding: 10px; font-size: 0.86rem; }
    @media (max-width: 980px) { .g-wrap { grid-template-columns: 1fr; } }
</style>

<div class="g-wrap">
    <aside class="g-side">
        <h3 class="g-title">⚙️ <?php echo app_h($operationTitle); ?></h3>
        <div class="g-sub"><?php echo app_h(app_tr('موديول ديناميكي لنوع العملية الحالي', 'Dynamic module for the current operation type')); ?></div>

        <div class="g-row">
            <span class="g-label"><?php echo app_h(app_tr('العملية', 'Operation')); ?></span>
            <div class="g-note"><?php echo app_h((string)($job['job_name'] ?? '-')); ?></div>
        </div>
        <div class="g-row">
            <span class="g-label"><?php echo app_h(app_tr('المرحلة الحالية', 'Current stage')); ?></span>
            <div class="g-note"><?php echo app_h((string)($workflow[$curr]['label'] ?? $curr)); ?></div>
        </div>

        <form method="post" class="g-row">
            <?php echo app_csrf_input(); ?>
            <label class="g-label"><?php echo app_h(app_tr('تعليق داخلي', 'Internal comment')); ?></label>
            <textarea class="g-textarea" name="comment_text" placeholder="<?php echo app_h(app_tr('أضف ملاحظة للفريق...', 'Add a note for the team...')); ?>"></textarea>
            <button class="g-btn g-btn-gray" name="add_internal_comment" type="submit" style="margin-top:8px; width:100%;"><?php echo app_h(app_tr('إضافة التعليق', 'Add comment')); ?></button>
        </form>

        <div class="g-row">
            <div class="g-actions">
                <?php if ($prevStage !== null): ?>
                <form method="post" style="flex:1;">
                    <?php echo app_csrf_input(); ?>
                    <button class="g-btn g-btn-gray" name="move_prev" type="submit" style="width:100%;"><?php echo app_h(app_tr('« المرحلة السابقة', '« Previous stage')); ?></button>
                </form>
                <?php endif; ?>
                <?php if ($nextStage !== null): ?>
                <form method="post" style="flex:1;">
                    <?php echo app_csrf_input(); ?>
                    <button class="g-btn g-btn-gold" name="move_next" type="submit" style="width:100%;"><?php echo app_h(app_tr('المرحلة التالية »', 'Next stage »')); ?></button>
                </form>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($canForceControl): ?>
        <form method="post" class="g-row">
            <?php echo app_csrf_input(); ?>
            <label class="g-label"><?php echo app_h(app_tr('تجاوز إداري للمرحلة', 'Administrative stage override')); ?></label>
            <select class="g-select" name="target_stage">
                <?php foreach ($workflow as $stageKey => $stageData): ?>
                    <option value="<?php echo app_h($stageKey); ?>" <?php echo $stageKey === $curr ? 'selected' : ''; ?>><?php echo app_h((string)$stageData['label']); ?></option>
                <?php endforeach; ?>
            </select>
            <button class="g-btn g-btn-red" name="force_stage_change" type="submit" style="margin-top:8px; width:100%;"><?php echo app_h(app_tr('تغيير المرحلة', 'Change stage')); ?></button>
        </form>
        <?php endif; ?>

        <div class="g-actions" style="margin-top:10px;">
            <form method="post" style="flex:1;">
                <?php echo app_csrf_input(); ?>
                <button class="g-btn g-btn-red" type="submit" name="archive_job" style="width:100%;"><?php echo app_h(app_tr('أرشفة', 'Archive')); ?></button>
            </form>
            <form method="post" style="flex:1;">
                <?php echo app_csrf_input(); ?>
                <button class="g-btn g-btn-gray" type="submit" name="reopen_job" style="width:100%;"><?php echo app_h(app_tr('إعادة فتح', 'Reopen')); ?></button>
            </form>
        </div>
    </aside>

    <section>
        <div class="g-main-card">
            <div class="g-pill-wrap">
                <?php foreach ($workflow as $stageKey => $stageData): ?>
                    <div class="g-pill <?php echo $stageKey === $curr ? 'active' : ''; ?>"><?php echo app_h((string)$stageData['label']); ?></div>
                <?php endforeach; ?>
            </div>

            <h3 class="g-title">🧩 <?php echo app_h(app_tr('تحديث المرحلة الحالية', 'Update current stage')); ?></h3>
            <div class="app-ai-panel" data-job-id="<?php echo (int)$job['id']; ?>" data-csrf="<?php echo app_h(app_csrf_token()); ?>" data-context="generic_stage_plan" data-item-count="1" data-stage-label="<?php echo app_h((string)($workflow[$curr]['label'] ?? $curr)); ?>" data-target-selector="textarea[name='stage_note']" data-apply-mode="fill-single" style="margin-bottom:15px;">
                <div class="app-ai-head">
                    <div class="app-ai-title"><?php echo app_h(app_tr('مساعد AI للمرحلة', 'AI stage helper')); ?></div>
                    <div class="app-ai-note"><?php echo app_h(app_tr('يبني خطة تنفيذ ومخرجات للمرحلة الحالية', 'Builds an execution plan for the current stage')); ?></div>
                </div>
                <textarea class="app-ai-seed" placeholder="<?php echo app_h(app_tr('أدخل ما تعرفه عن المطلوب في هذه المرحلة...', 'Enter what you know about this stage...')); ?>"></textarea>
                <div class="app-ai-actions">
                    <button type="button" class="app-ai-btn app-ai-btn-primary app-ai-generate"><?php echo app_h(app_tr('توليد خطة المرحلة', 'Generate stage plan')); ?></button>
                </div>
                <div class="app-ai-status"></div>
                <div class="app-ai-results"></div>
            </div>
            <form method="post" enctype="multipart/form-data" class="op-async-form" data-upload-progress="1">
                <?php echo app_csrf_input(); ?>
                <?php if (!empty($dynamicStageFields)): ?>
                <div class="g-row">
                    <label class="g-label"><?php echo app_h(app_tr('مدخلات المرحلة', 'Stage Inputs')); ?></label>
                    <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(240px,1fr)); gap:10px;">
                        <?php foreach ($dynamicStageFields as $fieldDef): ?>
                            <?php
                            $fieldKey = (string)($fieldDef['field_key'] ?? '');
                            $fieldLabel = (string)($fieldDef['field_label'] ?? $fieldKey);
                            $inputType = (string)($fieldDef['input_type'] ?? 'text');
                            $placeholder = (string)($fieldDef['placeholder'] ?? '');
                            $fieldValue = (string)($dynamicStageValues[$fieldKey] ?? '');
                            $isRequired = (int)($fieldDef['is_required'] ?? 0) === 1;
                            ?>
                            <div>
                                <label class="g-label"><?php echo app_h($fieldLabel); ?><?php echo $isRequired ? ' *' : ''; ?></label>
                                <?php if ($inputType === 'textarea'): ?>
                                    <textarea class="g-textarea" name="dynamic_field[<?php echo app_h($fieldKey); ?>]" placeholder="<?php echo app_h($placeholder); ?>" <?php echo $isRequired ? 'required' : ''; ?>><?php echo app_h($fieldValue); ?></textarea>
                                <?php elseif ($inputType === 'select'): ?>
                                    <select class="g-select" name="dynamic_field[<?php echo app_h($fieldKey); ?>]" <?php echo $isRequired ? 'required' : ''; ?>>
                                        <option value=""><?php echo app_h(app_tr('اختر', 'Select')); ?></option>
                                        <?php foreach ((array)($fieldDef['options'] ?? []) as $optionLabel): ?>
                                            <option value="<?php echo app_h((string)$optionLabel); ?>" <?php echo $fieldValue === (string)$optionLabel ? 'selected' : ''; ?>><?php echo app_h((string)$optionLabel); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php else: ?>
                                    <input class="g-input" type="<?php echo app_h(in_array($inputType, ['number', 'date'], true) ? $inputType : 'text'); ?>" name="dynamic_field[<?php echo app_h($fieldKey); ?>]" value="<?php echo app_h($fieldValue); ?>" placeholder="<?php echo app_h($placeholder); ?>" <?php echo $isRequired ? 'required' : ''; ?>>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
                <div class="g-row">
                    <label class="g-label"><?php echo app_h(app_tr('ملاحظة المرحلة', 'Stage note')); ?></label>
                    <textarea class="g-textarea" name="stage_note" placeholder="<?php echo app_h(app_tr('ماذا تم في هذه المرحلة؟', 'What was done in this stage?')); ?>"></textarea>
                </div>
                <div class="g-row">
                    <label class="g-label"><?php echo app_h(app_tr('ملفات المرحلة (متعدد)', 'Stage files (multiple)')); ?></label>
                    <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
                        <input class="g-input" type="file" name="stage_files[]" multiple style="flex:1 1 320px;">
                        <button class="g-btn" type="submit" name="save_stage_update" style="width:auto; white-space:nowrap;"><?php echo app_h(app_tr('رفع الملفات', 'Upload files')); ?></button>
                    </div>
                </div>
                <div class="g-row">
                    <label class="g-label"><?php echo app_h(app_tr('وصف عام للملفات (اختياري)', 'General file description (optional)')); ?></label>
                    <input class="g-input" type="text" name="file_desc[0]" placeholder="<?php echo app_h(app_tr('مثال: ملفات تنفيذ أولية', 'Example: initial execution files')); ?>">
                </div>
                <button class="g-btn g-btn-gold" type="submit" name="save_stage_update"><?php echo app_h(app_tr('حفظ تحديث المرحلة', 'Save stage update')); ?></button>
                <input type="hidden" name="__async_form" value="1">
                <div class="op-form-status" style="display:none; margin-top:10px; font-size:0.9rem;"></div>
                <div class="op-form-progress" style="display:none; margin-top:10px;"><div style="height:8px; background:#222; border-radius:999px; overflow:hidden;"><div class="op-form-progress-bar" style="width:0%; height:8px; background:linear-gradient(90deg,#c79c2f,#f4d269);"></div></div><div class="op-form-progress-text" style="margin-top:6px; color:#aaa; font-size:0.85rem;"><?php echo app_h(app_tr('جاري الرفع...', 'Uploading...')); ?></div></div>
            </form>

            <?php if ($prevStage !== null): ?>
            <form method="post" style="margin-top:10px;">
                <?php echo app_csrf_input(); ?>
                <input type="hidden" name="prev_target" value="<?php echo app_h($prevStage); ?>">
                <div class="g-row">
                    <label class="g-label"><?php echo app_h(app_tr('سبب التراجع (اختياري)', 'Return reason (optional)')); ?></label>
                    <input class="g-input" type="text" name="return_reason" placeholder="<?php echo app_h(app_tr('مثال: تعديل من العميل', 'Example: client requested revision')); ?>">
                </div>
                <button class="g-btn g-btn-red" type="submit" name="return_stage"><?php echo app_h(app_tr('رجوع للمرحلة السابقة', 'Return to previous stage')); ?></button>
            </form>
            <?php endif; ?>
        </div>

        <div class="g-main-card" style="margin-top:12px;">
            <h3 class="g-title">📎 <?php echo app_h(app_tr('ملفات المرحلة الحالية', 'Current stage files')); ?></h3>
            <?php if (!empty($currentStageFiles)): ?>
                <?php foreach ($currentStageFiles as $f): ?>
                    <div class="g-file">
                        <div>
                            <a href="<?php echo app_h((string)$f['file_path']); ?>" target="_blank"><?php echo app_h((string)($f['description'] ?: basename((string)$f['file_path']))); ?></a>
                            <div class="g-file-tag"><?php echo app_h((string)$f['stage']); ?></div>
                        </div>
                        <form method="post">
                            <?php echo app_csrf_input(); ?>
                            <input type="hidden" name="file_id" value="<?php echo (int)$f['id']; ?>">
                            <input type="hidden" name="__async_form" value="1"><button class="g-btn g-btn-red" type="submit" name="delete_file"><?php echo app_h(app_tr('حذف', 'Delete')); ?></button>
                        </form>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="g-note"><?php echo app_h(app_tr('لا توجد ملفات لهذه المرحلة حتى الآن.', 'No files for this stage yet.')); ?></div>
            <?php endif; ?>
        </div>

        <div class="g-main-card" style="margin-top:12px;">
            <h3 class="g-title">🗂️ <?php echo app_h(app_tr('كل ملفات العملية', 'All job files')); ?></h3>
            <?php if (!empty($allFiles)): ?>
                <?php foreach ($allFiles as $f): ?>
                    <div class="g-file">
                        <div>
                            <a href="<?php echo app_h((string)$f['file_path']); ?>" target="_blank"><?php echo app_h((string)($f['description'] ?: basename((string)$f['file_path']))); ?></a>
                            <div class="g-file-tag"><?php echo app_h((string)$f['stage']); ?> • <?php echo app_h((string)$f['uploaded_by']); ?></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="g-note"><?php echo app_h(app_tr('لا توجد ملفات مرفوعة لهذه العملية.', 'No uploaded files for this job.')); ?></div>
            <?php endif; ?>
        </div>
    </section>
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
                        const fallbackError = xhr.status === 413
                            ? 'حجم الملفات أكبر من حد الرفع المسموح. ارفع الملفات على دفعات.'
                            : 'تعذر تنفيذ العملية. قد يكون الرد غير مكتمل بسبب حجم الرفع أو انتهاء الجلسة.';
                        statusBox.style.color = '#d98c8c';
                        statusBox.textContent = (payload && payload.error) ? payload.error : fallbackError;
                    }
                    if (progressWrap) progressWrap.style.display = 'none';
                };
                xhr.send(data);
            });
        });
    })();
</script>
