<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\CommunityPostType;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\Mapping as ORM;
use InvalidArgumentException;

#[ORM\Entity]
#[ORM\Table(name: 'community_poll_vote')]
#[ORM\UniqueConstraint(name: 'uniq_community_poll_vote_post_voter', columns: ['post_id', 'voter_id'])]
class CommunityPollVote
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'post_id', nullable: false, onDelete: 'RESTRICT')]
    private CommunityPost $poll;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private CommunityPollOption $option;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private User $voter;

    #[ORM\Column(type: 'datetime_immutable')]
    private DateTimeImmutable $votedAt;

    private function __construct(CommunityPost $poll, CommunityPollOption $option, User $voter, DateTimeImmutable $votedAt)
    {
        self::assertCompatible($poll, $option);
        $this->poll = $poll;
        $this->option = $option;
        $this->voter = $voter;
        $this->votedAt = self::toUtc($votedAt);
    }

    public static function cast(CommunityPost $poll, CommunityPollOption $option, User $voter, DateTimeImmutable $votedAt): self
    {
        return new self($poll, $option, $voter, $votedAt);
    }

    public function getId(): ?int { return $this->id; }
    public function getPoll(): CommunityPost { return $this->poll; }
    public function getOption(): CommunityPollOption { return $this->option; }
    public function getVoter(): User { return $this->voter; }
    public function getVotedAt(): DateTimeImmutable { return $this->votedAt; }

    public function changeOption(CommunityPollOption $option, DateTimeImmutable $votedAt): void
    {
        self::assertCompatible($this->poll, $option);
        $this->option = $option;
        $this->votedAt = self::toUtc($votedAt);
    }

    private static function assertCompatible(CommunityPost $poll, CommunityPollOption $option): void
    {
        if (CommunityPostType::POLL !== $poll->getType()) {
            throw new InvalidArgumentException('Votes may be cast only for poll posts.');
        }
        if ($option->getPoll() !== $poll) {
            throw new InvalidArgumentException('Poll option does not belong to the selected poll.');
        }
    }

    private static function toUtc(DateTimeImmutable $dateTime): DateTimeImmutable
    {
        return $dateTime->setTimezone(new DateTimeZone('UTC'));
    }
}
