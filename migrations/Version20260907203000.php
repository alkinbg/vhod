<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260907203000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the foundation identity, property unit and access-control tables.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE person (id INT AUTO_INCREMENT NOT NULL, first_name VARCHAR(100) NOT NULL, middle_name VARCHAR(100) DEFAULT NULL, last_name VARCHAR(100) NOT NULL, email VARCHAR(180) DEFAULT NULL, phone VARCHAR(40) DEFAULT NULL, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE property_unit (id INT AUTO_INCREMENT NOT NULL, designation VARCHAR(32) NOT NULL, type VARCHAR(255) NOT NULL, floor INT DEFAULT NULL, built_area NUMERIC(10, 2) DEFAULT NULL, ideal_parts NUMERIC(7, 4) DEFAULT NULL, active TINYINT(1) DEFAULT 1 NOT NULL, UNIQUE INDEX UNIQ_PROPERTY_UNIT_DESIGNATION (designation), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE unit_relation (id INT AUTO_INCREMENT NOT NULL, person_id INT NOT NULL, unit_id INT NOT NULL, type VARCHAR(255) NOT NULL, valid_from DATE NOT NULL COMMENT \'(DC2Type:date_immutable)\', valid_until DATE DEFAULT NULL COMMENT \'(DC2Type:date_immutable)\', ownership_share NUMERIC(7, 4) DEFAULT NULL, INDEX IDX_UNIT_RELATION_PERSON (person_id), INDEX IDX_UNIT_RELATION_UNIT (unit_id), INDEX idx_unit_relation_period (valid_from, valid_until), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE app_user (id INT AUTO_INCREMENT NOT NULL, person_id INT NOT NULL, email VARCHAR(180) NOT NULL, roles JSON NOT NULL, password VARCHAR(255) NOT NULL, active TINYINT(1) DEFAULT 1 NOT NULL, UNIQUE INDEX UNIQ_APP_USER_PERSON (person_id), UNIQUE INDEX uniq_app_user_email (email), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE unit_relation ADD CONSTRAINT FK_UNIT_RELATION_PERSON FOREIGN KEY (person_id) REFERENCES person (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE unit_relation ADD CONSTRAINT FK_UNIT_RELATION_UNIT FOREIGN KEY (unit_id) REFERENCES property_unit (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE app_user ADD CONSTRAINT FK_APP_USER_PERSON FOREIGN KEY (person_id) REFERENCES person (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE unit_relation DROP FOREIGN KEY FK_UNIT_RELATION_PERSON');
        $this->addSql('ALTER TABLE unit_relation DROP FOREIGN KEY FK_UNIT_RELATION_UNIT');
        $this->addSql('ALTER TABLE app_user DROP FOREIGN KEY FK_APP_USER_PERSON');
        $this->addSql('DROP TABLE unit_relation');
        $this->addSql('DROP TABLE app_user');
        $this->addSql('DROP TABLE property_unit');
        $this->addSql('DROP TABLE person');
    }
}
