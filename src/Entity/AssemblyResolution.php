<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\AssemblyResolutionResult;
use App\Util\ExactDecimal;
use App\Value\AssemblyResolutionCalculation;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'assembly_resolution')]
#[ORM\UniqueConstraint(name: 'uniq_assembly_resolution_item', columns: ['agenda_item_id'])]
#[ORM\Index(name: 'idx_assembly_resolution_resolved_by', columns: ['resolved_by_id'])]
final class AssemblyResolution
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne]
    #[ORM\JoinColumn(name: 'agenda_item_id', nullable: false, onDelete: 'RESTRICT', foreignKeyName: 'fk_assembly_resolution_item')]
    private AssemblyAgendaItem $agendaItem;

    #[ORM\Column(type: 'decimal', precision: 14, scale: 8)]
    private string $forIdealPartsPercent;

    #[ORM\Column(type: 'decimal', precision: 14, scale: 8)]
    private string $againstIdealPartsPercent;

    #[ORM\Column(type: 'decimal', precision: 14, scale: 8)]
    private string $abstainIdealPartsPercent;

    #[ORM\Column(type: 'decimal', precision: 14, scale: 8)]
    private string $denominatorIdealPartsPercent;

    #[ORM\Column(type: 'decimal', precision: 14, scale: 8)]
    private string $requiredIdealPartsPercent;

    #[ORM\Column(length: 32, enumType: AssemblyResolutionResult::class)]
    private AssemblyResolutionResult $result;

    #[ORM\Column(type: 'text')]
    private string $explanation;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'resolved_by_id', nullable: false, onDelete: 'RESTRICT', foreignKeyName: 'fk_assembly_resolution_resolved_by')]
    private User $resolvedBy;

    #[ORM\Column(type: 'datetime_immutable')]
    private DateTimeImmutable $resolvedAt;

    private function __construct(
        AssemblyAgendaItem $agendaItem,
        AssemblyResolutionCalculation $calculation,
        User $resolvedBy,
        DateTimeImmutable $resolvedAt,
    ) {
        $this->agendaItem = $agendaItem;
        $this->forIdealPartsPercent = $calculation->forIdealPartsPercent;
        $this->againstIdealPartsPercent = $calculation->againstIdealPartsPercent;
        $this->abstainIdealPartsPercent = $calculation->abstainIdealPartsPercent;
        $this->denominatorIdealPartsPercent = $calculation->denominatorIdealPartsPercent;
        $this->requiredIdealPartsPercent = $calculation->requiredIdealPartsPercent;
        $this->result = $calculation->result;
        $this->explanation = $calculation->explanation;
        $this->resolvedBy = $resolvedBy;
        $this->resolvedAt = $resolvedAt->setTimezone(new DateTimeZone('UTC'));
    }

    public static function record(
        AssemblyAgendaItem $agendaItem,
        AssemblyResolutionCalculation $calculation,
        User $resolvedBy,
        DateTimeImmutable $resolvedAt,
    ): self {
        return new self($agendaItem, $calculation, $resolvedBy, $resolvedAt);
    }

    public function getId(): ?int { return $this->id; }
    public function getAgendaItem(): AssemblyAgendaItem { return $this->agendaItem; }
    public function getForIdealPartsPercent(): string { return ExactDecimal::normalize($this->forIdealPartsPercent); }
    public function getAgainstIdealPartsPercent(): string { return ExactDecimal::normalize($this->againstIdealPartsPercent); }
    public function getAbstainIdealPartsPercent(): string { return ExactDecimal::normalize($this->abstainIdealPartsPercent); }
    public function getDenominatorIdealPartsPercent(): string { return ExactDecimal::normalize($this->denominatorIdealPartsPercent); }
    public function getRequiredIdealPartsPercent(): string { return ExactDecimal::normalize($this->requiredIdealPartsPercent); }
    public function getResult(): AssemblyResolutionResult { return $this->result; }
    public function getExplanation(): string { return $this->explanation; }
    public function getResolvedBy(): User { return $this->resolvedBy; }
    public function getResolvedAt(): DateTimeImmutable { return $this->resolvedAt; }
}
