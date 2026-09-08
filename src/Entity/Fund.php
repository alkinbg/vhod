<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\FundType;
use Doctrine\ORM\Mapping as ORM;
use InvalidArgumentException;

#[ORM\Entity]
#[ORM\Table(name: 'fund')]
#[ORM\UniqueConstraint(name: 'uniq_fund_code', columns: ['code'])]
class Fund
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 64)]
    private string $code;

    #[ORM\Column(length: 150)]
    private string $name;

    #[ORM\Column(enumType: FundType::class)]
    private FundType $type;

    #[ORM\Column(options: ['default' => true])]
    private bool $active = true;

    public function __construct(string $code, string $name, FundType $type)
    {
        $code = trim($code);
        $name = trim($name);

        if ('' === $code) {
            throw new InvalidArgumentException('Fund code cannot be empty.');
        }
        if ('' === $name) {
            throw new InvalidArgumentException('Fund name cannot be empty.');
        }

        $this->code = $code;
        $this->name = $name;
        $this->type = $type;
    }

    public function getId(): ?int { return $this->id; }
    public function getCode(): string { return $this->code; }
    public function getName(): string { return $this->name; }
    public function getType(): FundType { return $this->type; }
    public function isActive(): bool { return $this->active; }
    public function deactivate(): void { $this->active = false; }
}
