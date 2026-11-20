<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddPaymentCorrections extends AbstractMigration
{
    public function up(): void
    {
        $this->execute("ALTER TABLE payments ADD version BIGINT NOT NULL DEFAULT 1 CHECK (version > 0), ADD deleted_at DATETIME(6) NULL");
        $this->execute("CREATE TABLE payment_revisions (
            user_id BIGINT NOT NULL, payment_id BIGINT NOT NULL, actor_id BIGINT NOT NULL CHECK (actor_id = user_id),
            previous_version BIGINT NOT NULL CHECK (previous_version > 0),
            result_version BIGINT NOT NULL CHECK (result_version = previous_version + 1),
            action VARCHAR(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_nopad_bin NOT NULL CHECK (action IN ('edit', 'delete')),
            previous_values JSON NOT NULL, reason VARCHAR(250) NULL,
            request_key VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_nopad_bin NULL,
            fingerprint CHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_nopad_bin NULL,
            created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
            CHECK ((request_key IS NULL) = (fingerprint IS NULL)),
            CHECK (action = 'edit' OR request_key IS NULL),
            PRIMARY KEY (user_id, payment_id, result_version),
            UNIQUE (user_id, payment_id, request_key),
            FOREIGN KEY (user_id, payment_id) REFERENCES payments (user_id, id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    public function down(): void
    {
        if ($this->fetchRow('SELECT 1 FROM payment_revisions LIMIT 1') || $this->fetchRow('SELECT 1 FROM payments WHERE deleted_at IS NOT NULL OR version <> 1 LIMIT 1')) {
            throw new RuntimeException('Preserve payment corrections before rolling back');
        }
        $this->execute('DROP TABLE payment_revisions');
        $this->execute('ALTER TABLE payments DROP version, DROP deleted_at');
    }
}
