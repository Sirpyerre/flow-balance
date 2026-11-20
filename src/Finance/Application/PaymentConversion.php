<?php

declare(strict_types=1);

namespace FlowBalance\Finance\Application;

use PDO;

final readonly class PaymentConversion
{
    public function __construct(private PDO $database) {}

    /**
     * SQL for the MXN value of an amount, rounded to cents with halves moving away from zero. It is NULL for a
     * dollar amount without an exchange rate. An expression and not a stored function, so the application user
     * needs no routine privileges (design D8).
     */
    public static function mxn(string $amount, string $currency = 'currency', string $rate = 'exchange_rate'): string
    {
        return "CASE {$currency} WHEN 'MXN' THEN ROUND({$amount}, 2) WHEN 'USD' THEN ROUND({$amount} * {$rate}, 2) END";
    }

    /** The MXN value of a payment's client-paid amount. */
    public static function paymentAmount(): string
    {
        return self::mxn('client_paid_amount');
    }

    /** Like paymentAmount(), but pesos keep the stored precision for the reports. */
    public static function reportPaymentAmount(): string
    {
        return "CASE WHEN currency = 'MXN' THEN client_paid_amount ELSE " . self::paymentAmount() . ' END';
    }

    public static function rate(string $currency, mixed $value): ?string
    {
        if ($currency === 'MXN' && $value === null) {
            return null;
        }
        if ($currency !== 'USD' || !is_string($value) || !preg_match('/\A\d{1,14}(?:\.\d{1,6})?\z/', $value)
            || trim($value, '0.') === '') {
            throw new IncomeValidation('exchangeRate', 'Indica un tipo de cambio positivo de MXN por USD con hasta seis decimales.');
        }
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $whole = ltrim($whole, '0') ?: '0';
        $fraction = rtrim($fraction, '0');
        return $fraction === '' ? $whole : $whole . '.' . $fraction;
    }

    public function equivalents(PaymentAmounts $amounts, string $currency, ?string $rate): ?array
    {
        // Each CAST is as wide as the column it mirrors: a DECIMAL without precision would truncate (design D24).
        $paid = self::mxn('amount', 'currency', 'rate');
        $fee = self::mxn('fee', 'currency', 'rate');
        $deductions = self::mxn('(fee + tax)', 'currency', 'rate');
        $query = $this->database->prepare(<<<SQL
            WITH input AS (
                SELECT CAST(? AS DECIMAL(22,8)) AS amount, CAST(? AS DECIMAL(22,8)) AS fee, CAST(? AS DECIMAL(22,8)) AS tax,
                       CAST(? AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_nopad_bin AS currency, CAST(? AS DECIMAL(26,12)) AS rate
            ), converted AS (
                SELECT {$paid} AS paid, {$fee} AS fee, {$deductions} AS deductions
                FROM input
            )
            SELECT CAST(paid AS CHAR) AS clientPaidAmount, CAST(fee AS CHAR) AS platformFee,
                   CAST(deductions - fee AS CHAR) AS withheldTaxAmount, CAST(paid - deductions AS CHAR) AS receivedAmount
            FROM converted
            SQL);
        $query->execute([$amounts->clientPaidAmount, $amounts->platformFee, $amounts->withheldTaxAmount, $currency, $rate]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        return $row['clientPaidAmount'] === null ? null : $row;
    }
}
