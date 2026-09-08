<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\CommunityReportStatus;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\Mapping as ORM;
use InvalidArgumentException;
use LogicException;

#[ORM\Entity]
#[ORM\Table(name: 'community_report')]
#[ORM\Index(name: 'idx_community_report_status_created', columns: ['status', 'created_at'])]
#[ORM\UniqueConstraint(name: 'uniq_community_report_open_post', columns: ['reporter_id', 'post_id', 'open_marker'])]
#[ORM\UniqueConstraint(name: 'uniq_community_report_open_comment', columns: ['reporter_id', 'comment_id', 'open_marker'])]
class CommunityReport
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private User $reporter;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'RESTRICT')]
    private ?CommunityPost $post;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'RESTRICT')]
    private ?CommunityComment $comment;

    #[ORM\Column(type: 'text')]
    private string $reason;

    #[ORM\Column(enumType: CommunityReportStatus::class)]
    private CommunityReportStatus $status;

    #[ORM\Column(type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $resolvedAt = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'RESTRICT')]
    private ?User $resolver = null;

    #[ORM\Column(nullable: true)]
    private ?int $openMarker = 1;

    private function __construct(User $reporter, ?CommunityPost $post, ?CommunityComment $comment, string $reason, DateTimeImmutable $createdAt)
    {
        if ((null === $post) === (null === $comment)) {
            throw new InvalidArgumentException('Community report must target exactly one post or comment.');
        }
        $reason = trim($reason);
        if ('' === $reason || mb_strlen($reason) > 2000) {
            throw new InvalidArgumentException('Community report reason must contain between 1 and 2000 characters.');
        }
        $this->reporter = $reporter;
        $this->post = $post;
        $this->comment = $comment;
        $this->reason = $reason;
        $this->status = CommunityReportStatus::OPEN;
        $this->createdAt = self::toUtc($createdAt);
    }

    public static function forPost(User $reporter, CommunityPost $post, string $reason, DateTimeImmutable $createdAt): self
    {
        return new self($reporter, $post, null, $reason, $createdAt);
    }

    public static function forComment(User $reporter, CommunityComment $comment, string $reason, DateTimeImmutable $createdAt): self
    {
        return new self($reporter, null, $comment, $reason, $createdAt);
    }

    public function getId(): ?int { return $this->id; }
    public function getReporter(): User { return $this->reporter; }
    public function getPost(): ?CommunityPost { return $this->post; }
    public function getComment(): ?CommunityComment { return $this->comment; }
    public function getReason(): string { return $this->reason; }
    public function getStatus(): CommunityReportStatus { return $this->status; }
    public function getCreatedAt(): DateTimeImmutable { return $this->createdAt; }
    public function getResolvedAt(): ?DateTimeImmutable { return $this->resolvedAt; }
    public function getResolver(): ?User { return $this->resolver; }
    public function getOpenMarker(): ?int { return $this->openMarker; }

    public function resolve(User $resolver, DateTimeImmutable $resolvedAt): void
    {
        if (CommunityReportStatus::RESOLVED === $this->status) {
            throw new LogicException('Community report is already resolved.');
        }
        $this->status = CommunityReportStatus::RESOLVED;
        $this->resolver = $resolver;
        $this->resolvedAt = self::toUtc($resolvedAt);
        $this->openMarker = null;
    }

    private static function toUtc(DateTimeImmutable $dateTime): DateTimeImmutable
    {
        return $dateTime->setTimezone(new DateTimeZone('UTC'));
    }
}
