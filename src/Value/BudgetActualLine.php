<?php

declare(strict_types=1);

namespace App\Value;

use App\Entity\Fund;
use App\Enum\ExpenseCategory;

final readonly class BudgetActualLine
{
    public function __construct(
        private Fund $fund,
        private ExpenseCategory $category,
        private int $budgetCents,
        private int $actualCents,
    ) {
    }

    public function getFund(): Fund { return $this->fund; }
    public function getCategory(): ExpenseCategory { return $this->category; }
    public function getBudgetCents(): int { return $this->budgetCents; }
    public function getActualCents(): int { return $this->actualCents; }
    public function getRemainingCents(): int { return $this->budgetCents - $this->actualCents; }
}
