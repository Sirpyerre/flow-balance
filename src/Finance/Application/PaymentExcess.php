<?php
declare(strict_types=1);

namespace FlowBalance\Finance\Application;

use PDO;

final readonly class PaymentExcess
{
    public function __construct(private PDO $database) {}

    public function project(string $userId, string $projectId, bool $lock = false): ?array
    {
        $query = $this->database->prepare('SELECT agreed_amount::text FROM projects WHERE user_id = ? AND id = ?'
            . ($lock ? ' FOR UPDATE' : ''));
        $query->execute([$userId, $projectId]);
        return $query->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function calculate(string $userId, string $projectId, ?string $agreedAmount, string $amount, ?string $replaceId = null): array
    {
        $converted = PaymentConversion::PAYMENT_AMOUNT;
        $query = $this->database->prepare("WITH projected AS (
            SELECT round(COALESCE(SUM({$converted}), 0) + CAST(? AS numeric), 2) AS total
            FROM payments WHERE user_id = ? AND project_id = ?
            AND deleted_at IS NULL AND id IS DISTINCT FROM CAST(? AS bigint)
        ) SELECT total::text, CASE WHEN CAST(? AS numeric) IS NULL THEN NULL
            ELSE GREATEST(total - CAST(? AS numeric), 0.00)::text END AS excess FROM projected");
        $query->execute([$amount, $userId, $projectId, $replaceId, $agreedAmount, $agreedAmount]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        return [
            'projectedClientPaidTotal' => $row['total'],
            'excessAmount' => $row['excess'],
            'requiresExcessReason' => $row['excess'] !== null && trim($row['excess'], '0.') !== '',
        ];
    }
}
