<?php

declare(strict_types=1);

namespace App\Value;

use App\Enum\AssemblyLegalResult;
use App\Util\ExactDecimal;
use InvalidArgumentException;

final readonly class AssemblyQuorumCalculation
{
    public string $representedIdealPartsPercent;
    public string $requiredIdealPartsPercent;
    public string $ruleCode;
    public string $explanation;

    public function __construct(
        string $representedIdealPartsPercent,
        string $requiredIdealPartsPercent,
        string $ruleCode,
        public AssemblyLegalResult $result,
        string $explanation,
    ) {
        $ruleCode = trim($ruleCode);
        $explanation = trim($explanation);
        if ('' === $ruleCode || mb_strlen($ruleCode) > 120) {
            throw new InvalidArgumentException('Quorum calculation rule code must contain between 1 and 120 characters.');
        }
        if ('' === $explanation) {
            throw new InvalidArgumentException('Quorum calculation explanation is required.');
        }

        $this->representedIdealPartsPercent = ExactDecimal::normalize($representedIdealPartsPercent);
        $this->requiredIdealPartsPercent = ExactDecimal::normalize($requiredIdealPartsPercent);
        $this->ruleCode = $ruleCode;
        $this->explanation = $explanation;
    }
}
