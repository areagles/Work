<?php
ob_start();
require 'auth.php';
require 'config.php';
require 'pricing_engine.php';
require 'header.php';

$isEnglish = app_current_lang($conn) === 'en';
$appName = app_setting_get($conn, 'app_name', 'Arab Eagles');
$appLogo = app_brand_logo_path($conn, 'assets/img/Logo.png');
$brandProfile = app_brand_profile($conn);
$outputShowFooter = !empty($brandProfile['show_footer']);
$outputShowQr = !empty($brandProfile['show_qr']);
$footerLines = app_brand_output_lines($brandProfile, 'footer', true);
$legacyFooterLine1 = trim(app_setting_get($conn, 'brand_footer_line1', $appName));
$legacyFooterLine2 = trim(app_setting_get($conn, 'brand_footer_line2', ''));
$legacyFooterLine3 = trim(app_setting_get($conn, 'brand_footer_line3', (string)parse_url(SYSTEM_URL, PHP_URL_HOST)));
if (empty($footerLines)) {
    $footerLines = array_values(array_filter([$legacyFooterLine1, $legacyFooterLine2, $legacyFooterLine3], static function ($line): bool {
        return trim((string)$line) !== '';
    }));
}
$canPricingView = app_user_can('pricing.view') || app_is_super_user() || ((string)($_SESSION['role'] ?? '') === 'admin');
if (!$canPricingView) {
    $denyMessage = $isEnglish ? '⛔ You do not have permission to open the print pricing screen.' : '⛔ لا تملك صلاحية فتح شاشة تسعير الطباعة.';
    echo "<div class='container' style='margin-top:30px;'><div style='background:#1d0f0f;border:1px solid #5b2020;border-radius:12px;padding:20px;color:#ffb3b3;'>" . app_h($denyMessage) . "</div></div>";
    require 'footer.php';
    exit;
}

$pricingConfig = pricing_load_config($conn);
$quoteCurrencyOptions = app_quote_currency_options($conn);
$defaultQuoteCurrency = app_quote_default_currency($conn);
$selectedQuoteCurrency = app_quote_normalize_currency_code($conn, (string)($_POST['quote_currency_code'] ?? $defaultQuoteCurrency));
$pricingEnabled = (bool)$pricingConfig['enabled'];
$pricingDefaults = (array)$pricingConfig['defaults'];
$pricingBindingCosts = (array)$pricingConfig['binding_costs'];
$paperTypes = (array)$pricingConfig['paper_types'];
$machines = (array)$pricingConfig['machines'];
$finishOps = (array)$pricingConfig['finish_ops'];
$sizePresets = (array)$pricingConfig['size_presets'];

$pricingClientName = '';
$pricingClientPhone = '';
$loadedPricingRecordId = (int)($_GET['record_id'] ?? 0);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' && $loadedPricingRecordId > 0) {
    $loadedForm = pricing_load_record_form($conn, $loadedPricingRecordId);
    if (is_array($loadedForm)) {
        pricing_apply_loaded_record_for_calc($loadedForm);
        $_POST['source_record_id'] = (string)$loadedPricingRecordId;
    }
}

$sourcePricingRecordId = (int)($_POST['source_record_id'] ?? 0);
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && $sourcePricingRecordId > 0) {
    $sourceRecordRow = pricing_load_record_row($conn, $sourcePricingRecordId);
    $sourceForm = pricing_load_record_form($conn, $sourcePricingRecordId);
    if (is_array($sourceForm)) {
        pricing_merge_source_record_into_post($sourceForm, $_POST, $sourceRecordRow ?? null);
    }
}

