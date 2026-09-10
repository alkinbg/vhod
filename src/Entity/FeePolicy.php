<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\FeeCategory;
use App\Enum\FeeDistribution;
use App\Enum\FundType;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use InvalidArgumentException;

#[ORM\Entity]
#[ORM\Table(name: 'fee_policy')]
#[ORM\UniqueConstraint(name: 'uniq_fee_policy_code_start', columns: ['code', 'effective_from'])]
#[ORM\Index(name: 'idx_fee_policy_effective', columns: ['code', 'effective_from', 'effective_until'])]
class FeePolicy
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 64)]
    private string $code;

    #[ORM\Column(length: 150)]
    private string $name;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Fund $fund;

    #[ORM\Column(enumType: FeeCategory::class)]
    private FeeCategory $category;

    #[ORM\Column(enumType: FeeDistribution::class)]
    private FeeDistribution $distribution;

    #[ORM\Column]
    private int $monthlyAmountCents;

    #[ORM\Column(type: 'date_immutable')]
    private DateTimeImmutable $effectiveFrom;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?DateTimeImmutable $effectiveUntil = null;

    #[ORM\Column(length: 255)]
    private string $decisionReference;

    #[ORM\Column(options: ['default' => false])]
    private bool $includeAnimalEquivalents = false;

    #[ORM\Column(options: ['default' => false])]
    private bool $statutoryMinimumConfirmed = false;

    private function __construct(
        string $code,
        string $name,
        Fund $fund,
        FeeCategory $category,
        FeeDistribution $distribution,
        int $monthlyAmountCents,
        DateTimeImmutable $effectiveFrom,
        string $decisionReference,
        bool $includeAnimalEquivalents,
        bool $statutoryMinimumConfirmed,
    ) {
        $code = trim($code);
        $name = trim($name);
        $decisionReference = trim($decisionReference);

        if ('' === $code) {
            throw new InvalidArgumentException('Fee policy code cannot be empty.');
        }
        if ('' === $name) {
            throw new InvalidArgumentException('Fee policy name cannot be empty.');
        }
        if ('' === $decisionReference) {
            throw new InvalidArgumentException('A General Assembly decision reference is required.');
        }
        if ($monthlyAmountCents <= 0) {
            throw new InvalidArgumentException('Monthly amount must be positive.');
        }
        self::assertFirstDayOfMonth($effectiveFrom, 'Policy effective-from date');

        if ($includeAnimalEquivalents && FeeDistribution::PER_PERSON !== $distribution) {
            throw new InvalidArgumentException('Animal equivalents are only valid for per-person policies.');
        }

        if (FeeCategory::REPAIR_RENOVATION === $category) {
            if (FundType::REPAIR_RENOVATION !== $fund->getType()) {
                throw new InvalidArgumentException('Repair and renovation fees must use the Repair and Renovation Fund.');
            }
            if (FeeDistribution::IDEAL_PARTS !== $distribution) {
                throw new InvalidArgumentException('Repair and renovation fees must be distributed by ideal parts.');
            }
            if (!$statutoryMinimumConfirmed) {
                throw new InvalidArgumentException('The statutory minimum must be confirmed for repair-fund policies.');
            }
        }

        $this->code = $code;
        $this->name = $name;
        $this->fund = $fund;
        $this->category = $category;
        $this->distribution = $distribution;
        $this->monthlyAmountCents = $monthlyAmountCents;
        $this->effectiveFrom = $effectiveFrom;
        $this->decisionReference = $decisionReference;
        $this->includeAnimalEquivalents = $includeAnimalEquivalents;
        $this->statutoryMinimumConfirmed = $statutoryMinimumConfirmed;
    }

    public static function create(
        string $code,
        string $name,
        Fund $fund,
        FeeCategory $category,
        FeeDistribution $distribution,
        int $monthlyAmountCents,
        DateTimeImmutable $effectiveFrom,
        string $decisionReference,
        bool $includeAnimalEquivalents = false,
        bool $statutoryMinimumConfirmed = false,
    ): self {
        return new self(
            $code,
            $name,
            $fund,
            $category,
            $distribution,
            $monthlyAmountCents,
            $effectiveFrom,
            $decisionReference,
            $includeAnimalEquivalents,
            $statutoryMinimumConfirmed,
        );
    }

    public function getId(): ?int { return $this->id; }
    public function getCode(): string { return $this->code; }
    public function getName(): string { return $this->name; }
    public function getFund(): Fund { return $this->fund; }
    public function getCategory(): FeeCategory { return $this->category; }
    public function getDistribution(): FeeDistribution { return $this->distribution; }
    public function getMonthlyAmountCents(): int { return $this->monthlyAmountCents; }
    public function getEffectiveFrom(): DateTimeImmutable { return $this->effectiveFrom; }
    public function getEffectiveUntil(): ?DateTimeImmutable { return $this->effectiveUntil; }
    public function getDecisionReference(): string { return $this->decisionReference; }
    public function includesAnimalEquivalents(): bool { return $this->includeAnimalEquivalents; }
    public function isStatutoryMinimumConfirmed(): bool { return $this->statutoryMinimumConfirmed; }

    public function endAt(DateTimeImmutable $effectiveUntil): void
    {
        self::assertLastDayOfMonth($effectiveUntil, 'Policy effective-until date');
        if ($effectiveUntil < $this->effectiveFrom) {
            throw new InvalidArgumentException('Policy cannot end before it starts.');
        }

        $this->effectiveUntil = $effectiveUntil;
    }

    public function isEffectiveFor(DateTimeImmutable $billingMonth): bool
    {
        $billingDate = $billingMonth->format('Y-m-d');

        return $billingDate >= $this->effectiveFrom->format('Y-m-d')
            && (null === $this->effectiveUntil || $billingDate <= $this->effectiveUntil->format('Y-m-d'));
    }

    private static function assertFirstDayOfMonth(DateTimeImmutable $date, string $label): void
    {
        if ('01' !== $date->format('d')) {
            throw new InvalidArgumentException(sprintf('%s must be the first day of a month.', $label));
        }
    }

    private static function assertLastDayOfMonth(DateTimeImmutable $date, string $label): void
    {
        if ($date->format('Y-m-d') !== $date->format('Y-m-t')) {
            throw new InvalidArgumentException(sprintf('%s must be the last day of a month.', $label));
        }
    }
}
