<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddProjectDeliveryStatus extends AbstractMigration
{
    public function up(): void
    {
        $this->execute("ALTER TABLE projects ADD delivery_status text
            CHECK (delivery_status IN ('in_progress', 'delivered'))");
        $this->execute("ALTER TABLE projects ALTER COLUMN delivery_status SET DEFAULT 'in_progress'");
    }

    public function down(): void
    {
        $this->execute('LOCK TABLE projects IN ACCESS EXCLUSIVE MODE');
        if ($this->fetchRow('SELECT 1 FROM projects WHERE delivery_status IS NOT NULL LIMIT 1')) {
            throw new RuntimeException('Preserve recorded delivery states before reverting this migration.');
        }
        $this->execute('ALTER TABLE projects DROP COLUMN delivery_status');
    }
}
