<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AssemblyElectorateEntry;
use App\Entity\AssemblyProxy;
use App\Entity\Document;
use App\Entity\GeneralAssembly;
use App\Entity\Person;
use App\Entity\User;
use App\Enum\GeneralAssemblyStatus;
use App\Repository\AssemblyAttendanceRepository;
use App\Repository\AssemblyProxyRepository;
use App\Security\GeneralAssemblyAccessPolicy;
use DateTimeImmutable;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use DomainException;

final readonly class AssemblyProxyService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private GeneralAssemblyAccessPolicy $accessPolicy,
        private AssemblyProxyRepository $proxies,
        private AssemblyAttendanceRepository $attendance,
    ) {}

    public function register(
        User $actor,
        GeneralAssembly $assembly,
        AssemblyElectorateEntry $principal,
        ?Person $representativePerson,
        string $representativeName,
        string $authorityKind,
        Document $evidenceDocument,
        DateTimeImmutable $registeredAt,
        ?string $notes = null,
    ): AssemblyProxy {
        $this->assertManager($actor);

        return $this->entityManager->wrapInTransaction(function (EntityManagerInterface $entityManager) use ($actor, $assembly, $principal, $representativePerson, $representativeName, $authorityKind, $evidenceDocument, $registeredAt, $notes): AssemblyProxy {
            $entityManager->lock($assembly, LockMode::PESSIMISTIC_WRITE);
            $this->assertMutable($assembly);

            if ($principal->getAssembly() !== $assembly) {
                throw new DomainException('Proxy principal does not belong to this General Assembly.');
            }
            if (null !== $this->proxies->findEffectiveForPrincipal($principal)) {
                throw new DomainException('Principal already has an effective proxy.');
            }
            if (null !== $this->attendance->findForPrincipal($assembly, $principal)) {
                throw new DomainException('Principal already has attendance recorded and cannot also be proxied.');
            }
            if (3 <= $this->proxies->countEffectiveForRepresentative($assembly, $representativePerson, $representativeName)) {
                throw new DomainException('A representative cannot represent more than three principals in this workflow.');
            }

            $proxy = AssemblyProxy::register(
                $assembly,
                $principal,
                $representativePerson,
                $representativeName,
                $authorityKind,
                $evidenceDocument,
                $actor,
                $registeredAt,
                $notes,
            );
            $entityManager->persist($proxy);

            return $proxy;
        });
    }

    public function revoke(User $actor, AssemblyProxy $proxy, string $reason, DateTimeImmutable $revokedAt): void
    {
        $this->assertManager($actor);

        $this->entityManager->wrapInTransaction(function (EntityManagerInterface $entityManager) use ($actor, $proxy, $reason, $revokedAt): void {
            $assembly = $proxy->getAssembly();
            $entityManager->lock($assembly, LockMode::PESSIMISTIC_WRITE);
            $this->assertMutable($assembly);
            $proxy->revoke($actor, $reason, $revokedAt);
        });
    }

    public function effectiveForPrincipal(AssemblyElectorateEntry $principal): ?AssemblyProxy
    {
        return $this->proxies->findEffectiveForPrincipal($principal);
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
            throw new DomainException('Proxy representation may be changed only after convening and before meeting close.');
        }
    }
}
