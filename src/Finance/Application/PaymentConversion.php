<?php

declare(strict_types=1);

namespace FlowBalance\Finance\Application;

use PDO;

final readonly class PaymentConversion
{
    public const PAYMENT_AMOUNT = 'payment_amount_mxn(client_paid_amount, currency, exchange_rate)';

    public const REPORT_PAYMENT_AMOUNT = "CASE WHEN currency = 'MXN' THEN client_paid_amount ELSE " . self::PAYMENT_AMOUNT . ' END';

    public function __construct(private PDO $database) {}

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
        $query = $this->database->prepare(<<<'SQL'
            WITH input AS (
                SELECT CAST(? AS numeric) AS amount, CAST(? AS numeric) AS fee, CAST(? AS numeric) AS tax,
                       CAST(? AS text) AS currency, CAST(? AS numeric) AS rate
            ), converted AS (
                SELECT payment_amount_mxn(amount, currency, rate) AS paid,
                       payment_amount_mxn(fee, currency, rate) AS fee,
                       payment_amount_mxn(fee + tax, currency, rate) AS deductions
                FROM input
            )
            SELECT paid::text AS "clientPaidAmount", fee::text AS "platformFee",
                   (deductions - fee)::text AS "withheldTaxAmount", (paid - deductions)::text AS "receivedAmount"
            FROM converted
            SQL);
        $query->execute([$amounts->clientPaidAmount, $amounts->platformFee, $amounts->withheldTaxAmount, $currency, $rate]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        return $row['clientPaidAmount'] === null ? null : $row;
    }
}
