<?php

declare(strict_types=1);

namespace App\Tests\Value;

use App\Value\EuroAmount;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EuroAmountTest extends TestCase
{
    #[DataProvider('validAmounts')]
    public function testParseUsesIntegerCentsWithoutFloat(string $input, int $expected): void
    {
        self::assertSame($expected, EuroAmount::parse($input));
    }

    /** @return iterable<string, array{string, int}> */
    public static function validAmounts(): iterable
    {
        yield 'integer' => ['123', 12300];
        yield 'one decimal' => ['123.4', 12340];
        yield 'two decimals' => ['123.45', 12345];
        yield 'comma decimal' => ['123,45', 12345];
        yield 'trimmed' => ['  0,01  ', 1];
    }

    #[DataProvider('invalidAmounts')]
    public function testParseRejectsInvalidAmounts(string $input): void
    {
        $this->expectException(InvalidArgumentException::class);
        EuroAmount::parse($input);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidAmounts(): iterable
    {
        yield 'blank' => ['   '];
        yield 'zero' => ['0'];
        yield 'negative' => ['-1.00'];
        yield 'too precise' => ['1.234'];
        yield 'letters' => ['12 EUR'];
        yield 'thousands separators' => ['1,234.56'];
    }

    public function testFormatIsStableDecimalEur(): void
    {
        self::assertSame('123.45', EuroAmount::format(12345));
        self::assertSame('-0.01', EuroAmount::format(-1));
        self::assertSame('0.00', EuroAmount::format(0));
    }
}
