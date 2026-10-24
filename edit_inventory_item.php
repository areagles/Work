<?php
ob_start(); // Start output buffering at the very beginning

// edit_inventory_item.php - (Royal Phantom V1.3 - Robust Save/Error Handling)

error_reporting(E_ALL);

require 'auth.php';
require 'config.php'; // mysqli_report is active
app_handle_lang_switch($conn);

$canEditInventoryItem = app_user_can('inventory.items.update');
$canDeleteInventoryItem = app_user_can('inventory.items.delete');
$message = '';
$item_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

// Redirect if no valid ID is provided
if ($item_id <= 0) {
    header("Location: inventory.php");
    exit;
}

// This will hold the item data to display in the form. 
// It could be the original data from the DB, or the submitted data from a failed POST attempt.
$item_data_for_form = [];

// --- Robust Form Processing Logic ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$canEditInventoryItem) {
        $message = "<div class='alert alert-danger'>صلاحيات الوصول غير كافية.</div>";
        $item_data_for_form = $_POST; // Keep user's entered data on permission error
    } else {
        if (!app_verify_csrf($_POST['_csrf_token'] ?? '')) {
            $message = "<div class='alert alert-danger'>انتهت صلاحية الجلسة، أعد تحميل الصفحة ثم حاول مرة أخرى.</div>";
            $item_data_for_form = $_POST;
        } else {
        $name = trim($_POST['name'] ?? '');
        $item_code = trim($_POST['item_code'] ?? '');
        $category = trim($_POST['category'] ?? '');
        $unit = trim($_POST['unit'] ?? '');
        $low_stock_threshold = !empty($_POST['low_stock_threshold']) ? intval($_POST['low_stock_threshold']) : 0;

        if (empty($name) || empty($item_code) || empty($unit)) {
            $message = "<div class='alert alert-warning'>الرجاء تعبئة جميع الحقول الإلزامية (*).</div>";
            $item_data_for_form = $_POST; // Keep user's entered data on validation error
        } else {
            try {
                $sql = "UPDATE inventory_items SET name = ?, item_code = ?, category = ?, unit = ?, low_stock_threshold = ? WHERE id = ?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param('ssssii', $name, $item_code, $category, $unit, $low_stock_threshold, $item_id);
                $stmt->execute();

                header("Location: inventory.php?msg=item_updated");
                exit;

            } catch (mysqli_sql_exception $e) {
                if ($e->getCode() == 1062) {
                    $message = "<div class='alert alert-danger'>خطأ: كود المنتج \"" . htmlspecialchars($item_code) . "\" موجود بالفعل.</div>";
                } else {
                    error_log("SQL Error in edit_inventory_item.php: " . $e->getMessage());
                    $message = "<div class='alert alert-danger'>حدث خطأ غير متوقع في قاعدة البيانات.</div>";
                }
                $item_data_for_form = $_POST; // IMPORTANT: On error, use the submitted data to repopulate the form
            }
        }
        }
    }
} else {
    // --- This is a GET request, so fetch the original data from the DB ---
    try {
        $stmt = $conn->prepare("SELECT * FROM inventory_items WHERE id = ?");
        $stmt->bind_param('i', $item_id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows === 1) {
            $item_data_for_form = $result->fetch_assoc();
        } else {
            // If no item found, redirect with an error
            header("Location: inventory.php?err=not_found");
            exit;
        }
    } catch (mysqli_sql_exception $e) {
        error_log("SQL Error fetching item in edit_inventory_item.php: " . $e->getMessage());
        die("خطأ أثناء جلب بيانات المنتج.");
    }
}

// --- Start HTML Output ---
require 'header.php';
echo '<link rel="stylesheet" href="assets/css/inventory-theme.css?v=20260311-1">';

if (!$canEditInventoryItem) {
    echo "<div class='container'><h1><i class='fa-solid fa-lock'></i> صلاحيات الوصول غير كافية</h1></div>";
    require 'footer.php';
    exit;
}

?>

