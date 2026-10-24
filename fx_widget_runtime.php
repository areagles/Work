<?php

if (!function_exists('app_fx_widget_currency_catalog')) {
    function app_fx_widget_currency_catalog(): array
    {
        return [
            'EGP' => ['ar' => 'جنيه مصري', 'en' => 'Egyptian Pound', 'symbol' => 'EGP'],
            'USD' => ['ar' => 'دولار أمريكي', 'en' => 'US Dollar', 'symbol' => 'USD'],
            'EUR' => ['ar' => 'يورو', 'en' => 'Euro', 'symbol' => 'EUR'],
            'GBP' => ['ar' => 'جنيه إسترليني', 'en' => 'British Pound', 'symbol' => 'GBP'],
            'SAR' => ['ar' => 'ريال سعودي', 'en' => 'Saudi Riyal', 'symbol' => 'SAR'],
            'AED' => ['ar' => 'درهم إماراتي', 'en' => 'UAE Dirham', 'symbol' => 'AED'],
            'KWD' => ['ar' => 'دينار كويتي', 'en' => 'Kuwaiti Dinar', 'symbol' => 'KWD'],
            'QAR' => ['ar' => 'ريال قطري', 'en' => 'Qatari Riyal', 'symbol' => 'QAR'],
            'BHD' => ['ar' => 'دينار بحريني', 'en' => 'Bahraini Dinar', 'symbol' => 'BHD'],
            'OMR' => ['ar' => 'ريال عماني', 'en' => 'Omani Rial', 'symbol' => 'OMR'],
            'JPY' => ['ar' => 'ين ياباني', 'en' => 'Japanese Yen', 'symbol' => 'JPY'],
            'CNY' => ['ar' => 'يوان صيني', 'en' => 'Chinese Yuan', 'symbol' => 'CNY'],
            'TRY' => ['ar' => 'ليرة تركية', 'en' => 'Turkish Lira', 'symbol' => 'TRY'],
            'CHF' => ['ar' => 'فرنك سويسري', 'en' => 'Swiss Franc', 'symbol' => 'CHF'],
            'CAD' => ['ar' => 'دولار كندي', 'en' => 'Canadian Dollar', 'symbol' => 'CAD'],
            'AUD' => ['ar' => 'دولار أسترالي', 'en' => 'Australian Dollar', 'symbol' => 'AUD'],
        ];
    }
}

if (!function_exists('array_is_list')) {
    function array_is_list(array $array): bool
    {
        $expected = 0;
        foreach ($array as $key => $_value) {
            if ($key !== $expected++) {
                return false;
            }
        }
        return true;
    }
}

if (!function_exists('app_fx_widget_defaults')) {
    function app_fx_widget_defaults(): array
    {
        return [
            'enabled' => true,
            'base_currency' => 'EGP',
            'currencies' => ['USD', 'EUR', 'GBP', 'SAR', 'AED'],
            'refresh_seconds' => 60,
            'provider' => 'frankfurter',
        ];
    }
}

if (!function_exists('app_fx_widget_normalize_settings')) {
    function app_fx_widget_normalize_settings(mysqli $conn): array
    {
        $defaults = app_fx_widget_defaults();
        $catalog = app_fx_widget_currency_catalog();
        $base = strtoupper(trim((string)app_setting_get($conn, 'fx_widget_base_currency', $defaults['base_currency'])));
        if (!isset($catalog[$base])) {
            $base = $defaults['base_currency'];
        }
        $enabled = app_setting_get($conn, 'fx_widget_enabled', $defaults['enabled'] ? '1' : '0') === '1';
        $refresh = (int)app_setting_get($conn, 'fx_widget_refresh_seconds', (string)$defaults['refresh_seconds']);
        if ($refresh < 15) {
            $refresh = 15;
        } elseif ($refresh > 3600) {
            $refresh = 3600;
        }
        $rawList = trim((string)app_setting_get($conn, 'fx_widget_currencies', implode(',', $defaults['currencies'])));
        $currencies = [];
        foreach (preg_split('/[\s,]+/', $rawList) as $code) {
            $code = strtoupper(trim((string)$code));
            if ($code === '' || $code === $base || !isset($catalog[$code])) {
                continue;
            }
            if (!in_array($code, $currencies, true)) {
                $currencies[] = $code;
            }
        }
        if (empty($currencies)) {
            foreach ($defaults['currencies'] as $code) {
                if ($code !== $base && isset($catalog[$code])) {
                    $currencies[] = $code;
                }
            }
        }

        return [
            'enabled' => $enabled,
            'base_currency' => $base,
            'currencies' => array_slice($currencies, 0, 10),
            'refresh_seconds' => $refresh,
            'provider' => (string)$defaults['provider'],
            'catalog' => $catalog,
        ];
    }
}

