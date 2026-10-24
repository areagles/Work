<?php
ob_start();
require 'auth.php';
require 'config.php';
require_once 'receipts_runtime.php';
require 'header.php';

$canInvoiceView = app_user_can('invoices.view') || app_user_can('invoices.update');
$flashSuccess = $_SESSION['flash_success'] ?? '';
unset($_SESSION['flash_success']);
$msg = '';

if (function_exists('financeEnsurePurchaseReturnFinanceSchema')) {
    financeEnsurePurchaseReturnFinanceSchema($conn);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'void_return') {
    if (!app_verify_csrf($_POST['_csrf_token'] ?? '')) {
        $msg = app_tr('انتهت صلاحية الجلسة. حدّث الصفحة ثم حاول مرة أخرى.', 'Session expired. Refresh the page and try again.');
    } else {
        $returnId = (int)($_POST['return_id'] ?? 0);
        try {
            $conn->begin_transaction();
            $void = financeVoidPurchaseReturn($conn, $returnId, (string)($_SESSION['name'] ?? 'System'));
            if (empty($void['ok'])) {
                throw new RuntimeException((string)($void['message'] ?? 'purchase_return_void_failed'));
            }
            $conn->commit();
            $_SESSION['flash_success'] = app_tr('✅ تم إلغاء مردود الشراء وإزالة أثره المالي.', '✅ Purchase return was voided and its financial impact was removed.');
            app_safe_redirect('purchase_returns.php');
        } catch (Throwable $e) {
            $conn->rollback();
            $msg = app_tr('❌ تعذر إلغاء مردود الشراء حالياً.', '❌ Failed to void purchase return.');
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'update_refund') {
    if (!app_verify_csrf($_POST['_csrf_token'] ?? '')) {
        $msg = app_tr('انتهت صلاحية الجلسة. حدّث الصفحة ثم حاول مرة أخرى.', 'Session expired. Refresh the page and try again.');
    } else {
        $returnId = (int)($_POST['return_id'] ?? 0);
        $refundAmount = round((float)($_POST['refund_amount'] ?? 0), 2);
        try {
            $conn->begin_transaction();
            $sync = finance_sync_purchase_return_refund_receipt($conn, $returnId, $refundAmount, (string)($_SESSION['name'] ?? 'System'));
            if (empty($sync['ok'])) {
                throw new RuntimeException((string)($sync['message'] ?? 'purchase_return_refund_failed'));
            }
            $conn->commit();
            $_SESSION['flash_success'] = app_tr('✅ تم تحديث رد مبلغ مردود الشراء وترحيله ماليًا.', '✅ Purchase return refund was updated and posted to finance.');
            app_safe_redirect('purchase_returns.php');
        } catch (Throwable $e) {
            $conn->rollback();
            $msg = app_tr('❌ تعذر تحديث رد مبلغ مردود الشراء حالياً.', '❌ Failed to update purchase return refund.');
        }
    }
}

if (!$canInvoiceView) {
    http_response_code(403);
    echo "<div class='container' style='margin-top:30px;'><div style='background:#1d0f0f;border:1px solid #5b2020;border-radius:12px;padding:20px;color:#ffb3b3;'>"
        . app_h(app_tr('⛔ لا تملك صلاحية عرض مردودات الشراء.', '⛔ You do not have permission to view purchase returns.'))
        . "</div></div>";
    require 'footer.php';
    exit;
}

$returns = [];
$stmt = $conn->prepare("
    SELECT r.*, s.name AS supplier_name, w.name AS warehouse_name, p.purchase_number
    FROM purchase_invoice_returns r
    JOIN suppliers s ON s.id = r.supplier_id
    LEFT JOIN warehouses w ON w.id = r.warehouse_id
    LEFT JOIN purchase_invoices p ON p.id = r.purchase_invoice_id
    ORDER BY r.id DESC
    LIMIT 300
");
if ($stmt) {
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $returns[] = $row;
    }
    $stmt->close();
}
?>

<div class="container" style="margin-top:30px;">
    <?php if ($flashSuccess !== ''): ?>
        <div style="margin-bottom:16px; background:#102416; border:1px solid #2c7a45; color:#d6ffe1; border-radius:12px; padding:14px 16px;"><?php echo app_h($flashSuccess); ?></div>
    <?php endif; ?>
    <?php if ($msg !== ''): ?>
        <div style="margin-bottom:16px; background:#2b1212; border:1px solid #7a2c2c; color:#ffd9d9; border-radius:12px; padding:14px 16px;"><?php echo app_h($msg); ?></div>
    <?php endif; ?>

    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; gap:12px; flex-wrap:wrap;">
        <h2 style="color:var(--gold); margin:0;">↩️ سجل مردودات الشراء</h2>
        <div style="display:flex; gap:10px; flex-wrap:wrap;">
            <a href="invoices.php?tab=purchases" class="btn-royal" style="background:#1f2937; color:#fff; padding:10px 18px; text-decoration:none; border-radius:10px; font-weight:700;">رجوع للمشتريات</a>
        </div>
    </div>

    <div style="background:#1a1a1a; padding:20px; border-radius:14px; border:1px solid #333;">
        <table style="width:100%; border-collapse:collapse; color:#fff;">
            <thead>
                <tr style="border-bottom:2px solid #333; color:var(--gold);">
                    <th style="padding:10px; text-align:right;">#</th>
                    <th style="padding:10px; text-align:right;">المورد</th>
                    <th style="padding:10px; text-align:right;">فاتورة الشراء</th>
                    <th style="padding:10px; text-align:right;">المخزن</th>
                    <th style="padding:10px; text-align:right;">التاريخ</th>
                    <th style="padding:10px; text-align:right;">القيمة</th>
                    <th style="padding:10px; text-align:right;">رد المورد</th>
                    <th style="padding:10px; text-align:right;">الرصيد الدائن</th>
                    <th style="padding:10px; text-align:right;">الحالة</th>
                    <th style="padding:10px; text-align:right;">إجراءات</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($returns): ?>
                    <?php foreach ($returns as $row): ?>
                        <?php
                        $ref = trim((string)($row['return_number'] ?? ''));
                        if ($ref === '') {
                            $ref = 'PRTN-' . str_pad((string)$row['id'], 5, '0', STR_PAD_LEFT);
                        }
                        $purchaseRef = trim((string)($row['purchase_number'] ?? ''));
                        if ($purchaseRef === '') {
                            $purchaseRef = '#' . (int)$row['purchase_invoice_id'];
                        }
                        ?>
                        <tr style="border-bottom:1px solid #333;">
                            <td style="padding:14px; color:#999;"><?php echo app_h($ref); ?></td>
                            <td style="padding:14px; font-weight:700;"><?php echo app_h((string)$row['supplier_name']); ?></td>
                            <td style="padding:14px;"><?php echo app_h($purchaseRef); ?></td>
                            <td style="padding:14px;"><?php echo app_h((string)($row['warehouse_name'] ?? '-')); ?></td>
                            <td style="padding:14px;"><?php echo app_h((string)$row['return_date']); ?></td>
                            <?php
                            $refundAmount = round((float)($row['refund_amount'] ?? 0), 2);
                            $remainingCredit = max(0, round((float)$row['total_amount'] - $refundAmount, 2));
                            ?>
                            <td style="padding:14px; color:#fbbf24; font-weight:800;"><?php echo number_format((float)$row['total_amount'], 2); ?></td>
                            <td style="padding:14px;">
                                <form method="POST" style="display:flex; gap:8px; align-items:center; flex-wrap:wrap; margin:0;">
                                    <?php echo app_csrf_input(); ?>
                                    <input type="hidden" name="action" value="update_refund">
                                    <input type="hidden" name="return_id" value="<?php echo (int)$row['id']; ?>">
                                    <input type="number" step="0.01" min="0" max="<?php echo app_h((string)($row['total_amount'] ?? 0)); ?>" name="refund_amount" value="<?php echo app_h((string)$refundAmount); ?>" style="width:110px; background:#090909; border:1px solid #444; color:#fff; padding:10px; border-radius:8px;">
                                    <button type="submit" style="padding:10px 12px; border-radius:8px; border:none; background:linear-gradient(135deg,#e5c75a,#b98713); color:#141414; font-weight:800;">حفظ</button>
                                </form>
                            </td>
                            <?php $rowStatus = strtolower(trim((string)($row['status'] ?? 'posted'))); ?>
                            <td style="padding:14px; color:#93c5fd; font-weight:700;"><?php echo number_format($remainingCredit, 2); ?></td>
                            <td style="padding:14px; color:<?php echo in_array($rowStatus, ['void','cancelled'], true) ? '#f87171' : '#93c5fd'; ?>; font-weight:700;"><?php echo app_h((string)($row['status'] ?? 'posted')); ?></td>
                            <td style="padding:14px;">
                                <a href="print_purchase_return.php?id=<?php echo (int)$row['id']; ?>" target="_blank" style="color:#fff; margin-left:10px;" title="طباعة">
                                    <i class="fa-solid fa-print"></i>
                                </a>
                                <a href="purchase_return.php?invoice_id=<?php echo (int)$row['purchase_invoice_id']; ?>" style="color:#60a5fa;" title="مردود جديد على نفس الفاتورة">
                                    <i class="fa-solid fa-plus"></i>
                                </a>
                                <?php if (!in_array($rowStatus, ['void','cancelled'], true)): ?>
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('سيتم إلغاء مردود الشراء وإزالة أثره المالي. هل تريد المتابعة؟');">
                                        <?php echo app_csrf_input(); ?>
                                        <input type="hidden" name="action" value="void_return">
                                        <input type="hidden" name="return_id" value="<?php echo (int)$row['id']; ?>">
                                        <button type="submit" style="background:none; border:none; color:#f87171; cursor:pointer; margin-right:8px;" title="إلغاء مردود"><i class="fa-solid fa-ban"></i></button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="10" style="text-align:center; padding:30px; color:#777;">لا توجد مردودات شراء مسجلة.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require 'footer.php'; ob_end_flush(); ?>
