<?php
ob_start();
require 'auth.php';
require 'config.php';
require 'header.php';
require_once __DIR__ . '/quote_growth_runtime.php';
app_quote_growth_ensure_schema($conn);
app_quote_backfill_base_totals($conn);

if (!(app_user_can('quotes.reports') || app_user_can('quotes.manage') || in_array((string)($_SESSION['role'] ?? ''), ['admin', 'manager'], true))) {
    die("<div class='container' style='text-align:center; padding:80px; color:#e74c3c;'>غير مصرح لك بالدخول.</div>");
}

$from = (string)($_GET['from'] ?? date('Y-m-01'));
$to = (string)($_GET['to'] ?? date('Y-m-d'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) { $from = date('Y-m-01'); }
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) { $to = date('Y-m-d'); }

$summary = ['total_quotes'=>0,'won'=>0,'lost'=>0,'open'=>0,'quoted_value'=>0,'won_value'=>0];
$baseValueSql = app_quote_base_value_sql('');
$stmt = $conn->prepare("SELECT COUNT(*) total_quotes, SUM(status='approved') won, SUM(status='rejected') lost, SUM(status='pending') open_count, SUM({$baseValueSql}) quoted_value, SUM(CASE WHEN status='approved' THEN {$baseValueSql} ELSE 0 END) won_value FROM quotes WHERE created_at BETWEEN ? AND ?");
$stmt->bind_param('ss', $from, $to);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc() ?: [];
$stmt->close();
foreach ($summary as $key => $value) {
    $sourceKey = $key === 'open' ? 'open_count' : $key;
    $summary[$key] = (float)($row[$sourceKey] ?? 0);
}
$rate = $summary['total_quotes'] > 0 ? round(($summary['won'] / $summary['total_quotes']) * 100, 1) : 0;
$reasons = $conn->query("SELECT quote_rejection_reason reason, COUNT(*) c FROM quotes WHERE status='rejected' AND created_at BETWEEN '" . $conn->real_escape_string($from) . "' AND '" . $conn->real_escape_string($to) . "' GROUP BY quote_rejection_reason ORDER BY c DESC");
$clientBaseValueSql = app_quote_base_value_sql('q');
$clients = $conn->query("SELECT c.name, COUNT(*) c, SUM({$clientBaseValueSql}) total FROM quotes q LEFT JOIN clients c ON c.id=q.client_id WHERE q.created_at BETWEEN '" . $conn->real_escape_string($from) . "' AND '" . $conn->real_escape_string($to) . "' GROUP BY q.client_id, c.name ORDER BY total DESC LIMIT 15");
?>
<style>
body{background:#0a0a0a;color:#eee;font-family:Cairo,sans-serif}.report{max-width:1180px;margin:34px auto;padding:0 18px}.head{display:flex;justify-content:space-between;gap:16px;align-items:center;margin-bottom:22px}.head h1{margin:0;color:#d4af37}.filter{display:flex;gap:8px;flex-wrap:wrap}.filter input,.filter button{background:#151515;color:#fff;border:1px solid #333;border-radius:9px;padding:10px;font-family:Cairo}.filter button{color:#000;background:#d4af37;font-weight:800}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:14px;margin-bottom:22px}.card,.panel{background:#151515;border:1px solid #2b2b2b;border-radius:12px;padding:18px}.card span{display:block;color:#888}.card strong{font-size:1.5rem}.panels{display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:16px}table{width:100%;border-collapse:collapse}th,td{padding:12px;border-bottom:1px solid #292929;text-align:right}th{color:#d4af37}.muted{color:#888}
</style>
<div class="report">
    <div class="head">
        <div><h1><i class="fa-solid fa-chart-simple"></i> تقرير أداء العروض</h1><p class="muted">تحويل العروض إلى مبيعات وأسباب الرفض خلال الفترة.</p></div>
        <form class="filter" method="get">
            <input type="date" name="from" value="<?php echo app_h($from); ?>">
            <input type="date" name="to" value="<?php echo app_h($to); ?>">
            <button type="submit">تطبيق</button>
        </form>
    </div>
    <div class="grid">
        <div class="card"><span>إجمالي العروض</span><strong><?php echo (int)$summary['total_quotes']; ?></strong></div>
        <div class="card"><span>نسبة القبول</span><strong><?php echo app_h((string)$rate); ?>%</strong></div>
        <div class="card"><span>قيمة العروض</span><strong><?php echo number_format((float)$summary['quoted_value'], 2); ?> EGP</strong></div>
        <div class="card"><span>قيمة المقبول</span><strong><?php echo number_format((float)$summary['won_value'], 2); ?> EGP</strong></div>
    </div>
    <div class="panels">
        <div class="panel">
            <h3>أسباب الرفض</h3>
            <table><thead><tr><th>السبب</th><th>العدد</th></tr></thead><tbody>
            <?php while($r = $reasons ? $reasons->fetch_assoc() : null): ?>
                <tr><td><?php echo app_h((string)($r['reason'] ?: 'غير محدد')); ?></td><td><?php echo (int)$r['c']; ?></td></tr>
            <?php endwhile; ?>
            </tbody></table>
        </div>
        <div class="panel">
            <h3>أعلى العملاء بالقيمة</h3>
            <table><thead><tr><th>العميل</th><th>عروض</th><th>القيمة</th></tr></thead><tbody>
            <?php while($c = $clients ? $clients->fetch_assoc() : null): ?>
                <tr><td><?php echo app_h((string)($c['name'] ?: 'بدون اسم')); ?></td><td><?php echo (int)$c['c']; ?></td><td><?php echo number_format((float)$c['total'], 2); ?></td></tr>
            <?php endwhile; ?>
            </tbody></table>
        </div>
    </div>
</div>
<?php include 'footer.php'; ob_end_flush(); ?>
