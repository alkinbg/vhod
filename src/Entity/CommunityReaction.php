<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\CommunityReactionType;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'community_reaction')]
#[ORM\UniqueConstraint(name: 'uniq_community_reaction_post_user', columns: ['post_id', 'user_id'])]
class CommunityReaction
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
    private User $user;

    #[ORM\Column(enumType: CommunityReactionType::class)]
    private CommunityReactionType $type;

    #[ORM\Column(type: 'datetime_immutable')]
    private DateTimeImmutable $reactedAt;

    private function __construct(CommunityPost $post, User $user, CommunityReactionType $type, DateTimeImmutable $reactedAt)
    {
        $this->post = $post;
        $this->user = $user;
        $this->type = $type;
        $this->reactedAt = self::toUtc($reactedAt);
    }

    public static function react(CommunityPost $post, User $user, CommunityReactionType $type, DateTimeImmutable $reactedAt): self
    {
        return new self($post, $user, $type, $reactedAt);
    }

    public function getId(): ?int { return $this->id; }
    public function getPost(): CommunityPost { return $this->post; }
    public function getUser(): User { return $this->user; }
    public function getType(): CommunityReactionType { return $this->type; }
    public function getReactedAt(): DateTimeImmutable { return $this->reactedAt; }

    public function changeType(CommunityReactionType $type, DateTimeImmutable $reactedAt): void
    {
        $this->type = $type;
        $this->reactedAt = self::toUtc($reactedAt);
    }

    private static function toUtc(DateTimeImmutable $dateTime): DateTimeImmutable
    {
        return $dateTime->setTimezone(new DateTimeZone('UTC'));
    }
}
