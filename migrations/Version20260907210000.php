<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260907210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Extend the condominium book and align the foundation schema with Doctrine DBAL 4 metadata.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE unit_relation DROP FOREIGN KEY FK_UNIT_RELATION_PERSON');
        $this->addSql('ALTER TABLE unit_relation MODIFY person_id INT DEFAULT NULL, CHANGE valid_from valid_from DATE NOT NULL, CHANGE valid_until valid_until DATE DEFAULT NULL, ADD legal_entity_name VARCHAR(255) DEFAULT NULL, ADD legal_entity_identifier VARCHAR(64) DEFAULT NULL, ADD management_rights_and_obligations LONGTEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE unit_relation RENAME INDEX idx_unit_relation_person TO IDX_17B7ADDB217BBB47');
        $this->addSql('ALTER TABLE unit_relation RENAME INDEX idx_unit_relation_unit TO IDX_17B7ADDBF8BD700D');
        $this->addSql('ALTER TABLE unit_relation ADD CONSTRAINT FK_UNIT_RELATION_PERSON FOREIGN KEY (person_id) REFERENCES person (id) ON DELETE CASCADE');

        $this->addSql('ALTER TABLE property_unit RENAME INDEX uniq_property_unit_designation TO UNIQ_C3A3B3698947610D');
        $this->addSql('ALTER TABLE app_user RENAME INDEX uniq_app_user_person TO UNIQ_88BDF3E9217BBB47');

        $this->addSql('CREATE TABLE household_member (id INT AUTO_INCREMENT NOT NULL, person_id INT NOT NULL, relation_id INT NOT NULL, valid_from DATE NOT NULL, valid_until DATE DEFAULT NULL, INDEX IDX_59EA3F6D217BBB47 (person_id), INDEX IDX_59EA3F6D3256915B (relation_id), INDEX idx_household_member_period (valid_from, valid_until), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE household_member ADD CONSTRAINT FK_HOUSEHOLD_MEMBER_PERSON FOREIGN KEY (person_id) REFERENCES person (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE household_member ADD CONSTRAINT FK_HOUSEHOLD_MEMBER_RELATION FOREIGN KEY (relation_id) REFERENCES unit_relation (id) ON DELETE CASCADE');

        $this->addSql('CREATE TABLE unit_absence (id INT AUTO_INCREMENT NOT NULL, person_id INT NOT NULL, unit_id INT NOT NULL, valid_from DATE NOT NULL, valid_until DATE DEFAULT NULL, INDEX IDX_2213EE0B217BBB47 (person_id), INDEX IDX_2213EE0BF8BD700D (unit_id), INDEX idx_unit_absence_period (valid_from, valid_until), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE unit_absence ADD CONSTRAINT FK_UNIT_ABSENCE_PERSON FOREIGN KEY (person_id) REFERENCES person (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE unit_absence ADD CONSTRAINT FK_UNIT_ABSENCE_UNIT FOREIGN KEY (unit_id) REFERENCES property_unit (id) ON DELETE CASCADE');

        $this->addSql('CREATE TABLE animal_registration (id INT AUTO_INCREMENT NOT NULL, unit_id INT NOT NULL, species VARCHAR(100) NOT NULL, animal_count INT NOT NULL, veterinary_passport_number VARCHAR(100) DEFAULT NULL, INDEX IDX_D0B2165CF8BD700D (unit_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE animal_registration ADD CONSTRAINT FK_ANIMAL_REGISTRATION_UNIT FOREIGN KEY (unit_id) REFERENCES property_unit (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $legalEntityRelations = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM unit_relation WHERE person_id IS NULL');
        $this->abortIf($legalEntityRelations > 0, 'Cannot roll back: legal-entity unit relations would lose their identity.');

        $this->addSql('ALTER TABLE household_member DROP FOREIGN KEY FK_HOUSEHOLD_MEMBER_PERSON');
        $this->addSql('ALTER TABLE household_member DROP FOREIGN KEY FK_HOUSEHOLD_MEMBER_RELATION');
        $this->addSql('ALTER TABLE unit_absence DROP FOREIGN KEY FK_UNIT_ABSENCE_PERSON');
        $this->addSql('ALTER TABLE unit_absence DROP FOREIGN KEY FK_UNIT_ABSENCE_UNIT');
        $this->addSql('ALTER TABLE animal_registration DROP FOREIGN KEY FK_ANIMAL_REGISTRATION_UNIT');
        $this->addSql('DROP TABLE household_member');
        $this->addSql('DROP TABLE unit_absence');
        $this->addSql('DROP TABLE animal_registration');

        $this->addSql('ALTER TABLE unit_relation DROP FOREIGN KEY FK_UNIT_RELATION_PERSON');
        $this->addSql('ALTER TABLE unit_relation DROP legal_entity_name, DROP legal_entity_identifier, DROP management_rights_and_obligations, MODIFY person_id INT NOT NULL, CHANGE valid_from valid_from DATE NOT NULL COMMENT \'(DC2Type:date_immutable)\', CHANGE valid_until valid_until DATE DEFAULT NULL COMMENT \'(DC2Type:date_immutable)\'');
        $this->addSql('ALTER TABLE unit_relation RENAME INDEX IDX_17B7ADDB217BBB47 TO idx_unit_relation_person');
        $this->addSql('ALTER TABLE unit_relation RENAME INDEX IDX_17B7ADDBF8BD700D TO idx_unit_relation_unit');
        $this->addSql('ALTER TABLE unit_relation ADD CONSTRAINT FK_UNIT_RELATION_PERSON FOREIGN KEY (person_id) REFERENCES person (id) ON DELETE CASCADE');

        $this->addSql('ALTER TABLE property_unit RENAME INDEX UNIQ_C3A3B3698947610D TO uniq_property_unit_designation');
        $this->addSql('ALTER TABLE app_user RENAME INDEX UNIQ_88BDF3E9217BBB47 TO uniq_app_user_person');
    }
}
