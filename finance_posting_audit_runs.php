<?php
ob_start();
require 'auth.php';
require 'config.php';
require_once 'receipts_runtime.php';
app_handle_lang_switch($conn);
$isEnglish = app_current_lang($conn) === 'en';

if (!app_user_can('finance.reports.view')) {
    http_response_code(403);
    require 'header.php';
    echo "<div class='container' style='margin-top:30px;'><div style='background:#1d0f0f;border:1px solid #5b2020;border-radius:12px;padding:20px;color:#ffb3b3;'>"
        . app_h($isEnglish ? 'You are not authorized to access bulk run reports.' : 'غير مصرح لك بالدخول إلى تقارير التشغيل الجماعي.')
        . "</div></div>";
    require 'footer.php';
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && (string)($_POST['action'] ?? '') === 'rerun_bulk_safe') {
    if (!app_user_can('finance.transactions.update')) {
        $_SESSION['flash_error'] = app_tr('لا تملك صلاحية إعادة تشغيل المعالجة الجماعية.', 'You do not have permission to rerun bulk remediation.');
    } elseif (!app_verify_csrf($_POST['_token'] ?? '')) {
        $_SESSION['flash_error'] = app_tr('رمز التحقق غير صالح. حدّث الصفحة وحاول مجددًا.', 'Invalid verification token. Refresh the page and try again.');
    } else {
        $rerun = function_exists('financeRepairPostingAuditSectionBulk')
            ? financeRepairPostingAuditSectionBulk(
                $conn,
                (string)($_POST['section_key'] ?? ''),
                (string)($_POST['date_from'] ?? date('Y-m-01')),
                (string)($_POST['date_to'] ?? date('Y-m-t')),
                (string)($_SESSION['name'] ?? 'System'),
                200
            )
            : ['ok' => false, 'message' => app_tr('محرك المعالجة الجماعية غير متاح حاليًا.', 'Bulk remediation engine is currently unavailable.')];
        if (!empty($rerun['ok'])) {
            $_SESSION['flash_success'] = app_tr(
                'تمت إعادة التشغيل الجماعي الآمن. تمت معالجة ' . (int)($rerun['repaired_count'] ?? 0) . ' صف، وفشل ' . (int)($rerun['failed_count'] ?? 0) . '، والمتبقي ' . (int)($rerun['remaining_count'] ?? 0) . '.',
                'Safe bulk rerun completed. Repaired ' . (int)($rerun['repaired_count'] ?? 0) . ', failed ' . (int)($rerun['failed_count'] ?? 0) . ', remaining ' . (int)($rerun['remaining_count'] ?? 0) . '.'
            );
            $_SESSION['flash_posting_run_id'] = (int)($rerun['run_id'] ?? 0);
        } else {
            $_SESSION['flash_error'] = (string)($rerun['message'] ?? app_tr('تعذرت إعادة التشغيل الجماعي المطلوبة.', 'Could not rerun the requested bulk remediation.'));
        }
    }
    $redirectQuery = [
        'run_id' => (string)($_POST['run_id'] ?? ''),
        'date_from' => (string)($_POST['view_date_from'] ?? date('Y-m-01')),
        'date_to' => (string)($_POST['view_date_to'] ?? date('Y-m-t')),
    ];
    header('Location: finance_posting_audit_runs.php?' . http_build_query($redirectQuery));
    exit;
}

financeEnsurePostingAuditRunSchema($conn);
require 'header.php';

$dateFrom = (string)($_GET['date_from'] ?? date('Y-m-01'));
$dateTo = (string)($_GET['date_to'] ?? date('Y-m-t'));
$runId = (int)($_GET['run_id'] ?? 0);
$csrfToken = app_csrf_token();
$flashSuccess = (string)($_SESSION['flash_success'] ?? '');
$flashError = (string)($_SESSION['flash_error'] ?? '');
$flashPostingRunId = (int)($_SESSION['flash_posting_run_id'] ?? 0);
unset($_SESSION['flash_success'], $_SESSION['flash_error'], $_SESSION['flash_posting_run_id']);
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) $dateFrom = date('Y-m-01');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) $dateTo = date('Y-m-t');
if ($dateFrom > $dateTo) { $tmp = $dateFrom; $dateFrom = $dateTo; $dateTo = $tmp; }

