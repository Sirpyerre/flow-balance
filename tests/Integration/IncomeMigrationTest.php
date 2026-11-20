<?php

declare(strict_types=1);

use FlowBalance\Finance\Application\PaymentConversion;
use FlowBalance\Shared\Database\DatabaseConfig;
use Phinx\Db\Adapter\MysqlAdapter;
use Phinx\Migration\AbstractMigration;
use PHPUnit\Framework\TestCase;

/**
 * Replays the migration ladder over historical rows. It needs a database of its own (design D25): MariaDB commits
 * every DDL statement, so there is no transaction to roll back and no per-test schema to throw away. The database is
 * emptied before and after, and the test refuses to run anywhere else.
 */
final class IncomeMigrationTest extends TestCase
{
    private const DATABASE = 'flow_balance_migration_test';
    private const FOREIGN_KEY_FAILS = 1452;
    private const DUPLICATE_ENTRY = 1062;

    private PDO $database;

    protected function setUp(): void
    {
        if (getenv('MIGRATION_TEST_DB_PASSWORD') === false) {
            self::markTestSkipped('Configure the dedicated migration test database');
        }
        require_once dirname(__DIR__, 2) . '/config/database.php'; // loads .env
        $this->database = DatabaseConfig::fromEnvironment(array_replace($_ENV, $_SERVER, getenv(), ['APP_ENV' => 'migration-testing']))->connect();
        self::assertSame(self::DATABASE, $this->database->query('SELECT DATABASE()')->fetchColumn(), 'Refusing to drop tables elsewhere');
        $this->emptyDatabase();
    }

    protected function tearDown(): void
    {
        if (isset($this->database)) {
            $this->emptyDatabase();
        }
    }

    private function emptyDatabase(): void
    {
        $tables = $this->database->query('SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE()')->fetchAll(PDO::FETCH_COLUMN);
        if ($tables !== []) {
            $this->database->exec('SET FOREIGN_KEY_CHECKS = 0');
            $this->database->exec('DROP TABLE IF EXISTS `' . implode('`, `', $tables) . '`');
            $this->database->exec('SET FOREIGN_KEY_CHECKS = 1');
        }
    }

    private function refused(callable $statement, int $driverCode): void
    {
        try {
            $statement();
        } catch (PDOException $error) {
            self::assertSame($driverCode, $error->errorInfo[1], $error->getMessage());
            return;
        }
        self::fail("Expected the database to refuse the statement with error {$driverCode}");
    }

    private function guarded(AbstractMigration $migration, string $message): void
    {
        try {
            $migration->down();
        } catch (RuntimeException $error) {
            self::assertStringContainsString($message, $error->getMessage());
            return;
        }
        self::fail("Expected the rollback guard: {$message}");
    }

