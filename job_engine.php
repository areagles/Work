<?php

declare(strict_types=1);

if (!function_exists('job_issue_material_cost')) {
    function job_issue_material_cost(mysqli $conn, int $jobId, int $userId, int $itemId, int $warehouseId, float $qty, string $stageKey = '', string $extraNotes = ''): bool
    {
        if ($jobId <= 0 || $userId <= 0 || $itemId <= 0 || $warehouseId <= 0 || $qty <= 0) {
            throw new RuntimeException('invalid_material_payload');
        }

        $conn->begin_transaction();
        try {
            $stmtStock = $conn->prepare("SELECT quantity FROM inventory_stock WHERE item_id = ? AND warehouse_id = ? FOR UPDATE");
            $stmtStock->bind_param('ii', $itemId, $warehouseId);
            $stmtStock->execute();
            $stockRow = $stmtStock->get_result()->fetch_assoc();
            $stmtStock->close();

            $available = (float)($stockRow['quantity'] ?? 0);
            if ($available < $qty) {
                throw new RuntimeException('insufficient_stock');
            }

            $stmtItem = $conn->prepare("SELECT name, item_code FROM inventory_items WHERE id = ? LIMIT 1");
            $stmtItem->bind_param('i', $itemId);
            $stmtItem->execute();
            $itemRow = $stmtItem->get_result()->fetch_assoc();
            $stmtItem->close();
            if (!$itemRow) {
                throw new RuntimeException('item_not_found');
            }

            $unitCost = function_exists('app_inventory_item_avg_cost')
                ? app_inventory_item_avg_cost($conn, $itemId)
                : 0.0;
            $totalCost = round($qty * $unitCost, 2);
            $signedQty = -$qty;
            $note = "صرف خامة للعملية #{$jobId}";
            if ($stageKey !== '') {
                $note .= " [{$stageKey}]";
            }
            if ($extraNotes !== '') {
                $note .= " - " . $extraNotes;
            }

            $stmtUpdStock = $conn->prepare("UPDATE inventory_stock SET quantity = quantity - ? WHERE item_id = ? AND warehouse_id = ?");
            $stmtUpdStock->bind_param('dii', $qty, $itemId, $warehouseId);
            $stmtUpdStock->execute();
            $stmtUpdStock->close();

            $stmtTrans = $conn->prepare("
                INSERT INTO inventory_transactions (
                    item_id, warehouse_id, user_id, transaction_type, quantity, related_order_id, notes,
                    unit_cost, total_cost, reference_type, reference_id, stage_key
                ) VALUES (?, ?, ?, 'out', ?, ?, ?, ?, ?, 'job_material', ?, ?)
            ");
            if (!$stmtTrans) {
                throw new RuntimeException('trans_insert_failed');
            }
            $stmtTrans->bind_param(
                'iiidisddis',
                $itemId,
                $warehouseId,
                $userId,
                $signedQty,
                $jobId,
                $note,
                $unitCost,
                $totalCost,
                $jobId,
                $stageKey
            );
            $stmtTrans->execute();
            $stmtTrans->close();

            $conn->commit();
            return true;
        } catch (Throwable $e) {
            $conn->rollback();
            throw $e;
        }
    }
}

if (!function_exists('job_reverse_material_cost')) {
    function job_reverse_material_cost(mysqli $conn, int $jobId, int $materialTransId): bool
    {
        if ($jobId <= 0 || $materialTransId <= 0) {
            throw new RuntimeException('invalid_material_transaction');
        }

        $conn->begin_transaction();
        try {
            $stmtTrans = $conn->prepare("
                SELECT item_id, warehouse_id, quantity
                FROM inventory_transactions
                WHERE id = ? AND related_order_id = ? AND reference_type = 'job_material'
                LIMIT 1
                FOR UPDATE
            ");
            if (!$stmtTrans) {
                throw new RuntimeException('load_material_transaction_failed');
            }
            $stmtTrans->bind_param('ii', $materialTransId, $jobId);
            $stmtTrans->execute();
            $transRow = $stmtTrans->get_result()->fetch_assoc();
            $stmtTrans->close();
            if (!$transRow) {
                throw new RuntimeException('material_transaction_not_found');
            }

            $itemId = (int)($transRow['item_id'] ?? 0);
            $warehouseId = (int)($transRow['warehouse_id'] ?? 0);
            $issuedQty = abs((float)($transRow['quantity'] ?? 0));
            if ($itemId <= 0 || $warehouseId <= 0 || $issuedQty <= 0) {
                throw new RuntimeException('material_transaction_invalid');
            }

            $stmtStock = $conn->prepare("
                INSERT INTO inventory_stock (item_id, warehouse_id, quantity)
                VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE quantity = quantity + VALUES(quantity)
            ");
            $stmtStock->bind_param('iid', $itemId, $warehouseId, $issuedQty);
            $stmtStock->execute();
            $stmtStock->close();

            $stmtDelete = $conn->prepare("
                DELETE FROM inventory_transactions
                WHERE id = ? AND related_order_id = ? AND reference_type = 'job_material'
            ");
            $stmtDelete->bind_param('ii', $materialTransId, $jobId);
            $stmtDelete->execute();
            $stmtDelete->close();

            $conn->commit();
            return true;
        } catch (Throwable $e) {
            $conn->rollback();
            throw $e;
        }
    }
}

if (!function_exists('job_add_service_cost')) {
    function job_add_service_cost(mysqli $conn, int $jobId, int $userId, string $stageKey, string $serviceName, float $qty, float $unitCost, string $notes = ''): bool
    {
        if ($jobId <= 0 || $userId <= 0 || $stageKey === '' || $serviceName === '' || $qty <= 0 || $unitCost < 0) {
            throw new RuntimeException('invalid_service_cost');
        }
        $totalCost = round($qty * $unitCost, 2);
        $stmtSvc = $conn->prepare("
            INSERT INTO job_service_costs (job_id, stage_key, service_name, qty, unit_cost, total_cost, notes, created_by_user_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        if (!$stmtSvc) {
            throw new RuntimeException('insert_service_cost_failed');
        }
        $stmtSvc->bind_param('issdddsi', $jobId, $stageKey, $serviceName, $qty, $unitCost, $totalCost, $notes, $userId);
        $stmtSvc->execute();
        $stmtSvc->close();
        return true;
    }
}

if (!function_exists('job_update_service_cost')) {
    function job_update_service_cost(mysqli $conn, int $jobId, int $serviceCostId, string $stageKey, string $serviceName, float $qty, float $unitCost, string $notes = ''): bool
    {
        if ($jobId <= 0 || $serviceCostId <= 0 || $stageKey === '' || $serviceName === '' || $qty <= 0 || $unitCost < 0) {
            throw new RuntimeException('invalid_service_cost');
        }
        $totalCost = round($qty * $unitCost, 2);
        $stmtUpdSvc = $conn->prepare("
            UPDATE job_service_costs
            SET stage_key = ?, service_name = ?, qty = ?, unit_cost = ?, total_cost = ?, notes = ?
            WHERE id = ? AND job_id = ?
        ");
        if (!$stmtUpdSvc) {
            throw new RuntimeException('update_service_cost_failed');
        }
        $stmtUpdSvc->bind_param('ssdddsii', $stageKey, $serviceName, $qty, $unitCost, $totalCost, $notes, $serviceCostId, $jobId);
        $stmtUpdSvc->execute();
        $stmtUpdSvc->close();
        return true;
    }
}

if (!function_exists('job_delete_service_cost')) {
    function job_delete_service_cost(mysqli $conn, int $jobId, int $serviceCostId): bool
    {
        if ($jobId <= 0 || $serviceCostId <= 0) {
            throw new RuntimeException('invalid_service_cost_delete');
        }
        $stmtDel = $conn->prepare("DELETE FROM job_service_costs WHERE id = ? AND job_id = ?");
        if (!$stmtDel) {
            throw new RuntimeException('delete_service_cost_failed');
        }
        $stmtDel->bind_param('ii', $serviceCostId, $jobId);
        $stmtDel->execute();
        $stmtDel->close();
        return true;
    }
}

if (!function_exists('job_material_cost_rows')) {
    function job_material_cost_rows(mysqli $conn, int $jobId, int $limit = 50): array
    {
        $rows = [];
        $stmt = $conn->prepare("
            SELECT
                t.id,
                t.item_id,
                t.warehouse_id,
                ABS(t.quantity) AS qty_used,
                IFNULL(t.unit_cost, 0) AS unit_cost,
                IFNULL(t.total_cost, 0) AS total_cost,
                IFNULL(t.stage_key, '') AS stage_key,
                IFNULL(t.notes, '') AS notes,
                t.transaction_date,
                i.name AS item_name,
                i.item_code AS item_code,
                w.name AS warehouse_name
            FROM inventory_transactions t
            LEFT JOIN inventory_items i ON i.id = t.item_id
            LEFT JOIN warehouses w ON w.id = t.warehouse_id
            WHERE t.reference_type = 'job_material' AND t.related_order_id = ?
            ORDER BY t.id DESC
            LIMIT ?
        ");
        if (!$stmt) {
            return $rows;
        }
        $stmt->bind_param('ii', $jobId, $limit);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($res && ($row = $res->fetch_assoc())) {
            $rows[] = $row;
        }
        $stmt->close();
        return $rows;
    }
}

if (!function_exists('job_service_cost_rows')) {
    function job_service_cost_rows(mysqli $conn, int $jobId, int $limit = 50): array
    {
        $rows = [];
        $stmt = $conn->prepare("
            SELECT id, stage_key, service_name, qty, unit_cost, total_cost, notes, created_at
            FROM job_service_costs
            WHERE job_id = ?
            ORDER BY id DESC
            LIMIT ?
        ");
        if (!$stmt) {
            return $rows;
        }
        $stmt->bind_param('ii', $jobId, $limit);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($res && ($row = $res->fetch_assoc())) {
            $rows[] = $row;
        }
        $stmt->close();
        return $rows;
    }
}

if (!function_exists('job_load_header_data')) {
    function job_load_header_data(mysqli $conn, int $jobId): ?array
    {
        static $cache = [];
        static $schema = null;
        if (isset($cache[$jobId])) {
            return $cache[$jobId];
        }
        if ($schema === null) {
            $hasColumn = static function (string $table, string $column) use ($conn): bool {
                if (function_exists('app_table_has_column')) {
                    return app_table_has_column($conn, $table, $column);
                }
                $safeTable = $conn->real_escape_string($table);
                $safeColumn = $conn->real_escape_string($column);
                $res = $conn->query("SHOW COLUMNS FROM `{$safeTable}` LIKE '{$safeColumn}'");
                return (bool)($res && $res->num_rows > 0);
            };
            $schema = [
                'has_order_number' => $hasColumn('job_orders', 'order_number'),
                'has_title' => $hasColumn('job_orders', 'title'),
                'has_activity_type' => $hasColumn('job_orders', 'activity_type'),
                'has_current_stage_id' => $hasColumn('job_orders', 'current_stage_id'),
                'has_workflow_stage_key' => $hasColumn('workflow_stages', 'stage_key'),
                'has_workflow_stage_name' => $hasColumn('workflow_stages', 'name'),
            ];
        }

        $selectFields = [
            'j.*',
            'c.name AS client_name',
            'c.phone AS client_phone',
        ];
        $joins = [
            'LEFT JOIN clients c ON j.client_id = c.id',
        ];
        if (!empty($schema['has_current_stage_id']) && !empty($schema['has_workflow_stage_key'])) {
            $selectFields[] = 'ws.stage_key AS workflow_stage_key';
            if (!empty($schema['has_workflow_stage_name'])) {
                $selectFields[] = 'ws.name AS workflow_stage_name';
            } else {
                $selectFields[] = "'' AS workflow_stage_name";
            }
            $joins[] = 'LEFT JOIN workflow_stages ws ON ws.id = j.current_stage_id';
        } else {
            $selectFields[] = "'' AS workflow_stage_key";
            $selectFields[] = "'' AS workflow_stage_name";
        }

        $stmt = $conn->prepare("
            SELECT
                " . implode(",\n                ", $selectFields) . "
            FROM job_orders j
            " . implode("\n            ", $joins) . "
            WHERE j.id = ?
            LIMIT 1
        ");
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('i', $jobId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (is_array($row)) {
            if (!isset($row['job_number']) || trim((string)$row['job_number']) === '') {
                $row['job_number'] = (string)($row['order_number'] ?? '');
            }
            if (!isset($row['job_name']) || trim((string)$row['job_name']) === '') {
                $row['job_name'] = (string)($row['title'] ?? '');
            }
            if (!isset($row['job_type']) || trim((string)$row['job_type']) === '') {
                $row['job_type'] = strtolower(trim((string)($row['activity_type'] ?? '')));
            } else {
                $row['job_type'] = strtolower(trim((string)$row['job_type']));
            }
            if (!isset($row['current_stage']) || trim((string)$row['current_stage']) === '') {
                $row['current_stage'] = (string)($row['workflow_stage_key'] ?? '');
            }
            if (!isset($row['current_stage_label']) || trim((string)$row['current_stage_label']) === '') {
                $row['current_stage_label'] = (string)($row['workflow_stage_name'] ?? '');
            }
            if ((!isset($row['status']) || trim((string)$row['status']) === '') && isset($row['job_status'])) {
                $row['status'] = (string)$row['job_status'];
            }
        }
        $cache[$jobId] = $row ?: null;
        return $cache[$jobId];
    }
}

if (!function_exists('job_resolve_id')) {
    function job_resolve_id(mysqli $conn, $jobRef): int
    {
        static $hasOrderNumber = null;
        if (is_int($jobRef) || ctype_digit((string)$jobRef)) {
            $rawId = (int)$jobRef;
            if ($rawId > 0) {
                $direct = job_load_header_data($conn, $rawId);
                if (is_array($direct)) {
                    return $rawId;
                }
            }
        }

        $jobNumber = strtoupper(trim((string)$jobRef));
        if ($jobNumber === '') {
            return 0;
        }

        if ($hasOrderNumber === null) {
            if (function_exists('app_table_has_column')) {
                $hasOrderNumber = app_table_has_column($conn, 'job_orders', 'order_number');
            } else {
                $res = $conn->query("SHOW COLUMNS FROM `job_orders` LIKE 'order_number'");
                $hasOrderNumber = (bool)($res && $res->num_rows > 0);
            }
        }

        $sql = $hasOrderNumber
            ? "SELECT id FROM job_orders WHERE job_number = ? OR order_number = ? LIMIT 1"
            : "SELECT id FROM job_orders WHERE job_number = ? LIMIT 1";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return 0;
        }
        if ($hasOrderNumber) {
            $stmt->bind_param('ss', $jobNumber, $jobNumber);
        } else {
            $stmt->bind_param('s', $jobNumber);
        }
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return (int)($row['id'] ?? 0);
    }
}

if (!function_exists('job_assignable_users')) {
    function job_assignable_users(mysqli $conn): array
    {
        static $cache = null;
        if (is_array($cache)) {
            return $cache;
        }
        $nameColumn = app_table_has_column($conn, 'users', 'full_name')
            ? 'full_name'
            : (app_table_has_column($conn, 'users', 'name') ? 'name' : 'email');
        $roleColumn = app_table_has_column($conn, 'users', 'role')
            ? 'role'
            : (app_table_has_column($conn, 'users', 'role_key') ? 'role_key' : "''");
        $phoneColumn = app_table_has_column($conn, 'users', 'phone')
            ? 'phone'
            : (app_table_has_column($conn, 'users', 'mobile') ? 'mobile' : "''");
        $statusClause = app_table_has_column($conn, 'users', 'status')
            ? " WHERE status IS NULL OR status IN ('', 'active')"
            : '';
        $rows = [];
        $users_rs = $conn->query("
            SELECT
                id,
                {$nameColumn} AS full_name,
                {$roleColumn} AS role,
                {$phoneColumn} AS phone
            FROM users
            {$statusClause}
            ORDER BY full_name ASC
        ");
        if ($users_rs) {
            while ($row = $users_rs->fetch_assoc()) {
                $rows[] = $row;
            }
        }
        $cache = $rows;
        return $cache;
    }
}

if (!function_exists('job_supplier_contacts')) {
    function job_supplier_contacts(mysqli $conn): array
    {
        static $cache = null;
        if (is_array($cache)) {
            return $cache;
        }
        $displayNameExpr = app_table_has_column($conn, 'suppliers', 'company_name')
            ? "TRIM(COALESCE(NULLIF(company_name, ''), name))"
            : 'name';
        $statusClause = app_table_has_column($conn, 'suppliers', 'status')
            ? " WHERE status IS NULL OR status IN ('', 'active')"
            : '';
        $rows = [];
        $res = $conn->query("
            SELECT
                id,
                name,
                {$displayNameExpr} AS display_name,
                phone,
                email
            FROM suppliers
            {$statusClause}
            ORDER BY display_name ASC
        ");
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $rows[] = $row;
            }
        }
        $cache = $rows;
        return $cache;
    }
}

if (!function_exists('job_whatsapp_phone')) {
    function job_whatsapp_phone(string $rawPhone): string
    {
        $digits = preg_replace('/\D+/', '', $rawPhone);
        if (!is_string($digits) || $digits === '') {
            return '';
        }
        if (strpos($digits, '00') === 0) {
            $digits = substr($digits, 2);
        }
        if (strpos($digits, '20') === 0) {
            return $digits;
        }
        $trimmed = ltrim($digits, '0');
        if ($trimmed === '') {
            return '';
        }
        if (strlen($trimmed) <= 11) {
            return '20' . $trimmed;
        }
        return $trimmed;
    }
}

if (!function_exists('job_whatsapp_link')) {
    function job_whatsapp_link(string $rawPhone, string $message): string
    {
        $phone = job_whatsapp_phone($rawPhone);
        if ($phone === '') {
            return '';
        }
        return 'https://wa.me/' . rawurlencode($phone) . '?text=' . rawurlencode($message);
    }
}

if (!function_exists('job_cost_warehouses')) {
    function job_cost_warehouses(mysqli $conn): array
    {
        static $cache = null;
        if (is_array($cache)) {
            return $cache;
        }
        $rows = [];
        $res = $conn->query("SELECT id, name FROM warehouses WHERE is_active = 1 ORDER BY name ASC");
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $rows[] = $row;
            }
        }
        $cache = $rows;
        return $cache;
    }
}

if (!function_exists('job_cost_items')) {
    function job_cost_items(mysqli $conn): array
    {
        static $cache = null;
        if (is_array($cache)) {
            return $cache;
        }
        $rows = [];
        $res = $conn->query("SELECT id, item_code, name, IFNULL(avg_unit_cost, 0) AS avg_unit_cost FROM inventory_items ORDER BY name ASC");
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $itemId = (int)($row['id'] ?? 0);
                if ($itemId > 0 && function_exists('app_inventory_item_avg_cost')) {
                    $row['avg_unit_cost'] = app_inventory_item_avg_cost($conn, $itemId);
                }
                $rows[] = $row;
            }
        }
        $cache = $rows;
        return $cache;
    }
}

if (!function_exists('job_service_catalog_map')) {
    function job_service_catalog_map(mysqli $conn, string $jobType): array
    {
        static $cache = [];
        $cacheKey = strtolower(trim($jobType));
        if (isset($cache[$cacheKey])) {
            return $cache[$cacheKey];
        }
        $rows = app_operation_catalog_entries($conn, $jobType, true);
        $map = [];
        foreach ($rows as $row) {
            $map[(string)$row['item_label']] = (float)($row['default_unit_price'] ?? 0);
        }
        $cache[$cacheKey] = ['rows' => $rows, 'map' => $map];
        return $cache[$cacheKey];
    }
}

if (!function_exists('job_stage_map_for_type')) {
    function job_stage_map_for_type(mysqli $conn, string $jobType): array
    {
        static $cache = [];
        $cacheKey = strtolower(trim($jobType));
        if (isset($cache[$cacheKey])) {
            return $cache[$cacheKey];
        }
        $stageMap = app_operation_stage_map($conn, $jobType, true);
        if (empty($stageMap)) {
            $stageMap = ['briefing' => '1. التجهيز'];
        }
        $cache[$cacheKey] = $stageMap;
        return $cache[$cacheKey];
    }
}

if (!function_exists('job_type_by_id')) {
    function job_type_by_id(mysqli $conn, int $jobId): string
    {
        $job = job_load_header_data($conn, $jobId);
        return strtolower(trim((string)($job['job_type'] ?? '')));
    }
}

if (!function_exists('job_archive_transition')) {
    function job_archive_transition(mysqli $conn, int $jobId): bool
    {
        if ($jobId <= 0) {
            throw new RuntimeException('invalid_job_id');
        }
        $jobType = job_type_by_id($conn, $jobId);
        $workflow = $jobType !== '' ? app_operation_workflow($conn, $jobType, ['briefing' => '1. التجهيز', 'completed' => 'الأرشيف']) : [];
        $archiveStage = !empty($workflow)
            ? (isset($workflow['completed']) ? 'completed' : (string)(array_key_last($workflow) ?: 'completed'))
            : 'completed';
        if ($archiveStage === '') {
            $archiveStage = 'completed';
        }
        return app_update_job_stage($conn, $jobId, $archiveStage, 'completed');
    }
}

if (!function_exists('job_reopen_transition')) {
    function job_reopen_transition(mysqli $conn, int $jobId): bool
    {
        if ($jobId <= 0) {
            throw new RuntimeException('invalid_job_id');
        }
        $jobType = job_type_by_id($conn, $jobId);
        $workflow = $jobType !== '' ? app_operation_workflow($conn, $jobType, ['briefing' => '1. التجهيز', 'completed' => 'الأرشيف']) : [];
        $firstStage = !empty($workflow) ? (string)(array_key_first($workflow) ?: 'briefing') : 'briefing';
        if ($firstStage === '') {
            $firstStage = 'briefing';
        }
        return app_update_job_stage($conn, $jobId, $firstStage, 'processing');
    }
}

if (!function_exists('job_stage_data_value')) {
    function job_stage_data_value(mysqli $conn, int $jobId, string $stageKey, string $fieldKey, string $default = ''): string
    {
        if ($jobId <= 0 || $stageKey === '' || $fieldKey === '' || !function_exists('app_stage_data_get')) {
            return $default;
        }
        $value = app_stage_data_get($conn, $jobId, $stageKey, $fieldKey, '');
        $value = trim((string)$value);
        return $value !== '' ? $value : $default;
    }
}

if (!function_exists('job_stage_files_count')) {
    function job_stage_files_count(mysqli $conn, int $jobId, string $stage): int
    {
        if ($jobId <= 0 || $stage === '') {
            return 0;
        }
        $safeStage = $conn->real_escape_string($stage);
        $row = $conn->query("SELECT COUNT(*) AS cnt FROM job_files WHERE job_id = {$jobId} AND stage = '{$safeStage}'")->fetch_assoc();
        return (int)($row['cnt'] ?? 0);
    }
}

if (!function_exists('job_stage_proofs_count')) {
    function job_stage_proofs_count(mysqli $conn, int $jobId): int
    {
        if ($jobId <= 0) {
            return 0;
        }
        $row = $conn->query("SELECT COUNT(*) AS cnt FROM job_proofs WHERE job_id = {$jobId}")->fetch_assoc();
        return (int)($row['cnt'] ?? 0);
    }
}

if (!function_exists('job_message_value')) {
    function job_message_value(string $value, int $limit = 240): string
    {
        $value = str_replace(["\r\n", "\r"], "\n", $value);
        $value = preg_replace('/[ \t]+/u', ' ', $value);
        $value = preg_replace('/\n{2,}/u', "\n", $value);
        $value = trim((string)$value);
        if ($value === '') {
            return '';
        }
        $value = str_replace("\n", ' | ', $value);
        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            if (mb_strlen($value, 'UTF-8') <= $limit) {
                return $value;
            }
            return rtrim(mb_substr($value, 0, $limit - 1, 'UTF-8')) . '…';
        }
        if (strlen($value) <= $limit) {
            return $value;
        }
        return rtrim(substr($value, 0, $limit - 1)) . '...';
    }
}

if (!function_exists('job_add_message_line')) {
    function job_add_message_line(array &$lines, string $label, string $value, int $limit = 240): void
    {
        $value = job_message_value($value, $limit);
        if ($value === '') {
            return;
        }
        $lines[] = $label . ': ' . $value;
    }
}

if (!function_exists('job_add_message_count')) {
    function job_add_message_count(array &$lines, string $label, int $count): void
    {
        if ($count <= 0) {
            return;
        }
        $lines[] = $label . ': ' . number_format($count);
    }
}

if (!function_exists('job_stage_request_title')) {
    function job_stage_request_title(string $stageKey, string $stageLabel): string
    {
        $map = [
            'briefing' => 'طلب تجهيز واعتماد بيانات التشغيل',
            'design' => 'طلب تنفيذ تصميم',
            'ui_design' => 'طلب تنفيذ تصميم واجهة',
            'client_rev' => 'طلب مراجعة واعتماد',
            'idea_review' => 'طلب مراجعة أفكار',
            'content_review' => 'طلب مراجعة محتوى',
            'design_review' => 'طلب مراجعة تصميم',
            'materials' => 'طلب توريد خامات',
            'pre_press' => 'طلب تجهيز ما قبل الطباعة',
            'printing' => 'طلب تشغيل طباعة',
            'finishing' => 'طلب تشطيب',
            'die_cutting' => 'طلب تكسير',
            'gluing' => 'طلب لصق',
            'cylinders' => 'طلب تجهيز سلندرات',
            'extrusion' => 'طلب سحب',
            'cutting' => 'طلب قص',
            'development' => 'طلب تطوير وتنفيذ',
            'testing' => 'طلب اختبار ومراجعة',
            'handover' => 'طلب تسليم مخرجات',
            'publishing' => 'طلب نشر وتسليم',
            'delivery' => 'طلب تسليم',
            'launch' => 'طلب إطلاق',
            'accounting' => 'طلب متابعة حسابية',
            'completed' => 'متابعة ختامية',
        ];
        return $map[$stageKey] ?? ('أمر تشغيل خارجي - ' . $stageLabel);
    }
}

if (!function_exists('job_external_public_link')) {
    function job_external_public_link(mysqli $conn, array $job): string
    {
        $jobId = (int)($job['id'] ?? 0);
        if ($jobId <= 0 || !function_exists('app_job_access_token')) {
            return '';
        }
        $token = trim((string)app_job_access_token($conn, $job));
        if ($token === '') {
            return '';
        }
        $baseUrl = function_exists('app_base_url')
            ? rtrim((string)app_base_url(), '/')
            : (((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http') . '://' . (string)($_SERVER['HTTP_HOST'] ?? ''));
        $basePath = rtrim((string)dirname((string)($_SERVER['PHP_SELF'] ?? '/job_fix/job_details.php')), '/\\');
        if ($basePath === '.' || $basePath === '/') {
            $basePath = '';
        }
        return $baseUrl . $basePath . '/view_order.php?id=' . $jobId . '&token=' . rawurlencode($token);
    }
}

if (!function_exists('job_first_non_empty_value')) {
    function job_first_non_empty_value(array $row, array $keys): string
    {
        foreach ($keys as $key) {
            $value = trim((string)($row[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }
        return '';
    }
}

if (!function_exists('job_external_stage_payloads')) {
    function job_external_stage_payloads(mysqli $conn, array $job, array $jobStageMap): array
    {
        $jobId = (int)($job['id'] ?? 0);
        if ($jobId <= 0 || empty($jobStageMap)) {
            return [];
        }

        $jobType = strtolower(trim((string)($job['job_type'] ?? '')));
        $quantity = job_first_non_empty_value($job, ['quantity', 'qty']);
        $dueDate = job_first_non_empty_value($job, ['delivery_date', 'due_date', 'deadline', 'target_date']);
        $jobDetails = job_message_value((string)($job['job_details'] ?? ''), 260);
        $briefingSummary = job_stage_data_value($conn, $jobId, 'briefing', 'briefing_summary', '');
        $materialsSummary = job_stage_data_value($conn, $jobId, 'materials', 'materials_summary', '');
        $printSpecsSummary = job_stage_data_value($conn, $jobId, 'printing', 'print_specs_summary', '');
        $finishingSummary = job_stage_data_value($conn, $jobId, 'finishing', 'production_summary', '');
        $deliverySummary = job_stage_data_value($conn, $jobId, 'delivery', 'production_summary', '');
        $cylindersSummary = job_stage_data_value($conn, $jobId, 'cylinders', 'cylinders_summary', '');
        $productionSummary = job_stage_data_value($conn, $jobId, 'printing', 'production_summary', '');
        $requirements = job_stage_data_value($conn, $jobId, 'briefing', 'requirements', '');
        $devUrl = job_stage_data_value($conn, $jobId, 'development', 'dev_url', '');
        $devNotes = job_stage_data_value($conn, $jobId, 'development', 'dev_notes', '');
        $testingReport = job_stage_data_value($conn, $jobId, 'testing', 'testing_report', '');
        $imaginationNotes = job_stage_data_value($conn, $jobId, 'briefing', 'imagination_notes', '');
        $handoverLink = job_stage_data_value($conn, $jobId, 'handover', 'source_link', '');
        $ideasSummary = job_stage_data_value($conn, $jobId, 'briefing', 'ideas_summary', '');
        $contentSummary = job_stage_data_value($conn, $jobId, 'content_writing', 'content_summary', '');
        $designsSummary = job_stage_data_value($conn, $jobId, 'designing', 'designs_summary', '');
        $genericSummary = job_stage_data_value($conn, $jobId, (string)($job['current_stage'] ?? 'briefing'), 'stage_update_summary', '');
        $proofsCount = job_stage_proofs_count($conn, $jobId);
        $briefingFiles = job_stage_files_count($conn, $jobId, 'briefing');
        $materialsFiles = job_stage_files_count($conn, $jobId, 'materials');
        $prepressFiles = job_stage_files_count($conn, $jobId, 'pre_press') + job_stage_files_count($conn, $jobId, 'pre_press_supplies');
        $cylindersFiles = job_stage_files_count($conn, $jobId, 'cylinders');
        $ideaFiles = job_stage_files_count($conn, $jobId, 'idea');
        $contentFiles = job_stage_files_count($conn, $jobId, 'content');
        $designSourceFiles = job_stage_files_count($conn, $jobId, 'design');
        $handoverFiles = job_stage_files_count($conn, $jobId, 'handover');
        $publicLink = job_external_public_link($conn, $job);

        $operationLines = [];
        job_add_message_line($operationLines, 'رقم العملية', (string)($job['job_number'] ?? ('#' . $jobId)));
        job_add_message_line($operationLines, 'اسم العملية', (string)($job['job_name'] ?? ('#' . $jobId)));
        job_add_message_line($operationLines, 'العميل', (string)($job['client_name'] ?? ''));
        job_add_message_line($operationLines, 'نوع العملية', (string)($job['job_type'] ?? ''));
        job_add_message_line($operationLines, 'الكمية', $quantity);
        job_add_message_line($operationLines, 'موعد التسليم', $dueDate);

        $payloads = [];
        foreach ($jobStageMap as $stageKey => $stageLabel) {
            $requestLines = [];
            $detailLines = [];

            switch ($jobType) {
                case 'print':
                case 'carton':
                    if (in_array($stageKey, ['briefing', 'design', 'client_rev'], true)) {
                        job_add_message_line($requestLines, 'ملخص التجهيز', $briefingSummary);
                        job_add_message_count($detailLines, 'عدد ملفات التجهيز', $briefingFiles);
                        job_add_message_count($detailLines, 'عدد البروفات', $proofsCount);
                    } elseif ($stageKey === 'materials') {
                        job_add_message_line($requestLines, 'الخامات المطلوبة', $materialsSummary);
                        job_add_message_count($detailLines, 'عدد ملفات الخامات', $materialsFiles);
                        job_add_message_line($detailLines, 'مرجع التجهيز', $briefingSummary);
                    } elseif ($stageKey === 'pre_press') {
                        job_add_message_line($requestLines, 'مواصفات الطباعة', $printSpecsSummary);
                        job_add_message_count($detailLines, 'عدد ملفات ما قبل الطباعة', $prepressFiles);
                        job_add_message_count($detailLines, 'عدد البروفات', $proofsCount);
                    } elseif (in_array($stageKey, ['printing', 'finishing', 'die_cutting', 'gluing', 'delivery'], true)) {
                        job_add_message_line($requestLines, 'مواصفات الطباعة', $printSpecsSummary);
                        job_add_message_line($requestLines, 'ملخص التنفيذ', $deliverySummary !== '' ? $deliverySummary : $finishingSummary);
                        job_add_message_line($detailLines, 'الخامات المرجعية', $materialsSummary);
                    }
                    break;

                case 'plastic':
                    if (in_array($stageKey, ['briefing', 'design', 'client_rev'], true)) {
                        job_add_message_line($requestLines, 'ملخص التجهيز', $briefingSummary);
                        job_add_message_count($detailLines, 'عدد البروفات', $proofsCount);
                    } elseif ($stageKey === 'cylinders') {
                        job_add_message_line($requestLines, 'بيانات السلندرات', $cylindersSummary);
                        job_add_message_count($detailLines, 'عدد ملفات السلندرات', $cylindersFiles);
                    } elseif (in_array($stageKey, ['extrusion', 'printing', 'cutting', 'delivery'], true)) {
                        job_add_message_line($requestLines, 'ملخص التشغيل', $productionSummary);
                        job_add_message_line($detailLines, 'مرجع السلندرات', $cylindersSummary);
                    }
                    break;

                case 'web':
                    if ($stageKey === 'briefing') {
                        job_add_message_line($requestLines, 'متطلبات المشروع', $requirements);
                        job_add_message_count($detailLines, 'عدد ملفات المرجع', $briefingFiles);
                    } elseif (in_array($stageKey, ['ui_design', 'client_rev'], true)) {
                        job_add_message_line($requestLines, 'متطلبات المشروع', $requirements);
                        job_add_message_count($detailLines, 'عدد ملفات الواجهة/البروفات', $proofsCount);
                    } elseif ($stageKey === 'development') {
                        job_add_message_line($requestLines, 'ملاحظات التطوير', $devNotes);
                        job_add_message_line($detailLines, 'متطلبات المشروع', $requirements);
                        job_add_message_line($detailLines, 'رابط المعاينة', $devUrl);
                    } elseif (in_array($stageKey, ['testing', 'launch'], true)) {
                        job_add_message_line($requestLines, 'تقرير الاختبار', $testingReport);
                        job_add_message_line($detailLines, 'رابط المعاينة', $devUrl);
                    }
                    break;

                case 'design_only':
                    if ($stageKey === 'briefing') {
                        job_add_message_line($requestLines, 'ملاحظات التجهيز', $imaginationNotes);
                        job_add_message_count($detailLines, 'عدد ملفات المرجع', $briefingFiles);
                    } elseif (in_array($stageKey, ['design', 'client_rev'], true)) {
                        job_add_message_line($requestLines, 'اتجاه التصميم', $imaginationNotes);
                        job_add_message_count($detailLines, 'عدد البروفات', $proofsCount);
                    } elseif ($stageKey === 'handover') {
                        job_add_message_line($requestLines, 'رابط/مرجع التسليم', $handoverLink);
                        job_add_message_count($detailLines, 'عدد ملفات التسليم', $handoverFiles);
                    }
                    break;

                case 'social':
                    if (in_array($stageKey, ['briefing', 'idea_review'], true)) {
                        job_add_message_line($requestLines, 'ملخص الأفكار', $ideasSummary);
                        job_add_message_count($detailLines, 'عدد ملفات المرجع', $ideaFiles);
                    } elseif (in_array($stageKey, ['content_writing', 'content_review'], true)) {
                        job_add_message_line($requestLines, 'ملخص المحتوى', $contentSummary);
                        job_add_message_line($detailLines, 'ملخص الأفكار', $ideasSummary);
                        job_add_message_count($detailLines, 'عدد ملفات المحتوى', $contentFiles);
                    } elseif (in_array($stageKey, ['designing', 'design_review', 'publishing'], true)) {
                        job_add_message_line($requestLines, 'ملخص التصميمات', $designsSummary);
                        job_add_message_line($detailLines, 'ملخص المحتوى', $contentSummary);
                        job_add_message_count($detailLines, 'عدد ملفات التصميم المصدر', $designSourceFiles);
                    }
                    break;
            }

            if (empty($requestLines)) {
                $stageSummary = job_stage_data_value($conn, $jobId, $stageKey, 'stage_update_summary', '');
                $stageFilesCount = (int)job_stage_data_value($conn, $jobId, $stageKey, 'stage_files_count', '0');
                job_add_message_line($requestLines, 'ملخص المرحلة', $stageSummary !== '' ? $stageSummary : $briefingSummary);
                job_add_message_count($detailLines, 'عدد ملفات المرحلة', $stageFilesCount);
            }

            if (empty($requestLines) && $jobDetails !== '') {
                $requestLines[] = 'الرجوع لبيانات العملية الأساسية: ' . $jobDetails;
            } elseif ($jobDetails !== '' && count($detailLines) < 2) {
                $detailLines[] = 'بيانات إضافية: ' . $jobDetails;
            }

            $requestLines = array_values(array_unique(array_filter($requestLines, static function ($line): bool {
                return trim((string)$line) !== '';
            })));
            $detailLines = array_values(array_unique(array_filter($detailLines, static function ($line): bool {
                return trim((string)$line) !== '';
            })));

            $payloads[$stageKey] = [
                'stage_key' => (string)$stageKey,
                'stage_label' => (string)$stageLabel,
                'order_title' => job_stage_request_title((string)$stageKey, (string)$stageLabel),
                'operation_lines' => $operationLines,
                'requirement_lines' => $requestLines,
                'detail_lines' => $detailLines,
                'action_line' => 'يرجى تأكيد الاستلام، مراجعة المتطلبات، وتحديد مدة التنفيذ قبل البدء.',
                'public_link' => $publicLink,
            ];
        }

        return $payloads;
    }
}

if (!function_exists('job_view_context')) {
    function job_view_context(mysqli $conn, int $jobId, bool $canManageAcl, string $jobType, int $editingServiceCostId = 0): array
    {
        $jobAssignments = app_job_assignments($conn, $jobId);
        $employeeContacts = job_assignable_users($conn);
        $assignableUsers = $canManageAcl ? $employeeContacts : [];
        $assignmentIndex = [];
        foreach ($jobAssignments as $assignmentRow) {
            $assignmentUserId = (int)($assignmentRow['user_id'] ?? 0);
            if ($assignmentUserId > 0) {
                $assignmentIndex[$assignmentUserId] = $assignmentRow;
            }
        }
        foreach ($employeeContacts as &$employeeContact) {
            $employeeId = (int)($employeeContact['id'] ?? 0);
            $employeePhone = trim((string)($employeeContact['phone'] ?? ''));
            $employeeContact['phone'] = $employeePhone;
            $employeeContact['whatsapp_phone'] = job_whatsapp_phone($employeePhone);
            $employeeContact['has_phone'] = ($employeePhone !== '');
            $employeeContact['is_assigned'] = isset($assignmentIndex[$employeeId]);
            $employeeContact['assigned_role'] = (string)($assignmentIndex[$employeeId]['assigned_role'] ?? '');
        }
        unset($employeeContact);
        usort($employeeContacts, static function (array $a, array $b): int {
            $aAssigned = !empty($a['is_assigned']) ? 1 : 0;
            $bAssigned = !empty($b['is_assigned']) ? 1 : 0;
            if ($aAssigned !== $bAssigned) {
                return $bAssigned <=> $aAssigned;
            }
            return strcasecmp((string)($a['full_name'] ?? ''), (string)($b['full_name'] ?? ''));
        });
        $supplierContacts = job_supplier_contacts($conn);
        foreach ($supplierContacts as &$supplierContact) {
            $supplierPhone = trim((string)($supplierContact['phone'] ?? ''));
            $supplierContact['phone'] = $supplierPhone;
            $supplierContact['whatsapp_phone'] = job_whatsapp_phone($supplierPhone);
            $supplierContact['has_phone'] = ($supplierPhone !== '');
        }
        unset($supplierContact);
        $jobStageMap = job_stage_map_for_type($conn, $jobType);
        $jobFinancial = app_job_financial_summary($conn, $jobId);
        $costWarehouses = job_cost_warehouses($conn);
        $costItems = job_cost_items($conn);
        $serviceCatalogBundle = job_service_catalog_map($conn, $jobType);
        $materialCostRows = job_material_cost_rows($conn, $jobId, 50);
        $serviceCostRows = job_service_cost_rows($conn, $jobId, 50);
        $jobHeader = job_load_header_data($conn, $jobId) ?: ['id' => $jobId, 'job_type' => $jobType];
        $externalStagePayloads = job_external_stage_payloads($conn, $jobHeader, $jobStageMap);

        $serviceCostEditRow = null;
        if ($editingServiceCostId > 0) {
            foreach ($serviceCostRows as $candidateServiceRow) {
                if ((int)($candidateServiceRow['id'] ?? 0) === $editingServiceCostId) {
                    $serviceCostEditRow = $candidateServiceRow;
                    break;
                }
            }
        }

        return [
            'job_assignments' => $jobAssignments,
            'assignable_users' => $assignableUsers,
            'employee_contacts' => $employeeContacts,
            'supplier_contacts' => $supplierContacts,
            'job_stage_map' => $jobStageMap,
            'job_financial' => $jobFinancial,
            'cost_warehouses' => $costWarehouses,
            'cost_items' => $costItems,
            'service_catalog_rows' => $serviceCatalogBundle['rows'],
            'service_catalog_map' => $serviceCatalogBundle['map'],
            'material_cost_rows' => $materialCostRows,
            'service_cost_rows' => $serviceCostRows,
            'service_cost_edit_row' => $serviceCostEditRow,
            'external_stage_payloads' => $externalStagePayloads,
        ];
    }
}

if (!function_exists('job_profitability_summary')) {
    function job_profitability_summary(array $jobFinancial, callable $translator): array
    {
        $status = (string)($jobFinancial['status'] ?? '');
        $profitClass = '';
        $profitText = $translator('العملية متعادلة', 'Job is neutral');
        if ($status === 'profit') {
            $profitClass = 'profit';
            $profitText = $translator('العملية رابحة', 'Job is profitable');
        } elseif ($status === 'loss') {
            $profitClass = 'loss';
            $profitText = $translator('العملية خاسرة', 'Job is losing');
        }

        return [
            'status' => $status,
            'profit_class' => $profitClass,
            'profit_text' => $profitText,
            'cards' => [
                [
                    'label' => $translator('إيراد العملية', 'Job revenue'),
                    'value' => number_format((float)($jobFinancial['revenue'] ?? 0), 2) . ' EGP',
                    'class' => '',
                ],
                [
                    'label' => $translator('الفواتير المرتبطة / المحصل', 'Linked invoices / collected'),
                    'value' => (int)($jobFinancial['invoice_count'] ?? 0) . ' / ' . number_format((float)($jobFinancial['paid_revenue'] ?? 0), 2) . ' EGP',
                    'class' => '',
                ],
                [
                    'label' => $translator('المتبقي على فواتير العملية', 'Outstanding job invoices'),
                    'value' => number_format((float)($jobFinancial['remaining_revenue'] ?? 0), 2) . ' EGP',
                    'class' => '',
                ],
                [
                    'label' => $translator('تكلفة الخامات المصروفة', 'Issued material cost'),
                    'value' => number_format((float)($jobFinancial['material_cost'] ?? 0), 2) . ' EGP',
                    'class' => '',
                ],
                [
                    'label' => $translator('تكلفة الخدمات/المراحل', 'Service/stage cost'),
                    'value' => number_format((float)($jobFinancial['service_cost'] ?? 0), 2) . ' EGP',
                    'class' => '',
                ],
                [
                    'label' => $translator('صافي العملية (ربح/خسارة)', 'Net result (profit/loss)'),
                    'value' => number_format((float)($jobFinancial['profit'] ?? 0), 2) . ' EGP',
                    'class' => $profitClass,
                ],
            ],
        ];
    }
}
