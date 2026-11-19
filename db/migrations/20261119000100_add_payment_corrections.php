<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddPaymentCorrections extends AbstractMigration
{
    public function up(): void
    {
        $this->execute("ALTER TABLE payments ADD version bigint NOT NULL DEFAULT 1 CHECK (version > 0), ADD deleted_at timestamptz");
        $this->execute("CREATE TABLE payment_revisions (
            user_id bigint NOT NULL, payment_id bigint NOT NULL, actor_id bigint NOT NULL CHECK (actor_id = user_id),
            previous_version bigint NOT NULL CHECK (previous_version > 0),
            result_version bigint NOT NULL CHECK (result_version = previous_version + 1),
            action text NOT NULL CHECK (action IN ('edit', 'delete')),
            previous_values jsonb NOT NULL, reason varchar(250), request_key varchar(255), fingerprint char(64),
            created_at timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP,
            CHECK ((request_key IS NULL) = (fingerprint IS NULL)),
            CHECK (action = 'edit' OR request_key IS NULL),
            PRIMARY KEY (user_id, payment_id, result_version),
            UNIQUE (user_id, payment_id, request_key),
            FOREIGN KEY (user_id, payment_id) REFERENCES payments(user_id, id)
        )");
    }

    public function down(): void
    {
        $this->execute('LOCK TABLE payments, payment_revisions IN ACCESS EXCLUSIVE MODE');
        if ($this->fetchRow('SELECT 1 FROM payment_revisions LIMIT 1') || $this->fetchRow('SELECT 1 FROM payments WHERE deleted_at IS NOT NULL OR version <> 1 LIMIT 1')) {
            throw new RuntimeException('Preserve payment corrections before rolling back');
        }
        $this->execute('DROP TABLE payment_revisions');
        $this->execute('ALTER TABLE payments DROP version, DROP deleted_at');
    }
}
