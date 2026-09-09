<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AssemblyAgendaItem;
use App\Entity\AssemblyVote;
use App\Enum\AssemblyResolutionResult;
use App\Enum\AssemblyVoteChoice;
use App\Enum\AssemblyVoteDenominator;
use App\Enum\MajorityComparison;
use App\Util\ExactDecimal;
use App\Value\AssemblyResolutionCalculation;
use InvalidArgumentException;

final class AssemblyResolutionCalculator
{
    /** @param list<AssemblyVote> $votes */
    public function calculate(
        AssemblyAgendaItem $item,
        array $votes,
        string $allCommonIdealPartsPercent,
        string $representedAtMeetingPercent,
    ): AssemblyResolutionCalculation {
        $rule = $item->getMajorityRule();
        $allCommon = ExactDecimal::normalize($allCommonIdealPartsPercent);
        $represented = ExactDecimal::normalize($representedAtMeetingPercent);

        if (ExactDecimal::compare($allCommon, '0') < 0 || ExactDecimal::compare($represented, '0') < 0) {
            throw new InvalidArgumentException('Resolution denominators cannot be negative.');
        }

        $for = '0.00000000';
        $against = '0.00000000';
        $abstain = '0.00000000';
        $seen = [];

        foreach ($votes as $vote) {
            if ($vote->getAgendaItem() !== $item) {
                throw new InvalidArgumentException('Formal vote does not belong to the resolved agenda item.');
            }

            $entry = $vote->getElectorateEntry();
            $key = null !== $entry->getId() ? 'id:'.$entry->getId() : 'object:'.spl_object_id($entry);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $weight = $vote->getWeightIdealPartsPercent();
            match ($vote->getChoice()) {
                AssemblyVoteChoice::FOR => $for = ExactDecimal::add($for, $weight),
                AssemblyVoteChoice::AGAINST => $against = ExactDecimal::add($against, $weight),
                AssemblyVoteChoice::ABSTAIN => $abstain = ExactDecimal::add($abstain, $weight),
            };
        }

        $denominator = match ($rule->getDenominator()) {
            AssemblyVoteDenominator::ALL_COMMON_IDEAL_PARTS => $allCommon,
            AssemblyVoteDenominator::REPRESENTED_AT_MEETING => $represented,
            AssemblyVoteDenominator::ELIGIBLE_ABSENTEE_UNIVERSE => '0.00000000',
        };

        if ($rule->requiresLegalReview()
            || AssemblyVoteDenominator::ELIGIBLE_ABSENTEE_UNIVERSE === $rule->getDenominator()
            || 0 === ExactDecimal::compare($denominator, '0')) {
            return new AssemblyResolutionCalculation(
                $for,
                $against,
                $abstain,
                $denominator,
                '0',
                AssemblyResolutionResult::REVIEW_REQUIRED,
                'Решението изисква преглед, защото правното правило или приложимият знаменател не позволяват автоматично заключение.',
            );
        }

        $required = ExactDecimal::div(
            ExactDecimal::mul($denominator, $rule->getThresholdPercent()),
            '100',
        );
        $comparison = ExactDecimal::compare($for, $required);
        $accepted = match ($rule->getComparison()) {
            MajorityComparison::AT_LEAST => $comparison >= 0,
            MajorityComparison::GREATER_THAN => $comparison > 0,
        };
        $result = $accepted ? AssemblyResolutionResult::ACCEPTED : AssemblyResolutionResult::REJECTED;

        return new AssemblyResolutionCalculation(
            $for,
            $against,
            $abstain,
            $denominator,
            $required,
            $result,
            sprintf(
                'Гласове „за“: %s%%, „против“: %s%%, „въздържал се“: %s%%; изискван праг: %s%% от знаменател %s%%.',
                $for,
                $against,
                $abstain,
                $required,
                $denominator,
            ),
        );
    }
}
