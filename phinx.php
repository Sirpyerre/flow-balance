<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';
$config = require __DIR__ . '/config/database.php';
foreach ($_SERVER['argv'] ?? [] as $index => $argument) {
    $selected = match (true) {
        $argument === '-e', $argument === '--environment' => $_SERVER['argv'][$index + 1] ?? '',
        str_starts_with($argument, '--environment=') => substr($argument, 14),
        default => $config->environment,
    };
    if ($selected !== $config->environment) {
        throw new InvalidArgumentException('Phinx environment must match APP_ENV');
    }
}
return [
    'paths' => ['migrations' => __DIR__ . '/db/migrations'],
    'environments' => [
        'default_migration_table' => 'phinxlog',
        'default_environment' => $config->environment,
        $config->environment => [
            'adapter' => 'mysql',
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'connection' => $config->connect(),
            'name' => $config->database,
        ],
    ],
    'version_order' => 'execution',
];
