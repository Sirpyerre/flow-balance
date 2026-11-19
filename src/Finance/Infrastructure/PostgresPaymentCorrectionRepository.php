<?php

declare(strict_types=1);

namespace FlowBalance\Finance\Infrastructure;

use FlowBalance\Finance\Application\IncomeNotFound;
use PDO;

final readonly class PostgresPaymentCorrectionRepository
{
    public function __construct(private PDO $database) {}

    public function lock(string $userId, string $id): array
    {
        $query = $this->database->prepare('SELECT project_id FROM payments WHERE user_id = ? AND id = ?');
        $query->execute([$userId, $id]);
        $projectId = $query->fetchColumn();
        if ($projectId === false) {
            throw new IncomeNotFound();
        }
        $query = $this->database->prepare('SELECT round(agreed_amount, 2)::text FROM projects WHERE user_id = ? AND id = ? FOR UPDATE');
        $query->execute([$userId, $projectId]);
        $agreement = $query->fetchColumn();
        $query = $this->database->prepare('SELECT *, version::text, client_paid_amount::text, platform_fee::text,
            withheld_tax_amount::text FROM payments WHERE user_id = ? AND id = ? FOR UPDATE');
        $query->execute([$userId, $id]);
        $payment = $query->fetch(PDO::FETCH_ASSOC);
        if (!$payment || $agreement === false) {
            throw new IncomeNotFound();
        }
        $payment['agreed_amount'] = $agreement;
        return $payment;
    }

    public function totals(array $payment, ?string $replacement): array
    {
        $amount = PostgresOwnedResourceRepository::BALANCE_PAYMENT_AMOUNT;
        $query = $this->database->prepare("SELECT COALESCE(SUM({$amount}), 0.00)::text AS previous,
            (COALESCE(SUM({$amount}) FILTER (WHERE id <> CAST(? AS bigint)), 0.00) + CAST(? AS numeric))::text AS projected
            FROM payments WHERE user_id = ? AND project_id = ? AND currency = 'MXN' AND deleted_at IS NULL");
        $query->execute([$payment['id'], $replacement, $payment['user_id'], $payment['project_id']]);
        return $query->fetch(PDO::FETCH_ASSOC);
    }

    public function attempt(array $payment, string $key): ?array
    {
        $query = $this->database->prepare('SELECT fingerprint, result_version::text FROM payment_revisions
            WHERE user_id = ? AND payment_id = ? AND request_key = ?');
        $query->execute([$payment['user_id'], $payment['id'], $key]);
        return $query->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function change(array $payment, string $action, array $fields, ?string $reason = null, ?string $key = null, ?string $fingerprint = null): void
    {
        $previous = array_intersect_key($payment, array_flip([
            'client_paid_amount', 'paid_on', 'source_type', 'source_name', 'platform_fee', 'withheld_tax_amount', 'excess_reason', 'currency',
        ]));
        $audit = $this->database->prepare('INSERT INTO payment_revisions
            (user_id, payment_id, actor_id, previous_version, result_version, action, previous_values, reason, request_key, fingerprint)
            VALUES (?, ?, ?, ?, CAST(? AS bigint) + 1, ?, CAST(? AS jsonb), ?, ?, ?)');
        $audit->execute([$payment['user_id'], $payment['id'], $payment['user_id'], $payment['version'], $payment['version'],
            $action, json_encode($previous, JSON_THROW_ON_ERROR), $reason, $key, $fingerprint]);

        $set = $action === 'delete' ? 'deleted_at = CURRENT_TIMESTAMP' : implode(', ', array_map(
            static fn (string $field): string => $field . ' = ?', array_keys($fields),
        ));
        $query = $this->database->prepare("UPDATE payments SET {$set}, version = version + 1, updated_at = CURRENT_TIMESTAMP
            WHERE user_id = ? AND id = ?");
        $query->execute([...array_values($fields), $payment['user_id'], $payment['id']]);
    }
}
