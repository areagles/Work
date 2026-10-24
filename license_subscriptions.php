<?php
ob_start();
require 'auth.php';
require 'config.php';
require_once __DIR__ . '/modules/license/subscriptions_runtime.php';
require_once __DIR__ . '/modules/license/actions_runtime.php';
app_start_session();
app_handle_lang_switch($conn);

$licenseEdition = app_license_edition();
$isOwnerEdition = $licenseEdition === 'owner';
$isSuperUser = app_is_super_user();
$canManage = $isOwnerEdition && $isSuperUser;

if (!$canManage) {
    $superRef = app_super_user_reference();
    $currentUserId = (int)($_SESSION['user_id'] ?? 0);
    $currentUsername = strtolower(trim((string)($_SESSION['username'] ?? '')));
    $reason = !$isOwnerEdition
        ? app_tr(
            'الصفحة تعمل فقط في نسخة المالك. اضبط APP_LICENSE_EDITION=owner في ملف .app_env.',
            'This page works only in owner edition. Set APP_LICENSE_EDITION=owner in .app_env.'
        )
        : app_tr(
            'الحساب الحالي ليس Super User. عدّل APP_SUPER_USER_ID أو APP_SUPER_USER_USERNAME في .app_env ليطابق حسابك الحالي.',
            'Current account is not Super User. Update APP_SUPER_USER_ID or APP_SUPER_USER_USERNAME in .app_env to match your current account.'
        );
    $debugRef = app_tr('القيم الحالية', 'Current values');
    $debugText = sprintf(
        '%s: edition=%s | expected_id=%d | expected_username=%s | session_user_id=%d | session_username=%s',
        $debugRef,
        $licenseEdition,
        (int)($superRef['id'] ?? 0),
        (string)($superRef['username'] ?? ''),
        $currentUserId,
        $currentUsername
    );

    require 'header.php';
    echo "<div class='container' style='margin-top:24px;'><div style='background:#1d0f0f;border:1px solid #5b2020;border-radius:12px;padding:16px;color:#ffb3b3;'>"
        . app_h($reason)
        . "<hr style='border-color:#5b2020;margin:10px 0;'>"
        . "<small style='opacity:.95;word-break:break-word;'>" . app_h($debugText) . "</small>"
        . "</div></div>";
    require 'footer.php';
    exit;
}

app_initialize_license_management($conn);

