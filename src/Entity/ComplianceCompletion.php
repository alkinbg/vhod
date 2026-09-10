<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\ComplianceCompletionType;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\Mapping as ORM;
use InvalidArgumentException;

#[ORM\Entity]
#[ORM\Table(name: 'compliance_completion')]
#[ORM\UniqueConstraint(name: 'uniq_compliance_completion_type_period', columns: ['type', 'period_key'])]
#[ORM\Index(name: 'idx_compliance_completion_completed_at', columns: ['completed_at'])]
#[ORM\Index(name: 'idx_compliance_completion_recorded_by', columns: ['recorded_by_id'])]
#[ORM\Index(name: 'idx_compliance_completion_evidence_document', columns: ['evidence_document_id'])]
final class ComplianceCompletion
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 32, enumType: ComplianceCompletionType::class)]
    private ComplianceCompletionType $type;

    #[ORM\Column(name: 'period_key', length: 7)]
    private string $periodKey;

    #[ORM\Column(name: 'completed_at', type: 'date_immutable')]
    private DateTimeImmutable $completedAt;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'recorded_by_id', nullable: false, onDelete: 'RESTRICT', foreignKeyName: 'fk_compliance_completion_recorded_by')]
    private User $recordedBy;

    #[ORM\Column(name: 'recorded_at', type: 'datetime_immutable')]
    private DateTimeImmutable $recordedAt;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'evidence_document_id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_compliance_completion_evidence_document')]
    private ?Document $evidenceDocument;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $note;

    private function __construct(
        ComplianceCompletionType $type,
        string $periodKey,
        DateTimeImmutable $completedAt,
        User $recordedBy,
        DateTimeImmutable $recordedAt,
        ?Document $evidenceDocument,
        ?string $note,
    ) {
        self::assertPeriodKey($type, $periodKey);

        $this->type = $type;
        $this->periodKey = $periodKey;
        $this->completedAt = $completedAt;
        $this->recordedBy = $recordedBy;
        $this->recordedAt = $recordedAt->setTimezone(new DateTimeZone('UTC'));
        $this->evidenceDocument = $evidenceDocument;
        $this->note = self::optional($note);
    }

    public static function record(
        ComplianceCompletionType $type,
        string $periodKey,
        DateTimeImmutable $completedAt,
        User $recordedBy,
        DateTimeImmutable $recordedAt,
        ?Document $evidenceDocument = null,
        ?string $note = null,
    ): self {
        return new self($type, trim($periodKey), $completedAt, $recordedBy, $recordedAt, $evidenceDocument, $note);
    }

    public function getId(): ?int { return $this->id; }
    public function getType(): ComplianceCompletionType { return $this->type; }
    public function getPeriodKey(): string { return $this->periodKey; }
    public function getCompletedAt(): DateTimeImmutable { return $this->completedAt; }
    public function getRecordedBy(): User { return $this->recordedBy; }
    public function getRecordedAt(): DateTimeImmutable { return $this->recordedAt; }
    public function getEvidenceDocument(): ?Document { return $this->evidenceDocument; }
    public function getNote(): ?string { return $this->note; }

    private static function assertPeriodKey(ComplianceCompletionType $type, string $periodKey): void
    {
        if (ComplianceCompletionType::MONTHLY_REPORT === $type) {
            if (1 !== preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $periodKey)) {
                throw new InvalidArgumentException('Monthly report period key must use YYYY-MM.');
            }

            return;
        }

        if (1 !== preg_match('/^\d{4}$/', $periodKey)) {
            throw new InvalidArgumentException('Annual cash audit period key must use YYYY.');
        }
    }

    private static function optional(?string $value): ?string
    {
        if (null === $value) {
            return null;
        }

        $value = trim($value);

        return '' === $value ? null : $value;
    }
}
