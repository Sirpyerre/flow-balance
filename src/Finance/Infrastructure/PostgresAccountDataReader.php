<?php

declare(strict_types=1);

namespace FlowBalance\Finance\Infrastructure;

use FlowBalance\Finance\Application\PaymentConversion;
use PDO;

final readonly class PostgresAccountDataReader
{
    public function __construct(private PDO $database) {}

    public function read(string $userId, string $month): array
    {
        $clients = $this->rows('SELECT id::text, name, to_char(created_at AT TIME ZONE \'America/Mexico_City\', \'YYYY-MM-DD\') AS "createdOn" FROM clients WHERE user_id = ? ORDER BY id', [$userId]);
        $projects = $this->rows('SELECT id::text, name, client_id::text AS "clientId", to_char(created_at AT TIME ZONE \'America/Mexico_City\', \'YYYY-MM-DD\') AS "createdOn" FROM projects WHERE user_id = ? ORDER BY id', [$userId]);
        $payments = $this->rows('SELECT id::text, project_id::text AS "projectId",
            trim_scale(client_paid_amount)::text AS "clientPaidAmount", currency, paid_on AS "paidOn"
            FROM payments WHERE user_id = ? AND deleted_at IS NULL ORDER BY id', [$userId]);
        $chargePeriods = $this->rows("SELECT DISTINCT to_char(c.paid_on, 'YYYY-MM') AS month
            FROM payment_charges c JOIN payments p ON p.id = c.payment_id AND p.user_id = c.user_id
            WHERE c.user_id = ? AND p.deleted_at IS NULL", [$userId]);
        $converted = PaymentConversion::REPORT_PAYMENT_AMOUNT;
        $monthlyPaidTotals = array_column($this->rows("SELECT to_char(paid_on, 'YYYY-MM') AS month,
            COALESCE(SUM({$converted}), 0.00)::text AS amount FROM payments
            WHERE user_id = ? AND deleted_at IS NULL GROUP BY 1", [$userId]), 'amount', 'month');
        return ['clients' => $clients, 'projects' => $projects, 'payments' => $payments,
            'activeClientCount' => count(array_unique(array_column($projects, 'clientId'))),
            'clientCount' => count($clients), 'projectCount' => count($projects), 'paymentCount' => count($payments),
            'month' => $month, 'chargeMonths' => array_column($chargePeriods, 'month'), 'currency' => 'MXN', 'monthlyPaidTotals' => (object) $monthlyPaidTotals,
            'clientPaidTotal' => PostgresMonthlyReportReader::format($monthlyPaidTotals[$month] ?? '0.00')];
    }

    private function rows(string $sql, array $parameters): array
    {
        $query = $this->database->prepare($sql);
        $query->execute($parameters);
        return $query->fetchAll(PDO::FETCH_ASSOC);
    }
}
