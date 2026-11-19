<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateProjectAgreementRevisions extends AbstractMigration
{
    public function up(): void
    {
        $this->execute("CREATE TABLE project_agreement_revisions (
            id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
            user_id bigint NOT NULL,
            project_id bigint NOT NULL,
            previous_agreed_amount numeric NOT NULL CHECK (previous_agreed_amount > 0),
            agreed_amount numeric NOT NULL CHECK (agreed_amount > 0 AND agreed_amount < previous_agreed_amount),
            client_paid_total numeric NOT NULL,
            excess_amount numeric NOT NULL CHECK (excess_amount > 0 AND excess_amount = client_paid_total - agreed_amount),
            reason varchar(250) NOT NULL CHECK (btrim(reason) <> ''),
            created_at timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id, project_id) REFERENCES projects (user_id, id)
        )");
        $this->execute('CREATE INDEX project_agreement_revisions_owner_index ON project_agreement_revisions (user_id, project_id)');
    }

    public function down(): void
    {
        $this->execute('LOCK TABLE project_agreement_revisions IN ACCESS EXCLUSIVE MODE');
        if ($this->fetchRow('SELECT 1 FROM project_agreement_revisions LIMIT 1')) {
            throw new RuntimeException('Preserve project agreement revisions before rolling back.');
        }
        $this->execute('DROP TABLE project_agreement_revisions');
    }
}
