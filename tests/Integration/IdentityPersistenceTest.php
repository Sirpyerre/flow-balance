<?php

declare(strict_types=1);

use FlowBalance\Identity\Application\LoginUser;
use FlowBalance\Identity\Application\RegisterUser;
use FlowBalance\Identity\Domain\RegistrationInput;
use FlowBalance\Identity\Domain\SessionPolicy;
use FlowBalance\Identity\Http\LogoutHandler;
use FlowBalance\Identity\Infrastructure\NativePasswordHasher;
use FlowBalance\Identity\Infrastructure\SqlSessionPersistence;
use FlowBalance\Shared\Clock\Clock;
use FlowBalance\Shared\Database\UnitOfWork;
use Laminas\Diactoros\Response;
use Laminas\Diactoros\ServerRequest;
use Mezzio\Session\Session;
use Mezzio\Session\SessionInterface;
use PHPUnit\Framework\TestCase;

final class MovableClock implements Clock
{
    public function __construct(public int $now) {}

    public function now(): int
    {
        return $this->now;
    }
}

/** Registration, the login throttle and sessions against the real engine, with the clock under control. */
final class IdentityPersistenceTest extends TestCase
{
    private const START = 1_800_000_000;
    private const PASSWORD = 'una contraseña larga y exacta';

    private PDO $database;
    private MovableClock $clock;

    protected function setUp(): void
    {
        if (getenv('TEST_DB_PASSWORD') === false) {
            self::markTestSkipped('Configure the dedicated test database');
        }
        $this->database = (require dirname(__DIR__, 2) . '/config/database.php')->connect();
        $this->database->beginTransaction();
        $this->clock = new MovableClock(self::START);
    }

    protected function tearDown(): void
    {
        if (isset($this->database) && $this->database->inTransaction()) {
            $this->database->rollBack();
        }
    }

    private function address(): string
    {
        return 'identity-' . bin2hex(random_bytes(6)) . '@example.test';
    }

    private function register(string $email): ?array
    {
        $input = new RegistrationInput(['name' => 'Ana', 'email' => $email, 'password' => self::PASSWORD, 'passwordConfirmation' => self::PASSWORD]);
        self::assertSame([], $input->errors);
        return (new RegisterUser($this->database, new UnitOfWork($this->database), new NativePasswordHasher()))->register($input);
    }

    private function login(string $email, string $password = self::PASSWORD, string $ip = '203.0.113.7', ?int $now = null): array
    {
        return (new LoginUser($this->database, new NativePasswordHasher(), str_repeat('s', 32)))
            ->login($email, $password, $ip, $now ?? $this->clock->now);
    }

    public function testRegistrationReturnsTheAccountAndStoresTheNormalizedAddress(): void
    {
        $email = strtoupper($this->address());
        $user = $this->register($email);

        self::assertSame(['id', 'name', 'email'], array_keys($user));
        self::assertMatchesRegularExpression('/\A[1-9][0-9]*\z/', $user['id']);
        self::assertSame($email, $user['email'], 'The address is returned as typed');
        $stored = $this->database->prepare('SELECT email, email_normalized FROM users WHERE id = ?');
        $stored->execute([$user['id']]);
        self::assertSame([$email, strtolower($email)], array_values($stored->fetch(PDO::FETCH_ASSOC)));
    }

    public function testTheSameAddressInAnotherSpellingIsADuplicateAndDoesNotBreakTheTransaction(): void
    {
        $email = $this->address();
        $first = $this->register($email);

        self::assertNull($this->register(strtoupper($email)));
        self::assertNull($this->register(" \t" . $email . "\n"));
        // The failed insert must not abort the surrounding transaction: more work can follow.
        $second = $this->register($this->address());
        self::assertNotNull($second);
        self::assertNotSame($first['id'], $second['id']);
        self::assertSame(2, (int) $this->database->query("SELECT COUNT(*) FROM users WHERE id IN ({$first['id']}, {$second['id']})")->fetchColumn());
    }

    public function testLoginAcceptsAnySpellingOfTheAddressAndReturnsTheIdAsText(): void
    {
        $email = $this->address();
        $user = $this->register($email);

        foreach ([$email, strtoupper($email), " {$email}\t"] as $spelling) {
            $result = $this->login($spelling);
            self::assertSame(['user'], array_keys($result));
            self::assertSame($user['id'], $result['user']['id']);
            self::assertIsString($result['user']['id']);
            self::assertSame(['id', 'name', 'email'], array_keys($result['user']));
        }
    }

