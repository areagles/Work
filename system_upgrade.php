<?php
// system_upgrade.php
// مركز الصيانة والتخصيص والتحديث التلقائي (Admin only)

require 'auth.php';
require 'config.php';
app_start_session();

if (($_SESSION['role'] ?? '') !== 'admin') {
    require 'header.php';
    echo "<div class='container' style='margin-top:30px;'><div style='background:#1d0f0f;border:1px solid #5b2020;border-radius:12px;padding:20px;color:#ffb3b3;'>هذه الصفحة مخصصة للمدير فقط.</div></div>";
    require 'footer.php';
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    app_require_csrf();
}

function app_existing_tables(mysqli $conn): array
{
    $tables = [];
    $res = $conn->query("SHOW TABLES");
    if ($res) {
        while ($row = $res->fetch_row()) {
            $tables[] = (string)$row[0];
        }
    }
    return $tables;
}

function app_count_table(mysqli $conn, string $table): int
{
    if (!preg_match('/^[a-z0-9_]+$/i', $table)) {
        return 0;
    }
    $res = $conn->query("SELECT COUNT(*) AS c FROM `$table`");
    if (!$res) {
        return 0;
    }
    $row = $res->fetch_assoc();
    return (int)($row['c'] ?? 0);
}

function app_truncate_tables(mysqli $conn, array $tables): array
{
    $existingMap = array_fill_keys(app_existing_tables($conn), true);
    $done = [];
    $skipped = [];
    $errors = [];

    $conn->query("SET FOREIGN_KEY_CHECKS=0");
    try {
        foreach ($tables as $table) {
            if (!preg_match('/^[a-z0-9_]+$/i', $table)) {
                $skipped[] = $table;
                continue;
            }
            if (!isset($existingMap[$table])) {
                $skipped[] = $table;
                continue;
            }
            if ($conn->query("TRUNCATE TABLE `$table`")) {
                $done[] = $table;
            } else {
                $errors[] = $table . ': ' . $conn->error;
            }
        }
    } finally {
        $conn->query("SET FOREIGN_KEY_CHECKS=1");
    }

    return ['done' => $done, 'skipped' => $skipped, 'errors' => $errors];
}

function app_cleanup_workspace(string $root, bool $deep): array
{
    $removed = [];
    $failed = [];
    $scanned = 0;

    $patterns = [
        '/^\\.DS_Store$/i',
        '/^Thumbs\\.db$/i',
        '/\\.swp$/i',
        '/~$/',
    ];
    if ($deep) {
        $patterns[] = '/\\.tmp$/i';
        $patterns[] = '/\\.bak$/i';
        $patterns[] = '/\\.log$/i';
    }

    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($it as $fileInfo) {
        $path = $fileInfo->getPathname();
        if (strpos($path, DIRECTORY_SEPARATOR . '.git' . DIRECTORY_SEPARATOR) !== false) {
            continue;
        }
        if (!$fileInfo->isFile()) {
            continue;
        }

        $scanned++;
        $filename = $fileInfo->getFilename();
        $match = false;
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $filename)) {
                $match = true;
                break;
            }
        }
        if (!$match) {
            continue;
        }

        if (@unlink($path)) {
            $removed[] = $path;
        } else {
            $failed[] = $path;
        }
    }

    return ['scanned' => $scanned, 'removed' => $removed, 'failed' => $failed];
}

function up_bytes(int $bytes): string
{
    $bytes = max(0, $bytes);
    if ($bytes < 1024) {
        return $bytes . ' B';
    }
    if ($bytes < 1024 * 1024) {
        return number_format($bytes / 1024, 1) . ' KB';
    }
    if ($bytes < 1024 * 1024 * 1024) {
        return number_format($bytes / 1024 / 1024, 1) . ' MB';
    }
    return number_format($bytes / 1024 / 1024 / 1024, 2) . ' GB';
}

function up_error_text(string $code): string
{
    $code = strtolower(trim($code));
    if ($code === '') {
        return '';
    }
    $map = [
        'schema_not_ready' => 'تعذر تجهيز بنية التحديث في قاعدة البيانات.',
        'zip_only' => 'يُسمح فقط بملفات ZIP.',
        'invalid_size' => 'حجم الملف غير مسموح.',
        'storage_not_writable' => 'مجلدات التحديث غير قابلة للكتابة.',
        'move_failed' => 'تعذر حفظ ملف التحديث على الخادم.',
        'zip_open_failed' => 'الملف المضغوط غير صالح.',
        'zip_empty' => 'الملف المضغوط فارغ.',
        'invalid_package_id' => 'معرّف الحزمة غير صحيح.',
        'package_not_found' => 'الحزمة غير موجودة.',
        'package_file_missing' => 'ملف الحزمة غير موجود.',
        'apply_failed' => 'تعذر تطبيق التحديث.',
        'remote_url_required' => 'رابط التحديث المركزي مطلوب.',
        'remote_token_required' => 'توكن الربط المركزي مطلوب.',
        'invalid_json' => 'استجابة خادم التحديث غير صالحة.',
        'download_url_missing' => 'الرابط الخاص بتنزيل التحديث غير متاح.',
        'download_failed' => 'فشل تنزيل الحزمة من الخادم المركزي.',
        'hash_mismatch' => 'فشل التحقق من سلامة الملف (SHA256).',
        'store_remote_failed' => 'تعذر حفظ التحديث المسحوب محليًا.',
        'remote_check_failed' => 'فشل الاتصال بخادم التحديث.',
        'access_denied' => 'لا تملك الصلاحية لتنفيذ هذا الإجراء.',
    ];
    return $map[$code] ?? ('خطأ: ' . $code);
}

