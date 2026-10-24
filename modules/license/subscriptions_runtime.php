<?php

if (!function_exists('ls_dt_local')) {
    function ls_dt_local(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        $ts = strtotime($value);
        if ($ts === false) {
            return '';
        }
        return date('Y-m-d\TH:i', $ts);
    }
}

if (!function_exists('ls_dt_db')) {
    function ls_dt_db(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        $value = str_replace('T', ' ', $value);
        if (preg_match('/^\d{4}-\d{2}-\d{2}\s\d{2}:\d{2}$/', $value)) {
            $value .= ':00';
        }
        return strtotime($value) === false ? '' : $value;
    }
}

if (!function_exists('ls_phone_whatsapp')) {
    function ls_phone_whatsapp(string $phone): string
    {
        $digits = preg_replace('/[^0-9]+/', '', $phone);
        return is_string($digits) ? $digits : '';
    }
}

if (!function_exists('ls_remote_error_text')) {
    function ls_remote_error_text(string $error): string
    {
        $err = strtolower(trim($error));
        if ($err === '') {
            return app_tr('سبب غير معروف', 'Unknown reason');
        }
        if ($err === 'client_url_missing') {
            return app_tr('لا يوجد رابط عميل عام صالح لسحب البيانات. اربط النظام السحابي أولاً أو حدّث SYSTEM_URL في نظام العميل.', 'No valid public client URL found for snapshot. Link cloud client first or update SYSTEM_URL on client.');
        }
        if ($err === 'client_private_url_unreachable_from_owner') {
            return app_tr('عنوان العميل محلي (localhost/private) وغير قابل للوصول من سيرفر المالك. استخدم دومين عام للعميل.', 'Client URL is localhost/private and unreachable from owner server. Use a public client domain.');
        }
        if ($err === 'users_schema_incompatible') {
            return app_tr('هيكل المستخدمين في نظام العميل غير جاهز للإدارة عن بُعد. استخدم install.php على نظام العميل لإضافة المدير الأول وربط API ثم أعد سحب Snapshot.', 'Client users schema is not ready for remote management. Use install.php on the client to create the first admin and save API link, then pull snapshot again.');
        }
        if ($err === 'unauthorized' || $err === 'bad_signature' || $err === 'timestamp_expired' || $err === 'replay_detected') {
            return app_tr('فشل توثيق الربط بين نظام المالك والعميل. نفّذ مزامنة ترخيص من نظام العميل ثم أعد محاولة سحب Snapshot.', 'Owner/client link authentication failed. Run a license sync from the client system, then retry snapshot.');
        }
        if (strpos($err, 'could not resolve host') !== false) {
            return app_tr('الدومين غير قابل للحل DNS من سيرفر المالك. راجع رابط العميل.', 'Domain cannot be resolved by owner server DNS. Check client URL.');
        }
        if (strpos($err, 'failed to connect to localhost') !== false) {
            return app_tr('لا يمكن الاتصال بـ localhost من سيرفر المالك. استخدم رابط عميل عام بدلاً من localhost.', 'Owner server cannot connect to localhost. Use public client URL instead of localhost.');
        }
        if ($err === 'missing_required_fields') {
            return app_tr('بيانات الربط المطلوبة غير مكتملة على نظام العميل.', 'Required client link fields are missing.');
        }
        if ($err === 'license_mismatch') {
            return app_tr('مفتاح الترخيص في الطلب لا يطابق المفتاح المثبّت على نظام العميل.', 'Requested license key does not match the key installed on client system.');
        }
        if ($err === 'installation_mismatch' || $err === 'fingerprint_mismatch') {
            return app_tr('هوية التثبيت لا تطابق النظام العميل الحالي. دع النظام العميل يرسل تقرير تشغيل جديد ثم أعد المحاولة.', 'Installation identity does not match current client system. Let the client system send a fresh runtime report, then retry.');
        }
        if ($err === 'domain_mismatch') {
            return app_tr('الدومين المرسل لا يطابق دومين نظام العميل.', 'Provided domain does not match client system domain.');
        }
        if (strpos($err, 'http_400') === 0) {
            return app_tr('الخادم العميل أعاد 400 Bad Request. غالباً رابط API غير صحيح أو توجد مشكلة WAF/Token (مسافات/سطر جديد). اضبط SYSTEM_URL كرابط نظيف، وتأكد أن API Token بدون أي مسافات أو أسطر، وجرب /license_api.php.', 'Client server returned 400 Bad Request. Usually API URL is wrong or WAF/token formatting issue (spaces/newlines). Set clean SYSTEM_URL, ensure API token has no spaces/newlines, and use /license_api.php endpoint.');
        }
        if (strpos($err, 'http_500') === 0) {
            return app_tr('الخادم العميل أعاد 500. غالباً خطأ داخلي في API العميل أو تعارض بنية users (مثل role enum قديم). نفّذ safe upgrade على العميل ثم أعد المحاولة.', 'Client server returned 500. Usually an internal client API error or users schema conflict (for example legacy role enum). Apply client safe upgrade then retry.');
        }
        if (strpos($err, 'create_failed') !== false || strpos($err, 'update_failed') !== false || strpos($err, 'password_update_failed') !== false) {
            return app_tr('تعذر تنفيذ عملية المستخدم على نظام العميل. غالباً بنية users أو قيمة role غير متوافقة مع المخطط القديم. نفّذ safe upgrade على العميل.', 'User operation failed on client system. users schema or role value is incompatible with legacy schema. Apply client safe upgrade.');
        }
        if (strpos($err, 'prepare_failed') !== false) {
            return app_tr('تعذر تجهيز استعلام قاعدة البيانات على نظام العميل. غالباً هيكل users قديم أو غير متوافق بالكامل. نفّذ safe upgrade على العميل.', 'Failed to prepare database statement on client system. users schema is likely legacy/incompatible. Apply client safe upgrade on client.');
        }
        if (strpos($err, 'http_404') !== false) {
            return app_tr('مسار API غير موجود على نظام العميل.', 'Client API endpoint not found.');
        }
        if (strpos($err, 'http_401') !== false || strpos($err, 'http_403') !== false) {
            return app_tr('فشل التوثيق مع نظام العميل. راجع API Token والترخيص.', 'Client authentication failed. Check API token and license.');
        }
        return $err;
    }
}

