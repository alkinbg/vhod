<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\BuildingAsset;
use App\Enum\BuildingAssetCategory;
use App\Enum\MaintenanceReminderType;
use App\Service\MaintenanceReminderService;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class MaintenanceReminderServiceTest extends TestCase
{
    public function testClassifiesWarrantyAndInspectionBoundaries(): void
    {
        $today = new DateTimeImmutable('2026-09-08');
        $expiredWarranty = BuildingAsset::register('Домофон', BuildingAssetCategory::ACCESS_SYSTEM, 'Вход', warrantyUntil: $today->modify('-2 days'));
        $dueWarranty = BuildingAsset::register('Контролер', BuildingAssetCategory::ACCESS_SYSTEM, 'Вход', warrantyUntil: $today->modify('+60 days'));
        $overdueInspection = BuildingAsset::register('Асансьор', BuildingAssetCategory::ELEVATOR, 'Вход', nextInspectionAt: $today->modify('-1 day'));
        $dueInspection = BuildingAsset::register('Пожарогасители', BuildingAssetCategory::FIRE_SAFETY, 'Стълбище', nextInspectionAt: $today->modify('+30 days'));
        $farFuture = BuildingAsset::register('Помпа', BuildingAssetCategory::PLUMBING, 'Мазе', warrantyUntil: $today->modify('+61 days'), nextInspectionAt: $today->modify('+31 days'));
        $inactive = BuildingAsset::register('Стар домофон', BuildingAssetCategory::ACCESS_SYSTEM, 'Вход', warrantyUntil: $today->modify('-10 days'));
        $inactive->deactivate();

        $reminders = (new MaintenanceReminderService())->build($today, [
            $dueInspection,
            $farFuture,
            $expiredWarranty,
            $inactive,
            $dueWarranty,
            $overdueInspection,
        ]);

        self::assertCount(4, $reminders);
        self::assertSame(MaintenanceReminderType::WARRANTY_EXPIRED, $reminders[0]->type);
        self::assertSame(-2, $reminders[0]->daysDelta);
        self::assertSame(MaintenanceReminderType::INSPECTION_OVERDUE, $reminders[1]->type);
        self::assertSame(-1, $reminders[1]->daysDelta);
        self::assertSame(MaintenanceReminderType::INSPECTION_DUE, $reminders[2]->type);
        self::assertSame(30, $reminders[2]->daysDelta);
        self::assertSame(MaintenanceReminderType::WARRANTY_DUE, $reminders[3]->type);
        self::assertSame(60, $reminders[3]->daysDelta);
    }

    public function testDueTodayIsDueNotOverdue(): void
    {
        $today = new DateTimeImmutable('2026-09-08');
        $asset = BuildingAsset::register('Асансьор', BuildingAssetCategory::ELEVATOR, 'Вход', warrantyUntil: $today, nextInspectionAt: $today);

        $reminders = (new MaintenanceReminderService())->build($today, [$asset]);

        self::assertCount(2, $reminders);
        self::assertSame(MaintenanceReminderType::WARRANTY_DUE, $reminders[0]->type);
        self::assertSame(MaintenanceReminderType::INSPECTION_DUE, $reminders[1]->type);
        self::assertSame(0, $reminders[0]->daysDelta);
        self::assertSame(0, $reminders[1]->daysDelta);
    }
}
