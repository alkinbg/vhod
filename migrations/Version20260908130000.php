<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260908130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create immutable expense and external-income ledgers with full reversals.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE expense (id INT AUTO_INCREMENT NOT NULL, fund_id INT NOT NULL, category VARCHAR(255) NOT NULL, amount_cents INT NOT NULL, paid_at DATETIME NOT NULL, posted_at DATETIME NOT NULL, description VARCHAR(255) NOT NULL, payee VARCHAR(255) DEFAULT NULL, document_reference VARCHAR(190) DEFAULT NULL, note LONGTEXT DEFAULT NULL, INDEX IDX_2F2D73576675F31B (fund_id), INDEX idx_expense_paid_at (paid_at), INDEX idx_expense_fund_paid (fund_id, paid_at), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE expense ADD CONSTRAINT FK_EXPENSE_FUND FOREIGN KEY (fund_id) REFERENCES fund (id) ON DELETE RESTRICT');

        $this->addSql('CREATE TABLE expense_reversal (id INT AUTO_INCREMENT NOT NULL, expense_id INT NOT NULL, amount_cents INT NOT NULL, reason LONGTEXT NOT NULL, reversed_at DATETIME NOT NULL, UNIQUE INDEX uniq_expense_reversal_expense (expense_id), INDEX idx_expense_reversal_reversed_at (reversed_at), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE expense_reversal ADD CONSTRAINT FK_EXPENSE_REVERSAL_EXPENSE FOREIGN KEY (expense_id) REFERENCES expense (id) ON DELETE RESTRICT');

        $this->addSql('CREATE TABLE external_income (id INT AUTO_INCREMENT NOT NULL, fund_id INT NOT NULL, category VARCHAR(255) NOT NULL, amount_cents INT NOT NULL, received_at DATETIME NOT NULL, posted_at DATETIME NOT NULL, description VARCHAR(255) NOT NULL, document_reference VARCHAR(190) DEFAULT NULL, note LONGTEXT DEFAULT NULL, INDEX IDX_6B7352FD6675F31B (fund_id), INDEX idx_external_income_received_at (received_at), INDEX idx_external_income_fund_received (fund_id, received_at), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE external_income ADD CONSTRAINT FK_EXTERNAL_INCOME_FUND FOREIGN KEY (fund_id) REFERENCES fund (id) ON DELETE RESTRICT');

        $this->addSql('CREATE TABLE external_income_reversal (id INT AUTO_INCREMENT NOT NULL, external_income_id INT NOT NULL, amount_cents INT NOT NULL, reason LONGTEXT NOT NULL, reversed_at DATETIME NOT NULL, UNIQUE INDEX uniq_external_income_reversal_income (external_income_id), INDEX idx_external_income_reversal_reversed_at (reversed_at), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE external_income_reversal ADD CONSTRAINT FK_EXTERNAL_INCOME_REVERSAL_INCOME FOREIGN KEY (external_income_id) REFERENCES external_income (id) ON DELETE RESTRICT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE expense_reversal DROP FOREIGN KEY FK_EXPENSE_REVERSAL_EXPENSE');
        $this->addSql('ALTER TABLE external_income_reversal DROP FOREIGN KEY FK_EXTERNAL_INCOME_REVERSAL_INCOME');
        $this->addSql('ALTER TABLE expense DROP FOREIGN KEY FK_EXPENSE_FUND');
        $this->addSql('ALTER TABLE external_income DROP FOREIGN KEY FK_EXTERNAL_INCOME_FUND');
        $this->addSql('DROP TABLE expense_reversal');
        $this->addSql('DROP TABLE external_income_reversal');
        $this->addSql('DROP TABLE expense');
        $this->addSql('DROP TABLE external_income');
    }
}
