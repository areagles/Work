<?php
$footerIsEn = function_exists('app_lang_is') ? app_lang_is('en') : false;
$footerDir = $footerIsEn ? 'ltr' : 'rtl';
$footerText = $footerIsEn ? 'Smart System' : 'النظام الذكي';
$footerVersion = function_exists('app_version') ? app_version(isset($conn) && $conn instanceof mysqli ? $conn : null) : '2026.03.29';
$footerCurrentPage = basename((string)($_SERVER['PHP_SELF'] ?? ''));
$footerCurrentUserId = (int)($_SESSION['user_id'] ?? 0);
$footerChatBlockedPages = ['login.php', 'client_review.php', 'view_quote.php', 'print_invoice.php', 'install.php', 'license_api.php'];
$showInternalChatWidget = $footerCurrentUserId > 0 && !in_array($footerCurrentPage, $footerChatBlockedPages, true);
$footerAiAssistantEnabled = $footerCurrentUserId > 0 && !in_array($footerCurrentPage, $footerChatBlockedPages, true);
$footerAiProvider = trim((string)app_setting_get($conn, 'ai_provider', app_env('AI_PROVIDER', 'ollama')));
if ($footerAiProvider === '') {
    $footerAiProvider = 'ollama';
}
$footerAiEnabledRaw = trim((string)app_setting_get($conn, 'ai_enabled', app_setting_get($conn, 'ai_openai_enabled', app_env('AI_ENABLED', app_env('OPENAI_ENABLED', '0')))));
$footerAiEnabled = !in_array(strtolower($footerAiEnabledRaw), ['0', 'false', 'off', 'no'], true);
$footerAiBaseUrl = trim((string)app_setting_get($conn, 'ai_base_url', app_env('AI_BASE_URL', '')));
$footerAiApiKey = trim((string)app_setting_get($conn, 'ai_api_key', app_env('AI_API_KEY', '')));
$footerAiLegacyKey = trim((string)app_setting_get($conn, 'ai_openai_api_key', app_env('OPENAI_API_KEY', '')));
$footerAiConfigured = $footerAiProvider === 'ollama'
    ? ($footerAiEnabled && trim($footerAiBaseUrl !== '' ? $footerAiBaseUrl : 'http://127.0.0.1:11434/v1') !== '')
    : ($footerAiEnabled && trim($footerAiApiKey !== '' ? $footerAiApiKey : $footerAiLegacyKey) !== '');
$footerChatLabels = [
    'title' => app_tr('شات الفريق', 'Team Chat'),
    'online' => app_tr('لحظي', 'Live'),
    'direct_tab' => app_tr('فردي', 'Direct'),
    'group_tab' => app_tr('جروب عام', 'General Group'),
    'general_group' => app_tr('الجروب العام', 'General Group'),
    'users' => app_tr('المستخدمون', 'Users'),
    'select_user' => app_tr('اختر مستخدمًا لبدء المحادثة.', 'Select a user to start chat.'),
    'no_users' => app_tr('لا يوجد مستخدمون متاحون.', 'No users available.'),
    'write_message' => app_tr('اكتب رسالة...', 'Write a message...'),
    'attach' => app_tr('ملف', 'File'),
    'voice' => app_tr('صوت', 'Voice'),
    'recording' => app_tr('جارٍ التسجيل...', 'Recording...'),
    'send' => app_tr('إرسال', 'Send'),
    'you' => app_tr('أنت', 'You'),
    'loading' => app_tr('جارٍ التحميل...', 'Loading...'),
    'open_chat' => app_tr('فتح شات الفريق', 'Open team chat'),
    'close' => app_tr('إغلاق', 'Close'),
    'connect_error' => app_tr('تعذر الاتصال بالشات الآن.', 'Could not connect to chat now.'),
    'select_peer_first' => app_tr('اختر مستخدمًا أولاً.', 'Select a user first.'),
    'voice_unsupported' => app_tr('المتصفح لا يدعم تسجيل الصوت.', 'Browser does not support voice recording.'),
    'mic_denied' => app_tr('تعذر الوصول للميكروفون. فعّل الصلاحية أو استخدم HTTPS.', 'Microphone access failed. Enable permission or use HTTPS.'),
    'sending' => app_tr('جارٍ الإرسال...', 'Sending...'),
    'choose_file' => app_tr('لم يتم اختيار ملف.', 'No file selected.'),
    'chat_files' => app_tr('صور، صوتيات، ملفات', 'Images, voice, files'),
    'no_messages' => app_tr('لا توجد رسائل بعد.', 'No messages yet.'),
    'image' => app_tr('صورة', 'Image'),
    'audio' => app_tr('صوت', 'Audio'),
    'file' => app_tr('ملف', 'File'),
    'new_message_from' => app_tr('رسالة جديدة من', 'New message from'),
    'new_group_message' => app_tr('رسالة جديدة في الجروب العام', 'New message in general group'),
    'job_update_title' => app_tr('تحديث حالة عملية', 'Job status update'),
    'job_stage' => app_tr('المرحلة', 'Stage'),
    'job_status' => app_tr('الحالة', 'Status'),
    'notifications_hint' => app_tr('فعّل إشعارات المتصفح للحصول على تنبيه فوري.', 'Enable browser notifications for instant alerts.'),
    'notifications_denied' => app_tr('إشعارات المتصفح محظورة. فعّلها من إعدادات المتصفح.', 'Browser notifications are blocked. Enable them from browser settings.'),
    'notifications_not_supported' => app_tr('هذا المتصفح لا يدعم إشعارات سطح المكتب.', 'This browser does not support desktop notifications.'),
];
$footerAiLabels = [
    'title' => app_tr('مساعد AI', 'AI Assistant'),
    'open' => app_tr('فتح المساعد', 'Open assistant'),
    'close' => app_tr('إغلاق', 'Close'),
    'placeholder' => app_tr('اكتب سؤالك أو اطلب خطة أو أفكارًا أو مساعدة تنفيذية...', 'Ask for ideas, plans, or execution help...'),
    'send' => app_tr('إرسال', 'Send'),
    'reset' => app_tr('محادثة جديدة', 'New chat'),
    'loading' => app_tr('جارٍ الاتصال بمزود AI...', 'Connecting to AI provider...'),
    'not_configured' => app_tr('مزود AI غير مفعّل بعد. فعّل Ollama أو أضف إعدادات المزود أولًا.', 'AI provider is not configured yet. Enable Ollama or add provider settings first.'),
    'connect_error' => app_tr('تعذر الوصول إلى مزود AI الآن.', 'Could not reach the AI provider right now.'),
];
?>

