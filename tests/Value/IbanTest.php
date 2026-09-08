<?php

declare(strict_types=1);

namespace App\Tests\Value;

use App\Value\Iban;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class IbanTest extends TestCase
{
    public function testNormalizeRemovesWhitespaceAndUppercasesValidIban(): void
    {
        self::assertSame('BG80BNBG96611020345678', Iban::normalize(' bg80 bnbg 9661 1020 3456 78 '));
    }

    #[DataProvider('invalidIbans')]
    public function testInvalidIbanIsRejected(string $iban): void
    {
        $this->expectException(InvalidArgumentException::class);

        Iban::normalize($iban);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidIbans(): iterable
    {
        yield 'blank' => ['   '];
        yield 'bad characters' => ['BG80-BNBG-96611020345678'];
        yield 'bad checksum' => ['BG81BNBG96611020345678'];
        yield 'too short' => ['BG80BNBG'];
    }
}
