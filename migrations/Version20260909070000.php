<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260909070000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add base Phase 8 documents, official announcements and read receipts.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE document (id INT AUTO_INCREMENT NOT NULL, category VARCHAR(32) NOT NULL, access_level VARCHAR(24) NOT NULL, title VARCHAR(180) NOT NULL, description LONGTEXT DEFAULT NULL, original_name VARCHAR(255) NOT NULL, storage_name VARCHAR(40) NOT NULL, mime_type VARCHAR(80) NOT NULL, size_bytes INT NOT NULL, uploaded_at DATETIME NOT NULL, uploaded_by_id INT NOT NULL, INDEX IDX_D8698A76A2B28FE8 (uploaded_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        $this->addSql("CREATE TABLE official_announcement (id INT AUTO_INCREMENT NOT NULL, status VARCHAR(24) NOT NULL, title VARCHAR(180) NOT NULL, body LONGTEXT NOT NULL, created_at DATETIME NOT NULL, published_at DATETIME DEFAULT NULL, created_by_id INT NOT NULL, published_by_id INT DEFAULT NULL, INDEX IDX_ADB9FC3DB03A8386 (created_by_id), INDEX IDX_ADB9FC3D5B075477 (published_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        $this->addSql("CREATE TABLE official_announcement_document (announcement_id INT NOT NULL, document_id INT NOT NULL, INDEX IDX_917CE1D5913AEA17 (announcement_id), INDEX IDX_917CE1D5C33F7837 (document_id), PRIMARY KEY (announcement_id, document_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        $this->addSql("CREATE TABLE announcement_receipt (id INT AUTO_INCREMENT NOT NULL, available_at DATETIME NOT NULL, read_at DATETIME DEFAULT NULL, announcement_id INT NOT NULL, user_id INT NOT NULL, INDEX IDX_AF38EB8F913AEA17 (announcement_id), INDEX IDX_AF38EB8FA76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");

        $this->addSql('ALTER TABLE document ADD CONSTRAINT FK_D8698A76A2B28FE8 FOREIGN KEY (uploaded_by_id) REFERENCES app_user (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE official_announcement ADD CONSTRAINT FK_ADB9FC3DB03A8386 FOREIGN KEY (created_by_id) REFERENCES app_user (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE official_announcement ADD CONSTRAINT FK_ADB9FC3D5B075477 FOREIGN KEY (published_by_id) REFERENCES app_user (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE official_announcement_document ADD CONSTRAINT FK_917CE1D5913AEA17 FOREIGN KEY (announcement_id) REFERENCES official_announcement (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE official_announcement_document ADD CONSTRAINT FK_917CE1D5C33F7837 FOREIGN KEY (document_id) REFERENCES document (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE announcement_receipt ADD CONSTRAINT FK_AF38EB8F913AEA17 FOREIGN KEY (announcement_id) REFERENCES official_announcement (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE announcement_receipt ADD CONSTRAINT FK_AF38EB8FA76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE RESTRICT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE announcement_receipt DROP FOREIGN KEY FK_AF38EB8F913AEA17');
        $this->addSql('ALTER TABLE announcement_receipt DROP FOREIGN KEY FK_AF38EB8FA76ED395');
        $this->addSql('ALTER TABLE official_announcement_document DROP FOREIGN KEY FK_917CE1D5913AEA17');
        $this->addSql('ALTER TABLE official_announcement_document DROP FOREIGN KEY FK_917CE1D5C33F7837');
        $this->addSql('ALTER TABLE official_announcement DROP FOREIGN KEY FK_ADB9FC3DB03A8386');
        $this->addSql('ALTER TABLE official_announcement DROP FOREIGN KEY FK_ADB9FC3D5B075477');
        $this->addSql('ALTER TABLE document DROP FOREIGN KEY FK_D8698A76A2B28FE8');

        $this->addSql('DROP TABLE announcement_receipt');
        $this->addSql('DROP TABLE official_announcement_document');
        $this->addSql('DROP TABLE official_announcement');
        $this->addSql('DROP TABLE document');
    }
}
