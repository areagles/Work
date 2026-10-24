<?php
require 'auth.php';
require 'config.php';
app_start_session();
app_handle_lang_switch($conn);

if ((string)($_SESSION['role'] ?? '') !== 'admin') {
    require 'header.php';
    echo "<div class='container' style='margin-top:30px;'><div style='background:#1d0f0f;border:1px solid #5b2020;border-radius:12px;padding:20px;color:#ffb3b3;'>⛔ "
        . app_h(app_tr('عذراً، الوصول مخصص للمدير فقط.', 'Sorry, access is restricted to administrators.'))
        . "</div></div>";
    require 'footer.php';
    exit;
}

if (app_license_edition() === 'owner') {
    app_safe_redirect('license_center.php');
}

if (!function_exists('ss_mask')) {
    function ss_mask(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '-';
        }
        $len = strlen($value);
        if ($len <= 10) {
            return str_repeat('*', $len);
        }
        return substr($value, 0, 6) . str_repeat('*', max(4, $len - 10)) . substr($value, -4);
    }
}

if (!function_exists('ss_dt')) {
    function ss_dt(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '-';
        }
        $ts = strtotime($value);
        return $ts === false ? $value : date('Y-m-d H:i', $ts);
    }
}

$noticeType = '';
$noticeText = '';
$row = app_license_row($conn);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    app_require_csrf();
    $action = strtolower(trim((string)($_POST['action'] ?? '')));

    try {
        if ($action === 'sync_now') {
            $sync = app_license_edition() === 'client'
                ? app_license_client_self_heal_connection($conn, true)
                : app_license_sync_remote($conn, true);
            if (!empty($sync['ok']) || !empty($sync['skipped'])) {
                $noticeType = 'ok';
                $noticeText = app_license_edition() === 'client'
                    ? app_tr('تم فحص الاتصال بانتظار اعتماد نظام المالك.', 'Connection check completed. Waiting for owner approval.')
                    : app_tr('تم تحديث حالة الاشتراك بنجاح.', 'Subscription status refreshed successfully.');
            } else {
                throw new RuntimeException(app_tr('فشل تحديث حالة الاشتراك: ', 'Failed to refresh subscription status: ') . (string)($sync['reason'] ?? $sync['error'] ?? 'unknown'));
            }
        }
    } catch (Throwable $e) {
        $noticeType = 'err';
        $noticeText = $e->getMessage();
    }
}

$row = app_license_row($conn);
if (app_license_edition() === 'client') {
    $remoteUrlProbe = trim((string)($row['remote_url'] ?? app_env('APP_LICENSE_REMOTE_URL', '')));
    $remoteTokenProbe = trim((string)($row['remote_token'] ?? app_env('APP_LICENSE_REMOTE_TOKEN', '')));
    $licenseKeyProbe = strtoupper(trim((string)($row['license_key'] ?? '')));
    $lastSuccessProbe = trim((string)($row['last_success_at'] ?? ''));
    if ($remoteUrlProbe !== '' && $remoteTokenProbe !== '' && ($licenseKeyProbe === '' || $lastSuccessProbe === '')) {
        app_license_client_self_heal_connection($conn, false);
    }
}

$status = app_license_status($conn, false);
$row = app_license_row($conn);
$isEnglish = app_current_lang($conn) === 'en';
$edition = strtolower((string)($status['edition'] ?? app_license_edition()));
$plan = strtolower((string)($status['plan'] ?? ($row['plan_type'] ?? 'trial')));
$licenseStatus = strtolower((string)($status['status'] ?? ($row['license_status'] ?? 'suspended')));

$planText = $plan === 'subscription'
    ? app_tr('اشتراك', 'Subscription')
    : ($plan === 'lifetime' ? app_tr('بيع نهائي', 'Lifetime') : app_tr('تجريبي', 'Trial'));
