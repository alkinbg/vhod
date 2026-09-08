<?php

declare(strict_types=1);

namespace App\Entity;

use App\Value\Iban;
use Doctrine\ORM\Mapping as ORM;
use InvalidArgumentException;

#[ORM\Entity]
#[ORM\Table(name: 'bank_account')]
#[ORM\UniqueConstraint(name: 'uniq_bank_account_iban', columns: ['iban'])]
class BankAccount
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 120)]
    private string $name;

    #[ORM\Column(length: 34)]
    private string $iban;

    #[ORM\Column(length: 3)]
    private string $currency = 'EUR';

    #[ORM\Column]
    private bool $active = true;

    private function __construct(string $name, string $iban)
    {
        $name = trim($name);
        if ('' === $name) {
            throw new InvalidArgumentException('Bank account name must not be blank.');
        }

        $this->name = $name;
        $this->iban = Iban::normalize($iban);
    }

    public static function create(string $name, string $iban): self
    {
        return new self($name, $iban);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getIban(): string
    {
        return $this->iban;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function deactivate(): void
    {
        $this->active = false;
    }
}
