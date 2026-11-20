<?php

declare(strict_types=1);

namespace FlowBalance\Finance\Application;

use DomainException;
use PDO;

final readonly class RecordPaymentCharge
{
    public function __construct(private PDO $database) {}

    public function record(string $userId, string $paymentId, string $key, string $type, string $date, string $amount): array
    {
        $owner = $this->database->prepare('SELECT 1 FROM payments WHERE user_id = ? AND id = ? AND deleted_at IS NULL FOR UPDATE');
        $owner->execute([$userId, $paymentId]);
        if (!$owner->fetchColumn()) {
            throw new IncomeNotFound();
        }
        $fingerprint = hash('sha256', json_encode([$paymentId, $type, $date, $amount], JSON_THROW_ON_ERROR));
        $projection = 'CAST(id AS CHAR) AS id, charge_type AS chargeType, paid_on AS paidOn, CAST(ROUND(amount, 2) AS CHAR) AS amount, currency';
        // A repeated key changes nothing, so the affected-row count tells an insert (1) from a replay (0).
        $query = $this->database->prepare("INSERT INTO payment_charges
            (user_id, payment_id, idempotency_key, request_fingerprint, charge_type, paid_on, amount, currency)
            VALUES (?, ?, ?, ?, ?, ?, ?, 'MXN') ON DUPLICATE KEY UPDATE idempotency_key = idempotency_key");
        $query->execute([$userId, $paymentId, $key, $fingerprint, $type, $date, $amount]);
        if ($query->rowCount() === 1) {
            $created = $this->database->prepare("SELECT {$projection} FROM payment_charges WHERE id = ?");
            $created->execute([$this->database->lastInsertId()]);
            $version = $this->database->prepare('UPDATE payments SET version = version + 1 WHERE user_id = ? AND id = ?');
            $version->execute([$userId, $paymentId]);
            return [$created->fetch(PDO::FETCH_ASSOC), true];
        }
        $query = $this->database->prepare("SELECT {$projection}, request_fingerprint FROM payment_charges WHERE user_id = ? AND idempotency_key = ?");
        $query->execute([$userId, $key]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        if (!$row || !hash_equals($row['request_fingerprint'], $fingerprint)) {
            throw new DomainException('La clave ya se usó para otro cargo.');
        }
        unset($row['request_fingerprint']);
        return [$row, false];
    }
}
