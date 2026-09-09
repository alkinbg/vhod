<?php

declare(strict_types=1);

namespace App\Value;

use App\Enum\AssemblyVoteDenominator;
use App\Enum\MajorityComparison;
use InvalidArgumentException;

final readonly class AssemblyMajorityRuleSnapshot
{
    private string $ruleCode;
    private AssemblyVoteDenominator $denominator;
    private string $thresholdPercent;
    private MajorityComparison $comparison;
    private string $legalBasis;
    private string $sourceVersion;
    private bool $requiresLegalReview;

    public function __construct(
        string $ruleCode,
        AssemblyVoteDenominator $denominator,
        string $thresholdPercent,
        MajorityComparison $comparison,
        string $legalBasis,
        string $sourceVersion,
        bool $requiresLegalReview = false,
    ) {
        $ruleCode = trim($ruleCode);
        $legalBasis = trim($legalBasis);
        $sourceVersion = trim($sourceVersion);

        if ('' === $ruleCode || mb_strlen($ruleCode) > 80) {
            throw new InvalidArgumentException('Majority rule code must contain between 1 and 80 characters.');
        }
        if ('' === $legalBasis) {
            throw new InvalidArgumentException('Majority rule legal basis cannot be blank.');
        }
        if ('' === $sourceVersion || mb_strlen($sourceVersion) > 120) {
            throw new InvalidArgumentException('Majority rule source version must contain between 1 and 120 characters.');
        }

        $this->ruleCode = $ruleCode;
        $this->denominator = $denominator;
        $this->thresholdPercent = self::normalizePercent($thresholdPercent);
        $this->comparison = $comparison;
        $this->legalBasis = $legalBasis;
        $this->sourceVersion = $sourceVersion;
        $this->requiresLegalReview = $requiresLegalReview;
    }

    public function getRuleCode(): string { return $this->ruleCode; }
    public function getDenominator(): AssemblyVoteDenominator { return $this->denominator; }
    public function getThresholdPercent(): string { return $this->thresholdPercent; }
    public function getComparison(): MajorityComparison { return $this->comparison; }
    public function getLegalBasis(): string { return $this->legalBasis; }
    public function getSourceVersion(): string { return $this->sourceVersion; }
    public function requiresLegalReview(): bool { return $this->requiresLegalReview; }

    private static function normalizePercent(string $value): string
    {
        $value = trim($value);
        if (1 !== preg_match('/^(?:0|[1-9]\d{0,2})(?:\.\d{1,8})?$/', $value)) {
            throw new InvalidArgumentException('Majority threshold must be a decimal percentage with at most 8 decimal places.');
        }

        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $wholeInt = (int) $whole;
        if ($wholeInt > 100 || (100 === $wholeInt && '' !== trim($fraction, '0'))) {
            throw new InvalidArgumentException('Majority threshold must be between 0 and 100 percent.');
        }

        return $whole.'.'.str_pad($fraction, 8, '0');
    }
}
