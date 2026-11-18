<?php

declare(strict_types=1);

namespace FlowBalance\Finance\Infrastructure;

use PDO;

final readonly class PostgresAccountDataReader
{
    public function __construct(private PDO $database) {}

    public function read(string $userId, string $month): array
    {
        $clients = $this->rows('SELECT id::text, name FROM clients WHERE user_id = ? ORDER BY id', [$userId]);
        $projects = $this->rows('SELECT id::text, name, client_id::text AS "clientId" FROM projects WHERE user_id = ? ORDER BY id', [$userId]);
        $payments = $this->rows('SELECT id::text, project_id::text AS "projectId",
            trim_scale(client_paid_amount)::text AS "clientPaidAmount", currency, paid_on AS "paidOn"
            FROM payments WHERE user_id = ? ORDER BY id', [$userId]);
        $report = (new PostgresMonthlyReportReader($this->database))->read($userId, $month, 'MXN');
        return ['clients' => $clients, 'projects' => $projects, 'payments' => $payments,
            'month' => $month, 'currency' => 'MXN', 'clientPaidTotal' => $report['clientPaidTotal']];
    }

    private function rows(string $sql, array $parameters): array
    {
        $query = $this->database->prepare($sql);
        $query->execute($parameters);
        return $query->fetchAll(PDO::FETCH_ASSOC);
    }
}
