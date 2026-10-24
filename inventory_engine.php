<?php

if (!function_exists('inventory_fetch_available_qty')) {
    function inventory_fetch_available_qty(mysqli $conn, int $itemId, int $warehouseId, bool $forUpdate = false): float
    {
        $sql = "SELECT quantity FROM inventory_stock WHERE item_id = ? AND warehouse_id = ?" . ($forUpdate ? " FOR UPDATE" : "");
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new RuntimeException($conn->error);
        }
        $stmt->bind_param('ii', $itemId, $warehouseId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ? (float)$row['quantity'] : 0.0;
    }
}

if (!function_exists('inventory_apply_stock_delta')) {
    function inventory_apply_stock_delta(mysqli $conn, int $itemId, int $warehouseId, float $deltaQty): void
    {
        $stmt = $conn->prepare("
            INSERT INTO inventory_stock (item_id, warehouse_id, quantity)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE quantity = quantity + VALUES(quantity)
        ");
        if (!$stmt) {
            throw new RuntimeException($conn->error);
        }
        $stmt->bind_param('iid', $itemId, $warehouseId, $deltaQty);
        if (!$stmt->execute()) {
            $err = $stmt->error ?: $conn->error;
            $stmt->close();
            throw new RuntimeException($err);
        }
        $stmt->close();
    }
}

if (!function_exists('inventory_insert_transaction')) {
    function inventory_insert_transaction(
        mysqli $conn,
        int $itemId,
        int $warehouseId,
        int $userId,
        string $transactionType,
        float $quantity,
        string $notes = '',
        int $relatedOrderId = 0,
        float $unitCost = 0.0,
        float $totalCost = 0.0,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?string $stageKey = null
    ): void {
        $stmt = $conn->prepare("
            INSERT INTO inventory_transactions (
                item_id, warehouse_id, user_id, transaction_type, quantity,
                related_order_id, notes, unit_cost, total_cost, reference_type, reference_id, stage_key
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        if (!$stmt) {
            throw new RuntimeException($conn->error);
        }
        $relatedOrderValue = $relatedOrderId > 0 ? $relatedOrderId : null;
        $referenceIdValue = ($referenceId !== null && $referenceId > 0) ? $referenceId : null;
        $referenceTypeValue = ($referenceType !== null && $referenceType !== '') ? $referenceType : null;
        $stageKeyValue = ($stageKey !== null && $stageKey !== '') ? $stageKey : null;
        $stmt->bind_param(
            'iiisdisddsis',
            $itemId,
            $warehouseId,
            $userId,
            $transactionType,
            $quantity,
            $relatedOrderValue,
            $notes,
            $unitCost,
            $totalCost,
            $referenceTypeValue,
            $referenceIdValue,
            $stageKeyValue
        );
        if (!$stmt->execute()) {
            $err = $stmt->error ?: $conn->error;
            $stmt->close();
            throw new RuntimeException($err);
        }
        $stmt->close();
    }
}

if (!function_exists('inventory_manual_adjustment')) {
    function inventory_manual_adjustment(
        mysqli $conn,
        int $itemId,
        int $warehouseId,
        int $userId,
        string $transactionType,
        float $quantity,
        string $notes = '',
        float $manualUnitCost = 0.0
    ): void {
        if ($itemId <= 0 || $warehouseId <= 0 || $quantity <= 0) {
            throw new InvalidArgumentException('invalid_inventory_adjustment');
        }
        if (!in_array($transactionType, ['in', 'out'], true)) {
            throw new InvalidArgumentException('invalid_inventory_transaction_type');
        }

        if ($transactionType === 'out') {
            $available = inventory_fetch_available_qty($conn, $itemId, $warehouseId, true);
            if ($available < $quantity) {
                throw new RuntimeException('insufficient_stock');
            }
        }

        $signedQty = ($transactionType === 'out') ? -$quantity : $quantity;
        $unitCost = 0.0;
        $totalCost = 0.0;
        $referenceType = ($transactionType === 'out') ? 'manual_out' : 'manual_in';
        if ($transactionType === 'out' && function_exists('app_inventory_item_avg_cost')) {
            $unitCost = app_inventory_item_avg_cost($conn, $itemId);
            $totalCost = round($quantity * $unitCost, 2);
        } elseif ($transactionType === 'in' && $manualUnitCost > 0) {
            $unitCost = $manualUnitCost;
            $totalCost = round($quantity * $unitCost, 2);
            if (function_exists('app_inventory_apply_purchase_cost')) {
                app_inventory_apply_purchase_cost($conn, $itemId, $quantity, $unitCost);
            }
        }
        inventory_insert_transaction(
            $conn,
            $itemId,
            $warehouseId,
            $userId,
            $transactionType,
            $signedQty,
            $notes,
            0,
            $unitCost,
            $totalCost,
            $referenceType,
            null
        );
        inventory_apply_stock_delta($conn, $itemId, $warehouseId, $signedQty);
    }
}

if (!function_exists('inventory_transfer_between_warehouses')) {
    function inventory_transfer_between_warehouses(
        mysqli $conn,
        int $itemId,
        int $fromWarehouseId,
        int $toWarehouseId,
        int $userId,
        float $quantity,
        string $notes = ''
    ): void {
        if ($itemId <= 0 || $fromWarehouseId <= 0 || $toWarehouseId <= 0 || $fromWarehouseId === $toWarehouseId || $quantity <= 0) {
            throw new InvalidArgumentException('invalid_inventory_transfer');
        }

        $available = inventory_fetch_available_qty($conn, $itemId, $fromWarehouseId, true);
        if ($available < $quantity) {
            throw new RuntimeException('insufficient_stock');
        }

        $noteOut = "تحويل إلى مخزن #{$toWarehouseId}" . ($notes !== '' ? '. ' . $notes : '');
        $noteIn = "تحويل من مخزن #{$fromWarehouseId}" . ($notes !== '' ? '. ' . $notes : '');

        $unitCost = function_exists('app_inventory_item_avg_cost') ? app_inventory_item_avg_cost($conn, $itemId) : 0.0;
        $totalCost = round($quantity * $unitCost, 2);

        inventory_insert_transaction($conn, $itemId, $fromWarehouseId, $userId, 'transfer', -$quantity, $noteOut, 0, $unitCost, $totalCost, 'warehouse_transfer', null);
        inventory_insert_transaction($conn, $itemId, $toWarehouseId, $userId, 'transfer', $quantity, $noteIn, 0, $unitCost, $totalCost, 'warehouse_transfer', null);
        inventory_apply_stock_delta($conn, $itemId, $fromWarehouseId, -$quantity);
        inventory_apply_stock_delta($conn, $itemId, $toWarehouseId, $quantity);
    }
}

if (!function_exists('inventory_create_audit_session')) {
    function inventory_create_audit_session(
        mysqli $conn,
        int $warehouseId,
        int $userId,
        string $auditDate,
        string $title = '',
        string $notes = ''
    ): int {
        if ($warehouseId <= 0) {
            throw new InvalidArgumentException('invalid_audit_warehouse');
        }
        if ($auditDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $auditDate)) {
            throw new InvalidArgumentException('invalid_audit_date');
        }

        $stmtWh = $conn->prepare("SELECT id, name FROM warehouses WHERE id = ? LIMIT 1");
        if (!$stmtWh) {
            throw new RuntimeException($conn->error);
        }
        $stmtWh->bind_param('i', $warehouseId);
        $stmtWh->execute();
        $warehouse = $stmtWh->get_result()->fetch_assoc();
        $stmtWh->close();
        if (!$warehouse) {
            throw new RuntimeException('warehouse_not_found');
        }

        $finalTitle = trim($title);
        if ($finalTitle === '') {
            $finalTitle = 'جرد ' . (string)($warehouse['name'] ?? 'المخزن') . ' - ' . $auditDate;
        }

        $stmtSession = $conn->prepare("
            INSERT INTO inventory_audit_sessions (
                warehouse_id, audit_date, title, notes, status, created_by_user_id
            ) VALUES (?, ?, ?, ?, 'draft', ?)
        ");
        if (!$stmtSession) {
            throw new RuntimeException($conn->error);
        }
        $stmtSession->bind_param('isssi', $warehouseId, $auditDate, $finalTitle, $notes, $userId);
        if (!$stmtSession->execute()) {
            $err = $stmtSession->error ?: $conn->error;
            $stmtSession->close();
            throw new RuntimeException($err);
        }
        $sessionId = (int)$stmtSession->insert_id;
        $stmtSession->close();

        $stmtItems = $conn->prepare("
            SELECT
                i.id AS item_id,
                COALESCE(s.quantity, 0) AS system_qty
            FROM inventory_items i
            LEFT JOIN inventory_stock s
                ON s.item_id = i.id
               AND s.warehouse_id = ?
            ORDER BY i.name ASC
        ");
        if (!$stmtItems) {
            throw new RuntimeException($conn->error);
        }
        $stmtItems->bind_param('i', $warehouseId);
        $stmtItems->execute();
        $itemsRes = $stmtItems->get_result();

        $stmtLine = $conn->prepare("
            INSERT INTO inventory_audit_lines (session_id, item_id, system_qty, counted_qty, variance_qty, notes)
            VALUES (?, ?, ?, NULL, 0, NULL)
        ");
        if (!$stmtLine) {
            $stmtItems->close();
            throw new RuntimeException($conn->error);
        }

        while ($row = $itemsRes->fetch_assoc()) {
            $itemId = (int)($row['item_id'] ?? 0);
            $systemQty = (float)($row['system_qty'] ?? 0);
            if ($itemId <= 0) {
                continue;
            }
            $stmtLine->bind_param('iid', $sessionId, $itemId, $systemQty);
            if (!$stmtLine->execute()) {
                $err = $stmtLine->error ?: $conn->error;
                $stmtLine->close();
                $stmtItems->close();
                throw new RuntimeException($err);
            }
        }

        $stmtLine->close();
        $stmtItems->close();

        return $sessionId;
    }
}

if (!function_exists('inventory_update_audit_count')) {
    function inventory_update_audit_count(
        mysqli $conn,
        int $sessionId,
        int $itemId,
        float $countedQty,
        int $userId,
        string $note = ''
    ): void {
        if ($sessionId <= 0 || $itemId <= 0) {
            throw new InvalidArgumentException('invalid_audit_line');
        }
        if ($countedQty < 0) {
            throw new InvalidArgumentException('invalid_audit_count');
        }

        $stmtSession = $conn->prepare("SELECT status FROM inventory_audit_sessions WHERE id = ? LIMIT 1");
        if (!$stmtSession) {
            throw new RuntimeException($conn->error);
        }
        $stmtSession->bind_param('i', $sessionId);
        $stmtSession->execute();
        $session = $stmtSession->get_result()->fetch_assoc();
        $stmtSession->close();
        if (!$session) {
            throw new RuntimeException('audit_session_not_found');
        }
        if ((string)($session['status'] ?? '') !== 'draft') {
            throw new RuntimeException('audit_session_locked');
        }

        $stmtLine = $conn->prepare("SELECT system_qty FROM inventory_audit_lines WHERE session_id = ? AND item_id = ? LIMIT 1");
        if (!$stmtLine) {
            throw new RuntimeException($conn->error);
        }
        $stmtLine->bind_param('ii', $sessionId, $itemId);
        $stmtLine->execute();
        $line = $stmtLine->get_result()->fetch_assoc();
        $stmtLine->close();
        if (!$line) {
            throw new RuntimeException('audit_line_not_found');
        }

        $systemQty = (float)($line['system_qty'] ?? 0);
        $varianceQty = round($countedQty - $systemQty, 2);

        $stmtUpdate = $conn->prepare("
            UPDATE inventory_audit_lines
            SET counted_qty = ?, variance_qty = ?, notes = ?, counted_by_user_id = ?, counted_at = NOW()
            WHERE session_id = ? AND item_id = ?
        ");
        if (!$stmtUpdate) {
            throw new RuntimeException($conn->error);
        }
        $stmtUpdate->bind_param('ddsiii', $countedQty, $varianceQty, $note, $userId, $sessionId, $itemId);
        if (!$stmtUpdate->execute()) {
            $err = $stmtUpdate->error ?: $conn->error;
            $stmtUpdate->close();
            throw new RuntimeException($err);
        }
        $stmtUpdate->close();
    }
}

if (!function_exists('inventory_apply_audit_session')) {
    function inventory_apply_audit_session(mysqli $conn, int $sessionId, int $userId): void
    {
        if ($sessionId <= 0) {
            throw new InvalidArgumentException('invalid_audit_session');
        }

        $stmtSession = $conn->prepare("
            SELECT id, warehouse_id, title, status
            FROM inventory_audit_sessions
            WHERE id = ?
            LIMIT 1
            FOR UPDATE
        ");
        if (!$stmtSession) {
            throw new RuntimeException($conn->error);
        }
        $stmtSession->bind_param('i', $sessionId);
        $stmtSession->execute();
        $session = $stmtSession->get_result()->fetch_assoc();
        $stmtSession->close();
        if (!$session) {
            throw new RuntimeException('audit_session_not_found');
        }
        if ((string)($session['status'] ?? '') !== 'draft') {
            throw new RuntimeException('audit_session_already_applied');
        }

        $warehouseId = (int)($session['warehouse_id'] ?? 0);
        if ($warehouseId <= 0) {
            throw new RuntimeException('audit_warehouse_missing');
        }

        $stmtLines = $conn->prepare("
            SELECT item_id, counted_qty, variance_qty
            FROM inventory_audit_lines
            WHERE session_id = ?
            ORDER BY item_id ASC
        ");
        if (!$stmtLines) {
            throw new RuntimeException($conn->error);
        }
        $stmtLines->bind_param('i', $sessionId);
        $stmtLines->execute();
        $resLines = $stmtLines->get_result();

        $hasCountedLines = false;
        while ($line = $resLines->fetch_assoc()) {
            if ($line['counted_qty'] === null) {
                continue;
            }

            $hasCountedLines = true;
            $itemId = (int)($line['item_id'] ?? 0);
            $varianceQty = round((float)($line['variance_qty'] ?? 0), 2);
            if ($itemId <= 0 || abs($varianceQty) <= 0.00001) {
                continue;
            }

            $avgCost = app_inventory_item_avg_cost($conn, $itemId);
            inventory_apply_stock_delta($conn, $itemId, $warehouseId, $varianceQty);
            inventory_insert_transaction(
                $conn,
                $itemId,
                $warehouseId,
                $userId,
                'adjustment',
                $varianceQty,
                'تسوية جرد جلسة #' . $sessionId . ' - ' . (string)($session['title'] ?? 'جرد مخزني'),
                0,
                $avgCost,
                round(abs($varianceQty) * $avgCost, 2),
                'audit_session',
                $sessionId
            );
        }
        $stmtLines->close();

        if (!$hasCountedLines) {
            throw new RuntimeException('audit_session_has_no_counts');
        }

        $stmtApply = $conn->prepare("
            UPDATE inventory_audit_sessions
            SET status = 'applied', applied_by_user_id = ?, applied_at = NOW()
            WHERE id = ?
        ");
        if (!$stmtApply) {
            throw new RuntimeException($conn->error);
        }
        $stmtApply->bind_param('ii', $userId, $sessionId);
        if (!$stmtApply->execute()) {
            $err = $stmtApply->error ?: $conn->error;
            $stmtApply->close();
            throw new RuntimeException($err);
        }
        $stmtApply->close();
        app_audit_log_add($conn, 'inventory.audit_applied', [
            'user_id' => $userId,
            'entity_type' => 'inventory_audit_session',
            'entity_key' => (string)$sessionId,
            'details' => [
                'warehouse_id' => $warehouseId,
                'title' => (string)($session['title'] ?? ''),
            ],
        ]);
    }
}

if (!function_exists('inventory_find_catalog_item_id')) {
    function inventory_find_catalog_item_id(mysqli $conn, array $purchaseLine): int
    {
        $existingItemId = (int)($purchaseLine['item_id'] ?? 0);
        if ($existingItemId > 0) {
            return $existingItemId;
        }

        static $catalogCodeColumn = null;
        static $codeCache = [];
        static $nameCache = [];
        $normalizeKey = static function (string $value): string {
            $value = preg_replace('/\s+/', ' ', trim($value));
            if (function_exists('mb_strtolower')) {
                return mb_strtolower($value, 'UTF-8');
            }
            return strtolower($value);
        };

        if ($catalogCodeColumn === null) {
            if (function_exists('app_table_has_column') && app_table_has_column($conn, 'inventory_items', 'item_code')) {
                $catalogCodeColumn = 'item_code';
            } elseif (function_exists('app_table_has_column') && app_table_has_column($conn, 'inventory_items', 'sku')) {
                $catalogCodeColumn = 'sku';
            } else {
                $catalogCodeColumn = '';
            }
        }

        $itemCode = trim((string)($purchaseLine['item_code'] ?? ''));
        if ($itemCode !== '' && $catalogCodeColumn !== '') {
            $codeKey = $normalizeKey($itemCode);
            if (array_key_exists($codeKey, $codeCache)) {
                return (int)$codeCache[$codeKey];
            }
            $stmtByCode = $conn->prepare("SELECT id FROM inventory_items WHERE TRIM({$catalogCodeColumn}) = ? LIMIT 1");
            if ($stmtByCode) {
                $stmtByCode->bind_param('s', $itemCode);
                $stmtByCode->execute();
                $match = $stmtByCode->get_result()->fetch_assoc();
                $stmtByCode->close();
                $resolvedId = (int)($match['id'] ?? 0);
                $codeCache[$codeKey] = $resolvedId;
                if ($resolvedId > 0) {
                    return $resolvedId;
                }
            }
        }

        $desc = trim((string)($purchaseLine['desc'] ?? ''));
        if ($desc !== '') {
            $nameKey = $normalizeKey($desc);
            if (array_key_exists($nameKey, $nameCache)) {
                return (int)$nameCache[$nameKey];
            }
            $stmtByName = $conn->prepare("SELECT id FROM inventory_items WHERE TRIM(name) = ? LIMIT 1");
            if ($stmtByName) {
                $stmtByName->bind_param('s', $desc);
                $stmtByName->execute();
                $match = $stmtByName->get_result()->fetch_assoc();
                $stmtByName->close();
                $resolvedId = (int)($match['id'] ?? 0);
                $nameCache[$nameKey] = $resolvedId;
                if ($resolvedId > 0) {
                    return $resolvedId;
                }
            }
        }

        $itemName = $desc !== '' ? $desc : ($itemCode !== '' ? $itemCode : '');
        if ($itemName === '') {
            return 0;
        }

        $unitValue = trim((string)($purchaseLine['unit_type'] ?? $purchaseLine['unit'] ?? ''));
        if ($unitValue === '') {
            $unitValue = 'EA';
        }
        $supplierId = (int)($purchaseLine['supplier_id'] ?? 0);
        $priceValue = (float)($purchaseLine['price'] ?? 0);
        $catalogColumns = [];
        $catalogTypes = '';
        $catalogValues = [];

        if ($catalogCodeColumn !== '') {
            $generatedCode = $itemCode !== '' ? $itemCode : ('ETA-' . strtoupper(substr(md5($itemName . '|' . $unitValue), 0, 10)));
            $catalogColumns[] = $catalogCodeColumn;
            $catalogTypes .= 's';
            $catalogValues[] = $generatedCode;
        }

        $catalogColumns[] = 'name';
        $catalogTypes .= 's';
        $catalogValues[] = $itemName;

        if (function_exists('app_table_has_column') && app_table_has_column($conn, 'inventory_items', 'description')) {
            $catalogColumns[] = 'description';
            $catalogTypes .= 's';
            $catalogValues[] = $itemName;
        }
        if (function_exists('app_table_has_column') && app_table_has_column($conn, 'inventory_items', 'notes')) {
            $catalogColumns[] = 'notes';
            $catalogTypes .= 's';
            $catalogValues[] = 'Created automatically from ETA purchase invoice';
        }
        if (function_exists('app_table_has_column') && app_table_has_column($conn, 'inventory_items', 'category')) {
            $catalogColumns[] = 'category';
            $catalogTypes .= 's';
            $catalogValues[] = 'ETA';
        }
        if (function_exists('app_table_has_column') && app_table_has_column($conn, 'inventory_items', 'unit')) {
            $catalogColumns[] = 'unit';
            $catalogTypes .= 's';
            $catalogValues[] = $unitValue;
        }
        if (function_exists('app_table_has_column') && app_table_has_column($conn, 'inventory_items', 'unit_label')) {
            $catalogColumns[] = 'unit_label';
            $catalogTypes .= 's';
            $catalogValues[] = $unitValue;
        }
        if (function_exists('app_table_has_column') && app_table_has_column($conn, 'inventory_items', 'low_stock_threshold')) {
            $catalogColumns[] = 'low_stock_threshold';
            $catalogTypes .= 'd';
            $catalogValues[] = 0.0;
        }
        if (function_exists('app_table_has_column') && app_table_has_column($conn, 'inventory_items', 'reorder_level')) {
            $catalogColumns[] = 'reorder_level';
            $catalogTypes .= 'd';
            $catalogValues[] = 0.0;
        }
        if (function_exists('app_table_has_column') && app_table_has_column($conn, 'inventory_items', 'status')) {
            $catalogColumns[] = 'status';
            $catalogTypes .= 's';
            $catalogValues[] = 'active';
        }
        if (function_exists('app_table_has_column') && app_table_has_column($conn, 'inventory_items', 'supplier_id')) {
            $catalogColumns[] = 'supplier_id';
            $catalogTypes .= 'i';
            $catalogValues[] = max(0, $supplierId);
        }
        if (function_exists('app_table_has_column') && app_table_has_column($conn, 'inventory_items', 'avg_unit_cost')) {
            $catalogColumns[] = 'avg_unit_cost';
            $catalogTypes .= 'd';
            $catalogValues[] = max(0.0, $priceValue);
        }

        $placeholders = implode(', ', array_fill(0, count($catalogColumns), '?'));
        $sql = "INSERT INTO inventory_items (" . implode(', ', $catalogColumns) . ") VALUES (" . $placeholders . ")";
        $stmtInsert = $conn->prepare($sql);
        if (!$stmtInsert) {
            return 0;
        }
        $stmtInsert->bind_param($catalogTypes, ...$catalogValues);
        if (!$stmtInsert->execute()) {
            $stmtInsert->close();
            return 0;
        }
        $createdId = (int)$conn->insert_id;
        $stmtInsert->close();
        if ($createdId > 0) {
            $nameCache[$normalizeKey($itemName)] = $createdId;
            if ($itemCode !== '') {
                $codeCache[$normalizeKey($itemCode)] = $createdId;
            }
            return $createdId;
        }

        return 0;
    }
}

if (!function_exists('inventory_normalize_purchase_items_for_posting')) {
    function inventory_normalize_purchase_items_for_posting(mysqli $conn, array $itemsForDb, int $supplierId = 0): array
    {
        $normalized = [];
        foreach ($itemsForDb as $purchaseLine) {
            if (!is_array($purchaseLine)) {
                continue;
            }
            if (!isset($purchaseLine['supplier_id']) || (int)$purchaseLine['supplier_id'] <= 0) {
                $purchaseLine['supplier_id'] = $supplierId;
            }
            $purchaseLine['item_id'] = inventory_find_catalog_item_id($conn, $purchaseLine);
            $normalized[] = $purchaseLine;
        }
        return $normalized;
    }
}

if (!function_exists('inventory_receive_purchase_invoice')) {
    function inventory_receive_purchase_invoice(
        mysqli $conn,
        int $purchaseInvoiceId,
        int $supplierId,
        int $warehouseId,
        int $userId,
        array $itemsForDb,
        float $grandTotal
    ): void {
        if ($purchaseInvoiceId <= 0 || $supplierId <= 0 || $warehouseId <= 0 || empty($itemsForDb)) {
            throw new InvalidArgumentException('invalid_purchase_inventory_payload');
        }

        $itemsForDb = inventory_normalize_purchase_items_for_posting($conn, $itemsForDb, $supplierId);
        $postableLines = [];
        foreach ($itemsForDb as $purchasedItem) {
            $itemId = (int)($purchasedItem['item_id'] ?? 0);
            $qty = (float)($purchasedItem['qty'] ?? 0);
            if ($itemId <= 0 || $qty <= 0) {
                continue;
            }
            $postableLines[] = $purchasedItem;
        }
        if (empty($postableLines)) {
            throw new RuntimeException('purchase_invoice_items_unmapped');
        }

        $stmtSupplier = $conn->prepare("UPDATE suppliers SET current_balance = current_balance + ? WHERE id = ?");
        if (!$stmtSupplier) {
            throw new RuntimeException($conn->error);
        }

        foreach ($postableLines as $purchasedItem) {
            $itemId = (int)($purchasedItem['item_id'] ?? 0);
            $qty = (float)($purchasedItem['qty'] ?? 0);
            $unitCost = (float)($purchasedItem['price'] ?? 0);
            if ($itemId <= 0 || $qty <= 0) {
                continue;
            }
            $lineTotal = round($qty * $unitCost, 2);
            app_inventory_apply_purchase_cost($conn, $itemId, $qty, $unitCost);
            inventory_apply_stock_delta($conn, $itemId, $warehouseId, $qty);
            inventory_insert_transaction(
                $conn,
                $itemId,
                $warehouseId,
                $userId,
                'in',
                $qty,
                "إدخال من فاتورة شراء رقم #{$purchaseInvoiceId}",
                $purchaseInvoiceId,
                $unitCost,
                $lineTotal,
                'purchase_invoice',
                $purchaseInvoiceId
            );
        }

        $stmtSupplier->bind_param('di', $grandTotal, $supplierId);
        if (!$stmtSupplier->execute()) {
            $err = $stmtSupplier->error ?: $conn->error;
            $stmtSupplier->close();
            throw new RuntimeException($err);
        }
        $stmtSupplier->close();
    }
}

if (!function_exists('inventory_purchase_invoice_is_posted')) {
    function inventory_purchase_invoice_is_posted(mysqli $conn, int $purchaseInvoiceId): bool
    {
        if ($purchaseInvoiceId <= 0) {
            return false;
        }
        $stmt = $conn->prepare("
            SELECT 1
            FROM inventory_transactions
            WHERE reference_type = 'purchase_invoice' AND reference_id = ?
            LIMIT 1
        ");
        if (!$stmt) {
            throw new RuntimeException($conn->error);
        }
        $stmt->bind_param('i', $purchaseInvoiceId);
        $stmt->execute();
        $found = (bool)$stmt->get_result()->fetch_row();
        $stmt->close();
        return $found;
    }
}

if (!function_exists('inventory_post_existing_purchase_invoice')) {
    function inventory_post_existing_purchase_invoice(mysqli $conn, int $purchaseInvoiceId, int $userId): void
    {
        if ($purchaseInvoiceId <= 0) {
            throw new InvalidArgumentException('invalid_purchase_invoice');
        }
        if (inventory_purchase_invoice_is_posted($conn, $purchaseInvoiceId)) {
            throw new RuntimeException('purchase_invoice_already_posted');
        }

        $stmtInv = $conn->prepare("
            SELECT id, supplier_id, warehouse_id, total_amount, status, items_json
            FROM purchase_invoices
            WHERE id = ?
            LIMIT 1
        ");
        if (!$stmtInv) {
            throw new RuntimeException($conn->error);
        }
        $stmtInv->bind_param('i', $purchaseInvoiceId);
        $stmtInv->execute();
        $invoice = $stmtInv->get_result()->fetch_assoc();
        $stmtInv->close();
        if (!$invoice) {
            throw new RuntimeException('purchase_invoice_not_found');
        }
        if ((string)($invoice['status'] ?? '') === 'cancelled') {
            throw new RuntimeException('purchase_invoice_cancelled');
        }

        $supplierId = (int)($invoice['supplier_id'] ?? 0);
        $warehouseId = (int)($invoice['warehouse_id'] ?? 0);
        $grandTotal = (float)($invoice['total_amount'] ?? 0);
        $items = json_decode((string)($invoice['items_json'] ?? '[]'), true);
        if ($supplierId <= 0 || $warehouseId <= 0 || !is_array($items) || empty($items)) {
            throw new RuntimeException('invalid_purchase_inventory_payload');
        }

        $normalizedItems = inventory_normalize_purchase_items_for_posting($conn, $items, $supplierId);
        $hasPostableLine = false;
        foreach ($normalizedItems as $line) {
            if ((int)($line['item_id'] ?? 0) > 0 && (float)($line['qty'] ?? 0) > 0) {
                $hasPostableLine = true;
                break;
            }
        }
        if (!$hasPostableLine) {
            throw new RuntimeException('purchase_invoice_items_unmapped');
        }
        if ($normalizedItems !== $items) {
            $normalizedJson = json_encode($normalizedItems, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (is_string($normalizedJson) && $normalizedJson !== '') {
                $stmtUpdateItems = $conn->prepare("UPDATE purchase_invoices SET items_json = ? WHERE id = ? LIMIT 1");
                if (!$stmtUpdateItems) {
                    throw new RuntimeException($conn->error);
                }
                $stmtUpdateItems->bind_param('si', $normalizedJson, $purchaseInvoiceId);
                if (!$stmtUpdateItems->execute()) {
                    $err = $stmtUpdateItems->error ?: $conn->error;
                    $stmtUpdateItems->close();
                    throw new RuntimeException($err);
                }
                $stmtUpdateItems->close();
            }
            $items = $normalizedItems;
        }

        inventory_receive_purchase_invoice(
            $conn,
            $purchaseInvoiceId,
            $supplierId,
            $warehouseId,
            $userId,
            $items,
            $grandTotal
        );
    }
}

if (!function_exists('inventory_cancel_purchase_invoice')) {
    function inventory_cancel_purchase_invoice(mysqli $conn, int $purchaseInvoiceId): void
    {
        if ($purchaseInvoiceId <= 0) {
            throw new InvalidArgumentException('invalid_purchase_invoice');
        }

        $stmtInv = $conn->prepare("
            SELECT id, supplier_id, warehouse_id, total_amount, paid_amount, remaining_amount, status, items_json
            FROM purchase_invoices
            WHERE id = ?
            LIMIT 1
        ");
        if (!$stmtInv) {
            throw new RuntimeException($conn->error);
        }
        $stmtInv->bind_param('i', $purchaseInvoiceId);
        $stmtInv->execute();
        $invoice = $stmtInv->get_result()->fetch_assoc();
        $stmtInv->close();
        if (!$invoice) {
            throw new RuntimeException('purchase_invoice_not_found');
        }
        if ((string)($invoice['status'] ?? '') === 'cancelled') {
            throw new RuntimeException('purchase_invoice_already_cancelled');
        }
        if ((float)($invoice['paid_amount'] ?? 0) > 0.00001) {
            throw new RuntimeException('purchase_invoice_paid');
        }

        $items = json_decode((string)($invoice['items_json'] ?? '[]'), true);
        if (!is_array($items) || empty($items)) {
            throw new RuntimeException('purchase_invoice_items_missing');
        }

        $warehouseId = (int)($invoice['warehouse_id'] ?? 0);
        if ($warehouseId <= 0) {
            throw new RuntimeException('purchase_invoice_warehouse_missing');
        }

        if (inventory_purchase_invoice_is_posted($conn, $purchaseInvoiceId)) {
            foreach ($items as $line) {
                $itemId = (int)($line['item_id'] ?? 0);
                $qty = (float)($line['qty'] ?? 0);
                if ($itemId <= 0 || $qty <= 0) {
                    continue;
                }
                $available = inventory_fetch_available_qty($conn, $itemId, $warehouseId, true);
                if ($available + 0.00001 < $qty) {
                    throw new RuntimeException('purchase_invoice_stock_consumed');
                }
            }

            foreach ($items as $line) {
                $itemId = (int)($line['item_id'] ?? 0);
                $qty = (float)($line['qty'] ?? 0);
                $unitCost = (float)($line['price'] ?? 0);
                if ($itemId <= 0 || $qty <= 0) {
                    continue;
                }

                $currentQty = inventory_fetch_available_qty($conn, $itemId, $warehouseId, true);
                $currentAvg = app_inventory_item_avg_cost($conn, $itemId);
                $remainingQty = max(0.0, $currentQty - $qty);
                $newAvg = 0.0;
                if ($remainingQty > 0.00001) {
                    $numerator = ($currentQty * $currentAvg) - ($qty * $unitCost);
                    $newAvg = $numerator / $remainingQty;
                    if ($newAvg < 0) {
                        $newAvg = 0.0;
                    }
                }

                inventory_apply_stock_delta($conn, $itemId, $warehouseId, -$qty);
                inventory_insert_transaction(
                    $conn,
                    $itemId,
                    $warehouseId,
                    (int)($_SESSION['user_id'] ?? 0),
                    'out',
                    -$qty,
                    "إلغاء ترحيل فاتورة شراء رقم #{$purchaseInvoiceId}",
                    0,
                    $unitCost,
                    round($qty * $unitCost, 2),
                    'purchase_invoice_cancel',
                    $purchaseInvoiceId
                );

                $stmtAvg = $conn->prepare("UPDATE inventory_items SET avg_unit_cost = ? WHERE id = ?");
                if (!$stmtAvg) {
                    throw new RuntimeException($conn->error);
                }
                $stmtAvg->bind_param('di', $newAvg, $itemId);
                if (!$stmtAvg->execute()) {
                    $err = $stmtAvg->error ?: $conn->error;
                    $stmtAvg->close();
                    throw new RuntimeException($err);
                }
                $stmtAvg->close();
            }
        }

        $supplierId = (int)($invoice['supplier_id'] ?? 0);
        $totalAmount = (float)($invoice['total_amount'] ?? 0);

        $stmtSupplier = $conn->prepare("UPDATE suppliers SET current_balance = current_balance - ? WHERE id = ?");
        if (!$stmtSupplier) {
            throw new RuntimeException($conn->error);
        }
        $stmtSupplier->bind_param('di', $totalAmount, $supplierId);
        if (!$stmtSupplier->execute()) {
            $err = $stmtSupplier->error ?: $conn->error;
            $stmtSupplier->close();
            throw new RuntimeException($err);
        }
        $stmtSupplier->close();

        $stmtCancel = $conn->prepare("UPDATE purchase_invoices SET status = 'cancelled', remaining_amount = 0 WHERE id = ?");
        if (!$stmtCancel) {
            throw new RuntimeException($conn->error);
        }
        $stmtCancel->bind_param('i', $purchaseInvoiceId);
        if (!$stmtCancel->execute()) {
            $err = $stmtCancel->error ?: $conn->error;
            $stmtCancel->close();
            throw new RuntimeException($err);
        }
        $stmtCancel->close();
    }
}

if (!function_exists('inventory_purge_purchase_invoice')) {
    function inventory_purge_purchase_invoice(mysqli $conn, int $purchaseInvoiceId): void
    {
        if ($purchaseInvoiceId <= 0) {
            throw new InvalidArgumentException('invalid_purchase_invoice');
        }

        $stmtInv = $conn->prepare("
            SELECT id, status, paid_amount,
                   IFNULL(eta_uuid, '') AS eta_uuid,
                   IFNULL(eta_status, '') AS eta_status,
                   IFNULL(eta_submission_id, '') AS eta_submission_id,
                   IFNULL(eta_long_id, '') AS eta_long_id
            FROM purchase_invoices
            WHERE id = ?
            LIMIT 1
        ");
        if (!$stmtInv) {
            throw new RuntimeException($conn->error);
        }
        $stmtInv->bind_param('i', $purchaseInvoiceId);
        $stmtInv->execute();
        $invoice = $stmtInv->get_result()->fetch_assoc();
        $stmtInv->close();
        if (!$invoice) {
            throw new RuntimeException('purchase_invoice_not_found');
        }

        $hasEtaBinding = trim((string)($invoice['eta_uuid'] ?? '')) !== ''
            || trim((string)($invoice['eta_status'] ?? '')) !== ''
            || trim((string)($invoice['eta_submission_id'] ?? '')) !== ''
            || trim((string)($invoice['eta_long_id'] ?? '')) !== '';
        if ($hasEtaBinding) {
            throw new RuntimeException('purchase_invoice_eta_locked');
        }

        $isPosted = inventory_purchase_invoice_is_posted($conn, $purchaseInvoiceId);
        $isCancelled = ((string)($invoice['status'] ?? '') === 'cancelled');
        if (!$isCancelled && $isPosted) {
            throw new RuntimeException('purchase_invoice_not_cancelled');
        }

        if ((float)($invoice['paid_amount'] ?? 0) > 0.00001) {
            throw new RuntimeException('purchase_invoice_paid');
        }

        $stmtDirect = $conn->prepare("
            SELECT COUNT(*)
            FROM financial_receipts
            WHERE invoice_id = ? AND type = 'out'
        ");
        if (!$stmtDirect) {
            throw new RuntimeException($conn->error);
        }
        $stmtDirect->bind_param('i', $purchaseInvoiceId);
        $stmtDirect->execute();
        $directReceiptCount = (int)($stmtDirect->get_result()->fetch_row()[0] ?? 0);
        $stmtDirect->close();
        if ($directReceiptCount > 0) {
            throw new RuntimeException('purchase_invoice_paid');
        }

        $stmtAlloc = $conn->prepare("
            SELECT COUNT(*)
            FROM financial_receipt_allocations
            WHERE allocation_type = 'purchase_invoice' AND target_id = ?
        ");
        if (!$stmtAlloc) {
            throw new RuntimeException($conn->error);
        }
        $stmtAlloc->bind_param('i', $purchaseInvoiceId);
        $stmtAlloc->execute();
        $allocationCount = (int)($stmtAlloc->get_result()->fetch_row()[0] ?? 0);
        $stmtAlloc->close();
        if ($allocationCount > 0) {
            throw new RuntimeException('purchase_invoice_paid');
        }

        $returnCount = 0;
        if ($checkReturns = $conn->prepare("SELECT COUNT(*) FROM purchase_invoice_returns WHERE purchase_invoice_id = ?")) {
            $checkReturns->bind_param('i', $purchaseInvoiceId);
            $checkReturns->execute();
            $returnCount = (int)($checkReturns->get_result()->fetch_row()[0] ?? 0);
            $checkReturns->close();
        }
        if ($returnCount > 0) {
            throw new RuntimeException('purchase_invoice_has_returns');
        }

        if ($isCancelled || $isPosted) {
            $stmtDeleteInvTx = $conn->prepare("
                DELETE FROM inventory_transactions
                WHERE reference_id = ?
                  AND reference_type IN ('purchase_invoice', 'purchase_invoice_cancel')
            ");
            if (!$stmtDeleteInvTx) {
                throw new RuntimeException($conn->error);
            }
            $stmtDeleteInvTx->bind_param('i', $purchaseInvoiceId);
            if (!$stmtDeleteInvTx->execute()) {
                $err = $stmtDeleteInvTx->error ?: $conn->error;
                $stmtDeleteInvTx->close();
                throw new RuntimeException($err);
            }
            $stmtDeleteInvTx->close();
        }

        $stmtDeleteInvoice = $conn->prepare("DELETE FROM purchase_invoices WHERE id = ?");
        if (!$stmtDeleteInvoice) {
            throw new RuntimeException($conn->error);
        }
        $stmtDeleteInvoice->bind_param('i', $purchaseInvoiceId);
        if (!$stmtDeleteInvoice->execute()) {
            $err = $stmtDeleteInvoice->error ?: $conn->error;
            $stmtDeleteInvoice->close();
            throw new RuntimeException($err);
        }
        $stmtDeleteInvoice->close();
    }
}

if (!function_exists('inventory_create_purchase_return')) {
    function inventory_create_purchase_return(
        mysqli $conn,
        int $purchaseInvoiceId,
        string $returnDate,
        array $returnLines,
        int $userId,
        string $creatorName = '',
        string $notes = ''
    ): int {
        if ($purchaseInvoiceId <= 0) {
            throw new InvalidArgumentException('invalid_purchase_invoice');
        }

        $stmtInv = $conn->prepare("
            SELECT id, supplier_id, warehouse_id, sub_total, tax, discount, total_amount, paid_amount, remaining_amount, status, items_json, inv_date
            FROM purchase_invoices
            WHERE id = ?
            LIMIT 1
        ");
        if (!$stmtInv) {
            throw new RuntimeException($conn->error);
        }
        $stmtInv->bind_param('i', $purchaseInvoiceId);
        $stmtInv->execute();
        $invoice = $stmtInv->get_result()->fetch_assoc();
        $stmtInv->close();
        if (!$invoice) {
            throw new RuntimeException('purchase_invoice_not_found');
        }
        if ((string)($invoice['status'] ?? '') === 'cancelled') {
            throw new RuntimeException('purchase_invoice_already_cancelled');
        }
        if ((float)($invoice['paid_amount'] ?? 0) > 0.00001) {
            throw new RuntimeException('purchase_invoice_paid');
        }

        $warehouseId = (int)($invoice['warehouse_id'] ?? 0);
        $supplierId = (int)($invoice['supplier_id'] ?? 0);
        if ($warehouseId <= 0 || $supplierId <= 0) {
            throw new RuntimeException('purchase_invoice_binding_invalid');
        }

        $existingItems = json_decode((string)($invoice['items_json'] ?? '[]'), true);
        if (!is_array($existingItems) || empty($existingItems)) {
            throw new RuntimeException('purchase_invoice_items_missing');
        }

        $itemMap = [];
        foreach ($existingItems as $idx => $line) {
            $itemId = (int)($line['item_id'] ?? 0);
            if ($itemId <= 0) {
                continue;
            }
            $itemMap[$itemId] = [
                'index' => $idx,
                'line' => $line,
            ];
        }

        $normalizedReturns = [];
        $returnSubtotal = 0.0;
        foreach ($returnLines as $returnLine) {
            $itemId = (int)($returnLine['item_id'] ?? 0);
            $qty = round((float)($returnLine['qty'] ?? 0), 4);
            if ($itemId <= 0 || $qty <= 0) {
                continue;
            }
            if (!isset($itemMap[$itemId])) {
                throw new RuntimeException('purchase_return_invalid_item');
            }
            $srcLine = $itemMap[$itemId]['line'];
            $sourceQty = round((float)($srcLine['qty'] ?? 0), 4);
            if ($qty - $sourceQty > 0.0001) {
                throw new RuntimeException('purchase_return_qty_exceeds_invoice');
            }

            $available = inventory_fetch_available_qty($conn, $itemId, $warehouseId, true);
            if ($available + 0.00001 < $qty) {
                throw new RuntimeException('purchase_invoice_stock_consumed');
            }

            $unitCost = (float)($srcLine['price'] ?? 0);
            $lineTotal = round($qty * $unitCost, 2);
            $returnSubtotal += $lineTotal;
            $normalizedReturns[] = [
                'item_id' => $itemId,
                'desc' => (string)($srcLine['desc'] ?? ''),
                'qty' => $qty,
                'price' => $unitCost,
                'total' => $lineTotal,
            ];
        }

        if (empty($normalizedReturns)) {
            throw new RuntimeException('purchase_return_empty');
        }

        $oldSubtotal = (float)($invoice['sub_total'] ?? 0);
        $oldTax = (float)($invoice['tax'] ?? 0);
        $oldDiscount = (float)($invoice['discount'] ?? 0);
        $ratio = ($oldSubtotal > 0.00001) ? min(1, $returnSubtotal / $oldSubtotal) : 0.0;
        $returnTax = round($oldTax * $ratio, 2);
        $returnDiscount = round($oldDiscount * $ratio, 2);
        $returnGrand = round($returnSubtotal + $returnTax - $returnDiscount, 2);
        if ($returnGrand <= 0) {
            throw new RuntimeException('purchase_return_total_invalid');
        }

        $stmtReturn = $conn->prepare("
            INSERT INTO purchase_invoice_returns (
                purchase_invoice_id, supplier_id, warehouse_id, return_date,
                subtotal_amount, tax_amount, discount_amount, total_amount,
                items_json, notes, created_by_user_id, created_by_name
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        if (!$stmtReturn) {
            throw new RuntimeException($conn->error);
        }
        $itemsJson = json_encode($normalizedReturns, JSON_UNESCAPED_UNICODE);
        $stmtReturn->bind_param(
            'iiisddddssis',
            $purchaseInvoiceId,
            $supplierId,
            $warehouseId,
            $returnDate,
            $returnSubtotal,
            $returnTax,
            $returnDiscount,
            $returnGrand,
            $itemsJson,
            $notes,
            $userId,
            $creatorName
        );
        if (!$stmtReturn->execute()) {
            $err = $stmtReturn->error ?: $conn->error;
            $stmtReturn->close();
            throw new RuntimeException($err);
        }
        $returnId = (int)$conn->insert_id;
        $stmtReturn->close();

        app_assign_document_number($conn, 'purchase_invoice_returns', $returnId, 'return_number', 'prtn', $returnDate);

        foreach ($normalizedReturns as $line) {
            $itemId = (int)$line['item_id'];
            $qty = (float)$line['qty'];
            $unitCost = (float)$line['price'];
            $currentQty = inventory_fetch_available_qty($conn, $itemId, $warehouseId, true);
            $currentAvg = app_inventory_item_avg_cost($conn, $itemId);
            $remainingQty = max(0.0, $currentQty - $qty);
            $newAvg = 0.0;
            if ($remainingQty > 0.00001) {
                $numerator = ($currentQty * $currentAvg) - ($qty * $unitCost);
                $newAvg = $numerator / $remainingQty;
                if ($newAvg < 0) {
                    $newAvg = 0.0;
                }
            }

            inventory_apply_stock_delta($conn, $itemId, $warehouseId, -$qty);
            inventory_insert_transaction(
                $conn,
                $itemId,
                $warehouseId,
                $userId,
                'out',
                -$qty,
                "مردود شراء رقم #{$returnId} على فاتورة شراء #{$purchaseInvoiceId}",
                0,
                $unitCost,
                round($qty * $unitCost, 2),
                'purchase_return',
                $returnId
            );

            $stmtAvg = $conn->prepare("UPDATE inventory_items SET avg_unit_cost = ? WHERE id = ?");
            if (!$stmtAvg) {
                throw new RuntimeException($conn->error);
            }
            $stmtAvg->bind_param('di', $newAvg, $itemId);
            if (!$stmtAvg->execute()) {
                $err = $stmtAvg->error ?: $conn->error;
                $stmtAvg->close();
                throw new RuntimeException($err);
            }
            $stmtAvg->close();
        }

        $updatedItems = $existingItems;
        foreach ($normalizedReturns as $returned) {
            $itemId = (int)$returned['item_id'];
            $returnedQty = (float)$returned['qty'];
            $idx = (int)$itemMap[$itemId]['index'];
            $src = $updatedItems[$idx];
            $newQty = max(0, round((float)($src['qty'] ?? 0) - $returnedQty, 4));
            if ($newQty <= 0.00001) {
                unset($updatedItems[$idx]);
                continue;
            }
            $unitCost = (float)($src['price'] ?? 0);
            $updatedItems[$idx]['qty'] = $newQty;
            $updatedItems[$idx]['total'] = round($newQty * $unitCost, 2);
        }
        $updatedItems = array_values($updatedItems);

        $newSubtotal = max(0.0, round($oldSubtotal - $returnSubtotal, 2));
        $newTax = max(0.0, round($oldTax - $returnTax, 2));
        $newDiscount = max(0.0, round($oldDiscount - $returnDiscount, 2));
        $newTotal = max(0.0, round((float)$invoice['total_amount'] - $returnGrand, 2));
        $newRemaining = $newTotal;
        $newStatus = $newTotal <= 0.00001 ? 'cancelled' : 'unpaid';

        $updatedItemsJson = json_encode($updatedItems, JSON_UNESCAPED_UNICODE);
        $stmtUpdInv = $conn->prepare("
            UPDATE purchase_invoices
            SET sub_total = ?, tax = ?, discount = ?, total_amount = ?, remaining_amount = ?, status = ?, items_json = ?
            WHERE id = ?
        ");
        if (!$stmtUpdInv) {
            throw new RuntimeException($conn->error);
        }
        $stmtUpdInv->bind_param('dddddssi', $newSubtotal, $newTax, $newDiscount, $newTotal, $newRemaining, $newStatus, $updatedItemsJson, $purchaseInvoiceId);
        if (!$stmtUpdInv->execute()) {
            $err = $stmtUpdInv->error ?: $conn->error;
            $stmtUpdInv->close();
            throw new RuntimeException($err);
        }
        $stmtUpdInv->close();

        $stmtSupplier = $conn->prepare("UPDATE suppliers SET current_balance = current_balance - ? WHERE id = ?");
        if (!$stmtSupplier) {
            throw new RuntimeException($conn->error);
        }
        $stmtSupplier->bind_param('di', $returnGrand, $supplierId);
        if (!$stmtSupplier->execute()) {
            $err = $stmtSupplier->error ?: $conn->error;
            $stmtSupplier->close();
            throw new RuntimeException($err);
        }
        $stmtSupplier->close();

        return $returnId;
    }
}
