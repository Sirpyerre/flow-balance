<?php

declare(strict_types=1);

namespace FlowBalance\Finance\Infrastructure;

use FlowBalance\Finance\Application\PaymentConversion;
use PDO;

final readonly class PostgresMonthlyReportReader
{
    public function __construct(private PDO $database) {}

    public function read(string $userId, string $month, string $currency): array
    {
        $converted = PaymentConversion::REPORT_PAYMENT_AMOUNT;
        $query = $this->database->prepare(<<<SQL
            WITH period AS (
                SELECT CAST(? AS date) AS start_date, CAST(? AS date) + INTERVAL '1 month' AS end_date
            ), owned AS (
                SELECT * FROM payments WHERE user_id = ? AND deleted_at IS NULL
            ), credits AS (
                SELECT id, paid_on, currency, exchange_rate, client_paid_amount, platform_fee, withheld_tax_amount,
                       {$converted} AS paid_mxn,
                       payment_amount_mxn(platform_fee, currency, exchange_rate) AS fee_mxn,
                       payment_amount_mxn(platform_fee + withheld_tax_amount, currency, exchange_rate) AS deductions_mxn
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
            SELECT COALESCE(SUM(paid_mxn), 0)::text AS paid,
                   COALESCE(SUM(fee_mxn), 0)::text AS fees,
                   COALESCE(SUM(tax_mxn), 0)::text AS taxes,
                   COALESCE(SUM(paid_mxn - fee_mxn - tax_mxn), 0)::text AS net,
                   COUNT(*) FILTER (WHERE paid_mxn IS NULL) AS unconverted,
                   COALESCE(json_agg(json_build_object(
                       'paymentId', payment_id::text, 'movementId', movement_id::text, 'paidOn', paid_on, 'kind', kind,
                       'currency', currency, 'exchangeRate', exchange_rate::text,
                       'originalPaidAmount', original_paid::text, 'originalPlatformFee', original_fee::text,
                       'originalTaxAmount', original_tax::text, 'clientPaidAmount', paid_mxn::text,
                       'platformFee', fee_mxn::text, 'taxAmount', tax_mxn::text,
                       'netAmount', (paid_mxn - fee_mxn - tax_mxn)::text
                   ) ORDER BY paid_on DESC, payment_id DESC, kind, movement_id DESC), '[]')::text AS movements
            FROM movements
            SQL);
        $query->execute([$month . '-01', $month . '-01', $userId]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        $movements = json_decode($row['movements'], true, flags: JSON_THROW_ON_ERROR);
        $payments = [];
        foreach ($movements as &$movement) {
            foreach (['originalPaidAmount', 'originalPlatformFee', 'originalTaxAmount', 'clientPaidAmount', 'platformFee', 'taxAmount', 'netAmount'] as $field) {
                if ($movement[$field] !== null) {
                    $movement[$field] = self::format($movement[$field]);
                }
            }
            if ($movement['kind'] === 'payment' && $movement['clientPaidAmount'] !== null) {
                $payments[] = [
                    'id' => $movement['paymentId'], 'paidOn' => $movement['paidOn'], 'currency' => $currency,
                    'clientPaidAmount' => $movement['clientPaidAmount'],
                ];
            }
        }
        unset($movement);
        usort($payments, static fn (array $left, array $right): int => (int) $left['id'] <=> (int) $right['id']);
        return [
            'month' => $month, 'currency' => $currency, 'clientPaidTotal' => self::format($row['paid']),
            'platformFeeTotal' => self::format($row['fees']), 'taxTotal' => self::format($row['taxes']),
            'netReceivedTotal' => self::format($row['net']), 'unconvertedPaymentCount' => (int) $row['unconverted'],
            'payments' => $payments, 'movements' => $movements,
        ];
    }

    public static function format(string $decimal): string
    {
        [$integer, $fraction] = array_pad(explode('.', $decimal, 2), 2, '');
        return $integer . '.' . str_pad(rtrim($fraction, '0'), 2, '0');
    }
}
