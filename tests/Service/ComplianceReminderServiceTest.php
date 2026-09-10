<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\BuildingAsset;
use App\Entity\ComplianceCompletion;
use App\Entity\MaintenanceContract;
use App\Entity\MaintenanceSupplier;
use App\Entity\ManagementMandate;
use App\Entity\Person;
use App\Entity\User;
use App\Enum\BuildingAssetCategory;
use App\Enum\ComplianceCompletionType;
use App\Enum\ComplianceReminderType;
use App\Enum\ManagementMandateKind;
use App\Service\ComplianceReminderService;
use App\Service\MaintenanceReminderService;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

final class ComplianceReminderServiceTest extends TestCase
{
    private DateTimeImmutable $today;
    private User $actor;

    protected function setUp(): void
    {
        $this->today = new DateTimeImmutable('2026-09-10', new DateTimeZone('Europe/Sofia'));
        $person = new Person('Мария', 'Управител', email: 'compliance-reminders@example.com');
        $this->actor = new User($person, 'compliance-reminders@example.com', 'hash');
        $this->actor->setRoles(['ROLE_MANAGER']);
    }

    public function testBuildsMandateRecurringContractAndMaintenanceReminders(): void
    {
        $expiredMandate = $this->mandate('Стар управител', '2024-09-01', '2026-09-09');
        $currentMandate = $this->mandate('Мария Иванова', '2026-09-01', '2026-10-15');
        $futureMandate = $this->mandate('Бъдещ управител', '2026-10-16', '2028-10-15');

        $supplier = MaintenanceSupplier::register('Асансьор Сервиз');
        $expiredContract = MaintenanceContract::create(
            $supplier,
            'Стар договор',
            new DateTimeImmutable('2025-01-01'),
            new DateTimeImmutable('2025-01-01T08:00:00Z'),
            endsAt: new DateTimeImmutable('2026-09-08'),
        );
        $dueContract = MaintenanceContract::create(
            $supplier,
            'Асансьорен договор',
            new DateTimeImmutable('2026-01-01'),
            new DateTimeImmutable('2026-01-01T08:00:00Z'),
            endsAt: new DateTimeImmutable('2026-10-01'),
        );
        $farContract = MaintenanceContract::create(
            $supplier,
            'Далечен договор',
            new DateTimeImmutable('2026-01-01'),
            new DateTimeImmutable('2026-01-01T08:00:00Z'),
            endsAt: new DateTimeImmutable('2027-12-31'),
        );

        $inspection = BuildingAsset::register(
            'Пожарогасители',
            BuildingAssetCategory::FIRE_SAFETY,
            'Стълбище',
            nextInspectionAt: new DateTimeImmutable('2026-09-20'),
        );
        $expiredWarranty = BuildingAsset::register(
            'Домофон',
            BuildingAssetCategory::ACCESS_SYSTEM,
            'Вход',
            warrantyUntil: new DateTimeImmutable('2026-09-07'),
        );
        $inactive = BuildingAsset::register(
            'Стар контролер',
            BuildingAssetCategory::ACCESS_SYSTEM,
            'Вход',
            warrantyUntil: new DateTimeImmutable('2026-09-01'),
        );
        $inactive->deactivate();

        $reminders = $this->service()->build(
            $this->today,
            [$expiredMandate, $currentMandate, $futureMandate],
            [],
            [$farContract, $dueContract, $expiredContract],
            [$inspection, $inactive, $expiredWarranty],
        );

        self::assertSame([
            ComplianceReminderType::MONTHLY_REPORT_MISSING,
            ComplianceReminderType::WARRANTY_EXPIRED,
            ComplianceReminderType::CONTRACT_EXPIRED,
            ComplianceReminderType::INSPECTION_DUE,
            ComplianceReminderType::CONTRACT_DUE,
            ComplianceReminderType::MANAGEMENT_MANDATE_DUE,
            ComplianceReminderType::ANNUAL_CASH_AUDIT_DUE,
        ], array_map(static fn ($reminder) => $reminder->type, $reminders));

        self::assertSame('Месечен отчет за 08.2026', $reminders[0]->subject);
        self::assertSame('2026-09-01', $reminders[0]->dueAt->format('Y-m-d'));
        self::assertSame(-9, $reminders[0]->daysDelta);
        self::assertSame('Домофон', $reminders[1]->subject);
        self::assertSame('Стар договор', $reminders[2]->subject);
        self::assertSame('Пожарогасители', $reminders[3]->subject);
        self::assertSame('Асансьорен договор', $reminders[4]->subject);
        self::assertSame('Мария Иванова', $reminders[5]->subject);
        self::assertSame('Годишна проверка на касата за 2026', $reminders[6]->subject);
        self::assertSame('2026-12-31', $reminders[6]->dueAt->format('Y-m-d'));

        self::assertNotContains('Бъдещ управител', array_map(static fn ($reminder) => $reminder->subject, $reminders));
        self::assertNotContains('Стар контролер', array_map(static fn ($reminder) => $reminder->subject, $reminders));
        self::assertNotContains('Далечен договор', array_map(static fn ($reminder) => $reminder->subject, $reminders));
    }

