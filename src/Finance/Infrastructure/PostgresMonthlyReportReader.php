<?php

declare(strict_types=1);

namespace FlowBalance\Finance\Infrastructure;

use PDO;

final readonly class PostgresMonthlyReportReader
{
    public function __construct(private PDO $database) {}

    public function read(string $userId, string $month, string $currency): array
    {
        $query = $this->database->prepare(<<<'SQL'
            SELECT COALESCE(SUM(client_paid_amount), 0)::text AS total,
                   COALESCE(json_agg(json_build_object(
                       'id', id::text, 'clientPaidAmount', client_paid_amount::text,
                       'currency', currency, 'paidOn', paid_on
                   ) ORDER BY id), '[]'::json)::text AS payments
            FROM payments
            WHERE user_id = ? AND currency = ? AND deleted_at IS NULL
              AND paid_on >= CAST(? AS date)
              AND paid_on < CAST(? AS date) + INTERVAL '1 month'
            SQL);
        $query->execute([$userId, $currency, $month . '-01', $month . '-01']);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        $payments = json_decode($row['payments'], true, flags: JSON_THROW_ON_ERROR);
        foreach ($payments as &$payment) {
            $payment['clientPaidAmount'] = self::format($payment['clientPaidAmount']);
        }
        return ['month' => $month, 'currency' => $currency, 'clientPaidTotal' => self::format($row['total']), 'payments' => $payments];
    }

    private static function format(string $decimal): string
    {
        [$integer, $fraction] = array_pad(explode('.', $decimal, 2), 2, '');
        return $integer . '.' . str_pad(rtrim($fraction, '0'), 2, '0');
    }
}
