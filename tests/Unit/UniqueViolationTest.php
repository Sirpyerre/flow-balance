<?php

declare(strict_types=1);

use FlowBalance\Shared\Database\UniqueViolation;
use PHPUnit\Framework\TestCase;

final class UniqueViolationTest extends TestCase
{
    private function error(int $driverCode, string $message): PDOException
    {
        $error = new PDOException($message);
        $error->errorInfo = ['23000', $driverCode, $message];
        return $error;
    }

    public function testRecognizesADuplicateOnTheNamedKey(): void
    {
        $error = $this->error(1062, "Duplicate entry 'ana@example.com' for key 'users_email_normalized_unique'");

        self::assertTrue(UniqueViolation::of($error, 'users_email_normalized_unique'));
    }

    public function testIgnoresADuplicateOnAnotherKey(): void
    {
        $error = $this->error(1062, "Duplicate entry '7-key' for key 'user_id'");

        self::assertFalse(UniqueViolation::of($error, 'users_email_normalized_unique'));
    }

    public function testIgnoresOtherIntegrityErrors(): void
    {
        $check = $this->error(4025, 'CONSTRAINT `users_email_normalized_unique` failed for `flow_balance`.`users`');
        $foreign = $this->error(1451, 'Cannot delete or update a parent row: a foreign key constraint fails (`users_email_normalized_unique`)');

        self::assertFalse(UniqueViolation::of($check, 'users_email_normalized_unique'));
        self::assertFalse(UniqueViolation::of($foreign, 'users_email_normalized_unique'));
    }

    public function testAnErrorWithoutDriverInformationIsNotADuplicate(): void
    {
        self::assertFalse(UniqueViolation::of(new PDOException('boom'), 'users_email_normalized_unique'));
    }
}
