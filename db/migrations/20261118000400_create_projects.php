<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateProjects extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
            CREATE TABLE projects (
                id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                user_id BIGINT NOT NULL,
                client_id BIGINT NOT NULL,
                name VARCHAR(100) NOT NULL CHECK (CHAR_LENGTH(name) = CHAR_LENGTH(TRIM(name)) AND name <> ''),
                created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
                updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
                UNIQUE (user_id, id),
                FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE RESTRICT,
                FOREIGN KEY (user_id, client_id) REFERENCES clients (user_id, id) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);
    }

    public function down(): void
    {
        if ($this->fetchRow('SELECT 1 FROM projects LIMIT 1')) {
            throw new RuntimeException('Preserve the rows in projects before rolling back');
        }
        $this->execute('DROP TABLE projects');
    }
}