$noticeType = '';
$noticeText = '';
$issuedResetInfo = [];
$issuedActivationInfo = [];
$generatedCredentialInfo = [];
$issuedLinkCodeInfo = [];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    app_require_csrf();
    $action = strtolower(trim((string)($_POST['action'] ?? '')));
    $lsActionState = [
        'noticeType' => &$noticeType,
        'noticeText' => &$noticeText,
        'issuedResetInfo' => &$issuedResetInfo,
        'issuedActivationInfo' => &$issuedActivationInfo,
    ];

    if ($action === 'save_license') {
        $extendDays = max(0, min(3650, (int)($_POST['extend_days'] ?? 0)));
        $extendTarget = strtolower(trim((string)($_POST['extend_target'] ?? 'auto')));
        $payload = [
            'id' => (int)($_POST['license_id'] ?? 0),
            'license_key' => trim((string)($_POST['license_key'] ?? '')),
            'api_token' => trim((string)($_POST['api_token'] ?? '')),
            'client_name' => trim((string)($_POST['client_name'] ?? '')),
            'client_email' => trim((string)($_POST['client_email'] ?? '')),
            'client_phone' => trim((string)($_POST['client_phone'] ?? '')),
            'plan_type' => trim((string)($_POST['plan_type'] ?? 'trial')),
            'status' => trim((string)($_POST['status'] ?? 'active')),
            'trial_ends_at' => ls_dt_db((string)($_POST['trial_ends_at'] ?? '')),
            'subscription_ends_at' => ls_dt_db((string)($_POST['subscription_ends_at'] ?? '')),
            'grace_days' => (int)($_POST['grace_days'] ?? 3),
            'allowed_domains' => trim((string)($_POST['allowed_domains'] ?? '')),
            'strict_installation' => isset($_POST['strict_installation']) ? 1 : 0,
            'max_installations' => (int)($_POST['max_installations'] ?? 1),
            'max_users' => (int)($_POST['max_users'] ?? 0),
            'notes' => trim((string)($_POST['notes'] ?? '')),
            'lock_reason' => trim((string)($_POST['lock_reason'] ?? '')),
        ];
        $saved = app_license_registry_save($conn, $payload);
        if (!empty($saved['ok'])) {
            $noticeType = 'success';
            $noticeText = app_tr('تم حفظ الاشتراك بنجاح.', 'Subscription saved successfully.');
            $savedId = (int)($saved['id'] ?? 0);
            if ($extendDays > 0 && $savedId > 0) {
                $ext = app_license_registry_extend_days($conn, $savedId, $extendDays, $extendTarget);
                if (!empty($ext['ok'])) {
                    $noticeText .= ' | ' . app_tr('تم التمديد بنجاح.', 'Extension applied.')
                        . ' +' . $extendDays . ' ' . app_tr('يوم', 'day(s)');
                } else {
                    $noticeType = 'error';
                    $noticeText = app_tr('تم الحفظ لكن فشل التمديد.', 'Saved but extension failed.')
                        . ' [' . app_h((string)($ext['error'] ?? 'unknown')) . ']';
                }
            }
            if ($noticeType !== 'error' && $savedId > 0) {
                $push = ls_try_push_license_credentials($conn, $savedId);
                $noticeText .= ' | ' . app_tr('تم تطبيق التغييرات.', 'Changes applied.');
                $noticeText .= ' | ' . app_tr('دفع الربط', 'Credential push')
                    . ': ' . (int)($push['pushed'] ?? 0)
                    . ' / ' . app_tr('فشل', 'Failed') . ': ' . (int)($push['failed'] ?? 0);
            }
        } else {
            $errCode = (string)($saved['error'] ?? 'unknown');
            $errHint = '';
            if ($errCode === 'invalid_subscription_ends_at') {
                $errHint = app_tr('اختر تاريخ نهاية صحيح للاشتراك أو اتركه فارغًا ليتم ضبط 30 يوم تلقائيًا.', 'Set a valid subscription end date or leave it empty for auto 30-day default.');
            } elseif ($errCode === 'super_user_required') {
                $errHint = app_tr('تحقق من APP_SUPER_USER_ID / APP_SUPER_USER_USERNAME وأنك داخل بحساب مطابق.', 'Check APP_SUPER_USER_ID / APP_SUPER_USER_USERNAME and login with the matching account.');
            }
            $noticeType = 'error';
            $noticeText = app_tr('فشل حفظ الاشتراك.', 'Failed to save subscription.') . ' [' . app_h($errCode) . ']'
                . ($errHint !== '' ? ' - ' . app_h($errHint) : '');
        }
    } elseif ($action === 'quick_generate_credentials') {
        $quickClientName = trim((string)($_POST['quick_client_name'] ?? ''));
        $quickPlan = strtolower(trim((string)($_POST['quick_plan_type'] ?? 'trial')));
        $quickLinkedSystem = strtolower(trim((string)($_POST['quick_linked_system'] ?? 'work')));
        if (!in_array($quickLinkedSystem, ['work', 'plast', 'sys'], true)) {
            $quickLinkedSystem = 'work';
        }
        $quickUsers = max(0, min(10000, (int)($_POST['quick_max_users'] ?? 0)));
        $quickInstallations = max(1, min(20, (int)($_POST['quick_max_installations'] ?? 1)));
        $quickDays = max(1, min(3650, (int)($_POST['quick_days'] ?? 30)));
        if (!in_array($quickPlan, ['trial', 'subscription', 'lifetime'], true)) {
            $quickPlan = 'trial';
        }
        if ($quickClientName === '') {
            $quickClientName = app_tr('عميل جديد', 'New Client');
        }
        $trialEnds = '';
        $subEnds = '';
        if ($quickPlan === 'trial') {
            $trialEnds = date('Y-m-d H:i:s', strtotime('+' . $quickDays . ' days'));
        } elseif ($quickPlan === 'subscription') {
            $subEnds = date('Y-m-d H:i:s', strtotime('+' . $quickDays . ' days'));
        }
        $saved = app_license_registry_save($conn, [
            'id' => 0,
            'license_key' => '',
            'api_token' => '',
            'client_name' => $quickClientName,
            'client_email' => '',
            'client_phone' => '',
            'plan_type' => $quickPlan,
            'status' => 'suspended',
            'trial_ends_at' => $trialEnds,
            'subscription_ends_at' => $subEnds,
            'grace_days' => 3,
            'allowed_domains' => '',
            'strict_installation' => 1,
            'max_installations' => $quickInstallations,
            'max_users' => $quickUsers,
            'notes' => app_tr('تم إنشاؤه من التوليد السريع.', 'Created from quick generator.'),
            'lock_reason' => app_tr('يتطلب اعتماد وربط أولي', 'Pending initial approval/binding'),
        ]);
        if (!empty($saved['ok'])) {
            $licenseId = (int)($saved['id'] ?? 0);
            $createdRow = $licenseId > 0 ? app_license_registry_get($conn, $licenseId) : [];
            $base = rtrim((string)app_base_url(), '/');
            $generatedCredentialInfo = [
                'license_id' => $licenseId,
                'license_key' => (string)($saved['license_key'] ?? ''),
                'api_token' => (string)($saved['api_token'] ?? ''),
                'remote_url' => $base . '/license_api.php',
                'alt_url' => $base . '/api/license/check/',
                'client_name' => (string)($createdRow['client_name'] ?? $quickClientName),
                'linked_system' => $quickLinkedSystem,
            ];
            $noticeType = 'success';
            $noticeText = app_tr('تم توليد الاشتراك بنجاح. انسخ بيانات التفعيل من كارت التوليد.', 'Subscription generated successfully. Copy activation credentials from the generator card.');
        } else {
            $noticeType = 'error';
            $noticeText = app_tr('فشل التوليد السريع.', 'Quick generation failed.')
                . ' [' . app_h((string)($saved['error'] ?? 'unknown')) . ']';
        }
    } elseif ($action === 'generate_link_package') {
        $licenseId = (int)($_POST['license_id'] ?? 0);
        $linkedSystem = strtolower(trim((string)($_POST['linked_system'] ?? 'work')));
        if (!in_array($linkedSystem, ['work', 'plast', 'sys'], true)) {
            $linkedSystem = 'work';
        }
        $licenseRow = $licenseId > 0 ? app_license_registry_get($conn, $licenseId) : [];
        if (empty($licenseRow)) {
            $noticeType = 'error';
            $noticeText = app_tr('الاشتراك غير موجود.', 'Subscription was not found.');
        } else {
            $apiToken = trim((string)($licenseRow['api_token'] ?? ''));
            if ($apiToken === '') {
                $rot = app_license_registry_rotate_api_token($conn, $licenseId);
                if (!empty($rot['ok'])) {
                    $apiToken = trim((string)($rot['api_token'] ?? ''));
                    $licenseRow['api_token'] = $apiToken;
                }
            }
            if ($apiToken === '') {
                $noticeType = 'error';
                $noticeText = app_tr('تعذر توليد API Token لهذا الاشتراك.', 'Could not generate API token for this subscription.');
            } else {
                $base = rtrim((string)app_base_url(), '/');
                $generatedCredentialInfo = [
                    'license_id' => $licenseId,
                    'license_key' => (string)($licenseRow['license_key'] ?? ''),
                    'api_token' => $apiToken,
                    'remote_url' => $base . '/license_api.php',
                    'alt_url' => $base . '/api/license/check/',
                    'client_name' => (string)($licenseRow['client_name'] ?? ''),
                    'linked_system' => $linkedSystem,
                ];
                $noticeType = 'success';
                $noticeText = app_tr('تم توليد بيانات الربط للاشتراك المحدد.', 'Link package generated for the selected subscription.');
            }
        }
    } elseif ($action === 'pause_license' || $action === 'activate_license' || $action === 'lock_license' || $action === 'unlock_license') {
        $licenseId = (int)($_POST['license_id'] ?? 0);
        $isPauseAction = in_array($action, ['pause_license', 'lock_license'], true);
        $reason = trim((string)($_POST['lock_reason'] ?? ''));
        $result = app_license_registry_set_status($conn, $licenseId, $isPauseAction ? 'suspended' : 'active', $reason);
        if (!empty($result['ok'])) {
            $noticeType = 'success';
            $noticeText = $isPauseAction
                ? app_tr('تم إيقاف الاشتراك مؤقتاً.', 'Subscription paused successfully.')
                : app_tr('تم تفعيل الاشتراك.', 'Subscription activated successfully.');
            if ($licenseId > 0) {
                $push = ls_try_push_license_credentials($conn, $licenseId);
                $noticeText .= ' | ' . app_tr('دفع الربط', 'Credential push')
                    . ': ' . (int)($push['pushed'] ?? 0)
                    . ' / ' . app_tr('فشل', 'Failed') . ': ' . (int)($push['failed'] ?? 0);
            }
            if (!$isPauseAction && !empty($result['auto_extended'])) {
                $noticeText .= ' | ' . app_tr('تم تمديد المدة تلقائياً عند التفعيل', 'Period was auto-extended on activation');
            }
        } else {
            $noticeType = 'error';
            $noticeText = app_tr('تعذر تنفيذ العملية.', 'Action failed.') . ' [' . app_h((string)($result['error'] ?? 'unknown')) . ']';
        }
    } elseif ($action === 'delete_license') {
        $licenseId = (int)($_POST['license_id'] ?? 0);
        $result = app_license_registry_delete($conn, $licenseId);
        if (!empty($result['ok'])) {
            $noticeType = 'success';
            $noticeText = app_tr('تم حذف الاشتراك والبيانات المرتبطة به.', 'Subscription and related data deleted.');
        } else {
            $noticeType = 'error';
            $noticeText = app_tr('فشل حذف الاشتراك.', 'Failed to delete subscription.') . ' [' . app_h((string)($result['error'] ?? 'unknown')) . ']';
        }
    } elseif ($action === 'delete_all_licenses') {
        $result = app_license_registry_delete_all($conn);
        if (!empty($result['ok'])) {
            $noticeType = 'success';
            $noticeText = app_tr('تم حذف كل الاشتراكات وبياناتها.', 'All subscriptions and related data were deleted.')
                . ' (' . (int)($result['deleted'] ?? 0) . ')';
        } else {
            $noticeType = 'error';
            $noticeText = app_tr('فشل حذف كل الاشتراكات.', 'Failed to delete all subscriptions.') . ' [' . app_h((string)($result['error'] ?? 'unknown')) . ']';
        }
    } elseif ($action === 'read_alert') {
        app_license_alert_mark_read($conn, (int)($_POST['alert_id'] ?? 0));
    } elseif ($action === 'read_all_alerts') {
        app_license_alert_mark_all_read($conn);
    } elseif ($action === 'delete_alert') {
        $ok = app_license_alert_delete($conn, (int)($_POST['alert_id'] ?? 0));
        $noticeType = $ok ? 'success' : 'error';
        $noticeText = $ok
            ? app_tr('تم حذف التنبيه.', 'Alert deleted.')
            : app_tr('فشل حذف التنبيه.', 'Failed to delete alert.');
    } elseif ($action === 'delete_read_alerts') {
        $deleted = app_license_alert_delete_all($conn, true);
        $noticeType = 'success';
        $noticeText = app_tr('تم حذف التنبيهات المقروءة.', 'Read alerts deleted.') . ' (' . $deleted . ')';
    } elseif ($action === 'delete_all_alerts') {
        $deleted = app_license_alert_delete_all($conn, false);
        $noticeType = 'success';
        $noticeText = app_tr('تم حذف كل التنبيهات.', 'All alerts deleted.') . ' (' . $deleted . ')';
    } elseif ($action === 'delete_runtime') {
        $ok = app_license_runtime_delete($conn, (int)($_POST['runtime_id'] ?? 0));
        $noticeType = $ok ? 'success' : 'error';
        $noticeText = $ok
            ? app_tr('تم حذف سجل التشغيل.', 'Runtime log entry deleted.')
            : app_tr('فشل حذف سجل التشغيل.', 'Failed to delete runtime log entry.');
    } elseif ($action === 'block_runtime') {
        $runtimeId = (int)($_POST['runtime_id'] ?? 0);
        $reason = trim((string)($_POST['block_reason'] ?? ''));
        if ($reason === '') {
            $reason = app_tr('تم الحظر من نظام المالك', 'Blocked by owner');
        }
        $blocked = app_license_runtime_block_from_log($conn, $runtimeId, $reason);
        if (!empty($blocked['ok'])) {
            $noticeType = 'success';
            $noticeText = app_tr('تم حظر النظام وإزالة أي أكواد ربط وسجلات هوية مرتبطة به.', 'Client system was blocked and any link codes / identity records were destroyed.');
        } else {
            $noticeType = 'error';
            $noticeText = app_tr('فشل تنفيذ الحظر.', 'Blocking failed.') . ' [' . app_h((string)($blocked['error'] ?? 'unknown')) . ']';
        }
    } elseif ($action === 'unblock_runtime') {
        $blockId = (int)($_POST['block_id'] ?? 0);
        $ok = app_license_blocked_runtime_release($conn, $blockId);
        $noticeType = $ok ? 'success' : 'error';
        $noticeText = $ok
            ? app_tr('تم فك الحظر بنجاح.', 'Block removed successfully.')
            : app_tr('فشل فك الحظر.', 'Failed to remove block.');
    } elseif ($action === 'delete_all_runtime') {
        $deleted = app_license_runtime_delete_all($conn);
        $noticeType = 'success';
        $noticeText = app_tr('تم حذف سجل تشغيل الأنظمة بالكامل.', 'All runtime logs deleted.') . ' (' . $deleted . ')';
    } elseif ($action === 'activate_runtime') {
        $runtimeId = (int)($_POST['runtime_id'] ?? 0);
        if ($runtimeId <= 0) {
            $noticeType = 'error';
            $noticeText = app_tr('سجل التشغيل غير صالح.', 'Invalid runtime row.');
        } else {
            $stmt = $conn->prepare("SELECT * FROM app_license_runtime_log WHERE id = ? LIMIT 1");
            $stmt->bind_param('i', $runtimeId);
            $stmt->execute();
            $runtimeRow = $stmt->get_result()->fetch_assoc() ?: [];
            $stmt->close();

            if (empty($runtimeRow)) {
                $noticeType = 'error';
                $noticeText = app_tr('سجل التشغيل غير موجود.', 'Runtime row was not found.');
            } else {
                $runtimeBoundId = max((int)($runtimeRow['license_id'] ?? 0), (int)($runtimeRow['linked_license_id'] ?? 0));
                $targetLicenseId = $runtimeBoundId;
                if ($targetLicenseId <= 0) {
                    $licensesForActivation = app_license_registry_list($conn, 600);
                    $targetLicenseId = ls_runtime_pick_license_id($runtimeRow, $licensesForActivation);
                }

                if ($targetLicenseId <= 0) {
                    $noticeType = 'error';
                    $noticeText = app_tr('لا يوجد اشتراك مطابق لهذا النظام بعد. أنشئ الاشتراك أولاً ثم اضغط تفعيل.', 'No matching subscription exists for this system yet. Create the subscription first, then activate.');
                } else {
                    $bind = app_license_runtime_bind_from_log(
                        $conn,
                        $runtimeId,
                        $targetLicenseId,
                        'owner_manual_activation',
                        true
                    );
                    if (empty($bind['ok'])) {
                        $noticeType = 'error';
                        $noticeText = app_tr('فشل تفعيل النظام من المالك.', 'Owner activation failed.')
                            . ' [' . app_h((string)($bind['error'] ?? 'unknown')) . ']';
                    } else {
                        $push = ls_try_push_license_credentials($conn, $targetLicenseId);
                        $noticeType = 'success';
                        $noticeText = app_tr('تم اعتماد النظام وحقن بيانات التفعيل من نظام المالك.', 'System was approved and activation data was injected from owner.')
                            . ' | ' . app_tr('دفع الربط', 'Credential push')
                            . ': ' . (int)($push['pushed'] ?? 0)
                            . ' / ' . app_tr('فشل', 'Failed') . ': ' . (int)($push['failed'] ?? 0);
                    }
                }
            }
        }
    } elseif ($action === 'smart_link_runtime' || $action === 'smart_link_all_runtimes') {
        $noticeType = 'error';
        $noticeText = app_tr('تم إيقاف الربط الذكي التلقائي. استخدم زر التفعيل اليدوي من نظام المالك.', 'Automatic smart linking has been disabled. Use the manual activation button from owner system.');
    } elseif ($action === 'extend_license_days') {
        $licenseId = (int)($_POST['license_id'] ?? 0);
        $days = max(0, min(3650, (int)($_POST['extend_days'] ?? 0)));
        $target = strtolower(trim((string)($_POST['extend_target'] ?? 'auto')));
        $result = app_license_registry_extend_days($conn, $licenseId, $days, $target);
        if (!empty($result['ok'])) {
            $noticeType = 'success';
            $noticeText = app_tr('تم تمديد الاشتراك/التجربة.', 'License period extended.')
                . ' +' . $days . ' ' . app_tr('يوم', 'day(s)');
            if ($licenseId > 0) {
                $push = ls_try_push_license_credentials($conn, $licenseId);
                $noticeText .= ' | ' . app_tr('دفع الربط', 'Credential push')
                    . ': ' . (int)($push['pushed'] ?? 0)
                    . ' / ' . app_tr('فشل', 'Failed') . ': ' . (int)($push['failed'] ?? 0);
            }
        } else {
            $noticeType = 'error';
            $noticeText = app_tr('فشل التمديد.', 'Extension failed.')
                . ' [' . app_h((string)($result['error'] ?? 'unknown')) . ']';
        }
    } elseif ($action === 'create_from_runtime') {
        $runtimeId = (int)($_POST['runtime_id'] ?? 0);
        $trialDays = max(1, min(365, (int)($_POST['trial_days'] ?? 14)));
        if ($runtimeId <= 0) {
            $noticeType = 'error';
            $noticeText = app_tr('سجل تشغيل غير صالح.', 'Invalid runtime row.');
        } else {
            $stmt = $conn->prepare("SELECT * FROM app_license_runtime_log WHERE id = ? LIMIT 1");
            $stmt->bind_param('i', $runtimeId);
            $stmt->execute();
            $runtimeRow = $stmt->get_result()->fetch_assoc() ?: [];
            $stmt->close();

            $runtimeKey = strtoupper(trim((string)($runtimeRow['license_key'] ?? '')));
            $runtimeDomain = trim((string)($runtimeRow['domain'] ?? ''));
            if ($runtimeKey === '') {
                $noticeType = 'error';
                $noticeText = app_tr('السجل لا يحتوي مفتاح ترخيص.', 'Runtime row has no license key.');
            } else {
                $existing = app_license_registry_by_key($conn, $runtimeKey);
                if (!empty($existing)) {
                    $noticeType = 'success';
                    $noticeText = app_tr('الاشتراك موجود بالفعل، يمكنك تعديله مباشرة.', 'Subscription already exists, you can edit it now.');
                } else {
                    $payload = [
                        'id' => 0,
                        'license_key' => $runtimeKey,
                        'client_name' => $runtimeDomain !== '' ? $runtimeDomain : ('Client ' . substr($runtimeKey, -6)),
                        'client_email' => '',
                        'client_phone' => '',
                        'plan_type' => 'trial',
                        'status' => 'active',
                        'trial_ends_at' => date('Y-m-d H:i:s', strtotime('+' . $trialDays . ' days')),
                        'subscription_ends_at' => '',
                        'grace_days' => 3,
                        'allowed_domains' => $runtimeDomain !== '' ? $runtimeDomain : '',
                        'strict_installation' => 1,
                        'max_installations' => 1,
                        'max_users' => 0,
                        'api_token' => '',
                        'notes' => 'Auto-created from runtime #' . $runtimeId,
                        'lock_reason' => '',
                    ];
                    $created = app_license_registry_save($conn, $payload);
                    if (!empty($created['ok'])) {
                        $noticeType = 'success';
                        $noticeText = app_tr('تم إنشاء اشتراك جديد من سجل التشغيل.', 'New subscription created from runtime log.');
                    } else {
                        $noticeType = 'error';
                        $noticeText = app_tr('فشل إنشاء الاشتراك من السجل.', 'Failed to create subscription from runtime.')
                            . ' [' . app_h((string)($created['error'] ?? 'unknown')) . ']';
                    }
                }
            }
        }
    } elseif ($action === 'link_runtime_to_license') {
        $runtimeId = (int)($_POST['runtime_id'] ?? 0);
        $targetLicenseId = (int)($_POST['target_license_id'] ?? 0);
        $bindNotes = trim((string)($_POST['bind_notes'] ?? ''));
        $activateAfterLink = ((string)($_POST['activate_after_link'] ?? '') === '1');
        $result = app_license_runtime_bind_from_log($conn, $runtimeId, $targetLicenseId, $bindNotes, $activateAfterLink);
        if (!empty($result['ok'])) {
            $noticeType = 'success';
            $noticeText = app_tr('تم ربط الجهاز بالاشتراك بنجاح.', 'Runtime/device linked to subscription successfully.')
                . ' [' . app_h((string)($result['linked_license_key'] ?? '')) . ']';
            if ($activateAfterLink) {
                $noticeText .= ' | ' . app_tr('تم تفعيل الاشتراك بعد الربط.', 'Subscription activated after linking.');
            }
            $noticeText .= ' | ' . app_tr('لا حاجة للدفع اليدوي: النظام يعمل الآن بنمط Pull-only.', 'No manual push needed: system now runs in pull-only mode.');
        } else {
            $noticeType = 'error';
            $noticeText = app_tr('فشل ربط الجهاز بالاشتراك.', 'Failed to link runtime/device to subscription.')
                . ' [' . app_h((string)($result['error'] ?? 'unknown')) . ']';
        }
    } elseif ($action === 'issue_link_code') {
        $runtimeId = (int)($_POST['runtime_id'] ?? 0);
        $targetLicenseId = (int)($_POST['target_license_id'] ?? 0);
        $sendChannel = strtolower(trim((string)($_POST['send_channel'] ?? 'generate')));
        if (!in_array($sendChannel, ['generate', 'email_auto', 'whatsapp_open'], true)) {
            $sendChannel = 'generate';
        }
        $noticeType = 'success';
        $noticeText = app_tr('تم إيقاف رموز الربط في الإصدار الجديد. استخدم مفتاح الترخيص + API Token من الاشتراك، والربط سيتم تلقائياً عبر Pull-only.', 'Link codes are deprecated in the new architecture. Use license key + API token from subscription; binding is now automatic via pull-only sync.');
    } elseif ($action === 'pull_client_snapshot') {
        $runtimeId = (int)($_POST['runtime_id'] ?? 0);
        $targetLicenseId = (int)($_POST['target_license_id'] ?? 0);
        if ($runtimeId <= 0 || $targetLicenseId <= 0) {
            $noticeType = 'error';
            $noticeText = app_tr('بيانات السجل/الاشتراك غير صالحة.', 'Invalid runtime/subscription selection.');
        } else {
            $stmt = $conn->prepare("SELECT * FROM app_license_runtime_log WHERE id = ? LIMIT 1");
            $stmt->bind_param('i', $runtimeId);
            $stmt->execute();
            $runtimeRow = $stmt->get_result()->fetch_assoc() ?: [];
            $stmt->close();

            if (empty($runtimeRow)) {
                $noticeType = 'error';
                $noticeText = app_tr('سجل تشغيل غير موجود.', 'Runtime row was not found.');
            } else {
                $appUrl = trim((string)($runtimeRow['app_url'] ?? ''));
                $domain = trim((string)($runtimeRow['domain'] ?? ''));
                $remoteUrl = $appUrl;
                if (function_exists('app_license_url_looks_placeholder') && app_license_url_looks_placeholder($remoteUrl)) {
                    $remoteUrl = '';
                }
                if ($remoteUrl === '' && $domain !== '' && (!function_exists('app_license_is_placeholder_remote_host') || !app_license_is_placeholder_remote_host($domain))) {
                    $remoteUrl = 'https://' . $domain;
                }
                $installationId = trim((string)($runtimeRow['installation_id'] ?? ''));
                $fingerprint = trim((string)($runtimeRow['fingerprint'] ?? ''));

                $result = app_support_owner_pull_client_snapshot(
                    $conn,
                    $targetLicenseId,
                    $remoteUrl,
                    $installationId,
                    $fingerprint,
                    $domain,
                    $appUrl
                );
                if (!empty($result['ok'])) {
                    $noticeType = 'success';
                    $noticeText = app_tr('تم سحب تقرير النظام والمستخدمين بنجاح.', 'System/users snapshot pulled successfully.')
                        . ' (' . app_h(app_tr('المستخدمون', 'Users')) . ': ' . (int)($result['users_count'] ?? 0) . ')';
                } elseif (in_array((string)($result['error'] ?? ''), ['client_private_url_unreachable_from_owner', 'client_url_missing'], true)) {
                    $noticeType = 'success';
                    $noticeText = app_tr('تم الربط/التفعيل بنجاح، لكن Snapshot المباشر مؤجل لأن عنوان العميل محلي. سيتم الاعتماد على التقارير الواردة تلقائياً من العميل.', 'Link/activation succeeded, but direct snapshot is deferred because client URL is local/private. Owner will use automatically pushed client reports.');
                } else {
                    $noticeType = 'error';
                    $pullErr = (string)($result['error'] ?? 'unknown');
                    $noticeText = app_tr('تعذر سحب Snapshot من النظام العميل.', 'Failed to pull client snapshot.')
                        . ' [' . app_h(ls_remote_error_text($pullErr)) . ']';
                }
            }
        }
    } elseif (ls_handle_remote_post_actions($conn, $action, $lsActionState)) {
    }
}