$notice = '';
$noticeType = 'info';
$actionReport = [];

app_initialize_system_settings($conn);
$edition = app_license_edition();
$isSuperUser = app_is_super_user();
$isOwnerEdition = ($edition === 'owner');
$canManageOwnerUpdates = $isOwnerEdition && $isSuperUser;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_customization') {
        $appName = trim((string)($_POST['app_name'] ?? 'Arab Eagles'));
        $themeColor = app_normalize_hex_color($_POST['theme_color'] ?? '#d4af37');
        $accentMode = trim((string)($_POST['accent_mode'] ?? 'adaptive'));
        if (!in_array($accentMode, ['adaptive', 'focus', 'minimal'], true)) {
            $accentMode = 'adaptive';
        }
        $openingDeductionSign = trim((string)($_POST['opening_balance_deduction_sign'] ?? 'positive'));
        if (!in_array($openingDeductionSign, ['positive', 'negative', 'both', 'none'], true)) {
            $openingDeductionSign = 'positive';
        }
        $supplierOpeningDeductionSign = trim((string)($_POST['supplier_opening_balance_deduction_sign'] ?? 'positive'));
        if (!in_array($supplierOpeningDeductionSign, ['positive', 'negative', 'both', 'none'], true)) {
            $supplierOpeningDeductionSign = 'positive';
        }

        $tz = trim((string)($_POST['timezone'] ?? 'Africa/Cairo'));
        if (!in_array($tz, timezone_identifiers_list(), true)) {
            $tz = 'Africa/Cairo';
        }

        $updates = [
            'app_name' => mb_substr($appName !== '' ? $appName : 'Arab Eagles', 0, 80),
            'theme_color' => $themeColor,
            'accent_mode' => $accentMode,
            'opening_balance_deduction_sign' => $openingDeductionSign,
            'supplier_opening_balance_deduction_sign' => $supplierOpeningDeductionSign,
            'timezone' => $tz,
        ];

        $okAll = true;
        foreach ($updates as $key => $value) {
            if (!app_setting_set($conn, $key, (string)$value)) {
                $okAll = false;
            }
        }
        if ($okAll) {
            $notice = 'تم حفظ التخصيصات بنجاح.';
            $noticeType = 'success';
        } else {
            $notice = 'حدثت مشكلة أثناء حفظ بعض التخصيصات.';
            $noticeType = 'error';
        }
    } elseif ($action === 'cleanup_workspace') {
        $deep = isset($_POST['deep_cleanup']) && $_POST['deep_cleanup'] === '1';
        $result = app_cleanup_workspace(__DIR__, $deep);
        $actionReport = $result;
        $notice = 'تم تنظيف ' . count($result['removed']) . ' ملف زائد.';
        $noticeType = empty($result['failed']) ? 'success' : 'warning';
    } elseif ($action === 'reset_system') {
        $mode = (($_POST['reset_mode'] ?? 'ops') === 'factory') ? 'factory' : 'ops';
        $confirmText = trim((string)($_POST['confirm_text'] ?? ''));

        if ($confirmText !== 'RESET NOW') {
            $notice = 'لم يتم تنفيذ إعادة التعيين: جملة التأكيد غير صحيحة.';
            $noticeType = 'error';
        } else {
            $opsTables = [
                'job_orders', 'job_files', 'job_proofs', 'proof_comments', 'social_posts',
                'invoices', 'purchase_invoices', 'financial_receipts', 'quotes',
                'purchases', 'salaries', 'payroll_sheets', 'inventory_transactions', 'inventory_stock',
                'inventory_audit_lines', 'inventory_audit_sessions'
            ];
            $factoryExtra = ['clients', 'suppliers', 'inventory_items', 'warehouses', 'employees'];
            $targets = $mode === 'factory' ? array_merge($opsTables, $factoryExtra) : $opsTables;

            $result = app_truncate_tables($conn, $targets);
            $actionReport = $result;

            if (empty($result['errors'])) {
                $notice = $mode === 'factory'
                    ? 'تمت إعادة التعيين المصنعية بنجاح (مع الحفاظ على المستخدمين والإعدادات).'
                    : 'تمت إعادة التعيين التشغيلية بنجاح.';
                $noticeType = 'success';
            } else {
                $notice = 'تم تنفيذ جزئي لإعادة التعيين مع وجود أخطاء في بعض الجداول.';
                $noticeType = 'warning';
            }
        }
    } elseif ($action === 'save_update_settings') {
        if (!$isSuperUser && !$isOwnerEdition) {
            // في نسخة العميل: مدير النظام مسموح له.
        }
        $remoteUrl = trim((string)($_POST['update_remote_url'] ?? ''));
        $remoteToken = trim((string)($_POST['update_remote_token'] ?? ''));
        $channel = trim((string)($_POST['update_channel'] ?? 'stable'));
        if (!in_array($channel, ['stable', 'beta'], true)) {
            $channel = 'stable';
        }
        $currentVersion = app_update_sanitize_version_tag((string)($_POST['update_current_version'] ?? ''));

        if ($remoteUrl !== '' && !filter_var($remoteUrl, FILTER_VALIDATE_URL)) {
            $notice = 'رابط التحديث المركزي غير صالح.';
            $noticeType = 'error';
        } else {
            app_setting_set($conn, 'update_remote_url', $remoteUrl);
            app_setting_set($conn, 'update_remote_token', $remoteToken);
            app_setting_set($conn, 'update_channel', $channel);
            app_setting_set($conn, 'update_current_version', $currentVersion);

            if ($canManageOwnerUpdates) {
                $apiToken = trim((string)($_POST['update_api_token'] ?? ''));
                if ($apiToken !== '') {
                    app_setting_set($conn, 'update_api_token', $apiToken);
                }
            }

            $notice = 'تم حفظ إعدادات مركز التحديث.';
            $noticeType = 'success';
        }
    } elseif ($action === 'generate_update_token') {
        if (!$canManageOwnerUpdates) {
            $notice = up_error_text('access_denied');
            $noticeType = 'error';
        } else {
            $token = bin2hex(random_bytes(32));
            app_setting_set($conn, 'update_api_token', $token);
            app_audit_log_add($conn, 'updates.api_token_generated', [
                'entity_type' => 'update_center',
                'entity_key' => 'api_token',
            ]);
            $notice = 'تم إنشاء توكن API جديد بنجاح.';
            $noticeType = 'success';
        }
    } elseif ($action === 'upload_update_package') {
        if (!$canManageOwnerUpdates) {
            $notice = up_error_text('access_denied');
            $noticeType = 'error';
        } else {
            $meta = [
                'version_tag' => (string)($_POST['version_tag'] ?? ''),
                'target_edition' => (string)($_POST['target_edition'] ?? 'any'),
                'release_notes' => (string)($_POST['release_notes'] ?? ''),
                'set_active' => isset($_POST['set_active']) && (string)($_POST['set_active'] ?? '') === '1',
                'uploaded_by' => (string)($_SESSION['name'] ?? $_SESSION['username'] ?? 'admin'),
                'source_mode' => 'local_upload',
            ];
            $stored = app_update_store_package($conn, $_FILES['update_zip'] ?? [], $meta);
            if (!empty($stored['ok'])) {
                app_audit_log_add($conn, 'updates.package_uploaded', [
                    'entity_type' => 'update_package',
                    'entity_key' => (string)($stored['id'] ?? 0),
                    'details' => ['version_tag' => (string)($meta['version_tag'] ?? '')],
                ]);
                $notice = 'تم رفع الحزمة بنجاح (ID: ' . (int)$stored['id'] . ').';
                $noticeType = 'success';
            } else {
                $notice = up_error_text((string)($stored['error'] ?? 'upload_failed'));
                $noticeType = 'error';
            }
        }
    } elseif ($action === 'activate_update_package') {
        if (!$canManageOwnerUpdates) {
            $notice = up_error_text('access_denied');
            $noticeType = 'error';
        } else {
            $packageId = (int)($_POST['package_id'] ?? 0);
            $active = app_update_activate_package($conn, $packageId);
            if (!empty($active['ok'])) {
                app_audit_log_add($conn, 'updates.package_activated', [
                    'entity_type' => 'update_package',
                    'entity_key' => (string)$packageId,
                ]);
                $notice = 'تم تعيين الحزمة النشطة بنجاح.';
                $noticeType = 'success';
            } else {
                $notice = up_error_text((string)($active['error'] ?? 'db_update_failed'));
                $noticeType = 'error';
            }
        }
    } elseif ($action === 'apply_update_package') {
        $packageId = (int)($_POST['package_id'] ?? 0);
        $apply = app_update_apply_package($conn, $packageId, (string)($_SESSION['name'] ?? $_SESSION['username'] ?? 'admin'));
        if (!empty($apply['ok'])) {
            $details = (array)($apply['details'] ?? []);
            app_audit_log_add($conn, 'updates.package_applied', [
                'entity_type' => 'update_package',
                'entity_key' => (string)$packageId,
                'details' => ['applied' => (int)($details['applied'] ?? 0)],
            ]);
            $notice = 'تم تطبيق التحديث بنجاح. ملفات محدثة: ' . number_format((int)($details['applied'] ?? 0));
            $noticeType = 'success';
        } else {
            $notice = up_error_text((string)($apply['error'] ?? 'apply_failed'));
            $noticeType = 'error';
        }
    } elseif ($action === 'pull_remote_update') {
        $remoteUrl = trim((string)app_setting_get($conn, 'update_remote_url', ''));
        if ($remoteUrl === '') {
            $remoteUrl = trim((string)app_env('APP_UPDATE_REMOTE_URL', (string)app_env('APP_LICENSE_REMOTE_URL', '')));
        }
        $remoteToken = trim((string)app_setting_get($conn, 'update_remote_token', ''));
        if ($remoteToken === '') {
            $remoteToken = trim((string)app_env('APP_UPDATE_REMOTE_TOKEN', (string)app_env('APP_LICENSE_REMOTE_TOKEN', '')));
        }
        $channel = trim((string)app_setting_get($conn, 'update_channel', 'stable'));
        if ($channel === '') {
            $channel = 'stable';
        }

        $pull = app_update_pull_remote_package($conn, $remoteUrl, $remoteToken, [
            'edition' => $edition,
            'channel' => $channel,
            'performed_by' => (string)($_SESSION['name'] ?? $_SESSION['username'] ?? 'admin'),
            'force' => isset($_POST['force']) && (string)($_POST['force'] ?? '') === '1',
        ]);

        if (!empty($pull['ok'])) {
            app_audit_log_add($conn, 'updates.remote_pull', [
                'entity_type' => 'update_center',
                'entity_key' => 'remote',
                'details' => [
                    'has_update' => !empty($pull['has_update']) ? 1 : 0,
                    'skipped' => !empty($pull['skipped']) ? 1 : 0,
                ],
            ]);
            if (!empty($pull['has_update'])) {
                $notice = 'تم سحب وتطبيق آخر تحديث بنجاح.';
            } else {
                $notice = !empty($pull['skipped'])
                    ? 'لا يوجد تحديث جديد (أنت بالفعل على آخر إصدار).'
                    : 'تم فحص الخادم المركزي: لا يوجد تحديث جديد.';
            }
            $noticeType = 'success';
        } else {
            $notice = up_error_text((string)($pull['error'] ?? 'remote_check_failed'));
            $noticeType = 'error';
        }
    }
}

