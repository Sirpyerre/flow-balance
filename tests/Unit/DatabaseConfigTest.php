<?php

declare(strict_types=1);

use FlowBalance\Shared\Database\DatabaseConfig;
use PHPUnit\Framework\TestCase;

final class DatabaseConfigTest extends TestCase
{
    public function testTestingCannotFallBackToDevelopmentSettings(): void
    {
        $this->expectException(InvalidArgumentException::class);
        DatabaseConfig::fromEnvironment(['APP_ENV' => 'testing', 'DB_DATABASE' => 'flow_balance']);
    }

    public function testTestingRejectsDevelopmentIdentity(): void
    {
        $this->expectException(InvalidArgumentException::class);
        DatabaseConfig::fromEnvironment([
            'APP_ENV' => 'testing',
            'TEST_DB_DATABASE' => 'flow_balance',
            'TEST_DB_USERNAME' => 'flow_balance_app',
        ]);
    }
}
