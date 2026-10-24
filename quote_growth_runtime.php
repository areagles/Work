<?php

if (!function_exists('app_quote_growth_ensure_schema')) {
    function app_quote_growth_ensure_schema(mysqli $conn): void
    {
        app_ensure_quotes_schema($conn);

        $ensureQuoteColumn = static function (string $column, string $sql) use ($conn): void {
            if (app_table_has_column($conn, 'quotes', $column)) {
                return;
            }
            try {
                $conn->query($sql);
                app_table_has_column_reset('quotes', $column);
            } catch (Throwable $e) {
                error_log('quote growth alter failed for ' . $column . ': ' . $e->getMessage());
            }
        };

        $ensureQuoteColumn('internal_approval_status', "ALTER TABLE quotes ADD COLUMN internal_approval_status VARCHAR(24) NOT NULL DEFAULT 'not_required' AFTER quote_review_closed_reason");
        $ensureQuoteColumn('internal_approved_by', "ALTER TABLE quotes ADD COLUMN internal_approved_by INT NOT NULL DEFAULT 0 AFTER internal_approval_status");
        $ensureQuoteColumn('internal_approved_at', "ALTER TABLE quotes ADD COLUMN internal_approved_at DATETIME DEFAULT NULL AFTER internal_approved_by");
        $ensureQuoteColumn('fulfillment_stage', "ALTER TABLE quotes ADD COLUMN fulfillment_stage VARCHAR(40) NOT NULL DEFAULT 'client_review' AFTER internal_approved_at");
        $ensureQuoteColumn('deposit_required_amount', "ALTER TABLE quotes ADD COLUMN deposit_required_amount DECIMAL(14,2) NOT NULL DEFAULT 0.00 AFTER fulfillment_stage");
        $ensureQuoteColumn('expected_close_date', "ALTER TABLE quotes ADD COLUMN expected_close_date DATE DEFAULT NULL AFTER deposit_required_amount");
        $ensureQuoteColumn('quote_rejection_reason', "ALTER TABLE quotes ADD COLUMN quote_rejection_reason VARCHAR(190) NOT NULL DEFAULT '' AFTER expected_close_date");
        $ensureQuoteColumn('client_review_last_sent_at', "ALTER TABLE quotes ADD COLUMN client_review_last_sent_at DATETIME DEFAULT NULL AFTER quote_rejection_reason");

        $conn->query("
            CREATE TABLE IF NOT EXISTS quote_templates (
                id INT AUTO_INCREMENT PRIMARY KEY,
                template_name VARCHAR(190) NOT NULL,
                template_kind VARCHAR(40) NOT NULL DEFAULT 'standard',
                items_json LONGTEXT DEFAULT NULL,
                notes TEXT DEFAULT NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_quote_templates_active (is_active)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $conn->query("
            CREATE TABLE IF NOT EXISTS quote_attachments (
                id INT AUTO_INCREMENT PRIMARY KEY,
                quote_id INT NOT NULL,
                file_name VARCHAR(255) NOT NULL,
                file_path VARCHAR(255) NOT NULL,
                file_mime VARCHAR(120) NOT NULL DEFAULT '',
                file_size INT NOT NULL DEFAULT 0,
                uploaded_by INT NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_quote_attachments_quote (quote_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $count = 0;
        $res = $conn->query("SELECT COUNT(*) AS c FROM quote_templates");
        if ($res) {
            $row = $res->fetch_assoc();
            $count = (int)($row['c'] ?? 0);
            $res->free();
        }
        if ($count === 0) {
            $defaults = [
                ['طباعة تجارية سريعة', 'standard', [['desc' => 'تصميم وتجهيز فني', 'qty' => 1, 'unit' => 'خدمة', 'price' => 0], ['desc' => 'طباعة وتوريد', 'qty' => 1, 'unit' => 'كمية', 'price' => 0]], 'يشمل العرض التجهيز الفني والطباعة طبقاً للمواصفات المعتمدة.'],
                ['توريد عبوات أو خامات', 'standard', [['desc' => 'توريد خامات/عبوات', 'qty' => 1, 'unit' => 'كرتونة', 'price' => 0], ['desc' => 'شحن أو مناولة', 'qty' => 1, 'unit' => 'خدمة', 'price' => 0]], 'التوريد مرتبط بتوفر الخامة وقت الاعتماد النهائي.'],
                ['خدمة تصميم وهوية', 'standard', [['desc' => 'تصميم مبدئي', 'qty' => 1, 'unit' => 'خدمة', 'price' => 0], ['desc' => 'تعديلات وتنفيذ نهائي', 'qty' => 1, 'unit' => 'خدمة', 'price' => 0]], 'يشمل العرض عدد التعديلات الموضح فقط وما يزيد عنه يسعر مستقلاً.'],
            ];
            $stmt = $conn->prepare("INSERT INTO quote_templates (template_name, template_kind, items_json, notes) VALUES (?, ?, ?, ?)");
            if ($stmt) {
                foreach ($defaults as $tpl) {
                    $itemsJson = json_encode($tpl[2], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    $stmt->bind_param('ssss', $tpl[0], $tpl[1], $itemsJson, $tpl[3]);
                    $stmt->execute();
                }
                $stmt->close();
            }
        }
    }
}

if (!function_exists('app_quote_base_value_sql')) {
    function app_quote_base_value_sql(string $alias = 'q'): string
    {
        $prefix = trim($alias) !== '' ? trim($alias) . '.' : '';
        return "CASE
            WHEN IFNULL({$prefix}base_total_amount, 0) > 0 THEN {$prefix}base_total_amount
            WHEN IFNULL({$prefix}exchange_rate_to_base, 0) > 0 THEN {$prefix}total_amount * {$prefix}exchange_rate_to_base
            ELSE {$prefix}total_amount
        END";
    }
}

if (!function_exists('app_quote_backfill_base_totals')) {
    function app_quote_backfill_base_totals(mysqli $conn): void
    {
        app_quote_growth_ensure_schema($conn);
        try {
            $conn->query("
                UPDATE quotes
                SET base_total_amount = CASE
                    WHEN IFNULL(exchange_rate_to_base, 0) > 0 THEN ROUND(total_amount * exchange_rate_to_base, 2)
                    ELSE total_amount
                END
                WHERE IFNULL(base_total_amount, 0) = 0
                  AND IFNULL(total_amount, 0) <> 0
            ");
        } catch (Throwable $e) {
            error_log('quote base total backfill failed: ' . $e->getMessage());
        }
    }
}

if (!function_exists('app_quote_growth_threshold')) {
    function app_quote_growth_threshold(mysqli $conn): float
    {
        return max(0.0, (float)app_setting_get($conn, 'quote_internal_approval_threshold', '50000'));
    }
}

if (!function_exists('app_quote_internal_status_for_total')) {
    function app_quote_internal_status_for_total(mysqli $conn, float $baseTotal): string
    {
        $threshold = app_quote_growth_threshold($conn);
        return ($threshold > 0 && $baseTotal >= $threshold) ? 'pending' : 'not_required';
    }
}

if (!function_exists('app_quote_templates')) {
    function app_quote_templates(mysqli $conn): array
    {
        app_quote_growth_ensure_schema($conn);
        $rows = [];
        $res = $conn->query("SELECT * FROM quote_templates WHERE is_active = 1 ORDER BY template_name ASC");
        while ($res && ($row = $res->fetch_assoc())) {
            $row['items'] = json_decode((string)($row['items_json'] ?? '[]'), true) ?: [];
            $rows[] = $row;
        }
        return $rows;
    }
}

if (!function_exists('app_quote_rejection_reasons')) {
    function app_quote_rejection_reasons(): array
    {
        return ['السعر غير مناسب', 'مدة التنفيذ غير مناسبة', 'المواصفات تحتاج تعديل', 'تم اختيار مورد آخر', 'تأجيل المشروع', 'سبب آخر'];
    }
}

if (!function_exists('app_quote_growth_upload_attachments')) {
    function app_quote_growth_upload_attachments(mysqli $conn, int $quoteId, string $field = 'quote_attachments'): void
    {
        if ($quoteId <= 0 || empty($_FILES[$field]) || !is_array($_FILES[$field]['name'] ?? null)) {
            return;
        }
        $dir = __DIR__ . '/uploads/quote_attachments';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        if (!is_dir($dir) || !is_writable($dir)) {
            return;
        }
        $allowedExt = ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'doc', 'docx', 'xls', 'xlsx'];
        $stmt = $conn->prepare("INSERT INTO quote_attachments (quote_id, file_name, file_path, file_mime, file_size, uploaded_by) VALUES (?, ?, ?, ?, ?, ?)");
        if (!$stmt) {
            return;
        }
        $userId = (int)($_SESSION['user_id'] ?? 0);
        foreach ($_FILES[$field]['name'] as $i => $name) {
            if ((int)($_FILES[$field]['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                continue;
            }
            $name = trim((string)$name);
            $tmp = (string)($_FILES[$field]['tmp_name'][$i] ?? '');
            $size = (int)($_FILES[$field]['size'][$i] ?? 0);
            if ($name === '' || $tmp === '' || $size <= 0 || $size > 12 * 1024 * 1024) {
                continue;
            }
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if (!in_array($ext, $allowedExt, true)) {
                continue;
            }
            $safeName = preg_replace('/[^A-Za-z0-9._-]+/', '_', $name);
            $stored = 'quote_' . $quoteId . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
            $target = $dir . '/' . $stored;
            if (!@move_uploaded_file($tmp, $target)) {
                continue;
            }
            $relative = 'uploads/quote_attachments/' . $stored;
            $mime = function_exists('mime_content_type') ? (string)@mime_content_type($target) : '';
            $stmt->bind_param('isssii', $quoteId, $safeName, $relative, $mime, $size, $userId);
            $stmt->execute();
        }
        $stmt->close();
    }
}

if (!function_exists('app_quote_attachments')) {
    function app_quote_attachments(mysqli $conn, int $quoteId): array
    {
        app_quote_growth_ensure_schema($conn);
        $rows = [];
        $stmt = $conn->prepare("SELECT * FROM quote_attachments WHERE quote_id = ? ORDER BY id DESC");
        if ($stmt) {
            $stmt->bind_param('i', $quoteId);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($res && ($row = $res->fetch_assoc())) {
                $rows[] = $row;
            }
            $stmt->close();
        }
        return $rows;
    }
}

if (!function_exists('app_quote_alerts')) {
    function app_quote_alerts(mysqli $conn): array
    {
        app_quote_growth_ensure_schema($conn);
        $today = date('Y-m-d');
        $soon = date('Y-m-d', strtotime('+3 days'));
        $stats = ['expired' => 0, 'soon' => 0, 'internal_pending' => 0, 'awaiting_deposit' => 0];
        $sql = "SELECT
            SUM(CASE WHEN status='pending' AND valid_until < '{$today}' THEN 1 ELSE 0 END) AS expired,
            SUM(CASE WHEN status='pending' AND valid_until BETWEEN '{$today}' AND '{$soon}' THEN 1 ELSE 0 END) AS soon,
            SUM(CASE WHEN internal_approval_status='pending' THEN 1 ELSE 0 END) AS internal_pending,
            SUM(CASE WHEN fulfillment_stage='awaiting_deposit' THEN 1 ELSE 0 END) AS awaiting_deposit
            FROM quotes";
        $res = $conn->query($sql);
        if ($res) {
            $row = $res->fetch_assoc() ?: [];
            foreach ($stats as $key => $value) {
                $stats[$key] = (int)($row[$key] ?? 0);
            }
            $res->free();
        }
        return $stats;
    }
}

if (!function_exists('app_quote_growth_can_manage')) {
    function app_quote_growth_can_manage(): bool
    {
        return app_user_can_any(['quotes.manage', 'quotes.approve']) || in_array(app_user_role(), ['admin', 'manager'], true);
    }
}