$statusText = $licenseStatus === 'active'
    ? app_tr('نشط', 'Active')
    : ($licenseStatus === 'expired' ? app_tr('منتهي', 'Expired') : app_tr('موقوف', 'Suspended'));
$editionText = $edition === 'owner' ? app_tr('مالك', 'Owner') : app_tr('عميل', 'Client');
$opsStateText = !empty($status['allowed'])
    ? app_tr('الاتصال سليم، والتفعيل يتم بعد اعتماد المالك.', 'Connection is healthy, and activation happens after owner approval.')
    : app_tr('النظام ينتظر تحديث الربط أو اعتماد المالك.', 'System is waiting for owner sync or activation.');
$opsLastError = trim((string)($status['last_error'] ?? ''));
$opsHealthClass = !empty($status['allowed']) ? 'ok' : ($opsLastError !== '' ? 'bad' : '');
$syncModeText = $edition === 'client'
    ? app_tr('ربط سحابي ذاتي مع نقطة مالك ثابتة', 'Self-cloud link with fixed owner endpoint')
    : app_tr('نقطة المالك المركزية', 'Central owner endpoint');
$opsTimeline = [
    ['label' => app_tr('آخر فحص', 'Last Check'), 'value' => ss_dt((string)($status['last_check_at'] ?? '')), 'tone' => ''],
    ['label' => app_tr('آخر نجاح', 'Last Success'), 'value' => ss_dt((string)($status['last_success_at'] ?? '')), 'tone' => 'ok'],
    ['label' => app_tr('الاستحقاق', 'Expiry'), 'value' => ss_dt((string)($status['expires_at'] ?? '')), 'tone' => ''],
    ['label' => app_tr('آخر تنبيه', 'Last Issue'), 'value' => $opsLastError !== '' ? $opsLastError : app_tr('لا يوجد', 'None'), 'tone' => $opsLastError !== '' ? 'bad' : 'ok'],
];

