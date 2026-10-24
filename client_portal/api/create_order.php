<?php
// client_portal/api/create_order.php
ob_start();
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/db_connect.php';

api_require_post_csrf();
$clientId = api_require_login();
api_rate_limit_or_fail('client_portal_create_order', 20, 600, api_rate_limit_scope('create_order'));
$clientName = api_clean_text((string)($_SESSION[APP_CLIENT_SESSION_NAME_KEY] ?? $_SESSION['client_name'] ?? 'Portal User'), 120);

function detect_job_type(string $service): string
{
    if (strpos($service, 'علب') !== false || strpos($service, 'كرتون') !== false) {
        return 'carton';
    }
    if (strpos($service, 'بلاستيك') !== false || strpos($service, 'أكياس') !== false) {
        return 'plastic';
    }
    if (strpos($service, 'تسويق') !== false || strpos($service, 'سوشيال') !== false) {
        return 'social';
    }
    if (strpos($service, 'ويب') !== false || strpos($service, 'موقع') !== false) {
        return 'web';
    }
    if (strpos($service, 'تصميم') !== false) {
        return 'design_only';
    }
    return 'print';
}

function normalize_qty($rawQty): float
{
    $qty = (float) $rawQty;
    if ($qty <= 0) {
        return 1;
    }
    if ($qty > 100000000) {
        return 100000000;
    }
    return $qty;
}

function portal_order_clean_nested($value, int $maxLen = 2500)
{
    if (is_array($value)) {
        $clean = [];
        foreach ($value as $key => $item) {
            $safeKey = is_string($key) ? preg_replace('/[^a-zA-Z0-9_\\-]/', '', $key) : $key;
            $cleaned = portal_order_clean_nested($item, $maxLen);
            if ($cleaned === '' || $cleaned === [] || $cleaned === null) {
                continue;
            }
            $clean[$safeKey] = $cleaned;
        }
        return $clean;
    }

    return api_clean_text($value, $maxLen);
}

function portal_order_value_to_text($value): string
{
    if (is_array($value)) {
        $isList = array_keys($value) === range(0, count($value) - 1);
        if ($isList) {
            return implode('، ', array_filter(array_map('portal_order_value_to_text', $value)));
        }
        $parts = [];
        foreach ($value as $key => $item) {
            $text = portal_order_value_to_text($item);
            if ($text !== '') {
                $parts[] = $key . ': ' . $text;
            }
        }
        return implode("\n", $parts);
    }
    return trim((string)$value);
}

