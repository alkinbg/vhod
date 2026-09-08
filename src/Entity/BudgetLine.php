<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\ExpenseCategory;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\Mapping as ORM;
use InvalidArgumentException;

#[ORM\Entity]
#[ORM\Table(name: 'budget_line')]
#[ORM\UniqueConstraint(name: 'uniq_budget_year_fund_category', columns: ['budget_year', 'fund_id', 'category'])]
class BudgetLine
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'budget_year')]
    private int $year;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Fund $fund;

    #[ORM\Column(enumType: ExpenseCategory::class)]
    private ExpenseCategory $category;

    #[ORM\Column]
    private int $amountCents;

    #[ORM\Column(length: 190)]
    private string $decisionReference;

    #[ORM\Column(type: 'datetime_immutable')]
    private DateTimeImmutable $postedAt;

    private function __construct(
        int $year,
        Fund $fund,
        ExpenseCategory $category,
        int $amountCents,
        string $decisionReference,
        DateTimeImmutable $postedAt,
    ) {
        if ($year < 2020 || $year > 2100) {
            throw new InvalidArgumentException('Budget year is outside the supported range.');
        }
        if ($amountCents <= 0) {
            throw new InvalidArgumentException('Budget amount must be positive.');
        }

        $decisionReference = trim($decisionReference);
        if ('' === $decisionReference) {
            throw new InvalidArgumentException('Budget decision reference is required.');
        }

        $this->year = $year;
        $this->fund = $fund;
        $this->category = $category;
        $this->amountCents = $amountCents;
        $this->decisionReference = $decisionReference;
        $this->postedAt = $postedAt->setTimezone(new DateTimeZone('UTC'));
    }

    public static function plan(
        int $year,
        Fund $fund,
        ExpenseCategory $category,
        int $amountCents,
        string $decisionReference,
        DateTimeImmutable $postedAt,
    ): self {
        return new self($year, $fund, $category, $amountCents, $decisionReference, $postedAt);
    }

    public function getId(): ?int { return $this->id; }
    public function getYear(): int { return $this->year; }
    public function getFund(): Fund { return $this->fund; }
    public function getCategory(): ExpenseCategory { return $this->category; }
    public function getAmountCents(): int { return $this->amountCents; }
    public function getDecisionReference(): string { return $this->decisionReference; }
    public function getPostedAt(): DateTimeImmutable { return $this->postedAt; }
}