if (!function_exists('ls_license_expiry_text')) {
    function ls_license_expiry_text(array $row): string
    {
        $plan = strtolower(trim((string)($row['plan_type'] ?? 'trial')));
        if ($plan === 'trial') {
            $value = trim((string)($row['trial_ends_at'] ?? ''));
            return $value !== '' ? $value : app_tr('غير محدد', 'Not set');
        }
        if ($plan === 'subscription') {
            $value = trim((string)($row['subscription_ends_at'] ?? ''));
            return $value !== '' ? $value : app_tr('غير محدد', 'Not set');
        }
        return app_tr('مفتوح (بيع نهائي)', 'Open-ended (lifetime sale)');
    }
}

if (!function_exists('ls_try_push_license_credentials')) {
    function ls_try_push_license_credentials(mysqli $conn, int $licenseId): array
    {
        if ($licenseId <= 0) {
            return ['ok' => false, 'error' => 'invalid_license_id', 'pushed' => 0, 'failed' => 0];
        }
        $push = app_license_owner_push_credentials_to_clients($conn, $licenseId, [
            'force' => true,
            'max_targets' => 20,
            'timeout' => 6,
            'max_urls' => 6,
            'max_tokens' => 4,
            'max_attempts' => 6,
        ]);
        return is_array($push) ? $push : ['ok' => false, 'error' => 'push_failed', 'pushed' => 0, 'failed' => 0];
    }
}

