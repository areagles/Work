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
    echo "<div class='container page-shell' style='margin-top:30px;'><div class='alert alert-danger'>" . app_h(app_tr('غير مصرح لك بالدخول إلى ETA Outbox.', 'You are not authorized to access ETA outbox.')) . "</div></div>";
    require 'footer.php';
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['action'], $_POST['id'])) {
    app_require_csrf();
    $action = trim((string)($_POST['action'] ?? ''));
    $id = (int)($_POST['id'] ?? 0);
    if ($action === 'submit_eta_outbox' && $id > 0) {
        $result = app_eta_einvoice_submit_outbox($conn, $id);
        $msg = 'failed';
        if (!empty($result['ok']) && !empty($result['deferred'])) {
            $msg = 'deferred';
        } elseif (!empty($result['ok'])) {
            $msg = 'submitted';
        }
        header('Location: eta_outbox.php?msg=' . $msg);
        exit;
    }
    if ($action === 'delete_eta_outbox' && $id > 0) {
        $stmtRow = $conn->prepare("SELECT id, invoice_id, IFNULL(eta_uuid,'') AS eta_uuid, IFNULL(eta_submission_id,'') AS eta_submission_id FROM eta_outbox WHERE id = ? LIMIT 1");
        if ($stmtRow) {
            $stmtRow->bind_param('i', $id);
            $stmtRow->execute();
            $row = $stmtRow->get_result()->fetch_assoc();
            $stmtRow->close();
            if (is_array($row)) {
                $invoiceId = (int)($row['invoice_id'] ?? 0);
                $etaUuid = trim((string)($row['eta_uuid'] ?? ''));
                $etaSubmissionId = trim((string)($row['eta_submission_id'] ?? ''));
                $stmtDelete = $conn->prepare("DELETE FROM eta_outbox WHERE id = ? LIMIT 1");
                if ($stmtDelete) {
                    $stmtDelete->bind_param('i', $id);
                    $stmtDelete->execute();
                    $stmtDelete->close();
                }
                if ($invoiceId > 0 && $etaUuid === '' && $etaSubmissionId === '') {
                    $stmtInvoice = $conn->prepare("UPDATE invoices SET eta_status = '', eta_submission_id = '', eta_last_sync_at = NULL, eta_validation_json = '' WHERE id = ? LIMIT 1");
                    if ($stmtInvoice) {
                        $stmtInvoice->bind_param('i', $invoiceId);
                        $stmtInvoice->execute();
                        $stmtInvoice->close();
                    }
                }
                header('Location: eta_outbox.php?msg=deleted');
                exit;
            }
        }
        header('Location: eta_outbox.php?msg=failed');
        exit;
    }
}

$rows = [];
$res = $conn->query("
    SELECT
        o.*,
        i.invoice_number,
        i.inv_date,
        i.total_amount,
        c.name AS client_name
    FROM eta_outbox o
    LEFT JOIN invoices i ON i.id = o.invoice_id
    LEFT JOIN clients c ON c.id = i.client_id
    ORDER BY o.id DESC
    LIMIT 200
");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $rows[] = $row;
    }
}

