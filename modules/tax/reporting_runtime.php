<?php

if (!function_exists('app_tax_report_dataset')) {
    function app_tax_report_dataset(mysqli $conn, string $dateFrom, string $dateTo, string $lawFilter = 'all'): array
    {
        $isEnglish = app_current_lang($conn) === 'en';
        $lawCatalog = app_tax_law_catalog($conn, true);
        $lawMap = [];
        foreach ($lawCatalog as $lawRow) {
            $lawMap[(string)$lawRow['key']] = $lawRow;
        }
        if ($lawFilter !== 'all' && !isset($lawMap[$lawFilter])) {
            $lawFilter = 'all';
        }
        $selectedLaw = ($lawFilter !== 'all' && isset($lawMap[$lawFilter])) ? $lawMap[$lawFilter] : null;
        $selectedSettlementMode = is_array($selectedLaw) ? (string)($selectedLaw['settlement_mode'] ?? '') : '';
        $invoiceLawFilter = ($lawFilter !== 'all' && $selectedSettlementMode !== 'turnover_based') ? $lawFilter : 'all';
        $collectInvoiceLawDetails = !($lawFilter !== 'all' && $selectedSettlementMode === 'turnover_based');

        $salesSummary = [];
        $lawSummary = [];
        $lawTypeSummary = [];
        $lawBracketSummary = [];
        $salesInvoiceCount = 0;
        $salesNetBase = 0.0;
        $salesTaxTotal = 0.0;
        $salesGrandTotal = 0.0;
        $outputVatTotal = 0.0;
        $withholdingTotal = 0.0;

        $sqlInvoices = "SELECT id, inv_date, sub_total, discount, total_amount, tax_total, tax_law_key, taxes_json FROM invoices WHERE invoice_kind = 'tax' AND COALESCE(status, '') <> 'cancelled' AND DATE(inv_date) BETWEEN ? AND ?";
        if ($invoiceLawFilter !== 'all') {
            $sqlInvoices .= " AND tax_law_key = ?";
        }
        $stmtInv = $conn->prepare($sqlInvoices);
        if ($invoiceLawFilter !== 'all') {
            $stmtInv->bind_param('sss', $dateFrom, $dateTo, $invoiceLawFilter);
        } else {
            $stmtInv->bind_param('ss', $dateFrom, $dateTo);
        }
        $stmtInv->execute();
        $invRes = $stmtInv->get_result();
        while ($row = $invRes->fetch_assoc()) {
            $lawKey = (string)($row['tax_law_key'] ?? '');
            if ($lawKey === '' || !isset($lawMap[$lawKey])) {
                continue;
            }
            $salesInvoiceCount++;
            $subTotal = (float)($row['sub_total'] ?? 0);
            $discount = (float)($row['discount'] ?? 0);
            $netBase = max(0, $subTotal - $discount);
            $taxTotal = (float)($row['tax_total'] ?? ($row['tax'] ?? 0));
            $salesNetBase += $netBase;
            $salesTaxTotal += $taxTotal;
            $salesGrandTotal += (float)($row['total_amount'] ?? 0);

            if ($collectInvoiceLawDetails && !isset($lawSummary[$lawKey])) {
                $lawSummary[$lawKey] = [
                    'name' => (string)($lawMap[$lawKey]['name'] ?? ($lawKey !== '' ? $lawKey : 'بدون قانون')),
                    'invoice_count' => 0,
                    'net_base' => 0.0,
                    'tax_total' => 0.0,
                    'grand_total' => 0.0,
                ];
            }
            if ($collectInvoiceLawDetails) {
                $lawSummary[$lawKey]['invoice_count']++;
                $lawSummary[$lawKey]['net_base'] += $netBase;
                $lawSummary[$lawKey]['tax_total'] += $taxTotal;
                $lawSummary[$lawKey]['grand_total'] += (float)($row['total_amount'] ?? 0);
            }

            foreach (app_tax_decode_lines((string)($row['taxes_json'] ?? '[]')) as $taxLine) {
                $taxKey = trim((string)($taxLine['key'] ?? ''));
                $taxName = trim((string)($taxLine['name'] ?? ''));
                $taxCategory = trim((string)($taxLine['category'] ?? ''));
                $taxRate = (float)($taxLine['rate'] ?? 0);
                $taxMode = trim((string)($taxLine['mode'] ?? ''));
                $taxBase = (float)($taxLine['base_amount'] ?? 0);
                $taxAmount = (float)($taxLine['amount'] ?? 0);

                if ($taxKey === '' || strtolower($taxKey) === 'unknown') {
                    if (abs($taxAmount) <= 0.00001 && abs($taxTotal) <= 0.00001) {
                        continue;
                    }
                    $taxKey = $lawKey !== '' ? $lawKey : 'manual_tax';
                    $taxName = $taxName !== '' && strtolower($taxName) !== 'unknown'
                        ? $taxName
                        : (string)($lawMap[$lawKey]['name'] ?? ($isEnglish ? 'Manual Tax Adjustment' : 'تسوية ضريبية مسجلة'));
                    $taxCategory = $taxCategory !== '' && strtolower($taxCategory) !== 'other'
                        ? $taxCategory
                        : (string)($lawMap[$lawKey]['category'] ?? 'vat');
                    $taxRate = $taxRate > 0 ? $taxRate : ($taxBase > 0.00001 ? ($taxAmount / $taxBase * 100) : ($netBase > 0.00001 ? ($taxAmount / $netBase * 100) : 0));
                    $taxMode = $taxMode !== '' ? $taxMode : 'add';
                    $taxBase = $taxBase > 0.00001 ? $taxBase : $netBase;
                }

                if (abs($taxAmount) <= 0.00001 && abs($taxBase) <= 0.00001) {
                    continue;
                }
                if (!isset($salesSummary[$taxKey])) {
                    $salesSummary[$taxKey] = [
                        'name' => $taxName !== '' ? $taxName : $taxKey,
                        'category' => $taxCategory !== '' ? $taxCategory : 'other',
                        'rate' => $taxRate,
                        'mode' => $taxMode !== '' ? $taxMode : 'add',
                        'base_total' => 0.0,
                        'amount_total' => 0.0,
                    ];
                }
                $salesSummary[$taxKey]['base_total'] += $taxBase;
                $salesSummary[$taxKey]['amount_total'] += $taxAmount;
                if ($collectInvoiceLawDetails && !isset($lawTypeSummary[$lawKey])) {
                    $lawTypeSummary[$lawKey] = [
                        'law_name' => (string)($lawMap[$lawKey]['name'] ?? ($lawKey !== '' ? $lawKey : 'بدون قانون')),
                        'taxes' => [],
                    ];
                }
                if ($collectInvoiceLawDetails && !isset($lawTypeSummary[$lawKey]['taxes'][$taxKey])) {
                    $lawTypeSummary[$lawKey]['taxes'][$taxKey] = [
                        'name' => $taxName !== '' ? $taxName : $taxKey,
                        'category' => $taxCategory !== '' ? $taxCategory : 'other',
                        'rate' => $taxRate,
                        'mode' => $taxMode !== '' ? $taxMode : 'add',
                        'base_total' => 0.0,
                        'amount_total' => 0.0,
                    ];
                }
                if ($collectInvoiceLawDetails) {
                    $lawTypeSummary[$lawKey]['taxes'][$taxKey]['base_total'] += $taxBase;
                    $lawTypeSummary[$lawKey]['taxes'][$taxKey]['amount_total'] += $taxAmount;
                }
                if ($taxCategory === 'vat' && ($taxMode === '' || $taxMode === 'add')) {
                    $outputVatTotal += $taxAmount;
                }
                if ($taxCategory === 'withholding') {
                    $withholdingTotal += $taxAmount;
                }
            }
        }
        $stmtInv->close();

        $purchaseVatTotal = 0.0;
        $purchaseCount = 0;
        $includePurchaseVat = $lawFilter === 'all' || (is_array($selectedLaw) && (string)($selectedLaw['category'] ?? '') === 'vat');
        if ($includePurchaseVat) {
            $stmtPur = $conn->prepare("SELECT id, tax, total_amount FROM purchase_invoices WHERE COALESCE(status, '') <> 'cancelled' AND DATE(inv_date) BETWEEN ? AND ?");
            $stmtPur->bind_param('ss', $dateFrom, $dateTo);
            $stmtPur->execute();
            $purRes = $stmtPur->get_result();
            while ($prow = $purRes->fetch_assoc()) {
                $purchaseCount++;
                $purchaseVatTotal += (float)($prow['tax'] ?? 0);
            }
            $stmtPur->close();
        }

        $quoteTaxTotal = 0.0;
        $quoteCount = 0;
        $sqlQuotes = "SELECT id, total_amount, tax_total, tax_law_key FROM quotes WHERE quote_kind = 'tax' AND DATE(created_at) BETWEEN ? AND ?";
        if ($invoiceLawFilter !== 'all') {
            $sqlQuotes .= " AND tax_law_key = ?";
        }
        $stmtQuote = $conn->prepare($sqlQuotes);
        if ($invoiceLawFilter !== 'all') {
            $stmtQuote->bind_param('sss', $dateFrom, $dateTo, $invoiceLawFilter);
        } else {
            $stmtQuote->bind_param('ss', $dateFrom, $dateTo);
        }
        $stmtQuote->execute();
        $quoteRes = $stmtQuote->get_result();
        while ($qrow = $quoteRes->fetch_assoc()) {
            $quoteLawKey = (string)($qrow['tax_law_key'] ?? '');
            if ($quoteLawKey === '' || !isset($lawMap[$quoteLawKey])) {
                continue;
            }
            $quoteCount++;
            $quoteTaxTotal += (float)($qrow['tax_total'] ?? 0);
        }
        $stmtQuote->close();

        foreach ($lawMap as $activeLawKey => $activeLawRow) {
            if ($lawFilter !== 'all' && $activeLawKey !== $lawFilter) {
                continue;
            }
            if ((string)($activeLawRow['settlement_mode'] ?? '') !== 'turnover_based') {
                continue;
            }
            $lawSummary[$activeLawKey] = [
                'name' => (string)($activeLawRow['name'] ?? $activeLawKey),
                'invoice_count' => $salesInvoiceCount,
                'net_base' => $salesNetBase,
                'tax_total' => 0.0,
                'grand_total' => $salesNetBase,
                'derived_report' => true,
            ];
        }

        foreach ($lawSummary as $lawKey => $summaryRow) {
            $lawRow = $lawMap[$lawKey] ?? [
                'key' => $lawKey,
                'name' => $summaryRow['name'] ?? ($lawKey !== '' ? $lawKey : 'بدون قانون'),
                'category' => 'procedural',
                'settlement_mode' => 'informational',
                'brackets' => [],
            ];
            $settlementMode = (string)($lawRow['settlement_mode'] ?? 'informational');
            $hasBrackets = !empty($lawRow['brackets']);
            $basis = app_tax_law_bracket_basis_summary($lawRow, $summaryRow);
            $breakdown = app_tax_calculate_bracket_breakdown(
                (float)$basis['taxable_amount'],
                (array)($lawRow['brackets'] ?? []),
                (string)($basis['settlement_mode'] ?? ($lawRow['settlement_mode'] ?? 'progressive'))
            );
            if ($hasBrackets || $settlementMode === 'turnover_based') {
                $lawBracketSummary[$lawKey] = [
                    'law_name' => (string)($lawRow['name'] ?? $summaryRow['name'] ?? $lawKey),
                    'law_key' => (string)$lawKey,
                    'basis_label' => (string)($basis['basis_label'] ?? 'الوعاء'),
                    'taxable_amount' => (float)($basis['taxable_amount'] ?? 0),
                    'estimated_due' => (float)($breakdown['estimated_due'] ?? 0),
                    'covered_amount' => (float)($breakdown['covered_amount'] ?? 0),
                    'uncovered_amount' => (float)($breakdown['uncovered_amount'] ?? 0),
                    'rows' => (array)($breakdown['rows'] ?? []),
                    'has_brackets' => $hasBrackets,
                    'settlement_mode' => $settlementMode,
                ];
            }
            $estimatedDue = (float)($breakdown['estimated_due'] ?? 0);
            $lawSummary[$lawKey]['estimated_due'] = $estimatedDue;
            $lawSummary[$lawKey]['report_tax_total'] = $settlementMode === 'turnover_based'
                ? $estimatedDue
                : (float)($lawSummary[$lawKey]['tax_total'] ?? 0);
            $lawSummary[$lawKey]['settlement_mode'] = $settlementMode;
        }

        $netVatDue = $outputVatTotal - $purchaseVatTotal;
        $declaration = app_tax_build_declaration_summary(
            $isEnglish,
            $selectedLaw,
            $salesNetBase,
            $outputVatTotal,
            $purchaseVatTotal,
            $netVatDue,
            $salesGrandTotal,
            $withholdingTotal,
            $salesTaxTotal
        );

        return [
            'law_catalog' => $lawCatalog,
            'law_filter' => $lawFilter,
            'law_map' => $lawMap,
            'active_laws' => array_values($lawCatalog),
            'sales_summary' => $salesSummary,
            'law_summary' => $lawSummary,
            'law_type_summary' => $lawTypeSummary,
            'law_bracket_summary' => $lawBracketSummary,
            'sales_invoice_count' => $salesInvoiceCount,
            'sales_net_base' => $salesNetBase,
            'sales_tax_total' => $salesTaxTotal,
            'sales_grand_total' => $salesGrandTotal,
            'output_vat_total' => $outputVatTotal,
            'withholding_total' => $withholdingTotal,
            'purchase_vat_total' => $purchaseVatTotal,
            'purchase_count' => $purchaseCount,
            'quote_tax_total' => $quoteTaxTotal,
            'quote_count' => $quoteCount,
            'net_vat_due' => $netVatDue,
            'selected_law' => $selectedLaw,
            'declaration_title' => (string)$declaration['title'],
            'declaration_body' => (string)$declaration['body'],
        ];
    }
}

