<?php
ob_start();
require 'auth.php';
require 'config.php';
require 'finance_engine.php';
app_handle_lang_switch($conn);
require 'header.php';

$canInvoiceUpdate = app_user_can('invoices.update');
if (!$canInvoiceUpdate) {
    http_response_code(403);
    echo "<div class='container' style='margin-top:30px;'><div style='background:#1d0f0f;border:1px solid #5b2020;border-radius:12px;padding:20px;color:#ffb3b3;'>" . app_h(app_tr('غير مصرح لك بتنفيذ مردود مبيعات.', 'You are not authorized to create a sales return.')) . "</div></div>";
    require 'footer.php';
    exit;
}

$invoiceId = (int)($_GET['invoice_id'] ?? 0);
$msg = '';
if ($invoiceId <= 0) {
    app_safe_redirect('invoices.php?tab=sales');
}

$stmt = $conn->prepare("
    SELECT i.*, c.name AS client_name
    FROM invoices i
    JOIN clients c ON c.id = i.client_id
    WHERE i.id = ?
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
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $returnDate)) {
            $returnDate = date('Y-m-d');
        }
        $notes = trim((string)($_POST['notes'] ?? ''));
        $postedIndexes = $_POST['line_index'] ?? [];
        $postedQtys = $_POST['return_qty'] ?? [];

        try {
            $conn->begin_transaction();
            $status = strtolower(trim((string)($invoice['status'] ?? '')));
            if ($status === 'cancelled') {
                throw new RuntimeException('sales_invoice_cancelled');
            }
            if ((float)($invoice['paid_amount'] ?? 0) > 0.00001) {
                throw new RuntimeException('sales_invoice_paid');
            }

            $normalizedReturns = [];
            $returnSubtotal = 0.0;
            for ($i = 0; $i < count($postedIndexes); $i++) {
                $idx = (int)($postedIndexes[$i] ?? -1);
                $qty = round((float)($postedQtys[$i] ?? 0), 4);
                if ($idx < 0 || $qty <= 0 || !isset($items[$idx])) {
                    continue;
                }
                $src = $items[$idx];
                $sourceQty = round((float)($src['qty'] ?? 0), 4);
                if ($qty - $sourceQty > 0.0001) {
                    throw new RuntimeException('sales_return_qty_exceeds_invoice');
                }
                $unitPrice = (float)($src['price'] ?? 0);
                $lineTotal = round($qty * $unitPrice, 2);
                $returnSubtotal += $lineTotal;
                $normalizedReturns[] = [
                    'line_index' => $idx,
                    'desc' => (string)($src['desc'] ?? ''),
                    'qty' => $qty,
                    'price' => $unitPrice,
                    'total' => $lineTotal,
                ];
            }

            if (empty($normalizedReturns)) {
                throw new RuntimeException('sales_return_empty');
            }

            $oldSubtotal = (float)($invoice['sub_total'] ?? 0);
            $oldTax = (float)($invoice['tax_total'] ?? ($invoice['tax'] ?? 0));
            $oldDiscount = (float)($invoice['discount'] ?? 0);
            $ratio = ($oldSubtotal > 0.00001) ? min(1, $returnSubtotal / $oldSubtotal) : 0.0;
            $returnTax = round($oldTax * $ratio, 2);
            $returnDiscount = round($oldDiscount * $ratio, 2);
            $returnGrand = round($returnSubtotal + $returnTax - $returnDiscount, 2);
            if ($returnGrand <= 0.00001) {
                throw new RuntimeException('sales_return_total_invalid');
            }

            $stmtReturn = $conn->prepare("
                INSERT INTO sales_invoice_returns (
                    invoice_id, client_id, return_date,
                    subtotal_amount, tax_amount, discount_amount, total_amount,
                    items_json, notes, created_by_user_id, created_by_name
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $itemsJson = json_encode($normalizedReturns, JSON_UNESCAPED_UNICODE);
            $userId = (int)($_SESSION['user_id'] ?? 0);
            $creatorName = (string)($_SESSION['name'] ?? 'System');
            $clientId = (int)$invoice['client_id'];
            $stmtReturn->bind_param(
                'iisddddssis',
                $invoiceId,
                $clientId,
                $returnDate,
                $returnSubtotal,
                $returnTax,
                $returnDiscount,
                $returnGrand,
                $itemsJson,
                $notes,
                $userId,
                $creatorName
            );
            $stmtReturn->execute();
            $returnId = (int)$conn->insert_id;
            $stmtReturn->close();
            app_assign_document_number($conn, 'sales_invoice_returns', $returnId, 'return_number', 'srtn', $returnDate);

            $updatedItems = $items;
            foreach ($normalizedReturns as $returned) {
                $idx = (int)$returned['line_index'];
                $src = $updatedItems[$idx];
                $newQty = max(0, round((float)($src['qty'] ?? 0) - (float)$returned['qty'], 4));
                if ($newQty <= 0.00001) {
                    unset($updatedItems[$idx]);
                    continue;
                }
                $unitPrice = (float)($src['price'] ?? 0);
                $updatedItems[$idx]['qty'] = $newQty;
                $updatedItems[$idx]['total'] = round($newQty * $unitPrice, 2);
            }
            $updatedItems = array_values($updatedItems);

            $newSubtotal = max(0.0, round($oldSubtotal - $returnSubtotal, 2));
            $newTax = max(0.0, round($oldTax - $returnTax, 2));
            $newDiscount = max(0.0, round($oldDiscount - $returnDiscount, 2));
            $newTotal = max(0.0, round((float)$invoice['total_amount'] - $returnGrand, 2));
            $newStatus = $newTotal <= 0.00001 ? 'cancelled' : 'unpaid';

            $taxLines = app_tax_decode_lines((string)($invoice['taxes_json'] ?? '[]'));
            $updatedTaxLines = [];
            foreach ($taxLines as $taxLine) {
                $taxLine['base_amount'] = max(0.0, round((float)($taxLine['base_amount'] ?? 0) * (1 - $ratio), 2));
                $taxLine['amount'] = max(0.0, round((float)($taxLine['amount'] ?? 0) * (1 - $ratio), 2));
                if ((float)$taxLine['amount'] > 0.00001 || (float)$taxLine['base_amount'] > 0.00001) {
                    $updatedTaxLines[] = $taxLine;
                }
            }

            $updatedItemsJson = json_encode($updatedItems, JSON_UNESCAPED_UNICODE);
            $updatedTaxesJson = json_encode($updatedTaxLines, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $stmtUpd = $conn->prepare("
                UPDATE invoices
                SET sub_total = ?, tax = ?, tax_total = ?, discount = ?, total_amount = ?,
                    paid_amount = 0, remaining_amount = ?, status = ?, items_json = ?, taxes_json = ?
                WHERE id = ?
            ");
            $stmtUpd->bind_param('ddddddsssi', $newSubtotal, $newTax, $newTax, $newDiscount, $newTotal, $newTotal, $newStatus, $updatedItemsJson, $updatedTaxesJson, $invoiceId);
            $stmtUpd->execute();
            $stmtUpd->close();

            app_audit_log_add($conn, 'sales_invoice.return_created', [
                'entity_type' => 'sales_invoice_return',
                'entity_key' => (string)$returnId,
                'details' => [
                    'invoice_id' => $invoiceId,
                    'client_id' => $clientId,
                    'total' => $returnGrand,
                ],
            ]);

            $conn->commit();
            $_SESSION['flash_success'] = app_tr('تم حفظ مردود المبيعات بنجاح. رقم المردود: ', 'Sales return created successfully. Return #: ') . $returnId;
            app_safe_redirect('sales_returns.php');
        } catch (Throwable $e) {
            $conn->rollback();
            $reason = $e->getMessage();
            if ($reason === 'sales_invoice_paid') {
                $msg = app_tr('لا يمكن عمل مردود على فاتورة مبيعات عليها تحصيل. نفّذ رد مبلغ/إشعار دائن أولاً.', 'Cannot return a sales invoice that already has receipts. Create a refund/credit note first.');
            } elseif ($reason === 'sales_return_qty_exceeds_invoice') {
                $msg = app_tr('كمية المردود تتجاوز كمية الفاتورة.', 'Return quantity exceeds invoice quantity.');
            } elseif ($reason === 'sales_return_empty') {
                $msg = app_tr('أدخل كمية مردود واحدة على الأقل.', 'Enter at least one return quantity.');
            } else {
                $msg = app_tr('تعذر حفظ مردود المبيعات حالياً.', 'Failed to save the sales return.');
            }
        }
    }
}
?>
<div class="container" style="margin-top:30px;">
    <?php if ($msg !== ''): ?>
        <div style="margin-bottom:16px; background:#2b1212; border:1px solid #7a2c2c; color:#ffd9d9; border-radius:12px; padding:14px 16px;"><?php echo app_h($msg); ?></div>
    <?php endif; ?>
    <div style="background:#151515; border:1px solid #333; border-top:4px solid var(--gold); border-radius:18px; padding:24px;">
        <div style="display:flex; justify-content:space-between; gap:16px; align-items:center; margin-bottom:18px; flex-wrap:wrap;">
            <div>
                <h2 style="margin:0; color:var(--gold);">مردود مبيعات جزئي</h2>
                <div style="color:#aaa; margin-top:8px;">
                    <?php echo app_h((string)($invoice['invoice_number'] ?: ('#' . $invoice['id']))); ?> |
                    <?php echo app_h((string)$invoice['client_name']); ?> |
                    <?php echo app_h(app_tr('الحالة:', 'Status:') . ' ' . (string)$invoice['status']); ?>
                </div>
            </div>
            <a href="invoices.php?tab=sales" style="color:#ddd; text-decoration:none;">رجوع</a>
        </div>
        <form method="POST">
            <?php echo app_csrf_input(); ?>
            <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(220px,1fr)); gap:18px; margin-bottom:20px;">
                <div>
                    <label style="display:block; color:var(--gold); margin-bottom:8px;">تاريخ المردود</label>
                    <input type="date" name="return_date" value="<?php echo app_h(date('Y-m-d')); ?>" style="width:100%; background:#090909; border:1px solid #444; color:#fff; padding:12px; border-radius:8px;">
                </div>
                <div>
                    <label style="display:block; color:var(--gold); margin-bottom:8px;">ملاحظة</label>
                    <input type="text" name="notes" style="width:100%; background:#090909; border:1px solid #444; color:#fff; padding:12px; border-radius:8px;">
                </div>
            </div>
            <table style="width:100%; border-collapse:collapse; color:#fff;">
                <thead>
                    <tr style="border-bottom:1px solid #333; color:var(--gold);">
                        <th style="text-align:right; padding:10px;">البند</th>
                        <th style="text-align:right; padding:10px;">كمية الفاتورة</th>
                        <th style="text-align:right; padding:10px;">سعر الوحدة</th>
                        <th style="text-align:right; padding:10px;">كمية المردود</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $idx => $line): ?>
                        <tr style="border-bottom:1px solid #282828;">
                            <td style="padding:10px;"><?php echo app_h((string)($line['desc'] ?? '')); ?></td>
                            <td style="padding:10px;"><?php echo number_format((float)($line['qty'] ?? 0), 4); ?></td>
                            <td style="padding:10px;"><?php echo number_format((float)($line['price'] ?? 0), 2); ?></td>
                            <td style="padding:10px;">
                                <input type="hidden" name="line_index[]" value="<?php echo (int)$idx; ?>">
                                <input type="number" step="0.0001" min="0" max="<?php echo app_h((string)($line['qty'] ?? 0)); ?>" name="return_qty[]" value="0" style="width:120px; background:#090909; border:1px solid #444; color:#fff; padding:8px; border-radius:8px;">
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <div style="margin-top:22px; display:flex; justify-content:flex-end; gap:10px;">
                <button type="submit" style="background:linear-gradient(45deg,var(--gold),#b8860b); color:#000; border:0; padding:12px 22px; border-radius:10px; font-weight:800;">حفظ المردود</button>
            </div>
        </form>
    </div>
</div>

<?php require 'footer.php'; ob_end_flush(); ?>
