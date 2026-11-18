<?php

declare(strict_types=1);

namespace FlowBalance\Identity\Infrastructure;

use RuntimeException;

final class NativePasswordHasher
{
    public function __construct()
    {
        if (!in_array('argon2id', password_algos(), true)) {
            throw new RuntimeException('PHP must support Argon2id');
        }
    }

    public function hash(string $password): string
    {
        return password_hash($password, PASSWORD_ARGON2ID);
    }

    public function verify(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    public function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, PASSWORD_ARGON2ID);
    }
}
