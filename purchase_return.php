<?php
ob_start();
require 'auth.php';
require 'config.php';
require_once 'inventory_engine.php';
require_once 'receipts_runtime.php';
app_handle_lang_switch($conn);
require 'header.php';

$canInvoiceUpdate = app_user_can('invoices.update');
if (!$canInvoiceUpdate) {
    http_response_code(403);
    echo "<div class='container' style='margin-top:30px;'><div style='background:#1d0f0f;border:1px solid #5b2020;border-radius:12px;padding:20px;color:#ffb3b3;'>" . app_h(app_tr('⛔ لا تملك صلاحية تنفيذ مردود شراء.', '⛔ You do not have permission to create a purchase return.')) . "</div></div>";
    require 'footer.php';
    exit;
}

$invoiceId = (int)($_GET['invoice_id'] ?? 0);
$msg = '';
$flashSuccess = $_SESSION['flash_success'] ?? '';
unset($_SESSION['flash_success']);

if ($invoiceId <= 0) {
    app_safe_redirect('invoices.php?tab=purchases');
}

$stmt = $conn->prepare("
    SELECT p.*, s.name AS supplier_name, w.name AS warehouse_name
    FROM purchase_invoices p
    JOIN suppliers s ON s.id = p.supplier_id
    LEFT JOIN warehouses w ON w.id = p.warehouse_id
    WHERE p.id = ?
    LIMIT 1
");
$stmt->bind_param('i', $invoiceId);
$stmt->execute();
$invoice = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$invoice) {
    echo "<div class='container' style='margin-top:30px;color:#ffb3b3;'>" . app_h(app_tr('الفاتورة غير موجودة.', 'Invoice not found.')) . "</div>";
    require 'footer.php';
    exit;
}

