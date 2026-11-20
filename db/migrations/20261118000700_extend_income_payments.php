<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class ExtendIncomePayments extends AbstractMigration
{
    public function up(): void
    {
        $this->execute("ALTER TABLE payments
            ADD platform_fee DECIMAL(22,8) NOT NULL DEFAULT 0 CHECK (platform_fee >= 0 AND platform_fee = ROUND(platform_fee, 2)),
            ADD withheld_tax_amount DECIMAL(22,8) NOT NULL DEFAULT 0 CHECK (withheld_tax_amount >= 0 AND withheld_tax_amount = ROUND(withheld_tax_amount, 2)),
            ADD source_type VARCHAR(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_nopad_bin NULL,
            ADD source_name VARCHAR(100) NULL,
            ADD excess_reason VARCHAR(250) NULL CHECK (CHAR_LENGTH(excess_reason) = CHAR_LENGTH(TRIM(excess_reason)) AND excess_reason <> ''),
            ADD CONSTRAINT payment_deductions CHECK (platform_fee + withheld_tax_amount <= client_paid_amount),
            ADD CONSTRAINT payment_source CHECK (
                (source_type IS NULL AND source_name IS NULL) OR
                (source_type IS NOT NULL AND (
                    (source_type = 'direct_client' AND source_name IS NULL) OR
                    (source_type IN ('platform', 'other') AND source_name IS NOT NULL
                     AND CHAR_LENGTH(source_name) = CHAR_LENGTH(TRIM(source_name)) AND source_name <> '')
                ))
            ),
            ADD CONSTRAINT payments_owner_id UNIQUE (user_id, id)");
    }

    public function down(): void
    {
        if ($this->fetchRow('SELECT 1 FROM payments WHERE source_type IS NOT NULL OR platform_fee <> 0 OR withheld_tax_amount <> 0 OR excess_reason IS NOT NULL LIMIT 1')) {
            throw new RuntimeException('Preserve payment income data before rolling back');
        }
        $this->execute('ALTER TABLE payments DROP CONSTRAINT payment_deductions, DROP CONSTRAINT payment_source');
        $this->execute('ALTER TABLE payments DROP INDEX payments_owner_id, DROP platform_fee, DROP withheld_tax_amount, DROP source_type, DROP source_name, DROP excess_reason');
    }
}
