<?php

declare(strict_types=1);

use FlowBalance\Shared\Configuration\AppSettings;
use FlowBalance\Shared\Configuration\InvalidConfiguration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AppSettingsTest extends TestCase
{
    private const SECRET = 'a-signing-secret-of-at-least-32-characters';
    private const PRODUCTION = ['APP_ENV' => 'production', 'APP_ORIGIN' => 'https://flow.monoforms.com', 'APP_SECRET' => self::SECRET];

    public function testProductionReadsItsOriginAndSecretFromTheEnvironment(): void
    {
        $settings = AppSettings::fromEnvironment(self::PRODUCTION);

        self::assertSame('production', $settings->environment);
        self::assertSame('https://flow.monoforms.com', $settings->origin);
        self::assertSame(self::SECRET, $settings->secret());
    }

    public function testProductionRefusesToFallBackToTheDevelopmentOrigin(): void
    {
        $values = self::PRODUCTION;
        unset($values['APP_ORIGIN']);
        try {
            AppSettings::fromEnvironment($values);
            self::fail('A production site without an origin must not start');
        } catch (InvalidConfiguration $error) {
            self::assertStringContainsString('APP_ORIGIN', $error->getMessage());
        }
    }

    #[DataProvider('unusableProductionOrigins')]
    public function testProductionRefusesAnOriginThatBrowsersCouldNeverSend(string $origin): void
    {
        try {
            AppSettings::fromEnvironment([...self::PRODUCTION, 'APP_ORIGIN' => $origin]);
            self::fail("Origin '{$origin}' must be refused");
        } catch (InvalidConfiguration $error) {
            self::assertStringNotContainsString($origin, $error->getMessage(), 'Messages name the setting, not its value');
        }
    }

    public static function unusableProductionOrigins(): iterable
    {
        yield 'plain HTTP' => ['http://flow.monoforms.com'];
        yield 'trailing slash' => ['https://flow.monoforms.com/'];
        yield 'with a path' => ['https://flow.monoforms.com/app'];
        yield 'with capitals' => ['https://Flow.Monoforms.com'];
        yield 'no scheme' => ['flow.monoforms.com'];
        yield 'no host' => ['https://'];
        yield 'port that is not a number' => ['https://flow.monoforms.com:abc'];
        yield 'with credentials' => ['https://user:pass@flow.monoforms.com'];
        yield 'with a query string' => ['https://flow.monoforms.com?x=1'];
    }

    public function testProductionAcceptsAnOriginWithAPort(): void
    {
        self::assertSame('https://localhost:8443', AppSettings::fromEnvironment([...self::PRODUCTION, 'APP_ORIGIN' => 'https://localhost:8443'])->origin);
    }

    #[DataProvider('nonProductionEnvironments')]
    public function testDevelopmentAndTestingKeepTheDevelopmentOrigin(string $environment): void
    {
        self::assertSame('https://flow-balance.test', AppSettings::fromEnvironment(['APP_ENV' => $environment])->origin);
    }

    public static function nonProductionEnvironments(): iterable
    {
        yield ['development'];
        yield ['testing'];
    }

    public function testAnUnknownEnvironmentIsRefused(): void
    {
        $this->expectException(InvalidConfiguration::class);
        AppSettings::fromEnvironment([...self::PRODUCTION, 'APP_ENV' => 'staging']);
    }

    public function testASecretShorterThan32CharactersIsNotUsable(): void
    {
        self::assertNull(AppSettings::fromEnvironment([...self::PRODUCTION, 'APP_SECRET' => str_repeat('x', 31)])->secret());
        self::assertSame(str_repeat('x', 32), AppSettings::fromEnvironment([...self::PRODUCTION, 'APP_SECRET' => str_repeat('x', 32)])->secret());
    }

    public function testAMissingSecretIsNotUsable(): void
    {
        $values = self::PRODUCTION;
        unset($values['APP_SECRET']);

        self::assertNull(AppSettings::fromEnvironment($values)->secret());
    }

    public function testTestingSignsWithItsOwnSecretAndProductionNeverUsesIt(): void
    {
        $testing = AppSettings::fromEnvironment(['APP_ENV' => 'testing', 'APP_SECRET' => self::SECRET]);
        self::assertNull($testing->secret(), 'Testing must not borrow the real secret');
        self::assertSame(self::SECRET, AppSettings::fromEnvironment(['APP_ENV' => 'testing', 'TEST_APP_SECRET' => self::SECRET])->secret());

        $production = AppSettings::fromEnvironment([...self::PRODUCTION, 'APP_SECRET' => '', 'TEST_APP_SECRET' => self::SECRET]);
        self::assertNull($production->secret(), 'Production must not borrow the test secret');
    }

    public function testTrustedProxyPeersAreListedAndTrimmed(): void
    {
        $settings = AppSettings::fromEnvironment([...self::PRODUCTION, 'TRUSTED_API_PROXY_PEERS' => '10.0.0.1, 10.0.0.2 ', 'CLIENT_IP_PROXY_TOKEN' => 'token']);

        self::assertSame(['10.0.0.1', '10.0.0.2'], $settings->trustedProxyPeers);
        self::assertSame('token', $settings->proxyToken);
    }
}
