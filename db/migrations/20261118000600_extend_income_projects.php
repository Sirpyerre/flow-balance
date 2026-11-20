<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class ExtendIncomeProjects extends AbstractMigration
{
    public function up(): void
    {
        // DECIMAL(22,8) plus "= ROUND(x, 2)": a plain DECIMAL(n,2) would round extra decimals silently (design D2).
        $this->execute("ALTER TABLE projects
            ADD agreed_amount DECIMAL(22,8) NULL,
            ADD agreed_currency CHAR(3) CHARACTER SET utf8mb4 COLLATE utf8mb4_nopad_bin NULL,
            ADD agreed_payment_count BIGINT NULL CHECK (agreed_payment_count > 0),
            ADD acquisition_source VARCHAR(100) NULL CHECK (CHAR_LENGTH(acquisition_source) = CHAR_LENGTH(TRIM(acquisition_source)) AND acquisition_source <> ''),
            ADD CONSTRAINT agreed_amount_currency CHECK (
                (agreed_amount IS NULL AND agreed_currency IS NULL) OR
                (agreed_amount IS NOT NULL AND agreed_currency IS NOT NULL AND agreed_currency = 'MXN'
                 AND agreed_amount > 0 AND agreed_amount < 100000000000000 AND agreed_amount = ROUND(agreed_amount, 2))
            )");
    }

    public function down(): void
    {
        if ($this->fetchRow('SELECT 1 FROM projects WHERE agreed_amount IS NOT NULL OR agreed_payment_count IS NOT NULL OR acquisition_source IS NOT NULL LIMIT 1')) {
            throw new RuntimeException('Preserve project income data before rolling back');
        }
        $this->execute('ALTER TABLE projects DROP CONSTRAINT agreed_amount_currency');
        $this->execute('ALTER TABLE projects DROP agreed_amount, DROP agreed_currency, DROP agreed_payment_count, DROP acquisition_source');
    }
}
