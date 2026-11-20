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
        // The parameters go straight into their columns: casting an amount to a narrower DECIMAL here
        // would round extra decimals before the column's own CHECK could refuse them.
        $query = $this->database->prepare('INSERT INTO projects
            (user_id, client_id, name, agreed_amount, agreed_currency, agreed_payment_count, acquisition_source)
            SELECT user_id, id, ?, ?, ?, ?, ?
            FROM clients WHERE user_id = ? AND id = ?');
        $query->execute([$input['name'], ...($agreement ?? [null, null, null, null]), $userId, $clientId]);
        if ($query->rowCount() === 0) {
            throw new InvalidArgumentException('El cliente seleccionado no está disponible');
        }
        return $this->database->lastInsertId();
    }
}
