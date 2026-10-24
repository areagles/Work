<?php
ob_start(); // Start output buffering at the very beginning

// edit_warehouse.php - (Royal Phantom V1.1 - Header Fix)

error_reporting(E_ALL);

require 'auth.php';
require 'config.php';
app_handle_lang_switch($conn);

$canUpdateWarehouse = app_user_can('inventory.warehouses.update');
$canDeleteWarehouse = app_user_can('inventory.warehouses.delete');
$message = '';
$warehouse_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

// Redirect if no ID is provided
if ($warehouse_id === 0) {
    header("Location: warehouses.php");
    exit;
}

// Handle form submission (POST request)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$canUpdateWarehouse) {
        // Unauthorized attempt to modify data
        $message = "<div class='alert alert-danger'>صلاحيات الوصول غير كافية.</div>";
    } else {
        if (!app_verify_csrf($_POST['_csrf_token'] ?? '')) {
            $message = "<div class='alert alert-danger'>انتهت صلاحية الجلسة، أعد المحاولة.</div>";
        } else {
        $name = trim((string)($_POST['name'] ?? ''));
        $location = trim((string)($_POST['location'] ?? ''));
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        $manager_id = !empty($_POST['manager_id']) ? intval($_POST['manager_id']) : null;
        $wh_id_post = intval($_POST['warehouse_id']);

        if (!empty($name) && $wh_id_post > 0) {
            $sql = "UPDATE warehouses SET name = ?, location = ?, is_active = ?, manager_id = ? WHERE id = ?";
            $stmt = $conn->prepare($sql);
            if ($stmt) {
                $stmt->bind_param('ssiii', $name, $location, $is_active, $manager_id, $wh_id_post);
                if ($stmt->execute()) {
                    header("Location: warehouses.php?msg=updated");
                    exit;
                } else {
                    $message = "<div class='alert alert-danger'>خطأ في التحديث: " . $stmt->error . "</div>";
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

// Include header after potential redirects
require 'header.php';
echo '<link rel="stylesheet" href="assets/css/inventory-theme.css?v=20260311-1">';

// Check for access permissions
if (!$canUpdateWarehouse) {
    echo "<div class='container'><h1><i class='fa-solid fa-lock'></i> صلاحيات الوصول غير كافية</h1></div>";
    require 'footer.php';
    exit;
}

// Fetch warehouse data for the form
$stmt = $conn->prepare("SELECT * FROM warehouses WHERE id = ?");
$stmt->bind_param('i', $warehouse_id);
$stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows === 1) {
    $warehouse = $result->fetch_assoc();
} else {
    echo "<div class='container'><h1>المخزن غير موجود</h1><p>لم يتم العثور على المخزن المطلوب.</p> <a href='warehouses.php'>العودة إلى القائمة</a></div>";
    require 'footer.php';
    exit;
}
$stmt->close();

// Fetch users for the manager dropdown
$users_result = $conn->query("SELECT id, full_name, username FROM users WHERE role IN ('admin', 'manager', 'production') ORDER BY full_name ASC");

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
    .btn-delete { display:inline-flex; align-items:center; gap:8px; margin-inline-start:10px; padding:12px 18px; border-radius:8px; border:1px solid rgba(231,76,60,0.6); background: rgba(231,76,60,0.14); color:#ffb7af; text-decoration:none; font-weight:700; }
    .btn-delete:hover { border-color:#e74c3c; color:#ffd8d2; }
    .alert { padding: 15px; border-radius: 8px; margin-bottom: 20px; }
    .alert-danger { background: rgba(231, 76, 60, 0.2); color: #e74c3c; border: 1px solid #e74c3c; }
    .alert-warning { background: rgba(241, 196, 15, 0.2); color: #f1c40f; border: 1px solid #f1c40f; }
</style>

<div class="container inv-page">
    <div class="ph-hero" style="display:flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--border); padding-bottom: 20px; margin-bottom: 25px;">
        <h1 style="color:var(--ae-gold);"><i class="fa-solid fa-pen-to-square"></i> تعديل المخزن</h1>
        <a href="warehouses.php" style="color: #ccc; text-decoration: none;"><i class="fa-solid fa-arrow-left"></i> العودة إلى قائمة المخازن</a>
    </div>

    <div class="form-container">
        <?php echo $message; ?>
        <form method="POST">
            <?php echo app_csrf_input(); ?>
            <input type="hidden" name="warehouse_id" value="<?php echo (int)$warehouse['id']; ?>">
            <div class="form-group">
                <label for="name">اسم المخزن <span style="color:red;">*</span></label>
                <input type="text" id="name" name="name" class="form-control" value="<?php echo htmlspecialchars($warehouse['name']); ?>" required>
            </div>
            <div class="form-group">
                <label for="location">الموقع (اختياري)</label>
                <input type="text" id="location" name="location" class="form-control" value="<?php echo htmlspecialchars($warehouse['location']); ?>">
            </div>
            <div class="form-group">
                <label for="manager_id">المسؤول (اختياري)</label>
                <select id="manager_id" name="manager_id" class="form-control">
                    <option value="">-- غير معين --</option>
                    <?php if($users_result && $users_result->num_rows > 0): ?>
                        <?php while($user = $users_result->fetch_assoc()): ?>
                            <option value="<?php echo (int)$user['id']; ?>" <?php echo ((int)$warehouse['manager_id'] === (int)$user['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars((string)($user['full_name'] ?: $user['username'])); ?>
                                (<?php echo htmlspecialchars((string)$user['username']); ?>)
                            </option>
                        <?php endwhile; ?>
                    <?php endif; ?>
                </select>
            </div>
            <div class="form-group">
                 <label class="form-check-label">
                    <input type="checkbox" name="is_active" value="1" <?php echo $warehouse['is_active'] ? 'checked' : ''; ?>> 
                    مخزن نشط
                </label>
            </div>
            <button type="submit" class="btn-submit">حفظ التعديلات</button>
            <?php if ($canDeleteWarehouse): ?>
                <a class="btn-delete" href="warehouses.php?del=<?php echo (int)$warehouse['id']; ?>&amp;_token=<?php echo urlencode(app_csrf_token()); ?>" onclick="return confirm('تحذير: سيتم حذف المخزن إذا كان رصيده صفراً. هل أنت متأكد؟');">
                    <i class="fa-solid fa-trash-can"></i> حذف المخزن
                </a>
            <?php endif; ?>
        </form>
    </div>
</div>

<?php 
require 'footer.php'; 
ob_end_flush(); // Send the output buffer to the browser
?>
