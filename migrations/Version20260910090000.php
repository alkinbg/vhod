<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260910090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add condominium registry profile, management mandate history and recurring compliance completion history.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE condominium_profile (id INT AUTO_INCREMENT NOT NULL, created_by_id INT NOT NULL, updated_by_id INT NOT NULL, scope_key VARCHAR(32) NOT NULL, registry_identifier VARCHAR(120) DEFAULT NULL, registry_parcel_number VARCHAR(120) DEFAULT NULL, registry_registered_at DATE DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, UNIQUE INDEX uniq_condominium_profile_scope (scope_key), INDEX idx_condominium_profile_created_by (created_by_id), INDEX idx_condominium_profile_updated_by (updated_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        $this->addSql("CREATE TABLE management_mandate (id INT AUTO_INCREMENT NOT NULL, recorded_by_id INT NOT NULL, source_assembly_id INT DEFAULT NULL, kind VARCHAR(32) NOT NULL, holder_label VARCHAR(180) NOT NULL, starts_at DATE NOT NULL, ends_at DATE NOT NULL, recorded_at DATETIME NOT NULL, note LONGTEXT DEFAULT NULL, INDEX idx_management_mandate_end_start (ends_at, starts_at), INDEX idx_management_mandate_recorded_by (recorded_by_id), INDEX idx_management_mandate_source_assembly (source_assembly_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        $this->addSql("CREATE TABLE compliance_completion (id INT AUTO_INCREMENT NOT NULL, recorded_by_id INT NOT NULL, evidence_document_id INT DEFAULT NULL, type VARCHAR(32) NOT NULL, period_key VARCHAR(7) NOT NULL, completed_at DATE NOT NULL, recorded_at DATETIME NOT NULL, note LONGTEXT DEFAULT NULL, UNIQUE INDEX uniq_compliance_completion_type_period (type, period_key), INDEX idx_compliance_completion_completed_at (completed_at), INDEX idx_compliance_completion_recorded_by (recorded_by_id), INDEX idx_compliance_completion_evidence_document (evidence_document_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");

        $this->addSql('ALTER TABLE condominium_profile ADD CONSTRAINT fk_condominium_profile_created_by FOREIGN KEY (created_by_id) REFERENCES app_user (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE condominium_profile ADD CONSTRAINT fk_condominium_profile_updated_by FOREIGN KEY (updated_by_id) REFERENCES app_user (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE management_mandate ADD CONSTRAINT fk_management_mandate_recorded_by FOREIGN KEY (recorded_by_id) REFERENCES app_user (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE management_mandate ADD CONSTRAINT fk_management_mandate_source_assembly FOREIGN KEY (source_assembly_id) REFERENCES general_assembly (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE compliance_completion ADD CONSTRAINT fk_compliance_completion_recorded_by FOREIGN KEY (recorded_by_id) REFERENCES app_user (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE compliance_completion ADD CONSTRAINT fk_compliance_completion_evidence_document FOREIGN KEY (evidence_document_id) REFERENCES document (id) ON DELETE RESTRICT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE compliance_completion DROP FOREIGN KEY fk_compliance_completion_recorded_by');
        $this->addSql('ALTER TABLE compliance_completion DROP FOREIGN KEY fk_compliance_completion_evidence_document');
        $this->addSql('ALTER TABLE management_mandate DROP FOREIGN KEY fk_management_mandate_recorded_by');
        $this->addSql('ALTER TABLE management_mandate DROP FOREIGN KEY fk_management_mandate_source_assembly');
        $this->addSql('ALTER TABLE condominium_profile DROP FOREIGN KEY fk_condominium_profile_created_by');
        $this->addSql('ALTER TABLE condominium_profile DROP FOREIGN KEY fk_condominium_profile_updated_by');

        $this->addSql('DROP TABLE compliance_completion');
        $this->addSql('DROP TABLE management_mandate');
        $this->addSql('DROP TABLE condominium_profile');
    }
}
