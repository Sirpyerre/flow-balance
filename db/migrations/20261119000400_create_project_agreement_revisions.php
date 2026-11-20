<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateProjectAgreementRevisions extends AbstractMigration
{
    public function up(): void
    {
        $this->execute("CREATE TABLE project_agreement_revisions (
            id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT NOT NULL,
            project_id BIGINT NOT NULL,
            previous_agreed_amount DECIMAL(22,8) NOT NULL CHECK (previous_agreed_amount > 0),
            agreed_amount DECIMAL(22,8) NOT NULL CHECK (agreed_amount > 0 AND agreed_amount < previous_agreed_amount),
            client_paid_total DECIMAL(22,8) NOT NULL,
            excess_amount DECIMAL(22,8) NOT NULL CHECK (excess_amount > 0 AND excess_amount = client_paid_total - agreed_amount),
            reason VARCHAR(250) NOT NULL CHECK (TRIM(reason) <> ''),
            created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
            INDEX project_agreement_revisions_owner_index (user_id, project_id),
            FOREIGN KEY (user_id, project_id) REFERENCES projects (user_id, id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    public function down(): void
    {
        if ($this->fetchRow('SELECT 1 FROM project_agreement_revisions LIMIT 1')) {
            throw new RuntimeException('Preserve project agreement revisions before rolling back.');
        }
        $this->execute('DROP TABLE project_agreement_revisions');
    }
}
