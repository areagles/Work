<?php

if (!function_exists('saas_payment_method_catalog')) {
    function saas_payment_method_catalog(): array
    {
        $catalog = [
            'bank_transfer' => ['label_ar' => 'تحويل بنكي', 'label_en' => 'Bank transfer'],
            'instapay' => ['label_ar' => 'إنستاباي', 'label_en' => 'InstaPay'],
            'wallet' => ['label_ar' => 'محفظة إلكترونية', 'label_en' => 'Mobile wallet'],
            'cash' => ['label_ar' => 'نقدي', 'label_en' => 'Cash'],
            'card' => ['label_ar' => 'بطاقة', 'label_en' => 'Card'],
            'check' => ['label_ar' => 'شيك', 'label_en' => 'Check'],
            'gateway' => ['label_ar' => 'بوابة دفع', 'label_en' => 'Payment gateway'],
            'manual' => ['label_ar' => 'يدوي', 'label_en' => 'Manual'],
        ];

        if (function_exists('app_setting_get') && isset($GLOBALS['conn']) && $GLOBALS['conn'] instanceof mysqli) {
            $raw = trim((string)app_setting_get($GLOBALS['conn'], 'saas_payment_methods_catalog', ''));
            if ($raw !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    foreach ($decoded as $key => $row) {
                        $code = strtolower(trim((string)$key));
                        if ($code === '') {
                            continue;
                        }
                        $catalog[$code] = [
                            'label_ar' => trim((string)($row['label_ar'] ?? $row['ar'] ?? $code)),
                            'label_en' => trim((string)($row['label_en'] ?? $row['en'] ?? $code)),
                        ];
                    }
                }
            }
        }

        return $catalog;
    }
}

