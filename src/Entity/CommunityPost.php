<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\CommunityContentStatus;
use App\Enum\CommunityPostType;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\Mapping as ORM;
use InvalidArgumentException;

#[ORM\Entity]
#[ORM\Table(name: 'community_post')]
#[ORM\Index(name: 'idx_community_post_status_created', columns: ['status', 'created_at'])]
#[ORM\Index(name: 'idx_community_post_type_status_created', columns: ['type', 'status', 'created_at'])]
class CommunityPost
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private User $author;

    #[ORM\Column(enumType: CommunityPostType::class)]
    private CommunityPostType $type;

    #[ORM\Column(length: 160)]
    private string $title;

    #[ORM\Column(type: 'text')]
    private string $body;

    #[ORM\Column(enumType: CommunityContentStatus::class)]
    private CommunityContentStatus $status;

    #[ORM\Column(type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $startsAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $endsAt;

    private function __construct(
        User $author,
        CommunityPostType $type,
        string $title,
        string $body,
        DateTimeImmutable $createdAt,
        ?DateTimeImmutable $startsAt,
        ?DateTimeImmutable $endsAt,
    ) {
        $title = trim($title);
        $body = trim($body);

        if ('' === $title || mb_strlen($title) > 160) {
            throw new InvalidArgumentException('Community post title must contain between 1 and 160 characters.');
        }
        if ('' === $body || mb_strlen($body) > 10000) {
            throw new InvalidArgumentException('Community post body must contain between 1 and 10000 characters.');
        }

        if (CommunityPostType::EVENT === $type) {
            if (null === $startsAt) {
                throw new InvalidArgumentException('Community event start time is required.');
            }
            if (null !== $endsAt && $endsAt <= $startsAt) {
                throw new InvalidArgumentException('Community event end time must be after its start time.');
            }
        } elseif (null !== $startsAt || null !== $endsAt) {
            throw new InvalidArgumentException('Only community event posts may contain event timestamps.');
        }

        $this->author = $author;
        $this->type = $type;
        $this->title = $title;
        $this->body = $body;
        $this->status = CommunityContentStatus::PUBLISHED;
        $this->createdAt = self::toUtc($createdAt);
        $this->startsAt = null === $startsAt ? null : self::toUtc($startsAt);
        $this->endsAt = null === $endsAt ? null : self::toUtc($endsAt);
    }

    public static function publish(
        User $author,
        CommunityPostType $type,
        string $title,
        string $body,
        DateTimeImmutable $createdAt,
        ?DateTimeImmutable $startsAt = null,
        ?DateTimeImmutable $endsAt = null,
    ): self {
        return new self($author, $type, $title, $body, $createdAt, $startsAt, $endsAt);
    }

    public function getId(): ?int { return $this->id; }
    public function getAuthor(): User { return $this->author; }
    public function getType(): CommunityPostType { return $this->type; }
    public function getTitle(): string { return $this->title; }
    public function getBody(): string { return $this->body; }
    public function getStatus(): CommunityContentStatus { return $this->status; }
    public function getCreatedAt(): DateTimeImmutable { return $this->createdAt; }
    public function getStartsAt(): ?DateTimeImmutable { return $this->startsAt; }
    public function getEndsAt(): ?DateTimeImmutable { return $this->endsAt; }

    public function hide(): void
    {
        $this->status = CommunityContentStatus::HIDDEN;
    }

    public function publishAgain(): void
    {
        $this->status = CommunityContentStatus::PUBLISHED;
    }

    public function isPublished(): bool
    {
        return CommunityContentStatus::PUBLISHED === $this->status;
    }

    private static function toUtc(DateTimeImmutable $dateTime): DateTimeImmutable
    {
        return $dateTime->setTimezone(new DateTimeZone('UTC'));
    }
}
