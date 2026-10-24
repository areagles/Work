<?php
ob_start();

require 'auth.php';
require 'config.php';
require_once __DIR__ . '/modules/tax/eta_einvoice_runtime.php';
app_handle_lang_switch($conn);

if (!app_is_work_runtime()) {
    http_response_code(404);
    exit('Not Found');
}

$canEtaPage = app_user_can_any(['invoices.view', 'invoices.update']);
if (!$canEtaPage) {
    http_response_code(403);
    require 'header.php';
    echo "<div class='container page-shell' style='margin-top:30px;'><div class='alert alert-danger'>" . app_h(app_tr('غير مصرح لك بالدخول إلى تشخيص ETA.', 'You are not authorized to access ETA diagnostics.')) . "</div></div>";
    require 'footer.php';
    exit;
}

$msg = trim((string)($_GET['msg'] ?? ''));
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    app_require_csrf();
    $action = trim((string)($_POST['action'] ?? ''));
    if ($action === 'eta_test_connection') {
        $result = app_eta_einvoice_test_connection($conn);
        $msg = !empty($result['ok']) ? 'auth_ok' : 'auth_failed';
        if (empty($result['ok'])) {
            $_SESSION['eta_diag_last_error'] = [
                'error' => (string)($result['error'] ?? 'eta_auth_failed'),
                'code' => (int)($result['code'] ?? 0),
                'body' => (string)($result['body'] ?? ''),
            ];
        } else {
            unset($_SESSION['eta_diag_last_error']);
        }
        header('Location: eta_diagnostics.php?msg=' . $msg);
        exit;
    }
}

$isEnglish = app_current_lang($conn) === 'en';
$readiness = app_eta_einvoice_runtime_readiness($conn);
$summary = app_eta_einvoice_outbox_summary($conn);
$logs = app_eta_einvoice_recent_logs($conn, 30);
$lastDiagError = $_SESSION['eta_diag_last_error'] ?? null;