    public function testFiveFailuresBlockForFifteenMinutesEvenForTheRightPassword(): void
    {
        $email = $this->address();
        $this->register($email);

        for ($attempt = 1; $attempt <= 4; ++$attempt) {
            self::assertSame([], $this->login($email, 'incorrecta'), "attempt {$attempt}");
        }
        self::assertSame(['retryAfter' => 900], $this->login($email, 'incorrecta'));
        self::assertSame(['retryAfter' => 890], $this->login($email, self::PASSWORD, now: self::START + 10));
        self::assertSame(['retryAfter' => 1], $this->login($email, self::PASSWORD, now: self::START + 899));
        $reopened = $this->login($email, self::PASSWORD, now: self::START + 900);
        self::assertArrayHasKey('user', $reopened);
    }

    public function testTheBlockIsLiftedAndTheCountStartsOver(): void
    {
        $email = $this->address();
        $this->register($email);
        for ($attempt = 1; $attempt <= 5; ++$attempt) {
            $this->login($email, 'incorrecta');
        }
        $after = self::START + 900;
        for ($attempt = 1; $attempt <= 4; ++$attempt) {
            self::assertSame([], $this->login($email, 'incorrecta', now: $after), "fresh attempt {$attempt}");
        }
        self::assertSame(['retryAfter' => 900], $this->login($email, 'incorrecta', now: $after));
    }

    public function testFailuresOlderThanFifteenMinutesDoNotCount(): void
    {
        $email = $this->address();
        $this->register($email);
        for ($attempt = 1; $attempt <= 4; ++$attempt) {
            $this->login($email, 'incorrecta');
        }

        self::assertSame([], $this->login($email, 'incorrecta', now: self::START + 901));
        self::assertSame([], $this->login($email, 'incorrecta', now: self::START + 902));
    }

    public function testASuccessfulLoginClearsTheCount(): void
    {
        $email = $this->address();
        $this->register($email);
        for ($attempt = 1; $attempt <= 4; ++$attempt) {
            $this->login($email, 'incorrecta');
        }
        self::assertArrayHasKey('user', $this->login($email));
        for ($attempt = 1; $attempt <= 4; ++$attempt) {
            self::assertSame([], $this->login($email, 'incorrecta'), "after success, attempt {$attempt}");
        }
    }

    public function testUnknownAddressesAreThrottledLikeKnownOnes(): void
    {
        $email = $this->address();
        for ($attempt = 1; $attempt <= 4; ++$attempt) {
            self::assertSame([], $this->login($email, 'incorrecta'));
        }
        self::assertSame(['retryAfter' => 900], $this->login($email, 'incorrecta'));
    }

    public function testEachOriginIsCountedSeparately(): void
    {
        $email = $this->address();
        $this->register($email);
        for ($attempt = 1; $attempt <= 5; ++$attempt) {
            $this->login($email, 'incorrecta', ip: '203.0.113.10');
        }

        self::assertSame(['retryAfter' => 900], $this->login($email, 'incorrecta', ip: '203.0.113.10'));
        self::assertSame([], $this->login($email, 'incorrecta', ip: '203.0.113.11'));
        self::assertSame([], $this->login($email, 'incorrecta', ip: '2001:db8::1'));
    }

    private function persistence(): SqlSessionPersistence
    {
        return new SqlSessionPersistence($this->database, $this->clock, new SessionPolicy());
    }

    private function cookieOf(Response $response): string
    {
        self::assertMatchesRegularExpression('/^__Host-flow_balance=([a-f0-9]{64}); Max-Age=\d+; Path=\/; Secure; HttpOnly; SameSite=Lax$/', $response->getHeaderLine('Set-Cookie'));
        return substr($response->getHeaderLine('Set-Cookie'), strlen('__Host-flow_balance='), 64);
    }

    private function restore(string $token): SessionInterface
    {
        return $this->persistence()->initializeSessionFromRequest(
            (new ServerRequest())->withCookieParams(['__Host-flow_balance' => $token])
        );
    }

