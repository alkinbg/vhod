<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Charge;
use App\Entity\FeePolicy;
use App\Entity\FeePolicyUnitRule;
use App\Entity\Unit;
use App\Enum\FeeDistribution;
use App\Value\ChargeGenerationResult;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use DomainException;

final readonly class MonthlyChargeGenerator
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function generate(DateTimeImmutable $billingMonth, DateTimeImmutable $postedAt): ChargeGenerationResult
    {
        $billingMonth = $billingMonth->modify('first day of this month')->setTime(0, 0);

        return $this->entityManager->wrapInTransaction(function (EntityManagerInterface $entityManager) use ($billingMonth, $postedAt): ChargeGenerationResult {
            $policies = $entityManager->getRepository(FeePolicy::class)->findAll();
            $units = $entityManager->getRepository(Unit::class)->findBy(['active' => true], ['designation' => 'ASC']);

            $created = 0;
            $skipped = 0;
            $totalAmountCents = 0;

            foreach ($policies as $policy) {
                if (!$policy->isEffectiveFor($billingMonth)) {
                    continue;
                }

                if (FeeDistribution::PER_UNIT !== $policy->getDistribution()) {
                    throw new DomainException(sprintf('Distribution "%s" is not implemented yet.', $policy->getDistribution()->value));
                }

                foreach ($units as $unit) {
                    $rule = $this->effectiveRule($policy, $unit, $billingMonth);
                    $quantity = $rule?->getQuantityOverride() ?? '1.000';
                    $multiplier = $rule?->getMultiplier() ?? '1.000';
                    $amountCents = self::calculateAmountCents(
                        $policy->getMonthlyAmountCents(),
                        $quantity,
                        $multiplier,
                    );

                    if (0 === $amountCents) {
                        ++$skipped;
                        continue;
                    }

                    $charge = Charge::post(
                        $policy,
                        $unit,
                        $billingMonth,
                        $quantity,
                        $policy->getMonthlyAmountCents(),
                        $amountCents,
                        [
                            'distribution' => $policy->getDistribution()->value,
                            'base_quantity' => '1.000',
                            'quantity_override' => $rule?->getQuantityOverride(),
                            'multiplier' => $multiplier,
                            'decision_reference' => $policy->getDecisionReference(),
                            'unit_rule_reason' => $rule?->getReason(),
                            'unit_rule_decision_reference' => $rule?->getDecisionReference(),
                        ],
                        $postedAt,
                    );
                    $entityManager->persist($charge);
                    ++$created;
                    $totalAmountCents += $amountCents;
                }
            }

            $entityManager->flush();

            return new ChargeGenerationResult($created, $skipped, $totalAmountCents);
        });
    }

    private function effectiveRule(FeePolicy $policy, Unit $unit, DateTimeImmutable $billingMonth): ?FeePolicyUnitRule
    {
        $rules = $this->entityManager->getRepository(FeePolicyUnitRule::class)->findBy([
            'policy' => $policy,
            'unit' => $unit,
        ]);
        $effective = [];

        foreach ($rules as $rule) {
            if ($rule->isEffectiveFor($billingMonth)) {
                $effective[] = $rule;
            }
        }

        if (count($effective) > 1) {
            throw new DomainException(sprintf(
                'More than one fee rule is effective for policy "%s" and unit "%s".',
                $policy->getCode(),
                $unit->getDesignation(),
            ));
        }

        return $effective[0] ?? null;
    }

    private static function calculateAmountCents(int $policyAmountCents, string $quantity, string $multiplier): int
    {
        $quantityMilli = self::decimal3ToMilli($quantity);
        $multiplierMilli = self::decimal3ToMilli($multiplier);
        $numerator = $policyAmountCents * $quantityMilli * $multiplierMilli;

        return intdiv($numerator + 500_000, 1_000_000);
    }

    private static function decimal3ToMilli(string $value): int
    {
        if (1 !== preg_match('/^(\d+)\.(\d{3})$/', $value, $matches)) {
            throw new DomainException(sprintf('Expected canonical three-decimal value, got "%s".', $value));
        }

        return ((int) $matches[1] * 1000) + (int) $matches[2];
    }
}
