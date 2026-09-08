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
        self::assertSame('BG35TEST00000000000000', Iban::normalize(' bg35 test 0000 0000 0000 00 '));
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
        yield 'bad characters' => ['BG35-TEST-00000000000000'];
        yield 'bad checksum' => ['BG36TEST00000000000000'];
        yield 'too short' => ['BG35TEST'];
    }
}
