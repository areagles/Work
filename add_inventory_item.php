<?php
ob_start(); // Start output buffering at the very beginning

// add_inventory_item.php - (Royal Phantom V1.3 - Robust Save/Error Handling)

error_reporting(E_ALL);

require 'auth.php';
require 'config.php'; // mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT) is now active
app_handle_lang_switch($conn);

$canCreateInventoryItem = app_user_can('inventory.items.create');
$message = '';

// --- Robust Form Processing Logic ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$canCreateInventoryItem) {
        $message = "<div class='alert alert-danger'>صلاحيات الوصول غير كافية.</div>";
    } else {
        if (!app_verify_csrf($_POST['_csrf_token'] ?? '')) {
            $message = "<div class='alert alert-danger'>انتهت صلاحية الجلسة، أعد تحميل الصفحة ثم حاول مرة أخرى.</div>";
        } else {
        // 1. Get and sanitize data
        $name = trim($_POST['name'] ?? '');
        $item_code = trim($_POST['item_code'] ?? '');
        $category = trim($_POST['category'] ?? '');
        $unit = trim($_POST['unit'] ?? '');
        $low_stock_threshold = !empty($_POST['low_stock_threshold']) ? intval($_POST['low_stock_threshold']) : 0;

        // 2. Validate data
        if (empty($name) || empty($item_code) || empty($unit)) {
            $message = "<div class='alert alert-warning'>الرجاء تعبئة جميع الحقول الإلزامية (*).</div>";
        } else {
            // 3. Try to execute database operation
            try {
                $sql = "INSERT INTO inventory_items (name, item_code, category, unit, low_stock_threshold) VALUES (?, ?, ?, ?, ?)";
                $stmt = $conn->prepare($sql);
                // No need to check for `$stmt` being false, an exception will be thrown on failure.
                $stmt->bind_param('ssssi', $name, $item_code, $category, $unit, $low_stock_threshold);
                $stmt->execute();

                // If execution is successful, redirect.
                header("Location: inventory.php?msg=item_added");
                exit;

            } catch (mysqli_sql_exception $e) {
                // 4. Catch and handle any database error
                if ($e->getCode() == 1062) { // 1062 = Duplicate entry
                    $message = "<div class='alert alert-danger'>خطأ: كود المنتج \"" . htmlspecialchars($item_code) . "\" موجود بالفعل.</div>";
                } else {
                    // For any other database error, show a generic message and log the real error
                    error_log("SQL Error in add_inventory_item.php: " . $e->getMessage());
                    $message = "<div class='alert alert-danger'>حدث خطأ غير متوقع في قاعدة البيانات. الرجاء المحاولة مرة أخرى.</div>";
                }
            }
        }
        }
    }
}

// --- Start HTML Output ---
require 'header.php';
echo '<link rel="stylesheet" href="assets/css/inventory-theme.css?v=20260311-1">';

// Check for page view permission
if (!$canCreateInventoryItem) {
    echo "<div class='container'><h1><i class='fa-solid fa-lock'></i> صلاحيات الوصول غير كافية</h1></div>";
    require 'footer.php';
    exit;
}

?>

<style>
    /* Styles remain the same */
    :root { --ae-gold: #d4af37; --border: rgba(212, 175, 55, 0.15); }
    body { background-color: #050505; color: #eee; }
    .form-container { background: #141414; border: 1px solid var(--border); border-radius: 16px; padding: 30px; max-width: 700px; margin: 30px auto; }
    .form-group { margin-bottom: 20px; }
    .form-group label { display: block; margin-bottom: 8px; color: #aaa; }
    .form-control { background: #000; color: #eee; width: 100%; padding: 12px; border-radius: 8px; border: 1px solid #333; }
    .btn-submit { background: linear-gradient(90deg, var(--ae-gold), #b8860b); color: #000; padding: 12px 30px; border: none; border-radius: 8px; font-weight: bold; cursor:pointer; }
    .alert { padding: 15px; border-radius: 8px; margin-bottom: 20px; text-align:center; }
    .alert-danger { background: rgba(231, 76, 60, 0.2); color: #e74c3c; border: 1px solid #e74c3c; }
    .alert-warning { background: rgba(241, 196, 15, 0.2); color: #f1c40f; border: 1px solid #f1c40f; }
</style>

<div class="container inv-page">
    <div class="ph-hero" style="display:flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--border); padding-bottom: 20px; margin-bottom: 25px;">
        <h1 style="color:var(--ae-gold);"><i class="fa-solid fa-plus-circle"></i> إضافة منتج جديد</h1>
        <a href="inventory.php" style="color: #ccc; text-decoration: none;"><i class="fa-solid fa-arrow-left"></i> العودة للمخزون</a>
    </div>
    <div class="form-container">
        <?php 
        // Display the message if it's not empty
        if (!empty($message)) {
            echo $message;
        }
        ?>
        <form method="POST" action="add_inventory_item.php"> 
            <?php echo app_csrf_input(); ?>
            <div class="form-group">
                <label for="name">اسم المنتج <span style="color:red;">*</span></label>
                <input type="text" id="name" name="name" class="form-control" required value="<?php echo htmlspecialchars($_POST['name'] ?? ''); ?>">
            </div>
            <div class="form-group">
                <label for="item_code">كود المنتج (SKU) <span style="color:red;">*</span></label>
                <input type="text" id="item_code" name="item_code" class="form-control" required value="<?php echo htmlspecialchars($_POST['item_code'] ?? ''); ?>">
            </div>
            <div class="form-group">
                <label for="category">الفئة</label>
                <input type="text" id="category" name="category" class="form-control" value="<?php echo htmlspecialchars($_POST['category'] ?? ''); ?>">
            </div>
            <div class="form-group">
                <label for="unit">وحدة القياس (مثال: قطعة، كجم، متر) <span style="color:red;">*</span></label>
                <input type="text" id="unit" name="unit" class="form-control" required value="<?php echo htmlspecialchars($_POST['unit'] ?? ''); ?>">
            </div>
            <div class="form-group">
                <label for="low_stock_threshold">حد المخزون المنخفض</label>
                <input type="number" id="low_stock_threshold" name="low_stock_threshold" class="form-control" value="<?php echo htmlspecialchars($_POST['low_stock_threshold'] ?? 0); ?>">
            </div>
            <button type="submit" class="btn-submit">حفظ المنتج</button>
        </form>
    </div>
</div>

<?php 
require 'footer.php'; 
ob_end_flush();
?>
