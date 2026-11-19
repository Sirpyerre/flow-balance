<?php

declare(strict_types=1);

namespace FlowBalance\Finance\Infrastructure;

use FlowBalance\Finance\Application\PaymentAmounts;
use PDO;

final readonly class PostgresOwnedResourceRepository
{
    public function __construct(private PDO $database) {}

    public function find(string $type, string $userId, string $id, bool $lock = false): ?array
    {
        $projection = $this->projection($type);
        $query = $this->database->prepare(
            "SELECT {$projection} FROM {$type} WHERE user_id = ? AND id = ?" . ($lock ? ' FOR UPDATE' : '')
        );
        $query->execute([$userId, $id]);
        return $query->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function detail(string $type, string $userId, string $id): ?array
    {
        $resource = $this->find($type, $userId, $id);
        if ($resource === null || $type === 'clients') {
            return $resource;
        }
        if ($type === 'projects') {
            $query = $this->database->prepare("SELECT COUNT(*) AS count, round(COALESCE(SUM(client_paid_amount), 0), 2)::text AS total,
                COALESCE(json_agg(json_build_object('id', id::text, 'paidOn', paid_on, 'currency', currency,
                    'clientPaidAmount', round(client_paid_amount, 2)::text, 'sourceType', source_type, 'sourceName', source_name)
                    ORDER BY paid_on DESC, id DESC), '[]') AS payments
                FROM payments WHERE user_id = ? AND project_id = ? AND currency = 'MXN'");
            $query->execute([$userId, $id]);
            $summary = $query->fetch(PDO::FETCH_ASSOC);
            $resource['client'] = $this->find('clients', $userId, $resource['clientId']);
            $resource['paymentCount'] = (int) $summary['count'];
            $resource['clientPaidTotal'] = $summary['total'];
            $resource['payments'] = json_decode($summary['payments'], true, flags: JSON_THROW_ON_ERROR);
            return $resource;
        }
        $project = $this->find('projects', $userId, $resource['projectId']);
        $resource['project'] = $project;
        $resource['client'] = $this->find('clients', $userId, $project['clientId']);
        $amounts = new PaymentAmounts($resource['clientPaidAmount'], $resource['platformFee'], $resource['withheldTaxAmount']);
        $query = $this->database->prepare('SELECT id::text, charge_type AS "chargeType", paid_on AS "paidOn", amount::text, currency
            FROM payment_charges WHERE user_id = ? AND payment_id = ? ORDER BY paid_on, id');
        $query->execute([$userId, $id]);
        return [...$resource, ...get_object_vars($amounts), 'clientPaidAmount' => $resource['sourceType'] === null
            ? $resource['clientPaidAmount'] . '0000' : $amounts->clientPaidAmount, 'charges' => $query->fetchAll(PDO::FETCH_ASSOC)];
    }

    public function hasPayments(string $userId, string $projectId): bool
    {
        $query = $this->database->prepare('SELECT 1 FROM payments WHERE user_id = ? AND project_id = ? LIMIT 1');
        $query->execute([$userId, $projectId]);
        return (bool) $query->fetchColumn();
    }

    public function update(string $type, string $userId, string $id, array $changes): array
    {
        $projection = $this->projection($type);
        $assignments = [];
        foreach (array_keys($changes) as $field) {
            $assignments[] = match ($field) {
                'name' => 'name = ?',
                'clientId' => 'client_id = ?',
                'clientPaidAmount' => 'client_paid_amount = ?',
            };
        }
        $set = implode(', ', $assignments);
        $query = $this->database->prepare(
            "UPDATE {$type} SET {$set}, updated_at = CURRENT_TIMESTAMP
             WHERE user_id = ? AND id = ? RETURNING {$projection}"
        );
        $query->execute([...array_values($changes), $userId, $id]);
        return $query->fetch(PDO::FETCH_ASSOC);
    }

    private function projection(string $type): string
    {
        return match ($type) {
            'clients' => 'id::text, name',
            'projects' => 'id::text, name, client_id::text AS "clientId", round(agreed_amount, 2)::text AS "agreedAmount", agreed_currency AS "agreedCurrency", agreed_payment_count AS "agreedPaymentCount", acquisition_source AS "acquisitionSource"',
            'payments' => 'id::text, project_id::text AS "projectId", round(client_paid_amount, 2)::text AS "clientPaidAmount", currency, paid_on AS "paidOn", round(platform_fee, 2)::text AS "platformFee", round(withheld_tax_amount, 2)::text AS "withheldTaxAmount", source_type AS "sourceType", source_name AS "sourceName", excess_reason AS "excessReason"',
        };
    }
}
