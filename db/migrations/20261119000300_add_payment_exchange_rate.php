<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddPaymentExchangeRate extends AbstractMigration
{
    public function up(): void
    {
        // DECIMAL(26,12) plus "= ROUND(x, 6)" rejects a seventh decimal; DECIMAL(n,6) would round it silently.
        // The MXN conversion is an expression built by the application, not a stored function (design D8).
        $this->execute("ALTER TABLE payments ADD exchange_rate DECIMAL(26,12) NULL CHECK (
            exchange_rate IS NULL OR (
                currency = 'USD' AND exchange_rate > 0 AND exchange_rate < 100000000000000 AND exchange_rate = ROUND(exchange_rate, 6)
            )
        )");
    }

    public function down(): void
    {
        if ($this->fetchRow("SELECT 1 FROM payments WHERE exchange_rate IS NOT NULL
            UNION ALL SELECT 1 FROM payment_revisions WHERE JSON_VALUE(previous_values, '$.exchange_rate') IS NOT NULL LIMIT 1")) {
            throw new RuntimeException('Preserve recorded exchange rates before reverting this migration.');
        }
        $this->execute('ALTER TABLE payments DROP COLUMN exchange_rate');
    }
}
