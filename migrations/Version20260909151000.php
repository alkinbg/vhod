<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260909151000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add General Assembly attendance, proxy representation and correction audit tables.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE assembly_proxy (id INT AUTO_INCREMENT NOT NULL, assembly_id INT NOT NULL, principal_entry_id INT NOT NULL, representative_person_id INT DEFAULT NULL, evidence_document_id INT NOT NULL, registered_by_id INT NOT NULL, revoked_by_id INT DEFAULT NULL, representative_name VARCHAR(255) NOT NULL, authority_kind VARCHAR(255) NOT NULL, registered_at DATETIME NOT NULL, notes LONGTEXT DEFAULT NULL, revoked_at DATETIME DEFAULT NULL, revocation_reason LONGTEXT DEFAULT NULL, INDEX idx_assembly_proxy_assembly (assembly_id), INDEX idx_assembly_proxy_principal (principal_entry_id), INDEX idx_assembly_proxy_representative_person (representative_person_id), INDEX idx_assembly_proxy_evidence_document (evidence_document_id), INDEX idx_assembly_proxy_registered_by (registered_by_id), INDEX idx_assembly_proxy_revoked_by (revoked_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        $this->addSql("CREATE TABLE assembly_attendance (id INT AUTO_INCREMENT NOT NULL, assembly_id INT NOT NULL, electorate_entry_id INT NOT NULL, representative_person_id INT DEFAULT NULL, registered_by_id INT NOT NULL, mode VARCHAR(32) NOT NULL, representative_name VARCHAR(255) DEFAULT NULL, authority_note LONGTEXT DEFAULT NULL, registered_at DATETIME NOT NULL, left_at DATETIME DEFAULT NULL, UNIQUE INDEX uniq_assembly_attendance_principal (assembly_id, electorate_entry_id), INDEX idx_assembly_attendance_assembly (assembly_id), INDEX idx_assembly_attendance_entry (electorate_entry_id), INDEX idx_assembly_attendance_representative_person (representative_person_id), INDEX idx_assembly_attendance_registered_by (registered_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        $this->addSql("CREATE TABLE assembly_attendance_change (id INT AUTO_INCREMENT NOT NULL, attendance_id INT NOT NULL, changed_by_id INT NOT NULL, old_mode VARCHAR(32) NOT NULL, new_mode VARCHAR(32) NOT NULL, reason LONGTEXT NOT NULL, changed_at DATETIME NOT NULL, INDEX idx_attendance_change_attendance (attendance_id), INDEX idx_attendance_change_changed_by (changed_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");

        $this->addSql('ALTER TABLE assembly_proxy ADD CONSTRAINT fk_assembly_proxy_assembly FOREIGN KEY (assembly_id) REFERENCES general_assembly (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE assembly_proxy ADD CONSTRAINT fk_assembly_proxy_principal FOREIGN KEY (principal_entry_id) REFERENCES assembly_electorate_entry (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE assembly_proxy ADD CONSTRAINT fk_assembly_proxy_representative_person FOREIGN KEY (representative_person_id) REFERENCES person (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE assembly_proxy ADD CONSTRAINT fk_assembly_proxy_evidence_document FOREIGN KEY (evidence_document_id) REFERENCES document (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE assembly_proxy ADD CONSTRAINT fk_assembly_proxy_registered_by FOREIGN KEY (registered_by_id) REFERENCES app_user (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE assembly_proxy ADD CONSTRAINT fk_assembly_proxy_revoked_by FOREIGN KEY (revoked_by_id) REFERENCES app_user (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE assembly_attendance ADD CONSTRAINT fk_assembly_attendance_assembly FOREIGN KEY (assembly_id) REFERENCES general_assembly (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE assembly_attendance ADD CONSTRAINT fk_assembly_attendance_entry FOREIGN KEY (electorate_entry_id) REFERENCES assembly_electorate_entry (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE assembly_attendance ADD CONSTRAINT fk_assembly_attendance_representative_person FOREIGN KEY (representative_person_id) REFERENCES person (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE assembly_attendance ADD CONSTRAINT fk_assembly_attendance_registered_by FOREIGN KEY (registered_by_id) REFERENCES app_user (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE assembly_attendance_change ADD CONSTRAINT fk_attendance_change_attendance FOREIGN KEY (attendance_id) REFERENCES assembly_attendance (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE assembly_attendance_change ADD CONSTRAINT fk_attendance_change_changed_by FOREIGN KEY (changed_by_id) REFERENCES app_user (id) ON DELETE RESTRICT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE assembly_attendance_change DROP FOREIGN KEY fk_attendance_change_attendance');
        $this->addSql('ALTER TABLE assembly_attendance_change DROP FOREIGN KEY fk_attendance_change_changed_by');
        $this->addSql('ALTER TABLE assembly_attendance DROP FOREIGN KEY fk_assembly_attendance_assembly');
        $this->addSql('ALTER TABLE assembly_attendance DROP FOREIGN KEY fk_assembly_attendance_entry');
        $this->addSql('ALTER TABLE assembly_attendance DROP FOREIGN KEY fk_assembly_attendance_representative_person');
        $this->addSql('ALTER TABLE assembly_attendance DROP FOREIGN KEY fk_assembly_attendance_registered_by');
        $this->addSql('ALTER TABLE assembly_proxy DROP FOREIGN KEY fk_assembly_proxy_assembly');
        $this->addSql('ALTER TABLE assembly_proxy DROP FOREIGN KEY fk_assembly_proxy_principal');
        $this->addSql('ALTER TABLE assembly_proxy DROP FOREIGN KEY fk_assembly_proxy_representative_person');
        $this->addSql('ALTER TABLE assembly_proxy DROP FOREIGN KEY fk_assembly_proxy_evidence_document');
        $this->addSql('ALTER TABLE assembly_proxy DROP FOREIGN KEY fk_assembly_proxy_registered_by');
        $this->addSql('ALTER TABLE assembly_proxy DROP FOREIGN KEY fk_assembly_proxy_revoked_by');

        $this->addSql('DROP TABLE assembly_attendance_change');
        $this->addSql('DROP TABLE assembly_attendance');
        $this->addSql('DROP TABLE assembly_proxy');
    }
}
