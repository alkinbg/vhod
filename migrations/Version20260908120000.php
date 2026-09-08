<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260908120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Prevent cascade deletion of condominium-book identity and historical records.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_user DROP FOREIGN KEY FK_APP_USER_PERSON');
        $this->addSql('ALTER TABLE app_user ADD CONSTRAINT FK_APP_USER_PERSON FOREIGN KEY (person_id) REFERENCES person (id) ON DELETE RESTRICT');

        $this->addSql('ALTER TABLE unit_relation DROP FOREIGN KEY FK_UNIT_RELATION_PERSON');
        $this->addSql('ALTER TABLE unit_relation DROP FOREIGN KEY FK_UNIT_RELATION_UNIT');
        $this->addSql('ALTER TABLE unit_relation ADD CONSTRAINT FK_UNIT_RELATION_PERSON FOREIGN KEY (person_id) REFERENCES person (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE unit_relation ADD CONSTRAINT FK_UNIT_RELATION_UNIT FOREIGN KEY (unit_id) REFERENCES property_unit (id) ON DELETE RESTRICT');

        $this->addSql('ALTER TABLE household_member DROP FOREIGN KEY FK_HOUSEHOLD_MEMBER_PERSON');
        $this->addSql('ALTER TABLE household_member DROP FOREIGN KEY FK_HOUSEHOLD_MEMBER_RELATION');
        $this->addSql('ALTER TABLE household_member ADD CONSTRAINT FK_HOUSEHOLD_MEMBER_PERSON FOREIGN KEY (person_id) REFERENCES person (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE household_member ADD CONSTRAINT FK_HOUSEHOLD_MEMBER_RELATION FOREIGN KEY (relation_id) REFERENCES unit_relation (id) ON DELETE RESTRICT');

        $this->addSql('ALTER TABLE unit_absence DROP FOREIGN KEY FK_UNIT_ABSENCE_PERSON');
        $this->addSql('ALTER TABLE unit_absence DROP FOREIGN KEY FK_UNIT_ABSENCE_UNIT');
        $this->addSql('ALTER TABLE unit_absence ADD CONSTRAINT FK_UNIT_ABSENCE_PERSON FOREIGN KEY (person_id) REFERENCES person (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE unit_absence ADD CONSTRAINT FK_UNIT_ABSENCE_UNIT FOREIGN KEY (unit_id) REFERENCES property_unit (id) ON DELETE RESTRICT');

        $this->addSql('ALTER TABLE animal_registration DROP FOREIGN KEY FK_ANIMAL_REGISTRATION_UNIT');
        $this->addSql('ALTER TABLE animal_registration ADD CONSTRAINT FK_ANIMAL_REGISTRATION_UNIT FOREIGN KEY (unit_id) REFERENCES property_unit (id) ON DELETE RESTRICT');

        $this->addSql('ALTER TABLE book_change_declaration DROP FOREIGN KEY FK_BOOK_DECLARATION_UNIT');
        $this->addSql('ALTER TABLE book_change_declaration DROP FOREIGN KEY FK_BOOK_DECLARATION_SUBMITTER');
        $this->addSql('ALTER TABLE book_change_declaration DROP FOREIGN KEY FK_BOOK_DECLARATION_REVIEWER');
        $this->addSql('ALTER TABLE book_change_declaration ADD CONSTRAINT FK_BOOK_DECLARATION_UNIT FOREIGN KEY (unit_id) REFERENCES property_unit (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE book_change_declaration ADD CONSTRAINT FK_BOOK_DECLARATION_SUBMITTER FOREIGN KEY (submitted_by_id) REFERENCES app_user (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE book_change_declaration ADD CONSTRAINT FK_BOOK_DECLARATION_REVIEWER FOREIGN KEY (reviewed_by_id) REFERENCES app_user (id) ON DELETE RESTRICT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE book_change_declaration DROP FOREIGN KEY FK_BOOK_DECLARATION_UNIT');
        $this->addSql('ALTER TABLE book_change_declaration DROP FOREIGN KEY FK_BOOK_DECLARATION_SUBMITTER');
        $this->addSql('ALTER TABLE book_change_declaration DROP FOREIGN KEY FK_BOOK_DECLARATION_REVIEWER');
        $this->addSql('ALTER TABLE book_change_declaration ADD CONSTRAINT FK_BOOK_DECLARATION_UNIT FOREIGN KEY (unit_id) REFERENCES property_unit (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE book_change_declaration ADD CONSTRAINT FK_BOOK_DECLARATION_SUBMITTER FOREIGN KEY (submitted_by_id) REFERENCES app_user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE book_change_declaration ADD CONSTRAINT FK_BOOK_DECLARATION_REVIEWER FOREIGN KEY (reviewed_by_id) REFERENCES app_user (id) ON DELETE SET NULL');

        $this->addSql('ALTER TABLE animal_registration DROP FOREIGN KEY FK_ANIMAL_REGISTRATION_UNIT');
        $this->addSql('ALTER TABLE animal_registration ADD CONSTRAINT FK_ANIMAL_REGISTRATION_UNIT FOREIGN KEY (unit_id) REFERENCES property_unit (id) ON DELETE CASCADE');

        $this->addSql('ALTER TABLE unit_absence DROP FOREIGN KEY FK_UNIT_ABSENCE_PERSON');
        $this->addSql('ALTER TABLE unit_absence DROP FOREIGN KEY FK_UNIT_ABSENCE_UNIT');
        $this->addSql('ALTER TABLE unit_absence ADD CONSTRAINT FK_UNIT_ABSENCE_PERSON FOREIGN KEY (person_id) REFERENCES person (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE unit_absence ADD CONSTRAINT FK_UNIT_ABSENCE_UNIT FOREIGN KEY (unit_id) REFERENCES property_unit (id) ON DELETE CASCADE');

        $this->addSql('ALTER TABLE household_member DROP FOREIGN KEY FK_HOUSEHOLD_MEMBER_PERSON');
        $this->addSql('ALTER TABLE household_member DROP FOREIGN KEY FK_HOUSEHOLD_MEMBER_RELATION');
        $this->addSql('ALTER TABLE household_member ADD CONSTRAINT FK_HOUSEHOLD_MEMBER_PERSON FOREIGN KEY (person_id) REFERENCES person (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE household_member ADD CONSTRAINT FK_HOUSEHOLD_MEMBER_RELATION FOREIGN KEY (relation_id) REFERENCES unit_relation (id) ON DELETE CASCADE');

        $this->addSql('ALTER TABLE unit_relation DROP FOREIGN KEY FK_UNIT_RELATION_PERSON');
        $this->addSql('ALTER TABLE unit_relation DROP FOREIGN KEY FK_UNIT_RELATION_UNIT');
        $this->addSql('ALTER TABLE unit_relation ADD CONSTRAINT FK_UNIT_RELATION_PERSON FOREIGN KEY (person_id) REFERENCES person (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE unit_relation ADD CONSTRAINT FK_UNIT_RELATION_UNIT FOREIGN KEY (unit_id) REFERENCES property_unit (id) ON DELETE CASCADE');

        $this->addSql('ALTER TABLE app_user DROP FOREIGN KEY FK_APP_USER_PERSON');
        $this->addSql('ALTER TABLE app_user ADD CONSTRAINT FK_APP_USER_PERSON FOREIGN KEY (person_id) REFERENCES person (id) ON DELETE CASCADE');
    }
}
