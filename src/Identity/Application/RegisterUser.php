<?php

declare(strict_types=1);

namespace FlowBalance\Identity\Application;

use FlowBalance\Identity\Domain\RegistrationInput;
use FlowBalance\Identity\Infrastructure\NativePasswordHasher;
use FlowBalance\Shared\Database\UnitOfWork;
use PDO;
use PDOException;

final readonly class RegisterUser
{
    public function __construct(private PDO $database, private UnitOfWork $work, private NativePasswordHasher $hasher) {}

    public function register(RegistrationInput $input): ?array
    {
        $hash = $this->hasher->hash($input->password);
        try {
            return $this->work->savepoint(function () use ($input, $hash): array {
                $insert = $this->database->prepare(
                    'INSERT INTO users (name, email, password_hash) VALUES (?, ?, ?) RETURNING id::text, name, email'
                );
                $insert->execute([$input->name, $input->email, $hash]);
                return $insert->fetch(PDO::FETCH_ASSOC);
            });
        } catch (PDOException $error) {
            if ($error->getCode() === '23505' && str_contains($error->errorInfo[2] ?? '', '"users_email_normalized_unique"')) {
                return null;
            }
            throw $error;
        }
    }
}
