<?php

declare(strict_types=1);

namespace App\Tests\Doctrine;

use App\Entity\AnimalRegistration;
use App\Entity\BookChangeDeclaration;
use App\Entity\HouseholdMember;
use App\Entity\UnitAbsence;
use App\Entity\UnitRelation;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ToOneOwningSideMapping;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class HistoricalForeignKeyPolicyTest extends KernelTestCase
{
    public function testHistoricalAndIdentityAssociationsRestrictHardDeletion(): void
    {
        self::bootKernel();
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $expected = [
            User::class => ['person'],
            UnitRelation::class => ['person', 'unit'],
            HouseholdMember::class => ['person', 'relation'],
            UnitAbsence::class => ['person', 'unit'],
            AnimalRegistration::class => ['unit'],
            BookChangeDeclaration::class => ['unit', 'submittedBy', 'reviewedBy'],
        ];

        foreach ($expected as $entityClass => $fields) {
            $metadata = $entityManager->getClassMetadata($entityClass);

            foreach ($fields as $field) {
                $mapping = $metadata->getAssociationMapping($field);
                self::assertInstanceOf(ToOneOwningSideMapping::class, $mapping);
                self::assertNotEmpty($mapping->joinColumns);
                self::assertSame(
                    'RESTRICT',
                    $mapping->joinColumns[0]->onDelete,
                    sprintf('%s::%s must preserve historical records on hard delete.', $entityClass, $field),
                );
            }
        }
    }
}
