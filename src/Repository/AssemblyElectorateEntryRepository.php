<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AssemblyElectorateEntry;
use App\Entity\GeneralAssembly;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<AssemblyElectorateEntry> */
final class AssemblyElectorateEntryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AssemblyElectorateEntry::class);
    }

    /** @return list<AssemblyElectorateEntry> */
    public function findForAssembly(GeneralAssembly $assembly): array
    {
        return $this->createQueryBuilder('entry')
            ->andWhere('entry.assembly = :assembly')
            ->setParameter('assembly', $assembly)
            ->orderBy('entry.unitDesignationSnapshot', 'ASC')
            ->addOrderBy('entry.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function hasForAssembly(GeneralAssembly $assembly): bool
    {
        return 0 < $this->count(['assembly' => $assembly]);
    }
}
