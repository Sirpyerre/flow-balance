<?php

declare(strict_types=1);

namespace FlowBalance\Finance\Infrastructure;

use FlowBalance\Finance\Application\DecimalText;
use FlowBalance\Finance\Application\IncomeNotFound;
use FlowBalance\Finance\Application\PaymentAmounts;
use FlowBalance\Finance\Application\PaymentConversion;
use PDO;

final readonly class SqlPaymentCorrectionRepository
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
        $query = $this->database->prepare('SELECT CAST(ROUND(agreed_amount, 2) AS CHAR) FROM projects WHERE user_id = ? AND id = ? FOR UPDATE');
        $query->execute([$userId, $projectId]);
        $agreement = $query->fetchColumn();
        // Every column is listed so that version stays text (it is compared as text) and the amounts keep a fixed scale.
        $query = $this->database->prepare('SELECT id, user_id, project_id, CAST(client_paid_amount AS CHAR) AS client_paid_amount,
            currency, paid_on, idempotency_key, request_fingerprint, created_at, updated_at,
            CAST(ROUND(platform_fee, 2) AS CHAR) AS platform_fee, CAST(ROUND(withheld_tax_amount, 2) AS CHAR) AS withheld_tax_amount,
            source_type, source_name, excess_reason, CAST(version AS CHAR) AS version, deleted_at,
            CAST(exchange_rate AS CHAR) AS exchange_rate
            FROM payments WHERE user_id = ? AND id = ? FOR UPDATE');
        $query->execute([$userId, $id]);
        $payment = $query->fetch(PDO::FETCH_ASSOC);
        if (!$payment || $agreement === false) {
            throw new IncomeNotFound();
        }
        $payment['exchange_rate'] = DecimalText::trimmed($payment['exchange_rate']);
        $payment['agreed_amount'] = $agreement;
        return $payment;
    }

    public function totals(array $payment, PaymentAmounts $amounts, string $currency, ?string $rate): array
    {
        $amount = PaymentConversion::paymentAmount();
        $replacement = (new PaymentConversion($this->database))->equivalents($amounts, $currency, $rate)['clientPaidAmount'];
        // The replacement is already rounded to cents, so DECIMAL(22,2) loses nothing and keeps the totals at two decimals.
        $query = $this->database->prepare("SELECT CAST(COALESCE(SUM({$amount}), 0.00) AS CHAR) AS previous,
            CAST(COALESCE(SUM(CASE WHEN id <> ? THEN {$amount} END), 0.00) + CAST(? AS DECIMAL(22,2)) AS CHAR) AS projected,
            COALESCE(SUM(id <> ? AND ({$amount}) IS NULL), 0) > 0 AS provisional
            FROM payments WHERE user_id = ? AND project_id = ? AND deleted_at IS NULL");
        $query->execute([$payment['id'], $replacement, $payment['id'], $payment['user_id'], $payment['project_id']]);
        return $query->fetch(PDO::FETCH_ASSOC);
    }

    public function attempt(array $payment, string $key): ?array
    {
        $query = $this->database->prepare('SELECT fingerprint, CAST(result_version AS CHAR) AS result_version, previous_values FROM payment_revisions
            WHERE user_id = ? AND payment_id = ? AND request_key = ?');
        $query->execute([$payment['user_id'], $payment['id'], $key]);
        return $query->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function change(array $payment, string $action, array $fields, ?string $reason = null, ?string $key = null, ?string $fingerprint = null): void
    {
        $previous = array_intersect_key($payment, array_flip([
            'client_paid_amount', 'paid_on', 'source_type', 'source_name', 'platform_fee', 'withheld_tax_amount', 'excess_reason', 'currency', 'exchange_rate',
        ]));
        $audit = $this->database->prepare('INSERT INTO payment_revisions
            (user_id, payment_id, actor_id, previous_version, result_version, action, previous_values, reason, request_key, fingerprint)
            VALUES (?, ?, ?, ?, CAST(? AS SIGNED) + 1, ?, ?, ?, ?, ?)');
        $audit->execute([$payment['user_id'], $payment['id'], $payment['user_id'], $payment['version'], $payment['version'],
            $action, json_encode($previous, JSON_THROW_ON_ERROR), $reason, $key, $fingerprint]);

        $set = $action === 'delete' ? 'deleted_at = CURRENT_TIMESTAMP(6)' : implode(', ', array_map(
            static fn (string $field): string => $field . ' = ?', array_keys($fields),
        ));
        $query = $this->database->prepare("UPDATE payments SET {$set}, version = version + 1, updated_at = CURRENT_TIMESTAMP(6)
            WHERE user_id = ? AND id = ?");
        $query->execute([...array_values($fields), $payment['user_id'], $payment['id']]);
    }
}
