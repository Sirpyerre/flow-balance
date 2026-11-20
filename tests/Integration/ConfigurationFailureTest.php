<?php

declare(strict_types=1);

require_once __DIR__ . '/../Support/AppClient.php';

use PHPUnit\Framework\TestCase;

/** A misconfigured site answers with a generic error, never a stack trace, a value or a session cookie. */
final class ConfigurationFailureTest extends TestCase
{
    private const SECRET = 'a-signing-secret-of-at-least-32-characters';

    private array $original = [];

    protected function setUp(): void
    {
        if (getenv('TEST_DB_PASSWORD') === false) {
            self::markTestSkipped('Configure the dedicated test database');
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->original as $name => $value) {
            putenv($value === false ? $name : "{$name}={$value}");
        }
    }

    private function set(string $name, string $value): void
    {
        $this->original[$name] ??= getenv($name);
        putenv("{$name}={$value}");
    }

    private function login(): array
    {
        // The secret is checked before the CSRF token, so no session is needed to reach it.
        return AppClient::boot()->request('POST', '/api/auth/login', '', ['email' => 'ana@example.test', 'password' => 'irrelevant']);
    }

    private function assertGenericFailure(array $response, string $code): void
    {
        [$status, $body, , , $headers] = $response;

        self::assertSame(503, $status);
        self::assertSame(['error' => ['code' => $code, 'message' => 'Intenta de nuevo más tarde']], $body);
        self::assertArrayNotHasKey('Set-Cookie', $headers, 'A failed configuration must not start a session');
    }

    public function testAShortSecretMakesLoginUnavailableInTesting(): void
    {
        $this->set('TEST_APP_SECRET', str_repeat('x', 20));

        $this->assertGenericFailure($this->login(), 'configuration_unavailable');
    }

    public function testAShortSecretMakesLoginUnavailableInProduction(): void
    {
        $this->set('APP_ENV', 'production');
        $this->set('APP_ORIGIN', 'https://flow.monoforms.test');
        $this->set('APP_SECRET', str_repeat('x', 20));

        $this->assertGenericFailure($this->login(), 'configuration_unavailable');
    }

    public function testAnAdequateSecretLetsLoginReachTheCsrfCheck(): void
    {
        // Control: with a good secret the same request is refused for the CSRF token, not for configuration.
        $this->set('TEST_APP_SECRET', self::SECRET);

        [$status, $body] = $this->login();

        self::assertSame(403, $status);
        self::assertSame('invalid_csrf', $body['error']['code']);
    }

    public function testAnInvalidProductionOriginMakesTheWholeApiUnavailable(): void
    {
        $this->set('APP_ENV', 'production');
        $this->set('APP_ORIGIN', 'http://insecure.example');
        $this->set('APP_SECRET', self::SECRET);

        $client = AppClient::boot();
        foreach (['/api/auth/csrf', '/api/auth/session', '/api/dashboard'] as $path) {
            $this->assertGenericFailure($client->request('GET', $path, ''), 'configuration_unavailable');
        }
    }

    public function testTheResponseNeverRevealsTheOffendingValue(): void
    {
        $this->set('APP_ENV', 'production');
        $this->set('APP_ORIGIN', 'http://leaked-origin.example');

        [, $body] = AppClient::boot()->request('GET', '/api/auth/csrf', '');

        self::assertStringNotContainsString('leaked-origin', json_encode($body));
        self::assertStringNotContainsString('APP_ORIGIN', json_encode($body));
    }
}
