<?php

if (!function_exists('app_tax_default_types')) {
    function app_tax_default_types(): array
    {
        return [
            [
                'key' => 'vat_14',
                'name' => 'ضريبة القيمة المضافة',
                'name_en' => 'Value Added Tax',
                'category' => 'vat',
                'rate' => 14,
                'mode' => 'add',
                'base' => 'net_after_discount',
                'scopes' => ['sales', 'quotes'],
                'is_active' => 1,
            ],
            [
                'key' => 'withholding_1',
                'name' => 'خصم تحت حساب الضريبة',
                'name_en' => 'Withholding Tax',
                'category' => 'withholding',
                'rate' => 1,
                'mode' => 'subtract',
                'base' => 'net_after_discount',
                'scopes' => ['sales', 'quotes'],
                'is_active' => 0,
            ],
            [
                'key' => 'stamp_0_4',
                'name' => 'ضريبة / رسم دمغة',
                'name_en' => 'Stamp Tax',
                'category' => 'stamp',
                'rate' => 0.4,
                'mode' => 'add',
                'base' => 'net_after_discount',
                'scopes' => ['sales', 'quotes'],
                'is_active' => 0,
            ],
        ];
    }
}

if (!function_exists('app_tax_default_laws')) {
    function app_tax_default_laws(): array
    {
        return [
            [
                'key' => 'vat_2016',
                'name' => 'ضريبة القيمة المضافة',
                'name_en' => 'VAT Law',
                'category' => 'vat',
                'frequency' => 'monthly',
                'settlement_mode' => 'vat_offset',
                'is_active' => 1,
                'notes' => 'تستخدم لإقرارات القيمة المضافة والمقاصة بين المخرجات والمدخلات.',
                'brackets' => [],
            ],
            [
                'key' => 'income_91_2005',
                'name' => 'ضريبة الدخل',
                'name_en' => 'Income Tax Law',
                'category' => 'income',
                'frequency' => 'annual',
                'settlement_mode' => 'standalone',
                'is_active' => 1,
                'notes' => 'تعتمد على صافي الربح بعد التسويات القانونية وفق الدليل المرفق.',
                'brackets' => [],
            ],
            [
                'key' => 'simplified_6_2025',
                'name' => 'النظام المبسط للمشروعات الصغيرة',
                'name_en' => 'Simplified SME Tax Regime',
                'category' => 'simplified',
                'frequency' => 'annual',
                'settlement_mode' => 'turnover_based',
                'is_active' => 1,
                'notes' => 'يعتمد على حجم الأعمال السنوي مع بقاء القيمة المضافة منفصلة.',
                'brackets' => [
                    ['label' => 'أقل من 500 ألف', 'from' => 0, 'to' => 500000, 'rate' => 0.4],
                    ['label' => 'من 500 ألف إلى أقل من 2 مليون', 'from' => 500000, 'to' => 2000000, 'rate' => 0.5],
                    ['label' => 'من 2 مليون إلى أقل من 3 ملايين', 'from' => 2000000, 'to' => 3000000, 'rate' => 0.75],
                    ['label' => 'من 3 ملايين إلى أقل من 10 ملايين', 'from' => 3000000, 'to' => 10000000, 'rate' => 1.0],
                    ['label' => 'من 10 ملايين إلى 20 مليون', 'from' => 10000000, 'to' => 20000000, 'rate' => 1.5],
                ],
            ],
            [
                'key' => 'procedures_206_2020',
                'name' => 'الإجراءات الضريبية الموحد',
                'name_en' => 'Unified Tax Procedures',
                'category' => 'procedural',
                'frequency' => 'informational',
                'settlement_mode' => 'informational',
                'is_active' => 1,
                'notes' => 'حاكم لدورة المستندات والإقرارات وليس وعاءً ضريبياً مستقلاً.',
                'brackets' => [],
            ],
        ];
    }
}

