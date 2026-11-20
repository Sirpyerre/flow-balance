<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateUsers extends AbstractMigration
{
    public function up(): void
    {
        // email_normalized is written by the application (trimmed, lower-cased) and compared exactly:
        // utf8mb4_unicode_ci would treat "nandu" and "ñandú" as the same address.
        $this->execute(<<<'SQL'
            CREATE TABLE users (
                id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(100) NOT NULL CHECK (TRIM(name) <> ''),
                email VARCHAR(254) NOT NULL CHECK (TRIM(email) <> ''),
                email_normalized VARCHAR(254) CHARACTER SET utf8mb4 COLLATE utf8mb4_nopad_bin NOT NULL,
                password_hash VARCHAR(255) NOT NULL,
                created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
                updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
                CONSTRAINT users_email_normalized_unique UNIQUE (email_normalized)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);
    }

    public function down(): void
    {
        if ($this->fetchRow('SELECT 1 FROM users LIMIT 1')) {
            throw new RuntimeException('Preserve the rows in users before rolling back');
        }
        $this->execute('DROP TABLE users');
    }
}
