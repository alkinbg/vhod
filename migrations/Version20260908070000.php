<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260908070000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create condominium funds, versioned fee policies, unit rules and immutable monthly charges.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE fund (id INT AUTO_INCREMENT NOT NULL, code VARCHAR(64) NOT NULL, name VARCHAR(150) NOT NULL, type VARCHAR(255) NOT NULL, active TINYINT(1) DEFAULT 1 NOT NULL, UNIQUE INDEX uniq_fund_code (code), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        $this->addSql("CREATE TABLE fee_policy (id INT AUTO_INCREMENT NOT NULL, fund_id INT NOT NULL, code VARCHAR(64) NOT NULL, name VARCHAR(150) NOT NULL, category VARCHAR(255) NOT NULL, distribution VARCHAR(255) NOT NULL, monthly_amount_cents INT NOT NULL, effective_from DATE NOT NULL, effective_until DATE DEFAULT NULL, decision_reference VARCHAR(255) NOT NULL, include_animal_equivalents TINYINT(1) DEFAULT 0 NOT NULL, statutory_minimum_confirmed TINYINT(1) DEFAULT 0 NOT NULL, INDEX IDX_25AE4B9E25A38F89 (fund_id), UNIQUE INDEX uniq_fee_policy_code_start (code, effective_from), INDEX idx_fee_policy_effective (code, effective_from, effective_until), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        $this->addSql("CREATE TABLE fee_policy_unit_rule (id INT AUTO_INCREMENT NOT NULL, fee_policy_id INT NOT NULL, unit_id INT NOT NULL, effective_from DATE NOT NULL, effective_until DATE DEFAULT NULL, reason LONGTEXT NOT NULL, decision_reference VARCHAR(255) DEFAULT NULL, quantity_override NUMERIC(10, 3) DEFAULT NULL, multiplier NUMERIC(6, 3) NOT NULL, UNIQUE INDEX uniq_fee_rule_policy_unit_start (fee_policy_id, unit_id, effective_from), INDEX idx_fee_rule_policy (fee_policy_id), INDEX idx_fee_rule_unit (unit_id), INDEX idx_fee_rule_effective (effective_from, effective_until), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        $this->addSql("CREATE TABLE charge (id INT AUTO_INCREMENT NOT NULL, fee_policy_id INT NOT NULL, unit_id INT NOT NULL, billing_month DATE NOT NULL, quantity NUMERIC(12, 4) NOT NULL, policy_amount_cents INT NOT NULL, amount_cents INT NOT NULL, calculation_details JSON NOT NULL, posted_at DATETIME NOT NULL, UNIQUE INDEX uniq_charge_policy_unit_month (fee_policy_id, unit_id, billing_month), INDEX idx_charge_policy (fee_policy_id), INDEX idx_charge_unit (unit_id), INDEX idx_charge_month (billing_month), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        $this->addSql('ALTER TABLE fee_policy ADD CONSTRAINT FK_FEE_POLICY_FUND FOREIGN KEY (fund_id) REFERENCES fund (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE fee_policy_unit_rule ADD CONSTRAINT FK_FEE_RULE_POLICY FOREIGN KEY (fee_policy_id) REFERENCES fee_policy (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE fee_policy_unit_rule ADD CONSTRAINT FK_FEE_RULE_UNIT FOREIGN KEY (unit_id) REFERENCES property_unit (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE charge ADD CONSTRAINT FK_CHARGE_POLICY FOREIGN KEY (fee_policy_id) REFERENCES fee_policy (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE charge ADD CONSTRAINT FK_CHARGE_UNIT FOREIGN KEY (unit_id) REFERENCES property_unit (id) ON DELETE RESTRICT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE charge DROP FOREIGN KEY FK_CHARGE_POLICY');
        $this->addSql('ALTER TABLE charge DROP FOREIGN KEY FK_CHARGE_UNIT');
        $this->addSql('ALTER TABLE fee_policy_unit_rule DROP FOREIGN KEY FK_FEE_RULE_POLICY');
        $this->addSql('ALTER TABLE fee_policy_unit_rule DROP FOREIGN KEY FK_FEE_RULE_UNIT');
        $this->addSql('ALTER TABLE fee_policy DROP FOREIGN KEY FK_FEE_POLICY_FUND');
        $this->addSql('DROP TABLE charge');
        $this->addSql('DROP TABLE fee_policy_unit_rule');
        $this->addSql('DROP TABLE fee_policy');
        $this->addSql('DROP TABLE fund');
    }
}
