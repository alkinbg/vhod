<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Unit;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UnitExactDecimalTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function invalidIdealParts(): iterable
    {
        yield 'scientific notation' => ['1e2'];
        yield 'too many decimals' => ['1.00000'];
        yield 'above maximum' => ['100.0001'];
        yield 'negative' => ['-0.0001'];
    }

    #[DataProvider('invalidIdealParts')]
    public function testIdealPartsRejectNonCanonicalDecimals(string $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Unit('12', idealParts: $value);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidBuiltAreas(): iterable
    {
        yield 'scientific notation' => ['1e2'];
        yield 'too many decimals' => ['10.001'];
        yield 'negative' => ['-1.00'];
        yield 'exceeds database precision' => ['100000000.00'];
    }

    #[DataProvider('invalidBuiltAreas')]
    public function testBuiltAreaRejectsNonCanonicalDecimals(string $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Unit('12', builtArea: $value);
    }
}
