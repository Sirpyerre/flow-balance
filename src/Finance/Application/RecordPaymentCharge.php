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
        $owner = $this->database->prepare('SELECT 1 FROM payments WHERE user_id = ? AND id = ?');
        $owner->execute([$userId, $paymentId]);
        if (!$owner->fetchColumn()) {
            throw new IncomeNotFound();
        }
        $fingerprint = hash('sha256', json_encode([$paymentId, $type, $date, $amount], JSON_THROW_ON_ERROR));
        $projection = 'id::text, charge_type AS "chargeType", paid_on AS "paidOn", amount::text, currency';
        $query = $this->database->prepare("INSERT INTO payment_charges
            (user_id, payment_id, idempotency_key, request_fingerprint, charge_type, paid_on, amount, currency)
            VALUES (?, ?, ?, ?, ?, ?, ?, 'MXN') ON CONFLICT (user_id, idempotency_key) DO NOTHING RETURNING {$projection}");
        $query->execute([$userId, $paymentId, $key, $fingerprint, $type, $date, $amount]);
        if ($row = $query->fetch(PDO::FETCH_ASSOC)) {
            return [$row, true];
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
