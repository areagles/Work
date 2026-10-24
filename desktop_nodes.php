<?php
require 'auth.php';
require 'config.php';
app_start_session();
app_handle_lang_switch($conn);

$currentRole = strtolower((string)($_SESSION['role'] ?? 'employee'));
$isAdmin = ($currentRole === 'admin');
$isSuperUser = app_is_super_user();
$isOwnerEdition = (app_license_edition() === 'owner');

if (!$isAdmin || !$isSuperUser || !$isOwnerEdition) {
    http_response_code(403);
    die(app_h(app_tr('الوصول مخصص لمسؤول النظام الأساسي فقط.', 'Access is restricted to the primary system administrator only.')));
}

if (!function_exists('dn_text')) {
    function dn_text(string $ar, string $en): string
    {
        return app_tr($ar, $en);
    }
}

if (!function_exists('dn_datetime')) {
    function dn_datetime(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '-';
        }
        $ts = strtotime($value);
        return $ts ? date('Y-m-d H:i', $ts) : $value;
    }
}

if (!function_exists('dn_status_badge')) {
    function dn_status_badge(string $status): array
    {
        $status = app_desktop_node_sanitize_status($status);
        $map = [
            'pending' => ['label' => dn_text('بانتظار التفعيل', 'Pending'), 'class' => 'pending'],
            'active' => ['label' => dn_text('نشط', 'Active'), 'class' => 'active'],
            'suspended' => ['label' => dn_text('موقوف', 'Suspended'), 'class' => 'suspended'],
            'disabled' => ['label' => dn_text('معطل', 'Disabled'), 'class' => 'disabled'],
            'revoked' => ['label' => dn_text('مسحوب', 'Revoked'), 'class' => 'revoked'],
        ];
        return $map[$status] ?? $map['pending'];
    }
}

$noticeType = '';
$noticeText = '';

