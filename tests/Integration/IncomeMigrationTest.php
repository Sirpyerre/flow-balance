<?php

declare(strict_types=1);

use Phinx\Db\Adapter\PostgresAdapter;
use Phinx\Migration\AbstractMigration;
use PHPUnit\Framework\TestCase;

final class IncomeMigrationTest extends TestCase
{
    public function testIncomeMigrationsPreserveHistoricalRowsAndProtectNewData(): void
    {
        if (getenv('TEST_DB_PASSWORD') === false) {
            self::markTestSkipped('Configure the dedicated PostgreSQL test database');
        }
        $root = dirname(__DIR__, 2);
        $database = (require $root . '/config/database.php')->connect();
        $schema = 'income_test_' . bin2hex(random_bytes(8));
        $database->beginTransaction();
        try {
            $adapter = (new PostgresAdapter(['schema' => $schema]))->setConnection($database);
            $database->exec("SET LOCAL search_path TO {$schema}");
            $migration = static function (string $class, string $file) use ($root, $adapter): AbstractMigration {
                require_once "$root/db/migrations/$file.php";
                return (new $class('testing', (int) substr($file, 0, 14)))->setAdapter($adapter);
            };
            $migration('CreateUsers', '20261118000100_create_users')->up();
            $migration('CreateClients', '20261118000300_create_clients')->up();
            $migration('CreateProjects', '20261118000400_create_projects')->up();
            $migration('CreatePayments', '20261118000500_create_payments')->up();
            $database->exec("INSERT INTO users (name,email,password_hash) VALUES ('Ana','ana@example.test','unused'), ('Luis','luis@example.test','unused')");
            $database->exec("INSERT INTO clients (user_id,name) VALUES (1,'Cliente')");
            $database->exec("INSERT INTO projects (user_id,client_id,name) VALUES (1,1,'Proyecto')");
            $database->exec("INSERT INTO payments (user_id,project_id,client_paid_amount,currency,paid_on,idempotency_key,request_fingerprint)
                VALUES (1,1,3000,'MXN','2025-11-30','legacy',repeat('a',64))");
            $projects = $migration('ExtendIncomeProjects', '20261118000600_extend_income_projects');
            $payments = $migration('ExtendIncomePayments', '20261118000700_extend_income_payments');
            $charges = $migration('CreatePaymentCharges', '20261118000800_create_payment_charges');
            foreach ([$projects, $payments, $charges] as $item) {
                $item->up();
            }
            self::assertNull($database->query('SELECT agreed_amount FROM projects')->fetchColumn());
            self::assertSame('3000.000000', $database->query('SELECT client_paid_amount::text FROM payments')->fetchColumn());
            self::assertSame('0', $database->query('SELECT platform_fee::text FROM payments')->fetchColumn());
            self::assertSame(str_repeat('a', 64), $database->query('SELECT request_fingerprint FROM payments')->fetchColumn());
            foreach ([$charges, $payments, $projects] as $item) {
                $item->down();
            }
            foreach ([$projects, $payments, $charges] as $item) {
                $item->up();
            }
            $database->exec('SAVEPOINT foreign_owner');
            try {
                $database->exec("INSERT INTO payment_charges (user_id,payment_id,charge_type,paid_on,amount,currency,idempotency_key,request_fingerprint)
                    VALUES (2,1,'commission','2025-12-02',50,'MXN','foreign',repeat('b',64))");
                self::fail('Expected an account ownership constraint');
            } catch (PDOException $e) {
                self::assertSame('23503', $e->getCode());
                $database->exec('ROLLBACK TO SAVEPOINT foreign_owner');
            }
            $database->exec("INSERT INTO payment_charges (user_id,payment_id,charge_type,paid_on,amount,currency,idempotency_key,request_fingerprint)
                    VALUES (1,1,'commission','2025-12-02',50,'MXN','charge',repeat('b',64))");
            $this->expectExceptionMessage('Preserve payment charges before rolling back');
            $charges->down();
        } finally {
            $database->rollBack();
        }
    }
}
