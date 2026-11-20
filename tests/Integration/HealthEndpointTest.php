<?php

declare(strict_types=1);

require_once __DIR__ . '/../Support/AppClient.php';

use PHPUnit\Framework\TestCase;

/** What a deployment asks after activating a release: can this site serve requests? */
final class HealthEndpointTest extends TestCase
{
    private const WRITTEN_TABLES = ['server_sessions', 'login_throttles', 'users', 'clients', 'projects', 'payments'];

    private array $original = [];
    private PDO $database;

    protected function setUp(): void
    {
        if (getenv('TEST_DB_PASSWORD') === false) {
            self::markTestSkipped('Configure the dedicated test database');
        }
        $this->database = (require dirname(__DIR__, 2) . '/config/database.php')->connect();
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

    private function rowCounts(): array
    {
        return array_map(
            fn (string $table): int => (int) $this->database->query("SELECT COUNT(*) FROM {$table}")->fetchColumn(),
            array_combine(self::WRITTEN_TABLES, self::WRITTEN_TABLES),
        );
    }

    private function assertUnavailable(array $response, string $code): void
    {
        [$status, $body, , $cache, $headers] = $response;

        self::assertSame(503, $status);
        self::assertSame(['error' => ['code' => $code, 'message' => 'Intenta de nuevo más tarde']], $body, 'The answer is generic');
        self::assertStringContainsString('no-store', $cache);
        self::assertArrayNotHasKey('Set-Cookie', $headers);
    }

    public function testAnswersOkWithoutOpeningASessionOrWritingAnything(): void
    {
        $before = $this->rowCounts();

        [$status, $body, , $cache, $headers] = AppClient::boot()->request('GET', '/api/health', '');

        self::assertSame(200, $status);
        self::assertSame(['status' => 'ok'], $body);
        self::assertStringContainsString('no-store', $cache);
        self::assertArrayNotHasKey('Set-Cookie', $headers, 'A health check must not open a session');
        self::assertSame($before, $this->rowCounts(), 'A health check must not write rows');
    }

    public function testNeedsNoSessionNoCsrfTokenAndIgnoresAStaleCookie(): void
    {
        $api = AppClient::boot();
        $before = $this->rowCounts();

        foreach (['', str_repeat('a', 64)] as $cookie) {
            [$status, $body] = $api->request('GET', '/api/health', $cookie);
            self::assertSame(200, $status);
            self::assertSame(['status' => 'ok'], $body);
        }
        self::assertSame($before, $this->rowCounts());
    }

    public function testOnlyGetAnswers(): void
    {
        [$status] = AppClient::boot()->request('POST', '/api/health', '', []);

        self::assertContains($status, [404, 405]);
    }

    public function testTheDatabaseBeingDownIsAGenericUnavailable(): void
    {
        $this->set('TEST_DB_PORT', '1');

        $this->assertUnavailable(AppClient::boot()->request('GET', '/api/health', ''), 'persistence_unavailable');
    }

    public function testAnInvalidConfigurationIsAGenericUnavailableEvenWithTheDatabaseUp(): void
    {
        $this->set('APP_ENV', 'production');
        $this->set('APP_ORIGIN', 'http://insecure.example');

        $this->assertUnavailable(AppClient::boot()->request('GET', '/api/health', ''), 'configuration_unavailable');
    }

    public function testNothingAboutTheFailureLeaksIntoTheAnswer(): void
    {
        $this->set('TEST_DB_PORT', '1');

        [, $body] = AppClient::boot()->request('GET', '/api/health', '');
        $json = json_encode($body);

        foreach (['SQLSTATE', 'flow_balance', 'TEST_DB', 'Connection refused', '127.0.0.1', getenv('TEST_DB_PASSWORD')] as $secret) {
            self::assertStringNotContainsString((string) $secret, $json);
        }
    }
}
