<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreatePaymentCharges extends AbstractMigration
{
    public function up(): void
    {
        $this->execute("CREATE TABLE payment_charges (
            id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT NOT NULL,
            payment_id BIGINT NOT NULL,
            charge_type VARCHAR(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_nopad_bin NOT NULL CHECK (charge_type IN ('commission', 'tax')),
            paid_on DATE NOT NULL,
            amount DECIMAL(22,8) NOT NULL CHECK (amount > 0 AND amount < 100000000000000 AND amount = ROUND(amount, 2)),
            currency CHAR(3) CHARACTER SET utf8mb4 COLLATE utf8mb4_nopad_bin NOT NULL CHECK (currency = 'MXN'),
            idempotency_key VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_nopad_bin NOT NULL,
            request_fingerprint CHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_nopad_bin NOT NULL,
            created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
            UNIQUE (user_id, idempotency_key),
            INDEX payment_charges_detail_index (user_id, payment_id, paid_on, id),
            FOREIGN KEY (user_id, payment_id) REFERENCES payments (user_id, id) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    public function down(): void
    {
        if ($this->fetchRow('SELECT 1 FROM payment_charges LIMIT 1')) {
            throw new RuntimeException('Preserve payment charges before rolling back');
        }
        $this->execute('DROP TABLE payment_charges');
    }
}
