<?php
require_once __DIR__ . '/auth.php';

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (!app_verify_csrf($_POST['_csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'invalid_csrf'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (!function_exists('ai_text_clean')) {
    function ai_text_clean(string $value): string
    {
        $value = trim(preg_replace('/\s+/u', ' ', $value));
        return mb_substr($value, 0, 1200);
    }
}

if (!function_exists('ai_text_lines')) {
    function ai_text_lines(array $lines): string
    {
        $clean = [];
        foreach ($lines as $line) {
            $line = trim((string)$line);
            if ($line !== '') {
                $clean[] = $line;
            }
        }
        return implode("\n", $clean);
    }
}

if (!function_exists('ai_cycle_pick')) {
    function ai_cycle_pick(array $items, int $index): string
    {
        if (empty($items)) {
            return '';
        }
        return (string)$items[$index % count($items)];
    }
}

if (!function_exists('ai_current_lang_is_en')) {
    function ai_current_lang_is_en(mysqli $conn): bool
    {
        return function_exists('app_current_lang') && app_current_lang($conn) === 'en';
    }
}

if (!function_exists('ai_job_row')) {
    function ai_job_row(mysqli $conn, int $jobId): ?array
    {
        $stmt = $conn->prepare("
            SELECT j.*, COALESCE(c.name, '') AS client_name
            FROM job_orders j
            LEFT JOIN clients c ON c.id = j.client_id
            WHERE j.id = ?
            LIMIT 1
        ");
        $stmt->bind_param('i', $jobId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }
}

if (!function_exists('ai_job_seed')) {
    function ai_job_seed(array $job, string $seed = ''): string
    {
        $parts = [];
        $seed = ai_text_clean($seed);
        if ($seed !== '') {
            $parts[] = $seed;
        }
        $jobName = ai_text_clean((string)($job['job_name'] ?? ''));
        if ($jobName !== '') {
            $parts[] = $jobName;
        }
        $jobDetails = ai_text_clean((string)($job['job_details'] ?? ''));
        if ($jobDetails !== '') {
            $parts[] = $jobDetails;
        }
        $clientName = ai_text_clean((string)($job['client_name'] ?? ''));
        if ($clientName !== '') {
            $parts[] = $clientName;
        }
        return implode(' | ', array_unique(array_filter($parts)));
    }
}

if (!function_exists('ai_social_ideas')) {
    function ai_social_ideas(array $job, string $seed, int $count, bool $isEnglish): array
    {
        $hooksAr = ['سؤال مباشر', 'رقم لافت', 'مشكلة شائعة', 'مقارنة سريعة', 'نصيحة عملية', 'قبل/بعد'];
        $hooksEn = ['Direct question', 'Strong number', 'Common pain point', 'Quick comparison', 'Practical tip', 'Before / after'];
        $anglesAr = ['تعليمي', 'بيعي ناعم', 'إثبات ثقة', 'توعوي', 'تحفيزي', 'عملي'];
        $anglesEn = ['Educational', 'Soft selling', 'Trust proof', 'Awareness', 'Motivational', 'Practical'];
        $ctaAr = ['اطلب التفاصيل', 'راسلنا الآن', 'احجز استشارة', 'اطلب عرض سعر', 'ابدأ معنا'];
        $ctaEn = ['Ask for details', 'Message us now', 'Book a consultation', 'Request a quote', 'Start with us'];
        $subject = ai_job_seed($job, $seed);
        $items = [];
        $count = max(1, min(12, $count));
        for ($i = 0; $i < $count; $i++) {
            if ($isEnglish) {
                $items[] = ai_text_lines([
                    'Post #' . ($i + 1),
                    'Hook: ' . ai_cycle_pick($hooksEn, $i),
                    'Angle: ' . ai_cycle_pick($anglesEn, $i),
                    'Core idea: Build a post around ' . $subject . ' with a clear, focused message and one main benefit.',
                    'CTA: ' . ai_cycle_pick($ctaEn, $i),
                ]);
            } else {
                $items[] = ai_text_lines([
                    'فكرة #' . ($i + 1),
                    'الافتتاحية: ' . ai_cycle_pick($hooksAr, $i),
                    'الزاوية: ' . ai_cycle_pick($anglesAr, $i),
                    'الفكرة الأساسية: بوست يركز على "' . $subject . '" برسالة واحدة واضحة وفائدة مباشرة للعميل.',
                    'الدعوة للإجراء: ' . ai_cycle_pick($ctaAr, $i),
                ]);
            }
        }
        return $items;
    }
}

if (!function_exists('ai_social_designs')) {
    function ai_social_designs(array $job, string $seed, int $count, bool $isEnglish): array
    {
        $stylesAr = ['مودرن نظيف', 'فاخر داكن', 'جريء عالي التباين', 'هادئ احترافي', 'شبكي منظم'];
        $stylesEn = ['Clean modern', 'Dark premium', 'Bold high-contrast', 'Calm professional', 'Structured grid'];
        $elementsAr = ['عنوان قوي', 'صورة منتج أو خدمة', 'أيقونات بسيطة', 'مساحة بيضاء متوازنة', 'زر دعوة واضح'];
        $elementsEn = ['Strong headline', 'Product/service visual', 'Simple icons', 'Balanced whitespace', 'Clear CTA'];
        $subject = ai_job_seed($job, $seed);
        $items = [];
        $count = max(1, min(12, $count));
        for ($i = 0; $i < $count; $i++) {
            if ($isEnglish) {
                $items[] = ai_text_lines([
                    'Design prompt #' . ($i + 1),
                    'Style: ' . ai_cycle_pick($stylesEn, $i),
                    'Visual direction: Build a social design for ' . $subject . ' with one clear focal point.',
                    'Key elements: ' . ai_cycle_pick($elementsEn, $i) . ', concise benefit line, strong CTA.',
                    'Output note: Keep mobile readability high and avoid visual clutter.',
                ]);
            } else {
                $items[] = ai_text_lines([
                    'تصور تصميم #' . ($i + 1),
                    'الستايل: ' . ai_cycle_pick($stylesAr, $i),
                    'الاتجاه البصري: تصميم سوشيال عن "' . $subject . '" مع نقطة تركيز واحدة واضحة.',
                    'العناصر الأساسية: ' . ai_cycle_pick($elementsAr, $i) . ' مع سطر فائدة مختصر وCTA واضح.',
                    'ملاحظة تنفيذ: حافظ على وضوح القراءة على الموبايل وتجنب الزحام البصري.',
                ]);
            }
        }
        return $items;
    }
}

if (!function_exists('ai_design_brief')) {
    function ai_design_brief(array $job, string $seed, bool $isEnglish): array
    {
        $subject = ai_job_seed($job, $seed);
        if ($isEnglish) {
            return [ai_text_lines([
                'Creative brief',
                'Objective: Deliver a design direction for ' . $subject . ' with a clear commercial goal.',
                'Audience: Define the main buyer/user segment and the message they should notice first.',
                'Mood: Premium, clear, focused, and easy to consume on mobile.',
                'Visual system: Define colors, typography, image style, and hierarchy before execution.',
                'Deliverables: Main concept, 2 quick variations, editable source, export-ready assets.',
            ])];
        }
        return [ai_text_lines([
            'ملخص إبداعي',
            'الهدف: إخراج اتجاه تصميم واضح لـ "' . $subject . '" يخدم الهدف التجاري مباشرة.',
            'الجمهور: حدد الشريحة الأساسية وما الذي يجب أن تلتقطه في أول 3 ثوان.',
            'الطابع: واضح، احترافي، مركز، ومناسب للعرض على الموبايل.',
            'النظام البصري: تحديد الألوان والخطوط والصور والتسلسل البصري قبل التنفيذ.',
            'المخرجات: فكرة رئيسية + بديلان سريعان + ملف مفتوح + مخرجات جاهزة للتسليم.',
        ])];
    }
}

if (!function_exists('ai_web_requirements_plan')) {
    function ai_web_requirements_plan(array $job, string $seed, bool $isEnglish): array
    {
        $subject = ai_job_seed($job, $seed);
        if ($isEnglish) {
            return [ai_text_lines([
                'Website scope plan',
                'Project: ' . $subject,
                'Pages: Home, About, Services/Products, Contact, FAQ, policy pages as needed.',
                'Core features: fast mobile-first UI, lead forms, CTA tracking, content sections, SEO basics, analytics.',
                'Admin needs: editable hero, sections, media, forms, SEO fields, contact data, branding controls.',
                'Integrations: WhatsApp, maps, social links, payment or booking if the project needs them.',
                'Launch checklist: responsive QA, content QA, speed pass, SSL, tracking, backups.',
            ])];
        }
        return [ai_text_lines([
            'خطة متطلبات الموقع',
            'المشروع: ' . $subject,
            'الصفحات: الرئيسية، من نحن، الخدمات/المنتجات، تواصل، الأسئلة الشائعة، وصفحات السياسات عند الحاجة.',
            'الوظائف الأساسية: واجهة سريعة Mobile-first، نماذج تواصل، تتبع CTA، أقسام محتوى، أساسيات SEO، تحليلات.',
            'احتياجات الإدارة: تعديل الهيرو، الأقسام، الوسائط، النماذج، حقول SEO، بيانات التواصل، والهوية البصرية.',
            'التكاملات: واتساب، خرائط، روابط السوشيال، والدفع أو الحجز إذا كان المشروع يحتاجها.',
            'قائمة الإطلاق: اختبار الموبايل، مراجعة المحتوى، فحص السرعة، SSL، التتبع، والنسخ الاحتياطي.',
        ])];
    }
}

if (!function_exists('ai_web_dev_plan')) {
    function ai_web_dev_plan(array $job, string $seed, bool $isEnglish): array
    {
        $subject = ai_job_seed($job, $seed);
        if ($isEnglish) {
            return [ai_text_lines([
                'Development plan',
                'Project: ' . $subject,
                'Phase 1: Prepare data model, page map, and shared components.',
                'Phase 2: Implement responsive UI and reusable content sections.',
                'Phase 3: Connect forms, media, SEO fields, and dashboard controls.',
                'Phase 4: QA for mobile, performance, permissions, and launch readiness.',
                'Technical note: keep code modular, avoid hardcoded content, and preserve simple content editing.',
            ])];
        }
        return [ai_text_lines([
            'خطة التطوير',
            'المشروع: ' . $subject,
            'المرحلة 1: تجهيز نموذج البيانات وخريطة الصفحات والمكونات المشتركة.',
            'المرحلة 2: تنفيذ الواجهة المتجاوبة وبناء أقسام محتوى قابلة لإعادة الاستخدام.',
            'المرحلة 3: ربط النماذج والوسائط وحقول SEO ولوحة التحكم.',
            'المرحلة 4: اختبار الموبايل والأداء والصلاحيات والجاهزية للإطلاق.',
            'ملاحظة تقنية: حافظ على modular code وتجنب المحتوى الصلب داخل القالب.',
        ])];
    }
}

if (!function_exists('ai_generic_stage_plan')) {
    function ai_generic_stage_plan(array $job, string $seed, string $stageLabel, bool $isEnglish): array
    {
        $subject = ai_job_seed($job, $seed);
        $stageLabel = ai_text_clean($stageLabel);
        if ($isEnglish) {
            return [ai_text_lines([
                'Stage plan: ' . ($stageLabel !== '' ? $stageLabel : 'Current stage'),
                'Job: ' . $subject,
                'Inputs: confirm all required files, references, and approvals before execution.',
                'Execution: complete one clear deliverable for this stage and document what changed.',
                'Review: note risks, blockers, dependencies, and the exact next action.',
                'Output: save a concise stage note and attach supporting files.',
            ])];
        }
        return [ai_text_lines([
            'خطة المرحلة: ' . ($stageLabel !== '' ? $stageLabel : 'المرحلة الحالية'),
            'العملية: ' . $subject,
            'المدخلات: تأكد من اكتمال الملفات المرجعية والموافقات المطلوبة قبل التنفيذ.',
            'التنفيذ: أنجز مخرجًا واحدًا واضحًا للمرحلة وسجل ما الذي تم تغييره.',
            'المراجعة: دوّن المخاطر والعوائق والاعتماديات والخطوة التالية بدقة.',
            'المخرجات: احفظ ملاحظة مرحلة مختصرة وأرفق الملفات الداعمة.',
        ])];
    }
}

$jobId = (int)($_POST['job_id'] ?? 0);
$context = trim((string)($_POST['context'] ?? ''));
$seedText = trim((string)($_POST['seed_text'] ?? ''));
$stageLabel = trim((string)($_POST['stage_label'] ?? ''));
$itemCount = max(1, min(12, (int)($_POST['item_count'] ?? 1)));

if ($jobId <= 0 || $context === '') {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'invalid_request'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$job = ai_job_row($conn, $jobId);
if (!$job) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'job_not_found'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$isEnglish = ai_current_lang_is_en($conn);
$suggestions = [];

switch ($context) {
    case 'social_ideas':
        $suggestions = ai_social_ideas($job, $seedText, $itemCount, $isEnglish);
        break;
    case 'social_designs':
        $suggestions = ai_social_designs($job, $seedText, $itemCount, $isEnglish);
        break;
    case 'design_brief':
        $suggestions = ai_design_brief($job, $seedText, $isEnglish);
        break;
    case 'design_prompts':
        $suggestions = ai_social_designs($job, $seedText, $itemCount, $isEnglish);
        break;
    case 'web_requirements_plan':
        $suggestions = ai_web_requirements_plan($job, $seedText, $isEnglish);
        break;
    case 'web_development_plan':
        $suggestions = ai_web_dev_plan($job, $seedText, $isEnglish);
        break;
    case 'generic_stage_plan':
        $suggestions = ai_generic_stage_plan($job, $seedText, $stageLabel, $isEnglish);
        break;
    default:
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'unsupported_context'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
}

echo json_encode([
    'ok' => true,
    'message' => $isEnglish ? 'AI helper suggestions are ready.' : 'تم تجهيز اقتراحات المساعد.',
    'suggestions' => array_values(array_filter($suggestions)),
    'combined_text' => implode("\n\n", array_values(array_filter($suggestions))),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
exit;
