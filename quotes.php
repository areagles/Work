<?php
// quotes.php - إدارة عروض الأسعار (النسخة الملكية المطورة V48.0)
ob_start();
require 'auth.php'; 
require 'config.php'; 
require 'header.php';
require_once __DIR__ . '/quote_growth_runtime.php';
app_quote_growth_ensure_schema($conn);
$appName = app_setting_get($conn, 'app_name', 'Arab Eagles');
$csrfToken = app_csrf_token();
$isEnglish = app_current_lang($conn) === 'en';

$my_role = $_SESSION['role'];
if (!(app_user_can('quotes.view') || in_array($my_role, ['admin', 'manager', 'sales', 'accountant'], true))) {
    die("<div class='container' style='text-align:center; padding:100px; color:#e74c3c;'><h2>" . app_h(app_tr('⛔ غير مصرح لك بالدخول.', '⛔ Access denied.')) . "</h2></div>");
}
$canCreateQuote = app_user_can('quotes.create') || in_array($my_role, ['admin', 'manager', 'sales'], true);
$canUpdateQuote = app_user_can('quotes.update') || in_array($my_role, ['admin', 'manager', 'sales'], true);
$canManageQuote = app_quote_growth_can_manage();
$quoteAlerts = app_quote_alerts($conn);

// 2. إجراءات الحالة والحذف
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['quote_action'])) {
    if (!app_verify_csrf($_POST['_csrf_token'] ?? '')) {
        http_response_code(403);
        die(app_h(app_tr('رمز التحقق غير صالح.', 'Invalid CSRF token.')));
    }
    $quoteId = (int)($_POST['quote_id'] ?? 0);
    $quoteAction = (string)($_POST['quote_action'] ?? '');
    if ($quoteId > 0 && $quoteAction === 'reset' && $canManageQuote) {
        $stmt = $conn->prepare("UPDATE quotes SET status = 'pending' WHERE id = ?");
        if ($stmt) {
            $stmt->bind_param('i', $quoteId);
            $stmt->execute();
            $stmt->close();
        }
        app_quote_history_add($conn, $quoteId, 'status_reset', 'تمت إعادة فتح العرض للمراجعة');
        header("Location: quotes.php?msg=reset"); exit;
    }

    if ($quoteId > 0 && $quoteAction === 'duplicate' && $canCreateQuote) {
        try {
            $stmtOld = $conn->prepare("SELECT * FROM quotes WHERE id = ? LIMIT 1");
            $stmtOld->bind_param('i', $quoteId);
            $stmtOld->execute();
            $oldQuote = $stmtOld->get_result()->fetch_assoc() ?: [];
            $stmtOld->close();
            if (!empty($oldQuote)) {
                $newDate = date('Y-m-d');
                $oldStart = strtotime((string)($oldQuote['created_at'] ?? $newDate));
                $oldEnd = strtotime((string)($oldQuote['valid_until'] ?? $newDate));
                $days = ($oldStart && $oldEnd && $oldEnd >= $oldStart) ? max(1, (int)ceil(($oldEnd - $oldStart) / 86400)) : 7;
                $newValid = date('Y-m-d', strtotime('+' . $days . ' days'));
                $newToken = bin2hex(random_bytes(32));
                $stmtDup = $conn->prepare("INSERT INTO quotes (client_id, created_at, valid_until, quote_kind, tax_law_key, total_amount, currency_code, currency_label, base_currency_code, exchange_rate_to_base, base_total_amount, tax_total, taxes_json, items_json, status, notes, access_token, source_pricing_record_id, pricing_source_ref) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, ?, ?, ?)");
                $clientIdDup = (int)($oldQuote['client_id'] ?? 0);
                $quoteKindDup = (string)($oldQuote['quote_kind'] ?? 'standard');
                $taxLawDup = (string)($oldQuote['tax_law_key'] ?? '');
                $totalDup = (float)($oldQuote['total_amount'] ?? 0);
                $currencyDup = (string)($oldQuote['currency_code'] ?? 'EGP');
                $currencyLabelDup = (string)($oldQuote['currency_label'] ?? $currencyDup);
                $baseCurrencyDup = (string)($oldQuote['base_currency_code'] ?? 'EGP');
                $rateDup = (float)($oldQuote['exchange_rate_to_base'] ?? 1);
                $baseTotalDup = (float)($oldQuote['base_total_amount'] ?? $totalDup);
                $taxTotalDup = (float)($oldQuote['tax_total'] ?? 0);
                $taxesDup = (string)($oldQuote['taxes_json'] ?? '[]');
                $itemsDup = (string)($oldQuote['items_json'] ?? '[]');
                $notesDup = (string)($oldQuote['notes'] ?? '');
                $sourcePricingRecordDup = (int)($oldQuote['source_pricing_record_id'] ?? 0);
                $pricingSourceRefDup = (string)($oldQuote['pricing_source_ref'] ?? '');
                $stmtDup->bind_param('issssdsssdddssssis', $clientIdDup, $newDate, $newValid, $quoteKindDup, $taxLawDup, $totalDup, $currencyDup, $currencyLabelDup, $baseCurrencyDup, $rateDup, $baseTotalDup, $taxTotalDup, $taxesDup, $itemsDup, $notesDup, $newToken, $sourcePricingRecordDup, $pricingSourceRefDup);
                $stmtDup->execute();
                $newQuoteId = (int)$stmtDup->insert_id;
                $stmtDup->close();
                app_assign_document_number($conn, 'quotes', $newQuoteId, 'quote_number', 'quote', $newDate);
                $stmtCopyItems = $conn->prepare("INSERT INTO quote_items (quote_id, item_name, description, quantity, unit, price, total) SELECT ?, item_name, description, quantity, unit, price, total FROM quote_items WHERE quote_id = ? ORDER BY id ASC");
                if ($stmtCopyItems) {
                    $stmtCopyItems->bind_param('ii', $newQuoteId, $quoteId);
                    $stmtCopyItems->execute();
                    $stmtCopyItems->close();
                }
                app_quote_history_add($conn, $newQuoteId, 'quote_duplicated', 'تم تكرار عرض السعر', ['source_quote_id' => $quoteId]);
                app_quote_history_add($conn, $quoteId, 'quote_duplicated_from', 'تم إنشاء نسخة من هذا العرض', ['new_quote_id' => $newQuoteId]);
                header("Location: edit_quote.php?id=" . $newQuoteId); exit;
            }
        } catch (Throwable $e) {
            error_log('quote duplicate failed: ' . $e->getMessage());
            header("Location: quotes.php?msg=duplicate_failed"); exit;
        }
    }
    if ($quoteId > 0 && in_array($quoteAction, ['close_review', 'open_review'], true) && $canManageQuote) {
        if ($quoteAction === 'close_review') {
            $reason = mb_substr(trim((string)($_POST['review_reason'] ?? 'تم إغلاق مراجعة العميل من إدارة العروض')), 0, 255);
            $userId = (int)($_SESSION['user_id'] ?? 0);
            $stmt = $conn->prepare("UPDATE quotes SET quote_review_closed_at = NOW(), quote_review_closed_by = ?, quote_review_closed_reason = ? WHERE id = ?");
            $stmt->bind_param('isi', $userId, $reason, $quoteId);
            $stmt->execute();
            $stmt->close();
            app_quote_history_add($conn, $quoteId, 'review_closed', 'تم إغلاق مراجعة العميل', ['reason' => $reason]);
            header("Location: quotes.php?msg=review_closed"); exit;
        }
        $stmt = $conn->prepare("UPDATE quotes SET quote_review_closed_at = NULL, quote_review_closed_by = 0, quote_review_closed_reason = '' WHERE id = ?");
        $stmt->bind_param('i', $quoteId);
        $stmt->execute();
        $stmt->close();
        app_quote_history_add($conn, $quoteId, 'review_opened', 'تم فتح مراجعة العميل');
        header("Location: quotes.php?msg=review_opened"); exit;
    }
    if ($quoteId > 0 && in_array($quoteAction, ['internal_approve', 'awaiting_deposit', 'ready_to_start', 'mark_sent'], true) && $canManageQuote) {
        if ($quoteAction === 'internal_approve') {
            $userId = (int)($_SESSION['user_id'] ?? 0);
            $stmt = $conn->prepare("UPDATE quotes SET internal_approval_status = 'approved', internal_approved_by = ?, internal_approved_at = NOW() WHERE id = ?");
            $stmt->bind_param('ii', $userId, $quoteId);
            $stmt->execute();
            $stmt->close();
            app_quote_history_add($conn, $quoteId, 'internal_approved', 'تم اعتماد العرض داخلياً');
            header("Location: quotes.php?msg=internal_approved"); exit;
        }
        if ($quoteAction === 'awaiting_deposit' || $quoteAction === 'ready_to_start') {
            $stage = $quoteAction === 'awaiting_deposit' ? 'awaiting_deposit' : 'ready_to_start';
            $stmt = $conn->prepare("UPDATE quotes SET fulfillment_stage = ? WHERE id = ?");
            $stmt->bind_param('si', $stage, $quoteId);
            $stmt->execute();
            $stmt->close();
            app_quote_history_add($conn, $quoteId, 'fulfillment_stage_changed', $stage === 'awaiting_deposit' ? 'تم وضع العرض بانتظار دفعة مقدمة' : 'تم وضع العرض جاهزاً للتنفيذ');
            header("Location: quotes.php?msg=stage_updated"); exit;
        }
        $stmt = $conn->prepare("UPDATE quotes SET client_review_last_sent_at = NOW() WHERE id = ?");
        $stmt->bind_param('i', $quoteId);
        $stmt->execute();
        $stmt->close();
        app_quote_history_add($conn, $quoteId, 'client_link_sent', 'تم تسجيل إرسال رابط العرض للعميل');
        header("Location: quotes.php?msg=sent_marked"); exit;
    }
    if ($quoteId > 0 && $quoteAction === 'delete' && $my_role === 'admin') {
        try {
            $conn->begin_transaction();
            $stmtItems = $conn->prepare("DELETE FROM quote_items WHERE quote_id = ?");
            if ($stmtItems) {
                $stmtItems->bind_param('i', $quoteId);
                $stmtItems->execute();
                $stmtItems->close();
            }
            $stmtQuote = $conn->prepare("DELETE FROM quotes WHERE id = ?");
            if ($stmtQuote) {
                $stmtQuote->bind_param('i', $quoteId);
                $stmtQuote->execute();
                $stmtQuote->close();
            }
            $conn->commit();
            header("Location: quotes.php?msg=deleted"); exit;
        } catch (Throwable $e) {
            $conn->rollback();
            error_log('quotes delete failed: ' . $e->getMessage());
            header("Location: quotes.php?msg=delete_failed"); exit;
        }
    }
}

