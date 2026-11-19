<?php

declare(strict_types=1);

namespace FlowBalance\Finance\Application;

use InvalidArgumentException;
use PDO;

final readonly class CreateProject
{
    public function __construct(private PDO $database) {}

    public function create(string $userId, string $clientId, string $name, ?array $agreement = null): string
    {
        $input = (new OwnedResourceChanges())->validate('projects', ['name' => $name, 'clientId' => $clientId]);
        $query = $this->database->prepare('INSERT INTO projects
            (user_id, client_id, name, agreed_amount, agreed_currency, agreed_payment_count, acquisition_source)
            SELECT user_id, id, ?, CAST(? AS numeric), ?, CAST(? AS bigint), ?
            FROM clients WHERE user_id = ? AND id = ? RETURNING id::text');
        $query->execute([$input['name'], ...($agreement ?? [null, null, null, null]), $userId, $clientId]);
        return $query->fetchColumn() ?: throw new InvalidArgumentException('El cliente seleccionado no está disponible');
    }
}
