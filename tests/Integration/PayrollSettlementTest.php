<?php
declare(strict_types=1);

namespace SmartSystem\Tests\Integration;

use SmartSystem\Tests\Support\DatabaseTestCase;

final class PayrollSettlementTest extends DatabaseTestCase
{
    public function testFullPayrollVoucherSettlesSheetsAndLeavesAdvanceRemainder(): void
    {
        app_ensure_users_core_schema($this->conn);
        app_ensure_payroll_schema($this->conn);
        financeEnsureAllocationSchema($this->conn);

        $stamp = 'TEST-PAYROLL-' . date('YmdHis');
        $employeeName = $stamp . ' Employee';
        $employeeId = $this->createEmployee($stamp, $employeeName);

        $payrollIds = [
            $this->createPayrollSheet($employeeId, $employeeName, date('Y-m', strtotime('-2 months')), 100.00, $stamp . ' PR1'),
            $this->createPayrollSheet($employeeId, $employeeName, date('Y-m', strtotime('-1 months')), 80.00, $stamp . ' PR2'),
        ];

        $receiptId = autoAllocatePayrollPayment(
            $this->conn,
            $employeeId,
            230.00,
            date('Y-m-d'),
            $stamp . ' SALARY',
            'phpunit'
        );

        $this->assertGreaterThan(0, $receiptId);

        foreach ($payrollIds as $payrollId) {
            recalculatePayroll($this->conn, $payrollId);
        }

        $voucher = $this->row("SELECT id, amount, category, payroll_id FROM financial_receipts WHERE id = {$receiptId} LIMIT 1");
        $this->assertSame('230.00', number_format((float)($voucher['amount'] ?? 0), 2, '.', ''));
        $this->assertSame('salary', (string)($voucher['category'] ?? ''));
        $this->assertSame(0, (int)($voucher['payroll_id'] ?? 0));

        $allocRows = $this->rows("SELECT allocation_type, target_id, amount FROM financial_receipt_allocations WHERE receipt_id = {$receiptId} ORDER BY id ASC");
        $this->assertCount(3, $allocRows);
        $this->assertSame('payroll', (string)$allocRows[0]['allocation_type']);
        $this->assertSame('100.00', number_format((float)$allocRows[0]['amount'], 2, '.', ''));
        $this->assertSame('payroll', (string)$allocRows[1]['allocation_type']);
        $this->assertSame('80.00', number_format((float)$allocRows[1]['amount'], 2, '.', ''));
        $this->assertSame('loan_advance', (string)$allocRows[2]['allocation_type']);
        $this->assertSame('50.00', number_format((float)$allocRows[2]['amount'], 2, '.', ''));
        $this->assertSame($employeeId, (int)$allocRows[2]['target_id']);

        $sheetOne = $this->row("SELECT paid_amount, remaining_amount, status FROM payroll_sheets WHERE id = {$payrollIds[0]}");
        $sheetTwo = $this->row("SELECT paid_amount, remaining_amount, status FROM payroll_sheets WHERE id = {$payrollIds[1]}");

        $this->assertSame('100.00', number_format((float)$sheetOne['paid_amount'], 2, '.', ''));
        $this->assertSame('0.00', number_format((float)$sheetOne['remaining_amount'], 2, '.', ''));
        $this->assertSame('paid', (string)$sheetOne['status']);

        $this->assertSame('80.00', number_format((float)$sheetTwo['paid_amount'], 2, '.', ''));
        $this->assertSame('0.00', number_format((float)$sheetTwo['remaining_amount'], 2, '.', ''));
        $this->assertSame('paid', (string)$sheetTwo['status']);

        $loanOutstanding = app_payroll_employee_outstanding_loan($this->conn, $employeeId);
        $this->assertSame('50.00', number_format($loanOutstanding, 2, '.', ''));
    }

    private function createEmployee(string $stamp, string $employeeName): int
    {
        $stmt = $this->conn->prepare("
            INSERT INTO users (username, password, full_name, role, is_active, email)
            VALUES (?, ?, ?, 'employee', 1, ?)
        ");
        $username = 'test_' . strtolower(preg_replace('/[^a-z0-9]+/i', '', $stamp));
        $password = password_hash('test-pass', PASSWORD_DEFAULT);
        $email = $username . '@example.test';
        $stmt->bind_param('ssss', $username, $password, $employeeName, $email);
        $stmt->execute();
        $employeeId = (int)$stmt->insert_id;
        $stmt->close();

        return $employeeId;
    }

    private function createPayrollSheet(int $employeeId, string $employeeName, string $month, float $netSalary, string $note): int
    {
        $stmt = $this->conn->prepare("
            INSERT INTO payroll_sheets (
                employee_id,
                employee_name_snapshot,
                month_year,
                basic_salary,
                bonus,
                deductions,
                loan_deduction,
                net_salary,
                paid_amount,
                remaining_amount,
                status,
                notes
            )
            VALUES (?, ?, ?, ?, 0, 0, 0, ?, 0, ?, 'pending', ?)
        ");
        $stmt->bind_param('issddds', $employeeId, $employeeName, $month, $netSalary, $netSalary, $netSalary, $note);
        $stmt->execute();
        $payrollId = (int)$stmt->insert_id;
        $stmt->close();

        return $payrollId;
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