if (!function_exists('ls_runtime_pick_license_id')) {
    function ls_runtime_pick_license_id(array $runtimeRow, array $licenses): int
    {
        $runtimeKey = strtoupper(trim((string)($runtimeRow['license_key'] ?? '')));
        $runtimeDomain = '';
        if (function_exists('app_license_normalize_domain')) {
            $runtimeDomain = app_license_normalize_domain((string)($runtimeRow['domain'] ?? ''));
        } else {
            $runtimeDomain = strtolower(trim((string)($runtimeRow['domain'] ?? '')));
        }

        if ($runtimeKey !== '') {
            foreach ($licenses as $row) {
                if (strtoupper(trim((string)($row['license_key'] ?? ''))) === $runtimeKey) {
                    return (int)($row['id'] ?? 0);
                }
            }
        }

        if ($runtimeDomain !== '') {
            foreach ($licenses as $row) {
                $allowed = app_license_decode_domains((string)($row['allowed_domains'] ?? ''));
                if (!empty($allowed) && app_license_domain_allowed($allowed, $runtimeDomain)) {
                    return (int)($row['id'] ?? 0);
                }
            }
        }

        return 0;
    }
}

if (!function_exists('ls_activation_message')) {
    function ls_activation_message(array $row, string $apiPrimaryUrl, string $apiAltUrl): string
    {
        $clientName = trim((string)($row['client_name'] ?? 'عميل'));
        $licenseKey = trim((string)($row['license_key'] ?? ''));
        $apiToken = trim((string)($row['api_token'] ?? ''));
        $plan = strtolower(trim((string)($row['plan_type'] ?? 'trial')));
        $status = strtolower(trim((string)($row['status'] ?? 'active')));
        $maxInst = max(1, (int)($row['max_installations'] ?? 1));
        $maxUsers = max(0, (int)($row['max_users'] ?? 0));
        $strictInstall = !empty($row['strict_installation']);
        $expiry = ls_license_expiry_text($row);

        $planText = $plan === 'subscription'
            ? app_tr('اشتراك', 'Subscription')
            : ($plan === 'lifetime' ? app_tr('بيع نهائي', 'Lifetime sale') : app_tr('تجريبي', 'Trial'));

        $statusText = $status === 'suspended'
            ? app_tr('موقوف', 'Suspended')
            : ($status === 'expired' ? app_tr('منتهي', 'Expired') : app_tr('نشط', 'Active'));

        $usersText = $maxUsers > 0
            ? (string)$maxUsers
            : app_tr('غير محدود', 'Unlimited');

        $installText = $strictInstall
            ? (string)$maxInst
            : app_tr('مرن', 'Flexible');

        $package = app_license_activation_package_text($apiPrimaryUrl, $apiToken, $licenseKey, 'client', [
            'client_name' => $clientName,
            'allowed_domains' => (string)($row['allowed_domains'] ?? ''),
        ]);
        $autoPackage = app_license_activation_package_encoded($apiPrimaryUrl, $apiToken, $licenseKey, 'client', [
            'client_name' => $clientName,
            'allowed_domains' => (string)($row['allowed_domains'] ?? ''),
        ]);

        $lines = [
            app_tr('مرحباً', 'Hello') . ' ' . $clientName,
            app_tr('حزمة الربط الذاتي:', 'Automatic connection package:'),
            $autoPackage,
            '',
            app_tr('بيانات البيئة الاحتياطية:', 'Fallback environment credentials:'),
            $package,
            '',
            app_tr('مسار بديل للـ API:', 'Alternative API endpoint:') . ' ' . $apiAltUrl,
            app_tr('الخطة:', 'Plan:') . ' ' . $planText,
            app_tr('الحالة:', 'Status:') . ' ' . $statusText,
            app_tr('الانتهاء:', 'Expiry:') . ' ' . $expiry,
            app_tr('حد الأجهزة:', 'Devices limit:') . ' ' . $installText,
            app_tr('حد المستخدمين:', 'Users limit:') . ' ' . $usersText,
        ];

        return implode("\n", $lines);
    }
}
