<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AssemblyElectorateEntry;
use App\Entity\AssemblyProxy;
use App\Entity\GeneralAssembly;
use App\Entity\Person;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<AssemblyProxy> */
final class AssemblyProxyRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AssemblyProxy::class);
    }

    public function findEffectiveForPrincipal(AssemblyElectorateEntry $entry): ?AssemblyProxy
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.principalEntry = :entry')
            ->andWhere('p.revokedAt IS NULL')
            ->setParameter('entry', $entry)
            ->orderBy('p.registeredAt', 'DESC')
            ->addOrderBy('p.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function countEffectiveForRepresentative(GeneralAssembly $assembly, ?Person $person, string $name): int
    {
        $qb = $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->andWhere('p.assembly = :assembly')
            ->andWhere('p.revokedAt IS NULL')
            ->setParameter('assembly', $assembly);

        if (null !== $person) {
            $qb->andWhere('p.representativePerson = :person')->setParameter('person', $person);
        } else {
            $qb->andWhere('p.representativePerson IS NULL')
                ->andWhere('p.representativeName = :name')
                ->setParameter('name', trim($name));
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }
}
