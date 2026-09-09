<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AssemblyAttendance;
use App\Entity\AssemblyElectorateEntry;
use App\Entity\GeneralAssembly;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

final class AssemblyAttendanceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AssemblyAttendance::class);
    }

    public function findForPrincipal(GeneralAssembly $assembly, AssemblyElectorateEntry $entry): ?AssemblyAttendance
    {
        return $this->findOneBy(['assembly' => $assembly, 'electorateEntry' => $entry]);
    }
}
