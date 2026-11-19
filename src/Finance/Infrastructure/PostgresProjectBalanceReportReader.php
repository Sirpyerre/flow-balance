<?php

declare(strict_types=1);

namespace FlowBalance\Finance\Infrastructure;

use FlowBalance\Finance\Application\ProjectBalance;
use FlowBalance\Finance\Application\PaymentConversion;
use PDO;

final readonly class PostgresProjectBalanceReportReader
{
    public function __construct(private PDO $database) {}

    public function read(string $userId): array
    {
        $amount = PaymentConversion::PAYMENT_AMOUNT;
        $query = $this->database->prepare(<<<SQL
            SELECT p.id::text AS "projectId", p.name, 'MXN' AS currency,
                   round(p.agreed_amount, 2)::text AS "agreedAmount",
                   COALESCE(SUM({$amount}), 0.00)::text AS "clientPaidTotal",
                   COUNT(payments.id) FILTER (WHERE {$amount} IS NULL) AS "unconvertedPaymentCount"
            FROM projects p
            LEFT JOIN payments ON payments.user_id = p.user_id AND payments.project_id = p.id AND payments.deleted_at IS NULL
            WHERE p.user_id = ?
            GROUP BY p.id
            ORDER BY p.name, p.id
            SQL);
        $query->execute([$userId]);
        $projects = array_map(static fn (array $project): array => [
            ...$project,
            'unconvertedPaymentCount' => (int) $project['unconvertedPaymentCount'],
            'isBalanceProvisional' => (int) $project['unconvertedPaymentCount'] > 0 && $project['agreedAmount'] !== null,
            ...ProjectBalance::calculate($project['agreedAmount'], $project['clientPaidTotal']),
        ], $query->fetchAll(PDO::FETCH_ASSOC));
        return ['projects' => $projects, ...ProjectBalance::summarize($projects)];
    }
}