    public function testExpiredLatestMandateIsReportedAndCompletedRecurringWorkIsSuppressed(): void
    {
        $monthly = ComplianceCompletion::record(
            ComplianceCompletionType::MONTHLY_REPORT,
            '2026-08',
            new DateTimeImmutable('2026-09-05'),
            $this->actor,
            new DateTimeImmutable('2026-09-05T08:00:00Z'),
        );
        $annual = ComplianceCompletion::record(
            ComplianceCompletionType::ANNUAL_CASH_AUDIT,
            '2026',
            new DateTimeImmutable('2026-06-30'),
            $this->actor,
            new DateTimeImmutable('2026-06-30T08:00:00Z'),
        );

        $reminders = $this->service()->build(
            $this->today,
            [$this->mandate('Изтекъл мандат', '2024-09-01', '2026-09-09')],
            [$monthly, $annual],
            [],
            [],
        );

        self::assertCount(1, $reminders);
        self::assertSame(ComplianceReminderType::MANAGEMENT_MANDATE_EXPIRED, $reminders[0]->type);
        self::assertSame('Изтекъл мандат', $reminders[0]->subject);
        self::assertSame(-1, $reminders[0]->daysDelta);
    }

    public function testDueTodayIsNotClassifiedAsExpired(): void
    {
        $supplier = MaintenanceSupplier::register('Сервиз');
        $contract = MaintenanceContract::create(
            $supplier,
            'Договор днес',
            new DateTimeImmutable('2026-01-01'),
            new DateTimeImmutable('2026-01-01T08:00:00Z'),
            endsAt: new DateTimeImmutable('2026-09-10'),
        );

        $reminders = $this->service()->build(
            $this->today,
            [$this->mandate('Мандат днес', '2024-09-10', '2026-09-10')],
            [
                ComplianceCompletion::record(ComplianceCompletionType::MONTHLY_REPORT, '2026-08', $this->today, $this->actor, $this->today),
                ComplianceCompletion::record(ComplianceCompletionType::ANNUAL_CASH_AUDIT, '2026', $this->today, $this->actor, $this->today),
            ],
            [$contract],
            [],
        );

        self::assertSame([
            ComplianceReminderType::CONTRACT_DUE,
            ComplianceReminderType::MANAGEMENT_MANDATE_DUE,
        ], array_map(static fn ($reminder) => $reminder->type, $reminders));
        self::assertSame(0, $reminders[0]->daysDelta);
        self::assertSame(0, $reminders[1]->daysDelta);
    }

    private function mandate(string $holder, string $startsAt, string $endsAt): ManagementMandate
    {
        return ManagementMandate::record(
            ManagementMandateKind::MANAGER,
            $holder,
            new DateTimeImmutable($startsAt),
            new DateTimeImmutable($endsAt),
            $this->actor,
            new DateTimeImmutable($startsAt.'T08:00:00Z'),
        );
    }

    private function service(): ComplianceReminderService
    {
        return new ComplianceReminderService(new MaintenanceReminderService());
    }
}
