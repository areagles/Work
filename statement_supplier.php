<?php
// statement_supplier.php - (Royal Supplier Statement V10.3 - Perfect Print)
ob_start();
error_reporting(E_ALL);

require 'auth.php'; 
require 'config.php'; 
require_once __DIR__ . '/modules/finance/receipts_runtime.php';

$financeRoles = ['admin', 'manager', 'accountant'];
if (!app_user_has_any_role($financeRoles)) {
    http_response_code(403);
    require 'header.php';
    echo "<div class='container' style='padding:50px; text-align:center; color:#ffb3b3;'>⛔ غير مصرح لك بالوصول إلى كشف حساب الموردين.</div>";
    require 'footer.php';
    exit;
}

require 'header.php';

// 1. التحقق من المورد
if(!isset($_GET['supplier_id']) || empty($_GET['supplier_id'])){
    echo "<div class='container' style='padding:50px; text-align:center;'>⛔ لم يتم تحديد المورد.</div>";
    require 'footer.php'; exit;
}

$supplier_id = intval($_GET['supplier_id']);
$date_from = $_GET['from'] ?? '';
$date_to   = $_GET['to']   ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$date_to)) {
    $date_to = date('Y-m-d');
}

// 2. جلب بيانات المورد
$stmtSupplier = $conn->prepare("SELECT * FROM suppliers WHERE id = ? LIMIT 1");
$stmtSupplier->bind_param('i', $supplier_id);
$stmtSupplier->execute();
$supplier = $stmtSupplier->get_result()->fetch_assoc();
$stmtSupplier->close();
if(!$supplier) {
    echo "<div class='container'>⛔ المورد غير موجود.</div>";
    require 'footer.php';
    exit;
}

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$date_from)) {
    $stmtMinDate = $conn->prepare("
        SELECT MIN(t_date) AS first_date
        FROM (
            SELECT DATE(COALESCE(NULLIF(inv_date, ''), created_at)) AS t_date
            FROM purchase_invoices
            WHERE supplier_id = ?
              AND COALESCE(status, '') <> 'cancelled'
            UNION ALL
            SELECT trans_date AS t_date
            FROM financial_receipts
            WHERE supplier_id = ?
              AND type = 'out'
        ) d
        WHERE t_date IS NOT NULL
    ");
    $stmtMinDate->bind_param('ii', $supplier_id, $supplier_id);
    $stmtMinDate->execute();
    $firstDate = (string)($stmtMinDate->get_result()->fetch_assoc()['first_date'] ?? '');
    $stmtMinDate->close();
    $date_from = preg_match('/^\d{4}-\d{2}-\d{2}$/', $firstDate) ? $firstDate : date('Y-01-01');
}

// --- إنشاء التوكن إذا لم يوجد ---
if(empty($supplier['access_token'])) {
    $new_token = bin2hex(random_bytes(16));
    $stmtToken = $conn->prepare("UPDATE suppliers SET access_token = ? WHERE id = ?");
    $stmtToken->bind_param('si', $new_token, $supplier_id);
    $stmtToken->execute();
    $stmtToken->close();
    $supplier['access_token'] = $new_token;
}

// رابط البوابة
$protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http");
$base_url = "$protocol://" . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']);
$base_url = str_replace('/modules', '', $base_url); 
$portal_link = $base_url . "/financial_review.php?token=" . $supplier['access_token'] . "&type=supplier";
$brandProfile = app_brand_profile($conn);
$outputShowHeader = !empty($brandProfile['show_header']);
$outputShowFooter = !empty($brandProfile['show_footer']);
$outputShowQr = !empty($brandProfile['show_qr']);
$headerLines = app_brand_output_lines($brandProfile, 'header', true);
$footerLines = app_brand_output_lines($brandProfile, 'footer', true);
$qrPayload = app_brand_qr_payload($brandProfile, [
    'Report' => 'Supplier Statement',
    'Supplier' => (string)($supplier['name'] ?? ''),
    'From' => $date_from,
    'To' => $date_to,
    'Portal' => $portal_link,
]);
$qrUrl = ($outputShowQr && $qrPayload !== '') ? app_brand_qr_url($qrPayload, 140) : '';

// 3. المحرك المحاسبي
// أ. الرصيد الافتتاحي
$openingPaymentExclusion = "description NOT LIKE 'تسوية رصيد أول المدة%'";
$snapshotAsOf = function_exists('financeSupplierBalanceSnapshotAsOf')
    ? financeSupplierBalanceSnapshotAsOf($conn, $supplier_id, $date_from)
    : null;
$opening_balance = $snapshotAsOf
    ? round((float)($snapshotAsOf['net_balance'] ?? 0), 2)
    : 0.0;
$registeredOpening = round((float)($supplier['opening_balance'] ?? 0), 2);

// ب. حركات الفترة
$supplierLedgerParts = [
    "
        SELECT
            COALESCE(NULLIF(inv_date, ''), DATE(created_at)) as t_date,
            'purchase' as type,
            id as ref_id,
            0 as debit,
            total_amount as credit,
            'فاتورة مشتريات' as description
        FROM purchase_invoices
        WHERE supplier_id = $supplier_id
          AND COALESCE(status, '') <> 'cancelled'
          AND DATE(COALESCE(NULLIF(inv_date, ''), created_at)) BETWEEN '$date_from' AND '$date_to'
    ",
    "
        SELECT
            trans_date as t_date,
            'payment' as type,
            id as ref_id,
            amount as debit,
            0 as credit,
            description
        FROM financial_receipts
        WHERE supplier_id = $supplier_id
          AND type='out'
          AND trans_date BETWEEN '$date_from' AND '$date_to'
          AND {$openingPaymentExclusion}
    ",
];
if (app_table_exists($conn, 'purchase_invoice_returns')) {
    $supplierLedgerParts[] = "
        SELECT
            return_date as t_date,
            'purchase_return' as type,
            id as ref_id,
            total_amount as debit,
            0 as credit,
            CONCAT('مردود شراء ', IFNULL(return_number, '')) as description
        FROM purchase_invoice_returns
        WHERE supplier_id = $supplier_id
          AND return_date BETWEEN '$date_from' AND '$date_to'
    ";
}
$transactions = $conn->query("
    SELECT * FROM (
        " . implode("\nUNION ALL\n", $supplierLedgerParts) . "
    ) AS ledger ORDER BY t_date ASC, ref_id ASC
");
$ledgerRows = [];
$sum_debit = 0.0;
$sum_credit = 0.0;
$running_balance = $opening_balance;
if ($transactions) {
    while ($row = $transactions->fetch_assoc()) {
        $running_balance += ((float)$row['credit'] - (float)$row['debit']);
        $sum_debit += (float)$row['debit'];
        $sum_credit += (float)$row['credit'];
        $row['running_balance'] = $running_balance;
        $ledgerRows[] = $row;
    }
}

// Helper for WhatsApp
function get_wa_link($phone, $text) {
    $phone = preg_replace('/[^0-9]/', '', $phone);
    if (strlen($phone) == 11 && substr($phone, 0, 2) == '01') { $phone = '2' . $phone; }
    return "https://wa.me/$phone?text=" . urlencode($text);
}
?>

<style>
    :root {
        --gold: #d4af37;
        --panel-bg: #1e1e1e;
        --sheet-bg: #ffffff;
        --sheet-card: #ffffff;
        --sheet-line: #b7bec8;
        --sheet-line-strong: #525a66;
        --sheet-text: #111111;
        --sheet-muted: #4d5560;
        --sheet-head-bg: #454c55;
        --sheet-head-text: #ffffff;
        --sheet-row-alt: #f1f3f5;
        --sheet-opening-bg: #d9dee4;
        --sheet-total-bg: #c2c9d1;
    }
    
    /* Layout */
    .container { max-width: 1200px; margin: 0 auto; padding: 20px; }

    /* Control Bar */
    .control-bar {
        background: var(--panel-bg); padding: 15px; border-radius: 12px; 
        border: 1px solid #333; margin-bottom: 25px; 
        display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px;
    }
    
    .filter-form { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
    .filter-form input { background: #000; color: #fff; border: 1px solid #444; padding: 10px; border-radius: 6px; }
    
    .share-panel {
        display: flex; gap: 10px; align-items: center; background: #222; padding: 8px 15px; border-radius: 8px; border: 1px solid var(--gold);
    }
    .share-input { background: transparent; border: none; color: var(--gold); font-family: monospace; width: 150px; overflow: hidden; text-overflow: ellipsis; }

    /* Printable Area */
    .printable-area {
        background: var(--sheet-bg);
        color: var(--sheet-text);
        padding: 40px; 
        border-radius: 5px; min-height: 800px; 
        font-family: 'Times New Roman', serif;
        box-shadow: 0 0 18px rgba(0,0,0,0.25);
    }
    
    .header-print { display: flex; justify-content: space-between; border-bottom: 2px solid #1f1f1f; padding-bottom: 20px; margin-bottom: 30px; }
    .header-extra { margin: -10px 0 18px; border: 1px solid var(--sheet-line); border-radius: 8px; padding: 9px 12px; font-size: 0.85rem; color: var(--sheet-text); line-height: 1.7; background:#fafafa; }
    .header-extra div { margin-bottom: 3px; }
    .header-extra div:last-child { margin-bottom: 0; }
    .client-info-box { background: var(--sheet-card); border: 1px solid var(--sheet-line); padding: 15px; margin-bottom: 20px; display: flex; justify-content: space-between; }
    .party-label { color:#505862; font-size:.86rem; }
    .party-name { margin:5px 0; color:#111; }
    .party-phone { color:#222; }
    .period-box { text-align:right; color:#111; }
    .report-footer { margin-top: 22px; border-top: 1px dashed #bbb; padding-top: 12px; display: flex; justify-content: space-between; align-items: flex-end; gap: 12px; flex-wrap: wrap; }
    .report-footer-lines { flex: 1; min-width: 240px; font-size: 0.84rem; color: #333; line-height: 1.7; }
    .report-footer-lines div { margin-bottom: 3px; }
    .report-footer-lines div:last-child { margin-bottom: 0; }
    .report-footer-qr img { width: 92px; height: 92px; border: 1px solid #bbb; border-radius: 8px; padding: 4px; background: #fff; }
    .statement-hero {
        display:grid;
        grid-template-columns:minmax(0,1.1fr) minmax(260px,0.9fr);
        gap:16px;
        margin-bottom:18px;
    }
    .hero-card {
        background: linear-gradient(180deg, rgba(255,255,255,0.05), rgba(255,255,255,0.02)), rgba(18,18,18,0.88);
        border:1px solid rgba(212,175,55,0.16);
        border-radius:20px;
        padding:18px 20px;
        box-shadow:0 14px 30px rgba(0,0,0,0.22);
    }
    .hero-pill {
        display:inline-flex; align-items:center; gap:8px; padding:6px 12px; border-radius:999px;
        background:rgba(212,175,55,0.08); border:1px solid rgba(212,175,55,0.24); color:#f0d684;
        font-size:.76rem; font-weight:700; margin-bottom:14px;
    }
    .hero-title { margin:0; color:#f7f1dc; font-size:1.55rem; line-height:1.35; }
    .hero-subtitle { margin:10px 0 0; color:#a8abb1; line-height:1.8; }
    .hero-kpi-grid { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:10px; }
    .hero-kpi {
        background:rgba(255,255,255,0.035); border:1px solid rgba(255,255,255,0.08); padding:14px; border-radius:16px;
        min-height:96px;
    }
    .hero-kpi-value { font-size:1.35rem; font-weight:800; color:#fff; }
    .hero-kpi-label { margin-top:8px; color:#9ca0a8; font-size:.78rem; }
    .screen-table-wrap {
        background:var(--sheet-card);
        border:1px solid var(--sheet-line);
        border-radius:14px;
        overflow:auto;
        box-shadow:0 8px 22px rgba(0,0,0,0.10);
    }

    /* Table */
    .royal-table { width: 100%; border-collapse: collapse; margin-top: 10px; font-family: 'Cairo', sans-serif; font-size: 0.95rem; }
    .royal-table th { background: var(--sheet-head-bg); color: var(--sheet-head-text); padding: 12px; border: 1px solid var(--sheet-line-strong); font-weight: bold; text-align: center; }
    .royal-table td { padding: 10px; border: 1px solid var(--sheet-line); text-align: center; vertical-align: middle; color:var(--sheet-text); background:var(--sheet-card); }

    .row-debit { color: #a11d1d; font-weight: 800; }
    .row-credit { color: #11613b; font-weight: 800; }
    .royal-table tbody tr:nth-child(even) td { background:var(--sheet-row-alt); }
    .royal-table tbody tr:hover td { background:#e7eaee; }
    .royal-table tr.opening-row td { background:var(--sheet-opening-bg) !important; color:var(--sheet-text) !important; }
    .royal-table tr.total-row td { background:var(--sheet-total-bg) !important; color:var(--sheet-text) !important; font-weight:800; }
    .royal-table tr.total-row td.row-debit { color:var(--sheet-text) !important; }
    .royal-table tr.total-row td.row-credit { color:var(--sheet-text) !important; }
    .opening-balance-cell { text-align:center; font-weight:800; }
    .narration-cell { text-align:right; color:var(--sheet-text); }
    .totals-label-cell { text-align:left; padding-left:20px; }
    .empty-state-cell { padding:30px !important; color:#5b6470 !important; background:#f7f8fa !important; }
    .confirm-state-box { margin-top:20px; border:1px solid #6d8d76; background:#eef6f0; padding:10px; text-align:center; color:#1d5c31; font-weight:bold; }

    /* Buttons */
    .btn-royal { 
        padding: 10px 20px; border-radius: 6px; border: none; cursor: pointer; 
        font-weight: bold; color: #fff; text-decoration: none; display: inline-flex; align-items: center; gap: 8px; font-family: 'Cairo'; transition: 0.3s;
    }
    .btn-royal:hover { transform: translateY(-2px); }

    /* Mobile Responsive */
    @media (max-width: 768px) {
        .statement-hero { grid-template-columns:1fr; }
        .control-bar { flex-direction: column; align-items: stretch; }
        .filter-form { flex-direction: column; }
        .filter-form input { width: 100%; box-sizing: border-box; }
        .share-panel { display: none; } 

        .printable-area { padding: 15px; }
        .header-print { flex-direction: column; text-align: center; gap: 15px; }
        .header-extra { font-size: 0.8rem; }
        .client-info-box { flex-direction: column; gap: 10px; }

        .royal-table, .royal-table thead, .royal-table tbody, .royal-table th, .royal-table td, .royal-table tr { display: block; }
        .royal-table thead { display: none; }
        .royal-table tr { margin-bottom: 15px; border: 1px solid var(--sheet-line); border-radius: 8px; padding: 10px; background: var(--sheet-card); box-shadow: 0 2px 5px rgba(0,0,0,0.05); }
        .royal-table td { border: none; border-bottom: 1px solid #e5e7eb; position: relative; padding-left: 50%; text-align: right; background: transparent !important; }
        .royal-table td:before { position: absolute; left: 10px; width: 45%; padding-right: 10px; white-space: nowrap; font-weight: bold; text-align: left; content: attr(data-label); color: var(--sheet-muted); }
        .royal-table td:last-child { border-bottom: 0; }
        
        .no-print-mobile { display: none !important; }
    }

    /* Print Specific Fixes */
    @media print { 
        @page { size: A4; margin: 0; }
        body { background: #fff; margin: 0; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        
        /* Hide UI elements */
        .no-print, .no-print-mobile, .main-navbar, footer { display: none !important; }
        
        /* Reset Container */
        .container { width: 100% !important; max-width: 100% !important; margin: 0 !important; padding: 0 !important; }
        .printable-area { 
            box-shadow: none !important; 
            width: 100% !important; 
            margin: 0 !important; 
            padding: 1.5cm !important; 
            min-height: auto !important;
            border: none !important;
        }

        /* Force Table Display */
        .royal-table { display: table !important; width: 100% !important; border: 1px solid #000; }
        .royal-table thead { display: table-header-group !important; }
        .royal-table tbody { display: table-row-group !important; }
        .royal-table tr { display: table-row !important; border: none !important; box-shadow: none !important; margin: 0 !important; padding: 0 !important; }
        .royal-table th, .royal-table td { 
            display: table-cell !important; 
            text-align: center !important; 
            border: 1px solid #ccc !important;
            padding: 8px !important;
        }
        .royal-table th { background:#5a616b !important; color:#fff !important; border-color:#4a5058 !important; }
        .royal-table td { background:#fff !important; color:#111 !important; border-color:#a6aeb8 !important; }
        .royal-table tr.opening-row td { background:#d6dce3 !important; color:#111 !important; font-weight:700 !important; }
        .royal-table tr.total-row td { background:#bfc7d0 !important; color:#111 !important; font-weight:800 !important; }
        .royal-table tr.total-row td.row-debit,
        .royal-table tr.total-row td.row-credit { color:#111 !important; }
        
        /* Remove Mobile Labels */
        .royal-table td:before { content: none !important; }
        .royal-table td { padding-left: 8px !important; text-align: center !important; }

        /* Headers Layout */
        .header-print { flex-direction: row !important; text-align: right !important; }
        .client-info-box { flex-direction: row !important; }
        .header-extra { border-color: #aaa !important; color: #000 !important; }
        .report-footer-lines { color: #000 !important; }
    }
</style>

<div class="container">

    <div class="statement-hero no-print">
        <div class="hero-card">
            <span class="hero-pill"><i class="fa-solid fa-file-invoice-dollar"></i> ملف المورد</span>
            <h2 class="hero-title"><?php echo app_h((string)$supplier['name']); ?></h2>
            <div class="hero-subtitle">
                كشف حساب المورد للفترة من <?php echo app_h($date_from); ?> إلى <?php echo app_h($date_to); ?> مع الرصيد المرحل وحركة الفترة والرصيد الختامي.
            </div>
        </div>
        <div class="hero-card">
            <div class="hero-kpi-grid">
                <div class="hero-kpi">
                    <div class="hero-kpi-value"><?php echo number_format($registeredOpening, 2); ?></div>
                    <div class="hero-kpi-label">رصيد أول المدة المسجل</div>
                </div>
                <div class="hero-kpi">
                    <div class="hero-kpi-value"><?php echo number_format((float)($snapshotAsOf['net_balance'] ?? 0), 2); ?></div>
                    <div class="hero-kpi-label">رصيد مرحل قبل الفترة</div>
                </div>
                <div class="hero-kpi">
                    <div class="hero-kpi-value"><?php echo number_format($sum_credit - $sum_debit, 2); ?></div>
                    <div class="hero-kpi-label">صافي حركة الفترة</div>
                </div>
                <div class="hero-kpi">
                    <div class="hero-kpi-value"><?php echo number_format($running_balance, 2); ?></div>
                    <div class="hero-kpi-label">الرصيد الختامي</div>
                </div>
            </div>
        </div>
    </div>

    <div class="control-bar no-print">
        <div style="display:flex; gap:10px;">
            <a href="finance_reports.php" class="btn-royal" style="background:#444;">
                <i class="fa-solid fa-arrow-right"></i> رجوع للمركز المالي
            </a>
            <button onclick="window.print()" class="btn-royal" style="background:var(--gold); color:#000;"><i class="fa-solid fa-print"></i> طباعة</button>
        </div>

        <form method="GET" class="filter-form">
            <input type="hidden" name="supplier_id" value="<?php echo $supplier_id; ?>">
            <input type="date" name="from" value="<?php echo $date_from; ?>">
            <input type="date" name="to" value="<?php echo $date_to; ?>">
            <button type="submit" class="btn-royal" style="background:#333; border:1px solid var(--gold);"><i class="fa-solid fa-filter"></i></button>
        </form>

        <div class="share-panel no-print-mobile">
            <input type="text" value="<?php echo $portal_link; ?>" id="shareLink" class="share-input" readonly>
            <button onclick="copyLink()" class="btn-royal" style="background:#3498db; padding: 5px 10px; font-size:0.8rem;">نسخ</button>
        </div>

        <a href="<?php echo get_wa_link($supplier['phone'], "السادة {$supplier['name']}، مرفق كشف الحساب للمطابقة:\n$portal_link"); ?>" target="_blank" class="btn-royal" style="background:#25D366; width:100%; justify-content:center;">
            <i class="fa-brands fa-whatsapp"></i> إرسال للمورد
        </a>
    </div>

    <div class="printable-area">
        
        <div class="header-print">
            <div>
                <h1 style="margin:0; text-transform:uppercase;"><?php echo app_h((string)($brandProfile['org_name'] ?? 'ARAB EAGLES')); ?></h1>
                <p style="margin:5px 0; color:#555;">Purchase & Supply Dept.</p>
            </div>
            <div style="text-align:right;">
                <div style="border:2px solid #000; padding:5px 20px; font-weight:bold; display:inline-block; margin-bottom:5px;">SUPPLIER STATEMENT<br>كشف حساب مورد</div>
                <div>Date: <?php echo date('d/m/Y'); ?></div>
            </div>
        </div>
        <?php if ($outputShowHeader && !empty($headerLines)): ?>
        <div class="header-extra">
            <?php foreach ($headerLines as $line): ?>
                <div><?php echo app_h($line); ?></div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <div class="client-info-box">
            <div>
                <small class="party-label">VENDOR / المورد:</small>
                <h2 class="party-name"><?php echo $supplier['name']; ?></h2>
                <div class="party-phone"><?php echo $supplier['phone']; ?></div>
            </div>
            <div class="period-box">
                <div><strong>من:</strong> <?php echo $date_from; ?></div>
                <div><strong>إلى:</strong> <?php echo $date_to; ?></div>
            </div>
        </div>

        <div class="screen-table-wrap">
        <table class="royal-table">
            <thead>
                <tr>
                    <th width="15%">التاريخ</th>
                    <th width="10%">النوع</th>
                    <th width="35%">البيان</th>
                    <th width="10%">مدين (دفعنا)</th>
                    <th width="10%">دائن (اشترينا)</th>
                    <th width="15%">الرصيد</th>
                </tr>
            </thead>
            <tbody>
                <tr class="opening-row">
                    <td data-label="التاريخ"><?php echo $date_from; ?></td>
                    <td data-label="البيان" colspan="2">رصيد مرحل قبل الفترة</td>
                    <td data-label="الرصيد" colspan="3" dir="ltr" class="opening-balance-cell"><?php echo number_format($opening_balance, 2); ?></td>
                </tr>

                <?php 
                if(!empty($ledgerRows)):
                    foreach($ledgerRows as $row):
                ?>
                <tr>
                    <td data-label="التاريخ"><?php echo date('Y-m-d', strtotime($row['t_date'])); ?></td>
                    <td data-label="النوع"><?php
                        $typeLabels = [
                            'purchase' => 'فاتورة',
                            'payment' => 'سداد',
                            'purchase_return' => 'مردود',
                        ];
                        echo app_h($typeLabels[(string)$row['type']] ?? (string)$row['type']);
                    ?></td>
                    <td data-label="البيان" class="narration-cell"><?php echo $row['description']; ?> #<?php echo $row['ref_id']; ?></td>
                    <td data-label="مدين" class="row-debit"><?php echo ($row['debit'] > 0) ? number_format($row['debit'], 2) : '-'; ?></td>
                    <td data-label="دائن" class="row-credit"><?php echo ($row['credit'] > 0) ? number_format($row['credit'], 2) : '-'; ?></td>
                    <td data-label="الرصيد" class="opening-balance-cell" dir="ltr"><?php echo number_format((float)$row['running_balance'], 2); ?></td>
                </tr>
                <?php endforeach; else: ?>
                <tr><td colspan="6" class="empty-state-cell">لا توجد حركات خلال الفترة المحددة.</td></tr>
                <?php endif; ?>
            </tbody>
            <tfoot>
                <tr class="total-row">
                    <td colspan="3" class="totals-label-cell">الإجماليات (Totals)</td>
                    <td data-label="مجموع المدين" class="row-debit"><?php echo number_format($sum_debit, 2); ?></td>
                    <td data-label="مجموع الدائن" class="row-credit"><?php echo number_format($sum_credit, 2); ?></td>
                    <td data-label="الرصيد النهائي" class="opening-balance-cell"><?php echo number_format($running_balance, 2); ?></td>
                </tr>
            </tfoot>
        </table>
        </div>

        <?php if(!empty($supplier['last_balance_confirm'])): ?>
        <div class="confirm-state-box">
            ✅ تمت المصادقة على هذا الرصيد من قبل المورد بتاريخ: <?php echo $supplier['last_balance_confirm']; ?>
        </div>
        <?php endif; ?>

        <?php if ($outputShowFooter): ?>
        <div class="report-footer">
            <div class="report-footer-lines">
                <div style="font-weight:bold;"><?php echo app_h((string)($brandProfile['org_name'] ?? '')); ?></div>
                <?php foreach ($footerLines as $line): ?>
                    <div><?php echo app_h($line); ?></div>
                <?php endforeach; ?>
                <?php if (trim((string)($brandProfile['org_footer_note'] ?? '')) !== ''): ?>
                    <div><?php echo app_h((string)$brandProfile['org_footer_note']); ?></div>
                <?php endif; ?>
            </div>
            <?php if ($qrUrl !== ''): ?>
            <div class="report-footer-qr">
                <img src="<?php echo app_h($qrUrl); ?>" alt="QR">
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <div style="margin-top:60px; display:flex; justify-content:space-between; text-align:center; page-break-inside:avoid;">
            <div>_________________<br>إعداد</div>
            <div>_________________<br>المدير المالي</div>
            <div>_________________<br>مصادقة المورد</div>
        </div>

    </div>
</div>

<script>
function copyLink() {
    var copyText = document.getElementById("shareLink");
    copyText.select();
    document.execCommand("copy");
    alert("تم نسخ رابط البوابة!");
}
</script>

<?php include 'footer.php'; ob_end_flush(); ?>
