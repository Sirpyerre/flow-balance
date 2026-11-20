<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreatePayments extends AbstractMigration
{
    public function up(): void
    {
        // Keys and currency codes are compared exactly (utf8mb4_nopad_bin): see design D3.
        $this->execute(<<<'SQL'
            CREATE TABLE payments (
                id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                user_id BIGINT NOT NULL,
                project_id BIGINT NOT NULL,
                client_paid_amount DECIMAL(20,6) NOT NULL CHECK (client_paid_amount > 0),
                currency CHAR(3) CHARACTER SET utf8mb4 COLLATE utf8mb4_nopad_bin NOT NULL,
                paid_on DATE NOT NULL,
                idempotency_key VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_nopad_bin NOT NULL,
                request_fingerprint CHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_nopad_bin NOT NULL,
                created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
                updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
                UNIQUE (user_id, idempotency_key),
                INDEX payments_monthly_report_index (user_id, currency, paid_on),
                FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE RESTRICT,
                FOREIGN KEY (user_id, project_id) REFERENCES projects (user_id, id) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);
    }

    public function down(): void
    {
        if ($this->fetchRow('SELECT 1 FROM payments LIMIT 1')) {
            throw new RuntimeException('Preserve the rows in payments before rolling back');
        }
        $this->execute('DROP TABLE payments');
    }
}
