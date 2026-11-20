<?php

declare(strict_types=1);

namespace FlowBalance\Identity\Domain;

final class EmailAddress
{
    /** The characters the entry points already trim: space, tab, line feed, carriage return, vertical tab, form feed. */
    private const TRIMMED = " \t\n\r\v\f";

    /** The one criterion that decides whether two addresses are the same account. */
    public static function normalize(string $email): string
    {
        return mb_strtolower(trim($email, self::TRIMMED), 'UTF-8');
    }
}
