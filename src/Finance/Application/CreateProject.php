<?php

declare(strict_types=1);

namespace FlowBalance\Finance\Application;

use InvalidArgumentException;
use PDO;

final readonly class CreateProject
{
    public function __construct(private PDO $database) {}

    public function create(string $userId, string $clientId, string $name): string
    {
        $input = (new OwnedResourceChanges())->validate('projects', ['name' => $name, 'clientId' => $clientId]);
        $query = $this->database->prepare('INSERT INTO projects (user_id, client_id, name)
            SELECT user_id, id, ? FROM clients WHERE user_id = ? AND id = ? RETURNING id::text');
        $query->execute([$input['name'], $userId, $clientId]);
        return $query->fetchColumn() ?: throw new InvalidArgumentException('El cliente seleccionado no está disponible');
    }
}
