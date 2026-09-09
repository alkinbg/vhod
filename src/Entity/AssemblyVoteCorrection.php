<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\AssemblyVoteChoice;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\Mapping as ORM;
use InvalidArgumentException;

#[ORM\Entity]
#[ORM\Table(name: 'assembly_vote_correction')]
#[ORM\Index(name: 'idx_vote_correction_vote', columns: ['vote_id'])]
#[ORM\Index(name: 'idx_vote_correction_changed_by', columns: ['changed_by_id'])]
final class AssemblyVoteCorrection
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'vote_id', nullable: false, onDelete: 'RESTRICT', foreignKeyName: 'fk_vote_correction_vote')]
    private AssemblyVote $vote;

    #[ORM\Column(length: 16, enumType: AssemblyVoteChoice::class)]
    private AssemblyVoteChoice $previousChoice;

    #[ORM\Column(length: 16, enumType: AssemblyVoteChoice::class)]
    private AssemblyVoteChoice $newChoice;

    #[ORM\Column(type: 'text')]
    private string $reason;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'changed_by_id', nullable: false, onDelete: 'RESTRICT', foreignKeyName: 'fk_vote_correction_changed_by')]
    private User $changedBy;

    #[ORM\Column(type: 'datetime_immutable')]
    private DateTimeImmutable $changedAt;

    private function __construct(
        AssemblyVote $vote,
        AssemblyVoteChoice $previousChoice,
        AssemblyVoteChoice $newChoice,
        string $reason,
        User $changedBy,
        DateTimeImmutable $changedAt,
    ) {
        $reason = trim($reason);
        if ('' === $reason) {
            throw new InvalidArgumentException('Formal vote correction reason cannot be blank.');
        }

        $this->vote = $vote;
        $this->previousChoice = $previousChoice;
        $this->newChoice = $newChoice;
        $this->reason = $reason;
        $this->changedBy = $changedBy;
        $this->changedAt = $changedAt->setTimezone(new DateTimeZone('UTC'));
    }

    public static function record(
        AssemblyVote $vote,
        AssemblyVoteChoice $previousChoice,
        AssemblyVoteChoice $newChoice,
        string $reason,
        User $changedBy,
        DateTimeImmutable $changedAt,
    ): self {
        return new self($vote, $previousChoice, $newChoice, $reason, $changedBy, $changedAt);
    }

    public function getId(): ?int { return $this->id; }
    public function getVote(): AssemblyVote { return $this->vote; }
    public function getPreviousChoice(): AssemblyVoteChoice { return $this->previousChoice; }
    public function getNewChoice(): AssemblyVoteChoice { return $this->newChoice; }
    public function getReason(): string { return $this->reason; }
    public function getChangedBy(): User { return $this->changedBy; }
    public function getChangedAt(): DateTimeImmutable { return $this->changedAt; }
}
