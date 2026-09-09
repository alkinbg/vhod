<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260909153000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add evidence-backed General Assembly absentee voting windows and declarations.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE assembly_absentee_window (id INT AUTO_INCREMENT NOT NULL, assembly_id INT NOT NULL, opened_by_id INT NOT NULL, closed_by_id INT DEFAULT NULL, opened_at DATETIME NOT NULL, deadline_at DATETIME NOT NULL, legal_basis LONGTEXT NOT NULL, closed_at DATETIME DEFAULT NULL, UNIQUE INDEX uniq_absentee_window_assembly (assembly_id), INDEX idx_absentee_deadline (deadline_at), INDEX idx_absentee_window_opened_by (opened_by_id), INDEX idx_absentee_window_closed_by (closed_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        $this->addSql("CREATE TABLE assembly_absentee_window_item (window_id INT NOT NULL, agenda_item_id INT NOT NULL, INDEX idx_absentee_window_item_item (agenda_item_id), PRIMARY KEY (window_id, agenda_item_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        $this->addSql("CREATE TABLE assembly_absentee_declaration (id INT AUTO_INCREMENT NOT NULL, assembly_id INT NOT NULL, window_id INT NOT NULL, electorate_entry_id INT NOT NULL, evidence_document_id INT NOT NULL, registered_by_id INT NOT NULL, signature_mode_snapshot VARCHAR(48) NOT NULL, submitted_at DATETIME NOT NULL, notes LONGTEXT DEFAULT NULL, UNIQUE INDEX uniq_absentee_declaration_window_entry (window_id, electorate_entry_id), INDEX idx_absentee_declaration_assembly (assembly_id), INDEX idx_absentee_declaration_evidence (evidence_document_id), INDEX idx_absentee_declaration_registered_by (registered_by_id), INDEX idx_absentee_declaration_submitted (submitted_at), INDEX idx_absentee_declaration_entry (electorate_entry_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        $this->addSql("CREATE TABLE assembly_absentee_declaration_vote (id INT AUTO_INCREMENT NOT NULL, declaration_id INT NOT NULL, agenda_item_id INT NOT NULL, choice VARCHAR(16) NOT NULL, UNIQUE INDEX uniq_absentee_declaration_vote_item (declaration_id, agenda_item_id), INDEX idx_absentee_declaration_vote_item (agenda_item_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");

        $this->addSql('ALTER TABLE assembly_absentee_window ADD CONSTRAINT fk_absentee_window_assembly FOREIGN KEY (assembly_id) REFERENCES general_assembly (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE assembly_absentee_window ADD CONSTRAINT fk_absentee_window_opened_by FOREIGN KEY (opened_by_id) REFERENCES app_user (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE assembly_absentee_window ADD CONSTRAINT fk_absentee_window_closed_by FOREIGN KEY (closed_by_id) REFERENCES app_user (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE assembly_absentee_window_item ADD CONSTRAINT fk_absentee_window_item_window FOREIGN KEY (window_id) REFERENCES assembly_absentee_window (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE assembly_absentee_window_item ADD CONSTRAINT fk_absentee_window_item_item FOREIGN KEY (agenda_item_id) REFERENCES assembly_agenda_item (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE assembly_absentee_declaration ADD CONSTRAINT fk_absentee_declaration_assembly FOREIGN KEY (assembly_id) REFERENCES general_assembly (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE assembly_absentee_declaration ADD CONSTRAINT fk_absentee_declaration_window FOREIGN KEY (window_id) REFERENCES assembly_absentee_window (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE assembly_absentee_declaration ADD CONSTRAINT fk_absentee_declaration_entry FOREIGN KEY (electorate_entry_id) REFERENCES assembly_electorate_entry (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE assembly_absentee_declaration ADD CONSTRAINT fk_absentee_declaration_evidence FOREIGN KEY (evidence_document_id) REFERENCES document (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE assembly_absentee_declaration ADD CONSTRAINT fk_absentee_declaration_registered_by FOREIGN KEY (registered_by_id) REFERENCES app_user (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE assembly_absentee_declaration_vote ADD CONSTRAINT fk_absentee_declaration_vote_declaration FOREIGN KEY (declaration_id) REFERENCES assembly_absentee_declaration (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE assembly_absentee_declaration_vote ADD CONSTRAINT fk_absentee_declaration_vote_item FOREIGN KEY (agenda_item_id) REFERENCES assembly_agenda_item (id) ON DELETE RESTRICT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE assembly_absentee_declaration_vote DROP FOREIGN KEY fk_absentee_declaration_vote_declaration');
        $this->addSql('ALTER TABLE assembly_absentee_declaration_vote DROP FOREIGN KEY fk_absentee_declaration_vote_item');
        $this->addSql('ALTER TABLE assembly_absentee_declaration DROP FOREIGN KEY fk_absentee_declaration_assembly');
        $this->addSql('ALTER TABLE assembly_absentee_declaration DROP FOREIGN KEY fk_absentee_declaration_window');
        $this->addSql('ALTER TABLE assembly_absentee_declaration DROP FOREIGN KEY fk_absentee_declaration_entry');
        $this->addSql('ALTER TABLE assembly_absentee_declaration DROP FOREIGN KEY fk_absentee_declaration_evidence');
        $this->addSql('ALTER TABLE assembly_absentee_declaration DROP FOREIGN KEY fk_absentee_declaration_registered_by');
        $this->addSql('ALTER TABLE assembly_absentee_window_item DROP FOREIGN KEY fk_absentee_window_item_window');
        $this->addSql('ALTER TABLE assembly_absentee_window_item DROP FOREIGN KEY fk_absentee_window_item_item');
        $this->addSql('ALTER TABLE assembly_absentee_window DROP FOREIGN KEY fk_absentee_window_assembly');
        $this->addSql('ALTER TABLE assembly_absentee_window DROP FOREIGN KEY fk_absentee_window_opened_by');
        $this->addSql('ALTER TABLE assembly_absentee_window DROP FOREIGN KEY fk_absentee_window_closed_by');

        $this->addSql('DROP TABLE assembly_absentee_declaration_vote');
        $this->addSql('DROP TABLE assembly_absentee_declaration');
        $this->addSql('DROP TABLE assembly_absentee_window_item');
        $this->addSql('DROP TABLE assembly_absentee_window');
    }
}
