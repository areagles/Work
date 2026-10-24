<?php

if (!function_exists('saas_billing_notice_recent_duplicate')) {
    function saas_billing_notice_recent_duplicate(mysqli $controlConn, int $tenantId, int $invoiceId, string $paymentRef): bool
    {
        $paymentRef = trim($paymentRef);
        if ($tenantId <= 0 || $invoiceId <= 0 || $paymentRef === '') {
            return false;
        }

        $stmt = $controlConn->prepare("
            SELECT context_json
            FROM saas_operation_log
            WHERE tenant_id = ? AND action_code = 'subscription.invoice_payment_notice'
            ORDER BY id DESC
            LIMIT 20
        ");
        $stmt->bind_param('i', $tenantId);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $context = json_decode((string)($row['context_json'] ?? '{}'), true);
            if ((int)($context['invoice_id'] ?? 0) === $invoiceId && trim((string)($context['payment_ref'] ?? '')) === $paymentRef) {
                $stmt->close();
                return true;
            }
        }
        $stmt->close();
        return false;
    }
}

if (!function_exists('saas_billing_portal_error_html')) {
    function saas_billing_portal_error_html(string $title, string $message): string
    {
        return '<!doctype html><html lang="ar" dir="rtl"><meta charset="utf-8"><title>'
            . app_h($title)
            . '</title><body style="background:#0b0b0b;color:#f3f3f3;font-family:Cairo,Tahoma,sans-serif;display:flex;min-height:100vh;align-items:center;justify-content:center"><div style="padding:24px;border:1px solid #2d2d2d;border-radius:18px;background:#151515">'
            . app_h($message)
            . '</div></body></html>';
    }
}

