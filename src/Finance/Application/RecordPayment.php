<?php

declare(strict_types=1);

namespace FlowBalance\Finance\Application;

use DateTimeImmutable;
use DomainException;
use InvalidArgumentException;
use PDO;

final readonly class RecordPayment
{
    public function __construct(private PDO $database) {}

    public function record(string $userId, string $projectId, string $amount, string $currency, string $paidOn, string $key): string
    {
        $decimal = (new ClientPaidAmount($amount))->value;
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $paidOn);
        if (!$date || $date->format('Y-m-d') !== $paidOn || $currency !== 'MXN'
            || !OwnedResourceChanges::identifier($projectId) || $key === '' || strlen($key) > 255) {
            throw new InvalidArgumentException('Revisa los datos del cobro');
        }
        $fingerprint = hash('sha256', json_encode([$projectId, $decimal, $currency, $paidOn], JSON_THROW_ON_ERROR));
        // The target column is qualified: the SELECT reads projects, which also has id and user_id. A repeated key
        // changes nothing, so the affected-row count tells an insert (1) from a replay (0).
        $query = $this->database->prepare('INSERT INTO payments
            (user_id, project_id, client_paid_amount, currency, paid_on, idempotency_key, request_fingerprint)
            SELECT user_id, id, ?, ?, ?, ?, ?
            FROM projects WHERE user_id = ? AND id = ?
            ON DUPLICATE KEY UPDATE payments.idempotency_key = payments.idempotency_key');
        $query->execute([$decimal, $currency, $paidOn, $key, $fingerprint, $userId, $projectId]);
        if ($query->rowCount() === 1) {
            return $this->database->lastInsertId();
        }
        $query = $this->database->prepare('SELECT CAST(id AS CHAR) AS id, request_fingerprint, deleted_at FROM payments WHERE user_id = ? AND idempotency_key = ?');
        $query->execute([$userId, $key]);
        $existing = $query->fetch(PDO::FETCH_ASSOC);
        if (!$existing) {
            throw new InvalidArgumentException('El proyecto seleccionado no está disponible');
        }
        if (!hash_equals($existing['request_fingerprint'], $fingerprint)) {
            throw new DomainException('La clave ya se usó para otro cobro');
        }
        if ($existing['deleted_at'] !== null) {
            throw new PaymentDeleted();
        }
        return $existing['id'];
    }
    public function capture(string $userId, string $projectId, string $paidOn, string $key, PaymentAmounts $amounts,
        string $sourceType, ?string $sourceName, ?string $reason, string $currency = 'MXN', ?string $rate = null): array
    {
        // A key is account-wide, including when concurrent requests target different projects, so the account's
        // row is the mutex. It is taken before the project row everywhere, which keeps the lock order acyclic.
        $this->database->prepare('SELECT id FROM users WHERE id = ? FOR UPDATE')->execute([$userId]);
        $excess = new PaymentExcess($this->database);
        $project = $excess->project($userId, $projectId, true);
        if ($project === null) {
            throw new IncomeNotFound();
        }
        $input = [
            $projectId, $paidOn, $amounts->clientPaidAmount, $amounts->platformFee,
            $amounts->withheldTaxAmount, $sourceType, $sourceName, $reason,
        ];
        if ($currency !== 'MXN') {
            array_push($input, $currency, $rate);
        }
        $fingerprint = hash('sha256', json_encode($input, JSON_THROW_ON_ERROR));
        $existing = $this->database->prepare('SELECT CAST(id AS CHAR) AS id, request_fingerprint, deleted_at FROM payments WHERE user_id = ? AND idempotency_key = ? FOR UPDATE');
        $existing->execute([$userId, $key]);
        if ($row = $existing->fetch(PDO::FETCH_ASSOC)) {
            if (!hash_equals($row['request_fingerprint'], $fingerprint)) {
                throw new DomainException('La clave ya se usó para otro cobro.');
            }
            if ($row['deleted_at'] !== null) {
                throw new PaymentDeleted();
            }
            return ['id' => $row['id'], 'created' => false];
        }
        $projection = $excess->calculate($userId, $projectId, $project['agreed_amount'],
            (new PaymentConversion($this->database))->equivalents($amounts, $currency, $rate)['clientPaidAmount']);
        if ($projection['requiresExcessReason'] && $reason === null) {
            throw new IncomeValidation('excessReason', 'Indica por qué el total supera el monto acordado.', $projection);
        }
        $insert = $this->database->prepare("INSERT INTO payments (user_id, project_id, client_paid_amount, currency,
            paid_on, idempotency_key, request_fingerprint, platform_fee, withheld_tax_amount, source_type, source_name, excess_reason, exchange_rate)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE idempotency_key = idempotency_key");
        $insert->execute([$userId, $projectId, $amounts->clientPaidAmount, $currency, $paidOn, $key, $fingerprint,
            $amounts->platformFee, $amounts->withheldTaxAmount, $sourceType, $sourceName, $reason, $rate]);
        if ($insert->rowCount() !== 1) {
            throw new DomainException('La clave ya se usó para otro cobro.');
        }

        return ['id' => $this->database->lastInsertId(), 'created' => true, ...$projection];
    }
}
