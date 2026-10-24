<?php
// statement_employee.php - (Arab Eagles Sovereign Statement V11.0)
ob_start();
error_reporting(E_ALL);

require 'auth.php'; 
require 'config.php'; 

$financeRoles = ['admin', 'manager', 'accountant'];
if (!app_user_has_any_role($financeRoles)) {
    http_response_code(403);
    require 'header.php';
    echo "<div class='container' style='padding:50px; text-align:center; color:#ffb3b3;'>⛔ غير مصرح لك.</div>";
    require 'footer.php'; exit;
}

require 'header.php';

$emp_id = isset($_GET['employee_id']) ? intval($_GET['employee_id']) : (isset($_GET['user_id']) ? intval($_GET['user_id']) : 0);
if($emp_id == 0){
    die("<div class='container' style='padding:50px; color:#C5A021; background:#050505; text-align:center; font-weight:bold; margin-top:20px; border-radius:10px;'>⛔ يرجى اختيار الموظف أولاً من قائمة الموظفين.</div>");
}

$date_from = $_GET['from'] ?? date('Y-m-01'); 
$date_to   = $_GET['to']   ?? date('Y-m-d');   
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$date_from)) { $date_from = date('Y-m-01'); }
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$date_to)) { $date_to = date('Y-m-d'); }

$stmtUser = $conn->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
$stmtUser->bind_param('i', $emp_id);
$stmtUser->execute();
$user_data = $stmtUser->get_result()->fetch_assoc();
$stmtUser->close();

if(!$user_data) {
    $fallbackName = '';
    $fallbackStmt = $conn->prepare("SELECT employee_name_snapshot FROM payroll_sheets WHERE employee_id = ? AND TRIM(COALESCE(employee_name_snapshot, '')) <> '' ORDER BY id DESC LIMIT 1");
    $fallbackStmt->bind_param('i', $emp_id);
    $fallbackStmt->execute();
    $fallbackRow = $fallbackStmt->get_result()->fetch_assoc();
    $fallbackStmt->close();
    $fallbackName = trim((string)($fallbackRow['employee_name_snapshot'] ?? ''));
    if ($fallbackName === '') { $fallbackName = '#' . $emp_id; }
    $user_data = ['id' => $emp_id, 'full_name' => $fallbackName];
}
$brandProfile = app_brand_profile($conn);
$outputShowHeader = !empty($brandProfile['show_header']);
$outputShowFooter = !empty($brandProfile['show_footer']);
$outputShowQr = !empty($brandProfile['show_qr']);
$headerLines = app_brand_output_lines($brandProfile, 'header', true);
$footerLines = app_brand_output_lines($brandProfile, 'footer', true);

$stmtTrans = $conn->prepare("SELECT trans_date as t_date, id as ref_id, amount, description, type, category FROM financial_receipts WHERE employee_id = ? AND trans_date BETWEEN ? AND ? ORDER BY trans_date ASC");
$stmtTrans->bind_param('iss', $emp_id, $date_from, $date_to);
$stmtTrans->execute();
$transactions = $stmtTrans->get_result();
$stmtTrans->close();

$total_in = 0; $total_out = 0; $rows_data = [];
if($transactions->num_rows > 0) {
    while($row = $transactions->fetch_assoc()) {
        if($row['type'] == 'in') $total_in += $row['amount']; else $total_out += $row['amount'];
        $rows_data[] = $row;
    }
}
$net_balance = $total_out - $total_in; 
$qrPayload = app_brand_qr_payload($brandProfile, [ 'Report' => 'Employee Statement', 'Employee' => (string)($user_data['full_name'] ?? ''), 'From' => $date_from, 'To' => $date_to ]);
$qrUrl = ($outputShowQr && $qrPayload !== '') ? app_brand_qr_url($qrPayload, 140) : '';
?>

