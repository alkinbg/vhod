<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\BuildingAsset;
use App\Entity\MaintenanceContract;
use App\Entity\MaintenanceEvent;
use App\Entity\MaintenanceSupplier;
use App\Entity\Person;
use App\Entity\User;
use App\Enum\BuildingAssetCategory;
use App\Enum\MaintenanceEventType;
use App\Service\MaintenanceRegistryService;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class MaintenanceRegistryServiceTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private MaintenanceRegistryService $service;
    private User $manager;

    protected function setUp(): void
    {
        self::bootKernel();
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->entityManager = $entityManager;
        $tool = new SchemaTool($entityManager);
        $metadata = $entityManager->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);

        $person = new Person('Мария', 'Петрова', email: 'manager@example.com');
        $this->manager = new User($person, 'manager@example.com', 'hash');
        $this->manager->setRoles(['ROLE_MANAGER']);
        $entityManager->persist($person);
        $entityManager->persist($this->manager);
        $entityManager->flush();

        $service = self::getContainer()->get(MaintenanceRegistryService::class);
        self::assertInstanceOf(MaintenanceRegistryService::class, $service);
        $this->service = $service;
    }

    protected function tearDown(): void
    {
        $this->entityManager->close();
        parent::tearDown();
    }

    public function testCreatesAssetSupplierAndContract(): void
    {
        $asset = $this->service->createAsset('Асансьор', BuildingAssetCategory::ELEVATOR, 'Вход А');
        $supplier = $this->service->createSupplier('Сервиз ООД', email: 'office@example.com');
        $contract = $this->service->createContract(
            $supplier,
            'Абонаментна поддръжка',
            new DateTimeImmutable('2026-01-01'),
            new DateTimeImmutable('2026-09-08 18:00:00+00:00'),
            asset: $asset,
        );

        self::assertNotNull($asset->getId());
        self::assertNotNull($supplier->getId());
        self::assertNotNull($contract->getId());
        self::assertCount(1, $this->entityManager->getRepository(BuildingAsset::class)->findAll());
        self::assertCount(1, $this->entityManager->getRepository(MaintenanceSupplier::class)->findAll());
        self::assertCount(1, $this->entityManager->getRepository(MaintenanceContract::class)->findAll());
    }

    public function testEventWithNextInspectionUpdatesAssetAtomically(): void
    {
        $asset = $this->service->createAsset('Пожарогасители', BuildingAssetCategory::FIRE_SAFETY, 'Стълбище');
        $nextInspectionAt = new DateTimeImmutable('2027-09-08');

        $event = $this->service->recordEvent(
            $asset,
            MaintenanceEventType::INSPECTION,
            new DateTimeImmutable('2026-09-08'),
            'Годишна проверка',
            $this->manager,
            new DateTimeImmutable('2026-09-08 18:00:00+00:00'),
            nextInspectionAt: $nextInspectionAt,
        );

        self::assertNotNull($event->getId());
        self::assertSame($nextInspectionAt, $asset->getNextInspectionAt());
        self::assertCount(1, $this->entityManager->getRepository(MaintenanceEvent::class)->findAll());
    }
}