function portal_order_labels(): array
{
    return [
        'common' => [
            'timeline' => 'موعد التنفيذ المطلوب',
            'request_priority' => 'أولوية الطلب',
            'approval_flow' => 'المطلوب بعد المراجعة',
        ],
        'web' => [
            'project_type' => 'نوع الموقع أو المنصة',
            'pages' => 'الصفحات المطلوبة',
            'features' => 'الوظائف المطلوبة',
            'integrations' => 'التكاملات',
            'user_roles' => 'أنواع المستخدمين والصلاحيات',
            'content_ready' => 'جاهزية المحتوى',
            'languages' => 'اللغات',
            'hosting_domain' => 'الدومين والاستضافة والبريد',
            'design_reference' => 'اتجاه التصميم أو المراجع',
            'security' => 'الأمان والخصوصية',
            'maintenance_needed' => 'الدعم بعد الإطلاق',
        ],
        'print' => [
            'print_product_type' => 'نوع المنتج المطبوع',
            'final_size' => 'المقاس النهائي',
            'open_size' => 'المقاس المفتوح',
            'pages_count' => 'عدد الصفحات',
            'paper_type' => 'نوع الورق',
            'paper_weight' => 'وزن الورق',
            'color_sides' => 'وجه الطباعة',
            'color_mode' => 'نظام الألوان',
            'artwork_ready' => 'حالة التصميم',
            'finishing' => 'التشطيبات',
            'folding_type' => 'نوع الطي',
            'binding_type' => 'نوع التجليد',
            'delivery_location' => 'مكان التسليم',
        ],
        'carton' => [
            'box_type' => 'نوع العبوة',
            'product_type' => 'المنتج داخل العبوة',
            'dimensions' => 'أبعاد العبوة',
            'product_weight' => 'وزن المنتج',
            'board_type' => 'نوع الخامة',
            'board_thickness' => 'السماكة أو الجراماج',
            'printing_required' => 'هل مطلوب طباعة؟',
            'print_sides' => 'جهات الطباعة',
            'color_mode' => 'عدد الألوان',
            'coating' => 'التشطيبات',
            'die_cut_required' => 'قص فورمة خاص',
            'window_required' => 'شباك شفاف',
            'handle_required' => 'يد أو حامل',
            'assembly_method' => 'طريقة التجميع',
            'packing_method' => 'طريقة التوريد',
            'sample_required' => 'عينة قبل الإنتاج',
        ],
        'plastic' => [
            'plastic_product_type' => 'نوع المنتج',
            'unit_type' => 'وحدة الكمية',
            'material_type' => 'نوع الخامة',
            'thickness' => 'السماكة',
            'dimensions' => 'المقاسات',
            'usage_type' => 'الاستخدام النهائي',
            'food_grade' => 'ملامسة غذاء',
            'printing_required' => 'هل مطلوب طباعة؟',
            'print_colors' => 'عدد ألوان الطباعة',
            'print_area' => 'مساحة الطباعة',
            'finish_type' => 'شكل الخامة',
            'sealing_type' => 'نوع اللحام',
            'gusset_required' => 'جنب أو قاعدة توسعة',
            'zipper_required' => 'زيبر',
            'hanger_hole' => 'فتحة تعليق',
            'roll_core_size' => 'قلب الرول',
            'packing_requirement' => 'طريقة التعبئة للتوريد',
            'sample_required' => 'عينة قبل الإنتاج',
        ],
        'social' => [
            'platforms' => 'المنصات المطلوبة',
            'service_scope' => 'نطاق الخدمة',
            'business_goal' => 'هدف النشاط',
            'target_audience' => 'الجمهور المستهدف',
            'monthly_posts' => 'عدد المنشورات شهريًا',
            'monthly_reels' => 'عدد الفيديوهات أو الريلز',
            'content_language' => 'لغة المحتوى',
            'brand_tone' => 'أسلوب الخطاب',
            'content_categories' => 'محاور المحتوى',
            'assets_available' => 'المواد المتوفرة',
            'ad_budget' => 'ميزانية الإعلانات',
            'competitors' => 'حسابات مرجعية أو منافسة',
            'approval_process' => 'آلية الاعتماد',
            'reporting_frequency' => 'دورية التقارير',
        ],
        'design_only' => [
            'design_scope' => 'نطاق التصميم',
            'brand_stage' => 'مرحلة العلامة',
            'business_description' => 'وصف النشاط',
            'brand_values' => 'قيم العلامة',
            'target_audience' => 'الجمهور المستهدف',
            'visual_direction' => 'التوجه البصري',
            'color_preferences' => 'الألوان المفضلة أو المرفوضة',
            'typography_preferences' => 'تفضيلات الخطوط',
            'usage_channels' => 'قنوات الاستخدام',
            'formats' => 'صيغ التسليم',
            'revisions_expectation' => 'جولات التعديل المتوقعة',
            'deadline' => 'الموعد النهائي',
        ],
    ];
}

function portal_order_filter_brief(array $brief, string $jobType): array
{
    $filtered = [];
    if (isset($brief['common']) && is_array($brief['common'])) {
        $filtered['common'] = $brief['common'];
    }
    if (isset($brief[$jobType]) && is_array($brief[$jobType])) {
        $filtered[$jobType] = $brief[$jobType];
    }
    return $filtered;
}

