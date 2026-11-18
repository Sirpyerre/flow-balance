<?php

declare(strict_types=1);

namespace FlowBalance\Shared\Database;

use PDO;
use Throwable;

final readonly class UnitOfWork
{
    public function __construct(private PDO $connection) {}

    public function transaction(callable $operation): mixed
    {
        $this->connection->beginTransaction();
        try {
            $result = $operation();
            $this->connection->commit();
            return $result;
        } catch (Throwable $error) {
            if ($this->connection->inTransaction()) {
                $this->connection->rollBack();
            }
            throw $error;
        }
    }

    public function savepoint(callable $operation): mixed
    {
        $this->connection->exec('SAVEPOINT application_operation');
        try {
            return $operation();
        } catch (Throwable $error) {
            $this->connection->exec('ROLLBACK TO SAVEPOINT application_operation');
            throw $error;
        } finally {
            $this->connection->exec('RELEASE SAVEPOINT application_operation');
        }
    }
}
