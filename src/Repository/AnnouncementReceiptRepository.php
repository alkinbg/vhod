<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AnnouncementReceipt;
use App\Entity\OfficialAnnouncement;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<AnnouncementReceipt> */
final class AnnouncementReceiptRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AnnouncementReceipt::class);
    }

    public function findFor(User $user, OfficialAnnouncement $announcement): ?AnnouncementReceipt
    {
        return $this->findOneBy(['user' => $user, 'announcement' => $announcement]);
    }

    public function countUnreadFor(User $user): int
    {
        return (int) $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->andWhere('r.user = :user')
            ->andWhere('r.readAt IS NULL')
            ->setParameter('user', $user)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** @return list<AnnouncementReceipt> */
    public function findUnreadFor(User $user, int $limit = 5): array
    {
        if ($limit < 1) {
            return [];
        }

        /** @var list<AnnouncementReceipt> $receipts */
        $receipts = $this->createQueryBuilder('r')
            ->andWhere('r.user = :user')
            ->andWhere('r.readAt IS NULL')
            ->setParameter('user', $user)
            ->orderBy('r.availableAt', 'DESC')
            ->addOrderBy('r.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $receipts;
    }

    public function countForAnnouncement(OfficialAnnouncement $announcement): int
    {
        return (int) $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->andWhere('r.announcement = :announcement')
            ->setParameter('announcement', $announcement)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countReadForAnnouncement(OfficialAnnouncement $announcement): int
    {
        return (int) $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->andWhere('r.announcement = :announcement')
            ->andWhere('r.readAt IS NOT NULL')
            ->setParameter('announcement', $announcement)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
