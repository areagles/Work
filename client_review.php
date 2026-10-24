<?php
// client_review.php - (Arab Eagles Portal V52.2 - Watermarked Edition)
require 'config.php';

$appName = app_setting_get($conn, 'app_name', 'Arab Eagles');

// --- 1. التحقق من التوكن والأمان ---
if(!isset($_GET['token']) || empty($_GET['token'])) 
    die("<div style='background:#000;height:100vh;display:flex;align-items:center;justify-content:center;'><h3 style='color:#d4af37;font-family:Cairo;'>الرابط غير صالح</h3></div>");

$token = trim((string)$_GET['token']);
$safeToken = rawurlencode($token);

// --- [هام جداً] إصلاح ترميز الأحرف لقراءة العربية بشكل صحيح ---
$conn->set_charset("utf8mb4");

// جلب بيانات الطلب والعميل
try {
    $stmt_job = $conn->prepare("SELECT j.*, c.name as client_name FROM job_orders j JOIN clients c ON j.client_id = c.id WHERE j.access_token = ? LIMIT 1");
    if (!$stmt_job) {
        throw new RuntimeException('Failed to prepare job lookup statement.');
    }
    $stmt_job->bind_param("s", $token);
    $stmt_job->execute();
    $res = $stmt_job->get_result();
} catch (Throwable $e) {
    error_log('client_review token lookup failed: ' . $e->getMessage());
    die("<div style='background:#000;height:100vh;display:flex;align-items:center;justify-content:center;'><h3 style='color:#d4af37;font-family:Cairo;'>تعذر فتح بوابة المراجعة حالياً</h3></div>");
}

if($res->num_rows == 0) 
    die("<div style='background:#000;height:100vh;display:flex;align-items:center;justify-content:center;'><h3 style='color:#d4af37;font-family:Cairo;'>الرابط منتهي أو غير صحيح</h3></div>");

$job = $res->fetch_assoc();
$stmt_job->close();
$job_id = (int)$job['id'];
$job_type = strtolower(trim((string)($job['job_type'] ?? 'print')));
$fallback_workflow_labels = [
    'briefing' => 'التجهيز',
    'idea_review' => 'مراجعة الأفكار',
    'content_review' => 'مراجعة المحتوى',
    'design_review' => 'مراجعة التصميم',
    'client_rev' => 'مراجعة العميل',
    'handover' => 'التسليم',
    'testing' => 'الاختبار',
    'launch' => 'الإطلاق',
    'delivery' => 'التسليم',
    'accounting' => 'الحسابات',
    'completed' => 'الأرشيف'
];
$workflow = app_operation_workflow($conn, $job_type, $fallback_workflow_labels);
$first_stage = (string)(array_key_first($workflow) ?? 'briefing');
$curr = app_workflow_current_stage($workflow, (string)($job['current_stage'] ?? ''), $first_stage);
$client_name = $job['client_name'];
$review_error = '';
$current_stage_label = (string)($workflow[$curr]['label'] ?? ($fallback_workflow_labels[$curr] ?? $curr));
$prev_stage_key = (string)($workflow[$curr]['prev'] ?? $curr);
$next_stage_key = (string)($workflow[$curr]['next'] ?? $curr);
$workflow_keys = array_keys($workflow);
$workflow_total = count($workflow_keys) > 0 ? count($workflow_keys) : 1;
$workflow_index = array_search($curr, $workflow_keys, true);
if ($workflow_index === false) {
    $workflow_index = 0;
}
$workflow_percent = (int)round((($workflow_index + 1) / $workflow_total) * 100);

function client_review_social_mode(string $stage): ?string
{
    $normalized = strtolower(trim($stage));
    if ($normalized === '') {
        return null;
    }
    if (strpos($normalized, 'idea') !== false) {
        return 'idea';
    }
    if (strpos($normalized, 'content') !== false || strpos($normalized, 'copy') !== false) {
        return 'content';
    }
    if (strpos($normalized, 'design') !== false || strpos($normalized, 'client_rev') !== false) {
        return 'design';
    }
    return null;
}
$social_review_mode = ($job_type === 'social') ? client_review_social_mode($curr) : null;

function stage_reason_suggestions(string $stage): array
{
    $normalized = strtolower(trim($stage));
    switch ($normalized) {
        case 'idea':
        case 'idea_review':
            return [
                'الفكرة ممتازة ولكن تحتاج زاوية أكثر ارتباطاً بالخدمة.',
                'يفضل تبسيط الرسالة لتكون أوضح عند أول قراءة.',
                'نحتاج فكرة أقوى في الدعوة لاتخاذ إجراء.',
                'الفكرة متكررة ونرغب بطرح جديد أكثر تميزا.'
            ];
        case 'content':
        case 'content_review':
            return [
                'النص طويل نسبياً ونحتاج نسخة مختصرة وواضحة.',
                'نرجو صياغة أقوى للعنوان الأساسي.',
                'الرسالة تحتاج إبراز القيمة المقدمة بشكل مباشر.',
                'يرجى تعديل الأسلوب ليكون أكثر اتساقا مع الهوية.'
            ];
        case 'design':
        case 'client_rev':
        case 'design_review':
            return [
                'الألوان الحالية لا تعكس هوية العلامة بشكل كاف.',
                'نحتاج توازن بصري أفضل بين النص والعناصر.',
                'حجم الخط يحتاج زيادة لتحسين الوضوح.',
                'يرجى تحسين المحاذاة والهوامش في التصميم.'
            ];
        default:
            return [
                'نحتاج توضيحاً أكبر قبل الاعتماد النهائي.',
                'يرجى تحسين جودة العرض لهذه النسخة.',
                'المحتوى جيد مبدئيا لكن يلزم تعديل قبل الاعتماد.',
                'نرغب بتحديث النسخة بما يتماشى مع الهدف المطلوب.'
            ];
    }
}

