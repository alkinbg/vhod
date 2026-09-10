<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260910100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add append-only audit trail for meaningful security, finance and governance actions.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE audit_entry (id INT AUTO_INCREMENT NOT NULL, actor_id INT DEFAULT NULL, actor_identifier VARCHAR(180) NOT NULL, action VARCHAR(100) NOT NULL, subject_type VARCHAR(120) NOT NULL, subject_id INT DEFAULT NULL, context JSON NOT NULL, occurred_at DATETIME NOT NULL, INDEX idx_audit_entry_occurred_at (occurred_at), INDEX idx_audit_entry_action (action), INDEX idx_audit_entry_subject (subject_type, subject_id), INDEX idx_audit_entry_actor (actor_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        $this->addSql('ALTER TABLE audit_entry ADD CONSTRAINT fk_audit_entry_actor FOREIGN KEY (actor_id) REFERENCES app_user (id) ON DELETE RESTRICT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE audit_entry DROP FOREIGN KEY fk_audit_entry_actor');
        $this->addSql('DROP TABLE audit_entry');
    }
}
