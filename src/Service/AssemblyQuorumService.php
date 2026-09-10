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
use DateTimeZone;
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
            $this->assertCheckTiming($assembly, $kind, $checkedAt);

            $rule = $assembly->getQuorumRuleSnapshot();
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
                && null === $this->proxyRepository->findEffectiveForPrincipal($entry)) {
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

    private function assertCheckTiming(
        GeneralAssembly $assembly,
        AssemblyQuorumCheckKind $kind,
        DateTimeImmutable $checkedAt,
    ): void {
        $checkedAt = $checkedAt->setTimezone(new DateTimeZone('UTC'));
        $scheduledAt = $assembly->getScheduledAt()->setTimezone(new DateTimeZone('UTC'));

        $notBefore = match ($kind) {
            AssemblyQuorumCheckKind::FIRST_CALL => $scheduledAt,
            AssemblyQuorumCheckKind::DELAYED_CALL => $scheduledAt->modify('+1 hour'),
            AssemblyQuorumCheckKind::NEXT_DAY_CALL => $this->nextEligibleCallAt($assembly),
        };

        if ($checkedAt < $notBefore) {
            throw new DomainException(match ($kind) {
                AssemblyQuorumCheckKind::FIRST_CALL => 'First-call quorum cannot be checked before the scheduled meeting time.',
                AssemblyQuorumCheckKind::DELAYED_CALL => 'Delayed-call quorum cannot be checked before the statutory one-hour delay has elapsed.',
                AssemblyQuorumCheckKind::NEXT_DAY_CALL => 'Next-day quorum cannot be checked before the next eligible day at the scheduled local time.',
            });
        }
    }

    private function nextEligibleCallAt(GeneralAssembly $assembly): DateTimeImmutable
    {
        $timezone = new DateTimeZone($assembly->getTimezoneSnapshot());
        $candidate = $assembly->getScheduledAt()->setTimezone($timezone)->modify('+1 day');

        while ($this->isBulgarianNonWorkingDay($candidate)) {
            $candidate = $candidate->modify('+1 day');
        }

        return $candidate->setTimezone(new DateTimeZone('UTC'));
    }

    private function isBulgarianNonWorkingDay(DateTimeImmutable $date): bool
    {
        if ((int) $date->format('N') >= 6) {
            return true;
        }

        $key = $date->format('Y-m-d');
        $year = (int) $date->format('Y');

        foreach ([$year - 1, $year, $year + 1] as $holidayYear) {
            if (isset(self::statutoryNonWorkingDates($holidayYear)[$key])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Bulgarian statutory non-working dates from Labour Code art. 154(1)-(2).
     * One-off additional non-working days under art. 154(3) remain an external legal-calendar input.
     *
     * @return array<string, true>
     */
    private static function statutoryNonWorkingDates(int $year): array
    {
        $timezone = new DateTimeZone('Europe/Sofia');
        $fixed = [
            sprintf('%04d-01-01', $year),
            sprintf('%04d-03-03', $year),
            sprintf('%04d-05-01', $year),
            sprintf('%04d-05-06', $year),
            sprintf('%04d-05-24', $year),
            sprintf('%04d-09-06', $year),
            sprintf('%04d-09-22', $year),
            sprintf('%04d-12-24', $year),
            sprintf('%04d-12-25', $year),
            sprintf('%04d-12-26', $year),
        ];

        $nonWorking = [];
        foreach ($fixed as $date) {
            $nonWorking[$date] = true;
        }

        $easterSunday = self::orthodoxEasterSunday($year)->setTimezone($timezone);
        foreach ([-2, -1, 0, 1] as $offset) {
            $holiday = 0 === $offset
                ? $easterSunday
                : $easterSunday->modify(sprintf('%+d day', $offset));
            $nonWorking[$holiday->format('Y-m-d')] = true;
        }

        foreach ($fixed as $date) {
            $holiday = new DateTimeImmutable($date.' 12:00:00', $timezone);
            if ((int) $holiday->format('N') < 6) {
                continue;
            }

            $substitute = $holiday->modify('+1 day');
            while ((int) $substitute->format('N') >= 6 || isset($nonWorking[$substitute->format('Y-m-d')])) {
                $substitute = $substitute->modify('+1 day');
            }
            $nonWorking[$substitute->format('Y-m-d')] = true;
        }

        return $nonWorking;
    }

    private static function orthodoxEasterSunday(int $year): DateTimeImmutable
    {
        $a = $year % 4;
        $b = $year % 7;
        $c = $year % 19;
        $d = (19 * $c + 15) % 30;
        $e = (2 * $a + 4 * $b - $d + 34) % 7;
        $month = intdiv($d + $e + 114, 31);
        $day = (($d + $e + 114) % 31) + 1;
        $julianToGregorianDays = intdiv($year, 100) - intdiv($year, 400) - 2;

        return (new DateTimeImmutable(
            sprintf('%04d-%02d-%02d 12:00:00', $year, $month, $day),
            new DateTimeZone('UTC'),
        ))->modify(sprintf('+%d days', $julianToGregorianDays));
    }

    private function entryKey(AssemblyElectorateEntry $entry): string
    {
        return null !== $entry->getId() ? 'id:'.$entry->getId() : 'object:'.spl_object_id($entry);
    }
}
