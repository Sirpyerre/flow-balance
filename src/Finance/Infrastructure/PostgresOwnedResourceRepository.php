<?php

declare(strict_types=1);

namespace FlowBalance\Finance\Infrastructure;

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
            'projects' => 'id::text, name, client_id::text AS "clientId"',
            'payments' => 'id::text, project_id::text AS "projectId", client_paid_amount::text AS "clientPaidAmount", currency, paid_on AS "paidOn"',
        };
    }
}