$updateSchemaReady = app_ensure_update_center_schema($conn);
$appName = app_setting_get($conn, 'app_name', 'Arab Eagles');
$themeColor = app_normalize_hex_color(app_setting_get($conn, 'theme_color', '#d4af37'));
$accentMode = app_setting_get($conn, 'accent_mode', 'adaptive');
$openingDeductionSign = app_setting_get($conn, 'opening_balance_deduction_sign', 'positive');
$supplierOpeningDeductionSign = app_setting_get($conn, 'supplier_opening_balance_deduction_sign', 'positive');
$timezone = app_setting_get($conn, 'timezone', 'Africa/Cairo');

$updateRemoteUrl = trim((string)app_setting_get($conn, 'update_remote_url', ''));
if ($updateRemoteUrl === '') {
    $updateRemoteUrl = trim((string)app_env('APP_UPDATE_REMOTE_URL', (string)app_env('APP_LICENSE_REMOTE_URL', '')));
}
$updateRemoteToken = trim((string)app_setting_get($conn, 'update_remote_token', ''));
if ($updateRemoteToken === '') {
    $updateRemoteToken = trim((string)app_env('APP_UPDATE_REMOTE_TOKEN', (string)app_env('APP_LICENSE_REMOTE_TOKEN', '')));
}
$updateChannel = trim((string)app_setting_get($conn, 'update_channel', 'stable'));
if (!in_array($updateChannel, ['stable', 'beta'], true)) {
    $updateChannel = 'stable';
}
$updateCurrentVersion = app_setting_get($conn, 'update_current_version', '');
$updateLastCheckAt = app_setting_get($conn, 'update_last_check_at', '');
$updateLastStatus = app_setting_get($conn, 'update_last_status', '');
$updateLastError = app_setting_get($conn, 'update_last_error', '');
$updateLastPackageId = app_setting_get($conn, 'update_last_package_id', '');
$updateApiToken = $updateSchemaReady ? app_update_api_token($conn) : '';

