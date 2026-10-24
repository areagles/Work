<?php

if (!function_exists('md_eta_catalog_post_rows')) {
    function md_eta_catalog_post_rows(array $locals, array $etas, array $types = [], array $sources = []): array
    {
        $rows = [];
        $count = max(count($locals), count($etas), count($types), count($sources));
        for ($i = 0; $i < $count; $i++) {
            $local = trim((string)($locals[$i] ?? ''));
            $eta = trim((string)($etas[$i] ?? ''));
            $type = strtoupper(trim((string)($types[$i] ?? 'EGS')));
            $source = strtolower(trim((string)($sources[$i] ?? 'manual')));
            if ($local === '' || $eta === '') {
                continue;
            }
            if (!in_array($type, ['EGS', 'GS1'], true)) {
                $type = 'EGS';
            }
            if (!in_array($source, ['manual', 'eta_sync'], true)) {
                $source = 'manual';
            }
            $rows[] = [
                'local' => $local,
                'eta' => $eta,
                'code_type' => $type,
                'source' => $source,
                'active' => 1,
            ];
        }
        return $rows;
    }
}

if (!function_exists('md_eta_unit_post_rows')) {
    function md_eta_unit_post_rows(array $locals, array $etas): array
    {
        $rows = [];
        $count = max(count($locals), count($etas));
        for ($i = 0; $i < $count; $i++) {
            $local = trim((string)($locals[$i] ?? ''));
            $eta = strtoupper(trim((string)($etas[$i] ?? '')));
            if ($local === '' || $eta === '') {
                continue;
            }
            $rows[] = [
                'local' => $local,
                'eta' => $eta,
            ];
        }
        return $rows;
    }
}

