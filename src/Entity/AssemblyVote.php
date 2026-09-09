<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\AssemblyVoteCastMode;
use App\Enum\AssemblyVoteChoice;
use App\Repository\AssemblyVoteRepository;
use App\Util\ExactDecimal;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\Mapping as ORM;
use InvalidArgumentException;

#[ORM\Entity(repositoryClass: AssemblyVoteRepository::class)]
#[ORM\Table(name: 'assembly_vote')]
#[ORM\UniqueConstraint(name: 'uniq_assembly_vote_item_entry', columns: ['agenda_item_id', 'electorate_entry_id'])]
#[ORM\Index(name: 'idx_vote_item_choice', columns: ['agenda_item_id', 'choice'])]
#[ORM\Index(name: 'idx_assembly_vote_entry', columns: ['electorate_entry_id'])]
#[ORM\Index(name: 'idx_assembly_vote_recorded_by', columns: ['recorded_by_id'])]
final class AssemblyVote
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'agenda_item_id', nullable: false, onDelete: 'RESTRICT', foreignKeyName: 'fk_assembly_vote_item')]
    private AssemblyAgendaItem $agendaItem;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'electorate_entry_id', nullable: false, onDelete: 'RESTRICT', foreignKeyName: 'fk_assembly_vote_entry')]
    private AssemblyElectorateEntry $electorateEntry;

    #[ORM\Column(length: 16, enumType: AssemblyVoteChoice::class)]
    private AssemblyVoteChoice $choice;

    #[ORM\Column(length: 32, enumType: AssemblyVoteCastMode::class)]
    private AssemblyVoteCastMode $castMode;

    #[ORM\Column(type: 'decimal', precision: 14, scale: 8)]
    private string $weightIdealPartsPercent;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'recorded_by_id', nullable: false, onDelete: 'RESTRICT', foreignKeyName: 'fk_assembly_vote_recorded_by')]
    private User $recordedBy;

    #[ORM\Column(type: 'datetime_immutable')]
    private DateTimeImmutable $recordedAt;

    private function __construct(
        AssemblyAgendaItem $agendaItem,
        AssemblyElectorateEntry $electorateEntry,
        AssemblyVoteChoice $choice,
        string $weightIdealPartsPercent,
        User $recordedBy,
        DateTimeImmutable $recordedAt,
        AssemblyVoteCastMode $castMode,
    ) {
        if ($electorateEntry->getAssembly() !== $agendaItem->getAssembly()) {
            throw new InvalidArgumentException('Formal vote electorate entry must belong to the agenda item meeting.');
        }

        $weight = ExactDecimal::normalize($weightIdealPartsPercent);
        if (ExactDecimal::compare($weight, '0') <= 0 || ExactDecimal::compare($weight, '100') > 0) {
            throw new InvalidArgumentException('Formal vote weight must be greater than zero and at most 100 percent.');
        }

        $this->agendaItem = $agendaItem;
        $this->electorateEntry = $electorateEntry;
        $this->choice = $choice;
        $this->weightIdealPartsPercent = $weight;
        $this->recordedBy = $recordedBy;
        $this->recordedAt = $recordedAt->setTimezone(new DateTimeZone('UTC'));
        $this->castMode = $castMode;
    }

    public static function record(
        AssemblyAgendaItem $agendaItem,
        AssemblyElectorateEntry $electorateEntry,
        AssemblyVoteChoice $choice,
        string $weightIdealPartsPercent,
        User $recordedBy,
        DateTimeImmutable $recordedAt,
        AssemblyVoteCastMode $castMode = AssemblyVoteCastMode::ATTENDANCE,
    ): self {
        return new self($agendaItem, $electorateEntry, $choice, $weightIdealPartsPercent, $recordedBy, $recordedAt, $castMode);
    }

    public function correctChoice(AssemblyVoteChoice $choice): void
    {
        $this->choice = $choice;
    }

    public function getId(): ?int { return $this->id; }
    public function getAgendaItem(): AssemblyAgendaItem { return $this->agendaItem; }
    public function getElectorateEntry(): AssemblyElectorateEntry { return $this->electorateEntry; }
    public function getChoice(): AssemblyVoteChoice { return $this->choice; }
    public function getCastMode(): AssemblyVoteCastMode { return $this->castMode; }
    public function getWeightIdealPartsPercent(): string { return ExactDecimal::normalize($this->weightIdealPartsPercent); }
    public function getRecordedBy(): User { return $this->recordedBy; }
    public function getRecordedAt(): DateTimeImmutable { return $this->recordedAt; }
}
