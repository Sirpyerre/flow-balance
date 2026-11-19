<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class ExtendIncomePayments extends AbstractMigration
{
    public function up(): void
    {
        $this->execute("ALTER TABLE payments
            ADD platform_fee numeric NOT NULL DEFAULT 0 CHECK (platform_fee >= 0 AND scale(platform_fee) <= 2),
            ADD withheld_tax_amount numeric NOT NULL DEFAULT 0 CHECK (withheld_tax_amount >= 0 AND scale(withheld_tax_amount) <= 2),
            ADD source_type text,
            ADD source_name varchar(100),
            ADD excess_reason varchar(250) CHECK (excess_reason = btrim(excess_reason) AND excess_reason <> ''),
            ADD CONSTRAINT payment_deductions CHECK (platform_fee + withheld_tax_amount <= client_paid_amount),
            ADD CONSTRAINT payment_source CHECK (
                (source_type IS NULL AND source_name IS NULL) OR
                (source_type IS NOT NULL AND (
                    (source_type = 'direct_client' AND source_name IS NULL) OR
                    (source_type IN ('platform', 'other') AND source_name IS NOT NULL AND source_name = btrim(source_name) AND source_name <> '')
                ))
            ),
            ADD CONSTRAINT payments_owner_id UNIQUE (user_id, id)");
    }

    public function down(): void
    {
        $this->execute('LOCK TABLE payments IN ACCESS EXCLUSIVE MODE');
        if ($this->fetchRow('SELECT 1 FROM payments WHERE source_type IS NOT NULL OR platform_fee <> 0 OR withheld_tax_amount <> 0 OR excess_reason IS NOT NULL LIMIT 1')) {
            throw new RuntimeException('Preserve payment income data before rolling back');
        }
        $this->execute('ALTER TABLE payments DROP CONSTRAINT payments_owner_id, DROP platform_fee, DROP withheld_tax_amount, DROP source_type, DROP source_name, DROP excess_reason');
    }
}