if (!function_exists('md_handle_settings_actions')) {
    function md_handle_settings_actions(mysqli $conn, string $action, array &$state): bool
    {
        if (!in_array($action, [
            'save_branding',
            'save_fx_widget_settings',
            'clear_logo',
            'save_payment_settings',
            'save_ai_settings',
            'save_eta_settings',
            'sync_eta_item_catalog',
            'save_tax_settings',
            'save_number_rule',
        ], true)) {
            return false;
        }

        $msg = &$state['msg'];

        if ($action === 'save_branding') {
            $appName = trim((string)($_POST['app_name'] ?? 'Arab Eagles'));
            $themeColor = app_normalize_hex_color((string)($_POST['theme_color'] ?? '#d4af37'));
            $uiThemePreset = trim((string)($_POST['ui_theme_preset'] ?? 'midnight_gold'));
            $outputThemePreset = trim((string)($_POST['output_theme_preset'] ?? 'midnight_gold'));
            $timezone = trim((string)($_POST['timezone'] ?? 'Africa/Cairo'));
            $appLang = app_normalize_lang((string)($_POST['app_lang'] ?? 'ar'));
            $orgName = trim((string)($_POST['org_name'] ?? $appName));
            $orgLegalName = trim((string)($_POST['org_legal_name'] ?? ''));
            $orgTaxNumber = trim((string)($_POST['org_tax_number'] ?? ''));
            $orgCommercialNumber = trim((string)($_POST['org_commercial_number'] ?? ''));
            $orgPhonePrimary = trim((string)($_POST['org_phone_primary'] ?? ''));
            $orgPhoneSecondary = trim((string)($_POST['org_phone_secondary'] ?? ''));
            $orgEmail = trim((string)($_POST['org_email'] ?? ''));
            $orgWebsite = trim((string)($_POST['org_website'] ?? ''));
            $orgAddress = trim((string)($_POST['org_address'] ?? ''));
            $orgSocialWhatsapp = trim((string)($_POST['org_social_whatsapp'] ?? ''));
            $orgSocialFacebook = trim((string)($_POST['org_social_facebook'] ?? ''));
            $orgSocialInstagram = trim((string)($_POST['org_social_instagram'] ?? ''));
            $orgSocialLinkedin = trim((string)($_POST['org_social_linkedin'] ?? ''));
            $orgSocialX = trim((string)($_POST['org_social_x'] ?? ''));
            $orgSocialYoutube = trim((string)($_POST['org_social_youtube'] ?? ''));
            $orgFooterNote = trim((string)($_POST['org_footer_note'] ?? ''));
            $showHeader = isset($_POST['output_show_header']) ? '1' : '0';
            $showFooter = isset($_POST['output_show_footer']) ? '1' : '0';
            $showLogo = isset($_POST['output_show_logo']) ? '1' : '0';
            $showQr = isset($_POST['output_show_qr']) ? '1' : '0';
            $fieldDefs = app_brand_profile_field_defs();
            $allowedVisibility = array_fill_keys(array_keys($fieldDefs), true);
            $headerDefaults = ['org_legal_name', 'org_tax_number', 'org_commercial_number'];
            $footerDefaults = ['org_phone_primary', 'org_phone_secondary', 'org_email', 'org_website', 'org_address'];
            $headerItems = md_visible_profile_fields($_POST['output_header_items'] ?? [], $allowedVisibility);
            $footerItems = md_visible_profile_fields($_POST['output_footer_items'] ?? [], $allowedVisibility);
            if (empty($headerItems)) {
                $headerItems = $headerDefaults;
            }
            if (empty($footerItems)) {
                $footerItems = $footerDefaults;
            }

            $validatedWebsite = md_public_url_or_empty($orgWebsite);
            if ($orgWebsite !== '' && $validatedWebsite === '') {
                throw new RuntimeException('رابط الموقع الإلكتروني غير صالح.');
            }
            $whatsappNormalized = md_public_url_or_empty($orgSocialWhatsapp);
            if ($whatsappNormalized === '' && $orgSocialWhatsapp !== '') {
                $digits = preg_replace('/\D+/', '', $orgSocialWhatsapp);
                if ($digits !== '') {
                    if (strlen($digits) === 11 && strpos($digits, '0') === 0) {
                        $digits = '2' . $digits;
                    }
                    $whatsappNormalized = 'https://wa.me/' . $digits;
                }
            }
            $socialUrlMap = [
                'واتساب' => $whatsappNormalized,
                'فيسبوك' => $orgSocialFacebook,
                'إنستجرام' => $orgSocialInstagram,
                'لينكدإن' => $orgSocialLinkedin,
                'X / تويتر' => $orgSocialX,
                'يوتيوب' => $orgSocialYoutube,
            ];
            $validatedSocial = [];
            foreach ($socialUrlMap as $label => $value) {
                $normalized = md_public_url_or_empty($value);
                if ($value !== '' && $normalized === '') {
                    throw new RuntimeException('رابط ' . $label . ' غير صالح.');
                }
                $validatedSocial[$label] = $normalized;
            }
            if ($orgEmail !== '' && !filter_var($orgEmail, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('البريد الإلكتروني للمؤسسة غير صالح.');
            }
            if (!in_array($timezone, timezone_identifiers_list(), true)) {
                $timezone = 'Africa/Cairo';
            }
            if (!isset(app_ui_theme_presets()[$uiThemePreset])) {
                $uiThemePreset = 'midnight_gold';
            }
            if (!isset(app_brand_output_theme_presets()[$outputThemePreset])) {
                $outputThemePreset = 'midnight_gold';
            }
            app_setting_set($conn, 'app_name', $appName !== '' ? mb_substr($appName, 0, 80) : 'Arab Eagles');
            app_setting_set($conn, 'theme_color', $themeColor);
            app_setting_set($conn, 'ui_theme_preset', $uiThemePreset);
            app_setting_set($conn, 'output_theme_preset', $outputThemePreset);
            app_setting_set($conn, 'timezone', $timezone);
            app_setting_set($conn, 'app_lang', $appLang);
            app_setting_set($conn, 'org_name', mb_substr($orgName !== '' ? $orgName : $appName, 0, 190));
            app_setting_set($conn, 'org_legal_name', mb_substr($orgLegalName, 0, 255));
            app_setting_set($conn, 'org_tax_number', mb_substr($orgTaxNumber, 0, 120));
            app_setting_set($conn, 'org_commercial_number', mb_substr($orgCommercialNumber, 0, 120));
            app_setting_set($conn, 'org_phone_primary', mb_substr($orgPhonePrimary, 0, 80));
            app_setting_set($conn, 'org_phone_secondary', mb_substr($orgPhoneSecondary, 0, 80));
            app_setting_set($conn, 'org_email', mb_substr($orgEmail, 0, 190));
            app_setting_set($conn, 'org_website', $validatedWebsite);
            app_setting_set($conn, 'org_address', mb_substr($orgAddress, 0, 255));
            app_setting_set($conn, 'org_social_whatsapp', $validatedSocial['واتساب'] ?? '');
            app_setting_set($conn, 'org_social_facebook', $validatedSocial['فيسبوك'] ?? '');
            app_setting_set($conn, 'org_social_instagram', $validatedSocial['إنستجرام'] ?? '');
            app_setting_set($conn, 'org_social_linkedin', $validatedSocial['لينكدإن'] ?? '');
            app_setting_set($conn, 'org_social_x', $validatedSocial['X / تويتر'] ?? '');
            app_setting_set($conn, 'org_social_youtube', $validatedSocial['يوتيوب'] ?? '');
            app_setting_set($conn, 'org_footer_note', mb_substr($orgFooterNote, 0, 300));
            app_setting_set($conn, 'output_show_header', $showHeader);
            app_setting_set($conn, 'output_show_footer', $showFooter);
            app_setting_set($conn, 'output_show_logo', $showLogo);
            app_setting_set($conn, 'output_show_qr', $showQr);
            app_setting_set($conn, 'output_header_items', implode(',', $headerItems));
            app_setting_set($conn, 'output_footer_items', implode(',', $footerItems));
            app_set_lang($appLang, $conn, false);

            if (isset($_FILES['logo_file']) && (int)($_FILES['logo_file']['error'] !== UPLOAD_ERR_NO_FILE)) {
                $upload = app_store_uploaded_file($_FILES['logo_file'], [
                    'dir' => 'uploads/branding',
                    'prefix' => 'brand_logo_',
                    'max_size' => 5 * 1024 * 1024,
                    'allowed_extensions' => ['jpg', 'jpeg', 'png', 'webp', 'svg'],
                    'allowed_mimes' => ['image/jpeg', 'image/png', 'image/webp', 'image/svg+xml'],
                ]);
                if ($upload['ok']) {
                    app_setting_set($conn, 'app_logo_path', $upload['path']);
                } else {
                    throw new RuntimeException($upload['error']);
                }
            }
            app_audit_log_add($conn, 'master_data.branding_saved', [
                'entity_type' => 'settings',
                'entity_key' => 'branding',
                'details' => [
                    'app_name' => $appName,
                    'timezone' => $timezone,
                    'lang' => $appLang,
                ],
            ]);
            $msg = 'تم حفظ هوية وبيانات المؤسسة بنجاح.';
            return true;
        }

        if ($action === 'save_fx_widget_settings') {
            $fxWidgetEnabled = isset($_POST['fx_widget_enabled']) ? '1' : '0';
            $fxWidgetBaseCurrency = strtoupper(trim((string)($_POST['fx_widget_base_currency'] ?? 'EGP')));
            $fxWidgetRefreshSeconds = (int)($_POST['fx_widget_refresh_seconds'] ?? 60);
            $fxWidgetCurrenciesPayload = trim((string)($_POST['fx_widget_currencies_payload'] ?? ''));
            $fxCatalog = function_exists('app_fx_widget_currency_catalog') ? app_fx_widget_currency_catalog() : [];

            if (!isset($fxCatalog[$fxWidgetBaseCurrency])) {
                $fxWidgetBaseCurrency = 'EGP';
            }

            $rawCodes = [];
            if ($fxWidgetCurrenciesPayload !== '') {
                $decoded = json_decode($fxWidgetCurrenciesPayload, true);
                if (is_array($decoded)) {
                    $rawCodes = $decoded;
                }
            }
            if (empty($rawCodes)) {
                $rawCodes = md_string_list((string)($_POST['fx_widget_currencies'] ?? ''), true, 20, 10);
            }

            $fxCodes = [];
            foreach ($rawCodes as $code) {
                $code = strtoupper(trim((string)$code));
                if ($code === '' || $code === $fxWidgetBaseCurrency || !isset($fxCatalog[$code])) {
                    continue;
                }
                if (!in_array($code, $fxCodes, true)) {
                    $fxCodes[] = $code;
                }
                if (count($fxCodes) >= 12) {
                    break;
                }
            }

            if (empty($fxCodes)) {
                $fxCodes = ['USD', 'EUR', 'GBP', 'SAR', 'AED'];
            }

            if ($fxWidgetRefreshSeconds < 15) {
                $fxWidgetRefreshSeconds = 15;
            } elseif ($fxWidgetRefreshSeconds > 3600) {
                $fxWidgetRefreshSeconds = 3600;
            }

            app_setting_set($conn, 'fx_widget_enabled', $fxWidgetEnabled);
            app_setting_set($conn, 'fx_widget_base_currency', $fxWidgetBaseCurrency);
            app_setting_set($conn, 'fx_widget_currencies', implode(',', $fxCodes));
            app_setting_set($conn, 'fx_widget_refresh_seconds', (string)$fxWidgetRefreshSeconds);

            app_audit_log_add($conn, 'master_data.fx_widget_saved', [
                'entity_type' => 'settings',
                'entity_key' => 'currencies',
                'details' => [
                    'enabled' => $fxWidgetEnabled,
                    'base_currency' => $fxWidgetBaseCurrency,
                    'currencies' => $fxCodes,
                    'refresh_seconds' => $fxWidgetRefreshSeconds,
                ],
            ]);
            $msg = 'تم حفظ إعدادات أسعار العملات بنجاح.';
            return true;
        }

        if ($action === 'clear_logo') {
            app_setting_set($conn, 'app_logo_path', 'assets/img/Logo.png');
            app_audit_log_add($conn, 'master_data.logo_cleared', [
                'entity_type' => 'settings',
                'entity_key' => 'branding.logo',
            ]);
            $msg = 'تمت إعادة الشعار للوضع الافتراضي.';
            return true;
        }

        if ($action === 'save_payment_settings') {
            $paymobUrl = trim((string)($_POST['payment_method_paymob_url'] ?? ''));
            $walletNumber = trim((string)($_POST['payment_method_wallet_number'] ?? ''));
            $instapayUrl = trim((string)($_POST['payment_method_instapay_url'] ?? ''));
            $gatewayEnabled = !empty($_POST['payment_gateway_enabled']) ? '1' : '0';
            $gatewayRolloutState = strtolower(trim((string)($_POST['payment_gateway_rollout_state'] ?? 'draft')));
            if (!in_array($gatewayRolloutState, ['draft', 'pending_contract', 'active'], true)) {
                $gatewayRolloutState = 'draft';
            }
            $gatewayProvider = mb_substr(trim((string)($_POST['payment_gateway_provider'] ?? 'manual')), 0, 80);
            $gatewayCheckoutUrl = trim((string)($_POST['payment_gateway_checkout_url'] ?? ''));
            $gatewayProviderLabelAr = mb_substr(trim((string)($_POST['payment_gateway_provider_label_ar'] ?? 'بوابة الدفع')), 0, 120);
            $gatewayProviderLabelEn = mb_substr(trim((string)($_POST['payment_gateway_provider_label_en'] ?? 'Payment gateway')), 0, 120);
            $gatewaySupportEmail = mb_substr(trim((string)($_POST['payment_gateway_support_email'] ?? '')), 0, 190);
            $gatewaySupportWhatsapp = mb_substr(trim((string)($_POST['payment_gateway_support_whatsapp'] ?? '')), 0, 80);
            $gatewayApiBaseUrl = trim((string)($_POST['payment_gateway_api_base_url'] ?? ''));
            $gatewayApiVersion = mb_substr(trim((string)($_POST['payment_gateway_api_version'] ?? '')), 0, 30);
            $gatewayPublicKey = mb_substr(trim((string)($_POST['payment_gateway_public_key'] ?? '')), 0, 255);
            $gatewaySecretKey = mb_substr(trim((string)($_POST['payment_gateway_secret_key'] ?? '')), 0, 255);
            $gatewayMerchantId = mb_substr(trim((string)($_POST['payment_gateway_merchant_id'] ?? '')), 0, 120);
            $gatewayIntegrationId = mb_substr(trim((string)($_POST['payment_gateway_integration_id'] ?? '')), 0, 120);
            $gatewayIframeId = mb_substr(trim((string)($_POST['payment_gateway_iframe_id'] ?? '')), 0, 120);
            $gatewayHmacSecret = mb_substr(trim((string)($_POST['payment_gateway_hmac_secret'] ?? '')), 0, 255);
            $gatewayWebhookSecret = mb_substr(trim((string)($_POST['payment_gateway_webhook_secret'] ?? '')), 0, 255);
            $gatewayCallbackUrl = trim((string)($_POST['payment_gateway_callback_url'] ?? ''));
            $gatewayWebhookUrl = trim((string)($_POST['payment_gateway_webhook_url'] ?? ''));
            $gatewayPaymobIntegrationName = mb_substr(trim((string)($_POST['payment_gateway_paymob_integration_name'] ?? '')), 0, 120);
            $gatewayPaymobProcessedCallbackUrl = trim((string)($_POST['payment_gateway_paymob_processed_callback_url'] ?? ''));
            $gatewayPaymobResponseCallbackUrl = trim((string)($_POST['payment_gateway_paymob_response_callback_url'] ?? ''));
            $gatewayEmailNotificationsEnabled = !empty($_POST['payment_gateway_email_notifications_enabled']) ? '1' : '0';
            $gatewayWhatsappNotificationsEnabled = !empty($_POST['payment_gateway_whatsapp_notifications_enabled']) ? '1' : '0';
            $gatewayWhatsappMode = mb_substr(trim((string)($_POST['payment_gateway_whatsapp_mode'] ?? 'link')), 0, 20);
            $gatewayWhatsappAccessToken = mb_substr(trim((string)($_POST['payment_gateway_whatsapp_access_token'] ?? '')), 0, 255);
            $gatewayWhatsappPhoneNumberId = mb_substr(trim((string)($_POST['payment_gateway_whatsapp_phone_number_id'] ?? '')), 0, 120);
            $gatewayOutboundWebhooksEnabled = !empty($_POST['payment_gateway_outbound_webhooks_enabled']) ? '1' : '0';
            $gatewayOutboundWebhooksUrl = trim((string)($_POST['payment_gateway_outbound_webhooks_url'] ?? ''));
            $gatewayOutboundWebhooksToken = mb_substr(trim((string)($_POST['payment_gateway_outbound_webhooks_token'] ?? '')), 0, 255);
            $gatewayOutboundWebhooksSecret = mb_substr(trim((string)($_POST['payment_gateway_outbound_webhooks_secret'] ?? '')), 0, 255);
            $gatewayOutboundWebhooksEvents = md_json_encode_list(md_string_list((string)($_POST['payment_gateway_outbound_webhooks_events'] ?? ''), true, 40, 120));
            $gatewayInstructionsAr = mb_substr(trim((string)($_POST['payment_gateway_instructions_ar'] ?? 'استخدم رابط السداد أو بيانات التحويل الظاهرة ثم أرسل مرجع السداد لفريق المتابعة.')), 0, 1000);
            $gatewayInstructionsEn = mb_substr(trim((string)($_POST['payment_gateway_instructions_en'] ?? 'Use the payment link or transfer details shown, then send the payment reference to the billing team.')), 0, 1000);
            $depositPercent = (float)($_POST['payment_request_default_percent'] ?? 30);
            $depositNote = trim((string)($_POST['payment_request_default_note'] ?? 'عربون'));

            $paymobScheme = strtolower((string)parse_url($paymobUrl, PHP_URL_SCHEME));
            if ($paymobUrl !== '' && (!filter_var($paymobUrl, FILTER_VALIDATE_URL) || !in_array($paymobScheme, ['http', 'https'], true))) {
                throw new RuntimeException('رابط Paymob غير صالح.');
            }
            $instapayScheme = strtolower((string)parse_url($instapayUrl, PHP_URL_SCHEME));
            if ($instapayUrl !== '' && (!filter_var($instapayUrl, FILTER_VALIDATE_URL) || !in_array($instapayScheme, ['http', 'https'], true))) {
                throw new RuntimeException('رابط InstaPay غير صالح.');
            }
            $gatewayScheme = strtolower((string)parse_url($gatewayCheckoutUrl, PHP_URL_SCHEME));
            if ($gatewayCheckoutUrl !== '' && (!filter_var($gatewayCheckoutUrl, FILTER_VALIDATE_URL) || !in_array($gatewayScheme, ['http', 'https'], true))) {
                throw new RuntimeException('رابط بوابة الدفع غير صالح.');
            }
            $gatewayApiBaseScheme = strtolower((string)parse_url($gatewayApiBaseUrl, PHP_URL_SCHEME));
            if ($gatewayApiBaseUrl !== '' && (!filter_var($gatewayApiBaseUrl, FILTER_VALIDATE_URL) || !in_array($gatewayApiBaseScheme, ['http', 'https'], true))) {
                throw new RuntimeException('رابط API الأساسي غير صالح.');
            }
            $gatewayCallbackScheme = strtolower((string)parse_url($gatewayCallbackUrl, PHP_URL_SCHEME));
            if ($gatewayCallbackUrl !== '' && (!filter_var($gatewayCallbackUrl, FILTER_VALIDATE_URL) || !in_array($gatewayCallbackScheme, ['http', 'https'], true))) {
                throw new RuntimeException('رابط Callback غير صالح.');
            }
            $gatewayWebhookScheme = strtolower((string)parse_url($gatewayWebhookUrl, PHP_URL_SCHEME));
            if ($gatewayWebhookUrl !== '' && (!filter_var($gatewayWebhookUrl, FILTER_VALIDATE_URL) || !in_array($gatewayWebhookScheme, ['http', 'https'], true))) {
                throw new RuntimeException('رابط Webhook غير صالح.');
            }
            $gatewayPaymobProcessedScheme = strtolower((string)parse_url($gatewayPaymobProcessedCallbackUrl, PHP_URL_SCHEME));
            if ($gatewayPaymobProcessedCallbackUrl !== '' && (!filter_var($gatewayPaymobProcessedCallbackUrl, FILTER_VALIDATE_URL) || !in_array($gatewayPaymobProcessedScheme, ['http', 'https'], true))) {
                throw new RuntimeException('رابط Paymob Processed Callback غير صالح.');
            }
            $gatewayPaymobResponseScheme = strtolower((string)parse_url($gatewayPaymobResponseCallbackUrl, PHP_URL_SCHEME));
            if ($gatewayPaymobResponseCallbackUrl !== '' && (!filter_var($gatewayPaymobResponseCallbackUrl, FILTER_VALIDATE_URL) || !in_array($gatewayPaymobResponseScheme, ['http', 'https'], true))) {
                throw new RuntimeException('رابط Paymob Response Callback غير صالح.');
            }
            $gatewayOutboundWebhookScheme = strtolower((string)parse_url($gatewayOutboundWebhooksUrl, PHP_URL_SCHEME));
            if ($gatewayOutboundWebhooksUrl !== '' && (!filter_var($gatewayOutboundWebhooksUrl, FILTER_VALIDATE_URL) || !in_array($gatewayOutboundWebhookScheme, ['http', 'https'], true))) {
                throw new RuntimeException('رابط Outbound Webhook غير صالح.');
            }

            $depositPercent = max(0, min(100, $depositPercent));
            $depositNote = mb_substr($depositNote !== '' ? $depositNote : 'عربون', 0, 120);
            $gatewayProvider = $gatewayProvider !== '' ? $gatewayProvider : 'manual';
            $gatewayWhatsappMode = in_array($gatewayWhatsappMode, ['link', 'api'], true) ? $gatewayWhatsappMode : 'link';

            app_setting_set($conn, 'payment_method_paymob_url', $paymobUrl);
            app_setting_set($conn, 'payment_method_wallet_number', mb_substr($walletNumber, 0, 40));
            app_setting_set($conn, 'payment_method_instapay_url', $instapayUrl);
            app_setting_set($conn, 'payment_gateway_enabled', $gatewayEnabled);
            app_setting_set($conn, 'payment_gateway_rollout_state', $gatewayRolloutState);
            app_setting_set($conn, 'payment_gateway_provider', $gatewayProvider);
            app_setting_set($conn, 'payment_gateway_checkout_url', $gatewayCheckoutUrl);
            app_setting_set($conn, 'payment_gateway_provider_label_ar', $gatewayProviderLabelAr);
            app_setting_set($conn, 'payment_gateway_provider_label_en', $gatewayProviderLabelEn);
            app_setting_set($conn, 'payment_gateway_support_email', $gatewaySupportEmail);
            app_setting_set($conn, 'payment_gateway_support_whatsapp', $gatewaySupportWhatsapp);
            app_setting_set($conn, 'payment_gateway_api_base_url', $gatewayApiBaseUrl);
            app_setting_set($conn, 'payment_gateway_api_version', $gatewayApiVersion);
            app_setting_set($conn, 'payment_gateway_public_key', $gatewayPublicKey);
            app_setting_set($conn, 'payment_gateway_secret_key', $gatewaySecretKey);
            app_setting_set($conn, 'payment_gateway_merchant_id', $gatewayMerchantId);
            app_setting_set($conn, 'payment_gateway_integration_id', $gatewayIntegrationId);
            app_setting_set($conn, 'payment_gateway_iframe_id', $gatewayIframeId);
            app_setting_set($conn, 'payment_gateway_hmac_secret', $gatewayHmacSecret);
            app_setting_set($conn, 'payment_gateway_webhook_secret', $gatewayWebhookSecret);
            app_setting_set($conn, 'payment_gateway_callback_url', $gatewayCallbackUrl);
            app_setting_set($conn, 'payment_gateway_webhook_url', $gatewayWebhookUrl);
            app_setting_set($conn, 'payment_gateway_paymob_integration_name', $gatewayPaymobIntegrationName);
            app_setting_set($conn, 'payment_gateway_paymob_processed_callback_url', $gatewayPaymobProcessedCallbackUrl);
            app_setting_set($conn, 'payment_gateway_paymob_response_callback_url', $gatewayPaymobResponseCallbackUrl);
            app_setting_set($conn, 'payment_gateway_email_notifications_enabled', $gatewayEmailNotificationsEnabled);
            app_setting_set($conn, 'payment_gateway_whatsapp_notifications_enabled', $gatewayWhatsappNotificationsEnabled);
            app_setting_set($conn, 'payment_gateway_whatsapp_mode', $gatewayWhatsappMode);
            app_setting_set($conn, 'payment_gateway_whatsapp_access_token', $gatewayWhatsappAccessToken);
            app_setting_set($conn, 'payment_gateway_whatsapp_phone_number_id', $gatewayWhatsappPhoneNumberId);
            app_setting_set($conn, 'payment_gateway_outbound_webhooks_enabled', $gatewayOutboundWebhooksEnabled);
            app_setting_set($conn, 'payment_gateway_outbound_webhooks_url', $gatewayOutboundWebhooksUrl);
            app_setting_set($conn, 'payment_gateway_outbound_webhooks_token', $gatewayOutboundWebhooksToken);
            app_setting_set($conn, 'payment_gateway_outbound_webhooks_secret', $gatewayOutboundWebhooksSecret);
            app_setting_set($conn, 'payment_gateway_outbound_webhooks_events', $gatewayOutboundWebhooksEvents);
            app_setting_set($conn, 'payment_gateway_instructions_ar', $gatewayInstructionsAr);
            app_setting_set($conn, 'payment_gateway_instructions_en', $gatewayInstructionsEn);
            app_setting_set($conn, 'payment_request_default_percent', (string)$depositPercent);
            app_setting_set($conn, 'payment_request_default_note', $depositNote);

            app_audit_log_add($conn, 'master_data.payment_settings_saved', [
                'entity_type' => 'settings',
                'entity_key' => 'payments',
                'details' => [
                    'provider' => $gatewayProvider,
                    'rollout_state' => $gatewayRolloutState,
                    'enabled' => $gatewayEnabled,
                ],
            ]);
            $msg = $gatewayRolloutState === 'active'
                ? 'تم حفظ إعدادات وسائل الدفع وبوابة الدفع.'
                : 'تم حفظ إعدادات الدفع في وضع التحضير. لن يتم تفعيل البوابة أو الإشعارات أو الـ Webhooks حتى تغيير الحالة إلى نشط.';
            return true;
        }

        if ($action === 'save_tax_settings') {
            $taxRowsRaw = is_array($_POST['tax_rows'] ?? null) ? $_POST['tax_rows'] : [];
            $lawRowsRaw = is_array($_POST['law_rows'] ?? null) ? $_POST['law_rows'] : [];
            $existingLawCatalog = [];
            foreach (app_tax_law_catalog($conn, false) as $existingLaw) {
                $existingLawCatalog[(string)($existingLaw['key'] ?? '')] = $existingLaw;
            }
            $taxRows = [];
            foreach ($taxRowsRaw as $rowRaw) {
                if (!is_array($rowRaw)) {
                    continue;
                }
                $normalized = app_tax_normalize_type([
                    'key' => $rowRaw['key'] ?? '',
                    'name' => $rowRaw['name'] ?? '',
                    'name_en' => $rowRaw['name_en'] ?? '',
                    'category' => $rowRaw['category'] ?? 'other',
                    'rate' => $rowRaw['rate'] ?? 0,
                    'mode' => $rowRaw['mode'] ?? 'add',
                    'base' => $rowRaw['base'] ?? 'net_after_discount',
                    'scopes' => $rowRaw['scopes'] ?? [],
                    'is_active' => isset($rowRaw['is_active']) ? 1 : 0,
                ]);
                if ($normalized !== null) {
                    $taxRows[] = $normalized;
                }
            }
            if (empty($taxRows)) {
                throw new RuntimeException('أضف نوع ضريبة واحد على الأقل.');
            }

            $lawRows = [];
            foreach ($lawRowsRaw as $rowRaw) {
                if (!is_array($rowRaw)) {
                    continue;
                }
                $normalized = app_tax_normalize_law([
                    'key' => $rowRaw['key'] ?? '',
                    'name' => $rowRaw['name'] ?? '',
                    'name_en' => $rowRaw['name_en'] ?? '',
                    'category' => $rowRaw['category'] ?? 'procedural',
                    'frequency' => $rowRaw['frequency'] ?? 'monthly',
                    'settlement_mode' => $rowRaw['settlement_mode'] ?? 'informational',
                    'is_active' => isset($rowRaw['is_active']) ? 1 : 0,
                    'notes' => $rowRaw['notes'] ?? '',
                    'brackets_text' => $rowRaw['brackets_text'] ?? (($existingLawCatalog[(string)($rowRaw['key'] ?? '')]['brackets'] ?? []) ? app_tax_law_brackets_to_text((array)$existingLawCatalog[(string)($rowRaw['key'] ?? '')]['brackets']) : ''),
                ]);
                if ($normalized !== null) {
                    $lawRows[] = $normalized;
                }
            }
            if (empty($lawRows)) {
                throw new RuntimeException('أضف قانوناً ضريبياً واحداً على الأقل.');
            }

            $salesLaw = md_normalize_type_key((string)($_POST['tax_default_sales_law'] ?? ''));
            $quoteLaw = md_normalize_type_key((string)($_POST['tax_default_quote_law'] ?? ''));
            $lawKeys = array_column($lawRows, 'key');
            if (!in_array($salesLaw, $lawKeys, true)) {
                $salesLaw = (string)($lawRows[0]['key'] ?? '');
            }
            if (!in_array($quoteLaw, $lawKeys, true)) {
                $quoteLaw = $salesLaw;
            }

            app_setting_set($conn, 'tax_types_catalog', json_encode($taxRows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            app_setting_set($conn, 'tax_law_catalog', json_encode($lawRows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            app_setting_set($conn, 'tax_default_sales_law', $salesLaw);
            app_setting_set($conn, 'tax_default_quote_law', $quoteLaw);

            app_audit_log_add($conn, 'master_data.tax_settings_saved', [
                'entity_type' => 'settings',
                'entity_key' => 'tax',
                'details' => [
                    'tax_types' => count($taxRows),
                    'tax_laws' => count($lawRows),
                    'sales_law' => $salesLaw,
                    'quote_law' => $quoteLaw,
                ],
            ]);
            $msg = 'تم حفظ إعدادات الضرائب والقوانين الضريبية.';
            return true;
        }

        if ($action === 'save_ai_settings') {
            $aiProvider = strtolower(trim((string)($_POST['ai_provider'] ?? 'ollama')));
            if (!in_array($aiProvider, ['ollama', 'openai', 'gemini', 'openai_compatible'], true)) {
                $aiProvider = 'ollama';
            }
            $aiEnabled = !empty($_POST['ai_enabled']) ? '1' : '0';
            $aiApiKey = trim((string)($_POST['ai_api_key'] ?? ''));
            $defaultModel = $aiProvider === 'ollama'
                ? 'llama3.1:8b'
                : ($aiProvider === 'gemini' ? 'gemini-3-flash-preview' : 'gpt-5.4-mini');
            $defaultBaseUrl = $aiProvider === 'ollama'
                ? 'http://127.0.0.1:11434/v1'
                : ($aiProvider === 'gemini' ? 'https://generativelanguage.googleapis.com/v1beta/openai' : 'https://api.openai.com/v1');
            $aiModel = mb_substr(trim((string)($_POST['ai_model'] ?? $defaultModel)), 0, 120);
            $aiBaseUrl = trim((string)($_POST['ai_base_url'] ?? $defaultBaseUrl));

            if ($aiModel === '') {
                $aiModel = $defaultModel;
            }

            $baseScheme = strtolower((string)parse_url($aiBaseUrl, PHP_URL_SCHEME));
            if ($aiBaseUrl !== '' && (!filter_var($aiBaseUrl, FILTER_VALIDATE_URL) || !in_array($baseScheme, ['http', 'https'], true))) {
                throw new RuntimeException('رابط OpenAI Base URL غير صالح.');
            }

            $resolvedBaseUrl = $aiBaseUrl !== '' ? $aiBaseUrl : $defaultBaseUrl;
            app_setting_set($conn, 'ai_provider', $aiProvider);
            app_setting_set($conn, 'ai_enabled', $aiEnabled);
            app_setting_set($conn, 'ai_api_key', $aiApiKey);
            app_setting_set($conn, 'ai_model', $aiModel);
            app_setting_set($conn, 'ai_base_url', $resolvedBaseUrl);
            app_setting_set($conn, 'ai_openai_enabled', $aiProvider === 'openai' ? $aiEnabled : '0');
            app_setting_set($conn, 'ai_openai_api_key', $aiProvider === 'openai' ? $aiApiKey : '');
            app_setting_set($conn, 'ai_openai_model', $aiProvider === 'openai' ? $aiModel : '');
            app_setting_set($conn, 'ai_openai_base_url', $aiProvider === 'openai' ? $resolvedBaseUrl : '');

            app_audit_log_add($conn, 'master_data.ai_settings_saved', [
                'entity_type' => 'settings',
                'entity_key' => 'ai.provider',
                'details' => [
                    'provider' => $aiProvider,
                    'enabled' => $aiEnabled,
                    'model' => $aiModel,
                    'has_key' => $aiApiKey !== '' ? 1 : 0,
                    'base_url' => $resolvedBaseUrl,
                ],
            ]);

            $msg = $aiProvider === 'ollama'
                ? 'تم حفظ إعدادات Ollama. سيعمل التكامل عند تشغيل Ollama على الـ Base URL المحدد ووجود الموديل المحدد.'
                : ($aiProvider === 'gemini'
                    ? 'تم حفظ إعدادات Gemini. يمكنك الآن استدعاء الموديلات المتاحة من Google AI واختيار المناسب.'
                    : ($aiApiKey !== ''
                        ? 'تم حفظ إعدادات المزود وتفعيلها وفق البيانات الحالية.'
                        : 'تم حفظ إعدادات المزود، لكن التكامل لن يعمل حتى إضافة API Key فعلي.'));
            return true;
        }

        if ($action === 'save_eta_settings') {
            if (!app_is_work_runtime()) {
                throw new RuntimeException('إعدادات ETA متاحة على work فقط.');
            }
            $unitCatalogRows = md_eta_unit_post_rows(
                (array)($_POST['eta_unit_local'] ?? []),
                (array)($_POST['eta_unit_eta'] ?? [])
            );
            $itemCatalogRows = md_eta_catalog_post_rows(
                (array)($_POST['eta_item_local'] ?? []),
                (array)($_POST['eta_item_eta'] ?? []),
                (array)($_POST['eta_item_type'] ?? []),
                (array)($_POST['eta_item_source'] ?? [])
            );
            $settings = app_eta_einvoice_normalize_settings([
                'enabled' => !empty($_POST['eta_enabled']) ? 1 : 0,
                'environment' => $_POST['eta_environment'] ?? 'preprod',
                'base_url' => $_POST['eta_base_url'] ?? '',
                'token_url' => $_POST['eta_token_url'] ?? '',
                'client_id' => $_POST['eta_client_id'] ?? '',
                'client_secret' => $_POST['eta_client_secret'] ?? '',
                'issuer_rin' => $_POST['eta_issuer_rin'] ?? '',
                'branch_code' => $_POST['eta_branch_code'] ?? '',
                'activity_code' => $_POST['eta_activity_code'] ?? '',
                'default_document_type' => $_POST['eta_default_document_type'] ?? 'i',
                'signing_mode' => $_POST['eta_signing_mode'] ?? 'signing_server',
                'signing_base_url' => $_POST['eta_signing_base_url'] ?? '',
                'signing_api_key' => $_POST['eta_signing_api_key'] ?? '',
                'callback_api_key' => $_POST['eta_callback_api_key'] ?? '',
                'submission_mode' => $_POST['eta_submission_mode'] ?? 'manual_review',
                'unit_catalog' => json_encode($unitCatalogRows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]',
                'item_catalog' => json_encode($itemCatalogRows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]',
                'auto_pull_status' => !empty($_POST['eta_auto_pull_status']) ? 1 : 0,
                'auto_pull_documents' => !empty($_POST['eta_auto_pull_documents']) ? 1 : 0,
            ]);

            $baseScheme = strtolower((string)parse_url($settings['base_url'], PHP_URL_SCHEME));
            if ($settings['base_url'] !== '' && (!filter_var($settings['base_url'], FILTER_VALIDATE_URL) || !in_array($baseScheme, ['http', 'https'], true))) {
                throw new RuntimeException('رابط ETA Base URL غير صالح.');
            }
            $tokenScheme = strtolower((string)parse_url($settings['token_url'], PHP_URL_SCHEME));
            if ($settings['token_url'] !== '' && (!filter_var($settings['token_url'], FILTER_VALIDATE_URL) || !in_array($tokenScheme, ['http', 'https'], true))) {
                throw new RuntimeException('رابط ETA Token URL غير صالح.');
            }

            $signingScheme = strtolower((string)parse_url($settings['signing_base_url'], PHP_URL_SCHEME));
            if ($settings['signing_base_url'] !== '' && (!filter_var($settings['signing_base_url'], FILTER_VALIDATE_URL) || !in_array($signingScheme, ['http', 'https'], true))) {
                throw new RuntimeException('رابط خدمة التوقيع غير صالح.');
            }

            if ($settings['environment'] === 'prod' && ($settings['client_id'] === '' || $settings['client_secret'] === '')) {
                throw new RuntimeException('بيئة الإنتاج تحتاج Client ID و Client Secret.');
            }
            if ($settings['enabled'] && $settings['issuer_rin'] === '') {
                throw new RuntimeException('رقم التسجيل الضريبي للممول مطلوب عند تفعيل ETA.');
            }
            if ($settings['enabled'] && $settings['branch_code'] === '') {
                throw new RuntimeException('Branch Code مطلوب عند تفعيل ETA.');
            }

            foreach ($settings as $key => $value) {
                app_setting_set($conn, 'eta_einvoice_' . $key, is_scalar($value) ? (string)$value : '');
            }

            app_audit_log_add($conn, 'master_data.eta_settings_saved', [
                'entity_type' => 'settings',
                'entity_key' => 'eta.einvoice',
                'details' => [
                    'enabled' => $settings['enabled'],
                    'environment' => $settings['environment'],
                    'signing_mode' => $settings['signing_mode'],
                    'submission_mode' => $settings['submission_mode'],
                    'has_client_id' => $settings['client_id'] !== '' ? 1 : 0,
                    'has_client_secret' => $settings['client_secret'] !== '' ? 1 : 0,
                    'has_signing_url' => $settings['signing_base_url'] !== '' ? 1 : 0,
                ],
            ]);

            $msg = $settings['enabled']
                ? 'تم حفظ إعدادات ETA. التكامل مفعل من جهة الإعدادات، لكن الإرسال الفعلي سيعتمد على توفر بيانات الاعتماد وخدمة التوقيع.'
                : 'تم حفظ إعدادات ETA في وضع غير مفعّل. يمكنك تجهيز البيانات الآن وتفعيل الربط لاحقًا.';
            return true;
        }

        if ($action === 'sync_eta_item_catalog') {
            if (!app_is_work_runtime()) {
                throw new RuntimeException('مزامنة ETA متاحة على work فقط.');
            }
            $filters = [
                'OnlyActive' => true,
                'Ps' => 100,
                'Pn' => 1,
            ];
            $codeTypeFilter = strtoupper(trim((string)($_POST['eta_sync_code_type'] ?? '')));
            if (in_array($codeTypeFilter, ['EGS', 'GS1'], true)) {
                $filters['CodeType'] = $codeTypeFilter;
            }
            $nameFilter = trim((string)($_POST['eta_sync_code_name'] ?? ''));
            if ($nameFilter !== '') {
                $filters['CodeName'] = $nameFilter;
            }
            $result = app_eta_einvoice_sync_my_item_catalog_from_eta($conn, $filters);
            if (empty($result['ok'])) {
                $error = trim((string)($result['error'] ?? ''));
                $status = (int)($result['status'] ?? $result['code'] ?? 0);
                $body = trim((string)($result['body'] ?? ''));
                $message = 'تعذر مزامنة كتالوج الأصناف من ETA.';
                if ($error !== '') {
                    $message .= ' [' . $error . ']';
                }
                if ($status > 0) {
                    $message .= ' HTTP ' . $status . '.';
                }
                if ($body !== '') {
                    $message .= ' ' . mb_substr($body, 0, 300);
                }
                throw new RuntimeException($message);
            }
            $msg = 'تمت مزامنة أكواد حسابك من ETA. إجمالي السجلات الحالية: ' . (int)($result['count'] ?? 0);
            return true;
        }

        if ($action === 'save_number_rule') {
            $docType = strtolower(trim((string)($_POST['doc_type'] ?? '')));
            $prefix = trim((string)($_POST['prefix'] ?? ''));
            $padding = (int)($_POST['padding'] ?? 5);
            $nextNumber = (int)($_POST['next_number'] ?? 1);
            $resetPolicy = trim((string)($_POST['reset_policy'] ?? 'none'));
            if (!preg_match('/^[a-z0-9_]{2,40}$/', $docType)) {
                throw new RuntimeException('نوع المستند غير صالح.');
            }
            if (!in_array($resetPolicy, ['none', 'yearly', 'monthly'], true)) {
                $resetPolicy = 'none';
            }
            $padding = max(1, min(10, $padding));
            $nextNumber = max(1, $nextNumber);
            $stmt = $conn->prepare("
                INSERT INTO app_document_sequences (doc_type, prefix, padding, next_number, reset_policy, last_reset_key)
                VALUES (?, ?, ?, ?, ?, '')
                ON DUPLICATE KEY UPDATE
                    prefix = VALUES(prefix),
                    padding = VALUES(padding),
                    next_number = VALUES(next_number),
                    reset_policy = VALUES(reset_policy)
            ");
            $stmt->bind_param('ssiis', $docType, $prefix, $padding, $nextNumber, $resetPolicy);
            $stmt->execute();
            $stmt->close();
            $msg = 'تم حفظ قاعدة الترقيم.';
            return true;
        }

        return false;
    }
}
