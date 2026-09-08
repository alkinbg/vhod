<?php

declare(strict_types=1);

namespace App\Entity;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\Mapping as ORM;
use InvalidArgumentException;

#[ORM\Entity]
#[ORM\Table(name: 'expense_reversal')]
#[ORM\UniqueConstraint(name: 'uniq_expense_reversal_expense', columns: ['expense_id'])]
#[ORM\Index(name: 'idx_expense_reversal_reversed_at', columns: ['reversed_at'])]
class ExpenseReversal
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Expense $expense;

    #[ORM\Column]
    private int $amountCents;

    #[ORM\Column(type: 'text')]
    private string $reason;

    #[ORM\Column(type: 'datetime_immutable')]
    private DateTimeImmutable $reversedAt;

    private function __construct(
        Expense $expense,
        int $amountCents,
        string $reason,
        DateTimeImmutable $reversedAt,
    ) {
        $reason = trim($reason);
        if ('' === $reason) {
            throw new InvalidArgumentException('A reversal reason is required.');
        }
        if ($amountCents !== $expense->getAmountCents()) {
            throw new InvalidArgumentException('Reversal amount must exactly match the original expense.');
        }

        $reversedAt = $reversedAt->setTimezone(new DateTimeZone('UTC'));
        if ($reversedAt < $expense->getPostedAt()) {
            throw new InvalidArgumentException('Reversal cannot predate expense posting.');
        }

        $this->expense = $expense;
        $this->amountCents = $amountCents;
        $this->reason = $reason;
        $this->reversedAt = $reversedAt;
    }

    public static function record(
        Expense $expense,
        int $amountCents,
        string $reason,
        DateTimeImmutable $reversedAt,
    ): self {
        return new self($expense, $amountCents, $reason, $reversedAt);
    }

    public function getId(): ?int { return $this->id; }
    public function getExpense(): Expense { return $this->expense; }
    public function getAmountCents(): int { return $this->amountCents; }
    public function getReason(): string { return $this->reason; }
    public function getReversedAt(): DateTimeImmutable { return $this->reversedAt; }
}
