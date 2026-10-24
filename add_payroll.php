<?php
// add_payroll.php - إعداد مسير راتب موظف
ob_start();
require 'auth.php'; require 'config.php'; app_handle_lang_switch($conn); require 'header.php';

$canPayrollCreate = app_user_can_any(['payroll.create', 'invoices.create']);
if (!$canPayrollCreate) {
    http_response_code(403);
    echo "<div class='container' style='margin-top:30px;'><div style='background:#1d0f0f;border:1px solid #5b2020;border-radius:12px;padding:20px;color:#ffb3b3;'>" . app_h(app_tr('لا تملك صلاحية إصدار مسيرات الرواتب.', 'You do not have permission to create payroll sheets.')) . "</div></div>";
    require 'footer.php';
    exit;
}

app_ensure_payroll_schema($conn);

if($_SERVER['REQUEST_METHOD'] == 'POST'){
    if (!$canPayrollCreate) {
        http_response_code(403);
        die(app_h(app_tr('غير مصرح بتنفيذ هذه العملية.', 'Not authorized to perform this action.')));
    }
    $emp_id = intval($_POST['employee_id']);
    $month = $_POST['month']; // YYYY-MM
    $basic = floatval($_POST['basic']);
    $bonus = floatval($_POST['bonus']);
    $manualDeduct = max(0, floatval($_POST['deduct']));
    $autoLoanApply = isset($_POST['auto_loan_apply']);
    $autoLoanDeduct = 0.0;
    if ($autoLoanApply && $emp_id > 0) {
        $autoLoanDeduct = app_payroll_employee_outstanding_loan($conn, $emp_id);
    }
    $gross = max(0, $basic + $bonus);
    if ($autoLoanDeduct > $gross) {
        $autoLoanDeduct = $gross;
    }
    $deduct = $manualDeduct + $autoLoanDeduct;
    $notes = $conn->real_escape_string($_POST['notes']);
    $empRow = $conn->query("SELECT full_name FROM users WHERE id=$emp_id LIMIT 1")->fetch_assoc();
    $employeeSnapshot = $conn->real_escape_string(trim((string)($empRow['full_name'] ?? '')));
    $net = $gross - $deduct;
    if ($net < 0) {
        $net = 0;
    }

    // التأكد من عدم تكرار الراتب لنفس الشهر
    $check = $conn->query("SELECT id FROM payroll_sheets WHERE employee_id=$emp_id AND month_year='$month'");
    if($check->num_rows > 0){
        echo "<script>alert('تم إصدار راتب هذا الشهر لهذا الموظف من قبل.');</script>";
    } else {
        $autoLoanDeductSql = number_format($autoLoanDeduct, 2, '.', '');
        $sql = "INSERT INTO payroll_sheets (employee_id, employee_name_snapshot, month_year, basic_salary, bonus, deductions, loan_deduction, net_salary, paid_amount, remaining_amount, status, notes)
                VALUES ('$emp_id', '$employeeSnapshot', '$month', '$basic', '$bonus', '$deduct', '{$autoLoanDeductSql}', '$net', '0.00', '$net', 'pending', '$notes')";
        
        if($conn->query($sql)){
            $newPayrollId = (int)$conn->insert_id;
            app_assign_document_number($conn, 'payroll_sheets', $newPayrollId, 'payroll_number', 'payroll', date('Y-m-d'));
            app_payroll_sync_sheet($conn, $newPayrollId);
            $loanMsg = $autoLoanDeduct > 0 ? ('\\nتم خصم سلف تلقائياً: ' . number_format($autoLoanDeduct, 2) . ' ج.م') : '';
            echo "<script>alert('تم اعتماد بيان الراتب بنجاح (بانتظار الصرف)' + '$loanMsg'); window.location.href='payroll.php';</script>";
        }
    }
}

$employeeRows = [];
$loanMap = [];
$salaryMap = [];
$employeeSalarySql = "
    SELECT
        u.id,
        u.full_name,
        COALESCE(e.salary, (
            SELECT ps.basic_salary
            FROM payroll_sheets ps
            WHERE ps.employee_id = u.id
            ORDER BY ps.id DESC
            LIMIT 1
        ), 0) AS basic_salary
    FROM users u
    LEFT JOIN employees e ON TRIM(e.name) = TRIM(u.full_name)
    WHERE u.is_active = 1 AND u.archived_at IS NULL
    ORDER BY u.full_name ASC
