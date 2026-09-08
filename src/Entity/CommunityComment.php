<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\CommunityContentStatus;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\Mapping as ORM;
use InvalidArgumentException;

#[ORM\Entity]
#[ORM\Table(name: 'community_comment')]
#[ORM\Index(name: 'idx_community_comment_post_status_created', columns: ['post_id', 'status', 'created_at'])]
class CommunityComment
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private CommunityPost $post;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private User $author;

    #[ORM\Column(type: 'text')]
    private string $body;

    #[ORM\Column(enumType: CommunityContentStatus::class)]
    private CommunityContentStatus $status;

    #[ORM\Column(type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    private function __construct(
        CommunityPost $post,
        User $author,
        string $body,
        DateTimeImmutable $createdAt,
    ) {
        $body = trim($body);
        if ('' === $body || mb_strlen($body) > 5000) {
            throw new InvalidArgumentException('Community comment must contain between 1 and 5000 characters.');
        }

        $this->post = $post;
        $this->author = $author;
        $this->body = $body;
        $this->status = CommunityContentStatus::PUBLISHED;
        $this->createdAt = self::toUtc($createdAt);
    }

    public static function write(
        CommunityPost $post,
        User $author,
        string $body,
        DateTimeImmutable $createdAt,
    ): self {
        return new self($post, $author, $body, $createdAt);
    }

    public function getId(): ?int { return $this->id; }
    public function getPost(): CommunityPost { return $this->post; }
    public function getAuthor(): User { return $this->author; }
    public function getBody(): string { return $this->body; }
    public function getStatus(): CommunityContentStatus { return $this->status; }
    public function getCreatedAt(): DateTimeImmutable { return $this->createdAt; }

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