require 'header.php';
$isEnglish = app_current_lang($conn) === 'en';
?>
<style>
    .eta-shell{max-width:1400px;margin:12px auto;padding:20px}
    .eta-card{background:#141414;border:1px solid #2a2a2a;border-radius:18px;padding:18px}
    .eta-table-wrap{overflow:auto}
    .eta-table{width:100%;border-collapse:collapse;color:#eee}
    .eta-table th,.eta-table td{padding:12px 10px;border-bottom:1px solid rgba(255,255,255,.08);text-align:right;vertical-align:top}
    .eta-badge{display:inline-flex;align-items:center;gap:6px;padding:6px 10px;border-radius:999px;background:#1e1e1e;border:1px solid #3a3a3a;font-size:.82rem}
    .eta-actions{display:flex;gap:8px;flex-wrap:wrap}
    .eta-btn{background:#d4af37;color:#000;border:none;border-radius:10px;padding:9px 12px;font-weight:700;cursor:pointer}
    .eta-note{color:#9ca0a8;font-size:.86rem}
</style>
<div class="eta-shell">
    <div class="eta-card">
        <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:14px;">
            <h2 class="ai-title" style="margin:0;"><?php echo app_h($isEnglish ? 'ETA Outbox' : 'ETA Outbox'); ?></h2>
            <div style="display:flex;gap:10px;flex-wrap:wrap;">
                <a href="invoices.php?tab=sales" class="eta-btn" style="text-decoration:none;display:inline-flex;align-items:center;"><?php echo app_h($isEnglish ? 'Back to invoices' : 'العودة إلى الفواتير'); ?></a>
                <a href="eta_diagnostics.php" class="eta-btn" style="text-decoration:none;display:inline-flex;align-items:center;"><?php echo app_h($isEnglish ? 'ETA diagnostics' : 'تشخيص ETA'); ?></a>
            </div>
        </div>
        <?php if (isset($_GET['msg'])): ?>
            <div class="eta-note" style="margin-bottom:12px;">
                <?php
                    if ($_GET['msg'] === 'submitted') echo app_h(app_tr('تم إرسال السجل إلى ETA.', 'Outbox record submitted to ETA.'));
                    if ($_GET['msg'] === 'deferred') echo app_h(app_tr('تم إبقاء السجل في ETA Outbox بانتظار ضبط خدمة التوقيع.', 'Outbox record remains queued until the signing service is configured.'));
                    if ($_GET['msg'] === 'deleted') echo app_h(app_tr('تم حذف سجل ETA المحلي بنجاح.', 'Local ETA outbox record deleted successfully.'));
                    if ($_GET['msg'] === 'failed') echo app_h(app_tr('تعذر تنفيذ الإرسال. راجع السجل والخطأ.', 'Submit failed. Review the row and the error.'));
                ?>
            </div>
        <?php endif; ?>
        <div class="eta-table-wrap">
            <table class="eta-table">
                <thead>
                    <tr>
                        <th><?php echo app_h(app_tr('السجل', 'Row')); ?></th>
                        <th><?php echo app_h(app_tr('الفاتورة', 'Invoice')); ?></th>
                        <th><?php echo app_h(app_tr('العميل', 'Client')); ?></th>
                        <th><?php echo app_h(app_tr('الحالة', 'Status')); ?></th>
                        <th><?php echo app_h(app_tr('UUID / Submission', 'UUID / Submission')); ?></th>
                        <th><?php echo app_h(app_tr('آخر خطأ', 'Last error')); ?></th>
                        <th><?php echo app_h(app_tr('إجراء', 'Action')); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$rows): ?>
                        <tr><td colspan="7" class="eta-note"><?php echo app_h(app_tr('لا توجد سجلات ETA حتى الآن.', 'No ETA outbox rows yet.')); ?></td></tr>
                    <?php else: ?>
                        <?php foreach ($rows as $row): ?>
                            <tr>
                                <td>#<?php echo (int)($row['id'] ?? 0); ?><div class="eta-note"><?php echo app_h((string)($row['created_at'] ?? '')); ?></div></td>
                                <td><?php echo app_h((string)($row['invoice_number'] ?? ('INV#' . (int)($row['invoice_id'] ?? 0)))); ?><div class="eta-note"><?php echo app_h((string)($row['inv_date'] ?? '')); ?> | <?php echo number_format((float)($row['total_amount'] ?? 0), 2); ?></div></td>
                                <td><?php echo app_h((string)($row['client_name'] ?? '')); ?></td>
                                <td>
                                    <span class="eta-badge"><?php echo app_h((string)($row['queue_status'] ?? 'draft')); ?></span>
                                    <div class="eta-note"><?php echo app_h((string)($row['signing_mode'] ?? '')); ?></div>
                                    <?php if (($row['queue_status'] ?? '') === 'queued' && trim((string)($row['eta_uuid'] ?? '')) === ''): ?>
                                        <div class="eta-note"><?php echo app_h(app_tr('مسودة انتظار توقيع محلي قبل الإرسال إلى ETA.', 'Local draft waiting for signing before ETA submission.')); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo app_h((string)($row['eta_uuid'] ?? '')); ?><div class="eta-note"><?php echo app_h((string)($row['eta_submission_id'] ?? '')); ?></div></td>
                                <td class="eta-note"><?php echo app_h((string)($row['last_error'] ?? '')); ?></td>
                                <td>
                                    <div class="eta-actions">
                                        <form method="post">
                                            <?php echo app_csrf_input(); ?>
                                            <input type="hidden" name="action" value="submit_eta_outbox">
                                            <input type="hidden" name="id" value="<?php echo (int)($row['id'] ?? 0); ?>">
                                            <button class="eta-btn" type="submit"><?php echo app_h(app_tr('توقيع وإرسال', 'Sign & Submit')); ?></button>
                                        </form>
                                        <a class="eta-btn" style="text-decoration:none;" href="edit_invoice.php?id=<?php echo (int)($row['invoice_id'] ?? 0); ?>"><?php echo app_h(app_tr('تعديل', 'Edit')); ?></a>
                                        <form method="post" onsubmit="return confirm('<?php echo app_h(app_tr('سيتم حذف سجل ETA المحلي فقط. هل تريد المتابعة؟', 'Only the local ETA outbox record will be deleted. Continue?')); ?>');">
                                            <?php echo app_csrf_input(); ?>
                                            <input type="hidden" name="action" value="delete_eta_outbox">
                                            <input type="hidden" name="id" value="<?php echo (int)($row['id'] ?? 0); ?>">
                                            <button class="eta-btn" type="submit" style="background:#b54242;color:#fff;"><?php echo app_h(app_tr('حذف', 'Delete')); ?></button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php require 'footer.php'; ob_end_flush(); ?>
