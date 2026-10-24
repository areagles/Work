<?php
// suppliers.php - إدارة الموردين (النسخة الملكية V5.0 - تعديل + روابط)
ob_start();
error_reporting(E_ALL);

require 'auth.php'; 
require 'config.php'; 
require_once __DIR__ . '/modules/finance/receipts_runtime.php';
require 'header.php';

$supplierDeleteBlockedNotice = (string)($_SESSION['supplier_delete_blocked'] ?? '');
unset($_SESSION['supplier_delete_blocked']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !app_verify_csrf($_POST['_csrf_token'] ?? '')) {
    http_response_code(419);
    die('Invalid CSRF token');
}

app_ensure_suppliers_schema($conn);

/* ==================================================
   2. معالجة العمليات (إضافة - تعديل - حذف)
   ================================================== */

// A. الحذف
if($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_supplier']) && $_SESSION['role'] == 'admin'){
    $id = intval($_POST['supplier_id'] ?? 0);
    $deleteSummary = function_exists('financeEntityDeleteLinkSummary')
        ? financeEntityDeleteLinkSummary($conn, 'supplier', $id)
        : ['total' => 0, 'details' => []];
    if ((int)($deleteSummary['total'] ?? 0) > 0) {
        $_SESSION['supplier_delete_blocked'] = function_exists('financeEntityDeleteBlockedMessage')
            ? financeEntityDeleteBlockedMessage('supplier', $deleteSummary)
            : 'لا يمكن حذف المورد لأنه مرتبط بمستندات أو حركات مالية.';
        header("Location: suppliers.php?msg=linked");
        exit;
    }
    $stmt_del = $conn->prepare("DELETE FROM suppliers WHERE id = ?");
    $stmt_del->bind_param("i", $id);
    $stmt_del->execute();
    $stmt_del->close();
    header("Location: suppliers.php?msg=deleted"); exit;
}

