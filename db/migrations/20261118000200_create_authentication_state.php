<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateAuthenticationState extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
            CREATE TABLE server_sessions (
                token_hash char(64) PRIMARY KEY,
                user_id bigint REFERENCES users(id) ON DELETE RESTRICT,
                payload jsonb NOT NULL,
                created_at timestamptz NOT NULL,
                authenticated_at timestamptz,
                last_activity_at timestamptz NOT NULL,
                expires_at timestamptz NOT NULL,
                revoked_at timestamptz
            )
            SQL);
        $this->execute(<<<'SQL'
            CREATE TABLE login_throttles (
                key_hash char(64) PRIMARY KEY,
                failed_at timestamptz[] NOT NULL DEFAULT '{}',
                blocked_until timestamptz,
                updated_at timestamptz NOT NULL
            )
            SQL);
        $this->execute('CREATE INDEX server_sessions_user_id_index ON server_sessions(user_id)');
        $this->execute('CREATE INDEX server_sessions_expires_at_index ON server_sessions(expires_at)');
    }

    public function down(): void
    {
        $this->execute('LOCK TABLE server_sessions IN ACCESS EXCLUSIVE MODE');
        if ($this->fetchRow('SELECT 1 FROM server_sessions LIMIT 1')) {
            throw new RuntimeException('Preserve the rows in server_sessions before rolling back');
        }
        $this->execute('LOCK TABLE login_throttles IN ACCESS EXCLUSIVE MODE');
        if ($this->fetchRow('SELECT 1 FROM login_throttles LIMIT 1')) {
            throw new RuntimeException('Preserve the rows in login_throttles before rolling back');
        }
        $this->execute('DROP TABLE login_throttles');
        $this->execute('DROP TABLE server_sessions');
    }
}
