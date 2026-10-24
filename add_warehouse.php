<?php
ob_start(); // Start output buffering at the very beginning

// add_warehouse.php - (Royal Phantom V1.1 - Header Fix)

error_reporting(E_ALL);

require 'auth.php';
require 'config.php';
app_handle_lang_switch($conn);

// IMPORTANT: The header is included AFTER the logic that might cause a redirect.

$canCreateWarehouse = app_user_can('inventory.warehouses.create');

// Server-side validation and data processing first
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$canCreateWarehouse) {
        // This check is inside the POST block to prevent displaying the page to unauthorized users who might land here.
        // We will show an error message instead of the form.
    } else {
        if (!app_verify_csrf($_POST['_csrf_token'] ?? '')) {
            $message = "<div class='alert alert-danger'>انتهت صلاحية الجلسة، أعد المحاولة.</div>";
        } else {
        $name = trim((string)($_POST['name'] ?? ''));
        $location = trim((string)($_POST['location'] ?? ''));
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        $manager_id = !empty($_POST['manager_id']) ? intval($_POST['manager_id']) : null;

        if (!empty($name)) {
            $sql = "INSERT INTO warehouses (name, location, is_active, manager_id) VALUES (?, ?, ?, ?)";
            $stmt = $conn->prepare($sql);
            if ($stmt) {
                $stmt->bind_param('ssii', $name, $location, $is_active, $manager_id);
                if ($stmt->execute()) {
                    // If execution is successful, redirect.
                    // Output buffering ensures this works.
                    header("Location: warehouses.php?msg=added");
                    exit;
                } else {
                    $message = "<div class='alert alert-danger'>خطأ في الحفظ: " . $stmt->error . "</div>";
                }
                $stmt->close();
            } else {
                 $message = "<div class='alert alert-danger'>خطأ في تجهيز الاستعلام: " . $conn->error . "</div>";
            }
        } else {
            $message = "<div class='alert alert-warning'>الرجاء إدخال اسم المخزن.</div>";
        }
        }
    }
}

// Now, include the header.
require 'header.php';
echo '<link rel="stylesheet" href="assets/css/inventory-theme.css?v=20260311-1">';

// Fetch users to populate the manager dropdown
$users_result = $conn->query("SELECT id, full_name, username FROM users WHERE role IN ('admin', 'manager', 'production') ORDER BY full_name ASC");

// Final check for page access
if (!$canCreateWarehouse) {
    echo "<div class='container'><h1><i class='fa-solid fa-lock'></i> صلاحيات الوصول غير كافية</h1></div>";
    require 'footer.php';
    exit;
}

?>

<style>
    :root { --ae-gold: #d4af37; --border: rgba(212, 175, 55, 0.15); }
    body { background-color: #050505; color: #eee; }
    .form-container { background: #141414; border: 1px solid var(--border); border-radius: 16px; padding: 30px; max-width: 700px; margin: 30px auto; }
    .form-group { margin-bottom: 20px; }
    .form-group label { display: block; margin-bottom: 8px; color: #aaa; }
    .form-control { background: #000; color: #eee; width: 100%; padding: 12px; border-radius: 8px; border: 1px solid #333; }
    .form-control:focus { border-color: var(--ae-gold); outline: none; }
    .btn-submit { background: linear-gradient(90deg, var(--ae-gold), #b8860b); color: #000; padding: 12px 30px; border: none; border-radius: 8px; font-weight: bold; cursor: pointer; }
    .alert { padding: 15px; border-radius: 8px; margin-bottom: 20px; }
    .alert-danger { background: rgba(231, 76, 60, 0.2); color: #e74c3c; border: 1px solid #e74c3c; }
    .alert-warning { background: rgba(241, 196, 15, 0.2); color: #f1c40f; border: 1px solid #f1c40f; }
</style>

<div class="container inv-page">
    <div class="ph-hero" style="display:flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--border); padding-bottom: 20px; margin-bottom: 25px;">
        <h1 style="color:var(--ae-gold);"><i class="fa-solid fa-plus-circle"></i> إضافة مخزن جديد</h1>
        <a href="warehouses.php" style="color: #ccc; text-decoration: none;"><i class="fa-solid fa-arrow-left"></i> العودة إلى قائمة المخازن</a>
    </div>

    <div class="form-container">
        <?php echo $message; ?>
        <form method="POST">
            <?php echo app_csrf_input(); ?>
            <div class="form-group">
                <label for="name">اسم المخزن <span style="color:red;">*</span></label>
                <input type="text" id="name" name="name" class="form-control" required>
            </div>
            <div class="form-group">
                <label for="location">الموقع (اختياري)</label>
                <input type="text" id="location" name="location" class="form-control">
            </div>
            <div class="form-group">
                <label for="manager_id">المسؤول (اختياري)</label>
                <select id="manager_id" name="manager_id" class="form-control">
                    <option value="">-- غير معين --</option>
                    <?php if($users_result && $users_result->num_rows > 0): ?>
                        <?php while($user = $users_result->fetch_assoc()): ?>
                            <option value="<?php echo (int)$user['id']; ?>">
                                <?php echo htmlspecialchars((string)($user['full_name'] ?: $user['username'])); ?>
                                (<?php echo htmlspecialchars((string)$user['username']); ?>)
                            </option>
                        <?php endwhile; ?>
                    <?php endif; ?>
                </select>
            </div>
            <div class="form-group">
                 <label class="form-check-label">
                    <input type="checkbox" name="is_active" value="1" checked> 
                    مخزن نشط
                </label>
            </div>
            <button type="submit" class="btn-submit">حفظ المخزن</button>
        </form>
    </div>

</div>

<?php 
require 'footer.php'; 
ob_end_flush(); // Send the output buffer to the browser
?>
