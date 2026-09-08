<?php

declare(strict_types=1);

namespace App\Entity;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\Mapping as ORM;
use InvalidArgumentException;

#[ORM\Entity]
#[ORM\Table(name: 'payment_allocation')]
#[ORM\UniqueConstraint(name: 'uniq_payment_allocation_payment_charge', columns: ['payment_id', 'charge_id'])]
#[ORM\Index(name: 'idx_payment_allocation_payment', columns: ['payment_id'])]
#[ORM\Index(name: 'idx_payment_allocation_charge', columns: ['charge_id'])]
class PaymentAllocation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Payment $payment;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Charge $charge;

    #[ORM\Column]
    private int $amountCents;

    #[ORM\Column]
    private int $position;

    #[ORM\Column(type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    private function __construct(
        Payment $payment,
        Charge $charge,
        int $amountCents,
        int $position,
        DateTimeImmutable $createdAt,
    ) {
        if (!self::sameUnit($payment->getUnit(), $charge->getUnit())) {
            throw new InvalidArgumentException('Payment and charge must belong to the same unit.');
        }
        if ($amountCents <= 0) {
            throw new InvalidArgumentException('Allocation amount must be positive.');
        }
        if ($position < 0) {
            throw new InvalidArgumentException('Allocation position cannot be negative.');
        }

        $this->payment = $payment;
        $this->charge = $charge;
        $this->amountCents = $amountCents;
        $this->position = $position;
        $this->createdAt = $createdAt->setTimezone(new DateTimeZone('UTC'));
    }

    public static function allocate(
        Payment $payment,
        Charge $charge,
        int $amountCents,
        int $position,
        DateTimeImmutable $createdAt,
    ): self {
        return new self($payment, $charge, $amountCents, $position, $createdAt);
    }

    public function getId(): ?int { return $this->id; }
    public function getPayment(): Payment { return $this->payment; }
    public function getCharge(): Charge { return $this->charge; }
    public function getAmountCents(): int { return $this->amountCents; }
    public function getPosition(): int { return $this->position; }
    public function getCreatedAt(): DateTimeImmutable { return $this->createdAt; }

    private static function sameUnit(Unit $left, Unit $right): bool
    {
        if ($left === $right) {
            return true;
        }

        $leftId = $left->getId();
        $rightId = $right->getId();

        return null !== $leftId && null !== $rightId && $leftId === $rightId;
    }
}
