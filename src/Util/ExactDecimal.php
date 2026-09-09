<?php

declare(strict_types=1);

namespace App\Util;

use InvalidArgumentException;

final class ExactDecimal
{
    private const int MAX_SCALE = 18;

    /** @return numeric-string */
    public static function normalize(string $value, int $scale = 8): string
    {
        self::assertScale($scale);
        $value = trim($value);
        self::assertPlainDecimal($value);

        $fraction = str_contains($value, '.') ? substr($value, strpos($value, '.') + 1) : '';
        if (strlen($fraction) > $scale) {
            throw new InvalidArgumentException(sprintf('Value exceeds the allowed decimal scale of %d.', $scale));
        }

        $normalized = bcadd($value, '0', $scale);
        if (0 === bccomp($normalized, '0', $scale)) {
            return self::zero($scale);
        }

        return $normalized;
    }

    /** @return numeric-string */
    public static function add(string $left, string $right, int $scale = 8): string
    {
        $left = self::normalize($left, $scale);
        $right = self::normalize($right, $scale);

        return self::normalizeResult(bcadd($left, $right, $scale), $scale);
    }

    /** @return numeric-string */
    public static function sub(string $left, string $right, int $scale = 8): string
    {
        $left = self::normalize($left, $scale);
        $right = self::normalize($right, $scale);

        return self::normalizeResult(bcsub($left, $right, $scale), $scale);
    }

    /** @return numeric-string */
    public static function mul(string $left, string $right, int $scale = 8): string
    {
        $left = self::normalize($left, $scale);
        $right = self::normalize($right, $scale);

        return self::normalizeResult(bcmul($left, $right, $scale), $scale);
    }

    /** @return numeric-string */
    public static function div(string $left, string $right, int $scale = 8): string
    {
        $left = self::normalize($left, $scale);
        $right = self::normalize($right, $scale);
        if (0 === bccomp($right, '0', $scale)) {
            throw new InvalidArgumentException('Division by zero is not allowed.');
        }

        return self::normalizeResult(bcdiv($left, $right, $scale), $scale);
    }

    public static function compare(string $left, string $right, int $scale = 8): int
    {
        $left = self::normalize($left, $scale);
        $right = self::normalize($right, $scale);

        return bccomp($left, $right, $scale);
    }

    /** @return numeric-string */
    public static function percentOf(string $part, string $whole, int $scale = 8): string
    {
        self::assertScale($scale);
        $part = self::normalize($part, $scale);
        $whole = self::normalize($whole, $scale);
        self::assertNonNegative($part, $scale, 'Part');
        self::assertNonNegative($whole, $scale, 'Whole');

        if (0 === bccomp($whole, '0', $scale)) {
            throw new InvalidArgumentException('Percentage denominator must be greater than zero.');
        }

        $intermediateScale = min(self::MAX_SCALE, $scale + 4);
        $ratio = bcdiv($part, $whole, $intermediateScale);

        return self::normalizeResult(bcmul($ratio, '100', $scale), $scale);
    }

    /**
     * @param numeric-string $value
     *
     * @return numeric-string
     */
    private static function normalizeResult(string $value, int $scale): string
    {
        if (0 === bccomp($value, '0', $scale)) {
            return self::zero($scale);
        }

        return $value;
    }

    /** @param numeric-string $value */
    private static function assertNonNegative(string $value, int $scale, string $label): void
    {
        if (bccomp($value, '0', $scale) < 0) {
            throw new InvalidArgumentException(sprintf('%s must be non-negative.', $label));
        }
    }

    /** @phpstan-assert numeric-string $value */
    private static function assertPlainDecimal(string $value): void
    {
        if (!is_numeric($value) || 1 !== preg_match('/^-?(?:0|[1-9]\d*)(?:\.\d+)?$/', $value)) {
            throw new InvalidArgumentException('Value must be a plain decimal number.');
        }
    }

    private static function assertScale(int $scale): void
    {
        if ($scale < 0 || $scale > self::MAX_SCALE) {
            throw new InvalidArgumentException(sprintf('Decimal scale must be between 0 and %d.', self::MAX_SCALE));
        }
    }

    /** @return numeric-string */
    private static function zero(int $scale): string
    {
        return bcadd('0', '0', $scale);
    }
}
