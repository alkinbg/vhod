<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\BuildingAsset;
use App\Entity\MaintenanceContract;
use App\Entity\MaintenanceEvent;
use App\Entity\MaintenanceSignal;
use App\Entity\MaintenanceSupplier;
use App\Entity\Person;
use App\Entity\User;
use App\Enum\BuildingAssetCategory;
use App\Enum\MaintenanceEventType;
use App\Enum\MaintenanceSignalCategory;
use App\Enum\MaintenanceSignalPriority;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class MaintenanceRegistryEntitiesTest extends TestCase
{
    public function testAssetValidatesInspectionIntervalAndDates(): void
    {
        $this->expectException(InvalidArgumentException::class);

        BuildingAsset::register(
            'Асансьор',
            BuildingAssetCategory::ELEVATOR,
            'Вход А',
            installedAt: new DateTimeImmutable('2026-01-01'),
            warrantyUntil: new DateTimeImmutable('2025-12-31'),
            inspectionIntervalMonths: 12,
        );
    }

    public function testAssetRejectsNonPositiveInspectionInterval(): void
    {
        $this->expectException(InvalidArgumentException::class);

        BuildingAsset::register('Асансьор', BuildingAssetCategory::ELEVATOR, 'Вход А', inspectionIntervalMonths: 0);
    }

    public function testAssetCanScheduleAndClearNextInspection(): void
    {
        $asset = BuildingAsset::register('Асансьор', BuildingAssetCategory::ELEVATOR, 'Вход А');
        $next = new DateTimeImmutable('2026-10-10');

        $asset->scheduleNextInspection($next);
        self::assertSame($next, $asset->getNextInspectionAt());

        $asset->scheduleNextInspection(null);
        self::assertNull($asset->getNextInspectionAt());
    }

    public function testSupplierRequiresNameAndValidatesOptionalEmail(): void
    {
        $this->expectException(InvalidArgumentException::class);

        MaintenanceSupplier::register('Сервиз ООД', email: 'not-an-email');
    }

    public function testSupplierNormalizesOptionalFields(): void
    {
        $supplier = MaintenanceSupplier::register(
            '  Сервиз ООД  ',
            registrationNumber: '  123456789  ',
            contactPerson: '  Иван Иванов  ',
            email: '  OFFICE@EXAMPLE.COM  ',
            phone: '  +359 888 123 456  ',
            address: '  Русе  ',
            note: '  Денонощен сервиз  ',
        );

        self::assertSame('Сервиз ООД', $supplier->getName());
        self::assertSame('123456789', $supplier->getRegistrationNumber());
        self::assertSame('Иван Иванов', $supplier->getContactPerson());
        self::assertSame('office@example.com', $supplier->getEmail());
        self::assertSame('+359 888 123 456', $supplier->getPhone());
        self::assertSame('Русе', $supplier->getAddress());
        self::assertSame('Денонощен сервиз', $supplier->getNote());
        self::assertTrue($supplier->isActive());
    }

    public function testContractRejectsEndBeforeStart(): void
    {
        $this->expectException(InvalidArgumentException::class);

        MaintenanceContract::create(
            MaintenanceSupplier::register('Сервиз ООД'),
            'Абонаментна поддръжка',
            new DateTimeImmutable('2026-09-01'),
            new DateTimeImmutable('2026-09-08 18:00:00+00:00'),
            endsAt: new DateTimeImmutable('2026-08-31'),
        );
    }

    public function testEventRejectsContractSupplierMismatch(): void
    {
        $asset = BuildingAsset::register('Асансьор', BuildingAssetCategory::ELEVATOR, 'Вход А');
        $supplierA = MaintenanceSupplier::register('Сервиз А');
        $supplierB = MaintenanceSupplier::register('Сервиз Б');
        $contract = MaintenanceContract::create($supplierA, 'Договор А', new DateTimeImmutable('2026-01-01'), new DateTimeImmutable('2026-01-01 08:00:00+00:00'), asset: $asset);

        $this->expectException(InvalidArgumentException::class);

        MaintenanceEvent::record(
            $asset,
            MaintenanceEventType::REPAIR,
            new DateTimeImmutable('2026-09-08'),
            'Ремонт на врата',
            $this->user(),
            new DateTimeImmutable('2026-09-08 18:00:00+00:00'),
            supplier: $supplierB,
            contract: $contract,
        );
    }

    public function testEventRejectsContractAssetMismatch(): void
    {
        $assetA = BuildingAsset::register('Асансьор А', BuildingAssetCategory::ELEVATOR, 'Вход А');
        $assetB = BuildingAsset::register('Асансьор Б', BuildingAssetCategory::ELEVATOR, 'Вход Б');
        $supplier = MaintenanceSupplier::register('Сервиз ООД');
        $contract = MaintenanceContract::create($supplier, 'Договор А', new DateTimeImmutable('2026-01-01'), new DateTimeImmutable('2026-01-01 08:00:00+00:00'), asset: $assetA);

        $this->expectException(InvalidArgumentException::class);

        MaintenanceEvent::record(
            $assetB,
            MaintenanceEventType::INSPECTION,
            new DateTimeImmutable('2026-09-08'),
            'Проверка',
            $this->user(),
            new DateTimeImmutable('2026-09-08 18:00:00+00:00'),
            supplier: $supplier,
            contract: $contract,
        );
    }

    public function testEventRejectsSignalAssetMismatch(): void
    {
        $assetA = BuildingAsset::register('Помпа', BuildingAssetCategory::PLUMBING, 'Мазе');
        $assetB = BuildingAsset::register('Табло', BuildingAssetCategory::ELECTRICAL, 'Партер');
        $user = $this->user();
        $signal = MaintenanceSignal::open(
            $user,
            MaintenanceSignalCategory::PLUMBING,
            MaintenanceSignalPriority::NORMAL,
            'Теч',
            'Има теч при помпата.',
            'Мазе',
            new DateTimeImmutable('2026-09-08 18:00:00+00:00'),
            $assetA,
        );

        $this->expectException(InvalidArgumentException::class);

        MaintenanceEvent::record(
            $assetB,
            MaintenanceEventType::REPAIR,
            new DateTimeImmutable('2026-09-08'),
            'Ремонт',
            $user,
            new DateTimeImmutable('2026-09-08 19:00:00+00:00'),
            signal: $signal,
        );
    }

    public function testEventAcceptsGeneralContractForAsset(): void
    {
        $asset = BuildingAsset::register('Асансьор', BuildingAssetCategory::ELEVATOR, 'Вход А');
        $supplier = MaintenanceSupplier::register('Сервиз ООД');
        $contract = MaintenanceContract::create($supplier, 'Общ договор', new DateTimeImmutable('2026-01-01'), new DateTimeImmutable('2026-01-01 08:00:00+00:00'));
        $recordedAt = new DateTimeImmutable('2026-09-08 22:00:00+03:00');

        $event = MaintenanceEvent::record(
            $asset,
            MaintenanceEventType::PREVENTIVE_MAINTENANCE,
            new DateTimeImmutable('2026-09-08'),
            'Профилактика',
            $this->user(),
            $recordedAt,
            supplier: $supplier,
            contract: $contract,
            nextInspectionAt: new DateTimeImmutable('2027-03-08'),
        );

        self::assertSame($asset, $event->getAsset());
        self::assertSame($supplier, $event->getSupplier());
        self::assertSame($contract, $event->getContract());
        self::assertSame(MaintenanceEventType::PREVENTIVE_MAINTENANCE, $event->getType());
        self::assertSame('Профилактика', $event->getSummary());
        self::assertSame('UTC', $event->getRecordedAt()->getTimezone()->getName());
        self::assertSame('2026-09-08 19:00:00', $event->getRecordedAt()->format('Y-m-d H:i:s'));
    }

    private function user(): User
    {
        $person = new Person('Иван', 'Иванов', email: 'manager@example.com');

        return new User($person, 'manager@example.com', 'hash');
    }
}
