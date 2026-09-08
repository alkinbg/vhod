<?php

declare(strict_types=1);

namespace App\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use InvalidArgumentException;

#[ORM\Entity]
#[ORM\Table(name: 'fee_policy_unit_rule')]
#[ORM\UniqueConstraint(name: 'uniq_fee_rule_policy_unit_start', columns: ['fee_policy_id', 'unit_id', 'effective_from'])]
#[ORM\Index(name: 'idx_fee_rule_policy', columns: ['fee_policy_id'])]
#[ORM\Index(name: 'idx_fee_rule_unit', columns: ['unit_id'])]
#[ORM\Index(name: 'idx_fee_rule_effective', columns: ['effective_from', 'effective_until'])]
class FeePolicyUnitRule
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'fee_policy_id', nullable: false, onDelete: 'CASCADE')]
    private FeePolicy $policy;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Unit $unit;

    #[ORM\Column(type: 'date_immutable')]
    private DateTimeImmutable $effectiveFrom;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?DateTimeImmutable $effectiveUntil = null;

    #[ORM\Column(type: 'text')]
    private string $reason;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $decisionReference;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 3, nullable: true)]
    private ?string $quantityOverride;

    #[ORM\Column(type: 'decimal', precision: 6, scale: 3)]
    private string $multiplier;

    public function __construct(
        FeePolicy $policy,
        Unit $unit,
        DateTimeImmutable $effectiveFrom,
        string $reason,
        ?string $decisionReference = null,
        ?string $quantityOverride = null,
        string $multiplier = '1.000',
    ) {
        $reason = trim($reason);
        if ('' === $reason) {
            throw new InvalidArgumentException('A fee rule reason is required.');
        }
        if ('01' !== $effectiveFrom->format('d')) {
            throw new InvalidArgumentException('Rule effective-from date must be the first day of a month.');
        }

        $normalizedQuantityOverride = null === $quantityOverride
            ? null
            : self::normalizePositiveDecimal3($quantityOverride, 'Quantity override');
        $normalizedMultiplier = self::normalizeMultiplier($multiplier);

        if (null === $normalizedQuantityOverride && '1.000' === $normalizedMultiplier) {
            throw new InvalidArgumentException('A fee rule must change quantity or multiplier.');
        }

        $this->policy = $policy;
        $this->unit = $unit;
        $this->effectiveFrom = $effectiveFrom;
        $this->reason = $reason;
        $this->decisionReference = self::nullableTrim($decisionReference);
        $this->quantityOverride = $normalizedQuantityOverride;
        $this->multiplier = $normalizedMultiplier;
    }

    public function getId(): ?int { return $this->id; }
    public function getPolicy(): FeePolicy { return $this->policy; }
    public function getUnit(): Unit { return $this->unit; }
    public function getEffectiveFrom(): DateTimeImmutable { return $this->effectiveFrom; }
    public function getEffectiveUntil(): ?DateTimeImmutable { return $this->effectiveUntil; }
    public function getReason(): string { return $this->reason; }
    public function getDecisionReference(): ?string { return $this->decisionReference; }
    public function getQuantityOverride(): ?string { return $this->quantityOverride; }
    public function getMultiplier(): string { return $this->multiplier; }

    public function endAt(DateTimeImmutable $effectiveUntil): void
    {
        if ($effectiveUntil->format('Y-m-d') !== $effectiveUntil->format('Y-m-t')) {
            throw new InvalidArgumentException('Rule effective-until date must be the last day of a month.');
        }
        if ($effectiveUntil < $this->effectiveFrom) {
            throw new InvalidArgumentException('Rule cannot end before it starts.');
        }

        $this->effectiveUntil = $effectiveUntil;
    }

    public function isEffectiveFor(DateTimeImmutable $billingMonth): bool
    {
        return $billingMonth >= $this->effectiveFrom
            && (null === $this->effectiveUntil || $billingMonth <= $this->effectiveUntil);
    }

    private static function normalizeMultiplier(string $value): string
    {
        $normalized = self::normalizeDecimal3($value, 'Multiplier');
        $milli = self::decimal3ToMilli($normalized);
        if ($milli > 5000) {
            throw new InvalidArgumentException('Multiplier must be between 0.000 and 5.000.');
        }

        return $normalized;
    }

    private static function normalizePositiveDecimal3(string $value, string $label): string
    {
        $normalized = self::normalizeDecimal3($value, $label);
        if (self::decimal3ToMilli($normalized) <= 0) {
            throw new InvalidArgumentException(sprintf('%s must be positive.', $label));
        }

        return $normalized;
    }

    private static function normalizeDecimal3(string $value, string $label): string
    {
        $value = trim($value);
        if (1 !== preg_match('/^\d+(?:\.\d{1,3})?$/', $value)) {
            throw new InvalidArgumentException(sprintf('%s must be a non-negative decimal with at most three decimal places.', $label));
        }

        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $whole = ltrim($whole, '0');
        $whole = '' === $whole ? '0' : $whole;

        return $whole.'.'.str_pad($fraction, 3, '0');
    }

    private static function decimal3ToMilli(string $value): int
    {
        [$whole, $fraction] = explode('.', $value, 2);

        return ((int) $whole * 1000) + (int) $fraction;
    }

    private static function nullableTrim(?string $value): ?string
    {
        if (null === $value) {
            return null;
        }
        $value = trim($value);

        return '' === $value ? null : $value;
    }
}
