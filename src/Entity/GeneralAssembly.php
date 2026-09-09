<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\AssemblyConveningBasis;
use App\Enum\AssemblyDecisionKind;
use App\Enum\GeneralAssemblyStatus;
use App\Value\AssemblyMajorityRuleSnapshot;
use App\Value\AssemblyQuorumRuleSnapshot;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use DomainException;
use InvalidArgumentException;

#[ORM\Entity]
#[ORM\Table(name: 'general_assembly')]
#[ORM\Index(name: 'idx_general_assembly_initiator_user', columns: ['initiator_user_id'])]
#[ORM\Index(name: 'idx_general_assembly_created_by', columns: ['created_by_id'])]
#[ORM\Index(name: 'idx_general_assembly_convened_by', columns: ['convened_by_id'])]
#[ORM\Index(name: 'idx_general_assembly_started_by', columns: ['started_by_id'])]
#[ORM\Index(name: 'idx_general_assembly_closed_by', columns: ['closed_by_id'])]
class GeneralAssembly
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 32, enumType: GeneralAssemblyStatus::class)]
    private GeneralAssemblyStatus $status;

    #[ORM\Column(length: 180)]
    private string $title;

    #[ORM\Column(type: 'datetime_immutable')]
    private DateTimeImmutable $scheduledAt;

    #[ORM\Column(length: 64)]
    private string $timezoneSnapshot;

    #[ORM\Column(type: 'date_immutable')]
    private DateTimeImmutable $referenceDate;

    #[ORM\Column(length: 255)]
    private string $place;

    #[ORM\Column(length: 1000, nullable: true)]
    private ?string $onlineMeetingReference;

    #[ORM\Column(length: 64, enumType: AssemblyConveningBasis::class)]
    private AssemblyConveningBasis $conveningBasis;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $conveningBasisNote;

    #[ORM\Column(length: 255)]
    private string $initiatorDisplayName;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'initiator_user_id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_general_assembly_initiator_user')]
    private ?User $initiatorUser;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'created_by_id', nullable: false, onDelete: 'RESTRICT', foreignKeyName: 'fk_general_assembly_created_by')]
    private User $createdBy;

    #[ORM\Column(type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'convened_by_id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_general_assembly_convened_by')]
    private ?User $convenedBy = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $convenedAt = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'started_by_id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_general_assembly_started_by')]
    private ?User $startedBy = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $startedAt = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'closed_by_id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_general_assembly_closed_by')]
    private ?User $closedBy = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $closedAt = null;

    #[ORM\Column(name: 'quorum_rule_code', length: 80, nullable: true)]
    private ?string $quorumRuleCode = null;

    #[ORM\Column(name: 'quorum_first_call_required_percent', type: 'decimal', precision: 14, scale: 8, nullable: true)]
    private ?string $quorumFirstCallRequiredPercent = null;

    #[ORM\Column(name: 'quorum_delayed_call_required_percent', type: 'decimal', precision: 14, scale: 8, nullable: true)]
    private ?string $quorumDelayedCallRequiredPercent = null;

    #[ORM\Column(name: 'quorum_dominant_owner_trigger_percent', type: 'decimal', precision: 14, scale: 8, nullable: true)]
    private ?string $quorumDominantOwnerTriggerPercent = null;

    #[ORM\Column(name: 'quorum_dominant_owner_required_percent', type: 'decimal', precision: 14, scale: 8, nullable: true)]
    private ?string $quorumDominantOwnerRequiredPercent = null;

    #[ORM\Column(name: 'quorum_legal_basis', type: 'text', nullable: true)]
    private ?string $quorumLegalBasis = null;

    #[ORM\Column(name: 'quorum_source_version', length: 120, nullable: true)]
    private ?string $quorumSourceVersion = null;

    #[ORM\Column(name: 'quorum_requires_legal_review', nullable: true)]
    private ?bool $quorumRequiresLegalReview = null;

    /** @var Collection<int, AssemblyAgendaItem> */
    #[ORM\OneToMany(mappedBy: 'assembly', targetEntity: AssemblyAgendaItem::class)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $agendaItems;

    private function __construct(
        string $title,
        DateTimeImmutable $scheduledAt,
        string $timezoneSnapshot,
        DateTimeImmutable $referenceDate,
        string $place,
        AssemblyConveningBasis $conveningBasis,
        string $initiatorDisplayName,
        ?User $initiatorUser,
        User $createdBy,
        DateTimeImmutable $createdAt,
        ?string $onlineMeetingReference,
        ?string $conveningBasisNote,
    ) {
        $createdAt = self::toUtc($createdAt);
        $scheduledAt = self::toUtc($scheduledAt);
        if ($scheduledAt < $createdAt) {
            throw new InvalidArgumentException('General Assembly cannot be scheduled before it is created.');
        }

        $this->status = GeneralAssemblyStatus::DRAFT;
        $this->agendaItems = new ArrayCollection();
        $this->applyDraftMetadata(
            $title,
            $scheduledAt,
            $timezoneSnapshot,
            $referenceDate,
            $place,
            $conveningBasis,
            $initiatorDisplayName,
            $initiatorUser,
            $onlineMeetingReference,
            $conveningBasisNote,
        );
        $this->createdBy = $createdBy;
        $this->createdAt = $createdAt;
    }

    public static function draft(
        string $title,
        DateTimeImmutable $scheduledAt,
        string $timezoneSnapshot,
        DateTimeImmutable $referenceDate,
        string $place,
        AssemblyConveningBasis $conveningBasis,
        string $initiatorDisplayName,
        ?User $initiatorUser,
        User $createdBy,
        DateTimeImmutable $createdAt,
        ?string $onlineMeetingReference = null,
        ?string $conveningBasisNote = null,
    ): self {
        return new self(
            $title,
            $scheduledAt,
            $timezoneSnapshot,
            $referenceDate,
            $place,
            $conveningBasis,
            $initiatorDisplayName,
            $initiatorUser,
            $createdBy,
            $createdAt,
            $onlineMeetingReference,
            $conveningBasisNote,
        );
    }

    public function reviseDraft(
        string $title,
        DateTimeImmutable $scheduledAt,
        string $timezoneSnapshot,
        DateTimeImmutable $referenceDate,
        string $place,
        AssemblyConveningBasis $conveningBasis,
        string $initiatorDisplayName,
        ?User $initiatorUser,
        ?string $onlineMeetingReference = null,
        ?string $conveningBasisNote = null,
    ): void {
        $this->assertStatus(GeneralAssemblyStatus::DRAFT, 'Only a draft General Assembly can be revised.');
        $scheduledAt = self::toUtc($scheduledAt);
        if ($scheduledAt < $this->createdAt) {
            throw new InvalidArgumentException('General Assembly cannot be scheduled before it is created.');
        }

        $this->applyDraftMetadata(
            $title,
            $scheduledAt,
            $timezoneSnapshot,
            $referenceDate,
            $place,
            $conveningBasis,
            $initiatorDisplayName,
            $initiatorUser,
            $onlineMeetingReference,
            $conveningBasisNote,
        );
    }

    public function addAgendaItem(
        int $position,
        string $title,
        ?string $description,
        string $draftResolutionText,
        AssemblyDecisionKind $kind,
        AssemblyMajorityRuleSnapshot $majorityRule,
    ): AssemblyAgendaItem {
        $this->assertStatus(GeneralAssemblyStatus::DRAFT, 'Ordinary agenda items may be added only while the meeting is a draft.');
        $this->assertAvailablePosition($position);

        $item = AssemblyAgendaItem::draft($this, $position, $title, $description, $draftResolutionText, $kind, $majorityRule);
        $this->agendaItems->add($item);

        return $item;
    }

    public function addEmergencyAgendaItem(
        int $position,
        string $title,
        ?string $description,
        string $draftResolutionText,
        AssemblyDecisionKind $kind,
        AssemblyMajorityRuleSnapshot $majorityRule,
        string $emergencyReason,
    ): AssemblyAgendaItem {
        $this->assertStatus(GeneralAssemblyStatus::IN_PROGRESS, 'Emergency agenda items may be added only while the meeting is in progress.');
        $this->assertAvailablePosition($position);

        $item = AssemblyAgendaItem::emergency($this, $position, $title, $description, $draftResolutionText, $kind, $majorityRule, $emergencyReason);
        $this->agendaItems->add($item);

        return $item;
    }

    public function snapshotQuorumRule(AssemblyQuorumRuleSnapshot $rule): void
    {
        $this->assertStatus(GeneralAssemblyStatus::DRAFT, 'Quorum rule may be snapshotted only while the meeting is a draft.');
        if (null !== $this->quorumRuleCode) {
            throw new DomainException('General Assembly quorum rule snapshot is immutable.');
        }

        $this->quorumRuleCode = $rule->code;
        $this->quorumFirstCallRequiredPercent = $rule->firstCallRequiredPercent;
        $this->quorumDelayedCallRequiredPercent = $rule->delayedCallRequiredPercent;
        $this->quorumDominantOwnerTriggerPercent = $rule->dominantOwnerTriggerPercent;
        $this->quorumDominantOwnerRequiredPercent = $rule->dominantOwnerRequiredPercent;
        $this->quorumLegalBasis = $rule->legalBasis;
        $this->quorumSourceVersion = $rule->sourceVersion;
        $this->quorumRequiresLegalReview = $rule->requiresLegalReview;
    }

    public function getQuorumRuleSnapshot(): ?AssemblyQuorumRuleSnapshot
    {
        if (null === $this->quorumRuleCode) {
            return null;
        }

        if (
            null === $this->quorumFirstCallRequiredPercent
            || null === $this->quorumDelayedCallRequiredPercent
            || null === $this->quorumDominantOwnerTriggerPercent
            || null === $this->quorumDominantOwnerRequiredPercent
            || null === $this->quorumLegalBasis
            || null === $this->quorumSourceVersion
            || null === $this->quorumRequiresLegalReview
        ) {
            throw new DomainException('Stored General Assembly quorum rule snapshot is incomplete.');
        }

        return new AssemblyQuorumRuleSnapshot(
            $this->quorumRuleCode,
            $this->quorumFirstCallRequiredPercent,
            $this->quorumDelayedCallRequiredPercent,
            $this->quorumDominantOwnerTriggerPercent,
            $this->quorumDominantOwnerRequiredPercent,
            $this->quorumLegalBasis,
            $this->quorumSourceVersion,
            $this->quorumRequiresLegalReview,
        );
    }

    public function convene(User $actor, DateTimeImmutable $convenedAt): void
    {
        $this->assertStatus(GeneralAssemblyStatus::DRAFT, 'Only a draft General Assembly can be convened.');
        $convenedAt = self::toUtc($convenedAt);
        if ($convenedAt < $this->createdAt || $convenedAt > $this->scheduledAt) {
            throw new InvalidArgumentException('Convening timestamp must be between creation and scheduled meeting time.');
        }

        $this->status = GeneralAssemblyStatus::CONVENED;
        $this->convenedBy = $actor;
        $this->convenedAt = $convenedAt;
    }

    public function start(User $actor, DateTimeImmutable $startedAt): void
    {
        $this->assertStatus(GeneralAssemblyStatus::CONVENED, 'Only a convened General Assembly can start.');
        $startedAt = self::toUtc($startedAt);
        if ($startedAt < $this->scheduledAt || (null !== $this->convenedAt && $startedAt < $this->convenedAt)) {
            throw new InvalidArgumentException('Meeting cannot start before its scheduled or convened time.');
        }

        $this->status = GeneralAssemblyStatus::IN_PROGRESS;
        $this->startedBy = $actor;
        $this->startedAt = $startedAt;
    }

    public function close(User $actor, DateTimeImmutable $closedAt): void
    {
        $this->assertStatus(GeneralAssemblyStatus::IN_PROGRESS, 'Only a meeting in progress can be closed.');
        $closedAt = self::toUtc($closedAt);
        if (null === $this->startedAt || $closedAt < $this->startedAt) {
            throw new InvalidArgumentException('Meeting cannot close before it starts.');
        }

        $this->status = GeneralAssemblyStatus::CLOSED;
        $this->closedBy = $actor;
        $this->closedAt = $closedAt;
    }

    public function getId(): ?int { return $this->id; }
    public function getStatus(): GeneralAssemblyStatus { return $this->status; }
    public function getTitle(): string { return $this->title; }
    public function getScheduledAt(): DateTimeImmutable { return $this->scheduledAt; }
    public function getTimezoneSnapshot(): string { return $this->timezoneSnapshot; }
    public function getReferenceDate(): DateTimeImmutable { return $this->referenceDate; }
    public function getPlace(): string { return $this->place; }
    public function getOnlineMeetingReference(): ?string { return $this->onlineMeetingReference; }
    public function getConveningBasis(): AssemblyConveningBasis { return $this->conveningBasis; }
    public function getConveningBasisNote(): ?string { return $this->conveningBasisNote; }
    public function getInitiatorDisplayName(): string { return $this->initiatorDisplayName; }
    public function getInitiatorUser(): ?User { return $this->initiatorUser; }
    public function getCreatedBy(): User { return $this->createdBy; }
    public function getCreatedAt(): DateTimeImmutable { return $this->createdAt; }
    public function getConvenedBy(): ?User { return $this->convenedBy; }
    public function getConvenedAt(): ?DateTimeImmutable { return $this->convenedAt; }
    public function getStartedBy(): ?User { return $this->startedBy; }
    public function getStartedAt(): ?DateTimeImmutable { return $this->startedAt; }
    public function getClosedBy(): ?User { return $this->closedBy; }
    public function getClosedAt(): ?DateTimeImmutable { return $this->closedAt; }

    /** @return Collection<int, AssemblyAgendaItem> */
    public function getAgendaItems(): Collection { return $this->agendaItems; }

    public function isResidentVisible(): bool
    {
        return GeneralAssemblyStatus::DRAFT !== $this->status;
    }

    private function applyDraftMetadata(
        string $title,
        DateTimeImmutable $scheduledAt,
        string $timezoneSnapshot,
        DateTimeImmutable $referenceDate,
        string $place,
        AssemblyConveningBasis $conveningBasis,
        string $initiatorDisplayName,
        ?User $initiatorUser,
        ?string $onlineMeetingReference,
        ?string $conveningBasisNote,
    ): void {
        $title = trim($title);
        $timezoneSnapshot = trim($timezoneSnapshot);
        $place = trim($place);
        $initiatorDisplayName = trim($initiatorDisplayName);
        $onlineMeetingReference = self::nullableTrim($onlineMeetingReference);
        $conveningBasisNote = self::nullableTrim($conveningBasisNote);

        if ('' === $title || mb_strlen($title) > 180) {
            throw new InvalidArgumentException('General Assembly title must contain between 1 and 180 characters.');
        }
        if ('' === $timezoneSnapshot || mb_strlen($timezoneSnapshot) > 64) {
            throw new InvalidArgumentException('General Assembly timezone is required.');
        }
        try {
            new DateTimeZone($timezoneSnapshot);
        } catch (\Exception) {
            throw new InvalidArgumentException('General Assembly timezone is invalid.');
        }
        if ('' === $place || mb_strlen($place) > 255) {
            throw new InvalidArgumentException('General Assembly place must contain between 1 and 255 characters.');
        }
        if ('' === $initiatorDisplayName || mb_strlen($initiatorDisplayName) > 255) {
            throw new InvalidArgumentException('General Assembly initiator name must contain between 1 and 255 characters.');
        }
        if (AssemblyConveningBasis::OTHER_LEGAL_BASIS === $conveningBasis && null === $conveningBasisNote) {
            throw new InvalidArgumentException('Other legal convening basis requires an explanation.');
        }
        if (null !== $onlineMeetingReference && mb_strlen($onlineMeetingReference) > 1000) {
            throw new InvalidArgumentException('Online meeting reference cannot exceed 1000 characters.');
        }

        $this->title = $title;
        $this->scheduledAt = $scheduledAt;
        $this->timezoneSnapshot = $timezoneSnapshot;
        $this->referenceDate = $referenceDate;
        $this->place = $place;
        $this->conveningBasis = $conveningBasis;
        $this->initiatorDisplayName = $initiatorDisplayName;
        $this->initiatorUser = $initiatorUser;
        $this->onlineMeetingReference = $onlineMeetingReference;
        $this->conveningBasisNote = $conveningBasisNote;
    }

    private function assertAvailablePosition(int $position): void
    {
        if ($position < 1) {
            throw new InvalidArgumentException('Agenda position must be a positive integer.');
        }

        foreach ($this->agendaItems as $item) {
            if ($item->getPosition() === $position) {
                throw new InvalidArgumentException('Agenda position must be unique within the meeting.');
            }
        }
    }

    private function assertStatus(GeneralAssemblyStatus $expected, string $message): void
    {
        if ($expected !== $this->status) {
            throw new DomainException($message);
        }
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
