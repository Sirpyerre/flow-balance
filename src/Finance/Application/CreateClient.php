<?php

declare(strict_types=1);

namespace FlowBalance\Finance\Application;

use PDO;

final readonly class CreateClient
{
    public function __construct(private PDO $database) {}

    public function create(string $userId, string $name): string
    {
        $input = (new OwnedResourceChanges())->validate('clients', ['name' => $name]);
        $query = $this->database->prepare('INSERT INTO clients (user_id, name) VALUES (?, ?) RETURNING id::text');
        $query->execute([$userId, $input['name']]);
        return $query->fetchColumn();
    }
}