";
$users = false;
try {
    $users = $conn->query($employeeSalarySql);
} catch (Throwable $e) {
    $users = $conn->query("
        SELECT
            u.id,
            u.full_name,
            COALESCE((
                SELECT ps.basic_salary
                FROM payroll_sheets ps
                WHERE ps.employee_id = u.id
                ORDER BY ps.id DESC
                LIMIT 1
            ), 0) AS basic_salary
        FROM users u
        WHERE u.is_active = 1 AND u.archived_at IS NULL
        ORDER BY u.full_name ASC
    ");
}
if ($users) {
    while ($u = $users->fetch_assoc()) {
        $uid = (int)($u['id'] ?? 0);
        if ($uid <= 0) {
            continue;
        }
        $employeeRows[] = $u;
        $loanMap[$uid] = app_payroll_employee_outstanding_loan($conn, $uid);
        $salaryMap[$uid] = (float)($u['basic_salary'] ?? 0);
    }
}
?>

<style>
    :root {
        --royal-gold: #d4af37;
        --royal-gold-dark: #aa8c2c;
        --dark-bg: #0f0f0f;
        --panel-bg: #1a1a1a;
        --text-color: #e0e0e0;
        --input-bg: #252525;
        --border-color: #333;
    }

    body {
        background-color: var(--dark-bg);
        font-family: 'Cairo', sans-serif;
        color: var(--text-color);
    }

    .royal-container {
        padding: 40px 20px;
        min-height: 80vh;
        display: flex;
        justify-content: center;
        align-items: flex-start;
    }

    .royal-card {
        background: var(--panel-bg);
        border: 1px solid var(--royal-gold);
        border-radius: 15px;
        padding: 40px;
        width: 100%;
        max-width: 600px;
        box-shadow: 0 10px 30px rgba(0, 0, 0, 0.5);
        position: relative;
        overflow: hidden;
    }

    /* شريط ذهبي علوي */
    .royal-card::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        height: 4px;
        background: linear-gradient(90deg, var(--royal-gold), #fff, var(--royal-gold));
    }

    .page-title {
        color: var(--royal-gold);
        text-align: center;
        margin-bottom: 30px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 1px;
        text-shadow: 0 2px 4px rgba(0,0,0,0.5);
    }

    label {
        color: #b0b0b0;
        font-size: 0.95rem;
        margin-bottom: 8px;
        display: block;
        font-weight: 600;
    }

    /* تنسيق الحقول */
    select, input[type="month"], input[type="number"], textarea {
        width: 100%;
        padding: 12px 15px;
        background-color: var(--input-bg);
        border: 1px solid var(--border-color);
        border-radius: 8px;
        color: #fff;
        font-size: 1rem;
        margin-bottom: 20px;
        transition: all 0.3s ease;
        outline: none;
    }

    select:focus, input:focus, textarea:focus {
        border-color: var(--royal-gold);
        box-shadow: 0 0 10px rgba(212, 175, 55, 0.2);
    }

    /* منطقة الحسابات */
    .calc-box {
        background: #111; /* خلفية داكنة جداً للتباين */
        padding: 25px;
        border-radius: 12px;
        border: 1px dashed #444;
        margin-top: 10px;
        margin-bottom: 25px;
        position: relative;
    }

    .calc-row {
        display: flex;
        align-items: center;
        margin-bottom: 15px;
    }

    .calc-row label {
        width: 140px;
        margin-bottom: 0;
    }

    .calc-row input {
        margin-bottom: 0;
        flex: 1;
        text-align: left;
        font-family: monospace;
        letter-spacing: 1px;
    }

    .net-salary-display {
        background: linear-gradient(135deg, rgba(212, 175, 55, 0.1), rgba(0,0,0,0));
        padding: 15px;
        border-radius: 8px;
        border-right: 4px solid var(--royal-gold);
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-top: 20px;
    }

    .net-salary-text {
        font-size: 1.2rem;
        font-weight: bold;
        color: #fff;
    }

    #net_salary {
        font-size: 1.8rem;
        font-weight: 800;
        color: var(--royal-gold);
        text-shadow: 0 0 10px rgba(212, 175, 55, 0.4);
    }

    /* الزر الملكي */
    .btn-royal {
        background: linear-gradient(45deg, var(--royal-gold-dark), var(--royal-gold));
        color: #000;
        border: none;
        padding: 15px;
        font-size: 1.1rem;
        font-weight: bold;
        border-radius: 50px;
        cursor: pointer;
        width: 100%;
        transition: transform 0.2s, box-shadow 0.2s;
        display: block;
        margin-top: 10px;
    }

    .btn-royal:hover {
        transform: translateY(-2px);
        box-shadow: 0 5px 15px rgba(212, 175, 55, 0.4);
        color: #fff;
    }

    /* أيقونات داخلية */
    .icon-label { margin-left: 8px; }
</style>

