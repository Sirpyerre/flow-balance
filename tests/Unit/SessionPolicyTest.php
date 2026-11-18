<?php

declare(strict_types=1);

use FlowBalance\Identity\Domain\SessionPolicy;
use PHPUnit\Framework\TestCase;

final class SessionPolicyTest extends TestCase
{
    public function testIdleLimitIsExclusive(): void
    {
        $policy = new SessionPolicy();
        self::assertTrue($policy->isActive(1000, 1000, 87399));
        self::assertFalse($policy->isActive(1000, 1000, 87400));
    }

    public function testRecentActivityDoesNotExtendAbsoluteLimit(): void
    {
        $policy = new SessionPolicy();
        $deadline = 1000 + 7 * 86400;
        self::assertTrue($policy->isActive(1000, $deadline - 10, $deadline - 1));
        self::assertFalse($policy->isActive(1000, $deadline - 10, $deadline));
    }
}
