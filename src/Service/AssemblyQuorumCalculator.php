<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AssemblyElectorateEntry;
use App\Enum\AssemblyLegalResult;
use App\Enum\AssemblyPrincipalType;
use App\Enum\AssemblyQuorumCheckKind;
use App\Util\ExactDecimal;
use App\Value\AssemblyQuorumCalculation;
use App\Value\AssemblyQuorumRuleSnapshot;
use InvalidArgumentException;

final class AssemblyQuorumCalculator
{
    /**
     * @param list<AssemblyElectorateEntry> $electorate
     * @param list<AssemblyElectorateEntry> $represented
     */
    public function calculate(
        AssemblyQuorumRuleSnapshot $rule,
        array $electorate,
        array $represented,
        AssemblyQuorumCheckKind $kind,
    ): AssemblyQuorumCalculation {
        $electorateByKey = [];
        $principalWeights = [];
        $reviewRequired = $rule->requiresLegalReview;

        foreach ($electorate as $entry) {
            $entryKey = $this->entryKey($entry);
            if (isset($electorateByKey[$entryKey])) {
                continue;
            }
            $electorateByKey[$entryKey] = $entry;

            if (!$entry->isQuorumEligible() || null !== $entry->getReviewReason()) {
                $reviewRequired = true;
                continue;
            }

            $weight = $entry->getRepresentedIdealPartsPercentSnapshot();
            if (null === $weight) {
                $reviewRequired = true;
                continue;
            }

            $principalKey = $this->principalKey($entry);
            $principalWeights[$principalKey] = ExactDecimal::add($principalWeights[$principalKey] ?? '0', $weight);
        }

        $dominantOwner = false;
        foreach ($principalWeights as $weight) {
            if (ExactDecimal::compare($weight, $rule->dominantOwnerTriggerPercent) > 0) {
                $dominantOwner = true;
                break;
            }
        }

        $required = $dominantOwner
            ? $rule->dominantOwnerRequiredPercent
            : match ($kind) {
                AssemblyQuorumCheckKind::FIRST_CALL => $rule->firstCallRequiredPercent,
                AssemblyQuorumCheckKind::DELAYED_CALL => $rule->delayedCallRequiredPercent,
                AssemblyQuorumCheckKind::NEXT_DAY_CALL => '0.00000000',
            };

        $representedTotal = '0.00000000';
        $seenRepresented = [];
        foreach ($represented as $entry) {
            $entryKey = $this->entryKey($entry);
            if (!isset($electorateByKey[$entryKey])) {
                throw new InvalidArgumentException('Represented electorate entry is not part of the meeting electorate snapshot.');
            }
            if (isset($seenRepresented[$entryKey])) {
                continue;
            }
            $seenRepresented[$entryKey] = true;

            if (!$entry->isQuorumEligible()) {
                $reviewRequired = true;
                continue;
            }
            $weight = $entry->getRepresentedIdealPartsPercentSnapshot();
            if (null === $weight) {
                $reviewRequired = true;
                continue;
            }

            $representedTotal = ExactDecimal::add($representedTotal, $weight);
        }

        if ($reviewRequired) {
            return new AssemblyQuorumCalculation(
                $representedTotal,
                $required,
                $rule->code.':review-required',
                AssemblyLegalResult::REVIEW_REQUIRED,
                'Кворумът изисква ръчен правен/данъчен преглед поради непълни или маркирани за преглед данни.',
            );
        }

        $result = ExactDecimal::compare($representedTotal, $required) >= 0
            ? AssemblyLegalResult::VALID
            : AssemblyLegalResult::INVALID;

        $ruleSuffix = $dominantOwner
            ? 'dominant-owner'
            : match ($kind) {
                AssemblyQuorumCheckKind::FIRST_CALL => 'first-call',
                AssemblyQuorumCheckKind::DELAYED_CALL => 'delayed-call',
                AssemblyQuorumCheckKind::NEXT_DAY_CALL => 'next-day-no-minimum',
            };

        return new AssemblyQuorumCalculation(
            $representedTotal,
            $required,
            $rule->code.':'.$ruleSuffix,
            $result,
            sprintf(
                'Представени са %s%% идеални части при изискване %s%% по правило %s.',
                $representedTotal,
                $required,
                $rule->code,
            ),
        );
    }

    private function entryKey(AssemblyElectorateEntry $entry): string
    {
        $id = $entry->getId();

        return null !== $id ? 'id:'.$id : 'object:'.spl_object_id($entry);
    }

    private function principalKey(AssemblyElectorateEntry $entry): string
    {
        if (AssemblyPrincipalType::PERSON === $entry->getPrincipalType()) {
            $person = $entry->getPerson();
            if (null === $person) {
                return 'person-snapshot:'.$entry->getPrincipalNameSnapshot();
            }

            return null !== $person->getId()
                ? 'person-id:'.$person->getId()
                : 'person-object:'.spl_object_id($person);
        }

        $identifier = $entry->getPrincipalIdentifierSnapshot();
        if (null !== $identifier) {
            return 'legal-id:'.$identifier;
        }

        return 'legal-name:'.$entry->getPrincipalNameSnapshot();
    }
}
