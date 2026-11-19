<?php

declare(strict_types=1);

namespace FlowBalance\Finance\Infrastructure;

use PDO;

final readonly class PostgresPaymentListReader
{
    public function __construct(private PDO $database) {}

    public function read(string $userId, int $limit, ?array $cursor): array
    {
        $before = $cursor === null ? '' : ' AND (paid_on, id) < (CAST(? AS date), CAST(? AS bigint))';
        $query = $this->database->prepare("SELECT id::text, paid_on AS \"paidOn\", currency,
            round(client_paid_amount, 2)::text AS \"clientPaidAmount\"
            FROM payments WHERE user_id = ? AND deleted_at IS NULL{$before}
            ORDER BY paid_on DESC, id DESC LIMIT ?");
        $query->execute([$userId, ...($cursor ?? []), $limit + 1]);
        $payments = $query->fetchAll(PDO::FETCH_ASSOC);
        $nextCursor = null;
        if (count($payments) > $limit) {
            array_pop($payments);
            $last = $payments[$limit - 1];
            $nextCursor = $last['paidOn'] . '~' . $last['id'];
        }
        return ['payments' => $payments, 'nextCursor' => $nextCursor];
    }
}
