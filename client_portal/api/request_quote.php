<?php
// client_portal/api/request_quote.php
ob_start();
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/db_connect.php';

api_require_post_csrf();
$clientId = api_require_login();
api_rate_limit_or_fail('client_portal_request_quote', 20, 600, api_rate_limit_scope('request_quote'));

function portal_quote_clean_nested($value, int $maxLen = 2500)
{
    if (is_array($value)) {
        $clean = [];
        foreach ($value as $key => $item) {
            $safeKey = is_string($key) ? preg_replace('/[^a-zA-Z0-9_\\-]/', '', $key) : $key;
            $cleaned = portal_quote_clean_nested($item, $maxLen);
            if ($cleaned === '' || $cleaned === [] || $cleaned === null) {
                continue;
            }
            $clean[$safeKey] = $cleaned;
        }
        return $clean;
    }

    return api_clean_text($value, $maxLen);
}

function portal_quote_table_has_column(PDO $pdo, string $table, string $column): bool
{
    static $cache = [];
    $key = $table . '.' . $column;
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    try {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
        $stmt->execute([$column]);
        $cache[$key] = (bool)$stmt->fetch();
    } catch (Throwable $e) {
        $cache[$key] = false;
    }
    return $cache[$key];
}

function portal_quote_value_to_text($value): string
{
    if (is_array($value)) {
        $isList = array_keys($value) === range(0, count($value) - 1);
        if ($isList) {
            return implode('، ', array_filter(array_map('portal_quote_value_to_text', $value)));
        }
        $parts = [];
        foreach ($value as $key => $item) {
            $text = portal_quote_value_to_text($item);
            if ($text !== '') {
                $parts[] = $key . ': ' . $text;
            }
        }
        return implode("\n", $parts);
    }
    return trim((string)$value);
}

function portal_quote_labels(): array
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

function portal_quote_filter_brief(array $brief, string $serviceKey): array
{
    $allowed = [];
    if (isset($brief['common']) && is_array($brief['common'])) {
        $commonKeys = ['timeline', 'request_priority', 'approval_flow'];
        $allowed['common'] = array_intersect_key($brief['common'], array_flip($commonKeys));
    }
    if (isset($brief[$serviceKey]) && is_array($brief[$serviceKey])) {
        $allowed[$serviceKey] = $brief[$serviceKey];
    }
    return $allowed;
}

function portal_quote_group_title(string $group): string
{
    $titles = [
        'common' => 'البيانات العامة',
        'web' => 'بريف موقع ويب / منصة رقمية',
        'print' => 'بريف الطباعة',
        'carton' => 'بريف الكرتون والتغليف',
        'plastic' => 'بريف البلاستيك',
        'social' => 'بريف السوشيال ميديا',
        'design_only' => 'بريف التصميم',
    ];
    return $titles[$group] ?? $group;
}

function portal_quote_service_key(string $service): string
{
    if (strpos($service, 'موقع') !== false || strpos($service, 'برمجة') !== false || stripos($service, 'web') !== false) {
        return 'web';
    }
    if (strpos($service, 'كرتون') !== false || strpos($service, 'علب') !== false) {
        return 'carton';
    }
    if (strpos($service, 'بلاستيك') !== false || strpos($service, 'أكياس') !== false) {
        return 'plastic';
    }
    if (strpos($service, 'سوشيال') !== false || strpos($service, 'تسويق') !== false) {
        return 'social';
    }
    if (strpos($service, 'تصميم') !== false) {
        return 'design_only';
    }
    return 'print';
}

function portal_quote_format_notes(string $service, string $jobName, float $qty, string $details, array $brief, array $files): string
{
    $labels = portal_quote_labels();
    $lines = [];
    $lines[] = 'طلب عرض سعر وارد من بوابة العميل';
    $lines[] = 'نوع الخدمة: ' . $service;
    $lines[] = 'عنوان الطلب: ' . $jobName;
    $lines[] = 'الكمية التقريبية: ' . rtrim(rtrim(number_format($qty, 2, '.', ''), '0'), '.');

    if ($details !== '') {
        $lines[] = '';
        $lines[] = 'المواصفات الأساسية:';
        $lines[] = $details;
    }

    foreach ($brief as $group => $fields) {
        if ($group === '_meta' || !is_array($fields) || empty($fields)) {
            continue;
        }
        $lines[] = '';
        $lines[] = '[' . portal_quote_group_title((string)$group) . ']';
        foreach ($fields as $key => $value) {
            $text = portal_quote_value_to_text($value);
            if ($text === '') {
                continue;
            }
            $label = $labels[$group][$key] ?? $key;
            $lines[] = $label . ': ' . $text;
        }
    }

    if (!empty($files)) {
        $lines[] = '';
        $lines[] = '[المرفقات]';
        foreach ($files as $file) {
            $lines[] = '- ' . ($file['original_name'] ?? basename((string)($file['path'] ?? ''))) . ' | ' . ($file['path'] ?? '');
        }
    }

    return implode("\n", $lines);
}

