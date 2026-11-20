<?php

declare(strict_types=1);

use FlowBalance\Finance\Application\PaymentAmounts;
use FlowBalance\Finance\Application\PaymentConversion;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Limits of amounts and exchange rates, independent of the storage engine. */
final class AmountBoundariesTest extends TestCase
{
    #[DataProvider('acceptedAmounts')]
    public function testAcceptsAmountsInsideTheLimits(string $input, string $normalized): void
    {
        self::assertSame($normalized, (new PaymentAmounts($input))->clientPaidAmount);
    }

    public static function acceptedAmounts(): iterable
    {
        yield 'largest amount' => ['99999999999999.99', '99999999999999.99'];
        yield 'smallest amount' => ['0.01', '0.01'];
        yield 'integer gets two decimals' => ['5', '5.00'];
        yield 'one decimal gets padded' => ['12.5', '12.50'];
        yield 'leading zeros are dropped' => ['00012.5', '12.50'];
    }

    #[DataProvider('rejectedAmounts')]
    public function testRejectsAmountsOutsideTheLimits(mixed $input): void
    {
        $this->expectException(InvalidArgumentException::class);
        new PaymentAmounts($input);
    }

    public static function rejectedAmounts(): iterable
    {
        yield 'one more integer digit than allowed' => ['100000000000000'];
        yield 'fifteen integer digits with decimals' => ['100000000000000.00'];
        yield 'three decimals just under the limit' => ['99999999999999.999'];
        yield 'zero' => ['0'];
        yield 'zero with decimals' => ['0.00'];
        yield 'missing integer part' => ['.5'];
        yield 'negative' => ['-1'];
        yield 'exponent notation' => ['1e3'];
        yield 'thousands separator' => ['1,000'];
        yield 'a number instead of text' => [5];
        yield 'null' => [null];
    }

    public function testDeductionsMayBeZeroAndMayEqualTheAmount(): void
    {
        self::assertSame('1.00', (new PaymentAmounts('1', '0.00', '0'))->receivedAmount);
        self::assertSame('0.00', (new PaymentAmounts('1', '0.50', '0.50'))->receivedAmount);
    }

    #[DataProvider('acceptedRates')]
    public function testAcceptsExchangeRatesInsideTheLimits(string $input, string $normalized): void
    {
        self::assertSame($normalized, PaymentConversion::rate('USD', $input));
    }

    public static function acceptedRates(): iterable
    {
        yield 'six decimals' => ['17.123456', '17.123456'];
        yield 'smallest rate' => ['0.000001', '0.000001'];
        yield 'largest rate' => ['99999999999999.999999', '99999999999999.999999'];
        yield 'trailing zeros are dropped' => ['17.100000', '17.1'];
        yield 'leading zeros are dropped' => ['017.1', '17.1'];
        yield 'integer rate' => ['18', '18'];
    }

    #[DataProvider('rejectedRates')]
    public function testRejectsExchangeRatesOutsideTheLimits(string $currency, mixed $input): void
    {
        $this->expectException(InvalidArgumentException::class);
        PaymentConversion::rate($currency, $input);
    }

    public static function rejectedRates(): iterable
    {
        yield 'zero' => ['USD', '0'];
        yield 'zero with decimals' => ['USD', '0.000000'];
        yield 'seven decimals' => ['USD', '17.1234567'];
        yield 'fifteen integer digits' => ['USD', '100000000000000'];
        yield 'exponent notation' => ['USD', '1e1'];
        yield 'a number instead of text' => ['USD', 17];
        yield 'USD without a rate' => ['USD', null];
        yield 'MXN with a rate' => ['MXN', '17'];
    }

    public function testMxnWithoutARateHasNoRate(): void
    {
        self::assertNull(PaymentConversion::rate('MXN', null));
    }
}