$runs = [];
if ($stmt = $conn->prepare("
    SELECT *
    FROM finance_posting_audit_runs
    WHERE DATE(created_at) BETWEEN ? AND ?
    ORDER BY id DESC
    LIMIT 100
")) {
    $stmt->bind_param('ss', $dateFrom, $dateTo);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $runs[] = $row;
    }
    $stmt->close();
}

if ($runId <= 0 && $runs) {
    $runId = (int)($runs[0]['id'] ?? 0);
}

$selectedRun = null;
foreach ($runs as $row) {
    if ((int)($row['id'] ?? 0) === $runId) {
        $selectedRun = $row;
        break;
    }
}

$items = [];
if ($runId > 0 && $stmt = $conn->prepare("
    SELECT *
    FROM finance_posting_audit_run_items
    WHERE run_id = ?
    ORDER BY id ASC
    LIMIT 500
")) {
    $stmt->bind_param('i', $runId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $items[] = $row;
    }
    $stmt->close();
}

$sectionLabel = static function(string $key) use ($isEnglish): string {
    return match (strtolower(trim($key))) {
        'sales_invoice_issue' => $isEnglish ? 'Sales invoice issue' : 'إصدار فواتير المبيعات',
        'purchase_invoice_issue' => $isEnglish ? 'Purchase invoice issue' : 'إصدار فواتير المشتريات',
        'receipt_posting' => $isEnglish ? 'Receipt posting' : 'ترحيل السندات',
        'sales_invoice_void' => $isEnglish ? 'Sales invoice void' : 'إلغاء فواتير المبيعات',
        'purchase_invoice_void' => $isEnglish ? 'Purchase invoice void' : 'إلغاء فواتير المشتريات',
        'sales_return_issue' => $isEnglish ? 'Sales return issue' : 'إصدار مردودات المبيعات',
        'purchase_return_issue' => $isEnglish ? 'Purchase return issue' : 'إصدار مردودات المشتريات',
        'sales_return_refund' => $isEnglish ? 'Sales return refund' : 'رد مبالغ مردودات المبيعات',
        'purchase_return_refund' => $isEnglish ? 'Purchase return refund' : 'رد مبالغ مردودات المشتريات',
        'sales_return_void' => $isEnglish ? 'Sales return void' : 'إلغاء مردودات المبيعات',
        'purchase_return_void' => $isEnglish ? 'Purchase return void' : 'إلغاء مردودات المشتريات',
        default => $key,
    };
};

$runPreviewTotal = (int)($selectedRun['preview_total_count'] ?? 0);
$runScannedCount = (int)($selectedRun['scanned_count'] ?? 0);
$runRepairedCount = (int)($selectedRun['repaired_count'] ?? 0);
$runFailedCount = (int)($selectedRun['failed_count'] ?? 0);
$runRemainingCount = (int)($selectedRun['remaining_count'] ?? 0);
$runNetReduction = max(0, $runPreviewTotal - $runRemainingCount);
$runCoveragePct = $runPreviewTotal > 0 ? round(($runScannedCount / $runPreviewTotal) * 100, 1) : 0.0;
$runRepairYieldPct = $runScannedCount > 0 ? round(($runRepairedCount / $runScannedCount) * 100, 1) : 0.0;
$runSectionKey = strtolower(trim((string)($selectedRun['section_key'] ?? '')));
$rerunAllowedSections = ['sales_invoice_issue','purchase_invoice_issue','receipt_posting','sales_invoice_void','purchase_invoice_void','sales_return_issue','purchase_return_issue','sales_return_void','purchase_return_void'];
$canRerunSelected = $selectedRun && in_array($runSectionKey, $rerunAllowedSections, true) && app_user_can('finance.transactions.update');
$remainingIssuesQuery = [
    'date_from' => (string)($selectedRun['date_from'] ?? $dateFrom),
    'date_to' => (string)($selectedRun['date_to'] ?? $dateTo),
    'issues_only' => '1',
];
if ($runSectionKey !== '') {
    $remainingIssuesQuery['section_key'] = $runSectionKey;
}
$remainingIssuesUrl = 'finance_posting_audit.php?' . http_build_query($remainingIssuesQuery);
$failedReasonCounts = [];
foreach ($items as $itemRow) {
    $itemStatusKey = strtolower(trim((string)($itemRow['item_status'] ?? 'pending')));
    if ($itemStatusKey !== 'failed') {
        continue;
    }
    $itemMessage = trim((string)($itemRow['message'] ?? ''));
    if ($itemMessage === '') {
        $itemMessage = $isEnglish ? 'Unspecified failure' : 'فشل غير موصوف';
    }
    $failedReasonCounts[$itemMessage] = ($failedReasonCounts[$itemMessage] ?? 0) + 1;
}
arsort($failedReasonCounts);
?>
<div class="container" style="margin-top:30px;">
    <div style="display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap; margin-bottom:20px;">
        <h2 style="margin:0; color:var(--gold);"><?php echo app_h($isEnglish ? 'Bulk Run Reports' : 'تقارير التشغيل الجماعي'); ?></h2>
        <div style="display:flex; gap:10px; flex-wrap:wrap;">
            <a href="finance_posting_audit.php" style="background:#5b3a12; color:#fff; padding:10px 18px; text-decoration:none; border-radius:10px; font-weight:700;"><?php echo app_h($isEnglish ? 'Back to posting audit' : 'الرجوع إلى تدقيق الترحيل'); ?></a>
            <?php if ($selectedRun): ?>
                <a href="<?php echo app_h($remainingIssuesUrl); ?>" style="background:#0f3d2e; color:#fff; padding:10px 18px; text-decoration:none; border-radius:10px; font-weight:700;"><?php echo app_h($isEnglish ? 'Open remaining issues' : 'فتح المشكلات المتبقية'); ?></a>
            <?php endif; ?>
            <a href="finance_ledger.php" style="background:#123b63; color:#fff; padding:10px 18px; text-decoration:none; border-radius:10px; font-weight:700;"><?php echo app_h($isEnglish ? 'Open ledger' : 'دفتر الحركة'); ?></a>
            <a href="finance_reports.php" style="background:#1f2937; color:#fff; padding:10px 18px; text-decoration:none; border-radius:10px; font-weight:700;"><?php echo app_h($isEnglish ? 'Back to finance reports' : 'الرجوع للتقارير المالية'); ?></a>
        </div>
    </div>

    <?php if ($flashSuccess !== ''): ?>
        <div style="background:#0f2c1f; border:1px solid #1f6b49; color:#bbf7d0; border-radius:14px; padding:14px 18px; margin-bottom:18px; font-weight:700;">
            <?php echo app_h($flashSuccess); ?>
        </div>
    <?php endif; ?>
    <?php if ($flashError !== ''): ?>
        <div style="background:#2b1111; border:1px solid #7f1d1d; color:#fecaca; border-radius:14px; padding:14px 18px; margin-bottom:18px; font-weight:700;">
            <?php echo app_h($flashError); ?>
        </div>
    <?php endif; ?>
    <?php if ($flashPostingRunId > 0): ?>
        <div style="margin-bottom:18px;">
            <a href="finance_posting_audit_runs.php?run_id=<?php echo (int)$flashPostingRunId; ?>" style="display:inline-flex; align-items:center; gap:8px; background:#1f2937; color:#fff; padding:10px 14px; text-decoration:none; border-radius:10px; font-weight:800;">
                <?php echo app_h($isEnglish ? 'Open new run report' : 'فتح تقرير التشغيل الجديد'); ?> #<?php echo (int)$flashPostingRunId; ?>
            </a>
        </div>
    <?php endif; ?>

    <div style="background:#151515; border:1px solid #333; border-radius:16px; padding:18px; margin-bottom:18px;">
        <form method="GET" style="display:grid; grid-template-columns:repeat(auto-fit,minmax(180px,1fr)); gap:12px;">
            <div>
                <label style="display:block; margin-bottom:8px; color:#aaa;"><?php echo app_h($isEnglish ? 'Date from' : 'من تاريخ'); ?></label>
                <input type="date" name="date_from" value="<?php echo app_h($dateFrom); ?>" style="width:100%; background:#090909; border:1px solid #444; color:#fff; padding:12px; border-radius:8px;">
            </div>
            <div>
                <label style="display:block; margin-bottom:8px; color:#aaa;"><?php echo app_h($isEnglish ? 'Date to' : 'إلى تاريخ'); ?></label>
                <input type="date" name="date_to" value="<?php echo app_h($dateTo); ?>" style="width:100%; background:#090909; border:1px solid #444; color:#fff; padding:12px; border-radius:8px;">
            </div>
            <div>
                <label style="display:block; margin-bottom:8px; color:#aaa;"><?php echo app_h($isEnglish ? 'Run ID' : 'رقم التشغيل'); ?></label>
                <input type="number" name="run_id" value="<?php echo $runId > 0 ? (int)$runId : ''; ?>" style="width:100%; background:#090909; border:1px solid #444; color:#fff; padding:12px; border-radius:8px;">
            </div>
            <div style="display:flex; align-items:flex-end;">
                <button type="submit" style="width:100%; background:linear-gradient(135deg,#e5c75a,#b98713); color:#141414; border:none; padding:12px; border-radius:8px; font-weight:800;"><?php echo app_h($isEnglish ? 'Refresh' : 'تحديث'); ?></button>
            </div>
        </form>
    </div>

    <?php if ($selectedRun): ?>
        <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(180px,1fr)); gap:12px; margin-bottom:18px;">
            <div style="background:#151515; border:1px solid #333; border-radius:14px; padding:18px;">
                <div style="color:#aaa; margin-bottom:8px;"><?php echo app_h($isEnglish ? 'Run' : 'التشغيل'); ?></div>
                <div style="font-size:28px; font-weight:900; color:var(--gold);">#<?php echo (int)($selectedRun['id'] ?? 0); ?></div>
            </div>
            <div style="background:#151515; border:1px solid #333; border-radius:14px; padding:18px;">
                <div style="color:#aaa; margin-bottom:8px;"><?php echo app_h($isEnglish ? 'Before' : 'قبل التشغيل'); ?></div>
                <div style="font-size:28px; font-weight:900; color:#d1d5db;"><?php echo $runPreviewTotal; ?></div>
            </div>
            <div style="background:#151515; border:1px solid #333; border-radius:14px; padding:18px;">
                <div style="color:#aaa; margin-bottom:8px;"><?php echo app_h($isEnglish ? 'Repaired' : 'تم إصلاحه'); ?></div>
                <div style="font-size:28px; font-weight:900; color:#34d399;"><?php echo $runRepairedCount; ?></div>
            </div>
            <div style="background:#151515; border:1px solid #333; border-radius:14px; padding:18px;">
                <div style="color:#aaa; margin-bottom:8px;"><?php echo app_h($isEnglish ? 'Failed' : 'فشل'); ?></div>
                <div style="font-size:28px; font-weight:900; color:#f87171;"><?php echo $runFailedCount; ?></div>
            </div>
            <div style="background:#151515; border:1px solid #333; border-radius:14px; padding:18px;">
                <div style="color:#aaa; margin-bottom:8px;"><?php echo app_h($isEnglish ? 'After' : 'بعد التشغيل'); ?></div>
                <div style="font-size:28px; font-weight:900; color:#fbbf24;"><?php echo $runRemainingCount; ?></div>
            </div>
            <div style="background:#151515; border:1px solid #333; border-radius:14px; padding:18px;">
                <div style="color:#aaa; margin-bottom:8px;"><?php echo app_h($isEnglish ? 'Net Reduction' : 'صافي التحسن'); ?></div>
                <div style="font-size:28px; font-weight:900; color:#60a5fa;"><?php echo $runNetReduction; ?></div>
            </div>
        </div>

        <div style="background:#151515; border:1px solid #333; border-radius:16px; padding:18px; margin-bottom:18px;">
            <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(240px,1fr)); gap:10px; color:#ddd;">
                <div><strong><?php echo app_h($isEnglish ? 'Section' : 'القسم'); ?>:</strong> <?php echo app_h($sectionLabel((string)($selectedRun['section_key'] ?? ''))); ?></div>
                <div><strong><?php echo app_h($isEnglish ? 'Date window' : 'الفترة'); ?>:</strong> <?php echo app_h((string)($selectedRun['date_from'] ?? '')); ?> → <?php echo app_h((string)($selectedRun['date_to'] ?? '')); ?></div>
                <div><strong><?php echo app_h($isEnglish ? 'Actor' : 'المنفذ'); ?>:</strong> <?php echo app_h((string)($selectedRun['actor_name'] ?? '')); ?></div>
                <div><strong><?php echo app_h($isEnglish ? 'Preview total' : 'إجمالي المعاينة'); ?>:</strong> <?php echo $runPreviewTotal; ?></div>
                <div><strong><?php echo app_h($isEnglish ? 'Scanned' : 'تم فحصه'); ?>:</strong> <?php echo $runScannedCount; ?></div>
                <div><strong><?php echo app_h($isEnglish ? 'Limit' : 'الحد'); ?>:</strong> <?php echo (int)($selectedRun['repair_limit_count'] ?? 0); ?></div>
                <div><strong><?php echo app_h($isEnglish ? 'Created at' : 'وقت التشغيل'); ?>:</strong> <?php echo app_h((string)($selectedRun['created_at'] ?? '')); ?></div>
                <div><strong><?php echo app_h($isEnglish ? 'Coverage' : 'نسبة التغطية'); ?>:</strong> <?php echo number_format($runCoveragePct, 1); ?>%</div>
                <div><strong><?php echo app_h($isEnglish ? 'Repair yield' : 'كفاءة الإصلاح'); ?>:</strong> <?php echo number_format($runRepairYieldPct, 1); ?>%</div>
            </div>
            <?php if ($selectedRun): ?>
                <div style="display:flex; gap:10px; flex-wrap:wrap; align-items:center; margin-top:14px;">
                    <?php if ($canRerunSelected): ?>
                        <form method="POST" style="margin:0;">
                            <input type="hidden" name="_token" value="<?php echo app_h($csrfToken); ?>">
                            <input type="hidden" name="action" value="rerun_bulk_safe">
                            <input type="hidden" name="run_id" value="<?php echo (int)($selectedRun['id'] ?? 0); ?>">
                            <input type="hidden" name="section_key" value="<?php echo app_h($runSectionKey); ?>">
                            <input type="hidden" name="date_from" value="<?php echo app_h((string)($selectedRun['date_from'] ?? $dateFrom)); ?>">
                            <input type="hidden" name="date_to" value="<?php echo app_h((string)($selectedRun['date_to'] ?? $dateTo)); ?>">
                            <input type="hidden" name="view_date_from" value="<?php echo app_h($dateFrom); ?>">
                            <input type="hidden" name="view_date_to" value="<?php echo app_h($dateTo); ?>">
                            <button type="submit" style="background:#3b2f0f; color:#fff; border:1px solid #7c5a12; padding:10px 14px; border-radius:10px; font-weight:800; cursor:pointer;">
                                <?php echo app_h($isEnglish ? 'Rerun safe bulk remediation' : 'إعادة تشغيل المعالجة الجماعية الآمنة'); ?>
                            </button>
                        </form>
                    <?php else: ?>
                        <span style="display:inline-flex; align-items:center; gap:8px; background:#111827; color:#d1d5db; border:1px solid #374151; padding:10px 14px; border-radius:10px; font-weight:700;">
                            <?php echo app_h($isEnglish ? 'This run cannot be safely rerun from this page.' : 'هذا التشغيل لا يدعم إعادة التشغيل الآمنة من هذه الصفحة.'); ?>
                        </span>
                    <?php endif; ?>
                    <a href="<?php echo app_h($remainingIssuesUrl); ?>" style="background:#0f3d2e; color:#fff; padding:10px 14px; text-decoration:none; border-radius:10px; font-weight:800;">
                        <?php echo app_h($isEnglish ? 'Open remaining issues' : 'فتح المشكلات المتبقية'); ?>
                    </a>
                </div>
            <?php endif; ?>
        </div>

        <div style="background:#111827; border:1px solid #334155; border-radius:16px; padding:18px; margin-bottom:18px;">
            <h3 style="margin:0 0 12px; color:#e5c75a;"><?php echo app_h($isEnglish ? 'Post-run Diff' : 'فرق ما بعد التشغيل'); ?></h3>
            <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(220px,1fr)); gap:10px; color:#e5e7eb;">
                <div><strong><?php echo app_h($isEnglish ? 'Before' : 'قبل'); ?>:</strong> <?php echo $runPreviewTotal; ?></div>
                <div><strong><?php echo app_h($isEnglish ? 'After' : 'بعد'); ?>:</strong> <?php echo $runRemainingCount; ?></div>
                <div><strong><?php echo app_h($isEnglish ? 'Net reduction' : 'صافي التحسن'); ?>:</strong> <?php echo $runNetReduction; ?></div>
                <div><strong><?php echo app_h($isEnglish ? 'Failed within run' : 'فشل أثناء التشغيل'); ?>:</strong> <?php echo $runFailedCount; ?></div>
            </div>
        </div>

        <?php if ($failedReasonCounts): ?>
            <div style="background:#1f1111; border:1px solid #7f1d1d; border-radius:16px; padding:18px; margin-bottom:18px;">
                <div style="display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap; margin-bottom:12px;">
                    <h3 style="margin:0; color:#fca5a5;"><?php echo app_h($isEnglish ? 'Failure Reasons' : 'أسباب الفشل'); ?></h3>
                    <a href="<?php echo app_h($remainingIssuesUrl); ?>" style="background:#2b3a0f; color:#fff; padding:9px 12px; text-decoration:none; border-radius:8px; font-weight:800;"><?php echo app_h($isEnglish ? 'Review remaining issues' : 'مراجعة المشكلات المتبقية'); ?></a>
                </div>
                <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(260px,1fr)); gap:10px;">
                    <?php foreach ($failedReasonCounts as $reasonText => $reasonCount): ?>
                        <div style="background:#140c0c; border:1px solid #5b2020; border-radius:12px; padding:14px;">
                            <div style="font-size:24px; font-weight:900; color:#f87171; margin-bottom:8px;"><?php echo (int)$reasonCount; ?></div>
                            <div style="color:#f3f4f6; line-height:1.6;"><?php echo app_h((string)$reasonText); ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <div style="background:#1a1a1a; padding:20px; border-radius:14px; border:1px solid #333; overflow:auto; margin-bottom:18px;">
            <h3 style="margin:0 0 14px; color:var(--gold);"><?php echo app_h($isEnglish ? 'Run Items' : 'عناصر التشغيل'); ?></h3>
            <table style="width:100%; border-collapse:collapse; color:#fff;">
                <thead>
                    <tr style="border-bottom:2px solid #333; color:var(--gold);">
                        <th style="padding:10px; text-align:right;">#</th>
                        <th style="padding:10px; text-align:right;"><?php echo app_h($isEnglish ? 'Section' : 'القسم'); ?></th>
                        <th style="padding:10px; text-align:right;"><?php echo app_h($isEnglish ? 'Source' : 'المصدر'); ?></th>
                        <th style="padding:10px; text-align:right;"><?php echo app_h($isEnglish ? 'Ledger' : 'القيد'); ?></th>
                        <th style="padding:10px; text-align:right;"><?php echo app_h($isEnglish ? 'Status' : 'الحالة'); ?></th>
                        <th style="padding:10px; text-align:right;"><?php echo app_h($isEnglish ? 'Message' : 'الرسالة'); ?></th>
                        <th style="padding:10px; text-align:right;"><?php echo app_h($isEnglish ? 'Action' : 'الإجراء'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($items): foreach ($items as $row): ?>
                        <?php
                        $itemSourceId = (int)($row['source_id'] ?? 0);
                        $itemLedgerId = (int)($row['ledger_id'] ?? 0);
                        $itemUrl = function_exists('financePostingAuditRunDrillUrl')
                            ? financePostingAuditRunDrillUrl((string)($row['section_key'] ?? ''), $itemSourceId, $itemLedgerId)
                            : 'finance_posting_audit.php';
                        $itemStatus = strtolower(trim((string)($row['item_status'] ?? 'pending')));
                        ?>
                        <tr style="border-bottom:1px solid #333;">
                            <td style="padding:12px;"><?php echo (int)($row['id'] ?? 0); ?></td>
                            <td style="padding:12px;"><?php echo app_h($sectionLabel((string)($row['section_key'] ?? ''))); ?></td>
                            <td style="padding:12px;">#<?php echo $itemSourceId; ?></td>
                            <td style="padding:12px;"><?php echo $itemLedgerId > 0 ? ('#' . $itemLedgerId) : '-'; ?></td>
                            <td style="padding:12px; color:<?php echo $itemStatus === 'repaired' ? '#34d399' : ($itemStatus === 'failed' ? '#f87171' : '#fbbf24'); ?>; font-weight:800;">
                                <?php echo app_h($itemStatus); ?>
                            </td>
                            <td style="padding:12px;"><?php echo app_h((string)($row['message'] ?? '')); ?></td>
                            <td style="padding:12px;">
                                <a href="<?php echo app_h($itemUrl); ?>" style="color:#93c5fd; text-decoration:none; font-weight:700;">
                                    <?php echo app_h($isEnglish ? 'Open' : 'فتح'); ?>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; else: ?>
                        <tr><td colspan="7" style="text-align:center; padding:26px; color:#777;"><?php echo app_h($isEnglish ? 'No run items found.' : 'لا توجد عناصر مسجلة لهذا التشغيل.'); ?></td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <div style="background:#1a1a1a; padding:20px; border-radius:14px; border:1px solid #333; overflow:auto;">
        <h3 style="margin:0 0 14px; color:var(--gold);"><?php echo app_h($isEnglish ? 'Recent Runs' : 'آخر عمليات التشغيل'); ?></h3>
        <table style="width:100%; border-collapse:collapse; color:#fff;">
            <thead>
                <tr style="border-bottom:2px solid #333; color:var(--gold);">
                    <th style="padding:10px; text-align:right;">#</th>
                    <th style="padding:10px; text-align:right;"><?php echo app_h($isEnglish ? 'Section' : 'القسم'); ?></th>
                    <th style="padding:10px; text-align:right;"><?php echo app_h($isEnglish ? 'Before' : 'قبل'); ?></th>
                    <th style="padding:10px; text-align:right;"><?php echo app_h($isEnglish ? 'Repaired' : 'تم إصلاحه'); ?></th>
                    <th style="padding:10px; text-align:right;"><?php echo app_h($isEnglish ? 'Failed' : 'فشل'); ?></th>
                    <th style="padding:10px; text-align:right;"><?php echo app_h($isEnglish ? 'After' : 'بعد'); ?></th>
                    <th style="padding:10px; text-align:right;"><?php echo app_h($isEnglish ? 'Delta' : 'الفرق'); ?></th>
                    <th style="padding:10px; text-align:right;"><?php echo app_h($isEnglish ? 'Created' : 'وقت التشغيل'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if ($runs): foreach ($runs as $row): ?>
                    <tr style="border-bottom:1px solid #333;">
                        <td style="padding:12px; font-weight:800;">
                            <a href="finance_posting_audit_runs.php?run_id=<?php echo (int)($row['id'] ?? 0); ?>&date_from=<?php echo app_h($dateFrom); ?>&date_to=<?php echo app_h($dateTo); ?>" style="color:#93c5fd; text-decoration:none;">
                                #<?php echo (int)($row['id'] ?? 0); ?>
                            </a>
                        </td>
                        <td style="padding:12px;"><?php echo app_h($sectionLabel((string)($row['section_key'] ?? ''))); ?></td>
                        <td style="padding:12px; color:#d1d5db; font-weight:800;"><?php echo (int)($row['preview_total_count'] ?? 0); ?></td>
                        <td style="padding:12px; color:#34d399; font-weight:800;"><?php echo (int)($row['repaired_count'] ?? 0); ?></td>
                        <td style="padding:12px; color:#f87171; font-weight:800;"><?php echo (int)($row['failed_count'] ?? 0); ?></td>
                        <td style="padding:12px; color:#fbbf24; font-weight:800;"><?php echo (int)($row['remaining_count'] ?? 0); ?></td>
                        <td style="padding:12px; color:#60a5fa; font-weight:800;"><?php echo max(0, (int)($row['preview_total_count'] ?? 0) - (int)($row['remaining_count'] ?? 0)); ?></td>
                        <td style="padding:12px;"><?php echo app_h((string)($row['created_at'] ?? '')); ?></td>
                    </tr>
                <?php endforeach; else: ?>
                    <tr><td colspan="8" style="text-align:center; padding:26px; color:#777;"><?php echo app_h($isEnglish ? 'No bulk runs found.' : 'لا توجد عمليات تشغيل جماعي مسجلة.'); ?></td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php require 'footer.php'; ob_end_flush(); ?>
