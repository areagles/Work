<?php

if (!function_exists('md_allowed_tabs')) {
    function md_allowed_tabs(): array
    {
        return ['branding', 'currencies', 'payments', 'ai', 'eta', 'taxes', 'types', 'stages', 'catalog', 'numbering', 'cloud_sync', 'pricing'];
    }
}

if (!function_exists('md_normalize_tab')) {
    function md_normalize_tab(string $tab): string
    {
        $tab = strtolower(trim($tab));
        return in_array($tab, md_allowed_tabs(), true) ? $tab : 'branding';
    }
}

if (!function_exists('md_normalize_type_key')) {
    function md_normalize_type_key(string $typeKey): string
    {
        $typeKey = strtolower(trim($typeKey));
        return preg_match('/^[a-z0-9_]{2,50}$/', $typeKey) ? $typeKey : '';
    }
}

if (!function_exists('md_tab_url')) {
    function md_tab_url(string $tab, array $extra = []): string
    {
        $params = ['tab' => md_normalize_tab($tab)];
        $scopeType = md_normalize_type_key((string)($GLOBALS['md_scope_type'] ?? ($_POST['scope_type'] ?? $_GET['scope_type'] ?? '')));
        if ($scopeType !== '') {
            $params['scope_type'] = $scopeType;
        }
        foreach ($extra as $key => $value) {
            if ($key === 'scope_type') {
                $value = md_normalize_type_key((string)$value);
                if ($value === '') {
                    unset($params['scope_type']);
                    continue;
                }
            }
            $params[$key] = $value;
        }
        return 'master_data.php?' . http_build_query($params);
    }
}

if (!function_exists('md_string_list')) {
    function md_string_list($raw, bool $lowercase = false, int $maxItems = 80, int $maxLen = 140): array
    {
        $items = [];
        if (is_array($raw)) {
            $items = $raw;
        } else {
            $text = trim((string)$raw);
            if ($text !== '' && ($text[0] === '[' || $text[0] === '{')) {
                $decoded = json_decode($text, true);
                if (is_array($decoded)) {
                    $items = $decoded;
                }
            }
            if (empty($items) && $text !== '') {
                $items = preg_split('/[\n\r,;]+/', $text) ?: [];
            }
        }

        $clean = [];
        foreach ($items as $item) {
            $value = trim((string)$item);
            if ($value === '') {
                continue;
            }
            if ($lowercase) {
                $value = strtolower($value);
            }
            if (function_exists('mb_strlen') && function_exists('mb_substr')) {
                if (mb_strlen($value, 'UTF-8') > $maxLen) {
                    $value = mb_substr($value, 0, $maxLen, 'UTF-8');
                }
            } elseif (strlen($value) > $maxLen) {
                $value = substr($value, 0, $maxLen);
            }
            $clean[] = $value;
            if (count($clean) >= $maxItems) {
                break;
            }
        }
        return array_values(array_unique($clean));
    }
}

if (!function_exists('md_json_encode_list')) {
    function md_json_encode_list(array $items): string
    {
        $json = json_encode(array_values(array_unique($items)), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return is_string($json) ? $json : '[]';
    }
}

if (!function_exists('md_public_url_or_empty')) {
    function md_public_url_or_empty(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        return app_brand_public_url($value);
    }
}

if (!function_exists('md_pricing_parse_lines')) {
    function md_pricing_parse_lines(string $raw, array $fields, int $max = 200): array
    {
        $lines = preg_split('/\r\n|\r|\n/', trim($raw)) ?: [];
        $rows = [];
        foreach ($lines as $line) {
            $line = trim((string)$line);
            if ($line === '') {
                continue;
            }
            $parts = array_map('trim', explode('|', $line));
            $row = [];
            foreach ($fields as $idx => $def) {
                $key = $def['key'];
                $type = $def['type'] ?? 'string';
                $value = $parts[$idx] ?? '';
                if ($type === 'float') {
                    $value = (float)str_replace(',', '.', (string)$value);
                } elseif ($type === 'int') {
                    $value = (int)preg_replace('/[^0-9\-]/', '', (string)$value);
                } else {
                    $value = trim((string)$value);
                }
                $row[$key] = $value;
            }
            if (!empty($row[$fields[0]['key']])) {
                $rows[] = $row;
            }
            if (count($rows) >= $max) {
                break;
            }
        }
        return $rows;
    }
}

if (!function_exists('md_pricing_collect_rows')) {
    function md_pricing_collect_rows($raw, array $fields, int $max = 200, ?string $requiredKey = null): array
    {
        $rows = [];
        if (!is_array($raw)) {
            return $rows;
        }
        $count = 0;
        foreach ($raw as $rowRaw) {
            if (!is_array($rowRaw)) {
                continue;
            }
            $row = [];
            foreach ($fields as $def) {
                $key = $def['key'];
                $type = $def['type'] ?? 'string';
                $value = $rowRaw[$key] ?? '';
                if ($type === 'float') {
                    $value = (float)str_replace(',', '.', trim((string)$value));
                } elseif ($type === 'int') {
                    $value = (int)preg_replace('/[^0-9\-]/', '', (string)$value);
                } else {
                    $value = trim((string)$value);
                }
                $row[$key] = $value;
            }
            $acceptKey = $requiredKey ?: ($fields[0]['key'] ?? '');
            if ($acceptKey !== '' && trim((string)($row[$acceptKey] ?? '')) !== '') {
                $rows[] = $row;
                $count++;
            }
            if ($count >= $max) {
                break;
            }
        }
        return $rows;
    }
}

if (!function_exists('md_pricing_collect_json_rows')) {
    function md_pricing_collect_json_rows($raw, array $fields, int $max = 200, ?string $requiredKey = null): array
    {
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }
        return md_pricing_collect_rows($decoded, $fields, $max, $requiredKey);
    }
}

