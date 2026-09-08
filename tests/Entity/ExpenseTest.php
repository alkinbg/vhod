<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Expense;
use App\Entity\Fund;
use App\Enum\ExpenseCategory;
use App\Enum\FundType;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ExpenseTest extends TestCase
{
    public function testExpenseKeepsNormalizedImmutableSnapshot(): void
    {
        $fund = new Fund('operating', 'Управление и поддръжка', FundType::OPERATING);
        $expense = Expense::post(
            $fund,
            ExpenseCategory::COMMON_ELECTRICITY,
            4280,
            new DateTimeImmutable('2026-09-05 08:15:00', new DateTimeZone('Europe/Sofia')),
            new DateTimeImmutable('2026-09-05 12:00:00', new DateTimeZone('Europe/Sofia')),
            '  Електроенергия общи части  ',
            '  Тестов доставчик  ',
            '  INV-TEST-001  ',
            '   ',
        );

        self::assertSame($fund, $expense->getFund());
        self::assertSame(ExpenseCategory::COMMON_ELECTRICITY, $expense->getCategory());
        self::assertSame(4280, $expense->getAmountCents());
        self::assertSame('2026-09-05 05:15:00', $expense->getPaidAt()->format('Y-m-d H:i:s'));
        self::assertSame('UTC', $expense->getPaidAt()->getTimezone()->getName());
        self::assertSame('2026-09-05 09:00:00', $expense->getPostedAt()->format('Y-m-d H:i:s'));
        self::assertSame('UTC', $expense->getPostedAt()->getTimezone()->getName());
        self::assertSame('Електроенергия общи части', $expense->getDescription());
        self::assertSame('Тестов доставчик', $expense->getPayee());
        self::assertSame('INV-TEST-001', $expense->getDocumentReference());
        self::assertNull($expense->getNote());
    }

    public function testAmountMustBePositive(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Expense amount must be positive.');

        $this->post(amountCents: 0);
    }

    public function testDescriptionIsRequired(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Expense description is required.');

        $this->post(description: '   ');
    }

    public function testExpenseCannotBePostedBeforeItWasPaid(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Expense cannot be posted before it was paid.');

        Expense::post(
            new Fund('operating', 'Управление', FundType::OPERATING),
            ExpenseCategory::OTHER,
            100,
            new DateTimeImmutable('2026-09-05 10:00:00 UTC'),
            new DateTimeImmutable('2026-09-05 09:59:59 UTC'),
            'Разход',
        );
    }

    private function post(int $amountCents = 100, string $description = 'Разход'): Expense
    {
        return Expense::post(
            new Fund('operating', 'Управление', FundType::OPERATING),
            ExpenseCategory::OTHER,
            $amountCents,
            new DateTimeImmutable('2026-09-05 09:00:00 UTC'),
            new DateTimeImmutable('2026-09-05 10:00:00 UTC'),
            $description,
        );
    }
}
