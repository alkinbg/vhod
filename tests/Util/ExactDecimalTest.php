<?php

declare(strict_types=1);

namespace App\Tests\Util;

use App\Util\ExactDecimal;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ExactDecimalTest extends TestCase
{
    public function testNormalizesAndCalculatesWithoutFloatingPoint(): void
    {
        $this->assertImplemented();

        self::assertSame('51.00000000', ExactDecimal::normalize('51'));
        self::assertSame('4.00000000', ExactDecimal::mul('8.00000000', '0.50000000'));
        self::assertSame('10.75000000', ExactDecimal::add('8.2500', '2.5'));
        self::assertSame('5.75000000', ExactDecimal::sub('8.2500', '2.5'));
        self::assertSame('4.12500000', ExactDecimal::div('8.2500', '2'));
        self::assertSame('25.00000000', ExactDecimal::percentOf('2', '8'));
    }

    public function testComparisonUsesExactScale(): void
    {
        $this->assertImplemented();

        self::assertSame(0, ExactDecimal::compare('51', '51.00000000'));
        self::assertSame(-1, ExactDecimal::compare('50.99999999', '51.00000000'));
        self::assertSame(1, ExactDecimal::compare('51.00000001', '51.00000000'));
    }

    public function testRejectsScientificNotationAndTooManyFractionDigits(): void
    {
        $this->assertImplemented();

        foreach (['1e2', '1E2', '1.234567891'] as $value) {
            try {
                ExactDecimal::normalize($value);
                self::fail(sprintf('Malformed or over-precise decimal "%s" must be rejected.', $value));
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testPercentOfRejectsNegativeInputAndZeroDenominator(): void
    {
        $this->assertImplemented();

        foreach ([['-1', '8'], ['1', '-8'], ['1', '0']] as [$part, $whole]) {
            try {
                ExactDecimal::percentOf($part, $whole);
                self::fail(sprintf('percentOf(%s, %s) must be rejected.', $part, $whole));
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testDivisionByZeroIsRejected(): void
    {
        $this->assertImplemented();

        $this->expectException(InvalidArgumentException::class);
        ExactDecimal::div('1', '0');
    }

    private function assertImplemented(): void
    {
        self::assertTrue(class_exists(ExactDecimal::class), 'ExactDecimal has not been implemented yet.');
    }
}
