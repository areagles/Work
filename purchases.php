<?php
// purchases.php - أرشيف المشتريات (Fix: Force Calculation Logic)
ob_start();
require 'auth.php'; 
require 'config.php'; 

$financeRoles = ['admin', 'manager', 'accountant'];
if (!app_user_has_any_role($financeRoles)) {
    http_response_code(403);
    require 'header.php';
    echo "<div class='container' style='margin-top:30px;'><div style='background:#1d0f0f;border:1px solid #5b2020;border-radius:12px;padding:20px;color:#ffb3b3;'>⛔ غير مصرح لك بالدخول إلى سجل المدفوعات المالية.</div></div>";
    require 'footer.php';
    exit;
}

require 'header.php';
$csrfToken = app_csrf_token();

// 1. إعداد فلاتر البحث
$search = isset($_GET['q']) ? $conn->real_escape_string($_GET['q']) : '';
$date_from = isset($_GET['from']) ? $_GET['from'] : '';
$date_to   = isset($_GET['to'])   ? $_GET['to']   : '';

// الشرط: سندات صرف (out)
$where = "WHERE r.type='out'";

// إضافة شروط البحث
if(!empty($search)){
    // البحث يشمل اسم المورد أو الوصف
    $where .= " AND (r.description LIKE '%$search%' OR s.name LIKE '%$search%')";
}
if(!empty($date_from) && !empty($date_to)){
    $where .= " AND r.trans_date BETWEEN '$date_from' AND '$date_to'";
}

/* 2. الاستعلام الأساسي (جلب الحركات فقط)
   قمنا بتبسيط الاستعلام لضمان سرعة الجلب، وسنقوم بالحساب الدقيق في الأسفل
*/
$sql = "SELECT r.*, 
        s.name as supplier_name, 
        s.category as sup_cat,
        s.opening_balance,
        s.id as real_supplier_id
        FROM financial_receipts r 
        LEFT JOIN suppliers s ON r.supplier_id = s.id 
        $where 
        ORDER BY r.trans_date DESC, r.id DESC";
$res = $conn->query($sql);

// 3. إجمالي الفلترة (للعرض العلوي)
$sql_sum = "SELECT SUM(amount) FROM financial_receipts r LEFT JOIN suppliers s ON r.supplier_id = s.id $where";
$total_filtered = $conn->query($sql_sum)->fetch_row()[0] ?? 0;
?>

