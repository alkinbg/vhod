<?php

declare(strict_types=1);

namespace App\Tests\Value;

use App\Value\BankTransactionFingerprint;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class BankTransactionFingerprintTest extends TestCase
{
    public function testAccountServicerReferenceProvidesStableIdentityAcrossChangingOptionalMetadata(): void
    {
        $first = BankTransactionFingerprint::fromFields(
            'BG79TEST00000100000000',
            12550,
            new DateTimeImmutable('2026-09-08'),
            new DateTimeImmutable('2026-09-09'),
            'TX-OLD',
            ' ASR-STABLE-1 ',
            'E2E-OLD',
            'BG88FAKE00000200000001',
            'Такса ап. 12',
        );
        $second = BankTransactionFingerprint::fromFields(
            ' bg79 test 0000 0100 0000 00 ',
            12550,
            new DateTimeImmutable('2026-09-08 19:30:00 UTC'),
            null,
            'TX-NEW',
            'asr-stable-1',
            'E2E-NEW',
            null,
            'Променено описание от банката',
        );

        self::assertSame($first, $second);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $first);
    }

    public function testFallbackIgnoresUnstableOptionalReferencesWhenAccountServicerReferenceIsMissing(): void
    {
        $first = BankTransactionFingerprint::fromFields(
            'BG79TEST00000100000000',
            12550,
            new DateTimeImmutable('2026-09-08'),
            new DateTimeImmutable('2026-09-09'),
            'TX-OLD',
            null,
            'E2E-OLD',
            ' bg88 fake 0000 0200 0000 01 ',
            '  Такса   ап. 12  ',
        );
        $second = BankTransactionFingerprint::fromFields(
            'BG79TEST00000100000000',
            12550,
            new DateTimeImmutable('2026-09-08 21:00:00 UTC'),
            null,
            'TX-NEW',
            null,
            'E2E-NEW',
            'BG88FAKE00000200000001',
            'Такса ап. 12',
        );

        self::assertSame($first, $second);
    }

    public function testMaterialFallbackDifferenceChangesFingerprint(): void
    {
        $first = BankTransactionFingerprint::fromFields(
            'BG79TEST00000100000000',
            12550,
            new DateTimeImmutable('2026-09-08'),
            null,
            null,
            null,
            null,
            'BG88FAKE00000200000001',
            'Такса ап. 12',
        );
        $second = BankTransactionFingerprint::fromFields(
            'BG79TEST00000100000000',
            12551,
            new DateTimeImmutable('2026-09-08'),
            null,
            null,
            null,
            null,
            'BG88FAKE00000200000001',
            'Такса ап. 12',
        );

        self::assertNotSame($first, $second);
    }
}