<style>
    /* Styles are unchanged */
    :root { --ae-gold: #d4af37; --border: rgba(212, 175, 55, 0.15); }
    body { background-color: #050505; color: #eee; }
    .form-container { background: #141414; border: 1px solid var(--border); border-radius: 16px; padding: 30px; max-width: 800px; margin: 30px auto; }
    .form-group { margin-bottom: 20px; }
    .form-control { background: #000; color: #eee; width: 100%; padding: 12px; border-radius: 8px; border: 1px solid #333; }
    .btn-submit { background: linear-gradient(90deg, var(--ae-gold), #b8860b); color: #000; padding: 12px 30px; border: none; border-radius: 8px; font-weight: bold; cursor: pointer; }
    .btn-delete { display: inline-block; margin-inline-start: 12px; background: rgba(192, 57, 43, 0.18); color: #ffb4ac; border: 1px solid rgba(192, 57, 43, 0.55); padding: 12px 18px; border-radius: 8px; font-weight: 700; text-decoration: none; }
    .btn-delete:hover { background: rgba(192, 57, 43, 0.28); }
    .alert { padding: 15px; border-radius: 8px; margin-bottom: 20px; text-align: center; }
    .alert-danger { background: rgba(231, 76, 60, 0.2); color: #e74c3c; border: 1px solid #e74c3c; }
    .alert-warning { background: rgba(241, 196, 15, 0.2); color: #f1c40f; border: 1px solid #f1c40f; }
</style>

<div class="container inv-page">
    <div class="ph-hero" style="display:flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--border); padding-bottom: 20px; margin-bottom: 25px;">
        <h1 style="color:var(--ae-gold);"><i class="fa-solid fa-pen-to-square"></i> تعديل بيانات المنتج</h1>
        <a href="inventory.php" style="color: #ccc; text-decoration: none;"><i class="fa-solid fa-arrow-left"></i> العودة للمخزون</a>
    </div>

    <div class="form-container">
        <?php if (!empty($message)) { echo $message; } ?>
        <form method="POST" action="edit_inventory_item.php?id=<?php echo $item_id; ?>">
            <?php echo app_csrf_input(); ?>
            <div class="form-group">
                <label for="name">اسم المنتج <span style="color:red;">*</span></label>
                <input type="text" id="name" name="name" class="form-control" value="<?php echo htmlspecialchars($item_data_for_form['name'] ?? ''); ?>" required>
            </div>
            <div class="form-group">
                <label for="item_code">كود المنتج (SKU) <span style="color:red;">*</span></label>
                <input type="text" id="item_code" name="item_code" class="form-control" value="<?php echo htmlspecialchars($item_data_for_form['item_code'] ?? ''); ?>" required>
            </div>
            <div class="form-group">
                <label for="category">الفئة</label>
                <input type="text" id="category" name="category" class="form-control" value="<?php echo htmlspecialchars($item_data_for_form['category'] ?? ''); ?>">
            </div>
            <div class="form-group">
                <label for="unit">وحدة القياس <span style="color:red;">*</span></label>
                <input type="text" id="unit" name="unit" class="form-control" value="<?php echo htmlspecialchars($item_data_for_form['unit'] ?? ''); ?>" required>
            </div>
            <div class="form-group">
                <label for="low_stock_threshold">حد المخزون المنخفض</label>
                <input type="number" id="low_stock_threshold" name="low_stock_threshold" class="form-control" value="<?php echo htmlspecialchars($item_data_for_form['low_stock_threshold'] ?? 0); ?>">
            </div>
            <button type="submit" class="btn-submit">حفظ التعديلات</button>
            <?php if ($canDeleteInventoryItem): ?>
                <a class="btn-delete" href="inventory.php?del=<?php echo (int)$item_id; ?>&amp;_token=<?php echo urlencode(app_csrf_token()); ?>" onclick="return confirm('تحذير: سيتم حذف المنتج وكل سجلات المخزون المرتبطة به. هل أنت متأكد؟');">
                    <i class="fa-solid fa-trash-can"></i> حذف المنتج
                </a>
            <?php endif; ?>
        </form>
    </div>
</div>

<?php 
require 'footer.php'; 
ob_end_flush();
?>