if (!function_exists('app_tax_normalize_type')) {
    function app_tax_normalize_type(array $row): ?array
    {
        $key = strtolower(trim((string)($row['key'] ?? '')));
        $name = trim((string)($row['name'] ?? ''));
        if ($key === '' || !preg_match('/^[a-z0-9_]{2,60}$/', $key) || $name === '') {
            return null;
        }
        $category = strtolower(trim((string)($row['category'] ?? 'other')));
        if (!in_array($category, ['vat', 'withholding', 'stamp', 'other'], true)) {
            $category = 'other';
        }
        $mode = strtolower(trim((string)($row['mode'] ?? 'add')));
        if (!in_array($mode, ['add', 'subtract'], true)) {
            $mode = 'add';
        }
        $base = strtolower(trim((string)($row['base'] ?? 'net_after_discount')));
        if (!in_array($base, ['subtotal', 'net_after_discount'], true)) {
            $base = 'net_after_discount';
        }
        $scopesRaw = $row['scopes'] ?? ['sales', 'quotes'];
        $scopes = [];
        foreach ((array)$scopesRaw as $scope) {
            $scope = strtolower(trim((string)$scope));
            if (in_array($scope, ['sales', 'quotes', 'purchase', 'all'], true) && !in_array($scope, $scopes, true)) {
                $scopes[] = $scope;
            }
        }
        if (empty($scopes)) {
            $scopes = ['sales', 'quotes'];
        }

        return [
            'key' => $key,
            'name' => $name,
            'name_en' => trim((string)($row['name_en'] ?? $name)),
            'category' => $category,
            'rate' => round((float)($row['rate'] ?? 0), 4),
            'mode' => $mode,
            'base' => $base,
            'scopes' => $scopes,
            'is_active' => (int)($row['is_active'] ?? 0) === 1 ? 1 : 0,
        ];
    }
}

if (!function_exists('app_tax_normalize_law')) {
    function app_tax_normalize_law_brackets($raw): array
    {
        $rows = [];
        if (is_string($raw)) {
            $lines = preg_split('/\r\n|\r|\n/', $raw) ?: [];
            foreach ($lines as $line) {
                $line = trim((string)$line);
                if ($line === '') {
                    continue;
                }
                $parts = array_map('trim', explode('|', $line));
                $rows[] = [
                    'label' => $parts[0] ?? '',
                    'from' => $parts[1] ?? '',
                    'to' => $parts[2] ?? '',
                    'rate' => $parts[3] ?? '',
                ];
            }
        } elseif (is_array($raw)) {
            $rows = $raw;
        }

        $normalized = [];
        $autoIndex = 1;
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $label = trim((string)($row['label'] ?? ''));
            $from = round(max(0.0, (float)($row['from'] ?? 0)), 2);
            $toRaw = trim((string)($row['to'] ?? ''));
            $to = ($toRaw === '' || strtolower($toRaw) === 'null') ? null : round(max($from, (float)$toRaw), 2);
            $rate = round(max(0.0, (float)($row['rate'] ?? 0)), 4);
            if ($label === '' && $rate <= 0) {
                continue;
            }
            if ($label === '') {
                $label = 'شريحة ' . $autoIndex;
            }
            $normalized[] = [
                'label' => $label,
                'from' => $from,
                'to' => $to,
                'rate' => $rate,
            ];
            $autoIndex++;
        }

        usort($normalized, static function (array $a, array $b): int {
            if ((float)$a['from'] === (float)$b['from']) {
                $aTo = $a['to'] === null ? PHP_FLOAT_MAX : (float)$a['to'];
                $bTo = $b['to'] === null ? PHP_FLOAT_MAX : (float)$b['to'];
                return $aTo <=> $bTo;
            }
            return (float)$a['from'] <=> (float)$b['from'];
        });

        return $normalized;
    }
}

if (!function_exists('app_tax_normalize_law')) {
    function app_tax_normalize_law(array $row): ?array
    {
        $key = strtolower(trim((string)($row['key'] ?? '')));
        $name = trim((string)($row['name'] ?? ''));
        if ($key === '' || !preg_match('/^[a-z0-9_]{2,60}$/', $key) || $name === '') {
            return null;
        }
        $category = strtolower(trim((string)($row['category'] ?? 'procedural')));
        if (!in_array($category, ['vat', 'income', 'simplified', 'stamp', 'procedural'], true)) {
            $category = 'procedural';
        }
        $frequency = strtolower(trim((string)($row['frequency'] ?? 'monthly')));
        if (!in_array($frequency, ['monthly', 'quarterly', 'annual', 'informational'], true)) {
            $frequency = 'monthly';
        }
        $settlementMode = strtolower(trim((string)($row['settlement_mode'] ?? 'informational')));
        if (!in_array($settlementMode, ['vat_offset', 'standalone', 'turnover_based', 'informational'], true)) {
            $settlementMode = 'informational';
        }

        return [
            'key' => $key,
            'name' => $name,
            'name_en' => trim((string)($row['name_en'] ?? $name)),
            'category' => $category,
            'frequency' => $frequency,
            'settlement_mode' => $settlementMode,
            'is_active' => (int)($row['is_active'] ?? 0) === 1 ? 1 : 0,
            'notes' => trim((string)($row['notes'] ?? '')),
            'brackets' => app_tax_normalize_law_brackets($row['brackets'] ?? ($row['brackets_text'] ?? [])),
        ];
    }
}

