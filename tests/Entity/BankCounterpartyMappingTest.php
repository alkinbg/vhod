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
    public function testMappingNormalizesIbanAndAuditTimestamp(): void
    {
        $unit = new Unit('12');
        $mapping = BankCounterpartyMapping::create(
            ' bg40 bnbg 9661 1000 0661 23 ',
            $unit,
            new DateTimeImmutable('2026-09-08 08:15:00 Europe/Sofia'),
        );

        self::assertSame('BG40BNBG96611000066123', $mapping->getCounterpartyIban());
        self::assertSame($unit, $mapping->getUnit());
        self::assertTrue($mapping->isActive());
        self::assertSame('2026-09-08 05:15:00', $mapping->getCreatedAt()->format('Y-m-d H:i:s'));
        self::assertSame('UTC', $mapping->getCreatedAt()->getTimezone()->getName());
    }

    public function testMappingCanBeDeactivatedWithoutDestroyingHistory(): void
    {
        $mapping = BankCounterpartyMapping::create(
            'BG40BNBG96611000066123',
            new Unit('12'),
            new DateTimeImmutable('2026-09-08 05:15:00 UTC'),
        );

        $mapping->deactivate();

        self::assertFalse($mapping->isActive());
        self::assertSame('BG40BNBG96611000066123', $mapping->getCounterpartyIban());
    }

    public function testInvalidIbanIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        BankCounterpartyMapping::create(
            'BG81BNBG96611020345678',
            new Unit('12'),
            new DateTimeImmutable('2026-09-08 05:15:00 UTC'),
        );
    }
}