if (!function_exists('md_pricing_derive_machine_key')) {
    function md_pricing_derive_machine_key(array $row): string
    {
        $key = trim((string)($row['key'] ?? ''));
        if ($key !== '') {
            return $key;
        }
        $labelAr = trim((string)($row['label_ar'] ?? ''));
        $labelEn = trim((string)($row['label_en'] ?? ''));
        $seed = $labelEn !== '' ? $labelEn : $labelAr;
        $asciiSeed = function_exists('iconv') ? (string)@iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $seed) : $seed;
        $slug = strtolower((string)preg_replace('/[^a-z0-9]+/i', '_', $asciiSeed));
        $slug = trim($slug, '_');
        return $slug !== '' ? $slug : ('machine_' . substr(sha1($labelAr . '|' . $labelEn), 0, 10));
    }
}

if (!function_exists('md_pricing_infer_machine_sheet_class')) {
    function md_pricing_infer_machine_sheet_class(array $row): string
    {
        $rawClass = strtolower(trim((string)($row['sheet_class'] ?? '')));
        $text = strtolower(trim(implode(' ', [
            (string)($row['key'] ?? ''),
            (string)($row['label_ar'] ?? ''),
            (string)($row['label_en'] ?? ''),
        ])));
        if (str_contains($text, 'quarter') || str_contains($text, 'gto') || str_contains($text, 'ربع')) {
            return 'quarter';
        }
        if (str_contains($text, 'half') || str_contains($text, 'نصف') || preg_match('/(^|[^0-9])1\\s*\\/\\s*2([^0-9]|$)/', $text)) {
            return 'half';
        }
        if (in_array($rawClass, ['quarter', 'half', 'full'], true)) {
            return $rawClass;
        }
        return 'full';
    }
}

if (!function_exists('md_pricing_unique_machine_key')) {
    function md_pricing_unique_machine_key(string $baseKey, string $labelAr, string $labelEn, array &$seen): string
    {
        $baseKey = trim($baseKey) !== '' ? trim($baseKey) : md_pricing_derive_machine_key(['label_ar' => $labelAr, 'label_en' => $labelEn]);
        $candidate = $baseKey;
        if (isset($seen[$candidate])) {
            $labelKey = md_pricing_derive_machine_key(['label_ar' => $labelAr, 'label_en' => $labelEn]);
            $candidate = (str_starts_with($labelKey, $baseKey . '_') || $labelKey !== $baseKey) ? $labelKey : ($baseKey . '_' . ((int)$seen[$baseKey] + 1));
            $counter = 2;
            $seed = $candidate;
            while (isset($seen[$candidate])) {
                $candidate = $seed . '_' . $counter;
                $counter++;
            }
        }
        $seen[$candidate] = ($seen[$candidate] ?? 0) + 1;
        return $candidate;
    }
}

