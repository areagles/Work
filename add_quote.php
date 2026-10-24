<?php
// add_quote.php - النسخة الملكية النهائية (تصحيح التنسيق الإجباري)
ob_start();
require 'auth.php'; 
require 'config.php'; 
require 'header.php';
require_once __DIR__ . '/quote_growth_runtime.php';
app_ensure_quotes_schema($conn);
app_quote_growth_ensure_schema($conn);
$quoteTaxCatalog = app_tax_catalog($conn, true, 'quotes');
$quoteCurrencyOptions = app_quote_currency_options($conn);
$defaultQuoteCurrency = app_quote_default_currency($conn);
$quoteTemplates = app_quote_templates($conn);
$quoteTaxLaws = app_tax_law_catalog($conn, true);
if (empty($quoteTaxLaws)) {
    $quoteTaxLaws = app_tax_default_laws();
}

// 2. مصفوفة الشروط الذكية
$default_terms = [
    'general' => [
        'title' => 'شروط عامة وأساسية',
        'items' => [
            'validity' => 'يسري هذا العرض لمدة {DAYS} أيام من تاريخ إصداره، ويجوز مراجعته بعد انتهاء المدة أو عند حدوث تغير جوهري في أسعار الخامات أو الخدمات.',
            'acceptance' => 'يعد اعتماد العميل للعرض، أو تحويل الدفعة المقدمة، موافقة على البنود والأسعار والمواصفات الموضحة به.',
            'payment' => 'شروط السداد: 50% دفعة مقدمة عند اعتماد العرض، و50% قبل التسليم أو عند الاستلام، ما لم يتم الاتفاق كتابياً على خلاف ذلك.',
            'tax' => 'الأسعار لا تشمل ضريبة القيمة المضافة أو أي رسوم حكومية إلا إذا ورد نص صريح بخلاف ذلك داخل العرض.',
        ],
    ],
    'print' => [
        'title' => 'فنيات الطباعة والتصنيع',
        'items' => [
            'colors' => 'قد يحدث تفاوت مقبول في درجات الألوان بين الشاشة والبروفة والإنتاج النهائي نتيجة اختلاف الخامات وأحبار الطباعة وظروف التشغيل.',
            'proof' => 'تعتمد المواصفات الفنية النهائية بعد موافقة العميل على التصميم أو البروفة أو العينة، وأي تعديل لاحق قد يؤثر على التكلفة ومدة التنفيذ.',
            'tolerance' => 'تخضع كميات المطبوعات والمصنعات لنسبة زيادة أو عجز تشغيلية في حدود ±10%، وتتم المحاسبة على الكمية الفعلية المنتجة عند الحاجة.',
            'materials' => 'قد تختلف درجة لون أو ملمس الخامة اختلافاً طفيفاً بين دفعات التوريد، مع الالتزام بأقرب مطابقة ممكنة للمواصفات المتفق عليها.',
            'storage' => 'في حال تأخر العميل عن الاستلام لمدة تزيد عن 15 يوماً من تاريخ الإخطار بجاهزية الطلب، يحق للشركة احتساب مصاريف تخزين أو أرضيات.',
        ],
    ],
    'delivery' => [
        'title' => 'التسليم والشحن',
        'items' => [
            'lead_time' => 'مدة التنفيذ تبدأ من تاريخ اعتماد العرض واستلام الدفعة المقدمة وكافة الملفات أو البيانات المطلوبة بصورة صالحة للتنفيذ.',
            'delivery_scope' => 'تكاليف النقل أو الشحن أو التحميل غير مشمولة إلا إذا وردت كبند مستقل داخل العرض.',
            'handover' => 'يعتبر توقيع العميل أو من ينوب عنه على سند التسليم إقراراً باستلام الكمية والحالة الظاهرة للمنتج.',
        ],
    ],
    'financial' => [
        'title' => 'بنود مالية إضافية',
        'items' => [
            'currency' => 'في حال إصدار العرض بعملة أجنبية، يتم تثبيت سعر التحويل الموضح في العرض وقت الإصدار، وقد تتم مراجعته إذا تغيرت شروط السداد أو مدة الصلاحية.',
            'bank_fees' => 'أي عمولات بنكية أو مصاريف تحويل أو فروق تحصيل تقع على عاتق العميل ما لم يتم الاتفاق على خلاف ذلك.',
            'late_payment' => 'يحق للشركة تعليق التنفيذ أو التسليم في حال تأخر الدفعات المستحقة عن المواعيد المتفق عليها.',
        ],
    ],
    'digital' => [
        'title' => 'الحلول الرقمية والتصميم',
        'items' => [
            'content' => 'يلتزم العميل بتوفير المحتوى والبيانات والصور والشعارات بجودة مناسبة، وتعد صحة المحتوى المرسل مسؤولية العميل.',
            'revisions' => 'يشمل العرض عدد التعديلات المتفق عليه فقط، وأي تعديلات إضافية أو تغيير في نطاق العمل يتم تسعيرها بشكل مستقل.',
            'rights' => 'تنتقل حقوق استخدام المخرجات النهائية للعميل بعد سداد كامل المستحقات، مع احتفاظ الشركة بحقوق الأدوات أو القوالب أو الأكواد العامة غير المخصصة إن وجدت.',
            'third_party' => 'اشتراكات المنصات أو الاستضافات أو الخطوط أو الصور أو الخدمات الخارجية لا تدخل ضمن السعر إلا إذا ذكرت صراحة داخل العرض.',
        ],
    ],
];