if (!function_exists('app_tax_build_declaration_summary')) {
    function app_tax_build_declaration_summary(
        bool $isEnglish,
        ?array $selectedLaw,
        float $salesNetBase,
        float $outputVatTotal,
        float $purchaseVatTotal,
        float $netVatDue,
        float $salesGrandTotal,
        float $withholdingTotal,
        float $salesTaxTotal
    ): array {
        $title = $isEnglish ? 'Draft Tax Return' : 'مسودة إقرار ضريبي';
        $body = $isEnglish
            ? 'This section shows an operational draft based on the data recorded in the system for the selected period. It should be reviewed by accounting before official filing.'
            : 'يعرض هذا القسم مسودة تشغيلية مبنية على البيانات المسجلة بالنظام خلال الفترة المحددة، ويجب مراجعتها محاسبياً قبل التقديم الرسمي.';

        if (!$selectedLaw) {
            return ['title' => $title, 'body' => $body];
        }

        $lawCategory = (string)($selectedLaw['category'] ?? 'procedural');
        $lawFrequency = (string)($selectedLaw['frequency'] ?? 'monthly');
        if ($lawCategory === 'vat') {
            $title = $isEnglish ? 'Draft VAT Return' : 'مسودة إقرار ضريبة القيمة المضافة';
            $body = $isEnglish
                ? "Selected {$lawFrequency} period. Net taxable sales are " . number_format($salesNetBase, 2) . ", output VAT is " . number_format($outputVatTotal, 2) . ", recoverable input VAT is " . number_format($purchaseVatTotal, 2) . ", and the net due/balance is " . number_format($netVatDue, 2) . "."
                : "الفترة المختارة {$lawFrequency}. صافي المبيعات الخاضعة {$salesNetBase} جنيه، ضريبة المخرجات " . number_format($outputVatTotal, 2) . " جنيه، ضريبة المدخلات القابلة للمقاصة " . number_format($purchaseVatTotal, 2) . " جنيه، وصافي المستحق/الرصيد " . number_format($netVatDue, 2) . " جنيه.";
        } elseif ($lawCategory === 'income') {
            $title = $isEnglish ? 'Draft Income Tax Return' : 'مسودة إقرار ضريبة الدخل';
            $body = $isEnglish
                ? "This law depends on business results, not invoice taxes alone. The system shows taxable turnover of " . number_format($salesGrandTotal, 2) . " and withholding/retained taxes of " . number_format($withholdingTotal, 2) . " as an initial review input."
                : "يعتمد هذا القانون على نتائج النشاط وليس فقط ضرائب الفواتير. يعرض النظام هنا إجمالي مبيعات خاضعة بقيمة " . number_format($salesGrandTotal, 2) . " جنيه وإجمالي خصومات/استقطاعات ضريبية قدرها " . number_format($withholdingTotal, 2) . " جنيه كمدخل أولي للمراجعة.";
        } elseif ($lawCategory === 'simplified') {
            $title = $isEnglish ? 'Draft Simplified Regime Return' : 'مسودة إقرار للنظام المبسط';
            $body = $isEnglish
                ? "The system shows pre-VAT turnover for the selected period of " . number_format($salesNetBase, 2) . ". VAT, if applicable, remains tracked separately from the simplified regime according to system settings."
                : "يعرض النظام رقم الأعمال قبل ضريبة القيمة المضافة للفترة المختارة بقيمة " . number_format($salesNetBase, 2) . " جنيه مع الإشارة إلى أن ضريبة القيمة المضافة - إن وجدت - تُتابع بشكل مستقل عن النظام المبسط وفقاً للإعدادات.";
        } else {
            $title = $isEnglish ? 'Draft Tax Compliance Summary' : 'مسودة متابعة التزام ضريبي';
            $body = $isEnglish
                ? "The selected law is procedural/regulatory. A total of " . number_format($salesTaxTotal, 2) . " in taxes recorded on tax documents during the period has been aggregated for review before preparing official forms."
                : "القانون المختار ذو طابع إجرائي/تنظيمي. تم تجميع " . number_format($salesTaxTotal, 2) . " جنيه كإجمالي ضرائب مسجلة على المستندات الضريبية خلال الفترة للمراجعة قبل إعداد النماذج الرسمية.";
        }

        return ['title' => $title, 'body' => $body];
    }
}
