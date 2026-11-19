<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class ExtendIncomeProjects extends AbstractMigration
{
    public function up(): void
    {
        $this->execute("ALTER TABLE projects
            ADD agreed_amount numeric,
            ADD agreed_currency char(3),
            ADD agreed_payment_count bigint CHECK (agreed_payment_count > 0),
            ADD acquisition_source varchar(100) CHECK (acquisition_source = btrim(acquisition_source) AND acquisition_source <> ''),
            ADD CONSTRAINT agreed_amount_currency CHECK (
                (agreed_amount IS NULL AND agreed_currency IS NULL) OR
                (agreed_amount IS NOT NULL AND agreed_currency IS NOT NULL AND agreed_currency = 'MXN'
                 AND agreed_amount > 0 AND agreed_amount < 100000000000000 AND scale(agreed_amount) <= 2)
            )");
    }

    public function down(): void
    {
        $this->execute('LOCK TABLE projects IN ACCESS EXCLUSIVE MODE');
        if ($this->fetchRow('SELECT 1 FROM projects WHERE agreed_amount IS NOT NULL OR agreed_payment_count IS NOT NULL OR acquisition_source IS NOT NULL LIMIT 1')) {
            throw new RuntimeException('Preserve project income data before rolling back');
        }
        $this->execute('ALTER TABLE projects DROP agreed_amount, DROP agreed_currency, DROP agreed_payment_count, DROP acquisition_source');
    }
}
