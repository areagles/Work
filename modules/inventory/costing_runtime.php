<?php

declare(strict_types=1);

if (!function_exists('inventoryCostCurrentAverage')) {
    function inventoryCostCurrentAverage(mysqli $conn, int $itemId): float
    {
        if ($itemId <= 0) {
            return 0.0;
        }

        try {
            $stmt = $conn->prepare("SELECT IFNULL(avg_unit_cost, 0) AS avg_cost FROM inventory_items WHERE id = ? LIMIT 1");
            if (!$stmt) {
                return 0.0;
            }
            $stmt->bind_param('i', $itemId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            $storedAvg = (float)($row['avg_cost'] ?? 0);
            if ($storedAvg > 0.000001) {
                return $storedAvg;
            }

            if (
                !function_exists('app_table_has_column')
                || !app_table_has_column($conn, 'inventory_transactions', 'unit_cost')
                || !app_table_has_column($conn, 'inventory_transactions', 'reference_type')
            ) {
                return 0.0;
            }

            $stmtFallback = $conn->prepare("
                SELECT
                    IFNULL(SUM(ABS(quantity) * unit_cost), 0) AS total_cost,
                    IFNULL(SUM(ABS(quantity)), 0) AS total_qty
                FROM inventory_transactions
                WHERE item_id = ?
                  AND quantity > 0
                  AND unit_cost > 0
                  AND reference_type IN ('purchase_invoice', 'opening_stock', 'manual_in', 'job_material_reversal')
            ");
            if (!$stmtFallback) {
                return 0.0;
            }
            $stmtFallback->bind_param('i', $itemId);
            $stmtFallback->execute();
            $fallbackRow = $stmtFallback->get_result()->fetch_assoc();
            $stmtFallback->close();

            $totalQty = (float)($fallbackRow['total_qty'] ?? 0);
            $totalCost = (float)($fallbackRow['total_cost'] ?? 0);
            if ($totalQty <= 0.000001 || $totalCost <= 0.000001) {
                return 0.0;
            }

            $computedAvg = $totalCost / $totalQty;
            $stmtUpd = $conn->prepare("UPDATE inventory_items SET avg_unit_cost = ? WHERE id = ? AND IFNULL(avg_unit_cost, 0) <= 0.000001");
            if ($stmtUpd) {
                $stmtUpd->bind_param('di', $computedAvg, $itemId);
                $stmtUpd->execute();
                $stmtUpd->close();
            }

            return $computedAvg;
        } catch (Throwable $e) {
            return 0.0;
        }
    }
}

if (!function_exists('inventoryCostApplyInboundAverage')) {
    function inventoryCostApplyInboundAverage(mysqli $conn, int $itemId, float $qtyIn, float $unitCost): float
    {
        if ($itemId <= 0 || $qtyIn <= 0 || $unitCost < 0) {
            return 0.0;
        }

        try {
            $stmtQty = $conn->prepare("SELECT IFNULL(SUM(quantity), 0) AS total_qty FROM inventory_stock WHERE item_id = ?");
            if (!$stmtQty) {
                return 0.0;
            }
            $stmtQty->bind_param('i', $itemId);
            $stmtQty->execute();
            $qtyRow = $stmtQty->get_result()->fetch_assoc();
            $stmtQty->close();

            $currentQty = max(0.0, (float)($qtyRow['total_qty'] ?? 0.0));
            $currentAvg = inventoryCostCurrentAverage($conn, $itemId);
            $denominator = $currentQty + $qtyIn;
            if ($denominator <= 0.00001) {
                return $currentAvg;
            }

            $newAvg = (($currentQty * $currentAvg) + ($qtyIn * $unitCost)) / $denominator;
            $stmtUpd = $conn->prepare("UPDATE inventory_items SET avg_unit_cost = ? WHERE id = ?");
            if ($stmtUpd) {
                $stmtUpd->bind_param('di', $newAvg, $itemId);
                $stmtUpd->execute();
                $stmtUpd->close();
            }

            return $newAvg;
        } catch (Throwable $e) {
            error_log('inventoryCostApplyInboundAverage skipped: ' . $e->getMessage());
            return 0.0;
        }
    }
}

if (!function_exists('inventoryCostResolveOutbound')) {
    function inventoryCostResolveOutbound(mysqli $conn, int $itemId, float $qty): array
    {
        $qty = max(0.0, $qty);
        $unitCost = inventoryCostCurrentAverage($conn, $itemId);

        return [
            'qty' => $qty,
            'unit_cost' => $unitCost,
            'total_cost' => round($qty * $unitCost, 2),
        ];
    }
}

if (!function_exists('inventoryCostBuildJobIssue')) {
    function inventoryCostBuildJobIssue(mysqli $conn, int $itemId, float $qty): array
    {
        $snapshot = inventoryCostResolveOutbound($conn, $itemId, $qty);
        $snapshot['reference_type'] = 'job_material';
        return $snapshot;
    }
}

if (!function_exists('inventoryCostBuildJobReversal')) {
    function inventoryCostBuildJobReversal(mysqli $conn, int $itemId, float $qty, float $unitCost): array
    {
        $qty = max(0.0, $qty);
        $unitCost = max(0.0, $unitCost);
        $totalCost = round($qty * $unitCost, 2);
        $newAvg = inventoryCostApplyInboundAverage($conn, $itemId, $qty, $unitCost);

        return [
            'qty' => $qty,
            'unit_cost' => $unitCost,
            'total_cost' => $totalCost,
            'new_avg_cost' => $newAvg,
            'reference_type' => 'job_material_reversal',
        ];
    }
}

