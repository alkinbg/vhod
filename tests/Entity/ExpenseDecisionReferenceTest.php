<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Expense;
use App\Entity\Fund;
use App\Enum\ExpenseCategory;
use App\Enum\FundType;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class ExpenseDecisionReferenceTest extends TestCase
{
    public function testOptionalDecisionReferenceIsNormalized(): void
    {
        $expense = Expense::post(
            new Fund('operating', 'Управление', FundType::OPERATING),
            ExpenseCategory::URGENT_REPAIR,
            5000,
            new DateTimeImmutable('2026-09-08 08:00:00 UTC'),
            new DateTimeImmutable('2026-09-08 09:00:00 UTC'),
            'Аварийна поправка',
            decisionReference: '  Протокол 03/2026  ',
        );

        self::assertSame('Протокол 03/2026', $expense->getDecisionReference());
    }

    public function testBlankDecisionReferenceBecomesNull(): void
    {
        $expense = Expense::post(
            new Fund('operating', 'Управление', FundType::OPERATING),
            ExpenseCategory::OTHER,
            100,
            new DateTimeImmutable('2026-09-08 08:00:00 UTC'),
            new DateTimeImmutable('2026-09-08 09:00:00 UTC'),
            'Разход',
            decisionReference: '   ',
        );

        self::assertNull($expense->getDecisionReference());
    }
}