$calc = pricing_empty_calc();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = (string)($_POST['action'] ?? 'calc');
    if (!app_verify_csrf($_POST['_csrf_token'] ?? '')) {
        $calc['error'] = pricing_text($isEnglish, 'انتهت الجلسة. أعد تحميل الصفحة ثم حاول مرة أخرى.', 'Session expired. Reload the page and try again.');
        $action = 'calc';
    } elseif ($sourcePricingRecordId > 0 && !is_array($sourceForm ?? null)) {
        $calc['error'] = pricing_text($isEnglish, 'ملف التسعير المحفوظ غير صالح أو لا يحتوي على بيانات قابلة للتحويل.', 'Saved pricing file is invalid or has no convertible data.');
        $action = 'calc';
    }

    $clientId = pricing_int($_POST['client_id'] ?? 0);
    $pricingClient = pricing_client_summary($conn, $clientId);
    $pricingClientName = (string)($pricingClient['name'] ?? '');
    $pricingClientPhone = (string)($pricingClient['phone'] ?? '');
    $operationName = trim((string)($_POST['operation_name'] ?? ''));
    $specs = trim((string)($_POST['specs'] ?? ''));
    $qty = max(0, pricing_float($_POST['qty'] ?? 0));
    $unitLabel = trim((string)($_POST['unit_label'] ?? pricing_text($isEnglish, 'قطعة', 'piece')));

    $paperKey = trim((string)($_POST['paper_type'] ?? ''));
    $paperRow = pricing_find_row($paperTypes, $paperKey, 'name');

    $machineKey = trim((string)($_POST['machine_type'] ?? ''));
    $machineRow = pricing_find_row($machines, $machineKey, 'key');
    $machineSheetFactor = pricing_sheet_class_factor((string)($machineRow['sheet_class'] ?? 'full'));
    $machineSheetDivisor = pricing_sheet_class_divisor((string)($machineRow['sheet_class'] ?? 'full'));
    $machineSheetClassLabel = pricing_sheet_class_label($isEnglish, (string)($machineRow['sheet_class'] ?? 'full'));

    $sheetPreset = trim((string)($_POST['sheet_size_preset'] ?? ''));
    $sheetWidth = pricing_float($_POST['paper_width_cm'] ?? 0);
    $sheetHeight = pricing_float($_POST['paper_height_cm'] ?? 0);
    if (($sheetWidth <= 0 || $sheetHeight <= 0) && isset($sizePresets[$sheetPreset])) {
        [$sheetWidth, $sheetHeight] = $sizePresets[$sheetPreset];
    }
    $paperGsm = pricing_float($_POST['paper_gsm'] ?? 0);

    $printMode = (string)($_POST['print_mode'] ?? 'single');
    $printModeMeta = pricing_print_mode_meta($printMode);
    $printFaces = (int)($printModeMeta['passes'] ?? 1);
    $printModeLabel = pricing_print_mode_label($isEnglish, $printMode);
    $colors = max(0, pricing_int($_POST['colors'] ?? 0));
    $pantone = max(0, pricing_int($_POST['pantone'] ?? 0));
    $pantoneTrayPrice = max(0, pricing_float($_POST['pantone_tray_price'] ?? 0));
    $processColorsPerFace = $colors;
    $plateColorsPerFace = max(0, $colors + $pantone);
    $totalColorSets = $plateColorsPerFace * (int)($printModeMeta['plate_sets'] ?? 1);

    $sheetYield = max(1, pricing_int($_POST['sheet_yield_override'] ?? 1));
    $plateUnitCost = max(0, pricing_float($machineRow['plate_cost'] ?? 0));
    $pricingMode = (string)($_POST['pricing_mode'] ?? 'general');
    $bookMode = $pricingMode === 'books' && !empty($pricingDefaults['book_mode_enabled']);

    $designRequired = !empty($_POST['design_required']);
    $designHours = max(0, pricing_float($_POST['design_hours'] ?? 0));
    $designHourlyRate = max(0, pricing_float($_POST['design_hour_rate'] ?? 0));
    $proofCost = max(0, pricing_float($_POST['proof_cost'] ?? 0));
    $creativeFlatCost = max(0, pricing_float($_POST['creative_flat_cost'] ?? 0));

    $prepressSetupCost = max(0, pricing_float($_POST['prepress_setup_cost'] ?? 0));
    $cuttingSetupCost = max(0, pricing_float($_POST['cutting_setup_cost'] ?? 0));

    $wastePercent = max(0, pricing_float($pricingDefaults['waste_percent'] ?? 0));
    $wasteSheets = max(0, pricing_int($pricingDefaults['waste_sheets'] ?? 0));
    $rejectPercent = max(0, pricing_float($pricingDefaults['reject_percent'] ?? 0));
    $miscCost = max(0, pricing_float($_POST['misc_cost'] ?? $pricingDefaults['misc_cost']));
    $setupFee = max(0, pricing_float($_POST['setup_fee'] ?? $pricingDefaults['setup_fee']));
    $colorTestCost = max(0, pricing_float($_POST['color_test_cost'] ?? $pricingDefaults['color_test_cost']));
    $internalTransportCost = max(0, pricing_float($_POST['internal_transport_cost'] ?? $pricingDefaults['internal_transport_cost']));
    $riskPercent = max(0, pricing_float($pricingDefaults['risk_percent'] ?? 0));
    $profitPercent = max(0, pricing_float($pricingDefaults['profit_percent'] ?? 0));

    $signaturesCount = max(1, pricing_int($_POST['signatures_count'] ?? 1));
    $bookGatherCostPerSignature = max(0, pricing_float($_POST['book_gather_cost_per_signature'] ?? $pricingDefaults['gather_cost_per_signature']));
    $bookBindingCostPerCopy = max(0, pricing_float($_POST['book_binding_cost_per_copy'] ?? 0));
    $bookYieldOverride = max(0, pricing_int($_POST['book_sheet_yield'] ?? 0));

    $packagingCost = max(0, pricing_float($_POST['packaging_cost'] ?? 0));
    $deliveryCost = max(0, pricing_float($_POST['delivery_cost'] ?? 0));
    $loadingCost = max(0, pricing_float($_POST['loading_cost'] ?? 0));

    $paperTonPrice = $paperRow ? pricing_float($paperRow['price_ton'] ?? 0) : 0.0;
    $coverPaperKey = trim((string)($_POST['book_cover_paper_type'] ?? ''));
    $coverPaperRow = pricing_find_row($paperTypes, $coverPaperKey, 'name');
    $coverPaperTonPrice = $coverPaperRow ? pricing_float($coverPaperRow['price_ton'] ?? 0) : 0.0;
    $coverWidth = pricing_float($_POST['book_cover_width_cm'] ?? 0);
    $coverHeight = pricing_float($_POST['book_cover_height_cm'] ?? 0);
    $coverGsm = pricing_float($_POST['book_cover_gsm'] ?? 0);
    $coverMachineKey = trim((string)($_POST['book_cover_machine_type'] ?? ''));
    $coverMachineRow = pricing_find_row($machines, $coverMachineKey, 'key');
    $coverMachineSheetFactor = pricing_sheet_class_factor((string)($coverMachineRow['sheet_class'] ?? 'full'));
    $coverMachineSheetDivisor = pricing_sheet_class_divisor((string)($coverMachineRow['sheet_class'] ?? 'full'));
    $coverMachineSheetClassLabel = pricing_sheet_class_label($isEnglish, (string)($coverMachineRow['sheet_class'] ?? 'full'));
    $coverPrintMode = (string)($_POST['book_cover_print_mode'] ?? 'single');
    $coverPrintModeMeta = pricing_print_mode_meta($coverPrintMode);
    $coverPrintFaces = (int)($coverPrintModeMeta['passes'] ?? 1);
    $coverPrintModeLabel = pricing_print_mode_label($isEnglish, $coverPrintMode);
    $coverColors = max(0, pricing_int($_POST['book_cover_colors'] ?? 0));
    $coverPantone = max(0, pricing_int($_POST['book_cover_pantone'] ?? 0));
    $coverPantoneTrayPrice = max(0, pricing_float($_POST['book_cover_pantone_tray_price'] ?? 0));
    $coverProcessColorsPerFace = $coverColors;
    $coverColorSetsPerFace = max(0, $coverColors + $coverPantone);
    $coverTotalColorSets = $coverColorSetsPerFace * (int)($coverPrintModeMeta['plate_sets'] ?? 1);
    $coverSheetYield = max(1, pricing_int($_POST['book_cover_sheet_yield'] ?? 1));
    $coverPlateUnitCost = max(0, pricing_float($coverMachineRow['plate_cost'] ?? 0));

    $innerPaperKey = trim((string)($_POST['book_inner_paper_type'] ?? ''));
    $innerPaperRow = pricing_find_row($paperTypes, $innerPaperKey, 'name');
    $innerPaperTonPrice = $innerPaperRow ? pricing_float($innerPaperRow['price_ton'] ?? 0) : 0.0;
    $innerWidth = pricing_float($_POST['book_inner_width_cm'] ?? 0);
    $innerHeight = pricing_float($_POST['book_inner_height_cm'] ?? 0);
    $innerGsm = pricing_float($_POST['book_inner_gsm'] ?? 0);
    $innerMachineKey = trim((string)($_POST['book_inner_machine_type'] ?? ''));
    $innerMachineRow = pricing_find_row($machines, $innerMachineKey, 'key');
    $innerMachineSheetFactor = pricing_sheet_class_factor((string)($innerMachineRow['sheet_class'] ?? 'full'));
    $innerMachineSheetDivisor = pricing_sheet_class_divisor((string)($innerMachineRow['sheet_class'] ?? 'full'));
    $innerMachineSheetClassLabel = pricing_sheet_class_label($isEnglish, (string)($innerMachineRow['sheet_class'] ?? 'full'));
    $innerPrintMode = (string)($_POST['book_inner_print_mode'] ?? 'double_plates');
    $innerPrintModeMeta = pricing_print_mode_meta($innerPrintMode);
    $innerPrintFaces = (int)($innerPrintModeMeta['passes'] ?? 2);
    $innerPrintModeLabel = pricing_print_mode_label($isEnglish, $innerPrintMode);
    $innerColors = max(0, pricing_int($_POST['book_inner_colors'] ?? 0));
    $innerPantone = max(0, pricing_int($_POST['book_inner_pantone'] ?? 0));
    $innerPantoneTrayPrice = max(0, pricing_float($_POST['book_inner_pantone_tray_price'] ?? 0));
    $innerProcessColorsPerFace = $innerColors;
    $innerColorSetsPerFace = max(0, $innerColors + $innerPantone);
    $innerTotalColorSets = $innerColorSetsPerFace * (int)($innerPrintModeMeta['plate_sets'] ?? 2);
    $innerSheetYield = max(1, pricing_int($_POST['book_inner_sheet_yield'] ?? 1));
    $innerPlateUnitCost = max(0, pricing_float($innerMachineRow['plate_cost'] ?? 0));
    $bindingType = trim((string)($_POST['book_binding_type'] ?? 'cut'));
    $bindingTypeLabel = pricing_binding_label($isEnglish, $bindingType);
    $bindingCostDefault = pricing_float($pricingBindingCosts[$bindingType] ?? 0);
    $bindingCostPerCopy = max(0, pricing_float($_POST['book_binding_cost_per_copy'] ?? $bindingCostDefault));

    $validationError = pricing_validate_inputs($isEnglish, [
        'client_id' => $clientId,
        'qty' => $qty,
        'book_mode' => $bookMode,
        'paper_row' => $paperRow,
        'machine_row' => $machineRow,
        'paper_ton_price' => $paperTonPrice,
        'sheet_width_cm' => $sheetWidth,
        'sheet_height_cm' => $sheetHeight,
        'sheet_gsm' => $paperGsm,
        'plate_colors_per_face' => $plateColorsPerFace,
        'cover_paper_row' => $coverPaperRow,
        'cover_machine_row' => $coverMachineRow,
        'inner_paper_row' => $innerPaperRow,
        'inner_machine_row' => $innerMachineRow,
        'cover_paper_ton_price' => $coverPaperTonPrice,
        'inner_paper_ton_price' => $innerPaperTonPrice,
        'cover_width_cm' => $coverWidth,
        'cover_height_cm' => $coverHeight,
        'cover_gsm' => $coverGsm,
        'inner_width_cm' => $innerWidth,
        'inner_height_cm' => $innerHeight,
        'inner_gsm' => $innerGsm,
        'cover_color_sets_per_face' => $coverColorSetsPerFace,
        'inner_color_sets_per_face' => $innerColorSetsPerFace,
    ]);
    if ($validationError !== '') {
        $calc['error'] = $validationError;
    } else {
        $baseUnits = $qty * ($bookMode ? $signaturesCount : 1);
        $machineSheetsRequired = (int)ceil($baseUnits / $sheetYield);
        $sheetsRequired = (int)ceil($machineSheetsRequired / max(1, $machineSheetDivisor));
        $wastePerPlateSetMachineSheets = (int)ceil($machineSheetsRequired * ($wastePercent / 100));
        $wasteMachineSheets = $wastePerPlateSetMachineSheets * max(1, (int)($printModeMeta['waste_multiplier'] ?? 1));
        $rejectMachineSheets = (int)ceil($machineSheetsRequired * ($rejectPercent / 100));
        $extraWasteMachineSheets = max(0, $wasteSheets) * max(1, $machineSheetDivisor);
        $totalWasteMachineSheets = $wasteMachineSheets + $rejectMachineSheets + $extraWasteMachineSheets;
        $totalMachineSheets = $machineSheetsRequired + $totalWasteMachineSheets;
        $sheetsWithWaste = (int)ceil($totalMachineSheets / max(1, $machineSheetDivisor));

        $sheetCost = pricing_sheet_cost_from_ton($paperTonPrice, $sheetWidth, $sheetHeight, $paperGsm);
        $paperCost = $sheetCost * $sheetsWithWaste;

        $designCost = $designRequired ? (($designHours * $designHourlyRate) + $proofCost + $creativeFlatCost) : 0.0;

        $coverBreakdown = pricing_empty_breakdown();
        $innerBreakdown = pricing_empty_breakdown();

        if ($bookMode) {
            $booksStage = pricing_compute_books_print_stage([
                'qty' => $qty,
                'signatures_count' => $signaturesCount,
                'waste_percent' => $wastePercent,
                'reject_percent' => $rejectPercent,
                'waste_sheets' => $wasteSheets,
                'prepress_setup_cost' => $prepressSetupCost,
                'cutting_setup_cost' => $cuttingSetupCost,
                'book_yield_override' => $bookYieldOverride,
                'cover_sheet_yield' => $coverSheetYield,
                'cover_machine_sheet_divisor' => $coverMachineSheetDivisor,
                'cover_print_mode_meta' => $coverPrintModeMeta,
                'cover_paper_ton_price' => $coverPaperTonPrice,
                'cover_width_cm' => $coverWidth,
                'cover_height_cm' => $coverHeight,
                'cover_gsm' => $coverGsm,
                'cover_color_sets_per_face' => $coverColorSetsPerFace,
                'cover_plate_unit_cost' => $coverPlateUnitCost,
                'cover_machine_min_trays' => pricing_int($coverMachineRow['min_trays'] ?? 1),
                'cover_machine_price_per_tray' => pricing_float($coverMachineRow['price_per_tray'] ?? 0),
                'cover_process_colors_per_face' => $coverProcessColorsPerFace,
                'cover_pantone' => $coverPantone,
                'cover_pantone_tray_price' => $coverPantoneTrayPrice,
                'inner_sheet_yield' => $innerSheetYield,
                'inner_machine_sheet_divisor' => $innerMachineSheetDivisor,
                'inner_print_mode_meta' => $innerPrintModeMeta,
                'inner_paper_ton_price' => $innerPaperTonPrice,
                'inner_width_cm' => $innerWidth,
                'inner_height_cm' => $innerHeight,
                'inner_gsm' => $innerGsm,
                'inner_color_sets_per_face' => $innerColorSetsPerFace,
                'inner_plate_unit_cost' => $innerPlateUnitCost,
                'inner_machine_min_trays' => pricing_int($innerMachineRow['min_trays'] ?? 1),
                'inner_machine_price_per_tray' => pricing_float($innerMachineRow['price_per_tray'] ?? 0),
                'inner_process_colors_per_face' => $innerProcessColorsPerFace,
                'inner_pantone' => $innerPantone,
                'inner_pantone_tray_price' => $innerPantoneTrayPrice,
            ]);

            $paperCost = (float)$booksStage['paper_cost'];
            $platesCost = (float)$booksStage['plates_cost'];
            $prepressCost = (float)$booksStage['prepress_cost'];
            $printingCost = (float)$booksStage['printing_cost'];
            $impressions = (int)$booksStage['impressions'];
            $trays = (int)$booksStage['trays'];
            $sheetCost = (float)$booksStage['sheet_cost'];
            $sheetsRequired = (int)$booksStage['sheets_required'];
            $sheetsWithWaste = (int)$booksStage['sheets_with_waste'];
            $sheetYield = (int)$booksStage['sheet_yield'];
            $totalColorSets = (int)$booksStage['total_color_sets'];
            $plateMultiplier = (int)$booksStage['plate_multiplier'];
            $coverBreakdown = (array)$booksStage['cover_breakdown'];
            $innerBreakdown = (array)$booksStage['inner_breakdown'];
            $coverPlateMultiplier = (int)($coverBreakdown['plates'] ?? 0);
            $innerPlateMultiplier = (int)($innerBreakdown['plates'] ?? 0);

            $coverSheetCost = (float)$booksStage['cover_sheet_cost'];
            $coverSheetsRequired = (int)$booksStage['cover_sheets_required'];
            $coverSheetsWithWaste = (int)$booksStage['cover_sheets_with_waste'];
            $innerSheetCost = (float)$booksStage['inner_sheet_cost'];
            $innerSheetsRequired = (int)$booksStage['inner_sheets_required'];
            $innerSheetsWithWaste = (int)$booksStage['inner_sheets_with_waste'];

            $coverPrintingCost = (float)$booksStage['cover_printing_cost'];
            $innerPrintingCost = (float)$booksStage['inner_printing_cost'];
            $coverPantonePrintingCost = (float)$booksStage['cover_pantone_printing_cost'];
            $innerPantonePrintingCost = (float)$booksStage['inner_pantone_printing_cost'];
            $coverComputedTrays = (int)$booksStage['cover_computed_trays'];
            $innerComputedTrays = (int)$booksStage['inner_computed_trays'];
            $coverBillableTrayRuns = (int)$booksStage['cover_billable_tray_runs'];
            $innerBillableTrayRuns = (int)$booksStage['inner_billable_tray_runs'];
            $coverMinTrays = (int)$booksStage['cover_min_trays'];
            $innerMinTrays = (int)$booksStage['inner_min_trays'];
        } else {
            $regularStage = pricing_compute_regular_print_stage([
                'plate_colors_per_face' => $plateColorsPerFace,
                'plate_sets' => (int)($printModeMeta['plate_sets'] ?? 1),
                'plate_unit_cost' => $plateUnitCost,
                'prepress_setup_cost' => $prepressSetupCost,
                'cutting_setup_cost' => $cuttingSetupCost,
                'total_machine_sheets' => $totalMachineSheets,
                'min_trays' => pricing_int($machineRow['min_trays'] ?? 1),
                'passes' => (int)($printModeMeta['passes'] ?? 1),
                'process_colors_per_face' => $processColorsPerFace,
                'pantone' => $pantone,
                'pantone_tray_price' => $pantoneTrayPrice,
                'price_per_tray' => pricing_float($machineRow['price_per_tray'] ?? 0),
            ]);
            $plateMultiplier = (int)$regularStage['plate_multiplier'];
            $platesCost = (float)$regularStage['plates_cost'];
            $prepressCost = (float)$regularStage['prepress_cost'];
            $impressions = (int)$regularStage['impressions'];
            $computedTrays = (int)$regularStage['computed_trays'];
            $minTrays = (int)$regularStage['min_trays'];
            $trays = (int)$regularStage['trays'];
            $pricePerTray = (float)$regularStage['price_per_tray'];
            $processPrintingCost = (float)$regularStage['process_printing_cost'];
            $pantonePrintingCost = (float)$regularStage['pantone_printing_cost'];
            $printingCost = (float)$regularStage['printing_cost'];
        }

        $printingRows = pricing_build_printing_rows($isEnglish, $bookMode, [
            'cover_printing_cost' => $coverPrintingCost ?? 0,
            'cover_print_mode_label' => $coverPrintModeLabel ?? '',
            'cover_machine_price_per_tray' => pricing_float($coverMachineRow['price_per_tray'] ?? 0),
            'cover_computed_trays' => $coverComputedTrays ?? 0,
            'cover_min_trays' => $coverMinTrays ?? 1,
            'cover_billable_tray_runs' => $coverBillableTrayRuns ?? 0,
            'cover_process_colors_per_face' => $coverProcessColorsPerFace ?? 0,
            'cover_pantone' => $coverPantone ?? 0,
            'cover_plate_multiplier' => $coverPlateMultiplier ?? 0,
            'cover_pantone_printing_cost' => $coverPantonePrintingCost ?? 0,
            'cover_pantone_tray_price' => $coverPantoneTrayPrice ?? 0,
            'inner_printing_cost' => $innerPrintingCost ?? 0,
            'inner_print_mode_label' => $innerPrintModeLabel ?? '',
            'inner_machine_price_per_tray' => pricing_float($innerMachineRow['price_per_tray'] ?? 0),
            'inner_computed_trays' => $innerComputedTrays ?? 0,
            'inner_min_trays' => $innerMinTrays ?? 1,
            'inner_billable_tray_runs' => $innerBillableTrayRuns ?? 0,
            'inner_process_colors_per_face' => $innerProcessColorsPerFace ?? 0,
            'inner_pantone' => $innerPantone ?? 0,
            'inner_plate_multiplier' => $innerPlateMultiplier ?? 0,
            'inner_pantone_printing_cost' => $innerPantonePrintingCost ?? 0,
            'inner_pantone_tray_price' => $innerPantoneTrayPrice ?? 0,
            'printing_cost' => $printingCost ?? 0,
            'print_mode_label' => $printModeLabel ?? '',
            'price_per_tray' => $pricePerTray ?? 0,
            'computed_trays' => $computedTrays ?? 0,
            'min_trays' => $minTrays ?? 1,
            'trays' => $trays ?? 0,
            'process_colors_per_face' => $processColorsPerFace ?? 0,
            'pantone' => $pantone ?? 0,
            'plate_multiplier' => $plateMultiplier ?? 0,
            'pantone_printing_cost' => $pantonePrintingCost ?? 0,
            'pantone_tray_price' => $pantoneTrayPrice ?? 0,
        ]);

        $finishingStage = pricing_compute_finishing_stage([
            'is_english' => $isEnglish,
            'book_mode' => $bookMode,
            'finish_ops' => $finishOps,
            'post' => $_POST,
            'machine_sheet_factor' => $machineSheetFactor,
            'cover_machine_sheet_factor' => $coverMachineSheetFactor ?? 1,
            'inner_machine_sheet_factor' => $innerMachineSheetFactor ?? 1,
            'sheets_with_waste' => $sheetsWithWaste,
            'cover_sheets_with_waste' => $coverSheetsWithWaste ?? 0,
            'inner_sheets_with_waste' => $innerSheetsWithWaste ?? 0,
            'qty' => $qty,
            'signatures_count' => $signaturesCount,
            'book_gather_cost_per_signature' => $bookGatherCostPerSignature,
            'book_binding_cost_per_copy' => $bookBindingCostPerCopy,
            'cover_breakdown' => $coverBreakdown,
            'inner_breakdown' => $innerBreakdown,
        ]);
        $finishingCost = (float)$finishingStage['finishing_cost'];
        $finishingRows = (array)$finishingStage['finishing_rows'];
        $coverBreakdown = (array)$finishingStage['cover_breakdown'];
        $innerBreakdown = (array)$finishingStage['inner_breakdown'];
        $bookGatherCost = (float)$finishingStage['book_gather_cost'];
        $bookBindCost = (float)$finishingStage['book_bind_cost'];

        $deliveryStageCost = $packagingCost + $deliveryCost + $loadingCost + $internalTransportCost;
        $baseSubtotal = $designCost + $paperCost + $prepressCost + $printingCost + $finishingCost + $deliveryStageCost + $miscCost + $setupFee + $colorTestCost;
        $riskCost = $baseSubtotal * ($riskPercent / 100);
        $subtotal = $baseSubtotal + $riskCost;
        $profitCost = $subtotal * ($profitPercent / 100);
        $total = $subtotal + $profitCost;

        $calc = pricing_build_calc_result($isEnglish, [
            'book_mode' => $bookMode,
            'machine_row' => $machineRow,
            'machine_key' => $machineKey,
            'paper_cost' => $paperCost,
            'design_cost' => $designCost,
            'prepress_cost' => $prepressCost,
            'printing_cost' => $printingCost,
            'pantone_printing_cost' => $pantonePrintingCost ?? 0.0,
            'plates_cost' => $platesCost,
            'finishing_cost' => $finishingCost,
            'packaging_cost' => $packagingCost,
            'loading_cost' => $loadingCost,
            'delivery_cost' => $deliveryCost,
            'color_test_cost' => $colorTestCost,
            'internal_transport_cost' => $internalTransportCost,
            'risk_cost' => $riskCost,
            'misc_cost' => $miscCost,
            'setup_fee' => $setupFee,
            'profit_cost' => $profitCost,
            'subtotal' => $subtotal,
            'total' => $total,
            'qty' => $qty,
            'unit_label' => $unitLabel,
            'sheet_width_cm' => $sheetWidth,
            'sheet_height_cm' => $sheetHeight,
            'sheet_gsm' => $paperGsm,
            'sheet_cost' => $sheetCost,
            'sheet_yield' => $sheetYield,
            'machine_sheet_divisor' => $machineSheetDivisor,
            'machine_sheets_required' => $machineSheetsRequired,
            'waste_machine_sheets' => $wasteMachineSheets + $rejectMachineSheets + $extraWasteMachineSheets,
            'base_units' => $baseUnits,
            'sheets_required' => $sheetsRequired,
            'sheets_with_waste' => $sheetsWithWaste,
            'impressions' => $impressions,
            'trays' => $trays,
            'print_faces' => $printFaces,
            'print_mode' => $printMode,
            'print_mode_label' => $printModeLabel,
            'total_color_sets' => $totalColorSets,
            'plate_multiplier' => $plateMultiplier,
            'book_bind_cost' => $bookBindCost,
            'book_gather_cost' => $bookGatherCost,
            'operation_name' => $operationName,
            'specs' => $specs,
            'paper_name' => (string)($paperRow['name'] ?? ''),
            'paper_ton_price' => $paperTonPrice,
            'cover_breakdown' => $coverBreakdown,
            'cover_pantone_printing_cost' => $coverPantonePrintingCost ?? 0.0,
            'inner_breakdown' => $innerBreakdown,
            'inner_pantone_printing_cost' => $innerPantonePrintingCost ?? 0.0,
            'binding_type' => $bindingType,
            'finishing_rows' => $finishingRows,
            'printing_rows' => $printingRows,
            'cover_paper_name' => (string)($coverPaperRow['name'] ?? ''),
            'cover_paper_ton_price' => $coverPaperTonPrice,
            'cover_sheet_width_cm' => $coverWidth,
            'cover_sheet_height_cm' => $coverHeight,
            'cover_sheet_gsm' => $coverGsm,
            'cover_sheet_cost' => $coverSheetCost ?? 0.0,
            'cover_sheets_required' => $coverSheetsRequired ?? 0,
            'cover_sheets_with_waste' => $coverSheetsWithWaste ?? 0,
            'inner_paper_name' => (string)($innerPaperRow['name'] ?? ''),
            'inner_paper_ton_price' => $innerPaperTonPrice,
            'inner_sheet_width_cm' => $innerWidth,
            'inner_sheet_height_cm' => $innerHeight,
            'inner_sheet_gsm' => $innerGsm,
            'inner_sheet_cost' => $innerSheetCost ?? 0.0,
            'inner_sheets_required' => $innerSheetsRequired ?? 0,
            'inner_sheets_with_waste' => $innerSheetsWithWaste ?? 0,
            'stage_rows' => pricing_build_stage_rows($isEnglish, [
                'book_mode' => $bookMode,
                'cover_breakdown' => $coverBreakdown,
                'inner_breakdown' => $innerBreakdown,
                'design_cost' => $designCost,
                'paper_cost' => $paperCost,
                'prepress_cost' => $prepressCost,
                'printing_cost' => $printingCost,
                'finishing_cost' => $finishingCost,
                'packaging_cost' => $packagingCost,
                'loading_cost' => $loadingCost,
                'color_test_cost' => $colorTestCost,
                'internal_transport_cost' => $internalTransportCost,
                'delivery_cost' => $deliveryCost,
                'misc_cost' => $miscCost,
                'setup_fee' => $setupFee,
                'risk_cost' => $riskCost,
            ]),
        ]);
    }

    if (in_array($action, ['save_pricing_record', 'save_pricing_record_print'], true) && $calc['ok']) {
        try {
            $creatorUserId = (int)($_SESSION['user_id'] ?? 0);
            $creatorName = (string)($_SESSION['name'] ?? $_SESSION['username'] ?? 'system');
            $pricingRecordId = pricing_save_record($conn, $_POST, $calc, $clientId, $creatorUserId, $creatorName);

            if ($action === 'save_pricing_record_print') {
                header('Location: print_pricing_record.php?id=' . $pricingRecordId);
            } else {
                header('Location: pricing_records.php?saved=1&id=' . $pricingRecordId);
            }
            exit;
        } catch (Throwable $e) {
            $calc['quote_error'] = pricing_text($isEnglish, 'تعذر حفظ ملف التسعير حالياً.', 'Unable to save the pricing file right now.');
        }
    }

    if ($action === 'save_quote' && $calc['ok']) {
        try {
            $sourcePricingRef = trim((string)(($sourceRecordRow ?? [])['pricing_ref'] ?? ''));
            $accessToken = pricing_create_quote($conn, $isEnglish, $calc, $clientId, $bookMode, trim((string)($_POST['notes'] ?? '')), $sourcePricingRecordId, $sourcePricingRef, (string)($_POST['quote_currency_code'] ?? $defaultQuoteCurrency));
            header('Location: view_quote.php?token=' . rawurlencode($accessToken));
            exit;
        } catch (Throwable $e) {
            $calc['quote_error'] = pricing_text($isEnglish, 'تعذر إنشاء عرض السعر حالياً.', 'Unable to create quote right now.');
        }
    }

    if ($action === 'save_job' && $calc['ok']) {
        if (!app_user_can('jobs.create') && !app_user_can('jobs.manage_all')) {
            $calc['quote_error'] = pricing_text($isEnglish, 'ليس لديك صلاحية إنشاء أمر شغل من شاشة التسعير.', 'You do not have permission to create a work order from pricing.');
        } else {
            $userName = (string)($_SESSION['name'] ?? $_SESSION['username'] ?? 'system');
            $creatorUserId = (int)($_SESSION['user_id'] ?? 0);
            try {
                $jobNotes = trim((string)($_POST['notes'] ?? ''));
                $calc['pricing_mode'] = $bookMode ? 'books' : 'general';
                $calc['signatures_count'] = $signaturesCount;
                $sourcePricingRef = trim((string)(($sourceRecordRow ?? [])['pricing_ref'] ?? ''));
                $job = pricing_create_job($conn, $isEnglish, $calc, $clientId, $designRequired, $jobNotes, $userName, $creatorUserId, $sourcePricingRecordId, $sourcePricingRef);
                header('Location: view_order.php?id=' . (int)$job['id'] . '&token=' . rawurlencode((string)$job['token']));
                exit;
            } catch (Throwable $e) {
                $calc['quote_error'] = pricing_text($isEnglish, 'تعذر إنشاء أمر الشغل حالياً.', 'Unable to create work order right now.');
            }
        }
    }
}
?>

