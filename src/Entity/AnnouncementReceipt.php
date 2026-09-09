<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\AnnouncementReceiptRepository;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\Mapping as ORM;
use DomainException;
use InvalidArgumentException;

#[ORM\Entity(repositoryClass: AnnouncementReceiptRepository::class)]
#[ORM\Table(name: 'announcement_receipt')]
#[ORM\Index(name: 'idx_announcement_receipt_user_read', columns: ['user_id', 'read_at'])]
#[ORM\Index(name: 'idx_announcement_receipt_announcement', columns: ['announcement_id'])]
#[ORM\UniqueConstraint(name: 'uniq_announcement_receipt_announcement_user', columns: ['announcement_id', 'user_id'])]
class AnnouncementReceipt
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'announcement_id', nullable: false, onDelete: 'RESTRICT', foreignKeyName: 'fk_announcement_receipt_announcement')]
    private OfficialAnnouncement $announcement;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'user_id', nullable: false, onDelete: 'RESTRICT', foreignKeyName: 'fk_announcement_receipt_user')]
    private User $user;

    #[ORM\Column(type: 'datetime_immutable')]
    private DateTimeImmutable $availableAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $readAt = null;

    private function __construct(
        OfficialAnnouncement $announcement,
        User $user,
        DateTimeImmutable $availableAt,
    ) {
        if (!$announcement->isPublished()) {
            throw new DomainException('Announcement receipt requires a published announcement.');
        }

        $availableAt = self::toUtc($availableAt);
        $publishedAt = $announcement->getPublishedAt();
        if (null === $publishedAt || $availableAt < $publishedAt) {
            throw new InvalidArgumentException('Announcement cannot be available before publication.');
        }

        $this->announcement = $announcement;
        $this->user = $user;
        $this->availableAt = $availableAt;
    }

    public static function record(
        OfficialAnnouncement $announcement,
        User $user,
        DateTimeImmutable $availableAt,
    ): self {
        return new self($announcement, $user, $availableAt);
    }

    public function markRead(DateTimeImmutable $readAt): void
    {
        if (null !== $this->readAt) {
            return;
        }

        $readAt = self::toUtc($readAt);
        if ($readAt < $this->availableAt) {
            throw new InvalidArgumentException('Announcement cannot be read before it became available.');
        }

        $this->readAt = $readAt;
    }

    public function getId(): ?int { return $this->id; }
    public function getAnnouncement(): OfficialAnnouncement { return $this->announcement; }
    public function getUser(): User { return $this->user; }
    public function getAvailableAt(): DateTimeImmutable { return $this->availableAt; }
    public function getReadAt(): ?DateTimeImmutable { return $this->readAt; }
    public function isRead(): bool { return null !== $this->readAt; }

    private static function toUtc(DateTimeImmutable $dateTime): DateTimeImmutable
    {
        return $dateTime->setTimezone(new DateTimeZone('UTC'));
    }
}
