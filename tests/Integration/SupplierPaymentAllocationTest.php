<?php
declare(strict_types=1);

namespace SmartSystem\Tests\Integration;

use SmartSystem\Tests\Support\DatabaseTestCase;

final class SupplierPaymentAllocationTest extends DatabaseTestCase
{
    public function testSupplierPaymentStaysWholeAndAllocatesAcrossPurchaseInvoicesFifo(): void
    {
        app_ensure_suppliers_schema($this->conn);
        $this->ensurePurchaseInvoicesTable();
        financeEnsureAllocationSchema($this->conn);

        $stamp = 'TEST-SUP-' . date('YmdHis');
        $today = date('Y-m-d');

        $supplierId = $this->createSupplier('Supplier ' . $stamp);
        $invoiceIds = [
            $this->createPurchaseInvoice($supplierId, date('Y-m-d', strtotime('-3 days')), $today, 120.00, $stamp . ' P1'),
            $this->createPurchaseInvoice($supplierId, date('Y-m-d', strtotime('-2 days')), $today, 90.00, $stamp . ' P2'),
        ];

        $paymentId = autoAllocateSupplierPayment($this->conn, $supplierId, 140.00, $today, $stamp . ' PAYMENT', 'phpunit');
        $this->assertGreaterThan(0, $paymentId);

        foreach ($invoiceIds as $invoiceId) {
            recalculatePurchaseInvoice($this->conn, $invoiceId);
        }

        $payment = $this->row("SELECT id, amount, supplier_id, invoice_id, type, category FROM financial_receipts WHERE id = {$paymentId} LIMIT 1");
        $this->assertSame('140.00', number_format((float)($payment['amount'] ?? 0), 2, '.', ''));
        $this->assertSame($supplierId, (int)($payment['supplier_id'] ?? 0));
        $this->assertSame(0, (int)($payment['invoice_id'] ?? 0));
        $this->assertSame('out', (string)($payment['type'] ?? ''));
        $this->assertSame('supplier', (string)($payment['category'] ?? ''));

        $allocRows = $this->rows("SELECT allocation_type, target_id, amount FROM financial_receipt_allocations WHERE receipt_id = {$paymentId} ORDER BY id ASC");
        $this->assertCount(2, $allocRows);
        $this->assertSame('purchase_invoice', (string)$allocRows[0]['allocation_type']);
        $this->assertSame('120.00', number_format((float)$allocRows[0]['amount'], 2, '.', ''));
        $this->assertSame('purchase_invoice', (string)$allocRows[1]['allocation_type']);
        $this->assertSame('20.00', number_format((float)$allocRows[1]['amount'], 2, '.', ''));

        $invoiceOne = $this->row("SELECT paid_amount, remaining_amount, status FROM purchase_invoices WHERE id = {$invoiceIds[0]}");
        $invoiceTwo = $this->row("SELECT paid_amount, remaining_amount, status FROM purchase_invoices WHERE id = {$invoiceIds[1]}");

        $this->assertSame('120.00', number_format((float)$invoiceOne['paid_amount'], 2, '.', ''));
        $this->assertSame('0.00', number_format((float)$invoiceOne['remaining_amount'], 2, '.', ''));
        $this->assertSame('paid', (string)$invoiceOne['status']);

        $this->assertSame('20.00', number_format((float)$invoiceTwo['paid_amount'], 2, '.', ''));
        $this->assertSame('70.00', number_format((float)$invoiceTwo['remaining_amount'], 2, '.', ''));
        $this->assertSame('partially_paid', (string)$invoiceTwo['status']);
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

    private function createSupplier(string $name): int
    {
        $stmt = $this->conn->prepare("INSERT INTO suppliers (name, phone, opening_balance) VALUES (?, '', 0)");
        $stmt->bind_param('s', $name);
        $stmt->execute();
        $id = (int)$stmt->insert_id;
        $stmt->close();
        return $id;
    }

    private function createPurchaseInvoice(int $supplierId, string $invDate, string $dueDate, float $total, string $note): int
    {
        $stmt = $this->conn->prepare("
            INSERT INTO purchase_invoices (supplier_id, inv_date, due_date, sub_total, tax, discount, total_amount, paid_amount, remaining_amount, status, items_json, notes)
            VALUES (?, ?, ?, ?, 0, 0, ?, 0, ?, 'unpaid', '[]', ?)
        ");
        $stmt->bind_param('issddds', $supplierId, $invDate, $dueDate, $total, $total, $total, $note);
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
