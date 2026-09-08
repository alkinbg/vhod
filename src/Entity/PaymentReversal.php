<?php

declare(strict_types=1);

namespace App\Entity;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\Mapping as ORM;
use InvalidArgumentException;

#[ORM\Entity]
#[ORM\Table(name: 'payment_reversal')]
#[ORM\UniqueConstraint(name: 'uniq_payment_reversal_payment', columns: ['payment_id'])]
class PaymentReversal
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Payment $payment;

    #[ORM\Column]
    private int $amountCents;

    #[ORM\Column(type: 'text')]
    private string $reason;

    #[ORM\Column(type: 'datetime_immutable')]
    private DateTimeImmutable $reversedAt;

    private function __construct(
        Payment $payment,
        int $amountCents,
        string $reason,
        DateTimeImmutable $reversedAt,
    ) {
        $reason = trim($reason);
        if ('' === $reason) {
            throw new InvalidArgumentException('A reversal reason is required.');
        }
        if ($amountCents !== $payment->getAmountCents()) {
            throw new InvalidArgumentException('Reversal amount must exactly match the original payment.');
        }

        $reversedAt = $reversedAt->setTimezone(new DateTimeZone('UTC'));
        if ($reversedAt < $payment->getPostedAt()) {
            throw new InvalidArgumentException('Reversal cannot predate payment posting.');
        }

        $this->payment = $payment;
        $this->amountCents = $amountCents;
        $this->reason = $reason;
        $this->reversedAt = $reversedAt;
    }

    public static function record(
        Payment $payment,
        int $amountCents,
        string $reason,
        DateTimeImmutable $reversedAt,
    ): self {
        return new self($payment, $amountCents, $reason, $reversedAt);
    }

    public function getId(): ?int { return $this->id; }
    public function getPayment(): Payment { return $this->payment; }
    public function getAmountCents(): int { return $this->amountCents; }
    public function getReason(): string { return $this->reason; }
    public function getReversedAt(): DateTimeImmutable { return $this->reversedAt; }
}
