<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260909070000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add Phase 8 documents, official announcements and read receipts with named integrity constraints.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE document (id INT AUTO_INCREMENT NOT NULL, category VARCHAR(32) NOT NULL, access_level VARCHAR(24) NOT NULL, title VARCHAR(180) NOT NULL, description LONGTEXT DEFAULT NULL, original_name VARCHAR(255) NOT NULL, storage_name VARCHAR(40) NOT NULL, mime_type VARCHAR(80) NOT NULL, size_bytes INT NOT NULL, uploaded_at DATETIME NOT NULL, uploaded_by_id INT NOT NULL, UNIQUE INDEX uniq_document_storage_name (storage_name), INDEX idx_document_access_category_uploaded (access_level, category, uploaded_at), INDEX idx_document_uploaded_by (uploaded_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        $this->addSql("CREATE TABLE official_announcement (id INT AUTO_INCREMENT NOT NULL, status VARCHAR(24) NOT NULL, title VARCHAR(180) NOT NULL, body LONGTEXT NOT NULL, created_at DATETIME NOT NULL, published_at DATETIME DEFAULT NULL, created_by_id INT NOT NULL, published_by_id INT DEFAULT NULL, INDEX idx_official_announcement_status_published (status, published_at), INDEX idx_official_announcement_created_by (created_by_id), INDEX idx_official_announcement_published_by (published_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        $this->addSql("CREATE TABLE official_announcement_document (announcement_id INT NOT NULL, document_id INT NOT NULL, INDEX idx_official_announcement_document_document (document_id), PRIMARY KEY (announcement_id, document_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        $this->addSql("CREATE TABLE announcement_receipt (id INT AUTO_INCREMENT NOT NULL, available_at DATETIME NOT NULL, read_at DATETIME DEFAULT NULL, announcement_id INT NOT NULL, user_id INT NOT NULL, UNIQUE INDEX uniq_announcement_receipt_announcement_user (announcement_id, user_id), INDEX idx_announcement_receipt_user_read (user_id, read_at), INDEX idx_announcement_receipt_announcement (announcement_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");

        $this->addSql('ALTER TABLE document ADD CONSTRAINT fk_document_uploaded_by FOREIGN KEY (uploaded_by_id) REFERENCES app_user (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE official_announcement ADD CONSTRAINT fk_official_announcement_created_by FOREIGN KEY (created_by_id) REFERENCES app_user (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE official_announcement ADD CONSTRAINT fk_official_announcement_published_by FOREIGN KEY (published_by_id) REFERENCES app_user (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE official_announcement_document ADD CONSTRAINT fk_official_announcement_document_announcement FOREIGN KEY (announcement_id) REFERENCES official_announcement (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE official_announcement_document ADD CONSTRAINT fk_official_announcement_document_document FOREIGN KEY (document_id) REFERENCES document (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE announcement_receipt ADD CONSTRAINT fk_announcement_receipt_announcement FOREIGN KEY (announcement_id) REFERENCES official_announcement (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE announcement_receipt ADD CONSTRAINT fk_announcement_receipt_user FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE RESTRICT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE announcement_receipt DROP FOREIGN KEY fk_announcement_receipt_announcement');
        $this->addSql('ALTER TABLE announcement_receipt DROP FOREIGN KEY fk_announcement_receipt_user');
        $this->addSql('ALTER TABLE official_announcement_document DROP FOREIGN KEY fk_official_announcement_document_announcement');
        $this->addSql('ALTER TABLE official_announcement_document DROP FOREIGN KEY fk_official_announcement_document_document');
        $this->addSql('ALTER TABLE official_announcement DROP FOREIGN KEY fk_official_announcement_created_by');
        $this->addSql('ALTER TABLE official_announcement DROP FOREIGN KEY fk_official_announcement_published_by');
        $this->addSql('ALTER TABLE document DROP FOREIGN KEY fk_document_uploaded_by');

        $this->addSql('DROP TABLE announcement_receipt');
        $this->addSql('DROP TABLE official_announcement_document');
        $this->addSql('DROP TABLE official_announcement');
        $this->addSql('DROP TABLE document');
    }
}
