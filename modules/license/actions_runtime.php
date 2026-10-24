<?php

if (!function_exists('ls_handle_remote_post_actions')) {
    function ls_handle_remote_post_actions(mysqli $conn, string $action, array &$state): bool
    {
        if (!in_array($action, [
            'issue_user_reset_link',
            'remote_create_user',
            'remote_update_user',
            'remote_set_password',
            'remote_delete_user',
            'rotate_api_token',
            'push_license_credentials',
            'delete_support_report',
            'delete_reports_for_license',
            'send_activation_package',
        ], true)) {
            return false;
        }

        if ($action === 'issue_user_reset_link') {
            $reportId = (int)($_POST['report_id'] ?? 0);
            $reportUserId = (int)($_POST['report_user_id'] ?? 0);
            $channel = strtolower(trim((string)($_POST['send_channel'] ?? 'none')));
            $result = app_support_owner_issue_user_reset_link($conn, $reportId, $reportUserId);
            if (!empty($result['ok'])) {
                $userNode = (isset($result['user']) && is_array($result['user'])) ? $result['user'] : [];
                $userName = trim((string)($userNode['full_name'] ?? $userNode['username'] ?? ''));
                $userEmail = trim((string)($userNode['email'] ?? ''));
                $userPhone = trim((string)($userNode['phone'] ?? ''));
                $resetLink = trim((string)($result['reset_link'] ?? ''));
                $expiresAt = trim((string)($result['expires_at'] ?? ''));
                $appName = app_setting_get($conn, 'app_name', 'Arab Eagles');

                $message = app_tr(
                    "مرحباً {$userName}،\nتم إنشاء رابط إعادة تعيين كلمة المرور لحسابك.\n{$resetLink}\nصلاحية الرابط حتى: {$expiresAt}",
                    "Hello {$userName},\nA password reset link was generated for your account.\n{$resetLink}\nThis link is valid until: {$expiresAt}"
                );
                $mailto = '';
                if (filter_var($userEmail, FILTER_VALIDATE_EMAIL)) {
                    $mailto = 'mailto:' . rawurlencode($userEmail)
                        . '?subject=' . rawurlencode(app_tr('رابط إعادة تعيين كلمة المرور', 'Password reset link'))
                        . '&body=' . rawurlencode($message);
                }
                $waPhone = ls_phone_whatsapp($userPhone);
                $whatsAppUrl = $waPhone !== '' ? ('https://wa.me/' . $waPhone . '?text=' . rawurlencode($message)) : '';

                $state['issuedResetInfo'] = [
                    'report_id' => $reportId,
                    'report_user_id' => $reportUserId,
                    'link' => $resetLink,
                    'expires_at' => $expiresAt,
                    'mailto' => $mailto,
                    'whatsapp' => $whatsAppUrl,
                    'user_name' => $userName,
                ];

                if ($channel === 'email_auto' && filter_var($userEmail, FILTER_VALIDATE_EMAIL)) {
                    $subject = app_tr('رابط إعادة تعيين كلمة المرور', 'Password reset link');
                    $mailBody = $message . "\n\n" . app_tr("مرسل من {$appName}", "Sent from {$appName}");
                    $mailSent = app_send_email_basic($userEmail, $subject, $mailBody, ['from_name' => $appName]);
                    if ($mailSent) {
                        $state['noticeType'] = 'success';
                        $state['noticeText'] = app_tr('تم إنشاء رابط إعادة التعيين وإرساله عبر البريد.', 'Reset link generated and emailed successfully.');
                    } else {
                        $state['noticeType'] = 'error';
                        $state['noticeText'] = app_tr('تم إنشاء الرابط لكن فشل إرسال البريد.', 'Reset link generated but email sending failed.');
                    }
                } else {
                    $state['noticeType'] = 'success';
                    $state['noticeText'] = app_tr('تم إنشاء رابط إعادة التعيين بنجاح.', 'Reset link generated successfully.');
                }
            } else {
                $state['noticeType'] = 'error';
                $state['noticeText'] = app_tr('تعذر إنشاء رابط إعادة التعيين.', 'Failed to generate reset link.')
                    . ' [' . app_h(ls_remote_error_text((string)($result['error'] ?? 'unknown'))) . ']';
            }
            return true;
        }

        if ($action === 'remote_create_user') {
            $reportId = (int)($_POST['report_id'] ?? 0);
            $username = strtolower(trim((string)($_POST['remote_new_username'] ?? '')));
            $password = (string)($_POST['remote_new_password'] ?? '');
            $fullName = trim((string)($_POST['remote_new_full_name'] ?? ''));
            $role = trim((string)($_POST['remote_new_role'] ?? 'employee'));
            $email = trim((string)($_POST['remote_new_email'] ?? ''));
            $phone = trim((string)($_POST['remote_new_phone'] ?? ''));

            if ($reportId <= 0 || $username === '' || strlen($password) < 4) {
                $state['noticeType'] = 'error';
                $state['noticeText'] = app_tr('بيانات إنشاء المستخدم غير مكتملة.', 'Create user payload is incomplete.');
            } else {
                $payload = [
                    'username' => $username,
                    'password' => $password,
                    'full_name' => $fullName !== '' ? $fullName : $username,
                    'role' => $role,
                    'email' => $email,
                    'phone' => $phone,
                ];
                $result = app_support_owner_remote_user_action($conn, $reportId, 'create', $payload);
                if (!empty($result['ok'])) {
                    $state['noticeType'] = 'success';
                    $state['noticeText'] = app_tr('تم إنشاء المستخدم على نظام العميل بنجاح.', 'User created on client system successfully.');
                } else {
                    $state['noticeType'] = 'error';
                    $state['noticeText'] = app_tr('فشل إنشاء المستخدم على نظام العميل.', 'Failed to create user on client system.')
                        . ' [' . app_h(ls_remote_error_text((string)($result['error'] ?? 'unknown'))) . ']';
                }
            }
            return true;
        }

        if ($action === 'remote_update_user') {
            $reportId = (int)($_POST['report_id'] ?? 0);
            $payload = [
                'remote_user_id' => (int)($_POST['remote_user_id'] ?? 0),
                'username' => trim((string)($_POST['remote_username'] ?? '')),
                'full_name' => trim((string)($_POST['remote_full_name'] ?? '')),
                'role' => trim((string)($_POST['remote_role'] ?? 'employee')),
                'email' => trim((string)($_POST['remote_email'] ?? '')),
                'phone' => trim((string)($_POST['remote_phone'] ?? '')),
            ];
            $result = app_support_owner_remote_user_action($conn, $reportId, 'update', $payload);
            if (!empty($result['ok'])) {
                $state['noticeType'] = 'success';
                $state['noticeText'] = app_tr('تم تحديث بيانات المستخدم على نظام العميل.', 'Client user updated successfully.');
            } else {
                $state['noticeType'] = 'error';
                $state['noticeText'] = app_tr('فشل تحديث المستخدم على نظام العميل.', 'Failed to update client user.')
                    . ' [' . app_h(ls_remote_error_text((string)($result['error'] ?? 'unknown'))) . ']';
            }
            return true;
        }

        if ($action === 'remote_set_password') {
            $reportId = (int)($_POST['report_id'] ?? 0);
            $payload = [
                'remote_user_id' => (int)($_POST['remote_user_id'] ?? 0),
                'username' => trim((string)($_POST['remote_username'] ?? '')),
                'password' => (string)($_POST['remote_new_password'] ?? ''),
            ];
            $result = app_support_owner_remote_user_action($conn, $reportId, 'set_password', $payload);
            if (!empty($result['ok'])) {
                $state['noticeType'] = 'success';
                $state['noticeText'] = app_tr('تم تعيين كلمة مرور جديدة للمستخدم.', 'New password has been set for the user.');
            } else {
                $state['noticeType'] = 'error';
                $state['noticeText'] = app_tr('فشل تعيين كلمة المرور.', 'Failed to set user password.')
                    . ' [' . app_h(ls_remote_error_text((string)($result['error'] ?? 'unknown'))) . ']';
            }
            return true;
        }

        if ($action === 'remote_delete_user') {
            $reportId = (int)($_POST['report_id'] ?? 0);
            $payload = [
                'remote_user_id' => (int)($_POST['remote_user_id'] ?? 0),
                'username' => trim((string)($_POST['remote_username'] ?? '')),
            ];
            $result = app_support_owner_remote_user_action($conn, $reportId, 'delete', $payload);
            if (!empty($result['ok'])) {
                $state['noticeType'] = 'success';
                $state['noticeText'] = app_tr('تم حذف المستخدم من نظام العميل.', 'User deleted from client system.');
            } else {
                $state['noticeType'] = 'error';
                $state['noticeText'] = app_tr('فشل حذف المستخدم من نظام العميل.', 'Failed to delete user from client system.')
                    . ' [' . app_h(ls_remote_error_text((string)($result['error'] ?? 'unknown'))) . ']';
            }
            return true;
        }

        if ($action === 'rotate_api_token') {
            $licenseId = (int)($_POST['license_id'] ?? 0);
            $result = app_license_registry_rotate_api_token($conn, $licenseId);
            if (!empty($result['ok'])) {
                $state['noticeType'] = 'success';
                $state['noticeText'] = app_tr('تم توليد API Token جديد وحفظه.', 'A new API token was generated and saved.');
                $push = ls_try_push_license_credentials($conn, $licenseId);
                $state['noticeText'] .= ' | ' . app_tr('دفع الربط', 'Credential push')
                    . ': ' . (int)($push['pushed'] ?? 0)
                    . ' / ' . app_tr('فشل', 'Failed') . ': ' . (int)($push['failed'] ?? 0);
            } else {
                $state['noticeType'] = 'error';
                $state['noticeText'] = app_tr('تعذر تجديد API Token.', 'Failed to rotate API token.')
                    . ' [' . app_h((string)($result['error'] ?? 'unknown')) . ']';
            }
            return true;
        }

        if ($action === 'push_license_credentials') {
            $licenseId = (int)($_POST['license_id'] ?? 0);
            $push = ls_try_push_license_credentials($conn, $licenseId);
            if (!empty($push['ok'])) {
                $state['noticeType'] = 'success';
                $state['noticeText'] = app_tr('تم دفع بيانات الربط للأنظمة المرتبطة.', 'Credential push sent to linked systems.')
                    . ' (' . app_tr('نجح', 'Success') . ': ' . (int)($push['pushed'] ?? 0)
                    . ' / ' . app_tr('فشل', 'Failed') . ': ' . (int)($push['failed'] ?? 0) . ')';
                $pushErr = trim((string)($push['error'] ?? ''));
                if ($pushErr !== '') {
                    $state['noticeText'] .= ' [' . app_h(ls_remote_error_text($pushErr)) . ']';
                }
            } else {
                $state['noticeType'] = 'error';
                $state['noticeText'] = app_tr('فشل دفع بيانات الربط.', 'Credential push failed.')
                    . ' [' . app_h(ls_remote_error_text((string)($push['error'] ?? 'unknown'))) . ']';
            }
            return true;
        }

        if ($action === 'delete_support_report') {
            $reportId = (int)($_POST['report_id'] ?? 0);
            $result = app_support_owner_delete_report($conn, $reportId);
            if (!empty($result['ok'])) {
                $state['noticeType'] = 'success';
                $state['noticeText'] = app_tr('تم حذف تقرير النظام المحدد.', 'Selected system report deleted.');
            } else {
                $state['noticeType'] = 'error';
                $state['noticeText'] = app_tr('تعذر حذف التقرير.', 'Failed to delete report.')
                    . ' [' . app_h((string)($result['error'] ?? 'unknown')) . ']';
            }
            return true;
        }

        if ($action === 'delete_reports_for_license') {
            $licenseId = (int)($_POST['license_id'] ?? 0);
            $licenseKey = strtoupper(trim((string)($_POST['license_key'] ?? '')));
            $result = app_support_owner_delete_reports_for_license($conn, $licenseId, $licenseKey);
            if (!empty($result['ok'])) {
                $state['noticeType'] = 'success';
                $state['noticeText'] = app_tr('تم حذف تقارير الدعم الخاصة بالاشتراك.', 'Support reports for this subscription were deleted.')
                    . ' (' . (int)($result['reports_deleted'] ?? 0) . ')';
            } else {
                $state['noticeType'] = 'error';
                $state['noticeText'] = app_tr('فشل حذف تقارير الدعم.', 'Failed to delete support reports.')
                    . ' [' . app_h((string)($result['error'] ?? 'unknown')) . ']';
            }
            return true;
        }

        if ($action === 'send_activation_package') {
            $licenseId = (int)($_POST['license_id'] ?? 0);
            $channel = strtolower(trim((string)($_POST['send_channel'] ?? 'generate')));
            $licenseRow = app_license_registry_get($conn, $licenseId);

            if (empty($licenseRow)) {
                $state['noticeType'] = 'error';
                $state['noticeText'] = app_tr('اشتراك غير موجود.', 'Subscription not found.');
            } else {
                $licenseKey = trim((string)($licenseRow['license_key'] ?? ''));
                if ($licenseKey === '') {
                    $state['noticeType'] = 'error';
                    $state['noticeText'] = app_tr('لا يمكن إرسال البيانات بدون License Key.', 'Cannot send activation package without license key.');
                } else {
                    $apiToken = trim((string)($licenseRow['api_token'] ?? ''));
                    if ($apiToken === '') {
                        $rot = app_license_registry_rotate_api_token($conn, $licenseId);
                        if (!empty($rot['ok'])) {
                            $apiToken = trim((string)($rot['api_token'] ?? ''));
                            $licenseRow['api_token'] = $apiToken;
                        }
                    }

                    $base = rtrim((string)app_base_url(), '/');
                    $apiPrimary = $base . '/license_api.php';
                    $apiAlt = $base . '/api/license/check/';
                    $activationMessage = ls_activation_message($licenseRow, $apiPrimary, $apiAlt);

                    $clientEmail = trim((string)($licenseRow['client_email'] ?? ''));
                    $clientPhoneDigits = ls_phone_whatsapp((string)($licenseRow['client_phone'] ?? ''));
                    $subject = app_tr('بيانات تفعيل النظام', 'System activation package');
                    $mailto = '';
                    if (filter_var($clientEmail, FILTER_VALIDATE_EMAIL)) {
                        $mailto = 'mailto:' . rawurlencode($clientEmail)
                            . '?subject=' . rawurlencode($subject)
                            . '&body=' . rawurlencode($activationMessage);
                    }
                    $whatsAppUrl = $clientPhoneDigits !== ''
                        ? ('https://wa.me/' . $clientPhoneDigits . '?text=' . rawurlencode($activationMessage))
                        : '';

                    $state['issuedActivationInfo'] = [
                        'license_id' => $licenseId,
                        'message' => $activationMessage,
                        'mailto' => $mailto,
                        'whatsapp' => $whatsAppUrl,
                        'client_name' => (string)($licenseRow['client_name'] ?? ''),
                    ];

                    if ($channel === 'email_auto') {
                        if (!filter_var($clientEmail, FILTER_VALIDATE_EMAIL)) {
                            $state['noticeType'] = 'error';
                            $state['noticeText'] = app_tr('البريد غير صالح للإرسال المباشر.', 'Client email is invalid for direct sending.');
                        } else {
                            $sent = app_send_email_basic($clientEmail, $subject, $activationMessage);
                            if ($sent) {
                                $state['noticeType'] = 'success';
                                $state['noticeText'] = app_tr('تم إرسال بيانات التفعيل عبر البريد مباشرة.', 'Activation package emailed successfully.');
                            } else {
                                $state['noticeType'] = 'error';
                                $state['noticeText'] = app_tr('تعذر إرسال البريد، لكن تم تجهيز الرسالة للنسخ/الإرسال اليدوي.', 'Email sending failed, but activation package is ready for manual copy/send.');
                            }
                        }
                    } elseif ($channel === 'whatsapp_open') {
                        if ($whatsAppUrl === '') {
                            $state['noticeType'] = 'error';
                            $state['noticeText'] = app_tr('رقم هاتف العميل غير متاح لإرسال واتساب.', 'Client phone is unavailable for WhatsApp.');
                        } else {
                            $state['noticeType'] = 'success';
                            $state['noticeText'] = app_tr('تم تجهيز رسالة التفعيل عبر واتساب. اضغط زر الإرسال من الجدول.', 'WhatsApp activation message is ready. Use the send button in the table.');
                        }
                    } else {
                        $state['noticeType'] = 'success';
                        $state['noticeText'] = app_tr('تم تجهيز بيانات التفعيل للنسخ/الإرسال.', 'Activation package generated successfully.');
                    }
                }
            }
            return true;
        }

        return false;
    }
}
