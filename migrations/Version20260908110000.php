<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260908110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create condominium bank accounts, statement imports and immutable bank transactions.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE bank_account (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(120) NOT NULL, iban VARCHAR(34) NOT NULL, currency VARCHAR(3) NOT NULL, active TINYINT(1) NOT NULL, UNIQUE INDEX uniq_bank_account_iban (iban), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        $this->addSql("CREATE TABLE bank_statement_import (id INT AUTO_INCREMENT NOT NULL, bank_account_id INT NOT NULL, format VARCHAR(255) NOT NULL, content_hash VARCHAR(64) NOT NULL, imported_at DATETIME NOT NULL, transaction_count INT NOT NULL, source_filename VARCHAR(255) DEFAULT NULL, statement_reference VARCHAR(190) DEFAULT NULL, period_from DATE DEFAULT NULL, period_to DATE DEFAULT NULL, UNIQUE INDEX uniq_bank_statement_import_account_hash (bank_account_id, content_hash), INDEX idx_bank_statement_import_account_date (bank_account_id, imported_at), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        $this->addSql("CREATE TABLE bank_transaction (id INT AUTO_INCREMENT NOT NULL, bank_account_id INT NOT NULL, statement_import_id INT NOT NULL, fingerprint VARCHAR(64) NOT NULL, amount_cents INT NOT NULL, currency VARCHAR(3) NOT NULL, booking_date DATE NOT NULL, value_date DATE DEFAULT NULL, bank_transaction_id VARCHAR(190) DEFAULT NULL, entry_reference VARCHAR(190) DEFAULT NULL, end_to_end_id VARCHAR(190) DEFAULT NULL, counterparty_name VARCHAR(255) DEFAULT NULL, counterparty_iban VARCHAR(34) DEFAULT NULL, remittance_information LONGTEXT DEFAULT NULL, UNIQUE INDEX uniq_bank_transaction_account_fingerprint (bank_account_id, fingerprint), INDEX idx_bank_transaction_account_booking (bank_account_id, booking_date), INDEX idx_bank_transaction_import (statement_import_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");

        $this->addSql('ALTER TABLE bank_statement_import ADD CONSTRAINT FK_BANK_STATEMENT_IMPORT_ACCOUNT FOREIGN KEY (bank_account_id) REFERENCES bank_account (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE bank_transaction ADD CONSTRAINT FK_BANK_TRANSACTION_ACCOUNT FOREIGN KEY (bank_account_id) REFERENCES bank_account (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE bank_transaction ADD CONSTRAINT FK_BANK_TRANSACTION_IMPORT FOREIGN KEY (statement_import_id) REFERENCES bank_statement_import (id) ON DELETE RESTRICT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bank_transaction DROP FOREIGN KEY FK_BANK_TRANSACTION_IMPORT');
        $this->addSql('ALTER TABLE bank_transaction DROP FOREIGN KEY FK_BANK_TRANSACTION_ACCOUNT');
        $this->addSql('ALTER TABLE bank_statement_import DROP FOREIGN KEY FK_BANK_STATEMENT_IMPORT_ACCOUNT');
        $this->addSql('DROP TABLE bank_transaction');
        $this->addSql('DROP TABLE bank_statement_import');
        $this->addSql('DROP TABLE bank_account');
    }
}