<style>
    :root { --gold: #C5A021; --dark: #050505; --surface: #0a0a0a; --panel: #111111; --border: rgba(255,255,255,0.05); }
    body { background-color: var(--dark) !important; color: #fff !important; font-family: 'Almarai', sans-serif; margin: 0; }
    .container { max-width: 1200px; margin: 0 auto; padding: 20px; }
    .control-panel { background: var(--panel); border: 1px solid var(--border); padding: 20px; border-radius: 15px; margin-bottom: 25px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px; box-shadow: 0 5px 20px rgba(0,0,0,0.3); }
    .filter-form { display: flex; gap: 15px; align-items: center; }
    .filter-form input[type="date"] { background: #000; border: 1px solid #444; color: #fff; padding: 10px; border-radius: 8px; outline: none; }
    .btn { padding: 10px 20px; border-radius: 8px; font-weight: bold; border: 1px solid var(--gold); cursor: pointer; display: inline-flex; align-items: center; gap: 8px; transition: 0.3s; background:#111; color:var(--gold); }
    .btn:hover { background: var(--gold); color:#000; }
    .kpi-row { display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; margin-bottom: 25px; }
    .kpi-box { background: var(--surface); padding: 20px; border-radius: 12px; border: 1px solid var(--border); text-align: center; }
    .kpi-box h4 { margin: 0 0 10px 0; color: #888; font-size: 0.9rem; }
    .kpi-box .num { font-size: 1.8rem; font-weight: bold; }
    .report-card { background: var(--surface); border-radius: 15px; border: 1px solid var(--border); padding:30px; }
    .report-header { text-align: center; border-bottom: 1px solid var(--border); padding-bottom:20px; margin-bottom:20px; }
    .report-header h2 { color: var(--gold); margin: 0 0 5px 0; }
    table { width: 100%; border-collapse: collapse; border:1px solid var(--border); border-radius:10px; overflow:hidden; }
    th { background: #111; color: var(--gold); padding: 15px; text-align: center; border-bottom: 1px solid rgba(197,160,33,0.3); }
    td { padding: 15px; text-align: center; border-bottom: 1px solid var(--border); color: #fff; }
    .amount-in { color: #e74c3c; font-weight: bold; } .amount-out { color: #2ecc71; font-weight: bold; }
    tfoot { background: #1a1a1a; } tfoot td { font-weight: 900; color: var(--gold); border-top: 2px solid var(--gold); }
    .cat-badge { font-size: 0.75rem; padding: 3px 8px; border-radius: 4px; background: #222; color: #aaa; }
    .signature-block { display: none; }
    
    @media print {
        @page { size: A4 portrait; margin: 15mm; }
        body { background: #fff !important; color: #000 !important; }
        .no-print, .control-panel, header, nav, footer { display: none !important; }
        .report-card { border: none !important; box-shadow: none !important; padding:0 !important; background:#fff !important; }
        .report-header { border-bottom: 2px solid #000 !important; }
        .report-header h2, .report-header h3, .report-header p { color: #000 !important; }
        table { border: 2px solid #000 !important; }
        th { background: #f0f0f0 !important; color: #000 !important; border: 1px solid #000 !important; -webkit-print-color-adjust: exact; }
        td { border: 1px solid #000 !important; color: #000 !important; }
        .amount-in, .amount-out { color: #000 !important; }
        tfoot { background: #e0e0e0 !important; } tfoot td { color: #000 !important; border-top: 2px solid #000 !important; }
        .kpi-row { display: flex; gap: 10px; } .kpi-box { border: 1px solid #000 !important; background: #fff !important; flex: 1; padding: 10px;}
        .kpi-box h4 { color: #333 !important; } .kpi-box .num { color: #000 !important; }
        .signature-block { display: flex !important; justify-content: space-around !important; margin-top: 60px !important; text-align: center !important; font-weight: bold !important; color: #000 !important; page-break-inside: avoid; }
        .signature-block div { width: 30%; border-top: 2px solid #000 !important; padding-top: 10px !important; }
    }
</style>

<div class="container">
    <div class="control-panel no-print">
        <form method="GET" class="filter-form">
            <input type="hidden" name="employee_id" value="<?php echo $emp_id; ?>">
            <div><label style="color:#888;">من:</label> <input type="date" name="from" value="<?php echo $date_from; ?>"></div>
            <div><label style="color:#888;">إلى:</label> <input type="date" name="to" value="<?php echo $date_to; ?>"></div>
            <button type="submit" class="btn"><i class="fa-solid fa-filter"></i> تصفية</button>
        </form>
        <div><button onclick="window.print()" class="btn"><i class="fa-solid fa-print"></i> طباعة الكشف</button></div>
    </div>

    <div class="kpi-row">
        <div class="kpi-box" style="border-bottom: 3px solid #2ecc71;"><h4>استلم (صرف رواتب/سلف)</h4><div class="num" style="color:#2ecc71;"><?php echo number_format($total_out, 2); ?></div></div>
        <div class="kpi-box" style="border-bottom: 3px solid #e74c3c;"><h4>ورّد (تسوية/سداد)</h4><div class="num" style="color:#e74c3c;"><?php echo number_format($total_in, 2); ?></div></div>
        <div class="kpi-box" style="border-bottom: 3px solid var(--gold);"><h4>الرصيد الصافي (في ذمته)</h4><div class="num" style="color:var(--gold);"><?php echo number_format($net_balance, 2); ?></div></div>
    </div>

    <div class="report-card">
        <div class="report-header">
            <div style="font-size: 2.5rem; color: var(--gold); margin-bottom: 10px;"><i class="fa-solid fa-file-invoice-dollar"></i></div>
            <h2>كشف حساب تفصيلي (موظف / عهدة)</h2>
            <h3 style="color:#eee; margin: 5px 0;">الاسم: <?php echo htmlspecialchars($user_data['full_name']); ?></h3>
            <p style="color:#888; font-size:0.9rem; margin:0;">الفترة من <?php echo date('Y/m/d', strtotime($date_from)); ?> إلى <?php echo date('Y/m/d', strtotime($date_to)); ?></p>
        </div>
        
        <div style="overflow-x: auto;">
            <table>
                <thead>
                    <tr><th width="15%">التاريخ</th><th style="text-align: right; width: 35%;">البيان / الوصف</th><th>استلام نقدية (+)</th><th>تسوية / توريد (-)</th><th>التصنيف</th></tr>
                </thead>
                <tbody>
                    <?php if(!empty($rows_data)): foreach($rows_data as $row): 
                        $cat_map = ['general'=>'عام/عهدة', 'salary'=>'راتب', 'loan'=>'سلفة'];
                        $cat_name = $cat_map[$row['category']] ?? 'أخرى';
                    ?>
                    <tr>
                        <td><?php echo date('Y-m-d', strtotime($row['t_date'])); ?><br><small style="color:#666;">#<?php echo $row['ref_id']; ?></small></td>
                        <td style="text-align:right;"><?php echo htmlspecialchars($row['description']); ?></td>
                        <td class="amount-out"><?php echo $row['type'] == 'out' ? number_format($row['amount'], 2) : '-'; ?></td>
                        <td class="amount-in"><?php echo $row['type'] == 'in' ? number_format($row['amount'], 2) : '-'; ?></td>
                        <td><span class="cat-badge"><?php echo $cat_name; ?></span></td>
                    </tr>
                    <?php endforeach; else: ?>
                    <tr><td colspan="5" style="padding: 40px; color: #666;">لا توجد حركات مسجلة.</td></tr>
                    <?php endif; ?>
                </tbody>
                <tfoot>
                    <tr><td colspan="2" style="text-align: right;">إجمالي الحركات المجمعة:</td><td><?php echo number_format($total_out, 2); ?></td><td><?php echo number_format($total_in, 2); ?></td><td></td></tr>
                </tfoot>
            </table>
        </div>

        <div class="signature-block">
            <div>توقيع الموظف</div>
            <div>مراجعة الحسابات</div>
            <div>اعتماد الإدارة العليا</div>
        </div>
    </div>
</div>
<?php include 'footer.php'; ob_end_flush(); ?>