<?php
ob_start();
// master_data.php
// Admin center for operation customization and numbering rules.

require 'auth.php';
require 'config.php';
require_once __DIR__ . '/modules/master_data/runtime.php';
require_once __DIR__ . '/modules/tax/eta_einvoice_runtime.php';
require_once __DIR__ . '/modules/finance/fx_widget_runtime.php';
require_once __DIR__ . '/modules/master_data/actions_runtime.php';
require_once __DIR__ . '/modules/master_data/settings_actions_runtime.php';
require_once __DIR__ . '/modules/master_data/workflow_actions_runtime.php';
app_start_session();
app_handle_lang_switch($conn);
$isWorkRuntime = app_is_work_runtime();

$requestedTab = strtolower(trim((string)($_POST['tab'] ?? $_GET['tab'] ?? 'branding')));
$requestedTab = preg_match('/^[a-z_]+$/', $requestedTab) ? $requestedTab : 'branding';
$isMasterDataAdmin = (($_SESSION['role'] ?? '') === 'admin');
$canManagePricingSettings = $isMasterDataAdmin || app_user_can('pricing.settings');

if (!$isMasterDataAdmin && !($requestedTab === 'pricing' && $canManagePricingSettings)) {
    require 'header.php';
    echo "<div class='container' style='margin-top:30px;'><div style='background:#1d0f0f;border:1px solid #5b2020;border-radius:12px;padding:20px;color:#ffb3b3;'>⛔ هذه الصفحة مخصصة للمدير فقط.</div></div>";
    require 'footer.php';
    exit;
}

app_initialize_customization_data($conn);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    app_require_csrf();
}


$msg = '';
$msgType = 'ok';
if (!empty($_SESSION['md_flash']) && is_array($_SESSION['md_flash'])) {
    $msg = (string)($_SESSION['md_flash']['message'] ?? '');
    $msgType = (string)($_SESSION['md_flash']['type'] ?? 'ok');
    unset($_SESSION['md_flash']);
}
$isAjaxRequest = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest'
    || (string)($_POST['__ajax'] ?? '') === '1';
$activeTab = md_normalize_tab((string)($_POST['tab'] ?? $_GET['tab'] ?? 'branding'));
$pricingSettingsDenied = !$isMasterDataAdmin && !$canManagePricingSettings;
$visibleTabs = $isMasterDataAdmin
    ? md_allowed_tabs()
    : ($canManagePricingSettings ? ['pricing'] : ['branding']);
if (!$isWorkRuntime) {
    $visibleTabs = array_values(array_filter($visibleTabs, static function ($tab) {
        return $tab !== 'eta';
    }));
}
if (!in_array($activeTab, $visibleTabs, true)) {
    $activeTab = $visibleTabs[0] ?? 'branding';
}
$scopeType = md_normalize_type_key((string)($_POST['scope_type'] ?? $_GET['scope_type'] ?? ''));
$GLOBALS['md_scope_type'] = $scopeType;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'save_pricing_settings' && $pricingSettingsDenied) {
            throw new RuntimeException('غير مصرح لك بإدارة إعدادات التسعير.');
        }
        $mdActionState = [
            'msg' => &$msg,
            'msgType' => &$msgType,
            'scopeType' => &$scopeType,
            'activeTab' => &$activeTab,
            'isAjaxRequest' => $isAjaxRequest,
        ];
        if (md_handle_settings_actions($conn, $action, $mdActionState)) {
        } elseif (md_handle_workflow_actions($conn, $action, $mdActionState)) {
        } elseif (md_handle_cloud_pricing_actions($conn, $action, $mdActionState)) {
        }
    } catch (Throwable $e) {
        $msgType = 'err';
        $msg = 'تعذر تنفيذ العملية: ' . $e->getMessage();
    }
}

