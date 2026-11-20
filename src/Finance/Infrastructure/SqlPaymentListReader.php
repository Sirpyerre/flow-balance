<?php

declare(strict_types=1);

namespace FlowBalance\Finance\Infrastructure;

use PDO;

final readonly class SqlPaymentListReader
{
    public function __construct(private PDO $database) {}

    public function read(string $userId, int $limit, ?array $cursor, ?string $month = null): array
    {
        $scope = 'user_id = ? AND deleted_at IS NULL';
        $filter = $month === null ? '' : ' AND paid_on >= CAST(? AS DATE) AND paid_on < DATE_ADD(CAST(? AS DATE), INTERVAL 1 MONTH)';
        $filterValues = $month === null ? [] : [$month . '-01', $month . '-01'];
        $before = $cursor === null ? '' : ' AND (paid_on, id) < (CAST(? AS DATE), CAST(? AS SIGNED))';
        $query = $this->database->prepare("SELECT CAST(id AS CHAR) AS id, paid_on AS paidOn, currency,
            CAST(ROUND(client_paid_amount, 2) AS CHAR) AS clientPaidAmount
            FROM payments WHERE {$scope}{$filter}{$before}
            ORDER BY paid_on DESC, id DESC LIMIT ?");
        $query->execute([$userId, ...$filterValues, ...($cursor ?? []), $limit + 1]);
        $payments = $query->fetchAll(PDO::FETCH_ASSOC);
        $nextCursor = null;
        if (count($payments) > $limit) {
            array_pop($payments);
            $last = $payments[$limit - 1];
            $nextCursor = $last['paidOn'] . '~' . $last['id'];
        }
        $totals = $this->database->prepare("SELECT currency, COUNT(*) AS count,
            CAST(SUM(ROUND(client_paid_amount, 2)) AS CHAR) AS amount, MAX(paid_on) AS latest
            FROM payments WHERE {$scope}{$filter} GROUP BY currency ORDER BY currency");
        $totals->execute([$userId, ...$filterValues]);
        $groups = $totals->fetchAll(PDO::FETCH_ASSOC);
        $months = $this->database->prepare("SELECT DISTINCT DATE_FORMAT(paid_on, '%Y-%m') AS month
            FROM payments WHERE {$scope} ORDER BY month DESC LIMIT 3");
        $months->execute([$userId]);
        $summary = [
            'paymentCount' => array_sum(array_column($groups, 'count')),
            'latestPaidOn' => $groups === [] ? null : max(array_column($groups, 'latest')),
            'totalsByCurrency' => (object) array_column($groups, 'amount', 'currency'),
        ];
        return ['summary' => $summary, 'recentMonths' => $months->fetchAll(PDO::FETCH_COLUMN), 'payments' => $payments, 'nextCursor' => $nextCursor];
    }
}
