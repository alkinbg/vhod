<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AnnouncementReceipt;
use App\Entity\Document;
use App\Entity\OfficialAnnouncement;
use App\Entity\User;
use App\Security\DocumentAccessPolicy;
use DateTimeImmutable;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use DomainException;

final readonly class OfficialAnnouncementService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private DocumentAccessPolicy $accessPolicy,
    ) {}

    /** @param iterable<Document> $documents */
    public function createDraft(
        User $actor,
        string $title,
        string $body,
        DateTimeImmutable $createdAt,
        iterable $documents = [],
    ): OfficialAnnouncement {
        $this->assertCanManage($actor);

        $announcement = OfficialAnnouncement::draft($title, $body, $actor, $createdAt, $documents);
        $this->entityManager->persist($announcement);
        $this->entityManager->flush();

        return $announcement;
    }

    /** @param iterable<Document> $documents */
    public function revise(
        User $actor,
        OfficialAnnouncement $announcement,
        string $title,
        string $body,
        iterable $documents,
    ): void {
        $this->assertCanManage($actor);

        $announcement->revise($title, $body, $documents);
        $this->entityManager->flush();
    }

    public function publish(
        User $actor,
        OfficialAnnouncement $announcement,
        DateTimeImmutable $publishedAt,
    ): void {
        $this->assertCanManage($actor);

        $this->entityManager->wrapInTransaction(
            function (EntityManagerInterface $entityManager) use ($actor, $announcement, $publishedAt): void {
                $entityManager->lock($announcement, LockMode::PESSIMISTIC_WRITE);
                $announcement->publish($actor, $publishedAt);

                /** @var list<User> $users */
                $users = $entityManager->getRepository(User::class)->findBy(['active' => true], ['id' => 'ASC']);
                foreach ($users as $user) {
                    $entityManager->persist(AnnouncementReceipt::record($announcement, $user, $publishedAt));
                }
            },
        );
    }

    private function assertCanManage(User $actor): void
    {
        if (!$this->accessPolicy->canManageOfficialContent($actor)) {
            throw new DomainException('Management access is required for official announcements.');
        }
    }
}
