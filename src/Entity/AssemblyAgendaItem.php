<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\AgendaItemStatus;
use App\Enum\AssemblyDecisionKind;
use App\Enum\AssemblyVoteDenominator;
use App\Enum\GeneralAssemblyStatus;
use App\Enum\MajorityComparison;
use App\Value\AssemblyMajorityRuleSnapshot;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\Mapping as ORM;
use DomainException;
use InvalidArgumentException;

#[ORM\Entity]
#[ORM\Table(name: 'assembly_agenda_item')]
#[ORM\Index(name: 'idx_assembly_agenda_item_assembly', columns: ['assembly_id'])]
class AssemblyAgendaItem
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'agendaItems')]
    #[ORM\JoinColumn(name: 'assembly_id', nullable: false, onDelete: 'RESTRICT', foreignKeyName: 'fk_assembly_agenda_item_assembly')]
    private GeneralAssembly $assembly;

    #[ORM\Column]
    private int $position;

    #[ORM\Column(length: 180)]
    private string $title;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description;

    #[ORM\Column(type: 'text')]
    private string $draftResolutionText;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $finalResolutionText = null;

    #[ORM\Column(length: 64, enumType: AssemblyDecisionKind::class)]
    private AssemblyDecisionKind $kind;

    #[ORM\Column(name: 'majority_rule_code', length: 80)]
    private string $majorityRuleCode;

    #[ORM\Column(name: 'majority_denominator', length: 48, enumType: AssemblyVoteDenominator::class)]
    private AssemblyVoteDenominator $majorityDenominator;

    #[ORM\Column(name: 'majority_threshold_percent', type: 'decimal', precision: 14, scale: 8)]
    private string $majorityThresholdPercent;

    #[ORM\Column(name: 'majority_comparison', length: 24, enumType: MajorityComparison::class)]
    private MajorityComparison $majorityComparison;

    #[ORM\Column(name: 'majority_legal_basis', type: 'text')]
    private string $majorityLegalBasis;

    #[ORM\Column(name: 'majority_source_version', length: 120)]
    private string $majoritySourceVersion;

    #[ORM\Column(name: 'majority_requires_legal_review', options: ['default' => false])]
    private bool $majorityRequiresLegalReview;

    #[ORM\Column(options: ['default' => false])]
    private bool $isEmergency;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $emergencyReason;

    #[ORM\Column(length: 32, enumType: AgendaItemStatus::class)]
    private AgendaItemStatus $status;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $openedAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $closedAt = null;

    private function __construct(
        GeneralAssembly $assembly,
        int $position,
        string $title,
        ?string $description,
        string $draftResolutionText,
        AssemblyDecisionKind $kind,
        AssemblyMajorityRuleSnapshot $majorityRule,
        bool $isEmergency,
        ?string $emergencyReason,
    ) {
        if ($position < 1) {
            throw new InvalidArgumentException('Agenda position must be a positive integer.');
        }

        [$title, $description, $draftResolutionText] = self::normalizeContent($title, $description, $draftResolutionText);
        $emergencyReason = self::nullableTrim($emergencyReason);
        if ($isEmergency && null === $emergencyReason) {
            throw new InvalidArgumentException('Emergency agenda item requires a reason.');
        }
        if (!$isEmergency && null !== $emergencyReason) {
            throw new InvalidArgumentException('Ordinary agenda item cannot have an emergency reason.');
        }

        $this->assembly = $assembly;
        $this->position = $position;
        $this->title = $title;
        $this->description = $description;
        $this->draftResolutionText = $draftResolutionText;
        $this->kind = $kind;
        $this->applyMajorityRule($majorityRule);
        $this->isEmergency = $isEmergency;
        $this->emergencyReason = $emergencyReason;
        $this->status = AgendaItemStatus::PLANNED;
    }

    public static function draft(
        GeneralAssembly $assembly,
        int $position,
        string $title,
        ?string $description,
        string $draftResolutionText,
        AssemblyDecisionKind $kind,
        AssemblyMajorityRuleSnapshot $majorityRule,
    ): self {
        if (GeneralAssemblyStatus::DRAFT !== $assembly->getStatus()) {
            throw new DomainException('Ordinary agenda items may be created only for a draft meeting.');
        }

        return new self($assembly, $position, $title, $description, $draftResolutionText, $kind, $majorityRule, false, null);
    }

    public static function emergency(
        GeneralAssembly $assembly,
        int $position,
        string $title,
        ?string $description,
        string $draftResolutionText,
        AssemblyDecisionKind $kind,
        AssemblyMajorityRuleSnapshot $majorityRule,
        string $emergencyReason,
    ): self {
        if (GeneralAssemblyStatus::IN_PROGRESS !== $assembly->getStatus()) {
            throw new DomainException('Emergency agenda items may be created only while the meeting is in progress.');
        }

        return new self($assembly, $position, $title, $description, $draftResolutionText, $kind, $majorityRule, true, $emergencyReason);
    }

    public function reviseDraft(
        string $title,
        ?string $description,
        string $draftResolutionText,
        AssemblyDecisionKind $kind,
        AssemblyMajorityRuleSnapshot $majorityRule,
    ): void {
        if (GeneralAssemblyStatus::DRAFT !== $this->assembly->getStatus() || AgendaItemStatus::PLANNED !== $this->status || $this->isEmergency) {
            throw new DomainException('Frozen agenda item cannot be revised.');
        }

        [$title, $description, $draftResolutionText] = self::normalizeContent($title, $description, $draftResolutionText);
        $this->title = $title;
        $this->description = $description;
        $this->draftResolutionText = $draftResolutionText;
        $this->kind = $kind;
        $this->applyMajorityRule($majorityRule);
    }

    public function open(string $finalResolutionText, DateTimeImmutable $openedAt): void
    {
        if (GeneralAssemblyStatus::IN_PROGRESS !== $this->assembly->getStatus() || AgendaItemStatus::PLANNED !== $this->status) {
            throw new DomainException('Only a planned item in an active meeting can be opened.');
        }

        $finalResolutionText = trim($finalResolutionText);
        if ('' === $finalResolutionText) {
            throw new InvalidArgumentException('Final resolution text cannot be blank.');
        }

        $this->finalResolutionText = $finalResolutionText;
        $this->openedAt = self::toUtc($openedAt);
        $this->status = AgendaItemStatus::OPEN;
    }

    public function deferToAbsenteeWindow(DateTimeImmutable $closedAt): void
    {
        if (AgendaItemStatus::OPEN !== $this->status) {
            throw new DomainException('Only an open agenda item can enter absentee voting.');
        }
        $closedAt = self::toUtc($closedAt);
        if (null !== $this->openedAt && $closedAt < $this->openedAt) {
            throw new InvalidArgumentException('Agenda item cannot close before it opens.');
        }

        $this->closedAt = $closedAt;
        $this->status = AgendaItemStatus::ABSENTEE_WINDOW;
    }

    public function resolve(DateTimeImmutable $closedAt): void
    {
        if (!in_array($this->status, [AgendaItemStatus::OPEN, AgendaItemStatus::ABSENTEE_WINDOW], true)) {
            throw new DomainException('Only an open or absentee-window agenda item can be resolved.');
        }
        $closedAt = self::toUtc($closedAt);
        if (null !== $this->openedAt && $closedAt < $this->openedAt) {
            throw new InvalidArgumentException('Agenda item cannot resolve before it opens.');
        }

        $this->closedAt = $closedAt;
        $this->status = AgendaItemStatus::RESOLVED;
    }

    public function getId(): ?int { return $this->id; }
    public function getAssembly(): GeneralAssembly { return $this->assembly; }
    public function getPosition(): int { return $this->position; }
    public function getTitle(): string { return $this->title; }
    public function getDescription(): ?string { return $this->description; }
    public function getDraftResolutionText(): string { return $this->draftResolutionText; }
    public function getFinalResolutionText(): ?string { return $this->finalResolutionText; }
    public function getKind(): AssemblyDecisionKind { return $this->kind; }
    public function getMajorityRule(): AssemblyMajorityRuleSnapshot
    {
        return new AssemblyMajorityRuleSnapshot(
            $this->majorityRuleCode,
            $this->majorityDenominator,
            $this->majorityThresholdPercent,
            $this->majorityComparison,
            $this->majorityLegalBasis,
            $this->majoritySourceVersion,
            $this->majorityRequiresLegalReview,
        );
    }
    public function isEmergency(): bool { return $this->isEmergency; }
    public function getEmergencyReason(): ?string { return $this->emergencyReason; }
    public function getStatus(): AgendaItemStatus { return $this->status; }
    public function getOpenedAt(): ?DateTimeImmutable { return $this->openedAt; }
    public function getClosedAt(): ?DateTimeImmutable { return $this->closedAt; }

    private function applyMajorityRule(AssemblyMajorityRuleSnapshot $rule): void
    {
        $this->majorityRuleCode = $rule->getRuleCode();
        $this->majorityDenominator = $rule->getDenominator();
        $this->majorityThresholdPercent = $rule->getThresholdPercent();
        $this->majorityComparison = $rule->getComparison();
        $this->majorityLegalBasis = $rule->getLegalBasis();
        $this->majoritySourceVersion = $rule->getSourceVersion();
        $this->majorityRequiresLegalReview = $rule->requiresLegalReview();
    }

    /** @return array{string, ?string, string} */
    private static function normalizeContent(string $title, ?string $description, string $resolution): array
    {
        $title = trim($title);
        $description = self::nullableTrim($description);
        $resolution = trim($resolution);

        if ('' === $title || mb_strlen($title) > 180) {
            throw new InvalidArgumentException('Agenda title must contain between 1 and 180 characters.');
        }
        if ('' === $resolution) {
            throw new InvalidArgumentException('Draft resolution text cannot be blank.');
        }

        return [$title, $description, $resolution];
    }

    private static function nullableTrim(?string $value): ?string
    {
        if (null === $value) {
            return null;
        }
        $value = trim($value);

        return '' === $value ? null : $value;
    }

    private static function toUtc(DateTimeImmutable $dateTime): DateTimeImmutable
    {
        return $dateTime->setTimezone(new DateTimeZone('UTC'));
    }
}
