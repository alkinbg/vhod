<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use InvalidArgumentException;

#[ORM\Entity]
class Person
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100)]
    private string $firstName;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $middleName;

    #[ORM\Column(length: 100)]
    private string $lastName;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $email;

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $phone;

    public function __construct(
        string $firstName,
        string $lastName,
        ?string $middleName = null,
        ?string $email = null,
        ?string $phone = null,
    ) {
        $firstName = trim($firstName);
        $lastName = trim($lastName);

        if ('' === $firstName || '' === $lastName) {
            throw new InvalidArgumentException('First name and last name are required.');
        }

        if (null !== $email && false === filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Invalid email address.');
        }

        $this->firstName = $firstName;
        $this->lastName = $lastName;
        $this->middleName = self::nullableTrim($middleName);
        $this->email = self::nullableTrim($email);
        $this->phone = self::nullableTrim($phone);
    }

    public function getId(): ?int { return $this->id; }
    public function getFirstName(): string { return $this->firstName; }
    public function getMiddleName(): ?string { return $this->middleName; }
    public function getLastName(): string { return $this->lastName; }
    public function getEmail(): ?string { return $this->email; }
    public function getPhone(): ?string { return $this->phone; }

    public function getDisplayName(): string
    {
        return implode(' ', array_filter([
            $this->firstName,
            $this->middleName,
            $this->lastName,
        ], static fn (?string $part): bool => null !== $part && '' !== $part));
    }

    private static function nullableTrim(?string $value): ?string
    {
        if (null === $value) {
            return null;
        }

        $value = trim($value);

        return '' === $value ? null : $value;
    }
}