if (!function_exists('md_pricing_normalize_finish_unit')) {
    function md_pricing_normalize_finish_unit(string $unit): string
    {
        $unit = strtolower(trim($unit));
        return match ($unit) {
            'tray', 'trays', 'تراج' => 'tray',
            'sheet', 'sheets', 'شيت', 'فرخ' => 'sheet',
            default => 'piece',
        };
    }
}

if (!function_exists('md_pricing_normalize_finish_rows')) {
    function md_pricing_normalize_finish_rows(array $rows): array
    {
        $normalized = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $row['default_unit'] = md_pricing_normalize_finish_unit((string)($row['default_unit'] ?? 'piece'));
            $pricePiece = max(0, (float)str_replace(',', '.', (string)($row['price_piece'] ?? 0)));
            $priceTray = max(0, (float)str_replace(',', '.', (string)($row['price_tray'] ?? 0)));
            if ($row['default_unit'] === 'piece' && $pricePiece <= 0 && $priceTray > 0) {
                $row['default_unit'] = 'tray';
            }
            $normalized[] = $row;
        }
        return $normalized;
    }
}

if (!function_exists('md_pricing_normalize_machine_rows')) {
    function md_pricing_normalize_machine_rows(array $rows): array
    {
        $normalized = [];
        $seen = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $labelAr = trim((string)($row['label_ar'] ?? ''));
            $labelEn = trim((string)($row['label_en'] ?? ''));
            if ($labelAr === '' && $labelEn === '') {
                continue;
            }
            $sheetClass = md_pricing_infer_machine_sheet_class($row);
            $baseKey = md_pricing_derive_machine_key($row);
            $normalized[] = [
                'key' => md_pricing_unique_machine_key($baseKey, $labelAr, $labelEn, $seen),
                'label_ar' => $labelAr,
                'label_en' => $labelEn,
                'price_per_tray' => max(0, (float)str_replace(',', '.', (string)($row['price_per_tray'] ?? 0))),
                'min_trays' => max(1, (int)preg_replace('/[^0-9\-]/', '', (string)($row['min_trays'] ?? 1))),
                'plate_cost' => max(0, (float)str_replace(',', '.', (string)($row['plate_cost'] ?? 0))),
                'sheet_class' => $sheetClass,
            ];
        }
        return $normalized;
    }
}

if (!function_exists('md_visible_profile_fields')) {
    function md_visible_profile_fields($raw, array $allowed): array
    {
        $items = [];
        if (is_array($raw)) {
            $items = $raw;
        } else {
            $items = preg_split('/[\s,;\n\r]+/', trim((string)$raw)) ?: [];
        }
        $clean = [];
        foreach ($items as $item) {
            $key = trim((string)$item);
            if ($key !== '' && isset($allowed[$key]) && !in_array($key, $clean, true)) {
                $clean[] = $key;
            }
        }
        return $clean;
    }
}

if (!function_exists('md_stage_action_definitions')) {
    function md_stage_action_definitions(): array
    {
        return [
            'client_review_whatsapp' => ['ar' => 'زر واتساب لمراجعة العميل', 'en' => 'WhatsApp client review button'],
            'file_whatsapp' => ['ar' => 'إرسال الملفات عبر واتساب', 'en' => 'Send files via WhatsApp'],
            'file_email' => ['ar' => 'إرسال الملفات عبر إيميل مباشر', 'en' => 'Send files via direct email'],
            'job_whatsapp' => ['ar' => 'إرسال أمر التشغيل عبر واتساب', 'en' => 'Send work order via WhatsApp'],
            'job_email' => ['ar' => 'إرسال أمر التشغيل عبر إيميل مباشر', 'en' => 'Send work order via direct email'],
        ];
    }
}

if (!function_exists('md_normalize_stage_actions')) {
    function md_normalize_stage_actions($raw): array
    {
        $allowed = array_fill_keys(array_keys(md_stage_action_definitions()), true);
        $items = md_string_list($raw, true, 20, 80);
        $result = [];
        foreach ($items as $item) {
            $key = preg_replace('/[^a-z0-9_]/', '', $item);
            if ($key !== '' && isset($allowed[$key])) {
                $result[] = $key;
            }
        }
        return array_values(array_unique($result));
    }
}

if (!function_exists('md_stage_action_labels')) {
    function md_stage_action_labels(array $actionKeys, bool $isEnglish): array
    {
        $defs = md_stage_action_definitions();
        $labels = [];
        foreach ($actionKeys as $key) {
            if (!isset($defs[$key])) {
                continue;
            }
            $labels[] = (string)($isEnglish ? $defs[$key]['en'] : $defs[$key]['ar']);
        }
        return array_values(array_unique($labels));
    }
}

