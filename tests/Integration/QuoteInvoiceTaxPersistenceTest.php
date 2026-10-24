<?php
declare(strict_types=1);

namespace SmartSystem\Tests\Integration;

use SmartSystem\Tests\Support\DatabaseTestCase;

final class QuoteInvoiceTaxPersistenceTest extends DatabaseTestCase
{
    public function testQuoteAndInvoicePreserveTaxMetadataAndTotalsAcrossSameSourceData(): void
    {
        $this->ensureClientsTable();
        $this->ensureQuotesTables();
        $this->ensureInvoicesTable();

        $clientId = $this->createClient('Client QI ' . date('YmdHis'));
        $items = [
            ['desc' => 'Printed Boxes', 'qty' => 2.0, 'unit' => 'pcs', 'price' => 300.00],
            ['desc' => 'Design Service', 'qty' => 1.0, 'unit' => 'job', 'price' => 400.00],
        ];

        $subTotal = $this->sumItems($items);
        $selectedTaxes = ['vat_14'];
        $quoteCalc = app_tax_calculate_document(app_tax_default_types(), 'tax', $subTotal, 0.00, $selectedTaxes);
        $quoteId = $this->createQuote($clientId, $items, $quoteCalc, 'tax', 'vat_2016');

        $quote = $this->row("SELECT quote_kind, tax_law_key, total_amount, tax_total, items_json, taxes_json FROM quotes WHERE id = {$quoteId} LIMIT 1");
        $quoteTaxLines = app_tax_decode_lines((string)($quote['taxes_json'] ?? '[]'));
        $quoteItems = json_decode((string)($quote['items_json'] ?? '[]'), true);

        $this->assertSame('tax', (string)($quote['quote_kind'] ?? ''));
        $this->assertSame('vat_2016', (string)($quote['tax_law_key'] ?? ''));
        $this->assertSame('140.00', number_format((float)($quote['tax_total'] ?? 0), 2, '.', ''));
        $this->assertSame('1140.00', number_format((float)($quote['total_amount'] ?? 0), 2, '.', ''));
        $this->assertIsArray($quoteItems);
        $this->assertCount(2, $quoteItems);
        $this->assertCount(1, $quoteTaxLines);
        $this->assertSame('vat_14', (string)($quoteTaxLines[0]['key'] ?? ''));

        $invoiceCalc = app_tax_calculate_document(app_tax_default_types(), 'tax', $subTotal, 100.00, $selectedTaxes);
        $invoiceId = $this->createInvoiceFromSourceData($clientId, $items, $invoiceCalc, 'tax', 'vat_2016', 100.00);

        $invoice = $this->row("SELECT invoice_kind, tax_law_key, sub_total, discount, total_amount, tax_total, items_json, taxes_json, remaining_amount, status FROM invoices WHERE id = {$invoiceId} LIMIT 1");
        $invoiceTaxLines = app_tax_decode_lines((string)($invoice['taxes_json'] ?? '[]'));
        $invoiceItems = json_decode((string)($invoice['items_json'] ?? '[]'), true);

        $this->assertSame('tax', (string)($invoice['invoice_kind'] ?? ''));
        $this->assertSame('vat_2016', (string)($invoice['tax_law_key'] ?? ''));
        $this->assertSame('1000.00', number_format((float)($invoice['sub_total'] ?? 0), 2, '.', ''));
        $this->assertSame('100.00', number_format((float)($invoice['discount'] ?? 0), 2, '.', ''));
        $this->assertSame('126.00', number_format((float)($invoice['tax_total'] ?? 0), 2, '.', ''));
        $this->assertSame('1026.00', number_format((float)($invoice['total_amount'] ?? 0), 2, '.', ''));
        $this->assertSame('1026.00', number_format((float)($invoice['remaining_amount'] ?? 0), 2, '.', ''));
        $this->assertSame('deferred', (string)($invoice['status'] ?? ''));
        $this->assertIsArray($invoiceItems);
        $this->assertCount(2, $invoiceItems);
        $this->assertCount(1, $invoiceTaxLines);
        $this->assertSame('vat_14', (string)($invoiceTaxLines[0]['key'] ?? ''));
        $this->assertSame('126.00', number_format((float)($invoiceTaxLines[0]['signed_amount'] ?? 0), 2, '.', ''));
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
                quote_number VARCHAR(40) DEFAULT NULL,
                client_id INT NOT NULL,
                created_at DATE NOT NULL,
                valid_until DATE DEFAULT NULL,
                quote_kind VARCHAR(20) NOT NULL DEFAULT 'standard',
                tax_law_key VARCHAR(60) DEFAULT NULL,
                total_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                tax_total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                taxes_json LONGTEXT DEFAULT NULL,
                items_json LONGTEXT DEFAULT NULL,
                status VARCHAR(40) NOT NULL DEFAULT 'pending',
                notes TEXT DEFAULT NULL,
                access_token VARCHAR(100) DEFAULT NULL,
                created_ts TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                KEY idx_quotes_client (client_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $this->conn->query("
            CREATE TABLE IF NOT EXISTS quote_items (
                id INT AUTO_INCREMENT PRIMARY KEY,
                quote_id INT NOT NULL,
                item_name VARCHAR(255) NOT NULL,
                quantity DECIMAL(12,2) NOT NULL DEFAULT 0.00,
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

    private function createQuote(int $clientId, array $items, array $taxCalc, string $quoteKind, string $taxLawKey): int
    {
        $itemsJson = json_encode($items, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $taxesJson = json_encode(($taxCalc['lines'] ?? []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $today = date('Y-m-d');
        $validUntil = date('Y-m-d', strtotime('+7 days'));
        $notes = 'PHPUnit quote source';
        $token = bin2hex(random_bytes(8));

        $stmt = $this->conn->prepare("
            INSERT INTO quotes (
                client_id, created_at, valid_until, quote_kind, tax_law_key,
                total_amount, tax_total, taxes_json, items_json, status, notes, access_token
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, ?)
        ");
        $grandTotal = (float)($taxCalc['grand_total'] ?? 0);
        $taxTotal = (float)($taxCalc['tax_total'] ?? 0);
        $stmt->bind_param('issssddssss', $clientId, $today, $validUntil, $quoteKind, $taxLawKey, $grandTotal, $taxTotal, $taxesJson, $itemsJson, $notes, $token);
        $stmt->execute();
        $quoteId = (int)$stmt->insert_id;
        $stmt->close();

        $stmtItem = $this->conn->prepare("
            INSERT INTO quote_items (quote_id, item_name, quantity, unit, price, total)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        foreach ($items as $item) {
            $desc = (string)$item['desc'];
            $qty = (float)$item['qty'];
            $unit = (string)$item['unit'];
            $price = (float)$item['price'];
            $total = $qty * $price;
            $stmtItem->bind_param('isdsdd', $quoteId, $desc, $qty, $unit, $price, $total);
            $stmtItem->execute();
        }
        $stmtItem->close();

        return $quoteId;
    }

    private function createInvoiceFromSourceData(int $clientId, array $items, array $taxCalc, string $invoiceKind, string $taxLawKey, float $discount): int
    {
        $itemsJson = json_encode($items, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $taxesJson = json_encode(($taxCalc['lines'] ?? []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $today = date('Y-m-d');
        $dueDate = date('Y-m-d', strtotime('+14 days'));
        $notes = 'PHPUnit invoice from quote source';
        $subTotal = $this->sumItems($items);
        $grandTotal = (float)($taxCalc['grand_total'] ?? 0);
        $taxTotal = (float)($taxCalc['tax_total'] ?? 0);

        $stmt = $this->conn->prepare("
            INSERT INTO invoices (
                client_id, inv_date, due_date, invoice_kind, tax_law_key, sub_total,
                tax, tax_total, discount, total_amount, items_json, taxes_json,
                notes, paid_amount, remaining_amount, status
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, 'deferred')
        ");
        $stmt->bind_param(
            'issssdddddsssd',
            $clientId,
            $today,
            $dueDate,
            $invoiceKind,
            $taxLawKey,
            $subTotal,
            $taxTotal,
            $taxTotal,
            $discount,
            $grandTotal,
            $itemsJson,
            $taxesJson,
            $notes,
            $grandTotal
        );
        $stmt->execute();
        $invoiceId = (int)$stmt->insert_id;
        $stmt->close();

        return $invoiceId;
    }

    private function sumItems(array $items): float
    {
        $sum = 0.0;
        foreach ($items as $item) {
            $sum += ((float)$item['qty']) * ((float)$item['price']);
        }

        return $sum;
    }

    private function row(string $sql): array
    {
        $result = $this->conn->query($sql);
        $row = $result ? $result->fetch_assoc() : null;

        return is_array($row) ? $row : [];
    }
}
