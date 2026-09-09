<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260908200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add maintenance signals, private attachments, assets, suppliers, contracts and service events.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE building_asset (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(160) NOT NULL, category VARCHAR(32) NOT NULL, location VARCHAR(180) NOT NULL, manufacturer VARCHAR(160) DEFAULT NULL, model VARCHAR(160) DEFAULT NULL, serial_number VARCHAR(160) DEFAULT NULL, installed_at DATETIME DEFAULT NULL, warranty_until DATETIME DEFAULT NULL, inspection_interval_months INT DEFAULT NULL, next_inspection_at DATETIME DEFAULT NULL, active TINYINT DEFAULT 1 NOT NULL, INDEX idx_building_asset_active_name (active, name), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        $this->addSql("CREATE TABLE maintenance_supplier (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(180) NOT NULL, registration_number VARCHAR(64) DEFAULT NULL, contact_person VARCHAR(160) DEFAULT NULL, email VARCHAR(180) DEFAULT NULL, phone VARCHAR(80) DEFAULT NULL, address VARCHAR(255) DEFAULT NULL, note LONGTEXT DEFAULT NULL, active TINYINT DEFAULT 1 NOT NULL, INDEX idx_maintenance_supplier_active_name (active, name), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        $this->addSql("CREATE TABLE maintenance_signal (id INT AUTO_INCREMENT NOT NULL, category VARCHAR(32) NOT NULL, priority VARCHAR(16) NOT NULL, status VARCHAR(24) NOT NULL, title VARCHAR(180) NOT NULL, description LONGTEXT NOT NULL, location VARCHAR(180) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, submitted_by_id INT NOT NULL, asset_id INT DEFAULT NULL, assigned_to_id INT DEFAULT NULL, INDEX idx_maintenance_signal_submitted_by (submitted_by_id), INDEX idx_maintenance_signal_asset (asset_id), INDEX idx_maintenance_signal_assigned_to (assigned_to_id), INDEX idx_maintenance_signal_status_created (status, created_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        $this->addSql("CREATE TABLE maintenance_signal_status_change (id INT AUTO_INCREMENT NOT NULL, from_status VARCHAR(24) DEFAULT NULL, to_status VARCHAR(24) NOT NULL, changed_at DATETIME NOT NULL, note LONGTEXT NOT NULL, signal_id INT NOT NULL, changed_by_id INT NOT NULL, INDEX idx_maintenance_signal_status_change_signal (signal_id), INDEX idx_maintenance_signal_status_change_changed_by (changed_by_id), INDEX idx_maintenance_signal_status_change_at (changed_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        $this->addSql("CREATE TABLE maintenance_contract (id INT AUTO_INCREMENT NOT NULL, title VARCHAR(180) NOT NULL, reference VARCHAR(120) DEFAULT NULL, starts_at DATETIME NOT NULL, ends_at DATETIME DEFAULT NULL, note LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, supplier_id INT NOT NULL, asset_id INT DEFAULT NULL, INDEX idx_maintenance_contract_supplier (supplier_id), INDEX idx_maintenance_contract_asset (asset_id), INDEX idx_maintenance_contract_starts_at (starts_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        $this->addSql("CREATE TABLE maintenance_event (id INT AUTO_INCREMENT NOT NULL, type VARCHAR(32) NOT NULL, performed_at DATETIME NOT NULL, summary VARCHAR(255) NOT NULL, note LONGTEXT DEFAULT NULL, next_inspection_at DATETIME DEFAULT NULL, recorded_at DATETIME NOT NULL, asset_id INT NOT NULL, supplier_id INT DEFAULT NULL, contract_id INT DEFAULT NULL, signal_id INT DEFAULT NULL, recorded_by_id INT NOT NULL, INDEX idx_maintenance_event_asset (asset_id), INDEX idx_maintenance_event_supplier (supplier_id), INDEX idx_maintenance_event_contract (contract_id), INDEX idx_maintenance_event_signal (signal_id), INDEX idx_maintenance_event_recorded_by (recorded_by_id), INDEX idx_maintenance_event_performed_at (performed_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        $this->addSql("CREATE TABLE maintenance_attachment (id INT AUTO_INCREMENT NOT NULL, original_name VARCHAR(255) NOT NULL, storage_name VARCHAR(40) NOT NULL, mime_type VARCHAR(80) NOT NULL, size_bytes INT NOT NULL, uploaded_at DATETIME NOT NULL, signal_id INT NOT NULL, uploaded_by_id INT NOT NULL, UNIQUE INDEX UNIQ_5E185935570EB513 (storage_name), INDEX idx_maintenance_attachment_signal (signal_id), INDEX idx_maintenance_attachment_uploaded_by (uploaded_by_id), INDEX idx_maintenance_attachment_uploaded_at (uploaded_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");

        $this->addSql('ALTER TABLE maintenance_signal ADD CONSTRAINT FK_5EF1A3FC79F7D87D FOREIGN KEY (submitted_by_id) REFERENCES app_user (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE maintenance_signal ADD CONSTRAINT FK_5EF1A3FC5DA1941 FOREIGN KEY (asset_id) REFERENCES building_asset (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE maintenance_signal ADD CONSTRAINT FK_5EF1A3FCF4BD7827 FOREIGN KEY (assigned_to_id) REFERENCES app_user (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE maintenance_signal_status_change ADD CONSTRAINT FK_4A9A8DBCD0CE460B FOREIGN KEY (signal_id) REFERENCES maintenance_signal (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE maintenance_signal_status_change ADD CONSTRAINT FK_4A9A8DBC828AD0A0 FOREIGN KEY (changed_by_id) REFERENCES app_user (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE maintenance_contract ADD CONSTRAINT FK_F7F72C742ADD6D8C FOREIGN KEY (supplier_id) REFERENCES maintenance_supplier (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE maintenance_contract ADD CONSTRAINT FK_F7F72C745DA1941 FOREIGN KEY (asset_id) REFERENCES building_asset (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE maintenance_event ADD CONSTRAINT FK_A9B3975C5DA1941 FOREIGN KEY (asset_id) REFERENCES building_asset (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE maintenance_event ADD CONSTRAINT FK_A9B3975C2ADD6D8C FOREIGN KEY (supplier_id) REFERENCES maintenance_supplier (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE maintenance_event ADD CONSTRAINT FK_A9B3975C2576E0FD FOREIGN KEY (contract_id) REFERENCES maintenance_contract (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE maintenance_event ADD CONSTRAINT FK_A9B3975CD0CE460B FOREIGN KEY (signal_id) REFERENCES maintenance_signal (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE maintenance_event ADD CONSTRAINT FK_A9B3975CD05A957B FOREIGN KEY (recorded_by_id) REFERENCES app_user (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE maintenance_attachment ADD CONSTRAINT FK_5E185935D0CE460B FOREIGN KEY (signal_id) REFERENCES maintenance_signal (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE maintenance_attachment ADD CONSTRAINT FK_5E185935A2B28FE8 FOREIGN KEY (uploaded_by_id) REFERENCES app_user (id) ON DELETE RESTRICT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE maintenance_attachment DROP FOREIGN KEY FK_5E185935D0CE460B');
        $this->addSql('ALTER TABLE maintenance_attachment DROP FOREIGN KEY FK_5E185935A2B28FE8');
        $this->addSql('ALTER TABLE maintenance_event DROP FOREIGN KEY FK_A9B3975C5DA1941');
        $this->addSql('ALTER TABLE maintenance_event DROP FOREIGN KEY FK_A9B3975C2ADD6D8C');
        $this->addSql('ALTER TABLE maintenance_event DROP FOREIGN KEY FK_A9B3975C2576E0FD');
        $this->addSql('ALTER TABLE maintenance_event DROP FOREIGN KEY FK_A9B3975CD0CE460B');
        $this->addSql('ALTER TABLE maintenance_event DROP FOREIGN KEY FK_A9B3975CD05A957B');
        $this->addSql('ALTER TABLE maintenance_contract DROP FOREIGN KEY FK_F7F72C742ADD6D8C');
        $this->addSql('ALTER TABLE maintenance_contract DROP FOREIGN KEY FK_F7F72C745DA1941');
        $this->addSql('ALTER TABLE maintenance_signal_status_change DROP FOREIGN KEY FK_4A9A8DBCD0CE460B');
        $this->addSql('ALTER TABLE maintenance_signal_status_change DROP FOREIGN KEY FK_4A9A8DBC828AD0A0');
        $this->addSql('ALTER TABLE maintenance_signal DROP FOREIGN KEY FK_5EF1A3FC79F7D87D');
        $this->addSql('ALTER TABLE maintenance_signal DROP FOREIGN KEY FK_5EF1A3FC5DA1941');
        $this->addSql('ALTER TABLE maintenance_signal DROP FOREIGN KEY FK_5EF1A3FCF4BD7827');

        $this->addSql('DROP TABLE maintenance_attachment');
        $this->addSql('DROP TABLE maintenance_event');
        $this->addSql('DROP TABLE maintenance_contract');
        $this->addSql('DROP TABLE maintenance_signal_status_change');
        $this->addSql('DROP TABLE maintenance_signal');
        $this->addSql('DROP TABLE maintenance_supplier');
        $this->addSql('DROP TABLE building_asset');
    }
}
