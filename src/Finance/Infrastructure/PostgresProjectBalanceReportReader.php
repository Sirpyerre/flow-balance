<?php

declare(strict_types=1);

namespace FlowBalance\Finance\Infrastructure;

use FlowBalance\Finance\Application\ProjectBalance;
use PDO;

final readonly class PostgresProjectBalanceReportReader
{
    public function __construct(private PDO $database) {}

    public function read(string $userId): array
    {
        $amount = PostgresOwnedResourceRepository::BALANCE_PAYMENT_AMOUNT;
        $query = $this->database->prepare(<<<SQL
            SELECT p.id::text AS "projectId", p.name, 'MXN' AS currency,
                   round(p.agreed_amount, 2)::text AS "agreedAmount",
                   COALESCE(SUM({$amount}), 0.00)::text AS "clientPaidTotal"
            FROM projects p
            LEFT JOIN payments ON payments.user_id = p.user_id AND payments.project_id = p.id AND payments.currency = 'MXN' AND payments.deleted_at IS NULL
            WHERE p.user_id = ?
            GROUP BY p.id
            ORDER BY p.name, p.id
            SQL);
        $query->execute([$userId]);
        return ['projects' => array_map(
            static fn (array $project): array => [
                ...$project,
                ...ProjectBalance::calculate($project['agreedAmount'], $project['clientPaidTotal']),
            ],
            $query->fetchAll(PDO::FETCH_ASSOC),
        )];
    }
}
