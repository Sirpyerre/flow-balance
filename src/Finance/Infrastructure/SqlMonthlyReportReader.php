<?php

declare(strict_types=1);

namespace FlowBalance\Finance\Infrastructure;

use FlowBalance\Finance\Application\DecimalText;
use FlowBalance\Finance\Application\PaymentConversion;
use PDO;

final readonly class SqlMonthlyReportReader
{
    public function __construct(private PDO $database) {}

    public function read(string $userId, string $month, string $currency): array
    {
        $converted = PaymentConversion::reportPaymentAmount();
        $fee = PaymentConversion::mxn('platform_fee');
        $deductions = PaymentConversion::mxn('(platform_fee + withheld_tax_amount)');
        // One statement returns the movements and, on every row, the totals over all of them (window functions),
        // so both come from the same instant. The movements are shaped in PHP: JSON functions would turn amounts
        // into numbers and lose the text format of the API.
        $query = $this->database->prepare(<<<SQL
            WITH period AS (
                SELECT CAST(? AS DATE) AS start_date, DATE_ADD(CAST(? AS DATE), INTERVAL 1 MONTH) AS end_date
            ), owned AS (
                SELECT * FROM payments WHERE user_id = ? AND deleted_at IS NULL
            ), credits AS (
                SELECT id, paid_on, currency, exchange_rate, client_paid_amount, platform_fee, withheld_tax_amount,
                       {$converted} AS paid_mxn,
                       {$fee} AS fee_mxn,
                       {$deductions} AS deductions_mxn
                FROM owned CROSS JOIN period WHERE paid_on >= start_date AND paid_on < end_date
            ), movements AS (
                SELECT id AS payment_id, id AS movement_id, paid_on, 'payment' AS kind, currency, exchange_rate,
                       client_paid_amount AS original_paid, platform_fee AS original_fee, withheld_tax_amount AS original_tax,
                       paid_mxn, fee_mxn, deductions_mxn - fee_mxn AS tax_mxn
                FROM credits
                UNION ALL
                SELECT c.payment_id, c.id, c.paid_on, c.charge_type, c.currency, NULL,
                       0, CASE WHEN c.charge_type = 'commission' THEN c.amount ELSE 0 END,
                       CASE WHEN c.charge_type = 'tax' THEN c.amount ELSE 0 END,
                       0, CASE WHEN c.charge_type = 'commission' THEN c.amount ELSE 0 END,
                       CASE WHEN c.charge_type = 'tax' THEN c.amount ELSE 0 END
                FROM payment_charges c JOIN owned p ON p.user_id = c.user_id AND p.id = c.payment_id
                CROSS JOIN period WHERE c.paid_on >= start_date AND c.paid_on < end_date
            )
            SELECT CAST(payment_id AS CHAR) AS paymentId, CAST(movement_id AS CHAR) AS movementId, paid_on AS paidOn, kind, currency,
                   CAST(exchange_rate AS CHAR) AS exchangeRate,
                   CAST(original_paid AS CHAR) AS originalPaidAmount, CAST(original_fee AS CHAR) AS originalPlatformFee,
                   CAST(original_tax AS CHAR) AS originalTaxAmount, CAST(paid_mxn AS CHAR) AS clientPaidAmount,
                   CAST(fee_mxn AS CHAR) AS platformFee, CAST(tax_mxn AS CHAR) AS taxAmount,
                   CAST(paid_mxn - fee_mxn - tax_mxn AS CHAR) AS netAmount,
                   CAST(SUM(paid_mxn) OVER () AS CHAR) AS paid_total, CAST(SUM(fee_mxn) OVER () AS CHAR) AS fee_total,
                   CAST(SUM(tax_mxn) OVER () AS CHAR) AS tax_total, CAST(SUM(paid_mxn - fee_mxn - tax_mxn) OVER () AS CHAR) AS net_total,
                   SUM(paid_mxn IS NULL) OVER () AS unconverted
            FROM movements
            ORDER BY paid_on DESC, payment_id DESC, kind, movement_id DESC
            SQL);
        $query->execute([$month . '-01', $month . '-01', $userId]);
        $rows = $query->fetchAll(PDO::FETCH_ASSOC);
        $totals = $rows[0] ?? ['paid_total' => '0', 'fee_total' => '0', 'tax_total' => '0', 'net_total' => '0', 'unconverted' => '0'];
        $payments = [];
        $movements = [];
        foreach ($rows as $row) {
            $movement = [
                'paymentId' => $row['paymentId'], 'movementId' => $row['movementId'], 'paidOn' => $row['paidOn'],
                'kind' => $row['kind'], 'currency' => $row['currency'], 'exchangeRate' => DecimalText::trimmed($row['exchangeRate']),
            ];
            foreach (['originalPaidAmount', 'originalPlatformFee', 'originalTaxAmount', 'clientPaidAmount', 'platformFee', 'taxAmount', 'netAmount'] as $field) {
                $movement[$field] = $row[$field] === null ? null : self::format($row[$field]);
            }
            $movements[] = $movement;
            if ($movement['kind'] === 'payment' && $movement['clientPaidAmount'] !== null) {
                $payments[] = [
                    'id' => $movement['paymentId'], 'paidOn' => $movement['paidOn'], 'currency' => $currency,
                    'clientPaidAmount' => $movement['clientPaidAmount'],
                ];
            }
        }
        usort($payments, static fn (array $left, array $right): int => (int) $left['id'] <=> (int) $right['id']);
        return [
            'month' => $month, 'currency' => $currency, 'clientPaidTotal' => self::format($totals['paid_total'] ?? '0'),
            'platformFeeTotal' => self::format($totals['fee_total'] ?? '0'), 'taxTotal' => self::format($totals['tax_total'] ?? '0'),
            'netReceivedTotal' => self::format($totals['net_total'] ?? '0'), 'unconvertedPaymentCount' => (int) $totals['unconverted'],
            'payments' => $payments, 'movements' => $movements,
        ];
    }

    public static function format(string $decimal): string
    {
        [$integer, $fraction] = array_pad(explode('.', $decimal, 2), 2, '');
        return $integer . '.' . str_pad(rtrim($fraction, '0'), 2, '0');
    }
}