<style>
    :root {
        --pricing-bg: #070707;
        --pricing-card: #121212;
        --pricing-card-soft: #171717;
        --pricing-input: #0f0f0f;
        --pricing-border: #2f2f2f;
        --pricing-border-soft: #232323;
        --pricing-text: #f4f4f4;
        --pricing-muted: #9d9d9d;
        --pricing-gold: #d4af37;
        --pricing-gold-soft: rgba(212, 175, 55, 0.16);
        --pricing-green: #2ecc71;
        --pricing-red: #f87171;
    }
    body { background: var(--pricing-bg) !important; }
    .pricing-shell { max-width: 1480px; margin: 0 auto; padding: 22px 18px 34px; }
    .pricing-header {
        display: flex;
        gap: 18px;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 22px;
        flex-wrap: wrap;
    }
    .pricing-title-wrap h2 {
        margin: 0;
        color: var(--pricing-text);
        font-size: clamp(1.8rem, 2.8vw, 2.7rem);
        line-height: 1.15;
    }
    .pricing-title-wrap p {
        margin: 8px 0 0;
        color: var(--pricing-muted);
        font-size: 1rem;
        max-width: 780px;
    }
    .pricing-actions {
        display: flex;
        gap: 10px;
        flex-wrap: wrap;
    }
    .pricing-btn,
    .pricing-btn-secondary {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-height: 48px;
        padding: 0 20px;
        border-radius: 14px;
        border: 1px solid var(--pricing-border);
        text-decoration: none;
        cursor: pointer;
        font-weight: 700;
        transition: .2s ease;
    }
    .pricing-btn {
        background: linear-gradient(135deg, #e5c44f, #c99a17);
        color: #111 !important;
        box-shadow: 0 12px 26px rgba(201, 154, 23, 0.22);
    }
    .pricing-btn-secondary {
        background: #171717;
        color: #f0f0f0 !important;
    }
    .pricing-btn:hover,
    .pricing-btn-secondary:hover {
        transform: translateY(-1px);
    }
    .pricing-alert {
        border: 1px solid var(--pricing-border);
        background: #161616;
        color: #eee;
        border-radius: 16px;
        padding: 14px 16px;
        margin-bottom: 18px;
    }
    .pricing-alert.error {
        border-color: rgba(248, 113, 113, 0.38);
        background: rgba(127, 29, 29, 0.20);
        color: #ffd7d7;
    }
    .pricing-alert.ok {
        border-color: rgba(46, 204, 113, 0.34);
        background: rgba(17, 94, 48, 0.20);
        color: #d7ffe6;
    }
    .pricing-layout {
        display: grid;
        grid-template-columns: minmax(0, 1.4fr) minmax(360px, 0.8fr);
        gap: 22px;
        align-items: start;
    }
    .pricing-main {
        display: flex;
        flex-direction: column;
        gap: 18px;
    }
    .pricing-summary {
        position: sticky;
        top: 92px;
        display: flex;
        flex-direction: column;
        gap: 18px;
    }
    .pricing-section {
        background: linear-gradient(180deg, #161616, #101010);
        border: 1px solid var(--pricing-border);
        border-radius: 22px;
        overflow: hidden;
        box-shadow: 0 18px 40px rgba(0, 0, 0, 0.32);
    }
    .pricing-section-head {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 14px;
        padding: 20px 22px 14px;
        border-bottom: 1px solid var(--pricing-border-soft);
        background: linear-gradient(180deg, rgba(212,175,55,0.07), transparent);
    }
    .pricing-section-head h3 {
        margin: 0;
        color: #fff;
        font-size: clamp(1.1rem, 1.4vw, 1.45rem);
    }
    .pricing-section-head span {
        color: var(--pricing-muted);
        font-size: 0.95rem;
    }
    .pricing-section-body {
        padding: 20px 22px 22px;
    }
    .pricing-mode-tabs {
        display: flex;
        flex-wrap: wrap;
        gap: 10px;
        margin-bottom: 16px;
    }
    .pricing-mode-tab {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-height: 46px;
        padding: 0 18px;
        border-radius: 14px;
        border: 1px solid var(--pricing-border);
        background: #111;
        color: #f5f5f5;
        cursor: pointer;
        font-weight: 700;
    }
    .pricing-mode-tab.active {
        background: linear-gradient(135deg, #e5c44f, #c99a17);
        color: #111;
        border-color: rgba(212,175,55,0.55);
        box-shadow: 0 10px 24px rgba(201,154,23,0.22);
    }
    .pricing-mode-panel.hidden {
        display: none;
    }
    .pricing-fields {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 16px 18px;
    }
    .pricing-field,
    .pricing-field-full {
        display: flex;
        flex-direction: column;
        gap: 8px;
    }
    .pricing-field-full {
        grid-column: 1 / -1;
    }
    .pricing-field label,
    .pricing-field-full label {
        color: #e9e9e9;
        font-size: 1rem;
        font-weight: 700;
        line-height: 1.35;
    }
    .pricing-hint {
        color: var(--pricing-muted);
        font-size: 0.9rem;
        line-height: 1.45;
        margin-top: -2px;
    }
    .pricing-input,
    .pricing-select,
    .pricing-textarea {
        width: 100%;
        min-height: 52px;
        padding: 14px 16px;
        border: 1px solid var(--pricing-border);
        border-radius: 16px;
        background: var(--pricing-input);
        color: #fff;
        font-size: 1rem;
        transition: .2s ease;
    }
    .pricing-textarea {
        min-height: 148px;
        resize: vertical;
        line-height: 1.65;
    }
    .pricing-input:focus,
    .pricing-select:focus,
    .pricing-textarea:focus {
        outline: none;
        border-color: var(--pricing-gold);
        box-shadow: 0 0 0 4px var(--pricing-gold-soft);
    }
    .pricing-inline {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 12px;
    }
    .pricing-extra-ops {
        display: grid;
        gap: 10px;
    }
    .pricing-extra-op-row {
        display: grid;
        grid-template-columns: minmax(160px, 1.2fr) minmax(120px, .8fr) auto;
        gap: 10px;
        align-items: center;
    }
    .pricing-extra-remove {
        width: 42px;
        height: 42px;
        border-radius: 10px;
        border: 1px solid rgba(231,76,60,.45);
        background: rgba(231,76,60,.08);
        color: #ffb0a8;
        cursor: pointer;
    }
    .pricing-check {
        display: flex;
        align-items: center;
        gap: 10px;
        min-height: 52px;
        padding: 0 14px;
        border-radius: 16px;
        background: #111;
        border: 1px solid var(--pricing-border);
    }
    .pricing-check input {
        width: 18px;
        height: 18px;
    }
    .pricing-check span {
        color: #f1f1f1;
        font-size: 0.98rem;
        font-weight: 700;
    }
    .pricing-op-card {
        padding: 16px;
        border-radius: 18px;
        border: 1px solid var(--pricing-border-soft);
        background: var(--pricing-card-soft);
        display: flex;
        flex-direction: column;
        gap: 12px;
    }
    .pricing-op-title {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
    }
    .pricing-op-title strong {
        color: #fff;
        font-size: 1rem;
    }
    .pricing-op-grid {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 10px;
    }
    .pricing-summary-card {
        background: linear-gradient(180deg, #181818, #111);
        border: 1px solid var(--pricing-border);
        border-radius: 22px;
        padding: 20px;
        box-shadow: 0 18px 34px rgba(0,0,0,0.28);
    }
    .pricing-summary-card h3 {
        margin: 0 0 14px;
        font-size: 1.25rem;
        color: #fff;
    }
    .pricing-stage-list {
        display: flex;
        flex-direction: column;
        gap: 10px;
    }
    .pricing-stage-row {
        display: flex;
        justify-content: space-between;
        gap: 14px;
        align-items: center;
        padding: 11px 0;
        border-bottom: 1px solid var(--pricing-border-soft);
    }
    .pricing-stage-row span:first-child {
        color: #ddd;
        font-size: 0.98rem;
    }
    .pricing-stage-row span:last-child {
        color: #fff;
        font-weight: 700;
        font-size: 1rem;
        white-space: nowrap;
    }
    .pricing-stage-row.total span:first-child,
    .pricing-stage-row.total span:last-child {
        color: var(--pricing-gold);
        font-size: 1.15rem;
        font-weight: 800;
    }
    .pricing-metrics {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 10px;
        margin-top: 14px;
    }
    .pricing-metric {
        background: #111;
        border: 1px solid var(--pricing-border-soft);
        border-radius: 16px;
        padding: 14px;
    }
    .pricing-metric small {
        display: block;
        color: var(--pricing-muted);
        margin-bottom: 6px;
        font-size: 0.85rem;
    }
    .pricing-metric strong {
        color: #fff;
        font-size: 1.05rem;
    }
    .pricing-currency-chip { display:inline-flex; align-items:center; gap:10px; padding:8px 10px; border:1px solid rgba(212,175,55,.25); border-radius:14px; color:var(--pricing-gold); background:rgba(212,175,55,.06); font-weight:800; flex-wrap:wrap; }
    .pricing-currency-chip .pricing-select { padding:8px 10px; min-height:auto; }
    .pricing-converted-total { width:100%; color:#f7e2a0; font-size:.84rem; line-height:1.6; }
    .pricing-footer-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 10px;
        margin-top: 18px;
    }
    .pricing-footer-actions .pricing-btn,
    .pricing-footer-actions .pricing-btn-secondary {
        min-width: 180px;
    }
    .pricing-print-document {
        display: none;
    }
    @media (max-width: 1180px) {
        .pricing-layout {
            grid-template-columns: 1fr;
        }
        .pricing-summary {
            position: static;
        }
    }
    @media (max-width: 820px) {
        .pricing-fields,
        .pricing-inline,
        .pricing-extra-op-row,
        .pricing-op-grid,
        .pricing-metrics {
            grid-template-columns: 1fr;
        }
        .pricing-shell {
            padding-inline: 14px;
        }
        .pricing-section-head,
        .pricing-section-body,
        .pricing-summary-card {
            padding-inline: 16px;
        }
    }
    @media print {
        body {
            background: #fff !important;
        }
        body.pricing-print-mode header,
        body.pricing-print-mode footer,
        body.pricing-print-mode .pricing-shell,
        body.pricing-print-mode .chat-balloon,
        body.pricing-print-mode .support-widget,
        body.pricing-print-mode .floating-chat-btn,
        body.pricing-print-mode .mobile-nav,
        body.pricing-print-mode .desktop-nav {
            display: none !important;
        }
        body.pricing-print-mode .pricing-print-document {
            display: block !important;
            background: #fff !important;
            color: #111 !important;
            padding: 24px;
        }
        .pricing-print-sheet {
            max-width: 820px;
            margin: 0 auto;
            border: 1px solid #d9d9d9;
            border-radius: 14px;
            overflow: hidden;
            background: #fff;
        }
        .pricing-print-head {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 22px 26px;
            border-bottom: 1px solid #ddd;
        }
        .pricing-print-title {
            font-size: 2rem;
            font-weight: 900;
            color: #111;
            margin: 0;
        }
        .pricing-print-subtitle {
            margin-top: 6px;
            color: #666;
            font-size: .95rem;
        }
        .pricing-print-logo {
            width: 72px;
            max-width: 100%;
        }
        .pricing-print-body {
            padding: 24px 26px 28px;
        }
        .pricing-print-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 12px;
            margin-bottom: 18px;
        }
        .pricing-print-card {
            border: 1px solid #ddd;
            border-radius: 10px;
            padding: 12px 14px;
            background: #fafafa;
        }
        .pricing-print-card small {
            display: block;
            color: #777;
            margin-bottom: 6px;
        }
        .pricing-print-card strong {
            color: #111;
            font-size: 1rem;
        }
        .pricing-print-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 12px;
        }
        .pricing-print-table th,
        .pricing-print-table td {
            border-bottom: 1px solid #ddd;
            padding: 10px 8px;
            text-align: right;
            color: #111;
        }
        .pricing-print-table th {
            background: #f4f4f4 !important;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }
        .pricing-print-total {
            margin-top: 18px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 14px 16px;
            border-radius: 12px;
            background: #f3f3f3 !important;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
            font-weight: 900;
            color: #111;
        }
        .pricing-print-notes {
            margin-top: 16px;
            border: 1px dashed #bbb;
            border-radius: 10px;
            padding: 14px;
            color: #111;
            line-height: 1.8;
        }
        .pricing-print-footer {
            margin-top: 18px;
            padding-top: 16px;
            border-top: 1px solid #ddd;
            color: #555;
            font-size: .9rem;
        }
        .pricing-print-footer p {
            margin: 0 0 4px;
        }
        @page {
            size: A4;
            margin: 12mm;
        }
    }
</style>

<div class="pricing-shell">
    <div class="pricing-header">
        <div class="pricing-title-wrap">
            <h2><?php echo app_h(pricing_text($isEnglish, 'نظام تسعير الطباعة المرحلي', 'Stage-Based Print Pricing')); ?></h2>
            <p><?php echo app_h(pricing_text($isEnglish, 'ابدأ من تعريف العملية، ثم مرّر التكلفة على مراحلها الفعلية: تصميم، خامات وتجهيز، طباعة، تشطيب، تغليف ونقل. الناتج يذهب مباشرة إلى عرض سعر مختصر وواضح للعميل.', 'Define the job first, then cost it through its real stages: design, materials and prepress, printing, finishing, packing, and delivery. The result creates a clean quotation for the client.')); ?></p>
        </div>
        <div class="pricing-actions">
            <a class="pricing-btn-secondary" href="master_data.php?tab=pricing"><?php echo app_h(pricing_text($isEnglish, 'إعدادات التسعير', 'Pricing Settings')); ?></a>
        </div>
    </div>

    <?php if (!$pricingEnabled): ?>
        <div class="pricing-alert"><?php echo app_h(pricing_text($isEnglish, 'الموديول غير مفعل حالياً. فعّله أولاً من البيانات الأولية > تسعير الطباعة.', 'The pricing module is currently disabled. Enable it first from Master Data > Print Pricing.')); ?></div>
    <?php endif; ?>

    <?php if ($calc['error'] !== ''): ?>
        <div class="pricing-alert error"><?php echo app_h($calc['error']); ?></div>
    <?php elseif ($calc['ok']): ?>
        <div class="pricing-alert ok"><?php echo app_h(pricing_text($isEnglish, 'تم احتساب التكلفة بنجاح. راجع المراحل على اليمين ثم أنشئ عرض السعر إذا كانت النتيجة مناسبة.', 'Costing completed successfully. Review the stages on the right, then create a quotation if the result is acceptable.')); ?></div>
    <?php endif; ?>

    <?php if ($calc['quote_error'] !== ''): ?>
        <div class="pricing-alert error"><?php echo app_h($calc['quote_error']); ?></div>
    <?php endif; ?>

    <form method="post">
        <?php echo app_csrf_input(); ?>
        <div class="pricing-layout">
            <input type="hidden" name="source_record_id" value="<?php echo (int)($_POST['source_record_id'] ?? 0); ?>">
            <div class="pricing-main">
                <section class="pricing-section">
                    <div class="pricing-section-head">
                        <div>
                            <h3><?php echo app_h(pricing_text($isEnglish, '1. تعريف العملية', '1. Job Definition')); ?></h3>
                            <span><?php echo app_h(pricing_text($isEnglish, 'بيانات الأمر الأساسي التي سيبنى عليها التسعير وعرض السعر.', 'The base job data that pricing and quotation will use.')); ?></span>
                        </div>
                    </div>
                    <div class="pricing-section-body">
                        <?php $pricingModeValue = (string)($_POST['pricing_mode'] ?? 'general'); ?>
                        <input type="hidden" name="pricing_mode" id="pricing_mode" value="<?php echo app_h($pricingModeValue); ?>">
                        <?php if (!empty($pricingDefaults['book_mode_enabled'])): ?>
                            <div class="pricing-mode-tabs">
                                <button type="button" class="pricing-mode-tab <?php echo $pricingModeValue !== 'books' ? 'active' : ''; ?>" data-mode="general"><?php echo app_h(pricing_text($isEnglish, 'تشغيل عادي', 'Regular Job')); ?></button>
                                <button type="button" class="pricing-mode-tab <?php echo $pricingModeValue === 'books' ? 'active' : ''; ?>" data-mode="books"><?php echo app_h(pricing_text($isEnglish, 'كتب / مجلات', 'Books / Magazines')); ?></button>
                            </div>
                        <?php endif; ?>
                        <div class="pricing-fields">
                            <div class="pricing-field">
                                <label><?php echo app_h(pricing_text($isEnglish, 'العميل', 'Client')); ?></label>
                                <select name="client_id" class="pricing-select" required>
                                    <option value=""><?php echo app_h(pricing_text($isEnglish, '-- اختر العميل --', '-- Select client --')); ?></option>
                                    <?php $clients = $conn->query("SELECT id, name FROM clients ORDER BY name ASC"); ?>
                                    <?php while ($client = $clients->fetch_assoc()): ?>
                                        <option value="<?php echo (int)$client['id']; ?>" <?php echo ((int)($_POST['client_id'] ?? 0) === (int)$client['id']) ? 'selected' : ''; ?>>
                                            <?php echo app_h((string)$client['name']); ?>
                                        </option>
                                    <?php endwhile; ?>
                                </select>
                            </div>
                            <div class="pricing-field">
                                <label><?php echo app_h(pricing_text($isEnglish, 'اسم العملية', 'Operation Name')); ?></label>
                                <input class="pricing-input" name="operation_name" value="<?php echo app_h((string)($_POST['operation_name'] ?? '')); ?>" placeholder="<?php echo app_h(pricing_text($isEnglish, 'مثال: طباعة علبة كرتون', 'Example: Folding carton printing')); ?>">
                            </div>
                            <div class="pricing-field">
                                <label><?php echo app_h(pricing_text($isEnglish, 'الكمية المطلوبة', 'Required Quantity')); ?></label>
                                <input class="pricing-input" type="number" name="qty" min="1" step="1" value="<?php echo app_h((string)($_POST['qty'] ?? '')); ?>">
                            </div>
                            <div class="pricing-field">
                                <label><?php echo app_h(pricing_text($isEnglish, 'وحدة القياس', 'Unit Label')); ?></label>
                                <input class="pricing-input" name="unit_label" value="<?php echo app_h((string)($_POST['unit_label'] ?? pricing_text($isEnglish, 'قطعة', 'piece'))); ?>" placeholder="<?php echo app_h(pricing_text($isEnglish, 'قطعة / كرتونة / ليبل ...', 'piece / carton / label ...')); ?>">
                            </div>
                            <div class="pricing-field-full">
                                <label><?php echo app_h(pricing_text($isEnglish, 'المواصفات العامة', 'General Specifications')); ?></label>
                                <textarea class="pricing-textarea" name="specs" placeholder="<?php echo app_h(pricing_text($isEnglish, 'اكتب المواصفات الأساسية التي ستظهر مختصرة داخل عرض السعر.', 'Write the core specs that should appear in the quotation.')); ?>"><?php echo app_h((string)($_POST['specs'] ?? '')); ?></textarea>
                            </div>
                        </div>
                    </div>
                </section>

                <section class="pricing-section">
                    <div class="pricing-section-head">
                        <div>
                            <h3><?php echo app_h(pricing_text($isEnglish, '2. التصميم والمحتوى', '2. Design & Creative')); ?></h3>
                            <span><?php echo app_h(pricing_text($isEnglish, 'احتسب أي تكلفة خاصة بالتصميم أو المعاينة أو تجهيز المحتوى قبل التصنيع.', 'Account for any design, proofing, or creative preparation cost before production.')); ?></span>
                        </div>
                    </div>
                    <div class="pricing-section-body">
                        <div class="pricing-fields">
                            <div class="pricing-field-full">
                                <label class="pricing-check">
                                    <input type="checkbox" name="design_required" value="1" <?php echo !empty($_POST['design_required']) ? 'checked' : ''; ?>>
                                    <span><?php echo app_h(pricing_text($isEnglish, 'العملية تحتاج تصميم/مراجعة فنية', 'This job needs design/creative handling')); ?></span>
                                </label>
                            </div>
                            <div class="pricing-field">
                                <label><?php echo app_h(pricing_text($isEnglish, 'ساعات التصميم', 'Design Hours')); ?></label>
                                <input class="pricing-input" type="number" min="0" step="0.25" name="design_hours" value="<?php echo app_h((string)($_POST['design_hours'] ?? '')); ?>">
                            </div>
                            <div class="pricing-field">
                                <label><?php echo app_h(pricing_text($isEnglish, 'سعر الساعة', 'Hourly Rate')); ?></label>
                                <input class="pricing-input" type="number" min="0" step="0.01" name="design_hour_rate" value="<?php echo app_h((string)($_POST['design_hour_rate'] ?? '')); ?>">
                            </div>
                            <div class="pricing-field">
                                <label><?php echo app_h(pricing_text($isEnglish, 'تكلفة تجهيز/إبداع ثابتة', 'Flat Creative Fee')); ?></label>
                                <input class="pricing-input" type="number" min="0" step="0.01" name="creative_flat_cost" value="<?php echo app_h((string)($_POST['creative_flat_cost'] ?? '')); ?>">
                            </div>
                            <div class="pricing-field">
                                <label><?php echo app_h(pricing_text($isEnglish, 'تكلفة بروفة/ماكيت', 'Proof / Mockup Cost')); ?></label>
                                <input class="pricing-input" type="number" min="0" step="0.01" name="proof_cost" value="<?php echo app_h((string)($_POST['proof_cost'] ?? '')); ?>">
                            </div>
                        </div>
                    </div>
                </section>

                <section class="pricing-section">
                    <div class="pricing-section-head">
                        <div>
                            <h3><?php echo app_h(pricing_text($isEnglish, '3. الورق والتجهيز قبل الطباعة', '3. Paper & Prepress')); ?></h3>
                            <span><?php echo app_h(pricing_text($isEnglish, 'الورق يعتمد على سعر الطن في الإعدادات. المقاس والجراماج يُدخلان هنا وقت التسعير.', 'Paper uses ton price from settings. Sheet size and GSM are entered here at pricing time.')); ?></span>
                        </div>
                    </div>
                    <div class="pricing-section-body">
                        <div class="pricing-fields">
                            <div class="pricing-field">
                                <label><?php echo app_h(pricing_text($isEnglish, 'نوع الورق', 'Paper Type')); ?></label>
                                <select name="paper_type" class="pricing-select" required>
                                    <option value=""><?php echo app_h(pricing_text($isEnglish, '-- اختر نوع الورق --', '-- Select paper type --')); ?></option>
                                    <?php foreach ($paperTypes as $paper): $paperName = (string)($paper['name'] ?? ''); ?>
                                        <option value="<?php echo app_h($paperName); ?>" <?php echo ((string)($_POST['paper_type'] ?? '') === $paperName) ? 'selected' : ''; ?>>
                                            <?php echo app_h($paperName); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="pricing-field">
                                <label><?php echo app_h(pricing_text($isEnglish, 'مقاس فرخ شائع', 'Preset Sheet Size')); ?></label>
                                <select name="sheet_size_preset" class="pricing-select">
                                    <option value=""><?php echo app_h(pricing_text($isEnglish, '-- مقاس مخصص --', '-- Custom size --')); ?></option>
                                    <?php foreach ($sizePresets as $preset => $dims): ?>
                                        <option value="<?php echo app_h($preset); ?>" <?php echo ((string)($_POST['sheet_size_preset'] ?? '') === $preset) ? 'selected' : ''; ?>>
                                            <?php echo app_h($preset); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="pricing-field">
                                <label><?php echo app_h(pricing_text($isEnglish, 'عرض الفرخ (سم)', 'Sheet Width (cm)')); ?></label>
                                <input class="pricing-input" type="number" min="0" step="0.01" name="paper_width_cm" value="<?php echo app_h((string)($_POST['paper_width_cm'] ?? '')); ?>">
                            </div>
                            <div class="pricing-field">
                                <label><?php echo app_h(pricing_text($isEnglish, 'طول الفرخ (سم)', 'Sheet Height (cm)')); ?></label>
                                <input class="pricing-input" type="number" min="0" step="0.01" name="paper_height_cm" value="<?php echo app_h((string)($_POST['paper_height_cm'] ?? '')); ?>">
                            </div>
                            <div class="pricing-field">
                                <label><?php echo app_h(pricing_text($isEnglish, 'الجراماج', 'GSM')); ?></label>
                                <input class="pricing-input" type="number" min="0" step="0.01" name="paper_gsm" value="<?php echo app_h((string)($_POST['paper_gsm'] ?? '')); ?>">
                            </div>
                            <div class="pricing-field">
                                <label><?php echo app_h(pricing_text($isEnglish, 'مصاريف تجهيز قبل الطباعة', 'Prepress Setup Cost')); ?></label>
                                <input class="pricing-input" type="number" min="0" step="0.01" name="prepress_setup_cost" value="<?php echo app_h((string)($_POST['prepress_setup_cost'] ?? '')); ?>">
                            </div>
                            <div class="pricing-field">
                                <label><?php echo app_h(pricing_text($isEnglish, 'مصاريف تجهيز/قص ثابتة', 'Cutting / Prep Cost')); ?></label>
                                <input class="pricing-input" type="number" min="0" step="0.01" name="cutting_setup_cost" value="<?php echo app_h((string)($_POST['cutting_setup_cost'] ?? '')); ?>">
                            </div>
                        </div>
                    </div>
                </section>

                <section class="pricing-section">
                    <div class="pricing-section-head">
                        <div>
                            <h3><?php echo app_h(pricing_text($isEnglish, '4. الطباعة', '4. Printing')); ?></h3>
                            <span><?php echo app_h(pricing_text($isEnglish, 'احتساب السحبات والتراج والزنكات والحد الأدنى للتشغيل حسب الماكينة.', 'Calculate impressions, trays, plates, and minimum run logic by machine.')); ?></span>
                        </div>
                    </div>
                    <div class="pricing-section-body">
                        <div class="pricing-fields">
                            <div class="pricing-field">
                                <label><?php echo app_h(pricing_text($isEnglish, 'نوع الماكينة', 'Printing Machine')); ?></label>
                                <select name="machine_type" class="pricing-select" required>
                                    <option value=""><?php echo app_h(pricing_text($isEnglish, '-- اختر الماكينة --', '-- Select machine --')); ?></option>
                                    <?php foreach ($machines as $machine): $machineKey = (string)($machine['key'] ?? ''); ?>
                                        <?php $machineLabel = $isEnglish ? (string)($machine['label_en'] ?? $machineKey) : (string)($machine['label_ar'] ?? $machineKey); ?>
                                        <?php $machineMeta = pricing_text($isEnglish, 'سعر التراج', 'Tray') . ': ' . pricing_currency(pricing_float($machine['price_per_tray'] ?? 0)) . ' / ' . pricing_sheet_class_label($isEnglish, (string)($machine['sheet_class'] ?? 'full')); ?>
                                        <option value="<?php echo app_h($machineKey); ?>" <?php echo ((string)($_POST['machine_type'] ?? '') === $machineKey) ? 'selected' : ''; ?>>
                                            <?php echo app_h($machineLabel . ' - ' . $machineMeta); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="pricing-field">
                                <label><?php echo app_h(pricing_text($isEnglish, 'نوع الطباعة', 'Print Mode')); ?></label>
                                <select name="print_mode" class="pricing-select">
                                    <option value="single" <?php echo ((string)($_POST['print_mode'] ?? 'single') === 'single') ? 'selected' : ''; ?>><?php echo app_h(pricing_text($isEnglish, 'وجه واحد', 'Single Face')); ?></option>
                                    <option value="double_plates" <?php echo ((string)($_POST['print_mode'] ?? '') === 'double_plates') ? 'selected' : ''; ?>><?php echo app_h(pricing_text($isEnglish, 'وجهين بطقمين زنكات', 'Double Face (Two Plate Sets)')); ?></option>
                                    <option value="work_turn" <?php echo ((string)($_POST['print_mode'] ?? '') === 'work_turn') ? 'selected' : ''; ?>><?php echo app_h(pricing_text($isEnglish, 'وجهين طبع وقلب', 'Work & Turn')); ?></option>
                                </select>
                            </div>
                            <div class="pricing-field">
                                <label><?php echo app_h(pricing_text($isEnglish, 'عدد الألوان', 'Process Colors')); ?></label>
                                <input class="pricing-input" type="number" min="0" step="1" name="colors" value="<?php echo app_h((string)($_POST['colors'] ?? '')); ?>">
                            </div>
                            <div class="pricing-field">
                                <label><?php echo app_h(pricing_text($isEnglish, 'ألوان بانتون', 'Pantone Colors')); ?></label>
                                <input class="pricing-input" type="number" min="0" step="1" name="pantone" value="<?php echo app_h((string)($_POST['pantone'] ?? '')); ?>">
                            </div>
                            <div class="pricing-field">
                                <label><?php echo app_h(pricing_text($isEnglish, 'سعر لون البانتون/تراج', 'Pantone Color / Tray Price')); ?></label>
                                <input class="pricing-input" type="number" min="0" step="0.01" name="pantone_tray_price" value="<?php echo app_h((string)($_POST['pantone_tray_price'] ?? '')); ?>">
                                <div class="pricing-hint"><?php echo app_h(pricing_text($isEnglish, 'اتركه 0 إذا كان سعر تراج الماكينة المختارة يشمل البانتون.', 'Leave 0 if the selected machine tray price already includes Pantone.')); ?></div>
                            </div>
                            <div class="pricing-field">
                                <label><?php echo app_h(pricing_text($isEnglish, 'عدد القطع على شيت الماكينة', 'Pieces per Machine Sheet')); ?></label>
                                <input class="pricing-input" type="number" min="1" step="1" name="sheet_yield_override" value="<?php echo app_h((string)($_POST['sheet_yield_override'] ?? 1)); ?>">
                                <div class="pricing-hint"><?php echo app_h(pricing_text($isEnglish, 'يتم بعد ذلك تحويل سحبات الماكينة إلى أفراخ كاملة للشراء حسب نوع الماكينة: ربع = 4، نصف = 2، كامل = 1.', 'The machine pulls are then converted into purchasable full sheets by machine type: quarter = 4, half = 2, full = 1.')); ?></div>
                            </div>
                            <div class="pricing-field">
                                <label><?php echo app_h(pricing_text($isEnglish, 'الحد الأدنى للتراجات', 'Minimum Trays')); ?></label>
                                <input class="pricing-input" type="number" min="1" step="1" value="<?php echo app_h((string)($machineRow['min_trays'] ?? 1)); ?>" readonly>
                            </div>
                            <div class="pricing-field">
                                <label><?php echo app_h(pricing_text($isEnglish, 'فئة الشيت للماكينة', 'Machine Sheet Class')); ?></label>
                                <input class="pricing-input" value="<?php echo app_h((string)$machineSheetClassLabel); ?>" readonly>
                                <div class="pricing-hint"><?php echo app_h(pricing_text($isEnglish, 'العمليات التكميلية المفعّل لها "حسب حجم الشيت" ستتسعر على أساس هذه الفئة.', 'Sheet-sensitive finishing operations will be priced according to this machine class.')); ?></div>
                            </div>
                            <?php if (!empty($pricingDefaults['book_mode_enabled'])): ?>
                                <div class="pricing-field-full pricing-mode-panel <?php echo $pricingModeValue === 'books' ? '' : 'hidden'; ?>" data-mode-panel="books">
                                    <div class="pricing-op-card">
                                        <div class="pricing-op-title"><strong><?php echo app_h(pricing_text($isEnglish, 'الغلاف', 'Cover')); ?></strong></div>
                                        <div class="pricing-op-grid">
                                            <div class="pricing-field">
                                                <label><?php echo app_h(pricing_text($isEnglish, 'ورق الغلاف', 'Cover Paper')); ?></label>
                                                <select name="book_cover_paper_type" class="pricing-select">
                                                    <option value=""><?php echo app_h(pricing_text($isEnglish, '-- اختر الورق --', '-- Select paper --')); ?></option>
                                                    <?php foreach ($paperTypes as $paper): $paperName = (string)($paper['name'] ?? ''); ?>
                                                        <option value="<?php echo app_h($paperName); ?>" <?php echo ((string)($_POST['book_cover_paper_type'] ?? '') === $paperName) ? 'selected' : ''; ?>><?php echo app_h($paperName); ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="pricing-field">
                                                <label><?php echo app_h(pricing_text($isEnglish, 'عرض الغلاف (سم)', 'Cover Width (cm)')); ?></label>
                                                <input class="pricing-input" type="number" min="0" step="0.01" name="book_cover_width_cm" value="<?php echo app_h((string)($_POST['book_cover_width_cm'] ?? '')); ?>">
                                            </div>
                                            <div class="pricing-field">
                                                <label><?php echo app_h(pricing_text($isEnglish, 'طول الغلاف (سم)', 'Cover Height (cm)')); ?></label>
                                                <input class="pricing-input" type="number" min="0" step="0.01" name="book_cover_height_cm" value="<?php echo app_h((string)($_POST['book_cover_height_cm'] ?? '')); ?>">
                                            </div>
                                            <div class="pricing-field">
                                                <label><?php echo app_h(pricing_text($isEnglish, 'جراماج الغلاف', 'Cover GSM')); ?></label>
                                                <input class="pricing-input" type="number" min="0" step="0.01" name="book_cover_gsm" value="<?php echo app_h((string)($_POST['book_cover_gsm'] ?? '')); ?>">
                                            </div>
                                            <div class="pricing-field">
                                                <label><?php echo app_h(pricing_text($isEnglish, 'ماكينة الغلاف', 'Cover Machine')); ?></label>
                                                <select name="book_cover_machine_type" class="pricing-select">
                                                    <option value=""><?php echo app_h(pricing_text($isEnglish, '-- اختر الماكينة --', '-- Select machine --')); ?></option>
                                                    <?php foreach ($machines as $machine): $machineKey = (string)($machine['key'] ?? ''); $machineLabel = $isEnglish ? (string)($machine['label_en'] ?? $machineKey) : (string)($machine['label_ar'] ?? $machineKey); ?>
                                                        <?php $machineMeta = pricing_text($isEnglish, 'سعر التراج', 'Tray') . ': ' . pricing_currency(pricing_float($machine['price_per_tray'] ?? 0)) . ' / ' . pricing_sheet_class_label($isEnglish, (string)($machine['sheet_class'] ?? 'full')); ?>
                                                        <option value="<?php echo app_h($machineKey); ?>" <?php echo ((string)($_POST['book_cover_machine_type'] ?? '') === $machineKey) ? 'selected' : ''; ?>><?php echo app_h($machineLabel . ' - ' . $machineMeta); ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="pricing-field">
                                                <label><?php echo app_h(pricing_text($isEnglish, 'نوع طباعة الغلاف', 'Cover Print Mode')); ?></label>
                                                <select name="book_cover_print_mode" class="pricing-select">
                                                    <option value="single" <?php echo ((string)($_POST['book_cover_print_mode'] ?? 'single') === 'single') ? 'selected' : ''; ?>><?php echo app_h(pricing_text($isEnglish, 'وجه واحد', 'Single Face')); ?></option>
                                                    <option value="double_plates" <?php echo ((string)($_POST['book_cover_print_mode'] ?? '') === 'double_plates') ? 'selected' : ''; ?>><?php echo app_h(pricing_text($isEnglish, 'وجهين بطقمين زنكات', 'Double Face (Two Plate Sets)')); ?></option>
                                                    <option value="work_turn" <?php echo ((string)($_POST['book_cover_print_mode'] ?? '') === 'work_turn') ? 'selected' : ''; ?>><?php echo app_h(pricing_text($isEnglish, 'وجهين طبع وقلب', 'Work & Turn')); ?></option>
                                                </select>
                                            </div>
                                            <div class="pricing-field">
                                                <label><?php echo app_h(pricing_text($isEnglish, 'ألوان الغلاف', 'Cover Colors')); ?></label>
                                                <input class="pricing-input" type="number" min="0" step="1" name="book_cover_colors" value="<?php echo app_h((string)($_POST['book_cover_colors'] ?? '')); ?>">
                                            </div>
                                            <div class="pricing-field">
                                                <label><?php echo app_h(pricing_text($isEnglish, 'بانتون الغلاف', 'Cover Pantone')); ?></label>
                                                <input class="pricing-input" type="number" min="0" step="1" name="book_cover_pantone" value="<?php echo app_h((string)($_POST['book_cover_pantone'] ?? '')); ?>">
                                            </div>
                                            <div class="pricing-field">
                                                <label><?php echo app_h(pricing_text($isEnglish, 'سعر بانتون الغلاف/تراج', 'Cover Pantone / Tray Price')); ?></label>
                                                <input class="pricing-input" type="number" min="0" step="0.01" name="book_cover_pantone_tray_price" value="<?php echo app_h((string)($_POST['book_cover_pantone_tray_price'] ?? '')); ?>">
                                            </div>
                                            <div class="pricing-field">
                                                <label><?php echo app_h(pricing_text($isEnglish, 'عدد النسخ على فرخ الغلاف', 'Cover Yield per Sheet')); ?></label>
                                                <input class="pricing-input" type="number" min="1" step="1" name="book_cover_sheet_yield" value="<?php echo app_h((string)($_POST['book_cover_sheet_yield'] ?? '1')); ?>">
                                            </div>
                                        </div>
                                    </div>
                                    <div class="pricing-op-card" style="margin-top:12px;">
                                        <div class="pricing-op-title"><strong><?php echo app_h(pricing_text($isEnglish, 'الداخلي', 'Inner Content')); ?></strong></div>
                                        <div class="pricing-op-grid">
                                            <div class="pricing-field">
                                                <label><?php echo app_h(pricing_text($isEnglish, 'ورق الداخلي', 'Inner Paper')); ?></label>
                                                <select name="book_inner_paper_type" class="pricing-select">
                                                    <option value=""><?php echo app_h(pricing_text($isEnglish, '-- اختر الورق --', '-- Select paper --')); ?></option>
                                                    <?php foreach ($paperTypes as $paper): $paperName = (string)($paper['name'] ?? ''); ?>
                                                        <option value="<?php echo app_h($paperName); ?>" <?php echo ((string)($_POST['book_inner_paper_type'] ?? '') === $paperName) ? 'selected' : ''; ?>><?php echo app_h($paperName); ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="pricing-field">
                                                <label><?php echo app_h(pricing_text($isEnglish, 'عرض الداخلي (سم)', 'Inner Width (cm)')); ?></label>
                                                <input class="pricing-input" type="number" min="0" step="0.01" name="book_inner_width_cm" value="<?php echo app_h((string)($_POST['book_inner_width_cm'] ?? '')); ?>">
                                            </div>
                                            <div class="pricing-field">
                                                <label><?php echo app_h(pricing_text($isEnglish, 'طول الداخلي (سم)', 'Inner Height (cm)')); ?></label>
                                                <input class="pricing-input" type="number" min="0" step="0.01" name="book_inner_height_cm" value="<?php echo app_h((string)($_POST['book_inner_height_cm'] ?? '')); ?>">
                                            </div>
                                            <div class="pricing-field">
                                                <label><?php echo app_h(pricing_text($isEnglish, 'جراماج الداخلي', 'Inner GSM')); ?></label>
                                                <input class="pricing-input" type="number" min="0" step="0.01" name="book_inner_gsm" value="<?php echo app_h((string)($_POST['book_inner_gsm'] ?? '')); ?>">
                                            </div>
                                            <div class="pricing-field">
                                                <label><?php echo app_h(pricing_text($isEnglish, 'ماكينة الداخلي', 'Inner Machine')); ?></label>
                                                <select name="book_inner_machine_type" class="pricing-select">
                                                    <option value=""><?php echo app_h(pricing_text($isEnglish, '-- اختر الماكينة --', '-- Select machine --')); ?></option>
                                                    <?php foreach ($machines as $machine): $machineKey = (string)($machine['key'] ?? ''); $machineLabel = $isEnglish ? (string)($machine['label_en'] ?? $machineKey) : (string)($machine['label_ar'] ?? $machineKey); ?>
                                                        <?php $machineMeta = pricing_text($isEnglish, 'سعر التراج', 'Tray') . ': ' . pricing_currency(pricing_float($machine['price_per_tray'] ?? 0)) . ' / ' . pricing_sheet_class_label($isEnglish, (string)($machine['sheet_class'] ?? 'full')); ?>
                                                        <option value="<?php echo app_h($machineKey); ?>" <?php echo ((string)($_POST['book_inner_machine_type'] ?? '') === $machineKey) ? 'selected' : ''; ?>><?php echo app_h($machineLabel . ' - ' . $machineMeta); ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="pricing-field">
                                                <label><?php echo app_h(pricing_text($isEnglish, 'نوع طباعة الداخلي', 'Inner Print Mode')); ?></label>
                                                <select name="book_inner_print_mode" class="pricing-select">
                                                    <option value="single" <?php echo ((string)($_POST['book_inner_print_mode'] ?? '') === 'single') ? 'selected' : ''; ?>><?php echo app_h(pricing_text($isEnglish, 'وجه واحد', 'Single Face')); ?></option>
                                                    <option value="double_plates" <?php echo ((string)($_POST['book_inner_print_mode'] ?? 'double_plates') === 'double_plates') ? 'selected' : ''; ?>><?php echo app_h(pricing_text($isEnglish, 'وجهين بطقمين زنكات', 'Double Face (Two Plate Sets)')); ?></option>
                                                    <option value="work_turn" <?php echo ((string)($_POST['book_inner_print_mode'] ?? '') === 'work_turn') ? 'selected' : ''; ?>><?php echo app_h(pricing_text($isEnglish, 'وجهين طبع وقلب', 'Work & Turn')); ?></option>
                                                </select>
                                            </div>
                                            <div class="pricing-field">
                                                <label><?php echo app_h(pricing_text($isEnglish, 'ألوان الداخلي', 'Inner Colors')); ?></label>
                                                <input class="pricing-input" type="number" min="0" step="1" name="book_inner_colors" value="<?php echo app_h((string)($_POST['book_inner_colors'] ?? '')); ?>">
                                            </div>
                                            <div class="pricing-field">
                                                <label><?php echo app_h(pricing_text($isEnglish, 'بانتون الداخلي', 'Inner Pantone')); ?></label>
                                                <input class="pricing-input" type="number" min="0" step="1" name="book_inner_pantone" value="<?php echo app_h((string)($_POST['book_inner_pantone'] ?? '')); ?>">
                                            </div>
                                            <div class="pricing-field">
                                                <label><?php echo app_h(pricing_text($isEnglish, 'سعر بانتون الداخلي/تراج', 'Inner Pantone / Tray Price')); ?></label>
                                                <input class="pricing-input" type="number" min="0" step="0.01" name="book_inner_pantone_tray_price" value="<?php echo app_h((string)($_POST['book_inner_pantone_tray_price'] ?? '')); ?>">
                                            </div>
                                            <div class="pricing-field">
                                                <label><?php echo app_h(pricing_text($isEnglish, 'عدد الملازم', 'Signatures Count')); ?></label>
                                                <input class="pricing-input" type="number" min="1" step="1" name="signatures_count" value="<?php echo app_h((string)($_POST['signatures_count'] ?? '1')); ?>">
                                            </div>
                                            <div class="pricing-field">
                                                <label><?php echo app_h(pricing_text($isEnglish, 'عدد النسخ على ملزمة/فرخ', 'Copies per Signature Sheet')); ?></label>
                                                <input class="pricing-input" type="number" min="1" step="1" name="book_sheet_yield" value="<?php echo app_h((string)($_POST['book_sheet_yield'] ?? '')); ?>">
                                            </div>
                                            <div class="pricing-field-full">
                                                <div class="pricing-hint"><?php echo app_h(pricing_text($isEnglish, 'زنكات الداخلي تُحسب تلقائياً = عدد الملازم × عدد الألوان لكل وجه × 2 لأن الملزمة وجهين.', 'Inner plates are calculated automatically = signatures count × colors per face × 2 because each signature prints on both faces.')); ?></div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="pricing-op-card" style="margin-top:12px;">
                                        <div class="pricing-op-title"><strong><?php echo app_h(pricing_text($isEnglish, 'التقفيل', 'Binding / Closing')); ?></strong></div>
                                        <div class="pricing-op-grid">
                                            <div class="pricing-field">
                                                <label><?php echo app_h(pricing_text($isEnglish, 'نوع التقفيل', 'Binding Type')); ?></label>
                                                <select name="book_binding_type" class="pricing-select">
                                                    <option value="cut" <?php echo ((string)($_POST['book_binding_type'] ?? 'cut') === 'cut') ? 'selected' : ''; ?>><?php echo app_h(pricing_text($isEnglish, 'بشر', 'Cut')); ?></option>
                                                    <option value="thread" <?php echo ((string)($_POST['book_binding_type'] ?? '') === 'thread') ? 'selected' : ''; ?>><?php echo app_h(pricing_text($isEnglish, 'خيط', 'Thread')); ?></option>
                                                    <option value="cut_thread" <?php echo ((string)($_POST['book_binding_type'] ?? '') === 'cut_thread') ? 'selected' : ''; ?>><?php echo app_h(pricing_text($isEnglish, 'بشر وخيط', 'Cut + Thread')); ?></option>
                                                    <option value="staple" <?php echo ((string)($_POST['book_binding_type'] ?? '') === 'staple') ? 'selected' : ''; ?>><?php echo app_h(pricing_text($isEnglish, 'دبوس', 'Staple')); ?></option>
                                                    <option value="staple_cut" <?php echo ((string)($_POST['book_binding_type'] ?? '') === 'staple_cut') ? 'selected' : ''; ?>><?php echo app_h(pricing_text($isEnglish, 'دبوس وبشر', 'Staple + Cut')); ?></option>
                                                </select>
                                            </div>
                                            <div class="pricing-field">
                                                <label><?php echo app_h(pricing_text($isEnglish, 'تجميع/طي لكل ملزمة', 'Gather/Fold Cost per Signature')); ?></label>
                                                <input class="pricing-input" type="number" min="0" step="0.01" name="book_gather_cost_per_signature" value="<?php echo app_h((string)($_POST['book_gather_cost_per_signature'] ?? ($pricingDefaults['gather_cost_per_signature'] ?? 0))); ?>">
                                            </div>
                                            <div class="pricing-field">
                                                <label><?php echo app_h(pricing_text($isEnglish, 'تكلفة التقفيل لكل نسخة', 'Binding Cost per Copy')); ?></label>
                                                <input class="pricing-input" type="number" min="0" step="0.01" id="book_binding_cost_per_copy" name="book_binding_cost_per_copy" value="<?php echo app_h((string)($_POST['book_binding_cost_per_copy'] ?? $bindingCostDefault)); ?>">
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="pricing-field-full pricing-mode-panel <?php echo $pricingModeValue === 'books' ? 'hidden' : ''; ?>" data-mode-panel="general">
                                    <div class="pricing-hint"><?php echo app_h(pricing_text($isEnglish, 'التراج هنا = كل 1000 شيت مطبوع، ويتم تطبيق الحد الأدنى لعدد التراجات تلقائياً حسب الماكينة المختارة.', 'A tray here equals each 1000 printed sheets, and the machine minimum tray count is applied automatically.')); ?></div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </section>

                <section class="pricing-section">
                    <div class="pricing-section-head">
                        <div>
                            <h3><?php echo app_h(pricing_text($isEnglish, '5. التشطيب', '5. Finishing')); ?></h3>
                            <span><?php echo app_h(pricing_text($isEnglish, 'فعّل فقط العمليات المطلوبة. يمكن استخدام التسعير الافتراضي أو كتابة سعر مخصص.', 'Enable only required operations. Use default pricing or enter a custom price.')); ?></span>
                        </div>
                    </div>
                    <div class="pricing-section-body">
                        <div class="pricing-fields">
                            <?php if (!empty($pricingDefaults['book_mode_enabled'])): ?>
                                <div class="pricing-field-full pricing-mode-panel <?php echo $pricingModeValue === 'books' ? '' : 'hidden'; ?>" data-mode-panel="books">
                                    <div class="pricing-op-card">
                                        <div class="pricing-op-title"><strong><?php echo app_h(pricing_text($isEnglish, 'فنيات الغلاف', 'Cover Finishing')); ?></strong></div>
                                        <div class="pricing-fields">
                                            <?php foreach ($finishOps as $op): $opKey = (string)($op['key'] ?? ''); ?>
                                                <?php if ($opKey === '') { continue; } ?>
                                                <div class="pricing-field-full">
                                                    <div class="pricing-op-card">
                                                        <div class="pricing-op-title">
                                                            <label class="pricing-check" style="flex:1;">
                                                                <input type="checkbox" name="book_cover_finish[<?php echo app_h($opKey); ?>][enabled]" value="1" <?php echo !empty($_POST['book_cover_finish'][$opKey]['enabled']) ? 'checked' : ''; ?>>
                                                                <span><?php echo app_h($isEnglish ? (string)($op['label_en'] ?? $opKey) : (string)($op['label_ar'] ?? $opKey)); ?></span>
                                                            </label>
                                                        </div>
                                                        <div class="pricing-op-grid">
                                                            <div class="pricing-field">
                                                                <label><?php echo app_h(pricing_text($isEnglish, 'طريقة الحساب', 'Pricing Unit')); ?></label>
                                                                <select class="pricing-select" name="book_cover_finish[<?php echo app_h($opKey); ?>][unit]">
                                                                    <?php $unitValue = pricing_normalize_finish_unit((string)($_POST['book_cover_finish'][$opKey]['unit'] ?? ($op['default_unit'] ?? 'piece'))); ?>
                                                                    <option value="piece" <?php echo $unitValue === 'piece' ? 'selected' : ''; ?>><?php echo app_h(pricing_text($isEnglish, 'بالقطعة النهائية', 'Per Finished Piece')); ?></option>
                                                                    <option value="sheet" <?php echo $unitValue === 'sheet' ? 'selected' : ''; ?>><?php echo app_h(pricing_text($isEnglish, 'بالشيت/الفرخ', 'Per Sheet')); ?></option>
                                                                    <option value="tray" <?php echo $unitValue === 'tray' ? 'selected' : ''; ?>><?php echo app_h(pricing_text($isEnglish, 'بالتراج', 'Per Tray')); ?></option>
                                                                </select>
                                                            </div>
                                                            <div class="pricing-field">
                                                                <label><?php echo app_h(pricing_text($isEnglish, 'عدد الأوجه', 'Faces')); ?></label>
                                                                <select class="pricing-select" name="book_cover_finish[<?php echo app_h($opKey); ?>][faces]">
                                                                    <option value="1" <?php echo ((string)($_POST['book_cover_finish'][$opKey]['faces'] ?? '1') === '1') ? 'selected' : ''; ?>><?php echo app_h(pricing_text($isEnglish, 'وجه واحد', 'Single Face')); ?></option>
                                                                    <option value="2" <?php echo ((string)($_POST['book_cover_finish'][$opKey]['faces'] ?? '') === '2') ? 'selected' : ''; ?>><?php echo app_h(pricing_text($isEnglish, 'وجهين', 'Double Face')); ?></option>
                                                                </select>
                                                            </div>
                                                            <div class="pricing-field">
                                                                <label><?php echo app_h(pricing_text($isEnglish, 'سعر مخصص', 'Custom Price')); ?></label>
                                                                <input class="pricing-input" type="number" min="0" step="0.01" name="book_cover_finish[<?php echo app_h($opKey); ?>][price]" value="<?php echo app_h((string)($_POST['book_cover_finish'][$opKey]['price'] ?? '')); ?>">
                                                            </div>
                                                            <?php if (!empty($op['sheet_sensitive'])): ?>
                                                                <div class="pricing-field" style="grid-column: 1 / -1;">
                                                                    <div class="pricing-hint"><?php echo app_h(pricing_text($isEnglish, 'هذه العملية تعتمد على حجم شيت ماكينة الغلاف الحالية: ', 'This operation depends on the current cover machine sheet size: ')) . app_h($coverMachineSheetClassLabel); ?></div>
                                                                </div>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                    <div class="pricing-op-card" style="margin-top:12px;">
                                        <div class="pricing-op-title"><strong><?php echo app_h(pricing_text($isEnglish, 'فنيات الداخلي بعد الطباعة', 'Inner Finishing After Printing')); ?></strong></div>
                                        <div class="pricing-fields">
                                            <?php foreach ($finishOps as $op): $opKey = (string)($op['key'] ?? ''); ?>
                                                <?php if ($opKey === '') { continue; } ?>
                                                <div class="pricing-field-full">
                                                    <div class="pricing-op-card">
                                                        <div class="pricing-op-title">
                                                            <label class="pricing-check" style="flex:1;">
                                                                <input type="checkbox" name="book_inner_finish[<?php echo app_h($opKey); ?>][enabled]" value="1" <?php echo !empty($_POST['book_inner_finish'][$opKey]['enabled']) ? 'checked' : ''; ?>>
                                                                <span><?php echo app_h($isEnglish ? (string)($op['label_en'] ?? $opKey) : (string)($op['label_ar'] ?? $opKey)); ?></span>
                                                            </label>
                                                        </div>
                                                        <div class="pricing-op-grid">
                                                            <div class="pricing-field">
                                                                <label><?php echo app_h(pricing_text($isEnglish, 'طريقة الحساب', 'Pricing Unit')); ?></label>
                                                                <select class="pricing-select" name="book_inner_finish[<?php echo app_h($opKey); ?>][unit]">
                                                                    <?php $unitValue = pricing_normalize_finish_unit((string)($_POST['book_inner_finish'][$opKey]['unit'] ?? ($op['default_unit'] ?? 'piece'))); ?>
                                                                    <option value="piece" <?php echo $unitValue === 'piece' ? 'selected' : ''; ?>><?php echo app_h(pricing_text($isEnglish, 'بالقطعة النهائية', 'Per Finished Piece')); ?></option>
                                                                    <option value="sheet" <?php echo $unitValue === 'sheet' ? 'selected' : ''; ?>><?php echo app_h(pricing_text($isEnglish, 'بالشيت/الفرخ', 'Per Sheet')); ?></option>
                                                                    <option value="tray" <?php echo $unitValue === 'tray' ? 'selected' : ''; ?>><?php echo app_h(pricing_text($isEnglish, 'بالتراج', 'Per Tray')); ?></option>
                                                                </select>
                                                            </div>
                                                            <div class="pricing-field">
                                                                <label><?php echo app_h(pricing_text($isEnglish, 'عدد الأوجه', 'Faces')); ?></label>
                                                                <select class="pricing-select" name="book_inner_finish[<?php echo app_h($opKey); ?>][faces]">
                                                                    <option value="1" <?php echo ((string)($_POST['book_inner_finish'][$opKey]['faces'] ?? '1') === '1') ? 'selected' : ''; ?>><?php echo app_h(pricing_text($isEnglish, 'وجه واحد', 'Single Face')); ?></option>
                                                                    <option value="2" <?php echo ((string)($_POST['book_inner_finish'][$opKey]['faces'] ?? '') === '2') ? 'selected' : ''; ?>><?php echo app_h(pricing_text($isEnglish, 'وجهين', 'Double Face')); ?></option>
                                                                </select>
                                                            </div>
                                                            <div class="pricing-field">
                                                                <label><?php echo app_h(pricing_text($isEnglish, 'سعر مخصص', 'Custom Price')); ?></label>
                                                                <input class="pricing-input" type="number" min="0" step="0.01" name="book_inner_finish[<?php echo app_h($opKey); ?>][price]" value="<?php echo app_h((string)($_POST['book_inner_finish'][$opKey]['price'] ?? '')); ?>">
                                                            </div>
                                                            <?php if (!empty($op['sheet_sensitive'])): ?>
                                                                <div class="pricing-field" style="grid-column: 1 / -1;">
                                                                    <div class="pricing-hint"><?php echo app_h(pricing_text($isEnglish, 'هذه العملية تعتمد على حجم شيت ماكينة الداخلي الحالية: ', 'This operation depends on the current inner machine sheet size: ')) . app_h($innerMachineSheetClassLabel); ?></div>
                                                                </div>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="pricing-field-full pricing-mode-panel <?php echo $pricingModeValue === 'books' ? 'hidden' : ''; ?>" data-mode-panel="general">
                            <?php endif; ?>
                            <?php foreach ($finishOps as $op): $opKey = (string)($op['key'] ?? ''); ?>
                                <?php if ($opKey === '') { continue; } ?>
                                <div class="pricing-field-full">
                                    <div class="pricing-op-card">
                                        <div class="pricing-op-title">
                                            <label class="pricing-check" style="flex:1;">
                                                <input type="checkbox" name="finish[<?php echo app_h($opKey); ?>][enabled]" value="1" <?php echo !empty($_POST['finish'][$opKey]['enabled']) ? 'checked' : ''; ?>>
                                                <span><?php echo app_h($isEnglish ? (string)($op['label_en'] ?? $opKey) : (string)($op['label_ar'] ?? $opKey)); ?></span>
                                            </label>
                                        </div>
                                        <div class="pricing-op-grid">
                                            <div class="pricing-field">
                                                <label><?php echo app_h(pricing_text($isEnglish, 'طريقة الحساب', 'Pricing Unit')); ?></label>
                                                <select class="pricing-select" name="finish[<?php echo app_h($opKey); ?>][unit]">
                                                    <?php $unitValue = pricing_normalize_finish_unit((string)($_POST['finish'][$opKey]['unit'] ?? ($op['default_unit'] ?? 'piece'))); ?>
                                                    <option value="piece" <?php echo $unitValue === 'piece' ? 'selected' : ''; ?>><?php echo app_h(pricing_text($isEnglish, 'بالقطعة النهائية', 'Per Finished Piece')); ?></option>
                                                    <option value="sheet" <?php echo $unitValue === 'sheet' ? 'selected' : ''; ?>><?php echo app_h(pricing_text($isEnglish, 'بالشيت/الفرخ', 'Per Sheet')); ?></option>
                                                    <option value="tray" <?php echo $unitValue === 'tray' ? 'selected' : ''; ?>><?php echo app_h(pricing_text($isEnglish, 'بالتراج', 'Per Tray')); ?></option>
                                                </select>
                                            </div>
                                            <div class="pricing-field">
                                                <label><?php echo app_h(pricing_text($isEnglish, 'عدد الأوجه', 'Faces')); ?></label>
                                                <select class="pricing-select" name="finish[<?php echo app_h($opKey); ?>][faces]">
                                                    <option value="1" <?php echo ((string)($_POST['finish'][$opKey]['faces'] ?? '1') === '1') ? 'selected' : ''; ?>><?php echo app_h(pricing_text($isEnglish, 'وجه واحد', 'Single Face')); ?></option>
                                                    <option value="2" <?php echo ((string)($_POST['finish'][$opKey]['faces'] ?? '') === '2') ? 'selected' : ''; ?>><?php echo app_h(pricing_text($isEnglish, 'وجهين', 'Double Face')); ?></option>
                                                </select>
                                            </div>
                                            <div class="pricing-field">
                                                <label><?php echo app_h(pricing_text($isEnglish, 'سعر مخصص (اختياري)', 'Custom Price (optional)')); ?></label>
                                                <input class="pricing-input" type="number" min="0" step="0.01" name="finish[<?php echo app_h($opKey); ?>][price]" value="<?php echo app_h((string)($_POST['finish'][$opKey]['price'] ?? '')); ?>">
                                            </div>
                                            <?php if (!empty($op['sheet_sensitive'])): ?>
                                                <div class="pricing-field" style="grid-column: 1 / -1;">
                                                    <div class="pricing-hint"><?php echo app_h(pricing_text($isEnglish, 'هذه العملية تعتمد على حجم شيت الماكينة الحالية: ', 'This operation depends on the current machine sheet size: ')) . app_h($machineSheetClassLabel); ?></div>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                            <?php if (!empty($pricingDefaults['book_mode_enabled'])): ?>
                                </div>
                            <?php endif; ?>
                            <?php
                                $customOpNames = $_POST['custom_op_name'] ?? [''];
                                $customOpCosts = $_POST['custom_op_cost'] ?? [''];
                                if (!is_array($customOpNames)) { $customOpNames = ['']; }
                                if (!is_array($customOpCosts)) { $customOpCosts = ['']; }
                                $customRows = [];
                                $customMax = max(count($customOpNames), count($customOpCosts), 1);
                                for ($customIndex = 0; $customIndex < $customMax; $customIndex++) {
                                    $customRows[] = [
                                        'name' => (string)($customOpNames[$customIndex] ?? ''),
                                        'cost' => (string)($customOpCosts[$customIndex] ?? ''),
                                    ];
                                }
                                $lastCustom = end($customRows);
                                if (trim((string)($lastCustom['name'] ?? '')) !== '' || trim((string)($lastCustom['cost'] ?? '')) !== '') {
                                    $customRows[] = ['name' => '', 'cost' => ''];
                                }
                                reset($customRows);
                            ?>
                            <div class="pricing-field-full">
                                <label><?php echo app_h(pricing_text($isEnglish, 'عمليات إضافية غير موجودة في الثوابت', 'Additional Operations Not in Settings')); ?></label>
                                <div class="pricing-extra-ops" id="pricing_extra_ops">
                                    <?php foreach ($customRows as $customRow): ?>
                                        <div class="pricing-extra-op-row">
                                            <input class="pricing-input" name="custom_op_name[]" value="<?php echo app_h((string)$customRow['name']); ?>" placeholder="<?php echo app_h(pricing_text($isEnglish, 'اسم العملية', 'Operation name')); ?>">
                                            <input class="pricing-input" type="number" min="0" step="0.01" name="custom_op_cost[]" value="<?php echo app_h((string)$customRow['cost']); ?>" placeholder="<?php echo app_h(pricing_text($isEnglish, 'التكلفة', 'Cost')); ?>">
                                            <button type="button" class="pricing-extra-remove" onclick="pricingRemoveExtraOp(this)" title="<?php echo app_h(pricing_text($isEnglish, 'حذف البند', 'Remove item')); ?>"><i class="fa-solid fa-xmark"></i></button>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <button type="button" class="pricing-btn-secondary" style="margin-top:10px;" onclick="pricingAddExtraOp()"><i class="fa-solid fa-plus"></i> <?php echo app_h(pricing_text($isEnglish, 'إضافة بند آخر', 'Add another item')); ?></button>
                                <div class="pricing-hint" style="margin-top:8px;"><?php echo app_h(pricing_text($isEnglish, 'هذه البنود تحفظ داخل ملف التسعير وتعود كما هي عند تحميله للتعديل.', 'These items are saved in the pricing file and restored when loaded for editing.')); ?></div>
                            </div>
                        </div>
                    </div>
                </section>

                <section class="pricing-section">
                    <div class="pricing-section-head">
                        <div>
                            <h3><?php echo app_h(pricing_text($isEnglish, '6. التغليف والنقل والهوامش', '6. Packing, Delivery & Margins')); ?></h3>
                            <span><?php echo app_h(pricing_text($isEnglish, 'أضف أي تكلفة لوجستية أو نثرية أو ربح مستهدف قبل إصدار العرض.', 'Add logistics, overhead, and target profit before issuing the quote.')); ?></span>
                        </div>
                    </div>
                    <div class="pricing-section-body">
                        <div class="pricing-fields">
                            <div class="pricing-field">
                                <label><?php echo app_h(pricing_text($isEnglish, 'مصاريف تجهيز ثابتة', 'Fixed Setup Fee')); ?></label>
                                <input class="pricing-input" type="number" min="0" step="0.01" name="setup_fee" value="<?php echo app_h((string)($_POST['setup_fee'] ?? $pricingDefaults['setup_fee'])); ?>">
                            </div>
                            <div class="pricing-field">
                                <label><?php echo app_h(pricing_text($isEnglish, 'نثريات عامة', 'Miscellaneous')); ?></label>
                                <input class="pricing-input" type="number" min="0" step="0.01" name="misc_cost" value="<?php echo app_h((string)($_POST['misc_cost'] ?? $pricingDefaults['misc_cost'])); ?>">
                            </div>
                            <div class="pricing-field">
                                <label><?php echo app_h(pricing_text($isEnglish, 'تكلفة التغليف', 'Packing Cost')); ?></label>
                                <input class="pricing-input" type="number" min="0" step="0.01" name="packaging_cost" value="<?php echo app_h((string)($_POST['packaging_cost'] ?? '')); ?>">
                            </div>
                            <div class="pricing-field">
                                <label><?php echo app_h(pricing_text($isEnglish, 'اختبار لون', 'Color Test Cost')); ?></label>
                                <input class="pricing-input" type="number" min="0" step="0.01" name="color_test_cost" value="<?php echo app_h((string)($_POST['color_test_cost'] ?? $pricingDefaults['color_test_cost'])); ?>">
                            </div>
                            <div class="pricing-field">
                                <label><?php echo app_h(pricing_text($isEnglish, 'نقل داخلي', 'Internal Transport')); ?></label>
                                <input class="pricing-input" type="number" min="0" step="0.01" name="internal_transport_cost" value="<?php echo app_h((string)($_POST['internal_transport_cost'] ?? $pricingDefaults['internal_transport_cost'])); ?>">
                            </div>
                            <div class="pricing-field">
                                <label><?php echo app_h(pricing_text($isEnglish, 'تكلفة التحميل/التجهيز للشحن', 'Handling / Loading Cost')); ?></label>
                                <input class="pricing-input" type="number" min="0" step="0.01" name="loading_cost" value="<?php echo app_h((string)($_POST['loading_cost'] ?? '')); ?>">
                            </div>
                            <div class="pricing-field">
                                <label><?php echo app_h(pricing_text($isEnglish, 'تكلفة النقل والتسليم', 'Delivery Cost')); ?></label>
                                <input class="pricing-input" type="number" min="0" step="0.01" name="delivery_cost" value="<?php echo app_h((string)($_POST['delivery_cost'] ?? '')); ?>">
                            </div>
                            <div class="pricing-field-full">
                                <label><?php echo app_h(pricing_text($isEnglish, 'ملاحظات تظهر في عرض السعر', 'Notes Appearing in the Quote')); ?></label>
                                <textarea class="pricing-textarea" name="notes" placeholder="<?php echo app_h(pricing_text($isEnglish, 'ملاحظات مختصرة للعميل: مدة التنفيذ، الاستبعاد، أو أي تنبيه مهم.', 'Client-facing notes: lead time, exclusions, or any important note.')); ?>"><?php echo app_h((string)($_POST['notes'] ?? '')); ?></textarea>
                            </div>
                        </div>
                    </div>
                </section>
            </div>

            <aside class="pricing-summary">
                <div class="pricing-summary-card">
                    <h3><?php echo app_h(pricing_text($isEnglish, 'ملخص التكلفة المرحلي', 'Stage Cost Summary')); ?></h3>
                    <div class="pricing-stage-list">
                        <?php foreach ($calc['stage_rows'] as $row): ?>
                            <div class="pricing-stage-row">
                                <span><?php echo app_h((string)$row['label']); ?></span>
                                <span><?php echo app_h(pricing_currency((float)$row['value'])); ?></span>
                            </div>
                        <?php endforeach; ?>
        <?php if (!empty($calc['finishing_rows'])): ?>
            <?php foreach ($calc['finishing_rows'] as $finishRow): ?>
                <div class="pricing-stage-row">
                    <span><?php echo app_h((string)$finishRow['label']); ?></span>
                    <span><?php echo app_h(pricing_currency((float)$finishRow['value'])); ?></span>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
        <?php if (!empty($calc['printing_rows'])): ?>
            <?php foreach ($calc['printing_rows'] as $printRow): ?>
                <div class="pricing-stage-row">
                    <span>
                        <?php echo app_h((string)$printRow['label']); ?>
                        <?php if (!empty($printRow['meta'])): ?>
                            <div class="pricing-hint" style="margin-top:6px;"><?php echo app_h((string)$printRow['meta']); ?></div>
                        <?php endif; ?>
                    </span>
                    <span><?php echo app_h(pricing_currency((float)$printRow['value'])); ?></span>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
                        <div class="pricing-stage-row">
                            <span><?php echo app_h(pricing_text($isEnglish, 'إجمالي قبل الربح', 'Subtotal Before Profit')); ?></span>
                            <span><?php echo app_h(pricing_currency((float)$calc['subtotal'])); ?></span>
                        </div>
                        <div class="pricing-stage-row">
                            <span><?php echo app_h(pricing_text($isEnglish, 'الربح', 'Profit')); ?></span>
                            <span><?php echo app_h(pricing_currency((float)$calc['profit_cost'])); ?></span>
                        </div>
                        <div class="pricing-stage-row total">
                            <span><?php echo app_h(pricing_text($isEnglish, 'الإجمالي النهائي', 'Final Total')); ?></span>
                            <span><?php echo app_h(pricing_currency((float)$calc['total'])); ?></span>
                        </div>
                    </div>

                    <div class="pricing-metrics">
                        <?php if ($bookMode): ?>
                            <div class="pricing-metric">
                                <small><?php echo app_h(pricing_text($isEnglish, 'ورق الغلاف / سعر الطن', 'Cover Paper / Ton Price')); ?></small>
                                <strong><?php echo app_h((string)$calc['cover_paper_name']); ?> - <?php echo app_h(pricing_currency((float)$calc['cover_paper_ton_price'])); ?></strong>
                            </div>
                            <div class="pricing-metric">
                                <small><?php echo app_h(pricing_text($isEnglish, 'مقاس الغلاف / الجراماج', 'Cover Size / GSM')); ?></small>
                                <strong><?php echo app_h(pricing_currency((float)$calc['cover_sheet_width_cm'])); ?> × <?php echo app_h(pricing_currency((float)$calc['cover_sheet_height_cm'])); ?> / <?php echo app_h(pricing_currency((float)$calc['cover_sheet_gsm'])); ?></strong>
                            </div>
                            <div class="pricing-metric">
                                <small><?php echo app_h(pricing_text($isEnglish, 'أفراخ الغلاف / بعد الهالك', 'Cover Sheets / After Waste')); ?></small>
                                <strong><?php echo (int)$calc['cover_sheets_required']; ?> / <?php echo (int)$calc['cover_sheets_with_waste']; ?></strong>
                            </div>
                            <div class="pricing-metric">
                                <small><?php echo app_h(pricing_text($isEnglish, 'تكلفة فرخ الغلاف', 'Cover Sheet Cost')); ?></small>
                                <strong><?php echo app_h(pricing_currency((float)$calc['cover_sheet_cost'])); ?></strong>
                            </div>
                            <div class="pricing-metric">
                                <small><?php echo app_h(pricing_text($isEnglish, 'ورق الداخلي / سعر الطن', 'Inner Paper / Ton Price')); ?></small>
                                <strong><?php echo app_h((string)$calc['inner_paper_name']); ?> - <?php echo app_h(pricing_currency((float)$calc['inner_paper_ton_price'])); ?></strong>
                            </div>
                            <div class="pricing-metric">
                                <small><?php echo app_h(pricing_text($isEnglish, 'مقاس الداخلي / الجراماج', 'Inner Size / GSM')); ?></small>
                                <strong><?php echo app_h(pricing_currency((float)$calc['inner_sheet_width_cm'])); ?> × <?php echo app_h(pricing_currency((float)$calc['inner_sheet_height_cm'])); ?> / <?php echo app_h(pricing_currency((float)$calc['inner_sheet_gsm'])); ?></strong>
                            </div>
                            <div class="pricing-metric">
                                <small><?php echo app_h(pricing_text($isEnglish, 'أفراخ الداخلي / بعد الهالك', 'Inner Sheets / After Waste')); ?></small>
                                <strong><?php echo (int)$calc['inner_sheets_required']; ?> / <?php echo (int)$calc['inner_sheets_with_waste']; ?></strong>
                            </div>
                            <div class="pricing-metric">
                                <small><?php echo app_h(pricing_text($isEnglish, 'تكلفة فرخ الداخلي', 'Inner Sheet Cost')); ?></small>
                                <strong><?php echo app_h(pricing_currency((float)$calc['inner_sheet_cost'])); ?></strong>
                            </div>
                        <?php else: ?>
                            <div class="pricing-metric">
                                <small><?php echo app_h(pricing_text($isEnglish, 'نوع الطباعة', 'Print Mode')); ?></small>
                                <strong><?php echo app_h((string)$calc['print_mode_label']); ?></strong>
                            </div>
                            <div class="pricing-metric">
                                <small><?php echo app_h(pricing_text($isEnglish, 'سحبات الماكينة المطلوبة', 'Required Machine Pulls')); ?></small>
                                <strong><?php echo (int)$calc['machine_sheets_required']; ?></strong>
                            </div>
                            <div class="pricing-metric">
                                <small><?php echo app_h(pricing_text($isEnglish, 'معامل تحويل الماكينة', 'Machine Conversion Divisor')); ?></small>
                                <strong><?php echo (int)$calc['machine_sheet_divisor']; ?></strong>
                            </div>
                            <div class="pricing-metric">
                                <small><?php echo app_h(pricing_text($isEnglish, 'هالك السحبات', 'Waste Pulls')); ?></small>
                                <strong><?php echo (int)$calc['waste_machine_sheets']; ?></strong>
                            </div>
                            <div class="pricing-metric">
                                <small><?php echo app_h(pricing_text($isEnglish, 'نوع الورق / سعر الطن', 'Paper Type / Ton Price')); ?></small>
                                <strong><?php echo app_h((string)$calc['paper_name']); ?> - <?php echo app_h(pricing_currency((float)$calc['paper_ton_price'])); ?></strong>
                            </div>
                            <div class="pricing-metric">
                                <small><?php echo app_h(pricing_text($isEnglish, 'المقاس / الجراماج', 'Sheet Size / GSM')); ?></small>
                                <strong><?php echo app_h(pricing_currency((float)$calc['sheet_width_cm'])); ?> × <?php echo app_h(pricing_currency((float)$calc['sheet_height_cm'])); ?> / <?php echo app_h(pricing_currency((float)$calc['sheet_gsm'])); ?></strong>
                            </div>
                        <?php endif; ?>
                        <?php if ($bookMode): ?>
                            <div class="pricing-metric">
                                <small><?php echo app_h(pricing_text($isEnglish, 'إجمالي الغلاف', 'Cover Total')); ?></small>
                                <strong><?php echo app_h(pricing_currency((float)(($calc['cover_breakdown']['paper_cost'] ?? 0) + ($calc['cover_breakdown']['prepress_cost'] ?? 0) + ($calc['cover_breakdown']['printing_cost'] ?? 0) + ($calc['cover_breakdown']['finishing_cost'] ?? 0)))); ?></strong>
                            </div>
                            <div class="pricing-metric">
                                <small><?php echo app_h(pricing_text($isEnglish, 'إجمالي الداخلي', 'Inner Total')); ?></small>
                                <strong><?php echo app_h(pricing_currency((float)(($calc['inner_breakdown']['paper_cost'] ?? 0) + ($calc['inner_breakdown']['prepress_cost'] ?? 0) + ($calc['inner_breakdown']['printing_cost'] ?? 0) + ($calc['inner_breakdown']['finishing_cost'] ?? 0)))); ?></strong>
                            </div>
                            <div class="pricing-metric">
                                <small><?php echo app_h(pricing_text($isEnglish, 'زنكات الغلاف', 'Cover Plates')); ?></small>
                                <strong><?php echo (int)($calc['cover_breakdown']['plates'] ?? 0); ?></strong>
                            </div>
                            <div class="pricing-metric">
                                <small><?php echo app_h(pricing_text($isEnglish, 'زنكات الداخلي', 'Inner Plates')); ?></small>
                                <strong><?php echo (int)($calc['inner_breakdown']['plates'] ?? 0); ?></strong>
                            </div>
                            <div class="pricing-metric">
                                <small><?php echo app_h(pricing_text($isEnglish, 'تقفيل الكتاب/المجلة', 'Binding Type')); ?></small>
                                <strong><?php echo app_h((string)($bindingTypeLabel ?? '')); ?></strong>
                            </div>
                            <div class="pricing-metric">
                                <small><?php echo app_h(pricing_text($isEnglish, 'تكلفة التقفيل', 'Binding Cost')); ?></small>
                                <strong><?php echo app_h(pricing_currency((float)$calc['book_bind_cost'])); ?></strong>
                            </div>
                        <?php endif; ?>
                        <div class="pricing-metric">
                            <small><?php echo app_h(pricing_text($isEnglish, 'تكلفة الفرخ', 'Cost per Sheet')); ?></small>
                            <strong><?php echo app_h(pricing_currency((float)$calc['sheet_cost'])); ?></strong>
                        </div>
                            <div class="pricing-metric">
                                <small><?php echo app_h(pricing_text($isEnglish, 'عدد القطع على شيت الماكينة', 'Pieces per Machine Sheet')); ?></small>
                                <strong><?php echo (int)$calc['sheet_yield']; ?></strong>
                            </div>
                            <div class="pricing-metric">
                                <small><?php echo app_h(pricing_text($isEnglish, 'الأفراخ الكاملة المطلوبة', 'Required Full Sheets')); ?></small>
                                <strong><?php echo (int)$calc['sheets_required']; ?></strong>
                            </div>
                        <div class="pricing-metric">
                            <small><?php echo app_h(pricing_text($isEnglish, 'الأفراخ الكاملة بعد الهالك', 'Full Sheets After Waste')); ?></small>
                            <strong><?php echo (int)$calc['sheets_with_waste']; ?></strong>
                        </div>
                        <div class="pricing-metric">
                            <small><?php echo app_h(pricing_text($isEnglish, 'إجمالي سحبات الماكينة', 'Total Machine Pulls')); ?></small>
                            <strong><?php echo (int)$calc['impressions']; ?></strong>
                        </div>
                        <div class="pricing-metric">
                            <small><?php echo app_h(pricing_text($isEnglish, 'عدد التراجات', 'Trays')); ?></small>
                            <strong><?php echo (int)$calc['trays']; ?></strong>
                        </div>
                        <div class="pricing-metric">
                            <small><?php echo app_h(pricing_text($isEnglish, 'الزنكات', 'Plates Count')); ?></small>
                            <strong><?php echo (int)$calc['plates_count']; ?></strong>
                        </div>
                        <div class="pricing-metric">
                            <small><?php echo app_h(pricing_text($isEnglish, 'مجموع الألوان الفعلي', 'Total Color Sets')); ?></small>
                            <strong><?php echo (int)$calc['total_color_sets']; ?></strong>
                        </div>
                    </div>

                    <div class="pricing-footer-actions">
                        <label class="pricing-currency-chip">
                            <span><?php echo app_h(pricing_text($isEnglish, 'عملة عرض السعر', 'Quote Currency')); ?></span>
                            <select class="pricing-select" name="quote_currency_code" id="pricing_quote_currency" style="min-width:170px;">
                                <?php foreach ($quoteCurrencyOptions as $currencyCode => $currencyMeta): ?>
                                    <option value="<?php echo app_h($currencyCode); ?>" data-rate="<?php echo app_h((string)($currencyMeta['rate_to_base'] ?? 1)); ?>" <?php echo $currencyCode === $selectedQuoteCurrency ? 'selected' : ''; ?>><?php echo app_h($currencyCode . ' - ' . (string)($currencyMeta['label_ar'] ?? $currencyCode)); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <small class="pricing-converted-total" id="pricing_converted_total"></small>
                        </label>
                        <button class="pricing-btn" type="submit" name="action" value="calc"><?php echo app_h(pricing_text($isEnglish, 'حساب التكلفة', 'Calculate')); ?></button>
                        <button class="pricing-btn-secondary" type="submit" name="action" value="save_pricing_record"><?php echo app_h(pricing_text($isEnglish, 'حفظ ملف التسعير', 'Save Pricing File')); ?></button>
                        <button class="pricing-btn-secondary" type="submit" name="action" value="save_quote"><?php echo app_h(pricing_text($isEnglish, 'إنشاء عرض سعر', 'Create Quote')); ?></button>
                        <?php if (app_user_can('jobs.create') || app_user_can('jobs.manage_all')): ?>
                            <button class="pricing-btn-secondary" type="submit" name="action" value="save_job"><?php echo app_h(pricing_text($isEnglish, 'إنشاء أمر شغل', 'Create Work Order')); ?></button>
                        <?php endif; ?>
                        <button class="pricing-btn-secondary" type="submit" name="action" value="save_pricing_record_print"><?php echo app_h(pricing_text($isEnglish, 'طباعة / PDF', 'Print / PDF')); ?></button>
                        <a class="pricing-btn-secondary" href="pricing_records.php"><?php echo app_h(pricing_text($isEnglish, 'ملفات التسعير', 'Pricing Files')); ?></a>
                    </div>
                </div>
            </aside>
        </div>
    </form>
</div>

<div class="pricing-print-document" aria-hidden="true">
    <div class="pricing-print-sheet">
        <div class="pricing-print-head">
            <div>
                <h1 class="pricing-print-title"><?php echo app_h(pricing_text($isEnglish, 'ملخص تسعير الطباعة', 'Print Pricing Summary')); ?></h1>
                <div class="pricing-print-subtitle"><?php echo app_h((string)$appName); ?></div>
            </div>
            <img src="<?php echo app_h($appLogo); ?>" alt="<?php echo app_h((string)$appName); ?>" class="pricing-print-logo">
        </div>
        <div class="pricing-print-body">
            <div class="pricing-print-grid">
                <div class="pricing-print-card">
                    <small><?php echo app_h(pricing_text($isEnglish, 'العميل', 'Client')); ?></small>
                    <strong><?php echo app_h($pricingClientName !== '' ? $pricingClientName : pricing_text($isEnglish, 'غير محدد', 'Not set')); ?></strong>
                </div>
                <div class="pricing-print-card">
                    <small><?php echo app_h(pricing_text($isEnglish, 'العملية', 'Operation')); ?></small>
                    <strong><?php echo app_h((string)($calc['job_title'] ?? '')); ?></strong>
                </div>
                <div class="pricing-print-card">
                    <small><?php echo app_h(pricing_text($isEnglish, 'الهاتف', 'Phone')); ?></small>
                    <strong><?php echo app_h($pricingClientPhone !== '' ? $pricingClientPhone : '-'); ?></strong>
                </div>
                <div class="pricing-print-card">
                    <small><?php echo app_h(pricing_text($isEnglish, 'تاريخ الإصدار', 'Issue Date')); ?></small>
                    <strong><?php echo app_h(date('Y-m-d')); ?></strong>
                </div>
            </div>

            <table class="pricing-print-table">
                <thead>
                    <tr>
                        <th><?php echo app_h(pricing_text($isEnglish, 'البند', 'Item')); ?></th>
                        <th><?php echo app_h(pricing_text($isEnglish, 'القيمة', 'Value')); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($calc['stage_rows'] as $row): ?>
                        <tr>
                            <td><?php echo app_h((string)$row['label']); ?></td>
                            <td><?php echo app_h(pricing_currency((float)$row['value'])); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php foreach ($calc['printing_rows'] as $row): ?>
                        <tr>
                            <td><?php echo app_h((string)$row['label']); ?></td>
                            <td><?php echo app_h(pricing_currency((float)$row['value'])); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php foreach ($calc['finishing_rows'] as $row): ?>
                        <tr>
                            <td><?php echo app_h((string)$row['label']); ?></td>
                            <td><?php echo app_h(pricing_currency((float)$row['value'])); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <tr>
                        <td><?php echo app_h(pricing_text($isEnglish, 'الربح', 'Profit')); ?></td>
                        <td><?php echo app_h(pricing_currency((float)$calc['profit_cost'])); ?></td>
                    </tr>
                </tbody>
            </table>

            <div class="pricing-print-total">
                <span><?php echo app_h(pricing_text($isEnglish, 'الإجمالي النهائي', 'Final Total')); ?></span>
                <span><?php echo app_h(pricing_currency((float)$calc['total'])); ?></span>
            </div>

            <?php $printNotes = trim((string)($_POST['notes'] ?? '')); ?>
            <?php if ($printNotes !== ''): ?>
                <div class="pricing-print-notes">
                    <strong><?php echo app_h(pricing_text($isEnglish, 'ملاحظات', 'Notes')); ?></strong><br>
                    <?php echo nl2br(app_h($printNotes)); ?>
                </div>
            <?php endif; ?>

            <?php if ($outputShowFooter && !empty($footerLines)): ?>
                <div class="pricing-print-footer">
                    <?php foreach ($footerLines as $line): ?>
                        <p><?php echo app_h((string)$line); ?></p>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
(function () {
    const modeInput = document.getElementById('pricing_mode');
    const modeTabs = Array.from(document.querySelectorAll('.pricing-mode-tab'));
    const modePanels = Array.from(document.querySelectorAll('[data-mode-panel]'));
    const bindingTypeSelect = document.querySelector('select[name="book_binding_type"]');
    const bindingCostInput = document.getElementById('book_binding_cost_per_copy');
    const bindingDefaults = <?php echo json_encode($pricingBindingCosts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?> || {};
    if (!modeInput || !modeTabs.length) {
        if (bindingTypeSelect && bindingCostInput) {
            const fillBindingDefault = () => {
                const key = bindingTypeSelect.value || 'cut';
                if (bindingCostInput.value === '' || bindingCostInput.dataset.autofill === '1') {
                    bindingCostInput.value = bindingDefaults[key] || 0;
                    bindingCostInput.dataset.autofill = '1';
                }
            };
            bindingTypeSelect.addEventListener('change', fillBindingDefault);
            bindingCostInput.addEventListener('input', () => { bindingCostInput.dataset.autofill = '0'; });
            fillBindingDefault();
        }
        return;
    }

    function renderMode(mode) {
        modeInput.value = mode;
        modeTabs.forEach((tab) => {
            tab.classList.toggle('active', (tab.getAttribute('data-mode') || 'general') === mode);
        });
        modePanels.forEach((panel) => {
            const panelMode = panel.getAttribute('data-mode-panel') || 'general';
            panel.classList.toggle('hidden', panelMode !== mode);
            panel.querySelectorAll('input, select, textarea').forEach((field) => {
                if (field === modeInput) {
                    return;
                }
                if (field.type === 'checkbox' && field.disabled) {
                    return;
                }
                field.disabled = panelMode !== mode;
            });
        });
    }

    modeTabs.forEach((tab) => {
        tab.addEventListener('click', () => renderMode(tab.getAttribute('data-mode') || 'general'));
    });

    renderMode(modeInput.value || 'general');

    if (bindingTypeSelect && bindingCostInput) {
        const fillBindingDefault = () => {
            const key = bindingTypeSelect.value || 'cut';
            if (bindingCostInput.value === '' || bindingCostInput.dataset.autofill === '1') {
                bindingCostInput.value = bindingDefaults[key] || 0;
                bindingCostInput.dataset.autofill = '1';
            }
        };
        bindingTypeSelect.addEventListener('change', fillBindingDefault);
        bindingCostInput.addEventListener('input', () => { bindingCostInput.dataset.autofill = '0'; });
        fillBindingDefault();
    }
})();

function pricingAddExtraOp() {
    const wrap = document.getElementById('pricing_extra_ops');
    if (!wrap) return;
    const row = document.createElement('div');
    row.className = 'pricing-extra-op-row';
    row.innerHTML = `
        <input class="pricing-input" name="custom_op_name[]" placeholder="<?php echo app_h(pricing_text($isEnglish, 'اسم العملية', 'Operation name')); ?>">
        <input class="pricing-input" type="number" min="0" step="0.01" name="custom_op_cost[]" placeholder="<?php echo app_h(pricing_text($isEnglish, 'التكلفة', 'Cost')); ?>">
        <button type="button" class="pricing-extra-remove" onclick="pricingRemoveExtraOp(this)" title="<?php echo app_h(pricing_text($isEnglish, 'حذف البند', 'Remove item')); ?>"><i class="fa-solid fa-xmark"></i></button>
    `;
    wrap.appendChild(row);
    const firstInput = row.querySelector('input');
    if (firstInput) firstInput.focus();
}

function pricingRemoveExtraOp(button) {
    const wrap = document.getElementById('pricing_extra_ops');
    const row = button ? button.closest('.pricing-extra-op-row') : null;
    if (!wrap || !row) return;
    if (wrap.querySelectorAll('.pricing-extra-op-row').length <= 1) {
        row.querySelectorAll('input').forEach((input) => { input.value = ''; });
        return;
    }
    row.remove();
}

(function () {
    const select = document.getElementById('pricing_quote_currency');
    const output = document.getElementById('pricing_converted_total');
    if (!select || !output) return;
    const baseTotal = <?php echo json_encode((float)($calc['total'] ?? 0)); ?>;
    const baseCurrency = <?php echo json_encode($defaultQuoteCurrency); ?>;
    function money(value, code) {
        return Number(value || 0).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + ' ' + code;
    }
    function renderConvertedTotal() {
        const option = select.options[select.selectedIndex];
        const code = select.value || baseCurrency;
        const rate = Number((option && option.dataset.rate) || 1);
        const converted = rate > 0 ? (baseTotal / rate) : baseTotal;
        output.textContent = code === baseCurrency
            ? 'إجمالي عرض السعر: ' + money(baseTotal, code)
            : 'إجمالي عرض السعر المحول: ' + money(converted, code) + ' | 1 ' + code + ' = ' + rate.toFixed(4) + ' ' + baseCurrency;
    }
    select.addEventListener('change', renderConvertedTotal);
    renderConvertedTotal();
})();
</script>

<?php require 'footer.php'; ob_end_flush(); ?>
