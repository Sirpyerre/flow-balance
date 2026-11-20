<?php

declare(strict_types=1);

namespace FlowBalance\Identity\Application;

use FlowBalance\Identity\Domain\EmailAddress;
use FlowBalance\Identity\Domain\RegistrationInput;
use FlowBalance\Identity\Infrastructure\NativePasswordHasher;
use FlowBalance\Shared\Database\UnitOfWork;
use FlowBalance\Shared\Database\UniqueViolation;
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
                    'INSERT INTO users (name, email, email_normalized, password_hash) VALUES (?, ?, ?, ?)'
                );
                $insert->execute([$input->name, $input->email, EmailAddress::normalize($input->email), $hash]);
                return ['id' => $this->database->lastInsertId(), 'name' => $input->name, 'email' => $input->email];
            });
        } catch (PDOException $error) {
            if (UniqueViolation::of($error, 'users_email_normalized_unique')) {
                return null;
            }
            throw $error;
        }
    }
}
