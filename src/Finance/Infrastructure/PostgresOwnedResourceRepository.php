<?php

declare(strict_types=1);

namespace FlowBalance\Finance\Infrastructure;

use FlowBalance\Finance\Application\PaymentAmounts;
use FlowBalance\Finance\Application\ProjectBalance;
use PDO;

final readonly class PostgresOwnedResourceRepository
{
    public const BALANCE_PAYMENT_AMOUNT = 'round(client_paid_amount, 2)';

    public function __construct(private PDO $database) {}

    public function find(string $type, string $userId, string $id, bool $lock = false): ?array
    {
        $projection = $this->projection($type);
        $active = $type === 'payments' ? ' AND deleted_at IS NULL' : '';
        $query = $this->database->prepare(
            "SELECT {$projection} FROM {$type} WHERE user_id = ? AND id = ?{$active}" . ($lock ? ' FOR UPDATE' : '')
        );
        $query->execute([$userId, $id]);
        return $query->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function detail(string $type, string $userId, string $id): ?array
    {
        if ($type === 'projects') {
            return $this->projectDetail($userId, $id);
        }
        $resource = $this->find($type, $userId, $id);
        if ($resource === null || $type === 'clients') {
            return $resource;
        }
        $project = $this->find('projects', $userId, $resource['projectId']);
        $resource['project'] = $project;
        $resource['client'] = $this->find('clients', $userId, $project['clientId']);
        $amounts = new PaymentAmounts($resource['clientPaidAmount'], $resource['platformFee'], $resource['withheldTaxAmount'], allowZeroAmount: true);
        $query = $this->database->prepare('SELECT id::text, charge_type AS "chargeType", paid_on AS "paidOn", amount::text, currency
            FROM payment_charges WHERE user_id = ? AND payment_id = ? ORDER BY paid_on, id');
        $query->execute([$userId, $id]);
        return [...$resource, ...get_object_vars($amounts), 'clientPaidAmount' => $resource['sourceType'] === null
            ? $resource['clientPaidAmount'] . '0000' : $amounts->clientPaidAmount, 'charges' => $query->fetchAll(PDO::FETCH_ASSOC)];
    }

    private function projectDetail(string $userId, string $id): ?array
    {
        $amount = self::BALANCE_PAYMENT_AMOUNT;
        $query = $this->database->prepare(<<<SQL
            SELECT p.id::text, p.name, p.client_id::text AS "clientId",
                   round(p.agreed_amount, 2)::text AS "agreedAmount",
                   p.agreed_currency AS "agreedCurrency", p.agreed_payment_count AS "agreedPaymentCount",
                   p.acquisition_source AS "acquisitionSource", c.name AS client_name,
                   summary.payment_count AS "paymentCount", summary.total AS "clientPaidTotal", summary.payments
            FROM projects p
            JOIN clients c ON c.user_id = p.user_id AND c.id = p.client_id
            CROSS JOIN LATERAL (
                SELECT COUNT(*) AS payment_count,
                       COALESCE(SUM(amount) FILTER (WHERE currency = 'MXN'), 0.00)::text AS total,
                       COALESCE(json_agg(json_build_object('id', id::text, 'paidOn', paid_on,
                           'currency', currency, 'clientPaidAmount', amount::text,
                           'sourceType', source_type, 'sourceName', source_name)
                           ORDER BY paid_on DESC, id DESC) FILTER (WHERE currency = 'MXN'), '[]') AS payments
                FROM (
                    SELECT id, paid_on, currency, source_type, source_name, {$amount} AS amount
                    FROM payments WHERE user_id = p.user_id AND project_id = p.id AND deleted_at IS NULL
                ) own_payments
            ) summary
            WHERE p.user_id = ? AND p.id = ?
            SQL);
        $query->execute([$userId, $id]);
        $project = $query->fetch(PDO::FETCH_ASSOC);
        if (!$project) {
            return null;
        }
        $project['client'] = ['id' => $project['clientId'], 'name' => $project['client_name']];
        unset($project['client_name']);
        $project['paymentCount'] = (int) $project['paymentCount'];
        $project['payments'] = json_decode($project['payments'], true, flags: JSON_THROW_ON_ERROR);
        return [...$project, ...ProjectBalance::calculate($project['agreedAmount'], $project['clientPaidTotal'])];
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
            'payments' => 'id::text, version::text, project_id::text AS "projectId", round(client_paid_amount, 2)::text AS "clientPaidAmount", currency, paid_on AS "paidOn", round(platform_fee, 2)::text AS "platformFee", round(withheld_tax_amount, 2)::text AS "withheldTaxAmount", source_type AS "sourceType", source_name AS "sourceName", excess_reason AS "excessReason"',
        };
    }
}
