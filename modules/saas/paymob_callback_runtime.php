<?php

if (!function_exists('saas_paymob_callback_prepare')) {
    function saas_paymob_callback_prepare(mysqli $controlConn): array
    {
        $stage = strtolower(trim((string)($_GET['stage'] ?? $_POST['stage'] ?? 'response')));
        if (!in_array($stage, ['processed', 'response', 'webhook'], true)) {
            $stage = 'response';
        }

        $rawBody = (string)file_get_contents('php://input');
        $GLOBALS['saas_paymob_raw_body'] = $rawBody;
        $payload = function_exists('saas_paymob_extract_payload') ? saas_paymob_extract_payload() : [];
        $gatewaySettings = function_exists('saas_payment_gateway_settings') ? saas_payment_gateway_settings($controlConn) : [];
        $signatureCheck = function_exists('saas_paymob_verify_signature')
            ? saas_paymob_verify_signature($gatewaySettings, $payload, $rawBody)
            : ['required' => false, 'verified' => false, 'reason' => 'helper_missing'];
        $references = function_exists('saas_paymob_callback_candidates') ? saas_paymob_callback_candidates($payload) : [];
        $invoice = function_exists('saas_find_subscription_invoice_by_reference')
            ? saas_find_subscription_invoice_by_reference($controlConn, $references)
            : null;
        $success = function_exists('saas_paymob_callback_success') ? saas_paymob_callback_success($payload) : false;
        $transactionId = (string)(function_exists('saas_paymob_payload_value') ? saas_paymob_payload_value($payload, ['id', 'obj.id', 'transaction_id']) : '');
        $paymentRef = trim($transactionId);
        if ($paymentRef === '') {
            $paymentRef = trim((string)(function_exists('saas_paymob_payload_value') ? saas_paymob_payload_value($payload, ['merchant_order_id', 'order.id', 'obj.order.id']) : ''));
        }

        $result = [
            'ok' => false,
            'stage' => $stage,
            'success' => $success,
            'invoice_id' => 0,
            'invoice_number' => '',
            'payment_ref' => $paymentRef,
            'matched_references' => $references,
            'signature' => $signatureCheck,
        ];

        if (!empty($signatureCheck['required']) && empty($signatureCheck['verified'])) {
            if (function_exists('app_saas_log_operation')) {
                app_saas_log_operation(
                    $controlConn,
                    'subscription.paymob_callback_rejected',
                    'Paymob callback rejected',
                    0,
                    [
                        'stage' => $stage,
                        'reason' => (string)($signatureCheck['reason'] ?? 'signature_mismatch'),
                        'references' => $references,
                        'payload' => $payload,
                    ],
                    'Paymob'
                );
            }
            return [
                'http_code' => 403,
                'result' => $result + ['ok' => false, 'error' => 'invalid_signature'],
                'redirect_url' => '',
            ];
        }

        if (is_array($invoice)) {
            $result['invoice_id'] = (int)($invoice['id'] ?? 0);
            $result['invoice_number'] = (string)($invoice['invoice_number'] ?? '');
            $result['portal_url'] = function_exists('saas_subscription_invoice_public_url') ? saas_subscription_invoice_public_url($invoice) : '';
            $result['tenant_id'] = (int)($invoice['tenant_id'] ?? 0);

            if ($success && strtolower(trim((string)($invoice['status'] ?? 'issued'))) !== 'paid') {
                $paidAt = date('Y-m-d H:i:s');
                $marked = function_exists('saas_mark_subscription_invoice_paid')
                    ? saas_mark_subscription_invoice_paid(
                        $controlConn,
                        (int)($invoice['id'] ?? 0),
                        (int)($invoice['tenant_id'] ?? 0),
                        $paidAt,
                        $paymentRef,
                        'paymob',
                        'Paymob callback [' . $stage . ']'
                    )
                    : false;
                $result['ok'] = $marked;
                $result['marked_paid'] = $marked;
            } else {
                $result['ok'] = $success;
                $result['marked_paid'] = false;
            }
        } else {
            $result['error'] = 'invoice_not_found';
        }

        if (function_exists('app_saas_log_operation')) {
            app_saas_log_operation(
                $controlConn,
                'subscription.paymob_callback',
                'Paymob callback',
                (int)($result['tenant_id'] ?? 0),
                [
                    'stage' => $stage,
                    'success' => $success,
                    'invoice_id' => (int)($result['invoice_id'] ?? 0),
                    'invoice_number' => (string)($result['invoice_number'] ?? ''),
                    'payment_ref' => $paymentRef,
                    'references' => $references,
                    'signature' => $signatureCheck,
                    'payload' => $payload,
                    'result' => $result,
                ],
                'Paymob'
            );
        }

        $redirectUrl = '';
        if ($stage === 'response' && !empty($result['portal_url'])) {
            $redirectUrl = (string)$result['portal_url'];
            $redirectUrl .= (strpos($redirectUrl, '?') === false ? '?' : '&') . 'gateway=' . rawurlencode('paymob');
            $redirectUrl .= '&gateway_result=' . rawurlencode(!empty($result['ok']) ? 'paid' : ($success ? 'received' : 'failed'));
        }

        return [
            'http_code' => 200,
            'result' => $result,
            'redirect_url' => $redirectUrl,
        ];
    }
}
