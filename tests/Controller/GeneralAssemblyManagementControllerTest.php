<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\GeneralAssemblyManagementController;
use App\Entity\AssemblyAgendaItem;
use App\Entity\AssemblyElectorateEntry;
use App\Entity\Document;
use App\Entity\GeneralAssembly;
use App\Entity\Person;
use App\Entity\Unit;
use App\Entity\UnitRelation;
use App\Entity\User;
use App\Enum\AssemblyConveningBasis;
use App\Enum\AssemblyDecisionKind;
use App\Enum\AssemblyVoteDenominator;
use App\Enum\DocumentAccessLevel;
use App\Enum\DocumentCategory;
use App\Enum\GeneralAssemblyStatus;
use App\Enum\MajorityComparison;
use App\Enum\UnitRelationType;
use App\Service\GeneralAssemblyService;
use App\Value\AssemblyMajorityRuleSnapshot;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class GeneralAssemblyManagementControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private int $residentId;
    private int $cashierId;
    private int $controllerId;
    private int $managerId;
    private int $adminId;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $em = $this->entityManager();
        $tool = new SchemaTool($em);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);

        $this->residentId = $this->persistUser($em, 'resident-ga@example.com');
        $this->cashierId = $this->persistUser($em, 'cashier-ga@example.com', ['ROLE_CASHIER']);
        $this->controllerId = $this->persistUser($em, 'controller-ga@example.com', ['ROLE_CONTROLLER']);
        $this->managerId = $this->persistUser($em, 'manager-ga@example.com', ['ROLE_MANAGER']);
        $this->adminId = $this->persistUser($em, 'admin-ga@example.com', ['ROLE_ADMIN']);
        $em->flush();
        $this->clearStorage();
    }

    protected function tearDown(): void
    {
        $this->clearStorage();
        parent::tearDown();
    }

    public function testManagementRouteRoleMatrix(): void
    {
        self::assertTrue(class_exists(GeneralAssemblyManagementController::class), 'GeneralAssemblyManagementController has not been implemented yet.');

        $this->client->request('GET', '/management/assemblies');
        self::assertResponseRedirects('/login');

        foreach ([$this->residentId, $this->cashierId] as $id) {
            self::ensureKernelShutdown();
            $this->client = self::createClient();
            $this->client->loginUser($this->user($id));
            $this->client->request('GET', '/management/assemblies');
            self::assertResponseStatusCodeSame(403);
        }

        foreach ([$this->controllerId, $this->managerId, $this->adminId] as $id) {
            self::ensureKernelShutdown();
            $this->client = self::createClient();
            $this->client->loginUser($this->user($id));
            $this->client->request('GET', '/management/assemblies');
            self::assertResponseIsSuccessful();
        }
    }

    public function testGovernanceManagerCanCreateDraftAndInvalidCsrfCreatesNothing(): void
    {
        self::assertTrue(class_exists(GeneralAssemblyService::class), 'GeneralAssemblyService has not been implemented yet.');

        $this->client->loginUser($this->user($this->controllerId));
        $crawler = $this->client->request('GET', '/management/assembly/new');
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('assembly_submit')->form([
            'title' => 'Редовно общо събрание',
            'scheduled_at' => '2026-09-20T18:00',
            'reference_date' => '2026-09-09',
            'place' => 'Вход А',
            'convening_basis' => AssemblyConveningBasis::CONTROLLER_OR_CONTROL_BOARD->value,
            'initiator_display_name' => 'Контрольор',
        ]);
        $this->client->submit($form);
        self::assertResponseRedirects('/management/assemblies');

        $assemblies = $this->entityManager()->getRepository(GeneralAssembly::class)->findAll();
        self::assertCount(1, $assemblies);
        self::assertSame(GeneralAssemblyStatus::DRAFT, $assemblies[0]->getStatus());
        self::assertFalse($assemblies[0]->isResidentVisible());

        self::ensureKernelShutdown();
        $this->client = self::createClient();
        $this->client->loginUser($this->user($this->managerId));
        $this->client->request('POST', '/management/assembly/new', [
            'title' => 'Blocked',
            'scheduled_at' => '2026-09-21T18:00',
            'reference_date' => '2026-09-09',
            'place' => 'Вход А',
            'convening_basis' => AssemblyConveningBasis::MANAGER_OR_BOARD->value,
            'initiator_display_name' => 'Управител',
            '_token' => 'invalid',
        ]);
        self::assertResponseStatusCodeSame(403);
        self::assertCount(1, $this->entityManager()->getRepository(GeneralAssembly::class)->findAll());
    }

    public function testConveneCreatesOneElectorateSnapshotAndOneResidentInvitation(): void
    {
        self::assertTrue(method_exists(GeneralAssemblyService::class, 'convene'), 'GeneralAssembly convening workflow has not been implemented yet.');

        $em = $this->entityManager();
        $manager = $this->user($this->managerId);
        $owner = new Person('Анна', 'Собственик');
        $unit = new Unit('Ап. 1', idealParts: '100.0000');
        $relation = new UnitRelation(
            $owner,
            $unit,
            UnitRelationType::OWNER,
            new DateTimeImmutable('2020-01-01'),
            '100.0000',
        );
        $assembly = GeneralAssembly::draft(
            'Общо събрание с покана',
            new DateTimeImmutable('2026-09-20T15:00:00Z'),
            'Europe/Sofia',
            new DateTimeImmutable('2026-09-09'),
            'Вход А',
            AssemblyConveningBasis::MANAGER_OR_BOARD,
            'Управител',
            $manager,
            $manager,
            new DateTimeImmutable('2026-09-09T07:00:00Z'),
        );
        $item = $assembly->addAgendaItem(
            1,
            'Ремонт на покрива',
            null,
            'Да се одобри ремонтът.',
            AssemblyDecisionKind::ORDINARY,
            $this->majorityRule(),
        );

        foreach ([$owner, $unit, $relation, $assembly, $item] as $entity) {
            $em->persist($entity);
        }
        $em->flush();
        self::assertNotNull($assembly->getId());

        $this->client->loginUser($manager);
        $crawler = $this->client->request('GET', '/management/assemblies');
        $form = $crawler->selectButton('assembly_convene_'.$assembly->getId())->form();
        $this->client->submit($form);
        self::assertResponseRedirects('/management/assemblies');

        $em = $this->entityManager();
        $reloaded = $em->find(GeneralAssembly::class, $assembly->getId());
        self::assertInstanceOf(GeneralAssembly::class, $reloaded);
        self::assertSame(GeneralAssemblyStatus::CONVENED, $reloaded->getStatus());
        self::assertCount(1, $em->getRepository(AssemblyElectorateEntry::class)->findBy(['assembly' => $reloaded]));

        $documents = $em->getRepository(Document::class)->findBy(['category' => DocumentCategory::MEETING_INVITATION]);
        self::assertCount(1, $documents);
        self::assertSame(DocumentAccessLevel::RESIDENTS, $documents[0]->getAccessLevel());
        self::assertSame($documents[0], $reloaded->getInvitationDocument());
        self::assertFileExists($this->storageDirectory().'/'.$documents[0]->getStorageName());

        self::ensureKernelShutdown();
        $this->client = self::createClient();
        $this->client->loginUser($this->user($this->managerId));
        $this->client->request('POST', '/management/assembly/'.$assembly->getId().'/convene', ['_token' => 'invalid']);
        self::assertResponseStatusCodeSame(403);
        self::assertCount(1, $this->entityManager()->getRepository(Document::class)->findBy(['category' => DocumentCategory::MEETING_INVITATION]));
    }

    private function entityManager(): EntityManagerInterface
    {
        $em = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
    }

    /** @param list<string> $roles */
    private function persistUser(EntityManagerInterface $em, string $email, array $roles = []): int
    {
        $person = new Person('Иван', 'Иванов', email: $email);
        $user = new User($person, $email, 'hash');
        $user->setRoles($roles);
        $em->persist($person);
        $em->persist($user);
        $em->flush();
        self::assertNotNull($user->getId());

        return $user->getId();
    }

    private function user(int $id): User
    {
        $user = $this->entityManager()->find(User::class, $id);
        self::assertInstanceOf(User::class, $user);

        return $user;
    }

    private function majorityRule(): AssemblyMajorityRuleSnapshot
    {
        return new AssemblyMajorityRuleSnapshot(
            'ordinary-represented',
            AssemblyVoteDenominator::REPRESENTED_AT_MEETING,
            '50',
            MajorityComparison::GREATER_THAN,
            'ЗУЕС — приложимо мнозинство',
            'effective-through-2026-09-09',
        );
    }

    private function storageDirectory(): string
    {
        return dirname(__DIR__, 2).'/var/storage/documents';
    }

    private function clearStorage(): void
    {
        $directory = $this->storageDirectory();
        if (!is_dir($directory)) {
            mkdir($directory, 0700, true);
        }
        foreach (glob($directory.'/*') ?: [] as $file) {
            @unlink($file);
        }
    }
}
