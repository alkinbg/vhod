<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260909150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add Phase 9 General Assembly meeting, agenda, electorate and quorum audit domain.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE general_assembly (id INT AUTO_INCREMENT NOT NULL, initiator_user_id INT DEFAULT NULL, created_by_id INT NOT NULL, convened_by_id INT DEFAULT NULL, started_by_id INT DEFAULT NULL, closed_by_id INT DEFAULT NULL, status VARCHAR(32) NOT NULL, title VARCHAR(180) NOT NULL, scheduled_at DATETIME NOT NULL, timezone_snapshot VARCHAR(64) NOT NULL, reference_date DATE NOT NULL, place VARCHAR(255) NOT NULL, online_meeting_reference VARCHAR(1000) DEFAULT NULL, convening_basis VARCHAR(64) NOT NULL, convening_basis_note LONGTEXT DEFAULT NULL, initiator_display_name VARCHAR(255) NOT NULL, created_at DATETIME NOT NULL, convened_at DATETIME DEFAULT NULL, started_at DATETIME DEFAULT NULL, closed_at DATETIME DEFAULT NULL, quorum_rule_code VARCHAR(80) DEFAULT NULL, quorum_first_call_required_percent NUMERIC(14, 8) DEFAULT NULL, quorum_delayed_call_required_percent NUMERIC(14, 8) DEFAULT NULL, quorum_dominant_owner_trigger_percent NUMERIC(14, 8) DEFAULT NULL, quorum_dominant_owner_required_percent NUMERIC(14, 8) DEFAULT NULL, quorum_legal_basis LONGTEXT DEFAULT NULL, quorum_source_version VARCHAR(120) DEFAULT NULL, quorum_requires_legal_review TINYINT(1) DEFAULT NULL, INDEX idx_general_assembly_initiator_user (initiator_user_id), INDEX idx_general_assembly_created_by (created_by_id), INDEX idx_general_assembly_convened_by (convened_by_id), INDEX idx_general_assembly_started_by (started_by_id), INDEX idx_general_assembly_closed_by (closed_by_id), INDEX idx_general_assembly_status_scheduled (status, scheduled_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        $this->addSql("CREATE TABLE assembly_agenda_item (id INT AUTO_INCREMENT NOT NULL, assembly_id INT NOT NULL, position INT NOT NULL, title VARCHAR(180) NOT NULL, description LONGTEXT DEFAULT NULL, draft_resolution_text LONGTEXT NOT NULL, final_resolution_text LONGTEXT DEFAULT NULL, kind VARCHAR(64) NOT NULL, majority_rule_code VARCHAR(80) NOT NULL, majority_denominator VARCHAR(48) NOT NULL, majority_threshold_percent NUMERIC(14, 8) NOT NULL, majority_comparison VARCHAR(24) NOT NULL, majority_legal_basis LONGTEXT NOT NULL, majority_source_version VARCHAR(120) NOT NULL, majority_requires_legal_review TINYINT(1) DEFAULT 0 NOT NULL, is_emergency TINYINT(1) DEFAULT 0 NOT NULL, emergency_reason LONGTEXT DEFAULT NULL, status VARCHAR(32) NOT NULL, opened_at DATETIME DEFAULT NULL, closed_at DATETIME DEFAULT NULL, UNIQUE INDEX uniq_assembly_agenda_position (assembly_id, position), INDEX idx_assembly_agenda_item_assembly (assembly_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        $this->addSql("CREATE TABLE assembly_electorate_entry (id INT AUTO_INCREMENT NOT NULL, assembly_id INT NOT NULL, unit_id INT DEFAULT NULL, source_relation_id INT DEFAULT NULL, person_id INT DEFAULT NULL, unit_designation_snapshot VARCHAR(64) NOT NULL, principal_type VARCHAR(32) NOT NULL, principal_name_snapshot VARCHAR(255) NOT NULL, principal_identifier_snapshot VARCHAR(128) DEFAULT NULL, relation_type_snapshot VARCHAR(32) NOT NULL, ownership_share_percent_snapshot NUMERIC(14, 8) DEFAULT NULL, unit_ideal_parts_percent_snapshot NUMERIC(14, 8) DEFAULT NULL, represented_ideal_parts_percent_snapshot NUMERIC(14, 8) DEFAULT NULL, quorum_eligible TINYINT(1) DEFAULT 0 NOT NULL, review_reason LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, INDEX idx_electorate_assembly (assembly_id), INDEX idx_electorate_unit (unit_id), INDEX idx_electorate_source_relation (source_relation_id), INDEX idx_electorate_person (person_id), INDEX idx_electorate_assembly_unit (assembly_id, unit_id), INDEX idx_electorate_assembly_person (assembly_id, person_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        $this->addSql("CREATE TABLE assembly_quorum_check (id INT AUTO_INCREMENT NOT NULL, assembly_id INT NOT NULL, checked_by_id INT NOT NULL, kind VARCHAR(32) NOT NULL, checked_at DATETIME NOT NULL, represented_ideal_parts_percent NUMERIC(14, 8) NOT NULL, required_ideal_parts_percent NUMERIC(14, 8) NOT NULL, rule_code VARCHAR(120) NOT NULL, result VARCHAR(32) NOT NULL, explanation LONGTEXT NOT NULL, INDEX idx_quorum_assembly (assembly_id), INDEX idx_quorum_checked_by (checked_by_id), INDEX idx_quorum_assembly_checked (assembly_id, checked_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");

        $this->addSql('ALTER TABLE general_assembly ADD CONSTRAINT fk_general_assembly_initiator_user FOREIGN KEY (initiator_user_id) REFERENCES app_user (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE general_assembly ADD CONSTRAINT fk_general_assembly_created_by FOREIGN KEY (created_by_id) REFERENCES app_user (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE general_assembly ADD CONSTRAINT fk_general_assembly_convened_by FOREIGN KEY (convened_by_id) REFERENCES app_user (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE general_assembly ADD CONSTRAINT fk_general_assembly_started_by FOREIGN KEY (started_by_id) REFERENCES app_user (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE general_assembly ADD CONSTRAINT fk_general_assembly_closed_by FOREIGN KEY (closed_by_id) REFERENCES app_user (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE assembly_agenda_item ADD CONSTRAINT fk_assembly_agenda_item_assembly FOREIGN KEY (assembly_id) REFERENCES general_assembly (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE assembly_electorate_entry ADD CONSTRAINT fk_electorate_assembly FOREIGN KEY (assembly_id) REFERENCES general_assembly (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE assembly_electorate_entry ADD CONSTRAINT fk_electorate_unit FOREIGN KEY (unit_id) REFERENCES property_unit (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE assembly_electorate_entry ADD CONSTRAINT fk_electorate_source_relation FOREIGN KEY (source_relation_id) REFERENCES unit_relation (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE assembly_electorate_entry ADD CONSTRAINT fk_electorate_person FOREIGN KEY (person_id) REFERENCES person (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE assembly_quorum_check ADD CONSTRAINT fk_quorum_assembly FOREIGN KEY (assembly_id) REFERENCES general_assembly (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE assembly_quorum_check ADD CONSTRAINT fk_quorum_checked_by FOREIGN KEY (checked_by_id) REFERENCES app_user (id) ON DELETE RESTRICT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE assembly_quorum_check DROP FOREIGN KEY fk_quorum_assembly');
        $this->addSql('ALTER TABLE assembly_quorum_check DROP FOREIGN KEY fk_quorum_checked_by');
        $this->addSql('ALTER TABLE assembly_electorate_entry DROP FOREIGN KEY fk_electorate_assembly');
        $this->addSql('ALTER TABLE assembly_electorate_entry DROP FOREIGN KEY fk_electorate_unit');
        $this->addSql('ALTER TABLE assembly_electorate_entry DROP FOREIGN KEY fk_electorate_source_relation');
        $this->addSql('ALTER TABLE assembly_electorate_entry DROP FOREIGN KEY fk_electorate_person');
        $this->addSql('ALTER TABLE assembly_agenda_item DROP FOREIGN KEY fk_assembly_agenda_item_assembly');
        $this->addSql('ALTER TABLE general_assembly DROP FOREIGN KEY fk_general_assembly_initiator_user');
        $this->addSql('ALTER TABLE general_assembly DROP FOREIGN KEY fk_general_assembly_created_by');
        $this->addSql('ALTER TABLE general_assembly DROP FOREIGN KEY fk_general_assembly_convened_by');
        $this->addSql('ALTER TABLE general_assembly DROP FOREIGN KEY fk_general_assembly_started_by');
        $this->addSql('ALTER TABLE general_assembly DROP FOREIGN KEY fk_general_assembly_closed_by');

        $this->addSql('DROP TABLE assembly_quorum_check');
        $this->addSql('DROP TABLE assembly_electorate_entry');
        $this->addSql('DROP TABLE assembly_agenda_item');
        $this->addSql('DROP TABLE general_assembly');
    }
}