    public function testIncomeMigrationsPreserveHistoricalRowsAndProtectNewData(): void
    {
        $root = dirname(__DIR__, 2);
        $database = $this->database;
        $adapter = (new MysqlAdapter(['name' => self::DATABASE]))->setConnection($database);
        $migration = static function (string $class, string $file) use ($root, $adapter): AbstractMigration {
            require_once "$root/db/migrations/$file.php";
            return (new $class('testing', (int) substr($file, 0, 14)))->setAdapter($adapter);
        };
        $migration('CreateUsers', '20261118000100_create_users')->up();
        $migration('CreateClients', '20261118000300_create_clients')->up();
        $migration('CreateProjects', '20261118000400_create_projects')->up();
        $migration('CreatePayments', '20261118000500_create_payments')->up();
        $database->exec("INSERT INTO users (name,email,email_normalized,password_hash) VALUES
            ('Ana','ana@example.test','ana@example.test','unused'), ('Luis','luis@example.test','luis@example.test','unused')");
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

        // Delivery status: historical rows stay NULL, new rows start in progress, and recorded states are protected.
        $delivery = $migration('AddProjectDeliveryStatus', '20261119000200_add_project_delivery_status');
        $delivery->up();
        self::assertNull($database->query('SELECT delivery_status FROM projects WHERE id = 1')->fetchColumn());
        $delivery->down();
        $delivery->up();
        $database->exec("INSERT INTO projects (id,user_id,client_id,name) VALUES (2,1,1,'Nuevo')");
        self::assertSame('in_progress', $database->query('SELECT delivery_status FROM projects WHERE id = 2')->fetchColumn());
        $this->guarded($delivery, 'Preserve recorded delivery states');
        $database->exec('DELETE FROM projects WHERE id = 2');
        $delivery->down();

        // Agreement revisions belong to the account that owns the project.
        $agreements = $migration('CreateProjectAgreementRevisions', '20261119000400_create_project_agreement_revisions');
        $agreements->up();
        $agreements->down();
        $agreements->up();
        $revision = "INSERT INTO project_agreement_revisions
            (user_id, project_id, previous_agreed_amount, agreed_amount, client_paid_total, excess_amount, reason)
            VALUES (%d,1,5000,2000,3000,1000,'Correction')";
        $this->refused(static fn () => $database->exec(sprintf($revision, 2)), self::FOREIGN_KEY_FAILS);
        $database->exec(sprintf($revision, 1));
        $this->guarded($agreements, 'Preserve project agreement revisions');
        $database->exec('DELETE FROM project_agreement_revisions');
        $agreements->down();

        // Corrections: historical payments start at version 1 and are not deleted.
        $corrections = $migration('AddPaymentCorrections', '20261119000100_add_payment_corrections');
        $corrections->up();
        self::assertSame('1', $database->query('SELECT CAST(version AS CHAR) FROM payments')->fetchColumn());
        self::assertNull($database->query('SELECT deleted_at FROM payments')->fetchColumn());
        $corrections->down();
        $corrections->up();

        // Exchange rate: dollar payments without one have no MXN value until it is recorded.
        $conversion = $migration('AddPaymentExchangeRate', '20261119000300_add_payment_exchange_rate');
        $mxn = 'SELECT CAST(' . PaymentConversion::paymentAmount() . ' AS CHAR) FROM payments';
        $database->exec("UPDATE payments SET currency = 'USD'");
        $conversion->up();
        self::assertNull($database->query('SELECT exchange_rate FROM payments')->fetchColumn());
        self::assertNull($database->query($mxn)->fetchColumn());
        $conversion->down();
        $conversion->up();
        $database->exec('UPDATE payments SET exchange_rate = 17.25');
        self::assertSame('51750.00', $database->query($mxn)->fetchColumn());
        $this->guarded($conversion, 'Preserve recorded exchange rates');
        $database->exec("UPDATE payments SET exchange_rate = NULL, currency = 'MXN'");
        $conversion->down();

        // Payment revisions: owner and actor must exist, and a request key is used once per payment.
        $audit = "INSERT INTO payment_revisions
            (user_id, payment_id, actor_id, previous_version, result_version, action, previous_values, request_key, fingerprint)
            VALUES (?,1,?, ?, ?, 'edit','{}','request',repeat('a',64))";
        $database->prepare($audit)->execute([1, 1, 1, 2]);
        foreach ([[2, 2, self::FOREIGN_KEY_FAILS], [1, 1, self::DUPLICATE_ENTRY]] as [$owner, $actor, $code]) {
            $this->refused(static fn () => $database->prepare($audit)->execute([$owner, $actor, 2, 3]), $code);
        }
        $this->guarded($corrections, 'Preserve payment corrections');
        $database->exec('DELETE FROM payment_revisions');
        $corrections->down();

        // After the whole ladder went up and partly down, the historical row is exactly as it was.
        self::assertNull($database->query('SELECT agreed_amount FROM projects')->fetchColumn());
        self::assertSame('3000.000000', $database->query('SELECT CAST(client_paid_amount AS CHAR) FROM payments')->fetchColumn());
        self::assertSame(1, (int) $database->query('SELECT platform_fee = 0 FROM payments')->fetchColumn());
        self::assertSame(str_repeat('a', 64), $database->query('SELECT request_fingerprint FROM payments')->fetchColumn());
        foreach ([$charges, $payments, $projects] as $item) {
            $item->down();
        }
        foreach ([$projects, $payments, $charges] as $item) {
            $item->up();
        }
        $this->refused(static fn () => $database->exec("INSERT INTO payment_charges
            (user_id,payment_id,charge_type,paid_on,amount,currency,idempotency_key,request_fingerprint)
            VALUES (2,1,'commission','2025-12-02',50,'MXN','foreign',repeat('b',64))"), self::FOREIGN_KEY_FAILS);

        // Each rollback refuses to discard data recorded since.
        foreach ([
            [$projects, "UPDATE projects SET acquisition_source = 'Recomendación'", 'UPDATE projects SET acquisition_source = NULL', 'Preserve project income data'],
            [$projects, 'UPDATE projects SET agreed_payment_count = 3', 'UPDATE projects SET agreed_payment_count = NULL', 'Preserve project income data'],
            [$payments, "UPDATE payments SET excess_reason = 'Trabajo adicional'", 'UPDATE payments SET excess_reason = NULL', 'Preserve payment income data'],
        ] as [$item, $record, $undo, $message]) {
            $database->exec($record);
            $this->guarded($item, $message);
            $database->exec($undo);
        }
        $database->exec("INSERT INTO payment_charges (user_id,payment_id,charge_type,paid_on,amount,currency,idempotency_key,request_fingerprint)
            VALUES (1,1,'commission','2025-12-02',50,'MXN','charge',repeat('b',64))");
        $this->guarded($charges, 'Preserve payment charges before rolling back');
    }
}
