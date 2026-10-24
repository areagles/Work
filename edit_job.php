<?php
// edit_job.php - (V4.1 - CRITICAL FIX & Inventory Integration)
ob_start();
error_reporting(E_ALL);

require 'auth.php';
require 'config.php';
app_handle_lang_switch($conn);
$is_en = app_lang_is('en');
$tr = static function (string $ar, string $en) use ($is_en): string {
    return $is_en ? $en : $ar;
};

// 1. التحقق من الصلاحيات والرابط
if(in_array($_SESSION['role'], ['driver', 'worker'])) { header("Location: dashboard.php?error=unauthorized"); exit; }
if(!isset($_GET['id']) || empty($_GET['id'])) { header("Location: dashboard.php"); exit; }
$id = intval($_GET['id']);
app_require_job_access($conn, $id, false);
$currentJobRow = $conn->execute_query("SELECT id, job_type, current_stage FROM job_orders WHERE id = ?", [$id])->fetch_assoc();
if (!$currentJobRow) {
    header("Location: dashboard.php?error=not_found");
    exit;
}

// 2. معالجة الحفظ (PRG Pattern + Prepared Statements + Transactions)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_job'])) {
    app_require_job_access($conn, $id, true);
    $conn->begin_transaction();
    try {
        $lockedStage = (string)($currentJobRow['current_stage'] ?? '');
        if ($lockedStage === '') {
            throw new RuntimeException('invalid_stage');
        }

        // A. Update main job order details
        $stmt = $conn->prepare("UPDATE job_orders SET job_name=?, client_id=?, delivery_date=?, price=?, paid=?, quantity=?, notes=?, job_details=?, current_stage=? WHERE id=?");
        $stmt->bind_param("sisddisssi", $_POST['job_name'], $_POST['client_id'], $_POST['delivery_date'], $_POST['price'], $_POST['paid'], $_POST['quantity'], $_POST['notes'], $_POST['job_details'], $lockedStage, $id);
        $stmt->execute();

        // B. Process materials through the current inventory subsystem.
        $oldMaterialsQ = $conn->execute_query(
            "SELECT item_id, warehouse_id, ABS(quantity) AS qty_used
             FROM inventory_transactions
             WHERE reference_type = 'job_material' AND related_order_id = ?",
            [$id]
        );
        foreach ($oldMaterialsQ as $item) {
            $itemId = (int)($item['item_id'] ?? 0);
            $warehouseId = (int)($item['warehouse_id'] ?? 0);
            $qtyUsed = (float)($item['qty_used'] ?? 0);
            if ($itemId <= 0 || $warehouseId <= 0 || $qtyUsed <= 0) {
                continue;
            }
            $stmtRestore = $conn->prepare("
                INSERT INTO inventory_stock (item_id, warehouse_id, quantity)
                VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE quantity = quantity + VALUES(quantity)
            ");
            $stmtRestore->bind_param('iid', $itemId, $warehouseId, $qtyUsed);
            $stmtRestore->execute();
            $stmtRestore->close();
        }
        $conn->execute_query(
            "DELETE FROM inventory_transactions WHERE reference_type = 'job_material' AND related_order_id = ?",
            [$id]
        );

        if (!empty($_POST['materials']) && is_array($_POST['materials'])) {
            $stockCheckStmt = $conn->prepare("SELECT quantity FROM inventory_stock WHERE item_id = ? AND warehouse_id = ? FOR UPDATE");
            $itemInfoStmt = $conn->prepare("SELECT name, item_code, IFNULL(avg_unit_cost, 0) AS avg_unit_cost FROM inventory_items WHERE id = ? LIMIT 1");
            $stockUpdateStmt = $conn->prepare("UPDATE inventory_stock SET quantity = quantity - ? WHERE item_id = ? AND warehouse_id = ?");
            $transInsertStmt = $conn->prepare("
                INSERT INTO inventory_transactions (
                    item_id, warehouse_id, user_id, transaction_type, quantity, related_order_id, notes,
                    unit_cost, total_cost, reference_type, reference_id, stage_key
                ) VALUES (?, ?, ?, 'out', ?, ?, ?, ?, ?, 'job_material', ?, ?)
            ");

            $userId = (int)($_SESSION['user_id'] ?? 0);
            $stageKey = $lockedStage;

            foreach ($_POST['materials'] as $mat) {
                $itemId = (int)($mat['product_id'] ?? 0);
                $warehouseId = (int)($mat['warehouse_id'] ?? 0);
                $qty = (float)($mat['quantity'] ?? 0);
                if ($itemId <= 0 || $warehouseId <= 0 || $qty <= 0) {
                    continue;
                }

                $stockCheckStmt->bind_param('ii', $itemId, $warehouseId);
                $stockCheckStmt->execute();
                $stockRow = $stockCheckStmt->get_result()->fetch_assoc();
                $available = (float)($stockRow['quantity'] ?? 0);
                if ($available < $qty) {
                    throw new RuntimeException('insufficient_stock');
                }

                $itemInfoStmt->bind_param('i', $itemId);
                $itemInfoStmt->execute();
                $itemRow = $itemInfoStmt->get_result()->fetch_assoc();
                if (!$itemRow) {
                    throw new RuntimeException('item_not_found');
                }

                $unitCost = (float)($itemRow['avg_unit_cost'] ?? 0);
                $totalCost = round($qty * $unitCost, 2);
                $signedQty = -$qty;
                $note = "صرف خامة للعملية #{$id}";
                if ($stageKey !== '') {
                    $note .= " [{$stageKey}]";
                }

                $stockUpdateStmt->bind_param('dii', $qty, $itemId, $warehouseId);
                $stockUpdateStmt->execute();

                $transInsertStmt->bind_param(
                    'iiidisddis',
                    $itemId,
                    $warehouseId,
                    $userId,
                    $signedQty,
                    $id,
                    $note,
                    $unitCost,
                    $totalCost,
                    $id,
                    $stageKey
                );
                $transInsertStmt->execute();
            }

            $stockCheckStmt->close();
            $itemInfoStmt->close();
            $stockUpdateStmt->close();
            $transInsertStmt->close();
        }

        $conn->commit();
        header("Location: job_details.php?id=$id&success=1");
        exit;
    } catch (Exception $e) {
        $conn->rollback();
        $error_msg = urlencode("An error occurred: " . $e->getMessage());
        header("Location: edit_job.php?id=$id&error=".$error_msg);
        exit;
    }
}

// 3. جلب كل البيانات اللازمة للعرض
$job = $conn->execute_query("SELECT * FROM job_orders WHERE id = ?", [$id])->fetch_assoc();
if (!$job) die("Order not found");

$clients = $conn->query("SELECT id, name FROM clients ORDER BY name ASC")->fetch_all(MYSQLI_ASSOC);
$products = $conn->query("
    SELECT
        i.id,
        i.name,
        i.item_code AS sku,
        s.warehouse_id,
        w.name AS warehouse_name,
        s.quantity AS stock_quantity
    FROM inventory_items i
    JOIN inventory_stock s ON i.id = s.item_id
    JOIN warehouses w ON s.warehouse_id = w.id
    WHERE s.quantity >= 0
    ORDER BY i.name, w.name
")->fetch_all(MYSQLI_ASSOC);
$used_materials = $conn->execute_query("
    SELECT
        t.item_id AS product_id,
        t.warehouse_id,
        ABS(t.quantity) AS quantity_used,
        i.name,
        i.item_code AS sku,
        w.name AS warehouse_name
    FROM inventory_transactions t
    JOIN inventory_items i ON t.item_id = i.id
    JOIN warehouses w ON t.warehouse_id = w.id
    WHERE t.reference_type = 'job_material' AND t.related_order_id = ?
    ORDER BY t.id DESC
", [$id])->fetch_all(MYSQLI_ASSOC);
$stageLabelFallbacks = [
    'briefing'    => 'أمر التشغيل',
    'design'      => 'التصميم',
    'client_rev'  => 'مراجعة العميل',
    'materials'   => 'الخامات',
    'pre_press'   => 'التجهيز الفني',
    'printing'    => 'الطباعة / الإنتاج',
    'finishing'   => 'التشطيب',
    'die_cutting' => 'التكسير',
    'gluing'      => 'اللصق',
    'cylinders'   => 'السلندرات',
    'extrusion'   => 'السحب',
    'cutting'     => 'القص',
    'ui_design'   => 'تصميم الواجهة',
    'development' => 'البرمجة',
    'testing'     => 'الاختبار',
    'launch'      => 'الإطلاق',
    'idea_review' => 'مراجعة الأفكار',
    'content_writing' => 'كتابة المحتوى',
    'content_review'  => 'مراجعة المحتوى',
    'designing'       => 'التصميم',
    'design_review'   => 'مراجعة التصميم',
    'publishing'      => 'النشر',
    'handover'        => 'التسليم',
    'delivery'        => 'التسليم',
    'accounting'      => 'الحسابات',
    'completed'       => 'منتهي',
];
$jobWorkflowForEdit = app_operation_workflow($conn, (string)($job['job_type'] ?? ''), $stageLabelFallbacks);
$currentStageLabel = (string)($stageLabelFallbacks[$job['current_stage']] ?? $job['current_stage']);
if (isset($jobWorkflowForEdit[$job['current_stage']]['label'])) {
    $currentStageLabel = (string)$jobWorkflowForEdit[$job['current_stage']]['label'];
}

require 'header.php';
?>

<style>
    :root { --gold: #d4af37; --bg: #121212; --panel: #1e1e1e; --input-bg: #0a0a0a; --border: #333; --danger: #e74c3c; --success: #2ecc71; }
    .edit-shell { max-width: 1180px; margin: 30px auto; padding: 0 15px; display:grid; gap:18px; }
    .edit-hero {
        position:relative;
        overflow:hidden;
        background:
            linear-gradient(180deg, rgba(255,255,255,0.06), rgba(255,255,255,0.02)),
            radial-gradient(circle at top left, rgba(212,175,55,0.12), transparent 34%),
            rgba(18,18,18,0.88);
        border:1px solid rgba(212,175,55,0.16);
        border-radius:24px;
        padding:24px;
        box-shadow:0 18px 38px rgba(0,0,0,0.24);
        backdrop-filter:blur(14px);
    }
    .edit-hero::after {
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
    .edit-eyebrow {
        display:inline-flex; align-items:center; gap:8px; padding:6px 12px; border-radius:999px;
        background:rgba(212,175,55,0.08); border:1px solid rgba(212,175,55,0.24); color:#f0d684;
        font-size:.76rem; font-weight:700; margin-bottom:14px;
    }
    .edit-title { margin:0; color:#f7f1dc; font-size:1.85rem; line-height:1.3; }
    .edit-subtitle { margin:10px 0 0; color:#a8abb1; line-height:1.8; max-width:760px; }
    .edit-kpis { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:12px; margin-top:18px; }
    .edit-kpi { border-radius:18px; border:1px solid rgba(255,255,255,0.08); background:rgba(255,255,255,0.035); padding:16px; min-height:96px; }
    .edit-kpi .label { color:#9ca0a8; font-size:.74rem; margin-bottom:8px; }
    .edit-kpi .value { color:#fff; font-size:1rem; font-weight:800; line-height:1.5; }
    .edit-card {
        background:
            linear-gradient(180deg, rgba(255,255,255,0.05), rgba(255,255,255,0.02)),
            rgba(18,18,18,0.78);
        border: 1px solid rgba(255,255,255,0.08);
        border-radius: 22px;
        padding: 24px;
        max-width: 100%;
        box-shadow: 0 14px 30px rgba(0,0,0,0.24);
        backdrop-filter: blur(14px);
    }
    .section-title { color: var(--gold); font-size: 1.1rem; border-bottom: 1px dashed var(--border); padding-bottom: 10px; margin: 25px 0 15px 0; display: flex; align-items: center; gap: 10px; }
    .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
    label { display: block; margin-bottom: 8px; font-size: 0.9rem; color: #aaa; }
    input, select, textarea { width: 100%; padding: 13px 14px; background: rgba(8,8,8,0.84); border: 1px solid rgba(255,255,255,0.1); color: #fff; border-radius: 14px; font-family: 'Cairo'; box-sizing: border-box; }
    .btn-save { background: linear-gradient(45deg, var(--gold), #b8860b); color: #000; border: none; padding: 15px 40px; border-radius: 16px; font-weight: bold; cursor: pointer; font-size: 1.1rem; width: 100%; margin-top: 30px; }
    .royal-alert.error { background: rgba(231, 76, 60, 0.2); color: #e74c3c; border: 1px solid #e74c3c; padding: 15px; border-radius: 5px; margin-bottom: 20px; text-align: center;}
    #materials_list { list-style: none; padding: 0; margin-top: 15px; }
    .material-item { display: grid; grid-template-columns: 1fr 100px 80px; gap: 10px; align-items: center; background: #111; padding: 10px; border-radius: 6px; margin-bottom: 8px; font-size: 0.9rem; }
    .material-item .remove-material { color: var(--danger); cursor: pointer; text-align: center; }
    .add-material-form { display: grid; grid-template-columns: 1fr 1fr 120px 100px; gap: 10px; align-items: flex-end; margin-top: 20px; padding: 15px; background: #000; border-radius: 8px; }
    .btn-add-material { background: var(--success); color: #fff; border:none; padding: 12px; border-radius: 6px; font-weight: bold; cursor: pointer; }
    @media (max-width: 900px) {
        .edit-kpis, .grid-2, .add-material-form { grid-template-columns: 1fr; }
    }
</style>

<div class="edit-shell">
    <section class="edit-hero">
        <div class="edit-eyebrow"><?php echo app_h($tr('تعديل العملية', 'Edit operation')); ?></div>
        <h1 class="edit-title"><?php echo app_h($tr('تحديث بيانات أمر الشغل', 'Update work order details')); ?></h1>
        <p class="edit-subtitle"><?php echo app_h($tr('هذه الشاشة مخصصة لتعديل البيانات الفنية والمالية ومواد المخزون مع الحفاظ على المرحلة الحالية دون تغيير.', 'This screen is intended to update technical, financial, and inventory data while preserving the current stage.')); ?></p>
        <div class="edit-kpis">
            <div class="edit-kpi">
                <div class="label"><?php echo app_h($tr('رقم العملية', 'Operation number')); ?></div>
                <div class="value">#<?php echo (int)$job['id']; ?></div>
            </div>
            <div class="edit-kpi">
                <div class="label"><?php echo app_h($tr('المرحلة الحالية', 'Current stage')); ?></div>
                <div class="value"><?php echo htmlspecialchars($currentStageLabel); ?></div>
            </div>
            <div class="edit-kpi">
                <div class="label"><?php echo app_h($tr('نوع العملية', 'Operation type')); ?></div>
                <div class="value"><?php echo htmlspecialchars((string)($job['job_type'] ?? '-')); ?></div>
            </div>
        </div>
    </section>

<div class="edit-card">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
        <h2 style="margin:0; color:#fff;"><?php echo app_h($tr('نموذج التعديل', 'Edit form')); ?> #<?php echo $job['id']; ?></h2>
        <a href="job_details.php?id=<?php echo $id; ?>" style="color:#aaa; text-decoration:none;"><?php echo app_h($tr('إلغاء وعودة', 'Cancel and return')); ?></a>
    </div>
    <?php if(isset($_GET['error'])) echo "<div class='royal-alert error'>".htmlspecialchars(urldecode($_GET['error']))."</div>"; ?>

    <form method="POST">
        <div class="section-title"><i class="fa-solid fa-file-signature"></i> البيانات الأساسية</div>
        <div class="grid-2">
            <div><label>اسم العملية</label><input type="text" name="job_name" value="<?php echo htmlspecialchars($job['job_name']); ?>" required></div>
            <div><label>العميل</label><select name="client_id">
                <?php foreach($clients as $c) { $sel = ($c['id'] == $job['client_id']) ? 'selected' : ''; echo "<option value='{$c['id']}' $sel>{$c['name']}</option>"; } ?>
            </select></div>
        </div>
        <div class="grid-2" style="margin-top:15px;">
            <div><label>تاريخ التسليم</label><input type="date" name="delivery_date" value="<?php echo $job['delivery_date']; ?>" required></div>
            <div><label>الكمية المطلوبة</label><input type="number" name="quantity" value="<?php echo $job['quantity']; ?>"></div>
        </div>

        <div class="section-title"><i class="fa-solid fa-microchip"></i> التفاصيل الفنية</div>
        <textarea name="job_details" rows="8" style="font-family:monospace; line-height:1.6;"><?php echo htmlspecialchars($job['job_details']); ?></textarea>
        
        <div class="section-title"><i class="fa-solid fa-boxes-stacked"></i> المواد المستخدمة من المخزون</div>
        <div id="materials_container">
            <ul id="materials_list"></ul>
        </div>
        <div class="add-material-form">
            <div><label>اختيار المنتج</label><select id="new_material_product"><option value="">-- اختر منتج --</option></select></div>
            <div><label>المخزن</label><select id="new_material_warehouse"><option value="">-- اختر منتج أولاً --</option></select></div>
            <div><label>الكمية</label><input type="number" id="new_material_quantity" step="0.01" placeholder="0.00"></div>
            <button type="button" id="add_material_btn" class="btn-add-material"><i class="fa fa-plus"></i> إضافة</button>
        </div>

        <div class="section-title"><i class="fa-solid fa-coins"></i> الملف المالي</div>
        <div class="grid-2">
            <div><label>إجمالي السعر</label><input type="number" step="0.01" name="price" style="color:var(--success);" value="<?php echo $job['price']; ?>"></div>
            <div><label>المدفوع</label><input type="number" step="0.01" name="paid" style="color:var(--success);" value="<?php echo $job['paid']; ?>"></div>
        </div>

        <div class="section-title"><i class="fa-solid fa-sliders"></i> التحكم والمرحلة</div>
        <div>
            <label>المرحلة الحالية</label>
            <div style="background:#141414;border:1px solid #e67e22;border-radius:10px;padding:14px 16px;color:#fff;font-weight:700;">
                <?php echo htmlspecialchars($currentStageLabel); ?>
            </div>
            <div style="margin-top:8px;color:#9aa0a6;font-size:.85rem;">تغيير المرحلة يتم من شاشة متابعة العملية فقط للحفاظ على تسلسل التشغيل.</div>
        </div>
        <div style="margin-top:15px;"><label>ملاحظات إدارية</label><textarea name="notes" rows="3"><?php echo htmlspecialchars($job['notes']); ?></textarea></div>

        <button type="submit" name="update_job" class="btn-save"><?php echo app_h($tr('حفظ كل التعديلات', 'Save all changes')); ?></button>
    </form>
</div>
</div>

<script>
// Data passed from PHP
const allProducts = <?php echo json_encode(array_values($products), JSON_UNESCAPED_UNICODE); ?>;
const usedMaterials = <?php echo json_encode($used_materials, JSON_UNESCAPED_UNICODE); ?>;
let materialIndex = 0;

// DOM Elements
const productSelect = document.getElementById('new_material_product');
const warehouseSelect = document.getElementById('new_material_warehouse');

// --- Functions to manage material list --- //
function addMaterialToList(material) {
    const list = document.getElementById('materials_list');
    const item = document.createElement('li');
    item.className = 'material-item';
    item.innerHTML = `
        <span><strong>${material.productName}</strong><br><small>من: ${material.warehouseName}</small></span>
        <span>${material.quantity}</span>
        <span class="remove-material" onclick="this.parentElement.remove()">✖</span>
        <input type="hidden" name="materials[${materialIndex}][product_id]" value="${material.productId}">
        <input type="hidden" name="materials[${materialIndex}][warehouse_id]" value="${material.warehouseId}">
        <input type="hidden" name="materials[${materialIndex}][quantity]" value="${material.quantity}">
    `;
    list.appendChild(item);
    materialIndex++;
}

function updateWarehouseOptions() {
    const productId = productSelect.value;
    warehouseSelect.innerHTML = '<option value="">-- اختر مخزن --</option>';
    if (productId) {
        const available = allProducts.filter(p => p.id == productId);
        available.forEach(item => {
            warehouseSelect.innerHTML += `<option value="${item.warehouse_id}">${item.warehouse_name} (المتاح: ${item.stock_quantity})</option>`;
        });
    }
}

function populateProductSelect() {
    const uniqueProducts = [...new Map(allProducts.map(item => [item['id'], item])).values()];
    uniqueProducts.forEach(p => {
        productSelect.innerHTML += `<option value="${p.id}">${p.name} (${p.sku})</option>`;
    });
}

// --- Event Listeners --- //
productSelect.addEventListener('change', updateWarehouseOptions);

document.getElementById('add_material_btn').addEventListener('click', function(){
    const productId = productSelect.value;
    const warehouseId = warehouseSelect.value;
    const quantity = parseFloat(document.getElementById('new_material_quantity').value);
    
    if (!productId || !warehouseId || !quantity || quantity <= 0) {
        alert('يرجى اختيار منتج ومخزن وكمية صحيحة.'); return;
    }

    const product = allProducts.find(p => p.id == productId);
    const warehouse = allProducts.find(p => p.id == productId && p.warehouse_id == warehouseId);

    if (quantity > parseFloat(warehouse.stock_quantity)) {
        if (!confirm('تحذير: الكمية المطلوبة أكبر من المتاح في المخزن. هل تريد المتابعة على أي حال؟')) {
            return;
        }
    }

    addMaterialToList({
        productId: productId,
        warehouseId: warehouseId,
        quantity: quantity,
        productName: product.name,
        warehouseName: warehouse.warehouse_name
    });
    document.getElementById('new_material_quantity').value = '';
});

// --- Initial Population --- //
document.addEventListener('DOMContentLoaded', function() {
    populateProductSelect();
    usedMaterials.forEach(mat => {
        addMaterialToList({
            productId: mat.product_id,
            warehouseId: mat.warehouse_id,
            quantity: mat.quantity_used,
            productName: mat.name,
            warehouseName: mat.warehouse_name
        });
    });
});
</script>

<?php include 'footer.php'; ob_end_flush(); ?>