<style>
    :root { --gold: #d4af37; --dark-bg: #0f0f0f; --panel-bg: #1a1a1a; }
    body { background-color: var(--dark-bg); color: #fff; font-family: 'Cairo'; }
    .purchases-shell { display:grid; gap:18px; }
    .purchases-hero {
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
    .page-pill {
        display:inline-flex; align-items:center; gap:8px; padding:6px 12px; border-radius:999px;
        background:rgba(212,175,55,0.08); border:1px solid rgba(212,175,55,0.24); color:#f0d684;
        font-size:.76rem; font-weight:700; margin-bottom:14px;
    }
    .page-title { margin:0; color:#f7f1dc; font-size:1.8rem; line-height:1.3; }
    .page-subtitle { margin:10px 0 0; color:#a8abb1; line-height:1.8; max-width:760px; }
    .page-actions { display:flex; gap:10px; flex-wrap:wrap; margin-top:18px; }
    .btn-add {
        background: linear-gradient(135deg, var(--gold), #b8860b); color: #000; padding: 12px 20px; border-radius: 14px;
        text-decoration: none; font-weight: bold; display: inline-flex; align-items: center; gap: 8px; transition:0.3s; box-shadow:0 8px 20px rgba(212,175,55,0.2);
    }
    .btn-add:hover { opacity: 0.95; transform: translateY(-2px); }
    .hero-stat-grid { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:12px; }
    .hero-stat {
        background:rgba(255,255,255,0.035); border:1px solid rgba(255,255,255,0.08); padding:18px; border-radius:18px;
        min-height:118px; text-align:right;
    }
    .hero-stat-value { font-size:1.7rem; font-weight:800; color:#fff; }
    .hero-stat-label { font-size:.82rem; color:#9ca0a8; margin-top:10px; }

    .table-container { background: var(--panel-bg); border-radius: 20px; overflow: hidden; border: 1px solid rgba(255,255,255,0.08); margin-top: 0; box-shadow: 0 5px 15px rgba(0,0,0,0.3); }
    table { width: 100%; border-collapse: collapse; }
    th { background: #111; color: var(--gold); padding: 15px; text-align: right; border-bottom: 2px solid #333; }
    td { padding: 15px; border-bottom: 1px solid #222; vertical-align: middle; }
    tr:hover { background: #222; }
    
    .btn-action { color: #fff; margin-left: 10px; text-decoration: none; font-size: 1.1rem; transition: 0.2s; }
    .btn-action:hover { color: var(--gold); transform: scale(1.2); }
    
    .search-inputs { display:flex; gap:10px; flex-wrap:wrap; background:#222; padding:15px; border-radius:18px; border:1px solid rgba(255,255,255,0.08); }
    .search-inputs input { padding:12px; background:#000; border:1px solid #444; color:#fff; border-radius:12px; flex:1; }
    .search-inputs button { padding:12px 25px; background:var(--gold); color:#000; border:none; border-radius:12px; cursor:pointer; font-weight:bold; }

    .sup-info { font-size: 0.75rem; color: #aaa; margin-top: 4px; display: block; }
    .balance-tag { background: #333; padding: 2px 6px; border-radius: 4px; border: 1px solid #444; color: #fff; font-size: 0.75rem; }
    @media (max-width: 900px) {
        .purchases-hero { grid-template-columns:1fr; }
    }
</style>

<div class="container" style="margin-top:40px;">
    <div class="purchases-shell">
    <div class="purchases-hero">
        <div class="glass-panel">
            <span class="page-pill"><i class="fa-solid fa-cart-shopping"></i> الموردون والمدفوعات</span>
            <h2 class="page-title">سجل المدفوعات المرتبط بالموردين</h2>
            <p class="page-subtitle">عرض مركزي لمدفوعات الموردين مع ربطها بالملف المالي للمورد والتسويات والدفعات المقدمة.</p>
            <div class="page-actions">
                <a href="finance.php?def_type=out&def_cat=supplier" class="btn-add">
                    <i class="fa-solid fa-plus"></i> تسجيل عملية جديدة
                </a>
            </div>
        </div>
        <div class="glass-panel">
            <div class="hero-stat-grid">
                <div class="hero-stat">
                    <div class="hero-stat-value"><?php echo number_format((float)$total_filtered, 2); ?></div>
                    <div class="hero-stat-label">إجمالي المعروض</div>
                </div>
                <div class="hero-stat">
                    <div class="hero-stat-value"><?php echo (int)($res ? $res->num_rows : 0); ?></div>
                    <div class="hero-stat-label">عدد الحركات</div>
                </div>
            </div>
        </div>
    </div>

    <form method="GET" class="search-inputs" style="margin-top:20px;">
        <input type="text" name="q" value="<?php echo htmlspecialchars($search); ?>" placeholder="اسم المورد، التفاصيل...">
        <input type="date" name="from" value="<?php echo $date_from; ?>" placeholder="من" title="من تاريخ">
        <input type="date" name="to" value="<?php echo $date_to; ?>" placeholder="إلى" title="إلى تاريخ">
        <button type="submit"><i class="fa-solid fa-filter"></i> عرض السجلات</button>
        <?php if($search || $date_from): ?>
            <a href="purchases.php" style="padding:10px 15px; background:#333; color:#fff; text-decoration:none; border-radius:5px;">إلغاء</a>
        <?php endif; ?>
    </form>

    <div class="table-container">
        <table>
            <thead>
                <tr>
                    <th width="5%">#</th>
                    <th>التاريخ</th>
                    <th>الجهة / المورد</th>
                    <th>تفاصيل العملية</th>
                    <th>المبلغ المدفوع</th>
                    <th>الملف المالي للمورد</th>
                    <th>إجراءات</th>
                </tr>
            </thead>
            <tbody>
                <?php if($res && $res->num_rows > 0): ?>
                    <?php while($row = $res->fetch_assoc()): ?>
                        <?php 
                            // ============================================================
                            //  🔥 الحساب القسري (Force Calculation) لكل صف 🔥
                            // ============================================================
                            $settled_total = 0;
                            $advance_credit = 0;
                            $supplierTotalInvoices = 0;
                            $current_bal = 0;
                            
                            // 1. هل هذه العملية مرتبطة بمورد؟
                            if(!empty($row['real_supplier_id'])) {
                                $sid = intval($row['real_supplier_id']);
                                
                                // 2. اذهب واحسب "كل" الفلوس التي دفعناها لهذا المورد تحديداً من جدول السندات
                                // نبحث عن supplier_id ونوع الحركة out
                                $q_purchase_total = $conn->query("SELECT IFNULL(SUM(total_amount), 0) FROM purchase_invoices WHERE supplier_id = $sid AND COALESCE(status, '') <> 'cancelled'");
                                $supplierTotalInvoices = (float)($q_purchase_total->fetch_row()[0] ?? 0);

                                if (function_exists('financeSupplierBalanceSnapshot')) {
                                    $supplierSnapshot = financeSupplierBalanceSnapshot($conn, $sid);
                                    $current_bal = (float)($supplierSnapshot['net_balance'] ?? 0);
                                    if (function_exists('financeSupplierSettlementSummary')) {
                                        $supplierSettlement = financeSupplierSettlementSummary($conn, $sid);
                                        $settled_total = (float)($supplierSettlement['settled_total'] ?? 0);
                                        $advance_credit = (float)($supplierSettlement['advance_credit'] ?? 0);
                                    }
                                } else {
                                    $q_calc = $conn->query("SELECT SUM(amount) FROM financial_receipts WHERE supplier_id = $sid AND type = 'out'");
                                    $settled_total = (float)($q_calc->fetch_row()[0] ?? 0);
                                    $current_bal = ((float)$row['opening_balance'] + $supplierTotalInvoices) - $settled_total;
                                }
                            }
                        ?>
                    <tr>
                        <td style="color:#666;">#<?php echo $row['id']; ?></td>
                        <td><?php echo $row['trans_date']; ?></td>
                        <td>
                            <?php if(!empty($row['real_supplier_id'])): ?>
                                <span style="color:var(--gold); font-weight:bold; font-size:1.05rem;">
                                    <i class="fa-solid fa-user-tie"></i> <?php echo $row['supplier_name']; ?>
                                </span>
                                <?php if(!empty($row['sup_cat'])) echo "<br><span class='sup-info'>({$row['sup_cat']})</span>"; ?>
                            <?php else: ?>
                                <span style="color:#aaa;">
                                    <i class="fa-solid fa-box-open"></i> مصروف عام / غير محدد
                                </span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php echo $row['description']; ?>
                            <?php if($row['invoice_id']) echo " <span style='color:#e74c3c; font-size:0.8rem;'>(فاتورة #{$row['invoice_id']})</span>"; ?>
                        </td>
                        <td style="font-weight:bold; color:#e74c3c; font-size:1.1rem;" dir="ltr">
                            - <?php echo number_format($row['amount'], 2); ?>
                        </td>
                        <td>
                            <?php if(!empty($row['real_supplier_id'])): ?>
                                <div style="font-size:0.8rem; line-height:1.7;">
                                    <span class="balance-tag" style="border-color:#2ecc71; color:#2ecc71;">
                                        <i class="fa-solid fa-check"></i> مجموع ما تمت تسويته: <?php echo number_format($settled_total, 2); ?>
                                    </span>
                                    <br>
                                    <span class="balance-tag" style="border-color:#3498db; color:#8fd3ff;">
                                        <i class="fa-solid fa-file-invoice-dollar"></i> إجمالي الفواتير: <?php echo number_format($supplierTotalInvoices, 2); ?>
                                    </span>
                                    <?php if ($advance_credit > 0.00001): ?>
                                    <br>
                                    <span class="balance-tag" style="border-color:#f39c12; color:#f8d17c;">
                                        <i class="fa-solid fa-wallet"></i> دفعات مقدمة غير مخصصة: <?php echo number_format($advance_credit, 2); ?>
                                    </span>
                                    <?php endif; ?>
                                    <br>
                                    <span style="color: <?php echo $current_bal <= 0 ? '#2ecc71' : '#e74c3c'; ?>; font-weight:bold;">
                                        المتبقي له: <?php echo number_format($current_bal, 2); ?>
                                    </span>
                                </div>
                            <?php else: ?>
                                <span style="color:#444; font-size:0.8rem;">-- لا ينطبق --</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <a href="finance.php?edit=<?php echo $row['id']; ?>" class="btn-action" title="تعديل"><i class="fa-solid fa-pen"></i></a>
                            <a href="finance.php?del=<?php echo $row['id']; ?>&amp;_token=<?php echo urlencode($csrfToken); ?>" onclick="return confirm('تأكيد الحذف؟')" class="btn-action" style="color:#e74c3c;" title="حذف"><i class="fa-solid fa-trash-can"></i></a>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr><td colspan="7" style="text-align:center; padding:40px; color:#666;">لا توجد سجلات.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    </div>
</div>

<?php include 'footer.php'; ob_end_flush(); ?>
