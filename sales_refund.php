<?php
ob_start();
require 'auth.php';
require 'config.php';
require 'finance_engine.php';
app_handle_lang_switch($conn);
require 'header.php';

$canFinanceEdit = app_user_can('finance.transactions.manage') || app_user_can('invoices.update');
if (!$canFinanceEdit) {
    http_response_code(403);
    echo "<div class='container' style='margin-top:30px;'><div style='background:#1d0f0f;border:1px solid #5b2020;border-radius:12px;padding:20px;color:#ffb3b3;'>" . app_h(app_tr('غير مصرح لك بتنفيذ رد مبلغ.', 'You are not authorized to create refunds.')) . "</div></div>";
    require 'footer.php';
    exit;
}

$clientId = (int)($_GET['client_id'] ?? ($_POST['client_id'] ?? 0));
$invoiceId = (int)($_GET['invoice_id'] ?? ($_POST['invoice_id'] ?? 0));
$msg = '';
$client = null;
$invoice = null;

if ($invoiceId > 0) {
    $stmtInvoice = $conn->prepare("
        SELECT i.id, i.client_id, i.invoice_number, i.total_amount, i.paid_amount, i.remaining_amount, c.name AS client_name
        FROM invoices i
        JOIN clients c ON c.id = i.client_id
        WHERE i.id = ?
        LIMIT 1
    ");
    $stmtInvoice->bind_param('i', $invoiceId);
    $stmtInvoice->execute();
    $invoice = $stmtInvoice->get_result()->fetch_assoc() ?: null;
    $stmtInvoice->close();
    if ($invoice) {
        $clientId = (int)$invoice['client_id'];
    }
}

if ($clientId > 0) {
    $stmtClient = $conn->prepare("SELECT id, name FROM clients WHERE id = ? LIMIT 1");
    $stmtClient->bind_param('i', $clientId);
    $stmtClient->execute();
    $client = $stmtClient->get_result()->fetch_assoc() ?: null;
    $stmtClient->close();
}

$snapshot = $clientId > 0 ? financeClientBalanceSnapshot($conn, $clientId) : ['net_balance' => 0.0, 'receipt_credit' => 0.0, 'opening_credit' => 0.0, 'refund_total' => 0.0];
$availableCredit = max(0.0, round(-1 * (float)($snapshot['net_balance'] ?? 0), 2));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!app_verify_csrf($_POST['_csrf_token'] ?? '')) {
        $msg = app_tr('انتهت صلاحية الجلسة. حدّث الصفحة ثم حاول مرة أخرى.', 'Session expired. Refresh the page and try again.');
    } elseif (!$client) {
        $msg = app_tr('اختر عميلاً صحيحاً.', 'Choose a valid client.');
    } else {
        $amount = round((float)($_POST['amount'] ?? 0), 2);
        $refundDate = trim((string)($_POST['refund_date'] ?? date('Y-m-d')));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $refundDate)) {
            $refundDate = date('Y-m-d');
        }
        $notes = trim((string)($_POST['notes'] ?? ''));
        if ($amount <= 0.00001) {
            $msg = app_tr('قيمة رد المبلغ يجب أن تكون أكبر من صفر.', 'Refund amount must be greater than zero.');
        } elseif ($amount - $availableCredit > 0.00001) {
            $msg = app_tr('لا يمكن رد مبلغ أكبر من الرصيد الدائن الحالي للعميل.', 'Cannot refund more than the current client credit.');
        } else {
            try {
                $conn->begin_transaction();
                $descParts = [app_tr('رد مبلغ للعميل', 'Customer refund')];
                if ($invoiceId > 0) {
                    $descParts[] = app_tr('عن فاتورة', 'for invoice') . ' #' . $invoiceId;
                }
                if ($notes !== '') {
                    $descParts[] = $notes;
                }
                $description = implode(' - ', $descParts);
                $createdBy = (string)($_SESSION['name'] ?? 'System');
                $stmtRefund = $conn->prepare("
                    INSERT INTO financial_receipts
                        (type, category, amount, description, trans_date, client_id, invoice_id, created_by)
                    VALUES
                        ('out', 'refund', ?, ?, ?, ?, NULL, ?)
                ");
                $stmtRefund->bind_param('dssis', $amount, $description, $refundDate, $clientId, $createdBy);
                $stmtRefund->execute();
                $refundId = (int)$conn->insert_id;
                $stmtRefund->close();

                app_audit_log_add($conn, 'sales_refund.created', [
                    'entity_type' => 'financial_receipt',
                    'entity_key' => (string)$refundId,
                    'details' => [
                        'client_id' => $clientId,
                        'invoice_id' => $invoiceId,
                        'amount' => $amount,
                    ],
                ]);
                $conn->commit();
                $_SESSION['flash_success'] = app_tr('تم تسجيل رد المبلغ بنجاح. رقم الحركة: ', 'Refund recorded successfully. Transaction #: ') . $refundId;
                app_safe_redirect('finance.php?msg=saved&lid=' . $refundId);
            } catch (Throwable $e) {
                $conn->rollback();
                $msg = app_tr('تعذر تسجيل رد المبلغ حالياً.', 'Could not record the refund now.');
            }
        }
    }
}
?>

