<?php

declare(strict_types=1);

namespace FlowBalance\Shared\Database;

use PDOException;

final class UniqueViolation
{
    private const DUPLICATE_ENTRY = 1062;

    /** MariaDB reports a clash as driver code 1062 and quotes the key: "Duplicate entry '...' for key 'name'". */
    public static function of(PDOException $error, string $constraint): bool
    {
        return ($error->errorInfo[1] ?? null) === self::DUPLICATE_ENTRY
            && str_contains($error->errorInfo[2] ?? '', "{$constraint}'");
    }
}