$appName = app_setting_get($conn, 'app_name', 'Arab Eagles');
$themeColor = app_setting_get($conn, 'theme_color', '#d4af37');
$uiThemePreset = app_ui_theme_system_key($conn);
$uiThemePresets = app_ui_theme_presets();
$outputThemePreset = app_brand_output_theme_key($conn);
$outputThemePresets = app_brand_output_theme_presets();
$timezone = app_setting_get($conn, 'timezone', 'Africa/Cairo');
$appLang = app_current_lang($conn);
$supportedLangs = app_supported_languages();
$logoPath = app_brand_logo_path($conn, 'assets/img/Logo.png');
$fxWidgetSettings = app_fx_widget_normalize_settings($conn);
$fxCurrencyCatalog = app_fx_widget_currency_catalog();
$fxCurrencySelectedJson = json_encode(array_values((array)$fxWidgetSettings['currencies']), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$fxCurrencyCatalogJson = json_encode($fxCurrencyCatalog, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$brandProfile = app_brand_profile($conn);
$orgName = (string)($brandProfile['org_name'] ?? $appName);
$orgLegalName = (string)($brandProfile['org_legal_name'] ?? '');
$orgTaxNumber = (string)($brandProfile['org_tax_number'] ?? '');
$orgCommercialNumber = (string)($brandProfile['org_commercial_number'] ?? '');
$orgPhonePrimary = (string)($brandProfile['org_phone_primary'] ?? '');
$orgPhoneSecondary = (string)($brandProfile['org_phone_secondary'] ?? '');
$orgEmail = (string)($brandProfile['org_email'] ?? '');
$orgWebsite = (string)($brandProfile['org_website'] ?? '');
$orgAddress = (string)($brandProfile['org_address'] ?? '');
$orgSocialWhatsapp = (string)($brandProfile['org_social_whatsapp'] ?? '');
$orgSocialFacebook = (string)($brandProfile['org_social_facebook'] ?? '');
$orgSocialInstagram = (string)($brandProfile['org_social_instagram'] ?? '');
$orgSocialLinkedin = (string)($brandProfile['org_social_linkedin'] ?? '');
$orgSocialX = (string)($brandProfile['org_social_x'] ?? '');
$orgSocialYoutube = (string)($brandProfile['org_social_youtube'] ?? '');
$orgFooterNote = (string)($brandProfile['org_footer_note'] ?? '');
$outputShowHeader = !empty($brandProfile['show_header']);
$outputShowFooter = !empty($brandProfile['show_footer']);
$outputShowLogo = !empty($brandProfile['show_logo']);
$outputShowQr = !empty($brandProfile['show_qr']);
$brandFieldDefs = app_brand_profile_field_defs();
$outputHeaderItems = (array)($brandProfile['header_items'] ?? []);
$outputFooterItems = (array)($brandProfile['footer_items'] ?? []);
$paymobUrl = app_setting_get($conn, 'payment_method_paymob_url', '');
$walletNumber = app_setting_get($conn, 'payment_method_wallet_number', '');
$instapayUrl = app_setting_get($conn, 'payment_method_instapay_url', 'https://ipn.eg/S/eagles.bm/instapay/3MH6E0');
$paymentGatewayEnabled = app_setting_get($conn, 'payment_gateway_enabled', '0') === '1';
$paymentGatewayRolloutState = app_setting_get($conn, 'payment_gateway_rollout_state', 'draft');
if (!in_array($paymentGatewayRolloutState, ['draft', 'pending_contract', 'active'], true)) {
    $paymentGatewayRolloutState = 'draft';
}
$paymentGatewayLiveEnabled = $paymentGatewayEnabled && $paymentGatewayRolloutState === 'active';
$paymentGatewayProvider = app_setting_get($conn, 'payment_gateway_provider', 'manual');
$paymentGatewayCheckoutUrl = app_setting_get($conn, 'payment_gateway_checkout_url', '');
$paymentGatewayProviderLabelAr = app_setting_get($conn, 'payment_gateway_provider_label_ar', 'بوابة الدفع');
$paymentGatewayProviderLabelEn = app_setting_get($conn, 'payment_gateway_provider_label_en', 'Payment gateway');
$paymentGatewaySupportEmail = app_setting_get($conn, 'payment_gateway_support_email', '');
$paymentGatewaySupportWhatsapp = app_setting_get($conn, 'payment_gateway_support_whatsapp', '');
$paymentGatewayApiBaseUrl = app_setting_get($conn, 'payment_gateway_api_base_url', '');
$paymentGatewayApiVersion = app_setting_get($conn, 'payment_gateway_api_version', '');
$paymentGatewayPublicKey = app_setting_get($conn, 'payment_gateway_public_key', '');
$paymentGatewaySecretKey = app_setting_get($conn, 'payment_gateway_secret_key', '');
$paymentGatewayMerchantId = app_setting_get($conn, 'payment_gateway_merchant_id', '');
$paymentGatewayIntegrationId = app_setting_get($conn, 'payment_gateway_integration_id', '');
$paymentGatewayIframeId = app_setting_get($conn, 'payment_gateway_iframe_id', '');
$paymentGatewayHmacSecret = app_setting_get($conn, 'payment_gateway_hmac_secret', '');
$paymentGatewayWebhookSecret = app_setting_get($conn, 'payment_gateway_webhook_secret', '');
$paymentGatewayCallbackUrl = app_setting_get($conn, 'payment_gateway_callback_url', '');
$paymentGatewayWebhookUrl = app_setting_get($conn, 'payment_gateway_webhook_url', '');
$paymentGatewayPaymobIntegrationName = app_setting_get($conn, 'payment_gateway_paymob_integration_name', '');
$paymentGatewayPaymobProcessedCallbackUrl = app_setting_get($conn, 'payment_gateway_paymob_processed_callback_url', $paymentGatewayCallbackUrl);
$paymentGatewayPaymobResponseCallbackUrl = app_setting_get($conn, 'payment_gateway_paymob_response_callback_url', $paymentGatewayWebhookUrl);
$paymentGatewayEmailNotificationsEnabled = app_setting_get($conn, 'payment_gateway_email_notifications_enabled', '1') === '1';
$paymentGatewayWhatsappNotificationsEnabled = app_setting_get($conn, 'payment_gateway_whatsapp_notifications_enabled', '0') === '1';
$paymentGatewayWhatsappMode = app_setting_get($conn, 'payment_gateway_whatsapp_mode', 'link');
$paymentGatewayWhatsappAccessToken = app_setting_get($conn, 'payment_gateway_whatsapp_access_token', '');
$paymentGatewayWhatsappPhoneNumberId = app_setting_get($conn, 'payment_gateway_whatsapp_phone_number_id', '');
$paymentGatewayOutboundWebhooksEnabled = app_setting_get($conn, 'payment_gateway_outbound_webhooks_enabled', '0') === '1';
$paymentGatewayOutboundWebhooksUrl = app_setting_get($conn, 'payment_gateway_outbound_webhooks_url', '');
$paymentGatewayOutboundWebhooksToken = app_setting_get($conn, 'payment_gateway_outbound_webhooks_token', '');
$paymentGatewayOutboundWebhooksSecret = app_setting_get($conn, 'payment_gateway_outbound_webhooks_secret', '');
$paymentGatewayOutboundWebhooksEvents = implode(', ', md_string_list(app_setting_get($conn, 'payment_gateway_outbound_webhooks_events', 'subscription.invoice_issued,subscription.invoice_paid,subscription.invoice_payment_notice,subscription.status_changed,automation.run,automation.failed'), true, 40, 120));
$paymentGatewayInstructionsAr = app_setting_get($conn, 'payment_gateway_instructions_ar', 'استخدم رابط السداد أو بيانات التحويل الظاهرة ثم أرسل مرجع السداد لفريق المتابعة.');
$paymentGatewayInstructionsEn = app_setting_get($conn, 'payment_gateway_instructions_en', 'Use the payment link or transfer details shown, then send the payment reference to the billing team.');
$depositPercent = app_setting_get($conn, 'payment_request_default_percent', '30');
$depositNote = app_setting_get($conn, 'payment_request_default_note', 'عربون');
$aiProvider = app_setting_get($conn, 'ai_provider', 'ollama');
if (!in_array($aiProvider, ['ollama', 'openai', 'gemini', 'openai_compatible'], true)) {
    $aiProvider = 'ollama';
}
$aiEnabled = app_setting_get($conn, 'ai_enabled', '0') === '1';
$aiApiKey = app_setting_get($conn, 'ai_api_key', '');
$aiModel = app_setting_get($conn, 'ai_model', $aiProvider === 'ollama' ? 'llama3.1:8b' : ($aiProvider === 'gemini' ? 'gemini-3-flash-preview' : 'gpt-5.4-mini'));
$aiBaseUrl = app_setting_get($conn, 'ai_base_url', $aiProvider === 'ollama' ? 'http://127.0.0.1:11434/v1' : ($aiProvider === 'gemini' ? 'https://generativelanguage.googleapis.com/v1beta/openai' : 'https://api.openai.com/v1'));
$aiConfigured = $aiProvider === 'ollama'
    ? trim($aiBaseUrl) !== ''
    : (trim($aiApiKey) !== '' || trim((string)app_env('AI_API_KEY', app_env('OPENAI_API_KEY', ''))) !== '');
$aiApiKeyMasked = trim($aiApiKey) !== ''
    ? str_repeat('*', max(0, strlen($aiApiKey) - 6)) . substr($aiApiKey, -6)
    : '';
$etaDefaults = app_eta_einvoice_default_settings();
$etaSettings = app_eta_einvoice_normalize_settings([
    'enabled' => app_setting_get($conn, 'eta_einvoice_enabled', (string)$etaDefaults['enabled']),
    'environment' => app_setting_get($conn, 'eta_einvoice_environment', $etaDefaults['environment']),
    'base_url' => app_setting_get($conn, 'eta_einvoice_base_url', $etaDefaults['base_url']),
    'token_url' => app_setting_get($conn, 'eta_einvoice_token_url', $etaDefaults['token_url']),
    'client_id' => app_setting_get($conn, 'eta_einvoice_client_id', $etaDefaults['client_id']),
    'client_secret' => app_setting_get($conn, 'eta_einvoice_client_secret', $etaDefaults['client_secret']),
    'issuer_rin' => app_setting_get($conn, 'eta_einvoice_issuer_rin', $etaDefaults['issuer_rin']),
    'branch_code' => app_setting_get($conn, 'eta_einvoice_branch_code', $etaDefaults['branch_code']),
    'activity_code' => app_setting_get($conn, 'eta_einvoice_activity_code', $etaDefaults['activity_code']),
    'default_document_type' => app_setting_get($conn, 'eta_einvoice_default_document_type', $etaDefaults['default_document_type']),
    'signing_mode' => app_setting_get($conn, 'eta_einvoice_signing_mode', $etaDefaults['signing_mode']),
    'signing_base_url' => app_setting_get($conn, 'eta_einvoice_signing_base_url', $etaDefaults['signing_base_url']),
    'signing_api_key' => app_setting_get($conn, 'eta_einvoice_signing_api_key', $etaDefaults['signing_api_key']),
    'callback_api_key' => app_setting_get($conn, 'eta_einvoice_callback_api_key', $etaDefaults['callback_api_key']),
    'submission_mode' => app_setting_get($conn, 'eta_einvoice_submission_mode', $etaDefaults['submission_mode']),
    'unit_catalog' => app_setting_get($conn, 'eta_einvoice_unit_catalog', (string)$etaDefaults['unit_catalog']),
    'item_catalog' => app_setting_get($conn, 'eta_einvoice_item_catalog', (string)$etaDefaults['item_catalog']),
    'auto_pull_status' => app_setting_get($conn, 'eta_einvoice_auto_pull_status', (string)$etaDefaults['auto_pull_status']),
    'auto_pull_documents' => app_setting_get($conn, 'eta_einvoice_auto_pull_documents', (string)$etaDefaults['auto_pull_documents']),
    'last_sync_at' => app_setting_get($conn, 'eta_einvoice_last_sync_at', $etaDefaults['last_sync_at']),
    'last_submit_at' => app_setting_get($conn, 'eta_einvoice_last_submit_at', $etaDefaults['last_submit_at']),
]);
$etaSigningModes = app_eta_einvoice_signing_modes();
$etaClientIdMasked = trim($etaSettings['client_id']) !== ''
    ? str_repeat('*', max(0, strlen($etaSettings['client_id']) - 4)) . substr($etaSettings['client_id'], -4)
    : '';
$etaClientSecretMasked = trim($etaSettings['client_secret']) !== ''
    ? str_repeat('*', max(0, strlen($etaSettings['client_secret']) - 4)) . substr($etaSettings['client_secret'], -4)
    : '';
$etaRequiredMasterData = app_eta_einvoice_required_master_data();
$etaUnitRows = app_eta_einvoice_unit_catalog($conn);
if (empty($etaUnitRows)) {
    $etaUnitRows = [['local' => '', 'eta' => '']];
}
$etaItemRows = app_eta_einvoice_item_catalog($conn);
if (empty($etaItemRows)) {
    $etaItemRows = [['local' => '', 'eta' => '', 'code_type' => 'EGS', 'source' => 'manual', 'active' => 1]];
}
$pricingEnabled = app_setting_get($conn, 'pricing_enabled', '0') === '1';
$pricingDefaultsRaw = app_setting_get($conn, 'pricing_defaults', '');
$pricingDefaults = json_decode($pricingDefaultsRaw, true);
if (!is_array($pricingDefaults)) {
    $pricingDefaults = [
        'waste_percent' => 0,
        'waste_sheets' => 0,
        'profit_percent' => 15,
        'misc_cost' => 0,
        'setup_fee' => 0,
        'gather_cost_per_signature' => 0,
        'risk_percent' => 0,
        'reject_percent' => 0,
        'color_test_cost' => 0,
        'internal_transport_cost' => 0,
        'book_mode_enabled' => 0,
        'binding_costs' => [],
    ];
}
$pricingBindingCosts = (array)($pricingDefaults['binding_costs'] ?? []);
$pricingPaperRaw = app_setting_get($conn, 'pricing_paper_types', '');
$pricingMachineRaw = app_setting_get($conn, 'pricing_machines', '');
$pricingFinishRaw = app_setting_get($conn, 'pricing_finishing_ops', '');
$taxRows = app_tax_catalog($conn, false);
$taxLawRows = app_tax_law_catalog($conn, false);
$taxDefaultSalesLaw = app_setting_get($conn, 'tax_default_sales_law', 'vat_2016');
$taxDefaultQuoteLaw = app_setting_get($conn, 'tax_default_quote_law', 'vat_2016');
$pricingPaperRows = json_decode($pricingPaperRaw, true);
$pricingMachineRows = json_decode($pricingMachineRaw, true);
$pricingFinishRows = json_decode($pricingFinishRaw, true);
if (!is_array($pricingPaperRows)) { $pricingPaperRows = []; }
if (!is_array($pricingMachineRows)) { $pricingMachineRows = []; }
if (!is_array($pricingFinishRows)) { $pricingFinishRows = []; }
$pricingMachineRows = md_pricing_normalize_machine_rows($pricingMachineRows);
$pricingFinishRows = md_pricing_normalize_finish_rows($pricingFinishRows);
$pricingPaperText = (string)($_POST['pricing_paper_lines'] ?? '');
$pricingMachineText = (string)($_POST['pricing_machine_lines'] ?? '');
$pricingFinishText = (string)($_POST['pricing_finish_lines'] ?? '');
if ($pricingPaperText === '') {
    $tmp = [];
    foreach ($pricingPaperRows as $row) {
        $tmp[] = implode(' | ', [
            (string)($row['name'] ?? ''),
            (string)($row['price_ton'] ?? 0),
        ]);
    }
    $pricingPaperText = implode(PHP_EOL, $tmp);
}
if ($pricingMachineText === '') {
    $tmp = [];
    foreach ($pricingMachineRows as $row) {
        $tmp[] = implode(' | ', [
            (string)($row['key'] ?? ''),
            (string)($row['label_ar'] ?? ''),
            (string)($row['label_en'] ?? ''),
            (string)($row['price_per_tray'] ?? 0),
            (string)($row['min_trays'] ?? 0),
        ]);
    }
    $pricingMachineText = implode(PHP_EOL, $tmp);
}
if ($pricingFinishText === '') {
    $tmp = [];
    foreach ($pricingFinishRows as $row) {
        $tmp[] = implode(' | ', [
            (string)($row['key'] ?? ''),
            (string)($row['label_ar'] ?? ''),
            (string)($row['label_en'] ?? ''),
            (string)($row['price_piece'] ?? 0),
            (string)($row['price_tray'] ?? 0),
            (string)($row['allow_faces'] ?? 0),
            (string)($row['default_unit'] ?? 'piece'),
        ]);
    }
    $pricingFinishText = implode(PHP_EOL, $tmp);
}
$isEnglish = $appLang === 'en';

$txtTitle = app_t('md.title', 'البيانات الأولية والتخصيص');
$txtSectionBrand = app_t('md.section.brand', 'هوية النظام');
$txtSectionTypes = app_t('md.section.types', 'أنواع العمليات');
$txtSectionTaxes = app_t('md.section.taxes', 'الضرائب والقوانين');
$txtSectionStages = app_t('md.section.stages', 'مراحل العمليات');
$txtSectionCatalog = app_t('md.section.catalog', 'كتالوج الفنيات والخامات');
$txtSectionSeq = app_t('md.section.seq', 'قواعد الترقيم الذكي');
$txtSectionCloudSync = app_t('md.section.cloud_sync', 'الربط السحابي ومزامنة البيانات');
$txtLang = app_t('common.lang', 'اللغة');

$types = app_operation_types($conn, false);
$typesMap = [];
$typeHasModule = [];
foreach ($types as $typeRow) {
    $tKey = (string)($typeRow['type_key'] ?? '');
    if ($tKey === '') {
        continue;
    }
    $typesMap[$tKey] = $typeRow;
    $typeHasModule[$tKey] = is_file(__DIR__ . '/modules/' . $tKey . '.php');
}
if ($scopeType !== '' && !isset($typesMap[$scopeType])) {
    $scopeType = '';
}

$typeNameExpr = $isEnglish ? "COALESCE(NULLIF(t.type_name_en, ''), t.type_name)" : "t.type_name";
$stageRows = [];
$stageSql = "
    SELECT s.*, t.type_name AS type_name_ar, t.type_name_en, {$typeNameExpr} AS type_name
    FROM app_operation_stages s
    LEFT JOIN app_operation_types t ON t.type_key = s.type_key
";
if ($scopeType !== '') {
    $stageSql .= " WHERE s.type_key = ?";
}
$stageSql .= " ORDER BY t.sort_order ASC, s.stage_order ASC, s.id ASC";
if ($scopeType !== '') {
    $stmtStageRows = $conn->prepare($stageSql);
    $stmtStageRows->bind_param('s', $scopeType);
    $stmtStageRows->execute();
    $res = $stmtStageRows->get_result();
    while ($res && ($row = $res->fetch_assoc())) {
        $stageRows[] = $row;
    }
    $stmtStageRows->close();
} else {
    $res = $conn->query($stageSql);
    while ($res && ($row = $res->fetch_assoc())) {
        $stageRows[] = $row;
    }
}

$catalogRows = [];
$catalogSql = "
    SELECT c.*, t.type_name AS type_name_ar, t.type_name_en, {$typeNameExpr} AS type_name
    FROM app_operation_catalog c
    LEFT JOIN app_operation_types t ON t.type_key = c.type_key
";
if ($scopeType !== '') {
    $catalogSql .= " WHERE c.type_key = ?";
}
$catalogSql .= " ORDER BY t.sort_order ASC, c.catalog_group ASC, c.sort_order ASC, c.id ASC";
if ($scopeType !== '') {
    $stmtCatalogRows = $conn->prepare($catalogSql);
    $stmtCatalogRows->bind_param('s', $scopeType);
    $stmtCatalogRows->execute();
    $res = $stmtCatalogRows->get_result();
    while ($res && ($row = $res->fetch_assoc())) {
        $catalogRows[] = $row;
    }
    $stmtCatalogRows->close();
} else {
    $res = $conn->query($catalogSql);
    while ($res && ($row = $res->fetch_assoc())) {
        $catalogRows[] = $row;
    }
}

$catalogOptionsByType = [];
$catalogGlobalPool = [];
$catalogSeen = [];
$catalogGlobalSeen = [];
$resCatalogPool = $conn->query("
    SELECT type_key, item_label, item_label_en
    FROM app_operation_catalog
    WHERE is_active = 1
    ORDER BY type_key ASC, catalog_group ASC, sort_order ASC, id ASC
");
while ($resCatalogPool && ($row = $resCatalogPool->fetch_assoc())) {
    $typeKey = (string)($row['type_key'] ?? '');
    $labels = [
        trim((string)($row['item_label'] ?? '')),
        trim((string)($row['item_label_en'] ?? '')),
    ];
    foreach ($labels as $label) {
        if ($label === '') {
            continue;
        }
        if (!isset($catalogGlobalSeen[$label])) {
            $catalogGlobalSeen[$label] = true;
            $catalogGlobalPool[] = $label;
        }
        $rowKey = $typeKey . '|' . $label;
        if (!isset($catalogSeen[$rowKey])) {
            $catalogSeen[$rowKey] = true;
            if (!isset($catalogOptionsByType[$typeKey])) {
                $catalogOptionsByType[$typeKey] = [];
            }
            $catalogOptionsByType[$typeKey][] = $label;
        }
    }
}

$numberRows = $conn->query("SELECT * FROM app_document_sequences ORDER BY doc_type ASC");
$stageActionDefs = md_stage_action_definitions();
$smartStageTemplates = md_stage_template_definitions();
$cloudSyncSettings = app_cloud_sync_settings($conn);
$cloudSyncIntegrity = app_cloud_sync_integrity_report($conn);
$cloudSyncSequences = app_cloud_sync_sequence_snapshot($conn);
$cloudSyncLogs = [];
if (app_ensure_cloud_sync_schema($conn)) {
    $resCloudLog = $conn->query("
        SELECT id, direction, status, installation_code, license_key, source_domain, sync_mode, numbering_policy, payload_hash, integrity_hash, details, created_at
        FROM app_cloud_sync_runtime_log
        ORDER BY id DESC
        LIMIT 30
    ");
    while ($resCloudLog && ($row = $resCloudLog->fetch_assoc())) {
        $cloudSyncLogs[] = $row;
    }
}

$typeForm = [
    'type_key' => '',
    'type_name' => '',
    'type_name_en' => '',
    'icon_class' => 'fa-circle',
    'default_stage_key' => 'briefing',
    'sort_order' => 100,
];
$stageForm = [
    'stage_id' => 0,
    'stage_type_key' => '',
    'stage_key' => '',
    'stage_name' => '',
    'stage_name_en' => '',
    'stage_order' => 1,
    'default_stage_cost' => '0.00',
    'is_terminal' => 0,
    'stage_actions' => [],
    'stage_required_ops' => [],
    'stage_required_ops_text' => '',
];
$catalogForm = [
    'catalog_item_id' => 0,
    'catalog_type_key' => '',
    'catalog_group' => 'material',
    'item_label' => '',
    'item_label_en' => '',
    'item_sort_order' => 1,
    'default_unit_price' => '0.00',
];

$editTypeKey = strtolower(trim((string)($_GET['edit_type'] ?? '')));
$editStageId = (int)($_GET['edit_stage_id'] ?? 0);
$editCatalogId = (int)($_GET['edit_catalog_id'] ?? 0);
$isTypeEdit = false;
$isStageEdit = false;
$isCatalogEdit = false;

if ($editTypeKey !== '' && preg_match('/^[a-z0-9_]{2,50}$/', $editTypeKey)) {
    $stmtEditType = $conn->prepare("
        SELECT type_key, type_name, type_name_en, icon_class, default_stage_key, sort_order
        FROM app_operation_types
        WHERE type_key = ?
        LIMIT 1
    ");
    $stmtEditType->bind_param('s', $editTypeKey);
    $stmtEditType->execute();
    $row = $stmtEditType->get_result()->fetch_assoc();
    $stmtEditType->close();
    if ($row) {
        $typeForm = [
            'type_key' => (string)$row['type_key'],
            'type_name' => (string)$row['type_name'],
            'type_name_en' => (string)($row['type_name_en'] ?? ''),
            'icon_class' => (string)($row['icon_class'] ?? 'fa-circle'),
            'default_stage_key' => (string)($row['default_stage_key'] ?? 'briefing'),
            'sort_order' => (int)($row['sort_order'] ?? 100),
        ];
        $isTypeEdit = true;
        $activeTab = 'types';
        $scopeType = (string)$row['type_key'];
    }
}

if ($editStageId > 0) {
    $stmtEditStage = $conn->prepare("
        SELECT id, type_key, stage_key, stage_name, stage_name_en, stage_order, default_stage_cost, is_terminal, stage_actions_json, stage_required_ops_json
        FROM app_operation_stages
        WHERE id = ?
        LIMIT 1
    ");
    $stmtEditStage->bind_param('i', $editStageId);
    $stmtEditStage->execute();
    $row = $stmtEditStage->get_result()->fetch_assoc();
    $stmtEditStage->close();
    if ($row) {
        $requiredOps = md_string_list((string)($row['stage_required_ops_json'] ?? '[]'), false, 120, 140);
        $stageForm = [
            'stage_id' => (int)$row['id'],
            'stage_type_key' => (string)$row['type_key'],
            'stage_key' => (string)$row['stage_key'],
            'stage_name' => (string)$row['stage_name'],
            'stage_name_en' => (string)($row['stage_name_en'] ?? ''),
            'stage_order' => (int)($row['stage_order'] ?? 1),
            'default_stage_cost' => (string)number_format((float)($row['default_stage_cost'] ?? 0), 2, '.', ''),
            'is_terminal' => (int)($row['is_terminal'] ?? 0) === 1 ? 1 : 0,
            'stage_actions' => md_normalize_stage_actions((string)($row['stage_actions_json'] ?? '[]')),
            'stage_required_ops' => $requiredOps,
            'stage_required_ops_text' => implode(', ', $requiredOps),
        ];
        $isStageEdit = true;
        $activeTab = 'stages';
        $scopeType = (string)$row['type_key'];
    }
}

if ($editCatalogId > 0) {
    $stmtEditCatalog = $conn->prepare("
        SELECT id, type_key, catalog_group, item_label, item_label_en, sort_order, default_unit_price
        FROM app_operation_catalog
        WHERE id = ?
        LIMIT 1
    ");
    $stmtEditCatalog->bind_param('i', $editCatalogId);
    $stmtEditCatalog->execute();
    $row = $stmtEditCatalog->get_result()->fetch_assoc();
    $stmtEditCatalog->close();
    if ($row) {
        $catalogForm = [
            'catalog_item_id' => (int)$row['id'],
            'catalog_type_key' => (string)$row['type_key'],
            'catalog_group' => (string)$row['catalog_group'],
            'item_label' => (string)$row['item_label'],
            'item_label_en' => (string)($row['item_label_en'] ?? ''),
            'item_sort_order' => (int)($row['sort_order'] ?? 1),
            'default_unit_price' => (string)number_format((float)($row['default_unit_price'] ?? 0), 2, '.', ''),
        ];
        $isCatalogEdit = true;
        $activeTab = 'catalog';
        $scopeType = (string)$row['type_key'];
    }
}

if ((string)$stageForm['stage_type_key'] === '' && $scopeType !== '') {
    $stageForm['stage_type_key'] = $scopeType;
}
if ((string)$catalogForm['catalog_type_key'] === '' && $scopeType !== '') {
    $catalogForm['catalog_type_key'] = $scopeType;
}
$GLOBALS['md_scope_type'] = $scopeType;

require 'header.php';
?>
<style>
    .md-wrap { max-width: 1250px; margin: 0 auto; padding: 20px; }
    .md-tabs { display:flex; flex-wrap:wrap; gap:8px; margin:0 0 14px; }
    .md-tab-link {
        display:inline-flex;
        align-items:center;
        justify-content:center;
        min-height:38px;
        padding: 0 14px;
        border-radius: 11px;
        border: 1px solid #3a3a3a;
        background: #171717;
        color: #d8d8d8;
        font-weight: 700;
        text-decoration: none;
    }
    .md-tab-link.active {
        background: linear-gradient(140deg, var(--gold-primary), #ad8529);
        border-color: transparent;
        color: #090909;
    }
    .md-card { background: #151515; border: 1px solid #2f2f2f; border-radius: 14px; padding: 16px; margin-bottom: 16px; }
    .md-title { margin: 0 0 12px; color: var(--gold-primary); font-size: 1.05rem; }
    .md-grid { display: grid; grid-template-columns: repeat(auto-fit,minmax(210px,1fr)); gap: 10px; }
    .md-input, .md-select {
        width: 100%;
        background: #0d0d0d;
        color: #fff;
        border: 1px solid #333;
        border-radius: 9px;
        padding: 10px;
        font-family: 'Cairo', sans-serif;
    }
    .md-btn {
        border: 1px solid #3f3f3f;
        background: linear-gradient(140deg, var(--gold-primary), #ad8529);
        color: #000;
        border-radius: 10px;
        padding: 10px 14px;
        font-family: 'Cairo', sans-serif;
        font-weight: 800;
        cursor: pointer;
    }
    .md-btn-danger {
        border: 1px solid rgba(231,76,60,0.55);
        background: rgba(231,76,60,0.14);
        color: #ffb7af;
        border-radius: 9px;
        padding: 7px 10px;
        font-family: 'Cairo', sans-serif;
        cursor: pointer;
    }
    .md-btn-neutral {
        border: 1px solid #3f3f3f;
        background: #1c1c1c;
        color: #ddd;
        border-radius: 9px;
        padding: 7px 10px;
        font-family: 'Cairo', sans-serif;
        cursor: pointer;
    }
    .md-help { display:block; margin-top:5px; color:#9f9f9f; font-size:0.79rem; line-height:1.45; }
    .md-note { background:#101010; border:1px dashed #373737; border-radius:10px; padding:10px; color:#bfbfbf; font-size:0.84rem; margin-bottom:12px; }
    .md-theme-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(170px,1fr)); gap:12px; }
    .md-theme-input { position:absolute; opacity:0; pointer-events:none; }
    .md-theme-card {
        display:block;
        position:relative;
        min-height:128px;
        border-radius:16px;
        border:1px solid #2f2f2f;
        overflow:hidden;
        background:#101010;
        cursor:pointer;
        transition:transform .18s ease,border-color .18s ease,box-shadow .18s ease;
    }
    .md-theme-card:hover { transform:translateY(-1px); border-color:rgba(255,255,255,.16); }
    .md-theme-input:checked + .md-theme-card {
        border-color:var(--ae-gold);
        box-shadow:0 0 0 1px color-mix(in srgb, var(--ae-gold) 55%, transparent), 0 18px 34px rgba(0,0,0,.24);
    }
    .md-theme-preview {
        height:72px;
        padding:10px;
        display:grid;
        align-content:end;
        gap:8px;
    }
    .md-theme-swatches { display:flex; gap:7px; }
    .md-theme-swatches span {
        width:18px;
        height:18px;
        border-radius:50%;
        border:1px solid rgba(255,255,255,.18);
    }
    .md-theme-lines { display:grid; gap:6px; }
    .md-theme-lines i {
        display:block;
        height:7px;
        border-radius:999px;
        background:rgba(255,255,255,.14);
    }
    .md-theme-lines i:first-child { width:62%; }
    .md-theme-lines i:last-child { width:84%; }
    .md-theme-body { padding:12px 12px 14px; display:grid; gap:4px; }
    .md-theme-name { font-size:.93rem; font-weight:800; color:#f5f5f5; }
    .md-theme-key { font-size:.75rem; color:#999; }
    .md-table-wrap { width: 100%; overflow-x: auto; -webkit-overflow-scrolling: touch; border-radius: 12px; }
    .md-table { width: 100%; border-collapse: collapse; min-width: 680px; }
    .md-table th, .md-table td { border-bottom: 1px solid #262626; padding: 10px 8px; text-align: right; font-size: 0.9rem; }
    .md-table th { color: #c9c9c9; background: #111; }
    .md-btn-mini {
        border: 1px solid #3f3f3f;
        background: #171717;
        color: #ddd;
        border-radius: 8px;
        padding: 6px 8px;
        font-family: 'Cairo', sans-serif;
        cursor: pointer;
    }
    .md-link-mini {
        display: inline-block;
        border: 1px solid #4a4a4a;
        background: #151515;
        color: #e8e8e8;
        border-radius: 8px;
        padding: 6px 8px;
        text-decoration: none;
    }
    .md-form-actions { display:flex; gap:8px; align-items:center; flex-wrap:wrap; }
    .md-alert { border-radius: 10px; padding: 10px 12px; margin-bottom: 12px; font-weight: 700; }
    .md-alert.ok { background: rgba(46,204,113,0.14); color: #9df2c2; border: 1px solid rgba(46,204,113,0.45); }
    .md-alert.err { background: rgba(231,76,60,0.14); color: #ffb5ad; border: 1px solid rgba(231,76,60,0.45); }
    .md-scope-row { display:flex; gap:10px; align-items:flex-end; flex-wrap:wrap; }
    .md-scope-row > div { min-width:240px; flex:1; }
    .md-check-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(220px,1fr)); gap:8px; }
    .md-check-item { display:flex; gap:8px; align-items:flex-start; padding:9px; border:1px solid #353535; border-radius:10px; background:#111; color:#dedede; }
    .md-check-item input { margin-top:3px; }
    .md-pill-wrap { display:flex; flex-wrap:wrap; gap:6px; margin-top:8px; }
    .md-pill { display:inline-flex; align-items:center; border:1px solid #3b3b3b; background:#141414; color:#d8d8d8; border-radius:999px; padding:4px 9px; font-size:0.78rem; cursor:default; }
    .md-pill.clickable { cursor:pointer; }
    .md-pill:hover.clickable { background:#1a1a1a; border-color:#6d5c2f; }
    .md-search-shell { border:1px solid #353535; border-radius:14px; background:#111; padding:12px; }
    .md-search-results { margin-top:10px; max-height:280px; overflow:auto; display:grid; gap:8px; }
    .md-search-result { display:flex; justify-content:space-between; gap:10px; align-items:center; border:1px solid #2f2f2f; border-radius:12px; padding:10px 12px; background:#151515; }
    .md-search-result strong { color:#f3d67f; }
    .md-search-result small { display:block; color:#9a9a9a; margin-top:2px; }
    .md-selected-list { display:flex; flex-wrap:wrap; gap:8px; margin-top:10px; }
    .md-selected-chip { display:inline-flex; align-items:center; gap:8px; border:1px solid rgba(212,175,55,.35); background:rgba(212,175,55,.08); color:#f1e0a6; border-radius:999px; padding:8px 10px; }
    .md-chip-remove { border:none; background:transparent; color:#ffb5ad; cursor:pointer; font-weight:700; padding:0; }
    .md-btn-mini { border:1px solid rgba(212,175,55,.32); background:#1a1710; color:#f0d585; border-radius:10px; padding:7px 10px; cursor:pointer; font-weight:700; }
    .md-btn-mini:disabled { opacity:.45; cursor:not-allowed; }
    .md-smart-stage-card { border:1px solid #2f2f2f; border-radius:12px; padding:12px; background:#111; margin-top:10px; }
    .md-smart-stage-card.hidden { display:none; }
    .md-smart-stage-head { display:flex; justify-content:space-between; gap:8px; align-items:center; color:#f1d27b; font-weight:700; margin-bottom:8px; }
    .md-small-muted { color:#999; font-size:0.8rem; }
    @media (max-width: 760px) {
        .md-wrap { padding: 14px; }
        .md-tabs { flex-wrap: nowrap; overflow-x: auto; padding-bottom: 4px; }
        .md-tab-link { white-space: nowrap; flex: 0 0 auto; }
        .md-card { padding: 14px; border-radius: 12px; }
        .md-scope-row > div { min-width: 100%; }
    }
</style>

<div class="md-wrap">
    <h2 class="ai-title" style="margin-top:0;"><?php echo app_h($txtTitle); ?></h2>
    <div class="md-note">
        <?php echo app_h($isEnglish ? 'Tip: keys (type_key, stage_key, group) are internal IDs. Keep them stable, and edit display names freely.' : 'تنبيه: مفاتيح الحقول مثل type_key و stage_key و group هي معرفات داخلية، حافظ عليها ثابتة، ويمكنك تعديل الاسم الظاهر بحرية.'); ?>
    </div>
    <?php if ($msg !== ''): ?>
        <div class="md-alert <?php echo $msgType === 'err' ? 'err' : 'ok'; ?>"><?php echo app_h($msg); ?></div>
    <?php endif; ?>

    <div class="md-tabs">
        <?php if (in_array('branding', $visibleTabs, true)): ?><a class="md-tab-link <?php echo $activeTab === 'branding' ? 'active' : ''; ?>" href="<?php echo app_h(md_tab_url('branding')); ?>"><?php echo app_h($txtSectionBrand); ?></a><?php endif; ?>
        <?php if (in_array('currencies', $visibleTabs, true)): ?><a class="md-tab-link <?php echo $activeTab === 'currencies' ? 'active' : ''; ?>" href="<?php echo app_h(md_tab_url('currencies')); ?>"><?php echo app_h($isEnglish ? 'Currency Rates' : 'أسعار العملات'); ?></a><?php endif; ?>
        <?php if (in_array('payments', $visibleTabs, true)): ?><a class="md-tab-link <?php echo $activeTab === 'payments' ? 'active' : ''; ?>" href="<?php echo app_h(md_tab_url('payments')); ?>"><?php echo app_h($isEnglish ? 'Payment Methods' : 'وسائل الدفع'); ?></a><?php endif; ?>
        <?php if (in_array('ai', $visibleTabs, true)): ?><a class="md-tab-link <?php echo $activeTab === 'ai' ? 'active' : ''; ?>" href="<?php echo app_h(md_tab_url('ai')); ?>"><?php echo app_h($isEnglish ? 'AI Provider' : 'مزود الذكاء الاصطناعي'); ?></a><?php endif; ?>
        <?php if (in_array('eta', $visibleTabs, true)): ?><a class="md-tab-link <?php echo $activeTab === 'eta' ? 'active' : ''; ?>" href="<?php echo app_h(md_tab_url('eta')); ?>"><?php echo app_h($isEnglish ? 'ETA eInvoice' : 'الفاتورة الإلكترونية ETA'); ?></a><?php endif; ?>
        <?php if (in_array('taxes', $visibleTabs, true)): ?><a class="md-tab-link <?php echo $activeTab === 'taxes' ? 'active' : ''; ?>" href="<?php echo app_h(md_tab_url('taxes')); ?>"><?php echo app_h($txtSectionTaxes); ?></a><?php endif; ?>
        <?php if (in_array('types', $visibleTabs, true)): ?><a class="md-tab-link <?php echo $activeTab === 'types' ? 'active' : ''; ?>" href="<?php echo app_h(md_tab_url('types')); ?>"><?php echo app_h($txtSectionTypes); ?></a><?php endif; ?>
        <?php if (in_array('stages', $visibleTabs, true)): ?><a class="md-tab-link <?php echo $activeTab === 'stages' ? 'active' : ''; ?>" href="<?php echo app_h(md_tab_url('stages')); ?>"><?php echo app_h($txtSectionStages); ?></a><?php endif; ?>
        <?php if (in_array('catalog', $visibleTabs, true)): ?><a class="md-tab-link <?php echo $activeTab === 'catalog' ? 'active' : ''; ?>" href="<?php echo app_h(md_tab_url('catalog')); ?>"><?php echo app_h($txtSectionCatalog); ?></a><?php endif; ?>
        <?php if (in_array('numbering', $visibleTabs, true)): ?><a class="md-tab-link <?php echo $activeTab === 'numbering' ? 'active' : ''; ?>" href="<?php echo app_h(md_tab_url('numbering')); ?>"><?php echo app_h($txtSectionSeq); ?></a><?php endif; ?>
        <?php if (in_array('cloud_sync', $visibleTabs, true)): ?><a class="md-tab-link <?php echo $activeTab === 'cloud_sync' ? 'active' : ''; ?>" href="<?php echo app_h(md_tab_url('cloud_sync')); ?>"><?php echo app_h($txtSectionCloudSync); ?></a><?php endif; ?>
        <?php if (in_array('pricing', $visibleTabs, true)): ?><a class="md-tab-link <?php echo $activeTab === 'pricing' ? 'active' : ''; ?>" href="<?php echo app_h(md_tab_url('pricing')); ?>"><?php echo app_h($isEnglish ? 'Print Pricing' : 'تسعير الطباعة'); ?></a><?php endif; ?>
    </div>

    <?php if (in_array($activeTab, ['stages', 'catalog'], true)): ?>
    <div class="md-card">
        <div class="md-title" style="margin-bottom:8px;"><?php echo app_h($isEnglish ? 'Isolated Edit Context' : 'سياق تعديل منفصل'); ?></div>
        <form method="get" class="md-scope-row">
            <input type="hidden" name="tab" value="<?php echo app_h($activeTab); ?>">
            <div>
                <label><?php echo app_h($isEnglish ? 'Operation Type' : 'نوع العملية'); ?></label>
                <select class="md-select" name="scope_type">
                    <option value=""><?php echo app_h($isEnglish ? 'All operation types' : 'كل أنواع العمليات'); ?></option>
                    <?php foreach ($types as $type): $tk = (string)$type['type_key']; ?>
                        <option value="<?php echo app_h($tk); ?>" <?php echo $scopeType === $tk ? 'selected' : ''; ?>>
                            <?php echo app_h((string)$type['type_name']); ?>
                            <?php if (!empty($typeHasModule[$tk])): ?>
                                <?php echo app_h($isEnglish ? ' (module)' : ' (موديول)'); ?>
                            <?php endif; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <span class="md-help"><?php echo app_h($isEnglish ? 'Select one operation to load its stages and catalog separately without overlap.' : 'اختر عملية واحدة لعرض وتعديل مراحلها وكتالوجها بشكل منفصل بدون تداخل مع بقية العمليات.'); ?></span>
            </div>
            <div class="md-form-actions">
                <button class="md-btn" type="submit"><?php echo app_h($isEnglish ? 'Load Data' : 'استدعاء البيانات'); ?></button>
                <a class="md-link-mini" href="<?php echo app_h(md_tab_url($activeTab, ['scope_type' => ''])); ?>"><?php echo app_h($isEnglish ? 'Clear Filter' : 'إلغاء التصفية'); ?></a>
            </div>
        </form>
    </div>
    <?php endif; ?>

    <?php if ($activeTab === 'branding'): ?>
    <div class="md-card">
        <h3 class="md-title"><?php echo app_h($txtSectionBrand); ?></h3>
        <form method="post" enctype="multipart/form-data" class="md-grid">
            <?php echo app_csrf_input(); ?>
            <input type="hidden" name="action" value="save_branding">
            <input type="hidden" name="tab" value="branding">
            <div style="grid-column:1/-1;">
                <div class="md-note" style="margin:0;">
                    <?php echo app_h($isEnglish ? 'Institution profile fields are used dynamically in invoices, receipts, and reports with automatic QR generation.' : 'بيانات المؤسسة التالية تُستخدم ديناميكياً في الفواتير والإيصالات والتقارير مع توليد QR تلقائياً.'); ?>
                </div>
            </div>
            <div style="grid-column:1/-1;">
                <div class="md-note" style="margin:0;">
                    <?php echo app_h($isEnglish ? 'This section now includes 20 visible themes for the full system UI and professional outputs.' : 'هذا القسم يتضمن الآن 20 ثيمًا مرئيًا للنظام بالكامل وللفواتير والإيصالات وعروض الأسعار.'); ?>
                </div>
            </div>
            <div>
                <label>اسم النظام</label>
                <input class="md-input" name="app_name" value="<?php echo app_h($appName); ?>">
                <span class="md-help"><?php echo app_h($isEnglish ? 'Displayed in header, login screen, and outputs.' : 'يظهر في الهيدر وشاشة الدخول وكل المخرجات.'); ?></span>
            </div>
            <div>
                <label><?php echo app_h($isEnglish ? 'Institution Name' : 'اسم المؤسسة'); ?></label>
                <input class="md-input" name="org_name" value="<?php echo app_h($orgName); ?>">
                <span class="md-help"><?php echo app_h($isEnglish ? 'Official institution name for all print outputs.' : 'الاسم الرسمي للمؤسسة في كل المخرجات.'); ?></span>
            </div>
            <div>
                <label>لون الهوية</label>
                <input class="md-input" name="theme_color" value="<?php echo app_h($themeColor); ?>">
                <span class="md-help"><?php echo app_h($isEnglish ? 'Primary color for buttons and highlights.' : 'اللون الأساسي للأزرار والعناصر البارزة.'); ?></span>
            </div>
            <div>
                <label>المنطقة الزمنية</label>
                <input class="md-input" name="timezone" value="<?php echo app_h($timezone); ?>">
                <span class="md-help"><?php echo app_h($isEnglish ? 'Used for dates in operations, reports, and numbering reset rules.' : 'تُستخدم في التواريخ داخل العمليات والتقارير وسياسات إعادة الترقيم.'); ?></span>
            </div>
            <div>
                <label><?php echo app_h($txtLang); ?></label>
                <select class="md-select" name="app_lang">
                    <?php foreach ($supportedLangs as $langCode => $langLabel): ?>
                        <option value="<?php echo app_h($langCode); ?>" <?php echo $appLang === $langCode ? 'selected' : ''; ?>>
                            <?php echo app_h($langLabel); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <span class="md-help"><?php echo app_h($isEnglish ? 'Default system language (users can still switch from the header).' : 'اللغة الافتراضية للنظام (ويمكن للمستخدم التبديل من الهيدر).'); ?></span>
            </div>
            <div>
                <label>الشعار</label>
                <input class="md-input" type="file" name="logo_file" accept=".png,.jpg,.jpeg,.webp,.svg">
                <span class="md-help"><?php echo app_h($isEnglish ? 'Recommended transparent PNG/WebP for best output quality.' : 'يفضّل شعار PNG/WebP بخلفية شفافة للحصول على أفضل جودة.'); ?></span>
            </div>
            <div style="grid-column:1/-1;">
                <label><?php echo app_h($isEnglish ? 'System UI Theme' : 'ثيم النظام بالكامل'); ?></label>
                <div class="md-help" style="margin:0 0 10px;"><?php echo app_h($isEnglish ? 'Applies to header, pages, cards, and shared UI. Users can override it from their profile.' : 'يطبّق على الهيدر والصفحات والكروت والواجهة المشتركة، ويمكن لكل مستخدم تجاوزه من صفحة حسابه.'); ?></div>
                <div class="md-theme-grid">
                    <?php foreach ($uiThemePresets as $presetKey => $preset): ?>
                        <?php
                        $accent = (string)($preset['accent'] ?? '#d4af37');
                        $accentSoft = (string)($preset['accent_soft'] ?? '#f2d47a');
                        $bg = (string)($preset['bg'] ?? '#050505');
                        $card = (string)($preset['card'] ?? '#121212');
                        $textColor = (string)($preset['text'] ?? '#f2f2f2');
                        $label = (string)($isEnglish ? ($preset['label'] ?? $presetKey) : ($preset['label_ar'] ?? $preset['label'] ?? $presetKey));
                        ?>
                        <div>
                            <input class="md-theme-input" type="radio" id="ui_theme_<?php echo app_h($presetKey); ?>" name="ui_theme_preset" value="<?php echo app_h($presetKey); ?>" <?php echo $uiThemePreset === $presetKey ? 'checked' : ''; ?>>
                            <label class="md-theme-card" for="ui_theme_<?php echo app_h($presetKey); ?>">
                                <div class="md-theme-preview" style="background:linear-gradient(160deg, <?php echo app_h($bg); ?>, <?php echo app_h($card); ?>);">
                                    <div class="md-theme-swatches">
                                        <span style="background:<?php echo app_h($accent); ?>;"></span>
                                        <span style="background:<?php echo app_h($accentSoft); ?>;"></span>
                                        <span style="background:<?php echo app_h($card); ?>;"></span>
                                    </div>
                                    <div class="md-theme-lines">
                                        <i style="background:<?php echo app_h($textColor); ?>;"></i>
                                        <i style="background:<?php echo app_h($accentSoft); ?>;"></i>
                                    </div>
                                </div>
                                <div class="md-theme-body">
                                    <div class="md-theme-name"><?php echo app_h($label); ?></div>
                                    <div class="md-theme-key"><?php echo app_h($presetKey); ?></div>
                                </div>
                            </label>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <div style="grid-column:1/-1;">
                <label><?php echo app_h($isEnglish ? 'Output Theme' : 'ثيم المخرجات'); ?></label>
                <div class="md-help" style="margin:0 0 10px;"><?php echo app_h($isEnglish ? 'Controls invoice, voucher, and quotation print views.' : 'يتحكم في منظر الفواتير والسندات وعروض الأسعار عند العرض والطباعة.'); ?></div>
                <div class="md-theme-grid">
                    <?php foreach ($outputThemePresets as $presetKey => $preset): ?>
                        <?php
                        $accent = (string)($preset['accent'] ?? '#d4af37');
                        $accentSoft = (string)($preset['accent_soft'] ?? '#f2d47a');
                        $paper = (string)($preset['paper'] ?? '#ffffff');
                        $ink = (string)($preset['ink'] ?? '#171717');
                        $tint = (string)($preset['tint'] ?? '#f6efe0');
                        $label = (string)($isEnglish ? ($preset['label'] ?? $presetKey) : ($preset['label_ar'] ?? $preset['label'] ?? $presetKey));
                        ?>
                        <div>
                            <input class="md-theme-input" type="radio" id="output_theme_<?php echo app_h($presetKey); ?>" name="output_theme_preset" value="<?php echo app_h($presetKey); ?>" <?php echo $outputThemePreset === $presetKey ? 'checked' : ''; ?>>
                            <label class="md-theme-card" for="output_theme_<?php echo app_h($presetKey); ?>">
                                <div class="md-theme-preview" style="background:linear-gradient(160deg, <?php echo app_h($paper); ?>, <?php echo app_h($tint); ?>);">
                                    <div class="md-theme-swatches">
                                        <span style="background:<?php echo app_h($accent); ?>;"></span>
                                        <span style="background:<?php echo app_h($accentSoft); ?>;"></span>
                                        <span style="background:<?php echo app_h($ink); ?>;"></span>
                                    </div>
                                    <div class="md-theme-lines">
                                        <i style="background:<?php echo app_h($ink); ?>;"></i>
                                        <i style="background:<?php echo app_h($accent); ?>;"></i>
                                    </div>
                                </div>
                                <div class="md-theme-body">
                                    <div class="md-theme-name"><?php echo app_h($label); ?></div>
                                    <div class="md-theme-key"><?php echo app_h($presetKey); ?></div>
                                </div>
                            </label>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <div>
                <label><?php echo app_h($isEnglish ? 'Legal Name' : 'الاسم القانوني'); ?></label>
                <input class="md-input" name="org_legal_name" value="<?php echo app_h($orgLegalName); ?>">
            </div>
            <div>
                <label><?php echo app_h($isEnglish ? 'Tax Number' : 'رقم التسجيل الضريبي'); ?></label>
                <input class="md-input" name="org_tax_number" value="<?php echo app_h($orgTaxNumber); ?>">
            </div>
            <div>
                <label><?php echo app_h($isEnglish ? 'Commercial Register' : 'رقم السجل التجاري'); ?></label>
                <input class="md-input" name="org_commercial_number" value="<?php echo app_h($orgCommercialNumber); ?>">
            </div>
            <div>
                <label><?php echo app_h($isEnglish ? 'Primary Phone' : 'هاتف رئيسي'); ?></label>
                <input class="md-input" name="org_phone_primary" value="<?php echo app_h($orgPhonePrimary); ?>">
            </div>
            <div>
                <label><?php echo app_h($isEnglish ? 'Secondary Phone' : 'هاتف إضافي'); ?></label>
                <input class="md-input" name="org_phone_secondary" value="<?php echo app_h($orgPhoneSecondary); ?>">
            </div>
            <div>
                <label><?php echo app_h($isEnglish ? 'Email' : 'البريد الإلكتروني'); ?></label>
                <input class="md-input" name="org_email" value="<?php echo app_h($orgEmail); ?>" placeholder="name@domain.com">
            </div>
            <div>
                <label><?php echo app_h($isEnglish ? 'Website URL' : 'الموقع الإلكتروني'); ?></label>
                <input class="md-input" name="org_website" value="<?php echo app_h($orgWebsite); ?>" placeholder="https://example.com">
            </div>
            <div style="grid-column:1/-1;">
                <label><?php echo app_h($isEnglish ? 'Address' : 'العنوان'); ?></label>
                <input class="md-input" name="org_address" value="<?php echo app_h($orgAddress); ?>">
            </div>
            <div>
                <label><?php echo app_h($isEnglish ? 'WhatsApp URL' : 'رابط واتساب'); ?></label>
                <input class="md-input" name="org_social_whatsapp" value="<?php echo app_h($orgSocialWhatsapp); ?>" placeholder="https://wa.me/...">
            </div>
            <div>
                <label><?php echo app_h($isEnglish ? 'Facebook URL' : 'رابط فيسبوك'); ?></label>
                <input class="md-input" name="org_social_facebook" value="<?php echo app_h($orgSocialFacebook); ?>" placeholder="https://facebook.com/...">
            </div>
            <div>
                <label><?php echo app_h($isEnglish ? 'Instagram URL' : 'رابط إنستجرام'); ?></label>
                <input class="md-input" name="org_social_instagram" value="<?php echo app_h($orgSocialInstagram); ?>" placeholder="https://instagram.com/...">
            </div>
            <div>
                <label><?php echo app_h($isEnglish ? 'LinkedIn URL' : 'رابط لينكدإن'); ?></label>
                <input class="md-input" name="org_social_linkedin" value="<?php echo app_h($orgSocialLinkedin); ?>" placeholder="https://linkedin.com/...">
            </div>
            <div>
                <label><?php echo app_h($isEnglish ? 'X / Twitter URL' : 'رابط X / تويتر'); ?></label>
                <input class="md-input" name="org_social_x" value="<?php echo app_h($orgSocialX); ?>" placeholder="https://x.com/...">
            </div>
            <div>
                <label><?php echo app_h($isEnglish ? 'YouTube URL' : 'رابط يوتيوب'); ?></label>
                <input class="md-input" name="org_social_youtube" value="<?php echo app_h($orgSocialYoutube); ?>" placeholder="https://youtube.com/...">
            </div>
            <div style="grid-column:1/-1;">
                <label><?php echo app_h($isEnglish ? 'Extra footer note (optional)' : 'ملاحظة إضافية بالفوتر (اختياري)'); ?></label>
                <textarea class="md-input" rows="2" name="org_footer_note"><?php echo app_h($orgFooterNote); ?></textarea>
            </div>
            <div style="grid-column:1/-1;">
                <label><?php echo app_h($isEnglish ? 'Output visibility control' : 'التحكم في ظهور عناصر المخرجات'); ?></label>
                <div class="md-check-grid">
                    <label class="md-check-item"><input type="checkbox" name="output_show_header" value="1" <?php echo $outputShowHeader ? 'checked' : ''; ?>><span><?php echo app_h($isEnglish ? 'Show header block in outputs' : 'إظهار الهيدر في المخرجات'); ?></span></label>
                    <label class="md-check-item"><input type="checkbox" name="output_show_footer" value="1" <?php echo $outputShowFooter ? 'checked' : ''; ?>><span><?php echo app_h($isEnglish ? 'Show footer block in outputs' : 'إظهار الفوتر في المخرجات'); ?></span></label>
                    <label class="md-check-item"><input type="checkbox" name="output_show_logo" value="1" <?php echo $outputShowLogo ? 'checked' : ''; ?>><span><?php echo app_h($isEnglish ? 'Show logo in outputs' : 'إظهار الشعار في المخرجات'); ?></span></label>
                    <label class="md-check-item"><input type="checkbox" name="output_show_qr" value="1" <?php echo $outputShowQr ? 'checked' : ''; ?>><span><?php echo app_h($isEnglish ? 'Enable auto QR in outputs' : 'تفعيل QR تلقائي في المخرجات'); ?></span></label>
                </div>
            </div>
            <div style="grid-column:1/-1;">
                <label><?php echo app_h($isEnglish ? 'Fields shown in output header' : 'البيانات الظاهرة في هيدر المخرجات'); ?></label>
                <div class="md-check-grid">
                    <?php foreach ($brandFieldDefs as $fieldKey => $labels): ?>
                        <label class="md-check-item">
                            <input type="checkbox" name="output_header_items[]" value="<?php echo app_h($fieldKey); ?>" <?php echo in_array($fieldKey, $outputHeaderItems, true) ? 'checked' : ''; ?>>
                            <span><?php echo app_h((string)($isEnglish ? $labels['en'] : $labels['ar'])); ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <div style="grid-column:1/-1;">
                <label><?php echo app_h($isEnglish ? 'Fields shown in output footer' : 'البيانات الظاهرة في فوتر المخرجات'); ?></label>
                <div class="md-check-grid">
                    <?php foreach ($brandFieldDefs as $fieldKey => $labels): ?>
                        <label class="md-check-item">
                            <input type="checkbox" name="output_footer_items[]" value="<?php echo app_h($fieldKey); ?>" <?php echo in_array($fieldKey, $outputFooterItems, true) ? 'checked' : ''; ?>>
                            <span><?php echo app_h((string)($isEnglish ? $labels['en'] : $labels['ar'])); ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <div style="display:flex; align-items:flex-end;">
                <button class="md-btn" type="submit"><?php echo app_h(app_t('md.btn.save', 'حفظ الهوية')); ?></button>
            </div>
        </form>
        <div style="margin-top:10px; color:#a6a6a6; font-size:0.85rem;">الشعار الحالي: <code><?php echo app_h($logoPath); ?></code></div>
        <form method="post" style="margin-top:8px;">
            <?php echo app_csrf_input(); ?>
            <input type="hidden" name="action" value="clear_logo">
            <input type="hidden" name="tab" value="branding">
            <button type="submit" class="md-btn-neutral" onclick="return confirm('إرجاع الشعار للوضع الافتراضي؟')"><?php echo app_h($isEnglish ? 'Reset Logo' : 'إرجاع الشعار الافتراضي'); ?></button>
        </form>
    </div>
    <?php endif; ?>

    <?php if ($activeTab === 'currencies'): ?>
    <div class="md-card">
        <h3 class="md-title"><?php echo app_h($isEnglish ? 'Live Currency Rates' : 'أسعار العملات المباشرة'); ?></h3>
        <form method="post" class="md-grid" id="fx-settings-form">
            <?php echo app_csrf_input(); ?>
            <input type="hidden" name="action" value="save_fx_widget_settings">
            <input type="hidden" name="tab" value="currencies">
            <input type="hidden" name="fx_widget_currencies_payload" id="fx_widget_currencies_payload" value="<?php echo app_h(is_string($fxCurrencySelectedJson) ? $fxCurrencySelectedJson : '[]'); ?>">
            <div style="grid-column:1/-1;">
                <div class="md-note" style="margin:0;">
                    <?php echo app_h($isEnglish ? 'Rates are pulled from a direct public engine without API keys. Choose a base currency, search any visible currency, then add it to the card.' : 'الأسعار تُسحب من محرك مباشر عام بدون API. اختر عملة الأساس، ثم ابحث عن أي عملة وأدرجها في الكارت.'); ?>
                </div>
            </div>
            <div>
                <label><?php echo app_h($isEnglish ? 'Base Currency' : 'عملة الأساس'); ?></label>
                <select class="md-select" name="fx_widget_base_currency" id="fx_widget_base_currency">
                    <?php foreach ($fxCurrencyCatalog as $currencyCode => $currencyMeta): ?>
                        <option value="<?php echo app_h($currencyCode); ?>" <?php echo $fxWidgetSettings['base_currency'] === $currencyCode ? 'selected' : ''; ?>>
                            <?php echo app_h($currencyCode . ' - ' . (string)($isEnglish ? ($currencyMeta['en'] ?? $currencyCode) : ($currencyMeta['ar'] ?? $currencyCode))); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <span class="md-help"><?php echo app_h($isEnglish ? 'Shown as 1 foreign currency = X base currency.' : 'العرض يكون بالشكل: 1 من العملة الأجنبية = X من عملة الأساس.'); ?></span>
            </div>
            <div>
                <label><?php echo app_h($isEnglish ? 'Refresh Interval (seconds)' : 'زمن التحديث بالثواني'); ?></label>
                <input class="md-input" type="number" min="15" max="3600" name="fx_widget_refresh_seconds" value="<?php echo (int)$fxWidgetSettings['refresh_seconds']; ?>">
                <span class="md-help"><?php echo app_h($isEnglish ? 'Controls browser refresh and server cache lifetime.' : 'يتحكم في تحديث المتصفح وعمر الكاش على السيرفر.'); ?></span>
            </div>
            <div style="grid-column:1/-1;">
                <label class="md-check-item" style="padding:14px;border-radius:14px;border:1px solid rgba(255,255,255,.08);display:flex;gap:10px;align-items:center;">
                    <input type="checkbox" name="fx_widget_enabled" value="1" <?php echo !empty($fxWidgetSettings['enabled']) ? 'checked' : ''; ?>>
                    <span><?php echo app_h($isEnglish ? 'Show the live currency card for all users' : 'إظهار كارت العملات المباشر لكل المستخدمين'); ?></span>
                </label>
            </div>
            <div style="grid-column:1/-1;">
                <label><?php echo app_h($isEnglish ? 'Search and Add Visible Currencies' : 'بحث وإدراج العملات الظاهرة'); ?></label>
                <div class="md-search-shell" id="fx_currency_picker" data-base="<?php echo app_h((string)$fxWidgetSettings['base_currency']); ?>">
                    <input class="md-input" type="search" id="fx_currency_search" placeholder="<?php echo app_h($isEnglish ? 'Search by code or name...' : 'ابحث بالكود أو الاسم...'); ?>">
                    <div class="md-help"><?php echo app_h($isEnglish ? 'You can add up to 12 currencies. The base currency is excluded automatically.' : 'يمكنك إضافة حتى 12 عملة، وسيتم استبعاد عملة الأساس تلقائيًا.'); ?></div>
                    <div class="md-selected-list" id="fx_selected_list"></div>
                    <div class="md-search-results" id="fx_search_results"></div>
                </div>
            </div>
            <div style="display:flex; align-items:flex-end;">
                <button class="md-btn" type="submit"><?php echo app_h($isEnglish ? 'Save Currency Settings' : 'حفظ إعدادات العملات'); ?></button>
            </div>
        </form>
    </div>
    <script>
    (function () {
        const catalog = <?php echo is_string($fxCurrencyCatalogJson) ? $fxCurrencyCatalogJson : '{}'; ?>;
        const initialSelected = <?php echo is_string($fxCurrencySelectedJson) ? $fxCurrencySelectedJson : '[]'; ?>;
        const picker = document.getElementById('fx_currency_picker');
        const payloadInput = document.getElementById('fx_widget_currencies_payload');
        const searchInput = document.getElementById('fx_currency_search');
        const resultsWrap = document.getElementById('fx_search_results');
        const selectedWrap = document.getElementById('fx_selected_list');
        const baseSelect = document.getElementById('fx_widget_base_currency');
        if (!picker || !payloadInput || !searchInput || !resultsWrap || !selectedWrap || !baseSelect) {
            return;
        }

        let selected = Array.isArray(initialSelected) ? initialSelected.slice(0, 12) : [];

        function labelsFor(code) {
            const meta = catalog[code] || {};
            return {
                code: code,
                ar: meta.ar || code,
                en: meta.en || code
            };
        }

        function syncPayload() {
            payloadInput.value = JSON.stringify(selected);
        }

        function removeCode(code) {
            selected = selected.filter(function (item) { return item !== code; });
            render();
        }

        function addCode(code) {
            if (!catalog[code] || code === baseSelect.value || selected.indexOf(code) !== -1 || selected.length >= 12) {
                return;
            }
            selected.push(code);
            render();
        }

        function renderSelected() {
            selectedWrap.innerHTML = '';
            if (!selected.length) {
                const empty = document.createElement('div');
                empty.className = 'md-small-muted';
                empty.textContent = <?php echo json_encode($isEnglish ? 'No currencies selected yet.' : 'لا توجد عملات مختارة حتى الآن.', JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
                selectedWrap.appendChild(empty);
                return;
            }
            selected.forEach(function (code) {
                const meta = labelsFor(code);
                const chip = document.createElement('span');
                chip.className = 'md-selected-chip';
                chip.innerHTML = '<strong>' + meta.code + '</strong><span>' + meta.ar + '</span>';
                const remove = document.createElement('button');
                remove.type = 'button';
                remove.className = 'md-chip-remove';
                remove.textContent = '×';
                remove.addEventListener('click', function () { removeCode(code); });
                chip.appendChild(remove);
                selectedWrap.appendChild(chip);
            });
        }

        function renderResults() {
            const term = (searchInput.value || '').toLowerCase().trim();
            resultsWrap.innerHTML = '';
            const entries = Object.keys(catalog).filter(function (code) {
                if (code === baseSelect.value || selected.indexOf(code) !== -1) {
                    return false;
                }
                const meta = labelsFor(code);
                const hay = [code, meta.ar, meta.en].join(' ').toLowerCase();
                return term === '' || hay.indexOf(term) !== -1;
            }).slice(0, 30);

            if (!entries.length) {
                const empty = document.createElement('div');
                empty.className = 'md-small-muted';
                empty.textContent = <?php echo json_encode($isEnglish ? 'No matching currencies.' : 'لا توجد عملات مطابقة.', JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
                resultsWrap.appendChild(empty);
                return;
            }

            entries.forEach(function (code) {
                const meta = labelsFor(code);
                const row = document.createElement('div');
                row.className = 'md-search-result';
                row.innerHTML = '<div><strong>' + meta.code + '</strong><small>' + meta.ar + ' / ' + meta.en + '</small></div>';
                const addBtn = document.createElement('button');
                addBtn.type = 'button';
                addBtn.className = 'md-btn-mini';
                addBtn.textContent = <?php echo json_encode($isEnglish ? 'Add' : 'إضافة', JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
                addBtn.disabled = selected.length >= 12;
                addBtn.addEventListener('click', function () { addCode(code); });
                row.appendChild(addBtn);
                resultsWrap.appendChild(row);
            });
        }

        function render() {
            selected = selected.filter(function (code, index, arr) {
                return catalog[code] && code !== baseSelect.value && arr.indexOf(code) === index;
            }).slice(0, 12);
            syncPayload();
            renderSelected();
            renderResults();
        }

        searchInput.addEventListener('input', renderResults);
        baseSelect.addEventListener('change', render);
        render();
    }());
    </script>
    <?php endif; ?>

    <?php if ($activeTab === 'payments'): ?>
    <div class="md-card">
        <h3 class="md-title"><?php echo app_h($isEnglish ? 'Client Payment Methods' : 'وسائل الدفع للعملاء'); ?></h3>
        <form method="post" class="md-grid">
            <?php echo app_csrf_input(); ?>
            <input type="hidden" name="action" value="save_payment_settings">
            <input type="hidden" name="tab" value="payments">
            <div style="grid-column:1/-1;">
                <div class="md-note" style="border-style:solid;border-color:rgba(212,175,55,.35);background:rgba(212,175,55,.06);">
                    <?php echo app_h($isEnglish
                        ? 'Payment integrations are currently in preparation mode. You can fill all provider/API fields now, but the live gateway, automatic notifications, and outbound webhooks stay locked until the rollout state becomes Active.'
                        : 'تكاملات الدفع الآن في وضع التحضير. يمكنك تجهيز كل حقول المزود وAPI من الآن، لكن البوابة الحية والإشعارات التلقائية والـ Webhooks تظل مقفلة حتى تصبح الحالة نشطة.'); ?>
                </div>
            </div>
            <div>
                <label><?php echo app_h($isEnglish ? 'Rollout State' : 'حالة الإطلاق'); ?></label>
                <select class="md-select" name="payment_gateway_rollout_state">
                    <option value="draft" <?php echo $paymentGatewayRolloutState === 'draft' ? 'selected' : ''; ?>><?php echo app_h($isEnglish ? 'Draft / Preparation' : 'تحضير / مسودة'); ?></option>
                    <option value="pending_contract" <?php echo $paymentGatewayRolloutState === 'pending_contract' ? 'selected' : ''; ?>><?php echo app_h($isEnglish ? 'Pending Contract' : 'بانتظار التعاقد'); ?></option>
                    <option value="active" <?php echo $paymentGatewayRolloutState === 'active' ? 'selected' : ''; ?>><?php echo app_h($isEnglish ? 'Active' : 'نشط'); ?></option>
                </select>
                <span class="md-help"><?php echo app_h($paymentGatewayLiveEnabled
                    ? ($isEnglish ? 'Live payment gateway is currently active.' : 'بوابة الدفع الحية مفعلة حاليًا.')
                    : ($isEnglish ? 'Live gateway remains locked until the rollout state becomes Active.' : 'البوابة الحية ستظل مقفلة حتى تتحول الحالة إلى نشط.')); ?></span>
            </div>
            <div>
                <label><?php echo app_h($isEnglish ? 'Enable Unified Gateway' : 'تفعيل البوابة الموحدة'); ?></label>
                <label class="md-check-item" style="padding:14px;border-radius:14px;border:1px solid rgba(255,255,255,.08);display:flex;gap:10px;align-items:center;">
                    <input type="checkbox" name="payment_gateway_enabled" value="1" <?php echo $paymentGatewayEnabled ? 'checked' : ''; ?>>
                    <span><?php echo app_h($isEnglish ? 'Use a single payment gateway across billing and SaaS' : 'استخدام بوابة دفع واحدة عبر النظام المالي وSaaS'); ?></span>
                </label>
                <span class="md-help"><?php echo app_h($paymentGatewayLiveEnabled
                    ? ($isEnglish ? 'This is the active gateway layer for subscription invoices and public billing links.' : 'هذه الآن طبقة البوابة النشطة لفواتير الاشتراكات وروابط السداد العامة.')
                    : ($isEnglish ? 'The checkbox keeps the planned configuration, but runtime activation still waits for an Active rollout state.' : 'هذا الحقل يحفظ التهيئة المخططة، لكن التفعيل الفعلي وقت التشغيل ينتظر أن تصبح الحالة نشطة.')); ?></span>
            </div>
            <div>
                <label><?php echo app_h($isEnglish ? 'Gateway Provider' : 'مزود البوابة'); ?></label>
                <input class="md-input" name="payment_gateway_provider" value="<?php echo app_h($paymentGatewayProvider); ?>" placeholder="manual / paymob / paytabs">
                <span class="md-help"><?php echo app_h($isEnglish ? 'Internal provider code used by billing and SaaS.' : 'رمز داخلي للمزود يستخدمه النظام المالي وSaaS.'); ?></span>
            </div>
            <div>
                <label><?php echo app_h($isEnglish ? 'Gateway Label (AR)' : 'اسم البوابة بالعربية'); ?></label>
                <input class="md-input" name="payment_gateway_provider_label_ar" value="<?php echo app_h($paymentGatewayProviderLabelAr); ?>" placeholder="<?php echo app_h($isEnglish ? 'Payment gateway' : 'بوابة الدفع'); ?>">
            </div>
            <div>
                <label><?php echo app_h($isEnglish ? 'Gateway Label (EN)' : 'اسم البوابة بالإنجليزية'); ?></label>
                <input class="md-input" name="payment_gateway_provider_label_en" value="<?php echo app_h($paymentGatewayProviderLabelEn); ?>" placeholder="Payment gateway">
            </div>
            <div style="grid-column:1/-1;">
                <label><?php echo app_h($isEnglish ? 'Unified Checkout URL' : 'رابط الـ Checkout الموحد'); ?></label>
                <input class="md-input" name="payment_gateway_checkout_url" value="<?php echo app_h($paymentGatewayCheckoutUrl); ?>" placeholder="https://pay.example.com/checkout?ref={invoice_number}&amount={amount}&token={token}">
                <span class="md-help"><?php echo app_h($isEnglish ? 'Supported placeholders: {invoice_number} {amount} {currency} {token} {tenant_slug} {tenant_name} {billing_email}' : 'المتغيرات المدعومة: {invoice_number} {amount} {currency} {token} {tenant_slug} {tenant_name} {billing_email}'); ?></span>
            </div>
            <div>
                <label><?php echo app_h($isEnglish ? 'Gateway Support Email' : 'بريد دعم البوابة'); ?></label>
                <input class="md-input" name="payment_gateway_support_email" value="<?php echo app_h($paymentGatewaySupportEmail); ?>" placeholder="billing@example.com">
            </div>
            <div>
                <label><?php echo app_h($isEnglish ? 'Gateway Support WhatsApp' : 'واتساب دعم البوابة'); ?></label>
                <input class="md-input" name="payment_gateway_support_whatsapp" value="<?php echo app_h($paymentGatewaySupportWhatsapp); ?>" placeholder="+2010...">
            </div>
            <div>
                <label><?php echo app_h($isEnglish ? 'API Base URL' : 'رابط API الأساسي'); ?></label>
                <input class="md-input" name="payment_gateway_api_base_url" value="<?php echo app_h($paymentGatewayApiBaseUrl); ?>" placeholder="https://accept.paymob.com/api">
            </div>
            <div>
                <label><?php echo app_h($isEnglish ? 'API Version' : 'إصدار API'); ?></label>
                <input class="md-input" name="payment_gateway_api_version" value="<?php echo app_h($paymentGatewayApiVersion); ?>" placeholder="v1 / v20.0">
            </div>
            <div>
                <label><?php echo app_h($isEnglish ? 'Public Key' : 'المفتاح العام'); ?></label>
                <input class="md-input" name="payment_gateway_public_key" value="<?php echo app_h($paymentGatewayPublicKey); ?>" placeholder="pk_live_...">
            </div>
            <div>
                <label><?php echo app_h($isEnglish ? 'Secret Key / API Key' : 'المفتاح السري / API Key'); ?></label>
                <input class="md-input" name="payment_gateway_secret_key" value="<?php echo app_h($paymentGatewaySecretKey); ?>" placeholder="sk_live_...">
            </div>
            <div>
                <label><?php echo app_h($isEnglish ? 'Merchant ID' : 'Merchant ID'); ?></label>
                <input class="md-input" name="payment_gateway_merchant_id" value="<?php echo app_h($paymentGatewayMerchantId); ?>" placeholder="merchant_...">
            </div>
            <div>
                <label><?php echo app_h($isEnglish ? 'Integration ID' : 'Integration ID'); ?></label>
                <input class="md-input" name="payment_gateway_integration_id" value="<?php echo app_h($paymentGatewayIntegrationId); ?>" placeholder="integration_...">
            </div>
            <div>
                <label><?php echo app_h($isEnglish ? 'Iframe ID' : 'Iframe ID'); ?></label>
                <input class="md-input" name="payment_gateway_iframe_id" value="<?php echo app_h($paymentGatewayIframeId); ?>" placeholder="iframe_...">
            </div>
            <div>
                <label><?php echo app_h($isEnglish ? 'HMAC Secret' : 'HMAC Secret'); ?></label>
                <input class="md-input" name="payment_gateway_hmac_secret" value="<?php echo app_h($paymentGatewayHmacSecret); ?>" placeholder="hmac_...">
            </div>
            <div>
                <label><?php echo app_h($isEnglish ? 'Webhook Secret' : 'Webhook Secret'); ?></label>
                <input class="md-input" name="payment_gateway_webhook_secret" value="<?php echo app_h($paymentGatewayWebhookSecret); ?>" placeholder="whsec_...">
            </div>
            <div>
                <label><?php echo app_h($isEnglish ? 'Callback URL' : 'رابط Callback'); ?></label>
                <input class="md-input" name="payment_gateway_callback_url" value="<?php echo app_h($paymentGatewayCallbackUrl); ?>" placeholder="https://example.com/payment/callback">
            </div>
            <div>
                <label><?php echo app_h($isEnglish ? 'Webhook URL' : 'رابط Webhook'); ?></label>
                <input class="md-input" name="payment_gateway_webhook_url" value="<?php echo app_h($paymentGatewayWebhookUrl); ?>" placeholder="https://example.com/payment/webhook">
            </div>
            <div>
                <label><?php echo app_h($isEnglish ? 'Paymob Integration Name' : 'اسم تكامل Paymob'); ?></label>
                <input class="md-input" name="payment_gateway_paymob_integration_name" value="<?php echo app_h($paymentGatewayPaymobIntegrationName); ?>" placeholder="bank">
            </div>
            <div>
                <label><?php echo app_h($isEnglish ? 'Paymob Processed Callback URL' : 'Processed Callback URL لـ Paymob'); ?></label>
                <input class="md-input" name="payment_gateway_paymob_processed_callback_url" value="<?php echo app_h($paymentGatewayPaymobProcessedCallbackUrl); ?>" placeholder="<?php echo app_h(rtrim(app_base_url(), '/') . '/saas_paymob_callback.php?stage=processed'); ?>">
            </div>
            <div>
                <label><?php echo app_h($isEnglish ? 'Paymob Response Callback URL' : 'Response Callback URL لـ Paymob'); ?></label>
                <input class="md-input" name="payment_gateway_paymob_response_callback_url" value="<?php echo app_h($paymentGatewayPaymobResponseCallbackUrl); ?>" placeholder="<?php echo app_h(rtrim(app_base_url(), '/') . '/saas_paymob_callback.php?stage=response'); ?>">
            </div>
            <div>
                <label><?php echo app_h($isEnglish ? 'Email notifications' : 'إشعارات البريد'); ?></label>
                <label class="md-check-item" style="padding:14px;border-radius:14px;border:1px solid rgba(255,255,255,.08);display:flex;gap:10px;align-items:center;">
                    <input type="checkbox" name="payment_gateway_email_notifications_enabled" value="1" <?php echo $paymentGatewayEmailNotificationsEnabled ? 'checked' : ''; ?>>
                    <span><?php echo app_h($isEnglish ? 'Send payment and notice emails' : 'إرسال رسائل الفواتير وإشعارات السداد بالبريد'); ?></span>
                </label>
            </div>
            <div>
                <label><?php echo app_h($isEnglish ? 'WhatsApp notifications' : 'إشعارات واتساب'); ?></label>
                <label class="md-check-item" style="padding:14px;border-radius:14px;border:1px solid rgba(255,255,255,.08);display:flex;gap:10px;align-items:center;">
                    <input type="checkbox" name="payment_gateway_whatsapp_notifications_enabled" value="1" <?php echo $paymentGatewayWhatsappNotificationsEnabled ? 'checked' : ''; ?>>
                    <span><?php echo app_h($isEnglish ? 'Enable WhatsApp billing notifications' : 'تفعيل إشعارات واتساب الخاصة بالسداد'); ?></span>
                </label>
            </div>
            <div>
                <label><?php echo app_h($isEnglish ? 'WhatsApp mode' : 'وضع واتساب'); ?></label>
                <select class="md-select" name="payment_gateway_whatsapp_mode">
                    <option value="link" <?php echo $paymentGatewayWhatsappMode === 'link' ? 'selected' : ''; ?>><?php echo app_h($isEnglish ? 'Direct link' : 'رابط مباشر'); ?></option>
                    <option value="api" <?php echo $paymentGatewayWhatsappMode === 'api' ? 'selected' : ''; ?>><?php echo app_h($isEnglish ? 'Cloud API' : 'Cloud API'); ?></option>
                </select>
            </div>
            <div>
                <label><?php echo app_h($isEnglish ? 'WhatsApp Access Token' : 'رمز وصول واتساب'); ?></label>
                <input class="md-input" name="payment_gateway_whatsapp_access_token" value="<?php echo app_h($paymentGatewayWhatsappAccessToken); ?>" placeholder="EAAG...">
            </div>
            <div>
                <label><?php echo app_h($isEnglish ? 'WhatsApp Phone Number ID' : 'WhatsApp Phone Number ID'); ?></label>
                <input class="md-input" name="payment_gateway_whatsapp_phone_number_id" value="<?php echo app_h($paymentGatewayWhatsappPhoneNumberId); ?>" placeholder="1234567890">
            </div>
            <div>
                <label><?php echo app_h($isEnglish ? 'Outbound webhooks' : 'Outbound Webhooks'); ?></label>
                <label class="md-check-item" style="padding:14px;border-radius:14px;border:1px solid rgba(255,255,255,.08);display:flex;gap:10px;align-items:center;">
                    <input type="checkbox" name="payment_gateway_outbound_webhooks_enabled" value="1" <?php echo $paymentGatewayOutboundWebhooksEnabled ? 'checked' : ''; ?>>
                    <span><?php echo app_h($isEnglish ? 'Notify external systems when billing and subscription events happen' : 'إخطار الأنظمة الخارجية عند حدوث أحداث الفوترة والاشتراك'); ?></span>
                </label>
            </div>
            <div>
                <label><?php echo app_h($isEnglish ? 'Outbound webhook URL' : 'رابط الـ Webhook الخارجي'); ?></label>
                <input class="md-input" name="payment_gateway_outbound_webhooks_url" value="<?php echo app_h($paymentGatewayOutboundWebhooksUrl); ?>" placeholder="https://integrations.example.com/arab-eagles/webhooks">
            </div>
            <div>
                <label><?php echo app_h($isEnglish ? 'Outbound webhook token' : 'Token للـ Webhook الخارجي'); ?></label>
                <input class="md-input" name="payment_gateway_outbound_webhooks_token" value="<?php echo app_h($paymentGatewayOutboundWebhooksToken); ?>" placeholder="whk_live_...">
            </div>
            <div>
                <label><?php echo app_h($isEnglish ? 'Outbound webhook secret' : 'Secret لتوقيع الـ Webhook'); ?></label>
                <input class="md-input" name="payment_gateway_outbound_webhooks_secret" value="<?php echo app_h($paymentGatewayOutboundWebhooksSecret); ?>" placeholder="whsec_...">
            </div>
            <div style="grid-column:1/-1;">
                <label><?php echo app_h($isEnglish ? 'Outbound webhook events' : 'أحداث الـ Webhook الخارجي'); ?></label>
                <textarea class="md-input" name="payment_gateway_outbound_webhooks_events" rows="3" placeholder="subscription.invoice_issued, subscription.invoice_paid, subscription.invoice_payment_notice, subscription.status_changed, automation.run"><?php echo app_h($paymentGatewayOutboundWebhooksEvents); ?></textarea>
                <span class="md-help"><?php echo app_h($isEnglish ? 'Comma separated events. Leave empty to allow all future outbound webhook events.' : 'أدخل الأحداث مفصولة بفواصل. اتركه فارغًا للسماح بكل الأحداث الخارجية الحالية والمستقبلية.'); ?></span>
            </div>
            <div style="grid-column:1/-1;">
                <label><?php echo app_h($isEnglish ? 'Gateway Instructions (AR)' : 'تعليمات البوابة بالعربية'); ?></label>
                <textarea class="md-input" name="payment_gateway_instructions_ar" rows="3" placeholder="<?php echo app_h($isEnglish ? 'Arabic gateway instructions' : 'تعليمات عربية للبوابة'); ?>"><?php echo app_h($paymentGatewayInstructionsAr); ?></textarea>
            </div>
            <div style="grid-column:1/-1;">
                <label><?php echo app_h($isEnglish ? 'Gateway Instructions (EN)' : 'تعليمات البوابة بالإنجليزية'); ?></label>
                <textarea class="md-input" name="payment_gateway_instructions_en" rows="3" placeholder="English gateway instructions"><?php echo app_h($paymentGatewayInstructionsEn); ?></textarea>
            </div>
            <div>
                <label>Paymob URL</label>
                <input class="md-input" name="payment_method_paymob_url" value="<?php echo app_h($paymobUrl); ?>" placeholder="https://...">
                <span class="md-help"><?php echo app_h($isEnglish ? 'Displayed in public invoice as a direct payment link.' : 'يظهر للعميل في عرض الفاتورة كرابط دفع مباشر.'); ?></span>
            </div>
            <div>
                <label>Wallet Number</label>
                <input class="md-input" name="payment_method_wallet_number" value="<?php echo app_h($walletNumber); ?>" placeholder="01xxxxxxxxx">
                <span class="md-help"><?php echo app_h($isEnglish ? 'Mobile wallet number shown and copyable for clients.' : 'رقم محفظة يظهر للعميل مع زر نسخ.'); ?></span>
            </div>
            <div>
                <label>InstaPay URL</label>
                <input class="md-input" name="payment_method_instapay_url" value="<?php echo app_h($instapayUrl); ?>" placeholder="https://...">
                <span class="md-help"><?php echo app_h($isEnglish ? 'InstaPay payment link shown in public invoice.' : 'رابط InstaPay يظهر للعميل في الفاتورة.'); ?></span>
            </div>
            <div>
                <label><?php echo app_h($isEnglish ? 'Default Deposit %' : 'نسبة العربون الافتراضية %'); ?></label>
                <input class="md-input" type="number" min="0" max="100" step="0.01" name="payment_request_default_percent" value="<?php echo app_h($depositPercent); ?>">
                <span class="md-help"><?php echo app_h($isEnglish ? 'Used as initial value when generating a separate payment request.' : 'تُستخدم كقيمة مقترحة عند إنشاء طلب دفع منفصل.'); ?></span>
            </div>
            <div>
                <label><?php echo app_h($isEnglish ? 'Default Deposit Label' : 'وصف الطلب الافتراضي'); ?></label>
                <input class="md-input" name="payment_request_default_note" value="<?php echo app_h($depositNote); ?>" placeholder="<?php echo app_h($isEnglish ? 'Deposit' : 'عربون'); ?>">
                <span class="md-help"><?php echo app_h($isEnglish ? 'Example: Deposit, Advance payment, Booking fee.' : 'مثال: عربون، دفعة مقدمة، رسوم حجز.'); ?></span>
            </div>
            <div style="display:flex; align-items:flex-end;">
                <button class="md-btn" type="submit"><?php echo app_h($isEnglish ? 'Save Payment Settings' : 'حفظ إعدادات الدفع'); ?></button>
            </div>
        </form>
    </div>
    <?php endif; ?>

    <?php if ($activeTab === 'eta'): ?>
    <div class="md-card">
        <h3 class="md-title"><?php echo app_h($isEnglish ? 'ETA eInvoicing Integration' : 'تكامل منظومة الفاتورة الإلكترونية ETA'); ?></h3>
        <div class="md-note">
            <?php echo app_h($isEnglish
                ? 'This section prepares the system for Egyptian ETA eInvoicing. Keep it disabled until preprod credentials and the signing path are ready.'
                : 'هذا القسم يجهز النظام لتكامل الفاتورة الإلكترونية المصرية. اتركه غير مفعّل حتى تتوفر بيانات preprod ومسار التوقيع.'); ?>
        </div>
        <form method="post" class="md-grid">
            <?php echo app_csrf_input(); ?>
            <input type="hidden" name="action" value="save_eta_settings">
            <input type="hidden" name="tab" value="eta">
            <div style="grid-column:1/-1;">
                <label class="md-check-item" style="padding:14px;border-radius:14px;border:1px solid rgba(255,255,255,.08);display:flex;gap:10px;align-items:center;">
                    <input type="checkbox" name="eta_enabled" value="1" <?php echo !empty($etaSettings['enabled']) ? 'checked' : ''; ?>>
                    <span><?php echo app_h($isEnglish ? 'Enable ETA integration' : 'تفعيل تكامل ETA'); ?></span>
                </label>
            </div>
            <div>
                <label><?php echo app_h($isEnglish ? 'Environment' : 'البيئة'); ?></label>
                <select class="md-select" name="eta_environment">
                    <option value="preprod" <?php echo $etaSettings['environment'] === 'preprod' ? 'selected' : ''; ?>>Preprod</option>
                    <option value="prod" <?php echo $etaSettings['environment'] === 'prod' ? 'selected' : ''; ?>>Production</option>
                </select>
            </div>
            <div>
                <label>ETA Base URL</label>
                <input class="md-input" name="eta_base_url" value="<?php echo app_h($etaSettings['base_url']); ?>" placeholder="https://sdk.invoicing.eta.gov.eg">
            </div>
            <div>
                <label><?php echo app_h($isEnglish ? 'ETA Token URL' : 'ETA Token URL'); ?></label>
                <input class="md-input" name="eta_token_url" value="<?php echo app_h($etaSettings['token_url']); ?>" placeholder="https://id.preprod.eta.gov.eg/connect/token">
            </div>
            <div>
                <label>Client ID</label>
                <input class="md-input" name="eta_client_id" value="<?php echo app_h($etaSettings['client_id']); ?>" placeholder="eta_client_id">
                <?php if ($etaClientIdMasked !== ''): ?><span class="md-help"><?php echo app_h(($isEnglish ? 'Current saved Client ID:' : 'Client ID المحفوظ حاليًا:') . ' ' . $etaClientIdMasked); ?></span><?php endif; ?>
            </div>
            <div>
                <label>Client Secret</label>
                <input class="md-input" type="password" name="eta_client_secret" value="<?php echo app_h($etaSettings['client_secret']); ?>" placeholder="eta_client_secret">
                <?php if ($etaClientSecretMasked !== ''): ?><span class="md-help"><?php echo app_h(($isEnglish ? 'Current saved Client Secret:' : 'Client Secret المحفوظ حاليًا:') . ' ' . $etaClientSecretMasked); ?></span><?php endif; ?>
            </div>
            <div>
                <label><?php echo app_h($isEnglish ? 'Issuer RIN' : 'رقم التسجيل الضريبي للممول'); ?></label>
                <input class="md-input" name="eta_issuer_rin" value="<?php echo app_h($etaSettings['issuer_rin']); ?>" placeholder="123456789">
            </div>
            <div>
                <label><?php echo app_h($isEnglish ? 'Branch Code' : 'Branch Code'); ?></label>
                <input class="md-input" name="eta_branch_code" value="<?php echo app_h($etaSettings['branch_code']); ?>" placeholder="0">
            </div>
            <div>
                <label><?php echo app_h($isEnglish ? 'Activity Code' : 'Activity Code'); ?></label>
                <input class="md-input" name="eta_activity_code" value="<?php echo app_h($etaSettings['activity_code']); ?>" placeholder="0111">
            </div>
            <div>
                <label><?php echo app_h($isEnglish ? 'Default Document Type' : 'نوع المستند الافتراضي'); ?></label>
                <select class="md-select" name="eta_default_document_type">
                    <option value="i" <?php echo strtolower((string)$etaSettings['default_document_type']) === 'i' ? 'selected' : ''; ?>>I - Invoice</option>
                    <option value="c" <?php echo strtolower((string)$etaSettings['default_document_type']) === 'c' ? 'selected' : ''; ?>>C - Credit Note</option>
                    <option value="d" <?php echo strtolower((string)$etaSettings['default_document_type']) === 'd' ? 'selected' : ''; ?>>D - Debit Note</option>
                </select>
            </div>
            <div>
                <label><?php echo app_h($isEnglish ? 'Signing Mode' : 'وضع التوقيع'); ?></label>
                <select class="md-select" name="eta_signing_mode">
                    <?php foreach ($etaSigningModes as $mode): ?>
                        <option value="<?php echo app_h((string)$mode['key']); ?>" <?php echo $etaSettings['signing_mode'] === (string)$mode['key'] ? 'selected' : ''; ?>>
                            <?php echo app_h($isEnglish ? ((string)($mode['name_en'] ?? $mode['name'] ?? '')) : ((string)($mode['name'] ?? ''))); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label><?php echo app_h($isEnglish ? 'Signing Service URL' : 'رابط خدمة التوقيع'); ?></label>
                <input class="md-input" name="eta_signing_base_url" value="<?php echo app_h($etaSettings['signing_base_url']); ?>" placeholder="https://signer.internal/api">
            </div>
            <div>
                <label><?php echo app_h($isEnglish ? 'Signing API Key' : 'مفتاح خدمة التوقيع'); ?></label>
                <input class="md-input" type="password" name="eta_signing_api_key" value="<?php echo app_h($etaSettings['signing_api_key']); ?>" placeholder="signing_api_key">
            </div>
            <div>
                <label><?php echo app_h($isEnglish ? 'ETA Callback ApiKey' : 'مفتاح تشغيل ETA Callback'); ?></label>
                <input class="md-input" type="password" name="eta_callback_api_key" value="<?php echo app_h($etaSettings['callback_api_key']); ?>" placeholder="ApiKey xxxxx">
                <span class="md-help"><?php echo app_h($isEnglish ? 'This is the pre-shared key from ETA ERP registration and is used by /ping and callback endpoints.' : 'هذا هو المفتاح المشترك المسبق الخارج من تسجيل ERP في بوابة ETA، ويستخدمه /ping وواجهات الـ callback.'); ?></span>
            </div>
            <div>
                <label><?php echo app_h($isEnglish ? 'Submission Mode' : 'نمط الإرسال'); ?></label>
                <select class="md-select" name="eta_submission_mode">
                    <option value="manual_review" <?php echo $etaSettings['submission_mode'] === 'manual_review' ? 'selected' : ''; ?>><?php echo app_h($isEnglish ? 'Manual Review' : 'مراجعة يدوية'); ?></option>
                    <option value="queue" <?php echo $etaSettings['submission_mode'] === 'queue' ? 'selected' : ''; ?>><?php echo app_h($isEnglish ? 'Queue / Outbox' : 'طابور / Outbox'); ?></option>
                    <option value="auto_submit" <?php echo $etaSettings['submission_mode'] === 'auto_submit' ? 'selected' : ''; ?>><?php echo app_h($isEnglish ? 'Auto Submit' : 'إرسال تلقائي'); ?></option>
                </select>
            </div>
            <div style="grid-column:1/-1;display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:12px;">
                <label class="md-check-item" style="padding:14px;border-radius:14px;border:1px solid rgba(255,255,255,.08);display:flex;gap:10px;align-items:center;">
                    <input type="checkbox" name="eta_auto_pull_status" value="1" <?php echo !empty($etaSettings['auto_pull_status']) ? 'checked' : ''; ?>>
                    <span><?php echo app_h($isEnglish ? 'Auto sync ETA statuses' : 'مزامنة حالات ETA تلقائيًا'); ?></span>
                </label>
                <label class="md-check-item" style="padding:14px;border-radius:14px;border:1px solid rgba(255,255,255,.08);display:flex;gap:10px;align-items:center;">
                    <input type="checkbox" name="eta_auto_pull_documents" value="1" <?php echo !empty($etaSettings['auto_pull_documents']) ? 'checked' : ''; ?>>
                    <span><?php echo app_h($isEnglish ? 'Auto pull ETA documents' : 'سحب مستندات ETA تلقائيًا'); ?></span>
                </label>
            </div>
            <div style="grid-column:1/-1;">
                <label><?php echo app_h($isEnglish ? 'ETA Unit Catalog' : 'كتالوج وحدات ETA'); ?></label>
                <div id="eta-unit-catalog-wrap" style="display:grid;gap:10px;">
                    <?php foreach ($etaUnitRows as $index => $unitRow): ?>
                        <div class="eta-unit-row" style="display:grid;grid-template-columns:minmax(0,1fr) 160px auto;gap:10px;align-items:end;">
                            <div>
                                <label><?php echo app_h($isEnglish ? 'Local Unit' : 'الوحدة المحلية'); ?></label>
                                <input class="md-input" name="eta_unit_local[]" value="<?php echo app_h((string)($unitRow['local'] ?? '')); ?>" placeholder="<?php echo app_h($isEnglish ? 'Piece' : 'قطعة'); ?>">
                            </div>
                            <div>
                                <label><?php echo app_h($isEnglish ? 'ETA Unit' : 'كود وحدة ETA'); ?></label>
                                <input class="md-input" name="eta_unit_eta[]" value="<?php echo app_h((string)($unitRow['eta'] ?? '')); ?>" placeholder="EA">
                            </div>
                            <button class="md-btn-neutral eta-remove-row" type="button"><?php echo app_h($isEnglish ? 'Remove' : 'حذف'); ?></button>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div style="margin-top:10px;">
                    <button class="md-btn-neutral" type="button" id="eta-add-unit-row"><?php echo app_h($isEnglish ? 'Add Unit Mapping' : 'إضافة وحدة'); ?></button>
                </div>
                <span class="md-help"><?php echo app_h($isEnglish ? 'Map each local unit to ETA unit code using repeatable fields.' : 'اربط كل وحدة محلية بكود وحدة ETA عبر حقول قابلة للإضافة.'); ?></span>
            </div>
            <div style="grid-column:1/-1;">
                <label><?php echo app_h($isEnglish ? 'ETA Item Code Catalog' : 'كتالوج أكواد الأصناف ETA'); ?></label>
                <div style="margin:0 0 10px;display:grid;grid-template-columns:minmax(0,220px) minmax(0,1fr);gap:10px;align-items:end;">
                    <div>
                        <label><?php echo app_h($isEnglish ? 'Sync Type Filter' : 'فلتر نوع المزامنة'); ?></label>
                        <select class="md-select" name="eta_sync_code_type">
                            <option value=""><?php echo app_h($isEnglish ? 'All Types' : 'كل الأنواع'); ?></option>
                            <option value="EGS">EGS</option>
                            <option value="GS1">GS1</option>
                        </select>
                    </div>
                    <div class="md-help"><?php echo app_h($isEnglish ? 'Choose All, EGS only, or GS1 only before sync.' : 'اختر الكل أو EGS فقط أو GS1 فقط قبل تنفيذ المزامنة.'); ?></div>
                </div>
                <div style="margin:10px 0;display:flex;gap:10px;flex-wrap:wrap;">
                    <button class="md-btn-neutral" type="button" id="eta-select-all-items"><?php echo app_h($isEnglish ? 'Select All' : 'تحديد الكل'); ?></button>
                    <button class="md-btn-neutral" type="button" id="eta-clear-selected-items"><?php echo app_h($isEnglish ? 'Clear Selected' : 'مسح المحدد'); ?></button>
                </div>
                <div id="eta-item-catalog-wrap" style="display:grid;gap:10px;">
                    <?php foreach ($etaItemRows as $itemRow): ?>
                        <div class="eta-item-row" style="display:grid;grid-template-columns:44px minmax(0,1.2fr) minmax(0,1fr) 120px 120px auto;gap:10px;align-items:end;">
                            <div>
                                <label><?php echo app_h($isEnglish ? 'Pick' : 'تحديد'); ?></label>
                                <label style="display:flex;align-items:center;justify-content:center;height:44px;border:1px solid rgba(255,255,255,.08);border-radius:10px;">
                                    <input type="checkbox" class="eta-item-select">
                                </label>
                            </div>
                            <div>
                                <label><?php echo app_h($isEnglish ? 'Local Description' : 'الوصف المحلي'); ?></label>
                                <input class="md-input" name="eta_item_local[]" value="<?php echo app_h((string)($itemRow['local'] ?? '')); ?>" placeholder="<?php echo app_h($isEnglish ? 'Water bottle' : 'مرتبة ليف'); ?>">
                            </div>
                            <div>
                                <label><?php echo app_h($isEnglish ? 'ETA Code' : 'كود ETA'); ?></label>
                                <input class="md-input" name="eta_item_eta[]" value="<?php echo app_h((string)($itemRow['eta'] ?? '')); ?>" placeholder="EG-ITEM-001">
                            </div>
                            <div>
                                <label><?php echo app_h($isEnglish ? 'Type' : 'النوع'); ?></label>
                                <select class="md-select" name="eta_item_type[]">
                                    <option value="EGS" <?php echo strtoupper((string)($itemRow['code_type'] ?? 'EGS')) === 'EGS' ? 'selected' : ''; ?>>EGS</option>
                                    <option value="GS1" <?php echo strtoupper((string)($itemRow['code_type'] ?? 'EGS')) === 'GS1' ? 'selected' : ''; ?>>GS1</option>
                                </select>
                            </div>
                            <div>
                                <label><?php echo app_h($isEnglish ? 'Source' : 'المصدر'); ?></label>
                                <input class="md-input" name="eta_item_source[]" value="<?php echo app_h((string)($itemRow['source'] ?? 'manual')); ?>" readonly>
                            </div>
                            <button class="md-btn-neutral eta-remove-row" type="button"><?php echo app_h($isEnglish ? 'Remove' : 'حذف'); ?></button>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div style="margin-top:10px;display:flex;gap:10px;flex-wrap:wrap;">
                    <button class="md-btn-neutral" type="button" id="eta-add-item-row"><?php echo app_h($isEnglish ? 'Add Item Code' : 'إضافة كود صنف'); ?></button>
                </div>
                <span class="md-help"><?php echo app_h($isEnglish ? 'You can add manual codes of both types (EGS / GS1). ETA sync will append published codes from the portal and keep manual rows.' : 'يمكنك إضافة أكواد يدويًا بنوعيها EGS وGS1. مزامنة ETA تضيف الأكواد المنشورة من البوابة مع الإبقاء على المدخلات اليدوية.'); ?></span>
            </div>
            <div style="grid-column:1/-1;">
                <div class="md-note" style="margin:0;">
                    <?php
                    $etaCallbackBaseUrl = 'https://work.areagles.com';
                    ?>
                    <strong><?php echo app_h($isEnglish ? 'ERP Contact URL:' : 'رابط الاتصال الصحيح لتسجيل ERP:'); ?></strong>
                    <span dir="ltr"><?php echo app_h($etaCallbackBaseUrl); ?></span>
                    <br>
                    <?php echo app_h($isEnglish ? 'Required master data before live use:' : 'الـ master data المطلوبة قبل الاستخدام الفعلي:'); ?>
                    <strong><?php echo app_h(implode('، ', $etaRequiredMasterData)); ?></strong>
                </div>
            </div>
            <div style="grid-column:1/-1;">
                <div class="md-note" style="margin:0;">
                    <?php echo app_h($isEnglish
                        ? 'Recommended operating mode: Dedicated Signing Server. Do not attempt to extract the private key from the USB token into the ERP.'
                        : 'الوضع التشغيلي الموصى به: Dedicated Signing Server. لا تحاول نقل المفتاح الخاص من USB token إلى الـ ERP.'); ?>
                </div>
            </div>
            <div style="display:flex; align-items:flex-end; gap:10px; flex-wrap:wrap;">
                <button class="md-btn" type="submit"><?php echo app_h($isEnglish ? 'Save ETA Settings' : 'حفظ إعدادات ETA'); ?></button>
                <button class="md-btn-neutral" type="submit" name="action" value="sync_eta_item_catalog"><?php echo app_h($isEnglish ? 'Sync My ETA Codes' : 'مزامنة أكواد حسابي من ETA'); ?></button>
                <a class="md-link-mini" href="eta_diagnostics.php"><?php echo app_h($isEnglish ? 'Open ETA Diagnostics' : 'فتح تشخيص ETA'); ?></a>
                <a class="md-link-mini" href="eta_outbox.php"><?php echo app_h($isEnglish ? 'Open ETA Outbox' : 'فتح ETA Outbox'); ?></a>
            </div>
        </form>
        <template id="eta-unit-row-template">
            <div class="eta-unit-row" style="display:grid;grid-template-columns:minmax(0,1fr) 160px auto;gap:10px;align-items:end;">
                <div>
                    <label><?php echo app_h($isEnglish ? 'Local Unit' : 'الوحدة المحلية'); ?></label>
                    <input class="md-input" name="eta_unit_local[]" value="" placeholder="<?php echo app_h($isEnglish ? 'Piece' : 'قطعة'); ?>">
                </div>
                <div>
                    <label><?php echo app_h($isEnglish ? 'ETA Unit' : 'كود وحدة ETA'); ?></label>
                    <input class="md-input" name="eta_unit_eta[]" value="" placeholder="EA">
                </div>
                <button class="md-btn-neutral eta-remove-row" type="button"><?php echo app_h($isEnglish ? 'Remove' : 'حذف'); ?></button>
            </div>
        </template>
        <template id="eta-item-row-template">
            <div class="eta-item-row" style="display:grid;grid-template-columns:44px minmax(0,1.2fr) minmax(0,1fr) 120px 120px auto;gap:10px;align-items:end;">
                <div>
                    <label><?php echo app_h($isEnglish ? 'Pick' : 'تحديد'); ?></label>
                    <label style="display:flex;align-items:center;justify-content:center;height:44px;border:1px solid rgba(255,255,255,.08);border-radius:10px;">
                        <input type="checkbox" class="eta-item-select">
                    </label>
                </div>
                <div>
                    <label><?php echo app_h($isEnglish ? 'Local Description' : 'الوصف المحلي'); ?></label>
                    <input class="md-input" name="eta_item_local[]" value="" placeholder="<?php echo app_h($isEnglish ? 'Water bottle' : 'مرتبة ليف'); ?>">
                </div>
                <div>
                    <label><?php echo app_h($isEnglish ? 'ETA Code' : 'كود ETA'); ?></label>
                    <input class="md-input" name="eta_item_eta[]" value="" placeholder="EG-ITEM-001">
                </div>
                <div>
                    <label><?php echo app_h($isEnglish ? 'Type' : 'النوع'); ?></label>
                    <select class="md-select" name="eta_item_type[]">
                        <option value="EGS">EGS</option>
                        <option value="GS1">GS1</option>
                    </select>
                </div>
                <div>
                    <label><?php echo app_h($isEnglish ? 'Source' : 'المصدر'); ?></label>
                    <input class="md-input" name="eta_item_source[]" value="manual" readonly>
                </div>
                <button class="md-btn-neutral eta-remove-row" type="button"><?php echo app_h($isEnglish ? 'Remove' : 'حذف'); ?></button>
            </div>
        </template>
        <script>
        (function () {
            const unitWrap = document.getElementById('eta-unit-catalog-wrap');
            const itemWrap = document.getElementById('eta-item-catalog-wrap');
            const unitTpl = document.getElementById('eta-unit-row-template');
            const itemTpl = document.getElementById('eta-item-row-template');
            const addUnitBtn = document.getElementById('eta-add-unit-row');
            const addItemBtn = document.getElementById('eta-add-item-row');
            const selectAllItemsBtn = document.getElementById('eta-select-all-items');
            const clearSelectedItemsBtn = document.getElementById('eta-clear-selected-items');
            function bindRemove(scope) {
                if (!scope) return;
                scope.querySelectorAll('.eta-remove-row').forEach(function (btn) {
                    btn.onclick = function () {
                        const row = btn.closest('.eta-unit-row, .eta-item-row');
                        const wrap = row && row.parentElement;
                        if (!row || !wrap) return;
                        if (wrap.children.length <= 1) {
                            row.querySelectorAll('input').forEach(function (input) { if (!input.readOnly) input.value = ''; });
                            row.querySelectorAll('select').forEach(function (select) { select.selectedIndex = 0; });
                            return;
                        }
                        row.remove();
                    };
                });
            }
            bindRemove(document);
            if (addUnitBtn && unitWrap && unitTpl) {
                addUnitBtn.onclick = function () {
                    unitWrap.appendChild(unitTpl.content.firstElementChild.cloneNode(true));
                    bindRemove(unitWrap);
                };
            }
            if (addItemBtn && itemWrap && itemTpl) {
                addItemBtn.onclick = function () {
                    itemWrap.appendChild(itemTpl.content.firstElementChild.cloneNode(true));
                    bindRemove(itemWrap);
                };
            }
            if (selectAllItemsBtn && itemWrap) {
                selectAllItemsBtn.onclick = function () {
                    itemWrap.querySelectorAll('.eta-item-select').forEach(function (box) {
                        box.checked = true;
                    });
                };
            }
            if (clearSelectedItemsBtn && itemWrap) {
                clearSelectedItemsBtn.onclick = function () {
                    const rows = Array.from(itemWrap.querySelectorAll('.eta-item-row'));
                    const selectedRows = rows.filter(function (row) {
                        const box = row.querySelector('.eta-item-select');
                        return box && box.checked;
                    });
                    if (!selectedRows.length) return;
                    if (selectedRows.length === rows.length && rows.length > 0) {
                        const keep = rows[0];
                        keep.querySelectorAll('input').forEach(function (input) {
                            if (input.type === 'checkbox') {
                                input.checked = false;
                            } else if (!input.readOnly) {
                                input.value = '';
                            }
                        });
                        keep.querySelectorAll('select').forEach(function (select) {
                            select.selectedIndex = 0;
                        });
                        rows.slice(1).forEach(function (row) { row.remove(); });
                        return;
                    }
                    selectedRows.forEach(function (row) { row.remove(); });
                };
            }
        }());
        </script>
    </div>
    <?php endif; ?>

    <?php if ($activeTab === 'ai'): ?>
    <div class="md-card">
        <h3 class="md-title"><?php echo app_h($isEnglish ? 'AI Provider Integration' : 'تكامل مزود الذكاء الاصطناعي'); ?></h3>
        <div class="md-note">
            <?php echo app_h($isEnglish
                ? 'This integration enables the floating AI chat inside the system for logged-in users only. For a free/open-source setup, use Ollama. OpenAI remains optional as a paid provider.'
                : 'هذا التكامل يفعّل محادثة AI العائمة داخل النظام للمستخدمين المسجلين فقط. لاستخدام مجاني/مفتوح المصدر استخدم Ollama. ويظل OpenAI خيارًا اختياريًا مدفوعًا.'); ?>
        </div>
        <form method="post" class="md-grid">
            <?php echo app_csrf_input(); ?>
            <input type="hidden" name="action" value="save_ai_settings">
            <input type="hidden" name="tab" value="ai">
            <div>
                <label><?php echo app_h($isEnglish ? 'Provider' : 'المزود'); ?></label>
                <select class="md-select" name="ai_provider" id="ai-provider-select">
                    <option value="ollama" <?php echo $aiProvider === 'ollama' ? 'selected' : ''; ?>>Ollama</option>
                    <option value="openai" <?php echo $aiProvider === 'openai' ? 'selected' : ''; ?>>OpenAI</option>
                    <option value="gemini" <?php echo $aiProvider === 'gemini' ? 'selected' : ''; ?>>Google Gemini</option>
                    <option value="openai_compatible" <?php echo $aiProvider === 'openai_compatible' ? 'selected' : ''; ?>>OpenAI Compatible</option>
                </select>
                <span class="md-help"><?php echo app_h($isEnglish ? 'Choose the provider, then fetch models directly from its API.' : 'اختر المزود، ثم استدع الموديلات مباشرة من API الخاص به.'); ?></span>
            </div>
            <div style="grid-column:1/-1;">
                <label class="md-check-item" style="padding:14px;border-radius:14px;border:1px solid rgba(255,255,255,.08);display:flex;gap:10px;align-items:center;">
                    <input type="checkbox" name="ai_enabled" value="1" <?php echo $aiEnabled ? 'checked' : ''; ?>>
                    <span><?php echo app_h($isEnglish ? 'Enable AI chat for logged-in users' : 'تفعيل محادثة AI للمستخدمين المسجلين'); ?></span>
                </label>
                <span class="md-help"><?php echo app_h($aiProvider === 'ollama'
                    ? ($isEnglish ? 'Ollama does not require a paid API key. It requires a reachable Ollama server and a pulled model.' : 'Ollama لا يحتاج مفتاح API مدفوع. لكنه يحتاج خدمة Ollama عاملة وموديلًا تم سحبه.')
                    : ($aiConfigured
                        ? ($isEnglish ? 'A provider key is currently configured.' : 'يوجد مفتاح مزود مضبوط حاليًا.')
                        : ($isEnglish ? 'No provider key is configured yet.' : 'لا يوجد مفتاح مزود مضبوط حاليًا.'))); ?></span>
            </div>
            <div>
                <label><?php echo app_h($isEnglish ? 'Model' : 'الموديل'); ?></label>
                <input class="md-input" id="ai-model-input" name="ai_model" value="<?php echo app_h($aiModel); ?>" placeholder="<?php echo app_h($aiProvider === 'ollama' ? 'llama3.1:8b' : ($aiProvider === 'gemini' ? 'gemini-3-flash-preview' : 'gpt-5.4-mini')); ?>">
                <span class="md-help"><?php echo app_h($aiProvider === 'ollama'
                    ? ($isEnglish ? 'Examples: llama3.1:8b, mistral, qwen2.5:7b.' : 'أمثلة: llama3.1:8b, mistral, qwen2.5:7b.')
                    : ($aiProvider === 'gemini'
                        ? ($isEnglish ? 'Examples: gemini-3-flash-preview, gemini-2.5-pro.' : 'أمثلة: gemini-3-flash-preview, gemini-2.5-pro.')
                        : ($isEnglish ? 'Examples: gpt-5.4-mini, gpt-5.4, gpt-4.1-mini.' : 'أمثلة: gpt-5.4-mini, gpt-5.4, gpt-4.1-mini.'))); ?></span>
            </div>
            <div>
                <label><?php echo app_h($isEnglish ? 'Base URL' : 'Base URL'); ?></label>
                <input class="md-input" id="ai-base-url-input" name="ai_base_url" value="<?php echo app_h($aiBaseUrl); ?>" placeholder="<?php echo app_h($aiProvider === 'ollama' ? 'http://127.0.0.1:11434/v1' : ($aiProvider === 'gemini' ? 'https://generativelanguage.googleapis.com/v1beta/openai' : 'https://api.openai.com/v1')); ?>">
                <span class="md-help"><?php echo app_h($aiProvider === 'ollama'
                    ? ($isEnglish ? 'Default local endpoint for Ollama OpenAI-compatible API.' : 'هذا هو المسار المحلي الافتراضي لـ API المتوافق مع OpenAI في Ollama.')
                    : ($aiProvider === 'gemini'
                        ? ($isEnglish ? 'Gemini OpenAI-compatible base URL from Google AI.' : 'هذا هو Base URL المتوافق مع OpenAI الخاص بـ Gemini من Google AI.')
                        : ($isEnglish ? 'Keep the default unless you use a compatible gateway/proxy.' : 'اترك القيمة الافتراضية إلا إذا كنت تستخدم بوابة أو Proxy متوافقًا.'))); ?></span>
            </div>
            <div style="grid-column:1/-1;">
                <label><?php echo app_h($isEnglish ? 'API Key / Token (optional for Ollama)' : 'API Key / Token (اختياري مع Ollama)'); ?></label>
                <input class="md-input" id="ai-api-key-input" type="password" name="ai_api_key" value="<?php echo app_h($aiApiKey); ?>" placeholder="<?php echo app_h($aiProvider === 'ollama' ? 'ollama' : ($aiProvider === 'gemini' ? 'AIza...' : 'sk-...')); ?>">
                <span class="md-help"><?php echo app_h($aiProvider === 'ollama'
                    ? ($isEnglish ? 'Ollama local deployments usually do not need a real key. The integration can send a placeholder token.' : 'عادة لا تحتاج نشرات Ollama المحلية إلى مفتاح حقيقي. يمكن للنظام إرسال token شكلي.')
                    : ($isEnglish ? 'The key is stored in app settings. For stronger isolation, store it in the environment and leave this field empty.' : 'يُحفظ المفتاح داخل إعدادات النظام. للعزل الأقوى، ضعه في البيئة واترك هذا الحقل فارغًا.')); ?></span>
                <?php if ($aiApiKeyMasked !== ''): ?>
                    <span class="md-help"><?php echo app_h(($isEnglish ? 'Current saved key:' : 'المفتاح المحفوظ حاليًا:') . ' ' . $aiApiKeyMasked); ?></span>
                <?php endif; ?>
            </div>
            <div style="grid-column:1/-1;">
                <div class="md-form-actions" style="margin-bottom:10px;">
                    <button class="md-btn-neutral" type="button" id="ai-fetch-models-btn"><?php echo app_h($isEnglish ? 'Fetch Models' : 'استدعاء الموديلات'); ?></button>
                    <select class="md-select" id="ai-model-select" style="max-width:360px;">
                        <option value=""><?php echo app_h($isEnglish ? 'Choose discovered model' : 'اختر موديلًا مكتشفًا'); ?></option>
                    </select>
                    <span id="ai-models-status" class="md-help" style="margin:0;"></span>
                </div>
                <div style="overflow:auto;">
                    <table class="md-table" id="ai-models-table">
                        <thead>
                            <tr>
                                <th><?php echo app_h($isEnglish ? 'Model' : 'الموديل'); ?></th>
                                <th><?php echo app_h($isEnglish ? 'Status' : 'الحالة'); ?></th>
                                <th><?php echo app_h($isEnglish ? 'Usable for chat' : 'قابل للاستخدام في الشات'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr><td colspan="3" class="md-small-muted"><?php echo app_h($isEnglish ? 'Use "Fetch Models" to load models from the selected provider.' : 'استخدم "استدعاء الموديلات" لتحميل الموديلات من المزود المختار.'); ?></td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <div style="grid-column:1/-1;">
                <div class="md-note" style="margin:0;">
                    <?php echo app_h($aiProvider === 'ollama'
                        ? ($isEnglish ? 'Status: the integration is ready, but this server currently does not run Ollama. Start Ollama or point Base URL to a reachable Ollama host.' : 'الحالة: التكامل جاهز، لكن هذا الخادم لا يشغّل Ollama حاليًا. شغّل Ollama أو وجّه Base URL إلى خادم Ollama متاح.')
                        : ($aiProvider === 'gemini'
                            ? ($isEnglish ? 'Status: Gemini is supported through Google OpenAI-compatible endpoint. Add the Gemini API key, fetch models, then choose one.' : 'الحالة: Gemini مدعوم عبر endpoint جوجل المتوافق مع OpenAI. أضف مفتاح Gemini ثم استدع الموديلات واختر واحدًا.')
                            : ($aiConfigured
                                ? ($isEnglish ? 'Status: the provider can be used immediately after saving if the enable flag is on.' : 'الحالة: يمكن استخدام المزود مباشرة بعد الحفظ إذا كان التفعيل مفعّلًا.')
                                : ($isEnglish ? 'Status: this provider remains blocked until a real API key is added.' : 'الحالة: هذا المزود سيبقى متوقفًا حتى إضافة API Key حقيقي.')))); ?>
                </div>
            </div>
            <div style="display:flex; align-items:flex-end;">
                <button class="md-btn" type="submit"><?php echo app_h($isEnglish ? 'Save AI Settings' : 'حفظ إعدادات AI'); ?></button>
            </div>
        </form>
        <script>
        (function () {
            const btn = document.getElementById('ai-fetch-models-btn');
            const providerInput = document.getElementById('ai-provider-select');
            const baseUrlInput = document.getElementById('ai-base-url-input');
            const apiKeyInput = document.getElementById('ai-api-key-input');
            const modelInput = document.getElementById('ai-model-input');
            const modelSelect = document.getElementById('ai-model-select');
            const status = document.getElementById('ai-models-status');
            const table = document.getElementById('ai-models-table');
            if (!btn || !providerInput || !baseUrlInput || !apiKeyInput || !modelInput || !modelSelect || !table) return;

            const textLoading = <?php echo json_encode($isEnglish ? 'Loading models...' : 'جارٍ تحميل الموديلات...', JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
            const textFailed = <?php echo json_encode($isEnglish ? 'Could not load models.' : 'تعذر تحميل الموديلات.', JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
            const textEmpty = <?php echo json_encode($isEnglish ? 'No models returned by this provider.' : 'لم يُرجع المزود أي موديلات.', JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
            const textYes = <?php echo json_encode($isEnglish ? 'Yes' : 'نعم', JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
            const textNo = <?php echo json_encode($isEnglish ? 'No' : 'لا', JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
            const textChoose = <?php echo json_encode($isEnglish ? 'Choose discovered model' : 'اختر موديلًا مكتشفًا', JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;

            modelSelect.addEventListener('change', function () {
                if (modelSelect.value) {
                    modelInput.value = modelSelect.value;
                }
            });

            btn.addEventListener('click', async function () {
                status.textContent = textLoading;
                modelSelect.innerHTML = '<option value="">' + textChoose + '</option>';
                table.querySelector('tbody').innerHTML = '';
                try {
                    const fd = new FormData();
                    fd.append('_csrf_token', <?php echo json_encode(app_csrf_token(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>);
                    fd.append('provider', providerInput.value || '');
                    fd.append('base_url', baseUrlInput.value || '');
                    fd.append('api_key', apiKeyInput.value || '');
                    const res = await fetch('ai_provider_models_api.php', {
                        method: 'POST',
                        body: fd,
                        credentials: 'same-origin',
                        headers: { 'X-Requested-With': 'XMLHttpRequest' }
                    });
                    const payload = await res.json().catch(() => null);
                    if (!res.ok || !payload || !payload.ok) {
                        throw new Error((payload && (payload.message || payload.error)) ? (payload.message || payload.error) : textFailed);
                    }
                    const models = Array.isArray(payload.models) ? payload.models : [];
                    if (!models.length) {
                        table.querySelector('tbody').innerHTML = '<tr><td colspan="3" class="md-small-muted">' + textEmpty + '</td></tr>';
                        status.textContent = textEmpty;
                        return;
                    }
                    const rows = [];
                    models.forEach((model) => {
                        const id = String(model.id || '').trim();
                        if (!id) return;
                        const opt = document.createElement('option');
                        opt.value = id;
                        opt.textContent = id + (model.status ? ' [' + model.status + ']' : '');
                        if (modelInput.value === id) {
                            opt.selected = true;
                        }
                        modelSelect.appendChild(opt);
                        rows.push('<tr><td><code>' + id.replace(/</g, '&lt;') + '</code></td><td>' + String(model.status || 'available').replace(/</g, '&lt;') + '</td><td>' + (model.usable ? textYes : textNo) + '</td></tr>');
                    });
                    table.querySelector('tbody').innerHTML = rows.join('');
                    status.textContent = String(models.length) + ' ' + <?php echo json_encode($isEnglish ? 'models loaded.' : 'موديل تم تحميله.', JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
                } catch (err) {
                    const msg = String((err && err.message) ? err.message : textFailed);
                    table.querySelector('tbody').innerHTML = '<tr><td colspan="3" class="md-small-muted">' + msg.replace(/</g, '&lt;') + '</td></tr>';
                    status.textContent = msg;
                }
            });
        })();
        </script>
    </div>
    <?php endif; ?>

    <?php if ($activeTab === 'taxes'): ?>
    <div class="md-card">
        <h3 class="md-title"><?php echo app_h($txtSectionTaxes); ?></h3>
        <div class="md-note">
            <?php echo app_h($isEnglish
                ? 'Define tax types once, then reuse them inside sales invoices and quotations. Taxes are applied only when the document is marked as a tax invoice.'
                : 'عرّف أنواع الضرائب مرة واحدة ثم استخدمها داخل فواتير المبيعات وعروض الأسعار. لا يتم احتساب الضرائب إلا إذا تم تحديد المستند كفاتورة ضريبية.'); ?>
        </div>
        <form method="post">
            <?php echo app_csrf_input(); ?>
            <input type="hidden" name="action" value="save_tax_settings">
            <input type="hidden" name="tab" value="taxes">

            <div class="md-grid" style="margin-bottom:16px;">
                <div>
                    <label><?php echo app_h($isEnglish ? 'Default sales law' : 'القانون الافتراضي لفواتير المبيعات'); ?></label>
                    <select class="md-select" name="tax_default_sales_law">
                        <?php foreach ($taxLawRows as $lawRow): ?>
                            <option value="<?php echo app_h((string)$lawRow['key']); ?>" <?php echo ((string)$taxDefaultSalesLaw === (string)$lawRow['key']) ? 'selected' : ''; ?>>
                                <?php echo app_h((string)$lawRow['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label><?php echo app_h($isEnglish ? 'Default quotation law' : 'القانون الافتراضي لعروض الأسعار'); ?></label>
                    <select class="md-select" name="tax_default_quote_law">
                        <?php foreach ($taxLawRows as $lawRow): ?>
                            <option value="<?php echo app_h((string)$lawRow['key']); ?>" <?php echo ((string)$taxDefaultQuoteLaw === (string)$lawRow['key']) ? 'selected' : ''; ?>>
                                <?php echo app_h((string)$lawRow['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="md-title" style="margin-top:0;"><?php echo app_h($isEnglish ? 'Tax Types' : 'أنواع الضرائب'); ?></div>
            <div style="overflow:auto; margin-bottom:16px;">
                <table class="md-table" id="md-tax-table">
                    <thead>
                        <tr>
                            <th><?php echo app_h($isEnglish ? 'Name' : 'اسم الضريبة'); ?></th>
                            <th><?php echo app_h($isEnglish ? 'English Name' : 'الاسم الإنجليزي'); ?></th>
                            <th><?php echo app_h($isEnglish ? 'Key' : 'المفتاح'); ?></th>
                            <th><?php echo app_h($isEnglish ? 'Category' : 'الفئة'); ?></th>
                            <th><?php echo app_h($isEnglish ? 'Rate %' : 'النسبة %'); ?></th>
                            <th><?php echo app_h($isEnglish ? 'Mode' : 'طريقة الحساب'); ?></th>
                            <th><?php echo app_h($isEnglish ? 'Base' : 'أساس الاحتساب'); ?></th>
                            <th><?php echo app_h($isEnglish ? 'Scope' : 'النطاق'); ?></th>
                            <th><?php echo app_h($isEnglish ? 'Active' : 'مفعل'); ?></th>
                            <th><?php echo app_h($isEnglish ? 'Actions' : 'إجراءات'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $taxUiRows = !empty($taxRows) ? $taxRows : app_tax_default_types(); ?>
                        <?php foreach ($taxUiRows as $idx => $taxRow): ?>
                            <tr>
                                <td><input class="md-input" name="tax_rows[<?php echo (int)$idx; ?>][name]" value="<?php echo app_h((string)$taxRow['name']); ?>"></td>
                                <td><input class="md-input" name="tax_rows[<?php echo (int)$idx; ?>][name_en]" value="<?php echo app_h((string)($taxRow['name_en'] ?? '')); ?>"></td>
                                <td><input class="md-input" name="tax_rows[<?php echo (int)$idx; ?>][key]" value="<?php echo app_h((string)$taxRow['key']); ?>"></td>
                                <td>
                                    <select class="md-select" name="tax_rows[<?php echo (int)$idx; ?>][category]">
                                        <?php foreach (['vat' => ($isEnglish ? 'VAT' : 'قيمة مضافة'), 'withholding' => ($isEnglish ? 'Withholding' : 'خصم'), 'stamp' => ($isEnglish ? 'Stamp' : 'دمغة'), 'other' => ($isEnglish ? 'Other' : 'أخرى')] as $catKey => $catLabel): ?>
                                            <option value="<?php echo app_h($catKey); ?>" <?php echo ((string)$taxRow['category'] === $catKey) ? 'selected' : ''; ?>><?php echo app_h($catLabel); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td><input class="md-input" type="number" step="0.0001" name="tax_rows[<?php echo (int)$idx; ?>][rate]" value="<?php echo app_h((string)$taxRow['rate']); ?>"></td>
                                <td>
                                    <select class="md-select" name="tax_rows[<?php echo (int)$idx; ?>][mode]">
                                        <option value="add" <?php echo ((string)$taxRow['mode'] === 'add') ? 'selected' : ''; ?>><?php echo app_h($isEnglish ? 'Add to invoice' : 'إضافة على الفاتورة'); ?></option>
                                        <option value="subtract" <?php echo ((string)$taxRow['mode'] === 'subtract') ? 'selected' : ''; ?>><?php echo app_h($isEnglish ? 'Deduct from invoice' : 'خصم من الفاتورة'); ?></option>
                                    </select>
                                </td>
                                <td>
                                    <select class="md-select" name="tax_rows[<?php echo (int)$idx; ?>][base]">
                                        <option value="net_after_discount" <?php echo ((string)$taxRow['base'] === 'net_after_discount') ? 'selected' : ''; ?>><?php echo app_h($isEnglish ? 'Net after discount' : 'الصافي بعد الخصم'); ?></option>
                                        <option value="subtotal" <?php echo ((string)$taxRow['base'] === 'subtotal') ? 'selected' : ''; ?>><?php echo app_h($isEnglish ? 'Subtotal' : 'الإجمالي قبل الخصم'); ?></option>
                                    </select>
                                </td>
                                <td>
                                    <label class="md-check-item" style="margin-bottom:6px;"><input type="checkbox" name="tax_rows[<?php echo (int)$idx; ?>][scopes][]" value="sales" <?php echo in_array('sales', (array)($taxRow['scopes'] ?? []), true) ? 'checked' : ''; ?>><span><?php echo app_h($isEnglish ? 'Sales' : 'فواتير'); ?></span></label>
                                    <label class="md-check-item"><input type="checkbox" name="tax_rows[<?php echo (int)$idx; ?>][scopes][]" value="quotes" <?php echo in_array('quotes', (array)($taxRow['scopes'] ?? []), true) ? 'checked' : ''; ?>><span><?php echo app_h($isEnglish ? 'Quotes' : 'عروض أسعار'); ?></span></label>
                                </td>
                                <td><label class="md-check-item"><input type="checkbox" name="tax_rows[<?php echo (int)$idx; ?>][is_active]" value="1" <?php echo !empty($taxRow['is_active']) ? 'checked' : ''; ?>><span><?php echo app_h($isEnglish ? 'Enabled' : 'مفعل'); ?></span></label></td>
                                <td><button type="button" class="md-btn-danger js-tax-row-remove"><?php echo app_h($isEnglish ? 'Delete' : 'حذف'); ?></button></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="md-form-actions" style="margin-bottom:18px;">
                <button type="button" class="md-btn-neutral" id="md-add-tax-row"><?php echo app_h($isEnglish ? 'Add Tax Type' : 'إضافة نوع ضريبة'); ?></button>
            </div>

            <div class="md-title"><?php echo app_h($isEnglish ? 'Tax Laws / Reporting Templates' : 'القوانين الضريبية / قوالب الإقرار'); ?></div>
            <div style="overflow:auto;">
                <table class="md-table" id="md-tax-law-table">
                    <thead>
                        <tr>
                            <th><?php echo app_h($isEnglish ? 'Law Name' : 'اسم القانون'); ?></th>
                            <th><?php echo app_h($isEnglish ? 'English Name' : 'الاسم الإنجليزي'); ?></th>
                            <th><?php echo app_h($isEnglish ? 'Key' : 'المفتاح'); ?></th>
                            <th><?php echo app_h($isEnglish ? 'Category' : 'الفئة'); ?></th>
                            <th><?php echo app_h($isEnglish ? 'Frequency' : 'دورية الإقرار'); ?></th>
                            <th><?php echo app_h($isEnglish ? 'Settlement' : 'طريقة المقاصة'); ?></th>
                            <th><?php echo app_h($isEnglish ? 'Brackets' : 'الشرائح'); ?></th>
                            <th><?php echo app_h($isEnglish ? 'Notes' : 'ملاحظات'); ?></th>
                            <th><?php echo app_h($isEnglish ? 'Active' : 'مفعل'); ?></th>
                            <th><?php echo app_h($isEnglish ? 'Actions' : 'إجراءات'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $lawUiRows = !empty($taxLawRows) ? $taxLawRows : app_tax_default_laws(); ?>
                        <?php foreach ($lawUiRows as $idx => $lawRow): ?>
                            <tr>
                                <td><input class="md-input" name="law_rows[<?php echo (int)$idx; ?>][name]" value="<?php echo app_h((string)$lawRow['name']); ?>"></td>
                                <td><input class="md-input" name="law_rows[<?php echo (int)$idx; ?>][name_en]" value="<?php echo app_h((string)($lawRow['name_en'] ?? '')); ?>"></td>
                                <td><input class="md-input" name="law_rows[<?php echo (int)$idx; ?>][key]" value="<?php echo app_h((string)$lawRow['key']); ?>"></td>
                                <td>
                                    <select class="md-select" name="law_rows[<?php echo (int)$idx; ?>][category]">
                                        <?php foreach (['vat' => ($isEnglish ? 'VAT' : 'قيمة مضافة'), 'income' => ($isEnglish ? 'Income' : 'دخل'), 'simplified' => ($isEnglish ? 'Simplified' : 'مبسط'), 'stamp' => ($isEnglish ? 'Stamp' : 'دمغة'), 'procedural' => ($isEnglish ? 'Procedural' : 'إجرائي')] as $catKey => $catLabel): ?>
                                            <option value="<?php echo app_h($catKey); ?>" <?php echo ((string)$lawRow['category'] === $catKey) ? 'selected' : ''; ?>><?php echo app_h($catLabel); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td>
                                    <select class="md-select" name="law_rows[<?php echo (int)$idx; ?>][frequency]">
                                        <?php foreach (['monthly' => ($isEnglish ? 'Monthly' : 'شهري'), 'quarterly' => ($isEnglish ? 'Quarterly' : 'ربع سنوي'), 'annual' => ($isEnglish ? 'Annual' : 'سنوي'), 'informational' => ($isEnglish ? 'Info' : 'معلوماتي')] as $freqKey => $freqLabel): ?>
                                            <option value="<?php echo app_h($freqKey); ?>" <?php echo ((string)$lawRow['frequency'] === $freqKey) ? 'selected' : ''; ?>><?php echo app_h($freqLabel); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td>
                                    <select class="md-select" name="law_rows[<?php echo (int)$idx; ?>][settlement_mode]">
                                        <?php foreach (['vat_offset' => ($isEnglish ? 'VAT Offset' : 'مقاصة قيمة مضافة'), 'standalone' => ($isEnglish ? 'Standalone' : 'منفصل'), 'turnover_based' => ($isEnglish ? 'Turnover Based' : 'على حجم الأعمال'), 'informational' => ($isEnglish ? 'Informational' : 'معلوماتي')] as $modeKey => $modeLabel): ?>
                                            <option value="<?php echo app_h($modeKey); ?>" <?php echo ((string)$lawRow['settlement_mode'] === $modeKey) ? 'selected' : ''; ?>><?php echo app_h($modeLabel); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td>
                                    <textarea class="md-input" name="law_rows[<?php echo (int)$idx; ?>][brackets_text]" rows="5" placeholder="<?php echo app_h($isEnglish ? 'Label | From | To | Rate' : 'اسم الشريحة | من | إلى | النسبة'); ?>"><?php echo app_h(app_tax_law_brackets_to_text((array)($lawRow['brackets'] ?? []))); ?></textarea>
                                    <div class="md-help"><?php echo app_h($isEnglish ? 'One bracket per line. Example: Under 500k | 0 | 500000 | 0.4' : 'سطر لكل شريحة. مثال: أقل من 500 ألف | 0 | 500000 | 0.4'); ?></div>
                                </td>
                                <td><input class="md-input" name="law_rows[<?php echo (int)$idx; ?>][notes]" value="<?php echo app_h((string)($lawRow['notes'] ?? '')); ?>"></td>
                                <td><label class="md-check-item"><input type="checkbox" name="law_rows[<?php echo (int)$idx; ?>][is_active]" value="1" <?php echo !empty($lawRow['is_active']) ? 'checked' : ''; ?>><span><?php echo app_h($isEnglish ? 'Enabled' : 'مفعل'); ?></span></label></td>
                                <td><button type="button" class="md-btn-danger js-tax-law-row-remove"><?php echo app_h($isEnglish ? 'Delete' : 'حذف'); ?></button></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="md-form-actions" style="margin-top:10px;">
                <button type="button" class="md-btn-neutral" id="md-add-tax-law-row"><?php echo app_h($isEnglish ? 'Add Law' : 'إضافة قانون'); ?></button>
                <button class="md-btn" type="submit"><?php echo app_h($isEnglish ? 'Save Tax Settings' : 'حفظ إعدادات الضرائب'); ?></button>
            </div>
        </form>
    </div>
    <script>
    (function () {
        const taxTable = document.getElementById('md-tax-table');
        const lawTable = document.getElementById('md-tax-law-table');
        const addTaxBtn = document.getElementById('md-add-tax-row');
        const addLawBtn = document.getElementById('md-add-tax-law-row');
        if (!taxTable || !lawTable || !addTaxBtn || !addLawBtn) return;

        function taxIndex() { return taxTable.querySelectorAll('tbody tr').length; }
        function lawIndex() { return lawTable.querySelectorAll('tbody tr').length; }

        addTaxBtn.addEventListener('click', function () {
            const idx = taxIndex();
            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td><input class="md-input" name="tax_rows[${idx}][name]" value=""></td>
                <td><input class="md-input" name="tax_rows[${idx}][name_en]" value=""></td>
                <td><input class="md-input" name="tax_rows[${idx}][key]" value=""></td>
                <td><select class="md-select" name="tax_rows[${idx}][category]"><option value="vat"><?php echo app_h($isEnglish ? 'VAT' : 'قيمة مضافة'); ?></option><option value="withholding"><?php echo app_h($isEnglish ? 'Withholding' : 'خصم'); ?></option><option value="stamp"><?php echo app_h($isEnglish ? 'Stamp' : 'دمغة'); ?></option><option value="other"><?php echo app_h($isEnglish ? 'Other' : 'أخرى'); ?></option></select></td>
                <td><input class="md-input" type="number" step="0.0001" name="tax_rows[${idx}][rate]" value="0"></td>
                <td><select class="md-select" name="tax_rows[${idx}][mode]"><option value="add"><?php echo app_h($isEnglish ? 'Add to invoice' : 'إضافة على الفاتورة'); ?></option><option value="subtract"><?php echo app_h($isEnglish ? 'Deduct from invoice' : 'خصم من الفاتورة'); ?></option></select></td>
                <td><select class="md-select" name="tax_rows[${idx}][base]"><option value="net_after_discount"><?php echo app_h($isEnglish ? 'Net after discount' : 'الصافي بعد الخصم'); ?></option><option value="subtotal"><?php echo app_h($isEnglish ? 'Subtotal' : 'الإجمالي قبل الخصم'); ?></option></select></td>
                <td>
                    <label class="md-check-item" style="margin-bottom:6px;"><input type="checkbox" name="tax_rows[${idx}][scopes][]" value="sales" checked><span><?php echo app_h($isEnglish ? 'Sales' : 'فواتير'); ?></span></label>
                    <label class="md-check-item"><input type="checkbox" name="tax_rows[${idx}][scopes][]" value="quotes" checked><span><?php echo app_h($isEnglish ? 'Quotes' : 'عروض أسعار'); ?></span></label>
                </td>
                <td><label class="md-check-item"><input type="checkbox" name="tax_rows[${idx}][is_active]" value="1" checked><span><?php echo app_h($isEnglish ? 'Enabled' : 'مفعل'); ?></span></label></td>
                <td><button type="button" class="md-btn-danger js-tax-row-remove"><?php echo app_h($isEnglish ? 'Delete' : 'حذف'); ?></button></td>
            `;
            taxTable.querySelector('tbody').appendChild(tr);
        });

        addLawBtn.addEventListener('click', function () {
            const idx = lawIndex();
            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td><input class="md-input" name="law_rows[${idx}][name]" value=""></td>
                <td><input class="md-input" name="law_rows[${idx}][name_en]" value=""></td>
                <td><input class="md-input" name="law_rows[${idx}][key]" value=""></td>
                <td><select class="md-select" name="law_rows[${idx}][category]"><option value="vat"><?php echo app_h($isEnglish ? 'VAT' : 'قيمة مضافة'); ?></option><option value="income"><?php echo app_h($isEnglish ? 'Income' : 'دخل'); ?></option><option value="simplified"><?php echo app_h($isEnglish ? 'Simplified' : 'مبسط'); ?></option><option value="stamp"><?php echo app_h($isEnglish ? 'Stamp' : 'دمغة'); ?></option><option value="procedural"><?php echo app_h($isEnglish ? 'Procedural' : 'إجرائي'); ?></option></select></td>
                <td><select class="md-select" name="law_rows[${idx}][frequency]"><option value="monthly"><?php echo app_h($isEnglish ? 'Monthly' : 'شهري'); ?></option><option value="quarterly"><?php echo app_h($isEnglish ? 'Quarterly' : 'ربع سنوي'); ?></option><option value="annual"><?php echo app_h($isEnglish ? 'Annual' : 'سنوي'); ?></option><option value="informational"><?php echo app_h($isEnglish ? 'Info' : 'معلوماتي'); ?></option></select></td>
                <td><select class="md-select" name="law_rows[${idx}][settlement_mode]"><option value="vat_offset"><?php echo app_h($isEnglish ? 'VAT Offset' : 'مقاصة قيمة مضافة'); ?></option><option value="standalone"><?php echo app_h($isEnglish ? 'Standalone' : 'منفصل'); ?></option><option value="turnover_based"><?php echo app_h($isEnglish ? 'Turnover Based' : 'على حجم الأعمال'); ?></option><option value="informational"><?php echo app_h($isEnglish ? 'Informational' : 'معلوماتي'); ?></option></select></td>
                <td><textarea class="md-input" name="law_rows[${idx}][brackets_text]" rows="5" placeholder="<?php echo app_h($isEnglish ? 'Label | From | To | Rate' : 'اسم الشريحة | من | إلى | النسبة'); ?>"></textarea></td>
                <td><input class="md-input" name="law_rows[${idx}][notes]" value=""></td>
                <td><label class="md-check-item"><input type="checkbox" name="law_rows[${idx}][is_active]" value="1" checked><span><?php echo app_h($isEnglish ? 'Enabled' : 'مفعل'); ?></span></label></td>
                <td><button type="button" class="md-btn-danger js-tax-law-row-remove"><?php echo app_h($isEnglish ? 'Delete' : 'حذف'); ?></button></td>
            `;
            lawTable.querySelector('tbody').appendChild(tr);
        });

        document.addEventListener('click', function (event) {
            if (event.target.classList.contains('js-tax-row-remove')) {
                event.target.closest('tr')?.remove();
            }
            if (event.target.classList.contains('js-tax-law-row-remove')) {
                event.target.closest('tr')?.remove();
            }
        });
    })();
    </script>
    <?php endif; ?>

    <?php if ($activeTab === 'types'): ?>
    <div class="md-card">
        <h3 class="md-title"><?php echo app_h($txtSectionTypes); ?></h3>
        <div class="md-note">
            <?php echo app_h($isEnglish ? 'Smart creation flow: type name (AR/EN) -> select stages -> define required operations + WhatsApp/Email actions per stage.' : 'مسار الإدراج الذكي: اسم النشاط (عربي/إنجليزي) -> اختيار المراحل -> تحديد العمليات المطلوبة + إجراءات واتساب/إيميل لكل مرحلة.'); ?>
        </div>
        <form method="post" class="md-grid" id="md-smart-type-form" style="margin-bottom:16px;">
            <?php echo app_csrf_input(); ?>
            <input type="hidden" name="action" value="save_type_smart">
            <input type="hidden" name="tab" value="types">
            <input type="hidden" name="scope_type" value="<?php echo app_h($scopeType); ?>">
            <div><input class="md-input" id="smart_type_name" name="smart_type_name" placeholder="<?php echo app_h($isEnglish ? 'Type name (Arabic)' : 'اسم النشاط بالعربية'); ?>" required><span class="md-help"><?php echo app_h($isEnglish ? 'First step: write the Arabic activity name.' : 'الخطوة الأولى: اكتب اسم النشاط بالعربية.'); ?></span></div>
            <div><input class="md-input" id="smart_type_name_en" name="smart_type_name_en" placeholder="<?php echo app_h($isEnglish ? 'Type name (English)' : 'اسم النشاط بالإنجليزية'); ?>" required><span class="md-help"><?php echo app_h($isEnglish ? 'Second step: write the English name used in EN interface.' : 'الخطوة الثانية: اكتب الاسم الإنجليزي المستخدم في واجهة EN.'); ?></span></div>
            <div><input class="md-input" id="smart_type_key" name="smart_type_key" placeholder="type_key مثال: flexo_print" required><span class="md-help"><?php echo app_h($isEnglish ? 'Internal key (generated automatically from EN name; editable).' : 'مفتاح داخلي (يتولد تلقائيًا من الاسم الإنجليزي ويمكن تعديله).'); ?></span></div>
            <div><input class="md-input" name="smart_icon_class" placeholder="fa-print" value="fa-circle"></div>
            <div><input class="md-input" type="number" name="smart_sort_order" value="100" placeholder="الترتيب"></div>
            <div class="md-form-actions" style="align-items:flex-end;">
                <button class="md-btn" type="submit"><?php echo app_h($isEnglish ? 'Create Smart Activity' : 'إنشاء نشاط ذكي'); ?></button>
            </div>

            <div style="grid-column:1/-1;">
                <label><?php echo app_h($isEnglish ? 'Choose workflow stages' : 'اختر مراحل سير العمل'); ?></label>
                <div class="md-check-grid">
                    <?php foreach ($smartStageTemplates as $stageKey => $stageTpl): ?>
                        <label class="md-check-item">
                            <input type="checkbox" class="js-smart-stage-toggle" name="smart_stage_keys[]" value="<?php echo app_h($stageKey); ?>" <?php echo in_array($stageKey, ['briefing', 'design', 'client_rev', 'completed'], true) ? 'checked' : ''; ?>>
                            <span><?php echo app_h((string)($isEnglish ? $stageTpl['en'] : $stageTpl['ar'])); ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <div style="grid-column:1/-1;" id="md-smart-stage-configs">
                <?php foreach ($smartStageTemplates as $stageKey => $stageTpl): ?>
                    <div class="md-smart-stage-card js-smart-stage-card" data-stage-key="<?php echo app_h($stageKey); ?>">
                        <div class="md-smart-stage-head">
                            <span><?php echo app_h($isEnglish ? 'Stage Setup: ' : 'إعداد المرحلة: '); ?><?php echo app_h((string)($isEnglish ? $stageTpl['en'] : $stageTpl['ar'])); ?></span>
                            <span class="md-small-muted"><?php echo app_h($stageKey); ?></span>
                        </div>
                        <div class="md-grid">
                            <div><input class="md-input" name="smart_stage_name_ar[<?php echo app_h($stageKey); ?>]" value="<?php echo app_h((string)$stageTpl['ar']); ?>" placeholder="<?php echo app_h($isEnglish ? 'Arabic stage name' : 'اسم المرحلة بالعربية'); ?>"></div>
                            <div><input class="md-input" name="smart_stage_name_en[<?php echo app_h($stageKey); ?>]" value="<?php echo app_h((string)$stageTpl['en']); ?>" placeholder="<?php echo app_h($isEnglish ? 'English stage name' : 'اسم المرحلة بالإنجليزية'); ?>"></div>
                            <div>
                                <select class="md-select" name="smart_stage_terminal[<?php echo app_h($stageKey); ?>]">
                                    <option value="0" <?php echo ((int)($stageTpl['terminal'] ?? 0) === 0) ? 'selected' : ''; ?>><?php echo app_h($isEnglish ? 'Normal Stage' : 'مرحلة عادية'); ?></option>
                                    <option value="1" <?php echo ((int)($stageTpl['terminal'] ?? 0) === 1) ? 'selected' : ''; ?>><?php echo app_h($isEnglish ? 'Terminal Stage' : 'مرحلة نهائية'); ?></option>
                                </select>
                            </div>
                            <div style="grid-column:1/-1;">
                                <label><?php echo app_h($isEnglish ? 'Required operations in this stage (comma separated)' : 'العمليات المطلوبة داخل هذه المرحلة (مفصولة بفواصل)'); ?></label>
                                <textarea class="md-input" rows="2" name="smart_stage_required_ops[<?php echo app_h($stageKey); ?>]" placeholder="<?php echo app_h($isEnglish ? 'Example: CTP, Printing, Lamination' : 'مثال: CTP, طباعة, سلفان'); ?>"></textarea>
                                <div class="md-pill-wrap js-smart-ops-pool"></div>
                            </div>
                            <div style="grid-column:1/-1;">
                                <label><?php echo app_h($isEnglish ? 'Communication actions for this stage' : 'إجراءات التواصل لهذه المرحلة'); ?></label>
                                <div class="md-check-grid">
                                    <?php foreach ($stageActionDefs as $actionKey => $actionDef): ?>
                                        <label class="md-check-item">
                                            <input type="checkbox" name="smart_stage_actions[<?php echo app_h($stageKey); ?>][]" value="<?php echo app_h($actionKey); ?>" <?php echo in_array($actionKey, (array)($stageTpl['actions'] ?? []), true) ? 'checked' : ''; ?>>
                                            <span><?php echo app_h((string)($isEnglish ? $actionDef['en'] : $actionDef['ar'])); ?></span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </form>

        <div class="md-note"><?php echo app_h($isEnglish ? 'Manual edit form for an existing operation type.' : 'نموذج يدوي لتعديل نوع عملية موجود.'); ?></div>
        <form method="post" class="md-grid" style="margin-bottom:14px;">
            <?php echo app_csrf_input(); ?>
            <input type="hidden" name="action" value="save_type">
            <input type="hidden" name="tab" value="types">
            <input type="hidden" name="scope_type" value="<?php echo app_h($scopeType); ?>">
            <div><input class="md-input" name="type_key" placeholder="type_key مثال: print" value="<?php echo app_h((string)$typeForm['type_key']); ?>" <?php echo $isTypeEdit ? 'readonly' : ''; ?> required><span class="md-help"><?php echo app_h(app_t('md.help.type_key', 'مفتاح داخلي ثابت للنوع (أحرف/أرقام/شرطة سفلية).')); ?></span></div>
            <div><input class="md-input" name="type_name" placeholder="<?php echo app_h($isEnglish ? 'Arabic name' : 'الاسم بالعربية'); ?>" value="<?php echo app_h((string)$typeForm['type_name']); ?>" required><span class="md-help"><?php echo app_h($isEnglish ? 'Arabic display name shown when language = Arabic.' : 'الاسم الذي يظهر عند اختيار اللغة العربية.'); ?></span></div>
            <div><input class="md-input" name="type_name_en" placeholder="<?php echo app_h($isEnglish ? 'English name' : 'الاسم بالإنجليزية'); ?>" value="<?php echo app_h((string)$typeForm['type_name_en']); ?>"><span class="md-help"><?php echo app_h($isEnglish ? 'English display name shown when language = English.' : 'الاسم الذي يظهر عند اختيار اللغة الإنجليزية.'); ?></span></div>
            <div><input class="md-input" name="icon_class" placeholder="أيقونة FontAwesome مثل fa-print" value="<?php echo app_h((string)$typeForm['icon_class']); ?>"><span class="md-help"><?php echo app_h($isEnglish ? 'FontAwesome class for visual identity.' : 'كلاس أيقونة FontAwesome لتمييز النوع بصرياً.'); ?></span></div>
            <div><input class="md-input" name="default_stage_key" placeholder="المرحلة الافتراضية" value="<?php echo app_h((string)$typeForm['default_stage_key']); ?>"><span class="md-help"><?php echo app_h($isEnglish ? 'The first stage used when creating jobs of this type.' : 'المرحلة التي يبدأ منها أمر التشغيل لهذا النوع.'); ?></span></div>
            <div><input class="md-input" type="number" name="sort_order" placeholder="الأولوية/الترتيب" value="<?php echo (int)$typeForm['sort_order']; ?>"><span class="md-help"><?php echo app_h($isEnglish ? 'Lower value appears first in dropdowns.' : 'رقم أقل = ظهور أعلى في القوائم.'); ?></span></div>
            <div class="md-form-actions">
                <button class="md-btn" type="submit"><?php echo app_h($isTypeEdit ? app_tr('حفظ التعديل', 'Save Changes') : app_t('md.btn.add_update', 'إضافة / تحديث النوع')); ?></button>
                <?php if ($isTypeEdit): ?>
                    <a class="md-link-mini" href="<?php echo app_h(md_tab_url('types')); ?>"><?php echo app_h(app_tr('إلغاء التعديل', 'Cancel Edit')); ?></a>
                <?php endif; ?>
            </div>
        </form>
        <table class="md-table">
            <thead><tr><th>المفتاح</th><th>الاسم (AR)</th><th>الاسم (EN)</th><th>الأولوية</th><th>الأيقونة</th><th>المرحلة الافتراضية</th><th>نشط</th><th>تحكم</th></tr></thead>
            <tbody>
                <?php foreach ($types as $type): ?>
                    <tr>
                        <td><?php echo app_h((string)$type['type_key']); ?></td>
                        <td><?php echo app_h((string)($type['type_name_ar'] ?? $type['type_name'] ?? '')); ?></td>
                        <td><?php echo app_h((string)($type['type_name_en'] ?? '')); ?></td>
                        <td><?php echo (int)($type['sort_order'] ?? 0); ?></td>
                        <td><?php echo app_h((string)$type['icon_class']); ?></td>
                        <td><?php echo app_h((string)$type['default_stage_key']); ?></td>
                        <td><?php echo (int)$type['is_active'] === 1 ? 'نعم' : 'لا'; ?></td>
                        <td>
                            <a class="md-link-mini" href="<?php echo app_h(md_tab_url('types', ['edit_type' => (string)$type['type_key']])); ?>"><?php echo app_h(app_tr('تعديل', 'Edit')); ?></a>
                            <form method="post" style="display:inline;">
                                <?php echo app_csrf_input(); ?>
                                <input type="hidden" name="tab" value="types">
                                <input type="hidden" name="scope_type" value="<?php echo app_h($scopeType); ?>">
                                <input type="hidden" name="action" value="move_type">
                                <input type="hidden" name="type_key" value="<?php echo app_h((string)$type['type_key']); ?>">
                                <input type="hidden" name="direction" value="up">
                                <button class="md-btn-mini" type="submit" title="<?php echo app_h(app_tr('تحريك لأعلى', 'Move Up')); ?>">↑</button>
                            </form>
                            <form method="post" style="display:inline;">
                                <?php echo app_csrf_input(); ?>
                                <input type="hidden" name="tab" value="types">
                                <input type="hidden" name="scope_type" value="<?php echo app_h($scopeType); ?>">
                                <input type="hidden" name="action" value="move_type">
                                <input type="hidden" name="type_key" value="<?php echo app_h((string)$type['type_key']); ?>">
                                <input type="hidden" name="direction" value="down">
                                <button class="md-btn-mini" type="submit" title="<?php echo app_h(app_tr('تحريك لأسفل', 'Move Down')); ?>">↓</button>
                            </form>
                            <form method="post" style="display:inline;">
                                <?php echo app_csrf_input(); ?>
                                <input type="hidden" name="tab" value="types">
                                <input type="hidden" name="scope_type" value="<?php echo app_h($scopeType); ?>">
                                <input type="hidden" name="action" value="toggle_type">
                                <input type="hidden" name="type_key" value="<?php echo app_h((string)$type['type_key']); ?>">
                                <input type="hidden" name="is_active" value="<?php echo (int)$type['is_active'] === 1 ? '0' : '1'; ?>">
                                <button class="md-btn-danger" type="submit"><?php echo (int)$type['is_active'] === 1 ? app_h(app_tr('تعطيل', 'Disable')) : app_h(app_tr('تفعيل', 'Enable')); ?></button>
                            </form>
                            <form method="post" style="display:inline;">
                                <?php echo app_csrf_input(); ?>
                                <input type="hidden" name="tab" value="types">
                                <input type="hidden" name="scope_type" value="<?php echo app_h($scopeType); ?>">
                                <input type="hidden" name="action" value="delete_type">
                                <input type="hidden" name="type_key" value="<?php echo app_h((string)$type['type_key']); ?>">
                                <button class="md-btn-neutral" type="submit" onclick="return confirm('<?php echo app_h(app_tr('سيتم حذف النوع وجميع مراحله وعناصره. متابعة؟', 'This type and all related stages/items will be deleted. Continue?')); ?>')"><?php echo app_h(app_t('md.btn.delete', 'حذف')); ?></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <?php if ($activeTab === 'stages'): ?>
    <div class="md-card">
        <h3 class="md-title"><?php echo app_h($txtSectionStages); ?></h3>
        <form method="post" class="md-grid" style="margin-bottom:14px;">
            <?php echo app_csrf_input(); ?>
            <input type="hidden" name="action" value="save_stage">
            <input type="hidden" name="tab" value="stages">
            <input type="hidden" name="scope_type" value="<?php echo app_h($scopeType); ?>">
            <input type="hidden" name="stage_id" value="<?php echo (int)$stageForm['stage_id']; ?>">
            <div><select class="md-select" name="stage_type_key" required>
                <option value="">نوع العملية</option>
                <?php foreach ($types as $type): ?><option value="<?php echo app_h((string)$type['type_key']); ?>" <?php echo (string)$stageForm['stage_type_key'] === (string)$type['type_key'] ? 'selected' : ''; ?>><?php echo app_h((string)$type['type_name']); ?></option><?php endforeach; ?>
            </select><span class="md-help"><?php echo app_h($isEnglish ? 'Operation type that owns this stage.' : 'حدد نوع العملية المرتبط بهذه المرحلة.'); ?></span></div>
            <div><input class="md-input" name="stage_key" placeholder="stage_key مثال: pre_press" value="<?php echo app_h((string)$stageForm['stage_key']); ?>" required><span class="md-help"><?php echo app_h(app_t('md.help.stage_key', 'مفتاح داخلي ثابت لمسار المرحلة والتنقل.')); ?></span></div>
            <div><input class="md-input" name="stage_name" placeholder="<?php echo app_h($isEnglish ? 'Arabic stage name' : 'اسم المرحلة بالعربية'); ?>" value="<?php echo app_h((string)$stageForm['stage_name']); ?>" required><span class="md-help"><?php echo app_h($isEnglish ? 'Arabic stage label shown in Arabic UI.' : 'الاسم الذي يظهر للمرحلة في الواجهة العربية.'); ?></span></div>
            <div><input class="md-input" name="stage_name_en" placeholder="<?php echo app_h($isEnglish ? 'English stage name' : 'اسم المرحلة بالإنجليزية'); ?>" value="<?php echo app_h((string)$stageForm['stage_name_en']); ?>"><span class="md-help"><?php echo app_h($isEnglish ? 'English stage label shown in English UI.' : 'الاسم الذي يظهر للمرحلة في الواجهة الإنجليزية.'); ?></span></div>
            <div><input class="md-input" type="number" name="stage_order" value="<?php echo (int)$stageForm['stage_order']; ?>" placeholder="الأولوية/الترتيب"><span class="md-help"><?php echo app_h($isEnglish ? 'Controls stage sequence in workflow.' : 'يحدد ترتيب المرحلة في مسار العمل.'); ?></span></div>
            <div><input class="md-input" type="number" step="0.01" min="0" name="default_stage_cost" value="<?php echo app_h((string)$stageForm['default_stage_cost']); ?>" placeholder="تكلفة مرحلة افتراضية"><span class="md-help"><?php echo app_h($isEnglish ? 'Optional default cost for this stage when adding service lines.' : 'تكلفة افتراضية اختيارية للمرحلة يمكن استخدامها عند تسجيل التكاليف.'); ?></span></div>
            <div><select class="md-select" name="is_terminal"><option value="0" <?php echo (int)$stageForm['is_terminal'] === 0 ? 'selected' : ''; ?>>مرحلة عادية</option><option value="1" <?php echo (int)$stageForm['is_terminal'] === 1 ? 'selected' : ''; ?>>مرحلة نهائية</option></select><span class="md-help"><?php echo app_h($isEnglish ? 'Terminal stages represent end/archive points.' : 'المرحلة النهائية تعني انتهاء المسار (أرشيف/إغلاق).'); ?></span></div>
            <div style="grid-column:1/-1;">
                <label><?php echo app_h($isEnglish ? 'Communication actions in this stage' : 'إجراءات التواصل داخل هذه المرحلة'); ?></label>
                <div class="md-check-grid">
                    <?php foreach ($stageActionDefs as $actionKey => $actionDef): ?>
                        <label class="md-check-item">
                            <input type="checkbox" name="stage_actions[]" value="<?php echo app_h($actionKey); ?>" <?php echo in_array($actionKey, (array)$stageForm['stage_actions'], true) ? 'checked' : ''; ?>>
                            <span><?php echo app_h((string)($isEnglish ? $actionDef['en'] : $actionDef['ar'])); ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <div style="grid-column:1/-1;">
                <label><?php echo app_h($isEnglish ? 'Required operations for this stage (comma separated)' : 'العمليات المطلوبة في هذه المرحلة (مفصولة بفواصل)'); ?></label>
                <textarea class="md-input" name="stage_required_ops_text" rows="2" id="md-stage-required-ops"><?php echo app_h((string)$stageForm['stage_required_ops_text']); ?></textarea>
                <span class="md-help"><?php echo app_h($isEnglish ? 'Tip: click any suggestion below to append it quickly.' : 'نصيحة: اضغط على أي اقتراح بالأسفل لإضافته تلقائيًا.'); ?></span>
                <div class="md-pill-wrap" id="md-stage-ops-pool"></div>
            </div>
            <div class="md-form-actions">
                <button class="md-btn" type="submit"><?php echo app_h($isStageEdit ? app_tr('حفظ التعديل', 'Save Changes') : app_t('md.btn.add_update', 'إضافة / تحديث المرحلة')); ?></button>
                <?php if ($isStageEdit): ?>
                    <a class="md-link-mini" href="<?php echo app_h(md_tab_url('stages')); ?>"><?php echo app_h(app_tr('إلغاء التعديل', 'Cancel Edit')); ?></a>
                <?php endif; ?>
            </div>
        </form>
        <div style="margin-bottom:10px; color:#a6a6a6; font-size:0.82rem;">يفضّل عدم تغيير <code>stage_key</code> للمراحل الأساسية في الموديولات الحالية، ويمكنك تعديل الاسم والترتيب أو إضافة مراحل جديدة.</div>
        <table class="md-table">
            <thead><tr><th>النوع</th><th>المفتاح</th><th>الاسم (AR)</th><th>الاسم (EN)</th><th>الأولوية</th><th>تكلفة افتراضية</th><th><?php echo app_h($isEnglish ? 'Actions' : 'إجراءات المرحلة'); ?></th><th><?php echo app_h($isEnglish ? 'Required Ops' : 'العمليات المطلوبة'); ?></th><th>نهائية</th><th>نشط</th><th>تحكم</th></tr></thead>
            <tbody>
                <?php foreach ($stageRows as $row): ?>
                    <tr>
                        <td><?php echo app_h((string)$row['type_name']); ?></td>
                        <td><?php echo app_h((string)$row['stage_key']); ?></td>
                        <td><?php echo app_h((string)($row['stage_name'] ?? '')); ?></td>
                        <td><?php echo app_h((string)($row['stage_name_en'] ?? '')); ?></td>
                        <td><?php echo (int)$row['stage_order']; ?></td>
                        <td><?php echo number_format((float)($row['default_stage_cost'] ?? 0), 2); ?></td>
                        <td>
                            <?php $rowActionLabels = md_stage_action_labels(md_normalize_stage_actions((string)($row['stage_actions_json'] ?? '[]')), $isEnglish); ?>
                            <?php if (!empty($rowActionLabels)): ?>
                                <div class="md-pill-wrap">
                                    <?php foreach ($rowActionLabels as $label): ?>
                                        <span class="md-pill"><?php echo app_h($label); ?></span>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <span class="md-small-muted">-</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php $rowRequiredOps = md_string_list((string)($row['stage_required_ops_json'] ?? '[]'), false, 120, 140); ?>
                            <?php if (!empty($rowRequiredOps)): ?>
                                <div class="md-pill-wrap">
                                    <?php foreach ($rowRequiredOps as $opLabel): ?>
                                        <span class="md-pill"><?php echo app_h($opLabel); ?></span>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <span class="md-small-muted">-</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo (int)$row['is_terminal'] === 1 ? 'نعم' : 'لا'; ?></td>
                        <td><?php echo (int)$row['is_active'] === 1 ? 'نعم' : 'لا'; ?></td>
                        <td>
                            <a class="md-link-mini" href="<?php echo app_h(md_tab_url('stages', ['edit_stage_id' => (int)$row['id']])); ?>"><?php echo app_h(app_tr('تعديل', 'Edit')); ?></a>
                            <form method="post" style="display:inline;">
                                <?php echo app_csrf_input(); ?>
                                <input type="hidden" name="tab" value="stages">
                                <input type="hidden" name="scope_type" value="<?php echo app_h($scopeType); ?>">
                                <input type="hidden" name="action" value="move_stage">
                                <input type="hidden" name="stage_id" value="<?php echo (int)$row['id']; ?>">
                                <input type="hidden" name="direction" value="up">
                                <button type="submit" class="md-btn-mini" title="<?php echo app_h(app_tr('تحريك لأعلى', 'Move Up')); ?>">↑</button>
                            </form>
                            <form method="post" style="display:inline;">
                                <?php echo app_csrf_input(); ?>
                                <input type="hidden" name="tab" value="stages">
                                <input type="hidden" name="scope_type" value="<?php echo app_h($scopeType); ?>">
                                <input type="hidden" name="action" value="move_stage">
                                <input type="hidden" name="stage_id" value="<?php echo (int)$row['id']; ?>">
                                <input type="hidden" name="direction" value="down">
                                <button type="submit" class="md-btn-mini" title="<?php echo app_h(app_tr('تحريك لأسفل', 'Move Down')); ?>">↓</button>
                            </form>
                            <form method="post" style="display:inline;">
                                <?php echo app_csrf_input(); ?>
                                <input type="hidden" name="tab" value="stages">
                                <input type="hidden" name="scope_type" value="<?php echo app_h($scopeType); ?>">
                                <input type="hidden" name="action" value="toggle_stage">
                                <input type="hidden" name="stage_id" value="<?php echo (int)$row['id']; ?>">
                                <input type="hidden" name="is_active" value="<?php echo (int)$row['is_active'] === 1 ? '0' : '1'; ?>">
                                <button type="submit" class="md-btn-danger" onclick="return confirm('<?php echo app_h((int)$row['is_active'] === 1 ? app_tr('تعطيل هذه المرحلة؟', 'Disable this stage?') : app_tr('تفعيل هذه المرحلة؟', 'Enable this stage?')); ?>')"><?php echo (int)$row['is_active'] === 1 ? app_h(app_tr('تعطيل', 'Disable')) : app_h(app_tr('تفعيل', 'Enable')); ?></button>
                            </form>
                            <form method="post" style="display:inline;">
                                <?php echo app_csrf_input(); ?>
                                <input type="hidden" name="tab" value="stages">
                                <input type="hidden" name="scope_type" value="<?php echo app_h($scopeType); ?>">
                                <input type="hidden" name="action" value="delete_stage">
                                <input type="hidden" name="stage_id" value="<?php echo (int)$row['id']; ?>">
                                <button type="submit" class="md-btn-neutral" onclick="return confirm('<?php echo app_h(app_tr('حذف المرحلة نهائياً؟', 'Delete this stage permanently?')); ?>')"><?php echo app_h(app_t('md.btn.delete', 'حذف')); ?></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <?php if ($activeTab === 'catalog'): ?>
    <div class="md-card">
        <h3 class="md-title"><?php echo app_h($txtSectionCatalog); ?></h3>
        <form method="post" class="md-grid" style="margin-bottom:14px;">
            <?php echo app_csrf_input(); ?>
            <input type="hidden" name="action" value="save_catalog_item">
            <input type="hidden" name="tab" value="catalog">
            <input type="hidden" name="scope_type" value="<?php echo app_h($scopeType); ?>">
            <input type="hidden" name="catalog_item_id" value="<?php echo (int)$catalogForm['catalog_item_id']; ?>">
            <div><select class="md-select" name="catalog_type_key" required>
                <option value="">نوع العملية</option>
                <?php foreach ($types as $type): ?><option value="<?php echo app_h((string)$type['type_key']); ?>" <?php echo (string)$catalogForm['catalog_type_key'] === (string)$type['type_key'] ? 'selected' : ''; ?>><?php echo app_h((string)$type['type_name']); ?></option><?php endforeach; ?>
            </select><span class="md-help"><?php echo app_h($isEnglish ? 'Operation type that will use this catalog item.' : 'نوع العملية الذي سيستخدم هذا العنصر.'); ?></span></div>
            <div><input class="md-input" name="catalog_group" placeholder="group مثال: material / service / paper" value="<?php echo app_h((string)$catalogForm['catalog_group']); ?>" required><span class="md-help"><?php echo app_h(app_t('md.help.group', 'اسم المجموعة (material / service / feature ...).')); ?></span></div>
            <div><input class="md-input" name="item_label" placeholder="<?php echo app_h($isEnglish ? 'Arabic item name' : 'اسم العنصر بالعربية'); ?>" value="<?php echo app_h((string)$catalogForm['item_label']); ?>" required><span class="md-help"><?php echo app_h($isEnglish ? 'Arabic label shown in Arabic UI.' : 'الاسم الظاهر للعنصر في الواجهة العربية.'); ?></span></div>
            <div><input class="md-input" name="item_label_en" placeholder="<?php echo app_h($isEnglish ? 'English item name' : 'اسم العنصر بالإنجليزية'); ?>" value="<?php echo app_h((string)$catalogForm['item_label_en']); ?>"><span class="md-help"><?php echo app_h($isEnglish ? 'English label shown in English UI.' : 'الاسم الظاهر للعنصر في الواجهة الإنجليزية.'); ?></span></div>
            <div><input class="md-input" type="number" name="item_sort_order" value="<?php echo (int)$catalogForm['item_sort_order']; ?>" placeholder="الأولوية/الترتيب"><span class="md-help"><?php echo app_h($isEnglish ? 'Lower value appears first.' : 'رقم أقل = ظهور أعلى في القائمة.'); ?></span></div>
            <div><input class="md-input" type="number" step="0.01" min="0" name="default_unit_price" value="<?php echo app_h((string)$catalogForm['default_unit_price']); ?>" placeholder="سعر/تكلفة افتراضية"><span class="md-help"><?php echo app_h($isEnglish ? 'Optional default unit price for quick costing.' : 'سعر/تكلفة وحدة افتراضية لتسهيل تسجيل التكاليف.'); ?></span></div>
            <div class="md-form-actions">
                <button class="md-btn" type="submit"><?php echo app_h($isCatalogEdit ? app_tr('حفظ التعديل', 'Save Changes') : app_t('md.btn.add_update', 'إضافة / تحديث العنصر')); ?></button>
                <?php if ($isCatalogEdit): ?>
                    <a class="md-link-mini" href="<?php echo app_h(md_tab_url('catalog')); ?>"><?php echo app_h(app_tr('إلغاء التعديل', 'Cancel Edit')); ?></a>
                <?php endif; ?>
            </div>
        </form>
        <table class="md-table">
            <thead><tr><th>النوع</th><th>المجموعة</th><th>العنصر (AR)</th><th>العنصر (EN)</th><th>الأولوية</th><th>سعر افتراضي</th><th>نشط</th><th>تحكم</th></tr></thead>
            <tbody>
                <?php foreach ($catalogRows as $row): ?>
                    <tr>
                        <td><?php echo app_h((string)$row['type_name']); ?></td>
                        <td><?php echo app_h((string)$row['catalog_group']); ?></td>
                        <td><?php echo app_h((string)$row['item_label']); ?></td>
                        <td><?php echo app_h((string)($row['item_label_en'] ?? '')); ?></td>
                        <td><?php echo (int)$row['sort_order']; ?></td>
                        <td><?php echo number_format((float)($row['default_unit_price'] ?? 0), 2); ?></td>
                        <td><?php echo (int)$row['is_active'] === 1 ? 'نعم' : 'لا'; ?></td>
                        <td>
                            <a class="md-link-mini" href="<?php echo app_h(md_tab_url('catalog', ['edit_catalog_id' => (int)$row['id']])); ?>"><?php echo app_h(app_tr('تعديل', 'Edit')); ?></a>
                            <form method="post" style="display:inline;">
                                <?php echo app_csrf_input(); ?>
                                <input type="hidden" name="tab" value="catalog">
                                <input type="hidden" name="scope_type" value="<?php echo app_h($scopeType); ?>">
                                <input type="hidden" name="action" value="move_catalog_item">
                                <input type="hidden" name="item_id" value="<?php echo (int)$row['id']; ?>">
                                <input type="hidden" name="direction" value="up">
                                <button type="submit" class="md-btn-mini" title="<?php echo app_h(app_tr('تحريك لأعلى', 'Move Up')); ?>">↑</button>
                            </form>
                            <form method="post" style="display:inline;">
                                <?php echo app_csrf_input(); ?>
                                <input type="hidden" name="tab" value="catalog">
                                <input type="hidden" name="scope_type" value="<?php echo app_h($scopeType); ?>">
                                <input type="hidden" name="action" value="move_catalog_item">
                                <input type="hidden" name="item_id" value="<?php echo (int)$row['id']; ?>">
                                <input type="hidden" name="direction" value="down">
                                <button type="submit" class="md-btn-mini" title="<?php echo app_h(app_tr('تحريك لأسفل', 'Move Down')); ?>">↓</button>
                            </form>
                            <form method="post" style="display:inline;">
                                <?php echo app_csrf_input(); ?>
                                <input type="hidden" name="tab" value="catalog">
                                <input type="hidden" name="scope_type" value="<?php echo app_h($scopeType); ?>">
                                <input type="hidden" name="action" value="toggle_catalog_item">
                                <input type="hidden" name="item_id" value="<?php echo (int)$row['id']; ?>">
                                <input type="hidden" name="is_active" value="<?php echo (int)$row['is_active'] === 1 ? '0' : '1'; ?>">
                                <button type="submit" class="md-btn-danger" onclick="return confirm('<?php echo app_h((int)$row['is_active'] === 1 ? app_tr('تعطيل هذا العنصر؟', 'Disable this item?') : app_tr('تفعيل هذا العنصر؟', 'Enable this item?')); ?>')"><?php echo (int)$row['is_active'] === 1 ? app_h(app_tr('تعطيل', 'Disable')) : app_h(app_tr('تفعيل', 'Enable')); ?></button>
                            </form>
                            <form method="post" style="display:inline;">
                                <?php echo app_csrf_input(); ?>
                                <input type="hidden" name="tab" value="catalog">
                                <input type="hidden" name="scope_type" value="<?php echo app_h($scopeType); ?>">
                                <input type="hidden" name="action" value="delete_catalog_item">
                                <input type="hidden" name="item_id" value="<?php echo (int)$row['id']; ?>">
                                <button type="submit" class="md-btn-neutral" onclick="return confirm('<?php echo app_h(app_tr('حذف العنصر نهائياً؟', 'Delete this item permanently?')); ?>')"><?php echo app_h(app_t('md.btn.delete', 'حذف')); ?></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <?php if ($activeTab === 'numbering'): ?>
    <div class="md-card">
        <h3 class="md-title"><?php echo app_h($txtSectionSeq); ?></h3>
        <form method="post" class="md-grid" style="margin-bottom:14px;">
            <?php echo app_csrf_input(); ?>
            <input type="hidden" name="action" value="save_number_rule">
            <input type="hidden" name="tab" value="numbering">
            <div><input class="md-input" name="doc_type" placeholder="نوع المستند: job / invoice / quote ..." required><span class="md-help"><?php echo app_h($isEnglish ? 'Internal document key (job, invoice, quote, purchase, payroll...).' : 'نوع المستند الداخلي مثل job أو invoice أو quote.'); ?></span></div>
            <div><input class="md-input" name="prefix" placeholder="البادئة: INV-"><span class="md-help"><?php echo app_h(app_t('md.help.prefix', 'بادئة تظهر قبل الرقم مثل INV- أو JOB-.')); ?></span></div>
            <div><input class="md-input" type="number" name="padding" value="5" placeholder="طول الرقم"><span class="md-help"><?php echo app_h($isEnglish ? 'Number length with leading zeros.' : 'طول الرقم مع الأصفار البادئة.'); ?></span></div>
            <div><input class="md-input" type="number" name="next_number" value="1" placeholder="الرقم التالي"><span class="md-help"><?php echo app_h($isEnglish ? 'Next number that will be assigned.' : 'أول رقم سيتم استخدامه في الإصدار القادم.'); ?></span></div>
            <div><select class="md-select" name="reset_policy">
                <option value="none">بدون إعادة ضبط</option>
                <option value="yearly">إعادة ضبط سنوية</option>
                <option value="monthly">إعادة ضبط شهرية</option>
            </select><span class="md-help"><?php echo app_h($isEnglish ? 'Reset sequence periodically by year or month.' : 'إعادة تعيين العداد تلقائياً سنوياً أو شهرياً.'); ?></span></div>
            <button class="md-btn" type="submit"><?php echo app_h(app_t('md.btn.save', 'حفظ قاعدة الترقيم')); ?></button>
        </form>
        <table class="md-table">
            <thead><tr><th>النوع</th><th>البادئة</th><th>الطول</th><th>الرقم التالي</th><th>سياسة الإعادة</th><th>تحكم</th></tr></thead>
            <tbody>
                <?php if ($numberRows): while ($row = $numberRows->fetch_assoc()): ?>
                    <tr>
                        <td><?php echo app_h((string)$row['doc_type']); ?></td>
                        <td><?php echo app_h((string)$row['prefix']); ?></td>
                        <td><?php echo (int)$row['padding']; ?></td>
                        <td><?php echo (int)$row['next_number']; ?></td>
                        <td><?php echo app_h((string)$row['reset_policy']); ?></td>
                        <td>
                            <form method="post" style="display:inline;">
                                <?php echo app_csrf_input(); ?>
                                <input type="hidden" name="tab" value="numbering">
                                <input type="hidden" name="action" value="delete_number_rule">
                                <input type="hidden" name="doc_type" value="<?php echo app_h((string)$row['doc_type']); ?>">
                                <button type="submit" class="md-btn-neutral" onclick="return confirm('<?php echo app_h(app_tr('حذف قاعدة الترقيم لهذا النوع؟', 'Delete numbering rule for this type?')); ?>')"><?php echo app_h(app_t('md.btn.delete', 'حذف')); ?></button>
                            </form>
                        </td>
                    </tr>
                <?php endwhile; endif; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <?php if ($activeTab === 'cloud_sync'): ?>
    <div class="md-card">
        <h3 class="md-title"><?php echo app_h($txtSectionCloudSync); ?></h3>
        <div class="md-note">
            <?php echo app_h($isEnglish
                ? 'Use this section to link cloud systems together and control secure data sync. Recommended numbering policy: Namespace.'
                : 'استخدم هذا القسم لربط الأنظمة السحابية معًا والتحكم في مزامنة البيانات بشكل آمن. سياسة الترقيم الموصى بها: Namespace.'); ?>
        </div>

        <form method="post" class="md-grid" style="margin-bottom:14px;">
            <?php echo app_csrf_input(); ?>
            <input type="hidden" name="tab" value="cloud_sync">
            <div>
                <label><?php echo app_h($isEnglish ? 'Enable Cloud Sync' : 'تفعيل المزامنة السحابية'); ?></label>
                <label class="md-check-item">
                    <input type="checkbox" name="cloud_sync_enabled" value="1" <?php echo (int)($cloudSyncSettings['enabled'] ?? 0) === 1 ? 'checked' : ''; ?>>
                    <span><?php echo app_h($isEnglish ? 'Enabled' : 'مفعلة'); ?></span>
                </label>
            </div>
            <div>
                <label><?php echo app_h($isEnglish ? 'Sync API URL' : 'رابط API للمزامنة'); ?></label>
                <input class="md-input" name="cloud_sync_remote_url" value="<?php echo app_h((string)($cloudSyncSettings['remote_url'] ?? '')); ?>" placeholder="https://work.areagles.com/api/cloud/sync">
                <span class="md-help"><?php echo app_h($isEnglish ? 'Cloud endpoint that receives sync payloads from linked systems.' : 'المسار السحابي الذي يستقبل بيانات المزامنة من الأنظمة المرتبطة.'); ?></span>
            </div>
            <div>
                <label><?php echo app_h($isEnglish ? 'Remote Bearer Token' : 'رمز توثيق الطرف السحابي'); ?></label>
                <input class="md-input" name="cloud_sync_remote_token" value="<?php echo app_h((string)($cloudSyncSettings['remote_token'] ?? '')); ?>" placeholder="<?php echo app_h($isEnglish ? 'Required for push/pull' : 'مطلوب للمزامنة'); ?>">
            </div>
            <div>
                <label><?php echo app_h($isEnglish ? 'Incoming API Token (this system)' : 'رمز API الداخل (لهذا النظام)'); ?></label>
                <input class="md-input" name="cloud_sync_api_token" value="<?php echo app_h((string)($cloudSyncSettings['api_token'] ?? '')); ?>" placeholder="<?php echo app_h($isEnglish ? 'Used by other nodes to sync to this system' : 'يُستخدم عند استقبال مزامنة من نظام آخر'); ?>">
            </div>
            <div>
                <label><?php echo app_h($isEnglish ? 'Installation Code' : 'رمز التثبيت'); ?></label>
                <input class="md-input" name="cloud_sync_installation_code" value="<?php echo app_h((string)($cloudSyncSettings['installation_code'] ?? '')); ?>" placeholder="AE-CL01">
                <span class="md-help"><?php echo app_h($isEnglish ? 'Used in numbering namespace to prevent collisions between desktop/cloud nodes.' : 'يُستخدم في Namespace الترقيم لمنع تداخل الأرقام بين المحلي والسحابي.'); ?></span>
            </div>
            <div>
                <label><?php echo app_h($isEnglish ? 'Sync Mode' : 'نمط المزامنة'); ?></label>
                <select class="md-select" name="cloud_sync_mode">
                    <?php
                    $syncModes = [
                        'off' => $isEnglish ? 'Off' : 'إيقاف',
                        'push' => $isEnglish ? 'Push only (This system -> Cloud)' : 'دفع فقط (هذا النظام -> السحابة)',
                        'pull' => $isEnglish ? 'Pull only (Cloud -> This system)' : 'سحب فقط (السحابة -> هذا النظام)',
                        'bidirectional' => $isEnglish ? 'Bidirectional' : 'اتجاهين',
                    ];
                    $selectedSyncMode = (string)($cloudSyncSettings['sync_mode'] ?? 'off');
                    foreach ($syncModes as $modeKey => $modeLabel):
                    ?>
                        <option value="<?php echo app_h($modeKey); ?>" <?php echo $selectedSyncMode === $modeKey ? 'selected' : ''; ?>><?php echo app_h($modeLabel); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label><?php echo app_h($isEnglish ? 'Numbering Policy' : 'سياسة الترقيم عند المزامنة'); ?></label>
                <select class="md-select" name="cloud_sync_numbering_policy">
                    <?php
                    $numberPolicies = [
                        'local' => $isEnglish ? 'Local only' : 'محلي فقط',
                        'namespace' => $isEnglish ? 'Namespace (recommended)' : 'Namespace (موصى به)',
                        'remote' => $isEnglish ? 'Cloud authoritative' : 'السحابة مرجعية',
                    ];
                    $selectedPolicy = (string)($cloudSyncSettings['numbering_policy'] ?? 'namespace');
                    foreach ($numberPolicies as $policyKey => $policyLabel):
                    ?>
                        <option value="<?php echo app_h($policyKey); ?>" <?php echo $selectedPolicy === $policyKey ? 'selected' : ''; ?>><?php echo app_h($policyLabel); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label><?php echo app_h($isEnglish ? 'Sync Interval (seconds)' : 'الفاصل الزمني للمزامنة (ثانية)'); ?></label>
                <input class="md-input" type="number" min="15" max="3600" name="cloud_sync_interval_seconds" value="<?php echo (int)($cloudSyncSettings['interval_seconds'] ?? 120); ?>">
            </div>
            <div>
                <label><?php echo app_h($isEnglish ? 'Local DB Label' : 'اسم قاعدة البيانات المحلية'); ?></label>
                <input class="md-input" name="cloud_sync_local_db_label" value="<?php echo app_h((string)($cloudSyncSettings['local_db_label'] ?? 'Primary Cloud Node')); ?>">
            </div>
            <div>
                <label><?php echo app_h($isEnglish ? 'Cloud DB Label' : 'اسم قاعدة البيانات السحابية'); ?></label>
                <input class="md-input" name="cloud_sync_remote_db_label" value="<?php echo app_h((string)($cloudSyncSettings['remote_db_label'] ?? 'Cloud DB')); ?>">
            </div>
            <div>
                <label><?php echo app_h($isEnglish ? 'Sync on Internet Return' : 'مزامنة تلقائية عند رجوع الإنترنت'); ?></label>
                <label class="md-check-item">
                    <input type="checkbox" name="cloud_sync_auto_online" value="1" <?php echo (int)($cloudSyncSettings['auto_online'] ?? 1) === 1 ? 'checked' : ''; ?>>
                    <span><?php echo app_h($isEnglish ? 'Enabled' : 'مفعلة'); ?></span>
                </label>
            </div>
            <div>
                <label><?php echo app_h($isEnglish ? 'Financial Integrity Check' : 'فحص سلامة العمليات الحسابية'); ?></label>
                <label class="md-check-item">
                    <input type="checkbox" name="cloud_sync_verify_financial" value="1" <?php echo (int)($cloudSyncSettings['verify_financial'] ?? 1) === 1 ? 'checked' : ''; ?>>
                    <span><?php echo app_h($isEnglish ? 'Enabled' : 'مفعلة'); ?></span>
                </label>
            </div>
            <div class="md-form-actions" style="grid-column:1/-1;">
                <button class="md-btn" type="submit" name="action" value="save_cloud_sync_settings"><?php echo app_h($isEnglish ? 'Save Sync Settings' : 'حفظ إعدادات المزامنة'); ?></button>
                <button class="md-btn-neutral" type="submit" name="action" value="run_cloud_sync_now"><?php echo app_h($isEnglish ? 'Run Sync Now' : 'تشغيل المزامنة الآن'); ?></button>
            </div>
        </form>
    </div>

    <div class="md-card">
        <h3 class="md-title"><?php echo app_h($isEnglish ? 'Current Sync Status' : 'الحالة الحالية للمزامنة'); ?></h3>
        <div class="md-grid">
            <div>
                <label><?php echo app_h($isEnglish ? 'Last Sync Attempt' : 'آخر محاولة مزامنة'); ?></label>
                <div class="md-note"><?php echo app_h((string)($cloudSyncSettings['last_sync_at'] ?: '-')); ?></div>
            </div>
            <div>
                <label><?php echo app_h($isEnglish ? 'Last Successful Sync' : 'آخر مزامنة ناجحة'); ?></label>
                <div class="md-note"><?php echo app_h((string)($cloudSyncSettings['last_success_at'] ?: '-')); ?></div>
            </div>
            <div>
                <label><?php echo app_h($isEnglish ? 'Last Error' : 'آخر خطأ'); ?></label>
                <div class="md-note"><?php echo app_h((string)($cloudSyncSettings['last_error'] ?: '-')); ?></div>
            </div>
            <div>
                <label><?php echo app_h($isEnglish ? 'Integrity Hash' : 'بصمة السلامة'); ?></label>
                <div class="md-note" style="word-break:break-all;"><?php echo app_h((string)($cloudSyncIntegrity['hash'] ?? '-')); ?></div>
            </div>
        </div>
        <div class="md-note">
            <?php echo app_h($isEnglish ? 'Financial integrity status: ' : 'حالة سلامة العمليات الحسابية: '); ?>
            <strong><?php echo app_h((string)($cloudSyncIntegrity['status'] ?? 'unknown')); ?></strong>
            <?php if (!empty($cloudSyncIntegrity['issues']) && is_array($cloudSyncIntegrity['issues'])): ?>
                <br>
                <?php echo app_h($isEnglish ? 'Detected issues: ' : 'المشكلات المكتشفة: '); ?>
                <code><?php echo app_h(implode(' | ', array_map('strval', $cloudSyncIntegrity['issues']))); ?></code>
            <?php endif; ?>
        </div>
    </div>

    <div class="md-card">
        <h3 class="md-title"><?php echo app_h($isEnglish ? 'Numbering Preview Under Sync Policy' : 'معاينة الترقيم حسب سياسة المزامنة'); ?></h3>
        <table class="md-table">
            <thead>
                <tr>
                    <th><?php echo app_h($isEnglish ? 'Doc Type' : 'نوع المستند'); ?></th>
                    <th><?php echo app_h($isEnglish ? 'Local Sequence' : 'الرقم المحلي'); ?></th>
                    <th><?php echo app_h($isEnglish ? 'After Policy' : 'بعد تطبيق السياسة'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($cloudSyncSequences as $docType => $seqRow): ?>
                    <?php
                    $localNumber = (string)($seqRow['prefix'] ?? '') . str_pad((string)((int)($seqRow['next_number'] ?? 1)), max(1, (int)($seqRow['padding'] ?? 5)), '0', STR_PAD_LEFT);
                    $policyNumber = app_cloud_sync_apply_numbering_policy($conn, $localNumber, (string)$docType);
                    ?>
                    <tr>
                        <td><?php echo app_h((string)$docType); ?></td>
                        <td><code><?php echo app_h($localNumber); ?></code></td>
                        <td><code><?php echo app_h($policyNumber); ?></code></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($cloudSyncSequences)): ?>
                    <tr><td colspan="3"><?php echo app_h($isEnglish ? 'No numbering rules found.' : 'لا توجد قواعد ترقيم حالياً.'); ?></td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="md-card">
        <h3 class="md-title"><?php echo app_h($isEnglish ? 'Sync Runtime Log' : 'سجل تشغيل المزامنة'); ?></h3>
        <table class="md-table">
            <thead>
                <tr>
                    <th><?php echo app_h($isEnglish ? 'Time' : 'الوقت'); ?></th>
                    <th><?php echo app_h($isEnglish ? 'Direction' : 'الاتجاه'); ?></th>
                    <th><?php echo app_h($isEnglish ? 'Status' : 'الحالة'); ?></th>
                    <th><?php echo app_h($isEnglish ? 'Installation' : 'التثبيت'); ?></th>
                    <th><?php echo app_h($isEnglish ? 'Domain' : 'الدومين'); ?></th>
                    <th><?php echo app_h($isEnglish ? 'Mode/Policy' : 'النمط/السياسة'); ?></th>
                    <th><?php echo app_h($isEnglish ? 'Details' : 'تفاصيل'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($cloudSyncLogs as $logRow): ?>
                    <tr>
                        <td><?php echo app_h((string)($logRow['created_at'] ?? '')); ?></td>
                        <td><?php echo app_h((string)($logRow['direction'] ?? '')); ?></td>
                        <td><?php echo app_h((string)($logRow['status'] ?? '')); ?></td>
                        <td><?php echo app_h((string)($logRow['installation_code'] ?? '')); ?></td>
                        <td><?php echo app_h((string)($logRow['source_domain'] ?? '')); ?></td>
                        <td><?php echo app_h((string)($logRow['sync_mode'] ?? '')); ?> / <?php echo app_h((string)($logRow['numbering_policy'] ?? '')); ?></td>
                        <td style="max-width:260px;word-break:break-word;"><?php echo app_h((string)($logRow['details'] ?? '')); ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($cloudSyncLogs)): ?>
                    <tr><td colspan="7"><?php echo app_h($isEnglish ? 'No sync runtime records yet.' : 'لا يوجد سجل مزامنة حتى الآن.'); ?></td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <?php if ($activeTab === 'pricing'): ?>
    <div class="md-card">
        <h3 class="md-title"><?php echo app_h($isEnglish ? 'Print Pricing Module' : 'موديول تسعير الطباعة'); ?></h3>
        <div class="md-note">
            <?php echo app_h($isEnglish
                ? 'Configure paper, machines, and finishing operations. The calculator will use these defaults to produce a single line item in Quotes.'
                : 'اضبط خامات الورق والماكينات والعمليات التكميلية. شاشة التسعير تستخدم هذه القيم لإنشاء بند واحد داخل عرض السعر.'); ?>
        </div>
        <div id="pricing-save-status" class="md-alert" style="display:none;"></div>
        <form method="post" action="<?php echo app_h(md_tab_url('pricing')); ?>" class="md-grid" id="pricing-settings-form" style="margin-bottom:14px;">
            <?php echo app_csrf_input(); ?>
            <input type="hidden" name="tab" value="pricing">
            <input type="hidden" name="action" value="save_pricing_settings">
            <input type="hidden" name="pricing_papers_payload" id="pricing-papers-payload" value="">
            <input type="hidden" name="pricing_machines_payload" id="pricing-machines-payload" value="">
            <input type="hidden" name="pricing_finish_payload" id="pricing-finish-payload" value="">
            <div>
                <label><?php echo app_h($isEnglish ? 'Enable pricing module' : 'تفعيل موديول التسعير'); ?></label>
                <label class="md-check-item">
                    <input type="checkbox" name="pricing_enabled" value="1" <?php echo $pricingEnabled ? 'checked' : ''; ?>>
                    <span><?php echo app_h($isEnglish ? 'Enabled' : 'مفعل'); ?></span>
                </label>
            </div>
            <div>
                <label><?php echo app_h($isEnglish ? 'Waste %' : 'نسبة الهالك %'); ?></label>
                <input class="md-input" type="number" min="0" max="100" step="0.01" name="pricing_waste_percent" value="<?php echo app_h((string)($pricingDefaults['waste_percent'] ?? 0)); ?>">
            </div>
            <div>
                <label><?php echo app_h($isEnglish ? 'Extra waste sheets' : 'هالك ثابت بالأفراخ'); ?></label>
                <input class="md-input" type="number" min="0" step="1" name="pricing_waste_sheets" value="<?php echo app_h((string)($pricingDefaults['waste_sheets'] ?? 0)); ?>">
            </div>
            <div>
                <label><?php echo app_h($isEnglish ? 'Profit %' : 'نسبة الربح %'); ?></label>
                <input class="md-input" type="number" min="0" max="1000" step="0.01" name="pricing_profit_percent" value="<?php echo app_h((string)($pricingDefaults['profit_percent'] ?? 15)); ?>">
            </div>
            <div>
                <label><?php echo app_h($isEnglish ? 'Misc cost (fixed)' : 'النثريات (ثابت)'); ?></label>
                <input class="md-input" type="number" min="0" step="0.01" name="pricing_misc_cost" value="<?php echo app_h((string)($pricingDefaults['misc_cost'] ?? 0)); ?>">
            </div>
            <div>
                <label><?php echo app_h($isEnglish ? 'Setup fee (per job)' : 'مصاريف تجهيز ثابتة'); ?></label>
                <input class="md-input" type="number" min="0" step="0.01" name="pricing_setup_fee" value="<?php echo app_h((string)($pricingDefaults['setup_fee'] ?? 0)); ?>">
            </div>
            <div>
                <label><?php echo app_h($isEnglish ? 'Default gather/fold cost per signature' : 'تجميع/طي افتراضي لكل ملزمة'); ?></label>
                <input class="md-input" type="number" min="0" step="0.01" name="pricing_gather_cost_per_signature" value="<?php echo app_h((string)($pricingDefaults['gather_cost_per_signature'] ?? 0)); ?>">
            </div>
            <div>
                <label><?php echo app_h($isEnglish ? 'Operational risk %' : 'هامش مخاطر التشغيل %'); ?></label>
                <input class="md-input" type="number" min="0" step="0.01" name="pricing_risk_percent" value="<?php echo app_h((string)($pricingDefaults['risk_percent'] ?? 0)); ?>">
            </div>
            <div>
                <label><?php echo app_h($isEnglish ? 'Reject / spoilage %' : 'رفض/فاقد تشغيل %'); ?></label>
                <input class="md-input" type="number" min="0" step="0.01" name="pricing_reject_percent" value="<?php echo app_h((string)($pricingDefaults['reject_percent'] ?? 0)); ?>">
            </div>
            <div>
                <label><?php echo app_h($isEnglish ? 'Default color test cost' : 'تكلفة اختبار لون افتراضية'); ?></label>
                <input class="md-input" type="number" min="0" step="0.01" name="pricing_color_test_cost" value="<?php echo app_h((string)($pricingDefaults['color_test_cost'] ?? 0)); ?>">
            </div>
            <div>
                <label><?php echo app_h($isEnglish ? 'Internal transport cost' : 'تكلفة نقل داخلي افتراضية'); ?></label>
                <input class="md-input" type="number" min="0" step="0.01" name="pricing_internal_transport_cost" value="<?php echo app_h((string)($pricingDefaults['internal_transport_cost'] ?? 0)); ?>">
            </div>
            <div>
                <label><?php echo app_h($isEnglish ? 'Enable book/magazine mode' : 'تفعيل تسعير الكتب/المجلات'); ?></label>
                <label class="md-check-item">
                    <input type="checkbox" name="pricing_book_enabled" value="1" <?php echo !empty($pricingDefaults['book_mode_enabled']) ? 'checked' : ''; ?>>
                    <span><?php echo app_h($isEnglish ? 'Enabled' : 'مفعل'); ?></span>
                </label>
            </div>
            <div style="grid-column:1/-1;">
                <label><?php echo app_h($isEnglish ? 'Default Binding Costs' : 'ثوابت التقفيل الافتراضية'); ?></label>
                <div class="md-note"><?php echo app_h($isEnglish ? 'These values are auto-filled in books/magazines pricing and can still be edited per job.' : 'هذه القيم تُسحب تلقائياً في تسعير الكتب/المجلات مع إمكانية تعديلها داخل العملية نفسها.'); ?></div>
                <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(180px,1fr)); gap:12px; margin-top:10px;">
                    <div>
                        <label><?php echo app_h($isEnglish ? 'Cut' : 'بشر'); ?></label>
                        <input class="md-input" type="number" min="0" step="0.01" name="pricing_binding_cut" value="<?php echo app_h((string)($pricingBindingCosts['cut'] ?? 0)); ?>">
                    </div>
                    <div>
                        <label><?php echo app_h($isEnglish ? 'Thread' : 'خيط'); ?></label>
                        <input class="md-input" type="number" min="0" step="0.01" name="pricing_binding_thread" value="<?php echo app_h((string)($pricingBindingCosts['thread'] ?? 0)); ?>">
                    </div>
                    <div>
                        <label><?php echo app_h($isEnglish ? 'Cut + Thread' : 'بشر وخيط'); ?></label>
                        <input class="md-input" type="number" min="0" step="0.01" name="pricing_binding_cut_thread" value="<?php echo app_h((string)($pricingBindingCosts['cut_thread'] ?? 0)); ?>">
                    </div>
                    <div>
                        <label><?php echo app_h($isEnglish ? 'Staple' : 'دبوس'); ?></label>
                        <input class="md-input" type="number" min="0" step="0.01" name="pricing_binding_staple" value="<?php echo app_h((string)($pricingBindingCosts['staple'] ?? 0)); ?>">
                    </div>
                    <div>
                        <label><?php echo app_h($isEnglish ? 'Staple + Cut' : 'دبوس وبشر'); ?></label>
                        <input class="md-input" type="number" min="0" step="0.01" name="pricing_binding_staple_cut" value="<?php echo app_h((string)($pricingBindingCosts['staple_cut'] ?? 0)); ?>">
                    </div>
                </div>
            </div>
            <div style="grid-column:1/-1;">
                <label><?php echo app_h($isEnglish ? 'Paper types (one per line)' : 'أنواع الورق (سطر لكل نوع)'); ?></label>
                <div class="md-note"><?php echo app_h($isEnglish ? 'Keep only paper name and ton price here. Sheet size and GSM are entered during pricing.' : 'احتفظ هنا باسم الورق وسعر الطن فقط. المقاس والجراماج يتم إدخالهما أثناء التسعير.'); ?></div>
                <div style="overflow:auto; margin-top:8px;">
                    <table class="md-table" id="pricing-paper-table">
                        <thead>
                            <tr>
                                <th><?php echo app_h($isEnglish ? 'Paper' : 'الورق'); ?></th>
                                <th><?php echo app_h($isEnglish ? 'Ton Price' : 'سعر الطن'); ?></th>
                                <th style="width:90px;"><?php echo app_h($isEnglish ? 'Remove' : 'حذف'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $paperUiRows = !empty($pricingPaperRows) ? $pricingPaperRows : [['name' => '', 'price_ton' => '']]; ?>
                            <?php foreach ($paperUiRows as $idx => $row): ?>
                                <tr>
                                    <td><input class="md-input" name="pricing_papers[<?php echo (int)$idx; ?>][name]" value="<?php echo app_h((string)($row['name'] ?? '')); ?>" placeholder="<?php echo app_h($isEnglish ? 'Paper name' : 'اسم الورق'); ?>"></td>
                                    <td><input class="md-input" type="number" step="0.01" name="pricing_papers[<?php echo (int)$idx; ?>][price_ton]" value="<?php echo app_h((string)($row['price_ton'] ?? '')); ?>" placeholder="<?php echo app_h($isEnglish ? 'Ton price' : 'سعر الطن'); ?>"></td>
                                    <td>
                                        <div class="md-inline-actions" style="display:flex; flex-wrap:wrap; gap:6px;">
                                            <button type="button" class="md-btn-neutral js-row-copy"><?php echo app_h($isEnglish ? 'Copy' : 'نسخ'); ?></button>
                                            <button type="button" class="md-btn-neutral js-row-up"><?php echo app_h($isEnglish ? 'Up' : 'أعلى'); ?></button>
                                            <button type="button" class="md-btn-neutral js-row-down"><?php echo app_h($isEnglish ? 'Down' : 'أسفل'); ?></button>
                                            <button type="button" class="md-btn-danger js-row-remove"><?php echo app_h($isEnglish ? 'Delete' : 'حذف'); ?></button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="md-form-actions" style="margin-top:10px;">
                    <button type="button" class="md-btn-neutral js-add-pricing-row" data-target="pricing-paper-table" data-kind="paper"><?php echo app_h($isEnglish ? 'Add paper' : 'إضافة ورق'); ?></button>
                </div>
            </div>
            <div style="grid-column:1/-1;">
                <label><?php echo app_h($isEnglish ? 'Printing machines (one per line)' : 'ماكينات الطباعة (سطر لكل ماكينة)'); ?></label>
                <div class="md-note"><?php echo app_h($isEnglish ? 'Tray means 1000 printed sheets on any machine. Save machine name, translated name, tray price, minimum trays, default plate cost, and sheet class (quarter / half / full).' : 'التراج هنا يساوي 1000 شيت مطبوع على أي ماكينة. احفظ اسم الماكينة، ترجمة الاسم، سعر التراج، الحد الأدنى للتراجات، سعر الزنك الافتراضي، وفئة الشيت (ربع / نصف / فرخ كامل).'); ?></div>
                <div style="overflow:auto; margin-top:8px;">
                    <table class="md-table" id="pricing-machine-table">
                        <thead>
                            <tr>
                                <th><?php echo app_h($isEnglish ? 'Machine Name' : 'اسم الماكينة'); ?></th>
                                <th><?php echo app_h($isEnglish ? 'Translated Name' : 'ترجمة الاسم'); ?></th>
                                <th><?php echo app_h($isEnglish ? 'Tray Price' : 'سعر التراج'); ?></th>
                                <th><?php echo app_h($isEnglish ? 'Minimum Trays' : 'الحد الأدنى للتراجات'); ?></th>
                                <th><?php echo app_h($isEnglish ? 'Plate Cost' : 'سعر الزنك'); ?></th>
                                <th><?php echo app_h($isEnglish ? 'Sheet Class' : 'فئة الشيت'); ?></th>
                                <th style="width:240px;"><?php echo app_h($isEnglish ? 'Actions' : 'إجراءات'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $machineUiRows = !empty($pricingMachineRows) ? $pricingMachineRows : [[
                                'key' => '', 'label_ar' => '', 'label_en' => '', 'price_per_tray' => '', 'min_trays' => '1', 'plate_cost' => '', 'sheet_class' => 'full'
                            ]]; ?>
                            <?php foreach ($machineUiRows as $idx => $row): ?>
                                <tr>
                                    <td>
                                        <input class="md-input" name="pricing_machines_rows[<?php echo (int)$idx; ?>][label_ar]" value="<?php echo app_h((string)($row['label_ar'] ?? '')); ?>" placeholder="<?php echo app_h($isEnglish ? 'Machine name' : 'اسم الماكينة'); ?>">
                                        <input type="hidden" name="pricing_machines_rows[<?php echo (int)$idx; ?>][key]" value="<?php echo app_h((string)($row['key'] ?? '')); ?>">
                                    </td>
                                    <td><input class="md-input" name="pricing_machines_rows[<?php echo (int)$idx; ?>][label_en]" value="<?php echo app_h((string)($row['label_en'] ?? '')); ?>" placeholder="<?php echo app_h($isEnglish ? 'Translated name' : 'ترجمة الاسم'); ?>"></td>
                                    <td><input class="md-input" type="number" min="0" step="0.01" name="pricing_machines_rows[<?php echo (int)$idx; ?>][price_per_tray]" value="<?php echo app_h((string)($row['price_per_tray'] ?? '')); ?>" placeholder="<?php echo app_h($isEnglish ? 'Tray price' : 'سعر التراج'); ?>"></td>
                                    <td><input class="md-input" type="number" min="1" step="1" name="pricing_machines_rows[<?php echo (int)$idx; ?>][min_trays]" value="<?php echo app_h((string)($row['min_trays'] ?? '1')); ?>" placeholder="<?php echo app_h($isEnglish ? 'Minimum trays' : 'الحد الأدنى للتراجات'); ?>"></td>
                                    <td><input class="md-input" type="number" min="0" step="0.01" name="pricing_machines_rows[<?php echo (int)$idx; ?>][plate_cost]" value="<?php echo app_h((string)($row['plate_cost'] ?? '')); ?>" placeholder="<?php echo app_h($isEnglish ? 'Plate cost' : 'سعر الزنك'); ?>"></td>
                                    <td>
                                        <select class="md-input" name="pricing_machines_rows[<?php echo (int)$idx; ?>][sheet_class]">
                                            <?php $sheetClassValue = (string)($row['sheet_class'] ?? 'full'); ?>
                                            <option value="quarter" <?php echo $sheetClassValue === 'quarter' ? 'selected' : ''; ?>><?php echo app_h($isEnglish ? 'Quarter' : 'ربع فرخ'); ?></option>
                                            <option value="half" <?php echo $sheetClassValue === 'half' ? 'selected' : ''; ?>><?php echo app_h($isEnglish ? 'Half' : 'نصف فرخ'); ?></option>
                                            <option value="full" <?php echo $sheetClassValue === 'full' ? 'selected' : ''; ?>><?php echo app_h($isEnglish ? 'Full' : 'فرخ كامل'); ?></option>
                                        </select>
                                    </td>
                                    <td>
                                        <div class="md-inline-actions" style="display:flex; flex-wrap:wrap; gap:6px;">
                                            <button type="button" class="md-btn-neutral js-row-copy"><?php echo app_h($isEnglish ? 'Copy' : 'نسخ'); ?></button>
                                            <button type="button" class="md-btn-neutral js-row-up"><?php echo app_h($isEnglish ? 'Up' : 'أعلى'); ?></button>
                                            <button type="button" class="md-btn-neutral js-row-down"><?php echo app_h($isEnglish ? 'Down' : 'أسفل'); ?></button>
                                            <button type="button" class="md-btn-danger js-row-remove"><?php echo app_h($isEnglish ? 'Delete' : 'حذف'); ?></button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="md-form-actions" style="margin-top:10px; gap:8px; flex-wrap:wrap;">
                    <button type="button" class="md-btn-neutral js-add-pricing-row" data-target="pricing-machine-table" data-kind="machine"><?php echo app_h($isEnglish ? 'Add machine' : 'إضافة ماكينة'); ?></button>
                    <button type="button" class="md-btn-neutral js-add-machine-preset" data-sheet-class="quarter"><?php echo app_h($isEnglish ? 'Quick add: Quarter' : 'إضافة سريعة: ربع'); ?></button>
                    <button type="button" class="md-btn-neutral js-add-machine-preset" data-sheet-class="half"><?php echo app_h($isEnglish ? 'Quick add: Half' : 'إضافة سريعة: نصف'); ?></button>
                    <button type="button" class="md-btn-neutral js-add-machine-preset" data-sheet-class="full"><?php echo app_h($isEnglish ? 'Quick add: Full' : 'إضافة سريعة: فرخ كامل'); ?></button>
                </div>
            </div>
            <div style="grid-column:1/-1;">
                <label><?php echo app_h($isEnglish ? 'Finishing operations (one per line)' : 'العمليات التكميلية (سطر لكل عملية)'); ?></label>
                <div class="md-note"><?php echo app_h($isEnglish ? 'Manage finishing operations here with per-finished-piece, per-sheet, and per-tray pricing. For lamination, spot UV, and similar sheet-based finishes, enable "Sheet-sensitive" so the system scales the cost by machine size (quarter / half / full).' : 'أدر العمليات التكميلية هنا مع تسعير بالقطعة النهائية أو بالشيت/الفرخ أو بالتراج حسب الحاجة. في السلفان والأسبوت يوفي وما شابه من فنيات تعتمد على الشيت، فعّل خيار "حسب حجم الشيت" ليقوم النظام بضبط التكلفة تلقائياً حسب فئة الماكينة (ربع / نصف / فرخ كامل).'); ?></div>
                <div style="overflow:auto; margin-top:8px;">
                    <table class="md-table" id="pricing-finish-table">
                        <thead>
                            <tr>
                                <th><?php echo app_h($isEnglish ? 'Key' : 'المفتاح'); ?></th>
                                <th><?php echo app_h($isEnglish ? 'Arabic' : 'الاسم عربي'); ?></th>
                                <th><?php echo app_h($isEnglish ? 'English' : 'الاسم إنجليزي'); ?></th>
                                <th><?php echo app_h($isEnglish ? 'Per Piece' : 'سعر/قطعة'); ?></th>
                                <th><?php echo app_h($isEnglish ? 'Per Tray' : 'سعر/تراج'); ?></th>
                                <th><?php echo app_h($isEnglish ? 'Faces 0/1' : 'وجهين 0/1'); ?></th>
                                <th><?php echo app_h($isEnglish ? 'Default Unit' : 'الوحدة'); ?></th>
                                <th><?php echo app_h($isEnglish ? 'Sheet-sensitive' : 'حسب حجم الشيت'); ?></th>
                                <th style="width:90px;"><?php echo app_h($isEnglish ? 'Remove' : 'حذف'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $finishUiRows = !empty($pricingFinishRows) ? $pricingFinishRows : [[
                                'key' => '', 'label_ar' => '', 'label_en' => '', 'price_piece' => '', 'price_tray' => '',
                                'allow_faces' => '0', 'default_unit' => 'piece', 'sheet_sensitive' => '0'
                            ]]; ?>
                            <?php foreach ($finishUiRows as $idx => $row): ?>
                                <tr>
                                    <td><input class="md-input" name="pricing_finish_rows[<?php echo (int)$idx; ?>][key]" value="<?php echo app_h((string)($row['key'] ?? '')); ?>"></td>
                                    <td><input class="md-input" name="pricing_finish_rows[<?php echo (int)$idx; ?>][label_ar]" value="<?php echo app_h((string)($row['label_ar'] ?? '')); ?>"></td>
                                    <td><input class="md-input" name="pricing_finish_rows[<?php echo (int)$idx; ?>][label_en]" value="<?php echo app_h((string)($row['label_en'] ?? '')); ?>"></td>
                                    <td><input class="md-input" type="number" step="0.01" name="pricing_finish_rows[<?php echo (int)$idx; ?>][price_piece]" value="<?php echo app_h((string)($row['price_piece'] ?? '')); ?>"></td>
                                    <td><input class="md-input" type="number" step="0.01" name="pricing_finish_rows[<?php echo (int)$idx; ?>][price_tray]" value="<?php echo app_h((string)($row['price_tray'] ?? '')); ?>"></td>
                                    <td><input class="md-input" type="number" step="1" name="pricing_finish_rows[<?php echo (int)$idx; ?>][allow_faces]" value="<?php echo app_h((string)($row['allow_faces'] ?? '0')); ?>"></td>
                                    <td>
                                        <select class="md-input" name="pricing_finish_rows[<?php echo (int)$idx; ?>][default_unit]">
                                            <?php $finishUnitValue = md_pricing_normalize_finish_unit((string)($row['default_unit'] ?? 'piece')); ?>
                                            <option value="piece" <?php echo $finishUnitValue === 'piece' ? 'selected' : ''; ?>><?php echo app_h($isEnglish ? 'Per finished piece' : 'بالقطعة النهائية'); ?></option>
                                            <option value="sheet" <?php echo $finishUnitValue === 'sheet' ? 'selected' : ''; ?>><?php echo app_h($isEnglish ? 'Per sheet' : 'بالشيت/الفرخ'); ?></option>
                                            <option value="tray" <?php echo $finishUnitValue === 'tray' ? 'selected' : ''; ?>><?php echo app_h($isEnglish ? 'Per tray' : 'بالتراج'); ?></option>
                                        </select>
                                    </td>
                                    <td>
                                        <label class="md-check" style="justify-content:center;">
                                            <input type="hidden" name="pricing_finish_rows[<?php echo (int)$idx; ?>][sheet_sensitive]" value="0">
                                            <input type="checkbox" name="pricing_finish_rows[<?php echo (int)$idx; ?>][sheet_sensitive]" value="1" <?php echo !empty($row['sheet_sensitive']) ? 'checked' : ''; ?>>
                                        </label>
                                    </td>
                                    <td>
                                        <div class="md-inline-actions" style="display:flex; flex-wrap:wrap; gap:6px;">
                                            <button type="button" class="md-btn-neutral js-row-copy"><?php echo app_h($isEnglish ? 'Copy' : 'نسخ'); ?></button>
                                            <button type="button" class="md-btn-neutral js-row-up"><?php echo app_h($isEnglish ? 'Up' : 'أعلى'); ?></button>
                                            <button type="button" class="md-btn-neutral js-row-down"><?php echo app_h($isEnglish ? 'Down' : 'أسفل'); ?></button>
                                            <button type="button" class="md-btn-danger js-row-remove"><?php echo app_h($isEnglish ? 'Delete' : 'حذف'); ?></button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="md-form-actions" style="margin-top:10px;">
                    <button type="button" class="md-btn-neutral js-add-pricing-row" data-target="pricing-finish-table" data-kind="finish"><?php echo app_h($isEnglish ? 'Add operation' : 'إضافة عملية'); ?></button>
                    <button type="button" class="md-btn-neutral js-add-finish-preset" data-preset="lamination"><?php echo app_h($isEnglish ? 'Preset: Lamination' : 'قالب: سلفان'); ?></button>
                    <button type="button" class="md-btn-neutral js-add-finish-preset" data-preset="spot_uv"><?php echo app_h($isEnglish ? 'Preset: Spot UV' : 'قالب: أسبوت UV'); ?></button>
                    <button type="button" class="md-btn-neutral js-add-finish-preset" data-preset="uv"><?php echo app_h($isEnglish ? 'Preset: UV' : 'قالب: UV'); ?></button>
                    <button type="button" class="md-btn-neutral js-add-finish-preset" data-preset="varnish"><?php echo app_h($isEnglish ? 'Preset: Varnish' : 'قالب: ورنيش'); ?></button>
                    <button type="button" class="md-btn-neutral js-add-finish-preset" data-preset="staple"><?php echo app_h($isEnglish ? 'Preset: Staple' : 'قالب: دبوس'); ?></button>
                    <button type="button" class="md-btn-neutral js-add-finish-preset" data-preset="creasing"><?php echo app_h($isEnglish ? 'Preset: Creasing' : 'قالب: تكسير'); ?></button>
                    <button type="button" class="md-btn-neutral js-add-finish-preset" data-preset="gluing"><?php echo app_h($isEnglish ? 'Preset: Gluing' : 'قالب: لصق'); ?></button>
                </div>
            </div>
            <div class="md-form-actions" style="grid-column:1/-1;">
                <button class="md-btn" type="submit"><?php echo app_h($isEnglish ? 'Save Pricing Settings' : 'حفظ إعدادات التسعير'); ?></button>
                <a class="md-btn-neutral" href="pricing_module.php"><?php echo app_h($isEnglish ? 'Open Pricing Calculator' : 'فتح شاشة التسعير'); ?></a>
            </div>
        </form>
    </div>
    <?php endif; ?>
</div>

<script>
(function () {
    const catalogByType = <?php echo json_encode($catalogOptionsByType, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?> || {};
    const globalOpsPool = <?php echo json_encode($catalogGlobalPool, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?> || [];

    function uniqueList(values) {
        const seen = new Set();
        const out = [];
        values.forEach((value) => {
            const v = String(value || '').trim();
            if (!v || seen.has(v)) {
                return;
            }
            seen.add(v);
            out.push(v);
        });
        return out;
    }

    function splitValues(text) {
        return uniqueList(String(text || '').split(/[\n\r,;]+/));
    }

    function appendValueToTextarea(textarea, value) {
        const current = splitValues(textarea.value);
        if (!current.includes(value)) {
            current.push(value);
            textarea.value = current.join(', ');
        }
    }

    const stageTypeSelect = document.querySelector('select[name="stage_type_key"]');
    const stageOpsTextarea = document.getElementById('md-stage-required-ops');
    const stageOpsPool = document.getElementById('md-stage-ops-pool');
    if (stageTypeSelect && stageOpsTextarea && stageOpsPool) {
        const renderStageOpsPool = () => {
            const selectedType = stageTypeSelect.value || '';
            const source = uniqueList((catalogByType[selectedType] || []).concat(globalOpsPool)).slice(0, 80);
            stageOpsPool.innerHTML = '';
            source.forEach((label) => {
                const pill = document.createElement('button');
                pill.type = 'button';
                pill.className = 'md-pill clickable';
                pill.textContent = label;
                pill.addEventListener('click', () => appendValueToTextarea(stageOpsTextarea, label));
                stageOpsPool.appendChild(pill);
            });
        };
        stageTypeSelect.addEventListener('change', renderStageOpsPool);
        renderStageOpsPool();
    }

    const smartStageToggles = Array.from(document.querySelectorAll('.js-smart-stage-toggle'));
    const smartStageCards = Array.from(document.querySelectorAll('.js-smart-stage-card'));
    const refreshSmartCards = () => {
        const selected = new Set(
            smartStageToggles.filter((input) => input.checked).map((input) => input.value)
        );
        smartStageCards.forEach((card) => {
            const stageKey = card.getAttribute('data-stage-key') || '';
            const active = selected.has(stageKey);
            card.classList.toggle('hidden', !active);
            card.querySelectorAll('input, textarea, select').forEach((field) => {
                field.disabled = !active;
            });
        });
    };
    smartStageToggles.forEach((input) => input.addEventListener('change', refreshSmartCards));
    refreshSmartCards();

    smartStageCards.forEach((card) => {
        const textarea = card.querySelector('textarea[name^="smart_stage_required_ops["]');
        const pool = card.querySelector('.js-smart-ops-pool');
        if (!textarea || !pool) {
            return;
        }
        uniqueList(globalOpsPool).slice(0, 60).forEach((label) => {
            const pill = document.createElement('button');
            pill.type = 'button';
            pill.className = 'md-pill clickable';
            pill.textContent = label;
            pill.addEventListener('click', () => appendValueToTextarea(textarea, label));
            pool.appendChild(pill);
        });
    });

    const smartNameEn = document.getElementById('smart_type_name_en');
    const smartTypeKey = document.getElementById('smart_type_key');
    if (smartNameEn && smartTypeKey) {
        let manualTypeKey = false;
        smartTypeKey.addEventListener('input', () => {
            manualTypeKey = smartTypeKey.value.trim() !== '';
        });
        smartNameEn.addEventListener('input', () => {
            if (manualTypeKey) {
                return;
            }
            const generated = String(smartNameEn.value || '')
                .toLowerCase()
                .replace(/[^a-z0-9]+/g, '_')
                .replace(/^_+|_+$/g, '')
                .replace(/_+/g, '_')
                .slice(0, 50);
            smartTypeKey.value = generated;
        });
    }
    const pricingLabels = {
        deleteText: <?php echo json_encode($isEnglish ? 'Delete' : 'حذف', JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
        copyText: <?php echo json_encode($isEnglish ? 'Copy' : 'نسخ', JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
        upText: <?php echo json_encode($isEnglish ? 'Up' : 'أعلى', JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
        downText: <?php echo json_encode($isEnglish ? 'Down' : 'أسفل', JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
        paperName: <?php echo json_encode($isEnglish ? 'Paper name' : 'اسم الورق', JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
        tonPrice: <?php echo json_encode($isEnglish ? 'Ton price' : 'سعر الطن', JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
        machineName: <?php echo json_encode($isEnglish ? 'Machine name' : 'اسم الماكينة', JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
        translatedName: <?php echo json_encode($isEnglish ? 'Translated name' : 'ترجمة الاسم', JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
        trayPrice: <?php echo json_encode($isEnglish ? 'Tray price' : 'سعر التراج', JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
        minTrays: <?php echo json_encode($isEnglish ? 'Minimum trays' : 'الحد الأدنى للتراجات', JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
        plateCost: <?php echo json_encode($isEnglish ? 'Plate cost' : 'سعر الزنك', JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
        sheetClass: <?php echo json_encode($isEnglish ? 'Sheet class' : 'فئة الشيت', JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
        sheetSensitive: <?php echo json_encode($isEnglish ? 'Sheet-sensitive' : 'حسب حجم الشيت', JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
        quarterName: <?php echo json_encode($isEnglish ? 'Quarter Sheet Machine' : 'ماكينة ربع فرخ', JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
        halfName: <?php echo json_encode($isEnglish ? 'Half Sheet Machine' : 'ماكينة نصف فرخ', JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
        fullName: <?php echo json_encode($isEnglish ? 'Full Sheet Machine' : 'ماكينة فرخ كامل', JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>
    };
    const finishPresets = {
        lamination: {
            key: 'lamination',
            label_ar: 'سلفان',
            label_en: 'Lamination',
            price_piece: '',
            price_tray: '',
            allow_faces: '1',
            default_unit: 'tray',
            sheet_sensitive: '1'
        },
        spot_uv: {
            key: 'spot_uv',
            label_ar: 'أسبوت UV',
            label_en: 'Spot UV',
            price_piece: '',
            price_tray: '',
            allow_faces: '1',
            default_unit: 'tray',
            sheet_sensitive: '1'
        },
        uv: {
            key: 'uv',
            label_ar: 'UV',
            label_en: 'UV',
            price_piece: '',
            price_tray: '',
            allow_faces: '1',
            default_unit: 'tray',
            sheet_sensitive: '1'
        },
        varnish: {
            key: 'varnish',
            label_ar: 'ورنيش',
            label_en: 'Varnish',
            price_piece: '',
            price_tray: '',
            allow_faces: '1',
            default_unit: 'tray',
            sheet_sensitive: '1'
        },
        staple: {
            key: 'staple',
            label_ar: 'دبوس',
            label_en: 'Staple',
            price_piece: '',
            price_tray: '',
            allow_faces: '0',
            default_unit: 'piece',
            sheet_sensitive: '0'
        },
        creasing: {
            key: 'creasing',
            label_ar: 'تكسير',
            label_en: 'Creasing',
            price_piece: '',
            price_tray: '',
            allow_faces: '0',
            default_unit: 'piece',
            sheet_sensitive: '0'
        },
        gluing: {
            key: 'gluing',
            label_ar: 'لصق',
            label_en: 'Gluing',
            price_piece: '',
            price_tray: '',
            allow_faces: '0',
            default_unit: 'piece',
            sheet_sensitive: '0'
        }
    };

    function pricingActionsCell() {
        return `<div class="md-inline-actions">
            <button type="button" class="md-btn-neutral js-row-copy">${pricingLabels.copyText}</button>
            <button type="button" class="md-btn-neutral js-row-up">${pricingLabels.upText}</button>
            <button type="button" class="md-btn-neutral js-row-down">${pricingLabels.downText}</button>
            <button type="button" class="md-btn-danger js-row-remove">${pricingLabels.deleteText}</button>
        </div>`;
    }

    const pricingTemplates = {
        paper: `
            <tr>
                <td><input class="md-input" value="" placeholder="${pricingLabels.paperName}"></td>
                <td><input class="md-input" type="number" step="0.01" value="" placeholder="${pricingLabels.tonPrice}"></td>
                <td>${pricingActionsCell()}</td>
            </tr>`,
        machine: `
            <tr>
                <td><input class="md-input" value="" placeholder="${pricingLabels.machineName}"><input type="hidden" value=""></td>
                <td><input class="md-input" value="" placeholder="${pricingLabels.translatedName}"></td>
                <td><input class="md-input" type="number" min="0" step="0.01" value="" placeholder="${pricingLabels.trayPrice}"></td>
                <td><input class="md-input" type="number" min="1" step="1" value="1" placeholder="${pricingLabels.minTrays}"></td>
                <td><input class="md-input" type="number" min="0" step="0.01" value="" placeholder="${pricingLabels.plateCost}"></td>
                <td>
                    <select class="md-input">
                        <option value="quarter"><?php echo app_h($isEnglish ? 'Quarter' : 'ربع فرخ'); ?></option>
                        <option value="half"><?php echo app_h($isEnglish ? 'Half' : 'نصف فرخ'); ?></option>
                        <option value="full" selected><?php echo app_h($isEnglish ? 'Full' : 'فرخ كامل'); ?></option>
                    </select>
                </td>
                <td>${pricingActionsCell()}</td>
            </tr>`,
        finish: `
            <tr>
                <td><input class="md-input" value=""></td>
                <td><input class="md-input" value=""></td>
                <td><input class="md-input" value=""></td>
                <td><input class="md-input" type="number" step="0.01" value=""></td>
                <td><input class="md-input" type="number" step="0.01" value=""></td>
                <td><input class="md-input" type="number" step="1" value="0"></td>
                <td>
                    <select class="md-input">
                        <option value="piece"><?php echo app_h($isEnglish ? 'Per finished piece' : 'بالقطعة النهائية'); ?></option>
                        <option value="sheet"><?php echo app_h($isEnglish ? 'Per sheet' : 'بالشيت/الفرخ'); ?></option>
                        <option value="tray"><?php echo app_h($isEnglish ? 'Per tray' : 'بالتراج'); ?></option>
                    </select>
                </td>
                <td><label class="md-check" style="justify-content:center;"><input type="hidden" value="0"><input type="checkbox" value="1"></label></td>
                <td>${pricingActionsCell()}</td>
            </tr>`
    };

    function pricingFieldMap(kind) {
        if (kind === 'paper') return ['name', 'price_ton'];
        if (kind === 'machine') return ['label_ar', 'key', 'label_en', 'price_per_tray', 'min_trays', 'plate_cost', 'sheet_class'];
        return ['key', 'label_ar', 'label_en', 'price_piece', 'price_tray', 'allow_faces', 'default_unit', 'sheet_sensitive'];
    }

    function pricingGroupName(kind) {
        if (kind === 'paper') return 'pricing_papers';
        if (kind === 'machine') return 'pricing_machines_rows';
        return 'pricing_finish_rows';
    }

    function pricingMachineKey(labelAr, labelEn) {
        const source = String(labelEn || labelAr || '').trim();
        const ascii = source.normalize('NFKD')
            .replace(/[^\w\s-]/g, '')
            .replace(/[\s_-]+/g, '_')
            .replace(/^_+|_+$/g, '')
            .toLowerCase()
            .slice(0, 60);
        if (ascii) return ascii;
        let hash = 0;
        for (let i = 0; i < source.length; i += 1) {
            hash = ((hash << 5) - hash) + source.charCodeAt(i);
            hash |= 0;
        }
        return `machine_${Math.abs(hash).toString(36)}`;
    }

    function collectPricingPayload(table, kind) {
        const rows = Array.from(table.querySelectorAll('tbody tr'));
        if (kind === 'paper') {
            return rows.map((row) => ({
                name: (row.querySelector('td:nth-child(1) input')?.value || '').trim(),
                price_ton: row.querySelector('td:nth-child(2) input')?.value || ''
            })).filter((row) => row.name !== '');
        }
        if (kind === 'machine') {
            return rows.map((row) => {
                const labelAr = (row.querySelector('td:nth-child(1) input.md-input')?.value || '').trim();
                const labelEn = (row.querySelector('td:nth-child(2) input')?.value || '').trim();
                const keyInput = row.querySelector('td:nth-child(1) input[type=\"hidden\"]');
                const key = (keyInput?.value || '').trim() || pricingMachineKey(labelAr, labelEn);
                if (keyInput) keyInput.value = key;
                return {
                    label_ar: labelAr,
                    key,
                    label_en: labelEn,
                    price_per_tray: row.querySelector('td:nth-child(3) input')?.value || '',
                    min_trays: row.querySelector('td:nth-child(4) input')?.value || '1',
                    plate_cost: row.querySelector('td:nth-child(5) input')?.value || '',
                    sheet_class: row.querySelector('td:nth-child(6) select')?.value || 'full'
                };
            }).filter((row) => row.label_ar !== '' || row.label_en !== '');
        }
        return rows.map((row) => ({
            key: (row.querySelector('td:nth-child(1) input')?.value || '').trim(),
            label_ar: (row.querySelector('td:nth-child(2) input')?.value || '').trim(),
            label_en: (row.querySelector('td:nth-child(3) input')?.value || '').trim(),
            price_piece: row.querySelector('td:nth-child(4) input')?.value || '',
            price_tray: row.querySelector('td:nth-child(5) input')?.value || '',
            allow_faces: row.querySelector('td:nth-child(6) input')?.value || '0',
            default_unit: (row.querySelector('td:nth-child(7) select')?.value || 'piece').trim(),
            sheet_sensitive: row.querySelector('td:nth-child(8) input[type=\"checkbox\"]')?.checked ? '1' : '0'
        })).filter((row) => row.key !== '');
    }

    function refreshPricingNames(table, kind) {
        const group = pricingGroupName(kind);
        const rows = Array.from(table.querySelectorAll('tbody tr'));
        rows.forEach((row, rowIndex) => {
            if (kind === 'paper') {
                const nameInput = row.querySelector('td:nth-child(1) input');
                const priceInput = row.querySelector('td:nth-child(2) input');
                if (nameInput) nameInput.name = `${group}[${rowIndex}][name]`;
                if (priceInput) priceInput.name = `${group}[${rowIndex}][price_ton]`;
                return;
            }
            if (kind === 'machine') {
                const labelArInput = row.querySelector('td:nth-child(1) input.md-input');
                const keyInput = row.querySelector('td:nth-child(1) input[type="hidden"]');
                const labelEnInput = row.querySelector('td:nth-child(2) input');
                const priceInput = row.querySelector('td:nth-child(3) input');
                const minInput = row.querySelector('td:nth-child(4) input');
                const plateInput = row.querySelector('td:nth-child(5) input');
                const classSelect = row.querySelector('td:nth-child(6) select');
                if (labelArInput) labelArInput.name = `${group}[${rowIndex}][label_ar]`;
                if (keyInput) keyInput.name = `${group}[${rowIndex}][key]`;
                if (labelEnInput) labelEnInput.name = `${group}[${rowIndex}][label_en]`;
                if (priceInput) priceInput.name = `${group}[${rowIndex}][price_per_tray]`;
                if (minInput) minInput.name = `${group}[${rowIndex}][min_trays]`;
                if (plateInput) plateInput.name = `${group}[${rowIndex}][plate_cost]`;
                if (classSelect) classSelect.name = `${group}[${rowIndex}][sheet_class]`;
                return;
            }
            const keyInput = row.querySelector('td:nth-child(1) input');
            const labelArInput = row.querySelector('td:nth-child(2) input');
            const labelEnInput = row.querySelector('td:nth-child(3) input');
            const pieceInput = row.querySelector('td:nth-child(4) input');
            const trayInput = row.querySelector('td:nth-child(5) input');
            const facesInput = row.querySelector('td:nth-child(6) input');
            const unitInput = row.querySelector('td:nth-child(7) select');
            const sensitiveHidden = row.querySelector('td:nth-child(8) input[type="hidden"]');
            const sensitiveCheck = row.querySelector('td:nth-child(8) input[type="checkbox"]');
            if (keyInput) keyInput.name = `${group}[${rowIndex}][key]`;
            if (labelArInput) labelArInput.name = `${group}[${rowIndex}][label_ar]`;
            if (labelEnInput) labelEnInput.name = `${group}[${rowIndex}][label_en]`;
            if (pieceInput) pieceInput.name = `${group}[${rowIndex}][price_piece]`;
            if (trayInput) trayInput.name = `${group}[${rowIndex}][price_tray]`;
            if (facesInput) facesInput.name = `${group}[${rowIndex}][allow_faces]`;
            if (unitInput) unitInput.name = `${group}[${rowIndex}][default_unit]`;
            if (sensitiveHidden) sensitiveHidden.name = `${group}[${rowIndex}][sheet_sensitive]`;
            if (sensitiveCheck) sensitiveCheck.name = `${group}[${rowIndex}][sheet_sensitive]`;
        });
    }

    document.querySelectorAll('.js-add-machine-preset').forEach((button) => {
        button.addEventListener('click', () => {
            const table = document.getElementById('pricing-machine-table');
            const tbody = table ? table.querySelector('tbody') : null;
            if (!tbody) return;
            const sheetClass = button.getAttribute('data-sheet-class') || 'full';
            const nameLabel = sheetClass === 'quarter' ? pricingLabels.quarterName : (sheetClass === 'half' ? pricingLabels.halfName : pricingLabels.fullName);
            tbody.insertAdjacentHTML('beforeend', pricingTemplates.machine);
            const newRow = tbody.lastElementChild;
            if (!newRow) return;
            const controls = newRow.querySelectorAll('input, select');
            if (controls[0]) controls[0].value = nameLabel;
            if (controls[2]) controls[2].value = '';
            if (controls[3]) controls[3].value = '1';
            if (controls[4]) controls[4].value = '';
            if (controls[5]) controls[5].value = sheetClass;
            refreshPricingNames(table, 'machine');
        });
    });

    document.querySelectorAll('.js-add-finish-preset').forEach((button) => {
        button.addEventListener('click', () => {
            const table = document.getElementById('pricing-finish-table');
            const tbody = table ? table.querySelector('tbody') : null;
            const presetKey = button.getAttribute('data-preset') || '';
            const preset = finishPresets[presetKey];
            if (!tbody || !preset) return;
            tbody.insertAdjacentHTML('beforeend', pricingTemplates.finish);
            const newRow = tbody.lastElementChild;
            if (!newRow) return;
            const textInputs = newRow.querySelectorAll('input, select');
            if (textInputs[0]) textInputs[0].value = preset.key;
            if (textInputs[1]) textInputs[1].value = preset.label_ar;
            if (textInputs[2]) textInputs[2].value = preset.label_en;
            if (textInputs[3]) textInputs[3].value = preset.price_piece;
            if (textInputs[4]) textInputs[4].value = preset.price_tray;
            if (textInputs[5]) textInputs[5].value = preset.allow_faces;
            if (textInputs[6]) textInputs[6].value = preset.default_unit;
            const hidden = newRow.querySelector('input[type="hidden"]');
            const check = newRow.querySelector('input[type="checkbox"]');
            if (hidden) hidden.value = '0';
            if (check) check.checked = preset.sheet_sensitive === '1';
            refreshPricingNames(table, 'finish');
        });
    });

    document.querySelectorAll('.js-add-pricing-row').forEach((button) => {
        button.addEventListener('click', () => {
            const targetId = button.getAttribute('data-target') || '';
            const kind = button.getAttribute('data-kind') || '';
            const table = document.getElementById(targetId);
            if (!table || !pricingTemplates[kind]) return;
            const tbody = table.querySelector('tbody');
            if (!tbody) return;
            tbody.insertAdjacentHTML('beforeend', pricingTemplates[kind]);
            refreshPricingNames(table, kind);
        });
    });

    document.addEventListener('click', (event) => {
        const actionButton = event.target.closest('.js-row-remove, .js-row-copy, .js-row-up, .js-row-down');
        if (!actionButton) return;
        const row = actionButton.closest('tr');
        const table = actionButton.closest('table');
        if (!row || !table) return;
        const tbody = table.querySelector('tbody');
        if (!tbody) return;
        const kind = table.id === 'pricing-paper-table' ? 'paper' : (table.id === 'pricing-machine-table' ? 'machine' : 'finish');
        if (actionButton.classList.contains('js-row-copy')) {
            const clone = row.cloneNode(true);
            tbody.insertBefore(clone, row.nextSibling);
            refreshPricingNames(table, kind);
            return;
        }
        if (actionButton.classList.contains('js-row-up')) {
            const prev = row.previousElementSibling;
            if (prev) tbody.insertBefore(row, prev);
            refreshPricingNames(table, kind);
            return;
        }
        if (actionButton.classList.contains('js-row-down')) {
            const next = row.nextElementSibling;
            if (next) tbody.insertBefore(next, row);
            refreshPricingNames(table, kind);
            return;
        }
        if (tbody.querySelectorAll('tr').length <= 1) {
            row.querySelectorAll('input').forEach((input) => {
                if (input.type === 'hidden') {
                    input.value = '';
                } else if (input.type === 'number') {
                    input.value = input.getAttribute('min') === '1' ? '1' : '';
                } else {
                    input.value = '';
                }
            });
            row.querySelectorAll('select').forEach((select) => {
                select.selectedIndex = 0;
            });
            return;
        }
        row.remove();
        refreshPricingNames(table, kind);
    });

    [['pricing-paper-table', 'paper'], ['pricing-machine-table', 'machine'], ['pricing-finish-table', 'finish']].forEach(([id, kind]) => {
        const table = document.getElementById(id);
        if (table) {
            refreshPricingNames(table, kind);
        }
    });

    const pricingForm = document.getElementById('pricing-settings-form');
    const pricingStatus = document.getElementById('pricing-save-status');
    if (pricingForm && pricingStatus) {
        pricingForm.addEventListener('submit', () => {
            const papersPayloadInput = document.getElementById('pricing-papers-payload');
            const machinesPayloadInput = document.getElementById('pricing-machines-payload');
            const finishPayloadInput = document.getElementById('pricing-finish-payload');
            const papersTable = document.getElementById('pricing-paper-table');
            const machinesTable = document.getElementById('pricing-machine-table');
            const finishTable = document.getElementById('pricing-finish-table');
            if (papersPayloadInput && papersTable) {
                papersPayloadInput.value = JSON.stringify(collectPricingPayload(papersTable, 'paper'));
            }
            if (machinesPayloadInput && machinesTable) {
                machinesPayloadInput.value = JSON.stringify(collectPricingPayload(machinesTable, 'machine'));
            }
            if (finishPayloadInput && finishTable) {
                finishPayloadInput.value = JSON.stringify(collectPricingPayload(finishTable, 'finish'));
            }
            pricingStatus.style.display = 'block';
            pricingStatus.className = 'md-alert';
            pricingStatus.textContent = <?php echo json_encode($isEnglish ? 'Saving pricing settings...' : 'جاري حفظ إعدادات التسعير...', JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
        });
    }

    document.querySelectorAll('.md-table').forEach((table) => {
        if (table.parentElement && table.parentElement.classList.contains('md-table-wrap')) {
            return;
        }
        const wrapper = document.createElement('div');
        wrapper.className = 'md-table-wrap';
        table.parentNode.insertBefore(wrapper, table);
        wrapper.appendChild(table);
    });
})();
</script>

<?php require 'footer.php'; ?>
<?php ob_end_flush(); ?>
