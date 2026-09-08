<?php

declare(strict_types=1);

namespace App\Value;

use InvalidArgumentException;

final readonly class FinancialReportLine
{
    public function __construct(
        private string $code,
        private string $label,
        private int $amountCents,
    ) {
        if ('' === trim($this->code)) {
            throw new InvalidArgumentException('Report line code cannot be empty.');
        }
        if ('' === trim($this->label)) {
            throw new InvalidArgumentException('Report line label cannot be empty.');
        }
    }

    public function getCode(): string { return $this->code; }
    public function getLabel(): string { return $this->label; }
    public function getAmountCents(): int { return $this->amountCents; }
}
