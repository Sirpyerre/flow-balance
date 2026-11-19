<?php

declare(strict_types=1);

use FlowBalance\Finance\Application\EditPayment;
use PHPUnit\Framework\TestCase;

final class PaymentCorrectionTest extends TestCase
{
    public function testRequiresReasonOnlyWhenTheExcessIncreases(): void
    {
        self::assertFalse(EditPayment::increasesExcess(null, '0.00', '0.04'));
        self::assertFalse(EditPayment::increasesExcess('0.04', '0.02', '0.04'));
        self::assertTrue(EditPayment::increasesExcess('0.03', '0.02', '0.04'));
        self::assertFalse(EditPayment::increasesExcess('0.03', '0.04', '0.04'));
        self::assertFalse(EditPayment::increasesExcess('0.03', '0.05', '0.04'));
        self::assertTrue(EditPayment::increasesExcess('0.03', '0.04', '0.05'));
    }
}