if (!function_exists('md_stage_template_definitions')) {
    function md_stage_template_definitions(): array
    {
        return [
            'briefing' => ['ar' => '1. التجهيز', 'en' => '1. Briefing', 'terminal' => 0, 'actions' => []],
            'design' => ['ar' => '2. التصميم', 'en' => '2. Design', 'terminal' => 0, 'actions' => []],
            'client_rev' => ['ar' => '3. مراجعة العميل', 'en' => '3. Client Review', 'terminal' => 0, 'actions' => ['client_review_whatsapp']],
            'materials' => ['ar' => '4. الخامات', 'en' => '4. Materials', 'terminal' => 0, 'actions' => []],
            'printing' => ['ar' => '5. التنفيذ/الطباعة', 'en' => '5. Production/Printing', 'terminal' => 0, 'actions' => []],
            'finishing' => ['ar' => '6. التشطيب', 'en' => '6. Finishing', 'terminal' => 0, 'actions' => ['file_whatsapp', 'file_email']],
            'delivery' => ['ar' => '7. التسليم', 'en' => '7. Delivery', 'terminal' => 0, 'actions' => ['job_whatsapp', 'job_email']],
            'accounting' => ['ar' => '8. الحسابات', 'en' => '8. Accounting', 'terminal' => 0, 'actions' => []],
            'completed' => ['ar' => '9. الأرشيف', 'en' => '9. Archive', 'terminal' => 1, 'actions' => []],
        ];
    }
}

