<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AssemblyAttendance;
use App\Entity\AssemblyAttendanceChange;
use App\Entity\AssemblyElectorateEntry;
use App\Entity\GeneralAssembly;
use App\Entity\Person;
use App\Entity\User;
use App\Enum\AssemblyAttendanceMode;
use App\Enum\GeneralAssemblyStatus;
use App\Repository\AssemblyAttendanceRepository;
use App\Repository\AssemblyProxyRepository;
use App\Security\GeneralAssemblyAccessPolicy;
use DateTimeImmutable;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use DomainException;
use Throwable;

final readonly class AssemblyAttendanceService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private GeneralAssemblyAccessPolicy $accessPolicy,
        private AssemblyAttendanceRepository $attendance,
        private AssemblyProxyRepository $proxies,
    ) {}

    public function register(
        User $actor,
        GeneralAssembly $assembly,
        AssemblyElectorateEntry $entry,
        AssemblyAttendanceMode $mode,
        DateTimeImmutable $registeredAt,
        ?Person $representativePerson = null,
        ?string $representativeName = null,
        ?string $authorityNote = null,
    ): AssemblyAttendance {
        $this->assertManager($actor);

        $connection = $this->entityManager->getConnection();
        $connection->beginTransaction();
        try {
            $this->entityManager->lock($assembly, LockMode::PESSIMISTIC_WRITE);
            $this->assertMutable($assembly);

            if ($entry->getAssembly() !== $assembly) {
                throw new DomainException('Attendance electorate entry does not belong to this General Assembly.');
            }
            if (null !== $this->attendance->findForPrincipal($assembly, $entry)) {
                throw new DomainException('Attendance for this principal is already recorded.');
            }

            if (AssemblyAttendanceMode::BY_PROXY === $mode) {
                $proxy = $this->proxies->findEffectiveForPrincipal($entry);
                if (null === $proxy) {
                    throw new DomainException('Proxy attendance requires an effective registered proxy.');
                }
                $representativePerson = $proxy->getRepresentativePerson();
                $representativeName = $proxy->getRepresentativeName();
            } elseif (null !== $this->proxies->findEffectiveForPrincipal($entry)) {
                throw new DomainException('A proxied principal cannot also be recorded as personally represented.');
            }

            $attendance = AssemblyAttendance::register(
                $assembly,
                $entry,
                $mode,
                $actor,
                $registeredAt,
                $representativePerson,
                $representativeName,
                $authorityNote,
            );
            $this->entityManager->persist($attendance);
            $this->entityManager->flush();
            $connection->commit();

            return $attendance;
        } catch (Throwable $exception) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            throw $exception;
        }
    }

    public function correct(
        User $actor,
        AssemblyAttendance $attendance,
        AssemblyAttendanceMode $newMode,
        string $reason,
        DateTimeImmutable $changedAt,
    ): AssemblyAttendanceChange {
        $this->assertManager($actor);

        $connection = $this->entityManager->getConnection();
        $connection->beginTransaction();
        try {
            $assembly = $attendance->getAssembly();
            $this->entityManager->lock($assembly, LockMode::PESSIMISTIC_WRITE);
            $this->assertMutable($assembly);

            $representativePerson = null;
            $representativeName = null;
            $authorityNote = null;
            if (AssemblyAttendanceMode::BY_PROXY === $newMode) {
                $proxy = $this->proxies->findEffectiveForPrincipal($attendance->getElectorateEntry());
                if (null === $proxy) {
                    throw new DomainException('Proxy attendance correction requires an effective registered proxy.');
                }
                $representativePerson = $proxy->getRepresentativePerson();
                $representativeName = $proxy->getRepresentativeName();
            } elseif (AssemblyAttendanceMode::STATUTORY_USER_AUTHORITY === $newMode) {
                $authorityNote = $attendance->getAuthorityNote();
                if (null === $authorityNote) {
                    throw new DomainException('Statutory authority correction requires an existing explicit authority note.');
                }
            } elseif (null !== $this->proxies->findEffectiveForPrincipal($attendance->getElectorateEntry())) {
                throw new DomainException('A proxied principal cannot be corrected to personal representation while the proxy is effective.');
            }

            $oldMode = $attendance->getMode();
            $change = AssemblyAttendanceChange::record($attendance, $oldMode, $newMode, $reason, $actor, $changedAt);
            $attendance->changeMode($newMode, $representativePerson, $representativeName, $authorityNote);
            $this->entityManager->persist($change);
            $this->entityManager->flush();
            $connection->commit();

            return $change;
        } catch (Throwable $exception) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            throw $exception;
        }
    }

    private function assertManager(User $actor): void
    {
        if (!$this->accessPolicy->canManage($actor)) {
            throw new DomainException('General Assembly management access is required.');
        }
    }

    private function assertMutable(GeneralAssembly $assembly): void
    {
        if (!in_array($assembly->getStatus(), [GeneralAssemblyStatus::CONVENED, GeneralAssemblyStatus::IN_PROGRESS], true)) {
            throw new DomainException('Attendance may be changed only after convening and before meeting close.');
        }
    }
}