$editId = max(0, (int)($_GET['edit'] ?? 0));
$editRow = $editId > 0 ? app_license_registry_get($conn, $editId) : [];
$licenses = app_license_registry_list($conn, 400);
$alerts = app_license_alert_recent($conn, 60);
$runtime = app_license_runtime_recent($conn, 80);
$blockedRuntimeRows = app_license_blocked_runtime_list($conn, true, 160);
$unreadAlerts = app_license_alert_unread_count($conn);
$supportReports = app_support_owner_reports_list($conn, 240);
$supportReportsCount = count($supportReports);
$selectedReportId = max(
    0,
    (int)($_POST['report_id'] ?? 0),
    (int)($_GET['report_id'] ?? 0),
    (int)($_GET['report'] ?? 0)
);
if ($selectedReportId <= 0 && !empty($supportReports)) {
    $selectedReportId = (int)($supportReports[0]['id'] ?? 0);
}
$selectedSupportReport = $selectedReportId > 0 ? app_support_owner_report_get($conn, $selectedReportId) : [];
$selectedSupportUsers = !empty($selectedSupportReport) ? app_support_owner_report_users($conn, (int)$selectedSupportReport['id'], 1200) : [];
$lastAction = isset($action) ? strtolower(trim((string)$action)) : '';
$advancedActions = [
    'read_all_alerts', 'delete_read_alerts', 'delete_all_alerts', 'read_alert', 'delete_alert',
    'delete_support_report', 'delete_reports_for_license', 'issue_user_reset_link',
    'remote_create_user', 'remote_update_user', 'remote_set_password', 'remote_delete_user',
    'delete_runtime', 'delete_all_runtime', 'create_from_runtime', 'link_runtime_to_license', 'pull_client_snapshot'
];
$openAdvancedByDefault = $unreadAlerts > 0
    || !empty($selectedSupportReport)
    || in_array($lastAction, $advancedActions, true);
$pendingRuntimeRows = 0;
$pendingRuntimeList = [];
$pendingRuntimeSeen = [];
foreach ($runtime as $rtRow) {
    $runtimeBoundId = max((int)($rtRow['license_id'] ?? 0), (int)($rtRow['linked_license_id'] ?? 0));
    $rtLicenseKey = trim((string)($rtRow['license_key'] ?? ''));
    $rtDomain = trim((string)($rtRow['domain'] ?? ''));
    $rtInstall = trim((string)($rtRow['installation_id'] ?? ''));
    $rtFp = trim((string)($rtRow['fingerprint'] ?? ''));
    $blockHit = app_license_blocked_runtime_match($conn, $rtDomain, (string)($rtRow['app_url'] ?? ''), $rtInstall, $rtFp, $rtLicenseKey);
    if ($runtimeBoundId <= 0 && $rtLicenseKey !== '' && empty($blockHit)) {
        $pendingRuntimeRows++;
        $identityKey = $rtInstall . '|' . $rtFp;
        if ($identityKey === '|') {
            $identityKey = strtoupper($rtLicenseKey) . '|' . strtolower($rtDomain);
        }
        if (!isset($pendingRuntimeSeen[$identityKey])) {
            $pendingRuntimeSeen[$identityKey] = true;
            $pendingRuntimeList[] = $rtRow;
        }
    }
}
$runtimeHealthyRows = 0;
foreach ($runtime as $rtRow) {
    if (max((int)($rtRow['license_id'] ?? 0), (int)($rtRow['linked_license_id'] ?? 0)) > 0) {
        $runtimeHealthyRows++;
    }
}
$showAdvancedAlerts = $unreadAlerts > 0;
$showSupportReports = true;
$showRuntimeOps = false;
$showPendingRuntimeOps = !empty($pendingRuntimeList);

$totalLicenses = count($licenses);
$activeCount = 0;
$suspendedCount = 0;
$expiredCount = 0;
$trialPlanCount = 0;
$subscriptionPlanCount = 0;
$lifetimePlanCount = 0;
$expiringSoonCount = 0;
foreach ($licenses as $l) {
    $st = strtolower(trim((string)($l['status'] ?? 'active')));
    $planType = strtolower(trim((string)($l['plan_type'] ?? 'trial')));
    if ($planType === 'subscription') {
        $subscriptionPlanCount++;
    } elseif ($planType === 'lifetime') {
        $lifetimePlanCount++;
    } else {
        $trialPlanCount++;
    }
    if ($st === 'suspended') {
        $suspendedCount++;
    } elseif ($st === 'expired') {
        $expiredCount++;
    } else {
        $activeCount++;
    }
    $state = app_license_registry_effective_state($l);
    $expiryValue = '';
    if ((string)($state['plan'] ?? '') === 'trial') {
        $expiryValue = (string)($state['trial_ends_at'] ?? '');
    } elseif ((string)($state['plan'] ?? '') === 'subscription') {
        $expiryValue = (string)($state['subscription_ends_at'] ?? '');
    }
    $expiryTs = $expiryValue !== '' ? strtotime($expiryValue) : false;
    if ($expiryTs !== false && $expiryTs >= time() && $expiryTs <= strtotime('+7 days')) {
        $expiringSoonCount++;
    }
}
$currentRuntimeProfileLabel = app_runtime_profile_label();

