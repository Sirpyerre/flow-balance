<?php

declare(strict_types=1);

use FlowBalance\Finance\Application\CreateClient;
use FlowBalance\Finance\Application\CreateProject;
use FlowBalance\Finance\Application\PaymentAmounts;
use FlowBalance\Finance\Application\RecordPayment;
use PHPUnit\Framework\TestCase;

final class IncomeIdempotencyTest extends TestCase
{
    public function testConcurrentPaymentsRecheckTheAgreedAmount(): void
    {
        if (getenv('TEST_DB_PASSWORD') === false) {
            self::markTestSkipped('Configure the dedicated test database');
        }
        $root = dirname(__DIR__, 2);
        $database = (require $root . '/config/database.php')->connect();
        $email = 'income-race-' . bin2hex(random_bytes(8)) . '@example.test';
        $database->prepare('INSERT INTO users (name, email, email_normalized, password_hash) VALUES (?, ?, ?, ?)')
            ->execute(['Concurrency test', $email, $email, 'unused']);
        $userId = $database->lastInsertId();
        $process = null;
        try {
            $clientId = (new CreateClient($database))->create($userId, 'Cliente');
            $projectId = (new CreateProject($database))->create($userId, $clientId, 'Proyecto', ['5000.00', 'MXN', 3, 'Recomendación']);
            $service = new RecordPayment($database);
            $service->record($userId, $projectId, '4800', 'MXN', '2025-11-30', 'initial');
            $database->beginTransaction();
            $first = $service->capture($userId, $projectId, '2025-11-30', 'race-first', new PaymentAmounts('150'), 'direct_client', null, null);

            $worker = <<<'PHP'
                require 'vendor/autoload.php';
                $database = (require 'config/database.php')->connect();
                $database->exec('SET SESSION innodb_lock_wait_timeout = 5');
                $database->beginTransaction();
                echo "ready\n";
                try {
                    $result = (new FlowBalance\Finance\Application\RecordPayment($database))->capture(
                        $argv[1], $argv[2], '2025-11-30', 'race-second',
                        new FlowBalance\Finance\Application\PaymentAmounts('150'), 'direct_client', null, null
                    );
                    $database->commit();
                    echo json_encode($result, JSON_THROW_ON_ERROR);
                } catch (FlowBalance\Finance\Application\IncomeValidation $error) {
                    $database->rollBack();
                    echo json_encode(['field' => $error->field, ...$error->context], JSON_THROW_ON_ERROR);
                }
                PHP;
            $process = proc_open([PHP_BINARY, '-r', $worker, $userId, $projectId],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
            self::assertIsResource($process);
            fclose($pipes[0]);
            stream_set_timeout($pipes[1], 8);
            self::assertSame("ready\n", fgets($pipes[1]));
            $database->commit();
            $result = stream_get_contents($pipes[1]);
            $errors = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame(0, proc_close($process), $errors);
            self::assertSame('100.00', json_decode($result, true, flags: JSON_THROW_ON_ERROR)['excessAmount']);
            $database->beginTransaction();
            $service->capture($userId, $projectId, '2025-11-30', 'race-second', new PaymentAmounts('150'), 'direct_client', null, 'Trabajo adicional');
            $replay = $service->capture($userId, $projectId, '2025-11-30', 'race-first', new PaymentAmounts('150'), 'direct_client', null, null);
            $database->commit();
            self::assertSame(['id' => $first['id'], 'created' => false], $replay);
            $count = $database->prepare('SELECT COUNT(*) FROM payments WHERE user_id = ? AND project_id = ?');
            $count->execute([$userId, $projectId]);
            self::assertSame(3, (int) $count->fetchColumn());
        } finally {
            if ($database->inTransaction()) {
                $database->rollBack();
            }
            if (is_resource($process)) {
                proc_terminate($process);
                proc_close($process);
            }
            foreach (['payments', 'projects', 'clients', 'users'] as $table) {
                $column = $table === 'users' ? 'id' : 'user_id';
                $database->prepare("DELETE FROM {$table} WHERE {$column} = ?")->execute([$userId]);
            }
        }
    }
}
