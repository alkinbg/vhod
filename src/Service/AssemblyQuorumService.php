<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AssemblyElectorateEntry;
use App\Entity\AssemblyQuorumCheck;
use App\Entity\GeneralAssembly;
use App\Entity\User;
use App\Enum\AssemblyAttendanceMode;
use App\Enum\AssemblyQuorumCheckKind;
use App\Enum\GeneralAssemblyStatus;
use App\Repository\AssemblyAttendanceRepository;
use App\Repository\AssemblyElectorateEntryRepository;
use App\Repository\AssemblyProxyRepository;
use App\Security\GeneralAssemblyAccessPolicy;
use App\Util\ExactDecimal;
use DateTimeImmutable;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use DomainException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Throwable;

final readonly class AssemblyQuorumService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private GeneralAssemblyAccessPolicy $accessPolicy,
        private AssemblyElectorateEntryRepository $electorateRepository,
        private AssemblyAttendanceRepository $attendanceRepository,
        private AssemblyProxyRepository $proxyRepository,
        private AssemblyQuorumCalculator $calculator,
    ) {
    }

    public function check(
        User $actor,
        GeneralAssembly $assembly,
        AssemblyQuorumCheckKind $kind,
        DateTimeImmutable $checkedAt,
    ): AssemblyQuorumCheck {
        $this->assertCanManage($actor);

        $this->entityManager->beginTransaction();

        try {
            $this->entityManager->lock($assembly, LockMode::PESSIMISTIC_WRITE);
            $this->assertCheckable($assembly);

            $rule = $assembly->quorumRuleSnapshot();
            if (null === $rule) {
                throw new DomainException('General Assembly quorum rule snapshot is missing.');
            }

            $electorate = $this->electorateRepository->findForAssembly($assembly);
            $represented = $this->representedElectorate($assembly);
            $calculation = $this->calculator->calculate($rule, $electorate, $represented, $kind);
            $check = AssemblyQuorumCheck::record($assembly, $kind, $checkedAt, $calculation, $actor);

            $this->entityManager->persist($check);
            $this->entityManager->flush();
            $this->entityManager->commit();

            return $check;
        } catch (Throwable $exception) {
            if ($this->entityManager->getConnection()->isTransactionActive()) {
                $this->entityManager->rollback();
            }

            throw $exception;
        }
    }

    /**
     * @return array{
     *     electorateCount:int,
     *     representedCount:int,
     *     unrepresentedCount:int,
     *     representedIdealPartsPercent:string,
     *     unresolvedCount:int
     * }
     */
    public function currentRepresentation(GeneralAssembly $assembly): array
    {
        $electorate = $this->electorateRepository->findForAssembly($assembly);
        $represented = $this->representedElectorate($assembly);
        $representedKeys = [];
        $representedIdealPartsPercent = '0.00000000';

        foreach ($represented as $entry) {
            $representedKeys[$this->entryKey($entry)] = true;
            $weight = $entry->getRepresentedIdealPartsPercentSnapshot();
            if (null !== $weight) {
                $representedIdealPartsPercent = ExactDecimal::add($representedIdealPartsPercent, $weight);
            }
        }

        $unrepresentedCount = 0;
        $unresolvedCount = 0;

        foreach ($electorate as $entry) {
            if (!isset($representedKeys[$this->entryKey($entry)])) {
                ++$unrepresentedCount;
            }
            if (null !== $entry->getReviewReason() || !$entry->isQuorumEligible()) {
                ++$unresolvedCount;
            }
        }

        return [
            'electorateCount' => count($electorate),
            'representedCount' => count($represented),
            'unrepresentedCount' => $unrepresentedCount,
            'representedIdealPartsPercent' => $representedIdealPartsPercent,
            'unresolvedCount' => $unresolvedCount,
        ];
    }

    /** @return list<AssemblyElectorateEntry> */
    private function representedElectorate(GeneralAssembly $assembly): array
    {
        $represented = [];

        foreach ($this->attendanceRepository->findForAssembly($assembly) as $attendance) {
            if (null !== $attendance->getLeftAt()) {
                continue;
            }

            $entry = $attendance->getElectorateEntry();
            if (AssemblyAttendanceMode::BY_PROXY === $attendance->getMode()
                && null === $this->proxyRepository->findEffectiveForPrincipal($assembly, $entry)) {
                continue;
            }

            $represented[$this->entryKey($entry)] = $entry;
        }

        return array_values($represented);
    }

    private function assertCanManage(User $actor): void
    {
        if (!$this->accessPolicy->canManage($actor)) {
            throw new AccessDeniedException('General Assembly management access is required.');
        }
    }

    private function assertCheckable(GeneralAssembly $assembly): void
    {
        if (!in_array($assembly->getStatus(), [GeneralAssemblyStatus::CONVENED, GeneralAssemblyStatus::IN_PROGRESS], true)) {
            throw new DomainException('Quorum may be checked only for a convened or in-progress General Assembly.');
        }
    }

    private function entryKey(AssemblyElectorateEntry $entry): string
    {
        return null !== $entry->getId() ? 'id:'.$entry->getId() : 'object:'.spl_object_id($entry);
    }
}
