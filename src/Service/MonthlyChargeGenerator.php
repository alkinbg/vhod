<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AnimalRegistration;
use App\Entity\Charge;
use App\Entity\FeePolicy;
use App\Entity\FeePolicyUnitRule;
use App\Entity\HouseholdMember;
use App\Entity\Unit;
use App\Entity\UnitRelation;
use App\Enum\FeeDistribution;
use App\Enum\UnitRelationType;
use App\Value\ChargeGenerationResult;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use DomainException;

final readonly class MonthlyChargeGenerator
{
    private const IDEAL_PARTS_TOTAL = 1_000_000;

    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function generate(DateTimeImmutable $billingMonth, DateTimeImmutable $postedAt): ChargeGenerationResult
    {
        $billingMonth = $billingMonth->modify('first day of this month')->setTime(0, 0);

        return $this->entityManager->wrapInTransaction(function (EntityManagerInterface $entityManager) use ($billingMonth, $postedAt): ChargeGenerationResult {
            $policies = $this->effectivePolicies($billingMonth);
            $units = $entityManager->getRepository(Unit::class)->findBy(['active' => true], ['designation' => 'ASC']);

            $created = 0;
            $skipped = 0;
            $totalAmountCents = 0;

            foreach ($policies as $policy) {
                if (FeeDistribution::IDEAL_PARTS === $policy->getDistribution()) {
                    $result = $this->generateIdealPartsPolicy($entityManager, $policy, $units, $billingMonth, $postedAt);
                    $created += $result->created;
                    $skipped += $result->skipped;
                    $totalAmountCents += $result->totalAmountCents;
                    continue;
                }

                foreach ($units as $unit) {
                    if ($this->chargeExists($policy, $unit, $billingMonth)) {
                        ++$skipped;
                        continue;
                    }

                    $rule = $this->effectiveRule($policy, $unit, $billingMonth);

                    if (FeeDistribution::PER_UNIT === $policy->getDistribution()) {
                        $baseQuantity = '1.000';
                        $details = ['base_quantity' => '1.000'];
                    } else {
                        [$baseQuantity, $details] = $this->perPersonQuantity($policy, $unit, $billingMonth);
                    }

                    $quantity = $rule?->getQuantityOverride() ?? $baseQuantity;
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
                            ...$details,
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

    /** @return list<FeePolicy> */
    private function effectivePolicies(DateTimeImmutable $billingMonth): array
    {
        $effective = [];
        $codes = [];

        foreach ($this->entityManager->getRepository(FeePolicy::class)->findAll() as $policy) {
            if (!$policy->isEffectiveFor($billingMonth)) {
                continue;
            }

            $code = $policy->getCode();
            if (isset($codes[$code])) {
                throw new DomainException(sprintf(
                    'Multiple fee policy versions with code "%s" are effective for %s.',
                    $code,
                    $billingMonth->format('Y-m'),
                ));
            }

            $codes[$code] = true;
            $effective[] = $policy;
        }

        return $effective;
    }

    /**
     * @param list<Unit> $units
     */
    private function generateIdealPartsPolicy(
        EntityManagerInterface $entityManager,
        FeePolicy $policy,
        array $units,
        DateTimeImmutable $billingMonth,
        DateTimeImmutable $postedAt,
    ): ChargeGenerationResult {
        /** @var list<array{unit: Unit, idealParts: string, share: int, amount: int, remainder: int}> $allocations */
        $allocations = [];
        $shareTotal = 0;
        $floorTotal = 0;

        foreach ($units as $unit) {
            $idealParts = $unit->getIdealParts();
            if (null === $idealParts) {
                throw new DomainException(sprintf('Unit "%s" is missing ideal parts.', $unit->getDesignation()));
            }

            [$canonical, $share] = self::normalizeIdealParts($idealParts, $unit->getDesignation());
            $numerator = $policy->getMonthlyAmountCents() * $share;
            $amount = intdiv($numerator, self::IDEAL_PARTS_TOTAL);
            $remainder = $numerator % self::IDEAL_PARTS_TOTAL;

            $allocations[] = [
                'unit' => $unit,
                'idealParts' => $canonical,
                'share' => $share,
                'amount' => $amount,
                'remainder' => $remainder,
            ];
            $shareTotal += $share;
            $floorTotal += $amount;
        }

        if (self::IDEAL_PARTS_TOTAL !== $shareTotal) {
            throw new DomainException(sprintf(
                'Ideal parts for active units must total 100.0000%%; got %s%%.',
                self::formatIdealParts($shareTotal),
            ));
        }

        usort($allocations, static function (array $left, array $right): int {
            $remainderOrder = $right['remainder'] <=> $left['remainder'];
            if (0 !== $remainderOrder) {
                return $remainderOrder;
            }

            $leftId = $left['unit']->getId();
            $rightId = $right['unit']->getId();
            if (null === $leftId || null === $rightId) {
                throw new DomainException('Cannot allocate ideal parts for non-persisted units.');
            }

            $idOrder = $leftId <=> $rightId;

            return 0 !== $idOrder
                ? $idOrder
                : strnatcmp($left['unit']->getDesignation(), $right['unit']->getDesignation());
        });

        $remainingCents = $policy->getMonthlyAmountCents() - $floorTotal;
        if ($remainingCents < 0 || $remainingCents > count($allocations)) {
            throw new DomainException('Ideal-parts remainder allocation is inconsistent.');
        }

        foreach ($allocations as $index => $allocation) {
            if (0 === $remainingCents) {
                break;
            }

            ++$allocation['amount'];
            $allocations[$index] = $allocation;
            --$remainingCents;
        }

        $created = 0;
        $skipped = 0;
        $totalAmountCents = 0;

        foreach ($allocations as $allocation) {
            if ($this->chargeExists($policy, $allocation['unit'], $billingMonth)) {
                ++$skipped;
                continue;
            }

            if (0 === $allocation['amount']) {
                ++$skipped;
                continue;
            }

            $charge = Charge::post(
                $policy,
                $allocation['unit'],
                $billingMonth,
                $allocation['idealParts'],
                $policy->getMonthlyAmountCents(),
                $allocation['amount'],
                [
                    'distribution' => FeeDistribution::IDEAL_PARTS->value,
                    'ideal_parts' => $allocation['idealParts'],
                    'ideal_parts_total' => '100.0000',
                    'allocation_method' => 'largest_remainder',
                    'allocation_remainder' => $allocation['remainder'],
                    'quantity_override' => null,
                    'multiplier' => '1.000',
                    'decision_reference' => $policy->getDecisionReference(),
                ],
                $postedAt,
            );
            $entityManager->persist($charge);
            ++$created;
            $totalAmountCents += $allocation['amount'];
        }

        return new ChargeGenerationResult($created, $skipped, $totalAmountCents);
    }

    /**
     * @return array{0: string, 1: array<string, bool|int|string|null>}
     */
    private function perPersonQuantity(FeePolicy $policy, Unit $unit, DateTimeImmutable $billingMonth): array
    {
        $relations = $this->entityManager->getRepository(UnitRelation::class)->findBy(['unit' => $unit]);
        $identities = [];
        $householdRelations = [];

        foreach ($relations as $relation) {
            if (!$relation->isActiveAt($billingMonth)) {
                continue;
            }

            $person = $relation->getPerson();
            if (null !== $person) {
                $personId = $person->getId();
                if (null === $personId) {
                    throw new DomainException('Cannot generate charges from a non-persisted person.');
                }
                $identities['person:'.$personId] = true;
            } else {
                $identifier = $relation->getLegalEntityIdentifier();
                if (null === $identifier) {
                    throw new DomainException('Legal-entity relation is missing its identifier.');
                }
                $identities['legal:'.mb_strtolower($identifier)] = true;
            }

            if (in_array($relation->getType(), [UnitRelationType::OWNER, UnitRelationType::USER], true)) {
                $householdRelations[] = $relation;
            }
        }

        foreach ($householdRelations as $relation) {
            $members = $this->entityManager->getRepository(HouseholdMember::class)->findBy(['relation' => $relation]);
            foreach ($members as $member) {
                if (!$member->isActiveAt($billingMonth)) {
                    continue;
                }

                $personId = $member->getPerson()->getId();
                if (null === $personId) {
                    throw new DomainException('Cannot generate charges from a non-persisted household member.');
                }
                $identities['person:'.$personId] = true;
            }
        }

        $baseOccupancyCount = count($identities);
        $unoccupiedMinimumApplied = 0 === $baseOccupancyCount;
        $chargeablePeople = max(1, $baseOccupancyCount);
        $animalEquivalents = 0;
        $animalSource = null;

        if ($policy->includesAnimalEquivalents()) {
            $animals = $this->entityManager->getRepository(AnimalRegistration::class)->findBy(['unit' => $unit]);
            foreach ($animals as $animal) {
                $animalEquivalents += $animal->getCount();
            }
            $animalSource = 'current_register';
        }

        $quantity = ($chargeablePeople + $animalEquivalents).'.000';

        return [$quantity, [
            'base_occupancy_count' => $baseOccupancyCount,
            'unoccupied_minimum_applied' => $unoccupiedMinimumApplied,
            'animal_equivalents' => $animalEquivalents,
            'animal_source' => $animalSource,
        ]];
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

    private function chargeExists(FeePolicy $policy, Unit $unit, DateTimeImmutable $billingMonth): bool
    {
        return null !== $this->entityManager->getRepository(Charge::class)->findOneBy([
            'policy' => $policy,
            'unit' => $unit,
            'billingMonth' => $billingMonth,
        ]);
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

    /** @return array{string, int} */
    private static function normalizeIdealParts(string $value, string $designation): array
    {
        $value = trim($value);
        if (1 !== preg_match('/^\d+(?:\.\d{1,4})?$/', $value)) {
            throw new DomainException(sprintf('Unit "%s" has invalid ideal parts.', $designation));
        }

        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $whole = ltrim($whole, '0');
        $whole = '' === $whole ? '0' : $whole;
        $fraction = str_pad($fraction, 4, '0');
        $scaled = ((int) $whole * 10_000) + (int) $fraction;

        if ($scaled <= 0 || $scaled > self::IDEAL_PARTS_TOTAL) {
            throw new DomainException(sprintf('Unit "%s" has invalid ideal parts.', $designation));
        }

        return [$whole.'.'.$fraction, $scaled];
    }

    private static function formatIdealParts(int $scaled): string
    {
        $whole = intdiv($scaled, 10_000);
        $fraction = $scaled % 10_000;

        return $whole.'.'.str_pad((string) $fraction, 4, '0', STR_PAD_LEFT);
    }
}
