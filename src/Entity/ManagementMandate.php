<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\ManagementMandateKind;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\Mapping as ORM;
use InvalidArgumentException;

#[ORM\Entity]
#[ORM\Table(name: 'management_mandate')]
#[ORM\Index(name: 'idx_management_mandate_end_start', columns: ['ends_at', 'starts_at'])]
#[ORM\Index(name: 'idx_management_mandate_recorded_by', columns: ['recorded_by_id'])]
#[ORM\Index(name: 'idx_management_mandate_source_assembly', columns: ['source_assembly_id'])]
final class ManagementMandate
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 32, enumType: ManagementMandateKind::class)]
    private ManagementMandateKind $kind;

    #[ORM\Column(name: 'holder_label', length: 180)]
    private string $holderLabel;

    #[ORM\Column(name: 'starts_at', type: 'date_immutable')]
    private DateTimeImmutable $startsAt;

    #[ORM\Column(name: 'ends_at', type: 'date_immutable')]
    private DateTimeImmutable $endsAt;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'recorded_by_id', nullable: false, onDelete: 'RESTRICT', foreignKeyName: 'fk_management_mandate_recorded_by')]
    private User $recordedBy;

    #[ORM\Column(name: 'recorded_at', type: 'datetime_immutable')]
    private DateTimeImmutable $recordedAt;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'source_assembly_id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_management_mandate_source_assembly')]
    private ?GeneralAssembly $sourceAssembly;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $note;

    private function __construct(
        ManagementMandateKind $kind,
        string $holderLabel,
        DateTimeImmutable $startsAt,
        DateTimeImmutable $endsAt,
        User $recordedBy,
        DateTimeImmutable $recordedAt,
        ?GeneralAssembly $sourceAssembly,
        ?string $note,
    ) {
        $holderLabel = trim($holderLabel);
        if ('' === $holderLabel || mb_strlen($holderLabel) > 180) {
            throw new InvalidArgumentException('Management mandate holder must contain between 1 and 180 characters.');
        }
        if ($endsAt < $startsAt) {
            throw new InvalidArgumentException('Management mandate end date cannot be before its start date.');
        }

        $this->kind = $kind;
        $this->holderLabel = $holderLabel;
        $this->startsAt = $startsAt;
        $this->endsAt = $endsAt;
        $this->recordedBy = $recordedBy;
        $this->recordedAt = $recordedAt->setTimezone(new DateTimeZone('UTC'));
        $this->sourceAssembly = $sourceAssembly;
        $this->note = self::optional($note);
    }

    public static function record(
        ManagementMandateKind $kind,
        string $holderLabel,
        DateTimeImmutable $startsAt,
        DateTimeImmutable $endsAt,
        User $recordedBy,
        DateTimeImmutable $recordedAt,
        ?GeneralAssembly $sourceAssembly = null,
        ?string $note = null,
    ): self {
        return new self($kind, $holderLabel, $startsAt, $endsAt, $recordedBy, $recordedAt, $sourceAssembly, $note);
    }

    public function getId(): ?int { return $this->id; }
    public function getKind(): ManagementMandateKind { return $this->kind; }
    public function getHolderLabel(): string { return $this->holderLabel; }
    public function getStartsAt(): DateTimeImmutable { return $this->startsAt; }
    public function getEndsAt(): DateTimeImmutable { return $this->endsAt; }
    public function getRecordedBy(): User { return $this->recordedBy; }
    public function getRecordedAt(): DateTimeImmutable { return $this->recordedAt; }
    public function getSourceAssembly(): ?GeneralAssembly { return $this->sourceAssembly; }
    public function getNote(): ?string { return $this->note; }

    private static function optional(?string $value): ?string
    {
        if (null === $value) {
            return null;
        }

        $value = trim($value);

        return '' === $value ? null : $value;
    }
}
