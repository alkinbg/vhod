<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260909152000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add formal General Assembly votes, vote corrections and immutable resolutions.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE assembly_vote (id INT AUTO_INCREMENT NOT NULL, agenda_item_id INT NOT NULL, electorate_entry_id INT NOT NULL, recorded_by_id INT NOT NULL, choice VARCHAR(16) NOT NULL, cast_mode VARCHAR(32) NOT NULL, weight_ideal_parts_percent NUMERIC(14, 8) NOT NULL, recorded_at DATETIME NOT NULL, UNIQUE INDEX uniq_assembly_vote_item_entry (agenda_item_id, electorate_entry_id), INDEX idx_vote_item_choice (agenda_item_id, choice), INDEX idx_assembly_vote_entry (electorate_entry_id), INDEX idx_assembly_vote_recorded_by (recorded_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        $this->addSql("CREATE TABLE assembly_vote_correction (id INT AUTO_INCREMENT NOT NULL, vote_id INT NOT NULL, changed_by_id INT NOT NULL, previous_choice VARCHAR(16) NOT NULL, new_choice VARCHAR(16) NOT NULL, reason LONGTEXT NOT NULL, changed_at DATETIME NOT NULL, INDEX idx_vote_correction_vote (vote_id), INDEX idx_vote_correction_changed_by (changed_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        $this->addSql("CREATE TABLE assembly_resolution (id INT AUTO_INCREMENT NOT NULL, agenda_item_id INT NOT NULL, resolved_by_id INT NOT NULL, for_ideal_parts_percent NUMERIC(14, 8) NOT NULL, against_ideal_parts_percent NUMERIC(14, 8) NOT NULL, abstain_ideal_parts_percent NUMERIC(14, 8) NOT NULL, denominator_ideal_parts_percent NUMERIC(14, 8) NOT NULL, required_ideal_parts_percent NUMERIC(14, 8) NOT NULL, result VARCHAR(32) NOT NULL, explanation LONGTEXT NOT NULL, resolved_at DATETIME NOT NULL, UNIQUE INDEX uniq_assembly_resolution_item (agenda_item_id), INDEX idx_assembly_resolution_resolved_by (resolved_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");

        $this->addSql('ALTER TABLE assembly_vote ADD CONSTRAINT fk_assembly_vote_item FOREIGN KEY (agenda_item_id) REFERENCES assembly_agenda_item (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE assembly_vote ADD CONSTRAINT fk_assembly_vote_entry FOREIGN KEY (electorate_entry_id) REFERENCES assembly_electorate_entry (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE assembly_vote ADD CONSTRAINT fk_assembly_vote_recorded_by FOREIGN KEY (recorded_by_id) REFERENCES app_user (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE assembly_vote_correction ADD CONSTRAINT fk_vote_correction_vote FOREIGN KEY (vote_id) REFERENCES assembly_vote (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE assembly_vote_correction ADD CONSTRAINT fk_vote_correction_changed_by FOREIGN KEY (changed_by_id) REFERENCES app_user (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE assembly_resolution ADD CONSTRAINT fk_assembly_resolution_item FOREIGN KEY (agenda_item_id) REFERENCES assembly_agenda_item (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE assembly_resolution ADD CONSTRAINT fk_assembly_resolution_resolved_by FOREIGN KEY (resolved_by_id) REFERENCES app_user (id) ON DELETE RESTRICT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE assembly_vote_correction DROP FOREIGN KEY fk_vote_correction_vote');
        $this->addSql('ALTER TABLE assembly_vote_correction DROP FOREIGN KEY fk_vote_correction_changed_by');
        $this->addSql('ALTER TABLE assembly_resolution DROP FOREIGN KEY fk_assembly_resolution_item');
        $this->addSql('ALTER TABLE assembly_resolution DROP FOREIGN KEY fk_assembly_resolution_resolved_by');
        $this->addSql('ALTER TABLE assembly_vote DROP FOREIGN KEY fk_assembly_vote_item');
        $this->addSql('ALTER TABLE assembly_vote DROP FOREIGN KEY fk_assembly_vote_entry');
        $this->addSql('ALTER TABLE assembly_vote DROP FOREIGN KEY fk_assembly_vote_recorded_by');

        $this->addSql('DROP TABLE assembly_vote_correction');
        $this->addSql('DROP TABLE assembly_resolution');
        $this->addSql('DROP TABLE assembly_vote');
    }
}