$search = isset($_GET['q']) ? $conn->real_escape_string($_GET['q']) : '';
$where = "WHERE 1=1";
if(!empty($search)) $where .= " AND (q.id LIKE '%$search%' OR c.name LIKE '%$search%')";

$sql = "SELECT q.*, c.name as client_name, c.phone as client_phone FROM quotes q LEFT JOIN clients c ON q.client_id = c.id $where ORDER BY q.id DESC";
$res = $conn->query($sql);
?>

<style>
    :root { --gold: #d4af37; --gold-dark: #b8860b; --bg-dark: #0a0a0a; --card-bg: #161616; --border: #2a2a2a; }
    body { background-color: var(--bg-dark); font-family: 'Cairo', sans-serif; color: #e0e0e0; }

    /* تحسين الهيدر الملكي */
    .page-header { 
        display: flex; 
        justify-content: space-between; 
        align-items: center; 
        margin: 40px 0; 
        background: var(--card-bg);
        padding: 25px 40px; /* زيادة التباعد الداخلي لشكل أفخم */
        border-radius: 15px;
        border: 1px solid var(--border);
        flex-wrap: wrap;
        gap: 30px;
    }

    .header-actions { 
        display: flex; 
        gap: 60px; /* مسافة أمان كبيرة بين البحث والزر */
        align-items: center; 
        flex-grow: 1; 
        justify-content: flex-end; 
    }

    .search-box { position: relative; width: 350px; }
    .search-box input { 
        width: 100%; padding: 12px 45px 12px 15px; 
        background: #000; border: 1px solid var(--border); 
        color: #fff; border-radius: 10px; font-family: 'Cairo';
        transition: 0.3s;
    }
    .search-box input:focus { border-color: var(--gold); box-shadow: 0 0 10px rgba(212, 175, 55, 0.2); outline: none; }
    .search-box i { position: absolute; right: 15px; top: 15px; color: var(--gold); }

    /* الجداول الفاخرة المتباعدة */
    .royal-table-card { 
        background: var(--card-bg); border: 1px solid var(--border); 
        border-radius: 15px; padding: 10px; box-shadow: 0 20px 40px rgba(0,0,0,0.5); 
    }
    table { width: 100%; border-collapse: separate; border-spacing: 0 12px; }
    th { padding: 15px; color: var(--gold); font-size: 0.85rem; text-align: right; }
    td { padding: 20px 15px; background: #1c1c1c; border-top: 1px solid var(--border); border-bottom: 1px solid var(--border); }
    td:first-child { border-right: 1px solid var(--border); border-radius: 0 12px 12px 0; }
    td:last-child { border-left: 1px solid var(--border); border-radius: 12px 0 0 12px; }
    
    tr:hover td { background: #222; transform: scale(1.005); transition: 0.2s ease; }

    /* الأزرار */
    .btn-new { 
        background: linear-gradient(135deg, var(--gold), var(--gold-dark)); 
        color: #000; padding: 12px 30px; border-radius: 10px; 
        text-decoration: none; font-weight: 800; display: flex; align-items: center; gap: 10px; 
        white-space: nowrap; transition: 0.3s;
    }
    .btn-new:hover { transform: translateY(-3px); box-shadow: 0 10px 25px rgba(212, 175, 55, 0.4); }

    .action-btn { 
        width: 36px; height: 36px; border-radius: 8px; display: inline-flex; 
        align-items: center; justify-content: center; transition: 0.3s; font-size: 1rem; color: #fff;
        border: 1px solid #333; background: #222;
    }
    .btn-wa { color: #25D366; border-color: #25D366; }
    .btn-wa:hover { background: #25D366; color: #fff; }
    .btn-view { color: var(--gold); border-color: var(--gold); }
    .btn-view:hover { background: var(--gold); color: #000; }
    .btn-reset { color: #f39c12; border-color: #f39c12; } /* لون برتقالي لإعادة الضبط */
    .btn-reset:hover { background: #f39c12; color: #fff; }
    
    .badge { padding: 6px 15px; border-radius: 50px; font-size: 0.75rem; font-weight: 700; border: 1px solid transparent; }
    .status-pending { background: rgba(241, 196, 15, 0.1); color: #f1c40f; border-color: #f1c40f; }
    .status-approved { background: rgba(46, 204, 113, 0.1); color: #2ecc71; border-color: #2ecc71; }
    .status-rejected { background: rgba(231, 76, 60, 0.1); color: #e74c3c; border-color: #e74c3c; }
    
    @media (max-width: 768px) {
        .header-actions { flex-direction: column; width: 100%; gap: 15px; }
        .search-box { width: 100%; }
        .btn-new { width: 100%; justify-content: center; }
    }
</style>

<div class="container">
    <div class="page-header">
        <h2 class="page-title"><i class="fa-solid fa-file-invoice-dollar"></i> <?php echo app_h(app_tr('عروض الأسعار', 'Quotations')); ?></h2>
        
        <div class="header-actions">
            <form method="GET" class="search-box">
                <input type="text" name="q" value="<?php echo htmlspecialchars($search); ?>" placeholder="<?php echo app_h(app_tr('ابحث برقم العرض أو العميل...', 'Search by quote number or client...')); ?>">
                <i class="fa-solid fa-magnifying-glass"></i>
            </form>
            
            <?php if($canCreateQuote): ?><a href="add_quote.php" class="btn-new"><i class="fa-solid fa-plus-circle"></i> <?php echo app_h(app_tr('إنشاء عرض سعر', 'Create Quote')); ?></a><?php endif; ?>
        </div>
    </div>

    <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(180px,1fr)); gap:12px; margin-bottom:18px;">
        <a href="quote_dashboard.php" style="text-decoration:none; background:#161616; border:1px solid #2a2a2a; border-radius:12px; padding:14px; color:#eee;">
            <strong style="display:block;color:var(--gold);">لوحة متابعة العروض</strong>
            <small style="color:#888;">مؤشرات واحتمالات الإغلاق</small>
        </a>
        <div style="background:rgba(231,76,60,.08); border:1px solid rgba(231,76,60,.25); border-radius:12px; padding:14px;">منتهية: <b style="color:#ffb7b7;"><?php echo (int)$quoteAlerts['expired']; ?></b></div>
        <div style="background:rgba(241,196,15,.08); border:1px solid rgba(241,196,15,.25); border-radius:12px; padding:14px;">تنتهي قريباً: <b style="color:#ffe08a;"><?php echo (int)$quoteAlerts['soon']; ?></b></div>
        <div style="background:rgba(52,152,219,.08); border:1px solid rgba(52,152,219,.25); border-radius:12px; padding:14px;">اعتماد داخلي: <b style="color:#bfe6ff;"><?php echo (int)$quoteAlerts['internal_pending']; ?></b></div>
        <div style="background:rgba(46,204,113,.08); border:1px solid rgba(46,204,113,.25); border-radius:12px; padding:14px;">دفعة مقدمة: <b style="color:#bdf3cf;"><?php echo (int)$quoteAlerts['awaiting_deposit']; ?></b></div>
    </div>

    <?php if(isset($_GET['msg']) && $_GET['msg']=='reset'): ?>
        <div style="background: rgba(243, 156, 18, 0.1); color: #f39c12; padding: 15px; border-radius: 8px; margin-bottom: 20px; text-align: center; border: 1px dashed #f39c12;">
            <?php echo app_h(app_tr('🔄 تم إلغاء اعتماد العرض وإعادته للمراجعة بنجاح', '🔄 Quote status reset and returned for review successfully')); ?>
        </div>
    <?php endif; ?>
    <?php if(isset($_GET['msg']) && $_GET['msg']=='deleted'): ?>
        <div style="background: rgba(46, 204, 113, 0.1); color: #2ecc71; padding: 15px; border-radius: 8px; margin-bottom: 20px; text-align: center; border: 1px dashed #2ecc71;">
            <?php echo app_h(app_tr('🗑️ تم حذف عرض السعر بنجاح', '🗑️ Quotation deleted successfully')); ?>
        </div>
    <?php endif; ?>
    <?php if(isset($_GET['msg']) && $_GET['msg']=='delete_failed'): ?>
        <div style="background: rgba(231, 76, 60, 0.1); color: #e74c3c; padding: 15px; border-radius: 8px; margin-bottom: 20px; text-align: center; border: 1px dashed #e74c3c;">
            <?php echo app_h(app_tr('تعذر حذف العرض حالياً.', 'Could not delete quotation right now.')); ?>
        </div>
    <?php endif; ?>

    <?php if(isset($_GET['msg']) && $_GET['msg']=='review_closed'): ?>
        <div style="background: rgba(230, 126, 34, 0.1); color: #f8c291; padding: 15px; border-radius: 8px; margin-bottom: 20px; text-align: center; border: 1px dashed #e67e22;">
            تم إغلاق مراجعة العميل لهذا العرض.
        </div>
    <?php endif; ?>
    <?php if(isset($_GET['msg']) && $_GET['msg']=='review_opened'): ?>
        <div style="background: rgba(46, 204, 113, 0.1); color: #2ecc71; padding: 15px; border-radius: 8px; margin-bottom: 20px; text-align: center; border: 1px dashed #2ecc71;">
            تم فتح مراجعة العميل لهذا العرض.
        </div>
    <?php endif; ?>
    <?php if(isset($_GET['msg']) && $_GET['msg']=='duplicate_failed'): ?>
        <div style="background: rgba(231, 76, 60, 0.1); color: #e74c3c; padding: 15px; border-radius: 8px; margin-bottom: 20px; text-align: center; border: 1px dashed #e74c3c;">
            تعذر تكرار العرض حالياً.
        </div>
    <?php endif; ?>
    <?php if(isset($_GET['msg']) && in_array($_GET['msg'], ['internal_approved','stage_updated','sent_marked','updated'], true)): ?>
        <div style="background: rgba(46, 204, 113, 0.1); color: #2ecc71; padding: 15px; border-radius: 8px; margin-bottom: 20px; text-align: center; border: 1px dashed #2ecc71;">
            تم تحديث متابعة العرض بنجاح.
        </div>
    <?php endif; ?>

    <div class="royal-table-card">
        <div style="overflow-x:auto;">
            <table>
                <thead>
                    <tr>
                        <th><?php echo app_h(app_tr('الرقم', 'Number')); ?></th>
                        <th><?php echo app_h(app_tr('العميل', 'Client')); ?></th>
                        <th><?php echo app_h(app_tr('تاريخ العرض', 'Quote Date')); ?></th>
                        <th><?php echo app_h(app_tr('صالح لغاية', 'Valid Until')); ?></th>
                        <th><?php echo app_h(app_tr('القيمة', 'Amount')); ?></th>
                        <th><?php echo app_h(app_tr('الحالة', 'Status')); ?></th>
                        <th><?php echo app_h(app_tr('المتابعة', 'Follow-up')); ?></th>
                        <th style="text-align:center;"><?php echo app_h(app_tr('التحكم', 'Actions')); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if($res && $res->num_rows > 0): ?>
                        <?php while($row = $res->fetch_assoc()): 
                            $st_txt = [
                                'pending' => $isEnglish ? 'Pending' : 'قيد الانتظار',
                                'approved' => $isEnglish ? 'Approved ✅' : 'مقبول ✅',
                                'rejected' => $isEnglish ? 'Rejected ❌' : 'مرفوض ❌'
                            ];
                            $status_class = "status-" . ($row['status'] ?? 'pending');
                            $phone = preg_replace('/[^0-9]/', '', $row['client_phone'] ?? '');
                            if(substr($phone, 0, 1) == '0') $phone = '2'.$phone;
                            $link = app_quote_view_link($conn, $row);
                            if (empty($row['quote_number'])) {
                                $row['quote_number'] = app_assign_document_number($conn, 'quotes', (int)$row['id'], 'quote_number', 'quote', $row['created_at'] ?? date('Y-m-d'));
                            }
                            $quoteRef = trim((string)($row['quote_number'] ?? ''));
                            if ($quoteRef === '') {
                                $quoteRef = '#' . str_pad((string)$row['id'], 5, '0', STR_PAD_LEFT);
                            }
                            $waText = $isEnglish
                                ? "Dear *{$row['client_name']}*, here is quotation {$quoteRef} from {$appName}:\n$link"
                                : "شريكنا العزيز *{$row['client_name']}*، إليك عرض السعر {$quoteRef} من {$appName}:\n$link";
                            $wa_url = "https://api.whatsapp.com/send?phone=$phone&text=" . urlencode($waText);
                            $rowCurrency = app_quote_currency_from_quote($conn, $row);
                            $reviewClosed = trim((string)($row['quote_review_closed_at'] ?? '')) !== '';
                            $internalStatus = (string)($row['internal_approval_status'] ?? 'not_required');
                            $stageLabel = [
                                'client_review' => 'مراجعة العميل',
                                'awaiting_deposit' => 'بانتظار دفعة',
                                'ready_to_start' => 'جاهز للتنفيذ',
                                'invoiced' => 'تمت الفوترة',
                            ][(string)($row['fulfillment_stage'] ?? 'client_review')] ?? 'مراجعة العميل';
                        ?>
                        <tr>
                            <td style="color:var(--gold); font-family:monospace; font-weight:bold;"><?php echo app_h($quoteRef); ?></td>
                            <td>
                                <strong style="display:block;"><?php echo app_h((string)$row['client_name']); ?></strong>
                                <small style="color:#777;"><?php echo app_h((string)$row['client_phone']); ?></small>
                            </td>
                            <td><?php echo app_h((string)$row['created_at']); ?></td>
                            <td style="color:#e74c3c; font-weight:bold;"><?php echo app_h((string)$row['valid_until']); ?></td>
                            <td><b style="font-size:1.1rem; color:var(--gold);"><?php echo app_h(app_quote_format_money((float)$row['total_amount'], (string)($rowCurrency['code'] ?? 'EGP'))); ?></b></td>
                            <td><span class="badge <?php echo $status_class; ?>"><?php echo $st_txt[$row['status']] ?? $row['status']; ?></span></td>
                            <td>
                                <span style="display:block; color:#ddd; font-weight:700;"><?php echo app_h($stageLabel); ?></span>
                                <?php if($internalStatus === 'pending'): ?><small style="color:#f1c40f;">بانتظار اعتماد داخلي</small><?php elseif($internalStatus === 'approved'): ?><small style="color:#2ecc71;">معتمد داخلياً</small><?php endif; ?>
                            </td>
                            <td style="text-align:center;">
                                <div style="display:flex; justify-content:center; gap:8px;">
                                    <a href="<?php echo $wa_url; ?>" target="_blank" class="action-btn btn-wa" title="<?php echo app_h(app_tr('إرسال واتساب', 'Send WhatsApp')); ?>"><i class="fa-brands fa-whatsapp"></i></a>
                                    <form method="post" style="display:inline;">
                                        <?php echo app_csrf_input(); ?>
                                        <input type="hidden" name="quote_action" value="mark_sent">
                                        <input type="hidden" name="quote_id" value="<?php echo (int)$row['id']; ?>">
                                        <button type="submit" class="action-btn" style="color:#7fdbff; border-color:#7fdbff;" title="تسجيل الإرسال"><i class="fa-solid fa-paper-plane"></i></button>
                                    </form>
                                    <a href="<?php echo app_h($link); ?>" class="action-btn btn-view" title="<?php echo app_h(app_tr('عرض العرض', 'View quotation')); ?>"><i class="fa-solid fa-expand"></i></a>
                                    
                                    <?php if($row['status'] != 'pending'): ?>
                                        <form method="post" style="display:inline;" onsubmit="return confirm('<?php echo app_h(app_tr('هل تود إلغاء الحالة الحالية وإعادته للعميل للموافقة؟', 'Do you want to clear the current status and return it to the client for approval?')); ?>')">
                                            <?php echo app_csrf_input(); ?>
                                            <input type="hidden" name="quote_action" value="reset">
                                            <input type="hidden" name="quote_id" value="<?php echo (int)$row['id']; ?>">
                                            <button type="submit" class="action-btn btn-reset" title="<?php echo app_h(app_tr('إعادة فتح للمراجعة', 'Reopen for review')); ?>"><i class="fa-solid fa-rotate-left"></i></button>
                                        </form>
                                    <?php endif; ?>


                                        <form method="post" style="display:inline;" onsubmit="return confirm('<?php echo app_h(app_tr('إنشاء نسخة من عرض السعر؟', 'Duplicate this quotation?')); ?>')">
                                            <?php echo app_csrf_input(); ?>
                                            <input type="hidden" name="quote_action" value="duplicate">
                                            <input type="hidden" name="quote_id" value="<?php echo (int)$row['id']; ?>">
                                            <button type="submit" class="action-btn" style="color:#f1c40f; border-color:#f1c40f;" title="<?php echo app_h(app_tr('تكرار العرض', 'Duplicate quote')); ?>"><i class="fa-solid fa-copy"></i></button>
                                        </form>
                                        <?php if($canManageQuote): ?>
                                            <?php if($internalStatus === 'pending'): ?>
                                            <form method="post" style="display:inline;" onsubmit="return confirm('اعتماد العرض داخلياً؟')">
                                                <?php echo app_csrf_input(); ?>
                                                <input type="hidden" name="quote_action" value="internal_approve">
                                                <input type="hidden" name="quote_id" value="<?php echo (int)$row['id']; ?>">
                                                <button type="submit" class="action-btn" style="color:#2ecc71; border-color:#2ecc71;" title="اعتماد داخلي"><i class="fa-solid fa-user-check"></i></button>
                                            </form>
                                            <?php endif; ?>
                                            <?php if(($row['status'] ?? '') === 'approved'): ?>
                                            <form method="post" style="display:inline;">
                                                <?php echo app_csrf_input(); ?>
                                                <input type="hidden" name="quote_action" value="<?php echo ((string)($row['fulfillment_stage'] ?? '') === 'awaiting_deposit') ? 'ready_to_start' : 'awaiting_deposit'; ?>">
                                                <input type="hidden" name="quote_id" value="<?php echo (int)$row['id']; ?>">
                                                <button type="submit" class="action-btn" style="color:#9b59b6; border-color:#9b59b6;" title="تغيير مرحلة الدفعة"><i class="fa-solid fa-hand-holding-dollar"></i></button>
                                            </form>
                                            <?php endif; ?>
                                            <form method="post" style="display:inline;" onsubmit="return confirm('<?php echo app_h(app_tr('تغيير حالة مراجعة العميل؟', 'Change client review state?')); ?>')">
                                                <?php echo app_csrf_input(); ?>
                                                <input type="hidden" name="quote_action" value="<?php echo $reviewClosed ? 'open_review' : 'close_review'; ?>">
                                                <input type="hidden" name="quote_id" value="<?php echo (int)$row['id']; ?>">
                                                <?php if(!$reviewClosed): ?><input type="hidden" name="review_reason" value="تم إغلاق مراجعة العميل من إدارة العروض"><?php endif; ?>
                                                <button type="submit" class="action-btn" style="color:<?php echo $reviewClosed ? '#2ecc71' : '#e67e22'; ?>; border-color:<?php echo $reviewClosed ? '#2ecc71' : '#e67e22'; ?>;" title="<?php echo app_h($reviewClosed ? app_tr('فتح مراجعة العميل', 'Open client review') : app_tr('إغلاق مراجعة العميل', 'Close client review')); ?>"><i class="fa-solid <?php echo $reviewClosed ? 'fa-lock-open' : 'fa-lock'; ?>"></i></button>
                                            </form>
                                        <?php endif; ?>
                                    <?php if($canUpdateQuote): ?><a href="edit_quote.php?id=<?php echo $row['id']; ?>" class="action-btn" style="color:#3498db; border-color:#3498db;" title="<?php echo app_h(app_tr('تعديل', 'Edit')); ?>"><i class="fa-solid fa-pen-nib"></i></a><?php endif; ?>
                                    
                                    <?php if($my_role == 'admin'): ?>
                                        <form method="post" style="display:inline;" onsubmit="return confirm('<?php echo app_h(app_tr('حذف نهائي؟', 'Permanent delete?')); ?>')">
                                            <?php echo app_csrf_input(); ?>
                                            <input type="hidden" name="quote_action" value="delete">
                                            <input type="hidden" name="quote_id" value="<?php echo (int)$row['id']; ?>">
                                            <button type="submit" class="action-btn" style="color:#c0392b; border-color:#c0392b;" title="<?php echo app_h(app_tr('حذف', 'Delete')); ?>"><i class="fa-solid fa-trash-can"></i></button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr><td colspan="8" style="text-align:center; padding:50px; color:#555;"><?php echo app_h(app_tr('لا يوجد عروض حالياً.', 'No quotations available.')); ?></td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include 'footer.php'; ob_end_flush(); ?>
