<?php
// clients.php - (Royal Clients V10.0 - Royal Cards Design)
ob_start();
error_reporting(E_ALL);

require 'auth.php'; 
require 'config.php'; 
require_once __DIR__ . '/modules/finance/receipts_runtime.php';
require 'header.php';
$isEnglish = app_current_lang($conn) === 'en';
$etaWorkRuntime = app_is_work_runtime();

$tempPasswordNotice = (string)($_SESSION['client_temp_password_notice'] ?? '');
unset($_SESSION['client_temp_password_notice']);
$clientDeleteBlockedNotice = (string)($_SESSION['client_delete_blocked'] ?? '');
unset($_SESSION['client_delete_blocked']);
$currentRole = strtolower((string)($_SESSION['role'] ?? ''));
$legacyClientRoles = ['admin', 'manager', 'sales', 'accountant', 'purchasing'];
$canViewClients = app_user_can('clients.view') || app_user_can('clients.create') || app_user_can('clients.update') || in_array($currentRole, $legacyClientRoles, true);
$canCreateClients = app_user_can('clients.create') || in_array($currentRole, ['admin', 'manager', 'sales', 'purchasing'], true);
$canUpdateClients = app_user_can('clients.update') || in_array($currentRole, ['admin', 'manager', 'sales', 'purchasing'], true);
$canViewClientBalances = app_user_can('clients.balance.view') || in_array($currentRole, $legacyClientRoles, true);

if (!$canViewClients) {
    die("<div class='container' style='text-align:center; padding:80px; color:#e74c3c;'>غير مصرح لك بالدخول إلى العملاء.</div>");
}

/* ==================================================
   2. المعالجة (POST Request)
   ================================================== */

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !app_verify_csrf($_POST['_csrf_token'] ?? '')) {
    http_response_code(419);
    die('Invalid CSRF token');
}

if(isset($_POST['reset_pass'])){
    if (!$canUpdateClients) {
        http_response_code(403);
        die('Access denied');
    }
    $cid = intval($_POST['client_id']);
    $tempPassword = 'AE-' . strtoupper(bin2hex(random_bytes(4)));
    $def_pass = password_hash($tempPassword, PASSWORD_DEFAULT);
    $stmt_reset = $conn->prepare("UPDATE clients SET password_hash = ? WHERE id = ?");
    $stmt_reset->bind_param("si", $def_pass, $cid);
    $stmt_reset->execute();
    $stmt_reset->close();
    $_SESSION['client_temp_password_notice'] = "تم إنشاء كلمة مرور مؤقتة جديدة للعميل #{$cid}: {$tempPassword}";
    header("Location: clients.php?msg=pass_reset"); exit;
}

