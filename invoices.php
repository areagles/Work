<?php
// invoices.php - (Royal Invoices V28.1 - Delivery Receipt Integration)
ob_start();
error_reporting(E_ALL);

require 'auth.php';
require 'config.php';
require_once __DIR__ . '/modules/finance/party_ledger_runtime.php';
require_once 'inventory_engine.php';
require_once 'finance_engine.php';
require_once __DIR__ . '/modules/tax/eta_einvoice_runtime.php';
app_handle_lang_switch($conn);
$etaWorkRuntime = app_is_work_runtime();

$canInvoicesPage = app_user_can_any(['invoices.view', 'invoices.create', 'invoices.update', 'invoices.delete', 'invoices.duplicate']);
$canInvoiceCreate = app_user_can('invoices.create');
$canInvoiceUpdate = app_user_can('invoices.update');
$canInvoiceDelete = app_user_can('invoices.delete');
$canInvoiceDuplicate = app_user_can('invoices.duplicate');
$canEtaManage = $etaWorkRuntime && app_user_can_any(['invoices.view', 'invoices.update']);

if (!$canInvoicesPage) {
    http_response_code(403);
    require 'header.php';
    echo "<div class='container page-shell' style='margin-top:30px;'><div class='alert alert-danger'>" . app_h(app_tr('غير مصرح لك بالدخول إلى الفواتير.', 'You are not authorized to access invoices.')) . "</div></div>";
    require 'footer.php';
    exit;
}

require 'header.php';
$appName = app_setting_get($conn, 'app_name', 'Arab Eagles');
$isEnglish = app_current_lang($conn) === 'en';
$purchaseInvoiceHasDisplayName = app_table_has_column($conn, 'purchase_invoices', 'supplier_display_name');

