<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AnnouncementReceipt;
use App\Entity\OfficialAnnouncement;
use App\Entity\User;
use App\Repository\AnnouncementReceiptRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;

final readonly class AnnouncementReceiptService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private AnnouncementReceiptRepository $receiptRepository,
    ) {}

    public function markRead(User $user, OfficialAnnouncement $announcement, DateTimeImmutable $readAt): bool
    {
        $receipt = $this->receiptRepository->findFor($user, $announcement);
        if (null === $receipt) {
            return false;
        }

        $receipt->markRead($readAt);
        $this->entityManager->flush();

        return true;
    }

    public function countUnread(User $user): int
    {
        return $this->receiptRepository->countUnreadFor($user);
    }

    /** @return list<AnnouncementReceipt> */
    public function findUnread(User $user, int $limit = 5): array
    {
        return $this->receiptRepository->findUnreadFor($user, $limit);
    }

    /** @return list<int> */
    public function unreadAnnouncementIds(User $user): array
    {
        $count = $this->receiptRepository->countUnreadFor($user);
        if (0 === $count) {
            return [];
        }

        return array_map(
            static function (AnnouncementReceipt $receipt): int {
                $id = $receipt->getAnnouncement()->getId();
                if (null === $id) {
                    throw new LogicException('Unread receipt references a transient announcement.');
                }

                return $id;
            },
            $this->receiptRepository->findUnreadFor($user, $count),
        );
    }
}
