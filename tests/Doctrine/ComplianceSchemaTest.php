<?php

declare(strict_types=1);

namespace App\Tests\Doctrine;

use App\Entity\ComplianceCompletion;
use App\Entity\CondominiumProfile;
use App\Entity\ManagementMandate;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\AssociationMapping;
use Doctrine\ORM\Mapping\ClassMetadata;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ComplianceSchemaTest extends KernelTestCase
{
    public function testComplianceTablesAndCriticalConstraintsAreStable(): void
    {
        $profile = $this->metadata(CondominiumProfile::class);
        $mandate = $this->metadata(ManagementMandate::class);
        $completion = $this->metadata(ComplianceCompletion::class);

        self::assertSame('condominium_profile', $profile->getTableName());
        self::assertSame('management_mandate', $mandate->getTableName());
        self::assertSame('compliance_completion', $completion->getTableName());

        self::assertSame(
            ['scope_key'],
            $profile->table['uniqueConstraints']['uniq_condominium_profile_scope']['columns'] ?? null,
        );
        self::assertSame(
            ['ends_at', 'starts_at'],
            $mandate->table['indexes']['idx_management_mandate_end_start']['columns'] ?? null,
        );
        self::assertSame(
            ['type', 'period_key'],
            $completion->table['uniqueConstraints']['uniq_compliance_completion_type_period']['columns'] ?? null,
        );
        self::assertSame(
            ['completed_at'],
            $completion->table['indexes']['idx_compliance_completion_completed_at']['columns'] ?? null,
        );
    }

    public function testComplianceAuditAndEvidenceAssociationsUseRestrict(): void
    {
        foreach (['createdBy', 'updatedBy'] as $association) {
            $this->assertRestrict($this->metadata(CondominiumProfile::class)->getAssociationMapping($association));
        }
        foreach (['recordedBy', 'sourceAssembly'] as $association) {
            $this->assertRestrict($this->metadata(ManagementMandate::class)->getAssociationMapping($association));
        }
        foreach (['recordedBy', 'evidenceDocument'] as $association) {
            $this->assertRestrict($this->metadata(ComplianceCompletion::class)->getAssociationMapping($association));
        }
    }

    /** @param class-string $class */
    private function metadata(string $class): ClassMetadata
    {
        self::bootKernel();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        return $entityManager->getClassMetadata($class);
    }

    private function assertRestrict(AssociationMapping $association): void
    {
        self::assertTrue($association->isToOneOwningSide());
        self::assertSame('RESTRICT', $association->joinColumns[0]->onDelete ?? null);
        self::assertFalse($association->isCascadeRemove());
    }
}
