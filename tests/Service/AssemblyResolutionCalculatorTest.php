<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\AssemblyAgendaItem;
use App\Entity\AssemblyElectorateEntry;
use App\Entity\AssemblyVote;
use App\Entity\GeneralAssembly;
use App\Entity\Person;
use App\Entity\Unit;
use App\Entity\User;
use App\Enum\AssemblyConveningBasis;
use App\Enum\AssemblyDecisionKind;
use App\Enum\AssemblyPrincipalType;
use App\Enum\AssemblyResolutionResult;
use App\Enum\AssemblyVoteChoice;
use App\Enum\AssemblyVoteDenominator;
use App\Enum\MajorityComparison;
use App\Service\AssemblyResolutionCalculator;
use App\Value\AssemblyMajorityRuleSnapshot;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class AssemblyResolutionCalculatorTest extends TestCase
{
    public function testAtLeastFiftyOfAllCommonIdealPartsAcceptsExactlyFifty(): void
    {
        [$item, $votes] = $this->scenario(
            AssemblyVoteDenominator::ALL_COMMON_IDEAL_PARTS,
            '50',
            MajorityComparison::AT_LEAST,
            [['50', AssemblyVoteChoice::FOR], ['50', AssemblyVoteChoice::AGAINST]],
        );

        $result = (new AssemblyResolutionCalculator())->calculate($item, $votes, '100', '100');

        self::assertSame('50.00000000', $result->forIdealPartsPercent);
        self::assertSame('50.00000000', $result->againstIdealPartsPercent);
        self::assertSame('100.00000000', $result->denominatorIdealPartsPercent);
        self::assertSame('50.00000000', $result->requiredIdealPartsPercent);
        self::assertSame(AssemblyResolutionResult::ACCEPTED, $result->result);
    }

    public function testGreaterThanFiftyRejectsExactlyFiftyAndAcceptsAboveFifty(): void
    {
        [$item, $votes] = $this->scenario(
            AssemblyVoteDenominator::ALL_COMMON_IDEAL_PARTS,
            '50',
            MajorityComparison::GREATER_THAN,
            [['50', AssemblyVoteChoice::FOR], ['50', AssemblyVoteChoice::AGAINST]],
        );

        $calculator = new AssemblyResolutionCalculator();
        self::assertSame(
            AssemblyResolutionResult::REJECTED,
            $calculator->calculate($item, $votes, '100', '100')->result,
        );

        [$item, $votes] = $this->scenario(
            AssemblyVoteDenominator::ALL_COMMON_IDEAL_PARTS,
            '50',
            MajorityComparison::GREATER_THAN,
            [['50.0001', AssemblyVoteChoice::FOR], ['49.9999', AssemblyVoteChoice::AGAINST]],
        );

        self::assertSame(
            AssemblyResolutionResult::ACCEPTED,
            $calculator->calculate($item, $votes, '100', '100')->result,
        );
    }

    public function testRepresentedAtMeetingUsesRepresentedDenominatorInsteadOfOneHundred(): void
    {
        [$item, $votes] = $this->scenario(
            AssemblyVoteDenominator::REPRESENTED_AT_MEETING,
            '50',
            MajorityComparison::GREATER_THAN,
            [['31', AssemblyVoteChoice::FOR], ['29', AssemblyVoteChoice::AGAINST]],
        );

        $result = (new AssemblyResolutionCalculator())->calculate($item, $votes, '100', '60');

        self::assertSame('60.00000000', $result->denominatorIdealPartsPercent);
        self::assertSame('30.00000000', $result->requiredIdealPartsPercent);
        self::assertSame(AssemblyResolutionResult::ACCEPTED, $result->result);
    }

    public function testRuleMarkedForLegalReviewProducesReviewRequired(): void
    {
        [$assembly, $manager] = $this->assembly();
        $rule = new AssemblyMajorityRuleSnapshot(
            'review-rule',
            AssemblyVoteDenominator::ALL_COMMON_IDEAL_PARTS,
            '50',
            MajorityComparison::AT_LEAST,
            'Изисква правен преглед',
            '2026-09-09',
            true,
        );
        $item = $assembly->addAgendaItem(1, 'Точка', null, 'Решение', AssemblyDecisionKind::ORDINARY, $rule);
        $assembly->convene($manager, new DateTimeImmutable('2026-09-19T12:00:00Z'));
        $assembly->start($manager, new DateTimeImmutable('2026-09-20T15:00:00Z'));
        $item->open('Финално решение', new DateTimeImmutable('2026-09-20T15:01:00Z'));

        $result = (new AssemblyResolutionCalculator())->calculate($item, [], '100', '60');

        self::assertSame(AssemblyResolutionResult::REVIEW_REQUIRED, $result->result);
    }

    /**
     * @param list<array{string, AssemblyVoteChoice}> $choices
     * @return array{AssemblyAgendaItem, list<AssemblyVote>}
     */
    private function scenario(
        AssemblyVoteDenominator $denominator,
        string $threshold,
        MajorityComparison $comparison,
        array $choices,
    ): array {
        [$assembly, $manager] = $this->assembly();
        $rule = new AssemblyMajorityRuleSnapshot(
            'test-rule',
            $denominator,
            $threshold,
            $comparison,
            'Тестово правило',
            '2026-09-09',
        );
        $item = $assembly->addAgendaItem(1, 'Точка', null, 'Решение', AssemblyDecisionKind::ORDINARY, $rule);
        $assembly->convene($manager, new DateTimeImmutable('2026-09-19T12:00:00Z'));
        $assembly->start($manager, new DateTimeImmutable('2026-09-20T15:00:00Z'));
        $item->open('Финално решение', new DateTimeImmutable('2026-09-20T15:01:00Z'));

        $votes = [];
        foreach ($choices as $index => [$weight, $choice]) {
            $person = new Person('Гласуващ', (string) ($index + 1));
            $unit = new Unit('Ап. '.($index + 1), idealParts: $weight);
            $entry = AssemblyElectorateEntry::snapshot(
                $assembly,
                $unit,
                null,
                'Ап. '.($index + 1),
                AssemblyPrincipalType::PERSON,
                $person,
                'Гласуващ '.($index + 1),
                null,
                'owner',
                '100',
                $weight,
                $weight,
                true,
                null,
                new DateTimeImmutable('2026-09-09T08:05:00Z'),
            );
            $votes[] = AssemblyVote::record(
                $item,
                $entry,
                $choice,
                $weight,
                $manager,
                new DateTimeImmutable('2026-09-20T15:05:00Z'),
            );
        }

        return [$item, $votes];
    }

    /** @return array{GeneralAssembly, User} */
    private function assembly(): array
    {
        $person = new Person('Управител', 'Тестов', email: 'manager-resolution@example.com');
        $manager = new User($person, 'manager-resolution@example.com', 'hash');
        $manager->setRoles(['ROLE_MANAGER']);
        $assembly = GeneralAssembly::draft(
            'Общо събрание',
            new DateTimeImmutable('2026-09-20T15:00:00Z'),
            'Europe/Sofia',
            new DateTimeImmutable('2026-09-09'),
            'Вход А',
            AssemblyConveningBasis::MANAGER_OR_BOARD,
            'Управител',
            $manager,
            $manager,
            new DateTimeImmutable('2026-09-09T08:00:00Z'),
        );

        return [$assembly, $manager];
    }
}
