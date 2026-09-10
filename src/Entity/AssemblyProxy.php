<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\DocumentAccessLevel;
use App\Repository\AssemblyProxyRepository;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\Mapping as ORM;
use InvalidArgumentException;

#[ORM\Entity(repositoryClass: AssemblyProxyRepository::class)]
#[ORM\Table(name: 'assembly_proxy')]
#[ORM\Index(name: 'idx_assembly_proxy_assembly', columns: ['assembly_id'])]
#[ORM\Index(name: 'idx_assembly_proxy_principal', columns: ['principal_entry_id'])]
#[ORM\Index(name: 'idx_assembly_proxy_representative_person', columns: ['representative_person_id'])]
#[ORM\Index(name: 'idx_proxy_assembly_representative', columns: ['assembly_id', 'representative_person_id'])]
#[ORM\Index(name: 'idx_assembly_proxy_registered_by', columns: ['registered_by_id'])]
#[ORM\Index(name: 'idx_assembly_proxy_evidence_document', columns: ['evidence_document_id'])]
#[ORM\Index(name: 'idx_assembly_proxy_revoked_by', columns: ['revoked_by_id'])]
final class AssemblyProxy
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'assembly_id', nullable: false, onDelete: 'RESTRICT', foreignKeyName: 'fk_assembly_proxy_assembly')]
    private GeneralAssembly $assembly;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'principal_entry_id', nullable: false, onDelete: 'RESTRICT', foreignKeyName: 'fk_assembly_proxy_principal')]
    private AssemblyElectorateEntry $principalEntry;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'representative_person_id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_assembly_proxy_representative_person')]
    private ?Person $representativePerson;

    #[ORM\Column(length: 255)]
    private string $representativeName;

    #[ORM\Column(length: 255)]
    private string $authorityKind;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'evidence_document_id', nullable: false, onDelete: 'RESTRICT', foreignKeyName: 'fk_assembly_proxy_evidence_document')]
    private Document $evidenceDocument;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'registered_by_id', nullable: false, onDelete: 'RESTRICT', foreignKeyName: 'fk_assembly_proxy_registered_by')]
    private User $registeredBy;

    #[ORM\Column(type: 'datetime_immutable')]
    private DateTimeImmutable $registeredAt;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $notes;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $revokedAt = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'revoked_by_id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_assembly_proxy_revoked_by')]
    private ?User $revokedBy = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $revocationReason = null;

    private function __construct(
        GeneralAssembly $assembly,
        AssemblyElectorateEntry $principalEntry,
        ?Person $representativePerson,
        string $representativeName,
        string $authorityKind,
        Document $evidenceDocument,
        User $registeredBy,
        DateTimeImmutable $registeredAt,
        ?string $notes,
    ) {
        $representativeName = trim($representativeName);
        $authorityKind = trim($authorityKind);
        $notes = self::nullableTrim($notes);

        if ($principalEntry->getAssembly() !== $assembly) {
            throw new InvalidArgumentException('Proxy principal must belong to the same General Assembly.');
        }
        if ('' === $representativeName || mb_strlen($representativeName) > 255) {
            throw new InvalidArgumentException('Proxy representative name must contain between 1 and 255 characters.');
        }
        if ('' === $authorityKind || mb_strlen($authorityKind) > 255) {
            throw new InvalidArgumentException('Proxy authority kind must contain between 1 and 255 characters.');
        }
        if (DocumentAccessLevel::GOVERNANCE !== $evidenceDocument->getAccessLevel()) {
            throw new InvalidArgumentException('Proxy evidence document must use GOVERNANCE access.');
        }

        $this->assembly = $assembly;
        $this->principalEntry = $principalEntry;
        $this->representativePerson = $representativePerson;
        $this->representativeName = $representativeName;
        $this->authorityKind = $authorityKind;
        $this->evidenceDocument = $evidenceDocument;
        $this->registeredBy = $registeredBy;
        $this->registeredAt = $registeredAt->setTimezone(new DateTimeZone('UTC'));
        $this->notes = $notes;
    }

    public static function register(
        GeneralAssembly $assembly,
        AssemblyElectorateEntry $principalEntry,
        ?Person $representativePerson,
        string $representativeName,
        string $authorityKind,
        Document $evidenceDocument,
        User $registeredBy,
        DateTimeImmutable $registeredAt,
        ?string $notes = null,
    ): self {
        return new self($assembly, $principalEntry, $representativePerson, $representativeName, $authorityKind, $evidenceDocument, $registeredBy, $registeredAt, $notes);
    }

    public function revoke(User $actor, string $reason, DateTimeImmutable $revokedAt): void
    {
        if ($this->isRevoked()) {
            throw new InvalidArgumentException('Proxy is already revoked.');
        }
        $reason = trim($reason);
        if ('' === $reason) {
            throw new InvalidArgumentException('Proxy revocation reason is required.');
        }
        $revokedAt = $revokedAt->setTimezone(new DateTimeZone('UTC'));
        if ($revokedAt < $this->registeredAt) {
            throw new InvalidArgumentException('Proxy cannot be revoked before it was registered.');
        }

        $this->revokedAt = $revokedAt;
        $this->revokedBy = $actor;
        $this->revocationReason = $reason;
    }

    public function getId(): ?int { return $this->id; }
    public function getAssembly(): GeneralAssembly { return $this->assembly; }
    public function getPrincipalEntry(): AssemblyElectorateEntry { return $this->principalEntry; }
    public function getRepresentativePerson(): ?Person { return $this->representativePerson; }
    public function getRepresentativeName(): string { return $this->representativeName; }
    public function getAuthorityKind(): string { return $this->authorityKind; }
    public function getEvidenceDocument(): Document { return $this->evidenceDocument; }
    public function getRegisteredBy(): User { return $this->registeredBy; }
    public function getRegisteredAt(): DateTimeImmutable { return $this->registeredAt; }
    public function getNotes(): ?string { return $this->notes; }
    public function getRevokedAt(): ?DateTimeImmutable { return $this->revokedAt; }
    public function getRevokedBy(): ?User { return $this->revokedBy; }
    public function getRevocationReason(): ?string { return $this->revocationReason; }
    public function isRevoked(): bool { return null !== $this->revokedAt; }

    private static function nullableTrim(?string $value): ?string
    {
        if (null === $value) {
            return null;
        }
        $value = trim($value);

        return '' === $value ? null : $value;
    }
}
