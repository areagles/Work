<?php
// finance.php - (Royal Finance V26.0 - Smart FIFO Engine & Mobile AI)
ob_start();
error_reporting(E_ALL);

require 'auth.php'; 
require 'config.php'; 
require_once 'finance_engine.php';
require_once __DIR__ . '/modules/finance/party_ledger_runtime.php';
app_handle_lang_switch($conn);

$canFinancePage = app_user_can_any([
    'finance.view',
    'finance.transactions.view',
    'finance.transactions.create',
    'finance.transactions.update',
    'finance.transactions.delete',
]);
$canViewTransactions = app_user_can_any([
    'finance.transactions.view',
    'finance.transactions.create',
    'finance.transactions.update',
    'finance.transactions.delete',
]);
$canCreateTransactions = app_user_can('finance.transactions.create');
$canUpdateTransactions = app_user_can('finance.transactions.update');
$canDeleteTransactions = app_user_can('finance.transactions.delete');

if (!$canFinancePage) {
    http_response_code(403);
    require 'header.php';
    echo "<div class='container page-shell' style='margin-top:30px;'><div class='alert alert-danger'>" . app_h(app_tr('غير مصرح لك بالدخول إلى الإدارة المالية.', 'You are not authorized to access finance management.')) . "</div></div>";
    require 'footer.php';
    exit;
}

require 'header.php';
financeEnsureAllocationSchema($conn);

$csrfToken = app_csrf_token();
$themeColor = app_normalize_hex_color(app_setting_get($conn, 'theme_color', '#d4af37'));

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    app_require_csrf();
}

// 1. الذكاء السياقي: القيم الافتراضية
$default_type = isset($_GET['def_type']) ? $_GET['def_type'] : 'in';
$default_cat  = isset($_GET['def_cat'])  ? $_GET['def_cat']  : 'general';
$default_emp  = isset($_GET['emp_id'])   ? intval($_GET['emp_id']) : '';
$default_pid  = isset($_GET['payroll_id']) ? intval($_GET['payroll_id']) : ''; 
$default_inv  = isset($_GET['invoice_id']) ? intval($_GET['invoice_id']) : ''; 
$default_sup  = isset($_GET['supplier_id']) ? intval($_GET['supplier_id']) : ''; 
$default_tax_law = isset($_GET['tax_law_key']) ? trim((string)$_GET['tax_law_key']) : '';

// العنوان والأيقونة
$page_title = "تسجيل حركة مالية";
$icon_class = "fa-solid fa-pen-to-square";

if($default_cat == 'salary' || $default_cat == 'loan') {
    $page_title = "صرف راتب / سلفة موظف";
    $icon_class = "fa-solid fa-user-clock";
} elseif($default_cat == 'tax') {
    $page_title = "سداد ضريبة";
    $icon_class = "fa-solid fa-file-invoice-dollar";
} elseif($default_cat == 'supplier') {
    $page_title = "تسجيل فاتورة مشتريات / سداد مورد";
    $icon_class = "fa-solid fa-truck-field";
}

/* ==================================================
   2. معالجة الإجراءات (Actions)
   ================================================== */
$edit_mode = false;
$edit_data = [];
$msg = "";
$last_id = 0; 
$focusMode = trim((string)($_GET['focus'] ?? ''));

if(isset($_GET['edit'])){
    $id = intval($_GET['edit']);
    if (!$canUpdateTransactions) {
        $msg = app_tr("لا تملك صلاحية تعديل الحركات المالية.", "You do not have permission to update financial transactions.");
    } else {
        $res = $conn->query("SELECT * FROM financial_receipts WHERE id=$id");
        if($res && $res->num_rows > 0){
            $edit_mode = true;
            $edit_data = $res->fetch_assoc();
            $default_type = $edit_data['type'];
            $default_cat = $edit_data['category'];
            $default_tax_law = trim((string)($edit_data['tax_law_key'] ?? ''));
            $page_title = "تعديل حركة (" . ($default_type=='in'?'قبض':'صرف') . ")";
        }
    }
}

$editAllocationSummary = [];
if ($edit_mode) {
    $editAllocationSummary = financeReceiptAllocationSummary($conn, $edit_data);
}

if(isset($_GET['duplicate'])){
    $id = intval($_GET['duplicate']);
    if (!$canCreateTransactions) {
        $msg = app_tr("لا تملك صلاحية إضافة حركة مالية جديدة.", "You do not have permission to create financial transactions.");
    } else {
        $res = $conn->query("SELECT * FROM financial_receipts WHERE id=$id");
        if($res && $res->num_rows > 0){
            $edit_mode = false; 
            $edit_data = $res->fetch_assoc();
            $default_type = $edit_data['type'];
            $default_cat = $edit_data['category'];
            $default_sup = $edit_data['supplier_id'];
            $default_emp = $edit_data['employee_id'];
            $default_tax_law = trim((string)($edit_data['tax_law_key'] ?? ''));
            $page_title = "تكرار حركة مالية";
            $msg = "تم نسخ بيانات الحركة السابقة، يرجى مراجعة المبلغ والتاريخ ثم الحفظ.";
        }
    }
}

if($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_trans'])){
    if (!$canDeleteTransactions) {
        http_response_code(403);
        $msg = app_tr("لا تملك صلاحية حذف الحركات المالية.", "You do not have permission to delete financial transactions.");
    } else {
        $deleteResult = financeTransactionDeleteService($conn, intval($_POST['trans_id'] ?? 0));
        if (!empty($deleteResult['ok'])) {
            header("Location: " . ($deleteResult['redirect'] ?? 'finance.php?msg=deleted')); exit;
        }
        $msg = (string)($deleteResult['message'] ?? app_tr('فشل حذف الحركة المالية.', 'Failed to delete financial transaction.'));
    }
}

if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['save_trans'])){
    $isUpdateRequest = isset($_POST['trans_id']) && !empty($_POST['trans_id']);
    if ($isUpdateRequest && !$canUpdateTransactions) {
        $msg = app_tr("لا تملك صلاحية تعديل الحركة المالية.", "You do not have permission to update this transaction.");
    } elseif (!$isUpdateRequest && !$canCreateTransactions) {
        $msg = app_tr("لا تملك صلاحية إضافة حركة مالية.", "You do not have permission to create financial transactions.");
    }

    if ($msg === '') {
        $saveResult = financeTransactionSaveService($conn, $_POST, (string)($_SESSION['name'] ?? 'Admin'));
        if (!empty($saveResult['ok'])) {
            header("Location: " . ($saveResult['redirect'] ?? 'finance.php')); exit;
        }
        $msg = (string)($saveResult['message'] ?? app_tr('فشل حفظ الحركة المالية.', 'Failed to save financial transaction.'));
    }
}