if (!function_exists('app_fx_widget_cache_path')) {
    function app_fx_widget_cache_path(mysqli $conn, array $settings): string
    {
        $baseDir = __DIR__ . '/../../uploads/cache';
        if (!is_dir($baseDir) && !@mkdir($baseDir, 0775, true) && !is_dir($baseDir)) {
            return sys_get_temp_dir() . '/fx_widget_' . md5((string)app_setting_get($conn, 'app_name', 'default')) . '.json';
        }
        return $baseDir . '/fx_widget_' . md5($settings['base_currency'] . '|' . implode(',', $settings['currencies'])) . '.json';
    }
}

if (!function_exists('app_fx_widget_read_cache')) {
    function app_fx_widget_read_cache(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }
        $json = @file_get_contents($path);
        if ($json === false || $json === '') {
            return [];
        }
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }
}

if (!function_exists('app_fx_widget_write_cache')) {
    function app_fx_widget_write_cache(string $path, array $payload): void
    {
        @file_put_contents($path, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}

if (!function_exists('app_fx_widget_http_get_json')) {
    function app_fx_widget_http_get_json(string $url): array
    {
        $body = '';
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_HTTPHEADER => ['Accept: application/json'],
                CURLOPT_USERAGENT => 'ArabEagles-FXWidget/1.0',
            ]);
            $body = (string)curl_exec($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            if (PHP_VERSION_ID < 80500) {
                curl_close($ch);
            }
            if ($status >= 400 || $body === '') {
                return [];
            }
        } else {
            $context = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'timeout' => 10,
                    'header' => "Accept: application/json\r\nUser-Agent: ArabEagles-FXWidget/1.0\r\n",
                ],
            ]);
            $body = (string)@file_get_contents($url, false, $context);
            if ($body === '') {
                return [];
            }
        }
        $decoded = json_decode($body, true);
        return is_array($decoded) ? $decoded : [];
    }
}

if (!function_exists('app_fx_widget_item_index')) {
    function app_fx_widget_item_index(array $items): array
    {
        $index = [];
        foreach ($items as $item) {
            $code = strtoupper(trim((string)($item['code'] ?? '')));
            if ($code !== '') {
                $index[$code] = $item;
            }
        }
        return $index;
    }
}

if (!function_exists('app_fx_widget_rates_from_response')) {
    function app_fx_widget_rates_from_response(array $response): array
    {
        $rateMap = [];
        if (!empty($response['rates']) && is_array($response['rates'])) {
            $rateMap = $response['rates'];
        } elseif (array_is_list($response)) {
            foreach ($response as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $quote = strtoupper(trim((string)($row['quote'] ?? '')));
                $rate = (float)($row['rate'] ?? 0);
                if ($quote !== '' && $rate > 0) {
                    $rateMap[$quote] = $rate;
                }
            }
        }
        return $rateMap;
    }
}

if (!function_exists('app_fx_widget_fetch_market_previous')) {
    function app_fx_widget_fetch_market_previous(array $settings, array $quotes, string $currentDate): array
    {
        $currentTs = strtotime($currentDate);
        if (!$currentTs) {
            $currentTs = time();
        }
        for ($daysBack = 1; $daysBack <= 10; $daysBack++) {
            $date = gmdate('Y-m-d', $currentTs - ($daysBack * 86400));
            $url = 'https://api.frankfurter.dev/v2/rates?date=' . rawurlencode($date)
                . '&base=' . rawurlencode((string)$settings['base_currency'])
                . '&quotes=' . rawurlencode(implode(',', $quotes));
            $response = app_fx_widget_http_get_json($url);
            if (empty($response)) {
                continue;
            }
            $rateMap = app_fx_widget_rates_from_response($response);
            if (!empty($rateMap)) {
                return [
                    'date' => (string)($response['date'] ?? $date),
                    'rates' => $rateMap,
                ];
            }
        }
        return ['date' => '', 'rates' => []];
    }
}

if (!function_exists('app_fx_widget_with_market_context')) {
    function app_fx_widget_with_market_context(array $items, array $previousRates = [], bool $isLive = true): array
    {
        $enriched = [];
        foreach ($items as $item) {
            $code = strtoupper(trim((string)($item['code'] ?? '')));
            $currentRate = (float)($item['display_rate'] ?? 0);
            $previousDirect = (float)($previousRates[$code] ?? 0);
            $previousRate = $previousDirect > 0.0000001 ? (1 / $previousDirect) : 0.0;
            $direction = 'flat';
            $changePct = 0.0;
            if ($previousRate > 0) {
                $delta = $currentRate - $previousRate;
                $changePct = ($delta / $previousRate) * 100;
                if (abs($delta) >= 0.0001) {
                    $direction = $delta > 0 ? 'up' : 'down';
                }
            }
            $item['direction'] = $direction;
            $item['previous_display_rate'] = $previousRate > 0 ? round($previousRate, 6) : null;
            $item['change_pct'] = round($changePct, 4);
            $item['market_status'] = $isLive ? 'live' : 'cached';
            $enriched[] = $item;
        }
        return $enriched;
    }
}

