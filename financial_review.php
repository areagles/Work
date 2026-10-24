<?php
/**
 * financial_review.php - Arab Eagles Sovereign Financial Portal V7.5
 * Logic Fix: Robust Item Description Mapping
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require 'config.php';
if (file_exists(__DIR__ . '/modules/finance/receipts_runtime.php')) {
    require_once __DIR__ . '/modules/finance/receipts_runtime.php';
}
app_start_session();

if(!isset($_GET['token']) || empty($_GET['token'])) 
    die("<div style='background:#050505;color:#C5A021;height:100vh;display:flex;align-items:center;justify-content:center;font-family:sans-serif;'><h3>⛔ الرابط غير صالح.</h3></div>");

$token = trim((string)$_GET['token']);
$type = $_GET['type'] ?? 'client';
if (!in_array($type, ['client', 'supplier'], true)) { $type = 'client'; }

$table = ($type == 'supplier') ? 'suppliers' : 'clients';
$stmt_entity = $conn->prepare("SELECT * FROM $table WHERE access_token = ? LIMIT 1");
$stmt_entity->bind_param("s", $token);
$stmt_entity->execute();
$res = $stmt_entity->get_result();

if($res->num_rows == 0) 
    die("<div style='background:#050505;color:#C5A021;height:100vh;display:flex;align-items:center;justify-content:center;font-family:sans-serif;'><h3>⛔ الرابط منتهي الصلاحية.</h3></div>");

$entity = $res->fetch_assoc();
$stmt_entity->close();
$entity_id = (int)$entity['id'];
$name = $entity['company_name'] ?? $entity['name'];

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['confirm_balance'])) {
    $now = date('Y-m-d H:i:s');
    $conn->query("UPDATE $table SET last_balance_confirm = '$now' WHERE id = $entity_id");
    header("Location: ?token=".urlencode($token)."&type=".urlencode($type)."&msg=confirmed"); exit;
}

$balance = 0;
if ($type == 'client') {
    if (function_exists('financeClientBalanceSnapshot')) {
        $snapshot = financeClientBalanceSnapshot($conn, $entity_id);
        $balance = (float)($snapshot['net_balance'] ?? 0);
    }
    $label_pos = "مستحقات مطلوبة"; $label_neg = "رصيد دائن لك";
    $hist_sql = "SELECT id, inv_date as t_date, 'فاتورة' as type, total_amount as amount, items_json as details, NULL as note FROM invoices WHERE client_id=$entity_id AND (status IS NULL OR status <> 'cancelled') 
                 UNION ALL 
                 SELECT id, trans_date as t_date, 'إيصال سداد' as type, amount, NULL as details, NULL as note FROM financial_receipts WHERE client_id=$entity_id AND type='in' 
                 ORDER BY t_date DESC LIMIT 20";
} else {
    if (function_exists('financeSupplierBalanceSnapshot')) {
        $snapshot = financeSupplierBalanceSnapshot($conn, $entity_id);
        $balance = (float)($snapshot['net_balance'] ?? 0);
    }
    $label_pos = "مستحقات للمورد"; $label_neg = "دفعات مقدمة";
    $hist_sql = "SELECT id, inv_date as t_date, 'فاتورة توريد' as type, total_amount as amount, items_json as details, NULL as note FROM purchase_invoices WHERE supplier_id=$entity_id AND (status IS NULL OR status <> 'cancelled') 
                 UNION ALL 
                 SELECT id, trans_date as t_date, 'دفعة نقدية' as type, amount, NULL as details, NULL as note FROM financial_receipts WHERE supplier_id=$entity_id AND type='out' 
                 ORDER BY t_date DESC LIMIT 20";
}
$history = $conn->query($hist_sql);
?>
<!DOCTYPE html>
<html dir="rtl" lang="ar">
<head>
    <meta charset="UTF-8">
    <title>البوابة المالية السيادية | Arab Eagles</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Almarai:wght@300;400;700;800&display=swap');
        body { font-family: 'Almarai', sans-serif; background-color: #050505; color: #fff; margin: 0; }
        .gold-text { color: #C5A021; }
        .glass { background: rgba(18, 18, 18, 0.85); backdrop-filter: blur(20px); border: 1px solid rgba(255, 255, 255, 0.05); }
        .vault-card { background: linear-gradient(145deg, #111 0%, #050505 100%); border: 1px solid #C5A02144; }
        .ledger-item { cursor: pointer; transition: 0.3s; background: rgba(255,255,255,0.02); border: 1px solid rgba(255,255,255,0.05); }
        .ledger-item:hover { background: rgba(197, 160, 33, 0.08); border-color: #C5A02166; transform: translateY(-3px); }
        ::-webkit-scrollbar { width: 4px; } ::-webkit-scrollbar-thumb { background: #C5A021; border-radius: 10px; }
    </style>
</head>
<body class="p-4 md:p-12">

<div class="max-w-4xl mx-auto space-y-10">
    <div class="flex justify-between items-center border-b border-white/10 pb-8">
        <div class="flex items-center gap-4">
            <div class="w-12 h-12 bg-[#C5A021] rounded-xl flex items-center justify-center shadow-lg"><i class="fas fa-eagle text-black text-xl"></i></div>
            <div><h1 class="text-xl font-black text-white">ARAB EAGLES</h1><p class="text-[8px] gold-text font-bold uppercase tracking-[0.3em]">Sovereign Financial Hub</p></div>
        </div>
        <div class="bg-white/5 px-4 py-2 rounded-xl border border-white/10 text-left"><span class="text-slate-500 text-[9px] font-bold block mb-1">الطرف المعتمد</span><span class="font-black text-xs"><?php echo htmlspecialchars($name); ?></span></div>
    </div>

    <div class="vault-card rounded-[2rem] p-10 flex flex-col md:flex-row items-center justify-between gap-6 shadow-2xl">
        <div class="text-center md:text-right">
            <div class="text-[10px] text-slate-500 font-bold uppercase tracking-widest mb-2">صافي الرصيد الحالي</div>
            <div class="text-6xl font-black <?php echo $balance > 0 ? 'text-red-500' : 'text-emerald-500'; ?> mb-3" dir="ltr"><?php echo number_format(abs($balance), 2); ?> <span class="text-lg text-slate-600">EGP</span></div>
            <div class="text-[10px] font-black px-4 py-1.5 rounded-full inline-block <?php echo $balance > 0 ? 'bg-red-500/10 text-red-500' : 'bg-emerald-500/10 text-emerald-400'; ?>"><i class="fas fa-info-circle ml-1"></i> <?php echo $balance > 0 ? $label_pos : $label_neg; ?></div>
        </div>
        <button onclick="document.getElementById('confirmModal').classList.remove('hidden')" class="bg-[#C5A021] hover:bg-white text-black font-black px-10 py-4 rounded-2xl text-xs uppercase transition-all shadow-xl"><i class="fas fa-signature ml-2"></i> مصادقة الرصيد</button>
    </div>

    <div class="glass rounded-[2.5rem] p-8 md:p-12 shadow-2xl">
        <h3 class="text-xs font-black gold-text uppercase tracking-widest mb-8 border-b border-white/5 pb-4"><i class="fas fa-list-ul ml-2"></i> سجل العمليات (اضغط للمعاينة)</h3>
        <div class="space-y-4">
            <?php if($history && $history->num_rows > 0): while($h = $history->fetch_assoc()): 
                $is_inv = strpos($h['type'], 'فاتورة') !== false;
                $safe_data = $is_inv ? htmlspecialchars($h['details'] ?? '[]', ENT_QUOTES, 'UTF-8') : htmlspecialchars($h['note'] ?? 'سداد نقدي للمستحقات', ENT_QUOTES, 'UTF-8');
            ?>
            <div onclick="showBrief(this, '<?php echo $h['type']; ?>', '<?php echo $h['id']; ?>', '<?php echo date('Y/m/d', strtotime($h['t_date'])); ?>', '<?php echo number_format($h['amount'], 2); ?>', <?php echo $is_inv ? 1 : 0; ?>)" 
                 data-details="<?php echo $safe_data; ?>" 
                 class="ledger-item flex justify-between items-center p-6 rounded-[1.5rem]">
                <div class="flex items-center gap-5">
                    <div class="w-12 h-12 rounded-xl bg-black border border-white/10 flex items-center justify-center text-lg <?php echo $is_inv ? 'text-red-400' : 'text-emerald-400'; ?>"><i class="fas <?php echo $is_inv ? 'fa-file-invoice' : 'fa-receipt'; ?>"></i></div>
                    <div><div class="font-black text-sm text-white"><?php echo $h['type']; ?> #<?php echo $h['id']; ?></div><div class="text-[10px] text-slate-600 font-bold"><?php echo date('Y/m/d', strtotime($h['t_date'])); ?></div></div>
                </div>
                <div class="text-lg font-black <?php echo $is_inv ? 'text-red-400' : 'text-emerald-400'; ?>" dir="ltr"><?php echo number_format($h['amount'], 2); ?></div>
            </div>
            <?php endwhile; else: ?><div class="text-center py-20 text-slate-600 font-bold text-xs uppercase border border-dashed border-white/10 rounded-3xl">لا توجد حركات مسجلة</div><?php endif; ?>
        </div>
    </div>
</div>

<div id="briefModal" class="hidden fixed inset-0 bg-black/95 backdrop-blur-md z-50 flex items-center justify-center p-6">
    <div class="glass border-[#C5A02144] rounded-[2.5rem] p-10 max-w-lg w-full shadow-2xl">
        <div class="flex justify-between items-center mb-8 border-b border-white/10 pb-4">
            <h3 class="text-sm font-black gold-text uppercase"><i class="fas fa-eye ml-2"></i> معاينة: <span id="brief_type"></span></h3>
            <button onclick="document.getElementById('briefModal').classList.add('hidden')" class="text-slate-500 hover:text-white transition-colors"><i class="fas fa-times text-2xl"></i></button>
        </div>
        <div class="grid grid-cols-2 gap-4 mb-8">
            <div class="bg-black/50 p-5 rounded-2xl border border-white/5"><span class="text-[9px] text-slate-500 font-bold block mb-1">الرقم المرجعي</span><span id="brief_id" class="font-black text-white text-sm"></span></div>
            <div class="bg-black/50 p-5 rounded-2xl border border-white/5"><span class="text-[9px] text-slate-500 font-bold block mb-1">تاريخ الإصدار</span><span id="brief_date" class="font-black text-white text-sm"></span></div>
        </div>
        <div id="brief_content" class="overflow-y-auto max-h-[300px] mb-8 custom-scrollbar"></div>
        <div class="flex justify-between items-center bg-black p-6 rounded-2xl border-r-4 border-[#C5A021]">
            <span class="text-xs font-black text-slate-500 uppercase">القيمة النهائية</span><span id="brief_total" class="text-2xl font-black gold-text" dir="ltr"></span>
        </div>
    </div>
</div>

<div id="confirmModal" class="hidden fixed inset-0 bg-black/95 z-50 flex items-center justify-center p-6"><div class="glass p-12 rounded-[2.5rem] text-center max-w-sm w-full"><form method="POST"><h3 class="text-lg font-black text-white mb-8 uppercase tracking-widest">تأكيد مصادقة الرصيد؟</h3><button type="submit" name="confirm_balance" class="w-full bg-[#C5A021] text-black font-black py-5 rounded-2xl uppercase hover:scale-105 transition-all">أصادق وأعتمد ✓</button><button type="button" onclick="this.closest('#confirmModal').classList.add('hidden')" class="mt-6 text-slate-600 text-[10px] font-black uppercase hover:text-white transition-colors">إلغاء الإجراء</button></form></div></div>

<script>
function showBrief(element, type, id, date, total, isInvoice) {
    document.getElementById('brief_type').innerText = type;
    document.getElementById('brief_id').innerText = '#' + id;
    document.getElementById('brief_date').innerText = date;
    document.getElementById('brief_total').innerText = total + ' EGP';
    
    let data = element.getAttribute('data-details');
    let content = document.getElementById('brief_content');
    content.innerHTML = '';

    if (isInvoice) {
        try {
            let items = JSON.parse(data);
            if(typeof items === 'string') items = JSON.parse(items);
            let html = '<table class="w-full text-right text-xs"><thead class="text-slate-600 border-b border-white/5"><th class="pb-3">البيان</th><th class="pb-3 text-center">الكمية</th><th class="pb-3 text-center">الإجمالي</th></thead><tbody class="divide-y divide-white/5">';
            if(items && items.length > 0) {
                items.forEach(item => {
                    // 🦅 محرك الفحص الذكي للبيان (Smart Key Detection)
                    let itemName = item.description || item.name || item.item_name || item.item || item.desc || 'صنف غير محدد';
                    html += `<tr><td class="py-4 font-bold text-white">${itemName}</td><td class="py-4 text-center text-slate-400 font-mono">${item.qty||1}</td><td class="py-4 text-center gold-text font-black font-mono">${parseFloat(item.total||0).toLocaleString()}</td></tr>`;
                });
            } else { html += '<tr><td colspan="3" class="py-4 text-center text-slate-500 font-bold">لا توجد أصناف تفصيلية مسجلة</td></tr>'; }
            html += '</tbody></table>';
            content.innerHTML = html;
        } catch(e) { content.innerHTML = '<div class="bg-red-900/10 p-4 text-red-500 text-xs font-bold text-center rounded-xl">تفاصيل الأصناف غير متوفرة لهذا السجل.</div>'; }
    } else {
        content.innerHTML = `<div class="bg-[#1a1a1a] p-6 rounded-2xl border border-white/5 shadow-inner"><h4 class="text-[10px] text-[#C5A021] font-bold uppercase mb-3">بيان إيصال السداد</h4><p class="text-xs text-slate-300 leading-relaxed italic">${data ? data : 'تحصيل نقدي لمستحقات Arab Eagles'}</p></div>`;
    }
    document.getElementById('briefModal').classList.remove('hidden');
}
window.onclick = function(e) { if(e.target.id.includes('Modal')) e.target.classList.add('hidden'); }
</script>
</body>
</html>