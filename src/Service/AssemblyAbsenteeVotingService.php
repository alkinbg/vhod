<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AssemblyAbsenteeDeclaration;
use App\Entity\AssemblyAbsenteeDeclarationVote;
use App\Entity\AssemblyAbsenteeWindow;
use App\Entity\AssemblyAgendaItem;
use App\Entity\AssemblyElectorateEntry;
use App\Entity\Document;
use App\Entity\GeneralAssembly;
use App\Entity\User;
use App\Enum\AssemblyAbsenteeSignatureMode;
use App\Enum\AssemblyVoteChoice;
use App\Enum\GeneralAssemblyStatus;
use App\Security\GeneralAssemblyAccessPolicy;
use DateTimeImmutable;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use DomainException;
use InvalidArgumentException;
use Throwable;

final readonly class AssemblyAbsenteeVotingService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private GeneralAssemblyAccessPolicy $accessPolicy,
        private AssemblyVotingService $votingService,
    ) {
    }

    /** @param list<AssemblyAgendaItem> $agendaItems */
    public function openWindow(
        User $actor,
        GeneralAssembly $assembly,
        array $agendaItems,
        DateTimeImmutable $openedAt,
        DateTimeImmutable $deadlineAt,
        string $legalBasis,
    ): AssemblyAbsenteeWindow {
        $this->assertCanManage($actor);
        $this->entityManager->beginTransaction();

        try {
            $this->entityManager->lock($assembly, LockMode::PESSIMISTIC_WRITE);
            if (GeneralAssemblyStatus::CLOSED !== $assembly->getStatus()) {
                throw new DomainException('Absentee voting may be opened only for a closed General Assembly.');
            }
            if ([] === $agendaItems) {
                throw new InvalidArgumentException('Absentee voting window requires at least one eligible agenda item.');
            }

            $existing = $this->entityManager->getRepository(AssemblyAbsenteeWindow::class)->findOneBy(['assembly' => $assembly]);
            if ($existing instanceof AssemblyAbsenteeWindow) {
                throw new DomainException('This General Assembly already has an absentee voting window.');
            }

            $window = AssemblyAbsenteeWindow::open($assembly, $actor, $openedAt, $deadlineAt, $legalBasis);
            foreach ($agendaItems as $item) {
                if (!$item instanceof AssemblyAgendaItem) {
                    throw new InvalidArgumentException('Absentee voting window received an invalid agenda item.');
                }
                $this->entityManager->lock($item, LockMode::PESSIMISTIC_WRITE);
                $window->addAgendaItem($item);
                $item->deferToAbsenteeWindow($openedAt);
            }

            $this->entityManager->persist($window);
            $this->entityManager->flush();
            $this->entityManager->commit();

            return $window;
        } catch (Throwable $exception) {
            $this->rollback();
            throw $exception;
        }
    }

    /** @param array<int, AssemblyVoteChoice> $choices */
    public function registerDeclaration(
        User $actor,
        AssemblyAbsenteeWindow $window,
        AssemblyElectorateEntry $entry,
        Document $evidence,
        AssemblyAbsenteeSignatureMode $signatureMode,
        array $choices,
        DateTimeImmutable $submittedAt,
        ?string $notes = null,
    ): AssemblyAbsenteeDeclaration {
        $this->assertCanManage($actor);
        $this->entityManager->beginTransaction();

        try {
            $this->entityManager->lock($window->getAssembly(), LockMode::PESSIMISTIC_WRITE);
            $this->entityManager->lock($window, LockMode::PESSIMISTIC_WRITE);

            if (GeneralAssemblyStatus::CLOSED !== $window->getAssembly()->getStatus()) {
                throw new DomainException('Absentee declarations require a closed General Assembly.');
            }
            if ([] === $choices) {
                throw new InvalidArgumentException('Absentee declaration requires at least one vote choice.');
            }
            if ($entry->getAssembly() !== $window->getAssembly()) {
                throw new DomainException('Absentee declaration principal does not belong to this General Assembly.');
            }

            $existing = $this->entityManager->getRepository(AssemblyAbsenteeDeclaration::class)->findOneBy([
                'window' => $window,
                'electorateEntry' => $entry,
            ]);
            if ($existing instanceof AssemblyAbsenteeDeclaration) {
                throw new DomainException('An absentee declaration for this principal and window already exists.');
            }

            $itemsById = [];
            foreach ($window->getAgendaItems() as $item) {
                $itemId = $item->getId();
                if (null === $itemId) {
                    throw new DomainException('Absentee voting window contains a non-persisted agenda item.');
                }
                $itemsById[$itemId] = $item;
            }

            $declaration = AssemblyAbsenteeDeclaration::record(
                $window,
                $entry,
                $evidence,
                $signatureMode,
                $actor,
                $submittedAt,
                $notes,
            );
            $this->entityManager->persist($declaration);

            foreach ($choices as $itemId => $choice) {
                if (!$choice instanceof AssemblyVoteChoice) {
                    throw new InvalidArgumentException('Absentee declaration contains an invalid vote choice.');
                }
                $item = $itemsById[$itemId] ?? null;
                if (!$item instanceof AssemblyAgendaItem) {
                    throw new DomainException('Absentee declaration references an agenda item outside the effective window.');
                }

                $this->votingService->recordAbsenteeVoteInCurrentTransaction(
                    $actor,
                    $item,
                    $entry,
                    $choice,
                    $submittedAt,
                );
                $this->entityManager->persist(AssemblyAbsenteeDeclarationVote::record($declaration, $item, $choice));
            }

            $this->entityManager->flush();
            $this->entityManager->commit();

            return $declaration;
        } catch (Throwable $exception) {
            $this->rollback();
            throw $exception;
        }
    }

    public function closeWindow(
        User $actor,
        AssemblyAbsenteeWindow $window,
        DateTimeImmutable $closedAt,
    ): void {
        $this->assertCanManage($actor);
        $this->entityManager->beginTransaction();

        try {
            $assembly = $window->getAssembly();
            $this->entityManager->lock($assembly, LockMode::PESSIMISTIC_WRITE);
            $this->entityManager->lock($window, LockMode::PESSIMISTIC_WRITE);
            if (GeneralAssemblyStatus::CLOSED !== $assembly->getStatus()) {
                throw new DomainException('Absentee voting may close only while the General Assembly is closed.');
            }

            $window->close($actor, $closedAt);
            foreach ($window->getAgendaItems() as $item) {
                $this->votingService->resolveAbsenteeItemInCurrentTransaction($actor, $item, $closedAt);
            }

            $this->entityManager->flush();
            $this->entityManager->commit();
        } catch (Throwable $exception) {
            $this->rollback();
            throw $exception;
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
