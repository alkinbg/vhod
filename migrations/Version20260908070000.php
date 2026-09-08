<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260908070000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create condominium funds and versioned monthly fee policies.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE fund (id INT AUTO_INCREMENT NOT NULL, code VARCHAR(64) NOT NULL, name VARCHAR(150) NOT NULL, type VARCHAR(255) NOT NULL, active TINYINT(1) DEFAULT 1 NOT NULL, UNIQUE INDEX uniq_fund_code (code), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        $this->addSql("CREATE TABLE fee_policy (id INT AUTO_INCREMENT NOT NULL, fund_id INT NOT NULL, code VARCHAR(64) NOT NULL, name VARCHAR(150) NOT NULL, category VARCHAR(255) NOT NULL, distribution VARCHAR(255) NOT NULL, monthly_amount_cents INT NOT NULL, effective_from DATE NOT NULL, effective_until DATE DEFAULT NULL, decision_reference VARCHAR(255) NOT NULL, include_animal_equivalents TINYINT(1) DEFAULT 0 NOT NULL, statutory_minimum_confirmed TINYINT(1) DEFAULT 0 NOT NULL, INDEX IDX_FEE_POLICY_FUND (fund_id), UNIQUE INDEX uniq_fee_policy_code_start (code, effective_from), INDEX idx_fee_policy_effective (code, effective_from, effective_until), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        $this->addSql('ALTER TABLE fee_policy ADD CONSTRAINT FK_FEE_POLICY_FUND FOREIGN KEY (fund_id) REFERENCES fund (id) ON DELETE RESTRICT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE fee_policy DROP FOREIGN KEY FK_FEE_POLICY_FUND');
        $this->addSql('DROP TABLE fee_policy');
        $this->addSql('DROP TABLE fund');
    }
}