if (!function_exists('app_tax_law_brackets_to_text')) {
    function app_tax_law_brackets_to_text(array $brackets): string
    {
        $lines = [];
        foreach ($brackets as $row) {
            if (!is_array($row)) {
                continue;
            }
            $lines[] = implode(' | ', [
                trim((string)($row['label'] ?? '')),
                number_format((float)($row['from'] ?? 0), 2, '.', ''),
                $row['to'] === null ? '' : number_format((float)$row['to'], 2, '.', ''),
                number_format((float)($row['rate'] ?? 0), 4, '.', ''),
            ]);
        }
        return implode(PHP_EOL, $lines);
    }
}

if (!function_exists('app_tax_calculate_bracket_breakdown')) {
    function app_tax_calculate_bracket_breakdown(float $taxableAmount, array $brackets, string $settlementMode = 'progressive'): array
    {
        $taxableAmount = round(max(0.0, $taxableAmount), 2);
        $normalizedBrackets = app_tax_normalize_law_brackets($brackets);
        if ($settlementMode === 'turnover_based') {
            foreach ($normalizedBrackets as $row) {
                $from = (float)($row['from'] ?? 0);
                $to = array_key_exists('to', $row) && $row['to'] !== null ? (float)$row['to'] : null;
                if ($taxableAmount < $from) {
                    continue;
                }
                if ($to !== null && $taxableAmount > $to) {
                    continue;
                }
                $rate = (float)($row['rate'] ?? 0);
                $due = round($taxableAmount * ($rate / 100), 2);
                return [
                    'rows' => [[
                        'label' => (string)($row['label'] ?? ''),
                        'from' => $from,
                        'to' => $to,
                        'rate' => $rate,
                        'slice_amount' => $taxableAmount,
                        'estimated_due' => $due,
                    ]],
                    'estimated_due' => $due,
                    'covered_amount' => $taxableAmount,
                    'uncovered_amount' => 0.0,
                ];
            }

            return [
                'rows' => [],
                'estimated_due' => 0.0,
                'covered_amount' => 0.0,
                'uncovered_amount' => $taxableAmount,
            ];
        }

        $rows = [];
        $estimatedDue = 0.0;
        $coveredAmount = 0.0;
        foreach ($normalizedBrackets as $row) {
            $from = (float)($row['from'] ?? 0);
            $to = array_key_exists('to', $row) && $row['to'] !== null ? (float)$row['to'] : null;
            if ($taxableAmount <= $from) {
                continue;
            }
            $sliceUpper = $to === null ? $taxableAmount : min($taxableAmount, $to);
            $sliceAmount = round(max(0.0, $sliceUpper - $from), 2);
            if ($sliceAmount <= 0) {
                continue;
            }
            $due = round($sliceAmount * ((float)($row['rate'] ?? 0) / 100), 2);
            $rows[] = [
                'label' => (string)($row['label'] ?? ''),
                'from' => $from,
                'to' => $to,
                'rate' => (float)($row['rate'] ?? 0),
                'slice_amount' => $sliceAmount,
                'estimated_due' => $due,
            ];
            $estimatedDue += $due;
            $coveredAmount += $sliceAmount;
            if ($to === null || $taxableAmount <= $to) {
                break;
            }
        }

        return [
            'rows' => $rows,
            'estimated_due' => round($estimatedDue, 2),
            'covered_amount' => round($coveredAmount, 2),
            'uncovered_amount' => round(max(0.0, $taxableAmount - $coveredAmount), 2),
        ];
    }
}

if (!function_exists('app_tax_law_bracket_basis_summary')) {
    function app_tax_law_bracket_basis_summary(array $lawRow, array $summaryRow): array
    {
        $settlementMode = (string)($lawRow['settlement_mode'] ?? 'informational');
        $taxableAmount = 0.0;
        $basisLabel = 'صافي الأساس الخاضع';

        if ($settlementMode === 'turnover_based') {
            // قانون التسهيلات يُحتسب على حجم الأعمال قبل ضريبة القيمة المضافة.
            $taxableAmount = (float)($summaryRow['net_base'] ?? 0);
            $basisLabel = 'حجم الأعمال قبل القيمة المضافة';
        } elseif ($settlementMode === 'standalone') {
            $taxableAmount = (float)($summaryRow['net_base'] ?? 0);
            $basisLabel = 'صافي الوعاء الخاضع';
        } elseif ($settlementMode === 'vat_offset') {
            $taxableAmount = (float)($summaryRow['net_base'] ?? 0);
            $basisLabel = 'صافي الأساس الخاضع';
        } else {
            $taxableAmount = (float)($summaryRow['tax_total'] ?? 0);
            $basisLabel = 'إجمالي الضرائب المسجلة';
        }

        return [
            'basis_label' => $basisLabel,
            'taxable_amount' => round(max(0.0, $taxableAmount), 2),
            'settlement_mode' => $settlementMode,
        ];
    }
}

