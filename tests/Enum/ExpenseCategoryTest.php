<?php

declare(strict_types=1);

namespace App\Tests\Enum;

use App\Enum\ExpenseCategory;
use App\Enum\ExpenseReportSection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ExpenseCategoryTest extends TestCase
{
    public function testRepresentativeCategoriesMapToEveryOfficialExpenseSection(): void
    {
        self::assertSame(ExpenseReportSection::MANAGEMENT, ExpenseCategory::MANAGER_COMPENSATION->section());
        self::assertSame(ExpenseReportSection::MAINTENANCE, ExpenseCategory::COMMON_ELECTRICITY->section());
        self::assertSame(ExpenseReportSection::REPAIRS, ExpenseCategory::URGENT_REPAIR->section());
        self::assertSame(ExpenseReportSection::SERVICES, ExpenseCategory::BANK_FEES->section());
        self::assertSame(ExpenseReportSection::OTHER, ExpenseCategory::OTHER->section());
    }

    #[DataProvider('categoryProvider')]
    public function testEveryCategoryHasBulgarianLabel(ExpenseCategory $category): void
    {
        self::assertNotSame('', trim($category->labelBg()));
    }

    /** @return iterable<string, array{ExpenseCategory}> */
    public static function categoryProvider(): iterable
    {
        foreach (ExpenseCategory::cases() as $category) {
            yield $category->name => [$category];
        }
    }
}