if (!function_exists('saas_payment_gateway_settings')) {
    function saas_payment_gateway_settings(?mysqli $settingsConn = null): array
    {
        if ($settingsConn instanceof mysqli && function_exists('app_payment_gateway_settings')) {
            $shared = app_payment_gateway_settings($settingsConn);
            return [
                'enabled' => !empty($shared['enabled']),
                'provider' => (string)($shared['provider'] ?? 'manual'),
                'checkout_url' => (string)($shared['checkout_url'] ?? ''),
                'provider_label_ar' => (string)($shared['provider_label_ar'] ?? 'بوابة الدفع'),
                'provider_label_en' => (string)($shared['provider_label_en'] ?? 'Payment gateway'),
                'instructions_ar' => (string)($shared['instructions_ar'] ?? ''),
                'instructions_en' => (string)($shared['instructions_en'] ?? ''),
                'support_email' => (string)($shared['support_email'] ?? ''),
                'support_whatsapp' => (string)($shared['support_whatsapp'] ?? ''),
                'api_base_url' => (string)($shared['api_base_url'] ?? ''),
                'api_version' => (string)($shared['api_version'] ?? ''),
                'public_key' => (string)($shared['public_key'] ?? ''),
                'secret_key' => (string)($shared['secret_key'] ?? ''),
                'merchant_id' => (string)($shared['merchant_id'] ?? ''),
                'integration_id' => (string)($shared['integration_id'] ?? ''),
                'iframe_id' => (string)($shared['iframe_id'] ?? ''),
                'hmac_secret' => (string)($shared['hmac_secret'] ?? ''),
                'webhook_secret' => (string)($shared['webhook_secret'] ?? ''),
                'callback_url' => (string)($shared['callback_url'] ?? ''),
                'webhook_url' => (string)($shared['webhook_url'] ?? ''),
                'paymob_integration_name' => (string)($shared['paymob_integration_name'] ?? ''),
                'paymob_processed_callback_url' => (string)($shared['paymob_processed_callback_url'] ?? ($shared['callback_url'] ?? '')),
                'paymob_response_callback_url' => (string)($shared['paymob_response_callback_url'] ?? ($shared['webhook_url'] ?? '')),
                'email_notifications_enabled' => !empty($shared['email_notifications_enabled']),
                'whatsapp_notifications_enabled' => !empty($shared['whatsapp_notifications_enabled']),
                'whatsapp_mode' => (string)($shared['whatsapp_mode'] ?? 'link'),
                'whatsapp_access_token' => (string)($shared['whatsapp_access_token'] ?? ''),
                'whatsapp_phone_number_id' => (string)($shared['whatsapp_phone_number_id'] ?? ''),
                'outbound_webhooks_enabled' => !empty($shared['outbound_webhooks_enabled']),
                'outbound_webhooks_url' => (string)($shared['outbound_webhooks_url'] ?? ''),
                'outbound_webhooks_token' => (string)($shared['outbound_webhooks_token'] ?? ''),
                'outbound_webhooks_secret' => (string)($shared['outbound_webhooks_secret'] ?? ''),
                'outbound_webhooks_events' => (string)($shared['outbound_webhooks_events'] ?? ''),
            ];
        }

        $provider = 'manual';
        $enabled = false;
        $checkoutUrl = '';
        $providerLabelAr = 'بوابة الدفع';
        $providerLabelEn = 'Payment gateway';
        $instructionsAr = 'استخدم رابط السداد أو بيانات التحويل الظاهرة، ثم أرسل مرجع السداد إلى فريق المتابعة.';
        $instructionsEn = 'Use the payment link or transfer details shown here, then send the payment reference to the support team.';
        $supportEmail = '';
        $supportWhatsapp = '';
        $apiBaseUrl = '';
        $apiVersion = '';
        $publicKey = '';
        $secretKey = '';
        $merchantId = '';
        $integrationId = '';
        $iframeId = '';
        $hmacSecret = '';
        $webhookSecret = '';
        $callbackUrl = '';
        $webhookUrl = '';
        $paymobIntegrationName = '';
        $paymobProcessedCallbackUrl = '';
        $paymobResponseCallbackUrl = '';
        $emailNotificationsEnabled = true;
        $whatsappNotificationsEnabled = false;
        $whatsappMode = 'link';
        $whatsappAccessToken = '';
        $whatsappPhoneNumberId = '';
        $outboundWebhooksEnabled = false;
        $outboundWebhooksUrl = '';
        $outboundWebhooksToken = '';
        $outboundWebhooksSecret = '';
        $outboundWebhooksEvents = '';

        if ($settingsConn instanceof mysqli && function_exists('app_setting_get')) {
            $enabled = app_setting_get($settingsConn, 'payment_gateway_enabled', app_setting_get($settingsConn, 'saas_gateway_enabled', '0')) === '1';
            $provider = strtolower(trim((string)app_setting_get($settingsConn, 'payment_gateway_provider', app_setting_get($settingsConn, 'saas_gateway_provider', 'manual'))));
            $checkoutUrl = trim((string)app_setting_get($settingsConn, 'payment_gateway_checkout_url', app_setting_get($settingsConn, 'saas_gateway_checkout_url', '')));
            $providerLabelAr = trim((string)app_setting_get($settingsConn, 'payment_gateway_provider_label_ar', app_setting_get($settingsConn, 'saas_gateway_provider_label_ar', $providerLabelAr)));
            $providerLabelEn = trim((string)app_setting_get($settingsConn, 'payment_gateway_provider_label_en', app_setting_get($settingsConn, 'saas_gateway_provider_label_en', $providerLabelEn)));
            $instructionsAr = trim((string)app_setting_get($settingsConn, 'payment_gateway_instructions_ar', app_setting_get($settingsConn, 'saas_gateway_instructions_ar', $instructionsAr)));
            $instructionsEn = trim((string)app_setting_get($settingsConn, 'payment_gateway_instructions_en', app_setting_get($settingsConn, 'saas_gateway_instructions_en', $instructionsEn)));
            $supportEmail = trim((string)app_setting_get($settingsConn, 'payment_gateway_support_email', app_setting_get($settingsConn, 'saas_gateway_support_email', app_setting_get($settingsConn, 'support_email', ''))));
            $supportWhatsapp = trim((string)app_setting_get($settingsConn, 'payment_gateway_support_whatsapp', app_setting_get($settingsConn, 'saas_gateway_support_whatsapp', app_setting_get($settingsConn, 'support_whatsapp', ''))));
            $apiBaseUrl = trim((string)app_setting_get($settingsConn, 'payment_gateway_api_base_url', ''));
            $apiVersion = trim((string)app_setting_get($settingsConn, 'payment_gateway_api_version', ''));
            $publicKey = trim((string)app_setting_get($settingsConn, 'payment_gateway_public_key', ''));
            $secretKey = trim((string)app_setting_get($settingsConn, 'payment_gateway_secret_key', ''));
            $merchantId = trim((string)app_setting_get($settingsConn, 'payment_gateway_merchant_id', ''));
            $integrationId = trim((string)app_setting_get($settingsConn, 'payment_gateway_integration_id', ''));
            $iframeId = trim((string)app_setting_get($settingsConn, 'payment_gateway_iframe_id', ''));
            $hmacSecret = trim((string)app_setting_get($settingsConn, 'payment_gateway_hmac_secret', ''));
            $webhookSecret = trim((string)app_setting_get($settingsConn, 'payment_gateway_webhook_secret', ''));
            $callbackUrl = trim((string)app_setting_get($settingsConn, 'payment_gateway_callback_url', ''));
            $webhookUrl = trim((string)app_setting_get($settingsConn, 'payment_gateway_webhook_url', ''));
            $paymobIntegrationName = trim((string)app_setting_get($settingsConn, 'payment_gateway_paymob_integration_name', ''));
            $paymobProcessedCallbackUrl = trim((string)app_setting_get($settingsConn, 'payment_gateway_paymob_processed_callback_url', $callbackUrl));
            $paymobResponseCallbackUrl = trim((string)app_setting_get($settingsConn, 'payment_gateway_paymob_response_callback_url', $webhookUrl));
            $emailNotificationsEnabled = app_setting_get($settingsConn, 'payment_gateway_email_notifications_enabled', '1') === '1';
            $whatsappNotificationsEnabled = app_setting_get($settingsConn, 'payment_gateway_whatsapp_notifications_enabled', '0') === '1';
            $whatsappMode = trim((string)app_setting_get($settingsConn, 'payment_gateway_whatsapp_mode', 'link'));
            $whatsappAccessToken = trim((string)app_setting_get($settingsConn, 'payment_gateway_whatsapp_access_token', ''));
            $whatsappPhoneNumberId = trim((string)app_setting_get($settingsConn, 'payment_gateway_whatsapp_phone_number_id', ''));
            $outboundWebhooksEnabled = app_setting_get($settingsConn, 'payment_gateway_outbound_webhooks_enabled', '0') === '1';
            $outboundWebhooksUrl = trim((string)app_setting_get($settingsConn, 'payment_gateway_outbound_webhooks_url', ''));
            $outboundWebhooksToken = trim((string)app_setting_get($settingsConn, 'payment_gateway_outbound_webhooks_token', ''));
            $outboundWebhooksSecret = trim((string)app_setting_get($settingsConn, 'payment_gateway_outbound_webhooks_secret', ''));
            $outboundWebhooksEvents = trim((string)app_setting_get($settingsConn, 'payment_gateway_outbound_webhooks_events', ''));
        }

        if ($provider === '') {
            $provider = 'manual';
        }

        return [
            'enabled' => $enabled,
            'provider' => $provider,
            'checkout_url' => $checkoutUrl,
            'provider_label_ar' => $providerLabelAr !== '' ? $providerLabelAr : 'بوابة الدفع',
            'provider_label_en' => $providerLabelEn !== '' ? $providerLabelEn : 'Payment gateway',
            'instructions_ar' => $instructionsAr !== '' ? $instructionsAr : 'استخدم رابط السداد أو بيانات التحويل الظاهرة، ثم أرسل مرجع السداد إلى فريق المتابعة.',
            'instructions_en' => $instructionsEn !== '' ? $instructionsEn : 'Use the payment link or transfer details shown here, then send the payment reference to the support team.',
            'support_email' => $supportEmail,
            'support_whatsapp' => $supportWhatsapp,
            'api_base_url' => $apiBaseUrl,
            'api_version' => $apiVersion,
            'public_key' => $publicKey,
            'secret_key' => $secretKey,
            'merchant_id' => $merchantId,
            'integration_id' => $integrationId,
            'iframe_id' => $iframeId,
            'hmac_secret' => $hmacSecret,
            'webhook_secret' => $webhookSecret,
            'callback_url' => $callbackUrl,
            'webhook_url' => $webhookUrl,
            'paymob_integration_name' => $paymobIntegrationName,
            'paymob_processed_callback_url' => $paymobProcessedCallbackUrl,
            'paymob_response_callback_url' => $paymobResponseCallbackUrl,
            'email_notifications_enabled' => $emailNotificationsEnabled,
            'whatsapp_notifications_enabled' => $whatsappNotificationsEnabled,
            'whatsapp_mode' => $whatsappMode !== '' ? $whatsappMode : 'link',
            'whatsapp_access_token' => $whatsappAccessToken,
            'whatsapp_phone_number_id' => $whatsappPhoneNumberId,
            'outbound_webhooks_enabled' => $outboundWebhooksEnabled,
            'outbound_webhooks_url' => $outboundWebhooksUrl,
            'outbound_webhooks_token' => $outboundWebhooksToken,
            'outbound_webhooks_secret' => $outboundWebhooksSecret,
            'outbound_webhooks_events' => $outboundWebhooksEvents,
        ];
    }
}