$items = json_decode((string)($invoice['items_json'] ?? '[]'), true);
if (!is_array($items)) {
    $items = [];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!app_verify_csrf($_POST['_csrf_token'] ?? '')) {
        $msg = app_tr('انتهت صلاحية الجلسة. حدّث الصفحة ثم حاول مرة أخرى.', 'Session expired. Refresh the page and try again.');
    } else {
        $returnDate = trim((string)($_POST['return_date'] ?? date('Y-m-d')));
        $notes = trim((string)($_POST['notes'] ?? ''));
        $lines = [];
        $postedItemIds = $_POST['item_id'] ?? [];
        $postedQtys = $_POST['return_qty'] ?? [];
        for ($i = 0; $i < count($postedItemIds); $i++) {
            $itemId = (int)($postedItemIds[$i] ?? 0);
            $qty = (float)($postedQtys[$i] ?? 0);
            if ($itemId > 0 && $qty > 0) {
                $lines[] = ['item_id' => $itemId, 'qty' => $qty];
            }
        }

        try {
            $conn->begin_transaction();
            $returnId = inventory_create_purchase_return(
                $conn,
                $invoiceId,
                $returnDate !== '' ? $returnDate : date('Y-m-d'),
                $lines,
                (int)($_SESSION['user_id'] ?? 0),
                (string)($_SESSION['name'] ?? 'System'),
                $notes
            );
            if (function_exists('financeLedgerPostByRule')) {
                $stmtLedger = $conn->prepare("
                    SELECT r.id, r.return_number, r.return_date, r.total_amount, r.subtotal_amount, r.tax_amount, r.discount_amount,
                           r.purchase_invoice_id, r.supplier_id
                    FROM purchase_invoice_returns r
                    WHERE r.id = ?
                    LIMIT 1
                ");
                $stmtLedger->bind_param('i', $returnId);
                $stmtLedger->execute();
                $ledgerRow = $stmtLedger->get_result()->fetch_assoc() ?: [];
                $stmtLedger->close();
                financeLedgerPostByRule(
                    $conn,
                    'purchase_return_issued',
                    $returnId,
                    [
                        'supplier_id' => (int)($ledgerRow['supplier_id'] ?? ($invoice['supplier_id'] ?? 0)),
                        'ref' => (string)($ledgerRow['return_number'] ?? ''),
                        'doc_date' => (string)($ledgerRow['return_date'] ?? ($returnDate !== '' ? $returnDate : date('Y-m-d'))),
                        'total_amount' => (float)($ledgerRow['total_amount'] ?? 0),
                        'notes' => trim($notes),
                    ],
                    [
                        'purchase_invoice_id' => (int)($ledgerRow['purchase_invoice_id'] ?? $invoiceId),
                        'subtotal_amount' => (float)($ledgerRow['subtotal_amount'] ?? 0),
                        'tax_amount' => (float)($ledgerRow['tax_amount'] ?? 0),
                        'discount_amount' => (float)($ledgerRow['discount_amount'] ?? 0),
                        'total_amount' => (float)($ledgerRow['total_amount'] ?? 0),
                    ],
                    (string)($_SESSION['name'] ?? 'System')
                );
            }
            $conn->commit();
            $_SESSION['flash_success'] = app_tr(
                '✅ تم حفظ مردود الشراء بنجاح. رقم المردود: ',
                '✅ Purchase return created successfully. Return #: '
            ) . $returnId;
            app_safe_redirect('invoices.php?tab=purchases');
        } catch (Throwable $e) {
            $conn->rollback();
            $reason = $e->getMessage();
            if ($reason === 'purchase_invoice_paid') {
                $msg = app_tr('❌ لا يمكن عمل مردود على فاتورة مسدد عليها. ألغ السداد أولاً.', '❌ Cannot create a return for a paid invoice. Reverse payments first.');
            } elseif ($reason === 'purchase_invoice_stock_consumed') {
                $msg = app_tr('❌ لا يمكن مردود هذه الكميات لأن جزءًا منها لم يعد متاحًا بالمخزن.', '❌ Cannot return these quantities because some stock is no longer available.');
            } elseif ($reason === 'purchase_return_qty_exceeds_invoice') {
                $msg = app_tr('❌ كمية المردود تتجاوز الكمية الأصلية في الفاتورة.', '❌ Return quantity exceeds the invoice quantity.');
            } elseif ($reason === 'purchase_return_empty') {
                $msg = app_tr('❌ أدخل كمية مردود واحدة على الأقل.', '❌ Enter at least one return quantity.');
            } else {
                $msg = app_tr('❌ تعذر حفظ مردود الشراء حالياً.', '❌ Failed to save the purchase return.');
            }
        }
    }
}
?>
<div class="container" style="margin-top:30px;">
    <?php if ($flashSuccess !== ''): ?>
        <div style="margin-bottom:16px; background:#102416; border:1px solid #2c7a45; color:#d6ffe1; border-radius:12px; padding:14px 16px;"><?php echo app_h($flashSuccess); ?></div>
    <?php endif; ?>
    <?php if ($msg !== ''): ?>
        <div style="margin-bottom:16px; background:#2b1212; border:1px solid #7a2c2c; color:#ffd9d9; border-radius:12px; padding:14px 16px;"><?php echo app_h($msg); ?></div>
    <?php endif; ?>

    <div style="background:#151515; border:1px solid #333; border-top:4px solid var(--gold); border-radius:18px; padding:24px;">
        <div style="display:flex; justify-content:space-between; gap:16px; align-items:center; margin-bottom:18px; flex-wrap:wrap;">
            <div>
                <h2 style="margin:0; color:var(--gold);">مردود شراء جزئي</h2>
                <div style="color:#aaa; margin-top:8px;">
                    <?php echo app_h((string)($invoice['purchase_number'] ?: ('#' . $invoice['id']))); ?> |
                    <?php echo app_h((string)$invoice['supplier_name']); ?> |
                    <?php echo app_h((string)($invoice['warehouse_name'] ?? '-')); ?>
                </div>
            </div>
            <a href="invoices.php?tab=purchases" style="color:#ddd; text-decoration:none;">رجوع</a>
        </div>

        <form method="POST">
            <?php echo app_csrf_input(); ?>
            <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(220px,1fr)); gap:18px; margin-bottom:20px;">
                <div>
                    <label style="display:block; color:var(--gold); margin-bottom:8px;">تاريخ المردود</label>
                    <input type="date" name="return_date" value="<?php echo app_h(date('Y-m-d')); ?>" style="width:100%; background:#090909; border:1px solid #444; color:#fff; padding:12px; border-radius:8px;">
                </div>
                <div>
                    <label style="display:block; color:var(--gold); margin-bottom:8px;">حالة الفاتورة</label>
                    <input type="text" readonly value="<?php echo app_h((string)$invoice['status']); ?>" style="width:100%; background:#090909; border:1px solid #444; color:#bbb; padding:12px; border-radius:8px;">
                </div>
            </div>

            <table style="width:100%; border-collapse:collapse; color:#fff;">
                <thead>
                    <tr style="border-bottom:1px solid #333; color:var(--gold);">
                        <th style="text-align:right; padding:10px;">الصنف</th>
                        <th style="text-align:right; padding:10px;">كمية الفاتورة</th>
                        <th style="text-align:right; padding:10px;">السعر</th>
                        <th style="text-align:right; padding:10px;">كمية المردود</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $item): ?>
                        <tr style="border-bottom:1px solid #262626;">
                            <td style="padding:12px;">
                                <?php echo app_h((string)($item['desc'] ?? '')); ?>
                                <input type="hidden" name="item_id[]" value="<?php echo (int)($item['item_id'] ?? 0); ?>">
                            </td>
                            <td style="padding:12px;"><?php echo app_h((string)($item['qty'] ?? 0)); ?></td>
                            <td style="padding:12px;"><?php echo number_format((float)($item['price'] ?? 0), 2); ?></td>
                            <td style="padding:12px;">
                                <input type="number" step="0.0001" min="0" max="<?php echo app_h((string)($item['qty'] ?? 0)); ?>" name="return_qty[]" value="0" style="width:140px; background:#090909; border:1px solid #444; color:#fff; padding:10px; border-radius:8px;">
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <div style="margin-top:20px;">
                <label style="display:block; color:var(--gold); margin-bottom:8px;">ملاحظات</label>
                <textarea name="notes" rows="3" style="width:100%; background:#090909; border:1px solid #444; color:#fff; padding:12px; border-radius:8px;"></textarea>
            </div>

            <div style="margin-top:20px; display:flex; justify-content:flex-end; gap:12px;">
                <a href="invoices.php?tab=purchases" style="padding:12px 16px; border-radius:10px; border:1px solid #444; color:#ddd; text-decoration:none;">إلغاء</a>
                <button type="submit" style="padding:12px 18px; border-radius:10px; border:none; background:linear-gradient(135deg,#e5c75a,#b98713); color:#141414; font-weight:800;">حفظ مردود الشراء</button>
            </div>
        </form>
    </div>
</div>

<?php require 'footer.php'; ob_end_flush(); ?>