if (!function_exists('app_fx_widget_fetch_remote')) {
    function app_fx_widget_fetch_remote(array $settings): array
    {
        $quotes = array_values(array_filter((array)$settings['currencies'], static function ($code) use ($settings) {
            return $code !== $settings['base_currency'];
        }));
        if (empty($quotes)) {
            return [];
        }

        $url = 'https://api.frankfurter.dev/v2/rates?base=' . rawurlencode((string)$settings['base_currency'])
            . '&quotes=' . rawurlencode(implode(',', $quotes));
        $response = app_fx_widget_http_get_json($url);
        if (empty($response)) {
            return [];
        }

        $rateMap = app_fx_widget_rates_from_response($response);
        if (empty($rateMap)) {
            return [];
        }
        $marketDate = (string)($response['date'] ?? ($response[0]['date'] ?? date('Y-m-d')));
        $previousMarket = app_fx_widget_fetch_market_previous($settings, $quotes, $marketDate);

        $items = [];
        $catalog = (array)$settings['catalog'];
        foreach ($quotes as $quote) {
            $directRate = (float)($rateMap[$quote] ?? 0);
            if ($directRate <= 0.0000001) {
                continue;
            }
            $displayRate = 1 / $directRate;
            $meta = $catalog[$quote] ?? ['ar' => $quote, 'en' => $quote, 'symbol' => $quote];
            $items[] = [
                'code' => $quote,
                'label_ar' => (string)($meta['ar'] ?? $quote),
                'label_en' => (string)($meta['en'] ?? $quote),
                'symbol' => (string)($meta['symbol'] ?? $quote),
                'display_rate' => round($displayRate, 6),
                'inverse_rate' => round($directRate, 6),
            ];
        }
        $items = app_fx_widget_with_market_context($items, (array)($previousMarket['rates'] ?? []), true);

        return [
            'ok' => !empty($items),
            'provider' => 'Frankfurter',
            'provider_url' => 'https://api.frankfurter.dev',
            'base_currency' => (string)$settings['base_currency'],
            'date' => $marketDate,
            'previous_date' => (string)($previousMarket['date'] ?? ''),
            'updated_at' => gmdate('c'),
            'items' => $items,
        ];
    }
}

if (!function_exists('app_fx_widget_payload')) {
    function app_fx_widget_payload(mysqli $conn, bool $forceRefresh = false): array
    {
        $settings = app_fx_widget_normalize_settings($conn);
        $baseMeta = $settings['catalog'][$settings['base_currency']] ?? ['ar' => $settings['base_currency'], 'en' => $settings['base_currency']];
        $payload = [
            'enabled' => !empty($settings['enabled']),
            'base_currency' => (string)$settings['base_currency'],
            'base_label_ar' => (string)($baseMeta['ar'] ?? $settings['base_currency']),
            'base_label_en' => (string)($baseMeta['en'] ?? $settings['base_currency']),
            'refresh_seconds' => (int)$settings['refresh_seconds'],
            'provider' => '',
            'provider_url' => '',
            'date' => '',
            'updated_at' => '',
            'items' => [],
            'stale' => true,
        ];
        if (empty($settings['enabled'])) {
            return $payload;
        }

        $cachePath = app_fx_widget_cache_path($conn, $settings);
        $cached = app_fx_widget_read_cache($cachePath);
        $cacheTs = strtotime((string)($cached['updated_at'] ?? ''));
        $isFresh = $cacheTs && (time() - $cacheTs) < (int)$settings['refresh_seconds'];

        if (!$forceRefresh && $isFresh && !empty($cached['items'])) {
            return array_merge($payload, $cached, ['enabled' => true, 'refresh_seconds' => (int)$settings['refresh_seconds'], 'stale' => false]);
        }

        $remote = app_fx_widget_fetch_remote($settings);
        if (!empty($remote['ok'])) {
            unset($remote['ok']);
            app_fx_widget_write_cache($cachePath, $remote);
            return array_merge($payload, $remote, ['enabled' => true, 'refresh_seconds' => (int)$settings['refresh_seconds'], 'stale' => false]);
        }

        if (!empty($cached['items'])) {
            foreach ($cached['items'] as &$cachedItem) {
                $cachedItem['market_status'] = 'cached';
            }
            unset($cachedItem);
            return array_merge($payload, $cached, ['enabled' => true, 'refresh_seconds' => (int)$settings['refresh_seconds'], 'stale' => true]);
        }

        return $payload;
    }
}