function get_wa_link($phone, $text) {
    $phone = preg_replace('/[^0-9]/', '', $phone);
    if (strlen($phone) == 11 && (substr($phone, 0, 2) == '01' || substr($phone, 0, 2) == '00')) {
         $phone = '2' . $phone; 
    }
    return "https://wa.me/$phone?text=" . urlencode($text);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'], $_POST['id'])) {
    if (!app_verify_csrf($_POST['_csrf_token'] ?? '')) {
        $safeTab = (string)($_GET['tab'] ?? 'sales');
        if (!in_array($safeTab, ['sales', 'purchases', 'salaries'], true)) {
            $safeTab = 'sales';
        }
        header("Location: invoices.php?tab=" . $safeTab . "&msg=denied");
        exit;
    }
    $act = (string)$_POST['action'];
    $id = (int)$_POST['id'];
    $type = (string)($_POST['type'] ?? 'sales');

    if (in_array($act, ['eta_queue', 'eta_submit', 'eta_sync'], true)) {
        if (!$canEtaManage || $type !== 'sales') {
            header("Location: invoices.php?tab=sales&msg=denied");
            exit;
        }
        $redirectMsg = 'eta_failed';
        if ($act === 'eta_queue') {
            $result = app_eta_einvoice_queue_invoice($conn, $id, (int)($_SESSION['user_id'] ?? 0));
            $redirectMsg = !empty($result['ok']) ? 'eta_queued' : 'eta_failed';
        } elseif ($act === 'eta_submit') {
            $queueResult = app_eta_einvoice_queue_invoice($conn, $id, (int)($_SESSION['user_id'] ?? 0));
            if (!empty($queueResult['ok']) && (int)($queueResult['outbox_id'] ?? 0) > 0) {
                $submitResult = app_eta_einvoice_submit_outbox($conn, (int)$queueResult['outbox_id']);
                if (!empty($submitResult['ok']) && !empty($submitResult['deferred'])) {
                    $redirectMsg = 'eta_deferred';
                } else {
                    $redirectMsg = !empty($submitResult['ok']) ? 'eta_submitted' : 'eta_failed';
                }
            }
        } elseif ($act === 'eta_sync') {
            $syncResult = app_eta_einvoice_sync_document_status($conn, $id);
            $redirectMsg = !empty($syncResult['ok']) ? 'eta_synced' : 'eta_failed';
        }
        header("Location: invoices.php?tab=sales&msg=" . $redirectMsg);
        exit;
    }
    
    if ($act == 'duplicate') {
        if (!$canInvoiceDuplicate) {
            header("Location: invoices.php?tab=" . (($type === 'purchase') ? 'purchases' : 'sales') . "&msg=denied");
            exit;
        }
        $new_date = date('Y-m-d');
        if ($type === 'sales') {
            $orig = $conn->query("SELECT client_id, items_json, notes, sub_total, tax, discount, total_amount FROM invoices WHERE id=$id")->fetch_assoc();
            if ($orig) {
                $sql = "INSERT INTO invoices (inv_date, client_id, items_json, notes, sub_total, tax, discount, total_amount, remaining_amount, status) 
                        VALUES ('$new_date', '{$orig['client_id']}', '{$conn->real_escape_string($orig['items_json'])}', '{$conn->real_escape_string($orig['notes'])}', '{$orig['sub_total']}', '{$orig['tax']}', '{$orig['discount']}', '{$orig['total_amount']}', '{$orig['total_amount']}', 'unpaid')";
                $conn->query($sql);
                $newInvoiceId = (int)$conn->insert_id;
                app_assign_document_number($conn, 'invoices', $newInvoiceId, 'invoice_number', 'invoice', $new_date);
                $creator = (string)($_SESSION['name'] ?? 'System');
                app_apply_client_opening_balance_to_invoice($conn, $newInvoiceId, (int)$orig['client_id'], $new_date, $creator);
                if (function_exists('app_apply_client_receipt_credit_to_invoice')) {
                    app_apply_client_receipt_credit_to_invoice($conn, $newInvoiceId, (int)$orig['client_id'], $new_date, $creator);
                }
                header("Location: edit_invoice.php?id=" . $newInvoiceId . "&msg=cloned"); exit;
            }
        } elseif ($type === 'purchase') {
            $orig = $conn->query("SELECT id FROM purchase_invoices WHERE id=$id LIMIT 1")->fetch_assoc();
            if ($orig) {
                header("Location: add_purchase.php?clone_id=" . (int)$id . "&msg=cloned"); exit;
            }
        }
    }
    
    if ($act === 'delete' && $canInvoiceDelete) {
        if ($type === 'sales') {
            $result = financeSalesInvoiceCancelService($conn, $id, (string)($_SESSION['name'] ?? 'System'));
            if (
                empty($result['ok'])
                && (string)($result['message'] ?? '') === 'sales_paid'
                && function_exists('app_eta_einvoice_release_sales_invoice_financial_links')
            ) {
                $etaSelectParts = [];
                foreach (['eta_uuid', 'eta_status', 'eta_submission_id', 'eta_long_id'] as $column) {
                    $hasColumn = function_exists('app_table_has_column')
                        ? app_table_has_column($conn, 'invoices', $column)
                        : false;
                    $etaSelectParts[] = $hasColumn
                        ? "IFNULL($column, '') AS $column"
                        : "'' AS $column";
                }
                $invoiceMeta = null;
                $stmtMeta = $conn->prepare("
                    SELECT job_id,
                           " . implode(",\n                           ", $etaSelectParts) . "
                    FROM invoices
                    WHERE id = ?
                    LIMIT 1
                ");
                if ($stmtMeta) {
                    $stmtMeta->bind_param('i', $id);
                    $stmtMeta->execute();
                    $invoiceMeta = $stmtMeta->get_result()->fetch_assoc() ?: null;
                    $stmtMeta->close();
                }
                $hasEtaBinding = trim((string)($invoiceMeta['eta_uuid'] ?? '')) !== ''
                    || trim((string)($invoiceMeta['eta_status'] ?? '')) !== ''
                    || trim((string)($invoiceMeta['eta_submission_id'] ?? '')) !== ''
                    || trim((string)($invoiceMeta['eta_long_id'] ?? '')) !== '';
                if ((int)($invoiceMeta['job_id'] ?? 0) > 0 && !$hasEtaBinding) {
                    app_eta_einvoice_release_sales_invoice_financial_links($conn, $id);
                    $result = financeSalesInvoiceCancelService($conn, $id, (string)($_SESSION['name'] ?? 'System'));
                }
            }
            if (!empty($result['ok'])) {
                header("Location: invoices.php?tab=sales&msg=cancelled");
                exit();
            }
            $reason = (string)($result['message'] ?? 'delete_failed');
            if ($reason === 'sales_eta_locked') {
                header("Location: invoices.php?tab=sales&msg=sales_eta_locked");
                exit();
            }
            if ($reason === 'sales_paid') {
                header("Location: invoices.php?tab=sales&msg=sales_paid");
                exit();
            }
            header("Location: invoices.php?tab=sales&msg=delete_failed");
            exit();
        } elseif ($type === 'purchase') {
            try {
                $conn->begin_transaction();
                $purchaseMetaStmt = $conn->prepare("
                    SELECT id, status,
                           IFNULL(eta_uuid, '') AS eta_uuid,
                           IFNULL(eta_status, '') AS eta_status,
                           IFNULL(eta_submission_id, '') AS eta_submission_id,
                           IFNULL(eta_long_id, '') AS eta_long_id
                    FROM purchase_invoices
                    WHERE id = ?
                    LIMIT 1
                ");
                if (!$purchaseMetaStmt) {
                    throw new RuntimeException($conn->error);
                }
                $purchaseMetaStmt->bind_param('i', $id);
                $purchaseMetaStmt->execute();
                $purchaseMeta = $purchaseMetaStmt->get_result()->fetch_assoc() ?: null;
                $purchaseMetaStmt->close();
                if (!$purchaseMeta) {
                    throw new RuntimeException('purchase_invoice_not_found');
                }

                $purchaseHasEtaBinding = trim((string)($purchaseMeta['eta_uuid'] ?? '')) !== ''
                    || trim((string)($purchaseMeta['eta_status'] ?? '')) !== ''
                    || trim((string)($purchaseMeta['eta_submission_id'] ?? '')) !== ''
                    || trim((string)($purchaseMeta['eta_long_id'] ?? '')) !== '';
                $purchasePosted = inventory_purchase_invoice_is_posted($conn, $id);
                $purchaseDirectReceiptCount = (int)($conn->query("SELECT COUNT(*) FROM financial_receipts WHERE invoice_id = {$id} AND type = 'out'")->fetch_row()[0] ?? 0);
                $purchaseAllocationCount = (int)($conn->query("SELECT COUNT(*) FROM financial_receipt_allocations WHERE allocation_type = 'purchase_invoice' AND target_id = {$id}")->fetch_row()[0] ?? 0);
                $purchaseReturnCount = (int)($conn->query("SELECT COUNT(*) FROM purchase_invoice_returns WHERE purchase_invoice_id = {$id}")->fetch_row()[0] ?? 0);

                $canDirectPurgePurchase = !$purchaseHasEtaBinding
                    && !$purchasePosted
                    && $purchaseDirectReceiptCount === 0
                    && $purchaseAllocationCount === 0
                    && $purchaseReturnCount === 0;

                if ($canDirectPurgePurchase) {
                    inventory_purge_purchase_invoice($conn, $id);
                    $conn->commit();
                    header("Location: invoices.php?tab=purchases&msg=purged");
                    exit();
                }

                inventory_cancel_purchase_invoice($conn, $id);
                $conn->commit();
                header("Location: invoices.php?tab=purchases&msg=cancelled");
                exit();
            } catch (Throwable $e) {
                $conn->rollback();
                $reason = (string)$e->getMessage();
                if ($reason === 'purchase_invoice_paid') {
                    header("Location: invoices.php?tab=purchases&msg=purchase_paid");
                    exit();
                }
                if ($reason === 'purchase_invoice_stock_consumed') {
                    header("Location: invoices.php?tab=purchases&msg=purchase_stock_used");
                    exit();
                }
                if ($reason === 'purchase_invoice_already_cancelled') {
                    header("Location: invoices.php?tab=purchases&msg=cancelled");
                    exit();
                }
                header("Location: invoices.php?tab=purchases&msg=delete_failed");
                exit();
            }
        } elseif ($type === 'salary') {
            $sheet = $conn->query("SELECT paid_amount FROM payroll_sheets WHERE id=$id LIMIT 1")->fetch_assoc();
            $paidAmount = (float)($sheet['paid_amount'] ?? 0);
            $salaryLinkedStmt = $conn->prepare("
                SELECT
                    IFNULL(SUM(CASE WHEN payroll_id = ? THEN 1 ELSE 0 END), 0) AS direct_receipt_count,
                    IFNULL((
                        SELECT COUNT(*)
                        FROM financial_receipt_allocations
                        WHERE allocation_type = 'payroll' AND target_id = ?
                    ), 0) AS allocated_receipt_count
                FROM financial_receipts
                WHERE type = 'out' AND category = 'salary'
            ");
            $salaryLinkedStmt->bind_param('ii', $id, $id);
            $salaryLinkedStmt->execute();
            $salaryLinked = $salaryLinkedStmt->get_result()->fetch_assoc() ?: [];
            $salaryLinkedStmt->close();
            if (
                $paidAmount > 0.00001
                || (int)($salaryLinked['direct_receipt_count'] ?? 0) > 0
                || (int)($salaryLinked['allocated_receipt_count'] ?? 0) > 0
            ) {
                header("Location: invoices.php?tab=salaries&msg=salary_paid");
                exit();
            }
            $conn->query("DELETE FROM payroll_sheets WHERE id=$id");
        }
        header("Location: invoices.php?tab=" . ($type == 'sales' ? 'sales' : ($type == 'purchase' ? 'purchases' : 'salaries')) . "&msg=deleted");
        exit();
    }

    if ($act === 'purge_purchase' && $canInvoiceDelete && $type === 'purchase') {
        try {
            $conn->begin_transaction();
            inventory_purge_purchase_invoice($conn, $id);
            $conn->commit();
            header("Location: invoices.php?tab=purchases&msg=purged");
            exit();
        } catch (Throwable $e) {
            $conn->rollback();
            $reason = (string)$e->getMessage();
            if ($reason === 'purchase_invoice_eta_locked') {
                header("Location: invoices.php?tab=purchases&msg=purchase_eta_locked");
                exit();
            }
            if ($reason === 'purchase_invoice_not_cancelled') {
                header("Location: invoices.php?tab=purchases&msg=purchase_not_cancelled");
                exit();
            }
            if ($reason === 'purchase_invoice_has_returns') {
                header("Location: invoices.php?tab=purchases&msg=purchase_has_returns");
                exit();
            }
            if ($reason === 'purchase_invoice_paid') {
                header("Location: invoices.php?tab=purchases&msg=purchase_paid");
                exit();
            }
            header("Location: invoices.php?tab=purchases&msg=delete_failed");
            exit();
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && !isset($_POST['id'])) {
    if (!app_verify_csrf($_POST['_csrf_token'] ?? '')) {
        header("Location: invoices.php?tab=sales&msg=denied");
        exit;
    }
    $act = (string)$_POST['action'];
    if ($etaWorkRuntime && $canEtaManage && in_array($act, ['eta_batch_sales', 'eta_pull_purchases', 'eta_pull_sales'], true)) {
        $etaFailureRedirect = static function (string $tab, string $reason = 'eta_failed'): void {
            $reason = preg_replace('/[^a-zA-Z0-9_\\-]/', '_', $reason) ?: 'eta_failed';
            header("Location: invoices.php?tab=" . $tab . "&msg=eta_failed&eta_error=" . urlencode($reason));
            exit;
        };
        try {
            @set_time_limit(300);
            if ($act === 'eta_batch_sales') {
                $dateFrom = trim((string)($_POST['eta_date_from'] ?? ''));
                $dateTo = trim((string)($_POST['eta_date_to'] ?? ''));
                $mode = trim((string)($_POST['eta_batch_mode'] ?? 'submit'));
                $result = app_eta_einvoice_batch_process_sales_period($conn, $dateFrom, $dateTo, $mode, (int)($_SESSION['user_id'] ?? 0));
                if (!empty($result['ok'])) {
                    $summary = [
                        'from' => (string)($result['from'] ?? ''),
                        'to' => (string)($result['to'] ?? ''),
                        'mode' => (string)($result['mode'] ?? $mode),
                        'requested_mode' => (string)($result['requested_mode'] ?? $mode),
                        'deferred_submit' => !empty($result['deferred_submit']) ? 1 : 0,
                        'success' => (int)($result['success'] ?? 0),
                        'failed' => (int)($result['failed'] ?? 0),
                        'matched' => (int)($result['matched'] ?? 0),
                    ];
                    header("Location: invoices.php?tab=sales&msg=eta_batch_sales_ok&eta_batch=" . urlencode(base64_encode(json_encode($summary))));
                    exit;
                }
                app_eta_einvoice_log_error($conn, null, null, 'sales_batch', (string)($result['error'] ?? 'eta_batch_failed'), 'ETA sales batch failed', $result);
                $etaFailureRedirect('sales', (string)($result['error'] ?? 'eta_batch_failed'));
            }
            if ($act === 'eta_pull_purchases') {
                $dateFrom = trim((string)($_POST['eta_purchase_from'] ?? ''));
                $dateTo = trim((string)($_POST['eta_purchase_to'] ?? ''));
                $result = app_eta_einvoice_pull_purchase_documents_by_period($conn, $dateFrom, $dateTo, false);
                if (!empty($result['ok'])) {
                    $summary = [
                        'from' => (string)($result['from'] ?? ''),
                        'to' => (string)($result['to'] ?? ''),
                        'imported' => (int)($result['imported'] ?? 0),
                        'duplicates' => (int)($result['duplicates'] ?? 0),
                        'errors' => count((array)($result['errors'] ?? [])),
                    ];
                    header("Location: invoices.php?tab=purchases&msg=eta_purchase_pull_ok&eta_pull=" . urlencode(base64_encode(json_encode($summary))));
                    exit;
                }
                app_eta_einvoice_log_error($conn, null, null, 'purchase_pull', (string)($result['error'] ?? 'eta_purchase_pull_failed'), 'ETA purchase pull failed', $result);
                $etaFailureRedirect('purchases', (string)($result['error'] ?? 'eta_purchase_pull_failed'));
            }
            if ($act === 'eta_pull_sales') {
                $dateFrom = trim((string)($_POST['eta_sales_from'] ?? ''));
                $dateTo = trim((string)($_POST['eta_sales_to'] ?? ''));
                $result = app_eta_einvoice_pull_sales_documents_by_period($conn, $dateFrom, $dateTo);
                if (!empty($result['ok'])) {
                    $summary = [
                        'from' => (string)($result['from'] ?? ''),
                        'to' => (string)($result['to'] ?? ''),
                        'imported' => (int)($result['imported'] ?? 0),
                        'duplicates' => (int)($result['duplicates'] ?? 0),
                        'errors' => count((array)($result['errors'] ?? [])),
                    ];
                    header("Location: invoices.php?tab=sales&msg=eta_pull_sales_ok&eta_pull_sales=" . urlencode(base64_encode(json_encode($summary))));
                    exit;
                }
                app_eta_einvoice_log_error($conn, null, null, 'sales_pull', (string)($result['error'] ?? 'eta_sales_pull_failed'), 'ETA sales pull failed', $result);
                $etaFailureRedirect('sales', (string)($result['error'] ?? 'eta_sales_pull_failed'));
            }
        } catch (Throwable $e) {
            error_log('ETA invoice action failed [' . $act . ']: ' . $e->getMessage());
            app_eta_einvoice_log_error($conn, null, null, $act, 'exception', $e->getMessage(), [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            $failedTab = $act === 'eta_pull_purchases' ? 'purchases' : 'sales';
            $etaFailureRedirect($failedTab, 'exception');
        }
    }
}

$tab = isset($_GET['tab']) ? $_GET['tab'] : 'sales';
$search = isset($_GET['q']) ? $conn->real_escape_string($_GET['q']) : '';
$dueFilter = isset($_GET['due_filter']) ? trim((string)$_GET['due_filter']) : 'all';
if (!in_array($dueFilter, ['all', 'overdue'], true)) {
    $dueFilter = 'all';
}
$title = ""; $stat_total = 0; $stat_paid = 0; $stat_due = 0;
$etaSettings = $etaWorkRuntime ? app_eta_einvoice_settings($conn) : [];
$etaAutoPurchaseNotice = null;

if ($etaWorkRuntime && $canEtaManage && $tab === 'purchases' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $autoPull = app_eta_einvoice_auto_pull_purchase_documents($conn);
    if (!empty($autoPull['ok']) && empty($autoPull['skipped']) && (int)($autoPull['imported'] ?? 0) > 0) {
        $etaAutoPurchaseNotice = [
            'imported' => (int)$autoPull['imported'],
            'duplicates' => (int)($autoPull['duplicates'] ?? 0),
            'from' => (string)($autoPull['from'] ?? ''),
            'to' => (string)($autoPull['to'] ?? ''),
        ];
    }
}

if ($tab == 'sales') {
    $title = $isEnglish ? 'Sales Invoices' : 'فواتير المبيعات';
    $title_icon = "fa-solid fa-file-invoice-dollar";
    $stats = $conn->query("
        SELECT
            IFNULL(SUM(total_amount), 0) as t,
            IFNULL(SUM(paid_amount), 0) as p,
            IFNULL(SUM(remaining_amount), 0) as r
        FROM invoices
        WHERE COALESCE(NULLIF(status, ''), 'unpaid') <> 'cancelled'
    ")->fetch_assoc();
    $sql = "SELECT i.*, c.name as party_name, c.phone as party_phone, j.job_number, j.job_name
            FROM invoices i
            LEFT JOIN clients c ON i.client_id = c.id
            LEFT JOIN job_orders j ON j.id = i.job_id
            WHERE 1=1";
    if ($dueFilter === 'overdue') {
        $sql .= " AND DATE(i.due_date) < CURDATE() AND IFNULL(i.remaining_amount, 0) > 0.009 AND COALESCE(NULLIF(i.status, ''), 'unpaid') NOT IN ('paid', 'cancelled')";
    }
    if($search) $sql .= " AND (i.id LIKE '%$search%' OR c.name LIKE '%$search%' OR j.job_name LIKE '%$search%' OR j.job_number LIKE '%$search%')";
    $sql .= " ORDER BY i.id DESC";

} elseif ($tab == 'purchases') {
    $title = $isEnglish ? 'Purchase Invoices' : 'فواتير المشتريات';
    $title_icon = "fa-solid fa-cart-flatbed";
    $stats = $conn->query("
        SELECT
            IFNULL(SUM(total_amount), 0) as t,
            IFNULL(SUM(paid_amount), 0) as p,
            IFNULL(SUM(remaining_amount), 0) as r
        FROM purchase_invoices
        WHERE COALESCE(NULLIF(status, ''), 'unpaid') <> 'cancelled'
    ")->fetch_assoc();
    if ($purchaseInvoiceHasDisplayName) {
        $sql = "SELECT p.*, COALESCE(NULLIF(p.supplier_display_name, ''), s.name) as party_name, s.name as supplier_master_name, s.phone as party_phone FROM purchase_invoices p LEFT JOIN suppliers s ON p.supplier_id = s.id WHERE 1=1";
    } else {
        $sql = "SELECT p.*, s.name as party_name, s.name as supplier_master_name, s.phone as party_phone FROM purchase_invoices p LEFT JOIN suppliers s ON p.supplier_id = s.id WHERE 1=1";
    }
    if($search) $sql .= " AND (p.id LIKE '%$search%' OR s.name LIKE '%$search%')";
    $sql .= " ORDER BY p.id DESC";

} elseif ($tab == 'salaries') {
    $title = $isEnglish ? 'Payroll Sheets' : 'مسيرات الرواتب';
    $title_icon = "fa-solid fa-users-gear";
    $stats = $conn->query("SELECT SUM(net_salary) as t, SUM(paid_amount) as p, SUM(remaining_amount) as r FROM payroll_sheets")->fetch_assoc();
    $sql = "SELECT p.*, u.full_name as party_name, u.phone as party_phone FROM payroll_sheets p LEFT JOIN users u ON p.employee_id = u.id WHERE 1=1";
    if($search) $sql .= " AND (u.full_name LIKE '%$search%' OR p.month_year LIKE '%$search%')";
    $sql .= " ORDER BY p.month_year DESC, p.id DESC";
}

$stat_total = $stats['t'] ?? 0; $stat_paid = $stats['p'] ?? 0; $stat_due = $stats['r'] ?? 0;
$res = $conn->query($sql);
?>

<style>
    :root { 
        --gold: #d4af37; 
        --gold-glow: rgba(212, 175, 55, 0.15);
        --card-bg: #141414; 
        --bg-dark: #0a0a0a; 
        --surface: #1e1e1e;
        --border-color: #2a2a2a;
    }
    body { background-color: var(--bg-dark); color: #fff; font-family: 'Cairo', sans-serif; margin: 0; padding-bottom: 80px; }
    
    .container { max-width: 1400px; margin: 0 auto; padding: 20px; }

    .page-shell-grid { display:grid; gap:18px; }
    .hero-panel {
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
    .hero-panel::after {
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
    .hero-header { display:flex; justify-content:space-between; align-items:flex-start; gap:15px; flex-wrap:wrap; }
    .hero-title-wrap { display:flex; align-items:flex-start; gap:14px; }
    .hero-kpis { display:grid; grid-template-columns:1fr; gap:12px; }
    .hero-kpi {
        border-radius:18px;
        background:rgba(255,255,255,0.035);
        border:1px solid rgba(255,255,255,0.08);
        padding:18px;
        min-height:110px;
    }
    .hero-kpi .label { color:#9ca0a8; font-size:.78rem; margin-bottom:10px; }
    .hero-kpi .value { color:#fff; font-size:1.65rem; font-weight:800; line-height:1; }
    .hero-kpi .note { color:#878c92; font-size:.73rem; margin-top:10px; }
    .page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 0; flex-wrap: wrap; gap: 15px; }
    .page-title { display: flex; align-items: center; gap: 12px; margin: 0; }
    .page-title .icon-box { background: var(--gold-glow); color: var(--gold); width: 45px; height: 45px; display: flex; align-items: center; justify-content: center; border-radius: 12px; font-size: 1.3rem; }
    .page-title h2 { color: var(--gold); margin: 0; font-size: 1.6rem; }
    
    .btn-add-new { 
        background: linear-gradient(135deg, var(--gold), #b8860b); color: #000; 
        padding: 12px 25px; border-radius: 10px; text-decoration: none; font-weight: bold; 
        display: flex; align-items: center; gap: 8px; transition: 0.3s; box-shadow: 0 4px 15px var(--gold-glow);
    }
    .btn-add-new:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(212, 175, 55, 0.4); }
    .btn-add-new[disabled] {
        opacity: .72;
        cursor: wait !important;
        transform: none;
        box-shadow: none;
    }

    .eta-sync-progress {
        display:none;
        grid-column:1 / -1;
        border:1px solid rgba(212,175,55,0.2);
        background:rgba(212,175,55,0.07);
        border-radius:12px;
        padding:10px 12px;
        color:#f4dfa4;
    }
    .eta-sync-progress.is-active { display:block; }
    .eta-sync-status {
        display:flex;
        align-items:center;
        justify-content:space-between;
        gap:12px;
        font-size:.86rem;
        margin-bottom:8px;
    }
    .eta-sync-status strong { color:#fff3c7; }
    .eta-progress-track {
        position:relative;
        overflow:hidden;
        height:8px;
        border-radius:999px;
        background:rgba(255,255,255,0.08);
    }
    .eta-progress-bar {
        position:absolute;
        inset-block:0;
        inset-inline-start:0;
        width:34%;
        border-radius:inherit;
        background:linear-gradient(90deg, rgba(212,175,55,0.35), #f2d47a, rgba(212,175,55,0.35));
        animation: etaSyncSlide 1.05s ease-in-out infinite;
    }
    @keyframes etaSyncSlide {
        0% { transform: translateX(-120%); }
        100% { transform: translateX(320%); }
    }

    .kpi-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 20px; margin-bottom: 30px; }
    .kpi-card { 
        background: var(--card-bg); padding: 25px; border-radius: 16px; border: 1px solid var(--border-color); 
        display: flex; align-items: center; justify-content: space-between; position: relative; overflow: hidden;
        box-shadow: 0 4px 15px rgba(0,0,0,0.2); transition: transform 0.3s;
    }
    .kpi-card:hover { transform: translateY(-3px); border-color: #444; }
    .kpi-info h3 { margin: 0 0 5px; color: #888; font-size: 1rem; font-weight: normal; }
    .kpi-info .num { font-size: 1.8rem; font-weight: 900; color: #fff; }
    .kpi-icon { font-size: 2.5rem; opacity: 0.2; }

    .toolbar { display: flex; justify-content: space-between; align-items: center; background: rgba(18,18,18,0.84); padding: 14px; border-radius: 18px; border: 1px solid rgba(255,255,255,0.08); margin-bottom: 0; flex-wrap: wrap; gap: 15px; backdrop-filter: blur(12px); }
    
    .tabs-container { display: flex; gap: 5px; overflow-x: auto; padding: 5px; background: var(--bg-dark); border-radius: 12px; }
    .tab-btn { padding: 10px 20px; border-radius: 8px; text-decoration: none; color: #888; transition: 0.3s; white-space: nowrap; font-weight: bold; font-size: 0.95rem; }
    .tab-btn:hover { color: #fff; }
    .tab-btn.active { background: var(--surface); color: var(--gold); box-shadow: 0 2px 8px rgba(0,0,0,0.2); }

    .search-form { flex: 1; min-width: 0; position: relative; display: flex; }
    .search-form i { position: absolute; right: 15px; top: 50%; transform: translateY(-50%); color: #666; }
    .search-form input { 
        width: 100%; background: var(--bg-dark); border: 1px solid var(--border-color); color: #fff; 
        padding: 12px 40px 12px 15px; border-radius: 10px; font-family: 'Cairo'; transition: 0.3s; outline: none;
    }
    .search-form input:focus { border-color: var(--gold); box-shadow: 0 0 0 2px var(--gold-glow); }
    .filter-select {
        min-width: 0;
        width: 100%;
        background: var(--bg-dark);
        border: 1px solid var(--border-color);
        color: #fff;
        padding: 12px 14px;
        border-radius: 10px;
        font-family: 'Cairo';
        outline: none;
    }

    .records-grid { display:grid; grid-template-columns:1fr; gap:14px; }
    .invoice-card {
        position:relative;
        overflow:hidden;
        border-radius:22px;
        border:1px solid rgba(255,255,255,0.08);
        background:
            linear-gradient(180deg, rgba(255,255,255,0.06), rgba(255,255,255,0.02)),
            radial-gradient(circle at top left, rgba(212,175,55,0.12), transparent 34%),
            rgba(18,18,18,0.76);
        padding:20px;
        box-shadow:0 16px 32px rgba(0,0,0,0.22);
        backdrop-filter:blur(14px);
    }
    .invoice-card::after {
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
    .invoice-head { display:flex; align-items:flex-start; justify-content:space-between; gap:12px; margin-bottom:14px; }
    .invoice-doc { display:inline-flex; align-items:center; justify-content:center; min-width:86px; height:36px; padding:0 12px; border-radius:999px; background:rgba(255,255,255,0.08); color:#f3e2a3; font-size:.8rem; font-weight:700; }
    .main-text { font-weight: bold; font-size: 1.02rem; color: #fff; display: block; margin-bottom: 4px; line-height:1.5; }
    .sub-text { font-size: 0.8rem; color: #777; line-height:1.6; display:block; }
    .invoice-grid { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:10px; margin:14px 0; }
    .invoice-metric { border-radius:14px; background:rgba(255,255,255,0.035); border:1px solid rgba(255,255,255,0.06); padding:12px; }
    .invoice-metric .label { color:#9ca0a8; font-size:.72rem; margin-bottom:6px; }
    .invoice-metric .value { color:#f0f0f0; font-size:.86rem; font-weight:700; line-height:1.5; }
    
    .badge { padding: 5px 12px; border-radius: 20px; font-size: 0.8rem; font-weight: bold; display: inline-flex; align-items: center; gap: 5px; }
    .badge-paid { background: rgba(46, 204, 113, 0.1); color: #2ecc71; border: 1px solid rgba(46, 204, 113, 0.2); }
    .badge-unpaid { background: rgba(231, 76, 60, 0.1); color: #e74c3c; border: 1px solid rgba(231, 76, 60, 0.2); }
    .badge-partial { background: rgba(241, 196, 15, 0.1); color: #f1c40f; border: 1px solid rgba(241, 196, 15, 0.2); }
    
    .actions-cell { display: flex; justify-content: flex-end; gap: 8px; flex-wrap: wrap; padding-top:14px; border-top:1px solid rgba(255,255,255,0.08); }
    .action-btn { 
        width: 38px; height: 38px; display: inline-flex; align-items: center; justify-content: center; 
        border-radius: 10px; background: var(--surface); color: #aaa; text-decoration: none; 
        border: 1px solid var(--border-color); transition: 0.2s; font-size: 0.95rem;
    }
    .action-btn:hover { background: var(--bg-dark); transform: translateY(-2px); }
    .action-btn.btn-wa { color: #25D366; background: rgba(37, 211, 102, 0.05); border-color: rgba(37, 211, 102, 0.2); }
    .action-btn.btn-wa:hover { background: rgba(37, 211, 102, 0.15); box-shadow: 0 0 10px rgba(37, 211, 102, 0.3); }
    .action-btn.btn-edit:hover { color: #3498db; border-color: #3498db; box-shadow: 0 0 10px rgba(52, 152, 219, 0.3); }
    .action-btn.btn-view:hover { color: var(--gold); border-color: var(--gold); box-shadow: 0 0 10px var(--gold-glow); }
    .action-btn.btn-del:hover { color: #e74c3c; border-color: #e74c3c; box-shadow: 0 0 10px rgba(231, 76, 60, 0.3); }
    .action-btn.btn-delivery { color: #e67e22; border-color: rgba(230, 126, 34, 0.3); background: rgba(230, 126, 34, 0.05); }
    .action-btn.btn-delivery:hover { background: rgba(230, 126, 34, 0.15); border-color: #e67e22; box-shadow: 0 0 10px rgba(230, 126, 34, 0.3); }

    .alert-msg { background: rgba(46, 204, 113, 0.1); border: 1px solid #2ecc71; color: #2ecc71; padding: 12px 20px; border-radius: 10px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; }

    @media (min-width: 901px) {
        .hero-kpis { grid-template-columns:repeat(3,minmax(0,1fr)); }
        .records-grid { grid-template-columns:repeat(auto-fit, minmax(320px, 1fr)); }
        .filter-select { min-width: 170px; width: auto; }
    }
    @media (max-width: 900px) {
        .hero-kpis,
        .kpi-grid,
        .invoice-grid { grid-template-columns: 1fr; }
        .toolbar,
        .hero-header { flex-direction: column; align-items: stretch; }
        .actions-cell { width: 100%; justify-content: center; }
        .btn-add-new { width: 100%; justify-content: center; }
        .invoice-card,
        .hero-panel { padding: 18px; border-radius: 18px; }
    }
</style>

<div class="container page-shell" style="margin-top:10px;">
    <div class="page-shell-grid">
    
    <?php if(isset($_GET['msg'])): ?>
        <div class="alert-msg">
            <span>
                <?php 
                    if($_GET['msg'] == 'cloned') echo app_h(app_tr('تم تكرار السجل بنجاح.', 'Record duplicated successfully.'));
                    if($_GET['msg'] == 'deleted') echo app_h(app_tr('تم حذف السجل بنجاح.', 'Record deleted successfully.'));
                    if($_GET['msg'] == 'cancelled') echo app_h(app_tr('تم إلغاء الفاتورة وتحديث أثرها المالي بنجاح.', 'Invoice cancelled and its financial effect updated successfully.'));
                    if($_GET['msg'] == 'purged') echo app_h(app_tr('تم حذف فاتورة الشراء نهائياً.', 'Purchase invoice permanently deleted.'));
                    if($_GET['msg'] == 'denied') echo app_h(app_tr('لا تملك صلاحية تنفيذ هذا الإجراء.', 'You do not have permission to perform this action.'));
                    if($_GET['msg'] == 'purchase_paid') echo app_h(app_tr('لا يمكن حذف/إلغاء فاتورة شراء تم السداد عليها.', 'Cannot cancel a purchase invoice that already has payments.'));
                    if($_GET['msg'] == 'purchase_eta_locked') echo app_h(app_tr('لا يمكن الحذف النهائي لفاتورة مرتبطة بمنظومة ETA.', 'ETA-linked purchase invoices cannot be permanently deleted.'));
                    if($_GET['msg'] == 'purchase_not_cancelled') echo app_h(app_tr('الحذف النهائي متاح فقط بعد إلغاء فاتورة الشراء أولاً.', 'Permanent deletion is available only after cancelling the purchase invoice first.'));
                    if($_GET['msg'] == 'purchase_has_returns') echo app_h(app_tr('لا يمكن الحذف النهائي لفاتورة شراء عليها مردودات.', 'Cannot permanently delete a purchase invoice that has returns.'));
                    if($_GET['msg'] == 'sales_paid') echo app_h(app_tr('لا يمكن حذف فاتورة مبيعات عليها سداد أو تخصيصات مالية.', 'Cannot delete a sales invoice that has receipts or financial allocations.'));
                    if($_GET['msg'] == 'sales_eta_locked') echo app_h(app_tr('لا يمكن حذف فاتورة مبيعات مرتبطة بمنظومة ETA.', 'ETA-linked sales invoices cannot be deleted.'));
                    if($_GET['msg'] == 'salary_paid') echo app_h(app_tr('لا يمكن حذف مسير تم الصرف عليه أو ربطه بسندات مالية.', 'Cannot delete a payroll sheet that has payments or financial allocations.'));
                    if($_GET['msg'] == 'purchase_stock_used') echo app_h(app_tr('لا يمكن إلغاء فاتورة الشراء لأن بعض الكميات تم صرفها من المخزون.', 'Cannot cancel the purchase invoice because some quantities were already consumed from stock.'));
                    if($_GET['msg'] == 'delete_failed') echo app_h(app_tr('تعذر تنفيذ العملية حالياً.', 'Failed to complete the requested operation.'));
                    if($_GET['msg'] == 'eta_queued') echo app_h(app_tr('تم تجهيز الفاتورة في ETA Outbox.', 'Invoice queued in ETA outbox.'));
                    if($_GET['msg'] == 'eta_deferred') echo app_h(app_tr('تم تجهيز الفاتورة في ETA Outbox وتأجيل الإرسال حتى ضبط خدمة التوقيع.', 'Invoice queued in ETA Outbox. Submission deferred until signing service is configured.'));
                    if($_GET['msg'] == 'eta_submitted') echo app_h(app_tr('تم إرسال الفاتورة إلى ETA.', 'Invoice submitted to ETA.'));
                    if($_GET['msg'] == 'eta_synced') echo app_h(app_tr('تمت مزامنة حالة ETA.', 'ETA status synced successfully.'));
                    if($_GET['msg'] == 'eta_batch_sales_ok') {
                        $batchPayload = json_decode((string)base64_decode((string)($_GET['eta_batch'] ?? ''), true), true);
                        $batchPayload = is_array($batchPayload) ? $batchPayload : [];
                        $batchMessageAr = 'تمت معالجة دفعة ETA للمبيعات بنجاح. نجح: ' . (int)($batchPayload['success'] ?? 0) . ' / فشل: ' . (int)($batchPayload['failed'] ?? 0);
                        $batchMessageEn = 'ETA sales batch completed. Success: ' . (int)($batchPayload['success'] ?? 0) . ' / Failed: ' . (int)($batchPayload['failed'] ?? 0);
                        if (!empty($batchPayload['deferred_submit'])) {
                            $batchMessageAr .= ' وتم التحويل إلى تجهيز فقط لحين ضبط خدمة التوقيع.';
                            $batchMessageEn .= ' Submission was downgraded to queue-only until the signing service is configured.';
                        }
                        echo app_h(app_tr($batchMessageAr, $batchMessageEn));
                    }
                    if($_GET['msg'] == 'eta_purchase_pull_ok') {
                        $pullPayload = json_decode((string)base64_decode((string)($_GET['eta_pull'] ?? ''), true), true);
                        $pullPayload = is_array($pullPayload) ? $pullPayload : [];
                        echo app_h(app_tr(
                            'تم سحب فواتير مشتريات من ETA. جديد: ' . (int)($pullPayload['imported'] ?? 0) . ' / مكرر: ' . (int)($pullPayload['duplicates'] ?? 0),
                            'ETA purchase pull completed. New: ' . (int)($pullPayload['imported'] ?? 0) . ' / Duplicates: ' . (int)($pullPayload['duplicates'] ?? 0)
                        ));
                    }
                    if($_GET['msg'] == 'eta_pull_sales_ok') {
                        $pullSalesPayload = json_decode((string)base64_decode((string)($_GET['eta_pull_sales'] ?? ''), true), true);
                        $pullSalesPayload = is_array($pullSalesPayload) ? $pullSalesPayload : [];
                        echo app_h(app_tr(
                            'تم سحب فواتير مبيعات من ETA. جديد: ' . (int)($pullSalesPayload['imported'] ?? 0) . ' / مكرر: ' . (int)($pullSalesPayload['duplicates'] ?? 0),
                            'ETA sales pull completed. New: ' . (int)($pullSalesPayload['imported'] ?? 0) . ' / Duplicates: ' . (int)($pullSalesPayload['duplicates'] ?? 0)
                        ));
                    }
                    if($_GET['msg'] == 'eta_failed') {
                        $etaErrorCode = trim((string)($_GET['eta_error'] ?? ''));
                        echo app_h(app_tr('تعذر تنفيذ عملية ETA. راجع ETA Outbox أو سجلات الأخطاء.', 'ETA action failed. Review ETA outbox or error logs.'));
                        if ($etaErrorCode !== '') {
                            echo ' <span style="opacity:.78;">(' . app_h($etaErrorCode) . ')</span>';
                        }
                    }
                ?>
            </span>
            <button onclick="this.parentElement.remove()" style="background:none; border:none; color:inherit; cursor:pointer;"><i class="fa-solid fa-xmark"></i></button>
        </div>
    <?php endif; ?>

    <?php if (is_array($etaAutoPurchaseNotice) && (int)($etaAutoPurchaseNotice['imported'] ?? 0) > 0): ?>
        <div class="alert-msg" style="background:rgba(52,152,219,0.12); border-color:#3498db; color:#9fd3ff;" data-eta-auto-notice="1" data-eta-new-count="<?php echo (int)$etaAutoPurchaseNotice['imported']; ?>">
            <span><?php echo app_h(app_tr('وصلت فواتير مشتريات جديدة من ETA وتم استيراد ' . (int)$etaAutoPurchaseNotice['imported'] . ' فاتورة تلقائياً.', 'New purchase invoices arrived from ETA and ' . (int)$etaAutoPurchaseNotice['imported'] . ' invoice(s) were imported automatically.')); ?></span>
            <button onclick="this.parentElement.remove()" style="background:none; border:none; color:inherit; cursor:pointer;"><i class="fa-solid fa-xmark"></i></button>
        </div>
    <?php endif; ?>

    <section class="hero-panel">
        <div class="hero-header">
            <div class="page-header">
                <div class="page-title">
                    <div class="icon-box"><i class="<?php echo $title_icon; ?>"></i></div>
                    <h2><?php echo $title; ?></h2>
                </div>
                <?php if($tab == 'sales' && $canInvoiceCreate): ?>
                    <a href="edit_invoice.php" class="btn-add-new"><i class="fa-solid fa-plus"></i> <?php echo app_h(app_tr('إنشاء فاتورة مبيعات', 'Create Sales Invoice')); ?></a>
                <?php elseif($tab == 'purchases' && $canInvoiceCreate): ?>
                    <div style="display:flex; gap:10px; flex-wrap:wrap;">
                        <a href="add_purchase.php" class="btn-add-new"><i class="fa-solid fa-plus"></i> <?php echo app_h(app_tr('تسجيل مشتريات', 'Record Purchase')); ?></a>
                        <?php if ($etaWorkRuntime && $canEtaManage): ?>
                            <a href="#eta-purchase-sync" class="btn-add-new" style="width:auto; padding:10px 18px;"><i class="fa-solid fa-download"></i> <?php echo app_h(app_tr('تنزيل فواتير المشتريات من ETA', 'Download purchase invoices from ETA')); ?></a>
                        <?php endif; ?>
                    </div>
                <?php elseif($tab == 'salaries' && $canInvoiceCreate): ?>
                    <a href="add_payroll.php" class="btn-add-new"><i class="fa-solid fa-plus"></i> <?php echo app_h(app_tr('إصدار مسير راتب', 'Create Payroll Sheet')); ?></a>
                <?php endif; ?>
            </div>
        </div>

        <div class="hero-kpis">
            <div class="hero-kpi">
                <div class="label"><?php echo app_h(app_tr('إجمالي المبالغ', 'Total amount')); ?></div>
                <div class="value"><?php echo number_format($stat_total); ?></div>
                <div class="note"><?php echo app_h(app_tr('إجمالي قيمة السجلات في هذا القسم', 'Total value of records in this section')); ?></div>
            </div>
            <div class="hero-kpi">
                <div class="label"><?php echo app_h(app_tr('المدفوع / المحصل', 'Paid / Collected')); ?></div>
                <div class="value" style="color:#2ecc71"><?php echo number_format($stat_paid); ?></div>
                <div class="note"><?php echo app_h(app_tr('القيمة المغطاة حتى الآن', 'Amount already settled')); ?></div>
            </div>
            <div class="hero-kpi">
                <div class="label"><?php echo app_h(app_tr('المتبقي', 'Remaining balance')); ?></div>
                <div class="value" style="color:#e74c3c"><?php echo number_format($stat_due); ?></div>
                <div class="note"><?php echo app_h(app_tr('الرصيد المفتوح الذي يحتاج متابعة', 'Outstanding balance that still needs attention')); ?></div>
            </div>
        </div>
        <?php if ($tab === 'sales' && $etaWorkRuntime && $canEtaManage): ?>
            <div style="margin-top:14px; display:flex; gap:10px; flex-wrap:wrap;">
                <a href="eta_outbox.php" class="btn-add-new" style="width:auto; padding:10px 18px;"><i class="fa-solid fa-receipt"></i> <?php echo app_h(app_tr('ETA Outbox', 'ETA Outbox')); ?></a>
                <a href="eta_diagnostics.php" class="btn-add-new" style="width:auto; padding:10px 18px;"><i class="fa-solid fa-stethoscope"></i> <?php echo app_h(app_tr('تشخيص ETA', 'ETA Diagnostics')); ?></a>
            </div>
            <form method="POST" style="margin-top:14px; display:grid; grid-template-columns:repeat(auto-fit,minmax(140px,1fr)); gap:10px; align-items:end;">
                <?php echo app_csrf_input(); ?>
                <input type="hidden" name="action" value="eta_batch_sales">
                <div>
                    <label style="display:block; margin-bottom:6px; color:#9ca0a8;"><?php echo app_h(app_tr('من', 'From')); ?></label>
                    <input type="date" name="eta_date_from" class="filter-select" value="<?php echo app_h(date('Y-m-01')); ?>" required>
                </div>
                <div>
                    <label style="display:block; margin-bottom:6px; color:#9ca0a8;"><?php echo app_h(app_tr('إلى', 'To')); ?></label>
                    <input type="date" name="eta_date_to" class="filter-select" value="<?php echo app_h(date('Y-m-d')); ?>" required>
                </div>
                <div>
                    <label style="display:block; margin-bottom:6px; color:#9ca0a8;"><?php echo app_h(app_tr('الإجراء', 'Action')); ?></label>
                    <select name="eta_batch_mode" class="filter-select">
                        <option value="queue"><?php echo app_h(app_tr('تجهيز فقط', 'Queue only')); ?></option>
                        <option value="submit" selected><?php echo app_h(app_tr('تجهيز وإرسال', 'Queue and submit')); ?></option>
                        <option value="sync"><?php echo app_h(app_tr('مزامنة الحالة', 'Sync status')); ?></option>
                    </select>
                </div>
                <button type="submit" class="btn-add-new" style="border:none; cursor:pointer; justify-content:center;">
                    <i class="fa-solid fa-cloud-arrow-up"></i> <?php echo app_h(app_tr('دفعة ETA للمبيعات', 'ETA Sales Batch')); ?>
                </button>
            </form>
            <form method="POST" id="eta-sales-sync" style="margin-top:10px; display:grid; grid-template-columns:repeat(auto-fit,minmax(140px,1fr)); gap:10px; align-items:end;">
                <?php echo app_csrf_input(); ?>
                <input type="hidden" name="action" value="eta_pull_sales">
                <div>
                    <label style="display:block; margin-bottom:6px; color:#9ca0a8;"><?php echo app_h(app_tr('من', 'From')); ?></label>
                    <input type="date" name="eta_sales_from" class="filter-select" value="<?php echo app_h(date('Y-m-01')); ?>" required>
                </div>
                <div>
                    <label style="display:block; margin-bottom:6px; color:#9ca0a8;"><?php echo app_h(app_tr('إلى', 'To')); ?></label>
                    <input type="date" name="eta_sales_to" class="filter-select" value="<?php echo app_h(date('Y-m-d')); ?>" required>
                </div>
                <button type="submit" class="btn-add-new" data-eta-submit-label="<?php echo app_h(app_tr('تحميل المبيعات من ETA', 'Download sales invoices from ETA')); ?>" style="border:none; cursor:pointer; justify-content:center;">
                    <i class="fa-solid fa-download"></i> <span><?php echo app_h(app_tr('تحميل المبيعات من ETA', 'Download sales invoices from ETA')); ?></span>
                </button>
                <div class="eta-sync-progress" role="status" aria-live="polite" data-eta-progress>
                    <div class="eta-sync-status">
                        <strong data-eta-status-text><?php echo app_h(app_tr('جاري الاتصال بمنظومة ETA...', 'Connecting to ETA...')); ?></strong>
                        <span data-eta-elapsed>00:00</span>
                    </div>
                    <div class="eta-progress-track"><span class="eta-progress-bar"></span></div>
                </div>
            </form>
        <?php endif; ?>
        <?php if ($tab === 'purchases' && $etaWorkRuntime && $canEtaManage): ?>
            <div style="margin-top:14px; display:flex; gap:10px; flex-wrap:wrap;">
                <a href="eta_diagnostics.php" class="btn-add-new" style="width:auto; padding:10px 18px;"><i class="fa-solid fa-stethoscope"></i> <?php echo app_h(app_tr('تشخيص ETA', 'ETA Diagnostics')); ?></a>
            </div>
            <form method="POST" id="eta-purchase-sync" style="margin-top:14px; display:grid; grid-template-columns:repeat(auto-fit,minmax(140px,1fr)); gap:10px; align-items:end;">
                <?php echo app_csrf_input(); ?>
                <input type="hidden" name="action" value="eta_pull_purchases">
                <div>
                    <label style="display:block; margin-bottom:6px; color:#9ca0a8;"><?php echo app_h(app_tr('من', 'From')); ?></label>
                    <input type="date" name="eta_purchase_from" class="filter-select" value="<?php echo app_h(date('Y-m-01')); ?>" required>
                </div>
                <div>
                    <label style="display:block; margin-bottom:6px; color:#9ca0a8;"><?php echo app_h(app_tr('إلى', 'To')); ?></label>
                    <input type="date" name="eta_purchase_to" class="filter-select" value="<?php echo app_h(date('Y-m-d')); ?>" required>
                </div>
                <button type="submit" class="btn-add-new" data-eta-submit-label="<?php echo app_h(app_tr('سحب مشتريات ETA', 'Pull ETA Purchases')); ?>" style="border:none; cursor:pointer; justify-content:center;">
                    <i class="fa-solid fa-download"></i> <span><?php echo app_h(app_tr('سحب مشتريات ETA', 'Pull ETA Purchases')); ?></span>
                </button>
                <div class="eta-sync-progress" role="status" aria-live="polite" data-eta-progress>
                    <div class="eta-sync-status">
                        <strong data-eta-status-text><?php echo app_h(app_tr('جاري الاتصال بمنظومة ETA...', 'Connecting to ETA...')); ?></strong>
                        <span data-eta-elapsed>00:00</span>
                    </div>
                    <div class="eta-progress-track"><span class="eta-progress-bar"></span></div>
                </div>
            </form>
        <?php endif; ?>
    </section>

    <div class="toolbar">
        <div class="tabs-container">
            <a href="?tab=sales<?php echo $dueFilter !== 'all' ? '&due_filter=' . urlencode($dueFilter) : ''; ?>" class="tab-btn <?php echo $tab=='sales'?'active':''; ?>"><i class="fa-solid fa-tags"></i> <?php echo app_h(app_tr('المبيعات', 'Sales')); ?></a>
            <a href="?tab=purchases" class="tab-btn <?php echo $tab=='purchases'?'active':''; ?>"><i class="fa-solid fa-boxes-stacked"></i> <?php echo app_h(app_tr('المشتريات', 'Purchases')); ?></a>
            <a href="?tab=salaries" class="tab-btn <?php echo $tab=='salaries'?'active':''; ?>"><i class="fa-solid fa-user-clock"></i> <?php echo app_h(app_tr('الرواتب', 'Payroll')); ?></a>
        </div>

        <form method="GET" class="search-form">
            <input type="hidden" name="tab" value="<?php echo $tab; ?>">
            <?php if ($tab === 'sales'): ?>
                <input type="hidden" name="due_filter" value="<?php echo app_h($dueFilter); ?>">
            <?php endif; ?>
            <i class="fa-solid fa-search"></i>
            <input type="text" name="q" value="<?php echo htmlspecialchars($search); ?>" placeholder="<?php echo app_h(app_tr('ابحث برقم الفاتورة أو اسم الجهة...', 'Search by invoice number or party name...')); ?>">
        </form>
        <?php if ($tab === 'sales'): ?>
            <form method="GET">
                <input type="hidden" name="tab" value="sales">
                <?php if ($search !== ''): ?>
                    <input type="hidden" name="q" value="<?php echo app_h($search); ?>">
                <?php endif; ?>
                <select name="due_filter" class="filter-select" onchange="this.form.submit()">
                    <option value="all" <?php echo $dueFilter === 'all' ? 'selected' : ''; ?>><?php echo app_h(app_tr('كل الفواتير', 'All invoices')); ?></option>
                    <option value="overdue" <?php echo $dueFilter === 'overdue' ? 'selected' : ''; ?>><?php echo app_h(app_tr('متأخرة', 'Overdue')); ?></option>
                </select>
            </form>
        <?php endif; ?>
        <?php if ($tab === 'purchases' && $etaWorkRuntime && $canEtaManage): ?>
            <a href="#eta-purchase-sync" class="btn-add-new" style="width:auto; padding:10px 18px;"><i class="fa-solid fa-download"></i> <?php echo app_h(app_tr('مزامنة المشتريات من ETA', 'Sync purchases from ETA')); ?></a>
        <?php endif; ?>
        <?php if ($tab === 'sales' && $etaWorkRuntime && $canEtaManage): ?>
            <a href="#eta-sales-sync" class="btn-add-new" style="width:auto; padding:10px 18px;"><i class="fa-solid fa-download"></i> <?php echo app_h(app_tr('مزامنة المبيعات من ETA', 'Sync sales from ETA')); ?></a>
        <?php endif; ?>
    </div>

    <div class="records-grid">
                <?php if($res && $res->num_rows > 0): ?>
                    <?php while($row = $res->fetch_assoc()): ?>
                        <?php 
                            $id = $row['id'];
                            $name = $row['party_name'] ?? app_tr('غير محدد', 'Not specified');
                            $status = strtolower(trim((string)($row['status'] ?? '-')));
                            $paidAmountRow = (float)($row['paid_amount'] ?? 0);
                            $remainingAmountRow = max(0.0, (float)($row['remaining_amount'] ?? 0));
                            $dueDateRow = (string)($row['due_date'] ?? '');
                            $todayDate = date('Y-m-d');

                            $st_class = 'badge-unpaid';
                            $st_icon = 'fa-xmark';
                            $st_text = app_tr('غير مدفوع', 'Unpaid');

                            if ($tab === 'sales') {
                                if ($status === 'cancelled') {
                                    $st_class = 'badge-unpaid';
                                    $st_icon = 'fa-ban';
                                    $st_text = app_tr('ملغاة', 'Cancelled');
                                } elseif ($remainingAmountRow <= 0.009) {
                                    $st_class = 'badge-paid';
                                    $st_icon = 'fa-check';
                                    $st_text = app_tr('مدفوع بالكامل', 'Paid in full');
                                } elseif ($paidAmountRow > 0.009) {
                                    $st_class = 'badge-partial';
                                    $st_icon = 'fa-ellipsis';
                                    $st_text = app_tr('دفع جزئي', 'Partially paid');
                                } elseif ($dueDateRow !== '' && $dueDateRow < $todayDate) {
                                    $st_class = 'badge-unpaid';
                                    $st_icon = 'fa-clock';
                                    $st_text = app_tr('متأخرة', 'Overdue');
                                } else {
                                    $st_class = 'badge-unpaid';
                                    $st_icon = 'fa-hourglass-half';
                                    $st_text = app_tr('غير مستحقة بعد', 'Not due yet');
                                }
                            } else {
                                if ($status === 'paid' || $remainingAmountRow <= 0.009) {
                                    $st_class = 'badge-paid';
                                    $st_icon = 'fa-check';
                                    $st_text = app_tr('مدفوع بالكامل', 'Paid in full');
                                } elseif ($status === 'partially_paid' || $status === 'partial' || $paidAmountRow > 0.009) {
                                    $st_class = 'badge-partial';
                                    $st_icon = 'fa-ellipsis';
                                    $st_text = app_tr('دفع جزئي', 'Partially paid');
                                } elseif ($status === 'cancelled') {
                                    $st_class = 'badge-unpaid';
                                    $st_icon = 'fa-ban';
                                    $st_text = app_tr('ملغاة', 'Cancelled');
                                }
                            }
                            
                            $token = app_public_token('invoice_view', $id);
                            $basePath = rtrim(str_replace('\\', '/', dirname($_SERVER['PHP_SELF'])), '/');
                            if ($basePath === '.' || $basePath === '/') { $basePath = ''; }
                            $view_url = app_base_url() . $basePath . "/view_invoice.php?id=$id&type=$tab&token=$token";
                            $docRef = '#' . $id;
                            if ($tab === 'sales' && empty($row['invoice_number'])) {
                                $row['invoice_number'] = app_assign_document_number($conn, 'invoices', (int)$id, 'invoice_number', 'invoice', $row['inv_date'] ?? date('Y-m-d'));
                            }
                            if ($tab === 'purchases' && empty($row['purchase_number'])) {
                                $row['purchase_number'] = app_assign_document_number($conn, 'purchase_invoices', (int)$id, 'purchase_number', 'purchase', $row['inv_date'] ?? date('Y-m-d'));
                            }
                            if ($tab === 'salaries' && empty($row['payroll_number'])) {
                                $row['payroll_number'] = app_assign_document_number($conn, 'payroll_sheets', (int)$id, 'payroll_number', 'payroll', date('Y-m-d'));
                            }

                            if ($tab === 'sales' && !empty($row['invoice_number'])) {
                                $docRef = (string)$row['invoice_number'];
                            } elseif ($tab === 'purchases' && !empty($row['purchase_number'])) {
                                $docRef = (string)$row['purchase_number'];
                            } elseif ($tab === 'salaries' && !empty($row['payroll_number'])) {
                                $docRef = (string)$row['payroll_number'];
                            }
                            
                            $wa_link = "#";
                            if (!empty($row['party_phone'])) {
                                $msg_txt = $isEnglish
                                    ? "Hello {$name},\nHere are the details of record {$docRef} from {$appName}:\n{$view_url}\n\nYou can review or pay directly from the link."
                                    : "مرحباً {$name}،\nإليك تفاصيل السجل رقم {$docRef} من {$appName}:\n{$view_url}\n\nيمكنك المراجعة أو الدفع مباشرة عبر الرابط.";
                                $wa_link = get_wa_link($row['party_phone'], $msg_txt);
                            }
                            
                            $date_val = $tab == 'salaries' ? $row['month_year'] : $row['inv_date'];
                            $amount_val = $tab == 'salaries' ? $row['net_salary'] : $row['total_amount'];
                            $etaStatus = strtolower(trim((string)($row['eta_status'] ?? '')));
                            $etaUuid = trim((string)($row['eta_uuid'] ?? ''));
                            $isTaxPurchase = $tab === 'purchases' && ($etaStatus !== '' || $etaUuid !== '');
                            $hasCustomPurchaseName = $tab === 'purchases'
                                && trim((string)($row['supplier_display_name'] ?? '')) !== ''
                                && trim((string)($row['supplier_display_name'] ?? '')) !== trim((string)($row['supplier_master_name'] ?? ''));
                        ?>
                        <article class="invoice-card">
                            <div class="invoice-head">
                                <div>
                                    <span class="main-text"><?php echo app_h($name); ?></span>
                                    <?php if(!empty($row['party_phone'])): ?>
                                        <span class="sub-text"><?php echo app_h($row['party_phone']); ?></span>
                                    <?php endif; ?>
                                </div>
                                <div class="invoice-doc"><?php echo app_h($docRef); ?></div>
                            </div>

                            <div>
                                <span class="main-text"><?php echo app_h($name); ?></span>
                                <?php if(!empty($row['party_phone'])): ?>
                                    <span class="sub-text"><i class="fa-solid fa-phone"></i> <?php echo app_h($row['party_phone']); ?></span>
                                <?php endif; ?>
                                <?php if($tab === 'purchases'): ?>
                                    <span class="sub-text" style="display:inline-flex; align-items:center; gap:6px; color:<?php echo $isTaxPurchase ? '#ffb3b3' : '#b9ffd0'; ?>;">
                                        <i class="fa-solid <?php echo $isTaxPurchase ? 'fa-file-shield' : 'fa-file-circle-check'; ?>"></i>
                                        <?php echo app_h($isTaxPurchase ? app_tr('ضريبية / ETA', 'Tax / ETA') : app_tr('غير ضريبية', 'Non-tax')); ?>
                                    </span>
                                    <?php if($hasCustomPurchaseName): ?>
                                        <span class="sub-text"><i class="fa-solid fa-signature"></i> <?php echo app_h(app_tr('اسم مورد مخصص على الفاتورة', 'Custom supplier name on invoice')); ?></span>
                                    <?php endif; ?>
                                <?php endif; ?>
                                <?php if($tab === 'sales' && (!empty($row['job_number']) || !empty($row['job_name']))): ?>
                                    <span class="sub-text"><i class="fa-solid fa-briefcase"></i> <?php echo app_h(trim((string)($row['job_number'] ?: ('JOB#' . (int)($row['job_id'] ?? 0))) . ' ' . (string)($row['job_name'] ?? ''))); ?></span>
                                <?php endif; ?>
                            </div>

                            <div class="invoice-grid">
                                <div class="invoice-metric">
                                    <div class="label"><?php echo app_h(app_tr('التاريخ', 'Date')); ?></div>
                                    <div class="value"><?php echo app_h($date_val); ?></div>
                                </div>
                                <div class="invoice-metric">
                                    <div class="label"><?php echo app_h(app_tr('الإجمالي', 'Total')); ?></div>
                                    <div class="value"><?php echo number_format($amount_val, 2); ?></div>
                                </div>
                                <div class="invoice-metric">
                                    <div class="label"><?php echo app_h(app_tr('المدفوع / المحصل', 'Paid / Collected')); ?></div>
                                    <div class="value" style="color:#2ecc71;"><?php echo number_format($paidAmountRow, 2); ?></div>
                                </div>
                                <div class="invoice-metric">
                                    <div class="label"><?php echo app_h(app_tr('المتبقي', 'Remaining')); ?></div>
                                    <div class="value" style="color:#e74c3c;"><?php echo number_format($remainingAmountRow, 2); ?></div>
                                </div>
                                <div class="invoice-metric">
                                    <div class="label"><?php echo app_h(app_tr('حالة السداد', 'Payment status')); ?></div>
                                    <div class="value">
                                <span class="badge <?php echo $st_class; ?>">
                                    <i class="fa-solid <?php echo app_h($st_icon); ?>"></i> <?php echo app_h($st_text); ?>
                                </span>
                                    </div>
                                </div>
                                <?php if ($tab === 'sales' && $etaWorkRuntime): ?>
                                <div class="invoice-metric">
                                    <div class="label"><?php echo app_h(app_tr('حالة ETA', 'ETA status')); ?></div>
                                    <div class="value"><?php echo app_h($etaStatus !== '' ? $etaStatus : app_tr('غير مُرسل', 'Not submitted')); ?></div>
                                    <?php if ($etaUuid !== ''): ?><div class="note"><?php echo app_h($etaUuid); ?></div><?php endif; ?>
                                </div>
                                <?php endif; ?>
                            </div>

                                <div class="actions-cell">
                                    <a href="<?php echo app_h($wa_link); ?>" target="_blank" class="action-btn btn-wa" title="<?php echo app_h(app_tr('إرسال عبر واتساب', 'Send via WhatsApp')); ?>"><i class="fa-brands fa-whatsapp"></i></a>
                                    
                                    <?php if($canInvoiceDuplicate && ($tab === 'sales' || $tab === 'purchases')): ?>
                                        <form method="POST" style="display:inline;" onsubmit="return confirm('<?php echo app_h(app_tr('هل تريد إنشاء نسخة مطابقة من هذا السجل؟', 'Do you want to create a duplicate copy of this record?')); ?>');">
                                            <?php echo app_csrf_input(); ?>
                                            <input type="hidden" name="action" value="duplicate">
                                            <input type="hidden" name="type" value="<?php echo $tab === 'purchases' ? 'purchase' : 'sales'; ?>">
                                            <input type="hidden" name="id" value="<?php echo $id; ?>">
                                            <button type="submit" class="action-btn" title="<?php echo app_h(app_tr('تكرار السجل', 'Duplicate record')); ?>"><i class="fa-solid fa-copy"></i></button>
                                        </form>
                                    <?php endif; ?>
                                    
                                    <?php if($tab == 'sales'): ?>
                                        <a href="delivery_receipt.php?id=<?php echo $id; ?>" target="_blank" class="action-btn btn-delivery" title="<?php echo app_h(app_tr('طباعة سند تسليم بضاعة (بدون أسعار)', 'Print delivery receipt (without prices)')); ?>"><i class="fa-solid fa-truck-ramp-box"></i></a>
                                        
                                        <a href="view_invoice.php?id=<?php echo $id; ?>&token=<?php echo $token; ?>&type=sales" target="_blank" class="action-btn btn-view" title="<?php echo app_h(app_tr('عرض الفاتورة', 'View invoice')); ?>"><i class="fa-solid fa-eye"></i></a>
                                        <?php if($etaWorkRuntime && $canEtaManage): ?>
                                            <a href="eta_preview.php?invoice_id=<?php echo $id; ?>" class="action-btn" title="<?php echo app_h(app_tr('معاينة ETA', 'ETA preview')); ?>"><i class="fa-solid fa-file-code"></i></a>
                                        <?php endif; ?>
                                        <?php if($canInvoiceUpdate): ?>
                                            <a href="edit_invoice.php?id=<?php echo $id; ?>" class="action-btn btn-edit" title="<?php echo app_h(app_tr('تعديل', 'Edit')); ?>"><i class="fa-solid fa-pen-to-square"></i></a>
                                            <?php if($status !== 'cancelled' && $paidAmountRow <= 0.009): ?>
                                                <a href="sales_return.php?invoice_id=<?php echo $id; ?>" class="action-btn" title="<?php echo app_h(app_tr('مردود مبيعات', 'Sales return')); ?>"><i class="fa-solid fa-rotate-left"></i></a>
                                            <?php endif; ?>
                                            <?php if($paidAmountRow > 0.009): ?>
                                                <a href="sales_refund.php?client_id=<?php echo (int)($row['client_id'] ?? 0); ?>&invoice_id=<?php echo $id; ?>" class="action-btn" title="<?php echo app_h(app_tr('رد مبلغ', 'Refund')); ?>"><i class="fa-solid fa-money-bill-transfer"></i></a>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                        <?php if($etaWorkRuntime && $canEtaManage): ?>
                                            <form method="POST" style="display:inline;">
                                                <?php echo app_csrf_input(); ?>
                                                <input type="hidden" name="action" value="eta_queue">
                                                <input type="hidden" name="type" value="sales">
                                                <input type="hidden" name="id" value="<?php echo $id; ?>">
                                                <button type="submit" class="action-btn" title="<?php echo app_h(app_tr('تجهيز في ETA Outbox', 'Queue in ETA outbox')); ?>"><i class="fa-solid fa-inbox"></i></button>
                                            </form>
                                            <form method="POST" style="display:inline;">
                                                <?php echo app_csrf_input(); ?>
                                                <input type="hidden" name="action" value="eta_submit">
                                                <input type="hidden" name="type" value="sales">
                                                <input type="hidden" name="id" value="<?php echo $id; ?>">
                                                <button type="submit" class="action-btn" title="<?php echo app_h(app_tr('إرسال إلى ETA', 'Submit to ETA')); ?>"><i class="fa-solid fa-paper-plane"></i></button>
                                            </form>
                                            <?php if($etaUuid !== ''): ?>
                                            <form method="POST" style="display:inline;">
                                                <?php echo app_csrf_input(); ?>
                                                <input type="hidden" name="action" value="eta_sync">
                                                <input type="hidden" name="type" value="sales">
                                                <input type="hidden" name="id" value="<?php echo $id; ?>">
                                                <button type="submit" class="action-btn" title="<?php echo app_h(app_tr('مزامنة ETA', 'Sync ETA')); ?>"><i class="fa-solid fa-rotate"></i></button>
                                            </form>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    
                                    <?php elseif($tab == 'purchases'): ?>
                                        <a href="print_purchase.php?id=<?php echo $id; ?>" target="_blank" class="action-btn btn-view" title="<?php echo app_h(app_tr('طباعة', 'Print')); ?>"><i class="fa-solid fa-print"></i></a>
                                        <?php if($canInvoiceUpdate): ?>
                                            <a href="edit_purchase.php?id=<?php echo $id; ?>" class="action-btn btn-edit" title="<?php echo app_h(app_tr('تعديل', 'Edit')); ?>"><i class="fa-solid fa-pen-to-square"></i></a>
                                            <?php if($status !== 'cancelled' && $paidAmountRow <= 0.009): ?>
                                                <a href="purchase_return.php?invoice_id=<?php echo $id; ?>" class="action-btn" title="<?php echo app_h(app_tr('مردود شراء', 'Purchase return')); ?>"><i class="fa-solid fa-rotate-left"></i></a>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    
                                    <?php elseif($tab == 'salaries'): ?>
                                        <a href="print_salary.php?id=<?php echo $id; ?>" target="_blank" class="action-btn btn-view" title="<?php echo app_h(app_tr('طباعة', 'Print')); ?>"><i class="fa-solid fa-print"></i></a>
                                        <?php if($canInvoiceUpdate): ?>
                                            <a href="edit_payroll.php?id=<?php echo $id; ?>" class="action-btn btn-edit" title="<?php echo app_h(app_tr('تعديل', 'Edit')); ?>"><i class="fa-solid fa-pen-to-square"></i></a>
                                        <?php endif; ?>
                                    <?php endif; ?>

                                    <?php if($canInvoiceDelete): ?>
                                        <?php
                                            $purchaseCanPurge = $tab === 'purchases'
                                                && $status === 'cancelled'
                                                && !$isTaxPurchase;
                                            $deleteAction = $purchaseCanPurge ? 'purge_purchase' : 'delete';
                                            $deleteTitle = $purchaseCanPurge
                                                ? app_tr('حذف نهائي', 'Permanent delete')
                                                : (($tab === 'purchases')
                                                    ? app_tr('إلغاء', 'Cancel')
                                                    : app_tr('حذف', 'Delete'));
                                            $deleteConfirm = $purchaseCanPurge
                                                ? app_tr('تنبيه: سيتم حذف فاتورة الشراء نهائياً مع سجلات المخزون التابعة لها. هل أنت متأكد؟', 'Warning: this purchase invoice will be permanently deleted with its related inventory logs. Are you sure?')
                                                : (($tab === 'purchases')
                                                    ? app_tr('سيتم إلغاء فاتورة الشراء وعكس ترحيل المخزون. هل تريد المتابعة؟', 'This will cancel the purchase invoice and reverse inventory posting. Continue?')
                                                    : app_tr('تنبيه: سيتم الحذف نهائياً. هل أنت متأكد؟', 'Warning: this record will be deleted permanently. Are you sure?'));
                                        ?>
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('<?php echo app_h($deleteConfirm); ?>');">
                                        <?php echo app_csrf_input(); ?>
                                        <input type="hidden" name="action" value="<?php echo app_h($deleteAction); ?>">
                                        <input type="hidden" name="type" value="<?php echo $tab=='purchases'?'purchase':($tab=='salaries'?'salary':'sales'); ?>">
                                        <input type="hidden" name="id" value="<?php echo $id; ?>">
                                        <button type="submit" class="action-btn btn-del" title="<?php echo app_h($deleteTitle); ?>"><i class="fa-solid fa-trash-can"></i></button>
                                    </form>
                                    <?php endif; ?>
                                </div>
                        </article>
                    <?php endwhile; ?>
                <?php else: ?>
                        <div style="text-align:center; padding:60px 20px; color:#666; border-radius: 20px; border:1px dashed rgba(255,255,255,0.1); background:rgba(255,255,255,0.025);">
                            <i class="fa-solid fa-folder-open" style="font-size:3rem; margin-bottom:15px; opacity:0.3;"></i>
                            <br><?php echo app_h(app_tr('لا توجد بيانات مسجلة في هذا القسم حتى الآن.', 'No records have been added in this section yet.')); ?>
                        </div>
                <?php endif; ?>
    </div>
</div>
</div>
<?php if (is_array($etaAutoPurchaseNotice) && (int)($etaAutoPurchaseNotice['imported'] ?? 0) > 0): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    try {
        if ('Notification' in window && Notification.permission === 'granted') {
            new Notification('ETA', {
                body: <?php echo json_encode(app_tr('وصلت فواتير مشتريات جديدة وتم استيرادها تلقائياً.', 'New purchase invoices arrived and were imported automatically.'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>
            });
        }
    } catch (e) {}
});
</script>
<?php endif; ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var syncForms = document.querySelectorAll('#eta-sales-sync, #eta-purchase-sync');
    var stages = [
        <?php echo json_encode(app_tr('جاري الاتصال بمنظومة ETA...', 'Connecting to ETA...'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
        <?php echo json_encode(app_tr('جاري قراءة المستندات من ETA...', 'Reading documents from ETA...'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
        <?php echo json_encode(app_tr('جاري استيراد الفواتير وتحديث الحسابات...', 'Importing invoices and updating balances...'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
        <?php echo json_encode(app_tr('العملية ما زالت تعمل، برجاء الانتظار...', 'Still working, please wait...'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>
    ];
    function pad(value) {
        return String(value).padStart(2, '0');
    }
    syncForms.forEach(function (form) {
        form.addEventListener('submit', function () {
            var button = form.querySelector('button[type="submit"]');
            var progress = form.querySelector('[data-eta-progress]');
            var statusText = form.querySelector('[data-eta-status-text]');
            var elapsedText = form.querySelector('[data-eta-elapsed]');
            var startedAt = Date.now();
            if (button) {
                button.disabled = true;
                button.innerHTML = '<i class="fa-solid fa-circle-notch fa-spin"></i> <span>' + <?php echo json_encode(app_tr('جاري التحميل...', 'Loading...'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?> + '</span>';
            }
            if (progress) {
                progress.classList.add('is-active');
            }
            window.setInterval(function () {
                var elapsed = Math.floor((Date.now() - startedAt) / 1000);
                if (elapsedText) {
                    elapsedText.textContent = pad(Math.floor(elapsed / 60)) + ':' + pad(elapsed % 60);
                }
                if (statusText) {
                    var index = Math.min(stages.length - 1, Math.floor(elapsed / 12));
                    statusText.textContent = stages[index];
                }
            }, 1000);
        });
    });
});
</script>
<?php include 'footer.php'; ob_end_flush(); ?>
