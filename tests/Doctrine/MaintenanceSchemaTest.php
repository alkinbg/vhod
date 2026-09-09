<?php

declare(strict_types=1);

namespace App\Tests\Doctrine;

use App\Entity\MaintenanceAttachment;
use App\Entity\MaintenanceContract;
use App\Entity\MaintenanceEvent;
use App\Entity\MaintenanceSignal;
use App\Entity\MaintenanceSignalStatusChange;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ToOneOwningSideMapping;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class MaintenanceSchemaTest extends KernelTestCase
{
    public function testMaintenanceAssociationsRestrictHardDeletion(): void
    {
        self::bootKernel();
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $associations = [
            [MaintenanceSignal::class, 'submittedBy'],
            [MaintenanceSignal::class, 'asset'],
            [MaintenanceSignal::class, 'assignedTo'],
            [MaintenanceSignalStatusChange::class, 'signal'],
            [MaintenanceSignalStatusChange::class, 'changedBy'],
            [MaintenanceAttachment::class, 'signal'],
            [MaintenanceAttachment::class, 'uploadedBy'],
            [MaintenanceContract::class, 'supplier'],
            [MaintenanceContract::class, 'asset'],
            [MaintenanceEvent::class, 'asset'],
            [MaintenanceEvent::class, 'supplier'],
            [MaintenanceEvent::class, 'contract'],
            [MaintenanceEvent::class, 'signal'],
            [MaintenanceEvent::class, 'recordedBy'],
        ];

        foreach ($associations as [$class, $field]) {
            $mapping = $entityManager->getClassMetadata($class)->getAssociationMapping($field);
            self::assertInstanceOf(ToOneOwningSideMapping::class, $mapping);
            self::assertSame('RESTRICT', $mapping->joinColumns[0]->onDelete, $class.'::'.$field);
        }
    }

    public function testMaintenanceForeignKeyIndexesAreExplicit(): void
    {
        self::bootKernel();
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        self::assertIndex($entityManager, MaintenanceSignal::class, 'idx_maintenance_signal_submitted_by', ['submitted_by_id']);
        self::assertIndex($entityManager, MaintenanceSignal::class, 'idx_maintenance_signal_asset', ['asset_id']);
        self::assertIndex($entityManager, MaintenanceSignal::class, 'idx_maintenance_signal_assigned_to', ['assigned_to_id']);
        self::assertIndex($entityManager, MaintenanceSignalStatusChange::class, 'idx_maintenance_signal_status_change_signal', ['signal_id']);
        self::assertIndex($entityManager, MaintenanceSignalStatusChange::class, 'idx_maintenance_signal_status_change_changed_by', ['changed_by_id']);
        self::assertIndex($entityManager, MaintenanceAttachment::class, 'idx_maintenance_attachment_signal', ['signal_id']);
        self::assertIndex($entityManager, MaintenanceAttachment::class, 'idx_maintenance_attachment_uploaded_by', ['uploaded_by_id']);
        self::assertIndex($entityManager, MaintenanceContract::class, 'idx_maintenance_contract_supplier', ['supplier_id']);
        self::assertIndex($entityManager, MaintenanceContract::class, 'idx_maintenance_contract_asset', ['asset_id']);
        self::assertIndex($entityManager, MaintenanceEvent::class, 'idx_maintenance_event_asset', ['asset_id']);
        self::assertIndex($entityManager, MaintenanceEvent::class, 'idx_maintenance_event_supplier', ['supplier_id']);
        self::assertIndex($entityManager, MaintenanceEvent::class, 'idx_maintenance_event_contract', ['contract_id']);
        self::assertIndex($entityManager, MaintenanceEvent::class, 'idx_maintenance_event_signal', ['signal_id']);
        self::assertIndex($entityManager, MaintenanceEvent::class, 'idx_maintenance_event_recorded_by', ['recorded_by_id']);
    }

    /** @param class-string $class @param list<string> $columns */
    private static function assertIndex(EntityManagerInterface $entityManager, string $class, string $name, array $columns): void
    {
        $indexes = $entityManager->getClassMetadata($class)->table['indexes'] ?? [];
        self::assertArrayHasKey($name, $indexes);
        self::assertSame($columns, $indexes[$name]['columns'] ?? null);
    }
}
