<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddPaymentExchangeRate extends AbstractMigration
{
    public function up(): void
    {
        $this->execute("ALTER TABLE payments ADD exchange_rate numeric CHECK (
            exchange_rate IS NULL OR (
                currency = 'USD' AND exchange_rate > 0 AND exchange_rate < 100000000000000 AND scale(exchange_rate) <= 6
            )
        )");
        $this->execute(<<<'SQL'
            CREATE FUNCTION payment_amount_mxn(amount numeric, currency text, rate numeric)
            RETURNS numeric LANGUAGE SQL IMMUTABLE PARALLEL SAFE
            RETURN CASE currency
                WHEN 'MXN' THEN round(amount, 2)
                WHEN 'USD' THEN round(amount * rate, 2)
                ELSE NULL
            END
            SQL);
    }

    public function down(): void
    {
        $this->execute('LOCK TABLE payments, payment_revisions IN ACCESS EXCLUSIVE MODE');
        if ($this->fetchRow("SELECT 1 FROM payments WHERE exchange_rate IS NOT NULL
            UNION ALL SELECT 1 FROM payment_revisions WHERE previous_values->>'exchange_rate' IS NOT NULL LIMIT 1")) {
            throw new RuntimeException('Preserve recorded exchange rates before reverting this migration.');
        }
        $this->execute('DROP FUNCTION payment_amount_mxn(numeric, text, numeric)');
        $this->execute('ALTER TABLE payments DROP COLUMN exchange_rate');
    }
}
