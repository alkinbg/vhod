<?php

declare(strict_types=1);

namespace App\Tests\Doctrine;

use App\Entity\AssemblyAbsenteeDeclaration;
use App\Entity\AssemblyAbsenteeDeclarationVote;
use App\Entity\AssemblyAbsenteeWindow;
use App\Entity\AssemblyAgendaItem;
use App\Entity\AssemblyAttendance;
use App\Entity\AssemblyAttendanceChange;
use App\Entity\AssemblyElectorateEntry;
use App\Entity\AssemblyInvitationPosting;
use App\Entity\AssemblyMinutesCorrection;
use App\Entity\AssemblyProxy;
use App\Entity\AssemblyQuorumCheck;
use App\Entity\AssemblyResolution;
use App\Entity\AssemblyVote;
use App\Entity\AssemblyVoteCorrection;
use App\Entity\GeneralAssembly;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\AssociationMapping;
use Doctrine\ORM\Mapping\ClassMetadata;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class GeneralAssemblySchemaTest extends KernelTestCase
{
    public function testEveryPhase9EntityUsesTheExpectedStableTable(): void
    {
        $expected = [
            GeneralAssembly::class => 'general_assembly',
            AssemblyAgendaItem::class => 'assembly_agenda_item',
            AssemblyElectorateEntry::class => 'assembly_electorate_entry',
            AssemblyQuorumCheck::class => 'assembly_quorum_check',
            AssemblyInvitationPosting::class => 'assembly_invitation_posting',
            AssemblyAttendance::class => 'assembly_attendance',
            AssemblyAttendanceChange::class => 'assembly_attendance_change',
            AssemblyProxy::class => 'assembly_proxy',
            AssemblyVote::class => 'assembly_vote',
            AssemblyVoteCorrection::class => 'assembly_vote_correction',
            AssemblyResolution::class => 'assembly_resolution',
            AssemblyAbsenteeWindow::class => 'assembly_absentee_window',
            AssemblyAbsenteeDeclaration::class => 'assembly_absentee_declaration',
            AssemblyAbsenteeDeclarationVote::class => 'assembly_absentee_declaration_vote',
            AssemblyMinutesCorrection::class => 'assembly_minutes_correction',
        ];

        foreach ($expected as $class => $table) {
            self::assertSame($table, $this->metadata($class)->getTableName(), $class);
        }
    }

    public function testPhase9CriticalUniqueAndLookupConstraintsAreStable(): void
    {
        $assembly = $this->metadata(GeneralAssembly::class);
        $agenda = $this->metadata(AssemblyAgendaItem::class);
        $electorate = $this->metadata(AssemblyElectorateEntry::class);
        $quorum = $this->metadata(AssemblyQuorumCheck::class);
        $attendance = $this->metadata(AssemblyAttendance::class);
        $proxy = $this->metadata(AssemblyProxy::class);
        $vote = $this->metadata(AssemblyVote::class);
        $resolution = $this->metadata(AssemblyResolution::class);
        $absenteeWindow = $this->metadata(AssemblyAbsenteeWindow::class);
        $absenteeDeclaration = $this->metadata(AssemblyAbsenteeDeclaration::class);

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
        self::assertSame(
            ['assembly_id', 'electorate_entry_id'],
            $attendance->table['uniqueConstraints']['uniq_assembly_attendance_principal']['columns'] ?? null,
        );
        self::assertSame(
            ['assembly_id', 'representative_person_id'],
            $proxy->table['indexes']['idx_proxy_assembly_representative']['columns'] ?? null,
        );
        self::assertSame(
            ['agenda_item_id', 'electorate_entry_id'],
            $vote->table['uniqueConstraints']['uniq_assembly_vote_item_entry']['columns'] ?? null,
        );
        self::assertSame(
            ['agenda_item_id', 'choice'],
            $vote->table['indexes']['idx_vote_item_choice']['columns'] ?? null,
        );
        self::assertSame(
            ['agenda_item_id'],
            $resolution->table['uniqueConstraints']['uniq_assembly_resolution_item']['columns'] ?? null,
        );
        self::assertSame(
            ['assembly_id'],
            $absenteeWindow->table['uniqueConstraints']['uniq_absentee_window_assembly']['columns'] ?? null,
        );
        self::assertSame(
            ['deadline_at'],
            $absenteeWindow->table['indexes']['idx_absentee_deadline']['columns'] ?? null,
        );
        self::assertSame(
            ['window_id', 'electorate_entry_id'],
            $absenteeDeclaration->table['uniqueConstraints']['uniq_absentee_declaration_window_entry']['columns'] ?? null,
        );
    }

    public function testEveryPhase9AuditAndSourceAssociationUsesRestrictWithoutCascadeRemove(): void
    {
        $this->assertAssociationsRestrict(GeneralAssembly::class, [
            'initiatorUser',
            'createdBy',
            'convenedBy',
            'startedBy',
            'closedBy',
            'invitationDocument',
            'minutesFinalizedBy',
            'minutesDocument',
        ]);
        self::assertFalse($this->metadata(GeneralAssembly::class)->getAssociationMapping('agendaItems')->isCascadeRemove());

        $this->assertAssociationsRestrict(AssemblyAgendaItem::class, ['assembly']);
        $this->assertAssociationsRestrict(AssemblyElectorateEntry::class, ['assembly', 'unit', 'sourceRelation', 'person']);
        $this->assertAssociationsRestrict(AssemblyQuorumCheck::class, ['assembly', 'checkedBy']);
        $this->assertAssociationsRestrict(AssemblyInvitationPosting::class, ['assembly', 'confirmedBy', 'evidenceDocument']);
        $this->assertAssociationsRestrict(AssemblyAttendance::class, ['assembly', 'electorateEntry', 'representativePerson', 'registeredBy']);
        $this->assertAssociationsRestrict(AssemblyAttendanceChange::class, ['attendance', 'changedBy']);
        $this->assertAssociationsRestrict(AssemblyProxy::class, [
            'assembly',
            'principalEntry',
            'representativePerson',
            'evidenceDocument',
            'registeredBy',
            'revokedBy',
        ]);
        $this->assertAssociationsRestrict(AssemblyVote::class, ['agendaItem', 'electorateEntry', 'recordedBy']);
        $this->assertAssociationsRestrict(AssemblyVoteCorrection::class, ['vote', 'changedBy']);
        $this->assertAssociationsRestrict(AssemblyResolution::class, ['agendaItem', 'resolvedBy']);
        $this->assertAssociationsRestrict(AssemblyAbsenteeWindow::class, ['assembly', 'openedBy', 'closedBy']);
        self::assertFalse($this->metadata(AssemblyAbsenteeWindow::class)->getAssociationMapping('agendaItems')->isCascadeRemove());
        $this->assertAssociationsRestrict(AssemblyAbsenteeDeclaration::class, [
            'assembly',
            'window',
            'electorateEntry',
            'evidenceDocument',
            'registeredBy',
        ]);
        $this->assertAssociationsRestrict(AssemblyAbsenteeDeclarationVote::class, ['declaration', 'agendaItem']);
        $this->assertAssociationsRestrict(AssemblyMinutesCorrection::class, ['assembly', 'document', 'recordedBy']);
    }

    /** @param class-string $class */
    private function metadata(string $class): ClassMetadata
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        return $entityManager->getClassMetadata($class);
    }

    /**
     * @param class-string $class
     * @param list<string> $associationNames
     */
    private function assertAssociationsRestrict(string $class, array $associationNames): void
    {
        $metadata = $this->metadata($class);
        foreach ($associationNames as $associationName) {
            $this->assertToOneRestrict($metadata->getAssociationMapping($associationName));
        }
    }

    private function assertToOneRestrict(AssociationMapping $association): void
    {
        self::assertTrue($association->isToOneOwningSide());
        self::assertSame('RESTRICT', $association->joinColumns[0]->onDelete);
        self::assertFalse($association->isCascadeRemove());
    }
}
