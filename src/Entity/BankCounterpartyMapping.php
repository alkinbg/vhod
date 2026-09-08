<?php

declare(strict_types=1);

namespace App\Entity;

use App\Value\Iban;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'bank_counterparty_mapping')]
#[ORM\UniqueConstraint(name: 'uniq_bank_counterparty_mapping_active_iban', columns: ['counterparty_iban', 'active'])]
#[ORM\Index(name: 'idx_bank_counterparty_mapping_unit', columns: ['unit_id'])]
class BankCounterpartyMapping
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 34)]
    private string $counterpartyIban;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Unit $unit;

    #[ORM\Column(nullable: true)]
    private ?bool $active = true;

    #[ORM\Column(type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    private function __construct(string $counterpartyIban, Unit $unit, DateTimeImmutable $createdAt)
    {
        $this->counterpartyIban = Iban::normalize($counterpartyIban);
        $this->unit = $unit;
        $this->createdAt = $createdAt->setTimezone(new DateTimeZone('UTC'));
    }

    public static function create(string $counterpartyIban, Unit $unit, DateTimeImmutable $createdAt): self
    {
        return new self($counterpartyIban, $unit, $createdAt);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCounterpartyIban(): string
    {
        return $this->counterpartyIban;
    }

    public function getUnit(): Unit
    {
        return $this->unit;
    }

    public function isActive(): bool
    {
        return true === $this->active;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function deactivate(): void
    {
        $this->active = null;
    }
}
