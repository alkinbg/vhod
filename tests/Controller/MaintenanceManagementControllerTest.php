<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\BuildingAsset;
use App\Entity\MaintenanceContract;
use App\Entity\MaintenanceEvent;
use App\Entity\MaintenanceSignal;
use App\Entity\MaintenanceSignalStatusChange;
use App\Entity\MaintenanceSupplier;
use App\Entity\Person;
use App\Entity\User;
use App\Enum\BuildingAssetCategory;
use App\Enum\MaintenanceEventType;
use App\Enum\MaintenanceSignalCategory;
use App\Enum\MaintenanceSignalPriority;
use App\Enum\MaintenanceSignalStatus;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class MaintenanceManagementControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private int $residentId;
    private int $managerId;
    private int $signalId;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $entityManager = $this->entityManager();
        $tool = new SchemaTool($entityManager);
        $metadata = $entityManager->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);

        $resident = $this->persistUser($entityManager, 'resident@example.com', 'Иван', 'Иванов');
        $manager = $this->persistUser($entityManager, 'manager@example.com', 'Мария', 'Петрова', ['ROLE_MANAGER']);
        $signal = MaintenanceSignal::open(
            $resident,
            MaintenanceSignalCategory::ELECTRICAL,
            MaintenanceSignalPriority::HIGH,
            'Осветление на етаж 3',
            'Двете лампи не светят.',
            'Етаж 3',
            new DateTimeImmutable('2026-09-08 18:00:00+00:00'),
        );
        $history = MaintenanceSignalStatusChange::record(
            $signal,
            null,
            MaintenanceSignalStatus::OPEN,
            $resident,
            new DateTimeImmutable('2026-09-08 18:00:00+00:00'),
        );
        $entityManager->persist($signal);
        $entityManager->persist($history);
        $entityManager->flush();

        self::assertNotNull($resident->getId());
        self::assertNotNull($manager->getId());
        self::assertNotNull($signal->getId());
        $this->residentId = $resident->getId();
        $this->managerId = $manager->getId();
        $this->signalId = $signal->getId();
    }

    public function testResidentCannotAccessMaintenanceManagement(): void
    {
        $this->client->loginUser($this->user($this->residentId));
        $this->client->request('GET', '/management/maintenance');

        self::assertResponseStatusCodeSame(403);
    }

    public function testManagerCanAssignSignalAndChangeStatusWithHistory(): void
    {
        $this->client->loginUser($this->user($this->managerId));
        $crawler = $this->client->request('GET', '/management/maintenance');
        self::assertResponseIsSuccessful();

        $assignForm = $crawler->selectButton('assign_submit')->form([
            'assigned_to_id' => (string) $this->managerId,
        ]);
        $this->client->submit($assignForm);
        self::assertResponseRedirects('/management/maintenance');

        $entityManager = $this->entityManager();
        $signal = $entityManager->find(MaintenanceSignal::class, $this->signalId);
        self::assertInstanceOf(MaintenanceSignal::class, $signal);
        self::assertSame($this->managerId, $signal->getAssignedTo()?->getId());

        $crawler = $this->client->request('GET', '/management/maintenance');
        $statusForm = $crawler->selectButton('status_submit')->form([
            'status' => MaintenanceSignalStatus::IN_PROGRESS->value,
            'note' => 'Назначена е проверка на място.',
        ]);
        $this->client->submit($statusForm);
        self::assertResponseRedirects('/management/maintenance');

        $entityManager = $this->entityManager();
        $signal = $entityManager->find(MaintenanceSignal::class, $this->signalId);
        self::assertInstanceOf(MaintenanceSignal::class, $signal);
        self::assertSame(MaintenanceSignalStatus::IN_PROGRESS, $signal->getStatus());
        /** @var list<MaintenanceSignalStatusChange> $history */
        $history = $entityManager->getRepository(MaintenanceSignalStatusChange::class)->findBy(['signal' => $signal], ['changedAt' => 'ASC']);
        self::assertCount(2, $history);
        self::assertSame('Назначена е проверка на място.', $history[1]->getNote());
    }

    public function testManagerCanCreateAssetSupplierContractAndEvent(): void
    {
        $this->client->loginUser($this->user($this->managerId));

        $crawler = $this->client->request('GET', '/management/maintenance/asset/new');
        self::assertResponseIsSuccessful();
        $this->client->submit($crawler->selectButton('asset_submit')->form([
            'name' => 'Асансьор 1',
            'category' => BuildingAssetCategory::ELEVATOR->value,
            'location' => 'Вход А',
            'manufacturer' => 'LiftCo',
            'model' => 'L-100',
            'serial_number' => 'SN-001',
            'installed_at' => '2024-01-10',
            'warranty_until' => '2027-01-10',
            'inspection_interval_months' => '12',
            'next_inspection_at' => '2026-12-10',
        ]));
        self::assertResponseRedirects('/management/maintenance');
        $asset = $this->entityManager()->getRepository(BuildingAsset::class)->findOneBy(['name' => 'Асансьор 1']);
        self::assertInstanceOf(BuildingAsset::class, $asset);
        self::assertNotNull($asset->getId());
        $assetId = $asset->getId();

        $crawler = $this->client->request('GET', '/management/maintenance/supplier/new');
        self::assertResponseIsSuccessful();
        $this->client->submit($crawler->selectButton('supplier_submit')->form([
            'name' => 'Сервиз Лифт ООД',
            'registration_number' => '123456789',
            'contact_person' => 'Петър Петров',
            'email' => 'service@example.com',
            'phone' => '0888123456',
            'address' => 'Русе',
            'note' => '24/7 аварийна поддръжка',
        ]));
        self::assertResponseRedirects('/management/maintenance');
        $supplier = $this->entityManager()->getRepository(MaintenanceSupplier::class)->findOneBy(['name' => 'Сервиз Лифт ООД']);
        self::assertInstanceOf(MaintenanceSupplier::class, $supplier);
        self::assertNotNull($supplier->getId());
        $supplierId = $supplier->getId();

        $crawler = $this->client->request('GET', '/management/maintenance/contract/new');
        self::assertResponseIsSuccessful();
        $this->client->submit($crawler->selectButton('contract_submit')->form([
            'supplier_id' => (string) $supplierId,
            'asset_id' => (string) $assetId,
            'title' => 'Годишна поддръжка на асансьор',
            'reference' => 'Д-2026-01',
            'starts_at' => '2026-01-01',
            'ends_at' => '2026-12-31',
            'note' => 'Месечни посещения',
        ]));
        self::assertResponseRedirects('/management/maintenance');
        $contract = $this->entityManager()->getRepository(MaintenanceContract::class)->findOneBy(['reference' => 'Д-2026-01']);
        self::assertInstanceOf(MaintenanceContract::class, $contract);
        self::assertNotNull($contract->getId());
        $contractId = $contract->getId();

        $crawler = $this->client->request('GET', '/management/maintenance/event/new');
        self::assertResponseIsSuccessful();
        $this->client->submit($crawler->selectButton('event_submit')->form([
            'asset_id' => (string) $assetId,
            'supplier_id' => (string) $supplierId,
            'contract_id' => (string) $contractId,
            'signal_id' => (string) $this->signalId,
            'type' => MaintenanceEventType::REPAIR->value,
            'performed_at' => '2026-09-08',
            'summary' => 'Сменен контактор на вратата',
            'note' => 'Проверена е и блокировката.',
            'next_inspection_at' => '2027-09-08',
        ]));
        self::assertResponseRedirects('/management/maintenance');

        $entityManager = $this->entityManager();
        self::assertCount(1, $entityManager->getRepository(MaintenanceEvent::class)->findAll());
        $asset = $entityManager->find(BuildingAsset::class, $assetId);
        self::assertInstanceOf(BuildingAsset::class, $asset);
        self::assertSame('2027-09-08', $asset->getNextInspectionAt()?->format('Y-m-d'));
    }

    public function testDashboardShowsWarrantyAndInspectionReminders(): void
    {
        $today = new DateTimeImmutable('today', new DateTimeZone('Europe/Sofia'));
        $asset = BuildingAsset::register(
            'Пожарогасители',
            BuildingAssetCategory::FIRE_SAFETY,
            'Стълбище',
            warrantyUntil: $today->modify('-1 day'),
            nextInspectionAt: $today->modify('+10 days'),
        );
        $entityManager = $this->entityManager();
        $entityManager->persist($asset);
        $entityManager->flush();

        $this->client->loginUser($this->user($this->managerId));
        $this->client->request('GET', '/management/maintenance');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Пожарогасители');
        self::assertSelectorTextContains('body', 'Изтекла гаранция');
        self::assertSelectorTextContains('body', 'Предстояща проверка');
    }

    public function testInvalidCsrfBlocksEveryManagementMutationFamily(): void
    {
        $this->client->loginUser($this->user($this->managerId));

        foreach ([
            ['/management/maintenance/signal/'.$this->signalId.'/assign', ['_token' => 'invalid']],
            ['/management/maintenance/signal/'.$this->signalId.'/status', ['_token' => 'invalid']],
            ['/management/maintenance/asset/new', ['_token' => 'invalid']],
            ['/management/maintenance/supplier/new', ['_token' => 'invalid']],
            ['/management/maintenance/contract/new', ['_token' => 'invalid']],
            ['/management/maintenance/event/new', ['_token' => 'invalid']],
        ] as [$uri, $parameters]) {
            $this->client->request('POST', $uri, $parameters);
            self::assertResponseStatusCodeSame(403, $uri);
        }
    }

    private function entityManager(): EntityManagerInterface
    {
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        return $entityManager;
    }

    private function user(int $id): User
    {
        $user = $this->entityManager()->find(User::class, $id);
        self::assertInstanceOf(User::class, $user);

        return $user;
    }

    /** @param list<string> $roles */
    private function persistUser(EntityManagerInterface $entityManager, string $email, string $firstName, string $lastName, array $roles = []): User
    {
        $person = new Person($firstName, $lastName, email: $email);
        $user = new User($person, $email, 'hash');
        $user->setRoles($roles);
        $entityManager->persist($person);
        $entityManager->persist($user);

        return $user;
    }
}
