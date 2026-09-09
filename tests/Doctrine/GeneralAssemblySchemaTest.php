<?php

declare(strict_types=1);

namespace App\Tests\Doctrine;

use App\Entity\AssemblyAgendaItem;
use App\Entity\AssemblyElectorateEntry;
use App\Entity\AssemblyQuorumCheck;
use App\Entity\GeneralAssembly;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\AssociationMapping;
use Doctrine\ORM\Mapping\ClassMetadata;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class GeneralAssemblySchemaTest extends KernelTestCase
{
    public function testCorePhase9TablesAndNamedIndexesAreStable(): void
    {
        $assembly = $this->metadata(GeneralAssembly::class);
        $agenda = $this->metadata(AssemblyAgendaItem::class);
        $electorate = $this->metadata(AssemblyElectorateEntry::class);
        $quorum = $this->metadata(AssemblyQuorumCheck::class);

        self::assertSame('general_assembly', $assembly->getTableName());
        self::assertSame('assembly_agenda_item', $agenda->getTableName());
        self::assertSame('assembly_electorate_entry', $electorate->getTableName());
        self::assertSame('assembly_quorum_check', $quorum->getTableName());

        self::assertSame(
            ['status', 'scheduled_at'],
            $assembly->table['indexes']['idx_general_assembly_status_scheduled']['columns'] ?? null,
        );
        self::assertSame(
            ['assembly_id', 'position'],
            $agenda->table['uniqueConstraints']['uniq_assembly_agenda_position']['columns'] ?? null,
        );
        self::assertSame(
            ['assembly_id', 'unit_id'],
            $electorate->table['indexes']['idx_electorate_assembly_unit']['columns'] ?? null,
        );
        self::assertSame(
            ['assembly_id', 'person_id'],
            $electorate->table['indexes']['idx_electorate_assembly_person']['columns'] ?? null,
        );
        self::assertSame(
            ['assembly_id', 'checked_at'],
            $quorum->table['indexes']['idx_quorum_assembly_checked']['columns'] ?? null,
        );
    }

    public function testEveryPhase9AuditAndSourceAssociationUsesRestrictWithoutCascadeRemove(): void
    {
        $assembly = $this->metadata(GeneralAssembly::class);
        $agenda = $this->metadata(AssemblyAgendaItem::class);
        $electorate = $this->metadata(AssemblyElectorateEntry::class);
        $quorum = $this->metadata(AssemblyQuorumCheck::class);

        foreach (['initiatorUser', 'createdBy', 'convenedBy', 'startedBy', 'closedBy'] as $associationName) {
            $this->assertToOneRestrict($assembly->getAssociationMapping($associationName));
        }

        $agendaItems = $assembly->getAssociationMapping('agendaItems');
        self::assertFalse($agendaItems->isCascadeRemove());

        $this->assertToOneRestrict($agenda->getAssociationMapping('assembly'));

        foreach (['assembly', 'unit', 'sourceRelation', 'person'] as $associationName) {
            $this->assertToOneRestrict($electorate->getAssociationMapping($associationName));
        }

        $this->assertToOneRestrict($quorum->getAssociationMapping('assembly'));
        $this->assertToOneRestrict($quorum->getAssociationMapping('checkedBy'));
    }

    /** @param class-string $class */
    private function metadata(string $class): ClassMetadata
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        return $entityManager->getClassMetadata($class);
    }

    private function assertToOneRestrict(AssociationMapping $association): void
    {
        self::assertTrue($association->isToOneOwningSide());
        self::assertSame('RESTRICT', $association->joinColumns[0]->onDelete);
        self::assertFalse($association->isCascadeRemove());
    }
}