require 'header.php';
?>
<style>
    .eta-diag-shell{max-width:1440px;margin:14px auto;padding:20px}
    .eta-diag-card{background:#141414;border:1px solid #2a2a2a;border-radius:18px;padding:18px}
    .eta-diag-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px}
    .eta-diag-kpi{background:#101010;border:1px solid rgba(255,255,255,.08);border-radius:16px;padding:14px}
    .eta-diag-kpi .k{color:#9ca0a8;font-size:.85rem}
    .eta-diag-kpi .v{font-size:1.5rem;font-weight:800;color:#fff;margin-top:6px}
    .eta-check-list{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:10px}
    .eta-check{background:#101010;border:1px solid rgba(255,255,255,.08);border-radius:14px;padding:12px}
    .eta-check.ok{border-color:rgba(46,204,113,.35)}
    .eta-check.bad{border-color:rgba(231,76,60,.35)}
    .eta-check .head{display:flex;justify-content:space-between;gap:8px;align-items:center;font-weight:800}
    .eta-chip{display:inline-flex;align-items:center;padding:4px 8px;border-radius:999px;font-size:.78rem}
    .eta-chip.ok{background:rgba(46,204,113,.12);color:#9df2c2}
    .eta-chip.bad{background:rgba(231,76,60,.12);color:#ffb5ad}
    .eta-note{color:#9ca0a8;font-size:.86rem}
    .eta-table-wrap{overflow:auto}
    .eta-table{width:100%;border-collapse:collapse;color:#eee}
    .eta-table th,.eta-table td{padding:12px 10px;border-bottom:1px solid rgba(255,255,255,.08);text-align:right;vertical-align:top}
    .eta-btn{background:#d4af37;color:#000;border:none;border-radius:10px;padding:10px 14px;font-weight:700;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center}
    .eta-actions{display:flex;gap:10px;flex-wrap:wrap}
    .eta-msg{margin-bottom:12px;font-weight:700}
    .eta-msg.ok{color:#9df2c2}
    .eta-msg.err{color:#ffb5ad}
</style>

<div class="eta-diag-shell">
    <div class="eta-diag-card" style="margin-bottom:14px;">
        <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;">
            <div>
                <h2 class="ai-title" style="margin:0;"><?php echo app_h($isEnglish ? 'ETA Diagnostics' : 'تشخيص ETA'); ?></h2>
                <div class="eta-note"><?php echo app_h($isEnglish ? 'Operational readiness, auth test, and ETA logs in one place.' : 'جاهزية التشغيل، اختبار التوثيق، وسجلات ETA في مكان واحد.'); ?></div>
            </div>
            <div class="eta-actions">
                <a href="master_data.php?tab=eta" class="eta-btn"><?php echo app_h($isEnglish ? 'ETA Settings' : 'إعدادات ETA'); ?></a>
                <a href="eta_outbox.php" class="eta-btn"><?php echo app_h($isEnglish ? 'ETA Outbox' : 'ETA Outbox'); ?></a>
                <form method="post" style="margin:0;">
                    <?php echo app_csrf_input(); ?>
                    <input type="hidden" name="action" value="eta_test_connection">
                    <button type="submit" class="eta-btn"><?php echo app_h($isEnglish ? 'Test ETA Auth' : 'اختبار التوثيق مع ETA'); ?></button>
                </form>
            </div>
        </div>
        <?php if ($msg === 'auth_ok'): ?>
            <div class="eta-msg ok"><?php echo app_h(app_tr('نجح اختبار التوثيق مع ETA وتم تحديث الـ access token.', 'ETA auth test succeeded and the access token was refreshed.')); ?></div>
        <?php elseif ($msg === 'auth_failed'): ?>
            <div class="eta-msg err"><?php echo app_h(app_tr('فشل اختبار التوثيق مع ETA. راجع تفاصيل الخطأ بالأسفل.', 'ETA auth test failed. Review the error details below.')); ?></div>
        <?php endif; ?>
    </div>

    <div class="eta-diag-grid" style="margin-bottom:14px;">
        <div class="eta-diag-kpi"><div class="k"><?php echo app_h(app_tr('إجمالي Outbox', 'Outbox total')); ?></div><div class="v"><?php echo (int)$summary['total']; ?></div></div>
        <div class="eta-diag-kpi"><div class="k"><?php echo app_h(app_tr('Queued', 'Queued')); ?></div><div class="v"><?php echo (int)$summary['queued']; ?></div></div>
        <div class="eta-diag-kpi"><div class="k"><?php echo app_h(app_tr('Submitted', 'Submitted')); ?></div><div class="v"><?php echo (int)$summary['submitted']; ?></div></div>
        <div class="eta-diag-kpi"><div class="k"><?php echo app_h(app_tr('Synced', 'Synced')); ?></div><div class="v"><?php echo (int)$summary['synced']; ?></div></div>
        <div class="eta-diag-kpi"><div class="k"><?php echo app_h(app_tr('Failed', 'Failed')); ?></div><div class="v"><?php echo (int)$summary['failed']; ?></div></div>
    </div>

    <div class="eta-diag-card" style="margin-bottom:14px;">
        <h3 class="ai-title" style="margin-top:0;"><?php echo app_h($isEnglish ? 'Runtime Readiness' : 'جاهزية التشغيل'); ?></h3>
        <div class="eta-check-list">
            <?php foreach ($readiness['checks'] as $check): ?>
                <div class="eta-check <?php echo !empty($check['ok']) ? 'ok' : 'bad'; ?>">
                    <div class="head">
                        <span><?php echo app_h((string)$check['label']); ?></span>
                        <span class="eta-chip <?php echo !empty($check['ok']) ? 'ok' : 'bad'; ?>"><?php echo app_h(!empty($check['ok']) ? app_tr('جاهز', 'Ready') : app_tr('ناقص', 'Missing')); ?></span>
                    </div>
                    <div class="eta-note" style="margin-top:8px;"><?php echo app_h((string)($check['details'] ?? '')); ?></div>
                </div>
            <?php endforeach; ?>
        </div>
        <?php if (!empty($lastDiagError)): ?>
            <div class="eta-diag-card" style="margin-top:12px;background:#101010;">
                <div class="ai-title" style="margin:0 0 8px 0;"><?php echo app_h($isEnglish ? 'Last auth error' : 'آخر خطأ توثيق'); ?></div>
                <div class="eta-note"><?php echo app_h('[' . (int)($lastDiagError['code'] ?? 0) . '] ' . (string)($lastDiagError['error'] ?? '')); ?></div>
                <?php if (trim((string)($lastDiagError['body'] ?? '')) !== ''): ?>
                    <pre style="white-space:pre-wrap;overflow:auto;background:#0d0d0d;padding:12px;border-radius:12px;border:1px solid rgba(255,255,255,.08);"><?php echo app_h((string)$lastDiagError['body']); ?></pre>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="eta-diag-card" style="margin-bottom:14px;">
        <h3 class="ai-title" style="margin-top:0;"><?php echo app_h($isEnglish ? 'Recent sync log' : 'آخر سجل مزامنة'); ?></h3>
        <div class="eta-table-wrap">
            <table class="eta-table">
                <thead>
                    <tr>
                        <th><?php echo app_h(app_tr('الوقت', 'Time')); ?></th>
                        <th><?php echo app_h(app_tr('الفاتورة', 'Invoice')); ?></th>
                        <th><?php echo app_h(app_tr('الحدث', 'Event')); ?></th>
                        <th><?php echo app_h(app_tr('قبل', 'Before')); ?></th>
                        <th><?php echo app_h(app_tr('بعد', 'After')); ?></th>
                        <th><?php echo app_h(app_tr('HTTP', 'HTTP')); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$logs['sync']): ?>
                        <tr><td colspan="6" class="eta-note"><?php echo app_h(app_tr('لا توجد سجلات مزامنة حتى الآن.', 'No sync log rows yet.')); ?></td></tr>
                    <?php else: foreach ($logs['sync'] as $row): ?>
                        <tr>
                            <td><?php echo app_h((string)($row['created_at'] ?? '')); ?></td>
                            <td><?php echo app_h((string)($row['invoice_number'] ?? ('#' . (int)($row['invoice_id'] ?? 0)))); ?></td>
                            <td><?php echo app_h((string)($row['event_type'] ?? '')); ?></td>
                            <td><?php echo app_h((string)($row['status_before'] ?? '')); ?></td>
                            <td><?php echo app_h((string)($row['status_after'] ?? '')); ?></td>
                            <td><?php echo app_h((string)($row['response_code'] ?? '')); ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="eta-diag-card">
        <h3 class="ai-title" style="margin-top:0;"><?php echo app_h($isEnglish ? 'Recent error log' : 'آخر سجل أخطاء'); ?></h3>
        <div class="eta-table-wrap">
            <table class="eta-table">
                <thead>
                    <tr>
                        <th><?php echo app_h(app_tr('الوقت', 'Time')); ?></th>
                        <th><?php echo app_h(app_tr('الفاتورة', 'Invoice')); ?></th>
                        <th><?php echo app_h(app_tr('المرحلة', 'Phase')); ?></th>
                        <th><?php echo app_h(app_tr('الكود', 'Code')); ?></th>
                        <th><?php echo app_h(app_tr('الرسالة', 'Message')); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$logs['errors']): ?>
                        <tr><td colspan="5" class="eta-note"><?php echo app_h(app_tr('لا توجد أخطاء ETA مسجلة حتى الآن.', 'No ETA errors logged yet.')); ?></td></tr>
                    <?php else: foreach ($logs['errors'] as $row): ?>
                        <tr>
                            <td><?php echo app_h((string)($row['created_at'] ?? '')); ?></td>
                            <td><?php echo app_h((string)($row['invoice_number'] ?? ('#' . (int)($row['invoice_id'] ?? 0)))); ?></td>
                            <td><?php echo app_h((string)($row['phase'] ?? '')); ?></td>
                            <td><?php echo app_h((string)($row['error_code'] ?? '')); ?></td>
                            <td class="eta-note"><?php echo app_h((string)($row['error_message'] ?? '')); ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require 'footer.php'; ob_end_flush(); ?>
