<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260908090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create immutable payments and charge allocation records.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE payment (id INT AUTO_INCREMENT NOT NULL, unit_id INT NOT NULL, amount_cents INT NOT NULL, source VARCHAR(255) NOT NULL, received_at DATETIME NOT NULL, posted_at DATETIME NOT NULL, reference VARCHAR(255) DEFAULT NULL, external_reference VARCHAR(190) DEFAULT NULL, note LONGTEXT DEFAULT NULL, UNIQUE INDEX uniq_payment_external_reference (external_reference), INDEX idx_payment_unit_received (unit_id, received_at), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        $this->addSql("CREATE TABLE payment_allocation (id INT AUTO_INCREMENT NOT NULL, payment_id INT NOT NULL, charge_id INT NOT NULL, amount_cents INT NOT NULL, position INT NOT NULL, created_at DATETIME NOT NULL, UNIQUE INDEX uniq_payment_allocation_payment_charge (payment_id, charge_id), INDEX idx_payment_allocation_payment (payment_id), INDEX idx_payment_allocation_charge (charge_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        $this->addSql('ALTER TABLE payment ADD CONSTRAINT FK_PAYMENT_UNIT FOREIGN KEY (unit_id) REFERENCES property_unit (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE payment_allocation ADD CONSTRAINT FK_PAYMENT_ALLOCATION_PAYMENT FOREIGN KEY (payment_id) REFERENCES payment (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE payment_allocation ADD CONSTRAINT FK_PAYMENT_ALLOCATION_CHARGE FOREIGN KEY (charge_id) REFERENCES charge (id) ON DELETE RESTRICT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE payment_allocation DROP FOREIGN KEY FK_PAYMENT_ALLOCATION_PAYMENT');
        $this->addSql('ALTER TABLE payment_allocation DROP FOREIGN KEY FK_PAYMENT_ALLOCATION_CHARGE');
        $this->addSql('ALTER TABLE payment DROP FOREIGN KEY FK_PAYMENT_UNIT');
        $this->addSql('DROP TABLE payment_allocation');
        $this->addSql('DROP TABLE payment');
    }
}
