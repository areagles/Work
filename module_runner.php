<?php
ob_start();

require 'auth.php';
require 'config.php';
require_once 'job_engine.php';
app_handle_lang_switch($conn);

$jobRef = (string)($_GET['id'] ?? $_GET['job'] ?? '');
$jobId = job_resolve_id($conn, $jobRef);
if ($jobId <= 0) {
    http_response_code(400);
    exit('Invalid job id');
}

app_require_job_access($conn, $jobId, false);
$job = job_load_header_data($conn, $jobId);
if (!$job) {
    http_response_code(404);
    exit('Job not found');
}

$jobType = (string)($job['job_type'] ?? '');
$mode = strtolower(trim((string)($_GET['mode'] ?? '')));
$moduleMap = [
    'print' => 'print.php',
    'carton' => 'carton.php',
    'plastic' => 'plastic.php',
    'web' => 'web.php',
    'social' => 'social.php',
    'design_only' => 'design_only.php',
];
$moduleFileName = ($mode === 'generic') ? 'generic.php' : (string)($moduleMap[$jobType] ?? 'generic.php');
if ($moduleFileName === '') {
    $moduleFileName = 'generic.php';
}

$moduleFile = __DIR__ . '/modules/' . $moduleFileName;
if (!is_file($moduleFile)) {
    $moduleFile = __DIR__ . '/modules/generic.php';
}

$app_module_embedded = true;
?>
<!DOCTYPE html>
<html lang="<?php echo app_h(app_current_lang()); ?>" dir="<?php echo app_h(app_lang_dir()); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo app_h((string)($job['job_name'] ?? 'Execution Module')); ?></title>
    <link rel="stylesheet" href="style.css">
    <style>
        body { margin:0; background:#090b0f; color:#f4f4f4; font-family:'Cairo',sans-serif; }
        .module-runner-shell { padding:14px; }
    </style>
</head>
<body>
    <div class="module-runner-shell">
        <?php include $moduleFile; ?>
    </div>
</body>
</html>