<style>
    .ae-footer-wrapper {
        text-align: center;
        padding: 18px 22px;
        font-family: 'Cairo', 'Poppins', sans-serif;
        font-size: 14px;
        color: #8f8f8f;
        direction: <?php echo $footerDir; ?>;
        width: fit-content;
        max-width: calc(100% - 24px);
        margin: 18px auto 0;
        border-radius: 999px;
        border: 1px solid rgba(212, 175, 55, 0.14);
        background: linear-gradient(180deg, rgba(18,18,18,0.82), rgba(10,10,10,0.8));
        box-shadow: 0 16px 32px rgba(0,0,0,0.22);
        backdrop-filter: blur(14px);
    }
    .ae-gold-link {
        color: #D4AF37;
        text-decoration: none;
        font-weight: bold;
        display: inline-block;
        text-shadow: 0.5px 0.5px 1px rgba(0,0,0,0.1);
        animation: ae-gold-pulse 3s infinite ease-in-out;
        transition: transform 0.2s ease;
    }
    .ae-gold-link:hover {
        transform: scale(1.05);
        color: #FFD700;
    }
    @keyframes ae-gold-pulse {
        0%, 100% { color: #D4AF37; filter: brightness(1); }
        50% { color: #FFD700; filter: brightness(1.2); }
    }
    @media (prefers-reduced-motion: reduce) {
        .ae-gold-link { animation: none !important; }
    }
    .ae-separator {
        margin: 0 5px;
        color: #ccc;
    }
    .ae-footer-version {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        margin-inline-start: 8px;
        padding: 3px 8px;
        border-radius: 999px;
        border: 1px solid rgba(212, 175, 55, 0.18);
        background: rgba(212, 175, 55, 0.08);
        color: #c9c9c9;
        font-size: 12px;
        vertical-align: middle;
    }
</style>

<div class="ae-footer-wrapper">
    <i class="fa-solid fa-crown" style="color:#D4AF37; margin-inline-end:8px;"></i>
    <?php echo app_h($footerText); ?>
    <?php echo date('Y'); ?>
    <span class="ae-separator">•</span>
    <a href="http://www.areagles.com" target="_blank" rel="noopener noreferrer" class="ae-gold-link">Arab Eagles</a>
    <a href="whats_new.php" class="ae-footer-version" title="<?php echo app_h(app_tr('ما الجديد في هذا الإصدار', 'What is new in this release')); ?>">v<?php echo app_h($footerVersion); ?></a>
</div>

<?php if ($showInternalChatWidget): ?>
    <style>
        .icw-root {
            position: fixed !important;
            inset-inline-end: 16px;
            right: 16px;
            bottom: calc(var(--icw-bottom, 18px) + env(safe-area-inset-bottom, 0px));
            z-index: 2147483000;
            pointer-events: none;
            transform: translateZ(0);
            font-family: 'Cairo', 'Poppins', sans-serif;
        }
        .icw-root > * { pointer-events: auto; }
        .icw-root:not(.open) .icw-fab {
            animation: icwFabFloat 3.4s ease-in-out infinite;
        }
        .icw-root.alert .icw-fab {
            animation: icwFabAlert .9s ease-in-out 2;
        }
        .icw-fab {
            width: 58px;
            height: 58px;
            border-radius: 50%;
            border: 1px solid rgba(212, 175, 55, 0.72);
            background: radial-gradient(circle at 30% 20%, #f5dd84 0%, #d4af37 55%, #8f6f12 100%);
            color: #111;
            box-shadow: 0 16px 30px rgba(0, 0, 0, 0.38);
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 1.15rem;
            position: relative;
            transition: transform .2s ease, box-shadow .2s ease;
        }
        .icw-fab::after {
            content: '';
            position: absolute;
            inset: -6px;
            border-radius: 50%;
            border: 1px solid rgba(212, 175, 55, 0.22);
            pointer-events: none;
        }
        .icw-fab:hover { transform: translateY(-2px) scale(1.02); box-shadow: 0 20px 38px rgba(0, 0, 0, 0.45); }
        .icw-fab-badge {
            position: absolute;
            top: -4px;
            inset-inline-start: -6px;
            left: -6px;
            min-width: 20px;
            height: 20px;
            padding: 0 6px;
            border-radius: 999px;
            background: #e53935;
            color: #fff;
            font-size: .72rem;
            line-height: 20px;
            text-align: center;
            font-weight: 700;
            border: 1px solid rgba(0, 0, 0, 0.35);
            display: none;
        }
        .icw-panel {
            position: absolute;
            bottom: 74px;
            inset-inline-end: 0;
            right: 0;
            width: min(930px, calc(100vw - 20px));
            height: min(640px, calc(100vh - 110px));
            background: linear-gradient(160deg, rgba(12, 14, 22, 0.98), rgba(10, 10, 12, 0.98));
            border: 1px solid rgba(212, 175, 55, 0.42);
            border-radius: 18px;
            box-shadow: 0 22px 46px rgba(0, 0, 0, 0.45);
            overflow: hidden;
            display: none;
        }
        .icw-panel.open { display: flex; flex-direction: column; }
        .icw-panel.group-mode .icw-contacts-wrap { display: none; }
        .icw-panel.group-mode .icw-body { grid-template-columns: 1fr; }
        .icw-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            padding: 10px 14px;
            border-bottom: 1px solid rgba(212, 175, 55, 0.22);
            background: rgba(255, 255, 255, 0.02);
        }
        .icw-title {
            color: #f3d67c;
            font-size: 1rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .icw-live {
            color: #8de39e;
            font-size: .78rem;
            background: rgba(48, 125, 69, 0.24);
            border: 1px solid rgba(69, 177, 96, 0.35);
            border-radius: 999px;
            padding: 2px 8px;
        }
        .icw-head-right {
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .icw-close {
            border: 1px solid #3d3d3d;
            background: #1b1b1f;
            color: #ddd;
            width: 32px;
            height: 32px;
            border-radius: 8px;
            cursor: pointer;
        }
        .icw-modes {
            display: inline-flex;
            gap: 6px;
            align-items: center;
        }
        .icw-mode-btn {
            border: 1px solid rgba(212, 175, 55, 0.36);
            background: rgba(255, 255, 255, 0.03);
            color: #d7d7d7;
            border-radius: 999px;
            font-size: .76rem;
            font-weight: 700;
            padding: 5px 10px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .icw-mode-btn.active {
            color: #141414;
            background: linear-gradient(135deg, #e4c65f, #c09522);
            border-color: rgba(255, 232, 160, 0.5);
        }
        .icw-mode-badge {
            min-width: 16px;
            height: 16px;
            line-height: 16px;
            border-radius: 999px;
            background: #e53935;
            color: #fff;
            font-size: .64rem;
            text-align: center;
            padding: 0 4px;
            display: none;
        }
        .icw-notify-hint {
            padding: 6px 12px;
            font-size: .74rem;
            color: #aab4c3;
            border-bottom: 1px dashed rgba(212, 175, 55, 0.18);
            background: rgba(0, 0, 0, 0.18);
            display: none;
        }
        .icw-body {
            display: grid;
            grid-template-columns: 290px minmax(0, 1fr);
            min-height: 0;
            flex: 1;
        }
        .icw-contacts-wrap {
            border-inline-end: 1px solid rgba(212, 175, 55, 0.17);
            background: rgba(255, 255, 255, 0.02);
            min-height: 0;
            display: flex;
            flex-direction: column;
        }
        .icw-contacts-head {
            color: #b9b9b9;
            font-size: .84rem;
            padding: 10px 12px;
            border-bottom: 1px solid rgba(212, 175, 55, 0.12);
        }
        .icw-contacts {
            overflow: auto;
            min-height: 0;
            padding: 6px;
            display: flex;
            flex-direction: column;
            gap: 6px;
        }
        .icw-contact-btn {
            width: 100%;
            border: 1px solid rgba(255, 255, 255, 0.08);
            background: rgba(255, 255, 255, 0.03);
            border-radius: 10px;
            padding: 8px 10px;
            color: #e3e3e3;
            text-align: start;
            cursor: pointer;
            display: grid;
            grid-template-columns: 34px minmax(0, 1fr) auto;
            gap: 8px;
            align-items: center;
        }
        .icw-contact-btn.active {
            border-color: rgba(212, 175, 55, 0.65);
            background: rgba(212, 175, 55, 0.12);
        }
        .icw-contact-avatar {
            width: 34px;
            height: 34px;
            border-radius: 50%;
            object-fit: cover;
            border: 1px solid rgba(255, 255, 255, 0.2);
            background: #222;
        }
        .icw-contact-main { min-width: 0; }
        .icw-contact-name {
            font-size: .9rem;
            font-weight: 700;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .icw-contact-preview {
            font-size: .75rem;
            color: #a9a9a9;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .icw-contact-meta {
            display: flex;
            flex-direction: column;
            align-items: end;
            gap: 4px;
        }
        .icw-contact-time {
            font-size: .69rem;
            color: #8f8f8f;
            white-space: nowrap;
        }
        .icw-contact-unread {
            min-width: 18px;
            height: 18px;
            border-radius: 999px;
            background: #e53935;
            color: #fff;
            font-size: .68rem;
            line-height: 18px;
            text-align: center;
            padding: 0 4px;
            font-weight: 700;
        }
        .icw-thread {
            min-height: 0;
            display: flex;
            flex-direction: column;
        }
        .icw-thread-head {
            border-bottom: 1px solid rgba(212, 175, 55, 0.14);
            padding: 10px 12px;
            color: #f2d58a;
            font-weight: 700;
            min-height: 44px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
        }
        .icw-thread-mode {
            font-size: .72rem;
            border: 1px solid rgba(212, 175, 55, 0.4);
            border-radius: 999px;
            padding: 2px 8px;
            color: #d4b86b;
            background: rgba(212, 175, 55, 0.1);
        }
        .icw-messages {
            flex: 1;
            min-height: 0;
            overflow: auto;
            padding: 10px 12px;
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        .icw-empty {
            color: #9e9e9e;
            text-align: center;
            padding: 20px 10px;
            font-size: .93rem;
        }
        .icw-msg-row { display: flex; }
        .icw-msg-row.me { justify-content: flex-end; }
        .icw-msg {
            max-width: min(72%, 620px);
            border: 1px solid rgba(255, 255, 255, 0.1);
            background: rgba(255, 255, 255, 0.05);
            border-radius: 12px;
            padding: 8px 9px;
            color: #ececec;
        }
        .icw-msg-row.me .icw-msg {
            border-color: rgba(212, 175, 55, 0.55);
            background: rgba(212, 175, 55, 0.14);
        }
        .icw-msg-head {
            display: flex;
            gap: 6px;
            font-size: .72rem;
            color: #b8b8b8;
            margin-bottom: 4px;
            white-space: nowrap;
        }
        .icw-msg-text {
            white-space: pre-wrap;
            line-height: 1.6;
            font-size: .9rem;
        }
        .icw-msg-attachment { margin-top: 7px; }
        .icw-msg-attachment img {
            display: block;
            width: 100%;
            max-width: 250px;
            border-radius: 8px;
            border: 1px solid rgba(255, 255, 255, 0.16);
        }
        .icw-msg-attachment audio {
            width: 250px;
            max-width: 100%;
        }
        .icw-msg-file {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            color: #ffd87a;
            text-decoration: none;
            border: 1px dashed rgba(212, 175, 55, 0.45);
            border-radius: 8px;
            padding: 5px 8px;
            font-size: .86rem;
        }
        .icw-composer {
            border-top: 1px solid rgba(212, 175, 55, 0.14);
            padding: 8px 10px 10px;
            background: rgba(0, 0, 0, 0.16);
        }
        .icw-text {
            width: 100%;
            min-height: 56px;
            max-height: 150px;
            resize: vertical;
            border-radius: 10px;
            border: 1px solid rgba(212, 175, 55, 0.3);
            background: #0d1018;
            color: #f3f3f3;
            padding: 8px 10px;
            font-family: inherit;
        }
        .icw-tools {
            margin-top: 8px;
            display: flex;
            gap: 6px;
            justify-content: space-between;
            flex-wrap: wrap;
        }
        .icw-btn {
            border: 1px solid rgba(212, 175, 55, 0.4);
            background: #1a1b21;
            color: #f4d87f;
            border-radius: 9px;
            padding: 7px 10px;
            cursor: pointer;
            font-size: .84rem;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .icw-btn.send {
            background: linear-gradient(135deg, #d8b74b, #a98118);
            color: #131313;
            border-color: rgba(255, 232, 160, 0.45);
        }
        .icw-btn.voice.recording {
            background: rgba(197, 47, 47, 0.22);
            border-color: rgba(255, 96, 96, 0.6);
            color: #ffd1d1;
            animation: icwPulse 1.2s infinite ease-in-out;
        }
        .icw-filename {
            margin-top: 5px;
            font-size: .76rem;
            color: #aeb3bd;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .icw-status {
            margin-top: 4px;
            min-height: 16px;
            font-size: .74rem;
            color: #9199a8;
        }
        .icw-status.err { color: #ff9b9b; }
        .icw-toast-wrap {
            position: fixed;
            inset-inline-end: 16px;
            right: 16px;
            bottom: 92px;
            z-index: 2699;
            display: flex;
            flex-direction: column;
            gap: 8px;
            pointer-events: none;
        }
        .icw-toast {
            background: rgba(16, 16, 20, 0.96);
            border: 1px solid rgba(212, 175, 55, 0.42);
            color: #f2f2f2;
            border-radius: 11px;
            padding: 9px 11px;
            min-width: 220px;
            max-width: 320px;
            box-shadow: 0 10px 24px rgba(0, 0, 0, 0.35);
            font-size: .83rem;
            line-height: 1.5;
            animation: icwToastIn .2s ease-out;
        }
        @keyframes icwToastIn {
            from { opacity: 0; transform: translateY(8px); }
            to { opacity: 1; transform: translateY(0); }
        }
        @keyframes icwFabFloat {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-5px); }
        }
        @keyframes icwFabAlert {
            0%, 100% { transform: translateY(0) scale(1); }
            20% { transform: translateY(-4px) scale(1.04); }
            40% { transform: translateY(0) scale(1); }
            60% { transform: translateY(-3px) scale(1.03); }
        }
        @keyframes icwPulse {
            0%, 100% { box-shadow: 0 0 0 0 rgba(229, 57, 53, 0.25); }
            50% { box-shadow: 0 0 0 8px rgba(229, 57, 53, 0); }
        }
        @media (max-width: 900px) {
            .icw-root { inset-inline-end: 10px; right: 10px; --icw-bottom: 12px; }
            .icw-panel {
                width: calc(100vw - 12px);
                height: calc(100vh - 88px);
                bottom: 68px;
                border-radius: 14px;
            }
            .icw-body { grid-template-columns: 1fr; }
            .icw-panel:not(.group-mode) .icw-contacts-wrap {
                max-height: 210px;
                border-inline-end: none;
                border-bottom: 1px solid rgba(212, 175, 55, 0.17);
            }
            .icw-msg { max-width: 92%; }
            .icw-text { min-height: 50px; }
            .icw-toast-wrap { bottom: 84px; inset-inline-end: 10px; right: 10px; }
        }
        @media (max-width: 420px) {
            .icw-root {
                inset-inline-end: 8px;
                right: 8px;
                --icw-bottom: 10px;
            }
            .icw-fab {
                width: 52px;
                height: 52px;
                font-size: 1rem;
            }
            .icw-fab-badge {
                min-width: 18px;
                height: 18px;
                line-height: 18px;
                font-size: .68rem;
            }
            .icw-panel {
                width: calc(100vw - 8px);
                height: calc(100vh - 78px);
                bottom: 60px;
                border-radius: 12px;
            }
            .icw-head {
                padding: 9px 10px;
                align-items: flex-start;
                flex-direction: column;
            }
            .icw-head-right,
            .icw-modes {
                width: 100%;
            }
            .icw-head-right {
                justify-content: space-between;
            }
            .icw-modes {
                flex-wrap: wrap;
                gap: 5px;
            }
            .icw-mode-btn {
                flex: 1 1 0;
                justify-content: center;
                min-width: 0;
                padding: 6px 8px;
            }
            .icw-thread-head,
            .icw-messages,
            .icw-composer {
                padding-inline: 10px;
            }
            .icw-msg {
                max-width: 100%;
            }
            .icw-tools > div,
            .icw-tools {
                width: 100%;
            }
            .icw-tools > div {
                justify-content: stretch;
            }
            .icw-btn {
                flex: 1 1 0;
                justify-content: center;
            }
            .icw-toast-wrap {
                inset-inline-end: 8px;
                right: 8px;
                bottom: 74px;
            }
            .icw-toast {
                min-width: 0;
                max-width: calc(100vw - 24px);
            }
        }
        @media (prefers-reduced-motion: reduce), (max-width: 640px) {
            .icw-root:not(.open) .icw-fab,
            .icw-root.alert .icw-fab,
            .icw-toast {
                animation: none !important;
            }
        }
    </style>

    <div id="icwRoot" class="icw-root" data-api-url="internal_chat_api.php" data-csrf="<?php echo app_h(app_csrf_token()); ?>" data-user-id="<?php echo (int)$footerCurrentUserId; ?>">
        <button id="icwFab" class="icw-fab" type="button" title="<?php echo app_h($footerChatLabels['open_chat']); ?>">
            <i class="fa-solid fa-comments"></i>
            <span id="icwFabBadge" class="icw-fab-badge">0</span>
        </button>

        <section id="icwPanel" class="icw-panel" aria-label="<?php echo app_h($footerChatLabels['title']); ?>">
            <div class="icw-head">
                <div class="icw-title">
                    <i class="fa-solid fa-comment-dots"></i>
                    <span><?php echo app_h($footerChatLabels['title']); ?></span>
                    <span class="icw-live"><?php echo app_h($footerChatLabels['online']); ?></span>
                </div>
                <div class="icw-head-right">
                    <div class="icw-modes">
                        <button id="icwModeDirect" type="button" class="icw-mode-btn active"><?php echo app_h($footerChatLabels['direct_tab']); ?></button>
                        <button id="icwModeGroup" type="button" class="icw-mode-btn"><?php echo app_h($footerChatLabels['group_tab']); ?> <span id="icwGroupBadge" class="icw-mode-badge">0</span></button>
                    </div>
                    <button id="icwClose" class="icw-close" type="button" title="<?php echo app_h($footerChatLabels['close']); ?>">
                        <i class="fa-solid fa-xmark"></i>
                    </button>
                </div>
            </div>

            <div id="icwNotifyHint" class="icw-notify-hint"></div>

            <div class="icw-body">
                <aside class="icw-contacts-wrap">
                    <div class="icw-contacts-head"><?php echo app_h($footerChatLabels['users']); ?></div>
                    <div id="icwContacts" class="icw-contacts"></div>
                </aside>

                <div class="icw-thread">
                    <div class="icw-thread-head">
                        <span id="icwPeerName"><?php echo app_h($footerChatLabels['select_user']); ?></span>
                        <span id="icwThreadModeBadge" class="icw-thread-mode"><?php echo app_h($footerChatLabels['direct_tab']); ?></span>
                    </div>
                    <div id="icwMessages" class="icw-messages">
                        <div class="icw-empty"><?php echo app_h($footerChatLabels['select_user']); ?></div>
                    </div>
                    <form id="icwComposer" class="icw-composer">
                        <textarea id="icwText" class="icw-text" placeholder="<?php echo app_h($footerChatLabels['write_message']); ?>"></textarea>
                        <div class="icw-tools">
                            <div style="display:flex; gap:6px; flex-wrap:wrap;">
                                <input type="file" id="icwFile" style="display:none" accept="image/*,audio/*,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.csv,.zip,.rar">
                                <button id="icwAttach" class="icw-btn" type="button"><i class="fa-solid fa-paperclip"></i> <?php echo app_h($footerChatLabels['attach']); ?></button>
                                <button id="icwVoice" class="icw-btn voice" type="button"><i class="fa-solid fa-microphone"></i> <?php echo app_h($footerChatLabels['voice']); ?></button>
                            </div>
                            <button id="icwSend" class="icw-btn send" type="submit"><i class="fa-solid fa-paper-plane"></i> <?php echo app_h($footerChatLabels['send']); ?></button>
                        </div>
                        <div id="icwFileName" class="icw-filename"><?php echo app_h($footerChatLabels['choose_file']); ?></div>
                        <div id="icwStatus" class="icw-status"></div>
                    </form>
                </div>
            </div>
        </section>

        <div id="icwToastWrap" class="icw-toast-wrap"></div>
    </div>

    <script>
        (() => {
            const labels = <?php echo json_encode($footerChatLabels, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
            const root = document.getElementById('icwRoot');
            if (!root) return;
            try {
                if (root.parentElement !== document.body) {
                    document.body.appendChild(root);
                }
            } catch (err) {}

            const applyDockPosition = () => {
                let baseBottom = window.matchMedia('(max-width: 900px)').matches ? 12 : 18;
                const vv = window.visualViewport;
                if (vv) {
                    const viewportGap = Math.max(0, window.innerHeight - (vv.height + vv.offsetTop));
                    baseBottom = Math.max(baseBottom, viewportGap + (window.matchMedia('(max-width: 900px)').matches ? 8 : 12));
                }
                root.style.setProperty('--icw-bottom', `${Math.round(baseBottom)}px`);
            };
            applyDockPosition();
            window.addEventListener('resize', applyDockPosition, { passive: true });
            if (window.visualViewport) {
                window.visualViewport.addEventListener('resize', applyDockPosition, { passive: true });
                window.visualViewport.addEventListener('scroll', applyDockPosition, { passive: true });
            }

            const apiUrl = root.dataset.apiUrl || 'internal_chat_api.php';
            const csrf = root.dataset.csrf || (typeof csrfToken !== 'undefined' ? csrfToken : '');
            const userId = Number(root.dataset.userId || 0);
            const mobileLite = window.matchMedia('(max-width: 900px)').matches;
            const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
            const coarsePointer = window.matchMedia('(pointer: coarse)').matches;
            const conn = navigator.connection || navigator.mozConnection || navigator.webkitConnection || null;
            const saveData = !!(conn && conn.saveData);
            const slowNetwork = !!(conn && /(^|-)2g$/i.test(String(conn.effectiveType || '')));
            const lowMemory = Number(navigator.deviceMemory || 8) <= 4;
            const lowPowerMode = mobileLite || reducedMotion || coarsePointer || saveData || slowNetwork || lowMemory;
            const pollOpenMs = lowPowerMode ? 7000 : 3000;
            const pollClosedMs = lowPowerMode ? 18000 : 9000;
            const pollHiddenMs = lowPowerMode ? 30000 : 14000;
            const schedulerTickMs = lowPowerMode ? 2800 : 1800;
            const jobTsStorageKey = `icw_last_job_ts_${userId}`;

            const fab = document.getElementById('icwFab');
            const fabBadge = document.getElementById('icwFabBadge');
            const panel = document.getElementById('icwPanel');
            const closeBtn = document.getElementById('icwClose');
            const modeDirectBtn = document.getElementById('icwModeDirect');
            const modeGroupBtn = document.getElementById('icwModeGroup');
            const groupBadge = document.getElementById('icwGroupBadge');
            const contactsBox = document.getElementById('icwContacts');
            const notifyHint = document.getElementById('icwNotifyHint');
            const peerNameNode = document.getElementById('icwPeerName');
            const threadModeBadge = document.getElementById('icwThreadModeBadge');
            const messagesBox = document.getElementById('icwMessages');
            const composer = document.getElementById('icwComposer');
            const textInput = document.getElementById('icwText');
            const fileInput = document.getElementById('icwFile');
            const attachBtn = document.getElementById('icwAttach');
            const voiceBtn = document.getElementById('icwVoice');
            const sendBtn = document.getElementById('icwSend');
            const fileNameNode = document.getElementById('icwFileName');
            const statusNode = document.getElementById('icwStatus');
            const toastWrap = document.getElementById('icwToastWrap');

            const state = {
                open: false,
                mode: 'direct',
                contacts: [],
                group: null,
                activePeerId: 0,
                directLastMessageId: 0,
                groupLastMessageId: 0,
                directUnreadMap: {},
                groupUnread: 0,
                sending: false,
                polling: false,
                recorder: null,
                recordStream: null,
                recordChunks: [],
                recording: false,
                lastPollAt: 0,
                lastJobTs: 0,
                notifiedJobKeys: {},
                initialized: false,
            };

            try {
                state.lastJobTs = Number(localStorage.getItem(jobTsStorageKey) || 0);
            } catch (err) {
                state.lastJobTs = 0;
            }

            const t = (key, fallback = '') => {
                if (labels && Object.prototype.hasOwnProperty.call(labels, key)) return labels[key];
                return fallback || key;
            };

            const esc = (value) => String(value ?? '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/\"/g, '&quot;')
                .replace(/'/g, '&#039;');

            const textHtml = (value) => esc(value).replace(/\n/g, '<br>');

            const setStatus = (text = '', isError = false) => {
                statusNode.textContent = text;
                statusNode.classList.toggle('err', !!isError);
            };

            const showHint = (text = '') => {
                if (!text) {
                    notifyHint.style.display = 'none';
                    notifyHint.textContent = '';
                    return;
                }
                notifyHint.textContent = text;
                notifyHint.style.display = 'block';
            };

            const updateFabBadge = (count) => {
                const num = Number(count || 0);
                if (num > 0) {
                    fabBadge.textContent = num > 99 ? '99+' : String(num);
                    fabBadge.style.display = 'inline-block';
                } else {
                    fabBadge.textContent = '0';
                    fabBadge.style.display = 'none';
                }
            };

            const updateGroupBadge = (count) => {
                const num = Number(count || 0);
                if (num > 0) {
                    groupBadge.textContent = num > 99 ? '99+' : String(num);
                    groupBadge.style.display = 'inline-block';
                } else {
                    groupBadge.textContent = '0';
                    groupBadge.style.display = 'none';
                }
            };

            const blinkFab = () => {
                root.classList.add('alert');
                setTimeout(() => root.classList.remove('alert'), 1800);
            };

            const showToast = (title, body = '') => {
                const toast = document.createElement('div');
                toast.className = 'icw-toast';
                toast.innerHTML = `<strong>${esc(title)}</strong>${body ? `<div style=\"margin-top:3px;opacity:.88\">${esc(body)}</div>` : ''}`;
                toastWrap.appendChild(toast);
                setTimeout(() => toast.remove(), 4200);
            };

            const pushBrowserNotification = (title, body, tag = '') => {
                if (!('Notification' in window)) {
                    showHint(t('notifications_not_supported', 'This browser does not support desktop notifications.'));
                    showToast(title, body);
                    return;
                }

                if (Notification.permission === 'granted') {
                    try {
                        const n = new Notification(title, { body, tag: tag || undefined, icon: 'assets/img/Logo.png' });
                        n.onclick = () => {
                            window.focus();
                            openPanel();
                        };
                    } catch (err) {
                        showToast(title, body);
                    }
                } else {
                    showToast(title, body);
                }
            };

            const requestNotificationPermission = async () => {
                if (!('Notification' in window)) {
                    showHint(t('notifications_not_supported', 'This browser does not support desktop notifications.'));
                    return;
                }
                if (Notification.permission === 'default') {
                    showHint(t('notifications_hint', 'Enable browser notifications for instant alerts.'));
                    try {
                        const p = await Notification.requestPermission();
                        if (p === 'granted') {
                            showHint('');
                        } else if (p === 'denied') {
                            showHint(t('notifications_denied', 'Browser notifications are blocked. Enable them from browser settings.'));
                        }
                    } catch (err) {}
                } else if (Notification.permission === 'denied') {
                    showHint(t('notifications_denied', 'Browser notifications are blocked. Enable them from browser settings.'));
                } else {
                    showHint('');
                }
            };

            const activePeer = () => state.contacts.find((c) => Number(c.id) === Number(state.activePeerId)) || null;

            const updateModeUI = () => {
                const isGroup = state.mode === 'group';
                modeDirectBtn.classList.toggle('active', !isGroup);
                modeGroupBtn.classList.toggle('active', isGroup);
                panel.classList.toggle('group-mode', isGroup);
                threadModeBadge.textContent = isGroup ? t('group_tab', 'General Group') : t('direct_tab', 'Direct');
                if (isGroup) {
                    peerNameNode.textContent = (state.group && state.group.name) ? state.group.name : t('general_group', 'General Group');
                } else {
                    const peer = activePeer();
                    peerNameNode.textContent = peer ? (peer.full_name || '') : t('select_user', 'Select a user to start chat.');
                }
            };

            const renderContacts = () => {
                contactsBox.innerHTML = '';
                if (!Array.isArray(state.contacts) || state.contacts.length === 0) {
                    contactsBox.innerHTML = `<div class=\"icw-empty\">${esc(t('no_users', 'No users available.'))}</div>`;
                    return;
                }

                for (const c of state.contacts) {
                    const isActive = Number(c.id) === Number(state.activePeerId);
                    const unread = Number(c.unread_count || 0);
                    const btn = document.createElement('button');
                    btn.type = 'button';
                    btn.className = 'icw-contact-btn' + (isActive ? ' active' : '');
                    btn.dataset.peerId = String(c.id || '');
                    btn.innerHTML = ''
                        + `<img class=\"icw-contact-avatar\" src=\"${esc(c.avatar_url || '')}\" alt=\"${esc(c.full_name || 'User')}\">`
                        + '<div class=\"icw-contact-main\">'
                        + `  <div class=\"icw-contact-name\">${esc(c.full_name || '')}</div>`
                        + `  <div class=\"icw-contact-preview\">${esc(c.last_message_preview || t('no_messages', 'No messages yet.'))}</div>`
                        + '</div>'
                        + '<div class=\"icw-contact-meta\">'
                        + `  <div class=\"icw-contact-time\">${esc(c.last_message_at || '')}</div>`
                        + (unread > 0 ? `  <div class=\"icw-contact-unread\">${esc(unread > 99 ? '99+' : String(unread))}</div>` : '')
                        + '</div>';
                    contactsBox.appendChild(btn);
                }
            };

            const renderEmptyThread = (text) => {
                messagesBox.innerHTML = `<div class=\"icw-empty\">${esc(text || t('no_messages', 'No messages yet.'))}</div>`;
                if (state.mode === 'group') {
                    state.groupLastMessageId = 0;
                } else {
                    state.directLastMessageId = 0;
                }
            };

            const messageCard = (msg) => {
                const row = document.createElement('div');
                row.className = 'icw-msg-row' + (msg.is_me ? ' me' : ' other');
                row.dataset.msgId = String(msg.id || '');

                const senderLabel = msg.is_me ? t('you', 'You') : (msg.sender_name || '');
                const kind = String(msg.attachment_kind || 'none');
                const url = String(msg.attachment_url || '').trim();
                const attachmentName = String(msg.attachment_name || '').trim();

                let attachHtml = '';
                if (url !== '') {
                    if (kind === 'image') {
                        attachHtml = `<div class=\"icw-msg-attachment\"><a href=\"${esc(url)}\" target=\"_blank\" rel=\"noopener\"><img src=\"${esc(url)}\" alt=\"${esc(attachmentName || t('image', 'Image'))}\" loading=\"lazy\"></a></div>`;
                    } else if (kind === 'audio') {
                        attachHtml = `<div class=\"icw-msg-attachment\"><audio controls preload=\"none\" src=\"${esc(url)}\"></audio></div>`;
                    } else {
                        const displayName = attachmentName || t('file', 'File');
                        attachHtml = `<div class=\"icw-msg-attachment\"><a class=\"icw-msg-file\" href=\"${esc(url)}\" target=\"_blank\" rel=\"noopener\" download><i class=\"fa-solid fa-file-arrow-down\"></i>${esc(displayName)}</a></div>`;
                    }
                }

                row.innerHTML = ''
                    + '<div class=\"icw-msg\">'
                    + '  <div class=\"icw-msg-head\">'
                    + `    <strong>${esc(senderLabel)}</strong><span>•</span><span>${esc(msg.created_at_text || '')}</span>`
                    + '  </div>'
                    + (msg.text ? `  <div class=\"icw-msg-text\">${textHtml(msg.text)}</div>` : '')
                    + attachHtml
                    + '</div>';
                return row;
            };

            const appendMessages = (messages, replace = false) => {
                const list = Array.isArray(messages) ? messages : [];
                if (replace) {
                    messagesBox.innerHTML = '';
                }
                if (!list.length && replace) {
                    renderEmptyThread(t('no_messages', 'No messages yet.'));
                    return;
                }

                const nearBottom = (messagesBox.scrollHeight - messagesBox.scrollTop - messagesBox.clientHeight) < 120;
                const empty = messagesBox.querySelector('.icw-empty');
                if (empty) empty.remove();

                for (const msg of list) {
                    const mid = Number(msg.id || 0);
                    if (mid > 0 && messagesBox.querySelector(`[data-msg-id=\"${mid}\"]`)) {
                        continue;
                    }
                    messagesBox.appendChild(messageCard(msg));
                    if (state.mode === 'group') {
                        if (mid > state.groupLastMessageId) state.groupLastMessageId = mid;
                    } else {
                        if (mid > state.directLastMessageId) state.directLastMessageId = mid;
                    }
                }

                if (replace || nearBottom) {
                    messagesBox.scrollTop = messagesBox.scrollHeight;
                }
            };

            const saveJobTs = () => {
                try {
                    localStorage.setItem(jobTsStorageKey, String(state.lastJobTs || 0));
                } catch (err) {}
            };

            const handleNotifications = (incomingContacts, incomingGroup, jobUpdates, notify = false) => {
                if (!notify) return;

                let shouldBlink = false;

                if (Array.isArray(incomingContacts)) {
                    for (const c of incomingContacts) {
                        const cid = Number(c.id || 0);
                        if (!cid) continue;
                        const newUnread = Number(c.unread_count || 0);
                        const oldUnread = Number(state.directUnreadMap[cid] || 0);
                        if (newUnread > oldUnread) {
                            const title = `${t('new_message_from', 'New message from')} ${c.full_name || ''}`;
                            const body = c.last_message_preview || '';
                            pushBrowserNotification(title, body, `direct-${cid}`);
                            shouldBlink = true;
                        }
                    }
                }

                const newGroupUnread = Number((incomingGroup && incomingGroup.unread_count) || 0);
                if (newGroupUnread > Number(state.groupUnread || 0)) {
                    pushBrowserNotification(t('new_group_message', 'New message in general group'), (incomingGroup && incomingGroup.last_message_preview) ? incomingGroup.last_message_preview : '', 'group-channel');
                    shouldBlink = true;
                }

                if (Array.isArray(jobUpdates)) {
                    for (const ev of jobUpdates) {
                        const jid = Number(ev.job_id || 0);
                        const uts = Number(ev.updated_ts || 0);
                        if (!jid || !uts) continue;
                        const key = `${jid}-${uts}`;
                        if (state.notifiedJobKeys[key]) continue;
                        state.notifiedJobKeys[key] = true;
                        const body = `${ev.job_name || ('#' + jid)} • ${t('job_stage', 'Stage')}: ${ev.stage_text || ev.stage || '-'} • ${t('job_status', 'Status')}: ${ev.status_text || ev.status || '-'}`;
                        pushBrowserNotification(t('job_update_title', 'Job status update'), body, `job-${jid}`);
                        shouldBlink = true;
                    }
                }

                if (shouldBlink && !state.open) {
                    blinkFab();
                }
            };

            const applyData = (data, notify = false) => {
                const nextContacts = Array.isArray(data.contacts) ? data.contacts : state.contacts;
                const nextGroup = data.group || state.group || null;
                const jobUpdates = Array.isArray(data.job_updates) ? data.job_updates : [];

                handleNotifications(nextContacts, nextGroup, jobUpdates, notify);

                state.contacts = nextContacts;
                renderContacts();

                const nextUnreadMap = {};
                for (const c of state.contacts) {
                    nextUnreadMap[Number(c.id || 0)] = Number(c.unread_count || 0);
                }
                state.directUnreadMap = nextUnreadMap;

                state.group = nextGroup;
                state.groupUnread = Number((nextGroup && nextGroup.unread_count) || 0);
                updateGroupBadge(state.groupUnread);
                updateFabBadge(Number(data.unread_total || 0));

                if (Number(data.latest_job_ts || 0) > state.lastJobTs) {
                    state.lastJobTs = Number(data.latest_job_ts || 0);
                    saveJobTs();
                }

                if (state.mode === 'direct') {
                    if (state.activePeerId > 0 && !activePeer()) {
                        state.activePeerId = 0;
                    }
                    if (state.activePeerId <= 0 && state.contacts.length > 0) {
                        state.activePeerId = Number(state.contacts[0].id || 0);
                    }
                }

                updateModeUI();
            };

            const getJson = async (params) => {
                const url = `${apiUrl}?${new URLSearchParams(params).toString()}`;
                const res = await fetch(url, { credentials: 'same-origin' });
                let data = {};
                try {
                    data = await res.json();
                } catch (err) {
                    data = {};
                }
                return data;
            };

            const postJson = async (formData) => {
                const res = await fetch(apiUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    body: formData,
                });
                let data = {};
                try {
                    data = await res.json();
                } catch (err) {
                    data = {};
                }
                return data;
            };

            const loadState = async (notify = false) => {
                try {
                    const data = await getJson({ action: 'state', since_job_ts: state.lastJobTs || 0, _: Date.now() });
                    if (!data || data.ok !== true) {
                        setStatus(data && data.message ? data.message : t('connect_error', 'Could not connect to chat now.'), true);
                        return;
                    }
                    applyData(data, notify);
                    if (!state.initialized) {
                        state.initialized = true;
                        if (state.mode === 'group') {
                            loadGroupThread();
                        } else {
                            loadDirectThread();
                        }
                    }
                } catch (err) {
                    setStatus(t('connect_error', 'Could not connect to chat now.'), true);
                }
            };

            const loadDirectThread = async () => {
                if (!state.activePeerId) {
                    renderEmptyThread(t('select_user', 'Select a user to start chat.'));
                    return;
                }
                setStatus(t('loading', 'Loading...'), false);
                state.directLastMessageId = 0;

                try {
                    const data = await getJson({
                        action: 'thread',
                        peer_id: state.activePeerId,
                        since: 0,
                        since_job_ts: state.lastJobTs || 0,
                        _: Date.now(),
                    });
                    if (!data || data.ok !== true) {
                        setStatus(data && data.message ? data.message : t('connect_error', 'Could not connect to chat now.'), true);
                        return;
                    }
                    applyData(data, false);
                    if (data.peer && data.peer.full_name) {
                        peerNameNode.textContent = data.peer.full_name;
                    }
                    appendMessages(data.messages || [], true);
                    state.directLastMessageId = Number(data.last_message_id || state.directLastMessageId || 0);
                    setStatus('', false);
                } catch (err) {
                    setStatus(t('connect_error', 'Could not connect to chat now.'), true);
                }
            };

            const loadGroupThread = async () => {
                setStatus(t('loading', 'Loading...'), false);
                state.groupLastMessageId = 0;
                peerNameNode.textContent = (state.group && state.group.name) ? state.group.name : t('general_group', 'General Group');
                try {
                    const data = await getJson({
                        action: 'group_thread',
                        since: 0,
                        since_job_ts: state.lastJobTs || 0,
                        _: Date.now(),
                    });
                    if (!data || data.ok !== true) {
                        setStatus(data && data.message ? data.message : t('connect_error', 'Could not connect to chat now.'), true);
                        return;
                    }
                    applyData(data, false);
                    peerNameNode.textContent = (data.group && data.group.name) ? data.group.name : t('general_group', 'General Group');
                    appendMessages(data.messages || [], true);
                    state.groupLastMessageId = Number(data.last_message_id || state.groupLastMessageId || 0);
                    setStatus('', false);
                } catch (err) {
                    setStatus(t('connect_error', 'Could not connect to chat now.'), true);
                }
            };

            const sendMessage = async (overrideFile = null) => {
                if (state.sending) return;
                if (state.mode === 'direct' && !state.activePeerId) {
                    setStatus(t('select_peer_first', 'Select a user first.'), true);
                    return;
                }

                const messageText = (textInput.value || '').trim();
                const file = overrideFile || (fileInput.files && fileInput.files[0] ? fileInput.files[0] : null);
                if (!messageText && !file) return;

                const fd = new FormData();
                fd.append('action', state.mode === 'group' ? 'send_group' : 'send');
                fd.append('_csrf_token', csrf);
                fd.append('message', messageText);
                if (state.mode === 'direct') {
                    fd.append('peer_id', String(state.activePeerId));
                }
                if (file) {
                    fd.append('attachment', file, file.name || 'attachment.bin');
                }

                state.sending = true;
                sendBtn.disabled = true;
                setStatus(t('sending', 'Sending...'), false);

                try {
                    const data = await postJson(fd);
                    if (!data || data.ok !== true) {
                        setStatus(data && data.message ? data.message : t('connect_error', 'Could not connect to chat now.'), true);
                        return;
                    }
                    applyData(data, false);
                    if (data.message) {
                        appendMessages([data.message], false);
                    }
                    textInput.value = '';
                    fileInput.value = '';
                    fileNameNode.textContent = t('choose_file', 'No file selected.');
                    setStatus('', false);
                } catch (err) {
                    setStatus(t('connect_error', 'Could not connect to chat now.'), true);
                } finally {
                    state.sending = false;
                    sendBtn.disabled = false;
                }
            };

            const stopRecorderStream = () => {
                if (state.recordStream) {
                    state.recordStream.getTracks().forEach((track) => track.stop());
                }
                state.recordStream = null;
            };

            const updateVoiceUi = () => {
                if (state.recording) {
                    voiceBtn.classList.add('recording');
                    voiceBtn.innerHTML = '<i class=\"fa-solid fa-stop\"></i> ' + esc(t('recording', 'Recording...'));
                } else {
                    voiceBtn.classList.remove('recording');
                    voiceBtn.innerHTML = '<i class=\"fa-solid fa-microphone\"></i> ' + esc(t('voice', 'Voice'));
                }
            };

            const startRecording = async () => {
                if (state.recording) return;
                if (!window.MediaRecorder || !navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
                    setStatus(t('voice_unsupported', 'Browser does not support voice recording.'), true);
                    return;
                }

                try {
                    const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
                    const recorder = new MediaRecorder(stream);
                    state.recordStream = stream;
                    state.recorder = recorder;
                    state.recordChunks = [];
                    state.recording = true;
                    updateVoiceUi();
                    setStatus(t('recording', 'Recording...'), false);

                    recorder.ondataavailable = (evt) => {
                        if (evt.data && evt.data.size > 0) {
                            state.recordChunks.push(evt.data);
                        }
                    };
                    recorder.onerror = () => {
                        setStatus(t('mic_denied', 'Microphone access failed. Enable permission or use HTTPS.'), true);
                        state.recording = false;
                        updateVoiceUi();
                        stopRecorderStream();
                    };
                    recorder.onstop = async () => {
                        const blob = new Blob(state.recordChunks, { type: recorder.mimeType || 'audio/webm' });
                        state.recording = false;
                        updateVoiceUi();
                        stopRecorderStream();
                        if (blob.size > 0) {
                            const file = new File([blob], `voice-${Date.now()}.webm`, { type: blob.type || 'audio/webm' });
                            await sendMessage(file);
                        } else {
                            setStatus('', false);
                        }
                    };
                    recorder.start();
                } catch (err) {
                    state.recording = false;
                    updateVoiceUi();
                    stopRecorderStream();
                    setStatus(t('mic_denied', 'Microphone access failed. Enable permission or use HTTPS.'), true);
                }
            };

            const stopRecording = () => {
                if (state.recorder && state.recording) {
                    state.recorder.stop();
                }
            };

            const poll = async () => {
                if (state.polling) return;
                state.polling = true;
                try {
                    const data = await getJson({
                        action: 'poll',
                        mode: state.mode,
                        peer_id: state.activePeerId || 0,
                        since: state.directLastMessageId || 0,
                        since_group: state.groupLastMessageId || 0,
                        since_job_ts: state.lastJobTs || 0,
                        _: Date.now(),
                    });
                    if (!data || data.ok !== true) {
                        return;
                    }

                    applyData(data, true);

                    if (state.mode === 'group') {
                        if (Array.isArray(data.new_group_messages) && data.new_group_messages.length > 0) {
                            appendMessages(data.new_group_messages, false);
                        }
                        state.groupLastMessageId = Math.max(state.groupLastMessageId, Number(data.last_group_id || 0));
                        if (data.group && data.group.name) {
                            peerNameNode.textContent = data.group.name;
                        }
                    } else {
                        if (Array.isArray(data.new_messages) && data.new_messages.length > 0) {
                            appendMessages(data.new_messages, false);
                        }
                        state.directLastMessageId = Math.max(state.directLastMessageId, Number(data.last_message_id || 0));
                        if (data.peer && data.peer.full_name) {
                            peerNameNode.textContent = data.peer.full_name;
                        }
                    }
                } catch (err) {
                    // temporary network error
                } finally {
                    state.polling = false;
                }
            };

            const setMode = async (mode) => {
                const next = mode === 'group' ? 'group' : 'direct';
                if (state.mode === next) return;
                state.mode = next;
                updateModeUI();
                if (state.mode === 'group') {
                    await loadGroupThread();
                } else {
                    await loadDirectThread();
                }
            };

            const openPanel = () => {
                state.open = true;
                root.classList.add('open');
                panel.classList.add('open');
                requestNotificationPermission();
                if (!state.initialized) {
                    loadState(false);
                } else if (state.mode === 'group') {
                    loadGroupThread();
                } else {
                    loadDirectThread();
                }
            };

            const closePanel = () => {
                state.open = false;
                root.classList.remove('open');
                panel.classList.remove('open');
            };

            fab.addEventListener('click', () => {
                if (state.open) {
                    closePanel();
                } else {
                    openPanel();
                }
            });
            closeBtn.addEventListener('click', closePanel);

            modeDirectBtn.addEventListener('click', async () => {
                await setMode('direct');
            });
            modeGroupBtn.addEventListener('click', async () => {
                await setMode('group');
            });

            contactsBox.addEventListener('click', async (evt) => {
                const btn = evt.target.closest('[data-peer-id]');
                if (!btn) return;
                const peerId = Number(btn.dataset.peerId || 0);
                if (!peerId) return;
                if (state.mode !== 'direct') {
                    state.mode = 'direct';
                    updateModeUI();
                }
                if (peerId === state.activePeerId) return;
                state.activePeerId = peerId;
                state.directLastMessageId = 0;
                renderContacts();
                await loadDirectThread();
            });

            attachBtn.addEventListener('click', () => fileInput.click());
            fileInput.addEventListener('change', () => {
                const f = fileInput.files && fileInput.files[0] ? fileInput.files[0] : null;
                fileNameNode.textContent = f ? f.name : t('choose_file', 'No file selected.');
            });

            voiceBtn.addEventListener('click', async () => {
                if (state.recording) {
                    stopRecording();
                } else {
                    await startRecording();
                }
            });

            composer.addEventListener('submit', async (evt) => {
                evt.preventDefault();
                await sendMessage();
            });

            textInput.addEventListener('keydown', async (evt) => {
                if (evt.key === 'Enter' && !evt.shiftKey) {
                    evt.preventDefault();
                    await sendMessage();
                }
            });

            loadState(false);
            setInterval(() => {
                const now = Date.now();
                const hidden = document.visibilityState === 'hidden';
                const wait = hidden ? pollHiddenMs : (state.open ? pollOpenMs : pollClosedMs);
                if ((now - state.lastPollAt) < wait) return;
                state.lastPollAt = now;
                poll();
            }, schedulerTickMs);
            document.addEventListener('visibilitychange', () => {
                if (document.visibilityState === 'visible') {
                    state.lastPollAt = 0;
                    poll();
                }
            });
        })();
    </script>
<?php endif; ?>

<?php if ($footerAiAssistantEnabled): ?>
    <style>
        .aiw-root {
            position: fixed !important;
            inset-inline-end: 16px;
            right: 16px;
            bottom: calc(var(--aiw-bottom, 92px) + env(safe-area-inset-bottom, 0px));
            z-index: 2147482900;
            pointer-events: none;
            transform: translateZ(0);
            font-family: 'Cairo', 'Poppins', sans-serif;
        }
        .aiw-root > * { pointer-events: auto; }
        .aiw-fab {
            width: 54px;
            height: 54px;
            border-radius: 50%;
            border: 1px solid rgba(75, 171, 247, 0.52);
            background: linear-gradient(135deg, #1c2c44, #0f1724);
            color: #e8f4ff;
            box-shadow: 0 16px 30px rgba(0, 0, 0, 0.34);
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 1.05rem;
        }
        .aiw-panel {
            position: absolute;
            bottom: 66px;
            inset-inline-end: 0;
            right: 0;
            width: min(380px, calc(100vw - 28px));
            max-height: min(70vh, 620px);
            background: #10151d;
            border: 1px solid #2a3444;
            border-radius: 16px;
            box-shadow: 0 26px 48px rgba(0,0,0,.46);
            display: none;
            overflow: hidden;
        }
        .aiw-root.open .aiw-panel { display:block; }
        .aiw-head {
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:10px;
            padding:12px 14px;
            border-bottom:1px solid #232c39;
            color:#edf5ff;
            background:linear-gradient(180deg,#111a25,#0d141d);
        }
        .aiw-title { font-weight:800; }
        .aiw-body { padding:12px; display:flex; flex-direction:column; gap:10px; }
        .aiw-log {
            min-height:220px;
            max-height:360px;
            overflow:auto;
            background:#0a0f15;
            border:1px solid #202734;
            border-radius:12px;
            padding:10px;
        }
        .aiw-msg { margin-bottom:10px; }
        .aiw-msg-role { font-size:.75rem; color:#8ba2bf; margin-bottom:4px; }
        .aiw-msg-text {
            white-space:pre-wrap;
            line-height:1.65;
            color:#f0f5fb;
            background:#121b26;
            border:1px solid #243041;
            border-radius:10px;
            padding:10px;
        }
        .aiw-msg.user .aiw-msg-text { background:#14202d; }
        .aiw-input {
            width:100%;
            min-height:92px;
            resize:vertical;
            background:#0c1117;
            border:1px solid #263140;
            border-radius:10px;
            color:#fff;
            padding:10px;
            box-sizing:border-box;
            font-family:inherit;
        }
        .aiw-actions { display:flex; gap:8px; }
        .aiw-btn {
            border:none;
            border-radius:10px;
            padding:10px 14px;
            cursor:pointer;
            font-weight:700;
        }
        .aiw-btn.primary { background:linear-gradient(90deg,#4baaf7,#78d3ff); color:#08121d; }
        .aiw-btn.secondary { background:#202b39; color:#dde9f5; }
        .aiw-status { display:none; font-size:.84rem; color:#8ba2bf; }
    </style>
    <div id="aiwRoot" class="aiw-root" data-api-url="ai_chat_api.php" data-csrf="<?php echo app_h(app_csrf_token()); ?>" data-configured="<?php echo $footerAiConfigured ? '1' : '0'; ?>">
        <button id="aiwFab" class="aiw-fab" type="button" title="<?php echo app_h($footerAiLabels['open']); ?>">
            <i class="fa-solid fa-robot"></i>
        </button>
        <section id="aiwPanel" class="aiw-panel" aria-label="<?php echo app_h($footerAiLabels['title']); ?>">
            <div class="aiw-head">
                <span class="aiw-title"><?php echo app_h($footerAiLabels['title']); ?></span>
                <button id="aiwClose" type="button" class="aiw-btn secondary" style="padding:6px 10px;"><?php echo app_h($footerAiLabels['close']); ?></button>
            </div>
            <div class="aiw-body">
                <div id="aiwLog" class="aiw-log"></div>
                <div id="aiwStatus" class="aiw-status"></div>
                <textarea id="aiwInput" class="aiw-input" placeholder="<?php echo app_h($footerAiLabels['placeholder']); ?>"></textarea>
                <div class="aiw-actions">
                    <button id="aiwSend" type="button" class="aiw-btn primary"><?php echo app_h($footerAiLabels['send']); ?></button>
                    <button id="aiwReset" type="button" class="aiw-btn secondary"><?php echo app_h($footerAiLabels['reset']); ?></button>
                </div>
            </div>
        </section>
    </div>
    <script>
        (function() {
            const root = document.getElementById('aiwRoot');
            if (!root) return;
            const labels = <?php echo json_encode($footerAiLabels, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
            const t = function(key, fallback) { return labels[key] || fallback || ''; };
            const configured = root.dataset.configured === '1';
            const apiUrl = root.dataset.apiUrl || 'ai_chat_api.php';
            const csrf = root.dataset.csrf || '';
            const fab = document.getElementById('aiwFab');
            const closeBtn = document.getElementById('aiwClose');
            const sendBtn = document.getElementById('aiwSend');
            const resetBtn = document.getElementById('aiwReset');
            const input = document.getElementById('aiwInput');
            const log = document.getElementById('aiwLog');
            const status = document.getElementById('aiwStatus');
            let history = [];

            const setStatus = function(message, isError) {
                if (!status) return;
                status.style.display = message ? 'block' : 'none';
                status.style.color = isError ? '#f2a7a7' : '#8ba2bf';
                status.textContent = message || '';
            };
            const render = function() {
                log.innerHTML = '';
                if (!history.length) {
                    const empty = document.createElement('div');
                    empty.className = 'aiw-msg';
                    empty.innerHTML = '<div class="aiw-msg-role">AI</div><div class="aiw-msg-text">' + (configured ? 'جاهز للمحادثة.' : t('not_configured', 'OpenAI is not configured yet.')) + '</div>';
                    log.appendChild(empty);
                    return;
                }
                history.forEach(function(item) {
                    const wrap = document.createElement('div');
                    wrap.className = 'aiw-msg ' + (item.role === 'user' ? 'user' : 'assistant');
                    const role = document.createElement('div');
                    role.className = 'aiw-msg-role';
                    role.textContent = item.role === 'user' ? 'You' : 'AI';
                    const text = document.createElement('div');
                    text.className = 'aiw-msg-text';
                    text.textContent = item.text || '';
                    wrap.appendChild(role);
                    wrap.appendChild(text);
                    log.appendChild(wrap);
                });
                log.scrollTop = log.scrollHeight;
            };
            const request = function(payload) {
                const data = new FormData();
                data.set('_csrf_token', csrf);
                Object.keys(payload).forEach(function(key) { data.set(key, payload[key]); });
                return fetch(apiUrl, {
                    method: 'POST',
                    body: data,
                    credentials: 'same-origin',
                    headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
                }).then(function(res) {
                    return res.json().catch(function() { return null; });
                });
            };
            fab.addEventListener('click', function() {
                root.classList.toggle('open');
                if (root.classList.contains('open')) render();
            });
            closeBtn.addEventListener('click', function() {
                root.classList.remove('open');
            });
            sendBtn.addEventListener('click', function() {
                const message = (input.value || '').trim();
                if (!configured) {
                    setStatus(t('not_configured', 'OpenAI is not configured yet.'), true);
                    return;
                }
                if (!message) return;
                sendBtn.disabled = true;
                history.push({ role: 'user', text: message });
                render();
                input.value = '';
                setStatus(t('loading', 'Connecting to OpenAI...'), false);
                request({ action: 'message', message: message })
                    .then(function(payload) {
                        sendBtn.disabled = false;
                        if (!payload || !payload.ok) {
                            history.push({ role: 'assistant', text: (payload && payload.message) ? payload.message : t('connect_error', 'Could not reach OpenAI right now.') });
                            render();
                            setStatus((payload && payload.error) ? payload.error : t('connect_error', 'Could not reach OpenAI right now.'), true);
                            return;
                        }
                        history = Array.isArray(payload.history) ? payload.history : history.concat([{ role: 'assistant', text: payload.reply || '' }]);
                        render();
                        setStatus('', false);
                    })
                    .catch(function() {
                        sendBtn.disabled = false;
                        history.push({ role: 'assistant', text: t('connect_error', 'Could not reach OpenAI right now.') });
                        render();
                        setStatus(t('connect_error', 'Could not reach OpenAI right now.'), true);
                    });
            });
            resetBtn.addEventListener('click', function() {
                history = [];
                render();
                request({ action: 'reset' }).catch(function() {});
                setStatus('', false);
            });
            render();
        })();
    </script>
<?php endif; ?>

<style>
    .app-upload-inline-actions {
        display: flex;
        gap: 8px;
        align-items: center;
        flex-wrap: wrap;
        margin-top: 8px;
    }
    .app-upload-now-btn {
        border: 1px solid rgba(212, 175, 55, 0.42);
        background: linear-gradient(135deg, #d4af37, #aa8c2c);
        color: #111;
        border-radius: 8px;
        padding: 8px 14px;
        font-family: 'Cairo', 'Poppins', sans-serif;
        font-weight: 700;
        cursor: pointer;
    }
    .app-upload-status {
        display: none;
        margin-top: 10px;
        font-size: 0.9rem;
        color: #aaa;
    }
    .app-upload-progress {
        display: none;
        margin-top: 10px;
    }
    .app-upload-progress-track {
        height: 8px;
        border-radius: 999px;
        background: #1a1a1a;
        border: 1px solid #2f2f2f;
        overflow: hidden;
    }
    .app-upload-progress-bar {
        width: 0%;
        height: 100%;
        background: linear-gradient(90deg, #d4af37, #f4d269);
        transition: width .2s ease;
    }
    .app-upload-progress-text {
        margin-top: 6px;
        font-size: 0.82rem;
        color: #aaa;
    }
</style>

<script>
    (function() {
        const shouldSkipForm = function(form) {
            return form.classList.contains('social-ref-upload-form')
                || form.classList.contains('social-async-form')
                || form.classList.contains('op-async-form')
                || form.hasAttribute('data-upload-skip-enhance');
        };

        const pickTargetSubmit = function(form, preferredName) {
            const buttons = Array.from(form.querySelectorAll('button[type="submit"], input[type="submit"]'));
            if (!buttons.length) return null;
            if (preferredName) {
                const exact = buttons.find(function(btn) { return (btn.name || '') === preferredName; });
                if (exact) return exact;
            }
            const heuristic = buttons.find(function(btn) {
                const hay = ((btn.name || '') + ' ' + (btn.value || '') + ' ' + (btn.textContent || '')).toLowerCase();
                return /upload|رفع|proof|file|design|source|brief|ctp|prep/.test(hay);
            });
            return heuristic || buttons[0];
        };

        const ensureUi = function(form) {
            if (!form.querySelector('.app-upload-status')) {
                const status = document.createElement('div');
                status.className = 'app-upload-status';
                form.appendChild(status);
            }
            if (!form.querySelector('.app-upload-progress')) {
                const wrap = document.createElement('div');
                wrap.className = 'app-upload-progress';
                wrap.innerHTML = '<div class="app-upload-progress-track"><div class="app-upload-progress-bar"></div></div><div class="app-upload-progress-text">جاري الرفع...</div>';
                form.appendChild(wrap);
            }
        };

        const attachInlineButtons = function(form) {
            const fileInputs = Array.from(form.querySelectorAll('input[type="file"]'));
            if (!fileInputs.length) return;
            fileInputs.forEach(function(input) {
                if (input.dataset.uploadEnhanced === '1') return;
                input.dataset.uploadEnhanced = '1';
                const preferredName = input.getAttribute('data-upload-submit') || form.getAttribute('data-upload-submit') || '';
                const targetSubmit = pickTargetSubmit(form, preferredName);
                if (!targetSubmit) return;
                const actions = document.createElement('div');
                actions.className = 'app-upload-inline-actions';
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'app-upload-now-btn';
                button.textContent = 'رفع الملفات';
                button.addEventListener('click', function() {
                    const hasFile = Array.from(form.querySelectorAll('input[type="file"]')).some(function(fileInput) {
                        return fileInput.files && fileInput.files.length > 0;
                    });
                    const status = form.querySelector('.app-upload-status');
                    if (!hasFile) {
                        if (status) {
                            status.style.display = 'block';
                            status.style.color = '#d98c8c';
                            status.textContent = 'اختر ملفًا واحدًا على الأقل قبل الرفع.';
                        }
                        return;
                    }
                    form.__appUploadSubmitter = targetSubmit;
                    form.dispatchEvent(new Event('submit', { cancelable: true, bubbles: true }));
                });
                actions.appendChild(button);
                input.insertAdjacentElement('afterend', actions);
            });
        };

        const bindForm = function(form) {
            if (form.dataset.uploadAjaxBound === '1') return;
            form.dataset.uploadAjaxBound = '1';
            ensureUi(form);
            attachInlineButtons(form);

            form.addEventListener('submit', function(evt) {
                const hasFileInput = form.querySelector('input[type="file"]');
                if (!hasFileInput) return;
                const submitter = evt.submitter || form.__appUploadSubmitter || document.activeElement;
                const formData = new FormData(form);
                if (submitter && submitter.name) {
                    formData.set(submitter.name, submitter.value || '1');
                }
                const progressWrap = form.querySelector('.app-upload-progress');
                const progressBar = form.querySelector('.app-upload-progress-bar');
                const progressText = form.querySelector('.app-upload-progress-text');
                const status = form.querySelector('.app-upload-status');

                evt.preventDefault();
                if (status) {
                    status.style.display = 'block';
                    status.style.color = '#aaa';
                    status.textContent = 'جاري التنفيذ...';
                }
                if (progressWrap) progressWrap.style.display = 'block';
                if (progressBar) progressBar.style.width = '0%';
                if (submitter && typeof submitter.disabled !== 'undefined') {
                    submitter.disabled = true;
                }

                const xhr = new XMLHttpRequest();
                xhr.open((form.method || 'POST').toUpperCase(), form.getAttribute('action') || window.location.href, true);
                xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
                xhr.upload.addEventListener('progress', function(e) {
                    if (!e.lengthComputable) return;
                    const percent = Math.max(0, Math.min(100, Math.round((e.loaded / e.total) * 100)));
                    if (progressBar) progressBar.style.width = percent + '%';
                    if (progressText) progressText.textContent = 'جاري الرفع... ' + percent + '%';
                });
                xhr.onreadystatechange = function() {
                    if (xhr.readyState !== 4) return;
                    if (submitter && typeof submitter.disabled !== 'undefined') {
                        submitter.disabled = false;
                    }
                    form.__appUploadSubmitter = null;

                    let payload = null;
                    try { payload = JSON.parse(xhr.responseText || '{}'); } catch (err) { payload = null; }

                    if (xhr.status >= 200 && xhr.status < 300) {
                        if (payload && payload.ok === false) {
                            if (status) {
                                status.style.color = '#d98c8c';
                                status.textContent = payload.error || 'تعذر تنفيذ العملية.';
                            }
                            if (progressWrap) progressWrap.style.display = 'none';
                            return;
                        }
                        if (status) {
                            status.style.color = '#9fd6a8';
                            status.textContent = (payload && (payload.message || payload.msg)) ? (payload.message || payload.msg) : 'تم التنفيذ بنجاح. جارٍ تحديث الصفحة...';
                        }
                        if (progressBar) progressBar.style.width = '100%';
                        if (progressText) progressText.textContent = 'تم الرفع بنجاح';
                        window.setTimeout(function() {
                            window.location.href = (payload && payload.redirect) ? payload.redirect : window.location.href;
                        }, 350);
                        return;
                    }

                    if (status) {
                        status.style.color = '#d98c8c';
                        status.textContent = (payload && payload.error) ? payload.error : 'تعذر رفع الملفات. أعد المحاولة.';
                    }
                    if (progressWrap) progressWrap.style.display = 'none';
                };
                xhr.send(formData);
            });
        };

        const forms = Array.from(document.querySelectorAll('form[enctype="multipart/form-data"]')).filter(function(form) {
            return !shouldSkipForm(form);
        });
        forms.forEach(bindForm);

        document.addEventListener('click', function(evt) {
            const trigger = evt.target.closest('.app-upload-trigger');
            if (!trigger) return;
            const form = trigger.closest('form');
            if (!form) return;
            evt.preventDefault();
            const submitName = trigger.getAttribute('data-upload-submit') || '';
            const submitter = submitName ? form.querySelector('[name="' + submitName + '"]') : null;
            form.__appUploadSubmitter = submitter || trigger;
            form.dispatchEvent(new Event('submit', { cancelable: true, bubbles: true }));
        });

        Array.from(document.querySelectorAll('form')).forEach(function(form) {
            if (form.dataset.appAsyncBound === '1') return;
            if (!form.querySelector('input[name="__async_form"][value="1"]')) return;
            if (form.querySelector('input[type="file"]')) return;

            form.dataset.appAsyncBound = '1';
            form.addEventListener('submit', function(evt) {
                evt.preventDefault();
                const submitter = evt.submitter || document.activeElement || null;
                const data = new FormData(form);
                if (submitter && submitter.name) {
                    data.set(submitter.name, submitter.value || '1');
                }
                if (submitter && typeof submitter.disabled !== 'undefined') {
                    submitter.disabled = true;
                }

                const xhr = new XMLHttpRequest();
                xhr.open((form.method || 'POST').toUpperCase(), form.getAttribute('action') || window.location.href, true);
                xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
                xhr.onreadystatechange = function() {
                    if (xhr.readyState !== 4) return;
                    if (submitter && typeof submitter.disabled !== 'undefined') {
                        submitter.disabled = false;
                    }
                    let payload = null;
                    try { payload = JSON.parse(xhr.responseText || '{}'); } catch (err) { payload = null; }
                    if (xhr.status >= 200 && xhr.status < 300 && payload && payload.ok !== false) {
                        window.setTimeout(function() {
                            window.location.href = (payload && payload.redirect) ? payload.redirect : window.location.href;
                        }, 120);
                        return;
                    }
                    const message = (payload && (payload.error || payload.message || payload.msg)) ? (payload.error || payload.message || payload.msg) : 'تعذر تنفيذ العملية. أعد المحاولة.';
                    window.alert(message);
                };
                xhr.send(data);
            });
        });
    })();
</script>

<style>
    .app-ai-panel { margin: 0 0 16px; padding: 14px; border: 1px solid #2f2f2f; border-radius: 10px; background: #111; }
    .app-ai-head { display:flex; align-items:center; justify-content:space-between; gap:10px; margin-bottom:10px; }
    .app-ai-title { color:#d4af37; font-weight:800; }
    .app-ai-note { color:#8f8f8f; font-size:0.82rem; }
    .app-ai-seed { width:100%; min-height:90px; background:#0c0c0c; border:1px solid #363636; border-radius:8px; color:#fff; padding:10px; resize:vertical; box-sizing:border-box; }
    .app-ai-actions { display:flex; gap:10px; flex-wrap:wrap; margin-top:10px; }
    .app-ai-btn { border:none; border-radius:8px; padding:10px 14px; cursor:pointer; font-weight:700; }
    .app-ai-btn-primary { background:linear-gradient(90deg,#d4af37,#f2d16b); color:#111; }
    .app-ai-btn-secondary { background:#2a2a2a; color:#e4e4e4; }
    .app-ai-status { display:none; margin-top:10px; font-size:0.88rem; }
    .app-ai-results { display:none; margin-top:12px; }
    .app-ai-card { background:#0c0c0c; border:1px solid #2d2d2d; border-radius:8px; padding:10px; margin-bottom:10px; }
    .app-ai-card-title { color:#c9c9c9; font-size:0.82rem; margin-bottom:6px; }
    .app-ai-card pre { margin:0; white-space:pre-wrap; color:#f0f0f0; font-family:inherit; }
    .app-ai-card-actions { display:flex; gap:8px; flex-wrap:wrap; margin-top:8px; }
</style>
<script>
    (function() {
        const panels = Array.from(document.querySelectorAll('.app-ai-panel'));
        if (!panels.length) return;

        const setValue = function(field, value) {
            if (!field) return;
            field.value = value;
            field.dispatchEvent(new Event('input', { bubbles: true }));
            field.dispatchEvent(new Event('change', { bubbles: true }));
        };

        const fillTargetsSequential = function(selector, items) {
            const fields = Array.from(document.querySelectorAll(selector));
            if (!fields.length) return false;
            fields.forEach(function(field, idx) {
                setValue(field, items[idx] || field.value || '');
            });
            return true;
        };

        const fillTargetSingle = function(selector, text) {
            const field = document.querySelector(selector);
            if (!field) return false;
            setValue(field, text);
            return true;
        };

        const renderResults = function(panel, payload) {
            const box = panel.querySelector('.app-ai-results');
            if (!box) return;
            const selector = panel.getAttribute('data-target-selector') || '';
            const applyMode = panel.getAttribute('data-apply-mode') || '';
            const suggestions = Array.isArray(payload.suggestions) ? payload.suggestions : [];
            box.innerHTML = '';
            suggestions.forEach(function(text, idx) {
                const card = document.createElement('div');
                card.className = 'app-ai-card';
                const title = document.createElement('div');
                title.className = 'app-ai-card-title';
                title.textContent = 'اقتراح ' + (idx + 1);
                const pre = document.createElement('pre');
                pre.textContent = text;
                const actions = document.createElement('div');
                actions.className = 'app-ai-card-actions';
                const copyBtn = document.createElement('button');
                copyBtn.type = 'button';
                copyBtn.className = 'app-ai-btn app-ai-btn-secondary';
                copyBtn.textContent = 'نسخ';
                copyBtn.addEventListener('click', function() {
                    navigator.clipboard.writeText(text).catch(function() {});
                });
                actions.appendChild(copyBtn);
                if (selector) {
                    const insertBtn = document.createElement('button');
                    insertBtn.type = 'button';
                    insertBtn.className = 'app-ai-btn app-ai-btn-secondary';
                    insertBtn.textContent = 'إدراج';
                    insertBtn.addEventListener('click', function() {
                        if (applyMode === 'fill-sequential') {
                            const fields = Array.from(document.querySelectorAll(selector));
                            if (fields[idx]) setValue(fields[idx], text);
                        } else {
                            fillTargetSingle(selector, text);
                        }
                    });
                    actions.appendChild(insertBtn);
                }
                card.appendChild(title);
                card.appendChild(pre);
                card.appendChild(actions);
                box.appendChild(card);
            });
            if (selector && suggestions.length) {
                const allActions = document.createElement('div');
                allActions.className = 'app-ai-actions';
                const applyAll = document.createElement('button');
                applyAll.type = 'button';
                applyAll.className = 'app-ai-btn app-ai-btn-primary';
                applyAll.textContent = 'تطبيق الكل';
                applyAll.addEventListener('click', function() {
                    if (applyMode === 'fill-sequential') {
                        fillTargetsSequential(selector, suggestions);
                    } else {
                        fillTargetSingle(selector, payload.combined_text || suggestions.join("\n\n"));
                    }
                });
                allActions.appendChild(applyAll);
                box.appendChild(allActions);
            }
            box.style.display = 'block';
        };

        panels.forEach(function(panel) {
            const generateBtn = panel.querySelector('.app-ai-generate');
            const status = panel.querySelector('.app-ai-status');
            const seed = panel.querySelector('.app-ai-seed');
            if (!generateBtn) return;
            generateBtn.addEventListener('click', function() {
                const data = new FormData();
                data.set('_csrf_token', panel.getAttribute('data-csrf') || '');
                data.set('job_id', panel.getAttribute('data-job-id') || '');
                data.set('context', panel.getAttribute('data-context') || '');
                data.set('item_count', panel.getAttribute('data-item-count') || '1');
                data.set('stage_label', panel.getAttribute('data-stage-label') || '');
                data.set('seed_text', seed ? seed.value : '');
                if (status) {
                    status.style.display = 'block';
                    status.style.color = '#aaa';
                    status.textContent = 'جاري تجهيز الاقتراحات...';
                }
                fetch('ai_assistant.php', {
                    method: 'POST',
                    body: data,
                    credentials: 'same-origin',
                    headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
                })
                .then(function(res) { return res.json().catch(function() { return null; }); })
                .then(function(payload) {
                    if (!payload || !payload.ok) {
                        if (status) {
                            status.style.color = '#d98c8c';
                            status.textContent = (payload && payload.error) ? payload.error : 'تعذر تجهيز الاقتراحات.';
                        }
                        return;
                    }
                    if (status) {
                        status.style.color = '#9fd6a8';
                        status.textContent = payload.message || 'تم تجهيز الاقتراحات.';
                    }
                    renderResults(panel, payload);
                })
                .catch(function() {
                    if (status) {
                        status.style.color = '#d98c8c';
                        status.textContent = 'تعذر الاتصال بمساعد AI.';
                    }
                });
            });
        });
    })();
</script>

</body>
</html>