$packages = $updateSchemaReady ? app_update_list_packages($conn, 80) : [];
$latestPackage = $updateSchemaReady ? app_update_latest_package($conn, $isOwnerEdition ? 'any' : $edition) : null;
$latestVersion = trim((string)($latestPackage['version_tag'] ?? ''));

$existingTables = app_existing_tables($conn);
$tableMap = array_fill_keys($existingTables, true);

$diagnostics = [
    'PHP Version' => PHP_VERSION,
    'MySQL Server' => $conn->server_info,
    'Timezone' => date_default_timezone_get(),
    'Uploads Writable' => is_writable(__DIR__ . '/uploads') ? 'Yes' : 'No',
    'Assets Writable' => is_writable(__DIR__ . '/assets') ? 'Yes' : 'No',
    'Inventory Tables' => (isset($tableMap['warehouses']) && isset($tableMap['inventory_items']) && isset($tableMap['inventory_stock'])) ? 'Ready' : 'Missing',
    'Update Center' => $updateSchemaReady ? 'Ready' : 'Error',
    'License Edition' => $edition,
];

$liveCounts = [
    'عمليات' => isset($tableMap['job_orders']) ? app_count_table($conn, 'job_orders') : 0,
    'فواتير بيع' => isset($tableMap['invoices']) ? app_count_table($conn, 'invoices') : 0,
    'فواتير شراء' => isset($tableMap['purchase_invoices']) ? app_count_table($conn, 'purchase_invoices') : 0,
    'حركات مالية' => isset($tableMap['financial_receipts']) ? app_count_table($conn, 'financial_receipts') : 0,
    'حركات مخزون' => isset($tableMap['inventory_transactions']) ? app_count_table($conn, 'inventory_transactions') : 0,
];

$updateApiPublicUrl = rtrim(app_base_url(), '/') . '/update_api.php';

require 'header.php';
?>