$financeStats = finance_dashboard_stats($conn);
$total_in = (float)$financeStats['total_in'];
$total_out = (float)$financeStats['total_out'];
$net = (float)$financeStats['net'];
$monthStart = (string)$financeStats['month_start'];
$monthly_in = (float)$financeStats['monthly_in'];
$monthly_out = (float)$financeStats['monthly_out'];
$monthly_net = (float)$financeStats['monthly_net'];
$avg_daily_out_90 = (float)$financeStats['avg_daily_out_90'];
$cash_runway_days = $financeStats['cash_runway_days'];
$payroll_due = (float)$financeStats['payroll_due'];
$purchases_due = (float)$financeStats['purchases_due'];
$receivables_due = (float)$financeStats['receivables_due'];
$finance_signal = (string)$financeStats['finance_signal'];
$financeOptions = finance_form_options($conn);
$journalRows = finance_journal_entries($conn, 100);
?>

<style>
    /* ==================================================
       تصميم محسن - الهوية البصرية (أسود فحمي × ذهبي)
       ================================================== */
    :root { 
        --gold: <?php echo app_h($themeColor); ?>;
        --gold-glow: rgba(212, 175, 55, 0.15);
        --card-bg: #141414; 
        --bg-dark: #0a0a0a; 
        --surface: #1e1e1e;
        --border-color: #2a2a2a;
    }
    body { background-color: var(--bg-dark); color: #fff; font-family: 'Cairo', sans-serif; margin: 0; padding-bottom: 80px; }
    
    .container { max-width: 1440px; margin: 0 auto; padding: 20px; }
    .finance-shell { display:grid; gap:18px; }
    .finance-hero {
        display:grid;
        grid-template-columns:minmax(0,1.15fr) minmax(320px,0.85fr);
        gap:18px;
        align-items:stretch;
    }
    .glass-panel {
        position:relative;
        overflow:hidden;
        background:
            linear-gradient(180deg, rgba(255,255,255,0.06), rgba(255,255,255,0.02)),
            radial-gradient(circle at top left, rgba(212,175,55,0.12), transparent 34%),
            rgba(18,18,18,0.88);
        border:1px solid rgba(212,175,55,0.16);
        border-radius:22px;
        padding:22px;
        box-shadow:0 18px 38px rgba(0,0,0,0.24);
        backdrop-filter:blur(14px);
    }
    .glass-panel::after {
        content:"";
        position:absolute;
        inset-inline-end:-56px;
        inset-block-start:-56px;
        width:160px;
        height:160px;
        border-radius:50%;
        background:radial-gradient(circle, rgba(212,175,55,0.1), transparent 70%);
        pointer-events:none;
    }
    .finance-eyebrow {
        display:inline-flex; align-items:center; gap:8px; padding:6px 12px; border-radius:999px;
        background:rgba(212,175,55,0.08); border:1px solid rgba(212,175,55,0.24); color:#f0d684;
        font-size:.76rem; font-weight:700; margin-bottom:14px;
    }
    .finance-title { margin:0; color:#f7f1dc; font-size:1.85rem; line-height:1.3; }
    .finance-subtitle { margin:10px 0 0; color:#a8abb1; line-height:1.8; max-width:760px; }
    
    /* KPI Grid */
    .kpi-wrapper { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 12px; margin-top: 15px; margin-bottom: 0; }
    .kpi-box { 
        background: rgba(255,255,255,0.035); padding: 18px; border-radius: 18px; border: 1px solid rgba(255,255,255,0.08);
        display: flex; flex-direction: column; align-items: flex-start; position: relative; overflow: hidden;
        box-shadow: none; transition: transform 0.3s;
    }
    .kpi-box:hover { transform: translateY(-3px); }
    .kpi-box i { position: absolute; left: 20px; top: 50%; transform: translateY(-50%); font-size: 3rem; opacity: 0.1; }
    .kpi-box h4 { margin: 0 0 10px; color: #aaa; font-size: 1rem; font-weight: normal; }
    .kpi-box .num { font-size: 1.8rem; font-weight: 900; }
    .smart-finance-card {
        background: linear-gradient(140deg, rgba(212, 175, 55, 0.08), rgba(30, 30, 30, 0.9));
        border: 1px solid rgba(212, 175, 55, 0.2);
        border-radius: 16px;
        padding: 16px 18px;
        margin-bottom: 22px;
    }
    .smart-finance-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px; }
    .smart-kpi { background: rgba(0,0,0,0.25); border: 1px solid #2e2e2e; border-radius: 12px; padding: 10px; }
    .smart-kpi .label { color: #9f9f9f; font-size: 0.8rem; margin-bottom: 6px; }
    .smart-kpi .value { color: #fff; font-weight: 800; font-size: 1.1rem; }

    /* Layout */
    .main-layout { display: grid; grid-template-columns: minmax(360px, 420px) minmax(0, 1fr); gap: 20px; }
    .opening-balance-note {
        margin-top: 12px;
        padding: 10px 12px;
        border-radius: 10px;
        border: 1px solid rgba(52, 152, 219, 0.28);
        background: rgba(52, 152, 219, 0.08);
        color: #9fd9ff;
        font-size: .84rem;
        line-height: 1.7;
    }
    
    .panel { 
        background:
            linear-gradient(180deg, rgba(255,255,255,0.05), rgba(255,255,255,0.02)),
            rgba(18,18,18,0.8);
        padding: 24px; border-radius: 22px;
        border: 1px solid rgba(255,255,255,0.08); height: fit-content; position: sticky; top: 20px;
        box-shadow: 0 14px 30px rgba(0,0,0,0.26); backdrop-filter: blur(14px);
    }
    
    /* Form Elements */
    .form-group { margin-bottom: 18px; }
    .form-group label { display: block; margin-bottom: 8px; font-size: 0.9rem; color: #ccc; }
    input, select, textarea { 
        width: 100%; background: var(--surface); border: 1px solid var(--border-color); color: #fff; 
        padding: 14px; border-radius: 10px; font-family: 'Cairo'; box-sizing: border-box; transition: 0.3s;
    }
    input:focus, select:focus, textarea:focus { border-color: var(--gold); outline: none; box-shadow: 0 0 0 3px var(--gold-glow); }
    
    /* Quick Amount Buttons */
    .quick-amounts { display: flex; gap: 8px; margin-top: 8px; }
    .quick-btn { 
        background: var(--surface); border: 1px solid var(--border-color); color: #aaa; 
        padding: 6px 12px; border-radius: 6px; cursor: pointer; font-size: 0.8rem; transition: 0.2s;
    }
    .quick-btn:hover { background: var(--gold-glow); color: var(--gold); border-color: var(--gold); }

    .btn-submit { 
        width: 100%; padding: 16px; margin-top: 10px;
        background: linear-gradient(135deg, var(--gold), #b8860b); border: none; font-weight: bold; 
        border-radius: 12px; cursor: pointer; color: #000; font-size: 1.1rem; box-shadow: 0 4px 15px var(--gold-glow);
        transition: 0.3s;
    }
    .btn-submit:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(212, 175, 55, 0.4); }

    /* Smart Tabs & Filter Area */
    .journal-panel {
        background:
            linear-gradient(180deg, rgba(255,255,255,0.05), rgba(255,255,255,0.02)),
            rgba(18,18,18,0.72);
        border:1px solid rgba(255,255,255,0.08);
        border-radius:22px;
        padding:18px;
        backdrop-filter:blur(12px);
    }
    .filter-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; gap: 15px; flex-wrap: wrap; }
    .search-bar { flex: 1; min-width: 250px; position: relative; }
    .search-bar input { margin: 0; padding-right: 40px; border-radius: 30px; }
    .search-bar i { position: absolute; right: 15px; top: 50%; transform: translateY(-50%); color: #888; }
    
    .type-filters { display: flex; gap: 10px; background: var(--card-bg); padding: 5px; border-radius: 30px; border: 1px solid var(--border-color); }
    .t-btn { 
        background: transparent; border: none; color: #888; padding: 8px 20px; border-radius: 25px; 
        cursor: pointer; font-family: 'Cairo'; font-weight: bold; transition: 0.3s;
    }
    .t-btn.active { background: var(--surface); color: var(--gold); box-shadow: 0 2px 8px rgba(0,0,0,0.2); }

    /* Scrollable Journal Area */
    .journal-scroll-area { 
        max-height: 750px; overflow-y: auto; padding-right: 10px; 
        scrollbar-width: thin; scrollbar-color: var(--gold) var(--surface);
    }
    .journal-scroll-area::-webkit-scrollbar { width: 6px; }
    .journal-scroll-area::-webkit-scrollbar-track { background: var(--surface); border-radius: 10px; }
    .journal-scroll-area::-webkit-scrollbar-thumb { background: var(--gold); border-radius: 10px; }

    .journal-row {
        background:
            linear-gradient(180deg, rgba(255,255,255,0.05), rgba(255,255,255,0.02)),
            rgba(18,18,18,0.72);
        border: 1px solid rgba(255,255,255,0.08); padding: 18px; border-radius: 18px;
        margin-bottom: 12px; display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; 
        box-shadow: 0 2px 10px rgba(0,0,0,0.1); transition: 0.2s; position: relative; overflow: hidden;
        backdrop-filter: blur(12px);
    }
    .journal-row::before { content: ''; position: absolute; right: 0; top: 0; bottom: 0; width: 4px; background: transparent; transition: 0.3s; }
    .journal-row:hover { transform: translateX(-5px); border-color: #444; }
    .journal-row:hover::before { background: var(--gold); }
    
    .j-info { flex: 1; min-width: 200px; }
    .j-date { font-size: 0.85rem; color: #888; display: flex; align-items: center; gap: 8px; margin-bottom: 6px; }
    .j-desc { font-weight: bold; font-size: 1.1rem; margin: 0 0 8px 0; color: #f5f5f5; }
    .j-meta { font-size: 0.85rem; display: flex; gap: 12px; flex-wrap: wrap; }
    
    .j-amount { text-align: left; min-width: 120px; font-weight: 900; font-size: 1.4rem; }
    .j-actions { width: 100%; margin-top: 12px; padding-top: 12px; border-top: 1px dashed var(--border-color); display: flex; justify-content: flex-end; gap: 20px; }
    .j-actions a { color: #888; text-decoration: none; font-size: 0.9rem; display: flex; align-items: center; gap: 6px; transition: 0.2s; }
    .j-actions a:hover { color: var(--gold); }

    .tag { padding: 4px 10px; border-radius: 6px; font-size: 0.75rem; font-weight: bold; }
    .tag.in { background: rgba(46, 204, 113, 0.1); color: #2ecc71; border: 1px solid rgba(46, 204, 113, 0.2); }
    .tag.out { background: rgba(231, 76, 60, 0.1); color: #e74c3c; border: 1px solid rgba(231, 76, 60, 0.2); }

    .hidden { display: none; }
    
    .toast-undo {
        position: fixed; bottom: 20px; left: 50%; transform: translateX(-50%);
        background: #111; border: 1px solid var(--gold); padding: 15px 25px; border-radius: 50px;
        box-shadow: 0 10px 40px rgba(0,0,0,0.6); z-index: 9999; display: flex; align-items: center; gap: 15px;
        animation: slideUp 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
    }
    @keyframes slideUp { from {bottom: -60px; opacity:0;} to {bottom: 20px; opacity:1;} }

    @media (max-width: 992px) {
        .finance-hero,
        .main-layout { grid-template-columns: 1fr; }
        .panel { position: static; margin-bottom: 20px; }
        .j-actions { justify-content: space-between; }
        .journal-scroll-area { max-height: none; overflow-y: visible; }
        .kpi-wrapper { grid-template-columns: 1fr; }
    }
</style>

<div class="container page-shell">
    <div class="finance-shell">
    <section class="finance-hero">
        <div class="glass-panel">
            <div class="finance-eyebrow">الإدارة المالية</div>
            <h2 class="finance-title">السجل المالي والتحكم في الحركة النقدية</h2>
            <p class="finance-subtitle">شاشة موحدة لتسجيل القبض والصرف، ومتابعة المؤشرات المالية، ومراجعة السجل اليومي بنفس الهوية الرسمية الحديثة.</p>
        </div>
        <div class="smart-finance-card ai-glass">
            <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:10px;">
                <h4 style="margin:0;color:var(--gold);display:flex;align-items:center;gap:8px;"><i class="fa-solid fa-chart-line"></i> <?php echo app_h(app_tr('المؤشرات المالية', 'Financial Indicators')); ?></h4>
                <span class="ai-pill">الحالة: <?php echo app_h($finance_signal); ?></span>
            </div>
            <div class="smart-finance-grid">
                <div class="smart-kpi"><div class="label">صافي الشهر الحالي</div><div class="value"><?php echo number_format($monthly_net, 2); ?> ج.م</div></div>
                <div class="smart-kpi"><div class="label">مستحقات الرواتب</div><div class="value"><?php echo number_format($payroll_due, 2); ?> ج.م</div></div>
                <div class="smart-kpi"><div class="label">مستحقات الموردين</div><div class="value"><?php echo number_format($purchases_due, 2); ?> ج.م</div></div>
                <div class="smart-kpi"><div class="label">متوقع التحصيل</div><div class="value"><?php echo number_format($receivables_due, 2); ?> ج.م</div></div>
                <div class="smart-kpi"><div class="label">مدى التغطية النقدية</div><div class="value"><?php echo $cash_runway_days === null ? 'غير كافٍ للحساب' : app_h((string)$cash_runway_days) . ' يوم'; ?></div></div>
            </div>
        </div>
    </section>
    
    <div class="glass-panel">
    <div class="kpi-wrapper">
        <div class="kpi-box">
            <i class="fa-solid fa-arrow-down" style="color:#2ecc71;"></i>
            <h4>إجمالي القبض (الوارد)</h4>
            <div class="num" style="color:#2ecc71"><?php echo number_format($total_in); ?> <span style="font-size:1rem; font-weight:normal; color:#666;">ج.م</span></div>
        </div>
        <div class="kpi-box">
            <i class="fa-solid fa-arrow-up" style="color:#e74c3c;"></i>
            <h4>إجمالي الصرف (الصادر)</h4>
            <div class="num" style="color:#e74c3c"><?php echo number_format($total_out); ?> <span style="font-size:1rem; font-weight:normal; color:#666;">ج.م</span></div>
        </div>
        <div class="kpi-box">
            <i class="fa-solid fa-vault" style="color:var(--gold);"></i>
            <h4>صافي الخزينة الحالي</h4>
            <div class="num" style="color:var(--gold)"><?php echo number_format($net); ?> <span style="font-size:1rem; font-weight:normal; color:#666;">ج.م</span></div>
        </div>
    </div>
    </div>
    </div>
    
    <?php if(isset($_GET['msg']) && isset($_GET['lid'])): ?>
    <div class="toast-undo">
        <span style="color:#2ecc71;"><i class="fa-solid fa-check-circle"></i> تم الحفظ بنجاح</span>
        <?php if ($canViewTransactions): ?>
            <a href="print_finance_voucher.php?id=<?php echo (int)$_GET['lid']; ?>" target="_blank" rel="noopener" style="color:var(--gold); font-weight:bold; text-decoration:none; border-right:1px solid #444; padding-right:15px;">
                <i class="fa-solid fa-print"></i> طباعة السند
            </a>
        <?php endif; ?>
        <?php if ($canDeleteTransactions): ?>
            <form method="post" style="display:inline; margin:0; border-right:1px solid #444; padding-right:15px;" onsubmit="return confirm('<?php echo app_h(app_tr('تنبيه: سيتم حذف العملية وإعادة حساب الفواتير المرتبطة بها. هل أنت متأكد؟', 'Warning: This transaction will be deleted and linked invoices will be recalculated. Are you sure?')); ?>')">
                <?php echo app_csrf_input(); ?>
                <input type="hidden" name="trans_id" value="<?php echo (int)$_GET['lid']; ?>">
                <button type="submit" name="delete_trans" style="color:#e74c3c; font-weight:bold; text-decoration:none; background:none; border:0; cursor:pointer; font:inherit;">
                    <i class="fa-solid fa-rotate-left"></i> تراجع عن العملية
                </button>
            </form>
        <?php endif; ?>
        <button onclick="this.parentElement.remove()" style="background:none; border:none; color:#888; cursor:pointer; font-size: 1.2rem;">✕</button>
    </div>
    <?php endif; ?>

    <div class="main-layout">
        
        <div class="panel">
            <h3 style="color:var(--gold); margin-top:0; margin-bottom: 25px; display: flex; align-items: center; gap: 10px;">
                <div style="background: var(--gold-glow); width: 40px; height: 40px; border-radius: 10px; display: flex; align-items: center; justify-content: center;">
                    <i class="<?php echo $icon_class; ?>" style="font-size: 1.2rem;"></i>
                </div>
                <span><?php echo $page_title; ?></span>
            </h3>

            <?php if ($edit_mode && financeReceiptIsOpeningBalance($conn, $edit_data ?? [])): ?>
                <div class="opening-balance-note">
                    <?php echo app_h(app_tr('هذا سند افتتاحي مرتبط برصيد أول المدة. عند تعديله أو حذفه سيقوم النظام بمزامنة الرصيد الافتتاحي للطرف المرتبط تلقائيًا.', 'This is an opening-balance linked voucher. Updating or deleting it will automatically synchronize the linked party opening balance.')); ?>
                </div>
            <?php endif; ?>
            
            <?php 
                if($msg !== '') echo "<div class='alert alert-danger'><p style='font-size:0.9rem; margin:0;'>" . app_h($msg) . "</p></div>";
                if(isset($_GET['msg']) && $_GET['msg']=='auto') echo "<div class='alert alert-warning'><p style='font-size:0.9rem; margin:0;'>" . app_h(app_tr('تم توزيع المبلغ آلياً على الفواتير القديمة (FIFO).', 'The amount was allocated automatically to older invoices (FIFO).')) . "</p></div>"; 
                if(isset($_GET['msg']) && $_GET['msg']=='auto_sup') echo "<div class='alert alert-warning'><p style='font-size:0.9rem; margin:0;'>" . app_h(app_tr('تم سداد فواتير المورد القديمة آلياً (FIFO).', 'Older supplier invoices were settled automatically (FIFO).')) . "</p></div>";
                if(isset($_GET['msg']) && $_GET['msg']=='auto_emp') echo "<div class='alert alert-warning'><p style='font-size:0.9rem; margin:0;'>" . app_h(app_tr('تم صرف الرواتب المتأخرة آلياً (FIFO).', 'Delayed payroll was processed automatically (FIFO).')) . "</p></div>";
            ?>

            <form method="POST" id="finance-form">
                <?php echo app_csrf_input(); ?>
                <?php if($edit_mode): ?><input type="hidden" name="trans_id" value="<?php echo $edit_data['id']; ?>"><?php endif; ?>

                <?php if (
                    $edit_mode
                    && (($edit_data['type'] ?? '') === 'in')
                    && (int)($edit_data['client_id'] ?? 0) > 0
                    && (float)($editAllocationSummary['unallocated_amount'] ?? 0) > 0.00001
                ): ?>
                    <div class="opening-balance-note" style="margin-top:0; border-color:rgba(212,175,55,.28); background:rgba(212,175,55,.08); color:#f7e3a1;">
                        <?php echo app_h(app_tr('يوجد فائض غير موزع على هذا السند. يمكنك ربطه بفاتورة محددة من قائمة الفواتير أو ترك الحقل فارغًا ليستخدم التوزيع التلقائي للأقدم (FIFO) عند الحفظ.', 'This receipt has unallocated excess. You can link it to a specific invoice from the invoice list, or leave it empty to use automatic oldest-first (FIFO) allocation when saving.')); ?>
                        <strong style="display:block; margin-top:6px;">
                            <?php echo app_h(app_tr('الفائض الحالي', 'Current excess')); ?>:
                            <?php echo number_format((float)($editAllocationSummary['unallocated_amount'] ?? 0), 2); ?> EGP
                        </strong>
                    </div>
                <?php endif; ?>

                <div class="form-group" style="<?php echo isset($_GET['def_type']) ? 'opacity:0.7;' : ''; ?>">
                    <label>نوع الحركة</label>
                    <select name="type" id="t_type" onchange="toggleFields()">
                        <option value="in" <?php if($default_type=='in') echo 'selected'; ?>><?php echo app_h(app_tr('قبض (إيداع في الخزينة)', 'Incoming (Cash Deposit)')); ?></option>
                        <option value="out" <?php if($default_type=='out') echo 'selected'; ?>><?php echo app_h(app_tr('صرف (سحب من الخزينة)', 'Outgoing (Cash Withdrawal)')); ?></option>
                    </select>
                </div>

                <div id="category_div" class="form-group hidden" style="<?php echo isset($_GET['def_cat']) ? 'opacity:0.7;' : ''; ?>">
                    <label>تصنيف المصروف</label>
                    <select name="category" id="t_cat" onchange="toggleFields()">
                        <option value="general" <?php if($default_cat=='general') echo 'selected'; ?>>مصروفات عامة / نثرية</option>
                        <option value="supplier" <?php if($default_cat=='supplier') echo 'selected'; ?>>سداد لمورد (مشتريات)</option>
                        <option value="salary" <?php if($default_cat=='salary') echo 'selected'; ?>>راتب شهري</option>
                        <option value="loan" <?php if($default_cat=='loan') echo 'selected'; ?>>سلفة موظف</option>
                        <option value="tax" <?php if($default_cat=='tax') echo 'selected'; ?>>سداد ضريبة</option>
                    </select>
                </div>

                <div class="form-group">
                    <label>المبلغ (EGP)</label>
                    <input type="number" id="amt_input" name="amount" step="0.01" required value="<?php echo $edit_mode||isset($_GET['duplicate']) ? $edit_data['amount'] : (isset($_GET['amount'])?$_GET['amount']:''); ?>" placeholder="0.00" style="font-size:1.4rem; font-weight:bold; color:var(--gold); border-color:var(--gold); text-align:center;">
                    <div class="quick-amounts">
                        <button type="button" class="quick-btn" onclick="document.getElementById('amt_input').value=500">+500</button>
                        <button type="button" class="quick-btn" onclick="document.getElementById('amt_input').value=1000">+1000</button>
                        <button type="button" class="quick-btn" onclick="document.getElementById('amt_input').value=5000">+5000</button>
                    </div>
                </div>

                <div id="client_div" class="form-group hidden">
                    <label>العميل (مصدر التوريد)</label>
                    <select name="client_id" id="client_select" onchange="populateInvoices('sales')">
                        <option value="">-- اختر العميل (اختياري) --</option>
                        <?php 
                        foreach(($financeOptions['clients'] ?? []) as $c){
                            $sel = (($edit_mode||isset($_GET['duplicate'])) && $edit_data['client_id'] == $c['id']) ? 'selected' : '';
                            echo "<option value='{$c['id']}' $sel>{$c['name']}</option>";
                        }
                        ?>
                    </select>
                </div>

                <div id="supplier_div" class="form-group hidden">
                    <label>المورد / الشركة (جهة الصرف)</label>
                    <select name="supplier_id" id="supplier_select" onchange="filterPurchaseInvoices()">
                        <option value="">-- اختر المورد --</option>
                        <?php 
                        foreach(($financeOptions['suppliers'] ?? []) as $s){
                            $sel = (($edit_mode||isset($_GET['duplicate'])) && $edit_data['supplier_id'] == $s['id']) ? 'selected' : (($default_sup == $s['id']) ? 'selected' : '');
                            echo "<option value='{$s['id']}' $sel>{$s['name']}</option>";
                        }
                        ?>
                    </select>
                </div>

                <div id="employee_div" class="form-group hidden">
                    <label><?php echo app_h(app_tr('الموظف المستفيد', 'Target Employee')); ?></label>
                    <select name="employee_id" id="emp_select" onchange="fetchPayrolls(this.value)">
                        <option value=""><?php echo app_h(app_tr('-- اختر الموظف --', '-- Select Employee --')); ?></option>
                        <?php 
                        foreach(($financeOptions['employees'] ?? []) as $e){
                            $sel = (($edit_mode||isset($_GET['duplicate'])) && $edit_data['employee_id'] == $e['id']) ? 'selected' : (($default_emp == $e['id']) ? 'selected' : '');
                            echo "<option value='{$e['id']}' $sel>{$e['name']}</option>";
                        }
                        ?>
                    </select>
                    
                    <div id="payroll_select_div" style="display:none; margin-top:12px; background:var(--surface); padding:10px; border-radius:8px; border-right:3px solid #f1c40f;">
                        <label style="color:#f1c40f; font-size:0.85rem; margin-bottom:5px;"><?php echo app_h(app_tr('تخصيص لراتب محدد (اختياري)', 'Allocate to a specific payroll (optional)')); ?></label>
                        <select name="payroll_id" id="payroll_id" style="margin-bottom:0; padding:8px;">
                            <option value=""><?php echo app_h(app_tr('-- صرف آلي للأقدم (FIFO) --', '-- Auto-pay oldest first (FIFO) --')); ?></option>
                        </select>
                    </div>
                </div>

                <div id="tax_div" class="form-group hidden">
                    <label><?php echo app_h(app_tr('نوع / قانون الضريبة', 'Tax Law / Type')); ?></label>
                    <select name="tax_law_key" id="tax_law_key">
                        <option value=""><?php echo app_h(app_tr('-- اختر نوع الضريبة --', '-- Select Tax Law --')); ?></option>
                        <?php foreach(($financeOptions['tax_laws'] ?? []) as $law): ?>
                            <?php $lawKey = (string)($law['key'] ?? ''); ?>
                            <option value="<?php echo app_h($lawKey); ?>" <?php echo $default_tax_law === $lawKey ? 'selected' : ''; ?>>
                                <?php echo app_h((string)($law['name'] ?? $lawKey)); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="opening-balance-note" style="margin-top:10px; color:#f0d684; border-color:rgba(212,175,55,.24); background:rgba(212,175,55,.08);">
                        <?php echo app_h(app_tr('هذا السند يُسجل كسداد ضريبي ويرتبط بالقانون الضريبي المختار حتى يظهر بوضوح في السجل والطباعة والمراجعة.', 'This voucher is recorded as a tax settlement and linked to the selected tax law for journal, print, and review clarity.')); ?>
                    </div>
                </div>

                <div id="invoice_div" class="form-group hidden">
                    <label id="inv_label" style="color:#2ecc71;"><?php echo app_h(app_tr('ربط بفاتورة محددة (اختياري)', 'Link to a specific invoice (optional)')); ?></label>
                    <select name="invoice_id" id="invoice_select">
                        <option value=""><?php echo app_h(app_tr('-- سداد آلي للأقدم (FIFO) --', '-- Auto-settle oldest first (FIFO) --')); ?></option>
                    </select>
                </div>

                <div class="form-group">
                    <label><?php echo app_h(app_tr('تاريخ الحركة', 'Transaction Date')); ?></label>
                    <input type="date" name="date" value="<?php echo ($edit_mode||isset($_GET['duplicate'])) ? app_h($edit_data['trans_date']) : date('Y-m-d'); ?>" required>
                </div>

                <div class="form-group">
                    <label><?php echo app_h(app_tr('البيان / التفاصيل', 'Description / Details')); ?></label>
                    <textarea name="desc" rows="2" required placeholder="<?php echo app_h(app_tr('اكتب وصفاً واضحاً للحركة المالية...', 'Write a clear description for this financial transaction...')); ?>" style="font-size:0.95rem; resize:vertical;"><?php echo ($edit_mode||isset($_GET['duplicate'])) ? app_h($edit_data['description']) : app_h($_GET['desc'] ?? ''); ?></textarea>
                </div>

                <?php $formCanSubmit = $edit_mode ? $canUpdateTransactions : $canCreateTransactions; ?>
                <button type="submit" name="save_trans" class="btn-submit" <?php echo $formCanSubmit ? '' : 'disabled'; ?>>
                    <?php
                    if (!$formCanSubmit) {
                        echo app_h(app_tr('عرض فقط - لا تملك صلاحية الحفظ', 'View only - no permission to save'));
                    } else {
                        echo app_h($edit_mode ? app_tr('تحديث البيانات', 'Update Transaction') : app_tr('حفظ العملية', 'Save Transaction'));
                    }
                    ?>
                </button>
            </form>
        </div>

        <div class="journal-panel" style="display:flex; flex-direction:column;">
            
            <div class="filter-header">
                <div class="type-filters">
                    <button class="t-btn active" onclick="filterJournal('all', this)"><?php echo app_h(app_tr('الكل', 'All')); ?></button>
                    <button class="t-btn" onclick="filterJournal('in', this)"><?php echo app_h(app_tr('الوارد (قبض)', 'Incoming (Receipt)')); ?></button>
                    <button class="t-btn" onclick="filterJournal('out', this)"><?php echo app_h(app_tr('الصادر (صرف)', 'Outgoing (Payment)')); ?></button>
                </div>
                
                <div class="search-bar">
                    <i class="fa-solid fa-search"></i>
                    <input type="text" id="liveSearch" placeholder="<?php echo app_h(app_tr('ابحث في السجل السريع...', 'Search the quick journal...')); ?>" onkeyup="searchJournal()">
                </div>
            </div>
            
            <div class="journal-scroll-area" id="journalContainer">
                <?php if ($canViewTransactions): ?>
                <?php 
                if(!empty($journalRows)):
                    foreach($journalRows as $row):
                        $in = ($row['type'] == 'in');
                        $allocationSummary = financeReceiptAllocationSummary($conn, $row);
                        $isOpeningReceipt = financeReceiptIsOpeningBalance($conn, $row);
                        $cat_map = ['general'=>'عام', 'supplier'=>'موردين', 'salary'=>'رواتب', 'loan'=>'سلف', 'tax'=>'ضرائب'];
                        $cat_txt = $cat_map[$row['category']] ?? 'عام';
                ?>
                <div class="journal-row j-item" data-type="<?php echo app_h($row['type']); ?>" data-text="<?php echo htmlspecialchars($row['description'].$row['cname'].$row['sname'].$row['ename']); ?>">
                    <div class="j-info">
                        <div class="j-date">
                            <span class="tag <?php echo $in?'in':'out'; ?>"><?php echo $cat_txt; ?></span>
                            <span><i class="fa-regular fa-calendar"></i> <?php echo app_h($row['trans_date']); ?></span>
                            <span style="color:#555; font-size:0.8rem;">| رقم: #<?php echo $row['id']; ?></span>
                        </div>
                        <div class="j-desc"><?php echo app_h($row['description']); ?></div>
                        <div class="j-meta">
                            <?php 
                                if($row['cname']) echo "<span style='color:var(--gold); background:rgba(212, 175, 55, 0.1); padding:2px 8px; border-radius:4px;'><i class='fa-solid fa-user-tie'></i> " . app_h($row['cname']) . "</span>";
                                if($row['sname']) echo "<span style='color:#e74c3c; background:rgba(231, 76, 60, 0.1); padding:2px 8px; border-radius:4px;'><i class='fa-solid fa-building'></i> " . app_h($row['sname']) . "</span>";
                                if($row['ename']) echo "<span style='color:#3498db; background:rgba(52, 152, 219, 0.1); padding:2px 8px; border-radius:4px;'><i class='fa-solid fa-id-badge'></i> " . app_h($row['ename']) . "</span>";
                                if($isOpeningReceipt) echo "<span style='color:#9fd9ff; background:rgba(52, 152, 219, 0.12); padding:2px 8px; border-radius:4px;'><i class='fa-solid fa-hourglass-start'></i> " . app_h(app_tr('سند افتتاحي مرتبط برصيد أول المدة', 'Opening balance linked voucher')) . "</span>";
                                if(($row['category'] ?? '') === 'tax' && !empty($row['tax_law_key'])) echo "<span style='color:#f0d684; background:rgba(212, 175, 55, 0.1); padding:2px 8px; border-radius:4px;'><i class='fa-solid fa-scale-balanced'></i> " . app_h((string)$row['tax_law_key']) . "</span>";
                                if($row['invoice_id']) echo "<span style='color:#888; background:var(--surface); padding:2px 8px; border-radius:4px;'><i class='fa-solid fa-file-invoice'></i> " . app_h(app_tr('فاتورة', 'Invoice')) . " #{$row['invoice_id']}</span>";
                                if(($allocationSummary['count'] ?? 0) > 0) echo "<span style='color:#8fd3ff; background:rgba(52, 152, 219, 0.1); padding:2px 8px; border-radius:4px;'><i class='fa-solid fa-layer-group'></i> " . app_h(app_tr('توزيع على فواتير', 'Allocated to invoices')) . ' x' . (int)$allocationSummary['count'] . "</span>";
                                if((float)($allocationSummary['unallocated_amount'] ?? 0) > 0.00001) echo "<span style='color:#ffe69b; background:rgba(212, 175, 55, 0.12); padding:2px 8px; border-radius:4px;'><i class='fa-solid fa-wallet'></i> " . app_h(app_tr('رصيد غير مخصص لهذا السند', 'Unallocated balance on this voucher')) . ': ' . number_format((float)$allocationSummary['unallocated_amount'], 2) . "</span>";
                            ?>
                        </div>
                    </div>
                    <div class="j-amount" style="color:<?php echo $in?'#2ecc71':'#e74c3c'; ?>">
                        <?php echo ($in?'+':'-') . number_format($row['amount'], 2); ?>
                        <span style="font-size:0.8rem; font-weight:normal; color:#666;">ج.م</span>
                    </div>
                    <div class="j-actions">
                        <?php if ($canViewTransactions): ?>
                            <a href="print_finance_voucher.php?id=<?php echo (int)$row['id']; ?>" target="_blank" rel="noopener"><i class="fa-solid fa-print"></i> <?php echo app_h(app_tr('طباعة سند', 'Print Voucher')); ?></a>
                        <?php endif; ?>
                        <?php if ($canCreateTransactions): ?>
                            <a href="?duplicate=<?php echo $row['id']; ?>" title="<?php echo app_h(app_tr('نسخ بيانات هذه العملية', 'Duplicate this transaction')); ?>"><i class="fa-solid fa-copy"></i> <?php echo app_h(app_tr('تكرار', 'Duplicate')); ?></a>
                        <?php endif; ?>
                        <?php if ($canUpdateTransactions): ?>
                            <a href="?edit=<?php echo $row['id']; ?>"><i class="fa-solid fa-pen-to-square"></i> <?php echo app_h(app_tr('تعديل', 'Edit')); ?></a>
                        <?php endif; ?>
                        <?php if ($canDeleteTransactions): ?>
                            <form method="post" style="display:inline; margin:0;" onsubmit="return confirm('<?php echo app_h(app_tr('تنبيه: سيتم حذف العملية وإعادة حساب الفواتير المرتبطة بها. هل أنت متأكد؟', 'Warning: This transaction will be deleted and linked invoices will be recalculated. Are you sure?')); ?>')">
                                <?php echo app_csrf_input(); ?>
                                <input type="hidden" name="trans_id" value="<?php echo (int)$row['id']; ?>">
                                <button type="submit" name="delete_trans" style="color:#e74c3c;background:none;border:0;cursor:pointer;font:inherit;"><i class="fa-solid fa-trash-can"></i> <?php echo app_h(app_tr('حذف', 'Delete')); ?></button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; else: ?>
                    <div style="text-align:center; padding:50px 20px; color:#666; background:var(--card-bg); border-radius:15px; border:1px dashed #333;">
                        <i class="fa-solid fa-receipt" style="font-size:3rem; margin-bottom:15px; opacity:0.3;"></i>
                        <br><?php echo app_h(app_tr('لا توجد حركات مالية مسجلة حتى الآن', 'No financial transactions have been recorded yet')); ?>
                    </div>
                <?php endif; ?>
                <?php else: ?>
                    <div style="text-align:center; padding:50px 20px; color:#ffb7af; background:var(--card-bg); border-radius:15px; border:1px dashed #7a3a3a;">
                        <i class="fa-solid fa-lock" style="font-size:2.4rem; margin-bottom:10px;"></i>
                        <br><?php echo app_h(app_tr('لا تملك صلاحية عرض السجل المالي.', 'You do not have permission to view the finance journal.')); ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
</div>

<script>
<?php $financePayloads = finance_reference_payloads($conn); ?>
// JSON Data
let payrolls = <?php echo json_encode($financePayloads['payrolls'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
let salesInvoices = <?php echo json_encode($financePayloads['sales_invoices'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
let purchaseInvoices = <?php echo json_encode($financePayloads['purchase_invoices'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
let taxLaws = <?php echo json_encode($financePayloads['tax_laws'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;

let defaultPid = "<?php echo $default_pid; ?>";
let defaultInv = "<?php echo $default_inv; ?>";

function toggleFields() {
    let type = document.getElementById('t_type').value;
    let cat = document.getElementById('t_cat').value;
    
    let els = {
        cat: document.getElementById('category_div'),
        client: document.getElementById('client_div'),
        supplier: document.getElementById('supplier_div'),
        emp: document.getElementById('employee_div'),
        inv: document.getElementById('invoice_div'),
        tax: document.getElementById('tax_div')
    };

    for (let k in els) els[k].classList.add('hidden');

    if(type === 'in') {
        els.client.classList.remove('hidden');
        els.inv.classList.remove('hidden');
        populateInvoices('sales'); 
    } else {
        els.cat.classList.remove('hidden');
        if(cat === 'supplier') {
            els.supplier.classList.remove('hidden');
            els.inv.classList.remove('hidden');
            filterPurchaseInvoices();
        } else if (cat === 'salary' || cat === 'loan') {
            els.emp.classList.remove('hidden');
            if (cat === 'salary') {
                fetchPayrolls(document.getElementById('emp_select').value);
            } else {
                document.getElementById('payroll_select_div').style.display = 'none';
                document.getElementById('payroll_id').value = '';
            }
        } else if (cat === 'tax') {
            els.tax.classList.remove('hidden');
        } 
    }
}

function populateInvoices(mode) {
    let select = document.getElementById('invoice_select');
    select.innerHTML = '<option value=""><?php echo app_h(app_tr('-- سداد آلي للأقدم (FIFO) --', '-- Auto-settle oldest first (FIFO) --')); ?></option>';
    let clientId = document.getElementById('client_select').value;

    if(mode === 'sales' && clientId) {
        salesInvoices.forEach(i => {
            if(i.client_id == clientId) {
                let opt = document.createElement('option');
                opt.value = i.id;
                opt.text = `<?php echo app_h(app_tr('فاتورة مبيعات', 'Sales invoice')); ?> #${i.id} (<?php echo app_h(app_tr('متبقي', 'Remaining')); ?>: ${i.remaining_amount})`;
                if(i.id == defaultInv) opt.selected = true;
                select.add(opt);
            }
        });
    }
}

function filterPurchaseInvoices() {
    let select = document.getElementById('invoice_select');
    select.innerHTML = '<option value=""><?php echo app_h(app_tr('-- Auto-settle oldest first (FIFO) --', '-- Auto-settle oldest first (FIFO) --')); ?></option>';
    let supId = document.getElementById('supplier_select').value;
    
    purchaseInvoices.forEach(i => {
        if(!supId || i.supplier_id == supId) {
            let opt = document.createElement('option');
            opt.value = i.id;
            opt.text = `<?php echo app_h(app_tr('فاتورة مشتريات', 'Purchase invoice')); ?> #${i.id} (<?php echo app_h(app_tr('متبقي', 'Remaining')); ?>: ${i.remaining_amount})`;
            if(i.id == defaultInv) opt.selected = true;
            select.add(opt);
        }
    });
}

function fetchPayrolls(empId) {
    let select = document.getElementById('payroll_id');
    select.innerHTML = '<option value=""><?php echo app_h(app_tr('-- صرف آلي للأقدم (FIFO) --', '-- Auto-pay oldest first (FIFO) --')); ?></option>';
    let div = document.getElementById('payroll_select_div');
    let cat = document.getElementById('t_cat').value;

    if (cat !== 'salary') {
        div.style.display = 'none';
        return;
    }
    
    let found = false;
    payrolls.forEach(p => {
        if(p.employee_id == empId) {
            let opt = document.createElement('option');
            opt.value = p.id;
            opt.text = `<?php echo app_h(app_tr('راتب شهر', 'Payroll month')); ?> ${p.month_year} (<?php echo app_h(app_tr('متبقي', 'Remaining')); ?>: ${p.remaining_amount})`;
            if(p.id == defaultPid) opt.selected = true;
            select.add(opt);
            found = true;
        }
    });
    if(found || empId) div.style.display = 'block'; else div.style.display = 'none';
}

// ----------------------------------------------------
// Smart UI Functions (New)
// ----------------------------------------------------

let currentFilterType = 'all';

function filterJournal(type, btnElement) {
    currentFilterType = type;
    
    // Update active button styling
    let btns = document.querySelectorAll('.t-btn');
    btns.forEach(b => b.classList.remove('active'));
    btnElement.classList.add('active');
    
    applyFilters();
}

function searchJournal() {
    applyFilters();
}

function applyFilters() {
    let searchText = document.getElementById('liveSearch').value.toLowerCase();
    let rows = document.querySelectorAll('.j-item');
    
    rows.forEach(row => {
        let textContent = row.getAttribute('data-text').toLowerCase();
        let rowType = row.getAttribute('data-type');
        
        let matchesSearch = textContent.includes(searchText);
        let matchesType = (currentFilterType === 'all') || (currentFilterType === rowType);
        
        if (matchesSearch && matchesType) {
            row.style.display = "flex";
        } else {
            row.style.display = "none";
        }
    });
}

document.addEventListener('DOMContentLoaded', function () {
    const url = new URL(window.location.href);
    if (url.searchParams.get('focus') !== 'settlement') return;

    const form = document.getElementById('finance-form');
    const invoiceSelect = document.getElementById('invoice_select');

    if (form) {
        form.scrollIntoView({ behavior: 'smooth', block: 'start' });
        form.style.boxShadow = '0 0 0 3px rgba(212,175,55,0.22)';
        setTimeout(() => { form.style.boxShadow = ''; }, 2400);
    }
    if (invoiceSelect) {
        setTimeout(() => invoiceSelect.focus(), 250);
    }
});

window.onload = toggleFields;
</script>

<?php include 'footer.php'; ob_end_flush(); ?>
