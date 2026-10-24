<?php

require_once __DIR__ . '/../finance/fx_widget_runtime.php';

if (!function_exists('pricing_text')) {
    function pricing_text(bool $isEnglish, string $ar, string $en): string
    {
        return $isEnglish ? $en : $ar;
    }
}

if (!function_exists('pricing_find_row')) {
    function pricing_find_row(array $rows, string $key, string $field = 'key'): ?array
    {
        $key = trim($key);
        foreach ($rows as $row) {
            if (trim((string)($row[$field] ?? '')) === $key) {
                return $row;
            }
        }
        if ($field === 'key') {
            foreach ($rows as $row) {
                foreach (['label_ar', 'label_en'] as $labelField) {
                    if (trim((string)($row[$labelField] ?? '')) === $key) {
                        return $row;
                    }
                }
            }
        }
        return null;
    }
}

if (!function_exists('pricing_derive_machine_key')) {
    function pricing_derive_machine_key(array $row): string
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

if (!function_exists('pricing_infer_machine_sheet_class')) {
    function pricing_infer_machine_sheet_class(array $row): string
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

if (!function_exists('pricing_unique_machine_key')) {
    function pricing_unique_machine_key(string $baseKey, string $labelAr, string $labelEn, array &$seen): string
    {
        $baseKey = trim($baseKey) !== '' ? trim($baseKey) : pricing_derive_machine_key(['label_ar' => $labelAr, 'label_en' => $labelEn]);
        $candidate = $baseKey;
        if (isset($seen[$candidate])) {
            $labelKey = pricing_derive_machine_key(['label_ar' => $labelAr, 'label_en' => $labelEn]);
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

if (!function_exists('pricing_normalize_finish_unit')) {
    function pricing_normalize_finish_unit(string $unit): string
    {
        $unit = strtolower(trim($unit));
        return match ($unit) {
            'tray', 'trays', 'تراج' => 'tray',
            'sheet', 'sheets', 'شيت', 'فرخ' => 'sheet',
            default => 'piece',
        };
    }
}

if (!function_exists('pricing_normalize_finish_rows')) {
    function pricing_normalize_finish_rows(array $rows): array
    {
        $normalized = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $row['default_unit'] = pricing_normalize_finish_unit((string)($row['default_unit'] ?? 'piece'));
            if ($row['default_unit'] === 'piece' && pricing_float($row['price_piece'] ?? 0) <= 0 && pricing_float($row['price_tray'] ?? 0) > 0) {
                $row['default_unit'] = 'tray';
            }
            $normalized[] = $row;
        }
        return $normalized;
    }
}

if (!function_exists('pricing_normalize_machine_rows')) {
    function pricing_normalize_machine_rows(array $rows): array
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
            $sheetClass = pricing_infer_machine_sheet_class($row);
            $baseKey = pricing_derive_machine_key($row);
            $normalized[] = [
                'key' => pricing_unique_machine_key($baseKey, $labelAr, $labelEn, $seen),
                'label_ar' => $labelAr,
                'label_en' => $labelEn,
                'price_per_tray' => max(0, pricing_float($row['price_per_tray'] ?? 0)),
                'min_trays' => max(1, pricing_int($row['min_trays'] ?? 1)),
                'plate_cost' => max(0, pricing_float($row['plate_cost'] ?? 0)),
                'sheet_class' => $sheetClass,
            ];
        }
        return $normalized;
    }
}

if (!function_exists('pricing_float')) {
    function pricing_float($value): float
    {
        return is_numeric($value) ? (float)$value : 0.0;
    }
}

if (!function_exists('pricing_int')) {
    function pricing_int($value): int
    {
        return is_numeric($value) ? (int)$value : 0;
    }
}

if (!function_exists('pricing_sheet_cost_from_ton')) {
    function pricing_sheet_cost_from_ton(float $tonPrice, float $widthCm, float $heightCm, float $gsm): float
    {
        if ($tonPrice <= 0 || $widthCm <= 0 || $heightCm <= 0 || $gsm <= 0) {
            return 0.0;
        }
        $sheetWeightKg = ($widthCm / 100) * ($heightCm / 100) * ($gsm / 1000);
        return $sheetWeightKg * ($tonPrice / 1000);
    }
}

if (!function_exists('pricing_currency')) {
    function pricing_currency(float $value): string
    {
        return number_format($value, 2);
    }
}

if (!function_exists('pricing_binding_label')) {
    function pricing_binding_label(bool $isEnglish, string $bindingType): string
    {
        $map = [
            'cut' => pricing_text($isEnglish, 'بشر', 'Cut'),
            'thread' => pricing_text($isEnglish, 'خيط', 'Thread'),
            'cut_thread' => pricing_text($isEnglish, 'بشر وخيط', 'Cut + Thread'),
            'staple' => pricing_text($isEnglish, 'دبوس', 'Staple'),
            'staple_cut' => pricing_text($isEnglish, 'دبوس وبشر', 'Staple + Cut'),
        ];
        return $map[$bindingType] ?? ($bindingType !== '' ? $bindingType : pricing_text($isEnglish, 'غير محدد', 'Not set'));
    }
}

if (!function_exists('pricing_sheet_class_factor')) {
    function pricing_sheet_class_factor(string $sheetClass): float
    {
        $sheetClass = strtolower(trim($sheetClass));
        return match ($sheetClass) {
            'quarter' => 0.25,
            'half' => 0.5,
            default => 1.0,
        };
    }
}

if (!function_exists('pricing_sheet_class_divisor')) {
    function pricing_sheet_class_divisor(string $sheetClass): int
    {
        return match (strtolower(trim($sheetClass))) {
            'quarter' => 4,
            'half' => 2,
            default => 1,
        };
    }
}

if (!function_exists('pricing_sheet_class_label')) {
    function pricing_sheet_class_label(bool $isEnglish, string $sheetClass): string
    {
        return match (strtolower(trim($sheetClass))) {
            'quarter' => pricing_text($isEnglish, 'ربع فرخ', 'Quarter Sheet'),
            'half' => pricing_text($isEnglish, 'نصف فرخ', 'Half Sheet'),
            default => pricing_text($isEnglish, 'فرخ كامل', 'Full Sheet'),
        };
    }
}

if (!function_exists('pricing_print_mode_meta')) {
    function pricing_print_mode_meta(string $mode): array
    {
        $mode = strtolower(trim($mode));
        return match ($mode) {
            'double_plates' => [
                'mode' => 'double_plates',
                'passes' => 2,
                'plate_sets' => 2,
                'waste_multiplier' => 2,
            ],
            'work_turn' => [
                'mode' => 'work_turn',
                'passes' => 2,
                'plate_sets' => 1,
                'waste_multiplier' => 1,
            ],
            default => [
                'mode' => 'single',
                'passes' => 1,
                'plate_sets' => 1,
                'waste_multiplier' => 1,
            ],
        };
    }
}

if (!function_exists('pricing_print_mode_label')) {
    function pricing_print_mode_label(bool $isEnglish, string $mode): string
    {
        return match (strtolower(trim($mode))) {
            'double_plates' => pricing_text($isEnglish, 'وجهين بطقمين زنكات', 'Double Face (Two Plate Sets)'),
            'work_turn' => pricing_text($isEnglish, 'وجهين طبع وقلب', 'Work & Turn'),
            default => pricing_text($isEnglish, 'وجه واحد', 'Single Face'),
        };
    }
}

if (!function_exists('pricing_billable_tray_runs')) {
    function pricing_billable_tray_runs(int $computedTrays, int $minTrays, int $passes, int $plateSets): int
    {
        $computedTrays = max(0, $computedTrays);
        $minTrays = max(1, $minTrays);
        $passes = max(1, $passes);
        $plateSets = max(1, $plateSets);
        $runsForPlateSets = $plateSets * max($computedTrays, $minTrays);
        $extraPasses = max(0, $passes - $plateSets);
        return $runsForPlateSets + ($extraPasses * $computedTrays);
    }
}

if (!function_exists('pricing_default_settings')) {
    function pricing_default_settings(): array
    {
        return [
            'waste_percent' => 0,
            'waste_sheets' => 0,
            'profit_percent' => 15,
            'misc_cost' => 0,
            'setup_fee' => 0,
            'gather_cost_per_signature' => 0,
            'risk_percent' => 0,
            'reject_percent' => 0,
            'color_test_cost' => 0,
            'internal_transport_cost' => 0,
            'book_mode_enabled' => 0,
            'binding_costs' => [],
        ];
    }
}

if (!function_exists('pricing_size_presets')) {
    function pricing_size_presets(): array
    {
        return [
            '70x100' => [70, 100],
            '50x70' => [50, 70],
            '35x50' => [35, 50],
            '33x48' => [33, 48],
            '32x45' => [32, 45],
            '25x35' => [25, 35],
            '23x33' => [23, 33],
        ];
    }
}

if (!function_exists('pricing_load_config')) {
    function pricing_load_config(mysqli $conn): array
    {
        $pricingDefaults = json_decode(app_setting_get($conn, 'pricing_defaults', ''), true);
        if (!is_array($pricingDefaults)) {
            $pricingDefaults = [];
        }
        $pricingDefaults = array_merge(pricing_default_settings(), $pricingDefaults);

        $paperTypes = json_decode(app_setting_get($conn, 'pricing_paper_types', ''), true);
        $machines = json_decode(app_setting_get($conn, 'pricing_machines', ''), true);
        $finishOps = json_decode(app_setting_get($conn, 'pricing_finishing_ops', ''), true);

        if (!is_array($paperTypes)) {
            $paperTypes = [];
        }
        if (!is_array($machines)) {
            $machines = [];
        }
        $machines = pricing_normalize_machine_rows($machines);
        if (!is_array($finishOps)) {
            $finishOps = [];
        }
        $finishOps = pricing_normalize_finish_rows($finishOps);

        return [
            'enabled' => app_setting_get($conn, 'pricing_enabled', '0') === '1',
            'defaults' => $pricingDefaults,
            'binding_costs' => (array)($pricingDefaults['binding_costs'] ?? []),
            'paper_types' => $paperTypes,
            'machines' => $machines,
            'finish_ops' => $finishOps,
            'size_presets' => pricing_size_presets(),
        ];
    }
}

if (!function_exists('pricing_load_record_snapshot')) {
    function pricing_load_record_snapshot(mysqli $conn, int $recordId): ?array
    {
        if ($recordId <= 0) {
            return null;
        }

        $stmt = $conn->prepare("SELECT snapshot_json FROM app_pricing_records WHERE id = ? LIMIT 1");
        if (!$stmt) {
            return null;
        }

        $stmt->bind_param('i', $recordId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $snapshot = json_decode((string)($row['snapshot_json'] ?? ''), true);
        return is_array($snapshot) ? $snapshot : null;
    }
}

if (!function_exists('pricing_load_record_row')) {
    function pricing_load_record_row(mysqli $conn, int $recordId): ?array
    {
        if ($recordId <= 0) {
            return null;
        }

        $stmt = $conn->prepare("SELECT * FROM app_pricing_records WHERE id = ? LIMIT 1");
        if (!$stmt) {
            return null;
        }

        $stmt->bind_param('i', $recordId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return is_array($row) ? $row : null;
    }
}

if (!function_exists('pricing_load_record_form')) {
    function pricing_load_record_form(mysqli $conn, int $recordId): ?array
    {
        $snapshot = pricing_load_record_snapshot($conn, $recordId);
        if (!is_array($snapshot)) {
            return null;
        }

        $form = $snapshot['form'] ?? null;
        return is_array($form) ? $form : null;
    }
}

if (!function_exists('pricing_apply_loaded_record_for_calc')) {
    function pricing_apply_loaded_record_for_calc(array $form): void
    {
        $_POST = $form;
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST['action'] = 'calc';
    }
}

if (!function_exists('pricing_merge_source_record_into_post')) {
    function pricing_merge_source_record_into_post(array $form, array $currentPost, ?array $recordRow = null): void
    {
        $requestedAction = (string)($currentPost['action'] ?? 'calc');
        $_POST = array_merge($form, $currentPost);
        if (is_array($recordRow)) {
            if (trim((string)($recordRow['operation_name'] ?? '')) !== '') {
                $_POST['operation_name'] = (string)$recordRow['operation_name'];
            }
            if (array_key_exists('notes', $recordRow)) {
                $_POST['notes'] = (string)$recordRow['notes'];
            }
        }
        $_POST['action'] = $requestedAction;
    }
}

if (!function_exists('pricing_client_summary')) {
    function pricing_client_summary(mysqli $conn, int $clientId): array
    {
        if ($clientId <= 0) {
            return ['name' => '', 'phone' => ''];
        }

        $stmt = $conn->prepare("SELECT name, phone FROM clients WHERE id = ? LIMIT 1");
        if (!$stmt) {
            return ['name' => '', 'phone' => ''];
        }

        $stmt->bind_param('i', $clientId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return [
            'name' => trim((string)($row['name'] ?? '')),
            'phone' => trim((string)($row['phone'] ?? '')),
        ];
    }
}

if (!function_exists('pricing_empty_breakdown')) {
    function pricing_empty_breakdown(): array
    {
        return [
            'paper_cost' => 0.0,
            'prepress_cost' => 0.0,
            'printing_cost' => 0.0,
            'finishing_cost' => 0.0,
            'trays' => 0,
            'printed_sheets' => 0,
            'plates' => 0,
        ];
    }
}

if (!function_exists('pricing_empty_calc')) {
    function pricing_empty_calc(): array
    {
        return [
            'ok' => false,
            'error' => '',
            'quote_error' => '',
            'paper_cost' => 0.0,
            'design_cost' => 0.0,
            'prepress_cost' => 0.0,
            'printing_cost' => 0.0,
            'pantone_printing_cost' => 0.0,
            'plates_cost' => 0.0,
            'finishing_cost' => 0.0,
            'packaging_cost' => 0.0,
            'delivery_cost' => 0.0,
            'color_test_cost' => 0.0,
            'internal_transport_cost' => 0.0,
            'risk_cost' => 0.0,
            'misc_cost' => 0.0,
            'setup_fee' => 0.0,
            'profit_cost' => 0.0,
            'subtotal' => 0.0,
            'total' => 0.0,
            'qty' => 0.0,
            'unit_label' => '',
            'sheet_width_cm' => 0.0,
            'sheet_height_cm' => 0.0,
            'sheet_gsm' => 0.0,
            'sheet_cost' => 0.0,
            'sheet_yield' => 1,
            'machine_sheet_divisor' => 1,
            'machine_sheets_required' => 0,
            'waste_machine_sheets' => 0,
            'total_machine_sheets' => 0,
            'base_units' => 0.0,
            'sheets_required' => 0,
            'sheets_with_waste' => 0,
            'impressions' => 0,
            'trays' => 0,
            'print_faces' => 1,
            'print_mode' => 'single',
            'print_mode_label' => '',
            'total_color_sets' => 0,
            'plates_count' => 0,
            'book_bind_cost' => 0.0,
            'book_gather_cost' => 0.0,
            'job_title' => '',
            'job_specs' => '',
            'paper_name' => '',
            'paper_ton_price' => 0.0,
            'machine_name' => '',
            'stage_rows' => [],
            'finishing_rows' => [],
            'printing_rows' => [],
            'cover_paper_name' => '',
            'cover_pantone_printing_cost' => 0.0,
            'cover_paper_ton_price' => 0.0,
            'cover_sheet_width_cm' => 0.0,
            'cover_sheet_height_cm' => 0.0,
            'cover_sheet_gsm' => 0.0,
            'cover_sheet_cost' => 0.0,
            'cover_sheets_required' => 0,
            'cover_sheets_with_waste' => 0,
            'inner_paper_name' => '',
            'inner_pantone_printing_cost' => 0.0,
            'inner_paper_ton_price' => 0.0,
            'inner_sheet_width_cm' => 0.0,
            'inner_sheet_height_cm' => 0.0,
            'inner_sheet_gsm' => 0.0,
            'inner_sheet_cost' => 0.0,
            'inner_sheets_required' => 0,
            'inner_sheets_with_waste' => 0,
        ];
    }
}

if (!function_exists('pricing_compute_regular_print_stage')) {
    function pricing_compute_regular_print_stage(array $input): array
    {
        $plateColorsPerFace = max(0, (int)($input['plate_colors_per_face'] ?? 0));
        $plateSets = max(1, (int)($input['plate_sets'] ?? 1));
        $plateUnitCost = max(0, (float)($input['plate_unit_cost'] ?? 0));
        $prepressSetupCost = max(0, (float)($input['prepress_setup_cost'] ?? 0));
        $cuttingSetupCost = max(0, (float)($input['cutting_setup_cost'] ?? 0));
        $totalMachineSheets = max(0, (int)($input['total_machine_sheets'] ?? 0));
        $minTrays = max(1, (int)($input['min_trays'] ?? 1));
        $passes = max(1, (int)($input['passes'] ?? 1));
        $processColorsPerFace = max(0, (int)($input['process_colors_per_face'] ?? 0));
        $pantone = max(0, (int)($input['pantone'] ?? 0));
        $pantoneTrayPrice = max(0, (float)($input['pantone_tray_price'] ?? 0));
        $pricePerTray = max(0, (float)($input['price_per_tray'] ?? 0));

        $plateMultiplier = $plateColorsPerFace * $plateSets;
        $platesCost = $plateUnitCost * $plateMultiplier;
        $prepressCost = $platesCost + $prepressSetupCost + $cuttingSetupCost;
        $impressions = $totalMachineSheets;
        $computedTrays = (int)ceil($totalMachineSheets / 1000);
        $trays = pricing_billable_tray_runs($computedTrays, $minTrays, $passes, $plateSets);
        $processPrintingCost = $processColorsPerFace * $pricePerTray * $trays;
        $pantonePrintingCost = $pantone * $pantoneTrayPrice * $trays;
        $printingCost = $processPrintingCost + $pantonePrintingCost;

        return [
            'plate_multiplier' => $plateMultiplier,
            'plates_cost' => $platesCost,
            'prepress_cost' => $prepressCost,
            'impressions' => $impressions,
            'computed_trays' => $computedTrays,
            'min_trays' => $minTrays,
            'trays' => $trays,
            'price_per_tray' => $pricePerTray,
            'process_printing_cost' => $processPrintingCost,
            'pantone_printing_cost' => $pantonePrintingCost,
            'printing_cost' => $printingCost,
        ];
    }
}

if (!function_exists('pricing_compute_books_print_stage')) {
    function pricing_compute_books_print_stage(array $input): array
    {
        $qty = max(0, pricing_float($input['qty'] ?? 0));
        $signaturesCount = max(1, pricing_int($input['signatures_count'] ?? 1));
        $wastePercent = max(0, pricing_float($input['waste_percent'] ?? 0));
        $rejectPercent = max(0, pricing_float($input['reject_percent'] ?? 0));
        $wasteSheets = max(0, pricing_int($input['waste_sheets'] ?? 0));
        $prepressSetupCost = max(0, pricing_float($input['prepress_setup_cost'] ?? 0));
        $cuttingSetupCost = max(0, pricing_float($input['cutting_setup_cost'] ?? 0));
        $bookYieldOverride = max(0, pricing_int($input['book_yield_override'] ?? 0));

        $coverSheetYield = max(1, pricing_int($input['cover_sheet_yield'] ?? 1));
        $coverMachineSheetDivisor = max(1, pricing_int($input['cover_machine_sheet_divisor'] ?? 1));
        $coverPrintModeMeta = (array)($input['cover_print_mode_meta'] ?? []);
        $coverPaperTonPrice = max(0, pricing_float($input['cover_paper_ton_price'] ?? 0));
        $coverWidth = max(0, pricing_float($input['cover_width_cm'] ?? 0));
        $coverHeight = max(0, pricing_float($input['cover_height_cm'] ?? 0));
        $coverGsm = max(0, pricing_float($input['cover_gsm'] ?? 0));
        $coverColorSetsPerFace = max(0, pricing_int($input['cover_color_sets_per_face'] ?? 0));
        $coverPlateUnitCost = max(0, pricing_float($input['cover_plate_unit_cost'] ?? 0));
        $coverMachineMinTrays = max(1, pricing_int($input['cover_machine_min_trays'] ?? 1));
        $coverMachinePricePerTray = max(0, pricing_float($input['cover_machine_price_per_tray'] ?? 0));
        $coverProcessColorsPerFace = max(0, pricing_int($input['cover_process_colors_per_face'] ?? 0));
        $coverPantone = max(0, pricing_int($input['cover_pantone'] ?? 0));
        $coverPantoneTrayPrice = max(0, pricing_float($input['cover_pantone_tray_price'] ?? 0));

        $innerSheetYield = max(1, pricing_int($input['inner_sheet_yield'] ?? 1));
        $innerMachineSheetDivisor = max(1, pricing_int($input['inner_machine_sheet_divisor'] ?? 1));
        $innerPrintModeMeta = (array)($input['inner_print_mode_meta'] ?? []);
        $innerPaperTonPrice = max(0, pricing_float($input['inner_paper_ton_price'] ?? 0));
        $innerWidth = max(0, pricing_float($input['inner_width_cm'] ?? 0));
        $innerHeight = max(0, pricing_float($input['inner_height_cm'] ?? 0));
        $innerGsm = max(0, pricing_float($input['inner_gsm'] ?? 0));
        $innerColorSetsPerFace = max(0, pricing_int($input['inner_color_sets_per_face'] ?? 0));
        $innerPlateUnitCost = max(0, pricing_float($input['inner_plate_unit_cost'] ?? 0));
        $innerMachineMinTrays = max(1, pricing_int($input['inner_machine_min_trays'] ?? 1));
        $innerMachinePricePerTray = max(0, pricing_float($input['inner_machine_price_per_tray'] ?? 0));
        $innerProcessColorsPerFace = max(0, pricing_int($input['inner_process_colors_per_face'] ?? 0));
        $innerPantone = max(0, pricing_int($input['inner_pantone'] ?? 0));
        $innerPantoneTrayPrice = max(0, pricing_float($input['inner_pantone_tray_price'] ?? 0));

        $coverMachineSheetsRequired = (int)ceil($qty / $coverSheetYield);
        $coverSheetsRequired = (int)ceil($coverMachineSheetsRequired / $coverMachineSheetDivisor);
        $coverWastePerPlateSetMachineSheets = (int)ceil($coverMachineSheetsRequired * ($wastePercent / 100));
        $coverWasteMachineSheets = $coverWastePerPlateSetMachineSheets * max(1, (int)($coverPrintModeMeta['waste_multiplier'] ?? 1));
        $coverRejectMachineSheets = (int)ceil($coverMachineSheetsRequired * ($rejectPercent / 100));
        $coverExtraWasteMachineSheets = $wasteSheets * $coverMachineSheetDivisor;
        $coverTotalWasteMachineSheets = $coverWasteMachineSheets + $coverRejectMachineSheets + $coverExtraWasteMachineSheets;
        $coverTotalMachineSheets = $coverMachineSheetsRequired + $coverTotalWasteMachineSheets;
        $coverSheetsWithWaste = (int)ceil($coverTotalMachineSheets / $coverMachineSheetDivisor);
        $coverSheetCost = pricing_sheet_cost_from_ton($coverPaperTonPrice, $coverWidth, $coverHeight, $coverGsm);
        $coverPaperCost = $coverSheetCost * $coverSheetsWithWaste;
        $coverPrintedSheets = $coverTotalMachineSheets;
        $coverComputedTrays = (int)ceil($coverTotalMachineSheets / 1000);
        $coverMinTrays = max(1, $coverMachineMinTrays);
        $coverBillableTrayRuns = pricing_billable_tray_runs(
            $coverComputedTrays,
            $coverMinTrays,
            (int)($coverPrintModeMeta['passes'] ?? 1),
            (int)($coverPrintModeMeta['plate_sets'] ?? 1)
        );
        $coverPlateMultiplier = $coverColorSetsPerFace * (int)($coverPrintModeMeta['plate_sets'] ?? 1);
        $coverPlatesCost = $coverPlateUnitCost * $coverPlateMultiplier;
        $coverPricePerTray = $coverMachinePricePerTray;
        $coverProcessPrintingCost = $coverProcessColorsPerFace * $coverPricePerTray * $coverBillableTrayRuns;
        $coverPantonePrintingCost = $coverPantone * $coverPantoneTrayPrice * $coverBillableTrayRuns;
        $coverPrintingCost = $coverProcessPrintingCost + $coverPantonePrintingCost;

        $innerYield = $bookYieldOverride > 0 ? $bookYieldOverride : $innerSheetYield;
        $innerBaseUnits = $qty * $signaturesCount;
        $innerMachineSheetsRequired = (int)ceil($innerBaseUnits / max(1, $innerYield));
        $innerSheetsRequired = (int)ceil($innerMachineSheetsRequired / $innerMachineSheetDivisor);
        $innerWastePerPlateSetMachineSheets = (int)ceil($innerMachineSheetsRequired * ($wastePercent / 100));
        $innerWasteMachineSheets = $innerWastePerPlateSetMachineSheets * max(1, (int)($innerPrintModeMeta['waste_multiplier'] ?? 1));
        $innerRejectMachineSheets = (int)ceil($innerMachineSheetsRequired * ($rejectPercent / 100));
        $innerExtraWasteMachineSheets = $wasteSheets * $innerMachineSheetDivisor;
        $innerTotalWasteMachineSheets = $innerWasteMachineSheets + $innerRejectMachineSheets + $innerExtraWasteMachineSheets;
        $innerTotalMachineSheets = $innerMachineSheetsRequired + $innerTotalWasteMachineSheets;
        $innerSheetsWithWaste = (int)ceil($innerTotalMachineSheets / $innerMachineSheetDivisor);
        $innerSheetCost = pricing_sheet_cost_from_ton($innerPaperTonPrice, $innerWidth, $innerHeight, $innerGsm);
        $innerPaperCost = $innerSheetCost * $innerSheetsWithWaste;
        $innerPrintedSheets = $innerTotalMachineSheets;
        $innerComputedTrays = (int)ceil($innerTotalMachineSheets / 1000);
        $innerMinTrays = max(1, $innerMachineMinTrays);
        $innerBillableTrayRuns = pricing_billable_tray_runs(
            $innerComputedTrays,
            $innerMinTrays,
            (int)($innerPrintModeMeta['passes'] ?? 2),
            (int)($innerPrintModeMeta['plate_sets'] ?? 2)
        );
        $innerPlateMultiplier = $signaturesCount * $innerColorSetsPerFace * (int)($innerPrintModeMeta['plate_sets'] ?? 2);
        $innerPlatesCost = $innerPlateUnitCost * $innerPlateMultiplier;
        $innerPricePerTray = $innerMachinePricePerTray;
        $innerProcessPrintingCost = $innerProcessColorsPerFace * $innerPricePerTray * $innerBillableTrayRuns;
        $innerPantonePrintingCost = $innerPantone * $innerPantoneTrayPrice * $innerBillableTrayRuns;
        $innerPrintingCost = $innerProcessPrintingCost + $innerPantonePrintingCost;

        $coverBreakdown = [
            'paper_cost' => $coverPaperCost,
            'prepress_cost' => $coverPlatesCost,
            'printing_cost' => $coverPrintingCost,
            'pantone_printing_cost' => $coverPantonePrintingCost,
            'finishing_cost' => 0.0,
            'trays' => $coverBillableTrayRuns,
            'printed_sheets' => $coverPrintedSheets,
            'plates' => $coverPlateMultiplier,
        ];
        $innerBreakdown = [
            'paper_cost' => $innerPaperCost,
            'prepress_cost' => $innerPlatesCost,
            'printing_cost' => $innerPrintingCost,
            'pantone_printing_cost' => $innerPantonePrintingCost,
            'finishing_cost' => 0.0,
            'trays' => $innerBillableTrayRuns,
            'printed_sheets' => $innerPrintedSheets,
            'plates' => $innerPlateMultiplier,
        ];

        return [
            'paper_cost' => $coverPaperCost + $innerPaperCost,
            'plates_cost' => $coverPlatesCost + $innerPlatesCost,
            'prepress_cost' => ($coverPlatesCost + $innerPlatesCost) + $prepressSetupCost + $cuttingSetupCost,
            'printing_cost' => $coverPrintingCost + $innerPrintingCost,
            'impressions' => $coverPrintedSheets + $innerPrintedSheets,
            'trays' => $coverBillableTrayRuns + $innerBillableTrayRuns,
            'sheet_cost' => $coverSheetCost + $innerSheetCost,
            'sheets_required' => $coverSheetsRequired + $innerSheetsRequired,
            'sheets_with_waste' => $coverSheetsWithWaste + $innerSheetsWithWaste,
            'sheet_yield' => max(1, $innerYield),
            'total_color_sets' => ($coverColorSetsPerFace * (int)($coverPrintModeMeta['plate_sets'] ?? 1)) + ($innerColorSetsPerFace * (int)($innerPrintModeMeta['plate_sets'] ?? 2)),
            'plate_multiplier' => $coverPlateMultiplier + $innerPlateMultiplier,
            'cover_breakdown' => $coverBreakdown,
            'inner_breakdown' => $innerBreakdown,
            'cover_sheet_cost' => $coverSheetCost,
            'cover_sheets_required' => $coverSheetsRequired,
            'cover_sheets_with_waste' => $coverSheetsWithWaste,
            'inner_sheet_cost' => $innerSheetCost,
            'inner_sheets_required' => $innerSheetsRequired,
            'inner_sheets_with_waste' => $innerSheetsWithWaste,
            'cover_printing_cost' => $coverPrintingCost,
            'inner_printing_cost' => $innerPrintingCost,
            'cover_pantone_printing_cost' => $coverPantonePrintingCost,
            'inner_pantone_printing_cost' => $innerPantonePrintingCost,
            'cover_computed_trays' => $coverComputedTrays,
            'inner_computed_trays' => $innerComputedTrays,
            'cover_billable_tray_runs' => $coverBillableTrayRuns,
            'inner_billable_tray_runs' => $innerBillableTrayRuns,
            'cover_min_trays' => $coverMinTrays,
            'inner_min_trays' => $innerMinTrays,
        ];
    }
}

if (!function_exists('pricing_compute_finishing_stage')) {
    function pricing_compute_finishing_stage(array $input): array
    {
        $isEnglish = (bool)($input['is_english'] ?? false);
        $bookMode = !empty($input['book_mode']);
        $finishOps = is_array($input['finish_ops'] ?? null) ? $input['finish_ops'] : [];
        $post = is_array($input['post'] ?? null) ? $input['post'] : [];

        $machineSheetFactor = pricing_float($input['machine_sheet_factor'] ?? 1);
        $coverMachineSheetFactor = pricing_float($input['cover_machine_sheet_factor'] ?? 1);
        $innerMachineSheetFactor = pricing_float($input['inner_machine_sheet_factor'] ?? 1);
        $sheetsWithWaste = pricing_int($input['sheets_with_waste'] ?? 0);
        $coverSheetsWithWaste = pricing_int($input['cover_sheets_with_waste'] ?? 0);
        $innerSheetsWithWaste = pricing_int($input['inner_sheets_with_waste'] ?? 0);

        $qty = pricing_float($input['qty'] ?? 0);
        $signaturesCount = pricing_int($input['signatures_count'] ?? 1);
        $bookGatherCostPerSignature = pricing_float($input['book_gather_cost_per_signature'] ?? 0);
        $bookBindingCostPerCopy = pricing_float($input['book_binding_cost_per_copy'] ?? 0);

        $coverBreakdown = is_array($input['cover_breakdown'] ?? null) ? $input['cover_breakdown'] : pricing_empty_breakdown();
        $innerBreakdown = is_array($input['inner_breakdown'] ?? null) ? $input['inner_breakdown'] : pricing_empty_breakdown();

        $finishingCost = 0.0;
        $finishingRows = [];

        foreach ($finishOps as $op) {
            $opKey = (string)($op['key'] ?? '');
            $finishGroup = $bookMode ? 'book_cover_finish' : 'finish';
            if ($opKey === '' || empty($post[$finishGroup][$opKey]['enabled'])) {
                continue;
            }
            $unit = pricing_normalize_finish_unit((string)($post[$finishGroup][$opKey]['unit'] ?? ($op['default_unit'] ?? 'piece')));
            $faces = pricing_int($post[$finishGroup][$opKey]['faces'] ?? 1);
            $faces = (!empty($op['allow_faces']) && $faces === 2) ? 2 : 1;
            $overridePrice = max(0, pricing_float($post[$finishGroup][$opKey]['price'] ?? 0));
            $basePrice = $unit === 'tray'
                ? pricing_float($op['price_tray'] ?? 0)
                : pricing_float($op['price_piece'] ?? 0);
            $price = $overridePrice > 0 ? $overridePrice : $basePrice;
            $sheetFactor = $bookMode ? $coverMachineSheetFactor : $machineSheetFactor;
            if (in_array($unit, ['tray', 'sheet'], true) && !empty($op['sheet_sensitive'])) {
                $price *= $sheetFactor;
            }
            $coverFinishingTrays = (int)ceil(max(0, $coverSheetsWithWaste) / 1000);
            $regularFinishingTrays = (int)ceil(max(0, $sheetsWithWaste) / 1000);
            if ($unit === 'tray') {
                $multiplier = $bookMode ? max(1, $coverFinishingTrays) : max(1, $regularFinishingTrays);
            } elseif ($unit === 'sheet') {
                $multiplier = $bookMode ? max(1, $coverSheetsWithWaste) : max(1, $sheetsWithWaste);
            } else {
                $multiplier = max(1, $qty);
            }
            $coverFinishCost = $price * $multiplier * $faces;
            $finishingCost += $coverFinishCost;
            $finishLabel = $isEnglish ? (string)($op['label_en'] ?? $opKey) : (string)($op['label_ar'] ?? $opKey);
            $finishingRows[] = [
                'label' => $bookMode
                    ? pricing_text($isEnglish, 'تشطيب الغلاف - ', 'Cover Finishing - ') . $finishLabel
                    : $finishLabel,
                'value' => $coverFinishCost,
            ];
            if ($bookMode) {
                $coverBreakdown['finishing_cost'] += $coverFinishCost;
            }
        }

        if ($bookMode) {
            foreach ($finishOps as $op) {
                $opKey = (string)($op['key'] ?? '');
                if ($opKey === '' || empty($post['book_inner_finish'][$opKey]['enabled'])) {
                    continue;
                }
                $unit = pricing_normalize_finish_unit((string)($post['book_inner_finish'][$opKey]['unit'] ?? ($op['default_unit'] ?? 'piece')));
                $faces = pricing_int($post['book_inner_finish'][$opKey]['faces'] ?? 1);
                $faces = (!empty($op['allow_faces']) && $faces === 2) ? 2 : 1;
                $overridePrice = max(0, pricing_float($post['book_inner_finish'][$opKey]['price'] ?? 0));
                $basePrice = $unit === 'tray'
                    ? pricing_float($op['price_tray'] ?? 0)
                    : pricing_float($op['price_piece'] ?? 0);
                $price = $overridePrice > 0 ? $overridePrice : $basePrice;
                if (in_array($unit, ['tray', 'sheet'], true) && !empty($op['sheet_sensitive'])) {
                    $price *= $innerMachineSheetFactor;
                }
                $innerFinishingTrays = (int)ceil(max(0, $innerSheetsWithWaste) / 1000);
                if ($unit === 'tray') {
                    $multiplier = max(1, $innerFinishingTrays);
                } elseif ($unit === 'sheet') {
                    $multiplier = max(1, $innerSheetsWithWaste);
                } else {
                    $multiplier = max(1, $qty);
                }
                $innerFinishCost = $price * $multiplier * $faces;
                $finishingCost += $innerFinishCost;
                $finishLabel = $isEnglish ? (string)($op['label_en'] ?? $opKey) : (string)($op['label_ar'] ?? $opKey);
                $finishingRows[] = [
                    'label' => pricing_text($isEnglish, 'تشطيب الداخلي - ', 'Inner Finishing - ') . $finishLabel,
                    'value' => $innerFinishCost,
                ];
                $innerBreakdown['finishing_cost'] += $innerFinishCost;
            }
        }

        $customNames = $post['custom_op_name'] ?? [];
        $customCosts = $post['custom_op_cost'] ?? [];
        if (is_array($customNames)) {
            $customCount = count($customNames);
            for ($i = 0; $i < $customCount; $i++) {
                $customName = trim((string)($customNames[$i] ?? ''));
                $customCost = max(0, pricing_float($customCosts[$i] ?? 0));
                if ($customName !== '' && $customCost > 0) {
                    $finishingCost += $customCost;
                    $finishingRows[] = [
                        'label' => pricing_text($isEnglish, 'عملية إضافية - ', 'Additional Operation - ') . $customName,
                        'value' => $customCost,
                    ];
                }
            }
        }

        $bookGatherCost = 0.0;
        $bookBindCost = 0.0;
        if ($bookMode) {
            $bookGatherCost = $qty * $signaturesCount * $bookGatherCostPerSignature;
            $bookBindCost = $qty * $bookBindingCostPerCopy;
            $finishingCost += $bookGatherCost + $bookBindCost;
            if ($bookGatherCost > 0) {
                $finishingRows[] = [
                    'label' => pricing_text($isEnglish, 'تجميع الملازم', 'Gathering Signatures'),
                    'value' => $bookGatherCost,
                ];
            }
            if ($bookBindCost > 0) {
                $finishingRows[] = [
                    'label' => pricing_text($isEnglish, 'التقفيل', 'Binding'),
                    'value' => $bookBindCost,
                ];
            }
        }

        return [
            'finishing_cost' => $finishingCost,
            'finishing_rows' => $finishingRows,
            'cover_breakdown' => $coverBreakdown,
            'inner_breakdown' => $innerBreakdown,
            'book_gather_cost' => $bookGatherCost,
            'book_bind_cost' => $bookBindCost,
        ];
    }
}

if (!function_exists('pricing_build_stage_rows')) {
    function pricing_build_stage_rows(bool $isEnglish, array $input): array
    {
        $bookMode = !empty($input['book_mode']);
        $coverBreakdown = is_array($input['cover_breakdown'] ?? null) ? $input['cover_breakdown'] : pricing_empty_breakdown();
        $innerBreakdown = is_array($input['inner_breakdown'] ?? null) ? $input['inner_breakdown'] : pricing_empty_breakdown();

        return [
            ['label' => pricing_text($isEnglish, 'التصميم والمعاينة', 'Design & Proofing'), 'value' => pricing_float($input['design_cost'] ?? 0)],
            ['label' => pricing_text($isEnglish, 'الغلاف', 'Cover'), 'value' => $bookMode ? (pricing_float($coverBreakdown['paper_cost'] ?? 0) + pricing_float($coverBreakdown['prepress_cost'] ?? 0) + pricing_float($coverBreakdown['printing_cost'] ?? 0) + pricing_float($coverBreakdown['finishing_cost'] ?? 0)) : 0],
            ['label' => pricing_text($isEnglish, 'الداخلي', 'Inner Content'), 'value' => $bookMode ? (pricing_float($innerBreakdown['paper_cost'] ?? 0) + pricing_float($innerBreakdown['prepress_cost'] ?? 0) + pricing_float($innerBreakdown['printing_cost'] ?? 0) + pricing_float($innerBreakdown['finishing_cost'] ?? 0)) : 0],
            ['label' => pricing_text($isEnglish, 'الورق والخامات', 'Paper & Material'), 'value' => pricing_float($input['paper_cost'] ?? 0)],
            ['label' => pricing_text($isEnglish, 'التجهيز قبل الطباعة', 'Prepress Setup'), 'value' => pricing_float($input['prepress_cost'] ?? 0)],
            ['label' => pricing_text($isEnglish, 'الطباعة', 'Printing'), 'value' => pricing_float($input['printing_cost'] ?? 0)],
            ['label' => pricing_text($isEnglish, 'التشطيب', 'Finishing'), 'value' => pricing_float($input['finishing_cost'] ?? 0)],
            ['label' => pricing_text($isEnglish, 'التغليف والتحميل', 'Packing & Handling'), 'value' => pricing_float($input['packaging_cost'] ?? 0) + pricing_float($input['loading_cost'] ?? 0)],
            ['label' => pricing_text($isEnglish, 'اختبار اللون', 'Color Test'), 'value' => pricing_float($input['color_test_cost'] ?? 0)],
            ['label' => pricing_text($isEnglish, 'نقل داخلي', 'Internal Transport'), 'value' => pricing_float($input['internal_transport_cost'] ?? 0)],
            ['label' => pricing_text($isEnglish, 'النقل والتسليم', 'Delivery'), 'value' => pricing_float($input['delivery_cost'] ?? 0)],
            ['label' => pricing_text($isEnglish, 'نثريات عامة', 'Miscellaneous'), 'value' => pricing_float($input['misc_cost'] ?? 0)],
            ['label' => pricing_text($isEnglish, 'مصاريف تجهيز ثابتة', 'Fixed Setup Fee'), 'value' => pricing_float($input['setup_fee'] ?? 0)],
            ['label' => pricing_text($isEnglish, 'هامش مخاطر التشغيل', 'Operational Risk Margin'), 'value' => pricing_float($input['risk_cost'] ?? 0)],
        ];
    }
}

if (!function_exists('pricing_validate_inputs')) {
    function pricing_validate_inputs(bool $isEnglish, array $input): string
    {
        $clientId = pricing_int($input['client_id'] ?? 0);
        $qty = pricing_float($input['qty'] ?? 0);
        $bookMode = !empty($input['book_mode']);

        if ($clientId <= 0 || $qty <= 0) {
            return pricing_text($isEnglish, 'اختر العميل ونوع الورق والماكينة وأدخل الكمية.', 'Select client, paper type, machine, and quantity.');
        }

        if (!$bookMode && (empty($input['paper_row']) || empty($input['machine_row']))) {
            return pricing_text($isEnglish, 'اختر نوع الورق والماكينة للعملية العادية.', 'Select paper type and machine for the regular job.');
        }
        if (!$bookMode && pricing_float($input['paper_ton_price'] ?? 0) <= 0) {
            return pricing_text($isEnglish, 'نوع الورق المختار لا يحتوي على سعر طن صالح داخل الإعدادات.', 'Selected paper type has no valid ton price in settings.');
        }
        if (
            !$bookMode
            && (
                pricing_float($input['sheet_width_cm'] ?? 0) <= 0
                || pricing_float($input['sheet_height_cm'] ?? 0) <= 0
                || pricing_float($input['sheet_gsm'] ?? 0) <= 0
            )
        ) {
            return pricing_text($isEnglish, 'أدخل مقاس الفرخ والجراماج ليتم حساب تكلفة الورق.', 'Enter sheet size and GSM to calculate paper cost.');
        }
        if (!$bookMode && pricing_int($input['plate_colors_per_face'] ?? 0) <= 0) {
            return pricing_text($isEnglish, 'أدخل عدد ألوان الطباعة أو البانتون على الأقل.', 'Enter process colors or pantone count.');
        }
        if (!$bookMode && pricing_float(($input['machine_row']['price_per_tray'] ?? 0)) <= 1) {
            return pricing_text($isEnglish, 'الماكينة المختارة لا تحتوي على سعر تراج صالح داخل البيانات الأولية.', 'Selected machine has no valid tray price in master data.');
        }
        if ($bookMode && (empty($input['cover_paper_row']) || empty($input['cover_machine_row']) || empty($input['inner_paper_row']) || empty($input['inner_machine_row']))) {
            return pricing_text($isEnglish, 'في وضع الكتب/المجلات يجب تحديد ورق وماكينة للغلاف والداخلي.', 'In books/magazines mode, you must select paper and machine for both cover and inner sections.');
        }
        if ($bookMode && (pricing_float(($input['cover_machine_row']['price_per_tray'] ?? 0)) <= 1 || pricing_float(($input['inner_machine_row']['price_per_tray'] ?? 0)) <= 1)) {
            return pricing_text($isEnglish, 'إحدى ماكينات الغلاف أو الداخلي لا تحتوي على سعر تراج صالح داخل البيانات الأولية.', 'One of the cover/inner machines has no valid tray price in master data.');
        }
        if ($bookMode && (pricing_float($input['cover_paper_ton_price'] ?? 0) <= 0 || pricing_float($input['inner_paper_ton_price'] ?? 0) <= 0)) {
            return pricing_text($isEnglish, 'أحد أنواع الورق المختارة للغلاف أو الداخلي لا يحتوي على سعر طن صالح.', 'One of the selected cover/inner paper types has no valid ton price.');
        }
        if (
            $bookMode
            && (
                pricing_float($input['cover_width_cm'] ?? 0) <= 0
                || pricing_float($input['cover_height_cm'] ?? 0) <= 0
                || pricing_float($input['cover_gsm'] ?? 0) <= 0
                || pricing_float($input['inner_width_cm'] ?? 0) <= 0
                || pricing_float($input['inner_height_cm'] ?? 0) <= 0
                || pricing_float($input['inner_gsm'] ?? 0) <= 0
            )
        ) {
            return pricing_text($isEnglish, 'أدخل مقاسات وجراماج الغلاف والداخلي لحساب الكتب/المجلات.', 'Enter cover and inner sheet sizes and GSM for books/magazines costing.');
        }
        if ($bookMode && (pricing_int($input['cover_color_sets_per_face'] ?? 0) <= 0 || pricing_int($input['inner_color_sets_per_face'] ?? 0) <= 0)) {
            return pricing_text($isEnglish, 'أدخل عدد الألوان للغلاف والداخلي.', 'Enter color counts for both cover and inner sections.');
        }

        return '';
    }
}

if (!function_exists('pricing_build_printing_rows')) {
    function pricing_build_printing_rows(bool $isEnglish, bool $bookMode, array $input): array
    {
        $rows = [];
        if ($bookMode) {
            $rows[] = [
                'label' => pricing_text($isEnglish, 'طباعة الغلاف', 'Cover Printing'),
                'value' => pricing_float($input['cover_printing_cost'] ?? 0),
                'meta' => pricing_text($isEnglish, 'نوع الطباعة', 'Print Mode') . ': ' . (string)($input['cover_print_mode_label'] ?? '')
                    . ' | ' . pricing_text($isEnglish, 'سعر التراج', 'Tray Price') . ': ' . pricing_currency(pricing_float($input['cover_machine_price_per_tray'] ?? 0))
                    . ' | ' . pricing_text($isEnglish, 'المحسوب', 'Computed') . ': ' . pricing_int($input['cover_computed_trays'] ?? 0)
                    . ' | ' . pricing_text($isEnglish, 'الحد الأدنى', 'Minimum') . ': ' . pricing_int($input['cover_min_trays'] ?? 1)
                    . ' | ' . pricing_text($isEnglish, 'المطبق', 'Applied') . ': ' . pricing_int($input['cover_billable_tray_runs'] ?? 0)
                    . ' | ' . pricing_text($isEnglish, 'ألوان البروسس', 'Process Colors') . ': ' . pricing_int($input['cover_process_colors_per_face'] ?? 0)
                    . ' | ' . pricing_text($isEnglish, 'ألوان البانتون', 'Pantone Colors') . ': ' . pricing_int($input['cover_pantone'] ?? 0)
                    . ' | ' . pricing_text($isEnglish, 'مجموعات الزنكات', 'Plate Sets') . ': ' . pricing_int($input['cover_plate_multiplier'] ?? 0),
            ];
            if (pricing_float($input['cover_pantone_printing_cost'] ?? 0) > 0) {
                $rows[] = [
                    'label' => pricing_text($isEnglish, 'بانتون الغلاف', 'Cover Pantone'),
                    'value' => pricing_float($input['cover_pantone_printing_cost'] ?? 0),
                    'meta' => pricing_text($isEnglish, 'سعر اللون/التراج', 'Color/Tray Price') . ': ' . pricing_currency(pricing_float($input['cover_pantone_tray_price'] ?? 0))
                        . ' | ' . pricing_text($isEnglish, 'عدد الألوان', 'Colors Count') . ': ' . pricing_int($input['cover_pantone'] ?? 0)
                        . ' | ' . pricing_text($isEnglish, 'التراجات المطبقة', 'Applied Tray Runs') . ': ' . pricing_int($input['cover_billable_tray_runs'] ?? 0),
                ];
            }
            $rows[] = [
                'label' => pricing_text($isEnglish, 'طباعة الداخلي', 'Inner Printing'),
                'value' => pricing_float($input['inner_printing_cost'] ?? 0),
                'meta' => pricing_text($isEnglish, 'نوع الطباعة', 'Print Mode') . ': ' . (string)($input['inner_print_mode_label'] ?? '')
                    . ' | ' . pricing_text($isEnglish, 'سعر التراج', 'Tray Price') . ': ' . pricing_currency(pricing_float($input['inner_machine_price_per_tray'] ?? 0))
                    . ' | ' . pricing_text($isEnglish, 'المحسوب', 'Computed') . ': ' . pricing_int($input['inner_computed_trays'] ?? 0)
                    . ' | ' . pricing_text($isEnglish, 'الحد الأدنى', 'Minimum') . ': ' . pricing_int($input['inner_min_trays'] ?? 1)
                    . ' | ' . pricing_text($isEnglish, 'المطبق', 'Applied') . ': ' . pricing_int($input['inner_billable_tray_runs'] ?? 0)
                    . ' | ' . pricing_text($isEnglish, 'ألوان البروسس', 'Process Colors') . ': ' . pricing_int($input['inner_process_colors_per_face'] ?? 0)
                    . ' | ' . pricing_text($isEnglish, 'ألوان البانتون', 'Pantone Colors') . ': ' . pricing_int($input['inner_pantone'] ?? 0)
                    . ' | ' . pricing_text($isEnglish, 'مجموعات الزنكات', 'Plate Sets') . ': ' . pricing_int($input['inner_plate_multiplier'] ?? 0),
            ];
            if (pricing_float($input['inner_pantone_printing_cost'] ?? 0) > 0) {
                $rows[] = [
                    'label' => pricing_text($isEnglish, 'بانتون الداخلي', 'Inner Pantone'),
                    'value' => pricing_float($input['inner_pantone_printing_cost'] ?? 0),
                    'meta' => pricing_text($isEnglish, 'سعر اللون/التراج', 'Color/Tray Price') . ': ' . pricing_currency(pricing_float($input['inner_pantone_tray_price'] ?? 0))
                        . ' | ' . pricing_text($isEnglish, 'عدد الألوان', 'Colors Count') . ': ' . pricing_int($input['inner_pantone'] ?? 0)
                        . ' | ' . pricing_text($isEnglish, 'التراجات المطبقة', 'Applied Tray Runs') . ': ' . pricing_int($input['inner_billable_tray_runs'] ?? 0),
                ];
            }
            return $rows;
        }

        $rows[] = [
            'label' => pricing_text($isEnglish, 'الطباعة', 'Printing'),
            'value' => pricing_float($input['printing_cost'] ?? 0),
            'meta' => pricing_text($isEnglish, 'نوع الطباعة', 'Print Mode') . ': ' . (string)($input['print_mode_label'] ?? '')
                . ' | ' . pricing_text($isEnglish, 'سعر التراج', 'Tray Price') . ': ' . pricing_currency(pricing_float($input['price_per_tray'] ?? 0))
                . ' | ' . pricing_text($isEnglish, 'المحسوب', 'Computed') . ': ' . pricing_int($input['computed_trays'] ?? 0)
                . ' | ' . pricing_text($isEnglish, 'الحد الأدنى', 'Minimum') . ': ' . pricing_int($input['min_trays'] ?? 1)
                . ' | ' . pricing_text($isEnglish, 'المطبق', 'Applied') . ': ' . pricing_int($input['trays'] ?? 0)
                . ' | ' . pricing_text($isEnglish, 'ألوان البروسس', 'Process Colors') . ': ' . pricing_int($input['process_colors_per_face'] ?? 0)
                . ' | ' . pricing_text($isEnglish, 'ألوان البانتون', 'Pantone Colors') . ': ' . pricing_int($input['pantone'] ?? 0)
                . ' | ' . pricing_text($isEnglish, 'مجموعات الزنكات', 'Plate Sets') . ': ' . pricing_int($input['plate_multiplier'] ?? 0),
        ];
        if (pricing_float($input['pantone_printing_cost'] ?? 0) > 0) {
            $rows[] = [
                'label' => pricing_text($isEnglish, 'تكلفة البانتون', 'Pantone Printing'),
                'value' => pricing_float($input['pantone_printing_cost'] ?? 0),
                'meta' => pricing_text($isEnglish, 'سعر اللون/التراج', 'Color/Tray Price') . ': ' . pricing_currency(pricing_float($input['pantone_tray_price'] ?? 0))
                    . ' | ' . pricing_text($isEnglish, 'عدد الألوان', 'Colors Count') . ': ' . pricing_int($input['pantone'] ?? 0)
                    . ' | ' . pricing_text($isEnglish, 'التراجات المطبقة', 'Applied Tray Runs') . ': ' . pricing_int($input['trays'] ?? 0),
            ];
        }
        return $rows;
    }
}

if (!function_exists('pricing_build_calc_result')) {
    function pricing_build_calc_result(bool $isEnglish, array $input): array
    {
        $bookMode = !empty($input['book_mode']);
        $machineRow = is_array($input['machine_row'] ?? null) ? $input['machine_row'] : [];
        $machineKey = (string)($input['machine_key'] ?? '');
        $machineLabel = $bookMode
            ? pricing_text($isEnglish, 'غلاف + داخلي', 'Cover + Inner')
            : ($isEnglish
                ? (string)($machineRow['label_en'] ?? $machineKey)
                : (string)($machineRow['label_ar'] ?? $machineKey));

        $unitLabel = trim((string)($input['unit_label'] ?? ''));

        return [
            'ok' => true,
            'error' => '',
            'quote_error' => '',
            'paper_cost' => pricing_float($input['paper_cost'] ?? 0),
            'design_cost' => pricing_float($input['design_cost'] ?? 0),
            'prepress_cost' => pricing_float($input['prepress_cost'] ?? 0),
            'printing_cost' => pricing_float($input['printing_cost'] ?? 0),
            'pantone_printing_cost' => pricing_float($input['pantone_printing_cost'] ?? 0),
            'plates_cost' => pricing_float($input['plates_cost'] ?? 0),
            'finishing_cost' => pricing_float($input['finishing_cost'] ?? 0),
            'packaging_cost' => pricing_float($input['packaging_cost'] ?? 0) + pricing_float($input['loading_cost'] ?? 0),
            'delivery_cost' => pricing_float($input['delivery_cost'] ?? 0),
            'color_test_cost' => pricing_float($input['color_test_cost'] ?? 0),
            'internal_transport_cost' => pricing_float($input['internal_transport_cost'] ?? 0),
            'risk_cost' => pricing_float($input['risk_cost'] ?? 0),
            'misc_cost' => pricing_float($input['misc_cost'] ?? 0),
            'setup_fee' => pricing_float($input['setup_fee'] ?? 0),
            'profit_cost' => pricing_float($input['profit_cost'] ?? 0),
            'subtotal' => pricing_float($input['subtotal'] ?? 0),
            'total' => pricing_float($input['total'] ?? 0),
            'qty' => pricing_float($input['qty'] ?? 0),
            'unit_label' => $unitLabel !== '' ? $unitLabel : pricing_text($isEnglish, 'قطعة', 'piece'),
            'sheet_width_cm' => pricing_float($input['sheet_width_cm'] ?? 0),
            'sheet_height_cm' => pricing_float($input['sheet_height_cm'] ?? 0),
            'sheet_gsm' => pricing_float($input['sheet_gsm'] ?? 0),
            'sheet_cost' => pricing_float($input['sheet_cost'] ?? 0),
            'sheet_yield' => pricing_int($input['sheet_yield'] ?? 0),
            'machine_sheet_divisor' => pricing_int($input['machine_sheet_divisor'] ?? 1),
            'machine_sheets_required' => pricing_int($input['machine_sheets_required'] ?? 0),
            'waste_machine_sheets' => pricing_int($input['waste_machine_sheets'] ?? 0),
            'total_machine_sheets' => pricing_int($input['impressions'] ?? 0),
            'base_units' => pricing_float($input['base_units'] ?? 0),
            'sheets_required' => pricing_int($input['sheets_required'] ?? 0),
            'sheets_with_waste' => pricing_int($input['sheets_with_waste'] ?? 0),
            'impressions' => pricing_int($input['impressions'] ?? 0),
            'trays' => pricing_int($input['trays'] ?? 0),
            'print_faces' => pricing_int($input['print_faces'] ?? 1),
            'print_mode' => (string)($input['print_mode'] ?? 'single'),
            'print_mode_label' => (string)($input['print_mode_label'] ?? ''),
            'total_color_sets' => pricing_int($input['total_color_sets'] ?? 0),
            'plates_count' => pricing_int($input['plate_multiplier'] ?? 0),
            'book_bind_cost' => pricing_float($input['book_bind_cost'] ?? 0),
            'book_gather_cost' => pricing_float($input['book_gather_cost'] ?? 0),
            'job_title' => trim((string)($input['operation_name'] ?? '')) !== '' ? (string)$input['operation_name'] : pricing_text($isEnglish, 'عملية طباعة', 'Print Job'),
            'job_specs' => (string)($input['specs'] ?? ''),
            'paper_name' => (string)($input['paper_name'] ?? ''),
            'paper_ton_price' => pricing_float($input['paper_ton_price'] ?? 0),
            'machine_name' => $machineLabel,
            'cover_breakdown' => is_array($input['cover_breakdown'] ?? null) ? $input['cover_breakdown'] : pricing_empty_breakdown(),
            'cover_pantone_printing_cost' => pricing_float($input['cover_pantone_printing_cost'] ?? 0),
            'inner_breakdown' => is_array($input['inner_breakdown'] ?? null) ? $input['inner_breakdown'] : pricing_empty_breakdown(),
            'inner_pantone_printing_cost' => pricing_float($input['inner_pantone_printing_cost'] ?? 0),
            'binding_type' => (string)($input['binding_type'] ?? ''),
            'finishing_rows' => is_array($input['finishing_rows'] ?? null) ? $input['finishing_rows'] : [],
            'printing_rows' => is_array($input['printing_rows'] ?? null) ? $input['printing_rows'] : [],
            'cover_paper_name' => (string)($input['cover_paper_name'] ?? ''),
            'cover_paper_ton_price' => pricing_float($input['cover_paper_ton_price'] ?? 0),
            'cover_sheet_width_cm' => pricing_float($input['cover_sheet_width_cm'] ?? 0),
            'cover_sheet_height_cm' => pricing_float($input['cover_sheet_height_cm'] ?? 0),
            'cover_sheet_gsm' => pricing_float($input['cover_sheet_gsm'] ?? 0),
            'cover_sheet_cost' => pricing_float($input['cover_sheet_cost'] ?? 0),
            'cover_sheets_required' => pricing_int($input['cover_sheets_required'] ?? 0),
            'cover_sheets_with_waste' => pricing_int($input['cover_sheets_with_waste'] ?? 0),
            'inner_paper_name' => (string)($input['inner_paper_name'] ?? ''),
            'inner_paper_ton_price' => pricing_float($input['inner_paper_ton_price'] ?? 0),
            'inner_sheet_width_cm' => pricing_float($input['inner_sheet_width_cm'] ?? 0),
            'inner_sheet_height_cm' => pricing_float($input['inner_sheet_height_cm'] ?? 0),
            'inner_sheet_gsm' => pricing_float($input['inner_sheet_gsm'] ?? 0),
            'inner_sheet_cost' => pricing_float($input['inner_sheet_cost'] ?? 0),
            'inner_sheets_required' => pricing_int($input['inner_sheets_required'] ?? 0),
            'inner_sheets_with_waste' => pricing_int($input['inner_sheets_with_waste'] ?? 0),
            'stage_rows' => is_array($input['stage_rows'] ?? null) ? $input['stage_rows'] : [],
        ];
    }
}

if (!function_exists('pricing_build_quote_notes')) {
    function pricing_build_quote_notes(bool $isEnglish, array $calc, bool $bookMode, string $quoteNotes = ''): string
    {
        $quoteLines = [];
        $quoteLines[] = pricing_text($isEnglish, 'ملخص التسعير المرحلي', 'Stage Pricing Summary');
        $quoteLines[] = str_repeat('-', 28);
        $quoteLines[] = pricing_text($isEnglish, 'نوع الورق', 'Paper Type') . ': ' . (string)($calc['paper_name'] ?? '');
        $quoteLines[] = pricing_text($isEnglish, 'المقاس', 'Sheet Size') . ': ' . pricing_currency((float)($calc['sheet_width_cm'] ?? 0)) . ' × ' . pricing_currency((float)($calc['sheet_height_cm'] ?? 0)) . ' سم';
        $quoteLines[] = pricing_text($isEnglish, 'الجراماج', 'GSM') . ': ' . pricing_currency((float)($calc['sheet_gsm'] ?? 0));
        $quoteLines[] = pricing_text($isEnglish, 'الماكينة', 'Machine') . ': ' . (string)($calc['machine_name'] ?? '');
        $quoteLines[] = pricing_text($isEnglish, 'إجمالي سحبات الماكينة/التراجات', 'Total Machine Pulls/Trays') . ': ' . (int)($calc['impressions'] ?? 0) . ' / ' . (int)($calc['trays'] ?? 0);
        if ($bookMode) {
            $quoteLines[] = pricing_text($isEnglish, 'ورق الغلاف', 'Cover Paper') . ': ' . (string)($calc['cover_paper_name'] ?? '');
            $quoteLines[] = pricing_text($isEnglish, 'ورق الداخلي', 'Inner Paper') . ': ' . (string)($calc['inner_paper_name'] ?? '');
            $quoteLines[] = pricing_text($isEnglish, 'نوع التقفيل', 'Binding Type') . ': ' . pricing_binding_label($isEnglish, (string)($calc['binding_type'] ?? ''));
        }
        foreach ((array)($calc['printing_rows'] ?? []) as $printingRow) {
            $quoteLines[] = (string)($printingRow['label'] ?? '') . ': ' . pricing_currency((float)($printingRow['value'] ?? 0));
            if (!empty($printingRow['meta'])) {
                $quoteLines[] = '  - ' . (string)$printingRow['meta'];
            }
        }
        foreach ((array)($calc['stage_rows'] ?? []) as $stageRow) {
            $quoteLines[] = (string)($stageRow['label'] ?? '') . ': ' . pricing_currency((float)($stageRow['value'] ?? 0));
        }
        $quoteLines[] = pricing_text($isEnglish, 'الربح', 'Profit') . ': ' . pricing_currency((float)($calc['profit_cost'] ?? 0));
        $quoteLines[] = pricing_text($isEnglish, 'الإجمالي', 'Total') . ': ' . pricing_currency((float)($calc['total'] ?? 0));
        $quoteNotes = trim($quoteNotes);
        if ($quoteNotes !== '') {
            $quoteLines[] = '';
            $quoteLines[] = pricing_text($isEnglish, 'ملاحظات العرض', 'Quote Notes') . ':';
            $quoteLines[] = $quoteNotes;
        }
        return implode("\n", $quoteLines);
    }
}

if (!function_exists('pricing_build_job_details')) {
    function pricing_build_job_details(bool $isEnglish, array $calc, bool $bookMode, string $specs = ''): string
    {
        $jobDetailsRows = [
            pricing_text($isEnglish, 'نوع الورق', 'Paper Type') . ': ' . (string)($calc['paper_name'] ?? ''),
            pricing_text($isEnglish, 'المقاس', 'Sheet Size') . ': ' . pricing_currency((float)($calc['sheet_width_cm'] ?? 0)) . ' x ' . pricing_currency((float)($calc['sheet_height_cm'] ?? 0)) . ' cm',
            pricing_text($isEnglish, 'الجراماج', 'GSM') . ': ' . pricing_currency((float)($calc['sheet_gsm'] ?? 0)),
            pricing_text($isEnglish, 'الماكينة', 'Machine') . ': ' . (string)($calc['machine_name'] ?? ''),
            pricing_text($isEnglish, 'نوع الطباعة', 'Print Mode') . ': ' . (string)($calc['print_mode_label'] ?? ''),
            pricing_text($isEnglish, 'الكمية', 'Quantity') . ': ' . pricing_currency((float)($calc['qty'] ?? 0)) . ' ' . (string)($calc['unit_label'] ?? ''),
            pricing_text($isEnglish, 'سحبات الماكينة المطلوبة', 'Required Machine Pulls') . ': ' . (int)($calc['machine_sheets_required'] ?? 0),
            pricing_text($isEnglish, 'هالك السحبات', 'Waste Pulls') . ': ' . (int)($calc['waste_machine_sheets'] ?? 0),
            pricing_text($isEnglish, 'الأفراخ الكاملة بعد الهالك', 'Full Sheets after waste') . ': ' . (int)($calc['sheets_with_waste'] ?? 0),
            pricing_text($isEnglish, 'إجمالي سحبات الماكينة', 'Total Machine Pulls') . ': ' . (int)($calc['impressions'] ?? 0),
            pricing_text($isEnglish, 'عدد التراجات', 'Trays') . ': ' . (int)($calc['trays'] ?? 0),
            pricing_text($isEnglish, 'عدد الزنكات', 'Plates Count') . ': ' . (int)($calc['plates_count'] ?? 0),
            pricing_text($isEnglish, 'تكلفة التصميم', 'Design Cost') . ': ' . pricing_currency((float)($calc['design_cost'] ?? 0)),
            pricing_text($isEnglish, 'تكلفة الورق', 'Paper Cost') . ': ' . pricing_currency((float)($calc['paper_cost'] ?? 0)),
            pricing_text($isEnglish, 'تكلفة التجهيز', 'Prepress Cost') . ': ' . pricing_currency((float)($calc['prepress_cost'] ?? 0)),
            pricing_text($isEnglish, 'تكلفة الطباعة', 'Printing Cost') . ': ' . pricing_currency((float)($calc['printing_cost'] ?? 0)),
            pricing_text($isEnglish, 'تكلفة التشطيب', 'Finishing Cost') . ': ' . pricing_currency((float)($calc['finishing_cost'] ?? 0)),
            pricing_text($isEnglish, 'تكلفة التغليف والتحميل', 'Packing & Handling Cost') . ': ' . pricing_currency((float)($calc['packaging_cost'] ?? 0)),
            pricing_text($isEnglish, 'تكلفة النقل', 'Delivery Cost') . ': ' . pricing_currency((float)($calc['delivery_cost'] ?? 0)),
            pricing_text($isEnglish, 'نثريات', 'Misc Cost') . ': ' . pricing_currency((float)($calc['misc_cost'] ?? 0)),
            pricing_text($isEnglish, 'مصاريف تجهيز ثابتة', 'Setup Fee') . ': ' . pricing_currency((float)($calc['setup_fee'] ?? 0)),
            pricing_text($isEnglish, 'الربح', 'Profit') . ': ' . pricing_currency((float)($calc['profit_cost'] ?? 0)),
            pricing_text($isEnglish, 'الإجمالي', 'Total') . ': ' . pricing_currency((float)($calc['total'] ?? 0)),
        ];
        if ($bookMode) {
            $jobDetailsRows[] = pricing_text($isEnglish, 'ورق الغلاف', 'Cover Paper') . ': ' . (string)($calc['cover_paper_name'] ?? '');
            $jobDetailsRows[] = pricing_text($isEnglish, 'ورق الداخلي', 'Inner Paper') . ': ' . (string)($calc['inner_paper_name'] ?? '');
            $jobDetailsRows[] = pricing_text($isEnglish, 'عدد الملازم', 'Signatures Count') . ': ' . (int)($calc['signatures_count'] ?? 0);
            $jobDetailsRows[] = pricing_text($isEnglish, 'نوع التقفيل', 'Binding Type') . ': ' . pricing_binding_label($isEnglish, (string)($calc['binding_type'] ?? ''));
            $jobDetailsRows[] = pricing_text($isEnglish, 'زنكات الداخلي', 'Inner Plates') . ': ' . (int)(((array)($calc['inner_breakdown'] ?? []))['plates'] ?? 0);
            $jobDetailsRows[] = pricing_text($isEnglish, 'تجميع الملازم', 'Gathering Cost') . ': ' . pricing_currency((float)($calc['book_gather_cost'] ?? 0));
            $jobDetailsRows[] = pricing_text($isEnglish, 'تجليد/ربط', 'Binding Cost') . ': ' . pricing_currency((float)($calc['book_bind_cost'] ?? 0));
        }
        foreach ((array)($calc['printing_rows'] ?? []) as $printingRow) {
            $jobDetailsRows[] = (string)($printingRow['label'] ?? '') . ': ' . pricing_currency((float)($printingRow['value'] ?? 0));
            if (!empty($printingRow['meta'])) {
                $jobDetailsRows[] = '  - ' . (string)$printingRow['meta'];
            }
        }
        $specs = trim($specs);
        if ($specs !== '') {
            $jobDetailsRows[] = pricing_text($isEnglish, 'المواصفات', 'Specifications') . ': ' . $specs;
        }
        return implode("\n", $jobDetailsRows);
    }
}

if (!function_exists('pricing_save_record')) {
    function pricing_save_record(mysqli $conn, array $post, array $calc, int $clientId, int $creatorUserId, string $creatorName): int
    {
        $existingRecordId = max(0, (int)($post['source_record_id'] ?? 0));
        $snapshotPayload = json_encode([
            'form' => $post,
            'calc' => $calc,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $pricingModeValue = !empty($post['pricing_mode']) && (string)$post['pricing_mode'] === 'books' ? 'books' : 'general';
        $pricingNotes = trim((string)($post['notes'] ?? ''));
        $jobTitle = (string)($calc['job_title'] ?? '');
        $qty = (float)($calc['qty'] ?? 0);
        $unitLabel = (string)($calc['unit_label'] ?? '');
        $total = (float)($calc['total'] ?? 0);

        if ($existingRecordId > 0) {
            $stmtUpdate = $conn->prepare("
                UPDATE app_pricing_records
                SET client_id = ?, operation_name = ?, pricing_mode = ?, qty = ?, unit_label = ?, total_amount = ?, notes = ?, snapshot_json = ?, created_by_user_id = ?, created_by_name = ?
                WHERE id = ?
                LIMIT 1
            ");
            if (!$stmtUpdate) {
                throw new RuntimeException('prepare_update_failed');
            }
            $stmtUpdate->bind_param(
                'issdsdssisi',
                $clientId,
                $jobTitle,
                $pricingModeValue,
                $qty,
                $unitLabel,
                $total,
                $pricingNotes,
                $snapshotPayload,
                $creatorUserId,
                $creatorName,
                $existingRecordId
            );
            $stmtUpdate->execute();
            $affected = $stmtUpdate->affected_rows;
            $stmtUpdate->close();
            if ($affected >= 0) {
                return $existingRecordId;
            }
        }

        $stmtPricingSave = $conn->prepare("
            INSERT INTO app_pricing_records
            (client_id, operation_name, pricing_mode, qty, unit_label, total_amount, notes, snapshot_json, created_by_user_id, created_by_name)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        if (!$stmtPricingSave) {
            throw new RuntimeException('prepare_failed');
        }
        $stmtPricingSave->bind_param(
            'issdsdssis',
            $clientId,
            $jobTitle,
            $pricingModeValue,
            $qty,
            $unitLabel,
            $total,
            $pricingNotes,
            $snapshotPayload,
            $creatorUserId,
            $creatorName
        );
        $stmtPricingSave->execute();
        $pricingRecordId = (int)$stmtPricingSave->insert_id;
        $stmtPricingSave->close();

        if ($pricingRecordId > 0) {
            $pricingRef = 'PRC-' . str_pad((string)$pricingRecordId, 5, '0', STR_PAD_LEFT);
            $stmtPricingRef = $conn->prepare("UPDATE app_pricing_records SET pricing_ref = ? WHERE id = ?");
            if ($stmtPricingRef) {
                $stmtPricingRef->bind_param('si', $pricingRef, $pricingRecordId);
                $stmtPricingRef->execute();
                $stmtPricingRef->close();
            }
        }
        return $pricingRecordId;
    }
}

if (!function_exists('pricing_create_quote')) {
    function pricing_create_quote(mysqli $conn, bool $isEnglish, array $calc, int $clientId, bool $bookMode, string $quoteNotes = '', int $sourcePricingRecordId = 0, string $pricingSourceRef = '', string $quoteCurrencyCode = 'EGP'): string
    {
        $quoteDate = date('Y-m-d');
        $validUntil = date('Y-m-d', strtotime('+7 days'));
        $accessToken = bin2hex(random_bytes(32));
        $itemName = (string)($calc['job_title'] ?? '');
        $jobSpecs = trim((string)($calc['job_specs'] ?? ''));
        if ($jobSpecs !== '') {
            $itemName .= ' - ' . $jobSpecs;
        }
        $qty = max(0, (float)($calc['qty'] ?? 0));
        $total = (float)($calc['total'] ?? 0);
        $unitPrice = $qty > 0 ? ($total / $qty) : $total;
        $currencyCode = app_quote_normalize_currency_code($conn, $quoteCurrencyCode);
        $currencyMeta = app_quote_currency_meta($conn, $currencyCode);
        $baseCurrencyCode = (string)($currencyMeta['base_currency'] ?? app_quote_default_currency($conn));
        $exchangeRateToBase = max(0, (float)($currencyMeta['rate_to_base'] ?? 1));
        $quoteTotal = ($exchangeRateToBase > 0 && $currencyCode !== $baseCurrencyCode) ? round($total / $exchangeRateToBase, 2) : $total;
        $quoteUnitPrice = $qty > 0 ? ($quoteTotal / $qty) : $quoteTotal;
        $currencyLabel = (string)($currencyMeta['label_ar'] ?? $currencyCode);
        $quoteNotesFinal = pricing_build_quote_notes($isEnglish, $calc, $bookMode, $quoteNotes);
        if ($currencyCode !== $baseCurrencyCode && $exchangeRateToBase > 0) {
            $quoteNotesFinal .= "
" . pricing_text($isEnglish, 'عملة العرض', 'Quote currency') . ': ' . $currencyCode . ' | 1 ' . $currencyCode . ' = ' . number_format($exchangeRateToBase, 4) . ' ' . $baseCurrencyCode;
        }

        $conn->begin_transaction();
        try {
            $stmtQuote = $conn->prepare("INSERT INTO quotes (client_id, created_at, valid_until, total_amount, currency_code, currency_label, base_currency_code, exchange_rate_to_base, base_total_amount, status, notes, access_token, source_pricing_record_id, pricing_source_ref) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, ?, ?, ?)");
            if (!$stmtQuote) {
                throw new RuntimeException('prepare_quote_failed');
            }
            $stmtQuote->bind_param('issdsssddssis', $clientId, $quoteDate, $validUntil, $quoteTotal, $currencyCode, $currencyLabel, $baseCurrencyCode, $exchangeRateToBase, $total, $quoteNotesFinal, $accessToken, $sourcePricingRecordId, $pricingSourceRef);
            $stmtQuote->execute();
            $quoteId = (int)$stmtQuote->insert_id;
            $stmtQuote->close();
            app_assign_document_number($conn, 'quotes', $quoteId, 'quote_number', 'quote', $quoteDate);

            $unitLabel = (string)($calc['unit_label'] ?? '');
            $stmtItem = $conn->prepare("INSERT INTO quote_items (quote_id, item_name, description, quantity, unit, price, total) VALUES (?, ?, ?, ?, ?, ?, ?)");
            if (!$stmtItem) {
                throw new RuntimeException('prepare_quote_item_failed');
            }
            $itemDescription = $jobSpecs;
            $stmtItem->bind_param('issdsdd', $quoteId, $itemName, $itemDescription, $qty, $unitLabel, $quoteUnitPrice, $quoteTotal);
            $stmtItem->execute();
            $stmtItem->close();

            $conn->commit();
            return $accessToken;
        } catch (Throwable $e) {
            $conn->rollback();
            throw $e;
        }
    }
}

if (!function_exists('pricing_create_job')) {
    function pricing_create_job(mysqli $conn, bool $isEnglish, array $calc, int $clientId, bool $designRequired, string $jobNotes, string $userName, int $creatorUserId, int $sourcePricingRecordId = 0, string $pricingSourceRef = ''): array
    {
        $deliveryDate = date('Y-m-d', strtotime('+7 days'));
        $accessToken = bin2hex(random_bytes(16));
        $designStatus = $designRequired ? 'needed' : 'ready';
        $jobType = 'print';
        $stage = 'briefing';
        $jobDetailsText = pricing_build_job_details($isEnglish, $calc, ((string)($calc['pricing_mode'] ?? 'general') === 'books'), (string)($calc['job_specs'] ?? ''));

        $stmtJob = $conn->prepare("INSERT INTO job_orders (client_id, job_name, job_type, design_status, start_date, delivery_date, current_stage, quantity, price, paid, notes, added_by, job_details, created_by_user_id, access_token, source_pricing_record_id, pricing_source_ref) VALUES (?, ?, ?, ?, NOW(), ?, ?, ?, ?, 0, ?, ?, ?, ?, ?, ?, ?)");
        if (!$stmtJob) {
            throw new RuntimeException('prepare_job_failed');
        }
        $jobName = (string)($calc['job_title'] ?? '');
        $qty = (float)($calc['qty'] ?? 0);
        $total = (float)($calc['total'] ?? 0);
        $stmtJob->bind_param(
            'isssssddsssisis',
            $clientId,
            $jobName,
            $jobType,
            $designStatus,
            $deliveryDate,
            $stage,
            $qty,
            $total,
            $jobNotes,
            $userName,
            $jobDetailsText,
            $creatorUserId,
            $accessToken
            ,
            $sourcePricingRecordId,
            $pricingSourceRef
        );
        $stmtJob->execute();
        $jobId = (int)$stmtJob->insert_id;
        $stmtJob->close();
        app_assign_document_number($conn, 'job_orders', $jobId, 'job_number', 'job', $deliveryDate);
        if ($creatorUserId > 0 && function_exists('app_assign_user_to_job')) {
            app_assign_user_to_job($conn, $jobId, $creatorUserId, 'owner', $creatorUserId);
        }
        return ['id' => $jobId, 'token' => $accessToken];
    }
}
