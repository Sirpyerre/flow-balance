<?php

declare(strict_types=1);

require_once __DIR__ . '/../Support/AppClient.php';

use FlowBalance\Finance\Application\CreateClient;
use FlowBalance\Finance\Application\CreateProject;
use PHPUnit\Framework\TestCase;

/**
 * Concurrency contract for payments, written against behaviour so it holds on any storage engine:
 * a key is account-wide, a replay returns the original payment and a clash is a conflict, and
 * recording a payment never deadlocks with a login of the same account.
 */
final class ConcurrentPaymentsTest extends TestCase
{
    private const HOLD_MILLISECONDS = 1500;

    /** Records one payment inside an open transaction, announces the lock, holds it, then commits. */
    private const WORKER = <<<'PHP'
        require 'vendor/autoload.php';
        [, $userId, $projectId, $key, $hold] = $argv;
        $database = (require 'config/database.php')->connect();
        $database->beginTransaction();
        try {
            $result = (new FlowBalance\Finance\Application\RecordPayment($database))->capture(
                $userId, $projectId, '2025-11-30', $key,
                new FlowBalance\Finance\Application\PaymentAmounts('100'), 'direct_client', null, null
            );
            $outcome = ['status' => $result['created'] ? 'created' : 'replayed', 'id' => $result['id']];
        } catch (DomainException) {
            $outcome = ['status' => 'conflict'];
        }
        echo "locked\n";
        fflush(STDOUT);
        usleep((int) $hold * 1000);
        $database->commit();
        echo json_encode($outcome, JSON_THROW_ON_ERROR), "\n";
        PHP;

    private ?PDO $database = null;
    private array $processes = [];
    private string $userId = '';

    protected function setUp(): void
    {
        if (getenv('TEST_DB_PASSWORD') === false) {
            self::markTestSkipped('Configure the dedicated test database');
        }
        $this->database = (require dirname(__DIR__, 2) . '/config/database.php')->connect();
    }

    protected function tearDown(): void
    {
        foreach ($this->processes as $process) {
            if (is_resource($process)) {
                proc_terminate($process);
                proc_close($process);
            }
        }
        if ($this->userId !== '' && $this->database !== null) {
            foreach (['payments', 'projects', 'clients', 'server_sessions'] as $table) {
                $this->database->prepare("DELETE FROM {$table} WHERE user_id = ?")->execute([$this->userId]);
            }
            $this->database->prepare('DELETE FROM users WHERE id = ?')->execute([$this->userId]);
        }
    }

    public function testIdenticalRequestsOnOneKeyCreateASinglePayment(): void
    {
        [$projectId] = $this->account(1);
        $first = $this->start($projectId, 'same-key', self::HOLD_MILLISECONDS);
        $second = $this->start($projectId, 'same-key', 0);
        $outcomes = [$this->finish($first), $this->finish($second)];

        self::assertSame(['created', 'replayed'], array_column($outcomes, 'status'));
        self::assertSame($outcomes[0]['id'], $outcomes[1]['id']);
        self::assertSame(1, $this->paymentCount('same-key'));
    }

    public function testTheSameKeyOnTwoProjectsIsAConflictNotASecondPayment(): void
    {
        [$firstProject, $secondProject] = $this->account(2);
        $first = $this->start($firstProject, 'shared-key', self::HOLD_MILLISECONDS);
        $second = $this->start($secondProject, 'shared-key', 0);
        $outcomes = [$this->finish($first), $this->finish($second)];

        self::assertSame(['created', 'conflict'], array_column($outcomes, 'status'));
        self::assertSame(1, $this->paymentCount('shared-key'));
        $owner = $this->database->prepare('SELECT project_id FROM payments WHERE user_id = ? AND idempotency_key = ?');
        $owner->execute([$this->userId, 'shared-key']);
        self::assertSame($firstProject, (string) $owner->fetchColumn());
    }

    public function testRecordingAPaymentAndLoggingInTheSameAccountDoNotDeadlock(): void
    {
        [$projectId] = $this->account(1);
        $worker = $this->start($projectId, 'login-race', self::HOLD_MILLISECONDS);

        $began = microtime(true);
        $login = AppClient::boot()->write('/api/auth/login', ['email' => $this->email(), 'password' => 'Una contraseña exacta']);
        $elapsed = microtime(true) - $began;

        self::assertSame(200, $login[0], json_encode($login[1]));
        self::assertLessThan(10.0, $elapsed, 'The login waited too long behind the payment');
        self::assertSame('created', $this->finish($worker)['status']);
        self::assertSame(1, $this->paymentCount('login-race'));
    }

    /** @return list<string> project ids of a fresh account */
    private function account(int $projects): array
    {
        $registered = AppClient::boot()->register('Ana', $this->email(), 'Una contraseña exacta');
        self::assertSame(201, $registered[0], json_encode($registered[1]));
        $this->userId = $registered[1]['user']['id'];
        $client = (new CreateClient($this->database))->create($this->userId, 'Cliente');
        $ids = [];
        for ($index = 1; $index <= $projects; ++$index) {
            $ids[] = (new CreateProject($this->database))->create($this->userId, $client, "Proyecto {$index}", ['5000.00', 'MXN', 3, 'Directo']);
        }
        return $ids;
    }

    private function email(): string
    {
        static $email = null;
        return $email ??= 'concurrency-' . bin2hex(random_bytes(6)) . '@example.test';
    }

    /** @return array{0: resource, 1: array} process and pipes, returned once the transaction holds its lock */
    private function start(string $projectId, string $key, int $hold): array
    {
        $process = proc_open([PHP_BINARY, '-r', self::WORKER, $this->userId, $projectId, $key, (string) $hold],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 2));
        self::assertIsResource($process);
        $this->processes[] = $process;
        fclose($pipes[0]);
        stream_set_timeout($pipes[1], 20);
        if ($hold > 0) {
            self::assertSame("locked\n", fgets($pipes[1]), stream_get_contents($pipes[2]));
        }
        return [$process, $pipes];
    }

    private function finish(array $worker): array
    {
        [$process, $pipes] = $worker;
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $errors);
        $lines = array_values(array_filter(explode("\n", $output), static fn (string $line): bool => $line !== '' && $line !== 'locked'));
        self::assertCount(1, $lines, $output . $errors);
        return json_decode($lines[0], true, flags: JSON_THROW_ON_ERROR);
    }

    private function paymentCount(string $key): int
    {
        $count = $this->database->prepare('SELECT COUNT(*) FROM payments WHERE user_id = ? AND idempotency_key = ?');
        $count->execute([$this->userId, $key]);
        return (int) $count->fetchColumn();
    }
}