if (!function_exists('app_tax_catalog')) {
    function app_tax_catalog(mysqli $conn, bool $activeOnly = false, string $scope = 'all'): array
    {
        $raw = trim(app_setting_get($conn, 'tax_types_catalog', ''));
        $decoded = $raw !== '' ? json_decode($raw, true) : null;
        if (!is_array($decoded)) {
            $decoded = app_tax_default_types();
        }

        $rows = [];
        foreach ($decoded as $row) {
            if (!is_array($row)) {
                continue;
            }
            $normalized = app_tax_normalize_type($row);
            if ($normalized === null) {
                continue;
            }
            if ($activeOnly && (int)$normalized['is_active'] !== 1) {
                continue;
            }
            if ($scope !== 'all' && !in_array('all', $normalized['scopes'], true) && !in_array($scope, $normalized['scopes'], true)) {
                continue;
            }
            $rows[] = $normalized;
        }
        return $rows;
    }
}

if (!function_exists('app_tax_law_catalog')) {
    function app_tax_law_catalog(mysqli $conn, bool $activeOnly = false): array
    {
        $raw = trim(app_setting_get($conn, 'tax_law_catalog', ''));
        $decoded = $raw !== '' ? json_decode($raw, true) : null;
        if (!is_array($decoded)) {
            $decoded = app_tax_default_laws();
        }

        $rows = [];
        foreach ($decoded as $row) {
            if (!is_array($row)) {
                continue;
            }
            $normalized = app_tax_normalize_law($row);
            if ($normalized === null) {
                continue;
            }
            if ($activeOnly && (int)$normalized['is_active'] !== 1) {
                continue;
            }
            $rows[] = $normalized;
        }
        return $rows;
    }
}

if (!function_exists('app_tax_find_law')) {
    function app_tax_find_law(mysqli $conn, string $lawKey): ?array
    {
        foreach (app_tax_law_catalog($conn, false) as $law) {
            if ((string)$law['key'] === $lawKey) {
                return $law;
            }
        }
        return null;
    }
}

if (!function_exists('app_tax_is_tax_invoice')) {
    function app_tax_is_tax_invoice(string $kind): bool
    {
        return strtolower(trim($kind)) === 'tax';
    }
}

if (!function_exists('app_tax_calculate_document')) {
    function app_tax_calculate_document(array $catalog, string $kind, float $subTotal, float $discount, array $selectedKeys): array
    {
        $subTotal = round(max(0.0, $subTotal), 2);
        $discount = round(max(0.0, $discount), 2);
        $netBase = round(max(0.0, $subTotal - $discount), 2);

        if (!app_tax_is_tax_invoice($kind)) {
            return [
                'sub_total' => $subTotal,
                'discount' => $discount,
                'net_base' => $netBase,
                'tax_total' => 0.0,
                'grand_total' => $netBase,
                'lines' => [],
            ];
        }

        $catalogMap = [];
        foreach ($catalog as $taxType) {
            $catalogMap[(string)$taxType['key']] = $taxType;
        }

        $lines = [];
        $taxTotal = 0.0;
        foreach ($selectedKeys as $selectedKeyRaw) {
            $selectedKey = strtolower(trim((string)$selectedKeyRaw));
            if ($selectedKey === '' || !isset($catalogMap[$selectedKey])) {
                continue;
            }
            $taxType = $catalogMap[$selectedKey];
            if ((int)($taxType['is_active'] ?? 0) !== 1) {
                continue;
            }

            $baseAmount = ((string)($taxType['base'] ?? 'net_after_discount') === 'subtotal') ? $subTotal : $netBase;
            $rate = round((float)($taxType['rate'] ?? 0), 4);
            $amount = round(($baseAmount * $rate) / 100, 2);
            $signedAmount = ((string)($taxType['mode'] ?? 'add') === 'subtract') ? -$amount : $amount;
            $taxTotal += $signedAmount;

            $lines[] = [
                'key' => (string)$taxType['key'],
                'name' => (string)$taxType['name'],
                'name_en' => (string)($taxType['name_en'] ?? $taxType['name']),
                'category' => (string)($taxType['category'] ?? 'other'),
                'rate' => $rate,
                'mode' => (string)($taxType['mode'] ?? 'add'),
                'base' => (string)($taxType['base'] ?? 'net_after_discount'),
                'base_amount' => $baseAmount,
                'amount' => $amount,
                'signed_amount' => $signedAmount,
            ];
        }

        $taxTotal = round($taxTotal, 2);
        return [
            'sub_total' => $subTotal,
            'discount' => $discount,
            'net_base' => $netBase,
            'tax_total' => $taxTotal,
            'grand_total' => round($netBase + $taxTotal, 2),
            'lines' => $lines,
        ];
    }
}

if (!function_exists('app_tax_decode_lines')) {
    function app_tax_decode_lines($raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }
}
