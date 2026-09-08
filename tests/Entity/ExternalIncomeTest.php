<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\ExternalIncome;
use App\Entity\Fund;
use App\Enum\ExternalIncomeCategory;
use App\Enum\FundType;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ExternalIncomeTest extends TestCase
{
    public function testExternalIncomeKeepsNormalizedSnapshot(): void
    {
        $fund = new Fund('operating', 'Управление', FundType::OPERATING);
        $income = ExternalIncome::record(
            $fund,
            ExternalIncomeCategory::COMMON_PART_RENT,
            12500,
            new DateTimeImmutable('2026-09-02 08:00:00 Europe/Sofia'),
            new DateTimeImmutable('2026-09-02 09:00:00 Europe/Sofia'),
            '  Наем обща част  ',
            '  RENT-09-2026  ',
            '   ',
        );

        self::assertSame($fund, $income->getFund());
        self::assertSame(ExternalIncomeCategory::COMMON_PART_RENT, $income->getCategory());
        self::assertSame(12500, $income->getAmountCents());
        self::assertSame('2026-09-02 05:00:00', $income->getReceivedAt()->format('Y-m-d H:i:s'));
        self::assertSame('UTC', $income->getReceivedAt()->getTimezone()->getName());
        self::assertSame('Наем обща част', $income->getDescription());
        self::assertSame('RENT-09-2026', $income->getDocumentReference());
        self::assertNull($income->getNote());
    }

    public function testAmountMustBePositive(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->record(amountCents: 0);
    }

    public function testDescriptionIsRequired(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->record(description: '   ');
    }

    public function testIncomeCannotBePostedBeforeReceipt(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ExternalIncome::record(
            new Fund('operating', 'Управление', FundType::OPERATING),
            ExternalIncomeCategory::OTHER,
            100,
            new DateTimeImmutable('2026-09-02 10:00:00 UTC'),
            new DateTimeImmutable('2026-09-02 09:59:59 UTC'),
            'Приход',
        );
    }

    private function record(int $amountCents = 100, string $description = 'Приход'): ExternalIncome
    {
        return ExternalIncome::record(
            new Fund('operating', 'Управление', FundType::OPERATING),
            ExternalIncomeCategory::OTHER,
            $amountCents,
            new DateTimeImmutable('2026-09-02 09:00:00 UTC'),
            new DateTimeImmutable('2026-09-02 10:00:00 UTC'),
            $description,
        );
    }
}