if (!function_exists('saas_outbound_webhook_events')) {
    function saas_outbound_webhook_events(array $gatewaySettings): array
    {
        $raw = $gatewaySettings['outbound_webhooks_events'] ?? '';
        $events = [];
        if (is_string($raw) && trim($raw) !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                foreach ($decoded as $item) {
                    $value = strtolower(trim((string)$item));
                    if ($value !== '') {
                        $events[] = $value;
                    }
                }
            } else {
                $parts = preg_split('/[\s,;\n\r]+/', trim((string)$raw)) ?: [];
                foreach ($parts as $item) {
                    $value = strtolower(trim((string)$item));
                    if ($value !== '') {
                        $events[] = $value;
                    }
                }
            }
        }
        return array_values(array_unique($events));
    }
}

if (!function_exists('saas_outbound_webhook_allowed')) {
    function saas_outbound_webhook_allowed(array $gatewaySettings, string $eventCode): bool
    {
        if (empty($gatewaySettings['outbound_webhooks_enabled'])) {
            return false;
        }
        if (trim((string)($gatewaySettings['outbound_webhooks_url'] ?? '')) === '') {
            return false;
        }
        $eventCode = strtolower(trim($eventCode));
        if ($eventCode === '') {
            return false;
        }
        $allowed = saas_outbound_webhook_events($gatewaySettings);
        if ($allowed === []) {
            return true;
        }
        return in_array($eventCode, $allowed, true);
    }
}

