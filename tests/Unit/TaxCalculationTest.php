<?php
declare(strict_types=1);

namespace SmartSystem\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class TaxCalculationTest extends TestCase
{
    public function testStandardInvoiceDoesNotApplyTaxesEvenWhenKeysAreSelected(): void
    {
        $catalog = app_tax_default_types();

        $result = app_tax_calculate_document($catalog, 'standard', 1000.00, 100.00, ['vat_14', 'withholding_1']);

        $this->assertSame('900.00', number_format((float)$result['net_base'], 2, '.', ''));
        $this->assertSame('0.00', number_format((float)$result['tax_total'], 2, '.', ''));
        $this->assertSame('900.00', number_format((float)$result['grand_total'], 2, '.', ''));
        $this->assertCount(0, $result['lines']);
    }

    public function testTaxInvoiceAppliesActiveAdditiveTaxOnNetAmountAfterDiscount(): void
    {
        $catalog = app_tax_default_types();

        $result = app_tax_calculate_document($catalog, 'tax', 1000.00, 100.00, ['vat_14']);

        $this->assertSame('900.00', number_format((float)$result['net_base'], 2, '.', ''));
        $this->assertSame('126.00', number_format((float)$result['tax_total'], 2, '.', ''));
        $this->assertSame('1026.00', number_format((float)$result['grand_total'], 2, '.', ''));
        $this->assertCount(1, $result['lines']);
        $this->assertSame('vat_14', (string)$result['lines'][0]['key']);
        $this->assertSame('add', (string)$result['lines'][0]['mode']);
        $this->assertSame('126.00', number_format((float)$result['lines'][0]['signed_amount'], 2, '.', ''));
    }

    public function testTaxInvoiceSupportsMixedAddAndSubtractTaxes(): void
    {
        $catalog = app_tax_default_types();
        foreach ($catalog as &$row) {
            if (($row['key'] ?? '') === 'withholding_1') {
                $row['is_active'] = 1;
            }
        }
        unset($row);

        $result = app_tax_calculate_document($catalog, 'tax', 1000.00, 100.00, ['vat_14', 'withholding_1']);

        $this->assertSame('900.00', number_format((float)$result['net_base'], 2, '.', ''));
        $this->assertSame('117.00', number_format((float)$result['tax_total'], 2, '.', ''));
        $this->assertSame('1017.00', number_format((float)$result['grand_total'], 2, '.', ''));
        $this->assertCount(2, $result['lines']);
        $this->assertSame('126.00', number_format((float)$result['lines'][0]['signed_amount'], 2, '.', ''));
        $this->assertSame('-9.00', number_format((float)$result['lines'][1]['signed_amount'], 2, '.', ''));
    }

    public function testInactiveTaxesAreIgnored(): void
    {
        $catalog = app_tax_default_types();

        $result = app_tax_calculate_document($catalog, 'tax', 1000.00, 0.00, ['withholding_1', 'stamp_0_4']);

        $this->assertSame('0.00', number_format((float)$result['tax_total'], 2, '.', ''));
        $this->assertSame('1000.00', number_format((float)$result['grand_total'], 2, '.', ''));
        $this->assertCount(0, $result['lines']);
    }
}
