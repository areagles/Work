<?php
// edit_payroll.php - تعديل مسير راتب
ob_start();
require 'auth.php'; 
require 'config.php'; 
app_handle_lang_switch($conn);
require 'header.php';

$canPayrollUpdate = app_user_can_any(['payroll.update', 'invoices.update']);
if (!$canPayrollUpdate) {
    http_response_code(403);
    echo "<div class='container' style='margin-top:30px;'><div style='background:#1d0f0f;border:1px solid #5b2020;border-radius:12px;padding:20px;color:#ffb3b3;'>" . app_h(app_tr('⛔ لا تملك صلاحية تعديل مسيرات الرواتب.', '⛔ You do not have permission to update payroll sheets.')) . "</div></div>";
    require 'footer.php';
    exit;
}

app_ensure_payroll_schema($conn);

$id = intval($_GET['id']);
$row = $conn->query("SELECT * FROM payroll_sheets WHERE id=$id")->fetch_assoc();
if (!$row) {
    echo "<div class='container' style='margin-top:30px;'><div style='background:#1d0f0f;border:1px solid #5b2020;border-radius:12px;padding:20px;color:#ffb3b3;'>"
        . app_h(app_tr('بيان راتب غير موجود.', 'Payroll sheet not found.'))
        . "</div></div>";
    require 'footer.php';
    exit;
}

$currentLoanDeduction = max(0, (float)($row['loan_deduction'] ?? 0));
$manualDeductionCurrent = max(0, (float)$row['deductions'] - $currentLoanDeduction);
$employeeId = (int)($row['employee_id'] ?? 0);
$loanOutstandingNow = app_payroll_employee_outstanding_loan($conn, $employeeId) + $currentLoanDeduction;
$autoLoanInitially = $currentLoanDeduction > 0.0001;
$employeeName = trim((string)($conn->query("SELECT full_name FROM users WHERE id = {$employeeId} LIMIT 1")->fetch_row()[0] ?? ''));

