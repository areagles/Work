<?php
ob_start();
require 'auth.php';
require 'config.php';

$isEnglish = app_current_lang($conn) === 'en';
$canPricingView = app_user_can('pricing.view') || app_is_super_user() || ((string)($_SESSION['role'] ?? '') === 'admin');
$canPricingManage = app_user_can('pricing.settings') || app_is_super_user() || ((string)($_SESSION['role'] ?? '') === 'admin');
$permissionDeniedMessage = $isEnglish ? '⛔ You do not have permission to access pricing files.' : '⛔ لا تملك صلاحية الوصول إلى ملفات التسعير.';

$flashMessage = '';
$flashType = 'ok';
$postAction = (string)($_POST['action'] ?? '');
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $postAction !== '') {
    if (!app_verify_csrf($_POST['_csrf_token'] ?? '')) {
        $flashMessage = $isEnglish ? 'Invalid session. Reload the page and try again.' : 'انتهت الجلسة. أعد تحميل الصفحة ثم حاول مرة أخرى.';
        $flashType = 'error';
    } elseif (!$canPricingManage && in_array($postAction, ['delete_record', 'duplicate_record', 'update_record_meta'], true)) {
        $flashMessage = $isEnglish ? 'You do not have permission to manage pricing files.' : 'لا تملك صلاحية إدارة ملفات التسعير.';
        $flashType = 'error';
    } elseif ($postAction === 'delete_record') {
        $deleteId = (int)($_POST['record_id'] ?? 0);
        if ($deleteId > 0) {
            $stmtDelete = $conn->prepare("DELETE FROM app_pricing_records WHERE id = ? LIMIT 1");
            if ($stmtDelete) {
                $stmtDelete->bind_param('i', $deleteId);
                $stmtDelete->execute();
                $stmtDelete->close();
                app_safe_redirect('pricing_records.php?deleted=1', 'pricing_records.php');
            } else {
                $flashMessage = $isEnglish ? 'Unable to delete this pricing file right now.' : 'تعذر حذف ملف التسعير حالياً.';
                $flashType = 'error';
            }
        }
    } elseif ($postAction === 'duplicate_record') {
        $sourceId = (int)($_POST['record_id'] ?? 0);
        $stmtDup = $conn->prepare("SELECT client_id, operation_name, pricing_mode, qty, unit_label, total_amount, notes, snapshot_json, created_by_user_id, created_by_name FROM app_pricing_records WHERE id = ? LIMIT 1");
        if ($stmtDup) {
            $stmtDup->bind_param('i', $sourceId);
            $stmtDup->execute();
            $sourceRow = $stmtDup->get_result()->fetch_assoc();
            $stmtDup->close();
            if ($sourceRow) {
                $newOperation = trim((string)$sourceRow['operation_name']) . ' - ' . ($isEnglish ? 'Copy' : 'نسخة');
                $stmtInsert = $conn->prepare("
                    INSERT INTO app_pricing_records
                    (client_id, operation_name, pricing_mode, qty, unit_label, total_amount, notes, snapshot_json, created_by_user_id, created_by_name)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                if ($stmtInsert) {
                    $stmtInsert->bind_param(
                        'issdsdssis',
                        $sourceRow['client_id'],
                        $newOperation,
                        $sourceRow['pricing_mode'],
                        $sourceRow['qty'],
                        $sourceRow['unit_label'],
                        $sourceRow['total_amount'],
                        $sourceRow['notes'],
                        $sourceRow['snapshot_json'],
                        $sourceRow['created_by_user_id'],
                        $sourceRow['created_by_name']
                    );
                    $stmtInsert->execute();
                    $newId = (int)$stmtInsert->insert_id;
                    $stmtInsert->close();
                    if ($newId > 0) {
                        $newRef = 'PRC-' . str_pad((string)$newId, 5, '0', STR_PAD_LEFT);
                        $stmtRef = $conn->prepare("UPDATE app_pricing_records SET pricing_ref = ? WHERE id = ?");
                        if ($stmtRef) {
                            $stmtRef->bind_param('si', $newRef, $newId);
                            $stmtRef->execute();
                            $stmtRef->close();
                        }
                        app_safe_redirect('pricing_records.php?duplicated=1', 'pricing_records.php');
                    }
                }
            }
        }
        $flashMessage = $flashMessage ?: ($isEnglish ? 'Unable to duplicate this pricing file.' : 'تعذر تكرار ملف التسعير.');
        $flashType = 'error';
    } elseif ($postAction === 'update_record_meta') {
        $editId = (int)($_POST['record_id'] ?? 0);
        $operationName = trim((string)($_POST['operation_name'] ?? ''));
        $notes = trim((string)($_POST['notes'] ?? ''));
        if ($editId > 0 && $operationName !== '') {
            $stmtUpdate = $conn->prepare("UPDATE app_pricing_records SET operation_name = ?, notes = ?, updated_at = NOW() WHERE id = ? LIMIT 1");
            if ($stmtUpdate) {
                $stmtUpdate->bind_param('ssi', $operationName, $notes, $editId);
                $stmtUpdate->execute();
                $stmtUpdate->close();
                app_safe_redirect('pricing_records.php?updated=1', 'pricing_records.php');
            }
        }
        $flashMessage = $flashMessage ?: ($isEnglish ? 'Unable to update pricing file data.' : 'تعذر تحديث بيانات ملف التسعير.');
        $flashType = 'error';
    }
}

$savedNotice = isset($_GET['saved']) ? ($isEnglish ? 'Pricing file saved successfully.' : 'تم حفظ ملف التسعير بنجاح.') : '';
$deletedNotice = isset($_GET['deleted']) ? ($isEnglish ? 'Pricing file deleted successfully.' : 'تم حذف ملف التسعير بنجاح.') : '';
$duplicatedNotice = isset($_GET['duplicated']) ? ($isEnglish ? 'Pricing file duplicated successfully.' : 'تم تكرار ملف التسعير بنجاح.') : '';
$updatedNotice = isset($_GET['updated']) ? ($isEnglish ? 'Pricing file updated successfully.' : 'تم تحديث ملف التسعير بنجاح.') : '';
if ($savedNotice !== '') {
    $flashMessage = $savedNotice;
    $flashType = 'ok';
}
if ($deletedNotice !== '') {
    $flashMessage = $deletedNotice;
    $flashType = 'ok';
}
if ($duplicatedNotice !== '') {
    $flashMessage = $duplicatedNotice;
    $flashType = 'ok';
}
if ($updatedNotice !== '') {
    $flashMessage = $updatedNotice;
    $flashType = 'ok';
}

$filterClient = trim((string)($_GET['client'] ?? ''));
$filterMode = trim((string)($_GET['mode'] ?? ''));
$filterFrom = trim((string)($_GET['date_from'] ?? ''));
$filterTo = trim((string)($_GET['date_to'] ?? ''));
$filterSearch = trim((string)($_GET['q'] ?? ''));
$editRecordId = (int)($_GET['edit'] ?? 0);

$records = [];
$sql = "
    SELECT pr.*, c.name AS client_name
    FROM app_pricing_records pr
    LEFT JOIN clients c ON c.id = pr.client_id
";
$where = [];
$types = '';
$params = [];

if ($filterClient !== '') {
    $where[] = "pr.client_id = ?";
    $types .= 'i';
    $params[] = (int)$filterClient;
}
if (in_array($filterMode, ['general', 'books'], true)) {
    $where[] = "pr.pricing_mode = ?";
    $types .= 's';
    $params[] = $filterMode;
}
if ($filterFrom !== '') {
    $where[] = "DATE(pr.created_at) >= ?";
    $types .= 's';
    $params[] = $filterFrom;
}
if ($filterTo !== '') {
    $where[] = "DATE(pr.created_at) <= ?";
    $types .= 's';
    $params[] = $filterTo;
}
if ($filterSearch !== '') {
    $where[] = "(pr.pricing_ref LIKE ? OR pr.operation_name LIKE ? OR c.name LIKE ?)";
    $types .= 'sss';
    $searchLike = '%' . $filterSearch . '%';
    $params[] = $searchLike;
    $params[] = $searchLike;
    $params[] = $searchLike;
}
if (!empty($where)) {
    $sql .= " WHERE " . implode(' AND ', $where);
}
$sql .= " ORDER BY pr.id DESC LIMIT 300";

$stmtRecords = $conn->prepare($sql);
if ($stmtRecords) {
    if ($types !== '') {
        $stmtRecords->bind_param($types, ...$params);
    }
    $stmtRecords->execute();
    $res = $stmtRecords->get_result();
}
if (isset($res) && $res instanceof mysqli_result) {
    while ($row = $res->fetch_assoc()) {
        $records[] = $row;
    }
    $res->close();
}
if (isset($stmtRecords) && $stmtRecords instanceof mysqli_stmt) {
    $stmtRecords->close();
}

if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $filename = 'pricing-records-' . date('Y-m-d-His') . '.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    if ($out) {
        fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
        fputcsv($out, [
            $isEnglish ? 'Ref' : 'المرجع',
            $isEnglish ? 'Client' : 'العميل',
            $isEnglish ? 'Operation' : 'العملية',
            $isEnglish ? 'Mode' : 'الوضع',
            $isEnglish ? 'Quantity' : 'الكمية',
            $isEnglish ? 'Unit' : 'الوحدة',
            $isEnglish ? 'Total' : 'الإجمالي',
            $isEnglish ? 'Date' : 'التاريخ',
        ]);
        foreach ($records as $row) {
            fputcsv($out, [
                (string)($row['pricing_ref'] ?: ('PRC-' . str_pad((string)$row['id'], 5, '0', STR_PAD_LEFT))),
                (string)($row['client_name'] ?? '-'),
                (string)($row['operation_name'] ?? '-'),
                ((string)($row['pricing_mode'] ?? 'general')) === 'books' ? ($isEnglish ? 'Books / Magazines' : 'كتب / مجلات') : ($isEnglish ? 'General' : 'عادي'),
                number_format((float)($row['qty'] ?? 0), 2, '.', ''),
                (string)($row['unit_label'] ?? ''),
                number_format((float)($row['total_amount'] ?? 0), 2, '.', ''),
                (string)($row['created_at'] ?? ''),
            ]);
        }
        fclose($out);
    }
    exit;
}