function render_reason_presets(string $targetId, array $suggestions): void
{
    if (empty($suggestions)) {
        return;
    }
    echo '<div class="reason-presets" data-target="' . htmlspecialchars($targetId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">';
    foreach ($suggestions as $suggestion) {
        echo '<button type="button" class="reason-chip" data-reason="' . htmlspecialchars((string)$suggestion, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '" onclick="applyQuickReason(\'' . htmlspecialchars($targetId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '\', this.dataset.reason)">';
        echo htmlspecialchars((string)$suggestion, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        echo '</button>';
    }
    echo '</div>';
}

$quick_reason_suggestions = stage_reason_suggestions((string)($social_review_mode ?? $curr));

function client_review_public_stage_is_open(string $stage): bool
{
    return in_array(strtolower(trim($stage)), ['client_rev', 'idea_review', 'content_review', 'design_review'], true);
}

if (!client_review_public_stage_is_open((string)$curr)) {
    http_response_code(410);
    $safeStageLabel = htmlspecialchars($current_stage_label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    die("<div style='background:#000;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px;box-sizing:border-box;text-align:center;'><div style='max-width:560px;font-family:Cairo,Arial,sans-serif;color:#d9d9d9;'><h3 style='color:#d4af37;margin:0 0 12px;font-size:1.4rem;'>انتهت صلاحية عرض العميل</h3><p style='line-height:1.9;margin:0;color:#aaa;'>هذا الرابط متاح فقط أثناء مرحلة عرض/مراجعة العميل. المرحلة الحالية: <b style='color:#fff;'>" . $safeStageLabel . "</b>.</p></div></div>");
}

function client_review_is_video(string $path): bool
{
    $ext = strtolower((string)pathinfo($path, PATHINFO_EXTENSION));
    return in_array($ext, ['mp4', 'webm', 'ogg', 'mov', 'm4v'], true);
}

function save_client_global_note(mysqli $conn, int $jobId, string $note, string $stageLabel, string $clientName): void
{
    $note = trim($note);
    if ($note === '') {
        return;
    }
    $note = mb_substr($note, 0, 1000);
    $safe = $conn->real_escape_string($note);
    $stage = $conn->real_escape_string($stageLabel);
    $client = $conn->real_escape_string($clientName);
    $prefix = "\n[ملاحظة عميل عامة - {$client} - {$stage}]: {$safe}";
    $conn->query("UPDATE job_orders SET notes = CONCAT(IFNULL(notes, ''), '$prefix') WHERE id = $jobId");
}

function social_post_belongs_to_job(mysqli $conn, int $postId, int $jobId): bool
{
    if ($postId <= 0 || $jobId <= 0) {
        return false;
    }
    try {
        $stmt = $conn->prepare("SELECT id FROM social_posts WHERE id = ? AND job_id = ? LIMIT 1");
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param("ii", $postId, $jobId);
        $stmt->execute();
        $ok = $stmt->get_result()->num_rows > 0;
        $stmt->close();
        return $ok;
    } catch (Throwable $e) {
        error_log('client_review social_post_belongs_to_job error: ' . $e->getMessage());
        return false;
    }
}

function proof_belongs_to_job(mysqli $conn, int $proofId, int $jobId): bool
{
    if ($proofId <= 0 || $jobId <= 0) {
        return false;
    }
    try {
        $stmt = $conn->prepare("SELECT id FROM job_proofs WHERE id = ? AND job_id = ? LIMIT 1");
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param("ii", $proofId, $jobId);
        $stmt->execute();
        $ok = $stmt->get_result()->num_rows > 0;
        $stmt->close();
        return $ok;
    } catch (Throwable $e) {
        error_log('client_review proof_belongs_to_job error: ' . $e->getMessage());
        return false;
    }
}

function client_review_safe_query(mysqli $conn, string $sql): ?mysqli_result
{
    try {
        $res = $conn->query($sql);
        return ($res instanceof mysqli_result) ? $res : null;
    } catch (Throwable $e) {
        error_log('client_review query failed: ' . $e->getMessage() . ' | SQL: ' . $sql);
        return null;
    }
}

// تسجيل وقت المشاهدة
try {
    if (function_exists('app_table_has_column') && app_table_has_column($conn, 'job_orders', 'client_viewed_at')) {
        $conn->query("UPDATE job_orders SET client_viewed_at = NOW() WHERE id = $job_id AND client_viewed_at IS NULL");
    }
} catch (Throwable $e) {
    error_log('client_review viewed_at update failed: ' . $e->getMessage());
}

// --- 2. معالجة الردود (Backend Processing) ---
if($_SERVER['REQUEST_METHOD'] == 'POST') {
    $global_note = trim((string)($_POST['global_note'] ?? ''));
    if (!app_verify_csrf($_POST['_csrf_token'] ?? '')) {
        $review_error = 'انتهت الجلسة. يرجى تحديث الصفحة ثم إعادة الإرسال.';
    }
    if ($review_error === '') {
    try {
    
    // --- أ) معالجة السوشيال ميديا ---
    if ($job_type === 'social' && $social_review_mode !== null) {
        
        // 1. مراجعة الأفكار (Batch Review)
        if (isset($_POST['review_idea_batch'])) {
            $has_rejection = false;
            $all_valid = true;
            $statuses = (isset($_POST['status']) && is_array($_POST['status'])) ? $_POST['status'] : [];
            foreach ($statuses as $pid => $st) {
                $pid = intval($pid);
                if ($pid <= 0) { $all_valid = false; break; }
                if (!social_post_belongs_to_job($conn, $pid, (int)$job_id)) { $all_valid = false; break; }
                $st = ($st === 'rejected') ? 'rejected' : 'approved';
                $reason = isset($_POST['reason'][$pid]) ? $conn->real_escape_string($_POST['reason'][$pid]) : '';
                if ($st == 'rejected' && empty($reason)) { $all_valid = false; break; }
                
                if ($st == 'rejected') {
                    $has_rejection = true;
                    $conn->query("UPDATE social_posts SET idea_status='idea_rejected', idea_feedback='$reason' WHERE id=$pid");
                } else {
                    $conn->query("UPDATE social_posts SET idea_status='idea_approved', idea_feedback=NULL WHERE id=$pid");
                }
            }
            if ($all_valid) {
                save_client_global_note($conn, (int)$job_id, $global_note, $current_stage_label, $client_name);
                $next = $has_rejection ? $prev_stage_key : $next_stage_key;
                if ($next !== '') {
                    app_update_job_stage($conn, (int)$job_id, $next);
                }
                header("Location: client_review.php?token={$safeToken}&done=1"); exit;
            }
        } 
        // 2. مراجعة المحتوى والتصميم
        elseif (isset($_POST['review_content_batch']) || isset($_POST['review_design_batch'])) {
            $has_rejection = false;
            $type = isset($_POST['review_content_batch']) ? 'content' : 'design';
            $all_valid = true;
            $statuses = (isset($_POST['status']) && is_array($_POST['status'])) ? $_POST['status'] : [];
            foreach ($statuses as $pid => $st) {
                $pid = intval($pid);
                if ($pid <= 0) { $all_valid = false; break; }
                if (!social_post_belongs_to_job($conn, $pid, (int)$job_id)) { $all_valid = false; break; }
                $st = ($st === 'rejected') ? 'rejected' : 'approved';
                $reason = isset($_POST['reason'][$pid]) ? $conn->real_escape_string($_POST['reason'][$pid]) : '';
                if ($st == 'rejected' && empty($reason)) { $all_valid = false; break; }
                if ($st == 'rejected') {
                    $has_rejection = true;
                    $conn->query("UPDATE social_posts SET status='".$type."_rejected', client_feedback='$reason' WHERE id=$pid");
                } else {
                    $conn->query("UPDATE social_posts SET status='".$type."_approved', client_feedback=NULL WHERE id=$pid");
                }
            }
            if ($all_valid) {
                save_client_global_note($conn, (int)$job_id, $global_note, $current_stage_label, $client_name);
                $next = $has_rejection ? $prev_stage_key : $next_stage_key;
                if ($next !== '') {
                    app_update_job_stage($conn, (int)$job_id, $next);
                }
                header("Location: client_review.php?token={$safeToken}&done=1"); exit;
            }
        }
    } 
    // --- ب) معالجة التصميم فقط ---
    elseif ($job_type == 'design_only' && isset($_POST['review_design_only_batch'])) {
        $all_valid = true;
        $statuses = (isset($_POST['status']) && is_array($_POST['status'])) ? $_POST['status'] : [];
        foreach ($statuses as $pid => $st) {
            $pid = intval($pid);
            if ($pid <= 0) { $all_valid = false; break; }
            if (!proof_belongs_to_job($conn, $pid, (int)$job_id)) { $all_valid = false; break; }
            $st = ($st === 'rejected') ? 'rejected' : 'approved';
            $reason = isset($_POST['reason'][$pid]) ? $conn->real_escape_string($_POST['reason'][$pid]) : '';
            if ($st == 'rejected' && empty($reason)) { $all_valid = false; break; }
            $conn->query("UPDATE job_proofs SET status='$st', client_comment='$reason' WHERE id=$pid");
        }
        if ($all_valid) {
            save_client_global_note($conn, (int)$job_id, $global_note, $current_stage_label, $client_name);
            header("Location: client_review.php?token={$safeToken}&done=1"); exit;
        }
    } 
    // --- ج) معالجة الدفعات العامة ---
    elseif (isset($_POST['review_generic_batch'])) {
        $all_valid = true;
        $statuses = (isset($_POST['status']) && is_array($_POST['status'])) ? $_POST['status'] : [];
        foreach ($statuses as $proof_id => $action) {
            $proof_id = intval($proof_id);
            if ($proof_id <= 0) { $all_valid = false; break; }
            if (!proof_belongs_to_job($conn, $proof_id, (int)$job_id)) { $all_valid = false; break; }
            $action = ($action === 'rejected') ? 'rejected' : 'approved';
            $comment = isset($_POST['reason'][$proof_id]) ? $conn->real_escape_string($_POST['reason'][$proof_id]) : '';
            if ($action == 'rejected' && empty(trim($comment))) { $all_valid = false; break; }
            $conn->query("UPDATE job_proofs SET status='$action', client_comment='$comment' WHERE id=$proof_id");
        }
        if ($all_valid) {
            save_client_global_note($conn, (int)$job_id, $global_note, $current_stage_label, $client_name);
            header("Location: client_review.php?token={$safeToken}&done=1"); exit;
        }
    }
    } catch (Throwable $e) {
        error_log('client_review post processing failed: ' . $e->getMessage());
        $review_error = 'تعذر حفظ المراجعة حالياً، يرجى المحاولة مرة أخرى.';
    }
    }
}
?>
<!DOCTYPE html>
<html dir="rtl" lang="ar">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>بوابة العملاء | <?php echo app_h($appName); ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@300;400;600;700;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/brand.css">
    <style>
        :root {
            --gold: #d4af37;
            --gold-gradient: linear-gradient(135deg, #d4af37 0%, #AA8E2F 100%);
            --dark-bg: #050505;
            --panel-bg: #121212;
            --text-main: #ffffff;
            --text-sub: #b0b0b0;
            --green: #27ae60;
            --red: #c0392b;
            --border-radius: 12px;
        }

        body.brand-shell { 
            background-color: var(--dark-bg); 
            color: var(--text-main); 
            font-family: 'Cairo', sans-serif; 
            margin: 0; padding: 20px; padding-bottom: 100px;
            background-image: 
                radial-gradient(circle at 10% 20%, rgba(212, 175, 55, 0.05) 0%, transparent 20%),
                radial-gradient(circle at 90% 80%, rgba(212, 175, 55, 0.05) 0%, transparent 20%);
        }

        .container { max-width: 900px; margin: 0 auto; }
        
        header { text-align: center; margin-bottom: 40px; padding-top: 20px; position: relative; }
        .brand-name { font-size: 2.5rem; font-weight: 900; margin: 0; letter-spacing: 1px; color: var(--gold); text-transform: uppercase; }
        .welcome-msg { font-size: 1.1rem; color: var(--text-sub); margin-top: 5px; }
        .stage-tracker {
            margin-top: 18px;
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            justify-content: center;
        }
        .stage-step {
            background: #171717;
            border: 1px solid #2f2f2f;
            border-radius: 18px;
            padding: 6px 12px;
            font-size: 0.82rem;
            color: #9f9f9f;
        }
        .stage-step.done {
            border-color: rgba(39, 174, 96, 0.6);
            color: #a7f0c0;
        }
        .stage-step.active {
            color: #000;
            background: var(--gold-gradient);
            border-color: transparent;
            font-weight: 800;
        }
        .stage-progress {
            margin: 14px auto 0;
            max-width: 520px;
            width: 100%;
            background: #101010;
            border: 1px solid #2a2a2a;
            border-radius: 999px;
            height: 10px;
            overflow: hidden;
        }
        .stage-progress span {
            display: block;
            height: 100%;
            background: linear-gradient(90deg, #18c7a0, var(--gold));
        }
        .stage-meta {
            margin-top: 8px;
            font-size: 0.85rem;
            color: #bfbfbf;
        }
        .stage-meta b { color: #f1d58a; }
        .review-global-note {
            background: #101010;
            border: 1px solid #2d2d2d;
            border-radius: 12px;
            padding: 14px;
            margin-bottom: 20px;
        }
        .review-global-note textarea {
            width: 100%;
            background: #070707;
            border: 1px solid #343434;
            border-radius: 9px;
            color: #fff;
            padding: 12px;
            font-family: 'Cairo', sans-serif;
            box-sizing: border-box;
            min-height: 85px;
            resize: vertical;
        }
        .review-global-note textarea:focus {
            border-color: var(--gold);
            outline: none;
        }

        /* البطاقات */
        .royal-card { 
            background: var(--panel-bg); 
            border: 1px solid #333; 
            border-radius: var(--border-radius); 
            margin-bottom: 30px; 
            overflow: hidden; 
            transition: transform 0.3s ease, border-color 0.3s ease;
        }
        .royal-card:hover { border-color: #444; }
        .royal-card.error-highlight { border: 1px solid var(--red); box-shadow: 0 0 15px rgba(192, 57, 43, 0.3); }

        .card-header { 
            background: linear-gradient(90deg, #1a1a1a 0%, #252525 100%); 
            padding: 15px 25px; 
            border-bottom: 1px solid #333; 
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
        }
        .card-title { color: var(--gold); font-weight: 700; font-size: 1.15rem; }
        .card-meta {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .status-pill {
            padding: 4px 10px;
            border-radius: 999px;
            font-size: 0.74rem;
            border: 1px solid #3a3a3a;
            color: #cfcfcf;
            background: #141414;
        }
        .status-pill.approved { border-color: rgba(39,174,96,.5); color: #bff0d1; background: rgba(39,174,96,.12); }
        .status-pill.rejected { border-color: rgba(192,57,43,.5); color: #ffc2bb; background: rgba(192,57,43,.12); }
        .toggle-card {
            border: 1px solid #3a3a3a;
            border-radius: 8px;
            background: #141414;
            color: #d6d6d6;
            padding: 6px 10px;
            font-family: 'Cairo', sans-serif;
            font-size: 0.78rem;
            cursor: pointer;
        }
        .card-body { padding: 25px; }

        .content-display { 
            background: #080808; padding: 20px; border-radius: 8px; 
            border: 1px dashed #444; line-height: 1.8; font-size: 1rem; 
            color: #ddd; white-space: pre-wrap; margin-bottom: 20px; 
        }

        /* Social Post Preview (Feed Style) */
        .social-post {
            background: #0f0f12;
            border-radius: 16px;
            border: 1px solid #262626;
            overflow: hidden;
            margin-bottom: 20px;
            box-shadow: 0 12px 30px rgba(0,0,0,0.35);
        }
        .social-header {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 16px 18px;
            border-bottom: 1px solid #1f1f1f;
            background: linear-gradient(90deg, #0f0f12 0%, #141419 100%);
        }
        .social-avatar {
            width: 42px;
            height: 42px;
            border-radius: 50%;
            background: #1f1f1f;
            border: 1px solid #2a2a2a;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-weight: 800;
            color: #d4af37;
            font-size: 0.9rem;
        }
        .social-name {
            font-weight: 700;
            color: #f5f5f5;
            font-size: 0.98rem;
        }
        .social-meta {
            color: #9a9a9a;
            font-size: 0.78rem;
            margin-top: 2px;
        }
        .social-body {
            padding: 16px 18px;
            font-size: 1rem;
            line-height: 1.7;
            color: #e6e6e6;
            white-space: pre-wrap;
        }
        .social-body strong { color: var(--gold); }
        .social-actions {
            display: flex;
            gap: 14px;
            padding: 10px 18px 16px;
            border-top: 1px solid #1f1f1f;
            color: #9a9a9a;
            font-size: 0.85rem;
        }
        .social-actions span { display: inline-flex; align-items: center; gap: 6px; }

        .media-grid {
            display: grid;
            gap: 8px;
            padding: 0 18px 16px;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
        }
        .media-item {
            background: #0b0b0b;
            border: 1px solid #1f1f1f;
            border-radius: 12px;
            overflow: hidden;
            position: relative;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .media-item img,
        .media-item video {
            width: 100%;
            height: auto;
            max-height: 520px;
            object-fit: contain;
            display: block;
            background: #000;
        }
        .media-item video { background: #000; }

        .post-full-preview { display: none; }
        .post-text-part { display: none; }

        /* Carousel (legacy hidden) */
        .carousel-wrapper { display: none; }

        /* Controls */
        .control-panel { margin-top: 20px; padding-top: 15px; border-top: 1px solid #222; }
        .radio-group { display: flex; gap: 10px; margin-bottom: 10px; }
        .radio-option { flex: 1; }
        .radio-option input { display: none; }
        .radio-option label { 
            display: flex; align-items: center; justify-content: center; gap: 8px;
            padding: 12px; width: 100%; cursor: pointer; border-radius: 8px; 
            background: #181818; border: 1px solid #333; color: #888; font-weight: bold; transition: 0.2s;
            box-sizing: border-box;
        }
        .radio-option input:checked + label[data-type="approve"] { background: rgba(39, 174, 96, 0.2); color: var(--green); border-color: var(--green); }
        .radio-option input:checked + label[data-type="reject"] { background: rgba(192, 57, 43, 0.2); color: var(--red); border-color: var(--red); }
        
        textarea.reject-input { 
            width: 100%; background: #080808; border: 1px solid var(--red); color: #fff; 
            padding: 15px; border-radius: 8px; display: none; font-family: 'Cairo'; 
            box-sizing: border-box; min-height: 80px; resize: vertical;
        }

        .submit-sticky {
            position: fixed; bottom: 0; left: 0; width: 100%;
            background: rgba(10, 10, 10, 0.95); backdrop-filter: blur(10px);
            padding: 15px; border-top: 1px solid #333;
            text-align: center; z-index: 1000;
        }
        .submit-top {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            flex-wrap: wrap;
        }
        .decision-inline {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }
        .mini-pill {
            background: #171717;
            border: 1px solid #313131;
            border-radius: 999px;
            padding: 4px 10px;
            font-size: 0.75rem;
            color: #cfcfcf;
        }
        .compact-toggle {
            border: 1px solid #3a3a3a;
            border-radius: 999px;
            background: #141414;
            color: #d6d6d6;
            padding: 6px 12px;
            font-family: 'Cairo', sans-serif;
            font-size: 0.78rem;
            cursor: pointer;
        }
        .submit-compact .review-controls,
        .submit-compact .bulk-actions {
            display: none;
        }
        .decision-summary {
            display: none;
        }
        .sum-pill {
            background: #171717;
            border: 1px solid #313131;
            border-radius: 30px;
            padding: 6px 12px;
            font-size: 0.82rem;
            color: #ccc;
        }
        .bulk-actions {
            display: flex;
            justify-content: center;
            gap: 8px;
            margin-bottom: 10px;
            flex-wrap: wrap;
        }
        .bulk-btn {
            border: 1px solid #373737;
            border-radius: 999px;
            background: #171717;
            color: #ddd;
            padding: 7px 12px;
            font-family: 'Cairo', sans-serif;
            font-size: 0.82rem;
            cursor: pointer;
        }
        .bulk-btn:hover { border-color: var(--gold); color: var(--gold); }
        .bulk-btn.bulk-reject:hover { border-color: var(--red); color: #ffb4ac; }
        .bulk-btn.jump-btn { border-color: #4a5a7a; color: #b8c5e6; }

        .collapsed .card-body { display: none; }

        @media (max-width: 720px) {
            body.brand-shell { padding: 14px; padding-bottom: 120px; }
            .container { max-width: 100%; }
            header { margin-bottom: 24px; padding-top: 8px; }
            .brand-name { font-size: 1.75rem; letter-spacing: 0.6px; }
            .welcome-msg { font-size: 1rem; }
            .stage-tracker { gap: 6px; }
            .stage-step { padding: 6px 10px; font-size: 0.74rem; }
            .stage-progress { height: 12px; }
            .stage-meta { font-size: 0.8rem; }

            .royal-card { border-radius: 16px; border-color: #2a2a2a; box-shadow: 0 12px 30px rgba(0,0,0,.35); }
            .card-header { padding: 14px 16px; flex-direction: column; align-items: flex-start; }
            .card-title { font-size: 1.05rem; }
            .card-meta { width: 100%; justify-content: space-between; }
            .status-pill { font-size: 0.7rem; padding: 4px 8px; }
            .toggle-card { font-size: 0.72rem; padding: 6px 8px; }
            .card-body { padding: 16px; }

            .content-display { font-size: 0.95rem; padding: 14px; }
            .social-post { border-radius: 14px; }
            .social-header { padding: 12px 14px; }
            .social-avatar { width: 36px; height: 36px; font-size: 0.85rem; }
            .social-body { font-size: 0.95rem; padding: 12px 14px; }
            .media-grid { padding: 0 14px 14px; grid-template-columns: 1fr; }
            .media-item img,
            .media-item video { max-height: 360px; }

            .radio-group { flex-direction: column; gap: 8px; }
            .radio-option label { padding: 12px 10px; font-size: 0.95rem; }
            textarea.reject-input { padding: 12px; min-height: 90px; }

            .review-controls { gap: 10px; }
            .filter-group { width: 100%; justify-content: center; }
            .filter-btn { font-size: 0.78rem; padding: 7px 12px; }
            .completion-wrap { width: 100%; }

            .submit-sticky { padding: 12px; }
            .btn-main { width: 100%; padding: 14px 18px; font-size: 1rem; }
            .bulk-actions { gap: 6px; }
            .bulk-btn { font-size: 0.78rem; padding: 8px 10px; }
            .submit-top { flex-direction: column; align-items: stretch; }
            .decision-inline { justify-content: center; }
            .review-controls { display: none; }
            .bulk-actions { display: none; }
        }

        .review-controls {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
            margin-bottom: 12px;
        }
        .filter-group {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }
        .filter-btn {
            border: 1px solid #393939;
            border-radius: 999px;
            background: #131313;
            color: #cdcdcd;
            padding: 6px 12px;
            font-family: 'Cairo', sans-serif;
            font-size: 0.8rem;
            cursor: pointer;
        }
        .filter-btn.active {
            border-color: var(--gold);
            color: #0d0d0d;
            background: var(--gold-gradient);
            font-weight: 700;
        }

        .completion-wrap {
            min-width: 220px;
        }
        .completion-label {
            font-size: 0.8rem;
            color: #bcbcbc;
            margin-bottom: 4px;
            text-align: right;
        }
        .completion-track {
            width: 100%;
            height: 8px;
            border-radius: 999px;
            background: #1d1d1d;
            border: 1px solid #2d2d2d;
            overflow: hidden;
        }
        .completion-track span {
            display: block;
            height: 100%;
            width: 0%;
            background: linear-gradient(90deg, #18c7a0, var(--gold));
            transition: width 0.25s ease;
        }

        .reason-presets {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            margin-top: 10px;
        }
        .reason-chip {
            border: 1px dashed #555;
            border-radius: 999px;
            background: rgba(17, 17, 17, 0.7);
            color: #d3d3d3;
            padding: 5px 10px;
            font-family: 'Cairo', sans-serif;
            font-size: 0.74rem;
            cursor: pointer;
            transition: border-color 0.15s ease, color 0.15s ease;
        }
        .reason-chip:hover {
            border-color: var(--gold);
            color: #ffd97f;
        }

        .hidden-card { display: none !important; }
        .btn-main {
            background: var(--gold-gradient); color: #000; border: none; 
            padding: 12px 60px; font-size: 1.1rem; font-weight: bold; 
            border-radius: 50px; cursor: pointer; font-family: 'Cairo';
            box-shadow: 0 5px 20px rgba(212, 175, 55, 0.2); transition: 0.3s;
        }
        .btn-main:hover { transform: translateY(-2px); box-shadow: 0 10px 30px rgba(212, 175, 55, 0.4); }

        .validation-error { color: var(--red); font-size: 0.9rem; margin-top: 5px; display: none; font-weight: bold; }

        @media (min-width: 1240px) {
            body.brand-shell.has-side-submit {
                padding-bottom: 28px;
                padding-inline-start: 390px;
            }
            .submit-sticky {
                top: 86px;
                bottom: 16px;
                left: auto;
                right: auto;
                inset-inline-start: 16px;
                inset-inline-end: auto;
                width: 360px;
                max-width: calc(100vw - 32px);
                border: 1px solid #343434;
                border-radius: 14px;
                border-top: 1px solid #343434;
                overflow-y: auto;
                text-align: start;
                box-shadow: 0 12px 35px rgba(0, 0, 0, 0.45);
            }
            .decision-summary,
            .bulk-actions,
            .review-controls {
                justify-content: flex-start;
            }
            .completion-wrap,
            .filter-group {
                width: 100%;
                min-width: 0;
            }
            .btn-main {
                width: 100%;
                padding-inline: 18px;
            }
        }
    </style>
</head>
<body class="brand-shell">

<div class="container">
    <header>
        <h1 class="brand-name">ARAB EAGLES</h1>
        <p class="welcome-msg"><?php echo htmlspecialchars($client_name); ?></p>
        <p style="font-size: 0.9rem; color: #666;">مشروع: <?php echo htmlspecialchars($job['job_name']); ?></p>
        <div class="stage-tracker">
            <?php $stagePos = 0; foreach ($workflow as $stageKey => $stageData): $stagePos++; ?>
                <span class="stage-step <?php echo ((string)$stageKey === (string)$curr) ? 'active' : (($stagePos - 1) < ($workflow_index + 1) ? 'done' : ''); ?>">
                    <?php echo htmlspecialchars((string)($stageData['label'] ?? $stageKey), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
                </span>
            <?php endforeach; ?>
        </div>
        <div class="stage-progress" aria-hidden="true">
            <span style="width: <?php echo (int)$workflow_percent; ?>%;"></span>
        </div>
        <div class="stage-meta">
            المرحلة الحالية: <b><?php echo htmlspecialchars($current_stage_label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></b>
            • تقدم: <b><?php echo (int)$workflow_percent; ?>%</b>
        </div>
    </header>

    <?php
    $review_items_count = 0;
    if ($job_type === 'social' && in_array($social_review_mode, ['idea', 'content', 'design'], true)) {
        $cnt_res = client_review_safe_query($conn, "SELECT COUNT(*) AS c FROM social_posts WHERE job_id=$job_id");
        if ($cnt_res) {
            $cnt_row = $cnt_res->fetch_assoc();
            $review_items_count = (int)($cnt_row['c'] ?? 0);
        }
    } else {
        $cnt_res = client_review_safe_query($conn, "SELECT COUNT(*) AS c FROM job_proofs WHERE job_id=$job_id AND status='pending'");
        if ($cnt_res) {
            $cnt_row = $cnt_res->fetch_assoc();
            $review_items_count = (int)($cnt_row['c'] ?? 0);
        }
    }
    $has_review_items = $review_items_count > 0;
    ?>

    <?php if(isset($_GET['done'])): ?>
        <div style="text-align:center; padding:100px 20px;">
            <div style="font-size:5rem; margin-bottom:20px;">تم</div>
            <h1 style="color:var(--gold);">تم استلام ردك بنجاح</h1>
            <p style="color:#bbb; max-width: 500px; margin: 20px auto;">شكراً لتعاونك. سيقوم الفريق بالعمل على ملاحظاتك فوراً.</p>
            <a href="#" onclick="window.close()" class="btn-main" style="text-decoration:none; display:inline-block;">إغلاق الصفحة</a>
        </div>
        <script>
            try {
                localStorage.removeItem(<?php echo json_encode('clientReviewDraft:' . (int)$job_id . ':' . (string)$curr); ?>);
            } catch (e) {}
        </script>
    <?php elseif(!$has_review_items): ?>
        <div style="text-align:center; padding:90px 20px;">
            <div style="font-size:4rem; margin-bottom:16px;">مراجعة</div>
            <h1 style="color:var(--gold); margin-bottom:12px;">لا يوجد ملفات للمراجعة حالياً</h1>
            <p style="color:#9f9f9f; max-width: 560px; margin: 0 auto 24px;">حالياً لا توجد أي ملفات أو عناصر متاحة لمراجعتك في هذه المرحلة.</p>
            <a href="#" onclick="if (window.history.length > 1) { window.history.back(); } else { window.close(); } return false;" class="btn-main" style="text-decoration:none; display:inline-block; width:auto; padding:12px 28px;">إغلاق الشاشة</a>
        </div>
    <?php else: ?>

        <form method="POST" id="reviewForm" onsubmit="return validateForm()">
            <?php echo app_csrf_input(); ?>
            <?php if($review_error !== ''): ?>
                <div style="background: rgba(192,57,43,0.15); border: 1px solid rgba(192,57,43,0.5); color:#ffb9b9; padding:12px; border-radius:10px; margin-bottom:15px;">
                    <?php echo htmlspecialchars($review_error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
                </div>
            <?php endif; ?>
            <div class="review-global-note">
                <div style="font-weight:700; color:var(--gold); margin-bottom:8px;">ملاحظة عامة على المرحلة (اختياري)</div>
                <textarea name="global_note" placeholder="اكتب ملاحظة عامة للفريق على هذه المرحلة بالكامل..."><?php echo htmlspecialchars($_POST['global_note'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></textarea>
            </div>

        <?php if($job_type === 'social' && $social_review_mode === 'idea'): ?>
            <?php 
            $posts = client_review_safe_query($conn, "SELECT * FROM social_posts WHERE job_id=$job_id ORDER BY post_index");
            if($posts && $posts->num_rows > 0):
            while($p = $posts->fetch_assoc()): 
            ?>
            <div class="royal-card item-card" data-id="<?php echo $p['id']; ?>">
                <div class="card-header">
                    <span class="card-title">فكرة بوست رقم #<?php echo $p['post_index']; ?></span>
                    <div class="card-meta">
                        <span class="status-pill" data-status>بدون قرار</span>
                        <button type="button" class="toggle-card" onclick="toggleCard(this)">إخفاء التفاصيل</button>
                    </div>
                </div>
                <div class="card-body">
                    <div class="social-post">
                        <div class="social-header">
                            <div class="social-avatar"><?php echo htmlspecialchars(mb_substr((string)$appName, 0, 2), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></div>
                            <div>
                                <div class="social-name"><?php echo htmlspecialchars($appName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></div>
                                <div class="social-meta">فكرة بوست • <?php echo (int)$p['post_index']; ?> • مراجعة</div>
                            </div>
                        </div>
                        <div class="social-body"><?php echo nl2br(htmlspecialchars($p['idea_text'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')); ?></div>
                        <div class="social-actions">
                            <span>إعجاب</span>
                            <span>تعليق</span>
                            <span>مشاركة</span>
                        </div>
                    </div>
                    <div class="control-panel">
                        <div class="radio-group">
                            <div class="radio-option">
                                <input type="radio" name="status[<?php echo $p['id']; ?>]" id="i_app_<?php echo $p['id']; ?>" value="approved" onchange="toggleReason('ir_<?php echo $p['id']; ?>', false)">
                                <label for="i_app_<?php echo $p['id']; ?>" data-type="approve">اعتماد</label>
                            </div>
                            <div class="radio-option">
                                <input type="radio" name="status[<?php echo $p['id']; ?>]" id="i_rej_<?php echo $p['id']; ?>" value="rejected" onchange="toggleReason('ir_<?php echo $p['id']; ?>', true)">
                                <label for="i_rej_<?php echo $p['id']; ?>" data-type="reject">طلب تعديل</label>
                            </div>
                        </div>
                        <textarea name="reason[<?php echo $p['id']; ?>]" id="ir_<?php echo $p['id']; ?>" class="reject-input" placeholder="سبب رفض الفكرة..."></textarea>
                        <?php render_reason_presets('ir_' . $p['id'], $quick_reason_suggestions); ?>
                        <div class="validation-error">مطلوب تحديد قرار.</div>
                    </div>
                </div>
            </div>
            <?php endwhile; ?>
            <?php else: ?>
                <div style="text-align:center; padding:50px; color:#777;">لا توجد عناصر مراجعة متاحة حالياً.</div>
            <?php endif; ?>
            <input type="hidden" name="review_idea_batch" value="1">

        <?php elseif($job_type === 'social' && $social_review_mode === 'content'): ?>
            <?php 
            $posts = client_review_safe_query($conn, "SELECT * FROM social_posts WHERE job_id=$job_id ORDER BY post_index");
            if($posts && $posts->num_rows > 0):
            while($p = $posts->fetch_assoc()): 
            ?>
            <div class="royal-card item-card" data-id="<?php echo $p['id']; ?>">
                <div class="card-header">
                    <span class="card-title">محتوى بوست رقم #<?php echo $p['post_index']; ?></span>
                    <div class="card-meta">
                        <span class="status-pill" data-status>بدون قرار</span>
                        <button type="button" class="toggle-card" onclick="toggleCard(this)">إخفاء التفاصيل</button>
                    </div>
                </div>
                <div class="card-body">
                    <div class="social-post">
                        <div class="social-header">
                            <div class="social-avatar"><?php echo htmlspecialchars(mb_substr((string)$appName, 0, 2), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></div>
                            <div>
                                <div class="social-name"><?php echo htmlspecialchars($appName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></div>
                                <div class="social-meta">محتوى بوست • <?php echo (int)$p['post_index']; ?> • للمراجعة</div>
                            </div>
                        </div>
                        <div class="social-body"><?php echo nl2br(htmlspecialchars($p['content_text'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')); ?></div>
                        <div class="social-actions">
                            <span>إعجاب</span>
                            <span>تعليق</span>
                            <span>مشاركة</span>
                        </div>
                    </div>
                    <div class="control-panel">
                        <div class="radio-group">
                            <div class="radio-option">
                                <input type="radio" name="status[<?php echo $p['id']; ?>]" id="c_app_<?php echo $p['id']; ?>" value="approved" onchange="toggleReason('cr_<?php echo $p['id']; ?>', false)">
                                <label for="c_app_<?php echo $p['id']; ?>" data-type="approve">اعتماد</label>
                            </div>
                            <div class="radio-option">
                                <input type="radio" name="status[<?php echo $p['id']; ?>]" id="c_rej_<?php echo $p['id']; ?>" value="rejected" onchange="toggleReason('cr_<?php echo $p['id']; ?>', true)">
                                <label for="c_rej_<?php echo $p['id']; ?>" data-type="reject">طلب تعديل</label>
                            </div>
                        </div>
                        <textarea name="reason[<?php echo $p['id']; ?>]" id="cr_<?php echo $p['id']; ?>" class="reject-input" placeholder="التعديل المطلوب..."></textarea>
                        <?php render_reason_presets('cr_' . $p['id'], $quick_reason_suggestions); ?>
                        <div class="validation-error">مطلوب تحديد قرار.</div>
                    </div>
                </div>
            </div>
            <?php endwhile; ?>
            <?php else: ?>
                <div style="text-align:center; padding:50px; color:#777;">لا توجد عناصر مراجعة متاحة حالياً.</div>
            <?php endif; ?>
            <input type="hidden" name="review_content_batch" value="1">

        <?php elseif($job_type === 'social' && $social_review_mode === 'design'): ?>
            <?php
            $posts = client_review_safe_query($conn, "SELECT * FROM social_posts WHERE job_id=$job_id ORDER BY post_index");
            if($posts && $posts->num_rows > 0):
            while($p = $posts->fetch_assoc()):
                $images = []; if (!empty($p['design_path'])) { $decoded = json_decode($p['design_path'], true); if (is_array($decoded)) { $images = $decoded; } else { $images[] = $p['design_path']; } }
            ?>
            <div class="royal-card item-card" data-id="<?php echo $p['id']; ?>">
                <div class="card-header">
                    <span class="card-title">المعاينة النهائية - بوست #<?php echo $p['post_index']; ?></span>
                    <div class="card-meta">
                        <span class="status-pill" data-status>بدون قرار</span>
                        <button type="button" class="toggle-card" onclick="toggleCard(this)">إخفاء التفاصيل</button>
                    </div>
                </div>
                <div class="card-body">
                    <div class="social-post">
                        <div class="social-header">
                            <div class="social-avatar"><?php echo htmlspecialchars(mb_substr((string)$appName, 0, 2), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></div>
                            <div>
                                <div class="social-name"><?php echo htmlspecialchars($appName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></div>
                                <div class="social-meta">تصميم نهائي • بوست <?php echo (int)$p['post_index']; ?></div>
                            </div>
                        </div>
                        <div class="social-body">
                            <strong>النص المعتمد:</strong>
                            <div style="margin-top:8px;"><?php echo nl2br(htmlspecialchars($p['content_text'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')); ?></div>
                        </div>
                        <?php if(!empty($images)): ?>
                            <div class="media-grid">
                                <?php foreach($images as $img_path): ?>
                                    <?php if (client_review_is_video((string)$img_path)): ?>
                                        <div class="media-item">
                                            <video controls playsinline preload="metadata">
                                                <source src="<?php echo htmlspecialchars($img_path, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
                                                المتصفح لا يدعم تشغيل الفيديو.
                                            </video>
                                        </div>
                                    <?php else: ?>
                                        <?php $protected_src = "watermark.php?src=" . urlencode($img_path) . "&token=" . $safeToken; ?>
                                        <div class="media-item">
                                            <img src="<?php echo $protected_src; ?>" alt="Design Preview">
                                        </div>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="social-body" style="padding-top:0;">لا يوجد تصميم</div>
                        <?php endif; ?>
                        <div class="social-actions">
                            <span>إعجاب</span>
                            <span>تعليق</span>
                            <span>مشاركة</span>
                        </div>
                    </div>

                    <div class="control-panel">
                        <div class="radio-group">
                            <div class="radio-option">
                                <input type="radio" name="status[<?php echo $p['id']; ?>]" id="d_app_<?php echo $p['id']; ?>" value="approved" onchange="toggleReason('dr_<?php echo $p['id']; ?>', false)">
                                <label for="d_app_<?php echo $p['id']; ?>" data-type="approve">اعتماد نهائي</label>
                            </div>
                            <div class="radio-option">
                                <input type="radio" name="status[<?php echo $p['id']; ?>]" id="d_rej_<?php echo $p['id']; ?>" value="rejected" onchange="toggleReason('dr_<?php echo $p['id']; ?>', true)">
                                <label for="d_rej_<?php echo $p['id']; ?>" data-type="reject">طلب تعديل</label>
                            </div>
                        </div>
                        <textarea name="reason[<?php echo $p['id']; ?>]" id="dr_<?php echo $p['id']; ?>" class="reject-input" placeholder="ملاحظات التعديل (على التصميم أو النص)..."></textarea>
                        <?php render_reason_presets('dr_' . $p['id'], $quick_reason_suggestions); ?>
                        <div class="validation-error">مطلوب تحديد قرار.</div>
                    </div>
                </div>
            </div>
            <?php endwhile; ?>
            <?php else: ?>
                <div style="text-align:center; padding:50px; color:#777;">لا توجد معاينات تصميم متاحة حالياً.</div>
            <?php endif; ?>
            <input type="hidden" name="review_design_batch" value="1">

        <?php elseif($job_type == 'design_only'): ?>
            <?php 
            $proofs = client_review_safe_query($conn, "SELECT * FROM job_proofs WHERE job_id=$job_id AND status='pending'");
            if($proofs && $proofs->num_rows > 0):
                while($p = $proofs->fetch_assoc()):
                    $ext = strtolower(pathinfo($p['file_path'], PATHINFO_EXTENSION));
                    $is_image = in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp']);
            ?>
            <div class="royal-card item-card" data-id="<?php echo $p['id']; ?>">
                <div class="card-header">
                    <span class="card-title">مراجعة تصميم: <?php echo $p['description']; ?></span>
                    <div class="card-meta">
                        <span class="status-pill" data-status>بدون قرار</span>
                        <button type="button" class="toggle-card" onclick="toggleCard(this)">إخفاء التفاصيل</button>
                    </div>
                </div>
                <div class="card-body">
                    <div style="background:#0a0a0a; border:1px solid #333; border-radius:8px; padding:15px; margin-bottom:20px; text-align:center;">
                        <?php if($is_image): 
                            // 🟢 إضافة الختم المائي للتصاميم
                            $protected_src = "watermark.php?src=" . urlencode($p['file_path']) . "&token=" . $safeToken;
                        ?>
                            <a href="javascript:void(0);" style="cursor: default;"><img src="<?php echo $protected_src; ?>" style="max-width:100%; border-radius:5px; max-height:400px;"></a>
                        <?php else: ?>
                            <div style="padding:20px;">
                                <h3 style="color:#fff;"><?php echo basename($p['file_path']); ?></h3>
                                <a href="<?php echo $p['file_path']; ?>" target="_blank" class="btn-main" style="text-decoration:none; display:inline-block; font-size:0.9rem;">تحميل / معاينة</a>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="control-panel">
                        <div class="radio-group">
                            <div class="radio-option">
                                <input type="radio" name="status[<?php echo $p['id']; ?>]" id="do_app_<?php echo $p['id']; ?>" value="approved" onchange="toggleReason('dor_<?php echo $p['id']; ?>', false)">
                                <label for="do_app_<?php echo $p['id']; ?>" data-type="approve">اعتماد التصميم</label>
                            </div>
                            <div class="radio-option">
                                <input type="radio" name="status[<?php echo $p['id']; ?>]" id="do_rej_<?php echo $p['id']; ?>" value="rejected" onchange="toggleReason('dor_<?php echo $p['id']; ?>', true)">
                                <label for="do_rej_<?php echo $p['id']; ?>" data-type="reject">طلب تعديلات</label>
                            </div>
                        </div>
                        <textarea name="reason[<?php echo $p['id']; ?>]" id="dor_<?php echo $p['id']; ?>" class="reject-input" placeholder="ملاحظات التعديل..."></textarea>
                        <?php render_reason_presets('dor_' . $p['id'], $quick_reason_suggestions); ?>
                        <div class="validation-error">مطلوب تحديد قرار.</div>
                    </div>
                </div>
            </div>
            <?php endwhile; ?>
            <input type="hidden" name="review_design_only_batch" value="1">
            <?php else: ?>
                <div style="text-align:center; padding:50px; color:#777;">لا توجد تصاميم معلقة.</div>
            <?php endif; ?>

        <?php else: ?>
            <?php 
            $proofs = client_review_safe_query($conn, "SELECT * FROM job_proofs WHERE job_id=$job_id AND status='pending' ORDER BY id DESC");
            if($proofs && $proofs->num_rows > 0):
                while($p = $proofs->fetch_assoc()):
                    $ext = strtolower(pathinfo($p['file_path'], PATHINFO_EXTENSION));
                    $is_image = in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp']);
            ?>
            <div class="royal-card item-card" data-id="<?php echo $p['id']; ?>">
                <div class="card-header">
                    <span class="card-title">مراجعة ملف: <?php echo $p['description']; ?></span>
                    <div class="card-meta">
                        <span class="status-pill" data-status>بدون قرار</span>
                        <button type="button" class="toggle-card" onclick="toggleCard(this)">إخفاء التفاصيل</button>
                    </div>
                </div>
                <div class="card-body">
                    <div style="background:#0a0a0a; border:1px solid #333; border-radius:8px; padding:15px; margin-bottom:20px; text-align:center;">
                        <?php if($is_image): 
                            // 🟢 إضافة الختم المائي للملفات العامة
                            $protected_src = "watermark.php?src=" . urlencode($p['file_path']) . "&token=" . $safeToken;
                        ?>
                            <a href="javascript:void(0);" style="cursor: default;"><img src="<?php echo $protected_src; ?>" style="max-width:100%; max-height:300px;"></a>
                        <?php else: ?>
                            <a href="<?php echo $p['file_path']; ?>" target="_blank" class="btn-main" style="text-decoration:none; display:inline-block; font-size:0.9rem;">تحميل الملف</a>
                        <?php endif; ?>
                    </div>
                    <div class="control-panel">
                        <div class="radio-group">
                            <div class="radio-option">
                                <input type="radio" name="status[<?php echo $p['id']; ?>]" id="g_app_<?php echo $p['id']; ?>" value="approved" onchange="toggleReason('gr_<?php echo $p['id']; ?>', false)">
                                <label for="g_app_<?php echo $p['id']; ?>" data-type="approve">اعتماد</label>
                            </div>
                            <div class="radio-option">
                                <input type="radio" name="status[<?php echo $p['id']; ?>]" id="g_rej_<?php echo $p['id']; ?>" value="rejected" onchange="toggleReason('gr_<?php echo $p['id']; ?>', true)">
                                <label for="g_rej_<?php echo $p['id']; ?>" data-type="reject">طلب تعديل</label>
                            </div>
                        </div>
                        <textarea name="reason[<?php echo $p['id']; ?>]" id="gr_<?php echo $p['id']; ?>" class="reject-input" placeholder="ملاحظات..."></textarea>
                        <?php render_reason_presets('gr_' . $p['id'], $quick_reason_suggestions); ?>
                        <div class="validation-error">مطلوب تحديد قرار.</div>
                    </div>
                </div>
            </div>
            <?php endwhile; ?>
            <input type="hidden" name="review_generic_batch" value="1">
            <?php else: ?>
                <div style="text-align:center; padding:50px; color:#777;">لا توجد عناصر للمراجعة حالياً.</div>
            <?php endif; ?>
        <?php endif; ?>

        <?php 
        // عرض زر الإرسال إذا كان هناك أي محتوى معروض
        $show_submit = false;
        if($job_type === 'social' && in_array($social_review_mode, ['idea', 'content', 'design'], true)) $show_submit = true;
        elseif(isset($proofs) && ($proofs instanceof mysqli_result) && $proofs->num_rows > 0) $show_submit = true;
        
        if($show_submit): ?>
            <div class="submit-sticky">
                <div class="submit-top">
                    <div class="decision-inline">
                        <span class="mini-pill">معتمد <b id="approvedCountTop">0</b></span>
                        <span class="mini-pill">مرفوض <b id="rejectedCountTop">0</b></span>
                        <span class="mini-pill">معلق <b id="pendingCountTop">0</b></span>
                        <span class="mini-pill">النسبة <b id="completionPercentTop">0%</b></span>
                    </div>
                    <button type="button" class="compact-toggle" onclick="toggleDecisionBar()">عرض أقل</button>
                </div>
                <div class="review-controls">
                    <div class="filter-group">
                        <button type="button" class="filter-btn active" data-filter="all" onclick="applyFilter('all', this)">كل العناصر</button>
                        <button type="button" class="filter-btn" data-filter="pending" onclick="applyFilter('pending', this)">بدون قرار</button>
                        <button type="button" class="filter-btn" data-filter="rejected" onclick="applyFilter('rejected', this)">المرفوض</button>
                        <button type="button" class="filter-btn" data-filter="approved" onclick="applyFilter('approved', this)">المعتمد</button>
                    </div>
                    <div class="completion-wrap">
                        <div class="completion-label">اكتمال القرارات: <b id="completionPercent">0%</b></div>
                        <div class="completion-track"><span id="completionFill"></span></div>
                    </div>
                </div>
                <div class="bulk-actions">
                    <button type="button" class="bulk-btn" onclick="setAllDecision('approved')">اعتماد الكل</button>
                    <button type="button" class="bulk-btn bulk-reject" onclick="setAllRejectedWithReason()">رفض الكل (بسبب موحد)</button>
                    <button type="button" class="bulk-btn" onclick="clearAllDecision()">تصفير الاختيارات</button>
                    <button type="button" class="bulk-btn jump-btn" onclick="jumpToNextPending()">الانتقال لأول عنصر بدون قرار</button>
                </div>
                <button type="submit" class="btn-main">إرسال الرد النهائي</button>
            </div>
        <?php endif; ?>

        </form>
    <?php endif; ?>
</div>

<script>
    const reviewDraftKey = <?php echo json_encode('clientReviewDraft:' . (int)$job_id . ':' . (string)$curr); ?>;
    let currentFilterMode = 'all';
    let draftSaveTimer = null;

    function cardDecisionState(card) {
        if (card.querySelector('input[value="approved"]:checked')) return 'approved';
        if (card.querySelector('input[value="rejected"]:checked')) return 'rejected';
        return 'pending';
    }

    function updateDecisionStats() {
        const cards = document.querySelectorAll('.item-card');
        let approved = 0;
        let rejected = 0;
        let pending = 0;

        cards.forEach(card => {
            const state = cardDecisionState(card);
            const pill = card.querySelector('[data-status]');
            if (pill) {
                pill.classList.remove('approved', 'rejected');
                if (state === 'approved') {
                    pill.textContent = 'معتمد';
                    pill.classList.add('approved');
                } else if (state === 'rejected') {
                    pill.textContent = 'مرفوض';
                    pill.classList.add('rejected');
                } else {
                    pill.textContent = 'بدون قرار';
                }
            }
            if (state === 'approved') approved++;
            else if (state === 'rejected') rejected++;
            else pending++;
        });

        const total = cards.length;
        const decided = approved + rejected;
        const percent = total === 0 ? 0 : Math.round((decided / total) * 100);

        const a = document.getElementById('approvedCount');
        const r = document.getElementById('rejectedCount');
        const p = document.getElementById('pendingCount');
        const aTop = document.getElementById('approvedCountTop');
        const rTop = document.getElementById('rejectedCountTop');
        const pTop = document.getElementById('pendingCountTop');
        const cpTop = document.getElementById('completionPercentTop');
        const cp = document.getElementById('completionPercent');
        const cf = document.getElementById('completionFill');
        if (a) a.textContent = String(approved);
        if (r) r.textContent = String(rejected);
        if (p) p.textContent = String(pending);
        if (aTop) aTop.textContent = String(approved);
        if (rTop) rTop.textContent = String(rejected);
        if (pTop) pTop.textContent = String(pending);
        if (cpTop) cpTop.textContent = percent + '%';
        if (cp) cp.textContent = percent + '%';
        if (cf) cf.style.width = percent + '%';
    }

    function toggleCard(btn) {
        const card = btn.closest('.royal-card');
        if (!card) return;
        card.classList.toggle('collapsed');
        btn.textContent = card.classList.contains('collapsed') ? 'إظهار التفاصيل' : 'إخفاء التفاصيل';
    }

    function jumpToNextPending() {
        const cards = Array.from(document.querySelectorAll('.item-card'));
        const target = cards.find(card => cardDecisionState(card) === 'pending');
        if (!target) {
            window.alert('كل العناصر تم اتخاذ قرار بشأنها.');
            return;
        }
        target.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }

    function toggleDecisionBar() {
        const bar = document.querySelector('.submit-sticky');
        if (!bar) return;
        const toggleBtn = bar.querySelector('.compact-toggle');
        bar.classList.toggle('submit-compact');
        const compact = bar.classList.contains('submit-compact');
        if (toggleBtn) {
            toggleBtn.textContent = compact ? 'عرض كل الأدوات' : 'عرض أقل';
        }
    }

    function applyFilter(mode, triggerBtn) {
        currentFilterMode = mode;
        document.querySelectorAll('.item-card').forEach(card => {
            const state = cardDecisionState(card);
            const shouldHide = mode !== 'all' && state !== mode;
            card.classList.toggle('hidden-card', shouldHide);
        });

        const buttons = document.querySelectorAll('.filter-btn');
        buttons.forEach(btn => btn.classList.remove('active'));
        if (triggerBtn) {
            triggerBtn.classList.add('active');
        } else {
            const active = document.querySelector(`.filter-btn[data-filter="${mode}"]`);
            if (active) active.classList.add('active');
        }
    }

    function setAllDecision(type) {
        const cards = document.querySelectorAll('.item-card');
        cards.forEach(card => {
            const target = card.querySelector(`input[value="${type}"]`);
            if (!target) return;
            target.checked = true;
            const reasonBox = card.querySelector('textarea.reject-input');
            if (!reasonBox) return;
            if (type === 'approved') {
                reasonBox.style.display = 'none';
                reasonBox.value = '';
            } else {
                reasonBox.style.display = 'block';
            }
        });
        updateDecisionStats();
        applyFilter(currentFilterMode);
        scheduleDraftSave();
    }

    function setAllRejectedWithReason() {
        const reason = window.prompt('اكتب سبب موحد لتطبيقه على كل العناصر المرفوضة:');
        if (reason === null) return;
        const normalizedReason = reason.trim();
        if (normalizedReason === '') {
            window.alert('يجب إدخال سبب واضح قبل تنفيذ الرفض الجماعي.');
            return;
        }
        document.querySelectorAll('.item-card').forEach(card => {
            const rejectRadio = card.querySelector('input[value="rejected"]');
            const reasonBox = card.querySelector('textarea.reject-input');
            if (!rejectRadio || !reasonBox) return;
            rejectRadio.checked = true;
            reasonBox.style.display = 'block';
            reasonBox.value = normalizedReason;
        });
        updateDecisionStats();
        applyFilter(currentFilterMode);
        scheduleDraftSave();
    }

    function clearAllDecision() {
        document.querySelectorAll('.item-card').forEach(card => {
            card.querySelectorAll('input[type="radio"]').forEach(r => r.checked = false);
            const reasonBox = card.querySelector('textarea.reject-input');
            if (reasonBox) {
                reasonBox.value = '';
                reasonBox.style.display = 'none';
            }
            card.classList.remove('error-highlight');
            const err = card.querySelector('.validation-error');
            if (err) err.style.display = 'none';
        });
        updateDecisionStats();
        applyFilter(currentFilterMode);
        scheduleDraftSave();
    }

    function toggleReason(elementId, show) {
        const el = document.getElementById(elementId);
        if (!el) return;
        if (show) {
            el.style.display = 'block';
            el.focus();
        } else {
            el.style.display = 'none';
            el.value = '';
        }
        updateDecisionStats();
        applyFilter(currentFilterMode);
        scheduleDraftSave();
    }

    function applyQuickReason(elementId, reason) {
        const reasonBox = document.getElementById(elementId);
        if (!reasonBox) return;
        const card = reasonBox.closest('.item-card');
        if (card) {
            const rejectRadio = card.querySelector('input[value="rejected"]');
            if (rejectRadio) rejectRadio.checked = true;
        }
        reasonBox.style.display = 'block';
        reasonBox.value = reason || '';
        reasonBox.focus();
        updateDecisionStats();
        applyFilter(currentFilterMode);
        scheduleDraftSave();
    }

    function collectDraftPayload() {
        const payload = {
            global_note: '',
            decisions: {}
        };
        const globalNote = document.querySelector('textarea[name="global_note"]');
        if (globalNote) payload.global_note = globalNote.value || '';

        document.querySelectorAll('.item-card').forEach(card => {
            const itemId = card.dataset.id || '';
            if (!itemId) return;
            const checked = card.querySelector('input[type="radio"]:checked');
            const reasonBox = card.querySelector('textarea.reject-input');
            const reason = reasonBox ? (reasonBox.value || '') : '';
            const status = checked ? checked.value : '';
            if (status || reason.trim() !== '') {
                payload.decisions[itemId] = { status: status, reason: reason };
            }
        });
        return payload;
    }

    function scheduleDraftSave() {
        if (draftSaveTimer) window.clearTimeout(draftSaveTimer);
        draftSaveTimer = window.setTimeout(saveDraft, 250);
    }

    function saveDraft() {
        const cards = document.querySelectorAll('.item-card');
        if (!cards.length) return;
        const payload = collectDraftPayload();
        const hasDecisions = Object.keys(payload.decisions).length > 0;
        const hasGlobalNote = (payload.global_note || '').trim() !== '';

        try {
            if (!hasDecisions && !hasGlobalNote) {
                localStorage.removeItem(reviewDraftKey);
                return;
            }
            localStorage.setItem(reviewDraftKey, JSON.stringify(payload));
        } catch (e) {}
    }

    function formHasUserInput() {
        const globalNote = document.querySelector('textarea[name="global_note"]');
        if (globalNote && globalNote.value.trim() !== '') return true;
        const checked = document.querySelector('.item-card input[type="radio"]:checked');
        if (checked) return true;
        const hasReason = Array.from(document.querySelectorAll('.item-card textarea.reject-input')).some(area => area.value.trim() !== '');
        return hasReason;
    }

    function restoreDraft() {
        if (!document.querySelector('.item-card')) return;
        let raw = null;
        try {
            raw = localStorage.getItem(reviewDraftKey);
        } catch (e) {
            raw = null;
        }
        if (!raw || formHasUserInput()) return;

        let payload = null;
        try {
            payload = JSON.parse(raw);
        } catch (e) {
            payload = null;
        }
        if (!payload || typeof payload !== 'object') return;

        const shouldRestore = window.confirm('تم العثور على مسودة قرارات سابقة لهذه المرحلة. هل تريد استعادتها؟');
        if (!shouldRestore) return;

        const globalNote = document.querySelector('textarea[name="global_note"]');
        if (globalNote && typeof payload.global_note === 'string') {
            globalNote.value = payload.global_note;
        }
        const decisions = payload.decisions && typeof payload.decisions === 'object' ? payload.decisions : {};
        Object.keys(decisions).forEach(itemId => {
            const safeId = String(itemId).replace(/"/g, '\\"');
            const card = document.querySelector('.item-card[data-id="' + safeId + '"]');
            if (!card) return;
            const status = decisions[itemId] && decisions[itemId].status ? String(decisions[itemId].status) : '';
            const reason = decisions[itemId] && decisions[itemId].reason ? String(decisions[itemId].reason) : '';

            if (status === 'approved' || status === 'rejected') {
                const target = card.querySelector('input[value="' + status + '"]');
                if (target) target.checked = true;
            }
            const reasonBox = card.querySelector('textarea.reject-input');
            if (reasonBox) {
                reasonBox.value = reason;
                if (status === 'rejected' || reason.trim() !== '') reasonBox.style.display = 'block';
                else reasonBox.style.display = 'none';
            }
        });
    }

    function validateForm() {
        let isValid = true;
        let firstError = null;
        const cards = document.querySelectorAll('.item-card');

        cards.forEach(card => {
            card.classList.remove('error-highlight');
            const errorMsg = card.querySelector('.validation-error');
            if (errorMsg) errorMsg.style.display = 'none';

            const approved = card.querySelector('input[value="approved"]:checked');
            const rejected = card.querySelector('input[value="rejected"]:checked');

            if (!approved && !rejected) {
                isValid = false;
                card.classList.add('error-highlight');
                if (errorMsg) errorMsg.style.display = 'block';
                if (!firstError) firstError = card;
            } else if (rejected) {
                const reasonBox = card.querySelector('textarea.reject-input');
                if (!reasonBox || reasonBox.value.trim() === '') {
                    isValid = false;
                    card.classList.add('error-highlight');
                    if (errorMsg) errorMsg.style.display = 'block';
                    if (!firstError) firstError = reasonBox || card;
                }
            }
        });

        if (!isValid) {
            applyFilter('all', document.querySelector('.filter-btn[data-filter="all"]'));
            if (firstError) firstError.scrollIntoView({ behavior: 'smooth', block: 'center' });
            return false;
        }
        return confirm("هل أنت متأكد من إرسال الرد النهائي؟");
    }

    document.addEventListener('DOMContentLoaded', function() {
        if (document.querySelector('.submit-sticky')) {
            document.body.classList.add('has-side-submit');
        }
        if (window.innerWidth < 720) {
            const bar = document.querySelector('.submit-sticky');
            if (bar) {
                bar.classList.add('submit-compact');
                const toggleBtn = bar.querySelector('.compact-toggle');
                if (toggleBtn) toggleBtn.textContent = 'عرض كل الأدوات';
            }
        }

        restoreDraft();

        document.querySelectorAll('.item-card input[type="radio"]').forEach(input => {
            input.addEventListener('change', function() {
                updateDecisionStats();
                applyFilter(currentFilterMode);
                scheduleDraftSave();
            });
        });
        document.querySelectorAll('.item-card textarea.reject-input').forEach(area => {
            area.addEventListener('input', scheduleDraftSave);
        });
        const globalNote = document.querySelector('textarea[name="global_note"]');
        if (globalNote) {
            globalNote.addEventListener('input', scheduleDraftSave);
        }

        applyFilter('all', document.querySelector('.filter-btn[data-filter="all"]'));
        updateDecisionStats();
    });
</script>

<style>
    /* منع تحديد النصوص وسحب الصور */
    body.brand-shell {
        -webkit-user-select: none; -moz-user-select: none; -ms-user-select: none; user-select: none;
    }
    img {
        pointer-events: none; /* يمنع التفاعل تماماً مع الصور */
        -webkit-user-drag: none; -khtml-user-drag: none; -moz-user-drag: none; -o-user-drag: none;
    }
    /* طبقة شفافة فوق الصور لمنع الحفظ (احتياطي) */
    .carousel-slide, .royal-card img { position: relative; }
    .carousel-slide::after, .royal-card img::after {
        content: ''; position: absolute; top: 0; left: 0; width: 100%; height: 100%; z-index: 99;
    }
</style>

<script>
    // 1. منع الزر الأيمن (Context Menu)
    document.addEventListener('contextmenu', function(e) {
        e.preventDefault();
    });

    // 2. منع اختصارات لوحة المفاتيح (Save, Print, DevTools)
    document.addEventListener('keydown', function(e) {
        // Ctrl+S (Save), Ctrl+P (Print), Ctrl+U (Source), F12 (DevTools)
        if ((e.ctrlKey && (e.key === 's' || e.key === 'S' || e.key === 'p' || e.key === 'P' || e.key === 'u' || e.key === 'U')) || e.key === 'F12') {
            e.preventDefault();
            e.stopPropagation();
        }
        // محاولة منع زر Print Screen (يعمل في بعض المتصفحات فقط)
        if (e.key === 'PrintScreen') {
            navigator.clipboard.writeText(''); // محاولة مسح الحافظة
            alert('لقطة الشاشة غير مسموحة حفاظاً على الحقوق.');
            // إخفاء المحتوى فوراً (تكتيك متقدم)
            document.body.style.display = 'none';
            setTimeout(() => document.body.style.display = 'block', 1000);
        }
    });

    // 3. منع روابط الصور من الفتح في نافذة جديدة (لإجبار العميل على البقاء هنا)
    document.addEventListener("DOMContentLoaded", function() {
        // استهداف الروابط التي تحتوي على صور فقط (إذا وجدت أي روابط أخرى)
        // ملاحظة: الروابط الرئيسية تم تعطيلها في PHP، هذا كود احتياطي
        const imgLinks = document.querySelectorAll('a[href$=".jpg"], a[href$=".png"], a[href$=".jpeg"], a[href$=".webp"]');
        imgLinks.forEach(link => {
            link.removeAttribute('target'); 
            link.href = 'javascript:void(0);'; 
            link.style.cursor = 'default';
            link.onclick = function(e) { e.preventDefault(); };
        });
    });
</script>

</body>
</html>
