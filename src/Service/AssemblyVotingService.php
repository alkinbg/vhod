<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AssemblyAgendaItem;
use App\Entity\AssemblyElectorateEntry;
use App\Entity\AssemblyResolution;
use App\Entity\AssemblyVote;
use App\Entity\AssemblyVoteCorrection;
use App\Entity\User;
use App\Enum\AgendaItemStatus;
use App\Enum\AssemblyAttendanceMode;
use App\Enum\AssemblyResolutionResult;
use App\Enum\AssemblyVoteCastMode;
use App\Enum\AssemblyVoteChoice;
use App\Enum\GeneralAssemblyStatus;
use App\Repository\AssemblyAttendanceRepository;
use App\Repository\AssemblyElectorateEntryRepository;
use App\Repository\AssemblyProxyRepository;
use App\Repository\AssemblyVoteRepository;
use App\Security\GeneralAssemblyAccessPolicy;
use App\Util\ExactDecimal;
use App\Value\AssemblyResolutionCalculation;
use DateTimeImmutable;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use DomainException;
use Throwable;

final readonly class AssemblyVotingService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private GeneralAssemblyAccessPolicy $accessPolicy,
        private AssemblyAttendanceRepository $attendanceRepository,
        private AssemblyProxyRepository $proxyRepository,
        private AssemblyElectorateEntryRepository $electorateRepository,
        private AssemblyVoteRepository $voteRepository,
        private AssemblyQuorumService $quorumService,
        private AssemblyResolutionCalculator $resolutionCalculator,
    ) {
    }

    public function openItem(
        User $actor,
        AssemblyAgendaItem $item,
        string $finalResolutionText,
        DateTimeImmutable $openedAt,
    ): void {
        $this->assertCanManage($actor);
        $this->entityManager->beginTransaction();

        try {
            $this->lockItem($item);
            $item->open($finalResolutionText, $openedAt);
            $this->entityManager->flush();
            $this->entityManager->commit();
        } catch (Throwable $exception) {
            $this->rollback();
            throw $exception;
        }
    }

    public function recordVote(
        User $actor,
        AssemblyAgendaItem $item,
        AssemblyElectorateEntry $entry,
        AssemblyVoteChoice $choice,
        DateTimeImmutable $recordedAt,
    ): AssemblyVote {
        $this->assertCanManage($actor);
        $this->entityManager->beginTransaction();

        try {
            $this->lockItem($item);
            $this->assertVotingOpen($item);
            if ($entry->getAssembly() !== $item->getAssembly()) {
                throw new DomainException('Formal vote principal does not belong to this General Assembly.');
            }
            if (null !== $this->voteRepository->findForItemAndEntry($item, $entry)) {
                throw new DomainException('An effective formal vote for this principal and agenda item already exists.');
            }

            $attendance = $this->attendanceRepository->findForPrincipal($item->getAssembly(), $entry);
            if (null === $attendance || null !== $attendance->getLeftAt()) {
                throw new DomainException('Only a currently represented principal may cast a formal vote.');
            }

            $castMode = match ($attendance->getMode()) {
                AssemblyAttendanceMode::IN_PERSON, AssemblyAttendanceMode::ONLINE => AssemblyVoteCastMode::ATTENDANCE,
                AssemblyAttendanceMode::BY_PROXY => AssemblyVoteCastMode::PROXY,
                AssemblyAttendanceMode::STATUTORY_USER_AUTHORITY => AssemblyVoteCastMode::STATUTORY_AUTHORITY,
            };
            if (AssemblyVoteCastMode::PROXY === $castMode && null === $this->proxyRepository->findEffectiveForPrincipal($entry)) {
                throw new DomainException('Proxy formal vote requires an effective proxy authority.');
            }

            $weight = $entry->getRepresentedIdealPartsPercentSnapshot();
            if (!$entry->isQuorumEligible() || null !== $entry->getReviewReason() || null === $weight) {
                throw new DomainException('Formal vote weight is unresolved and requires review.');
            }

            $vote = AssemblyVote::record($item, $entry, $choice, $weight, $actor, $recordedAt, $castMode);
            $this->entityManager->persist($vote);
            $this->entityManager->flush();
            $this->entityManager->commit();

            return $vote;
        } catch (Throwable $exception) {
            $this->rollback();
            throw $exception;
        }
    }

    public function correctVote(
        User $actor,
        AssemblyVote $vote,
        AssemblyVoteChoice $newChoice,
        string $reason,
        DateTimeImmutable $changedAt,
    ): AssemblyVoteCorrection {
        $this->assertCanManage($actor);
        $this->entityManager->beginTransaction();

        try {
            $item = $vote->getAgendaItem();
            $this->lockItem($item);
            $this->assertVotingOpen($item);

            $previousChoice = $vote->getChoice();
            $correction = AssemblyVoteCorrection::record($vote, $previousChoice, $newChoice, $reason, $actor, $changedAt);
            $vote->correctChoice($newChoice);
            $this->entityManager->persist($correction);
            $this->entityManager->flush();
            $this->entityManager->commit();

            return $correction;
        } catch (Throwable $exception) {
            $this->rollback();
            throw $exception;
        }
    }

    public function resolveItem(
        User $actor,
        AssemblyAgendaItem $item,
        DateTimeImmutable $resolvedAt,
    ): AssemblyResolution {
        $this->assertCanManage($actor);
        $this->entityManager->beginTransaction();

        try {
            $this->lockItem($item);
            $existing = $this->entityManager->getRepository(AssemblyResolution::class)->findOneBy(['agendaItem' => $item]);
            if ($existing instanceof AssemblyResolution) {
                $this->entityManager->commit();

                return $existing;
            }
            $this->assertVotingOpen($item);

            $electorate = $this->electorateRepository->findForAssembly($item->getAssembly());
            $allCommonIdealParts = '0.00000000';
            $requiresReview = false;
            foreach ($electorate as $entry) {
                $weight = $entry->getRepresentedIdealPartsPercentSnapshot();
                if (!$entry->isQuorumEligible() || null !== $entry->getReviewReason() || null === $weight) {
                    $requiresReview = true;
                    continue;
                }
                $allCommonIdealParts = ExactDecimal::add($allCommonIdealParts, $weight);
            }

            $representation = $this->quorumService->currentRepresentation($item->getAssembly());
            $calculation = $this->resolutionCalculator->calculate(
                $item,
                $this->voteRepository->findForItem($item),
                $allCommonIdealParts,
                $representation['representedIdealPartsPercent'],
            );
            if ($requiresReview && AssemblyResolutionResult::REVIEW_REQUIRED !== $calculation->result) {
                $calculation = new AssemblyResolutionCalculation(
                    $calculation->forIdealPartsPercent,
                    $calculation->againstIdealPartsPercent,
                    $calculation->abstainIdealPartsPercent,
                    $calculation->denominatorIdealPartsPercent,
                    $calculation->requiredIdealPartsPercent,
                    AssemblyResolutionResult::REVIEW_REQUIRED,
                    'Решението изисква преглед поради непълни или противоречиви данни в замразения електорат.',
                );
            }

            $resolution = AssemblyResolution::record($item, $calculation, $actor, $resolvedAt);
            $this->entityManager->persist($resolution);
            $item->resolve($resolvedAt);
            $this->entityManager->flush();
            $this->entityManager->commit();

            return $resolution;
        } catch (Throwable $exception) {
            $this->rollback();
            throw $exception;
        }
    }

    private function lockItem(AssemblyAgendaItem $item): void
    {
        $assembly = $item->getAssembly();
        $this->entityManager->lock($assembly, LockMode::PESSIMISTIC_WRITE);
        $this->entityManager->lock($item, LockMode::PESSIMISTIC_WRITE);
    }

    private function assertVotingOpen(AssemblyAgendaItem $item): void
    {
        if (GeneralAssemblyStatus::IN_PROGRESS !== $item->getAssembly()->getStatus() || AgendaItemStatus::OPEN !== $item->getStatus()) {
            throw new DomainException('Formal vote mutations require an open agenda item in an in-progress General Assembly.');
        }
    }

    private function assertCanManage(User $actor): void
    {
        if (!$this->accessPolicy->canManage($actor)) {
            throw new DomainException('General Assembly management access is required.');
        }
    }

    private function rollback(): void
    {
        if ($this->entityManager->getConnection()->isTransactionActive()) {
            $this->entityManager->rollback();
        }
    }
}