function portal_order_append_brief_lines(array &$details, array $brief): void
{
    if (empty($brief)) {
        return;
    }
    $labels = portal_order_labels();
    $titles = [
        'common' => 'بيانات تشغيل عامة',
        'web' => 'متطلبات الموقع أو المنصة',
        'print' => 'متطلبات الطباعة',
        'carton' => 'متطلبات الكرتون والتغليف',
        'plastic' => 'متطلبات البلاستيك والتعبئة',
        'social' => 'متطلبات السوشيال ميديا',
        'design_only' => 'متطلبات التصميم والهوية',
    ];

    foreach ($brief as $group => $fields) {
        if (!is_array($fields) || empty($fields)) {
            continue;
        }
        $details[] = '';
        $details[] = '--- ' . ($titles[$group] ?? $group) . ' ---';
        foreach ($fields as $key => $value) {
            $text = portal_order_value_to_text($value);
            if ($text === '') {
                continue;
            }
            $details[] = ($labels[$group][$key] ?? $key) . ': ' . $text;
        }
    }
}

$jobName = api_clean_text($_POST['job_name'] ?? 'طلب من البوابة', 180);
$serviceType = api_clean_text($_POST['service_type'] ?? 'عام', 80);
$quantity = normalize_qty($_POST['quantity'] ?? 1);
$userNotes = api_clean_text($_POST['details'] ?? '', 3000);
$designStatus = api_clean_text($_POST['design_status'] ?? 'needed', 40);
$jobType = detect_job_type($serviceType);
$brief = isset($_POST['brief']) && is_array($_POST['brief'])
    ? (array)portal_order_clean_nested($_POST['brief'], 2500)
    : [];
$brief = portal_order_filter_brief($brief, $jobType);

$details = [];
$details[] = '--- طلب وارد من بوابة العملاء ---';
$details[] = 'الخدمة المختارة: ' . $serviceType;

$qty = $quantity;
if ($jobType === 'design_only') {
    $qty = normalize_qty($_POST['design_items_count'] ?? $quantity);
    $details[] = 'عدد البنود: ' . $qty;
} elseif ($jobType === 'print') {
    $qty = normalize_qty($_POST['print_quantity'] ?? $quantity);
    $details[] = 'الكمية المطلوبة: ' . $qty;
    if (!empty($_POST['paper_type'])) {
        $details[] = 'الورق: ' . api_clean_text($_POST['paper_type'], 80);
    }
} elseif ($jobType === 'carton') {
    $qty = normalize_qty($_POST['carton_quantity'] ?? $quantity);
    $details[] = 'الكمية المطلوبة: ' . $qty;
} elseif ($jobType === 'plastic') {
    $qty = normalize_qty($_POST['plastic_quantity'] ?? $quantity);
    $details[] = 'الكمية: ' . $qty;
} elseif ($jobType === 'social') {
    $qty = normalize_qty($_POST['social_items_count'] ?? $quantity);
    $details[] = 'عدد البوستات: ' . $qty;
} elseif ($jobType === 'web') {
    $qty = 1;
    if (!empty($_POST['web_type'])) {
        $details[] = 'نوع الموقع: ' . api_clean_text($_POST['web_type'], 80);
    }
}

if ($userNotes !== '') {
    $details[] = '';
    $details[] = '--- ملاحظات العميل ---';
    $details[] = $userNotes;
}
portal_order_append_brief_lines($details, $brief);

$finalDetails = implode("\n", $details);
$currentStage = 'pending';

