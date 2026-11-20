<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** What every connection promises, checked against the real server before any table exists. */
final class DatabaseConnectionTest extends TestCase
{
    private PDO $database;

    protected function setUp(): void
    {
        if (getenv('TEST_DB_PASSWORD') === false) {
            self::markTestSkipped('Configure the dedicated test database');
        }
        $this->database = (require dirname(__DIR__, 2) . '/config/database.php')->connect();
    }

    public function testInstantsAreStoredAndReadInUtc(): void
    {
        self::assertSame('1970-01-01 00:00:00', $this->database->query('SELECT FROM_UNIXTIME(0)')->fetchColumn());
        self::assertSame('+00:00', $this->database->query('SELECT @@session.time_zone')->fetchColumn());
    }

    public function testTransactionsReadCommitted(): void
    {
        // The variable is tx_isolation on MariaDB 10.x and transaction_isolation from 11.1.
        $isolation = $this->database->query(
            "SHOW SESSION VARIABLES WHERE Variable_name IN ('tx_isolation', 'transaction_isolation')"
        )->fetch(PDO::FETCH_NUM)[1];
        self::assertSame('READ-COMMITTED', $isolation);
    }

    public function testSqlModeIsStrictForEveryTableNotOnlyTransactionalOnes(): void
    {
        // The server default is STRICT_TRANS_TABLES; the connection must set its own, stricter mode.
        $mode = explode(',', $this->database->query('SELECT @@session.sql_mode')->fetchColumn());

        foreach (['STRICT_ALL_TABLES', 'NO_ZERO_DATE', 'ERROR_FOR_DIVISION_BY_ZERO', 'NO_ENGINE_SUBSTITUTION'] as $flag) {
            self::assertContains($flag, $mode);
        }
    }

    public function testAValueThatDoesNotFitIsAnErrorNotATruncation(): void
    {
        $this->database->exec('CREATE TEMPORARY TABLE fit (name VARCHAR(3) NOT NULL, amount DECIMAL(4,2) NOT NULL)');
        $this->expectException(PDOException::class);
        $this->database->prepare('INSERT INTO fit (name, amount) VALUES (?, ?)')->execute(['too long', '1.00']);
    }

    public function testAnAmountOutOfRangeIsAnErrorNotAClampedValue(): void
    {
        $this->database->exec('CREATE TEMPORARY TABLE fit (amount DECIMAL(4,2) NOT NULL)');
        $this->expectException(PDOException::class);
        $this->database->prepare('INSERT INTO fit (amount) VALUES (?)')->execute(['100.00']);
    }

    public function testDivisionByZeroIsAnErrorInStrictMode(): void
    {
        $this->database->exec('CREATE TEMPORARY TABLE fit (amount DECIMAL(4,2) NULL)');
        $this->expectException(PDOException::class);
        $this->database->prepare('INSERT INTO fit (amount) VALUES (1 / ?)')->execute([0]);
    }

    public function testTextIsUtf8mb4(): void
    {
        $this->database->exec('CREATE TEMPORARY TABLE fit (name VARCHAR(20) CHARACTER SET utf8mb4 NOT NULL)');
        $this->database->prepare('INSERT INTO fit (name) VALUES (?)')->execute(["Ñandú 🙂"]);
        self::assertSame("Ñandú 🙂", $this->database->query('SELECT name FROM fit')->fetchColumn());
    }
}