// 3. معالجة الحفظ
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (!app_verify_csrf($_POST['_csrf_token'] ?? '')) {
        http_response_code(403);
        die('Invalid CSRF token');
    }
    $client_id = intval($_POST['client_id'] ?? 0);
    $date = (string)($_POST['date'] ?? date('Y-m-d'));
    $valid = (string)($_POST['valid_until'] ?? date('Y-m-d', strtotime('+7 days')));
    $manual_notes = (string)($_POST['manual_notes'] ?? '');
    $expectedCloseDate = trim((string)($_POST['expected_close_date'] ?? ''));
    $depositRequiredAmount = max(0, (float)($_POST['deposit_required_amount'] ?? 0));
    $quoteKind = (string)($_POST['quote_kind'] ?? 'standard');
    $taxLawKey = strtolower(trim((string)($_POST['tax_law_key'] ?? app_setting_get($conn, 'tax_default_quote_law', 'vat_2016'))));
    if (!preg_match('/^[a-z0-9_]{2,60}$/', $taxLawKey)) {
        $taxLawKey = app_setting_get($conn, 'tax_default_quote_law', 'vat_2016');
    }
    $selectedTaxKeys = isset($_POST['tax_keys']) && is_array($_POST['tax_keys']) ? array_map('strval', $_POST['tax_keys']) : [];
    $currencyCode = app_quote_normalize_currency_code($conn, (string)($_POST['currency_code'] ?? $defaultQuoteCurrency));
    $currencyMeta = app_quote_currency_meta($conn, $currencyCode);
    $currencyLabel = (string)($currencyMeta['label_ar'] ?? $currencyCode);
    $baseCurrencyCode = (string)($currencyMeta['base_currency'] ?? app_quote_default_currency($conn));
    $exchangeRateToBase = max(0, (float)($currencyMeta['rate_to_base'] ?? 1));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        $date = date('Y-m-d');
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $valid)) {
        $valid = date('Y-m-d', strtotime('+7 days'));
    }
    if ($expectedCloseDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $expectedCloseDate)) {
        $expectedCloseDate = '';
    }
    
    // تجميع الشروط
    $terms_text = "";
    if(isset($_POST['terms']) && is_array($_POST['terms'])){
        $terms_text .= "\n\n--- الشروط والأحكام ---\n";
        foreach($_POST['terms'] as $term_content){
            if(!empty(trim($term_content))){
                $terms_text .= "• " . trim($term_content) . "\n";
            }
        }
        $terms_text .= "\n* يعتبر تحويل الدفعة المقدمة بمثابة موافقة نهائية على العرض.";
    }

    $final_notes = $manual_notes . $terms_text;
    $token = bin2hex(random_bytes(32));

    // حساب الإجمالي
    $sub_total = 0;
    $itemsJsonRows = [];
    $itemNames = $_POST['item_name'] ?? [];
    $itemDescriptions = $_POST['item_description'] ?? [];
    $itemQtys = $_POST['qty'] ?? [];
    $itemPrices = $_POST['price'] ?? [];
    $itemUnits = $_POST['unit'] ?? [];
    $itemUnitsOther = $_POST['unit_other'] ?? [];
    if (is_array($itemNames)) {
        $itemCount = count($itemNames);
        for ($i = 0; $i < $itemCount; $i++) {
            $qtyVal = isset($itemQtys[$i]) ? (float)$itemQtys[$i] : 0.0;
            $priceVal = isset($itemPrices[$i]) ? (float)$itemPrices[$i] : 0.0;
            $lineTotal = ($qtyVal * $priceVal);
            $sub_total += $lineTotal;
            $unitRaw = trim((string)($itemUnits[$i] ?? ''));
            $unitOther = trim((string)($itemUnitsOther[$i] ?? ''));
            $unitVal = ($unitRaw === 'other') ? $unitOther : $unitRaw;
            $itemsJsonRows[] = [
                'desc' => trim((string)($itemNames[$i] ?? '')),
                'description' => trim((string)($itemDescriptions[$i] ?? '')),
                'qty' => $qtyVal,
                'unit' => $unitVal,
                'price' => $priceVal,
                'total' => $lineTotal,
            ];
        }
    }
    $taxCalc = app_tax_calculate_document($quoteTaxCatalog, $quoteKind, $sub_total, 0, $selectedTaxKeys);
    $taxTotal = (float)($taxCalc['tax_total'] ?? 0);
    $grand_total = (float)($taxCalc['grand_total'] ?? $sub_total);
    $baseTotalAmount = $exchangeRateToBase > 0 ? round($grand_total * $exchangeRateToBase, 2) : $grand_total;
    $internalApprovalStatus = app_quote_internal_status_for_total($conn, $baseTotalAmount);
    $fulfillmentStage = $depositRequiredAmount > 0 ? 'awaiting_deposit' : 'client_review';
    $expectedCloseDateParam = $expectedCloseDate !== '' ? $expectedCloseDate : null;
    $taxesJson = json_encode(($taxCalc['lines'] ?? []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($taxesJson)) {
        $taxesJson = '[]';
    }
    $itemsJson = json_encode($itemsJsonRows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($itemsJson)) {
        $itemsJson = '[]';
    }

    if ($client_id > 0) {
        try {
            $conn->begin_transaction();

            $stmtQuote = $conn->prepare("
                INSERT INTO quotes (client_id, created_at, valid_until, quote_kind, tax_law_key, total_amount, currency_code, currency_label, base_currency_code, exchange_rate_to_base, base_total_amount, tax_total, taxes_json, items_json, internal_approval_status, fulfillment_stage, deposit_required_amount, expected_close_date, status, notes, access_token)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, ?)
            ");
            $stmtQuote->bind_param("issssdsssdddssssdsss", $client_id, $date, $valid, $quoteKind, $taxLawKey, $grand_total, $currencyCode, $currencyLabel, $baseCurrencyCode, $exchangeRateToBase, $baseTotalAmount, $taxTotal, $taxesJson, $itemsJson, $internalApprovalStatus, $fulfillmentStage, $depositRequiredAmount, $expectedCloseDateParam, $final_notes, $token);
            $stmtQuote->execute();
            $quote_id = (int)$stmtQuote->insert_id;
            $stmtQuote->close();
            app_assign_document_number($conn, 'quotes', $quote_id, 'quote_number', 'quote', $date);
            app_quote_history_add($conn, $quote_id, 'quote_created', 'تم إنشاء عرض السعر', ['currency' => $currencyCode, 'total' => $grand_total, 'internal_approval_status' => $internalApprovalStatus]);
            app_quote_growth_upload_attachments($conn, $quote_id);

            if (is_array($itemNames)) {
                $stmtItem = $conn->prepare("
                    INSERT INTO quote_items (quote_id, item_name, description, quantity, unit, price, total)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");
                $itemCount = count($itemNames);
                for ($i = 0; $i < $itemCount; $i++) {
                    $name = trim((string)($itemNames[$i] ?? ''));
                    if ($name === '') {
                        continue;
                    }
                    $description = trim((string)($itemDescriptions[$i] ?? ''));
                    $description = mb_substr($description, 0, 2000);
                    $qty = isset($itemQtys[$i]) ? (float)$itemQtys[$i] : 0.0;
                    $price = isset($itemPrices[$i]) ? (float)$itemPrices[$i] : 0.0;
                    $unitRaw = trim((string)($itemUnits[$i] ?? ''));
                    $unitOther = trim((string)($itemUnitsOther[$i] ?? ''));
                    $unit = ($unitRaw === 'other') ? $unitOther : $unitRaw;
                    if ($unit !== '') {
                        $unit = mb_substr($unit, 0, 50);
                    }
                    $total = $qty * $price;
                    $stmtItem->bind_param("issdsdd", $quote_id, $name, $description, $qty, $unit, $price, $total);
                    $stmtItem->execute();
                }
                $stmtItem->close();
            }

            $conn->commit();
            app_safe_redirect('view_quote.php?token=' . rawurlencode($token), 'quotes.php');
        } catch (Throwable $e) {
            $conn->rollback();
            error_log('add_quote save failed: ' . $e->getMessage());
            echo "<script>alert('تعذر حفظ العرض حالياً. يرجى المحاولة مرة أخرى.');</script>";
        }
    }
}
?>

<style>
    /* 1. إعادة ضبط الهوية قسرياً */
    :root {
        --bg-dark: #050505;
        --card-bg: #121212;
        --input-bg: #1a1a1a;
        --gold: #d4af37;
        --gold-glow: rgba(212, 175, 55, 0.3);
        --text-main: #ffffff;
        --text-sub: #888888;
        --border: #333;
    }

    body {
        background-color: var(--bg-dark) !important;
        color: var(--text-main) !important;
        font-family: 'Cairo', sans-serif !important;
    }

    /* إصلاح مشاكل الهيدر القديم إن وجدت */
    .container { max-width: 1200px; margin: 0 auto; padding: 20px; }
    h1, h2, h3, h4, label { color: var(--text-main) !important; }

    /* 2. تصميم الكروت الفخم */
    .royal-card {
        background: linear-gradient(145deg, #151515, #0d0d0d);
        border: 1px solid var(--border);
        border-radius: 15px;
        padding: 25px;
        margin-bottom: 25px;
        box-shadow: 0 10px 30px rgba(0,0,0,0.5);
        position: relative;
        overflow: hidden;
    }
    .royal-card::before {
        content: ''; position: absolute; top: 0; left: 0; width: 100%; height: 3px;
        background: linear-gradient(90deg, transparent, var(--gold), transparent);
    }

    /* 3. حقول الإدخال */
    .form-control, select.form-control {
        width: 100%;
        background-color: var(--input-bg) !important;
        border: 1px solid var(--border) !important;
        color: #fff !important;
        padding: 12px 15px;
        border-radius: 8px;
        transition: 0.3s;
        font-size: 0.95rem;
    }
    .form-control:focus {
        border-color: var(--gold) !important;
        box-shadow: 0 0 15px var(--gold-glow) !important;
        outline: none;
    }
    label { color: var(--text-sub) !important; margin-bottom: 8px; display: block; font-weight: 600; }

    /* 4. الجدول */
    .items-table { width: 100%; border-collapse: separate; border-spacing: 0 8px; }
    .items-table th { text-align: right; color: var(--gold); padding: 10px; font-weight: bold; border-bottom: 1px solid var(--border); }
    .items-table td { background: var(--input-bg); padding: 5px; border-top: 1px solid var(--border); border-bottom: 1px solid var(--border); }
    .items-table td:first-child { border-radius: 0 8px 8px 0; border-right: 3px solid var(--gold); }
    .items-table td:last-child { border-radius: 8px 0 0 8px; }

    .qty-unit { display: flex; align-items: center; gap: 6px; }
    .qty-unit .qty-input { min-width: 90px; }
    .qty-unit .unit-select { min-width: 110px; }
    .qty-unit .unit-other { min-width: 120px; }
    .row-actions { display: flex; align-items: center; justify-content: center; gap: 12px; }
    
    .input-clean { 
        width: 100%; background: transparent; border: none; color: #fff; 
        padding: 10px; font-size: 1rem; outline: none;
    }
    
    /* 5. شبكة الشروط (Interactive Terms Grid) */
    .terms-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(350px, 1fr)); gap: 20px; margin-top: 20px; }
    
    .term-card {
        background: #0f0f0f;
        border: 1px solid #222;
        border-radius: 12px;
        transition: 0.3s;
    }
    .term-card:hover { border-color: #444; }
    
    .term-header {
        background: #1a1a1a;
        padding: 12px 15px;
        border-radius: 12px 12px 0 0;
        display: flex; justify-content: space-between; align-items: center;
        border-bottom: 1px solid #222;
    }
    .term-title { color: #ccc !important; font-size: 0.95rem; font-weight: 700; margin: 0; }
    
    .term-body { padding: 15px; }
    .disabled-section { opacity: 0.3; pointer-events: none; filter: grayscale(1); }

    .term-row { display: flex; align-items: flex-start; gap: 10px; margin-bottom: 12px; }
    .term-textarea {
        width: 100%; background: transparent; border: 1px dashed #333;
        color: #aaa; padding: 8px; border-radius: 6px; font-size: 0.85rem;
        min-height: 40px; resize: vertical; transition: 0.3s;
    }
    .term-textarea:focus { border-color: var(--gold); color: #fff; background: #000; }
    .term-textarea:disabled { border: none; }

    /* 6. الأزرار (Buttons) */
    .btn-royal {
        background: linear-gradient(135deg, #d4af37, #b8860b);
        color: #000 !important;
        border: none;
        padding: 12px 30px;
        border-radius: 50px;
        font-weight: 800;
        font-size: 1rem;
        cursor: pointer;
        box-shadow: 0 5px 15px var(--gold-glow);
        transition: transform 0.2s, box-shadow 0.2s;
        text-decoration: none; display: inline-block;
    }
    .btn-royal:hover { transform: translateY(-3px); box-shadow: 0 10px 25px var(--gold-glow); }
    
    .btn-outline {
        background: transparent; border: 1px solid var(--gold); color: var(--gold) !important;
        padding: 8px 20px; border-radius: 50px; font-size: 0.9rem; text-decoration: none;
    }
    .btn-outline:hover { background: var(--gold); color: #000 !important; }

    .btn-add {
        width: 100%; background: rgba(255,255,255,0.03); border: 1px dashed #444;
        color: var(--text-sub); padding: 12px; border-radius: 8px; cursor: pointer; transition: 0.3s;
    }
    .btn-add:hover { border-color: var(--gold); color: var(--gold); background: rgba(212,175,55,0.05); }
    .currency-orbit {
        display:grid; grid-template-columns: 1fr auto; gap:10px; align-items:center;
        background: radial-gradient(circle at top right, rgba(212,175,55,.14), transparent 34%), #101010;
        border:1px solid rgba(212,175,55,.28); border-radius:14px; padding:12px;
    }
    .currency-orbit select { min-width:0; }
    .currency-badge { color:var(--gold); font-weight:900; font-size:.86rem; text-align:left; white-space:nowrap; }
    .currency-hint { grid-column:1/-1; color:#8f8f8f; font-size:.78rem; line-height:1.6; }
    .tax-panel {
        margin-top: 18px;
        padding: 16px;
        background: #0f0f0f;
        border: 1px solid #2b2b2b;
        border-radius: 12px;
    }
    .tax-grid { display:grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap:14px; }
    .tax-checks { display:grid; gap:8px; margin-top:12px; }
    .tax-option {
        display:flex; justify-content:space-between; gap:10px; align-items:center;
        border:1px solid #2d2d2d; background:#151515; border-radius:8px; padding:10px 12px;
    }
    .tax-option.disabled { opacity:.45; }
    .tax-option input { accent-color: var(--gold); }
    .tax-breakdown { margin-top:12px; border-top:1px dashed #333; padding-top:12px; }
    .tax-line { display:flex; justify-content:space-between; gap:10px; color:#d0d0d0; margin-bottom:8px; }
    .tax-line.subtract { color:#ffaaaa; }
    .tax-hint { font-size:0.82rem; color:#808080; margin-top:8px; }

    /* 7. السويتش (Toggle Switch) */
    .switch { position: relative; display: inline-block; width: 40px; height: 20px; }
    .switch input { opacity: 0; width: 0; height: 0; }
    .slider { position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background-color: #333; transition: .4s; border-radius: 20px; }
    .slider:before { position: absolute; content: ""; height: 14px; width: 14px; left: 3px; bottom: 3px; background-color: white; transition: .4s; border-radius: 50%; }
    input:checked + .slider { background-color: var(--gold); }
    input:checked + .slider:before { transform: translateX(20px); }
</style>

<div class="container">
    
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:30px;">
        <div>
            <h2 style="font-size:1.8rem; margin:0; display:flex; align-items:center; gap:10px;">
                <i class="fa-solid fa-file-invoice-dollar" style="color:var(--gold);"></i> عرض سعر جديد
            </h2>
            <p style="color:var(--text-sub); margin:5px 0 0 0;">إصدار عرض مالي احترافي للعملاء</p>
        </div>
        <a href="quotes.php" class="btn-outline"><i class="fa-solid fa-arrow-right"></i> القائمة</a>
    </div>

    <form method="POST" enctype="multipart/form-data">
        <?php echo app_csrf_input(); ?>
        
        <div class="royal-card">
            <h3 style="border-bottom:1px solid #333; padding-bottom:15px; margin-top:0; font-size:1.1rem;">
                <i class="fa-solid fa-user-tag"></i> بيانات العرض الأساسية
            </h3>
            <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap:20px;">
                <div>
                    <label>قالب جاهز</label>
                    <select id="quote_template" class="form-control" onchange="applyQuoteTemplate(this.value)">
                        <option value="">بدون قالب</option>
                        <?php foreach ($quoteTemplates as $tpl): ?>
                            <option value="<?php echo (int)$tpl['id']; ?>"><?php echo app_h((string)$tpl['template_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label>اختار العميل</label>
                    <select name="client_id" required class="form-control">
                        <option value="">-- بحث عن عميل --</option>
                        <?php 
                        $clients = $conn->query("SELECT * FROM clients");
                        while($c = $clients->fetch_assoc()) echo "<option value='{$c['id']}'>{$c['name']}</option>";
                        ?>
                    </select>
                </div>
                <div>
                    <label>تاريخ العرض</label>
                    <input type="date" name="date" id="start_date" value="<?php echo date('Y-m-d'); ?>" class="form-control" required onchange="calcValidity()">
                </div>
                <div>
                    <label>تاريخ الانتهاء</label>
                    <input type="date" name="valid_until" id="end_date" value="<?php echo date('Y-m-d', strtotime('+7 days')); ?>" class="form-control" required onchange="calcValidity()">
                </div>
                <div>
                    <label>عملة العرض</label>
                    <div class="currency-orbit">
                        <select name="currency_code" id="quote_currency" class="form-control" onchange="updateCurrencyUI(true)">
                            <?php foreach ($quoteCurrencyOptions as $currencyCode => $currencyMeta): ?>
                                <option value="<?php echo app_h($currencyCode); ?>" data-rate="<?php echo app_h((string)($currencyMeta['rate_to_base'] ?? 1)); ?>" data-base="<?php echo app_h((string)($currencyMeta['base_currency'] ?? $defaultQuoteCurrency)); ?>" <?php echo $currencyCode === $defaultQuoteCurrency ? 'selected' : ''; ?>>
                                    <?php echo app_h($currencyCode . ' - ' . (string)($currencyMeta['label_ar'] ?? $currencyCode)); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <span class="currency-badge" id="currency_badge"><?php echo app_h($defaultQuoteCurrency); ?></span>
                        <div class="currency-hint" id="currency_hint">يتم حفظ سعر التحويل لحظة إصدار العرض.</div>
                    </div>
                </div>
                <div>
                    <label>تاريخ متوقع للإغلاق</label>
                    <input type="date" name="expected_close_date" class="form-control" value="<?php echo date('Y-m-d', strtotime('+3 days')); ?>">
                </div>
                <div>
                    <label>دفعة مقدمة مطلوبة</label>
                    <input type="number" name="deposit_required_amount" value="0" step="0.01" min="0" class="form-control">
                </div>
            </div>
            <div class="tax-panel">
                <div class="tax-grid">
                    <div>
                        <label>نوع العرض</label>
                        <select name="quote_kind" id="quote_kind" class="form-control" onchange="calcTotal()">
                            <option value="standard">عرض سعر عادي</option>
                            <option value="tax">عرض سعر ضريبي</option>
                        </select>
                    </div>
                    <div>
                        <label>القانون الضريبي</label>
                        <select name="tax_law_key" id="tax_law_key" class="form-control">
                            <?php foreach ($quoteTaxLaws as $lawRow): ?>
                                <option value="<?php echo app_h((string)$lawRow['key']); ?>" <?php echo ((string)app_setting_get($conn, 'tax_default_quote_law', 'vat_2016') === (string)$lawRow['key']) ? 'selected' : ''; ?>>
                                    <?php echo app_h((string)$lawRow['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="tax-checks" id="tax_checks">
                    <?php foreach ($quoteTaxCatalog as $taxType): ?>
                        <label class="tax-option disabled">
                            <span>
                                <input type="checkbox" class="js-tax-key" name="tax_keys[]" value="<?php echo app_h((string)$taxType['key']); ?>" onchange="calcTotal()">
                                <?php echo app_h((string)$taxType['name']); ?>
                            </span>
                            <span><?php echo number_format((float)($taxType['rate'] ?? 0), 2); ?>%</span>
                        </label>
                    <?php endforeach; ?>
                </div>
                <div class="tax-breakdown" id="tax_breakdown">
                    <div class="tax-line"><span>إجمالي الضرائب</span><span>0.00</span></div>
                </div>
                <div class="tax-hint">لا تُحتسب الضرائب إلا عند اختيار "عرض سعر ضريبي".</div>
            </div>
        </div>

        <div class="royal-card">
            <h3 style="border-bottom:1px solid #333; padding-bottom:15px; margin-top:0; font-size:1.1rem;">
                <i class="fa-solid fa-cart-flatbed"></i> تفاصيل البنود
            </h3>
            <table class="items-table">
                <thead>
                    <tr>
                        <th width="40%">البيان / الوصف</th>
                        <th width="20%" style="text-align:center;">الكمية / الوحدة</th>
                        <th width="15%" style="text-align:center;">السعر</th>
                        <th width="20%" style="text-align:center;">الإجمالي</th>
                        <th width="10%"></th>
                    </tr>
                </thead>
                <tbody id="items_container">
                    <tr>
                        <td>
                            <input type="text" name="item_name[]" required class="input-clean" placeholder="اكتب اسم الخدمة أو المنتج...">
                            <textarea name="item_description[]" class="input-clean item-description-input" rows="2" placeholder="وصف تفصيلي اختياري للبند..." style="border-top:1px dashed #333; resize:vertical; min-height:48px;"></textarea>
                        </td>
                        <td>
                            <div class="qty-unit">
                                <input type="number" name="qty[]" value="1" step="0.01" class="input-clean center qty-input" style="text-align:center;" oninput="calc(this)">
                                <select name="unit[]" class="input-clean unit-select" onchange="toggleUnitOther(this)">
                                    <option value="">وحدة</option>
                                    <option value="كيلو">كيلو</option>
                                    <option value="طن">طن</option>
                                    <option value="علبة">علبة</option>
                                    <option value="كرتونة">كرتونة</option>
                                    <option value="كيس">كيس</option>
                                    <option value="ليبل">ليبل</option>
                                    <option value="قطعة">قطعة</option>
                                    <option value="متر">متر</option>
                                    <option value="رول">رول</option>
                                    <option value="باكيت">باكيت</option>
                                    <option value="بالة">بالة</option>
                                    <option value="زجاجة">زجاجة</option>
                                    <option value="لوح">لوح</option>
                                    <option value="صندوق">صندوق</option>
                                    <option value="عبوة">عبوة</option>
                                    <option value="other">أخرى</option>
                                </select>
                                <input type="text" name="unit_other[]" class="input-clean unit-other" placeholder="أخرى" style="display:none;">
                            </div>
                        </td>
                        <td><input type="number" name="price[]" value="0" step="0.01" class="input-clean center" style="text-align:center;" oninput="calc(this)"></td>
                        <td><input type="text" readonly class="input-clean center row-total" style="text-align:center; color:var(--gold);" value="0.00"></td>
                        <td style="text-align:center;">
                            <div class="row-actions">
                                <i class="fa-solid fa-copy" onclick="duplicateRow(this)" title="تكرار البند" style="cursor:pointer; color:#d4af37; font-size:1.05rem;"></i>
                                <i class="fa-solid fa-trash-can" onclick="removeRow(this)" title="حذف البند" style="cursor:pointer; color:#e74c3c; font-size:1.05rem;"></i>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
            <button type="button" onclick="addRow()" class="btn-add" style="margin-top:15px;">
                <i class="fa-solid fa-plus-circle"></i> إضافة بند جديد
            </button>
        </div>

        <div class="royal-card" style="background:#0a0a0a;">
            <div style="display:flex; justify-content:space-between; align-items:center; border-bottom:1px solid #333; padding-bottom:15px; margin-bottom:20px;">
                <h3 style="margin:0; font-size:1.1rem; color:var(--gold);"><i class="fa-solid fa-scale-balanced"></i> تخصيص الشروط والأحكام</h3>
                <span style="font-size:0.8rem; color:#666;">قم بتفعيل الأقسام المطلوبة</span>
            </div>

            <div style="margin-bottom:20px;">
                <label>ملاحظة افتتاحية (تظهر أعلى الشروط)</label>
                <textarea name="manual_notes" class="form-control" rows="2" placeholder="مثال: الأسعار لا تشمل مصاريف الشحن..."></textarea>
            </div>

            <div class="terms-grid">
                <?php foreach($default_terms as $key => $section): ?>
                <div class="term-card">
                    <div class="term-header">
                        <span class="term-title"><?php echo app_h((string)$section['title']); ?></span>
                        <label class="switch">
                            <input type="checkbox" onchange="toggleSection(this, 'sec_<?php echo $key; ?>')" <?php echo ($key == 'general') ? 'checked' : ''; ?>>
                            <span class="slider round"></span>
                        </label>
                    </div>
                    <div id="sec_<?php echo $key; ?>" class="term-body <?php echo ($key == 'general') ? '' : 'disabled-section'; ?>" data-terms-section="<?php echo app_h($key); ?>">
                        <?php foreach($section['items'] as $k => $text): ?>
                            <div class="term-row">
                                <input type="checkbox" id="chk_<?php echo $key.$k; ?>" onchange="toggleTerm(this, 'txt_<?php echo $key.$k; ?>')" checked style="margin-top:5px; accent-color:var(--gold);">
                                <textarea name="terms[]" id="txt_<?php echo $key.$k; ?>" class="term-textarea <?php echo ($k=='validity')?'validity-term':''; ?>" 
                                          data-original="<?php echo app_h($text); ?>" <?php echo ($key === 'general') ? '' : 'disabled'; ?>><?php echo app_h($text); ?></textarea>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="royal-card">
            <h3 style="border-bottom:1px solid #333; padding-bottom:15px; margin-top:0; font-size:1.1rem;">
                <i class="fa-solid fa-paperclip"></i> مرفقات العرض
            </h3>
            <input type="file" name="quote_attachments[]" class="form-control" multiple accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx,.xls,.xlsx">
            <p style="color:#777; margin:10px 0 0; font-size:.85rem;">يمكن إرفاق مواصفة، بروفة، صورة خامة، أو ملف فني بحجم حتى 12MB لكل ملف.</p>
        </div>

        <div style="background:#111; padding:20px; border-radius:15px; border:1px solid var(--border); display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:20px;">
            <div style="display:flex; align-items:center; gap:20px;">
                <span style="color:#888;">الإجمالي النهائي:</span>
                <span style="font-size:2.2rem; font-weight:900; color:var(--gold); line-height:1;" id="grand_total">0.00</span>
                <span style="color:var(--gold);" id="grand_currency"><?php echo app_h($defaultQuoteCurrency); ?></span>
            </div>
            <button type="submit" class="btn-royal">
                <i class="fa-solid fa-save"></i> حفظ وإصدار العرض
            </button>
        </div>

    </form>
</div>

<script>
const quoteTaxes = <?php echo json_encode(array_values($quoteTaxCatalog), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
const quoteCurrencyMap = <?php echo json_encode($quoteCurrencyOptions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
const quoteTemplates = <?php echo json_encode(array_column($quoteTemplates, null, 'id'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
let activeCurrencyCode = <?php echo json_encode($defaultQuoteCurrency); ?>;
let activeRateToBase = Number((quoteCurrencyMap[activeCurrencyCode] || {}).rate_to_base || 1);
function quoteCurrencyMeta(code) {
    return quoteCurrencyMap[code] || {base_currency:'EGP', rate_to_base:1};
}
function convertAmountBetweenCurrencies(amount, fromRate, toRate) {
    amount = Number(amount || 0);
    fromRate = Number(fromRate || 1);
    toRate = Number(toRate || 1);
    if (fromRate <= 0) fromRate = 1;
    if (toRate <= 0) toRate = 1;
    return (amount * fromRate) / toRate;
}
function refreshQuoteRowTotals() {
    document.querySelectorAll('#items_container tr').forEach((tr) => {
        const qty = parseFloat((tr.querySelector('[name="qty[]"]') || {}).value) || 0;
        const price = parseFloat((tr.querySelector('[name="price[]"]') || {}).value) || 0;
        const totalEl = tr.querySelector('.row-total');
        if (totalEl) totalEl.value = (qty * price).toFixed(2);
    });
}
function convertQuoteInputsToCurrency(newCode) {
    const meta = quoteCurrencyMeta(newCode);
    const newRate = Number(meta.rate_to_base || 1);
    if (newCode === activeCurrencyCode || newRate <= 0) return;
    document.querySelectorAll('[name="price[]"]').forEach((input) => {
        const next = convertAmountBetweenCurrencies(parseFloat(input.value) || 0, activeRateToBase, newRate);
        input.value = next.toFixed(2);
    });
    activeCurrencyCode = newCode;
    activeRateToBase = newRate;
    refreshQuoteRowTotals();
}
function escapeHtml(value) {
    return String(value || '').replace(/[&<>"']/g, ch => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;', "'":'&#039;'}[ch]));
}
function applyQuoteTemplate(id) {
    const tpl = quoteTemplates[id];
    if (!tpl) return;
    const body = document.getElementById('items_container');
    const notes = document.querySelector('textarea[name="manual_notes"]');
    if (!body) return;
    body.innerHTML = '';
    const items = Array.isArray(tpl.items) && tpl.items.length ? tpl.items : [{desc:'', qty:1, unit:'', price:0}];
    items.forEach(item => {
        addRow();
        const row = body.lastElementChild;
        if (!row) return;
        row.querySelector('[name="item_name[]"]').value = item.desc || item.item_name || '';
        const descInput = row.querySelector('[name="item_description[]"]');
        if (descInput) descInput.value = item.description || item.item_description || '';
        row.querySelector('[name="qty[]"]').value = item.qty || 1;
        row.querySelector('[name="price[]"]').value = convertAmountBetweenCurrencies(item.price || 0, 1, activeRateToBase).toFixed(2);
        const unitSelect = row.querySelector('[name="unit[]"]');
        const unitOther = row.querySelector('[name="unit_other[]"]');
        const unit = item.unit || '';
        if (unitSelect && [...unitSelect.options].some(opt => opt.value === unit)) {
            unitSelect.value = unit;
        } else if (unitSelect && unit) {
            unitSelect.value = 'other';
            if (unitOther) { unitOther.style.display = ''; unitOther.value = unit; }
        }
    });
    if (notes && tpl.notes) notes.value = tpl.notes;
    calcTotal();
}
function updateCurrencyUI(convertValues = false) {
    const select = document.getElementById('quote_currency');
    const code = select ? select.value : 'EGP';
    if (convertValues) {
        convertQuoteInputsToCurrency(code);
    }
    const meta = quoteCurrencyMap[code] || {};
    const base = meta.base_currency || 'EGP';
    const rate = Number(meta.rate_to_base || 1);
    const badge = document.getElementById('currency_badge');
    const suffix = document.getElementById('grand_currency');
    const hint = document.getElementById('currency_hint');
    if (badge) badge.textContent = code;
    if (suffix) suffix.textContent = code;
    if (hint) hint.textContent = code === base ? 'عملة الأساس المعتمدة في البيانات الأولية.' : ('سعر التحويل الحالي: 1 ' + code + ' = ' + rate.toFixed(4) + ' ' + base + '. سيتم حفظه مع العرض.');
    if (convertValues) {
        calcTotal();
    }
}

// 1. منطق الجدول
function addRow() {
    let tr = document.createElement('tr');
    tr.innerHTML = `
        <td>
            <input type="text" name="item_name[]" required class="input-clean" placeholder="...">
            <textarea name="item_description[]" class="input-clean item-description-input" rows="2" placeholder="وصف تفصيلي اختياري للبند..." style="border-top:1px dashed #333; resize:vertical; min-height:48px;"></textarea>
        </td>
        <td>
            <div class="qty-unit">
                <input type="number" name="qty[]" value="1" step="0.01" class="input-clean center qty-input" style="text-align:center;" oninput="calc(this)">
                <select name="unit[]" class="input-clean unit-select" onchange="toggleUnitOther(this)">
                    <option value="">وحدة</option>
                    <option value="كيلو">كيلو</option>
                    <option value="طن">طن</option>
                    <option value="علبة">علبة</option>
                    <option value="كرتونة">كرتونة</option>
                    <option value="كيس">كيس</option>
                    <option value="ليبل">ليبل</option>
                    <option value="قطعة">قطعة</option>
                    <option value="متر">متر</option>
                    <option value="رول">رول</option>
                    <option value="باكيت">باكيت</option>
                    <option value="بالة">بالة</option>
                    <option value="زجاجة">زجاجة</option>
                    <option value="لوح">لوح</option>
                    <option value="صندوق">صندوق</option>
                    <option value="عبوة">عبوة</option>
                    <option value="other">أخرى</option>
                </select>
                <input type="text" name="unit_other[]" class="input-clean unit-other" placeholder="أخرى" style="display:none;">
            </div>
        </td>
        <td><input type="number" name="price[]" value="0" step="0.01" class="input-clean center" style="text-align:center;" oninput="calc(this)"></td>
        <td><input type="text" readonly class="input-clean center row-total" style="text-align:center; color:var(--gold);" value="0.00"></td>
        <td style="text-align:center;">
            <div class="row-actions">
                <i class="fa-solid fa-copy" onclick="duplicateRow(this)" title="تكرار البند" style="cursor:pointer; color:#d4af37; font-size:1.05rem;"></i>
                <i class="fa-solid fa-trash-can" onclick="removeRow(this)" title="حذف البند" style="cursor:pointer; color:#e74c3c; font-size:1.05rem;"></i>
            </div>
        </td>
    `;
    document.getElementById('items_container').appendChild(tr);
}

function duplicateRow(triggerEl) {
    const tr = triggerEl.closest('tr');
    if (!tr) return;
    const clone = tr.cloneNode(true);
    clone.querySelectorAll('input').forEach(input => {
        if (input.classList.contains('row-total')) {
            return;
        }
        input.value = input.value;
    });
    const sourceSelect = tr.querySelector('.unit-select');
    const cloneSelect = clone.querySelector('.unit-select');
    if (sourceSelect && cloneSelect) {
        cloneSelect.value = sourceSelect.value;
    }
    const sourceOther = tr.querySelector('.unit-other');
    const cloneOther = clone.querySelector('.unit-other');
    if (sourceOther && cloneOther) {
        cloneOther.value = sourceOther.value;
        cloneOther.style.display = sourceSelect && sourceSelect.value === 'other' ? '' : 'none';
    }
    tr.insertAdjacentElement('afterend', clone);
    calcTotal();
}

function removeRow(triggerEl) {
    const row = triggerEl.closest('tr');
    if (!row) return;
    const rows = document.querySelectorAll('#items_container tr');
    if (rows.length <= 1) {
        row.querySelector('[name="item_name[]"]').value = '';
        const descInput = row.querySelector('[name="item_description[]"]');
        if (descInput) descInput.value = '';
        row.querySelector('[name="qty[]"]').value = '1';
        row.querySelector('[name="price[]"]').value = '0';
        const unitSelect = row.querySelector('.unit-select');
        if (unitSelect) unitSelect.value = '';
        const unitOther = row.querySelector('.unit-other');
        if (unitOther) {
            unitOther.value = '';
            unitOther.style.display = 'none';
        }
        row.querySelector('.row-total').value = '0.00';
        calcTotal();
        return;
    }
    row.remove();
    calcTotal();
}

function calc(el) {
    let tr = el.closest('tr');
    let q = parseFloat(tr.querySelector('[name="qty[]"]').value) || 0;
    let p = parseFloat(tr.querySelector('[name="price[]"]').value) || 0;
    tr.querySelector('.row-total').value = (q * p).toFixed(2);
    calcTotal();
}

function calcTotal() {
    let subTotal = 0;
    document.querySelectorAll('.row-total').forEach(e => subTotal += parseFloat(e.value) || 0);
    const kindEl = document.getElementById('quote_kind');
    const isTaxQuote = kindEl && kindEl.value === 'tax';
    let taxTotal = 0;
    const selectedKeys = Array.from(document.querySelectorAll('.js-tax-key:checked')).map(el => String(el.value || '').trim().toLowerCase());
    document.querySelectorAll('.tax-option').forEach(el => el.classList.toggle('disabled', !isTaxQuote));
    document.querySelectorAll('.js-tax-key').forEach(el => {
        el.disabled = !isTaxQuote;
    });
    let breakdownHtml = '';
    if (isTaxQuote) {
        quoteTaxes.forEach(taxType => {
            const key = String(taxType.key || '').trim().toLowerCase();
            if (!selectedKeys.includes(key)) {
                return;
            }
            const baseType = String(taxType.base || 'net_after_discount');
            const baseAmount = baseType === 'subtotal' ? subTotal : subTotal;
            const rate = parseFloat(taxType.rate || 0);
            const amount = (baseAmount * rate) / 100;
            const mode = String(taxType.mode || 'add');
            const signedAmount = mode === 'subtract' ? (-1 * amount) : amount;
            taxTotal += signedAmount;
            breakdownHtml += `<div class="tax-line ${mode === 'subtract' ? 'subtract' : ''}"><span>${String(taxType.name || key)} (${rate.toFixed(2)}%)</span><span>${mode === 'subtract' ? '-' : '+'}${amount.toFixed(2)}</span></div>`;
        });
    }
    breakdownHtml += `<div class="tax-line" style="font-weight:700; color:var(--gold); margin-bottom:0;"><span>إجمالي الضرائب</span><span>${taxTotal.toFixed(2)}</span></div>`;
    document.getElementById('tax_breakdown').innerHTML = breakdownHtml;
    document.getElementById('grand_total').innerText = Math.max(0, subTotal + taxTotal).toFixed(2);
    updateCurrencyUI();
}

function toggleUnitOther(selectEl) {
    const tr = selectEl.closest('tr');
    if (!tr) return;
    const otherInput = tr.querySelector('.unit-other');
    if (!otherInput) return;
    if (selectEl.value === 'other') {
        otherInput.style.display = '';
        otherInput.focus();
    } else {
        otherInput.style.display = 'none';
        otherInput.value = '';
    }
}

// 2. منطق الشروط
function toggleSection(chk, secId) {
    let sec = document.getElementById(secId);
    if(chk.checked) {
        sec.classList.remove('disabled-section');
        sec.querySelectorAll('textarea').forEach(t => {
            // إعادة تفعيل الـ textareas المفعلة بالـ checkboxes الداخلية
            let checkboxId = t.id.replace('txt_', 'chk_');
            if(document.getElementById(checkboxId).checked) t.disabled = false;
        });
    } else {
        sec.classList.add('disabled-section');
        sec.querySelectorAll('textarea').forEach(t => t.disabled = true);
    }
}

function toggleTerm(chk, txtId) {
    let txt = document.getElementById(txtId);
    txt.disabled = !chk.checked;
    if(chk.checked) txt.focus();
}

// 3. الذكاء الزمني
updateCurrencyUI();
function calcValidity() {
    let start = new Date(document.getElementById('start_date').value);
    let end = new Date(document.getElementById('end_date').value);
    if(start && end) {
        let diffTime = end - start;
        let diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24)); 
        if(diffDays < 0) diffDays = 0;
        
        let validityBox = document.querySelector('.validity-term');
        if(validityBox) {
            let originalText = validityBox.getAttribute('data-original');
            validityBox.value = originalText.replace('{DAYS}', diffDays);
        }
    }
}

window.onload = () => {
    calcValidity();
    calcTotal();
};
</script>

<?php include 'footer.php'; ob_end_flush(); ?>
