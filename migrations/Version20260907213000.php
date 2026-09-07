<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260907213000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the electronic condominium-book declaration and review history table.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE book_change_declaration (id INT AUTO_INCREMENT NOT NULL, unit_id INT NOT NULL, submitted_by_id INT NOT NULL, reviewed_by_id INT DEFAULT NULL, type VARCHAR(255) NOT NULL, payload JSON NOT NULL, status VARCHAR(255) NOT NULL, submitted_at DATETIME NOT NULL, reviewed_at DATETIME DEFAULT NULL, review_note LONGTEXT DEFAULT NULL, INDEX IDX_2C16281AF8BD700D (unit_id), INDEX IDX_2C16281A79F7D87D (submitted_by_id), INDEX IDX_2C16281AFC6B21F1 (reviewed_by_id), INDEX idx_book_declaration_queue (status, submitted_at), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE book_change_declaration ADD CONSTRAINT FK_BOOK_DECLARATION_UNIT FOREIGN KEY (unit_id) REFERENCES property_unit (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE book_change_declaration ADD CONSTRAINT FK_BOOK_DECLARATION_SUBMITTER FOREIGN KEY (submitted_by_id) REFERENCES app_user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE book_change_declaration ADD CONSTRAINT FK_BOOK_DECLARATION_REVIEWER FOREIGN KEY (reviewed_by_id) REFERENCES app_user (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE book_change_declaration DROP FOREIGN KEY FK_BOOK_DECLARATION_UNIT');
        $this->addSql('ALTER TABLE book_change_declaration DROP FOREIGN KEY FK_BOOK_DECLARATION_SUBMITTER');
        $this->addSql('ALTER TABLE book_change_declaration DROP FOREIGN KEY FK_BOOK_DECLARATION_REVIEWER');
        $this->addSql('DROP TABLE book_change_declaration');
    }
}