try {
    $insert = $pdo->prepare(
        'INSERT INTO job_orders
         (client_id, job_name, job_type, design_status, status, start_date, delivery_date, created_at, current_stage, quantity, added_by, job_details)
         VALUES
         (?, ?, ?, ?, "pending", NOW(), DATE_ADD(NOW(), INTERVAL 3 DAY), NOW(), ?, ?, ?, ?)'
    );

    $insert->execute([$clientId, $jobName, $jobType, $designStatus, $currentStage, $qty, $clientName, $finalDetails]);
    $newJobId = (int) $pdo->lastInsertId();

    $uploadedCount = 0;
    if (isset($_FILES['attachment'])) {
        $allowExt = ['pdf', 'png', 'jpg', 'jpeg', 'webp', 'gif', 'ai', 'psd', 'cdr', 'doc', 'docx', 'xls', 'xlsx', 'zip', 'rar'];
        $maxSize = 15 * 1024 * 1024;
        $maxFiles = 8;
        $maxTotal = 60 * 1024 * 1024;

        $names = is_array($_FILES['attachment']['name']) ? $_FILES['attachment']['name'] : [$_FILES['attachment']['name']];
        $tmps = is_array($_FILES['attachment']['tmp_name']) ? $_FILES['attachment']['tmp_name'] : [$_FILES['attachment']['tmp_name']];
        $sizes = is_array($_FILES['attachment']['size']) ? $_FILES['attachment']['size'] : [$_FILES['attachment']['size']];
        $errors = is_array($_FILES['attachment']['error']) ? $_FILES['attachment']['error'] : [$_FILES['attachment']['error']];
        $activeFiles = 0;
        $totalUploadSize = 0;
        for ($i = 0; $i < count($names); $i++) {
            $error = (int)($errors[$i] ?? UPLOAD_ERR_NO_FILE);
            if ($error === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            $activeFiles++;
            $totalUploadSize += (int)($sizes[$i] ?? 0);
        }
        if ($activeFiles > $maxFiles || $totalUploadSize > $maxTotal) {
            api_json(['status' => 'error', 'message' => 'المرفقات تتجاوز الحدود المسموحة.'], 422);
        }

        for ($i = 0; $i < count($names); $i++) {
            $originalName = (string)($names[$i] ?? '');
            $tmp = (string)($tmps[$i] ?? '');
            $size = (int)($sizes[$i] ?? 0);
            $error = (int)($errors[$i] ?? UPLOAD_ERR_NO_FILE);

            if ($error === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            $ext = strtolower((string)pathinfo($originalName, PATHINFO_EXTENSION));
            if ($originalName === '' || $tmp === '' || $size <= 0 || $size > $maxSize || $error !== UPLOAD_ERR_OK || !in_array($ext, $allowExt, true)) {
                continue;
            }

            $singleFile = [
                'name' => $originalName,
                'type' => (string)($_FILES['attachment']['type'][$i] ?? $_FILES['attachment']['type'] ?? ''),
                'tmp_name' => $tmp,
                'error' => $error,
                'size' => $size,
            ];
            $stored = app_store_uploaded_file($singleFile, [
                'dir' => dirname(__DIR__, 2) . '/uploads/job_files',
                'prefix' => 'portal_' . $newJobId . '_',
                'max_size' => $maxSize,
                'allowed_extensions' => $allowExt,
            ]);
            if (!$stored['ok']) {
                continue;
            }

            $dbPath = ltrim(str_replace(dirname(__DIR__, 2) . '/', '', (string)$stored['path']), '/');
            $fileStmt = $pdo->prepare(
                'INSERT INTO job_files (job_id, file_path, stage, description, uploaded_by, created_at)
                 VALUES (?, ?, ?, "مرفق مبدئي", ?, NOW())'
            );
            $fileStmt->execute([$newJobId, $dbPath, $currentStage, $clientName]);
            $uploadedCount++;
        }
    }

    $fileStatus = $uploadedCount > 0 ? ' وتم رفع المرفقات بنجاح' : '';
    api_json([
        'status' => 'success',
        'message' => "تم إرسال الطلب رقم #{$newJobId} للإدارة للمراجعة{$fileStatus}"
    ]);
} catch (PDOException $e) {
    error_log('Create Order Error: ' . $e->getMessage());
    api_json(['status' => 'error', 'message' => 'تعذر حفظ الطلب حالياً، حاول مرة أخرى'], 500);
}