$clients = [];
$clientsRes = $conn->query("SELECT id, name FROM clients ORDER BY name ASC");
if ($clientsRes instanceof mysqli_result) {
    while ($clientRow = $clientsRes->fetch_assoc()) {
        $clients[] = $clientRow;
    }
    $clientsRes->close();
}

$editRecord = null;
if ($editRecordId > 0) {
    $stmtEditRecord = $conn->prepare("SELECT id, operation_name, notes FROM app_pricing_records WHERE id = ? LIMIT 1");
    if ($stmtEditRecord) {
        $stmtEditRecord->bind_param('i', $editRecordId);
        $stmtEditRecord->execute();
        $editRecord = $stmtEditRecord->get_result()->fetch_assoc();
        $stmtEditRecord->close();
    }
}

$statsCount = count($records);
$statsTotal = 0.0;
$statsGeneral = 0;
$statsBooks = 0;
foreach ($records as $statsRow) {
    $statsTotal += (float)($statsRow['total_amount'] ?? 0);
    if ((string)($statsRow['pricing_mode'] ?? 'general') === 'books') {
        $statsBooks++;
    } else {
        $statsGeneral++;
    }
}

require 'header.php';
if (!$canPricingView) {
    echo "<div class='container' style='margin-top:30px;'><div style='background:#1d0f0f;border:1px solid #5b2020;border-radius:12px;padding:20px;color:#ffb3b3;'>" . app_h($permissionDeniedMessage) . "</div></div>";
    require 'footer.php';
    ob_end_flush();
    exit;
}
?>
<style>
    .pricing-records-shell{max-width:1440px;margin:26px auto;padding:0 14px}
    .pricing-records-head{display:flex;justify-content:space-between;align-items:flex-end;gap:16px;flex-wrap:wrap;margin-bottom:18px}
    .pricing-records-title h1{margin:0;color:#fff;font-size:2rem;font-weight:900}
    .pricing-records-title p{margin:10px 0 0;color:#9ca3af;font-size:1rem}
    .pricing-records-actions{display:flex;gap:10px;flex-wrap:wrap}
    .pricing-records-btn{display:inline-flex;align-items:center;justify-content:center;gap:10px;min-height:48px;padding:0 18px;border-radius:14px;border:1px solid rgba(212,175,55,.28);background:linear-gradient(135deg,rgba(229,196,79,.16),rgba(201,154,23,.08));color:#f7e7a1;text-decoration:none;font-weight:800;box-shadow:0 16px 40px rgba(0,0,0,.24)}
    .pricing-records-btn.primary{background:linear-gradient(135deg,#e5c44f,#c99a17);color:#111;border-color:transparent}
    .pricing-records-btn.danger{background:linear-gradient(135deg,#5a1a1a,#7c2323);color:#ffd7d7;border-color:rgba(255,120,120,.2)}
    .pricing-records-card{background:linear-gradient(180deg,rgba(19,19,19,.98),rgba(11,11,11,.98));border:1px solid rgba(212,175,55,.16);border-radius:24px;box-shadow:0 20px 70px rgba(0,0,0,.34);overflow:hidden}
    .pricing-records-stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin-bottom:16px}
    .pricing-stat-card{background:linear-gradient(180deg,rgba(19,19,19,.98),rgba(11,11,11,.98));border:1px solid rgba(212,175,55,.16);border-radius:20px;padding:18px;box-shadow:0 20px 70px rgba(0,0,0,.24)}
    .pricing-stat-card small{display:block;color:#9ca3af;margin-bottom:8px}
    .pricing-stat-card strong{display:block;color:#fff;font-size:1.7rem;font-weight:900}
    .pricing-edit-card{margin-bottom:16px;padding:18px;background:linear-gradient(180deg,rgba(19,19,19,.98),rgba(11,11,11,.98));border:1px solid rgba(212,175,55,.16);border-radius:20px}
    .pricing-edit-form{display:grid;grid-template-columns:2fr 2fr auto;gap:12px;align-items:end}
    .pricing-edit-notes{grid-column:1 / -1}
    .pricing-records-toolbar{padding:18px;border-bottom:1px solid rgba(255,255,255,.06);background:rgba(255,255,255,.02)}
    .pricing-records-filters{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:12px}
    .pricing-records-field label{display:block;margin-bottom:8px;color:#f3f4f6;font-weight:700;font-size:.92rem}
    .pricing-records-input,.pricing-records-select{width:100%;min-height:48px;border-radius:14px;border:1px solid rgba(212,175,55,.16);background:#101010;color:#fff;padding:0 14px;box-sizing:border-box}
    .pricing-records-filter-actions{display:flex;gap:10px;align-items:flex-end}
    .pricing-records-table-wrap{overflow:auto}
    .pricing-records-table{width:100%;border-collapse:separate;border-spacing:0;min-width:980px}
    .pricing-records-table th{background:rgba(212,175,55,.08);color:#f1d36d;padding:16px 14px;font-size:.93rem;font-weight:800;border-bottom:1px solid rgba(212,175,55,.16);text-align:right;white-space:nowrap}
    .pricing-records-table td{padding:16px 14px;border-bottom:1px solid rgba(255,255,255,.06);color:#f3f4f6;vertical-align:middle}
    .pricing-records-table tr:last-child td{border-bottom:none}
    .pricing-records-table .muted{color:#9ca3af}
    .pricing-chip{display:inline-flex;align-items:center;gap:6px;min-height:32px;padding:0 12px;border-radius:999px;background:rgba(212,175,55,.1);color:#f7e7a1;font-size:.85rem;font-weight:800}
    .pricing-records-empty{padding:40px 20px;text-align:center;color:#9ca3af}
    .pricing-records-row-actions{display:flex;gap:8px;flex-wrap:wrap}
    .pricing-records-icon-btn{display:inline-flex;align-items:center;justify-content:center;width:42px;height:42px;border-radius:12px;border:1px solid rgba(255,255,255,.09);background:#161616;color:#f4f4f5;text-decoration:none}
    .pricing-records-icon-btn:hover{border-color:rgba(212,175,55,.35);color:#f7e7a1}
    .pricing-records-alert{margin-bottom:16px;padding:14px 16px;border-radius:16px;font-weight:700}
    .pricing-records-alert.ok{background:rgba(16,96,61,.18);color:#8ff0bf;border:1px solid rgba(37,160,104,.36)}
    .pricing-records-alert.error{background:rgba(120,22,22,.18);color:#ffb8b8;border:1px solid rgba(190,68,68,.36)}
    @media (max-width: 768px){
        .pricing-records-shell{padding:0 10px}
        .pricing-records-title h1{font-size:1.55rem}
        .pricing-records-btn{width:100%}
        .pricing-records-actions{width:100%}
        .pricing-records-stats{grid-template-columns:1fr}
        .pricing-edit-form{grid-template-columns:1fr}
        .pricing-records-filters{grid-template-columns:1fr}
        .pricing-records-filter-actions{flex-direction:column}
        .pricing-records-filter-actions .pricing-records-btn{width:100%}
    }
</style>
<div class="pricing-records-shell">
    <div class="pricing-records-head">
        <div class="pricing-records-title">
            <h1><?php echo app_h($isEnglish ? 'Pricing Files' : 'ملفات التسعير'); ?></h1>
            <p><?php echo app_h($isEnglish ? 'Saved pricing jobs for review, printing, reloading, and conversion into quotes or work orders.' : 'ملفات التسعير المحفوظة للمراجعة والطباعة وإعادة التحميل والتحويل إلى عرض سعر أو أمر شغل.'); ?></p>
        </div>
        <div class="pricing-records-actions">
            <a href="pricing_module.php" class="pricing-records-btn primary"><i class="fa-solid fa-plus"></i> <?php echo app_h($isEnglish ? 'New Pricing' : 'تسعير جديد'); ?></a>
            <a href="pricing_records.php?<?php echo app_h(http_build_query(array_filter(['client' => $filterClient, 'mode' => $filterMode, 'date_from' => $filterFrom, 'date_to' => $filterTo, 'q' => $filterSearch, 'export' => 'csv']))); ?>" class="pricing-records-btn"><i class="fa-solid fa-file-csv"></i> <?php echo app_h($isEnglish ? 'Export CSV' : 'تصدير CSV'); ?></a>
            <button type="button" class="pricing-records-btn" onclick="window.print()"><i class="fa-solid fa-print"></i> <?php echo app_h($isEnglish ? 'Print List' : 'طباعة القائمة'); ?></button>
            <a href="dashboard.php" class="pricing-records-btn"><i class="fa-solid fa-arrow-right"></i> <?php echo app_h($isEnglish ? 'Back to Dashboard' : 'العودة للرئيسية'); ?></a>
        </div>
    </div>

    <?php if ($flashMessage !== ''): ?>
        <div class="pricing-records-alert <?php echo app_h($flashType); ?>"><?php echo app_h($flashMessage); ?></div>
    <?php endif; ?>

    <div class="pricing-records-stats">
        <div class="pricing-stat-card"><small><?php echo app_h($isEnglish ? 'Displayed Files' : 'الملفات المعروضة'); ?></small><strong><?php echo (int)$statsCount; ?></strong></div>
        <div class="pricing-stat-card"><small><?php echo app_h($isEnglish ? 'Displayed Total' : 'إجمالي القيمة المعروضة'); ?></small><strong><?php echo app_h(number_format($statsTotal, 2)); ?></strong></div>
        <div class="pricing-stat-card"><small><?php echo app_h($isEnglish ? 'General Jobs' : 'العمليات العادية'); ?></small><strong><?php echo (int)$statsGeneral; ?></strong></div>
        <div class="pricing-stat-card"><small><?php echo app_h($isEnglish ? 'Books / Magazines' : 'الكتب / المجلات'); ?></small><strong><?php echo (int)$statsBooks; ?></strong></div>
    </div>

    <?php if ($canPricingManage && $editRecord): ?>
        <div class="pricing-edit-card">
            <form method="post" class="pricing-edit-form">
                <?php echo app_csrf_input(); ?>
                <input type="hidden" name="action" value="update_record_meta">
                <input type="hidden" name="record_id" value="<?php echo (int)$editRecord['id']; ?>">
                <div class="pricing-records-field">
                    <label><?php echo app_h($isEnglish ? 'Operation Name' : 'اسم العملية'); ?></label>
                    <input type="text" name="operation_name" class="pricing-records-input" value="<?php echo app_h((string)($editRecord['operation_name'] ?? '')); ?>" required>
                </div>
                <div class="pricing-records-field pricing-edit-notes">
                    <label><?php echo app_h($isEnglish ? 'Notes' : 'ملاحظات'); ?></label>
                    <textarea name="notes" class="pricing-records-input" style="padding:14px;min-height:120px;"><?php echo app_h((string)($editRecord['notes'] ?? '')); ?></textarea>
                </div>
                <div class="pricing-records-filter-actions">
                    <button type="submit" class="pricing-records-btn primary"><i class="fa-solid fa-floppy-disk"></i> <?php echo app_h($isEnglish ? 'Save Changes' : 'حفظ التعديلات'); ?></button>
                    <a href="pricing_records.php" class="pricing-records-btn"><i class="fa-solid fa-xmark"></i> <?php echo app_h($isEnglish ? 'Cancel' : 'إلغاء'); ?></a>
                </div>
            </form>
        </div>
    <?php endif; ?>

    <div class="pricing-records-card">
        <div class="pricing-records-toolbar">
            <form method="get" class="pricing-records-filters">
                <div class="pricing-records-field">
                    <label><?php echo app_h($isEnglish ? 'Client' : 'العميل'); ?></label>
                    <select name="client" class="pricing-records-select">
                        <option value=""><?php echo app_h($isEnglish ? 'All Clients' : 'كل العملاء'); ?></option>
                        <?php foreach ($clients as $clientRow): ?>
                            <option value="<?php echo (int)$clientRow['id']; ?>" <?php echo ((string)(int)$clientRow['id'] === $filterClient) ? 'selected' : ''; ?>>
                                <?php echo app_h((string)$clientRow['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="pricing-records-field">
                    <label><?php echo app_h($isEnglish ? 'Mode' : 'الوضع'); ?></label>
                    <select name="mode" class="pricing-records-select">
                        <option value=""><?php echo app_h($isEnglish ? 'All Modes' : 'كل الأنواع'); ?></option>
                        <option value="general" <?php echo $filterMode === 'general' ? 'selected' : ''; ?>><?php echo app_h($isEnglish ? 'General' : 'عادي'); ?></option>
                        <option value="books" <?php echo $filterMode === 'books' ? 'selected' : ''; ?>><?php echo app_h($isEnglish ? 'Books / Magazines' : 'كتب / مجلات'); ?></option>
                    </select>
                </div>
                <div class="pricing-records-field">
                    <label><?php echo app_h($isEnglish ? 'From' : 'من تاريخ'); ?></label>
                    <input type="date" name="date_from" class="pricing-records-input" value="<?php echo app_h($filterFrom); ?>">
                </div>
                <div class="pricing-records-field">
                    <label><?php echo app_h($isEnglish ? 'To' : 'إلى تاريخ'); ?></label>
                    <input type="date" name="date_to" class="pricing-records-input" value="<?php echo app_h($filterTo); ?>">
                </div>
                <div class="pricing-records-field">
                    <label><?php echo app_h($isEnglish ? 'Search' : 'بحث'); ?></label>
                    <input type="text" name="q" class="pricing-records-input" value="<?php echo app_h($filterSearch); ?>" placeholder="<?php echo app_h($isEnglish ? 'Ref / client / operation' : 'مرجع / عميل / عملية'); ?>">
                </div>
                <div class="pricing-records-filter-actions">
                    <button type="submit" class="pricing-records-btn primary"><i class="fa-solid fa-filter"></i> <?php echo app_h($isEnglish ? 'Filter' : 'فلترة'); ?></button>
                    <a href="pricing_records.php" class="pricing-records-btn"><i class="fa-solid fa-rotate-left"></i> <?php echo app_h($isEnglish ? 'Reset' : 'إعادة ضبط'); ?></a>
                </div>
            </form>
        </div>
        <div class="pricing-records-table-wrap">
            <table class="pricing-records-table">
                <thead>
                    <tr>
                        <th><?php echo app_h($isEnglish ? 'Ref' : 'المرجع'); ?></th>
                        <th><?php echo app_h($isEnglish ? 'Client' : 'العميل'); ?></th>
                        <th><?php echo app_h($isEnglish ? 'Operation' : 'العملية'); ?></th>
                        <th><?php echo app_h($isEnglish ? 'Mode' : 'الوضع'); ?></th>
                        <th><?php echo app_h($isEnglish ? 'Quantity' : 'الكمية'); ?></th>
                        <th><?php echo app_h($isEnglish ? 'Total' : 'الإجمالي'); ?></th>
                        <th><?php echo app_h($isEnglish ? 'Date' : 'التاريخ'); ?></th>
                        <th><?php echo app_h($isEnglish ? 'Actions' : 'الإجراءات'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($records)): ?>
                        <tr><td colspan="8" class="pricing-records-empty"><?php echo app_h($isEnglish ? 'No pricing files yet.' : 'لا توجد ملفات تسعير حتى الآن.'); ?></td></tr>
                    <?php else: ?>
                        <?php foreach ($records as $row): ?>
                            <tr>
                                <td><strong><?php echo app_h((string)($row['pricing_ref'] ?: ('PRC-' . str_pad((string)$row['id'], 5, '0', STR_PAD_LEFT)))); ?></strong></td>
                                <td><?php echo app_h((string)($row['client_name'] ?? '-')); ?></td>
                                <td><?php echo app_h((string)($row['operation_name'] ?? '-')); ?></td>
                                <td>
                                    <span class="pricing-chip">
                                        <?php echo app_h(((string)($row['pricing_mode'] ?? 'general')) === 'books' ? ($isEnglish ? 'Books / Magazines' : 'كتب / مجلات') : ($isEnglish ? 'General' : 'عادي')); ?>
                                    </span>
                                </td>
                                <td><?php echo app_h(number_format((float)($row['qty'] ?? 0), 2)); ?> <span class="muted"><?php echo app_h((string)($row['unit_label'] ?? '')); ?></span></td>
                                <td><strong><?php echo app_h(number_format((float)($row['total_amount'] ?? 0), 2)); ?></strong></td>
                                <td class="muted"><?php echo app_h((string)($row['created_at'] ?? '')); ?></td>
                                <td>
                                    <div class="pricing-records-row-actions">
                                        <a href="pricing_module.php?record_id=<?php echo (int)$row['id']; ?>" class="pricing-records-icon-btn" title="<?php echo app_h($isEnglish ? 'Load into calculator' : 'تحميل إلى شاشة التسعير'); ?>"><i class="fa-solid fa-pen-to-square"></i></a>
                                        <?php if ($canPricingManage): ?>
                                            <a href="pricing_records.php?edit=<?php echo (int)$row['id']; ?>" class="pricing-records-icon-btn" title="<?php echo app_h($isEnglish ? 'Edit metadata' : 'تعديل البيانات'); ?>"><i class="fa-solid fa-file-pen"></i></a>
                                        <?php endif; ?>
                                        <form method="post" action="pricing_module.php" style="margin:0;">
                                            <?php echo app_csrf_input(); ?>
                                            <input type="hidden" name="source_record_id" value="<?php echo (int)$row['id']; ?>">
                                            <button type="submit" name="action" value="save_quote" class="pricing-records-icon-btn" title="<?php echo app_h($isEnglish ? 'Convert to Quote' : 'تحويل إلى عرض سعر'); ?>"><i class="fa-solid fa-file-signature"></i></button>
                                        </form>
                                        <form method="post" action="pricing_module.php" style="margin:0;">
                                            <?php echo app_csrf_input(); ?>
                                            <input type="hidden" name="source_record_id" value="<?php echo (int)$row['id']; ?>">
                                            <button type="submit" name="action" value="save_job" class="pricing-records-icon-btn" title="<?php echo app_h($isEnglish ? 'Convert to Work Order' : 'تحويل إلى أمر شغل'); ?>"><i class="fa-solid fa-briefcase"></i></button>
                                        </form>
                                        <a href="print_pricing_record.php?id=<?php echo (int)$row['id']; ?>" target="_blank" class="pricing-records-icon-btn" title="<?php echo app_h($isEnglish ? 'Print' : 'طباعة'); ?>"><i class="fa-solid fa-print"></i></a>
                                        <?php if ($canPricingManage): ?>
                                            <form method="post" style="margin:0;">
                                                <?php echo app_csrf_input(); ?>
                                                <input type="hidden" name="action" value="duplicate_record">
                                                <input type="hidden" name="record_id" value="<?php echo (int)$row['id']; ?>">
                                                <button type="submit" class="pricing-records-icon-btn" title="<?php echo app_h($isEnglish ? 'Duplicate' : 'تكرار'); ?>"><i class="fa-solid fa-copy"></i></button>
                                            </form>
                                            <form method="post" onsubmit="return confirm('<?php echo app_h($isEnglish ? 'Delete this pricing file?' : 'حذف ملف التسعير هذا؟'); ?>');" style="margin:0;">
                                                <?php echo app_csrf_input(); ?>
                                                <input type="hidden" name="action" value="delete_record">
                                                <input type="hidden" name="record_id" value="<?php echo (int)$row['id']; ?>">
                                                <button type="submit" class="pricing-records-icon-btn" title="<?php echo app_h($isEnglish ? 'Delete' : 'حذف'); ?>"><i class="fa-solid fa-trash"></i></button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php require 'footer.php'; ob_end_flush(); ?>