// B. الإضافة / التعديل
if($_SERVER['REQUEST_METHOD'] == 'POST'){
    $name = trim((string)($_POST['name'] ?? ''));
    $category = trim((string)($_POST['category'] ?? ''));
    $phone = trim((string)($_POST['phone'] ?? ''));
    $email = trim((string)($_POST['email'] ?? ''));
    $address = trim((string)($_POST['address'] ?? ''));
    $contact = trim((string)($_POST['contact_person'] ?? ''));
    $opening = floatval($_POST['opening_balance']);
    $notes = trim((string)($_POST['notes'] ?? ''));

    if(isset($_POST['update_supplier'])){
        // تحديث
        $id = intval($_POST['supplier_id']);
        $stmt_up = $conn->prepare("
            UPDATE suppliers SET
                name = ?, category = ?, phone = ?, email = ?,
                address = ?, contact_person = ?, opening_balance = ?, notes = ?
            WHERE id = ?
        ");
        $stmt_up->bind_param("ssssssdsi", $name, $category, $phone, $email, $address, $contact, $opening, $notes, $id);
        if($stmt_up->execute()) { 
            $stmt_up->close();
            header("Location: suppliers.php?msg=updated"); exit; 
        }
        $stmt_up->close();
    } elseif(isset($_POST['add_supplier'])) {
        // إضافة جديد
        $token = bin2hex(random_bytes(16));
        $stmt_add = $conn->prepare("
            INSERT INTO suppliers (name, category, phone, email, address, contact_person, opening_balance, notes, access_token)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt_add->bind_param("ssssssdss", $name, $category, $phone, $email, $address, $contact, $opening, $notes, $token);
        if($stmt_add->execute()) { 
            $stmt_add->close();
            header("Location: suppliers.php?msg=success"); exit; 
        }
        $stmt_add->close();
    }
}

// C. جلب بيانات للتعديل
$edit_mode = false;
$s_edit = [];
if(isset($_GET['edit'])){
    $id = intval($_GET['edit']);
    $stmt_edit = $conn->prepare("SELECT * FROM suppliers WHERE id = ?");
    $stmt_edit->bind_param("i", $id);
    $stmt_edit->execute();
    $res = $stmt_edit->get_result();
    if($res->num_rows > 0){
        $edit_mode = true;
        $s_edit = $res->fetch_assoc();
    }
    $stmt_edit->close();
}

?>

<style>
    :root { --gold: #d4af37; --bg-dark: #0f0f0f; --card-bg: #1a1a1a; }
    body { background-color: var(--bg-dark); font-family: 'Cairo', sans-serif; color: #fff; }
    
    .page-header { display: flex; justify-content: space-between; align-items: center; margin: 30px 0; }
    .page-title { margin: 0; color: #fff; font-size: 1.8rem; display: flex; align-items: center; gap: 10px; }
    .page-title i { color: var(--gold); }

    .royal-form-card { background: var(--card-bg); padding: 30px; border-radius: 15px; border: 1px solid #333; border-top: 4px solid var(--gold); margin-bottom: 40px; }
    .supplier-modal { display:none; position:fixed; inset:0; z-index:2100; padding:22px; background:rgba(0,0,0,.88); backdrop-filter:blur(8px); overflow:auto; }
    .supplier-modal.is-open { display:flex; align-items:flex-start; justify-content:center; }
    .supplier-modal-card {
        width:min(860px, calc(100vw - 24px));
        margin:28px auto;
        background:linear-gradient(180deg, rgba(27,27,27,.98), rgba(14,14,14,.98));
        border:1px solid rgba(212,175,55,.28);
        border-top:4px solid var(--gold);
        border-radius:24px;
        padding:28px;
        box-shadow:0 24px 70px rgba(0,0,0,.58);
    }
    .supplier-modal-head { display:flex; align-items:flex-start; justify-content:space-between; gap:14px; border-bottom:1px solid rgba(255,255,255,.09); padding-bottom:16px; margin-bottom:20px; }
    .supplier-modal-title { margin:0; color:#fff; font-size:1.15rem; }
    .supplier-modal-close { width:40px; height:40px; border-radius:14px; border:1px solid rgba(255,255,255,.12); background:rgba(255,255,255,.06); color:#fff; cursor:pointer; }
    .supplier-modal-close:hover { background:var(--gold); color:#000; border-color:var(--gold); }
    
    .form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 20px; }
    
    .form-group label { display: block; color: var(--gold); margin-bottom: 8px; font-size: 0.9rem; font-weight: bold; }
    .form-control { width: 100%; background: #000; border: 1px solid #444; color: #fff; padding: 12px; border-radius: 8px; box-sizing: border-box; transition: 0.3s; }
    .form-control:focus { border-color: var(--gold); outline: none; box-shadow: 0 0 10px rgba(212, 175, 55, 0.2); }
    
    .btn-royal { width: 100%; padding: 15px; background: linear-gradient(45deg, var(--gold), #b8860b); border: none; font-weight: bold; font-size: 1rem; color: #000; border-radius: 8px; cursor: pointer; transition: 0.3s; }
    .btn-royal:hover { transform: translateY(-2px); box-shadow: 0 5px 15px rgba(212, 175, 55, 0.3); }
    .btn-cancel { background: #333; color: #ccc; margin-top: 10px; display:block; text-align:center; text-decoration:none; }
    .btn-supplier-add {
        display:inline-flex; align-items:center; justify-content:center; gap:8px; min-height:44px;
        padding:0 18px; border-radius:14px; border:1px solid rgba(212,175,55,.42);
        background:linear-gradient(135deg, var(--gold), #b8860b); color:#070707;
        font-weight:900; text-decoration:none; cursor:pointer; box-shadow:0 12px 24px rgba(212,175,55,.14);
    }
    .btn-supplier-add:hover { transform:translateY(-2px); box-shadow:0 16px 28px rgba(212,175,55,.2); }

    .badge-cat { background: rgba(52, 152, 219, 0.15); color: #3498db; padding: 5px 10px; border-radius: 15px; font-size: 0.8rem; border: 1px solid #3498db; }
    .balance-box { font-weight: 900; color: #e74c3c; font-size: 1rem; }
    .zero-balance { color: #2ecc71; }
    .suppliers-grid { display:grid; grid-template-columns:1fr; gap:20px; }
    .supplier-card {
        background:
            linear-gradient(180deg, rgba(255,255,255,0.06), rgba(255,255,255,0.02)),
            radial-gradient(circle at top left, rgba(212,175,55,0.12), transparent 34%),
            rgba(18,18,18,0.76);
        border: 1px solid rgba(255,255,255,0.08);
        border-radius: 22px;
        padding: 22px;
        position: relative;
        transition: all 0.3s ease;
        box-shadow: 0 16px 32px rgba(0,0,0,0.22);
        backdrop-filter: blur(14px);
    }
    .supplier-card:hover {
        transform: translateY(-5px);
        border-color: rgba(212,175,55,0.42);
        box-shadow: 0 14px 32px rgba(212, 175, 55, 0.12);
    }
    .supplier-card::after {
        content:"";
        position:absolute;
        inset-inline-end:-36px;
        inset-block-start:-36px;
        width:110px;
        height:110px;
        border-radius:50%;
        background:radial-gradient(circle, rgba(212,175,55,0.08), transparent 72%);
        pointer-events:none;
    }
    .s-header { display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:15px; gap:12px; }
    .s-identity { display:flex; gap:15px; align-items:flex-start; flex:1; }
    .s-avatar {
        width:54px; height:54px; background:rgba(212,175,55,0.1); color:var(--gold);
        border-radius:50%; display:flex; align-items:center; justify-content:center;
        font-size:1.2rem; border:1px solid rgba(212,175,55,0.3); box-shadow:0 10px 18px rgba(0,0,0,0.18);
    }
    .s-name { font-size:1.06rem; font-weight:bold; color:#fff; margin:0 0 5px 0; line-height:1.45; }
    .s-phone { font-size:0.86rem; color:#aaa; font-family:monospace; }
    .s-balance-box {
        background:rgba(6,6,6,0.76); border-radius:16px; padding:16px; margin:15px 0;
        text-align:center; border:1px dashed rgba(255,255,255,0.12);
    }
    .s-balance-val { font-size:1.4rem; font-weight:800; display:block; }
    .bal-pos { color:#e74c3c; }
    .bal-neg { color:#2ecc71; }
    .supplier-meta-grid { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:10px; margin-bottom:15px; }
    .supplier-meta-box {
        border-radius:14px; background:rgba(255,255,255,0.03); border:1px solid rgba(255,255,255,0.06);
        padding:12px; min-height:78px;
    }
    .supplier-meta-label { color:#9ca0a8; font-size:.72rem; margin-bottom:6px; }
    .supplier-meta-value { color:#f0f0f0; font-size:.83rem; line-height:1.6; overflow-wrap:anywhere; }
    .supplier-note { font-size:.74rem; color:#777; margin-top:10px; line-height:1.8; }
    .s-actions { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:10px; border-top:1px solid rgba(255,255,255,0.08); padding-top:15px; }
    .action-btn {
        background: rgba(255,255,255,0.04); color: #fff; border: 1px solid rgba(255,255,255,0.12); border-radius: 12px;
        height: 40px; display: flex; align-items: center; justify-content: center;
        text-decoration: none; font-size: 1rem; transition: 0.2s; cursor: pointer;
    }
    .action-btn:hover { background: var(--gold); color: #000; border-color: var(--gold); }
    .btn-wa { color: #25D366; border-color: rgba(37, 211, 102, 0.3); }
    .btn-wa:hover { background: #25D366; color: #fff; }
    .btn-copy { color: #3498db; border-color: rgba(52, 152, 219, 0.3); }
    .btn-copy:hover { background: #3498db; color: #fff; }
    .btn-edit { color: #f39c12; border-color: rgba(243, 156, 18, 0.3); }
    .btn-edit:hover { background: #f39c12; color: #000; }
    .btn-del { color: #e74c3c; border-color: rgba(231, 76, 60, 0.3); }
    .btn-del:hover { background: #e74c3c; color: #fff; }
    @media (max-width: 640px) {
        .page-header { align-items:stretch; flex-direction:column; gap:12px; }
        .supplier-meta-grid { grid-template-columns:1fr; }
        .supplier-modal-card { padding:18px; border-radius:18px; }
    }
</style>

<div class="container">
    <div class="page-header">
        <h2 class="page-title"><i class="fa-solid fa-truck-field"></i> إدارة الموردين</h2>
        <button type="button" class="btn-supplier-add" onclick="openSupplierModal('supplierFormModal')">
            <i class="fa-solid fa-plus"></i> تسجيل مورد جديد
        </button>
    </div>

    <?php if($supplierDeleteBlockedNotice !== ''): ?>
        <div style="margin:0 0 18px; background:rgba(231,76,60,0.12); border:1px solid rgba(231,76,60,0.55); color:#ffb0a8; padding:12px; border-radius:14px; font-weight:700;">
            <?php echo app_h($supplierDeleteBlockedNotice); ?>
        </div>
    <?php endif; ?>

    <div class="supplier-modal <?php echo $edit_mode ? 'is-open' : ''; ?>" id="supplierFormModal">
        <div class="supplier-modal-card" id="formArea">
        <div class="supplier-modal-head">
            <h3 class="supplier-modal-title">
                <?php echo $edit_mode ? '✏️ تعديل بيانات المورد' : '➕ تسجيل مورد جديد'; ?>
            </h3>
            <button type="button" class="supplier-modal-close" onclick="closeSupplierModal('supplierFormModal')" aria-label="إغلاق"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <form method="POST">
            <?php echo app_csrf_input(); ?>
            <?php if($edit_mode): ?>
                <input type="hidden" name="supplier_id" value="<?php echo (int)$s_edit['id']; ?>">
            <?php endif; ?>

            <div class="form-grid">
                <div class="form-group">
                    <label>اسم المورد / الشركة</label>
                    <input type="text" name="name" required class="form-control" value="<?php echo $edit_mode ? app_h($s_edit['name']) : ''; ?>" placeholder="اسم الشركة أو المورد">
                </div>
                <div class="form-group">
                    <label>التخصص (Category)</label>
                    <input type="text" name="category" list="cat_list" class="form-control" value="<?php echo $edit_mode ? app_h($s_edit['category']) : ''; ?>" placeholder="ورق، زنكات، أحبار...">
                    <datalist id="cat_list">
                        <option value="ورق وطباعة">
                        <option value="خامات بلاستيك">
                        <option value="زنكات وسلندرات">
                        <option value="نقل وشحن">
                        <option value="أدوات مكتبية">
                    </datalist>
                </div>
                <div class="form-group">
                    <label>الشخص المسؤول</label>
                    <input type="text" name="contact_person" class="form-control" value="<?php echo $edit_mode ? app_h($s_edit['contact_person']) : ''; ?>" placeholder="اسم المندوب">
                </div>
                <div class="form-group">
                    <label>رقم الهاتف</label>
                    <input type="text" name="phone" class="form-control" value="<?php echo $edit_mode ? app_h($s_edit['phone']) : ''; ?>" placeholder="01xxxxxxxxx">
                </div>
            </div>

            <div class="form-grid">
                <div class="form-group">
                    <label>البريد الإلكتروني</label>
                    <input type="email" name="email" class="form-control" value="<?php echo $edit_mode ? app_h($s_edit['email']) : ''; ?>" placeholder="example@company.com">
                </div>
                <div class="form-group">
                    <label>العنوان</label>
                    <input type="text" name="address" class="form-control" value="<?php echo $edit_mode ? app_h($s_edit['address']) : ''; ?>" placeholder="العنوان التفصيلي">
                </div>
                <div class="form-group">
                    <label>رصيد أول المدة</label>
                    <input type="number" step="0.01" name="opening_balance" class="form-control" value="<?php echo $edit_mode ? app_h($s_edit['opening_balance']) : '0.00'; ?>">
                </div>
            </div>

            <div class="form-group" style="margin-bottom: 20px;">
                <label>ملاحظات إضافية</label>
                <textarea name="notes" class="form-control" rows="2"><?php echo $edit_mode ? app_h($s_edit['notes']) : ''; ?></textarea>
            </div>

            <button type="submit" name="<?php echo $edit_mode ? 'update_supplier' : 'add_supplier'; ?>" class="btn-royal">
                <?php echo $edit_mode ? 'حفظ التعديلات ✅' : 'حفظ بيانات المورد 💾'; ?>
            </button>
            <?php if($edit_mode): ?>
                <a href="suppliers.php" class="btn-royal btn-cancel">إلغاء</a>
            <?php else: ?>
                <button type="button" onclick="closeSupplierModal('supplierFormModal')" class="btn-royal btn-cancel">إلغاء</button>
            <?php endif; ?>
        </form>
        </div>
    </div>

    <div class="suppliers-grid">
        <?php 
        $sql = "SELECT s.*, 
                (
                    SELECT IFNULL(SUM(total_amount), 0)
                    FROM purchase_invoices
                    WHERE supplier_id = s.id
                      AND COALESCE(status, '') <> 'cancelled'
                ) as total_purchases
                FROM suppliers s 
                ORDER BY s.id DESC";
        $sups = $conn->query($sql);
        if($sups && $sups->num_rows > 0):
            while($s = $sups->fetch_assoc()):
                $real_balance = (float)($s['current_balance'] ?? 0);
                $opening_balance = (float)($s['opening_balance'] ?? 0);
                $advance_credit = 0.0;
                $opening_outstanding = max(0.0, $opening_balance);
                $invoice_due = 0.0;
                $balance_kind = 'balanced';
                if (function_exists('financeSupplierBalanceSnapshot')) {
                    $supplierSnapshot = financeSupplierBalanceSnapshot($conn, (int)$s['id']);
                    $real_balance = (float)($supplierSnapshot['net_balance'] ?? $real_balance);
                    $advance_credit = (float)($supplierSnapshot['payment_credit'] ?? 0);
                    $opening_outstanding = (float)($supplierSnapshot['opening_outstanding'] ?? $opening_outstanding);
                    $invoice_due = (float)($supplierSnapshot['invoice_due'] ?? 0);
                    $balance_kind = (string)($supplierSnapshot['kind'] ?? $balance_kind);
                }
                $link = app_supplier_financial_review_link($conn, $s);
                $wa_msg = urlencode("السادة {$s['name']}،\nمرفق رابط كشف الحساب للمراجعة:\n$link");
                $wa_phone = preg_replace('/[^0-9]/', '', (string)$s['phone']);
                $due_amount = round(max(0.0, $real_balance), 2);
                $credit_amount = round(max(0.0, abs($real_balance)), 2);
                $primaryAmount = $balance_kind === 'due' ? $due_amount : ($balance_kind === 'credit' ? $credit_amount : 0.0);
                $balanceLabel = $balance_kind === 'due' ? 'المستحق الفعلي للمورد' : ($balance_kind === 'credit' ? 'رصيد دائن للمورد' : 'متوازن');
                $balanceClass = $balance_kind === 'due' ? 'bal-pos' : ($balance_kind === 'credit' ? 'bal-neg' : '');
                $netBalanceLabel = $balance_kind === 'credit'
                    ? 'صافي رصيد دائن'
                    : ($balance_kind === 'due' ? 'صافي مستحق' : 'صافي الرصيد');
        ?>
        <article class="supplier-card">
            <div class="s-header">
                <div class="s-identity">
                    <div class="s-avatar"><i class="fa-solid fa-truck-field"></i></div>
                    <div class="s-info">
                        <h3 class="s-name"><?php echo app_h($s['name']); ?></h3>
                        <div class="s-phone"><?php echo app_h($s['phone'] ?: '-'); ?></div>
                    </div>
                </div>
                <span class="badge-cat"><?php echo app_h($s['category'] ?: 'عام'); ?></span>
            </div>

            <div class="s-balance-box">
                <div style="color:#9ca0a8; font-size:.8rem; margin-bottom:8px;"><?php echo app_h($balanceLabel); ?></div>
                <span class="s-balance-val <?php echo app_h($balanceClass); ?>">
                    <?php echo number_format($primaryAmount, 2); ?> ج.م
                </span>
                <div style="margin-top:8px; color:#aab0b8; font-size:.76rem;">
                    <?php echo app_h($netBalanceLabel); ?>
                </div>
                <div style="display:flex; gap:8px; flex-wrap:wrap; justify-content:center; margin-top:10px;">
                    <?php if ($opening_outstanding > 0.00001): ?>
                        <span style="font-size:.72rem; color:#c8ced8; background:rgba(255,255,255,0.05); border:1px solid rgba(255,255,255,0.10); padding:3px 8px; border-radius:999px;">
                            أول المدة المسجل: <?php echo number_format($opening_outstanding, 2); ?>
                        </span>
                    <?php endif; ?>
                    <?php if ($invoice_due > 0.00001): ?>
                        <span style="font-size:.72rem; color:#9fd9ff; background:rgba(52,152,219,0.12); border:1px solid rgba(52,152,219,0.22); padding:3px 8px; border-radius:999px;">
                            فواتير غير مسددة: <?php echo number_format($invoice_due, 2); ?>
                        </span>
                    <?php endif; ?>
                    <?php if ($advance_credit > 0.00001): ?>
                        <span style="font-size:.72rem; color:#8cf0b1; background:rgba(46,204,113,0.12); border:1px solid rgba(46,204,113,0.22); padding:3px 8px; border-radius:999px;">
                            دفعات مقدمة: <?php echo number_format($advance_credit, 2); ?>
                        </span>
                    <?php endif; ?>
                </div>
            </div>

            <div class="supplier-meta-grid">
                <div class="supplier-meta-box">
                    <div class="supplier-meta-label">البريد الإلكتروني</div>
                    <div class="supplier-meta-value"><?php echo app_h(trim((string)($s['email'] ?? '')) !== '' ? (string)$s['email'] : 'غير مسجل'); ?></div>
                </div>
                <div class="supplier-meta-box">
                    <div class="supplier-meta-label">أول المدة المسجل</div>
                    <div class="supplier-meta-value"><?php echo number_format($opening_balance, 2); ?> ج.م</div>
                </div>
                <div class="supplier-meta-box">
                    <div class="supplier-meta-label">المسؤول</div>
                    <div class="supplier-meta-value"><?php echo app_h($s['contact_person'] ?: '-'); ?></div>
                </div>
                <div class="supplier-meta-box">
                    <div class="supplier-meta-label">العنوان</div>
                    <div class="supplier-meta-value"><?php echo app_h($s['address'] ?: '-'); ?></div>
                </div>
            </div>

            <div class="supplier-note">
                <?php if ($advance_credit > 0.00001): ?>
                    دفعات مقدمة: <?php echo number_format($advance_credit, 2); ?> ج.م
                <?php else: ?>
                    إجمالي المشتريات النشطة: <?php echo number_format((float)$s['total_purchases'], 2); ?> ج.م
                <?php endif; ?>
            </div>

            <div class="s-actions">
                <button onclick="copyLink('<?php echo app_h($link); ?>')" class="action-btn btn-copy" title="نسخ رابط البوابة"><i class="fa-solid fa-link"></i></button>
                <a href="https://wa.me/<?php echo app_h($wa_phone); ?>?text=<?php echo $wa_msg; ?>" target="_blank" class="action-btn btn-wa" title="إرسال واتساب"><i class="fa-brands fa-whatsapp"></i></a>
                <a href="?edit=<?php echo (int)$s['id']; ?>" class="action-btn btn-edit" title="تعديل"><i class="fa-solid fa-pen"></i></a>
                <?php if($_SESSION['role'] == 'admin'): ?>
                    <form method="POST" style="display:contents;" onsubmit="return confirm('حذف المورد سيحذف تاريخ تعاملاته. هل أنت متأكد؟');">
                        <?php echo app_csrf_input(); ?>
                        <input type="hidden" name="supplier_id" value="<?php echo (int)$s['id']; ?>">
                        <button type="submit" name="delete_supplier" value="1" class="action-btn btn-del" title="حذف"><i class="fa-solid fa-trash-can"></i></button>
                    </form>
                <?php else: ?>
                    <span class="action-btn" style="opacity:.35; cursor:default;"><i class="fa-solid fa-lock"></i></span>
                <?php endif; ?>
            </div>
        </article>
        <?php endwhile; else: ?>
            <div class="royal-form-card" style="text-align:center; padding:30px; color:#666;">لا يوجد موردين مسجلين.</div>
        <?php endif; ?>
    </div>
</div>

<script>
function openSupplierModal(id) {
    const modal = document.getElementById(id);
    if (!modal) return;
    modal.classList.add('is-open');
    document.body.style.overflow = 'hidden';
}
function closeSupplierModal(id) {
    const modal = document.getElementById(id);
    if (!modal) return;
    modal.classList.remove('is-open');
    document.body.style.overflow = '';
    <?php if($edit_mode): ?>
    if (id === 'supplierFormModal') window.location.href = 'suppliers.php';
    <?php endif; ?>
}
document.addEventListener('keydown', function(event) {
    if (event.key === 'Escape') closeSupplierModal('supplierFormModal');
});
document.getElementById('supplierFormModal')?.addEventListener('click', function(event) {
    if (event.target === this) closeSupplierModal('supplierFormModal');
});
<?php if($edit_mode): ?>
document.body.style.overflow = 'hidden';
<?php endif; ?>
function copyLink(text) {
    navigator.clipboard.writeText(text).then(function() {
        alert('تم نسخ رابط بوابة المورد! 📋');
    }, function(err) {
        console.error('فشل النسخ: ', err);
    });
}
</script>

<?php include 'footer.php'; ob_end_flush(); ?>
