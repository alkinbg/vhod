<?php

declare(strict_types=1);

namespace App\Value;

use App\Enum\AssemblyResolutionResult;
use App\Util\ExactDecimal;

final readonly class AssemblyResolutionCalculation
{
    public string $forIdealPartsPercent;
    public string $againstIdealPartsPercent;
    public string $abstainIdealPartsPercent;
    public string $denominatorIdealPartsPercent;
    public string $requiredIdealPartsPercent;

    public function __construct(
        string $forIdealPartsPercent,
        string $againstIdealPartsPercent,
        string $abstainIdealPartsPercent,
        string $denominatorIdealPartsPercent,
        string $requiredIdealPartsPercent,
        public AssemblyResolutionResult $result,
        public string $explanation,
    ) {
        $this->forIdealPartsPercent = ExactDecimal::normalize($forIdealPartsPercent);
        $this->againstIdealPartsPercent = ExactDecimal::normalize($againstIdealPartsPercent);
        $this->abstainIdealPartsPercent = ExactDecimal::normalize($abstainIdealPartsPercent);
        $this->denominatorIdealPartsPercent = ExactDecimal::normalize($denominatorIdealPartsPercent);
        $this->requiredIdealPartsPercent = ExactDecimal::normalize($requiredIdealPartsPercent);
    }
}
