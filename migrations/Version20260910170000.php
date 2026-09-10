<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260910170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Effective-date animal registrations; legacy rows start at 2026-09-01 because earlier animal history is unknown.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE animal_registration ADD valid_from DATE DEFAULT NULL, ADD valid_until DATE DEFAULT NULL');
        $this->addSql("UPDATE animal_registration SET valid_from = '2026-09-01' WHERE valid_from IS NULL");
        $this->addSql('ALTER TABLE animal_registration MODIFY valid_from DATE NOT NULL');
        $this->addSql('CREATE INDEX idx_animal_registration_unit_period ON animal_registration (unit_id, valid_from, valid_until)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_animal_registration_unit_period ON animal_registration');
        $this->addSql('ALTER TABLE animal_registration DROP valid_from, DROP valid_until');
    }
}
