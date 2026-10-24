<?php
// modules/social.php - (Royal Social V52.0 - Full Ideas Workflow Fixed)

error_reporting(E_ALL);
@ini_set('upload_max_filesize', '2048M');
@ini_set('post_max_size', '2048M');
@ini_set('max_execution_time', '3600');
@ini_set('max_input_time', '3600');
@ini_set('memory_limit', '2048M');
require_once __DIR__ . '/modules/jobs/event_log_runtime.php';
require_once __DIR__ . '/modules/job_invoice_runtime.php';

$app_module_embedded = !empty($app_module_embedded);

if (!isset($conn, $job) || !is_array($job) || !isset($job['id'])) {
    http_response_code(403);
    exit('Forbidden');
}

// 1. الدوال المساعدة
function safe_redirect($id) {
    if (!headers_sent()) {
        header('Location: job_details.php?id=' . (int)$id);
        exit;
    }
    echo "<script>window.location.href = 'job_details.php?id=" . (int)$id . "';</script>";
    exit;
}

function social_is_ajax_request(): bool {
    $requestedWith = strtolower(trim((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')));
    if ($requestedWith === 'xmlhttprequest') {
        return true;
    }
    $accept = strtolower(trim((string)($_SERVER['HTTP_ACCEPT'] ?? '')));
    return strpos($accept, 'application/json') !== false;
}

function social_finish_request(int $jobId, array $payload = []): void {
    if (social_is_ajax_request()) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
    safe_redirect($jobId);
}

function social_fetch_posts(mysqli $conn, int $jobId): array {
    $rows = [];
    $stmt = $conn->prepare("SELECT * FROM social_posts WHERE job_id = ? ORDER BY post_index");
    if ($stmt) {
        $stmt->bind_param('i', $jobId);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($res && ($row = $res->fetch_assoc())) {
            $rows[] = $row;
        }
        $stmt->close();
    }
    return $rows;
}

function social_fetch_posts_by_index(mysqli $conn, int $jobId): array {
    $map = [];
    foreach (social_fetch_posts($conn, $jobId) as $row) {
        $map[(int)($row['post_index'] ?? 0)] = $row;
    }
    return $map;
}

function social_fetch_job_files(mysqli $conn, int $jobId): array {
    $rows = [];
    $stmt = $conn->prepare("SELECT * FROM job_files WHERE job_id = ? ORDER BY id DESC");
    if ($stmt) {
        $stmt->bind_param('i', $jobId);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($res && ($row = $res->fetch_assoc())) {
            $rows[] = $row;
        }
        $stmt->close();
    }
    return $rows;
}

function social_insert_job_file(mysqli $conn, int $jobId, string $filePath, string $stage, string $description, string $uploadedBy): bool {
    if ($jobId <= 0 || trim($filePath) === '') {
        return false;
    }
    $stmt = $conn->prepare("INSERT INTO job_files (job_id, file_path, stage, description, uploaded_by) VALUES (?, ?, ?, ?, ?)");
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('issss', $jobId, $filePath, $stage, $description, $uploadedBy);
    $ok = $stmt->execute();
    $stmt->close();
    return (bool)$ok;
}

function social_stage_text_preview(string $value, int $limit = 220): string {
    $value = trim((string)preg_replace('/\s+/u', ' ', $value));
    if ($value === '') {
        return '';
    }
    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        if (mb_strlen($value, 'UTF-8') <= $limit) {
            return $value;
        }
        return rtrim(mb_substr($value, 0, $limit - 1, 'UTF-8')) . '…';
    }
    if (strlen($value) <= $limit) {
        return $value;
    }
    return rtrim(substr($value, 0, $limit - 1)) . '...';
}

function social_render_posts_summary(array $posts, string $field): string {
    $lines = [];
    foreach ($posts as $post) {
        $postIndex = (int)($post['post_index'] ?? 0);
        $value = trim((string)($post[$field] ?? ''));
        if ($postIndex <= 0 || $value === '') {
            continue;
        }
        $lines[] = 'بوست #' . $postIndex . ': ' . social_stage_text_preview($value);
    }
    return trim(implode("\n", $lines));
}

function social_render_designs_summary(array $posts): string {
    $lines = [];
    foreach ($posts as $post) {
        $postIndex = (int)($post['post_index'] ?? 0);
        if ($postIndex <= 0) {
            continue;
        }
        $decoded = json_decode((string)($post['design_path'] ?? ''), true);
        $images = is_array($decoded) ? array_values(array_filter($decoded)) : [];
        if (!empty($images)) {
            $lines[] = 'بوست #' . $postIndex . ': ' . count($images) . ' ملف/ملفات تصميم';
        }
    }
    return trim(implode("\n", $lines));
}

function social_count_job_files_by_stage(array $jobFiles, string $stage): int {
    $count = 0;
    foreach ($jobFiles as $file) {
        if (trim((string)($file['stage'] ?? '')) === $stage) {
            $count++;
        }
    }
    return $count;
}

function social_sync_stage_rollup(mysqli $conn, array $job, int $userId, string $userName): void {
    $jobId = (int)($job['id'] ?? 0);
    if ($jobId <= 0) {
        return;
    }

    $posts = social_fetch_posts($conn, $jobId);
    $jobFiles = social_fetch_job_files($conn, $jobId);
    $ideasSummary = social_render_posts_summary($posts, 'idea_text');
    $contentSummary = social_render_posts_summary($posts, 'content_text');
    $designsSummary = social_render_designs_summary($posts);
    $referenceFilesCount = (string)social_count_job_files_by_stage($jobFiles, 'idea');
    $contentFilesCount = (string)social_count_job_files_by_stage($jobFiles, 'content');
    $sourceFilesCount = (string)social_count_job_files_by_stage($jobFiles, 'design');

    $stageFields = [
        'briefing' => [
            'ideas_summary' => $ideasSummary,
            'reference_files_count' => $referenceFilesCount,
        ],
        'idea_review' => [
            'ideas_summary' => $ideasSummary,
            'reference_files_count' => $referenceFilesCount,
        ],
        'content_writing' => [
            'ideas_summary' => $ideasSummary,
            'content_summary' => $contentSummary,
            'content_files_count' => $contentFilesCount,
        ],
        'content_review' => [
            'ideas_summary' => $ideasSummary,
            'content_summary' => $contentSummary,
            'content_files_count' => $contentFilesCount,
        ],
        'designing' => [
            'ideas_summary' => $ideasSummary,
            'content_summary' => $contentSummary,
            'designs_summary' => $designsSummary,
            'source_files_count' => $sourceFilesCount,
        ],
        'design_review' => [
            'ideas_summary' => $ideasSummary,
            'content_summary' => $contentSummary,
            'designs_summary' => $designsSummary,
            'source_files_count' => $sourceFilesCount,
        ],
        'publishing' => [
            'ideas_summary' => $ideasSummary,
            'content_summary' => $contentSummary,
            'designs_summary' => $designsSummary,
            'source_files_count' => $sourceFilesCount,
        ],
        'accounting' => [
            'ideas_summary' => $ideasSummary,
            'content_summary' => $contentSummary,
            'designs_summary' => $designsSummary,
        ],
        'completed' => [
            'ideas_summary' => $ideasSummary,
            'content_summary' => $contentSummary,
            'designs_summary' => $designsSummary,
        ],
    ];

    foreach ($stageFields as $stageKey => $fields) {
        foreach ($fields as $fieldKey => $fieldValue) {
            app_stage_data_set($conn, $jobId, $stageKey, $fieldKey, (string)$fieldValue, $userId, $userName);
        }
    }
}

function social_delete_post(mysqli $conn, array $job, int $postId, int $userId, string $userName): bool {
    $jobId = (int)($job['id'] ?? 0);
    if ($jobId <= 0 || $postId <= 0) {
        return false;
    }

    $stmtPost = $conn->prepare("SELECT id, design_path FROM social_posts WHERE id = ? AND job_id = ? LIMIT 1");
    if (!$stmtPost) {
        return false;
    }
    $stmtPost->bind_param('ii', $postId, $jobId);
    $stmtPost->execute();
    $postRow = $stmtPost->get_result()->fetch_assoc();
    $stmtPost->close();
    if (!$postRow) {
        social_sync_stage_rollup($conn, $job, $userId, $userName);
        return true;
    }

    $decoded = json_decode((string)($postRow['design_path'] ?? ''), true);
    $designPaths = is_array($decoded) ? array_values(array_filter($decoded)) : [];
    foreach ($designPaths as $designPath) {
        if (is_string($designPath) && strpos($designPath, 'uploads/') === 0) {
            app_safe_unlink($designPath, __DIR__ . '/uploads');
        }
    }

    $deletedFromDb = false;
    $stmtDel = $conn->prepare("DELETE FROM social_posts WHERE id = ? AND job_id = ?");
    if ($stmtDel) {
        $stmtDel->bind_param('ii', $postId, $jobId);
        $stmtDel->execute();
        $deletedFromDb = $stmtDel->affected_rows > 0;
        $stmtDel->close();
    }
    if (!$deletedFromDb) {
        return false;
    }

    $remainingIds = [];
    $stmtRemaining = $conn->prepare("SELECT id FROM social_posts WHERE job_id = ? ORDER BY post_index ASC, id ASC");
    if ($stmtRemaining) {
        $stmtRemaining->bind_param('i', $jobId);
        $stmtRemaining->execute();
        $res = $stmtRemaining->get_result();
        while ($res && ($row = $res->fetch_assoc())) {
            $remainingIds[] = (int)($row['id'] ?? 0);
        }
        $stmtRemaining->close();
    }

    $index = 1;
    $stmtIndex = $conn->prepare("UPDATE social_posts SET post_index = ? WHERE id = ? AND job_id = ?");
    foreach ($remainingIds as $remainingId) {
        if ($remainingId <= 0) {
            continue;
        }
        if ($stmtIndex) {
            $stmtIndex->bind_param('iii', $index, $remainingId, $jobId);
            $stmtIndex->execute();
        }
        $index++;
    }
    if ($stmtIndex) {
        $stmtIndex->close();
    }

    $newQuantity = max(0, count($remainingIds));
    $stmtQty = $conn->prepare("UPDATE job_orders SET quantity = ? WHERE id = ?");
    if ($stmtQty) {
        $stmtQty->bind_param('ii', $newQuantity, $jobId);
        $stmtQty->execute();
        $stmtQty->close();
    }
    social_sync_stage_rollup($conn, $job, $userId, $userName);
    return true;
}

function social_ensure_post_placeholders(mysqli $conn, int $jobId, int $targetCount): void {
    if ($jobId <= 0 || $targetCount <= 0) {
        return;
    }

    $existing = [];
    $stmtExisting = $conn->prepare("SELECT post_index FROM social_posts WHERE job_id = ?");
    if ($stmtExisting) {
        $stmtExisting->bind_param('i', $jobId);
        $stmtExisting->execute();
        $res = $stmtExisting->get_result();
        while ($res && ($row = $res->fetch_assoc())) {
            $existing[(int)($row['post_index'] ?? 0)] = true;
        }
        $stmtExisting->close();
    }

    $stmtCreate = $conn->prepare("INSERT INTO social_posts (job_id, post_index) VALUES (?, ?)");
    if (!$stmtCreate) {
        return;
    }
    for ($i = 1; $i <= $targetCount; $i++) {
        if (!empty($existing[$i])) {
            continue;
        }
        $stmtCreate->bind_param('ii', $jobId, $i);
        $stmtCreate->execute();
    }
    $stmtCreate->close();
}

function social_add_post(mysqli $conn, array $job, int $userId, string $userName, string $ideaText = '', string $contentText = ''): array {
    $jobId = (int)($job['id'] ?? 0);
    if ($jobId <= 0) {
        return ['ok' => false, 'error' => 'تعذر تحديد العملية.'];
    }

    social_ensure_post_placeholders($conn, $jobId, (int)($job['quantity'] ?? 0));

    $nextIndex = 1;
    $stmtMax = $conn->prepare("SELECT IFNULL(MAX(post_index), 0) + 1 AS next_index FROM social_posts WHERE job_id = ?");
    if ($stmtMax) {
        $stmtMax->bind_param('i', $jobId);
        $stmtMax->execute();
        $nextIndex = max(1, (int)(($stmtMax->get_result()->fetch_assoc()['next_index'] ?? 1)));
        $stmtMax->close();
    }

    $ideaText = trim($ideaText);
    $contentText = trim($contentText);
    $postStatus = $contentText !== '' ? 'pending_review' : 'pending';

    $stmtInsert = $conn->prepare("
        INSERT INTO social_posts (job_id, post_index, idea_text, idea_status, content_text, status)
        VALUES (?, ?, ?, 'pending', ?, ?)
    ");
    if (!$stmtInsert) {
        return ['ok' => false, 'error' => 'تعذر تجهيز إضافة البوست.'];
    }
    $stmtInsert->bind_param('iisss', $jobId, $nextIndex, $ideaText, $contentText, $postStatus);
    $ok = $stmtInsert->execute();
    $newPostId = (int)$stmtInsert->insert_id;
    $stmtInsert->close();
    if (!$ok || $newPostId <= 0) {
        return ['ok' => false, 'error' => 'تعذر إضافة البوست.'];
    }

    $newQuantity = $nextIndex;
    $stmtCount = $conn->prepare("SELECT COUNT(*) AS c FROM social_posts WHERE job_id = ?");
    if ($stmtCount) {
        $stmtCount->bind_param('i', $jobId);
        $stmtCount->execute();
        $newQuantity = max($nextIndex, (int)(($stmtCount->get_result()->fetch_assoc()['c'] ?? $nextIndex)));
        $stmtCount->close();
    }

    $stmtQty = $conn->prepare("UPDATE job_orders SET quantity = ? WHERE id = ?");
    if ($stmtQty) {
        $stmtQty->bind_param('ii', $newQuantity, $jobId);
        $stmtQty->execute();
        $stmtQty->close();
    }

    if (function_exists('job_event_log_add')) {
        job_event_log_add(
            $conn,
            $jobId,
            'social_post_added',
            'إضافة بوست جديد',
            'تمت إضافة بوست جديد للعملية.',
            [
                'post_id' => $newPostId,
                'post_index' => $nextIndex,
            ],
            (string)($job['current_stage'] ?? ''),
            $userId,
            $userName
        );
    }

    social_sync_stage_rollup($conn, $job, $userId, $userName);
    return [
        'ok' => true,
        'post_id' => $newPostId,
        'post_index' => $nextIndex,
        'quantity' => $newQuantity,
    ];
}

function social_operation_upload_max_size(): int {
    return 2048 * 1024 * 1024;
}

function social_operation_allowed_extensions(): array {
    return ['jpg', 'jpeg', 'png', 'webp', 'gif', 'pdf', 'zip', 'rar', '7z', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'psd', 'ai', 'eps', 'svg', 'mp4', 'mov', 'avi', 'mkv'];
}

function social_file_extension(string $path): string {
    return strtolower(pathinfo(parse_url($path, PHP_URL_PATH) ?: $path, PATHINFO_EXTENSION));
}

function social_is_image_asset(string $path): bool {
    return in_array(social_file_extension($path), ['jpg', 'jpeg', 'png', 'webp', 'gif'], true);
}

function social_is_video_asset(string $path): bool {
    return in_array(social_file_extension($path), ['mp4', 'mov', 'avi', 'mkv'], true);
}

function social_asset_label(string $path): string {
    $name = basename(parse_url($path, PHP_URL_PATH) ?: $path);
    if ($name !== '') {
        return $name;
    }
    return social_is_video_asset($path) ? 'فيديو' : 'فتح الملف';
}

function social_asset_thumb_path(string $path): string {
    $path = trim($path);
    if ($path === '' || !social_is_image_asset($path)) {
        return '';
    }
    $source = __DIR__ . '/' . ltrim($path, '/');
    if (!is_file($source)) {
        return '';
    }
    $thumbRelDir = 'uploads/thumbs/social';
    $thumbAbsDir = __DIR__ . '/' . $thumbRelDir;
    if (!is_dir($thumbAbsDir)) {
        @mkdir($thumbAbsDir, 0755, true);
    }
    if (!is_dir($thumbAbsDir) || !is_writable($thumbAbsDir)) {
        return '';
    }

    $thumbRel = $thumbRelDir . '/' . md5($path . '|' . (string)@filemtime($source)) . '.jpg';
    $thumbAbs = __DIR__ . '/' . $thumbRel;
    if (is_file($thumbAbs)) {
        return $thumbRel;
    }

    $info = @getimagesize($source);
    if (!$info || empty($info[0]) || empty($info[1])) {
        return '';
    }

    $imageType = (int)($info[2] ?? 0);
    $create = null;
    if ($imageType === IMAGETYPE_JPEG && function_exists('imagecreatefromjpeg')) {
        $create = 'imagecreatefromjpeg';
    } elseif ($imageType === IMAGETYPE_PNG && function_exists('imagecreatefrompng')) {
        $create = 'imagecreatefrompng';
    } elseif ($imageType === IMAGETYPE_WEBP && function_exists('imagecreatefromwebp')) {
        $create = 'imagecreatefromwebp';
    } elseif ($imageType === IMAGETYPE_GIF && function_exists('imagecreatefromgif')) {
        $create = 'imagecreatefromgif';
    }
    if (!$create || !function_exists('imagecreatetruecolor') || !function_exists('imagecopyresampled') || !function_exists('imagejpeg')) {
        return '';
    }

    $src = @$create($source);
    if (!$src) {
        return '';
    }

    $srcW = max(1, (int)$info[0]);
    $srcH = max(1, (int)$info[1]);
    $max = 360;
    $ratio = min($max / $srcW, $max / $srcH, 1);
    $dstW = max(1, (int)round($srcW * $ratio));
    $dstH = max(1, (int)round($srcH * $ratio));
    $dst = imagecreatetruecolor($dstW, $dstH);
    if (!$dst) {
        imagedestroy($src);
        return '';
    }

    $white = imagecolorallocate($dst, 18, 18, 18);
    imagefill($dst, 0, 0, $white);
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $dstW, $dstH, $srcW, $srcH);
    @imagejpeg($dst, $thumbAbs, 78);
    imagedestroy($dst);
    imagedestroy($src);

    return is_file($thumbAbs) ? $thumbRel : '';
}

function social_asset_payload(string $path): array {
    $path = trim($path);
    return [
        'path' => $path,
        'thumb' => social_asset_thumb_path($path),
        'label' => social_asset_label($path),
        'is_image' => social_is_image_asset($path),
        'is_video' => social_is_video_asset($path),
    ];
}

function social_assets_payload(array $paths): array {
    $items = [];
    foreach ($paths as $path) {
        $path = trim((string)$path);
        if ($path === '') {
            continue;
        }
        $items[] = social_asset_payload($path);
    }
    return $items;
}

// 3. استخراج البيانات الفنية
$raw_text = $job['job_details'] ?? '';
function get_spec($pattern, $text) {
    preg_match($pattern, $text, $matches);
    return isset($matches[1]) ? trim($matches[1]) : null;
}

function social_extract_latest_note($notes, $label) {
    $pattern = '/\[' . preg_quote($label, '/') . '\]:\s*(.*?)(?=\n\[|$)/su';
    if (!preg_match_all($pattern, (string)$notes, $matches) || empty($matches[1])) {
        return '';
    }
    return trim((string)end($matches[1]));
}

function social_stage_value(mysqli $conn, array $job, string $stageKey, string $fieldKey, string $fallbackLabel = '', string $default = ''): string {
    $value = app_stage_data_get($conn, (int)($job['id'] ?? 0), $stageKey, $fieldKey, '');
    if ($value !== '') {
        return $value;
    }
    if ($fallbackLabel !== '') {
        $legacy = social_extract_latest_note($job['notes'] ?? '', $fallbackLabel);
        if ($legacy !== '') {
            return $legacy;
        }
    }
    return $default;
}

$specs = [
    'platforms' => get_spec('/(?:المنصات|المستهدفة):\s*(.*)/u', $raw_text) ?? 'غير محدد',
    'posts_num' => get_spec('/(?:عدد البنود|عدد البوستات\/الفيديوهات):\s*(\d+)/u', $raw_text),
    'goal'      => get_spec('/(?:الهدف|Goal):\s*(.*)/u', $raw_text) ?? 'غير محدد',
    'audience'  => get_spec('/(?:الجمهور|Audience):\s*(.*)/u', $raw_text) ?? 'عام',
    'budget'    => get_spec('/(?:الميزانية|Budget):\s*(.*)/u', $raw_text) ?? '-',
];

$posts_count = ($job['quantity'] > 0) ? intval($job['quantity']) : intval($specs['posts_num'] ?? 1);

// رابط العميل
$client_link = app_client_review_link($conn, $job);

$fallbackWorkflowLabels = [
    'briefing'        => '1. الأفكار',
    'idea_review'     => '2. مراجعة الأفكار',
    'content_writing' => '3. كتابة المحتوى',
    'content_review'  => '4. مراجعة المحتوى',
    'designing'       => '5. التصميم',
    'design_review'   => '6. مراجعة التصميم',
    'publishing'      => '7. النشر',
    'accounting'      => '8. الحسابات',
    'completed'       => '9. الأرشيف',
];
$workflow = app_operation_workflow($conn, 'social', $fallbackWorkflowLabels);
$allowed_stage_keys = array_keys($workflow);
$first_stage = (string)array_key_first($workflow);
if ($first_stage === '') {
    $first_stage = 'briefing';
}
$curr = app_workflow_current_stage($workflow, (string)($job['current_stage'] ?? ''), $first_stage);
$can_force_stage = app_user_can('jobs.manage_all');

// 4. معالجة الطلبات
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (!app_verify_csrf($_POST['_csrf_token'] ?? '')) {
        http_response_code(403);
        exit('Invalid CSRF token');
    }

    $user_name = $_SESSION['name'] ?? 'Team';

    // === ميزات جديدة: التعليقات، الحذف المتقدم، التحكم الجبري ===
    if (isset($_POST['add_internal_comment'])) {
        if(!empty($_POST['comment_text'])) {
            $c_text = $conn->real_escape_string($_POST['comment_text']);
            $timestamp = date('Y-m-d H:i');
            $new_note = "\n[تعليق داخلي $user_name ($timestamp)]: $c_text";
            $jobIdInt = (int)$job['id'];
            $stmtNote = $conn->prepare("UPDATE job_orders SET notes = CONCAT(IFNULL(notes, ''), ?) WHERE id = ?");
            if ($stmtNote) {
                $stmtNote->bind_param('si', $new_note, $jobIdInt);
                $stmtNote->execute();
                $stmtNote->close();
            }
        }
        safe_redirect($job['id']);
    }

    if (isset($_POST['force_stage_change']) && $can_force_stage) {
        $target_stage = trim((string)($_POST['target_stage'] ?? ''));
        if (in_array($target_stage, $allowed_stage_keys, true)) {
            app_update_job_stage($conn, (int)$job['id'], $target_stage);
        }
        safe_redirect($job['id']);
    }

    if (isset($_POST['delete_file'])) {
        $fid = intval($_POST['file_id']);
        $f = null;
        $jobIdInt = (int)$job['id'];
        $stmtFile = $conn->prepare("SELECT file_path, stage, description, uploaded_by FROM job_files WHERE id = ? AND job_id = ? LIMIT 1");
        if ($stmtFile) {
            $stmtFile->bind_param('ii', $fid, $jobIdInt);
            $stmtFile->execute();
            $f = $stmtFile->get_result()->fetch_assoc();
            $stmtFile->close();
        }
        if ($f && !empty($f['file_path'])) {
            app_safe_unlink((string)$f['file_path'], __DIR__ . '/uploads');
        }
        $stmtDelFile = $conn->prepare("DELETE FROM job_files WHERE id = ? AND job_id = ?");
        if ($stmtDelFile) {
            $stmtDelFile->bind_param('ii', $fid, $jobIdInt);
            $stmtDelFile->execute();
            $stmtDelFile->close();
        }
        job_event_log_add(
            $conn,
            (int)$job['id'],
            'attachment_deleted',
            'حذف مرفق من العملية',
            'تم حذف ملف من مرفقات العملية.',
            [
                'file_id' => $fid,
                'file_path' => (string)($f['file_path'] ?? ''),
                'stage_key' => (string)($f['stage'] ?? ''),
                'description' => (string)($f['description'] ?? ''),
                'uploaded_by' => (string)($f['uploaded_by'] ?? ''),
            ],
            (string)($f['stage'] ?? ''),
            (int)($_SESSION['user_id'] ?? 0),
            (string)$user_name
        );
        social_sync_stage_rollup($conn, $job, (int)($_SESSION['user_id'] ?? 0), (string)$user_name);
        social_finish_request((int)$job['id'], [
            'ok' => true,
            'message' => 'تم حذف الملف بنجاح.',
            'reload' => true,
            'redirect' => 'job_details.php?id=' . (int)$job['id'],
        ]);
    }

    if (isset($_POST['delete_design_img'])) {
        $pid = intval($_POST['post_id']);
        $img_to_del = trim((string)($_POST['img_path'] ?? ''));
        $jobIdInt = (int)$job['id'];
        $row = null;
        $stmtImg = $conn->prepare("SELECT design_path FROM social_posts WHERE id = ? AND job_id = ? LIMIT 1");
        if ($stmtImg) {
            $stmtImg->bind_param('ii', $pid, $jobIdInt);
            $stmtImg->execute();
            $row = $stmtImg->get_result()->fetch_assoc();
            $stmtImg->close();
        }
        if ($row && !empty($row['design_path'])) {
            $images = json_decode($row['design_path'], true) ?? [];
            $new_images = array_filter($images, function($img) use ($img_to_del) { return $img !== $img_to_del; });
            $isListedImage = in_array($img_to_del, $images, true);
            if ($isListedImage && strpos($img_to_del, 'uploads/') === 0) {
                app_safe_unlink($img_to_del, __DIR__ . '/uploads');
            }
            $json_paths = json_encode(array_values($new_images));
            $stmtUpdImg = $conn->prepare("UPDATE social_posts SET design_path = ? WHERE id = ? AND job_id = ?");
            if ($stmtUpdImg) {
                $stmtUpdImg->bind_param('sii', $json_paths, $pid, $jobIdInt);
                $stmtUpdImg->execute();
                $stmtUpdImg->close();
            }
        }
        social_sync_stage_rollup($conn, $job, (int)($_SESSION['user_id'] ?? 0), (string)$user_name);
        social_finish_request((int)$job['id'], [
            'ok' => true,
            'message' => 'تم حذف الملف بنجاح.',
            'reload' => true,
            'redirect' => 'job_details.php?id=' . (int)$job['id'],
        ]);
    }

    if (isset($_POST['delete_social_post'])) {
        $postId = (int)($_POST['post_id'] ?? 0);
        $deleted = social_delete_post($conn, $job, $postId, (int)($_SESSION['user_id'] ?? 0), (string)$user_name);
        social_finish_request((int)$job['id'], [
            'ok' => $deleted,
            'message' => $deleted ? 'تم حذف البوست بالكامل وإعادة ترتيب البوستات.' : 'تعذر حذف البوست.',
            'reload' => true,
            'redirect' => 'job_details.php?id=' . (int)$job['id'],
        ]);
    }

    if (isset($_POST['add_social_post'])) {
        $result = social_add_post(
            $conn,
            $job,
            (int)($_SESSION['user_id'] ?? 0),
            (string)$user_name,
            (string)($_POST['new_post_idea'] ?? ''),
            (string)($_POST['new_post_content'] ?? '')
        );
        social_finish_request((int)$job['id'], [
            'ok' => !empty($result['ok']),
            'message' => !empty($result['ok']) ? 'تمت إضافة البوست وترحيله لباقي مراحل العملية.' : '',
            'error' => (string)($result['error'] ?? ''),
            'reload' => true,
            'redirect' => 'job_details.php?id=' . (int)$job['id'],
        ]);
    }

    // A. مرحلة الفكرة (تم التعديل لتكون بنود منفصلة)
    if (isset($_POST['upload_ref_files_only'])) {
        $uploadedCount = 0;
        $uploadedFiles = [];
        $errors = [];
        if (!empty($_FILES['ref_files']['name'][0])) {
            foreach ($_FILES['ref_files']['name'] as $i => $name) {
                if (($_FILES['ref_files']['error'][$i] ?? UPLOAD_ERR_NO_FILE) == 0) {
                    $stored = app_store_uploaded_file([
                        'name' => $_FILES['ref_files']['name'][$i] ?? '',
                        'type' => $_FILES['ref_files']['type'][$i] ?? '',
                        'tmp_name' => $_FILES['ref_files']['tmp_name'][$i] ?? '',
                        'error' => $_FILES['ref_files']['error'][$i] ?? UPLOAD_ERR_NO_FILE,
                        'size' => $_FILES['ref_files']['size'][$i] ?? 0,
                    ], [
                        'dir' => 'uploads/briefs',
                        'prefix' => 'ref_',
                        'max_size' => social_operation_upload_max_size(),
                        'allowed_extensions' => social_operation_allowed_extensions(),
                    ]);
                    if (!empty($stored['ok'])) {
                        $target = (string)$stored['path'];
                        social_insert_job_file($conn, (int)$job['id'], $target, 'idea', 'ملف استدلالي', (string)$user_name);
                        $uploadedCount++;
                        $uploadedFiles[] = $target;
                    } else {
                        $errors[] = (string)($stored['error'] ?? 'Upload failed.');
                    }
                } elseif (($_FILES['ref_files']['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                    $errors[] = app_upload_error_message((int)$_FILES['ref_files']['error'][$i]);
                }
            }
        }
        social_sync_stage_rollup($conn, $job, (int)($_SESSION['user_id'] ?? 0), (string)$user_name);
        $ok = $uploadedCount > 0;
        social_finish_request((int)$job['id'], [
            'ok' => $ok,
            'uploaded_count' => $uploadedCount,
            'uploaded_files' => $uploadedFiles,
            'error' => !$ok ? trim(implode(' | ', array_filter($errors))) : '',
            'redirect' => 'job_details.php?id=' . (int)$job['id'],
        ]);
    }

    if (isset($_POST['save_idea_batch']) || isset($_POST['send_idea_batch'])) {
        // إنشاء السجلات إن لم تكن موجودة
        $jobIdInt = (int)$job['id'];
        $postCount = 0;
        $stmtCount = $conn->prepare("SELECT COUNT(*) AS c FROM social_posts WHERE job_id = ?");
        if ($stmtCount) {
            $stmtCount->bind_param('i', $jobIdInt);
            $stmtCount->execute();
            $postCount = (int)(($stmtCount->get_result()->fetch_assoc()['c'] ?? 0));
            $stmtCount->close();
        }
        if($postCount === 0){
            $stmtCreatePost = $conn->prepare("INSERT INTO social_posts (job_id, post_index) VALUES (?, ?)");
            for ($i=1; $i <= $posts_count; $i++) { 
                if ($stmtCreatePost) {
                    $stmtCreatePost->bind_param('ii', $jobIdInt, $i);
                    $stmtCreatePost->execute();
                }
            }
            if ($stmtCreatePost) {
                $stmtCreatePost->close();
            }
        }

        $postsByIndex = social_fetch_posts_by_index($conn, (int)$job['id']);

        if(isset($_POST['ideas'])){
            foreach ($_POST['ideas'] as $postIndex => $text) {
                $postIndex = (int)$postIndex;
                if ($postIndex <= 0 || !isset($postsByIndex[$postIndex])) {
                    continue;
                }
                $postId = (int)($postsByIndex[$postIndex]['id'] ?? 0);
                if ($postId <= 0) {
                    continue;
                }
                $ideaText = (string)$text;
                $stmtIdea = $conn->prepare("UPDATE social_posts SET idea_text = ?, idea_status = 'pending' WHERE id = ? AND job_id = ?");
                if ($stmtIdea) {
                    $stmtIdea->bind_param('sii', $ideaText, $postId, $jobIdInt);
                    $stmtIdea->execute();
                    $stmtIdea->close();
                }
            }
        }

        if (!empty($_FILES['ref_files']['name'][0])) {
            foreach ($_FILES['ref_files']['name'] as $i => $name) {
                if ($_FILES['ref_files']['error'][$i] == 0) {
                    $stored = app_store_uploaded_file([
                        'name' => $_FILES['ref_files']['name'][$i] ?? '',
                        'type' => $_FILES['ref_files']['type'][$i] ?? '',
                        'tmp_name' => $_FILES['ref_files']['tmp_name'][$i] ?? '',
                        'error' => $_FILES['ref_files']['error'][$i] ?? UPLOAD_ERR_NO_FILE,
                        'size' => $_FILES['ref_files']['size'][$i] ?? 0,
                    ], [
                        'dir' => 'uploads/briefs',
                        'prefix' => 'ref_',
                        'max_size' => social_operation_upload_max_size(),
                        'allowed_extensions' => social_operation_allowed_extensions(),
                    ]);
                    if (!empty($stored['ok'])) {
                        $target = (string)$stored['path'];
                        social_insert_job_file($conn, (int)$job['id'], $target, 'idea', 'ملف استدلالي', (string)$user_name);
                    }
                }
            }
        }

        if(isset($_POST['send_idea_batch'])) {
            app_update_job_stage($conn, (int)$job['id'], 'idea_review');
        }
        social_sync_stage_rollup($conn, $job, (int)($_SESSION['user_id'] ?? 0), (string)$user_name);
        social_finish_request((int)$job['id'], [
            'ok' => true,
            'message' => isset($_POST['send_idea_batch']) ? 'تم حفظ الأفكار وإرسالها للمراجعة.' : 'تم حفظ الأفكار بنجاح.',
            'reload' => isset($_POST['send_idea_batch']),
            'redirect' => 'job_details.php?id=' . (int)$job['id'],
        ]);
    }

    // B. المحتوى
    if (isset($_POST['save_content']) || isset($_POST['send_content'])) {
        $stmtUpdateContent = $conn->prepare("UPDATE social_posts SET content_text = ?, status = 'pending_review' WHERE id = ? AND job_id = ?");
        if(isset($_POST['content'])){
            foreach ($_POST['content'] as $pid => $text) {
                $pid = (int)$pid;
                if ($pid <= 0) {
                    continue;
                }
                $contentText = (string)$text;
                $jobIdInt = (int)$job['id'];
                $stmtUpdateContent->bind_param('sii', $contentText, $pid, $jobIdInt);
                $stmtUpdateContent->execute();
            }
        }
        $stmtUpdateContent->close();

        if (!empty($_FILES['content_docs']['name'][0])) {
            foreach ($_FILES['content_docs']['name'] as $i => $name) {
                if ($_FILES['content_docs']['error'][$i] == 0) {
                    $stored = app_store_uploaded_file([
                        'name' => $_FILES['content_docs']['name'][$i] ?? '',
                        'type' => $_FILES['content_docs']['type'][$i] ?? '',
                        'tmp_name' => $_FILES['content_docs']['tmp_name'][$i] ?? '',
                        'error' => $_FILES['content_docs']['error'][$i] ?? UPLOAD_ERR_NO_FILE,
                        'size' => $_FILES['content_docs']['size'][$i] ?? 0,
                    ], [
                        'dir' => 'uploads/briefs',
                        'prefix' => 'doc_',
                        'max_size' => social_operation_upload_max_size(),
                        'allowed_extensions' => social_operation_allowed_extensions(),
                    ]);
                    if (!empty($stored['ok'])) {
                        $target = (string)$stored['path'];
                        social_insert_job_file($conn, (int)$job['id'], $target, 'content', 'ملف محتوى', (string)$user_name);
                    }
                }
            }
        }

        if(isset($_POST['send_content'])) {
            app_update_job_stage($conn, (int)$job['id'], 'content_review');
        }
        social_sync_stage_rollup($conn, $job, (int)($_SESSION['user_id'] ?? 0), (string)$user_name);
        social_finish_request((int)$job['id'], [
            'ok' => true,
            'message' => isset($_POST['send_content']) ? 'تم حفظ الكابشن وإرساله للمراجعة.' : 'تم حفظ الكابشن بنجاح.',
            'reload' => isset($_POST['send_content']),
            'redirect' => 'job_details.php?id=' . (int)$job['id'],
        ]);
    }

    // C. التصميم
    if (isset($_POST['upload_designs']) || isset($_POST['send_designs'])) {
        $designUploadedCount = 0;
        $designErrors = [];
        $updatedPosts = [];
        if (!empty($_FILES['design_files']['name'])) {
            foreach ($_FILES['design_files']['name'] as $pid => $files) {
                $pid = (int)$pid;
                $existing_row = null;
                $stmtExisting = $conn->prepare("SELECT design_path FROM social_posts WHERE id = ? AND job_id = ? LIMIT 1");
                if ($stmtExisting) {
                    $jobIdInt = (int)$job['id'];
                    $stmtExisting->bind_param('ii', $pid, $jobIdInt);
                    $stmtExisting->execute();
                    $existing_row = $stmtExisting->get_result()->fetch_assoc();
                    $stmtExisting->close();
                }
                $current_paths = !empty($existing_row['design_path']) ? json_decode($existing_row['design_path'], true) : [];
                if(!is_array($current_paths)) $current_paths = [];

                if(is_array($files)) {
                    foreach($files as $key => $name) {
                        if (!empty($name) && $_FILES['design_files']['error'][$pid][$key] == 0) {
                            $stored = app_store_uploaded_file([
                                'name' => $_FILES['design_files']['name'][$pid][$key] ?? '',
                                'type' => $_FILES['design_files']['type'][$pid][$key] ?? '',
                                'tmp_name' => $_FILES['design_files']['tmp_name'][$pid][$key] ?? '',
                                'error' => $_FILES['design_files']['error'][$pid][$key] ?? UPLOAD_ERR_NO_FILE,
                                'size' => $_FILES['design_files']['size'][$pid][$key] ?? 0,
                            ], [
                                'dir' => 'uploads/proofs',
                                'prefix' => 'post_' . $pid . '_',
                                'max_size' => social_operation_upload_max_size(),
                                'allowed_extensions' => social_operation_allowed_extensions(),
                            ]);
                            if (!empty($stored['ok'])) {
                                $target = (string)$stored['path'];
                                $current_paths[] = $target;
                                $designUploadedCount++;
                            } else {
                                $designErrors[] = (string)($stored['error'] ?? ('فشل رفع الملف: ' . $name));
                            }
                        } elseif (!empty($name) && (int)($_FILES['design_files']['error'][$pid][$key] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                            $designErrors[] = app_upload_error_message((int)$_FILES['design_files']['error'][$pid][$key]);
                        }
                    }
                }
                
                if (!empty($current_paths)) {
                    $json_paths = json_encode(array_values($current_paths));
                    $stmtDesign = $conn->prepare("UPDATE social_posts SET design_path = ?, status = 'pending_design_review' WHERE id = ? AND job_id = ?");
                    if ($stmtDesign) {
                        $jobIdInt = (int)$job['id'];
                        $stmtDesign->bind_param('sii', $json_paths, $pid, $jobIdInt);
                        $stmtDesign->execute();
                        $stmtDesign->close();
                    }
                    $updatedPosts[(string)$pid] = social_assets_payload(array_values($current_paths));
                }
            }
        }

        if (!empty($_FILES['source_files']['name'][0])) {
            foreach ($_FILES['source_files']['name'] as $i => $name) {
                if ($_FILES['source_files']['error'][$i] == 0) {
                    $stored = app_store_uploaded_file([
                        'name' => $_FILES['source_files']['name'][$i] ?? '',
                        'type' => $_FILES['source_files']['type'][$i] ?? '',
                        'tmp_name' => $_FILES['source_files']['tmp_name'][$i] ?? '',
                        'error' => $_FILES['source_files']['error'][$i] ?? UPLOAD_ERR_NO_FILE,
                        'size' => $_FILES['source_files']['size'][$i] ?? 0,
                    ], [
                        'dir' => 'uploads/source',
                        'prefix' => 'src_',
                        'max_size' => social_operation_upload_max_size(),
                        'allowed_extensions' => social_operation_allowed_extensions(),
                    ]);
                    if (!empty($stored['ok'])) {
                        $target = (string)$stored['path'];
                        social_insert_job_file($conn, (int)$job['id'], $target, 'design', 'ملف مصدر (AI/PSD)', (string)$user_name);
                        $designUploadedCount++;
                    } else {
                        $designErrors[] = (string)($stored['error'] ?? ('فشل رفع الملف: ' . $name));
                    }
                } elseif (($_FILES['source_files']['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                    $designErrors[] = app_upload_error_message((int)$_FILES['source_files']['error'][$i]);
                }
            }
        }

        if(isset($_POST['send_designs'])) {
            app_update_job_stage($conn, (int)$job['id'], 'design_review');
        }
        social_sync_stage_rollup($conn, $job, (int)($_SESSION['user_id'] ?? 0), (string)$user_name);
        if ($designUploadedCount <= 0 && !isset($_POST['send_designs']) && !empty($designErrors)) {
            social_finish_request((int)$job['id'], [
                'ok' => false,
                'error' => trim(implode(' | ', array_filter($designErrors))),
                'redirect' => 'job_details.php?id=' . (int)$job['id'],
            ]);
        }
        social_finish_request((int)$job['id'], [
            'ok' => true,
            'message' => isset($_POST['send_designs']) ? 'تم رفع الملفات وإرسالها للمراجعة.' : 'تم حفظ ملفات التصميم بنجاح.',
            'reload' => isset($_POST['send_designs']),
            'updated_posts' => $updatedPosts,
            'redirect' => 'job_details.php?id=' . (int)$job['id'],
        ]);
    }

    // D. النشر
    if (isset($_POST['finish_publishing']) || isset($_POST['finish_publishing_no_invoice'])) {
        $publish_date_raw = trim((string)($_POST['publish_date'] ?? ''));
        $publish_channels_raw = trim((string)($_POST['publish_channels'] ?? ''));
        $publish_notes_raw = trim((string)($_POST['publish_notes'] ?? ''));
        $publish_links_raw = trim((string)($_POST['publish_links'] ?? ''));
        $publish_date = $conn->real_escape_string($publish_date_raw);
        $publish_channels = $conn->real_escape_string($publish_channels_raw);
        $publish_notes = $conn->real_escape_string($publish_notes_raw);
        $publish_links = $conn->real_escape_string($publish_links_raw);
        $publish_log_parts = [];
        if ($publish_date !== '') {
            $publish_log_parts[] = "تاريخ النشر: $publish_date";
        }
        if ($publish_channels !== '') {
            $publish_log_parts[] = "قنوات النشر: $publish_channels";
        }
        if ($publish_links !== '') {
            $publish_log_parts[] = "روابط النشر: $publish_links";
        }
        if ($publish_notes !== '') {
            $publish_log_parts[] = "ملاحظات التنفيذ: $publish_notes";
        }
        if (!empty($publish_log_parts)) {
            $publish_log = "\n[تقرير النشر]: " . implode(' | ', $publish_log_parts);
            $jobIdInt = (int)$job['id'];
            $stmtPublishLog = $conn->prepare("UPDATE job_orders SET notes = CONCAT(IFNULL(notes, ''), ?) WHERE id = ?");
            if ($stmtPublishLog) {
                $stmtPublishLog->bind_param('si', $publish_log, $jobIdInt);
                $stmtPublishLog->execute();
                $stmtPublishLog->close();
            }
        }
        app_stage_data_set($conn, (int)$job['id'], 'publishing', 'publish_date', $publish_date_raw, (int)($_SESSION['user_id'] ?? 0), (string)$user_name);
        app_stage_data_set($conn, (int)$job['id'], 'publishing', 'publish_channels', $publish_channels_raw, (int)($_SESSION['user_id'] ?? 0), (string)$user_name);
        app_stage_data_set($conn, (int)$job['id'], 'publishing', 'publish_links', $publish_links_raw, (int)($_SESSION['user_id'] ?? 0), (string)$user_name);
        app_stage_data_set($conn, (int)$job['id'], 'publishing', 'publish_notes', $publish_notes_raw, (int)($_SESSION['user_id'] ?? 0), (string)$user_name);
        social_sync_stage_rollup($conn, $job, (int)($_SESSION['user_id'] ?? 0), (string)$user_name);
        if (isset($_POST['finish_publishing'])) {
            job_create_sales_invoice_from_job($conn, $job, (string)($_SESSION['name'] ?? 'System'));
        } else {
            job_append_accounting_note($conn, (int)$job['id'], 'تم تحويل العملية للحسابات بدون إنشاء فاتورة تلقائية. سيتم الاعتماد على فاتورة موجودة أو مستوردة لاحقاً.');
        }
        app_update_job_stage($conn, (int)$job['id'], 'accounting');
        safe_redirect($job['id']);
    }

    // E. إنهاء وأرشفة
    if (isset($_POST['archive_job'])) {
        app_update_job_stage($conn, (int)$job['id'], 'completed', 'completed');
        safe_redirect($job['id']);
    }

    // F. إعادة الفتح
    if (isset($_POST['reopen_job'])) {
        app_update_job_stage($conn, (int)$job['id'], $first_stage, 'processing');
        safe_redirect($job['id']);
    }

    // أدوات مساعدة
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

$current_stage_outputs = app_stage_output_stage_lines($conn, (int)$job['id'], 'social', $curr);
$job_note_entries = array_slice(app_parse_job_notes((string)($job['notes'] ?? '')), 0, 12);
$current_publish_date = social_stage_value($conn, $job, 'publishing', 'publish_date');
$current_publish_channels = social_stage_value($conn, $job, 'publishing', 'publish_channels');
$current_publish_links = social_stage_value($conn, $job, 'publishing', 'publish_links');
$current_publish_notes = social_stage_value($conn, $job, 'publishing', 'publish_notes');
$render_social_posts = social_fetch_posts($conn, (int)$job['id']);
$render_job_files = social_fetch_job_files($conn, (int)$job['id']);

// 5. تهيئة العرض
$curr = app_workflow_current_stage($workflow, (string)($job['current_stage'] ?? ''), $first_stage);
$prev_stage_key = $workflow[$curr]['prev'] ?? null;
$next_stage_key = $workflow[$curr]['next'] ?? null;
$curr_label = $workflow[$curr]['label'] ?? '';
$prev_label = ($prev_stage_key && isset($workflow[$prev_stage_key])) ? $workflow[$prev_stage_key]['label'] : 'لا يوجد';
$next_label = ($next_stage_key && isset($workflow[$next_stage_key])) ? $workflow[$next_stage_key]['label'] : 'لا يوجد';
?>

<style>
    :root { 
        --royal-gold: #d4af37; 
        --royal-gold-dim: #aa8c2c;
        --royal-dark: #121212; 
        --royal-panel: #1e1e1e; 
        --royal-green: #27ae60; 
        --royal-red: #c0392b; 
    }
    
    .social-layout { display: flex; gap: 20px; align-items: flex-start; }
    .social-main { flex: 3; min-width: 0; }
    .social-sidebar { flex: 1; min-width: 280px; background: #151515; border: 1px solid #333; border-radius: 12px; padding: 20px; position: sticky; top: calc(var(--nav-total-height, 70px) + 20px); max-height: calc(100vh - var(--nav-total-height, 70px) - 40px); overflow-y: auto; }

    @media (max-width: 900px) { 
        .social-layout { flex-direction: column; } 
        .social-sidebar { width: 100%; order: 2; position: static; max-height: none; } 
        .social-main { width: 100%; order: 1; margin-bottom: 20px; }
    }

    .stage-container { display: flex; overflow-x: auto; gap: 8px; margin-bottom: 25px; padding-bottom: 10px; border-bottom: 1px solid #333; }
    .stage-pill { background: #2c2c2c; color: #777; padding: 6px 15px; border-radius: 20px; font-size: 0.8rem; white-space: nowrap; transition: 0.3s; }
    .stage-pill.active { background: var(--royal-gold); color: #000; font-weight: bold; transform: scale(1.05); }
    .stage-summary { display:grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap:12px; margin:0 0 20px; }
    .stage-summary-item { background:#151515; border:1px solid #333; border-radius:10px; padding:14px; }
    .stage-summary-label { display:block; color:#8f8f8f; font-size:0.8rem; margin-bottom:6px; }
    .stage-summary-value { color:#f2f2f2; font-size:1rem; font-weight:700; }
    .stage-output-panel { background:#151515; border:1px solid #333; border-radius:12px; padding:14px; margin:0 0 20px; }
    .stage-output-panel-title { color:var(--royal-gold); font-weight:700; margin-bottom:10px; }
    .stage-output-grid { display:grid; grid-template-columns:repeat(auto-fit, minmax(220px, 1fr)); gap:10px; }
    .stage-output-box { background:#0d0d0d; border:1px solid #292929; border-radius:10px; padding:12px; }
    .stage-output-box .label { color:#8f8f8f; font-size:.78rem; margin-bottom:6px; }
    .stage-output-box .value { color:#f4f4f4; font-weight:700; line-height:1.7; white-space:pre-wrap; word-break:break-word; }
    
    .royal-card { background: var(--royal-panel); padding: 25px; border-radius: 12px; border: 1px solid #333; margin-bottom: 20px; position: relative; overflow: hidden; }
    .royal-card::before { content: ''; position: absolute; top: 0; left: 0; width: 4px; height: 100%; background: var(--royal-gold); }
    
    .card-h { color: var(--royal-gold); margin: 0 0 20px 0; border-bottom: 1px dashed #444; padding-bottom: 10px; font-size: 1.3rem; display: flex; align-items: center; gap: 10px; }
    
    .gallery { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 10px; }
    .gallery-item { position: relative; width: 80px; height: 80px; border-radius: 6px; overflow: hidden; border: 1px solid #444; }
    .gallery-file-link,
    .preview-file-link,
    .publish-file-link { width: 100%; height: 100%; display:flex; align-items:center; justify-content:center; background:#181818; color:#d8c27a; text-decoration:none; text-align:center; padding:8px; box-sizing:border-box; font-size:.72rem; line-height:1.35; word-break:break-word; }
    .gallery-file-link:hover,
    .preview-file-link:hover,
    .publish-file-link:hover { background:#222; color:#f3d56d; }
    .del-btn { position: absolute; top: 0; right: 0; background: rgba(0,0,0,0.8); color: red; border: none; width: 25px; height: 25px; cursor: pointer; font-weight: bold; display: flex; align-items: center; justify-content: center; z-index: 2; }

    .post-card { background: #000; border: 1px solid #333; border-radius: 8px; padding: 20px; margin-bottom: 20px; transition: 0.3s; }
    .post-badge { display: inline-block; background: #222; padding: 3px 8px; border-radius: 4px; font-size: 0.75rem; color: #aaa; margin-bottom: 10px; }
    
    .preview-grid { display: flex; gap: 10px; overflow-x: auto; padding-bottom: 5px; flex-wrap: wrap; }
    .preview-img-box { position: relative; width: 80px; height: 80px; border: 1px solid #444; border-radius: 4px; overflow: hidden; }
    .social-thumb-img { width:100%; height:100%; display:block; object-fit:cover; background:#111; }
    .publish-file-box { height:200px; background:#111; display:flex; align-items:center; justify-content:center; overflow:hidden; border-radius:6px; margin-bottom:10px; }

    textarea, input[type="text"] { width: 100%; background: #151515; border: 1px solid #444; color: #fff; padding: 12px; border-radius: 6px; box-sizing: border-box; font-family: inherit; }
    .action-bar { display: flex; gap: 10px; margin-top: 20px; flex-wrap: wrap; }
    .btn { padding: 12px 25px; border: none; border-radius: 6px; font-weight: bold; cursor: pointer; color: #fff; flex: 1; transition: 0.2s; }
    .btn-gold { background: linear-gradient(135deg, var(--royal-gold), var(--royal-gold-dim)); color: #000; }
    .btn-gray { background: #333; color: #ccc; }
    .btn-red { background: var(--royal-red); }
    .btn-sm { padding: 8px 15px; font-size: 0.8rem; flex: none; width: auto; }

    .timeline { position: relative; padding-right: 20px; border-right: 2px solid #333; }
    .timeline-item { position: relative; margin-bottom: 20px; }
    .timeline-item::before { content: ''; position: absolute; right: -26px; top: 5px; width: 10px; height: 10px; background: #555; border-radius: 50%; border: 2px solid #151515; transition: 0.3s; }
    .timeline-item.active::before { background: var(--royal-gold); box-shadow: 0 0 10px var(--royal-gold); }
    .timeline-item.active .t-title { color: var(--royal-gold); font-weight: bold; }
    .t-title { color: #888; font-size: 0.9rem; }

    .comments-box { background: #000; padding: 10px; border-radius: 6px; max-height: 200px; overflow-y: auto; font-size: 0.85rem; border: 1px solid #333; margin-bottom: 10px; }
    .comment-input { width: 100%; background: #222; border: 1px solid #444; padding: 8px; color: #fff; border-radius: 4px; margin-bottom: 5px; }
    .admin-controls { display: flex; gap: 5px; margin-top: 10px; background: #222; padding: 5px; border-radius: 5px; }
    
    .status-alert { padding:10px; border-radius:5px; margin-bottom:10px; font-size:0.9rem; }
    .status-pending { background: rgba(255,193,7,0.1); color: #ffc107; border: 1px solid #ffc107; }
    .status-approved { background: rgba(39,174,96,0.1); color: #27ae60; border: 1px solid #27ae60; }
    .status-rejected { background: rgba(192,57,43,0.1); color: #e74c3c; border: 1px solid #e74c3c; }
    @media (max-width: 560px) {
        .social-layout {
            display: flex;
            flex-direction: column;
            gap: 12px;
            padding-inline: 0;
            width: 100%;
            overflow-x: clip;
        }
        .social-main,
        .social-sidebar {
            width: 100%;
            max-width: 100%;
            min-width: 0;
            margin-inline: 0;
        }
        .social-main { order: 1; }
        .social-sidebar { order: 2; position: static; max-height: none; }
        .royal-card,
        .social-sidebar,
        .stage-output-panel { padding: 12px; border-radius: 10px; }
        .social-sidebar {
            padding: 0;
            overflow: hidden;
        }
        .social-sidebar.mobile-collapsed > *:not(.sidebar-mobile-head) { display: none !important; }
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
        .stage-container {
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
        .stage-pill {
            min-width: 0;
            text-align: center;
            padding: 8px 10px;
            line-height: 1.35;
            border-radius: 14px;
            white-space: normal;
        }
        .stage-summary {
            display: none;
        }
        .stage-summary-item {
            min-width: 0;
            padding: 10px 12px;
        }
        .stage-summary-label { font-size: 0.68rem; }
        .stage-summary-value { font-size: 0.88rem; }
        .stage-output-grid { grid-template-columns: 1fr; }
        .gallery-item,
        .preview-img-box { width: 56px; height: 56px; }
        .action-bar { flex-direction: column; }
        .btn,
        .btn-sm { width: 100%; }
        .card-h { font-size: 1.05rem; margin-bottom: 14px; }
        .post-card { padding: 12px; font-size: 0.88rem; }
        .post-card textarea { min-height: 120px; }
        .post-card > div[style*="justify-content:space-between"] {
            flex-direction: column !important;
            align-items: stretch !important;
        }
        .post-card div[style*="min-width:250px"] {
            min-width: 0 !important;
            width: 100% !important;
        }
        .timeline-item,
        .stage-pill { font-size: 0.72rem; }
        .stage-pill.active { grid-column: 1 / -1; }
        .social-ref-upload-form,
        .royal-card div[style*="align-items:center"][style*="flex-wrap:wrap"] {
            flex-direction: column !important;
            align-items: stretch !important;
        }
        .social-ref-file-input,
        .social-ref-upload-btn {
            width: 100% !important;
            min-width: 0 !important;
        }
        .workflow-sidebar-block { display: none; }
        .social-sidebar div[style*="display:flex"],
        .royal-card div[style*="display:flex"] { gap: 8px !important; }
        .royal-card input[type="file"],
        .royal-card textarea,
        .royal-card select,
        .royal-card input[type="text"],
        .royal-card button { width: 100%; max-width: 100%; }
        .royal-card input[type="text"],
        .royal-card textarea,
        .royal-card select { padding: 10px; font-size: 0.9rem; }
        .gallery-item form,
        .preview-img-box form { width: 100%; }
    }
</style>
<script>
document.addEventListener('DOMContentLoaded', function () {
    if (!window.matchMedia('(max-width: 560px)').matches) return;
    document.querySelectorAll('.social-sidebar').forEach(function (sidebar) {
        if (sidebar.querySelector('.sidebar-mobile-head')) return;
        const titleEl = sidebar.querySelector('h3');
        const title = titleEl ? titleEl.textContent.trim() : 'ملف الحملة';
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

<div class="container">
    
    <div class="social-layout">
        <div class="social-sidebar">
            <h3 style="color:#fff; border-bottom:2px solid var(--royal-gold); padding-bottom:10px; margin-top:0;">ملف الحملة</h3>
            
            <div style="margin-bottom:20px;">
                <h4 style="color:var(--royal-gold); margin-bottom:10px; font-size:0.9rem;">البيانات الأساسية:</h4>
                <div style="background:#0a0a0a; padding:10px; border-radius:6px; font-size:0.9rem; color:#ccc; line-height:1.6;">
                    <strong>المنصات:</strong> <?php echo $specs['platforms']; ?><br>
                    <strong>البوستات:</strong> <?php echo $posts_count; ?><br>
                    <strong>الهدف:</strong> <?php echo $specs['goal']; ?>
                </div>
            </div>

            <div style="margin-bottom:20px;">
                <h4 style="color:var(--royal-gold); margin-bottom:10px; font-size:0.9rem;">التعليقات الداخلية:</h4>
                <div class="comments-box">
                    <?php if(empty($job_note_entries)): ?>
                        <div style="color:#666;">لا توجد ملاحظات</div>
                    <?php else: ?>
                        <?php foreach($job_note_entries as $note_entry): ?>
                            <div style="padding:8px 0; border-bottom:1px solid #1f1f1f;">
                                <div style="color:var(--royal-gold); font-size:0.78rem; font-weight:700; margin-bottom:4px;"><?php echo app_h((string)$note_entry['label']); ?></div>
                                <div style="color:#ddd; line-height:1.7; white-space:pre-wrap;"><?php echo app_h((string)$note_entry['value']); ?></div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                <form method="POST">
                    <?php echo app_csrf_input(); ?>
                    <input type="text" name="comment_text" class="comment-input" placeholder="اكتب ملاحظة..." required>
                    <button type="submit" name="add_internal_comment" class="btn btn-gray btn-sm" style="width:100%;">إرسال</button>
                </form>
            </div>

            <div class="workflow-sidebar-block" style="margin-bottom:20px;">
                <h4 style="color:var(--royal-gold); margin-bottom:10px; font-size:0.9rem;">مسار العمل:</h4>
                <div class="timeline">
                    <?php foreach($workflow as $k => $stageData): $active = ($k == $curr) ? 'active' : ''; ?>
                    <div class="timeline-item <?php echo $active; ?>"><span class="t-title"><?php echo $stageData['label']; ?></span></div>
                    <?php endforeach; ?>
                </div>
            </div>

            <?php if($can_force_stage): ?>
                <div style="border-top:1px dashed #333; padding-top:15px;">
                    <span style="color:#aaa; font-size:0.8rem; display:block; margin-bottom:5px;">تحكم إداري:</span>
                    <div class="admin-controls">
                        <?php if($prev_stage_key): ?>
                        <form method="POST" style="flex:1;"><?php echo app_csrf_input(); ?><input type="hidden" name="target_stage" value="<?php echo $prev_stage_key; ?>"><button name="force_stage_change" class="btn btn-red btn-sm" style="width:100%;">« تراجع</button></form>
                        <?php endif; ?>
                        <?php if($next_stage_key): ?>
                        <form method="POST" style="flex:1;"><?php echo app_csrf_input(); ?><input type="hidden" name="target_stage" value="<?php echo $next_stage_key; ?>"><button name="force_stage_change" class="btn btn-gold btn-sm" style="width:100%;">تمرير »</button></form>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <div class="social-main">
            <div class="stage-container">
                <?php foreach($workflow as $key => $stageData): ?>
                    <div class="stage-pill <?php echo ($key == $curr) ? 'active' : ''; ?>"><?php echo $stageData['label']; ?></div>
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

            <?php if(!in_array($curr, ['accounting', 'completed'], true)): ?>
            <div class="royal-card" style="padding:16px; margin-bottom:18px;">
                <form method="POST" class="social-async-form" data-upload-progress="0" style="display:grid; gap:12px;">
                    <?php echo app_csrf_input(); ?>
                    <input type="hidden" name="__async_form" value="1">
                    <div style="display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap;">
                        <h3 class="card-h" style="margin:0;">إضافة بوست</h3>
                        <span class="post-badge" style="margin:0;">التالي #<?php echo ((int)count($render_social_posts) + 1); ?></span>
                    </div>
                    <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(240px, 1fr)); gap:12px;">
                        <textarea name="new_post_idea" rows="2" placeholder="الفكرة أو التوجيه المختصر"></textarea>
                        <textarea name="new_post_content" rows="2" placeholder="محتوى مبدئي اختياري"></textarea>
                    </div>
                    <div class="action-bar" style="margin:0;">
                        <button type="submit" name="add_social_post" value="1" class="btn btn-gold">إضافة وترحيل</button>
                    </div>
                    <div class="social-form-status" style="display:none; margin-top:4px; font-size:0.9rem;"></div>
                </form>
            </div>
            <?php endif; ?>

            <?php if(!empty($current_stage_outputs)): ?>
            <div class="stage-output-panel">
                <div class="stage-output-panel-title">آخر مخرجات المرحلة</div>
                <div class="stage-output-grid">
                    <?php foreach($current_stage_outputs as $stage_output): ?>
                    <div class="stage-output-box">
                        <div class="label"><?php echo app_h((string)$stage_output['label']); ?></div>
                        <div class="value"><?php echo app_h((string)$stage_output['value']); ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <div class="royal-card">
                <h3 class="card-h">الحملة: <?php echo $job['job_name']; ?></h3>
                <h4 style="color:#aaa; font-size:0.9rem; margin-top:0;">الملفات المرجعية والمرفقات:</h4>
                <?php 
                if(!empty($render_job_files)): ?>
                    <div class="gallery">
                    <?php foreach($render_job_files as $f): 
                        $desc = $f['description'] ?: $f['file_type']; 
                        $asset = social_asset_payload((string)$f['file_path']);
                    ?>
                        <div class="gallery-item" title="<?php echo $desc; ?>">
                            <a href="<?php echo app_h((string)$asset['path']); ?>" target="_blank" class="gallery-file-link">
                                <?php if (!empty($asset['thumb'])): ?>
                                    <img src="<?php echo app_h((string)$asset['thumb']); ?>" alt="<?php echo app_h((string)$asset['label']); ?>" class="social-thumb-img" loading="lazy" decoding="async">
                                <?php else: ?>
                                    <?php echo app_h((string)$asset['label']); ?>
                                <?php endif; ?>
                            </a>
                            <form method="POST" onsubmit="return confirm('حذف الملف نهائياً من السيرفر؟');"><?php echo app_csrf_input(); ?><input type="hidden" name="__async_form" value="1"><input type="hidden" name="file_id" value="<?php echo $f['id']; ?>"><button name="delete_file" class="del-btn">×</button></form>
                        </div>
                    <?php endforeach; ?>
                    </div>
                <?php else: echo "<span style='color:#555; font-size:0.8rem;'> لا يوجد ملفات مرفقة.</span>"; endif; ?>
            </div>

            <?php if($curr == 'briefing'): ?>
            <div class="royal-card">
                <h3 class="card-h">صياغة الأفكار</h3>
                <p style="color:#aaa; margin-bottom:20px;">قم بصياغة فكرة منفصلة لكل منشور:</p>
                <div class="app-ai-panel" data-job-id="<?php echo (int)$job['id']; ?>" data-csrf="<?php echo app_h(app_csrf_token()); ?>" data-context="social_ideas" data-item-count="<?php echo (int)$posts_count; ?>" data-target-selector="textarea[name^='ideas[']" data-apply-mode="fill-sequential">
                    <div class="app-ai-head">
                        <div class="app-ai-title">مساعد AI للأفكار</div>
                        <div class="app-ai-note">مجاني داخل النظام للمستخدمين المسجلين</div>
                    </div>
                    <textarea class="app-ai-seed" placeholder="أدخل هدف الحملة أو المنتج أو أي توجيه إضافي لتحسين الأفكار..."><?php echo app_h(trim((string)($specs['goal'] ?? ''))); ?></textarea>
                    <div class="app-ai-actions">
                        <button type="button" class="app-ai-btn app-ai-btn-primary app-ai-generate">توليد أفكار</button>
                    </div>
                    <div class="app-ai-status"></div>
                    <div class="app-ai-results"></div>
                </div>
                <div style="margin:0 0 15px; background:#111; padding:12px; border-radius:6px; border:1px solid #2f2f2f;">
                    <label style="color:#aaa; display:block; margin-bottom:8px;">رفع ملفات مرجعية:</label>
                    <form method="POST" enctype="multipart/form-data" class="social-ref-upload-form" style="display:flex; gap:10px; align-items:center; flex-wrap:wrap; margin:0 0 8px;">
                        <?php echo app_csrf_input(); ?>
                        <input type="hidden" name="__async_form" value="1">
                        <input type="hidden" name="upload_ref_files_only" value="1">
                        <input type="file" name="ref_files[]" multiple class="social-ref-file-input" style="color:#fff; flex:1 1 320px; min-width:220px;">
                        <button type="submit" class="btn btn-gray social-ref-upload-btn" style="width:auto; min-width:120px;">رفع الملفات</button>
                    </form>
                    <div class="social-ref-upload-status" style="display:none; margin-top:6px;">
                        <div style="display:flex; justify-content:space-between; gap:10px; color:#aaa; font-size:0.82rem; margin-bottom:6px;">
                            <span class="social-ref-upload-text">جاري الرفع...</span>
                            <span class="social-ref-upload-percent">0%</span>
                        </div>
                        <div style="height:8px; border-radius:999px; background:#1b1b1b; overflow:hidden; border:1px solid #2f2f2f;">
                            <div class="social-ref-upload-bar" style="height:100%; width:0%; background:linear-gradient(90deg, #d4af37, #f2d16b); transition:width .2s ease;"></div>
                        </div>
                    </div>
                    <div class="social-ref-upload-result" style="margin-top:8px; color:#8f8f8f; font-size:0.82rem;"></div>
                </div>
                <form method="POST" enctype="multipart/form-data" class="social-async-form" data-upload-progress="0">
                    <?php echo app_csrf_input(); ?>
                    <input type="hidden" name="__async_form" value="1">
                    <?php 
                    $stage_posts = $render_social_posts;
                    $loop_count = !empty($stage_posts) ? count($stage_posts) : $posts_count;
                    for($i=0; $i<$loop_count; $i++): 
                        $p = $stage_posts[$i] ?? null;
                        $idx = $p ? $p['post_index'] : ($i+1);
                        $val = $p ? $p['idea_text'] : '';
                        $status = $p['idea_status'] ?? 'pending';
                        $feedback = $p['idea_feedback'] ?? '';
                    ?>
                        <div class="post-card" style="<?php if($status=='idea_rejected') echo 'border:1px solid var(--royal-red);'; ?>">
                            <div style="display:flex; justify-content:space-between; gap:12px; align-items:center; margin-bottom:10px;">
                                <span class="post-badge" style="margin-bottom:0;">فكرة رقم #<?php echo $idx; ?></span>
                                <button type="submit" name="delete_social_post" value="1" class="btn btn-red btn-sm" style="width:auto;" onclick="this.form.post_id.value='<?php echo (int)($p['id'] ?? 0); ?>'; return confirm('حذف البوست بالكامل بكل بياناته وتصميماته؟');"<?php echo empty($p['id']) ? ' disabled' : ''; ?>>حذف البوست</button>
                            </div>
                            <?php if($status == 'idea_rejected'): ?>
                                <div class="status-alert status-rejected">❌ مرفوضة: <?php echo $feedback; ?></div>
                            <?php elseif($status == 'idea_approved'): ?>
                                <div class="status-alert status-approved">معتمدة</div>
                            <?php endif; ?>
                            <textarea name="ideas[<?php echo (int)$idx; ?>]" rows="3" placeholder="اكتب الفكرة المقترحة لهذا البوست..."><?php echo $val; ?></textarea>
                        </div>
                    <?php endfor; ?>

                    <div class="action-bar">
                        <button type="submit" name="save_idea_batch" class="btn btn-gray">حفظ مسودة</button>
                        <button type="submit" name="send_idea_batch" class="btn btn-gold">حفظ وإرسال للمراجعة</button>
                    </div>
                    <input type="hidden" name="post_id" value="">
                    <div class="social-form-status" style="display:none; margin-top:10px; font-size:0.9rem;"></div>
                </form>
            </div>
            <?php endif; ?>

            <?php if($curr == 'idea_review'): ?>
            <div class="royal-card" style="text-align:center; padding:40px;">
                <h3 style="color:var(--royal-gold);">بانتظار موافقة العميل على الأفكار</h3>
                <p style="color:#aaa;">تم إرسال الأفكار للعميل للمراجعة الفردية.</p>
                <div class="action-bar">
                    <a href="https://wa.me/<?php echo $job['client_phone']; ?>?text=<?php echo urlencode("يرجى مراجعة الأفكار المقترحة:\n$client_link"); ?>" target="_blank" class="btn" style="background:#25D366; text-decoration:none;">تذكير عبر واتساب</a>
                    <form method="POST" style="display:inline;"><?php echo app_csrf_input(); ?><input type="hidden" name="prev_target" value="briefing"><textarea name="return_reason" style="display:none;">تراجع يدوي</textarea><button name="return_stage" class="btn btn-gray">تراجع للتعديل</button></form>
                </div>
            </div>
            <?php endif; ?>

            <?php if($curr == 'content_writing'): ?>
            <div class="royal-card">
                <h3 class="card-h">كتابة المحتوى (<?php echo $posts_count; ?> منشور)</h3>
                <form method="POST" enctype="multipart/form-data" class="social-async-form" data-upload-progress="0">
                    <?php echo app_csrf_input(); ?>
                    <input type="hidden" name="__async_form" value="1">
                    <?php 
                    foreach($render_social_posts as $p): 
                        $pid = $p['id']; 
                        $val = $p['content_text'];
                        $idea = $p['idea_text'];
                        $status = $p['status'];
                        $feedback = $p['client_feedback'];
                    ?>
                        <div class="post-card" style="<?php if($status=='content_rejected') echo 'border:1px solid var(--royal-red);'; ?>">
                            <div style="display:flex; justify-content:space-between; gap:12px; align-items:center; margin-bottom:10px;">
                                <span class="post-badge" style="margin-bottom:0;">بوست #<?php echo $p['post_index']; ?></span>
                                <button type="submit" name="delete_social_post" value="1" class="btn btn-red btn-sm" style="width:auto;" onclick="this.form.post_id.value='<?php echo (int)$pid; ?>'; return confirm('حذف البوست بالكامل بكل بياناته وتصميماته؟');">حذف البوست</button>
                            </div>
                            
                            <div style="background:#1a1a1a; border-right:3px solid var(--royal-gold); padding:10px; margin-bottom:10px; color:#ddd; font-size:0.9rem;">
                                <strong style="color:var(--royal-gold);">الفكرة المعتمدة:</strong><br>
                                <?php echo nl2br($idea); ?>
                            </div>

                            <?php if($status == 'content_rejected'): ?>
                                <div class="status-alert status-rejected">❌ ملاحظات العميل: <?php echo $feedback; ?></div>
                            <?php endif; ?>

                            <textarea name="content[<?php echo $pid; ?>]" rows="4" placeholder="اكتب الكابشن (Caption) ووصف التصميم هنا..."><?php echo $val; ?></textarea>
                        </div>
                    <?php endforeach; ?>
                    <div style="margin:15px 0; background:#111; padding:10px; border-radius:6px;">
                        <label style="color:#aaa;">ملفات مساعدة:</label>
                        <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap; margin-top:5px;">
                            <input type="file" name="content_docs[]" multiple style="color:#fff; flex:1 1 320px; width:100%;">
                            <button type="submit" name="save_content" class="btn btn-gray" style="width:auto; white-space:nowrap;">رفع الملفات</button>
                        </div>
                    </div>
                    <div class="action-bar">
                        <button type="submit" name="save_content" class="btn btn-gray">حفظ مسودة</button>
                        <button type="submit" name="send_content" class="btn btn-gold">حفظ وإرسال للمراجعة</button>
                    </div>
                    <input type="hidden" name="post_id" value="">
                    <div class="social-form-status" style="display:none; margin-top:10px; font-size:0.9rem;"></div>
                </form>
            </div>
            <?php endif; ?>

            <?php if($curr == 'content_review'): ?>
            <div class="royal-card">
                <h3 class="card-h">مراجعة المحتوى المرسل</h3>
                <div style="background:#111; padding:15px; border-radius:8px; margin-bottom:20px; border:1px dashed #444;">
                    <p style="margin-top:0; color:#aaa; font-size:0.9rem;">رابط المراجعة:</p>
                    <input type="text" value="<?php echo $client_link; ?>" readonly style="width:100%; background:#000; color:var(--royal-gold); padding:10px; border:1px solid #333; direction:ltr;">
                </div>
                <div class="action-bar">
                    <a href="https://wa.me/<?php echo $job['client_phone']; ?>?text=<?php echo urlencode("تم تجهيز المحتوى، يرجى المراجعة:\n$client_link"); ?>" target="_blank" class="btn" style="background:#25D366; text-decoration:none;">تذكير واتساب</a>
                    <form method="POST" style="flex:1;"><?php echo app_csrf_input(); ?><input type="hidden" name="prev_target" value="content_writing"><textarea name="return_reason" style="display:none;">تراجع يدوي</textarea><button name="return_stage" class="btn btn-gray">تراجع</button></form>
                </div>
            </div>
            <?php endif; ?>

            <?php if($curr == 'designing'): ?>
            <div class="royal-card">
                <h3 class="card-h">مرحلة التصميم</h3>
                <div class="app-ai-panel" data-job-id="<?php echo (int)$job['id']; ?>" data-csrf="<?php echo app_h(app_csrf_token()); ?>" data-context="social_designs" data-item-count="<?php echo (int)$posts_count; ?>">
                    <div class="app-ai-head">
                        <div class="app-ai-title">مساعد AI للتصميمات</div>
                        <div class="app-ai-note">اقتراحات بصرية سريعة لكل بوست</div>
                    </div>
                    <textarea class="app-ai-seed" placeholder="أدخل اتجاهًا بصريًا أو وصفًا مختصرًا للحملة لتحسين المقترحات..."></textarea>
                    <div class="app-ai-actions">
                        <button type="button" class="app-ai-btn app-ai-btn-primary app-ai-generate">توليد مقترحات تصميم</button>
                    </div>
                    <div class="app-ai-status"></div>
                    <div class="app-ai-results"></div>
                </div>
                <form method="POST" enctype="multipart/form-data" class="social-async-form" data-upload-progress="1">
                    <?php echo app_csrf_input(); ?>
                    <input type="hidden" name="__async_form" value="1">
                    <?php 
                    foreach($render_social_posts as $p): 
                        $images = [];
                        if(!empty($p['design_path'])) {
                            $decoded = json_decode($p['design_path'], true);
                            $images = is_array($decoded) ? $decoded : [$p['design_path']];
                        }
                    ?>
                        <div class="post-card" style="<?php if($p['status']=='design_rejected') echo 'border:1px solid var(--royal-red);'; ?>">
                            <div style="display:flex; justify-content:space-between; gap:12px; align-items:center; margin-bottom:10px;">
                                <span class="post-badge" style="margin-bottom:0;">تصميم بوست #<?php echo $p['post_index']; ?></span>
                                <button type="submit" name="delete_social_post" value="1" class="btn btn-red btn-sm" style="width:auto;" onclick="this.form.post_id.value='<?php echo (int)$p['id']; ?>'; return confirm('حذف البوست بالكامل بكل بياناته وتصميماته؟');">حذف البوست</button>
                            </div>
                            
                            <?php if($p['status'] == 'design_rejected'): ?>
                                <div class="status-alert status-rejected">❌ تعديل مطلوب: <?php echo $p['client_feedback']; ?></div>
                            <?php endif; ?>

                            <div style="display:flex; gap:15px; align-items:flex-start; flex-wrap:wrap;">
                                <div style="flex:1; min-width:250px;">
                                    <label style="color:#aaa; font-size:0.8rem;">رفع التصميم:</label>
                                    <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap; margin-top:5px;">
                                        <input type="file" name="design_files[<?php echo $p['id']; ?>][]" multiple style="color:#fff; flex:1 1 320px; width:100%; border:1px dashed #555; padding:10px;" accept=".jpg,.jpeg,.png,.webp,.gif,.pdf,.zip,.rar,.7z,.psd,.ai,.eps,.svg,.mp4,.mov,.avi,.mkv">
                                        <button type="submit" name="upload_designs" class="btn btn-gray" style="width:auto; white-space:nowrap;">رفع الملفات</button>
                                    </div>
                                </div>
                                
                                    <div class="preview-grid" data-post-id="<?php echo (int)$p['id']; ?>">
                                        <?php foreach(social_assets_payload($images) as $asset): ?>
                                            <div class="preview-img-box">
                                                <a href="<?php echo app_h((string)$asset['path']); ?>" target="_blank" class="preview-file-link">
                                                    <?php if (!empty($asset['thumb'])): ?>
                                                        <img src="<?php echo app_h((string)$asset['thumb']); ?>" alt="<?php echo app_h((string)$asset['label']); ?>" class="social-thumb-img" loading="lazy" decoding="async">
                                                    <?php else: ?>
                                                        <?php echo app_h((string)$asset['label']); ?>
                                                    <?php endif; ?>
                                                </a>
                                                <input type="hidden" name="__async_form" value="1"><button type="submit" name="delete_design_img" value="1" 
                                                        formaction="" 
                                                        onclick="this.form.post_id.value='<?php echo $p['id']; ?>'; this.form.img_path.value='<?php echo app_h((string)$asset['path']); ?>'; return confirm('حذف الصورة؟');"
                                                        style="position:absolute; top:0; right:0; background:rgba(200,0,0,0.8); color:#fff; border:none; cursor:pointer;">×</button>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                            </div>

                            <div style="background:#151515; padding:12px; border-radius:6px; margin-top:15px; color:#ddd; font-size:0.9rem; border-top:2px solid var(--royal-gold);">
                                <strong>المحتوى النصي المعتمد:</strong><br>
                                <?php echo nl2br($p['content_text']); ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    
                    <input type="hidden" name="post_id" value="">
                    <input type="hidden" name="img_path" value="">

                    <div style="margin:20px 0; border-top:1px solid #333; padding-top:15px;">
                        <label style="color:#aaa;">رفع ملفات المصدر - اختياري:</label>
                        <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap; margin-top:5px;">
                            <input type="file" name="source_files[]" multiple style="color:#fff; flex:1 1 320px; width:100%;" accept=".jpg,.jpeg,.png,.webp,.gif,.pdf,.zip,.rar,.7z,.psd,.ai,.eps,.svg,.mp4,.mov,.avi,.mkv,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt">
                            <button type="submit" name="upload_designs" class="btn btn-gray" style="width:auto; white-space:nowrap;">رفع الملفات</button>
                        </div>
                    </div>
                    <div class="action-bar">
                        <button type="submit" name="upload_designs" class="btn btn-gray">حفظ الملفات فقط</button>
                        <button type="submit" name="send_designs" class="btn btn-gold">رفع وإرسال للمراجعة</button>
                    </div>
                    <div class="social-form-status" style="display:none; margin-top:10px; font-size:0.9rem;"></div>
                    <div class="social-form-progress" style="display:none; margin-top:10px;">
                        <div style="height:8px; background:#222; border-radius:999px; overflow:hidden;">
                            <div class="social-form-progress-bar" style="width:0%; height:8px; background:linear-gradient(90deg,#c79c2f,#f4d269);"></div>
                        </div>
                        <div class="social-form-progress-text" style="margin-top:6px; color:#aaa; font-size:0.85rem;">جاري الرفع...</div>
                    </div>
                </form>
            </div>
            <?php endif; ?>

            <?php if($curr == 'design_review'): ?>
            <div class="royal-card">
                <h3 class="card-h">مراجعة التصاميم المرسلة</h3>
                <div style="background:#111; padding:15px; border-radius:8px; margin-bottom:20px; border:1px dashed #444;">
                    <p style="margin-top:0; color:#aaa; font-size:0.9rem;">رابط المراجعة:</p>
                    <input type="text" value="<?php echo $client_link; ?>" readonly style="width:100%; background:#000; color:var(--royal-gold); padding:10px; border:1px solid #333; direction:ltr;">
                </div>

                <div class="action-bar">
                    <a href="https://wa.me/<?php echo $job['client_phone']; ?>?text=<?php echo urlencode("تم رفع التصاميم، يرجى الاعتماد:\n$client_link"); ?>" target="_blank" class="btn" style="background:#25D366; text-decoration:none;">تذكير واتساب</a>
                    <form method="POST" style="flex:1;"><?php echo app_csrf_input(); ?><input type="hidden" name="prev_target" value="designing"><textarea name="return_reason" style="display:none;">تراجع يدوي</textarea><button name="return_stage" class="btn btn-gray">تراجع</button></form>
                </div>
            </div>
            <?php endif; ?>

            <?php if($curr == 'publishing'): ?>
            <div class="royal-card">
                <h3 class="card-h">النشر</h3>
                <p style="color:#aaa; margin-bottom:20px;">وثق تنفيذ النشر قبل إغلاق العملية وتحويلها للحسابات.</p>
                
                <div style="display:grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap:20px; margin-bottom:30px;">
                <?php 
                foreach($render_social_posts as $p): 
                    $images = !empty($p['design_path']) ? json_decode($p['design_path'], true) : [];
                    $cover = $images[0] ?? '';
                    $coverAsset = $cover ? social_asset_payload((string)$cover) : [];
                ?>
                    <div class="post-card" style="margin:0; height:100%;">
                        <span class="post-badge">#<?php echo $p['post_index']; ?></span>
                        <?php if($cover): ?>
                            <div class="publish-file-box">
                                <a href="<?php echo app_h((string)$coverAsset['path']); ?>" target="_blank" class="publish-file-link">
                                    <?php if (!empty($coverAsset['thumb'])): ?>
                                        <img src="<?php echo app_h((string)$coverAsset['thumb']); ?>" alt="<?php echo app_h((string)$coverAsset['label']); ?>" class="social-thumb-img" loading="lazy" decoding="async">
                                    <?php else: ?>
                                        <?php echo app_h((string)($coverAsset['label'] ?? 'فتح ملف التصميم')); ?>
                                    <?php endif; ?>
                                </a>
                            </div>
                        <?php else: ?><div style="height:200px; background:#111; color:#555; display:flex; align-items:center; justify-content:center;">بلا تصميم</div><?php endif; ?>
                        <div style="font-size:0.85rem; color:#ccc; max-height:100px; overflow-y:auto;"><?php echo nl2br($p['content_text']); ?></div>
                    </div>
                <?php endforeach; ?>
                </div>

                <form method="POST">
                    <?php echo app_csrf_input(); ?>
                    <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(220px, 1fr)); gap:12px; margin-bottom:15px;">
                        <div>
                            <label style="color:#aaa; display:block; margin-bottom:6px;">تاريخ النشر</label>
                            <input type="text" name="publish_date" value="<?php echo app_h($current_publish_date); ?>" placeholder="2026-03-23">
                        </div>
                        <div>
                            <label style="color:#aaa; display:block; margin-bottom:6px;">قنوات النشر</label>
                            <input type="text" name="publish_channels" value="<?php echo app_h($current_publish_channels); ?>" placeholder="Facebook / Instagram / TikTok">
                        </div>
                    </div>
                    <div style="margin-bottom:15px;">
                        <label style="color:#aaa; display:block; margin-bottom:6px;">روابط النشر</label>
                        <textarea name="publish_links" rows="2" placeholder="ضع روابط المنشورات أو الحسابات المنفذ عليها"><?php echo app_h($current_publish_links); ?></textarea>
                    </div>
                    <div style="margin-bottom:15px;">
                        <label style="color:#aaa; display:block; margin-bottom:6px;">ملاحظات التنفيذ</label>
                        <textarea name="publish_notes" rows="3" placeholder="أي ملاحظات تخص النشر أو الجدولة أو التسليم"><?php echo app_h($current_publish_notes); ?></textarea>
                    </div>
                    <div style="display:grid; gap:10px; width:100%;">
                        <button name="finish_publishing" class="btn btn-gold">اعتماد النشر وإنشاء فاتورة</button>
                        <button name="finish_publishing_no_invoice" class="btn btn-gray" type="submit" onclick="return confirm('سيتم تحويل العملية للحسابات بدون إنشاء فاتورة تلقائية. متابعة؟');">اعتماد النشر بدون فاتورة</button>
                        <div style="color:#aaa; font-size:0.84rem;">هذا الخيار مناسب إذا كانت الفاتورة صادرة على ETA أو ستُستورد للنظام لاحقاً.</div>
                    </div>
                </form>
            </div>
            <?php endif; ?>

            <?php if($curr == 'accounting'): ?>
            <div class="royal-card" style="text-align:center; padding:40px;">
                <h2 style="color:var(--royal-green); font-size:2rem;">قسم الحسابات</h2>
                <div class="action-bar" style="justify-content:center;">
                    <a href="invoices.php?tab=sales" class="btn btn-gray" style="display:inline-block; width:auto; text-decoration:none;">الملف المالي</a>
                    <form method="POST" style="display:inline-block;">
                        <?php echo app_csrf_input(); ?>
                        <button name="archive_job" class="btn btn-gold" style="width:auto;">إنهاء وأرشفة نهائية</button>
                    </form>
                </div>
            </div>
            <?php endif; ?>

            <?php if($curr == 'completed'): ?>
            <div class="royal-card" style="text-align:center; padding:40px;">
                <h2 style="color:var(--royal-green); font-size:2rem;">العملية مكتملة ومؤرشفة</h2>
                <div style="margin-top:30px; border-top:1px solid #333; padding-top:20px;">
                    <form method="POST" onsubmit="return confirm('هل أنت متأكد من إعادة فتح العملية؟');">
                        <?php echo app_csrf_input(); ?>
                        <button name="reopen_job" class="btn btn-red" style="width:auto; padding:10px 30px;">إعادة فتح العملية</button>
                    </form>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php
if (!$app_module_embedded) {
    include 'footer.php';
    if (function_exists('ob_get_level') && ob_get_level() > 0) {
        ob_end_flush();
    }
}
?>

<script>
    const SOCIAL_IMAGE_UPLOAD_LIMIT = 80 * 1024 * 1024;
    const SOCIAL_FILE_UPLOAD_LIMIT = 1024 * 1024 * 1024;
    const SOCIAL_BATCH_UPLOAD_LIMIT = 1024 * 1024 * 1024;

    function socialUploadSizeLabel(bytes) {
        const value = Number(bytes) || 0;
        if (value >= 1024 * 1024 * 1024) return (value / (1024 * 1024 * 1024)).toFixed(2) + ' GB';
        return (value / (1024 * 1024)).toFixed(1) + ' MB';
    }

    function socialIsImageFile(file) {
        const type = String(file.type || '').toLowerCase();
        const name = String(file.name || '').toLowerCase();
        return type.indexOf('image/') === 0 || /\.(jpg|jpeg|png|webp|gif|heic|heif)$/i.test(name);
    }

    function socialValidateUploadBatch(form) {
        let total = 0;
        const files = [];
        form.querySelectorAll('input[type="file"]').forEach(function(input) {
            Array.prototype.forEach.call(input.files || [], function(file) {
                files.push(file);
                total += Number(file.size || 0);
            });
        });

        for (const file of files) {
            const limit = socialIsImageFile(file) ? SOCIAL_IMAGE_UPLOAD_LIMIT : SOCIAL_FILE_UPLOAD_LIMIT;
            if (Number(file.size || 0) > limit) {
                return {
                    ok: false,
                    message: 'الملف "' + file.name + '" حجمه ' + socialUploadSizeLabel(file.size) + ' ويتجاوز حد الرفع الآمن لهذه الصفحة. ارفعه بحجم أقل أو على دفعات.'
                };
            }
        }

        if (total > SOCIAL_BATCH_UPLOAD_LIMIT) {
            return {
                ok: false,
                message: 'إجمالي الملفات في هذه الدفعة ' + socialUploadSizeLabel(total) + '. ارفع الملفات على دفعات أصغر حتى لا يستهلك المتصفح الذاكرة.'
            };
        }

        return { ok: true };
    }

    function socialClearFileInputs(form) {
        form.querySelectorAll('input[type="file"]').forEach(function(input) {
            input.value = '';
        });
    }

    function socialEscape(value) {
        return String(value || '').replace(/[&<>"']/g, function(ch) {
            return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' })[ch];
        });
    }

    function socialRenderDesignAsset(postId, asset) {
        const path = String(asset && asset.path ? asset.path : '');
        const label = String(asset && asset.label ? asset.label : 'فتح الملف');
        const thumb = String(asset && asset.thumb ? asset.thumb : '');
        const linkBody = thumb
            ? '<img src="' + socialEscape(thumb) + '" alt="' + socialEscape(label) + '" class="social-thumb-img" loading="lazy" decoding="async">'
            : socialEscape(label);
        return '' +
            '<div class="preview-img-box">' +
                '<a href="' + socialEscape(path) + '" target="_blank" class="preview-file-link">' + linkBody + '</a>' +
                '<button type="submit" name="delete_design_img" value="1" formaction="" class="social-delete-design-img" ' +
                    'data-post-id="' + socialEscape(postId) + '" data-img-path="' + socialEscape(path) + '" ' +
                    'style="position:absolute; top:0; right:0; background:rgba(200,0,0,0.8); color:#fff; border:none; cursor:pointer;">×</button>' +
            '</div>';
    }

    function socialApplyUpdatedPostAssets(updatedPosts) {
        if (!updatedPosts || typeof updatedPosts !== 'object') return;
        Object.keys(updatedPosts).forEach(function(postId) {
            let grid = null;
            const grids = document.querySelectorAll('.preview-grid[data-post-id]');
            for (let i = 0; i < grids.length; i++) {
                if (String(grids[i].getAttribute('data-post-id') || '') === String(postId)) {
                    grid = grids[i];
                    break;
                }
            }
            if (!grid) return;
            const assets = Array.isArray(updatedPosts[postId]) ? updatedPosts[postId] : [];
            grid.innerHTML = assets.map(function(asset) {
                return socialRenderDesignAsset(postId, asset);
            }).join('');
        });
    }

    (function() {
        const form = document.querySelector('.social-ref-upload-form');
        if (!form) return;
        const fileInput = form.querySelector('.social-ref-file-input');
        const button = form.querySelector('.social-ref-upload-btn');
        const statusBox = document.querySelector('.social-ref-upload-status');
        const statusText = document.querySelector('.social-ref-upload-text');
        const statusPercent = document.querySelector('.social-ref-upload-percent');
        const statusBar = document.querySelector('.social-ref-upload-bar');
        const resultBox = document.querySelector('.social-ref-upload-result');

        const setProgress = function(percent, text) {
            const safePercent = Math.max(0, Math.min(100, Number(percent) || 0));
            if (statusBox) statusBox.style.display = 'block';
            if (statusBar) statusBar.style.width = safePercent + '%';
            if (statusPercent) statusPercent.textContent = safePercent + '%';
            if (statusText && text) statusText.textContent = text;
        };

        form.addEventListener('submit', function(evt) {
            evt.preventDefault();
            if (!fileInput || !fileInput.files || !fileInput.files.length) {
                if (resultBox) {
                    resultBox.textContent = 'اختر ملفًا واحدًا على الأقل قبل الرفع.';
                    resultBox.style.color = '#d98c8c';
                }
                return;
            }

            const validation = socialValidateUploadBatch(form);
            if (!validation.ok) {
                if (resultBox) {
                    resultBox.textContent = validation.message;
                    resultBox.style.color = '#d98c8c';
                }
                return;
            }

            const data = new FormData(form);
            const xhr = new XMLHttpRequest();
            xhr.open('POST', form.getAttribute('action') || window.location.href, true);
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');

            if (button) {
                button.disabled = true;
                button.textContent = 'جاري الرفع...';
            }
            if (resultBox) {
                resultBox.textContent = '';
            }
            setProgress(0, 'جاري رفع الملفات...');

            xhr.upload.addEventListener('progress', function(e) {
                if (!e.lengthComputable) return;
                const percent = Math.round((e.loaded / e.total) * 100);
                setProgress(percent, 'جاري رفع الملفات...');
            });

            xhr.onreadystatechange = function() {
                if (xhr.readyState !== 4) return;
                if (button) {
                    button.disabled = false;
                    button.textContent = 'رفع الملفات';
                }

                let payload = null;
                try {
                    payload = JSON.parse(xhr.responseText || '{}');
                } catch (err) {
                    payload = null;
                }

                if (xhr.status >= 200 && xhr.status < 300 && payload && payload.ok) {
                    socialClearFileInputs(form);
                    setProgress(100, 'تم رفع الملفات بنجاح');
                    if (resultBox) {
                        const count = Number(payload.uploaded_count || 0);
                        resultBox.textContent = count > 0 ? ('تم رفع ' + count + ' ملف/ملفات بنجاح. جارٍ تحديث الصفحة...') : 'لم يتم رفع أي ملف.';
                        resultBox.style.color = '#9fd6a8';
                    }
                    window.setTimeout(function() {
                        window.location.href = payload.redirect || window.location.href;
                    }, 500);
                    return;
                }

                setProgress(0, 'فشل رفع الملفات');
                if (resultBox) {
                    const fallbackError = xhr.status === 413
                        ? 'حجم الملفات أكبر من حد الرفع المسموح. قلل حجم الملفات أو ارفعها على دفعات.'
                        : 'تعذر رفع الملفات. قد يكون حجم الملفات أكبر من إعدادات الخادم أو انتهت الجلسة. حدّث الصفحة وجرب مرة أخرى.';
                    resultBox.textContent = (payload && payload.error) ? payload.error : fallbackError;
                    resultBox.style.color = '#d98c8c';
                }
            };

            xhr.send(data);
        });
    })();

    (function() {
        const forms = document.querySelectorAll('.social-async-form');
        if (!forms.length) return;

        forms.forEach(function(form) {
            form.addEventListener('submit', function(evt) {
                evt.preventDefault();

                const submitter = evt.submitter || document.activeElement;
                if (submitter && submitter.name === 'delete_design_img' && submitter.classList && submitter.classList.contains('social-delete-design-img')) {
                    if (!confirm('حذف الصورة؟')) {
                        return;
                    }
                    const postInput = form.querySelector('input[name="post_id"]');
                    const imageInput = form.querySelector('input[name="img_path"]');
                    if (postInput) postInput.value = submitter.getAttribute('data-post-id') || '';
                    if (imageInput) imageInput.value = submitter.getAttribute('data-img-path') || '';
                }

                const statusBox = form.querySelector('.social-form-status');
                const progressWrap = form.querySelector('.social-form-progress');
                const progressBar = form.querySelector('.social-form-progress-bar');
                const progressText = form.querySelector('.social-form-progress-text');
                const shouldTrackProgress = form.getAttribute('data-upload-progress') === '1';
                const validation = socialValidateUploadBatch(form);
                if (!validation.ok) {
                    if (statusBox) {
                        statusBox.style.display = 'block';
                        statusBox.style.color = '#d98c8c';
                        statusBox.textContent = validation.message;
                    } else {
                        alert(validation.message);
                    }
                    return;
                }

                const formData = new FormData(form);

                if (submitter && submitter.name) {
                    formData.set(submitter.name, submitter.value || '1');
                }

                if (statusBox) {
                    statusBox.style.display = 'block';
                    statusBox.style.color = '#aaa';
                    statusBox.textContent = shouldTrackProgress ? 'جاري التنفيذ...' : 'جاري الحفظ...';
                }
                if (submitter) {
                    submitter.disabled = true;
                }
                if (progressWrap) {
                    progressWrap.style.display = shouldTrackProgress ? 'block' : 'none';
                }
                if (progressBar) {
                    progressBar.style.width = '0%';
                }
                if (progressText) {
                    progressText.textContent = 'جاري الرفع...';
                }

                const xhr = new XMLHttpRequest();
                xhr.open('POST', form.getAttribute('action') || window.location.href, true);
                xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');

                xhr.upload.addEventListener('progress', function(e) {
                    if (!shouldTrackProgress || !e.lengthComputable) return;
                    const percent = Math.max(0, Math.min(100, Math.round((e.loaded / e.total) * 100)));
                    if (progressBar) progressBar.style.width = percent + '%';
                    if (progressText) progressText.textContent = 'جاري الرفع... ' + percent + '%';
                });

                xhr.onreadystatechange = function() {
                    if (xhr.readyState !== 4) return;
                    if (submitter) {
                        submitter.disabled = false;
                    }

                    let payload = null;
                    try {
                        payload = JSON.parse(xhr.responseText || '{}');
                    } catch (err) {
                        payload = null;
                    }

                    if (xhr.status >= 200 && xhr.status < 300 && payload && payload.ok) {
                        socialClearFileInputs(form);
                        socialApplyUpdatedPostAssets(payload.updated_posts);
                        if (statusBox) {
                            statusBox.style.color = '#9fd6a8';
                            statusBox.textContent = payload.message || 'تم التنفيذ بنجاح.';
                        }
                        if (progressBar) progressBar.style.width = '100%';
                        if (progressText && shouldTrackProgress) progressText.textContent = 'تم الرفع بنجاح';
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
                    if (progressWrap) {
                        progressWrap.style.display = 'none';
                    }
                };

                xhr.send(formData);
            });
        });
    })();
</script>
