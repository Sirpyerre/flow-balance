<?php

declare(strict_types=1);

use FlowBalance\Shared\Configuration\InvalidConfiguration;
use FlowBalance\Shared\Database\DatabaseConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DatabaseConfigTest extends TestCase
{
    private const PRODUCTION = [
        'APP_ENV' => 'production', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => '3306',
        'DB_DATABASE' => 'flow_balance', 'DB_USERNAME' => 'flow_balance_app', 'DB_PASSWORD' => 'a-secret-value',
    ];

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

    public function testMigrationTestingUsesItsOwnDedicatedDatabaseAndRole(): void
    {
        $values = ['APP_ENV' => 'migration-testing', 'MIGRATION_TEST_DB_HOST' => '127.0.0.1', 'MIGRATION_TEST_DB_PORT' => '3306',
            'MIGRATION_TEST_DB_DATABASE' => 'flow_balance_migration_test', 'MIGRATION_TEST_DB_USERNAME' => 'flow_balance_migration_test_app',
            'MIGRATION_TEST_DB_PASSWORD' => 'x'];

        self::assertSame('flow_balance_migration_test', DatabaseConfig::fromEnvironment($values)->database);
        foreach (['MIGRATION_TEST_DB_DATABASE' => 'flow_balance_test', 'MIGRATION_TEST_DB_USERNAME' => 'flow_balance_test_app'] as $key => $other) {
            try {
                DatabaseConfig::fromEnvironment([...$values, $key => $other]);
                self::fail("{$key}={$other} must be refused");
            } catch (InvalidConfiguration) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testMigrationTestingNeverFallsBackToTheOtherSettings(): void
    {
        $this->expectException(InvalidConfiguration::class);
        DatabaseConfig::fromEnvironment([...self::PRODUCTION, 'APP_ENV' => 'migration-testing']);
    }

    public function testProductionIsAcceptedAndUsesTheDevelopmentStyleSettings(): void
    {
        $config = DatabaseConfig::fromEnvironment(self::PRODUCTION);

        self::assertSame('production', $config->environment);
        self::assertSame('flow_balance', $config->database);
    }

    public function testProductionNeverFallsBackToTheTestSettings(): void
    {
        $this->expectException(InvalidConfiguration::class);
        DatabaseConfig::fromEnvironment(['APP_ENV' => 'production', 'TEST_DB_HOST' => '127.0.0.1', 'TEST_DB_PORT' => '3306',
            'TEST_DB_DATABASE' => 'flow_balance_test', 'TEST_DB_USERNAME' => 'flow_balance_test_app', 'TEST_DB_PASSWORD' => 'x']);
    }

    public function testAnUnknownEnvironmentIsRefused(): void
    {
        $this->expectException(InvalidConfiguration::class);
        DatabaseConfig::fromEnvironment([...self::PRODUCTION, 'APP_ENV' => 'staging']);
    }

    public function testAMissingSettingIsNamedButItsValueIsNeverRevealed(): void
    {
        $values = self::PRODUCTION;
        unset($values['DB_HOST']);
        try {
            DatabaseConfig::fromEnvironment($values);
            self::fail('A missing host must be refused');
        } catch (InvalidConfiguration $error) {
            self::assertStringContainsString('DB_HOST', $error->getMessage());
            self::assertStringNotContainsString('a-secret-value', $error->getMessage());
        }
    }

    #[DataProvider('unusableEndpoints')]
    public function testRefusesAnEndpointThatCouldInjectDsnOptions(string $key, string $value): void
    {
        $this->expectException(InvalidConfiguration::class);
        DatabaseConfig::fromEnvironment([...self::PRODUCTION, $key => $value]);
    }

    public static function unusableEndpoints(): iterable
    {
        yield 'port zero' => ['DB_PORT', '0'];
        yield 'port beyond range' => ['DB_PORT', '65536'];
        yield 'port that is not a number' => ['DB_PORT', '3306x'];
        yield 'host with an extra DSN option' => ['DB_HOST', '127.0.0.1;charset=latin1'];
        yield 'database with a space' => ['DB_DATABASE', 'flow balance'];
    }
}
