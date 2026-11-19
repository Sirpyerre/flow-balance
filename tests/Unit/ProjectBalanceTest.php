<?php

declare(strict_types=1);

use FlowBalance\Finance\Application\ProjectBalance;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ProjectBalanceTest extends TestCase
{
    #[DataProvider('balances')]
    public function testCalculatesExactBalance(?string $agreement, string $paid, ?string $balance, ?string $excess, string $status): void
    {
        self::assertSame([
            'outstandingBalance' => $balance,
            'excessAmount' => $excess,
            'collectionStatus' => $status,
        ], ProjectBalance::calculate($agreement, $paid));
    }

    public static function balances(): iterable
    {
        yield ['5000.00', '0.00', '5000.00', '0.00', 'outstanding'];
        yield ['5000.00', '3000.00', '2000.00', '0.00', 'outstanding'];
        yield ['0.30', '0.30', '0.00', '0.00', 'settled'];
        yield ['0.30', '0.04', '0.26', '0.00', 'outstanding'];
        yield ['5000.00', '5300.00', '0.00', '300.00', 'overpaid'];
        yield [null, '3000.00', null, null, 'undetermined'];
        yield ['99999999999999.99', '100000000000000000000.00', '0.00', '99999900000000000000.01', 'overpaid'];
    }
}
