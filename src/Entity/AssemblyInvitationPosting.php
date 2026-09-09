<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\DocumentAccessLevel;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\Mapping as ORM;
use InvalidArgumentException;

#[ORM\Entity]
#[ORM\Table(name: 'assembly_invitation_posting')]
#[ORM\Index(name: 'idx_invitation_posting_assembly', columns: ['assembly_id'])]
#[ORM\Index(name: 'idx_invitation_posting_confirmed_by', columns: ['confirmed_by_id'])]
#[ORM\Index(name: 'idx_invitation_posting_evidence_document', columns: ['evidence_document_id'])]
final class AssemblyInvitationPosting
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'assembly_id', nullable: false, onDelete: 'RESTRICT', foreignKeyName: 'fk_invitation_posting_assembly')]
    private GeneralAssembly $assembly;

    #[ORM\Column(type: 'datetime_immutable')]
    private DateTimeImmutable $postedAt;

    #[ORM\Column(length: 255)]
    private string $postingPlace;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'confirmed_by_id', nullable: false, onDelete: 'RESTRICT', foreignKeyName: 'fk_invitation_posting_confirmed_by')]
    private User $confirmedBy;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'evidence_document_id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_invitation_posting_evidence_document')]
    private ?Document $evidenceDocument;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $notes;

    private function __construct(
        GeneralAssembly $assembly,
        DateTimeImmutable $postedAt,
        string $postingPlace,
        User $confirmedBy,
        ?Document $evidenceDocument,
        ?string $notes,
    ) {
        $postingPlace = trim($postingPlace);
        if ('' === $postingPlace || mb_strlen($postingPlace) > 255) {
            throw new InvalidArgumentException('Invitation posting place must contain between 1 and 255 characters.');
        }
        if (null !== $evidenceDocument && DocumentAccessLevel::GOVERNANCE !== $evidenceDocument->getAccessLevel()) {
            throw new InvalidArgumentException('Invitation posting evidence must use GOVERNANCE access.');
        }

        $notes = self::nullableTrim($notes);

        $this->assembly = $assembly;
        $this->postedAt = $postedAt->setTimezone(new DateTimeZone('UTC'));
        $this->postingPlace = $postingPlace;
        $this->confirmedBy = $confirmedBy;
        $this->evidenceDocument = $evidenceDocument;
        $this->notes = $notes;
    }

    public static function record(
        GeneralAssembly $assembly,
        DateTimeImmutable $postedAt,
        string $postingPlace,
        User $confirmedBy,
        ?Document $evidenceDocument,
        ?string $notes,
    ): self {
        return new self($assembly, $postedAt, $postingPlace, $confirmedBy, $evidenceDocument, $notes);
    }

    public function getId(): ?int { return $this->id; }
    public function getAssembly(): GeneralAssembly { return $this->assembly; }
    public function getPostedAt(): DateTimeImmutable { return $this->postedAt; }
    public function getPostingPlace(): string { return $this->postingPlace; }
    public function getConfirmedBy(): User { return $this->confirmedBy; }
    public function getEvidenceDocument(): ?Document { return $this->evidenceDocument; }
    public function getNotes(): ?string { return $this->notes; }

    private static function nullableTrim(?string $value): ?string
    {
        if (null === $value) {
            return null;
        }

        $value = trim($value);

        return '' === $value ? null : $value;
    }
}
