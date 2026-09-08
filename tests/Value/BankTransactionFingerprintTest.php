<?php

declare(strict_types=1);

namespace App\Tests\Value;

use App\Value\BankTransactionFingerprint;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class BankTransactionFingerprintTest extends TestCase
{
    public function testFingerprintIsDeterministicAcrossSemanticWhitespaceAndIbanCase(): void
    {
        $first = BankTransactionFingerprint::fromFields(
            'BG80BNBG96611020345678',
            12550,
            new DateTimeImmutable('2026-09-08'),
            new DateTimeImmutable('2026-09-09'),
            ' TX-1 ',
            ' ENTRY-1 ',
            ' E2E-1 ',
            ' bg40 bnbg 9661 1000 0661 23 ',
            '  Такса   ап. 12  ',
        );
        $second = BankTransactionFingerprint::fromFields(
            ' bg80 bnbg 9661 1020 3456 78 ',
            12550,
            new DateTimeImmutable('2026-09-08 19:30:00 UTC'),
            new DateTimeImmutable('2026-09-09 01:00:00 UTC'),
            'TX-1',
            'ENTRY-1',
            'E2E-1',
            'BG40BNBG96611000066123',
            'Такса ап. 12',
        );

        self::assertSame($first, $second);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $first);
    }

    public function testMaterialDifferenceChangesFingerprint(): void
    {
        $first = BankTransactionFingerprint::fromFields(
            'BG80BNBG96611020345678',
            12550,
            new DateTimeImmutable('2026-09-08'),
            null,
            null,
            'ENTRY-1',
            null,
            null,
            'Такса ап. 12',
        );
        $second = BankTransactionFingerprint::fromFields(
            'BG80BNBG96611020345678',
            12551,
            new DateTimeImmutable('2026-09-08'),
            null,
            null,
            'ENTRY-1',
            null,
            null,
            'Такса ап. 12',
        );

        self::assertNotSame($first, $second);
    }
}
