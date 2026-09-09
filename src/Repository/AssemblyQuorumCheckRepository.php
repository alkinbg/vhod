<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AssemblyQuorumCheck;
use App\Entity\GeneralAssembly;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<AssemblyQuorumCheck> */
final class AssemblyQuorumCheckRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AssemblyQuorumCheck::class);
    }

    public function findLatestForAssembly(GeneralAssembly $assembly): ?AssemblyQuorumCheck
    {
        return $this->findOneBy(['assembly' => $assembly], ['checkedAt' => 'DESC', 'id' => 'DESC']);
    }

    /** @return list<AssemblyQuorumCheck> */
    public function findForAssembly(GeneralAssembly $assembly): array
    {
        return $this->findBy(['assembly' => $assembly], ['checkedAt' => 'DESC', 'id' => 'DESC']);
    }
}
