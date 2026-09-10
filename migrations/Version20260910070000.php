<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260910070000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add compound General Assembly proxy representative lookup index.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE assembly_proxy ADD INDEX idx_proxy_assembly_representative (assembly_id, representative_person_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE assembly_proxy DROP INDEX idx_proxy_assembly_representative');
    }
}
