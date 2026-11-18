<?php

declare(strict_types=1);

namespace FlowBalance\Shared\Database;

use InvalidArgumentException;
use PDO;

final readonly class DatabaseConfig
{
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
            'development' => 'DB_',
            'testing' => 'TEST_DB_',
            default => throw new InvalidArgumentException('Unknown application environment'),
        };
        $required = static function (string $key) use ($values, $prefix): string {
            $value = $values[$prefix . $key] ?? '';
            if (!is_string($value) || $value === '') {
                throw new InvalidArgumentException("Missing database setting: {$prefix}{$key}");
            }
            return $value;
        };
        $database = $required('DATABASE');
        $username = $required('USERNAME');
        if ($environment === 'testing' && ($database !== 'flow_balance_test' || $username !== 'flow_balance_test_app')) {
            throw new InvalidArgumentException('Testing requires the dedicated database and role');
        }
        $host = $required('HOST');
        $port = filter_var($required('PORT'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
        if ($port === false || preg_match('/[;\x00-\x20]/', $host . $database)) {
            throw new InvalidArgumentException('Invalid database endpoint');
        }
        return new self($environment, $database, $host, $port, $username, $required('PASSWORD'));
    }

    public function connect(): PDO
    {
        $pdo = new PDO(
            "pgsql:host={$this->host};port={$this->port};dbname={$this->database}",
            $this->username,
            $this->password,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false],
        );
        $pdo->exec("SET TIME ZONE 'UTC'");
        return $pdo;
    }
}