    /** Stores a session and returns its cookie token. */
    private function store(array $data): string
    {
        $session = new Session([]);
        foreach ($data as $key => $value) {
            $session->set($key, $value);
        }
        return $this->cookieOf($this->persistence()->persistSession($session, new Response()));
    }

    private function signedIn(): array
    {
        $user = $this->register($this->address());
        return ['userId' => $user['id'], 'authenticatedAt' => self::START, 'lastActivityAt' => self::START];
    }

    public function testAnAnonymousSessionLastsFifteenMinutes(): void
    {
        $token = $this->store(['csrf' => 'x']);

        $this->clock->now = self::START + SessionPolicy::ANONYMOUS_SECONDS - 1;
        self::assertSame(['csrf' => 'x'], $this->restore($token)->toArray());
        $this->clock->now = self::START + SessionPolicy::ANONYMOUS_SECONDS;
        self::assertSame([], $this->restore($token)->toArray());
    }

    public function testASignedInSessionExpiresAfterADayWithoutActivity(): void
    {
        $data = $this->signedIn();
        $token = $this->store($data);

        $this->clock->now = self::START + SessionPolicy::IDLE_SECONDS - 1;
        self::assertSame($data, $this->restore($token)->toArray());
        $this->clock->now = self::START + SessionPolicy::IDLE_SECONDS;
        self::assertSame([], $this->restore($token)->toArray());
    }

    public function testActivityExtendsTheIdleLimitButNeverTheAbsoluteOne(): void
    {
        $token = $this->store($this->signedIn());
        $touch = function (int $at) use ($token): void {
            $this->clock->now = $at;
            $session = $this->restore($token);
            self::assertNotSame([], $session->toArray(), "The session must still be alive at +{$at}");
            $session->set('lastActivityAt', $at);
            $this->persistence()->persistSession($session, new Response());
        };

        // Activity every 80 000 s (under the one-day idle limit) keeps the session alive for the whole week...
        for ($at = self::START + 80_000; $at < self::START + SessionPolicy::ABSOLUTE_SECONDS - 100; $at += 80_000) {
            $touch($at);
        }
        $touch(self::START + SessionPolicy::ABSOLUTE_SECONDS - 100);
        $this->clock->now = self::START + SessionPolicy::ABSOLUTE_SECONDS - 1;
        self::assertNotSame([], $this->restore($token)->toArray());

        // ...but not one second past the absolute limit, however recent the activity.
        $this->clock->now = self::START + SessionPolicy::ABSOLUTE_SECONDS;
        self::assertSame([], $this->restore($token)->toArray());
    }

    public function testRegeneratingRevokesTheOldTokenAndIssuesANewOne(): void
    {
        $data = $this->signedIn();
        $old = $this->store($data);

        $regenerated = $this->restore($old)->regenerate();
        $new = $this->cookieOf($this->persistence()->persistSession($regenerated, new Response()));

        self::assertNotSame($old, $new);
        self::assertSame([], $this->restore($old)->toArray());
        self::assertSame($data, $this->restore($new)->toArray());
    }

    public function testPayloadsRoundTripWithoutLosingTypes(): void
    {
        $payload = ['userId' => null, 'nombre' => 'Ñandú 🙂', 'nested' => ['a' => 1, 'b' => 1.5, 'c' => [true, false, null]], 'text' => '007'];
        $token = $this->store($payload);

        self::assertSame($payload, $this->restore($token)->toArray());
    }

    public function testLoggingOutRevokesTheSessionAndEmptiesItsPayload(): void
    {
        $token = $this->store($this->signedIn());
        $session = $this->restore($token);

        $response = (new LogoutHandler())->handle(
            (new ServerRequest())->withAttribute(SessionInterface::class, $session)->withAttribute(PDO::class, $this->database)
        );

        self::assertSame(204, $response->getStatusCode());
        self::assertStringContainsString('Max-Age=0', $response->getHeaderLine('Set-Cookie'));
        $row = $this->database->prepare('SELECT revoked_at, payload FROM server_sessions WHERE token_hash = ?');
        $row->execute([hash('sha256', $token)]);
        $stored = $row->fetch(PDO::FETCH_ASSOC);
        self::assertNotNull($stored['revoked_at']);
        self::assertSame('{}', $stored['payload']);
        self::assertSame([], $this->restore($token)->toArray());
    }
}
