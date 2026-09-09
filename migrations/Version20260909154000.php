<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260909154000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add General Assembly minutes finalization metadata and append-only corrections.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE general_assembly ADD chairperson_name_snapshot VARCHAR(255) DEFAULT NULL, ADD secretary_name_snapshot VARCHAR(255) DEFAULT NULL, ADD formal_notes LONGTEXT DEFAULT NULL, ADD minutes_finalized_by_id INT DEFAULT NULL, ADD minutes_finalized_at DATETIME DEFAULT NULL, ADD minutes_due_on DATE DEFAULT NULL, ADD minutes_document_id INT DEFAULT NULL, ADD INDEX idx_general_assembly_minutes_finalized_by (minutes_finalized_by_id), ADD INDEX idx_general_assembly_minutes_document (minutes_document_id)');
        $this->addSql('ALTER TABLE general_assembly ADD CONSTRAINT fk_general_assembly_minutes_finalized_by FOREIGN KEY (minutes_finalized_by_id) REFERENCES app_user (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE general_assembly ADD CONSTRAINT fk_general_assembly_minutes_document FOREIGN KEY (minutes_document_id) REFERENCES document (id) ON DELETE RESTRICT');

        $this->addSql("CREATE TABLE assembly_minutes_correction (id INT AUTO_INCREMENT NOT NULL, assembly_id INT NOT NULL, document_id INT NOT NULL, recorded_by_id INT NOT NULL, reason LONGTEXT NOT NULL, recorded_at DATETIME NOT NULL, INDEX idx_minutes_correction_assembly (assembly_id), INDEX idx_minutes_correction_document (document_id), INDEX idx_minutes_correction_recorded_by (recorded_by_id), INDEX idx_minutes_correction_recorded_at (recorded_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        $this->addSql('ALTER TABLE assembly_minutes_correction ADD CONSTRAINT fk_minutes_correction_assembly FOREIGN KEY (assembly_id) REFERENCES general_assembly (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE assembly_minutes_correction ADD CONSTRAINT fk_minutes_correction_document FOREIGN KEY (document_id) REFERENCES document (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE assembly_minutes_correction ADD CONSTRAINT fk_minutes_correction_recorded_by FOREIGN KEY (recorded_by_id) REFERENCES app_user (id) ON DELETE RESTRICT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE assembly_minutes_correction DROP FOREIGN KEY fk_minutes_correction_assembly');
        $this->addSql('ALTER TABLE assembly_minutes_correction DROP FOREIGN KEY fk_minutes_correction_document');
        $this->addSql('ALTER TABLE assembly_minutes_correction DROP FOREIGN KEY fk_minutes_correction_recorded_by');
        $this->addSql('DROP TABLE assembly_minutes_correction');

        $this->addSql('ALTER TABLE general_assembly DROP FOREIGN KEY fk_general_assembly_minutes_finalized_by');
        $this->addSql('ALTER TABLE general_assembly DROP FOREIGN KEY fk_general_assembly_minutes_document');
        $this->addSql('ALTER TABLE general_assembly DROP INDEX idx_general_assembly_minutes_finalized_by, DROP INDEX idx_general_assembly_minutes_document, DROP chairperson_name_snapshot, DROP secretary_name_snapshot, DROP formal_notes, DROP minutes_finalized_by_id, DROP minutes_finalized_at, DROP minutes_due_on, DROP minutes_document_id');
    }
}