if (!function_exists('saas_sanitize_tenant_snapshot')) {
    function saas_sanitize_tenant_snapshot(array $tenant): array
    {
        $allowedKeys = [
            'id',
            'tenant_name',
            'tenant_slug',
            'legal_name',
            'status',
            'edition_key',
            'app_mode',
            'plan_code',
            'locale',
            'timezone',
            'users_limit',
            'storage_limit_mb',
            'current_subscription_id',
            'subscription_status',
            'subscribed_until',
            'billing_email',
            'provision_profile',
            'policy_pack',
            'system_name',
            'system_folder',
            'created_at',
            'updated_at',
        ];
        $clean = [];
        foreach ($allowedKeys as $key) {
            if (array_key_exists($key, $tenant)) {
                $clean[$key] = $tenant[$key];
            }
        }
        return $clean;
    }
}

if (!function_exists('saas_fetch_tenant_snapshot')) {
    function saas_fetch_tenant_snapshot(mysqli $controlConn, int $tenantId): ?array
    {
        if ($tenantId <= 0) {
            return null;
        }
        $stmt = $controlConn->prepare("SELECT * FROM saas_tenants WHERE id = ? LIMIT 1");
        $stmt->bind_param('i', $tenantId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        if (!is_array($row)) {
            return null;
        }
        return saas_sanitize_tenant_snapshot($row);
    }
}

if (!function_exists('saas_webhook_record_attempt')) {
    function saas_webhook_record_attempt(mysqli $controlConn, int $deliveryId, array $response, int $attemptCount, int $maxAttempts): void
    {
        $ok = !empty($response['ok']);
        $httpCode = (int)($response['http_code'] ?? 0);
        $body = (string)($response['body'] ?? '');
        $error = mb_substr(trim((string)($response['error'] ?? '')), 0, 255);
        $status = $ok ? 'sent' : 'failed';
        $lastAttemptAt = date('Y-m-d H:i:s');
        $deliveredAt = $ok ? $lastAttemptAt : null;
        $nextRetryAt = (!$ok && $attemptCount < $maxAttempts) ? saas_webhook_due_retry_at($attemptCount) : null;

        $stmt = $controlConn->prepare("
            UPDATE saas_webhook_deliveries
            SET status = ?, attempt_count = ?, http_code = ?, response_body = ?, last_error = ?, last_attempt_at = ?, next_retry_at = ?, delivered_at = ?
            WHERE id = ?
            LIMIT 1
        ");
        $stmt->bind_param('siisssssi', $status, $attemptCount, $httpCode, $body, $error, $lastAttemptAt, $nextRetryAt, $deliveredAt, $deliveryId);
        $stmt->execute();
        $stmt->close();
    }
}

if (!function_exists('saas_execute_webhook_delivery')) {
    function saas_execute_webhook_delivery(mysqli $controlConn, array $delivery): array
    {
        $deliveryId = (int)($delivery['id'] ?? 0);
        if ($deliveryId <= 0) {
            return ['ok' => false, 'skipped' => true, 'reason' => 'invalid_delivery'];
        }

        $targetUrl = trim((string)($delivery['target_url'] ?? ''));
        if ($targetUrl === '') {
            return ['ok' => false, 'skipped' => true, 'reason' => 'missing_target_url'];
        }

        $payload = json_decode((string)($delivery['payload_json'] ?? ''), true);
        if (!is_array($payload)) {
            $payload = [];
        }
        $headers = json_decode((string)($delivery['request_headers_json'] ?? ''), true);
        if (!is_array($headers)) {
            $headers = [];
        }
        $headers = array_values(array_filter(array_map(static function ($line) {
            return trim((string)$line);
        }, $headers), static function ($line) {
            return $line !== '';
        }));

        $attemptCount = max(0, (int)($delivery['attempt_count'] ?? 0)) + 1;
        $maxAttempts = max(1, (int)($delivery['max_attempts'] ?? 5));
        $response = app_license_http_post_json($targetUrl, $payload, $headers, 20);
        saas_webhook_record_attempt($controlConn, $deliveryId, $response, $attemptCount, $maxAttempts);

        $tenantId = max(0, (int)($delivery['tenant_id'] ?? 0));
        app_saas_log_operation(
            $controlConn,
            !empty($response['ok']) ? 'integration.webhook_sent' : 'integration.webhook_failed',
            !empty($response['ok']) ? 'إرسال Outbound Webhook' : 'فشل Outbound Webhook',
            $tenantId,
            [
                'delivery_id' => $deliveryId,
                'event' => (string)($delivery['event_code'] ?? ''),
                'target_url' => $targetUrl,
                'attempt_count' => $attemptCount,
                'max_attempts' => $maxAttempts,
                'http_code' => (int)($response['http_code'] ?? 0),
                'ok' => !empty($response['ok']),
                'error' => (string)($response['error'] ?? ''),
                'next_retry_at' => (!$response['ok'] && $attemptCount < $maxAttempts) ? saas_webhook_due_retry_at($attemptCount) : null,
            ],
            'System'
        );

        return [
            'ok' => !empty($response['ok']),
            'skipped' => false,
            'delivery_id' => $deliveryId,
            'attempt_count' => $attemptCount,
            'max_attempts' => $maxAttempts,
            'http_code' => (int)($response['http_code'] ?? 0),
            'reason' => (string)($response['error'] ?? ''),
            'body' => (string)($response['body'] ?? ''),
        ];
    }
}

if (!function_exists('saas_retry_due_webhook_deliveries')) {
    function saas_retry_due_webhook_deliveries(mysqli $controlConn, int $limit = 20): array
    {
        $limit = max(1, min(200, $limit));
        $rows = [];
        $now = date('Y-m-d H:i:s');
        $stmt = $controlConn->prepare("
            SELECT *
            FROM saas_webhook_deliveries
            WHERE status = 'failed'
              AND attempt_count < max_attempts
              AND (next_retry_at IS NULL OR next_retry_at <= ?)
            ORDER BY COALESCE(next_retry_at, created_at) ASC, id ASC
            LIMIT ?
        ");
        $stmt->bind_param('si', $now, $limit);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $rows[] = $row;
        }
        $stmt->close();

        $summary = ['selected' => count($rows), 'sent' => 0, 'failed' => 0];
        foreach ($rows as $row) {
            $result = saas_execute_webhook_delivery($controlConn, $row);
            if (!empty($result['ok'])) {
                $summary['sent']++;
            } elseif (empty($result['skipped'])) {
                $summary['failed']++;
            }
        }
        return $summary;
    }
}

if (!function_exists('saas_retry_webhook_delivery_now')) {
    function saas_retry_webhook_delivery_now(mysqli $controlConn, int $deliveryId): array
    {
        if ($deliveryId <= 0) {
            return ['ok' => false, 'reason' => 'invalid_delivery'];
        }
        $stmt = $controlConn->prepare("SELECT * FROM saas_webhook_deliveries WHERE id = ? LIMIT 1");
        $stmt->bind_param('i', $deliveryId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        if (!is_array($row)) {
            return ['ok' => false, 'reason' => 'not_found'];
        }
        if ((int)($row['attempt_count'] ?? 0) >= (int)($row['max_attempts'] ?? 5)) {
            return ['ok' => false, 'reason' => 'max_attempts_reached'];
        }
        return saas_execute_webhook_delivery($controlConn, $row);
    }
}

if (!function_exists('saas_dispatch_outbound_webhook')) {
    function saas_dispatch_outbound_webhook(mysqli $controlConn, string $eventCode, array $payload, int $tenantId = 0, string $label = ''): array
    {
        $gatewaySettings = saas_payment_gateway_settings($controlConn);
        $eventCode = strtolower(trim($eventCode));
        if (!saas_outbound_webhook_allowed($gatewaySettings, $eventCode)) {
            return ['ok' => false, 'skipped' => true, 'reason' => 'disabled_or_not_allowed'];
        }

        $url = trim((string)($gatewaySettings['outbound_webhooks_url'] ?? ''));
        if ($url === '') {
            return ['ok' => false, 'skipped' => true, 'reason' => 'missing_url'];
        }

        $tenantId = max(0, $tenantId);
        $tenant = $tenantId > 0 ? saas_fetch_tenant_snapshot($controlConn, $tenantId) : null;
        $body = [
            'event' => $eventCode,
            'label' => $label !== '' ? $label : $eventCode,
            'sent_at' => date('c'),
            'runtime_profile' => app_runtime_profile(),
            'system_url' => rtrim(app_base_url(), '/'),
            'tenant' => $tenant,
            'data' => $payload,
        ];

        $headers = ['X-ArabEagles-Webhook-Event: ' . $eventCode];
        $token = trim((string)($gatewaySettings['outbound_webhooks_token'] ?? ''));
        if ($token !== '') {
            $headers[] = 'Authorization: Bearer ' . $token;
        }
        $secret = trim((string)($gatewaySettings['outbound_webhooks_secret'] ?? ''));
        if ($secret !== '') {
            $json = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (is_string($json)) {
                $headers[] = 'X-ArabEagles-Webhook-Signature: sha256=' . hash_hmac('sha256', $json, $secret);
            }
        }

        $payloadJson = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $headersJson = json_encode($headers, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $maxAttempts = 5;
        if ($tenantId > 0) {
            $stmt = $controlConn->prepare("
                INSERT INTO saas_webhook_deliveries
                    (tenant_id, event_code, event_label, target_url, request_headers_json, payload_json, status, max_attempts)
                VALUES
                    (?, ?, ?, ?, ?, ?, 'pending', ?)
            ");
            $stmt->bind_param('isssssi', $tenantId, $eventCode, $label, $url, $headersJson, $payloadJson, $maxAttempts);
        } else {
            $stmt = $controlConn->prepare("
                INSERT INTO saas_webhook_deliveries
                    (tenant_id, event_code, event_label, target_url, request_headers_json, payload_json, status, max_attempts)
                VALUES
                    (NULL, ?, ?, ?, ?, ?, 'pending', ?)
            ");
            $stmt->bind_param('sssssi', $eventCode, $label, $url, $headersJson, $payloadJson, $maxAttempts);
        }
        $stmt->execute();
        $deliveryId = (int)$stmt->insert_id;
        $stmt->close();

        if ($deliveryId <= 0) {
            return ['ok' => false, 'skipped' => false, 'reason' => 'insert_failed'];
        }

        return saas_execute_webhook_delivery($controlConn, [
            'id' => $deliveryId,
            'tenant_id' => $tenantId,
            'event_code' => $eventCode,
            'target_url' => $url,
            'request_headers_json' => $headersJson,
            'payload_json' => $payloadJson,
            'attempt_count' => 0,
            'max_attempts' => $maxAttempts,
        ]);
    }
}

if (!function_exists('saas_billing_notification_message')) {
    function saas_billing_notification_message(array $invoiceRow, string $eventCode, bool $isEnglish = false, array $extra = []): array
    {
        $invoiceNumber = (string)($invoiceRow['invoice_number'] ?? 'SINV');
        $tenantName = (string)($invoiceRow['tenant_name'] ?? $invoiceRow['tenant_slug'] ?? 'Tenant');
        $amount = number_format((float)($invoiceRow['amount'] ?? 0), 2);
        $currency = (string)($invoiceRow['currency_code'] ?? 'EGP');
        $portalUrl = (string)(function_exists('saas_subscription_invoice_public_url') ? saas_subscription_invoice_public_url($invoiceRow) : '');
        $tenantPortalUrl = trim((string)($extra['tenant_portal_url'] ?? ''));
        $paymentRef = trim((string)($extra['payment_ref'] ?? ''));
        $payerName = trim((string)($extra['payer_name'] ?? ''));
        $notice = trim((string)($extra['notice'] ?? ''));

        if ($eventCode === 'payment_notice') {
            $subject = $isEnglish
                ? 'Payment notice for subscription invoice ' . $invoiceNumber
                : 'إشعار سداد لفاتورة الاشتراك ' . $invoiceNumber;
            $bodyLines = $isEnglish
                ? [
                    'A payment notice was submitted from the public billing portal.',
                    'Tenant: ' . $tenantName,
                    'Invoice: ' . $invoiceNumber,
                    'Amount: ' . $currency . ' ' . $amount,
                    'Payer: ' . ($payerName !== '' ? $payerName : '-'),
                    'Reference: ' . ($paymentRef !== '' ? $paymentRef : '-'),
                    'Notes: ' . ($notice !== '' ? $notice : '-'),
                    'Portal: ' . ($portalUrl !== '' ? $portalUrl : '-'),
                    'Account portal: ' . ($tenantPortalUrl !== '' ? $tenantPortalUrl : '-'),
                ]
                : [
                    'تم إرسال إشعار سداد من بوابة الفوترة العامة.',
                    'المستأجر: ' . $tenantName,
                    'الفاتورة: ' . $invoiceNumber,
                    'القيمة: ' . $currency . ' ' . $amount,
                    'اسم الدافع: ' . ($payerName !== '' ? $payerName : '-'),
                    'المرجع: ' . ($paymentRef !== '' ? $paymentRef : '-'),
                    'الملاحظات: ' . ($notice !== '' ? $notice : '-'),
                    'رابط البوابة: ' . ($portalUrl !== '' ? $portalUrl : '-'),
                    'رابط بوابة العميل: ' . ($tenantPortalUrl !== '' ? $tenantPortalUrl : '-'),
                ];
        } else {
            $subject = $isEnglish
                ? 'Subscription invoice issued: ' . $invoiceNumber
                : 'تم إصدار فاتورة اشتراك: ' . $invoiceNumber;
            $bodyLines = $isEnglish
                ? [
                    'A new subscription invoice has been issued.',
                    'Tenant: ' . $tenantName,
                    'Invoice: ' . $invoiceNumber,
                    'Amount: ' . $currency . ' ' . $amount,
                    'Portal: ' . ($portalUrl !== '' ? $portalUrl : '-'),
                    'Account portal: ' . ($tenantPortalUrl !== '' ? $tenantPortalUrl : '-'),
                ]
                : [
                    'تم إصدار فاتورة اشتراك جديدة.',
                    'المستأجر: ' . $tenantName,
                    'الفاتورة: ' . $invoiceNumber,
                    'القيمة: ' . $currency . ' ' . $amount,
                    'رابط البوابة: ' . ($portalUrl !== '' ? $portalUrl : '-'),
                    'رابط بوابة العميل: ' . ($tenantPortalUrl !== '' ? $tenantPortalUrl : '-'),
                ];
        }

        return [
            'subject' => $subject,
            'body_ar' => implode("\n", $isEnglish ? [] : $bodyLines),
            'body_en' => implode("\n", $isEnglish ? $bodyLines : []),
            'body' => implode("\n", $bodyLines),
        ];
    }
}

if (!function_exists('saas_send_billing_notifications')) {
    function saas_send_billing_notifications(mysqli $controlConn, array $invoiceRow, string $eventCode, array $extra = []): array
    {
        $gatewaySettings = saas_payment_gateway_settings($controlConn);
        $tenantEmail = trim((string)($invoiceRow['billing_email'] ?? ''));
        $supportEmail = trim((string)($gatewaySettings['support_email'] ?? ''));
        $supportWhatsapp = trim((string)($gatewaySettings['support_whatsapp'] ?? ''));
        $tenantPortalUrl = '';
        $tenantId = (int)($invoiceRow['tenant_id'] ?? 0);
        if ($tenantId > 0) {
            $stmtTenant = $controlConn->prepare("SELECT * FROM saas_tenants WHERE id = ? LIMIT 1");
            $stmtTenant->bind_param('i', $tenantId);
            $stmtTenant->execute();
            $tenantRow = $stmtTenant->get_result()->fetch_assoc() ?: null;
            $stmtTenant->close();
            if (is_array($tenantRow) && function_exists('saas_tenant_billing_portal_url')) {
                $tenantPortalUrl = (string)saas_tenant_billing_portal_url($tenantRow);
            }
        }
        $extra['tenant_portal_url'] = $tenantPortalUrl;

        $messageAr = saas_billing_notification_message($invoiceRow, $eventCode, false, $extra);
        $messageEn = saas_billing_notification_message($invoiceRow, $eventCode, true, $extra);

        $emailResults = [
            'support' => false,
            'tenant' => false,
        ];
        if (!empty($gatewaySettings['email_notifications_enabled']) && function_exists('app_send_email_basic')) {
            if ($supportEmail !== '') {
                $emailResults['support'] = app_send_email_basic($supportEmail, (string)$messageAr['subject'], (string)$messageAr['body'], [
                    'from_name' => 'Arab Eagles Billing',
                ]);
            }
            if ($tenantEmail !== '') {
                $emailResults['tenant'] = app_send_email_basic($tenantEmail, (string)$messageEn['subject'], (string)$messageEn['body'], [
                    'from_name' => 'Arab Eagles Billing',
                ]);
            }
        }

        $whatsResult = ['ok' => false, 'mode' => 'disabled', 'error' => 'notifications_disabled', 'link' => ''];
        if ($supportWhatsapp !== '' && function_exists('app_payment_gateway_send_whatsapp')) {
            $whatsResult = app_payment_gateway_send_whatsapp($gatewaySettings, $supportWhatsapp, (string)$messageAr['body']);
        }

        return [
            'email' => $emailResults,
            'whatsapp' => $whatsResult,
        ];
    }
}