$licenseClientMap = [];
if (function_exists('app_license_registry_list')) {
    foreach (app_license_registry_list($conn, 800) as $licenseRow) {
        $key = strtoupper(trim((string)($licenseRow['license_key'] ?? '')));
        if ($key === '') {
            continue;
        }
        $licenseClientMap[$key] = trim((string)($licenseRow['client_name'] ?? ''));
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    app_require_csrf();
    $action = strtolower(trim((string)($_POST['action'] ?? '')));
    if ($action === 'save_node_policy') {
        $installationCode = (string)($_POST['installation_code'] ?? '');
        $status = (string)($_POST['status'] ?? 'pending');
        $forceReadOnly = !empty($_POST['force_read_only']) ? 1 : 0;
        $forcePasswordReset = !empty($_POST['force_password_reset']) ? 1 : 0;
        $result = app_desktop_node_set_status($conn, $installationCode, $status, $forceReadOnly, $forcePasswordReset);
        if (!empty($result['ok'])) {
            $noticeType = 'ok';
            $noticeText = dn_text('تم تحديث سياسة الجهاز بنجاح.', 'Desktop node policy updated successfully.');
            app_audit_log_add($conn, 'desktop.node_policy_updated', [
                'entity_type' => 'desktop_node',
                'entity_key' => (string)$installationCode,
                'details' => [
                    'status' => $result['status'] ?? $status,
                    'force_read_only' => $forceReadOnly,
                    'force_password_reset' => $forcePasswordReset,
                ],
            ]);
        } else {
            $noticeType = 'err';
            $noticeText = dn_text('تعذر تحديث سياسة الجهاز.', 'Failed to update desktop node policy.')
                . ' [' . app_h((string)($result['error'] ?? 'unknown')) . ']';
        }
    }
}

$rows = app_desktop_nodes_list($conn, 300);
$statusFilter = app_desktop_node_sanitize_status((string)($_GET['status'] ?? 'pending'));
$applyStatusFilter = trim((string)($_GET['status'] ?? '')) !== '';
$search = strtolower(trim((string)($_GET['q'] ?? '')));

$filteredRows = [];
foreach ($rows as $row) {
    $rowStatus = app_desktop_node_sanitize_status((string)($row['status'] ?? 'pending'));
    if ($applyStatusFilter && $rowStatus !== $statusFilter) {
        continue;
    }
    if ($search !== '') {
        $hay = strtolower(
            trim((string)($row['installation_code'] ?? '')) . ' ' .
            trim((string)($row['node_name'] ?? '')) . ' ' .
            trim((string)($row['license_key'] ?? '')) . ' ' .
            trim((string)($licenseClientMap[strtoupper(trim((string)($row['license_key'] ?? '')))] ?? '')) . ' ' .
            trim((string)($row['linked_system'] ?? '')) . ' ' .
            trim((string)($row['platform'] ?? '')) . ' ' .
            trim((string)($row['app_version'] ?? ''))
        );
        if (strpos($hay, $search) === false) {
            continue;
        }
    }
    $filteredRows[] = $row;
}

$counts = ['pending' => 0, 'active' => 0, 'suspended' => 0, 'disabled' => 0, 'revoked' => 0];
foreach ($rows as $row) {
    $st = app_desktop_node_sanitize_status((string)($row['status'] ?? 'pending'));
    $counts[$st] = (int)($counts[$st] ?? 0) + 1;
}

$lang = app_current_lang($conn);
$dir = app_lang_dir($lang);
?>
<!doctype html>
<html lang="<?php echo app_h($lang); ?>" dir="<?php echo app_h($dir); ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo app_h(dn_text('أجهزة سطح المكتب', 'Desktop Nodes')); ?></title>
    <link rel="stylesheet" href="assets/css/brand.css">
    <style>
        body{background:#0b0d10;color:#f3f5f7;font-family:'Cairo',Tahoma,Arial,sans-serif}
        .wrap{max-width:1400px;margin:26px auto;padding:0 18px}
        .hero,.panel,.node{background:#15181d;border:1px solid rgba(212,175,55,.16);border-radius:22px;box-shadow:0 18px 44px rgba(0,0,0,.24)}
        .hero{padding:22px 24px;margin-bottom:18px}
        .hero h1{margin:0;color:#f1d06c;font-size:2rem}
        .hero p{margin:8px 0 0;color:#bfc7d1;line-height:1.8}
        .grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:14px;margin:18px 0}
        .stat{padding:16px;border-radius:18px;background:#101317;border:1px solid rgba(255,255,255,.05)}
        .stat b{display:block;font-size:2rem;color:#fff}
        .stat span{color:#a8b0ba}
        .panel{padding:18px 20px;margin-bottom:18px}
        .filters{display:grid;grid-template-columns:2fr 1fr auto;gap:12px}
        input[type="search"],select{min-height:46px;border-radius:14px;border:1px solid rgba(212,175,55,.22);background:#0d1116;color:#fff;padding:10px 12px}
        .btn{min-height:46px;border:0;border-radius:14px;padding:0 18px;font-weight:800;cursor:pointer}
        .btn.gold{background:#d4af37;color:#111}
        .msg{padding:12px 14px;border-radius:14px;margin-bottom:14px;font-weight:700}
        .msg.ok{background:rgba(47,160,92,.15);border:1px solid rgba(47,160,92,.4);color:#a9efc1}
        .msg.err{background:rgba(197,74,74,.15);border:1px solid rgba(197,74,74,.4);color:#ffd1d1}
        .nodes{display:grid;grid-template-columns:repeat(auto-fit,minmax(360px,1fr));gap:16px}
        .node{padding:18px}
        .top{display:flex;align-items:flex-start;justify-content:space-between;gap:10px;margin-bottom:10px}
        .code{font-size:.88rem;color:#97a0aa}
        .name{font-size:1.15rem;font-weight:800}
        .badge{display:inline-flex;align-items:center;padding:7px 12px;border-radius:999px;font-weight:800;font-size:.82rem}
        .badge.pending{background:rgba(212,175,55,.16);color:#f0cf73}
        .badge.active{background:rgba(47,160,92,.16);color:#98ebb5}
        .badge.suspended{background:rgba(210,106,44,.16);color:#ffbf99}
        .badge.disabled,.badge.revoked{background:rgba(197,74,74,.16);color:#ffc7c7}
        .meta{display:grid;grid-template-columns:1fr 1fr;gap:8px 14px;margin:12px 0 16px}
        .meta div{background:#101317;border-radius:14px;padding:10px 12px}
        .meta small{display:block;color:#9ea7b1;margin-bottom:5px}
        .meta b{color:#fff;word-break:break-word}
        .policy{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:10px}
        .checks{display:flex;flex-wrap:wrap;gap:16px;margin:12px 0}
        .checks label{display:flex;align-items:center;gap:8px;color:#d8dee6}
        .actions{display:flex;gap:10px;justify-content:flex-end}
        .empty{padding:26px;text-align:center;color:#a7afba}
    </style>
</head>
<body>
<?php require 'header.php'; ?>
<div class="wrap">
    <div class="hero">
        <h1><?php echo app_h(dn_text('مركز أجهزة سطح المكتب', 'Desktop Nodes Center')); ?></h1>
        <p><?php echo app_h(dn_text('تحكم مركزي في النسخ المكتبية المرتبطة: تفعيل، إيقاف، وضع قراءة فقط، وفرض تغيير كلمة المرور عند الحاجة.', 'Central control for linked desktop editions: activation, suspension, read-only mode, and forced password reset when needed.')); ?></p>
        <div class="grid">
            <div class="stat"><b><?php echo count($rows); ?></b><span><?php echo app_h(dn_text('إجمالي الأجهزة', 'Total Nodes')); ?></span></div>
            <div class="stat"><b><?php echo (int)$counts['active']; ?></b><span><?php echo app_h(dn_text('نشطة', 'Active')); ?></span></div>
            <div class="stat"><b><?php echo (int)$counts['pending']; ?></b><span><?php echo app_h(dn_text('بانتظار التفعيل', 'Pending')); ?></span></div>
            <div class="stat"><b><?php echo (int)$counts['suspended']; ?></b><span><?php echo app_h(dn_text('موقوفة', 'Suspended')); ?></span></div>
            <div class="stat"><b><?php echo (int)$counts['disabled'] + (int)$counts['revoked']; ?></b><span><?php echo app_h(dn_text('معطلة/مسحوبة', 'Disabled/Revoked')); ?></span></div>
        </div>
    </div>

    <?php if ($noticeText !== ''): ?>
        <div class="msg <?php echo $noticeType === 'ok' ? 'ok' : 'err'; ?>"><?php echo app_h($noticeText); ?></div>
    <?php endif; ?>

    <div class="panel">
        <form method="get" class="filters">
            <input type="search" name="q" value="<?php echo app_h((string)($_GET['q'] ?? '')); ?>" placeholder="<?php echo app_h(dn_text('ابحث باسم الجهاز أو الكود أو النظام المرتبط', 'Search by node name, code, or linked system')); ?>">
            <select name="status">
                <option value=""><?php echo app_h(dn_text('كل الحالات', 'All statuses')); ?></option>
                <?php foreach (app_desktop_node_allowed_statuses() as $status): ?>
                    <option value="<?php echo app_h($status); ?>" <?php echo ((string)($_GET['status'] ?? '') === $status) ? 'selected' : ''; ?>><?php echo app_h(dn_status_badge($status)['label']); ?></option>
                <?php endforeach; ?>
            </select>
            <button class="btn gold" type="submit"><?php echo app_h(dn_text('تصفية', 'Filter')); ?></button>
        </form>
    </div>

    <div class="nodes">
        <?php if (empty($filteredRows)): ?>
            <div class="panel empty"><?php echo app_h(dn_text('لا توجد أجهزة مطابقة للبحث الحالي.', 'No desktop nodes match the current filter.')); ?></div>
        <?php endif; ?>
        <?php foreach ($filteredRows as $row): ?>
            <?php $badge = dn_status_badge((string)($row['status'] ?? 'pending')); ?>
            <?php
                $rowLicenseKey = strtoupper(trim((string)($row['license_key'] ?? '')));
                $rowClientName = trim((string)($licenseClientMap[$rowLicenseKey] ?? ''));
            ?>
            <form class="node" method="post">
                <?php echo app_csrf_input(); ?>
                <input type="hidden" name="action" value="save_node_policy">
                <input type="hidden" name="installation_code" value="<?php echo app_h((string)($row['installation_code'] ?? '')); ?>">
                <div class="top">
                    <div>
                        <div class="name"><?php echo app_h(trim((string)($row['node_name'] ?? '')) !== '' ? (string)$row['node_name'] : dn_text('جهاز بدون اسم', 'Unnamed Node')); ?></div>
                        <div class="code"><?php echo app_h((string)($row['installation_code'] ?? '')); ?></div>
                    </div>
                    <span class="badge <?php echo app_h($badge['class']); ?>"><?php echo app_h($badge['label']); ?></span>
                </div>
                <div class="meta">
                    <div><small><?php echo app_h(dn_text('مفتاح الترخيص', 'License Key')); ?></small><b><?php echo app_h($rowLicenseKey !== '' ? $rowLicenseKey : '-'); ?></b></div>
                    <div><small><?php echo app_h(dn_text('العميل المرتبط', 'Linked Client')); ?></small><b><?php echo app_h($rowClientName !== '' ? $rowClientName : '-'); ?></b></div>
                    <div><small><?php echo app_h(dn_text('النظام المرتبط', 'Linked System')); ?></small><b><?php echo app_h((string)($row['linked_system'] ?? '-') ?: '-'); ?></b></div>
                    <div><small><?php echo app_h(dn_text('الإصدار', 'Edition')); ?></small><b><?php echo app_h((string)($row['edition'] ?? '-')); ?></b></div>
                    <div><small><?php echo app_h(dn_text('المنصة', 'Platform')); ?></small><b><?php echo app_h((string)($row['platform'] ?? '-') ?: '-'); ?></b></div>
                    <div><small><?php echo app_h(dn_text('نسخة التطبيق', 'App Version')); ?></small><b><?php echo app_h((string)($row['app_version'] ?? '-') ?: '-'); ?></b></div>
                    <div><small><?php echo app_h(dn_text('آخر نبضة', 'Last Heartbeat')); ?></small><b><?php echo app_h(dn_datetime((string)($row['last_heartbeat_at'] ?? ''))); ?></b></div>
                    <div><small><?php echo app_h(dn_text('آخر مزامنة', 'Last Sync')); ?></small><b><?php echo app_h(dn_datetime((string)($row['last_sync_at'] ?? ''))); ?></b></div>
                    <div><small><?php echo app_h(dn_text('آخر تحديث سياسة', 'Last Policy Update')); ?></small><b><?php echo app_h(dn_datetime((string)($row['updated_at'] ?? ''))); ?></b></div>
                    <div><small><?php echo app_h(dn_text('قاعدة البيانات المحلية', 'Local DB Label')); ?></small><b><?php echo app_h((string)($row['db_label'] ?? '-') ?: '-'); ?></b></div>
                </div>
                <div class="policy">
                    <div>
                        <small><?php echo app_h(dn_text('حالة الجهاز', 'Node Status')); ?></small>
                        <select name="status">
                            <?php foreach (app_desktop_node_allowed_statuses() as $status): ?>
                                <option value="<?php echo app_h($status); ?>" <?php echo (app_desktop_node_sanitize_status((string)($row['status'] ?? 'pending')) === $status) ? 'selected' : ''; ?>><?php echo app_h(dn_status_badge($status)['label']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="checks">
                    <label><input type="checkbox" name="force_read_only" value="1" <?php echo !empty($row['force_read_only']) ? 'checked' : ''; ?>> <?php echo app_h(dn_text('تشغيل بوضع قراءة فقط', 'Force read-only mode')); ?></label>
                    <label><input type="checkbox" name="force_password_reset" value="1" <?php echo !empty($row['force_password_reset']) ? 'checked' : ''; ?>> <?php echo app_h(dn_text('فرض تغيير كلمة المرور', 'Force password reset')); ?></label>
                </div>
                <div class="actions">
                    <button class="btn gold" type="submit"><?php echo app_h(dn_text('حفظ السياسة', 'Save Policy')); ?></button>
                </div>
            </form>
        <?php endforeach; ?>
    </div>
</div>
</body>
</html>