function portal_quote_store_files(array $filesBag): array
{
    $stored = [];
    if (!isset($filesBag['name'])) {
        return $stored;
    }

    $names = is_array($filesBag['name']) ? $filesBag['name'] : [$filesBag['name']];
    $tmps = is_array($filesBag['tmp_name']) ? $filesBag['tmp_name'] : [$filesBag['tmp_name']];
    $sizes = is_array($filesBag['size']) ? $filesBag['size'] : [$filesBag['size']];
    $errors = is_array($filesBag['error']) ? $filesBag['error'] : [$filesBag['error']];
    $types = is_array($filesBag['type'] ?? null) ? $filesBag['type'] : [$filesBag['type'] ?? ''];

    $allowed = ['pdf', 'png', 'jpg', 'jpeg', 'webp', 'gif', 'ai', 'psd', 'cdr', 'doc', 'docx', 'xls', 'xlsx', 'zip', 'rar'];
    $maxFiles = 8;
    $maxSize = 15 * 1024 * 1024;
    $maxTotal = 60 * 1024 * 1024;
    $active = 0;
    $total = 0;

    for ($i = 0; $i < count($names); $i++) {
        $error = (int)($errors[$i] ?? UPLOAD_ERR_NO_FILE);
        if ($error === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        $active++;
        $total += (int)($sizes[$i] ?? 0);
    }
    if ($active > $maxFiles) {
        api_json(['status' => 'error', 'message' => 'الحد الأقصى للمرفقات هو 8 ملفات.'], 422);
    }
    if ($total > $maxTotal) {
        api_json(['status' => 'error', 'message' => 'إجمالي حجم المرفقات يتجاوز 60MB.'], 422);
    }

    for ($i = 0; $i < count($names); $i++) {
        $originalName = (string)($names[$i] ?? '');
        $tmp = (string)($tmps[$i] ?? '');
        $size = (int)($sizes[$i] ?? 0);
        $error = (int)($errors[$i] ?? UPLOAD_ERR_NO_FILE);
        if ($error === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        if ($error !== UPLOAD_ERR_OK || $originalName === '' || $tmp === '' || $size <= 0 || $size > $maxSize) {
            api_json(['status' => 'error', 'message' => 'تعذر رفع أحد المرفقات. تحقق من الحجم والنوع ثم أعد المحاولة.'], 422);
        }
        $ext = strtolower((string)pathinfo($originalName, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed, true)) {
            api_json(['status' => 'error', 'message' => 'نوع ملف غير مدعوم: ' . api_clean_text($originalName, 120)], 422);
        }

        $singleFile = [
            'name' => $originalName,
            'type' => (string)($types[$i] ?? ''),
            'tmp_name' => $tmp,
            'error' => $error,
            'size' => $size,
        ];
        $storedFile = app_store_uploaded_file($singleFile, [
            'dir' => dirname(__DIR__, 2) . '/uploads/quote_requests',
            'prefix' => 'quote_request_',
            'max_size' => $maxSize,
            'allowed_extensions' => $allowed,
        ]);
        if (empty($storedFile['ok'])) {
            api_json(['status' => 'error', 'message' => 'تعذر حفظ أحد المرفقات.'], 422);
        }

        $dbPath = ltrim(str_replace(dirname(__DIR__, 2) . '/', '', (string)$storedFile['path']), '/');
        $stored[] = [
            'path' => $dbPath,
            'original_name' => api_clean_text($originalName, 180),
            'size' => $size,
            'extension' => $ext,
        ];
    }

    return $stored;
}

$jobName = api_clean_text($_POST['job_name'] ?? 'طلب عرض سعر', 180);
$service = api_clean_text($_POST['service_type'] ?? 'طباعة عامة', 100);
$qty = (float)($_POST['quantity'] ?? 1);
$qty = ($qty > 0 && $qty <= 100000000) ? $qty : 1;
$details = api_clean_text($_POST['details'] ?? '', 4000);
$brief = isset($_POST['brief']) && is_array($_POST['brief'])
    ? (array)portal_quote_clean_nested($_POST['brief'], 3000)
    : [];
$serviceKey = portal_quote_service_key($service);
$brief = portal_quote_filter_brief($brief, $serviceKey);
$token = bin2hex(random_bytes(16));

if ($jobName === '') {
    api_json(['status' => 'error', 'message' => 'اسم الطلب مطلوب.'], 422);
}

if ($details === '') {
    api_json(['status' => 'error', 'message' => 'يرجى إدخال المواصفات الأساسية.'], 422);
}

$files = isset($_FILES['attachment']) ? portal_quote_store_files($_FILES['attachment']) : [];
$brief['_meta'] = [
    'service_key' => $serviceKey,
    'submitted_from' => 'client_portal',
    'submitted_at' => date('Y-m-d H:i:s'),
];

$fullNotes = portal_quote_format_notes($service, $jobName, $qty, $details, $brief, $files);
$itemsJson = json_encode([
    'source' => 'client_portal_quote_request',
    'service_type' => $service,
    'service_key' => $serviceKey,
    'quantity' => $qty,
    'brief' => $brief,
    'attachments' => $files,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

try {
    $columns = ['client_id', 'created_at', 'valid_until', 'total_amount', 'status', 'notes', 'access_token'];
    $values = [$clientId, date('Y-m-d'), date('Y-m-d', strtotime('+7 days')), 0.00, 'pending', $fullNotes, $token];
    $placeholders = ['?', '?', '?', '?', '?', '?', '?'];

    if (portal_quote_table_has_column($pdo, 'quotes', 'quote_kind')) {
        $columns[] = 'quote_kind';
        $values[] = 'request';
        $placeholders[] = '?';
    }
    if (portal_quote_table_has_column($pdo, 'quotes', 'items_json')) {
        $columns[] = 'items_json';
        $values[] = $itemsJson;
        $placeholders[] = '?';
    }

    $sql = 'INSERT INTO quotes (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $placeholders) . ')';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($values);
    $quoteId = (int)$pdo->lastInsertId();

    api_json([
        'status' => 'success',
        'message' => 'تم استلام طلب عرض السعر رقم #' . $quoteId . ' وسيتم مراجعته من الفريق المختص.',
        'quote_id' => $quoteId,
        'attachments_count' => count($files),
    ]);
} catch (PDOException $e) {
    error_log('Request Quote Error: ' . $e->getMessage());
    api_json(['status' => 'error', 'message' => 'تعذر إرسال طلب عرض السعر حالياً.'], 500);
}
