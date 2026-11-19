<?php
declare(strict_types=1);

namespace FlowBalance\Finance\Application;

use InvalidArgumentException;

final readonly class PaymentAmounts
{
    public string $clientPaidAmount;
    public string $platformFee;
    public string $withheldTaxAmount;
    public string $receivedAmount;

    public function __construct(mixed $amount, mixed $fee = '0', mixed $tax = '0', bool $allowZeroAmount = false)
    {
        $this->clientPaidAmount = self::normalize($amount, 'clientPaidAmount', $allowZeroAmount);
        $this->platformFee = self::normalize($fee, 'platformFee', true);
        $this->withheldTaxAmount = self::normalize($tax, 'withheldTaxAmount', true);
        $received = self::cents($this->clientPaidAmount) - self::cents($this->platformFee) - self::cents($this->withheldTaxAmount);
        if ($received < 0) {
            throw new IncomeValidation('withheldTaxAmount', 'Las deducciones no pueden superar el importe abonado.');
        }
        $this->receivedAmount = intdiv($received, 100) . '.' . str_pad((string) ($received % 100), 2, '0', STR_PAD_LEFT);
    }

    public static function normalize(mixed $value, string $field, bool $allowZero = false): string
    {
        try {
            return (new ClientPaidAmount($value, $allowZero))->value;
        } catch (InvalidArgumentException) {
            throw new IncomeValidation($field, 'Ingresa un importe válido con hasta 14 dígitos enteros y dos decimales.');
        }
    }

    private static function cents(string $amount): int
    {
        return (int) str_replace('.', '', $amount);
    }
}
