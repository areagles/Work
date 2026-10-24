<?php
declare(strict_types=1);

namespace SmartSystem\Tests\Integration;

use SmartSystem\Tests\Support\DatabaseTestCase;

final class QuoteApprovalConversionFlowTest extends DatabaseTestCase
{
    public function testApprovedQuoteConvertsOnceIntoOperationalInvoiceAndLinksBack(): void
    {
        $this->ensureClientsTable();
        $this->ensureQuotesTables();
        $this->ensureInvoicesTable();

        $clientId = $this->createClient('Quote Flow Client ' . date('YmdHis'));
        $quoteId = $this->createApprovedQuote($clientId);

        $result = app_quote_convert_to_invoice($this->conn, $quoteId, 'phpunit');
        $this->assertTrue((bool)($result['ok'] ?? false));
        $this->assertFalse((bool)($result['already_converted'] ?? false));
        $invoiceId = (int)($result['invoice_id'] ?? 0);
        $this->assertGreaterThan(0, $invoiceId);

        $quote = $this->row("SELECT status, converted_invoice_id, converted_at, quote_kind, tax_law_key, tax_total, total_amount FROM quotes WHERE id = {$quoteId} LIMIT 1");
        $invoice = $this->row("SELECT source_quote_id, invoice_kind, tax_law_key, sub_total, tax_total, total_amount, remaining_amount, status, notes FROM invoices WHERE id = {$invoiceId} LIMIT 1");

        $this->assertSame('approved', (string)($quote['status'] ?? ''));
        $this->assertSame($invoiceId, (int)($quote['converted_invoice_id'] ?? 0));
        $this->assertNotSame('', trim((string)($quote['converted_at'] ?? '')));
        $this->assertSame('tax', (string)($quote['quote_kind'] ?? ''));
        $this->assertSame('vat_2016', (string)($quote['tax_law_key'] ?? ''));

        $this->assertSame($quoteId, (int)($invoice['source_quote_id'] ?? 0));
        $this->assertSame('tax', (string)($invoice['invoice_kind'] ?? ''));
        $this->assertSame('vat_2016', (string)($invoice['tax_law_key'] ?? ''));
        $this->assertSame('1000.00', number_format((float)($invoice['sub_total'] ?? 0), 2, '.', ''));
        $this->assertSame('140.00', number_format((float)($invoice['tax_total'] ?? 0), 2, '.', ''));
        $this->assertSame('1140.00', number_format((float)($invoice['total_amount'] ?? 0), 2, '.', ''));
        $this->assertSame('1140.00', number_format((float)($invoice['remaining_amount'] ?? 0), 2, '.', ''));
        $this->assertSame('deferred', (string)($invoice['status'] ?? ''));
        $this->assertStringContainsString('Converted from quote', (string)($invoice['notes'] ?? ''));

        $second = app_quote_convert_to_invoice($this->conn, $quoteId, 'phpunit');
        $this->assertTrue((bool)($second['ok'] ?? false));
        $this->assertTrue((bool)($second['already_converted'] ?? false));
        $this->assertSame($invoiceId, (int)($second['invoice_id'] ?? 0));

        $countRow = $this->row("SELECT COUNT(*) AS total FROM invoices WHERE source_quote_id = {$quoteId}");
        $this->assertSame(1, (int)($countRow['total'] ?? 0));
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

    private function ensureQuotesTables(): void
    {
        $this->conn->query("
            CREATE TABLE IF NOT EXISTS quotes (
                id INT AUTO_INCREMENT PRIMARY KEY,
                client_id INT NOT NULL,
                quote_number VARCHAR(40) DEFAULT NULL,
                created_at DATE NOT NULL,
                valid_until DATE NOT NULL,
                quote_kind VARCHAR(20) NOT NULL DEFAULT 'standard',
                tax_law_key VARCHAR(60) DEFAULT NULL,
                total_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                tax_total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
                notes TEXT DEFAULT NULL,
                client_comment TEXT DEFAULT NULL,
                access_token VARCHAR(100) DEFAULT NULL,
                items_json LONGTEXT DEFAULT NULL,
                taxes_json LONGTEXT DEFAULT NULL,
                converted_invoice_id INT DEFAULT NULL,
                converted_at DATETIME DEFAULT NULL,
                KEY idx_quotes_client (client_id),
                KEY idx_quotes_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $this->conn->query("
            CREATE TABLE IF NOT EXISTS quote_items (
                id INT AUTO_INCREMENT PRIMARY KEY,
                quote_id INT NOT NULL,
                item_name VARCHAR(255) NOT NULL,
                quantity DECIMAL(12,2) NOT NULL DEFAULT 1.00,
                unit VARCHAR(50) DEFAULT NULL,
                price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                KEY idx_quote_items_quote (quote_id)
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
                source_quote_id INT DEFAULT NULL,
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
                status VARCHAR(40) NOT NULL DEFAULT 'deferred',
                items_json LONGTEXT DEFAULT NULL,
                taxes_json LONGTEXT DEFAULT NULL,
                notes TEXT DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                KEY idx_invoices_client (client_id),
                KEY idx_invoices_status (status)
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

    private function createApprovedQuote(int $clientId): int
    {
        $items = [
            ['desc' => 'Boxes', 'qty' => 2.0, 'unit' => 'pcs', 'price' => 300.00, 'total' => 600.00],
            ['desc' => 'Design', 'qty' => 1.0, 'unit' => 'job', 'price' => 400.00, 'total' => 400.00],
        ];
        $itemsJson = json_encode($items, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $taxes = [[
            'key' => 'vat_14',
            'name' => 'VAT 14%',
            'mode' => 'add',
            'amount' => 140.00,
            'signed_amount' => 140.00,
        ]];
        $taxesJson = json_encode($taxes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $today = date('Y-m-d');
        $validUntil = date('Y-m-d', strtotime('+7 days'));
        $notes = 'Approved quote for conversion';
        $token = bin2hex(random_bytes(8));

        $stmt = $this->conn->prepare("
            INSERT INTO quotes (
                client_id, quote_number, created_at, valid_until, quote_kind, tax_law_key,
                total_amount, tax_total, status, notes, access_token, items_json, taxes_json
            ) VALUES (?, 'QT-TEST-001', ?, ?, 'tax', 'vat_2016', 1140.00, 140.00, 'approved', ?, ?, ?, ?)
        ");
        $stmt->bind_param('issssss', $clientId, $today, $validUntil, $notes, $token, $itemsJson, $taxesJson);
        $stmt->execute();
        $quoteId = (int)$stmt->insert_id;
        $stmt->close();

        foreach ($items as $item) {
            $stmtItem = $this->conn->prepare("
                INSERT INTO quote_items (quote_id, item_name, quantity, unit, price, total)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $desc = (string)$item['desc'];
            $qty = (float)$item['qty'];
            $unit = (string)$item['unit'];
            $price = (float)$item['price'];
            $total = (float)$item['total'];
            $stmtItem->bind_param('isdsdd', $quoteId, $desc, $qty, $unit, $price, $total);
            $stmtItem->execute();
            $stmtItem->close();
        }

        return $quoteId;
    }

    private function row(string $sql): array
    {
        $result = $this->conn->query($sql);
        $row = $result ? $result->fetch_assoc() : null;

        return is_array($row) ? $row : [];
    }
}
