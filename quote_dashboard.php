<?php
ob_start();
require 'auth.php';
require 'config.php';
require 'header.php';
require_once __DIR__ . '/quote_growth_runtime.php';
app_quote_growth_ensure_schema($conn);
app_quote_backfill_base_totals($conn);

if (!(app_user_can('quotes.view') || in_array((string)($_SESSION['role'] ?? ''), ['admin', 'manager', 'sales', 'accountant'], true))) {
    die("<div class='container' style='text-align:center; padding:80px; color:#e74c3c;'>غير مصرح لك بالدخول.</div>");
}

$money = static function (float $value): string {
    return number_format($value, 2) . ' EGP';
};
$cards = ['pending' => 0, 'approved' => 0, 'rejected' => 0, 'value_open' => 0.0, 'value_won' => 0.0];
$baseValueSql = app_quote_base_value_sql('');
$res = $conn->query("SELECT status, COUNT(*) c, SUM({$baseValueSql}) total FROM quotes GROUP BY status");
while ($res && ($row = $res->fetch_assoc())) {
    $status = (string)$row['status'];
    if (isset($cards[$status])) {
        $cards[$status] = (int)$row['c'];
    }
    if ($status === 'pending') {
        $cards['value_open'] = (float)$row['total'];
    } elseif ($status === 'approved') {
        $cards['value_won'] = (float)$row['total'];
    }
}
$alerts = app_quote_alerts($conn);
$pipeline = $conn->query("SELECT q.*, c.name client_name FROM quotes q LEFT JOIN clients c ON c.id=q.client_id WHERE q.status='pending' ORDER BY q.valid_until ASC, q.id DESC LIMIT 30");
?>
<style>
body{background:#0a0a0a;color:#eee;font-family:Cairo,sans-serif}.quote-dash{max-width:1200px;margin:34px auto;padding:0 18px}.dash-head{display:flex;justify-content:space-between;gap:16px;align-items:center;margin-bottom:22px}.dash-head h1{margin:0;color:#d4af37}.dash-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:14px;margin-bottom:22px}.dash-card{background:#151515;border:1px solid #2b2b2b;border-radius:12px;padding:18px}.dash-card span{display:block;color:#888;font-size:.86rem}.dash-card strong{display:block;color:#fff;font-size:1.6rem;margin-top:8px}.table-wrap{background:#151515;border:1px solid #2b2b2b;border-radius:12px;padding:10px;overflow:auto}table{width:100%;border-collapse:separate;border-spacing:0 8px}th{color:#d4af37;text-align:right;padding:10px}td{background:#1d1d1d;padding:14px;border-top:1px solid #2b2b2b;border-bottom:1px solid #2b2b2b}.tag{display:inline-flex;padding:5px 10px;border-radius:999px;border:1px solid #444;color:#ddd;font-size:.8rem}.danger{color:#ffb7b7}.warn{color:#ffe08a}.ok{color:#bdf3cf}.btn{display:inline-flex;align-items:center;gap:8px;text-decoration:none;border:1px solid #d4af37;color:#d4af37;border-radius:999px;padding:9px 14px}
</style>
<div class="quote-dash">
    <div class="dash-head">
        <div>
            <h1><i class="fa-solid fa-chart-line"></i> لوحة متابعة العروض</h1>
            <p style="color:#888;margin:6px 0 0;">متابعة احتمالات الإغلاق والتنبيهات والدفعات المطلوبة.</p>
        </div>
        <a class="btn" href="quotes.php"><i class="fa-solid fa-file-contract"></i> العروض</a>
    </div>
    <div class="dash-grid">
        <div class="dash-card"><span>عروض مفتوحة</span><strong><?php echo (int)$cards['pending']; ?></strong></div>
        <div class="dash-card"><span>عروض مقبولة</span><strong class="ok"><?php echo (int)$cards['approved']; ?></strong></div>
        <div class="dash-card"><span>عروض مرفوضة</span><strong class="danger"><?php echo (int)$cards['rejected']; ?></strong></div>
        <div class="dash-card"><span>قيمة مفتوحة</span><strong><?php echo app_h($money((float)$cards['value_open'])); ?></strong></div>
        <div class="dash-card"><span>قيمة مقبولة</span><strong class="ok"><?php echo app_h($money((float)$cards['value_won'])); ?></strong></div>
        <div class="dash-card"><span>تنتهي قريباً / منتهية</span><strong class="warn"><?php echo (int)$alerts['soon']; ?> / <?php echo (int)$alerts['expired']; ?></strong></div>
    </div>
    <div class="table-wrap">
        <table>
            <thead><tr><th>العرض</th><th>العميل</th><th>الصلاحية</th><th>القيمة</th><th>المتابعة</th><th>إجراء</th></tr></thead>
            <tbody>
            <?php while($row = $pipeline ? $pipeline->fetch_assoc() : null): ?>
                <?php
                    $stage = ['client_review'=>'مراجعة العميل','awaiting_deposit'=>'بانتظار دفعة','ready_to_start'=>'جاهز للتنفيذ','invoiced'=>'تمت الفوترة'][(string)($row['fulfillment_stage'] ?? 'client_review')] ?? 'مراجعة العميل';
                    $valid = (string)($row['valid_until'] ?? '');
                    $tone = ($valid < date('Y-m-d')) ? 'danger' : (($valid <= date('Y-m-d', strtotime('+3 days'))) ? 'warn' : '');
                ?>
                <tr>
                    <td><?php echo app_h((string)($row['quote_number'] ?: ('#'.$row['id']))); ?></td>
                    <td><?php echo app_h((string)($row['client_name'] ?? '')); ?></td>
                    <td class="<?php echo $tone; ?>"><?php echo app_h($valid); ?></td>
                    <td><?php echo app_h(app_quote_format_money((float)$row['total_amount'], (string)($row['currency_code'] ?? 'EGP'))); ?></td>
                    <td><span class="tag"><?php echo app_h($stage); ?></span></td>
                    <td><a class="btn" href="view_quote.php?id=<?php echo (int)$row['id']; ?>">فتح</a></td>
                </tr>
            <?php endwhile; ?>
            </tbody>
        </table>
    </div>
</div>
<?php include 'footer.php'; ob_end_flush(); ?>
