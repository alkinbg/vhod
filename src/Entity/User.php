<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use InvalidArgumentException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

#[ORM\Entity]
#[ORM\Table(name: 'app_user')]
#[ORM\UniqueConstraint(name: 'uniq_app_user_email', columns: ['email'])]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    /** @var list<string> */
    public const ALLOWED_ROLES = [
        'ROLE_USER',
        'ROLE_MANAGER',
        'ROLE_CASHIER',
        'ROLE_CONTROLLER',
        'ROLE_ADMIN',
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Person $person;

    #[ORM\Column(length: 180)]
    private string $email;

    /** @var list<string> */
    #[ORM\Column]
    private array $roles = [];

    #[ORM\Column]
    private string $password;

    #[ORM\Column(options: ['default' => true])]
    private bool $active = true;

    public function __construct(Person $person, string $email, string $passwordHash)
    {
        $email = mb_strtolower(trim($email));
        if (false === filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Invalid email address.');
        }
        if ('' === $passwordHash) {
            throw new InvalidArgumentException('Password hash cannot be empty.');
        }

        $this->person = $person;
        $this->email = $email;
        $this->password = $passwordHash;
    }

    public function getId(): ?int { return $this->id; }
    public function getPerson(): Person { return $this->person; }
    public function getUserIdentifier(): string { return $this->email; }

    /** @return list<string> */
    public function getRoles(): array
    {
        return array_values(array_unique([...$this->roles, 'ROLE_USER']));
    }

    /** @param list<string> $roles */
    public function setRoles(array $roles): void
    {
        foreach ($roles as $role) {
            if (!in_array($role, self::ALLOWED_ROLES, true)) {
                throw new InvalidArgumentException(sprintf('Unsupported role "%s".', $role));
            }
        }

        $this->roles = array_values(array_unique($roles));
    }

    public function changePasswordHash(string $passwordHash): void
    {
        if ('' === $passwordHash) {
            throw new InvalidArgumentException('Password hash cannot be empty.');
        }

        $this->password = $passwordHash;
    }

    public function getPassword(): string { return $this->password; }
    public function isActive(): bool { return $this->active; }
    public function deactivate(): void { $this->active = false; }
    public function eraseCredentials(): void {}
}
