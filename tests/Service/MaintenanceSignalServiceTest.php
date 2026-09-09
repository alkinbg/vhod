<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\MaintenanceSignal;
use App\Entity\MaintenanceSignalStatusChange;
use App\Entity\Person;
use App\Entity\User;
use App\Enum\MaintenanceSignalCategory;
use App\Enum\MaintenanceSignalPriority;
use App\Enum\MaintenanceSignalStatus;
use App\Service\MaintenanceSignalService;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use DomainException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class MaintenanceSignalServiceTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private MaintenanceSignalService $service;
    private User $resident;
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

        $residentPerson = new Person('Иван', 'Иванов', email: 'resident@example.com');
        $managerPerson = new Person('Мария', 'Петрова', email: 'manager@example.com');
        $this->resident = new User($residentPerson, 'resident@example.com', 'hash');
        $this->manager = new User($managerPerson, 'manager@example.com', 'hash');
        $this->manager->setRoles(['ROLE_MANAGER']);

        foreach ([$residentPerson, $managerPerson, $this->resident, $this->manager] as $entity) {
            $entityManager->persist($entity);
        }
        $entityManager->flush();

        $service = self::getContainer()->get(MaintenanceSignalService::class);
        self::assertInstanceOf(MaintenanceSignalService::class, $service);
        $this->service = $service;
    }

    protected function tearDown(): void
    {
        $this->entityManager->close();
        parent::tearDown();
    }

    public function testCreatePersistsSignalAndExactlyOneInitialHistoryRow(): void
    {
        $createdAt = new DateTimeImmutable('2026-09-08 18:00:00+00:00');

        $signal = $this->service->create(
            $this->resident,
            MaintenanceSignalCategory::ELECTRICAL,
            MaintenanceSignalPriority::HIGH,
            'Осветление на стълбището',
            'Лампите на третия етаж не работят.',
            'Етаж 3',
            $createdAt,
        );

        self::assertNotNull($signal->getId());
        self::assertCount(1, $this->entityManager->getRepository(MaintenanceSignal::class)->findAll());

        /** @var list<MaintenanceSignalStatusChange> $changes */
        $changes = $this->entityManager->getRepository(MaintenanceSignalStatusChange::class)->findAll();
        self::assertCount(1, $changes);
        self::assertSame($signal, $changes[0]->getSignal());
        self::assertNull($changes[0]->getFromStatus());
        self::assertSame(MaintenanceSignalStatus::OPEN, $changes[0]->getToStatus());
        self::assertSame($this->resident, $changes[0]->getChangedBy());
        self::assertSame($createdAt->format('Y-m-d H:i:s'), $changes[0]->getChangedAt()->format('Y-m-d H:i:s'));
    }

    public function testAssignPersistsAssigneeUnderTransaction(): void
    {
        $signal = $this->createSignal();

        $this->service->assign($signal, $this->manager, new DateTimeImmutable('2026-09-08 19:00:00+00:00'));

        self::assertSame($this->manager, $signal->getAssignedTo());
        self::assertSame('2026-09-08 19:00:00', $signal->getUpdatedAt()->format('Y-m-d H:i:s'));
    }

    public function testStatusChangePersistsAppendOnlyHistory(): void
    {
        $signal = $this->createSignal();

        $change = $this->service->changeStatus(
            $signal,
            MaintenanceSignalStatus::IN_PROGRESS,
            $this->manager,
            new DateTimeImmutable('2026-09-08 20:00:00+00:00'),
            'Поет за проверка.',
        );

        self::assertNotNull($change->getId());
        self::assertSame(MaintenanceSignalStatus::OPEN, $change->getFromStatus());
        self::assertSame(MaintenanceSignalStatus::IN_PROGRESS, $change->getToStatus());
        self::assertSame(MaintenanceSignalStatus::IN_PROGRESS, $signal->getStatus());
        self::assertCount(2, $this->entityManager->getRepository(MaintenanceSignalStatusChange::class)->findAll());
    }

    public function testNoOpStatusChangeDoesNotAppendHistory(): void
    {
        $signal = $this->createSignal();

        try {
            $this->service->changeStatus(
                $signal,
                MaintenanceSignalStatus::OPEN,
                $this->manager,
                new DateTimeImmutable('2026-09-08 20:00:00+00:00'),
            );
            self::fail('Expected no-op status transition to be rejected.');
        } catch (DomainException) {
            self::assertCount(1, $this->entityManager->getRepository(MaintenanceSignalStatusChange::class)->findAll());
        }
    }

    private function createSignal(): MaintenanceSignal
    {
        return $this->service->create(
            $this->resident,
            MaintenanceSignalCategory::COMMON_AREA,
            MaintenanceSignalPriority::NORMAL,
            'Проблем във входа',
            'Описание на проблема.',
            'Партер',
            new DateTimeImmutable('2026-09-08 18:00:00+00:00'),
        );
    }
}
