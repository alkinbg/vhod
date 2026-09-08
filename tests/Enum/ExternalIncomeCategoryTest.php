<?php

declare(strict_types=1);

namespace App\Tests\Enum;

use App\Enum\ExternalIncomeCategory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ExternalIncomeCategoryTest extends TestCase
{
    #[DataProvider('categoryProvider')]
    public function testEveryCategoryHasBulgarianLabel(ExternalIncomeCategory $category): void
    {
        self::assertNotSame('', trim($category->labelBg()));
    }

    /** @return iterable<string, array{ExternalIncomeCategory}> */
    public static function categoryProvider(): iterable
    {
        foreach (ExternalIncomeCategory::cases() as $category) {
            yield $category->name => [$category];
        }
    }
}
