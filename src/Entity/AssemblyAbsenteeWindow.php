<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\AgendaItemStatus;
use App\Enum\AssemblyVoteDenominator;
use App\Enum\GeneralAssemblyStatus;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use DomainException;
use InvalidArgumentException;

#[ORM\Entity]
#[ORM\Table(name: 'assembly_absentee_window')]
#[ORM\UniqueConstraint(name: 'uniq_absentee_window_assembly', columns: ['assembly_id'])]
#[ORM\Index(name: 'idx_absentee_deadline', columns: ['deadline_at'])]
#[ORM\Index(name: 'idx_absentee_window_opened_by', columns: ['opened_by_id'])]
#[ORM\Index(name: 'idx_absentee_window_closed_by', columns: ['closed_by_id'])]
final class AssemblyAbsenteeWindow
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'assembly_id', nullable: false, onDelete: 'RESTRICT', foreignKeyName: 'fk_absentee_window_assembly')]
    private GeneralAssembly $assembly;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'opened_by_id', nullable: false, onDelete: 'RESTRICT', foreignKeyName: 'fk_absentee_window_opened_by')]
    private User $openedBy;

    #[ORM\Column(type: 'datetime_immutable')]
    private DateTimeImmutable $openedAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private DateTimeImmutable $deadlineAt;

    #[ORM\Column(type: 'text')]
    private string $legalBasis;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'closed_by_id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_absentee_window_closed_by')]
    private ?User $closedBy = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $closedAt = null;

    /** @var Collection<int, AssemblyAgendaItem> */
    #[ORM\ManyToMany(targetEntity: AssemblyAgendaItem::class)]
    #[ORM\JoinTable(name: 'assembly_absentee_window_item')]
    #[ORM\JoinColumn(name: 'window_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT', foreignKeyName: 'fk_absentee_window_item_window')]
    #[ORM\InverseJoinColumn(name: 'agenda_item_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT', foreignKeyName: 'fk_absentee_window_item_item')]
    private Collection $agendaItems;

    private function __construct(
        GeneralAssembly $assembly,
        User $openedBy,
        DateTimeImmutable $openedAt,
        DateTimeImmutable $deadlineAt,
        string $legalBasis,
    ) {
        if (GeneralAssemblyStatus::CLOSED !== $assembly->getStatus()) {
            throw new DomainException('Absentee voting may be opened only after the General Assembly is closed.');
        }

        $openedAt = self::toUtc($openedAt);
        $deadlineAt = self::toUtc($deadlineAt);
        if ($deadlineAt <= $openedAt) {
            throw new InvalidArgumentException('Absentee voting deadline must be after the opening time.');
        }
        $meetingClosedAt = $assembly->getClosedAt();
        if (null !== $meetingClosedAt && $openedAt < $meetingClosedAt) {
            throw new InvalidArgumentException('Absentee voting cannot open before the General Assembly is closed.');
        }

        $legalBasis = trim($legalBasis);
        if ('' === $legalBasis) {
            throw new InvalidArgumentException('Absentee voting requires a legal-basis note.');
        }

        $this->assembly = $assembly;
        $this->openedBy = $openedBy;
        $this->openedAt = $openedAt;
        $this->deadlineAt = $deadlineAt;
        $this->legalBasis = $legalBasis;
        $this->agendaItems = new ArrayCollection();
    }

    public static function open(
        GeneralAssembly $assembly,
        User $openedBy,
        DateTimeImmutable $openedAt,
        DateTimeImmutable $deadlineAt,
        string $legalBasis,
    ): self {
        return new self($assembly, $openedBy, $openedAt, $deadlineAt, $legalBasis);
    }

    public function addAgendaItem(AssemblyAgendaItem $item): void
    {
        if ($item->getAssembly() !== $this->assembly) {
            throw new DomainException('Absentee agenda item must belong to the same General Assembly.');
        }
        if (AgendaItemStatus::OPEN !== $item->getStatus()) {
            throw new DomainException('Only an open agenda item can enter an absentee voting window.');
        }
        if (AssemblyVoteDenominator::ELIGIBLE_ABSENTEE_UNIVERSE !== $item->getMajorityRule()->getDenominator()) {
            throw new DomainException('Agenda item is not explicitly eligible for absentee voting.');
        }
        if ($this->agendaItems->contains($item)) {
            throw new DomainException('Agenda item is already part of this absentee voting window.');
        }

        $this->agendaItems->add($item);
    }

    public function acceptsSubmissionAt(DateTimeImmutable $submittedAt): bool
    {
        $submittedAt = self::toUtc($submittedAt);

        return null === $this->closedAt
            && $submittedAt >= $this->openedAt
            && $submittedAt <= $this->deadlineAt;
    }

    public function close(User $actor, DateTimeImmutable $closedAt): void
    {
        if (null !== $this->closedAt) {
            throw new DomainException('Absentee voting window is already closed.');
        }

        $closedAt = self::toUtc($closedAt);
        if ($closedAt < $this->deadlineAt) {
            throw new InvalidArgumentException('Absentee voting window cannot close before its deadline.');
        }

        $this->closedBy = $actor;
        $this->closedAt = $closedAt;
    }

    public function containsAgendaItem(AssemblyAgendaItem $item): bool
    {
        return $this->agendaItems->contains($item);
    }

    public function getId(): ?int { return $this->id; }
    public function getAssembly(): GeneralAssembly { return $this->assembly; }
    public function getOpenedBy(): User { return $this->openedBy; }
    public function getOpenedAt(): DateTimeImmutable { return $this->openedAt; }
    public function getDeadlineAt(): DateTimeImmutable { return $this->deadlineAt; }
    public function getLegalBasis(): string { return $this->legalBasis; }
    public function getClosedBy(): ?User { return $this->closedBy; }
    public function getClosedAt(): ?DateTimeImmutable { return $this->closedAt; }

    /** @return Collection<int, AssemblyAgendaItem> */
    public function getAgendaItems(): Collection { return $this->agendaItems; }

    private static function toUtc(DateTimeImmutable $dateTime): DateTimeImmutable
    {
        return $dateTime->setTimezone(new DateTimeZone('UTC'));
    }
}
