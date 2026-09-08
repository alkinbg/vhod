<?php

declare(strict_types=1);

namespace App\Entity;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\Mapping as ORM;
use InvalidArgumentException;

#[ORM\Entity]
#[ORM\Table(name: 'external_income_reversal')]
#[ORM\UniqueConstraint(name: 'uniq_external_income_reversal_income', columns: ['external_income_id'])]
#[ORM\Index(name: 'idx_external_income_reversal_reversed_at', columns: ['reversed_at'])]
class ExternalIncomeReversal
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'external_income_id', nullable: false, onDelete: 'RESTRICT')]
    private ExternalIncome $income;

    #[ORM\Column]
    private int $amountCents;

    #[ORM\Column(type: 'text')]
    private string $reason;

    #[ORM\Column(type: 'datetime_immutable')]
    private DateTimeImmutable $reversedAt;

    private function __construct(
        ExternalIncome $income,
        int $amountCents,
        string $reason,
        DateTimeImmutable $reversedAt,
    ) {
        $reason = trim($reason);
        if ('' === $reason) {
            throw new InvalidArgumentException('A reversal reason is required.');
        }
        if ($amountCents !== $income->getAmountCents()) {
            throw new InvalidArgumentException('Reversal amount must exactly match the original external income.');
        }

        $reversedAt = $reversedAt->setTimezone(new DateTimeZone('UTC'));
        if ($reversedAt < $income->getPostedAt()) {
            throw new InvalidArgumentException('Reversal cannot predate external income posting.');
        }

        $this->income = $income;
        $this->amountCents = $amountCents;
        $this->reason = $reason;
        $this->reversedAt = $reversedAt;
    }

    public static function record(
        ExternalIncome $income,
        int $amountCents,
        string $reason,
        DateTimeImmutable $reversedAt,
    ): self {
        return new self($income, $amountCents, $reason, $reversedAt);
    }

    public function getId(): ?int { return $this->id; }
    public function getIncome(): ExternalIncome { return $this->income; }
    public function getAmountCents(): int { return $this->amountCents; }
    public function getReason(): string { return $this->reason; }
    public function getReversedAt(): DateTimeImmutable { return $this->reversedAt; }
}
