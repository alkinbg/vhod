<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\AssemblyAbsenteeSignatureMode;
use App\Enum\DocumentAccessLevel;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\Mapping as ORM;
use DomainException;
use InvalidArgumentException;

#[ORM\Entity]
#[ORM\Table(name: 'assembly_absentee_declaration')]
#[ORM\UniqueConstraint(name: 'uniq_absentee_declaration_window_entry', columns: ['window_id', 'electorate_entry_id'])]
#[ORM\Index(name: 'idx_absentee_declaration_assembly', columns: ['assembly_id'])]
#[ORM\Index(name: 'idx_absentee_declaration_evidence', columns: ['evidence_document_id'])]
#[ORM\Index(name: 'idx_absentee_declaration_registered_by', columns: ['registered_by_id'])]
#[ORM\Index(name: 'idx_absentee_declaration_submitted', columns: ['submitted_at'])]
final class AssemblyAbsenteeDeclaration
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'assembly_id', nullable: false, onDelete: 'RESTRICT', foreignKeyName: 'fk_absentee_declaration_assembly')]
    private GeneralAssembly $assembly;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'window_id', nullable: false, onDelete: 'RESTRICT', foreignKeyName: 'fk_absentee_declaration_window')]
    private AssemblyAbsenteeWindow $window;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'electorate_entry_id', nullable: false, onDelete: 'RESTRICT', foreignKeyName: 'fk_absentee_declaration_entry')]
    private AssemblyElectorateEntry $electorateEntry;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'evidence_document_id', nullable: false, onDelete: 'RESTRICT', foreignKeyName: 'fk_absentee_declaration_evidence')]
    private Document $evidenceDocument;

    #[ORM\Column(length: 48, enumType: AssemblyAbsenteeSignatureMode::class)]
    private AssemblyAbsenteeSignatureMode $signatureModeSnapshot;

    #[ORM\Column(type: 'datetime_immutable')]
    private DateTimeImmutable $submittedAt;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'registered_by_id', nullable: false, onDelete: 'RESTRICT', foreignKeyName: 'fk_absentee_declaration_registered_by')]
    private User $registeredBy;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $notes;

    private function __construct(
        AssemblyAbsenteeWindow $window,
        AssemblyElectorateEntry $entry,
        Document $evidenceDocument,
        AssemblyAbsenteeSignatureMode $signatureMode,
        User $registeredBy,
        DateTimeImmutable $submittedAt,
        ?string $notes,
    ) {
        if ($entry->getAssembly() !== $window->getAssembly()) {
            throw new DomainException('Absentee declaration principal does not belong to this General Assembly.');
        }
        if (DocumentAccessLevel::GOVERNANCE !== $evidenceDocument->getAccessLevel()) {
            throw new DomainException('Absentee declaration evidence must use GOVERNANCE access.');
        }

        $submittedAt = self::toUtc($submittedAt);
        if (!$window->acceptsSubmissionAt($submittedAt)) {
            throw new DomainException('Absentee declaration is outside the effective voting window.');
        }

        $notes = self::nullableTrim($notes);
        if (null !== $notes && mb_strlen($notes) > 4000) {
            throw new InvalidArgumentException('Absentee declaration notes cannot exceed 4000 characters.');
        }

        $this->assembly = $window->getAssembly();
        $this->window = $window;
        $this->electorateEntry = $entry;
        $this->evidenceDocument = $evidenceDocument;
        $this->signatureModeSnapshot = $signatureMode;
        $this->submittedAt = $submittedAt;
        $this->registeredBy = $registeredBy;
        $this->notes = $notes;
    }

    public static function record(
        AssemblyAbsenteeWindow $window,
        AssemblyElectorateEntry $entry,
        Document $evidenceDocument,
        AssemblyAbsenteeSignatureMode $signatureMode,
        User $registeredBy,
        DateTimeImmutable $submittedAt,
        ?string $notes = null,
    ): self {
        return new self($window, $entry, $evidenceDocument, $signatureMode, $registeredBy, $submittedAt, $notes);
    }

    public function getId(): ?int { return $this->id; }
    public function getAssembly(): GeneralAssembly { return $this->assembly; }
    public function getWindow(): AssemblyAbsenteeWindow { return $this->window; }
    public function getElectorateEntry(): AssemblyElectorateEntry { return $this->electorateEntry; }
    public function getEvidenceDocument(): Document { return $this->evidenceDocument; }
    public function getSignatureModeSnapshot(): AssemblyAbsenteeSignatureMode { return $this->signatureModeSnapshot; }
    public function getSubmittedAt(): DateTimeImmutable { return $this->submittedAt; }
    public function getRegisteredBy(): User { return $this->registeredBy; }
    public function getNotes(): ?string { return $this->notes; }

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