<style>
    .upgrade-wrap { max-width: 1240px; margin: 0 auto; padding: 22px; }
    .upgrade-grid { display: grid; grid-template-columns: 1.1fr 1fr; gap: 18px; }
    .upgrade-card {
        background: #151515;
        border: 1px solid #2e2e2e;
        border-radius: 14px;
        padding: 18px;
        box-shadow: 0 8px 25px rgba(0,0,0,0.35);
    }
    .upgrade-title { margin: 0 0 14px; color: var(--gold-primary); font-size: 1.1rem; }
    .upgrade-row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 12px; }
    .upgrade-input, .upgrade-select, .upgrade-textarea {
        width: 100%;
        background: #0f0f0f;
        color: #fff;
        border: 1px solid #323232;
        border-radius: 10px;
        padding: 10px 12px;
        font-family: 'Cairo', sans-serif;
    }
    .upgrade-textarea { min-height: 84px; resize: vertical; }
    .upgrade-btn {
        border: 1px solid #3b3b3b;
        background: linear-gradient(140deg, var(--gold-primary), #9c7726);
        color: #000;
        border-radius: 10px;
        padding: 10px 14px;
        font-weight: 700;
        cursor: pointer;
    }
    .subtle-btn {
        border: 1px solid #3e4351;
        background: #202535;
        color: #cfd8ff;
        border-radius: 10px;
        padding: 9px 12px;
        font-weight: 700;
        cursor: pointer;
    }
    .danger-btn {
        border: 1px solid rgba(231, 76, 60, 0.45);
        background: rgba(231, 76, 60, 0.14);
        color: #ff8d80;
    }
    .diag-table { width: 100%; border-collapse: collapse; }
    .diag-table td { padding: 8px 0; border-bottom: 1px dashed #2b2b2b; color: #d6d6d6; }
    .notice {
        border-radius: 10px;
        padding: 12px 14px;
        margin-bottom: 14px;
        border: 1px solid transparent;
    }
    .notice.success { background: rgba(46, 204, 113, 0.1); border-color: rgba(46, 204, 113, 0.5); color: #8be8b0; }
    .notice.error { background: rgba(231, 76, 60, 0.12); border-color: rgba(231, 76, 60, 0.45); color: #ff9a90; }
    .notice.warning { background: rgba(241, 196, 15, 0.1); border-color: rgba(241, 196, 15, 0.45); color: #f4d67a; }
    .report-box {
        margin-top: 12px;
        background: #0f0f0f;
        border: 1px solid #2e2e2e;
        border-radius: 10px;
        padding: 10px;
        color: #bfbfbf;
        font-size: 0.88rem;
        line-height: 1.7;
    }
    .update-table-wrap {
        overflow: auto;
        border: 1px solid #2b2b2b;
        border-radius: 12px;
        margin-top: 10px;
    }
    .update-table {
        width: 100%;
        border-collapse: collapse;
        min-width: 980px;
    }
    .update-table th,
    .update-table td {
        border-bottom: 1px solid #242424;
        padding: 10px;
        text-align: right;
        vertical-align: middle;
    }
    .update-table th { color: #f0d277; background: #11141f; }
    .update-badge {
        display: inline-block;
        padding: 4px 8px;
        border-radius: 999px;
        border: 1px solid #3b3b3b;
        font-size: 0.8rem;
        color: #ddd;
        background: #1e1e1e;
    }
    .update-badge.active { color: #8ff2a8; border-color: rgba(46,204,113,.5); background: rgba(46,204,113,.12); }
    .actions-inline { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
    .label-muted { color: #9ca3af; font-size: .9rem; }
    .token-box {
        width: 100%;
        background: #0c0f18;
        border: 1px solid #26314a;
        border-radius: 10px;
        color: #d8e0ff;
        padding: 10px;
        font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
        font-size: .86rem;
        direction: ltr;
        text-align: left;
    }
    @media (max-width: 980px) {
        .upgrade-grid { grid-template-columns: 1fr; }
        .upgrade-row { grid-template-columns: 1fr; }
    }
</style>

<div class="upgrade-wrap">
    <h2 class="ai-title" style="margin-top:0;">مركز التخصيص والصيانة والتحديث</h2>
    <p style="color:#999;margin-top:6px;">تخصيص النظام، إعادة التهيئة الآمنة، وإدارة تحديثات ZIP تلقائياً بين نظام المالك والعملاء.</p>

    <?php if ($notice !== ''): ?>
        <div class="notice <?php echo app_h($noticeType); ?>"><?php echo app_h($notice); ?></div>
    <?php endif; ?>

    <div class="upgrade-grid">
        <div class="upgrade-card">
            <h3 class="upgrade-title"><i class="fa-solid fa-sliders"></i> تخصيص النظام</h3>
            <form method="post">
                <?php echo app_csrf_input(); ?>
                <input type="hidden" name="action" value="save_customization">

                <div class="upgrade-row">
                    <div>
                        <label>اسم النظام</label>
                        <input class="upgrade-input" type="text" name="app_name" maxlength="80" value="<?php echo app_h($appName); ?>">
                    </div>
                    <div>
                        <label>لون الهوية</label>
                        <input class="upgrade-input" type="color" name="theme_color" value="<?php echo app_h($themeColor); ?>">
                    </div>
                </div>

                <div class="upgrade-row">
                    <div>
                        <label>نمط الواجهة</label>
                        <select name="accent_mode" class="upgrade-select">
                            <option value="adaptive" <?php echo $accentMode === 'adaptive' ? 'selected' : ''; ?>>Adaptive</option>
                            <option value="focus" <?php echo $accentMode === 'focus' ? 'selected' : ''; ?>>Focus</option>
                            <option value="minimal" <?php echo $accentMode === 'minimal' ? 'selected' : ''; ?>>Minimal</option>
                        </select>
                    </div>
                    <div>
                        <label>خصم رصيد أول المدة (العملاء)</label>
                        <select name="opening_balance_deduction_sign" class="upgrade-select">
                            <option value="positive" <?php echo $openingDeductionSign === 'positive' ? 'selected' : ''; ?>>عند الرصيد الموجب</option>
                            <option value="negative" <?php echo $openingDeductionSign === 'negative' ? 'selected' : ''; ?>>عند الرصيد السالب</option>
                            <option value="both" <?php echo $openingDeductionSign === 'both' ? 'selected' : ''; ?>>في الحالتين</option>
                            <option value="none" <?php echo $openingDeductionSign === 'none' ? 'selected' : ''; ?>>تعطيل الخصم التلقائي</option>
                        </select>
                    </div>
                </div>

                <div class="upgrade-row">
                    <div>
                        <label>خصم رصيد أول المدة (الموردين)</label>
                        <select name="supplier_opening_balance_deduction_sign" class="upgrade-select">
                            <option value="positive" <?php echo $supplierOpeningDeductionSign === 'positive' ? 'selected' : ''; ?>>عند الرصيد الموجب</option>
                            <option value="negative" <?php echo $supplierOpeningDeductionSign === 'negative' ? 'selected' : ''; ?>>عند الرصيد السالب</option>
                            <option value="both" <?php echo $supplierOpeningDeductionSign === 'both' ? 'selected' : ''; ?>>في الحالتين</option>
                            <option value="none" <?php echo $supplierOpeningDeductionSign === 'none' ? 'selected' : ''; ?>>تعطيل الخصم التلقائي</option>
                        </select>
                    </div>
                    <div>
                        <label>المنطقة الزمنية</label>
                        <select name="timezone" class="upgrade-select">
                            <?php foreach (['Africa/Cairo', 'Asia/Riyadh', 'UTC'] as $tz): ?>
                                <option value="<?php echo app_h($tz); ?>" <?php echo $timezone === $tz ? 'selected' : ''; ?>><?php echo app_h($tz); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <button class="upgrade-btn" type="submit"><i class="fa-solid fa-floppy-disk"></i> حفظ التخصيصات</button>
            </form>
        </div>

        <div class="upgrade-card">
            <h3 class="upgrade-title"><i class="fa-solid fa-heart-pulse"></i> تشخيص النظام</h3>
            <table class="diag-table">
                <tbody>
                    <?php foreach ($diagnostics as $k => $v): ?>
                        <tr>
                            <td style="color:#999;"><?php echo app_h($k); ?></td>
                            <td><?php echo app_h((string)$v); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php foreach ($liveCounts as $k => $v): ?>
                        <tr>
                            <td style="color:#999;"><?php echo app_h($k); ?></td>
                            <td><?php echo number_format((int)$v); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <div style="margin-top:12px;color:#9c9c9c;font-size:0.9rem;">
                قبل أي إعادة تعيين، استخدم النسخ الاحتياطي من <a href="backup.php" style="color:var(--gold-primary);">هنا</a>.
            </div>
        </div>
    </div>

    <div class="upgrade-grid" style="margin-top:18px;">
        <div class="upgrade-card">
            <h3 class="upgrade-title"><i class="fa-solid fa-broom"></i> التنظيف الجذري الآمن</h3>
            <form method="post">
                <?php echo app_csrf_input(); ?>
                <input type="hidden" name="action" value="cleanup_workspace">
                <label style="display:flex;gap:8px;align-items:center;color:#bbb;margin-bottom:12px;">
                    <input type="checkbox" name="deep_cleanup" value="1">
                    تفعيل تنظيف عميق (يشمل `.tmp` / `.bak` / `.log`)
                </label>
                <button class="upgrade-btn" type="submit"><i class="fa-solid fa-soap"></i> بدء التنظيف</button>
            </form>
            <?php if (!empty($actionReport) && isset($actionReport['removed'])): ?>
                <div class="report-box">
                    تم فحص <?php echo number_format((int)$actionReport['scanned']); ?> ملف.
                    <br>المحذوف: <?php echo number_format(count($actionReport['removed'])); ?>.
                    <br>المتعذر حذفه: <?php echo number_format(count($actionReport['failed'])); ?>.
                </div>
            <?php endif; ?>
        </div>

        <div class="upgrade-card">
            <h3 class="upgrade-title"><i class="fa-solid fa-rotate"></i> إعادة تعيين النظام</h3>
            <form method="post" onsubmit="return confirm('هذا الإجراء نهائي. هل أنت متأكد؟');">
                <?php echo app_csrf_input(); ?>
                <input type="hidden" name="action" value="reset_system">
                <div class="upgrade-row">
                    <div>
                        <label>الوضع</label>
                        <select class="upgrade-select" name="reset_mode">
                            <option value="ops">تشغيلي (العمليات فقط)</option>
                            <option value="factory">مصنعي (عمليات + بيانات رئيسية)</option>
                        </select>
                    </div>
                    <div>
                        <label>اكتب: RESET NOW</label>
                        <input class="upgrade-input" type="text" name="confirm_text" placeholder="RESET NOW" required>
                    </div>
                </div>
                <button class="upgrade-btn danger-btn" type="submit"><i class="fa-solid fa-triangle-exclamation"></i> تنفيذ إعادة التعيين</button>
            </form>
            <?php if (!empty($actionReport) && isset($actionReport['done'])): ?>
                <div class="report-box">
                    تم التنفيذ على <?php echo number_format(count($actionReport['done'])); ?> جدول.
                    <br>تم التخطي: <?php echo number_format(count($actionReport['skipped'])); ?>.
                    <br>أخطاء: <?php echo number_format(count($actionReport['errors'])); ?>.
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="upgrade-card" style="margin-top:18px;">
        <h3 class="upgrade-title"><i class="fa-solid fa-cloud-arrow-down"></i> مركز التحديث</h3>
        <p style="color:#9ca3af;margin-top:0;">رفع حزم تحديث ZIP، تعيين الإصدار النشط، وسحب التحديث تلقائياً من نظام المالك للعملاء.</p>

        <?php if (!$updateSchemaReady): ?>
            <div class="notice error" style="margin-bottom:0;">تعذر تجهيز مركز التحديث. راجع صلاحيات قاعدة البيانات ومجلد `uploads/system_updates`.</div>
        <?php else: ?>
            <div class="upgrade-grid" style="grid-template-columns:1.1fr 1fr; margin-top:12px;">
                <div class="upgrade-card" style="padding:14px;">
                    <h4 class="upgrade-title" style="font-size:1rem;margin-bottom:10px;">إعدادات الربط والتحديث</h4>
                    <form method="post">
                        <?php echo app_csrf_input(); ?>
                        <input type="hidden" name="action" value="save_update_settings">

                        <div class="upgrade-row">
                            <div>
                                <label>رابط API للتحديث المركزي</label>
                                <input class="upgrade-input" type="url" name="update_remote_url" placeholder="https://work.areagles.com/update_api.php" value="<?php echo app_h($updateRemoteUrl); ?>">
                            </div>
                            <div>
                                <label>توكن الربط المركزي</label>
                                <input class="upgrade-input" type="text" name="update_remote_token" value="<?php echo app_h($updateRemoteToken); ?>">
                            </div>
                        </div>

                        <div class="upgrade-row">
                            <div>
                                <label>قناة التحديث</label>
                                <select name="update_channel" class="upgrade-select">
                                    <option value="stable" <?php echo $updateChannel === 'stable' ? 'selected' : ''; ?>>Stable</option>
                                    <option value="beta" <?php echo $updateChannel === 'beta' ? 'selected' : ''; ?>>Beta</option>
                                </select>
                            </div>
                            <div>
                                <label>الإصدار الحالي</label>
                                <input class="upgrade-input" type="text" name="update_current_version" placeholder="2026.03.04" maxlength="80" value="<?php echo app_h($updateCurrentVersion); ?>">
                            </div>
                        </div>

                        <?php if ($canManageOwnerUpdates): ?>
                            <label>توكن API التحديث (للاتصالات بين الأنظمة)</label>
                            <input class="upgrade-input" type="text" name="update_api_token" value="<?php echo app_h($updateApiToken); ?>" style="margin-bottom:10px;">
                        <?php endif; ?>

                        <div class="actions-inline">
                            <button class="upgrade-btn" type="submit"><i class="fa-solid fa-floppy-disk"></i> حفظ الإعدادات</button>
                        </div>
                    </form>

                    <form method="post" style="margin-top:10px;">
                        <?php echo app_csrf_input(); ?>
                        <input type="hidden" name="action" value="pull_remote_update">
                        <label style="display:flex;align-items:center;gap:8px;color:#bfc7d8;margin-bottom:10px;">
                            <input type="checkbox" name="force" value="1"> فرض التحديث حتى لو الإصدار متطابق
                        </label>
                        <button class="subtle-btn" type="submit"><i class="fa-solid fa-arrows-rotate"></i> فحص وسحب آخر تحديث من الخادم</button>
                    </form>

                    <div class="report-box" style="margin-top:12px;">
                        آخر فحص: <?php echo app_h($updateLastCheckAt !== '' ? $updateLastCheckAt : '-'); ?><br>
                        آخر حالة: <?php echo app_h($updateLastStatus !== '' ? $updateLastStatus : '-'); ?><br>
                        آخر خطأ: <?php echo app_h($updateLastError !== '' ? $updateLastError : '-'); ?><br>
                        آخر حزمة مطبقة: <?php echo app_h($updateLastPackageId !== '' ? $updateLastPackageId : '-'); ?><br>
                        آخر إصدار متاح: <?php echo app_h($latestVersion !== '' ? $latestVersion : '-'); ?>
                    </div>
                </div>

                <div class="upgrade-card" style="padding:14px;">
                    <h4 class="upgrade-title" style="font-size:1rem;margin-bottom:10px;">نشر تحديث جديد</h4>
                    <?php if ($canManageOwnerUpdates): ?>
                        <form method="post" enctype="multipart/form-data">
                            <?php echo app_csrf_input(); ?>
                            <input type="hidden" name="action" value="upload_update_package">

                            <label>ملف التحديث (ZIP)</label>
                            <input class="upgrade-input" type="file" name="update_zip" accept=".zip" required style="padding:7px 10px;">

                            <div class="upgrade-row" style="margin-top:10px;">
                                <div>
                                    <label>وسم الإصدار</label>
                                    <input class="upgrade-input" type="text" name="version_tag" maxlength="80" placeholder="2026.03.04-hotfix-1">
                                </div>
                                <div>
                                    <label>النسخة المستهدفة</label>
                                    <select class="upgrade-select" name="target_edition">
                                        <option value="any">Any</option>
                                        <option value="owner">Owner</option>
                                        <option value="client">Client</option>
                                    </select>
                                </div>
                            </div>

                            <label>ملاحظات الإصدار</label>
                            <textarea class="upgrade-textarea" name="release_notes" placeholder="وصف التغييرات داخل الحزمة..."></textarea>

                            <label style="display:flex;align-items:center;gap:8px;color:#bfc7d8;margin:10px 0;">
                                <input type="checkbox" name="set_active" value="1" checked> تعيينها كحزمة نشطة تلقائياً
                            </label>

                            <div class="actions-inline">
                                <button class="upgrade-btn" type="submit"><i class="fa-solid fa-upload"></i> رفع الحزمة</button>
                            </div>
                        </form>

                        <div class="report-box" style="margin-top:12px;">
                            API endpoint: <code><?php echo app_h($updateApiPublicUrl); ?></code><br>
                            استخدم هذا المسار في الأنظمة العميلة ضمن <code>update_remote_url</code>.
                        </div>

                        <form method="post" style="margin-top:10px;">
                            <?php echo app_csrf_input(); ?>
                            <input type="hidden" name="action" value="generate_update_token">
                            <button class="subtle-btn" type="submit"><i class="fa-solid fa-key"></i> إنشاء توكن API جديد</button>
                        </form>
                    <?php else: ?>
                        <div class="report-box" style="margin-top:4px;">
                            رفع حزم التحديث متاح فقط في نسخة المالك مع صلاحية Super User.
                            <br><br>
                            النسخة الحالية: <strong><?php echo app_h($edition); ?></strong>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="upgrade-card" style="padding:14px; margin-top:14px;">
                <h4 class="upgrade-title" style="font-size:1rem;margin-bottom:8px;">الحزم المتاحة</h4>
                <div class="update-table-wrap">
                    <table class="update-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>الاسم</th>
                                <th>الإصدار</th>
                                <th>النسخة</th>
                                <th>الحالة</th>
                                <th>الحجم</th>
                                <th>المصدر</th>
                                <th>آخر تطبيق</th>
                                <th>إجراءات</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($packages)): ?>
                                <tr>
                                    <td colspan="9" style="text-align:center;color:#8d93a3;">لا توجد حزم تحديث حالياً.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($packages as $row): ?>
                                    <?php
                                        $pkgId = (int)($row['id'] ?? 0);
                                        $active = (int)($row['is_active'] ?? 0) === 1;
                                        $isApplied = (int)($row['applied_count'] ?? 0) > 0;
                                        $sourceMode = (string)($row['source_mode'] ?? 'local_upload');
                                    ?>
                                    <tr>
                                        <td><?php echo $pkgId; ?></td>
                                        <td>
                                            <?php echo app_h((string)($row['package_name'] ?? '')); ?>
                                            <div class="label-muted">رفع بواسطة: <?php echo app_h((string)($row['uploaded_by'] ?? '')); ?></div>
                                        </td>
                                        <td><?php echo app_h((string)($row['version_tag'] ?? '-')); ?></td>
                                        <td><?php echo app_h((string)($row['target_edition'] ?? 'any')); ?></td>
                                        <td>
                                            <span class="update-badge <?php echo $active ? 'active' : ''; ?>"><?php echo $active ? 'Active' : 'Inactive'; ?></span>
                                            <?php if ($isApplied): ?>
                                                <div class="label-muted">Applied x<?php echo number_format((int)($row['applied_count'] ?? 0)); ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo app_h(up_bytes((int)($row['file_size'] ?? 0))); ?></td>
                                        <td><?php echo $sourceMode === 'remote_pull' ? 'Remote' : 'Local'; ?></td>
                                        <td><?php echo app_h((string)($row['last_applied_at'] ?? '-')); ?></td>
                                        <td>
                                            <div class="actions-inline">
                                                <form method="post" style="display:inline;">
                                                    <?php echo app_csrf_input(); ?>
                                                    <input type="hidden" name="action" value="apply_update_package">
                                                    <input type="hidden" name="package_id" value="<?php echo $pkgId; ?>">
                                                    <button class="subtle-btn" type="submit">تطبيق</button>
                                                </form>
                                                <?php if ($canManageOwnerUpdates): ?>
                                                    <form method="post" style="display:inline;">
                                                        <?php echo app_csrf_input(); ?>
                                                        <input type="hidden" name="action" value="activate_update_package">
                                                        <input type="hidden" name="package_id" value="<?php echo $pkgId; ?>">
                                                        <button class="subtle-btn" type="submit">تفعيل</button>
                                                    </form>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require 'footer.php'; ?>
