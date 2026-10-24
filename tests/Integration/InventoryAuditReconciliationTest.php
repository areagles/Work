<?php
declare(strict_types=1);

namespace SmartSystem\Tests\Integration;

use SmartSystem\Tests\Support\DatabaseTestCase;

final class InventoryAuditReconciliationTest extends DatabaseTestCase
{
    public function testAuditSessionAppliesVarianceAndCreatesAdjustmentTransaction(): void
    {
        $this->ensureUsersTable();
        $this->ensureWarehousesTable();
        $this->ensureInventoryItemsTable();
        $this->ensureInventoryStockTable();
        $this->ensureInventoryTransactionsTable();
        app_ensure_inventory_audit_schema($this->conn);

        $userId = $this->createUser('audit_user_' . date('His'), 'Audit Tester');
        $warehouseId = $this->createWarehouse('Warehouse ' . date('His'));
        $itemId = $this->createInventoryItem('ITM-' . date('His'), 'Audit Item');

        inventory_apply_stock_delta($this->conn, $itemId, $warehouseId, 10.00);

        $sessionId = inventory_create_audit_session(
            $this->conn,
            $warehouseId,
            $userId,
            date('Y-m-d'),
            'Inventory Audit Test',
            'phpunit'
        );

        $this->assertGreaterThan(0, $sessionId);

        $auditLine = $this->row("SELECT system_qty, counted_qty, variance_qty FROM inventory_audit_lines WHERE session_id = {$sessionId} AND item_id = {$itemId} LIMIT 1");
        $this->assertSame('10.00', number_format((float)($auditLine['system_qty'] ?? 0), 2, '.', ''));
        $this->assertNull($auditLine['counted_qty']);

        inventory_update_audit_count($this->conn, $sessionId, $itemId, 7.00, $userId, 'Counted lower');
        inventory_apply_audit_session($this->conn, $sessionId, $userId);

        $session = $this->row("SELECT status, applied_by_user_id FROM inventory_audit_sessions WHERE id = {$sessionId} LIMIT 1");
        $this->assertSame('applied', (string)($session['status'] ?? ''));
        $this->assertSame($userId, (int)($session['applied_by_user_id'] ?? 0));

        $updatedLine = $this->row("SELECT system_qty, counted_qty, variance_qty FROM inventory_audit_lines WHERE session_id = {$sessionId} AND item_id = {$itemId} LIMIT 1");
        $this->assertSame('10.00', number_format((float)$updatedLine['system_qty'], 2, '.', ''));
        $this->assertSame('7.00', number_format((float)$updatedLine['counted_qty'], 2, '.', ''));
        $this->assertSame('-3.00', number_format((float)$updatedLine['variance_qty'], 2, '.', ''));

        $stock = inventory_fetch_available_qty($this->conn, $itemId, $warehouseId);
        $this->assertSame('7.00', number_format($stock, 2, '.', ''));

        $movement = $this->row("
            SELECT transaction_type, quantity, reference_type, reference_id
            FROM inventory_transactions
            WHERE item_id = {$itemId} AND warehouse_id = {$warehouseId} AND reference_type = 'audit_session'
            ORDER BY id DESC
            LIMIT 1
        ");
        $this->assertSame('adjustment', (string)($movement['transaction_type'] ?? ''));
        $this->assertSame('-3.00', number_format((float)($movement['quantity'] ?? 0), 2, '.', ''));
        $this->assertSame('audit_session', (string)($movement['reference_type'] ?? ''));
        $this->assertSame($sessionId, (int)($movement['reference_id'] ?? 0));
    }

    private function ensureUsersTable(): void
    {
        app_ensure_users_core_schema($this->conn);
    }

    private function ensureWarehousesTable(): void
    {
        $this->conn->query("
            CREATE TABLE IF NOT EXISTS warehouses (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(190) NOT NULL,
                location VARCHAR(255) DEFAULT NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }

    private function ensureInventoryItemsTable(): void
    {
        $this->conn->query("
            CREATE TABLE IF NOT EXISTS inventory_items (
                id INT AUTO_INCREMENT PRIMARY KEY,
                item_code VARCHAR(80) NOT NULL,
                name VARCHAR(190) NOT NULL,
                category VARCHAR(120) DEFAULT NULL,
                unit VARCHAR(40) DEFAULT NULL,
                low_stock_threshold DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                avg_unit_cost DECIMAL(12,4) NOT NULL DEFAULT 0.0000,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_inventory_item_code (item_code)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }

    private function ensureInventoryStockTable(): void
    {
        $this->conn->query("
            CREATE TABLE IF NOT EXISTS inventory_stock (
                id INT AUTO_INCREMENT PRIMARY KEY,
                item_id INT NOT NULL,
                warehouse_id INT NOT NULL,
                quantity DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                UNIQUE KEY uq_inventory_stock_item_wh (item_id, warehouse_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }

    private function ensureInventoryTransactionsTable(): void
    {
        $this->conn->query("
            CREATE TABLE IF NOT EXISTS inventory_transactions (
                id INT AUTO_INCREMENT PRIMARY KEY,
                item_id INT NOT NULL,
                warehouse_id INT NOT NULL,
                user_id INT NOT NULL,
                transaction_type VARCHAR(40) NOT NULL,
                quantity DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                related_order_id INT DEFAULT NULL,
                notes TEXT DEFAULT NULL,
                unit_cost DECIMAL(12,4) NOT NULL DEFAULT 0.0000,
                total_cost DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                reference_type VARCHAR(40) DEFAULT NULL,
                reference_id INT DEFAULT NULL,
                stage_key VARCHAR(60) DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }

    private function createUser(string $username, string $fullName): int
    {
        $stmt = $this->conn->prepare("
            INSERT INTO users (username, password, full_name, role, is_active, email)
            VALUES (?, ?, ?, 'employee', 1, ?)
        ");
        $password = password_hash('test-pass', PASSWORD_DEFAULT);
        $email = $username . '@example.test';
        $stmt->bind_param('ssss', $username, $password, $fullName, $email);
        $stmt->execute();
        $id = (int)$stmt->insert_id;
        $stmt->close();
        return $id;
    }

    private function createWarehouse(string $name): int
    {
        $stmt = $this->conn->prepare("INSERT INTO warehouses (name, location, is_active) VALUES (?, '', 1)");
        $stmt->bind_param('s', $name);
        $stmt->execute();
        $id = (int)$stmt->insert_id;
        $stmt->close();
        return $id;
    }

    private function createInventoryItem(string $code, string $name): int
    {
        $stmt = $this->conn->prepare("
            INSERT INTO inventory_items (item_code, name, category, unit, low_stock_threshold, avg_unit_cost)
            VALUES (?, ?, 'general', 'pcs', 0, 5.0000)
        ");
        $stmt->bind_param('ss', $code, $name);
        $stmt->execute();
        $id = (int)$stmt->insert_id;
        $stmt->close();
        return $id;
    }

    private function row(string $sql): array
    {
        $result = $this->conn->query($sql);
        $row = $result ? $result->fetch_assoc() : null;
        return is_array($row) ? $row : [];
    }
}
