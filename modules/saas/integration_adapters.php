<?php

if (!function_exists('saas_gateway_template_replacements')) {
    function saas_gateway_template_replacements(array $invoiceRow, array $tenantRow, array $gatewaySettings): array
    {
        return [
            '{invoice_id}' => (string)($invoiceRow['id'] ?? 0),
            '{invoice_number}' => (string)($invoiceRow['invoice_number'] ?? ''),
            '{amount}' => number_format((float)($invoiceRow['amount'] ?? 0), 2, '.', ''),
            '{currency}' => (string)($invoiceRow['currency_code'] ?? 'EGP'),
            '{token}' => (string)($invoiceRow['access_token'] ?? ''),
            '{tenant_id}' => (string)($tenantRow['id'] ?? 0),
            '{tenant_slug}' => (string)($tenantRow['tenant_slug'] ?? ''),
            '{tenant_name}' => (string)($tenantRow['tenant_name'] ?? ''),
            '{billing_email}' => (string)($tenantRow['billing_email'] ?? ''),
            '{paymob_integration_name}' => (string)($gatewaySettings['paymob_integration_name'] ?? ''),
            '{processed_callback_url}' => (string)($gatewaySettings['paymob_processed_callback_url'] ?? ''),
            '{response_callback_url}' => (string)($gatewaySettings['paymob_response_callback_url'] ?? ''),
        ];
    }
}

if (!function_exists('saas_gateway_checkout_url')) {
    function saas_gateway_checkout_url(array $invoiceRow, array $tenantRow, array $gatewaySettings): string
    {
        $template = trim((string)($gatewaySettings['checkout_url'] ?? ''));
        if ($template === '') {
            return '';
        }

        $replacements = saas_gateway_template_replacements($invoiceRow, $tenantRow, $gatewaySettings);

        return strtr($template, $replacements);
    }
}

if (!function_exists('saas_gateway_provider_normalize')) {
    function saas_gateway_provider_normalize(string $provider): string
    {
        $provider = strtolower(trim($provider));
        if ($provider === '' || $provider === 'gateway') {
            return 'generic_link';
        }
        $aliases = [
            'link' => 'generic_link',
            'direct_link' => 'generic_link',
            'url' => 'generic_link',
            'manual' => 'manual',
            'paymob' => 'paymob',
        ];
        return $aliases[$provider] ?? $provider;
    }
}

if (!function_exists('saas_gateway_adapter_registry')) {
    function saas_gateway_adapter_registry(): array
    {
        return [
            'manual' => [
                'label' => 'Manual',
                'mode' => 'manual',
            ],
            'generic_link' => [
                'label' => 'Generic Link',
                'mode' => 'link',
            ],
            'paymob' => [
                'label' => 'Paymob',
                'mode' => 'hosted_link',
            ],
        ];
    }
}

if (!function_exists('saas_gateway_adapter_resolve')) {
    function saas_gateway_adapter_resolve(array $gatewaySettings): array
    {
        $provider = saas_gateway_provider_normalize((string)($gatewaySettings['provider'] ?? 'manual'));
        $registry = saas_gateway_adapter_registry();
        $adapter = $registry[$provider] ?? $registry['generic_link'];
        $adapter['key'] = $provider;
        $adapter['provider_label_ar'] = (string)($gatewaySettings['provider_label_ar'] ?? 'بوابة الدفع');
        $adapter['provider_label_en'] = (string)($gatewaySettings['provider_label_en'] ?? 'Payment gateway');
        return $adapter;
    }
}

if (!function_exists('saas_gateway_adapter_build_checkout')) {
    function saas_gateway_adapter_build_checkout(array $invoiceRow, array $tenantRow, array $gatewaySettings): array
    {
        $adapter = saas_gateway_adapter_resolve($gatewaySettings);
        $templateUrl = saas_gateway_checkout_url($invoiceRow, $tenantRow, $gatewaySettings);
        $result = [
            'provider' => (string)($adapter['key'] ?? 'manual'),
            'adapter_label' => (string)($adapter['label'] ?? 'Manual'),
            'mode' => (string)($adapter['mode'] ?? 'manual'),
            'status' => 'manual',
            'url' => '',
            'meta' => [],
        ];

        if (empty($gatewaySettings['enabled'])) {
            $result['status'] = 'locked';
            $result['meta'] = [
                'rollout_state' => (string)($gatewaySettings['rollout_state'] ?? 'draft'),
                'rollout_locked' => !empty($gatewaySettings['rollout_locked']),
            ];
            return $result;
        }

        if ($result['provider'] === 'manual') {
            return $result;
        }

        if ($result['provider'] === 'paymob') {
            $result['status'] = $templateUrl !== '' ? 'ready' : 'pending';
            $result['url'] = $templateUrl;
            $result['meta'] = [
                'integration_name' => (string)($gatewaySettings['paymob_integration_name'] ?? ''),
                'processed_callback_url' => (string)($gatewaySettings['paymob_processed_callback_url'] ?? ''),
                'response_callback_url' => (string)($gatewaySettings['paymob_response_callback_url'] ?? ''),
                'merchant_id' => (string)($gatewaySettings['merchant_id'] ?? ''),
                'integration_id' => (string)($gatewaySettings['integration_id'] ?? ''),
            ];
            return $result;
        }

        $result['status'] = $templateUrl !== '' ? 'ready' : 'pending';
        $result['url'] = $templateUrl;
        $result['meta'] = [
            'template_url' => (string)($gatewaySettings['checkout_url'] ?? ''),
        ];
        return $result;
    }
}
