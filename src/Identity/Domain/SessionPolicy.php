<?php

declare(strict_types=1);

namespace FlowBalance\Identity\Domain;

final class SessionPolicy
{
    public const IDLE_SECONDS = 86400;
    public const ABSOLUTE_SECONDS = 7 * self::IDLE_SECONDS;
    public const ANONYMOUS_SECONDS = 900;

    public function isActive(int $authenticatedAt, int $lastActivityAt, int $now): bool
    {
        return $now < $this->idleDeadline($authenticatedAt, $lastActivityAt);
    }

    public function idleDeadline(int $authenticatedAt, int $lastActivityAt): int
    {
        return min($authenticatedAt + self::ABSOLUTE_SECONDS, $lastActivityAt + self::IDLE_SECONDS);
    }
}
