<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260908140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add finance-management budget planning and expense decision references.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE expense ADD decision_reference VARCHAR(190) DEFAULT NULL');
        $this->addSql('CREATE TABLE budget_line (id INT AUTO_INCREMENT NOT NULL, fund_id INT NOT NULL, budget_year INT NOT NULL, category VARCHAR(255) NOT NULL, amount_cents INT NOT NULL, decision_reference VARCHAR(190) NOT NULL, posted_at DATETIME NOT NULL, INDEX IDX_ABD0B6A625A38F89 (fund_id), UNIQUE INDEX uniq_budget_year_fund_category (budget_year, fund_id, category), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE budget_line ADD CONSTRAINT FK_BUDGET_LINE_FUND FOREIGN KEY (fund_id) REFERENCES fund (id) ON DELETE RESTRICT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE budget_line DROP FOREIGN KEY FK_BUDGET_LINE_FUND');
        $this->addSql('DROP TABLE budget_line');
        $this->addSql('ALTER TABLE expense DROP decision_reference');
    }
}
