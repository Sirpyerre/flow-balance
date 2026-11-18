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
        $query = $this->database->prepare('INSERT INTO payments
            (user_id, project_id, client_paid_amount, currency, paid_on, idempotency_key, request_fingerprint)
            SELECT user_id, id, CAST(? AS numeric), ?, CAST(? AS date), ?, ?
            FROM projects WHERE user_id = ? AND id = ?
            ON CONFLICT (user_id, idempotency_key) DO NOTHING RETURNING id::text');
        $query->execute([$decimal, $currency, $paidOn, $key, $fingerprint, $userId, $projectId]);
        $id = $query->fetchColumn();
        if ($id !== false) {
            return $id;
        }
        $query = $this->database->prepare('SELECT id::text, request_fingerprint FROM payments WHERE user_id = ? AND idempotency_key = ?');
        $query->execute([$userId, $key]);
        $existing = $query->fetch(PDO::FETCH_ASSOC);
        if (!$existing) {
            throw new InvalidArgumentException('El proyecto seleccionado no está disponible');
        }
        if (!hash_equals($existing['request_fingerprint'], $fingerprint)) {
            throw new DomainException('La clave ya se usó para otro cobro');
        }
        return $existing['id'];
    }
}