if($_SERVER['REQUEST_METHOD'] == 'POST'){
    if(isset($_POST['add_client']) || isset($_POST['update_client'])) {
        if (isset($_POST['add_client']) && !$canCreateClients) {
            http_response_code(403);
            die('Access denied');
        }
        if (isset($_POST['update_client']) && !$canUpdateClients) {
            http_response_code(403);
            die('Access denied');
        }
        $name = trim((string)($_POST['name'] ?? ''));
        $phone = trim((string)($_POST['phone'] ?? ''));
        $phone = preg_replace('/[^0-9+]/', '', $phone) ?: '';
        $email = trim((string)($_POST['email'] ?? ''));
        $taxNumber = trim((string)($_POST['tax_number'] ?? ''));
        $taxId = trim((string)($_POST['tax_id'] ?? ''));
        $nationalId = trim((string)($_POST['national_id'] ?? ''));
        $countryCode = strtoupper(trim((string)($_POST['country_code'] ?? 'EG')));
        if ($countryCode === '' || strlen($countryCode) > 2) {
            $countryCode = 'EG';
        }
        $receiverType = strtoupper(trim((string)($_POST['eta_receiver_type'] ?? 'B')));
        if (!in_array($receiverType, ['B', 'P', 'F'], true)) {
            $receiverType = 'B';
        }
        $address = trim((string)($_POST['address'] ?? ''));
        $map = trim((string)($_POST['google_map'] ?? ''));
        $opening = $canViewClientBalances ? floatval($_POST['opening_balance'] ?? 0) : 0.0;
        $notes = trim((string)($_POST['notes'] ?? ''));

        if(isset($_POST['update_client'])){
            $id = intval($_POST['client_id']);
            if (!$canViewClientBalances) {
                $openingStmt = $conn->prepare("SELECT opening_balance FROM clients WHERE id = ? LIMIT 1");
                if ($openingStmt) {
                    $openingStmt->bind_param('i', $id);
                    $openingStmt->execute();
                    $openingRow = $openingStmt->get_result()->fetch_assoc() ?: [];
                    $opening = (float)($openingRow['opening_balance'] ?? 0);
                    $openingStmt->close();
                }
            }
            $stmt_up = $conn->prepare("
                UPDATE clients SET
                    name = ?, phone = ?, email = ?, tax_number = ?, tax_id = ?, national_id = ?, country_code = ?, eta_receiver_type = ?, address = ?,
                    google_map = ?, opening_balance = ?, notes = ?
                WHERE id = ?
            ");
            $stmt_up->bind_param("ssssssssssdsi", $name, $phone, $email, $taxNumber, $taxId, $nationalId, $countryCode, $receiverType, $address, $map, $opening, $notes, $id);
            if ($stmt_up->execute()) {
                $stmt_up->close();
                header("Location: clients.php?msg=updated");
                exit;
            }
            $stmt_up->close();
        } elseif(isset($_POST['add_client'])) {
            $tempPassword = 'AE-' . strtoupper(bin2hex(random_bytes(4)));
            $pass = password_hash($tempPassword, PASSWORD_DEFAULT);
            $token = bin2hex(random_bytes(16));
            $stmt_add = $conn->prepare("
                INSERT INTO clients (name, phone, email, tax_number, tax_id, national_id, country_code, eta_receiver_type, password_hash, address, google_map, opening_balance, notes, access_token)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt_add->bind_param("sssssssssssdss", $name, $phone, $email, $taxNumber, $taxId, $nationalId, $countryCode, $receiverType, $pass, $address, $map, $opening, $notes, $token);
            if($stmt_add->execute()) {
                $stmt_add->close();
                $_SESSION['client_temp_password_notice'] = "تم إنشاء العميل {$name}. كلمة المرور المؤقتة: {$tempPassword}";
                header("Location: clients.php?msg=added");
                exit;
            }
            $stmt_add->close();
        }
    }
}

if($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_client']) && $_SESSION['role'] == 'admin'){
    $id = intval($_POST['client_id'] ?? 0);
    $deleteSummary = function_exists('financeEntityDeleteLinkSummary')
        ? financeEntityDeleteLinkSummary($conn, 'client', $id)
        : ['total' => 0, 'details' => []];
    if ((int)($deleteSummary['total'] ?? 0) > 0) {
        $_SESSION['client_delete_blocked'] = function_exists('financeEntityDeleteBlockedMessage')
            ? financeEntityDeleteBlockedMessage('client', $deleteSummary)
            : 'لا يمكن حذف العميل لأنه مرتبط بمستندات أو حركات مالية.';
        header("Location: clients.php?msg=linked");
        exit;
    }
    $stmt_del = $conn->prepare("DELETE FROM clients WHERE id = ?");
    $stmt_del->bind_param("i", $id);
    $stmt_del->execute();
    $stmt_del->close();
    header("Location: clients.php?msg=deleted"); exit;
}

function get_portal_link() {
    $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http");
    $host = $_SERVER['HTTP_HOST'];
    return "$protocol://$host/login.php?mode=client";
}

// --- الإحصائيات + القائمة ---
$search = trim((string)($_GET['q'] ?? ''));
$clientRows = [];
$stats = [
    'count' => 0,
    'total_inv' => 0.0,
    'total_settled' => 0.0,
    'total_credit' => 0.0,
];
$total_debt = 0.0;

$searchLike = '%' . $search . '%';
$clientSql = "
    SELECT
        q.*,
        q.net_balance
    FROM (
        SELECT
            c.*,
            IFNULL(inv.total_sales, 0) AS total_sales,
            ROUND(
                (
                    CASE
                        WHEN c.opening_balance > 0 THEN
                            GREATEST(
                                c.opening_balance
                                - IFNULL(opening_legacy.legacy_opening_paid, 0)
                                - IFNULL(opening_alloc.opening_applied, 0),
                                0
                            )
                        ELSE 0
                    END
                )
                + IFNULL(inv.invoice_due, 0)
                - (
                    CASE
                        WHEN c.opening_balance < 0 THEN ABS(c.opening_balance)
                        ELSE 0
                    END
                    + IFNULL(rc.receipt_credit, 0)
                ),
                2
            ) AS net_balance
        FROM clients c
        LEFT JOIN (
            SELECT
                client_id,
                IFNULL(SUM(total_amount), 0) AS total_sales,
                IFNULL(SUM(CASE WHEN IFNULL(remaining_amount, 0) > 0.00001 THEN remaining_amount ELSE 0 END), 0) AS invoice_due
            FROM invoices
            WHERE COALESCE(NULLIF(status, ''), 'unpaid') <> 'cancelled'
            GROUP BY client_id
        ) inv ON inv.client_id = c.id
        LEFT JOIN (
            SELECT r.client_id, IFNULL(SUM(a.amount), 0) AS opening_applied
            FROM financial_receipt_allocations a
            INNER JOIN financial_receipts r ON r.id = a.receipt_id
            WHERE r.type = 'in' AND a.allocation_type = 'client_opening'
            GROUP BY r.client_id
        ) opening_alloc ON opening_alloc.client_id = c.id
        LEFT JOIN (
            SELECT r.client_id, IFNULL(SUM(r.amount), 0) AS legacy_opening_paid
            FROM financial_receipts r
            LEFT JOIN (
                SELECT receipt_id, COUNT(*) AS allocation_count
                FROM financial_receipt_allocations
                GROUP BY receipt_id
            ) ac ON ac.receipt_id = r.id
            WHERE r.type = 'in'
              AND LOWER(TRIM(IFNULL(r.category, ''))) IN ('opening_balance', 'client_opening')
              AND (
                    TRIM(IFNULL(r.description, '')) LIKE 'تسوية رصيد أول المدة%'
                    OR LOWER(TRIM(IFNULL(r.description, ''))) LIKE '%opening balance%'
                  )
              AND IFNULL(ac.allocation_count, 0) = 0
            GROUP BY r.client_id
        ) opening_legacy ON opening_legacy.client_id = c.id
        LEFT JOIN (
            SELECT
                r.client_id,
                IFNULL(SUM(
                    ROUND(
                        r.amount - CASE
                            WHEN IFNULL(r.invoice_id, 0) > 0 THEN r.amount
                            WHEN IFNULL(a.allocated_amount, 0) > 0 THEN IFNULL(a.allocated_amount, 0)
                            ELSE 0
                        END,
                        2
                    )
                ), 0) AS receipt_credit
            FROM financial_receipts r
            LEFT JOIN (
                SELECT receipt_id, IFNULL(SUM(amount), 0) AS allocated_amount
                FROM financial_receipt_allocations
                GROUP BY receipt_id
            ) a ON a.receipt_id = r.id
            WHERE r.type = 'in'
              AND LOWER(TRIM(IFNULL(r.category, ''))) NOT IN ('opening_balance', 'client_opening')
              AND ROUND(
                    r.amount - CASE
                        WHEN IFNULL(r.invoice_id, 0) > 0 THEN r.amount
                        WHEN IFNULL(a.allocated_amount, 0) > 0 THEN IFNULL(a.allocated_amount, 0)
                        ELSE 0
                    END,
                    2
                  ) > 0.00001
            GROUP BY r.client_id
        ) rc ON rc.client_id = c.id
    ) q
    WHERE (? = '' OR q.name LIKE ? OR q.phone LIKE ?)
    ORDER BY q.id DESC
";
$stmt_clients = $conn->prepare($clientSql);
$stmt_clients->bind_param('sss', $search, $searchLike, $searchLike);
$stmt_clients->execute();
$clientResult = $stmt_clients->get_result();
while ($clientResult && ($row = $clientResult->fetch_assoc())) {
    if (function_exists('financeClientBalanceSnapshot')) {
        $snapshot = financeClientBalanceSnapshot($conn, (int)($row['id'] ?? 0));
        $row['net_balance'] = (float)($snapshot['net_balance'] ?? 0);
        $row['receipt_credit'] = (float)($snapshot['receipt_credit'] ?? 0);
    }
    if (function_exists('financeClientSettlementSummary')) {
        $settlement = financeClientSettlementSummary($conn, (int)($row['id'] ?? 0));
        $row['total_settled'] = (float)($settlement['settled_total'] ?? 0);
        $row['unallocated_credit'] = (float)($settlement['unallocated_credit'] ?? 0);
    } else {
        $row['total_settled'] = 0.0;
        $row['unallocated_credit'] = 0.0;
    }
    $clientRows[] = $row;
    $stats['count']++;
    $stats['total_inv'] += (float)($row['total_sales'] ?? 0);
    $stats['total_settled'] += (float)($row['total_settled'] ?? 0);
    $stats['total_credit'] += (float)($row['unallocated_credit'] ?? 0);
    $net = (float)($row['net_balance'] ?? 0);
    if ($net > 0.00001) {
        $total_debt += $net;
    }
}
$stmt_clients->close();
$stats['total_inv'] = round((float)$stats['total_inv'], 2);
$stats['total_settled'] = round((float)$stats['total_settled'], 2);
$stats['total_credit'] = round((float)$stats['total_credit'], 2);
$total_debt = round((float)$total_debt, 2);
?>

<style>
    :root { --gold: #d4af37; --bg-dark: #0f0f0f; --card-bg: #1a1a1a; --border: #333; }
    body { background-color: var(--bg-dark); font-family: 'Cairo', sans-serif; color: #fff; padding-bottom: 80px; }
    .clients-shell { display:grid; gap:18px; }
    .clients-hero {
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
    .clients-eyebrow {
        display:inline-flex; align-items:center; gap:8px; padding:6px 12px; border-radius:999px;
        background:rgba(212,175,55,0.08); border:1px solid rgba(212,175,55,0.24); color:#f0d684;
        font-size:.76rem; font-weight:700; margin-bottom:14px;
    }
    .clients-title { margin:0; color:#f7f1dc; font-size:1.85rem; line-height:1.3; }
    .clients-subtitle { margin:10px 0 0; color:#a8abb1; line-height:1.8; max-width:760px; }
    .clients-hero-actions { display:flex; gap:10px; flex-wrap:wrap; margin-top:18px; }
    .hero-btn {
        display:inline-flex; align-items:center; justify-content:center; min-height:46px; padding:0 18px;
        border-radius:14px; text-decoration:none; font-weight:700; font-family:'Cairo',sans-serif;
        border:1px solid rgba(212,175,55,0.2); background:linear-gradient(140deg, var(--gold), #9c7726); color:#17120a;
    }
    .hero-btn.secondary { background:rgba(255,255,255,0.04); color:#ececec; border-color:rgba(255,255,255,0.12); }
    .stats-bar {
        display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:12px;
    }
    .stat-box {
        background:rgba(255,255,255,0.035); border:1px solid rgba(255,255,255,0.08); padding:18px; border-radius:18px;
        min-height:118px; text-align:right; position:relative; overflow:hidden;
    }
    .stat-val { font-size: 1.8rem; font-weight: bold; color: #fff; }
    .stat-lbl { font-size: 0.82rem; color: #9ca0a8; margin-top:10px; }

    .toolbar {
        display: flex; justify-content: space-between; align-items: center; margin-bottom: 0; gap: 15px;
        position: sticky; top: calc(var(--nav-total-height, 70px) + 10px); z-index: 10; background: rgba(15,15,15,0.8); padding: 16px 18px;
        border-radius: 20px; border: 1px solid rgba(255,255,255,0.08); backdrop-filter: blur(12px); box-shadow: 0 10px 30px rgba(0,0,0,0.35);
    }
    .search-box {
        flex: 1; background: rgba(8,8,8,0.82); border: 1px solid rgba(255,255,255,0.1); color: #fff; padding: 13px 18px;
        border-radius: 16px; outline: none; font-size: 0.98rem; transition: 0.3s;
    }
    .search-box:focus { border-color: var(--gold); box-shadow: 0 0 0 4px rgba(212, 175, 55, 0.08); }
    
    .btn-add-main {
        background: linear-gradient(135deg, var(--gold), #b8860b); color: #000; border: none;
        padding: 13px 22px; border-radius: 14px; font-weight: bold; cursor: pointer;
        white-space: nowrap; box-shadow: 0 8px 20px rgba(212, 175, 55, 0.2); transition: 0.3s;
    }
    .btn-add-main:hover { transform: translateY(-2px); box-shadow: 0 8px 20px rgba(212, 175, 55, 0.3); }

    .clients-grid {
        display: grid;
        grid-template-columns: 1fr;
        gap: 20px;
    }

    .client-card {
        background:
            linear-gradient(180deg, rgba(255,255,255,0.06), rgba(255,255,255,0.02)),
            radial-gradient(circle at top left, rgba(212,175,55,0.12), transparent 34%),
            rgba(18,18,18,0.76);
        border: 1px solid rgba(255,255,255,0.08); border-radius: 22px; padding: 22px;
        position: relative; transition: all 0.3s ease; 
        box-shadow: 0 16px 32px rgba(0,0,0,0.22);
        backdrop-filter: blur(14px);
    }
    .client-card:hover { 
        transform: translateY(-5px); 
        border-color: rgba(212,175,55,0.42);
        box-shadow: 0 14px 32px rgba(212, 175, 55, 0.12);
    }
    .client-card::after {
        content:"";
        position:absolute;
        inset-inline-end:-36px;
        inset-block-start:-36px;
        width:110px;
        height:110px;
        border-radius:50%;
        background:radial-gradient(circle, rgba(212,175,55,0.08), transparent 72%);
        pointer-events:none;
    }
    .c-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 15px; }
    .c-avatar { 
        width: 54px; height: 54px; background: rgba(212, 175, 55, 0.1); 
        color: var(--gold); border-radius: 50%; display: flex; align-items: center; 
        justify-content: center; font-size: 1.2rem; margin-left: 15px; border: 1px solid rgba(212, 175, 55, 0.3);
        box-shadow:0 10px 18px rgba(0,0,0,0.18);
    }
    .c-info { flex: 1; }
    .c-name { font-size: 1.06rem; font-weight: bold; color: #fff; margin: 0 0 5px 0; line-height:1.45; }
    .c-phone { font-size: 0.86rem; color: #aaa; font-family: monospace; }
    
    .c-balance-box {
        background: rgba(6,6,6,0.76); border-radius: 16px; padding: 16px; margin: 15px 0;
        text-align: center; border: 1px dashed rgba(255,255,255,0.12);
    }
    .c-balance-val { font-size: 1.4rem; font-weight: 800; display: block; }
    .bal-pos { color: #e74c3c; } /* عليه */
    .bal-neg { color: #2ecc71; } /* ليه */
    .client-meta-grid {
        display:grid;
        grid-template-columns:repeat(2,minmax(0,1fr));
        gap:10px;
        margin-bottom:15px;
    }
    .client-meta-box {
        border-radius:14px;
        background:rgba(255,255,255,0.03);
        border:1px solid rgba(255,255,255,0.06);
        padding:12px;
        min-height:78px;
    }
    .client-meta-label { color:#9ca0a8; font-size:.72rem; margin-bottom:6px; }
    .client-meta-value { color:#f0f0f0; font-size:.83rem; line-height:1.6; overflow-wrap:anywhere; }
    
    .c-actions {
        display: grid; grid-template-columns: repeat(4, minmax(0,1fr)); gap: 10px; border-top: 1px solid rgba(255,255,255,0.08); padding-top: 15px;
    }
    .action-btn {
        background: rgba(255,255,255,0.04); color: #fff; border: 1px solid rgba(255,255,255,0.12); border-radius: 12px;
        height: 40px; display: flex; align-items: center; justify-content: center; 
        text-decoration: none; font-size: 1rem; transition: 0.2s; cursor: pointer;
    }
    .action-btn:hover { background: var(--gold); color: #000; border-color: var(--gold); }
    
    /* Specific Button Colors */
    .btn-wa { color: #25D366; border-color: rgba(37, 211, 102, 0.3); }
    .btn-wa:hover { background: #25D366; color: #fff; }
    
    .btn-edit { color: #f39c12; border-color: rgba(243, 156, 18, 0.3); }
    .btn-edit:hover { background: #f39c12; color: #000; }
    
    .btn-del { color: #e74c3c; border-color: rgba(231, 76, 60, 0.3); }
    .btn-del:hover { background: #e74c3c; color: #fff; }

    .custom-modal { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.9); z-index: 10000; padding: 20px; overflow-y: auto; backdrop-filter: blur(5px); align-items:flex-start; justify-content:center; }
    .modal-content { width: min(760px, calc(100vw - 24px)); max-width: 760px; margin: 30px auto; background: linear-gradient(180deg, rgba(24,24,24,0.98), rgba(16,16,16,0.96)); border: 1px solid rgba(212,175,55,0.26); padding: 28px; border-radius: 24px; box-shadow: 0 20px 50px rgba(0,0,0,0.5); }
    .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
    .full-width { grid-column: span 2; }
    .form-panel {
        border:1px solid rgba(255,255,255,0.07);
        border-radius:18px;
        background:rgba(255,255,255,0.025);
        padding:18px;
    }
    .form-panel-title { color:#f0d684; font-size:.9rem; margin:0 0 12px; }
    .form-control { width: 100%; background: rgba(10,10,10,0.82); border: 1px solid rgba(255,255,255,0.1); color: #fff; padding: 14px; border-radius: 14px; margin-bottom: 5px; outline: none; transition: 0.3s; }
    .form-control:focus { border-color: var(--gold); box-shadow: 0 0 0 4px rgba(212, 175, 55, 0.08); }
    @media (max-width: 1024px) {
        .clients-hero { grid-template-columns:1fr; }
    }
    @media (min-width: 761px) {
        .clients-grid { grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); }
    }
    @media (max-width: 760px) {
        .stats-bar,
        .client-meta-grid,
        .form-grid { grid-template-columns:1fr; }
        .full-width { grid-column: span 1; }
        .toolbar { flex-direction:column; align-items:stretch; }
        .btn-add-main { width:100%; justify-content:center; }
        .c-actions { grid-template-columns:repeat(2, minmax(0,1fr)); }
        .glass-panel,
        .client-card,
        .modal-content { padding: 18px; border-radius: 18px; }
        .clients-hero-actions { flex-direction: column; }
        .hero-btn { width: 100%; }
    }
</style>

<div class="container">
    <div class="clients-shell">
        <section class="clients-hero">
            <div class="glass-panel">
                <div class="clients-eyebrow">إدارة العملاء</div>
                <h2 class="clients-title"><?php echo app_h(app_tr('سجل العملاء وحساباتهم', 'Clients and account records')); ?></h2>
                <p class="clients-subtitle"><?php echo app_h($canViewClientBalances ? app_tr('عرض موحد لبيانات العملاء، الأرصدة الحالية، وإجراءات التواصل والإدارة بنفس الهوية البصرية الحديثة.', 'Unified view for client records, balances, and communication actions with the same modern visual identity.') : app_tr('عرض بيانات العملاء الأساسية وإضافة عملاء جدد دون إظهار الأرصدة المالية.', 'View basic client records and add new clients without exposing financial balances.')); ?></p>
                <div class="clients-hero-actions">
                    <?php if($canCreateClients): ?>
                    <button type="button" onclick="openModal('addModal')" class="btn-add-main"><?php echo app_h(app_tr('إضافة عميل', 'Add Client')); ?></button>
                    <?php endif; ?>
                    <a href="dashboard.php" class="hero-btn secondary"><?php echo app_h(app_tr('لوحة العمليات', 'Operations dashboard')); ?></a>
                </div>
            </div>
            <div class="stats-bar">
                <div class="stat-box">
                    <div class="stat-val"><?php echo $stats['count']; ?></div>
                    <div class="stat-lbl"><?php echo app_h(app_tr('إجمالي العملاء', 'Total clients')); ?></div>
                </div>
                <?php if($canViewClientBalances): ?>
                <div class="stat-box">
                    <div class="stat-val" style="color:<?php echo $total_debt > 0 ? '#e74c3c' : '#2ecc71'; ?>">
                        <?php echo number_format($total_debt); ?> <small>EGP</small>
                    </div>
                    <div class="stat-lbl"><?php echo app_h(app_tr('إجمالي الرصيد المفتوح', 'Total outstanding balance')); ?></div>
                </div>
                <div class="stat-box">
                    <div class="stat-val"><?php echo number_format((float)$stats['total_inv']); ?> <small>EGP</small></div>
                    <div class="stat-lbl"><?php echo app_h(app_tr('إجمالي الفواتير', 'Total invoices')); ?></div>
                </div>
                <div class="stat-box">
                    <div class="stat-val"><?php echo number_format((float)$stats['total_settled']); ?> <small>EGP</small></div>
                    <div class="stat-lbl"><?php echo app_h(app_tr('إجمالي التسويات المحصلة', 'Total settled collections')); ?></div>
                </div>
                <div class="stat-box">
                    <div class="stat-val"><?php echo number_format((float)$stats['total_credit']); ?> <small>EGP</small></div>
                    <div class="stat-lbl"><?php echo app_h(app_tr('إجمالي الرصيد الدائن غير المخصص', 'Total unallocated customer credit')); ?></div>
                </div>
                <?php endif; ?>
            </div>
        </section>

        <div class="toolbar">
        <form method="GET" style="flex:1; display:flex;">
            <input type="text" name="q" class="search-box" placeholder="<?php echo app_h(app_tr('بحث باسم العميل أو الهاتف...', 'Search by client name or phone...')); ?>" value="<?php echo app_h($_GET['q'] ?? ''); ?>">
        </form>
        <?php if($canCreateClients): ?><button type="button" onclick="openModal('addModal')" class="btn-add-main"><?php echo app_h(app_tr('إضافة عميل', 'Add Client')); ?></button><?php endif; ?>
        </div>
    <?php if($tempPasswordNotice !== ''): ?>
        <div style="margin:0; background:rgba(212,175,55,0.12); border:1px solid rgba(212,175,55,0.55); color:#ffe69b; padding:12px; border-radius:14px; font-weight:700;">
            <?php echo app_h($tempPasswordNotice); ?>
        </div>
    <?php endif; ?>
    <?php if($clientDeleteBlockedNotice !== ''): ?>
        <div style="margin:0; background:rgba(231,76,60,0.12); border:1px solid rgba(231,76,60,0.55); color:#ffb0a8; padding:12px; border-radius:14px; font-weight:700;">
            <?php echo app_h($clientDeleteBlockedNotice); ?>
        </div>
    <?php endif; ?>

    <div class="clients-grid">
        <?php 
        if(!empty($clientRows)):
            foreach($clientRows as $row):
                $balance = (float)($row['net_balance'] ?? 0);
                $bal_class = $balance > 0 ? 'bal-pos' : ($balance < 0 ? 'bal-neg' : '');
                $balanceLabel = $balance > 0
                    ? app_tr('مستحق على العميل', 'Customer due')
                    : ($balance < 0 ? app_tr('رصيد دائن للعميل', 'Customer credit') : app_tr('متوازن', 'Balanced'));
                
                // إعداد واتساب
                $portal_link = get_portal_link();
                $has_pass = !empty($row['password_hash']);
                $msg_text = $isEnglish
                    ? "Client portal access details for {$row['name']}.\nPortal link: $portal_link\nUsername: {$row['phone']}\n"
                    : "بيانات الدخول إلى بوابة العميل للاسم {$row['name']}.\nرابط البوابة: $portal_link\nاسم المستخدم: {$row['phone']}\n";
                if(!$has_pass) $msg_text .= $isEnglish
                    ? "Please request password activation from system administration."
                    : "الرجاء طلب تفعيل كلمة المرور من إدارة النظام.";
                $wa_msg = urlencode($msg_text);
                $wa_num = preg_replace('/[^0-9]/', '', $row['phone']);
                if(substr($wa_num, 0, 1) == '0') $wa_num = '2'.$wa_num;

                // تأمين البيانات
                $safeData = htmlspecialchars(json_encode($row), ENT_QUOTES, 'UTF-8');
        ?>
        <div class="client-card">
            <div class="c-header">
                <div style="display:flex; align-items:center;">
                    <div class="c-avatar"><i class="fa-solid fa-user"></i></div>
                    <div class="c-info">
                        <h3 class="c-name"><?php echo app_h($row['name']); ?></h3>
                        <div class="c-phone"><?php echo app_h($row['phone']); ?></div>
                    </div>
                </div>
                <a href="tel:<?php echo app_h($row['phone']); ?>" class="action-btn" style="width:35px; height:35px; border-radius:50%;"><i class="fa-solid fa-phone"></i></a>
            </div>

            <?php if($canViewClientBalances): ?>
            <div class="c-balance-box">
                <span style="font-size:0.8rem; color:#888;"><?php echo app_h(app_tr('الرصيد الحالي', 'Current balance')); ?></span>
                <span class="c-balance-val <?php echo $bal_class; ?>">
                    <?php echo app_h($balanceLabel); ?>: <?php echo number_format(abs($balance), 2); ?> <small style="font-size:0.8rem;">EGP</small>
                </span>
            </div>
            <?php endif; ?>

            <div class="client-meta-grid">
                <div class="client-meta-box">
                    <div class="client-meta-label"><?php echo app_h(app_tr('البريد الإلكتروني', 'Email')); ?></div>
                    <div class="client-meta-value"><?php echo app_h(trim((string)($row['email'] ?? '')) !== '' ? (string)$row['email'] : app_tr('غير مسجل', 'Not set')); ?></div>
                </div>
                <?php if($canViewClientBalances): ?>
                <div class="client-meta-box">
                    <div class="client-meta-label"><?php echo app_h(app_tr('الرصيد الافتتاحي', 'Opening balance')); ?></div>
                    <div class="client-meta-value"><?php echo number_format((float)$row['opening_balance'], 2); ?> EGP</div>
                </div>
                <?php endif; ?>
                <div class="client-meta-box">
                    <div class="client-meta-label"><?php echo app_h(app_tr('الرقم الضريبي', 'Tax Number')); ?></div>
                    <div class="client-meta-value"><?php echo app_h(trim((string)($row['tax_number'] ?? '')) !== '' ? (string)$row['tax_number'] : app_tr('غير مسجل', 'Not set')); ?></div>
                </div>
                <div class="client-meta-box">
                    <?php if ($etaWorkRuntime): ?>
                    <div class="client-meta-label"><?php echo app_h(app_tr('نوع مستقبل ETA', 'ETA Receiver Type')); ?></div>
                    <div class="client-meta-value"><?php echo app_h(trim((string)($row['eta_receiver_type'] ?? 'B'))); ?></div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="c-actions">
                <a href="https://wa.me/<?php echo $wa_num; ?>?text=<?php echo $wa_msg; ?>" target="_blank" class="action-btn btn-wa" title="<?php echo app_h(app_tr('إرسال بيانات الدخول', 'Send login details')); ?>"><i class="fa-brands fa-whatsapp"></i></a>
                
                <?php if($canUpdateClients): ?>
                <button onclick="editClient(<?php echo $safeData; ?>)" class="action-btn btn-edit" title="<?php echo app_h(app_tr('تعديل', 'Edit')); ?>"><i class="fa-solid fa-pen"></i></button>
                
                <form method="POST" style="display:contents;" onsubmit="return confirm('<?php echo app_h(app_tr('إعادة تعيين كلمة المرور بكلمة مؤقتة جديدة؟', 'Reset password with a new temporary password?')); ?>');">
                    <?php echo app_csrf_input(); ?>
                    <input type="hidden" name="client_id" value="<?php echo (int)$row['id']; ?>">
                    <input type="hidden" name="reset_pass" value="1">
                    <button type="submit" class="action-btn" title="<?php echo app_h(app_tr('إعادة تعيين كلمة المرور', 'Reset password')); ?>"><i class="fa-solid fa-key"></i></button>
                </form>
                <?php endif; ?>

                <?php if($_SESSION['role'] == 'admin'): ?>
                <form method="POST" style="display:contents;" onsubmit="return confirm('<?php echo app_h(app_tr('حذف نهائي؟', 'Permanent delete?')); ?>');">
                    <?php echo app_csrf_input(); ?>
                    <input type="hidden" name="client_id" value="<?php echo (int)$row['id']; ?>">
                    <button type="submit" name="delete_client" value="1" class="action-btn btn-del" title="<?php echo app_h(app_tr('حذف', 'Delete')); ?>"><i class="fa-solid fa-trash"></i></button>
                </form>
                <?php else: ?>
                <button class="action-btn" disabled style="opacity:0.3"><i class="fa-solid fa-lock"></i></button>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
        <?php else: ?>
            <div style="grid-column:1/-1; text-align:center; padding:50px; color:#666;"><?php echo app_h(app_tr('لا يوجد عملاء مطابقين للبحث', 'No matching clients found')); ?></div>
        <?php endif; ?>
    </div>
</div>
</div>

<div id="addModal" class="custom-modal">
    <div class="modal-content">
        <h3 style="color:var(--gold); margin-top:0; border-bottom:1px solid #333; padding-bottom:15px; margin-bottom:20px;"><?php echo app_h(app_tr('إضافة عميل جديد', 'Add New Client')); ?></h3>
        <form method="POST">
            <?php echo app_csrf_input(); ?>
            <div class="form-grid">
                <div class="form-panel full-width">
                    <h4 class="form-panel-title"><?php echo app_h(app_tr('البيانات الأساسية', 'Basic data')); ?></h4>
                    <div class="form-grid">
                        <div class="full-width"><label style="color:#aaa;"><?php echo app_h(app_tr('الاسم', 'Name')); ?></label><input type="text" name="name" required class="form-control"></div>
                        <div><label style="color:#aaa;"><?php echo app_h(app_tr('الهاتف', 'Phone')); ?></label><input type="text" name="phone" required class="form-control"></div>
                        <?php if($canViewClientBalances): ?>
                        <div><label style="color:#aaa;"><?php echo app_h(app_tr('الرصيد الافتتاحي', 'Opening balance')); ?></label><input type="number" step="0.01" name="opening_balance" class="form-control" value="0.00"></div>
                        <?php else: ?>
                        <input type="hidden" name="opening_balance" value="0">
                        <?php endif; ?>
                        <div><label style="color:#aaa;"><?php echo app_h(app_tr('البريد', 'Email')); ?></label><input type="email" name="email" class="form-control"></div>
                        <div><label style="color:#aaa;"><?php echo app_h(app_tr('الرقم الضريبي', 'Tax Number')); ?></label><input type="text" name="tax_number" class="form-control"></div>
                        <div><label style="color:#aaa;"><?php echo app_h(app_tr('Tax ID', 'Tax ID')); ?></label><input type="text" name="tax_id" class="form-control"></div>
                        <div><label style="color:#aaa;"><?php echo app_h(app_tr('الرقم القومي', 'National ID')); ?></label><input type="text" name="national_id" class="form-control"></div>
                        <div><label style="color:#aaa;"><?php echo app_h(app_tr('كود الدولة', 'Country Code')); ?></label><input type="text" name="country_code" class="form-control" value="EG" maxlength="2"></div>
                        <?php if ($etaWorkRuntime): ?><div>
                            <label style="color:#aaa;"><?php echo app_h(app_tr('نوع مستقبل ETA', 'ETA Receiver Type')); ?></label>
                            <select name="eta_receiver_type" class="form-control">
                                <option value="B">B - Business</option>
                                <option value="P">P - Person</option>
                                <option value="F">F - Foreigner</option>
                            </select>
                        </div><?php endif; ?>
                        <div><label style="color:#aaa;"><?php echo app_h(app_tr('الخريطة', 'Map')); ?></label><input type="text" name="google_map" class="form-control"></div>
                        <div class="full-width"><label style="color:#aaa;"><?php echo app_h(app_tr('العنوان', 'Address')); ?></label><textarea name="address" class="form-control" rows="2"></textarea></div>
                        <div class="full-width"><label style="color:#aaa;"><?php echo app_h(app_tr('ملاحظات', 'Notes')); ?></label><textarea name="notes" class="form-control" rows="2"></textarea></div>
                    </div>
                </div>
            </div>
            <button type="submit" name="add_client" class="btn-add-main" style="width:100%; margin-top:20px; border-radius:10px;"><?php echo app_h(app_tr('حفظ العميل', 'Save Client')); ?></button>
            <button type="button" onclick="closeModal('addModal')" class="btn-add-main" style="width:100%; margin-top:10px; background:#333; border-radius:10px;"><?php echo app_h(app_tr('إلغاء', 'Cancel')); ?></button>
        </form>
    </div>
</div>

<div id="editModal" class="custom-modal">
    <div class="modal-content">
        <h3 style="color:var(--gold); margin-top:0; border-bottom:1px solid #333; padding-bottom:15px; margin-bottom:20px;"><?php echo app_h(app_tr('تعديل البيانات', 'Edit Data')); ?></h3>
        <form method="POST">
            <?php echo app_csrf_input(); ?>
            <input type="hidden" name="client_id" id="e_id">
            <div class="form-grid">
                <div class="form-panel full-width">
                    <h4 class="form-panel-title"><?php echo app_h(app_tr('بيانات العميل', 'Client data')); ?></h4>
                    <div class="form-grid">
                        <div class="full-width"><label style="color:#aaa;"><?php echo app_h(app_tr('الاسم', 'Name')); ?></label><input type="text" name="name" id="e_name" required class="form-control"></div>
                        <div><label style="color:#aaa;"><?php echo app_h(app_tr('الهاتف', 'Phone')); ?></label><input type="text" name="phone" id="e_phone" required class="form-control"></div>
                        <?php if($canViewClientBalances): ?>
                        <div><label style="color:#aaa;"><?php echo app_h(app_tr('الرصيد الافتتاحي', 'Opening balance')); ?></label><input type="number" step="0.01" name="opening_balance" id="e_open" class="form-control"></div>
                        <?php else: ?>
                        <input type="hidden" name="opening_balance" id="e_open" value="0">
                        <?php endif; ?>
                        <div><label style="color:#aaa;"><?php echo app_h(app_tr('البريد', 'Email')); ?></label><input type="email" name="email" id="e_email" class="form-control"></div>
                        <div><label style="color:#aaa;"><?php echo app_h(app_tr('الرقم الضريبي', 'Tax Number')); ?></label><input type="text" name="tax_number" id="e_tax_number" class="form-control"></div>
                        <div><label style="color:#aaa;"><?php echo app_h(app_tr('Tax ID', 'Tax ID')); ?></label><input type="text" name="tax_id" id="e_tax_id" class="form-control"></div>
                        <div><label style="color:#aaa;"><?php echo app_h(app_tr('الرقم القومي', 'National ID')); ?></label><input type="text" name="national_id" id="e_national_id" class="form-control"></div>
                        <div><label style="color:#aaa;"><?php echo app_h(app_tr('كود الدولة', 'Country Code')); ?></label><input type="text" name="country_code" id="e_country_code" class="form-control" maxlength="2"></div>
                        <?php if ($etaWorkRuntime): ?><div>
                            <label style="color:#aaa;"><?php echo app_h(app_tr('نوع مستقبل ETA', 'ETA Receiver Type')); ?></label>
                            <select name="eta_receiver_type" id="e_receiver_type" class="form-control">
                                <option value="B">B - Business</option>
                                <option value="P">P - Person</option>
                                <option value="F">F - Foreigner</option>
                            </select>
                        </div><?php endif; ?>
                        <div><label style="color:#aaa;"><?php echo app_h(app_tr('الخريطة', 'Map')); ?></label><input type="text" name="google_map" id="e_map" class="form-control"></div>
                        <div class="full-width"><label style="color:#aaa;"><?php echo app_h(app_tr('العنوان', 'Address')); ?></label><textarea name="address" id="e_address" class="form-control" rows="2"></textarea></div>
                        <div class="full-width"><label style="color:#aaa;"><?php echo app_h(app_tr('ملاحظات', 'Notes')); ?></label><textarea name="notes" id="e_notes" class="form-control" rows="2"></textarea></div>
                    </div>
                </div>
            </div>
            <button type="submit" name="update_client" class="btn-add-main" style="width:100%; margin-top:20px; border-radius:10px;"><?php echo app_h(app_tr('تحديث البيانات', 'Update Data')); ?></button>
            <button type="button" onclick="closeModal('editModal')" class="btn-add-main" style="width:100%; margin-top:10px; background:#333; border-radius:10px;"><?php echo app_h(app_tr('إلغاء', 'Cancel')); ?></button>
        </form>
    </div>
</div>

<script>
    window.openModal = function(id) {
        const modal = document.getElementById(id);
        if (!modal) return;
        modal.style.display = 'flex';
        document.body.style.overflow = '';
    };
    window.closeModal = function(id) {
        const modal = document.getElementById(id);
        if (!modal) return;
        modal.style.display = 'none';
        document.body.style.overflow = '';
    };
    document.addEventListener('keydown', function(event) {
        if (event.key !== 'Escape') return;
        closeModal('addModal');
        closeModal('editModal');
    });
    document.querySelectorAll('.custom-modal').forEach(function(modal) {
        modal.addEventListener('click', function(event) {
            if (event.target === modal) closeModal(modal.id);
        });
    });
    
    function editClient(data) {
        document.getElementById('e_id').value = data.id;
        document.getElementById('e_name').value = data.name;
        document.getElementById('e_phone').value = data.phone;
        document.getElementById('e_open').value = data.opening_balance;
        document.getElementById('e_email').value = data.email || '';
        document.getElementById('e_tax_number').value = data.tax_number || '';
        document.getElementById('e_tax_id').value = data.tax_id || '';
        document.getElementById('e_national_id').value = data.national_id || '';
        document.getElementById('e_country_code').value = data.country_code || 'EG';
        const etaReceiverEl = document.getElementById('e_receiver_type');
        if (etaReceiverEl) etaReceiverEl.value = data.eta_receiver_type || 'B';
        document.getElementById('e_address').value = data.address || '';
        document.getElementById('e_map').value = data.google_map || '';
        document.getElementById('e_notes').value = data.notes || '';
        openModal('editModal');
    }
</script>

<?php include 'footer.php'; ob_end_flush(); ?>
