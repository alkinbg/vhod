<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Unit;
use App\Enum\UnitType;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class UnitTest extends TestCase
{
    public function testCreatesActiveApartmentWithLegalMetadata(): void
    {
        $unit = new Unit('12', UnitType::APARTMENT, 4, '78.40', '3.1250');
        self::assertSame('12', $unit->getDesignation());
        self::assertSame(UnitType::APARTMENT, $unit->getType());
        self::assertSame(4, $unit->getFloor());
        self::assertSame('78.40', $unit->getBuiltArea());
        self::assertSame('3.1250', $unit->getIdealParts());
        self::assertTrue($unit->isActive());
    }

    public function testRejectsBlankDesignation(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Unit('   ');
    }

    public function testRejectsIdealPartsOverOneHundredPercent(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Unit('12', idealParts: '100.0001');
    }
}
