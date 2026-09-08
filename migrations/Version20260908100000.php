<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260908100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create immutable payment reversal records.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE payment_reversal (id INT AUTO_INCREMENT NOT NULL, payment_id INT NOT NULL, amount_cents INT NOT NULL, reason LONGTEXT NOT NULL, reversed_at DATETIME NOT NULL, UNIQUE INDEX uniq_payment_reversal_payment (payment_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        $this->addSql('ALTER TABLE payment_reversal ADD CONSTRAINT FK_PAYMENT_REVERSAL_PAYMENT FOREIGN KEY (payment_id) REFERENCES payment (id) ON DELETE RESTRICT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE payment_reversal DROP FOREIGN KEY FK_PAYMENT_REVERSAL_PAYMENT');
        $this->addSql('DROP TABLE payment_reversal');
    }
}
