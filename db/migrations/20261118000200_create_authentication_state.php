<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateAuthenticationState extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
            CREATE TABLE server_sessions (
                token_hash CHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_nopad_bin NOT NULL PRIMARY KEY,
                user_id BIGINT NULL,
                payload JSON NOT NULL,
                created_at DATETIME(6) NOT NULL,
                authenticated_at DATETIME(6) NULL,
                last_activity_at DATETIME(6) NOT NULL,
                expires_at DATETIME(6) NOT NULL,
                revoked_at DATETIME(6) NULL,
                INDEX server_sessions_user_id_index (user_id),
                INDEX server_sessions_expires_at_index (expires_at),
                FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);
        // failed_at holds a JSON array of epoch seconds: MariaDB has no arrays (design D21).
        $this->execute(<<<'SQL'
            CREATE TABLE login_throttles (
                key_hash CHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_nopad_bin NOT NULL PRIMARY KEY,
                failed_at JSON NOT NULL DEFAULT '[]',
                blocked_until DATETIME(6) NULL,
                updated_at DATETIME(6) NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);
    }

    public function down(): void
    {
        if ($this->fetchRow('SELECT 1 FROM server_sessions LIMIT 1')) {
            throw new RuntimeException('Preserve the rows in server_sessions before rolling back');
        }
        if ($this->fetchRow('SELECT 1 FROM login_throttles LIMIT 1')) {
            throw new RuntimeException('Preserve the rows in login_throttles before rolling back');
        }
        $this->execute('DROP TABLE login_throttles');
        $this->execute('DROP TABLE server_sessions');
    }
}