if($_SERVER['REQUEST_METHOD'] == 'POST'){
    if (!$canPayrollUpdate) {
        http_response_code(403);
        die(app_h(app_tr('غير مصرح بتنفيذ هذه العملية.', 'Not authorized to perform this action.')));
    }
    $basic = floatval($_POST['basic']);
    $bonus = floatval($_POST['bonus']);
    $manualDeduct = max(0, floatval($_POST['deduct']));
    $autoLoanApply = isset($_POST['auto_loan_apply']);
    $loanDeduct = $autoLoanApply ? $loanOutstandingNow : max(0, floatval($_POST['loan_deduction'] ?? 0));
    $notes = $conn->real_escape_string($_POST['notes']);
    
    $gross = max(0, $basic + $bonus);
    $maxLoanDeduct = min($gross, $loanOutstandingNow);
    if ($loanDeduct > $maxLoanDeduct) {
        $loanDeduct = $maxLoanDeduct;
    }
    $deduct = $manualDeduct + $loanDeduct;
    $net = $gross - $deduct;
    if ($net < 0) {
        $net = 0;
    }
    $sql = "UPDATE payroll_sheets SET basic_salary='$basic', bonus='$bonus', deductions='$deduct', loan_deduction='$loanDeduct', net_salary='$net', notes='$notes' WHERE id=$id";
    
    if($conn->query($sql)){
        app_payroll_sync_sheet($conn, $id);
        $msg = $autoLoanApply && $loanDeduct > 0
            ? app_tr('✅ تم تعديل الراتب بنجاح مع خصم السلفة تلقائياً.', '✅ Payroll updated and loan deducted automatically.')
            : app_tr('✅ تم تعديل الراتب بنجاح.', '✅ Payroll updated successfully.');
        echo "<script>alert(" . json_encode($msg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "); window.location.href='invoices.php?tab=salaries';</script>";
    }
}
?>

<style>
.payroll-edit-shell{max-width:1080px;margin:30px auto;padding:0 18px}
.payroll-edit-grid{display:grid;grid-template-columns:320px minmax(0,1fr);gap:22px;align-items:start}
.payroll-summary-card,.payroll-form-card{background:linear-gradient(180deg,rgba(19,24,34,.96),rgba(12,15,22,.96));border:1px solid rgba(212,175,55,.24);border-radius:22px;box-shadow:0 18px 50px rgba(0,0,0,.34);overflow:hidden}
.payroll-card-head{padding:22px 24px 16px;border-bottom:1px solid rgba(212,175,55,.14)}
.payroll-card-head h1,.payroll-card-head h2{margin:0;color:#f7f2d0;font-size:2rem;font-weight:900}
.payroll-card-head p{margin:8px 0 0;color:#b8c0d2;font-size:1rem}
.payroll-card-body{padding:22px 24px 24px}
.payroll-stat{display:flex;justify-content:space-between;gap:12px;padding:14px 0;border-bottom:1px dashed rgba(212,175,55,.14)}
.payroll-stat:last-child{border-bottom:none}
.payroll-stat-label{color:#9ca5ba;font-weight:700}
.payroll-stat-value{color:#fff;font-weight:900}
.payroll-form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:18px}
.payroll-field{display:flex;flex-direction:column;gap:8px}
.payroll-field.full{grid-column:1/-1}
.payroll-field label{color:#d9dfeb;font-size:1rem;font-weight:800}
.payroll-field input,.payroll-field textarea{width:100%;padding:14px 16px;border-radius:14px;border:1px solid rgba(212,175,55,.18);background:#0d1118;color:#fff;font-size:1rem;transition:.2s ease}
.payroll-field input:focus,.payroll-field textarea:focus{outline:none;border-color:rgba(212,175,55,.52);box-shadow:0 0 0 4px rgba(212,175,55,.08)}
.payroll-field textarea{min-height:160px;resize:vertical}
.loan-box{grid-column:1/-1;background:rgba(212,175,55,.06);border:1px solid rgba(212,175,55,.18);border-radius:18px;padding:18px}
.loan-top{display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap}
.loan-toggle{display:flex;align-items:center;gap:12px;color:#f7f2d0;font-weight:900}
.loan-help{margin-top:10px;color:#b5bdcf;line-height:1.7}
.loan-outstanding{display:inline-flex;align-items:center;gap:8px;padding:8px 12px;border-radius:999px;background:rgba(212,175,55,.12);color:#f5d66d;font-weight:900}
.net-box{grid-column:1/-1;background:linear-gradient(135deg,rgba(212,175,55,.16),rgba(212,175,55,.05));border:1px solid rgba(212,175,55,.22);border-radius:18px;padding:18px 20px;display:flex;justify-content:space-between;align-items:center;gap:16px;flex-wrap:wrap}
.net-box strong{color:#fff;font-size:1.08rem}
#net{color:var(--gold);font-size:1.8rem;font-weight:900}
.payroll-actions{display:flex;justify-content:flex-end;gap:12px;flex-wrap:wrap;margin-top:20px}
.payroll-actions .btn-secondary-link,.payroll-actions .btn-royal{display:inline-flex;align-items:center;justify-content:center;min-width:160px;padding:14px 20px;border-radius:14px;font-weight:900;text-decoration:none;border:none;cursor:pointer}
.btn-secondary-link{background:#141922;color:#c9d1e0;border:1px solid rgba(255,255,255,.08)}
.btn-secondary-link:hover{background:#1a2130;color:#fff}
@media (max-width: 920px){.payroll-edit-grid{grid-template-columns:1fr}.payroll-form-grid{grid-template-columns:1fr}.payroll-card-head h1,.payroll-card-head h2{font-size:1.55rem}}
</style>

<div class="payroll-edit-shell">
    <div class="payroll-edit-grid">
        <aside class="payroll-summary-card">
            <div class="payroll-card-head">
                <h2><?php echo app_h(app_tr('ملخص البيان', 'Payroll Summary')); ?></h2>
                <p><?php echo app_h(app_tr('مراجعة الوضع الحالي قبل اعتماد التعديل.', 'Review the current state before saving changes.')); ?></p>
            </div>
            <div class="payroll-card-body">
                <div class="payroll-stat">
                    <span class="payroll-stat-label"><?php echo app_h(app_tr('الموظف', 'Employee')); ?></span>
                    <span class="payroll-stat-value"><?php echo app_h($employeeName !== '' ? $employeeName : app_tr('غير محدد', 'Not set')); ?></span>
                </div>
                <div class="payroll-stat">
                    <span class="payroll-stat-label"><?php echo app_h(app_tr('الشهر', 'Month')); ?></span>
                    <span class="payroll-stat-value"><?php echo app_h((string)($row['month_year'] ?? '-')); ?></span>
                </div>
                <div class="payroll-stat">
                    <span class="payroll-stat-label"><?php echo app_h(app_tr('تم صرفه', 'Already Paid')); ?></span>
                    <span class="payroll-stat-value"><?php echo number_format((float)($row['paid_amount'] ?? 0), 2); ?></span>
                </div>
                <div class="payroll-stat">
                    <span class="payroll-stat-label"><?php echo app_h(app_tr('المتبقي الحالي', 'Current Remaining')); ?></span>
                    <span class="payroll-stat-value"><?php echo number_format((float)($row['remaining_amount'] ?? 0), 2); ?></span>
                </div>
                <div class="payroll-stat">
                    <span class="payroll-stat-label"><?php echo app_h(app_tr('رصيد السلف المتاح للخصم', 'Advance Balance Available')); ?></span>
                    <span class="payroll-stat-value" style="color:#f5d66d;"><?php echo number_format($loanOutstandingNow, 2); ?></span>
                </div>
            </div>
        </aside>

        <section class="payroll-form-card">
            <div class="payroll-card-head">
                <h1><?php echo app_h(app_tr('تعديل بيان الراتب', 'Edit Payroll Sheet')); ?></h1>
                <p><?php echo app_h(app_tr('عدّل المكونات الأساسية، ثم راجع صافي الراتب قبل الحفظ.', 'Update the payroll values, then review the net amount before saving.')); ?></p>
            </div>
            <div class="payroll-card-body">
                <form method="POST">
                    <div class="payroll-form-grid">
                        <div class="payroll-field">
                            <label for="basic"><?php echo app_h(app_tr('الراتب الأساسي', 'Basic Salary')); ?></label>
                            <input type="number" name="basic" id="basic" value="<?php echo app_h((string)$row['basic_salary']); ?>" oninput="calcNet()" step="0.01">
                        </div>

                        <div class="payroll-field">
                            <label for="bonus"><?php echo app_h(app_tr('إضافي / حوافز', 'Bonus / Incentives')); ?></label>
                            <input type="number" name="bonus" id="bonus" value="<?php echo app_h((string)$row['bonus']); ?>" oninput="calcNet()" step="0.01">
                        </div>

                        <div class="payroll-field">
                            <label for="deduct"><?php echo app_h(app_tr('خصومات أخرى', 'Other Deductions')); ?></label>
                            <input type="number" name="deduct" id="deduct" value="<?php echo app_h((string)$manualDeductionCurrent); ?>" oninput="calcNet()" step="0.01">
                        </div>

                        <div class="payroll-field">
                            <label for="loan_deduction"><?php echo app_h(app_tr('خصم السلفة يدويًا', 'Manual Advance Deduction')); ?></label>
                            <input type="number" name="loan_deduction" id="loan_deduction" value="<?php echo app_h((string)$currentLoanDeduction); ?>" oninput="calcNet()" step="0.01" max="<?php echo app_h((string)$loanOutstandingNow); ?>">
                        </div>

                        <div class="loan-box">
                            <div class="loan-top">
                                <label class="loan-toggle">
                                    <input type="checkbox" name="auto_loan_apply" id="auto_loan_apply" value="1" <?php echo $autoLoanInitially ? 'checked' : ''; ?> onchange="calcNet()">
                                    <span><?php echo app_h(app_tr('خصم تلقائي من السلفة لصالح الراتب', 'Auto-deduct advance against payroll')); ?></span>
                                </label>
                                <span class="loan-outstanding"><?php echo app_h(app_tr('المتاح', 'Available')); ?>: <?php echo number_format($loanOutstandingNow, 2); ?></span>
                            </div>
                            <div class="loan-help">
                                <?php echo app_h(app_tr('عند التفعيل، سيخصم النظام تلقائيًا الحد الممكن من السلفة حتى صافي الراتب المستحق أو الرصيد المتاح، أيهما أقل. عند الإلغاء يمكنك إدخال الخصم يدويًا.', 'When enabled, the system automatically deducts the possible advance amount up to the payroll net or the available advance balance, whichever is lower. Disable it to enter a manual deduction.')); ?>
                            </div>
                        </div>

                        <div class="net-box">
                            <strong><?php echo app_h(app_tr('صافي الراتب بعد التعديل', 'Net Payroll After Update')); ?></strong>
                            <span id="net"><?php echo number_format((float)$row['net_salary'], 2); ?></span>
                        </div>

                        <div class="payroll-field full">
                            <label for="notes"><?php echo app_h(app_tr('ملاحظات', 'Notes')); ?></label>
                            <textarea name="notes" id="notes"><?php echo app_h((string)$row['notes']); ?></textarea>
                        </div>
                    </div>

                    <div class="payroll-actions">
                        <a href="invoices.php?tab=salaries" class="btn-secondary-link"><?php echo app_h(app_tr('رجوع', 'Back')); ?></a>
                        <button type="submit" class="btn-royal"><?php echo app_h(app_tr('حفظ التعديل', 'Save Changes')); ?></button>
                    </div>
                </form>
            </div>
        </section>
    </div>
</div>

<script>
function calcNet(){
    const basicInput = document.getElementById('basic');
    const bonusInput = document.getElementById('bonus');
    const deductInput = document.getElementById('deduct');
    const loanInput = document.getElementById('loan_deduction');
    const autoLoan = document.getElementById('auto_loan_apply');
    let b = parseFloat(basicInput.value) || 0;
    let bo = parseFloat(bonusInput.value) || 0;
    let d = parseFloat(deductInput.value) || 0;
    let ld = parseFloat(loanInput.value) || 0;
    const maxLoan = <?php echo json_encode((float)$loanOutstandingNow); ?>;
    const gross = Math.max(0, b + bo);
    if (autoLoan && autoLoan.checked) {
        ld = Math.min(maxLoan, gross);
        loanInput.value = ld.toFixed(2);
        loanInput.setAttribute('readonly', 'readonly');
        loanInput.style.opacity = '0.7';
    } else {
        loanInput.removeAttribute('readonly');
        loanInput.style.opacity = '1';
    }
    if (ld > maxLoan) {
        ld = maxLoan;
        loanInput.value = maxLoan.toFixed(2);
    }
    if (ld > gross) {
        ld = gross;
        loanInput.value = gross.toFixed(2);
    }
    let net = b + bo - d - ld;
    if (net < 0) net = 0;
    document.getElementById('net').innerText = net.toFixed(2);
}
calcNet();
</script>
<?php include 'footer.php'; ob_end_flush(); ?>
