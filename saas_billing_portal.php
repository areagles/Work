<?php
require 'config.php';
require_once __DIR__ . '/modules/saas/billing_portal_runtime.php';

app_start_session();
app_handle_lang_switch($conn);

$isEnglish = app_current_lang($conn) === 'en';
$controlDbConfig = app_saas_control_db_config([
    'host' => app_env('DB_HOST', 'localhost'),
    'user' => app_env('DB_USER', ''),
    'pass' => app_env('DB_PASS', ''),
    'name' => app_env('DB_NAME', ''),
    'port' => (int)app_env('DB_PORT', '3306'),
    'socket' => app_env('DB_SOCKET', ''),
]);
$controlConn = app_saas_open_control_connection($controlDbConfig);
app_saas_ensure_control_plane_schema($controlConn);

$portalState = saas_billing_portal_prepare($controlConn, $isEnglish);
if (!empty($portalState['error_html'])) {
    http_response_code((int)($portalState['http_code'] ?? 404));
    die((string)$portalState['error_html']);
}
extract($portalState, EXTR_OVERWRITE);
$portalBackHref = $tenantPortalUrl !== '' ? $tenantPortalUrl : 'javascript:history.back()';
$portalBackLabel = app_tr('رجوع', 'Back');
?>
<!doctype html>
<html lang="<?php echo $isEnglish ? 'en' : 'ar'; ?>" dir="<?php echo $isEnglish ? 'ltr' : 'rtl'; ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?php echo app_h(app_tr('بوابة سداد الاشتراك', 'Subscription Billing Portal')); ?></title>
    <style>
        body{margin:0;background:radial-gradient(circle at top,#1a1a1a 0%,#090909 55%,#040404 100%);color:#f5f5f5;font-family:Cairo,Tahoma,Arial,sans-serif}
        .wrap{max-width:920px;margin:0 auto;padding:28px 18px 60px}
        .card{background:linear-gradient(180deg,rgba(255,255,255,.05),rgba(255,255,255,.02)),rgba(18,18,18,.86);border:1px solid rgba(255,255,255,.08);border-radius:24px;padding:24px;box-shadow:0 18px 40px rgba(0,0,0,.28)}
        .top-actions{display:flex;justify-content:flex-start;margin-bottom:14px}
        .top-back{display:inline-flex;align-items:center;gap:8px;text-decoration:none;padding:10px 16px;border-radius:999px;background:rgba(255,255,255,.04);border:1px solid rgba(212,175,55,.28);color:#f0d684;font-weight:800}
        .hero{display:grid;gap:14px;margin-bottom:18px}
        .hero h1{margin:0;color:#f0d684;font-size:2rem}
        .hero p{margin:0;color:#b9bec7;line-height:1.9}
        .grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px;margin-top:16px}
        .kpi-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin-top:16px}
        .item{padding:14px 16px;border-radius:18px;background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.06)}
        .item .k{display:block;color:#8e97a3;font-size:.82rem;margin-bottom:6px}
        .item .v{display:block;color:#fff;font-weight:800;word-break:break-word}
        .item.kpi .v{font-size:1.25rem}
        .badge{display:inline-flex;align-items:center;justify-content:center;padding:8px 14px;border-radius:999px;font-weight:800;font-size:.82rem}
        .badge.paid{background:rgba(46,204,113,.14);border:1px solid rgba(46,204,113,.3);color:#9cebba}
        .badge.issued{background:rgba(52,152,219,.14);border:1px solid rgba(52,152,219,.3);color:#a8d7ff}
        .badge.cancelled{background:rgba(127,140,141,.14);border:1px solid rgba(127,140,141,.3);color:#d0d6d7}
        .section{margin-top:18px}
        .section h2{margin:0 0 10px;color:#f0d684;font-size:1.12rem}
        .section p{margin:0;color:#b9bec7;line-height:1.9}
        .section-grid{display:grid;grid-template-columns:1.05fr .95fr;gap:16px;margin-top:18px}
        .actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:16px}
        .btn{display:inline-flex;align-items:center;justify-content:center;text-decoration:none;border:0;border-radius:14px;padding:12px 16px;font-weight:800;font-family:Cairo,Tahoma,Arial,sans-serif;cursor:pointer}
        .btn.primary{background:linear-gradient(135deg,#d4af37,#b8860b);color:#111}
        .btn.dark{background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.1);color:#fff}
        .note,.flash{padding:14px 16px;border-radius:16px;margin-top:16px}
        .note{background:rgba(255,255,255,.03);border:1px dashed rgba(255,255,255,.08);color:#b8bec7;line-height:1.9}
        .flash.success{background:rgba(46,204,113,.12);border:1px solid rgba(46,204,113,.28);color:#a8edc0}
        .flash.error{background:rgba(231,76,60,.12);border:1px solid rgba(231,76,60,.28);color:#ffb8af}
        .list{display:grid;gap:10px}
        .list-row{padding:14px 16px;border-radius:18px;background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.06);display:grid;gap:6px}
        .list-row .top{display:flex;justify-content:space-between;gap:10px;align-items:flex-start}
        .list-row strong{color:#fff}
        .list-row small{display:block;color:#96a0ab;line-height:1.7}
        form.portal-form{display:grid;gap:10px;margin-top:16px}
        form.portal-form input,form.portal-form textarea{width:100%;box-sizing:border-box;border:1px solid rgba(255,255,255,.1);background:rgba(8,8,8,.84);color:#fff;border-radius:14px;padding:13px 14px;font-family:Cairo,Tahoma,Arial,sans-serif}
        form.portal-form textarea{min-height:110px;resize:vertical}
        @media (max-width:760px){.grid,.section-grid,.kpi-grid{grid-template-columns:1fr}.actions{flex-direction:column}}
    </style>
</head>
<body>
    <div class="wrap">
        <div class="card">
            <div class="top-actions">
                <a href="<?php echo app_h($portalBackHref); ?>" class="top-back">
                    <span aria-hidden="true">←</span>
                    <span><?php echo app_h($portalBackLabel); ?></span>
                </a>
            </div>
            <div class="hero">
                <span class="badge <?php echo app_h($invoiceStatus); ?>"><?php echo app_h(app_status_label((string)($invoice['status'] ?? 'issued'))); ?></span>
                <h1><?php echo app_h(app_tr('بوابة سداد الاشتراك', 'Subscription Billing Portal')); ?></h1>
                <p><?php echo app_h(app_tr('هذه الصفحة مخصصة لمتابعة فاتورة اشتراك SaaS الحالية، وفتح رابط السداد أو إرسال إشعار الدفع لفريق المتابعة.', 'This page is for reviewing the current SaaS subscription invoice, opening the payment link, or sending a payment notice to the billing team.')); ?></p>
            </div>

            <?php if ($flashMessage !== ''): ?>
                <div class="flash <?php echo app_h($flashType); ?>"><?php echo app_h($flashMessage); ?></div>
            <?php endif; ?>

            <div class="grid">
                <div class="item"><span class="k"><?php echo app_h(app_tr('المستأجر', 'Tenant')); ?></span><span class="v"><?php echo app_h((string)($invoice['tenant_name'] ?? $invoice['tenant_slug'] ?? 'Tenant')); ?></span></div>
                <div class="item"><span class="k"><?php echo app_h(app_tr('رقم الفاتورة', 'Invoice number')); ?></span><span class="v"><?php echo app_h((string)($invoice['invoice_number'] ?? 'SINV')); ?></span></div>
                <div class="item"><span class="k"><?php echo app_h(app_tr('المبلغ', 'Amount')); ?></span><span class="v"><?php echo app_h((string)($invoice['currency_code'] ?? 'EGP')); ?> <?php echo app_h(number_format((float)($invoice['amount'] ?? 0), 2)); ?></span></div>
                <div class="item"><span class="k"><?php echo app_h(app_tr('تاريخ الاستحقاق', 'Due date')); ?></span><span class="v"><?php echo app_h((string)($invoice['due_date'] ?? '-')); ?></span></div>
                <div class="item"><span class="k"><?php echo app_h(app_tr('الدورة', 'Cycle')); ?></span><span class="v"><?php echo app_h((string)($invoice['billing_cycle'] ?? '-')); ?></span></div>
                <div class="item"><span class="k"><?php echo app_h(app_tr('الباقة', 'Plan')); ?></span><span class="v"><?php echo app_h((string)($invoice['plan_code'] ?? '-')); ?></span></div>
            </div>

            <div class="kpi-grid">
                <div class="item kpi"><span class="k"><?php echo app_h(app_tr('إجمالي الفواتير', 'Total invoices')); ?></span><span class="v"><?php echo app_h((string)$invoiceSummary['total']); ?></span></div>
                <div class="item kpi"><span class="k"><?php echo app_h(app_tr('فواتير مفتوحة', 'Open invoices')); ?></span><span class="v"><?php echo app_h((string)$invoiceSummary['issued']); ?></span></div>
                <div class="item kpi"><span class="k"><?php echo app_h(app_tr('فواتير مسددة', 'Paid invoices')); ?></span><span class="v"><?php echo app_h((string)$invoiceSummary['paid']); ?></span></div>
                <div class="item kpi"><span class="k"><?php echo app_h(app_tr('الرصيد المفتوح', 'Outstanding balance')); ?></span><span class="v"><?php echo app_h((string)($invoice['currency_code'] ?? 'EGP')); ?> <?php echo app_h(number_format((float)$invoiceSummary['outstanding_amount'], 2)); ?></span></div>
            </div>

            <div class="section-grid">
                <div class="section">
                    <h2><?php echo app_h(app_tr('حالة الاشتراك', 'Subscription status')); ?></h2>
                    <div class="list">
                        <div class="list-row">
                            <div class="top">
                                <strong><?php echo app_h((string)($invoice['tenant_name'] ?? $invoice['tenant_slug'] ?? 'Tenant')); ?></strong>
                                <span class="badge <?php echo app_h(strtolower(trim((string)($currentSubscription['status'] ?? 'issued')))); ?>">
                                    <?php echo app_h(app_status_label((string)($currentSubscription['status'] ?? 'issued'))); ?>
                                </span>
                            </div>
                            <small><?php echo app_h(app_tr('الخطة', 'Plan')); ?>: <?php echo app_h((string)($currentSubscription['plan_code'] ?? $invoice['plan_code'] ?? '-')); ?></small>
                            <small><?php echo app_h(app_tr('الدورة', 'Cycle')); ?>: <?php echo app_h((string)($currentSubscription['billing_cycle'] ?? $invoice['billing_cycle'] ?? '-')); ?></small>
                            <small><?php echo app_h(app_tr('التجديد', 'Renews at')); ?>: <?php echo app_h((string)($currentSubscription['renews_at'] ?? '-')); ?></small>
                            <small><?php echo app_h(app_tr('النهاية', 'Ends at')); ?>: <?php echo app_h((string)($currentSubscription['ends_at'] ?? '-')); ?></small>
                        </div>
                    </div>
                </div>

                <div class="section">
                    <h2><?php echo app_h(app_tr('آخر المدفوعات', 'Latest payments')); ?></h2>
                    <div class="list">
                        <?php if (empty($tenantPayments)): ?>
                            <div class="list-row"><small><?php echo app_h(app_tr('لا توجد حركات سداد حتى الآن.', 'No payment records yet.')); ?></small></div>
                        <?php else: ?>
                            <?php foreach (array_slice($tenantPayments, 0, 4) as $paymentRow): ?>
                                <div class="list-row">
                                    <div class="top">
                                        <strong><?php echo app_h((string)($paymentRow['currency_code'] ?? 'EGP')); ?> <?php echo app_h(number_format((float)($paymentRow['amount'] ?? 0), 2)); ?></strong>
                                        <span class="badge <?php echo app_h(strtolower(trim((string)($paymentRow['status'] ?? 'posted')))); ?>"><?php echo app_h((string)($paymentRow['status'] ?? 'posted')); ?></span>
                                    </div>
                                    <small><?php echo app_h(app_tr('الطريقة', 'Method')); ?>: <?php echo app_h(function_exists('saas_payment_method_label') ? saas_payment_method_label((string)($paymentRow['payment_method'] ?? 'manual'), $isEnglish) : (string)($paymentRow['payment_method'] ?? 'manual')); ?></small>
                                    <small><?php echo app_h(app_tr('المرجع', 'Reference')); ?>: <?php echo app_h((string)($paymentRow['payment_ref'] ?? '-')); ?></small>
                                    <small><?php echo app_h(app_tr('التاريخ', 'Date')); ?>: <?php echo app_h((string)($paymentRow['paid_at'] ?? '-')); ?></small>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="section">
                <h2><?php echo app_h(app_tr('آخر إشعار سداد', 'Latest payment notice')); ?></h2>
                <div class="list">
                    <?php if (!$latestPaymentNotice): ?>
                        <div class="list-row"><small><?php echo app_h(app_tr('لا يوجد إشعار سداد مسجل حتى الآن.', 'No payment notice has been submitted yet.')); ?></small></div>
                    <?php else: ?>
                        <?php $noticeContext = json_decode((string)($latestPaymentNotice['context_json'] ?? ''), true); ?>
                        <div class="list-row">
                            <div class="top">
                                <strong><?php echo app_h((string)($noticeContext['payment_ref'] ?? app_tr('بدون مرجع', 'No reference'))); ?></strong>
                                <span class="badge issued"><?php echo app_h(app_tr('تم الإرسال', 'Submitted')); ?></span>
                            </div>
                            <small><?php echo app_h(app_tr('اسم الدافع', 'Payer')); ?>: <?php echo app_h((string)($noticeContext['payer_name'] ?? $latestPaymentNotice['actor_name'] ?? '-')); ?></small>
                            <small><?php echo app_h(app_tr('وقت الإرسال', 'Submitted at')); ?>: <?php echo app_h((string)($latestPaymentNotice['created_at'] ?? '-')); ?></small>
                            <small><?php echo app_h(app_tr('ملاحظات', 'Notes')); ?>: <?php echo app_h((string)($noticeContext['notice'] ?? '-')); ?></small>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="section">
                <h2><?php echo app_h($gatewayLabel); ?></h2>
                <p><?php echo app_h($gatewayInstructions !== '' ? $gatewayInstructions : app_tr('استخدم بيانات السداد المتاحة ثم أرسل إشعار الدفع من نفس الصفحة.', 'Use the available payment details, then submit the payment notice from the same page.')); ?></p>
                <div class="actions">
                    <?php if ($checkoutUrl !== '' && $invoiceStatus !== 'paid'): ?>
                        <a href="<?php echo app_h($checkoutUrl); ?>" target="_blank" rel="noopener noreferrer" referrerpolicy="no-referrer" class="btn primary"><?php echo app_h(app_tr('فتح صفحة السداد', 'Open checkout page')); ?></a>
                    <?php endif; ?>
                    <button type="button" class="btn dark" onclick="copyValue('<?php echo app_h((string)($invoice['invoice_number'] ?? '')); ?>')"><?php echo app_h(app_tr('نسخ رقم الفاتورة', 'Copy invoice number')); ?></button>
                    <?php if ($tenantPortalUrl !== ''): ?>
                        <a href="<?php echo app_h($tenantPortalUrl); ?>" class="btn dark"><?php echo app_h(app_tr('بوابة الحساب الكاملة', 'Full billing portal')); ?></a>
                    <?php endif; ?>
                    <?php if (trim((string)($gatewaySettings['support_whatsapp'] ?? '')) !== ''): ?>
                        <a href="https://wa.me/<?php echo app_h(preg_replace('/[^0-9]/', '', (string)$gatewaySettings['support_whatsapp'])); ?>" target="_blank" rel="noopener noreferrer" referrerpolicy="no-referrer" class="btn dark"><?php echo app_h(app_tr('التواصل عبر واتساب', 'Contact on WhatsApp')); ?></a>
                    <?php endif; ?>
                    <?php if (!empty($gatewaySettings['whatsapp_notifications_enabled']) && trim((string)($gatewaySettings['support_whatsapp'] ?? '')) !== ''): ?>
                        <span class="btn dark" style="cursor:default;"><?php echo app_h(app_tr('إشعارات واتساب مفعلة', 'WhatsApp notifications enabled')); ?></span>
                    <?php endif; ?>
                </div>
            </div>

            <div class="note">
                <?php echo app_h(app_tr('مرجع البوابة', 'Gateway reference')); ?>: <?php echo app_h((string)($invoice['access_token'] ?? '')); ?><br>
                <?php if (trim((string)($gatewaySettings['support_email'] ?? '')) !== ''): ?>
                    <?php echo app_h(app_tr('بريد الدعم', 'Support email')); ?>: <?php echo app_h((string)$gatewaySettings['support_email']); ?><br>
                <?php endif; ?>
                <?php if (trim((string)($gatewaySettings['support_whatsapp'] ?? '')) !== ''): ?>
                    <?php echo app_h(app_tr('واتساب الدعم', 'Support WhatsApp')); ?>: <?php echo app_h((string)$gatewaySettings['support_whatsapp']); ?>
                <?php endif; ?>
            </div>

            <?php if ($invoiceStatus !== 'paid' && $invoiceStatus !== 'cancelled'): ?>
                <div class="section">
                    <h2><?php echo app_h(app_tr('إشعار السداد', 'Payment notice')); ?></h2>
                    <p><?php echo app_h(app_tr('بعد إتمام التحويل أو الدفع الخارجي، أرسل مرجع العملية من هنا ليظهر مباشرة في غرفة التشغيل.', 'After completing the transfer or external checkout, send the transaction reference here so it appears immediately in the operations room.')); ?></p>
                    <form method="post" class="portal-form">
                        <input type="hidden" name="action" value="submit_payment_notice">
                        <input type="hidden" name="token" value="<?php echo app_h($token); ?>">
                        <input type="text" name="payer_name" placeholder="<?php echo app_h(app_tr('اسم المحول / الشركة', 'Payer name / company')); ?>">
                        <input type="text" name="payment_ref" placeholder="<?php echo app_h(app_tr('مرجع العملية', 'Transaction reference')); ?>">
                        <textarea name="notice" placeholder="<?php echo app_h(app_tr('ملاحظات إضافية لفريق التحصيل', 'Additional notes for the billing team')); ?>"></textarea>
                        <button type="submit" class="btn primary"><?php echo app_h(app_tr('إرسال إشعار السداد', 'Send payment notice')); ?></button>
                    </form>
                </div>
            <?php else: ?>
                <div class="flash success"><?php echo app_h(app_tr('هذه الفاتورة مسددة بالفعل. شكرًا لك.', 'This invoice is already paid. Thank you.')); ?></div>
            <?php endif; ?>

            <div class="section">
                <h2><?php echo app_h(app_tr('فواتير الاشتراك', 'Subscription invoices')); ?></h2>
                <p><?php echo app_h(app_tr('عرض موحد لآخر الفواتير الخاصة بنفس المستأجر مع إمكانية فتح كل فاتورة على حدة.', 'Unified view for the latest invoices of this tenant with the ability to open each invoice separately.')); ?></p>
                <div class="list">
                    <?php foreach ($tenantInvoices as $tenantInvoice): ?>
                        <?php
                            $tenantInvoiceStatus = strtolower(trim((string)($tenantInvoice['status'] ?? 'issued')));
                            $tenantInvoicePortalUrl = function_exists('saas_subscription_invoice_public_url') ? saas_subscription_invoice_public_url($tenantInvoice) : '';
                        ?>
                        <div class="list-row">
                            <div class="top">
                                <strong><?php echo app_h((string)($tenantInvoice['invoice_number'] ?? 'SINV')); ?></strong>
                                <span class="badge <?php echo app_h($tenantInvoiceStatus); ?>"><?php echo app_h(app_status_label((string)($tenantInvoice['status'] ?? 'issued'))); ?></span>
                            </div>
                            <small><?php echo app_h(app_tr('القيمة', 'Amount')); ?>: <?php echo app_h((string)($tenantInvoice['currency_code'] ?? 'EGP')); ?> <?php echo app_h(number_format((float)($tenantInvoice['amount'] ?? 0), 2)); ?></small>
                            <small><?php echo app_h(app_tr('الدورة', 'Period')); ?>: <?php echo app_h((string)($tenantInvoice['period_start'] ?? '-')); ?> -> <?php echo app_h((string)($tenantInvoice['period_end'] ?? '-')); ?></small>
                            <small><?php echo app_h(app_tr('الاستحقاق', 'Due date')); ?>: <?php echo app_h((string)($tenantInvoice['due_date'] ?? '-')); ?></small>
                            <?php if ($tenantInvoicePortalUrl !== ''): ?>
                                <div class="actions">
                                    <a href="<?php echo app_h($tenantInvoicePortalUrl); ?>" class="btn dark"><?php echo app_h(app_tr('فتح الفاتورة', 'Open invoice')); ?></a>
                                    <?php if (trim((string)($tenantInvoice['gateway_public_url'] ?? '')) !== '' && $tenantInvoiceStatus !== 'paid'): ?>
                                        <a href="<?php echo app_h((string)$tenantInvoice['gateway_public_url']); ?>" target="_blank" rel="noopener noreferrer" referrerpolicy="no-referrer" class="btn primary"><?php echo app_h(app_tr('فتح السداد', 'Open payment')); ?></a>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
    <script>
    function copyValue(value) {
        if (!value) return;
        if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
            navigator.clipboard.writeText(value);
            return;
        }
        const probe = document.createElement('textarea');
        probe.value = value;
        document.body.appendChild(probe);
        probe.select();
        try { document.execCommand('copy'); } catch (error) {}
        document.body.removeChild(probe);
    }
    </script>
</body>
</html>
