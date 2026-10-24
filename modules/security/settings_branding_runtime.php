<?php

if (!function_exists('app_setting_get')) {
    function app_setting_get(mysqli $conn, string $key, string $default = ''): string
    {
        $cache = &$GLOBALS['__app_settings_cache'];
        if (!is_array($cache)) {
            $cache = [];
        }
        $cacheKey = spl_object_hash($conn) . '|' . $key;
        if (array_key_exists($cacheKey, $cache)) {
            return $cache[$cacheKey];
        }

        $safeKey = trim($key);
        if ($safeKey === '') {
            return $default;
        }

        @$conn->query("
            CREATE TABLE IF NOT EXISTS app_settings (
                setting_key VARCHAR(120) PRIMARY KEY,
                setting_value LONGTEXT NULL,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $stmt = $conn->prepare("SELECT setting_value FROM app_settings WHERE setting_key = ? LIMIT 1");
        if (!$stmt) {
            return $default;
        }
        $stmt->bind_param('s', $safeKey);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();

        $value = ($row && array_key_exists('setting_value', $row))
            ? (string)$row['setting_value']
            : $default;
        $cache[$cacheKey] = $value;
        return $value;
    }
}

if (!function_exists('app_setting_set')) {
    function app_setting_set(mysqli $conn, string $key, string $value): bool
    {
        $safeKey = trim($key);
        if ($safeKey === '') {
            return false;
        }

        @$conn->query("
            CREATE TABLE IF NOT EXISTS app_settings (
                setting_key VARCHAR(120) PRIMARY KEY,
                setting_value LONGTEXT NULL,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $stmt = $conn->prepare("
            INSERT INTO app_settings (setting_key, setting_value)
            VALUES (?, ?)
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
        ");
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('ss', $safeKey, $value);
        $ok = $stmt->execute();
        $stmt->close();
        if ($ok) {
            $cache = &$GLOBALS['__app_settings_cache'];
            if (!is_array($cache)) {
                $cache = [];
            }
            $cache[spl_object_hash($conn) . '|' . $safeKey] = $value;
        }
        return $ok;
    }
}

if (!function_exists('app_brand_logo_path')) {
    function app_brand_logo_path(mysqli $conn, string $fallback = 'assets/img/Logo.png'): string
    {
        $raw = trim(app_setting_get($conn, 'app_logo_path', $fallback));
        if ($raw === '') {
            return $fallback;
        }
        $path = str_replace('\\', '/', $raw);
        if (!preg_match('#^(assets|uploads)/[a-zA-Z0-9_./-]+$#', $path)) {
            return $fallback;
        }
        return $path;
    }
}

if (!function_exists('app_brand_profile_field_defs')) {
    function app_brand_profile_field_defs(): array
    {
        return [
            'org_name' => ['ar' => 'اسم المؤسسة', 'en' => 'Institution Name'],
            'org_legal_name' => ['ar' => 'الاسم القانوني', 'en' => 'Legal Name'],
            'org_tax_number' => ['ar' => 'الرقم الضريبي', 'en' => 'Tax Number'],
            'org_commercial_number' => ['ar' => 'السجل التجاري', 'en' => 'Commercial Register'],
            'org_phone_primary' => ['ar' => 'هاتف 1', 'en' => 'Phone 1'],
            'org_phone_secondary' => ['ar' => 'هاتف 2', 'en' => 'Phone 2'],
            'org_email' => ['ar' => 'البريد الإلكتروني', 'en' => 'Email'],
            'org_website' => ['ar' => 'الموقع الإلكتروني', 'en' => 'Website'],
            'org_address' => ['ar' => 'العنوان', 'en' => 'Address'],
            'org_social_whatsapp' => ['ar' => 'واتساب', 'en' => 'WhatsApp'],
            'org_social_facebook' => ['ar' => 'فيسبوك', 'en' => 'Facebook'],
            'org_social_instagram' => ['ar' => 'إنستجرام', 'en' => 'Instagram'],
            'org_social_linkedin' => ['ar' => 'لينكدإن', 'en' => 'LinkedIn'],
            'org_social_x' => ['ar' => 'X / تويتر', 'en' => 'X / Twitter'],
            'org_social_youtube' => ['ar' => 'يوتيوب', 'en' => 'YouTube'],
        ];
    }
}

if (!function_exists('app_brand_parse_visible_fields')) {
    function app_brand_parse_visible_fields(string $raw, array $allowed, array $fallback = []): array
    {
        $parts = preg_split('/[\s,;\n\r]+/', trim($raw)) ?: [];
        $seen = [];
        $items = [];
        foreach ($parts as $part) {
            $key = trim((string)$part);
            if ($key === '' || !isset($allowed[$key]) || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $items[] = $key;
        }
        if (!empty($items)) {
            return $items;
        }
        $result = [];
        foreach ($fallback as $key) {
            $k = trim((string)$key);
            if ($k !== '' && isset($allowed[$k]) && !isset($seen[$k])) {
                $seen[$k] = true;
                $result[] = $k;
            }
        }
        return $result;
    }
}

if (!function_exists('app_brand_public_url')) {
    function app_brand_public_url(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
        if (!in_array($scheme, ['http', 'https'], true) || !filter_var($url, FILTER_VALIDATE_URL)) {
            return '';
        }
        return $url;
    }
}

if (!function_exists('app_brand_profile')) {
    function app_brand_profile(mysqli $conn): array
    {
        $appName = trim(app_setting_get($conn, 'app_name', 'Arab Eagles'));
        $name = trim(app_setting_get($conn, 'org_name', $appName));
        if ($name === '') {
            $name = $appName !== '' ? $appName : 'Arab Eagles';
        }

        $defs = app_brand_profile_field_defs();
        $allowed = array_fill_keys(array_keys($defs), true);
        $headerDefaults = ['org_legal_name', 'org_tax_number', 'org_commercial_number'];
        $footerDefaults = ['org_phone_primary', 'org_phone_secondary', 'org_email', 'org_website', 'org_address'];
        $baseWebsite = app_brand_public_url((string)app_env('SYSTEM_URL', app_base_url()));

        $profile = [
            'org_name' => mb_substr($name, 0, 190),
            'org_legal_name' => mb_substr(trim(app_setting_get($conn, 'org_legal_name', '')), 0, 255),
            'org_tax_number' => mb_substr(trim(app_setting_get($conn, 'org_tax_number', '')), 0, 120),
            'org_commercial_number' => mb_substr(trim(app_setting_get($conn, 'org_commercial_number', '')), 0, 120),
            'org_phone_primary' => mb_substr(trim(app_setting_get($conn, 'org_phone_primary', '')), 0, 80),
            'org_phone_secondary' => mb_substr(trim(app_setting_get($conn, 'org_phone_secondary', '')), 0, 80),
            'org_email' => mb_substr(trim(app_setting_get($conn, 'org_email', '')), 0, 190),
            'org_website' => app_brand_public_url((string)app_setting_get($conn, 'org_website', $baseWebsite)),
            'org_address' => mb_substr(trim(app_setting_get($conn, 'org_address', '')), 0, 255),
            'org_social_whatsapp' => app_brand_public_url((string)app_setting_get($conn, 'org_social_whatsapp', '')),
            'org_social_facebook' => app_brand_public_url((string)app_setting_get($conn, 'org_social_facebook', '')),
            'org_social_instagram' => app_brand_public_url((string)app_setting_get($conn, 'org_social_instagram', '')),
            'org_social_linkedin' => app_brand_public_url((string)app_setting_get($conn, 'org_social_linkedin', '')),
            'org_social_x' => app_brand_public_url((string)app_setting_get($conn, 'org_social_x', '')),
            'org_social_youtube' => app_brand_public_url((string)app_setting_get($conn, 'org_social_youtube', '')),
            'org_footer_note' => mb_substr(trim(app_setting_get($conn, 'org_footer_note', '')), 0, 300),
            'show_header' => app_setting_get($conn, 'output_show_header', '1') === '1',
            'show_footer' => app_setting_get($conn, 'output_show_footer', '1') === '1',
            'show_logo' => app_setting_get($conn, 'output_show_logo', '1') === '1',
            'show_qr' => app_setting_get($conn, 'output_show_qr', '1') === '1',
        ];

        $profile['header_items'] = app_brand_parse_visible_fields(
            (string)app_setting_get($conn, 'output_header_items', implode(',', $headerDefaults)),
            $allowed,
            $headerDefaults
        );
        $profile['footer_items'] = app_brand_parse_visible_fields(
            (string)app_setting_get($conn, 'output_footer_items', implode(',', $footerDefaults)),
            $allowed,
            $footerDefaults
        );

        return $profile;
    }
}

if (!function_exists('app_brand_output_lines')) {
    function app_brand_output_lines(array $profile, string $scope = 'footer', bool $ar = true): array
    {
        $defs = app_brand_profile_field_defs();
        $keys = $scope === 'header'
            ? (array)($profile['header_items'] ?? [])
            : (array)($profile['footer_items'] ?? []);
        $lines = [];
        foreach ($keys as $key) {
            $value = trim((string)($profile[$key] ?? ''));
            if ($value === '' || !isset($defs[$key])) {
                continue;
            }
            $label = (string)($ar ? $defs[$key]['ar'] : $defs[$key]['en']);
            $lines[] = $label . ': ' . $value;
        }
        return $lines;
    }
}

if (!function_exists('app_brand_qr_payload')) {
    function app_brand_qr_payload(array $profile, array $extra = []): string
    {
        $rows = [];
        $name = trim((string)($profile['org_name'] ?? ''));
        if ($name !== '') {
            $rows[] = 'Institution: ' . $name;
        }
        $tax = trim((string)($profile['org_tax_number'] ?? ''));
        if ($tax !== '') {
            $rows[] = 'Tax No: ' . $tax;
        }
        $commercial = trim((string)($profile['org_commercial_number'] ?? ''));
        if ($commercial !== '') {
            $rows[] = 'Commercial Reg: ' . $commercial;
        }
        $phone = trim((string)($profile['org_phone_primary'] ?? ''));
        if ($phone !== '') {
            $rows[] = 'Phone: ' . $phone;
        }
        $email = trim((string)($profile['org_email'] ?? ''));
        if ($email !== '') {
            $rows[] = 'Email: ' . $email;
        }
        $website = trim((string)($profile['org_website'] ?? ''));
        if ($website !== '') {
            $rows[] = 'Website: ' . $website;
        }
        foreach ($extra as $label => $value) {
            $val = trim((string)$value);
            if ($val === '') {
                continue;
            }
            $rows[] = trim((string)$label) . ': ' . $val;
        }
        $payload = trim(implode("\n", $rows));
        return mb_substr($payload, 0, 1100);
    }
}

if (!function_exists('app_brand_qr_url')) {
    function app_brand_qr_url(string $payload, int $size = 130): string
    {
        $payload = trim($payload);
        if ($payload === '') {
            return '';
        }
        $size = max(90, min(360, $size));
        return 'https://api.qrserver.com/v1/create-qr-code/?size=' . $size . 'x' . $size . '&margin=0&data=' . rawurlencode($payload);
    }
}

if (!function_exists('app_brand_output_theme_presets')) {
    function app_brand_output_theme_presets(): array
    {
        return [
            'midnight_gold' => ['label' => 'Midnight Gold', 'label_ar' => 'ذهبي ليلي', 'accent' => '#d4af37', 'accent_soft' => '#f2d47a', 'bg' => '#050505', 'bg_alt' => '#0a0b0c', 'card' => '#121212', 'card_strong' => '#171717', 'paper' => '#ffffff', 'ink' => '#171717', 'text' => '#f2f2f2', 'muted' => '#9c9c9c', 'border' => 'rgba(255,255,255,0.08)', 'line' => '#ded7c4', 'tint' => '#f6efe0'],
            'graphite_emerald' => ['label' => 'Graphite Emerald', 'label_ar' => 'زمرد جرافيتي', 'accent' => '#16a085', 'accent_soft' => '#6de0cb', 'bg' => '#0b0f10', 'bg_alt' => '#111719', 'card' => '#11181a', 'card_strong' => '#182124', 'paper' => '#ffffff', 'ink' => '#192123', 'text' => '#edf8f5', 'muted' => '#8ea8a3', 'border' => 'rgba(109,224,203,0.14)', 'line' => '#d8e4e2', 'tint' => '#eef8f6'],
            'imperial_burgundy' => ['label' => 'Imperial Burgundy', 'label_ar' => 'برغندي إمبراطوري', 'accent' => '#8e2036', 'accent_soft' => '#d48a9b', 'bg' => '#14090d', 'bg_alt' => '#1a0d12', 'card' => '#1d1115', 'card_strong' => '#25161c', 'paper' => '#fffefe', 'ink' => '#24191c', 'text' => '#f8edef', 'muted' => '#b799a0', 'border' => 'rgba(212,138,155,0.16)', 'line' => '#ead8dc', 'tint' => '#fbf0f2'],
            'arctic_blue' => ['label' => 'Arctic Blue', 'label_ar' => 'أزرق قطبي', 'accent' => '#1f6feb', 'accent_soft' => '#8ab4ff', 'bg' => '#08111f', 'bg_alt' => '#0c1730', 'card' => '#0e1828', 'card_strong' => '#132038', 'paper' => '#ffffff', 'ink' => '#182433', 'text' => '#eef4ff', 'muted' => '#9bb1cf', 'border' => 'rgba(138,180,255,0.16)', 'line' => '#d8e2ef', 'tint' => '#eef4fb'],
            'terracotta_sand' => ['label' => 'Terracotta Sand', 'label_ar' => 'تيراكوتا رملي', 'accent' => '#c56b3d', 'accent_soft' => '#edb189', 'bg' => '#120d09', 'bg_alt' => '#18110d', 'card' => '#1b140f', 'card_strong' => '#241913', 'paper' => '#fffdf9', 'ink' => '#2b211a', 'text' => '#fbf1eb', 'muted' => '#be9d8d', 'border' => 'rgba(237,177,137,0.16)', 'line' => '#eaded2', 'tint' => '#fbf3ea'],
            'royal_indigo' => ['label' => 'Royal Indigo', 'label_ar' => 'نيلي ملكي', 'accent' => '#5b4fd1', 'accent_soft' => '#b0a8ff', 'bg' => '#0d0b19', 'bg_alt' => '#151228', 'card' => '#151226', 'card_strong' => '#1b1732', 'paper' => '#ffffff', 'ink' => '#211d3b', 'text' => '#f1efff', 'muted' => '#aaa5d3', 'border' => 'rgba(176,168,255,0.16)', 'line' => '#dfddf0', 'tint' => '#f2f1fb'],
            'olive_sand' => ['label' => 'Olive Sand', 'label_ar' => 'زيتوني رملي', 'accent' => '#7a8b32', 'accent_soft' => '#c8d98f', 'bg' => '#101108', 'bg_alt' => '#15170d', 'card' => '#181a10', 'card_strong' => '#202416', 'paper' => '#fffef9', 'ink' => '#25281b', 'text' => '#f5f7eb', 'muted' => '#b6c08a', 'border' => 'rgba(200,217,143,0.15)', 'line' => '#e5e6d7', 'tint' => '#f7f8ee'],
            'carbon_copper' => ['label' => 'Carbon Copper', 'label_ar' => 'نحاسي كربوني', 'accent' => '#b66a3d', 'accent_soft' => '#efb18a', 'bg' => '#0c0b0a', 'bg_alt' => '#12100f', 'card' => '#151312', 'card_strong' => '#1d1816', 'paper' => '#ffffff', 'ink' => '#241d19', 'text' => '#faf0ea', 'muted' => '#b89886', 'border' => 'rgba(239,177,138,0.16)', 'line' => '#e7d9d0', 'tint' => '#faf1ec'],
            'emerald_ice' => ['label' => 'Emerald Ice', 'label_ar' => 'زمرد ثلجي', 'accent' => '#1ca88a', 'accent_soft' => '#8ee7d3', 'bg' => '#081311', 'bg_alt' => '#0d1b18', 'card' => '#0f1d1a', 'card_strong' => '#142723', 'paper' => '#fbfffe', 'ink' => '#18302b', 'text' => '#effbf8', 'muted' => '#99ccc1', 'border' => 'rgba(142,231,211,0.16)', 'line' => '#d7ebe6', 'tint' => '#eef9f6'],
            'rose_charcoal' => ['label' => 'Rose Charcoal', 'label_ar' => 'فحمي وردي', 'accent' => '#cc5c7a', 'accent_soft' => '#f1a2b7', 'bg' => '#120b10', 'bg_alt' => '#190f15', 'card' => '#1a1217', 'card_strong' => '#22171d', 'paper' => '#ffffff', 'ink' => '#2a1d23', 'text' => '#fff1f5', 'muted' => '#cfa4b1', 'border' => 'rgba(241,162,183,0.16)', 'line' => '#edd8e0', 'tint' => '#fdf1f5'],
            'cobalt_silver' => ['label' => 'Cobalt Silver', 'label_ar' => 'كوبالت فضي', 'accent' => '#2858a8', 'accent_soft' => '#94b0e1', 'bg' => '#09111c', 'bg_alt' => '#0e1623', 'card' => '#111b28', 'card_strong' => '#172335', 'paper' => '#ffffff', 'ink' => '#1d2838', 'text' => '#eef4fb', 'muted' => '#a7b7cc', 'border' => 'rgba(148,176,225,0.16)', 'line' => '#dde4eb', 'tint' => '#f1f5f9'],
            'dune_amber' => ['label' => 'Dune Amber', 'label_ar' => 'كهرماني صحراوي', 'accent' => '#b88719', 'accent_soft' => '#e7c26f', 'bg' => '#120f07', 'bg_alt' => '#17130a', 'card' => '#1b170d', 'card_strong' => '#241d11', 'paper' => '#fffdf8', 'ink' => '#2b2418', 'text' => '#fbf3e2', 'muted' => '#ceb78c', 'border' => 'rgba(231,194,111,0.16)', 'line' => '#e9dfcc', 'tint' => '#fbf5e9'],
            'forest_cream' => ['label' => 'Forest Cream', 'label_ar' => 'غابة كريمية', 'accent' => '#2f6e4f', 'accent_soft' => '#9ecab2', 'bg' => '#08100c', 'bg_alt' => '#0c1611', 'card' => '#101913', 'card_strong' => '#17231c', 'paper' => '#fffef9', 'ink' => '#1e2c24', 'text' => '#f4faf5', 'muted' => '#a3bcaf', 'border' => 'rgba(158,202,178,0.16)', 'line' => '#dde7df', 'tint' => '#f2f7f3'],
            'plum_brass' => ['label' => 'Plum Brass', 'label_ar' => 'برقوقي نحاسي', 'accent' => '#7a3f68', 'accent_soft' => '#c79cb9', 'bg' => '#120a11', 'bg_alt' => '#180f17', 'card' => '#191018', 'card_strong' => '#21151f', 'paper' => '#fffefe', 'ink' => '#291e28', 'text' => '#fbf1fa', 'muted' => '#c3a9bc', 'border' => 'rgba(199,156,185,0.16)', 'line' => '#eadce8', 'tint' => '#faf2f9'],
            'mono_ink' => ['label' => 'Mono Ink', 'label_ar' => 'حبر أحادي', 'accent' => '#6d6d6d', 'accent_soft' => '#c0c0c0', 'bg' => '#090909', 'bg_alt' => '#0f0f0f', 'card' => '#141414', 'card_strong' => '#1a1a1a', 'paper' => '#ffffff', 'ink' => '#1b1b1b', 'text' => '#f2f2f2', 'muted' => '#9b9b9b', 'border' => 'rgba(255,255,255,0.1)', 'line' => '#dddddd', 'tint' => '#f3f3f3'],
            'sunset_orange' => ['label' => 'Sunset Orange', 'label_ar' => 'برتقالي الغروب', 'accent' => '#ff7a18', 'accent_soft' => '#ffb56e', 'bg' => '#140a05', 'bg_alt' => '#1c1109', 'card' => '#1d130c', 'card_strong' => '#251911', 'paper' => '#fffdfa', 'ink' => '#2f2116', 'text' => '#fff3ea', 'muted' => '#d8a988', 'border' => 'rgba(255,181,110,0.18)', 'line' => '#efdece', 'tint' => '#fef4ec'],
            'ocean_mint' => ['label' => 'Ocean Mint', 'label_ar' => 'نعناع محيطي', 'accent' => '#00a8a8', 'accent_soft' => '#78e0df', 'bg' => '#061212', 'bg_alt' => '#0c191a', 'card' => '#0d1a1b', 'card_strong' => '#122225', 'paper' => '#fbffff', 'ink' => '#163133', 'text' => '#edfbfb', 'muted' => '#9ecfce', 'border' => 'rgba(120,224,223,0.18)', 'line' => '#d7ecec', 'tint' => '#eefafa'],
            'ruby_night' => ['label' => 'Ruby Night', 'label_ar' => 'ياقوت ليلي', 'accent' => '#e23d5a', 'accent_soft' => '#ff9eb1', 'bg' => '#12070b', 'bg_alt' => '#190d11', 'card' => '#1a1014', 'card_strong' => '#241419', 'paper' => '#fffefe', 'ink' => '#301b21', 'text' => '#fff1f4', 'muted' => '#d0a0aa', 'border' => 'rgba(255,158,177,0.18)', 'line' => '#efd9df', 'tint' => '#fdf1f3'],
            'lavender_frost' => ['label' => 'Lavender Frost', 'label_ar' => 'لافندر ثلجي', 'accent' => '#8d6bff', 'accent_soft' => '#ccbfff', 'bg' => '#0d0a16', 'bg_alt' => '#141022', 'card' => '#151128', 'card_strong' => '#1d1736', 'paper' => '#ffffff', 'ink' => '#251f3f', 'text' => '#f5f1ff', 'muted' => '#b6abdd', 'border' => 'rgba(204,191,255,0.18)', 'line' => '#e5dff5', 'tint' => '#f5f1fd'],
            'jade_clay' => ['label' => 'Jade Clay', 'label_ar' => 'يشب طيني', 'accent' => '#3d8b6d', 'accent_soft' => '#acd7c5', 'bg' => '#09100e', 'bg_alt' => '#0e1714', 'card' => '#111916', 'card_strong' => '#18211d', 'paper' => '#fffefc', 'ink' => '#212d28', 'text' => '#f3faf7', 'muted' => '#abc6ba', 'border' => 'rgba(172,215,197,0.18)', 'line' => '#dde8e1', 'tint' => '#f2f8f5'],
        ];
    }
}

if (!function_exists('app_ui_theme_presets')) {
    function app_ui_theme_presets(): array
    {
        return app_brand_output_theme_presets();
    }
}

if (!function_exists('app_ui_theme_system_key')) {
    function app_ui_theme_system_key(mysqli $conn): string
    {
        $presets = app_ui_theme_presets();
        $key = trim(app_setting_get($conn, 'ui_theme_preset', 'midnight_gold'));
        return isset($presets[$key]) ? $key : 'midnight_gold';
    }
}

if (!function_exists('app_ui_theme_user_setting_key')) {
    function app_ui_theme_user_setting_key(int $userId): string
    {
        return 'user_theme_preset_' . max(0, $userId);
    }
}

if (!function_exists('app_ui_theme_user_key')) {
    function app_ui_theme_user_key(mysqli $conn, int $userId): string
    {
        if ($userId <= 0) {
            return '';
        }
        $presets = app_ui_theme_presets();
        $key = trim(app_setting_get($conn, app_ui_theme_user_setting_key($userId), ''));
        return isset($presets[$key]) ? $key : '';
    }
}

if (!function_exists('app_ui_theme_effective_key')) {
    function app_ui_theme_effective_key(mysqli $conn, int $userId = 0): string
    {
        $userKey = app_ui_theme_user_key($conn, $userId);
        return $userKey !== '' ? $userKey : app_ui_theme_system_key($conn);
    }
}

if (!function_exists('app_ui_theme')) {
    function app_ui_theme(mysqli $conn, int $userId = 0): array
    {
        $presets = app_ui_theme_presets();
        $key = app_ui_theme_effective_key($conn, $userId);
        $theme = $presets[$key] ?? $presets['midnight_gold'];
        $theme['key'] = $key;
        return $theme;
    }
}

if (!function_exists('app_ui_theme_css_vars')) {
    function app_ui_theme_css_vars(array $theme): array
    {
        return [
            '--bg' => (string)$theme['bg'],
            '--bg-alt' => (string)($theme['bg_alt'] ?? $theme['bg']),
            '--card-bg' => (string)$theme['card'],
            '--card-strong' => (string)($theme['card_strong'] ?? $theme['card']),
            '--ae-gold' => (string)$theme['accent'],
            '--ae-accent-soft' => (string)$theme['accent_soft'],
            '--border' => (string)$theme['border'],
            '--text' => (string)$theme['text'],
            '--muted' => (string)$theme['muted'],
            '--paper' => (string)$theme['paper'],
            '--ink' => (string)$theme['ink'],
            '--line' => (string)$theme['line'],
            '--tint' => (string)$theme['tint'],
        ];
    }
}

if (!function_exists('app_brand_output_theme_key')) {
    function app_brand_output_theme_key(mysqli $conn): string
    {
        $presets = app_brand_output_theme_presets();
        $key = trim(app_setting_get($conn, 'output_theme_preset', app_ui_theme_system_key($conn)));
        return isset($presets[$key]) ? $key : 'midnight_gold';
    }
}

if (!function_exists('app_brand_output_theme')) {
    function app_brand_output_theme(mysqli $conn): array
    {
        $presets = app_brand_output_theme_presets();
        $key = app_brand_output_theme_key($conn);
        $theme = $presets[$key];
        $theme['key'] = $key;
        return $theme;
    }
}