require 'header.php';
?>
<style>
    .ls-wrap { max-width: 1380px; margin: 14px auto 44px; padding: 0 12px; }
    .ls-wrap .content-card {
        overflow: visible;
        background:
            radial-gradient(circle at top right, rgba(212,175,55,.08), transparent 18%),
            radial-gradient(circle at left center, rgba(33,201,255,.08), transparent 22%),
            linear-gradient(180deg, rgba(8,11,17,.98), rgba(11,15,24,.98));
        border: 1px solid rgba(58,65,82,.8);
        box-shadow: 0 22px 60px rgba(0,0,0,.35);
    }
    .ls-hero {
        position: relative;
        overflow: hidden;
        border: 1px solid rgba(71,79,98,.75);
        border-radius: 24px;
        padding: 22px;
        margin-bottom: 16px;
        background:
            linear-gradient(135deg, rgba(212,175,55,.12), transparent 28%),
            linear-gradient(160deg, rgba(35,43,62,.95), rgba(9,13,20,.98));
    }
    .ls-hero::before {
        content:'';
        position:absolute;
        inset:0;
        background:
            repeating-linear-gradient(90deg, transparent 0 46px, rgba(255,255,255,.03) 46px 47px),
            repeating-linear-gradient(0deg, transparent 0 46px, rgba(255,255,255,.03) 46px 47px);
        opacity:.35;
        pointer-events:none;
    }
    .ls-hero-row {
        position: relative;
        z-index: 1;
        display:grid;
        grid-template-columns: 1.25fr .75fr;
        gap: 16px;
        align-items:start;
    }
    .ls-hero-title {
        margin:0;
        color:#f3f6fb;
        font-size:2rem;
        font-weight:900;
        letter-spacing:.01em;
    }
    .ls-hero-sub {
        margin:8px 0 0;
        color:#9eabc2;
        max-width:760px;
        line-height:1.8;
    }
    .ls-signal {
        display:flex;
        gap:10px;
        flex-wrap:wrap;
        margin-top:14px;
    }
    .ls-signal-chip {
        display:inline-flex;
        align-items:center;
        gap:8px;
        padding:9px 14px;
        border-radius:999px;
        border:1px solid rgba(83,95,119,.8);
        background:rgba(8,13,22,.78);
        color:#e8edf7;
        font-weight:800;
        font-size:.88rem;
    }
    .ls-signal-chip .dot {
        width:9px;
        height:9px;
        border-radius:50%;
        background:#21c9ff;
        box-shadow:0 0 0 4px rgba(33,201,255,.14);
    }
    .ls-signal-chip.gold .dot { background:#d4af37; box-shadow:0 0 0 4px rgba(212,175,55,.16); }
    .ls-signal-chip.green .dot { background:#63e39b; box-shadow:0 0 0 4px rgba(99,227,155,.16); }
    .ls-hero-aside {
        position:relative;
        z-index:1;
        display:grid;
        gap:10px;
    }
    .ls-hero-panel {
        padding:14px 16px;
        border-radius:18px;
        border:1px solid rgba(67,78,100,.8);
        background:rgba(8,12,20,.82);
    }
    .ls-hero-panel .label {
        display:block;
        color:#8e9bb1;
        font-size:.78rem;
        margin-bottom:6px;
    }
    .ls-hero-panel .value {
        color:#f4f7fc;
        font-size:1.08rem;
        font-weight:900;
        word-break:break-word;
    }
    .ls-command-grid {
        display:grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap:12px;
        margin-bottom:14px;
    }
    .ls-command {
        position:relative;
        overflow:hidden;
        border-radius:18px;
        padding:16px;
        background:
            linear-gradient(180deg, rgba(16,20,29,.98), rgba(10,13,19,.98));
        border:1px solid rgba(59,68,86,.85);
        min-height:130px;
    }
    .ls-command::after {
        content:'';
        position:absolute;
        inset:auto 0 0 0;
        height:3px;
        background:linear-gradient(90deg, rgba(212,175,55,0), rgba(212,175,55,.9), rgba(33,201,255,.7));
        opacity:.9;
    }
    .ls-command .eyebrow { color:#90a0ba; font-size:.76rem; margin-bottom:8px; display:block; }
    .ls-command .big { color:#fff; font-size:1.7rem; font-weight:900; }
    .ls-command .note { color:#9aa7bd; font-size:.84rem; margin-top:8px; line-height:1.55; }
    .ls-grid { display: grid; gap: 12px; grid-template-columns: repeat(4, minmax(0, 1fr)); margin-bottom: 14px; }
    .ls-stat {
        background: linear-gradient(165deg, rgba(19,20,26,.98), rgba(16,17,22,.98));
        border: 1px solid rgba(57,62,74,.9);
        border-radius: 16px;
        padding: 16px;
        position:relative;
        overflow:hidden;
    }
    .ls-stat::before{
        content:'';
        position:absolute;
        inset:0 auto auto 0;
        width:100%;
        height:1px;
        background:linear-gradient(90deg, rgba(212,175,55,.85), rgba(33,201,255,.1));
    }
    .ls-stat .k { color: #98a0af; font-size: 0.84rem; }
    .ls-stat .v { font-size: 1.5rem; font-weight: 900; color: #f2f2f2; margin-top: 4px; }

    .ls-two {
        display: grid;
        grid-template-columns: minmax(340px, 0.9fr) minmax(460px, 1.1fr);
        gap: 14px;
        margin-bottom: 14px;
        align-items: start;
    }
    .ls-topbar{
        display:flex;
        align-items:flex-start;
        justify-content:space-between;
        gap:16px;
        margin-bottom:14px;
        padding:16px 18px;
        border:1px solid rgba(212,175,55,.24);
        border-radius:16px;
        background:linear-gradient(180deg,#10131a,#0d1016);
    }
    .ls-topbar h2{
        margin:0 0 6px;
        color:#f5f7fb;
        font-size:1.45rem;
    }
    .ls-topbar p{
        margin:0;
        color:#9ea9bd;
        line-height:1.75;
        font-size:.92rem;
        max-width:760px;
    }
    .ls-topbar-stats{
        display:flex;
        flex-wrap:wrap;
        gap:10px;
        justify-content:flex-end;
    }
    .ls-top-pill{
        display:inline-flex;
        align-items:center;
        gap:8px;
        padding:10px 14px;
        border-radius:999px;
        border:1px solid #374057;
        background:#111722;
        color:#f2f5fb;
        font-size:.83rem;
        font-weight:800;
        white-space:nowrap;
    }
    .ls-top-pill strong{color:#d4af37}
    .ls-workbench{
        display:grid;
        grid-template-columns:1fr;
        gap:14px;
        margin-bottom:14px;
        align-items:start;
    }
    .ls-stack{
        display:grid;
        gap:14px;
        align-items:start;
    }
    .ls-card {
        background: linear-gradient(165deg, #11131a, #0f1016);
        border: 1px solid #2d3140;
        border-radius: 18px;
        padding: 16px;
        overflow: visible;
        box-shadow: 0 16px 32px rgba(0,0,0,0.28);
    }
    .ls-card h3 {
        margin: 0;
        color: #d4af37;
        font-size: 1.12rem;
        font-weight: 900;
    }
    .ls-head {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
        margin-bottom: 12px;
        flex-wrap: wrap;
    }
    .ls-toolbar { display: flex; gap: 8px; flex-wrap: wrap; }

    .ls-form { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; align-items: start; }
    .ls-form .full { grid-column: 1 / -1; }
    .ls-form label { display: block; color: #c2c7d4; font-size: 0.86rem; margin-bottom: 5px; font-weight: 700; }
    .ls-form input, .ls-form select, .ls-form textarea {
        width: 100%;
        border: 1px solid #3a3f50;
        border-radius: 11px;
        background: #090b12;
        color: #fff;
        min-height: 42px;
        padding: 8px 11px;
        line-height: 1.45;
        font-family: inherit;
        font-size: 0.95rem;
    }
    .ls-form textarea { min-height: 92px; resize: vertical; }

    .ls-check {
        display: flex;
        align-items: flex-start;
        gap: 9px;
        line-height: 1.45;
        color: #d4dae5;
        font-size: 0.9rem;
    }
    .ls-check input[type="checkbox"] {
        width: 18px;
        height: 18px;
        min-height: 18px;
        margin-top: 2px;
        flex: 0 0 18px;
        accent-color: #d4af37;
        border-radius: 4px;
    }

    .ls-actions { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 10px; }
    .ls-actions.compact { margin-top: 6px; }
    .ls-actions > form { margin: 0; }
    .ls-inline-compact {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        flex-wrap: nowrap;
    }
    .ls-inline-compact input,
    .ls-inline-compact select {
        min-height: 34px;
        height: 34px;
        padding: 4px 8px;
        border-radius: 9px;
        border: 1px solid #3d4355;
        background: #0a0d14;
        color: #fff;
        font-size: 0.84rem;
    }
    .ls-inline-compact input { width: 78px; }
    .ls-btn {
        border: 1px solid transparent;
        border-radius: 11px;
        padding: 9px 12px;
        min-height: 38px;
        font-weight: 800;
        line-height: 1.1;
        white-space: nowrap;
        cursor: pointer;
        font-family: inherit;
    }
    .ls-btn.gold { background: #d4af37; color: #111; border-color: rgba(212, 175, 55, 0.75); }
    .ls-btn.dark { background: #232733; color: #f3f5fb; border-color: #3d4355; }
    .ls-btn.red { background: #612326; color: #ffd7d7; border-color: #8a3a40; }
    .ls-btn.green { background: #18492e; color: #baf5cb; border-color: #2a7a4d; }
    .ls-btn.ice { background:#13293b; color:#b8ebff; border-color:#2c5a76; }

    .ls-notice { border-radius: 11px; padding: 10px 12px; margin-bottom: 10px; border: 1px solid transparent; }
    .ls-ok { background: rgba(46,178,93,.16); border-color: rgba(46,178,93,.4); color: #9df0bc; }
    .ls-err { background: rgba(200,70,70,.2); border-color: rgba(200,70,70,.45); color: #ffc1c1; }

    .ls-table-wrap {
        overflow: auto;
        border: 1px solid #2b3140;
        border-radius: 16px;
        background: #0f121a;
        scrollbar-width: thin;
    }
    .ls-table { width: 100%; border-collapse: collapse; min-width: 980px; }
    .ls-table th, .ls-table td { border-bottom: 1px solid #2d3140; padding: 10px 8px; text-align: start; vertical-align: top; }
    .ls-table th { color: #b7bfd0; font-size: 0.84rem; font-weight: 800; background: #131723; position: sticky; top: 0; z-index: 1; }
    .ls-table tbody tr{transition:transform .18s ease, background .18s ease}
    .ls-table tbody tr:hover{background:#141b28; transform:translateY(-1px)}
    .ls-table td.actions,
    .ls-user-table td.actions { min-width: 280px; }
    .ls-table code { color: #d3d7e3; font-size: 0.82rem; word-break: break-all; }
    .st-active { color: #78e7a4; font-weight: 800; }
    .st-suspended { color: #ff9f9f; font-weight: 800; }
    .st-expired { color: #ffd18b; font-weight: 800; }
    .ls-mini { font-size: 0.81rem; color: #9ea4af; word-break: break-word; }
    .ls-pill{
        display:inline-flex;align-items:center;gap:8px;
        padding:6px 10px;border-radius:999px;border:1px solid #39445b;background:#111824;
        font-size:.8rem;font-weight:800
    }
    .ls-pill.active{color:#8df0b2;border-color:rgba(80,201,130,.45)}
    .ls-pill.suspended{color:#ffc1c1;border-color:rgba(200,80,80,.45)}
    .ls-pill.expired{color:#ffd495;border-color:rgba(212,175,55,.45)}

    .ls-alert {
        border: 1px solid #32384a;
        border-radius: 11px;
        padding: 10px;
        margin-bottom: 8px;
        background: #101521;
    }
    .ls-alert.unread { border-color: #d4af37; background: #1a1812; }
    .ls-alert-head { display: flex; justify-content: space-between; gap: 8px; align-items: center; }
    .ls-alert-title { font-weight: 800; color: #f6f7fb; }
    .bidi-auto { unicode-bidi: plaintext; }
    .ls-empty { color: #8f96a8; padding: 16px 10px; }
    .ls-advanced {
        margin: 14px 0;
        border: 1px solid rgba(212, 175, 55, 0.2);
        border-radius: 12px;
        background: rgba(9, 12, 20, 0.55);
    }
    .ls-advanced > summary {
        cursor: pointer;
        list-style: none;
        padding: 11px 12px;
        color: #d4af37;
        font-weight: 900;
        user-select: none;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
    }
    .ls-advanced > summary::-webkit-details-marker { display: none; }
    .ls-advanced > summary::after {
        content: '+';
        color: #f3d26b;
        font-size: 1.05rem;
        font-weight: 900;
    }
    .ls-advanced[open] > summary::after { content: '−'; }
    .ls-advanced-body {
        border-top: 1px dashed rgba(212, 175, 55, 0.24);
        padding: 12px;
    }
    .ls-quick-tools {
        display: flex;
        gap: 8px;
        flex-wrap: wrap;
        margin-bottom: 12px;
        align-items:center;
    }
    .ls-filter-row {
        display: grid;
        grid-template-columns: 1fr 180px 180px;
        gap: 8px;
        margin-bottom: 10px;
    }
    .ls-filter-row input,
    .ls-filter-row select {
        border: 1px solid #3a3f50;
        border-radius: 11px;
        background: #090b12;
        color: #fff;
        min-height: 40px;
        padding: 7px 10px;
        font-family: inherit;
    }
    .ls-summary-grid {
        display:grid;
        grid-template-columns:repeat(4,minmax(0,1fr));
        gap:12px;
        margin:14px 0 0;
    }
    .ls-summary-card {
        padding:14px 16px;
        border-radius:16px;
        border:1px solid rgba(61,72,95,.75);
        background:rgba(9,13,21,.78);
    }
    .ls-summary-card .k {
        display:block;
        color:#98a4b8;
        font-size:.8rem;
        margin-bottom:8px;
    }
    .ls-summary-card .v {
        display:block;
        color:#fff;
        font-size:1.35rem;
        font-weight:900;
    }
    .ls-summary-card .m {
        display:block;
        margin-top:6px;
        color:#9ea7b8;
        font-size:.82rem;
        line-height:1.7;
    }
    .ls-inline-pills {
        display:flex;
        gap:8px;
        flex-wrap:wrap;
        margin-top:12px;
    }
    .ls-report-grid {
        display: grid;
        grid-template-columns: minmax(300px, 0.9fr) minmax(460px, 1.1fr);
        gap: 14px;
        margin-bottom: 14px;
        align-items: start;
    }
    .ls-report-list {
        max-height: 560px;
        overflow: auto;
        border: 1px solid #2b3140;
        border-radius: 12px;
        padding: 8px;
        background: #0f121a;
    }
    .ls-report-item {
        display: block;
        border: 1px solid #32384a;
        border-radius: 10px;
        padding: 10px;
        margin-bottom: 8px;
        color: #e6e8ef;
        text-decoration: none;
        background: #131823;
    }
    .ls-report-item.active {
        border-color: #d4af37;
        background: #1a1812;
    }
    .ls-report-item .meta { color: #9aa3b8; font-size: 0.8rem; margin-top: 4px; word-break: break-word; }
    .ls-user-table { width: 100%; border-collapse: collapse; min-width: 820px; }
    .ls-user-table th, .ls-user-table td {
        border-bottom: 1px solid #2d3140;
        padding: 9px 8px;
        text-align: start;
        vertical-align: top;
    }
    .ls-user-table th {
        color: #b7bfd0;
        font-size: 0.83rem;
        font-weight: 800;
        background: #131723;
        position: sticky;
        top: 0;
        z-index: 1;
    }
    .ls-link-box {
        margin-top: 8px;
        padding: 10px;
        border-radius: 10px;
        border: 1px dashed rgba(212,175,55,0.42);
        background: rgba(212,175,55,0.08);
    }
    .ls-card-glow{
        background:
            radial-gradient(circle at top right, rgba(212,175,55,.12), transparent 30%),
            linear-gradient(165deg,#11131a,#0f1016);
    }
    .ls-room-card{
        background:
            radial-gradient(circle at top left, rgba(33,201,255,.11), transparent 26%),
            linear-gradient(165deg,#10141d,#0b1017);
    }
    .ls-editor-card{
        background:
            radial-gradient(circle at top right, rgba(212,175,55,.08), transparent 28%),
            linear-gradient(165deg,#12151d,#0f1118);
    }
    .ls-alerts-card{
        background:
            radial-gradient(circle at top left, rgba(255,138,92,.08), transparent 26%),
            linear-gradient(165deg,#14131a,#11131a);
    }
    .ls-helper-card{
        background:linear-gradient(165deg,#11141b,#0e1117);
    }
    .ls-room-headline{
        display:flex;
        justify-content:space-between;
        gap:12px;
        flex-wrap:wrap;
        align-items:flex-start;
        margin-bottom:12px;
    }
    .ls-room-chips{display:flex;gap:8px;flex-wrap:wrap}
    .ls-room-chip{
        display:inline-flex;
        align-items:center;
        gap:7px;
        padding:8px 12px;
        border-radius:999px;
        border:1px solid #39455d;
        background:#0d131d;
        color:#dce5f8;
        font-size:.8rem;
        font-weight:800;
    }
    .ls-room-chip strong{color:#fff}
    .ls-link-box code {
        display: block;
        white-space: pre-wrap;
        word-break: break-all;
        color: #f4f4f4;
        margin-bottom: 8px;
    }

    @media (max-width: 1200px) {
        .ls-hero-row { grid-template-columns: 1fr; }
        .ls-command-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        .ls-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        .ls-workbench { grid-template-columns: 1fr; }
        .ls-two { grid-template-columns: 1fr; }
        .ls-report-grid { grid-template-columns: 1fr; }
        .ls-summary-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        .ls-topbar { flex-direction:column; }
        .ls-topbar-stats { justify-content:flex-start; }
    }
    @media (max-width: 980px) {
        .ls-wrap { padding: 0 8px; }
        .ls-card { padding: 12px; border-radius: 13px; }
        .ls-table,
        .ls-user-table { min-width: 0 !important; width: 100%; border-collapse: separate; border-spacing: 0; }
        .ls-table thead,
        .ls-user-table thead { display: none; }
        .ls-table tbody,
        .ls-user-table tbody,
        .ls-table tr,
        .ls-user-table tr,
        .ls-table td,
        .ls-user-table td { display: block; width: 100%; }
        .ls-table tr,
        .ls-user-table tr {
            border: 1px solid #31374a;
            border-radius: 12px;
            margin: 8px;
            padding: 8px 9px;
            background: #111621;
        }
        .ls-table td,
        .ls-user-table td {
            border-bottom: 1px dashed rgba(255,255,255,0.08);
            padding: 8px 4px;
            text-align: start;
        }
        .ls-table td:last-child,
        .ls-user-table td:last-child { border-bottom: none; }
        .ls-table td::before,
        .ls-user-table td::before {
            content: attr(data-label);
            display: block;
            color: #99a4bd;
            font-size: 0.76rem;
            margin-bottom: 4px;
            font-weight: 700;
        }
        .ls-table td.actions,
        .ls-user-table td.actions { min-width: 0; }
        .ls-actions { gap: 6px; }
        .ls-inline-compact { flex-wrap: wrap; }
        .ls-inline-compact input { width: 72px; }
        .ls-btn { width: auto; min-height: 36px; padding: 8px 10px; }
    }

    @media (max-width: 760px) {
        .ls-command-grid { grid-template-columns: 1fr; }
        .ls-grid { grid-template-columns: 1fr; }
        .ls-form { grid-template-columns: 1fr; }
        .ls-filter-row,
        .ls-summary-grid { grid-template-columns: 1fr; }
        .ls-topbar { padding:14px; }
        .ls-topbar h2 { font-size:1.2rem; }
        .ls-head { align-items: flex-start; }
        .ls-toolbar { width: 100%; }
        .ls-toolbar form { width: 100%; }
        .ls-toolbar .ls-btn { width: 100%; }
        .ls-link-box code { font-size: 0.76rem; }
    }
</style>

<div class="container ls-wrap">
    <div class="content-card">
        <section class="ls-topbar">
            <div>
                <h2><?php echo app_h(app_tr('إدارة اشتراكات العملاء', 'Client Subscriptions')); ?></h2>
                <p><?php echo app_h(app_tr('إدارة الاشتراك أولاً، ثم متابعة الأنظمة المعلقة والأدوات الإضافية عند الحاجة فقط.', 'Manage subscriptions first, then review pending systems and extra tools only when needed.')); ?></p>
                <div class="ls-inline-pills">
                    <span class="ls-room-chip"><i class="fa-solid fa-layer-group"></i> <strong><?php echo app_h($currentRuntimeProfileLabel); ?></strong></span>
                    <span class="ls-room-chip"><i class="fa-solid fa-building-shield"></i> <strong><?php echo app_h(app_tr('أنظمة خاصة', 'Private systems')); ?></strong></span>
                    <a href="saas_center.php" class="ls-room-chip" style="text-decoration:none;"><i class="fa-solid fa-server"></i> <strong><?php echo app_h(app_tr('مركز SaaS', 'SaaS Center')); ?></strong></a>
                </div>
            </div>
            <div class="ls-topbar-stats">
                <span class="ls-top-pill"><i class="fa-solid fa-id-card"></i> <?php echo app_h(app_tr('إجمالي', 'Total')); ?> <strong><?php echo $totalLicenses; ?></strong></span>
                <span class="ls-top-pill"><i class="fa-solid fa-circle-check"></i> <?php echo app_h(app_tr('نشطة', 'Active')); ?> <strong><?php echo $activeCount; ?></strong></span>
                <span class="ls-top-pill"><i class="fa-solid fa-link"></i> <?php echo app_h(app_tr('أنظمة متصلة', 'Connected')); ?> <strong><?php echo (int)$runtimeHealthyRows; ?></strong></span>
                <span class="ls-top-pill"><i class="fa-solid fa-bell"></i> <?php echo app_h(app_tr('تنبيهات', 'Alerts')); ?> <strong><?php echo $unreadAlerts; ?></strong></span>
            </div>
        </section>

        <?php if ($noticeText !== ''): ?>
            <div class="ls-notice <?php echo $noticeType === 'success' ? 'ls-ok' : 'ls-err'; ?>"><?php echo app_h($noticeText); ?></div>
        <?php endif; ?>

        <section class="ls-summary-grid">
            <div class="ls-summary-card">
                <span class="k"><?php echo app_h(app_tr('ملخص الخطط', 'Plan summary')); ?></span>
                <span class="v"><?php echo $subscriptionPlanCount; ?></span>
                <span class="m"><?php echo app_h(app_tr('اشتراكات مدفوعة', 'Paid subscriptions')); ?> | <?php echo app_h(app_tr('تجريبي', 'Trial')); ?>: <?php echo $trialPlanCount; ?> | <?php echo app_h(app_tr('بيع نهائي', 'Lifetime')); ?>: <?php echo $lifetimePlanCount; ?></span>
            </div>
            <div class="ls-summary-card">
                <span class="k"><?php echo app_h(app_tr('ملخص الحالة', 'Status summary')); ?></span>
                <span class="v"><?php echo $activeCount; ?></span>
                <span class="m"><?php echo app_h(app_tr('نشطة', 'Active')); ?> | <?php echo app_h(app_tr('موقوفة', 'Suspended')); ?>: <?php echo $suspendedCount; ?> | <?php echo app_h(app_tr('منتهية', 'Expired')); ?>: <?php echo $expiredCount; ?></span>
            </div>
            <div class="ls-summary-card">
                <span class="k"><?php echo app_h(app_tr('الربط والتنبيهات', 'Linking and alerts')); ?></span>
                <span class="v"><?php echo (int)$runtimeHealthyRows; ?></span>
                <span class="m"><?php echo app_h(app_tr('أنظمة متصلة', 'Connected systems')); ?> | <?php echo app_h(app_tr('بانتظار قرار', 'Pending approval')); ?>: <?php echo count($pendingRuntimeList); ?> | <?php echo app_h(app_tr('محظورة', 'Blocked')); ?>: <?php echo count($blockedRuntimeRows); ?></span>
            </div>
            <div class="ls-summary-card">
                <span class="k"><?php echo app_h(app_tr('استحقاقات قريبة', 'Upcoming expiries')); ?></span>
                <span class="v"><?php echo $expiringSoonCount; ?></span>
                <span class="m"><?php echo app_h(app_tr('خلال 7 أيام', 'Within 7 days')); ?> | <?php echo app_h(app_tr('تنبيهات غير مقروءة', 'Unread alerts')); ?>: <?php echo $unreadAlerts; ?> | <?php echo app_h(app_tr('تقارير دعم', 'Support reports')); ?>: <?php echo $supportReportsCount; ?></span>
            </div>
        </section>

        <details class="ls-advanced" open>
            <summary><?php echo app_h(app_tr('نموذج الاشتراك', 'Subscription form')); ?></summary>
            <div class="ls-advanced-body">
        <div class="ls-workbench">
            <div class="ls-card ls-editor-card">
                <div class="ls-head">
                    <h3><?php echo app_h(app_tr('إضافة / تعديل اشتراك', 'Create / Edit Subscription')); ?></h3>
                    <div class="ls-toolbar">
                        <a href="license_subscriptions.php#subscriptions-table" class="ls-btn dark" style="text-decoration:none;"><?php echo app_h(app_tr('عرض الاشتراكات الحالية', 'View current subscriptions')); ?></a>
                    </div>
                </div>
                <form method="post">
                    <?php echo app_csrf_field(); ?>
                    <input type="hidden" name="action" value="save_license">
                    <input type="hidden" name="license_id" value="<?php echo (int)($editRow['id'] ?? 0); ?>">
                    <div class="ls-form">
                        <div>
                            <label><?php echo app_h(app_tr('مفتاح الترخيص', 'License key')); ?></label>
                            <input type="text" name="license_key" value="<?php echo app_h((string)($editRow['license_key'] ?? '')); ?>" placeholder="<?php echo app_h(app_tr('يُولَّد تلقائياً إذا تُرك فارغاً', 'Auto-generated if left empty')); ?>">
                        </div>
                        <div>
                            <label><?php echo app_h(app_tr('API Token للعميل', 'Client API token')); ?></label>
                            <input type="text" name="api_token" value="<?php echo app_h((string)($editRow['api_token'] ?? '')); ?>" placeholder="<?php echo app_h(app_tr('يُولَّد تلقائياً إذا تُرك فارغاً', 'Auto-generated if left empty')); ?>">
                        </div>
                        <div>
                            <label><?php echo app_h(app_tr('اسم العميل', 'Client name')); ?></label>
                            <input type="text" name="client_name" value="<?php echo app_h((string)($editRow['client_name'] ?? '')); ?>">
                        </div>
                        <div>
                            <label><?php echo app_h(app_tr('البريد', 'Email')); ?></label>
                            <input type="email" name="client_email" value="<?php echo app_h((string)($editRow['client_email'] ?? '')); ?>">
                        </div>
                        <div>
                            <label><?php echo app_h(app_tr('الهاتف', 'Phone')); ?></label>
                            <input type="text" name="client_phone" value="<?php echo app_h((string)($editRow['client_phone'] ?? '')); ?>">
                        </div>
                        <div>
                            <label><?php echo app_h(app_tr('الخطة', 'Plan')); ?></label>
                            <select name="plan_type">
                                <?php $plan = (string)($editRow['plan_type'] ?? 'trial'); ?>
                                <option value="trial" <?php echo $plan === 'trial' ? 'selected' : ''; ?>><?php echo app_h(app_tr('تجريبي (Trial)', 'Trial')); ?></option>
                                <option value="subscription" <?php echo $plan === 'subscription' ? 'selected' : ''; ?>><?php echo app_h(app_tr('اشتراك (Subscription)', 'Subscription')); ?></option>
                                <option value="lifetime" <?php echo $plan === 'lifetime' ? 'selected' : ''; ?>><?php echo app_h(app_tr('بيع نهائي (Lifetime)', 'Lifetime Sale')); ?></option>
                            </select>
                        </div>
                        <div>
                            <label><?php echo app_h(app_tr('الحالة', 'Status')); ?></label>
                            <?php $st = (string)($editRow['status'] ?? 'active'); ?>
                            <select name="status">
                                <option value="active" <?php echo $st === 'active' ? 'selected' : ''; ?>><?php echo app_h(app_tr('نشط', 'Active')); ?></option>
                                <option value="suspended" <?php echo $st === 'suspended' ? 'selected' : ''; ?>><?php echo app_h(app_tr('موقوف', 'Suspended')); ?></option>
                                <option value="expired" <?php echo $st === 'expired' ? 'selected' : ''; ?>><?php echo app_h(app_tr('منتهي', 'Expired')); ?></option>
                            </select>
                        </div>
                        <div>
                            <label><?php echo app_h(app_tr('نهاية التجربة', 'Trial end')); ?></label>
                            <input type="datetime-local" name="trial_ends_at" value="<?php echo app_h(ls_dt_local((string)($editRow['trial_ends_at'] ?? ''))); ?>">
                        </div>
                        <div>
                            <label><?php echo app_h(app_tr('نهاية الاشتراك', 'Subscription end')); ?></label>
                            <input type="datetime-local" name="subscription_ends_at" value="<?php echo app_h(ls_dt_local((string)($editRow['subscription_ends_at'] ?? ''))); ?>">
                        </div>
                        <div>
                            <label><?php echo app_h(app_tr('أيام السماح', 'Grace days')); ?></label>
                            <input type="number" min="0" max="60" name="grace_days" value="<?php echo (int)($editRow['grace_days'] ?? 3); ?>">
                        </div>
                        <div>
                            <label><?php echo app_h(app_tr('الحد الأقصى للتركيبات', 'Max installations')); ?></label>
                            <input type="number" min="1" max="20" name="max_installations" value="<?php echo (int)($editRow['max_installations'] ?? 1); ?>">
                        </div>
                        <div>
                            <label><?php echo app_h(app_tr('حد المستخدمين (0 = غير محدود)', 'Users limit (0 = unlimited)')); ?></label>
                            <input type="number" min="0" max="10000" name="max_users" value="<?php echo (int)($editRow['max_users'] ?? 0); ?>">
                        </div>
                        <div>
                            <label><?php echo app_h(app_tr('تمديد (بالأيام)', 'Extension (days)')); ?></label>
                            <input type="number" min="0" max="3650" name="extend_days" value="0" placeholder="0">
                        </div>
                        <div>
                            <label><?php echo app_h(app_tr('نوع التمديد', 'Extension target')); ?></label>
                            <select name="extend_target">
                                <option value="auto"><?php echo app_h(app_tr('حسب الخطة', 'Auto by plan')); ?></option>
                                <option value="trial"><?php echo app_h(app_tr('فترة تجريبية', 'Trial')); ?></option>
                                <option value="subscription"><?php echo app_h(app_tr('اشتراك', 'Subscription')); ?></option>
                            </select>
                        </div>
                        <div class="full">
                            <label class="ls-check">
                                <input type="checkbox" name="strict_installation" value="1" <?php echo !empty($editRow['strict_installation']) ? 'checked' : ''; ?>>
                                <span><?php echo app_h(app_tr('تفعيل تقييد التركيبات (Lock by installations)', 'Enable strict installation lock')); ?></span>
                            </label>
                        </div>
                        <div class="full">
                            <label><?php echo app_h(app_tr('الدومينات المسموحة (واحد بكل سطر)', 'Allowed domains (one per line)')); ?></label>
                            <textarea name="allowed_domains"><?php echo app_h(implode("\n", app_license_decode_domains((string)($editRow['allowed_domains'] ?? '')))); ?></textarea>
                        </div>
                        <div class="full">
                            <label><?php echo app_h(app_tr('ملاحظات', 'Notes')); ?></label>
                            <textarea name="notes"><?php echo app_h((string)($editRow['notes'] ?? '')); ?></textarea>
                        </div>
                    </div>
                    <div class="ls-actions">
                        <button type="submit" class="ls-btn gold"><?php echo app_h(app_tr('حفظ الاشتراك', 'Save subscription')); ?></button>
                        <a href="license_subscriptions.php" class="ls-btn dark" style="text-decoration:none;"><?php echo app_h(app_tr('اشتراك جديد', 'New')); ?></a>
                    </div>
                </form>
            </div>
        </div>
            </div>
        </details>

        <?php if ($showPendingRuntimeOps): ?>
        <details class="ls-advanced" open>
            <summary><?php echo app_h(app_tr('أنظمة منصبة حديثاً وتحتاج قرار', 'Newly installed systems awaiting decision')); ?></summary>
            <div class="ls-advanced-body">
                <div class="ls-card ls-room-card">
                    <div class="ls-head">
                        <h3><?php echo app_h(app_tr('قائمة الانتظار', 'Pending queue')); ?></h3>
                        <div class="ls-toolbar">
                            <span class="ls-mini" style="padding:7px 10px;border:1px dashed #3d455b;border-radius:10px;"><?php echo app_h(app_tr('الأنظمة هنا لم تُعتمد بعد ولم تُحظر', 'Systems here are neither approved nor blocked yet')); ?></span>
                        </div>
                    </div>
                    <div class="ls-table-wrap">
                        <table class="ls-table" style="min-width:840px;">
                            <thead>
                                <tr>
                                    <th><?php echo app_h(app_tr('العميل/المفتاح', 'Client / Key')); ?></th>
                                    <th><?php echo app_h(app_tr('الدومين', 'Domain')); ?></th>
                                    <th><?php echo app_h(app_tr('التثبيت', 'Installation')); ?></th>
                                    <th><?php echo app_h(app_tr('آخر ظهور', 'Last seen')); ?></th>
                                    <th><?php echo app_h(app_tr('القرار', 'Decision')); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($pendingRuntimeList as $pr): ?>
                                    <tr>
                                        <td data-label="<?php echo app_h(app_tr('العميل/المفتاح', 'Client / Key')); ?>">
                                            <strong><?php echo app_h((string)($pr['client_name'] ?? '-')); ?></strong>
                                            <div class="ls-mini"><?php echo app_h((string)($pr['license_key'] ?? '')); ?></div>
                                        </td>
                                        <td data-label="<?php echo app_h(app_tr('الدومين', 'Domain')); ?>">
                                            <?php echo app_h((string)($pr['domain'] ?? '')); ?>
                                            <div class="ls-mini"><?php echo app_h((string)($pr['app_url'] ?? '')); ?></div>
                                        </td>
                                        <td data-label="<?php echo app_h(app_tr('التثبيت', 'Installation')); ?>">
                                            <div class="ls-mini"><?php echo app_h((string)($pr['installation_id'] ?? '')); ?></div>
                                            <div class="ls-mini"><?php echo app_h((string)($pr['fingerprint'] ?? '')); ?></div>
                                        </td>
                                        <td data-label="<?php echo app_h(app_tr('آخر ظهور', 'Last seen')); ?>"><?php echo app_h((string)($pr['checked_at'] ?? '')); ?></td>
                                        <td class="actions" data-label="<?php echo app_h(app_tr('القرار', 'Decision')); ?>">
                                            <div class="ls-actions compact">
                                                <form method="post">
                                                    <?php echo app_csrf_field(); ?>
                                                    <input type="hidden" name="action" value="activate_runtime">
                                                    <input type="hidden" name="runtime_id" value="<?php echo (int)($pr['id'] ?? 0); ?>">
                                                    <button type="submit" class="ls-btn green"><?php echo app_h(app_tr('تفعيل', 'Activate')); ?></button>
                                                </form>
                                                <form method="post" class="ls-inline-compact">
                                                    <?php echo app_csrf_field(); ?>
                                                    <input type="hidden" name="action" value="create_from_runtime">
                                                    <input type="hidden" name="runtime_id" value="<?php echo (int)($pr['id'] ?? 0); ?>">
                                                    <input type="number" min="1" max="365" name="trial_days" value="14" title="<?php echo app_h(app_tr('أيام الفترة التجريبية', 'Trial days')); ?>">
                                                    <button type="submit" class="ls-btn dark"><?php echo app_h(app_tr('إنشاء اشتراك', 'Create subscription')); ?></button>
                                                </form>
                                                <form method="post" class="ls-inline-compact" onsubmit="return confirm('<?php echo app_h(app_tr('سيتم حظر هذا النظام ومسح أكواد الربط وسجل هويته. هل تريد المتابعة؟', 'This will block the system and destroy its link codes and identity records. Continue?')); ?>');">
                                                    <?php echo app_csrf_field(); ?>
                                                    <input type="hidden" name="action" value="block_runtime">
                                                    <input type="hidden" name="runtime_id" value="<?php echo (int)($pr['id'] ?? 0); ?>">
                                                    <input type="text" name="block_reason" placeholder="<?php echo app_h(app_tr('سبب الحظر', 'Block reason')); ?>">
                                                    <button type="submit" class="ls-btn red"><?php echo app_h(app_tr('حظر', 'Block')); ?></button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </details>
        <?php endif; ?>

        <?php if (!empty($blockedRuntimeRows)): ?>
        <details class="ls-advanced">
            <summary><?php echo app_h(app_tr('النطاقات والأنظمة المحظورة', 'Blocked domains and systems')); ?></summary>
            <div class="ls-advanced-body">
                <div class="ls-card ls-alerts-card">
                    <div class="ls-table-wrap">
                        <table class="ls-table" style="min-width:760px;">
                            <thead>
                                <tr>
                                    <th><?php echo app_h(app_tr('الدومين', 'Domain')); ?></th>
                                    <th><?php echo app_h(app_tr('الهوية', 'Identity')); ?></th>
                                    <th><?php echo app_h(app_tr('سبب الحظر', 'Reason')); ?></th>
                                    <th><?php echo app_h(app_tr('تاريخ الحظر', 'Blocked at')); ?></th>
                                    <th><?php echo app_h(app_tr('إجراء', 'Action')); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($blockedRuntimeRows as $br): ?>
                                    <tr>
                                        <td data-label="<?php echo app_h(app_tr('الدومين', 'Domain')); ?>">
                                            <?php echo app_h((string)($br['domain'] ?? '')); ?>
                                            <div class="ls-mini"><?php echo app_h((string)($br['app_url'] ?? '')); ?></div>
                                        </td>
                                        <td data-label="<?php echo app_h(app_tr('الهوية', 'Identity')); ?>">
                                            <div class="ls-mini"><?php echo app_h((string)($br['license_key'] ?? '')); ?></div>
                                            <div class="ls-mini"><?php echo app_h((string)($br['installation_id'] ?? '')); ?></div>
                                        </td>
                                        <td data-label="<?php echo app_h(app_tr('سبب الحظر', 'Reason')); ?>">
                                            <?php echo app_h((string)($br['reason'] ?? '')); ?>
                                            <div class="ls-mini"><?php echo app_h((string)($br['notes'] ?? '')); ?></div>
                                        </td>
                                        <td data-label="<?php echo app_h(app_tr('تاريخ الحظر', 'Blocked at')); ?>"><?php echo app_h((string)($br['created_at'] ?? '')); ?></td>
                                        <td class="actions" data-label="<?php echo app_h(app_tr('إجراء', 'Action')); ?>">
                                            <form method="post">
                                                <?php echo app_csrf_field(); ?>
                                                <input type="hidden" name="action" value="unblock_runtime">
                                                <input type="hidden" name="block_id" value="<?php echo (int)($br['id'] ?? 0); ?>">
                                                <button type="submit" class="ls-btn green"><?php echo app_h(app_tr('فك الحظر', 'Unblock')); ?></button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </details>
        <?php endif; ?>

        <details class="ls-advanced">
                <summary><?php echo app_h(app_tr('أدوات إضافية', 'Additional tools')); ?></summary>
                <div class="ls-advanced-body">
                    <div class="ls-stack">
        <div class="ls-card ls-card-glow ls-helper-card">
            <div class="ls-head">
                <h3><?php echo app_h(app_tr('توليد سريع (License + API)', 'Quick Generator (License + API)')); ?></h3>
            </div>
            <form method="post">
                <?php echo app_csrf_field(); ?>
                <input type="hidden" name="action" value="quick_generate_credentials">
                <div class="ls-form">
                    <div>
                        <label><?php echo app_h(app_tr('اسم العميل', 'Client name')); ?></label>
                        <input type="text" name="quick_client_name" placeholder="<?php echo app_h(app_tr('مثال: Test / اسم الشركة', 'Example: Test / Company name')); ?>">
                    </div>
                    <div>
                        <label><?php echo app_h(app_tr('الخطة', 'Plan')); ?></label>
                        <select name="quick_plan_type">
                            <option value="trial"><?php echo app_h(app_tr('تجريبي', 'Trial')); ?></option>
                            <option value="subscription"><?php echo app_h(app_tr('اشتراك', 'Subscription')); ?></option>
                            <option value="lifetime"><?php echo app_h(app_tr('بيع نهائي', 'Lifetime')); ?></option>
                        </select>
                    </div>
                    <div>
                        <label><?php echo app_h(app_tr('المدة بالأيام', 'Duration in days')); ?></label>
                        <input type="number" name="quick_days" min="1" max="3650" value="30">
                    </div>
                    <div>
                        <label><?php echo app_h(app_tr('حد الأجهزة', 'Devices limit')); ?></label>
                        <input type="number" name="quick_max_installations" min="1" max="20" value="1">
                    </div>
                    <div>
                        <label><?php echo app_h(app_tr('حد المستخدمين (0 = غير محدود)', 'Users limit (0 = unlimited)')); ?></label>
                        <input type="number" name="quick_max_users" min="0" max="10000" value="0">
                    </div>
                    <div>
                        <label><?php echo app_h(app_tr('النظام الهدف', 'Target system')); ?></label>
                        <select name="quick_linked_system">
                            <option value="work">work</option>
                            <option value="plast">plast</option>
                            <option value="sys">sys</option>
                        </select>
                    </div>
                </div>
                <div class="ls-actions">
                    <button type="submit" class="ls-btn gold"><?php echo app_h(app_tr('توليد الآن', 'Generate now')); ?></button>
                </div>
            </form>
            <?php if (!empty($generatedCredentialInfo)): ?>
                <?php
                    $generatedText = implode("\n", [
                        (string)app_license_activation_package_encoded(
                            (string)($generatedCredentialInfo['remote_url'] ?? ''),
                            (string)($generatedCredentialInfo['api_token'] ?? ''),
                            (string)($generatedCredentialInfo['license_key'] ?? ''),
                            'client',
                            [
                                'client_name' => (string)($generatedCredentialInfo['client_name'] ?? ''),
                                'APP_DESKTOP_LINKED_SYSTEM' => (string)($generatedCredentialInfo['linked_system'] ?? 'work'),
                            ]
                        ),
                        '',
                        'APP_LICENSE_REMOTE_URL=' . (string)($generatedCredentialInfo['remote_url'] ?? ''),
                        'APP_LICENSE_REMOTE_TOKEN=' . (string)($generatedCredentialInfo['api_token'] ?? ''),
                        'APP_LICENSE_KEY=' . (string)($generatedCredentialInfo['license_key'] ?? ''),
                        'APP_DESKTOP_LINKED_SYSTEM=' . (string)($generatedCredentialInfo['linked_system'] ?? 'work'),
                    ]);
                ?>
                <div class="ls-link-box" style="margin-top:10px;">
                    <strong>
                        <?php
                            echo app_h(app_tr('حزمة الربط الجاهزة', 'Generated auto-link package'));
                            echo ' - ';
                            echo app_h(app_tr('النظام الهدف', 'Target system'));
                            echo ': ';
                            echo app_h((string)($generatedCredentialInfo['linked_system'] ?? 'work'));
                        ?>
                    </strong>
                    <code id="quick-generated-credentials"><?php echo app_h($generatedText); ?></code>
                    <div class="ls-actions compact">
                        <button type="button" class="ls-btn dark js-copy-link" data-target="quick-generated-credentials" data-label="<?php echo app_h(app_tr('نسخ حزمة الربط', 'Copy auto-link package')); ?>"><?php echo app_h(app_tr('نسخ حزمة الربط', 'Copy auto-link package')); ?></button>
                        <a href="license_subscriptions.php?edit=<?php echo (int)($generatedCredentialInfo['license_id'] ?? 0); ?>" class="ls-btn green" style="text-decoration:none;"><?php echo app_h(app_tr('فتح الاشتراك', 'Open subscription')); ?></a>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <?php if ($showSupportReports): ?>
        <div class="ls-card ls-room-card" id="support-reports">
            <div class="ls-room-headline">
                <div>
                    <h3><?php echo app_h(app_tr('غرفة متابعة الأنظمة العميلة', 'Client Systems Operations Room')); ?></h3>
                    <div class="ls-mini" style="margin-top:6px;"><?php echo app_h(app_tr('تتبّع الأنظمة المرتبطة حديثاً، إدارة المستخدمين عن بُعد، ومتابعة آخر تقرير وارد من كل عميل.', 'Track recently linked systems, manage users remotely, and review the latest incoming report from each client.')); ?></div>
                </div>
                <div class="ls-room-chips">
                    <span class="ls-room-chip"><i class="fa-solid fa-satellite-dish"></i> <strong><?php echo (int)$supportReportsCount; ?></strong> <?php echo app_h(app_tr('تقرير', 'reports')); ?></span>
                    <span class="ls-room-chip"><i class="fa-solid fa-user-gear"></i> <strong><?php echo count($selectedSupportUsers); ?></strong> <?php echo app_h(app_tr('مستخدم ظاهر', 'visible users')); ?></span>
                    <span class="ls-room-chip"><i class="fa-solid fa-link"></i> <strong><?php echo (int)$runtimeHealthyRows; ?></strong> <?php echo app_h(app_tr('نظام متصل', 'connected')); ?></span>
                </div>
            </div>
            <div class="ls-report-grid">
                <div>
                    <div class="ls-report-list">
                        <?php if (empty($supportReports)): ?>
                            <div class="ls-empty"><?php echo app_h(app_tr('لا توجد تقارير واردة من أنظمة العملاء بعد.', 'No client system reports received yet.')); ?></div>
                        <?php endif; ?>
                        <?php foreach ($supportReports as $reportRow): ?>
                            <?php
                                $rId = (int)($reportRow['id'] ?? 0);
                                $isActiveReport = $selectedReportId === $rId;
                            ?>
                            <a class="ls-report-item <?php echo $isActiveReport ? 'active' : ''; ?>" href="license_subscriptions.php?report_id=<?php echo $rId; ?>#support-reports">
                                <div><strong><?php echo app_h((string)($reportRow['client_name'] ?? '-')); ?></strong></div>
                                <div class="meta"><?php echo app_h((string)($reportRow['license_key'] ?? '')); ?></div>
                                <div class="meta"><?php echo app_h((string)($reportRow['domain'] ?? '')); ?> | <?php echo app_h((string)($reportRow['app_url'] ?? '')); ?></div>
                                <div class="meta">
                                    <?php echo app_h(app_tr('مستخدمون', 'Users')); ?>: <?php echo (int)($reportRow['users_count'] ?? 0); ?>
                                    | <?php echo app_h(app_tr('آخر تحديث', 'Last update')); ?>: <?php echo app_h((string)($reportRow['last_report_at'] ?? '')); ?>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div>
                    <?php if (empty($selectedSupportReport)): ?>
                        <div class="ls-empty"><?php echo app_h(app_tr('اختر نظام عميل من القائمة لعرض مستخدميه.', 'Select a client system from the list to view users.')); ?></div>
                    <?php else: ?>
                        <?php
                            $supportAppUrl = trim((string)($selectedSupportReport['app_url'] ?? ''));
                            if ($supportAppUrl === '') {
                                $supportDomain = trim((string)($selectedSupportReport['domain'] ?? ''));
                                if ($supportDomain !== '') {
                                    $supportAppUrl = 'https://' . ltrim($supportDomain, '/');
                                }
                            }
                            $installUrl = $supportAppUrl !== '' ? rtrim($supportAppUrl, '/') . '/install.php?force=1' : '';
                        ?>
                        <div class="ls-mini" style="margin-bottom:10px;">
                            <strong><?php echo app_h((string)($selectedSupportReport['client_name'] ?? '-')); ?></strong>
                            | <?php echo app_h((string)($selectedSupportReport['domain'] ?? '')); ?>
                            | <?php echo app_h(app_tr('عدد المستخدمين', 'Users count')); ?>: <?php echo (int)($selectedSupportReport['users_count'] ?? 0); ?>
                            | <?php echo app_h(app_tr('آخر تحديث', 'Last report')); ?>: <?php echo app_h((string)($selectedSupportReport['last_report_at'] ?? '')); ?>
                        </div>
                        <div class="ls-actions compact" style="margin-bottom:10px;">
                            <form method="post" onsubmit="return confirm('<?php echo app_h(app_tr('تأكيد حذف هذا التقرير بالكامل؟', 'Delete this report permanently?')); ?>');">
                                <?php echo app_csrf_field(); ?>
                                <input type="hidden" name="action" value="delete_support_report">
                                <input type="hidden" name="report_id" value="<?php echo (int)($selectedSupportReport['id'] ?? 0); ?>">
                                <button type="submit" class="ls-btn red"><?php echo app_h(app_tr('حذف التقرير', 'Delete report')); ?></button>
                            </form>
                        </div>
                        <div class="ls-link-box" style="margin-bottom:10px;">
                            <strong><?php echo app_h(app_tr('المسار الأسرع لتجهيز العميل', 'Fastest client setup path')); ?></strong>
                            <div class="ls-mini" style="margin-top:8px;line-height:1.8;">
                                <?php echo app_h(app_tr('1) افتح install.php على نظام العميل.', '1) Open install.php on the client system.')); ?><br>
                                <?php echo app_h(app_tr('2) أنشئ المدير الأول محلياً.', '2) Create the first local admin.')); ?><br>
                                <?php echo app_h(app_tr('3) أدخل API URL + API Token + License Key.', '3) Enter API URL + API Token + License Key.')); ?><br>
                                <?php echo app_h(app_tr('4) بعد الحفظ ارجع هنا واضغط سحب Snapshot.', '4) After saving, come back here and pull a snapshot.')); ?>
                            </div>
                            <?php if ($installUrl !== ''): ?>
                                <div class="ls-actions compact">
                                    <a class="ls-btn dark" target="_blank" rel="noopener" href="<?php echo app_h($installUrl); ?>" style="text-decoration:none;">
                                        <?php echo app_h(app_tr('فتح install.php للعميل', 'Open client install.php')); ?>
                                    </a>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="ls-table-wrap">
                            <div class="ls-link-box" style="margin-bottom:10px;">
                                <strong><?php echo app_h(app_tr('إضافة مستخدم جديد على نظام العميل', 'Create a new user on client system')); ?></strong>
                                <form method="post" class="ls-inline-compact" style="margin-top:8px; display:flex; flex-wrap:wrap; gap:8px; align-items:center;">
                                    <?php echo app_csrf_field(); ?>
                                    <input type="hidden" name="action" value="remote_create_user">
                                    <input type="hidden" name="report_id" value="<?php echo (int)($selectedSupportReport['id'] ?? 0); ?>">
                                    <input type="text" name="remote_new_username" required minlength="3" pattern="[A-Za-z0-9._-]{3,120}" placeholder="<?php echo app_h(app_tr('username', 'username')); ?>">
                                    <input type="text" name="remote_new_full_name" placeholder="<?php echo app_h(app_tr('الاسم الكامل', 'Full name')); ?>">
                                    <input type="text" name="remote_new_password" required minlength="4" placeholder="<?php echo app_h(app_tr('كلمة المرور', 'Password')); ?>">
                                    <select name="remote_new_role" title="<?php echo app_h(app_tr('الدور', 'Role')); ?>">
                                        <option value="employee"><?php echo app_h(app_tr('موظف', 'Employee')); ?></option>
                                        <option value="admin"><?php echo app_h(app_tr('مدير', 'Admin')); ?></option>
                                    </select>
                                    <input type="email" name="remote_new_email" placeholder="<?php echo app_h(app_tr('الإيميل', 'Email')); ?>">
                                    <input type="text" name="remote_new_phone" placeholder="<?php echo app_h(app_tr('الهاتف', 'Phone')); ?>">
                                    <button type="submit" class="ls-btn gold"><?php echo app_h(app_tr('إنشاء مستخدم', 'Create user')); ?></button>
                                </form>
                            </div>
                            <table class="ls-user-table">
                                <thead>
                                    <tr>
                                        <th><?php echo app_h(app_tr('المستخدم', 'User')); ?></th>
                                        <th><?php echo app_h(app_tr('الدور', 'Role')); ?></th>
                                        <th><?php echo app_h(app_tr('الإيميل', 'Email')); ?></th>
                                        <th><?php echo app_h(app_tr('الهاتف', 'Phone')); ?></th>
                                        <th><?php echo app_h(app_tr('إدارة المستخدم', 'User controls')); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($selectedSupportUsers)): ?>
                                        <tr>
                                            <td colspan="5" class="ls-empty"><?php echo app_h(app_tr('لا يوجد مستخدمون في التقرير.', 'No users in this report.')); ?></td>
                                        </tr>
                                    <?php endif; ?>
                                    <?php foreach ($selectedSupportUsers as $u): ?>
                                        <?php
                                            $uId = (int)($u['id'] ?? 0);
                                            $isIssued = !empty($issuedResetInfo)
                                                && (int)($issuedResetInfo['report_id'] ?? 0) === (int)($selectedSupportReport['id'] ?? 0)
                                                && (int)($issuedResetInfo['report_user_id'] ?? 0) === $uId;
                                        ?>
                                        <tr>
                                            <td data-label="<?php echo app_h(app_tr('المستخدم', 'User')); ?>">
                                                <strong><?php echo app_h((string)($u['full_name'] ?? '')); ?></strong>
                                                <div class="ls-mini">@<?php echo app_h((string)($u['username'] ?? '')); ?> | #<?php echo (int)($u['remote_user_id'] ?? 0); ?></div>
                                            </td>
                                            <td data-label="<?php echo app_h(app_tr('الدور', 'Role')); ?>"><?php echo app_h((string)($u['role'] ?? '')); ?></td>
                                            <td class="bidi-auto" data-label="<?php echo app_h(app_tr('الإيميل', 'Email')); ?>"><?php echo app_h((string)($u['email'] ?? '')); ?></td>
                                            <td class="bidi-auto" data-label="<?php echo app_h(app_tr('الهاتف', 'Phone')); ?>"><?php echo app_h((string)($u['phone'] ?? '')); ?></td>
                                            <td class="actions" data-label="<?php echo app_h(app_tr('إدارة المستخدم', 'User controls')); ?>">
                                                <form method="post" class="ls-inline-compact">
                                                    <?php echo app_csrf_field(); ?>
                                                    <input type="hidden" name="action" value="issue_user_reset_link">
                                                    <input type="hidden" name="report_id" value="<?php echo (int)($selectedSupportReport['id'] ?? 0); ?>">
                                                    <input type="hidden" name="report_user_id" value="<?php echo $uId; ?>">
                                                    <select name="send_channel" title="<?php echo app_h(app_tr('القناة', 'Channel')); ?>">
                                                        <option value="none"><?php echo app_h(app_tr('إنشاء فقط', 'Generate only')); ?></option>
                                                        <option value="email_auto"><?php echo app_h(app_tr('إرسال بريد مباشر', 'Send email directly')); ?></option>
                                                    </select>
                                                    <button type="submit" class="ls-btn dark"><?php echo app_h(app_tr('إنشاء رابط', 'Generate link')); ?></button>
                                                </form>
                                                <form method="post" class="ls-inline-compact" style="margin-top:6px;">
                                                    <?php echo app_csrf_field(); ?>
                                                    <input type="hidden" name="action" value="remote_update_user">
                                                    <input type="hidden" name="report_id" value="<?php echo (int)($selectedSupportReport['id'] ?? 0); ?>">
                                                    <input type="hidden" name="remote_user_id" value="<?php echo (int)($u['remote_user_id'] ?? 0); ?>">
                                                    <input type="hidden" name="remote_username" value="<?php echo app_h((string)($u['username'] ?? '')); ?>">
                                                    <input type="hidden" name="remote_full_name" value="<?php echo app_h((string)($u['full_name'] ?? '')); ?>">
                                                    <input type="hidden" name="remote_email" value="<?php echo app_h((string)($u['email'] ?? '')); ?>">
                                                    <input type="hidden" name="remote_phone" value="<?php echo app_h((string)($u['phone'] ?? '')); ?>">
                                                    <select name="remote_role" title="<?php echo app_h(app_tr('الدور', 'Role')); ?>">
                                                        <?php $uRole = strtolower(trim((string)($u['role'] ?? 'employee'))); ?>
                                                        <option value="employee" <?php echo $uRole === 'employee' || $uRole === 'user' ? 'selected' : ''; ?>><?php echo app_h(app_tr('موظف', 'Employee')); ?></option>
                                                        <option value="admin" <?php echo $uRole === 'admin' ? 'selected' : ''; ?>><?php echo app_h(app_tr('مدير', 'Admin')); ?></option>
                                                    </select>
                                                    <button type="submit" class="ls-btn dark"><?php echo app_h(app_tr('تحديث الدور', 'Update role')); ?></button>
                                                </form>
                                                <form method="post" class="ls-inline-compact" style="margin-top:6px;">
                                                    <?php echo app_csrf_field(); ?>
                                                    <input type="hidden" name="action" value="remote_set_password">
                                                    <input type="hidden" name="report_id" value="<?php echo (int)($selectedSupportReport['id'] ?? 0); ?>">
                                                    <input type="hidden" name="remote_user_id" value="<?php echo (int)($u['remote_user_id'] ?? 0); ?>">
                                                    <input type="hidden" name="remote_username" value="<?php echo app_h((string)($u['username'] ?? '')); ?>">
                                                    <input type="text" name="remote_new_password" minlength="4" required placeholder="<?php echo app_h(app_tr('كلمة مرور جديدة', 'New password')); ?>">
                                                    <button type="submit" class="ls-btn dark"><?php echo app_h(app_tr('تعيين كلمة مرور', 'Set password')); ?></button>
                                                </form>
                                                <form method="post" class="ls-inline-compact" style="margin-top:6px;" onsubmit="return confirm('<?php echo app_h(app_tr('تأكيد حذف المستخدم من نظام العميل؟', 'Delete this user from client system?')); ?>');">
                                                    <?php echo app_csrf_field(); ?>
                                                    <input type="hidden" name="action" value="remote_delete_user">
                                                    <input type="hidden" name="report_id" value="<?php echo (int)($selectedSupportReport['id'] ?? 0); ?>">
                                                    <input type="hidden" name="remote_user_id" value="<?php echo (int)($u['remote_user_id'] ?? 0); ?>">
                                                    <input type="hidden" name="remote_username" value="<?php echo app_h((string)($u['username'] ?? '')); ?>">
                                                    <button type="submit" class="ls-btn red"><?php echo app_h(app_tr('حذف المستخدم', 'Delete user')); ?></button>
                                                </form>
                                                <?php if ($isIssued): ?>
                                                    <div class="ls-link-box">
                                                        <code id="reset-link-<?php echo $uId; ?>"><?php echo app_h((string)($issuedResetInfo['link'] ?? '')); ?></code>
                                                        <div class="ls-actions compact">
                                                            <button type="button" class="ls-btn dark js-copy-link" data-target="reset-link-<?php echo $uId; ?>" data-label="<?php echo app_h(app_tr('نسخ الرابط', 'Copy link')); ?>"><?php echo app_h(app_tr('نسخ الرابط', 'Copy link')); ?></button>
                                                            <?php if (!empty($issuedResetInfo['mailto'])): ?>
                                                                <a class="ls-btn green" target="_blank" rel="noopener" href="<?php echo app_h((string)$issuedResetInfo['mailto']); ?>" style="text-decoration:none;"><?php echo app_h(app_tr('إرسال بالإيميل', 'Send by email')); ?></a>
                                                            <?php endif; ?>
                                                            <?php if (!empty($issuedResetInfo['whatsapp'])): ?>
                                                                <a class="ls-btn green" target="_blank" rel="noopener" href="<?php echo app_h((string)$issuedResetInfo['whatsapp']); ?>" style="text-decoration:none;"><?php echo app_h(app_tr('إرسال واتساب', 'Send WhatsApp')); ?></a>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>
            <?php if ($showAdvancedAlerts): ?>
            <div class="ls-card ls-alerts-card">
                <div class="ls-head">
                    <h3><?php echo app_h(app_tr('التنبيهات', 'Alerts')); ?> (<?php echo $unreadAlerts; ?>)</h3>
                    <div class="ls-toolbar">
                        <form method="post">
                            <?php echo app_csrf_field(); ?>
                            <input type="hidden" name="action" value="read_all_alerts">
                            <button type="submit" class="ls-btn dark"><?php echo app_h(app_tr('تعليم الكل كمقروء', 'Mark all as read')); ?></button>
                        </form>
                        <form method="post" onsubmit="return confirm('<?php echo app_h(app_tr('حذف كل التنبيهات المقروءة؟', 'Delete all read alerts?')); ?>');">
                            <?php echo app_csrf_field(); ?>
                            <input type="hidden" name="action" value="delete_read_alerts">
                            <button type="submit" class="ls-btn dark"><?php echo app_h(app_tr('حذف المقروء', 'Delete read')); ?></button>
                        </form>
                        <form method="post" onsubmit="return confirm('<?php echo app_h(app_tr('تأكيد حذف كل التنبيهات؟', 'Confirm delete all alerts?')); ?>');">
                            <?php echo app_csrf_field(); ?>
                            <input type="hidden" name="action" value="delete_all_alerts">
                            <button type="submit" class="ls-btn red"><?php echo app_h(app_tr('حذف الكل', 'Delete all')); ?></button>
                        </form>
                    </div>
                </div>
                <div style="max-height:420px;overflow:auto;">
                    <?php if (empty($alerts)): ?>
                        <div class="ls-empty"><?php echo app_h(app_tr('لا توجد تنبيهات حالياً.', 'No alerts available.')); ?></div>
                    <?php endif; ?>
                    <?php foreach ($alerts as $a): ?>
                        <div class="ls-alert <?php echo (int)($a['is_read'] ?? 0) === 0 ? 'unread' : ''; ?>">
                            <div class="ls-alert-head">
                                <div class="ls-alert-title bidi-auto"><?php echo app_h((string)($a['title'] ?? '')); ?></div>
                                <span class="ls-mini"><?php echo app_h((string)($a['created_at'] ?? '')); ?></span>
                            </div>
                            <div class="ls-mini bidi-auto"><?php echo app_h((string)($a['client_name'] ?? '')); ?> | <?php echo app_h((string)($a['license_key'] ?? '')); ?></div>
                            <div class="bidi-auto" style="margin-top:6px;"><?php echo app_h((string)($a['message'] ?? '')); ?></div>
                            <div class="ls-actions">
                                <?php if ((int)($a['is_read'] ?? 0) === 0): ?>
                                    <form method="post">
                                        <?php echo app_csrf_field(); ?>
                                        <input type="hidden" name="action" value="read_alert">
                                        <input type="hidden" name="alert_id" value="<?php echo (int)($a['id'] ?? 0); ?>">
                                        <button type="submit" class="ls-btn dark" style="padding:6px 10px;"><?php echo app_h(app_tr('مقروء', 'Read')); ?></button>
                                    </form>
                                <?php endif; ?>
                                <form method="post" onsubmit="return confirm('<?php echo app_h(app_tr('تأكيد حذف التنبيه؟', 'Delete this alert?')); ?>');">
                                    <?php echo app_csrf_field(); ?>
                                    <input type="hidden" name="action" value="delete_alert">
                                    <input type="hidden" name="alert_id" value="<?php echo (int)($a['id'] ?? 0); ?>">
                                    <button type="submit" class="ls-btn red" style="padding:6px 10px;"><?php echo app_h(app_tr('حذف', 'Delete')); ?></button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
                    </div>
                </div>
        </details>

        <details class="ls-advanced" open id="subscriptions-table">
            <summary><?php echo app_h(app_tr('الاشتراكات الحالية', 'Current subscriptions')); ?></summary>
            <div class="ls-advanced-body">
        <div class="ls-card" style="margin:0;">
            <div class="ls-head">
                <h3><?php echo app_h(app_tr('الاشتراكات الحالية', 'Current subscriptions')); ?></h3>
                <div class="ls-toolbar">
                    <form method="post" onsubmit="return confirm('<?php echo app_h(app_tr('تأكيد حذف كل الاشتراكات والبيانات المرتبطة؟', 'Delete all subscriptions and related data?')); ?>');">
                        <?php echo app_csrf_field(); ?>
                        <input type="hidden" name="action" value="delete_all_licenses">
                        <button type="submit" class="ls-btn red"><?php echo app_h(app_tr('حذف كل الاشتراكات', 'Delete all subscriptions')); ?></button>
                    </form>
                </div>
            </div>
            <div class="ls-filter-row">
                <input type="text" id="lsFilterText" placeholder="<?php echo app_h(app_tr('بحث بالعميل / المفتاح / الإيميل...', 'Search by client / key / email...')); ?>">
                <select id="lsFilterStatus">
                    <option value="all"><?php echo app_h(app_tr('كل الحالات', 'All statuses')); ?></option>
                    <option value="active"><?php echo app_h(app_tr('نشط', 'Active')); ?></option>
                    <option value="suspended"><?php echo app_h(app_tr('موقوف', 'Suspended')); ?></option>
                    <option value="expired"><?php echo app_h(app_tr('منتهي', 'Expired')); ?></option>
                </select>
                <select id="lsFilterPlan">
                    <option value="all"><?php echo app_h(app_tr('كل الخطط', 'All plans')); ?></option>
                    <option value="trial"><?php echo app_h(app_tr('تجريبي', 'Trial')); ?></option>
                    <option value="subscription"><?php echo app_h(app_tr('اشتراك', 'Subscription')); ?></option>
                    <option value="lifetime"><?php echo app_h(app_tr('بيع نهائي', 'Lifetime')); ?></option>
                </select>
            </div>
            <div class="ls-table-wrap">
                <table class="ls-table">
                    <thead>
                        <tr>
                            <th><?php echo app_h(app_tr('العميل', 'Client')); ?></th>
                            <th><?php echo app_h(app_tr('المفتاح', 'Key')); ?></th>
                            <th><?php echo app_h(app_tr('API / الحدود', 'API / Limits')); ?></th>
                            <th><?php echo app_h(app_tr('الخطة/الحالة', 'Plan/Status')); ?></th>
                            <th><?php echo app_h(app_tr('الانتهاء', 'Expiry')); ?></th>
                            <th><?php echo app_h(app_tr('التركيبات', 'Installations')); ?></th>
                            <th><?php echo app_h(app_tr('آخر تشغيل', 'Last seen')); ?></th>
                            <th><?php echo app_h(app_tr('إجراءات', 'Actions')); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($licenses)): ?>
                            <tr>
                                <td colspan="8" class="ls-empty"><?php echo app_h(app_tr('لا توجد اشتراكات حالياً.', 'No subscriptions available.')); ?></td>
                            </tr>
                        <?php endif; ?>
                        <?php foreach ($licenses as $row): ?>
                            <?php
                                $st = strtolower((string)($row['status'] ?? 'active'));
                                $stClass = $st === 'suspended' ? 'st-suspended' : ($st === 'expired' ? 'st-expired' : 'st-active');
                                $rowPlan = (string)($row['plan_type'] ?? '');
                                $defaultExtendTarget = $rowPlan === 'subscription' ? 'subscription' : 'trial';
                                $rowPlanText = app_license_plan_label($rowPlan);
                                $expiry = (string)($row['plan_type'] ?? '') === 'trial'
                                    ? (string)($row['trial_ends_at'] ?? '')
                                    : ((string)($row['plan_type'] ?? '') === 'subscription' ? (string)($row['subscription_ends_at'] ?? '') : '-');
                                $isActivationIssued = !empty($issuedActivationInfo)
                                    && (int)($issuedActivationInfo['license_id'] ?? 0) === (int)($row['id'] ?? 0);
                            ?>
                            <tr data-license-row="1" data-status="<?php echo app_h($st); ?>" data-plan="<?php echo app_h($rowPlan); ?>" data-key="<?php echo app_h(strtolower((string)($row['license_key'] ?? ''))); ?>" data-client="<?php echo app_h(strtolower((string)($row['client_name'] ?? ''))); ?>" data-email="<?php echo app_h(strtolower((string)($row['client_email'] ?? ''))); ?>">
                                <td data-label="<?php echo app_h(app_tr('العميل', 'Client')); ?>"><?php echo app_h((string)($row['client_name'] ?? '-')); ?><div class="ls-mini"><?php echo app_h((string)($row['client_email'] ?? '')); ?></div></td>
                                <td data-label="<?php echo app_h(app_tr('المفتاح', 'Key')); ?>"><code><?php echo app_h((string)($row['license_key'] ?? '')); ?></code></td>
                                <td data-label="<?php echo app_h(app_tr('API / الحدود', 'API / Limits')); ?>">
                                    <code><?php echo app_h((string)($row['api_token'] ?? '')); ?></code>
                                    <div class="ls-mini"><?php echo app_h(app_tr('حد المستخدمين', 'Users limit')); ?>: <?php echo (int)($row['max_users'] ?? 0) > 0 ? (int)$row['max_users'] : app_h(app_tr('غير محدود', 'Unlimited')); ?></div>
                                    <div class="ls-mini"><?php echo app_h(app_tr('المستخدمون الحاليون', 'Current users')); ?>: <?php echo (int)($row['latest_users_count'] ?? 0); ?></div>
                                </td>
                                <td data-label="<?php echo app_h(app_tr('الخطة/الحالة', 'Plan/Status')); ?>">
                                    <div><?php echo app_h($rowPlanText); ?></div>
                                    <div class="ls-pill <?php echo app_h($st); ?>"><?php echo app_h(app_status_label($st)); ?></div>
                                </td>
                                <td data-label="<?php echo app_h(app_tr('الانتهاء', 'Expiry')); ?>"><?php echo app_h($expiry !== '' ? $expiry : '-'); ?></td>
                                <td data-label="<?php echo app_h(app_tr('التركيبات', 'Installations')); ?>"><?php echo (int)($row['installations_count'] ?? 0); ?><div class="ls-mini"><?php echo !empty($row['strict_installation']) ? app_h(app_tr('تقييد مفعل', 'Strict ON')) : app_h(app_tr('مرن', 'Flexible')); ?></div></td>
                                <td data-label="<?php echo app_h(app_tr('آخر تشغيل', 'Last seen')); ?>"><?php echo app_h((string)($row['last_seen_at'] ?? '-')); ?></td>
                                <td class="actions" data-label="<?php echo app_h(app_tr('إجراءات', 'Actions')); ?>">
                                    <div class="ls-actions">
                                        <a href="license_subscriptions.php?edit=<?php echo (int)($row['id'] ?? 0); ?>" class="ls-btn dark" style="text-decoration:none;"><?php echo app_h(app_tr('تعديل', 'Edit')); ?></a>
                                        <?php if ($st === 'active'): ?>
                                            <form method="post">
                                                <?php echo app_csrf_field(); ?>
                                                <input type="hidden" name="action" value="pause_license">
                                                <input type="hidden" name="license_id" value="<?php echo (int)($row['id'] ?? 0); ?>">
                                                <input type="hidden" name="lock_reason" value="Paused by owner">
                                                <button type="submit" class="ls-btn dark"><?php echo app_h(app_tr('إيقاف مؤقت', 'Pause')); ?></button>
                                            </form>
                                            <form method="post" onsubmit="return confirm('<?php echo app_h(app_tr('سيتم حظر هذا الاشتراك وإيقاف أي عمل غير مرخص. هل تريد المتابعة؟', 'This will block this subscription and stop any unlicensed usage. Continue?')); ?>');">
                                                <?php echo app_csrf_field(); ?>
                                                <input type="hidden" name="action" value="lock_license">
                                                <input type="hidden" name="license_id" value="<?php echo (int)($row['id'] ?? 0); ?>">
                                                <input type="hidden" name="lock_reason" value="<?php echo app_h(app_tr('تم الحظر من نظام المالك بسبب مخالفة/غياب الترخيص', 'Blocked from owner system due to unlicensed usage')); ?>">
                                                <button type="submit" class="ls-btn red"><?php echo app_h(app_tr('حظر', 'Block')); ?></button>
                                            </form>
                                        <?php else: ?>
                                            <form method="post">
                                                <?php echo app_csrf_field(); ?>
                                                <input type="hidden" name="action" value="activate_license">
                                                <input type="hidden" name="license_id" value="<?php echo (int)($row['id'] ?? 0); ?>">
                                                <button type="submit" class="ls-btn green"><?php echo app_h(app_tr('تفعيل', 'Activate')); ?></button>
                                            </form>
                                        <?php endif; ?>
                                        <form method="post" class="ls-inline-compact">
                                            <?php echo app_csrf_field(); ?>
                                            <input type="hidden" name="action" value="generate_link_package">
                                            <input type="hidden" name="license_id" value="<?php echo (int)($row['id'] ?? 0); ?>">
                                            <select name="linked_system" title="<?php echo app_h(app_tr('النظام الهدف', 'Target system')); ?>">
                                                <option value="work">work</option>
                                                <option value="plast">plast</option>
                                                <option value="sys">sys</option>
                                            </select>
                                            <button type="submit" class="ls-btn gold"><?php echo app_h(app_tr('توليد ربط', 'Generate Link')); ?></button>
                                        </form>
                                        <form method="post" class="ls-inline-compact">
                                            <?php echo app_csrf_field(); ?>
                                            <input type="hidden" name="action" value="extend_license_days">
                                            <input type="hidden" name="license_id" value="<?php echo (int)($row['id'] ?? 0); ?>">
                                            <input type="number" min="1" max="3650" name="extend_days" value="7" title="<?php echo app_h(app_tr('عدد أيام التمديد', 'Extension days')); ?>">
                                            <select name="extend_target" title="<?php echo app_h(app_tr('نوع التمديد', 'Extension target')); ?>">
                                                <option value="auto"><?php echo app_h(app_tr('تلقائي', 'Auto')); ?></option>
                                                <option value="trial" <?php echo $defaultExtendTarget === 'trial' ? 'selected' : ''; ?>><?php echo app_h(app_tr('تجريبي', 'Trial')); ?></option>
                                                <option value="subscription" <?php echo $defaultExtendTarget === 'subscription' ? 'selected' : ''; ?>><?php echo app_h(app_tr('اشتراك', 'Subscription')); ?></option>
                                            </select>
                                            <button type="submit" class="ls-btn dark"><?php echo app_h(app_tr('مد', 'Extend')); ?></button>
                                        </form>
                                        <form method="post" onsubmit="return confirm('<?php echo app_h(app_tr('تأكيد حذف هذا الاشتراك وكل بياناته؟', 'Delete this subscription and all related data?')); ?>');">
                                            <?php echo app_csrf_field(); ?>
                                            <input type="hidden" name="action" value="delete_license">
                                            <input type="hidden" name="license_id" value="<?php echo (int)($row['id'] ?? 0); ?>">
                                            <button type="submit" class="ls-btn red"><?php echo app_h(app_tr('حذف', 'Delete')); ?></button>
                                        </form>
                                        <form method="post">
                                            <?php echo app_csrf_field(); ?>
                                            <input type="hidden" name="action" value="rotate_api_token">
                                            <input type="hidden" name="license_id" value="<?php echo (int)($row['id'] ?? 0); ?>">
                                            <button type="submit" class="ls-btn dark"><?php echo app_h(app_tr('تجديد API', 'Rotate API')); ?></button>
                                        </form>
                                        <span class="ls-mini" style="padding:6px 10px; border:1px dashed #3d455b; border-radius:10px;">
                                            <?php echo app_h(app_tr('التفعيل يتم فقط بعد اعتماد المالك وضغط زر التفعيل', 'Activation happens only after owner approval and manual activation')); ?>
                                        </span>
                                    </div>
                                    <?php if ($isActivationIssued): ?>
                                        <div class="ls-link-box">
                                            <code id="activation-message-<?php echo (int)($row['id'] ?? 0); ?>"><?php echo app_h((string)($issuedActivationInfo['message'] ?? '')); ?></code>
                                            <div class="ls-actions compact">
                                                <button type="button" class="ls-btn dark js-copy-link" data-target="activation-message-<?php echo (int)($row['id'] ?? 0); ?>"><?php echo app_h(app_tr('نسخ البيانات', 'Copy data')); ?></button>
                                                <?php if (!empty($issuedActivationInfo['mailto'])): ?>
                                                    <a class="ls-btn green" target="_blank" rel="noopener" href="<?php echo app_h((string)$issuedActivationInfo['mailto']); ?>" style="text-decoration:none;"><?php echo app_h(app_tr('إرسال بالإيميل', 'Send by email')); ?></a>
                                                <?php endif; ?>
                                                <?php if (!empty($issuedActivationInfo['whatsapp'])): ?>
                                                    <a class="ls-btn green" target="_blank" rel="noopener" href="<?php echo app_h((string)$issuedActivationInfo['whatsapp']); ?>" style="text-decoration:none;"><?php echo app_h(app_tr('إرسال واتساب', 'Send WhatsApp')); ?></a>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
            </div>
        </details>

        <?php if ($showRuntimeOps): ?>
        <details class="ls-advanced">
            <summary><?php echo app_h(app_tr('سجل تشغيل الأنظمة (متقدم)', 'Runtime logs (advanced)')); ?></summary>
            <div class="ls-advanced-body">
        <div class="ls-card" style="margin:0;">
            <div class="ls-head">
                <h3><?php echo app_h(app_tr('سجل تشغيل الأنظمة', 'Runtime check-ins')); ?></h3>
                <div class="ls-toolbar">
                    <form method="post" onsubmit="return confirm('<?php echo app_h(app_tr('تأكيد حذف سجل التشغيل بالكامل؟', 'Delete all runtime logs?')); ?>');">
                        <?php echo app_csrf_field(); ?>
                        <input type="hidden" name="action" value="delete_all_runtime">
                        <button type="submit" class="ls-btn red"><?php echo app_h(app_tr('حذف سجل التشغيل', 'Delete runtime logs')); ?></button>
                    </form>
                </div>
            </div>
            <div class="ls-table-wrap">
                <table class="ls-table" style="min-width:760px;">
                    <thead>
                        <tr>
                            <th><?php echo app_h(app_tr('الوقت', 'Time')); ?></th>
                            <th><?php echo app_h(app_tr('العميل', 'Client')); ?></th>
                            <th><?php echo app_h(app_tr('الدومين', 'Domain')); ?></th>
                            <th><?php echo app_h(app_tr('التثبيت', 'Installation')); ?></th>
                            <th><?php echo app_h(app_tr('الحالة', 'Status')); ?></th>
                            <th><?php echo app_h(app_tr('إجراءات', 'Actions')); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($runtime)): ?>
                            <tr>
                                <td colspan="6" class="ls-empty"><?php echo app_h(app_tr('لا توجد سجلات تشغيل حالياً.', 'No runtime logs available.')); ?></td>
                            </tr>
                        <?php endif; ?>
                        <?php foreach ($runtime as $r): ?>
                            <?php
                                $runtimeBoundId = max((int)($r['license_id'] ?? 0), (int)($r['linked_license_id'] ?? 0));
                            ?>
                            <tr>
                                <td data-label="<?php echo app_h(app_tr('الوقت', 'Time')); ?>"><?php echo app_h((string)($r['checked_at'] ?? '')); ?></td>
                                <td data-label="<?php echo app_h(app_tr('العميل', 'Client')); ?>"><?php echo app_h((string)($r['client_name'] ?? '')); ?><div class="ls-mini"><?php echo app_h((string)($r['license_key'] ?? '')); ?></div></td>
                                <td data-label="<?php echo app_h(app_tr('الدومين', 'Domain')); ?>"><?php echo app_h((string)($r['domain'] ?? '')); ?></td>
                                <td class="ls-mini" data-label="<?php echo app_h(app_tr('التثبيت', 'Installation')); ?>"><?php echo app_h((string)($r['installation_id'] ?? '')); ?></td>
                                <td data-label="<?php echo app_h(app_tr('الحالة', 'Status')); ?>">
                                    <?php echo app_h((string)($r['status'] ?? '')); ?> / <?php echo app_h((string)($r['plan_type'] ?? '')); ?>
                                    <?php if ($runtimeBoundId > 0): ?>
                                        <div class="ls-mini"><?php echo app_h(app_tr('مرتبط باشتراك', 'Linked to subscription')); ?> #<?php echo $runtimeBoundId; ?></div>
                                    <?php endif; ?>
                                </td>
                                <td class="actions" data-label="<?php echo app_h(app_tr('إجراءات', 'Actions')); ?>">
                                    <div class="ls-actions compact">
                                        <form method="post">
                                            <?php echo app_csrf_field(); ?>
                                            <input type="hidden" name="action" value="activate_runtime">
                                            <input type="hidden" name="runtime_id" value="<?php echo (int)($r['id'] ?? 0); ?>">
                                            <button type="submit" class="ls-btn green"><?php echo app_h(app_tr('تفعيل', 'Activate')); ?></button>
                                        </form>
                                        <?php if (!empty($licenses)): ?>
                                            <form method="post" class="ls-inline-compact">
                                                <?php echo app_csrf_field(); ?>
                                                <input type="hidden" name="action" value="link_runtime_to_license">
                                                <input type="hidden" name="runtime_id" value="<?php echo (int)($r['id'] ?? 0); ?>">
                                                <input type="hidden" name="activate_after_link" value="1">
                                                <select name="target_license_id" title="<?php echo app_h(app_tr('اختيار الاشتراك', 'Choose subscription')); ?>">
                                                    <?php foreach ($licenses as $lr): ?>
                                                        <?php $optId = (int)($lr['id'] ?? 0); ?>
                                                        <option value="<?php echo $optId; ?>" <?php echo $runtimeBoundId === $optId ? 'selected' : ''; ?>>
                                                            <?php echo app_h((string)($lr['client_name'] ?? '-')); ?> | <?php echo app_h((string)($lr['license_key'] ?? '')); ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <button type="submit" class="ls-btn dark"><?php echo app_h(app_tr('ربط بالاشتراك', 'Link to subscription')); ?></button>
                                            </form>
                                            <span class="ls-mini" style="padding:6px 10px; border:1px dashed #3d455b; border-radius:10px;">
                                                <?php echo app_h(app_tr('تم إلغاء رموز الربط في النسخة الجديدة', 'Link codes deprecated in new architecture')); ?>
                                            </span>
                                            <form method="post" class="ls-inline-compact">
                                                <?php echo app_csrf_field(); ?>
                                                <input type="hidden" name="action" value="pull_client_snapshot">
                                                <input type="hidden" name="runtime_id" value="<?php echo (int)($r['id'] ?? 0); ?>">
                                                <select name="target_license_id" title="<?php echo app_h(app_tr('اختيار الاشتراك', 'Choose subscription')); ?>">
                                                    <?php foreach ($licenses as $lr): ?>
                                                        <?php $optId = (int)($lr['id'] ?? 0); ?>
                                                        <option value="<?php echo $optId; ?>" <?php echo $runtimeBoundId === $optId ? 'selected' : ''; ?>>
                                                            <?php echo app_h((string)($lr['client_name'] ?? '-')); ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <button type="submit" class="ls-btn dark"><?php echo app_h(app_tr('سحب بيانات النظام', 'Pull system snapshot')); ?></button>
                                            </form>
                                        <?php endif; ?>
                                        <?php if ($runtimeBoundId <= 0 && trim((string)($r['license_key'] ?? '')) !== ''): ?>
                                            <form method="post" class="ls-inline-compact">
                                                <?php echo app_csrf_field(); ?>
                                                <input type="hidden" name="action" value="create_from_runtime">
                                                <input type="hidden" name="runtime_id" value="<?php echo (int)($r['id'] ?? 0); ?>">
                                                <input type="number" min="1" max="365" name="trial_days" value="14" title="<?php echo app_h(app_tr('أيام الفترة التجريبية', 'Trial days')); ?>">
                                                <button type="submit" class="ls-btn green"><?php echo app_h(app_tr('إنشاء اشتراك', 'Create subscription')); ?></button>
                                            </form>
                                        <?php endif; ?>
                                        <form method="post" onsubmit="return confirm('<?php echo app_h(app_tr('تأكيد حذف هذا السجل؟', 'Delete this log entry?')); ?>');">
                                            <?php echo app_csrf_field(); ?>
                                            <input type="hidden" name="action" value="delete_runtime">
                                            <input type="hidden" name="runtime_id" value="<?php echo (int)($r['id'] ?? 0); ?>">
                                            <button type="submit" class="ls-btn red"><?php echo app_h(app_tr('حذف', 'Delete')); ?></button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
            </div>
        </details>
        <?php endif; ?>
    </div>
</div>

<script>
document.addEventListener('click', function (event) {
    const btn = event.target instanceof Element ? event.target.closest('.js-copy-link') : null;
    if (!btn) return;
    const targetId = btn.getAttribute('data-target') || '';
    const target = targetId ? document.getElementById(targetId) : null;
    const value = target ? (target.textContent || '').trim() : '';
    if (!value) return;
    const defaultLabel = btn.getAttribute('data-label') || '<?php echo app_h(app_tr('نسخ الرابط', 'Copy link')); ?>';
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(value).then(() => {
            btn.textContent = '<?php echo app_h(app_tr('تم النسخ', 'Copied')); ?>';
            window.setTimeout(() => { btn.textContent = defaultLabel; }, 1200);
        }).catch(() => {});
    }
});

const filterTextEl = document.getElementById('lsFilterText');
const filterStatusEl = document.getElementById('lsFilterStatus');
const filterPlanEl = document.getElementById('lsFilterPlan');
const applySubscriptionFilter = () => {
    const q = (filterTextEl && filterTextEl.value ? filterTextEl.value : '').trim().toLowerCase();
    const st = (filterStatusEl && filterStatusEl.value ? filterStatusEl.value : 'all').toLowerCase();
    const plan = (filterPlanEl && filterPlanEl.value ? filterPlanEl.value : 'all').toLowerCase();
    document.querySelectorAll('tr[data-license-row=\"1\"]').forEach((row) => {
        const rowStatus = (row.getAttribute('data-status') || '').toLowerCase();
        const rowPlan = (row.getAttribute('data-plan') || '').toLowerCase();
        const haystack = [
            row.getAttribute('data-client') || '',
            row.getAttribute('data-key') || '',
            row.getAttribute('data-email') || ''
        ].join(' ');
        const statusOk = st === 'all' || st === rowStatus;
        const planOk = plan === 'all' || plan === rowPlan;
        const textOk = q === '' || haystack.indexOf(q) !== -1;
        row.style.display = (statusOk && planOk && textOk) ? '' : 'none';
    });
};
if (filterTextEl) filterTextEl.addEventListener('input', applySubscriptionFilter);
if (filterStatusEl) filterStatusEl.addEventListener('change', applySubscriptionFilter);
if (filterPlanEl) filterPlanEl.addEventListener('change', applySubscriptionFilter);
</script>

<?php require 'footer.php'; ob_end_flush(); ?>