<div class="royal-container">
    <div class="royal-card">
        <h2 class="page-title"><i class="fa-solid fa-file-invoice-dollar icon-label"></i> إعداد مسير راتب</h2>
        
        <form method="POST">
            <label><i class="fa-solid fa-user icon-label"></i> اختر الموظف</label>
            <select name="employee_id" id="employee_id" required onchange="onEmployeeChange()">
                <option value="">-- القائمة --</option>
                <?php 
                foreach ($employeeRows as $u) {
                    $uid = (int)($u['id'] ?? 0);
                    $name = app_h((string)($u['full_name'] ?? ''));
                    $salary = number_format((float)($u['basic_salary'] ?? 0), 2, '.', '');
                    echo "<option value='{$uid}' data-salary='{$salary}'>{$name}</option>";
                }
                ?>
            </select>

            <label><i class="fa-solid fa-calendar-days icon-label"></i> عن شهر</label>
            <input type="month" name="month" value="<?php echo date('Y-m'); ?>" required>

            <div class="calc-box">
                <div class="calc-row">
                    <label><i class="fa-solid fa-money-bill-wave icon-label"></i> الراتب الأساسي</label>
                    <input type="number" name="basic" id="basic" step="0.01" value="0" oninput="calcNet()" required style="border-left: 3px solid #fff;">
                </div>
                <small style="display:block; color:#8b93a7; margin-top:-6px; margin-bottom:16px;">يُجلب تلقائياً من ملف الموظف، ويمكن تعديله لهذه المسيرة فقط عند الحاجة.</small>
                
                <div class="calc-row">
                    <label style="color:#2ecc71;"><i class="fa-solid fa-plus-circle icon-label"></i> إضافي / مكافآت</label>
                    <input type="number" name="bonus" id="bonus" step="0.01" value="0" oninput="calcNet()" style="border-color:#2ecc71; color:#2ecc71;">
                </div>
                
                <div class="calc-row">
                    <label style="color:#e74c3c;"><i class="fa-solid fa-minus-circle icon-label"></i> خصومات أخرى</label>
                    <input type="number" name="deduct" id="deduct" step="0.01" value="0" oninput="calcNet()" style="border-color:#e74c3c; color:#e74c3c;">
                </div>

                <div class="calc-row" style="align-items:flex-start; flex-direction:column; gap:8px;">
                    <label style="width:100%; color:#f1c40f;"><i class="fa-solid fa-wallet icon-label"></i> خصم سلف تلقائي</label>
                    <div style="width:100%; display:flex; align-items:center; gap:10px; justify-content:space-between; background:#101215; border:1px solid #3a3214; border-radius:8px; padding:10px 12px;">
                        <label style="margin:0; display:flex; align-items:center; gap:8px; width:auto;">
                            <input type="checkbox" name="auto_loan_apply" id="auto_loan_apply" checked onchange="calcNet()" style="transform:scale(1.15); accent-color:#f1c40f;">
                            تطبيق تلقائي
                        </label>
                        <span style="font-size:0.9rem; color:#f1c40f;">المتبقي على الموظف: <strong id="loan_outstanding">0.00</strong> ج.م</span>
                    </div>
                    <small style="color:#8b93a7;">يتم الخصم تلقائياً من صافي الراتب بدون تجاوز إجمالي المستحق.</small>
                </div>

                <hr style="border-color:#333; margin: 20px 0;">
                
                <div class="net-salary-display">
                    <span class="net-salary-text">صافي الراتب المستحق:</span>
                    <span id="net_salary">0.00</span>
                </div>
            </div>

            <label><i class="fa-solid fa-note-sticky icon-label"></i> ملاحظات إدارية</label>
            <textarea name="notes" rows="3" placeholder="أدخل أي ملاحظات إضافية هنا..."></textarea>

            <button type="submit" class="btn-royal">
                <i class="fa-solid fa-check-circle icon-label"></i> حفظ واعتماد البيان
            </button>
        </form>
    </div>
</div>

<script>
const employeeLoanMap = <?php echo json_encode($loanMap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
const employeeSalaryMap = <?php echo json_encode($salaryMap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;

function currentEmployeeSalary(){
    const empId = document.getElementById('employee_id').value;
    return parseFloat(employeeSalaryMap[empId] || 0) || 0;
}

function currentLoanDeduction(){
    const empId = document.getElementById('employee_id').value;
    const autoEnabled = document.getElementById('auto_loan_apply').checked;
    const basic = parseFloat(document.getElementById('basic').value) || 0;
    const bonus = parseFloat(document.getElementById('bonus').value) || 0;
    const gross = Math.max(0, basic + bonus);
    const outstanding = parseFloat(employeeLoanMap[empId] || 0) || 0;
    document.getElementById('loan_outstanding').innerText = outstanding.toFixed(2);
    if (!autoEnabled) return 0;
    return Math.min(outstanding, gross);
}

function onEmployeeChange(){
    const basicInput = document.getElementById('basic');
    const mappedSalary = currentEmployeeSalary();
    basicInput.value = mappedSalary > 0 ? mappedSalary.toFixed(2) : '0.00';
    calcNet();
}

function calcNet(){
    let basic = parseFloat(document.getElementById('basic').value) || 0;
    let bonus = parseFloat(document.getElementById('bonus').value) || 0;
    let deduct = parseFloat(document.getElementById('deduct').value) || 0;
    let loanDeduct = currentLoanDeduction();
    
    // معادلة الصافي مع خصم السلف التلقائي
    let total = (basic + bonus - deduct - loanDeduct);
    if (total < 0) total = 0;
    document.getElementById('net_salary').innerText = total.toFixed(2);
}

window.addEventListener('DOMContentLoaded', onEmployeeChange);
</script>

<?php include 'footer.php'; ?>