require 'header.php';
?>
<style>
    .ss-wrap{max-width:1200px;margin:16px auto 40px;padding:0 12px}
    .ss-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin-bottom:14px}
    .ss-card,.ss-panel{background:linear-gradient(165deg,#11131a,#0f1016);border:1px solid #2d3140;border-radius:15px;box-shadow:0 10px 26px rgba(0,0,0,.28)}
    .ss-card{padding:14px}
    .ss-k{color:#97a1b4;font-size:.82rem}
    .ss-v{margin-top:6px;font-size:1.35rem;font-weight:900;color:#f3f5fb}
    .ss-v.ok{color:#83e7a7}.ss-v.bad{color:#ffb2b2}
    .ss-panel{padding:16px}
    .ss-head{display:flex;justify-content:space-between;gap:10px;align-items:flex-start;flex-wrap:wrap;margin-bottom:12px}
    .ss-title{margin:0;color:#d4af37;font-size:1.2rem;font-weight:900}
    .ss-sub{margin:4px 0 0;color:#9ea8bb}
    .ss-actions{display:flex;gap:8px;flex-wrap:wrap}
    .ss-btn{border:1px solid transparent;border-radius:11px;padding:10px 14px;font-weight:800;cursor:pointer;font-family:inherit}
    .ss-btn.gold{background:#d4af37;color:#111;border-color:rgba(212,175,55,.75)}
    .ss-btn.dark{background:#232733;color:#f3f5fb;border-color:#3d4355}
    .ss-notice{border-radius:11px;padding:10px 12px;margin-bottom:12px;border:1px solid transparent}
    .ss-notice.ok{background:rgba(46,178,93,.16);border-color:rgba(46,178,93,.4);color:#9df0bc}
    .ss-notice.err{background:rgba(200,70,70,.2);border-color:rgba(200,70,70,.45);color:#ffc1c1}
    .ss-table{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}
    .ss-field{padding:12px;border:1px solid #2d3140;border-radius:12px;background:#0c1018}
    .ss-field label{display:block;color:#98a0af;font-size:.8rem;margin-bottom:5px}
    .ss-field .val{color:#f3f5fb;font-weight:800;word-break:break-word}
    .ss-ops{display:grid;grid-template-columns:1.2fr .8fr;gap:14px;margin-bottom:14px}
    .ss-room{padding:18px;background:
        radial-gradient(circle at top right, rgba(212,175,55,.14), transparent 28%),
        linear-gradient(165deg,#10131b,#090d14)}
    .ss-room-head{display:flex;justify-content:space-between;gap:12px;align-items:flex-start;flex-wrap:wrap;margin-bottom:14px}
    .ss-chip{display:inline-flex;align-items:center;gap:8px;padding:8px 12px;border-radius:999px;background:#151b27;border:1px solid #30384a;color:#dbe3f3;font-weight:800}
    .ss-chip.ok{border-color:rgba(46,178,93,.45);color:#9df0bc}
    .ss-chip.bad{border-color:rgba(200,70,70,.45);color:#ffc1c1}
    .ss-timeline{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}
    .ss-step{padding:12px;border-radius:12px;border:1px solid #273042;background:#0b111a}
    .ss-step .k{display:block;color:#8f9bb3;font-size:.78rem;margin-bottom:6px}
    .ss-step .v{font-size:.96rem;font-weight:800;color:#eef2fb;word-break:break-word}
    .ss-step.ok .v{color:#9df0bc}.ss-step.bad .v{color:#ffc1c1}
    .ss-matrix{display:grid;grid-template-columns:1fr;gap:10px}
    .ss-pulse{padding:13px;border-radius:13px;border:1px solid #2b3344;background:#0b1018}
    .ss-pulse .head{color:#98a0af;font-size:.8rem;margin-bottom:6px}
    .ss-pulse .body{color:#f3f5fb;font-weight:900}
    @media(max-width:920px){.ss-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.ss-table{grid-template-columns:1fr}}
    @media(max-width:920px){.ss-ops{grid-template-columns:1fr}.ss-timeline{grid-template-columns:1fr}}
    @media(max-width:640px){.ss-grid{grid-template-columns:1fr}.ss-wrap{padding:0 8px}}
</style>

<div class="container ss-wrap">
    <?php if ($noticeText !== ''): ?>
        <div class="ss-notice <?php echo $noticeType === 'ok' ? 'ok' : 'err'; ?>"><?php echo app_h($noticeText); ?></div>
    <?php endif; ?>

    <div class="ss-head">
        <div>
            <h2 class="ss-title"><?php echo app_h(app_tr('حالة النظام', 'System Status')); ?></h2>
            <p class="ss-sub"><?php echo app_h(app_tr('غرفة تشغيل لحالة الاتصال والاعتماد والمزامنة مع النظام المركزي.', 'Operations room for connection, activation and sync with the central system.')); ?></p>
        </div>
        <div class="ss-actions">
            <form method="post" style="margin:0;">
                <?php echo app_csrf_input(); ?>
                <input type="hidden" name="action" value="sync_now">
                <button class="ss-btn gold" type="submit"><?php echo app_h(app_tr('تشغيل فحص الاتصال الآن', 'Run connection check now')); ?></button>
            </form>
        </div>
    </div>

    <div class="ss-ops">
        <div class="ss-panel ss-room">
            <div class="ss-room-head">
                <div>
                    <div class="ss-chip <?php echo $opsHealthClass; ?>"><?php echo app_h($opsStateText); ?></div>
                    <div class="ss-sub" style="margin-top:10px;"><?php echo app_h($syncModeText); ?></div>
                </div>
                <div class="ss-chip"><?php echo app_h(app_tr('المسار النشط', 'Active Route')); ?>: <?php echo app_h((string)($status['remote_url'] ?? '-')); ?></div>
            </div>
            <div class="ss-timeline">
                <?php foreach ($opsTimeline as $step): ?>
                    <div class="ss-step <?php echo app_h($step['tone']); ?>">
                        <span class="k"><?php echo app_h($step['label']); ?></span>
                        <div class="v"><?php echo app_h($step['value']); ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="ss-matrix">
            <div class="ss-pulse">
                <div class="head"><?php echo app_h(app_tr('هوية التنصيب', 'Installation Identity')); ?></div>
                <div class="body"><?php echo app_h(ss_mask((string)($status['installation_id'] ?? ''))); ?></div>
            </div>
            <div class="ss-pulse">
                <div class="head"><?php echo app_h(app_tr('حالة الترخيص', 'License State')); ?></div>
                <div class="body"><?php echo app_h($statusText); ?></div>
            </div>
            <div class="ss-pulse">
                <div class="head"><?php echo app_h(app_tr('الخطة الفعالة', 'Effective Plan')); ?></div>
                <div class="body"><?php echo app_h($planText); ?></div>
            </div>
        </div>
    </div>

    <div class="ss-grid">
        <div class="ss-card"><div class="ss-k"><?php echo app_h(app_tr('نوع النظام', 'Edition')); ?></div><div class="ss-v"><?php echo app_h($editionText); ?></div></div>
        <div class="ss-card"><div class="ss-k"><?php echo app_h(app_tr('الخطة', 'Plan')); ?></div><div class="ss-v"><?php echo app_h($planText); ?></div></div>
        <div class="ss-card"><div class="ss-k"><?php echo app_h(app_tr('حالة الاشتراك', 'Subscription Status')); ?></div><div class="ss-v <?php echo !empty($status['allowed']) ? 'ok' : 'bad'; ?>"><?php echo app_h($statusText); ?></div></div>
        <div class="ss-card"><div class="ss-k"><?php echo app_h(app_tr('الأيام المتبقية', 'Days Left')); ?></div><div class="ss-v"><?php echo isset($status['days_left']) && $status['days_left'] !== null ? (int)$status['days_left'] : '-'; ?></div></div>
    </div>

    <div class="ss-panel">
        <div class="ss-table">
            <div class="ss-field"><label><?php echo app_h(app_tr('اسم العميل/المالك', 'Owner / Client Name')); ?></label><div class="val"><?php echo app_h((string)($status['owner_name'] ?? '-')); ?></div></div>
            <div class="ss-field"><label><?php echo app_h(app_tr('الانتهاء', 'Expiry')); ?></label><div class="val"><?php echo app_h(ss_dt((string)($status['expires_at'] ?? ''))); ?></div></div>
            <div class="ss-field"><label><?php echo app_h(app_tr('مفتاح الترخيص', 'License Key')); ?></label><div class="val"><?php echo app_h(ss_mask((string)($status['license_key'] ?? ''))); ?></div></div>
            <div class="ss-field"><label><?php echo app_h(app_tr('رابط المالك/API', 'Owner API URL')); ?></label><div class="val"><?php echo app_h((string)($status['remote_url'] ?? '-')); ?></div></div>
            <div class="ss-field"><label><?php echo app_h(app_tr('آخر فحص', 'Last Check')); ?></label><div class="val"><?php echo app_h(ss_dt((string)($status['last_check_at'] ?? ''))); ?></div></div>
            <div class="ss-field"><label><?php echo app_h(app_tr('آخر نجاح', 'Last Success')); ?></label><div class="val"><?php echo app_h(ss_dt((string)($status['last_success_at'] ?? ''))); ?></div></div>
            <div class="ss-field"><label><?php echo app_h(app_tr('هوية التثبيت', 'Installation ID')); ?></label><div class="val"><?php echo app_h(ss_mask((string)($status['installation_id'] ?? ''))); ?></div></div>
            <div class="ss-field"><label><?php echo app_h(app_tr('آخر خطأ', 'Last Error')); ?></label><div class="val"><?php echo app_h((string)($status['last_error'] ?? '-') ?: '-'); ?></div></div>
        </div>
    </div>
</div>

<?php require 'footer.php'; ?>