if (!function_exists('saas_billing_portal_prepare')) {
    function saas_billing_portal_prepare(mysqli $controlConn, bool $isEnglish): array
    {
        $token = trim((string)($_GET['token'] ?? $_POST['token'] ?? ''));
        $portalToken = trim((string)($_GET['portal'] ?? $_POST['portal'] ?? ''));
        if ($token === '' && $portalToken === '') {
            return [
                'http_code' => 404,
                'error_html' => saas_billing_portal_error_html('Invalid Link', 'رابط السداد غير صالح.'),
            ];
        }

        $gatewaySettings = function_exists('saas_payment_gateway_settings') ? saas_payment_gateway_settings($controlConn) : [];
        $tenantPortal = null;
        $invoice = $token !== '' && function_exists('saas_find_subscription_invoice_by_token') ? saas_find_subscription_invoice_by_token($controlConn, $token) : null;
        if (!$invoice && $portalToken !== '' && function_exists('saas_find_tenant_by_portal_token')) {
            $tenantPortal = saas_find_tenant_by_portal_token($controlConn, $portalToken);
        }
        if (!$invoice && !$tenantPortal) {
            return [
                'http_code' => 404,
                'error_html' => saas_billing_portal_error_html('Invoice Not Found', 'فاتورة الاشتراك غير موجودة أو انتهى رابطها.'),
            ];
        }

        $flashMessage = '';
        $flashType = 'success';
        $gatewayResult = strtolower(trim((string)($_GET['gateway_result'] ?? '')));
        $tenantId = (int)($invoice['tenant_id'] ?? $tenantPortal['id'] ?? 0);
        $currentSubscription = null;
        $tenantInvoices = [];
        $tenantPayments = [];
        $latestPaymentNotice = null;
        $invoiceSummary = [
            'total' => 0,
            'issued' => 0,
            'paid' => 0,
            'cancelled' => 0,
            'outstanding_amount' => 0.0,
        ];

        if ($tenantId > 0) {
            if (!$tenantPortal) {
                $stmtPortalTenant = $controlConn->prepare("SELECT * FROM saas_tenants WHERE id = ? LIMIT 1");
                $stmtPortalTenant->bind_param('i', $tenantId);
                $stmtPortalTenant->execute();
                $tenantPortal = $stmtPortalTenant->get_result()->fetch_assoc() ?: null;
                $stmtPortalTenant->close();
            }

            $stmtSubscription = $controlConn->prepare("
                SELECT *
                FROM saas_subscriptions
                WHERE tenant_id = ?
                ORDER BY
                    CASE
                        WHEN status = 'active' THEN 1
                        WHEN status = 'trial' THEN 2
                        WHEN status = 'past_due' THEN 3
                        WHEN status = 'suspended' THEN 4
                        ELSE 5
                    END,
                    id DESC
                LIMIT 1
            ");
            $stmtSubscription->bind_param('i', $tenantId);
            $stmtSubscription->execute();
            $currentSubscription = $stmtSubscription->get_result()->fetch_assoc();
            $stmtSubscription->close();

            $stmtInvoices = $controlConn->prepare("
                SELECT *
                FROM saas_subscription_invoices
                WHERE tenant_id = ?
                ORDER BY id DESC
                LIMIT 12
            ");
            $stmtInvoices->bind_param('i', $tenantId);
            $stmtInvoices->execute();
            $invoiceResult = $stmtInvoices->get_result();
            while ($row = $invoiceResult->fetch_assoc()) {
                $hydratedInvoice = function_exists('saas_issue_subscription_invoice_access')
                    ? saas_issue_subscription_invoice_access($controlConn, $row, $controlConn)
                    : $row;
                $tenantInvoices[] = $hydratedInvoice;
                $invoiceSummary['total']++;
                $statusCode = strtolower(trim((string)($hydratedInvoice['status'] ?? 'issued')));
                if ($statusCode === 'paid') {
                    $invoiceSummary['paid']++;
                } elseif ($statusCode === 'cancelled') {
                    $invoiceSummary['cancelled']++;
                } else {
                    $invoiceSummary['issued']++;
                    $invoiceSummary['outstanding_amount'] += (float)($hydratedInvoice['amount'] ?? 0);
                }
            }
            $stmtInvoices->close();

            $stmtPayments = $controlConn->prepare("
                SELECT *
                FROM saas_subscription_invoice_payments
                WHERE tenant_id = ?
                ORDER BY id DESC
                LIMIT 12
            ");
            $stmtPayments->bind_param('i', $tenantId);
            $stmtPayments->execute();
            $paymentResult = $stmtPayments->get_result();
            while ($row = $paymentResult->fetch_assoc()) {
                $tenantPayments[] = $row;
            }
            $stmtPayments->close();

            $stmtNotice = $controlConn->prepare("
                SELECT created_at, actor_name, context_json
                FROM saas_operation_log
                WHERE tenant_id = ? AND action_code = 'subscription.invoice_payment_notice'
                ORDER BY id DESC
                LIMIT 1
            ");
            $stmtNotice->bind_param('i', $tenantId);
            $stmtNotice->execute();
            $latestPaymentNotice = $stmtNotice->get_result()->fetch_assoc() ?: null;
            $stmtNotice->close();
        }

        if (!$invoice && !empty($tenantInvoices)) {
            foreach ($tenantInvoices as $candidateInvoice) {
                $statusCode = strtolower(trim((string)($candidateInvoice['status'] ?? 'issued')));
                if ($statusCode !== 'paid' && $statusCode !== 'cancelled') {
                    $invoice = $candidateInvoice;
                    break;
                }
            }
            if (!$invoice) {
                $invoice = $tenantInvoices[0];
            }
        }

        if ($tenantPortal && function_exists('saas_tenant_billing_portal_url')) {
            $tenantPortal['billing_portal_url'] = saas_tenant_billing_portal_url($tenantPortal);
        }
        if ($invoice && function_exists('saas_issue_subscription_invoice_access')) {
            $invoice = saas_issue_subscription_invoice_access($controlConn, $invoice, $controlConn);
        }

        if (!$invoice) {
            return [
                'http_code' => 404,
                'error_html' => saas_billing_portal_error_html('Invoice Not Found', 'لا توجد فاتورة متاحة لهذا الحساب حاليًا.'),
            ];
        }

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $action = trim((string)($_POST['action'] ?? ''));
            if ($action === 'submit_payment_notice' && strtolower(trim((string)($invoice['status'] ?? 'issued'))) !== 'paid') {
                $rate = function_exists('app_rate_limit_check')
                    ? app_rate_limit_check(
                        'saas_billing_notice',
                        function_exists('app_rate_limit_client_key') ? app_rate_limit_client_key('saas_billing_notice_' . (int)($invoice['id'] ?? 0)) : ((string)($_SERVER['REMOTE_ADDR'] ?? 'billing_notice')),
                        5,
                        600
                    )
                    : ['allowed' => true];
                if (empty($rate['allowed'])) {
                    $flashMessage = app_tr('تم الوصول إلى الحد المسموح لإشعارات السداد. حاول مرة أخرى بعد قليل.', 'Payment notice limit reached. Please try again shortly.');
                    $flashType = 'error';
                } else {
                    $paymentRef = mb_substr(trim((string)($_POST['payment_ref'] ?? '')), 0, 190);
                    $payerName = mb_substr(trim((string)($_POST['payer_name'] ?? '')), 0, 190);
                    $notice = mb_substr(trim((string)($_POST['notice'] ?? '')), 0, 1000);
                    if (saas_billing_notice_recent_duplicate($controlConn, (int)($invoice['tenant_id'] ?? 0), (int)($invoice['id'] ?? 0), $paymentRef)) {
                        $flashMessage = app_tr('تم تسجيل هذا المرجع مؤخرًا بالفعل، لذلك لم نكرر الإشعار.', 'This reference was already submitted recently, so the notice was not duplicated.');
                        $flashType = 'warning';
                    } else {
                        $notificationDispatch = function_exists('saas_send_billing_notifications')
                            ? saas_send_billing_notifications($controlConn, $invoice, 'payment_notice', [
                                'payment_ref' => $paymentRef,
                                'payer_name' => $payerName,
                                'notice' => $notice,
                            ])
                            : ['email' => ['support' => false, 'tenant' => false], 'whatsapp' => ['ok' => false, 'mode' => 'none', 'error' => 'helper_missing', 'link' => '']];
                        app_saas_log_operation($controlConn, 'subscription.invoice_payment_notice', 'إشعار سداد من بوابة الاشتراك', (int)($invoice['tenant_id'] ?? 0), [
                            'invoice_id' => (int)($invoice['id'] ?? 0),
                            'invoice_number' => (string)($invoice['invoice_number'] ?? ''),
                            'payment_ref' => $paymentRef,
                            'payer_name' => $payerName,
                            'notice' => $notice,
                            'notification_dispatch' => $notificationDispatch,
                        ], $payerName !== '' ? $payerName : 'Billing Portal');
                        if (function_exists('saas_dispatch_outbound_webhook')) {
                            saas_dispatch_outbound_webhook($controlConn, 'subscription.invoice_payment_notice', [
                                'invoice' => $invoice,
                                'payment_notice' => [
                                    'payment_ref' => $paymentRef,
                                    'payer_name' => $payerName,
                                    'notice' => $notice,
                                    'submitted_at' => date('c'),
                                ],
                                'notification_dispatch' => $notificationDispatch,
                            ], (int)($invoice['tenant_id'] ?? 0), 'إشعار سداد من بوابة الاشتراك');
                        }
                        $emailSent = !empty($notificationDispatch['email']['support']) || !empty($notificationDispatch['email']['tenant']);
                        $whatsMode = (string)($notificationDispatch['whatsapp']['mode'] ?? 'disabled');
                        $whatsSent = !empty($notificationDispatch['whatsapp']['ok']);
                        $extra = [];
                        if ($emailSent) {
                            $extra[] = app_tr('تم إرسال بريد إشعار تلقائي.', 'Notification email was sent automatically.');
                        }
                        if ($whatsSent && $whatsMode === 'api') {
                            $extra[] = app_tr('تم إرسال إشعار واتساب عبر API.', 'WhatsApp notification was sent via API.');
                        } elseif ($whatsSent && $whatsMode === 'link') {
                            $extra[] = app_tr('تم تجهيز رابط واتساب لفريق المتابعة.', 'A WhatsApp link was prepared for the billing team.');
                        }
                        $flashMessage = app_tr('تم إرسال إشعار السداد لفريق المتابعة. سيتم مراجعة الفاتورة وتأكيدها.', 'Payment notice has been sent to the billing team. The invoice will be reviewed and confirmed.');
                        if (!empty($extra)) {
                            $flashMessage .= ' ' . implode(' ', $extra);
                        }
                        $flashType = 'success';
                    }
                }
            }
        }

        if ($flashMessage === '' && $gatewayResult !== '') {
            if ($gatewayResult === 'paid') {
                $flashMessage = app_tr('استقبلنا تأكيدًا مبدئيًا من بوابة الدفع. يمكنك الآن مراجعة حالة الفاتورة أو الانتظار لحين اعتمادها النهائي تلقائيًا.', 'We received an initial confirmation from the payment gateway. You can review the invoice status now or wait for final automatic confirmation.');
                $flashType = 'success';
            } elseif ($gatewayResult === 'received') {
                $flashMessage = app_tr('تم استلام إشعار من بوابة الدفع، وجارٍ مراجعة العملية.', 'A notification was received from the payment gateway and is being reviewed.');
                $flashType = 'success';
            } elseif ($gatewayResult === 'failed') {
                $flashMessage = app_tr('لم يكتمل السداد عبر البوابة. يمكنك إعادة المحاولة أو إرسال إشعار سداد يدوي.', 'Payment was not completed through the gateway. You can retry or submit a manual payment notice.');
                $flashType = 'error';
            }
        }

        $gatewayLabel = (string)($isEnglish ? ($gatewaySettings['provider_label_en'] ?? 'Payment gateway') : ($gatewaySettings['provider_label_ar'] ?? 'بوابة الدفع'));
        $gatewayInstructions = (string)($isEnglish ? ($gatewaySettings['instructions_en'] ?? '') : ($gatewaySettings['instructions_ar'] ?? ''));
        $tenantPortalUrl = $tenantPortal && function_exists('saas_tenant_billing_portal_url') ? saas_tenant_billing_portal_url($tenantPortal) : '';
        $checkoutUrl = '';
        if (function_exists('saas_gateway_checkout_url')) {
            $checkoutUrl = saas_gateway_checkout_url($invoice, $invoice, $gatewaySettings);
        }
        if ($checkoutUrl === '') {
            $checkoutUrl = trim((string)($invoice['gateway_public_url'] ?? ''));
            if (strpos($checkoutUrl, 'saas_billing_portal.php') !== false) {
                $checkoutUrl = '';
            }
        }

        return [
            'http_code' => 200,
            'isEnglish' => $isEnglish,
            'token' => $token,
            'portalToken' => $portalToken,
            'tenantPortal' => $tenantPortal,
            'invoice' => $invoice,
            'currentSubscription' => $currentSubscription,
            'tenantInvoices' => $tenantInvoices,
            'tenantPayments' => $tenantPayments,
            'latestPaymentNotice' => $latestPaymentNotice,
            'invoiceSummary' => $invoiceSummary,
            'flashMessage' => $flashMessage,
            'flashType' => $flashType,
            'gatewayResult' => $gatewayResult,
            'gatewaySettings' => $gatewaySettings,
            'gatewayLabel' => $gatewayLabel,
            'gatewayInstructions' => $gatewayInstructions,
            'tenantPortalUrl' => $tenantPortalUrl,
            'checkoutUrl' => $checkoutUrl,
            'invoiceStatus' => strtolower(trim((string)($invoice['status'] ?? 'issued'))),
        ];
    }
}