if (!function_exists('md_swap_type_order')) {
    function md_swap_type_order(mysqli $conn, string $typeKey, string $direction): bool
    {
        $direction = strtolower(trim($direction));
        if ($typeKey === '' || !in_array($direction, ['up', 'down'], true)) {
            return false;
        }

        $stmt = $conn->prepare("SELECT type_key, sort_order FROM app_operation_types WHERE type_key = ? LIMIT 1");
        $stmt->bind_param('s', $typeKey);
        $stmt->execute();
        $current = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$current) {
            return false;
        }

        $currentOrder = (int)$current['sort_order'];
        if ($direction === 'up') {
            $stmtNeighbor = $conn->prepare("
                SELECT type_key, sort_order
                FROM app_operation_types
                WHERE sort_order < ? OR (sort_order = ? AND type_key < ?)
                ORDER BY sort_order DESC, type_key DESC
                LIMIT 1
            ");
        } else {
            $stmtNeighbor = $conn->prepare("
                SELECT type_key, sort_order
                FROM app_operation_types
                WHERE sort_order > ? OR (sort_order = ? AND type_key > ?)
                ORDER BY sort_order ASC, type_key ASC
                LIMIT 1
            ");
        }
        $stmtNeighbor->bind_param('iis', $currentOrder, $currentOrder, $typeKey);
        $stmtNeighbor->execute();
        $neighbor = $stmtNeighbor->get_result()->fetch_assoc();
        $stmtNeighbor->close();
        if (!$neighbor) {
            return false;
        }

        $neighborKey = (string)$neighbor['type_key'];
        $neighborOrder = (int)$neighbor['sort_order'];

        $conn->begin_transaction();
        try {
            $u1 = $conn->prepare("UPDATE app_operation_types SET sort_order = ? WHERE type_key = ?");
            $u1->bind_param('is', $neighborOrder, $typeKey);
            $u1->execute();
            $u1->close();

            $u2 = $conn->prepare("UPDATE app_operation_types SET sort_order = ? WHERE type_key = ?");
            $u2->bind_param('is', $currentOrder, $neighborKey);
            $u2->execute();
            $u2->close();

            $conn->commit();
            return true;
        } catch (Throwable $e) {
            $conn->rollback();
            throw $e;
        }
    }
}

if (!function_exists('md_swap_stage_order')) {
    function md_swap_stage_order(mysqli $conn, int $stageId, string $direction): bool
    {
        $direction = strtolower(trim($direction));
        if ($stageId <= 0 || !in_array($direction, ['up', 'down'], true)) {
            return false;
        }

        $stmt = $conn->prepare("SELECT id, type_key, stage_order FROM app_operation_stages WHERE id = ? LIMIT 1");
        $stmt->bind_param('i', $stageId);
        $stmt->execute();
        $current = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$current) {
            return false;
        }

        $typeKey = (string)$current['type_key'];
        $currentOrder = (int)$current['stage_order'];
        if ($direction === 'up') {
            $stmtNeighbor = $conn->prepare("
                SELECT id, stage_order
                FROM app_operation_stages
                WHERE type_key = ?
                  AND (stage_order < ? OR (stage_order = ? AND id < ?))
                ORDER BY stage_order DESC, id DESC
                LIMIT 1
            ");
        } else {
            $stmtNeighbor = $conn->prepare("
                SELECT id, stage_order
                FROM app_operation_stages
                WHERE type_key = ?
                  AND (stage_order > ? OR (stage_order = ? AND id > ?))
                ORDER BY stage_order ASC, id ASC
                LIMIT 1
            ");
        }
        $stmtNeighbor->bind_param('siii', $typeKey, $currentOrder, $currentOrder, $stageId);
        $stmtNeighbor->execute();
        $neighbor = $stmtNeighbor->get_result()->fetch_assoc();
        $stmtNeighbor->close();
        if (!$neighbor) {
            return false;
        }

        $neighborId = (int)$neighbor['id'];
        $neighborOrder = (int)$neighbor['stage_order'];

        $conn->begin_transaction();
        try {
            $u1 = $conn->prepare("UPDATE app_operation_stages SET stage_order = ? WHERE id = ?");
            $u1->bind_param('ii', $neighborOrder, $stageId);
            $u1->execute();
            $u1->close();

            $u2 = $conn->prepare("UPDATE app_operation_stages SET stage_order = ? WHERE id = ?");
            $u2->bind_param('ii', $currentOrder, $neighborId);
            $u2->execute();
            $u2->close();

            $conn->commit();
            return true;
        } catch (Throwable $e) {
            $conn->rollback();
            throw $e;
        }
    }
}

if (!function_exists('md_swap_catalog_order')) {
    function md_swap_catalog_order(mysqli $conn, int $itemId, string $direction): bool
    {
        $direction = strtolower(trim($direction));
        if ($itemId <= 0 || !in_array($direction, ['up', 'down'], true)) {
            return false;
        }

        $stmt = $conn->prepare("SELECT id, type_key, catalog_group, sort_order FROM app_operation_catalog WHERE id = ? LIMIT 1");
        $stmt->bind_param('i', $itemId);
        $stmt->execute();
        $current = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$current) {
            return false;
        }

        $typeKey = (string)$current['type_key'];
        $group = (string)$current['catalog_group'];
        $currentOrder = (int)$current['sort_order'];
        if ($direction === 'up') {
            $stmtNeighbor = $conn->prepare("
                SELECT id, sort_order
                FROM app_operation_catalog
                WHERE type_key = ?
                  AND catalog_group = ?
                  AND (sort_order < ? OR (sort_order = ? AND id < ?))
                ORDER BY sort_order DESC, id DESC
                LIMIT 1
            ");
        } else {
            $stmtNeighbor = $conn->prepare("
                SELECT id, sort_order
                FROM app_operation_catalog
                WHERE type_key = ?
                  AND catalog_group = ?
                  AND (sort_order > ? OR (sort_order = ? AND id > ?))
                ORDER BY sort_order ASC, id ASC
                LIMIT 1
            ");
        }
        $stmtNeighbor->bind_param('ssiii', $typeKey, $group, $currentOrder, $currentOrder, $itemId);
        $stmtNeighbor->execute();
        $neighbor = $stmtNeighbor->get_result()->fetch_assoc();
        $stmtNeighbor->close();
        if (!$neighbor) {
            return false;
        }

        $neighborId = (int)$neighbor['id'];
        $neighborOrder = (int)$neighbor['sort_order'];

        $conn->begin_transaction();
        try {
            $u1 = $conn->prepare("UPDATE app_operation_catalog SET sort_order = ? WHERE id = ?");
            $u1->bind_param('ii', $neighborOrder, $itemId);
            $u1->execute();
            $u1->close();

            $u2 = $conn->prepare("UPDATE app_operation_catalog SET sort_order = ? WHERE id = ?");
            $u2->bind_param('ii', $currentOrder, $neighborId);
            $u2->execute();
            $u2->close();

            $conn->commit();
            return true;
        } catch (Throwable $e) {
            $conn->rollback();
            throw $e;
        }
    }
}
