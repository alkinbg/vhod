<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Document;
use App\Entity\GeneralAssembly;
use App\Entity\User;
use App\Enum\AssemblyConveningBasis;
use App\Enum\DocumentAccessLevel;
use App\Enum\DocumentCategory;
use App\Enum\GeneralAssemblyStatus;
use App\Security\GeneralAssemblyAccessPolicy;
use App\Value\AssemblyQuorumRuleSnapshot;
use DateTimeImmutable;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use DomainException;
use Throwable;

final readonly class GeneralAssemblyService
{
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

    private function assertManager(User $actor): void
    {
        if (!$this->accessPolicy->canManage($actor)) {
            throw new DomainException('General Assembly management access is required.');
        }
    }
}
