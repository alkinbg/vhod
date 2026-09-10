<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AssemblyAbsenteeDeclaration;
use App\Entity\AssemblyAbsenteeWindow;
use App\Entity\AssemblyAgendaItem;
use App\Entity\AssemblyMinutesCorrection;
use App\Entity\AssemblyProxy;
use App\Entity\AssemblyResolution;
use App\Entity\AssemblyVote;
use App\Entity\Document;
use App\Entity\GeneralAssembly;
use App\Entity\User;
use App\Enum\AgendaItemStatus;
use App\Enum\AssemblyLegalResult;
use App\Enum\AssemblyResolutionResult;
use App\Enum\DocumentAccessLevel;
use App\Enum\DocumentCategory;
use App\Enum\GeneralAssemblyStatus;
use App\Repository\AssemblyAttendanceRepository;
use App\Repository\AssemblyElectorateEntryRepository;
use App\Repository\AssemblyProxyRepository;
use App\Repository\AssemblyQuorumCheckRepository;
use App\Repository\AssemblyVoteRepository;
use App\Security\GeneralAssemblyAccessPolicy;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Dompdf\Dompdf;
use Dompdf\Options;
use DomainException;
use Throwable;
use Twig\Environment;

final readonly class AssemblyMinutesService
{
    public function __construct(
        private Environment $twig,
        private EntityManagerInterface $entityManager,
        private GeneralAssemblyAccessPolicy $accessPolicy,
        private AssemblyQuorumCheckRepository $quorumChecks,
        private AssemblyAttendanceRepository $attendance,
        private AssemblyProxyRepository $proxies,
        private AssemblyElectorateEntryRepository $electorate,
        private AssemblyVoteRepository $votes,
        private DocumentService $documentService,
        private DocumentStorage $documentStorage,
        private ?AuditLogService $auditLog = null,
    ) {}

    /** @return array<string, mixed> */
    public function buildViewModel(GeneralAssembly $assembly): array
    {
        $quorumChecks = $this->quorumChecks->findForAssembly($assembly);
        $latestQuorumCheck = $quorumChecks[0] ?? null;
        $attendance = $this->attendance->findForAssembly($assembly);
        $proxies = $this->proxies->findBy(['assembly' => $assembly], ['registeredAt' => 'ASC', 'id' => 'ASC']);
        $electorate = $this->electorate->findForAssembly($assembly);

        $resolutionRepository = $this->entityManager->getRepository(AssemblyResolution::class);
        $agenda = [];
        $warnings = [];

        foreach ($electorate as $entry) {
            if (null !== $entry->getReviewReason()) {
                $warnings[] = sprintf(
                    '%s (%s): %s',
                    $entry->getPrincipalNameSnapshot(),
                    $entry->getUnitDesignationSnapshot(),
                    $entry->getReviewReason(),
                );
            }
        }

        foreach ($quorumChecks as $check) {
            if (AssemblyLegalResult::REVIEW_REQUIRED === $check->getResult()) {
                $warnings[] = 'Проверка на кворума изисква правен преглед: '.$check->getExplanation();
            }
        }

        foreach ($assembly->getAgendaItems() as $item) {
            $resolution = $resolutionRepository->findOneBy(['agendaItem' => $item]);
            $itemVotes = $this->votes->findForItem($item);
            $agenda[] = [
                'item' => $item,
                'votes' => $itemVotes,
                'resolution' => $resolution,
            ];

            if ($resolution instanceof AssemblyResolution && AssemblyResolutionResult::REVIEW_REQUIRED === $resolution->getResult()) {
                $warnings[] = sprintf('Точка %d изисква правен преглед: %s', $item->getPosition(), $resolution->getExplanation());
            }
            if (
                in_array($assembly->getStatus(), [GeneralAssemblyStatus::CLOSED, GeneralAssemblyStatus::MINUTES_FINALIZED], true)
                && (!$resolution instanceof AssemblyResolution || AgendaItemStatus::RESOLVED !== $item->getStatus())
            ) {
                $warnings[] = sprintf('Точка %d няма окончателно замразен резултат.', $item->getPosition());
            }
        }

        $absenteeWindow = $this->entityManager->getRepository(AssemblyAbsenteeWindow::class)->findOneBy(['assembly' => $assembly]);
        $absenteeDeclarations = $this->entityManager->getRepository(AssemblyAbsenteeDeclaration::class)->findBy(
            ['assembly' => $assembly],
            ['submittedAt' => 'ASC', 'id' => 'ASC'],
        );
        if ($absenteeWindow instanceof AssemblyAbsenteeWindow && null === $absenteeWindow->getClosedAt()) {
            $warnings[] = 'Неприсъственото гласуване все още е отворено.';
        }

        return [
            'assembly' => $assembly,
            'latestQuorumCheck' => $latestQuorumCheck,
            'quorumChecks' => $quorumChecks,
            'representedIdealPartsPercent' => $latestQuorumCheck?->getRepresentedIdealPartsPercent(),
            'attendance' => $attendance,
            'proxies' => $proxies,
            'electorate' => $electorate,
            'agenda' => $agenda,
            'absenteeWindow' => $absenteeWindow,
            'absenteeDeclarations' => $absenteeDeclarations,
            'warnings' => $warnings,
        ];
    }

    public function renderPdf(GeneralAssembly $assembly): string
    {
        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml(
            $this->twig->render('assemblies/minutes_pdf.html.twig', $this->buildViewModel($assembly)),
            'UTF-8',
        );
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return $dompdf->output();
    }

    public function finalize(User $actor, GeneralAssembly $assembly, DateTimeImmutable $finalizedAt): Document
    {
        $this->assertManager($actor);
        $document = null;

        try {
            return $this->entityManager->wrapInTransaction(function (EntityManagerInterface $entityManager) use ($actor, $assembly, $finalizedAt, &$document): Document {
                $entityManager->lock($assembly, LockMode::PESSIMISTIC_WRITE);

                if (GeneralAssemblyStatus::MINUTES_FINALIZED === $assembly->getStatus()) {
                    $existing = $assembly->getMinutesDocument();
                    if (!$existing instanceof Document) {
                        throw new DomainException('Finalized General Assembly has no linked minutes document.');
                    }

                    return $existing;
                }

                $this->assertReadyForFinalization($assembly);

                $pdfBytes = $this->renderPdf($assembly);
                $document = $this->documentService->recordGenerated(
                    $actor,
                    DocumentCategory::MEETING_MINUTES,
                    DocumentAccessLevel::RESIDENTS,
                    'Протокол — '.$assembly->getTitle(),
                    'Окончателен протокол от официалното общо събрание.',
                    sprintf('general-assembly-minutes-%s.pdf', $assembly->getId() ?? 'draft'),
                    $pdfBytes,
                    $finalizedAt,
                    true,
                );

                $dueOn = $assembly->getMinutesDueOn() ?? $this->calculateMinutesDueOn($assembly);
                $assembly->finalizeMinutes($document, $actor, $finalizedAt, $dueOn);
                $entityManager->persist($assembly);
                $this->auditLog?->record(
                    $actor,
                    'assembly.minutes.finalized',
                    'GeneralAssembly',
                    $assembly->getId(),
                    $finalizedAt,
                    ['minutes_document_id' => $document->getId()],
                );

                return $document;
            });
        } catch (Throwable $exception) {
            if ($document instanceof Document) {
                try {
                    $this->documentStorage->remove($document->getStorageName());
                } catch (Throwable) {
                    // Preserve the original persistence/finalization failure.
                }
            }

            throw $exception;
        }
    }

    public function addCorrection(
        User $actor,
        GeneralAssembly $assembly,
        string $reason,
        Document $document,
        DateTimeImmutable $recordedAt,
    ): AssemblyMinutesCorrection {
        $this->assertManager($actor);

        return $this->entityManager->wrapInTransaction(function (EntityManagerInterface $entityManager) use ($actor, $assembly, $reason, $document, $recordedAt): AssemblyMinutesCorrection {
            $entityManager->lock($assembly, LockMode::PESSIMISTIC_WRITE);
            $correction = AssemblyMinutesCorrection::record($assembly, $reason, $document, $actor, $recordedAt);
            $entityManager->persist($correction);
            $this->auditLog?->record(
                $actor,
                'assembly.minutes.correction.recorded',
                'GeneralAssembly',
                $assembly->getId(),
                $recordedAt,
                ['document_id' => $document->getId()],
            );

            return $correction;
        });
    }

    private function assertReadyForFinalization(GeneralAssembly $assembly): void
    {
        if (GeneralAssemblyStatus::CLOSED !== $assembly->getStatus()) {
            throw new DomainException('General Assembly minutes may be finalized only after the meeting is closed.');
        }
        if (!$assembly->hasCompleteMinutesMetadata()) {
            throw new DomainException('Chairperson and secretary are required before minutes finalization.');
        }

        $window = $this->entityManager->getRepository(AssemblyAbsenteeWindow::class)->findOneBy(['assembly' => $assembly]);
        if ($window instanceof AssemblyAbsenteeWindow && null === $window->getClosedAt()) {
            throw new DomainException('General Assembly minutes cannot be finalized while absentee voting is open.');
        }

        $resolutionRepository = $this->entityManager->getRepository(AssemblyResolution::class);
        foreach ($assembly->getAgendaItems() as $item) {
            if (AgendaItemStatus::RESOLVED !== $item->getStatus()) {
                throw new DomainException(sprintf('Agenda item %d is not resolved.', $item->getPosition()));
            }
            if (!$resolutionRepository->findOneBy(['agendaItem' => $item]) instanceof AssemblyResolution) {
                throw new DomainException(sprintf('Agenda item %d has no persisted resolution.', $item->getPosition()));
            }
        }
    }

    private function calculateMinutesDueOn(GeneralAssembly $assembly): DateTimeImmutable
    {
        $timezone = new DateTimeZone($assembly->getTimezoneSnapshot());
        $meetingDate = $assembly->getScheduledAt()->setTimezone($timezone);
        $dueDate = $meetingDate->modify('+7 days')->format('Y-m-d');

        return new DateTimeImmutable($dueDate, $timezone);
    }

    private function assertManager(User $actor): void
    {
        if (!$this->accessPolicy->canManage($actor)) {
            throw new DomainException('General Assembly management access is required.');
        }
    }
}
