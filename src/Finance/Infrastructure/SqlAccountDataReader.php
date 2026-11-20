<?php

declare(strict_types=1);

namespace FlowBalance\Finance\Infrastructure;

use DateTimeImmutable;
use DateTimeZone;
use FlowBalance\Finance\Application\DecimalText;
use FlowBalance\Finance\Application\PaymentConversion;
use PDO;

final readonly class SqlAccountDataReader
{
    private const LOCAL_ZONE = 'America/Mexico_City';

    public function __construct(private PDO $database) {}

    public function read(string $userId, string $month): array
    {
        $clients = $this->withLocalDay($this->rows('SELECT CAST(id AS CHAR) AS id, name, created_at FROM clients WHERE user_id = ? ORDER BY id', [$userId]));
        $projects = $this->withLocalDay($this->rows('SELECT CAST(id AS CHAR) AS id, name, CAST(client_id AS CHAR) AS clientId, created_at FROM projects WHERE user_id = ? ORDER BY id', [$userId]));
        $payments = array_map(static fn (array $payment): array => [
            ...$payment, 'clientPaidAmount' => DecimalText::trimmed($payment['clientPaidAmount']),
        ], $this->rows('SELECT CAST(id AS CHAR) AS id, CAST(project_id AS CHAR) AS projectId,
            CAST(client_paid_amount AS CHAR) AS clientPaidAmount, currency, paid_on AS paidOn
            FROM payments WHERE user_id = ? AND deleted_at IS NULL ORDER BY id', [$userId]));
        $chargePeriods = $this->rows("SELECT DISTINCT DATE_FORMAT(c.paid_on, '%Y-%m') AS month
            FROM payment_charges c JOIN payments p ON p.id = c.payment_id AND p.user_id = c.user_id
            WHERE c.user_id = ? AND p.deleted_at IS NULL ORDER BY month", [$userId]);
        $converted = PaymentConversion::reportPaymentAmount();
        $monthlyPaidTotals = array_column($this->rows("SELECT DATE_FORMAT(paid_on, '%Y-%m') AS month,
            CAST(COALESCE(SUM({$converted}), 0.00) AS CHAR) AS amount FROM payments
            WHERE user_id = ? AND deleted_at IS NULL GROUP BY 1", [$userId]), 'amount', 'month');
        return ['clients' => $clients, 'projects' => $projects, 'payments' => $payments,
            'activeClientCount' => count(array_unique(array_column($projects, 'clientId'))),
            'clientCount' => count($clients), 'projectCount' => count($projects), 'paymentCount' => count($payments),
            'month' => $month, 'chargeMonths' => array_column($chargePeriods, 'month'), 'currency' => 'MXN', 'monthlyPaidTotals' => (object) $monthlyPaidTotals,
            'clientPaidTotal' => SqlMonthlyReportReader::format($monthlyPaidTotals[$month] ?? '0.00')];
    }

    /**
     * Replaces created_at (stored in UTC) with the calendar day it falls on in Mexico City. Done here and not with
     * CONVERT_TZ, which only works when the server has its time zone tables loaded (design D22).
     */
    private function withLocalDay(array $rows): array
    {
        $utc = new DateTimeZone('UTC');
        $local = new DateTimeZone(self::LOCAL_ZONE);
        return array_map(static function (array $row) use ($utc, $local): array {
            $createdAt = $row['created_at'];
            unset($row['created_at']);
            return [...$row, 'createdOn' => (new DateTimeImmutable($createdAt, $utc))->setTimezone($local)->format('Y-m-d')];
        }, $rows);
    }

    private function rows(string $sql, array $parameters): array
    {
        $query = $this->database->prepare($sql);
        $query->execute($parameters);
        return $query->fetchAll(PDO::FETCH_ASSOC);
    }
}
