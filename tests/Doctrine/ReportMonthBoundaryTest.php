<?php

declare(strict_types=1);

namespace App\Tests\Doctrine;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

final class ReportMonthBoundaryTest extends TestCase
{
    public function testSofiaMonthStartCanBelongToPreviousUtcCalendarDay(): void
    {
        $sofia = new DateTimeZone('Europe/Sofia');
        $utc = new DateTimeZone('UTC');
        $start = new DateTimeImmutable('2026-09-01 00:00:00', $sofia);
        $end = $start->modify('first day of next month');

        self::assertSame('2026-08-31 21:00:00', $start->setTimezone($utc)->format('Y-m-d H:i:s'));
        self::assertSame('2026-09-30 21:00:00', $end->setTimezone($utc)->format('Y-m-d H:i:s'));
    }
}
