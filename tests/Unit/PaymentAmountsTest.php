<?php
declare(strict_types=1);

use FlowBalance\Finance\Application\PaymentAmounts;
use PHPUnit\Framework\TestCase;

final class PaymentAmountsTest extends TestCase
{
    public function testExactReceivedAmount(): void
    {
        self::assertSame('0.00', (new PaymentAmounts('0.30', '0.10', '0.20'))->receivedAmount);
        self::assertSame('2800.00', (new PaymentAmounts('3000', '150.25', '49.75'))->receivedAmount);
        self::assertSame('99999999999999.99', (new PaymentAmounts('99999999999999.99'))->receivedAmount);
    }

    public function testRejectsExcessPrecision(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new PaymentAmounts('10.001');
    }

    public function testRejectsNegativeDeduction(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new PaymentAmounts('10', '-1');
    }

    public function testRejectsDeductionsGreaterThanPayment(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new PaymentAmounts('1', '0.60', '0.50');
    }
}
