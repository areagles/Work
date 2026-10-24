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
    echo "<div class='container page-shell' style='margin-top:30px;'><div class='alert alert-danger'>" . app_h(app_tr('غير مصرح لك بالدخول إلى ETA Preview.', 'You are not authorized to access ETA preview.')) . "</div></div>";
    require 'footer.php';
    exit;
}

$invoiceId = (int)($_GET['invoice_id'] ?? 0);
$isEnglish = app_current_lang($conn) === 'en';
$readiness = app_eta_einvoice_runtime_readiness($conn);
$prepared = $invoiceId > 0 ? app_eta_einvoice_prepare_invoice_payload($conn, $invoiceId) : ['ok' => false, 'error' => 'invalid_invoice_id'];

require 'header.php';
?>
<style>
    .eta-preview-shell{max-width:1440px;margin:14px auto;padding:20px}
    .eta-preview-card{background:#141414;border:1px solid #2a2a2a;border-radius:18px;padding:18px}
    .eta-preview-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px}
    .eta-preview-kv{background:#101010;border:1px solid rgba(255,255,255,.08);border-radius:14px;padding:12px}
    .eta-preview-kv .k{color:#9ca0a8;font-size:.84rem}
    .eta-preview-kv .v{color:#fff;font-weight:800;margin-top:6px}
    .eta-preview-btn{background:#d4af37;color:#000;border:none;border-radius:10px;padding:10px 14px;font-weight:700;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center}
    .eta-preview-note{color:#9ca0a8;font-size:.86rem}
    .eta-preview-msg{padding:12px 14px;border-radius:12px;font-weight:700}
    .eta-preview-msg.ok{background:rgba(46,204,113,.12);color:#9df2c2}
    .eta-preview-msg.err{background:rgba(231,76,60,.12);color:#ffb5ad}
</style>
<div class="eta-preview-shell">
    <div class="eta-preview-card" style="margin-bottom:14px;">
        <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;">
            <div>
                <h2 class="ai-title" style="margin:0;"><?php echo app_h($isEnglish ? 'ETA Payload Preview' : 'معاينة ETA Payload'); ?></h2>
                <div class="eta-preview-note"><?php echo app_h($isEnglish ? 'Validate invoice mapping before queueing or submitting to ETA.' : 'تحقق من ربط الفاتورة قبل تجهيزها أو إرسالها إلى ETA.'); ?></div>
            </div>
            <div style="display:flex;gap:10px;flex-wrap:wrap;">
                <a href="invoices.php?tab=sales" class="eta-preview-btn"><?php echo app_h($isEnglish ? 'Back to invoices' : 'العودة إلى الفواتير'); ?></a>
                <?php if ($invoiceId > 0): ?>
                    <a href="edit_invoice.php?id=<?php echo $invoiceId; ?>" class="eta-preview-btn"><?php echo app_h($isEnglish ? 'Open invoice' : 'فتح الفاتورة'); ?></a>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="eta-preview-card" style="margin-bottom:14px;">
        <div class="eta-preview-grid">
            <div class="eta-preview-kv"><div class="k"><?php echo app_h(app_tr('الفاتورة', 'Invoice')); ?></div><div class="v"><?php echo app_h((string)($prepared['invoice']['invoice_number'] ?? ('#' . $invoiceId))); ?></div></div>
            <div class="eta-preview-kv"><div class="k"><?php echo app_h(app_tr('جاهزية ETA', 'ETA readiness')); ?></div><div class="v"><?php echo app_h(!empty($readiness['ok']) ? app_tr('جاهز', 'Ready') : app_tr('ناقص', 'Not ready')); ?></div></div>
            <div class="eta-preview-kv"><div class="k"><?php echo app_h(app_tr('عدد البنود', 'Items count')); ?></div><div class="v"><?php echo (int)count((array)($prepared['items'] ?? [])); ?></div></div>
            <div class="eta-preview-kv"><div class="k"><?php echo app_h(app_tr('Payload SHA256', 'Payload SHA256')); ?></div><div class="v" style="font-size:.88rem;word-break:break-all;"><?php echo app_h((string)($prepared['payload_hash'] ?? '')); ?></div></div>
        </div>
    </div>

    <div class="eta-preview-card" style="margin-bottom:14px;">
        <h3 class="ai-title" style="margin-top:0;"><?php echo app_h($isEnglish ? 'Validation result' : 'نتيجة التحقق'); ?></h3>
        <?php if (!empty($prepared['ok'])): ?>
            <div class="eta-preview-msg ok"><?php echo app_h(app_tr('الـ payload جاهز من جهة النظام المحلي.', 'The payload is valid from the local system side.')); ?></div>
        <?php else: ?>
            <div class="eta-preview-msg err"><?php echo app_h((string)($prepared['error'] ?? app_tr('تعذر تجهيز الـ payload.', 'Could not prepare payload.'))); ?></div>
            <?php if (!empty($prepared['messages']) && is_array($prepared['messages'])): ?>
                <ul style="margin:12px 0 0 18px;">
                    <?php foreach ($prepared['messages'] as $message): ?>
                        <li><?php echo app_h((string)$message); ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <?php if (!empty($prepared['ok'])): ?>
        <div class="eta-preview-card" style="margin-bottom:14px;">
            <h3 class="ai-title" style="margin-top:0;"><?php echo app_h($isEnglish ? 'Receiver / issuer summary' : 'ملخص الممول والمستقبل'); ?></h3>
            <div class="eta-preview-grid">
                <div class="eta-preview-kv"><div class="k"><?php echo app_h(app_tr('الممول', 'Issuer')); ?></div><div class="v"><?php echo app_h((string)($prepared['payload']['issuer']['name'] ?? '')); ?></div></div>
                <div class="eta-preview-kv"><div class="k"><?php echo app_h(app_tr('RIN', 'RIN')); ?></div><div class="v"><?php echo app_h((string)($prepared['payload']['issuer']['id'] ?? '')); ?></div></div>
                <div class="eta-preview-kv"><div class="k"><?php echo app_h(app_tr('العميل', 'Receiver')); ?></div><div class="v"><?php echo app_h((string)($prepared['payload']['receiver']['name'] ?? '')); ?></div></div>
                <div class="eta-preview-kv"><div class="k"><?php echo app_h(app_tr('معرف المستقبل', 'Receiver ID')); ?></div><div class="v"><?php echo app_h((string)($prepared['payload']['receiver']['id'] ?? '')); ?></div></div>
            </div>
        </div>
        <div class="eta-preview-card">
            <h3 class="ai-title" style="margin-top:0;"><?php echo app_h($isEnglish ? 'Payload JSON' : 'Payload JSON'); ?></h3>
            <pre style="white-space:pre-wrap;overflow:auto;background:#0d0d0d;padding:14px;border-radius:14px;border:1px solid rgba(255,255,255,.08);"><?php echo app_h(json_encode($prepared['payload'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); ?></pre>
        </div>
    <?php endif; ?>
</div>
<?php require 'footer.php'; ob_end_flush(); ?>
