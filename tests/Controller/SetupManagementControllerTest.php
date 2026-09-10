<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\FeePolicy;
use App\Entity\Fund;
use App\Entity\Person;
use App\Entity\Unit;
use App\Entity\UnitRelation;
use App\Entity\User;
use App\Enum\FeeCategory;
use App\Enum\FeeDistribution;
use App\Enum\FundType;
use App\Enum\UnitRelationType;
use App\Enum\UnitType;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class SetupManagementControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private User $admin;
    private User $manager;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->entityManager = $entityManager;

        $tool = new SchemaTool($this->entityManager);
        $metadata = $this->entityManager->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);

        $this->admin = $this->user('admin-setup@example.com', ['ROLE_ADMIN']);
        $this->manager = $this->user('manager-setup@example.com', ['ROLE_MANAGER']);
        foreach ([$this->admin, $this->manager] as $user) {
            $this->entityManager->persist($user->getPerson());
            $this->entityManager->persist($user);
        }
        $this->entityManager->flush();
    }

    protected function tearDown(): void
    {
        $this->entityManager->close();
        parent::tearDown();
    }

    public function testAdminCanBootstrapMinimumBuildingAndFinanceConfiguration(): void
    {
        $this->client->loginUser($this->admin);

        $crawler = $this->client->request('GET', '/management/setup/unit/new');
        self::assertResponseIsSuccessful();
        $this->client->submit($crawler->selectButton('Създай обект')->form([
            'designation' => '12',
            'type' => UnitType::APARTMENT->value,
            'floor' => '2',
            'built_area' => '82.50',
            'ideal_parts' => '12.5000',
        ]));
        self::assertResponseRedirects('/management/setup');

        $unit = $this->entityManager->getRepository(Unit::class)->findOneBy(['designation' => '12']);
        self::assertInstanceOf(Unit::class, $unit);
        self::assertNotNull($unit->getId());
        self::assertNotNull($this->admin->getPerson()->getId());

        $crawler = $this->client->request('GET', '/management/setup/relation/new');
        self::assertResponseIsSuccessful();
        $this->client->submit($crawler->selectButton('Създай отношение')->form([
            'unit_id' => (string) $unit->getId(),
            'person_id' => (string) $this->admin->getPerson()->getId(),
            'type' => UnitRelationType::OWNER->value,
            'valid_from' => '2026-09-01',
            'ownership_share' => '100.0000',
            'legal_entity_name' => '',
            'legal_entity_identifier' => '',
        ]));
        self::assertResponseRedirects('/management/setup');

        $crawler = $this->client->request('GET', '/management/setup/fund/new');
        self::assertResponseIsSuccessful();
        $this->client->submit($crawler->selectButton('Създай фонд')->form([
            'code' => 'operating',
            'name' => 'Управление и поддръжка',
            'type' => FundType::OPERATING->value,
        ]));
        self::assertResponseRedirects('/management/setup');

        $fund = $this->entityManager->getRepository(Fund::class)->findOneBy(['code' => 'operating']);
        self::assertInstanceOf(Fund::class, $fund);
        self::assertNotNull($fund->getId());

        $crawler = $this->client->request('GET', '/management/setup/fee-policy/new');
        self::assertResponseIsSuccessful();
        $this->client->submit($crawler->selectButton('Създай правило')->form([
            'code' => 'maintenance',
            'name' => 'Месечна поддръжка',
            'fund_id' => (string) $fund->getId(),
            'category' => FeeCategory::MANAGEMENT_MAINTENANCE->value,
            'distribution' => FeeDistribution::PER_UNIT->value,
            'amount' => '25.00',
            'effective_from' => '2026-09-01',
            'decision_reference' => 'ОС 01/2026, т. 4',
        ]));
        self::assertResponseRedirects('/management/setup');

        $relations = $this->entityManager->getRepository(UnitRelation::class)->findAll();
        self::assertCount(1, $relations);
        self::assertSame($this->admin->getPerson()->getId(), $relations[0]->getPerson()?->getId());
        self::assertSame('100.0000', $relations[0]->getOwnershipShare());

        $policies = $this->entityManager->getRepository(FeePolicy::class)->findAll();
        self::assertCount(1, $policies);
        self::assertSame(2500, $policies[0]->getMonthlyAmountCents());
        self::assertSame($fund->getId(), $policies[0]->getFund()->getId());
    }

    public function testSetupIsAdminOnly(): void
    {
        $this->client->loginUser($this->manager);

        $this->client->request('GET', '/management/setup');
        self::assertResponseStatusCodeSame(403);

        $this->client->request('GET', '/management/setup/unit/new');
        self::assertResponseStatusCodeSame(403);
    }

    public function testSetupMutationRequiresValidCsrf(): void
    {
        $this->client->loginUser($this->admin);
        $this->client->request('POST', '/management/setup/unit/new', [
            '_token' => 'invalid',
            'designation' => '12',
            'type' => UnitType::APARTMENT->value,
        ]);

        self::assertResponseStatusCodeSame(403);
        self::assertCount(0, $this->entityManager->getRepository(Unit::class)->findAll());
    }

    /** @param list<string> $roles */
    private function user(string $email, array $roles): User
    {
        $person = new Person('Тест', ucfirst(strstr($email, '@', true) ?: 'User'), email: $email);
        $user = new User($person, $email, 'hash');
        $user->setRoles($roles);

        return $user;
    }
}
