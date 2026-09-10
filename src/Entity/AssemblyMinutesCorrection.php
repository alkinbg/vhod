<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\DocumentAccessLevel;
use App\Enum\DocumentCategory;
use App\Enum\GeneralAssemblyStatus;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\Mapping as ORM;
use DomainException;
use InvalidArgumentException;

#[ORM\Entity]
#[ORM\Table(name: 'assembly_minutes_correction')]
#[ORM\Index(name: 'idx_minutes_correction_assembly', columns: ['assembly_id'])]
#[ORM\Index(name: 'idx_minutes_correction_document', columns: ['document_id'])]
#[ORM\Index(name: 'idx_minutes_correction_recorded_by', columns: ['recorded_by_id'])]
#[ORM\Index(name: 'idx_minutes_correction_recorded_at', columns: ['recorded_at'])]
final class AssemblyMinutesCorrection
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'assembly_id', nullable: false, onDelete: 'RESTRICT', foreignKeyName: 'fk_minutes_correction_assembly')]
    private GeneralAssembly $assembly;

    #[ORM\Column(type: 'text')]
    private string $reason;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'document_id', nullable: false, onDelete: 'RESTRICT', foreignKeyName: 'fk_minutes_correction_document')]
    private Document $document;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'recorded_by_id', nullable: false, onDelete: 'RESTRICT', foreignKeyName: 'fk_minutes_correction_recorded_by')]
    private User $recordedBy;

    #[ORM\Column(type: 'datetime_immutable')]
    private DateTimeImmutable $recordedAt;

    private function __construct(
        GeneralAssembly $assembly,
        string $reason,
        Document $document,
        User $recordedBy,
        DateTimeImmutable $recordedAt,
    ) {
        if (GeneralAssemblyStatus::MINUTES_FINALIZED !== $assembly->getStatus()) {
            throw new DomainException('Minutes corrections may be appended only after finalization.');
        }

        $reason = trim($reason);
        if ('' === $reason || mb_strlen($reason) > 4000) {
            throw new InvalidArgumentException('Minutes correction reason must contain between 1 and 4000 characters.');
        }
        if (DocumentCategory::MEETING_MINUTES !== $document->getCategory() || DocumentAccessLevel::RESIDENTS !== $document->getAccessLevel()) {
            throw new InvalidArgumentException('Minutes correction document must be a resident-visible MEETING_MINUTES document.');
        }

        $recordedAt = $recordedAt->setTimezone(new DateTimeZone('UTC'));
        $finalizedAt = $assembly->getMinutesFinalizedAt();
        if (null === $finalizedAt || $recordedAt < $finalizedAt) {
            throw new InvalidArgumentException('Minutes correction cannot predate finalization.');
        }

        $this->assembly = $assembly;
        $this->reason = $reason;
        $this->document = $document;
        $this->recordedBy = $recordedBy;
        $this->recordedAt = $recordedAt;
    }

    public static function record(
        GeneralAssembly $assembly,
        string $reason,
        Document $document,
        User $recordedBy,
        DateTimeImmutable $recordedAt,
    ): self {
        return new self($assembly, $reason, $document, $recordedBy, $recordedAt);
    }

    public function getId(): ?int { return $this->id; }
    public function getAssembly(): GeneralAssembly { return $this->assembly; }
    public function getReason(): string { return $this->reason; }
    public function getDocument(): Document { return $this->document; }
    public function getRecordedBy(): User { return $this->recordedBy; }
    public function getRecordedAt(): DateTimeImmutable { return $this->recordedAt; }
}
