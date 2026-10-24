<?php 
// add_job.php - (Royal Ops V30.0 - Mobile Responsive & Smart Logic)
// تم الحفاظ على جميع الفنيات والمواصفات كما هي
error_reporting(E_ALL);
require 'auth.php'; require 'config.php'; 
app_handle_lang_switch($conn);
$is_en = app_lang_is('en');
$tr = static function (string $ar, string $en) use ($is_en): string {
    return $is_en ? $en : $ar;
};
$pageError = '';

// --- 1. التحقق من الصلاحيات ---
if(!app_user_can('jobs.create') && !app_user_can('jobs.manage_all')){
    die("<div class='container'><div class='alert-box' style='color:red; text-align:center; padding:50px; background:#1a1a1a; border-radius:10px;'>" . app_h($tr('ليس لديك صلاحية فتح أمر شغل.', 'You do not have permission to create a work order.')) . "</div></div>");
}
$canAssignOnCreate = app_user_can('jobs.assign') || app_user_can('jobs.manage_all');

$teamUsers = [];
if ($canAssignOnCreate) {
    $usersRes = $conn->query("SELECT id, full_name, role FROM users WHERE role <> 'guest' ORDER BY full_name ASC");
    if ($usersRes) {
        while ($uRow = $usersRes->fetch_assoc()) {
            $teamUsers[] = $uRow;
        }
    }
}

$operationTypes = app_operation_types($conn, true);
$jobTypeDisplayDefaults = [
    'print' => $tr('الطباعة', 'Printing'),
    'carton' => $tr('الكرتون', 'Carton'),
    'plastic' => $tr('البلاستيك', 'Plastic'),
    'social' => $tr('السوشيال', 'Social'),
    'web' => $tr('المواقع', 'Web'),
    'design_only' => $tr('التصميم فقط', 'Design Only'),
];
if (empty($operationTypes)) {
    $operationTypes = [
        ['type_key' => 'print', 'type_name' => $jobTypeDisplayDefaults['print'], 'icon_class' => 'fa-print'],
        ['type_key' => 'carton', 'type_name' => $jobTypeDisplayDefaults['carton'], 'icon_class' => 'fa-box-open'],
        ['type_key' => 'plastic', 'type_name' => $jobTypeDisplayDefaults['plastic'], 'icon_class' => 'fa-bag-shopping'],
        ['type_key' => 'social', 'type_name' => $jobTypeDisplayDefaults['social'], 'icon_class' => 'fa-hashtag'],
        ['type_key' => 'web', 'type_name' => $jobTypeDisplayDefaults['web'], 'icon_class' => 'fa-laptop-code'],
        ['type_key' => 'design_only', 'type_name' => $jobTypeDisplayDefaults['design_only'], 'icon_class' => 'fa-pen-nib'],
    ];
}
$operationTypeMap = [];
foreach ($operationTypes as $typeRow) {
    $typeKey = (string)($typeRow['type_key'] ?? '');
    if ($typeKey === '') {
        continue;
    }
    $displayTypeName = (string)($typeRow['type_name'] ?? $typeKey);
    if ($is_en && isset($jobTypeDisplayDefaults[$typeKey])) {
        $displayTypeName = $jobTypeDisplayDefaults[$typeKey];
    }
    $operationTypeMap[$typeKey] = [
        'name' => $displayTypeName,
        'icon' => (string)($typeRow['icon_class'] ?? 'fa-circle'),
    ];
}
if (empty($operationTypeMap)) {
    $operationTypeMap = [
        'print' => ['name' => $jobTypeDisplayDefaults['print'], 'icon' => 'fa-print'],
    ];
}

$paperCatalog = [
    'كوشيه',
    'دوبلكس',
    'برستول',
    'كرافت',
    'طبع',
    'نيوز',
    'ايفوري',
    'NCR',
    'FBB',
    'ورق لاصق',
    'ورق فويل'
];
$materialsCatalog = [
    'ورق',
    'أحبار طباعة',
    'زنكات',
    'سلفان',
    'ورنيش UV',
    'غراء',
    'كرتون مموج',
    'بلاستيك HDPE',
    'بلاستيك LDPE',
    'خيط/دبوس',
    'شريط لاصق',
    'كرتون تعبئة'
];
try {
    $invRes = $conn->query("SELECT name, category FROM inventory_items ORDER BY name ASC");
    if ($invRes) {
        while ($inv = $invRes->fetch_assoc()) {
            $invName = trim((string)($inv['name'] ?? ''));
            $invCategory = trim((string)($inv['category'] ?? ''));
            if ($invName === '') {
                continue;
            }
            $materialsCatalog[] = $invName;
            $searchScope = function_exists('mb_strtolower')
                ? mb_strtolower($invName . ' ' . $invCategory, 'UTF-8')
                : strtolower($invName . ' ' . $invCategory);
            if (
                strpos($searchScope, 'ورق') !== false
                || strpos($searchScope, 'paper') !== false
                || strpos($searchScope, 'coated') !== false
                || strpos($searchScope, 'كوشيه') !== false
                || strpos($searchScope, 'دوبلكس') !== false
                || strpos($searchScope, 'برستول') !== false
                || strpos($searchScope, 'كرافت') !== false
                || strpos($searchScope, 'ivory') !== false
            ) {
                $paperCatalog[] = $invName;
            }
        }
    }
} catch (Throwable $e) {
    error_log('add_job catalog lookup skipped: ' . $e->getMessage());
}
$paperCatalog = array_values(array_unique(array_filter(array_map('trim', $paperCatalog))));
$materialsCatalog = array_values(array_unique(array_filter(array_map('trim', $materialsCatalog))));
sort($paperCatalog, SORT_NATURAL | SORT_FLAG_CASE);
sort($materialsCatalog, SORT_NATURAL | SORT_FLAG_CASE);

$paperCatalog = array_values(array_unique(array_merge(
    $paperCatalog,
    app_operation_catalog_items($conn, 'print', 'paper'),
    app_operation_catalog_items($conn, 'carton', 'paper')
)));
sort($paperCatalog, SORT_NATURAL | SORT_FLAG_CASE);

$materialsCatalog = array_values(array_unique(array_merge(
    $materialsCatalog,
    app_operation_catalog_items($conn, 'print', 'material'),
    app_operation_catalog_items($conn, 'carton', 'material'),
    app_operation_catalog_items($conn, 'plastic', 'material')
)));
sort($materialsCatalog, SORT_NATURAL | SORT_FLAG_CASE);

$designScopeCatalog = array_values(array_unique(array_merge(
    ['هوية بصرية', 'عبوات', 'مطبوعات', 'سوشيال ميديا', 'موشن'],
    app_operation_catalog_items($conn, 'design_only', 'scope')
)));
$designDeliverablesCatalog = array_values(array_unique(array_merge(
    ['PDF جاهز للطباعة', 'AI مفتوح', 'PSD مفتوح', 'PNG/JPG', 'Mockup'],
    app_operation_catalog_items($conn, 'design_only', 'deliverable')
)));
$printServicesCatalog = array_values(array_unique(array_merge(
    ['بصمة', 'كفراج', 'تخريم', 'ترقيم', 'تدبيس', 'تجليد', 'UV موضعي', 'Hot Foil'],
    app_operation_catalog_items($conn, 'print', 'service')
)));
$cartonServicesCatalog = array_values(array_unique(array_merge(
    ['كفراج', 'لسان لصق', 'تخريم', 'تجميع'],
    app_operation_catalog_items($conn, 'carton', 'service')
)));
$plasticFeaturesCatalog = array_values(array_unique(array_merge(
    ['فتحات تهوية', 'ثقوب تعليق', 'سحاب', 'قاع مدعم', 'طباعة داخلية'],
    app_operation_catalog_items($conn, 'plastic', 'feature')
)));
$socialPlatformsCatalog = array_values(array_unique(array_merge(
    ['Facebook', 'Instagram', 'TikTok', 'Snapchat', 'LinkedIn', 'X (Twitter)', 'YouTube', 'Google Ads'],
    app_operation_catalog_items($conn, 'social', 'platform')
)));
$socialContentCatalog = array_values(array_unique(array_merge(
    ['بوست ثابت', 'كاروسيل', 'ريلز', 'ستوري', 'فيديو إعلاني'],
    app_operation_catalog_items($conn, 'social', 'content')
)));
$webFeaturesCatalog = array_values(array_unique(array_merge(
    ['لوحة تحكم', 'متعدد اللغات', 'SEO', 'مدونة', 'نظام حجز', 'نظام دفع'],
    app_operation_catalog_items($conn, 'web', 'feature')
)));

