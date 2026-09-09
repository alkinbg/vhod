<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\OfficialAnnouncement;
use App\Enum\OfficialAnnouncementStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<OfficialAnnouncement> */
final class OfficialAnnouncementRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, OfficialAnnouncement::class);
    }

    /** @return list<OfficialAnnouncement> */
    public function findPublished(): array
    {
        /** @var list<OfficialAnnouncement> $announcements */
        $announcements = $this->createQueryBuilder('a')
            ->andWhere('a.status = :status')
            ->setParameter('status', OfficialAnnouncementStatus::PUBLISHED->value)
            ->orderBy('a.publishedAt', 'DESC')
            ->addOrderBy('a.id', 'DESC')
            ->getQuery()
            ->getResult();

        return $announcements;
    }
}
