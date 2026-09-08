<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use InvalidArgumentException;

#[ORM\Entity]
#[ORM\Table(name: 'maintenance_supplier')]
#[ORM\Index(name: 'idx_maintenance_supplier_active_name', columns: ['active', 'name'])]
class MaintenanceSupplier
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180)]
    private string $name;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $registrationNumber;

    #[ORM\Column(length: 160, nullable: true)]
    private ?string $contactPerson;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $email;

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $phone;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $address;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $note;

    #[ORM\Column(options: ['default' => true])]
    private bool $active = true;

    private function __construct(
        string $name,
        ?string $registrationNumber,
        ?string $contactPerson,
        ?string $email,
        ?string $phone,
        ?string $address,
        ?string $note,
    ) {
        $name = trim($name);
        if ('' === $name || mb_strlen($name) > 180) {
            throw new InvalidArgumentException('Maintenance supplier name must contain between 1 and 180 characters.');
        }

        $email = self::optional($email);
        if (null !== $email) {
            $email = mb_strtolower($email);
            if (false === filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new InvalidArgumentException('Invalid maintenance supplier email address.');
            }
        }

        $this->name = $name;
        $this->registrationNumber = self::optional($registrationNumber);
        $this->contactPerson = self::optional($contactPerson);
        $this->email = $email;
        $this->phone = self::optional($phone);
        $this->address = self::optional($address);
        $this->note = self::optional($note);
    }

    public static function register(
        string $name,
        ?string $registrationNumber = null,
        ?string $contactPerson = null,
        ?string $email = null,
        ?string $phone = null,
        ?string $address = null,
        ?string $note = null,
    ): self {
        return new self($name, $registrationNumber, $contactPerson, $email, $phone, $address, $note);
    }

    public function getId(): ?int { return $this->id; }
    public function getName(): string { return $this->name; }
    public function getRegistrationNumber(): ?string { return $this->registrationNumber; }
    public function getContactPerson(): ?string { return $this->contactPerson; }
    public function getEmail(): ?string { return $this->email; }
    public function getPhone(): ?string { return $this->phone; }
    public function getAddress(): ?string { return $this->address; }
    public function getNote(): ?string { return $this->note; }
    public function isActive(): bool { return $this->active; }

    public function deactivate(): void
    {
        $this->active = false;
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