// --- معالجة الحفظ (Smart Save) ---
if(isset($_POST['save_job'])){
    if (!app_verify_csrf($_POST['_csrf_token'] ?? '')) {
        http_response_code(403);
        die('Invalid CSRF token');
    }

    $collectValues = static function ($raw): array {
        $values = [];
        if (!is_array($raw)) {
            return $values;
        }
        foreach ($raw as $item) {
            $val = trim((string)$item);
            if ($val !== '') {
                $values[] = $val;
            }
        }
        return array_values(array_unique($values));
    };
    $expandTextValues = static function ($rawText): array {
        $rawText = trim((string)$rawText);
        if ($rawText === '') {
            return [];
        }
        $parts = preg_split('/[\n,،]+/u', $rawText);
        $values = [];
        foreach ((array)$parts as $part) {
            $val = trim((string)$part);
            if ($val !== '') {
                $values[] = $val;
            }
        }
        return array_values(array_unique($values));
    };

    $client_id = intval($_POST['client_id']);
    $job_name = trim((string)($_POST['job_name'] ?? ''));
    $job_type = (string)($_POST['job_type'] ?? 'print');
    $allowedJobTypes = array_keys($operationTypeMap);
    if (!in_array($job_type, $allowedJobTypes, true)) {
        $job_type = (string)($allowedJobTypes[0] ?? 'print');
    }
    $delivery_date = (string)($_POST['delivery_date'] ?? date('Y-m-d'));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $delivery_date)) {
        $delivery_date = date('Y-m-d');
    }
    $notes = trim((string)($_POST['notes'] ?? ''));
    $design_status = (string)($_POST['design_status'] ?? 'ready');
    if (!in_array($design_status, ['ready', 'needed'], true)) {
        $design_status = 'ready';
    }
    
    // تجميع التفاصيل الفنية (كما هي تماماً)
    $details = [];
    $details[] = "--- تفاصيل العملية ---";
    
    // معالجة نوع الورق
    $final_paper_type = $_POST['paper_type'] ?? '';
    if($final_paper_type == 'other' && !empty($_POST['paper_type_other'])){
        $final_paper_type = $_POST['paper_type_other'];
    }

    $qty = 0; 

    // 1. التصميم فقط
    if($job_type == 'design_only'){
        $qty = intval($_POST['design_items_count']);
        $details[] = "عدد البنود: " . $qty;
        $designScope = $collectValues($_POST['design_scope'] ?? []);
        $designScope = array_values(array_unique(array_merge($designScope, $expandTextValues($_POST['design_scope_other'] ?? ''))));
        $designDeliverables = $collectValues($_POST['design_deliverables'] ?? []);
        $designDeliverables = array_values(array_unique(array_merge($designDeliverables, $expandTextValues($_POST['design_deliverables_other'] ?? ''))));
        if (!empty($designScope)) {
            $details[] = "نطاق التصميم: " . implode(" + ", array_values(array_unique($designScope)));
        }
        if (!empty($designDeliverables)) {
            $details[] = "مخرجات التسليم: " . implode(" + ", array_values(array_unique($designDeliverables)));
        }
    }
    
    // 2. الطباعة
    elseif($job_type == 'print'){
        $qty = floatval($_POST['print_quantity'] ?? 0); 
        $details[] = "الكمية المطلوبة: " . $qty;
        $details[] = "الورق: " . $final_paper_type . " | الوزن: " . $_POST['paper_weight'] . "جم";
        if (!empty($_POST['paper_source'])) {
            $details[] = "مصدر الورق: " . trim((string)$_POST['paper_source']);
        }
        $details[] = "مقاس الورق: " . $_POST['paper_w'] . "x" . $_POST['paper_h'];
        $details[] = "مقاس القص: " . $_POST['cut_w'] . "x" . $_POST['cut_h'];
        $details[] = "الألوان: " . $_POST['print_colors'] . " | طريقة الطبع: " . $_POST['print_mode'];
        if (!empty($_POST['print_side_note'])) {
            $details[] = "ملاحظة الأوجه: " . trim((string)$_POST['print_side_note']);
        }
        if (!empty($_POST['print_machine'])) {
            $details[] = "الماكينة: " . trim((string)$_POST['print_machine']);
        }
        $details[] = "الزنكات: " . $_POST['zinc_count'] . " (" . $_POST['zinc_status'] . ")";

        $printMaterials = $collectValues($_POST['print_materials'] ?? []);
        $printMaterials = array_values(array_unique(array_merge($printMaterials, $expandTextValues($_POST['print_materials_other'] ?? ''))));
        if (!empty($printMaterials)) {
            $details[] = "الخامات المطلوبة: " . implode(" + ", array_values(array_unique($printMaterials)));
        }

        $printFinish = $collectValues($_POST['print_finish'] ?? []);
        $postPressServices = $collectValues($_POST['postpress_services'] ?? []);
        $postPressServices = array_values(array_unique(array_merge($postPressServices, $expandTextValues($_POST['postpress_other'] ?? ''))));
        $allFinishing = array_values(array_unique(array_merge($printFinish, $postPressServices)));
        if (!empty($allFinishing)) {
            $details[] = "التكميلي: " . implode(" + ", $allFinishing);
        }
        if (!empty($_POST['postpress_details'])) {
            $details[] = "تفاصيل ما بعد الطباعة: " . trim((string)$_POST['postpress_details']);
        }
    }

    // 3. الكرتون
    elseif($job_type == 'carton'){
        $carton_paper = $_POST['carton_paper_type'];
        if($carton_paper == 'other' && !empty($_POST['carton_paper_other'])){
            $carton_paper = $_POST['carton_paper_other'];
        }
        $qty = floatval($_POST['carton_quantity'] ?? 0);
        $details[] = "الكمية المطلوبة: " . $qty;
        $details[] = "الخامة الخارجية: " . $carton_paper;
        if (!empty($_POST['carton_flute_type'])) {
            $details[] = "نوع الفلوت: " . trim((string)$_POST['carton_flute_type']);
        }
        $details[] = "عدد الطبقات: " . $_POST['carton_layers'];
        $details[] = "تفاصيل الطبقات: " . $_POST['carton_details'];
        $details[] = "مقاس القص: " . $_POST['carton_cut_w'] . "x" . $_POST['carton_cut_h'];
        if (!empty($_POST['carton_box_l']) || !empty($_POST['carton_box_w']) || !empty($_POST['carton_box_h'])) {
            $details[] = "مقاس العلبة النهائي: " . trim((string)$_POST['carton_box_l']) . "x" . trim((string)$_POST['carton_box_w']) . "x" . trim((string)$_POST['carton_box_h']);
        }
        if (!empty($_POST['carton_die_type'])) {
            $details[] = "نوع/رقم الفورمة: " . trim((string)$_POST['carton_die_type']);
        }
        if (!empty($_POST['carton_glue_type'])) {
            $details[] = "نوع الغراء/الإقفال: " . trim((string)$_POST['carton_glue_type']);
        }
        $details[] = "الزنكات: " . $_POST['carton_zinc_count'] . " (" . $_POST['carton_zinc_status'] . ")";

        $cartonMaterials = $collectValues($_POST['carton_materials'] ?? []);
        $cartonMaterials = array_values(array_unique(array_merge($cartonMaterials, $expandTextValues($_POST['carton_materials_other'] ?? ''))));
        if (!empty($cartonMaterials)) {
            $details[] = "خامات الكرتون: " . implode(" + ", array_values(array_unique($cartonMaterials)));
        }

        $cartonFinish = $collectValues($_POST['carton_finish'] ?? []);
        $cartonServices = $collectValues($_POST['carton_services'] ?? []);
        $cartonServices = array_values(array_unique(array_merge($cartonServices, $expandTextValues($_POST['carton_services_other'] ?? ''))));
        $cartonAllFinish = array_values(array_unique(array_merge($cartonFinish, $cartonServices)));
        if (!empty($cartonAllFinish)) {
            $details[] = "التكميلي: " . implode(" + ", $cartonAllFinish);
        }
        if (!empty($_POST['carton_finish_details'])) {
            $details[] = "تفاصيل التشطيب: " . trim((string)$_POST['carton_finish_details']);
        }
    }

    // 4. البلاستيك
    elseif($job_type == 'plastic'){
        $qty = floatval($_POST['plastic_quantity'] ?? 0);
        $details[] = "الكمية: " . $qty;
        $details[] = "الخامة: " . $_POST['plastic_material'];
        if (!empty($_POST['plastic_product_type'])) {
            $details[] = "نوع المنتج البلاستيكي: " . trim((string)$_POST['plastic_product_type']);
        }
        $details[] = "السمك: " . $_POST['plastic_microns'] . " ميكرون | عرض الفيلم: " . $_POST['film_width'];
        $details[] = "المعالجة: " . $_POST['plastic_treatment'];
        $details[] = "طول القص: " . $_POST['plastic_cut_len'];
        if (!empty($_POST['plastic_sealing'])) {
            $details[] = "نوع اللحام/القفل: " . trim((string)$_POST['plastic_sealing']);
        }
        if (!empty($_POST['plastic_handle'])) {
            $details[] = "نوع اليد/الهاندل: " . trim((string)$_POST['plastic_handle']);
        }
        $details[] = "السلندرات: " . $_POST['cylinder_count'] . " (" . $_POST['cylinder_status'] . ")";

        $plasticFeatures = $collectValues($_POST['plastic_features'] ?? []);
        $plasticFeatures = array_values(array_unique(array_merge($plasticFeatures, $expandTextValues($_POST['plastic_features_other'] ?? ''))));
        if (!empty($plasticFeatures)) {
            $details[] = "خصائص إضافية: " . implode(" + ", array_values(array_unique($plasticFeatures)));
        }
    }

    // 5. التسويق
    elseif($job_type == 'social'){
        $qty = intval($_POST['social_items_count']);
        $details[] = "عدد البوستات/الفيديوهات: " . $qty;
        
        $platforms = isset($_POST['social_platforms']) ? implode(", ", $_POST['social_platforms']) : "غير محدد";
        $details[] = "المنصات المستهدفة: " . $platforms;
        $contentTypes = $collectValues($_POST['social_content_types'] ?? []);
        if (!empty($contentTypes)) {
            $details[] = "أنواع المحتوى: " . implode(" + ", $contentTypes);
        }
        
        if(!empty($_POST['campaign_goal'])) $details[] = "الهدف: " . $_POST['campaign_goal'];
        if(!empty($_POST['target_audience'])) $details[] = "الجمهور: " . $_POST['target_audience'];
        if(!empty($_POST['ad_budget'])) $details[] = "الميزانية المقترحة: " . $_POST['ad_budget'];
        if(!empty($_POST['social_publish_frequency'])) $details[] = "وتيرة النشر: " . trim((string)$_POST['social_publish_frequency']);
        if(!empty($_POST['social_kpis'])) $details[] = "مؤشرات القياس (KPI): " . trim((string)$_POST['social_kpis']);
    }

    // 6. المواقع
    elseif($job_type == 'web'){
        $qty = 1;
        $details[] = "نوع الموقع: " . $_POST['web_type'];
        if (!empty($_POST['web_pages_count'])) {
            $details[] = "عدد الصفحات المتوقع: " . trim((string)$_POST['web_pages_count']);
        }
        $details[] = "الدومين: " . $_POST['web_domain'];
        $details[] = "الاستضافة: " . $_POST['web_hosting'];
        $details[] = "الثيم: " . $_POST['web_theme'];
        $webFeatures = $collectValues($_POST['web_features'] ?? []);
        if (!empty($webFeatures)) {
            $details[] = "مزايا الموقع: " . implode(" + ", $webFeatures);
        }
        if (!empty($_POST['web_integrations'])) {
            $details[] = "تكاملات مطلوبة: " . trim((string)$_POST['web_integrations']);
        }
    }
    // 7. أي نوع عملية مخصص غير مبرمج بقسم خاص
    else {
        $qty = max(1, (int)($_POST['generic_quantity'] ?? 1));
        $details[] = "الكمية: " . $qty;
        if (!empty($_POST['generic_scope'])) {
            $details[] = "نطاق التنفيذ: " . trim((string)$_POST['generic_scope']);
        }
        if (!empty($_POST['generic_details'])) {
            $details[] = "تفاصيل إضافية: " . trim((string)$_POST['generic_details']);
        }
    }

    $job_details_text = implode("\n", $details);

    // التوجيه الذكي (Smart Routing) حسب تعريف المراحل من البيانات الأولية.
    $current_stage = app_operation_first_stage($conn, $job_type, 'briefing');
    $jobStages = app_operation_stages($conn, $job_type, true);
    $jobStageMap = app_operation_stage_map($conn, $job_type, true);
    if ($design_status === 'needed' && isset($jobStageMap['design'])) {
        $current_stage = 'design';
    } elseif ($design_status === 'ready' && $current_stage === 'design' && count($jobStages) > 1) {
        $current_stage = (string)($jobStages[1]['stage_key'] ?? 'briefing');
    }

    // الإدخال الأولي للطلب (للحصول على ID)
    $user = $_SESSION['name'] ?? $_SESSION['username'];
    $creatorUserId = (int)($_SESSION['user_id'] ?? 0);
    $allowedAssignRoles = ['member', 'owner', 'reviewer', 'finance', 'designer', 'production'];
    $selectedTeamAssignments = [];
    if ($canAssignOnCreate && is_array($_POST['team_user_ids'] ?? null)) {
        $teamIds = array_values(array_unique(array_map('intval', $_POST['team_user_ids'] ?? [])));
        $rawRoles = is_array($_POST['team_roles'] ?? null) ? $_POST['team_roles'] : [];
        foreach ($teamIds as $teamUid) {
            if ($teamUid <= 0) {
                continue;
            }
            $teamRole = trim((string)($rawRoles[$teamUid] ?? 'member'));
            if (!in_array($teamRole, $allowedAssignRoles, true)) {
                $teamRole = 'member';
            }
            $selectedTeamAssignments[$teamUid] = $teamRole;
        }
    }

    $accessToken = bin2hex(random_bytes(16));
    try {
        $conn->begin_transaction();
        $stmt = $conn->prepare("
            INSERT INTO job_orders
            (client_id, job_name, job_type, design_status, start_date, delivery_date, current_stage,
             quantity, notes, added_by, job_details, created_by_user_id, access_token)
            VALUES
            (?, ?, ?, ?, NOW(), ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        if (!$stmt) {
            throw new RuntimeException('prepare_job_insert_failed');
        }
        $stmt->bind_param(
            'isssssdsssis',
            $client_id,
            $job_name,
            $job_type,
            $design_status,
            $delivery_date,
            $current_stage,
            $qty,
            $notes,
            $user,
            $job_details_text,
            $creatorUserId,
            $accessToken
        );
        $stmt->execute();
        $new_id = (int)$stmt->insert_id;
        $stmt->close();

        $jobNumber = app_assign_document_number($conn, 'job_orders', (int)$new_id, 'job_number', 'job', $delivery_date);
        if ($creatorUserId > 0) {
            app_assign_user_to_job($conn, (int)$new_id, $creatorUserId, 'owner', $creatorUserId);
        }
        if ($canAssignOnCreate && !empty($selectedTeamAssignments)) {
            foreach ($selectedTeamAssignments as $memberId => $memberRole) {
                if ($memberId === $creatorUserId) {
                    continue;
                }
                app_assign_user_to_job($conn, (int)$new_id, (int)$memberId, (string)$memberRole, $creatorUserId > 0 ? $creatorUserId : null);
            }
        }

        if (!empty($_FILES['attachment']['name'][0])) {
            $allowedExt = [
                'jpg', 'jpeg', 'png', 'gif', 'webp',
                'pdf', 'ai', 'psd', 'cdr',
                'zip', 'rar',
                'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt'
            ];

            $total_files = count($_FILES['attachment']['name']);
            $insertFileStmt = $conn->prepare("
                INSERT INTO job_files (job_id, file_path, stage, description, uploaded_by)
                VALUES (?, ?, ?, 'ملف مرفق عند الإنشاء', ?)
            ");
            if (!$insertFileStmt) {
                throw new RuntimeException('prepare_job_file_insert_failed');
            }

            for ($i = 0; $i < $total_files; $i++) {
                $singleFile = [
                    'name' => $_FILES['attachment']['name'][$i] ?? '',
                    'type' => $_FILES['attachment']['type'][$i] ?? '',
                    'tmp_name' => $_FILES['attachment']['tmp_name'][$i] ?? '',
                    'error' => $_FILES['attachment']['error'][$i] ?? UPLOAD_ERR_NO_FILE,
                    'size' => $_FILES['attachment']['size'][$i] ?? 0,
                ];

                if ((int)$singleFile['error'] === UPLOAD_ERR_NO_FILE) {
                    continue;
                }

                $upload = app_store_uploaded_file($singleFile, [
                    'dir' => 'uploads/job_files',
                    'prefix' => 'job_' . $new_id . '_',
                    'max_size' => 2048 * 1024 * 1024,
                    'allowed_extensions' => $allowedExt,
                ]);

                if ($upload['ok']) {
                    $filePath = $upload['path'];
                    $insertFileStmt->bind_param("isss", $new_id, $filePath, $current_stage, $user);
                    $insertFileStmt->execute();
                } else {
                    error_log("add_job upload skipped: " . $upload['error']);
                }
            }

            $insertFileStmt->close();
        }

        $conn->commit();
        $jobRef = $jobNumber !== '' ? $jobNumber : ('#' . $new_id);
        $_SESSION['job_flash_success'] = $tr('تم فتح أمر الشغل رقم ', 'Work order created: ')
            . $jobRef
            . $tr(' وتم توجيهه لقسم: ', ' • Routed to stage: ')
            . $current_stage;
        app_safe_redirect('dashboard.php', 'index.php');
    } catch (Throwable $e) {
        $conn->rollback();
        error_log('add_job save failed: ' . $e->getMessage());
        $pageError = $tr('تعذر حفظ أمر الشغل حالياً. راجع البيانات ثم حاول مرة أخرى.', 'Could not save the work order right now. Review the data and try again.');
    }
}
?>

<?php require 'header.php'; ?>
<style>
    :root { --bg-dark: #121212; --panel: #1e1e1e; --gold: #d4af37; --text: #e0e0e0; }
    body { background-color: var(--bg-dark); color: var(--text); font-family: 'Cairo', sans-serif; margin: 0; padding-bottom: 50px; }
    
    .container { max-width: 1220px; margin: 0 auto; padding: 15px; overflow: visible; }
    .job-page-shell { display:grid; gap:18px; overflow: visible; }
    .job-hero {
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
        overflow: visible;
    }
    .job-hero::after {
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
    .job-eyebrow {
        display:inline-flex; align-items:center; gap:8px; padding:6px 12px; border-radius:999px;
        background:rgba(212,175,55,0.08); border:1px solid rgba(212,175,55,0.24); color:#f0d684;
        font-size:.76rem; font-weight:700; margin-bottom:14px;
    }
    .job-title { margin:0; color:#f7f1dc; font-size:1.9rem; line-height:1.3; }
    .job-subtitle { margin:10px 0 0; color:#a8abb1; line-height:1.8; max-width:760px; }
    .hero-meta { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:12px; margin-top:18px; }
    .hero-chip {
        border-radius:18px;
        border:1px solid rgba(255,255,255,0.08);
        background:rgba(255,255,255,0.035);
        padding:16px;
        min-height:96px;
    }
    .hero-chip .label { color:#9ca0a8; font-size:.74rem; margin-bottom:8px; }
    .hero-chip .value { color:#fff; font-size:1rem; font-weight:800; line-height:1.5; }

    .royal-card {
        background:
            linear-gradient(180deg, rgba(255,255,255,0.05), rgba(255,255,255,0.02)),
            rgba(18,18,18,0.78);
        border: 1px solid rgba(255,255,255,0.08);
        border-radius: 22px;
        padding: 24px;
        box-shadow: 0 14px 30px rgba(0,0,0,0.24);
        backdrop-filter: blur(14px);
        overflow: visible;
        position: relative;
        z-index: 2;
    }
    
    .section-header {
        color: var(--gold);
        font-size: 1.04rem;
        border-bottom: 1px solid rgba(255,255,255,0.08);
        padding-bottom: 12px;
        margin: 28px 0 16px 0;
        display: flex; align-items: center; gap: 10px;
    }
    
    /* Responsive Grid System */
    .grid-row {
        display: grid; 
        grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); /* متجاوب مع الموبايل */
        gap: 15px; 
        margin-bottom: 15px;
        overflow: visible;
    }
    .grid-row > div,
    .dynamic-section,
    form,
    .team-wrap {
        position: relative;
        overflow: visible;
    }
    
    label { display: block; margin-bottom: 8px; font-size: 0.9rem; color: #aaa; }
    
    input, select, textarea {
        width: 100%; padding: 13px 14px;
        background: rgba(8,8,8,0.84); border: 1px solid rgba(255,255,255,0.1); color: #fff;
        border-radius: 14px; font-family: 'Cairo'; transition: 0.3s;
        box-sizing: border-box; /* يمنع الخروج عن الإطار */
    }
    select {
        color-scheme: dark;
        background-color: rgba(8,8,8,0.96);
        position: relative;
        z-index: 30;
    }
    select option,
    select optgroup {
        background: #111;
        color: #f3f3f3;
    }
    input:focus, select:focus, textarea:focus { border-color: var(--gold); outline: none; box-shadow: 0 0 0 4px rgba(212, 175, 55, 0.08); }
    .grid-row > div:focus-within,
    .dynamic-section:focus-within,
    .team-item:focus-within {
        z-index: 40;
    }
    
    .btn-royal {
        background: linear-gradient(135deg, var(--gold), #b8860b);
        color: #000; font-weight: bold; border: none;
        padding: 15px; border-radius: 16px;
        cursor: pointer; font-size: 1.1rem; width: 100%; margin-top: 20px;
        transition: transform 0.2s;
        box-shadow: 0 5px 15px rgba(212, 175, 55, 0.3);
    }
    .btn-royal:hover { transform: translateY(-2px); }
    
    .dynamic-section { display: none; animation: fadeIn 0.5s; }
    @keyframes fadeIn { from {opacity:0; transform:translateY(-10px);} to {opacity:1; transform:translateY(0);} }
    
    /* Checkbox Styling */
    .checkbox-group { display: flex; flex-wrap: wrap; gap: 10px; }
    .cb-label {
        background: rgba(255,255,255,0.04); padding: 10px 15px; border-radius: 12px; cursor: pointer; border: 1px solid rgba(255,255,255,0.08);
        display: flex; align-items: center; gap: 8px; font-size: 0.85rem; transition: 0.3s; flex: 1; min-width: 120px;
    }
    .cb-label:hover { border-color: var(--gold); transform: translateY(-2px); }
    .cb-label i { font-size: 1.1rem; }
    input[type="checkbox"] { width: auto; accent-color: var(--gold); margin: 0; }
    .team-wrap {
        border: 1px solid rgba(255,255,255,0.08);
        background: rgba(255,255,255,0.025);
        border-radius: 18px;
        padding: 14px;
        margin-bottom: 10px;
    }
    .team-item {
        display: grid;
        grid-template-columns: 1fr 180px;
        gap: 10px;
        align-items: center;
        padding: 8px;
        border-bottom: 1px dashed #2f2f2f;
    }
    .team-item:last-child { border-bottom: none; }
    .team-user-label {
        display: flex;
        align-items: center;
        gap: 10px;
        color: #ddd;
        margin: 0;
    }
    .team-user-label small { color: #9f9f9f; }
    .team-role-select {
        width: 100%;
        padding: 9px;
        background: #0e0e0e;
        border: 1px solid #333;
        color: #fff;
        border-radius: 8px;
    }
    .team-confirm-row {
        margin-top: 12px;
        display: flex;
        gap: 10px;
        align-items: center;
        flex-wrap: wrap;
    }
    .btn-team-confirm {
        border: 1px solid rgba(212, 175, 55, 0.5);
        background: rgba(212, 175, 55, 0.12);
        color: #f3d980;
        padding: 10px 14px;
        border-radius: 9px;
        cursor: pointer;
        font-family: 'Cairo', sans-serif;
        font-weight: 700;
    }
    .btn-team-confirm:hover { background: rgba(212, 175, 55, 0.2); }
    .team-summary {
        color: #a9a9a9;
        font-size: 0.86rem;
        flex: 1;
    }
    .native-select-hidden {
        position: absolute;
        inline-size: 1px;
        block-size: 1px;
        opacity: 0;
        pointer-events: none;
    }
    .select-shell {
        position: relative;
    }
    .select-trigger {
        width: 100%;
        min-height: 54px;
        padding: 13px 42px 13px 14px;
        background: rgba(8,8,8,0.84);
        border: 1px solid rgba(255,255,255,0.1);
        color: #fff;
        border-radius: 14px;
        font-family: 'Cairo', sans-serif;
        font-size: 1rem;
        text-align: right;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        transition: 0.3s;
    }
    .select-trigger::before {
        content: "";
        position: absolute;
        inset-inline-start: 14px;
        inset-block-start: 50%;
        width: 14px;
        height: 14px;
        border-right: 2px solid var(--gold);
        border-bottom: 2px solid var(--gold);
        transform: translateY(-65%) rotate(45deg);
        pointer-events: none;
        transition: transform 0.2s ease;
    }
    .select-shell.open .select-trigger,
    .select-trigger:focus {
        border-color: var(--gold);
        outline: none;
        box-shadow: 0 0 0 4px rgba(212, 175, 55, 0.08);
    }
    .select-shell.open .select-trigger::before {
        transform: translateY(-35%) rotate(-135deg);
    }
    .select-trigger-label {
        flex: 1;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }
    .select-panel {
        position: absolute;
        inset-inline: 0;
        inset-block-start: calc(100% + 8px);
        max-height: 280px;
        overflow-y: auto;
        background: rgba(14,14,14,0.98);
        border: 1px solid rgba(212,175,55,0.22);
        border-radius: 16px;
        box-shadow: 0 18px 36px rgba(0,0,0,0.34);
        z-index: 80;
        display: none;
        padding: 8px;
    }
    .select-shell.open .select-panel {
        display: block;
    }
    .select-option {
        width: 100%;
        border: 0;
        background: transparent;
        color: #f3f3f3;
        padding: 12px 14px;
        border-radius: 12px;
        text-align: right;
        font-family: 'Cairo', sans-serif;
        font-size: 0.97rem;
        cursor: pointer;
        display: block;
    }
    .select-option:hover,
    .select-option:focus,
    .select-option.is-selected {
        background: rgba(212,175,55,0.12);
        color: #f3d980;
        outline: none;
    }

    /* تحسين ظهور القوائم المنسدلة عبر المتصفحات */
    select:not([multiple]) {
        -webkit-appearance: none;
        -moz-appearance: none;
        appearance: none;
        background-image: url("data:image/svg+xml;charset=UTF-8,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%23d4af37' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3e%3cpolyline points='6 9 12 15 18 9'%3e%3c/polyline%3e%3c/svg%3e");
        background-repeat: no-repeat;
        background-position: left 12px center;
        background-size: 15px;
        padding-left: 34px;
    }
    @media (max-width: 900px) {
        .hero-meta { grid-template-columns:1fr; }
    }
</style>

<div class="container">
    <div class="job-page-shell">
    <section class="job-hero">
        <div class="job-eyebrow">إضافة عملية جديدة</div>
        <h1 class="job-title"><?php echo app_h($tr('فتح أمر تشغيل جديد', 'Create a new work order')); ?></h1>
        <p class="job-subtitle"><?php echo app_h($tr('واجهة إنشاء موحدة لاختيار العميل والقسم الفني والفريق والمواصفات التشغيلية من شاشة واحدة.', 'Unified creation flow to choose the client, department, team, and technical specifications from one screen.')); ?></p>
        <div class="hero-meta">
            <div class="hero-chip">
                <div class="label"><?php echo app_h($tr('الأقسام المتاحة', 'Available departments')); ?></div>
                <div class="value"><?php echo count($operationTypeMap); ?></div>
            </div>
            <div class="hero-chip">
                <div class="label"><?php echo app_h($tr('إسناد الفريق', 'Team assignment')); ?></div>
                <div class="value"><?php echo $canAssignOnCreate ? app_h($tr('متاح أثناء الإنشاء', 'Available during creation')) : app_h($tr('غير متاح لهذا الحساب', 'Not available for this account')); ?></div>
            </div>
            <div class="hero-chip">
                <div class="label"><?php echo app_h($tr('المرفقات', 'Attachments')); ?></div>
                <div class="value"><?php echo app_h($tr('يمكن رفع عدة ملفات مع الطلب', 'Multiple files can be uploaded with the order')); ?></div>
            </div>
        </div>
    </section>
    <div class="royal-card">
        <?php if ($pageError !== ''): ?>
            <div style="background:rgba(231,76,60,0.12); border:1px solid rgba(231,76,60,0.45); color:#ffb3ad; padding:14px 16px; border-radius:12px; margin-bottom:18px;">
                <?php echo app_h($pageError); ?>
            </div>
        <?php endif; ?>
        <h2 style="text-align:center; color:var(--gold); margin-top:0; border-bottom:1px dashed rgba(255,255,255,0.08); padding-bottom:15px;"><?php echo app_h($tr('نموذج أمر التشغيل', 'Work order form')); ?></h2>
        
        <form method="post" enctype="multipart/form-data" id="jobForm" onsubmit="return validateJobForm()">
            <?php echo app_csrf_input(); ?>
            
            <div class="section-header"><i class="fa-solid fa-circle-info"></i> <?php echo app_h($tr('1. البيانات الأساسية', '1. Basic Data')); ?></div>
            <div class="grid-row">
                <div>
                    <label><?php echo app_h($tr('العميل', 'Client')); ?></label>
                    <select name="client_id" required>
                        <option value=""><?php echo app_h($tr('-- اختر العميل --', '-- Select Client --')); ?></option>
                        <?php 
                        $c_res = $conn->query("SELECT id, name FROM clients ORDER BY name ASC");
                        while($row = $c_res->fetch_assoc()) echo "<option value='" . (int)$row['id'] . "'>" . app_h($row['name']) . "</option>";
                        ?>
                    </select>
                </div>
                <div><label><?php echo app_h($tr('اسم العملية', 'Operation Name')); ?></label><input type="text" name="job_name" required placeholder="<?php echo app_h($tr('مثال: علبة حلويات رمضان', 'Example: Ramadan sweets box')); ?>"></div>
                <div><label><?php echo app_h($tr('تاريخ التسليم', 'Delivery Date')); ?></label><input type="date" name="delivery_date" required></div>
            </div>

            <div class="section-header"><i class="fa-solid fa-layer-group"></i> <?php echo app_h($tr('2. القسم الفني', '2. Technical Section')); ?></div>
            <div class="grid-row">
                <div>
                    <label><?php echo app_h($tr('نوع العملية (القسم)', 'Operation Type')); ?></label>
                    <div class="select-shell" id="job_type_shell">
                        <select name="job_type" id="job_type" class="native-select-hidden" onchange="showSection()" required>
                            <option value=""><?php echo app_h($tr('-- حدد القسم --', '-- Select Section --')); ?></option>
                            <?php foreach ($operationTypeMap as $typeKey => $typeMeta): ?>
                                <option value="<?php echo app_h($typeKey); ?>">
                                    <?php echo app_h((string)$typeMeta['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <button type="button" class="select-trigger" id="job_type_trigger" aria-haspopup="listbox" aria-expanded="false">
                            <span class="select-trigger-label"><?php echo app_h($tr('-- حدد القسم --', '-- Select Section --')); ?></span>
                        </button>
                        <div class="select-panel" id="job_type_panel" role="listbox">
                            <?php foreach ($operationTypeMap as $typeKey => $typeMeta): ?>
                                <button type="button" class="select-option" data-value="<?php echo app_h($typeKey); ?>">
                                    <?php echo app_h((string)$typeMeta['name']); ?>
                                </button>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                
                <div id="design_toggle" style="display:none;">
                    <label><?php echo app_h($tr('حالة التصميم', 'Design Status')); ?></label>
                    <select name="design_status">
                        <option value="needed"><?php echo app_h($tr('يحتاج تصميم (مرحلة أولى)', 'Design required (initial stage)')); ?></option>
                        <option value="ready"><?php echo app_h($tr('التصميم جاهز (تخطي للتجهيز)', 'Design ready (skip to preparation)')); ?></option>
                    </select>
                </div>
            </div>

            <?php if ($canAssignOnCreate): ?>
            <div class="section-header"><i class="fa-solid fa-users"></i> <?php echo app_h($tr('3. فريق العملية', '3. Operation Team')); ?></div>
            <div class="team-wrap">
                <input type="hidden" name="team_selection_confirmed" id="team_selection_confirmed" value="0">
                <?php if (empty($teamUsers)): ?>
                    <div style="color:#999; padding:10px;"><?php echo app_h($tr('لا يوجد مستخدمون متاحون للإسناد حالياً.', 'No users are currently available for assignment.')); ?></div>
                <?php else: ?>
                    <?php foreach ($teamUsers as $member): ?>
                        <div class="team-item">
                            <label class="team-user-label">
                                <input type="checkbox" name="team_user_ids[]" value="<?php echo (int)$member['id']; ?>" onchange="toggleTeamRole(this)">
                                <span>
                                    <?php echo app_h((string)$member['full_name']); ?>
                                    <small>(<?php echo app_h((string)$member['role']); ?>)</small>
                                </span>
                            </label>
                            <select name="team_roles[<?php echo (int)$member['id']; ?>]" class="team-role-select" disabled>
                                <option value="member"><?php echo app_h($tr('عضو فريق', 'Team Member')); ?></option>
                                <option value="reviewer"><?php echo app_h($tr('مراجع', 'Reviewer')); ?></option>
                                <option value="designer"><?php echo app_h($tr('مصمم', 'Designer')); ?></option>
                                <option value="production"><?php echo app_h($tr('إنتاج', 'Production')); ?></option>
                                <option value="finance"><?php echo app_h($tr('مالي', 'Finance')); ?></option>
                            </select>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
                <div class="team-confirm-row">
                    <button type="button" class="btn-team-confirm" onclick="confirmTeamSelection()"><?php echo app_h($tr('تأكيد اختيار الأعضاء', 'Confirm Team Selection')); ?></button>
                    <div id="team_summary" class="team-summary"><?php echo app_h($tr('لم يتم اختيار أعضاء بعد.', 'No members selected yet.')); ?></div>
                </div>
            </div>
            <?php endif; ?>

            <div id="sec_design_only" class="dynamic-section">
                <div class="section-header">تفاصيل طلب التصميم</div>
                <div class="grid-row">
                    <div><label>عدد البنود المطلوبة *</label><input type="number" name="design_items_count" value="1"></div>
                </div>
                <label>نطاق التصميم:</label>
                <div class="checkbox-group">
                    <?php foreach ($designScopeCatalog as $designScopeItem): ?>
                        <label class="cb-label"><input type="checkbox" name="design_scope[]" value="<?php echo app_h($designScopeItem); ?>"> <?php echo app_h($designScopeItem); ?></label>
                    <?php endforeach; ?>
                </div>
                <input type="text" name="design_scope_other" placeholder="نطاق إضافي (افصل بفاصلة)">
                <label>مخرجات التسليم:</label>
                <div class="checkbox-group">
                    <?php foreach ($designDeliverablesCatalog as $designDeliverableItem): ?>
                        <label class="cb-label"><input type="checkbox" name="design_deliverables[]" value="<?php echo app_h($designDeliverableItem); ?>"> <?php echo app_h($designDeliverableItem); ?></label>
                    <?php endforeach; ?>
                </div>
                <input type="text" name="design_deliverables_other" placeholder="مخرجات إضافية (افصل بفاصلة)">
            </div>

            <div id="sec_print" class="dynamic-section">
                <div class="section-header">📋 مواصفات الطباعة</div>
                <div class="grid-row">
                    <div><label>الكمية المطلوبة (نسخة/فرخ)</label><input type="number" step="any" name="print_quantity" placeholder="العدد المطلوب"></div>
                </div>
                <div class="grid-row">
                    <div>
                        <label>نوع الورق</label>
                        <select name="paper_type" id="paper_type" onchange="toggleOtherPaper('print')">
                            <?php foreach ($paperCatalog as $paperOption): ?>
                                <option value="<?php echo app_h($paperOption); ?>"><?php echo app_h($paperOption); ?></option>
                            <?php endforeach; ?>
                            <option value="other">--- أخرى (حدد) ---</option>
                        </select>
                        <input type="text" list="materials_catalog" name="paper_type_other" id="paper_type_other" placeholder="اكتب نوع الورق..." style="display:none; margin-top:5px; border-color:#2ecc71;">
                    </div>
                    <div><label>الوزن (جرام)</label><input type="number" step="any" name="paper_weight"></div>
                    <div><label>عدد الألوان</label><input type="text" name="print_colors"></div>
                </div>
                <div class="grid-row">
                    <div><label>مصدر الورق / المورد</label><input type="text" name="paper_source" placeholder="اسم المورد أو المخزن"></div>
                    <div><label>الماكينة</label><input type="text" name="print_machine" placeholder="مثال: Heidelberg SM74"></div>
                    <div><label>الجهة المطبوعة</label><input type="text" name="print_side_note" placeholder="اختياري"></div>
                </div>
                <div class="grid-row">
                    <div><label>مقاس الورق (سم)</label><div style="display:flex; gap:5px;"><input placeholder="عرض" name="paper_w"><input placeholder="طول" name="paper_h"></div></div>
                    <div><label>مقاس القص (سم)</label><div style="display:flex; gap:5px;"><input placeholder="عرض" name="cut_w"><input placeholder="طول" name="cut_h"></div></div>
                </div>
                <div class="grid-row">
                    <div>
                        <label>طريقة الطباعة</label>
                        <select name="print_mode">
                            <option value="وجه واحد">وجه واحد</option>
                            <option value="وجهين">وجهين</option>
                            <option value="طبع وقلب بنسة">طبع وقلب بنسة</option>
                            <option value="طبع وقلب ديل">طبع وقلب ديل</option>
                        </select>
                    </div>
                    <div><label>عدد الزنكات</label><input type="number" step="any" name="zinc_count"></div>
                    <div><label>حالة الزنكات</label><select name="zinc_status"><option>جديدة</option><option>مستخدمة</option></select></div>
                </div>
                <label>الخامات المطلوبة:</label>
                <div class="checkbox-group">
                    <?php foreach (array_slice($materialsCatalog, 0, 18) as $matOption): ?>
                        <label class="cb-label"><input type="checkbox" name="print_materials[]" value="<?php echo app_h($matOption); ?>"> <?php echo app_h($matOption); ?></label>
                    <?php endforeach; ?>
                </div>
                <input type="text" list="materials_catalog" name="print_materials_other" placeholder="خامات إضافية (افصل بينها بفاصلة)">
                <label>العمليات التكميلية:</label>
                <div class="checkbox-group">
                    <label class="cb-label"><input type="checkbox" name="print_finish[]" value="سلفان لامع"> سلفان لامع</label>
                    <label class="cb-label"><input type="checkbox" name="print_finish[]" value="سلفان مط"> سلفان مط</label>
                    <label class="cb-label"><input type="checkbox" name="print_finish[]" value="سبوت يوفي"> سبوت يوفي</label>
                    <label class="cb-label"><input type="checkbox" name="print_finish[]" value="تكسير"> تكسير</label>
                    <label class="cb-label"><input type="checkbox" name="print_finish[]" value="لصق"> لصق</label>
                </div>
                <label>خدمات ما بعد الطباعة:</label>
                <div class="checkbox-group">
                    <?php foreach ($printServicesCatalog as $printServiceItem): ?>
                        <label class="cb-label"><input type="checkbox" name="postpress_services[]" value="<?php echo app_h($printServiceItem); ?>"> <?php echo app_h($printServiceItem); ?></label>
                    <?php endforeach; ?>
                </div>
                <input type="text" name="postpress_other" placeholder="خدمات إضافية (افصل بينها بفاصلة)">
                <textarea name="postpress_details" rows="2" placeholder="تفاصيل ما بعد الطباعة (أماكن البصمة/الكفراج، مقاسات، عدد النسخ...)"></textarea>
            </div>

            <div id="sec_carton" class="dynamic-section">
                <div class="section-header">مواصفات الكرتون</div>
                <div class="grid-row">
                    <div><label>الكمية المطلوبة (علبة)</label><input type="number" step="any" name="carton_quantity" placeholder="العدد المطلوب"></div>
                </div>
                <div class="grid-row">
                    <div>
                        <label>نوع الورق الخارجي</label>
                        <select name="carton_paper_type" id="carton_paper_type" onchange="toggleOtherPaper('carton')">
                            <?php foreach ($paperCatalog as $paperOption): ?>
                                <option value="<?php echo app_h($paperOption); ?>"><?php echo app_h($paperOption); ?></option>
                            <?php endforeach; ?>
                            <option value="other">--- أخرى (حدد) ---</option>
                        </select>
                        <input type="text" list="materials_catalog" name="carton_paper_other" id="carton_paper_other" placeholder="اكتب نوع الورق..." style="display:none; margin-top:5px; border-color:#2ecc71;">
                    </div>
                    <div><label>عدد طبقات الكرتون</label><input type="number" name="carton_layers" placeholder="مثال: 3"></div>
                    <div>
                        <label>نوع الفلوت</label>
                        <select name="carton_flute_type">
                            <option value="">-- اختر --</option>
                            <option value="E-Flute">E-Flute</option>
                            <option value="B-Flute">B-Flute</option>
                            <option value="C-Flute">C-Flute</option>
                            <option value="BC Double Wall">BC Double Wall</option>
                            <option value="Micro Flute">Micro Flute</option>
                        </select>
                    </div>
                </div>
                <label>تفاصيل الطبقات والأوزان:</label>
                <textarea name="carton_details" placeholder="اكتب تفاصيل كل طبقة هنا (مثال: E-Flute + كرافت 150جم)"></textarea>
                <div class="grid-row" style="margin-top:15px;">
                    <div><label>مقاس القص النهائي</label><div style="display:flex; gap:5px;"><input placeholder="عرض" name="carton_cut_w"><input placeholder="طول" name="carton_cut_h"></div></div>
                    <div><label>عدد الزنكات</label><input type="number" step="any" name="carton_zinc_count"></div>
                    <div><label>حالة الزنكات</label><select name="carton_zinc_status"><option>جديدة</option><option>مستخدمة</option></select></div>
                </div>
                <div class="grid-row">
                    <div><label>مقاس العلبة النهائي (طول × عرض × ارتفاع)</label><div style="display:flex; gap:5px;"><input placeholder="L" name="carton_box_l"><input placeholder="W" name="carton_box_w"><input placeholder="H" name="carton_box_h"></div></div>
                    <div><label>نوع / رقم الفورمة</label><input type="text" name="carton_die_type" placeholder="مثال: Die #A-52"></div>
                    <div><label>نوع الغراء أو الإقفال</label><input type="text" name="carton_glue_type" placeholder="Hotmelt / غراء أبيض / قفل أوتوماتيك"></div>
                </div>
                <label>خامات الكرتون:</label>
                <div class="checkbox-group">
                    <?php foreach (array_slice($materialsCatalog, 0, 18) as $matOption): ?>
                        <label class="cb-label"><input type="checkbox" name="carton_materials[]" value="<?php echo app_h($matOption); ?>"> <?php echo app_h($matOption); ?></label>
                    <?php endforeach; ?>
                </div>
                <input type="text" list="materials_catalog" name="carton_materials_other" placeholder="خامات إضافية (افصل بينها بفاصلة)">
                <label>التشطيب:</label>
                <div class="checkbox-group">
                    <label class="cb-label"><input type="checkbox" name="carton_finish[]" value="سلفان"> سلفان</label>
                    <label class="cb-label"><input type="checkbox" name="carton_finish[]" value="بصمة"> بصمة</label>
                    <label class="cb-label"><input type="checkbox" name="carton_finish[]" value="تكسير"> تكسير</label>
                </div>
                <label>خدمات ما بعد التشغيل:</label>
                <div class="checkbox-group">
                    <?php foreach ($cartonServicesCatalog as $cartonServiceItem): ?>
                        <label class="cb-label"><input type="checkbox" name="carton_services[]" value="<?php echo app_h($cartonServiceItem); ?>"> <?php echo app_h($cartonServiceItem); ?></label>
                    <?php endforeach; ?>
                </div>
                <input type="text" name="carton_services_other" placeholder="خدمات إضافية (افصل بينها بفاصلة)">
                <textarea name="carton_finish_details" rows="2" placeholder="تفاصيل التشطيب المطلوبة (مواضع البصمة/الكفراج، عدد نقاط اللصق، تعليمات التجميع...)"></textarea>
            </div>

            <div id="sec_plastic" class="dynamic-section">
                <div class="section-header">مواصفات البلاستيك</div>
                <div class="grid-row">
                    <div><label>الكمية المطلوبة (كجم/قطعة)</label><input type="number" step="any" name="plastic_quantity" placeholder="الوزن أو العدد"></div>
                </div>
                <div class="grid-row">
                    <div>
                        <label>نوع الخامة</label>
                        <select name="plastic_material">
                            <option value="HDPE">هاي (HDPE)</option>
                            <option value="LDPE">لو (LDPE)</option>
                            <option value="PP">PP</option>
                            <option value="BOPP">BOPP</option>
                            <option value="CPP">CPP</option>
                        </select>
                    </div>
                    <div>
                        <label>نوع المنتج</label>
                        <select name="plastic_product_type">
                            <option value="">-- اختر --</option>
                            <option value="شنطة">شنطة</option>
                            <option value="رول تغليف">رول تغليف</option>
                            <option value="أكياس تغليف">أكياس تغليف</option>
                            <option value="أكياس قمامة">أكياس قمامة</option>
                            <option value="شرنك">شرنك</option>
                        </select>
                    </div>
                    <div><label>السمك (ميكرون)</label><input type="number" step="any" name="plastic_microns"></div>
                    <div><label>عرض الفيلم (سم)</label><input type="text" name="film_width"></div>
                </div>
                <div class="grid-row">
                    <div>
                        <label>المعالجة</label>
                        <select name="plastic_treatment">
                            <option value="بدون">بدون</option>
                            <option value="وجه واحد">وجه واحد</option>
                            <option value="وجهين">وجهين</option>
                        </select>
                    </div>
                    <div><label>طول القص</label><input type="text" name="plastic_cut_len"></div>
                    <div>
                        <label>نوع اللحام / القفل</label>
                        <select name="plastic_sealing">
                            <option value="">-- اختر --</option>
                            <option value="لحام جانبي">لحام جانبي</option>
                            <option value="لحام سفلي">لحام سفلي</option>
                            <option value="قفل بسحاب">قفل بسحاب</option>
                            <option value="فالف">فالف</option>
                        </select>
                    </div>
                </div>
                <div class="grid-row">
                    <div><label>عدد السلندرات</label><input type="number" step="any" name="cylinder_count"></div>
                    <div><label>حالتها</label><select name="cylinder_status"><option>جديدة</option><option>مستخدمة</option></select></div>
                    <div><label>نوع اليد / الهاندل</label><input type="text" name="plastic_handle" placeholder="قص يدوي / مقصوص / بدون"></div>
                </div>
                <label>خصائص إضافية:</label>
                <div class="checkbox-group">
                    <?php foreach ($plasticFeaturesCatalog as $plasticFeatureItem): ?>
                        <label class="cb-label"><input type="checkbox" name="plastic_features[]" value="<?php echo app_h($plasticFeatureItem); ?>"> <?php echo app_h($plasticFeatureItem); ?></label>
                    <?php endforeach; ?>
                </div>
                <input type="text" name="plastic_features_other" placeholder="خصائص إضافية (افصل بينها بفاصلة)">
            </div>

            <div id="sec_social" class="dynamic-section">
                <div class="section-header">حملة تسويق إلكتروني</div>
                <div class="grid-row">
                    <div>
                        <label>الهدف من الحملة</label>
                        <select name="campaign_goal" style="border-color:var(--gold);">
                            <option value="Awareness">📢 الوعي بالعلامة التجارية (Awareness)</option>
                            <option value="Engagement">👍 التفاعل (Engagement)</option>
                            <option value="Traffic">زيارات الموقع (Traffic)</option>
                            <option value="Leads">🎯 تجميع بيانات عملاء (Leads)</option>
                            <option value="Sales">💰 مبيعات مباشرة (Sales)</option>
                            <option value="App">📲 تحميل تطبيق (App Promotion)</option>
                        </select>
                    </div>
                    <div>
                        <label>عدد البوستات/الفيديوهات</label>
                        <input type="number" name="social_items_count" value="4">
                    </div>
                </div>

                <label style="margin-bottom:15px; display:block;">المنصات المستهدفة (اختر ما يناسبك):</label>
                <div class="checkbox-group" style="margin-bottom:20px;">
                    <?php foreach ($socialPlatformsCatalog as $socialPlatformItem): ?>
                        <label class="cb-label"><input type="checkbox" name="social_platforms[]" value="<?php echo app_h($socialPlatformItem); ?>"> <?php echo app_h($socialPlatformItem); ?></label>
                    <?php endforeach; ?>
                </div>

                <div class="grid-row">
                    <div><label>الجمهور المستهدف (باختصار)</label><input type="text" name="target_audience" placeholder="مثال: نساء، مهتمين بالموضة، الرياض..."></div>
                    <div><label>الميزانية الإعلانية المقترحة (اختياري)</label><input type="text" name="ad_budget" placeholder="مثال: 5000 ريال"></div>
                </div>
                <label>أنواع المحتوى:</label>
                <div class="checkbox-group">
                    <?php foreach ($socialContentCatalog as $socialContentItem): ?>
                        <label class="cb-label"><input type="checkbox" name="social_content_types[]" value="<?php echo app_h($socialContentItem); ?>"> <?php echo app_h($socialContentItem); ?></label>
                    <?php endforeach; ?>
                </div>
                <div class="grid-row">
                    <div><label>وتيرة النشر</label><input type="text" name="social_publish_frequency" placeholder="مثال: 4 منشورات أسبوعياً"></div>
                    <div><label>مؤشرات القياس KPI</label><input type="text" name="social_kpis" placeholder="Reach / CTR / Leads ..."></div>
                </div>
            </div>

            <div id="sec_web" class="dynamic-section">
                <div class="section-header">تطوير موقع إلكتروني</div>
                <div class="grid-row">
                    <div>
                        <label>نوع الموقع</label>
                        <select name="web_type">
                            <option value="تعريفي">تعريفي (Corporate)</option>
                            <option value="متجر">متجر إلكتروني (E-Commerce)</option>
                            <option value="تطبيق">تطبيق جوال</option>
                        </select>
                    </div>
                    <div><label>النطاق (Domain)</label><input type="text" name="web_domain"></div>
                    <div><label>الاستضافة (Hosting)</label><input type="text" name="web_hosting"></div>
                </div>
                <div class="grid-row">
                    <div><label>عدد الصفحات المتوقع</label><input type="number" name="web_pages_count" min="1" placeholder="مثال: 8"></div>
                    <div><label>تكاملات مطلوبة</label><input type="text" name="web_integrations" placeholder="دفع إلكتروني، واتساب API، CRM..."></div>
                </div>
                <label>مزايا الموقع:</label>
                <div class="checkbox-group">
                    <?php foreach ($webFeaturesCatalog as $webFeatureItem): ?>
                        <label class="cb-label"><input type="checkbox" name="web_features[]" value="<?php echo app_h($webFeatureItem); ?>"> <?php echo app_h($webFeatureItem); ?></label>
                    <?php endforeach; ?>
                </div>
                <label>الثيم / الشكل المطلوب</label>
                <textarea name="web_theme" rows="2"></textarea>
            </div>

            <div id="sec_generic" class="dynamic-section">
                <div class="section-header">بيانات عملية مخصصة</div>
                <div class="grid-row">
                    <div><label>الكمية</label><input type="number" name="generic_quantity" value="1" min="1"></div>
                    <div><label>نطاق التنفيذ</label><input type="text" name="generic_scope" placeholder="مثال: تنفيذ حملة ميدانية أو تجهيز فعالية"></div>
                </div>
                <label>تفاصيل العملية</label>
                <textarea name="generic_details" rows="3" placeholder="أدخل أي تفاصيل مخصصة لهذا النوع من العمليات..."></textarea>
            </div>

            <div class="section-header"><i class="fa-solid fa-paperclip"></i> 5. مرفقات وملاحظات</div>
            <div class="grid-row">
                <div><label>ملفات مساعدة (يمكن اختيار أكثر من ملف)</label><input type="file" name="attachment[]" multiple></div>
            </div>
            <label>ملاحظات عامة</label>
            <textarea name="notes" rows="3" placeholder="أي تفاصيل إضافية..."></textarea>

            <datalist id="materials_catalog">
                <?php foreach ($materialsCatalog as $matItem): ?>
                    <option value="<?php echo app_h($matItem); ?>"></option>
                <?php endforeach; ?>
            </datalist>

            <button type="submit" name="save_job" class="btn-royal"><?php echo app_h($tr('حفظ أمر الشغل', 'Save work order')); ?></button>
        </form>
    </div>
    </div>
</div>

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<script>
    const txtTeamSelectionChanged = <?php echo json_encode($tr('تم تغيير الاختيارات. اضغط "تأكيد اختيار الأعضاء".', 'Selection changed. Click "Confirm team selection".'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
    const txtNoMembersSelected = <?php echo json_encode($tr('لم يتم اختيار أعضاء بعد.', 'No members selected yet.'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
    const txtDefaultTeamRole = <?php echo json_encode($tr('عضو فريق', 'Team member'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
    const txtTeamConfirmedPrefix = <?php echo json_encode($tr('تم تأكيد الفريق: ', 'Team confirmed: '), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
    const txtConfirmMembersBeforeLaunch = <?php echo json_encode($tr('يرجى الضغط على زر "تأكيد اختيار الأعضاء" قبل إطلاق أمر الشغل.', 'Please click "Confirm team selection" before launching the work order.'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
    const txtJobTypePlaceholder = <?php echo json_encode($tr('-- حدد القسم --', '-- Select Section --'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;

    function initJobTypePicker() {
        var shell = document.getElementById('job_type_shell');
        var nativeSelect = document.getElementById('job_type');
        var trigger = document.getElementById('job_type_trigger');
        var triggerLabel = trigger ? trigger.querySelector('.select-trigger-label') : null;
        var panel = document.getElementById('job_type_panel');
        if (!shell || !nativeSelect || !trigger || !triggerLabel || !panel) return;

        function syncLabel() {
            var selectedOption = nativeSelect.options[nativeSelect.selectedIndex];
            var selectedValue = nativeSelect.value;
            triggerLabel.textContent = selectedOption && selectedValue ? selectedOption.text : txtJobTypePlaceholder;
            panel.querySelectorAll('.select-option').forEach(function(optionButton) {
                optionButton.classList.toggle('is-selected', optionButton.dataset.value === selectedValue);
            });
        }

        function closePanel() {
            shell.classList.remove('open');
            trigger.setAttribute('aria-expanded', 'false');
        }

        trigger.addEventListener('click', function() {
            var isOpen = shell.classList.toggle('open');
            trigger.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
        });

        panel.querySelectorAll('.select-option').forEach(function(optionButton) {
            optionButton.addEventListener('click', function() {
                nativeSelect.value = optionButton.dataset.value || '';
                syncLabel();
                closePanel();
                showSection();
            });
        });

        document.addEventListener('click', function(event) {
            if (!shell.contains(event.target)) {
                closePanel();
            }
        });

        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                closePanel();
            }
        });

        syncLabel();
    }

    function showSection() {
        document.querySelectorAll('.dynamic-section').forEach(el => el.style.display = 'none');
        document.getElementById('design_toggle').style.display = 'none';
        
        var type = document.getElementById('job_type').value;
        
        if(type == 'design_only') document.getElementById('sec_design_only').style.display = 'block';
        else if(type == 'print') { document.getElementById('sec_print').style.display = 'block'; document.getElementById('design_toggle').style.display = 'block'; }
        else if(type == 'carton') { document.getElementById('sec_carton').style.display = 'block'; document.getElementById('design_toggle').style.display = 'block'; }
        else if(type == 'plastic') { document.getElementById('sec_plastic').style.display = 'block'; document.getElementById('design_toggle').style.display = 'block'; }
        else if(type == 'social') document.getElementById('sec_social').style.display = 'block';
        else if(type == 'web') document.getElementById('sec_web').style.display = 'block';
        else if(type) document.getElementById('sec_generic').style.display = 'block';
    }

    function toggleOtherPaper(section) {
        if(section === 'print') {
            var val = document.getElementById('paper_type').value;
            document.getElementById('paper_type_other').style.display = (val === 'other') ? 'block' : 'none';
        } else if (section === 'carton') {
            var val = document.getElementById('carton_paper_type').value;
            document.getElementById('carton_paper_other').style.display = (val === 'other') ? 'block' : 'none';
        }
    }

    function toggleTeamRole(checkbox) {
        var row = checkbox.closest('.team-item');
        if (!row) return;
        var roleSelect = row.querySelector('.team-role-select');
        if (!roleSelect) return;
        roleSelect.disabled = !checkbox.checked;
        if (!checkbox.checked) {
            roleSelect.value = 'member';
        }
        var confirmedInput = document.getElementById('team_selection_confirmed');
        if (confirmedInput) {
            confirmedInput.value = '0';
        }
        var summary = document.getElementById('team_summary');
        if (summary) {
            summary.textContent = txtTeamSelectionChanged;
        }
    }

    function confirmTeamSelection() {
        var selected = [];
        document.querySelectorAll('input[name="team_user_ids[]"]:checked').forEach(function(cb) {
            var row = cb.closest('.team-item');
            if (!row) return;
            var label = row.querySelector('.team-user-label span');
            var role = row.querySelector('.team-role-select');
            var labelText = label ? label.textContent.trim() : ('User #' + cb.value);
            var roleText = role ? role.options[role.selectedIndex].text : txtDefaultTeamRole;
            selected.push(labelText + ' [' + roleText + ']');
        });
        var confirmedInput = document.getElementById('team_selection_confirmed');
        var summary = document.getElementById('team_summary');
        if (selected.length === 0) {
            if (summary) summary.textContent = txtNoMembersSelected;
            if (confirmedInput) confirmedInput.value = '0';
            return;
        }
        if (summary) summary.textContent = txtTeamConfirmedPrefix + selected.join('، ');
        if (confirmedInput) confirmedInput.value = '1';
    }

    function validateJobForm() {
        var selectedMembers = document.querySelectorAll('input[name="team_user_ids[]"]:checked').length;
        var confirmedInput = document.getElementById('team_selection_confirmed');
        if (selectedMembers > 0 && confirmedInput && confirmedInput.value !== '1') {
            alert(txtConfirmMembersBeforeLaunch);
            return false;
        }
        return true;
    }

    document.addEventListener('DOMContentLoaded', function() {
        initJobTypePicker();
        showSection();
    });
</script>

<?php include 'footer.php'; ob_end_flush(); ?>
