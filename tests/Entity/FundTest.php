<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Fund;
use App\Enum\FundType;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class FundTest extends TestCase
{
    public function testFundKeepsStableIdentityAndCanBeDeactivated(): void
    {
        $fund = new Fund(' operating ', ' Текуща поддръжка ', FundType::OPERATING);

        self::assertSame('operating', $fund->getCode());
        self::assertSame('Текуща поддръжка', $fund->getName());
        self::assertSame(FundType::OPERATING, $fund->getType());
        self::assertTrue($fund->isActive());

        $fund->deactivate();

        self::assertFalse($fund->isActive());
    }

    public function testFundRejectsEmptyCode(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Fund('   ', 'Текуща поддръжка', FundType::OPERATING);
    }

    public function testFundRejectsEmptyName(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Fund('operating', '   ', FundType::OPERATING);
    }
}
