<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\GeneralAssembly;
use App\Enum\GeneralAssemblyStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<GeneralAssembly> */
final class GeneralAssemblyRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GeneralAssembly::class);
    }

    /** @return list<GeneralAssembly> */
    public function findManagement(): array
    {
        return $this->createQueryBuilder('assembly')
            ->orderBy('assembly.scheduledAt', 'DESC')
            ->addOrderBy('assembly.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /** @return list<GeneralAssembly> */
    public function findResidentVisible(): array
    {
        return $this->createQueryBuilder('assembly')
            ->andWhere('assembly.status != :draft')
            ->setParameter('draft', GeneralAssemblyStatus::DRAFT)
            ->orderBy('assembly.scheduledAt', 'DESC')
            ->addOrderBy('assembly.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findResidentVisibleById(int $id): ?GeneralAssembly
    {
        return $this->createQueryBuilder('assembly')
            ->andWhere('assembly.id = :id')
            ->andWhere('assembly.status != :draft')
            ->setParameter('id', $id)
            ->setParameter('draft', GeneralAssemblyStatus::DRAFT)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
