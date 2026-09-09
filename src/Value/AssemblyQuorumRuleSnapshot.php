<?php

declare(strict_types=1);

namespace App\Value;

use App\Util\ExactDecimal;
use InvalidArgumentException;

final readonly class AssemblyQuorumRuleSnapshot
{
    public string $code;
    public string $firstCallRequiredPercent;
    public string $delayedCallRequiredPercent;
    public string $dominantOwnerTriggerPercent;
    public string $dominantOwnerRequiredPercent;
    public string $legalBasis;
    public string $sourceVersion;

    public function __construct(
        string $code,
        string $firstCallRequiredPercent,
        string $delayedCallRequiredPercent,
        string $dominantOwnerTriggerPercent,
        string $dominantOwnerRequiredPercent,
        string $legalBasis,
        string $sourceVersion,
        public bool $requiresLegalReview,
    ) {
        $code = trim($code);
        $legalBasis = trim($legalBasis);
        $sourceVersion = trim($sourceVersion);

        if ('' === $code || mb_strlen($code) > 80) {
            throw new InvalidArgumentException('Quorum rule code must contain between 1 and 80 characters.');
        }
        if ('' === $legalBasis) {
            throw new InvalidArgumentException('Quorum rule legal basis is required.');
        }
        if ('' === $sourceVersion || mb_strlen($sourceVersion) > 120) {
            throw new InvalidArgumentException('Quorum rule source version must contain between 1 and 120 characters.');
        }

        $this->code = $code;
        $this->firstCallRequiredPercent = self::percent($firstCallRequiredPercent, 'First-call quorum');
        $this->delayedCallRequiredPercent = self::percent($delayedCallRequiredPercent, 'Delayed-call quorum');
        $this->dominantOwnerTriggerPercent = self::percent($dominantOwnerTriggerPercent, 'Dominant-owner trigger');
        $this->dominantOwnerRequiredPercent = self::percent($dominantOwnerRequiredPercent, 'Dominant-owner quorum');
        $this->legalBasis = $legalBasis;
        $this->sourceVersion = $sourceVersion;
    }

    private static function percent(string $value, string $label): string
    {
        $value = ExactDecimal::normalize($value);
        if (ExactDecimal::compare($value, '0') < 0 || ExactDecimal::compare($value, '100') > 0) {
            throw new InvalidArgumentException(sprintf('%s must be between 0 and 100 percent.', $label));
        }

        return $value;
    }
}
