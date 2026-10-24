<?php
require 'auth.php';
require 'config.php';

$sessionId = (int)($_GET['id'] ?? 0);
if ($sessionId <= 0) {
    die('جلسة الجرد غير صالحة.');
}

$stmtSession = $conn->prepare("
    SELECT s.*, w.name AS warehouse_name, u.full_name AS created_by_name
    FROM inventory_audit_sessions s
    JOIN warehouses w ON w.id = s.warehouse_id
    LEFT JOIN users u ON u.id = s.created_by_user_id
    WHERE s.id = ?
    LIMIT 1
");
$stmtSession->bind_param('i', $sessionId);
$stmtSession->execute();
$session = $stmtSession->get_result()->fetch_assoc();
$stmtSession->close();
if (!$session) {
    die('جلسة الجرد غير موجودة.');
}

$lines = [];
$stmtLines = $conn->prepare("
    SELECT l.*, i.item_code, i.name AS item_name, i.category, i.unit
    FROM inventory_audit_lines l
    JOIN inventory_items i ON i.id = l.item_id
    WHERE l.session_id = ?
    ORDER BY i.name ASC
");
$stmtLines->bind_param('i', $sessionId);
$stmtLines->execute();
$resLines = $stmtLines->get_result();
while ($row = $resLines->fetch_assoc()) {
    $lines[] = $row;
}
$stmtLines->close();

$printStats = [
    'total_items' => count($lines),
    'counted_items' => 0,
    'matched_items' => 0,
    'variance_items' => 0,
    'variance_total' => 0.0,
];
foreach ($lines as $line) {
    if ($line['counted_qty'] !== null) {
        $printStats['counted_items']++;
        $variance = (float)($line['variance_qty'] ?? 0);
        if (abs($variance) <= 0.00001) {
            $printStats['matched_items']++;
        } else {
            $printStats['variance_items']++;
            $printStats['variance_total'] += $variance;
        }
    }
}
?>
<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>طباعة جرد المخزن</title>
    <style>
    body { font-family: Cairo, Tahoma, Arial, sans-serif; background:#f5f5f5; color:#111; margin:0; padding:24px; }
    .sheet { max-width: 210mm; margin:0 auto; background:#fff; padding:24px; box-shadow:0 12px 30px rgba(0,0,0,.12); }
    .header { display:flex; justify-content:space-between; gap:16px; border-bottom:2px solid #111; padding-bottom:12px; margin-bottom:18px; }
    .stats { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:10px; margin:0 0 18px; }
    .stat { border:1px solid #d8d8d8; padding:10px 12px; }
    .stat .n { font-size:20px; font-weight:700; margin-top:4px; }
    table { width:100%; border-collapse:collapse; font-size:13px; }
    th, td { border:1px solid #cfcfcf; padding:8px; text-align:right; }
    th { background:#efefef; }
    .muted { color:#666; font-size:13px; }
    .match-ok { background:#f1fbf4; }
    .match-bad { background:#fff4f3; }
    .print-bar { position:fixed; top:16px; left:16px; }
    .print-btn { background:#111; color:#fff; border:none; border-radius:8px; padding:12px 16px; cursor:pointer; }
    @media print {
        body { background:#fff; padding:0; }
        .sheet { box-shadow:none; max-width:100%; padding:10mm; }
        .print-bar { display:none; }
    }
    @media (max-width: 900px) {
        .stats { grid-template-columns:repeat(2,minmax(0,1fr)); }
    }

        
        /* Codex responsive print safety 2026-05-15 */
        @media (max-width: 680px) {
            .items-table tbody td[data-label],
            .inv-table tbody td[data-label],
            .royal-table tbody td[data-label],
            table.mobile-stack tbody td[data-label] {
                display: grid !important;
                grid-template-columns: minmax(92px, 36%) minmax(0, 1fr) !important;
                gap: 10px !important;
                align-items: start !important;
                padding: 12px !important;
                text-align: right !important;
                min-height: 0 !important;
                white-space: normal !important;
                overflow-wrap: anywhere !important;
                word-break: normal !important;
            }
            .items-table tbody td[data-label]::before,
            .inv-table tbody td[data-label]::before,
            .royal-table tbody td[data-label]::before,
            table.mobile-stack tbody td[data-label]::before {
                content: attr(data-label) !important;
                position: static !important;
                width: auto !important;
                min-width: 0 !important;
                max-width: none !important;
                color: var(--gold-soft, var(--gold, #d4af37)) !important;
                font-size: .78rem !important;
                font-weight: 800 !important;
                line-height: 1.5 !important;
                white-space: normal !important;
                overflow-wrap: anywhere !important;
            }
            .items-table td.desc,
            .inv-table td.desc,
            .royal-table td.desc {
                width: 100% !important;
                line-height: 1.7 !important;
            }
            .price-cell,
            .amount-cell,
            .money-cell {
                direction: ltr !important;
                unicode-bidi: plaintext !important;
            }
        }
        
        @media print {
            .table-responsive,
            .table-wrapper,
            .items-panel,
            .table-section,
            .royal-table-card,
            .table-container {
                overflow: visible !important;
                break-inside: auto !important;
                page-break-inside: auto !important;
            }
            table,
            .items-table,
            .inv-table,
            .royal-table,
            .print-master-table {
                table-layout: fixed !important;
                width: 100% !important;
                overflow: visible !important;
                border-collapse: collapse !important;
            }
            thead { display: table-header-group !important; }
            tfoot { display: table-footer-group !important; }
            tr,
            tbody tr,
            thead tr,
            tfoot tr,
            td,
            th {
                break-inside: avoid !important;
                page-break-inside: avoid !important;
            }
            td,
            th {
                overflow-wrap: anywhere !important;
                word-break: normal !important;
                vertical-align: top !important;
            }
            td::before,
            th::before {
                display: none !important;
            }
        }
        </style>
</head>
<body>
    <div class="print-bar">
        <button class="print-btn" onclick="window.print()">طباعة</button>
    </div>
    <div class="sheet">
        <div class="header">
            <div>
                <h1 style="margin:0 0 8px;">نموذج جرد المخزن</h1>
                <div class="muted">الجلسة: <?php echo app_h((string)($session['title'] ?? 'جرد')); ?></div>
                <div class="muted">المخزن: <?php echo app_h((string)($session['warehouse_name'] ?? '')); ?></div>
            </div>
            <div>
                <div class="muted">التاريخ: <?php echo app_h((string)($session['audit_date'] ?? '')); ?></div>
                <div class="muted">المنشئ: <?php echo app_h((string)($session['created_by_name'] ?? 'System')); ?></div>
                <div class="muted">الحالة: <?php echo ((string)($session['status'] ?? '') === 'applied') ? 'معتمد' : 'مسودة'; ?></div>
            </div>
        </div>

        <div class="stats">
            <div class="stat"><div class="muted">عدد الأصناف</div><div class="n"><?php echo number_format($printStats['total_items']); ?></div></div>
            <div class="stat"><div class="muted">تم عده</div><div class="n"><?php echo number_format($printStats['counted_items']); ?></div></div>
            <div class="stat"><div class="muted">مطابق</div><div class="n"><?php echo number_format($printStats['matched_items']); ?></div></div>
            <div class="stat"><div class="muted">أصناف بها فرق</div><div class="n"><?php echo number_format($printStats['variance_items']); ?></div></div>
        </div>

        <div class="muted" style="margin-bottom:14px;">
            ملخص المطابقة: إجمالي فروقات الكميات = <?php echo number_format($printStats['variance_total'], 2); ?>
        </div>

        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>الكود</th>
                    <th>الصنف</th>
                    <th>الفئة</th>
                    <th>الوحدة</th>
                    <th>رصيد النظام</th>
                    <th>العد الفعلي</th>
                    <th>الفرق</th>
                    <th>ملاحظات</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($lines as $index => $line): ?>
                    <?php $variance = (float)($line['variance_qty'] ?? 0); ?>
                    <tr class="<?php echo ($line['counted_qty'] === null) ? '' : ((abs($variance) <= 0.00001) ? 'match-ok' : 'match-bad'); ?>">
                        <td><?php echo (int)$index + 1; ?></td>
                        <td><?php echo app_h((string)($line['item_code'] ?? '')); ?></td>
                        <td><?php echo app_h((string)($line['item_name'] ?? '')); ?></td>
                        <td><?php echo app_h((string)($line['category'] ?? '-')); ?></td>
                        <td><?php echo app_h((string)($line['unit'] ?? '-')); ?></td>
                        <td><?php echo number_format((float)($line['system_qty'] ?? 0), 2); ?></td>
                        <td><?php echo ($line['counted_qty'] !== null) ? number_format((float)$line['counted_qty'], 2) : '................'; ?></td>
                        <td><?php echo ($line['counted_qty'] !== null) ? number_format($variance, 2) : '................'; ?></td>
                        <td><?php echo app_h((string)($line['notes'] ?? '')); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <div style="margin-top:20px;" class="muted">
            ملاحظات الجلسة: <?php echo app_h((string)($session['notes'] ?? '')); ?>
        </div>
    </div>
</body>
</html>
