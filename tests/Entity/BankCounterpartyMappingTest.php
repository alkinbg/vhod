<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\BankCounterpartyMapping;
use App\Entity\Unit;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class BankCounterpartyMappingTest extends TestCase
{
    private const PAYER_IBAN = 'BG97FAKE00000000000001';

    public function testMappingNormalizesIbanAndAuditTimestamp(): void
    {
        $unit = new Unit('12');
        $mapping = BankCounterpartyMapping::create(
            ' bg97 fake 0000 0000 0000 01 ',
            $unit,
            new DateTimeImmutable('2026-09-08 08:15:00 Europe/Sofia'),
        );

        self::assertSame(self::PAYER_IBAN, $mapping->getCounterpartyIban());
        self::assertSame($unit, $mapping->getUnit());
        self::assertTrue($mapping->isActive());
        self::assertSame('2026-09-08 05:15:00', $mapping->getCreatedAt()->format('Y-m-d H:i:s'));
        self::assertSame('UTC', $mapping->getCreatedAt()->getTimezone()->getName());
    }

    public function testMappingCanBeDeactivatedWithoutDestroyingHistory(): void
    {
        $mapping = BankCounterpartyMapping::create(
            self::PAYER_IBAN,
            new Unit('12'),
            new DateTimeImmutable('2026-09-08 05:15:00 UTC'),
        );

        $mapping->deactivate();

        self::assertFalse($mapping->isActive());
        self::assertSame(self::PAYER_IBAN, $mapping->getCounterpartyIban());
    }

    public function testInvalidIbanIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        BankCounterpartyMapping::create(
            'BG98FAKE00000000000001',
            new Unit('12'),
            new DateTimeImmutable('2026-09-08 05:15:00 UTC'),
        );
    }
}