<div class="container" style="margin-top:30px;">
    <?php if ($msg !== ''): ?>
        <div style="margin-bottom:16px; background:#2b1212; border:1px solid #7a2c2c; color:#ffd9d9; border-radius:12px; padding:14px 16px;"><?php echo app_h($msg); ?></div>
    <?php endif; ?>

    <div style="background:#151515; border:1px solid #333; border-top:4px solid var(--gold); border-radius:18px; padding:24px; max-width:820px; margin:auto;">
        <div style="display:flex; justify-content:space-between; gap:16px; align-items:center; margin-bottom:18px; flex-wrap:wrap;">
            <div>
                <h2 style="margin:0; color:var(--gold);">رد مبلغ للعميل</h2>
                <div style="color:#aaa; margin-top:8px;">
                    <?php echo app_h($client ? (string)$client['name'] : app_tr('اختر العميل', 'Choose client')); ?>
                    <?php if ($invoice): ?> | <?php echo app_h((string)($invoice['invoice_number'] ?: ('#' . $invoice['id']))); ?><?php endif; ?>
                </div>
            </div>
            <a href="finance.php" style="color:#ddd; text-decoration:none;">رجوع للمالية</a>
        </div>

        <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(180px,1fr)); gap:12px; margin-bottom:20px;">
            <div style="background:#0d0d0d; border:1px solid #333; border-radius:12px; padding:14px;">
                <div style="color:#aaa;">الرصيد الدائن المتاح</div>
                <div style="font-size:1.6rem; font-weight:900; color:#2ecc71;"><?php echo number_format($availableCredit, 2); ?></div>
            </div>
            <div style="background:#0d0d0d; border:1px solid #333; border-radius:12px; padding:14px;">
                <div style="color:#aaa;">صافي الرصيد</div>
                <div style="font-size:1.6rem; font-weight:900;"><?php echo number_format((float)($snapshot['net_balance'] ?? 0), 2); ?></div>
            </div>
        </div>

        <form method="POST">
            <?php echo app_csrf_input(); ?>
            <input type="hidden" name="client_id" value="<?php echo (int)$clientId; ?>">
            <input type="hidden" name="invoice_id" value="<?php echo (int)$invoiceId; ?>">
            <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(220px,1fr)); gap:18px; margin-bottom:20px;">
                <div>
                    <label style="display:block; color:var(--gold); margin-bottom:8px;">تاريخ الرد</label>
                    <input type="date" name="refund_date" value="<?php echo app_h(date('Y-m-d')); ?>" style="width:100%; background:#090909; border:1px solid #444; color:#fff; padding:12px; border-radius:8px;">
                </div>
                <div>
                    <label style="display:block; color:var(--gold); margin-bottom:8px;">المبلغ</label>
                    <input type="number" step="0.01" min="0" max="<?php echo app_h((string)$availableCredit); ?>" name="amount" value="<?php echo app_h((string)$availableCredit); ?>" style="width:100%; background:#090909; border:1px solid #444; color:#fff; padding:12px; border-radius:8px;">
                </div>
            </div>
            <div style="margin-bottom:20px;">
                <label style="display:block; color:var(--gold); margin-bottom:8px;">ملاحظات</label>
                <textarea name="notes" rows="3" style="width:100%; background:#090909; border:1px solid #444; color:#fff; padding:12px; border-radius:8px;"></textarea>
            </div>
            <div style="display:flex; justify-content:flex-end; gap:10px;">
                <button type="submit" <?php echo (!$client || $availableCredit <= 0.00001) ? 'disabled' : ''; ?> style="background:linear-gradient(45deg,var(--gold),#b8860b); color:#000; border:0; padding:12px 22px; border-radius:10px; font-weight:800; opacity:<?php echo (!$client || $availableCredit <= 0.00001) ? '0.5' : '1'; ?>;">تسجيل رد المبلغ</button>
            </div>
        </form>
    </div>
</div>

<?php require 'footer.php'; ob_end_flush(); ?>
