<?php

declare(strict_types=1);

namespace FlowBalance\Finance\Application;

use PDO;

final readonly class PaymentExcess
{
    public function __construct(private PDO $database) {}

    public function project(string $userId, string $projectId, bool $lock = false): ?array
    {
        $query = $this->database->prepare('SELECT CAST(ROUND(agreed_amount, 2) AS CHAR) AS agreed_amount FROM projects WHERE user_id = ? AND id = ?'
            . ($lock ? ' FOR UPDATE' : ''));
        $query->execute([$userId, $projectId]);
        return $query->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function calculate(string $userId, string $projectId, ?string $agreedAmount, string $amount, ?string $replaceId = null): array
    {
        $converted = PaymentConversion::paymentAmount();
        // Both amounts arrive already rounded to cents, so DECIMAL(22,2) loses nothing and keeps the result at two decimals.
        $query = $this->database->prepare("WITH projected AS (
            SELECT ROUND(COALESCE(SUM({$converted}), 0) + CAST(? AS DECIMAL(22,2)), 2) AS total
            FROM payments WHERE user_id = ? AND project_id = ?
            AND deleted_at IS NULL AND NOT (id <=> CAST(? AS SIGNED))
        ) SELECT CAST(total AS CHAR) AS total, CASE WHEN CAST(? AS DECIMAL(22,2)) IS NULL THEN NULL
            ELSE CAST(GREATEST(total - CAST(? AS DECIMAL(22,2)), 0.00) AS CHAR) END AS excess FROM projected");
        $query->execute([$amount, $userId, $projectId, $replaceId, $agreedAmount, $agreedAmount]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        return [
            'projectedClientPaidTotal' => $row['total'],
            'excessAmount' => $row['excess'],
            'projectedOutstandingBalance' => ProjectBalance::calculate($agreedAmount, $row['total'])['outstandingBalance'],
            'requiresExcessReason' => $row['excess'] !== null && trim($row['excess'], '0.') !== '',
        ];
    }
}
