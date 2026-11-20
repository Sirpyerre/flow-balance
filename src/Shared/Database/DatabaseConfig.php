<?php

declare(strict_types=1);

namespace FlowBalance\Shared\Database;

use FlowBalance\Shared\Configuration\InvalidConfiguration;
use PDO;

final readonly class DatabaseConfig
{
    private const SQL_MODE = 'STRICT_ALL_TABLES,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';

    private function __construct(
        public string $environment,
        public string $database,
        private string $host,
        private int $port,
        private string $username,
        private string $password,
    ) {}

    public static function fromEnvironment(array $values): self
    {
        $environment = $values['APP_ENV'] ?? 'development';
        $prefix = match ($environment) {
            'development', 'production' => 'DB_',
            'testing' => 'TEST_DB_',
            'migration-testing' => 'MIGRATION_TEST_DB_',
            default => throw new InvalidConfiguration('Unknown application environment'),
        };
        $required = static function (string $key) use ($values, $prefix): string {
            $value = $values[$prefix . $key] ?? '';
            if (!is_string($value) || $value === '') {
                throw new InvalidConfiguration("Missing database setting: {$prefix}{$key}");
            }
            return $value;
        };
        $database = $required('DATABASE');
        $username = $required('USERNAME');
        $dedicated = [
            'testing' => ['flow_balance_test', 'flow_balance_test_app'],
            'migration-testing' => ['flow_balance_migration_test', 'flow_balance_migration_test_app'],
        ];
        if (isset($dedicated[$environment]) && [$database, $username] !== $dedicated[$environment]) {
            throw new InvalidConfiguration('Testing requires the dedicated database and role');
        }
        $host = $required('HOST');
        $port = filter_var($required('PORT'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
        if ($port === false || preg_match('/[;\x00-\x20]/', $host . $database)) {
            throw new InvalidConfiguration('Invalid database endpoint');
        }
        return new self($environment, $database, $host, $port, $username, $required('PASSWORD'));
    }

    public function connect(): PDO
    {
        $pdo = new PDO(
            "mysql:host={$this->host};port={$this->port};dbname={$this->database};charset=utf8mb4",
            $this->username,
            $this->password,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false],
        );
        // UTC instants and strict errors instead of truncation; READ COMMITTED matches the isolation
        // the application was written against (InnoDB defaults to REPEATABLE READ).
        $pdo->exec("SET time_zone = '+00:00', sql_mode = '" . self::SQL_MODE . "'");
        $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
        return $pdo;
    }
}
