<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AssemblyAgendaItem;
use App\Entity\Document;
use App\Entity\GeneralAssembly;
use App\Entity\User;
use App\Enum\AssemblyConveningBasis;
use App\Enum\AssemblyDecisionKind;
use App\Enum\AssemblyVoteDenominator;
use App\Enum\DocumentAccessLevel;
use App\Enum\DocumentCategory;
use App\Enum\GeneralAssemblyStatus;
use App\Enum\MajorityComparison;
use App\Security\GeneralAssemblyAccessPolicy;
use App\Value\AssemblyMajorityRuleSnapshot;
use App\Value\AssemblyQuorumRuleSnapshot;
use DateTimeImmutable;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use DomainException;
use InvalidArgumentException;
use Throwable;

final readonly class GeneralAssemblyService
{
    private const MAJORITY_RULE_SOURCE_VERSION = 'effective-through-2026-09-10';

    public function __construct(
        private EntityManagerInterface $entityManager,
        private GeneralAssemblyAccessPolicy $accessPolicy,
        private AssemblyElectorateSnapshotService $electorateSnapshotService,
        private AssemblyInvitationService $invitationService,
        private DocumentService $documentService,
        private DocumentStorage $documentStorage,
        private ?AuditLogService $auditLog = null,
    ) {}

    public function createDraft(
        User $actor,
        string $title,
        DateTimeImmutable $scheduledAt,
        string $timezoneSnapshot,
        DateTimeImmutable $referenceDate,
        string $place,
        AssemblyConveningBasis $conveningBasis,
        string $initiatorDisplayName,
        ?User $initiatorUser,
        DateTimeImmutable $createdAt,
        ?string $onlineMeetingReference = null,
        ?string $conveningBasisNote = null,
    ): GeneralAssembly {
        $this->assertManager($actor);

        return $this->entityManager->wrapInTransaction(function (EntityManagerInterface $entityManager) use ($actor, $title, $scheduledAt, $timezoneSnapshot, $referenceDate, $place, $conveningBasis, $initiatorDisplayName, $initiatorUser, $createdAt, $onlineMeetingReference, $conveningBasisNote): GeneralAssembly {
            $assembly = GeneralAssembly::draft(
                $title,
                $scheduledAt,
                $timezoneSnapshot,
                $referenceDate,
                $place,
                $conveningBasis,
                $initiatorDisplayName,
                $initiatorUser,
                $actor,
                $createdAt,
                $onlineMeetingReference,
                $conveningBasisNote,
            );
            $entityManager->persist($assembly);

            return $assembly;
        });
    }

    public function reviseDraft(
        User $actor,
        GeneralAssembly $assembly,
        string $title,
        DateTimeImmutable $scheduledAt,
        string $timezoneSnapshot,
        DateTimeImmutable $referenceDate,
        string $place,
        AssemblyConveningBasis $conveningBasis,
        string $initiatorDisplayName,
        ?User $initiatorUser,
        ?string $onlineMeetingReference = null,
        ?string $conveningBasisNote = null,
    ): void {
        $this->assertManager($actor);

        $assembly->reviseDraft(
            $title,
            $scheduledAt,
            $timezoneSnapshot,
            $referenceDate,
            $place,
            $conveningBasis,
            $initiatorDisplayName,
            $initiatorUser,
            $onlineMeetingReference,
            $conveningBasisNote,
        );
        $this->entityManager->flush();
    }

    public function addAgendaItem(
        User $actor,
        GeneralAssembly $assembly,
        int $position,
        string $title,
        ?string $description,
        string $draftResolutionText,
        AssemblyDecisionKind $kind,
    ): AssemblyAgendaItem {
        $this->assertManager($actor);

        $item = $assembly->addAgendaItem(
            $position,
            $title,
            $description,
            $draftResolutionText,
            $kind,
            self::suggestedMajorityRule($kind),
        );
        $this->entityManager->persist($item);
        $this->entityManager->flush();

        return $item;
    }

    public function reviseAgendaItem(
        User $actor,
        GeneralAssembly $assembly,
        AssemblyAgendaItem $item,
        string $title,
        ?string $description,
        string $draftResolutionText,
        AssemblyDecisionKind $kind,
    ): void {
        $this->assertManager($actor);
        $this->assertAgendaItemBelongsToAssembly($assembly, $item);

        $item->reviseDraft(
            $title,
            $description,
            $draftResolutionText,
            $kind,
            self::suggestedMajorityRule($kind),
        );
        $this->entityManager->flush();
    }

    public function removeAgendaItem(User $actor, GeneralAssembly $assembly, AssemblyAgendaItem $item): void
    {
        $this->assertManager($actor);
        $this->assertAgendaItemBelongsToAssembly($assembly, $item);
        if (GeneralAssemblyStatus::DRAFT !== $assembly->getStatus()) {
            throw new DomainException('Ordinary agenda items may be removed only while the meeting is a draft.');
        }

        $this->entityManager->remove($item);
        $this->entityManager->flush();
    }

    public static function suggestedMajorityRule(AssemblyDecisionKind $kind): AssemblyMajorityRuleSnapshot
    {
        return match ($kind) {
            AssemblyDecisionKind::ORDINARY => new AssemblyMajorityRuleSnapshot(
                'ordinary-represented-majority',
                AssemblyVoteDenominator::REPRESENTED_AT_MEETING,
                '50.00000000',
                MajorityComparison::GREATER_THAN,
                'ЗУЕС, чл. 17, ал. 3 — повече от 50% от представените идеални части.',
                self::MAJORITY_RULE_SOURCE_VERSION,
            ),
            AssemblyDecisionKind::ELECTION_OR_REMOVAL => new AssemblyMajorityRuleSnapshot(
                'management-election-all-common-majority-review',
                AssemblyVoteDenominator::ALL_COMMON_IDEAL_PARTS,
                '50.00000000',
                MajorityComparison::GREATER_THAN,
                'ЗУЕС, чл. 17, ал. 2, т. 7 урежда избора на управител/УС с повече от 50% от всички идеални части; освобождаването и евентуално решение по чл. 17, ал. 7 изискват потвърждение на приложимото правило.',
                self::MAJORITY_RULE_SOURCE_VERSION,
                true,
            ),
            AssemblyDecisionKind::HOUSE_RULES => new AssemblyMajorityRuleSnapshot(
                'house-rules-represented-majority-review',
                AssemblyVoteDenominator::REPRESENTED_AT_MEETING,
                '50.00000000',
                MajorityComparison::GREATER_THAN,
                'По общото правило на ЗУЕС, чл. 17, ал. 3 — повече от 50% от представените идеални части; чл. 17, ал. 7 допуска специален режим по брой самостоятелни обекти след отделно решение.',
                self::MAJORITY_RULE_SOURCE_VERSION,
                true,
            ),
            AssemblyDecisionKind::MAJOR_REPAIR_OR_RENOVATION,
            AssemblyDecisionKind::EU_OR_PUBLIC_FUNDING => new AssemblyMajorityRuleSnapshot(
                'major-repair-public-funding-51-all-common',
                AssemblyVoteDenominator::ALL_COMMON_IDEAL_PARTS,
                '51.00000000',
                MajorityComparison::AT_LEAST,
                'ЗУЕС, чл. 17, ал. 2, т. 5 — не по-малко от 51% идеални части от общите части.',
                self::MAJORITY_RULE_SOURCE_VERSION,
            ),
            AssemblyDecisionKind::USE_OR_CHANGE_OF_COMMON_PARTS => new AssemblyMajorityRuleSnapshot(
                'common-parts-use-change-review',
                AssemblyVoteDenominator::ALL_COMMON_IDEAL_PARTS,
                '100.00000000',
                MajorityComparison::AT_LEAST,
                'Категорията обхваща различни хипотези: промяна предназначението на общи части е по ЗУЕС, чл. 17, ал. 2, т. 1 със 100% от всички идеални части, а обикновено използване може да следва друго правило. Необходимо е потвърждение.',
                self::MAJORITY_RULE_SOURCE_VERSION,
                true,
            ),
            AssemblyDecisionKind::RIGHT_OF_USE_OR_BUILDING_RIGHT => new AssemblyMajorityRuleSnapshot(
                'right-of-use-building-right-unanimous',
                AssemblyVoteDenominator::ALL_COMMON_IDEAL_PARTS,
                '100.00000000',
                MajorityComparison::AT_LEAST,
                'ЗУЕС, чл. 17, ал. 2, т. 1 — 100% идеални части от общите части.',
                self::MAJORITY_RULE_SOURCE_VERSION,
            ),
            AssemblyDecisionKind::MANAGEMENT_MAINTENANCE_COST_DISTRIBUTION => new AssemblyMajorityRuleSnapshot(
                'management-cost-distribution-51-all-common',
                AssemblyVoteDenominator::ALL_COMMON_IDEAL_PARTS,
                '51.00000000',
                MajorityComparison::AT_LEAST,
                'ЗУЕС, чл. 17, ал. 2, т. 8 във връзка с чл. 51, ал. 9 — не по-малко от 51% идеални части от общите части.',
                self::MAJORITY_RULE_SOURCE_VERSION,
            ),
            AssemblyDecisionKind::ASSOCIATION_RELATED => new AssemblyMajorityRuleSnapshot(
                'association-related-review',
                AssemblyVoteDenominator::REPRESENTED_AT_MEETING,
                '50.00000000',
                MajorityComparison::GREATER_THAN,
                'Решенията, свързани със сдружение на собствениците, имат отделни правила според конкретната хипотеза. Изисква се потвърждение на приложимото мнозинство.',
                self::MAJORITY_RULE_SOURCE_VERSION,
                true,
            ),
            AssemblyDecisionKind::OTHER_REQUIRES_REVIEW => new AssemblyMajorityRuleSnapshot(
                'other-review-required',
                AssemblyVoteDenominator::REPRESENTED_AT_MEETING,
                '50.00000000',
                MajorityComparison::GREATER_THAN,
                'Категорията няма безопасно автоматично правно правило. Предложено е общото правило само като отправна точка и е задължителен правен преглед.',
                self::MAJORITY_RULE_SOURCE_VERSION,
                true,
            ),
        };
    }

    public function convene(
        User $actor,
        GeneralAssembly $assembly,
        DateTimeImmutable $convenedAt,
    ): Document {
        $this->assertManager($actor);
        $invitationDocument = null;

        try {
            return $this->entityManager->wrapInTransaction(function (EntityManagerInterface $entityManager) use ($actor, $assembly, $convenedAt, &$invitationDocument): Document {
                $entityManager->lock($assembly, LockMode::PESSIMISTIC_WRITE);

                if (GeneralAssemblyStatus::DRAFT !== $assembly->getStatus()) {
                    throw new DomainException('Only a draft General Assembly can be convened.');
                }
                if (null !== $assembly->getInvitationDocument()) {
                    throw new DomainException('General Assembly invitation has already been created.');
                }
                if ($assembly->getAgendaItems()->isEmpty()) {
                    throw new DomainException('General Assembly cannot be convened without an agenda.');
                }

                $this->electorateSnapshotService->createSnapshot($assembly, $convenedAt);

                if (null === $assembly->getQuorumRuleSnapshot()) {
                    $assembly->snapshotQuorumRule(self::currentQuorumRule());
                }

                $pdfBytes = $this->invitationService->renderPdf($assembly);
                $invitationDocument = $this->documentService->recordGenerated(
                    $actor,
                    DocumentCategory::MEETING_INVITATION,
                    DocumentAccessLevel::RESIDENTS,
                    'Покана — '.$assembly->getTitle(),
                    'Официална покана за общо събрание.',
                    sprintf('general-assembly-invitation-%s.pdf', $assembly->getId() ?? 'draft'),
                    $pdfBytes,
                    $convenedAt,
                    true,
                );

                $assembly->linkInvitation($invitationDocument);
                $assembly->convene($actor, $convenedAt);
                $entityManager->persist($assembly);
                $this->auditLog?->record(
                    $actor,
                    'assembly.convened',
                    'GeneralAssembly',
                    $assembly->getId(),
                    $convenedAt,
                    ['invitation_document_id' => $invitationDocument->getId()],
                );

                return $invitationDocument;
            });
        } catch (Throwable $exception) {
            if ($invitationDocument instanceof Document) {
                $this->documentStorage->remove($invitationDocument->getStorageName());
            }
            throw $exception;
        }
    }

    public static function currentQuorumRule(): AssemblyQuorumRuleSnapshot
    {
        return new AssemblyQuorumRuleSnapshot(
            'zues-2025-default',
            '51.00000000',
            '26.00000000',
            '51.00000000',
            '75.00000000',
            'ЗУЕС — приложим кворум към 2026-09-09',
            'effective-through-2026-09-09',
            false,
        );
    }

    private function assertAgendaItemBelongsToAssembly(GeneralAssembly $assembly, AssemblyAgendaItem $item): void
    {
        if ($item->getAssembly() !== $assembly) {
            throw new InvalidArgumentException('Agenda item does not belong to this General Assembly.');
        }
    }

    private function assertManager(User $actor): void
    {
        if (!$this->accessPolicy->canManage($actor)) {
            throw new DomainException('General Assembly management access is required.');
        }
    }
}
