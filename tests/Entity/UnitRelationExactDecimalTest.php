<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Person;
use App\Entity\Unit;
use App\Entity\UnitRelation;
use App\Enum\UnitRelationType;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UnitRelationExactDecimalTest extends TestCase
{
    public function testAcceptsExactOwnershipShareBoundaries(): void
    {
        $minimum = $this->ownerRelation('0.0001');
        $maximum = $this->ownerRelation('100.0000');

        self::assertSame('0.0001', $minimum->getOwnershipShare());
        self::assertSame('100.0000', $maximum->getOwnershipShare());
    }

    /** @return iterable<string, array{string}> */
    public static function invalidShares(): iterable
    {
        yield 'zero' => ['0'];
        yield 'zero scaled' => ['0.0000'];
        yield 'over 100' => ['100.0001'];
        yield 'too many decimals' => ['1.00000'];
        yield 'scientific notation' => ['1e2'];
        yield 'leading plus' => ['+1.0000'];
        yield 'negative value' => ['-1.0000'];
        yield 'text' => ['abc'];
    }

    #[DataProvider('invalidShares')]
    public function testRejectsNonCanonicalOrOutOfRangeOwnershipShare(string $share): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Ownership share must be greater than 0 and at most 100.');

        $this->ownerRelation($share);
    }

    private function ownerRelation(string $share): UnitRelation
    {
        return new UnitRelation(
            new Person('Иван', 'Иванов'),
            new Unit('12'),
            UnitRelationType::OWNER,
            new DateTimeImmutable('2026-01-01'),
            $share,
        );
    }
}
