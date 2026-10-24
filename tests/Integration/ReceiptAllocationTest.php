<?php
declare(strict_types=1);

namespace SmartSystem\Tests\Integration;

use SmartSystem\Tests\Support\DatabaseTestCase;

final class ReceiptAllocationTest extends DatabaseTestCase
{
    public function testClientReceiptStaysWholeAndAllocatesAcrossSalesInvoicesFifo(): void
    {
        $this->ensureClientsTable();
        app_ensure_suppliers_schema($this->conn);
        $this->ensureInvoicesTable();
        $this->ensurePurchaseInvoicesTable();
        financeEnsureAllocationSchema($this->conn);

        $stamp = 'TEST-ALLOC-' . date('YmdHis');
        $today = date('Y-m-d');

        $clientId = $this->createClient('Client ' . $stamp);
        $invoiceIds = [
            $this->createSalesInvoice($clientId, date('Y-m-d', strtotime('-3 days')), $today, 100.00, $stamp . ' S1'),
            $this->createSalesInvoice($clientId, date('Y-m-d', strtotime('-2 days')), $today, 150.00, $stamp . ' S2'),
            $this->createSalesInvoice($clientId, date('Y-m-d', strtotime('-1 day')), $today, 80.00, $stamp . ' S3'),
        ];

        $receiptId = autoAllocatePayment($this->conn, $clientId, 220.00, $today, $stamp . ' RECEIPT', 'phpunit');
        $this->assertGreaterThan(0, $receiptId);

        foreach ($invoiceIds as $invoiceId) {
            recalculateSalesInvoice($this->conn, $invoiceId);
        }

        $receipt = $this->row("SELECT id, amount, client_id, invoice_id, type FROM financial_receipts WHERE id = {$receiptId} LIMIT 1");
        $this->assertSame('220.00', number_format((float)($receipt['amount'] ?? 0), 2, '.', ''));
        $this->assertSame($clientId, (int)($receipt['client_id'] ?? 0));
        $this->assertSame(0, (int)($receipt['invoice_id'] ?? 0));
        $this->assertSame('in', (string)($receipt['type'] ?? ''));

        $allocRows = $this->rows("SELECT allocation_type, target_id, amount FROM financial_receipt_allocations WHERE receipt_id = {$receiptId} ORDER BY id ASC");
        $this->assertCount(2, $allocRows);
        $this->assertSame('sales_invoice', (string)$allocRows[0]['allocation_type']);
        $this->assertSame('100.00', number_format((float)$allocRows[0]['amount'], 2, '.', ''));
        $this->assertSame('sales_invoice', (string)$allocRows[1]['allocation_type']);
        $this->assertSame('120.00', number_format((float)$allocRows[1]['amount'], 2, '.', ''));

        $invoiceOne = $this->row("SELECT paid_amount, remaining_amount, status FROM invoices WHERE id = {$invoiceIds[0]}");
        $invoiceTwo = $this->row("SELECT paid_amount, remaining_amount, status FROM invoices WHERE id = {$invoiceIds[1]}");
        $invoiceThree = $this->row("SELECT paid_amount, remaining_amount, status FROM invoices WHERE id = {$invoiceIds[2]}");

        $this->assertSame('100.00', number_format((float)$invoiceOne['paid_amount'], 2, '.', ''));
        $this->assertSame('0.00', number_format((float)$invoiceOne['remaining_amount'], 2, '.', ''));
        $this->assertSame('paid', (string)$invoiceOne['status']);

        $this->assertSame('120.00', number_format((float)$invoiceTwo['paid_amount'], 2, '.', ''));
        $this->assertSame('30.00', number_format((float)$invoiceTwo['remaining_amount'], 2, '.', ''));
        $this->assertSame('partially_paid', (string)$invoiceTwo['status']);

        $this->assertSame('0.00', number_format((float)$invoiceThree['paid_amount'], 2, '.', ''));
        $this->assertSame('80.00', number_format((float)$invoiceThree['remaining_amount'], 2, '.', ''));
        $this->assertContains((string)$invoiceThree['status'], ['unpaid', 'deferred', 'overdue']);
    }

    private function ensureClientsTable(): void
    {
        $this->conn->query("
            CREATE TABLE IF NOT EXISTS clients (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(255) NOT NULL,
                phone VARCHAR(50) DEFAULT NULL,
                email VARCHAR(120) DEFAULT NULL,
                address TEXT DEFAULT NULL,
                opening_balance DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                current_balance DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                access_token VARCHAR(100) DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }

    private function ensureInvoicesTable(): void
    {
        $this->conn->query("
            CREATE TABLE IF NOT EXISTS invoices (
                id INT AUTO_INCREMENT PRIMARY KEY,
                invoice_number VARCHAR(40) DEFAULT NULL,
                client_id INT NOT NULL,
                job_id INT DEFAULT NULL,
                inv_date DATE NOT NULL,
                due_date DATE DEFAULT NULL,
                invoice_kind VARCHAR(20) NOT NULL DEFAULT 'standard',
                tax_law_key VARCHAR(60) DEFAULT NULL,
                sub_total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                tax DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                tax_total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                discount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                total_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                paid_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                remaining_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                status VARCHAR(40) NOT NULL DEFAULT 'unpaid',
                items_json LONGTEXT DEFAULT NULL,
                taxes_json LONGTEXT DEFAULT NULL,
                notes TEXT DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                KEY idx_invoices_client (client_id),
                KEY idx_invoices_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }

    private function ensurePurchaseInvoicesTable(): void
    {
        $this->conn->query("
            CREATE TABLE IF NOT EXISTS purchase_invoices (
                id INT AUTO_INCREMENT PRIMARY KEY,
                purchase_number VARCHAR(40) DEFAULT NULL,
                supplier_id INT NOT NULL,
                inv_date DATE NOT NULL,
                due_date DATE DEFAULT NULL,
                sub_total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                tax DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                discount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                total_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                paid_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                remaining_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                status VARCHAR(40) NOT NULL DEFAULT 'unpaid',
                items_json LONGTEXT DEFAULT NULL,
                notes TEXT DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                KEY idx_purchase_invoices_supplier (supplier_id),
                KEY idx_purchase_invoices_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }

    private function createClient(string $name): int
    {
        $stmt = $this->conn->prepare("INSERT INTO clients (name, phone, opening_balance) VALUES (?, '', 0)");
        $stmt->bind_param('s', $name);
        $stmt->execute();
        $id = (int)$stmt->insert_id;
        $stmt->close();
        return $id;
    }

    private function createSalesInvoice(int $clientId, string $invDate, string $dueDate, float $total, string $note): int
    {
        $stmt = $this->conn->prepare("
            INSERT INTO invoices (client_id, inv_date, due_date, total_amount, paid_amount, remaining_amount, status, items_json, notes)
            VALUES (?, ?, ?, ?, 0, ?, 'deferred', '[]', ?)
        ");
        $stmt->bind_param('issdds', $clientId, $invDate, $dueDate, $total, $total, $note);
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

    private function rows(string $sql): array
    {
        $rows = [];
        $result = $this->conn->query($sql);
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $rows[] = $row;
            }
        }
        return $rows;
    }
}
