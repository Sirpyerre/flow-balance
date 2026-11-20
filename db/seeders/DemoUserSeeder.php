<?php

declare(strict_types=1);

use Dotenv\Dotenv;
use FlowBalance\Identity\Domain\EmailAddress;
use FlowBalance\Identity\Infrastructure\NativePasswordHasher;

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

try {
    Dotenv::createImmutable($root)->safeLoad();
    $environment = array_replace($_ENV, $_SERVER, getenv());
    if (($environment['APP_ENV'] ?? null) !== 'development'
        || !in_array($environment['DB_HOST'] ?? null, ['localhost', '127.0.0.1', '::1'], true)
        || ($environment['DB_DATABASE'] ?? null) !== 'flow_balance'
        || ($environment['DB_USERNAME'] ?? null) !== 'flow_balance_app') {
        fwrite(STDERR, "El seeder requiere la base local de desarrollo de Flow Balance.\n");
        exit(1);
    }

    $email = 'demo@flowbalance.test';
    $password = 'FlowBalance-Demo-2026!';
    $database = (require $root . '/config/database.php')->connect();
    $statement = $database->prepare(
        'INSERT INTO users (name, email, email_normalized, password_hash) VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE email_normalized = email_normalized'
    );
    $hasher = new NativePasswordHasher();
    $statement->execute(['Cuenta de prueba', $email, EmailAddress::normalize($email), $hasher->hash($password)]);

    $query = $database->prepare('SELECT password_hash FROM users WHERE email_normalized = ?');
    $query->execute([EmailAddress::normalize($email)]);
    if (!$hasher->verify($password, (string) $query->fetchColumn())) {
        fwrite(STDERR, "El correo de prueba ya existe con otra contraseña. Conservé la cuenta.\n");
        exit(1);
    }

    echo "Cuenta de prueba disponible.\nCorreo: {$email}\nContraseña: {$password}\n";
} catch (Throwable) {
    fwrite(STDERR, "No se pudo crear la cuenta de prueba. Revisa .env, MariaDB y las migraciones.\n");
    exit(1);
}
