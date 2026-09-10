<?php

declare(strict_types=1);

namespace App\Entity;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\Mapping as ORM;
use InvalidArgumentException;

#[ORM\Entity]
#[ORM\Table(name: 'condominium_profile')]
#[ORM\UniqueConstraint(name: 'uniq_condominium_profile_scope', columns: ['scope_key'])]
#[ORM\Index(name: 'idx_condominium_profile_created_by', columns: ['created_by_id'])]
#[ORM\Index(name: 'idx_condominium_profile_updated_by', columns: ['updated_by_id'])]
final class CondominiumProfile
{
    private const SCOPE_KEY = 'primary';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'scope_key', length: 32)]
    private string $scopeKey = self::SCOPE_KEY;

    #[ORM\Column(name: 'registry_identifier', length: 120, nullable: true)]
    private ?string $registryIdentifier = null;

    #[ORM\Column(name: 'registry_parcel_number', length: 120, nullable: true)]
    private ?string $registryParcelNumber = null;

    #[ORM\Column(name: 'registry_registered_at', type: 'date_immutable', nullable: true)]
    private ?DateTimeImmutable $registryRegisteredAt = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'created_by_id', nullable: false, onDelete: 'RESTRICT', foreignKeyName: 'fk_condominium_profile_created_by')]
    private User $createdBy;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'updated_by_id', nullable: false, onDelete: 'RESTRICT', foreignKeyName: 'fk_condominium_profile_updated_by')]
    private User $updatedBy;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    private DateTimeImmutable $updatedAt;

    private function __construct(User $actor, DateTimeImmutable $createdAt)
    {
        $createdAt = self::toUtc($createdAt);
        $this->createdBy = $actor;
        $this->createdAt = $createdAt;
        $this->updatedBy = $actor;
        $this->updatedAt = $createdAt;
    }

    public static function create(User $actor, DateTimeImmutable $createdAt): self
    {
        return new self($actor, $createdAt);
    }

    public function updateRegistryData(
        ?string $identifier,
        ?string $parcelNumber,
        ?DateTimeImmutable $registeredAt,
        User $actor,
        DateTimeImmutable $updatedAt,
    ): void {
        $identifier = self::optional($identifier, 120, 'Registry identifier');
        if (null !== $this->registryIdentifier && $identifier !== $this->registryIdentifier) {
            throw new InvalidArgumentException('The externally assigned registry identifier cannot be changed once recorded.');
        }

        $this->registryIdentifier = $identifier;
        $this->registryParcelNumber = self::optional($parcelNumber, 120, 'Registry parcel number');
        $this->registryRegisteredAt = $registeredAt;
        $this->updatedBy = $actor;
        $this->updatedAt = self::toUtc($updatedAt);
    }

    public function getId(): ?int { return $this->id; }
    public function getScopeKey(): string { return $this->scopeKey; }
    public function getRegistryIdentifier(): ?string { return $this->registryIdentifier; }
    public function getRegistryParcelNumber(): ?string { return $this->registryParcelNumber; }
    public function getRegistryRegisteredAt(): ?DateTimeImmutable { return $this->registryRegisteredAt; }
    public function getCreatedBy(): User { return $this->createdBy; }
    public function getCreatedAt(): DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedBy(): User { return $this->updatedBy; }
    public function getUpdatedAt(): DateTimeImmutable { return $this->updatedAt; }

    private static function optional(?string $value, int $maxLength, string $field): ?string
    {
        if (null === $value) {
            return null;
        }

        $value = trim($value);
        if ('' === $value) {
            return null;
        }
        if (mb_strlen($value) > $maxLength) {
            throw new InvalidArgumentException(sprintf('%s cannot exceed %d characters.', $field, $maxLength));
        }

        return $value;
    }

    private static function toUtc(DateTimeImmutable $dateTime): DateTimeImmutable
    {
        return $dateTime->setTimezone(new DateTimeZone('UTC'));
    }
}
