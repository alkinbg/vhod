<?php

declare(strict_types=1);

namespace App\Entity;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\Mapping as ORM;
use InvalidArgumentException;

#[ORM\Entity]
#[ORM\Table(name: 'charge')]
#[ORM\UniqueConstraint(name: 'uniq_charge_policy_unit_month', columns: ['fee_policy_id', 'unit_id', 'billing_month'])]
#[ORM\Index(name: 'idx_charge_policy', columns: ['fee_policy_id'])]
#[ORM\Index(name: 'idx_charge_unit', columns: ['unit_id'])]
#[ORM\Index(name: 'idx_charge_month', columns: ['billing_month'])]
class Charge
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private FeePolicy $policy;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Unit $unit;

    #[ORM\Column(type: 'date_immutable')]
    private DateTimeImmutable $billingMonth;

    #[ORM\Column(type: 'decimal', precision: 12, scale: 3)]
    private string $quantity;

    #[ORM\Column]
    private int $policyAmountCents;

    #[ORM\Column]
    private int $amountCents;

    /** @var array<string, bool|int|string|null> */
    #[ORM\Column(type: 'json')]
    private array $calculationDetails;

    #[ORM\Column(type: 'datetime_immutable')]
    private DateTimeImmutable $postedAt;

    /**
     * @param array<string, bool|int|string|null> $calculationDetails
     */
    private function __construct(
        FeePolicy $policy,
        Unit $unit,
        DateTimeImmutable $billingMonth,
        string $quantity,
        int $policyAmountCents,
        int $amountCents,
        array $calculationDetails,
        DateTimeImmutable $postedAt,
    ) {
        if ($policyAmountCents <= 0) {
            throw new InvalidArgumentException('Policy amount must be positive.');
        }
        if ($amountCents <= 0) {
            throw new InvalidArgumentException('Charge amount must be positive.');
        }
        if ([] === $calculationDetails) {
            throw new InvalidArgumentException('Calculation details are required.');
        }

        $this->policy = $policy;
        $this->unit = $unit;
        $this->billingMonth = $billingMonth->modify('first day of this month')->setTime(0, 0);
        $this->quantity = self::normalizePositiveDecimal3($quantity);
        $this->policyAmountCents = $policyAmountCents;
        $this->amountCents = $amountCents;
        $this->calculationDetails = $calculationDetails;
        $this->postedAt = $postedAt->setTimezone(new DateTimeZone('UTC'));
    }

    /**
     * @param array<string, bool|int|string|null> $calculationDetails
     */
    public static function post(
        FeePolicy $policy,
        Unit $unit,
        DateTimeImmutable $billingMonth,
        string $quantity,
        int $policyAmountCents,
        int $amountCents,
        array $calculationDetails,
        DateTimeImmutable $postedAt,
    ): self {
        return new self(
            $policy,
            $unit,
            $billingMonth,
            $quantity,
            $policyAmountCents,
            $amountCents,
            $calculationDetails,
            $postedAt,
        );
    }

    public function getId(): ?int { return $this->id; }
    public function getPolicy(): FeePolicy { return $this->policy; }
    public function getUnit(): Unit { return $this->unit; }
    public function getBillingMonth(): DateTimeImmutable { return $this->billingMonth; }
    public function getQuantity(): string { return $this->quantity; }
    public function getPolicyAmountCents(): int { return $this->policyAmountCents; }
    public function getAmountCents(): int { return $this->amountCents; }

    /** @return array<string, bool|int|string|null> */
    public function getCalculationDetails(): array { return $this->calculationDetails; }

    public function getPostedAt(): DateTimeImmutable { return $this->postedAt; }

    private static function normalizePositiveDecimal3(string $value): string
    {
        $value = trim($value);
        if (1 !== preg_match('/^\d+(?:\.\d{1,3})?$/', $value)) {
            throw new InvalidArgumentException('Charge quantity must be a positive decimal with at most three decimal places.');
        }

        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $whole = ltrim($whole, '0');
        $whole = '' === $whole ? '0' : $whole;
        $fraction = str_pad($fraction, 3, '0');
        $normalized = $whole.'.'.$fraction;

        if ((((int) $whole) * 1000) + (int) $fraction <= 0) {
            throw new InvalidArgumentException('Charge quantity must be positive.');
        }

        return $normalized;
    }
}
