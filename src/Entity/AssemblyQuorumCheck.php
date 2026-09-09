<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\AssemblyLegalResult;
use App\Enum\AssemblyQuorumCheckKind;
use App\Repository\AssemblyQuorumCheckRepository;
use App\Value\AssemblyQuorumCalculation;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AssemblyQuorumCheckRepository::class)]
#[ORM\Table(name: 'assembly_quorum_check')]
#[ORM\Index(name: 'idx_quorum_assembly', columns: ['assembly_id'])]
#[ORM\Index(name: 'idx_quorum_checked_by', columns: ['checked_by_id'])]
#[ORM\Index(name: 'idx_quorum_assembly_checked', columns: ['assembly_id', 'checked_at'])]
class AssemblyQuorumCheck
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'assembly_id', nullable: false, onDelete: 'RESTRICT', foreignKeyName: 'fk_quorum_assembly')]
    private GeneralAssembly $assembly;

    #[ORM\Column(length: 32, enumType: AssemblyQuorumCheckKind::class)]
    private AssemblyQuorumCheckKind $kind;

    #[ORM\Column(type: 'datetime_immutable')]
    private DateTimeImmutable $checkedAt;

    #[ORM\Column(type: 'decimal', precision: 14, scale: 8)]
    private string $representedIdealPartsPercent;

    #[ORM\Column(type: 'decimal', precision: 14, scale: 8)]
    private string $requiredIdealPartsPercent;

    #[ORM\Column(length: 120)]
    private string $ruleCode;

    #[ORM\Column(length: 32, enumType: AssemblyLegalResult::class)]
    private AssemblyLegalResult $result;

    #[ORM\Column(type: 'text')]
    private string $explanation;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'checked_by_id', nullable: false, onDelete: 'RESTRICT', foreignKeyName: 'fk_quorum_checked_by')]
    private User $checkedBy;

    private function __construct(
        GeneralAssembly $assembly,
        AssemblyQuorumCheckKind $kind,
        DateTimeImmutable $checkedAt,
        AssemblyQuorumCalculation $calculation,
        User $checkedBy,
    ) {
        $this->assembly = $assembly;
        $this->kind = $kind;
        $this->checkedAt = $checkedAt->setTimezone(new DateTimeZone('UTC'));
        $this->representedIdealPartsPercent = $calculation->representedIdealPartsPercent;
        $this->requiredIdealPartsPercent = $calculation->requiredIdealPartsPercent;
        $this->ruleCode = $calculation->ruleCode;
        $this->result = $calculation->result;
        $this->explanation = $calculation->explanation;
        $this->checkedBy = $checkedBy;
    }

    public static function record(
        GeneralAssembly $assembly,
        AssemblyQuorumCheckKind $kind,
        DateTimeImmutable $checkedAt,
        AssemblyQuorumCalculation $calculation,
        User $checkedBy,
    ): self {
        return new self($assembly, $kind, $checkedAt, $calculation, $checkedBy);
    }

    public function getId(): ?int { return $this->id; }
    public function getAssembly(): GeneralAssembly { return $this->assembly; }
    public function getKind(): AssemblyQuorumCheckKind { return $this->kind; }
    public function getCheckedAt(): DateTimeImmutable { return $this->checkedAt; }
    public function getRepresentedIdealPartsPercent(): string { return $this->representedIdealPartsPercent; }
    public function getRequiredIdealPartsPercent(): string { return $this->requiredIdealPartsPercent; }
    public function getRuleCode(): string { return $this->ruleCode; }
    public function getResult(): AssemblyLegalResult { return $this->result; }
    public function getExplanation(): string { return $this->explanation; }
    public function getCheckedBy(): User { return $this->checkedBy; }
}
